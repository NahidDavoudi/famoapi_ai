-- =============================================================================
-- Migration 007: Weekly Appointment Reminder SMS Log & Appointment Status Upgrade
-- Target Engine: MySQL 8.0+ / 8.4+
-- =============================================================================

-- Allow all appointment statuses used by the API ('pending', 'confirmed', 'scheduled', 'completed', 'cancelled', 'canceled', 'absent', 'done', 'accepted', 'rejected')
ALTER TABLE appointments
    MODIFY COLUMN status VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending';

CREATE TABLE IF NOT EXISTS appointment_sms_log (
    id             INT         NOT NULL AUTO_INCREMENT,
    student_id     INT         NOT NULL,
    week_date      DATE        NOT NULL,
    mobile         VARCHAR(20) NOT NULL DEFAULT '',
    recipient_type VARCHAR(20) NOT NULL DEFAULT 'parent',
    appointment_at DATETIME    DEFAULT NULL,
    status         VARCHAR(30) NOT NULL,
    error          TEXT        DEFAULT NULL,
    attempts       INT         NOT NULL DEFAULT 1,
    sent_at        DATETIME    DEFAULT NULL,
    created_by     INT         DEFAULT NULL,
    created_at     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_appointment_sms_log_student_week_mobile (student_id, week_date, mobile),
    KEY idx_appointment_sms_log_week_status (week_date, status),
    KEY idx_appointment_sms_log_student_week (student_id, week_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
