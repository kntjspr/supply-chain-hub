<?php
// Check if system is installed
if (!file_exists('includes/config.php')) {
    header('Location: install.php');
    exit();
}

require_once 'includes/config.php';
require_once 'includes/auth.php';

if ($auth->isLoggedIn()) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit();
 