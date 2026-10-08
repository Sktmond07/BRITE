<?php
session_start();
header('Content-Type: application/json');

if (isset($_SESSION['user_id'])) {
    // Refresh session timestamp
    $_SESSION['last_activity'] = time();
    echo json_encode(['success' => true, 'message' => 'Session refreshed']);
} else {
    echo json_encode(['success' => false, 'message' => 'No active session']);
}
?>