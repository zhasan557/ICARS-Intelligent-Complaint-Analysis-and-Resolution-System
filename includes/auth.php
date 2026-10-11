<?php
declare(strict_types=1);

/* ---------- Session-user accessors ---------- */

/** True when the DB was unreachable while validating this request's session (fail closed, keep the session intact). */
function auth_suspended(?bool $set = null): bool
{
    static $suspended = false;
    if ($set !== null) {
        $suspended = $set;
    }
    return $suspended;
}

function auth_user(): ?array   { return auth_suspended() ? null : ($_SESSION['user'] ?? null); }
function auth_check(): bool    { return auth_user() !== null && isset($_SESSION['user']['id']); }
function auth_id(): ?int       { return auth_check() ? (int)$_SESSION['user']['id'] : null; }
function auth_role(): ?string  { return auth_check() ? $_SESSION['user']['role'] : null; }

function auth_has_role(string ...$roles): bool
{
    return auth_check() && in_array(auth_role(), $roles, true);
}

/** Dashboard path (relative to public/) for a role. */
function auth_dashboard_path(?string $role = null): string
{
    return match ($role ?? auth_role()) {
        ROLE_ADMIN => 'admin/dashboard.php',
        ROLE_STAFF => 'staff/dashboard.php',
        default    => 'user/dashboard.php',
    };
}

/* ---------- Server-side session registry (user_sessions) ---------- */

function auth_session_hash(): string
{
    return hash('sha256', session_id());
}

