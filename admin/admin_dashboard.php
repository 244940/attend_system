<?php
session_start();
require 'database_connection.php';  // Ensure this file exists and contains your database connection logic


// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Debug: Log session data at the start
error_log("Session data at start of admin_dashboard.php: " . print_r($_SESSION, true));

// Function to check if user is admin
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin' && isset($_SESSION['admin_id']);
}

// Redirect non-admin users
if (!isAdmin()) {
    error_log("Unauthorized access: admin_id=" . ($_SESSION['admin_id'] ?? 'unset') . ", user_role=" . ($_SESSION['user_role'] ?? 'unset'));
    header("Location: ../login.php");
    exit();
}

// Debug: Log successful access
error_log("Admin dashboard accessed: admin_id={$_SESSION['admin_id']}, user_email={$_SESSION['user_email']}, user_role={$_SESSION['user_role']}");

// Fetch dashboard data with error handling
try {
    // Total admins
    $totalAdminsResult = $conn->query("SELECT COUNT(*) FROM admins");
    if ($totalAdminsResult === false) {
        throw new Exception("Error querying admins: " . $conn->error);
    }
    $totalAdmins = $totalAdminsResult->fetch_row()[0];

    // Total teachers
    $totalTeachersResult = $conn->query("SELECT COUNT(*) FROM teachers");
    if ($totalTeachersResult === false) {
        throw new Exception("Error querying teachers: " . $conn->error);
    }
    $totalTeachers = $totalTeachersResult->fetch_row()[0];

    // Total students
    $totalStudentsResult = $conn->query("SELECT COUNT(*) FROM students");
    if ($totalStudentsResult === false) {
        throw new Exception("Error querying students: " . $conn->error);
    }
    $totalStudents = $totalStudentsResult->fetch_row()[0];

} catch (Exception $e) {
    error_log("Dashboard query error: " . $e->getMessage());
    $totalAdmins = $totalTeachers = $totalStudents = 0; // Default to 0 on error
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
    <link rel="stylesheet" href="admin-styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="top-bar">
        <h1>Admin Dashboard</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
        </div>
    </div>

    <div class="admin-container">
        <aside class="sidebar">
            <ul>
                <li><a href="admin_dashboard.php">Dashboard</a></li>
                <li><a href="manage_users.php">Manage Users</a></li>
                <li><a href="add_users.php">Add Users</a></li>
                <li><a href="add_course.php">Add Course</a></li>
                <li><a href="enroll_student.php">Enroll Student</a></li>
                <li><a href="../logout.php">Logout</a></li>
            </ul>
        </aside>

        <div class="main-content">
            <section class="dashboard-section" id="dashboard">
                <h2>Admin Dashboard Overview</h2>
                <div class="stats-container">
                    <div class="stat-box">Total Admins: <span id="totalAdmins"><?php echo $totalAdmins; ?></span></div>
                    <div class="stat-box">Total Teachers: <span id="totalTeachers"><?php echo $totalTeachers; ?></span></div>
                    <div class="stat-box">Total Students: <span id="totalStudents"><?php echo $totalStudents; ?></span></div>
                </div>
                
                <div class="chart-container">
                    <canvas id="userChart"></canvas>
                </div>
            </section>
        </div>
    </div>

    <footer>
        <p>© <?php echo date("Y"); ?> University Admin Dashboard. All rights reserved.</p>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            initializeChart();
        });

        function initializeChart() {
            console.log('Initializing chart...');

            // Validate data
            const totalAdmins = <?php echo json_encode($totalAdmins); ?>;
            const totalTeachers = <?php echo json_encode($totalTeachers); ?>;
            const totalStudents = <?php echo json_encode($totalStudents); ?>;

            console.log('Chart data:', { totalAdmins, totalTeachers, totalStudents });

            // Ensure data is numeric, default to 0 if invalid
            const data = [
                isNaN(totalAdmins) ? 0 : Number(totalAdmins),
                isNaN(totalTeachers) ? 0 : Number(totalTeachers),
                isNaN(totalStudents) ? 0 : Number(totalStudents)
            ];

            const ctx = document.getElementById('userChart').getContext('2d');
            if (!ctx) {
                console.error('Canvas context not found for userChart');
                return;
            }

            try {
                const userChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: ['Admins', 'Teachers', 'Students'],
                        datasets: [{
                            label: 'User Statistics',
                            data: data,
                            backgroundColor: [
                                'rgba(52, 152, 219, 0.7)',
                                'rgba(46, 204, 113, 0.7)',
                                'rgba(231, 76, 60, 0.7)'
                            ],
                            borderColor: [
                                'rgba(52, 152, 219, 1)',
                                'rgba(46, 204, 113, 1)',
                                'rgba(231, 76, 60, 1)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Count'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'User Roles'
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top'
                            },
                            title: {
                                display: true,
                                text: 'User Distribution'
                            }
                        }
                    }
                });
                console.log('Chart initialized successfully');
            } catch (error) {
                console.error('Error initializing chart:', error);
            }
        }
    </script>

    <style>
        /* Reset CSS */
        body, html {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
            height: 100%;
            display: flex;
            flex-direction: column;
            background-image: url('../assets/bb.jpg');
            background-size: cover;
            background-position: center;
        }

        /* Top Bar */
        .top-bar {
            width: 100%;
            background-color: #2980b9;
            color: white;
            padding: 15px 20px;
            text-align: left;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .top-bar h1 {
            margin: 0;
            font-size: 24px;
        }

        .user-info {
            font-size: 16px;
        }

        /* Admin Page Layout */
        .admin-container {
            display: flex;
            flex: 1;
            width: 100%;
            height: calc(100vh - 70px); /* Adjust for top bar and footer */
            background: rgba(255, 255, 255, 0.9);
        }

        /* Sidebar Styling */
        .sidebar {
            width: 250px;
            background-color: #2c3e50;
            color: white;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            height: 100%;
        }

        .sidebar ul {
            list-style-type: none;
            padding: 0;
            width: 100%;
        }

        .sidebar ul li {
            margin: 15px 0;
            text-align: center;
        }

        .sidebar ul li a {
            color: white;
            text-decoration: none;
            display: block;
            padding: 10px;
            transition: background 0.3s;
        }

        .sidebar ul li a:hover {
            background-color: #34495e;
            border-radius: 5px;
        }

        /* Main Content Area */
        .main-content {
            flex: 1;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            background-color: #ecf0f1;
            height: 100%;
            overflow-y: auto;
        }

        .stats-container {
            display: flex;
            gap: 20px;
            margin-top: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .stat-box {
            background-color: #3498db;
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            min-width: 150px;
        }

        /* Chart Container Styling */
        .chart-container {
            width: 80%;
            max-width: 600px;
            margin: 20px auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        #userChart {
            width: 100% !important;
            height: 300px !important;
        }

        /* Footer Styling */
        footer {
            text-align: center;
            padding: 10px;
            background-color: #34495e;
            color: white;
            width: 100%;
        }
    </style>
</body>
</html>