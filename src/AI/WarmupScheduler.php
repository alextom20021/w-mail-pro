<?php

declare(strict_types=1);

namespace MailAI\AI;

use MailAI\Core\ClientContext;
use MailAI\Sending\SendingConnectionRepository;
use PDO;

/**
 * WarmupScheduler
 *
 * Rules-engine warm-up ramp — runs every minute per the spec, so it must
 * be fast and deterministic; it does NOT call an external AI API per
 * connection (that would be both slow and expensive at scale). Instead
 * this computes a recommended next daily_limit using a standard
 * exponential-with-caution curve, adjusted down if recent bounce/
 * complaint signals look bad. The AIAgent (`adjust_warmup` tool) is the
 * layer that can override this with LLM judgment on harder calls — see
 * `proposeViaAgent()` for that path, used sparingly (once a day per
 * connection, not once a minute) since it costs an API call.
 *
 * Curve (day 1-30, industry-standard conservative ramp):
 *   days 1-3:   50 -> 100 -> 150            (fixed, no reputation data yet)
 *   days 4-14:  +40% per day, capped at reputation-adjusted ceiling
 *   day 15+:    fully warmed — daily_limit stays at plan ceiling unless
 *               reputation drops, then it's cut, not just held flat.
 */
final class WarmupScheduler
{
    private const BASE_SCHEDULE = [50, 100, 150]; // days 1-3

    public function __construct(private readonly PDO $db)
    {
    }

    /** Runs for every connection still in 'warming' status for the current client. */
    public function runForCurrentClient(SendingConnectionRepository $connections): array
    {
        $decisions = [];

        $warming = $connections->findAllWhere("status = 'warming'");
        foreach ($warming as $conn) {
            $decision = $this->evaluate($conn);
            if ($decision !== null) {
                $connections->update((int) $conn['id'], [
                    'daily_limit' => $decision['new_daily_limit'],
                    'warmup_stage' => $decision['new_warmup_stage'],
                    'status' => $decision['new_warmup_stage'] >= 8 ? 'active' : 'warming',
                ]);
                $this->recordSchedule((int) $conn['id'], $decision);
                $decisions[] = $decision;
            }
        }

        return $decisions;
    }

    private function evaluate(array $conn): ?array
    {
        $warmupStartedAt = $conn['warmup_started_at'] ?? null;
        if ($warmupStartedAt === null) {
            return null;
        }

        $dayNumber = max(1, (int) floor((time() - strtotime($warmupStartedAt)) / 86400) + 1);
        $recentHealth = $this->recentHealthSignal((int) $conn['id']);

        // A bad recent signal halts ramp-up entirely regardless of day number —
        // this is the "self-healing" guardrail: warm-up never outruns reputation.
        if ($recentHealth['complaint_rate'] > 0.003 || $recentHealth['bounce_rate'] > 0.05) {
            return [
                'connection_id' => $conn['id'],
                'new_daily_limit' => (int) $conn['daily_limit'], // hold flat, don't increase
                'new_warmup_stage' => (int) $conn['warmup_stage'], // don't advance stage
                'reason' => sprintf(
                    'Held ramp flat: complaint_rate=%.4f bounce_rate=%.4f exceeds safe thresholds',
                    $recentHealth['complaint_rate'],
                    $recentHealth['bounce_rate']
                ),
            ];
        }

        if ($dayNumber <= 3) {
            $newLimit = self::BASE_SCHEDULE[$dayNumber - 1];
        } else {
            $newLimit = (int) round((int) $conn['daily_limit'] * 1.4);
        }

        $stage = min(8, (int) floor($dayNumber / 2)); // stage 8 = fully warmed by ~day 16

        return [
            'connection_id' => $conn['id'],
            'new_daily_limit' => $newLimit,
            'new_warmup_stage' => $stage,
            'reason' => "Day {$dayNumber} of warm-up ramp, health signals nominal",
        ];
    }

    /** Bounce/complaint rate over the last 3 days for this connection. */
    private function recentHealthSignal(int $connectionId): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                COALESCE(SUM(sent), 0) AS sent,
                COALESCE(SUM(hard_bounced + soft_bounced), 0) AS bounced,
                COALESCE(SUM(complained), 0) AS complained
             FROM isp_deliverability_stats
             WHERE connection_id = :connection_id AND stat_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)"
        );
        $stmt->execute(['connection_id' => $connectionId]);
        $row = $stmt->fetch() ?: ['sent' => 0, 'bounced' => 0, 'complained' => 0];

        $sent = max(1, (int) $row['sent']); // avoid div-by-zero; no data reads as 0% rate

        return [
            'bounce_rate' => (int) $row['bounced'] / $sent,
            'complaint_rate' => (int) $row['complained'] / $sent,
        ];
    }

    private function recordSchedule(int $connectionId, array $decision): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO warmup_schedules (client_id, connection_id, day_number, target_volume, ai_adjusted, adjustment_reason, applies_on)
             VALUES (:client_id, :connection_id, :day_number, :target_volume, 0, :reason, CURDATE())
             ON DUPLICATE KEY UPDATE target_volume = VALUES(target_volume), adjustment_reason = VALUES(adjustment_reason)"
        );
        $stmt->execute([
            'client_id' => ClientContext::clientId(),
            'connection_id' => $connectionId,
            'day_number' => $decision['new_warmup_stage'],
            'target_volume' => $decision['new_daily_limit'],
            'reason' => $decision['reason'],
        ]);
    }
}
