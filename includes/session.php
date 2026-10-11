<?php
declare(strict_types=1);

/** Start a hardened session and enforce idle / absolute timeouts. */
function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    session_name((string)config('session.name', 'ICARS_SESSID'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_url() !== '' ? base_url() : '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $now  = time();
    $idle = (int)config('session.idle_timeout', 1800);
    $max  = (int)config('session.max_lifetime', 28800);

    $expired = isset($_SESSION['user']) && (
        (isset($_SESSION['_last']) && $now - $_SESSION['_last'] > $idle) ||
        (isset($_SESSION['_created']) && $now - $_SESSION['_created'] > $max)
    );
    if ($expired) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash_set('warning', 'Your session expired. Please log in again.');
    }
    $_SESSION['_created'] ??= $now;
    $_SESSION['_last'] = $now;
}
