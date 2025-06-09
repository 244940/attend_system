<?php
session_start();
ini_set('display_errors', 1); // เปิด error reporting เพื่อ debug
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

require 'database_connection.php';

try {
    // Check required parameters
    if (!isset($_GET['course_id'])) {
        throw new Exception('Missing course_id parameter');
    }
    if (!isset($_GET['date'])) {
        throw new Exception('Missing date parameter');
    }

    $course_id = intval($_GET['course_id']);
    $selected_date = $_GET['date'];

    // Validate date format
    if (!DateTime::createFromFormat('Y-m-d', $selected_date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date format'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Check user authorization
    if (!isset($_SESSION['teacher_id']) || $_SESSION['user_role'] !== 'teacher') {
        http_response_code(403);
        throw new Exception('Unauthorized');
    }

    // Verify course ownership and get scheduled days
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
    $course_check->bind_param("ii", $course_id, $_SESSION['teacher_id']);
    $course_check->execute();
    $course_result = $course_check->get_result();
    if ($course_result->num_rows === 0) {
        http_response_code(403);
        throw new Exception('Course not found or not authorized');
    }
    $course_data = $course_result->fetch_assoc();
    $course_check->close();

    // Check if selected date matches any of the course's scheduled days
    $day_of_week = date('l', strtotime($selected_date));
    $scheduled_days = !empty($course_data['days_of_week']) ? explode(',', $course_data['days_of_week']) : [];
    $schedule_ids = !empty($course_data['schedule_ids']) ? explode(',', $course_data['schedule_ids']) : [];
    $is_scheduled_day = in_array($day_of_week, $scheduled_days);

    // Fetch students and attendance
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
            AND DATE(a.scan_time) = ?
            " . (!empty($schedule_ids) ? "AND a.schedule_id IN (" . implode(',', array_fill(0, count($schedule_ids), '?')) . ")" : "") . "
        WHERE e.course_id = ?
        ORDER BY s.name";

    $stmt = $conn->prepare($attendance_query);
    if (!$stmt) {
        throw new Exception('Failed to prepare attendance query: ' . $conn->error);
    }

    // Prepare parameters for binding
    $bind_params = [$selected_date, $course_id];
    $bind_types = 'si';
    if (!empty($schedule_ids)) {
        $bind_types .= str_repeat('i', count($schedule_ids));
        $bind_params = array_merge($bind_params, $schedule_ids);
    }

    $stmt->bind_param($bind_types, ...$bind_params);
    if (!$stmt->execute()) {
        throw new Exception('Failed to execute attendance query: ' . $stmt->error);
    }

    $result = $stmt->get_result();

    // Process results
    $students = [];
    $statistics = [
        'present' => 0,
        'late' => 0,
        'absent' => 0,
        'total' => 0
    ];

    $has_logs = false;
    while ($row = $result->fetch_assoc()) {
        $student = [
            'student_id' => $row['student_id'],
            'name' => $row['name'],
            'course_code' => $row['course_code'],
            'scan_time' => $row['scan_time_display'],
            'status' => $row['status']
        ];

        switch (strtolower($row['status'])) {
            case 'present':
                $statistics['present']++;
                $has_logs = true;
                break;
            case 'late':
                $statistics['late']++;
                $has_logs = true;
                break;
            case 'absent':
                $statistics['absent']++;
                break;
        }

        $students[] = $student;
        $statistics['total']++;
    }

    $stmt->close();

    $response = [
        'date' => $selected_date,
        'students' => $students,
        'statistics' => $statistics,
        'is_scheduled_day' => $is_scheduled_day
    ];

    if (!$has_logs && $statistics['total'] === 0) {
        $response['message'] = 'No students enrolled or no attendance logs for this date';
    } elseif (!$is_scheduled_day) {
        $response['warning'] = 'This course is not scheduled on ' . $day_of_week . '. Displaying available data.';
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Error in get_attendance.php: " . htmlspecialchars($e->getMessage()));
    http_response_code(500);
    echo json_encode([
        'error' => "Server error: " . htmlspecialchars($e->getMessage()),
        'file' => __FILE__,
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($stmt)) $stmt->close();
    if (isset($course_check)) $course_check->close();
    if (isset($conn)) $conn->close();
}
?>