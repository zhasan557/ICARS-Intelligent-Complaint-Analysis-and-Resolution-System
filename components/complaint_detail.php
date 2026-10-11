<?php
/**
 * Shared complaint detail view (owner and admin).
 * Expects: $c (complaint_find row), $attachments, $history, $isAdminView (bool)
 * Optional: $sidePanel (HTML string rendered between "Details" and "History", used by the admin page)
 */
$isAdminView = $isAdminView ?? false;
$sidePanel   = $sidePanel ?? '';

$stages   = [STATUS_SUBMITTED, STATUS_UNDER_REVIEW, STATUS_ASSIGNED, STATUS_IN_PROGRESS, STATUS_RESOLVED, STATUS_CLOSED];
$reopened = $c['status'] === STATUS_REOPENED;
$current  = array_search($reopened ? STATUS_UNDER_REVIEW : $c['status'], $stages, true);
?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card mb-4"><div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <h1 class="h4 mb-0"><?= e($c['title']) ?></h1>
                <span class="badge <?= e(status_badge_class($c['status'])) ?> fs-6"><?= e($c['status']) ?></span>
            </div>
            <div class="text-muted small mb-3"><?= e($c['complaint_no']) ?> &middot; submitted <?= e(format_datetime($c['created_at'])) ?></div>

            <ol class="stepper mb-3" aria-label="Progress">
                <?php foreach ($stages as $i => $stage): ?>
                    <li class="<?= $i < $current ? 'done' : ($i === $current ? 'current' : '') ?>"><?= e($stage) ?></li>
                <?php endforeach; ?>
            </ol>
            <?php if ($reopened): ?>
                <div class="alert alert-danger py-2 small">This complaint was <strong>reopened</strong> and is being handled again.</div>
            <?php endif; ?>

            <h2 class="h6 text-uppercase text-muted">Description</h2>
            <div class="complaint-body"><?= nl2br(e($c['description'])) ?></div>
        </div></div>

        <div class="card"><div class="card-body p-4">
            <h2 class="h6 text-uppercase text-muted mb-3">Attachments</h2>
            <?php if (!$attachments): ?>
                <p class="text-muted small mb-0">No attachments.</p>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                <?php foreach ($attachments as $a):
                    $href  = url('api/complaints/attachment.php?id=' . (int)$a['id']);
                    $isImg = in_array($a['mime_type'], ['image/jpeg', 'image/png'], true); ?>
                    <li class="list-group-item px-0 d-flex align-items-center gap-3">
                        <?php if ($isImg): ?><img src="<?= e($href) ?>" alt="" class="attachment-thumb rounded"><?php endif; ?>
                        <div class="flex-grow-1">
                            <a href="<?= e($href) ?>" target="_blank" rel="noopener"><?= e($a['original_name']) ?></a>
                            <div class="small text-muted"><?= e(format_bytes((int)$a['file_size'])) ?></div>
                        </div>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div></div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-4"><div class="card-body">
            <h2 class="h6 text-uppercase text-muted mb-3">Details</h2>
            <dl class="row small mb-0">
                <dt class="col-5">Category</dt><dd class="col-7"><?= $c['category'] ? e($c['category']) : '<span class="text-muted">Pending classification</span>' ?></dd>
                <dt class="col-5">Department</dt><dd class="col-7"><?= $c['department'] ? e($c['department']) : '<span class="text-muted">Not yet assigned</span>' ?></dd>
                <?php if ($isAdminView): ?>
                    <dt class="col-5">Priority</dt><dd class="col-7"><?= $c['priority'] ? '<span class="badge ' . e(level_badge_class($c['priority'])) . '">' . e($c['priority']) . '</span>' : '<span class="text-muted">Not set</span>' ?></dd>
                    <dt class="col-5">Severity</dt><dd class="col-7"><?= $c['severity'] ? '<span class="badge ' . e(level_badge_class($c['severity'])) . '">' . e($c['severity']) . '</span>' : '<span class="text-muted">Not set</span>' ?></dd>
                    <dt class="col-5">Assigned to</dt><dd class="col-7"><?= $c['assignee_name'] ? e($c['assignee_name']) : '<span class="text-muted">Unassigned</span>' ?></dd>
                    <dt class="col-5">Submitted by</dt><dd class="col-7"><?= e($c['submitter_name']) ?><br><span class="text-muted"><?= e($c['submitter_email']) ?></span></dd>
                <?php endif; ?>
                <dt class="col-5">Location</dt><dd class="col-7"><?= e($c['location']) ?></dd>
                <dt class="col-5">Submitted</dt><dd class="col-7"><?= e(format_datetime($c['created_at'])) ?></dd>
                <dt class="col-5">Last update</dt><dd class="col-7"><?= e(format_datetime($c['updated_at'])) ?></dd>
                <?php if ($c['resolved_at']): ?><dt class="col-5">Resolved</dt><dd class="col-7"><?= e(format_datetime($c['resolved_at'])) ?></dd><?php endif; ?>
                <?php if ($c['closed_at']): ?><dt class="col-5">Closed</dt><dd class="col-7"><?= e(format_datetime($c['closed_at'])) ?></dd><?php endif; ?>
            </dl>
        </div></div>

        <?= $sidePanel ?>

        <div class="card"><div class="card-body">
            <h2 class="h6 text-uppercase text-muted mb-3">History</h2>
            <ul class="timeline list-unstyled mb-0">
                <?php foreach (array_reverse($history) as $h):
                    $old = $h['old_values'] ? json_decode($h['old_values'], true) : null;
                    $new = $h['new_values'] ? json_decode($h['new_values'], true) : null; ?>
                    <li>
                        <div class="d-flex flex-wrap align-items-center gap-1">
                            <?php if ($h['to_status']): ?><span class="badge <?= e(status_badge_class($h['to_status'])) ?>"><?= e($h['to_status']) ?></span><?php endif; ?>
                            <span class="small fw-semibold"><?= e(history_title($h)) ?></span>
                            <?php if ($h['visibility'] === 'internal'): ?><span class="badge text-bg-dark">Internal</span><?php endif; ?>
                        </div>
                        <?php if ($h['note'] && $h['event_type'] !== HIST_SUBMITTED): ?><div class="small mt-1"><?= nl2br(e($h['note'])) ?></div><?php endif; ?>
                        <?php if ($isAdminView && $new): ?>
                            <ul class="small text-muted mb-0 ps-3">
                                <?php foreach ($new as $k => $v):
                                    if ($k === 'note') { if ($v) { echo '<li>Admin note: ' . e($v) . '</li>'; } continue; } ?>
                                    <li><?= e(history_field_label($k)) ?>: <?= e($old[$k] ?? '—') ?> → <strong><?= e($v ?? '—') ?></strong></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <div class="small text-muted"><?= e(format_datetime($h['created_at'])) ?><?= $h['changed_by'] && $isAdminView ? ' · ' . e($h['changed_by']) : '' ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div></div>
    </div>
</div>
