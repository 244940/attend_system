<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');
require 'database_connection.php'; // ตรวจสอบเส้นทางไฟล์นี้

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

    // ตรวจสอบ student_id และดึงข้อมูลอีเมลและชื่อ (ถ้ามี)
    // *** สำคัญ: ต้องแน่ใจว่าตาราง 'students' มีคอลัมน์ 'email' และ 'name' (หรือ 'name_en') ***
    $stmt = $conn->prepare("SELECT email, name FROM students WHERE student_id = ?");
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $student_result = $stmt->get_result();
    if ($student_result->num_rows === 0) {
        throw new Exception('Student not found');
    }
    $student_info = $student_result->fetch_assoc();
    $student_email = $student_info['email'];
    $student_name = $student_info['name']; // สามารถใช้ได้ถ้าต้องการชื่อจริงในอนาคต
    $stmt->close();

    // ดึงข้อมูลวิชาจาก schedule_id
    $stmt = $conn->prepare("SELECT c.course_name, c.course_code, s.day_of_week, s.start_time, s.end_time FROM schedules s JOIN courses c ON s.course_id = c.course_id WHERE s.schedule_id = ?");
    $stmt->bind_param('i', $schedule_id);
    $stmt->execute();
    $schedule_result = $stmt->get_result();
    if ($schedule_result->num_rows === 0) {
        throw new Exception('Schedule details not found');
    }
    $schedule_info = $schedule_result->fetch_assoc();
    $course_name = $schedule_info['course_name'];
    $course_code = $schedule_info['course_code'];
    $class_day = $schedule_info['day_of_week'];
    $class_start_time = substr($schedule_info['start_time'], 0, 5); // HH:MM
    $class_end_time = substr($schedule_info['end_time'], 0, 5);     // HH:MM
    $stmt->close();

    // บันทึกข้อมูลการเข้าเรียน
    // ใช้ ON DUPLICATE KEY UPDATE ถ้าคุณต้องการให้อัปเดตข้อมูลหากมีการสแกนซ้ำ
    $stmt = $conn->prepare("INSERT INTO attendance (student_id, schedule_id, scan_time, status) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE scan_time = VALUES(scan_time), status = VALUES(status)");
    $stmt->bind_param('iiss', $student_id, $schedule_id, $scan_time, $status);
    if (!$stmt->execute()) {
        throw new Exception('Failed to save attendance: ' . $conn->error);
    }
    $stmt->close();

    // *** เริ่มส่งอีเมลผ่าน Flask API ***

    $flask_api_url = 'http://127.0.0.1:5000/api/send-email'; // ตรวจสอบ URL/Port ของ Flask App
    
    // เตรียมข้อมูลสำหรับส่งไปยัง Flask
    $email_subject = "แจ้งเตือนการเข้าเรียน: " . htmlspecialchars($course_name); // หัวข้ออีเมล
    $email_body_template = "เรียน (ชื่อนิสิต)\n\n" .
                           "ระบบได้บันทึกว่าคุณได้เข้าเรียนวิชา " . htmlspecialchars($course_name) . " (" . htmlspecialchars($course_code) . ")\n" .
                           "ในวันที่ " . (new DateTime($scan_time))->format('d/m/Y') . " เวลา " . (new DateTime($scan_time))->format('H:i') . " น.\n" .
                           "ขอบคุณที่มาเข้าเรียนตรงเวลา";

    $post_fields = json_encode([
    'to' => $student_email,
    'subject' => $email_subject,
    'body_template' => $email_body_template,
    'course_name' => $course_name,
    'student_name' => $student_name // เพิ่มตัวแปรนี้
]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $flask_api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // รับ response กลับมา
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // ตั้ง timeout 10 วินาที
    
    $flask_response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($flask_response === false) {
        error_log("cURL error when sending email to Flask: " . $curl_error);
        // ไม่ต้อง throw exception เพราะการบันทึก attendance สำเร็จแล้ว แต่อีเมลอาจจะไม่ไป
        echo json_encode(['message' => 'บันทึกการเข้าเรียนสำเร็จ แต่อีเมลแจ้งเตือนอาจมีปัญหา (cURL error)']);
    } else {
        $flask_result = json_decode($flask_response, true);
        if ($http_code >= 200 && $http_code < 300) {
            error_log("Email sent via Flask successfully: " . ($flask_result['message'] ?? 'No message'));
            echo json_encode(['message' => 'บันทึกการเข้าเรียนและส่งอีเมลสำเร็จ']);
        } else {
            error_log("Flask API error (HTTP $http_code): " . ($flask_result['error'] ?? $flask_response));
            echo json_encode(['message' => 'บันทึกการเข้าเรียนสำเร็จ แต่อีเมลแจ้งเตือนมีปัญหา: ' . ($flask_result['error'] ?? 'Unknown Flask error')]);
        }
    }

} catch (Exception $e) {
    error_log("Error in add_attendance.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    if (isset($stmt) && $stmt instanceof mysqli_stmt) $stmt->close();
    if (isset($conn) && $conn instanceof mysqli) $conn->close();
}
?>
