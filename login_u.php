<?php
// Start the session
session_start();

// Database connection details
$host = "localhost";
$username = "root";
$password = "paganini019";
$dbname = "face_recognition_db";

// Create connection
$conn = new mysqli($host . ":3308", $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$error = "";

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $input_password = $_POST['password'];  // Capture the password input

    // Check if the user is an admin
    if ($email === 'admin' && $input_password === 'admin_password') { // Replace with a secure password check
        // Set session variables for admin user
        $_SESSION['user_id'] = 'admin';
        $_SESSION['user_name'] = 'Administrator';
        $_SESSION['user_role'] = 'admin';
        $_SESSION['user_email'] = 'admin@example.com';  // Store email in session

        // Redirect to admin dashboard
        header("Location: admin_dashboard.php");
        exit();
    } else {
        // Regular user login process
        
        // Prepare SQL statement to prevent SQL injection
        $stmt = $conn->prepare("
            SELECT u.id, u.name, u.user_role, COALESCE(s.email, t.email) as email, COALESCE(s.hashed_password, t.hashed_password) as hashed_password
            FROM users u
            LEFT JOIN students s ON u.id = s.user_id
            LEFT JOIN teachers t ON u.id = t.user_id
            WHERE (s.email = ? OR t.email = ?)
        ");
        
        $stmt->bind_param("ss", $email, $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            $row = $result->fetch_assoc();
            
            // Verify the hashed password
            if (password_verify($input_password, $row['hashed_password'])) {
                // Start a new session since login is successful
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['user_name'] = $row['name'];
                $_SESSION['user_role'] = $row['user_role'];
                $_SESSION['user_email'] = $row['email'];  // Store email in session

                // Redirect to user dashboard
                if ($_SESSION['user_role'] === 'admin') {
                    header("Location: admin_dashboard.php");
                } elseif ($_SESSION['user_role'] === 'teacher') {
                    header("Location: teacher_dashboard.php");
                } elseif ($_SESSION['user_role'] === 'student') {
                    header("Location: student_dashboard.php");
                } else {
                    // Handle invalid user role (should never happen)
                    $error = "Invalid user role.";
                }
                exit();
            } else {
                $error = "Invalid password.";
            }
        } else {
            $error = "Invalid email or password.";
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
    <link rel="stylesheet" href="style t.css">
    <style>
        /* Ensure html and body take the full height of the viewport */
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: #f4f4f4;
        }
        .form-box {
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
             /* Set width of form */
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
    </style>
</head>
<body>
    <section>
        <div class="form-box">
            <div class="form-value">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    <h2>Login</h2>
                    <?php
                    if (!empty($error)) {
                        echo "<p class='error'>$error</p>";
                    }
                    ?>
                    <div class="inputbox">
                        <input type="text" name="email" required>
                        <label for="email">Username or Email</label>
                    </div>

                    <div class="inputbox">
                        <input type="password" name="password" required>
                        <label for="password">Password</label>
                    </div>
                    <button type="submit">Log in</button>
                    <div class="register">
                        <p>Don't have an account? <a href="#">Register</a></p>
                    </div>
                </form>
            </div>
        </div>
    </section>
</body>
</html>
