<?php

declare(strict_types=1);

namespace MailAI\Tracking;

/**
 * LinkRewriter
 *
 * Injects the open-tracking pixel and rewrites <a href> links to route
 * through the click redirector, for one outgoing message. Called by the
 * worker right before dispatch — see worker/worker.php integration note.
 */
final class LinkRewriter
{
    public function __construct(
        private readonly TrackingTokenService $tokens,
        private readonly string $appUrl,
    ) {
    }

    public function rewrite(string $html, int $clientId, int $contactId, int $campaignId, ?int $outboxId = null): string
    {
        $token = $this->tokens->makeToken($clientId, $contactId, $campaignId, $outboxId);

        $html = $this->rewriteLinks($html, $token);
        $html = $this->injectPixel($html, $token);

        return $html;
    }

    private function rewriteLinks(string $html, string $token): string
    {
        return preg_replace_callback(
            '/href=["\']([^"\']+)["\']/i',
            function (array $m) use ($token) {
                $url = $m[1];
                // Don't rewrite mailto:, tel:, anchors, or the unsubscribe link
                // itself (that one carries its own distinct signed token).
                if (str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:') || str_starts_with($url, '#') || str_contains($url, '/unsubscribe')) {
                    return $m[0];
                }

                $encoded = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
                $trackedUrl = "{$this->appUrl}/track/click.php?t={$token}&u={$encoded}";

                return 'href="' . htmlspecialchars($trackedUrl, ENT_QUOTES) . '"';
            },
            $html
        );
    }

    private function injectPixel(string $html, string $token): string
    {
        $pixelTag = '<img src="' . htmlspecialchars("{$this->appUrl}/track/open.php?t={$token}", ENT_QUOTES) .
            '" width="1" height="1" alt="" style="display:block;border:0;" />';

        if (str_contains($html, '</body>')) {
            return str_replace('</body>', $pixelTag . '</body>', $html);
        }

        return $html . $pixelTag;
    }
}
