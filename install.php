<?php
session_start();

// Check if already installed
if (file_exists('includes/config.php') && !isset($_GET['force'])) {
    header('Location: index.php');
    exit();
}

// Installation steps
$steps = array(
    1 => 'Database Configuration',
    2 => 'Admin Account Setup',
    3 => 'Initial Data Setup',
    4 => 'Installation Complete'
);

// Get current step
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case 1:
            // Validate database connection
            try {
                $db_host = $_POST['db_host'];
                $db_name = $_POST['db_name'];
                $db_user = $_POST['db_user'];
                $db_pass = $_POST['db_pass'];
                
                $pdo = new PDO(
                    "mysql:host=$db_host;charset=utf8mb4",
                    $db_user,
                    $db_pass,
                    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
                );
                
                // Drop database if exists and create new one
                $pdo->exec("DROP DATABASE IF EXISTS `$db_name`");
                $pdo->exec("CREATE DATABASE `$db_name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `$db_name`");
                
                // Save database config
                $config = array(
                    'DB_HOST' => $db_host,
                    'DB_NAME' => $db_name,
                    'DB_USER' => $db_user,
                    'DB_PASS' => $db_pass
                );
                $_SESSION['install_config'] = $config;
                
                // Import schema
                $schema = file_get_contents('database/schema.sql');
                $notifications_schema = file_get_contents('database/notifications.sql');
                $updates_schema = file_get_contents('database/updates.sql');
                
                // Combine schemas
                $schema .= "\n" . $notifications_schema . "\n" . $updates_schema;
                
                // Split schema into individual statements
                $statements = array_filter(
                    array_map('trim', explode(';', $schema)),
                    function($stmt) { return !empty($stmt); }
                );
                
                // Execute each statement
                foreach ($statements as $statement) {
                    try {
                        $pdo->exec($statement);
                    } catch (PDOException $e) {
                        // Ignore table already exists errors
                        if ($e->getCode() !== '42S01') {
                            throw $e;
                        }
                    }
                }
                
                header('Location: install.php?step=2');
                exit();
            } catch (PDOException $e) {
                $error = 'Database connection failed: ' . $e->getMessage();
            }
            break;
            
        case 2:
            // Create admin account
            try {
                $config = $_SESSION['install_config'];
                $pdo = new PDO(
                    "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
                    $config['DB_USER'],
                    $config['DB_PASS'],
                    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
                );
                
                // Check if admin department exists
                $stmt = $pdo->prepare("SELECT department_id FROM departments WHERE name = ?");
                $stmt->execute(array('System Administration'));
                $dept = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$dept) {
                    // Insert default department
                    $stmt = $pdo->prepare("INSERT INTO departments (name) VALUES (?)");
                    $stmt->execute(array('System Administration'));
                    $dept_id = $pdo->lastInsertId();
                } else {
                    $dept_id = $dept['department_id'];
                }
                
                // Check if username exists
                $username = $_POST['username'];
                $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
                $stmt->execute(array($username));
                if ($stmt->fetch()) {
                    throw new Exception('Username already exists. Please choose a different username.');
                }
                
                // Check if email exists
                $email = $_POST['email'];
                $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
                $stmt->execute(array($email));
                if ($stmt->fetch()) {
                    throw new Exception('Email already exists. Please use a different email address.');
                }
                
                // Create admin user
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $full_name = $_POST['full_name'];
                
                $stmt = $pdo->prepare(
                    "INSERT INTO users (username, password, full_name, email, role, department_id, status) 
                     VALUES (?, ?, ?, ?, 'admin', ?, 'active')"
                );
                $stmt->execute(array($username, $password, $full_name, $email, $dept_id));
                
                // Store admin username in session
                $_SESSION['admin_username'] = $username;
                
                header('Location: install.php?step=3');
                exit();
            } catch (Exception $e) {
                $error = 'Error creating admin account: ' . $e->getMessage();
            }
            break;
            
        case 3:
            try {
                $config = $_SESSION['install_config'];
                $pdo = new PDO(
                    "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
                    $config['DB_USER'],
                    $config['DB_PASS'],
                    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
                );
                
                // Debug: Check if admin user exists
                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND role = 'admin'");
                $stmt->execute([$_SESSION['admin_username']]);
                $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$admin) {
                    die("Error: Admin user not found in database. Installation incomplete.");
                }
                
                // Insert sample departments if they don't exist
                $departments = array(
                    'Information Technology',
                    'Human Resources',
                    'Finance',
                    'Operations'
                );
                
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE name = ?");
                $insertStmt = $pdo->prepare("INSERT INTO departments (name) VALUES (?)");
                
                foreach ($departments as $dept) {
                    $stmt->execute(array($dept));
                    if ($stmt->fetchColumn() == 0) {
                        $insertStmt->execute(array($dept));
                    }
                }
                
                // Create config file
                $config_content = "<?php\n";
                $config_content .= "define('SITE_NAME', 'USTP Supply Chain Hub');\n";
                $config_content .= "define('DB_HOST', '{$config['DB_HOST']}');\n";
                $config_content .= "define('DB_NAME', '{$config['DB_NAME']}');\n";
                $config_content .= "define('DB_USER', '{$config['DB_USER']}');\n";
                $config_content .= "define('DB_PASS', '{$config['DB_PASS']}');\n";
                
                // Create includes directory if it doesn't exist
                if (!is_dir('includes')) {
                    mkdir('includes', 0755, true);
                }
                
                file_put_contents('includes/config.php', $config_content);
                
                // Create required directories
                $directories = array(
                    'assets/images',
                    'assets/uploads',
                    'templates',
                    'logs'
                );
                
                foreach ($directories as $dir) {
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                }
                
                // Clear installation session
                unset($_SESSION['install_config']);
                
                // Redirect to login with success message
                $_SESSION['success'] = 'Installation completed successfully. You can now log in with your admin account.';
                header('Location: login.php');
                exit();
            } catch (Exception $e) {
                $error = 'Error setting up initial data: ' . $e->getMessage();
            }
            break;
    }
}

