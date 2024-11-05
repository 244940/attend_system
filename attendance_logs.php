<?php
session_start();
require 'database_connection.php'; // Include your database connection

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: login.php");
    exit();
}

// Get course code from URL
$course_code = $_GET['course_code'] ?? null;

if (!$course_code) {
    die("Invalid course.");
}

// Get student ID
$stmt = $conn->prepare("SELECT student_id FROM students WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Error: Student not found.");
}
$student_id = $result->fetch_assoc()['student_id'];
$stmt->close();

// Retrieve schedule for the selected course
$get_schedule_stmt = $conn->prepare("
    SELECT s.schedule_id, s.day_of_week, s.start_time, s.end_time 
    FROM schedules s 
    INNER JOIN courses c ON s.course_id = c.id 
    WHERE c.course_code = ?");

$get_schedule_stmt->bind_param("s", $course_code);
$get_schedule_stmt->execute();
$schedule_result = $get_schedule_stmt->get_result();

if ($schedule_result->num_rows === 0) {
    die("No schedule found for this course.");
}

$schedule = $schedule_result->fetch_assoc();
$schedule_id = $schedule['schedule_id'];
$get_schedule_stmt->close();

// Retrieve attendance logs for the selected schedule
$get_attendance_stmt = $conn->prepare("
    SELECT a.scan_time, a.status 
    FROM attendance a 
    WHERE a.schedule_id = ? AND a.user_id = ?");
$get_attendance_stmt->bind_param("ii", $schedule_id, $student_id);
$get_attendance_stmt->execute();
$attendance_result = $get_attendance_stmt->get_result();
$attendance_logs = $attendance_result->fetch_all(MYSQLI_ASSOC);
$get_attendance_stmt->close();

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Logs</title>
</head>
<body>

<h1>Attendance Logs for Course: <?php echo htmlspecialchars($course_code); ?></h1>

<table border="1">
    <thead>
        <tr>
            <th>Scan Time</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($attendance_logs)): ?>
            <tr><td colspan="2">No attendance records found.</td></tr>
        <?php else: ?>
            <?php foreach ($attendance_logs as $log): ?>
                <tr>
                    <td><?php echo htmlspecialchars($log['scan_time']); ?></td>
                    <td><?php echo htmlspecialchars($log['status']); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<a href="student_dashboard.php"><button>Back to Dashboard</button></a>

</body>
</html>