<?php
session_start();
require 'database_connection.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if (!isset($_GET['course_id']) || !isset($_SESSION['teacher_id']) || $_SESSION['user_role'] !== 'teacher') {
        echo json_encode(['error' => 'Unauthorized or missing course_id']);
        exit();
    }

    $course_id = intval($_GET['course_id']);
    $teacher_id = $_SESSION['teacher_id'];

    // ตรวจสอบความเป็นเจ้าของวิชา
    $course_check = $conn->prepare("SELECT course_id FROM courses WHERE course_id = ? AND teacher_id = ?");
    $course_check->bind_param("ii", $course_id, $teacher_id);
    $course_check->execute();
    $result = $course_check->get_result();
    if ($result->num_rows === 0) {
        echo json_encode(['error' => 'Course not found or not authorized']);
        exit();
    }
    $course_check->close();

    // ดึงรายชื่อนักเรียนที่ลงทะเบียน
    $stmt = $conn->prepare("
        SELECT e.student_id, COALESCE(s.name, '') AS name
        FROM enrollments e
        LEFT JOIN students s ON e.student_id = s.student_id
        WHERE e.course_id = ?
    ");
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[$row['student_id']] = $row['name'] ?: "Student ID {$row['student_id']}";
    }
    $stmt->close();

    echo json_encode(['students' => $students]);
} catch (Exception $e) {
    error_log("get_enrolled_students.php: Error: " . $e->getMessage());
    echo json_encode(['error' => 'Server error']);
} finally {
    $conn->close();
}
?>