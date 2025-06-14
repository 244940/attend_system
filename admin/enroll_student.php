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

// Handle template download
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="enrollment_template.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['student_code', 'name', 'course_code', 'course_name', 'group']);
    fclose($output);
    exit();
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['enrollment_file'])) {
    $file = $_FILES['enrollment_file'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if ($file_ext == 'csv' || $file_ext == 'xlsx') {
        $enrollmentMessage = processEnrollmentFile($file, $file_ext, $conn);
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
        
        $conn->begin_transaction();
        
        $validCourses = [];
        $courseQuery = "SELECT course_id, course_name, course_code, group_number 
                       FROM courses";
        $result = $conn->query($courseQuery);
        while ($row = $result->fetch_assoc()) {
            $group_key = $row['course_name'] . '_' . strval($row['group_number']);
            $validCourses[$group_key] = [
                'course_id' => $row['course_id'],
                'course_code' => $row['course_code'],
                'group_number' => $row['group_number']
            ];
        }
        
        for ($i = 1; $i < count($rows); $i++) {
            if (empty($rows[$i][1])) continue;
            
            $student_code = trim($rows[$i][0]);
            $student_name = trim($rows[$i][1]);
            $course_code = trim($rows[$i][2]);
            $course_name = trim($rows[$i][3]);
            $group_number = trim($rows[$i][4]);
            
            if (!is_numeric($group_number)) {
                $errors[] = "Row $i: Invalid group number format for student $student_name.";
                $errorCount++;
                continue;
            }
            
            $course_group_key = $course_name . '_' . $group_number;
            
            if (!isset($validCourses[$course_group_key])) {
                $errors[] = "Row $i: Course '$course_name' with group $group_number does not exist.";
                $errorCount++;
                continue;
            }
            
            if ($validCourses[$course_group_key]['course_code'] !== $course_code) {
                $errors[] = "Row $i: Course code '$course_code' does not match.";
                $errorCount++;
                continue;
            }
            
            $entry_key = $student_code . '_' . $course_group_key;
            
            if (isset($processed[$entry_key])) {
                $errors[] = "Row $i: Duplicate entry for $student_name in $course_name group $group_number";
                $errorCount++;
                continue;
            }
            
            $processed[$entry_key] = true;
            
            $student_id = getStudentId($student_code, $student_name, $conn);
            if (!$student_id) {
                $errors[] = "Row $i: Student not found - $student_name ($student_code)";
                $errorCount++;
                continue;
            }
            
            $course_info = $validCourses[$course_group_key];
            
            $result = enrollStudent($student_id, $course_info['course_id'], $course_info['group_number'], $conn);
            
            if (strpos($result, "successfully") !== false) {
                $successCount++;
            } else {
                $errorCount++;
                $errors[] = "Row $i: $result";
            }
        }
        
        if ($errorCount > 0) {
            $conn->rollback();
            return "Enrollment failed. Found $errorCount errors:\n" . implode("\n", $errors);
        }
        
        $conn->commit();
        return "Enrollment complete. Successful enrollments: $successCount.";
        
    } catch (Exception $e) {
        $conn->rollback();
        return "Fatal error during enrollment: " . $e->getMessage();
    }
}

function getStudentId($student_code, $student_name, $conn) {
    $query = "SELECT student_id FROM students WHERE citizen_id = ? OR name = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $student_code, $student_name);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? $row['student_id'] : null;
}

function enrollStudent($student_id, $course_id, $group_number, $conn) {
    $conn->begin_transaction();
    try {
        $course_verify_query = "SELECT course_id FROM courses WHERE course_id = ? AND group_number = ?";
        $course_verify_stmt = $conn->prepare($course_verify_query);
        $course_verify_stmt->bind_param("ii", $course_id, $group_number);
        $course_verify_stmt->execute();
        $course_verify_result = $course_verify_stmt->get_result();
        
        if ($course_verify_result->num_rows === 0) {
            $course_verify_stmt->close();
            $conn->rollback();
            return "Invalid course ID $course_id or group number $group_number.";
        }
        $course_verify_stmt->close();

        $schedule_query = "SELECT day_of_week, start_time, end_time 
                          FROM schedules 
                          WHERE course_id = ?";
        $schedule_stmt = $conn->prepare($schedule_query);
        $schedule_stmt->bind_param("i", $course_id);
        $schedule_stmt->execute();
        $schedule_result = $schedule_stmt->get_result();
        
        if ($schedule_result->num_rows === 0) {
            $schedule_stmt->close();
            $conn->rollback();
            return "No schedule found for course ID $course_id.";
        }

        $course_schedules = [];
        while ($row = $schedule_result->fetch_assoc()) {
            if (empty($row['day_of_week']) || empty($row['start_time']) || empty($row['end_time'])) {
                $schedule_stmt->close();
                $conn->rollback();
                return "Incomplete schedule information for course ID $course_id.";
            }
            if ($row['start_time'] === $row['end_time']) {
                $schedule_stmt->close();
                $conn->rollback();
                return "Invalid schedule for course ID $course_id: start_time equals end_time.";
            }
            $course_schedules[] = $row;
        }
        $schedule_stmt->close();

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

        foreach ($course_schedules as $schedule) {
            $conflict_query = "
                SELECT COUNT(*) as conflict_count 
                FROM student_schedules ss
                WHERE ss.student_id = ?
                AND ss.day_of_week = ?
                AND (
                    (? BETWEEN ss.start_time AND ss.end_time) OR
                    (? BETWEEN ss.start_time AND ss.end_time) OR
                    (ss.start_time BETWEEN ? AND ?)
                )";
            
            $conflict_stmt = $conn->prepare($conflict_query);
            $conflict_stmt->bind_param("isssss", 
                $student_id, 
                $schedule['day_of_week'], 
                $schedule['start_time'], 
                $schedule['end_time'], 
                $schedule['start_time'], 
                $schedule['end_time']
            );
            $conflict_stmt->execute();
            $conflict_result = $conflict_stmt->get_result();
            $conflict_row = $conflict_result->fetch_assoc();
            
            if ($conflict_row['conflict_count'] > 0) {
                $conflict_stmt->close();
                $conn->rollback();
                return "Schedule conflict detected for {$schedule['day_of_week']} {$schedule['start_time']}-{$schedule['end_time']}.";
            }
            $conflict_stmt->close();
        }

        $enroll_query = "INSERT INTO enrollments (student_id, course_id, group_number) VALUES (?, ?, ?)";
        $enroll_stmt = $conn->prepare($enroll_query);
        $enroll_stmt->bind_param("iii", $student_id, $course_id, $group_number);
        
        if (!$enroll_stmt->execute()) {
            throw new Exception("Error enrolling student: " . $conn->error);
        }
        $enroll_stmt->close();

        foreach ($course_schedules as $schedule) {
            $student_schedule_query = "INSERT INTO student_schedules (student_id, course_id, day_of_week, start_time, end_time) 
                                      VALUES (?, ?, ?, ?, ?)";
            $student_schedule_stmt = $conn->prepare($student_schedule_query);
            $student_schedule_stmt->bind_param("iisss", 
                $student_id, 
                $course_id, 
                $schedule['day_of_week'], 
                $schedule['start_time'], 
                $schedule['end_time']
            );
            
            if (!$student_schedule_stmt->execute()) {
                throw new Exception("Error creating student schedule: " . $conn->error);
            }
            $student_schedule_stmt->close();
        }

        $conn->commit();
        return "Student enrolled successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        return "Failed to enroll student: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enroll Student</title>
    <style>
        body, html { margin: 0; padding: 0; font-family: Arial, sans-serif; height: 100%; display: flex; flex-direction: column; background-image: url('/attend_system/admin/assets/bb.jpg'); background-size: cover; background-position: center; }
        .top-bar { width: 100%; background-color: #2980b9; color: white; padding: 15px 20px; text-align: left; box-shadow: 0 2px 4px rgba(0,0,0,0.2); display: flex; justify-content: space-between; align-items: center; }
        .top-bar h1 { margin: 0; font-size: 24px; }
        .admin-container { display: flex; flex: 1; width: 100%; height: calc(100vh - 70px); background: rgba(255, 255, 255, 0.9); }
        .sidebar { width: 250px; background-color: #2c3e50; color: white; padding: 20px; display: flex; flex-direction: column; align-items: center; height: 100%; }
        .sidebar ul { list-style: none; padding: 0; width: 100%; }
        .sidebar ul li { margin: 15px 0; text-align: center; }
        .sidebar ul li a { color: white; text-decoration: none; display: block; padding: 10px; transition: background 0.3s; }
        .sidebar ul li a:hover { background-color: #34495e; border-radius: 5px; }
        .main-content { flex: 1; padding: 20px; display: flex; flex-direction: column; align-items: center; background-color: #ecf0f1; height: 100%; overflow-y: auto; }
        .form-container { background: white; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 20px; width: 100%; max-width: 600px; }
        h2, h3 { color: #2980b9; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="file"], input[type="text"], select { width: calc(100% - 22px); padding: 10px; margin-bottom: 15px; border-radius: 4px; border: 1px solid #ccc; }
        button { background: #3498db; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; margin-right: 10px; }
        button:hover { background: #2980b9; }
        .file-details { font-size: 12px; color: #666; }
        .enrollment-message { padding: 10px; margin-bottom: 20px; border-radius: 5px; }
        .enrollment-message.success { background: #dff0d8; color: #3c763d; }
        .enrollment-message.error { background: #f2dede; color: #a94442; }
        footer { text-align: center; padding: 10px; background-color: #34495e; color: white; width: 100%; }
    </style>
</head>
<body>
    <div class="top-bar">
        <h1>Enroll Student</h1>
        <div class="user-info">
            <span><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
        </div>
    </div>
    <div class="admin-container">
        <aside class="sidebar">
            <ul>
                <li><a href="admin_dashboard.php">Dashboard</a></li>
                <li><a href="manage_users.php">Manage Users</a></li>
                <li><a href="add_users.php">Add User</a></li>
                <li><a href="manage_course.php">Manage Courses</a></li>
                <li><a href="add_course.php">Add Course</a></li>
                <li><a href="manage_enrollments.php">Manage Enrollments</a></li>
                <li><a href="enroll_student.php">Enroll Student</a></li>
                
                <li><a href="logout.php">Logout</a></li>
            </ul>
        </aside>
        <div class="main-content">
            <section class="dashboard-section">
                <h2>Enroll Student in a Course</h2>
                <?php if ($enrollmentMessage): ?>
                    <p class="enrollment-message <?php echo strpos($enrollmentMessage, 'successfully') !== false || strpos($enrollmentMessage, 'complete') !== false ? 'success' : 'error'; ?>">
                        <?php echo nl2br(htmlspecialchars($enrollmentMessage)); ?>
                    </p>
                <?php endif; ?>
                <div class="form-container">
                    <h3>Upload Enrollment File</h3>
                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="file" name="enrollment_file" accept=".csv,.xlsx" required>
                        <button type="submit" name="upload_file">Upload and Enroll</button>
                        <a href="?download_template=1"><button type="button">Download CSV Template</button></a>
                        <label class="file-details">File should contain: student_code, name, course_code, course_name, group</label>
                    </form>
                </div>
                <div class="form-container">
                    <h3>Enroll Individual Student</h3>
                    <form method="POST" action="">
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
                            $courses_query = "SELECT course_id, course_name, group_number FROM courses ORDER BY course_name, group_number";
                            $result = $conn->query($courses_query);
                            while ($course = $result->fetch_assoc()) {
                                echo "<option value='{$course['course_id']}'>" . htmlspecialchars($course['course_name']) . " (Group {$course['group_number']})</option>";
                            }
                            ?>
                        </select>
                        <label for="group_number">Enter Group Number:</label>
                        <input type="text" name="group_number" id="group_number" required placeholder="Enter group number" pattern="\d+" title="Group number must be numeric">
                        <button type="submit" name="enroll_student">Enroll Student</button>
                    </form>
                </div>
            </section>
        </div>
    </div>
    <footer>
        <p>© <?php echo date("Y"); ?> University Admin System. All rights reserved.</p>
    </footer>
</body>
</html>
<?php
$conn->close();
?>