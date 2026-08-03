<?php

declare(strict_types=1);

/**
 * public/dashboard/analytics_export.php
 *
 * CSV/PDF export for the analytics dashboard — replaces "no dedicated
 * export endpoint yet" from the README roadmap.
 * GET params: type=isp|country|connection|timeseries, format=csv|pdf, days=1-365
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Analytics\AnalyticsService;
use MailAI\Core\Database;
use MailAI\Core\SessionAuth;
use MailAI\Export\CsvExporter;
use MailAI\Export\SimplePdfWriter;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

SessionAuth::requireClient();

$days = max(1, min(365, (int) ($_GET['days'] ?? 30)));
$type = $_GET['type'] ?? 'isp';
$format = $_GET['format'] ?? 'csv';

$analytics = new AnalyticsService(Database::connection());

[$title, $rows] = match ($type) {
    'country' => ['Analytics by Country', $analytics->byCountry($days)],
    'connection' => ['Analytics by Connection', $analytics->byConnection($days)],
    'timeseries' => ['Analytics Time Series', $analytics->timeSeries($days)],
    'failures' => ['Failure Reasons', $analytics->failureReasonBreakdown($days)],
    default => ['Analytics by ISP', $analytics->byIsp($days)],
};

$filenameBase = "mailai-analytics-{$type}-{$days}d";

if ($format === 'pdf') {
    $headers = $rows === [] ? [] : array_keys($rows[0]);
    $tableRows = array_map(fn($r) => array_map(fn($v) => (string) $v, array_values($r)), $rows);

    $pdf = SimplePdfWriter::render("{$title} — last {$days} days", $headers, $tableRows);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

CsvExporter::stream("{$filenameBase}.csv", $rows);
