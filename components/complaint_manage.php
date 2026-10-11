<?php
/**
 * Admin management panel. Expects: $c, $categories, $levels, $departments, $staffOptions
 * Each form posts JSON to its API endpoint via js/pages/admin-complaint.js (data-endpoint).
 */
$next        = complaint_allowed_next($c['status']);
$canAssign   = complaint_can_assign($c['status']);
$needsNote   = json_encode(STATUS_NEEDS_NOTE);
?>
<div id="manage-panel">

<!-- Status -->
<div class="card mb-4"><div class="card-body">
    <h2 class="h6 text-uppercase text-muted mb-3">Update status</h2>
    <?php if (!$next): ?>
        <p class="small text-muted mb-0">No manual status changes are available from "<?= e($c['status']) ?>".<?= $canAssign ? ' Use the assignment form.' : '' ?></p>
    <?php else: ?>
    <form data-endpoint="api/admin/complaints/status.php" novalidate>
        <input type="hidden" name="id" value="<?= e($c['id']) ?>">
        <input type="hidden" name="expected_status" value="<?= e($c['status']) ?>">
        <div class="form-alert alert alert-danger d-none py-2 small" role="alert"></div>
        <div class="mb-2">
            <label for="s-status" class="form-label small mb-1">Move to</label>
            <select class="form-select form-select-sm" id="s-status" name="status" data-rules="required" data-needs-note="<?= e($needsNote) ?>">
                <option value="">Select status…</option>
                <?php foreach ($next as $st): ?><option value="<?= e($st) ?>"><?= e($st) ?></option><?php endforeach; ?>
            </select>
            <div class="invalid-feedback"></div>
        </div>
        <div class="mb-2">
            <label for="s-note" class="form-label small mb-1">Note <span class="text-muted" id="s-note-hint">(visible to the complainant)</span></label>
            <textarea class="form-control form-control-sm" id="s-note" name="note" rows="2" maxlength="500" data-rules="max:500"></textarea>
            <div class="invalid-feedback"></div>
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Update status</button>
    </form>
    <?php endif; ?>
</div></div>

<!-- Assignment -->
<div class="card mb-4"><div class="card-body">
    <h2 class="h6 text-uppercase text-muted mb-3"><?= $c['assigned_to'] ? 'Reassign' : 'Assign' ?> to staff</h2>
    <?php if (!$canAssign): ?>
        <p class="small text-muted mb-0">A complaint that is "<?= e($c['status']) ?>" cannot be assigned. Reopen it first.</p>
    <?php elseif (!$staffOptions): ?>
        <p class="small text-muted mb-0">No active staff members exist yet. Create staff accounts under User Management.</p>
    <?php else: ?>
    <form data-endpoint="api/admin/complaints/assign.php" novalidate>
        <input type="hidden" name="id" value="<?= e($c['id']) ?>">
        <div class="form-alert alert alert-danger d-none py-2 small" role="alert"></div>
        <div class="mb-2">
            <label for="a-staff" class="form-label small mb-1">Staff member</label>
            <select class="form-select form-select-sm" id="a-staff" name="staff_id" data-rules="required">
                <option value="">Select staff…</option>
                <?php
                $group = null;
                foreach ($staffOptions as $st):
                    if ($group !== $st['department_id']):
                        if ($group !== null) { echo '</optgroup>'; }
                        $group = $st['department_id'];
                        echo '<optgroup label="' . e($st['department_name'] . ((int)$c['department_id'] === (int)$group ? ' (current department)' : '')) . '">';
                    endif; ?>
                    <option value="<?= e($st['id']) ?>">
                        <?= e($st['full_name'] . ($st['job_title'] ? ' — ' . $st['job_title'] : '') . ' (' . $st['open_load'] . ' open)' . ((int)$c['assigned_to'] === (int)$st['id'] ? ' — current assignee' : '')) ?>
                    </option>
                <?php endforeach; ?>
                </optgroup>
            </select>
            <div class="invalid-feedback"></div>
            <div class="form-text"><?= in_array($c['status'], ASSIGN_MOVES_TO_ASSIGNED, true) ? 'The status will change to "Assigned".' : 'The status stays "' . e($c['status']) . '".' ?> The department follows the staff member.</div>
        </div>
        <div class="mb-2">
            <label for="a-note" class="form-label small mb-1">Instructions <span class="text-muted">(internal, admins only)</span></label>
            <textarea class="form-control form-control-sm" id="a-note" name="note" rows="2" maxlength="500" data-rules="max:500"></textarea>
            <div class="invalid-feedback"></div>
        </div>
        <button type="submit" class="btn btn-sm btn-primary"><?= $c['assigned_to'] ? 'Reassign' : 'Assign' ?></button>
    </form>
    <?php endif; ?>
</div></div>

<!-- Classification corrections -->
<div class="card mb-4"><div class="card-body">
    <h2 class="h6 text-uppercase text-muted mb-1">Classification</h2>
    <p class="small text-muted">Correct category, priority, severity or department. Changes are logged as internal history.</p>
    <form data-endpoint="api/admin/complaints/classify.php" novalidate>
        <input type="hidden" name="id" value="<?= e($c['id']) ?>">
        <div class="form-alert alert alert-danger d-none py-2 small" role="alert"></div>
        <?php
        $selects = [
            ['category_id',   'Category',   $categories,  $c['category_id']],
            ['priority_id',   'Priority',   $levels,      $c['priority_id']],
            ['severity_id',   'Severity',   $levels,      $c['severity_id']],
            ['department_id', 'Department', $departments, $c['department_id']],
        ];
        foreach ($selects as [$name, $label, $options, $selected]): ?>
            <div class="mb-2">
                <label for="k-<?= e($name) ?>" class="form-label small mb-1"><?= e($label) ?></label>
                <select class="form-select form-select-sm" id="k-<?= e($name) ?>" name="<?= e($name) ?>" data-rules="">
                    <option value="">— Not set —</option>
                    <?php foreach ($options as $o): ?>
                        <option value="<?= e($o['id']) ?>"<?= (int)$selected === (int)$o['id'] ? ' selected' : '' ?>><?= e($o['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="invalid-feedback"></div>
            </div>
        <?php endforeach; ?>
        <div class="mb-2">
            <label for="k-note" class="form-label small mb-1">Reason <span class="text-muted">(optional, internal)</span></label>
            <textarea class="form-control form-control-sm" id="k-note" name="note" rows="2" maxlength="500" data-rules="max:500"></textarea>
            <div class="invalid-feedback"></div>
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Save classification</button>
    </form>
</div></div>

</div>
