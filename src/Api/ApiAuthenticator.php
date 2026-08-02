<?php

declare(strict_types=1);

namespace MailAI\Api;

use MailAI\Core\ClientContext;
use MailAI\Core\Database;
use PDO;

/**
 * ApiAuthenticator
 *
 * Validates the `Authorization: Bearer <api_key>` header against
 * `clients.api_key`, sets ClientContext for the rest of the request, and
 * exposes scope checks. api_key is stored as a plain unique token
 * (64-char random hex, NOT a password — generated server-side, never
 * user-chosen) rather than hashed, because unlike a password it's a
 * high-entropy machine credential the client copies from the dashboard,
 * and we need fast equality lookup on every API request without a
 * bcrypt-style deliberately-slow hash. It's still transmitted only over
 * HTTPS and never logged (see ApiRequestLogger).
 */
final class ApiAuthenticator
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /** @return array{client_id:int, scopes:string[]}|null Null if auth fails. */
    public function authenticate(?string $authorizationHeader): ?array
    {
        if ($authorizationHeader === null || !preg_match('/^Bearer\s+(.+)$/i', $authorizationHeader, $m)) {
            return null;
        }
        $apiKey = trim($m[1]);
        if ($apiKey === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT id, api_key_scopes, status FROM clients WHERE api_key = :api_key LIMIT 1"
        );
        $stmt->execute(['api_key' => $apiKey]);
        $client = $stmt->fetch();

        if ($client === false || $client['status'] !== 'active') {
            return null;
        }

        ClientContext::setClient((int) $client['id']);

        return [
            'client_id' => (int) $client['id'],
            'scopes' => json_decode($client['api_key_scopes'] ?? '["read"]', true) ?: ['read'],
        ];
    }

    public function hasScope(array $auth, string $required): bool
    {
        return in_array($required, $auth['scopes'], true) || in_array('admin', $auth['scopes'], true);
    }
}
