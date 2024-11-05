<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Database connection details
$host = "localhost";
$username = "root";
$password = "paganini019";
$dbname = "face_recognition_db";

// Create connection
$conn = new mysqli($host.":3308", $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to sanitize user input
function sanitize_input($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

$error = "";
$success = "";

// Handle both direct form submission and token-based password reset
if ($_SERVER["REQUEST_METHOD"] == "POST" || isset($_GET['token'])) {
    // Initialize variables
    $user_id = $_SESSION['user_id'] ?? 0;
    $user_role = $_SESSION['user_role'] ?? '';
    $hashed_password = '';
    
    // Handle direct form submission
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
            header("Location: login.php");
            exit();
        }
        
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if ($new_password !== $confirm_password) {
            $error = "Passwords do not match!";
            goto end_script;
        }
        
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    }
    // Handle token-based reset
    else if (isset($_GET['token'])) {
        $token = sanitize_input($_GET['token']);
        
        if (!$token || !isset($_SESSION['password_reset_token']) || 
            $token !== $_SESSION['password_reset_token']) {
            $error = "Invalid or expired token. Please request a new password reset.";
            goto end_script;
        }
        
        $hashed_password = $_SESSION['new_hashed_password'] ?? '';
        
        if (!$hashed_password) {
            $error = "Invalid session data. Please try resetting your password again.";
            goto end_script;
        }
    }

    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Determine which table to update based on user role
        $password_update_query = "";
        switch ($user_role) {
            case 'student':
                $password_update_query = "UPDATE students SET hashed_password = ?, password_changed = 1 WHERE user_id = ?";
                break;
            case 'teacher':
                $password_update_query = "UPDATE teachers SET hashed_password = ?, password_changed = 1 WHERE user_id = ?";
                break;
            case 'admin':
                $password_update_query = "UPDATE admins SET hashed_password = ?, password_changed = 1 WHERE id = ?";
                break;
            default:
                throw new Exception("Invalid user role");
        }

        // Execute password update
        $stmt = $conn->prepare($password_update_query);
        $stmt->bind_param("si", $hashed_password, $user_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update password");
        }

        // Check if any rows were affected
        if ($stmt->affected_rows == 0) {
            throw new Exception("No records were updated");
        }

        // Commit transaction
        $conn->commit();
        $success = "Password successfully changed!";

        // Clear token-related session data if it exists
        if (isset($_SESSION['password_reset_token'])) {
            unset($_SESSION['password_reset_token']);
            unset($_SESSION['new_hashed_password']);
        }

        // Optionally, you might want to log the user out here to force a new login
        // session_destroy();
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $error = "Error: " . $e->getMessage();
    }
    
    if (isset($stmt)) {
        $stmt->close();
    }
}
end_script:
// Close the connection
$conn->close();

// Initialize message and message_class
$message = $error ?: $success;
$message_class = $error ? "error" : "success";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Password Change</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; }
        .message { background: #f4f4f4; border: 1px solid #ddd; padding: 20px; margin-bottom: 20px; }
        .success { color: green; }
        .error { color: red; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Confirm Password Change</h1>
        <div class="message <?php echo $message_class; ?>">
            <?php echo $message; ?>
        </div>
        <?php if ($message_class === 'success'): ?>
            <p>You can now <a href="login.php">log in with your new password</a>.</p>
        <?php else: ?>
            <p>Please <a href="change_password.php">try changing your password again</a>.</p>
        <?php endif; ?>
    </div>
</body>
</html>
