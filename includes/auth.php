<?php
require_once 'db.php';

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function login($username, $password) {
        $stmt = $this->db->query(
            "SELECT * FROM users WHERE username = ?",
            array($username)
        );
        
        if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                return true;
            }
        }
        return false;
    }
    
    public function register($userData) {
        // Validate username uniqueness
        $stmt = $this->db->query(
            "SELECT COUNT(*) as count FROM users WHERE username = ?",
            array($userData['username'])
        );
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            return false;
        }
        
        // Hash password
        $userData['password'] = password_hash($userData['password'], PASSWORD_DEFAULT);
        
        // Insert user
        return $this->db->insert('users', $userData);
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
    
    public function logout() {
        session_destroy();
        return true;
    }
    
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        $stmt = $this->db->query(
            "SELECT user_id, username, email, full_name, role FROM users WHERE user_id = ?",
            array($_SESSION['user_id'])
        );
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function hasPermission($role) {
        if (!$this->isLoggedIn()) {
            return false;
        }
        return $_SESSION['role'] === $role;
    }
    
    public function updateProfile($userId, $data) {
        // Don't allow role updates through this method
        unset($data['role']);
        
        if (isset($data['password'])) {
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        
        return $this->db->update('users', $data, "user_id = " . $userId);
    }
}

// Initialize auth
$auth = new Auth();

// Redirect if not logged in (except for login and register pages)
$publicPages = array('login.php', 'register.php');
$currentPage = basename($_SERVER['PHP_SELF']);

if (!in_array($currentPage, $publicPages) && !$auth->isLoggedIn()) {
    header('Location: login.php');
    exit();
}
?> 