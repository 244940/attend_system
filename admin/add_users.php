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

// Handle single user addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $name = $_POST['name'];
    $id = $_POST['id'];
    $user_role = $_POST['role'];
    $upload_dir = 'uploads/';
    
    // Create uploads directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $file_name = $_FILES['user_image']['name'];
    $file_error = $_FILES['user_image']['error'];
    $tmp_name = $_FILES['user_image']['tmp_name'];
    
    if ($file_error === UPLOAD_ERR_OK) {
        $unique_filename = uniqid() . '_' . $file_name;
        $image_path = $upload_dir . $unique_filename;
        
        if (move_uploaded_file($tmp_name, $image_path)) {
            if (insert_face_encoding($conn, $name, $id, $image_path, $user_role)) {
                echo "Successfully added user: $name (ID: $id)<br>";
            }
        } else {
            echo "Failed to move uploaded file $file_name<br>";
        }
    } else {
        echo "Error uploading file $file_name: " . get_upload_error_message($file_error) . "<br>";
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

// Function to insert face encoding
function insert_face_encoding($conn, $name, $id, $image_path, $user_role) {
    try {
        // Get the absolute path to the Python script
        $script_dir = __DIR__;
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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add User</title>
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

        /* Form Container */
        .form-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 50%;
            margin: 20px auto;
        }

        .form-container input, 
        .form-container select {
            margin-bottom: 10px;
            padding: 8px;
            width: calc(100% - 16px);
        }

        .form-container button {
            padding: 10px 15px;
            background-color: #2980b9;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        .form-container a {
            display: block;
            margin-top: 10px;
            text-align: center;
            color: #2980b9;
            text-decoration: none;
        }

        .form-container a:hover {
            text-decoration: underline;
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
        <h1>Add New User</h1>
    </div>
    
    <div class="form-container">
        <h2>Add User</h2>
        <form method="POST" enctype="multipart/form-data">
            <label for="name">Name:</label>
            <input type="text" name="name" id="name" required>
            
            <label for="id">ID:</label>
            <input type="text" name="id" id="id" required>
            
            <label for="role">Role:</label>
            <select name="role" id="role" required>
                <option value="student">Student</option>
                <option value="teacher">Teacher</option>
                <option value="admin">Admin</option>
            </select>
            
            <label for="user_image">User Image:</label>
            <input type="file" name="user_image" id="user_image" accept="image/*" required>
            
            <button type="submit" name="add_user">Add User</button>
        </form>
        <a href="manage_users.php">Back to Manage Users</a>
    </div>

    <!--
    <footer>
        <p>© <?php echo date("Y"); ?> University Admin Dashboard. All rights reserved.</p>
    </footer>
    -->
</body>
</html>