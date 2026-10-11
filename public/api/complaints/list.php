<?php
define('ICARS_API', true);
require_once dirname(__DIR__, 3) . '/includes/app_init.php';

api_guard(['GET'], [ROLE_USER]);           // a user only ever sees their own complaints

$statuses = array_column(complaint_enabled_statuses(), 'name');
$status   = get_string('status');

$result = complaint_list_for_user((int)auth_id(), [
    'q'        => mb_substr(get_string('q'), 0, 100),
    'status'   => in_array($status, $statuses, true) ? $status : '',
    'page'     => (int)get_string('page', '1'),
    'per_page' => 10,
]);
foreach ($result['complaints'] as &$c) {
    $c['id']               = (int)$c['id'];
    $c['attachment_count'] = (int)$c['attachment_count'];
}
unset($c);
$result['meta'] = ['statuses' => $statuses];

json_success($result);
