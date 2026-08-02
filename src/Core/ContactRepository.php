<?php

declare(strict_types=1);

namespace MailAI\Core;

final class ContactRepository extends TenantRepository
{
    protected string $table = 'contacts';

    /** @return array{inserted:int, skipped_suppressed:int, skipped_duplicate:int, skipped_invalid:int} */
    public function importCsvRow(int $listId, string $email, array $extraFields = []): string
    {
        $email = trim(strtolower($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'invalid';
        }

        $suppressed = $this->db->prepare(
            'SELECT 1 FROM suppressions WHERE client_id = :client_id AND email = :email LIMIT 1'
        );
        $suppressed->execute(['client_id' => $this->clientId(), 'email' => $email]);
        if ($suppressed->fetchColumn()) {
            return 'suppressed';
        }

        $firstName = $extraFields['first_name'] ?? null;
        $lastName = $extraFields['last_name'] ?? null;
        unset($extraFields['first_name'], $extraFields['last_name']);

        try {
            $this->insert([
                'list_id' => $listId,
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'custom_fields' => !empty($extraFields) ? json_encode($extraFields, JSON_THROW_ON_ERROR) : null,
                'status' => 'subscribed',
                'consent_source' => 'import',
                'consent_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\PDOException $e) {
            // Unique key (list_id, email) violation = already on this list.
            if ((int) $e->errorInfo[1] === 1062) {
                return 'duplicate';
            }
            throw $e;
        }

        return 'inserted';
    }

    public function findSubscribed(int $listId, int $limit = 1000, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM contacts WHERE client_id = :client_id AND list_id = :list_id AND status = 'subscribed'
             ORDER BY id ASC LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':client_id', $this->clientId(), \PDO::PARAM_INT);
        $stmt->bindValue(':list_id', $listId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
