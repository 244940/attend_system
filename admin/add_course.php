<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'database_connection.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

// Authentication check (admin only)
if (!isset($_SESSION['admin_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Redirecting to login: Session data: " . print_r($_SESSION, true));
    header("Location: /attend_system/login.php");
    exit();
}

function format_time($time) {
    if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
        return $time . ":00";
    }
    if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $time)) {
        return $time;
    }
    return false;
}

function validate_semester($semester) {
    $valid_semesters = ['first', 'second', 'summer'];
    return in_array(strtolower($semester), $valid_semesters);
}

function validate_year($year) {
    return is_numeric($year) && strlen($year) === 4 && $year >= 1901 && $year <= 2155;
}

function validate_year_code($year_code) {
    return is_numeric($year_code) && strlen($year_code) === 2 && $year_code >= 00 && $year_code <= 99;
}

function insert_course($conn, $course_data, $schedules) {
    $course_name = isset($course_data['course_name']) ? trim($course_data['course_name']) : '';
    $name_en = isset($course_data['name_en']) ? trim($course_data['name_en']) : '';
    $course_code = isset($course_data['course_code']) ? trim($course_data['course_code']) : '';
    $teacher_name = isset($course_data['teacher_name']) ? trim($course_data['teacher_name']) : '';
    $group_number = isset($course_data['group_number']) ? trim($course_data['group_number']) : '';
    $semester = isset($course_data['semester']) ? strtolower(trim($course_data['semester'])) : '';
    $c_year = isset($course_data['c_year']) ? trim($course_data['c_year']) : '';
    $year_code = isset($course_data['year_code']) ? trim($course_data['year_code']) : '';

    // Validate course data
    if (empty($course_name) || empty($name_en) || empty($course_code) || empty($teacher_name) ||
        empty($group_number) || empty($semester) || empty($c_year) || empty($year_code)) {
        return "Incomplete course data for course: " . ($course_code ?: 'Unknown code');
    }

    if (!validate_semester($semester)) {
        return "Invalid semester for course: $course_code. Must be 'first', 'second', or 'summer'.";
    }

    if (!validate_year($c_year)) {
        return "Invalid year for course: $course_code. Must be between 1901 and 2155.";
    }

    if (!validate_year_code($year_code)) {
        return "Invalid year code for course: $course_code. Must be a 2-digit number (00-99).";
    }

    // Validate schedules
    $valid_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    foreach ($schedules as $index => $schedule) {
        $day_of_week = isset($schedule['day_of_week']) ? trim($schedule['day_of_week']) : '';
        $start_time = isset($schedule['start_time']) ? format_time(trim($schedule['start_time'])) : false;
        $end_time = isset($schedule['end_time']) ? format_time(trim($schedule['end_time'])) : false;

        if (empty($day_of_week) || !$start_time || !$end_time) {
            return "Incomplete schedule data at index $index for course: $course_code";
        }

        if (!in_array($day_of_week, $valid_days)) {
            return "Invalid day of the week at index $index for course: $course_code. Allowed values: " . implode(', ', $valid_days);
        }

        // Check if start_time equals end_time
        if ($start_time === $end_time) {
            return "Invalid schedule for course $course_code: start_time equals end_time ($start_time) on $day_of_week.";
        }
    }

    // Look up teacher_id
    $teacher_id = null;
    $teacher_stmt = $conn->prepare("SELECT teacher_id FROM teachers WHERE name = ?");
    $teacher_stmt->bind_param("s", $teacher_name);
    $teacher_stmt->execute();
    $teacher_result = $teacher_stmt->get_result();
    if ($teacher_result->num_rows > 0) {
        $teacher_id = $teacher_result->fetch_assoc()['teacher_id'];
        if (!is_numeric($teacher_id) || $teacher_id <= 0) {
            $teacher_stmt->close();
            return "Invalid teacher_id for teacher: $teacher_name";
        }
    } else {
        $teacher_stmt->close();
        return "Teacher not found: $teacher_name";
    }
    $teacher_stmt->close();

    // Check for duplicate course
    $check_stmt = $conn->prepare(
        "SELECT course_id FROM courses 
         WHERE course_code = ? AND semester = ? AND c_year = ? AND year_code = ? AND group_number = ? AND teacher_name = ?"
    );
    $check_stmt->bind_param("ssssss", $course_code, $semester, $c_year, $year_code, $group_number, $teacher_name);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $check_stmt->close();
        return "SKIP: Course already exists with identical attributes.";
    }
    $check_stmt->close();

    // Check for time conflicts
    foreach ($schedules as $index => $schedule) {
        $day_of_week = $schedule['day_of_week'];
        $start_time = format_time($schedule['start_time']);
        $end_time = format_time($schedule['end_time']);

        $conflict_stmt = $conn->prepare(
            "SELECT c.course_code, c.group_number 
             FROM schedules s 
             JOIN courses c ON s.course_id = c.course_id 
             WHERE s.teacher_id = ? 
             AND s.day_of_week = ? 
             AND c.semester = ? 
             AND c.c_year = ? 
             AND c.year_code = ?
             AND (
                 (? BETWEEN s.start_time AND s.end_time) 
                 OR (? BETWEEN s.start_time AND s.end_time)
                 OR (s.start_time BETWEEN ? AND ?)
             )"
        );
        $conflict_stmt->bind_param("issssssss", $teacher_id, $day_of_week, $semester, $c_year, $year_code, 
            $start_time, $end_time, $start_time, $end_time);
        $conflict_stmt->execute();
        $conflict_result = $conflict_stmt->get_result();

        if ($conflict_result->num_rows > 0) {
            $conflict = $conflict_result->fetch_assoc();
            $conflict_stmt->close();
            return "Time conflict detected for teacher $teacher_name with course {$conflict['course_code']} group {$conflict['group_number']} on $day_of_week.";
        }
        $conflict_stmt->close();
    }

    // Insert course
    $course_stmt = $conn->prepare(
        "INSERT INTO courses 
         (course_name, name_en, course_code, teacher_id, teacher_name, group_number, semester, c_year, year_code) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $course_stmt->bind_param("sssisssss", 
        $course_name, $name_en, $course_code, $teacher_id, $teacher_name, $group_number, $semester, $c_year, $year_code);

    if (!$course_stmt->execute()) {
        $error = "Error adding course $course_code: " . $course_stmt->error;
        $course_stmt->close();
        return $error;
    }

    $course_id = $conn->insert_id;
    $course_stmt->close();

    // Insert schedules
    $schedule_stmt = $conn->prepare(
        "INSERT INTO schedules (course_id, teacher_id, day_of_week, start_time, end_time) 
         VALUES (?, ?, ?, ?, ?)"
    );
    foreach ($schedules as $schedule) {
        $day_of_week = $schedule['day_of_week'];
        $start_time = format_time($schedule['start_time']);
        $end_time = format_time($schedule['end_time']);
        $schedule_stmt->bind_param("iisss", $course_id, $teacher_id, $day_of_week, $start_time, $end_time);

        if (!$schedule_stmt->execute()) {
            $error = "Error adding schedule for course $course_code: " . $schedule_stmt->error;
            $schedule_stmt->close();
            return $error;
        }
    }
    $schedule_stmt->close();

    return null;
}

