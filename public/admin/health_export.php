<?php

declare(strict_types=1);

/**
 * public/admin/health_export.php
 *
 * Exportable platform health report (spec 1.1) — CSV or PDF snapshot of
 * the Global Health dashboard's numbers: overview counters, per-ISP
 * deliverability, and per-client 7-day volume. Super-admin only.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Core\Database;
use MailAI\Core\SessionAuth;
use MailAI\Export\CsvExporter;
use MailAI\Export\SimplePdfWriter;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

SessionAuth::requireSuperAdmin();
$db = Database::connection();

$format = ($_GET['format'] ?? 'csv') === 'pdf' ? 'pdf' : 'csv';

$overview = $db->query(
    "SELECT
        (SELECT COUNT(*) FROM clients) AS total_clients,
        (SELECT COUNT(*) FROM clients WHERE status = 'active' AND deleted_at IS NULL) AS active_clients,
        (SELECT COUNT(*) FROM outbox WHERE status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)) AS sent_24h,
        (SELECT COUNT(*) FROM outbox WHERE status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS sent_7d,
        (SELECT COUNT(*) FROM outbox WHERE status = 'queued') AS queue_depth,
        (SELECT COUNT(*) FROM sending_connections WHERE status = 'quarantined') AS quarantined_connections"
)->fetch();

$isp = $db->query(
    "SELECT isp, SUM(sent) AS sent, SUM(delivered) AS delivered, SUM(opened) AS opened,
            SUM(hard_bounced + soft_bounced) AS bounced, SUM(complained) AS complained
     FROM isp_deliverability_stats
     WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY isp ORDER BY sent DESC"
)->fetchAll();

$perClient = $db->query(
    "SELECT c.company_name, c.plan, c.status,
            COALESCE(SUM(s.sent), 0) AS sent_7d, COALESCE(SUM(s.complained), 0) AS complaints_7d
     FROM clients c
     LEFT JOIN isp_deliverability_stats s ON s.client_id = c.id AND s.stat_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
     GROUP BY c.id ORDER BY sent_7d DESC LIMIT 100"
)->fetchAll();

$rows = [];
$rows[] = ['PLATFORM OVERVIEW', '', '', '', ''];
foreach ($overview as $k => $v) {
    $rows[] = [str_replace('_', ' ', (string) $k), (string) $v, '', '', ''];
}
$rows[] = ['', '', '', '', ''];
$rows[] = ['ISP (7d)', 'Sent', 'Delivered', 'Bounced', 'Complained'];
foreach ($isp as $r) {
    $rows[] = [$r['isp'], (string) $r['sent'], (string) $r['delivered'], (string) $r['bounced'], (string) $r['complained']];
}
$rows[] = ['', '', '', '', ''];
$rows[] = ['CLIENT (7d)', 'Plan', 'Status', 'Sent', 'Complaints'];
foreach ($perClient as $r) {
    $rows[] = [$r['company_name'], $r['plan'], $r['status'], (string) $r['sent_7d'], (string) $r['complaints_7d']];
}

$filename = 'platform-health-' . date('Ymd-His');

if ($format === 'csv') {
    (new CsvExporter())->stream($filename . '.csv', $rows, ['Metric / Name', 'Value / Sent', 'Delivered / Status', 'Bounced / Sent', 'Complained']);
}

$pdf = (new SimplePdfWriter())->render(
    'MailAI Platform Health Report — ' . gmdate('Y-m-d H:i') . ' UTC',
    ['Metric / Name', 'Value', 'Col 3', 'Col 4', 'Col 5'],
    $rows
);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
