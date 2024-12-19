<?php
require_once 'config.php';
require_once 'auth.php';

if (!isset($pageTitle)) {
    $pageTitle = SITE_NAME;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - <?php echo SITE_NAME; ?></title>
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <?php if (isset($extraStyles)) echo $extraStyles; ?>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo-container">
            <img src="assets/images/logo.png" alt="USTP Logo">
            <div>
                <h1 class="logo-text">USTP</h1>
                <small>Supply Chain Hub</small>
            </div>
        </div>
        
        <div class="user-info p-3">
            <div class="d-flex align-items-center gap-2">
                <i class="fas fa-user-circle fa-2x"></i>
                <div>
                    <div class="fw-bold"><?php echo $_SESSION['full_name']; ?></div>
                    <small><?php echo ucfirst($_SESSION['role']); ?></small>
                </div>
            </div>
        </div>
        
        <nav class="mt-3">
            <a href="dashboard.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="inventory.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'inventory.php' ? 'active' : ''; ?>">
                <i class="fas fa-boxes"></i> Inventory Management
            </a>
            <a href="requests.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'requests.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-alt"></i> Supply Request Module
            </a>
            <a href="procurement.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'procurement.php' ? 'active' : ''; ?>">
                <i class="fas fa-shopping-cart"></i> Procurement & Distribution
            </a>
            <a href="reports.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar"></i> Audit & Reporting
            </a>
            <a href="returns.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'returns.php' ? 'active' : ''; ?>">
                <i class="fas fa-undo"></i> Return and Exchange
            </a>
            
            <?php if ($auth->hasPermission('admin')): ?>
            <a href="users.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> User Management
            </a>
            <?php endif; ?>
        </nav>
        
        <div class="mt-auto p-3">
            <a href="settings.php" class="nav-link">
                <i class="fas fa-cog"></i> Settings
            </a>
            <a href="help.php" class="nav-link">
                <i class="fas fa-question-circle"></i> Help Center
            </a>
            <a href="logout.php" class="nav-link text-danger">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <!-- Main content -->
    <div class="main-content">
        <!-- Top header -->
        <div class="top-header">
            <div class="d-flex align-items-center">
                <h2 class="h4 mb-0"><?php echo $pageTitle; ?></h2>
                <div class="search-bar">
                    <input type="text" class="form-control" placeholder="Search...">
                </div>
            </div>
            
            <div class="user-menu">
                <div class="notification-badge">
                    <a href="#" class="btn btn-light rounded-circle">
                        <i class="fas fa-bell"></i>
                        <span class="badge bg-danger">3</span>
                    </a>
                </div>
                
                <div class="notification-badge">
                    <a href="#" class="btn btn-light rounded-circle">
                        <i class="fas fa-envelope"></i>
                        <span class="badge bg-danger">5</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Page content -->
        <div class="content-wrapper">
            <?php 
            echo get_flash_message();
            if (isset($content)) echo $content;
            ?>
        </div>
    </div>

    <!-- Loading Spinner -->
    <?php require_once 'spinner.php'; ?>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/script.js"></script>
    <?php if (isset($extraScripts)) echo $extraScripts; ?>
</body>
</html> 