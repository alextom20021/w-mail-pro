<?php

declare(strict_types=1);

namespace MailAI\Core;

use PDO;

/**
 * TenantRepository
 *
 * Base class for every repository that touches client-scoped data
 * (contacts, campaigns, connections, domains, outbox, etc.).
 *
 * The pattern: every read/write method accepts a WHERE fragment for its
 * *business* filter, and this base class ALWAYS appends "client_id = ?"
 * bound from ClientContext — never from caller input. That's what makes
 * it structurally impossible for a client-scoped controller to
 * accidentally (or via a tampered request parameter) read another
 * tenant's row.
 *
 * Super-admin code paths should NOT extend this class — they use
 * Database::connection() directly, since they legitimately need
 * cross-tenant queries.
 */
abstract class TenantRepository
{
    protected PDO $db;
    protected string $table;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    protected function clientId(): int
    {
        return ClientContext::clientId();
    }

    /**
     * Fetch one row by primary key, scoped to the current client.
     * Returns null if the row doesn't exist OR belongs to another client
     * (indistinguishable by design — no tenant enumeration via timing/404).
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE id = :id AND client_id = :client_id LIMIT 1"
        );
        $stmt->execute(['id' => $id, 'client_id' => $this->clientId()]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param string $where Additional SQL condition fragment (parameterized
     *                       placeholders only — never string-interpolate
     *                       caller values here).
     * @param array  $params Bound params for $where, WITHOUT client_id.
     */
    public function findAllWhere(string $where = '1=1', array $params = [], string $orderBy = 'id DESC', int $limit = 500): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE client_id = :client_id AND ({$where}) ORDER BY {$orderBy} LIMIT :limit";
        $stmt = $this->db->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . ltrim((string)$key, ':'), $value);
        }
        $stmt->bindValue(':client_id', $this->clientId(), PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Inserts a row, forcing client_id from context regardless of $data. */
    public function insert(array $data): int
    {
        $data['client_id'] = $this->clientId();

        $columns = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute($data);

        return (int) $this->db->lastInsertId();
    }

    /** Updates a row by id, but only if it belongs to the current client. */
    public function update(int $id, array $data): bool
    {
        unset($data['client_id'], $data['id']); // never allow tenant reassignment via update()

        $assignments = implode(', ', array_map(fn($c) => "{$c} = :{$c}", array_keys($data)));
        $sql = "UPDATE {$this->table} SET {$assignments} WHERE id = :id AND client_id = :client_id";

        $stmt = $this->db->prepare($sql);
        $data['id'] = $id;
        $data['client_id'] = $this->clientId();
        $stmt->execute($data);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE id = :id AND client_id = :client_id");
        $stmt->execute(['id' => $id, 'client_id' => $this->clientId()]);

        return $stmt->rowCount() > 0;
    }
}
