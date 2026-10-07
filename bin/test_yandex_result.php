<?php
/**
 * Забирает результат отложенной задачи Yandex Search API по operation_id.
 * Опрашивает Яндекс, пока задача не будет готова (done=true), затем
 * распаковывает выдачу (base64 -> XML) и печатает топ сайтов.
 *
 * Запуск:  D:\xampp\php\php.exe test_yandex_result.php <operation_id>
 */

$config = require __DIR__ . '/../config.php';
$y = $config['yandex'];

$opId = $argv[1] ?? '';
if ($opId === '') {
    exit("Укажите operation_id:  php test_yandex_result.php <id>\n");
}

$url = $y['result_url'] . $opId;   // https://operation.api.cloud.yandex.net/operations/<id>

echo "== Забираю результат задачи $opId ==\n\n";

$maxTries = 12;      // сколько раз опросить
$waitSec  = 15;      // пауза между опросами
$data = null;

for ($i = 1; $i <= $maxTries; $i++) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Api-Key ' . $y['api_key']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);

    if ($status !== 200) {
        exit("[ОШИБКА] HTTP $status. Ответ:\n$resp\n");
    }

    if (!empty($data['done'])) {
        echo "Попытка $i: готово!\n\n";
        break;
    }
    echo "Попытка $i: ещё обрабатывается (done=false), жду {$waitSec} сек...\n";
    if ($i < $maxTries) {
        sleep($waitSec);
    }
}

if (empty($data['done'])) {
    exit("\nЗадача пока не готова. Запустите скрипт ещё раз позже тем же id "
       . "(результат хранится 12 часов).\n");
}

// Если задача завершилась ошибкой на стороне Яндекса.
if (isset($data['error'])) {
    exit("[ОШИБКА задачи] " . json_encode($data['error'], JSON_UNESCAPED_UNICODE) . "\n");
}

// Результат лежит в response.rawData как base64 -> XML.
$b64 = $data['response']['rawData'] ?? '';
if ($b64 === '') {
    exit("[ОШИБКА] В ответе нет rawData. Полный ответ:\n"
       . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
}

$xmlText = base64_decode($b64);
$xml = simplexml_load_string($xmlText);
if ($xml === false) {
    exit("[ОШИБКА] Не удалось разобрать XML выдачи.\n");
}

// Проверка на ошибку внутри самой выдачи.
if (isset($xml->response->error)) {
    exit("[ОШИБКА выдачи] " . (string)$xml->response->error . "\n");
}

echo "Топ выдачи Яндекса (органика):\n";
echo str_repeat('-', 60) . "\n";

$pos = 0;
foreach ($xml->response->results->grouping->group as $group) {
    $pos++;
    $doc    = $group->doc;
    $domain = (string)($doc->domain ?? '');
    $link   = (string)($doc->url ?? '');
    echo sprintf("%2d. %-28s %s\n", $pos, $domain, $link);
}

echo str_repeat('-', 60) . "\n";
echo "Всего позиций получено: $pos\n";
echo "\n[OK] Полный цикл отложенного режима работает: поставили -> забрали -> разобрали.\n";
