<?php
session_start();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'C:\xampp\logs\php_error_log');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

require 'database_connection.php';

try {
    if (!isset($_GET['course_id'])) {
        echo json_encode(['error' => 'Missing course_id parameter'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    if (!isset($_GET['dates'])) {
        echo json_encode([], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $course_id = intval($_GET['course_id']);
    $dates = json_decode($_GET['dates'], true);
    if (!is_array($dates)) {
        echo json_encode([], JSON_UNESCAPED_UNICODE);
        exit();
    }

    error_log("get_attendance.php: course_id=$course_id, dates=" . json_encode($dates));
    error_log("get_attendance.php: Session data=" . print_r($_SESSION, true));

    $user_role = $_SESSION['user_role'] ?? '';
    $user_id = null;
    if ($user_role === 'student' && isset($_SESSION['student_id'])) {
        $user_id = $_SESSION['student_id'];
    } elseif ($user_role === 'teacher' && isset($_SESSION['teacher_id'])) {
        $user_id = $_SESSION['teacher_id'];
    } else {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($user_role === 'teacher') {
        $course_check = $conn->prepare("SELECT course_id FROM courses WHERE course_id = ? AND teacher_id = ?");
        $course_check->bind_param("ii", $course_id, $user_id);
    } else {
        $course_check = $conn->prepare("SELECT course_id FROM enrollments WHERE course_id = ? AND student_id = ?");
        $course_check->bind_param("ii", $course_id, $user_id);
    }

    if (!$course_check->execute()) {
        error_log("get_attendance.php: Course check execution failed: " . $course_check->error);
        throw new Exception("Course check query failed");
    }
    $result = $course_check->get_result();
    if ($result->num_rows === 0) {
        error_log("get_attendance.php: Course check failed for course_id=$course_id, user_id=$user_id");
        http_response_code(403);
        echo json_encode(['error' => 'Course not found or not authorized'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    $course_check->close();

    $attendance_data = [];
    $stmt = null;

    $schedule_query = $conn->prepare("SELECT schedule_id, start_time, end_time FROM schedules WHERE course_id = ?");
    $schedule_query->bind_param("i", $course_id);
    if (!$schedule_query->execute()) {
        error_log("get_attendance.php: Schedule query execution failed: " . $schedule_query->error);
        throw new Exception("Schedule query failed");
    }
    $schedule_result = $schedule_query->get_result();
    $schedule_ids = [];
    $schedule_times = []; // เก็บ start_time และ end_time ของแต่ละ schedule_id
    while ($row = $schedule_result->fetch_assoc()) {
        $schedule_ids[] = $row['schedule_id'];
        $schedule_times[$row['schedule_id']] = [
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time']
        ];
    }
    $schedule_query->close();
    error_log("get_attendance.php: Retrieved schedule_ids=" . json_encode($schedule_ids));

    if (empty($schedule_ids)) {
        error_log("get_attendance.php: No schedules found for course_id=$course_id");
        foreach ($dates as $date) {
            $attendance_data[$date] = [];
        }
        http_response_code(200);
        echo json_encode(['attendance' => $attendance_data, 'students' => []], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $student_query = $conn->prepare("
        SELECT DISTINCT e.student_id, COALESCE(s.name, '') AS name
        FROM enrollments e
        LEFT JOIN students s ON e.student_id = s.student_id
        WHERE e.course_id = ?
    ");
    $student_query->bind_param("i", $course_id);
    if (!$student_query->execute()) {
        error_log("get_attendance.php: Student query execution failed: " . $student_query->error);
        throw new Exception("Student query failed");
    }
    $student_result = $student_query->get_result();
    $students = [];
    while ($row = $student_result->fetch_assoc()) {
        $students[$row['student_id']] = $row['name'] ?: "Student ID {$row['student_id']}";
    }
    $student_query->close();
    error_log("get_attendance.php: students=" . json_encode(array_keys($students)));

    $current_datetime = new DateTime('now', new DateTimeZone('Asia/Bangkok')); // ใช้เวลาในโซน +07

    foreach ($dates as $date) {
        if (!DateTime::createFromFormat('Y-m-d', $date)) {
            $attendance_data[$date] = array_fill_keys(array_keys($students), 'None');
            continue;
        }

        $date_obj = DateTime::createFromFormat('Y-m-d', $date);
        $is_past_date = $date_obj < $current_datetime->setTime(0, 0, 0); // เปรียบเทียบวันที่

        $query = "
            SELECT s.student_id, a.status
            FROM students s
            JOIN enrollments e ON s.student_id = e.student_id
            LEFT JOIN (
                SELECT student_id, status, scan_time
                FROM attendance
                WHERE schedule_id IN (" . implode(',', array_fill(0, count($schedule_ids), '?')) . ")
                AND DATE(scan_time) = ?
                ORDER BY scan_time DESC
                LIMIT 1
            ) a ON s.student_id = a.student_id
            WHERE e.course_id = ?
        ";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log("get_attendance.php: Failed to prepare query for date=$date: " . $conn->error);
            $attendance_data[$date] = array_fill_keys(array_keys($students), $is_past_date ? 'Absent' : 'None');
            continue;
        }

        $bind_params = array_merge($schedule_ids, [$date], [$course_id]);
        $bind_types = str_repeat('i', count($schedule_ids)) . 'si';
        $stmt->bind_param($bind_types, ...$bind_params);

        if (!$stmt->execute()) {
            error_log("get_attendance.php: Query execution failed for date=$date: " . $stmt->error);
            $attendance_data[$date] = array_fill_keys(array_keys($students), $is_past_date ? 'Absent' : 'None');
            continue;
        }

        $result = $stmt->get_result();
        $attendance_data[$date] = array_fill_keys(array_keys($students), $is_past_date ? 'Absent' : 'None');
        while ($row = $result->fetch_assoc()) {
            if ($row['status'] !== null) {
                $attendance_data[$date][$row['student_id']] = $row['status'];
            }
        }
        $stmt->close();
        error_log("Attendance data for date $date before sending: " . json_encode($attendance_data[$date]));
    }

    $response = ['attendance' => $attendance_data, 'students' => $students];
    error_log("get_attendance.php: Sending response: " . json_encode($response));
    http_response_code(200);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();

} catch (Exception $e) {
    error_log("get_attendance.php: Error: " . htmlspecialchars($e->getMessage()) . " at " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'error' => "Server error: " . htmlspecialchars($e->getMessage()),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
} finally {
    try {
        if (isset($stmt) && $stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
        if (isset($course_check) && $course_check instanceof mysqli_stmt) {
            $course_check->close();
        }
        if (isset($schedule_query) && $schedule_query instanceof mysqli_stmt) {
            $schedule_query->close();
        }
        if (isset($student_query) && $student_query instanceof mysqli_stmt) {
            $student_query->close();
        }
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->close();
        }
    } catch (Exception $e) {
        error_log("get_attendance.php: Error in finally block: " . htmlspecialchars($e->getMessage()));
    }
}
?>