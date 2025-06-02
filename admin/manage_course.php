<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'database_connection.php';

// Authentication check (admin only)
if (!isset($_SESSION['admin_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access: Session data: " . print_r($_SESSION, true));
    header("Location: /attend_system/login.php");
    exit();
}

// Handle course deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_course'])) {
    $course_id = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT);
    if ($course_id) {
        try {
            // Begin transaction
            $conn->begin_transaction();

            // Delete schedules associated with the course
            $delete_schedules = $conn->prepare("DELETE FROM schedules WHERE course_id = ?");
            $delete_schedules->bind_param("i", $course_id);
            $delete_schedules->execute();
            $delete_schedules->close();

            // Delete course
            $delete_course = $conn->prepare("DELETE FROM courses WHERE course_id = ?");
            $delete_course->bind_param("i", $course_id);
            $delete_course->execute();
            $delete_course->close();

            // Commit transaction
            $conn->commit();
            $_SESSION['success_message'] = "Course deleted successfully.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Error deleting course: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "Invalid course ID.";
    }
    header("Location: /attend_system/admin/manage_course.php");
    exit();
}

// Handle search and pagination
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT)) : 1;
$per_page = 10; // Number of courses per page
$offset = ($page - 1) * $per_page;

// Build the query for courses
$query = "SELECT c.course_id, c.course_name, c.name_en, c.course_code, c.teacher_name, c.group_number, c.semester, c.c_year, c.year_code, 
                 GROUP_CONCAT(CONCAT(s.day_of_week, ' ', s.start_time, '-', s.end_time) SEPARATOR '; ') as schedules
          FROM courses c
          LEFT JOIN schedules s ON c.course_id = s.course_id
          WHERE c.course_name LIKE ? OR c.name_en LIKE ? OR c.course_code LIKE ? OR c.teacher_name LIKE ?
          GROUP BY c.course_id";
$search_param = "%$search%";
$stmt = $conn->prepare($query);
$stmt->bind_param("ssss", $search_param, $search_param, $search_param, $search_param);
$stmt->execute();
$result = $stmt->get_result();
$courses = [];
while ($row = $result->fetch_assoc()) {
    $courses[] = $row;
}
$stmt->close();

// Calculate total pages for pagination
$total_courses_query = "SELECT COUNT(DISTINCT course_id) FROM courses 
                       WHERE course_name LIKE ? OR name_en LIKE ? OR course_code LIKE ? OR teacher_name LIKE ?";
$stmt = $conn->prepare($total_courses_query);
$stmt->bind_param("ssss", $search_param, $search_param, $search_param, $search_param);
$stmt->execute();
$result = $stmt->get_result(); // Get the result set
$total_courses = $result->fetch_row()[0]; // Fetch the count
$stmt->close();
$total_pages = ceil($total_courses / $per_page);
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Courses</title>
    <link rel="stylesheet" href="/attend_system/admin/admin-styles.css">
    <style>
        /* Reuse styles from add_course.php for consistency */
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
            display: flex;
            flex-direction: column;
            align-items: center;
            background-color: #ecf0f1;
            height: 100%;
            overflow-y: auto;
        }

        .form-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 1000px;
            margin: 20px auto;
        }

        .search-bar {
            margin-bottom: 20px;
        }

        .search-bar input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 5px;
            font-size: 1em;
        }

        .course-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .course-table th, .course-table td {
            padding: 10px;
            border: 1px solid #d1d5db;
            text-align: left;
        }

        .course-table th {
            background-color: #2980b9;
            color: white;
        }

        .course-table tr:nth-child(even) {
            background-color: #f9fafb;
        }

        .action-buttons button {
            padding: 5px 10px;
            margin-right: 5px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        .edit-button {
            background-color: #3b82f6;
            color: white;
        }

        .edit-button:hover {
            background-color: #2563eb;
        }

        .delete-button {
            background-color: #ef4444;
            color: white;
        }

        .delete-button:hover {
            background-color: #dc2626;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }

        .pagination a {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 5px;
            text-decoration: none;
            color: #2980b9;
        }

        .pagination a.active, .pagination a:hover {
            background-color: #2980b9;
            color: white;
        }

        .message {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 4px;
            width: 100%;
            text-align: center;
        }

        .success {
            background-color: #d4edda;
            color: #155724;
        }

        .error {
            background-color: #f8d7da;
            color: #721c24;
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
        <h1>Manage Courses</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
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
            
                <li><a href="/attend_system/admin/enroll_student.php">Enroll Student</a></li>
                <li><a href="/attend_system/admin/logout.php">Logout</a></li>
            </ul>
        </aside>

        <div class="main-content">
            <section class="dashboard-section" id="manage-courses">
                <div class="form-container">
                    <h2>Manage Courses</h2>

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

                    <div class="search-bar">
                        <form method="GET" action="/attend_system/admin/manage_course.php">
                            <input type="text" name="search" placeholder="Search by course name, code, or teacher" value="<?php echo htmlspecialchars($search); ?>">
                            <button type="submit">Search</button>
                        </form>
                    </div>

                    <table class="course-table">
                        <thead>
                            <tr>
                                <th>Course Code</th>
                                <th>Course Name</th>
                                <th>Teacher</th>
                                <th>Semester</th>
                                <th>Year</th>
                                <th>Group</th>
                                <th>Schedules</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($courses)): ?>
                                <tr>
                                    <td colspan="8">No courses found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($courses as $course): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($course['course_code']); ?></td>
                                        <td><?php echo htmlspecialchars($course['course_name']); ?> (<?php echo htmlspecialchars($course['name_en']); ?>)</td>
                                        <td><?php echo htmlspecialchars($course['teacher_name']); ?></td>
                                        <td><?php echo htmlspecialchars(ucfirst($course['semester'])); ?></td>
                                        <td><?php echo htmlspecialchars($course['c_year']); ?> (<?php echo htmlspecialchars($course['year_code']); ?>)</td>
                                        <td><?php echo htmlspecialchars($course['group_number']); ?></td>
                                        <td><?php echo htmlspecialchars($course['schedules'] ?? 'No schedules'); ?></td>
                                        <td class="action-buttons">
                                            <a href="/attend_system/admin/edit_course.php?course_id=<?php echo $course['course_id']; ?>">
                                                <button class="edit-button">Edit</button>
                                            </a>
                                            <form method="POST" action="/attend_system/admin/manage_course.php" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this course?');">
                                                <input type="hidden" name="course_id" value="<?php echo $course['course_id']; ?>">
                                                <button type="submit" name="delete_course" class="delete-button">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>">Previous</a>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>">Next</a>
                        <?php endif; ?>
                    </div>

                    <a href="/attend_system/admin/admin_dashboard.php">
                        <button class="back-button">Back to Dashboard</button>
                    </a>
                </div>
            </section>
        </div>
    </div>

    <footer>
        <p>© <?php echo date("Y"); ?> University Admin System. All rights reserved.</p>
    </footer>
</body>
</html>