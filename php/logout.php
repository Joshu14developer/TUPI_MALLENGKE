<?php
session_start();

// Clear the remember-me token in the database
if (isset($_SESSION['user_id'])) {
    $conn = require_once __DIR__ . '/../config/db.php';
    $stmt = $conn->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $stmt->close();
}

// Destroy session
$_SESSION = [];
session_destroy();

// Clear the cookie
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
}

header('Location: login.php');
exit;