<?php
session_start();
require '../database_connection.php';

// PHP Spreadsheet

require '../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;

// ตรวจสอบว่า font THSarabunNew มีอยู่
$font_path = '../vendor/tecnickcom/tcpdf/fonts/thsarabunnew.php';
if (!file_exists($font_path)) {
    error_log("THSarabunNew font not found in $font_path. Please run convert_font.php.");
}

// Check if user is an admin
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

// Redirect non-admin users
if (!isAdmin()) {
    error_log("Access denied: Not an admin. Session: " . print_r($_SESSION, true));
    header("Location: ../login.php");
    exit();
}

$enrollmentMessage = '';
$selected_course_id = null;
$selected_group_number = null;
$selected_course_name = '';
$selected_course_code = '';

// Handle course selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_course'])) {
    $course_key = $_POST['course_key'] ?? '';
    if ($course_key && $course_key !== 'all') {
        $parts = explode('_', $course_key);
        if (count($parts) >= 4) {
            $course_id = array_shift($parts);
            $group_number = array_shift($parts);
            $course_code = array_pop($parts);
            $course_name = implode('_', $parts);
            $selected_course_id = $course_id;
            $selected_group_number = $group_number;
            $selected_course_name = urldecode($course_name);
            $selected_course_code = urldecode($course_code);
            $_SESSION['selected_course_id'] = $selected_course_id;
            $_SESSION['selected_group_number'] = $selected_group_number;
            $_SESSION['selected_course_name'] = $selected_course_name;
            $_SESSION['selected_course_code'] = $selected_course_code;
        } else {
            error_log("Invalid course_key format: $course_key");
            $enrollmentMessage = "Invalid course selection.";
        }
    } else {
        unset($_SESSION['selected_course_id'], $_SESSION['selected_group_number'], $_SESSION['selected_course_name'], $_SESSION['selected_course_code']);
    }
} elseif (isset($_SESSION['selected_course_id'], $_SESSION['selected_group_number'], $_SESSION['selected_course_name'], $_SESSION['selected_course_code'])) {
    $selected_course_id = $_SESSION['selected_course_id'];
    $selected_group_number = $_SESSION['selected_group_number'];
    $selected_course_name = $_SESSION['selected_course_name'];
    $selected_course_code = $_SESSION['selected_course_code'];
} else {
    unset($_SESSION['selected_course_id'], $_SESSION['selected_group_number'], $_SESSION['selected_course_name'], $_SESSION['selected_course_code']);
}

// Handle deletion of enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_enrollment'])) {
    $student_id = $_POST['student_id'] ?? null;
    $course_id = $_POST['course_id'] ?? null;
    $group_number = $_POST['group_number'] ?? null;

    if ($student_id && $course_id && $group_number) {
        $enrollmentMessage = deleteEnrollment($student_id, $course_id, $group_number, $conn);
    } else {
        error_log("Invalid deletion data: student_id=$student_id, course_id=$course_id, group_number=$group_number");
        $enrollmentMessage = "Invalid data provided for deletion.";
    }
}

// Handle export CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_csv'])) {
    $course_id = $_POST['course_id'] ?? null;
    $group_number = $_POST['group_number'] ?? null;
    $course_name = $_POST['course_name'] ?? 'course';
    $course_code = $_POST['course_code'] ?? 'code';

    if ($course_id && $group_number) {
        exportToCsv($conn, $course_id, $group_number, $course_name, $course_code);
        exit();
    } else {
        $enrollmentMessage = "Invalid data for CSV export.";
    }
}

// Handle export Excel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_excel'])) {
    $course_id = $_POST['course_id'] ?? null;
    $group_number = $_POST['group_number'] ?? null;
    $course_name = $_POST['course_name'] ?? 'course';
    $course_code = $_POST['course_code'] ?? 'code';

    if ($course_id && $group_number) {
        exportToExcel($conn, $course_id, $group_number, $course_name, $course_code);
        exit();
    } else {
        $enrollmentMessage = "Invalid data for Excel export.";
    }
}

