# MailAI Platform

A multi-tenant, AI-agent-driven email deliverability platform. This repo is
being built in phases — this README always reflects the **actual, honest
state** of what's implemented vs. stubbed, so nothing here overstates what
exists.

## Status (current)

### Built and functional
- **Database schema** (`database/001_platform_schema.sql`,
  `002_api_rate_limiting.sql`, `003_auth_and_admin.sql`) — full multi-tenant
  schema: clients, contact lists/contacts, sending_connections (unified
  SMTP+API pool), outbox queue, suppressions/bounces/complaints, ISP-level
  analytics tables, warm-up schedules, AI audit log, API rate-limit buckets,
  super-admin logins. Requires **MySQL 8.0.29+** (uses
  `ADD COLUMN IF NOT EXISTS` syntax).
- **Encryption** (`src/Security/EncryptionService.php`) — libsodium
  XChaCha20-Poly1305 for all secrets at rest (SMTP passwords, API keys,
  DKIM keys).
- **Multi-tenancy core** (`src/Core/`) — `Database`, `ClientContext`,
  `TenantRepository`, `SessionAuth` (dashboard) + `ApiAuthenticator` (REST
  API) both funnel into the same `ClientContext`, `Csrf` for form
  protection. Every tenant-scoped query is structurally forced to filter by
  `client_id` from the authenticated context, never from caller input.
- **AI agent subsystem** (`src/AI/`) — provider-agnostic (OpenAI +
  Anthropic implementations, `FailoverProvider` for redundancy), a tool
  registry (`adjust_warmup`, `quarantine_connection`,
  `get_deliverability_summary`, `create_email_template`,
  `score_content_spam_risk`, `clean_contact_list`), and an
  `ai_autonomy_level` gate (`off` / `suggest_only` / `approve_required` /
  `full_auto`) — every tool call logged to `ai_audit_log` regardless of
  whether it executed or was queued for approval. `WarmupScheduler` and
  `AnomalyDetector` are rules-engine (fast, run every minute via
  `worker/ai_cycle.php`); `ContentScorer` and `ListCleaner` are heuristic
  and callable both directly and as agent tools.
- **Sending pipeline** — `ConnectionRotator` (weighted scoring), unified
  SMTP (PHPMailer) **and** API sending (SendGrid, Mailgun, SES w/ hand-
  rolled SigV4, Postmark — all real HTTP clients, `src/Sending/Api/`),
  `OutboxRepository` (atomic claiming via `FOR UPDATE SKIP LOCKED`,
  exponential backoff), `CampaignQueueingService` (expands a campaign into
  outbox rows), `worker/worker.php` (re-checks suppression at send time,
  enforces compliance footer + `List-Unsubscribe` unconditionally, applies
  DKIM via PHPMailer when configured, rewrites links/injects the tracking
  pixel before send).
- **DKIM + DNS verification** (`src/Security/DkimSigner.php`,
  `DomainVerificationService.php`) — key generation, PHPMailer-integrated
  signing, live SPF/DKIM/DMARC DNS checks with specific fix-it messages.
- **Tracking** (`src/Tracking/`) — signed-token open pixel and click
  redirector, `LinkRewriter`, GeoIP country lookup (degrades gracefully
  without a MaxMind DB file), engagement scoring feeding back into
  `contacts`.
- **REST API v1** (`public/api/v1/index.php`) — Bearer auth, per-client
  rate limiting (120 req/min, MySQL-backed fixed window), routes for
  connections/domains/lists/campaigns/analytics/suppressions/AI chat, every
  request logged.
- **Analytics** (`src/Analytics/AnalyticsService.php`) — by-ISP, by-country,
  by-connection, time series, failure-reason breakdown, all client-scoped.
- **Client dashboard** (`public/dashboard/`) — session-authed Bootstrap 5
  UI: overview (real charts), connections (add SMTP/SendGrid/Mailgun/SES/
  Postmark), domains (DKIM + DNS verify), contact lists (CSV import + AI
  clean button), campaigns (template spam-score-on-save + send), analytics
  drill-down, onboarding wizard, and a floating AI chat panel wired to the
  real agent.
- **Super-admin panel** (`public/admin/`) — client list with
  activate/suspend, global 24h health, full AI audit log viewer.
- **Docker Compose** — web/worker/cron/mysql/redis services.

### Explicitly stubbed / not yet built
- **Send-time optimization** — `contacts.best_send_hour_local` is written
  naively (last engagement hour, not a rolling histogram/mode) in
  `TrackingEventRecorder`; not yet used to actually schedule sends.
