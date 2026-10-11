<?php
define('ICARS_API', true);
require_once dirname(__DIR__, 4) . '/includes/app_init.php';

api_guard(['POST'], [ROLE_ADMIN]);

$body = api_json_body();
$id   = (int)($body['id'] ?? 0);

try {
    if ($id <= 0) {
        throw new ValidationException(['form' => 'Complaint id is required.'], 'Complaint id is required.');
    }
    complaint_classify($id, [
        'category_id'   => nullable_id($body['category_id'] ?? null),
        'priority_id'   => nullable_id($body['priority_id'] ?? null),
        'severity_id'   => nullable_id($body['severity_id'] ?? null),
        'department_id' => nullable_id($body['department_id'] ?? null),
    ], complaint_note_from($body), (int)auth_id());
} catch (ValidationException $ex) {
    json_error($ex->getMessage(), $ex->httpStatus, $ex->errors);
}

flash_set('success', 'Classification updated.');
json_success([], 'Classification updated.');
