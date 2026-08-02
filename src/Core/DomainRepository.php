<?php

declare(strict_types=1);

namespace MailAI\Core;

use MailAI\Security\DkimSigner;
use MailAI\Security\DomainVerificationService;
use MailAI\Security\EncryptionService;

final class DomainRepository extends TenantRepository
{
    protected string $table = 'domains';

    private EncryptionService $encryption;

    public function __construct(EncryptionService $encryption, $db = null)
    {
        parent::__construct($db);
        $this->encryption = $encryption;
    }

    /** Registers a new domain and generates its DKIM key pair immediately. */
    public function create(string $domainName, string $dkimSelector = 'mailai'): int
    {
        $keyPair = DkimSigner::generateKeyPair();

        return $this->insert([
            'domain' => $domainName,
            'dkim_selector' => $dkimSelector,
            'dkim_private_key_encrypted' => $this->encryption->encrypt($keyPair['private_key_pem']),
            'dkim_public_key' => $keyPair['public_key'],
            'dns_verification_status' => 'pending',
        ]);
    }

    /** Runs live DNS checks and persists the result. */
    public function verify(int $domainId): array
    {
        $domain = $this->find($domainId);
        if ($domain === null) {
            throw new \RuntimeException("Domain {$domainId} not found for this client.");
        }

        $checker = new DomainVerificationService();
        $results = $checker->checkAll($domain['domain'], $domain['dkim_selector'], $domain['dkim_public_key']);

        $allPass = $results['spf']['status'] === 'pass'
            && $results['dkim']['status'] === 'pass'
            && $results['dmarc']['status'] === 'pass';

        $this->update($domainId, [
            'spf_status' => $results['spf']['status'],
            'dkim_status' => $results['dkim']['status'],
            'dmarc_status' => $results['dmarc']['status'],
            'dns_verification_status' => $allPass ? 'verified' : 'failed',
            'dns_last_checked_at' => date('Y-m-d H:i:s'),
        ]);

        return $results;
    }

    /** Decrypted DKIM material for use at send time (MailDispatcher's 'dkim' param). */
    public function getDkimForSending(int $domainId): ?array
    {
        $domain = $this->find($domainId);
        if ($domain === null || empty($domain['dkim_private_key_encrypted'])) {
            return null;
        }

        return [
            'domain' => $domain['domain'],
            'selector' => $domain['dkim_selector'],
            'private_key_pem' => $this->encryption->decrypt($domain['dkim_private_key_encrypted']),
        ];
    }

    /** The exact DNS records a client needs to publish, for the onboarding wizard UI. */
    public function requiredDnsRecords(int $domainId): array
    {
        $domain = $this->find($domainId);
        if ($domain === null) {
            throw new \RuntimeException("Domain {$domainId} not found for this client.");
        }

        return [
            'spf' => ['type' => 'TXT', 'host' => '@', 'value' => 'v=spf1 include:_spf.mailai.io ~all'],
            'dkim' => [
                'type' => 'TXT',
                'host' => "{$domain['dkim_selector']}._domainkey",
                'value' => DkimSigner::dnsRecordValue($domain['dkim_public_key']),
            ],
            'dmarc' => ['type' => 'TXT', 'host' => '_dmarc', 'value' => "v=DMARC1; p=quarantine; rua=mailto:dmarc-reports@{$domain['domain']}"],
        ];
    }
}
