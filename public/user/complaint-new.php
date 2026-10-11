<?php
require_once dirname(__DIR__, 2) . '/includes/app_init.php';
require_role(ROLE_USER);

$categories  = complaint_categories();
$maxMb       = (float)config('upload.max_size_mb', 5);
$maxFiles    = upload_max_files();
$allowedExt  = array_map('strtolower', (array)config('upload.allowed_ext', []));

$pageTitle   = 'Submit Complaint';
$layout      = 'app';
$pageScripts = ['js/pages/complaint-new.js'];
require ICARS_ROOT . '/components/header.php';
?>
<h1 class="h3 mb-1">Submit a complaint</h1>
<p class="text-muted">Describe the problem clearly. The more detail you give, the faster it can be resolved.</p>

<div class="card"><div class="card-body p-4">
    <div id="complaint-alert" class="alert alert-danger d-none" role="alert"></div>

    <form id="complaint-form" novalidate enctype="multipart/form-data"
          data-max-bytes="<?= e((int)($maxMb * 1048576)) ?>" data-max-files="<?= e($maxFiles) ?>"
          data-allowed="<?= e(implode(',', $allowedExt)) ?>">
        <input type="hidden" name="form_token" value="<?= e(form_token_issue('complaint')) ?>">

        <?= form_field('title', 'Title', 'text', '', null, 'required|min:5|max:150',
            ['maxlength' => 150, 'placeholder' => 'Short summary, e.g. "Wi-Fi not working in Lab 3"'], '5-150 characters.') ?>

        <div class="mb-3">
            <label for="f-description" class="form-label">Description</label>
            <textarea class="form-control" id="f-description" name="description" rows="6" maxlength="5000"
                      data-rules="required|min:20|max:5000"
                      placeholder="What happened? When did it start? Who is affected?"></textarea>
            <div class="invalid-feedback"></div>
            <div class="form-text d-flex justify-content-between">
                <span>At least 20 characters.</span><span><span id="desc-count">0</span> / 5000</span>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6"><?= form_field('location', 'Location', 'text', '', null, 'required|min:3|max:150',
                ['maxlength' => 150, 'placeholder' => 'e.g. Building B, Room 402'], 'Where did this happen?') ?></div>
            <div class="col-md-6 mb-3">
                <label for="f-category_id" class="form-label">Category <span class="text-muted">(optional)</span></label>
                <select class="form-select" id="f-category_id" name="category_id" data-rules="">
                    <option value="">Not sure / let the system decide</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= e($c['id']) ?>"><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="invalid-feedback"></div>
                <div class="form-text">Leave blank if you are unsure; it will be categorised for you.</div>
            </div>
        </div>

        <div class="mb-4">
            <label for="f-attachments" class="form-label">Attachments <span class="text-muted">(optional)</span></label>
            <input type="file" class="form-control" id="f-attachments" name="attachments[]" multiple
                   accept="<?= e('.' . implode(',.', $allowedExt)) ?>">
            <div id="attachments-error" class="text-danger small mt-1 d-none"></div>
            <ul id="attachments-list" class="list-unstyled small mt-2 mb-0"></ul>
            <div class="form-text">Up to <?= e($maxFiles) ?> files, <?= e($maxMb) ?> MB each (<?= e(implode(', ', $allowedExt)) ?>).</div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary" id="btn-submit">Submit complaint</button>
            <a href="<?= e(url('user/complaints.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div></div>
<?php require ICARS_ROOT . '/components/footer.php'; ?>
