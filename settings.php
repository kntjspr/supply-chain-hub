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
    
    // Get departments
    $stmt = $pdo->query("SELECT * FROM departments ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validate_csrf_token($_POST['csrf_token'])) {
            throw new Exception('Invalid request');
        }
        
        $action = $_POST['action'];
        
        switch ($action) {
            case 'update_profile':
                // Validate email uniqueness
                $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
                $stmt->execute([
                    clean_input($_POST['email']),
                    $_SESSION['user_id']
                ]);
                if ($stmt->fetch()) {
                    throw new Exception('Email already exists');
                }
                
                // Update user profile
                $stmt = $pdo->prepare(
                    "UPDATE users 
                     SET full_name = ?, 
                         email = ?, 
                         department_id = ? 
                     WHERE user_id = ?"
                );
                
                $stmt->execute([
                    clean_input($_POST['full_name']),
                    clean_input($_POST['email']),
                    (int)$_POST['department_id'],
                    $_SESSION['user_id']
                ]);
                
                $_SESSION['success'] = 'Profile updated successfully';
                break;
                
            case 'change_password':
                // Verify current password
                if (!password_verify($_POST['current_password'], $user['password'])) {
                    throw new Exception('Current password is incorrect');
                }
                
                // Validate new password
                if ($_POST['new_password'] !== $_POST['confirm_password']) {
                    throw new Exception('New passwords do not match');
                }
                
                if (strlen($_POST['new_password']) < 8) {
                    throw new Exception('Password must be at least 8 characters long');
                }
                
                // Update password
                $stmt = $pdo->prepare(
                    "UPDATE users 
                     SET password = ? 
                     WHERE user_id = ?"
                );
                
                $stmt->execute([
                    password_hash($_POST['new_password'], PASSWORD_DEFAULT),
                    $_SESSION['user_id']
                ]);
                
                $_SESSION['success'] = 'Password changed successfully';
                break;
                
            case 'update_theme':
                // Update theme preference
                $stmt = $pdo->prepare(
                    "UPDATE users 
                     SET theme_preference = ? 
                     WHERE user_id = ?"
                );
                
                $stmt->execute([
                    clean_input($_POST['theme']),
                    $_SESSION['user_id']
                ]);
                
                $_SESSION['success'] = 'Theme preference updated successfully';
                break;
        }
        
        header('Location: settings.php');
        exit();
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
    <title>Settings - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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
        .card {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            backdrop-filter: blur(10px);
        }
        .form-control, .form-select {
            background-color: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.2);
            color: #ffffff;
        }
        .form-control:focus, .form-select:focus {
            background-color: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.3);
            color: #ffffff;
            box-shadow: 0 0 0 0.25rem rgba(255, 255, 255, 0.1);
        }
        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.5);
        }
        .form-select option {
            background-color: #2c3154;
            color: #ffffff;
        }
        .form-label {
            color: #ffffff;
        }
        .text-muted {
            color: rgba(255, 255, 255, 0.6) !important;
        }
        .modal-content {
            background-color: #2c3154;
            color: #ffffff;
        }
        .modal-header {
            border-bottom-color: rgba(255, 255, 255, 0.1);
        }
        .modal-footer {
            border-top-color: rgba(255, 255, 255, 0.1);
        }
    </style>
</head>
<body>
        <style>.mb-0 {
        color: white;
    }</style>
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
                        <a class="nav-link" href="alerts.php">
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
                        <a class="nav-link active" href="settings.php">
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
                    <h4>Settings</h4>
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

                <div class="row">
                    <!-- Profile Settings -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Profile Settings</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="" class="needs-validation" novalidate>
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="action" value="update_profile">
                                    
                                    <div class="mb-3">
                                        <label for="full_name" class="form-label">Full Name</label>
                                        <input type="text" class="form-control" id="full_name" name="full_name" 
                                               value="<?php echo sanitize_output($user['full_name']); ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email</label>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?php echo sanitize_output($user['email']); ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="department_id" class="form-label">Department</label>
                                        <select class="form-select" id="department_id" name="department_id" required>
                                            <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo $dept['department_id']; ?>" 
                                                    <?php echo $dept['department_id'] == $user['department_id'] ? 'selected' : ''; ?>>
                                                <?php echo sanitize_output($dept['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">Update Profile</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Password Settings -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Change Password</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="" class="needs-validation" novalidate>
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="action" value="change_password">
                                    
                                    <div class="mb-3">
                                        <label for="current_password" class="form-label">Current Password</label>
                                        <input type="password" class="form-control" id="current_password" 
                                               name="current_password" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="new_password" class="form-label">New Password</label>
                                        <input type="password" class="form-control" id="new_password" 
                                               name="new_password" required minlength="8">
                                        <small class="text-muted">
                                            Password must be at least 8 characters long
                                        </small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                                        <input type="password" class="form-control" id="confirm_password" 
                                               name="confirm_password" required>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">Change Password</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Theme Settings -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Theme Settings</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="" class="needs-validation" novalidate>
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="action" value="update_theme">
                                    
                                    <div class="mb-3">
                                        <label for="theme" class="form-label">Theme</label>
                                        <select class="form-select" id="theme" name="theme" required>
                                            <option value="dark" <?php echo ($user['theme_preference'] ?? 'dark') === 'dark' ? 'selected' : ''; ?>>
                                                Dark Theme
                                            </option>
                                            <option value="light" <?php echo ($user['theme_preference'] ?? 'dark') === 'light' ? 'selected' : ''; ?>>
                                                Light Theme
                                            </option>
                                        </select>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">Update Theme</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation
        (function () {
            'use strict'
            var forms = document.querySelectorAll('.needs-validation')
            Array.prototype.slice.call(forms).forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                    }
                    form.classList.add('was-validated')
                }, false)
            })
        })()
        
        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            if (this.value !== document.getElementById('new_password').value) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html> 