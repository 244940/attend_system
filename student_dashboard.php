<?php 
session_start(); 
error_reporting(E_ALL); 
ini_set('display_errors', 1); 
require 'database_connection.php'; 

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') { 
    header("Location: login.php"); 
    exit(); 
} 

// Get the student's information
$stmt = $conn->prepare("SELECT student_id, name FROM students WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) { 
    die("Error: Student not found."); 
} 

$student_data = $result->fetch_assoc();
$student_id = $student_data['student_id'];
$student_name = $student_data['name'];
$stmt->close();

// Get courses for the student
$get_courses_stmt = $conn->prepare("
    SELECT c.course_id, c.course_name, c.course_code, c.day_of_week, c.semester, c.c_year 
    FROM courses AS c 
    JOIN enrollments AS e ON c.course_id = e.course_id 
    WHERE e.student_id = ? 
    ORDER BY c.semester
");
$get_courses_stmt->bind_param("i", $student_id);
$get_courses_stmt->execute();
$courses = $get_courses_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$get_courses_stmt->close();
$conn->close();

function getSemesterDateRange($semester, $year) {
    if ($semester == 1) {
        // First semester: June to October
        return ['start' => "2024-06-01", 'end' => "2024-10-31"];
    } elseif ($semester == 2) {
        // Second semester: November to March
        return ['start' => "2024-11-25", 'end' => "2025-03-31"];
    } elseif ($semester == 3) {
        // Summer semester: April to June
        return ['start' => "2024-04-21", 'end' => "2024-06-04"];
    } else {
        // Invalid semester
        return ['start' => null, 'end' => null];
    }
}

function getClassDates($dayOfWeek, $startDate, $endDate) {
    $dates = [];
    $current = strtotime($startDate);
    $end = strtotime($endDate);
    
    // Map day names to numerical values
    $dayMapping = [
        'Monday' => 1,
        'Tuesday' => 2,
        'Wednesday' => 3,
        'Thursday' => 4,
        'Friday' => 5,
        'Saturday' => 6,
        'Sunday' => 0
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
                <tbody id="attendanceTableBody"></tbody>
            </table>
        </div>
    </div>

    <script>
        function showAttendance(courseId, dayOfWeek, semester, year) {
            const attendanceTableBody = document.getElementById('attendanceTableBody');
            attendanceTableBody.innerHTML = '<tr><td colspan="2" class="text-center">Loading...</td></tr>';

            const { start, end } = getSemesterDateRange(semester, year);
            const validClassDates = getClassDates(dayOfWeek, start, end);

            fetch(`get_attendance.php?course_id=${courseId}&dates=${JSON.stringify(validClassDates)}`)
                .then(response => response.json())
                .then(data => {
                    attendanceTableBody.innerHTML = '';
                    validClassDates.forEach(date => {
                        const status = data[date] !== undefined ? data[date] : 'None'; // Display "None" if no attendance record exists
                        const row = attendanceTableBody.insertRow();
                        row.innerHTML = `<td class="border px-4 py-2">${date}</td><td class="border px-4 py-2">${status}</td>`;
                    });
                    document.getElementById('attendanceSection').style.display = 'block';
                })
                .catch(error => {
                    console.error("Error:", error);
                    attendanceTableBody.innerHTML = `<tr><td colspan="2" class="text-center text-red-500">Error loading data</td></tr>`;
                });
        }

        function logout() {
            window.location.href = 'logout.php';
        }

        function getSemesterDateRange(semester, year) {
            if (semester == 1) {
                return { start: "2024-06-01", end: "2024-10-31" }; // Adjust as needed for actual dates
            } else {
                return { start: "2024-11-25", end: "2025-03-31" }; // Adjust as needed for actual dates
            }
        }

        function getClassDates(dayOfWeek, startDate, endDate) {
            const dates = [];
            const currentDate = new Date(startDate);
            const endDateObj = new Date(endDate);

            const dayMapping = {
                'Monday': 1,
                'Tuesday': 2,
                'Wednesday': 3,
                'Thursday': 4,
                'Friday': 5,
                'Saturday': 6,
                'Sunday': 0
            };

            const targetDayIndex = dayMapping[dayOfWeek];

            while (currentDate <= endDateObj) {
                if (currentDate.getDay() === targetDayIndex) {
                    dates.push(currentDate.toISOString().split('T')[0]);
                }
                currentDate.setDate(currentDate.getDate() + 1);
            }

            return dates;
        }
        
    </script>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap');
* { font-family: 'Sarabun', sans-serif; }
.header { background-color: #71b773; padding: 1rem; color: white; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
.attendance-table { width: 100%; border-collapse: collapse; margin-top: 1rem; background-color: white; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
.attendance-table th { background-color: #f9e69e; font-weight: bold; }
.attendance-table th, .attendance-table td { padding: 0.75rem; text-align: left; border: 1px solid #e2e8f0; }
.attendance-table tbody tr:nth-child(even) { background-color: #f8f9fa; }
.attendance-table tbody tr:hover { background-color: #f5f5f5; }
.course-button { background-color: #4CAF50; color: white; padding: 0.75rem; border-radius: 0.5rem; transition: all 0.3s; border: none; cursor: pointer; margin-bottom: 10px; width: calc(100% - 10px); }
.course-button:hover { background-color: #45a049; transform: translateY(-2px); }
</style>

</body>
</html>