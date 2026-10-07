<?php
/**
 * Выгрузка CSV (скачивание файла).
 * Параметры (GET):
 *   type=positions&project=<id>&from=YYYY-MM-DD&to=YYYY-MM-DD  — позиции за период
 *   type=keywords&project=<id>                                 — список ключей
 */

require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/keywords.php';

$pdo       = db();
$type      = $_GET['type'] ?? 'positions';
$projectId = (int) ($_GET['project'] ?? 0);

if ($projectId <= 0) { http_response_code(400); exit('Не указан проект'); }

if ($type === 'keywords') {
    [$header, $rows] = exportKeywordsRows($pdo, $projectId);
    $filename = "keywords-project{$projectId}.csv";
} else {
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-90 days'));
    $to   = $_GET['to']   ?? date('Y-m-d');
    [$header, $rows] = exportPositionsRows($pdo, $projectId, $from, $to);
    $filename = "positions-project{$projectId}-{$from}_{$to}.csv";
}

// Заголовки для скачивания.
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");            // BOM — чтобы Excel увидел UTF-8
fputcsv($out, $header, CSV_DELIM);
foreach ($rows as $row) {
    fputcsv($out, $row, CSV_DELIM);
}
fclose($out);
