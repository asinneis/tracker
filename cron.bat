@echo off
REM Обёртка для Планировщика заданий Windows.
REM Запускает bin\cron.php. Весь вывод дублируется в logs\cron.out.log.
"D:\xampp\php\php.exe" "D:\xampp\htdocs\tracker\bin\cron.php" >> "D:\xampp\htdocs\tracker\logs\cron.out.log" 2>&1