// Page title
$pageTitle = 'Install USTP Supply Chain Hub';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .install-container {
            max-width: 600px;
            margin: 50px auto;
        }
        .steps {
            margin-bottom: 30px;
        }
        .step {
            padding: 10px;
            border-bottom: 2px solid #dee2e6;
            color: #6c757d;
        }
        .step.active {
            border-bottom-color: #0d6efd;
            color: #0d6efd;
        }
        .step.completed {
            border-bottom-color: #198754;
            color: #198754;
        }
    </style>
</head>
<body>
    <div class="install-container">
        <div class="text-center mb-4">
            <img src="assets/images/logo.png" alt="USTP Logo" style="width: 100px;">
            <h2 class="mt-3"><?php echo $pageTitle; ?></h2>
        </div>
        
        <!-- Installation Steps -->
        <div class="steps">
            <div class="row">
                <?php foreach ($steps as $num => $title): ?>
                <div class="col-3">
                    <div class="step text-center <?php 
                        if ($num === $step) echo 'active';
                        elseif ($num < $step) echo 'completed';
                    ?>">
                        <div class="step-number">
                            <?php if ($num < $step): ?>
                                <i class="fas fa-check"></i>
                            <?php else: ?>
                                <?php echo $num; ?>
                            <?php endif; ?>
                        </div>
                        <div class="step-title small"><?php echo $title; ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Error Messages -->
        <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <?php echo $error; ?>
        </div>
        <?php endif; ?>
        
        <!-- Step Content -->
        <div class="card">
            <div class="card-body">
                <?php switch ($step):
                    case 1: // Database Configuration ?>
                    <form method="POST" action="?step=1">
                        <div class="mb-3">
                            <label for="db_host" class="form-label">Database Host</label>
                            <input type="text" class="form-control" id="db_host" name="db_host" 
                                   value="localhost" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="db_name" class="form-label">Database Name</label>
                            <input type="text" class="form-control" id="db_name" name="db_name" 
                                   value="usch_db" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="db_user" class="form-label">Database Username</label>
                            <input type="text" class="form-control" id="db_user" name="db_user" 
                                   value="root" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="db_pass" class="form-label">Database Password</label>
                            <input type="password" class="form-control" id="db_pass" name="db_pass">
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">
                                Continue <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </form>
                    <?php break;
                    
                    case 2: // Admin Account Setup ?>
                    <form method="POST" action="?step=2">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">
                                Continue <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </form>
                    <?php break;
                    
                    case 3: // Initial Data Setup ?>
                    <form method="POST" action="?step=3">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            We'll now set up some initial data for your system, including:
                            <ul class="mb-0">
                                <li>Default departments</li>
                                <li>System configuration</li>
                                <li>Required directories</li>
                            </ul>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">
                                Start Installation <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </form>
                    <?php break;
                    
                    case 4: // Installation Complete ?>
                    <div class="text-center">
                        <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
                        <h4>Installation Complete!</h4>
                        <p>The USTP Supply Chain Hub has been successfully installed.</p>
                        <div class="d-grid">
                            <a href="login.php" class="btn btn-primary">
                                Go to Login <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                    <?php break;
                endswitch; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 