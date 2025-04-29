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
    $name_en = $_POST['name_en'];
    $id = $_POST['id'];
    $citizen_id = $_POST['citizen_id'];
    $email = $_POST['email'];
    $gender = $_POST['gender'];
    $birth_date = $_POST['birth_date'];
    $phone_number = $_POST['phone_number'];
    $user_role = $_POST['role'];
    $upload_dir = 'Uploads/';
    
    // Input validation
    if (!preg_match('/^\d{13}$/', $citizen_id)) {
        echo "Error: Citizen ID must be 13 digits.<br>";
        exit();
    }
    if (!preg_match('/^\d{9,10}$/', $phone_number)) { // Accept 9 or 10 digits
        echo "Error: Phone number must be 9 or 10 digits.<br>";
        exit();
    }
    // Normalize phone number to 10 digits by adding leading 0 if necessary
    if (strlen($phone_number) == 9) {
        $phone_number = '0' . $phone_number;
        error_log("Normalized phone number for $name: $phone_number");
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "Error: Invalid email format.<br>";
        exit();
    }
    if (!is_numeric($id) || $id <= 0 || strlen($id) > 20) {
        echo "Error: ID must be a positive number and not exceed 20 digits.<br>";
        exit();
    }
    if (!in_array($gender, ['male', 'female', 'other'])) {
        echo "Error: Invalid gender.<br>";
        exit();
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
        echo "Error: Invalid birth date format (use YYYY-MM-DD).<br>";
        exit();
    }
    
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
            if (insert_face_encoding($conn, $name, $name_en, $id, $citizen_id, $email, $gender, $birth_date, $phone_number, $image_path, $user_role)) {
                echo "Successfully added user: $name (ID: $id)<br>";
            }
        } else {
            echo "Failed to move uploaded file $file_name<br>";
        }
    } else {
        echo "Error uploading file $file_name: " . get_upload_error_message($file_error) . "<br>";
    }
}

