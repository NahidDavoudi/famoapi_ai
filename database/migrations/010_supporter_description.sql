-- =============================================================================
-- Migration 010: Supporter Profile Description & Avatar Support
-- Target Engine: MySQL 8.0+ / 8.4+
-- =============================================================================

ALTER TABLE supporters
    ADD COLUMN description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER avatar;
