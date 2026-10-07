<?php
/**
 * Подключение к базе данных.
 * Возвращает один общий объект PDO (создаётся при первом вызове).
 *
 * Использование в любом скрипте:
 *   require __DIR__ . '/src/db.php';
 *   $pdo = db();
 *   $rows = $pdo->query("SELECT * FROM projects")->fetchAll();
 */

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $config = require __DIR__ . '/../config.php';
        $c = $config['db'];

        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset={$c['charset']}";

        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            // Ошибки БД бросают исключения — их видно сразу, а не молча.
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            // Результаты как ассоциативные массивы: $row['phrase'].
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Настоящие подготовленные запросы (безопаснее).
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    return $pdo;
}
