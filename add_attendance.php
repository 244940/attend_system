<?php
session_start(); // เริ่มต้นเซสชัน
ini_set('display_errors', 1); // เปิด error reporting เพื่อ debug
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8'); // กำหนด Header ให้เป็น JSON

require 'database_connection.php'; // ตรวจสอบเส้นทางไฟล์นี้ให้ถูกต้อง

try {
    // --- 1. ตรวจสอบพารามิเตอร์ที่จำเป็นและสิทธิ์ผู้ใช้ ---
    // ตรวจสอบว่า student_id ใน session มีอยู่และบทบาทเป็น 'student'
    if (!isset($_SESSION['student_id']) || $_SESSION['user_role'] !== 'student') {
        http_response_code(403); // Forbidden
        echo json_encode(['error' => 'Access denied: You are not logged in as a student.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // ตรวจสอบว่าได้รับ course_id และ dates จาก AJAX call
    if (!isset($_GET['course_id']) || !isset($_GET['dates'])) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'Missing required parameters (course_id or dates).'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $student_id = $_SESSION['student_id'];
    $course_id = intval($_GET['course_id']);
    
    // ถอดรหัส JSON string ของวันที่ที่ส่งมาจาก JavaScript
    $requested_dates_json = $_GET['dates'];
    $requested_dates_array = json_decode($requested_dates_json, true);

    // ตรวจสอบว่า dates ที่ส่งมาเป็น array และไม่ว่างเปล่า
    if (!is_array($requested_dates_array) || empty($requested_dates_array)) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'Invalid or empty dates array provided.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // ตรวจสอบว่านักเรียนคนนี้ลงทะเบียนวิชานี้จริงหรือไม่
    $enrollment_check_stmt = $conn->prepare("SELECT COUNT(*) FROM enrollments WHERE student_id = ? AND course_id = ?");
    $enrollment_check_stmt->bind_param("ii", $student_id, $course_id);
    $enrollment_check_stmt->execute();
    $enrollment_result = $enrollment_check_stmt->get_result()->fetch_row()[0];
    $enrollment_check_stmt->close();

    if ($enrollment_result === 0) {
        http_response_code(403); // Forbidden
        echo json_encode(['error' => 'Unauthorized: Student not enrolled in this course.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // --- 2. ดึงข้อมูลการเข้าเรียนสำหรับวันที่ที่ร้องขอ ---
    // สร้าง string สำหรับเงื่อนไข WHERE IN (...) สำหรับวันที่
    $placeholders = implode(',', array_fill(0, count($requested_dates_array), '?'));
    $types = str_repeat('s', count($requested_dates_array)); // 's' สำหรับ string (วันที่)

    $attendance_query = "
        SELECT 
            DATE(a.scan_time) AS attendance_date,
            a.status
        FROM attendance a
        JOIN schedules sch ON a.schedule_id = sch.schedule_id
        WHERE a.student_id = ? 
        AND sch.course_id = ?
        AND DATE(a.scan_time) IN ($placeholders)
        ORDER BY attendance_date ASC;
    ";

    $stmt = $conn->prepare($attendance_query);
    if (!$stmt) {
        throw new Exception('Failed to prepare attendance query: ' . $conn->error);
    }

    // ผูกพารามิเตอร์: student_id (int), course_id (int), และวันที่ทั้งหมด (string array)
    $bind_params = array_merge([$student_id, $course_id], $requested_dates_array);
    $bind_types = "ii" . $types; // 'ii' สำหรับ student_id และ course_id

    // ใช้ call_user_func_array เพื่อ bind_param ด้วย array
    $stmt->bind_param($bind_types, ...$bind_params);

    if (!$stmt->execute()) {
        throw new Exception('Failed to execute attendance query: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    
    // จัดรูปแบบข้อมูลการเข้าเรียนให้อยู่ในรูป array โดยมีวันที่เป็น key
    $attendance_data = [];
    while ($row = $result->fetch_assoc()) {
        $attendance_data[$row['attendance_date']] = $row['status'];
    }
    $stmt->close();

    // --- 3. ส่งข้อมูลกลับไปยัง Frontend ---
    // Response นี้จะส่งคืนข้อมูลการเข้าเรียนในรูปแบบ { "YYYY-MM-DD": "Present", "YYYY-MM-DD": "Absent", ... }
    echo json_encode($attendance_data, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Error in get_attendance.php: " . htmlspecialchars($e->getMessage()));
    http_response_code(500); // Internal Server Error
    echo json_encode([
        'error' => "Server error: " . htmlspecialchars($e->getMessage()),
        'file' => basename(__FILE__), // ชื่อไฟล์
        'line' => $e->getLine()       // บรรทัดที่เกิดข้อผิดพลาด
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>