// Handle export PDF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_pdf'])) {
    $course_id = $_POST['course_id'] ?? null;
    $group_number = $_POST['group_number'] ?? null;
    $course_name = $_POST['course_name'] ?? 'course';
    $course_code = $_POST['course_code'] ?? 'code';

    if ($course_id && $group_number) {
        exportToPdf($conn, $course_id, $group_number, $course_name, $course_code);
        exit();
    } else {
        $enrollmentMessage = "Invalid data for PDF export.";
    }
}

function deleteEnrollment($student_id, $course_id, $group_number, $conn) {
    $conn->begin_transaction();
    try {
        $delete_enrollment_query = "DELETE FROM enrollments WHERE student_id = ? AND course_id = ? AND group_number = ?";
        $delete_enrollment_stmt = $conn->prepare($delete_enrollment_query);
        $delete_enrollment_stmt->bind_param("iii", $student_id, $course_id, $group_number);
        if (!$delete_enrollment_stmt->execute()) {
            throw new Exception("Error deleting enrollment: " . $conn->error);
        }
        $delete_enrollment_stmt->close();

        $delete_schedule_query = "DELETE FROM student_schedules WHERE student_id = ? AND course_id = ?";
        $delete_schedule_stmt = $conn->prepare($delete_schedule_query);
        $delete_schedule_stmt->bind_param("ii", $student_id, $course_id);
        if (!$delete_schedule_stmt->execute()) {
            throw new Exception("Error deleting student schedule: " . $conn->error);
        }
        $delete_schedule_stmt->close();

        $conn->commit();
        return "Enrollment deleted successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Delete enrollment failed: " . $e->getMessage());
        return "Failed to delete enrollment: " . $e->getMessage();
    }
}

function getEnrolledStudents($conn, $course_id = null, $group_number = null) {
    $query = "SELECT e.student_id, c.course_id, c.course_name, c.course_code, s.name AS student_name, s.email, e.group_number 
              FROM courses c 
              JOIN enrollments e ON c.course_id = e.course_id 
              JOIN students s ON e.student_id = s.student_id";
    
    $params = [];
    $types = '';
    if ($course_id !== null && $group_number !== null) {
        $query .= " WHERE c.course_id = ? AND e.group_number = ?";
        $params = [$course_id, $group_number];
        $types = "ii";
    }
    
    $query .= " ORDER BY s.name";
    
    $stmt = $conn->prepare($query);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $enrollments = [];
    while ($row = $result->fetch_assoc()) {
        $course_id = $row['course_id'];
        $group_number = $row['group_number'];
        $key = $course_id . '_' . $group_number;
        if (!isset($enrollments[$key])) {
            $enrollments[$key] = [
                'course_name' => $row['course_name'],
                'course_code' => $row['course_code'],
                'group_number' => $group_number,
                'students' => []
            ];
        }
        $enrollments[$key]['students'][] = [
            'student_id' => $row['student_id'],
            'student_name' => $row['student_name'],
            'email' => $row['email'],
            'course_id' => $row['course_id']
        ];
    }
    $stmt->close();
    return $enrollments;
}

function getCourses($conn) {
    $query = "SELECT course_id, course_name, course_code, group_number 
              FROM courses 
              ORDER BY course_name, group_number";
    $result = $conn->query($query);
    $courses = [];
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
    return $courses;
}

function exportToCsv($conn, $course_id, $group_number, $course_name, $course_code) {
    $enrollments = getEnrolledStudents($conn, $course_id, $group_number);
    $filename = "enrollments_{$course_code}_group{$group_number}.csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel compatibility
    
    fputcsv($output, ['Group', 'Student ID', 'Student Name', 'Email']);
    
    if (!empty($enrollments)) {
        foreach ($enrollments as $course_data) {
            foreach ($course_data['students'] as $student) {
                fputcsv($output, [
                    $course_data['group_number'],
                    $student['student_id'],
                    $student['student_name'],
                    $student['email']
                ]);
            }
        }
    }
    
    fclose($output);
}

