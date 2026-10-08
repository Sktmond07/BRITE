<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/email_helper.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

switch($action) {
    case 'get_admins':
        getAdmins($conn);
        break;
    case 'get_admin':
        getAdmin($conn);
        break;
    case 'add_admin':
        addAdmin($conn);
        break;
    case 'update_admin':
        updateAdmin($conn);
        break;
    case 'delete_admin':
        deleteAdmin($conn);
        break;
    case 'reset_admin_password':
        resetAdminPassword($conn);
        break;
    case 'toggle_admin_status':
        toggleAdminStatus($conn);
        break;
    case 'check_availability':
        checkAvailability($conn);
        break;
    case 'generate_qr':
    generateQRCodeForRequest($conn);
    break;
    case 'get_kagawad_members':
        getKagawadMembers($conn);
        break;
    case 'get_kagawad':
        getKagawad($conn);
        break;
    case 'add_kagawad':
        addKagawad($conn);
        break;
    case 'update_kagawad':
        updateKagawad($conn);
        break;
    case 'delete_kagawad':
        deleteKagawad($conn);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

// ============ KAGAWAD FUNCTIONS ============

function getKagawadMembers($conn) {
    $sql = "SELECT * FROM kagawad_members ORDER BY id ASC";
    $result = mysqli_query($conn, $sql);
    $members = [];
    
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $members[] = $row;
        }
    }
    
    echo json_encode(['success' => true, 'kagawad_members' => $members]);
}
function addKagawad($conn) {
    $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $suffix = isset($_POST['suffix']) ? trim($_POST['suffix']) : '';
    $committee = isset($_POST['committee']) ? trim($_POST['committee']) : '';
    $contact_number = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $birth_date = isset($_POST['birth_date']) && !empty($_POST['birth_date']) ? $_POST['birth_date'] : null;
    $birth_place = isset($_POST['birth_place']) ? trim($_POST['birth_place']) : '';
    $gender = isset($_POST['gender']) ? trim($_POST['gender']) : '';
    $civil_status = isset($_POST['civil_status']) ? trim($_POST['civil_status']) : '';
    $religion = isset($_POST['religion']) ? trim($_POST['religion']) : '';
    $occupation = isset($_POST['occupation']) ? trim($_POST['occupation']) : '';
    $is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;
    
    if (empty($full_name)) {
        echo json_encode(['success' => false, 'message' => 'Full name is required']);
        return;
    }
    
    $countResult = mysqli_query($conn, "SELECT COUNT(*) as count FROM kagawad_members");
    $currentCount = $countResult ? mysqli_fetch_assoc($countResult)['count'] : 0;
    
    if ($currentCount >= 7) {
        echo json_encode(['success' => false, 'message' => 'Maximum of 7 Kagawad positions already filled']);
        return;
    }
    
    $imagePath = null;
    if (isset($_FILES['kagawad_image']) && $_FILES['kagawad_image']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = uploadProfileImage($_FILES['kagawad_image'], 'kagawad');
        if ($uploadResult['success']) {
            $imagePath = $uploadResult['path'];
        } else {
            echo json_encode(['success' => false, 'message' => $uploadResult['message']]);
            return;
        }
    }
    
    $full_name = mysqli_real_escape_string($conn, $full_name);
    $suffix = mysqli_real_escape_string($conn, $suffix);
    $committee = mysqli_real_escape_string($conn, $committee);
    $contact_number = mysqli_real_escape_string($conn, $contact_number);
    $email = mysqli_real_escape_string($conn, $email);
    $birth_place = mysqli_real_escape_string($conn, $birth_place);
    $gender = mysqli_real_escape_string($conn, $gender);
    $civil_status = mysqli_real_escape_string($conn, $civil_status);
    $religion = mysqli_real_escape_string($conn, $religion);
    $occupation = mysqli_real_escape_string($conn, $occupation);
    $imagePathEsc = $imagePath ? mysqli_real_escape_string($conn, $imagePath) : null;
    $birthDateSql = $birth_date ? "'" . mysqli_real_escape_string($conn, $birth_date) . "'" : "NULL";
    $imageSql = $imagePathEsc ? "'$imagePathEsc'" : "NULL";
    
    $sql = "INSERT INTO kagawad_members 
            (full_name, suffix, committee, contact_number, email, birth_date, birth_place, 
             gender, civil_status, religion, occupation, is_active, profile_image, created_at, updated_at) 
            VALUES 
            ('$full_name', '$suffix', '$committee', '$contact_number', '$email', $birthDateSql, '$birth_place',
             '$gender', '$civil_status', '$religion', '$occupation', $is_active, $imageSql, NOW(), NOW())";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Kagawad added successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add Kagawad: ' . mysqli_error($conn)]);
    }
}
function updateKagawad($conn) {
    $id = intval($_POST['id']);
    $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $suffix = isset($_POST['suffix']) ? trim($_POST['suffix']) : '';
    $committee = isset($_POST['committee']) ? trim($_POST['committee']) : '';
    $contact_number = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $birth_date = isset($_POST['birth_date']) && !empty($_POST['birth_date']) ? $_POST['birth_date'] : null;
    $birth_place = isset($_POST['birth_place']) ? trim($_POST['birth_place']) : '';
    $gender = isset($_POST['gender']) ? trim($_POST['gender']) : '';
    $civil_status = isset($_POST['civil_status']) ? trim($_POST['civil_status']) : '';
    $religion = isset($_POST['religion']) ? trim($_POST['religion']) : '';
    $occupation = isset($_POST['occupation']) ? trim($_POST['occupation']) : '';
    $is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;
    
    if (empty($full_name)) {
        echo json_encode(['success' => false, 'message' => 'Full name is required']);
        return;
    }
    
    $full_name = mysqli_real_escape_string($conn, $full_name);
    $suffix = mysqli_real_escape_string($conn, $suffix);
    $committee = mysqli_real_escape_string($conn, $committee);
    $contact_number = mysqli_real_escape_string($conn, $contact_number);
    $email = mysqli_real_escape_string($conn, $email);
    $birth_place = mysqli_real_escape_string($conn, $birth_place);
    $gender = mysqli_real_escape_string($conn, $gender);
    $civil_status = mysqli_real_escape_string($conn, $civil_status);
    $religion = mysqli_real_escape_string($conn, $religion);
    $occupation = mysqli_real_escape_string($conn, $occupation);
    $birthDateSql = $birth_date ? "'" . mysqli_real_escape_string($conn, $birth_date) . "'" : "NULL";
    
    $imageUpdate = "";
    
    if (isset($_POST['remove_image']) && $_POST['remove_image'] === 'true') {
        $currentSql = "SELECT profile_image FROM kagawad_members WHERE id = $id";
        $currentResult = mysqli_query($conn, $currentSql);
        if ($currentResult && $row = mysqli_fetch_assoc($currentResult)) {
            if ($row['profile_image'] && file_exists(__DIR__ . '/../' . $row['profile_image'])) {
                unlink(__DIR__ . '/../' . $row['profile_image']);
            }
        }
        $imageUpdate = ", profile_image = NULL";
    }
    
    if (isset($_FILES['kagawad_image']) && $_FILES['kagawad_image']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = uploadProfileImage($_FILES['kagawad_image'], 'kagawad');
        if ($uploadResult['success']) {
            $currentSql = "SELECT profile_image FROM kagawad_members WHERE id = $id";
            $currentResult = mysqli_query($conn, $currentSql);
            if ($currentResult && $row = mysqli_fetch_assoc($currentResult)) {
                if ($row['profile_image'] && file_exists(__DIR__ . '/../' . $row['profile_image'])) {
                    unlink(__DIR__ . '/../' . $row['profile_image']);
                }
            }
            $newImagePath = mysqli_real_escape_string($conn, $uploadResult['path']);
            $imageUpdate = ", profile_image = '$newImagePath'";
        } else {
            echo json_encode(['success' => false, 'message' => $uploadResult['message']]);
            return;
        }
    }
    
    $sql = "UPDATE kagawad_members SET 
            full_name = '$full_name', 
            suffix = '$suffix',
            committee = '$committee', 
            contact_number = '$contact_number',
            email = '$email',
            birth_date = $birthDateSql,
            birth_place = '$birth_place',
            gender = '$gender',
            civil_status = '$civil_status',
            religion = '$religion',
            occupation = '$occupation',
            is_active = $is_active
            $imageUpdate,
            updated_at = NOW() 
            WHERE id = $id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Kagawad updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update Kagawad: ' . mysqli_error($conn)]);
    }
}
function getKagawad($conn) {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid Kagawad ID']);
        return;
    }
    
    $sql = "SELECT * FROM kagawad_members WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result && $row = mysqli_fetch_assoc($result)) {
        echo json_encode(['success' => true, 'kagawad' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Kagawad not found']);
    }
}

