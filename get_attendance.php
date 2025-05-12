<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

require 'database_connection.php';

try {
    // Check if required parameters are set
    if (!isset($_GET['course_id'])) {
        throw new Exception('Missing course_id parameter');
    }

    if (!isset($_GET['date'])) {
        throw new Exception('Missing date parameter');
    }

    $course_id = intval($_GET['course_id']); // course_id is an int
    $selected_date = $_GET['date'];

    // Validate date format
    if (!DateTime::createFromFormat('Y-m-d', $selected_date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date format']);
        exit();
    }

    // Check user authorization
    if (!isset($_SESSION['teacher_id']) || $_SESSION['user_role'] !== 'teacher') {
        http_response_code(403);
        throw new Exception('Unauthorized');
    }

    // Verify that the course belongs to the teacher
    $course_check = $conn->prepare("
        SELECT course_id, course_code, start_time 
        FROM courses 
        WHERE course_id = ? AND teacher_id = ?
    ");
    $course_check->bind_param("ii", $course_id, $_SESSION['teacher_id']); // course_id and teacher_id are int
    $course_check->execute();
    $course_result = $course_check->get_result();
    if ($course_result->num_rows === 0) {
        http_response_code(403);
        throw new Exception('Course not found or not authorized');
    }
    $course_data = $course_result->fetch_assoc();
    $course_check->close();

    // Determine the day of the week for the selected date
    $day_of_week = date('l', strtotime($selected_date));

    // Check if this course is scheduled on this day
    $schedule_query = "
        SELECT course_id 
        FROM courses 
        WHERE course_id = ? AND day_of_week = ?";
    $schedule_stmt = $conn->prepare($schedule_query);
    $schedule_stmt->bind_param("is", $course_id, $day_of_week); // course_id is int
    $schedule_stmt->execute();
    $schedule_result = $schedule_stmt->get_result();

    if ($schedule_result->num_rows === 0) {
        echo json_encode([
            'error' => 'No schedule found for this course on the selected date',
            'message' => 'This course does not have a schedule on this day'
        ]);
        exit();
    }
    $schedule_stmt->close();

    // Fetch students enrolled in the course and their attendance
    $attendance_query = "
        SELECT 
            s.student_id,
            s.name,
            c.course_code,
            c.start_time,
            a.scan_time,
            CASE
                WHEN a.scan_time IS NOT NULL THEN
                    CASE
                        WHEN TIME(a.scan_time) <= c.start_time THEN 'Present'
                        WHEN TIME(a.scan_time) <= ADDTIME(c.start_time, '00:15:00') THEN 'Late'
                        ELSE 'Absent'
                    END
                ELSE 'Absent'
            END as status,
            CASE
                WHEN a.scan_time IS NULL THEN 'ไม่มีการสแกน'
                ELSE TIME_FORMAT(a.scan_time, '%H:%i:%s')
            END as scan_time_display
        FROM enrollments e
        JOIN students s ON e.student_id = s.student_id
        JOIN courses c ON e.course_id = c.course_id
        LEFT JOIN schedules sch ON c.course_id = sch.course_id
        LEFT JOIN attendance a ON s.student_id = a.student_id 
            AND a.schedule_id = sch.schedule_id 
            AND DATE(a.scan_time) = ?
        WHERE e.course_id = ?
        ORDER BY s.name";

    $stmt = $conn->prepare($attendance_query);
    if (!$stmt) {
        throw new Exception('Failed to prepare attendance query: ' . $conn->error);
    }

    $stmt->bind_param("si", $selected_date, $course_id); // date is string, course_id is int
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
            'course_code' => $row['course_code'] ?? null,
            'scan_time' => $row['scan_time_display'] ?? null,
            'status' => $row['status']
        ];

        switch ($row['status']) {
            case 'Present':
                $statistics['present']++;
                $has_logs = true;
                break;
            case 'Late':
                $statistics['late']++;
                $has_logs = true;
                break;
            case 'Absent':
                $statistics['absent']++;
                break;
        }

        $students[] = $student;
        $statistics['total']++;
    }

    $stmt->close();

    if (!$has_logs && $statistics['total'] === 0) {
        echo json_encode(['message' => 'No students enrolled or no attendance logs for this date', 'statistics' => $statistics]);
        exit();
    }

    echo json_encode([
        'date' => $selected_date,
        'students' => $students,
        'statistics' => $statistics
    ]);

} catch (Exception $e) {
    error_log("Error in get_attendance.php: " . htmlspecialchars($e->getMessage()));
    http_response_code(500);
    echo json_encode([
        'error' => "Server error: " . htmlspecialchars($e->getMessage()),
        'file' => __FILE__,
        'line' => $e->getLine()
    ]);
} finally {
    if (isset($stmt)) {
        $stmt->close();
    }
    if (isset($schedule_stmt)) {
        $schedule_stmt->close();
    }
    if (isset($course_check)) {
        $course_check->close();
    }
    if (isset($conn)) {
        $conn->close();
    }
}
?>