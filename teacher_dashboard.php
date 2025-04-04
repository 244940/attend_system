<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'database_connection.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    header("Location: login.php");
    exit();
}

// Get the teacher's information
$stmt = $conn->prepare("
    SELECT teacher_id, name 
    FROM teachers 
    WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    die("Error: Teacher not found.");
}
$teacher_data = $result->fetch_assoc();
$teacher_id = $teacher_data['teacher_id'];
$teacher_name = $teacher_data['name'];
$stmt->close();



// Get distinct days where teacher has courses
$days_query = "
    SELECT DISTINCT day_of_week 
    FROM courses 
    WHERE teacher_id = ?
    ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')";
$days_stmt = $conn->prepare($days_query);
$days_stmt->bind_param("i", $teacher_id);
$days_stmt->execute();
$days_result = $days_stmt->get_result();
$teaching_days = $days_result->fetch_all(MYSQLI_ASSOC);
$days_stmt->close();

function getFirstAndLastDateOfSemester($semester, $year) {
    // Thai academic year usually starts in June for first semester and November for second semester
    if ($semester == 1) {
        $startMonth = 6; // June
        $endMonth = 10; // October
    } else {
        $startMonth = 11; // November
        $endMonth = 3; // March of next year
        if ($endMonth < $startMonth) {
            $year++; // Increment year for second semester end date
        }
    }
    
    $startDate = date('Y-m-d', strtotime("$year-$startMonth-1"));
    $endDate = date('Y-m-t', strtotime("$year-$endMonth-1"));
    
    return array($startDate, $endDate);
}

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

// Get current semester and year (you might want to get these from your database or configuration)
$current_semester = 1; // or 2
$current_year = date('Y');

// Get semester date range
list($semester_start, $semester_end) = getFirstAndLastDateOfSemester($current_semester, $current_year);

