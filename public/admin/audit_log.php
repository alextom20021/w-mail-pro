<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Core\Database;
use MailAI\Core\SessionAuth;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

SessionAuth::requireSuperAdmin();
$db = Database::connection();

$rows = $db->query(
    "SELECT a.*, c.company_name FROM ai_audit_log a
     LEFT JOIN clients c ON c.id = a.client_id
     ORDER BY a.created_at DESC LIMIT 200"
)->fetchAll();

$pageTitle = 'AI Audit Log';
$activeNav = 'audit';
include __DIR__ . '/partials/layout_head.php';
?>

<p class="text-muted">Every AI tool call across every client — the platform's full transparency trail. This is what "why did the AI do that" gets answered from.</p>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle small">
            <thead><tr><th>Time</th><th>Client</th><th>Tool</th><th>Provider</th><th>Autonomy</th><th>Status</th><th>Summary / Input</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="text-nowrap"><?= htmlspecialchars($r['created_at']) ?></td>
                    <td><?= htmlspecialchars($r['company_name'] ?? 'platform') ?></td>
                    <td><code><?= htmlspecialchars($r['tool_name']) ?></code></td>
                    <td><?= htmlspecialchars($r['provider'] ?? '—') ?></td>
                    <td class="text-capitalize"><?= htmlspecialchars(str_replace('_', ' ', $r['autonomy_level'])) ?></td>
                    <td>
                        <?php $statusClass = ['executed' => 'success', 'pending_approval' => 'warning', 'proposed' => 'secondary', 'rejected' => 'danger', 'failed' => 'danger', 'approved' => 'info'][$r['status']] ?? 'secondary'; ?>
                        <span class="badge bg-<?= $statusClass ?>-subtle text-<?= $statusClass ?>"><?= htmlspecialchars($r['status']) ?></span>
                    </td>
                    <td class="text-truncate" style="max-width:280px;" title="<?= htmlspecialchars($r['input_json'] ?? '') ?>"><?= htmlspecialchars($r['decision_summary'] ?? $r['input_json'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="7" class="text-center text-muted py-4">No AI actions logged yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/partials/layout_foot.php'; ?>
