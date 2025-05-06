<?php
session_start();
require 'database_connection.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['teacher_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    die("Error: Please log in as a teacher.");
}

// Check if a course ID was provided
if (!isset($_GET['course_id'])) {
    die("Error: Course ID is required.");
}

$course_id = intval($_GET['course_id']);
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Validate date format
if (!DateTime::createFromFormat('Y-m-d', $selected_date)) {
    die("Error: Invalid date format.");
}

// Validate that the course belongs to the teacher
$teacher_id = $_SESSION['teacher_id'];
$course_check_query = "
    SELECT course_id 
    FROM courses 
    WHERE course_id = ? AND teacher_id = ?";
$course_check_stmt = $conn->prepare($course_check_query);
$course_check_stmt->bind_param("ii", $course_id, $teacher_id); // course_id and teacher_id are int
$course_check_stmt->execute();
$course_check_result = $course_check_stmt->get_result();
if ($course_check_result->num_rows === 0) {
    die("Error: Course not found or you are not authorized to access it.");
}
$course_check_stmt->close();

// Prepare SQL query to get course details (course code, name, and group number)
$course_query = "
    SELECT c.course_code, c.course_name, s.group_number 
    FROM courses c 
    JOIN schedules s ON c.course_id = s.course_id
    WHERE c.course_id = ?";
$course_stmt = $conn->prepare($course_query);
$course_stmt->bind_param("i", $course_id); // course_id is int
$course_stmt->execute();
$course_result = $course_stmt->get_result();

if ($course_result->num_rows === 0) {
    die("Error: Course not found.");
}

$course_row = $course_result->fetch_assoc();
$course_code = $course_row['course_code'];
$course_name = preg_replace('/[^a-zA-Z0-9_]/', '_', $course_row['course_name']); // Sanitize course name for filename
$group_number = $course_row['group_number'];

// Prepare SQL query to get attendance for the given course on the selected date
$attendance_query = "
    SELECT 
        s.student_id,
        s.student_id as user_id,  -- Map student_id to user_id as per schema
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
    LEFT JOIN attendance a ON s.student_id = a.user_id 
        AND DATE(a.scan_time) = ?
        AND a.schedule_id IN (
            SELECT schedule_id 
            FROM schedules 
            WHERE course_id = ?
        )
    WHERE e.course_id = ?
    ORDER BY s.name";

$stmt = $conn->prepare($attendance_query);
$stmt->bind_param("sii", $selected_date, $course_id, $course_id); // course_id is int
$stmt->execute();
$result = $stmt->get_result();

// Check if there are any records
if ($result->num_rows === 0) {
    die("No attendance records found for the selected course on this date.");
}

// Prepare the file for download with the specified filename format
$filename = "{$course_code}_{$course_name}_Group_{$group_number}_Attendance_Report.csv";
header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename={$filename}");

// Create a file pointer connected to the output stream
$output = fopen('php://output', 'w');

// Output column headings
fputcsv($output, ['Student ID', 'User ID', 'Student Name', 'Course Code', 'Scan Time', 'Status']);

// Fetch each record and write to the CSV file
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['student_id'], 
        $row['user_id'], 
        $row['name'], 
        $row['course_code'], 
        $row['scan_time_display'], 
        $row['status']
    ]);
}

// Close resources
fclose($output);
$stmt->close();
$course_stmt->close();
$conn->close();
exit();
?>