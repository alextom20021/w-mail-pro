<?php

declare(strict_types=1);

namespace MailAI\Sending\Api;

/**
 * MailgunClient — wraps Mailgun's /messages endpoint (form-encoded, Basic auth).
 * Credentials expected: ['api_key' => string, 'domain' => string, 'region' => 'us'|'eu' (default 'us')]
 */
final class MailgunClient implements ApiSenderInterface
{
    public function send(array $credentials, array $message): array
    {
        $region = $credentials['region'] ?? 'us';
        $host = $region === 'eu' ? 'api.eu.mailgun.net' : 'api.mailgun.net';
        $domain = $credentials['domain'];

        $fields = [
            'from' => sprintf('%s <%s>', $message['from_name'] ?? $message['from_email'], $message['from_email']),
            'to' => $message['to'],
            'subject' => $message['subject'],
            'html' => $message['html'],
            'h:List-Unsubscribe' => "<{$message['unsubscribe_url']}>",
            'h:List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];

        $response = HttpClient::request(
            'POST',
            "https://{$host}/v3/{$domain}/messages",
            [
                'Authorization: Basic ' . base64_encode('api:' . $credentials['api_key']),
                'Content-Type: application/x-www-form-urlencoded',
            ],
            http_build_query($fields)
        );

        if ($response['status'] === 200) {
            return ['success' => true, 'transcript' => $response['body'], 'error' => null, 'is_hard_failure' => false];
        }

        $isHard = in_array($response['status'], [400, 401, 403], true);

        return [
            'success' => false,
            'transcript' => $response['body'],
            'error' => "Mailgun HTTP {$response['status']}: {$response['body']}",
            'is_hard_failure' => $isHard,
        ];
    }
}
