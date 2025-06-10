<?php
session_start(); // เริ่มต้นเซสชัน (จำเป็นสำหรับการตรวจสอบสิทธิ์)
ini_set('display_errors', 1); // เปิดการแสดงข้อผิดพลาด
ini_set('display_startup_errors', 1);
error_reporting(E_ALL); // รายงานข้อผิดพลาดทั้งหมด

header('Content-Type: application/json; charset=UTF-8'); // กำหนด Header ให้เป็น JSON

require 'database_connection.php'; // เรียกไฟล์เชื่อมต่อฐานข้อมูล

try {
    // --- ตรวจสอบพารามิเตอร์ที่จำเป็น ---
    if (!isset($_GET['course_id'])) {
        throw new Exception('Missing course_id parameter'); // โยน Exception ถ้าไม่มี course_id
    }
    // หมายเหตุ: เดิมโค้ดมี 'date' แต่ใน student_dashboard.php ส่ง 'dates' (JSON array)
    // ดังนั้นโค้ดนี้ควรปรับให้รับ 'dates' และวนลูปตรวจสอบแต่ละวันที่
    // แต่จากโค้ดที่ให้มามันรับแค่ 'date' ตัวเดียว ซึ่งขัดแย้งกัน
    // ตรงนี้จะอธิบายตามโค้ดที่มีอยู่ แต่มีหมายเหตุถึงความไม่เข้ากันนี้
    if (!isset($_GET['date'])) { // <-- ตรงนี้อาจจะต้องเปลี่ยนเป็น 'dates' และ parse JSON
        throw new Exception('Missing date parameter'); // โยน Exception ถ้าไม่มี date
    }

    $course_id = intval($_GET['course_id']); // แปลง course_id เป็น integer
    $selected_date = $_GET['date']; // รับวันที่ที่เลือก

    // --- ตรวจสอบรูปแบบวันที่ ---
    if (!DateTime::createFromFormat('Y-m-d', $selected_date)) {
        http_response_code(400); // ตั้งค่า HTTP Status Code เป็น 400 Bad Request
        echo json_encode(['error' => 'Invalid date format'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // --- ตรวจสอบสิทธิ์ผู้ใช้ (เฉพาะอาจารย์) ---
    // หมายเหตุ: โค้ดนี้ถูกเขียนมาเพื่อตรวจสอบสิทธิ์ของ 'teacher' ซึ่งดูเหมือนว่าจะขัดแย้งกับการใช้งานใน student_dashboard.php
    // เพราะ student_dashboard.php ส่งคำขอมา ซึ่งควรเป็น student_id
    // หากไฟล์นี้ถูกเรียกโดยนักเรียน ควรเปลี่ยนเป็น $_SESSION['student_id'] และ 'student'
    if (!isset($_SESSION['teacher_id']) || $_SESSION['user_role'] !== 'teacher') {
        http_response_code(403); // ตั้งค่า HTTP Status Code เป็น 403 Forbidden
        throw new Exception('Unauthorized'); // โยน Exception หากไม่ได้รับอนุญาต
    }

    // --- ตรวจสอบความเป็นเจ้าของวิชาและวันเวลาเรียน ---
    $course_check = $conn->prepare("
        SELECT 
            c.course_id, 
            c.course_code,
            GROUP_CONCAT(sch.day_of_week) as days_of_week,
            GROUP_CONCAT(sch.schedule_id) as schedule_ids
        FROM courses c
        LEFT JOIN schedules sch ON c.course_id = sch.course_id
        WHERE c.course_id = ? AND c.teacher_id = ?
        GROUP BY c.course_id, c.course_code
    ");
    if (!$course_check) {
        throw new Exception('Failed to prepare course check query: ' . $conn->error);
    }
    $course_check->bind_param("ii", $course_id, $_SESSION['teacher_id']); // ผูก course_id และ teacher_id
    $course_check->execute();
    $course_result = $course_check->get_result();
    if ($course_result->num_rows === 0) {
        http_response_code(403); // ถ้าไม่พบวิชาหรืออาจารย์ไม่ใช่เจ้าของ
        throw new Exception('Course not found or not authorized');
    }
    $course_data = $course_result->fetch_assoc();
    $course_check->close();

    // ตรวจสอบว่าวันที่เลือกเป็นวันที่มีเรียนหรือไม่
    $day_of_week = date('l', strtotime($selected_date)); // ได้ชื่อวัน เช่น "Monday"
    $scheduled_days = !empty($course_data['days_of_week']) ? explode(',', $course_data['days_of_week']) : [];
    $schedule_ids = !empty($course_data['schedule_ids']) ? explode(',', $course_data['schedule_ids']) : [];
    $is_scheduled_day = in_array($day_of_week, $scheduled_days);

    // --- ดึงข้อมูลนักเรียนและบันทึกการเข้าเรียน ---
    $attendance_query = "
        SELECT 
            s.student_id,
            s.name,
            c.course_code,
            a.scan_time,
            COALESCE(a.status, 'Absent') as status, 
            CASE
                WHEN a.scan_time IS NULL THEN 'ไม่มีการสแกน'
                ELSE TIME_FORMAT(a.scan_time, '%H:%i:%s')
            END as scan_time_display
        FROM enrollments e
        JOIN students s ON e.student_id = s.student_id
        JOIN courses c ON e.course_id = c.course_id
        LEFT JOIN attendance a ON s.student_id = a.student_id 
            AND DATE(a.scan_time) = ? "; // JOIN กับ attendance แบบ LEFT JOIN
    
    // เพิ่มเงื่อนไข schedule_id หากมี
    if (!empty($schedule_ids)) {
        $attendance_query .= "AND a.schedule_id IN (" . implode(',', array_fill(0, count($schedule_ids), '?')) . ")";
    }
    
    $attendance_query .= " WHERE e.course_id = ? ORDER BY s.name";

    $stmt = $conn->prepare($attendance_query);
    if (!$stmt) {
        throw new Exception('Failed to prepare attendance query: ' . $conn->error);
    }

    // --- เตรียมพารามิเตอร์สำหรับผูก (bind) ---
    $bind_params = [$selected_date]; // พารามิเตอร์ตัวแรกคือวันที่
    $bind_types = 's'; // ประเภทของพารามิเตอร์ตัวแรกคือ string (สำหรับวันที่)

    // เพิ่ม schedule_ids เข้าไปในพารามิเตอร์และประเภท
    if (!empty($schedule_ids)) {
        $bind_types .= str_repeat('i', count($schedule_ids)); // เพิ่ม 'i' ตามจำนวน schedule_ids
        $bind_params = array_merge($bind_params, $schedule_ids); // รวม array ของพารามิเตอร์
    }
    // เพิ่ม course_id เข้าไปในพารามิเตอร์สุดท้าย
    $bind_types .= 'i'; // ประเภทของ course_id คือ integer
    $bind_params[] = $course_id;

    // ผูกพารามิเตอร์
    // ใช้ `...$bind_params` เพื่อ unpack array ให้เป็น arguments ของ bind_param
    $stmt->bind_param($bind_types, ...$bind_params); 

    if (!$stmt->execute()) {
        throw new Exception('Failed to execute attendance query: ' . $stmt->error);
    }

    $result = $stmt->get_result(); // รับผลลัพธ์

    // --- ประมวลผลผลลัพธ์ ---
    $students = [];
    $statistics = [ // สถิติการเข้าเรียน
        'present' => 0, 'late' => 0, 'absent' => 0, 'total' => 0
    ];
    $has_logs = false; // แฟล็กตรวจสอบว่ามีบันทึกการเข้าเรียนจริงๆ หรือไม่

    while ($row = $result->fetch_assoc()) {
        $student = [
            'student_id' => $row['student_id'],
            'name' => $row['name'],
            'course_code' => $row['course_code'],
            'scan_time' => $row['scan_time_display'],
            'status' => $row['status']
        ];

        // นับสถิติ
        switch (strtolower($row['status'])) {
            case 'present': $statistics['present']++; $has_logs = true; break;
            case 'late': $statistics['late']++; $has_logs = true; break;
            case 'absent': $statistics['absent']++; break;
        }

        $students[] = $student; // เพิ่มข้อมูลนักเรียนเข้า array
        $statistics['total']++; // นับจำนวนนักเรียนทั้งหมด
    }

    $stmt->close(); // ปิด prepared statement

    // --- สร้าง Response JSON ---
    $response = [
        'date' => $selected_date,
        'students' => $students,
        'statistics' => $statistics,
        'is_scheduled_day' => $is_scheduled_day // บอกว่าเป็นวันที่มีการเรียนการสอนตามตารางหรือไม่
    ];

    if (!$has_logs && $statistics['total'] === 0) {
        $response['message'] = 'No students enrolled or no attendance logs for this date';
    } elseif (!$is_scheduled_day) {
        $response['warning'] = 'This course is not scheduled on ' . $day_of_week . '. Displaying available data.';
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE); // ส่ง JSON กลับไป

} catch (Exception $e) {
    // --- การจัดการข้อผิดพลาด ---
    error_log("Error in get_attendance.php: " . htmlspecialchars($e->getMessage()));
    http_response_code(500); // ตั้งค่า HTTP Status Code เป็น 500 Internal Server Error
    echo json_encode([
        'error' => "Server error: " . htmlspecialchars($e->getMessage()),
        'file' => __FILE__, // ไฟล์ที่เกิด error
        'line' => $e->getLine() // บรรทัดที่เกิด error
    ], JSON_UNESCAPED_UNICODE);
} finally {
    // --- ปิดการเชื่อมต่อฐานข้อมูลและ Statement เสมอ ---
    if (isset($stmt)) $stmt->close();
    if (isset($course_check)) $course_check->close();
    if (isset($conn)) $conn->close();
}
?>