<?php
/**
 * Разовый тест связи с Yandex Search API (отложенный / async режим).
 * Отправляет ОДИН запрос и проверяет, что Яндекс его принял (значит ключ+роль ОК).
 * Результат выдачи НЕ забираем — для проверки доступа достаточно получить operation_id.
 *
 * Запуск:  D:\xampp\php\php.exe test_yandex.php
 */

$config = require __DIR__ . '/../config.php';
$y = $config['yandex'];

// Защита от запуска без ключа.
if (str_starts_with($y['api_key'], 'ВПИШИТЕ')) {
    exit("[ОШИБКА] API-ключ не вписан в config.php (строка 30).\n");
}

// Тело запроса. Для теста берём топ-10 по России, формат XML.
$body = [
    'query' => [
        'searchType'  => 'SEARCH_TYPE_RU',        // поиск по yandex.ru
        'queryText'   => 'кофемашина купить',      // любая безобидная фраза
        'familyMode'  => 'FAMILY_MODE_NONE',
        'page'        => '0',
        'fixTypoMode' => 'FIX_TYPO_MODE_ON',
    ],
    'groupSpec' => [
        'groupMode'    => 'GROUP_MODE_FLAT',
        'groupsOnPage' => '10',                    // топ-10 (в бою будет 100)
        'docsInGroup'  => '1',
    ],
    'region'         => '225',                     // 225 = Россия
    'l10n'           => 'LOCALIZATION_RU',
    'folderId'       => $y['folder_id'],
    'responseFormat' => 'FORMAT_XML',
];

echo "== Тест связи с Yandex Search API (async) ==\n\n";
echo "Эндпоинт: {$y['submit_url']}\n";
echo "folder_id: {$y['folder_id']}\n\n";

$ch = curl_init($y['submit_url']);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER     => [
        'Authorization: Api-Key ' . $y['api_key'],
        'Content-Type: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
]);

$response = curl_exec($ch);
$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$netErr   = curl_error($ch);
curl_close($ch);

if ($response === false) {
    exit("[ОШИБКА сети] $netErr\n");
}

echo "HTTP-статус: $status\n";
$data = json_decode($response, true);

if ($status === 200 && isset($data['id'])) {
    echo "\n[OK] Запрос принят! Яндекс завёл отложенную задачу.\n";
    echo "     operation_id = {$data['id']}\n";
    echo "     done         = " . var_export($data['done'] ?? null, true) . "\n\n";
    echo "Ключ и роль рабочие. Результат забирается позже по этому operation_id.\n";
} else {
    echo "\n[ОШИБКА] Яндекс не принял запрос. Полный ответ сервера:\n";
    echo $response . "\n\n";
    echo "Частые причины: неверная роль сервисного аккаунта, не тот folder_id,\n";
    echo "или Search API не активирован в облаке.\n";
}
