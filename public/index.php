<?php
/**
 * Контроллер дашборда (веб-корень).
 * Задача: получить данные через слой логики и передать их в шаблон.
 * Ни SQL, ни HTML здесь нет — они разнесены:
 *   src/dashboard.php      — логика и запросы
 *   public/views/dashboard.php — представление (HTML)
 */

require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/dashboard.php';

$pdo = db();

$projects  = dashboardProjects($pdo);
$projectId = (int) ($_GET['project'] ?? ($projects[0]['id'] ?? 0));
$data      = dashboardData($pdo, $projectId);

require __DIR__ . '/views/dashboard.php';
