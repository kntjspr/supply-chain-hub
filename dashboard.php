<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    header('Location: login.php');
    exit();
}

// Get user information and statistics
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    
    // Get user info
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get department
    $stmt = $pdo->prepare("SELECT name FROM departments WHERE department_id = ?");
    $stmt->execute([$user['department_id']]);
    $department = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get statistics
    $stats = array();
    
    // Supply Requests
    $stmt = $pdo->query("SELECT COUNT(*) FROM supply_requests");
    $stats['total_requests'] = $stmt->fetchColumn();
    
    // Approved Requests
    $stmt = $pdo->query("SELECT COUNT(*) FROM supply_requests WHERE status = 'approved'");
    $stats['approved_requests'] = $stmt->fetchColumn();
    
    // Pending Requests
    $stmt = $pdo->query("SELECT COUNT(*) FROM supply_requests WHERE status = 'pending'");
    $stats['pending_requests'] = $stmt->fetchColumn();
    
    // Rejected Requests
    $stmt = $pdo->query("SELECT COUNT(*) FROM supply_requests WHERE status = 'rejected'");
    $stats['rejected_requests'] = $stmt->fetchColumn();
    
    // Task Summary
    // Low Stock Items
    $stmt = $pdo->query("SELECT COUNT(*) FROM inventory WHERE status = 'low_stock'");
    $stats['low_stock'] = $stmt->fetchColumn();
    
    // Procurement Delays
    $stmt = $pdo->query("SELECT COUNT(*) FROM procurement_orders WHERE status = 'delayed'");
    $stats['procurement_delays'] = $stmt->fetchColumn();
    
    // Expiring Items
    $stmt = $pdo->query("SELECT COUNT(*) FROM inventory WHERE expiry_date <= DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY)");
    $stats['expiring_items'] = $stmt->fetchColumn();
    
    // Stock Quantities
    $stmt = $pdo->query("SELECT COUNT(*) FROM inventory WHERE quantity > 0");
    $stats['stock_quantities'] = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #1a1f3c;
            color: #ffffff;
        }
        .sidebar {
            background-color: #2c3154;
            min-height: 100vh;
            padding: 1rem;
        }
        .nav-link {
            color: #ffffff;
            opacity: 0.8;
            transition: all 0.3s;
        }
        .nav-link:hover {
            opacity: 1;
            color: #ffffff;
        }
        .nav-link.active {
            background-color: rgba(255, 255, 255, 0.1);
            opacity: 1;
        }
        .main-content {
            padding: 2rem;
        }
        .status-card {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            backdrop-filter: blur(10px);
        }
        .task-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s;
        }
        .task-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        .search-bar {
            background: rgba(255, 255, 255, 0.1);
            border: none;
            border-radius: 20px;
            padding: 0.5rem 1rem;
            color: #ffffff;
        }
        .search-bar::placeholder {
            color: rgba(255, 255, 255, 0.5);
        }
        .notification-icon {
            position: relative;
        }
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: #ff4444;
            border-radius: 50%;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }
        .datetime {
            font-size: 1.2rem;
            color: #ffd700;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar">
                <div class="text-center mb-4">
                    <img src="assets/images/logo.png" alt="USTP Logo" style="width: 80px;">
                    <h4 class="mt-2">USCH</h4>
                    <p class="mb-0">USTP Supply Chain Hub</p>
                </div>
                
                <div class="user-info mb-4">
                    <div class="d-flex align-items-center">
                        <div class="avatar me-3">
                            <i class="fas fa-user-circle fa-2x"></i>
                        </div>
                        <div>
                            <h6 class="mb-0"><?php echo sanitize_output($user['full_name']); ?></h6>
                            <small class="text"><?php echo sanitize_output($user['role']); ?></small>
                        </div>
                    </div>
                </div>

                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                        </a>
                    </li>
                    <?php if ($user['role'] !== 'auditor'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="inventory.php">
                            <i class="fas fa-boxes me-2"></i> Inventory Management
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if ($user['role'] !== 'auditor'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="requests.php">
                            <i class="fas fa-file-alt me-2"></i> Supply Request Module
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if ($user['role'] === 'admin' || $user['role'] === 'department_head' || $user['role'] === 'auditor'): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $user['role'] === 'supply_personnel' ? 'disabled' : ''; ?>" 
                           href="<?php echo $user['role'] === 'supply_personnel' ? '#' : 'procurement.php'; ?>"
                           <?php echo $user['role'] === 'supply_personnel' ? 'style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>
                            <i class="fas fa-shopping-cart me-2"></i> Procurement & Distribution
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link" href="audit.php">
                            <i class="fas fa-history me-2"></i> Audit & Reporting
                        </a>
                    </li>
                    <?php if ($user['role'] === 'admin' || $user['role'] === 'supply_personnel'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="returns.php">
                            <i class="fas fa-undo me-2"></i> Return and Exchange
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link" href="alerts.php">
                            <i class="fas fa-bell me-2"></i> Alert & Notifications
                        </a>
                    </li>
                    <?php if ($user['role'] === 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="users.php">
                            <i class="fas fa-users me-2"></i> User Management
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>

                <ul class="nav flex-column mt-4">
                    <li class="nav-item">
                        <a class="nav-link" href="settings.php">
                            <i class="fas fa-cog me-2"></i> Settings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="help.php">
                            <i class="fas fa-question-circle me-2"></i> Help Center
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">
                            <i class="fas fa-sign-out-alt me-2"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 main-content">
                <!-- Header -->
                <div class="header-section">
                    <h4>Welcome to USCH Dashboard!</h4>
                    <div class="d-flex align-items-center">
                        <div class="search-container me-4">
                            <input type="text" class="search-bar" placeholder="Search...">
                        </div>
                        <div class="notification-icon me-3">
                            <i class="fas fa-bell fa-lg"></i>
                            <span class="notification-badge">3</span>
                        </div>
                        <div class="message-icon">
                            <i class="fas fa-envelope fa-lg"></i>
                        </div>
                    </div>
                </div>

                <!-- Status Overview -->
                <h5 class="mb-4">STATUS OVERVIEW</h5>
                <div class="row">
                    <div class="col-md-6 col-lg-3">
                        <div class="status-card">
                            <h6>Supply Requests</h6>
                            <h3><?php echo number_format($stats['total_requests']); ?></h3>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="status-card">
                            <h6>Approved Requests</h6>
                            <h3><?php echo number_format($stats['approved_requests']); ?></h3>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="status-card">
                            <h6>Pending</h6>
                            <h3><?php echo number_format($stats['pending_requests']); ?></h3>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="status-card">
                            <h6>Rejected</h6>
                            <h3><?php echo number_format($stats['rejected_requests']); ?></h3>
                        </div>
                    </div>
                </div>

                <!-- Task Summary -->
                <h5 class="mb-4 mt-5">TASK SUMMARY</h5>
                <div class="row">
                    <div class="col-md-6 col-lg-3">
                        <div class="task-card">
                            <h6>Low Stock</h6>
                            <h3><?php echo number_format($stats['low_stock']); ?></h3>
                            <small>Items need reordering</small>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="task-card">
                            <h6>Procurement Delays</h6>
                            <h3><?php echo number_format($stats['procurement_delays']); ?></h3>
                            <small>Orders delayed</small>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="task-card">
                            <h6>Expiring Items</h6>
                            <h3><?php echo number_format($stats['expiring_items']); ?></h3>
                            <small>Within 30 days</small>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="task-card">
                            <h6>Stock Quantities</h6>
                            <h3><?php echo number_format($stats['stock_quantities']); ?></h3>
                            <small>Items in stock</small>
                        </div>
                    </div>
                </div>

                <!-- Date/Time Display -->
                <div class="datetime-display text-end mt-4">
                    <div class="datetime">
                        <i class="fas fa-calendar-alt me-2"></i>
                        <span id="current-datetime"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update datetime
        function updateDateTime() {
            const now = new Date();
            const options = { 
                hour: '2-digit', 
                minute: '2-digit',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            };
            document.getElementById('current-datetime').textContent = 
                now.toLocaleString('en-US', options);
        }
        
        updateDateTime();
        setInterval(updateDateTime, 1000);

        // Add this to your existing script section
        document.addEventListener('DOMContentLoaded', function() {
            // Disable clicks on restricted links for supply personnel
            <?php if ($user['role'] === 'supply_personnel'): ?>
            document.querySelectorAll('.nav-link.disabled').forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    alert('You do not have permission to access this module.');
                });
            });
            <?php endif; ?>
        });
    </script>
</body>
</html>
