<?php

declare(strict_types=1);

/**
 * public/dashboard/campaign_progress.php
 *
 * JSON endpoint polled by the Campaigns page every few seconds to render
 * REAL-TIME SENDING PROGRESS (spec 2.6): per-campaign outbox counts by
 * status plus open/click totals. Session-authed and tenant-scoped like
 * every other dashboard endpoint — a client only ever sees their own
 * campaigns' numbers.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Core\ClientContext;
use MailAI\Core\Database;
use MailAI\Core\SessionAuth;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

SessionAuth::requireClient();

header('Content-Type: application/json');

$db = Database::connection();
$clientId = ClientContext::clientId();

$stmt = $db->prepare(
    "SELECT c.id, c.status AS campaign_status,
            COUNT(o.id) AS total,
            SUM(o.status = 'queued') AS queued,
            SUM(o.status = 'paused') AS paused,
            SUM(o.status IN ('locked','sending')) AS sending,
            SUM(o.status = 'sent') AS sent,
            SUM(o.status IN ('failed','hard_bounced')) AS failed,
            SUM(o.status = 'soft_bounced') AS soft_bounced,
            SUM(o.status = 'cancelled') AS cancelled
     FROM campaigns c
     LEFT JOIN outbox o ON o.campaign_id = c.id AND o.client_id = c.client_id
     WHERE c.client_id = :client_id
     GROUP BY c.id"
);
$stmt->execute(['client_id' => $clientId]);

$campaigns = [];
foreach ($stmt->fetchAll() as $row) {
    $total = (int) $row['total'];
    $sent = (int) $row['sent'];
    $campaigns[(int) $row['id']] = [
        'campaign_status' => $row['campaign_status'],
        'total' => $total,
        'queued' => (int) $row['queued'],
        'paused' => (int) $row['paused'],
        'sending' => (int) $row['sending'],
        'sent' => $sent,
        'failed' => (int) $row['failed'] + (int) $row['soft_bounced'],
        'cancelled' => (int) $row['cancelled'],
        'pct_sent' => $total > 0 ? (int) round($sent / $total * 100) : 0,
    ];
}

echo json_encode(['campaigns' => $campaigns, 'ts' => date('H:i:s')]);
