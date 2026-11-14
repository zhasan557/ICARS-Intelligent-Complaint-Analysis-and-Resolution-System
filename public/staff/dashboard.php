<?php
require_once dirname(__DIR__, 2) . '/includes/app_init.php';
require_role(ROLE_STAFF);
$assignedComplaints = complaint_assigned_to_staff((int)auth_id());

$pageTitle   = 'Staff Dashboard';
$layout      = 'app';
$pageScripts = ['js/pages/dashboard.js'];
require ICARS_ROOT . '/components/header.php';
?>
<h1 class="h3 mb-1">Staff Dashboard</h1>
<p class="text-muted">Welcome, <?= e(auth_user()['name']) ?>.</p>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small">Signed in as</div>
            <div class="fw-semibold"><?= e(auth_user()['email']) ?></div>
            <span class="badge text-bg-primary text-uppercase mt-2"><?= e(auth_role()) ?></span>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small">Server &amp; database</div>
            <div class="fw-semibold" id="health-status">Checking…</div>
            <div class="small text-muted" id="health-detail"></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small">Milestone</div>
            <div class="fw-semibold">Assigned complaints</div>
            <div class="small text-muted"><?= count($assignedComplaints) ?> open case(s)</div>
        </div></div>
    </div>
</div>
<div class="card mt-4"><div class="card-body">
    <h2 class="h5">My assigned complaints</h2>
    <?php if (!$assignedComplaints): ?><p class="small text-muted mb-0">No open complaints are assigned to you.</p><?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Reference</th><th>Complaint</th><th>Category</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach($assignedComplaints as $item): ?><tr><td><?= e($item['complaint_no']) ?></td><td><?= e($item['title']) ?></td><td><?= e($item['category']??'—') ?></td><td><?= e($item['status']) ?></td><td><a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/complaint.php?id='.(int)$item['id'])) ?>">Review</a></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</div></div>
<?php require ICARS_ROOT . '/components/footer.php'; ?>
