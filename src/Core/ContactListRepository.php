<?php

declare(strict_types=1);

namespace MailAI\Core;

final class ContactListRepository extends TenantRepository
{
    protected string $table = 'contact_lists';

    public function create(string $name, ?string $description = null): int
    {
        return $this->insert(['name' => $name, 'description' => $description]);
    }

    public function refreshContactCount(int $listId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE contact_lists SET contact_count = (
                SELECT COUNT(*) FROM contacts WHERE list_id = :list_id AND client_id = :client_id AND status = 'subscribed'
             ) WHERE id = :list_id AND client_id = :client_id"
        );
        $stmt->execute(['list_id' => $listId, 'client_id' => $this->clientId()]);
    }
}
