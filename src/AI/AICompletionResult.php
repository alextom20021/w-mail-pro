<?php

declare(strict_types=1);

namespace MailAI\AI;

/**
 * Normalized response shape returned by every AIProviderInterface,
 * regardless of the underlying vendor's wire format.
 */
final class AICompletionResult
{
    /**
     * @param string|null $text     Assistant's natural-language reply, if any.
     * @param array       $toolCalls Each: ['id' => string, 'name' => string, 'arguments' => array]
     */
    public function __construct(
        public readonly ?string $text,
        public readonly array $toolCalls,
        public readonly string $rawProviderName,
        public readonly ?array $usage = null, // ['input_tokens' => int, 'output_tokens' => int]
    ) {
    }

    public function hasToolCalls(): bool
    {
        return !empty($this->toolCalls);
    }
}
