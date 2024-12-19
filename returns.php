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
            case 'create_return':
                // Start transaction
                $pdo->beginTransaction();
                
                try {
                    // Insert return request
                    $stmt = $pdo->prepare(
                        "INSERT INTO return_requests (requester_id, request_id, reason, status, created_at) 
                         VALUES (?, ?, ?, 'pending', CURRENT_TIMESTAMP)"
                    );
                    
                    $stmt->execute([
                        $_SESSION['user_id'],
                        (int)$_POST['request_id'],
                        clean_input($_POST['reason'])
                    ]);
                    
                    $return_id = $pdo->lastInsertId();
                    
                    // Insert return items
                    $stmt = $pdo->prepare(
                        "INSERT INTO return_items (return_id, item_id, quantity, condition_notes) 
                         VALUES (?, ?, ?, ?)"
                    );
                    
                    foreach ($_POST['items'] as $item) {
                        $stmt->execute([
                            $return_id,
                            (int)$item['item_id'],
                            (int)$item['quantity'],
                            clean_input($item['condition_notes'])
                        ]);
                    }
                    
                    // Log the return request
                    log_audit($_SESSION['user_id'], 'create', 'return_requests', $return_id);
                    
                    $pdo->commit();
                    $_SESSION['success'] = 'Return request created successfully';
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                break;
                
            case 'update_status':
                if (!is_admin()) {
                    throw new Exception('Unauthorized action');
                }
                
                $stmt = $pdo->prepare(
                    "UPDATE return_requests 
                     SET status = ?, 
                         processor_id = ?, 
                         processed_at = CURRENT_TIMESTAMP,
                         processor_notes = ?
                     WHERE return_id = ?"
                );
                
                $stmt->execute([
                    clean_input($_POST['status']),
                    $_SESSION['user_id'],
                    clean_input($_POST['processor_notes']),
                    (int)$_POST['return_id']
                ]);
                
                // If return is approved, update inventory quantities
                if ($_POST['status'] === 'approved') {
                    $stmt = $pdo->prepare(
                        "SELECT ri.item_id, ri.quantity 
                         FROM return_items ri 
                         WHERE ri.return_id = ?"
                    );
                    $stmt->execute([(int)$_POST['return_id']]);
                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $stmt = $pdo->prepare(
                        "UPDATE inventory 
                         SET quantity = quantity + ? 
                         WHERE item_id = ?"
                    );
                    
                    foreach ($items as $item) {
                        $stmt->execute([
                            $item['quantity'],
                            $item['item_id']
                        ]);
                    }
                }
                
                log_audit($_SESSION['user_id'], 'update_status', 'return_requests', $_POST['return_id']);
                
                $_SESSION['success'] = 'Return request status updated successfully';
                break;
        }
        
        header('Location: returns.php');
        exit();
    }
    
    // Get user's supply requests for return
    if (is_admin()) {
        $stmt = $pdo->query(
            "SELECT sr.request_id, sr.created_at, u.full_name as requester_name, d.name as department_name 
             FROM supply_requests sr 
             JOIN users u ON sr.requester_id = u.user_id 
             JOIN departments d ON u.department_id = d.department_id 
             WHERE sr.status = 'approved' 
             ORDER BY sr.created_at DESC"
        );
    } else {
        $stmt = $pdo->prepare(
            "SELECT sr.request_id, sr.created_at, u.full_name as requester_name, d.name as department_name 
             FROM supply_requests sr 
             JOIN users u ON sr.requester_id = u.user_id 
             JOIN departments d ON u.department_id = d.department_id 
             WHERE sr.requester_id = ? AND sr.status = 'approved' 
             ORDER BY sr.created_at DESC"
        );
        $stmt->execute([$_SESSION['user_id']]);
    }
    $supply_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get return requests
    if (is_admin()) {
        $stmt = $pdo->query(
            "SELECT rr.*, u.full_name as requester_name, d.name as department_name,
                    p.full_name as processor_name, sr.created_at as request_date
             FROM return_requests rr 
             JOIN users u ON rr.requester_id = u.user_id 
             JOIN departments d ON u.department_id = d.department_id 
             LEFT JOIN users p ON rr.processor_id = p.user_id 
             JOIN supply_requests sr ON rr.request_id = sr.request_id 
             ORDER BY rr.created_at DESC"
        );
    } else {
        $stmt = $pdo->prepare(
            "SELECT rr.*, u.full_name as requester_name, d.name as department_name,
                    p.full_name as processor_name, sr.created_at as request_date
             FROM return_requests rr 
             JOIN users u ON rr.requester_id = u.user_id 
             JOIN departments d ON u.department_id = d.department_id 
             LEFT JOIN users p ON rr.processor_id = p.user_id 
             JOIN supply_requests sr ON rr.request_id = sr.request_id 
             WHERE rr.requester_id = ?
             ORDER BY rr.created_at DESC"
        );
        $stmt->execute([$_SESSION['user_id']]);
    }
    $return_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get inventory items
    $stmt = $pdo->query("SELECT * FROM inventory ORDER BY item_name");
    $inventory_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return and Exchange - <?php echo SITE_NAME; ?></title>
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
                        <a class="nav-link active" href="returns.php">
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
                    <h4>Return and Exchange</h4>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newReturnModal">
                        <i class="fas fa-plus me-2"></i>New Return Request
                    </button>
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

                <!-- Return Requests Table -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="returnsTable" class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Return ID</th>
                                        <th>Request Date</th>
                                        <th>Requester</th>
                                        <th>Department</th>
                                        <th>Reason</th>
                                        <th>Status</th>
                                        <th>Processor</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($return_requests as $return): ?>
                                    <tr>
                                        <td>#<?php echo $return['return_id']; ?></td>
                                        <td><?php echo date('Y-m-d', strtotime($return['created_at'])); ?></td>
                                        <td><?php echo sanitize_output($return['requester_name']); ?></td>
                                        <td><?php echo sanitize_output($return['department_name']); ?></td>
                                        <td><?php echo sanitize_output($return['reason']); ?></td>
                                        <td>
                                            <?php
                                            $status_class = 'secondary';
                                            switch ($return['status']) {
                                                case 'pending':
                                                    $status_class = 'warning';
                                                    break;
                                                case 'approved':
                                                    $status_class = 'success';
                                                    break;
                                                case 'rejected':
                                                    $status_class = 'danger';
                                                    break;
                                            }
                                            ?>
                                            <span class="badge bg-<?php echo $status_class; ?>">
                                                <?php echo ucfirst($return['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $return['processor_name'] ? sanitize_output($return['processor_name']) : '-'; ?>
                                        </td>
                                        <td>
                                            <?php if (is_admin() && $return['status'] === 'pending'): ?>
                                            <button type="button" class="btn btn-sm btn-success update-status" 
                                                    data-return-id="<?php echo $return['return_id']; ?>"
                                                    data-status="approved">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger update-status"
                                                    data-return-id="<?php echo $return['return_id']; ?>"
                                                    data-status="rejected">
                                                <i class="fas fa-times"></i>
                                            </button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-info view-details"
                                                    data-return-id="<?php echo $return['return_id']; ?>">
                                                <i class="fas fa-eye"></i>
                                            </button>
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

    <!-- New Return Request Modal -->
    <div class="modal fade" id="newReturnModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">New Return Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="create_return">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="request_id" class="form-label">Supply Request</label>
                            <select class="form-select" id="request_id" name="request_id" required>
                                <option value="">Select Supply Request</option>
                                <?php foreach ($supply_requests as $request): ?>
                                <option value="<?php echo $request['request_id']; ?>">
                                    #<?php echo $request['request_id']; ?> - 
                                    <?php echo date('Y-m-d', strtotime($request['created_at'])); ?> - 
                                    <?php echo sanitize_output($request['requester_name']); ?> - 
                                    <?php echo sanitize_output($request['department_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="reason" class="form-label">Reason for Return</label>
                            <textarea class="form-control" id="reason" name="reason" rows="3" required></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Items to Return</label>
                            <div id="item-container">
                                <div class="item-row mb-2">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <select class="form-select" name="items[0][item_id]" required>
                                                <option value="">Select Item</option>
                                                <?php foreach ($inventory_items as $item): ?>
                                                <option value="<?php echo $item['item_id']; ?>">
                                                    <?php echo sanitize_output($item['item_name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <input type="number" class="form-control" name="items[0][quantity]" 
                                                   placeholder="Quantity" required min="1">
                                        </div>
                                        <div class="col-md-5">
                                            <input type="text" class="form-control" name="items[0][condition_notes]" 
                                                   placeholder="Condition Notes" required>
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
                        <button type="submit" class="btn btn-primary">Submit Return Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div class="modal fade" id="updateStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Return Request Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="return_id" id="update_return_id">
                    <input type="hidden" name="status" id="update_status">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="processor_notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="processor_notes" name="processor_notes" rows="3" required></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Status</button>
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
            $('#returnsTable').DataTable({
                order: [[1, 'desc']],
                pageLength: 25,
                language: {
                    search: "Search returns:"
                }
            });
            
            let itemCount = 1;
            
            // Add item row
            $('#add-item').click(function() {
                const newRow = $('.item-row').first().clone();
                newRow.find('select').attr('name', `items[${itemCount}][item_id]`).val('');
                newRow.find('input[name$="[quantity]"]').attr('name', `items[${itemCount}][quantity]`).val('');
                newRow.find('input[name$="[condition_notes]"]').attr('name', `items[${itemCount}][condition_notes]`).val('');
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
                const returnId = $(this).data('return-id');
                const status = $(this).data('status');
                const statusText = status.charAt(0).toUpperCase() + status.slice(1);
                
                $('#update_return_id').val(returnId);
                $('#update_status').val(status);
                $('#updateStatusModal').modal('show');
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