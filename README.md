# MailAI Platform

A multi-tenant, AI-agent-driven email deliverability platform. This repo is
being built in phases — this README always reflects the **actual, honest
state** of what's implemented vs. stubbed, so nothing here overstates what
exists.

## Phase 1 status (current)

### Built and functional
- **Database schema** (`database/001_platform_schema.sql`) — full
  multi-tenant schema: clients, contact lists/contacts, sending_connections
  (unified SMTP+API pool), outbox queue, suppressions/bounces/complaints,
  ISP-level analytics tables, warm-up schedules, and the AI audit log.
  Requires **MySQL 8.0.29+** (uses `ADD COLUMN IF NOT EXISTS` syntax).
- **Encryption** (`src/Security/EncryptionService.php`) — libsodium
  XChaCha20-Poly1305 for all secrets at rest (SMTP passwords, API keys,
  DKIM keys).
- **Multi-tenancy core** (`src/Core/`) — `Database`, `ClientContext`,
  `TenantRepository`. Every tenant-scoped query is structurally forced to
  filter by `client_id` from the authenticated context, never from
  caller-supplied input.
- **AI agent subsystem** (`src/AI/`) — provider-agnostic (OpenAI +
  Anthropic implementations included, `FailoverProvider` for redundancy),
  a tool registry the LLM can call (`adjust_warmup`,
  `quarantine_connection`, `get_deliverability_summary`,
  `create_email_template`), and an `ai_autonomy_level` gate
  (`off` / `suggest_only` / `approve_required` / `full_auto`) so the agent's
  authority to act without a human is an explicit, auditable setting —
  every tool call is logged to `ai_audit_log` regardless of whether it
  executed or was queued for approval.
- **Sending pipeline** — `ConnectionRotator` (weighted scoring: reputation,
  headroom, warm-up stage, recency), `SendingConnectionRepository`,
  `OutboxRepository` (atomic job claiming via `FOR UPDATE SKIP LOCKED`,
  exponential-backoff retries), `MailDispatcher` (SMTP via PHPMailer —
  **fully working**), and `worker/worker.php`, a cron-driven queue
  processor that re-checks suppression at send time (not enqueue time)
  and enforces the compliance footer + `List-Unsubscribe` header on every
  send, unconditionally.
- **Docker Compose** — web/worker/cron/mysql/redis services.

### Explicitly stubbed / not yet built (Phase 2+)
- **API-provider sending** (SendGrid, Mailgun, SES, Postmark) —
  `MailDispatcher::sendViaApi()` throws a clear "not implemented" error.
  SMTP is fully wired so the queue/rotation/compliance pipeline is
  testable end-to-end today.
- **AI warm-up scheduler / anomaly detector / content scorer / send-time
  optimizer / list cleaner** — the `AIAgent` orchestrator and tool
  registry are real and working, but only a handful of tools are
  registered so far. `worker/ai_cycle.php` (the per-minute AI loop the
  spec calls for) is a stub that only recovers stale locks right now.
- **DKIM signing, click/open tracking pixel, GeoIP/ISP enrichment,
  analytics dashboard API, REST API v1, client portal UI, super-admin UI,
  onboarding wizard** — none of these exist yet.
- `email_logs` schema assumptions — the worker writes to `email_logs`
  assuming columns added by this migration; verify against your actual
  pre-existing table before running in anything but a fresh database.

## Setup (local dev)

```bash
cp .env.example .env
# generate an encryption key and put it in .env:
php -r "require 'vendor/autoload.php'; echo MailAI\Security\EncryptionService::generateKey(), PHP_EOL;"

composer install
docker compose up -d
docker compose exec web php -r "require 'vendor/autoload.php';" # sanity check
```

The `mysql` service auto-runs everything in `database/` on first boot via
`docker-entrypoint-initdb.d`. To seed an AI provider, encrypt an API key
with `EncryptionService` and insert it into `ai_providers` — see
`src/AI/AIProviderFactory.php` for the expected row shape.

## Architecture notes worth knowing before extending this

- **Tenant isolation is structural, not convention.** Don't bypass
  `TenantRepository` for client-facing code paths — it's the only thing
  guaranteeing one client can't read another's rows.
- **The AI agent can only do what's in `AIToolRegistry`.** It has no raw
  DB or shell access. Adding a new AI capability means writing a new
  tool handler in `AIToolHandlers`, not giving the model broader access.
- **`ai_autonomy_level` is per-client and enforced in `AIAgent`, not the
  UI.** Even a `full_auto` client still routes destructive tools
  (`delete_contact_list`, `disable_connection`, `send_campaign_now`)
  through human approval — see `AIAgent::DESTRUCTIVE_TOOLS`.
- **`worker.php` is stateless per job.** `ClientContext` is cleared after
  every job specifically because this is a long-running, multi-tenant
  process — don't remove that `finally` block.

## Roadmap (planned order)

1. API-provider sending clients (SendGrid/Mailgun/SES/Postmark) to
   complete the unified rotation pool.
2. AI warm-up scheduler + anomaly detector wired into `ai_cycle.php`.
3. DKIM signing + tracking pixel/click redirector with GeoIP/ISP
   enrichment.
4. REST API v1 (Bearer auth, rate limiting).
5. Client dashboard + AI chat panel; super-admin panel.
6. Content optimizer, send-time optimizer, A/B auto-pilot, AI list
   cleaning.
