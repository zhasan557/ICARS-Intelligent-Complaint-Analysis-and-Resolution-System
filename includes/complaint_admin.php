<?php
declare(strict_types=1);
/**
 * Complaint management service (FR-04): lifecycle transitions, assignment, classification
 * corrections and the admin list. Every state change locks the row, validates against the
 * lifecycle rules, writes a complaint_history record and an audit_logs entry in ONE transaction.
 */

/* ---------- Lookups ---------- */
function complaint_levels(): array
{
    return db_all('SELECT id, name FROM complaint_levels ORDER BY sort_order');
}

/** Status names an admin can pick from the lifecycle right now (enabled statuses). */
function complaint_enabled_statuses(): array
{
    return db_all('SELECT id, name FROM complaint_statuses WHERE is_enabled = 1 ORDER BY sort_order');
}

/** Statuses the admin form may move a complaint to (Assigned is excluded: use the Assign action). */
function complaint_allowed_next(string $status): array
{
    return array_values(array_filter(COMPLAINT_TRANSITIONS[$status] ?? [], static fn($s) => $s !== STATUS_ASSIGNED));
}

function complaint_can_assign(string $status): bool
{
    return in_array($status, ASSIGN_MOVES_TO_ASSIGNED, true) || in_array($status, ASSIGN_KEEPS_STATUS, true);
}

/** Active staff grouped by department for the assignment dropdown. */
function complaint_staff_options(): array
{
    return db_all(
        "SELECT u.id, u.full_name, sp.department_id, d.name AS department_name, sp.job_title,
                (SELECT COUNT(*) FROM complaints c JOIN complaint_statuses s ON s.id = c.status_id
                  WHERE c.assigned_to = u.id AND s.name IN ('Assigned','In Progress','Reopened')) AS open_load
           FROM users u
           JOIN roles r ON r.id = u.role_id AND r.name = 'staff'
           JOIN staff_profiles sp ON sp.user_id = u.id
           JOIN departments d ON d.id = sp.department_id AND d.is_active = 1
          WHERE u.is_active = 1
          ORDER BY d.name, u.full_name"
    );
}

/* ---------- History ---------- */
function complaint_history_add(int $complaintId, string $event, ?int $fromStatusId, ?int $toStatusId, ?int $actorId,
                               string $visibility, ?string $note, ?array $old = null, ?array $new = null): void
{
    db_exec(
        'INSERT INTO complaint_history (complaint_id, event_type, from_status_id, to_status_id, changed_by, visibility, note, old_values, new_values)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$complaintId, $event, $fromStatusId, $toStatusId, $actorId, $visibility, $note,
         $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
         $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE)]
    );
}

/** Lock a complaint row for update and return the fields the rules need. */
function complaint_lock(int $id): array
{
    $c = db_one(
        'SELECT c.id, c.status_id, s.name AS status, c.assigned_to, c.department_id, c.category_id, c.priority_id, c.severity_id
           FROM complaints c JOIN complaint_statuses s ON s.id = c.status_id WHERE c.id = ? FOR UPDATE',
        [$id]
    );
    if (!$c) {
        throw new ValidationException(['form' => 'Complaint not found.'], 'Complaint not found.', 404);
    }
    return $c;
}

