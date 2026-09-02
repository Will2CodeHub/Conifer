<?php
require_once 'config.php';

if (isLoggedIn()) {
    logActivity('logout', 'user', $_SESSION['ten_user_id'], 'User logged out');
}

// Destroy session
session_unset();
session_destroy();

// Redirect to login
header('Location: login.php?logged_out=1');
exit();
