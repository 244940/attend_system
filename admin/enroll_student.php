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
        $courseQuery = "SELECT course_id, course_name, course_code, group_number, semester, c_year 
                       FROM courses";
        $result = $conn->query($courseQuery);
        while ($row = $result->fetch_assoc()) {
            $group_key = $row['course_name'] . '_' . strval($row['group_number']);
            $validCourses[$group_key] = [
                'course_id' => $row['course_id'],
                'course_code' => $row['course_code'],
                'group_number' => $row['group_number'],
                'semester' => $row['semester'],
                'c_year' => $row['c_year']
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
    $query = "SELECT student_id, name, email FROM students WHERE citizen_id = ? OR name = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $student_code, $student_name);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? ['student_id' => $row['student_id'], 'name' => $row['name'], 'email' => $row['email']] : null;
}

function enrollStudent($student_id, $course_id, $group_number, $conn) {
    $conn->begin_transaction();
    try {
        // Verify course
        $course_verify_query = "SELECT course_id, course_name, course_code, semester, c_year 
                               FROM courses 
                               WHERE course_id = ? AND group_number = ?";
        $course_verify_stmt = $conn->prepare($course_verify_query);
        $course_verify_stmt->bind_param("ii", $course_id, $group_number);
        $course_verify_stmt->execute();
        $course_verify_result = $course_verify_stmt->get_result();
        
        if ($course_verify_result->num_rows === 0) {
            $course_verify_stmt->close();
            $conn->rollback();
            return "Invalid course ID $course_id or group number $group_number.";
        }
        $course_info = $course_verify_result->fetch_assoc();
        $course_name = $course_info['course_name'];
        $course_code = $course_info['course_code'];
        $semester = $course_info['semester'];
        $c_year = $course_info['c_year'];
        $course_verify_stmt->close();

        // Fetch student email and name
        $student_query = "SELECT email, name FROM students WHERE student_id = ?";
        $student_stmt = $conn->prepare($student_query);
        $student_stmt->bind_param("i", $student_id);
        $student_stmt->execute();
        $student_result = $student_stmt->get_result();
        if ($student_result->num_rows === 0) {
            $student_stmt->close();
            $conn->rollback();
            return "Student ID $student_id not found.";
        }
        $student_info = $student_result->fetch_assoc();
        $student_email = $student_info['email'];
        $student_name = $student_info['name'];
        $student_stmt->close();

        // Fetch schedules
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

        // Check for schedule conflicts
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

        // Insert enrollment
        $enroll_query = "INSERT INTO enrollments (student_id, course_id, group_number) VALUES (?, ?, ?)";
        $enroll_stmt = $conn->prepare($enroll_query);
        $enroll_stmt->bind_param("iii", $student_id, $course_id, $group_number);
        
        if (!$enroll_stmt->execute()) {
            throw new Exception("Error enrolling student: " . $conn->error);
        }
        $enroll_stmt->close();

        // Insert student schedules
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

        // Send email via Flask API
        $flask_api_url = 'http://127.0.0.1:5000/api/send-email';
        $email_subject = "แจ้งเตือนการลงทะเบียนเรียน: " . htmlspecialchars($course_name);
        $schedule_text = empty($course_schedules) ? 'ยังไม่กำหนดตาราง' : implode(', ', array_map(function($sched) {
            return getDayNameThai($sched['day_of_week']) . ' ' . substr($sched['start_time'], 0, 5) . '-' . substr($sched['end_time'], 0, 5);
        }, $course_schedules));
        $email_body_template = "เรียน $student_name\n\n" .
                              "คุณได้ลงทะเบียนเรียนวิชา " . htmlspecialchars($course_name) . " (" . htmlspecialchars($course_code) . ")\n" .
                              "กลุ่ม: $group_number\n" .
                              "ภาคการศึกษา: " . getSemesterThai($semester) . "/" . $c_year . "\n" .
                              "ตารางเรียน: $schedule_text\n" .
                              "กรุณาตรวจสอบตารางเรียนและเตรียมตัวให้พร้อม";

        $post_fields = json_encode([
            'to' => $student_email,
            'subject' => $email_subject,
            'body_template' => $email_body_template,
            'course_name' => $course_name
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $flask_api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $flask_response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($flask_response === false) {
            error_log("cURL error when sending enrollment email: $curl_error");
            // Continue with commit despite email failure
        } else {
            $flask_result = json_decode($flask_response, true);
            if ($http_code >= 200 && $http_code < 300) {
                error_log("Enrollment email sent via Flask: " . ($flask_result['message'] ?? 'No message'));
            } else {
                error_log("Flask API error (HTTP $http_code): " . ($flask_result['error'] ?? $flask_response));
            }
        }

        $conn->commit();
        return "Student enrolled successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        return "Failed to enroll student: " . $e->getMessage();
    }
}

function getDayNameThai($day) {
    $dayMapping = [
        'Monday' => 'วันจันทร์',
        'Tuesday' => 'วันอังคาร',
        'Wednesday' => 'วันพุธ',
        'Thursday' => 'วันพฤหัสบดี',
        'Friday' => 'วันศุกร์',
        'Saturday' => 'วันเสาร์',
        'Sunday' => 'วันอาทิตย์'
    ];
    return $dayMapping[$day] ?? $day;
}

function getSemesterThai($semester) {
    $semesterMap = ['first' => '1', 'second' => '2', 'summer' => 'ฤดูร้อน'];
    return $semesterMap[$semester] ?? $semester;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ลงทะเบียนนิสิต</title>
    <style>
        body, html { 
            margin: 0; 
            padding: 0; 
            font-family: 'Sarabun', Arial, sans-serif; 
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
            list-style: none; 
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
            background: white; 
            border-radius: 8px; 
            box-shadow: 0 0 10px rgba(0,0,0,0.1); 
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
        input[type="file"], input[type="text"], select { 
            width: calc(100% - 22px); 
            padding: 10px; 
            margin-bottom: 15px; 
            border-radius: 4px; 
            border: 1px solid #ccc; 
        }
        button { 
            background: #3498db; 
            color: white; 
            padding: 10px 15px; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer; 
            margin-right: 10px; 
        }
        button:hover { 
            background: #2980b9; 
        }
        .file-details { 
            font-size: 12px; 
            color: #666; 
        }
        .enrollment-message { 
            padding: 10px; 
            margin-bottom: 20px; 
            border-radius: 5px; 
        }
        .enrollment-message.success { 
            background: #dff0d8; 
            color: #3c763d; 
        }
        .enrollment-message.error { 
            background: #f2dede; 
            color: #a94442; 
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
        <h1>ลงทะเบียนนิสิต</h1>
        <div class="user-info">
            <span><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
        </div>
    </div>
    <div class="admin-container">
        <aside class="sidebar">
            <ul>
                <li><a href="admin_dashboard.php">แดชบอร์ด</a></li>
                <li><a href="manage_users.php">จัดการผู้ใช้</a></li>
                <li><a href="add_users.php">เพิ่มผู้ใช้</a></li>
                <li><a href="manage_course.php">จัดการวิชา</a></li>
                <li><a href="add_course.php">เพิ่มวิชา</a></li>
                <li><a href="manage_enrollments.php">จัดการการลงทะเบียน</a></li>
                <li><a href="enroll_student.php">ลงทะเบียนนิสิต</a></li>
                <li><a href="logout.php">ออกจากระบบ</a></li>
            </ul>
        </aside>
        <div class="main-content">
            <section class="dashboard-section">
                <h2>ลงทะเบียนนิสิตในวิชา</h2>
                <?php if ($enrollmentMessage): ?>
                    <p class="enrollment-message <?php echo strpos($enrollmentMessage, 'successfully') !== false || strpos($enrollmentMessage, 'complete') !== false ? 'success' : 'error'; ?>">
                        <?php echo nl2br(htmlspecialchars($enrollmentMessage)); ?>
                    </p>
                <?php endif; ?>
                <div class="form-container">
                    <h3>อัปโหลดไฟล์การลงทะเบียน</h3>
                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="file" name="enrollment_file" accept=".csv,.xlsx" required>
                        <button type="submit" name="upload_file">อัปโหลดและลงทะเบียน</button>
                        <a href="?download_template=1"><button type="button">ดาวน์โหลดเทมเพลต CSV</button></a>
                        <label class="file-details">ไฟล์ต้องประกอบด้วย: student_code, name, course_code, course_name, group</label>
                    </form>
                </div>
                <div class="form-container">
                    <h3>ลงทะเบียนนิสิตรายบุคคล</h3>
                    <form method="POST" action="">
                        <label for="student_id">เลือกนิสิต:</label>
                        <select name="student_id" id="student_id" required>
                            <option value="">เลือกนิสิต</option>
                            <?php
                            $students_query = "SELECT student_id, name FROM students ORDER BY name";
                            $result = $conn->query($students_query);
                            if ($result && $result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    echo '<option value="' . htmlspecialchars($row['student_id']) . '">' . htmlspecialchars($row['name']) . '</option>';
                                }
                            } else {
                                echo '<option value="">ไม่มีนิสิต</option>';
                            }
                            ?>
                        </select>
                        <label for="course_id">เลือกวิชา:</label>
                        <select name="course_id" id="course_id" required>
                            <option value="">เลือกวิชา</option>
                            <?php
                            $courses_query = "SELECT course_id, course_name, group_number FROM courses ORDER BY course_name, group_number";
                            $result = $conn->query($courses_query);
                            while ($course = $result->fetch_assoc()) {
                                echo "<option value='{$course['course_id']}'>" . htmlspecialchars($course['course_name']) . " (กลุ่ม {$course['group_number']})</option>";
                            }
                            ?>
                        </select>
                        <label for="group_number">ระบุหมายเลขกลุ่ม:</label>
                        <input type="text" name="group_number" id="group_number" required placeholder="ระบุหมายเลขกลุ่ม" pattern="\d+" title="หมายเลขกลุ่มต้องเป็นตัวเลข">
                        <button type="submit" name="enroll_student">ลงทะเบียนนิสิต</button>
                    </form>
                </div>
            </section>
        </div>
    </div>
    <footer>
        <p>© <?php echo date("Y"); ?> ระบบบริหารมหาวิทยาลัย. สงวนลิขสิทธิ์.</p>
    </footer>
</body>
</html>
<?php
$conn->close();
?>