<?php

declare(strict_types=1);

namespace MailAI\Api\Controllers;

use MailAI\Api\JsonResponse;
use MailAI\Core\ContactListRepository;
use MailAI\Core\ContactRepository;

final class ListsController
{
    public function index(): void
    {
        $repo = new ContactListRepository();
        JsonResponse::ok(['lists' => $repo->findAllWhere()]);
    }

    public function create(array $body): void
    {
        if (empty($body['name'])) {
            JsonResponse::error('Missing required field: name', 422);
            return;
        }

        $repo = new ContactListRepository();
        $id = $repo->create($body['name'], $body['description'] ?? null);

        JsonResponse::ok(['id' => $id], 201);
    }

    /** Bulk contact import: body = ['contacts' => [['email' => ..., 'first_name' => ...], ...]] */
    public function importContacts(array $params, array $body): void
    {
        $listId = (int) $params['id'];
        $listRepo = new ContactListRepository();
        if ($listRepo->find($listId) === null) {
            JsonResponse::error('List not found', 404);
            return;
        }

        if (empty($body['contacts']) || !is_array($body['contacts'])) {
            JsonResponse::error('Missing required field: contacts (array)', 422);
            return;
        }

        $contactRepo = new ContactRepository();
        $results = ['inserted' => 0, 'duplicate' => 0, 'suppressed' => 0, 'invalid' => 0];

        foreach ($body['contacts'] as $row) {
            if (empty($row['email'])) {
                $results['invalid']++;
                continue;
            }
            $extra = $row;
            unset($extra['email']);
            $outcome = $contactRepo->importCsvRow($listId, $row['email'], $extra);
            $results[$outcome]++;
        }

        $listRepo->refreshContactCount($listId);

        JsonResponse::ok(['results' => $results]);
    }
}
