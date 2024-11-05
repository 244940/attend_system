<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'database_connection.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'teacher')) {
    header("Location: login.php");
    exit();
}

// Initialize variables
$teacher_id = null;
$error = null;
$success = null;

// If the user is a teacher, get their teacher_id
if ($_SESSION['user_role'] === 'teacher') {
    $stmt = $conn->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        die("Error: Teacher not found.");
    }
    $teacher_id = $result->fetch_assoc()['teacher_id'];
    $stmt->close();
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

function insert_course($conn, $course_data, $default_teacher_id) {
    // Add default values and null checks for all fields
    $course_name = isset($course_data['course_name']) ? trim($course_data['course_name']) : '';
    $course_code = isset($course_data['course_code']) ? trim($course_data['course_code']) : '';
    $teacher_id = isset($course_data['teacher_id']) && !empty($course_data['teacher_id']) ? 
        trim($course_data['teacher_id']) : $default_teacher_id;
    $day_of_week = isset($course_data['day_of_week']) ? trim($course_data['day_of_week']) : '';
    $start_time = isset($course_data['start_time']) ? format_time(trim($course_data['start_time'])) : false;
    $end_time = isset($course_data['end_time']) ? format_time(trim($course_data['end_time'])) : false;
    $group_number = isset($course_data['group_number']) ? trim($course_data['group_number']) : '';
    $semester = isset($course_data['semester']) ? strtolower(trim($course_data['semester'])) : '';
    $c_year = isset($course_data['c_year']) ? trim($course_data['c_year']) : '';

    // Validate that all required fields are present
    if (empty($course_name) || empty($course_code) || empty($day_of_week) || 
        !$start_time || !$end_time || empty($group_number) || 
        empty($semester) || empty($c_year)) {
        return "Incomplete data or invalid time format for course: " . ($course_code ?: 'Unknown code');
    }

    // Validate semester and year
    if (!validate_semester($semester)) {
        return "Invalid semester for course: $course_code. Must be 'first', 'second', or 'summer'.";
    }

    if (!validate_year($c_year)) {
        return "Invalid year for course: $course_code. Must be between 1901 and 2155.";
    }

    $valid_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    if (!in_array($day_of_week, $valid_days)) {
        return "Invalid day of the week for course: $course_code. Allowed values: " . implode(', ', $valid_days);
    }

    // Check for exact duplicate (comparing all relevant attributes)
    $check_stmt = $conn->prepare(
        "SELECT course_id FROM courses 
         WHERE course_code = ? AND semester = ? AND c_year = ? AND group_number = ? 
         AND teacher_id = ? AND day_of_week = ? AND start_time = ? AND end_time = ?"
    );
    $check_stmt->bind_param("ssssssss", 
        $course_code, $semester, $c_year, $group_number,
        $teacher_id, $day_of_week, $start_time, $end_time
    );
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $check_stmt->close();
        return "SKIP: Course already exists with identical attributes.";
    }
    $check_stmt->close();

    // Check for time conflict for the same teacher
    $conflict_stmt = $conn->prepare(
        "SELECT c.course_code, c.group_number 
         FROM courses c 
         WHERE c.teacher_id = ? 
         AND c.day_of_week = ? 
         AND c.semester = ? 
         AND c.c_year = ? 
         AND (
             (? BETWEEN c.start_time AND c.end_time) 
             OR (? BETWEEN c.start_time AND c.end_time)
             OR (c.start_time BETWEEN ? AND ?)
         )"
    );
    $conflict_stmt->bind_param("ssssssss", 
        $teacher_id, $day_of_week, $semester, $c_year, 
        $start_time, $end_time, $start_time, $end_time
    );
    $conflict_stmt->execute();
    $conflict_result = $conflict_stmt->get_result();

    if ($conflict_result->num_rows > 0) {
        $conflict = $conflict_result->fetch_assoc();
        $conflict_stmt->close();
        return "Time conflict detected for teacher ID $teacher_id with course {$conflict['course_code']} group {$conflict['group_number']}.";
    }
    $conflict_stmt->close();

    // Insert the new course into the database
    $stmt = $conn->prepare(
        "INSERT INTO courses 
         (course_name, course_code, teacher_id, day_of_week, start_time, end_time, group_number, semester, c_year) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("ssissssss", 
        $course_name, $course_code, $teacher_id, $day_of_week, 
        $start_time, $end_time, $group_number, $semester, $c_year
    );

    if ($stmt->execute()) {
        $stmt->close();
        return null;
    } else {
        $error = "Error adding course $course_code: " . $stmt->error;
        $stmt->close();
        return $error;
    }
}
// Function to manually parse CSV
function parse_csv($file_path) {
    $rows = array_map(function($line) {
        return str_getcsv($line, ',', '"', '\\');
    }, file($file_path));
    
    // Remove any UTF-8 BOM if present
    if (!empty($rows[0])) {
        $rows[0][0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $rows[0][0]);
    }
    
    return $rows;
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_file']) && isset($_FILES['course_file'])) {
    $file = $_FILES['course_file'];
    $file_type = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($file_type != "csv" && $file_type != "xlsx") {
        $error = "Only CSV and XLSX files are allowed.";
    } else {
        try {
            $rows = [];
            if ($file_type == "csv") {
                $rows = parse_csv($file['tmp_name']);
            } else { // xlsx
                if (class_exists('ZipArchive')) {
                    $reader = IOFactory::createReader('Xlsx');
                    $spreadsheet = $reader->load($file['tmp_name']);
                    $worksheet = $spreadsheet->getActiveSheet();
                    $rows = $worksheet->toArray();
                } else {
                    $error = "XLSX processing requires the ZIP extension. Please use CSV format instead.";
                }
            }

            if (!empty($rows)) {
                $header = array_shift($rows); // Get header row
                
                // Normalize header names
                $header = array_map(function($h) {
                    return trim(strtolower(str_replace(' ', '_', $h)));
                }, $header);
                
                // Replace 'year' with 'c_year' if present
                $header = array_map(function($h) {
                    return $h === 'year' ? 'c_year' : $h;
                }, $header);
            
                $required_columns = ['course_name', 'course_code', 'teacher_id', 'day_of_week', 
                                   'start_time', 'end_time', 'group_number', 'semester', 'c_year'];
                
                // Verify all required columns are present
                $missing_columns = array_diff($required_columns, $header);
                if (!empty($missing_columns)) {
                    $error = "Missing required columns: " . implode(', ', $missing_columns);
                } else {
                    $success_count = 0;
                    $skip_count = 0;
                    $errors = [];
            
                    foreach ($rows as $row_index => $row) {
                        // Skip empty rows
                        if (empty(array_filter($row))) {
                            continue;
                        }
            
                        // Ensure row has same number of elements as header
                        if (count($row) !== count($header)) {
                            $errors[] = "Row " . ($row_index + 2) . " has incorrect number of columns";
                            continue;
                        }
            
                        $course_data = array_combine($header, $row);
                        
                        // Clean and validate data before insertion
                        foreach ($course_data as $key => &$value) {
                            $value = trim($value);
                            if ($key === 'start_time' || $key === 'end_time') {
                                // Ensure time format is correct
                                if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value)) {
                                    $errors[] = "Invalid time format in row " . ($row_index + 2) . " for " . $key;
                                    continue 2;
                                }
                            }
                        }
                        unset($value);
            
                        $result = insert_course($conn, $course_data, $teacher_id);
                        if ($result === null) {
                            $success_count++;
                        } else if (strpos($result, 'SKIP:') === 0) {
                            $skip_count++;
                        } else {
                            $errors[] = "Row " . ($row_index + 2) . ": " . $result;
                        }
                    }
            
                    $success = "Successfully added $success_count courses. Skipped $skip_count duplicate courses.";
                    if (!empty($errors)) {
                        $error = "Errors occurred:\n" . implode("\n", $errors);
                    }
                }
            
            }
        } catch (Exception $e) {
            $error = "Error processing file: " . $e->getMessage();
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Course</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            padding: 20px;
            background-color: #f5f5f5;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background-color: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: #2c3e50;
            margin-bottom: 30px;
            text-align: center;
            font-size: 2em;
        }

        h2 {
            color: #34495e;
            margin: 25px 0 15px 0;
            font-size: 1.5em;
        }

        .alert {
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background-color: #dcfce7;
            color: #16a34a;
            border: 1px solid #bbf7d0;
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
    </style>
</head>
<body>
    <div class="container">
        <h1>Add a New Course</h1>

        <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <?php echo nl2br(htmlspecialchars($error)); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($success)): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <h2>Add Single Course</h2>
