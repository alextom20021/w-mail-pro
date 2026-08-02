<?php

declare(strict_types=1);

namespace MailAI\Sending\Api;

/**
 * SesClient — wraps Amazon SES v2 SendEmail API, signed with SigV4.
 * Credentials expected: ['access_key' => string, 'secret_key' => string, 'region' => string, e.g. 'us-east-1']
 */
final class SesClient implements ApiSenderInterface
{
    public function send(array $credentials, array $message): array
    {
        $region = $credentials['region'] ?? 'us-east-1';
        $host = "email.{$region}.amazonaws.com";
        $path = '/v2/email/outbound-emails';

        $payload = [
            'FromEmailAddress' => sprintf('%s <%s>', $message['from_name'] ?? $message['from_email'], $message['from_email']),
            'Destination' => ['ToAddresses' => [$message['to']]],
            'Content' => [
                'Simple' => [
                    'Subject' => ['Data' => $message['subject'], 'Charset' => 'UTF-8'],
                    'Body' => ['Html' => ['Data' => $message['html'], 'Charset' => 'UTF-8']],
                    'Headers' => [
                        ['Name' => 'List-Unsubscribe', 'Value' => "<{$message['unsubscribe_url']}>"],
                        ['Name' => 'List-Unsubscribe-Post', 'Value' => 'List-Unsubscribe=One-Click'],
                    ],
                ],
            ],
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $signer = new AwsSigV4Signer($credentials['access_key'], $credentials['secret_key'], $region);
        $signedHeaders = $signer->signRequest('POST', $host, $path, $body);

        $headers = [];
        foreach ($signedHeaders as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }

        $response = HttpClient::request('POST', "https://{$host}{$path}", $headers, $body);

        if ($response['status'] >= 200 && $response['status'] < 300) {
            return ['success' => true, 'transcript' => $response['body'], 'error' => null, 'is_hard_failure' => false];
        }

        // SES returns 400 for MessageRejected (invalid recipient, content
        // policy violation) — hard failure. 429/5xx are retryable.
        $isHard = $response['status'] === 400;

        return [
            'success' => false,
            'transcript' => $response['body'],
            'error' => "SES HTTP {$response['status']}: {$response['body']}",
            'is_hard_failure' => $isHard,
        ];
    }
}
