<?php

declare(strict_types=1);

namespace MailAI\Api;

use PDO;

/**
 * RateLimiter
 *
 * Fixed-window (per-minute) rate limiting, keyed by client_id, backed by
 * MySQL rather than Redis — see migration 002's note on why. A fixed
 * window has a known edge case (up to 2x the limit across a window
 * boundary); acceptable for Phase 1's abuse-prevention purpose. Move to
 * a sliding-window Redis implementation if precise limiting becomes
 * important at higher volume.
 */
final class RateLimiter
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{allowed:bool, remaining:int, limit:int, reset_at:string} */
    public function check(int $clientId, int $limitPerMinute): array
    {
        $windowStart = date('Y-m-d H:i:00');

        $this->db->prepare(
            "INSERT INTO api_rate_limit_buckets (client_id, window_start, request_count)
             VALUES (:client_id, :window_start, 1)
             ON DUPLICATE KEY UPDATE request_count = request_count + 1"
        )->execute(['client_id' => $clientId, 'window_start' => $windowStart]);

        $stmt = $this->db->prepare(
            "SELECT request_count FROM api_rate_limit_buckets WHERE client_id = :client_id AND window_start = :window_start"
        );
        $stmt->execute(['client_id' => $clientId, 'window_start' => $windowStart]);
        $count = (int) $stmt->fetchColumn();

        return [
            'allowed' => $count <= $limitPerMinute,
            'remaining' => max(0, $limitPerMinute - $count),
            'limit' => $limitPerMinute,
            'reset_at' => date('c', strtotime($windowStart) + 60),
        ];
    }
}
