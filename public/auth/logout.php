<?php
require_once dirname(__DIR__, 2) . '/includes/app_init.php';

// Logout is POST-only + CSRF-protected so it cannot be triggered by a link/image.
if (!is_post()) {
    redirect('');
}
csrf_require();
auth_logout();

session_boot();                       // fresh session to carry the flash message
flash_set('success', 'You have been logged out.');
redirect('auth/login.php');
