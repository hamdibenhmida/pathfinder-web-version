<?php
// Database configuration constants
$host = "localhost";          // Database server hostname
$user = "root";               // Database username
$pass = "";                   // Database password (empty for local development)
$dbname = "pathfinder_db";    // Name of the database to connect to

// Establish database connection using MySQLi
$conn = new mysqli($host, $user, $pass, $dbname);

// Check if connection was successful
if ($conn->connect_error) {
    // Terminate script and display error message if connection fails
    die("Connection failed: " . $conn->connect_error);
}

// Set character set to UTF-8 for proper handling of special characters
$conn->set_charset('utf8mb4');
?>

