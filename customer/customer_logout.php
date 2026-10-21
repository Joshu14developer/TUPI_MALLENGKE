<?php
session_start();

if (isset($_SESSION['customer_id'])) {
    $conn = require __DIR__ . '/../config/db.php';
    $stmt = $conn->prepare("UPDATE customers SET remember_token = NULL WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['customer_id']);
    $stmt->execute();
    $stmt->close();
}

/* Clear only the customer session keys */
unset($_SESSION['customer_id']);
unset($_SESSION['customer_name']);
unset($_SESSION['customer_email']);
unset($_SESSION['customer_user']);

if (isset($_COOKIE['customer_remember'])) {
    setcookie('customer_remember', '', time() - 3600, '/');
}

header('Location: customer_login.php');
exit;