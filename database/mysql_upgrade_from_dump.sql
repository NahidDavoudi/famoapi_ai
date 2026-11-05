-- =============================================================================
-- Famo MySQL 8.4 Upgrade Script (to run after importing famo_2026-11-04_1617_4b1abc51.sql)
-- Usage:
--   mysql -u root -p famo < public/assets/aistudio/famo_2026-11-04_1617_4b1abc51.sql
--   mysql -u root -p famo < database/mysql_upgrade_from_dump.sql
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1. Align existing tables from dump with new module columns/statuses
ALTER TABLE `appointments`
    MODIFY COLUMN `status` VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending';

ALTER TABLE `daily_attendance`
    MODIFY COLUMN `status` ENUM('present','absent','excused') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'present';

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `phone` VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `username`,
    ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `linked_id`;

UPDATE `users`
SET `phone` = `username`
WHERE `phone` IS NULL AND `username` REGEXP '^09[0-9]{9}$';

-- 2. Auxiliary tables referenced by modules if not yet present in dump
CREATE TABLE IF NOT EXISTS `admins` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(255) NOT NULL,
    `phone`      VARCHAR(20)  DEFAULT NULL,
    `chat_id`    BIGINT       DEFAULT NULL,
    `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_admins_phone` (`phone`),
    KEY `idx_admins_chat_id` (`chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `instructor_social_links` (
    `id`            INT          NOT NULL AUTO_INCREMENT,
    `instructor_id` INT          NOT NULL,
    `platform`      VARCHAR(50)  NOT NULL,
    `url`           VARCHAR(500) NOT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_instructor_social_instructor` (`instructor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `topic_tree` (
    `id`           INT          NOT NULL AUTO_INCREMENT,
    `parent_id`    INT          DEFAULT NULL,
    `label`        VARCHAR(255) NOT NULL,
    `grade`        SMALLINT     DEFAULT NULL,
    `field`        VARCHAR(50)  DEFAULT NULL,
    `subject_name` VARCHAR(100) DEFAULT NULL,
    `sort_order`   INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_topic_tree_parent` (`parent_id`, `sort_order`, `id`),
    KEY `idx_topic_tree_filter` (`grade`, `field`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `course_features` (
    `id`            INT          NOT NULL AUTO_INCREMENT,
    `course_id`     INT          NOT NULL,
    `feature_text`  VARCHAR(500) NOT NULL,
    `display_order` INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_course_features_course` (`course_id`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscriptions` (
    `id`         INT         NOT NULL AUTO_INCREMENT,
    `course_id`  INT         NOT NULL,
    `student_id` INT         NOT NULL,
    `status`     VARCHAR(30) NOT NULL DEFAULT 'pending',
    `is_active`  TINYINT(1)  NOT NULL DEFAULT 1,
    `note`       TEXT        DEFAULT NULL,
    `started_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `end_at`     DATETIME    DEFAULT NULL,
    `created_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_subscriptions_student` (`student_id`, `is_active`),
    KEY `idx_subscriptions_course` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Phase 1 & 2/3 Exam Teacher Comments & Shareable Parent Result Links (Migration 006)
CREATE TABLE IF NOT EXISTS `exam_comments` (
    `id`              INT       NOT NULL AUTO_INCREMENT,
    `student_id`      INT       NOT NULL,
    `exam_date`       DATE      NOT NULL,
    `teacher_user_id` INT       NOT NULL,
    `body`            TEXT      NOT NULL,
    `created_at`      DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_exam_comments_student_date_teacher` (`student_id`, `exam_date`, `teacher_user_id`),
    KEY `idx_exam_comments_student_date` (`student_id`, `exam_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_result_links` (
    `id`             INT      NOT NULL AUTO_INCREMENT,
    `token_hash`     CHAR(64) NOT NULL,
    `student_id`     INT      NOT NULL,
    `exam_date`      DATE     NOT NULL,
    `expires_at`     DATETIME NOT NULL,
    `revoked_at`     DATETIME DEFAULT NULL,
    `sms_sent_at`    DATETIME DEFAULT NULL,
    `created_by`     INT      DEFAULT NULL,
    `last_viewed_at` DATETIME DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_exam_result_links_token_hash` (`token_hash`),
    KEY `idx_exam_result_links_student_date` (`student_id`, `exam_date`),
    KEY `idx_exam_result_links_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Weekly Appointment SMS Reminder Log (Migration 007)
CREATE TABLE IF NOT EXISTS `appointment_sms_log` (
    `id`             INT         NOT NULL AUTO_INCREMENT,
    `student_id`     INT         NOT NULL,
    `week_date`      DATE        NOT NULL,
    `mobile`         VARCHAR(20) NOT NULL DEFAULT '',
    `recipient_type` VARCHAR(20) NOT NULL DEFAULT 'parent',
    `appointment_at` DATETIME    DEFAULT NULL,
    `status`         VARCHAR(30) NOT NULL,
    `error`          TEXT        DEFAULT NULL,
    `attempts`       INT         NOT NULL DEFAULT 1,
    `sent_at`        DATETIME    DEFAULT NULL,
    `created_by`     INT         DEFAULT NULL,
    `created_at`     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_appointment_sms_log_student_week_mobile` (`student_id`, `week_date`, `mobile`),
    KEY `idx_appointment_sms_log_week_status` (`week_date`, `status`),
    KEY `idx_appointment_sms_log_student_week` (`student_id`, `week_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Record migrations in `migrations` table so Migrator::run() knows they are applied
INSERT IGNORE INTO `migrations` (`migration`, `executed_at`) VALUES
    ('001_core_identity_and_users.sql', CURRENT_TIMESTAMP),
    ('002_academic_and_planner.sql', CURRENT_TIMESTAMP),
    ('003_courses_blog_and_public.sql', CURRENT_TIMESTAMP),
    ('004_attendance_and_tutoring.sql', CURRENT_TIMESTAMP),
    ('005_telegram_bot_threads_and_outbox.sql', CURRENT_TIMESTAMP),
    ('006_exam_comments_and_share_links.sql', CURRENT_TIMESTAMP),
    ('007_appointment_sms_log.sql', CURRENT_TIMESTAMP);