- **A/B testing auto-pilot** — no A/B split/winner-selection logic yet
  (`campaigns.ab_test_enabled` / `ab_winner_variant_id` columns exist,
  unused).
- **Automated DNS record application** (Cloudflare API etc.) —
  `DomainVerificationService` tells a client exactly what to publish; it
  does not publish it for them.
- **Click-link allowlisting** — `public/track/click.php` validates the
  redirect target is http(s) but doesn't cross-check it against links
  actually present in the original campaign (documented open-redirect
  caveat in that file).
- **PDF export** for analytics; CSV export exists implicitly via the API's
  JSON (no dedicated export endpoint yet).
- **Webhook support** for real-time event streaming (spec section 3.8) —
  not implemented.
- `email_logs` / `campaigns` schema assumptions — several repos assume
  columns on these two **pre-existing** (baseline-system) tables that
  weren't part of this project's migrations; see the `ASSUMPTION NOTE` in
  `src/Core/CampaignRepository.php` and the note in `worker/worker.php`'s
  `logEmailEvent()`. Verify against your actual tables before running
  against anything but a fresh database.

## Setup (local dev)

```bash
cp .env.example .env
# generate an encryption key and put it in .env:
php -r "require 'vendor/autoload.php'; echo MailAI\Security\EncryptionService::generateKey(), PHP_EOL;"

composer install
docker compose up -d
```

The `mysql` service auto-runs everything in `database/` (in filename order:
001, 002, 003) on first boot via `docker-entrypoint-initdb.d`.

To seed an AI provider, encrypt an API key with `EncryptionService` and
insert it into `ai_providers` — see `src/AI/AIProviderFactory.php` for the
expected row shape. To create your first client and super-admin login,
insert rows into `clients` / `super_admins` with
`password_hash(..., PASSWORD_DEFAULT)` — no signup UI exists yet (Phase 2:
proper registration flow).

Cron entries the spec calls for (add to the `cron` container or host
crontab):
```
* * * * * php /path/to/worker/worker.php 50 >> /var/log/mailai/worker.log 2>&1
* * * * * php /path/to/worker/ai_cycle.php >> /var/log/mailai/ai_cycle.log 2>&1
```
(`docker-compose.yml`'s `worker`/`cron` services already loop these every
5s/60s respectively — the crontab lines above are for a non-Docker deploy.)

## Architecture notes worth knowing before extending this

- **Tenant isolation is structural, not convention.** Don't bypass
  `TenantRepository` for client-facing code paths — it's the only thing
  guaranteeing one client can't read another's rows. Both `SessionAuth`
  (dashboard) and `ApiAuthenticator` (REST API) set the same
  `ClientContext`, so this guarantee holds regardless of entry point.
- **The AI agent can only do what's in `AIToolRegistry`.** It has no raw
  DB or shell access. Adding a new AI capability means writing a new tool
  handler in `AIToolHandlers`, not giving the model broader access.
- **`ai_autonomy_level` is per-client and enforced in `AIAgent`, not the
  UI.** Even a `full_auto` client still routes destructive tools
  (`delete_contact_list`, `disable_connection`, `send_campaign_now`)
  through human approval — see `AIAgent::DESTRUCTIVE_TOOLS`.
- **`worker.php` and `ai_cycle.php` are stateless per job/client.**
  `ClientContext` is cleared after every iteration specifically because
  these are long-running, multi-tenant processes — don't remove those
  `finally` blocks.
- **`isp_deliverability_stats.connection_id` uses `0`, never `NULL`, as
  its "not applicable" sentinel** (tracking events aren't tied to a
  specific connection). MySQL unique indexes treat every `NULL` as
  distinct, which would silently break the `ON DUPLICATE KEY UPDATE`
  upsert in `TrackingEventRecorder` — see the comment there before
  "simplifying" it back to `NULL`.
- **Content and spam-risk scores are heuristic, not authoritative.**
  `ContentScorer` says so in its own docblock and in the UI copy — real
  mailbox-provider filters are proprietary; don't let a future feature
  present the score as a guarantee.

## Roadmap (planned order)

1. Send-time optimization (real histogram-based best-hour scheduling) and
   A/B auto-pilot.
2. Cloudflare (or similar) DNS API integration for one-click record
   application.
3. Click-link allowlisting (register links per campaign at queue time).
4. Webhook event streaming.
5. PDF/CSV export endpoints for analytics.
6. Proper client signup/registration flow (currently insert-a-row-by-hand).
