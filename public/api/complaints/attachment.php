<?php
define('ICARS_API', true);
require_once dirname(__DIR__, 3) . '/includes/app_init.php';

api_guard(['GET'], []);                    // any logged-in user; ownership checked below

$id  = (int)get_string('id');
$row = $id > 0 ? db_one(
    'SELECT a.original_name, a.stored_path, a.mime_type, a.file_size, c.user_id
       FROM complaint_attachments a JOIN complaints c ON c.id = a.complaint_id WHERE a.id = ?', [$id]) : null;

// 404 (not 403) for both "missing" and "not yours": do not reveal which ids exist.
if (!$row || !complaint_can_view($row) || ($path = upload_resolve($row['stored_path'])) === null) {
    json_error('Attachment not found.', 404);
}

$inline   = in_array($row['mime_type'], ['image/jpeg', 'image/png'], true);   // only images render in the browser
$fallback = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $row['original_name']);

while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: ' . $row['mime_type']);                 // stored, server-detected type
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($row['original_name']));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile($path);
exit;
