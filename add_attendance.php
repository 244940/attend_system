<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

require 'database_connection.php';

try {
    if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['teacher', 'student'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied: You are not logged in.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !isset($data['student_id']) || !isset($data['schedule_id']) || !isset($data['scan_time']) || !isset($data['status'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameters'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $student_id = $conn->real_escape_string($data['student_id']);
    $schedule_id = intval($data['schedule_id']);
    $scan_time = $conn->real_escape_string($data['scan_time']);
    $status = $conn->real_escape_string($data['status']);

    $check_stmt = $conn->prepare("SELECT course_id FROM schedules WHERE schedule_id = ?");
    $check_stmt->bind_param("i", $schedule_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    if ($result->num_rows === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid schedule_id'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    $row = $result->fetch_assoc();
    $course_id = $row['course_id'];
    $check_stmt->close();

    if ($_SESSION['user_role'] === 'teacher') {
        $teacher_id = $_SESSION['teacher_id'];
        $course_check = $conn->prepare("SELECT course_id FROM courses WHERE course_id = ? AND teacher_id = ?");
        $course_check->bind_param("ii", $course_id, $teacher_id);
        $course_check->execute();
        $result = $course_check->get_result();
        if ($result->num_rows === 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied: You are not authorized for this course'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $course_check->close();
    } elseif ($_SESSION['user_role'] === 'student') {
        $student_id_session = $_SESSION['student_id'];
        if ($student_id_session !== $student_id) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied: Mismatch student ID'], JSON_UNESCAPED_UNICODE);
            exit();
        }
    }

    $enroll_check = $conn->prepare("SELECT student_id FROM enrollments WHERE course_id = ? AND student_id = ?");
    $enroll_check->bind_param("is", $course_id, $student_id);
    $enroll_check->execute();
    $result = $enroll_check->get_result();
    if ($result->num_rows === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Student is not enrolled in this course'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    $enroll_check->close();

    $insert_stmt = $conn->prepare("INSERT INTO attendance (student_id, schedule_id, scan_time, status) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = ?, scan_time = ?");
    $insert_stmt->bind_param("iissss", $student_id, $schedule_id, $scan_time, $status, $status, $scan_time);
    if ($insert_stmt->execute()) {
        // เพิ่มการล็อกเพื่อยืนยันข้อมูลที่บันทึก
        error_log("add_attendance.php: Successfully inserted/updated attendance - student_id: $student_id, schedule_id: $schedule_id, scan_time: $scan_time, status: $status");
        echo json_encode(['message' => 'Attendance recorded successfully'], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        error_log("add_attendance.php: Failed to execute insert/update: " . $conn->error);
        echo json_encode(['error' => 'Failed to record attendance: ' . $conn->error], JSON_UNESCAPED_UNICODE);
    }
    $insert_stmt->close();

} catch (Exception $e) {
    error_log("Error in add_attendance.php: " . htmlspecialchars($e->getMessage()));
    http_response_code(500);
    echo json_encode([
        'error' => "Server error: " . htmlspecialchars($e->getMessage()),
        'file' => basename(__FILE__),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>