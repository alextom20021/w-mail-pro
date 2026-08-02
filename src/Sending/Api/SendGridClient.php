<?php

declare(strict_types=1);

namespace MailAI\Sending\Api;

/**
 * SendGridClient — wraps SendGrid's v3 /mail/send endpoint.
 * Credentials expected: ['api_key' => string]
 */
final class SendGridClient implements ApiSenderInterface
{
    public function send(array $credentials, array $message): array
    {
        $payload = [
            'personalizations' => [[
                'to' => [['email' => $message['to']]],
            ]],
            'from' => [
                'email' => $message['from_email'],
                'name' => $message['from_name'] ?? '',
            ],
            'subject' => $message['subject'],
            'content' => [[
                'type' => 'text/html',
                'value' => $message['html'],
            ]],
            'headers' => array_merge($message['headers'] ?? [], [
                'List-Unsubscribe' => "<{$message['unsubscribe_url']}>",
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ]),
        ];

        $response = HttpClient::request(
            'POST',
            'https://api.sendgrid.com/v3/mail/send',
            [
                'Authorization: Bearer ' . $credentials['api_key'],
                'Content-Type: application/json',
            ],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        // SendGrid returns 202 with an empty body on success.
        if ($response['status'] === 202) {
            return ['success' => true, 'transcript' => 'HTTP 202 Accepted', 'error' => null, 'is_hard_failure' => false];
        }

        $isHard = $response['status'] === 400 || $response['status'] === 401 || $response['status'] === 403;

        return [
            'success' => false,
            'transcript' => $response['body'],
            'error' => "SendGrid HTTP {$response['status']}: {$response['body']}",
            'is_hard_failure' => $isHard,
        ];
    }
}
