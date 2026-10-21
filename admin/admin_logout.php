<?php
session_start();

unset(
    $_SESSION['admin_id'],
    $_SESSION['admin_username'],
    $_SESSION['admin_full_name'],
    $_SESSION['admin_role'],
    $_SESSION['admin_color'],
    $_SESSION['admin_email']
);

header('Location: admin_login.php');
exit;
