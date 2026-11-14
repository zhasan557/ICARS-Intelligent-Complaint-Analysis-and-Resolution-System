<?php
declare(strict_types=1);

/** Read configuration with dot notation, e.g. config('db.host'). */
function config(?string $key = null, mixed $default = null): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $file = ICARS_ROOT . '/config/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('ICARS: config/config.php is missing. Copy config/config.example.php to config/config.php.');
        }
        $cfg = require $file;
    }
    if ($key === null) {
        return $cfg;
    }
    $value = $cfg;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/** Escape for HTML output (XSS protection). Use on EVERY dynamic value in templates. */
function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function base_url(): string
{
    return rtrim((string)config('app.base_url', ''), '/');
}

/** Build an internal URL (path relative to public/). */
function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

/** Asset URL with cache-busting timestamp. */
function asset(string $path): string
{
    $file = ICARS_ROOT . '/public/assets/' . ltrim($path, '/');
    $ver  = is_file($file) ? '?v=' . filemtime($file) : '';
    return url('assets/' . ltrim($path, '/')) . $ver;
}

/** Redirect to an internal path only (prevents open redirects). */
function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_api_request(): bool
{
    return defined('ICARS_API') && ICARS_API === true;
}

/** Trimmed string from $_POST. */
function post_string(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** Trimmed string from $_GET. */
function get_string(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** Client IP. Uses REMOTE_ADDR only (forwarded headers are spoofable). */
function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/* ---------- Flash messages ---------- */
function flash_set(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function flash_pull(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $items;
}

/* ---------- Human-friendly date ---------- */
function format_datetime(?string $value): string
{
    if (!$value) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d M Y, h:i A', $ts) : e($value);
}

/**
 * Render one form group (label + input + server/client error slot).
 * $rules feeds the client-side validator (data-rules) and mirrors the server rules.
 */
function form_field(string $name, string $label, string $type = 'text', string $value = '', ?string $error = null, string $rules = '', array $attrs = [], string $help = ''): string
{
    $id   = 'f-' . $name;
    $attr = '';
    foreach ($attrs as $k => $v) {
        $attr .= ' ' . e($k) . ($v === true ? '' : '="' . e($v) . '"');
    }
    $val = $type === 'password' ? '' : $value;          // never echo passwords back
    return '<div class="mb-3">'
        . '<label for="' . e($id) . '" class="form-label">' . e($label) . '</label>'
        . '<input type="' . e($type) . '" class="form-control' . ($error ? ' is-invalid' : '') . '" id="' . e($id) . '" name="' . e($name) . '"'
        . ' value="' . e($val) . '" data-rules="' . e($rules) . '"' . $attr . '>'
        . '<div class="invalid-feedback">' . e($error ?? '') . '</div>'
        . ($help !== '' ? '<div class="form-text">' . e($help) . '</div>' : '')
        . '</div>';
}

/** Color class for a complaint status name. */
function status_badge_class(string $status): string
{
    return match ($status) {
        'Submitted'         => 'text-bg-primary',
        'AI Analyzed'       => 'text-bg-info',
        'Under Review', 'Assigned' => 'text-bg-warning',
        'In Progress'       => 'text-bg-warning',
        'Resolved', 'User Verification' => 'text-bg-success',
        'Closed'            => 'text-bg-secondary',
        'Reopened'          => 'text-bg-danger',
        default             => 'text-bg-light',
    };
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) { return round($bytes / 1048576, 1) . ' MB'; }
    if ($bytes >= 1024)    { return round($bytes / 1024) . ' KB'; }
    return $bytes . ' B';
}

/** Color class for a priority / severity level name. */
function level_badge_class(?string $level): string
{
    return match ($level) {
        'Low'      => 'text-bg-secondary',
        'Medium'   => 'text-bg-info',
        'High'     => 'text-bg-warning',
        'Critical' => 'text-bg-danger',
        default    => 'text-bg-light',
    };
}

/** Human title for a complaint_history row. */
function history_title(array $h): string
{
    return match ($h['event_type']) {
        HIST_SUBMITTED  => 'Complaint submitted',
        HIST_ASSIGNED   => $h['from_status'] !== $h['to_status'] ? 'Assigned' : 'Reassigned',
        HIST_CLASSIFIED => 'Classification updated',
        'recommendation_generated' => 'Rule-based recommendation generated',
        'recommendation_failed'    => 'Rule analysis failed; manual triage needed',
        'recommendation_accept'    => 'Recommendation accepted',
        'recommendation_modify'    => 'Recommendation modified',
        'recommendation_reject'    => 'Recommendation rejected',
        default         => 'Status changed' . ($h['from_status'] ? ': ' . $h['from_status'] . ' → ' . $h['to_status'] : ''),
    };
}

/** Label used when rendering old → new values in admin history. */
function history_field_label(string $key): string
{
    return ['assigned_to' => 'Assigned to', 'department' => 'Department', 'category' => 'Category',
            'priority' => 'Priority', 'severity' => 'Severity'][$key] ?? ucfirst(str_replace('_', ' ', $key));
}
