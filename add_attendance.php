<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');
require 'database_connection.php';

try {
    // Check session
    if (!isset($_SESSION['teacher_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
        http_response_code(403);
        throw new Exception('Unauthorized: Please log in as a teacher');
    }

    // Parse JSON input
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['student_id']) || !isset($data['schedule_id']) || !isset($data['scan_time']) || !isset($data['status'])) {
        throw new Exception('Missing required fields');
    }

    $student_id = $data['student_id'];
    $schedule_id = $data['schedule_id'];
    $scan_time = $data['scan_time'];
    $status = $data['status'];

    // Validate schedule_id and teacher authorization
    $stmt = $conn->prepare("
        SELECT c.course_id, c.course_name, c.course_code, s.day_of_week, s.start_time, s.end_time 
        FROM schedules s 
        JOIN courses c ON s.course_id = c.course_id 
        WHERE s.schedule_id = ? AND c.teacher_id = ?
    ");
    if (!$stmt) {
        throw new Exception('Failed to prepare schedule check query: ' . $conn->error);
    }
    $stmt->bind_param('ii', $schedule_id, $_SESSION['teacher_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        throw new Exception('Unauthorized or invalid schedule');
    }
    $schedule_info = $result->fetch_assoc();
    $course_name = $schedule_info['course_name'];
    $course_code = $schedule_info['course_code'];
    $class_day = getDayNameThai($schedule_info['day_of_week']);
    $class_start_time = substr($schedule_info['start_time'], 0, 5);
    $class_end_time = substr($schedule_info['end_time'], 0, 5);
    $stmt->close();

    // Validate student_id
    $stmt = $conn->prepare("SELECT email, name FROM students WHERE student_id = ?");
    if (!$stmt) {
        throw new Exception('Failed to prepare student check query: ' . $conn->error);
    }
    $stmt->bind_param('s', $student_id);
    $stmt->execute();
    $student_result = $stmt->get_result();
    if ($student_result->num_rows === 0) {
        throw new Exception('Student not found');
    }
    $student_info = $student_result->fetch_assoc();
    $student_email = $student_info['email'];
    $student_name = $student_info['name'];
    $stmt->close();

    // Insert or update attendance
    $stmt = $conn->prepare("
        INSERT INTO attendance (student_id, schedule_id, scan_time, status) 
        VALUES (?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE scan_time = VALUES(scan_time), status = VALUES(status)
    ");
    if (!$stmt) {
        throw new Exception('Failed to prepare attendance insert query: ' . $conn->error);
    }
    $stmt->bind_param('siss', $student_id, $schedule_id, $scan_time, $status);
    if (!$stmt->execute()) {
        throw new Exception('Failed to save attendance: ' . $stmt->error);
    }
    $stmt->close();

    // Send email via Flask API
    $flask_api_url = 'http://127.0.0.1:5000/api/send-email';
    $email_subject = "แจ้งเตือนการเข้าเรียน: " . htmlspecialchars($course_name);
    $status_thai = translateStatus($status);
    $email_body_template = "เรียน $student_name\n\n" .
                           "ระบบได้บันทึกว่าคุณได้เข้าเรียนวิชา " . htmlspecialchars($course_name) . " (" . htmlspecialchars($course_code) . ")\n" .
                           "วันที่: " . (new DateTime($scan_time))->format('d/m/Y') . "\n" .
                           "เวลา: " . (new DateTime($scan_time))->format('H:i') . " น.\n" .
                           "สถานะ: $status_thai\n" .
                           "ตารางเรียน: $class_day $class_start_time-$class_end_time";

    $post_fields = json_encode([
        'to' => $student_email,
        'subject' => $email_subject,
        'body_template' => $email_body_template,
        'course_name' => $course_name
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $flask_api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $flask_response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($flask_response === false) {
        error_log("cURL error when sending attendance email: $curl_error");
        echo json_encode(['message' => 'บันทึกการเข้าเรียนสำเร็จ แต่อีเมลแจ้งเตือนอาจมีปัญหา (cURL error)']);
    } else {
        $flask_result = json_decode($flask_response, true);
        if ($http_code >= 200 && $http_code < 300) {
            error_log("Attendance email sent via Flask: " . ($flask_result['message'] ?? 'No message'));
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

function getDayNameThai($day) {
    $dayMapping = [
        'Monday' => 'วันจันทร์',
        'Tuesday' => 'วันอังคาร',
        'Wednesday' => 'วันพุธ',
        'Thursday' => 'วันพฤหัสบดี',
        'Friday' => 'วันศุกร์',
        'Saturday' => 'วันเสาร์',
        'Sunday' => 'วันอาทิตย์'
    ];
    return $dayMapping[$day] ?? $day;
}

function translateStatus($status) {
    $statusMap = [
        'present' => 'มาเรียน',
        'late' => 'สาย',
        'absent' => 'ขาดเรียน'
    ];
    return $statusMap[strtolower($status)] ?? $status;
}
?>