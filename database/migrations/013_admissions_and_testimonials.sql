-- =============================================================================
-- Migration 013: Admissions (Past Achievers) & Testimonials (Moderated Reviews)
-- Target Engine: MySQL 8.0+ / 8.4+
-- =============================================================================

CREATE TABLE IF NOT EXISTS admissions (
    id                   INT                        NOT NULL AUTO_INCREMENT,
    student_name         VARCHAR(150)               NOT NULL,
    jalali_year          SMALLINT UNSIGNED          NOT NULL,
    exam_type            VARCHAR(50)                NOT NULL DEFAULT 'konkur',
    field                VARCHAR(60)                DEFAULT NULL,
    result_title         VARCHAR(255)               NOT NULL,
    rank_value           VARCHAR(50)                DEFAULT NULL,
    quote                VARCHAR(500)               DEFAULT NULL,
    image_url            VARCHAR(255)               DEFAULT NULL,
    display_order        INT                        NOT NULL DEFAULT 0,
    is_featured          TINYINT(1)                 NOT NULL DEFAULT 0,
    consent_confirmed    TINYINT(1)                 NOT NULL DEFAULT 0,
    consent_confirmed_at DATETIME                   DEFAULT NULL,
    consent_confirmed_by INT                        DEFAULT NULL,
    status               ENUM('draft','published')  NOT NULL DEFAULT 'draft',
    published_at         DATETIME                   DEFAULT NULL,
    created_by           INT                        DEFAULT NULL,
    updated_by           INT                        DEFAULT NULL,
    created_at           DATETIME                   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME                   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admissions_public (status, consent_confirmed, jalali_year, display_order, id),
    KEY idx_admissions_year (jalali_year),
    KEY idx_admissions_featured (status, is_featured, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS testimonials (
    id               INT                                         NOT NULL AUTO_INCREMENT,
    author_name      VARCHAR(120)                                NOT NULL,
    author_role      ENUM('student','parent','graduate','other') NOT NULL DEFAULT 'student',
    author_meta      VARCHAR(150)                                DEFAULT NULL,
    rating           TINYINT UNSIGNED                            NOT NULL DEFAULT 5,
    content          TEXT                                        NOT NULL,
    course_id        INT                                         DEFAULT NULL,
    instructor_id    INT                                         DEFAULT NULL,
    status           ENUM('pending','approved','rejected')       NOT NULL DEFAULT 'pending',
    is_featured      TINYINT(1)                                  NOT NULL DEFAULT 0,
    display_order    INT                                         NOT NULL DEFAULT 0,
    source           ENUM('public_form','admin')                 NOT NULL DEFAULT 'public_form',
    moderated_by     INT                                         DEFAULT NULL,
    moderated_at     DATETIME                                    DEFAULT NULL,
    rejection_reason VARCHAR(300)                                DEFAULT NULL,
    created_at       DATETIME                                    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME                                    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_testimonials_public (status, is_featured, display_order, id),
    KEY idx_testimonials_status_created (status, created_at),
    KEY idx_testimonials_course (course_id),
    KEY idx_testimonials_instructor (instructor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
