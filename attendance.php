<?php
session_start();
require 'database_connection.php'; // Include your database connection

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$course_id = $_GET['course_id'] ?? null;

if (!$course_id) {
    echo "Invalid course.";
    exit();
}

// Validate course ID
$course_check_stmt = $conn->prepare("SELECT * FROM courses WHERE course_id = ?");
$course_check_stmt->bind_param("i", $course_id);
$course_check_stmt->execute();
$course_result = $course_check_stmt->get_result();

if ($course_result->num_rows === 0) {
    echo "Course not found.";
    exit();
}

// Fetch attendance based on user role
$user_role = $_SESSION['user_role'];

if ($user_role === 'teacher') {
    // For teachers, show students' attendance for the selected course
    $stmt = $conn->prepare("SELECT students.name, attendance.date, attendance.status 
                             FROM attendance 
                             INNER JOIN students ON attendance.student_id = students.user_id 
                             WHERE attendance.course_id = ?");
} else if ($user_role === 'student') {
    // For students, show their own attendance for the selected course
    $stmt = $conn->prepare("SELECT date, status FROM attendance WHERE student_id = ? AND course_id = ?");
    $stmt->bind_param("ii", $user_id, $course_id);
} else {
    echo "Invalid user role.";
    exit();
}

if ($user_role === 'teacher') {
    $stmt->bind_param("i", $course_id);
}

$stmt->execute();
$attendance_result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance</title>
</head>
<body>

<h1>Attendance Details</h1>

<table border="1">
    <tr>
        <?php if ($user_role === 'teacher'): ?>
            <th>Student Name</th>
        <?php endif; ?>
        <th>Date</th>
        <th>Status</th>
    </tr>

<?php while ($row = $attendance_result->fetch_assoc()): ?>
    <tr>
        <?php if ($user_role === 'teacher'): ?>
            <td><?php echo htmlspecialchars($row['name']); ?></td>
        <?php endif; ?>
        <td><?php echo htmlspecialchars($row['date']); ?></td>
        <td><?php echo htmlspecialchars($row['status']); ?></td>
    </tr>
<?php endwhile; ?>

</table>

<?php
$stmt->close();
$course_check_stmt->close();
$conn->close();
?>

</body>
</html>
