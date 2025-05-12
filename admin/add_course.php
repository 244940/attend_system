<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'database_connection.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

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

function insert_course($conn, $course_data) {
    $course_name = isset($course_data['course_name']) ? trim($course_data['course_name']) : '';
    $name_en = isset($course_data['course_name_en']) ? trim($course_data['course_name_en']) : '';
    $course_code = isset($course_data['course_code']) ? trim($course_data['course_code']) : '';
    $teacher_name = isset($course_data['teacher_name']) ? trim($course_data['teacher_name']) : '';
    $day_of_week = isset($course_data['day_of_week']) ? trim($course_data['day_of_week']) : '';
    $start_time = isset($course_data['start_time']) ? format_time(trim($course_data['start_time'])) : false;
    $end_time = isset($course_data['end_time']) ? format_time(trim($course_data['end_time'])) : false;
    $group_number = isset($course_data['group_number']) ? trim($course_data['group_number']) : '';
    $semester = isset($course_data['semester']) ? strtolower(trim($course_data['semester'])) : '';
    $c_year = isset($course_data['c_year']) ? trim($course_data['c_year']) : '';
    $year_code = isset($course_data['year_code']) ? trim($course_data['year_code']) : '';

    if (empty($course_name) || empty($name_en) || empty($course_code) || empty($teacher_name) ||
        empty($day_of_week) || !$start_time || !$end_time || empty($group_number) ||
        empty($semester) || empty($c_year) || empty($year_code)) {
        return "Incomplete data or invalid time format for course: " . ($course_code ?: 'Unknown code');
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

    $valid_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    if (!in_array($day_of_week, $valid_days)) {
        return "Invalid day of the week for course: $course_code. Allowed values: " . implode(', ', $valid_days);
    }

    // Look up teacher_id based on teacher_name
    $teacher_id = null;
    $teacher_stmt = $conn->prepare("SELECT teacher_id FROM teachers WHERE name = ?");
    $teacher_stmt->bind_param("s", $teacher_name);
    $teacher_stmt->execute();
    $teacher_result = $teacher_stmt->get_result();
    if ($teacher_result->num_rows > 0) {
        $teacher_id = $teacher_result->fetch_assoc()['teacher_id'];
        if (!is_numeric($teacher_id) || $teacher_id <= 0) {
            $teacher_stmt->close();
            error_log("Invalid teacher_id for teacher: $teacher_name, teacher_id: $teacher_id");
            return "Invalid teacher_id for teacher: $teacher_name";
        }
        error_log("Teacher ID for $teacher_name: $teacher_id");
    } else {
        $teacher_stmt->close();
        error_log("Teacher not found: $teacher_name");
        return "Teacher not found: $teacher_name";
    }
    $teacher_stmt->close();

    // Check for duplicate course
    $check_stmt = $conn->prepare(
        "SELECT course_id FROM courses 
         WHERE course_code = ? AND semester = ? AND c_year = ? AND year_code = ? AND group_number = ? 
         AND teacher_name = ? AND day_of_week = ? AND start_time = ? AND end_time = ?"
    );
    $check_stmt->bind_param("sssssssss",
        $course_code, $semester, $c_year, $year_code, $group_number,
        $teacher_name, $day_of_week, $start_time, $end_time
    );
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $check_stmt->close();
        return "SKIP: Course already exists with identical attributes.";
    }
    $check_stmt->close();

    // Check for time conflicts
    $conflict_stmt = $conn->prepare(
        "SELECT c.course_code, c.group_number 
         FROM courses c 
         WHERE c.teacher_name = ? 
         AND c.day_of_week = ? 
         AND c.semester = ? 
         AND c.c_year = ? 
         AND c.year_code = ?
         AND (
             (? BETWEEN c.start_time AND c.end_time) 
             OR (? BETWEEN c.start_time AND c.end_time)
             OR (c.start_time BETWEEN ? AND ?)
         )"
    );
    $conflict_stmt->bind_param("sssssssss",
        $teacher_name, $day_of_week, $semester, $c_year, $year_code,
        $start_time, $end_time, $start_time, $end_time
    );
    $conflict_stmt->execute();
    $conflict_result = $conflict_stmt->get_result();

    if ($conflict_result->num_rows > 0) {
        $conflict = $conflict_result->fetch_assoc();
        $conflict_stmt->close();
        return "Time conflict detected for teacher $teacher_name with course {$conflict['course_code']} group {$conflict['group_number']}.";
    }
    $conflict_stmt->close();

    // Insert course
    $stmt = $conn->prepare(
        "INSERT INTO courses 
         (course_name, name_en, course_code, teacher_id, teacher_name, day_of_week, start_time, end_time, group_number, semester, c_year, year_code) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("sssissssssss",
        $course_name, $name_en, $course_code, $teacher_id, $teacher_name, $day_of_week,
        $start_time, $end_time, $group_number, $semester, $c_year, $year_code
    );

    error_log("Inserting course: course_code=$course_code, teacher_id=$teacher_id, teacher_name=$teacher_name");
    if ($stmt->execute()) {
        $stmt->close();
        return null;
    } else {
        $error = "Error adding course $course_code: " . $stmt->error;
        $stmt->close();
        return $error;
    }
}

// Handle single course addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_single_course'])) {
    $course_data = [
        'course_name' => $_POST['course_name'],
        'course_name_en' => $_POST['course_name_en'],
        'course_code' => $_POST['course_code'],
        'teacher_name' => $_POST['teacher_name'],
        'day_of_week' => $_POST['day_of_week'],
        'start_time' => $_POST['start_time'],
        'end_time' => $_POST['end_time'],
        'group_number' => $_POST['group_number'],
        'semester' => $_POST['semester'],
        'c_year' => $_POST['c_year'],
        'year_code' => $_POST['year_code']
    ];

    $result = insert_course($conn, $course_data);
    if ($result === null) {
        $_SESSION['success_message'] = "Course {$_POST['course_code']} added successfully.";
    } else if (strpos($result, 'SKIP:') === 0) {
        $_SESSION['success_message'] = $result;
    } else {
        $_SESSION['error_message'] = $result;
    }
    header("Location: /attend_system/admin/add_course.php");
    exit();
}

