<?php

declare(strict_types=1);

namespace MailAI\Sending;

use MailAI\Core\TenantRepository;
use MailAI\Security\EncryptionService;

/**
 * SendingConnectionRepository
 *
 * CRUD for `sending_connections` — the unified pool of SMTP servers and
 * third-party API connections (SendGrid, Mailgun, SES, Postmark) a client
 * rotates across. Credentials are always encrypted before they touch the
 * database and decrypted only at the point of use (ConnectionRotator /
 * the worker), never logged or returned in list views.
 */
final class SendingConnectionRepository extends TenantRepository
{
    protected string $table = 'sending_connections';

    private EncryptionService $encryption;

    public function __construct(EncryptionService $encryption, $db = null)
    {
        parent::__construct($db);
        $this->encryption = $encryption;
    }

    /**
     * @param array $credentials Plain associative array, e.g.
     *   SMTP:  ['host' => ..., 'port' => 587, 'username' => ..., 'password' => ..., 'encryption' => 'tls']
     *   API:   ['api_key' => ...] (+ 'domain' => ... for Mailgun, 'region' => ... for SES)
     */
    public function create(string $type, string $label, array $credentials, int $dailyLimit = 50, ?int $fromDomainId = null): int
    {
        return $this->insert([
            'type' => $type,
            'label' => $label,
            'credentials_encrypted' => $this->encryption->encryptArray($credentials),
            'from_domain_id' => $fromDomainId,
            'daily_limit' => $dailyLimit,
            'status' => 'warming',
            'warmup_started_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Returns decrypted credentials for one connection — call only at send time. */
    public function getDecryptedCredentials(int $connectionId): ?array
    {
        $row = $this->find($connectionId);
        if ($row === null) {
            return null;
        }

        return $this->encryption->decryptArray($row['credentials_encrypted']);
    }

    /** Connections eligible to receive traffic right now (not disabled/quarantined, under daily cap). */
    public function findEligible(): array
    {
        return $this->findAllWhere(
            "status IN ('active','warming') AND sent_today < daily_limit",
            [],
            'reputation_score DESC'
        );
    }

    public function incrementSentToday(int $connectionId, int $by = 1): void
    {
        $stmt = $this->db->prepare(
            "UPDATE sending_connections SET sent_today = sent_today + :by, last_used_at = NOW()
             WHERE id = :id AND client_id = :client_id"
        );
        $stmt->execute(['by' => $by, 'id' => $connectionId, 'client_id' => $this->clientId()]);
    }

    /** Called by a daily cron at midnight per client timezone. */
    public function resetDailyCounters(): void
    {
        $stmt = $this->db->prepare("UPDATE sending_connections SET sent_today = 0 WHERE client_id = :client_id");
        $stmt->execute(['client_id' => $this->clientId()]);
    }

    public function quarantine(int $connectionId, string $reason): void
    {
        $this->update($connectionId, [
            'status' => 'quarantined',
            'quarantine_reason' => $reason,
        ]);
    }
}
