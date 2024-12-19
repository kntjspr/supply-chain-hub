<?php
/**
 * Common utility functions for the application
 */

/**
 * Generates a CSRF token and stores it in the session
 * @return string The generated CSRF token
 */
function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validates a CSRF token against the one stored in session
 * @param string $token The token to validate
 * @return bool Whether the token is valid
 */
function validate_csrf_token($token) {
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    return true;
}

/**
 * Sanitizes output to prevent XSS
 * @param string $data The data to sanitize
 * @return string The sanitized data
 */
function sanitize_output($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

/**
 * Clean input data
 * @param string $data The input to clean
 * @return string The cleaned input
 */
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * Redirects to a URL with an error message
 * @param string $url The URL to redirect to
 * @param string $message The error message
 */
function redirect_with_error($url, $message) {
    $_SESSION['error'] = $message;
    header("Location: $url");
    exit();
}

/**
 * Redirects to a URL with a success message
 * @param string $url The URL to redirect to
 * @param string $message The success message
 */
function redirect_with_success($url, $message) {
    $_SESSION['success'] = $message;
    header("Location: $url");
    exit();
}

/**
 * Checks if user is logged in
 * @return bool Whether the user is logged in
 */
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

/**
 * Checks if user has admin role
 * @return bool Whether the user is an admin
 */
function is_admin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Gets flash messages and clears them from session
 * @param string $type The type of message (error or success)
 * @return string|null The message if it exists
 */
function get_flash_message($type) {
    if (isset($_SESSION[$type])) {
        $message = $_SESSION[$type];
        unset($_SESSION[$type]);
        return $message;
    }
    return null;
}

/**
 * Formats a date string to a readable format
 * @param string $date The date string to format
 * @return string The formatted date
 */
function format_date($date) {
    return date('M j, Y g:i A', strtotime($date));
}

/**
 * Format currency
 * @param float $amount The amount to format
 * @return string The formatted currency
 */
function format_currency($amount) {
    return '₱ ' . number_format($amount, 2);
}

/**
 * Generate select options
 * @param array $options Array of options (value => label)
 * @param string|int $selected The selected value
 * @return string The generated HTML options
 */
function generate_options($options, $selected = '') {
    $html = '';
    foreach ($options as $value => $label) {
        $select = ($value == $selected) ? ' selected' : '';
        $html .= '<option value="' . sanitize_output($value) . '"' . $select . '>' . sanitize_output($label) . '</option>';
    }
    return $html;
}

/**
 * Check if string contains only allowed characters
 * @param string $str The string to check
 * @param string $pattern The regex pattern to match against
 * @return bool Whether the string is valid
 */
function is_valid_input($str, $pattern = '/^[a-zA-Z0-9\s\-_\.]+$/') {
    return preg_match($pattern, $str);
}

/**
 * Generate random reference number
 * @param string $prefix The prefix for the reference number
 * @return string The generated reference number
 */
function generate_reference($prefix = 'REF') {
    return $prefix . date('Ymd') . substr(uniqid(), -5);
}

/**
 * Logs an audit event
 * @param int $user_id The ID of the user performing the action
 * @param string $action The action being performed
 * @param string $table The table being affected
 * @param int $record_id The ID of the record being affected
 * @param array|null $old_values The old values before the change
 * @param array|null $new_values The new values after the change
 */
function log_audit($user_id, $action, $table, $record_id, $old_values = null, $new_values = null) {
    global $pdo;
    
    $stmt = $pdo->prepare(
        "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values) 
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    
    $stmt->execute([
        $user_id,
        $action,
        $table,
        $record_id,
        $old_values ? json_encode($old_values) : null,
        $new_values ? json_encode($new_values) : null
    ]);
}

/**
 * Gets the current database connection
 * @return PDO The database connection
 */
function get_db_connection() {
    global $pdo;
    
    if (!isset($pdo)) {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
        );
    }
    
    return $pdo;
}
?> 