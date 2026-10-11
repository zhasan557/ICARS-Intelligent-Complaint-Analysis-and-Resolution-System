<?php
define('ICARS_API', true);
require_once dirname(__DIR__, 3) . '/includes/app_init.php';

// Only complainants submit complaints. CSRF is enforced by api_guard (POST).
api_guard(['POST'], [ROLE_USER]);

// A request larger than post_max_size arrives with empty $_POST/$_FILES.
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !$_POST && !$_FILES) {
    json_error('The upload is too large. Please attach smaller files.', 413);
}

$in     = complaint_clean_input($_POST);
$errors = complaint_validate($in);

$files = upload_collect('attachments');
[$fileErrors, $infos] = upload_validate_all($files);
if ($fileErrors) {
    $errors['attachments'] = implode(' ', $fileErrors);
}
if ($errors) {
    json_error('Please correct the highlighted fields.', 422, $errors);
}

if (complaint_rate_limited((int)auth_id())) {
    json_error('You have submitted too many complaints recently. Please try again later.', 429, ['form' => 'Submission limit reached. Please try again later.']);
}
// One-time token: blocks double-clicks and replays. Consumed only after validation passes.
if (!form_token_consume('complaint', $in['form_token'])) {
    json_error('This form was already submitted or has expired.', 409, ['form' => 'This form was already submitted or has expired. Please reload the page to submit again.']);
}

$result = complaint_create((int)auth_id(), $in, $files, $infos);

flash_set('success', 'Your complaint ' . $result['complaint_no'] . ' has been submitted.');
json_success([
    'id'           => $result['id'],
    'complaint_no' => $result['complaint_no'],
    'redirect'     => 'complaints/view.php?id=' . $result['id'],
], 'Complaint submitted.', 201);
