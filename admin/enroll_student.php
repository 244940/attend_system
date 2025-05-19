<?php
session_start();
require 'database_connection.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// Check if user is an admin
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

// Redirect non-admin users
if (!isAdmin()) {
    error_log("Access denied: Not an admin. Session: " . print_r($_SESSION, true));
    header("Location: login.php");
    exit();
}

$enrollmentMessage = '';
$enrollmentCompleted = false;

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['enrollment_file'])) {
    $file = $_FILES['enrollment_file'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if ($file_ext == 'csv' || $file_ext == 'xlsx') {
        $enrollmentMessage = processEnrollmentFile($file, $file_ext, $conn);
        $enrollmentCompleted = true;
    } else {
        $enrollmentMessage = "Invalid file format. Please upload a CSV or XLSX file.";
    }
}

// Handle single student enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_student'])) {
    $student_id = $_POST['student_id'] ?? null;
    $course_id = $_POST['course_id'] ?? null;
    $group_number = $_POST['group_number'] ?? null;

    if ($student_id && $course_id && $group_number) {
        $enrollmentMessage = enrollStudent($student_id, $course_id, $group_number, $conn);
        $enrollmentCompleted = true;
    } else {
        $enrollmentMessage = "Please provide all required fields.";
    }
}

function processEnrollmentFile($file, $file_ext, $conn) {
    try {
        $spreadsheet = IOFactory::load($file['tmp_name']);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();
        
        $successCount = 0;
        $errorCount = 0;
        $processed = [];
        $errors = [];
        
        // Begin transaction
        $conn->begin_transaction();
        
        // Cache course information
        $validCourses = [];
        $courseQuery = "SELECT course_id, course_name, group_number, day_of_week, start_time, end_time 
                       FROM courses";
        $result = $conn->query($courseQuery);
        while ($row = $result->fetch_assoc()) {
            $group_key = $row['course_name'] . '_' . strval($row['group_number']);
            $validCourses[$group_key] = [
                'course_id' => $row['course_id'],
                'schedule' => [
                    'day_of_week' => $row['day_of_week'],
                    'start_time' => $row['start_time'],
                    'end_time' => $row['end_time']
                ]
            ];
        }
        error_log("Valid courses cached: " . print_r(array_keys($validCourses), true));
        
        // Skip header row
        for ($i = 1; $i < count($rows); $i++) {
            if (empty($rows[$i][1])) continue;
            
            $student_name = trim($rows[$i][1]);
            $course_name = trim($rows[$i][3]);
            $group_number = trim($rows[$i][4]);
            
            error_log("Processing row $i: Student: $student_name, Course: $course_name, Group: $group_number");
            
            if (!is_numeric($group_number)) {
                $errors[] = "Row $i: Invalid group number format for student $student_name";
                $errorCount++;
                continue;
            }
            
            $course_group_key = $course_name . '_' . $group_number;
            
            if (!isset($validCourses[$course_group_key])) {
                $errors[] = "Row $i: Course '$course_name' with group $group_number does not exist";
                $errorCount++;
                continue;
            }
            
            $entry_key = $student_name . '_' . $course_group_key;
            
            if (isset($processed[$entry_key])) {
                $errors[] = "Row $i: Duplicate entry for $student_name in $course_name group $group_number";
                continue;
            }
            
            $processed[$entry_key] = true;
            
            $student_id = getStudentId($student_name, $conn);
            if (!$student_id) {
                $errors[] = "Row $i: Student not found - $student_name";
                $errorCount++;
                continue;
            }
            
            $course_info = $validCourses[$course_group_key];
            
            if (empty($course_info['schedule']['day_of_week']) || 
                empty($course_info['schedule']['start_time']) || 
                empty($course_info['schedule']['end_time'])) {
                $errors[] = "Row $i: Incomplete schedule information for $course_name group $group_number";
                $errorCount++;
                continue;
            }
            
            $result = enrollStudent($student_id, $course_info['course_id'], $group_number, $conn);
            
            if (strpos($result, "successfully") !== false) {
                $successCount++;
            } else {
                $errorCount++;
                $errors[] = "Row $i: $result";
            }
        }
        
        if ($errorCount > 0) {
            $conn->rollback();
            $error_message = "Enrollment failed. Found $errorCount errors:\n" . implode("\n", $errors);
            error_log($error_message);
            return $error_message;
        }
        
        $conn->commit();
        $success_message = "Enrollment complete. Successful enrollments: $successCount.";
        if (!empty($errors)) {
            $success_message .= "\nWarnings encountered:\n" . implode("\n", $errors);
        }
        error_log($success_message);
        return $success_message;
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Fatal error during enrollment: " . $e->getMessage();
        error_log($error_message);
        return $error_message;
    }
}

function getStudentId($student_name, $conn) {
    $query = "SELECT student_id FROM students WHERE name = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $student_name);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? $row['student_id'] : null;
}

