# Документация разработчика

Трекер позиций в Яндексе и Google. PHP 8 + MariaDB (XAMPP), **без фреймворка и
Composer** — только встроенные средства PHP (PDO, curl_multi, simplexml, gzcompress).
Всё, что снимает и считает, работает из CLI. Веб-часть — только просмотр.

---

## Структура проекта

Проект разделён на слои: **логика** (`src/`), **CLI-скрипты** (`bin/`),
**веб** (`public/`: контроллер → логика → шаблон; стили в отдельном БЭМ-файле).

```
config.example.php   шаблон настроек (в git)
config.php           реальные настройки с секретами (НЕ в git)
schema.sql           создание всех таблиц

src/                 переиспользуемая логика (НЕ запускается напрямую)
  db.php             функция db(): PDO — одно общее подключение
  http.php           httpMulti() — многопоточные запросы (curl_multi)
  yandex.php         сборка запросов, разбор XML, нормализация домена, подсчёт позиции
  dashboard.php      выборка и подготовка данных для дашборда (без HTML)

bin/                 CLI-скрипты (точки запуска)
  check.php          проверка связи с БД
  submit.php  <id>   постановка задач Яндекса по проекту
  collect.php <id>   сбор результатов и расчёт позиций
  cron.php           автозапуск (submit + collect + backup)
  seed_test.php      ДЕМО-проект и фразы
  seed_history.php   ДЕМО-история позиций (синтетика для превью)
  test_yandex*.php   разовые проверочные скрипты (можно удалить)

public/              веб-корень (отдаётся Apache)
  index.php          контроллер: получает данные и подключает шаблон (без SQL и HTML)
  views/dashboard.php      шаблон — только HTML + вывод
  assets/css/dashboard.css стили по методологии БЭМ

cron.bat             обёртка для Планировщика Windows (вызывает bin/cron.php)
logs/ exports/ backups/   рабочие папки (в git только .gitkeep)
docs/                этот и другие документы
```

---

## Схема базы данных

Кодировка везде **utf8mb4**. Полное определение — в `schema.sql`.

| Таблица | Роль |
|---------|------|
| `projects` | Сайты-проекты (имя, домен, таймзона). |
| `keywords` | Ключевые фразы проекта (phrase, tags, is_active). |
| `targets` | Что снимаем: ключ × engine × регион × устройство × **depth** (глубина топа). |
| `runs` | Прогон — один съём проекта на дату (`run_date`), счётчики requested/collected/failed. |
| `provider_tasks` | Очередь к API (заменяет Redis). `state`: queued→submitted→ready→collected / expired / failed. `external_id` = operation_id Яндекса, `expires_at` = submitted+12ч. |
| `serp_snapshots` | Сырая выдача (JSON, сжатый gzcompress, в MEDIUMBLOB) + sha256. Архив для пересчёта. |
| `positions` | Итог для графиков: (target_id, run_date) → position, found_url, previous_position, delta, in_top10, is_absent. |

Связи — внешними ключами с `ON DELETE CASCADE` (удаление проекта чистит всё ниже).

---

## Как работает пайплайн Яндекса (отложенный режим)

1. **submit** (`bin/submit.php`): создаёт `run` на сегодня → на каждую активную цель
   заводит `provider_tasks` (queued) → шлёт запросы пачкой через `httpMulti` →
   сохраняет `operation_id` в `external_id`, ставит `state=submitted`,
   `expires_at = now + 12h`.
2. Яндекс обрабатывает задачи асинхронно (от секунд до часов).
3. **collect** (`bin/collect.php`): опрашивает `submitted`-задачи → у готовых
   (`done=true`) забирает выдачу (base64 → XML) → пишет `serp_snapshots` →
   считает позицию → пишет `positions` → `state=collected`.
   Протухшие (expires_at прошёл) → `state=expired` (это НЕ «нет в топе»!).

Формат запроса/ответа Яндекса — в комментариях `src/yandex.php`.
Ключевой нюанс цены: использовать **searchAsync** (отложенный, 30,5 ₽/1000),
НЕ синхронный (488 ₽/1000).

---

## Правила подсчёта позиции (зафиксированы, не менять без причины)

- **Позиция — органическая.** Для Яндекса Search API и так отдаёт чистую органику
  без рекламы. Для Google/XMLRiver рекламу нужно **фильтровать** при разборе.
- **Нормализация домена** (`normalizeDomain` в `src/yandex.php`): нижний регистр,
  убрать протокол и `www.`, взять host до `/`. Поддомены НЕ схлопываются.
- **Дедупликация:** берём **первое** вхождение домена в выдаче.
- **`is_absent`** («нет в топ-N») и `state` («ошибка/протухло») — РАЗНЫЕ вещи.
  Обвал на графике из-за ошибки — недопустим, поэтому они разведены.
- **Дата** позиции = `run_date` прогона, а НЕ момент ответа API.
- `delta = previous_position - position` (плюс = рост вверх).

---

## Конвенции кода

- Настройки только через `require config.php` (никаких хардкодов ключей).
- Работа с БД — только через `db()` и подготовленные запросы (PDO, prepared).
- Сетевые запросы — через `httpMulti()` (параллельно), не поштучно.
- Русский текст в БД: при импорте из CLI `mysql.exe` всегда
  `--default-character-set=utf8mb4`.
- Комментарии в коде — по-русски, для новичка; поясняем «зачем», не только «что».

---

## Как добавить Google (XMLRiver) — план

1. В `config.php` уже есть блок `'xmlriver'` (user, key, url).
2. Сделать `src/xmlriver.php` по аналогии с `src/yandex.php`:
   - запрос к `https://xmlriver.com/search/xml` (обычный, синхронный — ответ сразу);
   - **глубина набирается параметром `page` по 10** (num=100 Google убрал):
     топ-20 = 2 запроса, топ-100 = 10 запросов. Глубина берётся из `targets.depth`.
   - при разборе **пропускать рекламные блоки**, брать первую органику.
3. В `bin/submit.php` / `bin/collect.php` (или отдельных google-скриптах) ветвить
   по `targets.engine`. XMLRiver синхронный, поэтому submit и collect для Google
   можно объединить в один проход.
4. `provider = 'xmlriver'` в `provider_tasks` и `serp_snapshots` уже предусмотрен.

---

## Локальный запуск и отладка

```
D:\xampp\php\php.exe bin/check.php          # проверка связи с БД
D:\xampp\php\php.exe bin/seed_test.php      # демо-данные
D:\xampp\php\php.exe bin/submit.php 1   # съём демо-проекта
D:\xampp\php\php.exe bin/collect.php 1
```
Дашборд: `http://tracker.local/public/` (см. `docs/ADMIN.md` про vhost/hosts).

Репозиторий: GitLab, приватный (`git push` — вход через браузер, Credential Manager).
`config.php` и содержимое `logs/ exports/ backups/` — в `.gitignore`.
