<?php
/**
 * ШАБЛОН конфигурации. Этот файл коммитим в git (он без секретов).
 *
 * Как пользоваться:
 *   1. Скопируйте этот файл рядом и назовите копию config.php
 *   2. Впишите в config.php реальные пароли и ключи
 *   3. config.php в git НЕ попадёт — он в .gitignore
 *
 * Внутри кода настройки читаются так:
 *   $config = require __DIR__ . '/config.php';
 *   $config['db']['name'];
 */

return [

    // === База данных (MariaDB из XAMPP) ===
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'tracker',
        'user'    => 'root',      // в XAMPP по умолчанию root
        'pass'    => '',          // в XAMPP по умолчанию пароль пустой
        'charset' => 'utf8mb4',   // обязательно utf8mb4
    ],

    // === Яндекс — Search API v2 (отложенный / deferred режим) ===
    // Ключ и folder_id берутся в Yandex Cloud у сервисного аккаунта.
    'yandex' => [
        'api_key'   => 'ВПИШИТЕ_КЛЮЧ_СЕРВИСНОГО_АККАУНТА',
        'folder_id' => 'ВПИШИТЕ_FOLDER_ID',
        // Базовые URL API (менять обычно не нужно):
        'submit_url' => 'https://searchapi.api.cloud.yandex.net/v2/web/searchAsync',
        'result_url' => 'https://operation.api.cloud.yandex.net/operations/', // + operation_id
    ],

    // === Google — через XMLRiver ===
    // user (id) и key берутся в личном кабинете XMLRiver.
    'xmlriver' => [
        'user' => 'ВПИШИТЕ_USER_ID',
        'key'  => 'ВПИШИТЕ_KEY',
        'url'  => 'https://xmlriver.com/search/xml',
    ],

    // === Google Search Console API (бесплатно, добавим позже) ===
    'gsc' => [
        'credentials_json' => __DIR__ . '/gsc-service-account.json', // файл ключа, тоже в .gitignore
        'enabled'          => false,
    ],

    // === Общие параметры ===
    'app' => [
        'timezone'        => 'Europe/Moscow',
        'curl_concurrency' => 10,   // сколько запросов слать параллельно (curl_multi)
        'log_dir'         => __DIR__ . '/logs',
        'export_dir'      => __DIR__ . '/exports',
        'backup_dir'      => __DIR__ . '/backups',
    ],

    // === Расписание съёма (для cron.php / Планировщика Windows) ===
    'schedule' => [
        'weekdays'    => [1, 4],  // дни съёма: 1=Пн, 2=Вт ... 7=Вс (здесь Пн и Чт)
        'submit_hour' => 9,       // с какого часа утром ставить задачи (время ПК)
        'backup'      => true,    // делать mysqldump после завершения съёма
    ],
];
