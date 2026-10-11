<?php
declare(strict_types=1);
/**
 * Secure upload handling.
 * Defence in depth:  extension whitelist  +  server-side MIME detection (finfo)  +  content signature check
 *                    +  size/count limits  +  random storage names  +  storage outside the web root.
 * The client-supplied filename and Content-Type are NEVER trusted or used on disk.
 */

/** ext => acceptable server-detected MIME types. */
const UPLOAD_TYPES = [
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'pdf'  => ['application/pdf'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
];

function upload_root(): string
{
    return ICARS_ROOT . '/storage/uploads';
}

function upload_max_bytes(): int
{
    return (int)((float)config('upload.max_size_mb', 5) * 1048576);
}

function upload_max_files(): int
{
    return (int)config('upload.max_files', 3);
}

/** Flatten $_FILES['field'] (multiple) into a list of single-file arrays; empty slots are dropped. */
function upload_collect(string $field): array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field]['name'] ?? null)) {
        return [];
    }
    $files = [];
    foreach ($_FILES[$field]['name'] as $i => $name) {
        $err = (int)$_FILES[$field]['error'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $files[] = [
            'name' => (string)$name, 'tmp_name' => (string)$_FILES[$field]['tmp_name'][$i],
            'size' => (int)$_FILES[$field]['size'][$i], 'error' => $err,
        ];
    }
    return $files;
}

/** Make a user-supplied filename safe for display / Content-Disposition (NOT for disk use). */
function safe_display_name(string $name): string
{
    $name = str_replace('\\', '/', $name);
    $name = basename($name);                                              // strip any path
    $name = preg_replace('/[\x00-\x1F\x7F"<>|:*?\/\\\\]/u', '', $name) ?? '';
    $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
    $name = ltrim($name, '.');                                            // no hidden-file names
    if (mb_strlen($name) > 120) {
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 110) . ($ext !== '' ? '.' . $ext : '');
    }
    return $name !== '' ? $name : 'file';
}

/** A real .docx is a zip containing word/document.xml (skipped only if the zip extension is unavailable). */
function upload_docx_has_document(string $path): bool
{
    if (!class_exists('ZipArchive')) {
        return true;
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return false;
    }
    $found = $zip->locateName('word/document.xml') !== false;
    $zip->close();
    return $found;
}

/** Reject files carrying embedded PHP code (e.g. an image with a script appended). Files are 5 MB max. */
function upload_contains_php(string $path): bool
{
    return stripos((string)file_get_contents($path), '<?php') !== false;
}

/**
 * Validate one uploaded file. Returns [error|null, info]. info = ext, mime, size, sha256, display_name.
 */
function upload_validate(array $f): array
{
    $label = safe_display_name($f['name']);

    $phpErrors = [
        UPLOAD_ERR_INI_SIZE   => 'is too large.',
        UPLOAD_ERR_FORM_SIZE  => 'is too large.',
        UPLOAD_ERR_PARTIAL    => 'was only partially uploaded. Please try again.',
        UPLOAD_ERR_NO_TMP_DIR => 'could not be saved (server configuration).',
        UPLOAD_ERR_CANT_WRITE => 'could not be saved (server configuration).',
        UPLOAD_ERR_EXTENSION  => 'was blocked by the server.',
    ];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return ["\"$label\" " . ($phpErrors[$f['error']] ?? 'could not be uploaded.'), []];
    }
    if (!is_uploaded_file($f['tmp_name'])) {
        return ["\"$label\" was not a valid upload.", []];
    }
    $size = (int)filesize($f['tmp_name']);
    if ($size <= 0) {
        return ["\"$label\" is empty.", []];
    }
    if ($size > upload_max_bytes()) {
        return ["\"$label\" exceeds the " . config('upload.max_size_mb', 5) . ' MB limit.', []];
    }

    // 1) extension whitelist (from the FINAL extension only)
    $ext     = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $allowed = array_map('strtolower', (array)config('upload.allowed_ext', []));
    if ($ext === '' || !in_array($ext, $allowed, true) || !isset(UPLOAD_TYPES[$ext])) {
        return ["\"$label\" has a file type that is not allowed (allowed: " . implode(', ', $allowed) . ').', []];
    }

    // 2) real MIME type from file content
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($f['tmp_name']);
    if (!in_array($mime, UPLOAD_TYPES[$ext], true)) {
        return ["\"$label\" does not look like a valid ." . $ext . ' file.', []];
    }

    // 3) content signature checks
    $fh   = fopen($f['tmp_name'], 'rb');
    $head = $fh ? (string)fread($fh, 8) : '';
    if ($fh) { fclose($fh); }
    $ok = match ($ext) {
        'pdf'         => str_starts_with($head, '%PDF-'),
        'docx'        => str_starts_with($head, "PK\x03\x04") && upload_docx_has_document($f['tmp_name']),
        'png', 'jpg', 'jpeg' => @getimagesize($f['tmp_name']) !== false,   // parses as a real image
        default       => false,
    };
    if (!$ok) {
        return ["\"$label\" appears to be corrupted or not a real ." . $ext . ' file.', []];
    }
    if (upload_contains_php($f['tmp_name'])) {
        return ["\"$label\" contains disallowed content.", []];
    }
    if ($mime === 'application/zip' || $mime === 'application/octet-stream') {
        $mime = UPLOAD_TYPES['docx'][0];                     // docx is a zip container; store the canonical type
    }

    return [null, [
        'ext' => $ext, 'mime' => $mime, 'size' => $size, 'display_name' => $label,
        'sha256' => hash_file('sha256', $f['tmp_name']),
    ]];
}

/** Validate a whole batch (count + each file). Returns [errors[], infos[]] aligned with $files. */
function upload_validate_all(array $files): array
{
    $errors = [];
    if (count($files) > upload_max_files()) {
        $errors[] = 'You can attach at most ' . upload_max_files() . ' files.';
        return [$errors, []];
    }
    $infos = [];
    foreach ($files as $i => $f) {
        [$err, $info] = upload_validate($f);
        if ($err) { $errors[] = $err; }
        $infos[$i] = $info;
    }
    return [$errors, $infos];
}

/**
 * Move a validated upload into storage/uploads/YYYY/MM/<random>.<ext>. Returns the relative path.
 * Throws on failure so the caller can roll back.
 */
function upload_store(array $file, array $info): string
{
    $sub = date('Y') . '/' . date('m');
    $dir = upload_root() . '/' . $sub;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create upload directory.');
    }
    $rel = $sub . '/' . bin2hex(random_bytes(16)) . '.' . $info['ext'];   // name never derived from user input
    if (!move_uploaded_file($file['tmp_name'], upload_root() . '/' . $rel)) {
        throw new RuntimeException('Could not store the uploaded file.');
    }
    @chmod(upload_root() . '/' . $rel, 0640);
    return $rel;
}

function upload_delete(string $rel): void
{
    $path = realpath(upload_root() . '/' . $rel);
    $root = realpath(upload_root());
    if ($path !== false && $root !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path)) {
        @unlink($path);
    }
}

/** Resolve a stored relative path to an absolute path, or null if it escapes the upload root. */
function upload_resolve(string $rel): ?string
{
    $path = realpath(upload_root() . '/' . $rel);
    $root = realpath(upload_root());
    return ($path !== false && $root !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path)) ? $path : null;
}
