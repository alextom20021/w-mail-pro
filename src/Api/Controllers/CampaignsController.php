<?php

declare(strict_types=1);

namespace MailAI\Api\Controllers;

use MailAI\Api\JsonResponse;
use MailAI\Core\CampaignRepository;
use MailAI\Core\Database;
use MailAI\Sending\CampaignQueueingService;

final class CampaignsController
{
    public function index(): void
    {
        $repo = new CampaignRepository();
        JsonResponse::ok(['campaigns' => $repo->findAllWhere()]);
    }

    public function create(array $body): void
    {
        foreach (['name', 'template_id', 'list_id'] as $field) {
            if (empty($body[$field])) {
                JsonResponse::error("Missing required field: {$field}", 422);
                return;
            }
        }

        $repo = new CampaignRepository();
        $id = $repo->create($body['name'], (int) $body['template_id'], (int) $body['list_id']);

        JsonResponse::ok(['id' => $id], 201);
    }

    /**
     * Destructive per the spec (irreversibly starts sending to a whole
     * list) — the dashboard/AI-agent path should route this through
     * AIAgent::DESTRUCTIVE_TOOLS approval when triggered by the AI chat
     * assistant; direct API calls are gated only by the caller's own
     * `write`/`admin` scope, which is the expected trust boundary for a
     * client's own API key.
     */
    public function send(array $params): void
    {
        $db = Database::connection();
        $campaignId = (int) $params['id'];

        $stmt = $db->prepare('SELECT * FROM campaigns WHERE id = :id');
        $stmt->execute(['id' => $campaignId]);
        $campaign = $stmt->fetch();

        if ($campaign === false) {
            JsonResponse::error('Campaign not found', 404);
            return;
        }

        $tplStmt = $db->prepare('SELECT subject, html_body FROM email_templates WHERE id = :id');
        $tplStmt->execute(['id' => $campaign['template_id']]);
        $template = $tplStmt->fetch();

        if ($template === false) {
            JsonResponse::error('Campaign\'s template not found', 422);
            return;
        }

        try {
            $service = new CampaignQueueingService($db);
            $count = $service->enqueue($campaignId, $template['subject'], $template['html_body'], (int) $campaign['list_id']);
        } catch (\RuntimeException $e) {
            JsonResponse::error($e->getMessage(), 422);
            return;
        }

        JsonResponse::ok(['enqueued' => $count]);
    }
}
