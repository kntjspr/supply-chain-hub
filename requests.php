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
            redirect_with_error('requests.php', 'Invalid CSRF token.');
            exit;
        }

        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'submit_request':
                try {
                    // Start transaction
                $pdo->beginTransaction();
                
                    // Insert the supply request
                    $stmt = $pdo->prepare("
                        INSERT INTO supply_requests (requester_id, department_id, justification, status, created_at) 
                        VALUES (?, ?, ?, 'pending', NOW())
                    ");
                    $stmt->execute([$user['user_id'], $user['department_id'], $_POST['justification']]);
                    $request_id = $pdo->lastInsertId();
                    
                    // Insert request items
                    $stmt = $pdo->prepare("
                        INSERT INTO request_items (request_id, item_id, quantity) 
                        VALUES (?, ?, ?)
                    ");
                    
                    foreach ($_POST['items'] as $item) {
                        $stmt->execute([$request_id, $item['item_id'], $item['quantity']]);
                    }
                    
                    // Log the action
                    log_audit(
                        $user['user_id'],
                        'create',
                        'supply_requests',
                        $request_id,
                        null,
                        json_encode(['request_id' => $request_id, 'status' => 'pending'])
                    );
                    
                    $pdo->commit();
                    redirect_with_success('requests.php', 'Supply request submitted successfully.');
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    redirect_with_error('requests.php', 'Error creating supply request: ' . $e->getMessage());
                }
                break;
                
            case 'update_status':
                try {
                    error_log("Starting status update process...");
                    error_log("Request ID: " . $_POST['request_id']);
                    error_log("New Status: " . $_POST['status']);
                    
                    $pdo->beginTransaction();
                    
                    $request_id = $_POST['request_id'];
                    $new_status = $_POST['status'];
                    
                    // Get old values for audit
                    $stmt = $pdo->prepare("SELECT * FROM supply_requests WHERE request_id = ?");
                    $stmt->execute([$request_id]);
                    $old_request = $stmt->fetch();
                    error_log("Old request data: " . json_encode($old_request));
                    
                    // Update the request status
                    $stmt = $pdo->prepare("
                        UPDATE supply_requests 
                        SET status = ?, 
                            approval_date = CASE WHEN ? = 'approved' THEN NOW() ELSE NULL END,
                            updated_at = NOW() 
                        WHERE request_id = ?
                    ");
                    $result = $stmt->execute([$new_status, $new_status, $request_id]);
                    error_log("Status update result: " . ($result ? 'success' : 'failed'));
                    
                    // If request is approved, update inventory quantities
                    if ($new_status === 'approved') {
                        error_log("Processing approved request...");
                        // Get all items in the request
                        $stmt = $pdo->prepare("
                            SELECT item_id, quantity 
                            FROM request_items 
                            WHERE request_id = ?
                        ");
                        $stmt->execute([$request_id]);
                        $request_items = $stmt->fetchAll();
                        error_log("Request items: " . json_encode($request_items));
                        
                        // Update inventory quantities
                        $update_stmt = $pdo->prepare("
                            UPDATE inventory 
                            SET quantity = quantity - ? 
                            WHERE item_id = ?
                        ");
                        
                        foreach ($request_items as $item) {
                            $result = $update_stmt->execute([$item['quantity'], $item['item_id']]);
                            error_log("Inventory update for item {$item['item_id']}: " . ($result ? 'success' : 'failed'));
                        }
                    }
                    
                    // Log the action
                    log_audit(
                        $user['user_id'],
                        'update',
                        'supply_requests',
                        $request_id,
                        json_encode($old_request),
                        json_encode(['status' => $new_status])
                    );
                    
                    $pdo->commit();
                    error_log("Transaction committed successfully");
                    $_SESSION['success'] = 'Request status updated successfully.';
                    header('Location: requests.php');
                    exit;
                    
                } catch (Exception $e) {
                    error_log("Error in status update: " . $e->getMessage());
                    $pdo->rollBack();
                    $error = 'Error updating request status: ' . $e->getMessage();
                }
                break;
        }
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
    
    // Fetch inventory items for the request form
    $inventory_stmt = $pdo->query("
        SELECT item_id, item_name, quantity, unit 
        FROM inventory 
        WHERE quantity > 0 
        ORDER BY item_name ASC
    ");
    $inventory_items = $inventory_stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
                    <?php if ($user['role'] !== 'auditor'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="inventory.php">
                            <i class="fas fa-boxes me-2"></i> Inventory Management
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if ($user['role'] !== 'auditor'): ?>
                    <li class="nav-item">
                        <a class="nav-link active" href="requests.php">
                            <i class="fas fa-file-alt me-2"></i> Supply Request Module
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if ($user['role'] === 'admin' || $user['role'] === 'department_head' || $user['role'] === 'auditor'): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $user['role'] === 'supply_personnel' ? 'disabled' : ''; ?>" 
                           href="<?php echo $user['role'] === 'supply_personnel' ? '#' : 'procurement.php'; ?>"
                           <?php echo $user['role'] === 'supply_personnel' ? 'style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>
                            <i class="fas fa-shopping-cart me-2"></i> Procurement & Distribution
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link" href="audit.php">
                            <i class="fas fa-history me-2"></i> Audit & Reporting
                        </a>
                    </li>
                    <?php if ($user['role'] === 'admin' || $user['role'] === 'supply_personnel'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="returns.php">
                            <i class="fas fa-undo me-2"></i> Return and Exchange
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link" href="alerts.php">
                            <i class="fas fa-bell me-2"></i> Alert & Notifications
                        </a>
                    </li>
                    <?php if ($user['role'] === 'admin'): ?>
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
                    <h4>Supply Requests</h4>
                    <?php if ($user['role'] !== 'auditor'): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newRequestModal">
                        <i class="fas fa-plus me-2"></i>New Supply Request
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

    <!-- New Supply Request Modal -->
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
                            <div class="invalid-feedback">Please provide a justification for the request.</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Items</label>
                            <div id="itemsContainer">
                                <div class="item-row mb-2">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <select class="form-select" name="items[0][item_id]" required>
                                                <option value="">Select Item</option>
                                                <?php foreach ($inventory_items as $item): ?>
                                                <option value="<?php echo $item['item_id']; ?>" 
                                                        data-available="<?php echo $item['quantity']; ?>">
                                                    <?php echo sanitize_output($item['item_name']); ?> 
                                                    (<?php echo $item['quantity']; ?> <?php echo $item['unit']; ?> available)
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-5">
                                            <input type="number" class="form-control" name="items[0][quantity]" 
                                                   placeholder="Quantity" required min="1">
                                            <div class="invalid-feedback">Please enter a valid quantity.</div>
                                        </div>
                                        <div class="col-md-1">
                                            <button type="button" class="btn btn-danger remove-item" style="display: none;">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary mt-2" id="addItem">
                                <i class="fas fa-plus me-2"></i>Add Item
                            </button>
                        </div>
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
            // Debug inventory items
            console.log('Inventory items:', <?php echo json_encode($inventory_items); ?>);
            
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
                console.log('Approving request:', requestId);
                $('#approve_request_id').val(requestId);
            });
            
            // Handle form submission for approval
            $('#approveRequestModal form').submit(function(e) {
                console.log('Form data:', {
                    request_id: $('#approve_request_id').val(),
                    status: 'approved',
                    action: 'update_status'
                });
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
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('#newRequestModal form');
        const itemsContainer = document.querySelector('#itemsContainer');
        const addItemBtn = document.querySelector('#addItem');
        let itemCount = 1;

        // Add new item row
        addItemBtn.addEventListener('click', function() {
            const template = itemsContainer.querySelector('.item-row').cloneNode(true);
            template.querySelectorAll('select, input').forEach(input => {
                input.name = input.name.replace('[0]', `[${itemCount}]`);
                input.value = '';
            });
            
            template.querySelector('.remove-item').style.display = 'block';
            itemsContainer.appendChild(template);
            itemCount++;
        });

        // Remove item row
        itemsContainer.addEventListener('click', function(e) {
            if (e.target.closest('.remove-item')) {
                e.target.closest('.item-row').remove();
            }
        });

        // Validate quantities against available stock
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            let isValid = true;
            const selectedItems = new Set();

            form.querySelectorAll('.item-row').forEach(row => {
                const select = row.querySelector('select');
                const quantity = row.querySelector('input[type="number"]');
                const available = select.selectedOptions[0]?.dataset.available;

                // Check for duplicate items
                if (selectedItems.has(select.value)) {
                    select.setCustomValidity('This item has already been selected');
                    isValid = false;
                } else if (select.value) {
                    selectedItems.add(select.value);
                    select.setCustomValidity('');
                }

                // Check quantity against available stock
                if (quantity.value && available && parseInt(quantity.value) > parseInt(available)) {
                    quantity.setCustomValidity(`Maximum available quantity is ${available}`);
                    isValid = false;
                } else {
                    quantity.setCustomValidity('');
                }
            });

            if (!form.checkValidity() || !isValid) {
                e.stopPropagation();
                form.classList.add('was-validated');
            } else {
                form.submit();
            }
        });

        // Reset form when modal is closed
        const modal = document.querySelector('#newRequestModal');
        modal.addEventListener('hidden.bs.modal', function() {
            form.reset();
            form.classList.remove('was-validated');
            const rows = itemsContainer.querySelectorAll('.item-row');
            for (let i = 1; i < rows.length; i++) {
                rows[i].remove();
            }
        });
    });
    </script>
</body>
</html>
?> 