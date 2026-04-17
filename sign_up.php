<?php
session_start();
require 'config/database.php';

$error_message = '';
$success_message = '';
$current_step = 1;
$form_data = $_SESSION['signup_data'] ?? [];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Store form data in session
    if (!isset($_SESSION['signup_data'])) {
        $_SESSION['signup_data'] = [];
    }
    
    // Get the action (next, prev, or submit)
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $current_step = isset($_POST['current_step']) ? (int)$_POST['current_step'] : 1;
    
    // Merge posted data with session data
    $post_data = $_POST;
    unset($post_data['action']);
    unset($post_data['current_step']);
    unset($post_data['submit_final']);
    
    $_SESSION['signup_data'] = array_merge($_SESSION['signup_data'], $post_data);
    $form_data = $_SESSION['signup_data'];
    
    // Handle navigation
    if ($action === 'next') {
        $current_step++;
        if ($current_step > 5) $current_step = 5;
    } elseif ($action === 'prev') {
        $current_step--;
        if ($current_step < 1) $current_step = 1;
    } elseif ($action === 'submit') {
        // Final submission - insert into database
        $data = $_SESSION['signup_data'];
        
        // Validation
        $errors = [];
        
        if (empty($data['first_name'])) $errors[] = 'First name is required';
        if (empty($data['last_name'])) $errors[] = 'Last name is required';
        if (empty($data['date_of_birth'])) $errors[] = 'Date of birth is required';
        if (empty($data['gender'])) $errors[] = 'Gender is required';
        if (empty($data['phone_number'])) $errors[] = 'Phone number is required';
        if (empty($data['street'])) $errors[] = 'Street is required';
        if (empty($data['barangay'])) $errors[] = 'Barangay is required';
        if (empty($data['municipality'])) $errors[] = 'Municipality is required';
        if (empty($data['province'])) $errors[] = 'Province is required';
        if (empty($data['email'])) $errors[] = 'Email is required';
        if (empty($data['username'])) $errors[] = 'Username is required';
        if (empty($data['password'])) $errors[] = 'Password is required';
        if ($data['password'] !== $data['confirm_password']) $errors[] = 'Passwords do not match';
        
        // Phone number validation
        if (!empty($data['phone_number'])) {
            $phone = preg_replace('/[^0-9]/', '', $data['phone_number']);
            if (strlen($phone) < 10 || strlen($phone) > 13) {
                $errors[] = 'Phone number must be 10-13 digits long';
            }
        }
        
        // Strong password validation
        $password = $data['password'];
        $password_errors = [];
        if (strlen($password) < 8) $password_errors[] = 'Password must be at least 8 characters long';
        if (!preg_match('/[A-Z]/', $password)) $password_errors[] = 'Password must contain at least one uppercase letter';
        if (!preg_match('/[a-z]/', $password)) $password_errors[] = 'Password must contain at least one lowercase letter';
        if (!preg_match('/[0-9]/', $password)) $password_errors[] = 'Password must contain at least one number';
        if (!preg_match('/[^A-Za-z0-9]/', $password)) $password_errors[] = 'Password must contain at least one special character (!@#$%^&* etc.)';
        
        if (!empty($password_errors)) {
            foreach ($password_errors as $pe) {
                $errors[] = $pe;
            }
        }
        
        // Scanned document validation
        if (empty($_FILES['scanned_document']['name'])) {
            $errors[] = 'Scanned document or ID is required';
        }
        
        // Check if username or email already exists
        if (empty($errors)) {
            $checkQuery = "SELECT id FROM resident WHERE username = ? OR email = ?";
            $checkStmt = mysqli_prepare($conn, $checkQuery);
            if ($checkStmt) {
                mysqli_stmt_bind_param($checkStmt, "ss", $data['username'], $data['email']);
                mysqli_stmt_execute($checkStmt);
                mysqli_stmt_store_result($checkStmt);
                
                if (mysqli_stmt_num_rows($checkStmt) > 0) {
                    $errors[] = 'Username or email already exists';
                }
                mysqli_stmt_close($checkStmt);
            }
        }
        
        // Handle file upload
        $document_file_path = '';
        if (empty($errors) && isset($_FILES['scanned_document']) && $_FILES['scanned_document']['error'] == 0) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
            $file_type = $_FILES['scanned_document']['type'];
            $file_size = $_FILES['scanned_document']['size'];
            
            if (!in_array($file_type, $allowed_types)) {
                $errors[] = 'Only JPG, PNG, and PDF files are allowed';
            } elseif ($file_size > 5 * 1024 * 1024) {
                $errors[] = 'File size must be less than 5MB';
            } else {
                $upload_dir = 'uploads/documents/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = pathinfo($_FILES['scanned_document']['name'], PATHINFO_EXTENSION);
                $filename = time() . '_' . uniqid() . '.' . $file_extension;
                $file_path = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['scanned_document']['tmp_name'], $file_path)) {
                    $document_file_path = $file_path;
                } else {
                    $errors[] = 'Failed to upload file';
                }
            }
        }
        
        // Combine address fields (house number is optional)
        $house_number_part = !empty($data['house_number']) ? $data['house_number'] . ', ' : '';
        $full_address = $house_number_part . $data['street'] . ', ' . $data['barangay'] . ', ' . $data['municipality'] . ', ' . $data['province'];
        
        // Insert into database if no errors
        if (empty($errors)) {
            $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
            
            // First, check if the scanned_document column exists
            $checkColumn = "SHOW COLUMNS FROM resident LIKE 'scanned_document'";
            $columnResult = mysqli_query($conn, $checkColumn);
            
            if (mysqli_num_rows($columnResult) == 0) {
                // Column doesn't exist, add it
                $alterQuery = "ALTER TABLE resident ADD COLUMN scanned_document VARCHAR(255) NULL AFTER password";
                mysqli_query($conn, $alterQuery);
            }
            
            // Check if is_verified column exists
            $checkVerifiedColumn = "SHOW COLUMNS FROM resident LIKE 'is_verified'";
            $verifiedResult = mysqli_query($conn, $checkVerifiedColumn);
            
            if (mysqli_num_rows($verifiedResult) == 0) {
                // Column doesn't exist, add it
                $alterVerified = "ALTER TABLE resident ADD COLUMN is_verified TINYINT(1) DEFAULT 0 AFTER scanned_document";
                mysqli_query($conn, $alterVerified);
            }
            
            // Check if phone_number column exists
            $checkPhoneColumn = "SHOW COLUMNS FROM resident LIKE 'phone_number'";
            $phoneResult = mysqli_query($conn, $checkPhoneColumn);
            
            if (mysqli_num_rows($phoneResult) == 0) {
                // Column doesn't exist, add it
                $alterPhone = "ALTER TABLE resident ADD COLUMN phone_number VARCHAR(20) NULL AFTER email";
                mysqli_query($conn, $alterPhone);
            }
            
            // Insert using the correct column names based on your existing table
            $insertQuery = "INSERT INTO resident (first_name, last_name, date_of_birth, gender, address, email, phone_number, username, password, scanned_document, is_verified, is_active, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, NOW())";
            
            $insertStmt = mysqli_prepare($conn, $insertQuery);
            
            if ($insertStmt) {
                mysqli_stmt_bind_param($insertStmt, "ssssssssss", 
                    $data['first_name'], 
                    $data['last_name'], 
                    $data['date_of_birth'], 
                    $data['gender'], 
                    $full_address,
                    $data['email'], 
                    $data['phone_number'],
                    $data['username'], 
                    $hashed_password, 
                    $document_file_path
                );
                
                if (mysqli_stmt_execute($insertStmt)) {
                    unset($_SESSION['signup_data']);
                    ?>
                    <!DOCTYPE html>
                    <html lang="en">
                    <head>
                        <meta charset="UTF-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                        <title>Registration Success - BRITE</title>
                        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
                        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
                        <style>
                            body {
                                margin: 0;
                                padding: 0;
                                min-height: 100vh;
                                background: linear-gradient(135deg, #4facfe, #43e97b);
                                display: flex;
                                justify-content: center;
                                align-items: center;
                                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                            }
                        </style>
                    </head>
                    <body>
                        <script>
                            Swal.fire({
                                title: 'Registration Complete!',
                                html: `
                                    <div style="text-align: center;">
                                        <i class="fas fa-check-circle" style="color: #43e97b; font-size: 64px; margin-bottom: 15px;"></i>
                                        <h3 style="color: #333; margin-bottom: 15px;">Account Created Successfully!</h3>
                                        <div style="background: #fff3e0; padding: 15px; border-radius: 10px; margin: 15px 0; text-align: left;">
                                            <p style="margin: 8px 0;"><i class="fas fa-clock" style="color: #ff9800; width: 25px;"></i> <strong>Waiting for Verification</strong></p>
                                            <p style="margin: 8px 0;"><i class="fas fa-shield-alt" style="color: #43e97b; width: 25px;"></i> Admin will review your documents</p>
                                            <p style="margin: 8px 0;"><i class="fas fa-envelope" style="color: #43e97b; width: 25px;"></i> You'll receive email confirmation</p>
                                            <p style="margin: 8px 0;"><i class="fas fa-hourglass-half" style="color: #ff9800; width: 25px;"></i> Verification takes 1-3 days</p>
                                        </div>
                                        <p style="color: #666; font-size: 13px; margin-bottom: 15px;"><i class="fas fa-info-circle"></i> You can only login after verification</p>
                                        <button onclick="goToLogin()" style="background: #43e97b; color: white; border: none; padding: 12px 30px; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; margin-top: 10px;">
                                            <i class="fas fa-sign-in-alt"></i> Go to Login Page
                                        </button>
                                    </div>
                                `,
                                icon: 'success',
                                showConfirmButton: false,
                                allowOutsideClick: false,
                                allowEscapeKey: false,
                                didOpen: () => {
                                    const style = document.createElement('style');
                                    style.textContent = `
                                        button:hover {
                                            transform: translateY(-2px);
                                            box-shadow: 0 4px 12px rgba(67, 233, 123, 0.4);
                                        }
                                    `;
                                    document.head.appendChild(style);
                                }
                            });
                            
                            function goToLogin() {
                                window.location.href = 'sign_in.php';
                            }
                        </script>
                    </body>
                    </html>
                    <?php
                    exit;
                } else {
                    $error_message = 'Registration failed: ' . mysqli_error($conn);
                }
                mysqli_stmt_close($insertStmt);
            } else {
                $error_message = 'Database error: ' . mysqli_error($conn);
            }
        } else {
            $error_message = implode('<br>', $errors);
            $current_step = 5;
        }
    }
}

