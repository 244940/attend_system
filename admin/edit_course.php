<?php
// edit_course.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
error_reporting(E_ALL);
ini_set('display_errors', 1);

// **** ADD THIS LINE ****
require 'database_connection.php'; // Or your actual database connection file name
// ***********************

require 'course_functions.php'; // Your functions rely on $conn being available

if (!isset($_SESSION['admin_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: /attend_system/login.php");
    exit();
}

$course_id = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
if (!$course_id) {
    $_SESSION['error_message'] = "Invalid course ID.";
    header("Location: /attend_system/admin/manage_course.php");
    exit();
}

// Line 24, $conn should now be defined
$course_stmt = $conn->prepare("SELECT * FROM courses WHERE course_id = ?");
if (!$course_stmt) {
    // It's good practice to log the actual DB error if prepare fails
    error_log("DB Prepare Error (fetch course): " . $conn->error);
    $_SESSION['error_message'] = "Database error: Unable to prepare course query.";
    header("Location: /attend_system/admin/manage_course.php");
    exit();
}
$course_stmt->bind_param("i", $course_id);
if (!$course_stmt->execute()) {
    error_log("DB Execute Error (fetch course): " . $course_stmt->error);
    $_SESSION['error_message'] = "Database error: Unable to execute course query.";
    header("Location: /attend_system/admin/manage_course.php");
    exit();
}
$course_result = $course_stmt->get_result();
if ($course_result->num_rows === 0) {
    $course_stmt->close();
    $_SESSION['error_message'] = "Course not found.";
    header("Location: /attend_system/admin/manage_course.php");
    exit();
}
$course = $course_result->fetch_assoc();
$course_stmt->close();

$schedule_stmt = $conn->prepare("SELECT * FROM schedules WHERE course_id = ? ORDER BY day_of_week, start_time"); // Added ORDER BY for consistency
if (!$schedule_stmt) {
    error_log("DB Prepare Error (fetch schedules): " . $conn->error);
    $_SESSION['error_message'] = "Database error: Unable to prepare schedules query.";
    header("Location: /attend_system/admin/manage_course.php");
    exit();
}
$schedule_stmt->bind_param("i", $course_id);
if (!$schedule_stmt->execute()) {
    error_log("DB Execute Error (fetch schedules): " . $schedule_stmt->error);
    $_SESSION['error_message'] = "Database error: Unable to execute schedules query.";
    header("Location: /attend_system/admin/manage_course.php");
    exit();
}
$schedule_result = $schedule_stmt->get_result();
$schedules = [];
while ($row = $schedule_result->fetch_assoc()) {
    // Ensure time is formatted for HTML5 time input (HH:MM) if it's stored as HH:MM:SS
    $row['start_time'] = date("H:i", strtotime($row['start_time']));
    $row['end_time'] = date("H:i", strtotime($row['end_time']));
    $schedules[] = $row;
}
$schedule_stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_course'])) {
    $teacher_name = trim($_POST['teacher_name']);
    // No need to re-fetch teacher_id here if your update_course function handles teacher_name correctly
    // The update_course function should resolve teacher_name to teacher_id.

    $course_data = [
        'course_name' => trim($_POST['course_name']),
        'name_en' => trim($_POST['course_name_en']),
        'course_code' => trim($_POST['course_code']),
        'teacher_name' => $teacher_name, // Pass teacher_name, function will resolve ID
        'group_number' => trim($_POST['group_number']),
        'semester' => strtolower(trim($_POST['semester'])),
        'c_year' => trim($_POST['c_year']),
        'year_code' => trim($_POST['year_code'])
    ];

    $new_schedules_from_post = [];
    if (isset($_POST['schedules']) && is_array($_POST['schedules'])) {
        foreach ($_POST['schedules'] as $schedule_item) {
            if (!empty($schedule_item['day_of_week']) && !empty($schedule_item['start_time']) && !empty($schedule_item['end_time'])) {
                $new_schedules_from_post[] = [
                    'day_of_week' => trim($schedule_item['day_of_week']),
                    'start_time' => trim($schedule_item['start_time']),
                    'end_time' => trim($schedule_item['end_time'])
                ];
            }
        }
    }

    if (empty($new_schedules_from_post)) {
        $_SESSION['error_message'] = "At least one schedule is required.";
        header("Location: /attend_system/admin/edit_course.php?course_id=$course_id");
        exit();
    }

    // $conn is passed to update_course
    $result = update_course($conn, $course_id, $course_data, $new_schedules_from_post);

    if ($result === null) {
        $_SESSION['success_message'] = "Course updated successfully.";
        header("Location: /attend_system/admin/manage_course.php");
        exit();
    } else {
        $_SESSION['error_message'] = $result; // This will show errors from update_course
        header("Location: /attend_system/admin/edit_course.php?course_id=$course_id");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Course</title>
    <link rel="stylesheet" href="/attend_system/admin/admin-styles.css">
    <style>
        body, html {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
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
            height: calc(100vh - 70px); /* Adjusted for top-bar height */
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
            height: 100%; /* Fill available height */
            box-sizing: border-box;
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

        .main-content {
            flex: 1;
            padding: 20px;
            display: flex; /* Use flex for centering form-container */
            flex-direction: column;
            align-items: center; /* Center form-container horizontally */
            /* background-color: #ecf0f1; */ /* Can be removed if background image is main focus */
            height: 100%; /* Fill available height */
            overflow-y: auto; /* Allow scrolling for content */
            box-sizing: border-box;
        }

        .form-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 800px; /* Max width for the form */
            margin: 20px 0; /* Margin top and bottom, auto for left/right is handled by flex align-items */
        }

        .form-container h2 {
            margin-top: 0;
            color: #2980b9;
            font-size: 1.5em;
            text-align: center;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            color: #4b5563;
            font-weight: 500;
        }

        input[type="text"],
        input[type="number"],
        input[type="time"],
        select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 5px;
            font-size: 1em;
            outline: none;
            transition: border-color 0.2s ease;
            box-sizing: border-box; /* Include padding and border in element's total width and height */
        }

        input:focus, select:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }

        button {
            background-color: #3b82f6;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
            transition: background-color 0.2s ease;
        }

        button:hover {
            background-color: #2563eb;
        }

        .back-button {
            background-color: #6b7280;
            margin-top: 20px;
            display: inline-block; /* To allow margin-top */
        }

        .back-button:hover {
            background-color: #4b5563;
        }

        .message {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 4px;
            width: 100%;
            text-align: center;
            box-sizing: border-box;
        }

        .success {
            background-color: #d4edda;
            color: #155724;
        }

        .error {
            background-color: #f8d7da;
            color: #721c24;
        }

        .schedule-group {
            border: 1px solid #d1d5db;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 5px;
            position: relative;
            background-color: #f9fafb;
        }

        .remove-schedule {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: #ef4444;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9em;
        }

        .remove-schedule:hover {
            background-color: #dc2626;
        }

        .add-schedule {
            background-color: #10b981;
            margin-bottom: 20px;
        }

        .add-schedule:hover {
            background-color: #059669;
        }

        footer {
            text-align: center;
            padding: 10px;
            background-color: #34495e;
            color: white;
            width: 100%;
            margin-top: auto; /* Push footer to the bottom if content is short */
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <h1>Edit Course</h1>
        <div class="user-info">
            <span><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
        </div>
    </div>

    <div class="admin-container">
        <aside class="sidebar">
            <ul>
                <li><a href="/attend_system/admin/admin_dashboard.php">Dashboard</a></li>
                <li><a href="/attend_system/admin/manage_users.php">Manage Users</a></li>
                <li><a href="/attend_system/admin/add_users.php">Add User</a></li>
                <li><a href="/attend_system/admin/manage_course.php">Manage Courses</a></li>
                <li><a href="/attend_system/admin/add_course.php">Add Course</a></li>
                <li><a href="/attend_system/admin/manage_enrollments.php">Manage Enrollments</a></li>
                <li><a href="/attend_system/admin/enroll_student.php">Enroll Student</a></li>
                <li><a href="/attend_system/logout.php">Logout</a></li>
            </ul>
        </aside>

        <div class="main-content">
            <section class="dashboard-section" id="edit-course">
                <div class="form-container">
                    <h2>Edit Course: <?php echo htmlspecialchars($course['course_code'] . " - " . $course['course_name']); ?></h2>

                    <?php
                    if (isset($_SESSION['success_message'])) {
                        echo '<div class="message success">' . htmlspecialchars($_SESSION['success_message']) . '</div>';
                        unset($_SESSION['success_message']);
                    }
                    if (isset($_SESSION['error_message'])) {
                        echo '<div class="message error">' . htmlspecialchars($_SESSION['error_message']) . '</div>';
                        unset($_SESSION['error_message']);
                    }
                    ?>

                    <form action="/attend_system/admin/edit_course.php?course_id=<?php echo $course_id; ?>" method="POST">
                        <div class="form-group">
                            <label for="course_name">Course Name (Thai)</label>
                            <input type="text" id="course_name" name="course_name" value="<?php echo htmlspecialchars($course['course_name']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="course_name_en">Course Name (English)</label>
                            <input type="text" id="course_name_en" name="course_name_en" value="<?php echo htmlspecialchars($course['name_en']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="course_code">Course Code</label>
                            <input type="text" id="course_code" name="course_code" value="<?php echo htmlspecialchars($course['course_code']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="semester">Semester</label>
                            <select id="semester" name="semester" required>
                                <option value="first" <?php echo $course['semester'] === 'first' ? 'selected' : ''; ?>>First</option>
                                <option value="second" <?php echo $course['semester'] === 'second' ? 'selected' : ''; ?>>Second</option>
                                <option value="summer" <?php echo $course['semester'] === 'summer' ? 'selected' : ''; ?>>Summer</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="c_year">Year (Full, e.g., 2025, ค.ศ.)</label>
                            <input type="number" id="c_year" name="c_year" min="1900" max="2155" value="<?php echo htmlspecialchars($course['c_year']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="year_code">Year Code (Last 2 digits, e.g., 60 for 2560)</label>
                            <input type="text" id="year_code" name="year_code" pattern="\d{1,2}" maxlength="2" value="<?php echo htmlspecialchars($course['year_code']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="group_number">Group Number</label>
                            <input type="number" id="group_number" name="group_number" value="<?php echo htmlspecialchars($course['group_number']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="teacher_name">Teacher Name</label>
                            <select id="teacher_name" name="teacher_name" required>
                                <?php
                                // $conn should be available here due to the require 'database_connection.php' at the top
                                $teacher_list_stmt = $conn->prepare("SELECT name FROM teachers ORDER BY name");
                                if ($teacher_list_stmt) {
                                    $teacher_list_stmt->execute();
                                    $teacher_list_result = $teacher_list_stmt->get_result();
                                    $available_teachers = [];
                                    while ($teacher_row = $teacher_list_result->fetch_assoc()) {
                                        $available_teachers[] = $teacher_row['name'];
                                        $selected = ($teacher_row['name'] === $course['teacher_name']) ? 'selected' : '';
                                        echo '<option value="' . htmlspecialchars($teacher_row['name']) . '" ' . $selected . '>' . htmlspecialchars($teacher_row['name']) . '</option>';
                                    }
                                    $teacher_list_stmt->close();
                                    if (empty($available_teachers)) {
                                        echo '<option value="">No teachers available</option>';
                                    }
                                } else {
                                    echo '<option value="">Error loading teachers</option>';
                                    error_log("DB Prepare Error (teacher list dropdown): " . $conn->error);
                                }
                                ?>
                            </select>
                        </div>

                        <h2>Schedules</h2>
                        <div id="schedules-container">
                            <?php if (!empty($schedules)): ?>
                                <?php foreach ($schedules as $index => $schedule_item): ?>
                                    <div class="schedule-group">
                                        <button type="button" class="remove-schedule" onclick="removeSchedule(this)">Remove</button>
                                        <div class="form-group">
                                            <label>Day of the Week</label>
                                            <select name="schedules[<?php echo $index; ?>][day_of_week]" required>
                                                <option value="Monday" <?php echo $schedule_item['day_of_week'] === 'Monday' ? 'selected' : ''; ?>>Monday</option>
                                                <option value="Tuesday" <?php echo $schedule_item['day_of_week'] === 'Tuesday' ? 'selected' : ''; ?>>Tuesday</option>
                                                <option value="Wednesday" <?php echo $schedule_item['day_of_week'] === 'Wednesday' ? 'selected' : ''; ?>>Wednesday</option>
                                                <option value="Thursday" <?php echo $schedule_item['day_of_week'] === 'Thursday' ? 'selected' : ''; ?>>Thursday</option>
                                                <option value="Friday" <?php echo $schedule_item['day_of_week'] === 'Friday' ? 'selected' : ''; ?>>Friday</option>
                                                <option value="Saturday" <?php echo $schedule_item['day_of_week'] === 'Saturday' ? 'selected' : ''; ?>>Saturday</option>
                                                <option value="Sunday" <?php echo $schedule_item['day_of_week'] === 'Sunday' ? 'selected' : ''; ?>>Sunday</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Start Time</label>
                                            <input type="time" name="schedules[<?php echo $index; ?>][start_time]" value="<?php echo htmlspecialchars($schedule_item['start_time']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>End Time</label>
                                            <input type="time" name="schedules[<?php echo $index; ?>][end_time]" value="<?php echo htmlspecialchars($schedule_item['end_time']); ?>" required>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: // Provide a blank schedule group if none exist ?>
                                <div class="schedule-group">
                                    <button type="button" class="remove-schedule" onclick="removeSchedule(this)">Remove</button>
                                    <div class="form-group">
                                        <label>Day of the Week</label>
                                        <select name="schedules[0][day_of_week]" required>
                                            <option value="Monday">Monday</option>
                                            <option value="Tuesday">Tuesday</option>
                                            <option value="Wednesday">Wednesday</option>
                                            <option value="Thursday">Thursday</option>
                                            <option value="Friday">Friday</option>
                                            <option value="Saturday">Saturday</option>
                                            <option value="Sunday">Sunday</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Start Time</label>
                                        <input type="time" name="schedules[0][start_time]" required>
                                    </div>
                                    <div class="form-group">
                                        <label>End Time</label>
                                        <input type="time" name="schedules[0][end_time]" required>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="add-schedule" onclick="addSchedule()">Add Another Schedule</button>

                        <button type="submit" name="update_course">Save Changes</button>
                    </form>

                    <a href="/attend_system/admin/manage_course.php">
                        <button class="back-button">Back to Manage Courses</button>
                    </a>
                </div>
            </section>
        </div>
    </div>

    <footer>
        <p>© <?php echo date("Y"); ?> University Admin System. All rights reserved.</p>
    </footer>

    <script>
        let scheduleIndex = <?php echo max(1, count($schedules)); // Start index after existing schedules, or 1 if none ?>;

        function addSchedule() {
            const container = document.getElementById('schedules-container');
            const newScheduleGroup = document.createElement('div');
            newScheduleGroup.className = 'schedule-group';
            newScheduleGroup.innerHTML = `
                <button type="button" class="remove-schedule" onclick="removeSchedule(this)">Remove</button>
                <div class="form-group">
                    <label>Day of the Week</label>
                    <select name="schedules[${scheduleIndex}][day_of_week]" required>
                        <option value="Monday">Monday</option>
                        <option value="Tuesday">Tuesday</option>
                        <option value="Wednesday">Wednesday</option>
                        <option value="Thursday">Thursday</option>
                        <option value="Friday">Friday</option>
                        <option value="Saturday">Saturday</option>
                        <option value="Sunday">Sunday</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Start Time</label>
                    <input type="time" name="schedules[${scheduleIndex}][start_time]" required>
                </div>
                <div class="form-group">
                    <label>End Time</label>
                    <input type="time" name="schedules[${scheduleIndex}][end_time]" required>
                </div>
            `;
            container.appendChild(newScheduleGroup);
            scheduleIndex++;
        }

        function removeSchedule(button) {
            const scheduleGroups = document.querySelectorAll('.schedule-group');
            if (scheduleGroups.length > 1) {
                button.parentElement.remove();
                // No need to decrement scheduleIndex here, as new additions will use the incremented value.
                // If you re-index elements, it becomes more complex.
            } else {
                alert('At least one schedule is required.');
            }
        }
    </script>
</body>
</html>

<?php
if (isset($conn) && $conn instanceof mysqli) { // Check if $conn is a valid mysqli object
    $conn->close();
}
?>