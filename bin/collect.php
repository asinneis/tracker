<?php
/**
 * СБОР результатов Яндекса для проекта (боевой скрипт).
 * Опрашивает поставленные задачи, забирает готовую выдачу, сохраняет её сырой
 * в serp_snapshots и считает позиции в positions (дедуп — первое вхождение).
 * Запускать несколько раз, пока не соберутся все (задачи готовятся не мгновенно).
 *
 * Запуск:  D:\xampp\php\php.exe bin/collect.php <project_id>
 */

require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/http.php';
require __DIR__ . '/../src/yandex.php';

$config = require __DIR__ . '/../config.php';
$y      = $config['yandex'];
$pdo    = db();

$projectId = (int) ($argv[1] ?? 0);
if ($projectId <= 0) {
    exit("Укажите project_id:  php bin/collect.php <project_id>\n");
}

$project = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
$project->execute([$projectId]);
$project = $project->fetch();
if (!$project) {
    exit("Проект id=$projectId не найден.\n");
}
$projectDomain = $project['domain'];

$runDate = date('Y-m-d');
$runId = (int) $pdo->query(
    "SELECT id FROM runs WHERE project_id = $projectId AND run_date = '$runDate'"
)->fetchColumn();
if (!$runId) {
    exit("Прогона на сегодня нет. Сначала: php bin/submit.php $projectId\n");
}

// 1) Помечаем протухшие задачи (12 часов вышли) как expired.
$pdo->prepare(
    "UPDATE provider_tasks
        SET state = 'expired'
      WHERE run_id = ? AND provider = 'yandex'
        AND state = 'submitted' AND expires_at < NOW()"
)->execute([$runId]);

// 2) Берём задачи, ждущие результата.
$tasks = $pdo->prepare(
    "SELECT pt.id, pt.external_id, pt.target_id, t.depth, k.phrase
       FROM provider_tasks pt
       JOIN targets t  ON t.id = pt.target_id
       JOIN keywords k ON k.id = t.keyword_id
      WHERE pt.run_id = ? AND pt.provider = 'yandex' AND pt.state = 'submitted'"
);
$tasks->execute([$runId]);
$tasks = $tasks->fetchAll();

if (!$tasks) {
    echo "Нет задач в ожидании. Возможно, всё уже собрано.\n";
}

// 3) Запрашиваем результаты пачкой (ключ = task_id).
$requests = [];
foreach ($tasks as $t) {
    $requests[$t['id']] = yandexFetchRequest($y, $t['external_id']);
}
$responses = $requests ? httpMulti($requests, (int) $config['app']['curl_concurrency']) : [];

// Подготовленные запросы для БД.
$insSnap = $pdo->prepare(
    "INSERT INTO serp_snapshots (target_id, run_id, captured_at, provider, results, raw_hash, http_status)
     VALUES (?, ?, NOW(), 'yandex', ?, ?, 200)"
);
$prevQ = $pdo->prepare(
    "SELECT position FROM positions WHERE target_id = ? AND run_date < ? ORDER BY run_date DESC LIMIT 1"
);
$upPos = $pdo->prepare(
    "INSERT INTO positions
        (target_id, run_date, position, found_url, previous_position, delta, in_top10, is_absent)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        position=VALUES(position), found_url=VALUES(found_url),
        previous_position=VALUES(previous_position), delta=VALUES(delta),
        in_top10=VALUES(in_top10), is_absent=VALUES(is_absent)"
);
$setCollected = $pdo->prepare(
    "UPDATE provider_tasks SET state='collected', ready_at=NOW(), collected_at=NOW(),
            attempts=attempts+1 WHERE id=?"
);
$setFailed = $pdo->prepare(
    "UPDATE provider_tasks SET state='failed', last_error=?, attempts=attempts+1 WHERE id=?"
);
$bumpAttempt = $pdo->prepare(
    "UPDATE provider_tasks SET attempts=attempts+1 WHERE id=?"
);

$targetById = [];
foreach ($tasks as $t) { $targetById[$t['id']] = $t; }

$collected = 0; $failed = 0; $pending = 0;

foreach ($responses as $taskId => $resp) {
    $t = $targetById[$taskId];
    $parsed = yandexParseResult($resp);

    if (!$parsed['done']) {
        $bumpAttempt->execute([$taskId]);   // ещё не готово — заберём в следующий раз
        $pending++;
        continue;
    }
    if ($parsed['error'] !== null) {
        $setFailed->execute([substr($parsed['error'], 0, 1000), $taskId]);
        $failed++;
        continue;
    }

    // Сохраняем сырую выдачу (сжатый JSON).
    $json = json_encode($parsed['results'], JSON_UNESCAPED_UNICODE);
    $insSnap->execute([
        $t['target_id'], $runId, gzcompress($json, 6), hash('sha256', $json),
    ]);

    // Считаем позицию.
    $p = findPosition($parsed['results'], $projectDomain);

    $prevQ->execute([$t['target_id'], $runDate]);
    $prev = $prevQ->fetchColumn();
    $prev = ($prev === false) ? null : (int) $prev;

    $delta = ($prev !== null && $p['position'] !== null) ? $prev - $p['position'] : null;
    $inTop10 = ($p['position'] !== null && $p['position'] <= 10) ? 1 : 0;

    $upPos->execute([
        $t['target_id'], $runDate, $p['position'], $p['found_url'],
        $prev, $delta, $inTop10, $p['is_absent'] ? 1 : 0,
    ]);

    $setCollected->execute([$taskId]);
    $collected++;
}

// 4) Обновляем счётчики и статус прогона.
$totCollected = (int) $pdo->query(
    "SELECT COUNT(*) FROM provider_tasks WHERE run_id=$runId AND state='collected'"
)->fetchColumn();
$totFailed = (int) $pdo->query(
    "SELECT COUNT(*) FROM provider_tasks WHERE run_id=$runId AND state IN ('failed','expired')"
)->fetchColumn();
$totPending = (int) $pdo->query(
    "SELECT COUNT(*) FROM provider_tasks WHERE run_id=$runId AND state='submitted'"
)->fetchColumn();

$status = $totPending > 0 ? 'collecting' : ($totFailed > 0 ? 'partial' : 'done');
$pdo->prepare("UPDATE runs SET collected=?, failed=?, status=? WHERE id=?")
    ->execute([$totCollected, $totFailed, $status, $runId]);

echo "Собрано сейчас: $collected, ошибок: $failed, ещё в работе: $pending\n";
echo "Итого по прогону #$runId: собрано $totCollected, ошибок/протухло $totFailed, ждём $totPending\n";
if ($totPending > 0) {
    echo "\nНе всё готово — запустите сбор ещё раз через несколько минут:\n";
    echo "  php bin/collect.php $projectId\n";
} else {
    echo "\n[OK] Прогон завершён. Позиции посчитаны.\n";
}
