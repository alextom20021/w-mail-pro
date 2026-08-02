<?php

declare(strict_types=1);

namespace MailAI\Sending\Api;

/**
 * ApiSenderInterface
 *
 * Every third-party ESP client (SendGrid, Mailgun, SES, Postmark)
 * implements this so MailDispatcher can treat API-based sending exactly
 * like SMTP from the outside — same rotation pool, same result shape.
 */
interface ApiSenderInterface
{
    /**
     * @param array $credentials Decrypted connection credentials (vendor-specific keys).
     * @param array $message     ['to' => string, 'subject' => string, 'html' => string,
     *                            'from_email' => string, 'from_name' => string,
     *                            'unsubscribe_url' => string, 'headers' => array]
     * @return array{success: bool, transcript: ?string, error: ?string, is_hard_failure: bool}
     */
    public function send(array $credentials, array $message): array;
}
