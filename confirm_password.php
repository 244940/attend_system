<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
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

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", user_role=" . ($_SESSION['user_role'] ?? 'unset'));
    header("Location: ../login.php");
    exit();
}

// Debug: Log session data
error_log("Admin dashboard accessed: user_id={$_SESSION['user_id']}, user_email={$_SESSION['user_email']}, user_role={$_SESSION['user_role']}");

// Get user counts
try {
    // Count admins
    $admin_result = $conn->query("SELECT COUNT(*) as count FROM admins");
    $admin_count = $admin_result->fetch_assoc()['count'];

    // Count teachers
    $teacher_result = $conn->query("SELECT COUNT(*) as count FROM teachers");
    $teacher_count = $teacher_result->fetch_assoc()['count'];

    // Count students
    $student_result = $conn->query("SELECT COUNT(*) as count FROM students");
    $student_count = $student_result->fetch_assoc()['count'];

    // Total users
    $total_users = $admin_count + $teacher_count + $student_count;

} catch (Exception $e) {
    error_log("Error fetching user counts: " . $e->getMessage());
    $admin_count = $teacher_count = $student_count = $total_users = 0;
}

// Close the connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
        }
        .welcome {
            margin-bottom: 20px;
        }
        .stats {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .stat-box {
            background: #03a9f4;
            color: white;
            padding: 20px;
            border-radius: 5px;
            flex: 1;
            min-width: 200px;
            text-align: center;
        }
        .stat-box h3 {
            margin: 0 0 10px;
        }
        .logout {
            margin-top: 20px;
        }
        .logout a {
            color: #03a9f4;
            text-decoration: none;
        }
        .logout a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Admin Dashboard</h1>
        <div class="welcome">
            <p>Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?> (<?php echo htmlspecialchars($_SESSION['user_email']); ?>)</p>
        </div>
        <div class="stats">
            <div class="stat-box">
                <h3>Admins</h3>
                <p><?php echo $admin_count; ?></p>
            </div>
            <div class="stat-box">
                <h3>Teachers</h3>
                <p><?php echo $teacher_count; ?></p>
            </div>
            <div class="stat-box">
                <h3>Students</h3>
                <p><?php echo $student_count; ?></p>
            </div>
            <div class="stat-box">
                <h3>Total Users</h3>
                <p><?php echo $total_users; ?></p>
            </div>
        </div>
        <div class="logout">
            <a href="../logout.php">Logout</a>
        </div>
    </div>
</body>
</html>