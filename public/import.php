<?php
/**
 * Импорт ключей (загрузка CSV/списком) и переход к выгрузкам.
 * Контроллер: обрабатывает форму через src/keywords.php и подключает шаблон.
 */

require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/dashboard.php';   // dashboardProjects()
require __DIR__ . '/../src/keywords.php';

$pdo      = db();
$result   = null;   // сообщение о результате импорта
$error    = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        // Проект: существующий или новый.
        $projectId = $_POST['project'] ?? '';
        if ($projectId === 'new') {
            $name   = trim($_POST['new_name'] ?? '');
            $domain = trim($_POST['new_domain'] ?? '');
            if ($name === '' || $domain === '') {
                throw new RuntimeException('Для нового проекта укажите название и домен.');
            }
            $projectId = createProject($pdo, $name, $domain);
        } else {
            $projectId = (int) $projectId;
            if ($projectId <= 0) { throw new RuntimeException('Выберите проект.'); }
        }

        // Фразы: из файла и/или из текстового поля.
        $content = '';
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $content .= file_get_contents($_FILES['file']['tmp_name']) . "\n";
        }
        $content .= $_POST['phrases'] ?? '';
        $phrases = parseKeywordsText($content);
        if (!$phrases) { throw new RuntimeException('Не найдено ни одной фразы.'); }

        $engine = $_POST['engine'] === 'google' ? 'google' : 'yandex';
        $region = trim($_POST['region'] ?? '213') ?: '213';
        $depth  = (int) ($_POST['depth'] ?? 100) ?: 100;

        $res = importKeywords($pdo, $projectId, $phrases, $engine, $region, $depth);
        $result = "Готово: добавлено {$res['added']}, пропущено дублей {$res['skipped']} "
                . "(проект #$projectId).";
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$projects = dashboardProjects($pdo);

require __DIR__ . '/views/import.php';
