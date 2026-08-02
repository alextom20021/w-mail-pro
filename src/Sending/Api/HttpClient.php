<?php

declare(strict_types=1);

namespace MailAI\Sending\Api;

/**
 * HttpClient
 *
 * Tiny shared cURL wrapper so each vendor client isn't reimplementing
 * the same request/response plumbing. Deliberately dependency-free
 * (no Guzzle) to keep composer.json lean — these are simple JSON/form
 * REST calls, not complex streaming APIs.
 */
final class HttpClient
{
    /**
     * @return array{status: int, body: string, headers: array}
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
    {
        $ch = curl_init($url);
        $responseHeaders = [];

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim(strtolower($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        $responseBody = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            return ['status' => 0, 'body' => '', 'headers' => [], 'curl_error' => $curlError];
        }

        return ['status' => $status, 'body' => $responseBody, 'headers' => $responseHeaders];
    }
}
