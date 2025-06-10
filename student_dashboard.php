<?php
session_start(); // เริ่มต้นเซสชัน
error_reporting(E_ALL); // เปิดการรายงานข้อผิดพลาดทั้งหมด
ini_set('display_errors', 1); // แสดงข้อผิดพลาดบนหน้าจอ
require 'database_connection.php'; // เรียกไฟล์เชื่อมต่อฐานข้อมูล

// --- การตรวจสอบสิทธิ์ผู้ใช้ ---
// ตรวจสอบว่าผู้ใช้เข้าสู่ระบบแล้วและมีบทบาทเป็น 'student'
if (!isset($_SESSION['student_id']) || $_SESSION['user_role'] !== 'student') {
    // บันทึกข้อผิดพลาดลงใน log หากไม่มีสิทธิ์เข้าถึง
    error_log("Access denied: student_id or user_role not set. Session: " . print_r($_SESSION, true));
    header("Location: login.php"); // เปลี่ยนเส้นทางไปหน้า login.php
    exit(); // หยุดการทำงานของสคริปต์
}

// --- การดึงข้อมูลนักเรียน ---
// เตรียมคำสั่ง SQL เพื่อดึง student_id และ name จากตาราง students
$stmt = $conn->prepare("SELECT student_id, name FROM students WHERE student_id = ?");
$stmt->bind_param("i", $_SESSION['student_id']); // ผูกค่า student_id จาก session
$stmt->execute(); // รันคำสั่ง
$result = $stmt->get_result(); // รับผลลัพธ์

// ตรวจสอบว่าพบข้อมูลนักเรียนหรือไม่
if ($result->num_rows === 0) {
    // บันทึกข้อผิดพลาดและหยุดการทำงานหากไม่พบนักเรียน
    error_log("Student not found for student_id: {$_SESSION['student_id']}");
    die("Error: Student not found.");
}

$student_data = $result->fetch_assoc(); // ดึงข้อมูลนักเรียนมาเป็น associative array
$student_id = $student_data['student_id']; // เก็บ student_id
$student_name = $student_data['name']; // เก็บชื่อนักเรียน
$stmt->close(); // ปิด prepared statement