function deleteKagawad($conn) {
    $id = intval($_POST['id']);
    
    // Get image to delete
    $currentSql = "SELECT profile_image FROM kagawad_members WHERE id = $id";
    $currentResult = mysqli_query($conn, $currentSql);
    if ($currentResult && $row = mysqli_fetch_assoc($currentResult)) {
        if ($row['profile_image'] && file_exists(__DIR__ . '/../' . $row['profile_image'])) {
            unlink(__DIR__ . '/../' . $row['profile_image']);
        }
    }
    
    $sql = "DELETE FROM kagawad_members WHERE id = $id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Kagawad deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete Kagawad: ' . mysqli_error($conn)]);
    }
}

// ============ IMAGE UPLOAD HELPER ============

function uploadProfileImage($file, $prefix = 'profile') {
    $targetDir = __DIR__ . '/../uploads/profiles/';
    if (!file_exists($targetDir)) mkdir($targetDir, 0777, true);
    
    $fileName = $prefix . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($file['name']));
    $targetFile = $targetDir . $fileName;
    $imageFileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
    
    $check = getimagesize($file['tmp_name']);
    if ($check === false) return ['success' => false, 'message' => 'File is not an image.'];
    if ($file['size'] > 5000000) return ['success' => false, 'message' => 'File is too large. Max 5MB.'];
    
    $allowedFormats = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($imageFileType, $allowedFormats)) return ['success' => false, 'message' => 'Only JPG, JPEG, PNG, GIF & WEBP files are allowed.'];
    
    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        return ['success' => true, 'path' => 'uploads/profiles/' . $fileName];
    }
    return ['success' => false, 'message' => 'Failed to upload image.'];
}

