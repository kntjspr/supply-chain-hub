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
        
        // Check if action is allowed for the user's role
        if ($user['role'] === 'supply_personnel' && !in_array($action, ['update_status'])) {
            throw new Exception('You do not have permission to perform this action');
        }
        if ($user['role'] === 'department_head' && !in_array($action, ['create', 'cancel'])) {
            throw new Exception('You do not have permission to perform this action');
        }
        if ($user['role'] === 'auditor') {
            throw new Exception('You do not have permission to perform this action');
        }
        
        switch ($action) {
            case 'submit_request':
                $pdo->beginTransaction();
                
                try {
                    // Insert supply request
                    $stmt = $pdo->prepare(
                        "INSERT INTO supply_requests (requester_id, department_id, justification, status) 
                         VALUES (?, ?, ?, 'pending')"
                    );
                    
                    $stmt->execute([
                        $_SESSION['user_id'],
                        $user['department_id'],
                        clean_input($_POST['justification'])
                    ]);
                    
                    $request_id = $pdo->lastInsertId();
                    
                    // Insert request items
                    $stmt = $pdo->prepare(
                        "INSERT INTO request_items (request_id, item_id, quantity, status) 
                         VALUES (?, ?, ?, 'pending')"
                    );
                    
                    foreach ($_POST['items'] as $item) {
                        $stmt->execute([
                            $request_id,
                            (int)$item['item_id'],
                            (int)$item['quantity']
                        ]);
                    }
                    
                    // Log the request
                    log_audit($_SESSION['user_id'], 'submit', 'supply_requests', $request_id);
                    
                    $pdo->commit();
                    $_SESSION['success'] = 'Supply request submitted successfully';
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                break;
                
            case 'update_status':
                if (!is_admin()) {
                    throw new Exception('Unauthorized action');
                }
                
                $pdo->beginTransaction();
                
                try {
                    // Update request status
                    $stmt = $pdo->prepare(
                        "UPDATE supply_requests 
                         SET status = ?, approval_date = CURRENT_TIMESTAMP 
                         WHERE request_id = ?"
                    );
                    
                    $stmt->execute([
                        clean_input($_POST['status']),
                        (int)$_POST['request_id']
                    ]);
                    
                    // Update request items status
                    $stmt = $pdo->prepare(
                        "UPDATE request_items 
                         SET status = ? 
                         WHERE request_id = ?"
                    );
                    
                    $stmt->execute([
                        clean_input($_POST['status']),
                        (int)$_POST['request_id']
                    ]);
                    
                    // If approved, update inventory quantities
                    if ($_POST['status'] === 'approved') {
                        $stmt = $pdo->prepare(
                            "UPDATE inventory i 
                             JOIN request_items ri ON i.item_id = ri.item_id 
                             SET i.quantity = i.quantity - ri.quantity,
                                 i.status = CASE 
                                    WHEN i.quantity - ri.quantity <= 0 THEN 'out_of_stock'
                                    WHEN i.quantity - ri.quantity <= i.min_stock_level THEN 'low_stock'
                                    ELSE 'available'
                                 END
                             WHERE ri.request_id = ?"
                        );
                        
                        $stmt->execute([(int)$_POST['request_id']]);
                    }
                    
                    // Log the status update
                    log_audit($_SESSION['user_id'], 'update', 'supply_requests', $_POST['request_id']);
                    
                    $pdo->commit();
                    $_SESSION['success'] = 'Request status updated successfully';
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                break;
        }
        
        header('Location: requests.php');
        exit();
    }
    
    // Get requests based on role
    $sql = "SELECT r.*, u.full_name as requester_name, d.name as department_name 
            FROM supply_requests r 
            LEFT JOIN users u ON r.requester_id = u.user_id 
            LEFT JOIN departments d ON r.department_id = d.department_id";
    
    // Department heads can only see their department's requests
    if ($user['role'] === 'department_head') {
        $sql .= " WHERE r.department_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user['department_id']]);
    } 
    // Supply personnel and admin can see all requests
    else {
        $stmt = $pdo->query($sql);
    }
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supply Request Module - <?php echo SITE_NAME; ?></title>
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
                        <a class="nav-link" href="inventory.php">
                            <i class="fas fa-boxes me-2"></i> Inventory Management
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="requests.php">
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
                            <h4 class="card-title mb-0">Supply Request Module</h4>
                            <div>
                                <?php if ($user['role'] === 'department_head'): ?>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRequestModal">
                                        <i class="fas fa-plus me-2"></i>New Request
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

                        <!-- Requests Table -->
                        <div class="table-responsive">
                            <table id="requestsTable" class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Request ID</th>
                                        <th>Requester</th>
                                        <th>Department</th>
                                        <th>Items</th>
                                        <th>Status</th>
                                        <th>Request Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $query = "SELECT sr.*, u.full_name as requester_name, d.name as department_name,
                                            GROUP_CONCAT(CONCAT(i.item_name, ' (', ri.quantity, ' ', i.unit, ')') SEPARATOR ', ') as items
                                            FROM supply_requests sr
                                            JOIN users u ON sr.requester_id = u.user_id
                                            JOIN departments d ON sr.department_id = d.department_id
                                            JOIN request_items ri ON sr.request_id = ri.request_id
                                            JOIN inventory i ON ri.item_id = i.item_id
                                            GROUP BY sr.request_id
                                            ORDER BY sr.request_date DESC";
                                    
                                    $stmt = $pdo->query($query);
                                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)):
                                        $status_class = 'secondary';
                                        if ($row['status'] === 'approved') {
                                            $status_class = 'success';
                                        } elseif ($row['status'] === 'rejected') {
                                            $status_class = 'danger';
                                        } elseif ($row['status'] === 'pending') {
                                            $status_class = 'warning';
                                        }
                                    ?>
                                    <tr>
                                        <td><?php echo $row['request_id']; ?></td>
                                        <td><?php echo sanitize_output($row['requester_name']); ?></td>
                                        <td><?php echo sanitize_output($row['department_name']); ?></td>
                                        <td><?php echo sanitize_output($row['items']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $status_class; ?>">
                                                <?php echo ucfirst($row['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo format_date($row['request_date']); ?></td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-info view-request" 
                                                    data-request='<?php echo json_encode($row); ?>'
                                                    data-bs-toggle="modal" data-bs-target="#viewRequestModal">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($user['role'] === 'supply_personnel' || $user['role'] === 'admin'): ?>
                                                <?php if ($row['status'] === 'pending'): ?>
                                                    <button type="button" class="btn btn-sm btn-success approve-request"
                                                            data-request-id="<?php echo $row['request_id']; ?>"
                                                            data-bs-toggle="modal" data-bs-target="#approveRequestModal">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-danger reject-request"
                                                            data-request-id="<?php echo $row['request_id']; ?>"
                                                            data-bs-toggle="modal" data-bs-target="#rejectRequestModal">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                <?php endif; ?>
                                            <?php elseif ($user['role'] === 'department_head' && $row['status'] === 'pending'): ?>
                                                <button type="button" class="btn btn-sm btn-danger cancel-request"
                                                        data-request-id="<?php echo $row['request_id']; ?>"
                                                        data-bs-toggle="modal" data-bs-target="#cancelRequestModal">
                                                    <i class="fas fa-ban"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
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

    <!-- New Request Modal -->
    <div class="modal fade" id="newRequestModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">New Supply Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="submit_request">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="justification" class="form-label">Justification</label>
                            <textarea class="form-control" id="justification" name="justification" rows="3" required></textarea>
                        </div>
                        
                        <div id="itemsContainer">
                            <div class="row item-row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Item</label>
                                    <select class="form-select" name="items[0][item_id]" required>
                                        <option value="">Select Item</option>
                                        <?php
                                        $stmt = $pdo->query("SELECT * FROM inventory WHERE quantity > 0 ORDER BY item_name");
                                        while ($item = $stmt->fetch(PDO::FETCH_ASSOC)):
                                        ?>
                                        <option value="<?php echo $item['item_id']; ?>" 
                                                data-available="<?php echo $item['quantity']; ?>"
                                                data-unit="<?php echo $item['unit']; ?>">
                                            <?php echo sanitize_output($item['item_name']); ?>
                                            (<?php echo $item['quantity'] . ' ' . $item['unit']; ?> available)
                                        </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Quantity</label>
                                    <input type="number" class="form-control" name="items[0][quantity]" min="1" required>
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="button" class="btn btn-danger remove-item" style="display: none;">
                                        <i class="fas fa-minus"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <button type="button" class="btn btn-secondary" id="addItem">
                            <i class="fas fa-plus me-2"></i>Add Item
                        </button>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Submit Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Request Modal -->
    <div class="modal fade" id="viewRequestModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Request Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <dl class="row">
                        <dt class="col-sm-4">Request ID</dt>
                        <dd class="col-sm-8" id="view_request_id"></dd>
                        
                        <dt class="col-sm-4">Requester</dt>
                        <dd class="col-sm-8" id="view_requester"></dd>
                        
                        <dt class="col-sm-4">Department</dt>
                        <dd class="col-sm-8" id="view_department"></dd>
                        
                        <dt class="col-sm-4">Items</dt>
                        <dd class="col-sm-8" id="view_items"></dd>
                        
                        <dt class="col-sm-4">Status</dt>
                        <dd class="col-sm-8" id="view_status"></dd>
                        
                        <dt class="col-sm-4">Request Date</dt>
                        <dd class="col-sm-8" id="view_request_date"></dd>
                        
                        <dt class="col-sm-4">Justification</dt>
                        <dd class="col-sm-8" id="view_justification"></dd>
                    </dl>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Approve Request Modal -->
    <div class="modal fade" id="approveRequestModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Approve Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="status" value="approved">
                    <input type="hidden" name="request_id" id="approve_request_id">
                    
                    <div class="modal-body">
                        <p>Are you sure you want to approve this request?</p>
                        <p class="text-info mb-0">This will update inventory quantities.</p>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Approve Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reject Request Modal -->
    <div class="modal fade" id="rejectRequestModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reject Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="status" value="rejected">
                    <input type="hidden" name="request_id" id="reject_request_id">
                    
                    <div class="modal-body">
                        <p>Are you sure you want to reject this request?</p>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject Request</button>
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
            $('#requestsTable').DataTable({
                order: [[5, 'desc']],
                pageLength: 25,
                language: {
                    search: "Search requests:"
                }
            });
            
            // Handle view request
            $('.view-request').click(function() {
                var request = $(this).data('request');
                $('#view_request_id').text(request.request_id);
                $('#view_requester').text(request.requester_name);
                $('#view_department').text(request.department_name);
                $('#view_items').text(request.items);
                $('#view_status').html('<span class="badge bg-' + getStatusClass(request.status) + '">' + 
                                     request.status.charAt(0).toUpperCase() + request.status.slice(1) + '</span>');
                $('#view_request_date').text(request.request_date);
                $('#view_justification').text(request.justification);
            });
            
            // Handle approve request
            $('.approve-request').click(function() {
                var requestId = $(this).data('request-id');
                $('#approve_request_id').val(requestId);
            });
            
            // Handle reject request
            $('.reject-request').click(function() {
                var requestId = $(this).data('request-id');
                $('#reject_request_id').val(requestId);
            });
            
            // Handle add item
            let itemCount = 0;
            $('#addItem').click(function() {
                itemCount++;
                let template = $('.item-row').first().clone();
                template.find('select').attr('name', 'items[' + itemCount + '][item_id]').val('');
                template.find('input').attr('name', 'items[' + itemCount + '][quantity]').val('');
                template.find('.remove-item').show();
                $('#itemsContainer').append(template);
            });
            
            // Handle remove item
            $(document).on('click', '.remove-item', function() {
                $(this).closest('.item-row').remove();
            });
            
            // Handle quantity validation
            $(document).on('change', 'select[name^="items"]', function() {
                let available = $(this).find(':selected').data('available');
                let quantityInput = $(this).closest('.item-row').find('input[name$="[quantity]"]');
                quantityInput.attr('max', available);
            });
        });
        
        function getStatusClass(status) {
            switch (status) {
                case 'approved': return 'success';
                case 'rejected': return 'danger';
                case 'pending': return 'warning';
                default: return 'secondary';
            }
        }
        
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