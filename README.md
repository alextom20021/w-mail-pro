# MailAI Platform

A multi-tenant, AI-agent-driven email deliverability platform. This repo is
being built in phases — this README always reflects the **actual, honest
state** of what's implemented vs. stubbed, so nothing here overstates what
exists.

## Status (current)

### Built and functional
- **Database schema** (`database/001_platform_schema.sql`,
  `002_api_rate_limiting.sql`, `003_auth_and_admin.sql`,
  `004_platform_extensions.sql`, `005_domains_dns_columns.sql`,
  `006_admin_client_management.sql`, `007_campaign_lifecycle.sql`) — full multi-tenant
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
  `score_content_spam_risk`, `clean_contact_list`, plus the setup/
  provisioning tools listed under Phase 2 below —
  `add_sending_connection`, `add_domain`, `create_campaign`, etc.), and
  an `ai_autonomy_level` gate (`off` / `suggest_only` / `approve_required`
  / `full_auto`) — every tool call logged to `ai_audit_log` regardless of
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

### Built and functional (Phase 2 additions)
- **Self-serve signup** (`public/dashboard/signup.php`,
  `src/Core/ClientRegistrationService.php`) — replaces the old
  insert-a-row-by-hand flow; creates a client and logs them straight into
  the onboarding wizard.
- **Real send-time optimization** (`src/AI/SendTimeOptimizer.php`,
  `contact_engagement_hours` table) — a rolling per-contact histogram of
  which hour opens/clicks land in; `contacts.best_send_hour_local` is the
  mode of that histogram, recomputed on every engagement event, not just
  overwritten with "whatever hour it is right now".
- **A/B testing auto-pilot** (`src/AI/ABTestingService.php`,
  `src/Sending/CampaignQueueingService::enqueueAbTest()`,
  `campaign_variants` table) — splits a campaign across variants by
  traffic percentage at queue time; once every variant has fully sent
  with enough combined sample size, `ai_cycle.php`'s per-minute run picks
  a winner by open rate (click rate fallback) automatically. Sends the
  full split up front rather than a small-test-then-rollout-winner
  design — see that class's docblock for the tradeoff.
- **Click-link allowlisting** (`src/Tracking/ClickAllowlist.php`,
  `campaign_links` table) — every link a campaign's content actually
  contains is registered once at queue time; `public/track/click.php`
  now rejects any redirect target that isn't in that set, closing the
  open-redirect gap that used to be documented there.
- **Webhook event streaming** (`src/Webhooks/`, `webhooks` /
  `webhook_deliveries` tables, `worker/webhook_worker.php`) — clients
  manage subscriptions from `public/dashboard/webhooks.php`; send/open/
  click/bounce events are queued by `WebhookDispatcher` (never delivered
  inline — a slow customer endpoint must not block sending/tracking) and
  POSTed with an HMAC-SHA256 signature by the dedicated worker, 5 retries
  with exponential backoff.
- **CSV/PDF analytics export** (`src/Export/`,
  `public/dashboard/analytics_export.php`) — PDF via a small hand-rolled
  writer (`SimplePdfWriter`), not a third-party library: this sandbox has
  no outbound network access to verify a new Composer dependency
  actually builds on Render, and this project already hit exactly that
  failure mode once with `firebase/php-jwt`. Raw PDF syntax has nothing
  to fail to install.
- **Cloudflare DNS automation** (`src/Security/CloudflareDnsService.php`,
  wired into `public/dashboard/domains.php`) — optional per-domain: a
  client can link a scoped Cloudflare API token + zone ID (verified
  before saving, encrypted at rest) and click "Auto-apply DNS records"
  instead of copy/pasting SPF/DKIM/DMARC by hand.
- **AI agent can now perform setup work, not just advise** — this is the
  core "AI does the work" goal made real:
  `add_sending_connection`/`add_domain`/`create_campaign`/`apply_dns_records`
  and read-only `list_connections`/`list_domains`/`list_contact_lists`
  tools (`src/AI/AIToolHandlers.php`) let a client say "connect my
  SendGrid account" or "add mail.mydomain.com" in chat; the agent asks
  for whatever details it needs conversationally, then calls the tool.
  `send_campaign_now`/`disable_connection`/`delete_contact_list` remain
  in `AIAgent::DESTRUCTIVE_TOOLS` — always gated on human approval
  regardless of `ai_autonomy_level`. The dashboard chat widget
  (`layout_foot.php`) now has real Approve/Reject buttons wired to
  `public/dashboard/ai_approve.php`, which re-validates the pending
  action belongs to the logged-in client before executing it — the
  previous build logged pending approvals but had no UI path to actually
  approve one.