// Update addAdmin to handle profile image
// Find the addAdmin function and add image handling before the INSERT


function generateQRCodeForRequest($conn) {
    $request_id = intval($_POST['request_id'] ?? 0);
    
    if ($request_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
        return;
    }
    
    // Check if qrcode library exists
    $qrLibPath = __DIR__ . '/qrcode/phpqrcode.php';
    if (!file_exists($qrLibPath)) {
        echo json_encode(['success' => false, 'message' => 'QR library not found at: ' . $qrLibPath]);
        return;
    }
    
    require_once $qrLibPath;
    
    // Get request details
    $sql = "SELECT dr.*, r.first_name, r.last_name, r.email 
            FROM document_requests dr 
            JOIN resident r ON dr.resident_id = r.id 
            WHERE dr.id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $request_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $request = mysqli_fetch_assoc($result);
    
    if (!$request) {
        echo json_encode(['success' => false, 'message' => 'Request not found']);
        return;
    }
    
    // Create QR code directory
    $qrDir = __DIR__ . '/../generated_qrcodes/';
    if (!file_exists($qrDir)) {
        mkdir($qrDir, 0777, true);
    }
    
    // Generate verification code
    $verificationCode = md5($request_id . $request['email'] . time() . uniqid());
    
    $qrPayload = json_encode([
        'request_id' => $request_id,
        'document_type' => $request['document_type'],
        'resident_name' => $request['first_name'] . ' ' . $request['last_name'],
        'resident_email' => $request['email'],
        'issue_date' => date('Y-m-d H:i:s'),
        'verification_code' => $verificationCode,
        'verified' => false
    ]);
    
    $qrFilename = 'qr_document_' . $request_id . '_' . time() . '.png';
    $qrPath = $qrDir . $qrFilename;
    
    QRcode::png($qrPayload, $qrPath, QR_ECLEVEL_H, 10, 2);
    
    if (file_exists($qrPath) && filesize($qrPath) > 0) {
        $relativePath = 'generated_qrcodes/' . $qrFilename;
        $updateSql = "UPDATE document_requests SET qr_code_path = ?, qr_verification_code = ? WHERE id = ?";
        $updateStmt = mysqli_prepare($conn, $updateSql);
        mysqli_stmt_bind_param($updateStmt, "ssi", $relativePath, $verificationCode, $request_id);
        
        if (mysqli_stmt_execute($updateStmt)) {
            echo json_encode([
                'success' => true, 
                'message' => 'QR Code generated successfully',
                'qr_path' => $relativePath,
                'verification_code' => $verificationCode
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update database']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to generate QR code']);
    }
}


function getAdmins($conn) {
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $perPage = 12;
    $offset = ($page - 1) * $perPage;
    
    $countSql = "SELECT COUNT(*) as total FROM admin WHERE id != 1";
    $countResult = mysqli_query($conn, $countSql);
    $totalCount = $countResult ? mysqli_fetch_assoc($countResult)['total'] : 0;
    $totalPages = ceil($totalCount / $perPage);
    
    $sql = "SELECT id, username, email, full_name, admin_role, is_active, last_login, created_at, 
                   phone_number, birth_date, birth_place, gender, civil_status, religion, occupation, suffix
            FROM admin 
            WHERE id != 1
            ORDER BY 
                CASE admin_role 
                    WHEN 'captain' THEN 1
                    WHEN 'secretary' THEN 2
                    WHEN 'kagawad' THEN 3
                    WHEN 'lupon' THEN 4
                    WHEN 'super_admin' THEN 5
                    ELSE 6
                END,
                full_name ASC
            LIMIT $offset, $perPage";
    
    $result = mysqli_query($conn, $sql);
    $admins = [];
    
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            unset($row['password']);
            $admins[] = $row;
        }
    }
    
    echo json_encode([
        'success' => true,
        'admins' => $admins,
        'totalPages' => $totalPages,
        'totalCount' => $totalCount,
        'currentPage' => $page
    ]);
}
function getAdmin($conn) {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid admin ID']);
        return;
    }
    
    $sql = "SELECT id, username, email, full_name, admin_role, is_active, phone_number, 
                   birth_date, birth_place, gender, civil_status, religion, occupation, suffix, profile_image
            FROM admin WHERE id = ?";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result && $row = mysqli_fetch_assoc($result)) {
        unset($row['password']);
        echo json_encode(['success' => true, 'admin' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Admin not found']);
    }
}

