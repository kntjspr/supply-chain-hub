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

$pageTitle = "Import Data";

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token']);
    
    try {
        $db = Database::getInstance();
        $db->beginTransaction();
        
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Please select a valid CSV file');
        }
        
        // Check file type
        $mimeType = mime_content_type($_FILES['file']['tmp_name']);
        if (!in_array($mimeType, array('text/csv', 'text/plain', 'application/vnd.ms-excel'))) {
            throw new Exception('Invalid file type. Please upload a CSV file');
        }
        
        // Read CSV file
        $handle = fopen($_FILES['file']['tmp_name'], 'r');
        if (!$handle) {
            throw new Exception('Error reading file');
        }
        
        // Get headers
        $headers = fgetcsv($handle);
        if (!$headers) {
            throw new Exception('Invalid CSV format');
        }
        
        // Validate headers based on import type
        $type = clean_input($_POST['type']);
        $required_headers = array();
        
        switch ($type) {
            case 'inventory':
                $required_headers = array('item_name', 'quantity', 'unit', 'unit_price', 'min_stock_level');
                break;
                
            case 'suppliers':
                $required_headers = array('name', 'contact_person', 'email', 'phone', 'address');
                break;
                
            default:
                throw new Exception('Invalid import type');
        }
        
        // Check if all required headers are present
        $missing_headers = array_diff($required_headers, array_map('strtolower', $headers));
        if (!empty($missing_headers)) {
            throw new Exception('Missing required columns: ' . implode(', ', $missing_headers));
        }
        
        // Map headers to column indices
        $header_map = array_flip(array_map('strtolower', $headers));
        
        // Process rows
        $row_number = 1;
        $imported = 0;
        $errors = array();
        
        while (($row = fgetcsv($handle)) !== false) {
            $row_number++;
            
            try {
                switch ($type) {
                    case 'inventory':
                        // Validate and clean data
                        $item_name = clean_input($row[$header_map['item_name']]);
                        $quantity = (int)$row[$header_map['quantity']];
                        $unit = clean_input($row[$header_map['unit']]);
                        $unit_price = (float)$row[$header_map['unit_price']];
                        $min_stock_level = (int)$row[$header_map['min_stock_level']];
                        
                        if (!$item_name) {
                            throw new Exception('Item name is required');
                        }
                        
                        if ($quantity < 0) {
                            throw new Exception('Quantity must be non-negative');
                        }
                        
                        if (!$unit) {
                            throw new Exception('Unit is required');
                        }
                        
                        if ($unit_price < 0) {
                            throw new Exception('Unit price must be non-negative');
                        }
                        
                        if ($min_stock_level < 0) {
                            throw new Exception('Minimum stock level must be non-negative');
                        }
                        
                        // Check if item exists
                        $stmt = $db->query(
                            "SELECT item_id FROM inventory WHERE item_name = ?",
                            array($item_name)
                        );
                        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($existing) {
                            // Update existing item
                            $data = array(
                                'quantity' => $quantity,
                                'unit' => $unit,
                                'unit_price' => $unit_price,
                                'min_stock_level' => $min_stock_level
                            );
                            
                            $db->update('inventory', $data, "item_id = " . $existing['item_id']);
                            updateItemStatus($existing['item_id']);
                        } else {
                            // Insert new item
                            $data = array(
                                'item_name' => $item_name,
                                'quantity' => $quantity,
                                'unit' => $unit,
                                'unit_price' => $unit_price,
                                'min_stock_level' => $min_stock_level,
                                'status' => 'available'
                            );
                            
                            $item_id = $db->insert('inventory', $data);
                            updateItemStatus($item_id);
                        }
                        break;
                        
                    case 'suppliers':
                        // Validate and clean data
                        $name = clean_input($row[$header_map['name']]);
                        $contact_person = clean_input($row[$header_map['contact_person']]);
                        $email = clean_input($row[$header_map['email']]);
                        $phone = clean_input($row[$header_map['phone']]);
                        $address = clean_input($row[$header_map['address']]);
                        
                        if (!$name) {
                            throw new Exception('Supplier name is required');
                        }
                        
                        if (!$email) {
                            throw new Exception('Email is required');
                        }
                        
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            throw new Exception('Invalid email format');
                        }
                        
                        // Check if supplier exists
                        $stmt = $db->query(
                            "SELECT supplier_id FROM suppliers WHERE name = ? OR email = ?",
                            array($name, $email)
                        );
                        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($existing) {
                            // Update existing supplier
                            $data = array(
                                'contact_person' => $contact_person,
                                'email' => $email,
                                'phone' => $phone,
                                'address' => $address
                            );
                            
                            $db->update('suppliers', $data, "supplier_id = " . $existing['supplier_id']);
                        } else {
                            // Insert new supplier
                            $data = array(
                                'name' => $name,
                                'contact_person' => $contact_person,
                                'email' => $email,
                                'phone' => $phone,
                                'address' => $address,
                                'status' => 'active'
                            );
                            
                            $db->insert('suppliers', $data);
                        }
                        break;
                }
                
                $imported++;
            } catch (Exception $e) {
                $errors[] = "Row {$row_number}: " . $e->getMessage();
            }
        }
        
        fclose($handle);
        
        if (!empty($errors)) {
            throw new Exception(
                "Import completed with errors:\n" . implode("\n", $errors) . 
                "\n\nSuccessfully imported {$imported} records."
            );
        }
        
        $db->commit();
        log_audit($_SESSION['user_id'], 'imported', $type, null, null, array('count' => $imported));
        set_flash_message('success', "Successfully imported {$imported} records");
        
        header('Location: ' . ($type === 'inventory' ? 'inventory.php' : 'procurement.php'));
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        set_flash_message('error', $e->getMessage());
    }
}

// Start output buffering
ob_start();
?>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0">Import Data</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" enctype="multipart/form-data">
                    <?php $token = generate_csrf_token(); ?>
                    <input type="hidden" name="csrf_token" value="<?php echo $token; ?>">
                    
                    <div class="mb-3">
                        <label for="type" class="form-label">Import Type</label>
                        <select class="form-select" id="type" name="type" required>
                            <option value="">Select Type</option>
                            <option value="inventory">Inventory Items</option>
                            <option value="suppliers">Suppliers</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="file" class="form-label">CSV File</label>
                        <input type="file" class="form-control" id="file" name="file" accept=".csv" required>
                    </div>
                    
                    <div class="mb-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-upload"></i> Import Data
                        </button>
                        <a href="inventory.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0">Import Instructions</h5>
            </div>
            <div class="card-body">
                <h6>Inventory Items</h6>
                <p>Required columns:</p>
                <ul>
                    <li>item_name</li>
                    <li>quantity</li>
                    <li>unit</li>
                    <li>unit_price</li>
                    <li>min_stock_level</li>
                </ul>
                
                <h6 class="mt-4">Suppliers</h6>
                <p>Required columns:</p>
                <ul>
                    <li>name</li>
                    <li>contact_person</li>
                    <li>email</li>
                    <li>phone</li>
                    <li>address</li>
                </ul>
                
                <div class="alert alert-info mt-4">
                    <i class="fas fa-info-circle"></i> 
                    Download template files:
                    <ul class="mb-0">
                        <li><a href="templates/inventory_template.csv">Inventory Template</a></li>
                        <li><a href="templates/suppliers_template.csv">Suppliers Template</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once 'includes/layout.php';
?> 