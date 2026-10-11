<?php
require_once dirname(__DIR__, 2) . '/includes/app_init.php';
require_role(ROLE_ADMIN);

$id = (int)get_string('id');
$c  = $id > 0 ? complaint_find($id) : null;
if (!$c) {
    abort(404, 'Complaint not found.');
}

$isAdminView  = true;
$attachments  = complaint_attachments($id);
$history      = complaint_history($id, true);            // admins also see internal events

$categories   = complaint_categories();
$levels       = complaint_levels();
$departments  = departments_all();
$staffOptions = complaint_staff_options();

ob_start();
require ICARS_ROOT . '/components/complaint_manage.php';
$sidePanel = ob_get_clean();

$pageTitle   = 'Manage ' . $c['complaint_no'];
$layout      = 'app';
$pageScripts = ['js/pages/admin-complaint.js'];
require ICARS_ROOT . '/components/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= e(url('admin/complaints.php')) ?>">All Complaints</a></li>
    <li class="breadcrumb-item active"><?= e($c['complaint_no']) ?></li>
</ol></nav>
<?php require ICARS_ROOT . '/components/complaint_detail.php'; ?>
<?php require ICARS_ROOT . '/components/footer.php'; ?>
