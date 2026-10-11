<?php
define('ICARS_API', true);
require_once dirname(__DIR__, 4) . '/includes/app_init.php';

api_guard(['GET'], [ROLE_ADMIN]);

$statuses = array_column(complaint_enabled_statuses(), 'name');
$status   = get_string('status');

$result = complaint_admin_list([
    'q'          => mb_substr(get_string('q'), 0, 100),
    'status'     => in_array($status, $statuses, true) ? $status : '',
    'category'   => get_string('category'),
    'priority'   => get_string('priority'),
    'department' => get_string('department'),
    'assignee'   => get_string('assignee'),
    'sort'       => get_string('sort', 'newest'),
    'page'       => (int)get_string('page', '1'),
    'per_page'   => 10,
]);
foreach ($result['complaints'] as &$c) {
    $c['id'] = (int)$c['id'];
}
unset($c);

$result['meta'] = [
    'statuses'    => $statuses,
    'categories'  => complaint_categories(),
    'priorities'  => complaint_levels(),
    'departments' => departments_all(),
    'staff'       => array_map(static fn($s) => ['id' => (int)$s['id'], 'name' => $s['full_name'] . ' (' . $s['department_name'] . ')'], complaint_staff_options()),
];
json_success($result);
