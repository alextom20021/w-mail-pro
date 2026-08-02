<?php

declare(strict_types=1);

namespace MailAI\Sending;

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

            $mail->send();

            return ['success' => true, 'transcript' => $transcript, 'error' => null, 'is_hard_failure' => false];
        } catch (PHPMailerException $e) {
            $isHard = $this->isHardFailure($mail->ErrorInfo);

            return ['success' => false, 'transcript' => $transcript, 'error' => $mail->ErrorInfo, 'is_hard_failure' => $isHard];
        }
    }

    private function sendViaApi(array $connection, array $job, string $unsubscribeUrl): array
    {
        // NOTE (Phase 1 scope): API-provider clients (SendGrid/Mailgun/SES/Postmark)
        // are stubbed here — each needs its own thin HTTP client mirroring the
        // AIProviderInterface pattern (one class per vendor, unified return shape).
        // Wiring this up is Phase 2's connection-manager milestone; SMTP is fully
        // functional now so the worker/rotation/compliance pipeline can be tested
        // end-to-end without waiting on all four vendor integrations.
        throw new RuntimeException(
            "API connection type '{$connection['type']}' is not yet implemented — Phase 2. Use an SMTP connection for now."
        );
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
