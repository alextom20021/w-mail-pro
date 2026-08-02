<?php

declare(strict_types=1);

namespace MailAI\Core;

/**
 * ASSUMPTION NOTE: this repo assumes the pre-existing `campaigns` table
 * (from the baseline system, before this rebuild) has `name`,
 * `template_id`, `list_id`, and `status` columns — those weren't part of
 * the migration this project added, since campaigns already existed.
 * Verify against your actual table before running `create()`; adjust
 * the column list here if the baseline schema differs.
 */
final class CampaignRepository extends TenantRepository
{
    protected string $table = 'campaigns';

    public function create(string $name, int $templateId, int $listId): int
    {
        return $this->insert([
            'name' => $name,
            'template_id' => $templateId,
            'list_id' => $listId,
            'status' => 'draft',
        ]);
    }
}
