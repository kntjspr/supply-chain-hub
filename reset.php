<?php
session_start();
session_destroy();

try {
    // Connect to MySQL without selecting a database
    $pdo = new PDO(
        "mysql:host=localhost",
        "root",
        "",
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    
    // Drop the database if it exists
    $pdo->exec("DROP DATABASE IF EXISTS usch_db");
    
    // Delete config file if it exists
    if (file_exists('includes/config.php')) {
        unlink('includes/config.php');
    }
    
    // Redirect to installer
    header('Location: install.php');
    exit();
    
} catch (PDOException $e) {
    die('Error resetting application: ' . $e->getMessage());
}
?> 