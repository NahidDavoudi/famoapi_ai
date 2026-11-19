-- =============================================================================
-- Migration 009: Staff Activity Logs (Admins, Supporters, Teachers)
-- Target Engine: MySQL 8.0+ / 8.4+
-- =============================================================================

CREATE TABLE IF NOT EXISTS `staff_activity_logs` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor_user_id`   INT UNSIGNED    NOT NULL,
    `actor_role`      VARCHAR(32)     NOT NULL COMMENT 'admin | supporter | teacher',
    `actor_linked_id` INT UNSIGNED    DEFAULT NULL,
    `actor_name`      VARCHAR(150)    DEFAULT NULL,
    `module`          VARCHAR(64)     NOT NULL,
    `action`          VARCHAR(64)     NOT NULL,
    `target_type`     VARCHAR(64)     DEFAULT NULL,
    `target_id`       VARCHAR(64)     DEFAULT NULL,
    `summary`         VARCHAR(500)    NOT NULL,
    `http_method`     VARCHAR(10)     NOT NULL DEFAULT 'POST',
    `endpoint`        VARCHAR(255)    NOT NULL,
    `status_code`     SMALLINT UNSIGNED NOT NULL DEFAULT 200,
    `ip_address`      VARCHAR(64)     DEFAULT NULL,
    `details`         JSON            DEFAULT NULL,
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_staff_activity_actor` (`actor_user_id`, `actor_role`),
    KEY `idx_staff_activity_role_date` (`actor_role`, `created_at`),
    KEY `idx_staff_activity_module_date` (`module`, `created_at`),
    KEY `idx_staff_activity_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
