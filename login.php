<?php
// Start the session
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1); // Set to 0 in production

// Database connection details
$host = "localhost";
$username = "root";
$password = "paganini019";
$dbname = "attend_data";

// Create connection with port 3308
$conn = new mysqli($host . ":3308", $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    error_log("Connection failed: " . $conn->connect_error);
    die("Connection failed. Please try again later.");
}

$error = "";

// Handle login form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_login'])) {
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    $password = trim($_POST['password']); // Remove leading/trailing spaces

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format";
        error_log("Invalid email format: $email");
    } else {
        // Query to find user in admins, teachers, or students
        $stmt = $conn->prepare("
            SELECT 'admin' AS user_role, admin_id AS id, admin_name AS name, email, hashed_password, password_changed, citizen_id
            FROM admins WHERE email = ?
            UNION
            SELECT 'teacher' AS user_role, teacher_id AS id, name, email, hashed_password, password_changed, citizen_id
            FROM teachers WHERE email = ?
            UNION
            SELECT 'student' AS user_role, student_id AS id, name, email, hashed_password, password_changed, citizen_id
            FROM students WHERE email = ?
        ");
        $stmt->bind_param("sss", $email, $email, $email);
        if (!$stmt->execute()) {
            $error = "Database error.";
            error_log("SQL error: " . $stmt->error);
        } else {
            $result = $stmt->get_result();

            if ($result->num_rows == 1) {
                $row = $result->fetch_assoc();
                $is_first_login = ($row['password_changed'] == 0 && $row['hashed_password'] === null);

                // First-time login: verify citizen_id
                if ($is_first_login) {
                    if ($password === $row['citizen_id']) {
                        // Set session variables and redirect to change password page
                        $_SESSION['user_name'] = $row['name'];
                        $_SESSION['user_role'] = $row['user_role'];
                        $_SESSION['user_email'] = $row['email'];
                        $_SESSION['password_changed'] = $row['password_changed'];
                        $_SESSION['user_id'] = $row['id']; // Add this to set user_id
                
                        if ($row['user_role'] === 'admin') {
                            $_SESSION['admin_id'] = $row['id'];
                        } elseif ($row['user_role'] === 'teacher') {
                            $_SESSION['teacher_id'] = $row['id'];
                        } elseif ($row['user_role'] === 'student') {
                            $_SESSION['student_id'] = $row['id'];
                        }
                
                        error_log("First login successful: email={$row['email']}, citizen_id=$password, user_id={$row['id']}");
                        header("Location: login_first_time.php");
                        exit();
                    } else {
                        $error = "Invalid Citizen ID for first-time login.";
                        error_log("Invalid citizen ID: input=$password, expected={$row['citizen_id']}");
                    }
                
                } else {
                    // Regular login: verify password
                    if ($row['hashed_password'] !== null && password_verify($password, $row['hashed_password'])) {
                        // Password correct, set session variables
                        $_SESSION['user_name'] = $row['name'];
                        $_SESSION['user_role'] = $row['user_role'];
                        $_SESSION['user_email'] = $row['email'];
                        $_SESSION['password_changed'] = $row['password_changed'];

                        if ($row['user_role'] === 'admin') {
                            $_SESSION['admin_id'] = $row['id'];
                        } elseif ($row['user_role'] === 'teacher') {
                            $_SESSION['teacher_id'] = $row['id'];
                        } elseif ($row['user_role'] === 'student') {
                            $_SESSION['student_id'] = $row['id'];
                        }

                        error_log("Normal login successful: email={$row['email']}, role={$row['user_role']}");
                        switch ($row['user_role']) {
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
                                $error = "Invalid user role.";
                                error_log("Invalid user role: {$row['user_role']}");
                                break;
                        }
                        exit();
                    } else {
                        $error = "Invalid password.";
                        error_log("Password verification failed: email={$row['email']}, hashed_password=" . ($row['hashed_password'] ?: 'NULL'));
                    }
                }
            } else {
                $error = "Invalid email or password.";
                error_log("No user found for email: $email");
            }
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
    <title>Login Form</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background-image: url('assets/bb.jpg');
            background-size: cover;
            background-position: center;
        }
        .blur-background {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: url('assets/bb.jpg');
            background-size: cover;
            background-position: center;
            filter: blur(3px);
            z-index: -1;
        }
        .form-box {
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .form-value {
            width: 300px;
        }
        h2 {
            text-align: center;
            color: #333;
        }
        .inputbox {
            position: relative;
            margin-bottom: 20px;
        }
        .inputbox input {
            width: 100%;
            padding: 10px 0;
            font-size: 16px;
            color: #333;
            border: none;
            border-bottom: 1px solid #333;
            outline: none;
            background: transparent;
        }
        .inputbox label {
            position: absolute;
            top: 0;
            left: 0;
            padding: 10px 0;
            font-size: 16px;
            color: #666;
            pointer-events: none;
            transition: 0.5s;
        }
        .inputbox input:focus ~ label,
        .inputbox input:valid ~ label {
            top: -20px;
            left: 0;
            color: #03a9f4;
            font-size: 12px;
        }
        .forget {
            margin-bottom: 20px;
        }
        button {
            width: 100%;
            padding: 10px;
            border: none;
            background-color: #03a9f4;
            color: white;
            font-size: 16px;
            cursor: pointer;
            border-radius: 5px;
        }
        .register {
            text-align: center;
            margin-top: 20px;
        }
        .error {
            color: red;
            text-align: center;
            margin-bottom: 10px;
        }
        .note {
            color: #666;
            font-size: 12px;
            text-align: center;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="blur-background"></div>
    <section>
        <div class="form-box">
            <div class="form-value">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    <h2>Login</h2>
                    <?php if (!empty($error)): ?>
                        <p class="error"><?php echo htmlspecialchars($error); ?></p>
                    <?php endif; ?>
                    <p class="note">For first-time login, use your 13-digit Citizen ID as the password.</p>
                    <div class="inputbox">
                        <input type="email" name="email" required>
                        <label for="email">Email</label>
                    </div>
                    <div class="inputbox">
                        <input type="password" name="password" required>
                        <label for="password">Password / Citizen ID</label>
                    </div>
                    <input type="hidden" name="submit_login" value="1">
                    <button type="submit">Log in</button>
                </form>
            </div>
        </div>
    </section>
</body>
</html>