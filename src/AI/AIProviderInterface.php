<?php

declare(strict_types=1);

namespace MailAI\AI;

/**
 * AIProviderInterface
 *
 * Every LLM backend (OpenAI, Anthropic, others) implements this so the
 * AIAgent orchestrator never hardcodes a specific vendor's API shape.
 * A provider's only job is: given a conversation + a tool schema, return
 * either an assistant text reply or a list of tool calls to execute.
 */
interface AIProviderInterface
{
    /**
     * @param array $messages Chat history: [['role' => 'user'|'assistant'|'system'|'tool', 'content' => string, ...], ...]
     * @param array $tools    JSON-Schema tool definitions (OpenAI/Anthropic "function calling" format)
     * @return AICompletionResult
     */
    public function complete(array $messages, array $tools = []): AICompletionResult;

    public function name(): string;
}
