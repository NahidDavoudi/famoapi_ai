-- Migration 017: Lernito Question Bank (Shelves, Questions, Parts, Shelf Links, Missing Tracker)
-- Compatible with MySQL 8.0+ / 8.4+
-- Phase 1: Multi-account Shelves + Questions + Normalized Parts + Missing Retry Tracking
-- Phase 2 ready: Nullable answer_key_json, answer_html, answer_synced_at on lernito_questions

CREATE TABLE IF NOT EXISTS lernito_shelves (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lernito_id VARCHAR(64) NOT NULL,
  account VARCHAR(32) NOT NULL,
  name VARCHAR(255) NOT NULL DEFAULT '',
  color VARCHAR(32) DEFAULT NULL,
  creator_id VARCHAR(64) DEFAULT NULL,
  record_count INT UNSIGNED NOT NULL DEFAULT 0,
  records_hash CHAR(64) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  missing_since DATETIME DEFAULT NULL,
  lernito_created_at DATETIME DEFAULT NULL,
  lernito_modified_at DATETIME DEFAULT NULL,
  last_synced_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lernito_shelves_lernito_id (lernito_id),
  KEY idx_lernito_shelves_account_active (account, is_active),
  KEY idx_lernito_shelves_name (name(100)),
  KEY idx_lernito_shelves_last_synced (last_synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lernito_questions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lernito_id VARCHAR(64) NOT NULL,
  source_account VARCHAR(32) DEFAULT NULL,
  question_type VARCHAR(32) NOT NULL DEFAULT 'unknown',
  is_multipart TINYINT(1) NOT NULL DEFAULT 0,
  is_descriptive TINYINT(1) NOT NULL DEFAULT 0,
  part_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  difficulty TINYINT UNSIGNED DEFAULT NULL,
  question_html MEDIUMTEXT NOT NULL,
  choices_json JSON DEFAULT NULL,
  sub_questions_json JSON DEFAULT NULL,
  topics_json JSON DEFAULT NULL,
  references_json JSON DEFAULT NULL,
  flags_json JSON DEFAULT NULL,
  has_topic TINYINT(1) NOT NULL DEFAULT 0,
  primary_segment VARCHAR(64) DEFAULT NULL,
  primary_group VARCHAR(64) DEFAULT NULL,
  primary_grade VARCHAR(64) DEFAULT NULL,
  primary_lesson VARCHAR(128) DEFAULT NULL,
  primary_chapter VARCHAR(255) DEFAULT NULL,
  primary_subject VARCHAR(255) DEFAULT NULL,
  answer_key_json JSON DEFAULT NULL,
  answer_html MEDIUMTEXT DEFAULT NULL,
  answer_synced_at DATETIME DEFAULT NULL,
  lernito_version INT UNSIGNED DEFAULT NULL,
  lernito_created_by VARCHAR(64) DEFAULT NULL,
  lernito_created_at DATETIME DEFAULT NULL,
  raw_json JSON NOT NULL,
  last_synced_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lernito_questions_lernito_id (lernito_id),
  KEY idx_lernito_questions_type (question_type, is_multipart, is_descriptive),
  KEY idx_lernito_questions_account (source_account),
  KEY idx_lernito_questions_lesson_grade (primary_lesson(80), primary_grade(40), difficulty),
  KEY idx_lernito_questions_group_grade (primary_group(50), primary_grade(40)),
  KEY idx_lernito_questions_answer_synced (answer_synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lernito_question_parts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id BIGINT UNSIGNED NOT NULL,
  part_index SMALLINT UNSIGNED NOT NULL,
  part_type VARCHAR(32) NOT NULL DEFAULT 'unknown',
  is_descriptive TINYINT(1) NOT NULL DEFAULT 0,
  segment VARCHAR(64) DEFAULT NULL,
  grade VARCHAR(64) DEFAULT NULL,
  group_name VARCHAR(64) DEFAULT NULL,
  lesson VARCHAR(128) DEFAULT NULL,
  chapter VARCHAR(255) DEFAULT NULL,
  subject VARCHAR(255) DEFAULT NULL,
  has_topic TINYINT(1) NOT NULL DEFAULT 0,
  topics_json JSON DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lernito_qp_question_part (question_id, part_index),
  KEY idx_lernito_qp_descriptive (is_descriptive),
  KEY idx_lernito_qp_lesson_chapter (lesson(80), chapter(100)),
  KEY idx_lernito_qp_has_topic (has_topic),
  CONSTRAINT fk_lernito_qp_question
    FOREIGN KEY (question_id) REFERENCES lernito_questions (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lernito_shelf_questions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shelf_id BIGINT UNSIGNED NOT NULL,
  question_lernito_id VARCHAR(64) NOT NULL,
  position INT UNSIGNED NOT NULL,
  doc_type VARCHAR(32) NOT NULL DEFAULT 'Question',
  sub_parts_json JSON DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lernito_shelf_pos (shelf_id, position),
  KEY idx_lernito_sq_question_id (question_lernito_id),
  KEY idx_lernito_sq_shelf_question (shelf_id, question_lernito_id),
  CONSTRAINT fk_lernito_sq_shelf
    FOREIGN KEY (shelf_id) REFERENCES lernito_shelves (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lernito_missing_questions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lernito_id VARCHAR(64) NOT NULL,
  account VARCHAR(32) NOT NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  last_attempt_at DATETIME DEFAULT NULL,
  last_error VARCHAR(255) DEFAULT NULL,
  resolved_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lernito_missing_id_account (lernito_id, account),
  KEY idx_lernito_missing_retry (account, resolved_at, attempts, last_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
