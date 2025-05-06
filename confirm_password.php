<?php
session_start();

// Database connection details
$host = "localhost";
$username = "root";
$password = "paganini019";
$dbname = "attend_data";

// Create connection
$conn = new mysqli($host . ":3308", $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    error_log("Connection failed: " . $conn->connect_error);
    die("Connection failed. Please try again later.");
}

$message = '';
$messageClass = '';

// Check if token is provided
if (!isset($_GET['token']) || empty($_GET['token'])) {
    $message = "Invalid or missing token. Please try again.";
    $messageClass = 'error';
    error_log("Invalid or missing token in confirm_password.php");
} else {
    $token = $_GET['token'];

    // Check if user is logged in and token matches
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || !isset($_SESSION['password_reset_token']) || !isset($_SESSION['new_hashed_password'])) {
        $message = "Session expired or invalid. Please log in and try again.";
        $messageClass = 'error';
        error_log("Session expired or invalid: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", token mismatch");
    } elseif ($_SESSION['password_reset_token'] !== $token) {
        $message = "Invalid token. Please try again.";
        $messageClass = 'error';
        error_log("Token mismatch: session_token={$_SESSION['password_reset_token']}, provided_token=$token");
    } elseif (time() > $_SESSION['password_reset_expiry']) {
        $message = "This confirmation link has expired. Please try changing your password again.";
        $messageClass = 'error';
        error_log("Token expired: user_id={$_SESSION['user_id']}, expiry={$_SESSION['password_reset_expiry']}, current_time=" . time());
    } else {
        // Token is valid, proceed to update the password
        $user_id = $_SESSION['user_id'];
        $user_role = $_SESSION['user_role'];
        $hashed_password = $_SESSION['new_hashed_password'];

        // Determine the correct table and ID column based on user role
        $table = '';
        $id_column = '';
        
        switch ($user_role) {
            case 'teacher':
                $table = 'teachers';
                $id_column = 'teacher_id';
                break;
            case 'admin':
                $table = 'admins';
                $id_column = 'admin_id';
                break;
            case 'student':
            default:
                $table = 'students';
                $id_column = 'student_id';
                break;
        }

        try {
            // Update the password and set password_changed to 1
            $stmt = $conn->prepare("UPDATE $table SET hashed_password = ?, password_changed = 1 WHERE $id_column = ?");
            $stmt->bind_param("ss", $hashed_password, $user_id);
            if ($stmt->execute()) {
                $message = "Password updated successfully. You can now log in with your new password.";
                $messageClass = 'success';
                error_log("Password updated successfully: user_id=$user_id, user_role=$user_role");

                // Clean up session data
                unset($_SESSION['new_hashed_password']);
                unset($_SESSION['password_reset_token']);
                unset($_SESSION['password_reset_expiry']);

                // Optionally log the user out
                session_unset();
                session_destroy();
            } else {
                $message = "Failed to update password. Please try again.";
                $messageClass = 'error';
                error_log("Failed to update password: " . $stmt->error);
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = "Database error: Unable to update password.";
            $messageClass = 'error';
            error_log("Database error: " . $e->getMessage());
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Password Change</title>
    <style>
        body, html {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
            height: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            background: url('assets/bb.jpg') no-repeat center center fixed;
            background-size: cover;
        }

        .container {
            background: rgba(255, 255, 255, 0.8);
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.3);
            text-align: center;
            width: 100%;
            max-width: 400px;
        }

        h2 {
            margin-bottom: 20px;
            font-size: 24px;
            color: #333;
        }

        .message {
            padding: 10px;
            margin-bottom: 15px;
            text-align: center;
            border-radius: 4px;
        }

        .message.success {
            color: green;
            background-color: #e6ffe6;
        }

        .message.error {
            color: red;
            background-color: #ffe6e6;
        }

        a {
            color: #007bff;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Confirm Password Change</h2>
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $messageClass; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        <p><a href="login.php">Return to Login</a></p>
    </div>
</body>
</html>