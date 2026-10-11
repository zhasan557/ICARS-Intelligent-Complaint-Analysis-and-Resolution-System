<?php
require_once dirname(__DIR__, 2) . '/includes/app_init.php';
require_role(ROLE_USER);

$pageTitle   = 'My Complaints';
$layout      = 'app';
$pageScripts = ['js/pages/complaints-list.js'];
require ICARS_ROOT . '/components/header.php';
?>
<div id="complaints-app">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">My Complaints</h1>
        <a href="<?= e(url('user/complaint-new.php')) ?>" class="btn btn-primary">Submit complaint</a>
    </div>

    <div class="card mb-3"><div class="card-body">
        <form id="filter-form" class="row g-2" autocomplete="off">
            <div class="col-md-7"><input type="search" class="form-control" id="filter-q" placeholder="Search by title, number, location or description" maxlength="100"></div>
            <div class="col-md-5"><select class="form-select" id="filter-status"><option value="">All statuses</option></select></div>
        </form>
    </div></div>

    <div class="card"><div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr>
                <th>Number</th><th>Title</th><th>Category</th><th>Status</th><th>Submitted</th><th>Last update</th>
            </tr></thead>
            <tbody id="complaints-body"><tr><td colspan="6" class="text-center text-muted py-4">Loading…</td></tr></tbody>
        </table>
    </div></div>
    <div class="d-flex justify-content-between align-items-center mt-3 small">
        <span id="complaints-summary" class="text-muted"></span>
        <div class="btn-group">
            <button class="btn btn-outline-secondary btn-sm" id="btn-prev">Previous</button>
            <button class="btn btn-outline-secondary btn-sm" id="btn-next">Next</button>
        </div>
    </div>
</div>
<?php require ICARS_ROOT . '/components/footer.php'; ?>