- **The AI agent manages the ENTIRE platform for the client** (the spec's
  MAIN GOAL: "client co-works with agent with prompts, agent asks for
  details, client confirms, agent works") — `src/AI/AIPlatformTools.php`
  adds the second half of the tool surface on top of `AIToolHandlers`,
  bringing the agent to **40 tools** covering every dashboard capability:
  account overview + onboarding-gap detection (`get_account_overview`),
  templates (list/get/update, plus create + spam-score from phase 1),
  contact lists (create, `add_contacts` bulk import with per-address
  invalid/duplicate/suppressed reporting, `get_list_contacts` inspection),
  suppression hygiene (`suppress_email`, `list_suppressions` — there is
  deliberately NO unsuppress tool, since undoing an unsubscribe is a
  compliance decision a human must make from the dashboard), full
  campaign lifecycle (`list_campaigns`, `get_campaign_progress`,
  `schedule_campaign` for future sends, `pause_campaign` /
  `resume_campaign` mid-send, `cancel_campaign`,
  `send_ab_test_campaign` which creates variants + traffic-splits +
  auto-winner-selects), connection lifecycle (`resume_connection`,
  `get_warmup_history`, plus add/disable/quarantine/warm-up from
  phase 1), real analytics (`get_analytics`: isp/country/connection/
  timeseries/failures), and webhooks (list/create/pause). The
  destructive list grew to match: `schedule_campaign`,
  `cancel_campaign`, and `send_ab_test_campaign` joined
  `send_campaign_now`/`disable_connection`/`delete_contact_list` in
  `AIAgent::DESTRUCTIVE_TOOLS` — anything that commits or destroys real
  send volume always pauses for the client's Approve click regardless
  of autonomy level. Campaign pause/resume works with **zero worker
  changes**: `007_campaign_lifecycle.sql` adds a `paused` state to the
  outbox enum, and since the worker only ever claims
  `status='queued' AND scheduled_at <= NOW()`, paused rows (and
  future-scheduled rows, which is how `schedule_campaign` works) are
  naturally skipped. The chat system prompt was rewritten to match: the
  agent introduces itself as the operator of the account, looks up real
  state before acting, drives onboarding proactively for new clients,
  and never reports numbers a tool didn't return.
- **Super Admin client management (spec 1.2)** —
  `public/admin/clients.php` (search/filter/bulk suspend-activate/create)
  and `public/admin/client_detail.php` (plan + quota editing, force
  logout, force password reset, internal notes, a merged
  campaigns/AI-actions/admin-actions activity timeline, soft-delete with
  a restore path, and permanent purge gated on typing the client's email
  to confirm). `src/Core/ClientAdminRepository.php` is deliberately NOT a
  `TenantRepository` — it's the one place in the codebase allowed to
  query across every client, and every method on it should only ever be
  called from a `SessionAuth::requireSuperAdmin()`-gated page.
  `src/Core/AdminAuditLogger.php` + the `admin_audit_log` table record
  every one of these actions (who, what, which client, when) — this is
  spec 1.7's "platform-wide audit log", distinct from `ai_audit_log`
  which records the AI agent's own tool calls, not human admin actions.
- **Impersonation** — `SessionAuth::startImpersonation()` lets a super
  admin log in as a client (audited) to see exactly what they see; the
  client dashboard chrome shows an unmissable "a super admin is viewing
  this account as you" banner with a one-click "Return to Admin" link
  (`public/admin/stop_impersonate.php`) for the whole time it's active.
- **Force logout now actually forces logout** — `clients.session_version`
  is bumped by "Force logout", client suspend, soft-delete, and forced
  password reset; `SessionAuth::requireClient()` re-checks
  status/deleted_at/session_version against the DB on *every* request
  (not just at login), so these actions kick an already-open browser
  session immediately instead of only blocking the next login attempt.

### Explicitly stubbed / not yet built
- **Two-factor authentication**, ticket system, GDPR self-service export
  tooling, plan/billing/quota *enforcement* UI (quotas are stored and
  editable per client, but nothing yet throttles a client who exceeds
  `quota_daily_sends`/`quota_contacts`/`quota_connections`), and the rest
  of the super-admin suite (AI engine control panel, infra oversight
  dashboards beyond connection/domain lists, revenue/usage overview,
  platform settings UI, queue worker restart controls) — large,
  separately-scoped pieces of the full spec not yet built.
- `email_logs` / `campaigns` schema assumptions — several repos assume
  columns on these two tables; see the `ASSUMPTION NOTE` in
  `src/Core/CampaignRepository.php` and the note in `worker/worker.php`'s
  `logEmailEvent()`. As of `004_platform_extensions.sql` both tables are
  actually created by this project's own migrations on a fresh database
  (see the note further down), so this mainly matters if you're
  retrofitting onto a genuinely pre-existing install with different
  column names.

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
`password_hash(..., PASSWORD_DEFAULT)`, or use `public/dashboard/signup.php`
for a real self-serve account.

Cron entries the spec calls for (add to the `cron` container or host
crontab):
```
* * * * * php /path/to/worker/worker.php 50 >> /var/log/mailai/worker.log 2>&1
* * * * * php /path/to/worker/ai_cycle.php >> /var/log/mailai/ai_cycle.log 2>&1
* * * * * php /path/to/worker/webhook_worker.php >> /var/log/mailai/webhook_worker.log 2>&1
```
(`docker-compose.yml`'s `worker`/`cron` services already loop `worker.php`/
`ai_cycle.php` every 5s/60s respectively — add `webhook_worker.php` to the
same loop if you're deploying with Docker Compose; the crontab lines above
are for a non-Docker deploy.)

## Deploying to Render

`render.yaml` provisions the web service, two worker services (outbox
processor + AI cycle), and Redis via Render's Blueprint feature ("New +" →
"Blueprint", point at this repo). Two things to know before deploying:

