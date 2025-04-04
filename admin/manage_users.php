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
    header("Location: login.php");
    exit();
}


// Handle multiple file uploads and user creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_users'])) {
    $user_role = $_POST['role'];
    $upload_dir = 'uploads/';
    
    // Create uploads directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Process each uploaded file
    foreach ($_FILES['user_images']['tmp_name'] as $key => $tmp_name) {
        $file_name = $_FILES['user_images']['name'][$key];
        $file_error = $_FILES['user_images']['error'][$key];
        
        // Skip files with upload errors
        if ($file_error !== UPLOAD_ERR_OK) {
            echo "Error uploading file $file_name: " . get_upload_error_message($file_error) . "<br>";
            continue;
        }
        
        // Extract name and ID from filename (format: Name_ID.jpg)
        $file_info = pathinfo($file_name);
        $name_id = explode('_', $file_info['filename']);
        
        if (count($name_id) !== 2) {
            echo "Invalid filename format for $file_name. Expected format: Name_ID.jpg<br>";
            continue;
        }
        
        $name = $name_id[0];
        $id = $name_id[1];
        
        // Create unique filename to prevent overwriting
        $unique_filename = uniqid() . '_' . $file_name;
        $image_path = $upload_dir . $unique_filename;
        
        // Move uploaded file
        if (move_uploaded_file($tmp_name, $image_path)) {
            // Insert user and face encoding
            if (insert_face_encoding($conn, $name, $id, $image_path, $user_role)) {
                echo "Successfully added user: $name (ID: $id)<br>";
            }
        } else {
            echo "Failed to move uploaded file $file_name<br>";
        }
    }
}

// Helper function to get upload error messages
function get_upload_error_message($error_code) {
    switch ($error_code) {
        case UPLOAD_ERR_INI_SIZE:
            return "The uploaded file exceeds the upload_max_filesize directive in php.ini";
        case UPLOAD_ERR_FORM_SIZE:
            return "The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form";
        case UPLOAD_ERR_PARTIAL:
            return "The uploaded file was only partially uploaded";
        case UPLOAD_ERR_NO_FILE:
            return "No file was uploaded";
        case UPLOAD_ERR_NO_TMP_DIR:
            return "Missing a temporary folder";
        case UPLOAD_ERR_CANT_WRITE:
            return "Failed to write file to disk";
        case UPLOAD_ERR_EXTENSION:
            return "File upload stopped by extension";
        default:
            return "Unknown upload error";
    }
}

// Modified insert_face_encoding function with compatible transaction handling
function insert_face_encoding($conn, $name, $id, $image_path, $user_role) {
    try {
        // Get the absolute path to the Python script
        $script_dir = __DIR__;  // Current directory of the PHP file
        $python_script = $script_dir . DIRECTORY_SEPARATOR . 'process_image.py';
        
        // Debug: Log paths to check they're correct
        error_log("Script directory: " . $script_dir);
        error_log("Python script path: " . $python_script);
        error_log("Image path: " . $image_path);

        // Check if Python script exists
        if (!file_exists($python_script)) {
            throw new Exception("Python script not found at: " . $python_script);
        }

        // Check if image file exists
        if (!file_exists($image_path)) {
            throw new Exception("Image file not found at: " . $image_path);
        }

        // On Windows, use python command explicitly with proper path escaping
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $command = sprintf(
                'python "%s" "%s"',
                str_replace('\\', '\\\\', $python_script),
                str_replace('\\', '\\\\', $image_path)
            );
        } else {
            // On Unix-like systems
            $command = sprintf(
                'python3 %s %s',
                escapeshellarg($python_script),
                escapeshellarg($image_path)
            );
        }

        // Debug: Log the command being executed
        error_log("Executing command: " . $command);

        // Execute command and capture output
        $output = shell_exec($command . " 2>&1");
        
        if ($output === null) {
            throw new Exception("Failed to execute Python script. Command: " . $command);
        }

        // Debug output
        error_log("Python script output: " . $output);
        
        // Decode JSON response
        $result = json_decode(trim($output), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON output from Python script: " . $output);
        }
        
        if ($result['status'] === 'error') {
            throw new Exception("Python script error: " . $result['message']);
        }
        
        // Decode the base64 encoding back to binary
        $encoding_binary = base64_decode($result['encoding']);
        
        // Start transaction
        $conn->autocommit(FALSE);
        
        // Generate email if not provided
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        
        // Insert into users table with binary encoding
        $stmt = $conn->prepare("INSERT INTO users (name, id, face_encoding, user_role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $name, $id, $encoding_binary, $user_role);
        
        if (!$stmt->execute()) {
            throw new Exception("Error inserting into users table: " . $stmt->error);
        }
        
        // Insert into role-specific table
        switch ($user_role) {
            case 'admin':
                $role_stmt = $conn->prepare("INSERT INTO admins (id, admin_name, email, hashed_password, password_changed) VALUES (?, ?, ?, NULL, 0)");
                $role_stmt->bind_param("sss", $id, $name, $email);
                break;
                
            case 'student':
                $role_stmt = $conn->prepare("INSERT INTO students (user_id, name, email, hashed_password, password_changed) VALUES (?, ?, ?, NULL, 0)");
                $role_stmt->bind_param("sss", $id, $name, $email);
                break;
                
            case 'teacher':
                $role_stmt = $conn->prepare("INSERT INTO teachers (user_id, name, email, hashed_password, password_changed) VALUES (?, ?, ?, NULL, 0)");
                $role_stmt->bind_param("sss", $id, $name, $email);
                break;
                
            default:
                throw new Exception("Invalid user role: " . $user_role);
        }
        
        if (!$role_stmt->execute()) {
            throw new Exception("Error inserting into role table: " . $role_stmt->error);
        }
        
        // Commit transaction
        if (!$conn->commit()) {
            throw new Exception("Failed to commit transaction");
        }
        
        $conn->autocommit(TRUE);
        $stmt->close();
        $role_stmt->close();
        
        return true;
        
    } catch (Exception $e) {
        if ($conn) {
            $conn->rollback();
            $conn->autocommit(TRUE);
        }
        error_log("Error in insert_face_encoding: " . $e->getMessage());
        echo "Error adding user $name: " . $e->getMessage() . "<br>";
        return false;
    }
}

