<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'C:\xampp\logs\php_error_log');

require 'database_connection.php';

error_log("Session at teacher_dashboard.php: " . print_r($_SESSION, true));

if (!isset($_SESSION['teacher_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Redirecting to login.php: teacher_id or user_role not set correctly");
    $_SESSION['error_message'] = "กรุณาเข้าสู่ระบบในฐานะอาจารย์";
    header("Location: login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$teacher_name = $_SESSION['user_name'] ?? 'อาจารย์'; // ใช้ 'name' ตามตาราง teachers
error_log("Teacher ID: " . var_export($teacher_id, true));
error_log("Teacher Name: " . var_export($teacher_name, true)); // เพิ่มการล็อกเพื่อตรวจสอบ

$get_courses_stmt = $conn->prepare("
    SELECT 
        c.course_id,
        c.course_name,
        c.course_code,
        c.group_number,
        c.semester,
        c.c_year
    FROM courses c
    WHERE c.teacher_id = ?
");
if (!$get_courses_stmt) {
    error_log("Prepare failed for courses query: " . $conn->error);
    $_SESSION['error_message'] = "เกิดข้อผิดพลาดในการดึงข้อมูลวิชา";
    header("Location: teacher_dashboard.php");
    exit();
}
$get_courses_stmt->bind_param("i", $teacher_id);
if (!$get_courses_stmt->execute()) {
    error_log("Execute failed for courses query: " . $get_courses_stmt->error);
    $_SESSION['error_message'] = "เกิดข้อผิดพลาดในการดึงข้อมูลวิชา";
    header("Location: teacher_dashboard.php");
    exit();
}
$courses_result = $get_courses_stmt->get_result();
$courses = $courses_result->fetch_all(MYSQLI_ASSOC);
error_log("Courses fetched: " . print_r($courses, true));
$get_courses_stmt->close();

foreach ($courses as &$course) {
    $sched_stmt = $conn->prepare("
        SELECT schedule_id, day_of_week, start_time, end_time
        FROM schedules
        WHERE course_id = ?
    ");
    if (!$sched_stmt) {
        error_log("Prepare schedules failed for course_id {$course['course_id']}: " . $conn->error);
        continue;
    }
    $sched_stmt->bind_param("i", $course['course_id']);
    if (!$sched_stmt->execute()) {
        error_log("Execute schedules failed for course_id {$course['course_id']}: " . $sched_stmt->error);
        $sched_stmt->close();
        continue;
    }
    $sched_result = $sched_stmt->get_result();
    $course['schedules'] = $sched_result->fetch_all(MYSQLI_ASSOC);
    error_log("Schedules for course_id {$course['course_id']}: " . print_r($course['schedules'], true));
    $sched_stmt->close();
}
unset($course);

$days_query = "
    SELECT DISTINCT s.day_of_week 
    FROM schedules s
    JOIN courses c ON s.course_id = c.course_id
    WHERE c.teacher_id = ?
    ORDER BY FIELD(s.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')";
$days_stmt = $conn->prepare($days_query);
if ($days_stmt) {
    $days_stmt->bind_param("i", $teacher_id);
    $days_stmt->execute();
    $days_result = $days_stmt->get_result();
    $teaching_days = $days_result->fetch_all(MYSQLI_ASSOC);
    $days_stmt->close();
}

function mapSemesterToInt($semester) {
    $semesterMap = ['first' => 1, 'second' => 2, 'summer' => 3];
    return $semesterMap[strtolower($semester)] ?? 1;
}

function getFirstAndLastDateOfSemester($semester, $year) {
    $semester = mapSemesterToInt($semester);
    if ($semester == 1) {
        return [date('Y-m-d', strtotime("$year-06-24")), date('Y-m-d', strtotime("$year-11-04"))];
    } elseif ($semester == 2) {
        return [date('Y-m-d', strtotime("$year-11-25")), date('Y-m-d', strtotime(($year+1)."-03-31"))];
    } else {
        return [date('Y-m-d', strtotime("$year-04-21")), date('Y-m-d', strtotime("$year-06-04"))];
    }
}

function getAllTeachingDates($course, $startDate, $endDate) {
    $dates = [];
    if (empty($course['schedules'])) {
        error_log("No schedules for course_id: {$course['course_id']}");
        return $dates;
    }

    foreach ($course['schedules'] as $schedule) {
        $current = strtotime($startDate);
        $end = strtotime($endDate);
        while ($current <= $end) {
            if (date('l', $current) === $schedule['day_of_week']) {
                $dates[] = date('Y-m-d', $current);
            }
            $current = strtotime('+1 day', $current);
        }
    }
    $dates = array_unique($dates);
    sort($dates);
    error_log("Teaching dates for course_id {$course['course_id']}: " . json_encode($dates));
    return $dates;
}

$current_semester = 'first';
$current_year = date('Y');
if (!empty($courses)) {
    usort($courses, function($a, $b) {
        if ($a['c_year'] === $b['c_year']) {
            return mapSemesterToInt($b['semester']) - mapSemesterToInt($a['semester']);
        }
        return $b['c_year'] - $a['c_year'];
    });
    $current_semester = $courses[0]['semester'];
    $current_year = $courses[0]['c_year'];
}

list($semester_start, $semester_end) = getFirstAndLastDateOfSemester($current_semester, $current_year);

$teaching_schedule = [];
foreach ($courses as $course) {
    $dates = getAllTeachingDates($course, $semester_start, $semester_end);
    $teaching_schedule[$course['course_id']] = $dates;
}

$attendance_stats = ['present' => 0, 'late' => 0, 'absent' => 0, 'total' => 0];
foreach ($courses as $course) {
    $course_id = $course['course_id'];
    $stmt = $conn->prepare("
        SELECT a.status, COUNT(*) as count 
        FROM attendance a
        JOIN schedules s ON a.schedule_id = s.schedule_id
        WHERE s.course_id = ?
        GROUP BY a.status
    ");
    if (!$stmt) {
        error_log("Prepare failed for attendance stats query: " . $conn->error);
        continue;
    }
    $stmt->bind_param("i", $course_id);
    if (!$stmt->execute()) {
        error_log("Execute failed for attendance stats course_id=$course_id: " . $stmt->error);
        $stmt->close();
        continue;
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $status = strtolower($row['status']);
        if ($status === 'present') $attendance_stats['present'] += $row['count'];
        elseif ($status === 'late') $attendance_stats['late'] += $row['count'];
        elseif ($status === 'absent') $attendance_stats['absent'] += $row['count'];
    }
    $attendance_stats['total'] = $attendance_stats['present'] + $attendance_stats['late'] + $attendance_stats['absent'];
    $stmt->close();
}

function getDayNameThai($englishDay) {
    $dayMapping = [
        'Monday' => 'วันจันทร์', 'Tuesday' => 'วันอังคาร', 'Wednesday' => 'วันพุธ',
        'Thursday' => 'วันพฤหัสบดี', 'Friday' => 'วันศุกร์', 'Saturday' => 'วันเสาร์',
        'Sunday' => 'วันอาทิตย์'
    ];
    return $dayMapping[$englishDay] ?? $englishDay;
}

function getSemesterThai($semester) {
    $semesterMap = [
        'summer' => 'ภาคฤดูร้อน'
        'first' => 'ภาคการศึกษาที่ 1',
        'second' => 'ภาคการศึกษาที่ 2',

    ];
    return $semesterMap[strtolower($semester)] ?? $semester;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบจัดการการเข้าเรียน - หน้าอาจารย์</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap');
        * { font-family: 'Sarabun', sans-serif; }
        .header { background-color: #71b773; padding: 1rem; color: white; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .course-button { background-color: #4CAF50; color: white; padding: 0.75rem 1.5rem; border-radius: 0.5rem; transition: all 0.3s; border: none; cursor: pointer; margin: 0.5rem; width: 100%; max-width: 300px; text-align: left; }
        .course-button:hover { background-color: #45a049; transform: translateY(-2px); }
        .course-button.selected { background-color: #2E7D32; }
        .attendance-table { width: 100%; border-collapse: collapse; margin: 1rem 0; background-color: white; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
        .attendance-table th, .attendance-table td { padding: 0.75rem; text-align: left; border: 1px solid #e2e8f0; }
        .attendance-table th { background-color: #f9e69e; font-weight: 600; position: sticky; top: 0; z-index: 10; }
        .attendance-table tbody tr:nth-child(even) { background-color: #f8f9fa; }
        .attendance-table tbody tr:hover { background-color: #f5f5f5; }
        .chart-container { max-width: 600px; margin: 2rem auto; }
        .error-message { background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; text-align: center; }
        .edit-button { background-color: #007bff; color: white; padding: 0.5rem; border-radius: 0.25rem; cursor: pointer; }
        .edit-button:hover { background-color: #0056b3; }
        .add-button { background-color: #28a745; color: white; padding: 0.75rem 1.5rem; border-radius: 0.5rem; margin: 1rem 0; }
        .add-button:hover { background-color: #218838; }
        .status-present { background-color: rgba(76, 175, 80, 0.1); color: #2e7d32; }
        .status-late { background-color: rgba(240, 173, 78, 0.1); color: #f57c00; }
        .status-absent { background-color: rgba(217, 83, 79, 0.1); color: #c62828; }
    </style>
</head>
<body class="bg-gray-100">
    <div class="header flex items-center justify-between">
        <div class="flex items-center space-x-4">
            <img src="assets/logo.png" alt="Logo" class="w-16 h-16">
            <div>
                <h1 class="text-xl font-bold">ระบบเช็คชื่อนิสิตมหาวิทยาลัยเกษตรศาสตร์</h1>
                <p class="text-sm">อาจารย์: <?php echo htmlspecialchars($teacher_name); ?></p>
            </div>
        </div>
        <button onclick="logout()" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded">ออกจากระบบ</button>
    </div>

    <div class="container mx-auto px-4 py-8">
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="error-message"><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></div>
        <?php endif; ?>

        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-bold mb-4">รายวิชาที่สอน</h2>
            <?php if (empty($courses)): ?>
                <p class="text-center text-gray-500">ไม่มีรายวิชาที่สอนในขณะนี้</p>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($courses as $course): ?>
                        <button class="course-button" data-course-id="<?php echo htmlspecialchars($course['course_id']); ?>">
                            <div class="font-bold"><?php echo htmlspecialchars($course['course_code']); ?></div>
                            <div class="text-sm"><?php echo htmlspecialchars($course['course_name']); ?></div>
                            <div class="text-sm">
                                กลุ่ม <?php echo htmlspecialchars($course['group_number']); ?> |
                                <?php
                                if (!empty($course['schedules'])) {
                                    $schedule_texts = array_map(function($sched) {
                                        return htmlspecialchars(getDayNameThai($sched['day_of_week'])) . ' ' .
                                               substr($sched['start_time'], 0, 5) . '-' .
                                               substr($sched['end_time'], 0, 5);
                                    }, $course['schedules']);
                                    echo implode(' | ', $schedule_texts);
                                } else {
                                    echo 'ไม่มีตาราง';
                                }
                                ?>
                            </div>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold">เลือกวันที่</h2>
                <input type="date" id="selectedDate" class="border border-gray-300 rounded px-4 py-2" value="<?php echo date('Y-m-d'); ?>" onchange="selectDate(this.value)">
            </div>
        </div>

        <div id="faceScanSection" class="bg-white rounded-lg shadow-md p-6 mb-6" style="display: none;">
            <h2 class="text-xl font-bold mb-4">สแกนใบหน้าเพื่อเช็คชื่อ</h2>
            <div class="flex items-center justify-between mb-4">
                <div><span id="scanningCourseInfo"></span></div>
                <div>
                    <button id="startScanBtn" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded">เริ่มสแกน</button>
                    <button id="stopScanBtn" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded" style="display: none;">หยุดสแกน</button>
                </div>
            </div>
            <div class="relative">
                <video id="videoFeed" autoplay playsinline></video>
                <canvas id="overlayCanvas" style="display: none;"></canvas>
            </div>
            <div id="scanResult" class="text-center mt-4 font-medium"></div>
        </div>

        <button onclick="exportAttendance()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">ดาวน์โหลดข้อมูลการเข้าเรียน</button>

        <div id="attendanceSection" class="bg-white rounded-lg shadow-md p-6" style="display: none;">
            <div id="courseInfo" class="mb-4"></div>
            <button onclick="showAddAttendanceForm()" class="add-button">เพิ่มการเข้าเรียนด้วยตนเอง</button>
            <div id="addAttendanceForm" class="mb-4" style="display: none;">
                <h3 class="text-lg font-bold mb-2">เพิ่ม/แก้ไขการเข้าเรียน</h3>
                <form id="manualAttendanceForm">
                    <div class="mb-2">
                        <label class="block text-sm font-medium">รหัสนิสิต</label>
                        <input type="text" id="studentId" class="border border-gray-300 rounded px-4 py-2 w-full" required placeholder="พิมพ์รหัสนิสิต" oninput="suggestStudentIds()">
                        <div id="idSuggestions" class="border border-gray-300 rounded mt-1 max-h-40 overflow-y-auto hidden"></div>
                    </div>
                    <div class="mb-2">
                        <label class="block text-sm font-medium">ชื่อ-นามสกุล</label>
                        <input type="text" id="studentName" class="border border-gray-300 rounded px-4 py-2 w-full" required placeholder="พิมพ์ชื่อ" oninput="suggestStudentNames()">
                        <div id="nameSuggestions" class="border border-gray-300 rounded mt-1 max-h-40 overflow-y-auto hidden"></div>
                    </div>
                    <div class="mb-2">
                        <label class="block text-sm font-medium">สถานะ</label>
                        <select id="attendanceStatus" class="border border-gray-300 rounded px-4 py-2 w-full" required>
                            <option value="Present">มาเรียน</option>
                            <option value="Late">สาย</option>
                            <option value="Absent">ขาดเรียน</option>
                            <option value="Leave">ลา</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="block text-sm font-medium">เวลา (YYYY-MM-DD HH:MM:SS)</label>
                        <input type="datetime-local" id="scanTime" class="border border-gray-300 rounded px-4 py-2 w-full" required>
                    </div>
                    <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">บันทึก</button>
                    <button type="button" onclick="hideAddAttendanceForm()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded">ยกเลิก</button>
                </form>
            </div>
            <div class="mb-4">
                <h2 class="text-xl font-bold mb-2">เลือกวันที่เพื่อดูผลบันทึก</h2>
                <input type="date" id="viewDate" class="border border-gray-300 rounded px-4 py-2" onchange="loadAttendanceForDate()">
            </div>
            <div class="overflow-x-auto">
                <table class="attendance-table">
                    <thead>
                        <tr>
                            <th>ลำดับ</th>
                            <th>รหัสนิสิต</th>
                            <th>ชื่อ-นามสกุล</th>
                            <th>วันที่</th>
                            <th>สถานะ</th>
                            <th>การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody id="attendanceTableBody">
                        <tr><td colspan="6" class="text-center text-gray-500">กรุณาเลือกวิชาเพื่อดูข้อมูล</td></tr>
                    </tbody>
                </table>
            </div>
            
        </div>
    </div>
    <!--popup แจ้งผลการเข้าเรียน -->
        <div id="statusModel" class="fixed inset=0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class ="bg-white rounded-lg shadow-xl p-6 w-full max-w-sm mx-auto">
            <div class="flex justify-between items-center mb-4">
                <h4 class="text-lg font-bold" id="modelStatusTitle">การแจ้งเตือน</h4>
                <button class="text-gray-500 hover:text-gray-700 text-2xl font-bold" 
                    onclick="closeStatusModel()">&itemes;</button>
            </div>
                        <div class="text-gray-700 mb-6" id="modelStatusBody">  </div>
                            <div class="text-right">
                                <button class ="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded"
                                onclick="closeStatusModel()">ตกลง</button>
                                 </div>
             </div>
         </div>
                     

    <script src="faceScan.js"></script>
   
    <script>
        function openStatusModel(title,massge, autoCloseMs = 3000) {
            document.getElementById('modelTitle').textContent = title;
            document.getElementById('modelBody').textContent = massage;
            const box = document.getElementById('statusModel');
            box.classList.remove('hidden');
            box.classList.add('flex');

            if (autoCloseMs) {
                setTimeout(closeStatusModel, autoCloseMs);
            }
        }
        function closeStatusModel() {
            const box = document.getElementById('statusModel');
            box.classList.remove('hidden');
            box.classList.add('flex');
        }
            
        let currentChart = null;
        const courses = <?php echo json_encode($courses, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[]'; ?>;
        window.courses = courses;
        console.log('window.courses initialized:', window.courses);
        const teachingSchedule = <?php echo json_encode($teaching_schedule, JSON_UNESCAPED_UNICODE); ?>;
        const teacherId = <?php echo json_encode($teacher_id, JSON_UNESCAPED_UNICODE); ?>;

        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM fully loaded');
            const initialStats = <?php echo json_encode($attendance_stats, JSON_UNESCAPED_UNICODE); ?>;
            console.log('Initial attendance stats:', initialStats);
            //updateAttendanceChart(initialStats);
            window.initializeVideo?.();
            startAutoRefresh();

            // เพิ่ม event listeners สำหรับ course buttons
            document.querySelectorAll('.course-button').forEach(button => {
                button.addEventListener('click', function() {
                    const courseId = this.dataset.courseId;
                    showAttendance(courseId);
                });
            });
        });

        let enrolledStudents = {}; // เก็บรายชื่อและรหัสนิสิตที่ลงทะเบียน

        function suggestStudentIds() {
        const input = document.getElementById('studentId');
        const suggestions = document.getElementById('idSuggestions');
        const query = input.value.toLowerCase();

        if (!query) {
            suggestions.classList.add('hidden');
            return;
        }

        suggestions.innerHTML = '';
        const matches = Object.keys(enrolledStudents).filter(studentId =>
            studentId.includes(query) || enrolledStudents[studentId].toLowerCase().includes(query)
        ).slice(0, 5);

        if (matches.length > 0) {
            matches.forEach(studentId => {
                const div = document.createElement('div');
                div.textContent = `${studentId} (${enrolledStudents[studentId]})`;
                div.className = 'p-2 hover:bg-gray-200 cursor-pointer';
                div.onclick = () => {
                    input.value = studentId; // Set to numeric student_id
                    document.getElementById('studentName').value = enrolledStudents[studentId];
                    suggestions.classList.add('hidden');
                };
                suggestions.appendChild(div);
            });
            suggestions.classList.remove('hidden');
        } else {
            suggestions.classList.add('hidden');
        }
    }


        

        function loadAttendanceForDate() {
            const courseId = window.currentCourseId;
            const selectedDate = document.getElementById('viewDate').value;
            if (!courseId || !selectedDate) return;

            fetch(`get_attendance.php?course_id=${courseId}&dates=${JSON.stringify([selectedDate])}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) throw new Error(data.error);
                    console.log('Loaded attendance data:', data); // ตรวจสอบข้อมูล
                    updateAttendanceTable(data.attendance[selectedDate] || {}, data.students);
                })
                .catch(error => {
                    console.error('Error loading attendance:', error);
                    document.getElementById('scanResult').textContent = 'เกิดข้อผิดพลาดในการโหลดข้อมูล: ' + error.message;
                    document.getElementById('scanResult').style.color = 'red';
                });
        }

        function updateAttendanceTable(attendanceData, students) {
            const tableBody = document.getElementById('attendanceTableBody');
            tableBody.innerHTML = '';

            // Ensure attendanceData and students are valid objects
            if (typeof attendanceData !== 'object' || attendanceData === null || typeof students !== 'object') {
                console.error('Invalid attendance data or students:', { attendanceData, students });
                return;
            }

            let index = 1;
            for (const studentId in students) {
                if (students.hasOwnProperty(studentId)) {
                    const status = attendanceData[studentId] || 'None'; // Default to 'None' if no status
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${index++}</td>
                        <td>${studentId}</td>
                        <td>${students[studentId]}</td>
                        <td>${document.getElementById('viewDate').value || 'N/A'}</td>
                        <td class="status-${status.toLowerCase().replace(' ', '-') || 'none'}">${translateStatus(status)}</td>
                        <td>
                            <button onclick="editAttendance('${students[studentId]}', '${studentId}', '${document.getElementById('viewDate').value}', '${status}')" class="edit-button">แก้ไข</button>
                        </td>
                    `;
                    tableBody.appendChild(row);
                }
            }
        }


        function showAttendance(courseId) {
            console.log('showAttendance called with courseId:', courseId);
            if (!window.courses || !Array.isArray(window.courses) || window.courses.length === 0) {
                console.error('No courses available:', window.courses);
                document.getElementById('scanResult').textContent = 'ไม่พบข้อมูลรายวิชาทั้งหมด';
                document.getElementById('scanResult').style.color = 'red';
                document.getElementById('startScanBtn').disabled = true;
                document.getElementById('attendanceTableBody').innerHTML = '<tr><td colspan="6" class="text-center text-red-500">Error: No courses available</td></tr>';
                return;
            }

            updateDatePicker(courseId); // อัปเดตสำหรับการสแกนหน้า
            updateViewDatePicker(courseId); // อัปเดตสำหรับการดูผลบันทึก
            document.querySelectorAll('.course-button').forEach(button => {
                button.classList.remove('selected');
                button.style.backgroundColor = '#4CAF50';
            });
            const selectedButton = document.querySelector(`[data-course-id="${courseId}"]`);
            if (selectedButton) {
                selectedButton.classList.add('selected');
                selectedButton.style.backgroundColor = '#2E7D32';
            }

            document.getElementById('faceScanSection').style.display = 'block';
            window.currentCourseId = courseId;

            const course = window.courses.find(c => c.course_id == courseId);
            if (!course) {
                console.error('Course not found:', courseId);
                document.getElementById('scanResult').textContent = `ไม่พบข้อมูลวิชา (ID: ${courseId})`;
                document.getElementById('scanResult').style.color = 'red';
                document.getElementById('startScanBtn').disabled = true;
                return;
            }

            let scheduleId = null;
            const selectedDate = document.getElementById('selectedDate').value;
            const selectedDay = new Date(selectedDate).toLocaleString('en-US', { weekday: 'long' });
            if (course.schedules && course.schedules.length > 0) {
                const matchingSchedule = course.schedules.find(sched => sched.day_of_week === selectedDay);
                scheduleId = matchingSchedule ? matchingSchedule.schedule_id : null; // ใช้ null ถ้าไม่เจอวันที่ตรงกัน
                if (!scheduleId && course.schedules.length > 1) {
                    console.warn('No matching schedule for selected day, using first available:', course.schedules[0]);
                    scheduleId = course.schedules[0].schedule_id; // ใช้ตารางแรกถ้าไม่มีวันที่ตรง
                }
            }
            window.currentScheduleId = scheduleId;
            console.log('Selected scheduleId:', scheduleId, 'for day:', selectedDay, 'all schedules:', course.schedules);
            document.getElementById('startScanBtn').disabled = !scheduleId;
            document.getElementById('scanningCourseInfo').innerHTML = `
                วิชา: ${course.course_code} ${course.course_name} |
                กลุ่ม ${course.group_number} |
                ${course.schedules?.map(sched => `${getDayNameThai(sched.day_of_week)} ${sched.start_time.slice(0, 5)} - ${sched.end_time.slice(0, 5)}`).join(' | ') || 'ไม่มีตาราง'}
            `;
            updateCourseInfo(course);

            if (!scheduleId) {
                document.getElementById('scanResult').textContent = `ไม่มีตารางเรียนสำหรับวันนี้ (${getDayNameThai(selectedDay)})`;
                document.getElementById('scanResult').style.color = 'red';
            } else {
                document.getElementById('scanResult').textContent = 'พร้อมสแกนใบหน้า';
                document.getElementById('scanResult').style.color = 'green';
            }

            document.getElementById('attendanceSection').style.display = 'block';
            // โหลดข้อมูลเริ่มต้นจาก #viewDate

            // ดึงรายชื่อนักเรียนที่ลงทะเบียน
            fetch(`get_enrolled_students.php?course_id=${encodeURIComponent(courseId)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) throw new Error(data.error);
                    enrolledStudents = data.students || {};
                    console.log('Enrolled students:', enrolledStudents);
                })
                .catch(error => {
                    console.error('Error fetching enrolled students:', error);
                    enrolledStudents = {};
                });
        }

        // เรียก updateDatePicker เมื่อโหลดหน้าและเมื่อเลือกวิชา (สำหรับการสแกนหน้า)
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM fully loaded');
            const initialStats = <?php echo json_encode($attendance_stats, JSON_UNESCAPED_UNICODE); ?>;
            console.log('Initial attendance stats:', initialStats);
            //updateAttendanceChart(initialStats);
            window.initializeVideo?.();
            startAutoRefresh();

            // เพิ่ม event listeners สำหรับ course buttons
            document.querySelectorAll('.course-button').forEach(button => {
                button.addEventListener('click', function() {
                    const courseId = this.dataset.courseId;
                    updateDatePicker(courseId); // อัปเดตสำหรับการสแกนหน้า
                    showAttendance(courseId);
                });
            });
        });

        function getDayNameThai(day) {
            const dayMapping = {
                'Monday': 'วันจันทร์',
                'Tuesday': 'วันอังคาร',
                'Wednesday': 'วันพุธ',
                'Thursday': 'วันพฤหัสบดี',
                'Friday': 'วันศุกร์',
                'Saturday': 'วันเสาร์',
                'Sunday': 'วันอาทิตย์'
            };
            return dayMapping[day] || day;
        }

        function getValidDatesForCourse(course) {
        if (!course || !course.schedules || course.schedules.length === 0) return [];

        const semester = course.semester.toLowerCase();
        const year = parseInt(course.c_year);
        const today = new Date(); // Current date (2025-07-04)
        const range = {
            'first': { startMonth: 6, startDay: 24, endMonth: 11, endDay: 4 },
            'second': { startMonth: 11, startDay: 25, endMonth: 3, endDay: 31 },
            'summer': { startMonth: 4, startDay: 21, endMonth: 6, endDay: 17 }
        }[semester] || {};

        let startDate = new Date(year, range.startMonth - 1, range.startDay || 1);
        let endDate = new Date(year, range.endMonth - 1, range.endDay || 1);
        if (semester === 'second' && range.endMonth < range.startMonth) {
            endDate = new Date(year + 1, range.endMonth - 1, range.endDay || 1);
        }
        // Include current date if within semester
        if (today >= startDate && today <= endDate) {
            endDate = today;
        }

        const dayMapping = {
            'Monday': 1, 'Tuesday': 2, 'Wednesday': 3, 'Thursday': 4,
            'Friday': 5, 'Saturday': 6, 'Sunday': 0
        };

        let validDates = [];
        course.schedules.forEach(sched => {
            const targetDay = dayMapping[sched.day_of_week];
            let current = new Date(startDate);
            while (current <= endDate) {
                if (current.getDay() === targetDay) {
                    validDates.push(current.toISOString().split('T')[0]);
                }
                current.setDate(current.getDate() + 1);
            }
        });

        validDates = [...new Set(validDates)].sort();
        // Add current date if it matches a scheduled day
        const todayStr = today.toISOString().split('T')[0];
        if (today >= startDate && today <= endDate) {
            const todayDay = today.getDay();
            if (course.schedules.some(sched => dayMapping[sched.day_of_week] === todayDay)) {
                if (!validDates.includes(todayStr)) validDates.push(todayStr);
                validDates.sort();
            }
        }
        console.log('Valid dates for course:', course.course_id, validDates);
        return validDates;
    }

        function updateDatePicker(courseId) {
        const course = window.courses.find(c => c.course_id == courseId);
        if (!course) return;

        const validDates = getValidDatesForCourse(course);
        const datePicker = document.getElementById('selectedDate');
        if (!datePicker) {
            console.error('Date picker element not found');
            return;
        }

        datePicker.innerHTML = '<option value="">เลือกวันที่</option>';
        validDates.forEach(date => {
            const option = document.createElement('option');
            option.value = date;
            option.textContent = new Date(date).toLocaleDateString('th-TH', { year: 'numeric', month: 'long', day: 'numeric' });
            datePicker.appendChild(option);
        });

        const today = new Date().toISOString().split('T')[0]; // Use real current date
        if (validDates.includes(today)) {
            datePicker.value = today; // Select current date if it’s valid
        } else if (validDates.length > 0) {
            datePicker.value = validDates[validDates.length - 1]; // Fallback to latest valid date
            console.warn(`Current date (${today}) is not in teaching schedule, using latest date: ${datePicker.value}`);
        } else {
            datePicker.value = today; // Fallback to current date if no valid dates
            console.warn('No teaching dates available, using current date:', today);
        }
        console.log('Date picker updated with value:', datePicker.value, new Date(datePicker.value).toLocaleString('en-US', { weekday: 'long', timeZone: 'Asia/Bangkok' }));
    }

        function updateViewDatePicker(courseId) {
        const course = window.courses.find(c => c.course_id == courseId);
        if (!course) return;

        const validDates = getValidDatesForCourse(course);
        const viewDatePicker = document.getElementById('viewDate');
        if (!viewDatePicker) {
            console.error('View date picker element not found');
            return;
        }

        viewDatePicker.innerHTML = '<option value="">เลือกวันที่</option>';
        validDates.forEach(date => {
            const option = document.createElement('option');
            option.value = date;
            option.textContent = new Date(date).toLocaleDateString('th-TH', { year: 'numeric', month: 'long', day: 'numeric' });
            viewDatePicker.appendChild(option);
        });

        const today = new Date().toISOString().split('T')[0]; // Use real current date
        if (validDates.includes(today)) {
            viewDatePicker.value = today; // Select current date if it’s valid
            loadAttendanceForDate(); // Load attendance for current date
        } else if (validDates.length > 0) {
            viewDatePicker.value = validDates[validDates.length - 1]; // Fallback to latest valid date
            loadAttendanceForDate(); // Load attendance for latest date
            console.warn(`Current date (${today}) is not in teaching schedule, using latest date: ${viewDatePicker.value}`);
        } else {
            viewDatePicker.value = today; // Fallback to current date
            loadAttendanceForDate(); // Load attendance for current date
            console.warn('No teaching dates available, using current date:', today);
        }
        console.log('View date picker updated with value:', viewDatePicker.value);
    }

        function selectDate() {
            const selectedCourseId = document.querySelector('.course-button.selected')?.dataset.courseId;
            if (selectedCourseId) {
                showAttendance(selectedCourseId);
            } else {
                console.warn('No course selected');
            }
        }

        // เรียก updateDatePicker เมื่อโหลดหน้าและเมื่อเลือกวิชา
        document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM fully loaded');
        const initialStats = <?php echo json_encode($attendance_stats, JSON_UNESCAPED_UNICODE); ?>;
        console.log('Initial attendance stats:', initialStats);
        window.initializeVideo?.();
        startAutoRefresh();

            // Initialize date picker with current date
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('selectedDate').value = today;
            document.getElementById('viewDate').value = today;

            // Add event listeners for course buttons
            document.querySelectorAll('.course-button').forEach(button => {
                button.addEventListener('click', function() {
                    const courseId = this.dataset.courseId;
                    updateDatePicker(courseId);
                    showAttendance(courseId);
                });
            });
        });

        function updateCourseInfo(course) {
            let scheduleInfo = 'ไม่มีตาราง';
            if (course.schedules && course.schedules.length > 0) {
                scheduleInfo = course.schedules.map(sched => 
                    `${getDayNameThai(sched.day_of_week)} ${sched.start_time.slice(0, 5)} - ${sched.end_time.slice(0, 5)}`
                ).join(' | ');
            }
            document.getElementById('courseInfo').innerHTML = `
                <h3 class="text-lg font-bold">${course.course_code} ${course.course_name}</h3>
                <p class="text-sm text-gray-600">
                    กลุ่ม ${course.group_number} |
                    ${scheduleInfo} |
                    ภาคการศึกษาที่ <?php echo getSemesterThai('${course.semester}'); ?>/${course.c_year}
                </p>
            `;
        }

        
        function translateStatus(status) {
            const statusMap = {
                'present': 'มาเรียน',
                'late': 'สาย',
                'absent': 'ขาดเรียน',
                'leave': 'ลา'
            };
            return statusMap[status.toLowerCase()] || status;
        }

        
        function suggestStudentNames() {
        const input = document.getElementById('studentName');
        const suggestions = document.getElementById('nameSuggestions');
        const query = input.value.toLowerCase();

        if (!query) {
            suggestions.classList.add('hidden');
            return;
        }

        suggestions.innerHTML = '';
        const matches = Object.entries(enrolledStudents).filter(([studentId, name]) =>
            name.toLowerCase().includes(query)
        ).slice(0, 5); // Limit to 5 suggestions

        if (matches.length > 0) {
            matches.forEach(([studentId, name]) => {
                const div = document.createElement('div');
                div.textContent = `${name} (${studentId})`;
                div.className = 'p-2 hover:bg-gray-200 cursor-pointer';
                div.onclick = () => {
                    document.getElementById('studentName').value = name;
                    document.getElementById('studentId').value = studentId; // Set to numeric student_id
                    suggestions.classList.add('hidden');
                };
                suggestions.appendChild(div);
            });
            suggestions.classList.remove('hidden');
        } else {
            suggestions.classList.add('hidden');
        }
    }

        function showAddAttendanceForm(studentId = '', studentName = '', date = '', status = 'Present') {
            const form = document.getElementById('addAttendanceForm');
            form.style.display = 'block';
            document.getElementById('studentId').value = studentId;
            document.getElementById('studentName').value = studentName || (studentId ? enrolledStudents[studentId] : '');
            document.getElementById('attendanceStatus').value = status;
            document.getElementById('scanTime').value = date ? new Date(date).toISOString().slice(0, 16) : '';
            document.getElementById('idSuggestions').classList.add('hidden');
            document.getElementById('nameSuggestions').classList.add('hidden');
        }

        function hideAddAttendanceForm() {
            document.getElementById('addAttendanceForm').style.display = 'none';
            document.getElementById('manualAttendanceForm').reset();
            document.getElementById('idSuggestions').classList.add('hidden');
            document.getElementById('nameSuggestions').classList.add('hidden');
        }

        
        function editAttendance(studentName, studentId, date, status) {
            showAddAttendanceForm(studentName, studentId, date, status);
        }

        document.getElementById('manualAttendanceForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const studentId = document.getElementById('studentId').value.trim();
        const studentName = document.getElementById('studentName').value.trim();
        const status = document.getElementById('attendanceStatus').value;
        const scanTime = document.getElementById('scanTime').value;
        const courseId = window.currentCourseId;
        const scheduleId = window.currentScheduleId;

        if (!courseId || !scheduleId) {
            console.error('No course or schedule selected');
            document.getElementById('scanResult').textContent = 'กรุณาเลือกวิชาก่อน';
            document.getElementById('scanResult').style.color = 'red';
            return;
        }

        // Validate studentId is numeric and exists in enrolledStudents
        if (!/^\d+$/.test(studentId) || !enrolledStudents[studentId]) {
            console.error('Invalid student ID:', studentId);
            document.getElementById('scanResult').textContent = 'รหัสนิสิตไม่ถูกต้องหรือไม่ได้ลงทะเบียนในวิชานี้';
            document.getElementById('scanResult').style.color = 'red';
            return;
        }

        // Validate name matches the studentId
        const expectedName = enrolledStudents[studentId];
        if (expectedName !== studentName) {
            console.error('Name mismatch:', { studentId, studentName, expectedName });
            document.getElementById('scanResult').textContent = 'ชื่อและรหัสนิสิตไม่ตรงกัน';
            document.getElementById('scanResult').style.color = 'red';
            return;
        }

        console.log('Submitting attendance with:', {
            student_id: studentId,
            schedule_id: scheduleId,
            scan_time: new Date(scanTime).toISOString().slice(0, 19).replace('T', ' '),
            status
        });

        fetch('add_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                student_id: studentId,
                schedule_id: scheduleId,
                scan_time: new Date(scanTime).toISOString().slice(0, 19).replace('T', ' '),
                status
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) throw new Error(data.error);
            document.getElementById('scanResult').textContent = data.message || 'บันทึกการเข้าเรียนสำเร็จ';
            document.getElementById('scanResult').style.color = 'green';
            loadAttendanceForDate();
            hideAddAttendanceForm();
        })
        .catch(error => {
            console.error('Error adding attendance:', error);
            document.getElementById('scanResult').textContent = 'เกิดข้อผิดพลาด: ' + error.message;
            document.getElementById('scanResult').style.color = 'red';
        });
    });


        function exportAttendance() {
            const selectedCourseId = document.querySelector('.course-button.selected')?.dataset.courseId;
            const selectedDate = document.getElementById('selectedDate').value;
            if (!selectedCourseId) {
                alert('กรุณาเลือกวิชา');
                return;
            }
            window.location.href = `export_attendance.php?course_id=${selectedCourseId}&date=${selectedDate}`;
        }

        

        function startAutoRefresh() {
            let errorCount = 0;
            setInterval(() => {
                if (errorCount >= 3) {
                    console.warn('Stopping auto-refresh due to repeated errors');
                    return;
                }
                const selectedCourseId = document.querySelector('.course-button.selected')?.dataset.courseId;
                if (selectedCourseId && !window.scanning) {
                    showAttendance(selectedCourseId);
                }
            }, 30000);
        }

        function logout() {
            if (window.scanning) window.stopFaceScan?.();
            window.location.href = 'logout.php';
        }

        window.addEventListener('beforeunload', () => {
            if (window.scanning) {
                fetch('http://127.0.0.1:5000/stop_scan', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                }).catch(error => console.error("Error stopping scan on unload:", error));
            }
            if (window.stream) {
                window.stream.getTracks().forEach(track => track.stop());
            }
        });

        document.getElementById('startScanBtn').onclick = function() {
            const courseId = window.currentCourseId;
            const course = window.courses.find(c => c.course_id == courseId);
            const selectedDate = document.getElementById('selectedDate').value;
            const selectedDay = new Date(selectedDate).toLocaleString('en-US', { weekday: 'long' });

            console.log('Start scan button clicked:', { courseId, scheduleId: window.currentScheduleId });

            if (!course) {
                console.log('No course selected');
                document.getElementById('scanResult').textContent = 'กรุณาเลือกวิชา';
                document.getElementById('scanResult').style.color = 'red';
                return;
            }

            if (!window.currentScheduleId) {
                console.warn('No scheduleId found');
                const scheduleDays = course.schedules?.map(sched => getDayNameThai(sched.day_of_week)).join(', ') || '';
                document.getElementById('scanResult').textContent = `ไม่มีตารางเรียนสำหรับวันนี้ (${getDayNameThai(selectedDay)})`;
                document.getElementById('scanResult').style.color = 'red';
                window.currentScheduleId = course.schedules[0]?.schedule_id || null;
                if (!window.currentScheduleId) {
                    document.getElementById('scanResult').textContent = 'ไม่พบตารางเรียนสำหรับวิชานี้';
                    return;
                }
            }

            if (typeof window.startFaceScan === 'function') {
                console.log('Starting face scan with:', { teacherId, scheduleId: window.currentScheduleId });
                document.getElementById('scanResult').textContent = 'กำลังเริ่มการสแกน...';
                document.getElementById('scanResult').style.color = 'blue';
                window.startFaceScan(teacherId, window.currentScheduleId);
            } else {
                console.error('startFaceScan function not found.');
                document.getElementById('scanResult').textContent = 'ไม่สามารถเริ่มการสแกนใบหน้าได้';
                document.getElementById('scanResult').style.color = 'red';
            }
        };
    </script>
</body>
</html>