// --- การดึงรายวิชาที่นักเรียนลงทะเบียน ---
// เตรียมคำสั่ง SQL เพื่อดึงข้อมูลรายวิชาที่นักเรียนลงทะเบียน
$get_courses_stmt = $conn->prepare("
    SELECT c.course_id, c.course_name, c.course_code, c.day_of_week, c.semester, c.c_year
    FROM courses AS c
    JOIN enrollments AS e ON c.course_id = e.course_id
    WHERE e.student_id = ?
    ORDER BY c.semester
");
$get_courses_stmt->bind_param("i", $student_id); // ผูกค่า student_id
$get_courses_stmt->execute(); // รันคำสั่ง
$courses = $get_courses_stmt->get_result()->fetch_all(MYSQLI_ASSOC); // ดึงผลลัพธ์ทั้งหมด
$get_courses_stmt->close(); // ปิด prepared statement
$conn->close(); // ปิดการเชื่อมต่อฐานข้อมูล

// --- ฟังก์ชัน PHP สำหรับจัดการวันที่ (ไม่ถูกใช้ใน JavaScript) ---
// ฟังก์ชันนี้ถูกทำซ้ำใน JavaScript ด้วย
function getSemesterDateRange($semester, $year) {
    if ($semester == 1) {
        return ['start' => "2024-06-01", 'end' => "2024-10-31"];
    } elseif ($semester == 2) {
        return ['start' => "2024-11-25", 'end' => "2025-03-31"];
    } elseif ($semester == 3) {
        return ['start' => "2025-04-01", 'end' => "2025-06-30"];
    } else {
        return ['start' => null, 'end' => null];
    }
}

// ฟังก์ชันนี้ถูกทำซ้ำใน JavaScript ด้วย
function getClassDates($dayOfWeek, $startDate, $endDate) {
    $dates = [];
    $current = strtotime($startDate);
    $end = strtotime($endDate);

    $dayMapping = [
        'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4,
        'Friday' => 5, 'Saturday' => 6, 'Sunday' => 0
    ];

    $targetDay = $dayMapping[$dayOfWeek];

    while ($current <= $end) {
        if (date('N', $current) == $targetDay) { // date('N') ให้ค่า 1 (จันทร์) ถึง 7 (อาทิตย์)
            $dates[] = date('Y-m-d', $current);
        }
        $current = strtotime('+1 day', $current);
    }
    return $dates;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <div class="header flex items-center justify-between">
        <div class="flex items-center space-x-4">
            <img src="assets/logo.png" alt="Logo" class="w-16 h-16">
            <div>
                <h1 class="text-xl font-bold">ระบบเช็คชื่อนิสิตมหาวิทยาลัยเกษตรศาสตร์</h1>
                <p class="text-sm">นิสิต: <?php echo htmlspecialchars($student_name); ?></p>
            </div>
            <button onclick="logout()" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded">
                ออกจากระบบ
            </button>
        </div>
    </div>

    <div class="container mx-auto px-4 py-8">
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-bold mb-4">รายวิชาที่ลงทะเบียน</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($courses as $course): ?>
                    <button onclick="showAttendance('<?php echo htmlspecialchars($course['course_id']); ?>', '<?php echo htmlspecialchars($course['day_of_week']); ?>', '<?php echo htmlspecialchars($course['semester']); ?>', '<?php echo htmlspecialchars($course['c_year']); ?>')" class="course-button bg-green-500 text-white p-4 rounded-lg shadow hover:bg-green-600 transition duration-200">
                        <div class="font-bold"><?php echo htmlspecialchars($course['course_code']); ?></div>
                        <div><?php echo htmlspecialchars($course['course_name']); ?></div>
                        <div>Semester: <?php echo htmlspecialchars($course['semester']); ?> | Year: <?php echo htmlspecialchars($course['c_year']); ?></div>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="attendanceSection" class="bg-white rounded-lg shadow-md p-6" style="display:none;">
            <h3 class="font-bold mb-4">Attendance Summary</h3>
            <table class="attendance-table w-full border-collapse shadow-md">
                <thead>
                    <tr>
                        <th class="border px-4 py-2">Date</th>
                        <th class="border px-4 py-2">Status</th>
                    </tr>
                </thead>
                <tbody id="attendanceTableBody">
                    </tbody>
            </table>
        </div>
    </div>

    <script>
        // ฟังก์ชันเมื่อคลิกปุ่มวิชา เพื่อแสดงข้อมูลการเข้าเรียน
        function showAttendance(courseId, dayOfWeek, semester, year) {
            const attendanceTableBody = document.getElementById('attendanceTableBody');
            attendanceTableBody.innerHTML = '<tr><td colspan="2" class="text-center">Loading...</td></tr>'; // แสดงสถานะกำลังโหลด

            // รับช่วงวันที่ของภาคเรียนจากฟังก์ชัน JavaScript
            const { start, end } = getSemesterDateRange(semester, year);
            // คำนวณวันที่เรียนทั้งหมดในภาคเรียนนั้นๆ
            const validClassDates = getClassDates(dayOfWeek, start, end);

            // ส่งคำขอ Fetch (AJAX) ไปยัง get_attendance.php
            fetch(`get_attendance.php?course_id=${courseId}&dates=${JSON.stringify(validClassDates)}`)
                .then(response => response.json()) // แปลงการตอบกลับเป็น JSON
                .then(data => {
                    attendanceTableBody.innerHTML = ''; // ล้างข้อมูลเก่าในตาราง
                    // วนลูปผ่านวันที่เรียนทั้งหมด
                    validClassDates.forEach(date => {
                        // กำหนดสถานะ ถ้ามีข้อมูลจาก server ใช้ข้อมูลนั้น ถ้าไม่มีใช้ 'None'
                        const status = data[date] !== undefined ? data[date] : 'None';
                        const row = attendanceTableBody.insertRow(); // สร้างแถวใหม่ในตาราง
                        row.innerHTML = `<td class="border px-4 py-2">${date}</td><td class="border px-4 py-2">${status}</td>`; // ใส่ข้อมูลวันที่และสถานะ
                    });
                    document.getElementById('attendanceSection').style.display = 'block'; // แสดงส่วนตารางการเข้าเรียน
                })
                .catch(error => {
                    console.error("Error:", error); // แสดงข้อผิดพลาดใน console
                    attendanceTableBody.innerHTML = `<tr><td colspan="2" class="text-center text-red-500">Error loading data</td></tr>`; // แสดงข้อความผิดพลาดในตาราง
                });
        }

        // ฟังก์ชันสำหรับการออกจากระบบ
        function logout() {
            window.location.href = 'logout.php'; // เปลี่ยนเส้นทางไปหน้า logout.php
        }

        // ฟังก์ชัน JavaScript สำหรับกำหนดช่วงวันที่ของแต่ละภาคเรียน (เหมือนกับ PHP)
        function getSemesterDateRange(semester, year) {
            if (semester == 1) {
                return { start: "2024-06-01", end: "2024-10-31" };
            } else if (semester == 2) {
                return { start: "2024-11-25", end: "2025-03-31" };
            } else if (semester == 3) {
                return { start: "2025-04-01", end: "2025-06-30" };
            }
            return { start: null, end: null };
        }

        // ฟังก์ชัน JavaScript สำหรับคำนวณวันที่เรียนทั้งหมดในแต่ละสัปดาห์ (เหมือนกับ PHP)
        function getClassDates(dayOfWeek, startDate, endDate) {
            const dates = [];
            const currentDate = new Date(startDate);
            const endDateObj = new Date(endDate);

            // แผนที่ชื่อวันเป็นตัวเลข (0=อาทิตย์, 1=จันทร์, ...)
            const dayMapping = {
                'Monday': 1, 'Tuesday': 2, 'Wednesday': 3, 'Thursday': 4,
                'Friday': 5, 'Saturday': 6, 'Sunday': 0
            };

            const targetDayIndex = dayMapping[dayOfWeek];

            // วนลูปจากวันที่เริ่มต้นจนถึงวันที่สิ้นสุด
            while (currentDate <= endDateObj) {
                if (currentDate.getDay() === targetDayIndex) { // ตรวจสอบว่าเป็นวันเป้าหมายหรือไม่
                    dates.push(currentDate.toISOString().split('T')[0]); // เพิ่มวันที่เข้าสู่ array (format YYYY-MM-DD)
                }
                currentDate.setDate(currentDate.getDate() + 1); // เพิ่มไป 1 วัน
            }
            return dates;
        }
    </script>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap');
        * { font-family: 'Sarabun', sans-serif; } /* กำหนด Font เป็น Sarabun */
        .header { background-color: #71b773; padding: 1rem; color: white; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .attendance-table { width: 100%; border-collapse: collapse; margin-top: 1rem; background-color: white; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
        .attendance-table th { background-color: #f9e69e; font-weight: bold; }
        .attendance-table th, .attendance-table td { padding: 0.75rem; text-align: left; border: 1px solid #e2e8f0; }
        .attendance-table tbody tr:nth-child(even) { background-color: #f8f9fa; }
        .attendance-table tbody tr:hover { background-color: #f5f5f5; }
        .course-button { /* สไตล์สำหรับปุ่มวิชา */ }
        .course-button:hover { /* สไตล์เมื่อโฮเวอร์ */ }
    </style>
</body>
</html>
