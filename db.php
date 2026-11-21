<?php
// YHAN & KIEL Fresh Farm Egg - MySQL connection
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host = 'localhost';
$username = 'root';
$password = ''; // Default XAMPP MySQL password is blank
$database = 'yhan_kiel_egg_sorting';

$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    http_response_code(500);
    die('Database connection failed: ' . htmlspecialchars($conn->connect_error));
}
$conn->set_charset('utf8mb4');
?>
