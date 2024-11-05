<?php
session_start();
require 'database_connection.php';  // Ensure this file exists and contains your database connection logic

// Simulating admin check. In a real application, this would involve proper authentication.
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

// Redirect non-admin users
if (!isAdmin()) {
    header("Location: login.php");
    exit();
}

// Fetch dashboard data
$totalStudents = $conn->query("SELECT COUNT(*) FROM students")->fetch_row()[0];
$presentToday = $conn->query("SELECT COUNT(*) FROM attendance WHERE DATE(scan_time) = CURDATE() AND status = 'present'")->fetch_row()[0];
$newUsers = $conn->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()")->fetch_row()[0];
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
        
    </div>

    <div class="admin-container">
        <aside class="sidebar">
            <ul>
                <li><a href="admin_dashboard.php">Dashboard</a></li>
                <li><a href="manage_users.php">Manage Users</a></li>
                <li><a href="add_course.php">Add Course</a></li>
                <li><a href="enroll_student.php">Enroll Student</a></li>
                
                <li><a href="logout.php">Logout</a></li>
            </ul>
        </aside>

        <div class="main-content">
            <section class="dashboard-section" id="dashboard">
                <h2>Admin Dashboard Overview</h2>
                <div class="stats-container">
                    <div class="stat-box">Total Students: <span id="totalStudents"><?php echo $totalStudents; ?></span></div>
                    <div class="stat-box">Present Today: <span id="presentToday"><?php echo $presentToday; ?></span></div>
                    <div class="stat-box">New Users: <span id="newUsers"><?php echo $newUsers; ?></span></div>
                </div>
                
                <div class="chart-container">
                    <canvas id="studentChart"></canvas>
                </div>
            </section>
        </div>
    </div>

    <footer>
        <p>&copy; <?php echo date("Y"); ?> University Admin Dashboard. All rights reserved.</p>
    </footer>

    <script>
        window.onload = function() {
            initializeChart();
        }

        function initializeChart() {
            const ctx = document.getElementById('studentChart').getContext('2d');
            const studentChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Total Students', 'Students Present', 'New Users'],
                    datasets: [{
                        label: 'Student Statistics',
                        data: [<?php echo "$totalStudents, $presentToday, $newUsers"; ?>],
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
                            beginAtZero: true
                        }
                    }
                }
            });
        }
    </script>

    <style>
        /* Reset CSS */
        body, html {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
            height: 100%; /* Make content take up the full height */
            display: flex;
            flex-direction: column;
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

        /* Admin Page Layout */
        .admin-container {
            display: flex;
            flex: 1;
            width: 100%;
            height: 100%;
            background: white;
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
            height: 100vh; /* Make Sidebar full height */
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
            height: 100vh;
            overflow-y: auto;
        }

        .stats-container {
            display: flex;
            gap: 20px;
            margin-top: 20px;
            justify-content: center;
        }

        .stat-box {
            background-color: #3498db;
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
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

        /* Footer Styling */
        footer {
            text-align: center;
            padding: 10px;
            background-color: #34495e;
            color: white;
            width: 100%;
        }

        /* Profile Image Styling */
        .profile-image {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 70px;
            border: 2px solid white;
        }
    </style>
</body>
</html>
