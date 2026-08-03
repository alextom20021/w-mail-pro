# Spec Progress Tracker

Every bullet from the platform spec, with its real status. Updated with every commit.
✅ = built & live · 🟡 = partially built (notes say what's missing) · ❌ = not built yet

## MAIN GOAL — AI agent does all the work for the client

| Item | Status | Where |
|---|---|---|
| Client co-works with agent via prompts | ✅ | Chat widget on every dashboard page → `AiChatController` |
| Agent asks for IPs/credentials/details conversationally | ✅ | System prompt + tool schemas (`AIToolHandlers`, `AIPlatformTools`) |
| Client confirms, agent works | ✅ | Approve/Reject buttons → `ai_approve.php`; destructive tools always gated |
| Agent can manage the ENTIRE platform (40 tools) | ✅ | Connections, domains/DNS, templates, lists/contacts, suppressions, campaigns (send/schedule/pause/resume/cancel/A-B), analytics, webhooks, warm-up |
| Every AI action audited | ✅ | `ai_audit_log` + admin viewer |

## 1. SUPER ADMIN

### 1.1 Dashboard & Global Health
| Item | Status | Notes |
|---|---|---|
| Real-time platform overview (clients, sends 24h/7d/30d) | ✅ | `admin/index.php` |
| Global inbox placement rate (weighted, all tenants) | ✅ | `admin/index.php` |
| Open anomaly counter + severity indicators | ✅ | OK / ELEVATED / CRITICAL badge |
| Live AI Engine activity feed | ✅ | Cross-tenant, newest 12 |
| System health (Worker, MySQL, queue depth) | 🟡 | Worker liveness, queue depth+age, webhook backlog, MySQL. Redis/CPU/mem not shown (Render free tier exposes no host metrics; Redis not provisioned in prod) |
| Deliverability heatmap by ISP | ✅ | Color-coded 7d table |
| Critical alerts panel + one-click actions | ✅ | Release connection, pause client, inspect |
| Exportable platform health report (CSV/PDF) | ✅ | `admin/health_export.php` |

### 1.2 Client / Tenant Management
| Item | Status | Notes |
|---|---|---|
| Full client CRUD + impersonate | ✅ | `admin/clients.php`, `admin/client_detail.php` |
| Onboarding status tracking | 🟡 | Status field shown; per-step onboarding % not surfaced in admin yet |
| Plan assignment & quota management | ✅ | Plan + daily sends/contacts/connections quotas editable |
| Force password reset / force logout | ✅ | Real session invalidation (`session_version`) |
| Client activity timeline | ✅ | Campaigns + AI actions + admin actions merged |
| Bulk actions | 🟡 | Bulk suspend/activate done; bulk plan-change/export not yet |
| Client notes / internal comments | ✅ | |
| Revenue / usage overview per client | 🟡 | Usage (sends/complaints) in health export; no billing/revenue since no payment system exists |
| Soft-delete + permanent purge with confirmation | ✅ | Purge requires typing client email |

### 1.3 Plans, Billing & Quotas — ❌ mostly not built
Plan definitions exist as enum + per-client quota columns; **enforcement, feature flags per plan, overage alerts/throttling, usage reports, billing** not built yet. (Next priority.)

### 1.4 Infrastructure Oversight
| Item | Status | Notes |
|---|---|---|
| Global view of all connections | 🟡 | Quarantined ones on Global Health; full sortable cross-tenant board not yet |
| Reputation score board | ❌ | |
| Warm-up progress monitor (all connections) | ❌ | Per-client only so far |
| Forced quarantine/release of any connection | ✅ | One-click on Global Health |
| Domain verification status across clients | ❌ | Per-client only so far |
| Override DNS verification / force re-check | ❌ | |

### 1.5 Campaign & List Oversight — ❌ not built (emergency kill-switch, global search)

### 1.6 AI Engine Control
| Item | Status | Notes |
|---|---|---|
| AI Decision Log (full audit viewer) | ✅ | `admin/audit_log.php` |
| Anomaly detection configuration UI | ❌ | Thresholds are code constants |
| Global AI model settings UI | ❌ | Providers seeded via DB rows |
| Feature toggles per plan | ❌ | |
| Token usage / cost tracking | ❌ | |
| Manual AI action triggers | ❌ | |

### 1.7 Security & Compliance
| Item | Status | Notes |
|---|---|---|
| Platform-wide audit log | ✅ | `admin_audit_log` (all admin actions recorded) — viewer UI pending |
| API key management for platform services | ❌ | |
| Credential decryption viewer (master key + 2FA) | ❌ | Deliberately deferred until 2FA exists |
| GDPR / data export for any client | ❌ | |
| Force suppressions across platform | ❌ | |
| Session management / force logout | ✅ | Per client |

### 1.8 System Administration — ❌ mostly not built
Worker status shown on Global Health; settings UI, cache tools, feature flags, maintenance mode, notification centre, changelog display not built.

## 2. CLIENT PORTAL

### 2.1 Dashboard
| Item | Status | Notes |
|---|---|---|
| Personalised overview (sent, open/click/inbox/bounce) | ✅ | Stat cards + charts |
| Real-time connection health cards | ✅ | Connections page + overview counters |
| AI activity feed (client-specific) | ✅ | On overview |
| Quick actions | ✅ | New Campaign / Import List / Add Connection / Ask AI |
| Anomaly & reputation alerts | ✅ | Complaint warnings + quarantine counter |
| Upcoming warm-up milestones | ✅ | On overview |
| Drag-and-drop widget dashboard | ❌ | Spec marks this "later versions" |

### 2.2 Onboarding Wizard
| Item | Status | Notes |
|---|---|---|
| 7-step guided flow (connection → domain → DNS → Cloudflare → warm-up → list → first campaign) | ✅ | All 7 steps on `onboarding.php` |
| Progress bar + resume later | ✅ | % bar; progress derived from real account state so resume is automatic |

### 2.3 Sending Connections Manager
| Item | Status | Notes |
|---|---|---|
| Unified SMTP + API management | ✅ | |
| Add / delete / pause / resume | ✅ | UI + AI tools |
| Daily limit settings | ✅ | Hourly limits ❌ |
| Warm-up status + progress | ✅ | History via AI tool + connections page |
| Reputation score history chart | ❌ | Score stored; chart not built |
| Real-time error log per connection | 🟡 | Errors recorded per outbox row; per-connection viewer not built |
| Priority ordering for rotation | 🟡 | Rotator scores by reputation; manual ordering not built |
| Test connection button | ❌ | |
| Credentials encrypted, never re-shown | ✅ | libsodium |

### 2.4 Domain Management
| Item | Status | Notes |
|---|---|---|
| Add/verify domains, DKIM generation, live SPF/DKIM/DMARC checks | ✅ | |
| Cloudflare auto-apply | ✅ | |
| Tracking domain (CNAME) setup | ❌ | Tracking uses APP_URL |
| Default from name / reply-to per domain | ❌ | Per-connection instead |
| DNS record copy buttons | 🟡 | Records shown; one-click copy JS not added |

### 2.5 Contact Lists & Contacts
| Item | Status | Notes |
|---|---|---|
| Create/delete lists, CSV import | ✅ | Rename ❌; Excel import ❌ (CSV only); field mapping 🟡 (fixed columns) |
| AI list cleaning | ✅ | |
| Contact status management | ✅ | |
| Custom fields (JSON) | ✅ | |
| GeoIP + ISP detection | ✅ | On open/click (import-time ❌) |
| Best-send-time learning per contact | ✅ | Real engagement-hour histogram |
| Bulk actions (move/suppress/export/delete) | 🟡 | Suppress + delete-list; move/export not built |
| Suppression list management | ✅ | UI + AI tools |

### 2.6 Campaign Builder & Management
| Item | Status | Notes |
|---|---|---|
| Quick campaign wizard | ✅ | |
| HTML editor + template library | ✅ | Plain HTML textarea (no WYSIWYG) |
| AI Content Optimizer (spam score + rewrite) | ✅ | Score on save + agent rewrites via update_template |
| A/B testing auto-pilot with winner selection | ✅ | Via AI agent (`send_ab_test_campaign`); dashboard-form A/B setup ❌ |
| Send-time optimisation per contact | 🟡 | Best-hour learned + stored; per-hour queueing bucketing exists but not wired into enqueue |
| Schedule / immediate / pause / resume / cancel | ✅ | UI + AI tools |
| **Real-time sending progress** | ✅ | Live progress bars, 5s polling (`campaign_progress.php`) |
| Campaign analytics drill-down | 🟡 | Per-campaign progress + A/B stats; deeper drill-down page pending |

### 2.7 AI Features (client-facing)
All ✅ except: predictive alerts ("IP will hit limit in 3h") ❌, self-healing push notifications 🟡 (quarantine is automatic + visible, no notification toast), rotation transparency 🟡 (scoring exists, "why this connection" viewer not built).

### 2.8 Analytics & Reporting
| Item | Status | Notes |
|---|---|---|
| Real-time Chart.js dashboard | ✅ | |
| Filters (ISP/country/connection/date) | ✅ | Campaign filter ❌; domain filter ❌ |
| Core metrics | ✅ | Spam placement estimate ❌ (no seed-list infra) |
| Heatmaps (hour/day engagement) | ❌ | Data exists (`contact_engagement_hours`) |
| Time-to-open histograms | ❌ | |
| Per-connection reputation trends | ❌ | |
| Per-ISP comparison | ✅ | |
| Export CSV/PDF | ✅ | |
| Scheduled email reports | ❌ | |

### 2.9 Tracking & Compliance
All ✅ (pixel+click with GeoIP/ISP, List-Unsubscribe, compliant footer, consent capture, suppression, bounce processing) except: FBL ingestion ❌, unsubscribe page customisation ❌.

### 2.10 Security & Account
| Item | Status | Notes |
|---|---|---|
| Profile & password management | ❌ | Page not built (admin can force-reset) |
| API key + scopes | 🟡 | Key auto-generated; scopes column exists, scope-enforcement + self-serve regenerate UI not built |
| Webhook configuration | ✅ | |
| Two-factor authentication | ❌ | |
| Session management | 🟡 | Force-logout works; self-serve session list not built |
| Audit log of own actions | 🟡 | AI actions visible; own-actions page not built |
| GDPR data export | ❌ | |

### 2.11 Support & Help
In-app AI assistant ✅ · knowledge base ❌ · ticket system ❌ · status page ❌

## Cross-Cutting
Multi-tenancy ✅ · queue-based sending ✅ · throttled parallel sending ✅ · exponential backoff ✅ ·
SMTP transcripts ✅ · Redis caching 🟡 (in docker-compose; prod uses in-process memoization — Render free
tier has no Redis) · Docker Compose ✅ · REST API v1 + rate limiting ✅ · webhooks ✅ · libsodium ✅ ·
CSRF/XSS/SQLi protection ✅ · dark/light mode 🟡 (Bootstrap 5 with `data-bs-theme` wired, no toggle button)
