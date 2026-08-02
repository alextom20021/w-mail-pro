-- =====================================================================
-- MailAI Platform — Phase 1 Migration
-- Multi-tenant, AI-agent-driven email deliverability platform
-- Run against the existing database that already has:
--   domains, ip_pool, email_templates, campaigns, email_logs, settings
-- This migration ADDS new tables and ALTERS existing ones to add
-- client_id scoping. Safe for a pre-launch DB with no live client data.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. CLIENTS (tenants)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clients (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid                CHAR(36)        NOT NULL UNIQUE,
    company_name        VARCHAR(190)    NOT NULL,
    email               VARCHAR(190)    NOT NULL UNIQUE,
    password_hash       VARCHAR(255)    NOT NULL,
    api_key             CHAR(64)        NOT NULL UNIQUE,
    api_key_scopes      JSON            NULL COMMENT 'e.g. ["read","write","admin"]',
    plan                ENUM('trial','starter','pro','enterprise') NOT NULL DEFAULT 'trial',
    status              ENUM('active','suspended','pending_verification','cancelled') NOT NULL DEFAULT 'pending_verification',
    quota_daily_sends    INT UNSIGNED    NOT NULL DEFAULT 1000,
    quota_contacts       INT UNSIGNED    NOT NULL DEFAULT 5000,
    quota_connections    SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    ai_autonomy_level   ENUM('off','suggest_only','approve_required','full_auto') NOT NULL DEFAULT 'suggest_only'
                        COMMENT 'How much authority the AI agent has to act without human confirmation',
    timezone            VARCHAR(64)     NOT NULL DEFAULT 'UTC',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_clients_status (status),
    INDEX idx_clients_api_key (api_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS client_settings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED NOT NULL,
    setting_key     VARCHAR(120) NOT NULL,
    setting_value   TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_client_setting (client_id, setting_key),
    CONSTRAINT fk_client_settings_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 2. ADD client_id TO EXISTING TABLES
-- ---------------------------------------------------------------------
ALTER TABLE domains
    ADD COLUMN IF NOT EXISTS client_id INT UNSIGNED NULL AFTER id,
    ADD COLUMN IF NOT EXISTS dkim_selector VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS dkim_private_key_encrypted TEXT NULL,
    ADD COLUMN IF NOT EXISTS dkim_public_key TEXT NULL,
    ADD COLUMN IF NOT EXISTS spf_status ENUM('unknown','pass','fail') NOT NULL DEFAULT 'unknown',
    ADD COLUMN IF NOT EXISTS dkim_status ENUM('unknown','pass','fail') NOT NULL DEFAULT 'unknown',
    ADD COLUMN IF NOT EXISTS dmarc_status ENUM('unknown','pass','fail') NOT NULL DEFAULT 'unknown',
    ADD COLUMN IF NOT EXISTS dns_verification_status ENUM('pending','verified','failed') NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS dns_last_checked_at DATETIME NULL,
    ADD INDEX IF NOT EXISTS idx_domains_client (client_id);

ALTER TABLE ip_pool
    ADD COLUMN IF NOT EXISTS client_id INT UNSIGNED NULL AFTER id,
    ADD COLUMN IF NOT EXISTS reputation_score DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    ADD COLUMN IF NOT EXISTS warmup_stage TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS is_quarantined TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS quarantined_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS quarantine_reason VARCHAR(255) NULL,
    ADD INDEX IF NOT EXISTS idx_ip_pool_client (client_id);

ALTER TABLE email_templates
    ADD COLUMN IF NOT EXISTS client_id INT UNSIGNED NULL AFTER id,
    ADD COLUMN IF NOT EXISTS ai_generated TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS spam_score DECIMAL(5,2) NULL,
    ADD COLUMN IF NOT EXISTS spam_score_notes JSON NULL,
    ADD INDEX IF NOT EXISTS idx_templates_client (client_id);

ALTER TABLE campaigns
    ADD COLUMN IF NOT EXISTS client_id INT UNSIGNED NULL AFTER id,
    ADD COLUMN IF NOT EXISTS ai_managed TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'AI agent may adjust this campaign autonomously',
    ADD COLUMN IF NOT EXISTS ab_test_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS ab_winner_variant_id INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS send_time_optimized TINYINT(1) NOT NULL DEFAULT 0,
    ADD INDEX IF NOT EXISTS idx_campaigns_client (client_id);

ALTER TABLE email_logs
    ADD COLUMN IF NOT EXISTS client_id INT UNSIGNED NULL AFTER id,
    ADD COLUMN IF NOT EXISTS connection_id INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS isp VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS recipient_country CHAR(2) NULL,
    ADD INDEX IF NOT EXISTS idx_email_logs_client (client_id),
    ADD INDEX IF NOT EXISTS idx_email_logs_isp (isp);

-- ---------------------------------------------------------------------
-- 3. CONTACT LISTS & CONTACTS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contact_lists (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED NOT NULL,
    name            VARCHAR(190) NOT NULL,
    description     VARCHAR(500) NULL,
    contact_count   INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_contact_lists_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_contact_lists_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contacts (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id           INT UNSIGNED NOT NULL,
    list_id             INT UNSIGNED NOT NULL,
    email               VARCHAR(190) NOT NULL,
    first_name          VARCHAR(120) NULL,
    last_name           VARCHAR(120) NULL,
    custom_fields       JSON NULL,
    status              ENUM('subscribed','unsubscribed','bounced','complained','suppressed','pending') NOT NULL DEFAULT 'subscribed',
    consent_source      VARCHAR(120) NULL COMMENT 'e.g. web_form, import, api',
    consent_ip          VARCHAR(45) NULL,
    consent_at          DATETIME NULL,
    country             CHAR(2) NULL,
    isp                 VARCHAR(64) NULL,
    engagement_score    DECIMAL(5,2) NOT NULL DEFAULT 0,
    best_send_hour_local TINYINT UNSIGNED NULL COMMENT 'AI-learned optimal send hour 0-23',
    risk_flags          JSON NULL COMMENT 'AI list-cleaning output: role_account, spam_trap_suspect, disposable, etc.',
    last_engaged_at     DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_contacts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_contacts_list FOREIGN KEY (list_id) REFERENCES contact_lists(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_contact_per_list (list_id, email),
    INDEX idx_contacts_client (client_id),
    INDEX idx_contacts_status (status),
    INDEX idx_contacts_isp (isp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 4. SENDING CONNECTIONS (unified SMTP + API rotation pool)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sending_connections (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id               INT UNSIGNED NOT NULL,
    type                    ENUM('smtp','sendgrid','mailgun','ses','postmark') NOT NULL,
    label                   VARCHAR(120) NOT NULL,
    credentials_encrypted   TEXT NOT NULL COMMENT 'libsodium-sealed JSON blob: host/port/user/pass or api_key',
    from_domain_id          INT UNSIGNED NULL,
    daily_limit             INT UNSIGNED NOT NULL DEFAULT 50,
    sent_today               INT UNSIGNED NOT NULL DEFAULT 0,
    warmup_stage             TINYINT UNSIGNED NOT NULL DEFAULT 0,
    warmup_started_at        DATETIME NULL,
    reputation_score          DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    status                   ENUM('active','warming','quarantined','disabled') NOT NULL DEFAULT 'warming',
    quarantine_reason         VARCHAR(255) NULL,
    last_used_at              DATETIME NULL,
    created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sending_connections_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_sending_connections_domain FOREIGN KEY (from_domain_id) REFERENCES domains(id) ON DELETE SET NULL,
    INDEX idx_sending_connections_client (client_id),
    INDEX idx_sending_connections_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 5. OUTBOX (send queue)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS outbox (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id           INT UNSIGNED NOT NULL,
    campaign_id         INT UNSIGNED NULL,
    contact_id          BIGINT UNSIGNED NOT NULL,
    connection_id        INT UNSIGNED NULL COMMENT 'assigned at pickup time by rotation logic',
    to_email             VARCHAR(190) NOT NULL,
    subject               VARCHAR(500) NOT NULL,
    html_body             MEDIUMTEXT NOT NULL,
    headers_json           JSON NULL,
    scheduled_at            DATETIME NOT NULL COMMENT 'may be set by send-time optimizer',
    status                 ENUM('queued','locked','sending','sent','failed','soft_bounced','hard_bounced','cancelled') NOT NULL DEFAULT 'queued',
    attempts                TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts             TINYINT UNSIGNED NOT NULL DEFAULT 5,
    next_retry_at             DATETIME NULL,
    locked_by                 VARCHAR(64) NULL COMMENT 'worker instance id, prevents double-send',
    locked_at                  DATETIME NULL,
    last_error                  TEXT NULL,
    smtp_transcript              MEDIUMTEXT NULL,
    sent_at                       DATETIME NULL,
    created_at                     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_outbox_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_outbox_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_outbox_connection FOREIGN KEY (connection_id) REFERENCES sending_connections(id) ON DELETE SET NULL,
    INDEX idx_outbox_pickup (status, scheduled_at),
    INDEX idx_outbox_client (client_id),
    INDEX idx_outbox_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 6. COMPLIANCE: suppressions, bounces, complaints
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS suppressions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED NOT NULL,
    email           VARCHAR(190) NOT NULL,
    reason          ENUM('unsubscribe','hard_bounce','complaint','manual','ai_list_clean') NOT NULL,
    source_detail    VARCHAR(255) NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_suppressions_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_suppression (client_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bounces (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED NOT NULL,
    outbox_id        BIGINT UNSIGNED NULL,
    connection_id     INT UNSIGNED NULL,
    email             VARCHAR(190) NOT NULL,
    bounce_type        ENUM('soft','hard') NOT NULL,
    smtp_code           VARCHAR(10) NULL,
    diagnostic_message   TEXT NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_bounces_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_bounces_client (client_id),
    INDEX idx_bounces_connection (connection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS complaints (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED NOT NULL,
    outbox_id        BIGINT UNSIGNED NULL,
    connection_id     INT UNSIGNED NULL,
    email             VARCHAR(190) NOT NULL,
    feedback_type       VARCHAR(64) NULL COMMENT 'abuse, fraud, etc from FBL',
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_complaints_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_complaints_client (client_id),
    INDEX idx_complaints_connection (connection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 7. ANALYTICS: isp_deliverability_stats, reputation_history, warmup_schedules
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS isp_deliverability_stats (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id           INT UNSIGNED NOT NULL,
    connection_id        INT UNSIGNED NULL,
    stat_date              DATE NOT NULL,
    isp                     VARCHAR(64) NOT NULL,
    country                  CHAR(2) NULL,
    sent                      INT UNSIGNED NOT NULL DEFAULT 0,
    delivered                  INT UNSIGNED NOT NULL DEFAULT 0,
    opened                       INT UNSIGNED NOT NULL DEFAULT 0,
    clicked                       INT UNSIGNED NOT NULL DEFAULT 0,
    soft_bounced                   INT UNSIGNED NOT NULL DEFAULT 0,
    hard_bounced                     INT UNSIGNED NOT NULL DEFAULT 0,
    complained                         INT UNSIGNED NOT NULL DEFAULT 0,
    inbox_estimate_pct                   DECIMAL(5,2) NULL,
    CONSTRAINT fk_isp_stats_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_isp_stat (client_id, connection_id, stat_date, isp, country),
    INDEX idx_isp_stats_lookup (client_id, isp, stat_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reputation_history (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED NOT NULL,
    connection_id    INT UNSIGNED NOT NULL,
    recorded_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reputation_score     DECIMAL(5,2) NOT NULL,
    complaint_rate         DECIMAL(6,4) NULL,
    bounce_rate               DECIMAL(6,4) NULL,
    notes                       VARCHAR(500) NULL,
    CONSTRAINT fk_reputation_history_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_reputation_history_connection FOREIGN KEY (connection_id) REFERENCES sending_connections(id) ON DELETE CASCADE,
    INDEX idx_reputation_history_conn (connection_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS warmup_schedules (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id           INT UNSIGNED NOT NULL,
    connection_id         INT UNSIGNED NOT NULL,
    day_number             SMALLINT UNSIGNED NOT NULL,
    target_volume             INT UNSIGNED NOT NULL,
    actual_volume               INT UNSIGNED NULL,
    ai_adjusted                   TINYINT(1) NOT NULL DEFAULT 0,
    adjustment_reason               VARCHAR(255) NULL,
    applies_on                        DATE NOT NULL,
    CONSTRAINT fk_warmup_schedules_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_warmup_schedules_connection FOREIGN KEY (connection_id) REFERENCES sending_connections(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_warmup_day (connection_id, day_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 8. AI SUBSYSTEM: audit log, provider config, agent conversations
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_audit_log (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id           INT UNSIGNED NULL COMMENT 'null for platform-level/super-admin actions',
    actor               ENUM('agent','system_rule','human') NOT NULL DEFAULT 'agent',
    provider              VARCHAR(40) NULL COMMENT 'openai, anthropic, local_rules, etc.',
    tool_name              VARCHAR(80) NOT NULL COMMENT 'e.g. rotate_connection, adjust_warmup, create_template',
    input_json               JSON NULL,
    output_json                JSON NULL,
    decision_summary             VARCHAR(500) NULL,
    autonomy_level                 ENUM('suggest_only','approve_required','full_auto') NOT NULL,
    approved_by_user                 INT UNSIGNED NULL COMMENT 'client user id if human approved',
    status                             ENUM('proposed','approved','rejected','executed','failed') NOT NULL DEFAULT 'proposed',
    created_at                           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_audit_client (client_id, created_at),
    INDEX idx_ai_audit_tool (tool_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_conversations (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED NOT NULL,
    title           VARCHAR(190) NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ai_conversations_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_messages (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id     BIGINT UNSIGNED NOT NULL,
    role                 ENUM('user','assistant','tool') NOT NULL,
    content                TEXT NULL,
    tool_calls_json          JSON NULL,
    created_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ai_messages_conversation FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE,
    INDEX idx_ai_messages_conv (conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Platform-level AI provider credentials (super-admin managed; clients can
-- optionally supply their own key via client_settings, checked first).
CREATE TABLE IF NOT EXISTS ai_providers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(40) NOT NULL UNIQUE COMMENT 'openai, anthropic, local_rules',
    api_key_encrypted TEXT NULL,
    default_model     VARCHAR(80) NULL,
    is_active            TINYINT(1) NOT NULL DEFAULT 1,
    priority               TINYINT UNSIGNED NOT NULL DEFAULT 100 COMMENT 'lower = tried first',
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
