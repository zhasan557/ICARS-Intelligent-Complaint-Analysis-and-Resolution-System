<?php
define('ICARS_API', true);
require_once dirname(__DIR__, 4) . '/includes/app_init.php';

api_guard(['POST'], [ROLE_ADMIN]);

$body = api_json_body();
$id   = (int)($body['id'] ?? 0);
$to   = is_string($body['status'] ?? null) ? trim($body['status']) : '';
$expected = is_string($body['expected_status'] ?? null) ? $body['expected_status'] : null;

try {
    if ($id <= 0) {
        throw new ValidationException(['form' => 'Complaint id is required.'], 'Complaint id is required.');
    }
    if ($to === '') {
        throw new ValidationException(['status' => 'Select a status.']);
    }
    complaint_change_status($id, $to, complaint_note_from($body), (int)auth_id(), $expected);
} catch (ValidationException $ex) {
    json_error($ex->getMessage(), $ex->httpStatus, $ex->errors);
}

flash_set('success', 'Status updated to "' . $to . '".');
json_success(['status' => $to], 'Status updated.');
