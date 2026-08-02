<?php

declare(strict_types=1);

namespace MailAI\AI;

/**
 * ContentScorer
 *
 * Heuristic spam-risk scoring for subject/HTML content, run before a
 * campaign sends. IMPORTANT — read this before trusting the number:
 * real mailbox-provider spam filters are proprietary black boxes (Gmail,
 * Outlook, Yahoo do not publish their models), so this can only ever be
 * a heuristic approximation of well-known risk signals, not a ground-
 * truth prediction. It's deliberately conservative and directional: use
 * it to catch obvious problems (spammy phrasing, too many links, no
 * text content, no unsubscribe), not as a pass/fail gate a client blindly
 * trusts. The AI chat assistant can layer an LLM's more nuanced read on
 * top of this (see AIToolHandlers — a future `review_content` tool would
 * feed this heuristic score plus the raw content to the LLM for a
 * second opinion), but the deterministic checks here always run first
 * since they're free and instant.
 *
 * Score: 0 (low risk) - 100 (high risk). Nothing here is a guarantee.
 */
final class ContentScorer
{
    private const SPAM_TRIGGER_PHRASES = [
        'act now', 'limited time', 'click here', '100% free', 'buy now',
        'winner', 'congratulations', 'risk-free', 'no obligation', 'cash bonus',
        'earn money', 'work from home', 'guaranteed', 'urgent', 'act immediately',
    ];

    public function score(string $subject, string $htmlBody): array
    {
        $issues = [];
        $score = 0;

        // Subject line checks
        $capsRatio = $this->uppercaseRatio($subject);
        if ($capsRatio > 0.5 && strlen($subject) > 5) {
            $score += 15;
            $issues[] = 'Subject line is mostly uppercase — a strong spam-filter trigger.';
        }
        if (substr_count($subject, '!') > 1) {
            $score += 10;
            $issues[] = 'Multiple exclamation marks in subject line.';
        }
        if (preg_match('/\$\d|\d+% off|free money/i', $subject)) {
            $score += 10;
            $issues[] = 'Subject contains price/discount bait patterns commonly flagged by filters.';
        }

        // Body content checks
        $plainText = trim(strip_tags($htmlBody));
        $textLength = strlen($plainText);

        $triggerHits = 0;
        $lowerBody = strtolower($plainText);
        foreach (self::SPAM_TRIGGER_PHRASES as $phrase) {
            if (str_contains($lowerBody, $phrase)) {
                $triggerHits++;
            }
        }
        if ($triggerHits > 0) {
            $score += min(25, $triggerHits * 6);
            $issues[] = "Found {$triggerHits} common spam-trigger phrase(s) in body content.";
        }

        $linkCount = preg_match_all('/<a\s+[^>]*href=/i', $htmlBody);
        if ($linkCount > 10) {
            $score += 15;
            $issues[] = "High link count ({$linkCount}) — filters weigh link density heavily.";
        }

        $imageCount = preg_match_all('/<img\s+[^>]*src=/i', $htmlBody);
        if ($textLength < 100 && $imageCount > 0) {
            $score += 20;
            $issues[] = 'Very little text relative to images — a classic "image-only spam" pattern.';
        }

        if ($textLength < 20) {
            $score += 15;
            $issues[] = 'Almost no text content in the email body.';
        }

        if (!str_contains($lowerBody, 'unsubscribe')) {
            $score += 20;
            $issues[] = 'No visible "unsubscribe" text found in body — compliance and deliverability risk (this platform ' .
                'appends a footer automatically, but a client-authored unsubscribe mention is still a positive signal to filters).';
        }

        $score = min(100, $score);

        return [
            'score' => $score,
            'risk_level' => match (true) {
                $score >= 60 => 'high',
                $score >= 30 => 'medium',
                default => 'low',
            },
            'issues' => $issues,
            'disclaimer' => 'Heuristic estimate only — not a guarantee of inbox placement. Real spam filters are proprietary and vary by provider.',
        ];
    }

    private function uppercaseRatio(string $text): float
    {
        $letters = preg_replace('/[^a-zA-Z]/', '', $text);
        if ($letters === '') {
            return 0.0;
        }
        $upper = preg_replace('/[^A-Z]/', '', $letters);

        return strlen($upper) / strlen($letters);
    }
}
