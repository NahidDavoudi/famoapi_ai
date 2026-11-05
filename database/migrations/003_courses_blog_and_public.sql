-- =============================================================================
-- Migration 003: Courses, Course Features, Subscriptions, Blog Categories & Posts
-- Target Engine: MySQL 8.0+ / 8.4+
-- =============================================================================

CREATE TABLE IF NOT EXISTS courses (
    id                   INT          NOT NULL AUTO_INCREMENT,
    name                 VARCHAR(255) NOT NULL,
    icon                 VARCHAR(100) NOT NULL,
    gradient_color_from  VARCHAR(7)   NOT NULL,
    gradient_color_to    VARCHAR(7)   NOT NULL,
    background_image_url TEXT         DEFAULT NULL,
    description          TEXT         DEFAULT NULL,
    price                VARCHAR(100) DEFAULT NULL,
    display_order        INT          DEFAULT 0,
    is_active            TINYINT(1)   NOT NULL DEFAULT 1,
    created_at           DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS course_features (
    id            INT          NOT NULL AUTO_INCREMENT,
    course_id     INT          NOT NULL,
    feature_text  VARCHAR(500) NOT NULL,
    display_order INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_course_features_course (course_id, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriptions (
    id         INT         NOT NULL AUTO_INCREMENT,
    course_id  INT         NOT NULL,
    student_id INT         NOT NULL,
    status     VARCHAR(30) NOT NULL DEFAULT 'pending',
    is_active  TINYINT(1)  NOT NULL DEFAULT 1,
    note       TEXT        DEFAULT NULL,
    started_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    end_at     DATETIME    DEFAULT NULL,
    created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_subscriptions_student (student_id, is_active),
    KEY idx_subscriptions_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_category (
    id          INT          NOT NULL AUTO_INCREMENT,
    name        VARCHAR(255) NOT NULL,
    slug        VARCHAR(191) NOT NULL,
    icon        VARCHAR(64)  DEFAULT NULL,
    color       VARCHAR(16)  DEFAULT NULL,
    description VARCHAR(500) DEFAULT NULL,
    sort_order  INT          NOT NULL DEFAULT 0,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_blog_category_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_posts (
    id               INT          NOT NULL AUTO_INCREMENT,
    title            VARCHAR(255) NOT NULL,
    slug             VARCHAR(255) NOT NULL,
    category_id      INT          DEFAULT NULL,
    excerpt          VARCHAR(500) DEFAULT NULL,
    content          MEDIUMTEXT   DEFAULT NULL,
    cover_image      VARCHAR(255) DEFAULT NULL,
    meta_description VARCHAR(300) DEFAULT NULL,
    published_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    views            INT          NOT NULL DEFAULT 0,
    is_published     TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY slug (slug),
    KEY idx_blog_published (is_published, published_at),
    KEY category_id (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
