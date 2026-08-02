<?php

declare(strict_types=1);

namespace MailAI\AI;

use MailAI\Sending\SendingConnectionRepository;
use PDO;

/**
 * AnomalyDetector
 *
 * Compares each connection's last-24h inbox/bounce/complaint signal
 * against its own 14-day trailing baseline and flags statistically
 * meaningful drops — "Gmail suddenly delaying mail" per the spec.
 * Rules-based (fast, runs every minute) rather than LLM-based for the
 * same reason as WarmupScheduler: per-connection-per-minute is too high
 * a volume/cost for an API call. Severe anomalies trigger self-healing
 * (quarantine) directly; borderline ones are logged for the AI chat
 * assistant / dashboard to surface to a human.
 */
final class AnomalyDetector
{
    private const SEVERE_COMPLAINT_RATE = 0.005; // 0.5% — most ESPs consider >0.1-0.3% already risky
    private const SEVERE_BOUNCE_RATE = 0.08;
    private const BASELINE_DROP_THRESHOLD = 0.25; // 25% relative drop in inbox-rate vs. baseline

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array List of anomaly records found (empty if all connections look healthy). */
    public function scanForCurrentClient(SendingConnectionRepository $connections): array
    {
        $anomalies = [];

        foreach ($connections->findAllWhere("status IN ('active','warming')") as $conn) {
            $anomaly = $this->evaluateConnection($conn);
            if ($anomaly !== null) {
                $anomalies[] = $anomaly;

                if ($anomaly['severity'] === 'severe') {
                    $connections->quarantine((int) $conn['id'], $anomaly['summary']);
                    $this->recordReputationDrop((int) $conn['id'], $anomaly);
                }
            }
        }

        return $anomalies;
    }

    private function evaluateConnection(array $conn): ?array
    {
        $today = $this->windowStats((int) $conn['id'], 1);
        $baseline = $this->windowStats((int) $conn['id'], 14);

        if ($today['sent'] < 20) {
            return null; // not enough volume today to draw a conclusion
        }

        $complaintRate = $today['complained'] / max(1, $today['sent']);
        $bounceRate = $today['bounced'] / max(1, $today['sent']);

        if ($complaintRate >= self::SEVERE_COMPLAINT_RATE) {
            return $this->anomaly($conn, 'severe', 'complaint_spike', sprintf(
                'Complaint rate spiked to %.3f%% today (severe threshold %.3f%%) — quarantining and starting recovery warm-up.',
                $complaintRate * 100,
                self::SEVERE_COMPLAINT_RATE * 100
            ));
        }

        if ($bounceRate >= self::SEVERE_BOUNCE_RATE) {
            return $this->anomaly($conn, 'severe', 'bounce_spike', sprintf(
                'Bounce rate spiked to %.2f%% today (severe threshold %.2f%%) — quarantining and starting recovery warm-up.',
                $bounceRate * 100,
                self::SEVERE_BOUNCE_RATE * 100
            ));
        }

        if ($baseline['sent'] >= 100) {
            $baselineInboxRate = $baseline['delivered'] / max(1, $baseline['sent']);
            $todayInboxRate = $today['delivered'] / max(1, $today['sent']);

            if ($baselineInboxRate > 0 && ($baselineInboxRate - $todayInboxRate) / $baselineInboxRate >= self::BASELINE_DROP_THRESHOLD) {
                return $this->anomaly($conn, 'warning', 'inbox_rate_drop', sprintf(
                    'Inbox rate dropped from a %.1f%% 14-day baseline to %.1f%% today — flagged for review, not yet quarantined.',
                    $baselineInboxRate * 100,
                    $todayInboxRate * 100
                ));
            }
        }

        return null;
    }

    private function anomaly(array $conn, string $severity, string $type, string $summary): array
    {
        return [
            'connection_id' => $conn['id'],
            'connection_label' => $conn['label'],
            'severity' => $severity,
            'type' => $type,
            'summary' => $summary,
            'detected_at' => date('c'),
        ];
    }

    private function windowStats(int $connectionId, int $days): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                COALESCE(SUM(sent), 0) AS sent,
                COALESCE(SUM(delivered), 0) AS delivered,
                COALESCE(SUM(hard_bounced + soft_bounced), 0) AS bounced,
                COALESCE(SUM(complained), 0) AS complained
             FROM isp_deliverability_stats
             WHERE connection_id = :connection_id AND stat_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)"
        );
        $stmt->execute(['connection_id' => $connectionId, 'days' => $days]);

        return $stmt->fetch() ?: ['sent' => 0, 'delivered' => 0, 'bounced' => 0, 'complained' => 0];
    }

    private function recordReputationDrop(int $connectionId, array $anomaly): void
    {
        $stmt = $this->db->prepare(
            "UPDATE sending_connections SET reputation_score = GREATEST(0, reputation_score - 25) WHERE id = :id"
        );
        $stmt->execute(['id' => $connectionId]);

        $history = $this->db->prepare(
            "INSERT INTO reputation_history (client_id, connection_id, reputation_score, notes)
             SELECT client_id, id, reputation_score, :notes FROM sending_connections WHERE id = :id"
        );
        $history->execute(['notes' => $anomaly['summary'], 'id' => $connectionId]);
    }
}
