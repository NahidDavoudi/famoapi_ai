-- =============================================================================
-- Migration 006: Exam Teacher Comments & Parent Shareable Result Links
-- Target Engine: MySQL 8.0+ / 8.4+
-- =============================================================================

CREATE TABLE IF NOT EXISTS exam_comments (
    id              INT       NOT NULL AUTO_INCREMENT,
    student_id      INT       NOT NULL,
    exam_date       DATE      NOT NULL,
    teacher_user_id INT       NOT NULL,
    body            TEXT      NOT NULL,
    created_at      DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_exam_comments_student_date_teacher (student_id, exam_date, teacher_user_id),
    KEY idx_exam_comments_student_date (student_id, exam_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_result_links (
    id             INT      NOT NULL AUTO_INCREMENT,
    token_hash     CHAR(64) NOT NULL,
    student_id     INT      NOT NULL,
    exam_date      DATE     NOT NULL,
    expires_at     DATETIME NOT NULL,
    revoked_at     DATETIME DEFAULT NULL,
    sms_sent_at    DATETIME DEFAULT NULL,
    created_by     INT      DEFAULT NULL,
    last_viewed_at DATETIME DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_exam_result_links_token_hash (token_hash),
    KEY idx_exam_result_links_student_date (student_id, exam_date),
    KEY idx_exam_result_links_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
