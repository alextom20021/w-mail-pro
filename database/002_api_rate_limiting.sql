-- =====================================================================
-- MailAI Platform — Migration 002
-- Fixed-window rate limiting for the REST API v1. Kept in MySQL rather
-- than requiring Redis for this, so the API works even before the
-- Redis-backed queue upgrade (see README roadmap) — one less moving
-- part for a fresh deploy to get right.
-- =====================================================================

CREATE TABLE IF NOT EXISTS api_rate_limit_buckets (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED NOT NULL,
    window_start    DATETIME NOT NULL COMMENT 'truncated to the minute',
    request_count   INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_rate_limit_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_client_window (client_id, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_request_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED NOT NULL,
    method          VARCHAR(10) NOT NULL,
    path            VARCHAR(255) NOT NULL,
    status_code     SMALLINT UNSIGNED NOT NULL,
    ip_address      VARCHAR(45) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_api_request_log_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_api_request_log_client (client_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
