<?php

declare(strict_types=1);

namespace MailAI\Sending\Api;

/**
 * AwsSigV4Signer
 *
 * Minimal AWS Signature Version 4 implementation for signing SES API
 * requests without pulling in the full AWS SDK for PHP (which drags in a
 * large dependency tree for one endpoint). Implements just enough of the
 * spec for a single JSON POST request — see AWS's "Signing AWS API
 * requests" documentation if extending this to other AWS services.
 */
final class AwsSigV4Signer
{
    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region,
        private readonly string $service = 'ses',
    ) {
    }

    /**
     * @return array<string, string> Headers to attach to the request (includes Authorization, X-Amz-Date, Host).
     */
    public function signRequest(string $method, string $host, string $path, string $body): array
    {
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        $canonicalHeaders = "content-type:application/json\nhost:{$host}\nx-amz-date:{$amzDate}\n";
        $signedHeaders = 'content-type;host;x-amz-date';
        $payloadHash = hash('sha256', $body);

        $canonicalRequest = implode("\n", [
            $method,
            $path,
            '', // no query string for this POST endpoint
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = "{$dateStamp}/{$this->region}/{$this->service}/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->deriveSigningKey($dateStamp);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$credentialScope}, " .
            "SignedHeaders={$signedHeaders}, Signature={$signature}";

        return [
            'Authorization' => $authorization,
            'X-Amz-Date' => $amzDate,
            'Host' => $host,
            'Content-Type' => 'application/json',
        ];
    }

    private function deriveSigningKey(string $dateStamp): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $this->service, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
