<?php

declare(strict_types=1);

namespace MailAI\Sending;

use MailAI\Sending\Api\ApiSenderInterface;
use MailAI\Sending\Api\MailgunClient;
use MailAI\Sending\Api\PostmarkClient;
use MailAI\Sending\Api\SendGridClient;
use MailAI\Sending\Api\SesClient;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use RuntimeException;

/**
 * MailDispatcher
 *
 * Sends one message through a given sending_connections row's decrypted
 * credentials. Dispatches to SMTP (via PHPMailer) or one of the API
 * providers depending on connection `type`. Every send appends the
 * mandatory compliance footer + List-Unsubscribe header — this is NOT
 * optional per client config; see composeMessage().
 */
final class MailDispatcher
{
    /**
     * @param array $connection  Decrypted sending_connections row + 'credentials' (decrypted array).
     * @param array $job         outbox row.
     * @param string $unsubscribeUrl One-click unsubscribe link for this contact.
     * @return array{success: bool, transcript: ?string, error: ?string, is_hard_failure: bool}
     */
    public function send(array $connection, array $job, string $unsubscribeUrl): array
    {
        return match ($connection['type']) {
            'smtp' => $this->sendViaSmtp($connection, $job, $unsubscribeUrl),
            'sendgrid', 'mailgun', 'ses', 'postmark' => $this->sendViaApi($connection, $job, $unsubscribeUrl),
            default => ['success' => false, 'transcript' => null, 'error' => 'Unknown connection type', 'is_hard_failure' => true],
        };
    }

    private function sendViaSmtp(array $connection, array $job, string $unsubscribeUrl): array
    {
        $creds = $connection['credentials'];
        $mail = new PHPMailer(true);
        $transcript = '';
        $mail->Debugoutput = function ($str) use (&$transcript) {
            $transcript .= $str . "\n";
        };
        $mail->SMTPDebug = 2;

        try {
            $mail->isSMTP();
            $mail->Host = $creds['host'];
            $mail->Port = (int) $creds['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $creds['username'];
            $mail->Password = $creds['password'];
            $mail->SMTPSecure = $creds['encryption'] ?? PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Timeout = 20;

            $mail->setFrom($creds['from_email'] ?? $creds['username'], $creds['from_name'] ?? '');
            $mail->addAddress($job['to_email']);
            $mail->Subject = $job['subject'];
            $mail->isHTML(true);
            $mail->Body = $this->appendComplianceFooter($job['html_body'], $unsubscribeUrl);
            $mail->addCustomHeader('List-Unsubscribe', "<{$unsubscribeUrl}>");
            $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

            // DKIM: prefer PHPMailer's built-in signer for SMTP sends (it's
            // battle-tested against real MTA quirks) over the hand-rolled
            // DkimSigner, which exists for raw-MIME/API-send paths where
            // PHPMailer isn't in the loop. Only signs if the connection's
            // domain has DKIM configured (see DomainRepository).
            if (!empty($connection['dkim'])) {
                $mail->DKIM_domain = $connection['dkim']['domain'];
                $mail->DKIM_selector = $connection['dkim']['selector'];
                $mail->DKIM_private_string = $connection['dkim']['private_key_pem'];
                $mail->DKIM_identity = $mail->From;
            }

            $mail->send();

            return ['success' => true, 'transcript' => $transcript, 'error' => null, 'is_hard_failure' => false];
        } catch (PHPMailerException $e) {
            $isHard = $this->isHardFailure($mail->ErrorInfo);

            return ['success' => false, 'transcript' => $transcript, 'error' => $mail->ErrorInfo, 'is_hard_failure' => $isHard];
        }
    }

    private function sendViaApi(array $connection, array $job, string $unsubscribeUrl): array
    {
        $client = $this->apiClientFor($connection['type']);
        $creds = $connection['credentials'];

        $message = [
            'to' => $job['to_email'],
            'subject' => $job['subject'],
            'html' => $this->appendComplianceFooter($job['html_body'], $unsubscribeUrl),
            'from_email' => $creds['from_email'] ?? '',
            'from_name' => $creds['from_name'] ?? '',
            'unsubscribe_url' => $unsubscribeUrl,
            'headers' => [],
        ];

        return $client->send($creds, $message);
    }

    private function apiClientFor(string $type): ApiSenderInterface
    {
        return match ($type) {
            'sendgrid' => new SendGridClient(),
            'mailgun' => new MailgunClient(),
            'ses' => new SesClient(),
            'postmark' => new PostmarkClient(),
            default => throw new RuntimeException("Unknown API connection type: {$type}"),
        };
    }

    private function appendComplianceFooter(string $html, string $unsubscribeUrl): string
    {
        $footer = <<<HTML
            <div style="margin-top:24px;padding-top:16px;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;">
                You are receiving this email because you opted in to updates from this sender.
                <a href="{$unsubscribeUrl}">Unsubscribe</a> at any time.
            </div>
            HTML;

        return $html . $footer;
    }

    private function isHardFailure(string $errorInfo): bool
    {
        // 5xx SMTP codes and known permanent-failure phrases are hard bounces;
        // everything else (4xx, timeouts, greylisting) is treated as soft/retryable.
        return (bool) preg_match('/\b5\d{2}\b|does not exist|no such user|mailbox unavailable|user unknown/i', $errorInfo);
    }
}
