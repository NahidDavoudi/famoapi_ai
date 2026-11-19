-- =============================================================================
-- Migration 004: Daily Attendance & Tutoring Classrooms / Sessions / Teacher Status
-- Target Engine: MySQL 8.0+ / 8.4+
-- =============================================================================

CREATE TABLE IF NOT EXISTS daily_attendance (
    id              BIGINT UNSIGNED         NOT NULL AUTO_INCREMENT,
    attendance_date DATE                    NOT NULL,
    student_id      INT                     DEFAULT NULL,
    guest_name      VARCHAR(255)            DEFAULT NULL,
    field           VARCHAR(50)             DEFAULT NULL,
    status          ENUM('present','absent','excused') NOT NULL DEFAULT 'present',
    arrived_at      DATETIME                DEFAULT NULL,
    exam_started_at DATETIME                DEFAULT NULL,
    exam_ended_at   DATETIME                DEFAULT NULL,
    departed_at     DATETIME                DEFAULT NULL,
    removed_at      DATETIME                DEFAULT NULL,
    removed_by      INT                     DEFAULT NULL,
    created_by      INT                     DEFAULT NULL,
    client_uuid     CHAR(36)                DEFAULT NULL,
    created_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_att_student_day (attendance_date, student_id),
    UNIQUE KEY uq_att_client_uuid (client_uuid),
    KEY idx_att_day_field (attendance_date, field),
    KEY fk_att_student (student_id),
    KEY fk_att_removed_by (removed_by),
    KEY fk_att_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tutoring_classrooms (
    id            INT          NOT NULL AUTO_INCREMENT,
    name          VARCHAR(100) NOT NULL,
    instructor_id INT          NOT NULL,
    sort_order    INT          NOT NULL DEFAULT 0,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_classroom_instructor (instructor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tutoring_sessions (
    id                BIGINT UNSIGNED      NOT NULL AUTO_INCREMENT,
    session_date      DATE                 NOT NULL,
    classroom_id      INT                  NOT NULL,
    student_id        INT                  NOT NULL,
    attendance_id     BIGINT UNSIGNED      DEFAULT NULL,
    entered_at        DATETIME             NOT NULL,
    ended_at          DATETIME             DEFAULT NULL,
    client_entered_at DATETIME             DEFAULT NULL,
    entry_source      ENUM('auto','manual') NOT NULL DEFAULT 'auto',
    needs_review      TINYINT(1)           NOT NULL DEFAULT 0,
    created_by        INT                  DEFAULT NULL,
    client_uuid       CHAR(36)             DEFAULT NULL,
    created_at        DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    open_student_key  INT GENERATED ALWAYS AS (IF(ended_at IS NULL, student_id, NULL)) VIRTUAL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sess_client_uuid (client_uuid),
    UNIQUE KEY uq_sess_one_open_per_student (open_student_key),
    KEY idx_sess_day_class (session_date, classroom_id),
    KEY idx_sess_class_open (classroom_id, ended_at),
    KEY fk_sess_student (student_id),
    KEY fk_sess_attendance (attendance_id),
    KEY fk_sess_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tutoring_teacher_status_log (
    id            BIGINT UNSIGNED               NOT NULL AUTO_INCREMENT,
    instructor_id INT                           NOT NULL,
    status        ENUM('absent','ready','break') NOT NULL,
    status_date   DATE                          NOT NULL,
    started_at    DATETIME                      NOT NULL,
    ended_at      DATETIME                      DEFAULT NULL,
    client_uuid   CHAR(36)                      DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_status_client_uuid (client_uuid),
    KEY idx_status_teacher_day (instructor_id, status_date, ended_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
