<?php
/**
 * Логика импорта/экспорта ключей и отчётов (CSV). Без HTML.
 * CSV делаем «для Excel»: разделитель ';' и BOM — так русский Excel открывает
 * файл сразу по столбцам и без кракозябр.
 */

const CSV_DELIM = ';';

/** Разобрать загруженный текст в список фраз (по одной на строку, берём 1-й столбец). */
function parseKeywordsText(string $content): array
{
    $content = str_replace("\xEF\xBB\xBF", '', $content);   // убрать BOM, если есть
    $phrases = [];
    foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
        $line = trim($line);
        if ($line === '') { continue; }
        // если это CSV-строка — берём первую колонку (разделитель ; , или таб)
        $first = preg_split('/[;,\t]/', $line)[0];
        $first = trim($first, " \t\"'");
        if ($first !== '') { $phrases[] = $first; }
    }
    return $phrases;
}

/** Создать проект, вернуть id. */
function createProject(PDO $pdo, string $name, string $domain): int
{
    $st = $pdo->prepare("INSERT INTO projects (name, domain, timezone) VALUES (?, ?, 'Europe/Moscow')");
    $st->execute([$name, $domain]);
    return (int) $pdo->lastInsertId();
}

/**
 * Импорт фраз в проект: добавляет ключи (без дублей) и цели.
 * Возвращает ['added'=>N, 'skipped'=>M].
 */
function importKeywords(
    PDO $pdo, int $projectId, array $phrases,
    string $engine, string $region, int $depth, string $device = 'desktop'
): array {
    // Уже существующие фразы проекта (чтобы не плодить дубли).
    $st = $pdo->prepare("SELECT LOWER(phrase) FROM keywords WHERE project_id = ?");
    $st->execute([$projectId]);
    $exists = array_flip($st->fetchAll(PDO::FETCH_COLUMN));

    $insKw = $pdo->prepare("INSERT INTO keywords (project_id, phrase, is_active) VALUES (?, ?, 1)");
    $insTg = $pdo->prepare(
        "INSERT IGNORE INTO targets (project_id, keyword_id, engine, region_code, device, depth)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    $added = 0; $skipped = 0;
    foreach ($phrases as $phrase) {
        $key = mb_strtolower($phrase);
        if (isset($exists[$key])) { $skipped++; continue; }
        $exists[$key] = true;

        $insKw->execute([$projectId, $phrase]);
        $keywordId = (int) $pdo->lastInsertId();
        $insTg->execute([$projectId, $keywordId, $engine, $region, $device, $depth]);
        $added++;
    }
    return ['added' => $added, 'skipped' => $skipped];
}

/** Экспорт списка ключей проекта: [headerRow, dataRows]. */
function exportKeywordsRows(PDO $pdo, int $projectId): array
{
    $st = $pdo->prepare(
        "SELECT k.phrase, t.engine, t.region_code, t.depth, k.tags, k.is_active
           FROM keywords k
           JOIN targets t ON t.keyword_id = k.id
          WHERE k.project_id = ?
          ORDER BY k.phrase"
    );
    $st->execute([$projectId]);
    $header = ['Фраза', 'Поисковик', 'Регион', 'Глубина', 'Теги', 'Активна'];
    $rows = [];
    foreach ($st as $r) {
        $rows[] = [$r['phrase'], $r['engine'], $r['region_code'], $r['depth'], $r['tags'], $r['is_active'] ? 'да' : 'нет'];
    }
    return [$header, $rows];
}

/**
 * Экспорт позиций проекта за период [from, to]: матрица фраза × даты.
 * Возвращает [headerRow, dataRows].
 */
function exportPositionsRows(PDO $pdo, int $projectId, string $from, string $to): array
{
    // Даты съёмов в периоде.
    $st = $pdo->prepare(
        "SELECT DISTINCT p.run_date
           FROM positions p JOIN targets t ON t.id = p.target_id
          WHERE t.project_id = ? AND p.run_date BETWEEN ? AND ?
          ORDER BY p.run_date"
    );
    $st->execute([$projectId, $from, $to]);
    $dates = $st->fetchAll(PDO::FETCH_COLUMN);

    // Позиции.
    $st = $pdo->prepare(
        "SELECT k.phrase, t.engine, t.region_code, p.run_date, p.position
           FROM targets t
           JOIN keywords k ON k.id = t.keyword_id
           LEFT JOIN positions p ON p.target_id = t.id AND p.run_date BETWEEN ? AND ?
          WHERE t.project_id = ?
          ORDER BY k.phrase"
    );
    $st->execute([$from, $to, $projectId]);

    $byPhrase = [];
    foreach ($st as $r) {
        $k = $r['phrase'] . "\x00" . $r['engine'] . "\x00" . $r['region_code'];
        if (!isset($byPhrase[$k])) {
            $byPhrase[$k] = ['phrase' => $r['phrase'], 'engine' => $r['engine'], 'region' => $r['region_code'], 'cells' => []];
        }
        if ($r['run_date'] !== null) {
            $byPhrase[$k]['cells'][$r['run_date']] = $r['position'];
        }
    }

    $header = array_merge(['Фраза', 'Поисковик', 'Регион'], array_map(fn($d) => date('d.m.Y', strtotime($d)), $dates));
    $rows = [];
    foreach ($byPhrase as $row) {
        $line = [$row['phrase'], $row['engine'], $row['region']];
        foreach ($dates as $d) {
            $line[] = $row['cells'][$d] ?? '';   // пусто, если в этот день не снимали/не в топе
        }
        $rows[] = $line;
    }
    return [$header, $rows];
}
