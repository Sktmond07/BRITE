<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/push_send.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    die('Log in as admin first');
}

$uid = (int)$_SESSION['user_id'];

// Check subscription exists
$s = mysqli_prepare($conn, "SELECT push_subscription FROM admin WHERE id = ?");
mysqli_stmt_bind_param($s, "i", $uid);
mysqli_stmt_execute($s);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));

header('Content-Type: text/plain');
if (!$row || empty($row['push_subscription'])) {
    echo "❌ No push_subscription saved for user $uid.\n";
    echo "   → The 'Enable desktop push notifications' toggle was never saved.\n";
    echo "   → Open the dashboard, click bell → toggle it on → Allow permission.\n";
    exit;
}

echo "✓ Subscription found for user $uid.\n";
echo "  Preview: " . substr($row['push_subscription'], 0, 80) . "...\n\n";

echo "Sending test push...\n";
$sent = pushToUser($conn, $uid,
    'BRITE Test Push',
    'If you see this, Web Push works! Sent at ' . date('H:i:s'),
    '/BRITE/admin/dashboards/secretary_dashboard.php');

echo "pushToUser returned: $sent\n";
if ($sent > 0) {
    echo "✓ Queued. Watch for a desktop notification.\n";
    echo "  (Check Chrome DevTools → Application → Service Workers for delivery status)\n";
} else {
    echo "❌ pushToUser returned 0 — the flush likely failed.\n";
    echo "  Check C:\\xampp\\apache\\logs\\error.log for 'Push fail:' lines.\n";
}