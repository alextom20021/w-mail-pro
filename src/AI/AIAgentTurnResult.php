<?php

declare(strict_types=1);

namespace MailAI\AI;

final class AIAgentTurnResult
{
    public function __construct(
        public readonly string $finalText,
        public readonly array $executedActions,
        public readonly array $pendingApprovals,
        public readonly string $provider,
    ) {
    }
}
