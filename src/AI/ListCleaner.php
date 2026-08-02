<?php

declare(strict_types=1);

namespace MailAI\AI;

use MailAI\Core\ClientContext;
use PDO;

/**
 * ListCleaner
 *
 * Scans a client's contacts for risk signals — role accounts, disposable
 * domains, syntactically invalid addresses, and repeat hard-bounce
 * history — and flags them via `contacts.risk_flags`. Deliberately does
 * NOT auto-suppress on every flag: role accounts (info@, support@) are
 * often legitimate B2B recipients, so this surfaces risk for a human (or
 * a `full_auto` client's AI agent) to decide on, except for the two
 * signals that are unambiguous — invalid syntax and prior hard bounce —
 * which get suppressed outright since sending to them is guaranteed to
 * hurt reputation with zero upside.
 */
final class ListCleaner
{
    private const ROLE_ACCOUNT_PREFIXES = [
        'admin', 'info', 'support', 'sales', 'contact', 'help', 'no-reply',
        'noreply', 'postmaster', 'webmaster', 'abuse', 'billing', 'hello',
    ];

    private const DISPOSABLE_DOMAINS = [
        'mailinator.com', 'guerrillamail.com', 'tempmail.com', '10minutemail.com',
        'throwawaymail.com', 'yopmail.com', 'trashmail.com',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{scanned:int, flagged:int, suppressed:int} */
    public function cleanCurrentClientList(int $listId): array
    {
        $clientId = ClientContext::clientId();

        $stmt = $this->db->prepare(
            'SELECT id, email FROM contacts WHERE client_id = :client_id AND list_id = :list_id AND status = :status'
        );
        $stmt->execute(['client_id' => $clientId, 'list_id' => $listId, 'status' => 'subscribed']);
        $contacts = $stmt->fetchAll();

        $flaggedCount = 0;
        $suppressedCount = 0;

        foreach ($contacts as $contact) {
            $flags = $this->evaluate($contact['email']);

            if (empty($flags)) {
                continue;
            }
            $flaggedCount++;

            $this->updateFlags((int) $contact['id'], $flags);

            if (in_array('invalid_syntax', $flags, true) || $this->hasHardBounceHistory($clientId, $contact['email'])) {
                $this->suppress($clientId, $contact['id'], $contact['email']);
                $suppressedCount++;
            }
        }

        return ['scanned' => count($contacts), 'flagged' => $flaggedCount, 'suppressed' => $suppressedCount];
    }

    private function evaluate(string $email): array
    {
        $flags = [];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flags[] = 'invalid_syntax';
            return $flags; // no point checking further signals on a malformed address
        }

        [$local, $domain] = explode('@', $email, 2);
        $domain = strtolower($domain);
        $local = strtolower($local);

        if (in_array($domain, self::DISPOSABLE_DOMAINS, true)) {
            $flags[] = 'disposable_domain';
        }

        if (in_array($local, self::ROLE_ACCOUNT_PREFIXES, true)) {
            $flags[] = 'role_account';
        }

        if (preg_match('/^[a-z0-9]{20,}$/', $local)) {
            $flags[] = 'spam_trap_suspect'; // long random-looking local part, a common honeypot pattern
        }

        return $flags;
    }

    private function hasHardBounceHistory(int $clientId, string $email): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM bounces WHERE client_id = :client_id AND email = :email AND bounce_type = 'hard' LIMIT 1"
        );
        $stmt->execute(['client_id' => $clientId, 'email' => $email]);

        return (bool) $stmt->fetchColumn();
    }

    private function updateFlags(int $contactId, array $flags): void
    {
        $stmt = $this->db->prepare('UPDATE contacts SET risk_flags = :flags WHERE id = :id');
        $stmt->execute(['flags' => json_encode($flags, JSON_THROW_ON_ERROR), 'id' => $contactId]);
    }

    private function suppress(int $clientId, int $contactId, string $email): void
    {
        $this->db->prepare(
            "INSERT IGNORE INTO suppressions (client_id, email, reason, source_detail)
             VALUES (:client_id, :email, 'ai_list_clean', 'Flagged by ListCleaner')"
        )->execute(['client_id' => $clientId, 'email' => $email]);

        $this->db->prepare(
            "UPDATE contacts SET status = 'suppressed' WHERE id = :id AND client_id = :client_id"
        )->execute(['id' => $contactId, 'client_id' => $clientId]);
    }
}
