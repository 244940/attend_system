<?php
session_start();
require 'database_connection.php'; // Include your database connection

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$course_id = $_GET['course_id'] ?? null;
$selected_date = $_GET['date'] ?? date('Y-m-d');
$schedule_id = $_GET['schedule_id'] ?? null;

if (!$course_id || !$selected_date) {
    echo "Invalid course or date.";
    exit();
}

// Validate course ID and schedule
$course_check_stmt = $conn->prepare("SELECT * FROM courses WHERE course_id = ?");
$course_check_stmt->bind_param("i", $course_id);
$course_check_stmt->execute();
$course_result = $course_check_stmt->get_result();

if ($course_result->num_rows === 0) {
    echo "Course not found.";
    exit();
}

// Fetch attendance based on user role
if ($user_role === 'teacher') {
    // For teachers, show students' attendance for the selected course and date
    $stmt = $conn->prepare("SELECT s.student_id, s.name, a.scan_time, a.status 
                           FROM attendance a
                           INNER JOIN students s ON a.student_id = s.student_id
                           WHERE a.course_id = ? AND DATE(a.scan_time) = ? 
                           " . ($schedule_id ? "AND a.schedule_id = ?" : ""));
    if ($schedule_id) {
        $stmt->bind_param("iss", $course_id, $selected_date, $schedule_id);
    } else {
        $stmt->bind_param("is", $course_id, $selected_date);
    }
} else if ($user_role === 'student') {
    // For students, show their own attendance for the selected course and date
    $stmt = $conn->prepare("SELECT scan_time, status FROM attendance 
                           WHERE student_id = ? AND course_id = ? AND DATE(scan_time) = ?");
    $stmt->bind_param("iis", $user_id, $course_id, $selected_date);
} else {
    echo "Invalid user role.";
    exit();
}

$stmt->execute();
$attendance_result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Details</title>
    <style>
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .status-present { background-color: #d4edda; }
        .status-late { background-color: #fff3cd; }
        .status-absent { background-color: #f8d7da; }
    </style>
</head>
<body>

<h1>Attendance Details - Course ID: <?php echo htmlspecialchars($course_id); ?> (Date: <?php echo htmlspecialchars($selected_date); ?>)</h1>

<table border="1">
    <tr>
        <?php if ($user_role === 'teacher'): ?>
            <th>Student ID</th>
            <th>Student Name</th>
        <?php endif; ?>
        <th>Scan Time</th>
        <th>Status</th>
        <?php if ($user_role === 'teacher'): ?>
            <th>Action</th>
        <?php endif; ?>
    </tr>

    <?php while ($row = $attendance_result->fetch_assoc()): ?>
        <tr class="status-<?php echo strtolower($row['status']); ?>">
            <?php if ($user_role === 'teacher'): ?>
                <td><?php echo htmlspecialchars($row['student_id']); ?></td>
                <td><?php echo htmlspecialchars($row['name']); ?></td>
            <?php endif; ?>
            <td><?php echo htmlspecialchars($row['scan_time'] ?: 'Not scanned'); ?></td>
            <td><?php echo htmlspecialchars($row['status']); ?></td>
            <?php if ($user_role === 'teacher'): ?>
                <td><button onclick="editAttendance('<?php echo htmlspecialchars($row['student_id']); ?>', '<?php echo htmlspecialchars($row['scan_time'] ?: ''); ?>', '<?php echo htmlspecialchars($row['status']); ?>')">Edit</button></td>
            <?php endif; ?>
        </tr>
    <?php endwhile; ?>

</table>

<?php if ($user_role === 'teacher'): ?>
    <button onclick="window.location.href='teacher_dashboard.php'">Back to Dashboard</button>
<?php else: ?>
    <button onclick="window.location.href='student_dashboard.php'">Back to Dashboard</button>
<?php endif; ?>

<script>
    function editAttendance(studentId, scanTime, status) {
        // Redirect to edit page or open form (implement as needed)
        alert(`Edit attendance for ${studentId}: ${scanTime}, ${status}`);
        // Example: window.location.href = `edit_attendance.php?student_id=${studentId}&course_id=<?php echo $course_id; ?>&date=<?php echo $selected_date; ?>`;
    }
</script>

<?php
$stmt->close();
$course_check_stmt->close();
$conn->close();
?>

</body>
</html>