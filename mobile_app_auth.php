<?php
session_start();
require 'config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    $error_message = '';
    $user_data = null;
    
    // First check in admin table
    $adminQuery = "SELECT id, username, email, password, admin_role FROM admin WHERE (username = ? OR email = ?) AND is_active = 1";
    $stmt = mysqli_prepare($conn, $adminQuery);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ss", $username, $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) > 0) {
            $admin = mysqli_fetch_assoc($result);
            if (password_verify($password, $admin['password'])) {
                // For admin, use username as name
                $fullName = $admin['username'];
                $user_data = [
                    'id' => $admin['id'],
                    'name' => $fullName,
                    'first_name' => $fullName,
                    'last_name' => '',
                    'middle_name' => '',
                    'email' => $admin['email'],
                    'type' => 'admin'
                ];
            }
        }
        mysqli_stmt_close($stmt);
    }
    
    // If not admin, check resident table
    if (!$user_data) {
        $residentQuery = "SELECT id, username, email, password, is_verified, first_name, last_name, middle_name 
                         FROM resident WHERE (username = ? OR email = ?) AND is_active = 1";
        $stmt2 = mysqli_prepare($conn, $residentQuery);
        if ($stmt2) {
            mysqli_stmt_bind_param($stmt2, "ss", $username, $username);
            mysqli_stmt_execute($stmt2);
            $result = mysqli_stmt_get_result($stmt2);
            
            if (mysqli_num_rows($result) > 0) {
                $resident = mysqli_fetch_assoc($result);
                if (password_verify($password, $resident['password'])) {
                    if ($resident['is_verified'] == 1) {
                        // Construct full name
                        $fullName = trim($resident['first_name'] . ' ' . 
                                       ($resident['middle_name'] ? $resident['middle_name'] . ' ' : '') . 
                                       $resident['last_name']);
                        
                        $user_data = [
                            'id' => $resident['id'],
                            'name' => $fullName,
                            'first_name' => $resident['first_name'],
                            'last_name' => $resident['last_name'],
                            'middle_name' => $resident['middle_name'],
                            'email' => $resident['email'],
                            'type' => 'resident'
                        ];
                    } else {
                        $error_message = 'Your account is pending verification.';
                    }
                }
            }
            mysqli_stmt_close($stmt2);
        } else {
            error_log("Resident query prepare failed: " . mysqli_error($conn));
        }
    }
    
    if ($user_data) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Success - App Connected</title>
            <style>
                body {
                    margin: 0;
                    padding: 0;
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background: linear-gradient(150deg, #43e97b 20%, #11612e 80%);
                    min-height: 100vh;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                }
                .success-card {
                    background: white;
                    border-radius: 12px;
                    padding: 40px;
                    text-align: center;
                    max-width: 350px;
                    margin: 20px;
                }
                .check-icon {
                    font-size: 64px;
                    color: #43e97b;
                    margin-bottom: 20px;
                }
                h2 {
                    color: #333;
                    margin-bottom: 10px;
                }
                p {
                    color: #666;
                    margin-bottom: 20px;
                }
                .user-info {
                    background: #f5f5f5;
                    padding: 15px;
                    border-radius: 8px;
                    text-align: left;
                    margin: 20px 0;
                }
                .user-info p {
                    margin: 5px 0;
                    font-size: 14px;
                }
                .user-info strong {
                    color: #43e97b;
                }
                .barangay-note {
                    font-size: 12px;
                    color: #D32F2F;
                    text-align: center;
                    margin-top: 10px;
                    padding: 8px;
                    background: #FFF3E0;
                    border-radius: 6px;
                }
                .btn-close {
                    background: #43e97b;
                    color: white;
                    border: none;
                    padding: 12px 30px;
                    border-radius: 8px;
                    font-size: 16px;
                    cursor: pointer;
                    width: 100%;
                }
                .btn-close:hover {
                    background: #36c76d;
                }
            </style>
        </head>
        <body>
            <div class="success-card">
                <div class="check-icon">✓</div>
                <h2>Account Connected!</h2>
                <p>Your BRITE account is now linked to the mobile app</p>
                
                <div class="user-info">
                    <p><strong>📧 Email:</strong> <span id="user_email"><?php echo htmlspecialchars($user_data['email']); ?></span></p>
                    <p><strong>👤 Name:</strong> <span id="user_name"><?php echo htmlspecialchars($user_data['name']); ?></span></p>
                </div>
                
                <div class="barangay-note">
                    🏘️ Barangay: San Bartolome (Fixed)
                </div>
                
                <button class="btn-close" onclick="closeAndReturn()">
                    ← Return to App
                </button>
            </div>
            
            <script>
                function closeAndReturn() {
                    window.location.href = 'briteapp://sync_success?email=<?php echo urlencode($user_data['email']); ?>&name=<?php echo urlencode($user_data['name']); ?>';
                }
                setTimeout(closeAndReturn, 3000);
            </script>
        </body>
        </html>
        <?php
    } else {
        $error_param = urlencode($error_message ?: 'Invalid credentials');
        header("Location: mobile_app_login.php?error=" . $error_param);
        exit();
    }
}
?>