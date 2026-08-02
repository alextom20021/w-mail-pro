<?php

declare(strict_types=1);

namespace MailAI\Sending\Api;

/**
 * PostmarkClient — wraps Postmark's /email endpoint.
 * Credentials expected: ['server_token' => string]
 */
final class PostmarkClient implements ApiSenderInterface
{
    public function send(array $credentials, array $message): array
    {
        $payload = [
            'From' => sprintf('%s <%s>', $message['from_name'] ?? $message['from_email'], $message['from_email']),
            'To' => $message['to'],
            'Subject' => $message['subject'],
            'HtmlBody' => $message['html'],
            'MessageStream' => 'outbound',
            'Headers' => [
                ['Name' => 'List-Unsubscribe', 'Value' => "<{$message['unsubscribe_url']}>"],
                ['Name' => 'List-Unsubscribe-Post', 'Value' => 'List-Unsubscribe=One-Click'],
            ],
        ];

        $response = HttpClient::request(
            'POST',
            'https://api.postmarkapp.com/email',
            [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Postmark-Server-Token: ' . $credentials['server_token'],
            ],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        $decoded = json_decode($response['body'], true) ?? [];

        if ($response['status'] === 200 && ($decoded['ErrorCode'] ?? 1) === 0) {
            return ['success' => true, 'transcript' => $response['body'], 'error' => null, 'is_hard_failure' => false];
        }

        // Postmark ErrorCode 300-406 range covers invalid recipient / inactive
        // address / content problems — treat as hard failures; everything
        // else (e.g. rate limiting, 5xx) as retryable.
        $errorCode = $decoded['ErrorCode'] ?? 0;
        $isHard = $errorCode >= 300 && $errorCode <= 406;

        return [
            'success' => false,
            'transcript' => $response['body'],
            'error' => "Postmark error {$errorCode}: " . ($decoded['Message'] ?? $response['body']),
            'is_hard_failure' => $isHard,
        ];
    }
}
