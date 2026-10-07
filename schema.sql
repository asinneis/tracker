-- =====================================================================
--  Трекер позиций — схема базы данных
--  СУБД: MariaDB (из XAMPP). Кодировка везде utf8mb4.
--
--  Как применить (из командной строки):
--    D:\xampp\mysql\bin\mysql.exe -u root -p tracker < schema.sql
--  или через phpMyAdmin: вкладка SQL -> вставить содержимое -> Выполнить.
--
--  Перед этим база должна существовать, см. первую команду ниже.
-- =====================================================================

-- Создаём базу, если её нет, и переключаемся в неё.
CREATE DATABASE IF NOT EXISTS `tracker`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE `tracker`;


-- ---------------------------------------------------------------------
-- projects — SEO-проекты (сайты, которые отслеживаем)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `projects` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(255) NOT NULL COMMENT 'Название проекта',
    `domain`     VARCHAR(255) NOT NULL COMMENT 'Основной домен, напр. example.ru',
    `timezone`   VARCHAR(64)  NOT NULL DEFAULT 'Europe/Moscow',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- keywords — ключевые фразы проекта
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `keywords` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `project_id` INT UNSIGNED NOT NULL,
    `phrase`     VARCHAR(400) NOT NULL COMMENT 'Сама фраза (лимит API 400 символов / 40 слов)',
    `tags`       VARCHAR(255) NULL COMMENT 'Метки через запятую для группировки в отчётах',
    `is_active`  TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = снимаем, 0 = выключена',
    PRIMARY KEY (`id`),
    KEY `idx_keywords_project` (`project_id`),
    CONSTRAINT `fk_keywords_project`
        FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- targets — что именно снимаем: ключ × поисковик × регион × устройство × глубина
-- Глубина (depth) — свойство КЛЮЧА, не расписания. Напр. приоритетные — 20, прочие — 10.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `targets` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `project_id`  INT UNSIGNED NOT NULL,
    `keyword_id`  INT UNSIGNED NOT NULL,
    `engine`      ENUM('yandex','google') NOT NULL,
    `region_code` VARCHAR(32)  NOT NULL COMMENT 'Регион: lr для Яндекса, loc/geo для XMLRiver',
    `device`      ENUM('desktop','mobile') NOT NULL DEFAULT 'desktop',
    `depth`       SMALLINT UNSIGNED NOT NULL DEFAULT 100 COMMENT 'Глубина топа: 10/20/100',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_target` (`keyword_id`,`engine`,`region_code`,`device`),
    KEY `idx_targets_project` (`project_id`),
    CONSTRAINT `fk_targets_project`
        FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_targets_keyword`
        FOREIGN KEY (`keyword_id`) REFERENCES `keywords` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- runs — прогон (один плановый съём проекта на дату)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `runs` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `project_id` INT UNSIGNED NOT NULL,
    `run_date`   DATE NOT NULL COMMENT 'Дата съёма (берётся из прогона, НЕ из момента ответа API)',
    `status`     ENUM('open','collecting','done','partial','failed') NOT NULL DEFAULT 'open',
    `requested`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Сколько задач поставлено',
    `collected`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Сколько успешно собрано',
    `failed`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Сколько с ошибкой/истекло',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_run` (`project_id`,`run_date`),
    CONSTRAINT `fk_runs_project`
        FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- provider_tasks — ОЧЕРЕДЬ обращений к API (заменяет Redis)
-- Особенно важна для Яндекса: отложенный режим, результат живёт 12 часов.
-- state: queued (поставить) | submitted (отправлено, ждём)
--        | ready (готово к сбору) | collected (собрано)
--        | expired (12ч истекли) | failed (ошибка)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `provider_tasks` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `run_id`       INT UNSIGNED NOT NULL,
    `target_id`    INT UNSIGNED NOT NULL,
    `provider`     ENUM('yandex','xmlriver','gsc') NOT NULL,
    `external_id`  VARCHAR(255) NULL COMMENT 'operation_id Яндекса / id запроса провайдера',
    `state`        ENUM('queued','submitted','ready','collected','expired','failed')
                       NOT NULL DEFAULT 'queued',
    `submitted_at` DATETIME NULL COMMENT 'Когда отправили задачу провайдеру',
    `ready_at`     DATETIME NULL COMMENT 'Когда провайдер сообщил о готовности',
    `collected_at` DATETIME NULL COMMENT 'Когда мы забрали результат',
    `expires_at`   DATETIME NULL COMMENT 'Когда результат сгорит (Яндекс: submitted_at + 12ч)',
    `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Число попыток забрать/отправить',
    `last_error`   VARCHAR(1000) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_tasks_state` (`state`),
    KEY `idx_tasks_run` (`run_id`),
    KEY `idx_tasks_target` (`target_id`),
    KEY `idx_tasks_expires` (`expires_at`),
    CONSTRAINT `fk_tasks_run`
        FOREIGN KEY (`run_id`) REFERENCES `runs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tasks_target`
        FOREIGN KEY (`target_id`) REFERENCES `targets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- serp_snapshots — СЫРАЯ выдача (архив). results = JSON, сжатый gzcompress.
-- Храним, чтобы можно было пересчитать позиции по другим правилам без
-- повторной (невозможной) покупки выдачи.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `serp_snapshots` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `target_id`   INT UNSIGNED NOT NULL,
    `run_id`      INT UNSIGNED NOT NULL,
    `captured_at` DATETIME NOT NULL COMMENT 'Момент получения выдачи',
    `provider`    ENUM('yandex','xmlriver','gsc') NOT NULL,
    `results`     MEDIUMBLOB NOT NULL COMMENT 'JSON списка результатов, сжатый gzcompress()',
    `raw_hash`    CHAR(64) NOT NULL COMMENT 'sha256 несжатого JSON — от дублей',
    `http_status` SMALLINT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_snap_target` (`target_id`),
    KEY `idx_snap_run` (`run_id`),
    CONSTRAINT `fk_snap_target`
        FOREIGN KEY (`target_id`) REFERENCES `targets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_snap_run`
        FOREIGN KEY (`run_id`) REFERENCES `runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- positions — РАССЧИТАННЫЕ позиции (то, что рисуем на графиках).
-- Позиция ОРГАНИЧЕСКАЯ (API иначе не умеет) — так и называть в отчётах.
-- is_absent ("нет в топ-N") — ОТДЕЛЬНО от ошибок/истечения (те в provider_tasks.state).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `positions` (
    `target_id`         INT UNSIGNED NOT NULL,
    `run_date`          DATE NOT NULL COMMENT 'Дата прогона, НЕ момента ответа API',
    `position`          SMALLINT UNSIGNED NULL COMMENT 'Органическая позиция; NULL если нет в топе',
    `found_url`         VARCHAR(1000) NULL COMMENT 'Первое вхождение нашего домена (дедуп)',
    `previous_position` SMALLINT UNSIGNED NULL COMMENT 'Позиция в прошлый прогон',
    `delta`             SMALLINT NULL COMMENT 'Изменение: previous - current (+ вверх, - вниз)',
    `in_top10`          TINYINT(1) NOT NULL DEFAULT 0,
    `is_absent`         TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = домена нет в снятом топ-N',
    PRIMARY KEY (`target_id`,`run_date`),
    KEY `idx_positions_date` (`run_date`),
    CONSTRAINT `fk_positions_target`
        FOREIGN KEY (`target_id`) REFERENCES `targets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
