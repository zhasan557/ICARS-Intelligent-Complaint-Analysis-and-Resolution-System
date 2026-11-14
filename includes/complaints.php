<?php
declare(strict_types=1);
/**
 * Complaint service (FR-02 / FR-03 foundation). All complaint reads/writes go through here.
 */

const COMPLAINT_SELECT = 'SELECT c.id, c.complaint_no, c.user_id, c.category_id, c.department_id, c.assigned_to, c.priority_id, c.severity_id,
                                 c.title, c.description, c.location, c.created_at, c.updated_at, c.resolved_at, c.closed_at,
                                 s.name AS status, cat.name AS category, d.name AS department,
                                 pr.name AS priority, sv.name AS severity, st.full_name AS assignee_name,
                                 u.full_name AS submitter_name, u.email AS submitter_email
                            FROM complaints c
                            JOIN complaint_statuses s ON s.id = c.status_id
                            JOIN users u ON u.id = c.user_id
                       LEFT JOIN complaint_categories cat ON cat.id = c.category_id
                       LEFT JOIN departments d ON d.id = c.department_id
                       LEFT JOIN complaint_levels pr ON pr.id = c.priority_id
                       LEFT JOIN complaint_levels sv ON sv.id = c.severity_id
                       LEFT JOIN users st ON st.id = c.assigned_to';

/* ---------- Lookups ---------- */
function complaint_categories(bool $activeOnly = true): array
{
    return db_all('SELECT id, name, description FROM complaint_categories' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY name = \'Other\', name');
}

function complaint_statuses(): array
{
    return db_all('SELECT id, name FROM complaint_statuses ORDER BY sort_order');
}

function complaint_status_id(string $name): int
{
    static $map = null;
    $map ??= array_column(complaint_statuses(), 'id', 'name');
    if (!isset($map[$name])) {
        throw new RuntimeException("Unknown complaint status: $name");
    }
    return (int)$map[$name];
}

/* ---------- Input ---------- */

/** Remove control characters (keeps \n and \t and all letters/marks, incl. Bengali joiners). */
function clean_text(string $s, bool $multiline): string
{
    $s = str_replace("\r\n", "\n", $s);
    $s = str_replace("\r", "\n", $s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s) ?? '';
    if ($multiline) {
        $s = preg_replace("/\n{3,}/", "\n\n", trim($s)) ?? '';
        return $s;
    }
    return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
}

/** Whitelist + normalise the submitted fields. Unknown keys (status, user_id, ...) are ignored. */
function complaint_clean_input(array $src): array
{
    $text = static fn(string $k): string => isset($src[$k]) && is_string($src[$k]) ? $src[$k] : '';
    return [
        'title'       => clean_text($text('title'), false),
        'description' => clean_text($text('description'), true),
        'location'    => clean_text($text('location'), false),
        'category_id' => ctype_digit($text('category_id')) ? (int)$text('category_id') : null,
        'form_token'  => $text('form_token'),
    ];
}

function complaint_validate(array $in): array
{
    foreach (['title', 'description', 'location'] as $k) {
        if (!mb_check_encoding($in[$k], 'UTF-8')) {
            return [$k => 'Contains invalid characters.'];
        }
    }
    $errors = validate($in, [
        'title'       => 'required|min:5|max:150',
        'description' => 'required|min:20|max:5000',
        'location'    => 'required|min:3|max:150',
    ]);
    if ($in['category_id'] !== null) {
        $ok = db_value('SELECT 1 FROM complaint_categories WHERE id = ? AND is_active = 1', [$in['category_id']]);
        if (!$ok) {
            $errors['category_id'] = 'Please choose a valid category.';
        }
    }
    return $errors;
}

/** Spam protection: too many complaints from one user in the last hour? */
function complaint_rate_limited(int $userId): bool
{
    $n = (int)db_value('SELECT COUNT(*) FROM complaints WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)', [$userId]);
    return $n >= (int)config('security.max_complaints_per_hour', 10);
}

/* ---------- Create ---------- */

/**
 * Store a complaint + attachments atomically.
 * $files/$infos come from upload_validate_all() (already validated).
 * Returns ['id' => int, 'complaint_no' => string].
 */
