<?php

declare(strict_types=1);

namespace MailAI\Sending;

/**
 * ConnectionRotator
 *
 * Weighted scoring rotation across a client's sending_connections pool.
 * This is the LOW-LATENCY path — it runs once per outbox row picked up
 * by the worker, so it must NOT call an external AI API. Instead it uses
 * pre-computed signals (reputation_score, warmup_stage, recent bounce/
 * complaint rates from isp_deliverability_stats) that the AI meta-layer
 * (AIWarmupScheduler, AIAnomalyDetector) updates on a slower cadence
 * (every minute via cron, not per-email).
 *
 * Score formula (0-100, higher wins):
 *   reputation_score            weight 0.45
 *   headroom (limit - sent_today, normalized) weight 0.25
 *   warmup_stage penalty (new connections throttled) weight 0.15
 *   recency spread (avoid hammering the same connection back-to-back) weight 0.15
 */
final class ConnectionRotator
{
    /**
     * @param array $connections Rows from SendingConnectionRepository::findEligible()
     * @param string|null $isp   Recipient's mailbox provider, if known (gmail.com -> "gmail")
     * @param array $ispStats    Optional map of connection_id => ['inbox_estimate_pct' => float] for this ISP
     */
    public function pickBest(array $connections, ?string $isp = null, array $ispStats = []): ?array
    {
        if (empty($connections)) {
            return null;
        }

        $scored = array_map(function (array $conn) use ($isp, $ispStats) {
            $conn['_score'] = $this->score($conn, $isp, $ispStats[$conn['id']] ?? null);
            return $conn;
        }, $connections);

        usort($scored, fn($a, $b) => $b['_score'] <=> $a['_score']);

        return $scored[0];
    }

    private function score(array $conn, ?string $isp, ?array ispStat): float
    {
        $reputation = (float) $conn['reputation_score']; // 0-100

        $dailyLimit = max(1, (int) $conn['daily_limit']);
        $sentToday = (int) $conn['sent_today'];
        $headroomPct = max(0.0, 1 - ($sentToday / $dailyLimit)) * 100;

        // Warmup stage 0 = brand new (max caution), higher stages = more trusted.
        $warmupStage = (int) $conn['warmup_stage'];
        $warmupScore = min(100, $warmupStage * 12.5); // stage 8 = fully warmed = 100

        // Recency: connections idle longer get a slight boost to spread load evenly.
        $lastUsedAt = $conn['last_used_at'] ?? null;
        $idleMinutes = $lastUsedAt ? (time() - strtotime($lastUsedAt)) / 60 : 999;
        $recencyScore = min(100, $idleMinutes * 2);

        // If we have ISP-specific inbox placement stats for this connection, blend them in
        // and lean harder on reputation for that ISP (Gmail vs Outlook can diverge sharply).
        $ispAdjustment = 0.0;
        if ($isp !== null && $ispStat !== null && isset($ispStat['inbox_estimate_pct'])) {
            $ispAdjustment = ((float) $ispStat['inbox_estimate_pct'] - 90.0) * 0.5; // centered around a 90% baseline
        }

        $base = ($reputation * 0.45) + ($headroomPct * 0.25) + ($warmupScore * 0.15) + ($recencyScore * 0.15);

        return max(0.0, min(100.0, $base + $ispAdjustment));
    }

    /** Extracts a lowercase ISP label from a recipient address for scoring/analytics. */
    public static function ispFromEmail(string $email): string
    {
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));

        return match (true) {
            str_contains($domain, 'gmail.com') || str_contains($domain, 'googlemail.com') => 'gmail',
            str_contains($domain, 'outlook.') || str_contains($domain, 'hotmail.') || str_contains($domain, 'live.com') => 'outlook',
            str_contains($domain, 'yahoo.') => 'yahoo',
            str_contains($domain, 'icloud.com') || str_contains($domain, 'me.com') => 'apple',
            $domain === '' => 'unknown',
            default => 'other',
        };
    }
}
