<?php
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'database_connection.php';

// Debug session
error_log("Session at teacher_dashboard.php: " . print_r($_SESSION, true));

// Check if user is logged in and is a teacher
if (!isset($_SESSION['teacher_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Redirecting to login.php: teacher_id or user_role not set correctly");
    $_SESSION['error_message'] = "กรุณาเข้าสู่ระบบในฐานะอาจารย์";
    header("Location: login.php");
    exit();
}

// Get the teacher's information
$teacher_id = $_SESSION['teacher_id'];
$teacher_name = $_SESSION['user_name']; // Use user_name set by login.php
$stmt = $conn->prepare("SELECT teacher_id, name FROM teachers WHERE teacher_id = ?");
$stmt->bind_param("i", $teacher_id); // teacher_id is an int
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    error_log("Teacher not found: teacher_id=$teacher_id");
    $_SESSION['error_message'] = "ไม่พบข้อมูลอาจารย์";
    header("Location: login.php");
    exit();
}
$teacher_data = $result->fetch_assoc();
$teacher_id = $teacher_data['teacher_id'];
$teacher_name = $teacher_data['name']; // Override with DB name if needed
$stmt->close();

// Get distinct days where teacher has courses
$days_query = "
    SELECT DISTINCT day_of_week 
    FROM courses 
    WHERE teacher_id = ?
    ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')";
$days_stmt = $conn->prepare($days_query);
$days_stmt->bind_param("i", $teacher_id); // teacher_id is an int
$days_stmt->execute();
$days_result = $days_stmt->get_result();
$teaching_days = $days_result->fetch_all(MYSQLI_ASSOC);
$days_stmt->close();

// Function to map semester enum to integer
function mapSemesterToInt($semester) {
    $semesterMap = [
        'first' => 1,
        'second' => 2,
        'summer' => 3
    ];
    return $semesterMap[$semester] ?? 1; // Default to 1 if not found
}

// Function to get semester date range
function getFirstAndLastDateOfSemester($semester, $year) {
    $semester = mapSemesterToInt($semester);
    if ($semester == 1) {
        $startMonth = 6; // June
        $endMonth = 10; // October
    } elseif ($semester == 2) {
        $startMonth = 11; // November
        $endMonth = 3; // March of next year
        $year = ($endMonth < $startMonth) ? $year + 1 : $year;
    } else { // Summer (semester 3)
        $startMonth = 4; // April
        $endMonth = 6; // June
    }
    $startDate = date('Y-m-d', strtotime("$year-$startMonth-1"));
    $endDate = date('Y-m-t', strtotime("$year-$endMonth-1"));
    return array($startDate, $endDate);
}

// Function to get all teaching dates for a course
function getAllTeachingDates($courseSchedule, $startDate, $endDate) {
    $dates = array();
    $current = strtotime($startDate);
    $end = strtotime($endDate);
    while ($current <= $end) {
        $currentDayOfWeek = date('l', $current);
        if ($currentDayOfWeek === $courseSchedule['day_of_week']) {
            $dates[] = date('Y-m-d', $current);
        }
        $current = strtotime('+1 day', $current);
    }
    return $dates;
}

// Get teacher's courses (including schedules table)
$get_courses_stmt = $conn->prepare("
    SELECT 
        c.course_id,
        c.course_name,
        c.course_code,
        c.day_of_week,
        c.start_time,
        c.end_time,
        c.group_number,
        c.semester,
        c.c_year,
        s.schedule_id
    FROM courses c
    LEFT JOIN schedules s ON c.course_id = s.course_id
    WHERE c.teacher_id = ?
    ORDER BY c.day_of_week, c.start_time");
$get_courses_stmt->bind_param("i", $teacher_id); // teacher_id is an int
$get_courses_stmt->execute();
$courses_result = $get_courses_stmt->get_result();
$courses = $courses_result->fetch_all(MYSQLI_ASSOC);
$get_courses_stmt->close();

// Determine current semester and year (use the most recent course)
$current_semester = 'first'; // Default
$current_year = date('Y');
if (!empty($courses)) {
    // Sort courses by c_year and semester to get the most recent
    usort($courses, function($a, $b) {
        if ($a['c_year'] === $b['c_year']) {
            $semesterOrder = ['first' => 1, 'second' => 2, 'summer' => 3];
            return $semesterOrder[$b['semester']] - $semesterOrder[$a['semester']];
        }
        return $b['c_year'] - $a['c_year'];
    });
    $most_recent_course = $courses[0];
    $current_semester = $most_recent_course['semester'];
    $current_year = $most_recent_course['c_year'];
}

// Get semester date range
list($semester_start, $semester_end) = getFirstAndLastDateOfSemester($current_semester, $current_year);

// Get all teaching dates for each course
$teaching_schedule = array();
foreach ($courses as $course) {
    $dates = getAllTeachingDates($course, $semester_start, $semester_end);
    $teaching_schedule[$course['course_id']] = $dates;
}

// Fetch overall attendance statistics for initial chart display
$attendance_stats = [
    'present' => 0,
    'late' => 0,
    'absent' => 0,
    'total' => 0
];
foreach ($courses as $course) {
    $course_id = $course['course_id'];
    $stmt = $conn->prepare("
        SELECT status, COUNT(*) as count 
        FROM attendance 
        WHERE course_id = ?
        GROUP BY status");
    $stmt->bind_param("i", $course_id); // course_id is an int
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (strtolower($row['status']) === 'present') {
            $attendance_stats['present'] += $row['count'];
        } elseif (strtolower($row['status']) === 'late') {
            $attendance_stats['late'] += $row['count'];
        } elseif (strtolower($row['status']) === 'absent') {
            $attendance_stats['absent'] += $row['count'];
        }
    }
    $attendance_stats['total'] += ($attendance_stats['present'] + $attendance_stats['late'] + $attendance_stats['absent']);
    $stmt->close();
}

// Helper function to convert day names to Thai
function getDayNameThai($englishDay) {
    $dayMapping = [
        'Monday' => 'วันจันทร์',
        'Tuesday' => 'วันอังคาร',
        'Wednesday' => 'วันพุธ',
        'Thursday' => 'วันพฤหัสบดี',
        'Friday' => 'วันศุกร์',
        'Saturday' => 'วันเสาร์',
        'Sunday' => 'วันอาทิตย์'
    ];
    return $dayMapping[$englishDay] ?? $englishDay;
}

// Helper function to convert semester enum to Thai
function getSemesterThai($semester) {
    $semesterMap = [
        'first' => '1',
        'second' => '2',
        'summer' => 'ฤดูร้อน'
    ];
    return $semesterMap[$semester] ?? $semester;
}

// Close the database connection
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

        * {
            font-family: 'Sarabun', sans-serif;
        }

        .header {
            background-color: #71b773;
            padding: 1rem;
            color: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .course-button {
            background-color: #4CAF50;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            margin: 0.5rem;
            width: 100%;
            max-width: 300px;
            text-align: left;
        }

        .course-button:hover {
            background-color: #45a049;
            transform: translateY(-2px);
        }

        .day-button {
            transition: all 0.3s;
        }

        .day-button:hover {
            background-color: #45a049;
            color: white;
        }

        .calendar {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
        }

        .day {
            border: 1px solid #ccc;
            padding: 10px;
        }

        .highlighted {
            background-color: yellow;
        }

        .status-present {
            background-color: rgba(76, 175, 80, 0.1);
            color: #2e7d32;
        }

        .status-late {
            background-color: rgba(240, 173, 78, 0.1);
            color: #f57c00;
        }

        .status-absent {
            background-color: rgba(217, 83, 79, 0.1);
            color: #c62828;
        }

        .attendance-table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
            background-color: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }

        .attendance-table th,
        .attendance-table td {
            padding: 0.75rem;
            text-align: left;
            border: 1px solid #e2e8f0;
        }

        .attendance-table th {
            background-color: #f9e69e;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .attendance-table tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .attendance-table tbody tr:hover {
            background-color: #f5f5f5;
        }

        .chart-container {
            max-width: 600px;
            margin: 2rem auto;
        }

        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            text-align: center;
        }
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
        <button onclick="logout()" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded">
            ออกจากระบบ
        </button>
    </div>

    <div class="container mx-auto px-4 py-8">
        <!-- Display error message if any -->
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($_SESSION['error_message']); ?>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-bold mb-4">รายวิชาที่สอน</h2>
            <?php if (empty($courses)): ?>
                <p class="text-center text-gray-500">ไม่มีรายวิชาที่สอนในขณะนี้</p>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($courses as $course): ?>
                        <button onclick="showAttendance(<?php echo htmlspecialchars($course['course_id']); ?>)" class="course-button" data-course-id="<?php echo htmlspecialchars($course['course_id']); ?>">
                            <div class="font-bold"><?php echo htmlspecialchars($course['course_code']); ?></div>
                            <div class="text-sm"><?php echo htmlspecialchars($course['course_name']); ?></div>
                            <div class="text-sm">
                                กลุ่ม <?php echo htmlspecialchars($course['group_number']); ?> |
                                <?php echo htmlspecialchars(getDayNameThai($course['day_of_week'])); ?> |
                                <?php echo substr($course['start_time'], 0, 5); ?> - <?php echo substr($course['end_time'], 0, 5); ?>
                            </div>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold">เลือกวันที่</h2>
                <input 
                    type="date" 
                    id="selectedDate" 
                    class="border border-gray-300 rounded px-4 py-2" 
                    value="<?php echo date('Y-m-d'); ?>"
                    onchange="selectDate(this.value)"
                >
            </div>
        </div>

        <div id="faceScanSection" class="bg-white rounded-lg shadow-md p-6 mb-6" style="display: none;">
            <h2 class="text-xl font-bold mb-4">สแกนใบหน้าเพื่อเช็คชื่อ</h2>
            <div class="flex items-center justify-between mb-4">
                <div>
                    <span id="scanningCourseInfo"></span>
                </div>
                <div>
                    <button id="startScanBtn" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded">
                        เริ่มสแกน
                    </button>
                    <button id="stopScanBtn" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded" onclick="stopFaceScan()" style="display: none;">
                        หยุดสแกน
                    </button>
                </div>
            </div>
            <div class="relative">
                <video id="videoFeed" autoplay playsinline class="w-full max-w-2xl mx-auto"></video>
                <canvas id="overlayCanvas" class="absolute top-0 left-0 w-full max-w-2xl mx-auto" style="display: none;"></canvas>
            </div>
            <div id="scanResult" class="text-center mt-4 font-medium"></div>
        </div>

        <button onclick="exportAttendance()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">
            ดาวน์โหลดข้อมูลการเข้าเรียน
        </button>

        <div id="attendanceSection" class="bg-white rounded-lg shadow-md p-6" style="display: none;">
            <div id="courseInfo" class="mb-4"></div>
            <div class="overflow-x-auto">
                <table class="attendance-table">
                    <thead>
                        <tr>
                            <th>ลำดับ</th>
                            <th>รหัสนิสิต</th>
                            <th>ชื่อ-นามสกุล</th>
                            <th>เวลาสแกน</th>
                            <th>สถานะ</th>
                        </tr>
                    </thead>
                    <tbody id="attendanceTableBody">
                        <tr><td colspan="5" class="text-center text-gray-500">กรุณาเลือกวิชาเพื่อดูข้อมูล</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="chart-container">
                <canvas id="attendanceChart" style="width: 100%; height: 300px;"></canvas>
            </div>
        </div>
    </div>

    <script src="js/faceScanner.js"></script>
    <script>
        let currentChart = null;
        const courses = <?php echo json_encode($courses); ?>;
        const teachingSchedule = <?php echo json_encode($teaching_schedule); ?>;
        const teacherId = <?php echo json_encode($teacher_id); ?>;

        // Initialize chart with overall statistics on page load
        document.addEventListener('DOMContentLoaded', function() {
            const initialStats = <?php echo json_encode($attendance_stats); ?>;
            console.log('Initial attendance stats:', initialStats);
            updateAttendanceChart(initialStats);
            startAutoRefresh();
        });

        function selectDate(date) {
            const selectedCourseId = document.querySelector('.course-button.selected')?.dataset.courseId;
            if (selectedCourseId) {
                showAttendance(selectedCourseId);
            }
        }

        function showAttendance(courseId) {
            // Update date picker constraints
            updateDatePicker(courseId);

            // Highlight selected course button
            document.querySelectorAll('.course-button').forEach(button => {
                button.classList.remove('selected');
                button.style.backgroundColor = '#4CAF50';
            });
            const selectedButton = document.querySelector(`[data-course-id="${courseId}"]`);
            if (selectedButton) {
                selectedButton.classList.add('selected');
                selectedButton.style.backgroundColor = '#2E7D32';
            }

            // Show face scan section
            document.getElementById('faceScanSection').style.display = 'block';
            window.currentCourseId = courseId;

            // Initialize video for face scanning if not already initialized
            if (!window.video) {
                window.initializeVideo();
            }

            // Display course info in face scan section
            const course = courses.find(c => c.course_id == courseId);
            if (course) {
                document.getElementById('scanningCourseInfo').innerHTML = `
                    วิชา: ${course.course_code} ${course.course_name} |
                    กลุ่ม ${course.group_number} |
                    ${course.day_of_week} ${course.start_time} - ${course.end_time}
                `;
                updateCourseInfo(course);
                window.currentScheduleId = course.schedule_id; // Pass schedule_id to faceScanner.js
            }

            // Show attendance section and load data
            const selectedDate = document.getElementById('selectedDate').value;
            document.getElementById('attendanceSection').style.display = 'block';
            document.getElementById('attendanceTableBody').innerHTML = 
                '<tr><td colspan="5" class="text-center">กำลังโหลดข้อมูล...</td></tr>';

            fetch(`get_attendance.php?course_id=${courseId}&date=${selectedDate}`)
                .then(response => response.json())
                .then(data => {
                    console.log('Attendance data:', data);
                    if (data.error) {
                        throw new Error(data.error);
                    }

                    if (data.message) {
                        document.getElementById('attendanceTableBody').innerHTML = 
                            `<tr><td colspan="5" class="text-center text-gray-500">${data.message}</td></tr>`;
                        if (data.statistics) {
                            updateAttendanceChart(data.statistics);
                        }
                        return;
                    }

                    if (data.students && Array.isArray(data.students)) {
                        updateAttendanceTable(data.students);
                    } else {
                        document.getElementById('attendanceTableBody').innerHTML = 
                            '<tr><td colspan="5" class="text-center text-gray-500">ไม่มีข้อมูลการเข้าเรียนสำหรับวันที่เลือก</td></tr>';
                    }

                    if (data.statistics) {
                        updateAttendanceChart(data.statistics);
                    }
                })
                .catch(error => {
                    console.error("Error fetching attendance:", error);
                    document.getElementById('attendanceTableBody').innerHTML = 
                        `<tr><td colspan="5" class="text-center text-red-500">
                            เกิดข้อผิดพลาด: ${error.message}
                        </td></tr>`;
                });
        }

        const SEMESTER_RANGES = {
            'first': { startMonth: 6, startDay: 24, endMonth: 11, endDay: 4 },
            'second': { startMonth: 11, startDay: 25, endMonth: 3, endDay: 31 },
            'summer': { startMonth: 4, startDay: 21, endMonth: 6, endDay: 4 }
        };

        function getValidDatesForCourse(course) {
            const semester = course.semester;
            const year = parseInt(course.c_year);
            const dayOfWeek = course.day_of_week;
            
            const range = SEMESTER_RANGES[semester];
            if (!range) return [];

            let startDate, endDate;
            if (semester === 'second' && range.endMonth < range.startMonth) {
                startDate = new Date(year, range.startMonth - 1, range.startDay);
                endDate = new Date(year + 1, range.endMonth - 1, range.endDay);
            } else {
                startDate = new Date(year, range.startMonth - 1, range.startDay);
                endDate = new Date(year, range.endMonth - 1, range.endDay);
            }
            
            const validDates = [];
            const dayMapping = {
                'Monday': 1, 'Tuesday': 2, 'Wednesday': 3, 'Thursday': 4,
                'Friday': 5, 'Saturday': 6, 'Sunday': 0
            };
            const targetDay = dayMapping[dayOfWeek];
            let current = new Date(startDate);
            
            while (current <= endDate) {
                if (current.getDay() === targetDay) {
                    validDates.push(current.toISOString().split('T')[0]);
                }
                current.setDate(current.getDate() + 1);
            }
            
            return validDates;
        }

        function updateDatePicker(courseId) {
            const datePicker = document.getElementById('selectedDate');
            const course = courses.find(c => c.course_id == courseId);
            
            if (!course || !teachingSchedule[courseId]) {
                console.error('Course or schedule not found');
                datePicker.disabled = true;
                return;
            }

            const validDates = teachingSchedule[courseId];
            if (validDates.length > 0) {
                datePicker.disabled = false;
                datePicker.min = validDates[0];
                datePicker.max = validDates[validDates.length - 1];
                
                const today = new Date().toISOString().split('T')[0];
                const futureValidDate = validDates.find(date => date >= today) || validDates[validDates.length - 1];
                datePicker.value = futureValidDate;
            } else {
                datePicker.disabled = true;
            }

            datePicker.onchange = function(e) {
                const selectedDate = this.value;
                if (!validDates.includes(selectedDate)) {
                    alert('กรุณาเลือกวันที่มีการเรียนการสอนเท่านั้น');
                    const nearestDate = validDates.reduce((nearest, date) => {
                        if (!nearest) return date;
                        const diffNearest = Math.abs(new Date(nearest) - new Date(selectedDate));
                        const diffCurrent = Math.abs(new Date(date) - new Date(selectedDate));
                        return diffCurrent < diffNearest ? date : nearest;
                    }, null);
                    this.value = nearestDate;
                }
                showAttendance(courseId);
            };
        }

        function updateCourseInfo(course) {
            document.getElementById('courseInfo').innerHTML = `
                <h3 class="text-lg font-bold">
                    ${course.course_code} ${course.course_name}
                </h3>
                <p class="text-sm text-gray-600">
                    กลุ่ม ${course.group_number} | 
                    ${course.day_of_week} ${course.start_time} - ${course.end_time} | 
                    ภาคการศึกษาที่ <?php echo getSemesterThai($current_semester); ?>/${course.c_year}
                </p>
            `;
        }

        function updateAttendanceTable(students) {
            const tbody = document.getElementById('attendanceTableBody');
            tbody.innerHTML = '';

            if (!students || students.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-gray-500">ไม่มีข้อมูลการเข้าเรียน</td></tr>';
                return;
            }

            students.forEach((student, index) => {
                const row = tbody.insertRow();
                row.className = `status-${student.status.toLowerCase()}`;
                row.innerHTML = `
                    <td>${index + 1}</td>
                    <td>${student.student_id || 'N/A'}</td>
                    <td>${student.name || 'N/A'}</td>
                    <td>${student.scan_time || 'ไม่มีการสแกน'}</td>
                    <td class="font-medium">${translateStatus(student.status)}</td>
                `;
            });
        }

        function translateStatus(status) {
            const statusMap = {
                'present': 'มาเรียน',
                'late': 'สาย',
                'absent': 'ขาดเรียน'
            };
            return statusMap[status.toLowerCase()] || status;
        }

        function exportAttendance() {
            const selectedCourseId = document.querySelector('.course-button.selected')?.dataset.courseId;
            const selectedDate = document.getElementById('selectedDate').value;
            
            if (!selectedCourseId) {
                alert('กรุณาเลือกวิชา');
                return;
            }

            window.location.href = `export_attendance.php?course_id=${selectedCourseId}&date=${selectedDate}`;
        }

        function updateAttendanceChart(statistics) {
            console.log('Updating chart with statistics:', statistics);
            if (currentChart) {
                currentChart.destroy();
            }

            const ctx = document.getElementById('attendanceChart').getContext('2d');
            if (!ctx) {
                console.error('Canvas context not found for attendanceChart');
                return;
            }

            currentChart = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: ['มาเรียน', 'สาย', 'ขาดเรียน'],
                    datasets: [{
                        data: [
                            statistics.present || 0,
                            statistics.late || 0,
                            statistics.absent || 0
                        ],
                        backgroundColor: ['#4CAF50', '#f0ad4e', '#d9534f']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const value = context.raw;
                                    const total = (statistics.total && statistics.total > 0) ? statistics.total : (value || 0);
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return `${context.label}: ${value} คน (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }

        function startAutoRefresh() {
            setInterval(() => {
                const selectedCourseId = document.querySelector('.course-button.selected')?.dataset.courseId;
                if (selectedCourseId) {
                    showAttendance(selectedCourseId);
                }
            }, 30000); // Refresh every 30 seconds
        }

        function logout() {
            if (window.scanning) {
                window.stopFaceScan();
            }
            window.location.href = 'logout.php';
        }

        window.addEventListener('beforeunload', () => {
            if (window.scanning) {
                fetch('http://localhost:5000/stop_scan', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                }).catch(error => {
                    console.error("Error stopping scan on unload:", error);
                });
            }
            if (window.stream) {
                window.stream.getTracks().forEach(track => track.stop());
            }
        });

        // Bind the startFaceScan function to the start scan button
        document.getElementById('startScanBtn').onclick = function() {
            if (typeof window.startFaceScan === 'function') {
                window.startFaceScan(teacherId, window.currentScheduleId);
            } else {
                console.error('startFaceScan function not found. Ensure faceScanner.js is loaded correctly.');
                alert('ไม่สามารถเริ่มการสแกนใบหน้าได้ กรุณาตรวจสอบการเชื่อมต่อหรือติดต่อผู้ดูแลระบบ');
            }
        };
    </script>
</body>
</html>