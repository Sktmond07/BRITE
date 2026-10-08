<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require '../config/database.php';

$data = json_decode(file_get_contents('php://input'), true);
$email = $data['email'] ?? '';

if ($email) {
    // Try to find in admin
    $adminQuery = "SELECT id, username as name, email FROM admin WHERE email = ? AND is_active = 1";
    $stmt = mysqli_prepare($conn, $adminQuery);
    $user = null;
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($admin = mysqli_fetch_assoc($result)) {
            $user = [
                'id' => $admin['id'],
                'name' => $admin['name'],
                'email' => $admin['email']
            ];
        }
        mysqli_stmt_close($stmt);
    }
    
    // If not admin, check resident table for full name
    if (!$user) {
        $residentQuery = "SELECT id, email, first_name, last_name, middle_name 
                         FROM resident WHERE email = ? AND is_active = 1 AND is_verified = 1";
        $stmt2 = mysqli_prepare($conn, $residentQuery);
        if ($stmt2) {
            mysqli_stmt_bind_param($stmt2, "s", $email);
            mysqli_stmt_execute($stmt2);
            $result = mysqli_stmt_get_result($stmt2);
            
            if ($resident = mysqli_fetch_assoc($result)) {
                // Construct full name
                $fullName = trim($resident['first_name'] . ' ' . 
                               ($resident['middle_name'] ? $resident['middle_name'] . ' ' : '') . 
                               $resident['last_name']);
                
                $user = [
                    'id' => $resident['id'],
                    'name' => $fullName,
                    'email' => $resident['email']
                ];
            }
            mysqli_stmt_close($stmt2);
        }
    }
    
    if ($user) {
        echo json_encode([
            'success' => true,
            'user' => $user
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'User not found'
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Email required'
    ]);
}
?>