// Add this function to verify the setup
function verify_setup() {
    try {
        $script_dir = __DIR__;
        $python_script = $script_dir . DIRECTORY_SEPARATOR . 'process_image.py';
        
        // Check Python script existence
        if (!file_exists($python_script)) {
            throw new Exception("Python script not found at: " . $python_script);
        }
        
        // Check Python installation
        $python_version = shell_exec('python --version 2>&1');
        if (!$python_version) {
            throw new Exception("Python is not installed or not in PATH");
        }
        
        // Check uploads directory
        $upload_dir = $script_dir . DIRECTORY_SEPARATOR . 'uploads';
        if (!file_exists($upload_dir)) {
            if (!mkdir($upload_dir, 0777, true)) {
                throw new Exception("Failed to create uploads directory");
            }
        }
        
        if (!is_writable($upload_dir)) {
            throw new Exception("Uploads directory is not writable");
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Setup verification failed: " . $e->getMessage());
        echo "Setup Error: " . $e->getMessage() . "<br>";
        return false;
    }
}

// Function to test the Python script directly
function test_python_script() {
    $test_image = "path/to/test/image.jpg";  // Replace with a real test image path
    $command = escapeshellcmd("python3 process_image.py " . escapeshellarg($test_image));
    $output = shell_exec($command . " 2>&1");
    
    echo "Python script test output:<br>";
    echo htmlspecialchars($output) . "<br>";
    
    return $output !== null;
}


// Handle user deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $user_id = $_POST['user_id'];
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);

    if ($stmt->execute()) {
        echo "User with ID $user_id deleted successfully.<br>";
    } else {
        echo "Error deleting user: " . $stmt->error . "<br>";
    }
    $stmt->close();
}

