-- Схема БД auma-project-3
-- MySQL 8 / utf8mb4

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email         VARCHAR(190) NOT NULL,
    name          VARCHAR(190) NOT NULL DEFAULT '',
    password_hash VARCHAR(255) NOT NULL,
    role          VARCHAR(20)  NOT NULL DEFAULT 'user',
    created_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Папки. Состав дерева (группы и папки) описан в app/catalog.php —
-- таблица оставлена для совместимости и заполняется оттуда при необходимости.
CREATE TABLE IF NOT EXISTS folders (
    code        VARCHAR(20)  NOT NULL,
    title       VARCHAR(120) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    sort_order  INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documents (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_no    VARCHAR(64)  NOT NULL DEFAULT '',
    article     VARCHAR(64)  NOT NULL DEFAULT '',
    folder_code VARCHAR(20)  NOT NULL DEFAULT '',
    doc_type    VARCHAR(24)  NOT NULL DEFAULT 'other',   -- characteristics | scheme | other
    lang        VARCHAR(8)   NOT NULL DEFAULT 'en',
    format      VARCHAR(8)   NOT NULL DEFAULT '',        -- htm | pdf
    title       VARCHAR(255) NOT NULL DEFAULT '',
    filename    VARCHAR(255) NOT NULL,                   -- как файл называется у пользователя
    stored_name VARCHAR(255) NOT NULL,                   -- имя на диске
    size        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    mime        VARCHAR(128) NOT NULL DEFAULT '',
    source_url  VARCHAR(768) NOT NULL DEFAULT '',
    owner_id    INT UNSIGNED NULL,
    favorite    TINYINT(1)   NOT NULL DEFAULT 0,
    deleted     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL,
    updated_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_documents_order  (order_no),
    KEY idx_documents_folder (folder_code),
    KEY idx_documents_owner  (owner_id),
    KEY idx_documents_del    (deleted),
    KEY idx_documents_art    (article),
    CONSTRAINT fk_documents_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Журнал отправки писем. Реальная отправка пока отключена (заглушка),
-- поэтому здесь копится то, что было бы отправлено.
CREATE TABLE IF NOT EXISTS email_log (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id INT UNSIGNED NULL,
    user_id     INT UNSIGNED NULL,
    to_email    VARCHAR(190) NOT NULL,
    subject     VARCHAR(255) NOT NULL DEFAULT '',
    body        TEXT         NULL,
    status      VARCHAR(24)  NOT NULL DEFAULT 'stub',  -- stub | sent | failed
    error       VARCHAR(255) NOT NULL DEFAULT '',
    created_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_email_doc (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Журнал действий: кто что загрузил и когда.
CREATE TABLE IF NOT EXISTS events (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NULL,
    action     VARCHAR(40)  NOT NULL,
    entity     VARCHAR(40)  NOT NULL DEFAULT '',
    entity_id  INT UNSIGNED NULL,
    meta       VARCHAR(500) NOT NULL DEFAULT '',
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_events_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
