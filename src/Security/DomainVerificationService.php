<?php

declare(strict_types=1);

namespace MailAI\Security;

/**
 * DomainVerificationService
 *
 * Checks a domain's live DNS for SPF, DKIM, and DMARC records and
 * compares them against what the platform expects, so the client
 * onboarding wizard and AI DNS assistant can both tell a client exactly
 * what's missing or misconfigured — the spec's "AI detects SPF/DKIM/
 * DMARC misconfigurations, tells the client what to add" requirement.
 *
 * Uses PHP's built-in dns_get_record() (no external API dependency).
 * Auto-applying records via a provider API (Cloudflare etc.) is a
 * separate, explicitly-scoped integration — not implemented here; see
 * README roadmap.
 */
final class DomainVerificationService
{
    /**
     * @return array{
     *   spf: array{status: string, record: ?string, issue: ?string},
     *   dkim: array{status: string, record: ?string, issue: ?string},
     *   dmarc: array{status: string, record: ?string, issue: ?string}
     * }
     */
    public function checkAll(string $domain, string $dkimSelector, string $expectedDkimPublicKey): array
    {
        return [
            'spf' => $this->checkSpf($domain),
            'dkim' => $this->checkDkim($domain, $dkimSelector, $expectedDkimPublicKey),
            'dmarc' => $this->checkDmarc($domain),
        ];
    }

    private function checkSpf(string $domain): array
    {
        $records = $this->txtRecords($domain);
        $spf = array_values(array_filter($records, fn($r) => str_starts_with($r, 'v=spf1')));

        if (empty($spf)) {
            return ['status' => 'fail', 'record' => null, 'issue' => 'No SPF (v=spf1) TXT record found at the apex domain.'];
        }

        if (count($spf) > 1) {
            return ['status' => 'fail', 'record' => implode(' | ', $spf), 'issue' => 'Multiple SPF records found — RFC 7208 only allows one; merge them into a single record.'];
        }

        $record = $spf[0];
        if (!str_contains($record, '-all') && !str_contains($record, '~all')) {
            return ['status' => 'fail', 'record' => $record, 'issue' => 'SPF record has no enforcement mechanism (-all or ~all) — it will not reliably reject spoofed mail.'];
        }

        return ['status' => 'pass', 'record' => $record, 'issue' => null];
    }

    private function checkDkim(string $domain, string $selector, string $expectedPublicKey): array
    {
        $host = "{$selector}._domainkey.{$domain}";
        $records = $this->txtRecords($host);

        if (empty($records)) {
            return [
                'status' => 'fail',
                'record' => null,
                'issue' => "No DKIM TXT record found at {$host}. Publish: " . DkimSigner::dnsRecordValue($expectedPublicKey),
            ];
        }

        $record = $records[0];
        // Normalize whitespace for comparison — DNS providers often wrap TXT values differently.
        $normalizedFound = preg_replace('/\s+/', '', $record);
        $normalizedExpected = preg_replace('/\s+/', '', DkimSigner::dnsRecordValue($expectedPublicKey));

        if ($normalizedFound !== $normalizedExpected) {
            return [
                'status' => 'fail',
                'record' => $record,
                'issue' => 'DKIM record found but does not match the platform\'s generated public key — it may be stale or from a different key pair.',
            ];
        }

        return ['status' => 'pass', 'record' => $record, 'issue' => null];
    }

    private function checkDmarc(string $domain): array
    {
        $host = "_dmarc.{$domain}";
        $records = $this->txtRecords($host);
        $dmarc = array_values(array_filter($records, fn($r) => str_starts_with($r, 'v=DMARC1')));

        if (empty($dmarc)) {
            return ['status' => 'fail', 'record' => null, 'issue' => "No DMARC record found at {$host}. Publish at minimum: v=DMARC1; p=quarantine; rua=mailto:dmarc-reports@{$domain}"];
        }

        $record = $dmarc[0];
        if (str_contains($record, 'p=none')) {
            return ['status' => 'fail', 'record' => $record, 'issue' => 'DMARC policy is p=none — monitoring only, no enforcement. Recommend p=quarantine or p=reject once SPF/DKIM alignment is confirmed.'];
        }

        return ['status' => 'pass', 'record' => $record, 'issue' => null];
    }

    /** @return string[] Concatenated TXT record values for a host. */
    private function txtRecords(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);
        if ($records === false) {
            return [];
        }

        return array_map(fn($r) => $r['txt'] ?? '', $records);
    }
}
