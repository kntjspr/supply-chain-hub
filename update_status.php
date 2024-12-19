<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/db.php';

// Check if user is logged in
if (!$auth->isLoggedIn()) {
    die(json_encode(['success' => false, 'message' => 'Not authorized']));
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    die(json_encode(['success' => false, 'message' => 'Invalid token']));
}

// Get parameters
$item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
$status = isset($_POST['status']) ? clean_input($_POST['status']) : '';

if (!$item_id || !$status) {
    die(json_encode(['success' => false, 'message' => 'Missing parameters']));
}

$db = Database::getInstance();

// Get old values for audit
$stmt = $db->query("SELECT * FROM inventory WHERE item_id = ?", array($item_id));
$old_values = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$old_values) {
    die(json_encode(['success' => false, 'message' => 'Item not found']));
}

// Update status
$data = array('status' => $status);
try {
    $db->update('inventory', $data, "item_id = " . $item_id);
    
    // Log the change
    log_audit(
        $_SESSION['user_id'],
        'updated status',
        'inventory',
        $item_id,
        array('status' => $old_values['status']),
        array('status' => $status)
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Status updated successfully',
        'new_status' => $status
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error updating status: ' . $e->getMessage()
    ]);
} 