function checkAvailability($conn) {
    $username = isset($_GET['username']) ? trim($_GET['username']) : '';
    $email = isset($_GET['email']) ? trim($_GET['email']) : '';
    $excludeId = isset($_GET['exclude_id']) ? intval($_GET['exclude_id']) : 0;
    
    if (!empty($username)) {
        $sql = "SELECT id FROM admin WHERE username = '$username'";
        if ($excludeId > 0) $sql .= " AND id != $excludeId";
        $result = mysqli_query($conn, $sql);
        if ($result && mysqli_num_rows($result) > 0) {
            echo json_encode(['available' => false, 'message' => 'Username already exists']);
            return;
        }
        
        $sql = "SELECT id FROM resident WHERE username = '$username'";
        $result = mysqli_query($conn, $sql);
        if ($result && mysqli_num_rows($result) > 0) {
            echo json_encode(['available' => false, 'message' => 'Username already exists in resident accounts']);
            return;
        }
    }
    
    if (!empty($email)) {
        $sql = "SELECT id FROM admin WHERE email = '$email'";
        if ($excludeId > 0) $sql .= " AND id != $excludeId";
        $result = mysqli_query($conn, $sql);
        if ($result && mysqli_num_rows($result) > 0) {
            echo json_encode(['available' => false, 'message' => 'Email already exists']);
            return;
        }
        
        $sql = "SELECT id FROM resident WHERE email = '$email'";
        $result = mysqli_query($conn, $sql);
        if ($result && mysqli_num_rows($result) > 0) {
            echo json_encode(['available' => false, 'message' => 'Email already exists in resident accounts']);
            return;
        }
    }
    
    echo json_encode(['available' => true, 'message' => '']);
}

