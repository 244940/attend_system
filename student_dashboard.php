<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
require 'database_connection.php';

if (!isset($_SESSION['student_id']) || $_SESSION['user_role'] !== 'student') {
    error_log("Access denied: student_id or user_role not set. Session: " . print_r($_SESSION, true));
    header("Location: login.php");
    exit();
}

$stmt = $conn->prepare("SELECT student_id, name FROM students WHERE student_id = ?");
$stmt->bind_param("i", $_SESSION['student_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    error_log("Student not found for student_id: {$_SESSION['student_id']}");
    die("Error: Student not found.");
}

$student_data = $result->fetch_assoc();
$student_id = $student_data['student_id'];
$student_name = $student_data['name'];
$stmt->close();

$get_courses_stmt = $conn->prepare("
    SELECT 
        c.course_id,
        c.course_name,
        c.course_code,
        c.group_number,
        c.semester,
        c.c_year,
        s.day_of_week
    FROM courses c
    JOIN enrollments e ON c.course_id = e.course_id
    JOIN schedules s ON c.course_id = s.course_id
    WHERE e.student_id = ?
    GROUP BY c.course_id, c.course_name, c.course_code, c.group_number, c.semester, c.c_year, s.day_of_week
    ORDER BY c.semester
");
$get_courses_stmt->bind_param("i", $student_id);
$get_courses_stmt->execute();
$courses = $get_courses_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$get_courses_stmt->close();

function getSemesterDateRange($semester, $year) {
    $year = (int)$year;
    if ($semester == 1) {
        return ['start' => "$year-06-01", 'end' => "$year-10-31"];
    } elseif ($semester == 2) {
        return ['start' => "$year-11-25", 'end' => ($year + 1) . "-03-31"];
    } elseif ($semester == 3 || strtolower($semester) === 'summer') {
        $endYear = ($year == date('Y') && date('m') > 6) ? $year : $year + 1;
        return ['start' => "$year-04-01", 'end' => "$endYear-07-31"];
    } else {
        return ['start' => null, 'end' => null];
    }
}

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
        if (date('N', $current) == $targetDay) {
            $dates[] = date('Y-m-d', $current);
        }
        $current = strtotime('+1 day', $current);
    }
    return $dates;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap');
        * { font-family: 'Sarabun', sans-serif; }
        .header { background-color: #71b773; padding: 1rem; color: white; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .attendance-table { width: 100%; border-collapse: collapse; margin-top: 1rem; background-color: white; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
        .attendance-table th { background-color: #f9e69e; font-weight: bold; }
        .attendance-table th, .attendance-table td { padding: 0.75rem; text-align: left; border: 1px solid #e2e8f0; }
        .attendance-table tbody tr:nth-child(even) { background-color: #f8f9fa; }
        .attendance-table tbody tr:hover { background-color: #f5f5f5; }
        .course-button { background-color: #4CAF50; color: white; padding: 0.75rem 1.5rem; border-radius: 0.5rem; transition: all 0.3s; border: none; cursor: pointer; margin: 0.5rem; width: 100%; max-width: 300px; text-align: left; }
        .course-button:hover { background-color: #45a049; transform: translateY(-2px); }
    </style>
</head>
<body class="bg-gray-100">
    <div class="header flex items-center justify-between">
        <div class="flex items-center space-x-4">
            <img src="assets/logo.png" alt="Logo" class="w-16 h-16">
            <div>
                <h1 class="text-xl font-bold">ระบบเช็คชื่อนิสิตมหาวิทยาลัยเกษตรศาสตร์</h1>
                <p class="text-sm">นิสิต: <?php echo htmlspecialchars($student_name); ?></p>
            </div>
        </div>
        <button onclick="logout()" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded">ออกจากระบบ</button>
    </div>

    <div class="container mx-auto px-4 py-8">
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-bold mb-4">รายวิชาที่ลงทะเบียน</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($courses as $course): ?>
                    <button onclick="showAttendance('<?php echo htmlspecialchars($course['course_id']); ?>', '<?php echo htmlspecialchars($course['day_of_week']); ?>', '<?php echo htmlspecialchars($course['semester']); ?>', '<?php echo htmlspecialchars($course['c_year']); ?>')" class="course-button">
                        <div class="font-bold"><?php echo htmlspecialchars($course['course_code'] ?? 'N/A'); ?></div>
                        <div><?php echo htmlspecialchars($course['course_name'] ?? 'N/A'); ?></div>
                        <div>Semester: <?php echo htmlspecialchars($course['semester']); ?> | Group: <?php echo htmlspecialchars($course['group_number'] ?? 'N/A'); ?> | Year: <?php echo htmlspecialchars($course['c_year']); ?></div>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="attendanceSection" class="bg-white rounded-lg shadow-md p-6" style="display:none;">
            <div class="flex items-center justify-between mb-4">
                <div id="courseInfo" class="text-lg font-bold"></div>
                <input type="date" id="selectedDate" class="border border-gray-300 rounded px-4 py-2" onchange="loadAttendanceForDate()">
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
                        </tr>
                    </thead>
                    <tbody id="attendanceTableBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        let currentCourseId = null;

        function showAttendance(courseId, dayOfWeek, semester, year) {
            console.log('showAttendance params:', { courseId, dayOfWeek, semester, year });
            currentCourseId = courseId;
            const attendanceTableBody = document.getElementById('attendanceTableBody');
            attendanceTableBody.innerHTML = '<tr><td colspan="5" class="text-center">Please select a date...</td></tr>';

            if (!dayOfWeek || !semester || !year) {
                console.error('Invalid parameters:', { dayOfWeek, semester, year });
                attendanceTableBody.innerHTML = '<tr><td colspan="5" class="text-center text-red-500">Error: Invalid course data</td></tr>';
                return;
            }

            const { start, end } = getSemesterDateRange(semester, year);
            console.log('Date range:', { start, end });
            if (!start || !end) {
                console.error('Invalid semester date range:', { semester, year });
                attendanceTableBody.innerHTML = '<tr><td colspan="5" class="text-center text-red-500">Error: Invalid semester dates</td></tr>';
                return;
            }

            const validClassDates = getClassDates(dayOfWeek, start, end);
            console.log('Valid class dates:', validClassDates);

            // ตั้งค่า min และ max ของ date picker
            const datePicker = document.getElementById('selectedDate');
            datePicker.min = validClassDates[0];
            datePicker.max = validClassDates[validClassDates.length - 1];
            datePicker.value = validClassDates[0]; // ตั้งค่าเริ่มต้นเป็นวันที่แรก

            // โหลดข้อมูลสำหรับวันที่เริ่มต้น
            loadAttendanceForDate();

            // แสดงข้อมูลวิชาใน courseInfo
            const course = getCourseDetailsFromId(courseId);
            document.getElementById('courseInfo').innerHTML = `
                Course: ${course.course_name || 'N/A'} | 
                Semester: ${course.semester || 'N/A'} | 
                Group: ${course.group_number || 'N/A'} | 
                Code: ${course.course_code || 'N/A'} | 
                Year: ${course.c_year || 'N/A'}
            `;
            document.getElementById('attendanceSection').style.display = 'block';
        }

        function loadAttendanceForDate() {
            if (!currentCourseId) return;

            const selectedDate = document.getElementById('selectedDate').value;
            if (!selectedDate) return;

            const attendanceTableBody = document.getElementById('attendanceTableBody');
            attendanceTableBody.innerHTML = '<tr><td colspan="5" class="text-center">Loading...</td></tr>';

            fetch(`get_attendance.php?course_id=${currentCourseId}&student_id=<?php echo $student_id; ?>&dates=${JSON.stringify([selectedDate])}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    attendanceTableBody.innerHTML = '';
                    let index = 1;
                    const status = data.attendance[selectedDate] && data.attendance[selectedDate][<?php echo $student_id; ?>] !== undefined ? data.attendance[selectedDate][<?php echo $student_id; ?>] : 'None';
                    const row = attendanceTableBody.insertRow();
                    row.innerHTML = `
                        <td class="border px-4 py-2">${index}</td>
                        <td class="border px-4 py-2"><?php echo htmlspecialchars($student_id); ?></td>
                        <td class="border px-4 py-2"><?php echo htmlspecialchars($student_name); ?></td>
                        <td class="border px-4 py-2">${selectedDate}</td>
                        <td class="border px-4 py-2">${status}</td>
                    `;
                    document.getElementById('attendanceSection').style.display = 'block';
                })
                .catch(error => {
                    console.error("Error fetching attendance:", error);
                    attendanceTableBody.innerHTML = `<tr><td colspan="5" class="text-center text-red-500">Error: ${error.message}</td></tr>`;
                });
        }

        function getCourseDetailsFromId(courseId) {
            const course = <?php echo json_encode($courses); ?>.find(c => c.course_id == courseId);
            return course ? course : { course_name: 'N/A', semester: 'N/A', group_number: 'N/A', course_code: 'N/A', c_year: 'N/A', day_of_week: 'N/A' };
        }

        function logout() {
            window.location.href = 'logout.php';
        }

        function getSemesterDateRange(semester, year) {
            console.log('getSemesterDateRange:', { semester, year });
            if (semester == 1) {
                return { start: `${year}-06-01`, end: `${year}-10-31` };
            } else if (semester == 2) {
                return { start: `${year}-11-25`, end: `${parseInt(year) + 1}-03-31` };
            } else if (semester == 3 || semester.toLowerCase() === 'summer') {
                const endYear = (year == new Date().getFullYear() && new Date().getMonth() > 6) ? year : parseInt(year) + 1;
                return { start: `${year}-04-01`, end: `${endYear}-07-31` };
            }
            return { start: null, end: null };
        }

        function getClassDates(dayOfWeek, startDate, endDate) {
            console.log('getClassDates:', { dayOfWeek, startDate, endDate });
            if (!dayOfWeek || !startDate || !endDate) {
                console.error('Invalid inputs for getClassDates:', { dayOfWeek, startDate, endDate });
                return [];
            }

            const dates = [];
            const currentDate = new Date(startDate);
            const endDateObj = new Date(endDate);
            const dayMapping = {
                'Monday': 1, 'Tuesday': 2, 'Wednesday': 3, 'Thursday': 4,
                'Friday': 5, 'Saturday': 6, 'Sunday': 0
            };

            const targetDayIndex = dayMapping[dayOfWeek];
            if (targetDayIndex === undefined) {
                console.error('Invalid dayOfWeek:', dayOfWeek);
                return [];
            }

            while (currentDate <= endDateObj) {
                if (currentDate.getDay() === targetDayIndex) {
                    dates.push(currentDate.toISOString().split('T')[0]);
                }
                currentDate.setDate(currentDate.getDate() + 1);
            }
            console.log('Valid class dates:', dates);
            return dates;
        }
    </script>
</body>
</html>