// Clear session data on page refresh (if not in the middle of a POST request)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['signup_data'])) {
    unset($_SESSION['signup_data']);
    $form_data = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - BRITE</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="sign.css">
    <style>
        /* Additional styles for multi-step form */
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            position: relative;
        }
        
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: #e0e0e0;
            z-index: 1;
        }
        
        .step-item {
            flex: 1;
            text-align: center;
            position: relative;
            z-index: 2;
            background: white;
        }
        
        .step-circle {
            width: 40px;
            height: 40px;
            background: #e0e0e0;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #666;
            font-weight: bold;
            margin-bottom: 8px;
            transition: all 0.3s ease;
        }
        
        .step-item.active .step-circle {
            background: #43e97b;
            color: white;
            box-shadow: 0 0 0 3px rgba(67, 233, 123, 0.3);
        }
        
        .step-item.completed .step-circle {
            background: #43e97b;
            color: white;
        }
        
        .step-label {
            font-size: 12px;
            color: #666;
        }
        
        .step-item.active .step-label {
            color: #43e97b;
            font-weight: bold;
        }
        
        .form-step {
            display: none;
        }
        
        .form-step.active-step {
            display: block;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateX(20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .navigation-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .navigation-buttons button {
            flex: 1;
            padding: 12px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
            border: none;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        .btn {
            background: #43e97b;
            color: white;
            border: none;
        }
        
        .btn:hover {
            background: #36c76d;
            transform: translateY(-2px);
        }
        
        .file-input-wrapper {
            position: relative;
            width: 100%;
        }
        
        .file-input-wrapper input[type="file"] {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 8px;
            width: 100%;
            cursor: pointer;
        }
        
        .file-info {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .age-display {
            margin-top: 5px;
            font-size: 12px;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .age-display.valid-age {
            color: #43e97b;
        }
        
        .age-display.invalid-age {
            color: #d33;
        }
        
        .error-message {
            color: #d33;
            font-size: 12px;
            margin-top: 5px;
        }
        
        .name-error {
            color: #d33;
            font-size: 11px;
            margin-top: 3px;
            display: none;
        }
        
        .success-message {
            color: #43e97b;
            font-size: 12px;
            margin-top: 5px;
        }
        
        .input-group {
            margin-bottom: 20px;
            position: relative;
        }
        
        .input-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        .input-group label .optional {
            font-weight: normal;
            color: #999;
            font-size: 12px;
        }
        
        .input-group select,
        .input-group input[type="date"],
        .input-group input[type="text"],
        .input-group input[type="email"],
        .input-group input[type="password"],
        .input-group input[type="tel"] {
            width: 100%;
            padding: 12px 12px 12px 40px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .input-group i {
            position: absolute;
            left: 12px;
            top: 42px;
            color: #999;
        }
        
        .input-group select:focus,
        .input-group input:focus {
            border-color: #43e97b;
            outline: none;
            box-shadow: 0 0 5px rgba(67, 233, 123, 0.4);
        }
        
        /* Date input border styling based on age validity */
        .input-group input[type="date"].valid-date {
            border-color: #43e97b;
            border-width: 2px;
        }
        
        .input-group input[type="date"].invalid-date {
            border-color: #d33;
            border-width: 2px;
            background-color: #fff5f5;
        }
        
        .input-group input.error-input {
            border-color: #d33;
            background-color: #fff5f5;
        }
        
        .input-group input.valid-input {
            border-color: #43e97b;
            border-width: 2px;
        }
        
        .name-error i, .error-message i {
            margin-right: 5px;
        }
        
        .success-check {
            color: #43e97b;
            font-size: 11px;
            margin-top: 3px;
            display: none;
        }
        
        /* Password requirements styling */
        .password-requirements {
            margin-top: 8px;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 6px;
            font-size: 11px;
        }
        
        .password-requirements .requirement {
            margin: 4px 0;
            color: #666;
        }
        
        .password-requirements .requirement i {
            margin-right: 5px;
            font-size: 10px;
        }
        
        .password-requirements .requirement.valid {
            color: #43e97b;
        }
        
        .password-requirements .requirement.invalid {
            color: #d33;
        }
        
        /* Age requirement message */
        .age-requirement {
            font-size: 11px;
            margin-top: 5px;
            color: #666;
        }
        
        .age-requirement i {
            margin-right: 3px;
        }
        
        /* Address section styling */
        .address-section .input-group {
            margin-bottom: 15px;
        }
        
        .link {
            margin-top: 20px;
            text-align: center;
        }
        
        .link button {
            padding: 10px 20px;
            background: #4facfe;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .link button:hover {
            background: #3a8edb;
            transform: translateY(-2px);
        }
        
        .required-star {
            color: #d33;
            margin-left: 3px;
        }
        
        .optional-text {
            color: #999;
            font-size: 11px;
            font-weight: normal;
            margin-left: 5px;
        }
        
        /* Loading spinner for validation */
        .checking-spinner {
            position: absolute;
            right: 12px;
            top: 42px;
            color: #999;
            display: none;
        }
        
        .input-group {
            position: relative;
        }
        
        /* Scanned document styling */
        .document-info {
            background: #e8f5e9;
            padding: 12px;
            border-radius: 8px;
            margin-top: 10px;
        }
        
        .document-info i {
            position: static;
            margin-right: 8px;
            color: #43e97b;
        }
        
        .document-info p {
            margin: 5px 0;
            font-size: 12px;
            color: #2e7d32;
        }
        
        /* Image preview styling */
        .image-preview {
            margin-top: 15px;
            display: none;
            position: relative;
        }
        
        .image-preview img {
            max-width: 100%;
            max-height: 200px;
            border-radius: 8px;
            border: 2px solid #ddd;
        }
        
        .clarity-status {
            margin-top: 10px;
            padding: 8px;
            border-radius: 6px;
            font-size: 12px;
            display: none;
        }
        
        .clarity-status.valid {
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 3px solid #43e97b;
        }
        
        .clarity-status.invalid {
            background: #ffebee;
            color: #d32f2f;
            border-left: 3px solid #d33;
        }
        
        .clarity-status i {
            margin-right: 5px;
        }
        
        /* Phone number styling */
        .phone-number-wrapper {
            position: relative;
        }
        
        .phone-prefix {
            position: absolute;
            left: 40px;
            top: 42px;
            color: #666;
            font-size: 12px;
            pointer-events: none;
            z-index: 5;
        }
        
        .input-group input[type="tel"] {
            padding-left: 70px;
        }
    </style>
</head>
<body>
<div class="login-wrapper">
    <div class="login-info">
        <div class="logo-container">
            <img src="logo.jpg" alt="Barangay logo" onerror="this.src='https://via.placeholder.com/120'">
        </div>
        <h1>BRITE - San Bartolome</h1>
        <p>Join our community! Create your account to start booking and managing your hospital appointments easily.</p>
    </div>

    <div class="login-form">
        <div class="container">
            <h1 class="form-title">Create Account</h1>
            
            <?php if ($error_message): ?>
            <script>
                Swal.fire({
                    title: 'Error!',
                    html: '<?php echo addslashes($error_message); ?>',
                    icon: 'error',
                    confirmButtonColor: '#d33',
                    confirmButtonText: 'OK'
                });
            </script>
            <?php endif; ?>
            
            <!-- Step Indicator -->
            <div class="step-indicator">
                <div class="step-item <?php echo $current_step >= 1 ? 'active' : ''; ?> <?php echo $current_step > 1 ? 'completed' : ''; ?>">
                    <div class="step-circle">1</div>
                    <div class="step-label">Personal Info</div>
                </div>
                <div class="step-item <?php echo $current_step >= 2 ? 'active' : ''; ?> <?php echo $current_step > 2 ? 'completed' : ''; ?>">
                    <div class="step-circle">2</div>
                    <div class="step-label">Basic Info</div>
                </div>
                <div class="step-item <?php echo $current_step >= 3 ? 'active' : ''; ?> <?php echo $current_step > 3 ? 'completed' : ''; ?>">
                    <div class="step-circle">3</div>
                    <div class="step-label">Address</div>
                </div>
                <div class="step-item <?php echo $current_step >= 4 ? 'active' : ''; ?> <?php echo $current_step > 4 ? 'completed' : ''; ?>">
                    <div class="step-circle">4</div>
                    <div class="step-label">Account Details</div>
                </div>
                <div class="step-item <?php echo $current_step >= 5 ? 'active' : ''; ?> <?php echo $current_step > 5 ? 'completed' : ''; ?>">
                    <div class="step-circle">5</div>
                    <div class="step-label">Verification</div>
                </div>
            </div>
            
            <form method="post" id="signupForm" enctype="multipart/form-data">
                <input type="hidden" name="current_step" id="current_step" value="<?php echo $current_step; ?>">
                <input type="hidden" name="action" id="action" value="">
                
                <!-- Step 1: Personal Information -->
                <div class="form-step <?php echo $current_step == 1 ? 'active-step' : ''; ?>" id="step1">
                    <div class="input-group">
                        <label for="first_name">First Name <span class="required-star">*</span></label>
                        <i class="fas fa-user"></i>
                        <input type="text" name="first_name" id="first_name" placeholder="Enter your first name" 
                               value="<?php echo htmlspecialchars($form_data['first_name'] ?? ''); ?>" 
                               oninput="validateRealName('first_name')" required>
                        <div id="first_name_error" class="name-error"><i class="fas fa-exclamation-triangle"></i> <span></span></div>
                    </div>
                    
                    <div class="input-group">
                        <label for="last_name">Last Name <span class="required-star">*</span></label>
                        <i class="fas fa-user"></i>
                        <input type="text" name="last_name" id="last_name" placeholder="Enter your last name" 
                               value="<?php echo htmlspecialchars($form_data['last_name'] ?? ''); ?>" 
                               oninput="validateRealName('last_name')" required>
                        <div id="last_name_error" class="name-error"><i class="fas fa-exclamation-triangle"></i> <span></span></div>
                    </div>
                    
                    <div class="navigation-buttons">
                        <button type="button" class="btn" onclick="validateAndNextStep1()">Next <i class="fas fa-arrow-right"></i></button>
                    </div>
                </div>
                
                <!-- Step 2: Basic Information (DOB, Gender & Phone) -->
                <div class="form-step <?php echo $current_step == 2 ? 'active-step' : ''; ?>" id="step2">
                    <div class="input-group">
                        <label for="date_of_birth">Date of Birth <span class="required-star">*</span></label>
                        <i class="fas fa-calendar-alt"></i>
                        <input type="date" name="date_of_birth" id="date_of_birth" 
                               value="<?php echo htmlspecialchars($form_data['date_of_birth'] ?? ''); ?>" 
                               onchange="calculateAge()" required>
                        <div id="age_display" class="age-display"></div>
                        <div class="age-requirement" id="age_requirement">
                            <i class="fas fa-info-circle"></i> Must be 15 years old or above to register
                        </div>
                    </div>
                    
                    <div class="input-group">
                        <label for="gender">Gender <span class="required-star">*</span></label>
                        <i class="fas fa-venus-mars"></i>
                        <select name="gender" id="gender" required>
                            <option value="" disabled <?php echo empty($form_data['gender']) ? 'selected' : ''; ?>>Select Gender</option>
                            <option value="Male" <?php echo ($form_data['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($form_data['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                            <option value="Other" <?php echo ($form_data['gender'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    
                    <div class="input-group">
                        <label for="phone_number">Phone Number <span class="required-star">*</span></label>
                        <i class="fas fa-phone-alt"></i>
                        <input type="tel" name="phone_number" id="phone_number" placeholder="09123456789" 
                               value="<?php echo htmlspecialchars($form_data['phone_number'] ?? ''); ?>" 
                               oninput="validatePhoneNumber()" maxlength="13" required>
                        <div id="phone_error" class="name-error"><i class="fas fa-exclamation-triangle"></i> <span></span></div>
                        <div id="phone_success" class="success-check"><i class="fas fa-check-circle"></i> Valid phone number</div>
                    </div>
                    
                    <div class="navigation-buttons">
                        <button type="button" class="btn btn-secondary" onclick="prevStep()"><i class="fas fa-arrow-left"></i> Previous</button>
                        <button type="button" class="btn" onclick="validateStep2AndNext()">Next <i class="fas fa-arrow-right"></i></button>
                    </div>
                </div>
                
                <!-- Step 3: Address Information -->
                <div class="form-step <?php echo $current_step == 3 ? 'active-step' : ''; ?>" id="step3">
                    <div class="input-group">
                        <label for="house_number">House/Block/Lot Number <span class="optional-text">(Optional)</span></label>
                        <i class="fas fa-home"></i>
                        <input type="text" name="house_number" id="house_number" placeholder="Enter house/block/lot number (optional)" 
                               value="<?php echo htmlspecialchars($form_data['house_number'] ?? ''); ?>">
                    </div>
                    
                    <div class="input-group">
                        <label for="street">Street/Subdivision <span class="required-star">*</span></label>
                        <i class="fas fa-road"></i>
                        <input type="text" name="street" id="street" placeholder="Enter street or subdivision name" 
                               value="<?php echo htmlspecialchars($form_data['street'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="input-group">
                        <label for="barangay">Barangay <span class="required-star">*</span></label>
                        <i class="fas fa-map-marker-alt"></i>
                        <input type="text" name="barangay" id="barangay" placeholder="Enter barangay" 
                               value="<?php echo htmlspecialchars($form_data['barangay'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="input-group">
                        <label for="municipality">Municipality/City <span class="required-star">*</span></label>
                        <i class="fas fa-city"></i>
                        <input type="text" name="municipality" id="municipality" placeholder="Enter municipality or city" 
                               value="<?php echo htmlspecialchars($form_data['municipality'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="input-group">
                        <label for="province">Province <span class="required-star">*</span></label>
                        <i class="fas fa-globe"></i>
                        <input type="text" name="province" id="province" placeholder="Enter province" 
                               value="<?php echo htmlspecialchars($form_data['province'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="navigation-buttons">
                        <button type="button" class="btn btn-secondary" onclick="prevStep()"><i class="fas fa-arrow-left"></i> Previous</button>
                        <button type="button" class="btn" onclick="validateStep3AndNext()">Next <i class="fas fa-arrow-right"></i></button>
                    </div>
                </div>
                
                <!-- Step 4: Account Details -->
                <div class="form-step <?php echo $current_step == 4 ? 'active-step' : ''; ?>" id="step4">
                    <div class="input-group">
                        <label for="email">Email Address <span class="required-star">*</span></label>
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="email" id="email" placeholder="Enter your email address" 
                               value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>" 
                               oninput="checkEmailAvailability()" required>
                        <div id="email_error" class="name-error"><i class="fas fa-exclamation-triangle"></i> <span></span></div>
                        <div id="email_success" class="success-check"><i class="fas fa-check-circle"></i> Email is available</div>
                        <div class="checking-spinner" id="email_spinner"><i class="fas fa-spinner fa-spin"></i></div>
                    </div>
                    
                    <div class="input-group">
                        <label for="username">Username <span class="required-star">*</span></label>
                        <i class="fas fa-user-circle"></i>
                        <input type="text" name="username" id="username" placeholder="Choose a username" 
                               value="<?php echo htmlspecialchars($form_data['username'] ?? ''); ?>" 
                               oninput="checkUsernameAvailability()" required>
                        <div id="username_error" class="name-error"><i class="fas fa-exclamation-triangle"></i> <span></span></div>
                        <div id="username_success" class="success-check"><i class="fas fa-check-circle"></i> Username is available</div>
                        <div class="checking-spinner" id="username_spinner"><i class="fas fa-spinner fa-spin"></i></div>
                    </div>
                    
                    <div class="input-group">
                        <label for="password">Password <span class="required-star">*</span></label>
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" id="password" placeholder="Create a strong password" 
                               value="<?php echo htmlspecialchars($form_data['password'] ?? ''); ?>"
                               oninput="validatePassword()" required>
                        <div id="password_strength" class="success-check" style="color: #ff9800;"></div>
                        
                        <div class="password-requirements" id="password_requirements">
                            <div class="requirement" id="req_length"><i class="fas fa-circle"></i> At least 8 characters</div>
                            <div class="requirement" id="req_uppercase"><i class="fas fa-circle"></i> At least one uppercase letter (A-Z)</div>
                            <div class="requirement" id="req_lowercase"><i class="fas fa-circle"></i> At least one lowercase letter (a-z)</div>
                            <div class="requirement" id="req_number"><i class="fas fa-circle"></i> At least one number (0-9)</div>
                            <div class="requirement" id="req_special"><i class="fas fa-circle"></i> At least one special character (!@#$%^&* etc.)</div>
                        </div>
                    </div>
                    
                    <div class="input-group">
                        <label for="confirm_password">Confirm Password <span class="required-star">*</span></label>
                        <i class="fas fa-lock"></i>
                        <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm your password" 
                               value="<?php echo htmlspecialchars($form_data['confirm_password'] ?? ''); ?>"
                               oninput="checkPasswordMatch()" required>
                        <div id="password_match_error" class="error-message"><i class="fas fa-times-circle"></i> <span></span></div>
                    </div>
                    
                    <div class="navigation-buttons">
                        <button type="button" class="btn btn-secondary" onclick="prevStep()"><i class="fas fa-arrow-left"></i> Previous</button>
                        <button type="button" class="btn" id="nextButton" onclick="validateAndNext()">Next <i class="fas fa-arrow-right"></i></button>
                    </div>
                </div>
                
                <!-- Step 5: Scanned Document / ID Upload -->
                <div class="form-step <?php echo $current_step == 5 ? 'active-step' : ''; ?>" id="step5">
                    <div class="input-group">
                        <label for="scanned_document">Scanned Document or ID <span class="required-star">*</span></label>
                        <i class="fas fa-file-pdf"></i>
                        <div class="file-input-wrapper">
                            <input type="file" name="scanned_document" id="scanned_document" accept=".jpg,.jpeg,.png,.pdf" onchange="handleFileUpload(this.files[0])" required>
                        </div>
                        <div class="image-preview" id="imagePreview">
                            <img id="previewImg" src="">
                            <div class="clarity-status" id="clarityStatus"></div>
                        </div>
                        <div class="document-info">
                            <i class="fas fa-info-circle"></i>
                            <p><strong>Please upload a scanned copy of any valid ID or document:</strong></p>
                            <p>• Valid Government ID (Driver's License, Passport, UMID, Postal ID, etc.)</p>
                            <p>• Barangay ID or Barangay Clearance</p>
                            <p>• School ID (for students)</p>
                            <p>• Company ID</p>
                            <p>• Any official document with your name and photo</p>
                            <p><strong>File requirements:</strong> JPG, PNG, or PDF format, Max 5MB</p>
                            <p><strong>Image clarity:</strong> Blurred or low-quality images will be rejected</p>
                        </div>
                        <div id="file_error" class="name-error"><i class="fas fa-exclamation-triangle"></i> <span></span></div>
                    </div>
                    
                    <div class="navigation-buttons">
                        <button type="button" class="btn btn-secondary" onclick="prevStep()"><i class="fas fa-arrow-left"></i> Previous</button>
                        <button type="button" class="btn" id="submitButton" onclick="submitForm()">Submit Registration <i class="fas fa-check-circle"></i></button>
                    </div>
                </div>
            </form>
            
            <div class="link">
                <p>Already have an account?</p>
                <a href="sign_in.php"><button type="button"><i class="fas fa-sign-in-alt"></i> Sign In</button></a>
            </div>
        </div>
    </div>
</div>

<script>
    let emailAvailable = false;
    let usernameAvailable = false;
    let isPasswordStrong = false;
    let isImageClear = false;
    let isPhoneValid = false;
    
    // Validate phone number (only numbers, 10-13 digits)
    function validatePhoneNumber() {
        const phoneInput = document.getElementById('phone_number');
        const errorDiv = document.getElementById('phone_error');
        const successDiv = document.getElementById('phone_success');
        let phone = phoneInput.value.trim();
        
        // Remove all non-numeric characters
        phone = phone.replace(/[^0-9]/g, '');
        
        // Update input with only numbers
        if (phoneInput.value !== phone) {
            phoneInput.value = phone;
        }
        
        if (phone === '') {
            errorDiv.style.display = 'none';
            successDiv.style.display = 'none';
            isPhoneValid = false;
            phoneInput.classList.remove('valid-input', 'error-input');
            return false;
        }
        
        // Check length (Philippine mobile numbers are 10-13 digits including country code)
        if (phone.length >= 10 && phone.length <= 13) {
            errorDiv.style.display = 'none';
            successDiv.style.display = 'block';
            isPhoneValid = true;
            phoneInput.classList.add('valid-input');
            phoneInput.classList.remove('error-input');
            return true;
        } else {
            errorDiv.querySelector('span').innerHTML = ' Phone number must be 10-13 digits long';
            errorDiv.style.display = 'block';
            successDiv.style.display = 'none';
            isPhoneValid = false;
            phoneInput.classList.add('error-input');
            phoneInput.classList.remove('valid-input');
            return false;
        }
    }
    
    // Detect image blur/clarity
    function detectImageClarity(imageElement, callback) {
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        
        canvas.width = imageElement.width;
        canvas.height = imageElement.height;
        ctx.drawImage(imageElement, 0, 0, canvas.width, canvas.height);
        
        const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const data = imageData.data;
        
        let sum = 0;
        let sumSquared = 0;
        
        for (let i = 0; i < data.length; i += 4) {
            const gray = (data[i] + data[i + 1] + data[i + 2]) / 3;
            sum += gray;
            sumSquared += gray * gray;
        }
        
        const mean = sum / (data.length / 4);
        const variance = (sumSquared / (data.length / 4)) - (mean * mean);
        
        const isClear = variance > 300;
        
        callback(isClear, variance);
    }
    
    // Handle file upload with clarity check
    function handleFileUpload(file) {
        const fileInput = document.getElementById('scanned_document');
        const errorDiv = document.getElementById('file_error');
        const previewDiv = document.getElementById('imagePreview');
        const previewImg = document.getElementById('previewImg');
        const clarityStatus = document.getElementById('clarityStatus');
        
        if (!file) return;
        
        // Check file type
        const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
        if (!allowedTypes.includes(file.type) && file.type !== 'application/pdf') {
            errorDiv.querySelector('span').innerHTML = ' Only JPG, PNG, and PDF files are allowed';
            errorDiv.style.display = 'block';
            fileInput.value = '';
            previewDiv.style.display = 'none';
            isImageClear = false;
            return;
        }
        
        // Check file size
        if (file.size > 5 * 1024 * 1024) {
            errorDiv.querySelector('span').innerHTML = ' File size must be less than 5MB';
            errorDiv.style.display = 'block';
            fileInput.value = '';
            previewDiv.style.display = 'none';
            isImageClear = false;
            return;
        }
        
        errorDiv.style.display = 'none';
        
        // For PDF files, skip clarity check
        if (file.type === 'application/pdf') {
            isImageClear = true;
            previewDiv.style.display = 'none';
            return;
        }
        
        // For images, check clarity
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            previewDiv.style.display = 'block';
            
            previewImg.onload = function() {
                detectImageClarity(previewImg, function(isClear, variance) {
                    isImageClear = isClear;
                    
                    if (isClear) {
                        clarityStatus.innerHTML = '<i class="fas fa-check-circle"></i> Image is clear and acceptable';
                        clarityStatus.className = 'clarity-status valid';
                        clarityStatus.style.display = 'block';
                    } else {
                        clarityStatus.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Image is blurry or low quality. Please upload a clearer image.';
                        clarityStatus.className = 'clarity-status invalid';
                        clarityStatus.style.display = 'block';
                    }
                });
            };
        };
        reader.readAsDataURL(file);
    }
    
    // Validate password with all requirements
    function validatePassword() {
        const password = document.getElementById('password').value;
        const strengthDiv = document.getElementById('password_strength');
        
        const hasLength = password.length >= 8;
        const hasUppercase = /[A-Z]/.test(password);
        const hasLowercase = /[a-z]/.test(password);
        const hasNumber = /[0-9]/.test(password);
        const hasSpecial = /[^A-Za-z0-9]/.test(password);
        
        updateRequirement('req_length', hasLength);
        updateRequirement('req_uppercase', hasUppercase);
        updateRequirement('req_lowercase', hasLowercase);
        updateRequirement('req_number', hasNumber);
        updateRequirement('req_special', hasSpecial);
        
        isPasswordStrong = hasLength && hasUppercase && hasLowercase && hasNumber && hasSpecial;
        
        if (password === '') {
            strengthDiv.innerHTML = '';
            return false;
        }
        
        if (isPasswordStrong) {
            strengthDiv.innerHTML = '<i class="fas fa-check-circle"></i> Strong password - All requirements met!';
            strengthDiv.style.color = '#43e97b';
            document.getElementById('password').classList.add('valid-input');
            document.getElementById('password').classList.remove('error-input');
        } else {
            strengthDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Password does not meet all requirements';
            strengthDiv.style.color = '#d33';
            document.getElementById('password').classList.remove('valid-input');
            document.getElementById('password').classList.add('error-input');
        }
        
        checkPasswordMatch();
        
        return isPasswordStrong;
    }
    
    function updateRequirement(elementId, isValid) {
        const element = document.getElementById(elementId);
        if (element) {
            const text = element.innerHTML.split('</i> ')[1];
            if (isValid) {
                element.innerHTML = '<i class="fas fa-check-circle"></i> ' + text;
                element.classList.add('valid');
                element.classList.remove('invalid');
            } else {
                element.innerHTML = '<i class="fas fa-times-circle"></i> ' + text;
                element.classList.add('invalid');
                element.classList.remove('valid');
            }
        }
    }
    
    function checkEmailAvailability() {
        const email = document.getElementById('email').value.trim();
        const errorDiv = document.getElementById('email_error');
        const successDiv = document.getElementById('email_success');
        const spinner = document.getElementById('email_spinner');
        
        if (email === '') {
            errorDiv.style.display = 'none';
            successDiv.style.display = 'none';
            emailAvailable = false;
            return;
        }
        
        if (!isValidEmail(email)) {
            errorDiv.querySelector('span').innerHTML = ' Please enter a valid email address';
            errorDiv.style.display = 'block';
            successDiv.style.display = 'none';
            emailAvailable = false;
            return;
        }
        
        spinner.style.display = 'block';
        
        fetch('check_availability.php?email=' + encodeURIComponent(email))
            .then(response => response.json())
            .then(data => {
                spinner.style.display = 'none';
                if (data.exists) {
                    errorDiv.querySelector('span').innerHTML = ' This email is already registered';
                    errorDiv.style.display = 'block';
                    successDiv.style.display = 'none';
                    emailAvailable = false;
                    document.getElementById('email').classList.add('error-input');
                    document.getElementById('email').classList.remove('valid-input');
                } else {
                    errorDiv.style.display = 'none';
                    successDiv.style.display = 'block';
                    emailAvailable = true;
                    document.getElementById('email').classList.remove('error-input');
                    document.getElementById('email').classList.add('valid-input');
                }
            })
            .catch(error => {
                spinner.style.display = 'none';
                console.error('Error:', error);
            });
    }
    
    function checkUsernameAvailability() {
        const username = document.getElementById('username').value.trim();
        const errorDiv = document.getElementById('username_error');
        const successDiv = document.getElementById('username_success');
        const spinner = document.getElementById('username_spinner');
        
        if (username === '') {
            errorDiv.style.display = 'none';
            successDiv.style.display = 'none';
            usernameAvailable = false;
            return;
        }
        
        if (username.length < 3) {
            errorDiv.querySelector('span').innerHTML = ' Username must be at least 3 characters';
            errorDiv.style.display = 'block';
            successDiv.style.display = 'none';
            usernameAvailable = false;
            return;
        }
        
        spinner.style.display = 'block';
        
        fetch('check_availability.php?username=' + encodeURIComponent(username))
            .then(response => response.json())
            .then(data => {
                spinner.style.display = 'none';
                if (data.exists) {
                    errorDiv.querySelector('span').innerHTML = ' This username is already taken';
                    errorDiv.style.display = 'block';
                    successDiv.style.display = 'none';
                    usernameAvailable = false;
                    document.getElementById('username').classList.add('error-input');
                    document.getElementById('username').classList.remove('valid-input');
                } else {
                    errorDiv.style.display = 'none';
                    successDiv.style.display = 'block';
                    usernameAvailable = true;
                    document.getElementById('username').classList.remove('error-input');
                    document.getElementById('username').classList.add('valid-input');
                }
            })
            .catch(error => {
                spinner.style.display = 'none';
                console.error('Error:', error);
            });
    }
    
    function checkPasswordMatch() {
        const password = document.getElementById('password').value;
        const confirmPassword = document.getElementById('confirm_password').value;
        const errorDiv = document.getElementById('password_match_error');
        const errorSpan = errorDiv ? errorDiv.querySelector('span') : null;
        
        if (password && confirmPassword) {
            if (password !== confirmPassword) {
                if (errorSpan) errorSpan.innerHTML = ' Passwords do not match!';
                if (errorDiv) errorDiv.style.display = 'block';
                return false;
            } else {
                if (errorDiv) errorDiv.style.display = 'none';
                return true;
            }
        }
        if (errorDiv) errorDiv.style.display = 'none';
        return true;
    }
    
    const fakeNames = [
        'asdf', 'qwer', 'zxcv', 'test', 'fake', 'dummy', 'sample', 
        'user', 'admin', 'guest', 'anonymous', 'none', 'n/a', 'null',
        'abc', 'abcd', 'asdfg', 'qwerty', '123', '111', 'aaa', 'bbb',
        'xxx', 'yyy', 'zzz', 'foo', 'bar', 'baz', 'lorem', 'ipsum'
    ];
    
    function isFakeName(name) {
        if (!name) return false;
        
        const lowerName = name.toLowerCase().trim();
        
        if (fakeNames.includes(lowerName)) return true;
        if (lowerName.length < 2) return true;
        if (/^([a-z])\1+$/.test(lowerName)) return true;
        if (/^\d+$/.test(lowerName)) return true;
        if (/^[asdfghjkl]+$/i.test(lowerName) || /^[qwertyuiop]+$/i.test(lowerName) || /^[zxcvbnm]+$/i.test(lowerName)) return true;
        if (/^[a-z]{4,}\d+$/i.test(lowerName)) return true;
        
        return false;
    }
    
    function validateRealName(fieldId) {
        const input = document.getElementById(fieldId);
        const errorDiv = document.getElementById(`${fieldId}_error`);
        const errorSpan = errorDiv ? errorDiv.querySelector('span') : null;
        const name = input.value.trim();
        
        if (name === '') {
            if (errorDiv) errorDiv.style.display = 'none';
            if (input) input.classList.remove('error-input');
            return false;
        }
        
        if (isFakeName(name)) {
            if (errorSpan) errorSpan.innerHTML = ' Please enter a valid real name. Fake names are not allowed.';
            if (errorDiv) errorDiv.style.display = 'block';
            if (input) input.classList.add('error-input');
            return false;
        }
        
        if (!/^[A-Za-z\s\-'ñÑ]+$/.test(name)) {
            if (errorSpan) errorSpan.innerHTML = ' Name should only contain letters, spaces, hyphens, and apostrophes.';
            if (errorDiv) errorDiv.style.display = 'block';
            if (input) input.classList.add('error-input');
            return false;
        }
        
        if (name.replace(/[^A-Za-z]/g, '').length < 2) {
            if (errorSpan) errorSpan.innerHTML = ' Name must contain at least 2 letters.';
            if (errorDiv) errorDiv.style.display = 'block';
            if (input) input.classList.add('error-input');
            return false;
        }
        
        const words = name.split(' ');
        const capitalized = words.map(word => {
            if (word.length > 0) {
                return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
            }
            return word;
        }).join(' ');
        
        if (name !== capitalized) input.value = capitalized;
        
        if (errorDiv) errorDiv.style.display = 'none';
        if (input) input.classList.remove('error-input');
        return true;
    }
    
    function calculateAge() {
        const dobInput = document.getElementById('date_of_birth');
        const dob = dobInput.value;
        const ageDisplay = document.getElementById('age_display');
        const ageRequirement = document.getElementById('age_requirement');
        
        if (dob) {
            const birthDate = new Date(dob);
            const today = new Date();
            let age = today.getFullYear() - birthDate.getFullYear();
            const monthDiff = today.getMonth() - birthDate.getMonth();
            
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) age--;
            
            const isValidAge = age >= 15;
            
            if (isValidAge) {
                ageDisplay.innerHTML = `<i class="fas fa-birthday-cake"></i> Age: ${age} years old (Valid)`;
                ageDisplay.className = 'age-display valid-age';
                dobInput.classList.remove('invalid-date');
                dobInput.classList.add('valid-date');
                if (ageRequirement) {
                    ageRequirement.style.color = '#43e97b';
                    ageRequirement.innerHTML = '<i class="fas fa-check-circle"></i> Age requirement satisfied (15 years or above)';
                }
            } else {
                ageDisplay.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Age: ${age} years old (Underage)`;
                ageDisplay.className = 'age-display invalid-age';
                dobInput.classList.remove('valid-date');
                dobInput.classList.add('invalid-date');
                if (ageRequirement) {
                    ageRequirement.style.color = '#d33';
                    ageRequirement.innerHTML = '<i class="fas fa-times-circle"></i> You must be at least 15 years old to register!';
                }
            }
        } else {
            ageDisplay.innerHTML = '';
            dobInput.classList.remove('valid-date', 'invalid-date');
            if (ageRequirement) {
                ageRequirement.style.color = '#666';
                ageRequirement.innerHTML = '<i class="fas fa-info-circle"></i> Must be 15 years old or above to register';
            }
        }
    }
    
    function validateStep2() {
        const dob = document.getElementById('date_of_birth').value;
        const gender = document.getElementById('gender').value;
        const phoneValid = validatePhoneNumber();
        
        if (!dob) {
            Swal.fire('Error', 'Please enter your date of birth', 'error');
            return false;
        }
        if (!gender) {
            Swal.fire('Error', 'Please select your gender', 'error');
            return false;
        }
        if (!phoneValid) {
            Swal.fire('Error', 'Please enter a valid phone number (10-13 digits)', 'error');
            return false;
        }
        
        const birthDate = new Date(dob);
        const today = new Date();
        let age = today.getFullYear() - birthDate.getFullYear();
        const monthDiff = today.getMonth() - birthDate.getMonth();
        
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) age--;
        
        if (age < 15) {
            Swal.fire('Error', 'You must be at least 15 years old to register', 'error');
            return false;
        }
        
        return true;
    }
    
    function validateStep2AndNext() {
        if (validateStep2()) {
            nextStep();
        }
    }
    
    function validateStep3() {
        const street = document.getElementById('street').value.trim();
        const barangay = document.getElementById('barangay').value.trim();
        const municipality = document.getElementById('municipality').value.trim();
        const province = document.getElementById('province').value.trim();
        
        if (!street) {
            Swal.fire('Error', 'Please enter your street or subdivision', 'error');
            return false;
        }
        if (!barangay) {
            Swal.fire('Error', 'Please enter your barangay', 'error');
            return false;
        }
        if (!municipality) {
            Swal.fire('Error', 'Please enter your municipality or city', 'error');
            return false;
        }
        if (!province) {
            Swal.fire('Error', 'Please enter your province', 'error');
            return false;
        }
        
        return true;
    }
    
    function validateStep3AndNext() {
        if (validateStep3()) {
            nextStep();
        }
    }
    
    function validateStep1Names() {
        const firstNameValid = validateRealName('first_name');
        const lastNameValid = validateRealName('last_name');
        
        if (!firstNameValid) {
            Swal.fire('Invalid First Name', 'Please enter a valid real first name.', 'error');
            return false;
        }
        if (!lastNameValid) {
            Swal.fire('Invalid Last Name', 'Please enter a valid real last name.', 'error');
            return false;
        }
        return true;
    }
    
    function validateAndNextStep1() {
        if (validateStep1Names()) {
            nextStep();
        }
    }
    
    function validateStep4() {
        const email = document.getElementById('email').value.trim();
        const username = document.getElementById('username').value.trim();
        const password = document.getElementById('password').value;
        
        if (!email) {
            Swal.fire('Error', 'Please enter your email address', 'error');
            return false;
        }
        if (!isValidEmail(email)) {
            Swal.fire('Error', 'Please enter a valid email address', 'error');
            return false;
        }
        if (!emailAvailable) {
            Swal.fire('Error', 'This email is already registered', 'error');
            return false;
        }
        
        if (!username) {
            Swal.fire('Error', 'Please choose a username', 'error');
            return false;
        }
        if (username.length < 3) {
            Swal.fire('Error', 'Username must be at least 3 characters long', 'error');
            return false;
        }
        if (!usernameAvailable) {
            Swal.fire('Error', 'This username is already taken', 'error');
            return false;
        }
        
        if (!password) {
            Swal.fire('Error', 'Please create a password', 'error');
            return false;
        }
        
        validatePassword();
        if (!isPasswordStrong) {
            Swal.fire({
                title: 'Weak Password',
                html: 'Your password must meet all requirements:<br>' +
                      '- At least 8 characters<br>' +
                      '- At least one uppercase letter<br>' +
                      '- At least one lowercase letter<br>' +
                      '- At least one number<br>' +
                      '- At least one special character (!@#$%^&*)',
                icon: 'error',
                confirmButtonColor: '#d33'
            });
            return false;
        }
        
        if (!checkPasswordMatch()) {
            Swal.fire('Error', 'Passwords do not match', 'error');
            return false;
        }
        
        return true;
    }
    
    function validateAndNext() {
        if (validateStep4()) {
            nextStep();
        }
    }
    
    function isValidEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }
    
    function nextStep() {
        const currentStep = parseInt(document.getElementById('current_step').value);
        let isValid = true;
        
        if (currentStep === 1) {
            isValid = validateStep1Names();
        } else if (currentStep === 2) {
            isValid = validateStep2();
        } else if (currentStep === 3) {
            isValid = validateStep3();
        } else if (currentStep === 4) {
            isValid = validateStep4();
        }
        
        if (isValid) {
            document.getElementById('action').value = 'next';
            document.getElementById('signupForm').submit();
        }
    }
    
    function prevStep() {
        document.getElementById('action').value = 'prev';
        document.getElementById('signupForm').submit();
    }
    
    function submitForm() {
        const scannedFile = document.getElementById('scanned_document').files[0];
        
        if (!scannedFile) {
            Swal.fire('Error', 'Please upload a scanned copy of your valid ID or document', 'error');
            return false;
        }
        
        if (scannedFile.type !== 'application/pdf' && !isImageClear) {
            Swal.fire('Error', 'The uploaded image is blurry or low quality. Please upload a clearer image.', 'error');
            return false;
        }
        
        document.getElementById('action').value = 'submit';
        document.getElementById('signupForm').submit();
    }
    
    const passwordField = document.getElementById('password');
    const confirmField = document.getElementById('confirm_password');
    if (passwordField && confirmField) {
        passwordField.addEventListener('input', function() {
            validatePassword();
            checkPasswordMatch();
        });
        confirmField.addEventListener('input', checkPasswordMatch);
    }
    
    // Add phone number validation on input
    const phoneField = document.getElementById('phone_number');
    if (phoneField) {
        phoneField.addEventListener('input', validatePhoneNumber);
    }
    
    window.addEventListener('load', function() {
        calculateAge();
        const firstName = document.getElementById('first_name');
        const lastName = document.getElementById('last_name');
        if (firstName && firstName.value) validateRealName('first_name');
        if (lastName && lastName.value) validateRealName('last_name');
        
        // Re-validate password if it exists
        const password = document.getElementById('password');
        if (password && password.value) {
            validatePassword();
        }
        
        // Validate phone number if it exists
        if (phoneField && phoneField.value) {
            validatePhoneNumber();
        }
    });
</script>
</body>
</html>