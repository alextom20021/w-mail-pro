<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\AI\ListCleaner;
use MailAI\Core\ContactListRepository;
use MailAI\Core\ContactRepository;
use MailAI\Core\Csrf;
use MailAI\Core\Database;
use MailAI\Core\SessionAuth;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

SessionAuth::requireClient();

$listRepo = new ContactListRepository();
$flash = null;
$cleanResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrDie();

    if (($_POST['action'] ?? '') === 'create_list' && !empty($_POST['name'])) {
        $listRepo->create(trim($_POST['name']), $_POST['description'] ?? null);
        $flash = 'List created.';
    }

    if (($_POST['action'] ?? '') === 'import_csv' && !empty($_FILES['csv']['tmp_name'])) {
        $listId = (int) $_POST['list_id'];
        $contactRepo = new ContactRepository();
        $handle = fopen($_FILES['csv']['tmp_name'], 'r');
        $header = fgetcsv($handle);
        $header = array_map(fn($h) => strtolower(trim($h)), $header ?: []);
        $results = ['inserted' => 0, 'duplicate' => 0, 'suppressed' => 0, 'invalid' => 0];

        while (($row = fgetcsv($handle)) !== false) {
            $assoc = array_combine($header, array_pad($row, count($header), null));
            $email = $assoc['email'] ?? '';
            unset($assoc['email']);
            $outcome = $contactRepo->importCsvRow($listId, $email, array_filter($assoc));
            $results[$outcome]++;
        }
        fclose($handle);
        $listRepo->refreshContactCount($listId);

        $flash = "Import complete: {$results['inserted']} added, {$results['duplicate']} duplicates skipped, " .
            "{$results['suppressed']} already suppressed, {$results['invalid']} invalid.";
    }

    if (($_POST['action'] ?? '') === 'ai_clean' && !empty($_POST['list_id'])) {
        $cleanResult = (new ListCleaner(Database::connection()))->cleanCurrentClientList((int) $_POST['list_id']);
        $flash = "AI list clean: scanned {$cleanResult['scanned']}, flagged {$cleanResult['flagged']}, suppressed {$cleanResult['suppressed']}.";
    }
}

$lists = $listRepo->findAllWhere();

$pageTitle = 'Contact Lists';
$activeNav = 'lists';
include __DIR__ . '/partials/layout_head.php';
?>

<?php if ($flash): ?><div class="alert alert-success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <p class="text-muted mb-0">Every import is checked against your suppression list automatically. AI list cleaning flags risky addresses.</p>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createListModal"><i class="bi bi-plus-lg"></i> New List</button>
</div>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Name</th><th>Contacts</th><th>Created</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($lists as $l): ?>
                <tr>
                    <td class="fw-medium"><?= htmlspecialchars($l['name']) ?></td>
                    <td><?= number_format((int) $l['contact_count']) ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($l['created_at']) ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#importModal-<?= (int) $l['id'] ?>">Import CSV</button>
                        <form method="post" class="d-inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="ai_clean">
                            <input type="hidden" name="list_id" value="<?= (int) $l['id'] ?>">
                            <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-robot"></i> AI Clean</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($lists)): ?><tr><td colspan="4" class="text-center text-muted py-4">No lists yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php foreach ($lists as $l): ?>
    <div class="modal fade" id="importModal-<?= (int) $l['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <form method="post" enctype="multipart/form-data" class="modal-content">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="import_csv">
                <input type="hidden" name="list_id" value="<?= (int) $l['id'] ?>">
                <div class="modal-header"><h5 class="modal-title">Import CSV to "<?= htmlspecialchars($l['name']) ?>"</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="small text-muted">CSV must have an <code>email</code> column header. <code>first_name</code>/<code>last_name</code> are recognized; other columns become custom fields.</p>
                    <input type="file" name="csv" accept=".csv" class="form-control" required>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-primary">Import</button></div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<div class="modal fade" id="createListModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create_list">
            <div class="modal-header"><h5 class="modal-title">New Contact List</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input name="name" class="form-control mb-2" placeholder="List name" required>
                <textarea name="description" class="form-control" placeholder="Description (optional)" rows="2"></textarea>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Create</button></div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/partials/layout_foot.php'; ?>
