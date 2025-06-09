<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');
require 'database_connection.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['student_id']) || !isset($data['schedule_id']) || !isset($data['scan_time']) || !isset($data['status'])) {
        throw new Exception('Missing required fields');
    }

    $student_id = $data['student_id'];
    $schedule_id = $data['schedule_id'];
    $scan_time = $data['scan_time'];
    $status = $data['status'];

    // ตรวจสอบว่า schedule_id ตรงกับ course_id ของ teacher
    $stmt = $conn->prepare("SELECT c.course_id FROM schedules s JOIN courses c ON s.course_id = c.course_id WHERE s.schedule_id = ? AND c.teacher_id = ?");
    $stmt->bind_param('ii', $schedule_id, $_SESSION['teacher_id']);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        throw new Exception('Unauthorized or invalid schedule');
    }
    $stmt->close();

    // ตรวจสอบ student_id
    $stmt = $conn->prepare("SELECT student_id FROM students WHERE student_id = ?");
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        throw new Exception('Student not found');
    }
    $stmt->close();

    // บันทึกข้อมูล
    $stmt = $conn->prepare("INSERT INTO attendance (student_id, schedule_id, scan_time, status) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE scan_time = VALUES(scan_time), status = VALUES(status)");
    $stmt->bind_param('iiss', $student_id, $schedule_id, $scan_time, $status);
    if (!$stmt->execute()) {
        throw new Exception('Failed to save attendance: ' . $conn->error);
    }
    $stmt->close();

    echo json_encode(['message' => 'บันทึกการเข้าเรียนสำเร็จ']);
} catch (Exception $e) {
    error_log("Error in add_attendance.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    if (isset($stmt)) $stmt->close();
    if (isset($conn)) $conn->close();
}
?>