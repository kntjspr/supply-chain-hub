<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    header('Location: login.php');
    exit();
}

// Get user information
try {
    $pdo = get_db_connection();
    
    // Get user info
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check if user is supply personnel
    if ($user['role'] === 'supply_personnel') {
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
                    <p class="mb-4">Sorry, as a Supply Personnel, you do not have access to the Procurement & Distribution module.</p>
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

    // Process form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_admin()) {
        if (!validate_csrf_token($_POST['csrf_token'])) {
            throw new Exception('Invalid request');
        }
        
        $action = $_POST['action'];
        
        switch ($action) {
            case 'create_order':
                // Start transaction
                $pdo->beginTransaction();
                
                try {
                    // Insert procurement order
                    $stmt = $pdo->prepare(
                        "INSERT INTO procurement_orders (supplier_id, processor_id, status, total_amount, order_date) 
                         VALUES (?, ?, 'pending', ?, CURRENT_TIMESTAMP)"
                    );
                    
                    $total_amount = 0;
                    foreach ($_POST['items'] as $item) {
                        $total_amount += (float)$item['quantity'] * (float)$item['unit_price'];
                    }
                    
                    $stmt->execute([
                        (int)$_POST['supplier_id'],
                        $_SESSION['user_id'],
                        $total_amount
                    ]);
                    
                    $order_id = $pdo->lastInsertId();
                    
                    // Insert procurement items
                    $stmt = $pdo->prepare(
                        "INSERT INTO procurement_items (order_id, item_id, quantity, unit_price) 
                         VALUES (?, ?, ?, ?)"
                    );
                    
                    foreach ($_POST['items'] as $item) {
                        $stmt->execute([
                            $order_id,
                            (int)$item['item_id'],
                            (int)$item['quantity'],
                            (float)$item['unit_price']
                        ]);
                    }
                    
                    // Log the order
                    log_audit($_SESSION['user_id'], 'create', 'procurement_orders', $order_id);
                    
                    $pdo->commit();
                    $_SESSION['success'] = 'Procurement order created successfully';
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                break;
                
            case 'update_status':
                $stmt = $pdo->prepare(
                    "UPDATE procurement_orders 
                     SET status = ?, delivery_date = CASE WHEN ? = 'received' THEN CURRENT_TIMESTAMP ELSE delivery_date END 
                     WHERE order_id = ?"
                );
                
                $stmt->execute([
                    clean_input($_POST['status']),
                    clean_input($_POST['status']),
                    (int)$_POST['order_id']
                ]);
                
                // If order is received, update inventory quantities
                if ($_POST['status'] === 'received') {
                    $stmt = $pdo->prepare(
                        "SELECT pi.item_id, pi.quantity 
                         FROM procurement_items pi 
                         WHERE pi.order_id = ?"
                    );
                    $stmt->execute([(int)$_POST['order_id']]);
                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $stmt = $pdo->prepare(
                        "UPDATE inventory 
                         SET quantity = quantity + ?, 
                             status = CASE 
                                WHEN quantity + ? > min_stock_level THEN 'available'
                                ELSE status 
                             END 
                         WHERE item_id = ?"
                    );
                    
                    foreach ($items as $item) {
                        $stmt->execute([
                            $item['quantity'],
                            $item['quantity'],
                            $item['item_id']
                        ]);
                    }
                }
                
                log_audit($_SESSION['user_id'], 'update_status', 'procurement_orders', $_POST['order_id']);
                
                $_SESSION['success'] = 'Order status updated successfully';
                break;
        }
        
        header('Location: procurement.php');
        exit();
    }
    
    // Get suppliers
    $stmt = $pdo->query("SELECT * FROM suppliers ORDER BY name");
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get inventory items
    $stmt = $pdo->query("SELECT * FROM inventory ORDER BY item_name");
    $inventory_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get procurement orders
    $stmt = $pdo->query(
        "SELECT po.*, s.name as supplier_name, u.full_name as processor_name 
         FROM procurement_orders po 
         JOIN suppliers s ON po.supplier_id = s.supplier_id 
         JOIN users u ON po.processor_id = u.user_id 
         ORDER BY po.order_date DESC"
    );
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement & Distribution - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .card-body { color: white; }
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
                        <a class="nav-link active" href="procurement.php">
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
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4>Procurement & Distribution</h4>
                    <?php if (is_admin()): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newOrderModal">
                        <i class="fas fa-plus me-2"></i>New Order
                    </button>
                    <?php endif; ?>
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

                <!-- Orders Table -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="ordersTable" class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Order ID</th>
                                        <th>Supplier</th>
                                        <th>Total Amount</th>
                                        <th>Status</th>
                                        <th>Order Date</th>
                                        <th>Delivery Date</th>
                                        <?php if (is_admin()): ?>
                                        <th>Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orders as $order): ?>
                                    <tr>
                                        <td>#<?php echo $order['order_id']; ?></td>
                                        <td><?php echo sanitize_output($order['supplier_name']); ?></td>
                                        <td><?php echo number_format($order['total_amount'], 2); ?></td>
                                        <td>
                                            <?php
                                            $status_class = 'secondary';
                                            switch ($order['status']) {
                                                case 'pending':
                                                    $status_class = 'warning';
                                                    break;
                                                case 'ordered':
                                                    $status_class = 'info';
                                                    break;
                                                case 'received':
                                                    $status_class = 'success';
                                                    break;
                                                case 'cancelled':
                                                    $status_class = 'danger';
                                                    break;
                                            }
                                            ?>
                                            <span class="badge bg-<?php echo $status_class; ?>">
                                                <?php echo ucfirst($order['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('Y-m-d', strtotime($order['order_date'])); ?></td>
                                        <td><?php echo $order['delivery_date'] ? date('Y-m-d', strtotime($order['delivery_date'])) : '-'; ?></td>
                                        <?php if (is_admin()): ?>
                                        <td>
                                            <?php if ($order['status'] === 'pending'): ?>
                                            <button type="button" class="btn btn-sm btn-success update-status" 
                                                    data-order-id="<?php echo $order['order_id']; ?>"
                                                    data-status="ordered">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger update-status"
                                                    data-order-id="<?php echo $order['order_id']; ?>"
                                                    data-status="cancelled">
                                                <i class="fas fa-times"></i>
                                            </button>
                                            <?php elseif ($order['status'] === 'ordered'): ?>
                                            <button type="button" class="btn btn-sm btn-success update-status"
                                                    data-order-id="<?php echo $order['order_id']; ?>"
                                                    data-status="received">
                                                <i class="fas fa-truck"></i>
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
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

    <?php if (is_admin()): ?>
    <!-- New Order Modal -->
    <div class="modal fade" id="newOrderModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">New Procurement Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="create_order">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="supplier_id" class="form-label">Supplier</label>
                            <select class="form-select" id="supplier_id" name="supplier_id" required>
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['supplier_id']; ?>">
                                    <?php echo sanitize_output($supplier['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Items</label>
                            <div id="item-container">
                                <div class="item-row mb-2">
                                    <div class="row">
                                        <div class="col-md-5">
                                            <select class="form-select" name="items[0][item_id]" required>
                                                <option value="">Select Item</option>
                                                <?php foreach ($inventory_items as $item): ?>
                                                <option value="<?php echo $item['item_id']; ?>">
                                                    <?php echo sanitize_output($item['item_name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <input type="number" class="form-control" name="items[0][quantity]" 
                                                   placeholder="Quantity" required min="1">
                                        </div>
                                        <div class="col-md-3">
                                            <input type="number" class="form-control" name="items[0][unit_price]" 
                                                   placeholder="Unit Price" required min="0" step="0.01">
                                        </div>
                                        <div class="col-md-1">
                                            <button type="button" class="btn btn-danger remove-item" style="display: none;">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary mt-2" id="add-item">
                                <i class="fas fa-plus me-2"></i>Add Item
                            </button>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Order</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Status Form (Hidden) -->
    <form id="updateStatusForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="order_id" id="update_order_id">
        <input type="hidden" name="status" id="update_status">
    </form>
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize DataTable
            $('#ordersTable').DataTable({
                order: [[4, 'desc']],
                pageLength: 25,
                language: {
                    search: "Search orders:"
                }
            });
            
            let itemCount = 1;
            
            // Add item row
            $('#add-item').click(function() {
                const newRow = $('.item-row').first().clone();
                newRow.find('select').attr('name', `items[${itemCount}][item_id]`).val('');
                newRow.find('input[name$="[quantity]"]').attr('name', `items[${itemCount}][quantity]`).val('');
                newRow.find('input[name$="[unit_price]"]').attr('name', `items[${itemCount}][unit_price]`).val('');
                newRow.find('.remove-item').show();
                $('#item-container').append(newRow);
                itemCount++;
            });
            
            // Remove item row
            $(document).on('click', '.remove-item', function() {
                $(this).closest('.item-row').remove();
            });
            
            // Handle status update
            $('.update-status').click(function() {
                const orderId = $(this).data('order-id');
                const status = $(this).data('status');
                const statusText = status.charAt(0).toUpperCase() + status.slice(1);
                
                if (confirm(`Are you sure you want to mark this order as ${statusText}?`)) {
                    $('#update_order_id').val(orderId);
                    $('#update_status').val(status);
                    $('#updateStatusForm').submit();
                }
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