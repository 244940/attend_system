<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'database_connection.php'; // Include your database connection

// Admin authentication check
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

// Redirect non-admin users
if (!isAdmin()) {
    error_log("Unauthorized access: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", user_role=" . ($_SESSION['user_role'] ?? 'unset'));
    header("Location: login.php");
    exit();
}

// Handle user deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $user_id = $_POST['user_id'];
    $user_role = $_POST['user_role'];

    try {
        $stmt = null;
        if ($user_role === 'admin') {
            $stmt = $conn->prepare("DELETE FROM admins WHERE admin_id = ?");
        } elseif ($user_role === 'teacher') {
            $stmt = $conn->prepare("DELETE FROM teachers WHERE teacher_id = ?");
        } elseif ($user_role === 'student') {
            // Delete related enrollments first
            $delete_enrollments = $conn->prepare("DELETE FROM enrollments WHERE student_id = ?");
            $delete_enrollments->bind_param("s", $user_id);
            $delete_enrollments->execute();
            $delete_enrollments->close();

            $stmt = $conn->prepare("DELETE FROM students WHERE student_id = ?");
        } else {
            throw new Exception("Invalid user role: $user_role");
        }

        $stmt->bind_param("s", $user_id);
        if ($stmt->execute()) {
            echo "User with ID $user_id deleted successfully.<br>";
        } else {
            throw new Exception("Error deleting user: " . $stmt->error);
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Delete user error: " . $e->getMessage());
        echo "Error deleting user: " . $e->getMessage() . "<br>";
    }
}

// Fetch and display users with search and sort functionality
function getUsers($conn) {
    // Initialize the base query using UNION ALL to combine users from admins, teachers, and students
    $query = "
        SELECT admin_id AS id, admin_name AS name, email, 'admin' AS user_role FROM admins
        UNION ALL
        SELECT teacher_id AS id, name, email, 'teacher' AS user_role FROM teachers
        UNION ALL
        SELECT student_id AS id, name, email, 'student' AS user_role FROM students
    ";

    // Handle search
    $conditions = [];
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $search = $conn->real_escape_string($_GET['search']);
        $conditions[] = "name LIKE '%$search%'";
    }

    // Add WHERE clause if there are search conditions
    if (!empty($conditions)) {
        $where_clause = " WHERE " . implode(" AND ", $conditions);
        $query = "
            SELECT * FROM (
                SELECT admin_id AS id, admin_name AS name, email, 'admin' AS user_role FROM admins
                UNION ALL
                SELECT teacher_id AS id, name, email, 'teacher' AS user_role FROM teachers
                UNION ALL
                SELECT student_id AS id, name, email, 'student' AS user_role FROM students
            ) AS combined_users
            $where_clause
        ";
    }

    // Handle sorting
    $sort_column = isset($_GET['sort']) ? $_GET['sort'] : 'name';
    $sort_order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';

    // Validate sort column to prevent SQL injection
    $valid_columns = ['name', 'id', 'user_role'];
    if (!in_array($sort_column, $valid_columns)) {
        $sort_column = 'name'; // Default to name if invalid
    }

    $query .= " ORDER BY $sort_column $sort_order";

    // Debug: Log the query
    error_log("Executing query: $query");

    // Execute the query
    $result = $conn->query($query);
    if ($result === false) {
        error_log("Query error: " . $conn->error);
        return [];
    }

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    return $users;
}

