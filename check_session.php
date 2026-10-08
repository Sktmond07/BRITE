<?php
session_start();
header('Content-Type: application/json');

$response = ['logged_in' => false, 'redirect' => '', 'timeout' => false];

// Session timeout in seconds (10 minutes = 600 seconds)
$timeout = 600;

if (isset($_SESSION['user_id'])) {
    // Check if session has timed out
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        // Session expired
        $response['timeout'] = true;
        $response['message'] = 'Your session has expired due to inactivity.';
        
        // Clear session
        session_unset();
        session_destroy();
    } else {
        // Update last activity time
        $_SESSION['last_activity'] = time();
        $response['logged_in'] = true;
        
        if ($_SESSION['user_type'] === 'resident') {
            $response['redirect'] = 'resident/dashboard.php';
        } elseif ($_SESSION['user_type'] === 'admin') {
            // Check admin role for proper redirect
            require_once __DIR__ . '/config/database.php';
            $user_id = $_SESSION['user_id'];
            $roleSql = "SELECT admin_role FROM admin WHERE id = ?";
            $roleStmt = mysqli_prepare($conn, $roleSql);
            mysqli_stmt_bind_param($roleStmt, "i", $user_id);
            mysqli_stmt_execute($roleStmt);
            $roleResult = mysqli_stmt_get_result($roleStmt);
            if ($roleRow = mysqli_fetch_assoc($roleResult)) {
                $role = $roleRow['admin_role'];
                switch($role) {
                    case 'captain':
                        $response['redirect'] = 'admin/dashboards/captain_dashboard.php';
                        break;
                    case 'secretary':
                        $response['redirect'] = 'admin/dashboards/secretary_dashboard.php';
                        break;
                    case 'kagawad':
                        $response['redirect'] = 'admin/dashboards/kagawad_dashboard.php';
                        break;
                    case 'lupon':
                        $response['redirect'] = 'admin/dashboards/lupon_dashboard.php';
                        break;
                    default:
                        $response['redirect'] = 'admin/dashboard.php';
                }
            } else {
                $response['redirect'] = 'admin/dashboard.php';
            }
            mysqli_stmt_close($roleStmt);
        }
    }
}

echo json_encode($response);
?>