// Fetch and display users
function getUsers($conn) {
    $query = "SELECT * FROM users ORDER BY name ASC";
    $result = $conn->query($query);
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

    $stmt = $conn->prepare("UPDATE users SET name = ?, user_role = ? WHERE id = ?");
    $stmt->bind_param("ssi", $name, $user_role, $user_id);

    if ($stmt->execute()) {
        echo "User updated successfully.<br>";

        // Fetch previous role
        $old_role_stmt = $conn->prepare("SELECT user_role FROM users WHERE id = ?");
        $old_role_stmt->bind_param("i", $user_id);
        $old_role_stmt->execute();
        $old_role_stmt->bind_result($old_role);
        $old_role_stmt->fetch();
        $old_role_stmt->close();

        // Remove from the previous role's table
        if ($old_role === 'student') {
            $delete_enrollments = $conn->prepare("DELETE FROM enrollments WHERE student_id = ?");
            $delete_enrollments->bind_param("i", $user_id);
            $delete_enrollments->execute();
            $delete_enrollments->close();

            $remove_stmt = $conn->prepare("DELETE FROM students WHERE user_id = ?");
        } elseif ($old_role === 'teacher') {
            $remove_stmt = $conn->prepare("DELETE FROM teachers WHERE user_id = ?");
        } elseif ($old_role === 'admin') {
            $remove_stmt = $conn->prepare("DELETE FROM admins WHERE id = ?");
        }

        if (isset($remove_stmt)) {
            $remove_stmt->bind_param("i", $user_id);
            $remove_stmt->execute();
            $remove_stmt->close();
        }

        // Insert into new role's table
        if ($user_role === 'student') {
            $email = $name . '@example.com';
            $stmt_student = $conn->prepare("INSERT INTO students (user_id, name, email) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)");
            $stmt_student->bind_param("iss", $user_id, $name, $email);
            $stmt_student->execute();
            $stmt_student->close();
        } elseif ($user_role === 'teacher') {
            $email = $name . '@example.com';
            $stmt_teacher = $conn->prepare("INSERT INTO teachers (user_id, name, email) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)");
            $stmt_teacher->bind_param("iss", $user_id, $name, $email);
            $stmt_teacher->execute();
            $stmt_teacher->close();
        } elseif ($user_role === 'admin') {
            $email = $name . '@example.com';
            $stmt_admin = $conn->prepare("INSERT INTO admins (id, admin_name, email) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE admin_name = VALUES(admin_name)");
            $stmt_admin->bind_param("iss", $user_id, $name, $email);
            $stmt_admin->execute();
            $stmt_admin->close();
        }

    } else {
        echo "Error updating user: " . $stmt->error . "<br>";
    }
    $stmt->close();
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
        /* Reset CSS */
        body, html {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        /* Top Bar */
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

        /* Admin Page Layout */
        .admin-container {
            display: flex;
            flex: 1;
            width: 100%;
            height: 100%;
            background: white;
        }

        /* Sidebar Styling */
        .sidebar {
            width: 250px;
            background-color: #2c3e50;
            color: white;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            height: 100vh; 
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

       /* Main Content Area */
       .main-content { 
           flex: 1; 
           padding: 20px; 
           display: flex; 
           flex-direction: column; 
           align-items: center; 
           background-color: #ecf0f1; 
           height: 100vh; 
           overflow-y: auto; 
       }

       /* User List Styles */
       .add-user-form,
       .user-list { 
           background-color: white; 
           padding: 20px; 
           border-radius: 8px; 
           box-shadow: 0 0 10px rgba(0, 0, 0, 0.1); 
           margin-bottom: 20px; 
       }

       .add-user-form input,
       .add-user-form select { 
           margin-bottom: 10px; 
           padding: 8px; 
           width: calc(100% - 16px); /* Adjust for padding */
       }

       .add-user-form button { 
           padding: 10px 15px; 
           background-color: #2980b9; 
           color: white; 
           border:none; 
           border-radius :5px ;  
           cursor:pointer ;  
       }

       footer {  
          text-align:center ;  
          padding :10 px ;  
          background-color :#34495e ;  
          color:white ;  
          width :100% ;  
      }
   </style>
</head>
<body>
   <div class="top-bar">
      <h1>Manage Users</h1>
   </div>

   <div class="admin-container">
      <aside class="sidebar">
         <ul>
             <li><a href="admin_dashboard.php">Dashboard</a></li>
             <li><a href="manage_users.php">Manage Users</a></li>
             <li><a href="add_course.php">Add Course</a></li>
             <li><a href="enroll_student.php">Enroll Student</a></li>
             <li><a href="logout.php">Logout</a></li>
         </ul>
      </aside>

      <div class="main-content">
         <section class="dashboard-section" id="manage-users">
             <h2>Manage Users</h2>

             <!-- Add User Form -->
             <div class="add-user-form">
                 <h3>Add New Users with Face Recognition</h3>
                 <form method="POST" enctype="multipart/form-data">
                     <label for="role">Role:</label>
                     <select name="role" required>
                         <option value="student">Student</option>
                         <option value="teacher">Teacher</option>
                         <option value="admin">Admin</option>
                     </select>

                     <label for="user_images">Upload User Images (e.g., John_1234.jpg):</label>
                     <input type="file" name="user_images[]" multiple required>

                     <button type="submit" name="add_users">Add Users</button>
                 </form>
             </div>

             <!-- Display Current Users -->
             <div class="user-list">
                <h3>Current Users</h3>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th style="border: 1px solid #ddd; padding: 8px;">Name</th>
                            <th style="border: 1px solid #ddd; padding: 8px;">ID</th>
                            <th style="border: 1px solid #ddd; padding: 8px;">Role</th>
                            <th style="border: 1px solid #ddd; padding: 8px;">Actions</th> <!-- New column for actions -->
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
                                <!-- Edit and Delete buttons -->
                                <button type="button" onclick="openModal('<?php echo htmlspecialchars($user['id']); ?>', '<?php echo htmlspecialchars($user['name']); ?>', '<?php echo htmlspecialchars($user['user_role']); ?>')" style="background-color: #f39c12; color: white; border: none; padding: 5px 10px; cursor: pointer;">Edit</button>
                                <form action="manage_users.php" method="POST" style="display: inline;">
                                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['id']); ?>">
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
<!-- Edit User Modal -->
<div id="editUserModal" class="modal" style="display:none;">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <h2>Edit User</h2>
        <form id="editUserForm" method="POST" action="">
            <input type="hidden" name="user_id" id="editUserId">
            
            <!-- Add Edit ID Field -->
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
    document.getElementById('editUserId').value = userId; // Hidden input for user ID
    document.getElementById('editUserIdDisplay').value = userId; // Displayed user ID
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

<style>
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
      </div>
   </div>

   <footer>
      <p>&copy; <?php echo date("Y"); ?> University Admin Dashboard. All rights reserved.</p>
   </footer>
</body>
</html>
