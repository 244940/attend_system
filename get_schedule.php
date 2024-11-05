<?php
session_start();
require 'database_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    http_response_code(403);
    exit('Unauthorized');
}

if (!isset($_GET['course_code'])) {
    http_response_code(400);
    exit('Course code required');
}

$course_code = $_GET['course_code'];
$current_day = date('l'); // Gets the current day name (Monday, Tuesday, etc.)

// Get course information and check if it's scheduled for today
$query = "
    SELECT 
        c.course_id,
        c.course_name,
        c.course_code,
        c.start_time,
        c.end_time,
        c.day_of_week,
        c.semester,
        c.c_year,
        c.group_number
    FROM courses c
    WHERE c.course_code = ?
    AND c.day_of_week = ?
    AND c.teacher_id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("ssi", $course_code, $current_day, $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['error' => 'No course scheduled for today']);
    exit();
}

$course = $result->fetch_assoc();

if (!$course['course_code']) {
    echo json_encode(['error' => 'Course code not found']);
    exit();
}

echo json_encode($course);

$stmt->close();
$conn->close();
?>
