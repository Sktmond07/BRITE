<?php
// send_push.php - Send push notification via service worker
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // For now, just log
    error_log('Push notification requested: ' . json_encode($data));
    
    // Return success
    echo json_encode(['success' => true, 'message' => 'Push requested']);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
}
?>