<form action="add_course.php" method="POST">
    <div class="form-group">
        <label for="course_name">Course Name</label>
        <input type="text" id="course_name" name="course_name" required>
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
        <label for="c_year">Year</label>
        <input type="number" id="c_year" name="c_year" min="1901" max="2155" 
               value="<?php echo date('Y'); ?>" required>
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
        <label for="teacher_id">Teacher ID</label>
        <input type="text" id="teacher_id" name="teacher_id" required>
    </div>

    <button type="submit" name="add_single_course">Add Course</button>
</form>


        <h2>Upload Courses via CSV or XLSX</h2>
        <form action="add_course.php" method="POST" enctype="multipart/form-data">
            <div class="file-upload">
                <label for="course_file">Select CSV or XLSX file</label>
                <input type="file" name="course_file" id="course_file" accept=".csv, .xlsx" required>
                <button type="submit" name="upload_file">Upload File</button>
            </div>
        </form>

        <div class="file-info">
            <strong>Required columns:</strong>
            <p>course_name, course_code, teacher_id, day_of_week, start_time, end_time, group_number, semester, c_year</p>
        </div>

        <a href="<?php echo ($_SESSION['user_role'] === 'admin') ? 'admin_dashboard.php' : 'teacher_dashboard.php'; ?>">
            <button class="back-button">Back to Dashboard</button>
        </a>
    </div>
</body>
</html>