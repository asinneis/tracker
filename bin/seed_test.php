<?php
/**
 * Загрузка ТЕСТОВЫХ данных: один проект + 8 фраз + цели (Яндекс, Москва).
 * Повторный запуск пересоздаёт тестовый проект заново (старый удаляется каскадом).
 *
 * Запуск:  D:\xampp\php\php.exe seed_test.php
 */

require __DIR__ . '/../src/db.php';
$pdo = db();

$projectName   = 'ТЕСТ (демо)';
$projectDomain = 'ozon.ru';       // отслеживаемый домен — ранжируется по товарным запросам
$region        = '213';           // 213 = Москва
$depth         = 100;             // топ-100 за один запрос

$phrases = [
    'кофемашина купить',
    'наушники',
    'ноутбук',
    'холодильник',
    'робот пылесос',
    'смартфон',
    'телевизор 55 дюймов',
    'микроволновая печь',
];

// Чисто пересоздаём тестовый проект (каскад удалит его ключи/цели/позиции).
$pdo->prepare("DELETE FROM projects WHERE name = ?")->execute([$projectName]);

$pdo->prepare(
    "INSERT INTO projects (name, domain, timezone) VALUES (?, ?, 'Europe/Moscow')"
)->execute([$projectName, $projectDomain]);
$projectId = (int) $pdo->lastInsertId();

$insKw = $pdo->prepare(
    "INSERT INTO keywords (project_id, phrase, is_active) VALUES (?, ?, 1)"
);
$insTg = $pdo->prepare(
    "INSERT INTO targets (project_id, keyword_id, engine, region_code, device, depth)
     VALUES (?, ?, 'yandex', ?, 'desktop', ?)"
);

foreach ($phrases as $phrase) {
    $insKw->execute([$projectId, $phrase]);
    $keywordId = (int) $pdo->lastInsertId();
    $insTg->execute([$projectId, $keywordId, $region, $depth]);
}

echo "Тестовый проект создан:\n";
echo "  id        = $projectId\n";
echo "  название  = $projectName\n";
echo "  домен     = $projectDomain\n";
echo "  фраз      = " . count($phrases) . "\n";
echo "  регион    = $region (Москва), глубина $depth\n\n";
echo "Готово. Теперь можно запускать съём:  php bin/submit.php $projectId\n";