function auth_session_register(int $userId): void
{
    db_exec('INSERT INTO user_sessions (user_id, session_hash, ip_address, user_agent) VALUES (?, ?, ?, ?)',
        [$userId, auth_session_hash(), client_ip(), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);

    if (random_int(1, 50) === 1) {                          // occasional housekeeping
        db_exec('DELETE FROM user_sessions WHERE last_activity_at < DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [(int)config('session.max_lifetime', 28800) * 2]);
    }
}

/** Revoke a user's sessions (optionally keeping the current one). Returns number revoked. */
function auth_revoke_sessions(int $userId, bool $exceptCurrent = false): int
{
    $sql    = 'UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL';
    $params = [$userId];
    if ($exceptCurrent) {
        $sql .= ' AND session_hash <> ?';
        $params[] = auth_session_hash();
    }
    return db_exec($sql, $params);
}

/** Issue a new session id and keep the registry row in step (used after privilege/credential changes). */
function auth_rotate_session(): void
{
    $old = auth_session_hash();
    session_regenerate_id(true);
    db_exec('UPDATE user_sessions SET session_hash = ? WHERE session_hash = ? AND user_id = ?',
        [auth_session_hash(), $old, auth_id()]);
}

function auth_other_session_count(int $userId): int
{
    return (int)db_value(
        'SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL AND session_hash <> ?
            AND last_activity_at > DATE_SUB(NOW(), INTERVAL ? SECOND)',
        [$userId, auth_session_hash(), (int)config('session.idle_timeout', 1800)]
    );
}

/**
 * Runs on EVERY request (from app initialization). Re-validates the session against the database so that
 * deactivation, revocation and role changes apply immediately, and refreshes the role from the DB
 * (the role kept in $_SESSION is never trusted on its own).
 */
function auth_refresh(): void
{
    if (!isset($_SESSION['user']['id'])) {
        return;
    }
    try {
        $row = db_one(
            'SELECT u.id, u.full_name, u.email, u.is_active, r.name AS role, s.id AS session_row,
                    (s.last_activity_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)) AS stale
               FROM user_sessions s
               JOIN users u ON u.id = s.user_id
               JOIN roles r ON r.id = u.role_id
              WHERE s.session_hash = ? AND s.user_id = ? AND s.revoked_at IS NULL LIMIT 1',
            [auth_session_hash(), (int)$_SESSION['user']['id']]
        );
    } catch (Throwable $ex) {
        log_message('error', 'Session validation failed: ' . $ex->getMessage());
        auth_suspended(true);
        return;
    }

    if (!$row || (int)$row['is_active'] !== 1) {
        unset($_SESSION['user']);
        session_regenerate_id(true);
        csrf_rotate();
        flash_set('warning', 'Your session has ended. Please log in again.');
        return;
    }

    $_SESSION['user'] = ['id' => (int)$row['id'], 'name' => $row['full_name'], 'email' => $row['email'], 'role' => $row['role']];
    if ((int)$row['stale'] === 1) {
        db_exec('UPDATE user_sessions SET last_activity_at = NOW() WHERE id = ?', [$row['session_row']]);
    }
}

/* ---------- Login throttling ---------- */
function auth_is_throttled(string $email): bool
{
    $failures = (int)db_value(
        'SELECT COUNT(*) FROM login_attempts
          WHERE email = ? AND ip_address = ? AND was_success = 0
            AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
        [$email, client_ip(), (int)config('security.lockout_minutes', 15)]
    );
    return $failures >= (int)config('security.max_login_attempts', 5);
}

function auth_record_attempt(string $email, bool $success): void
{
    db_exec('INSERT INTO login_attempts (email, ip_address, was_success) VALUES (?, ?, ?)',
        [$email, client_ip(), $success ? 1 : 0]);
}

/**
 * Attempt a login. Returns [bool success, string|null error].
 * Generic error message: attackers cannot tell which emails exist or which accounts are disabled.
 */
function auth_attempt(string $email, string $password): array
{
    $email = strtolower(trim($email));

    if (auth_is_throttled($email)) {
        $mins = (int)config('security.lockout_minutes', 15);
        return [false, "Too many failed attempts. Please try again in $mins minutes."];
    }

    $user  = db_one('SELECT u.id, u.full_name, u.email, u.password_hash, u.is_active, r.name AS role
                       FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = ? LIMIT 1', [$email]);
    $valid = password_verify($password, $user['password_hash'] ?? AUTH_DUMMY_HASH) && $user !== null;

    if (!$valid || (int)$user['is_active'] !== 1) {
        auth_record_attempt($email, false);
        return [false, 'Invalid email or password.'];
    }

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    auth_record_attempt($email, true);
    auth_login_user($user);
    return [true, null];
}

/** Establish an authenticated session. $user needs: id, full_name, email, role (name). */
function auth_login_user(array $user): void
{
    session_regenerate_id(true);          // session fixation defence: new id at privilege change
    csrf_rotate();
    $_SESSION['user'] = [
        'id'    => (int)$user['id'],
        'name'  => $user['full_name'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ];
    auth_session_register((int)$user['id']);
    db_exec('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
    try {
        audit_log('login', 'user', (int)$user['id']);
    } catch (Throwable $ex) {
        log_message('error', 'Audit log failed on login: ' . $ex->getMessage());
    }
}

function auth_logout(): void
{
    if (auth_check()) {
        try {
            db_exec('UPDATE user_sessions SET revoked_at = NOW() WHERE session_hash = ?', [auth_session_hash()]);
            audit_log('logout', 'user', auth_id());
        } catch (Throwable $ex) {
            log_message('error', 'Logout bookkeeping failed: ' . $ex->getMessage());
        }
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'],
            'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    }
    session_destroy();
}

/* ---------- Route guards for pages ---------- */
function require_login(): void
{
    if (!auth_check()) {
        flash_set('warning', 'Please log in to continue.');
        redirect('auth/login.php');
    }
}

/** Server-side RBAC: only the listed roles may proceed, everyone else gets 403. */
function require_role(string ...$roles): void
{
    require_login();
    if (!auth_has_role(...$roles)) {
        log_message('warning', 'Forbidden access attempt', ['user' => auth_id(), 'role' => auth_role(), 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
        abort(403);
    }
}
