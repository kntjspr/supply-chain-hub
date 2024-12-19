<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!$auth->isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => false,
        'message' => 'Authentication required'
    ));
    exit();
}

// Get request ID
$request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;

if (!$request_id) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => false,
        'message' => 'Invalid request ID'
    ));
    exit();
}

try {
    $db = Database::getInstance();
    
    // Get request details
    $stmt = $db->query(
        "SELECT sr.*, u.full_name, d.name as department_name
         FROM supply_requests sr
         JOIN users u ON sr.requester_id = u.user_id
         JOIN departments d ON sr.department_id = d.department_id
         WHERE sr.request_id = ?",
        array($request_id)
    );
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        throw new Exception('Request not found');
    }
    
    // Get request items
    $stmt = $db->query(
        "SELECT ri.*, i.item_name, i.unit, i.unit_price
         FROM request_items ri
         JOIN inventory i ON ri.item_id = i.item_id
         WHERE ri.request_id = ?",
        array($request_id)
    );
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check if user can approve requests
    $can_approve = $auth->hasPermission('supply_personnel');
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => true,
        'request' => $request,
        'items' => $items,
        'can_approve' => $can_approve
    ));
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => false,
        'message' => $e->getMessage()
    ));
} 