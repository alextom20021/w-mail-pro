<?php

declare(strict_types=1);

/**
 * public/index.php
 *
 * PHASE 2 STUB. There is no router, no admin UI, and no client dashboard
 * yet — those are explicitly out of scope for this Phase 1 delivery
 * (schema, encryption, multi-tenancy core, AI agent, sending/queue
 * pipeline). This file exists only so `docker compose up` has something
 * to serve and you can confirm the container boots and can reach MySQL.
 *
 * See README.md's "What's built vs. what's next" section for the honest
 * status of every piece.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use MailAI\Core\Database;

header('Content-Type: application/json');

try {
    Database::connection()->query('SELECT 1');
    $dbStatus = 'connected';
} catch (\Throwable $e) {
    $dbStatus = 'unreachable: ' . $e->getMessage();
}

echo json_encode([
    'platform' => 'MailAI Platform',
    'phase' => 1,
    'status' => 'scaffold — no UI yet, see README',
    'database' => $dbStatus,
]);
