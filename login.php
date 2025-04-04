<?php
// Start the session
session_start();

// Database connection details
$host = "localhost";
$username = "root";
$password = "paganini019";
$dbname = "face_recognition_db";

// Create connection with port 3308
$conn = new mysqli($host . ":3308", $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$error = "";

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format";
    } else {
        // Prepare the SQL statement - simplified query that doesn't require user_id
        $stmt = $conn->prepare("
            SELECT u.id, u.name, u.user_role, 
                   CASE 
                       WHEN u.user_role = 'student' THEN s.hashed_password 
                       WHEN u.user_role = 'teacher' THEN t.hashed_password 
                       WHEN u.user_role = 'admin' THEN a.hashed_password 
                   END as hashed_password,
                   CASE 
                       WHEN u.user_role = 'student' THEN s.email 
                       WHEN u.user_role = 'teacher' THEN t.email 
                       WHEN u.user_role = 'admin' THEN a.email 
                   END as email,
                   CASE 
                       WHEN u.user_role = 'student' THEN s.password_changed 
                       WHEN u.user_role = 'teacher' THEN t.password_changed 
                       WHEN u.user_role = 'admin' THEN a.password_changed 
                   END as password_changed
            FROM users u
            LEFT JOIN students s ON u.id = s.user_id 
            LEFT JOIN teachers t ON u.id = t.user_id 
            LEFT JOIN admins a ON u.id = a.id
            WHERE s.email = ? OR t.email = ? OR a.email = ?
        ");

        $stmt->bind_param("sss", $email, $email, $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            $row = $result->fetch_assoc();

            // Check if it's the first login
            if ($row['password_changed'] == 0) {
                // First login, redirect to change password page
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['user_name'] = $row['name'];
                $_SESSION['user_role'] = $row['user_role'];
                $_SESSION['user_email'] = $row['email'];
                
                header("Location: login_first_time.php");
                exit();
            } else {
                // Not first login: verify password
                if (password_verify($password, $row['hashed_password'])) {
                    // Password correct, set session variables
                    $_SESSION['user_id'] = $row['id'];
                    $_SESSION['user_name'] = $row['name'];
                    $_SESSION['user_role'] = $row['user_role'];
                    $_SESSION['user_email'] = $row['email'];

                    // Redirect based on user role
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
                            $error = "Invalid user role.";
                            break;
                    }
                    exit();
                } else {
                    $error = "Invalid email or password.";
                }
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
        

        body {

            
            background-image: url('assets/bb.jpg'); /* เปลี่ยนเป็น URL ของภาพพื้นหลังที่ต้องการ */
            background-size: cover; /* ให้ภาพพื้นหลังครอบคลุมทั้งหน้า */
            background-position: center; /* จัดตำแหน่งภาพพื้นหลังให้อยู่ตรงกลาง */
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh; /* ให้มีความสูงเต็มหน้าจอ */
            margin: 0;
        

            
            

            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        /* เลเยอร์เบลอ */
        .blur-background {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: url('bb.jpg'); /* ใช้ภาพเดียวกับพื้นหลัง */
            background-size: cover;
            background-position: center;
            filter: blur(3px); /* กำหนดความเบลอ */
            z-index: -1; /* ทำให้เลเยอร์อยู่ด้านหลัง */
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
                        echo "<p class='error'>" . htmlspecialchars($error) . "</p>";
                    }
                    ?>
                    <div class="inputbox">
                        <input type="email" name="email" required>
                        <label for="email">Email</label>
                    </div>

                    <div class="inputbox">
                        <input type="password" name="password" required>
                        <label for="password">Password or ID(for first time)</label>
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