function enrollStudent($student_id, $course_id, $group_number, $conn) {
    // Begin transaction
    $conn->begin_transaction();

    try {
        // Check for existing enrollment
        $check_query = "SELECT * FROM enrollments WHERE student_id = ? AND course_id = ? AND group_number = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("iii", $student_id, $course_id, $group_number);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            $check_stmt->close();
            $conn->rollback();
            return "Student is already enrolled in this course and group.";
        }
        $check_stmt->close();

        // Check for schedule conflicts using courses table
        $conflict_check_query = "
            SELECT COUNT(*) as conflict_count 
            FROM schedules s1 
            JOIN ( 
                SELECT day_of_week, start_time, end_time 
                FROM courses 
                WHERE course_id = ? AND group_number = ?
            ) s2 ON s2.day_of_week = s1.day_of_week 
            AND (
                (s2.start_time BETWEEN s1.start_time AND s1.end_time) OR 
                (s2.end_time BETWEEN s1.start_time AND s1.end_time) OR 
                (s1.start_time BETWEEN s2.start_time AND s2.end_time)
            ) 
            WHERE s1.student_id = ?";
        
        $conflict_stmt = $conn->prepare($conflict_check_query);
        $conflict_stmt->bind_param("iii", $course_id, $group_number, $student_id);
        $conflict_stmt->execute();
        $conflict_result = $conflict_stmt->get_result();
        $conflict_row = $conflict_result->fetch_assoc();
        
        if ($conflict_row['conflict_count'] > 0) {
            $conflict_stmt->close();
            $conn->rollback();
            return "Schedule conflict detected. Cannot enroll in this course.";
        }
        $conflict_stmt->close();

        // Insert enrollment record
        $enroll_query = "INSERT INTO enrollments (student_id, course_id, group_number) VALUES (?, ?, ?)";
        $enroll_stmt = $conn->prepare($enroll_query);
        $enroll_stmt->bind_param("iii", $student_id, $course_id, $group_number);
        
        if (!$enroll_stmt->execute()) {
            throw new Exception("Error enrolling student: " . $conn->error);
        }
        $enroll_stmt->close();

        // Get course schedule from courses table
        $course_time_query = "SELECT day_of_week, start_time, end_time 
                             FROM courses 
                             WHERE course_id = ? AND group_number = ?";
        $course_time_stmt = $conn->prepare($course_time_query);
        $course_time_stmt->bind_param("ii", $course_id, $group_number);
        $course_time_stmt->execute();
        $course_time_result = $course_time_stmt->get_result();
        
        if ($course_time_result->num_rows === 0) {
            $course_time_stmt->close();
            throw new Exception("Course schedule not found.");
        }
        
        $course_time_row = $course_time_result->fetch_assoc();
        $start_time = $course_time_row['start_time'];
        $end_time = $course_time_row['end_time'];
        $day_of_week = $course_time_row['day_of_week'];
        $course_time_stmt->close();
        
        // Insert schedule
        $schedule_query = "INSERT INTO schedules (student_id, day_of_week, start_time, end_time, course_id) 
                          VALUES (?, ?, ?, ?, ?)";
        $schedule_stmt = $conn->prepare($schedule_query);
        $schedule_stmt->bind_param("isssi", 
            $student_id,
            $day_of_week,
            $start_time,
            $end_time,
            $course_id);
        
        if (!$schedule_stmt->execute()) {
            throw new Exception("Error creating schedule: " . $conn->error);
        }
        $schedule_stmt->close();

        // Commit the transaction
        $conn->commit();
        return "Student enrolled successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Failed to enroll student: " . $e->getMessage();
        error_log($error_message);
        return $error_message;
    }
}

