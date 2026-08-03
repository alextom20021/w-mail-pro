-- =====================================================================
-- MailAI Platform — campaign lifecycle control for the AI agent
-- (pause / resume / cancel a campaign that's already been queued).
--
-- Adds a 'paused' state to the outbox status enum. The worker's claim
-- query only ever picks rows WHERE status = 'queued' AND
-- scheduled_at <= NOW(), so paused rows are naturally skipped with NO
-- worker code change: pause = queued->paused, resume = paused->queued,
-- cancel = queued/paused->cancelled. MODIFY COLUMN with a superset enum
-- is safe for existing rows (every current value remains valid).
-- =====================================================================
ALTER TABLE outbox
    MODIFY COLUMN status ENUM('queued','paused','locked','sending','sent','failed','soft_bounced','hard_bounced','cancelled') NOT NULL DEFAULT 'queued';
