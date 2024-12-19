<?php
require_once 'includes/config.php';
$pageTitle = "Page Not Found";
ob_start();
?>

<div class="text-center py-5">
    <i class="fas fa-exclamation-circle text-danger fa-5x mb-4"></i>
    <h1 class="h2 mb-4">404 - Page Not Found</h1>
    <p class="text-muted mb-4">The page you are looking for might have been removed, had its name changed, or is temporarily unavailable.</p>
    <a href="dashboard.php" class="btn btn-primary">
        <i class="fas fa-home"></i> Back to Dashboard
    </a>
</div>

<?php
$content = ob_get_clean();
require_once 'includes/layout.php';
?> 