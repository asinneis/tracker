<?php
/**
 * Проверочный скрипт: убеждаемся, что PHP видит базу и все таблицы.
 * Запуск из командной строки:
 *   D:\xampp\php\php.exe check.php
 */

require __DIR__ . '/../src/db.php';

echo "== Проверка связки PHP + база данных ==\n\n";

try {
    $pdo = db();
    echo "[OK] Подключение к базе установлено\n\n";

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Таблиц найдено: " . count($tables) . "\n";

    foreach ($tables as $t) {
        $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo sprintf("  %-16s %s строк\n", $t, $count);
    }

    echo "\n[OK] Всё работает. Можно двигаться дальше.\n";

} catch (Throwable $e) {
    echo "[ОШИБКА] " . $e->getMessage() . "\n";
    exit(1);
}
