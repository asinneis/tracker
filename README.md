# Трекер позиций (Яндекс + Google)

Свой сервис съёма позиций для SEO-проектов.
**7 проектов, ~3 545 ключей, съём 2 раза в неделю.**

## Стек

- PHP 8 + MariaDB (из XAMPP), без фреймворка и Composer
- Встроенные средства PHP: PDO, `curl_multi`, `simplexml_load_string`, `json_decode`, `gzcompress`, `fputcsv`
- Запуск — **только из командной строки** (`php cli.php`), не через Apache (там таймаут 30 сек)

## Источники данных

| Поисковик | Провайдер | Режим | Цена |
|-----------|-----------|-------|------|
| Яндекс | Yandex Search API v2 | отложенный (deferred), топ-100 за 1 запрос | ~30,5 ₽ / 1000 |
| Google | XMLRiver | обычный, топ набирается параметром `page` (по 10) | 25 ₽ / 1000 |
| Свой сайт | Google Search Console API | средняя позиция, задержка ~3 дня | бесплатно |

## Установка (Windows + XAMPP)

1. **Скопировать конфиг и вписать секреты:**
   ```
   copy config.example.php config.php
   ```
   Открыть `config.php`, вписать пароль БД и API-ключи. Этот файл в git не попадёт.

2. **Создать таблицы:**
   ```
   D:\xampp\mysql\bin\mysql.exe -u root -p < schema.sql
   ```
   (пароль в XAMPP по умолчанию пустой — просто нажать Enter),
   либо вставить содержимое `schema.sql` в phpMyAdmin → вкладка SQL.

3. **Проверить связь с БД:**
   ```
   D:\xampp\php\php.exe bin/check.php
   ```

## Быстрый старт (демо)

```
D:\xampp\php\php.exe bin/seed_test.php      # демо-проект + фразы
D:\xampp\php\php.exe bin/submit.php 1   # поставить задачи в Яндекс
D:\xampp\php\php.exe bin/collect.php 1  # забрать результаты и посчитать позиции
```
Дашборд: http://tracker.local/public/

## Документация

- **[docs/OPERATION.md](docs/OPERATION.md)** — запуск съёма вручную и автозапуск (Планировщик Windows).
- **[docs/DEVELOPER.md](docs/DEVELOPER.md)** — архитектура, схема БД, пайплайн, как расширять (Google).
- **[docs/ADMIN.md](docs/ADMIN.md)** — веб-дашборд: доступ, настройка vhost/hosts, обслуживание.

## Расписание

Планировщик заданий Windows раз в несколько минут запускает `cron.bat` → `cron.php`.
Скрипт сам смотрит по расписанию и таблице `provider_tasks`, что пора делать:
поставить задачи → забрать результат → посчитать позиции → сделать бэкап.
Дни и час съёма настраиваются в `config.php`, блок `'schedule'`.

## Важные правила подсчёта (не менять)

- Позиция **органическая** — так и называть в отчётах.
- Дедупликация домена: берём **первое** вхождение.
- «Нет в топ-N» (`is_absent`) и «ошибка/задача истекла» (`state`) — **разные** вещи.
- Дата съёма — из прогона (`runs.run_date`), а не из момента ответа API.

## Резервные копии

После каждого съёма — `mysqldump`. История позиций невосстановима:
второй раз выдачу не купить.

## Структура

```
config.example.php   шаблон настроек (в git)
config.php           реальные настройки, секреты (НЕ в git)
schema.sql           создание таблиц

src/                 переиспользуемая логика (не запускается напрямую)
  db.php             подключение к БД
  http.php           curl_multi (многопоточные запросы)
  yandex.php         API Яндекса, разбор XML, подсчёт позиции
  dashboard.php      логика дашборда (выборка данных, без HTML)

bin/                 CLI-скрипты (запуск из командной строки)
  check.php          проверка связи с БД
  submit.php         постановка задач Яндекса   (php bin/submit.php <id>)
  collect.php        сбор результатов и позиций (php bin/collect.php <id>)
  cron.php           автозапуск (submit + collect + backup)
  seed_test.php      демо-данные
  seed_history.php   демо-история для превью

public/              веб-корень (отдаётся Apache)
  index.php          контроллер дашборда (без SQL и HTML)
  views/dashboard.php      шаблон (HTML)
  assets/css/dashboard.css стили (БЭМ)

cron.bat             обёртка для Планировщика Windows (вызывает bin/cron.php)
docs/                OPERATION / DEVELOPER / ADMIN
logs/ exports/ backups/   рабочие папки
```
