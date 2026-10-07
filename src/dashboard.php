<?php
/**
 * Логика дашборда: выборка и подготовка данных из БД.
 * Здесь НЕТ ни одной строчки HTML — только данные.
 * Контроллер (public/index.php) вызывает эти функции, результат уходит в шаблон.
 */

/** Список проектов для выпадающего списка. */
function dashboardProjects(PDO $pdo): array
{
    return $pdo->query("SELECT id, name, domain FROM projects ORDER BY name")->fetchAll();
}

/**
 * Данные матрицы «фразы × даты» по проекту.
 * Возвращает массив: project, dates (по возрастанию), rows, summary, lastDate.
 */
function dashboardData(PDO $pdo, int $projectId, int $maxDates = 12): array
{
    $project = null;
    if ($projectId > 0) {
        $st = $pdo->prepare("SELECT id, name, domain FROM projects WHERE id = ?");
        $st->execute([$projectId]);
        $project = $st->fetch() ?: null;
    }

    $dates = [];
    $rows  = [];

    if ($project) {
        // Последние даты съёмов (по убыванию), затем разворачиваем по возрастанию.
        $st = $pdo->prepare(
            "SELECT DISTINCT p.run_date
               FROM positions p JOIN targets t ON t.id = p.target_id
              WHERE t.project_id = ?
              ORDER BY p.run_date DESC
              LIMIT $maxDates"
        );
        $st->execute([$projectId]);
        $dates = array_reverse($st->fetchAll(PDO::FETCH_COLUMN));

        // Все цели проекта + их позиции по датам.
        $st = $pdo->prepare(
            "SELECT t.id AS target_id, k.phrase, t.engine, t.region_code,
                    p.run_date, p.position, p.is_absent
               FROM targets t
               JOIN keywords k ON k.id = t.keyword_id
               LEFT JOIN positions p ON p.target_id = t.id
              WHERE t.project_id = ?
              ORDER BY k.phrase"
        );
        $st->execute([$projectId]);
        foreach ($st as $r) {
            $tid = $r['target_id'];
            if (!isset($rows[$tid])) {
                $rows[$tid] = [
                    'phrase' => $r['phrase'],
                    'engine' => $r['engine'],
                    'region' => $r['region_code'],
                    'cells'  => [],
                ];
            }
            if ($r['run_date'] !== null) {
                $rows[$tid]['cells'][$r['run_date']] = [
                    'pos'    => $r['position'] !== null ? (int) $r['position'] : null,
                    'absent' => (bool) $r['is_absent'],
                ];
            }
        }

        // Для каждой строки заполняем все показанные даты и считаем дельту
        // относительно предыдущей показанной даты (плюс = рост вверх).
        foreach ($rows as &$row) {
            $prev = null;
            foreach ($dates as $d) {
                $cell = $row['cells'][$d] ?? ['pos' => null, 'absent' => true];
                $cell['delta'] = ($prev !== null && $cell['pos'] !== null) ? $prev - $cell['pos'] : null;
                $row['cells'][$d] = $cell;
                $prev = $cell['pos'];
            }
        }
        unset($row);
    }

    $lastDate = $dates ? end($dates) : null;                            // самая свежая
    $prevDate = (count($dates) >= 2) ? $dates[count($dates) - 2] : null; // предыдущий съём

    // Сводка по последней дате + её изменение к предыдущему съёму
    // (положительная дельта = улучшение, чтобы фронт красил единообразно).
    $summary = dashboardSummary($rows, $lastDate);
    if ($prevDate !== null) {
        $prev = dashboardSummary($rows, $prevDate);
        $summary['d_top3']       = $summary['top3']  - $prev['top3'];
        $summary['d_top10']      = $summary['top10'] - $prev['top10'];
        $summary['d_top30']      = $summary['top30'] - $prev['top30'];
        $summary['d_absent']     = $prev['absent']   - $summary['absent'];       // меньше — лучше
        $summary['d_visibility'] = $summary['visibility'] - $prev['visibility'];
        $summary['d_avg']        = ($summary['avg'] !== null && $prev['avg'] !== null)
            ? round($prev['avg'] - $summary['avg'], 1)                           // ниже позиция — лучше
            : null;
    }

    return [
        'project'  => $project,
        'dates'    => array_reverse($dates),   // новые слева, старые справа
        'rows'     => $rows,
        'lastDate' => $lastDate,
        'summary'  => $summary,
    ];
}

/** Сводка по последней дате: всего, топ-3/10/30, не в топе, средняя, видимость. */
function dashboardSummary(array $rows, ?string $lastDate): array
{
    $total = count($rows);
    $top3 = $top10 = $top30 = $absent = 0;
    $sum = $cnt = 0;

    foreach ($rows as $row) {
        $c = $row['cells'][$lastDate] ?? null;
        if (!$c) { continue; }
        if ($c['absent'] || $c['pos'] === null) { $absent++; continue; }
        $pos = $c['pos'];
        $sum += $pos; $cnt++;
        if ($pos <= 3)  { $top3++; }
        if ($pos <= 10) { $top10++; }
        if ($pos <= 30) { $top30++; }
    }

    return [
        'total'      => $total,
        'top3'       => $top3,
        'top10'      => $top10,
        'top30'      => $top30,
        'absent'     => $absent,
        'avg'        => $cnt ? round($sum / $cnt, 1) : null,
        'visibility' => $total ? (int) round($top10 / $total * 100) : 0,
    ];
}