1. **Render has no native managed MySQL** — only PostgreSQL and Redis are
   first-party. This deployment uses **Aiven's free-tier MySQL** (1 CPU /
   1 GB RAM / 1 GB storage, powers off on inactivity — fine for a
   pre-launch/demo instance, not for production load). Set
   `DB_HOST`/`DB_PORT`/`DB_USER`/`DB_PASS`/`DB_NAME` as env vars on the
   Render service. Aiven (and most managed MySQL) enforce TLS — also set
   `DB_SSL_CA` to the path of `database/aiven-ca.pem` (already committed;
   it's a public CA cert, not a secret) so `Database::connection()` opts
   into an encrypted connection. Also set `APP_ENCRYPTION_KEY` (generate
   with the command in "Setup" below) and `APP_URL`.
2. **Run the migrations once** against that database — `database/001`,
   `002`, `003`, `004`, in that order. This Render plan has no shell
   access, so there's no `psql`/`mysql` CLI to run them by hand from the
   dashboard. `public/migrate.php` exists for exactly this: a token-gated
   one-time endpoint (guarded by a `MIGRATE_TOKEN` env var you set only
   for the duration of the migration, then delete) that runs every
   `database/0*.sql` file it finds, in order, or a single file via
   `&only=004` (useful once earlier files are already applied — none of
   these migrations are safe to re-run, see the note further down). With
   `&seed=1&password=...` it also creates a first `clients` row and
   `super_admins` row so there's something to log in with. **Delete
   `MIGRATE_TOKEN` from the Render env right after running it** — without
   that var set, every request to `migrate.php` 403s, which is the safe
   default state for it to sit in
   long-term.

The build failure you'd hit without `render.yaml`/the root `Dockerfile`
("open Dockerfile: no such file or directory") is because Render's Docker
build step looks for `Dockerfile` at the repo root by default — this repo
originally only had one at `docker/php.Dockerfile` (for `docker compose`,
which supports arbitrary paths). Both now point at the same root
`Dockerfile` so there's one source of truth.

### A note on `database/001_platform_schema.sql`'s baseline tables

This migration was originally written assuming `domains`, `email_templates`,
`campaigns`, and `email_logs` already existed from a pre-existing legacy
install, and only added `client_id`/AI columns via `ALTER TABLE`. On a
genuinely fresh database that assumption doesn't hold, so the migration now
also `CREATE TABLE IF NOT EXISTS`s minimal baseline versions of those four
tables first (columns matched to what `src/` actually reads/writes). The
`ALTER TABLE` clauses were also changed from `ADD COLUMN IF NOT EXISTS` /
`ADD INDEX IF NOT EXISTS` to plain `ADD COLUMN` / `ADD INDEX` — MySQL 8.4
(confirmed against live Aiven) rejects the former with a 1064 syntax error
regardless of table state. `ip_pool` was dropped entirely: a full grep
across `src/` and `worker/` found zero live references to it —
`ConnectionRotator.php` uses `sending_connections` instead, so `ip_pool`
was dead legacy naming that never got cleaned up.

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
