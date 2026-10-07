<?php
/**
 * Многопоточный HTTP через curl_multi.
 * Отправляет пачку запросов параллельно (в N потоков) — так 4000 запросов
 * улетают за минуты, а не за полтора часа поштучно.
 *
 * $requests — массив запросов, ключ любой (мы используем id цели/задачи):
 *   [ 42 => ['url'=>..., 'method'=>'POST', 'headers'=>[...], 'body'=>'...'], ... ]
 * Возвращает массив с теми же ключами:
 *   [ 42 => ['status'=>200, 'body'=>'...', 'error'=>''], ... ]
 */

function httpMulti(array $requests, int $concurrency = 10): array
{
    $results = [];
    $queue   = array_keys($requests);   // ключи, которые ещё не запущены
    $handles = [];                      // (int)$ch => ключ запроса
    $mh      = curl_multi_init();

    // Внутренняя функция: поставить один запрос в работу.
    $start = function ($key) use (&$handles, $requests, $mh) {
        $r  = $requests[$key];
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $r['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $r['timeout'] ?? 30,
            CURLOPT_HTTPHEADER     => $r['headers'] ?? [],
        ];
        if (($r['method'] ?? 'GET') === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = $r['body'] ?? '';
        }
        curl_setopt_array($ch, $opts);
        curl_multi_add_handle($mh, $ch);
        $handles[(int) $ch] = $key;
    };

    // Наполняем стартовое «окно» из $concurrency запросов.
    for ($i = 0, $n = min($concurrency, count($queue)); $i < $n; $i++) {
        $start(array_shift($queue));
    }

    // Крутим, пока есть активные запросы или очередь.
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 1.0);   // ждём событий (до 1 сек), не жжём CPU

        while ($info = curl_multi_info_read($mh)) {
            $ch  = $info['handle'];
            $key = $handles[(int) $ch];

            $results[$key] = [
                'status' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'body'   => curl_multi_getcontent($ch),
                'error'  => curl_error($ch),
            ];

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($handles[(int) $ch]);

            // Освободился поток — берём следующий запрос из очереди.
            if ($queue) {
                $start(array_shift($queue));
            }
        }
    } while ($running || $handles || $queue);

    curl_multi_close($mh);
    return $results;
}
