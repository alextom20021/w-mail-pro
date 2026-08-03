<?php

declare(strict_types=1);

namespace MailAI\Tracking;

use MailAI\AI\SendTimeOptimizer;
use MailAI\Sending\ConnectionRotator;
use MailAI\Webhooks\WebhookDispatcher;
use PDO;

/**
 * TrackingEventRecorder
 *
 * Writes an open/click event into isp_deliverability_stats (the daily
 * aggregate the analytics dashboard reads from) and updates the
 * contact's engagement_score + last_engaged_at, which is what
 * downstream AI features (send-time optimizer, warm-up scheduler) key
 * off of. One upsert per event — cheap enough to run inline in the
 * tracking endpoint rather than queueing it.
 */
final class TrackingEventRecorder
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function recordOpen(int $clientId, int $contactId, int $campaignId, ?string $ip, ?string $country, ?int $outboxId = null): void
    {
        $isp = $this->ispForContact($clientId, $contactId);
        $this->upsertStat($clientId, $campaignId, $isp, $country, 'opened');
        $this->bumpEngagement($clientId, $contactId, 1.0);
        $this->bumpVariantStat($clientId, $outboxId, 'opened_count');
        (new WebhookDispatcher($this->db))->dispatch($clientId, 'open', [
            'campaign_id' => $campaignId, 'contact_id' => $contactId, 'country' => $country,
        ]);
    }

    public function recordClick(int $clientId, int $contactId, int $campaignId, ?string $ip, ?string $country, ?int $outboxId = null): void
    {
        $isp = $this->ispForContact($clientId, $contactId);
        $this->upsertStat($clientId, $campaignId, $isp, $country, 'clicked');
        $this->bumpEngagement($clientId, $contactId, 2.5); // clicks weigh more than opens
        $this->bumpVariantStat($clientId, $outboxId, 'clicked_count');
        (new WebhookDispatcher($this->db))->dispatch($clientId, 'click', [
            'campaign_id' => $campaignId, 'contact_id' => $contactId, 'country' => $country,
        ]);
    }

    /** A/B testing: attribute this open/click back to the variant that was actually sent, via the outbox row. */
    private function bumpVariantStat(int $clientId, ?int $outboxId, string $column): void
    {
        if ($outboxId === null || $outboxId === 0) {
            return;
        }

        $stmt = $this->db->prepare(
            "UPDATE campaign_variants cv
             JOIN outbox o ON o.variant_id = cv.id
             SET cv.{$column} = cv.{$column} + 1
             WHERE o.id = :outbox_id AND o.client_id = :client_id AND cv.client_id = :client_id2"
        );
        $stmt->execute(['outbox_id' => $outboxId, 'client_id' => $clientId, 'client_id2' => $clientId]);
    }

    private function ispForContact(int $clientId, int $contactId): string
    {
        $stmt = $this->db->prepare('SELECT email FROM contacts WHERE id = :id AND client_id = :client_id');
        $stmt->execute(['id' => $contactId, 'client_id' => $clientId]);
        $email = $stmt->fetchColumn();

        return $email ? ConnectionRotator::ispFromEmail($email) : 'unknown';
    }

    private function upsertStat(int $clientId, int $campaignId, string $isp, ?string $country, string $column): void
    {
        // isp_deliverability_stats is keyed by (client_id, connection_id, stat_date,
        // isp, country) — opens/clicks aren't per-connection, they're per-
        // campaign/recipient. IMPORTANT: we use connection_id = 0 as a "not
        // applicable" sentinel rather than NULL, because MySQL unique indexes
        // treat every NULL as distinct from every other NULL — an upsert
        // keyed partly on NULL would silently insert a new row per event
        // instead of aggregating. 0 is safe here: this column has no FK
        // constraint on isp_deliverability_stats (unlike reputation_history).
        $stmt = $this->db->prepare(
            "INSERT INTO isp_deliverability_stats (client_id, connection_id, stat_date, isp, country, {$column})
             VALUES (:client_id, 0, CURDATE(), :isp, :country, 1)
             ON DUPLICATE KEY UPDATE {$column} = {$column} + 1"
        );
        $stmt->execute(['client_id' => $clientId, 'isp' => $isp, 'country' => $country]);
    }

    private function bumpEngagement(int $clientId, int $contactId, float $weight): void
    {
        $stmt = $this->db->prepare(
            "UPDATE contacts
             SET engagement_score = LEAST(100, engagement_score + :weight),
                 last_engaged_at = NOW()
             WHERE id = :id AND client_id = :client_id"
        );
        $stmt->execute(['weight' => $weight, 'id' => $contactId, 'client_id' => $clientId]);

        // Real send-time optimization: bump this hour's bucket in the
        // contact's engagement histogram and recompute best_send_hour_local
        // as the mode across all buckets, not just "whatever hour it is
        // right now" (the old Phase 1 placeholder this replaced).
        (new SendTimeOptimizer($this->db))->recordEngagementHour($clientId, $contactId);
    }
}
