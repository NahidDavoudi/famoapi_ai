-- =============================================================================
-- Migration 005: Telegram Links, Report Threads, Messages, Broadcasts, Outbox & Reports Status
-- Target Engine: MySQL 8.0+ / 8.4+
-- =============================================================================

CREATE TABLE IF NOT EXISTS telegram_links (
    id               INT                                 NOT NULL AUTO_INCREMENT,
    telegram_user_id BIGINT                              NOT NULL,
    chat_id          BIGINT                              NOT NULL,
    role             ENUM('student','supporter','admin') NOT NULL,
    account_id       INT                                 NOT NULL,
    is_blocked       TINYINT(1)                          NOT NULL DEFAULT 0,
    linked_at        DATETIME                            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME                            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_telegram_role (telegram_user_id, role),
    UNIQUE KEY uk_role_account (role, account_id),
    KEY idx_telegram_chat (chat_id),
    KEY idx_telegram_blocked (role, is_blocked)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS report_threads (
    id           INT      NOT NULL AUTO_INCREMENT,
    student_id   INT      NOT NULL,
    day          DATE     NOT NULL,
    supporter_id INT      DEFAULT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_thread_student_day (student_id, day),
    KEY idx_thread_supporter (supporter_id),
    KEY idx_thread_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS report_messages (
    id                INT                                     NOT NULL AUTO_INCREMENT,
    thread_id         INT                                     NOT NULL,
    student_id        INT                                     NOT NULL,
    sender_role       ENUM('student','supporter','broadcast') NOT NULL,
    sender_account_id INT                                     DEFAULT NULL,
    body              TEXT                                    DEFAULT NULL,
    media_group_id    VARCHAR(64)                             DEFAULT NULL,
    is_broadcast      TINYINT(1)                              NOT NULL DEFAULT 0,
    read_by_student   TINYINT(1)                              NOT NULL DEFAULT 0,
    read_by_supporter TINYINT(1)                              NOT NULL DEFAULT 0,
    created_at        DATETIME                                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_msg_thread (thread_id, id),
    KEY idx_msg_student_supporter_read (student_id, read_by_supporter),
    KEY idx_msg_student_student_read (student_id, read_by_student),
    KEY idx_msg_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS report_message_attachments (
    id         INT                                                NOT NULL AUTO_INCREMENT,
    message_id INT                                                NOT NULL,
    kind       ENUM('photo','document','voice','video','audio')   NOT NULL,
    tg_file_id VARCHAR(255)                                       NOT NULL,
    file_name  VARCHAR(255)                                       DEFAULT NULL,
    mime_type  VARCHAR(127)                                       DEFAULT NULL,
    file_size  BIGINT                                             DEFAULT NULL,
    created_at DATETIME                                           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_att_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS bot_outbox (
    id                   INT                                                       NOT NULL AUTO_INCREMENT,
    kind                 ENUM('student_report','supporter_reply','broadcast')      NOT NULL,
    recipient_role       ENUM('student','supporter')                               NOT NULL,
    recipient_account_id INT                                                       NOT NULL,
    telegram_user_id     BIGINT                                                    DEFAULT NULL,
    chat_id              BIGINT                                                    DEFAULT NULL,
    status               ENUM('pending','processing','sent','failed','skipped')    NOT NULL DEFAULT 'pending',
    payload_json         MEDIUMTEXT                                                NOT NULL,
    ref_type             VARCHAR(40)                                               DEFAULT NULL,
    ref_id               INT                                                       DEFAULT NULL,
    dedup_key            VARCHAR(160)                                              DEFAULT NULL,
    attempts             INT                                                       NOT NULL DEFAULT 0,
    max_attempts         INT                                                       NOT NULL DEFAULT 3,
    available_at         DATETIME                                                  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_by            VARCHAR(64)                                               DEFAULT NULL,
    locked_at            DATETIME                                                  DEFAULT NULL,
    telegram_message_id  VARCHAR(64)                                               DEFAULT NULL,
    error                VARCHAR(500)                                              DEFAULT NULL,
    sent_at              DATETIME                                                  DEFAULT NULL,
    created_at           DATETIME                                                  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME                                                  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_outbox_dedup (dedup_key),
    KEY idx_outbox_claim (status, available_at, id),
    KEY idx_outbox_locked (status, locked_at),
    KEY idx_outbox_recipient (recipient_role, recipient_account_id),
    KEY idx_outbox_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS report_broadcasts (
    id               INT                                      NOT NULL AUTO_INCREMENT,
    supporter_id     INT                                      NOT NULL,
    audience         ENUM('no_report_today','all_students')   NOT NULL,
    day              DATE                                     NOT NULL,
    body             TEXT                                     DEFAULT NULL,
    attachment_count INT                                      NOT NULL DEFAULT 0,
    recipient_count  INT                                      NOT NULL DEFAULT 0,
    created_at       DATETIME                                 NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_broadcast_supporter (supporter_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS report_broadcast_recipients (
    id                  INT                                         NOT NULL AUTO_INCREMENT,
    broadcast_id        INT                                         NOT NULL,
    student_id          INT                                         NOT NULL,
    message_id          INT                                         DEFAULT NULL,
    outbox_id           INT                                         DEFAULT NULL,
    status              ENUM('pending','sent','failed','blocked')   NOT NULL DEFAULT 'pending',
    telegram_message_id VARCHAR(64)                                 DEFAULT NULL,
    error               VARCHAR(500)                                DEFAULT NULL,
    created_at          DATETIME                                    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME                                    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recipient_broadcast (broadcast_id),
    KEY idx_recipient_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS reports_status (
    id          INT                         NOT NULL AUTO_INCREMENT,
    student_id  INT                         NOT NULL,
    supporter_id INT                        DEFAULT NULL,
    file_id     INT                         DEFAULT NULL,
    report_text TEXT                        DEFAULT NULL,
    report_date DATE                        NOT NULL,
    status      ENUM('pending','replied')   DEFAULT 'pending',
    reply_text  TEXT                        DEFAULT NULL,
    created_at  DATETIME                    DEFAULT CURRENT_TIMESTAMP,
    replied_at  DATETIME                    DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
