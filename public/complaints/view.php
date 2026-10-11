<?php
require_once dirname(__DIR__, 2) . '/includes/app_init.php';
require_login();

$id = (int)get_string('id');
$c  = $id > 0 ? complaint_find($id) : null;

// Same 404 for "does not exist" and "not yours" so complaint ids cannot be probed.
if (!$c || !complaint_can_view($c)) {
    abort(404, 'Complaint not found.');
}

$isAdminView = auth_has_role(ROLE_ADMIN);
$isOwner     = (int)$c['user_id'] === auth_id();
$attachments = complaint_attachments($id);
$history     = complaint_history($id, $isAdminView);     // users only receive public events

$pageTitle = $c['complaint_no'];
$layout    = 'app';
require ICARS_ROOT . '/components/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small">
    <?php if ($isOwner): ?><li class="breadcrumb-item"><a href="<?= e(url('user/complaints.php')) ?>">My Complaints</a></li><?php endif; ?>
    <?php if ($isAdminView): ?><li class="breadcrumb-item"><a href="<?= e(url('admin/complaints.php')) ?>">All Complaints</a></li><?php endif; ?>
    <li class="breadcrumb-item active"><?= e($c['complaint_no']) ?></li>
</ol></nav>
<?php if ($isAdminView): ?>
    <div class="mb-3"><a class="btn btn-sm btn-primary" href="<?= e(url('admin/complaint.php?id=' . (int)$c['id'])) ?>">Manage this complaint</a></div>
<?php endif; ?>
<?php require ICARS_ROOT . '/components/complaint_detail.php'; ?>
<?php require ICARS_ROOT . '/components/footer.php'; ?>
