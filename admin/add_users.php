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
        $_SESSION['error_message'] = "Citizen ID must be 13 digits.";
        header("Location: add_users.php");
        exit();
    }
    if (!preg_match('/^\d{9,10}$/', $phone_number)) {
        $_SESSION['error_message'] = "Phone number must be 9 or 10 digits.";
        header("Location: add_users.php");
        exit();
    }
    // Normalize phone number to 10 digits by adding leading 0 if necessary
    if (strlen($phone_number) == 9) {
        $phone_number = '0' . $phone_number;
        error_log("Normalized phone number for $name: $phone_number");
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error_message'] = "Invalid email format.";
        header("Location: add_users.php");
        exit();
    }
    if (!is_numeric($id) || $id <= 0 || strlen($id) > 20) {
        $_SESSION['error_message'] = "ID must be a positive number and not exceed 20 digits.";
        header("Location: add_users.php");
        exit();
    }
    if (!in_array($gender, ['male', 'female', 'other'])) {
        $_SESSION['error_message'] = "Invalid gender.";
        header("Location: add_users.php");
        exit();
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
        $_SESSION['error_message'] = "Invalid birth date format (use YYYY-MM-DD).";
        header("Location: add_users.php");
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
                $_SESSION['success_message'] = "Successfully added user: $name (ID: $id)";
                header("Location: add_users.php");
                exit();
            } else {
                $_SESSION['error_message'] = "Failed to add user: $name (ID: $id)";
                header("Location: add_users.php");
                exit();
            }
        } else {
            $_SESSION['error_message'] = "Failed to move uploaded file $file_name";
            header("Location: add_users.php");
            exit();
        }
    } else {
        $_SESSION['error_message'] = "Error uploading file $file_name: " . get_upload_error_message($file_error);
        header("Location: add_users.php");
        exit();
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
            $_SESSION['error_message'] = "Only CSV files are allowed.";
            header("Location: add_users.php");
            exit();
        }
        
        // Move uploaded CSV to a temporary location
        $csv_path = $upload_dir . uniqid() . '_' . $file_name;
        if (!move_uploaded_file($tmp_name, $csv_path)) {
            $_SESSION['error_message'] = "Failed to move uploaded CSV file.";
            header("Location: add_users.php");
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
                $password_changed = $row[6] === 'TRUE' ? 1 : 0;
                $citizen_id = $row[7];
                $gender = $row[8];
                $birth_date = $row[9];
                $phone_number = $row[10];
                $name_en = $row[11];
                $image_path = $row[12];
                $user_role = $_POST['csv_role'];
                
                // Debug: Log CSV row data
                error_log("Processing CSV row: ID=$id, Name=$name, Email=$email, Citizen ID=$citizen_id, Phone=$phone_number, Role=$user_role");

                // Validate input
                if (!preg_match('/^\d{13}$/', $citizen_id)) {
                    $_SESSION['error_message'] = "Invalid citizen ID for $name ($citizen_id).";
                    continue;
                }
                if (!preg_match('/^\d{9,10}$/', $phone_number)) {
                    $_SESSION['error_message'] = "Invalid phone number for $name ($phone_number).";
                    continue;
                }
                if (strlen($phone_number) == 9) {
                    $phone_number = '0' . $phone_number;
                    error_log("Normalized phone number for $name: $phone_number");
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $_SESSION['error_message'] = "Invalid email for $name ($email).";
                    continue;
                }
                if (!is_numeric($id) || $id <= 0 || strlen($id) > 20) {
                    $_SESSION['error_message'] = "Invalid ID for $name ($id).";
                    continue;
                }
                if (!in_array($gender, ['male', 'female', 'other'])) {
                    $_SESSION['error_message'] = "Invalid gender for $name ($gender).";
                    continue;
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
                    $_SESSION['error_message'] = "Invalid birth date for $name ($birth_date).";
                    continue;
                }
                if (!file_exists($image_path)) {
                    $_SESSION['error_message'] = "Image file not found for $name ($image_path).";
                    continue;
                }
                
                if (insert_face_encoding($conn, $name, $name_en, $id, $citizen_id, $email, $gender, $birth_date, $phone_number, $image_path, $user_role)) {
                    $_SESSION['success_message'] = "Successfully added user: $name (ID: $id)";
                } else {
                    $_SESSION['error_message'] = "Failed to add user: $name (ID: $id)";
                }
            }
            fclose($handle);
            unlink($csv_path);
            header("Location: add_users.php");
            exit();
        } else {
            $_SESSION['error_message'] = "Failed to read CSV file.";
            header("Location: add_users.php");
            exit();
        }
    } else {
        $_SESSION['error_message'] = "Error uploading CSV file: " . get_upload_error_message($file_error);
        header("Location: add_users.php");
        exit();
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
        $script_dir = __DIR__;
        $python_script = $script_dir . DIRECTORY_SEPARATOR . 'process_image.py';
        
        error_log("Script directory: " . $script_dir);
        error_log("Python script path: " . $python_script);
        error_log("Image path: " . $image_path);

        if (!file_exists($python_script)) {
            throw new Exception("Python script not found at: " . $python_script);
        }

        if (!file_exists($image_path)) {
            throw new Exception("Image file not found at: " . $image_path);
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $command = sprintf(
                'python "%s" "%s"',
                str_replace('\\', '\\\\', $python_script),
                str_replace('\\', '\\\\', $image_path)
            );
        } else {
            $command = sprintf(
                'python3 %s %s',
                escapeshellarg($python_script),
                escapeshellarg($image_path)
            );
        }

        error_log("Executing command: " . $command);

        $output = shell_exec($command . " 2>&1");
        
        if ($output === null) {
            throw new Exception("Failed to execute Python script. Command: " . $command);
        }

        error_log("Python script output: " . $output);
        
        $result = json_decode(trim($output), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON output from Python script: " . $output);
        }
        
        if ($result['status'] === 'error') {
            throw new Exception("Python script error: " . $result['message']);
        }
        
        $encoding_binary = base64_decode($result['encoding']);
        
        $conn->autocommit(FALSE);
        
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
        $_SESSION['error_message'] = "Error adding user $name: " . $e->getMessage();
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
            width: 50%;
            margin: 20px auto;
        }

        .form-container h2 {
            margin-top: 0;
            color: #2980b9;
        }

        .form-container input, 
        .form-container select {
            margin-bottom: 10px;
            padding: 8px;
            width: calc(100% - 16px);
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }

        .form-container button {
            padding: 10px 15px;
            background-color: #2980b9;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        .form-container button:hover {
            background-color: #3498db;
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
        <h1>Add New User</h1>
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
            <section class="dashboard-section" id="add-users">
                <div class="form-container">
                    <h2>Add Single User</h2>

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
            </section>
        </div>
    </div>

    <footer>
        <p>© <?php echo date("Y"); ?> University Admin Dashboard. All rights reserved.</p>
    </footer>
</body>
</html>