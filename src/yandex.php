<?php
/**
 * Работа с Yandex Search API v2 (отложенный режим) + подсчёт позиции.
 * Формат API проверен на практике (см. test_yandex*.php).
 */

/**
 * Собрать запрос на ПОСТАНОВКУ задачи (для httpMulti).
 * $depth — глубина топа (10/20/100), уходит в groupsOnPage.
 */
function yandexSubmitRequest(array $y, string $query, string $region, int $depth): array
{
    $body = [
        'query' => [
            'searchType'  => 'SEARCH_TYPE_RU',
            'queryText'   => $query,
            'familyMode'  => 'FAMILY_MODE_NONE',
            'page'        => '0',
            'fixTypoMode' => 'FIX_TYPO_MODE_ON',
        ],
        'groupSpec' => [
            'groupMode'    => 'GROUP_MODE_FLAT',
            'groupsOnPage' => (string) min($depth, 100),  // максимум 100 за запрос
            'docsInGroup'  => '1',
        ],
        'region'         => $region,
        'l10n'           => 'LOCALIZATION_RU',
        'folderId'       => $y['folder_id'],
        'responseFormat' => 'FORMAT_XML',
    ];

    return [
        'url'     => $y['submit_url'],
        'method'  => 'POST',
        'headers' => [
            'Authorization: Api-Key ' . $y['api_key'],
            'Content-Type: application/json',
        ],
        'body'    => json_encode($body, JSON_UNESCAPED_UNICODE),
    ];
}

/**
 * Разобрать ответ на постановку. Возвращает operation_id или null.
 */
function yandexParseSubmit(array $resp, ?string &$error = null): ?string
{
    if ($resp['status'] !== 200) {
        $error = "HTTP {$resp['status']}: {$resp['body']}";
        return null;
    }
    $data = json_decode($resp['body'], true);
    if (!isset($data['id'])) {
        $error = 'Нет id в ответе: ' . $resp['body'];
        return null;
    }
    return $data['id'];
}

/**
 * Собрать запрос на ЗАБОР результата по operation_id.
 */
function yandexFetchRequest(array $y, string $operationId): array
{
    return [
        'url'     => $y['result_url'] . $operationId,
        'method'  => 'GET',
        'headers' => ['Authorization: Api-Key ' . $y['api_key']],
    ];
}

/**
 * Разобрать ответ с результатом.
 * Возвращает ['done'=>bool, 'results'=>[['domain'=>,'url'=>], ...], 'error'=>?string].
 * results — органика по порядку (позиция = индекс + 1).
 */
function yandexParseResult(array $resp): array
{
    $out = ['done' => false, 'results' => [], 'error' => null];

    if ($resp['status'] !== 200) {
        $out['error'] = "HTTP {$resp['status']}: {$resp['body']}";
        return $out;
    }

    $data = json_decode($resp['body'], true);

    // Задача ещё в работе.
    if (empty($data['done'])) {
        return $out; // done=false
    }
    $out['done'] = true;

    // Задача завершилась ошибкой на стороне Яндекса.
    if (isset($data['error'])) {
        $out['error'] = json_encode($data['error'], JSON_UNESCAPED_UNICODE);
        return $out;
    }

    $b64 = $data['response']['rawData'] ?? '';
    if ($b64 === '') {
        $out['error'] = 'Нет rawData в ответе';
        return $out;
    }

    $xml = simplexml_load_string(base64_decode($b64));
    if ($xml === false) {
        $out['error'] = 'Не удалось разобрать XML';
        return $out;
    }
    if (isset($xml->response->error)) {
        $out['error'] = 'Ошибка выдачи: ' . (string) $xml->response->error;
        return $out;
    }

    foreach ($xml->response->results->grouping as $grouping) {
        foreach ($grouping->group as $group) {
            $out['results'][] = [
                'domain' => (string) ($group->doc->domain ?? ''),
                'url'    => (string) ($group->doc->url ?? ''),
            ];
        }
    }

    return $out;
}

/**
 * Нормализация домена (ЯВНОЕ правило, фиксируем и не меняем):
 *  - убираем протокол http/https
 *  - приводим к нижнему регистру
 *  - убираем ведущий www.
 *  - берём только host (до первого /)
 * Поддомены НЕ схлопываются: shop.example.ru != example.ru.
 */
function normalizeDomain(string $d): string
{
    $d = strtolower(trim($d));
    $d = preg_replace('#^https?://#', '', $d);
    $d = explode('/', $d)[0];
    $d = preg_replace('#^www\.#', '', $d);
    return $d;
}

/**
 * Найти позицию домена в выдаче.
 * Дедупликация: берём ПЕРВОЕ вхождение.
 * Возвращает ['position'=>?int, 'found_url'=>?string, 'is_absent'=>bool].
 */
function findPosition(array $results, string $projectDomain): array
{
    $target = normalizeDomain($projectDomain);
    foreach ($results as $i => $r) {
        if (normalizeDomain($r['domain']) === $target) {
            return ['position' => $i + 1, 'found_url' => $r['url'], 'is_absent' => false];
        }
    }
    return ['position' => null, 'found_url' => null, 'is_absent' => true];
}