// Update user information and handle role change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $user_id = $_POST['user_id'];
    $name = $_POST['name'];
    $user_role = $_POST['user_role'];

    try {
        $conn->autocommit(FALSE);

        // Fetch previous role
        $old_role_query = "
            SELECT 'admin' AS user_role FROM admins WHERE admin_id = ?
            UNION ALL
            SELECT 'teacher' AS user_role FROM teachers WHERE teacher_id = ?
            UNION ALL
            SELECT 'student' AS user_role FROM students WHERE student_id = ?
            LIMIT 1
        ";
        $old_role_stmt = $conn->prepare($old_role_query);
        $old_role_stmt->bind_param("sss", $user_id, $user_id, $user_id);
        $old_role_stmt->execute();
        $old_role_result = $old_role_stmt->get_result();
        $old_role = $old_role_result->fetch_assoc()['user_role'] ?? null;
        $old_role_stmt->close();

        if (!$old_role) {
            throw new Exception("User with ID $user_id not found.");
        }

        // Remove from the previous role's table
        if ($old_role === 'student') {
            $delete_enrollments = $conn->prepare("DELETE FROM enrollments WHERE student_id = ?");
            $delete_enrollments->bind_param("s", $user_id);
            $delete_enrollments->execute();
            $delete_enrollments->close();

            $remove_stmt = $conn->prepare("DELETE FROM students WHERE student_id = ?");
        } elseif ($old_role === 'teacher') {
            $remove_stmt = $conn->prepare("DELETE FROM teachers WHERE teacher_id = ?");
        } elseif ($old_role === 'admin') {
            $remove_stmt = $conn->prepare("DELETE FROM admins WHERE admin_id = ?");
        }

        if (isset($remove_stmt)) {
            $remove_stmt->bind_param("s", $user_id);
            $remove_stmt->execute();
            $remove_stmt->close();
        }

        // Insert into new role's table
        if ($user_role === 'student') {
            $email = $name . '@example.com';
            $stmt = $conn->prepare("INSERT INTO students (student_id, name, email, citizen_id, gender, birth_date, phone_number, hashed_password, password_changed) VALUES (?, ?, ?, '0000000000000', 'other', '2000-01-01', '0000000000', NULL, 0)");
            $stmt->bind_param("sss", $user_id, $name, $email);
        } elseif ($user_role === 'teacher') {
            $email = $name . '@example.com';
            $stmt = $conn->prepare("INSERT INTO teachers (teacher_id, name, email, citizen_id, gender, birth_date, phone_number, hashed_password, password_changed) VALUES (?, ?, ?, '0000000000000', 'other', '2000-01-01', '0000000000', NULL, 0)");
            $stmt->bind_param("sss", $user_id, $name, $email);
        } elseif ($user_role === 'admin') {
            $email = $name . '@example.com';
            $stmt = $conn->prepare("INSERT INTO admins (admin_id, admin_name, email, citizen_id, gender, birth_date, phone_number, hashed_password, password_changed) VALUES (?, ?, ?, '0000000000000', 'other', '2000-01-01', '0000000000', NULL, 0)");
            $stmt->bind_param("sss", $user_id, $name, $email);
        } else {
            throw new Exception("Invalid new user role: $user_role");
        }

        if ($stmt->execute()) {
            echo "User updated successfully.<br>";
        } else {
            throw new Exception("Error updating user: " . $stmt->error);
        }
        $stmt->close();

        $conn->commit();
        $conn->autocommit(TRUE);
    } catch (Exception $e) {
        $conn->rollback();
        $conn->autocommit(TRUE);
        error_log("Update user error: " . $e->getMessage());
        echo "Error updating user: " . $e->getMessage() . "<br>";
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users</title>
    <link rel="stylesheet" href="admin-styles.css">
    <style>
        body, html {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
            height: 100%;
            display: flex;
            flex-direction: column;
            background-image: url('assets/bb.jpg');
            background-size: cover;
            background-position: center;
        }

        .top-bar {
            width: 100%;
            background-color: #2980b9;
            color: white;
            padding: 15px 20px;
            text-align: left;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
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

        .user-list {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            width: 100%;
        }

        .search-bar {
            margin-bottom: 20px;
            display: flex;
            justify-content: flex-end;
        }

        .search-bar input[type="text"] {
            padding: 8px;
            width: 200px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .search-bar input[type="submit"] {
            padding: 8px 15px;
            background-color: #2980b9;
            color: white;
            border: none;
            border-radius: 4px;
            margin-left: 10px;
            cursor: pointer;
        }

        .search-bar input[type="submit"]:hover {
            background-color: #3498db;
        }

        th a {
            color: #2980b9;
            text-decoration: none;
        }

        th a:hover {
            text-decoration: underline;
        }

        footer {
            text-align: center;
            padding: 10px;
            background-color: #34495e;
            color: white;
            width: 100%;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgb(0,0,0);
            background-color: rgba(0,0,0,0.4);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <h1>Manage Users</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?> (<?php echo htmlspecialchars($_SESSION['user_email']); ?>)</span>
        </div>
    </div>

    <div class="admin-container">
        <aside class="sidebar">
            <ul>
                <li><a href="admin_dashboard.php">Dashboard</a></li>
                <li><a href="manage_users.php">Manage Users</a></li>
                <li><a href="add_user.php">Add User</a></li>
                <li><a href="add_course.php">Add Course</a></li>
                <li><a href="enroll_student.php">Enroll Student</a></li>
                <li><a href="logout.php">Logout</a></li>
            </ul>
        </aside>

        <div class="main-content">
            <section class="dashboard-section" id="manage-users">
                <h2>Manage Users</h2>

                <!-- Search Bar -->
                <div class="search-bar">
                    <form method="GET" action="">
                        <input type="text" name="search" placeholder="Search by name..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                        <input type="submit" value="Search">
                    </form>
                </div>

                <!-- Display Current Users -->
                <div class="user-list">
                    <h3>Current Users</h3>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th style="border: 1px solid #ddd; padding: 8px;">
                                    <a href="?sort=name&order=<?php echo (isset($_GET['sort']) && $_GET['sort'] === 'name' && isset($_GET['order']) && $_GET['order'] === 'asc') ? 'desc' : 'asc'; ?>&search=<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">Name</a>
                                </th>
                                <th style="border: 1px solid #ddd; padding: 8px;">
                                    <a href="?sort=id&order=<?php echo (isset($_GET['sort']) && $_GET['sort'] === 'id' && isset($_GET['order']) && $_GET['order'] === 'asc') ? 'desc' : 'asc'; ?>&search=<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">ID</a>
                                </th>
                                <th style="border: 1px solid #ddd; padding: 8px;">
                                    <a href="?sort=user_role&order=<?php echo (isset($_GET['sort']) && $_GET['sort'] === 'user_role' && isset($_GET['order']) && $_GET['order'] === 'asc') ? 'desc' : 'asc'; ?>&search=<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">Role</a>
                                </th>
                                <th style="border: 1px solid #ddd; padding: 8px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Fetch and display users in a table
                            $users = getUsers($conn);
                            if (!empty($users)) {
                                foreach ($users as $user):
                            ?>
                            <tr>
                                <td style="border: 1px solid #ddd; padding: 8px;"><?php echo htmlspecialchars($user['name']); ?></td>
                                <td style="border: 1px solid #ddd; padding: 8px;"><?php echo htmlspecialchars($user['id']); ?></td>
                                <td style="border: 1px solid #ddd; padding: 8px;"><?php echo htmlspecialchars($user['user_role']); ?></td>
                                <td style="border: 1px solid #ddd; padding: 8px;">
                                    <button type="button" onclick="openModal('<?php echo htmlspecialchars($user['id']); ?>', '<?php echo htmlspecialchars($user['name']); ?>', '<?php echo htmlspecialchars($user['user_role']); ?>')" style="background-color: #f39c12; color: white; border: none; padding: 5px 10px; cursor: pointer;">Edit</button>
                                    <form action="manage_users.php" method="POST" style="display: inline;">
                                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['id']); ?>">
                                        <input type="hidden" name="user_role" value="<?php echo htmlspecialchars($user['user_role']); ?>">
                                        <button type="submit" name="delete_user" style="background-color: #e74c3c; color: white; border: none; padding: 5px 10px; cursor: pointer;" onclick="return confirm('Are you sure you want to delete this user?');">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php
                                endforeach;
                            } else {
                                echo "<tr><td colspan='4' style='border: 1px solid #ddd; padding: 8px; text-align: center;'>No users found</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Edit User Modal -->
            <div id="editUserModal" class="modal" style="display:none;">
                <div class="modal-content">
                    <span class="close" onclick="closeModal()">×</span>
                    <h2>Edit User</h2>
                    <form id="editUserForm" method="POST" action="">
                        <input type="hidden" name="user_id" id="editUserId">
                        <label for="editUserIdDisplay">User ID:</label>
                        <input type="text" name="user_id_display" id="editUserIdDisplay" readonly>
                        <label for="editUserName">Name:</label>
                        <input type="text" name="name" id="editUserName" required>
                        <label for="editUserRole">Role:</label>
                        <select name="user_role" id="editUserRole" required>
                            <option value="">Select Role</option>
                            <option value="student">Student</option>
                            <option value="teacher">Teacher</option>
                            <option value="admin">Admin</option>
                        </select>
                        <button type="submit" name="update_user">Update User</button>
                    </form>
                </div>
            </div>

            <script type='text/javascript'>
                function openModal(userId, userName, userRole) {
                    document.getElementById('editUserId').value = userId;
                    document.getElementById('editUserIdDisplay').value = userId;
                    document.getElementById('editUserName').value = userName;
                    document.getElementById('editUserRole').value = userRole;
                    document.getElementById('editUserModal').style.display = 'block';
                }

                function closeModal() {
                    document.getElementById('editUserModal').style.display = 'none';
                }

                window.onclick = function(event) {
                    if (event.target == document.getElementById('editUserModal')) {
                        closeModal();
                    }
                }
            </script>
        </div>
    </div>

    <footer>
        <p>© <?php echo date("Y"); ?> University Admin Dashboard. All rights reserved.</p>
    </footer>
</body>
</html>