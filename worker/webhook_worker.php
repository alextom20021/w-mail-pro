<?php

declare(strict_types=1);

/**
 * worker/webhook_worker.php
 *
 * Drains webhook_deliveries (queued by WebhookDispatcher from
 * worker/worker.php and TrackingEventRecorder) and POSTs each payload to
 * the client's configured URL, HMAC-signed with their per-webhook
 * secret so they can verify the request actually came from this
 * platform. Exponential backoff on failure (1m, 5m, 30m, 2h, 6h — 5
 * attempts total, matching the outbox's own retry philosophy), then
 * gives up and leaves the row as a permanent record (delivered_at stays
 * NULL, attempt_count maxed).
 *
 * Usage: php worker/webhook_worker.php [batch_size=50]
 * Cron:  * * * * * php /path/to/worker/webhook_worker.php >> /var/log/mailai/webhook_worker.log 2>&1
 */

require_once __DIR__ . '/../vendor/autoload.php';

use MailAI\Core\Database;
use MailAI\Security\EncryptionService;
use MailAI\Sending\Api\HttpClient;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$batchSize = isset($argv[1]) ? (int) $argv[1] : 50;

$db = Database::connection();
$encryption = new EncryptionService($_ENV['APP_ENCRYPTION_KEY']);

const MAX_ATTEMPTS = 5;
const BACKOFF_SECONDS = [60, 300, 1800, 7200, 21600]; // 1m, 5m, 30m, 2h, 6h

$stmt = $db->prepare(
    "SELECT wd.*, w.url, w.secret_encrypted
     FROM webhook_deliveries wd
     JOIN webhooks w ON w.id = wd.webhook_id
     WHERE wd.delivered_at IS NULL AND wd.attempt_count < :max_attempts
       AND wd.next_attempt_at <= NOW() AND w.is_active = 1
     ORDER BY wd.next_attempt_at ASC
     LIMIT :limit"
);
$stmt->bindValue('max_attempts', MAX_ATTEMPTS, PDO::PARAM_INT);
$stmt->bindValue('limit', $batchSize, PDO::PARAM_INT);
$stmt->execute();
$deliveries = $stmt->fetchAll();

$sent = 0;
$failed = 0;
$gaveUp = 0;

foreach ($deliveries as $delivery) {
    $secret = $encryption->decrypt($delivery['secret_encrypted']);
    $payload = $delivery['payload']; // already a JSON string from WebhookDispatcher
    $signature = hash_hmac('sha256', $payload, $secret);

    $response = HttpClient::request('POST', $delivery['url'], [
        'Content-Type: application/json',
        'X-MailAI-Signature: sha256=' . $signature,
        'X-MailAI-Event: ' . $delivery['event_type'],
    ], $payload, 10);

    $attempt = (int) $delivery['attempt_count'] + 1;
    $success = $response['status'] >= 200 && $response['status'] < 300;

    if ($success) {
        $db->prepare('UPDATE webhook_deliveries SET delivered_at = NOW(), response_status = :status, attempt_count = :attempt WHERE id = :id')
            ->execute(['status' => $response['status'], 'attempt' => $attempt, 'id' => $delivery['id']]);
        $sent++;
        continue;
    }

    if ($attempt >= MAX_ATTEMPTS) {
        // Give up — leave delivered_at NULL as the "never succeeded" marker,
        // but stop retrying (next_attempt_at NULL excludes it from the
        // WHERE clause above since attempt_count is now >= MAX_ATTEMPTS).
        $db->prepare('UPDATE webhook_deliveries SET response_status = :status, attempt_count = :attempt, next_attempt_at = NULL WHERE id = :id')
            ->execute(['status' => $response['status'], 'attempt' => $attempt, 'id' => $delivery['id']]);
        $gaveUp++;
        continue;
    }

    $delaySeconds = BACKOFF_SECONDS[$attempt - 1] ?? end(BACKOFF_SECONDS);
    $db->prepare('UPDATE webhook_deliveries SET response_status = :status, attempt_count = :attempt, next_attempt_at = DATE_ADD(NOW(), INTERVAL :delay SECOND) WHERE id = :id')
        ->execute(['status' => $response['status'], 'attempt' => $attempt, 'delay' => $delaySeconds, 'id' => $delivery['id']]);
    $failed++;
}

fwrite(STDOUT, date('c') . ' webhook_worker: ' . json_encode([
    'checked' => count($deliveries), 'delivered' => $sent, 'retrying' => $failed, 'gave_up' => $gaveUp,
]) . "\n");
