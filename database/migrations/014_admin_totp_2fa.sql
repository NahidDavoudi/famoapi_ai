-- =============================================================================
-- Migration 014: Admin TOTP Two-Factor Authentication (RFC 6238)
-- Target Engine: MySQL 8.0+ / 8.4+
-- =============================================================================

ALTER TABLE users
    ADD COLUMN totp_secret TEXT DEFAULT NULL COMMENT 'AES-256-GCM encrypted Base32 TOTP secret' AFTER password_hash;

ALTER TABLE users
    ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 when authenticator-app TOTP 2FA is enabled' AFTER totp_secret;

ALTER TABLE users
    ADD COLUMN totp_last_used_step BIGINT UNSIGNED DEFAULT NULL COMMENT 'Last accepted 30-second TOTP time-step for replay protection' AFTER totp_enabled;

ALTER TABLE users
    ADD COLUMN recovery_codes TEXT DEFAULT NULL COMMENT 'JSON array of hashed one-time recovery codes (never plaintext)' AFTER totp_last_used_step;
