<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    header('Location: login.php');
    exit();
}

try {
    $pdo = get_db_connection();
    
    // Get user info
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Process form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validate_csrf_token($_POST['csrf_token'])) {
            throw new Exception('Invalid request');
        }
        
        $action = $_POST['action'];
        
        switch ($action) {
            case 'mark_read':
                $stmt = $pdo->prepare(
                    "UPDATE notifications 
                     SET is_read = 1, 
                         read_at = CURRENT_TIMESTAMP 
                     WHERE notification_id = ? AND user_id = ?"
                );
                
                $stmt->execute([
                    (int)$_POST['notification_id'],
                    $_SESSION['user_id']
                ]);
                
                $_SESSION['success'] = 'Notification marked as read';
                break;
                
            case 'mark_all_read':
                $stmt = $pdo->prepare(
                    "UPDATE notifications 
                     SET is_read = 1, 
                         read_at = CURRENT_TIMESTAMP 
                     WHERE user_id = ? AND is_read = 0"
                );
                
                $stmt->execute([$_SESSION['user_id']]);
                
                $_SESSION['success'] = 'All notifications marked as read';
                break;
                
            case 'update_settings':
                $stmt = $pdo->prepare(
                    "UPDATE notification_settings 
                     SET low_stock_alerts = ?,
                         pending_requests_alerts = ?,
                         request_status_alerts = ?,
                         return_alerts = ?,
                         email_notifications = ? 
                     WHERE user_id = ?"
                );
                
                $stmt->execute([
                    isset($_POST['low_stock_alerts']) ? 1 : 0,
                    isset($_POST['pending_requests_alerts']) ? 1 : 0,
                    isset($_POST['request_status_alerts']) ? 1 : 0,
                    isset($_POST['return_alerts']) ? 1 : 0,
                    isset($_POST['email_notifications']) ? 1 : 0,
                    $_SESSION['user_id']
                ]);
                
                $_SESSION['success'] = 'Notification settings updated successfully';
                break;
        }
        
        header('Location: alerts.php');
        exit();
    }
    
    // Get user's notifications
    $stmt = $pdo->prepare(
        "SELECT * FROM notifications 
         WHERE user_id = ? 
         ORDER BY created_at DESC"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get user's notification settings
    $stmt = $pdo->prepare(
        "SELECT * FROM notification_settings 
         WHERE user_id = ?"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If settings don't exist, create default settings
    if (!$settings) {
        $stmt = $pdo->prepare(
            "INSERT INTO notification_settings 
             (user_id, low_stock_alerts, pending_requests_alerts, request_status_alerts, return_alerts, email_notifications) 
             VALUES (?, 1, 1, 1, 1, 1)"
        );
        $stmt->execute([$_SESSION['user_id']]);
        
        $settings = [
            'low_stock_alerts' => 1,
            'pending_requests_alerts' => 1,
            'request_status_alerts' => 1,
            'return_alerts' => 1,
            'email_notifications' => 1
        ];
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alert & Notifications - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .mb-0 { color: white; }
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
        .card {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            backdrop-filter: blur(10px);
        }
        .table {
            color: #ffffff;
        }
        .table thead th {
            border-color: rgba(255, 255, 255, 0.2);
        }
        .table td {
            border-color: rgba(255, 255, 255, 0.1);
        }
        .notification-item {
            padding: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s;
        }
        .notification-item:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        .notification-item.unread {
            background-color: rgba(255, 255, 255, 0.05);
        }
        .notification-item .timestamp {
            font-size: 0.8rem;
            opacity: 0.7;
        }
        .form-check-input {
            background-color: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.2);
        }
        .form-check-input:checked {
            background-color: #0d6efd;
            border-color: #0d6efd;
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
                            <small class="text-muted"><?php echo sanitize_output($user['role']); ?></small>
                        </div>
                    </div>
                </div>

                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="inventory.php">
                            <i class="fas fa-boxes me-2"></i> Inventory Management
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="requests.php">
                            <i class="fas fa-file-alt me-2"></i> Supply Request Module
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="procurement.php">
                            <i class="fas fa-shopping-cart me-2"></i> Procurement & Distribution
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="audit.php">
                            <i class="fas fa-history me-2"></i> Audit & Reporting
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="returns.php">
                            <i class="fas fa-undo me-2"></i> Return and Exchange
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="alerts.php">
                            <i class="fas fa-bell me-2"></i> Alert & Notifications
                        </a>
                    </li>
                    <?php if (is_admin()): ?>
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
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4>Alert & Notifications</h4>
                    <div class="btn-group">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#settingsModal">
                            <i class="fas fa-cog me-2"></i>Notification Settings
                        </button>
                        <form method="POST" action="" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <input type="hidden" name="action" value="mark_all_read">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-check-double me-2"></i>Mark All as Read
                            </button>
                        </form>
                    </div>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php 
                        echo $_SESSION['success'];
                        unset($_SESSION['success']);
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Notifications List -->
                <div class="card">
                    <div class="card-body">
                        <?php if (empty($notifications)): ?>
                            <p class="text-center mb-0">No notifications to display</p>
                        <?php else: ?>
                            <?php foreach ($notifications as $notification): ?>
                                <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="mb-1">
                                                <?php if (!$notification['is_read']): ?>
                                                <span class="badge bg-primary me-2">New</span>
                                                <?php endif; ?>
                                                <?php echo sanitize_output($notification['message']); ?>
                                            </div>
                                            <div class="timestamp">
                                                <?php echo date('F j, Y g:i A', strtotime($notification['created_at'])); ?>
                                            </div>
                                        </div>
                                        <?php if (!$notification['is_read']): ?>
                                        <form method="POST" action="">
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="mark_read">
                                            <input type="hidden" name="notification_id" value="<?php echo $notification['notification_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-light">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification Settings Modal -->
    <div class="modal fade" id="settingsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Notification Settings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="update_settings">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="low_stock_alerts" name="low_stock_alerts"
                                       <?php echo $settings['low_stock_alerts'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="low_stock_alerts">
                                    Low Stock Alerts
                                </label>
                            </div>
                            <small class="text-muted">
                                Receive notifications when inventory items fall below minimum stock levels
                            </small>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="pending_requests_alerts" name="pending_requests_alerts"
                                       <?php echo $settings['pending_requests_alerts'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="pending_requests_alerts">
                                    Pending Requests Alerts
                                </label>
                            </div>
                            <small class="text-muted">
                                Receive notifications about new supply requests that need approval
                            </small>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="request_status_alerts" name="request_status_alerts"
                                       <?php echo $settings['request_status_alerts'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="request_status_alerts">
                                    Request Status Updates
                                </label>
                            </div>
                            <small class="text-muted">
                                Receive notifications when your supply requests are approved or rejected
                            </small>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="return_alerts" name="return_alerts"
                                       <?php echo $settings['return_alerts'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="return_alerts">
                                    Return Request Alerts
                                </label>
                            </div>
                            <small class="text-muted">
                                Receive notifications about new return requests and their status updates
                            </small>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="email_notifications" name="email_notifications"
                                       <?php echo $settings['email_notifications'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="email_notifications">
                                    Email Notifications
                                </label>
                            </div>
                            <small class="text-muted">
                                Receive notifications via email in addition to in-app notifications
                            </small>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 