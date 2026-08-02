<?php

declare(strict_types=1);

/**
 * worker.php
 *
 * Cron-driven outbox processor. Run this every minute (or every few
 * seconds via a supervisor loop — see docker-compose's worker service)
 * against a MySQL-backed queue. It:
 *
 *   1. Claims a batch of due 'queued' jobs (cross-tenant, one process).
 *   2. For each job: sets ClientContext, re-checks suppression (a contact
 *      may have unsubscribed AFTER the job was enqueued — never trust a
 *      stale snapshot for a compliance-critical check).
 *   3. Uses ConnectionRotator to pick the best sending_connections row
 *      for that client + recipient ISP.
 *   4. Dispatches via MailDispatcher, records the result, updates
 *      per-connection sent_today and reputation signals.
 *   5. Clears ClientContext before moving to the next job — see the
 *      Phase 1 self-review note on why this matters in a multi-tenant
 *      long-running process.
 *
 * Usage: php worker/worker.php [batch_size]
 * Cron:  * * * * * php /path/to/worker/worker.php >> /var/log/mailai/worker.log 2>&1
 */

require_once __DIR__ . '/../vendor/autoload.php';

use MailAI\Core\ClientContext;
use MailAI\Core\Database;
use MailAI\Sending\ConnectionRotator;
use MailAI\Sending\MailDispatcher;
use MailAI\Sending\OutboxRepository;
use MailAI\Sending\SendingConnectionRepository;
use MailAI\Security\EncryptionService;
use MailAI\Tracking\LinkRewriter;
use MailAI\Tracking\TrackingTokenService;

// --- Bootstrap -------------------------------------------------------
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$workerId = gethostname() . ':' . getmypid();
$batchSize = isset($argv[1]) ? (int) $argv[1] : 20;

$encryption = new EncryptionService($_ENV['APP_ENCRYPTION_KEY']);
$outbox = new OutboxRepository();
$rotator = new ConnectionRotator();
$dispatcher = new MailDispatcher();

// Recover jobs orphaned by a worker that crashed mid-batch before touching anything else.
$recovered = $outbox->releaseStaleLocks();
if ($recovered > 0) {
    fwrite(STDOUT, "[{$workerId}] Recovered {$recovered} stale-locked job(s)\n");
}

$jobs = $outbox->claimBatch($workerId, $batchSize);
fwrite(STDOUT, "[{$workerId}] Claimed " . count($jobs) . " job(s)\n");

foreach ($jobs as $job) {
    try {
        processJob($job, $outbox, $rotator, $dispatcher, $encryption);
    } catch (\Throwable $e) {
        // Never let one bad job kill the whole batch.
        fwrite(STDERR, "[{$workerId}] Job {$job['id']} threw: {$e->getMessage()}\n");
        $outbox->markFailed((int) $job['id'], $e->getMessage(), isHardFailure: false);
    } finally {
        ClientContext::clear();
    }
}

