-- =============================================================================
-- Migration 015: Unified SMS Delivery Log & Idempotency Table (sms_log)
-- Target Engine: MySQL 8.0+ / 8.4+
-- =============================================================================

CREATE TABLE IF NOT EXISTS sms_log (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client               VARCHAR(32)     NOT NULL DEFAULT 'famo',
    purpose              VARCHAR(64)     NOT NULL,
    receptor             VARCHAR(20)     NOT NULL,
    ref                  VARCHAR(191)    NOT NULL,
    text                 TEXT            NOT NULL,
    segments             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    provider             VARCHAR(32)     NOT NULL,
    status               ENUM('pending','sent','delivered','failed','unknown') NOT NULL DEFAULT 'pending',
    provider_msg_id      VARCHAR(128)    DEFAULT NULL,
    provider_cost        DECIMAL(12,2)   DEFAULT NULL,
    provider_status_code INT             DEFAULT NULL,
    provider_status_text VARCHAR(255)    DEFAULT NULL,
    error                VARCHAR(500)    DEFAULT NULL,
    sent_at              DATETIME        DEFAULT NULL,
    delivered_at         DATETIME        DEFAULT NULL,
    created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sms_log_dedup (client, purpose, receptor, ref),
    KEY idx_sms_log_reconcile (status, created_at),
    KEY idx_sms_log_provider_msg_id (provider_msg_id),
    KEY idx_sms_log_purpose_created (purpose, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
