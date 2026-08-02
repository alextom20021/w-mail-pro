<?php

declare(strict_types=1);

namespace MailAI\Security;

use RuntimeException;

/**
 * DkimSigner
 *
 * Generates DKIM key pairs and signs outgoing message headers/body
 * (RFC 6376, relaxed/relaxed canonicalization) using OpenSSL directly —
 * no external DKIM library dependency. The private key is stored
 * encrypted (via EncryptionService) in `domains.dkim_private_key_encrypted`
 * and only decrypted at sign time inside the worker process.
 */
final class DkimSigner
{
    /**
     * @return array{public_key: string, private_key_pem: string} public_key is the
     *         base64 body to publish in DNS (selector._domainkey.domain TXT record);
     *         private_key_pem is what gets encrypted and stored.
     */
    public static function generateKeyPair(): array
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($res === false) {
            throw new RuntimeException('Failed to generate DKIM key pair: ' . openssl_error_string());
        }

        openssl_pkey_export($res, $privateKeyPem);
        $details = openssl_pkey_get_details($res);

        // Strip PEM headers/newlines to get the raw base64 body DNS wants.
        $publicKeyPem = $details['key'];
        $publicKeyBody = preg_replace('/-----[^-]+-----|\s+/', '', $publicKeyPem);

        return ['public_key' => $publicKeyBody, 'private_key_pem' => $privateKeyPem];
    }

    /** Returns the exact TXT record value a client should publish for this domain. */
    public static function dnsRecordValue(string $publicKeyBody): string
    {
        return "v=DKIM1; k=rsa; p={$publicKeyBody}";
    }

    /**
     * Signs a message and returns the DKIM-Signature header value to prepend.
     *
     * @param string $selector      e.g. "mailai"
     * @param string $domain        signing domain, e.g. "mail.client.com"
     * @param string $privateKeyPem Decrypted PEM private key
     * @param array  $headers       ['from' => ..., 'to' => ..., 'subject' => ..., 'date' => ...] (lowercase keys)
     * @param string $body          Raw message body (before any MTA-added headers)
     */
    public static function sign(string $selector, string $domain, string $privateKeyPem, array $headers, string $body): string
    {
        $canonicalBody = self::canonicalizeBodyRelaxed($body);
        $bodyHash = base64_encode(hash('sha256', $canonicalBody, true));

        $signedHeaderNames = array_keys($headers);
        $dkimHeaderTemplate = sprintf(
            'v=1; a=rsa-sha256; c=relaxed/relaxed; d=%s; s=%s; h=%s; bh=%s; b=',
            $domain,
            $selector,
            implode(':', $signedHeaderNames),
            $bodyHash
        );

        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= self::canonicalizeHeaderRelaxed($name, $value) . "\r\n";
        }
        $canonicalHeaders .= 'dkim-signature:' . self::foldRelaxedValue($dkimHeaderTemplate);

        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            throw new RuntimeException('Invalid DKIM private key: ' . openssl_error_string());
        }

        openssl_sign($canonicalHeaders, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $signatureB64 = base64_encode($signature);

        return $dkimHeaderTemplate . $signatureB64;
    }

    private static function canonicalizeBodyRelaxed(string $body): string
    {
        // Relaxed body canonicalization: collapse WSP runs, strip trailing
        // WSP per line, remove trailing empty lines (RFC 6376 §3.4.4).
        $lines = preg_split('/\r\n|\r|\n/', $body);
        $lines = array_map(function ($line) {
            $line = preg_replace('/[ \t]+/', ' ', $line);
            return rtrim($line, " \t");
        }, $lines);

        while (!empty($lines) && end($lines) === '') {
            array_pop($lines);
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    private static function canonicalizeHeaderRelaxed(string $name, string $value): string
    {
        $name = strtolower(trim($name));
        $value = preg_replace('/\s+/', ' ', trim($value));

        return "{$name}:{$value}";
    }

    private static function foldRelaxedValue(string $value): string
    {
        return $value; // single-line for the tag list; real MTAs may fold long lines, omitted here for clarity
    }
}
