-- =====================================================================
-- MailAI Platform — Phase 2 migration
-- Adds: send-time optimization (real per-contact hour histogram),
-- A/B testing (campaign variants + winner tracking), click-link
-- allowlisting (per-campaign registered links), and webhook event
-- streaming (subscriptions + delivery/retry log).
-- Safe to run multiple times: every statement is IF NOT EXISTS.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. SEND-TIME OPTIMIZATION
-- Per-contact histogram of which hour-of-day (0-23, server/UTC hour —
-- there's no per-contact timezone geocoding in this project) their
-- opens/clicks land in. best_send_hour_local on `contacts` is the mode
-- of this histogram, recomputed on every engagement event rather than
-- naively overwritten with "whatever hour it is right now".
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contact_engagement_hours (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED NOT NULL,
    contact_id      BIGINT UNSIGNED NOT NULL,
    hour_of_day     TINYINT UNSIGNED NOT NULL,
    event_count     INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_contact_hour (contact_id, hour_of_day),
    INDEX idx_engagement_hours_client (client_id),
    CONSTRAINT fk_engagement_hours_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 2. A/B TESTING
-- campaigns.ab_test_enabled / ab_winner_variant_id already exist (see
-- 001_platform_schema.sql). This adds the variants themselves.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS campaign_variants (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id           INT UNSIGNED NOT NULL,
    campaign_id         INT UNSIGNED NOT NULL,
    variant_label       VARCHAR(10) NOT NULL COMMENT 'A, B, C...',
    template_id         INT UNSIGNED NOT NULL,
    subject_override    VARCHAR(255) NULL COMMENT 'NULL = use the template subject as-is',
    traffic_percent     TINYINT UNSIGNED NOT NULL DEFAULT 50,
    sent_count          INT UNSIGNED NOT NULL DEFAULT 0,
    opened_count        INT UNSIGNED NOT NULL DEFAULT 0,
    clicked_count       INT UNSIGNED NOT NULL DEFAULT 0,
    is_winner           TINYINT(1) NOT NULL DEFAULT 0,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_campaign_variant (campaign_id, variant_label),
    INDEX idx_campaign_variants_client (client_id),
    INDEX idx_campaign_variants_campaign (campaign_id),
    CONSTRAINT fk_campaign_variants_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE outbox
    ADD COLUMN variant_id INT UNSIGNED NULL COMMENT 'which campaign_variants row this send used, NULL for non-A/B campaigns' AFTER campaign_id,
    ADD INDEX idx_outbox_variant (variant_id);

-- ---------------------------------------------------------------------
-- 3. CLICK-LINK ALLOWLISTING
-- Registered at campaign-queue time (CampaignQueueingService), one row
-- per distinct URL found in the template. public/track/click.php checks
-- the redirect target against this table instead of just validating
-- "is it http(s)" — closes the open-redirect gap noted in that file.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS campaign_links (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED NOT NULL,
    campaign_id     INT UNSIGNED NOT NULL,
    url_hash        CHAR(64) NOT NULL COMMENT 'sha256(original_url), for fast unique lookup',
    original_url    TEXT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_campaign_link (campaign_id, url_hash),
    INDEX idx_campaign_links_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 4. WEBHOOK EVENT STREAMING
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webhooks (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id           INT UNSIGNED NOT NULL,
    url                 VARCHAR(500) NOT NULL,
    secret_encrypted    TEXT NOT NULL COMMENT 'HMAC signing secret for X-MailAI-Signature, encrypted at rest',
    events              JSON NOT NULL COMMENT 'e.g. ["send","open","click","bounce","complaint"]',
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_webhooks_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    webhook_id          INT UNSIGNED NOT NULL,
    client_id           INT UNSIGNED NOT NULL,
    event_type          VARCHAR(32) NOT NULL,
    payload             JSON NOT NULL,
    response_status     SMALLINT NULL,
    attempt_count       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    delivered_at        DATETIME NULL,
    next_attempt_at     DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_webhook_deliveries_webhook (webhook_id),
    INDEX idx_webhook_deliveries_pending (next_attempt_at),
    CONSTRAINT fk_webhook_deliveries_webhook FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 5. CLOUDFLARE DNS AUTOMATION
-- Per-domain, optional: a client can link a Cloudflare zone + API token
-- so DomainVerificationService's recommended records can be published
-- automatically instead of just displayed.
--
-- Plain ADD COLUMN (no IF NOT EXISTS) is deliberate — see the note in
-- 001_platform_schema.sql: MySQL 8.4 (confirmed against live Aiven)
-- rejects "ADD COLUMN IF NOT EXISTS" with a 1064 syntax error outright.
-- This migration is meant to run once against a database that already
-- has 001-003 applied and none of these 3 columns yet.
-- ---------------------------------------------------------------------
ALTER TABLE domains
    ADD COLUMN dns_provider VARCHAR(32) NULL COMMENT 'e.g. cloudflare, NULL = manual DNS',
    ADD COLUMN dns_provider_zone_id VARCHAR(64) NULL,
    ADD COLUMN dns_provider_token_encrypted TEXT NULL;
