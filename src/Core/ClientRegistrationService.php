<?php

declare(strict_types=1);

namespace MailAI\Core;

use PDO;
use RuntimeException;

/**
 * ClientRegistrationService
 *
 * Self-serve signup (previously "insert a row by hand" per the README).
 * New clients land in `plan = 'trial'` and `ai_autonomy_level =
 * 'suggest_only'` — nobody gets `full_auto` by self-signup, a super-admin
 * has to promote that from the admin panel. Status is set to 'active'
 * immediately: there's no transactional-email sending path yet for the
 * platform itself (sending_connections are per-client, for campaign
 * mail, not a system mailbox), so gating on 'pending_verification' would
 * be a dead end with no way to progress. Revisit this once the platform
 * has its own outbound email capability — see README roadmap.
 */
final class ClientRegistrationService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /** @throws RuntimeException on duplicate email or weak password */
    public function register(string $companyName, string $email, string $password): int
    {
        $companyName = trim($companyName);
        $email = trim(strtolower($email));

        if ($companyName === '') {
            throw new RuntimeException('Company/organization name is required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid email address.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Password must be at least 8 characters.');
        }

        $stmt = $this->db->prepare('SELECT id FROM clients WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch() !== false) {
            throw new RuntimeException('An account with that email already exists.');
        }

        $uuid = self::uuidV4();
        $apiKey = bin2hex(random_bytes(32));
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $this->db->prepare(
            'INSERT INTO clients (uuid, company_name, email, password_hash, api_key, plan, status, ai_autonomy_level, created_at)
             VALUES (:uuid, :company_name, :email, :password_hash, :api_key, :plan, :status, :autonomy, NOW())'
        );
        $stmt->execute([
            'uuid' => $uuid,
            'company_name' => $companyName,
            'email' => $email,
            'password_hash' => $passwordHash,
            'api_key' => $apiKey,
            'plan' => 'trial',
            'status' => 'active',
            'autonomy' => 'suggest_only',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public static function uuidV4(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
    }
}
