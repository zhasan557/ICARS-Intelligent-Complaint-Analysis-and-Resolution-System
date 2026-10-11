<?php
define('ICARS_API', true);
require_once dirname(__DIR__, 3) . '/includes/app_init.php';

api_guard(['GET']);

json_success([
    'authenticated' => auth_check(),
    'user'          => auth_check() ? auth_user() : null,
    'csrf_token'    => csrf_token(),
]);
