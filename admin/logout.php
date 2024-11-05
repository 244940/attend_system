<?php
// login.php (in the root directory)
session_start();

// Unset all session variables
session_unset();

// Destroy the session
session_destroy();

// Check if the user is logged in and has the admin role
if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
    
    // Redirect the user to the admin dashboard
    header("Location: admin/admin_dashboard.php");
    exit();
} else {
    // Redirect the user to the login page
    header("Location: /attend_system/login.php");
    exit();
}
?>