/* ---------- Status change ---------- */
function complaint_change_status(int $id, string $to, string $note, int $actorId, ?string $expected = null): void
{
    db_transaction(function () use ($id, $to, $note, $actorId, $expected): void {
        $c = complaint_lock($id);

        if ($expected !== null && $expected !== $c['status']) {
            throw new ValidationException(['form' => 'This complaint was changed by someone else (now "' . $c['status'] . '"). Reload the page and try again.'],
                'The complaint was modified in the meantime.', 409);
        }
        if ($to === STATUS_ASSIGNED) {
            throw new ValidationException(['status' => 'Use the Assign action to assign a complaint to a staff member.']);
        }
        if (!in_array($to, complaint_allowed_next($c['status']), true)) {
            throw new ValidationException(['status' => 'A complaint that is "' . $c['status'] . '" cannot be moved to "' . $to . '".']);
        }
        if (in_array($to, STATUS_NEEDS_NOTE, true) && $note === '') {
            throw new ValidationException(['note' => 'A note is required when setting the status to "' . $to . '".']);
        }
        if ($to === STATUS_IN_PROGRESS && !$c['assigned_to']) {
            throw new ValidationException(['status' => 'Assign the complaint to a staff member before starting work.']);
        }

        $toId = complaint_status_id($to);
        $sets = ['status_id = ?'];
        $params = [$toId];
        if ($to === STATUS_RESOLVED) { $sets[] = 'resolved_at = NOW()'; }
        if ($to === STATUS_CLOSED)   { $sets[] = 'closed_at = NOW()'; }
        if ($to === STATUS_REOPENED) { $sets[] = 'resolved_at = NULL'; $sets[] = 'closed_at = NULL'; }
        $params[] = $id;
        db_exec('UPDATE complaints SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        complaint_history_add($id, HIST_STATUS, (int)$c['status_id'], $toId, $actorId, 'public', $note !== '' ? $note : null);
        audit_log('complaint_status_change', 'complaint', $id, ['status' => $c['status']], ['status' => $to, 'note' => $note], $actorId);
    });
}

/* ---------- Assignment ---------- */
function complaint_assign(int $id, int $staffId, string $note, int $actorId): void
{
    db_transaction(function () use ($id, $staffId, $note, $actorId): void {
        $c = complaint_lock($id);

        if (!complaint_can_assign($c['status'])) {
            throw new ValidationException(['form' => 'A complaint that is "' . $c['status'] . '" cannot be assigned. Reopen it first.']);
        }
        $staff = db_one(
            "SELECT u.id, u.full_name, sp.department_id, d.name AS department_name
               FROM users u
               JOIN roles r ON r.id = u.role_id AND r.name = 'staff'
               JOIN staff_profiles sp ON sp.user_id = u.id
               JOIN departments d ON d.id = sp.department_id AND d.is_active = 1
              WHERE u.id = ? AND u.is_active = 1", [$staffId]);
        if (!$staff) {
            throw new ValidationException(['staff_id' => 'Select a valid, active staff member.']);
        }

        $movesStatus = in_array($c['status'], ASSIGN_MOVES_TO_ASSIGNED, true);
        if (!$movesStatus && (int)$c['assigned_to'] === $staffId) {
            throw new ValidationException(['staff_id' => 'This complaint is already assigned to ' . $staff['full_name'] . '.']);
        }

        $oldStaff = $c['assigned_to'] ? (string)db_value('SELECT full_name FROM users WHERE id = ?', [$c['assigned_to']]) : null;
        $oldDept  = $c['department_id'] ? (string)db_value('SELECT name FROM departments WHERE id = ?', [$c['department_id']]) : null;
        $toId     = $movesStatus ? complaint_status_id(STATUS_ASSIGNED) : (int)$c['status_id'];

        db_exec('UPDATE complaints SET assigned_to = ?, department_id = ?, status_id = ? WHERE id = ?',
            [$staffId, $staff['department_id'], $toId, $id]);

        // Public note is generated; the admin's own note is kept in new_values and shown to admins only.
        $public = ($oldStaff ? 'Reassigned' : 'Assigned') . ' to the ' . $staff['department_name'] . ' department';
        complaint_history_add($id, HIST_ASSIGNED, (int)$c['status_id'], $toId, $actorId, 'public', $public,
            ['assigned_to' => $oldStaff, 'department' => $oldDept],
            ['assigned_to' => $staff['full_name'], 'department' => $staff['department_name'], 'note' => $note !== '' ? $note : null]);
        audit_log('complaint_assign', 'complaint', $id,
            ['assigned_to' => $c['assigned_to'], 'department_id' => $c['department_id'], 'status' => $c['status']],
            ['assigned_to' => $staffId, 'department_id' => (int)$staff['department_id'], 'status' => $movesStatus ? STATUS_ASSIGNED : $c['status']], $actorId);
    });
}

/* ---------- Classification corrections (category / priority / severity / department) ---------- */
function complaint_classify(int $id, array $in, string $note, int $actorId): void
{
    $errors = [];
    $check = static function (string $key, string $sql, string $label) use ($in, &$errors): void {
        if (($in[$key] ?? null) !== null && !db_value($sql, [$in[$key]])) {
            $errors[$key] = "Please choose a valid $label.";
        }
    };
    $check('category_id',   'SELECT 1 FROM complaint_categories WHERE id = ? AND is_active = 1', 'category');
    $check('priority_id',   'SELECT 1 FROM complaint_levels WHERE id = ?', 'priority');
    $check('severity_id',   'SELECT 1 FROM complaint_levels WHERE id = ?', 'severity');
    $check('department_id', 'SELECT 1 FROM departments WHERE id = ? AND is_active = 1', 'department');
    if ($errors) {
        throw new ValidationException($errors);
    }

    db_transaction(function () use ($id, $in, $note, $actorId): void {
        $c = complaint_lock($id);
        $fields = ['category_id' => 'category', 'priority_id' => 'priority', 'severity_id' => 'severity', 'department_id' => 'department'];
        $tables = ['category_id' => 'complaint_categories', 'priority_id' => 'complaint_levels', 'severity_id' => 'complaint_levels', 'department_id' => 'departments'];

        $changes = [];
        foreach ($fields as $col => $label) {
            $new = $in[$col] ?? null;
            $old = $c[$col] !== null ? (int)$c[$col] : null;
            if ($new !== $old) {
                $changes[$col] = $new;
            }
        }
        if (!$changes) {
            throw new ValidationException(['form' => 'Nothing was changed.']);
        }

        // A department change must not contradict the assigned staff member.
        if (array_key_exists('department_id', $changes) && $c['assigned_to']) {
            $staffDept = (int)db_value('SELECT department_id FROM staff_profiles WHERE user_id = ?', [$c['assigned_to']]);
            if ($changes['department_id'] !== $staffDept) {
                throw new ValidationException(['department_id' => 'A staff member from another department is assigned. Reassign the complaint to change its department.']);
            }
        }

        $old = $new = [];
        $sets = [];
        $params = [];
        foreach ($changes as $col => $value) {
            $name = static fn($v) => $v === null ? null : (string)db_value("SELECT name FROM {$tables[$col]} WHERE id = ?", [$v]);
            $old[$fields[$col]] = $name($c[$col] !== null ? (int)$c[$col] : null);
            $new[$fields[$col]] = $name($value);
            $sets[]   = "$col = ?";
            $params[] = $value;
        }
        $params[] = $id;
        db_exec('UPDATE complaints SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        // Internal: classification corrections are triage data, visible to admins only.
        complaint_history_add($id, HIST_CLASSIFIED, (int)$c['status_id'], (int)$c['status_id'], $actorId, 'internal', $note !== '' ? $note : null, $old, $new);
        audit_log('complaint_classify', 'complaint', $id, $old, $new, $actorId);
    });
}

/* ---------- Admin list & dashboard counts ---------- */
const ADMIN_SORTS = [
    'newest'   => 'c.created_at DESC, c.id DESC',
    'oldest'   => 'c.created_at ASC, c.id ASC',
    'updated'  => 'c.updated_at DESC, c.id DESC',
    'priority' => 'lp.sort_order IS NULL, lp.sort_order DESC, c.created_at DESC',
];

/**
 * Filters: q, status (name), category / priority / department ('' = any, 'none' = not set, or id),
 * assignee ('' | 'none' | id), sort, page.
 */
function complaint_admin_list(array $f): array
{
    $where = ['1 = 1'];
    $params = [];

    if (($f['q'] ?? '') !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
        $where[] = '(c.title LIKE ? OR c.complaint_no LIKE ? OR c.location LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if (($f['status'] ?? '') !== '') {
        $where[] = 's.name = ?';
        $params[] = $f['status'];
    }
    foreach (['category' => 'c.category_id', 'priority' => 'c.priority_id', 'department' => 'c.department_id', 'assignee' => 'c.assigned_to'] as $key => $col) {
        $v = (string)($f[$key] ?? '');
        if ($v === 'none') {
            $where[] = "$col IS NULL";
        } elseif ($v !== '' && ctype_digit($v)) {
            $where[] = "$col = ?";
            $params[] = (int)$v;
        }
    }
    $cond  = implode(' AND ', $where);
    $order = ADMIN_SORTS[$f['sort'] ?? 'newest'] ?? ADMIN_SORTS['newest'];
    $per   = max(1, min(50, (int)($f['per_page'] ?? 10)));
    $page  = max(1, (int)($f['page'] ?? 1));
    $off   = ($page - 1) * $per;

    $from = 'FROM complaints c
             JOIN complaint_statuses s ON s.id = c.status_id
             JOIN users u ON u.id = c.user_id
        LEFT JOIN complaint_categories cat ON cat.id = c.category_id
        LEFT JOIN complaint_levels lp ON lp.id = c.priority_id
        LEFT JOIN departments d ON d.id = c.department_id
        LEFT JOIN users st ON st.id = c.assigned_to';

    $total = (int)db_value("SELECT COUNT(*) $from WHERE $cond", $params);
    // $order comes from a fixed whitelist; $per/$off are integer-cast: both injection-safe.
    $rows = db_all(
        "SELECT c.id, c.complaint_no, c.title, c.created_at, c.updated_at, s.name AS status, cat.name AS category,
                lp.name AS priority, d.name AS department, st.full_name AS assignee, u.full_name AS submitter
         $from WHERE $cond ORDER BY $order LIMIT $per OFFSET $off",
        $params
    );
    return ['complaints' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per];
}

/** Counts for the admin dashboard. */
function complaint_admin_counts(): array
{
    $byStatus = array_column(db_all('SELECT s.name, COUNT(c.id) AS n FROM complaint_statuses s
                                      LEFT JOIN complaints c ON c.status_id = s.id WHERE s.is_enabled = 1 GROUP BY s.id, s.name ORDER BY s.sort_order'), 'n', 'name');
    $unassigned = (int)db_value("SELECT COUNT(*) FROM complaints c JOIN complaint_statuses s ON s.id = c.status_id
                                  WHERE c.assigned_to IS NULL AND s.name NOT IN ('Resolved','Closed')");
    return ['by_status' => array_map('intval', $byStatus), 'total' => array_sum(array_map('intval', $byStatus)), 'unassigned' => $unassigned];
}

/** Request value -> ?int. '' / null => null; digits => int; anything else => -1 (fails the "valid choice" check). */
function nullable_id(mixed $v): ?int
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_int($v)) {
        return $v;
    }
    return is_string($v) && ctype_digit($v) ? (int)$v : -1;
}

/** Shared parsing of the optional note field used by all admin complaint actions. */
function complaint_note_from(array $body): string
{
    $note = clean_text(is_string($body['note'] ?? null) ? $body['note'] : '', true);
    if (mb_strlen($note) > 500) {
        throw new ValidationException(['note' => 'Note must not exceed 500 characters.']);
    }
    return $note;
}
