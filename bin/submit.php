<?php
/**
 * ПОСТАНОВКА задач Яндекса для проекта (боевой скрипт).
 * Создаёт прогон (run) на сегодня, ставит по задаче на каждую активную цель,
 * шлёт их в Яндекс пачкой (curl_multi) и сохраняет operation_id в provider_tasks.
 *
 * Запуск:  D:\xampp\php\php.exe bin/submit.php <project_id>
 */

require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/http.php';
require __DIR__ . '/../src/yandex.php';

$config = require __DIR__ . '/../config.php';
$y      = $config['yandex'];
$pdo    = db();

$projectId = (int) ($argv[1] ?? 0);
if ($projectId <= 0) {
    exit("Укажите project_id:  php bin/submit.php <project_id>\n");
}

// Домен проекта (нужен для проверки, что проект есть).
$project = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
$project->execute([$projectId]);
$project = $project->fetch();
if (!$project) {
    exit("Проект id=$projectId не найден.\n");
}

$runDate = date('Y-m-d');

// Создаём прогон на сегодня или берём существующий.
$pdo->prepare(
    "INSERT INTO runs (project_id, run_date, status) VALUES (?, ?, 'collecting')
     ON DUPLICATE KEY UPDATE status = 'collecting'"
)->execute([$projectId, $runDate]);

$runId = (int) $pdo->query(
    "SELECT id FROM runs WHERE project_id = $projectId AND run_date = '$runDate'"
)->fetchColumn();

// Активные цели Яндекса этого проекта, у которых ещё нет задачи в этом прогоне.
$targets = $pdo->prepare(
    "SELECT t.id, t.region_code, t.depth, k.phrase
       FROM targets t
       JOIN keywords k ON k.id = t.keyword_id
      WHERE t.project_id = ?
        AND t.engine = 'yandex'
        AND k.is_active = 1
        AND NOT EXISTS (
            SELECT 1 FROM provider_tasks pt
             WHERE pt.run_id = ? AND pt.target_id = t.id AND pt.provider = 'yandex'
        )"
);
$targets->execute([$projectId, $runId]);
$targets = $targets->fetchAll();

if (!$targets) {
    exit("Нет целей для постановки (возможно, уже поставлены в прогоне #$runId).\n");
}

echo "Проект: {$project['name']} ({$project['domain']})\n";
echo "Прогон #$runId на $runDate. Целей к постановке: " . count($targets) . "\n\n";

// 1) Заводим задачи в очереди (state=queued) и готовим HTTP-запросы, ключ = task_id.
$insTask = $pdo->prepare(
    "INSERT INTO provider_tasks (run_id, target_id, provider, state) VALUES (?, ?, 'yandex', 'queued')"
);
$requests = [];
foreach ($targets as $t) {
    $insTask->execute([$runId, $t['id']]);
    $taskId = (int) $pdo->lastInsertId();
    $requests[$taskId] = yandexSubmitRequest($y, $t['phrase'], $t['region_code'], (int) $t['depth']);
}

// 2) Отправляем всё пачкой.
echo "Отправляю задачи в Яндекс...\n";
$responses = httpMulti($requests, (int) $config['app']['curl_concurrency']);

// 3) Разбираем ответы и обновляем очередь.
$updOk = $pdo->prepare(
    "UPDATE provider_tasks
        SET external_id = ?, state = 'submitted',
            submitted_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 12 HOUR),
            attempts = attempts + 1
      WHERE id = ?"
);
$updFail = $pdo->prepare(
    "UPDATE provider_tasks
        SET state = 'failed', last_error = ?, attempts = attempts + 1
      WHERE id = ?"
);

$ok = 0; $fail = 0;
foreach ($responses as $taskId => $resp) {
    $err = null;
    $opId = yandexParseSubmit($resp, $err);
    if ($opId !== null) {
        $updOk->execute([$opId, $taskId]);
        $ok++;
    } else {
        $updFail->execute([substr((string) $err, 0, 1000), $taskId]);
        $fail++;
    }
}

// 4) Обновляем счётчики прогона.
$pdo->prepare("UPDATE runs SET requested = ?, failed = ? WHERE id = ?")
    ->execute([count($targets), $fail, $runId]);

echo "\nПоставлено: $ok, ошибок: $fail\n";
echo "Задачи в обработке у Яндекса. Забрать результат:\n";
echo "  php bin/collect.php $projectId\n";
