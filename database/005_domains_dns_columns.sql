-- =====================================================================
-- MailAI Platform — follow-up to 004_platform_extensions.sql
--
-- 004's final statement (ALTER TABLE domains ADD COLUMN dns_provider...)
-- never actually ran against the live Aiven database: migrate.php's
-- original naive `explode(';', $sql)` statement splitter broke on the
-- semicolon inside that statement's own COMMENT string, so the
-- migration errored out with a 1064 syntax error on that last statement
-- while the six statements before it (contact_engagement_hours,
-- campaign_variants, outbox.variant_id, campaign_links, webhooks,
-- webhook_deliveries) had already committed successfully. migrate.php
-- has since been fixed to split quote-aware, but re-running 004 in full
-- now fails a different way (1060 duplicate column 'variant_id') because
-- those six statements aren't safe to re-run. This file isolates just
-- the one statement that never actually applied, so &only=005 finishes
-- the job without touching anything already in place.
-- =====================================================================
ALTER TABLE domains
    ADD COLUMN dns_provider VARCHAR(32) NULL COMMENT 'e.g. cloudflare, NULL = manual DNS',
    ADD COLUMN dns_provider_zone_id VARCHAR(64) NULL,
    ADD COLUMN dns_provider_token_encrypted TEXT NULL;
