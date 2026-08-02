<?php

declare(strict_types=1);

namespace MailAI\Api\Controllers;

use MailAI\Api\JsonResponse;
use MailAI\Core\DomainRepository;
use MailAI\Security\EncryptionService;

final class DomainsController
{
    public function __construct(private readonly EncryptionService $encryption)
    {
    }

    public function index(): void
    {
        $repo = new DomainRepository($this->encryption);
        $rows = $repo->findAllWhere();
        $sanitized = array_map(fn($r) => array_diff_key($r, ['dkim_private_key_encrypted' => true]), $rows);

        JsonResponse::ok(['domains' => $sanitized]);
    }

    public function create(array $body): void
    {
        if (empty($body['domain'])) {
            JsonResponse::error('Missing required field: domain', 422);
            return;
        }

        $repo = new DomainRepository($this->encryption);
        $id = $repo->create($body['domain'], $body['dkim_selector'] ?? 'mailai');

        JsonResponse::ok([
            'id' => $id,
            'required_dns_records' => $repo->requiredDnsRecords($id),
        ], 201);
    }

    public function verify(array $params): void
    {
        $repo = new DomainRepository($this->encryption);
        $results = $repo->verify((int) $params['id']);

        JsonResponse::ok(['verification' => $results]);
    }
}
