<?php
session_start();
header('Content-Type: application/json');

$response = ['logged_in' => false, 'redirect' => ''];
if (isset($_SESSION['user_id'])) {
    $response['logged_in'] = true;
    if ($_SESSION['user_type'] === 'resident') {
        $response['redirect'] = 'resident/dashboard.php';
    } elseif ($_SESSION['user_type'] === 'admin') {
        $response['redirect'] = 'admin/dashboard.php';
    }
}
echo json_encode($response);
?>