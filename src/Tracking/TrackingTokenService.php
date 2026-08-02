<?php

declare(strict_types=1);

namespace MailAI\Tracking;

/**
 * TrackingTokenService
 *
 * Generates and verifies signed tokens embedded in tracking pixel/click
 * URLs. Using an HMAC-signed token (rather than a raw outbox_id) prevents
 * anyone from scraping open/click stats for arbitrary IDs they don't own,
 * and lets the pixel/redirect endpoints stay stateless (no session).
 */
final class TrackingTokenService
{
    public function __construct(private readonly string $secret)
    {
    }

    public function makeToken(int $clientId, int $contactId, int $campaignId, ?int $outboxId = null): string
    {
        $payload = "{$clientId}.{$contactId}.{$campaignId}." . ($outboxId ?? 0);
        $signature = substr(hash_hmac('sha256', $payload, $this->secret), 0, 32);

        return rtrim(strtr(base64_encode("{$payload}.{$signature}"), '+/', '-_'), '=');
    }

    /** @return array{client_id:int, contact_id:int, campaign_id:int, outbox_id:int}|null */
    public function verify(string $token): ?array
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        $parts = explode('.', $decoded);
        if (count($parts) !== 5) {
            return null;
        }

        [$clientId, $contactId, $campaignId, $outboxId, $signature] = $parts;
        $payload = "{$clientId}.{$contactId}.{$campaignId}.{$outboxId}";
        $expected = substr(hash_hmac('sha256', $payload, $this->secret), 0, 32);

        if (!hash_equals($expected, $signature)) {
            return null;
        }

        return [
            'client_id' => (int) $clientId,
            'contact_id' => (int) $contactId,
            'campaign_id' => (int) $campaignId,
            'outbox_id' => (int) $outboxId,
        ];
    }
}
