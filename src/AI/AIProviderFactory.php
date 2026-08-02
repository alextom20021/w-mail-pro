<?php

declare(strict_types=1);

namespace MailAI\AI;

use MailAI\AI\Providers\AnthropicProvider;
use MailAI\AI\Providers\OpenAIProvider;
use MailAI\Core\Database;
use MailAI\Security\EncryptionService;
use RuntimeException;

/**
 * AIProviderFactory
 *
 * Builds an AIProviderInterface from the `ai_providers` table (priority-
 * ordered, active providers only). This is what makes "swap the AI
 * backend" a config change instead of a code change: add a row, flip
 * `is_active`, done.
 *
 * FailoverProvider wraps N providers and tries them in priority order,
 * falling through to the next on a hard API error (timeout, 5xx, auth
 * failure) so a single vendor outage doesn't take down the whole AI layer.
 */
final class AIProviderFactory
{
    public static function fromDatabase(EncryptionService $encryption): AIProviderInterface
    {
        $db = Database::connection();
        $stmt = $db->query(
            "SELECT * FROM ai_providers WHERE is_active = 1 ORDER BY priority ASC"
        );
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            throw new RuntimeException(
                'No active AI providers configured. Insert a row into ai_providers ' .
                '(name=openai|anthropic, api_key_encrypted, default_model) to enable the AI agent.'
            );
        }

        $providers = [];
        foreach ($rows as $row) {
            $apiKey = $encryption->decrypt($row['api_key_encrypted']);
            $providers[] = match ($row['name']) {
                'openai' => new OpenAIProvider($apiKey, $row['default_model'] ?: 'gpt-4o'),
                'anthropic' => new AnthropicProvider($apiKey, $row['default_model'] ?: 'claude-sonnet-4-5'),
                default => throw new RuntimeException("Unknown AI provider name: {$row['name']}"),
            };
        }

        return count($providers) === 1 ? $providers[0] : new FailoverProvider($providers);
    }
}
