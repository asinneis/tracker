<?php
/**
 * ДЕМО-история для тестового проекта: дорисовывает позиции за прошлые 6 дат,
 * чтобы дашборд-матрица (в стиле Topvisor) выглядела наполненной.
 * ВНИМАНИЕ: это синтетические данные ТОЛЬКО для демо-проекта, для превью интерфейса.
 *
 * Запуск:  D:\xampp\php\php.exe seed_history.php
 */

require __DIR__ . '/../src/db.php';
$pdo = db();

$projectName = 'ТЕСТ (демо)';
$project = $pdo->prepare("SELECT id FROM projects WHERE name = ?");
$project->execute([$projectName]);
$projectId = (int) $project->fetchColumn();
if (!$projectId) {
    exit("Демо-проект не найден. Сначала: php seed_test.php\n");
}

// Цели проекта и их сегодняшняя (реальная) позиция.
$targets = $pdo->query(
    "SELECT t.id AS target_id,
            (SELECT position FROM positions p
              WHERE p.target_id = t.id ORDER BY p.run_date DESC LIMIT 1) AS cur
       FROM targets t WHERE t.project_id = $projectId"
)->fetchAll();

$today = new DateTimeImmutable('today');

$upPos = $pdo->prepare(
    "INSERT INTO positions
        (target_id, run_date, position, found_url, previous_position, delta, in_top10, is_absent)
     VALUES (?, ?, ?, NULL, ?, ?, ?, 0)
     ON DUPLICATE KEY UPDATE
        position=VALUES(position), previous_position=VALUES(previous_position),
        delta=VALUES(delta), in_top10=VALUES(in_top10), is_absent=VALUES(is_absent)"
);
$upRun = $pdo->prepare(
    "INSERT INTO runs (project_id, run_date, status, requested, collected)
     VALUES (?, ?, 'done', ?, ?)
     ON DUPLICATE KEY UPDATE status='done'"
);

$cntTargets = count($targets);
$dates = [];
for ($d = 6; $d >= 0; $d--) {
    $dates[] = $today->modify("-$d day")->format('Y-m-d');
}

foreach ($targets as $t) {
    $real = $t['cur'] !== null ? (int) $t['cur'] : rand(15, 40);

    // Случайное блуждание: раньше чуть хуже, к сегодняшнему дню сходится к real.
    $prev = null;
    foreach ($dates as $i => $date) {
        $daysAgo = 6 - $i;
        if ($date === $today->format('Y-m-d')) {
            $pos = $real;                    // сегодня — реальная позиция, не трогаем сильно
        } else {
            $pos = max(1, min(100, $real + $daysAgo + rand(-2, 2)));
        }
        $delta = ($prev !== null) ? $prev - $pos : null;
        $inTop10 = $pos <= 10 ? 1 : 0;

        // Сегодняшнюю реальную строку не перезаписываем позицией/URL — только дельту/prev.
        if ($date === $today->format('Y-m-d')) {
            $pdo->prepare(
                "UPDATE positions SET previous_position=?, delta=?, in_top10=?
                  WHERE target_id=? AND run_date=?"
            )->execute([$prev, $delta, $inTop10, $t['target_id'], $date]);
        } else {
            $upPos->execute([$t['target_id'], $date, $pos, $prev, $delta, $inTop10]);
        }
        $prev = $pos;
    }
}

// Прогоны для каждой даты (для консистентности).
foreach ($dates as $date) {
    $upRun->execute([$projectId, $date, $cntTargets, $cntTargets]);
}

echo "Демо-история добавлена: " . count($dates) . " дат для $cntTargets целей.\n";
echo "Откройте дашборд — матрица будет заполнена.\n";
