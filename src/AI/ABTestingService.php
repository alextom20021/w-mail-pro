<?php

declare(strict_types=1);

namespace MailAI\AI;

use PDO;

/**
 * ABTestingService
 *
 * "Auto-pilot" winner selection for A/B campaigns. This project sends
 * the FULL list split across variants up front (see
 * CampaignQueueingService::enqueueAbTest), rather than a small test
 * batch + rollout-winner-to-the-remainder — that keeps the queueing
 * model simple (one enqueue pass, no "resume sending the rest") at the
 * cost of not saving send volume on the losing variant. Given that,
 * "automatic winner selection" here means: once a campaign's variants
 * have all actually been sent (no more queued/sending outbox rows for
 * that campaign) and have enough combined sample size to be meaningful,
 * pick the variant with the best open rate (falling back to click rate
 * if every variant has zero opens) and mark it the winner — useful for
 * reporting and for future campaigns' AI-driven template selection.
 *
 * Run once a minute from worker/ai_cycle.php, same cadence as
 * WarmupScheduler/AnomalyDetector — cheap SQL-only checks, no LLM calls.
 */
final class ABTestingService
{
    private const MIN_SAMPLE_SIZE = 20; // combined sends across all variants before a winner is trustworthy

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<int, array{campaign_id:int, winner_variant_id:int, winner_label:string}> Decisions made this run. */
    public function selectWinnersForCurrentClient(int $clientId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM campaigns
             WHERE client_id = :client_id AND ab_test_enabled = 1 AND ab_winner_variant_id IS NULL"
        );
        $stmt->execute(['client_id' => $clientId]);
        $campaignIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $decisions = [];
        foreach ($campaignIds as $campaignId) {
            $decision = $this->tryDecide($clientId, (int) $campaignId);
            if ($decision !== null) {
                $decisions[] = $decision;
            }
        }

        return $decisions;
    }

    private function tryDecide(int $clientId, int $campaignId): ?array
    {
        // Not done sending yet — don't call a winner on partial data.
        $pending = $this->db->prepare(
            "SELECT COUNT(*) FROM outbox
             WHERE client_id = :client_id AND campaign_id = :campaign_id AND status IN ('queued','locked','sending')"
        );
        $pending->execute(['client_id' => $clientId, 'campaign_id' => $campaignId]);
        if ((int) $pending->fetchColumn() > 0) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT id, variant_label, sent_count, opened_count, clicked_count
             FROM campaign_variants WHERE client_id = :client_id AND campaign_id = :campaign_id'
        );
        $stmt->execute(['client_id' => $clientId, 'campaign_id' => $campaignId]);
        $variants = $stmt->fetchAll();

        if (count($variants) < 2) {
            return null; // nothing to compare
        }

        $totalSent = array_sum(array_column($variants, 'sent_count'));
        if ($totalSent < self::MIN_SAMPLE_SIZE) {
            return null;
        }

        $totalOpens = array_sum(array_column($variants, 'opened_count'));
        $rankBy = $totalOpens > 0 ? 'opened_count' : 'clicked_count';

        usort($variants, function ($a, $b) use ($rankBy) {
            $rateA = $a['sent_count'] > 0 ? $a[$rankBy] / $a['sent_count'] : 0;
            $rateB = $b['sent_count'] > 0 ? $b[$rankBy] / $b['sent_count'] : 0;

            return $rateB <=> $rateA;
        });

        $winner = $variants[0];

        $this->db->prepare('UPDATE campaign_variants SET is_winner = 0 WHERE client_id = :client_id AND campaign_id = :campaign_id')
            ->execute(['client_id' => $clientId, 'campaign_id' => $campaignId]);
        $this->db->prepare('UPDATE campaign_variants SET is_winner = 1 WHERE id = :id AND client_id = :client_id')
            ->execute(['id' => $winner['id'], 'client_id' => $clientId]);
        $this->db->prepare('UPDATE campaigns SET ab_winner_variant_id = :variant_id WHERE id = :id AND client_id = :client_id')
            ->execute(['variant_id' => $winner['id'], 'id' => $campaignId, 'client_id' => $clientId]);

        return [
            'campaign_id' => $campaignId,
            'winner_variant_id' => (int) $winner['id'],
            'winner_label' => $winner['variant_label'],
        ];
    }
}
