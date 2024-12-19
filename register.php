<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/functions.php';

// If user is already logged in, redirect to dashboard
if (is_logged_in()) {
    header('Location: dashboard.php');
    exit();
}

// Process registration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
        );
        
        // Validate CSRF token
        if (!validate_csrf_token($_POST['csrf_token'])) {
            throw new Exception('Invalid request');
        }
        
        // Clean and validate input
        $username = clean_input($_POST['username']);
        $email = clean_input($_POST['email']);
        $full_name = clean_input($_POST['full_name']);
        $department_id = (int)$_POST['department_id'];
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validate password match
        if ($password !== $confirm_password) {
            throw new Exception('Passwords do not match');
        }
        
        // Validate password strength
        if (strlen($password) < 8) {
            throw new Exception('Password must be at least 8 characters long');
        }
        
        // Check if username exists
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            throw new Exception('Username already exists');
        }
        
        // Check if email exists
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new Exception('Email already exists');
        }
        
        // Check if department exists
        $stmt = $pdo->prepare("SELECT department_id FROM departments WHERE department_id = ?");
        $stmt->execute([$department_id]);
        if (!$stmt->fetch()) {
            throw new Exception('Invalid department');
        }
        
        // Create user
        $stmt = $pdo->prepare(
            "INSERT INTO users (username, password, email, full_name, role, department_id, status) 
             VALUES (?, ?, ?, ?, 'staff', ?, 'active')"
        );
        
        $stmt->execute([
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            $email,
            $full_name,
            $department_id
        ]);
        
        // Log the registration
        $user_id = $pdo->lastInsertId();
        log_audit($user_id, 'register', 'users', $user_id);
        
        // Redirect to login with success message
        $_SESSION['success'] = 'Registration successful. You can now log in.';
        header('Location: login.php');
        exit();
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get departments for dropdown
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    
    $stmt = $pdo->query("SELECT department_id, name FROM departments ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error loading departments: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #0a2463 0%, #3e92cc 100%);
            min-height: 100vh;
        }
        .register-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-top: 5vh;
        }
        .logo {
            max-width: 150px;
            margin-bottom: 1rem;
        }
        .btn-primary {
            background-color: #0a2463;
            border-color: #0a2463;
        }
        .btn-primary:hover {
            background-color: #3e92cc;
            border-color: #3e92cc;
        }
        .form-control {
            border-radius: 8px;
            padding: 0.75rem 1rem;
        }
        .system-title {
            color: #0a2463;
            font-weight: bold;
            margin-bottom: 1.5rem;
        }
        .system-subtitle {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 2rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="register-container">
                    <div class="text-center">
                        <img src="assets/images/logo.png" alt="USTP Logo" class="logo">
                        <h3 class="h5 mb-4">Create New Account</h3>
                        <p class="system-subtitle">Join USTP Supply Chain Hub to manage inventory and supply requests.</p>
                    </div>

                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger" role="alert">
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" class="needs-validation" novalidate>
                        <?php $token = generate_csrf_token(); ?>
                        <input type="hidden" name="csrf_token" value="<?php echo $token; ?>">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" name="username" required 
                                       pattern="[a-zA-Z0-9_]{3,20}" 
                                       title="Username must be 3-20 characters long and can only contain letters, numbers, and underscores"
                                       value="<?php echo isset($_POST['username']) ? sanitize_output($_POST['username']) : ''; ?>">
                                <div class="invalid-feedback">
                                    Please choose a valid username.
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" required
                                       value="<?php echo isset($_POST['email']) ? sanitize_output($_POST['email']) : ''; ?>">
                                <div class="invalid-feedback">
                                    Please provide a valid email.
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="full_name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required
                                   value="<?php echo isset($_POST['full_name']) ? sanitize_output($_POST['full_name']) : ''; ?>">
                            <div class="invalid-feedback">
                                Please enter your full name.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="department_id" class="form-label">Department</label>
                            <select class="form-select" id="department_id" name="department_id" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['department_id']; ?>" 
                                            <?php echo (isset($_POST['department_id']) && $_POST['department_id'] == $dept['department_id']) ? 'selected' : ''; ?>>
                                        <?php echo sanitize_output($dept['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">
                                Please select your department.
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required
                                       pattern=".{8,}" title="Password must be at least 8 characters long">
                                <div class="invalid-feedback">
                                    Password must be at least 8 characters long.
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="confirm_password" class="form-label">Confirm Password</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                <div class="invalid-feedback">
                                    Please confirm your password.
                                </div>
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">Create Account</button>
                        </div>
                    </form>

                    <div class="text-center mt-4">
                        <p>Already have an account? <a href="login.php">Sign In</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

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

        // Password match validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            if (this.value !== document.getElementById('password').value) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html> 