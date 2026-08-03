<?php

declare(strict_types=1);

namespace MailAI\Security;

use MailAI\Sending\Api\HttpClient;
use RuntimeException;

/**
 * CloudflareDnsService
 *
 * Optional automated-DNS-record-application integration (README roadmap
 * item: "Automated DNS record application (Cloudflare API etc.)").
 * DomainVerificationService still does the checking; this is purely the
 * "publish it for them" half, gated on the client having linked a
 * Cloudflare zone + scoped API token for that specific domain (stored
 * encrypted on the `domains` row — see 004_platform_extensions.sql).
 * Not automatic for every domain: a client who doesn't use Cloudflare,
 * or doesn't want to grant DNS write access, keeps the manual "copy
 * these records" flow DomainVerificationService already provides.
 */
final class CloudflareDnsService
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(private readonly string $apiToken, private readonly string $zoneId)
    {
    }

    /** Verifies the token can actually read this zone before we trust it for writes. */
    public function verifyAccess(): bool
    {
        $response = $this->request('GET', "/zones/{$this->zoneId}");
        return $response['status'] === 200 && ($response['json']['success'] ?? false) === true;
    }

    /**
     * Publishes (creates or updates) a TXT record. Cloudflare has no
     * native "upsert" — this looks up an existing record with the same
     * name+type first and PATCHes it if found, otherwise POSTs a new one,
     * so re-running this for the same record (e.g. after a DKIM key
     * rotation) doesn't create duplicates.
     */
    public function upsertTxtRecord(string $name, string $content, int $ttl = 3600): array
    {
        $existing = $this->findRecord($name, 'TXT');

        $body = json_encode(['type' => 'TXT', 'name' => $name, 'content' => $content, 'ttl' => $ttl]);

        if ($existing !== null) {
            $response = $this->request('PATCH', "/zones/{$this->zoneId}/dns_records/{$existing['id']}", $body);
        } else {
            $response = $this->request('POST', "/zones/{$this->zoneId}/dns_records", $body);
        }

        if (!($response['json']['success'] ?? false)) {
            $errors = array_map(fn($e) => $e['message'] ?? 'unknown error', $response['json']['errors'] ?? []);
            throw new RuntimeException('Cloudflare API error: ' . implode('; ', $errors ?: ['unknown']));
        }

        return $response['json']['result'];
    }

    /** Applies the standard SPF/DKIM/DMARC record set DomainVerificationService recommends, in one call. */
    public function applyRecommendedRecords(string $domain, string $dkimSelector, string $dkimDnsValue, string $spfValue, string $dmarcValue): array
    {
        $results = [];
        $results['spf'] = $this->upsertTxtRecord($domain, $spfValue);
        $results['dkim'] = $this->upsertTxtRecord("{$dkimSelector}._domainkey.{$domain}", $dkimDnsValue);
        $results['dmarc'] = $this->upsertTxtRecord("_dmarc.{$domain}", $dmarcValue);

        return $results;
    }

    private function findRecord(string $name, string $type): ?array
    {
        $response = $this->request('GET', "/zones/{$this->zoneId}/dns_records?type={$type}&name=" . urlencode($name));
        $matches = $response['json']['result'] ?? [];

        return $matches[0] ?? null;
    }

    private function request(string $method, string $path, ?string $body = null): array
    {
        $response = HttpClient::request($method, self::API_BASE . $path, [
            'Authorization: Bearer ' . $this->apiToken,
            'Content-Type: application/json',
        ], $body);

        $response['json'] = json_decode($response['body'], true) ?? [];

        return $response;
    }
}