function generateUsername($fullName) {
    // Parse full name: "DELA CRUZ, JUAN M." or "JUAN M. DELA CRUZ"
    $nameParts = [];
    
    if (strpos($fullName, ',') !== false) {
        // Format: LASTNAME, FIRSTNAME MI.
        $parts = explode(',', $fullName);
        $lastName = trim($parts[0]);
        $firstPart = isset($parts[1]) ? trim($parts[1]) : '';
        
        // Extract first name (first word after comma)
        $firstNameWords = explode(' ', $firstPart);
        $firstName = $firstNameWords[0];
    } else {
        // Format: FIRSTNAME LASTNAME
        $nameWords = explode(' ', trim($fullName));
        if (count($nameWords) >= 2) {
            $firstName = $nameWords[0];
            $lastName = end($nameWords);
        } else {
            $firstName = $nameWords[0];
            $lastName = '';
        }
    }
    
    // Clean names (remove special characters)
    $firstName = strtolower(preg_replace('/[^a-z]/i', '', $firstName));
    $lastName = strtolower(preg_replace('/[^a-z]/i', '', $lastName));
    
    if ($firstName && $lastName) {
        $baseUsername = $firstName . '.' . $lastName;
    } else if ($firstName) {
        $baseUsername = $firstName;
    } else {
        $baseUsername = 'user';
    }
    
    $randomNum = rand(100, 999);
    $username = $baseUsername . $randomNum;
    
    if (strlen($username) > 30) {
        $username = substr($username, 0, 30);
    }
    
    return $username;
}

function generateStrongPassword() {
    $length = 10;
    $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lowercase = 'abcdefghijkmnpqrstuvwxyz';
    $numbers = '23456789';
    
    $password = '';
    $password .= $uppercase[rand(0, strlen($uppercase) - 1)];
    $password .= $lowercase[rand(0, strlen($lowercase) - 1)];
    $password .= $numbers[rand(0, strlen($numbers) - 1)];
    
    $allChars = $uppercase . $lowercase . $numbers;
    for ($i = strlen($password); $i < $length; $i++) {
        $password .= $allChars[rand(0, strlen($allChars) - 1)];
    }
    
    return str_shuffle($password);
}

