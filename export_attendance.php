<?php
session_start();
require 'database_connection.php';

// ตรวจสอบสิทธิ์ครู
if (!isset($_SESSION['teacher_id']) || $_SESSION['user_role'] !== 'teacher') {
    die("Error: Please log in as a teacher.");
}

// ตรวจสอบว่า course_id ถูกส่งมาหรือไม่
if (!isset($_GET['course_id'])) {
    die("Error: Course ID is required.");
}

$course_id = intval($_GET['course_id']);
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// ตรวจสอบรูปแบบวันที่
if (!DateTime::createFromFormat('Y-m-d', $selected_date)) {
    die("Error: Invalid date format.");
}

// ตรวจสอบว่าเป็นเจ้าของวิชาหรือไม่
$teacher_id = $_SESSION['teacher_id'];
$check = $conn->prepare("SELECT course_id FROM courses WHERE course_id = ? AND teacher_id = ?");
$check->bind_param("ii", $course_id, $teacher_id);
$check->execute();
$result_check = $check->get_result();
if ($result_check->num_rows === 0) {
    die("Error: Course not found or unauthorized.");
}
$check->close();

// ดึงข้อมูลชื่อวิชา กลุ่ม รหัส
$course_info = $conn->prepare("
    SELECT c.course_code, c.course_name, s.group_number
    FROM courses c
    JOIN schedules s ON c.course_id = s.course_id
    WHERE c.course_id = ?
    LIMIT 1
");
$course_info->bind_param("i", $course_id);
$course_info->execute();
$info_result = $course_info->get_result();
if ($info_result->num_rows === 0) {
    die("Error: Course not found.");
}
$info = $info_result->fetch_assoc();
$course_code   = $info['course_code'];
$course_name   = preg_replace('/[^a-zA-Z0-9ก-๙_]/u', '_', $info['course_name']); // ทำชื่อไฟล์ให้ปลอดภัย
$group_number  = $info['group_number'];
$course_info->close();

// ดึงข้อมูลการเข้าเรียน
$attendance_query = "
    SELECT 
        s.student_id,
        s.name,
        sch.start_time,
        a.scan_time,
        CASE
            WHEN a.scan_time IS NOT NULL THEN
                CASE
                    WHEN TIME(a.scan_time) <= sch.start_time THEN 'มาเรียน'
                    WHEN TIME(a.scan_time) <= ADDTIME(sch.start_time, '00:15:00') THEN 'สาย'
                    ELSE 'ขาดเรียน'
                END
            ELSE 'ขาดเรียน'
        END AS status,
        TIME_FORMAT(a.scan_time, '%H:%i:%s') AS scan_time_display
    FROM enrollments e
    JOIN students s ON e.student_id = s.student_id
    JOIN schedules sch ON e.course_id = sch.course_id
    LEFT JOIN attendance a 
        ON s.student_id = a.user_id
        AND DATE(a.scan_time) = ?
        AND a.schedule_id = sch.schedule_id
    WHERE e.course_id = ?
    ORDER BY s.name
";

$stmt = $conn->prepare($attendance_query);
$stmt->bind_param("si", $selected_date, $course_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("ไม่พบข้อมูลการเข้าเรียนในวันดังกล่าว");
}

// สร้างชื่อไฟล์ CSV
$filename = "{$course_code}_{$course_name}_Group_{$group_number}_{$selected_date}.csv";
header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename=\"$filename\"");

// เปิดไฟล์ output stream
$output = fopen('php://output', 'w');

// เพิ่ม BOM เพื่อให้เปิดใน Excel ภาษาไทยไม่เพี้ยน
fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// เขียนหัวตาราง
fputcsv($output, ['รหัสนิสิต', 'ชื่อ-สกุล', 'เวลาเข้าเรียน', 'สถานะ']);

// เขียนข้อมูลแต่ละแถว
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['student_id'],
        $row['name'],
        $row['scan_time_display'] ?: 'ไม่มีการสแกน',
        $row['status']
    ]);
}

// ปิด connection และไฟล์
fclose($output);
$stmt->close();
$conn->close();
exit();
?>
