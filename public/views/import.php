<?php
/** Шаблон импорта/экспорта. Ожидает: $projects, $result, $error. */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$today = date('Y-m-d');
$ago   = date('Y-m-d', strtotime('-90 days'));
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Импорт / экспорт — трекер позиций</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>
<div class="page">

  <header class="head">
    <h1 class="head__title">Импорт и экспорт</h1>
    <div class="head__subtitle"><a class="link" href="index.php">← к дашборду</a></div>
  </header>

  <?php if ($result): ?><div class="note note--ok"><?= $h($result) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="note note--err"><?= $h($error) ?></div><?php endif; ?>

  <!-- ─── Импорт ключей ─── -->
  <section class="card">
    <h2 class="card__title">Загрузить ключи</h2>
    <form class="form" method="post" enctype="multipart/form-data">
      <label class="form__row">
        <span class="form__label">Проект</span>
        <select class="form__control" name="project" id="projectSel" onchange="toggleNew()">
          <?php foreach ($projects as $p): ?>
            <option value="<?= (int) $p['id'] ?>"><?= $h($p['name']) ?> (<?= $h($p['domain']) ?>)</option>
          <?php endforeach; ?>
          <option value="new">➕ Новый проект…</option>
        </select>
      </label>

      <div id="newFields" style="display:none">
        <label class="form__row"><span class="form__label">Название</span>
          <input class="form__control" type="text" name="new_name" placeholder="Мой проект"></label>
        <label class="form__row"><span class="form__label">Домен</span>
          <input class="form__control" type="text" name="new_domain" placeholder="example.ru"></label>
      </div>

      <label class="form__row"><span class="form__label">Поисковик</span>
        <select class="form__control" name="engine">
          <option value="yandex">Яндекс</option>
          <option value="google">Google</option>
        </select></label>
      <label class="form__row"><span class="form__label">Регион</span>
        <input class="form__control" type="text" name="region" value="213" placeholder="213 = Москва"></label>
      <label class="form__row"><span class="form__label">Глубина</span>
        <select class="form__control" name="depth">
          <option value="100">Топ-100</option>
          <option value="20">Топ-20</option>
          <option value="10">Топ-10</option>
        </select></label>

      <label class="form__row"><span class="form__label">Файл CSV</span>
        <input class="form__control" type="file" name="file" accept=".csv,.txt"></label>
      <label class="form__row form__row--top"><span class="form__label">или список</span>
        <textarea class="form__control" name="phrases" rows="6" placeholder="По одной фразе на строку"></textarea></label>

      <div class="form__actions">
        <button class="btn btn--primary" type="submit">Загрузить ключи</button>
      </div>
      <p class="form__hint">Формат: по одной фразе на строку (или CSV, берётся первый столбец).
        Дубли внутри проекта пропускаются.</p>
    </form>
  </section>

  <!-- ─── Экспорт ─── -->
  <section class="card">
    <h2 class="card__title">Выгрузить в CSV (откроется в Excel)</h2>
    <form class="form" method="get" action="export.php">
      <input type="hidden" name="type" value="positions">
      <label class="form__row"><span class="form__label">Проект</span>
        <select class="form__control" name="project">
          <?php foreach ($projects as $p): ?>
            <option value="<?= (int) $p['id'] ?>"><?= $h($p['name']) ?> (<?= $h($p['domain']) ?>)</option>
          <?php endforeach; ?>
        </select></label>
      <label class="form__row"><span class="form__label">Период с</span>
        <input class="form__control" type="date" name="from" value="<?= $ago ?>"></label>
      <label class="form__row"><span class="form__label">по</span>
        <input class="form__control" type="date" name="to" value="<?= $today ?>"></label>
      <div class="form__actions">
        <button class="btn btn--primary" type="submit">Скачать позиции</button>
      </div>
    </form>

    <form class="form" method="get" action="export.php">
      <input type="hidden" name="type" value="keywords">
      <label class="form__row"><span class="form__label">Проект</span>
        <select class="form__control" name="project">
          <?php foreach ($projects as $p): ?>
            <option value="<?= (int) $p['id'] ?>"><?= $h($p['name']) ?> (<?= $h($p['domain']) ?>)</option>
          <?php endforeach; ?>
        </select></label>
      <div class="form__actions">
        <button class="btn" type="submit">Скачать список ключей</button>
      </div>
    </form>
  </section>

</div>
<script>
  function toggleNew() {
    document.getElementById('newFields').style.display =
      document.getElementById('projectSel').value === 'new' ? 'block' : 'none';
  }
</script>
</body>
</html>
