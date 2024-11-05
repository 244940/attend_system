<?php
session_start();
require 'database_connection.php'; // Ensure this file contains proper DB connection

// Check if user is already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['user_email'])) {
    // Redirect to change password page if already logged in
    header("Location: change_password.php");
    exit();
}

$message = ''; // Message for login feedback

// Handle form submission for login
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_id = $_POST['user_id'];
    $email = $_POST['email'];
    $user_role = $_POST['user_role']; // Optional: User role can be selected (student/teacher/admin)

    // Basic validation
    if (empty($user_id) || empty($email)) {
        $message = "Please fill in both User ID and Email.";
    } else {
        // Check user credentials based on user role
        switch ($user_role) {
            case 'teacher':
                $table = 'teachers';
                break;
            case 'admin':
                $table = 'admins';
                $id_column = 'id'; // Assuming admin table uses 'id'
                break;
            default:
                $table = 'students';
                $id_column = 'user_id';
        }

        // Prepare SQL query to check if the user exists and matches both user_id and email
        $stmt = $conn->prepare("SELECT * FROM $table WHERE $id_column = ? AND email = ?");
        $stmt->bind_param("is", $user_id, $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            // User found, start session
            $user = $result->fetch_assoc();
            $_SESSION['user_id'] = $user[$id_column];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user_role;
            $_SESSION['password_changed'] = $user['password_changed'];

            // Redirect to the change password page
            header("Location: change_password.php");
            exit();
        } else {
            $message = "Invalid User ID or Email. Please try again.";
        }

        $stmt->close();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login First Time</title>
    <link rel="stylesheet" href="styles1.css">
</head>
<body>
    <div class="container">
        <div class="login-box">
            <h2>Login First Time</h2>
            <?php if (!empty($message)): ?>
                <div class="message"><?php echo $message; ?></div>
            <?php endif; ?>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <div class="input-group">
                    <label for="user_id">User ID</label>
                    <input type="text" id="user_id" name="user_id" required>
                </div>
                <div class="input-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="input-group">
                    <label for="user_role">User Role</label>
                    <select id="user_role" name="user_role">
                        <option value="student">Student</option>
                        <option value="teacher">Teacher</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <button type="submit">Login</button>
            </form>
        </div>
    </div>
</body>
</html>
