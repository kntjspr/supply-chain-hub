<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!$auth->isLoggedIn()) {
    header('Location: login.php');
    exit();
}

// Get export type
$type = isset($_GET['type']) ? clean_input($_GET['type']) : '';

// Validate export type
if (!in_array($type, array('inventory', 'suppliers', 'requests'))) {
    set_flash_message('error', 'Invalid export type');
    header('Location: inventory.php');
    exit();
}

try {
    $db = Database::getInstance();
    
    // Set filename and headers
    $filename = $type . '_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Create output handle
    $output = fopen('php://output', 'w');
    if (!$output) {
        throw new Exception('Error creating output file');
    }
    
    switch ($type) {
        case 'inventory':
            // Write headers
            fputcsv($output, array(
                'Item Name',
                'Quantity',
                'Unit',
                'Unit Price',
                'Min Stock Level',
                'Status',
                'Last Updated'
            ));
            
            // Get inventory items
            $stmt = $db->query(
                "SELECT * FROM inventory ORDER BY item_name"
            );
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, array(
                    $row['item_name'],
                    $row['quantity'],
                    $row['unit'],
                    $row['unit_price'],
                    $row['min_stock_level'],
                    $row['status'],
                    $row['updated_at']
                ));
            }
            break;
            
        case 'suppliers':
            // Write headers
            fputcsv($output, array(
                'Name',
                'Contact Person',
                'Email',
                'Phone',
                'Address',
                'Status',
                'Last Updated'
            ));
            
            // Get suppliers
            $stmt = $db->query(
                "SELECT * FROM suppliers ORDER BY name"
            );
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, array(
                    $row['name'],
                    $row['contact_person'],
                    $row['email'],
                    $row['phone'],
                    $row['address'],
                    $row['status'],
                    $row['updated_at']
                ));
            }
            break;
            
        case 'requests':
            // Write headers
            fputcsv($output, array(
                'Request ID',
                'Requester',
                'Department',
                'Status',
                'Created At',
                'Approval Date',
                'Item Name',
                'Quantity',
                'Unit',
                'Unit Price',
                'Total'
            ));
            
            // Get requests with items
            $stmt = $db->query(
                "SELECT sr.request_id, u.full_name, d.name as department_name,
                        sr.status, sr.created_at, sr.approval_date,
                        i.item_name, ri.quantity, i.unit, i.unit_price
                 FROM supply_requests sr
                 JOIN users u ON sr.requester_id = u.user_id
                 JOIN departments d ON sr.department_id = d.department_id
                 JOIN request_items ri ON sr.request_id = ri.request_id
                 JOIN inventory i ON ri.item_id = i.item_id
                 ORDER BY sr.created_at DESC"
            );
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, array(
                    $row['request_id'],
                    $row['full_name'],
                    $row['department_name'],
                    $row['status'],
                    $row['created_at'],
                    $row['approval_date'],
                    $row['item_name'],
                    $row['quantity'],
                    $row['unit'],
                    $row['unit_price'],
                    $row['quantity'] * $row['unit_price']
                ));
            }
            break;
    }
    
    fclose($output);
    
    // Log export
    log_audit($_SESSION['user_id'], 'exported', $type);
    exit();
} catch (Exception $e) {
    set_flash_message('error', 'Error exporting data: ' . $e->getMessage());
    header('Location: inventory.php');
    exit();
} 