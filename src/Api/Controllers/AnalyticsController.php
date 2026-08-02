<?php

declare(strict_types=1);

namespace MailAI\Api\Controllers;

use MailAI\Analytics\AnalyticsService;
use MailAI\Api\JsonResponse;
use MailAI\Core\Database;

final class AnalyticsController
{
    private AnalyticsService $analytics;

    public function __construct()
    {
        $this->analytics = new AnalyticsService(Database::connection());
    }

    public function byIsp(): void
    {
        JsonResponse::ok(['isp' => $this->analytics->byIsp($this->days())]);
    }

    public function byCountry(): void
    {
        JsonResponse::ok(['country' => $this->analytics->byCountry($this->days())]);
    }

    public function byConnection(): void
    {
        JsonResponse::ok(['connections' => $this->analytics->byConnection($this->days())]);
    }

    public function timeSeries(): void
    {
        JsonResponse::ok(['series' => $this->analytics->timeSeries($this->days())]);
    }

    public function failures(): void
    {
        JsonResponse::ok(['failures' => $this->analytics->failureReasonBreakdown($this->days())]);
    }

    private function days(): int
    {
        $days = (int) ($_GET['days'] ?? 30);
        return max(1, min(365, $days));
    }
}
