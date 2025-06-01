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
    $role_specific_id = $_POST['role_specific_id'];
    $user_role = $_POST['user_role'];

    try {
        $stmt = null;
        if ($user_role === 'admin') {
            $stmt = $conn->prepare("DELETE FROM admins WHERE admin_id = ?");
        } elseif ($user_role === 'teacher') {
            // Delete related courses first
            $delete_courses = $conn->prepare("DELETE FROM courses WHERE teacher_id = ?");
            $delete_courses->bind_param("s", $role_specific_id);
            $delete_courses->execute();
            $delete_courses->close();

            $stmt = $conn->prepare("DELETE FROM teachers WHERE teacher_id = ?");
        } elseif ($user_role === 'student') {
            // Delete related enrollments first
            $delete_enrollments = $conn->prepare("DELETE FROM enrollments WHERE student_id = ?");
            $delete_enrollments->bind_param("s", $role_specific_id);
            $delete_enrollments->execute();
            $delete_enrollments->close();

            $stmt = $conn->prepare("DELETE FROM students WHERE student_id = ?");
        } else {
            throw new Exception("Invalid user role: $user_role");
        }

        $stmt->bind_param("s", $role_specific_id);
        if ($stmt->execute()) {
            $stmt->close();
            $_SESSION['success_message'] = "User with ID $role_specific_id deleted successfully.";
            header("Location: manage_users.php");
            exit();
        } else {
            $error = $stmt->error;
            $stmt->close();
            throw new Exception("Error deleting user: $error");
        }
    } catch (Exception $e) {
        error_log("Delete user error for role_specific_id=$role_specific_id: " . $e->getMessage());
        $_SESSION['error_message'] = "Error deleting user: " . $e->getMessage();
        header("Location: manage_users.php");
        exit();
    }
}

// Handle user update from Sidebar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $old_role_specific_id = $_POST['old_role_specific_id']; // The original role-specific ID
    $new_role_specific_id = $_POST['role_specific_id']; // The new role-specific ID (may be changed)
    $name = $_POST['name'];
    $name_en = $_POST['name_en'];
    $email = $_POST['email'];
    $citizen_id = $_POST['citizen_id'];
    $gender = $_POST['gender'];
    $birth_date = $_POST['birth_date'];
    $phone_number = $_POST['phone_number'];
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
        $old_role_stmt->bind_param("sss", $old_role_specific_id, $old_role_specific_id, $old_role_specific_id);
        $old_role_stmt->execute();
        $old_role_result = $old_role_stmt->get_result();
        $old_role = $old_role_result->fetch_assoc()['user_role'] ?? null;
        $old_role_stmt->close();

        if (!$old_role) {
            throw new Exception("User with ID $old_role_specific_id not found.");
        }

        // Check if the new role-specific ID already exists (if it has changed and the role hasn't changed)
        if ($old_role_specific_id !== $new_role_specific_id && $old_role === $user_role) {
            $check_new_id_query = "";
            if ($user_role === 'admin') {
                $check_new_id_query = "SELECT admin_id FROM admins WHERE admin_id = ?";
            } elseif ($user_role === 'teacher') {
                $check_new_id_query = "SELECT teacher_id FROM teachers WHERE teacher_id = ?";
            } elseif ($user_role === 'student') {
                $check_new_id_query = "SELECT student_id FROM students WHERE student_id = ?";
            }
            $check_stmt = $conn->prepare($check_new_id_query);
            $check_stmt->bind_param("s", $new_role_specific_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            if ($check_result->num_rows > 0) {
                $check_stmt->close();
                throw new Exception("The new $user_role ID $new_role_specific_id already exists.");
            }
            $check_stmt->close();

            // Update related tables if the ID has changed
            if ($old_role === 'student') {
                $update_enrollments = $conn->prepare("UPDATE enrollments SET student_id = ? WHERE student_id = ?");
                $update_enrollments->bind_param("ss", $new_role_specific_id, $old_role_specific_id);
                $update_enrollments->execute();
                $update_enrollments->close();
            } elseif ($old_role === 'teacher') {
                $update_courses = $conn->prepare("UPDATE courses SET teacher_id = ? WHERE teacher_id = ?");
                $update_courses->bind_param("ss", $new_role_specific_id, $old_role_specific_id);
                $update_courses->execute();
                $update_courses->close();
            }
        }

        // Remove from the previous role's table
        if ($old_role === 'student') {
            $delete_enrollments = $conn->prepare("DELETE FROM enrollments WHERE student_id = ?");
            $delete_enrollments->bind_param("s", $old_role_specific_id);
            $delete_enrollments->execute();
            $delete_enrollments->close();

            $remove_stmt = $conn->prepare("DELETE FROM students WHERE student_id = ?");
        } else if ($old_role === 'teacher') {
            $delete_courses = $conn->prepare("DELETE FROM courses WHERE teacher_id = ?");
            $delete_courses->bind_param("s", $old_role_specific_id);
            $delete_courses->execute();
            $delete_courses->close();

            $remove_stmt = $conn->prepare("DELETE FROM teachers WHERE teacher_id = ?");
        } else if ($old_role === 'admin') {
            $remove_stmt = $conn->prepare("DELETE FROM admins WHERE admin_id = ?");
        }

        if (isset($remove_stmt)) {
            $remove_stmt->bind_param("s", $old_role_specific_id);
            $remove_stmt->execute();
            $remove_stmt->close();
        }

        // Insert into new role's table with updated data (using the new role-specific ID)
        if ($user_role === 'student') {
            $stmt = $conn->prepare("INSERT INTO students (student_id, name, name_en, email, citizen_id, gender, birth_date, phone_number, hashed_password, password_changed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 0)");
            $stmt->bind_param("ssssssss", $new_role_specific_id, $name, $name_en, $email, $citizen_id, $gender, $birth_date, $phone_number);
        } elseif ($user_role === 'teacher') {
            $stmt = $conn->prepare("INSERT INTO teachers (teacher_id, name, name_en, email, citizen_id, gender, birth_date, phone_number, hashed_password, password_changed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 0)");
          $stmt->bind_param("ssssssss", $new_role_specific_id, $name, $name_en, $email, $citizen_id, $gender, $birth_date, $phone_number);
        } elseif ($user_role === 'admin') {
            $stmt = $conn->prepare("INSERT INTO admins (admin_id, admin_name, name_en, email, citizen_id, gender, birth_date, phone_number, hashed_password, password_changed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 0)");
            $stmt->bind_param("ssssssss", $new_role_specific_id, $name, $name_en, $email, $citizen_id, $gender, $birth_date, $phone_number);
        } else {
            error_log("Update user error: Invalid new user role: $user_role");
            throw new Exception("Invalid new user role: $user_role");
        }

        if ($stmt->execute()) {
            $stmt->close();
            $conn->commit();
            $_SESSION['success_message'] = "User updated successfully.";
            header("Location: manage_users.php");
            exit();
        } else {
            $error = $stmt->error;
            $stmt->close();
            throw new Exception("Error updating user: $error");
        }
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Update user error for role_specific_id=$old_role_specific_id: " . $e->getMessage());
        $_SESSION['error_message'] = "Error updating user: " . $e->getMessage();
        header("Location: manage_users.php");
        exit();
    } finally {
        $conn->autocommit(TRUE);
    }
}

