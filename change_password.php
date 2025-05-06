<?php
session_start();
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

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

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    error_log("Unauthorized access: user_id or user_role not set");
    header("Location: login.php");
    exit();
}

// Debug: Log session data
error_log("Password edit page accessed: user_id={$_SESSION['user_id']}, user_role={$_SESSION['user_role']}");

// Function to get a valid "From" email address
function getValidFromAddress() {
    return 'wonwinpor@gmail.com'; // Use the same email as SMTP username for Gmail
}

// Function to get localhost domain for the confirmation link
function getLocalhostDomain() {
    return "http://localhost/attend_system";
}

$message = '';
$messageClass = '';

// If user_email is not in session, fetch it from the database
if (!isset($_SESSION['user_email']) || empty($_SESSION['user_email'])) {
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['user_role'];

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
        $stmt = $conn->prepare("SELECT email, password_changed FROM $table WHERE $id_column = ?");
        $stmt->bind_param("s", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['password_changed'] = $user['password_changed'];
        } else {
            $message = "Error: Unable to retrieve user email. Please log out and log in again.";
            $messageClass = 'error';
            error_log("User not found: user_id=$user_id, user_role=$user_role, table=$table, id_column=$id_column");
        }

        $stmt->close();
    } catch (Exception $e) {
        $message = "Database error: Unable to retrieve user email.";
        $messageClass = 'error';
        error_log("Database error: " . $e->getMessage());
    }
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    // Validate password strength
    if (empty($new_password) || empty($confirm_password)) {
        $message = "Please fill in both password fields.";
        $messageClass = 'error';
    } elseif (strlen($new_password) < 8) {
        $message = "Password must be at least 8 characters long.";
        $messageClass = 'error';
    } elseif (!preg_match("/[A-Z]/", $new_password)) {
        $message = "Password must contain at least one uppercase letter.";
        $messageClass = 'error';
    } elseif (!preg_match("/[a-z]/", $new_password)) {
        $message = "Password must contain at least one lowercase letter.";
        $messageClass = 'error';
    } elseif (!preg_match("/[0-9]/", $new_password)) {
        $message = "Password must contain at least one number.";
        $messageClass = 'error';
    } elseif ($new_password !== $confirm_password) {
        $message = "Passwords do not match.";
        $messageClass = 'error';
    } else {
        $email = $_SESSION['user_email'];
        $user_id = $_SESSION['user_id'];
        $user_role = $_SESSION['user_role'];

        // Hash the new password and store it in session temporarily
        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
        $_SESSION['new_hashed_password'] = $hashed_password;

        // Generate a secure token for confirmation
        $token = bin2hex(random_bytes(32));
        $_SESSION['password_reset_token'] = $token;
        $_SESSION['password_reset_expiry'] = time() + (15 * 60); // Token expires in 15 minutes

        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'wonwinpor@gmail.com';
            $mail->Password = 'dvom wjpg hkkb xjdo'; // Ensure this is an App Password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            // Additional Gmail settings
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];

            // Recipients
            $fromAddress = getValidFromAddress();
            $mail->setFrom($fromAddress, 'Attendance System');
            $mail->addAddress($email);

            // Content
            $mail->isHTML(true);
            $mail->Subject = "Confirm Password Change";
            $confirmation_link = getLocalhostDomain() . "/confirm_password.php?token=" . $token;
            $mail->Body = "Please click the following link to confirm your password change: <a href='$confirmation_link'>$confirmation_link</a><br>This link will expire in 15 minutes.";
            $mail->AltBody = "Please click the following link to confirm your password change: $confirmation_link\nThis link will expire in 15 minutes.";

            $mail->send();
            $message = "A confirmation email has been sent to your address ($email). Please check your inbox and spam folder.";
            $messageClass = 'success';
            error_log("Confirmation email sent to $email. Link: $confirmation_link");
        } catch (Exception $e) {
            $message = "Message could not be sent. Please try again later or contact support.";
            $messageClass = 'error';
            error_log("Failed to send email to $email. Error: " . $mail->ErrorInfo);

            // Clean up session data on failure
            unset($_SESSION['new_hashed_password']);
            unset($_SESSION['password_reset_token']);
            unset($_SESSION['password_reset_expiry']);
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
    <title>Password Editing Page</title>
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
        }

        .password-box {
            width: 350px;
            padding: 20px;
            border: none;
            background-color: transparent;
        }

        .password-box h2 {
            margin-bottom: 20px;
            font-size: 24px;
            color: #333;
            text-align: center;
        }

        .input-group {
            margin-bottom: 20px;
            text-align: left;
            padding-left: 10px;
        }

        .input-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
            text-align: left;
        }

        .input-group input {
            width: 90%;
            padding: 12px;
            border: 1px solid #aaa;
            border-radius: 5px;
            font-size: 16px;
            text-align: center;
        }

        button {
            width: 95%;
            padding: 12px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 18px;
            cursor: pointer;
            margin-top: 10px;
            text-align: center;
        }

        button:hover {
            background-color: #0056b3;
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
    </style>
</head>
<body>
    <div class="container">
        <div class="password-box">
            <h2>Edit Password</h2>
            <?php if (!empty($message)): ?>
                <div class="message <?php echo $messageClass; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <div class="input-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required>
                </div>
                <div class="input-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                <button type="submit">Submit</button>
            </form>
        </div>
    </div>
</body>
</html>