function exportToExcel($conn, $course_id, $group_number, $course_name, $course_code) {
    $enrollments = getEnrolledStudents($conn, $course_id, $group_number);
    $filename = "enrollments_{$course_code}_group{$group_number}.xlsx";
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Set headers
    $sheet->setCellValue('A1', 'Group');
    $sheet->setCellValue('B1', 'Student ID');
    $sheet->setCellValue('C1', 'Student Name');
    $sheet->setCellValue('D1', 'Email');
    
    // Populate data
    $row = 2;
    if (!empty($enrollments)) {
        foreach ($enrollments as $course_data) {
            foreach ($course_data['students'] as $student) {
                $sheet->setCellValue('A' . $row, $course_data['group_number']);
                $sheet->setCellValue('B' . $row, $student['student_id']);
                $sheet->setCellValue('C' . $row, $student['student_name']);
                $sheet->setCellValue('D' . $row, $student['email']);
                $row++;
            }
        }
    }
    
    // Auto-size columns
    foreach (range('A', 'D') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    // Set headers for Excel download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
}

function exportToPdf($conn, $course_id, $group_number, $course_name, $course_code) {
    $enrollments = getEnrolledStudents($conn, $course_id, $group_number);
    $filename = "enrollments_{$course_code}_group{$group_number}.pdf";
    
    // ตรวจสอบว่า font มีอยู่
    $font_path = '../vendor/tecnickcom/tcpdf/fonts/thsarabunnew.php';
    if (!file_exists($font_path)) {
        error_log("THSarabunNew font not found in $font_path. Falling back to freeserif.");
        $font = 'freeserif'; // ใช้ font สำรอง
    } else {
        $font = 'thsarabunnew';
    }

    // สร้าง PDF ด้วย TCPDF
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // ตั้งค่าเอกสาร
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('University Admin System');
    $pdf->SetTitle("Enrollment List - {$course_name} ({$course_code}) Group {$group_number}");
    $pdf->SetSubject('Enrollment List');
    
    // ตั้งค่า font
    $pdf->SetFont($font, '', 14);
    
    // เพิ่มหน้า
    $pdf->AddPage();
    
    // เขียนหัวข้อ
    $pdf->Write(0, "Enrollment List: {$course_name} ({$course_code}) Group {$group_number}\n\n", '', 0, 'C', true);
    
    // สร้างตาราง HTML
    $html = '<table border="1" cellpadding="5">
                <thead>
                    <tr style="background-color:#3498db;color:white;">
                        <th>Group</th>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th>Email</th>
                    </tr>
                </thead>
                <tbody>';
    
    if (!empty($enrollments)) {
        foreach ($enrollments as $course_data) {
            foreach ($course_data['students'] as $student) {
                $html .= '<tr>';
                $html .= '<td>' . htmlspecialchars($course_data['group_number']) . '</td>';
                $html .= '<td>' . htmlspecialchars($student['student_id']) . '</td>';
                $html .= '<td>' . htmlspecialchars($student['student_name']) . '</td>';
                $html .= '<td>' . htmlspecialchars($student['email']) . '</td>';
                $html .= '</tr>';
            }
        }
    } else {
        $html .= '<tr><td colspan="4">No enrollments found.</td></tr>';
    }
    
    $html .= '</tbody></table>';
    
    // เขียน HTML ลงใน PDF
    $pdf->writeHTML($html, true, false, true, false, '');
    
    // ส่งออก PDF
    $pdf->Output($filename, 'D');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Enrollments</title>
    <style>
        body, html { margin: 0; padding: 0; font-family: Arial, sans-serif; height: 100%; display: flex; flex-direction: column; background-image: url('assets/bb.jpg'); background-size: cover; background-position: center; }
        .top-bar { width: 100%; background-color: #2980b9; color: white; padding: 15px 20px; text-align: left; box-shadow: 0 2px 4px rgba(0,0,0,0.2); display: flex; justify-content: space-between; align-items: center; }
        .top-bar h1 { margin: 0; font-size: 24px; }
        .admin-container { display: flex; flex: 1; width: 100%; height: calc(100vh - 70px); background: rgba(255, 255, 255, 0.9); }
        .sidebar { width: 250px; background-color: #2c3e50; color: white; padding: 20px; display: flex; flex-direction: column; align-items: center; height: 100%; }
        .sidebar ul { list-style: none; padding: 0; width: 100%; }
        .sidebar ul li { margin: 15px 0; text-align: center; }
        .sidebar ul li a { color: white; text-decoration: none; display: block; padding: 10px; transition: background 0.3s; }
        .sidebar ul li a:hover { background-color: #34495e; border-radius: 5px; }
        .main-content { flex: 1; padding: 20px; display: flex; flex-direction: column; align-items: center; background-color: #ecf0f1; height: 100%; overflow-y: auto; }
        .course-buttons { background: white; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 20px; width: 100%; max-width: 800px; }
        .course-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        .course-button { background: #3498db; color: white; padding: 10px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.9em; text-align: center; transition: background 0.3s; white-space: normal; word-wrap: break-word; height: 60px; }
        .course-button:hover { background: #2980b9; }
        .course-button.all-courses { background: #2ecc71; }
        .course-button.all-courses:hover { background: #27ae60; }
        .course-button.active { background: #2980b9; font-weight: bold; }
        .enrollment-list { background: white; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 20px; width: 100%; max-width: 800px; }
        h2, h3 { color: #2980b9; }
        .course-item { margin-bottom: 20px; }
        .course-name { font-weight: bold; font-size: 1.2em; }
        .enrollment-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .enrollment-table th, .enrollment-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .enrollment-table th { background-color: #3498db; color: white; }
        .enrollment-table tr:nth-child(even) { background-color: #f9f9f9; }
        .enrollment-table tr:hover { background-color: #f1f1f1; }
        .delete-button { background: #e74c3c; color: white; padding: 5px 10px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.9em; }
        .delete-button:hover { background: #c0392b; }
        .export-button { background: #2ecc71; color: white; padding: 5px 10px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.9em; margin-right: 5px; }
        .export-button:hover { background: #27ae60; }
        .export-button.excel { background: #f1c40f; }
        .export-button.excel:hover { background: #e1b307; }
        .export-button.pdf { background: #e74c3c; }
        .export-button.pdf:hover { background: #c0392b; }
        .enrollment-message { padding: 10px; margin-bottom: 20px; border-radius: 5px; width: 100%; max-width: 800px; }
        .enrollment-message.success { background: #dff0d8; color: #3c763d; }
        .enrollment-message.error { background: #f2dede; color: #a94442; }
        footer { text-align: center; padding: 10px; background-color: #34495e; color: white; width: 100%; }
        @media (max-width: 600px) {
            .course-grid { grid-template-columns: repeat(2, 1fr); }
            .enrollment-table { font-size: 0.8em; }
            .enrollment-table th, .enrollment-table td { padding: 5px; }
            .export-button { margin-bottom: 5px; }
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <h1>Manage Enrollments</h1>
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
                <li><a href="../logout.php">Logout</a></li>
            </ul>
        </aside>
        <div class="main-content">
            <section class="dashboard-section">
                <h2>Manage Enrollments</h2>
                <?php if ($enrollmentMessage): ?>
                    <p class="enrollment-message <?php echo strpos($enrollmentMessage, 'successfully') !== false ? 'success' : 'error'; ?>">
                        <?php echo htmlspecialchars($enrollmentMessage); ?>
                    </p>
                <?php endif; ?>
                <div class="course-buttons">
                    <h3>Select Course</h3>
                    <div class="course-grid">
                        <form method="POST" action="">
                            <input type="hidden" name="course_key" value="all">
                            <button type="submit" name="select_course" class="course-button all-courses <?php echo (!$selected_course_id && !$selected_group_number) ? 'active' : ''; ?>">All Courses</button>
                        </form>
                        <?php
                        $courses = getCourses($conn);
                        if (empty($courses)) {
                            echo "<p>No courses available.</p>";
                        } else {
                            foreach ($courses as $course):
                                $course_name_encoded = urlencode($course['course_name']);
                                $course_code_encoded = urlencode($course['course_code']);
                                $course_key = $course['course_id'] . '_' . $course['group_number'] . '_' . $course_name_encoded . '_' . $course_code_encoded;
                                $is_active = ($selected_course_id == $course['course_id'] && $selected_group_number == $course['group_number']) ? 'active' : '';
                        ?>
                            <form method="POST" action="">
                                <input type="hidden" name="course_key" value="<?php echo htmlspecialchars($course_key); ?>">
                                <button type="submit" name="select_course" class="course-button <?php echo $is_active; ?>">
                                    <?php echo htmlspecialchars($course['course_name']) . '<br>(' . htmlspecialchars($course['course_code']) . ', Group ' . $course['group_number'] . ')'; ?>
                                </button>
                            </form>
                        <?php endforeach; }
                        ?>
                    </div>
                </div>
                <div class="enrollment-list">
                    <h3>
                        <?php
                        if ($selected_course_id && $selected_group_number) {
                            echo 'Enrolled Students in ' . htmlspecialchars($selected_course_name) . ' (' . htmlspecialchars($selected_course_code) . ', Group ' . $selected_group_number . ')';
                        } else {
                            echo 'Enrolled Students (All Courses)';
                        }
                        ?>
                    </h3>
                    <?php
                    $enrollments = getEnrolledStudents($conn, $selected_course_id, $selected_group_number);
                    if (empty($enrollments)) {
                        echo "<p>No enrollments found for the selected course.</p>";
                    } else {
                        foreach ($enrollments as $key => $course_data):
                    ?>
                        <div class="course-item">
                            <p class="course-name"><?php echo htmlspecialchars($course_data['course_name']) . ' (' . htmlspecialchars($course_data['course_code']) . ', Group ' . htmlspecialchars($course_data['group_number']) . ')'; ?></p>
                            <div style="margin-bottom: 10px;">
                                <form method="POST" action="" style="display:inline;">
                                    <input type="hidden" name="course_id" value="<?php echo $course_data['students'][0]['course_id']; ?>">
                                    <input type="hidden" name="group_number" value="<?php echo $course_data['group_number']; ?>">
                                    <input type="hidden" name="course_name" value="<?php echo htmlspecialchars($course_data['course_name']); ?>">
                                    <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($course_data['course_code']); ?>">
                                    <button type="submit" name="export_csv" class="export-button">Export to CSV</button>
                                </form>
                                <form method="POST" action="" style="display:inline;">
                                    <input type="hidden" name="course_id" value="<?php echo $course_data['students'][0]['course_id']; ?>">
                                    <input type="hidden" name="group_number" value="<?php echo $course_data['group_number']; ?>">
                                    <input type="hidden" name="course_name" value="<?php echo htmlspecialchars($course_data['course_name']); ?>">
                                    <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($course_data['course_code']); ?>">
                                    <button type="submit" name="export_excel" class="export-button excel">Export to Excel</button>
                                </form>
                                <form method="POST" action="" style="display:inline;">
                                    <input type="hidden" name="course_id" value="<?php echo $course_data['students'][0]['course_id']; ?>">
                                    <input type="hidden" name="group_number" value="<?php echo $course_data['group_number']; ?>">
                                    <input type="hidden" name="course_name" value="<?php echo htmlspecialchars($course_data['course_name']); ?>">
                                    <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($course_data['course_code']); ?>">
                                    <button type="submit" name="export_pdf" class="export-button pdf">Export to PDF</button>
                                </form>
                            </div>
                            <table class="enrollment-table">
                                <thead>
                                    <tr>
                                        <th>Group</th>
                                        <th>Student ID</th>
                                        <th>Student Name</th>
                                        <th>Email</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($course_data['students'] as $student): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($course_data['group_number']); ?></td>
                                            <td><?php echo htmlspecialchars($student['student_id']); ?></td>
                                            <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                            <td><?php echo htmlspecialchars($student['email']); ?></td>
                                            <td>
                                                <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this enrollment for <?php echo htmlspecialchars($student['student_name']); ?>?');">
                                                    <input type="hidden" name="student_id" value="<?php echo $student['student_id']; ?>">
                                                    <input type="hidden" name="course_id" value="<?php echo $student['course_id']; ?>">
                                                    <input type="hidden" name="group_number" value="<?php echo $course_data['group_number']; ?>">
                                                    <button type="submit" name="delete_enrollment" class="delete-button">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php
                        endforeach;
                    }
                    ?>
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