function parse_csv($file_path) {
    $rows = array_map(function($line) {
        return str_getcsv($line, ',', '"', '\\');
    }, file($file_path));
    
    if (!empty($rows[0])) {
        $rows[0][0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $rows[0][0]); // Clean first cell of header
    }
    
    return $rows;
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
                $rows = parse_csv($file['tmp_name']);
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
            
                $required_columns = ['course_name', 'name_en', 'course_code', 'teacher_name', 'day_of_week', 
                                   'start_time', 'end_time', 'group_number', 'semester', 'c_year', 'year_code'];
                
                $missing_columns = array_diff($required_columns, $header);
                if (!empty($missing_columns)) {
                    $_SESSION['error_message'] = "Missing required columns: " . implode(', ', $missing_columns);
                } else {
                    $success_count = 0;
                    $skip_count = 0;
                    $errors = [];
            
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
                        
                        foreach ($course_data as $key => &$value) {
                            $value = trim($value);
                            if ($key === 'start_time' || $key === 'end_time') {
                                if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value)) {
                                    $errors[] = "Invalid time format in row " . ($row_index + 2) . " for " . $key;
                                    continue 2;
                                }
                            }
                        }
                        unset($value);
            
                        $result = insert_course($conn, $course_data);
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

                    <!-- Display Flash Messages -->
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
                            <label for="c_year">Year (Full, e.g., 2560)</label>
                            <input type="number" id="c_year" name="c_year" min="1901" max="2155" 
                                   value="<?php echo date('Y'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="year_code">Year Code (Last 2 digits, e.g., 60 for 2560)</label>
                            <input type="text" id="year_code" name="year_code" pattern="\d{2}" maxlength="2" required>
                        </div>

                        <div class="form-group">
                            <label for="day_of_week">Day of the Week</label>
                            <select id="day_of_week" name="day_of_week" required>
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
                            <label for="start_time">Start Time</label>
                            <input type="time" id="start_time" name="start_time" required>
                        </div>

                        <div class="form-group">
                            <label for="end_time">End Time</label>
                            <input type="time" id="end_time" name="end_time" required>
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

                        <button type="submit" name="add_single_course">Add Course</button>
                    </form>

                    <h2>Upload Courses via CSV or XLSX</h2>
                    <form action="/attend_system/admin/add_course.php" method="POST" enctype="multipart/form-data">
                        <div class="file-upload">
                            <label for="course_file">Select CSV or XLSX file</label>
                            <input type="file" name="course_file" id="course_file" accept=".csv, .xlsx" required>
                            <button type="submit" name="upload_file">Upload File</button>
                        </div>
                    </form>

                    <div class="file-info">
                        <strong>Required columns:</strong>
                        <p>course_name, course_name_en, course_code, teacher_name, day_of_week, start_time, end_time, group_number, semester, c_year, year_code</p>
                    </div>

                    <a href="/attend_system/admin/admin_dashboard.php">
                        <button class="back-button">Back to Dashboard</button>
                    </a>
                </div>
            </section>
        </div>
    </div>

    <footer>
        <p>© <?php echo date("Y"); ?> University Admin Dashboard. All rights reserved.</p>
    </footer>
</body>
</html>

<?php
$conn->close();
?>