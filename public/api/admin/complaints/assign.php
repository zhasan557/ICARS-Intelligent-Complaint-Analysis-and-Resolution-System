<?php
define('ICARS_API', true);
require_once dirname(__DIR__, 4) . '/includes/app_init.php';

api_guard(['POST'], [ROLE_ADMIN]);

$body    = api_json_body();
$id      = (int)($body['id'] ?? 0);
$staffId = nullable_id($body['staff_id'] ?? null);

try {
    if ($id <= 0) {
        throw new ValidationException(['form' => 'Complaint id is required.'], 'Complaint id is required.');
    }
    if ($staffId === null || $staffId <= 0) {
        throw new ValidationException(['staff_id' => 'Select a staff member.']);
    }
    complaint_assign($id, $staffId, complaint_note_from($body), (int)auth_id());
} catch (ValidationException $ex) {
    json_error($ex->getMessage(), $ex->httpStatus, $ex->errors);
}

flash_set('success', 'Complaint assigned.');
json_success([], 'Complaint assigned.');
