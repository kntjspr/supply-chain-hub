<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Log the logout action
if (isset($_SESSION['user_id'])) {
    log_audit($_SESSION['user_id'], 'logged out', 'auth', $_SESSION['user_id']);
}

// Destroy the session
$auth->logout();

// Redirect to login page
header('Location: login.php');
exit();
 