// Fetch and display users with search, role filter, and sort functionality
function getUsers($conn) {
    $base_query = "
        SELECT admin_id AS id, admin_name AS name, name_en, email, citizen_id, gender, birth_date, phone_number, 'admin' AS user_role FROM admins
        UNION ALL
        SELECT teacher_id AS id, name, name_en, email, citizen_id, gender, birth_date, phone_number, 'teacher' AS user_role FROM teachers
        UNION ALL
        SELECT student_id AS id, name, name_en, email, citizen_id, gender, birth_date, phone_number, 'student' AS user_role FROM students
    ";

    // Handle role filter, search, and sorting
    $role_filter = isset($_GET['role_filter']) && in_array($_GET['role_filter'], ['admin', 'teacher', 'student']) ? $_GET['role_filter'] : '';
    $search = isset($_GET['search']) && !empty(trim($_GET['search'])) ? trim($_GET['search']) : '';
    $sort_column = isset($_GET['sort']) && in_array($_GET['sort'], ['name', 'id', 'user_role']) ? $_GET['sort'] : 'name';
    $sort_order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';

    // Build query
    $query = "SELECT * FROM ($base_query) AS combined_users WHERE 1=1";
    $params = [];
    $types = '';

    if (!empty($search)) {
        $query .= " AND name LIKE ?";
        $params[] = "%$search%";
        $types .= 's';
    }

    if (!empty($role_filter)) {
        $query .= " AND user_role = ?";
        $params[] = $role_filter;
        $types .= 's';
    }

    $query .= " ORDER BY $sort_column $sort_order";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Query prepare failed: " . $conn->error);
        return [];
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        error_log("Query execute failed: " . $stmt->error);
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $result->free();
    $stmt->close();

    return $users;
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
            position: relative;
        }

        .user-list {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            width: 100%;
        }

        .search-bar {
            margin-bottom: 20px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 10px;
        }

        .search-bar select,
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

        tr {
            cursor: pointer;
        }

        tr:hover {
            background-color: #f5f5f5;
        }

        footer {
            text-align: center;
            padding: 10px;
            background-color: #34495e;
            color: white;
            width: 100%;
        }

        .details-sidebar {
            position: fixed;
            top: 0;
            right: -350px;
            width: 350px;
            height: 100%;
            background-color: white;
            box-shadow: -2px 0 5px rgba(0,0,0,0.3);
            padding: 20px;
            z-index: 1000;
            transition: right 0.3s ease-in-out;
            overflow-y: auto;
        }

        .details-sidebar.open {
            right: 0;
        }

        .details-sidebar h2 {
            margin-top: 0;
            font-size: 24px;
            color: #2980b9;
        }

        .details-sidebar form {
            margin-top: 20px;
        }

        .details-sidebar label {
            display: block;
            margin: 10px 0 5px;
            font-weight: bold;
        }

        .details-sidebar input,
        .details-sidebar select {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }

        .details-sidebar input[readonly] {
            background-color: #f0f0f0;
        }

        .details-sidebar button {
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-right: 10px;
            margin-top: 10px;
        }

        .details-sidebar .save-btn {
            background-color: #2980b9;
            color: white;
        }

        .details-sidebar .save-btn:hover {
            background-color: #3498db;
        }

        .details-sidebar .delete-btn {
            background-color: #e74c3c;
            color: white;
        }

        .details-sidebar .delete-btn:hover {
            background-color: #c0392b;
        }

        .details-sidebar .close-btn {
            position: absolute;
            top: 10px;
            left: 10px;
            font-size: 24px;
            cursor: pointer;
            color: #e74c3c;
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
    </style>
</head>
<body>
    <div class="top-bar">
        <h1>Manage Users</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
        </div>
    </div>

    <div class="admin-container">
        <aside class="sidebar">
            <ul>
                <li><a href="admin_dashboard.php">Dashboard</a></li>
                <li><a href="manage_users.php">Manage Users</a></li>
                <li><a href="add_users.php">Add User</a></li>
                <li><a href="add_course.php">Add Course</a></li>
                <li><a href="enroll_student.php">Enroll Student</a></li>
                <li><a href="logout.php">Logout</a></li>
            </ul>
        </aside>

        <div class="main-content">
            <section class="dashboard-section" id="manage-users">
                <h2>Manage Users</h2>

                <!-- Display Flash Messages -->
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

                <!-- Search Bar with Role Filter -->
                <div class="search-bar">
                    <form method="GET" action="">
                        <select name="role_filter" onchange="this.form.submit()">
                            <option value="" <?php echo (!isset($_GET['role_filter']) || $_GET['role_filter'] === '') ? 'selected' : ''; ?>>All Roles</option>
                            <option value="admin" <?php echo (isset($_GET['role_filter']) && $_GET['role_filter'] === 'admin') ? 'selected' : ''; ?>>Admin</option>
                            <option value="teacher" <?php echo (isset($_GET['role_filter']) && $_GET['role_filter'] === 'teacher') ? 'selected' : ''; ?>>Teacher</option>
                            <option value="student" <?php echo (isset($_GET['role_filter']) && $_GET['role_filter'] === 'student') ? 'selected' : ''; ?>>Student</option>
                        </select>
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
                                    <a href="?sort=name&order=<?php echo (isset($_GET['sort']) && $_GET['sort'] === 'name' && isset($_GET['order']) && $_GET['order'] === 'asc') ? 'desc' : 'asc'; ?>&search=<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>&role_filter=<?php echo isset($_GET['role_filter']) ? htmlspecialchars($_GET['role_filter']) : ''; ?>">Name</a>
                                </th>
                                <th style="border: 1px solid #ddd; padding: 8px;">
                                    <a href="?sort=id&order=<?php echo (isset($_GET['sort']) && $_GET['sort'] === 'id' && isset($_GET['order']) && $_GET['order'] === 'asc') ? 'desc' : 'asc'; ?>&search=<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>&role_filter=<?php echo isset($_GET['role_filter']) ? htmlspecialchars($_GET['role_filter']) : ''; ?>">ID</a>
                                </th>
                                <th style="border: 1px solid #ddd; padding: 8px;">
                                    <a href="?sort=user_role&order=<?php echo (isset($_GET['sort']) && $_GET['sort'] === 'user_role' && isset($_GET['order']) && $_GET['order'] === 'asc') ? 'desc' : 'asc'; ?>&search=<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>&role_filter=<?php echo isset($_GET['role_filter']) ? htmlspecialchars($_GET['role_filter']) : ''; ?>">Role</a>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $users = getUsers($conn);
                            if (!empty($users)) {
                                foreach ($users as $user):
                            ?>
                                <tr onclick="showDetails('<?php echo htmlspecialchars($user['id']); ?>', '<?php echo htmlspecialchars($user['name']); ?>', '<?php echo htmlspecialchars($user['name_en']); ?>', '<?php echo htmlspecialchars($user['email']); ?>', '<?php echo htmlspecialchars($user['citizen_id']); ?>', '<?php echo htmlspecialchars($user['gender']); ?>', '<?php echo htmlspecialchars($user['birth_date']); ?>', '<?php echo htmlspecialchars($user['phone_number']); ?>', '<?php echo htmlspecialchars($user['user_role']); ?>')">
                                    <td style="border: 1px solid #ddd; padding: 8px;"><?php echo htmlspecialchars($user['name']); ?></td>
                                    <td style="border: 1px solid #ddd; padding: 8px;"><?php echo htmlspecialchars($user['id']); ?></td>
                                    <td style="border: 1px solid #ddd; padding: 8px;"><?php echo htmlspecialchars($user['user_role']); ?></td>
                                </tr>
                            <?php
                                endforeach;
                            } else {
                                echo "<tr><td colspan='3' style='border: 1px solid #ddd; padding: 8px; text-align: center;'>No users found</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Details Sidebar -->
            <div id="detailsSidebar" class="details-sidebar">
                <span class="close-btn" onclick="closeDetails()">×</span>
                <h2>User Details</h2>
                <form method="POST" action="">
                    <input type="hidden" name="old_role_specific_id" id="detailOldId">
                    <label id="roleSpecificIdLabel" for="detailId">ID:</label>
                    <input type="text" name="role_specific_id" id="detailId" required>
                    <input type="hidden" name="user_role" id="detailRoleHidden">
                    <label for="detailName">Name (TH):</label>
                    <input type="text" name="name" id="detailName" required>
                    <label for="detailNameEn">Name En):</label>
                    <input type="text" name="detailNameEn" id="name_en" required>
                    <label for="detailEmail">Email:</label>
                    <input type="email" name="email" id="detailEmail" required>
                    <label for="detailCitizenId">Citizen ID:</label>
                    <input type="text" name="citizen_id" id="detailCitizenId" pattern="\d{13}" required>
                    <label for="detailGender">Gender:</label>
                    <select name="gender" id="detailGender" required>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                    <option value="other">Other</option>
                    </select>
                    <label for="detailBirthDate">Birth Date:</label>
                    <input type="date" name="birth_date" id="detailBirthDate" required>
                    <label for="detailPhoneNumber">Phone Number:</label>
                    <input type="text" name="phone_number" id="detailPhoneNumber" pattern="\d{9,10}" required>
                    <label for="detailRole">Role:</label>
                    <select name="user_role" id="detailRole" onchange="updateIdLabel()" required>
                        <option value="student">Student</option>
                        <option value="teacher">Teacher</option>
                        <option value="admin">Admin</option>
                    </select>
                    <button type="submit" name="update_user" class="save-btn">Update User</button>
                    <button type="submit" name="delete_user" class="delete-btn" onclick="return confirm('Are you sure you want to delete this user?');">Delete User</button>
                </form>
            </div>

            <script type='text/javascript'>
                function updateIdLabel() {
                    const role = document.getElementById('detailRole').value;
                    const label = document.getElementById('roleSpecificIdLabel');
                    if (role === 'admin') {
                        label.textContent = 'Admin ID:';
                    } else if (role === 'teacher') {
                        label.textContent = 'Teacher ID:';
                    } else if (role === 'student') {
                        label.textContent = 'Student ID:';
                    }
                }

                function showDetails(id, name, nameEn, email, citizenId, gender, birthDate, phoneNumber, role) {
                    document.getElementById('detailOldId').value = id; // Store the original role-specific ID
                    document.getElementById('detailId').value = id; // Editable role-specific ID field
                    document.getElementById('detailName').value = name;
                    document.getElementById('detailNameEn').value = nameEn;
                    document.getElementById('detailEmail').value = email;
                    document.getElementById('detailCitizenId').value = citizenId;
                    document.getElementById('detailGender').value = gender;
                    document.getElementById('detailBirthDate').value = birthDate;
                    document.getElementById('detailPhoneNumber').value = phoneNumber;
                    document.getElementById('detailRole').value = role;
                    document.getElementById('detailRoleHidden').value = role;

                    updateIdLabel(); // Update the label based on the ID role

                    const sidebar = document.getElementById('detailsSidebar');
                    sidebar.classList.add('open');
                }

                function closeDetails() {
                    const sidebar = document.getElementById('detailsSidebar');
                    sidebar.classList.remove('open');
                }
            </script>
        </div>
    </div>
</html>