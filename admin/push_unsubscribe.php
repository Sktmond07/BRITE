<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$admin_id = (int)$_SESSION['user_id'];
$s = mysqli_prepare($conn, "UPDATE admin SET push_subscription = NULL WHERE id = ?");
mysqli_stmt_bind_param($s, "i", $admin_id);
$ok = mysqli_stmt_execute($s);

echo json_encode(['success' => (bool)$ok]);