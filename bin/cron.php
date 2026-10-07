<?php
/**
 * ЕДИНАЯ ТОЧКА ВХОДА для автозапуска (Планировщик заданий Windows).
 * Запускать каждые несколько минут — скрипт сам смотрит по базе и расписанию,
 * что пора делать:
 *   1) в дни съёма после заданного часа — поставить задачи (submit) по всем проектам;
 *   2) всегда — забрать готовые результаты (collect) для задач в ожидании;
 *   3) после завершения съёма — один раз за день сделать бэкап базы (mysqldump).
 *
 * Ручной запуск (для проверки):  D:\xampp\php\php.exe cron.php
 */

require __DIR__ . '/../src/db.php';
$config = require __DIR__ . '/../config.php';
date_default_timezone_set($config['app']['timezone']);

$pdo = db();
$dir = __DIR__;

// --- Лог ---
$logDir = $config['app']['log_dir'];
$logFile = $logDir . '/cron-' . date('Y-m-d') . '.log';
function logln(string $msg): void {
    global $logFile;
    $line = '[' . date('H:i:s') . '] ' . $msg . "\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

// --- Запуск другого PHP-скрипта проекта, вывод — в лог ---
function runScript(string $script, int $projectId): void {
    global $dir;
    $cmd = '"' . PHP_BINARY . '" "' . $dir . DIRECTORY_SEPARATOR . $script . '" ' . $projectId;
    exec($cmd . ' 2>&1', $out);
    foreach ($out as $l) { logln("    | $l"); }
}

$today  = date('Y-m-d');
$dow    = (int) date('N');   // 1=Пн ... 7=Вс
$hour   = (int) date('G');   // 0..23
$sched  = $config['schedule'];

logln("=== cron старт: $today, день недели $dow, час $hour ===");

$projects = $pdo->query("SELECT id, name FROM projects ORDER BY id")->fetchAll();
if (!$projects) { logln("Проектов нет — выходим."); exit; }

// --- 1) ПОСТАНОВКА задач (в дни съёма, после submit_hour) ---
$isRunDay = in_array($dow, $sched['weekdays'], true);
if ($isRunDay && $hour >= (int) $sched['submit_hour']) {
    foreach ($projects as $p) {
        // Уже ставили сегодня? (есть задачи в сегодняшнем прогоне)
        $cnt = (int) $pdo->query(
            "SELECT COUNT(*) FROM provider_tasks pt
               JOIN runs r ON r.id = pt.run_id
              WHERE r.project_id = {$p['id']} AND r.run_date = '$today'"
        )->fetchColumn();
        if ($cnt === 0) {
            logln("Постановка задач: проект #{$p['id']} {$p['name']}");
            runScript('submit.php', (int) $p['id']);
        }
    }
} else {
    logln("Сегодня не день съёма или рано для постановки — пропускаем submit.");
}

// --- 2) СБОР результатов (всегда, где есть ожидающие задачи) ---
foreach ($projects as $p) {
    $pending = (int) $pdo->query(
        "SELECT COUNT(*) FROM provider_tasks pt
           JOIN runs r ON r.id = pt.run_id
          WHERE r.project_id = {$p['id']} AND pt.state = 'submitted'"
    )->fetchColumn();
    if ($pending > 0) {
        logln("Сбор результатов: проект #{$p['id']} {$p['name']} (в ожидании: $pending)");
        runScript('collect.php', (int) $p['id']);
    }
}

// --- 3) БЭКАП базы (один раз в день, после завершения съёма) ---
if (!empty($sched['backup']) && $isRunDay) {
    $backupFile = $config['app']['backup_dir'] . '/tracker-' . $today . '.sql';
    // Есть ли сегодня завершённый прогон и ещё нет бэкапа?
    $doneToday = (int) $pdo->query(
        "SELECT COUNT(*) FROM runs WHERE run_date='$today' AND status IN ('done','partial')"
    )->fetchColumn();
    if ($doneToday > 0 && !file_exists($backupFile)) {
        $dump = 'D:/xampp/mysql/bin/mysqldump.exe';
        $cmd = '"' . $dump . '" --default-character-set=utf8mb4 -u '
             . escapeshellarg($config['db']['user'])
             . ($config['db']['pass'] !== '' ? ' -p' . escapeshellarg($config['db']['pass']) : '')
             . ' ' . escapeshellarg($config['db']['name'])
             . ' > "' . $backupFile . '"';
        exec($cmd . ' 2>&1', $o, $code);
        logln($code === 0 ? "Бэкап сделан: $backupFile" : "ОШИБКА бэкапа (код $code)");
    }
}

logln("=== cron конец ===\n");
