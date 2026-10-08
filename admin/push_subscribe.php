<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$admin_id = (int)$_SESSION['user_id'];
$in = json_decode(file_get_contents('php://input'), true);

if (!$in || empty($in['endpoint']) || empty($in['keys']['p256dh']) || empty($in['keys']['auth'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid subscription']); exit;
}

$sub = json_encode([
    'endpoint' => $in['endpoint'],
    'p256dh'   => $in['keys']['p256dh'],
    'auth'     => $in['keys']['auth']
]);

$s = mysqli_prepare($conn, "UPDATE admin SET push_subscription = ? WHERE id = ?");
mysqli_stmt_bind_param($s, "si", $sub, $admin_id);
$ok = mysqli_stmt_execute($s);

echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Subscribed' : 'DB error']);