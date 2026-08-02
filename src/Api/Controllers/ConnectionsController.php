<?php

declare(strict_types=1);

namespace MailAI\Api\Controllers;

use MailAI\Api\JsonResponse;
use MailAI\Security\EncryptionService;
use MailAI\Sending\SendingConnectionRepository;

final class ConnectionsController
{
    public function __construct(private readonly EncryptionService $encryption)
    {
    }

    public function index(): void
    {
        $repo = new SendingConnectionRepository($this->encryption);
        $rows = $repo->findAllWhere();
        // Never return credentials_encrypted over the API, even encrypted —
        // no reason to expose that blob to a client-side consumer.
        $sanitized = array_map(fn($r) => array_diff_key($r, ['credentials_encrypted' => true]), $rows);

        JsonResponse::ok(['connections' => $sanitized]);
    }

    public function create(array $body): void
    {
        foreach (['type', 'label', 'credentials'] as $field) {
            if (empty($body[$field])) {
                JsonResponse::error("Missing required field: {$field}", 422);
                return;
            }
        }

        $repo = new SendingConnectionRepository($this->encryption);
        $id = $repo->create(
            $body['type'],
            $body['label'],
            $body['credentials'],
            (int) ($body['daily_limit'] ?? 50),
            isset($body['from_domain_id']) ? (int) $body['from_domain_id'] : null
        );

        JsonResponse::ok(['id' => $id], 201);
    }

    public function show(array $params): void
    {
        $repo = new SendingConnectionRepository($this->encryption);
        $row = $repo->find((int) $params['id']);
        if ($row === null) {
            JsonResponse::error('Connection not found', 404);
            return;
        }
        unset($row['credentials_encrypted']);

        JsonResponse::ok(['connection' => $row]);
    }

    public function delete(array $params): void
    {
        $repo = new SendingConnectionRepository($this->encryption);
        $deleted = $repo->delete((int) $params['id']);

        if (!$deleted) {
            JsonResponse::error('Connection not found', 404);
            return;
        }

        JsonResponse::ok(['deleted' => true]);
    }
}