// Handle download template
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download_template'])) {
    $format = $_GET['download_template'];
    $columns = ['course_name', 'name_en', 'course_code', 'teacher_name', 'group_number', 'semester', 'c_year', 'year_code', 'day_of_week', 'start_time', 'end_time'];
    
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="course_template.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, $columns);
        
        fclose($output);
        exit();
    } elseif ($format === 'xlsx') {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Set headers
        foreach ($columns as $index => $column) {
            $sheet->setCellValue(chr(65 + $index) . '1', $column);
        }
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="course_template.xlsx"');
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        exit();
    }
}
// Handle single course addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_single_course'])) {
    $schedules = [];
    if (isset($_POST['schedules']) && is_array($_POST['schedules'])) {
        foreach ($_POST['schedules'] as $schedule) {
            if (!empty($schedule['day_of_week']) && !empty($schedule['start_time']) && !empty($schedule['end_time'])) {
                $schedules[] = [
                    'day_of_week' => $schedule['day_of_week'],
                    'start_time' => $schedule['start_time'],
                    'end_time' => $schedule['end_time']
                ];
            }
        }
    }

    if (empty($schedules)) {
        $_SESSION['error_message'] = "At least one schedule is required.";
        header("Location: /attend_system/admin/add_course.php");
        exit();
    }

    $course_data = [
        'course_name' => $_POST['course_name'],
        'course_name_en' => $_POST['course_name_en'],
        'course_code' => $_POST['course_code'],
        'teacher_name' => $_POST['teacher_name'],
        'group_number' => $_POST['group_number'],
        'semester' => $_POST['semester'],
        'c_year' => $_POST['c_year'],
        'year_code' => $_POST['year_code']
    ];

    $result = insert_course($conn, $course_data, $schedules);
    if ($result === null) {
        $_SESSION['success_message'] = "Course {$_POST['course_code']} added successfully with " . count($schedules) . " schedule(s).";
    } else if (strpos($result, 'SKIP:') === 0) {
        $_SESSION['success_message'] = $result;
    } else {
        $_SESSION['error_message'] = $result;
    }
    header("Location: /attend_system/admin/add_course.php");
    exit();
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_file']) && isset($_FILES['course_file'])) {
    $file = $_FILES['course_file'];
    $file_type = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($file_type != "csv" && $file_type != "xlsx") {
        $_SESSION['error_message'] = "Only CSV and XLSX files are allowed.";
    } else {
        try {
            $rows = [];
            if ($file_type == "csv") {
                $rows = array_map(function($line) {
                    return str_getcsv($line, ',', '"', '\\');
                }, file($file['tmp_name']));
                if (!empty($rows[0])) {
                    $rows[0][0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $rows[0][0]);
                }
            } else {
                if (class_exists('ZipArchive')) {
                    $reader = IOFactory::createReader('Xlsx');
                    $spreadsheet = $reader->load($file['tmp_name']);
                    $worksheet = $spreadsheet->getActiveSheet();
                    $rows = $worksheet->toArray();
                } else {
                    $_SESSION['error_message'] = "XLSX processing requires the ZIP extension. Please use CSV format instead.";
                    header("Location: /attend_system/admin/add_course.php");
                    exit();
                }
            }

            if (!empty($rows)) {
                $header = array_shift($rows);
                $header = array_map(function($h) {
                    return trim(strtolower(str_replace(' ', '_', $h)));
                }, $header);
                $header = array_map(function($h) {
                    return $h === 'year' ? 'c_year' : ($h === 'course_name_en' ? 'name_en' : $h);
                }, $header);

                $required_columns = ['course_name', 'name_en', 'course_code', 'teacher_name', 'group_number', 
                                    'semester', 'c_year', 'year_code', 'day_of_week', 'start_time', 'end_time'];
                $missing_columns = array_diff($required_columns, $header);
                if (!empty($missing_columns)) {
                    $_SESSION['error_message'] = "Missing required columns: " . implode(', ', $missing_columns);
                } else {
                    $success_count = 0;
                    $skip_count = 0;
                    $errors = [];
                    $current_course = null;
                    $schedules = [];
                    $last_course_data = null;

                    foreach ($rows as $row_index => $row) {
                        if (empty(array_filter($row))) {
                            continue;
                        }

                        if (count($row) !== count($header)) {
                            $errors[] = "Row " . ($row_index + 2) . " has incorrect number of columns";
                            continue;
                        }

                        $course_data = array_combine($header, $row);

                        // Validate teacher_name
                        $teacher_name = trim($course_data['teacher_name']);
                        $teacher_check = $conn->prepare("SELECT COUNT(*) as count FROM teachers WHERE name = ?");
                        $teacher_check->bind_param("s", $teacher_name);
                        $teacher_check->execute();
                        $teacher_count = $teacher_check->get_result()->fetch_assoc()['count'];
                        $teacher_check->close();

                        if ($teacher_count == 0) {
                            $errors[] = "Row " . ($row_index + 2) . ": Teacher not found: $teacher_name";
                            continue;
                        }

                        // Check if this row belongs to the same course
                        $course_key = "{$course_data['course_code']}-{$course_data['semester']}-{$course_data['c_year']}-{$course_data['year_code']}-{$course_data['group_number']}-{$course_data['teacher_name']}";
                        if ($current_course !== $course_key) {
                            if (!empty($schedules) && $last_course_data) {
                                $result = insert_course($conn, $last_course_data, $schedules);
                                if ($result === null) {
                                    $success_count++;
                                } else if (strpos($result, 'SKIP:') === 0) {
                                    $skip_count++;
                                } else {
                                    $errors[] = "Row " . ($row_index + 1) . ": " . $result;
                                }
                                $schedules = [];
                            }
                            $current_course = $course_key;
                            $last_course_data = $course_data;
                        }

                        $schedules[] = [
                            'day_of_week' => $course_data['day_of_week'],
                            'start_time' => $course_data['start_time'],
                            'end_time' => $course_data['end_time']
                        ];
                    }

                    // Insert the last course
                    if (!empty($schedules) && $last_course_data) {
                        $result = insert_course($conn, $last_course_data, $schedules);
                        if ($result === null) {
                            $success_count++;
                        } else if (strpos($result, 'SKIP:') === 0) {
                            $skip_count++;
                        } else {
                            $errors[] = "Row " . ($row_index + 2) . ": " . $result;
                        }
                    }

                    $_SESSION['success_message'] = "Successfully added $success_count courses. Skipped $skip_count duplicate courses.";
                    if (!empty($errors)) {
                        $_SESSION['error_message'] = "Errors occurred:\n" . implode("\n", $errors);
                    }
                }
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error processing file: " . $e->getMessage();
        }
    }
    header("Location: /attend_system/admin/add_course.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Course</title>
    <link rel="stylesheet" href="/attend_system/admin/admin-styles.css">
    <style>
        body, html {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
            height: 100%;
            display: flex;
            flex-direction: column;
            background-image: url('/attend_system/admin/assets/bb.jpg');
            background-size: cover;
            background-position: center;
        }

        .top-bar {
            width: 100%;
            background-color: #2980b9;
            color: white;
            padding: 15px 20px;
            text-align: left;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .top-bar h1 {
            margin: 0;
            font-size: 24px;
        }

        .admin-container {
            display: flex;
            flex: 1;
            width: 100%;
            height: calc(100vh - 70px);
            background: rgba(255, 255, 255, 0.9);
        }

        .sidebar {
            width: 250px;
            background-color: #2c3e50;
            color: white;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            height: 100%;
        }

        .sidebar ul {
            list-style-type: none;
            padding: 0;
            width: 100%;
        }

        .sidebar ul li {
            margin: 15px 0;
            text-align: center;
        }

        .sidebar ul li a {
            color: white;
            text-decoration: none;
            display: block;
            padding: 10px;
            transition: background 0.3s;
        }

        .sidebar ul li a:hover {
            background-color: #34495e;
            border-radius: 5px;
        }

        .main-content {
            flex: 1;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            background-color: #ecf0f1;
            height: 100%;
            overflow-y: auto;
        }

        .form-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 800px;
            margin: 20px auto;
        }

        .form-container h2 {
            margin-top: 0;
            color: #2980b9;
            font-size: 1.5em;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            color: #4b5563;
            font-weight: 500;
        }

        input, select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 5px;
            font-size: 1em;
            outline: none;
            transition: border-color 0.2s ease;
        }

        input:focus, select:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }

        button {
            background-color: #3b82f6;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
            transition: background-color 0.2s ease;
        }

        button:hover {
            background-color: #2563eb;
        }

        .file-upload {
            border: 2px dashed #d1d5db;
            padding: 20px;
            text-align: center;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .file-info {
            background-color: #f3f4f6;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
            font-size: 0.9em;
        }

        .download-template {
            background-color: #10b981;
            margin: 10px;
        }

        .download-template:hover {
            background-color: #059669;
        }

        .back-button {
            background-color: #6b7280;
            margin-top: 20px;
        }

        .back-button:hover {
            background-color: #4b5563;
        }

        .message {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 4px;
            width: 100%;
            text-align: center;
        }

        .success {
            background-color: #d4edda;
            color: #155724;
        }

        .error {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px 15px;
            border: 1px solid #721c24;
            border-radius: 4px;
        }

        .schedule-group {
            border: 1px solid #d1d5db;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 5px;
            position: relative;
            background-color: #f9fafb;
        }

        .remove-schedule {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: #ef4444;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9em;
        }

        .remove-schedule:hover {
            background-color: #dc2626;
        }

        .add-schedule {
            background-color: #10b981;
            margin-bottom: 20px;
        }

        .add-schedule:hover {
            background-color: #059669;
        }

        footer {
            text-align: center;
            padding: 10px;
            background-color: #34495e;
            color: white;
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <h1>Add New Course</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
        </div>
    </div>

    <div class="admin-container">
        <aside class="sidebar">
            <ul>
                <li><a href="/attend_system/admin/admin_dashboard.php">Dashboard</a></li>
                <li><a href="/attend_system/admin/manage_users.php">Manage Users</a></li>
                <li><a href="/attend_system/admin/add_users.php">Add User</a></li>
                <li><a href="/attend_system/admin/add_course.php">Add Course</a></li>
                <li><a href="/attend_system/admin/enroll_student.php">Enroll Student</a></li>
                <li><a href="/attend_system/admin/logout.php">Logout</a></li>
            </ul>
        </aside>

        <div class="main-content">
            <section class="dashboard-section" id="add-course">
                <div class="form-container">
                    <h2>Add Single Course</h2>

                    <?php
                    if (isset($_SESSION['success_message'])) {
                        echo '<div class="message success">' . htmlspecialchars($_SESSION['success_message']) . '</div>';
                        unset($_SESSION['success_message']);
                    }
                    if (isset($_SESSION['error_message'])) {
                        echo '<div class="message error">' . htmlspecialchars($_SESSION['error_message']) . '</div>';
                        unset($_SESSION['error_message']);
                    }
                    ?>

                    <form action="/attend_system/admin/add_course.php" method="POST">
                        <div class="form-group">
                            <label for="course_name">Course Name (Thai)</label>
                            <input type="text" id="course_name" name="course_name" required>
                        </div>

                        <div class="form-group">
                            <label for="course_name_en">Course Name (English)</label>
                            <input type="text" id="course_name_en" name="course_name_en" required>
                        </div>

                        <div class="form-group">
                            <label for="course_code">Course Code</label>
                            <input type="text" id="course_code" name="course_code" required>
                        </div>

                        <div class="form-group">
                            <label for="semester">Semester</label>
                            <select id="semester" name="semester" required>
                                <option value="first">First</option>
                                <option value="second">Second</option>
                                <option value="summer">Summer</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="c_year">Year (Full, e.g., 2560, ค.ศ.)</label>
                            <input type="number" id="c_year" name="c_year" min="1901" max="2155" 
                                   value="<?php echo date('Y'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="year_code">Year Code (Last 2 digits, e.g., 60 for 2560)</label>
                            <input type="text" id="year_code" name="year_code" pattern="\d{2}" maxlength="2" required>
                        </div>

                        <div class="form-group">
                            <label for="group_number">Group Number</label>
                            <input type="number" id="group_number" name="group_number" required>
                        </div>

                        <div class="form-group">
                            <label for="teacher_name">Teacher Name</label>
                            <select id="teacher_name" name="teacher_name" required>
                                <?php
                                $teacher_query = $conn->query("SELECT name FROM teachers ORDER BY name");
                                while ($teacher = $teacher_query->fetch_assoc()) {
                                    echo '<option value="' . htmlspecialchars($teacher['name']) . '">' . htmlspecialchars($teacher['name']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <h2>Schedules</h2>
                        <div id="schedules-container">
                            <div class="schedule-group">
                                <button type="button" class="remove-schedule" onclick="removeSchedule(this)">Remove</button>
                                <div class="form-group">
                                    <label>Day of the Week</label>
                                    <select name="schedules[0][day_of_week]" required>
                                        <option value="Monday">Monday</option>
                                        <option value="Tuesday">Tuesday</option>
                                        <option value="Wednesday">Wednesday</option>
                                        <option value="Thursday">Thursday</option>
                                        <option value="Friday">Friday</option>
                                        <option value="Saturday">Saturday</option>
                                        <option value="Sunday">Sunday</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Start Time</label>
                                    <input type="time" name="schedules[0][start_time]" required>
                                </div>
                                <div class="form-group">
                                    <label>End Time</label>
                                    <input type="time" name="schedules[0][end_time]" required>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="add-schedule" onclick="addSchedule()">Add Another Schedule</button>

                        <button type="submit" name="add_single_course">Add Course</button>
                    </form>

                    <h2>Upload Courses via CSV or XLSX</h2>
                    <form action="/attend_system/admin/add_course.php" method="POST" enctype="multipart/form-data">
                        <div class="file-upload">
                            <label for="course_file">Select CSV or XLSX file</label>
                            <input type="file" name="course_file" id="course_file" accept=".csv, .xlsx" required>
                            <button type="submit" name="upload_file">Upload File</button>
                            <a href="/attend_system/admin/add_course.php?download_template=csv">
                                <button type="button" class="download-template">Download CSV Template</button>
                            </a>
                            <a href="/attend_system/admin/add_course.php?download_template=xlsx">
                                <button type="button" class="download-template">Download XLSX Template</button>
                            </a>
                        </div>
                    </form>

                    <div class="file-info">
                        <strong>Required columns:</strong>
                        <p>course_name, name_en, course_code, teacher_name, group_number, semester, c_year, year_code, day_of_week, start_time, end_time</p>
                        <p><strong>Note:</strong> For courses with multiple schedules, repeat the course details with different day_of_week, start_time, and end_time values in separate rows.</p>
                        <p><strong>Teachers:</strong> Ensure teacher names match those in the system (e.g., <?php
                            $teacher_query = $conn->query("SELECT name FROM teachers ORDER BY name LIMIT 3");
                            $teachers = [];
                            while ($teacher = $teacher_query->fetch_assoc()) {
                                $teachers[] = htmlspecialchars($teacher['name']);
                            }
                            echo implode(', ', $teachers);
                        ?>).</p>
                    </div>

                    <a href="/attend_system/admin/admin_dashboard.php">
                        <button class="back-button">Back to Dashboard</button>
                    </a>
                </div>
            </section>
        </div>
    </div>

    <footer>
        <p>&copy; <?php echo date("Y"); ?> University Admin System. All rights reserved.</p>
    </footer>

    <script>
        let scheduleIndex = 1;

        function addSchedule() {
            const container = document.getElementById('schedules-container');
            const scheduleGroup = document.createElement('div');
            scheduleGroup.className = 'schedule-group';
            scheduleGroup.innerHTML = `
                <button type="button" class="remove-schedule" onclick="removeSchedule(this)">Remove</button>
                <div class="form-group">
                    <label>Day of the Week</label>
                    <select name="schedules[${scheduleIndex}][day_of_week]" required>
                        <option value="Monday">Monday</option>
                        <option value="Tuesday">Tuesday</option>
                        <option value="Wednesday">Wednesday</option>
                        <option value="Thursday">Thursday</option>
                        <option value="Friday">Friday</option>
                        <option value="Saturday">Saturday</option>
                        <option value="Sunday">Sunday</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Start Time</label>
                    <input type="time" name="schedules[${scheduleIndex}][start_time]" required>
                </div>
                <div class="form-group">
                    <label>End Time</label>
                    <input type="time" name="schedules[${scheduleIndex}][end_time]" required>
                </div>
            `;
            container.appendChild(scheduleGroup);
            scheduleIndex++;
        }

        function removeSchedule(button) {
            if (document.querySelectorAll('.schedule-group').length > 1) {
                button.parentElement.remove();
            } else {
                alert('At least one schedule is required.');
            }
        }
    </script>
</body>
</html>

<?php
$conn->close();
?>