<?php

declare(strict_types=1);

namespace MailAI\Core;

use PDO;
use RuntimeException;

/**
 * ClientAdminRepository
 *
 * Deliberately NOT a TenantRepository — every method here operates
 * across ALL clients, which is exactly the access a super admin needs
 * and exactly what client-facing code must never have. Every public
 * method in this class should only ever be called from a
 * SessionAuth::requireSuperAdmin()-gated page under public/admin/.
 */
final class ClientAdminRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function search(string $query = '', string $status = '', bool $includeDeleted = false): array
    {
        $where = [];
        $params = [];

        if (!$includeDeleted) {
            $where[] = 'c.deleted_at IS NULL';
        }
        if ($query !== '') {
            $where[] = '(c.company_name LIKE :q OR c.email LIKE :q)';
            $params['q'] = '%' . $query . '%';
        }
        if ($status !== '') {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        }

        $sql = 'SELECT c.*,
                    (SELECT COUNT(*) FROM sending_connections sc WHERE sc.client_id = c.id) AS connection_count,
                    (SELECT COUNT(*) FROM campaigns cp WHERE cp.client_id = c.id) AS campaign_count,
                    (SELECT COUNT(*) FROM contacts ct WHERE ct.client_id = c.id) AS contact_count
                FROM clients c';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY c.created_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function find(int $clientId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM clients WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $clientId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(string $companyName, string $email, string $password, string $plan = 'trial'): int
    {
        $stmt = $this->db->prepare('SELECT id FROM clients WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch() !== false) {
            throw new RuntimeException('A client with that email already exists.');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO clients (uuid, company_name, email, password_hash, api_key, plan, status, ai_autonomy_level, created_at)
             VALUES (:uuid, :company_name, :email, :password_hash, :api_key, :plan, :status, :autonomy, NOW())'
        );
        $stmt->execute([
            'uuid' => self::uuidV4(),
            'company_name' => $companyName,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'api_key' => bin2hex(random_bytes(32)),
            'plan' => $plan,
            'status' => 'active',
            'autonomy' => 'suggest_only',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateStatus(int $clientId, string $status): void
    {
        $this->db->prepare('UPDATE clients SET status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $clientId]);
    }

    public function updatePlanAndQuotas(int $clientId, string $plan, int $dailySends, int $contacts, int $connections): void
    {
        $stmt = $this->db->prepare(
            'UPDATE clients SET plan = :plan, quota_daily_sends = :daily, quota_contacts = :contacts, quota_connections = :connections
             WHERE id = :id'
        );
        $stmt->execute([
            'plan' => $plan,
            'daily' => $dailySends,
            'contacts' => $contacts,
            'connections' => $connections,
            'id' => $clientId,
        ]);
    }

    /** Bumps session_version, invalidating every active session for this client on their next request. */
    public function forceLogout(int $clientId): void
    {
        $this->db->prepare('UPDATE clients SET session_version = session_version + 1 WHERE id = :id')
            ->execute(['id' => $clientId]);
    }

    /** Generates and sets a new random password, returned once (plaintext never stored). */
    public function forcePasswordReset(int $clientId): string
    {
        $newPassword = bin2hex(random_bytes(9));
        $this->db->prepare('UPDATE clients SET password_hash = :hash, session_version = session_version + 1 WHERE id = :id')
            ->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $clientId]);

        return $newPassword;
    }

    public function softDelete(int $clientId): void
    {
        $this->db->prepare('UPDATE clients SET deleted_at = NOW(), status = :status, session_version = session_version + 1 WHERE id = :id')
            ->execute(['status' => 'cancelled', 'id' => $clientId]);
    }

    public function restore(int $clientId): void
    {
        $this->db->prepare('UPDATE clients SET deleted_at = NULL, status = :status WHERE id = :id')
            ->execute(['status' => 'active', 'id' => $clientId]);
    }

    /** Real, irreversible DELETE. Only ever call after an explicit typed confirmation in the UI. */
    public function purge(int $clientId): void
    {
        $stmt = $this->db->prepare('SELECT deleted_at FROM clients WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $clientId]);
        $row = $stmt->fetch();
        if ($row === false || $row['deleted_at'] === null) {
            throw new RuntimeException('Only a soft-deleted client can be permanently purged. Soft-delete it first.');
        }

        $this->db->prepare('DELETE FROM clients WHERE id = :id')->execute(['id' => $clientId]);
    }

    public function addNote(int $clientId, string $adminEmail, string $note): void
    {
        $this->db->prepare('INSERT INTO client_notes (client_id, admin_email, note, created_at) VALUES (:client_id, :admin_email, :note, NOW())')
            ->execute(['client_id' => $clientId, 'admin_email' => $adminEmail, 'note' => $note]);
    }

    public function notes(int $clientId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM client_notes WHERE client_id = :client_id ORDER BY created_at DESC');
        $stmt->execute(['client_id' => $clientId]);

        return $stmt->fetchAll();
    }

    /** Merged, time-ordered feed of this client's campaigns, AI actions, and admin actions — spec 1.2's "activity timeline". */
    public function activityTimeline(int $clientId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "(SELECT 'campaign' AS kind, name AS summary, status AS detail, created_at FROM campaigns WHERE client_id = :c1)
             UNION ALL
             (SELECT 'ai_action' AS kind, tool_name AS summary, status AS detail, created_at FROM ai_audit_log WHERE client_id = :c2)
             UNION ALL
             (SELECT 'admin_action' AS kind, action AS summary, admin_email AS detail, created_at FROM admin_audit_log WHERE client_id = :c3)
             ORDER BY created_at DESC LIMIT :lim"
        );
        $stmt->bindValue('c1', $clientId, PDO::PARAM_INT);
        $stmt->bindValue('c2', $clientId, PDO::PARAM_INT);
        $stmt->bindValue('c3', $clientId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
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
