<?php
session_start();
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';
require 'database_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Function to get a valid "From" email address
function getValidFromAddress() {
    $domain = $_SERVER['HTTP_HOST'];
    if ($domain === 'localhost' || filter_var($domain, FILTER_VALIDATE_IP)) {
        return 'noreply@example.com';
    }
    return 'noreply@' . $domain;
}

// Function to get localhost domain for the confirmation link
function getLocalhostDomain() {
    return "http://localhost";
}

$message = '';

// If user_email is not in session, fetch it from the database
if (!isset($_SESSION['user_email']) || empty($_SESSION['user_email'])) {
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['user_role'] ?? 'student';
    //$table = ($user_role == 'teacher') ? 'teachers' : 'students';
    //$table = ($user_role == 'teacher') ? 'teachers' : (($user_role == 'admin') ? 'admins' : 'students'); // Handle admin

     // Determine the correct table based on user role
     $table = '';
     $id_column = 'user_id';
     
     switch($user_role) {
         case 'teacher':
             $table = 'teachers';
             break;
         case 'admin':
             $table = 'admins';
             $id_column = 'id'; // Assuming admin table uses 'id' instead of 'user_id'
             break;
         default:
             $table = 'students';
     }
 
     $stmt = $conn->prepare("SELECT email, password_changed FROM $table WHERE $id_column = ?");
     $stmt->bind_param("i", $user_id);
     $stmt->execute();
     $result = $stmt->get_result();
 
     if ($result->num_rows == 1) {
         $user = $result->fetch_assoc();
         $_SESSION['user_email'] = $user['email'];
         $_SESSION['password_changed'] = $user['password_changed'];
     } else {
         $message = "Error: Unable to retrieve user email. Please log out and log in again.";
         $messageClass = 'error';
     }
 
     $stmt->close();
 }

// Handle form submission

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Validate password strength
    if (strlen($new_password) < 8) {
        $message = "Password must be at least 8 characters long.";
        $messageClass = 'error';
    } elseif (!preg_match("/[A-Z]/", $new_password)) {
        $message = "Password must contain at least one uppercase letter.";
        $messageClass = 'error';
    } elseif (!preg_match("/[a-z]/", $new_password)) {
        $message = "Password must contain at least one lowercase letter.";
        $messageClass = 'error';
    } elseif (!preg_match("/[0-9]/", $new_password)) {
        $message = "Password must contain at least one number.";
        $messageClass = 'error';
    } elseif ($new_password !== $confirm_password) {
        $message = "Passwords do not match.";
        $messageClass = 'error';
    } else {
        $email = $_SESSION['user_email'];
        $user_id = $_SESSION['user_id'];
        $user_role = $_SESSION['user_role'] ?? 'student';

        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
        $_SESSION['new_hashed_password'] = $hashed_password;

        $token = bin2hex(random_bytes(32));
        $_SESSION['password_reset_token'] = $token;

        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'wonwinpor@gmail.com'; // Replace with your SMTP username
            $mail->Password   = 'dvom wjpg hkkb xjdo'; // Replace with your SMTP password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            
            // Additional Gmail settings
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            // Recipients
            $fromAddress = getValidFromAddress();
            $mail->setFrom($fromAddress, 'Your Website');
            $mail->addAddress($email);

            // Content
            $mail->isHTML(true);
            $mail->Subject = "Confirm Password Change";
            $confirmation_link = getLocalhostDomain() . "/attend_system/confirm_password.php?token=" . $token;
            $mail->Body    = "Please click the following link to confirm your password change: <a href='$confirmation_link'>$confirmation_link</a>";
            $mail->AltBody = "Please click the following link to confirm your password change: $confirmation_link";

            $mail->send();
            $message = "A confirmation email has been sent to your address ($email). Please check your inbox and spam folder.";
        } catch (Exception $e) {
            $message = "Message could not be sent. Please try again later or contact support.";
            error_log("Failed to send email to $email. Error: " . $mail->ErrorInfo);
        }
    } 
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Editing Page</title>
    <link rel="stylesheet" href="styles1.css">
