<?php

declare(strict_types=1);

namespace MailAI\AI;

use RuntimeException;
use Throwable;

/**
 * FailoverProvider
 *
 * Wraps multiple AIProviderInterface instances and tries them in order,
 * moving to the next on any exception. Used when the client (or platform)
 * has more than one AI provider configured — e.g. OpenAI primary,
 * Anthropic fallback — so a vendor outage degrades gracefully instead of
 * breaking every AI-dependent feature at once.
 */
final class FailoverProvider implements AIProviderInterface
{
    /** @param AIProviderInterface[] $providers In priority order. */
    public function __construct(private readonly array $providers)
    {
        if (empty($providers)) {
            throw new RuntimeException('FailoverProvider requires at least one provider.');
        }
    }

    public function name(): string
    {
        return 'failover(' . implode(',', array_map(fn($p) => $p->name(), $this->providers)) . ')';
    }

    public function complete(array $messages, array $tools = []): AICompletionResult
    {
        $lastError = null;

        foreach ($this->providers as $provider) {
            try {
                return $provider->complete($messages, $tools);
            } catch (Throwable $e) {
                $lastError = $e;
                continue;
            }
        }

        throw new RuntimeException(
            'All configured AI providers failed. Last error: ' . ($lastError?->getMessage() ?? 'unknown'),
            0,
            $lastError
        );
    }
}
