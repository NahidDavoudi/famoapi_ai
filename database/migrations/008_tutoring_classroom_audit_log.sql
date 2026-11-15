-- =============================================================================
-- Migration 008: Tutoring Classroom Configuration Audit Log
-- Target Engine: MySQL 8.0+ / 8.4+
-- =============================================================================

CREATE TABLE IF NOT EXISTS tutoring_classroom_audit_logs (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    classroom_id        INT             NOT NULL,
    instructor_id       INT             DEFAULT NULL,
    actor_user_id       INT             DEFAULT NULL,
    actor_role          VARCHAR(30)     DEFAULT NULL,
    actor_instructor_id INT             DEFAULT NULL,
    action              VARCHAR(30)     NOT NULL,
    summary              VARCHAR(500)    NOT NULL DEFAULT '',
    changes_json        JSON             NOT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tutoring_cls_audit_classroom (classroom_id, created_at),
    KEY idx_tutoring_cls_audit_actor (actor_user_id, created_at),
    KEY idx_tutoring_cls_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