</head>
<body>
    <div class="container">
        <div class="password-box">
            <h2>Edit Password</h2>
            <?php if (!empty($message)): ?>
                <div class="message"><?php echo $message; ?></div>
            <?php endif; ?>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <div class="input-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required>
                </div>
                <div class="input-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                <button type="submit">Submit</button>
                <style>

body, html {
    margin: 0;
    padding: 0;
    font-family: Arial, sans-serif;
    height: 100%;
    display: flex;
    justify-content: center;
    align-items: center;
    background: url('https://example.com/background-image.jpg') no-repeat center center fixed;
    background-size: cover;
}

.container {
    background: rgba(255, 255, 255, 0.8); /* พื้นหลังของกรอบหลัก */
    padding: 40px; /* การเว้นระยะภายในกรอบหลัก */
    border-radius: 12px; /* มุมโค้งมนของกรอบ */
    box-shadow: 0 0 20px rgba(0, 0, 0, 0.3); /* เงาของกรอบ */
    text-align: center; /* จัดตำแหน่งข้อความในกรอบให้อยู่ตรงกลาง */
}

.password-box {
    width: 350px; /* ความกว้างของกรอบ */
    padding: 20px; /* การเว้นระยะภายในกรอบ */
    border: 2px solid #ccc; /* ขอบของกรอบ */
    border-radius: 8px; /* มุมโค้งมนของกรอบ */
    background-color: white; /* สีพื้นหลังของกรอบ */
}

.password-box h2 {
    margin-bottom: 20px; /* ระยะห่างด้านล่างของหัวเรื่อง */
    font-size: 24px; /* ขนาดของหัวเรื่อง */
    color: #333; /* สีของหัวเรื่อง */
    text-align: center; /* จัดตำแหน่งหัวเรื่องให้อยู่ตรงกลาง */
}

.input-group {
    margin-bottom: 20px; /* ระยะห่างระหว่างกลุ่ม input */
    text-align: left; /* จัดข้อความชิดซ้ายสำหรับป้ายกำกับและช่องกรอก */
    padding-left: 10px; /* เพิ่มการเว้นระยะด้านซ้าย */
}

.input-group label {
    display: block;
    font-weight: bold;
    margin-bottom: 8px; /* ระยะห่างระหว่างป้ายกำกับและช่องกรอก */
    text-align: left; /* จัดตำแหน่งป้ายกำกับให้อยู่ด้านซ้าย */
}

.input-group input {
    width: 90%; /* ความกว้างของช่องกรอก */
    padding: 12px; /* การเว้นระยะภายในช่องกรอก */
    border: 1px solid #aaa; /* ขอบของช่องกรอก */
    border-radius: 5px; /* มุมโค้งมนของช่องกรอก */
    font-size: 16px; /* ขนาดของข้อความในช่องกรอก */
    text-align: center; /* จัดข้อความในช่องกรอกให้อยู่ตรงกลาง */
}

button {
    width: 95%; /* ความกว้างของปุ่ม */
    padding: 12px; /* การเว้นระยะภายในปุ่ม */
    background-color: #007bff; /* สีพื้นหลังของปุ่ม */
    color: white; /* สีของข้อความในปุ่ม */
    border: none; /* ไม่มีขอบของปุ่ม */
    border-radius: 5px; /* มุมโค้งมนของปุ่ม */
    font-size: 18px; /* ขนาดของข้อความในปุ่ม */
    cursor: pointer; /* เปลี่ยนตัวชี้เมื่อเอาเมาส์ไปวางบนปุ่ม */
    margin-top: 10px; /* ระยะห่างด้านบนของปุ่ม */
    text-align: center; /* จัดตำแหน่งปุ่มให้อยู่ตรงกลาง */
}

button:hover {
    background-color: #0056b3; /* สีของปุ่มเมื่อเอาเมาส์ไปวาง */
}

.password-box {
    width: 350px; /* ความกว้างของกล่อง */
    padding: 20px; /* การเว้นระยะภายในกล่อง */
    /* ลบขอบและพื้นหลังออก */
    border: none; /* ไม่มีขอบของกล่อง */
    background-color: transparent; /* สีพื้นหลังโปร่งใส */
}

                </style>
                
            </form>
        </div>
    </div>
</body>
</html>