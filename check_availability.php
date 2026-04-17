<?php
session_start();
require 'config/database.php';

// Check email availability
if (isset($_GET['email'])) {
    $email = trim($_GET['email']);
    
    $query = "SELECT id FROM resident WHERE email = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    
    $exists = mysqli_stmt_num_rows($stmt) > 0;
    
    echo json_encode(['exists' => $exists]);
    exit;
}

// Check username availability
if (isset($_GET['username'])) {
    $username = trim($_GET['username']);
    
    $query = "SELECT id FROM resident WHERE username = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    
    $exists = mysqli_stmt_num_rows($stmt) > 0;
    
    echo json_encode(['exists' => $exists]);
    exit;
}
?>