function complaint_create(int $userId, array $in, array $files = [], array $infos = []): array
{
    $storedPaths = [];                                        // for cleanup if anything fails
    try {
        return db_transaction(function () use ($userId, $in, $files, $infos, &$storedPaths): array {
            $submitted = complaint_status_id(STATUS_SUBMITTED);

            // Temporary unique number, replaced below once the auto-increment id is known.
            db_exec(
                'INSERT INTO complaints (complaint_no, user_id, category_id, status_id, title, description, location)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                ['TMP-' . bin2hex(random_bytes(8)), $userId, $in['category_id'], $submitted, $in['title'], $in['description'], $in['location']]
            );
            $id = db_last_id();
            $no = sprintf('ICARS-%s-%06d', date('Y'), $id);   // ID generation: unique because $id is unique
            db_exec('UPDATE complaints SET complaint_no = ? WHERE id = ?', [$no, $id]);

            complaint_history_add($id, HIST_SUBMITTED, null, $submitted, $userId, 'public', 'Complaint submitted');

            // Rule analysis is best-effort: a failure must never roll back a valid complaint.
            complaint_intelligence_attempt($id, static function () use ($id, $in): void {
                [$categories, $departments, $staff, $resolved] = complaint_intelligence_context();
                $suggestion = complaint_intelligence_rules($in, $categories, $departments, $staff, $resolved);
                complaint_intelligence_store($id, $suggestion);
            });

            foreach ($files as $i => $file) {
                $rel = upload_store($file, $infos[$i]);
                $storedPaths[] = $rel;
                db_exec(
                    'INSERT INTO complaint_attachments (complaint_id, uploaded_by, original_name, stored_path, mime_type, file_size, sha256)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$id, $userId, $infos[$i]['display_name'], $rel, $infos[$i]['mime'], $infos[$i]['size'], $infos[$i]['sha256']]
                );
            }

            audit_log('complaint_create', 'complaint', $id, null,
                ['complaint_no' => $no, 'category_id' => $in['category_id'], 'attachments' => count($files)], $userId);

            return ['id' => $id, 'complaint_no' => $no];
        });
    } catch (Throwable $ex) {
        foreach ($storedPaths as $rel) {                       // don't leave orphan files behind
            upload_delete($rel);
        }
        throw $ex;
    }
}

/* ---------- Read ---------- */

function complaint_find(int $id): ?array
{
    return db_one(COMPLAINT_SELECT . ' WHERE c.id = ? LIMIT 1', [$id]);
}

/**
 * Visibility rule. Owner and admins only for now; staff access will be added with assignment
 * (a later milestone) so staff never see complaints that are not theirs.
 */
function complaint_can_view(array $complaint): bool
{
    return auth_check() && ((int)$complaint['user_id'] === auth_id() || auth_has_role(ROLE_ADMIN));
}

function complaint_attachments(int $complaintId): array
{
    return db_all('SELECT id, original_name, mime_type, file_size, created_at FROM complaint_attachments
                    WHERE complaint_id = ? ORDER BY id', [$complaintId]);
}

/**
 * Timeline of a complaint, oldest first. Users only ever get 'public' events;
 * administrators pass $includeInternal = true to also see internal triage events.
 */
function complaint_history(int $complaintId, bool $includeInternal = false): array
{
    return db_all(
        'SELECT h.id, h.event_type, h.visibility, h.note, h.old_values, h.new_values, h.created_at,
                fs.name AS from_status, ts.name AS to_status, u.full_name AS changed_by
           FROM complaint_history h
      LEFT JOIN complaint_statuses fs ON fs.id = h.from_status_id
      LEFT JOIN complaint_statuses ts ON ts.id = h.to_status_id
      LEFT JOIN users u ON u.id = h.changed_by
          WHERE h.complaint_id = ?' . ($includeInternal ? '' : " AND h.visibility = 'public'") . '
          ORDER BY h.created_at, h.id',
        [$complaintId]
    );
}

/** A user's own complaints: filters q (title / number / location / description), status name; paginated. */
function complaint_list_for_user(int $userId, array $f): array
{
    $where  = ['c.user_id = ?'];
    $params = [$userId];

    if (($f['q'] ?? '') !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
        $where[] = '(c.title LIKE ? OR c.complaint_no LIKE ? OR c.location LIKE ? OR c.description LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    if (($f['status'] ?? '') !== '') {
        $where[]  = 's.name = ?';
        $params[] = $f['status'];
    }
    $cond  = implode(' AND ', $where);
    $per   = max(1, min(50, (int)($f['per_page'] ?? 10)));
    $page  = max(1, (int)($f['page'] ?? 1));
    $off   = ($page - 1) * $per;

    $total = (int)db_value("SELECT COUNT(*) FROM complaints c JOIN complaint_statuses s ON s.id = c.status_id WHERE $cond", $params);
    // $per/$off are integer-cast above, so inlining them is injection-safe.
    $rows = db_all(
        "SELECT c.id, c.complaint_no, c.title, c.location, c.created_at, c.updated_at, s.name AS status, cat.name AS category,
                (SELECT COUNT(*) FROM complaint_attachments a WHERE a.complaint_id = c.id) AS attachment_count
           FROM complaints c
           JOIN complaint_statuses s ON s.id = c.status_id
      LEFT JOIN complaint_categories cat ON cat.id = c.category_id
          WHERE $cond ORDER BY c.created_at DESC, c.id DESC LIMIT $per OFFSET $off",
        $params
    );
    return ['complaints' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per];
}

function complaint_count_for_user(int $userId): int
{
    return (int)db_value('SELECT COUNT(*) FROM complaints WHERE user_id = ?', [$userId]);
}
