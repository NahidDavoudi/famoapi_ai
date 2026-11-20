-- =============================================================================
-- Migration 012: Separate Melipayamak Pattern Body IDs per SMS Purpose
-- Target Engine: MySQL 8.0+ / 8.4+
-- =============================================================================

CREATE TABLE IF NOT EXISTS `sms_pattern_settings` (
    `pattern_key`  VARCHAR(64)  NOT NULL,
    `body_id`      BIGINT       DEFAULT NULL,
    `pattern_text` TEXT         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `updated_by`   INT          DEFAULT NULL,
    `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`pattern_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `sms_pattern_settings` (`pattern_key`, `body_id`, `pattern_text`) VALUES
    ('auth_2fa', NULL, 'کد تایید ورود به پنل فامو: {0}'),
    ('appointment_reminder', NULL, 'موسسه آموزشی فامو | دکتر موسوی والدین گرامی {0} ، نوبت فرزند شما روز {1} ساعت {2} میباشد. در صورت تایید به شماره موسسه پیامک دهید.'),
    ('exam_result', NULL, 'کارنامه آزمون {0} در تاریخ {1}: {2}');
