-- =============================================================================
-- Migration 016: Admin Multi-Channel 2FA Challenges (SMS -> Email -> TOTP)
-- Target Engine: MySQL 8.0+ / 8.4+
-- =============================================================================

CREATE TABLE IF NOT EXISTS admin_2fa_challenges (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id            INT             NOT NULL,
    challenge_hash     CHAR(64)        NOT NULL COMMENT 'HMAC-SHA256 of per-login challenge token',
    otp_salt           CHAR(32)        DEFAULT NULL COMMENT 'Random hex salt for OTP HMAC digest',
    otp_hash           CHAR(64)        DEFAULT NULL COMMENT 'Salted HMAC-SHA256 of 6-digit OTP (never plaintext)',
    active_channel     ENUM('sms','email','totp') NOT NULL,
    delivered_channels VARCHAR(64)     NOT NULL DEFAULT '' COMMENT 'Comma-separated outbound channels delivered in this session',
    send_count         TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total outbound OTP sends in this login session (max 3)',
    last_sent_at       DATETIME        DEFAULT NULL COMMENT 'UTC timestamp of most recent outbound OTP send (60s cooldown)',
    verify_attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Failed verification attempts on this challenge (max 5)',
    max_attempts       TINYINT UNSIGNED NOT NULL DEFAULT 5,
    expires_at         DATETIME        NOT NULL COMMENT 'UTC expiration timestamp (5 minutes)',
    consumed_at        DATETIME        DEFAULT NULL COMMENT 'UTC timestamp when challenge was used or invalidated',
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_2fa_challenge_hash (challenge_hash),
    KEY idx_admin_2fa_user_active (user_id, consumed_at, expires_at),
    KEY idx_admin_2fa_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