function sendAdminAccountEmail($toEmail, $fullName, $username, $password, $adminRole) {
    $roleDisplay = '';
    switch($adminRole) {
        case 'captain': $roleDisplay = 'Barangay Captain'; break;
        case 'secretary': $roleDisplay = 'Secretary'; break;
        case 'kagawad': $roleDisplay = 'Kagawad'; break;
        case 'lupon': $roleDisplay = 'Lupon Member'; break;
        case 'super_admin': $roleDisplay = 'Super Administrator'; break;
        default: $roleDisplay = 'Staff Member';
    }
    
    $subject = "Welcome to Barangay System - Your Account Credentials";
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #1a472a, #2b9b54); color: white; padding: 20px; text-align: center; }
            .content { background: #f9f9f9; padding: 30px; border: 1px solid #ddd; }
            .credentials { background: #e8f5e9; padding: 15px; border-radius: 8px; margin: 20px 0; }
            .credential-item { margin: 10px 0; font-family: monospace; font-size: 14px; }
            .label { font-weight: bold; color: #1a472a; }
            .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Barangay San Bartolome</h2>
                <p>Official/Staff Account Created</p>
            </div>
            <div class='content'>
                <p>Dear <strong>" . htmlspecialchars($fullName) . "</strong>,</p>
                <p>You have been appointed as <strong>" . htmlspecialchars($roleDisplay) . "</strong> in Barangay San Bartolome, Sto Tomas, Pampanga.</p>
                <p>Your account has been created. Below are your login credentials:</p>
                
                <div class='credentials'>
                    <div class='credential-item'><span class='label'>Username:</span> " . htmlspecialchars($username) . "</div>
                    <div class='credential-item'><span class='label'>Password:</span> " . htmlspecialchars($password) . "</div>
                    <div class='credential-item'><span class='label'>Role:</span> " . htmlspecialchars($roleDisplay) . "</div>
                </div>
                
                <p><strong>Important:</strong> For security reasons, please change your password after your first login.</p>
                <p>You can access the admin dashboard at: <a href='" . getBaseUrl() . "/admin/dashboard.php'>Admin Dashboard</a></p>
                
                <p>Best regards,<br>
                <strong>Barangay San Bartolome Administration</strong></p>
            </div>
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.</p>
                <p>&copy; " . date('Y') . " Barangay San Bartolome. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>";
    
    return sendEmail($toEmail, $fullName, $subject, $body);
}

function sendPasswordResetEmail($toEmail, $fullName, $username, $newPassword) {
    $subject = "Barangay System - Password Reset";
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #1a472a, #2b9b54); color: white; padding: 20px; text-align: center; }
            .content { background: #f9f9f9; padding: 30px; border: 1px solid #ddd; }
            .credentials { background: #e8f5e9; padding: 15px; border-radius: 8px; margin: 20px 0; }
            .credential-item { margin: 10px 0; font-family: monospace; font-size: 14px; }
            .label { font-weight: bold; color: #1a472a; }
            .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Barangay San Bartolome</h2>
                <p>Password Reset Confirmation</p>
            </div>
            <div class='content'>
                <p>Dear <strong>" . htmlspecialchars($fullName) . "</strong>,</p>
                <p>Your account password has been reset by the administrator.</p>
                <p>Your new login credentials are:</p>
                
                <div class='credentials'>
                    <div class='credential-item'><span class='label'>Username:</span> " . htmlspecialchars($username) . "</div>
                    <div class='credential-item'><span class='label'>New Password:</span> " . htmlspecialchars($newPassword) . "</div>
                </div>
                
                <p><strong>Important:</strong> Please change your password after logging in for security purposes.</p>
                <p>If you did not request this password reset, please contact the administrator immediately.</p>
                
                <p>Best regards,<br>
                <strong>Barangay San Bartolome Administration</strong></p>
            </div>
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>";
    
    return sendEmail($toEmail, $fullName, $subject, $body);
}

function sendEmail($toEmail, $toName, $subject, $htmlBody) {
    require_once __DIR__ . '/../vendor/autoload.php';
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        $mail->SMTPDebug = PHPMailer\PHPMailer\SMTP::DEBUG_OFF;
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'guevarraraymond10@gmail.com';
        $mail->Password   = 'imsv dvyk jmti lmys';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        
        $mail->setFrom('noreply@barangay.com', 'Barangay System');
        $mail->addAddress($toEmail, $toName);
        
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email could not be sent. Error: {$mail->ErrorInfo}");
        return false;
    }
}

function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    return $protocol . '://' . $host;
}

function addAdmin($conn) {
    $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $admin_role = isset($_POST['admin_role']) ? trim($_POST['admin_role']) : '';
    $suffix = isset($_POST['suffix']) ? trim($_POST['suffix']) : '';
    $phone_number = isset($_POST['phone_number']) ? trim($_POST['phone_number']) : '';
    $birth_date = isset($_POST['birth_date']) && !empty($_POST['birth_date']) ? $_POST['birth_date'] : null;
    $birth_place = isset($_POST['birth_place']) ? trim($_POST['birth_place']) : '';
    $gender = isset($_POST['gender']) ? trim($_POST['gender']) : '';
    $civil_status = isset($_POST['civil_status']) ? trim($_POST['civil_status']) : '';
    $religion = isset($_POST['religion']) ? trim($_POST['religion']) : '';
    $occupation = isset($_POST['occupation']) ? trim($_POST['occupation']) : '';
    $is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;
    
    // Validate required fields
    if (empty($full_name)) {
        echo json_encode(['success' => false, 'message' => 'Full name is required']);
        return;
    }
    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Email is required']);
        return;
    }
    if (empty($admin_role)) {
        echo json_encode(['success' => false, 'message' => 'Role is required']);
        return;
    }
    
    // Check Lupon member limit (10-20 members)
    if ($admin_role === 'lupon') {
        $countSql = "SELECT COUNT(*) as count FROM admin WHERE admin_role = 'lupon' AND is_active = 1";
        $countResult = mysqli_query($conn, $countSql);
        $currentLuponCount = $countResult ? mysqli_fetch_assoc($countResult)['count'] : 0;
        
        if ($currentLuponCount >= 20) {
            echo json_encode(['success' => false, 'message' => 'Maximum of 20 Lupon members already reached.']);
            return;
        }
    }
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        return;
    }
    
    // Validate phone number (if provided)
    if (!empty($phone_number)) {
        $phone_number = preg_replace('/[^0-9]/', '', $phone_number);
        if (!preg_match('/^[0-9]{10,11}$/', $phone_number)) {
            echo json_encode(['success' => false, 'message' => 'Phone number must contain 10-11 digits only']);
            return;
        }
    }
    
    // Generate username and password
    $username = generateUsername($full_name);
    $password = generateStrongPassword();
    
    // Check if generated username exists
    $counter = 1;
    $checkSql = "SELECT id FROM admin WHERE username = '$username' UNION SELECT id FROM resident WHERE username = '$username'";
    $checkResult = mysqli_query($conn, $checkSql);
    while ($checkResult && mysqli_num_rows($checkResult) > 0) {
        $username = generateUsername($full_name) . $counter;
        $checkSql = "SELECT id FROM admin WHERE username = '$username' UNION SELECT id FROM resident WHERE username = '$username'";
        $checkResult = mysqli_query($conn, $checkSql);
        $counter++;
    }
    
    // Check if email exists
    $checkSql = "SELECT id FROM admin WHERE email = '$email' UNION SELECT id FROM resident WHERE email = '$email'";
    $checkResult = mysqli_query($conn, $checkSql);
    if ($checkResult && mysqli_num_rows($checkResult) > 0) {
        echo json_encode(['success' => false, 'message' => 'Email already exists']);
        return;
    }
    
    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Escape strings
    $username = mysqli_real_escape_string($conn, $username);
    $email = mysqli_real_escape_string($conn, $email);
    $full_name = mysqli_real_escape_string($conn, $full_name);
    $admin_role = mysqli_real_escape_string($conn, $admin_role);
    $suffix = mysqli_real_escape_string($conn, $suffix);
    $phone_number = mysqli_real_escape_string($conn, $phone_number);
    $birth_place = mysqli_real_escape_string($conn, $birth_place);
    $gender = mysqli_real_escape_string($conn, $gender);
    $civil_status = mysqli_real_escape_string($conn, $civil_status);
    $religion = mysqli_real_escape_string($conn, $religion);
    $occupation = mysqli_real_escape_string($conn, $occupation);
    
    // Build the SQL query
    $sql = "INSERT INTO admin (username, email, password, full_name, admin_role, suffix, 
                               phone_number, birth_date, birth_place, gender, civil_status, religion, 
                               occupation, is_active, created_at, updated_at) 
            VALUES ('$username', '$email', '$hashed_password', '$full_name', '$admin_role', '$suffix', 
                    '$phone_number', " . ($birth_date ? "'$birth_date'" : "NULL") . ", 
                    '$birth_place', '$gender', '$civil_status', '$religion', '$occupation', 
                    $is_active, NOW(), NOW())";
    
    if (mysqli_query($conn, $sql)) {
        // Send email with credentials
        $emailSent = sendAdminAccountEmail($email, $full_name, $username, $password, $admin_role);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Account created successfully',
            'email_sent' => $emailSent
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create account: ' . mysqli_error($conn)]);
    }
}

function updateAdmin($conn) {
    $id = intval($_POST['id']);
    $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $suffix = isset($_POST['suffix']) ? trim($_POST['suffix']) : '';
    $phone_number = isset($_POST['phone_number']) ? trim($_POST['phone_number']) : '';
    $birth_date = isset($_POST['birth_date']) && !empty($_POST['birth_date']) ? $_POST['birth_date'] : null;
    $birth_place = isset($_POST['birth_place']) ? trim($_POST['birth_place']) : '';
    $gender = isset($_POST['gender']) ? trim($_POST['gender']) : '';
    $civil_status = isset($_POST['civil_status']) ? trim($_POST['civil_status']) : '';
    $religion = isset($_POST['religion']) ? trim($_POST['religion']) : '';
    $occupation = isset($_POST['occupation']) ? trim($_POST['occupation']) : '';
    $is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;
    
    // Validate required fields
    if (empty($full_name)) {
        echo json_encode(['success' => false, 'message' => 'Full name is required']);
        return;
    }
    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Email is required']);
        return;
    }
    
    // Validate phone number
    if (!empty($phone_number)) {
        $phone_number = preg_replace('/[^0-9]/', '', $phone_number);
        if (!preg_match('/^[0-9]{10,11}$/', $phone_number)) {
            echo json_encode(['success' => false, 'message' => 'Phone number must contain 10-11 digits only']);
            return;
        }
    }
    
    // Check if email exists in other accounts
    $checkSql = "SELECT id FROM admin WHERE email = '$email' AND id != $id 
                 UNION SELECT id FROM resident WHERE email = '$email'";
    $checkResult = mysqli_query($conn, $checkSql);
    if ($checkResult && mysqli_num_rows($checkResult) > 0) {
        echo json_encode(['success' => false, 'message' => 'Email already exists']);
        return;
    }
    
    // Get current profile image
    $currentImageSql = "SELECT profile_image FROM admin WHERE id = $id";
    $currentImageResult = mysqli_query($conn, $currentImageSql);
    $currentImage = $currentImageResult ? mysqli_fetch_assoc($currentImageResult)['profile_image'] : null;
    
    // Handle image
    $imageUpdate = "";
    
    // Remove image
    if (isset($_POST['remove_image']) && $_POST['remove_image'] === 'true') {
        if ($currentImage && file_exists(__DIR__ . '/../' . $currentImage)) {
            unlink(__DIR__ . '/../' . $currentImage);
        }
        $imageUpdate = ", profile_image = NULL";
    }
    
    // Upload new image
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = uploadProfileImage($_FILES['profile_image'], 'admin');
        if ($uploadResult['success']) {
            // Delete old image
            if ($currentImage && file_exists(__DIR__ . '/../' . $currentImage)) {
                unlink(__DIR__ . '/../' . $currentImage);
            }
            $newImagePath = mysqli_real_escape_string($conn, $uploadResult['path']);
            $imageUpdate = ", profile_image = '$newImagePath'";
        } else {
            echo json_encode(['success' => false, 'message' => $uploadResult['message']]);
            return;
        }
    }
    
    // Escape strings
    $email = mysqli_real_escape_string($conn, $email);
    $full_name = mysqli_real_escape_string($conn, $full_name);
    $suffix = mysqli_real_escape_string($conn, $suffix);
    $phone_number = mysqli_real_escape_string($conn, $phone_number);
    $birth_place = mysqli_real_escape_string($conn, $birth_place);
    $gender = mysqli_real_escape_string($conn, $gender);
    $civil_status = mysqli_real_escape_string($conn, $civil_status);
    $religion = mysqli_real_escape_string($conn, $religion);
    $occupation = mysqli_real_escape_string($conn, $occupation);
    
    // Build SQL - REMOVED password update
    $sql = "UPDATE admin SET 
            email = '$email', 
            full_name = '$full_name', 
            suffix = '$suffix', 
            phone_number = '$phone_number', 
            birth_date = " . ($birth_date ? "'$birth_date'" : "NULL") . ",
            birth_place = '$birth_place', 
            gender = '$gender', 
            civil_status = '$civil_status', 
            religion = '$religion', 
            occupation = '$occupation',
            is_active = $is_active
            $imageUpdate,
            updated_at = NOW()
            WHERE id = $id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Account updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update account: ' . mysqli_error($conn)]);
    }
}
function deleteAdmin($conn) {
    $id = intval($_POST['id']);
    
    if ($id == 1) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete the main super admin account']);
        return;
    }
    
    $sql = "DELETE FROM admin WHERE id = $id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Account deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete account: ' . mysqli_error($conn)]);
    }
}

