<?php
/**
 * Шаблон дашборда (представление). Только вывод данных — без запросов к БД.
 * Ожидает переменные от контроллера: $projects, $projectId, $data.
 */

// Мелкие помощники представления (форматирование, не логика).
$h   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$dmy = fn($d) => date('d.m', strtotime($d));

// BEM-модификатор ячейки по позиции.
$cellMod = function (?int $pos, bool $absent): string {
    if ($absent || $pos === null) return 'cell--none';
    if ($pos <= 3)  return 'cell--top3';
    if ($pos <= 10) return 'cell--top10';
    if ($pos <= 30) return 'cell--top30';
    if ($pos <= 50) return 'cell--top50';
    return 'cell--low';
};

// Значок изменения статистики (положительное = улучшение → зелёная ▲).
$stat = function ($imp, string $suffix = '') {
    if ($imp === null || $imp == 0) return '';
    $cls = $imp > 0 ? 'delta--up' : 'delta--down';
    $arrow = $imp > 0 ? '▲' : '▼';
    return '<span class="tile__delta ' . $cls . '">' . $arrow . abs($imp) . $suffix . '</span>';
};

$project = $data['project'];
$dates   = $data['dates'];
$rows    = $data['rows'];
$s       = $data['summary'];
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Трекер позиций</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>
<div class="page">

  <header class="head">
    <h1 class="head__title">Трекер позиций</h1>
    <div class="head__subtitle">
      <?= $project ? $h($project['name']) . ' · ' . $h($project['domain']) : 'Нет проектов' ?>
      <?= $data['lastDate'] ? ' · последний съём ' . $h($dmy($data['lastDate'])) : '' ?>
    </div>
  </header>

  <?php if ($projects): ?>
    <div class="toolbar">
      <form method="get">
        <select class="toolbar__select" name="project" onchange="this.form.submit()">
          <?php foreach ($projects as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= $p['id'] == $projectId ? 'selected' : '' ?>>
              <?= $h($p['name']) ?> (<?= $h($p['domain']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <a class="btn" href="import.php">＋ Загрузить ключи</a>
      <a class="btn" href="export.php?type=positions&project=<?= (int) $projectId ?>">⬇ Выгрузить CSV</a>
    </div>

    <div class="tiles">
      <div class="tile"><div class="tile__num"><?= $s['total'] ?></div><div class="tile__label">Фраз</div></div>
      <div class="tile"><div class="tile__num"><?= $s['visibility'] ?>% <?= $stat($s['d_visibility'] ?? null) ?></div><div class="tile__label">Видимость (топ-10)</div></div>
      <div class="tile"><div class="tile__num"><?= $s['avg'] ?? '—' ?> <?= $stat($s['d_avg'] ?? null) ?></div><div class="tile__label">Средняя позиция</div></div>
      <div class="tile"><div class="tile__num"><?= $s['top3'] ?> <?= $stat($s['d_top3'] ?? null) ?></div><div class="tile__label">Топ-3</div></div>
      <div class="tile"><div class="tile__num"><?= $s['top10'] ?> <?= $stat($s['d_top10'] ?? null) ?></div><div class="tile__label">Топ-10</div></div>
      <div class="tile"><div class="tile__num"><?= $s['top30'] ?> <?= $stat($s['d_top30'] ?? null) ?></div><div class="tile__label">Топ-30</div></div>
      <div class="tile"><div class="tile__num"><?= $s['absent'] ?> <?= $stat($s['d_absent'] ?? null) ?></div><div class="tile__label">Не в топе</div></div>
    </div>
  <?php endif; ?>

  <?php if ($rows && $dates): ?>
    <div class="matrix">
      <table class="matrix__table">
        <thead>
          <tr class="matrix__row">
            <th class="matrix__th matrix__kw matrix__th--sortable" data-type="text" onclick="sortMatrix(this)">Фраза</th>
            <?php foreach ($dates as $d): ?>
              <th class="matrix__th matrix__th--sortable" data-type="num" onclick="sortMatrix(this)"><?= $h($dmy($d)) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr class="matrix__row">
              <td class="matrix__kw" data-sort="<?= $h(mb_strtolower($row['phrase'])) ?>">
                <div class="matrix__phrase"><?= $h($row['phrase']) ?></div>
                <div class="matrix__meta"><?= $h($row['engine']) ?> · рег. <?= $h($row['region']) ?></div>
              </td>
              <?php foreach ($dates as $d): ?>
                <?php $c = $row['cells'][$d]; ?>
                <td class="matrix__cell cell <?= $cellMod($c['pos'], $c['absent']) ?>"
                    data-sort="<?= $c['pos'] ?? 999999 ?>">
                  <?= $c['pos'] ?? '—' ?>
                  <?php if (!empty($c['delta'])): ?>
                    <span class="delta <?= $c['delta'] > 0 ? 'delta--up' : 'delta--down' ?>">
                      <?= $c['delta'] > 0 ? '▲' : '▼' ?><?= abs($c['delta']) ?>
                    </span>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="empty">
      <?php if ($projects): ?>
        По проекту ещё нет данных. Запустите:
        <code class="empty__code">php bin/submit.php <?= (int) $projectId ?></code>,
        затем <code class="empty__code">php bin/collect.php <?= (int) $projectId ?></code>
      <?php else: ?>
        Проектов нет. Загрузите данные: <code class="empty__code">php bin/seed_test.php</code>
      <?php endif; ?>
    </div>
  <?php endif; ?>

</div>
<script src="assets/js/dashboard.js"></script>
</body>
</html>
