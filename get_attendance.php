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

    // ตรวจสอบความเป็นเจ้าของวิชา
    if ($user_role === 'teacher') {
        $course_check = $conn->prepare("
            SELECT course_id
            FROM courses
            WHERE course_id = ? AND teacher_id = ?
        ");
        $course_check->bind_param("ii", $course_id, $user_id);
    } else {
        $course_check = $conn->prepare("
            SELECT course_id
            FROM enrollments
            WHERE course_id = ? AND student_id = ?
        ");
        $course_check->bind_param("ii", $course_id, $user_id);
    }

    $course_check->execute();
    $result = $course_check->get_result();
    if ($result->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Course not found or not authorized'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    $course_check->close();

    $attendance_data = [];
    $stmt = null;
    foreach ($dates as $date) {
        if (!DateTime::createFromFormat('Y-m-d', $date)) {
            $attendance_data[$date] = 'None';
            continue;
        }

        $query = "
            SELECT COALESCE(a.status, 'Absent') as status
            FROM enrollments e
            JOIN students s ON e.student_id = s.student_id
            LEFT JOIN attendance a ON s.student_id =a.student_id 
                AND DATE(a.scan_time) = ? 
                AND a.schedule_id IN (
                    SELECT schedule_id 
                    FROM schedules 
                    WHERE course_id = ?
                )
            WHERE e.course_id = ? AND s.student_id = ?
        ";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log("Failed to prepare query: " . $conn->error);
            $attendance_data[$date] = 'None';
            continue;
        }
        $stmt->bind_param("siii", $date, $course_id, $course_id, $user_id);
        if (!$stmt->execute()) {
            error_log("Query execution failed: " . $stmt->error);
            $attendance_data[$date] = 'None';
            continue;
        }
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $attendance_data[$date] = $row ? $row['status'] : 'None';
    }

    // ส่ง response ด้วย HTTP 200 หากสำเร็จ
    http_response_code(200);
    echo json_encode($attendance_data, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Error in get_attendance.php: " . htmlspecialchars($e->getMessage()) . " at " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'error' => "Server error: " . htmlspecialchars($e->getMessage()),
        'file' => __FILE__,
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($stmt) && $stmt instanceof mysqli_stmt && !$stmt->error) {
        $stmt->close();
    }
    if (isset($course_check) && $course_check instanceof mysqli_stmt && !$course_check->error) {
        $course_check->close();
    }
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>