// Modify the courses query to include schedule information
$get_courses_stmt = $conn->prepare("
    SELECT 
        course_id,
        course_name,
        course_code,
        day_of_week,
        start_time,
        end_time,
        group_number,
        semester,
        c_year
    FROM courses 
    WHERE teacher_id = ?
    ORDER BY day_of_week, start_time");
$get_courses_stmt->bind_param("i", $teacher_id);
$get_courses_stmt->execute();
$courses = $get_courses_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get all teaching dates for each course
$teaching_schedule = array();
foreach ($courses as $course) {
    $dates = getAllTeachingDates($course, $semester_start, $semester_end);
    $teaching_schedule[$course['course_id']] = $dates;
}


// Get teacher's courses for the selected day (default to current day)
//$selected_day = isset($_GET['day']) ? $_GET['day'] : date('l');



// Get teacher's courses
/*
$get_courses_stmt = $conn->prepare("
    SELECT 
        course_id,
        course_name,
        course_code,
        day_of_week,
        start_time,
        end_time,
        group_number,
        semester,
        c_year
    FROM courses 
    WHERE teacher_id = ?
    ORDER BY day_of_week, start_time");
$get_courses_stmt->bind_param("i", $teacher_id);
$get_courses_stmt->execute();
$courses = $get_courses_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$get_courses_stmt->close();
$conn->close();
*/
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
            grid-template-columns: repeat(7, 1fr); /* 7 days */
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
        

        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-bold mb-4">รายวิชาที่สอน</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($courses as $course): ?>
                    <button onclick="showAttendance('<?php echo $course['course_id']; ?>')" class="course-button">
                        <div class="font-bold"><?php echo htmlspecialchars($course['course_code']); ?></div>
                        <div class="text-sm"><?php echo htmlspecialchars($course['course_name']); ?></div>
                        <div class="text-sm">
                            กลุ่ม <?php echo htmlspecialchars($course['group_number']); ?> |
                            <?php echo htmlspecialchars($course['day_of_week']); ?> |
                            <?php echo substr($course['start_time'], 0, 5); ?> - <?php echo substr($course['end_time'], 0, 5); ?>
                        </div>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        
        

        <!-- Add day selector -->
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
                        <!-- Data will be populated by JavaScript -->
                    </tbody>
                </table>
            </div>

            <div class="chart-container">
                <canvas id="attendanceChart"></canvas>
            </div>
        </div>
    </div>

    <script>
        let currentChart = null;
                    function selectDate(date) {
                const selectedCourseId = document.querySelector('.course-button.selected')?.dataset.courseId;
                if (selectedCourseId) {
                    showAttendance(selectedCourseId);
                }
            }


            
// Modify the showAttendance function to handle date selection
function showAttendance(courseId) {
    // Update the date picker first
    updateDatePicker(courseId);
    
    // Update selected state of course buttons
    document.querySelectorAll('.course-button').forEach(button => {
        button.classList.remove('selected');
        button.style.backgroundColor = '#4CAF50';
        button.dataset.courseId = courseId; // Store courseId for reference
    });
    
    const selectedButton = document.querySelector(`[onclick="showAttendance('${courseId}')"]`);
    if (selectedButton) {
        selectedButton.classList.add('selected');
        selectedButton.style.backgroundColor = '#2E7D32';
    }

    const selectedDate = document.getElementById('selectedDate').value;
    document.getElementById('attendanceSection').style.display = 'block';
    
    // Show loading state
    document.getElementById('attendanceTableBody').innerHTML = 
        '<tr><td colspan="5" class="text-center">กำลังโหลดข้อมูล...</td></tr>';

    // Fetch attendance data
    fetch(`get_attendance.php?course_id=${courseId}&date=${selectedDate}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                throw new Error(data.error);
            }

            if (data.students && Array.isArray(data.students)) {
                updateAttendanceTable(data.students);
            }

            if (data.statistics) {
                updateAttendanceChart(data.statistics);
            }
        })
        .catch(error => {
            console.error("Error:", error);
            document.getElementById('attendanceTableBody').innerHTML = 
                `<tr><td colspan="5" class="text-center text-red-500">
                    ${error.message}
                </td></tr>`;
        });
}

        // Define semester date ranges
const SEMESTER_RANGES = {
    1: { // First semester
        startMonth: 6,  // June
        startDay: 24,
        endMonth: 11,   // November
        endDay: 4
    },
    2: { // Second semester
        startMonth: 11,  // November
        startDay: 25,
        endMonth: 3,    // March
        endDay: 31
    },
    3: { // Summer
        startMonth: 4,   // April
        startDay: 21,
        endMonth: 6,    // June
        endDay: 4
    }
};

function getValidDatesForCourse(course) {
    const semester = parseInt(course.semester);
    const year = parseInt(course.c_year);
    const dayOfWeek = course.day_of_week;
    
    // Get semester date range
    const range = SEMESTER_RANGES[semester];
    let startDate, endDate;
    
    if (semester === 2 && range.endMonth < range.startMonth) {
        // Handle second semester crossing year boundary
        startDate = new Date(year, range.startMonth - 1, range.startDay);
        endDate = new Date(year + 1, range.endMonth - 1, range.endDay);
    } else {
        startDate = new Date(year, range.startMonth - 1, range.startDay);
        endDate = new Date(year, range.endMonth - 1, range.endDay);
    }
    
    // Get all dates for the specified day of week within the semester
    const validDates = [];
    const current = new Date(startDate);
    const dayMapping = {
        'Monday': 1, 'Tuesday': 2, 'Wednesday': 3, 'Thursday': 4,
        'Friday': 5, 'Saturday': 6, 'Sunday': 0
    };
    const targetDay = dayMapping[dayOfWeek];
    
    while (current <= endDate) {
        if (current.getDay() === targetDay) {
            validDates.push(new Date(current));
        }
        current.setDate(current.getDate() + 1);
    }
    
    return validDates;
}

// Function to update date picker based on selected course
const courses = <?php echo json_encode($courses); ?>;
const teachingSchedule = <?php echo json_encode($teaching_schedule); ?>;

function updateDatePicker(courseId) {
    const datePicker = document.getElementById('selectedDate');
    const course = courses.find(c => c.course_id === courseId);
    
    if (!course || !teachingSchedule[courseId]) {
        console.error('Course or schedule not found');
        return;
    }

    // Get valid dates for this course from teaching schedule
    const validDates = teachingSchedule[courseId];

    // Set min and max dates
    if (validDates.length > 0) {
        datePicker.min = validDates[0];
        datePicker.max = validDates[validDates.length - 1];
        
        // Set initial value to the nearest valid date
        const today = new Date().toISOString().split('T')[0];
        const futureValidDate = validDates.find(date => date >= today) || validDates[0];
        datePicker.value = futureValidDate;
    }

    // Add event listener for date validation
    datePicker.onchange = function(e) {
        const selectedDate = this.value;
        if (!validDates.includes(selectedDate)) {
            alert('กรุณาเลือกวันที่มีการเรียนการสอนเท่านั้น');
            
            // Reset to nearest valid date
            const nearestDate = validDates.reduce((nearest, date) => {
                if (!nearest) return date;
                const diffNearest = Math.abs(new Date(nearest) - new Date(selectedDate));
                const diffCurrent = Math.abs(new Date(date) - new Date(selectedDate));
                return diffCurrent < diffNearest ? date : nearest;
            });
            
            this.value = nearestDate;
        }
        
        // Trigger attendance update with new date
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
                ภาคการศึกษาที่ ${course.semester}/${course.c_year}
            </p>
        `;
}

function updateAttendanceTable(students) {
    const tbody = document.getElementById('attendanceTableBody');
    tbody.innerHTML = '';

    students.forEach((student, index) => {
        const row = tbody.insertRow();
        
        // Add status class to the row
        row.className = `status-${student.status.toLowerCase()}`;
        
        row.innerHTML = `
            <td>${index + 1}</td>
            <td>${student.user_id}</td>
            <td>${student.name}</td>
            <td>${student.scan_time || 'ไม่มีการสแกน'}</td>
            <td class="font-medium">${translateStatus(student.status)}</td>
        `;
    });
}

function translateStatus(status) {
    const statusMap = {
        'Present': 'มาเรียน',
        'Late': 'สาย',
        'Absent': 'ขาดเรียน'
    };
    return statusMap[status] || status;


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
    if (currentChart) {
        currentChart.destroy();
    }

    const ctx = document.getElementById('attendanceChart').getContext('2d');
    currentChart = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: ['มาเรียน', 'สาย', 'ขาดเรียน'],
            datasets: [{
                data: [
                    statistics.present,
                    statistics.late,
                    statistics.absent
                ],
                backgroundColor: ['#4CAF50', '#f0ad4e', '#d9534f']
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'top'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const value = context.raw;
                            const total = statistics.total;
                            const percentage = ((value / total) * 100).toFixed(1);
                            return `${context.label}: ${value} คน (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
}


// Add auto-refresh functionality
function startAutoRefresh() {
    setInterval(() => {
        const selectedCourseId = document.querySelector('.course-button.selected')?.dataset.courseId;
        if (selectedCourseId) {
            showAttendance(selectedCourseId);
        }
    }, 30000); // Refresh every 30 seconds
}

// Start auto-refresh when page loads
document.addEventListener('DOMContentLoaded', startAutoRefresh);

        function logout() {
            window.location.href = 'logout.php';
        }
    </script>
</body>
</html>