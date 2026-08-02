<?php

declare(strict_types=1);

/**
 * ai_cycle.php
 *
 * The per-minute AI meta-optimization loop (spec: "weighted scoring
 * algorithm updated every minute"). For every active client, runs the
 * rules-engine layers — WarmupScheduler and AnomalyDetector — which are
 * fast/deterministic and safe to run this often. LLM-backed features
 * (content review via chat, list cleaning) are intentionally NOT run
 * here on a fixed schedule — they're triggered on-demand (campaign
 * creation, explicit "clean my list" action) since they cost real API
 * calls and don't need per-minute freshness.
 *
 * Usage: php worker/ai_cycle.php
 * Cron:  * * * * * php /path/to/worker/ai_cycle.php >> /var/log/mailai/ai_cycle.log 2>&1
 */

require_once __DIR__ . '/../vendor/autoload.php';

use MailAI\AI\AnomalyDetector;
use MailAI\AI\WarmupScheduler;
use MailAI\Core\ClientContext;
use MailAI\Core\Database;
use MailAI\Security\EncryptionService;
use MailAI\Sending\OutboxRepository;
use MailAI\Sending\SendingConnectionRepository;
use PDO;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$db = Database::connection();
$encryption = new EncryptionService($_ENV['APP_ENCRYPTION_KEY']);

// Recover any outbox jobs orphaned by a crashed worker — cheap, always useful.
$recovered = (new OutboxRepository())->releaseStaleLocks();

$clientIds = $db->query("SELECT id FROM clients WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);

$summary = ['clients_processed' => 0, 'warmup_decisions' => 0, 'anomalies_found' => 0, 'severe_anomalies' => 0];

foreach ($clientIds as $clientId) {
    try {
        ClientContext::setClient((int) $clientId);

        $connections = new SendingConnectionRepository($encryption);

        $warmupDecisions = (new WarmupScheduler($db))->runForCurrentClient($connections);
        $anomalies = (new AnomalyDetector($db))->scanForCurrentClient($connections);

        $summary['clients_processed']++;
        $summary['warmup_decisions'] += count($warmupDecisions);
        $summary['anomalies_found'] += count($anomalies);
        $summary['severe_anomalies'] += count(array_filter($anomalies, fn($a) => $a['severity'] === 'severe'));

        foreach ($anomalies as $anomaly) {
            fwrite(STDOUT, sprintf(
                "[client %d] %s anomaly on connection %d (%s): %s\n",
                $clientId,
                strtoupper($anomaly['severity']),
                $anomaly['connection_id'],
                $anomaly['type'],
                $anomaly['summary']
            ));
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "[client {$clientId}] ai_cycle error: {$e->getMessage()}\n");
    } finally {
        ClientContext::clear();
    }
}

fwrite(STDOUT, date('c') . ' ai_cycle: ' . json_encode(array_merge($summary, ['stale_locks_recovered' => $recovered])) . "\n");
