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

    $course_id = intval($_GET['course_id']);
    $selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

    // Validate date format
    if (!DateTime::createFromFormat('Y-m-d', $selected_date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date format']);
        exit();
    }

    // Check user authorization
    if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
        http_response_code(403);
        throw new Exception('Unauthorized');
    }

    // Determine the day of the week for the selected date
    $day_of_week = date('N', strtotime($selected_date));

    // Check if this course has a schedule on this day
    $schedule_query = "
        SELECT s.schedule_id, c.course_name 
        FROM schedules s
        JOIN courses c ON s.course_id = c.course_id
        WHERE s.course_id = ? AND c.day_of_week = ?";
    
    $schedule_stmt = $conn->prepare($schedule_query);
    $schedule_stmt->bind_param("ii", $course_id, $day_of_week);
    $schedule_stmt->execute();
    $schedule_result = $schedule_stmt->get_result();

    if ($schedule_result->num_rows === 0) {
        echo json_encode([
            'error' => 'No schedule found for this course on the selected date',
            'message' => 'This course does not have a schedule on this day'
        ]);
        exit();
    }

    $schedule_row = $schedule_result->fetch_assoc();
    $schedule_id = $schedule_row['schedule_id'];

    // Main query to get attendance data
    $attendance_query = "
        SELECT 
            s.student_id,
            s.user_id,
            s.name,
            c.course_code,
            c.start_time,
            a.scan_time,
            CASE
                WHEN a.scan_time IS NOT NULL THEN
                    CASE
                        WHEN TIME(a.scan_time) <= c.start_time THEN 'มาเรียน'   -- Present
                        WHEN TIME(a.scan_time) <= ADDTIME(c.start_time, '00:15:00') THEN 'สาย'  -- Late
                        ELSE 'ขาดเรียน'  -- Absent
                    END
                ELSE 'ขาดเรียน'  -- Absent
            END as status,
            CASE
                WHEN a.scan_time IS NULL THEN 'ไม่มีการสแกน'  -- No scan
                ELSE TIME_FORMAT(a.scan_time, '%H:%i:%s')
            END as scan_time_display
        FROM enrollments e
        JOIN students s ON e.student_id = s.student_id
        JOIN courses c ON e.course_id = c.course_id
        LEFT JOIN attendance a ON s.user_id = a.user_id 
            AND DATE(a.scan_time) = ?
            AND a.schedule_id IN (
                SELECT schedule_id 
                FROM schedules 
                WHERE course_id = ?
            )
        WHERE e.course_id = ?
        ORDER BY s.name";

    $stmt = $conn->prepare($attendance_query);
    if (!$stmt) {
        throw new Exception('Failed to prepare attendance query: ' . $conn->error);
    }

    $stmt->bind_param("sii", $selected_date, $course_id, $course_id);
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
        // Collect student data and update statistics
        $student = [
            'student_id' => $row['student_id'],
            'user_id' => $row['user_id'],
            'name' => $row['name'],
            'course_code' => $row['course_code'] ?? null,
            'scan_time' => $row['scan_time_display'] ?? null,
            'status' => $row['status']
        ];

        switch ($row['status']) {
            case 'มาเรียน':
                $statistics['present']++;
                $has_logs = true;
                break;
            case 'สาย':
                $statistics['late']++;
                $has_logs = true;
                break;
            case 'ขาดเรียน':
                $statistics['absent']++;
                break;
        }

        $students[] = $student;
        $statistics['total']++;
    }

     // Check if we have any attendance logs
     if (!$has_logs) {
        echo json_encode(['message' => 'No attendance logs for this date', 'statistics' => null]);
        exit();
    }
    // Send JSON response
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
   if (isset($schedule_stmt)) { 
       $schedule_stmt->close(); 
   }
   if (isset($stmt)) { 
       $stmt->close(); 
   }
   if (isset($conn)) { 
       $conn->close(); 
   }
}
?>
