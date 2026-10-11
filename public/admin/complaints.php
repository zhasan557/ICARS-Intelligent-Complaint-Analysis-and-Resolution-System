<?php
require_once dirname(__DIR__, 2) . '/includes/app_init.php';
require_role(ROLE_ADMIN);

$pageTitle   = 'All Complaints';
$layout      = 'app';
$pageScripts = ['js/pages/admin-complaints.js'];
require ICARS_ROOT . '/components/header.php';
?>
<div id="admin-complaints-app">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">All Complaints</h1>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-reset">Reset filters</button>
    </div>

    <div class="card mb-3"><div class="card-body">
        <form id="filter-form" class="row g-2" autocomplete="off">
            <div class="col-lg-6"><input type="search" class="form-control" id="filter-q" placeholder="Search number, title, location, submitter name or email" maxlength="100"></div>
            <div class="col-lg-3 col-md-6"><select class="form-select" id="filter-status"><option value="">All statuses</option></select></div>
            <div class="col-lg-3 col-md-6"><select class="form-select" id="filter-sort">
                <option value="newest">Newest first</option><option value="oldest">Oldest first</option>
                <option value="updated">Recently updated</option><option value="priority">Highest priority</option></select></div>
            <div class="col-lg-3 col-md-6"><select class="form-select" id="filter-category"><option value="">All categories</option><option value="none">No category</option></select></div>
            <div class="col-lg-3 col-md-6"><select class="form-select" id="filter-priority"><option value="">All priorities</option><option value="none">No priority</option></select></div>
            <div class="col-lg-3 col-md-6"><select class="form-select" id="filter-department"><option value="">All departments</option><option value="none">No department</option></select></div>
            <div class="col-lg-3 col-md-6"><select class="form-select" id="filter-assignee"><option value="">Any assignee</option><option value="none">Unassigned</option></select></div>
        </form>
    </div></div>

    <div class="card"><div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr>
                <th>Number</th><th>Complaint</th><th>Category</th><th>Priority</th><th>Department / Assignee</th><th>Status</th><th>Submitted</th>
            </tr></thead>
            <tbody id="complaints-body"><tr><td colspan="7" class="text-center text-muted py-4">Loading…</td></tr></tbody>
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
