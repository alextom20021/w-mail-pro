<?php

declare(strict_types=1);

/**
 * public/dashboard/webhooks.php
 *
 * Client-facing webhook subscription management. The signing secret is
 * shown exactly once, right after creation (same "never shown again"
 * pattern as an API key) — see WebhookRepository::create().
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Core\Csrf;
use MailAI\Core\SessionAuth;
use MailAI\Security\EncryptionService;
use MailAI\Webhooks\WebhookRepository;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

SessionAuth::requireClient();

$encryption = new EncryptionService($_ENV['APP_ENCRYPTION_KEY']);
$repo = new WebhookRepository($encryption);

$flash = null;
$newSecret = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrDie();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        try {
            $result = $repo->create(trim($_POST['url'] ?? ''), $_POST['events'] ?? []);
            $newSecret = $result['secret'];
            $flash = 'Webhook created. Copy the signing secret now — it will not be shown again.';
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'toggle' && !empty($_POST['id'])) {
        $repo->setActive((int) $_POST['id'], ($_POST['active'] ?? '') === '1');
        $flash = 'Webhook updated.';
    } elseif ($action === 'delete' && !empty($_POST['id'])) {
        $repo->delete((int) $_POST['id']);
        $flash = 'Webhook deleted.';
    }
}

$webhooks = $repo->findAllWhere();

$pageTitle = 'Webhooks';
$activeNav = 'webhooks';
include __DIR__ . '/partials/layout_head.php';
?>

<?php if ($flash): ?><div class="alert alert-success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($newSecret): ?>
    <div class="alert alert-warning">
        <strong>Signing secret (shown once):</strong>
        <code class="user-select-all"><?= htmlspecialchars($newSecret) ?></code>
        <div class="small mt-1">Verify each delivery's <code>X-MailAI-Signature</code> header:
            <code>sha256=</code> + HMAC-SHA256(request body, this secret).</div>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <p class="text-muted mb-0">Real-time events (send, open, click, bounce, complaint) POSTed to your endpoint as JSON, with retry/backoff on failure.</p>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addWebhookModal"><i class="bi bi-plus-lg"></i> Add Webhook</button>
</div>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>URL</th><th>Events</th><th>Status</th><th>Created</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($webhooks as $w): ?>
                <?php $events = json_decode($w['events'], true) ?: []; ?>
                <tr>
                    <td class="fw-medium text-truncate" style="max-width:280px;"><?= htmlspecialchars($w['url']) ?></td>
                    <td><?php foreach ($events as $e): ?><span class="badge bg-secondary-subtle text-secondary me-1"><?= htmlspecialchars($e) ?></span><?php endforeach; ?></td>
                    <td>
                        <span class="badge bg-<?= $w['is_active'] ? 'success' : 'secondary' ?>-subtle text-<?= $w['is_active'] ? 'success' : 'secondary' ?>">
                            <?= $w['is_active'] ? 'Active' : 'Paused' ?>
                        </span>
                    </td>
                    <td class="small text-muted"><?= htmlspecialchars($w['created_at']) ?></td>
                    <td class="text-end">
                        <form method="post" class="d-inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
                            <input type="hidden" name="active" value="<?= $w['is_active'] ? '0' : '1' ?>">
                            <button class="btn btn-sm btn-outline-secondary"><?= $w['is_active'] ? 'Pause' : 'Resume' ?></button>
                        </form>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this webhook?');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($webhooks)): ?><tr><td colspan="5" class="text-center text-muted py-4">No webhooks configured yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="addWebhookModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-header"><h5 class="modal-title">Add Webhook</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small">Endpoint URL (https only)</label>
                    <input name="url" type="url" class="form-control" placeholder="https://yourapp.com/webhooks/mailai" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Events</label>
                    <?php foreach (\MailAI\Webhooks\WebhookRepository::VALID_EVENTS as $e): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="events[]" value="<?= $e ?>" id="ev-<?= $e ?>" checked>
                            <label class="form-check-label small" for="ev-<?= $e ?>"><?= ucfirst($e) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Create Webhook</button></div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/partials/layout_foot.php'; ?>
