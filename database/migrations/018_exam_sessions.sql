-- Migration 018: Curriculum Node Enrichment, Student Shelves, Exam Sessions, Multi-Topic Results
-- Compatible with MySQL 8.0+ / 8.4+
-- Depends on: 001 (students, users), 002 (exam_results), 017 (lernito_shelves, lernito_questions)

-- 1. Ensure curriculum_nodes exists (for fresh/CI databases) and uses InnoDB for FK integrity
CREATE TABLE IF NOT EXISTS curriculum_nodes (
  id INT NOT NULL,
  parent_id INT DEFAULT NULL,
  label VARCHAR(255) NOT NULL,
  level TINYINT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_curriculum_nodes_parent (parent_id),
  KEY idx_curriculum_nodes_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE curriculum_nodes ENGINE=InnoDB;

ALTER TABLE curriculum_nodes
  ADD COLUMN node_type VARCHAR(24) DEFAULT NULL AFTER level,
  ADD COLUMN segment_id INT DEFAULT NULL AFTER node_type,
  ADD COLUMN lesson_id INT DEFAULT NULL AFTER segment_id,
  ADD COLUMN grade_label VARCHAR(32) DEFAULT NULL AFTER lesson_id,
  ADD COLUMN group_label VARCHAR(64) DEFAULT NULL AFTER grade_label,
  ADD COLUMN full_path VARCHAR(500) DEFAULT NULL AFTER group_label,
  ADD COLUMN normalized_label VARCHAR(255) DEFAULT NULL AFTER full_path;

ALTER TABLE curriculum_nodes
  ADD KEY idx_cn_node_type_norm (node_type, normalized_label(100)),
  ADD KEY idx_cn_lesson_type (lesson_id, node_type),
  ADD KEY idx_cn_parent_sort (parent_id, sort_order, id),
  ADD KEY idx_cn_full_path (full_path(191));

-- 2. Panel-owned student <-> shelf assignment (never wiped by Lernito shelf sync)
CREATE TABLE IF NOT EXISTS student_shelves (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT NOT NULL,
  shelf_id BIGINT UNSIGNED NOT NULL,
  assigned_by_user_id INT DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_student_shelves_student_shelf (student_id, shelf_id),
  KEY idx_student_shelves_shelf (shelf_id),
  CONSTRAINT fk_student_shelves_student
    FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
  CONSTRAINT fk_student_shelves_shelf
    FOREIGN KEY (shelf_id) REFERENCES lernito_shelves (id) ON DELETE RESTRICT,
  CONSTRAINT fk_student_shelves_assigned_by
    FOREIGN KEY (assigned_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Exam sessions: connects student + shelf + exam_date (allows multiple shelves on same date)
CREATE TABLE IF NOT EXISTS exam_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT NOT NULL,
  shelf_id BIGINT UNSIGNED NOT NULL,
  exam_date DATE NOT NULL,
  shelf_name_snapshot VARCHAR(255) NOT NULL DEFAULT '',
  shelf_account_snapshot VARCHAR(32) NOT NULL,
  total_questions INT UNSIGNED NOT NULL DEFAULT 0,
  skipped_descriptive INT UNSIGNED NOT NULL DEFAULT 0,
  missing_question_count INT UNSIGNED NOT NULL DEFAULT 0,
  is_incomplete TINYINT(1) NOT NULL DEFAULT 0,
  has_unclassified_topics TINYINT(1) NOT NULL DEFAULT 0,
  warnings_json JSON DEFAULT NULL,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_exam_sessions_student_shelf_date (student_id, shelf_id, exam_date),
  KEY idx_exam_sessions_student_date (student_id, exam_date),
  KEY idx_exam_sessions_shelf (shelf_id),
  KEY idx_exam_sessions_created_at (created_at),
  CONSTRAINT fk_exam_sessions_student
    FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
  CONSTRAINT fk_exam_sessions_shelf
    FOREIGN KEY (shelf_id) REFERENCES lernito_shelves (id) ON DELETE RESTRICT,
  CONSTRAINT fk_exam_sessions_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Immutable snapshot of shelf records at exam session creation (base for booklet & important flags)
CREATE TABLE IF NOT EXISTS exam_session_questions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  exam_session_id BIGINT UNSIGNED NOT NULL,
  position INT UNSIGNED NOT NULL,
  question_lernito_id VARCHAR(64) NOT NULL,
  doc_type VARCHAR(32) NOT NULL DEFAULT 'Question',
  sub_parts_json JSON DEFAULT NULL,
  is_important TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_exam_session_questions_pos (exam_session_id, position),
  KEY idx_exam_session_questions_qid (question_lernito_id),
  KEY idx_exam_session_questions_important (exam_session_id, is_important),
  CONSTRAINT fk_exam_session_questions_session
    FOREIGN KEY (exam_session_id) REFERENCES exam_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Suggested & manually editable exam form defaults (1 row per lesson + array of curriculum topic IDs)
CREATE TABLE IF NOT EXISTS exam_session_topics (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  exam_session_id BIGINT UNSIGNED NOT NULL,
  subject_id INT DEFAULT NULL,
  lesson VARCHAR(128) NOT NULL,
  topic_ids_json JSON DEFAULT NULL,
  topic_labels_json JSON DEFAULT NULL,
  topic VARCHAR(255) DEFAULT NULL,
  auto_count INT UNSIGNED NOT NULL DEFAULT 0,
  final_count INT UNSIGNED NOT NULL DEFAULT 0,
  is_manual TINYINT(1) NOT NULL DEFAULT 0,
  has_topic TINYINT(1) NOT NULL DEFAULT 1,
  display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_exam_session_topics_session_manual (exam_session_id, is_manual, display_order),
  KEY idx_exam_session_topics_subject (subject_id),
  CONSTRAINT fk_exam_session_topics_session
    FOREIGN KEY (exam_session_id) REFERENCES exam_sessions (id) ON DELETE CASCADE,
  CONSTRAINT fk_exam_session_topics_subject
    FOREIGN KEY (subject_id) REFERENCES curriculum_nodes (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Backward-compatible upgrade to exam_results: link to exam_session & curriculum_nodes IDs
ALTER TABLE exam_results
  MODIFY COLUMN subject VARCHAR(128) NOT NULL,
  MODIFY COLUMN chapter VARCHAR(255) DEFAULT NULL,
  ADD COLUMN exam_session_id BIGINT UNSIGNED DEFAULT NULL AFTER file_id,
  ADD COLUMN exam_session_topic_id BIGINT UNSIGNED DEFAULT NULL AFTER exam_session_id,
  ADD COLUMN subject_id INT DEFAULT NULL AFTER exam_date,
  ADD COLUMN topic_ids_json JSON DEFAULT NULL AFTER subject_id;

ALTER TABLE exam_results
  ADD KEY idx_exam_results_session (exam_session_id),
  ADD KEY idx_exam_results_subject_id (subject_id),
  ADD CONSTRAINT fk_exam_results_session
    FOREIGN KEY (exam_session_id) REFERENCES exam_sessions (id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_exam_results_session_topic
    FOREIGN KEY (exam_session_topic_id) REFERENCES exam_session_topics (id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_exam_results_subject
    FOREIGN KEY (subject_id) REFERENCES curriculum_nodes (id) ON DELETE SET NULL;

-- 7. Normalized join table kept in sync with exam_results.topic_ids_json for fast indexed SQL analytics
CREATE TABLE IF NOT EXISTS exam_result_topics (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  exam_result_id INT NOT NULL,
  topic_id INT NOT NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_exam_result_topics_pair (exam_result_id, topic_id),
  KEY idx_exam_result_topics_topic (topic_id, exam_result_id),
  CONSTRAINT fk_exam_result_topics_result
    FOREIGN KEY (exam_result_id) REFERENCES exam_results (id) ON DELETE CASCADE,
  CONSTRAINT fk_exam_result_topics_node
    FOREIGN KEY (topic_id) REFERENCES curriculum_nodes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
