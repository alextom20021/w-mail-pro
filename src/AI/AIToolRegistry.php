<?php

declare(strict_types=1);

namespace MailAI\AI;

use InvalidArgumentException;

/**
 * AIToolRegistry
 *
 * The fixed set of actions the AI agent is allowed to take. This is the
 * single most important safety boundary in the AI subsystem: the LLM
 * cannot execute arbitrary code or arbitrary SQL — it can only invoke one
 * of these named, schema-validated tools, each backed by a plain PHP
 * closure that calls real repository methods under the current
 * ClientContext (so tenant isolation still applies to AI actions).
 *
 * Register tools with a JSON-Schema `parameters` block (used both to tell
 * the LLM what arguments look like, and — in a fuller build — to validate
 * incoming arguments before execution).
 */
final class AIToolRegistry
{
    /** @var array<string, array{schema: array, handler: callable}> */
    private array $tools = [];

    public function register(string $name, string $description, array $parametersSchema, callable $handler): void
    {
        $this->tools[$name] = [
            'schema' => [
                'name' => $name,
                'description' => $description,
                'parameters' => $parametersSchema,
            ],
            'handler' => $handler,
        ];
    }

    /** Tool schemas formatted for AIProviderInterface::complete(). */
    public function schemas(): array
    {
        return array_map(fn($t) => $t['schema'], array_values($this->tools));
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /** Executes a tool by name with the given arguments and returns its result array. */
    public function execute(string $name, array $arguments): array
    {
        if (!$this->has($name)) {
            throw new InvalidArgumentException("Unknown AI tool: {$name}");
        }

        return ($this->tools[$name]['handler'])($arguments);
    }
}
