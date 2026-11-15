-- =============================================================================
-- Migration 001: Core Identity, Users, Roles, Supporters, Instructors, Students
-- Target Engine: MySQL 8.0+ / 8.4+
-- =============================================================================

CREATE TABLE IF NOT EXISTS admins (
    id         INT          NOT NULL AUTO_INCREMENT,
    name       VARCHAR(255) NOT NULL,
    phone      VARCHAR(20)  DEFAULT NULL,
    chat_id    BIGINT       DEFAULT NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admins_phone (phone),
    KEY idx_admins_chat_id (chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supporters (
    id          INT          NOT NULL AUTO_INCREMENT,
    name        VARCHAR(255) NOT NULL,
    grade       INT          NOT NULL DEFAULT 12,
    field       VARCHAR(50)  NOT NULL DEFAULT '',
    phone       VARCHAR(15)  DEFAULT NULL,
    chat_id     BIGINT       DEFAULT NULL,
    avatar      VARCHAR(255) DEFAULT NULL,
    description TEXT         DEFAULT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    admin_id    INT          DEFAULT NULL,
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_supporters_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supporter_scopes (
    id           INT         NOT NULL AUTO_INCREMENT,
    supporter_id INT         NOT NULL,
    field        VARCHAR(50) NOT NULL,
    grade        INT         NOT NULL,
    created_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_supporter_scope (supporter_id, field, grade),
    KEY idx_scope_field_grade (field, grade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS instructors (
    id             INT          NOT NULL AUTO_INCREMENT,
    name           VARCHAR(255) NOT NULL,
    title          VARCHAR(255) NOT NULL,
    description    TEXT          DEFAULT NULL,
    image_url      TEXT         DEFAULT NULL,
    initial_letter VARCHAR(10)  DEFAULT NULL,
    display_order  INT          DEFAULT 0,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at     DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_instructors_id (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS instructor_social_links (
    id            INT          NOT NULL AUTO_INCREMENT,
    instructor_id INT          NOT NULL,
    platform      VARCHAR(50)  NOT NULL,
    url           VARCHAR(500) NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_instructor_social_instructor (instructor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS students (
    id           INT          NOT NULL AUTO_INCREMENT,
    name         VARCHAR(255) NOT NULL,
    grade        INT          NOT NULL,
    field        VARCHAR(50)  DEFAULT NULL,
    chat_id      BIGINT       DEFAULT NULL,
    phone        VARCHAR(15)  DEFAULT NULL,
    national_id  VARCHAR(10)  DEFAULT NULL,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    supporter_id VARCHAR(256) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_students_supporter (supporter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS users (
    id            INT          NOT NULL AUTO_INCREMENT,
    full_name     VARCHAR(255) DEFAULT NULL,
    username      VARCHAR(100) NOT NULL,
    phone         VARCHAR(20)  DEFAULT NULL,
    email         VARCHAR(255) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','supporter','student','teacher') NOT NULL DEFAULT 'student',
    linked_id     INT          DEFAULT NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
    last_login    DATETIME     DEFAULT NULL,
    chat_id       VARCHAR(256) DEFAULT NULL,
    supporter_id  INT          DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY chat_id (chat_id),
    KEY idx_users_id (id),
    KEY idx_users_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS parent_contacts (
    id           INT          NOT NULL AUTO_INCREMENT,
    student_id   INT          NOT NULL,
    parent_name  VARCHAR(255) DEFAULT NULL,
    relationship VARCHAR(50)  DEFAULT NULL,
    phone        VARCHAR(15)  NOT NULL,
    is_primary   TINYINT(1)   DEFAULT 0,
    created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_parent_contacts_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_supporter_assignments (
    id                INT          NOT NULL AUTO_INCREMENT,
    student_id        INT          NOT NULL,
    supporter_id      INT          NOT NULL,
    is_active         TINYINT(1)   NOT NULL DEFAULT 1,
    assigned_by       INT          DEFAULT NULL,
    note              VARCHAR(255) DEFAULT NULL,
    assigned_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deactivated_at    DATETIME     DEFAULT NULL,
    active_student_id INT GENERATED ALWAYS AS (CASE WHEN is_active = 1 THEN student_id ELSE NULL END) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uk_active_student (active_student_id),
    KEY idx_ssa_supporter_active (supporter_id, is_active),
    KEY idx_ssa_student_history (student_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS login_codes (
    id         INT        NOT NULL AUTO_INCREMENT,
    user_id    INT        NOT NULL,
    code       VARCHAR(6) NOT NULL,
    expires_at DATETIME   NOT NULL,
    used       TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_id (user_id),
    KEY idx_code (code),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS telegram_auth_nonces (
    id          BIGINT                   NOT NULL AUTO_INCREMENT,
    kind        ENUM('payload','ticket') NOT NULL,
    nonce       VARCHAR(64)              NOT NULL,
    expires_at  DATETIME                 NOT NULL,
    consumed_at DATETIME                 DEFAULT NULL,
    created_at  DATETIME                 NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_kind_nonce (kind, nonce),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS telegram_2fa_challenges (
    id              BIGINT      NOT NULL AUTO_INCREMENT,
    challenge_nonce VARCHAR(64) NOT NULL,
    ticket_jti      VARCHAR(64) NOT NULL,
    user_id         INT         NOT NULL,
    attempts        INT         NOT NULL DEFAULT 0,
    expires_at      DATETIME    NOT NULL,
    consumed_at     DATETIME    DEFAULT NULL,
    created_at      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_challenge_nonce (challenge_nonce),
    KEY idx_ticket_jti (ticket_jti),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS auth_throttle (
    throttle_key      VARCHAR(191) NOT NULL,
    attempts          INT          NOT NULL DEFAULT 0,
    window_started_at DATETIME     NOT NULL,
    locked_until      DATETIME     DEFAULT NULL,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (throttle_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
