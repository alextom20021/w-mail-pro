<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\AI\ContentScorer;
use MailAI\Core\CampaignRepository;
use MailAI\Core\ClientContext;
use MailAI\Core\ContactListRepository;
use MailAI\Core\Csrf;
use MailAI\Core\Database;
use MailAI\Core\SessionAuth;
use MailAI\Sending\CampaignQueueingService;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

SessionAuth::requireClient();
$db = Database::connection();

$campaignRepo = new CampaignRepository();
$listRepo = new ContactListRepository();
$flash = null;
$scoreResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrDie();

    if (($_POST['action'] ?? '') === 'create_template') {
        $scorer = new ContentScorer();
        $scoreResult = $scorer->score($_POST['subject'] ?? '', $_POST['html_body'] ?? '');

        $stmt = $db->prepare(
            'INSERT INTO email_templates (client_id, name, subject, html_body, spam_score, spam_score_notes, created_at)
             VALUES (:client_id, :name, :subject, :html_body, :score, :notes, NOW())'
        );
        $stmt->execute([
            'client_id' => ClientContext::clientId(),
            'name' => $_POST['template_name'] ?? 'Untitled',
            'subject' => $_POST['subject'] ?? '',
            'html_body' => $_POST['html_body'] ?? '',
            'score' => $scoreResult['score'],
            'notes' => json_encode($scoreResult['issues']),
        ]);
        $flash = "Template saved. Spam-risk score: {$scoreResult['score']}/100 ({$scoreResult['risk_level']}).";
    }

    if (($_POST['action'] ?? '') === 'create_campaign') {
        $campaignRepo->create($_POST['name'] ?? 'Untitled Campaign', (int) $_POST['template_id'], (int) $_POST['list_id']);
        $flash = 'Campaign created as a draft. Review it, then click Send.';
    }

    if (($_POST['action'] ?? '') === 'send_campaign') {
        $campaignId = (int) $_POST['campaign_id'];
        $stmt = $db->prepare('SELECT * FROM campaigns WHERE id = :id AND client_id = :client_id');
        $stmt->execute(['id' => $campaignId, 'client_id' => ClientContext::clientId()]);
        $campaign = $stmt->fetch();

        $tplStmt = $db->prepare('SELECT subject, html_body FROM email_templates WHERE id = :id');
        $tplStmt->execute(['id' => $campaign['template_id'] ?? 0]);
        $template = $tplStmt->fetch();

        if ($campaign && $template) {
            try {
                $count = (new CampaignQueueingService($db))->enqueue($campaignId, $template['subject'], $template['html_body'], (int) $campaign['list_id']);
                $flash = "Campaign queued: {$count} emails handed to the worker.";
            } catch (\RuntimeException $e) {
                $flash = 'Could not send: ' . $e->getMessage();
            }
        }
    }
}

$campaigns = $campaignRepo->findAllWhere();
$templates = $db->prepare('SELECT id, name, subject, spam_score FROM email_templates WHERE client_id = :client_id ORDER BY id DESC');
$templates->execute(['client_id' => ClientContext::clientId()]);
$templates = $templates->fetchAll();
$lists = $listRepo->findAllWhere();

$pageTitle = 'Campaigns';
$activeNav = 'campaigns';
include __DIR__ . '/partials/layout_head.php';
?>

<?php if ($flash): ?><div class="alert alert-success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<?php if ($scoreResult && !empty($scoreResult['issues'])): ?>
<div class="alert alert-warning">
    <strong>Content review (heuristic, not a guarantee):</strong>
    <ul class="mb-0 small">
        <?php foreach ($scoreResult['issues'] as $issue): ?><li><?= htmlspecialchars($issue) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card table-card h-100">
            <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold">Templates</h6>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newTemplateModal"><i class="bi bi-plus-lg"></i> New</button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Name</th><th>Subject</th><th>Spam Score</th></tr></thead>
                    <tbody>
                    <?php foreach ($templates as $t): ?>
                        <tr><td><?= htmlspecialchars($t['name']) ?></td><td class="text-truncate" style="max-width:180px;"><?= htmlspecialchars($t['subject']) ?></td>
                            <td><?= $t['spam_score'] !== null ? (int) $t['spam_score'] . '/100' : '—' ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($templates)): ?><tr><td colspan="3" class="text-center text-muted py-3">No templates yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card table-card h-100">
            <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold">Campaigns</h6>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newCampaignModal"><i class="bi bi-plus-lg"></i> New</button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Name</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($campaigns as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars($c['name']) ?></td>
                            <td><span class="badge bg-secondary-subtle text-secondary"><?= htmlspecialchars($c['status'] ?? 'draft') ?></span></td>
                            <td>
                                <?php if (($c['status'] ?? 'draft') === 'draft'): ?>
                                <form method="post" onsubmit="return confirm('Send this campaign now? This cannot be undone.');">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="send_campaign">
                                    <input type="hidden" name="campaign_id" value="<?= (int) $c['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Send</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($campaigns)): ?><tr><td colspan="3" class="text-center text-muted py-3">No campaigns yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="newTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create_template">
            <div class="modal-header"><h5 class="modal-title">New Template</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input name="template_name" class="form-control mb-2" placeholder="Template name" required>
                <input name="subject" class="form-control mb-2" placeholder="Subject line" required>
                <textarea name="html_body" class="form-control" rows="8" placeholder="HTML body" required></textarea>
                <p class="small text-muted mt-2 mb-0">Saved with an automatic heuristic spam-risk score — not a guarantee of inbox placement, but a useful first check.</p>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Save & Score</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="newCampaignModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create_campaign">
            <div class="modal-header"><h5 class="modal-title">New Campaign</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input name="name" class="form-control mb-2" placeholder="Campaign name" required>
                <select name="template_id" class="form-select mb-2" required>
                    <option value="">Choose template…</option>
                    <?php foreach ($templates as $t): ?><option value="<?= (int) $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>
                </select>
                <select name="list_id" class="form-select" required>
                    <option value="">Choose list…</option>
                    <?php foreach ($lists as $l): ?><option value="<?= (int) $l['id'] ?>"><?= htmlspecialchars($l['name']) ?> (<?= (int) $l['contact_count'] ?>)</option><?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Create Draft</button></div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/partials/layout_foot.php'; ?>
