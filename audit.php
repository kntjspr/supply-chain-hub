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
    
    // Get audit logs with user and table details
    $stmt = $pdo->query(
        "SELECT al.*, u.full_name as user_name 
         FROM audit_logs al 
         JOIN users u ON al.user_id = u.user_id 
         ORDER BY al.timestamp DESC"
    );
    $audit_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get inventory movement report
    $stmt = $pdo->query(
        "SELECT i.item_name, i.quantity as current_quantity, 
                i.min_stock_level,
                (SELECT COUNT(*) FROM supply_requests sr 
                 JOIN request_items ri ON sr.request_id = ri.request_id 
                 WHERE ri.item_id = i.item_id AND sr.status = 'approved') as total_requests,
                (SELECT SUM(ri.quantity) FROM request_items ri 
                 JOIN supply_requests sr ON ri.request_id = sr.request_id 
                 WHERE ri.item_id = i.item_id AND sr.status = 'approved') as total_requested
         FROM inventory i
         ORDER BY i.item_name"
    );
    $inventory_report = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get department usage report
    $stmt = $pdo->query(
        "SELECT d.name as department_name, 
                COUNT(DISTINCT sr.request_id) as total_requests,
                SUM(ri.quantity) as total_items_requested,
                COUNT(DISTINCT CASE WHEN sr.status = 'approved' THEN sr.request_id END) as approved_requests,
                SUM(CASE WHEN sr.status = 'approved' THEN ri.quantity ELSE 0 END) as approved_items
         FROM departments d
         LEFT JOIN users u ON d.department_id = u.department_id
         LEFT JOIN supply_requests sr ON u.user_id = sr.requester_id
         LEFT JOIN request_items ri ON sr.request_id = ri.request_id
         GROUP BY d.department_id, d.name
         ORDER BY d.name"
    );
    $department_report = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit & Reporting - <?php echo SITE_NAME; ?></title>
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
        .nav-tabs {
            border-bottom-color: rgba(255, 255, 255, 0.2);
        }
        .nav-tabs .nav-link {
            color: #ffffff;
            border: none;
            padding: 0.5rem 1rem;
            margin-right: 0.5rem;
            border-radius: 0.5rem 0.5rem 0 0;
        }
        .nav-tabs .nav-link:hover {
            border-color: transparent;
            background-color: rgba(255, 255, 255, 0.1);
        }
        .nav-tabs .nav-link.active {
            color: #ffffff;
            background-color: rgba(255, 255, 255, 0.2);
            border-color: transparent;
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
                        <a class="nav-link active" href="audit.php">
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
                    <h4>Audit & Reporting</h4>
                    <div class="btn-group">
                        <button type="button" class="btn btn-primary" onclick="exportReport('audit')">
                            <i class="fas fa-download me-2"></i>Export Audit Log
                        </button>
                        <button type="button" class="btn btn-primary" onclick="exportReport('inventory')">
                            <i class="fas fa-download me-2"></i>Export Inventory Report
                        </button>
                        <button type="button" class="btn btn-primary" onclick="exportReport('department')">
                            <i class="fas fa-download me-2"></i>Export Department Report
                        </button>
                    </div>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Tabs -->
                <ul class="nav nav-tabs mb-4" role="tablist">
                    <?php if ($user['role'] !== 'supply_personnel'): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="audit-tab" data-bs-toggle="tab" data-bs-target="#audit" type="button" role="tab">
                            Audit Logs
                        </button>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?php echo $user['role'] === 'supply_personnel' ? 'active' : ''; ?>" id="inventory-tab" data-bs-toggle="tab" data-bs-target="#inventory" type="button" role="tab">
                            Inventory Movement
                        </button>
                    </li>
                    <?php if ($user['role'] !== 'supply_personnel'): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="department-tab" data-bs-toggle="tab" data-bs-target="#department" type="button" role="tab">
                            Department Usage
                        </button>
                    </li>
                    <?php endif; ?>
                </ul>

                <!-- Tab Content -->
                <div class="tab-content">
                    <?php if ($user['role'] !== 'supply_personnel'): ?>
                    <div class="tab-pane fade show active" id="audit" role="tabpanel">
                        <div class="card">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table id="auditTable" class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Timestamp</th>
                                                <th>User</th>
                                                <th>Action</th>
                                                <th>Table</th>
                                                <th>Record ID</th>
                                                <th>Old Values</th>
                                                <th>New Values</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($audit_logs as $log): ?>
                                            <tr>
                                                <td><?php echo date('Y-m-d H:i:s', strtotime($log['timestamp'])); ?></td>
                                                <td><?php echo sanitize_output($log['user_name']); ?></td>
                                                <td><?php echo sanitize_output($log['action']); ?></td>
                                                <td><?php echo sanitize_output($log['table_name']); ?></td>
                                                <td><?php echo sanitize_output($log['record_id']); ?></td>
                                                <td>
                                                    <?php 
                                                    if ($log['old_values']) {
                                                        $old_values = json_decode($log['old_values'], true);
                                                        foreach ($old_values as $key => $value) {
                                                            echo sanitize_output("$key: $value") . "<br>";
                                                        }
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    if ($log['new_values']) {
                                                        $new_values = json_decode($log['new_values'], true);
                                                        foreach ($new_values as $key => $value) {
                                                            echo sanitize_output("$key: $value") . "<br>";
                                                        }
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="tab-pane fade <?php echo $user['role'] === 'supply_personnel' ? 'show active' : ''; ?>" id="inventory" role="tabpanel">
                        <div class="card">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table id="inventoryTable" class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Item Name</th>
                                                <th>Current Quantity</th>
                                                <th>Min Stock Level</th>
                                                <th>Total Requests</th>
                                                <th>Total Requested</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($inventory_report as $item): ?>
                                            <tr>
                                                <td><?php echo sanitize_output($item['item_name']); ?></td>
                                                <td><?php echo $item['current_quantity']; ?></td>
                                                <td><?php echo $item['min_stock_level']; ?></td>
                                                <td><?php echo $item['total_requests']; ?></td>
                                                <td><?php echo $item['total_requested']; ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($user['role'] !== 'supply_personnel'): ?>
                    <div class="tab-pane fade" id="department" role="tabpanel">
                        <div class="card">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table id="departmentTable" class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Department</th>
                                                <th>Total Requests</th>
                                                <th>Total Items Requested</th>
                                                <th>Approved Requests</th>
                                                <th>Approved Items</th>
                                                <th>Approval Rate</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($department_report as $dept): ?>
                                            <tr>
                                                <td><?php echo sanitize_output($dept['department_name']); ?></td>
                                                <td><?php echo $dept['total_requests']; ?></td>
                                                <td><?php echo $dept['total_items_requested']; ?></td>
                                                <td><?php echo $dept['approved_requests']; ?></td>
                                                <td><?php echo $dept['approved_items']; ?></td>
                                                <td>
                                                    <?php 
                                                    if ($dept['total_requests'] > 0) {
                                                        echo round(($dept['approved_requests'] / $dept['total_requests']) * 100, 1) . '%';
                                                    } else {
                                                        echo '0%';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize DataTables
            $('#auditTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                language: {
                    search: "Search audit log:"
                }
            });
            
            $('#inventoryTable').DataTable({
                order: [[0, 'asc']],
                pageLength: 25,
                language: {
                    search: "Search inventory:"
                }
            });
            
            $('#departmentTable').DataTable({
                order: [[5, 'desc']],
                pageLength: 25,
                language: {
                    search: "Search departments:"
                }
            });
        });
        
        function exportReport(type) {
            let table;
            let filename;
            
            switch (type) {
                case 'audit':
                    table = document.querySelector('#auditTable');
                    filename = 'audit_log';
                    break;
                case 'inventory':
                    table = document.querySelector('#inventoryTable');
                    filename = 'inventory_movement';
                    break;
                case 'department':
                    table = document.querySelector('#departmentTable');
                    filename = 'department_usage';
                    break;
            }
            
            if (!table) return;
            
            const rows = [];
            const headers = [];
            
            // Get headers
            table.querySelectorAll('thead th').forEach(th => headers.push(th.textContent.trim()));
            rows.push(headers);
            
            // Get data
            table.querySelectorAll('tbody tr').forEach(tr => {
                const row = [];
                tr.querySelectorAll('td').forEach(td => row.push(td.textContent.trim()));
                rows.push(row);
            });
            
            // Convert to CSV
            const csvContent = rows.map(row => row.join(',')).join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            
            // Download file
            const link = document.createElement('a');
            if (link.download !== undefined) {
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', `${filename}_${new Date().toISOString().split('T')[0]}.csv`);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($user['role'] === 'supply_personnel'): ?>
            // Force inventory tab for supply personnel
            document.querySelector('#inventory-tab').click();
            <?php endif; ?>
        });
    </script>
</body>
</html> 