function resetAdminPassword($conn) {
    $id = intval($_POST['id']);
    $newPassword = generateStrongPassword();
    
    // Get user details
    $userSql = "SELECT username, email, full_name FROM admin WHERE id = $id";
    $userResult = mysqli_query($conn, $userSql);
    if (!$userResult || mysqli_num_rows($userResult) == 0) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        return;
    }
    $user = mysqli_fetch_assoc($userResult);
    
    $hashed_password = password_hash($newPassword, PASSWORD_DEFAULT);
    $hashed_password = mysqli_real_escape_string($conn, $hashed_password);
    
    $sql = "UPDATE admin SET password = '$hashed_password', updated_at = NOW() WHERE id = $id";
    
    if (mysqli_query($conn, $sql)) {
        $emailSent = sendPasswordResetEmail($user['email'], $user['full_name'], $user['username'], $newPassword);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Password reset successfully',
            'email_sent' => $emailSent
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to reset password: ' . mysqli_error($conn)]);
    }
}

function toggleAdminStatus($conn) {
    $id = intval($_POST['id']);
    $status = intval($_POST['status']);
    
    if ($id == 1 && $status == 0) {
        echo json_encode(['success' => false, 'message' => 'Cannot deactivate the main super admin account']);
        return;
    }
    
    $sql = "UPDATE admin SET is_active = $status, updated_at = NOW() WHERE id = $id";
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update status: ' . mysqli_error($conn)]);
    }
}
?>