function processJob(
    array $job,
    OutboxRepository $outbox,
    ConnectionRotator $rotator,
    MailDispatcher $dispatcher,
    EncryptionService $encryption
): void {
    $db = Database::connection();
    ClientContext::setClient((int) $job['client_id']);

    // Compliance gate: re-check suppression at send time, not enqueue time.
    // A contact may have unsubscribed or bounced in the minutes since this
    // job was queued — sending anyway is exactly the kind of gap that gets
    // a platform blacklisted, so this is not a client-configurable toggle.
    $suppressed = $db->prepare(
        "SELECT 1 FROM suppressions WHERE client_id = :client_id AND email = :email LIMIT 1"
    );
    $suppressed->execute(['client_id' => $job['client_id'], 'email' => $job['to_email']]);
    if ($suppressed->fetchColumn()) {
        $outbox->markFailed((int) $job['id'], 'Recipient is on the suppression list', isHardFailure: true);
        return;
    }

    $connectionRepo = new SendingConnectionRepository($encryption);
    $eligible = $connectionRepo->findEligible();

    if (empty($eligible)) {
        // No connection available right now (all at daily cap or quarantined) — retry later.
        $outbox->markFailed((int) $job['id'], 'No eligible sending connection available', isHardFailure: false);
        return;
    }

    $isp = ConnectionRotator::ispFromEmail($job['to_email']);
    $best = $rotator->pickBest($eligible, $isp);

    if ($best === null) {
        $outbox->markFailed((int) $job['id'], 'Rotator returned no connection', isHardFailure: false);
        return;
    }

    $outbox->markSending((int) $job['id'], (int) $best['id']);

    $credentials = $connectionRepo->getDecryptedCredentials((int) $best['id']);
    $best['credentials'] = $credentials;

    // Rewrite links + inject the open pixel BEFORE the mandatory compliance
    // footer is appended by MailDispatcher, so tracking never touches the
    // unsubscribe link itself (LinkRewriter explicitly skips it).
    $tokens = new TrackingTokenService($_ENV['APP_ENCRYPTION_KEY'] ?? '');
    $linkRewriter = new LinkRewriter($tokens, rtrim($_ENV['APP_URL'] ?? 'https://app.example.com', '/'));
    $job['html_body'] = $linkRewriter->rewrite(
        $job['html_body'],
        (int) $job['client_id'],
        (int) $job['contact_id'],
        (int) ($job['campaign_id'] ?? 0),
        (int) $job['id']
    );

    $unsubscribeUrl = buildUnsubscribeUrl((int) $job['client_id'], (int) $job['contact_id']);
    $result = $dispatcher->send($best, $job, $unsubscribeUrl);

    if ($result['success']) {
        $outbox->markSent((int) $job['id'], $result['transcript']);
        $connectionRepo->incrementSentToday((int) $best['id']);
        logEmailEvent($db, $job, $best, $isp, 'sent');
    } else {
        $outbox->markFailed((int) $job['id'], $result['error'] ?? 'Unknown send error', $result['is_hard_failure']);
        if ($result['is_hard_failure']) {
            recordBounce($db, $job, $best, 'hard', $result['error'] ?? '');
        }
        logEmailEvent($db, $job, $best, $isp, $result['is_hard_failure'] ? 'hard_bounced' : 'soft_bounced');
    }
}

function buildUnsubscribeUrl(int $clientId, int $contactId): string
{
    $base = $_ENV['APP_URL'] ?? 'https://app.example.com';
    $token = hash_hmac('sha256', "{$clientId}:{$contactId}", $_ENV['APP_ENCRYPTION_KEY'] ?? '');

    return "{$base}/unsubscribe?c={$contactId}&t={$token}";
}

function logEmailEvent(\PDO $db, array $job, array $connection, string $isp, string $status): void
{
    $stmt = $db->prepare(
        "INSERT INTO email_logs (client_id, connection_id, isp, campaign_id, created_at)
         VALUES (:client_id, :connection_id, :isp, :campaign_id, NOW())"
    );
    $stmt->execute([
        'client_id' => $job['client_id'],
        'connection_id' => $connection['id'],
        'isp' => $isp,
        'campaign_id' => $job['campaign_id'],
    ]);
    // Note: assumes email_logs has these columns per the Phase 1 migration's
    // ALTER TABLE additions; adjust if the original table's column names differ.
    unset($status); // reserved for a future status column in a follow-up migration
}

function recordBounce(\PDO $db, array $job, array $connection, string $type, string $diagnostic): void
{
    $stmt = $db->prepare(
        "INSERT INTO bounces (client_id, outbox_id, connection_id, email, bounce_type, diagnostic_message)
         VALUES (:client_id, :outbox_id, :connection_id, :email, :type, :diagnostic)"
    );
    $stmt->execute([
        'client_id' => $job['client_id'],
        'outbox_id' => $job['id'],
        'connection_id' => $connection['id'],
        'email' => $job['to_email'],
        'type' => $type,
        'diagnostic' => $diagnostic,
    ]);
}
