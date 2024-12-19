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
    
    // Check if user has access to this module
    if ($user['role'] === 'auditor') {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Access Denied - <?php echo SITE_NAME; ?></title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
            <style>
                body {
                    background-color: #1a1f3c;
                    color: #ffffff;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                .access-denied {
                    text-align: center;
                    padding: 2rem;
                    background: rgba(255, 255, 255, 0.1);
                    border-radius: 15px;
                    backdrop-filter: blur(10px);
                }
                .icon {
                    font-size: 4rem;
                    color: #dc3545;
                    margin-bottom: 1rem;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="access-denied">
                    <i class="fas fa-exclamation-circle icon"></i>
                    <h2 class="mb-4">Access Denied</h2>
                    <p class="mb-4">Sorry, as an Auditor, you do not have access to the Inventory Management module.</p>
                    <a href="dashboard.php" class="btn btn-primary">
                        <i class="fas fa-home me-2"></i>Return to Dashboard
                    </a>
                </div>
            </div>
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        </body>
        </html>
        <?php
        exit();
    }
    
    // Process form submissions - only for admin and supply personnel
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($user['role'] === 'admin' || $user['role'] === 'supply_personnel')) {
        if (!validate_csrf_token($_POST['csrf_token'])) {
            throw new Exception('Invalid request');
        }
        
        $action = $_POST['action'];
        
        // Check if action is allowed for the user's role
        if ($user['role'] === 'supply_personnel' && in_array($action, ['delete', 'import'])) {
            throw new Exception('You do not have permission to perform this action');
        }
        
        switch ($action) {
            case 'add':
                $stmt = $pdo->prepare(
                    "INSERT INTO inventory (item_name, quantity, unit, unit_price, min_stock_level, expiry_date, status) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                
                // Calculate status based on quantity and min_stock_level
                $status = 'available';
                if ($_POST['quantity'] <= 0) {
                    $status = 'out_of_stock';
                } elseif ($_POST['quantity'] <= $_POST['min_stock_level']) {
                    $status = 'low_stock';
                }
                
                $stmt->execute([
                    clean_input($_POST['item_name']),
                    (int)$_POST['quantity'],
                    clean_input($_POST['unit']),
                    (float)$_POST['unit_price'],
                    (int)$_POST['min_stock_level'],
                    !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null,
                    $status
                ]);
                
                $item_id = $pdo->lastInsertId();
                log_audit($_SESSION['user_id'], 'add', 'inventory', $item_id);
                
                $_SESSION['success'] = 'Item added successfully';
                break;
                
            case 'edit':
                $stmt = $pdo->prepare(
                    "UPDATE inventory 
                     SET item_name = ?, quantity = ?, unit = ?, unit_price = ?, 
                         min_stock_level = ?, expiry_date = ?, status = ?
                     WHERE item_id = ?"
                );
                
                // Calculate status based on quantity and min_stock_level
                $status = 'available';
                if ($_POST['quantity'] <= 0) {
                    $status = 'out_of_stock';
                } elseif ($_POST['quantity'] <= $_POST['min_stock_level']) {
                    $status = 'low_stock';
                }
                
                $stmt->execute([
                    clean_input($_POST['item_name']),
                    (int)$_POST['quantity'],
                    clean_input($_POST['unit']),
                    (float)$_POST['unit_price'],
                    (int)$_POST['min_stock_level'],
                    !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null,
                    $status,
                    (int)$_POST['item_id']
                ]);
                
                log_audit($_SESSION['user_id'], 'edit', 'inventory', $_POST['item_id']);
                
                $_SESSION['success'] = 'Item updated successfully';
                break;
                
            case 'delete':
                $stmt = $pdo->prepare("DELETE FROM inventory WHERE item_id = ?");
                $stmt->execute([(int)$_POST['item_id']]);
                
                log_audit($_SESSION['user_id'], 'delete', 'inventory', $_POST['item_id']);
                
                $_SESSION['success'] = 'Item deleted successfully';
                break;
                
            case 'import':
                if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Please select a valid CSV file');
                }
                
                $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
                if (!$handle) {
                    throw new Exception('Error reading file');
                }
                
                // Skip header row
                fgetcsv($handle);
                
                $stmt = $pdo->prepare(
                    "INSERT INTO inventory (item_name, quantity, unit, unit_price, min_stock_level, expiry_date, status) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                
                while (($data = fgetcsv($handle)) !== false) {
                    $status = 'available';
                    if ($data[1] <= 0) {
                        $status = 'out_of_stock';
                    } elseif ($data[1] <= $data[4]) {
                        $status = 'low_stock';
                    }
                    
                    $stmt->execute([
                        $data[0], // item_name
                        (int)$data[1], // quantity
                        $data[2], // unit
                        (float)$data[3], // unit_price
                        (int)$data[4], // min_stock_level
                        !empty($data[5]) ? $data[5] : null, // expiry_date
                        $status
                    ]);
                }
                
                fclose($handle);
                $_SESSION['success'] = 'Items imported successfully';
                break;
        }
        
        header('Location: inventory.php');
        exit();
        
    }
    
    // Get inventory items based on role
    $sql = "SELECT * FROM inventory";
    
    $stmt = $pdo->query($sql);
    $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - <?php echo SITE_NAME; ?></title>
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
                        <a class="nav-link active" href="inventory.php">
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
                            <h4 class="card-title mb-0">Inventory Management</h4>
                            <div>
                                <?php if (is_admin()): ?>
                                <button type="button" class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#importModal">
                                    <i class="fas fa-file-import me-2"></i>Import
                                </button>
                                <a href="export.php?type=inventory" class="btn btn-info me-2">
                                    <i class="fas fa-file-export me-2"></i>Export
                                </a>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addItemModal">
                                    <i class="fas fa-plus me-2"></i>Add Item
                                </button>
                                <?php endif; ?>
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

                        <!-- Inventory Table -->
                        <div class="table-responsive">
                            <table id="inventoryTable" class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Item Name</th>
                                        <th>Quantity</th>
                                        <th>Unit</th>
                                        <th>Unit Price</th>
                                        <th>Min Stock</th>
                                        <th>Status</th>
                                        <th>Expiry Date</th>
                                        <?php if (is_admin()): ?>
                                        <th>Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $stmt = $pdo->query("SELECT * FROM inventory ORDER BY item_name");
                                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)):
                                        $status_class = 'success';
                                        if ($row['status'] === 'low_stock') {
                                            $status_class = 'warning';
                                        } elseif ($row['status'] === 'out_of_stock') {
                                            $status_class = 'danger';
                                        } elseif ($row['status'] === 'expired') {
                                            $status_class = 'dark';
                                        }
                                    ?>
                                    <tr>
                                        <td><?php echo sanitize_output($row['item_name']); ?></td>
                                        <td><?php echo number_format($row['quantity']); ?></td>
                                        <td><?php echo sanitize_output($row['unit']); ?></td>
                                        <td><?php echo number_format($row['unit_price'], 2); ?></td>
                                        <td><?php echo number_format($row['min_stock_level']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $status_class; ?>">
                                                <?php echo ucwords(str_replace('_', ' ', $row['status'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $row['expiry_date'] ? date('Y-m-d', strtotime($row['expiry_date'])) : ''; ?></td>
                                        <?php if ($user['role'] === 'admin'): ?>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-primary edit-item" 
                                                    data-item='<?php echo json_encode($row); ?>'
                                                    data-bs-toggle="modal" data-bs-target="#editItemModal">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger delete-item"
                                                    data-item-id="<?php echo $row['item_id']; ?>"
                                                    data-item-name="<?php echo sanitize_output($row['item_name']); ?>"
                                                    data-bs-toggle="modal" data-bs-target="#deleteItemModal">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                        <?php elseif ($user['role'] === 'supply_personnel'): ?>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-info edit-item" 
                                                    data-item='<?php echo json_encode($row); ?>'
                                                    data-bs-toggle="modal" data-bs-target="#editItemModal">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (is_admin()): ?>
    <!-- Add Item Modal -->
    <div class="modal fade" id="addItemModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="item_name" class="form-label">Item Name</label>
                            <input type="text" class="form-control" id="item_name" name="item_name" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="quantity" class="form-label">Quantity</label>
                                <input type="number" class="form-control" id="quantity" name="quantity" required min="0">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="unit" class="form-label">Unit</label>
                                <input type="text" class="form-control" id="unit" name="unit" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="unit_price" class="form-label">Unit Price</label>
                                <input type="number" class="form-control" id="unit_price" name="unit_price" required min="0" step="0.01">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="min_stock_level" class="form-label">Min Stock Level</label>
                                <input type="number" class="form-control" id="min_stock_level" name="min_stock_level" required min="0">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="expiry_date" class="form-label">Expiry Date</label>
                            <input type="date" class="form-control" id="expiry_date" name="expiry_date">
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Item</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Item Modal -->
    <div class="modal fade" id="editItemModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="item_id" id="edit_item_id">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_item_name" class="form-label">Item Name</label>
                            <input type="text" class="form-control" id="edit_item_name" name="item_name" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_quantity" class="form-label">Quantity</label>
                                <input type="number" class="form-control" id="edit_quantity" name="quantity" required min="0">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="edit_unit" class="form-label">Unit</label>
                                <input type="text" class="form-control" id="edit_unit" name="unit" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_unit_price" class="form-label">Unit Price</label>
                                <input type="number" class="form-control" id="edit_unit_price" name="unit_price" required min="0" step="0.01">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="edit_min_stock_level" class="form-label">Min Stock Level</label>
                                <input type="number" class="form-control" id="edit_min_stock_level" name="min_stock_level" required min="0">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_expiry_date" class="form-label">Expiry Date</label>
                            <input type="date" class="form-control" id="edit_expiry_date" name="expiry_date">
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Item Modal -->
    <div class="modal fade" id="deleteItemModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="item_id" id="delete_item_id">
                    
                    <div class="modal-body">
                        <p>Are you sure you want to delete <strong id="delete_item_name"></strong>?</p>
                        <p class="text-danger mb-0">This action cannot be undone.</p>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Item</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Import Modal -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Import Items</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="import">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="csv_file" class="form-label">CSV File</label>
                            <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
                            <small class="text-muted">
                                File should have headers: Item Name, Quantity, Unit, Unit Price, Min Stock Level, Expiry Date
                            </small>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Import</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize DataTable
            $('#inventoryTable').DataTable({
                order: [[0, 'asc']],
                pageLength: 25,
                language: {
                    search: "Search items:"
                }
            });
            
            // Handle edit item
            $('.edit-item').click(function() {
                var item = $(this).data('item');
                $('#edit_item_id').val(item.item_id);
                $('#edit_item_name').val(item.item_name);
                $('#edit_quantity').val(item.quantity);
                $('#edit_unit').val(item.unit);
                $('#edit_unit_price').val(item.unit_price);
                $('#edit_min_stock_level').val(item.min_stock_level);
                $('#edit_expiry_date').val(item.expiry_date ? item.expiry_date.split(' ')[0] : '');
            });
            
            // Handle delete item
            $('.delete-item').click(function() {
                var itemId = $(this).data('item-id');
                var itemName = $(this).data('item-name');
                $('#delete_item_id').val(itemId);
                $('#delete_item_name').text(itemName);
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