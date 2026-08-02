<?php

declare(strict_types=1);

namespace MailAI\AI\Providers;

use MailAI\AI\AICompletionResult;
use MailAI\AI\AIProviderInterface;
use RuntimeException;

/**
 * AnthropicProvider
 *
 * Talks to the Anthropic Messages API using its native tool-use format.
 * Kept structurally parallel to OpenAIProvider so AIAgent can swap
 * between them (or fail over) without any caller-side changes.
 */
final class AnthropicProvider implements AIProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'claude-sonnet-4-5',
        private readonly string $baseUrl = 'https://api.anthropic.com/v1',
    ) {
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function complete(array $messages, array $tools = []): AICompletionResult
    {
        // Anthropic wants system prompt separated from the messages array.
        $system = null;
        $filtered = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') {
                $system = $m['content'];
                continue;
            }
            $filtered[] = $m;
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => 2048,
            'messages' => $filtered,
        ];
        if ($system !== null) {
            $payload['system'] = $system;
        }
        if (!empty($tools)) {
            $payload['tools'] = array_map(fn($t) => [
                'name' => $t['name'],
                'description' => $t['description'] ?? '',
                'input_schema' => $t['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ], $tools);
        }

        $response = $this->request('/messages', $payload);

        $text = null;
        $toolCalls = [];
        foreach ($response['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $text = ($text ?? '') . $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'arguments' => $block['input'] ?? [],
                ];
            }
        }

        return new AICompletionResult(
            text: $text,
            toolCalls: $toolCalls,
            rawProviderName: 'anthropic:' . $this->model,
            usage: isset($response['usage']) ? [
                'input_tokens' => $response['usage']['input_tokens'] ?? 0,
                'output_tokens' => $response['usage']['output_tokens'] ?? 0,
            ] : null,
        );
    }

    private function request(string $path, array $payload): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => 60,
        ]);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Anthropic request failed: {$error}");
        }

        $decoded = json_decode($body, true);
        if ($status >= 400) {
            $msg = $decoded['error']['message'] ?? $body;
            throw new RuntimeException("Anthropic API error ({$status}): {$msg}");
        }

        return $decoded;
    }
}
