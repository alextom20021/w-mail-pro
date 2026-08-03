-- =====================================================================
-- MailAI Platform — Migration 003
-- Session-based dashboard auth needs somewhere to store super-admin
-- logins (separate from `clients`, which are tenants, not platform
-- operators). Client dashboard auth reuses clients.email/password_hash,
-- already in migration 001.
-- =====================================================================

CREATE TABLE IF NOT EXISTS super_admins (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(190) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
