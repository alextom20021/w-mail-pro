<?php

declare(strict_types=1);

/**
 * ai_cycle.php
 *
 * PHASE 2 STUB. This is the "AI evaluates reputation and adjusts things
 * every minute" cron entry point the spec calls for (warm-up scheduler,
 * anomaly detector, self-healing). It's wired into docker-compose now so
 * the deploy shape is correct from day one, but the actual per-minute AI
 * logic isn't implemented yet — only the low-latency ConnectionRotator
 * (Phase 1) is live in the send path.
 *
 * What this will do once built:
 *   1. For each active client, pull recent reputation_history +
 *      isp_deliverability_stats.
 *   2. Ask the AIAgent (autonomy-gated per client) to propose warm-up
 *      adjustments and flag anomalies via the adjust_warmup /
 *      quarantine_connection tools already registered in AIToolHandlers.
 *   3. Log every decision to ai_audit_log (already implemented —
 *      AIAuditLogger is ready for this).
 *
 * Until that's built, this script just recovers stale outbox locks so a
 * crashed worker doesn't leave jobs stuck — a real, useful no-op.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use MailAI\Sending\OutboxRepository;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$outbox = new OutboxRepository();
$recovered = $outbox->releaseStaleLocks();

fwrite(STDOUT, date('c') . " ai_cycle: recovered {$recovered} stale job(s). " .
    "Warm-up/anomaly AI logic not yet implemented — see Phase 2 roadmap.\n");