// Handle CSV file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_csv'])) {
    $upload_dir = 'Uploads/';
    $file_name = $_FILES['csv_file']['name'];
    $file_error = $_FILES['csv_file']['error'];
    $tmp_name = $_FILES['csv_file']['tmp_name'];
    
    if ($file_error === UPLOAD_ERR_OK) {
        $allowed_extensions = ['csv'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        if (!in_array($file_ext, $allowed_extensions)) {
            echo "Error: Only CSV files are allowed.<br>";
            exit();
        }
        
        // Move uploaded CSV to a temporary location
        $csv_path = $upload_dir . uniqid() . '_' . $file_name;
        if (!move_uploaded_file($tmp_name, $csv_path)) {
            echo "Error: Failed to move uploaded CSV file.<br>";
            exit();
        }
        
        // Process CSV file
        if (($handle = fopen($csv_path, 'r')) !== FALSE) {
            fgetcsv($handle); // Skip header row
            while (($row = fgetcsv($handle, 1000, ',')) !== FALSE) {
                // Map CSV columns to variables
                $id = $row[0];
                $name = $row[1];
                $email = $row[2];
                // $row[3] is face_encoding (ignored)
                // $row[4] is created_at (ignored)
                // $row[5] is hashed_password (ignored)
                $password_changed = $row[6] === 'TRUE' ? 1 : 0;
                $citizen_id = $row[7];
                $gender = $row[8];
                $birth_date = $row[9];
                $phone_number = $row[10];
                $name_en = $row[11];
                $image_path = $row[12]; // Image path from CSV
                $user_role = $_POST['csv_role']; // Role from form
                
                // Debug: Log CSV row data
                error_log("Processing CSV row: ID=$id, Name=$name, Email=$email, Citizen ID=$citizen_id, Phone=$phone_number, Role=$user_role");

                // Validate input
                if (!preg_match('/^\d{13}$/', $citizen_id)) {
                    echo "Error: Invalid citizen ID for $name ($citizen_id).<br>";
                    continue;
                }
                if (!preg_match('/^\d{9,10}$/', $phone_number)) { // Accept 9 or 10 digits
                    echo "Error: Invalid phone number for $name ($phone_number).<br>";
                    continue;
                }
                // Normalize phone number to 10 digits by adding leading 0 if necessary
                if (strlen($phone_number) == 9) {
                    $phone_number = '0' . $phone_number;
                    error_log("Normalized phone number for $name: $phone_number");
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo "Error: Invalid email for $name ($email).<br>";
                    continue;
                }
                if (!is_numeric($id) || $id <= 0 || strlen($id) > 20) {
                    echo "Error: Invalid ID for $name ($id).<br>";
                    continue;
                }
                if (!in_array($gender, ['male', 'female', 'other'])) {
                    echo "Error: Invalid gender for $name ($gender).<br>";
                    continue;
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
                    echo "Error: Invalid birth date for $name ($birth_date).<br>";
                    continue;
                }
                if (!file_exists($image_path)) {
                    echo "Error: Image file not found for $name ($image_path).<br>";
                    continue;
                }
                
                // Insert user with face encoding
                if (insert_face_encoding($conn, $name, $name_en, $id, $citizen_id, $email, $gender, $birth_date, $phone_number, $image_path, $user_role)) {
                    echo "Successfully added user: $name (ID: $id)<br>";
                } else {
                    echo "Failed to add user: $name (ID: $id)<br>";
                }
            }
            fclose($handle);
            unlink($csv_path); // Delete temporary CSV file
        } else {
            echo "Error: Failed to read CSV file.<br>";
        }
    } else {
        echo "Error uploading CSV file: " . get_upload_error_message($file_error) . "<br>";
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
function insert_face_encoding($conn, $name, $name_en, $id, $citizen_id, $email, $gender, $birth_date, $phone_number, $image_path, $user_role) {
    try {
        // Get the absolute path to the Python script
        $script_dir = __DIR__;
        $python_script = $script_dir . DIRECTORY_SEPARATOR . 'process_image.py';
        
        // Debug: Log paths
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

        // On Windows, use python command with proper path escaping
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

        // Debug: Log command
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
        
        // Insert into role-specific table
        switch ($user_role) {
            case 'admin':
                $stmt = $conn->prepare("INSERT INTO admins (admin_id, admin_name, name_en, email, citizen_id, gender, birth_date, phone_number, face_encoding, hashed_password, password_changed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 0)");
                $stmt->bind_param("sssssssss", $id, $name, $name_en, $email, $citizen_id, $gender, $birth_date, $phone_number, $encoding_binary);
                break;
                
            case 'student':
                $stmt = $conn->prepare("INSERT INTO students (student_id, name, name_en, email, citizen_id, gender, birth_date, phone_number, face_encoding, hashed_password, password_changed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 0)");
                $stmt->bind_param("sssssssss", $id, $name, $name_en, $email, $citizen_id, $gender, $birth_date, $phone_number, $encoding_binary);
                break;
                
            case 'teacher':
                $stmt = $conn->prepare("INSERT INTO teachers (teacher_id, name, name_en, email, citizen_id, gender, birth_date, phone_number, face_encoding, hashed_password, password_changed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 0)");
                $stmt->bind_param("sssssssss", $id, $name, $name_en, $email, $citizen_id, $gender, $birth_date, $phone_number, $encoding_binary);
                break;
                
            default:
                throw new Exception("Invalid user role: " . $user_role);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Error inserting into role table: " . $stmt->error);
        }
        
        // Commit transaction
        if (!$conn->commit()) {
            throw new Exception("Failed to commit transaction");
        }
        
        $conn->autocommit(TRUE);
        $stmt->close();
        
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
        .form-container {
            background-color: rgba(255, 255, 255, 0.9);
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
        .csv-upload {
            margin-top: 20px;
            border-top: 1px solid #ccc;
            padding-top: 20px;
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
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?> (<?php echo htmlspecialchars($_SESSION['user_email']); ?>)</span>
        </div>
    </div>
    
    <div class="form-container">
        <h2>Add Single User</h2>
        <form method="POST" enctype="multipart/form-data">
            <label for="name">Name (TH):</label>
            <input type="text" name="name" id="name" required>
            
            <label for="name_en">Name (EN):</label>
            <input type="text" name="name_en" id="name_en" required>
            
            <label for="id">ID:</label>
            <input type="text" name="id" id="id" pattern="\d{1,20}" required>
            
            <label for="citizen_id">Citizen ID:</label>
            <input type="text" name="citizen_id" id="citizen_id" pattern="\d{13}" required>
            
            <label for="email">Email:</label>
            <input type="email" name="email" id="email" required>
            
            <label for="gender">Gender:</label>
            <select name="gender" id="gender" required>
                <option value="male">Male</option>
                <option value="female">Female</option>
                <option value="other">Other</option>
            </select>
            
            <label for="birth_date">Birth Date:</label>
            <input type="date" name="birth_date" id="birth_date" required>
            
            <label for="phone_number">Phone Number:</label>
            <input type="text" name="phone_number" id="phone_number" pattern="\d{9,10}" title="Phone number must be 9 or 10 digits" required>
            
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

        <div class="csv-upload">
            <h2>Upload CSV File</h2>
            <form method="POST" enctype="multipart/form-data">
                <label for="csv_role">User Role for CSV:</label>
                <select name="csv_role" id="csv_role" required>
                    <option value="student">Student</option>
                    <option value="teacher">Teacher</option>
                    <option value="admin">Admin</option>
                </select>
                
                <label for="csv_file">CSV File:</label>
                <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                
                <button type="submit" name="upload_csv">Upload CSV</button>
            </form>
        </div>

        <a href="manage_users.php">Back to Manage Users</a>
    </div>

    <footer>
        <p>© <?php echo date("Y"); ?> University Admin Dashboard. All rights reserved.</p>
    </footer>
</body>
</html>