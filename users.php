<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    header('Location: login.php');
    exit();
}

// Check if user is admin
if (!is_admin()) {
    header('Location: dashboard.php');
    exit();
}

try {
    $pdo = get_db_connection();
    
    // Get user info
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get all users
    $stmt = $pdo->query(
        "SELECT u.*, d.name as department_name 
         FROM users u 
         LEFT JOIN departments d ON u.department_id = d.department_id 
         ORDER BY u.full_name"
    );
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get departments for dropdown
    $stmt = $pdo->query("SELECT * FROM departments ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validate_csrf_token($_POST['csrf_token'])) {
            throw new Exception('Invalid request');
        }
        
        $action = $_POST['action'];
        
        switch ($action) {
            case 'add_user':
                // Validate input
                if (empty($_POST['username']) || empty($_POST['password']) || 
                    empty($_POST['email']) || empty($_POST['full_name']) || 
                    empty($_POST['role']) || empty($_POST['department_id'])) {
                    throw new Exception('All fields are required');
                }
                
                // Check if username exists
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                $stmt->execute([clean_input($_POST['username'])]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('Username already exists');
                }
                
                // Check if email exists
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                $stmt->execute([clean_input($_POST['email'])]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('Email already exists');
                }
                
                // Insert user
                $stmt = $pdo->prepare(
                    "INSERT INTO users (username, password, email, full_name, role, department_id, status) 
                     VALUES (?, ?, ?, ?, ?, ?, 'active')"
                );
                
                $stmt->execute([
                    clean_input($_POST['username']),
                    password_hash($_POST['password'], PASSWORD_DEFAULT),
                    clean_input($_POST['email']),
                    clean_input($_POST['full_name']),
                    clean_input($_POST['role']),
                    (int)$_POST['department_id']
                ]);
                
                $user_id = $pdo->lastInsertId();
                log_audit($_SESSION['user_id'], 'create', 'users', $user_id);
                
                $_SESSION['success'] = 'User added successfully';
                break;
                
            case 'edit_user':
                // Validate input
                if (empty($_POST['user_id']) || empty($_POST['email']) || 
                    empty($_POST['full_name']) || empty($_POST['role']) || 
                    empty($_POST['department_id'])) {
                    throw new Exception('All fields are required');
                }
                
                // Check if email exists for other users
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM users WHERE email = ? AND user_id != ?"
                );
                $stmt->execute([
                    clean_input($_POST['email']),
                    (int)$_POST['user_id']
                ]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('Email already exists');
                }
                
                // Update user
                $sql = "UPDATE users SET 
                        email = ?, 
                        full_name = ?, 
                        role = ?, 
                        department_id = ?, 
                        status = ?";
                
                $params = [
                    clean_input($_POST['email']),
                    clean_input($_POST['full_name']),
                    clean_input($_POST['role']),
                    (int)$_POST['department_id'],
                    clean_input($_POST['status'])
                ];
                
                // Add password to update if provided
                if (!empty($_POST['password'])) {
                    $sql .= ", password = ?";
                    $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
                }
                
                $sql .= " WHERE user_id = ?";
                $params[] = (int)$_POST['user_id'];
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                log_audit($_SESSION['user_id'], 'update', 'users', $_POST['user_id']);
                
                $_SESSION['success'] = 'User updated successfully';
                break;
                
            case 'delete_user':
                if (empty($_POST['user_id'])) {
                    throw new Exception('User ID is required');
                }
                
                // Prevent deleting own account
                if ((int)$_POST['user_id'] === $_SESSION['user_id']) {
                    throw new Exception('Cannot delete your own account');
                }
                
                $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
                $stmt->execute([(int)$_POST['user_id']]);
                
                log_audit($_SESSION['user_id'], 'delete', 'users', $_POST['user_id']);
                
                $_SESSION['success'] = 'User deleted successfully';
                break;
        }
        
        header('Location: users.php');
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
    <title>User Management - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .mb-0 {
    color: white;
}
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
        .dataTables_wrapper {
            color: #ffffff;
        }
        .dataTables_filter input {
            background-color: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.2);
            color: #ffffff;
        }
        .dataTables_length select {
            background-color: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.2);
            color: #ffffff;
        }
        .page-link {
            background-color: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.2);
            color: #ffffff;
        }
        .page-item.active .page-link {
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
                        <a class="nav-link" href="alerts.php">
                            <i class="fas fa-bell me-2"></i> Alert & Notifications
                        </a>
                    </li>
                    <?php if (is_admin()): ?>
                    <li class="nav-item">
                        <a class="nav-link active" href="users.php">
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
                <div class="card shadow-sm my-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h4 class="card-title mb-0">User Management</h4>
                            <div>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                                    <i class="fas fa-plus me-2"></i>Add User
                                </button>
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

                        <!-- Users Table -->
                        <div class="table-responsive">
                            <table id="usersTable" class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Full Name</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Department</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $u): ?>
                                    <tr>
                                        <td><?php echo sanitize_output($u['full_name']); ?></td>
                                        <td><?php echo sanitize_output($u['username']); ?></td>
                                        <td><?php echo sanitize_output($u['email']); ?></td>
                                        <td><?php echo sanitize_output($u['role']); ?></td>
                                        <td><?php echo sanitize_output($u['department_name']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $u['status'] === 'active' ? 'success' : 'danger'; ?>">
                                                <?php echo ucfirst($u['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-info edit-user" 
                                                    data-user='<?php echo json_encode($u); ?>'
                                                    data-bs-toggle="modal" data-bs-target="#editUserModal">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($u['user_id'] !== $_SESSION['user_id']): ?>
                                            <button type="button" class="btn btn-sm btn-danger delete-user"
                                                    data-user-id="<?php echo $u['user_id']; ?>"
                                                    data-bs-toggle="modal" data-bs-target="#deleteUserModal">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="add_user">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="role" class="form-label">Role</label>
                            <select class="form-select" id="role" name="role" required>
                                <option value="">Select Role</option>
                                <option value="admin">Admin</option>
                                <option value="supply_personnel">Supply Personnel</option>
                                <option value="department_head">Department Head</option>
                                <option value="auditor">Auditor</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="department_id" class="form-label">Department</label>
                            <select class="form-select" id="department_id" name="department_id" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['department_id']; ?>">
                                    <?php echo sanitize_output($dept['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="edit_email" name="email" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_full_name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="edit_full_name" name="full_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_password" class="form-label">New Password (leave blank to keep current)</label>
                            <input type="password" class="form-control" id="edit_password" name="password">
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_role" class="form-label">Role</label>
                            <select class="form-select" id="edit_role" name="role" required>
                                <option value="">Select Role</option>
                                <option value="admin">Admin</option>
                                <option value="supply_personnel">Supply Personnel</option>
                                <option value="department_head">Department Head</option>
                                <option value="auditor">Auditor</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_department_id" class="form-label">Department</label>
                            <select class="form-select" id="edit_department_id" name="department_id" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['department_id']; ?>">
                                    <?php echo sanitize_output($dept['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_status" class="form-label">Status</label>
                            <select class="form-select" id="edit_status" name="status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete User Modal -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" id="delete_user_id">
                    
                    <div class="modal-body">
                        <p>Are you sure you want to delete this user?</p>
                        <p class="text-danger mb-0">This action cannot be undone.</p>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize DataTable
            $('#usersTable').DataTable({
                order: [[0, 'asc']],
                pageLength: 25,
                language: {
                    search: "Search users:"
                }
            });
            
            // Handle edit user
            $('.edit-user').click(function() {
                var user = $(this).data('user');
                $('#edit_user_id').val(user.user_id);
                $('#edit_email').val(user.email);
                $('#edit_full_name').val(user.full_name);
                $('#edit_role').val(user.role);
                $('#edit_department_id').val(user.department_id);
                $('#edit_status').val(user.status);
            });
            
            // Handle delete user
            $('.delete-user').click(function() {
                var userId = $(this).data('user-id');
                $('#delete_user_id').val(userId);
            });
        });
        
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
    </script>
</body>
</html>
?> 