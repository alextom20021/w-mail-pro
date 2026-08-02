<?php

declare(strict_types=1);

namespace MailAI\AI\Providers;

use MailAI\AI\AICompletionResult;
use MailAI\AI\AIProviderInterface;
use RuntimeException;

/**
 * OpenAIProvider
 *
 * Talks to the OpenAI Chat Completions API using its native
 * function-calling (tools) format. Requires no SDK — plain cURL keeps
 * the dependency surface small and avoids version churn.
 */
final class OpenAIProvider implements AIProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gpt-4o',
        private readonly string $baseUrl = 'https://api.openai.com/v1',
    ) {
    }

    public function name(): string
    {
        return 'openai';
    }

    public function complete(array $messages, array $tools = []): AICompletionResult
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.3, // low — this agent takes real actions, we want it consistent, not creative
        ];

        if (!empty($tools)) {
            $payload['tools'] = array_map(
                fn($t) => ['type' => 'function', 'function' => $t],
                $tools
            );
            $payload['tool_choice'] = 'auto';
        }

        $response = $this->request('/chat/completions', $payload);
        $choice = $response['choices'][0]['message'] ?? [];

        $toolCalls = [];
        foreach ($choice['tool_calls'] ?? [] as $call) {
            $toolCalls[] = [
                'id' => $call['id'],
                'name' => $call['function']['name'],
                'arguments' => json_decode($call['function']['arguments'] ?? '{}', true) ?? [],
            ];
        }

        return new AICompletionResult(
            text: $choice['content'] ?? null,
            toolCalls: $toolCalls,
            rawProviderName: 'openai:' . $this->model,
            usage: isset($response['usage']) ? [
                'input_tokens' => $response['usage']['prompt_tokens'] ?? 0,
                'output_tokens' => $response['usage']['completion_tokens'] ?? 0,
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
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => 60,
        ]);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("OpenAI request failed: {$error}");
        }

        $decoded = json_decode($body, true);
        if ($status >= 400) {
            $msg = $decoded['error']['message'] ?? $body;
            throw new RuntimeException("OpenAI API error ({$status}): {$msg}");
        }

        return $decoded;
    }
}
