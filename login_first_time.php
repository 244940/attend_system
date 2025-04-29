<?php
session_start();
require 'database_connection.php'; // Ensure this file contains proper DB connection

// Check if user is coming from login.php with valid session
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_email']) || !isset($_SESSION['user_role'])) {
    header("Location: login.php");
    exit();
}

// Check if user has already changed password
if (isset($_SESSION['password_changed']) && $_SESSION['password_changed'] == TRUE) {
    // Redirect to appropriate dashboard
    switch ($_SESSION['user_role']) {
        case 'admin':
            header("Location: admin/admin_dashboard.php");
            break;
        case 'teacher':
            header("Location: teacher_dashboard.php");
            break;
        case 'student':
            header("Location: student_dashboard.php");
            break;
        default:
            session_destroy();
            header("Location: login.php");
            exit();
    }
}

$message = ''; // Message for login feedback

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $citizen_id = $_POST['citizen_id'];
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);

    // Basic validation
    if (empty($citizen_id) || empty($email)) {
        $message = "Please fill in both Citizen ID and Email.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format.";
    } elseif (!preg_match('/^\d{13}$/', $citizen_id)) {
        $message = "Citizen ID must be 13 digits.";
    } else {
        // Determine table and ID column based on user_role
        $user_role = $_SESSION['user_role'];
        switch ($user_role) {
            case 'admin':
                $table = 'admins';
                $id_column = 'admin_id';
                $name_column = 'admin_name';
                break;
            case 'teacher':
                $table = 'teachers';
                $id_column = 'teacher_id';
                $name_column = 'name';
                break;
            case 'student':
                $table = 'students';
                $id_column = 'student_id';
                $name_column = 'name';
                break;
            default:
                $message = "Invalid user role.";
                error_log("Invalid user role: $user_role");
                break;
        }

        if (empty($message)) {
            // Prepare SQL query to check user
            $stmt = $conn->prepare("
                SELECT $id_column, $name_column, email, citizen_id, password_changed, hashed_password
                FROM $table
                WHERE email = ? AND citizen_id = ? AND password_changed = FALSE AND hashed_password IS NULL
            ");
            $stmt->bind_param("ss", $email, $citizen_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 1) {
                $user = $result->fetch_assoc();
                
                // Verify session data
                if ($user[$id_column] == $_SESSION['user_id'] && $user['email'] == $_SESSION['user_email']) {
                    // Update session with additional data
                    $_SESSION['user_name'] = $user[$name_column];
                    $_SESSION['password_changed'] = $user['password_changed'];
                    
                    // Debug: Log successful validation
                    error_log("First-time login validated: email={$user['email']}, role=$user_role, citizen_id=$citizen_id");
                    
                    // Redirect to change password page
                    header("Location: change_password.php");
                    exit();
                } else {
                    $message = "Session data mismatch. Please log in again.";
                    error_log("Session mismatch: session_id={$_SESSION['user_id']}, session_email={$_SESSION['user_email']}, db_id={$user[$id_column]}, db_email={$user['email']}");
                    session_destroy();
                    header("Location: login.php");
                    exit();
                }
            } else {
                $message = "Invalid Citizen ID or Email, or account is not eligible for first-time login.";
                error_log("No user found or not eligible: email=$email, citizen_id=$citizen_id, role=$user_role");
            }

            $stmt->close();
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
    <title>Login First Time</title>
    <link rel="stylesheet" href="styles1.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background-color: #f4f4f4;
        }
        .container {
            width: 100%;
            max-width: 400px;
        }
        .login-box {
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .login-box h2 {
            text-align: center;
            margin-bottom: 20px;
            color: #333;
        }
        .input-group {
            margin-bottom: 15px;
        }
        .input-group label {
            display: block;
            margin-bottom: 5px;
            color: #666;
        }
        .input-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 16px;
        }
        .message {
            color: red;
            text-align: center;
            margin-bottom: 15px;
        }
        button {
            width: 100%;
            padding: 10px;
            background-color: #03a9f4;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
        }
        button:hover {
            background-color: #0288d1;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-box">
            <h2>Login First Time</h2>
            <?php if (!empty($message)): ?>
                <div class="message"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <div class="input-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($_SESSION['user_email'] ?? ''); ?>" required>
                </div>
                <div class="input-group">
                    <label for="citizen_id">Citizen ID</label>
                    <input type="text" id="citizen_id" name="citizen_id" pattern="\d{13}" title="Citizen ID must be 13 digits" required>
                </div>
                <button type="submit">Verify</button>
            </form>
        </div>
    </div>
</body>
</html>