function getEnrolledStudents($conn) {
    $query = "SELECT c.course_id, c.course_name, s.name AS student_name, e.group_number
              FROM courses c
              JOIN enrollments e ON c.course_id = e.course_id
              JOIN students s ON e.student_id = s.student_id
              ORDER BY c.course_name, e.group_number, s.name";
    
    $result = $conn->query($query);
    $enrollments = [];

    while ($row = $result->fetch_assoc()) {
        $course_id = $row['course_id'];
        $group_number = $row['group_number'];
        $key = $course_id . '_' . $group_number;
        if (!isset($enrollments[$key])) {
            $enrollments[$key] = [
                'course_name' => $row['course_name'],
                'group_number' => $group_number,
                'students' => []
            ];
        }
        $enrollments[$key]['students'][] = $row['student_name'];
    }

    return $enrollments;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enroll Student</title>
    <style>
        /* Reset CSS */
        body, html {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        /* Top Bar */
        .top-bar {
            width: 100%;
            background-color: #2980b9;
            color: white;
            padding: 15px 20px;
            text-align: left;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .top-bar h1 {
            margin: 0;
            font-size: 24px;
        }

        /* Admin Page Layout */
        .admin-container {
            display: flex;
            flex: 1;
            width: 100%;
            background: white;
        }

        /* Sidebar Styling */
        .sidebar {
            width: 250px;
            background-color: #2c3e50;
            color: white;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            height: 100vh;
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

        /* Main Content Area */
        .main-content {
            flex: 1;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            background-color: #ecf0f1;
            height: 100vh;
            overflow-y: auto;
        }

        .form-container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            padding: 20px;
            margin-bottom: 20px;
            width: 100%;
            max-width: 600px;
        }

        h2, h3 {
            color: #2980b9;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        input[type="file"],
        input[type="text"],
        select {
            width: calc(100% - 22px);
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
            border: 1px solid #ccc;
        }

        button {
            background-color: #3498db;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        button:hover {
            background-color: #2980b9;
        }

        .file-details {
            font-size: 12px;
            color: #666;
        }

        .enrollment-message {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .enrollment-message.success {
            background-color: #dff0d8;
            color: #3c763d;
        }

        .enrollment-message.error {
            background-color: #f2dede;
            color: #a94442;
        }

        .enrollment-list {
            margin-top: 20px;
            width: 100%;
            max-width: 600px;
        }

        .course-item {
            margin-bottom: 20px;
        }

        .course-name {
            font-weight: bold;
        }

        .student-list {
            list-style-type: none;
            padding-left: 20px;
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <h1>Enroll Student</h1>
    </div>

    <div class="admin-container">
        <aside class="sidebar">
            <ul>
                <li><a href="admin_dashboard.php">Dashboard</a></li>
                <li><a href="manage_users.php">Manage Users</a></li>
                <li><a href="add_users.php">Add Users</a></li>
                <li><a href="add_course.php">Add Course</a></li>
                <li><a href="enroll_student.php">Enroll Student</a></li>
                <li><a href="logout.php">Logout</a></li>
            </ul>
        </aside>

        <div class="main-content">
            <section class="dashboard-section" id="enroll-student">
                <h2>Enroll Student in a Course</h2>
                <?php if ($enrollmentMessage): ?>
                    <p class="enrollment-message <?php echo strpos($enrollmentMessage, 'successfully') !== false ? 'success' : 'error'; ?>">
                        <?php echo nl2br(htmlspecialchars($enrollmentMessage)); ?>
                    </p>
                <?php endif; ?>

                <div class="form-container">
                    <h3>Upload Enrollment File</h3>
                    <form method="POST" action="enroll_student.php" enctype="multipart/form-data" class="upload-form">
                        <input type="file" name="enrollment_file" accept=".csv,.xlsx" required>
                        <button type="submit" name="upload_file">Upload and Enroll</button>
                        <label class="file-details">The file should contain columns: student_code, name, course_code, course_name, group</label>
                    </form>
                </div>

                <div class="form-container">
                    <h3>Enroll Individual Student</h3>
                    <form method="POST" action="enroll_student.php" class="individual-enroll-form">
                        <label for="student_id">Select Student:</label>
                        <select name="student_id" id="student_id" required>
                            <option value="">Select a Student</option>
                            <?php
                            $students_query = "SELECT student_id, name FROM students ORDER BY name";
                            $result = $conn->query($students_query);
                            if ($result && $result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    echo '<option value="' . htmlspecialchars($row['student_id']) . '">' . htmlspecialchars($row['name']) . '</option>';
                                }
                            } else {
                                echo '<option value="">No students available</option>';
                            }
                            ?>
                        </select>

                        <label for="course_id">Select Course:</label>
                        <select name="course_id" id="course_id" required>
                            <option value="">Select a Course</option>
                            <?php
                            $courses_query = "SELECT course_id, course_name, group_number 
                                            FROM courses 
                                            ORDER BY course_name, group_number";
                            $result = $conn->query($courses_query);
                            while ($course = $result->fetch_assoc()) {
                                echo "<option value='{$course['course_id']}'>" . 
                                     htmlspecialchars($course['course_name']) . 
                                     " (Group {$course['group_number']})" . 
                                     "</option>";
                            }
                            ?>
                        </select>

                        <label for="group_number">Enter Group Number:</label>
                        <input type="text" name="group_number" id="group_number" required placeholder="Enter group number" pattern="\d+" title="Group number must be numeric">

                        <button type="submit" name="enroll_student">Enroll Student</button>
                    </form>
                </div>

                <?php if ($enrollmentCompleted): ?>
                    <div class="enrollment-list">
                        <h3>Current Enrollments</h3>
                        <?php
                        $enrollments = getEnrolledStudents($conn);
                        foreach ($enrollments as $key => $course_data):
                        ?>
                            <div class="course-item">
                                <p class="course-name"><?php echo htmlspecialchars($course_data['course_name']) . ' (Group ' . htmlspecialchars($course_data['group_number']) . ')'; ?></p>
                                <ul class="student-list">
                                    <?php foreach ($course_data['students'] as $student): ?>
                                        <li><?php echo htmlspecialchars($student); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</body>
</html>
<?php
$conn->close();
?>