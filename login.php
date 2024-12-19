<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = get_db_connection();
        
        $username = clean_input($_POST['username']);
        $password = $_POST['password'];
        
        // Get user
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Debug password verification
            $stored_hash = $user['password'];
            $input_password = $password;
            
            if (password_verify($input_password, $stored_hash)) {
                // Password is correct
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                
                // Log successful login
                log_audit($user['user_id'], 'login', 'users', $user['user_id']);
                
                header('Location: dashboard.php');
                exit();
            } else {
                // Debug output
                error_log("Password verification failed");
                error_log("Stored hash: " . $stored_hash);
                error_log("Input password: " . $input_password);
                
                // Show all users in database for debugging
                $stmt = $pdo->query("SELECT username, email, role FROM users");
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                error_log("Users in database:");
                foreach ($users as $u) {
                    error_log("Username: {$u['username']}, Email: {$u['email']}, Role: {$u['role']}");
                }
                
                $error = 'Invalid username or password';
            }
        } else {
            $error = 'Invalid username or password';
        }
    } catch (Exception $e) {
        $error = 'Login failed: ' . $e->getMessage();
    }
}

// Debug: Show all users in database
try {
    if (isset($pdo)) {
        $stmt = $pdo->query("SELECT username, email, role FROM users");
        $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($all_users)) {
            $error .= "<br><br>Users in database:<br>";
            foreach ($all_users as $u) {
                $error .= "Username: {$u['username']}, Email: {$u['email']}, Role: {$u['role']}<br>";
            }
        } else {
            $error .= "<br><br>No users found in database.";
        }
    }
} catch (PDOException $e) {
    $error .= "<br>Error checking users: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #0a2463 0%, #3e92cc 100%);
            min-height: 100vh;
        }
        .login-container {
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
            <div class="col-md-6">
                <div class="login-container">
                    <div class="text-center">
                        <img src="assets/images/logo.png" alt="USTP Logo" class="logo">
                        <!--<h2 class="system-title">USCH</h2>--->
                        <h3 class="h5 mb-4">USTP Supply Chain Hub</h3>
                        <p class="system-subtitle">Streamlines inventory and supply management for efficient operations at USTP.</p>
                    </div>

                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger" role="alert">
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <?php $token = generate_csrf_token(); ?>
                        <input type="hidden" name="csrf_token" value="<?php echo $token; ?>">
                        
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">Sign In</button>
                        </div>
                    </form>

                    <div class="text-center mt-4">
                        <p>Don't have an account? <a href="register.php">Create New Account</a></p>
                        <a href="#" class="text-muted">Learn More</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 