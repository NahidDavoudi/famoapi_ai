-- =============================================================================
-- Migration 002: Academic Files, Exam Results, Topic Tree, Weekly Plans, Appointments
-- Target Engine: MySQL 8.0+ / 8.4+
-- =============================================================================

CREATE TABLE IF NOT EXISTS files (
    id          INT                                  NOT NULL AUTO_INCREMENT,
    owner_type  ENUM('student','supporter','admin')  NOT NULL,
    owner_id    INT                                  NOT NULL,
    file_type   ENUM('exam','report','other')        NOT NULL,
    report_date DATE                                 NOT NULL,
    file_path   VARCHAR(255)                         NOT NULL,
    file_size   INT                                  DEFAULT NULL,
    description VARCHAR(255)                         DEFAULT NULL,
    created_at  DATETIME                             DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_results (
    id         INT           NOT NULL AUTO_INCREMENT,
    student_id INT           NOT NULL,
    file_id    INT           DEFAULT NULL,
    exam_date  DATE          NOT NULL,
    subject    VARCHAR(50)   NOT NULL,
    chapter    VARCHAR(100)  DEFAULT NULL,
    total_q    INT           NOT NULL,
    correct    INT           NOT NULL DEFAULT 0,
    wrong      INT           NOT NULL DEFAULT 0,
    skipped    INT           NOT NULL DEFAULT 0,
    percentage DECIMAL(5, 2) GENERATED ALWAYS AS (ROUND(((correct / NULLIF(total_q, 0)) * 100), 2)) STORED,
    created_at DATETIME      DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_exam_results_student_date (student_id, exam_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS topic_tree (
    id           INT          NOT NULL AUTO_INCREMENT,
    parent_id    INT          DEFAULT NULL,
    label        VARCHAR(255) NOT NULL,
    grade        SMALLINT     DEFAULT NULL,
    field        VARCHAR(50)  DEFAULT NULL,
    subject_name VARCHAR(100) DEFAULT NULL,
    sort_order   INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_topic_tree_parent (parent_id, sort_order, id),
    KEY idx_topic_tree_filter (grade, field)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS weekly_plans (
    id           INT      NOT NULL AUTO_INCREMENT,
    student_id   INT      NOT NULL,
    week_date    DATE     NOT NULL,
    weekly_notes TEXT     DEFAULT NULL,
    times_json   LONGTEXT DEFAULT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_student_week (student_id, week_date),
    KEY student_id (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS events (
    id               INT          NOT NULL AUTO_INCREMENT,
    plan_id          INT          NOT NULL,
    student_id       INT          NOT NULL,
    day_index        TINYINT      NOT NULL,
    day_name         VARCHAR(20)  NOT NULL,
    time_index       TINYINT      NOT NULL,
    time_label       VARCHAR(30)  DEFAULT NULL,
    topic_id         VARCHAR(20)  DEFAULT NULL,
    topic_path       TEXT         DEFAULT NULL,
    topic_path_short VARCHAR(200) DEFAULT NULL,
    topic_label      VARCHAR(200) DEFAULT NULL,
    subject_name     VARCHAR(100) DEFAULT NULL,
    selected_grade   VARCHAR(10)  DEFAULT NULL,
    title            VARCHAR(200) DEFAULT NULL,
    notes            TEXT         DEFAULT NULL,
    color            VARCHAR(20)  DEFAULT 'green',
    created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_events_plan (plan_id, day_index, time_index),
    KEY idx_events_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_topics (
    id               INT          NOT NULL AUTO_INCREMENT,
    event_id         INT          NOT NULL,
    topic_id         VARCHAR(20)  DEFAULT NULL,
    topic_label      VARCHAR(200) DEFAULT NULL,
    topic_path       TEXT         DEFAULT NULL,
    topic_path_short VARCHAR(255) DEFAULT NULL,
    position         INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_event_topics_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS plan_templates (
    id         INT          NOT NULL AUTO_INCREMENT,
    name       VARCHAR(255) NOT NULL,
    student_id INT          DEFAULT NULL,
    week_date  VARCHAR(20)  DEFAULT NULL,
    items_json LONGTEXT     DEFAULT NULL,
    created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS appointments (
    id               INT          NOT NULL AUTO_INCREMENT,
    student_id       INT          NOT NULL,
    appointment_date VARCHAR(255) NOT NULL,
    start_time       VARCHAR(10)  NOT NULL,
    end_time         TIME         DEFAULT NULL,
    status           VARCHAR(30)  NOT NULL DEFAULT 'pending',
    type             VARCHAR(50)  DEFAULT NULL,
    description      TEXT         DEFAULT NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_appointments_student_date (student_id, appointment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
