<?php
// Database connection details
$host = "localhost";    // Host (or 127.0.0.1)
$port = "3308";         // Optional: Port number if you're using a non-standard port
$username = "root";     // Database username
$password = "paganini019";   // Database password
$dbname = "attend_data";  // Database name-face_recognition_db

// Create a new MySQLi connection object
$conn = new mysqli($host . ":" . $port, $username, $password, $dbname);

// Check if connection is successful
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Optionally, set the character set to UTF-8 (if your database uses different encoding)
// $conn->set_charset("utf8");
?>
