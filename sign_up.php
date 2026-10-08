<?php
session_start();
require 'config/database.php';

// ============================================================
// CONFIGURATION
// ============================================================
define('OCR_DEBUG', true);
define('MIN_IMAGE_WIDTH', 300);
define('MIN_IMAGE_HEIGHT', 200);
define('MAX_FILE_SIZE', 5 * 1024 * 1024);

// OCR.space API Key
define('OCR_SPACE_API_KEY', 'K85569526888957');

$error_message = '';
$success_message = '';
$current_step = 1;
$form_data = $_SESSION['signup_data'] ?? [];
$ocr_data = $_SESSION['ocr_data'] ?? [];
$resident_match = $_SESSION['resident_match'] ?? [];
$verification_status = $_SESSION['verification_status'] ?? [];
$selected_id_type = $_SESSION['selected_id_type'] ?? '';
$detected_id_type = $_SESSION['detected_id_type'] ?? '';
$id_verified = $_SESSION['id_verified'] ?? false;
$verification_errors = $_SESSION['verification_errors'] ?? [];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['signup_data'])) {
        $_SESSION['signup_data'] = [];
    }
    
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $current_step = isset($_POST['current_step']) ? (int)$_POST['current_step'] : 1;
    
    $post_data = $_POST;
    unset($post_data['action']);
    unset($post_data['current_step']);
    unset($post_data['submit_final']);
    unset($post_data['front_image_data']);
    unset($post_data['back_image_data']);
    
    if (!empty($post_data)) {
        foreach ($post_data as $key => $value) {
            if (!empty($value) || $key === 'gender') {
                $_SESSION['signup_data'][$key] = $value;
            }
        }
    }
    $form_data = $_SESSION['signup_data'];
    
    if ($action === 'next') {
        if ($current_step == 2 && !$id_verified) {
            $error_message = '⚠️ Please verify your ID first before proceeding.';
        } else {
            $current_step++;
            if ($current_step > 5) $current_step = 5;
            $_SESSION['signup_data']['current_step'] = $current_step;
            
            if ($current_step == 3 && !empty($_SESSION['ocr_data'])) {
                $ocr_fields = ['last_name', 'first_name', 'middle_name', 'date_of_birth', 'address'];
                foreach ($ocr_fields as $field) {
                    if (!empty($_SESSION['ocr_data'][$field]) && empty($_SESSION['signup_data'][$field])) {
                        $_SESSION['signup_data'][$field] = $_SESSION['ocr_data'][$field];
                    }
                }
                if (!empty($_SESSION['ocr_data']['sex']) && empty($_SESSION['signup_data']['gender'])) {
                    $_SESSION['signup_data']['gender'] = $_SESSION['ocr_data']['sex'];
                }
            }
            $form_data = $_SESSION['signup_data'];
        }
    } elseif ($action === 'prev') {
        $current_step--;
        if ($current_step < 1) $current_step = 1;
        $_SESSION['signup_data']['current_step'] = $current_step;
    } elseif ($action === 'process_id') {
        $_SESSION['verification_errors'] = [];
        $verification_errors = [];
        
        $_SESSION['id_verified'] = false;
        $id_verified = false;
        
        $result = processIDWithOCRSpace();
        
        if ($result['success']) {
            $_SESSION['ocr_data'] = $result['data'];
            $ocr_data = $_SESSION['ocr_data'];
            
            $_SESSION['detected_id_type'] = $result['detected_type'] ?? '';
            $detected_id_type = $_SESSION['detected_id_type'];
            
            if (!empty($ocr_data['last_name'])) $_SESSION['signup_data']['last_name'] = $ocr_data['last_name'];
            if (!empty($ocr_data['first_name'])) $_SESSION['signup_data']['first_name'] = $ocr_data['first_name'];
            if (!empty($ocr_data['middle_name'])) $_SESSION['signup_data']['middle_name'] = $ocr_data['middle_name'];
            if (!empty($ocr_data['date_of_birth'])) $_SESSION['signup_data']['date_of_birth'] = $ocr_data['date_of_birth'];
            if (!empty($ocr_data['sex'])) $_SESSION['signup_data']['gender'] = $ocr_data['sex'];
            if (!empty($ocr_data['address'])) $_SESSION['signup_data']['address'] = $ocr_data['address'];
            if (!empty($ocr_data['id_number'])) $_SESSION['signup_data']['id_number'] = $ocr_data['id_number'];
            
            $form_data = $_SESSION['signup_data'];
            
            $verifyResult = verifyResidentInMasterlist($form_data, $ocr_data);
            
            if ($verifyResult['success']) {
                $accountCheck = checkExistingAccount($form_data);
                
                if ($accountCheck['exists']) {
                    $error_message = '
                        <div style="background:#fff3cd; padding:15px; border-radius:10px; border-left:4px solid #ffc107;">
                            <h4 style="color:#856404; margin:0 0 10px 0;">
                                <i class="fas fa-info-circle"></i> 
                                Account Already Exists
                            </h4>
                            <p style="margin:5px 0; color:#856404;">
                                You already have an account with us. Please proceed to login.
                            </p>
                            <div style="margin-top:15px;">
                                <a href="sign_in.php" style="background:#1e7e6c; color:white; padding:10px 25px; border-radius:5px; text-decoration:none; font-weight:bold; display:inline-block;">
                                    <i class="fas fa-sign-in-alt"></i> Go to Login
                                </a>
                            </div>
                        </div>
                    ';
                    
                    $_SESSION['verification_errors'][] = $accountCheck['message'];
                    $_SESSION['id_verified'] = false;
                    $id_verified = false;
                    $current_step = 2;
                } else {
                    $ageCheck = checkAge($form_data['date_of_birth']);
                    
                    if (!$ageCheck['valid']) {
                        $_SESSION['verification_errors'][] = $ageCheck['message'];
                        $_SESSION['id_verified'] = false;
                        $id_verified = false;
                        $current_step = 2;
                        $error_message = $ageCheck['message'];
                    } else {
                        $_SESSION['resident_match'] = $verifyResult['resident_data'];
                        $_SESSION['verification_status'] = $verifyResult;
                        $_SESSION['id_verified'] = true;
                        $resident_match = $_SESSION['resident_match'];
                        $verification_status = $_SESSION['verification_status'];
                        $id_verified = $_SESSION['id_verified'];
                        
                        if (!empty($resident_match)) {
                            if (isset($resident_match['household_address']) && !empty($resident_match['household_address'])) {
                                $_SESSION['signup_data']['address'] = $resident_match['household_address'];
                            }
                            if (isset($resident_match['purok'])) {
                                $_SESSION['signup_data']['purok'] = $resident_match['purok'];
                            }
                            $form_data = $_SESSION['signup_data'];
                        }
                        
                        $success_message = $verifyResult['message'];
                        $current_step = 3;
                        $_SESSION['signup_data']['current_step'] = 3;
                        $form_data = $_SESSION['signup_data'];
                    }
                }
            } else {
                $_SESSION['verification_errors'][] = $verifyResult['message'];
                $_SESSION['verification_status'] = $verifyResult;
                $_SESSION['id_verified'] = false;
                $id_verified = false;
                $current_step = 2;
                $error_message = $verifyResult['message'];
            }
        } else {
            $_SESSION['verification_errors'][] = $result['message'];
            $_SESSION['id_verified'] = false;
            $id_verified = false;
            $current_step = 2;
            $error_message = $result['message'];
        }
    } elseif ($action === 'submit') {
        $result = processFullRegistration($_SESSION['signup_data']);
        if ($result['success']) {
            unset($_SESSION['email_verified']);
            unset($_SESSION['signup_data']);
            unset($_SESSION['otp']);
            unset($_SESSION['otp_expires']);
            unset($_SESSION['ocr_data']);
            unset($_SESSION['resident_match']);
            unset($_SESSION['verification_status']);
            unset($_SESSION['id_image_front_path']);
            unset($_SESSION['id_image_back_path']);
            unset($_SESSION['selected_id_type']);
            unset($_SESSION['detected_id_type']);
            unset($_SESSION['id_verified']);
            unset($_SESSION['verification_errors']);
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Registration Complete - BRITE</title>
                <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
                <style>
                    body { margin:0; padding:0; min-height:100vh; background:linear-gradient(135deg,#4facfe,#43e97b); display:flex; justify-content:center; align-items:center; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; }
                    .swal2-popup { animation: fadeInUp 0.5s ease-out; }
                    @keyframes fadeInUp { from { opacity:0; transform:translateY(30px); } to { opacity:1; transform:translateY(0); } }
                </style>
            </head>
            <body>
                <script>
                    Swal.fire({
                        title: 'Registration Complete! 🎉',
                        html: `
                            <div style="text-align:center;">
                                <i class="fas fa-check-circle" style="color:#43e97b;font-size:64px;margin-bottom:15px;"></i>
                                <h3 style="color:#333;margin-bottom:15px;">Account Created Successfully!</h3>
                                <div style="background:#e8f5e9;padding:15px;border-radius:10px;margin:15px 0;text-align:left;">
                                    <p style="margin:8px 0;"><i class="fas fa-user" style="color:#43e97b;width:25px;"></i> <strong>Username:</strong> <?php echo htmlspecialchars($result['username'] ?? ''); ?></p>
                                    <p style="margin:8px 0;"><i class="fas fa-envelope" style="color:#43e97b;width:25px;"></i> <strong>Email:</strong> <?php echo htmlspecialchars($form_data['email'] ?? ''); ?></p>
                                    <p style="margin:8px 0;"><i class="fas fa-check-circle" style="color:#43e97b;width:25px;"></i> Account verified</p>
                                </div>
                                <button onclick="goToLogin()" style="background:#43e97b;color:white;border:none;padding:12px 30px;border-radius:8px;font-size:16px;font-weight:600;cursor:pointer;transition:all 0.3s ease;margin-top:10px;">
                                    Go to Login Page
                                </button>
                            </div>
                        `,
                        icon: 'success',
                        showConfirmButton: false,
                        allowOutsideClick: false,
                        allowEscapeKey: false
                    });
                    function goToLogin() { window.location.href = 'sign_in.php'; }
                </script>
            </body>
            </html>
            <?php
            exit;
        } else {
            $error_message = $result['message'];
            $current_step = $result['step'] ?? 4;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['signup_data'])) {
    $form_data = $_SESSION['signup_data'];
}

if ($current_step == 3 && $id_verified && !empty($_SESSION['ocr_data'])) {
    $ocr_fields = ['last_name', 'first_name', 'middle_name', 'date_of_birth', 'address'];
    foreach ($ocr_fields as $field) {
        if (!empty($_SESSION['ocr_data'][$field]) && empty($form_data[$field])) {
            $form_data[$field] = $_SESSION['ocr_data'][$field];
        }
    }
    if (!empty($_SESSION['ocr_data']['sex']) && empty($form_data['gender'])) {
        $form_data['gender'] = $_SESSION['ocr_data']['sex'];
    }
    $_SESSION['signup_data'] = $form_data;
}

// ============================================================
// OCR.SPACE FUNCTIONS (Primary)
// ============================================================

function processIDWithOCRSpace() {
    global $conn;
    
    if (empty($_FILES['id_image_front']['name']) && empty($_POST['front_image_data'])) {
        return ['success' => false, 'message' => '⚠️ Please capture or upload the front of your ID.'];
    }
    if (empty($_FILES['id_image_back']['name']) && empty($_POST['back_image_data'])) {
        return ['success' => false, 'message' => '⚠️ Please capture or upload the back of your ID.'];
    }
    
    if (!empty($_POST['front_image_data'])) {
        $frontImageData = $_POST['front_image_data'];
        $frontImageData = str_replace('data:image/jpeg;base64,', '', $frontImageData);
        $frontImageData = str_replace(' ', '+', $frontImageData);
        $frontImageData = base64_decode($frontImageData);
        
        $frontFilename = time() . '_front_' . uniqid() . '.jpg';
        $frontFilepath = 'uploads/ids/front/' . $frontFilename;
        
        if (!file_exists('uploads/ids/front/')) {
            mkdir('uploads/ids/front/', 0777, true);
        }
        
        file_put_contents($frontFilepath, $frontImageData);
        $_SESSION['id_image_front_path'] = $frontFilepath;
        $idFrontPath = $frontFilepath;
    } else {
        $frontResult = processFileUploadSimple($_FILES['id_image_front'], 'ids/front/');
        if (!$frontResult['success']) {
            return ['success' => false, 'message' => '⚠️ Front ID: ' . $frontResult['error']];
        }
        $idFrontPath = $frontResult['path'];
        $_SESSION['id_image_front_path'] = $idFrontPath;
    }
    
    if (!empty($_POST['back_image_data'])) {
        $backImageData = $_POST['back_image_data'];
        $backImageData = str_replace('data:image/jpeg;base64,', '', $backImageData);
        $backImageData = str_replace(' ', '+', $backImageData);
        $backImageData = base64_decode($backImageData);
        
        $backFilename = time() . '_back_' . uniqid() . '.jpg';
        $backFilepath = 'uploads/ids/back/' . $backFilename;
        
        if (!file_exists('uploads/ids/back/')) {
            mkdir('uploads/ids/back/', 0777, true);
        }
        
        file_put_contents($backFilepath, $backImageData);
        $_SESSION['id_image_back_path'] = $backFilepath;
        $idBackPath = $backFilepath;
    } else {
        $backResult = processFileUploadSimple($_FILES['id_image_back'], 'ids/back/');
        if (!$backResult['success']) {
            return ['success' => false, 'message' => '⚠️ Back ID: ' . $backResult['error']];
        }
        $idBackPath = $backResult['path'];
        $_SESSION['id_image_back_path'] = $idBackPath;
    }
    
    $frontResult = processOCRSpaceImage($idFrontPath, 'front');
    if (!$frontResult['success']) {
        return ['success' => false, 'message' => '⚠️ Front ID OCR failed: ' . $frontResult['error']];
    }
    
    $detectedType = $frontResult['detected_type'] ?? '';
    $frontData = $frontResult['data'];
    
    $_SESSION['selected_id_type'] = $detectedType;
    $_SESSION['detected_id_type'] = $detectedType;
    
    $backResult = processOCRSpaceImage($idBackPath, 'back', $detectedType);
    if (!$backResult['success']) {
        return ['success' => false, 'message' => '⚠️ Back ID OCR failed: ' . $backResult['error']];
    }
    
    $backData = $backResult['data'];
    
    $combinedData = array_merge($frontData, $backData);
    $combinedData = cleanOCRData($combinedData);
    $combinedData['id_type'] = $detectedType;
    
    $missingFields = [];
    if (empty($combinedData['last_name'])) $missingFields[] = 'Last Name';
    if (empty($combinedData['first_name'])) $missingFields[] = 'First Name';
    if (empty($combinedData['date_of_birth'])) $missingFields[] = 'Date of Birth';
    
    if (!empty($missingFields)) {
        $warningMsg = '⚠️ Could not read some fields clearly:<br>';
        foreach ($missingFields as $field) {
            $warningMsg .= '• ' . $field . '<br>';
        }
        $warningMsg .= '<br>💡 Please upload a clearer, well-lit image of your ID.';
        return [
            'success' => false,
            'message' => $warningMsg
        ];
    }
    
    return [
        'success' => true,
        'data' => $combinedData,
        'message' => '✅ ID processed successfully!',
        'detected_type' => $detectedType
    ];
}

function processOCRSpaceImage($imagePath, $side = 'front', $idType = '') {
    $apiKey = OCR_SPACE_API_KEY;
    $apiUrl = 'https://api.ocr.space/parse/image';
    
    if (!file_exists($imagePath)) {
        return ['success' => false, 'error' => 'Image file not found'];
    }
    
    $fileData = new CURLFile($imagePath);
    
    $postData = [
        'apikey' => $apiKey,
        'language' => 'eng',
        'file' => $fileData,
        'OCREngine' => '2',
        'scale' => 'true',
        'isTable' => 'false',
        'isOverlayRequired' => 'false',
        'detectOrientation' => 'true'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return ['success' => false, 'error' => 'Connection error: ' . $curlError];
    }
    
    if ($httpCode !== 200) {
        return ['success' => false, 'error' => 'Server error: ' . $httpCode];
    }
    
    $result = json_decode($response, true);
    
    if (!$result) {
        return ['success' => false, 'error' => 'Invalid response from OCR service'];
    }
    
    if ($result['OCRExitCode'] !== 1) {
        $errorMsg = is_array($result['ErrorMessage']) ? implode(', ', $result['ErrorMessage']) : ($result['ErrorMessage'] ?? 'Unknown error');
        return ['success' => false, 'error' => 'OCR failed: ' . $errorMsg];
    }
    
    if (!isset($result['ParsedResults']) || empty($result['ParsedResults'])) {
        return ['success' => false, 'error' => 'No readable text found - image may be too blurry or not contain text'];
    }
    
    $textData = $result['ParsedResults'][0]['ParsedText'] ?? '';
    
    if (is_array($textData)) {
        $text = implode("\n", $textData);
    } else {
        $text = (string)$textData;
    }
    
    if (empty(trim($text)) || strlen(trim($text)) < 30) {
        return ['success' => false, 'error' => 'Could not read text clearly. Please upload a clearer, well-lit image of your ID.'];
    }
    
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    
    $detectedType = detectIDType($text);
    
    if (empty($idType)) {
        $idType = $detectedType;
    }
    
    if ($side == 'front') {
        $data = parseFrontIDByType($text, $idType);
    } else {
        $data = parseBackID($text);
    }
    
    return [
        'success' => true,
        'data' => $data,
        'text' => $text,
        'detected_type' => $detectedType
    ];
}

// ============================================================
// ACCOUNT CHECK FUNCTION
// ============================================================

function checkExistingAccount($data) {
    global $conn;
    
    $firstName = !empty($data['first_name']) ? trim($data['first_name']) : '';
    $lastName = !empty($data['last_name']) ? trim($data['last_name']) : '';
    $middleName = !empty($data['middle_name']) ? trim($data['middle_name']) : '';
    $dob = !empty($data['date_of_birth']) ? $data['date_of_birth'] : '';
    
    if (empty($firstName) || empty($lastName) || empty($dob)) {
        return [
            'exists' => false,
            'message' => 'Incomplete data to check existing account.'
        ];
    }
    
    if (!empty($middleName)) {
        $query = "
            SELECT id, username, email, is_verified, is_active 
            FROM resident 
            WHERE first_name = ? 
            AND last_name = ? 
            AND middle_name = ?
            AND date_of_birth = ?
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssss", $firstName, $lastName, $middleName, $dob);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $resident = $result->fetch_assoc();
            $stmt->close();
            
            return [
                'exists' => true,
                'username' => $resident['username'],
                'email' => $resident['email'],
                'status' => $resident['is_active'] == 0 ? 'Inactive' : ($resident['is_verified'] == 0 ? 'Pending Verification' : 'Active & Verified'),
                'message' => 'Account already exists.'
            ];
        }
        $stmt->close();
    }
    
    $query = "
        SELECT id, username, email, is_verified, is_active 
        FROM resident 
        WHERE first_name = ? 
        AND last_name = ? 
        AND date_of_birth = ?
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sss", $firstName, $lastName, $dob);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $resident = $result->fetch_assoc();
        $stmt->close();
        
        return [
            'exists' => true,
            'username' => $resident['username'],
            'email' => $resident['email'],
            'status' => $resident['is_active'] == 0 ? 'Inactive' : ($resident['is_verified'] == 0 ? 'Pending Verification' : 'Active & Verified'),
            'message' => 'Account already exists.'
        ];
    }
    $stmt->close();
    
    return ['exists' => false, 'message' => 'No existing account found.'];
}

// ============================================================
// ID TYPE FUNCTIONS
// ============================================================

function detectIDType($text) {
    $textUpper = strtoupper($text);
    
    $idTypes = [
        'Philippine National ID' => ['PHILIPPINE NATIONAL ID', 'PHILSYS', 'NATIONAL ID', 'PAMBANSANG PAGKAKAKILANLAN', 'PUBLIKA NO PILIPINAS'],
        'PhilHealth ID' => ['PHILHEALTH', 'PHIL HEALTH', 'PHILHEALTH ID', 'NATIONAL HEALTH', 'NHI', 'HEALTH INSURANCE', 'PHILIPPINE HEALTH INSURANCE'],
        "Driver's License" => ['DRIVER', 'LICENSE', 'DRIVING', 'LTO', "DRIVER'S", 'DRIVING LICENSE', 'NON-PROFESSIONAL', 'PROFESSIONAL DRIVER'],
        'Passport' => ['PASSPORT', 'REPUBLIC OF THE PHILIPPINES', 'PHILIPPINE PASSPORT', 'PASAPORTE'],
        'UMID' => ['UMID', 'UNIFIED MULTI-PURPOSE ID', 'UNIFIED ID', 'GSIS', 'SSS', 'PAG-IBIG'],
        'PRC ID' => ['PRC ID', 'PROFESSIONAL REGULATION', 'PRC', 'PROFESSIONAL LICENSE', 'REGULATORY'],
        'Postal ID' => ['POSTAL ID', 'PHILIPPINE POSTAL', 'POSTAL', 'PHILPOST'],
        "Voter's ID" => ['VOTER', 'COMELEC', 'VOTER ID', 'REGISTERED VOTER'],
        'SSS ID' => ['SSS', 'SOCIAL SECURITY', 'SSS ID', 'SOCIAL SECURITY SYSTEM'],
        'GSIS ID' => ['GSIS', 'GOVERNMENT SERVICE', 'GSIS ID', 'GOVERNMENT SERVICE INSURANCE'],
        'PNP ID' => ['PNP', 'PHILIPPINE NATIONAL POLICE', 'PNP ID', 'POLICE'],
        'AFP ID' => ['AFP', 'ARMED FORCES', 'AFP ID', 'MILITARY'],
        'Senior Citizen ID' => ['SENIOR CITIZEN', 'SC ID', 'ELDERLY', 'SENIOR CITIZEN ID'],
        'PWD ID' => ['PWD', 'PERSONS WITH DISABILITY', 'DISABILITY', 'PWD ID'],
        'Barangay ID' => ['BARANGAY ID', 'BARANGAY CERTIFICATION', 'BARANGAY CLEARANCE'],
    ];
    
    foreach ($idTypes as $type => $keywords) {
        foreach ($keywords as $keyword) {
            if (strpos($textUpper, $keyword) !== false) {
                return $type;
            }
        }
    }
    
    if (preg_match('/\d{4}[- ]\d{4}[- ]\d{4}[- ]\d{4}/', $textUpper)) {
        return 'Philippine National ID';
    }
    if (preg_match('/\d{2}-\d{9}-\d{1}/', $textUpper)) {
        return 'PhilHealth ID';
    }
    if (preg_match('/12\d{10}/', $textUpper)) {
        return 'PhilHealth ID';
    }
    if (preg_match('/[A-Z]\d{7,8}/', $textUpper)) {
        return 'Passport';
    }
    if (preg_match('/[A-Z]{1,2}\d{2}-\d{2}-\d{6,7}/', $textUpper)) {
        return "Driver's License";
    }
    if (preg_match('/\d{2}-\d{7}-\d{1}/', $textUpper)) {
        return 'SSS ID';
    }
    
    return 'Government ID';
}

// ============================================================
// ID-TYPE-SPECIFIC FRONT ID PARSERS
// ============================================================

function parseFrontIDByType($text, $idType = '') {
    if (empty($idType)) {
        $idType = detectIDType($text);
    }
    
    switch ($idType) {
        case 'Philippine National ID':
            return parsePhilippineNationalID($text);
        case 'PhilHealth ID':
            return parsePhilHealthID($text);
        case "Driver's License":
            return parseDriversLicense($text);
        case 'Passport':
            return parsePassport($text);
        case 'UMID':
            return parseUMID($text);
        case 'SSS ID':
            return parseSSSID($text);
        case 'PRC ID':
            return parsePRCID($text);
        case 'Postal ID':
            return parsePostalID($text);
        case "Voter's ID":
            return parseVotersID($text);
        default:
            return parseGenericID($text);
    }
}

// ============================================================
// PARSER FUNCTIONS - PHILIPPINE NATIONAL ID
// ============================================================

function parsePhilippineNationalID($text) {
    $data = [
        'last_name' => '',
        'first_name' => '',
        'middle_name' => '',
        'date_of_birth' => '',
        'address' => '',
        'id_number' => '',
        'id_type' => 'Philippine National ID'
    ];
    
    $fullText = ' ' . $text . ' ';
    
    if (preg_match('/(\d{4}[- ]\d{4}[- ]\d{4}[- ]\d{4})/', $fullText, $matches)) {
        $data['id_number'] = trim($matches[1]);
    }
    
    if (preg_match('/Apelyido(?:\/Last Name)?\s*[:.]?\s*([A-Z\s\-]+)/i', $fullText, $matches)) {
        $data['last_name'] = cleanName($matches[1]);
    }
    
    if (preg_match('/Mga Pangalan(?:\/Given Names)?\s*[:.]?\s*([A-Z\s\-]+)/i', $fullText, $matches)) {
        $data['first_name'] = cleanName($matches[1]);
    }
    
    if (preg_match('/Gitnang Apelyido(?:\/Middle Name)?\s*[:.]?\s*([A-Z\s\-]+)/i', $fullText, $matches)) {
        $data['middle_name'] = cleanName($matches[1]);
    }
    
    if (preg_match('/etsa ng Kapanganakan(?:\/Date of Birth)?\s*[:.]?\s*([A-Z]+\s+\d{1,2},?\s+\d{4})/i', $fullText, $matches)) {
        $data['date_of_birth'] = parseDate($matches[1]);
    }
    
    if (preg_match('/Tirahan(?:\/Address)?\s*[:.]?\s*([^PHL]+?)(?:\s+PHL)?/i', $fullText, $matches)) {
        $data['address'] = trim(preg_replace('/\s+/', ' ', $matches[1]));
    }
    
    return $data;
}

// ============================================================
// PARSER FUNCTIONS - PHILHEALTH ID
// ============================================================

function parsePhilHealthID($text) {
    $data = [
        'last_name' => '',
        'first_name' => '',
        'middle_name' => '',
        'date_of_birth' => '',
        'address' => '',
        'id_number' => '',
        'id_type' => 'PhilHealth ID'
    ];
    
    $fullText = ' ' . $text . ' ';
    
    if (preg_match('/(\d{2}-\d{9}-\d{1})/', $fullText, $matches)) {
        $data['id_number'] = trim($matches[1]);
    } elseif (preg_match('/(\d{12})/', $fullText, $matches)) {
        $id = $matches[1];
        $data['id_number'] = substr($id, 0, 2) . '-' . substr($id, 2, 9) . '-' . substr($id, 11, 1);
    }
    
    if (preg_match('/([A-Z]+),([A-Z\s]+)\s+([A-Z]+)/', $fullText, $matches)) {
        $data['last_name'] = cleanName($matches[1]);
        $data['first_name'] = cleanName($matches[2]);
        $data['middle_name'] = cleanName($matches[3]);
    } elseif (preg_match('/\b([A-Z]{2,})\s+([A-Z]{2,})\s+([A-Z]{2,})\b/', $fullText, $matches)) {
        $common = ['PHILHEALTH', 'CORPORATION', 'INSURANCE', 'PHILIPPINES', 'REPUBLIC', 'NATIONAL', 'HEALTH'];
        if (!in_array($matches[1], $common) && !in_array($matches[2], $common) && !in_array($matches[3], $common)) {
            $data['last_name'] = cleanName($matches[1]);
            $data['first_name'] = cleanName($matches[2]);
            $data['middle_name'] = cleanName($matches[3]);
        }
    }
    
    if (preg_match('/([A-Z]+\s+\d{1,2},?\s+\d{4})/', $fullText, $matches)) {
        $data['date_of_birth'] = parseDate($matches[1]);
    } elseif (preg_match('/(\d{1,2})[-/](\d{1,2})[-/](\d{4})/', $fullText, $matches)) {
        $data['date_of_birth'] = $matches[3] . '-' . str_pad($matches[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT);
    }
    
    if (preg_match('/\b(MALE|FEMALE)\b/i', $fullText, $matches)) {
        $data['sex'] = ucfirst(strtolower($matches[1]));
    }
    
    if (preg_match('/(\d+[A-Z\s,]+(?:QUEZON|MANILA|MAKATI|PASAY|MANDALUYONG|SAN JUAN|CALOOCAN|VALENZUELA|MALABON|NAVOTAS|PARA?AQUE|LAS PI?AS|MUNTINLUPA|TAGUIG|PATEROS|MARIKINA|PASIG|QUEZON CITY|CEBU|DAVAO|ILOILO|BACOLOD|CAGAYAN DE ORO|ZAMBOANGA|GENERAL SANTOS|BAGUIO|ANGELES|OLONGAPO)[,\s]+(?:\d{4})?)/i', $fullText, $matches)) {
        $data['address'] = trim(preg_replace('/\s+/', ' ', $matches[1]));
    }
    
    return $data;
}

// ============================================================
// PARSER FUNCTIONS - DRIVER'S LICENSE
// ============================================================

function parseDriversLicense($text) {
    $data = [
        'last_name' => '',
        'first_name' => '',
        'middle_name' => '',
        'date_of_birth' => '',
        'address' => '',
        'id_number' => '',
        'id_type' => "Driver's License"
    ];
    
    $fullText = ' ' . $text . ' ';
    
    if (preg_match('/([A-Z]{1,2}\d{2}-\d{2}-\d{6,7})/', $fullText, $matches)) {
        $data['id_number'] = trim($matches[1]);
    } elseif (preg_match('/([A-Z]{1,2}\d{2}\s\d{2}\s\d{6,7})/', $fullText, $matches)) {
        $data['id_number'] = trim($matches[1]);
    }
    
    if (preg_match('/Name\s*[:.]?\s*([A-Z\s,]+)/i', $fullText, $matches)) {
        $name = trim($matches[1]);
        if (strpos($name, ',') !== false) {
            $parts = explode(',', $name);
            $data['last_name'] = cleanName($parts[0]);
            $remaining = explode(' ', trim($parts[1]));
            if (count($remaining) >= 1) {
                $data['first_name'] = cleanName($remaining[0]);
                if (count($remaining) >= 2) {
                    $data['middle_name'] = cleanName(implode(' ', array_slice($remaining, 1)));
                }
            }
        } else {
            $parts = explode(' ', $name);
            if (count($parts) >= 2) {
                $data['first_name'] = cleanName($parts[0]);
                $data['last_name'] = cleanName(end($parts));
                if (count($parts) >= 3) {
                    $data['middle_name'] = cleanName(implode(' ', array_slice($parts, 1, -1)));
                }
            }
        }
    }
    
    if (preg_match('/Birth(?:date)?\s*[:.]?\s*([A-Z]+\s+\d{1,2},?\s+\d{4})/i', $fullText, $matches)) {
        $data['date_of_birth'] = parseDate($matches[1]);
    } elseif (preg_match('/(\d{1,2})[-/](\d{1,2})[-/](\d{4})/', $fullText, $matches)) {
        $data['date_of_birth'] = $matches[3] . '-' . str_pad($matches[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT);
    }
    
    return $data;
}

// ============================================================
// PARSER FUNCTIONS - PASSPORT
// ============================================================

function parsePassport($text) {
    $data = [
        'last_name' => '',
        'first_name' => '',
        'middle_name' => '',
        'date_of_birth' => '',
        'address' => '',
        'id_number' => '',
        'id_type' => 'Passport'
    ];
    
    $fullText = ' ' . $text . ' ';
    
    if (preg_match('/([A-Z]\d{7,8})/', $fullText, $matches)) {
        $data['id_number'] = trim($matches[1]);
    }
    
    if (preg_match('/Surname\s*[:.]?\s*([A-Z\s\-]+)/i', $fullText, $matches)) {
        $data['last_name'] = cleanName($matches[1]);
    }
    
    if (preg_match('/Given Name(?:s)?\s*[:.]?\s*([A-Z\s\-]+)/i', $fullText, $matches)) {
        $data['first_name'] = cleanName($matches[1]);
    }
    
    if (preg_match('/Date of Birth\s*[:.]?\s*([A-Z]+\s+\d{1,2},?\s+\d{4})/i', $fullText, $matches)) {
        $data['date_of_birth'] = parseDate($matches[1]);
    }
    
    return $data;
}

// ============================================================
// PARSER FUNCTIONS - UMID
// ============================================================

function parseUMID($text) {
    $data = [
        'last_name' => '',
        'first_name' => '',
        'middle_name' => '',
        'date_of_birth' => '',
        'address' => '',
        'id_number' => '',
        'id_type' => 'UMID'
    ];
    
    $fullText = ' ' . $text . ' ';
    
    if (preg_match('/(\d{12,16})/', $fullText, $matches)) {
        $data['id_number'] = trim($matches[1]);
    }
    
    if (preg_match('/Name\s*[:.]?\s*([A-Z\s,]+)/i', $fullText, $matches)) {
        $name = trim($matches[1]);
        if (strpos($name, ',') !== false) {
            $parts = explode(',', $name);
            $data['last_name'] = cleanName($parts[0]);
            $remaining = explode(' ', trim($parts[1]));
            if (count($remaining) >= 1) {
                $data['first_name'] = cleanName($remaining[0]);
                if (count($remaining) >= 2) {
                    $data['middle_name'] = cleanName(implode(' ', array_slice($remaining, 1)));
                }
            }
        }
    }
    
    if (preg_match('/(?:DOB|Date of Birth)\s*[:.]?\s*([A-Z]+\s+\d{1,2},?\s+\d{4})/i', $fullText, $matches)) {
        $data['date_of_birth'] = parseDate($matches[1]);
    }
    
    return $data;
}

// ============================================================
// PARSER FUNCTIONS - SSS ID
// ============================================================

function parseSSSID($text) {
    $data = [
        'last_name' => '',
        'first_name' => '',
        'middle_name' => '',
        'date_of_birth' => '',
        'address' => '',
        'id_number' => '',
        'id_type' => 'SSS ID'
    ];
    
    $fullText = ' ' . $text . ' ';
    
    if (preg_match('/(\d{2}-\d{7}-\d{1})/', $fullText, $matches)) {
        $data['id_number'] = trim($matches[1]);
    } elseif (preg_match('/(\d{10})/', $fullText, $matches)) {
        $id = $matches[1];
        $data['id_number'] = substr($id, 0, 2) . '-' . substr($id, 2, 7) . '-' . substr($id, 9, 1);
    }
    
    if (preg_match('/Name\s*[:.]?\s*([A-Z\s,]+)/i', $fullText, $matches)) {
        $name = trim($matches[1]);
        if (strpos($name, ',') !== false) {
            $parts = explode(',', $name);
            $data['last_name'] = cleanName($parts[0]);
            $remaining = explode(' ', trim($parts[1]));
            if (count($remaining) >= 1) {
                $data['first_name'] = cleanName($remaining[0]);
                if (count($remaining) >= 2) {
                    $data['middle_name'] = cleanName(implode(' ', array_slice($remaining, 1)));
                }
            }
        }
    }
    
    return $data;
}

// ============================================================
// PARSER FUNCTIONS - PRC ID
// ============================================================

function parsePRCID($text) {
    $data = [
        'last_name' => '',
        'first_name' => '',
        'middle_name' => '',
        'date_of_birth' => '',
        'address' => '',
        'id_number' => '',
        'id_type' => 'PRC ID'
    ];
    
    $fullText = ' ' . $text . ' ';
    
    if (preg_match('/(\d{7,10})/', $fullText, $matches)) {
        $data['id_number'] = trim($matches[1]);
    }
    
    if (preg_match('/Name\s*[:.]?\s*([A-Z\s,]+)/i', $fullText, $matches)) {
        $name = trim($matches[1]);
        if (strpos($name, ',') !== false) {
            $parts = explode(',', $name);
            $data['last_name'] = cleanName($parts[0]);
            $remaining = explode(' ', trim($parts[1]));
            if (count($remaining) >= 1) {
                $data['first_name'] = cleanName($remaining[0]);
                if (count($remaining) >= 2) {
                    $data['middle_name'] = cleanName(implode(' ', array_slice($remaining, 1)));
                }
            }
        }
    }
    
    return $data;
}

// ============================================================
// PARSER FUNCTIONS - POSTAL ID
// ============================================================

function parsePostalID($text) {
    $data = [
        'last_name' => '',
        'first_name' => '',
        'middle_name' => '',
        'date_of_birth' => '',
        'address' => '',
        'id_number' => '',
        'id_type' => 'Postal ID'
    ];
    
    $fullText = ' ' . $text . ' ';
    
    if (preg_match('/([A-Z0-9]{8,12})/', $fullText, $matches)) {
        $data['id_number'] = trim($matches[1]);
    }
    
    if (preg_match('/Name\s*[:.]?\s*([A-Z\s,]+)/i', $fullText, $matches)) {
        $name = trim($matches[1]);
        if (strpos($name, ',') !== false) {
            $parts = explode(',', $name);
            $data['last_name'] = cleanName($parts[0]);
            $remaining = explode(' ', trim($parts[1]));
            if (count($remaining) >= 1) {
                $data['first_name'] = cleanName($remaining[0]);
                if (count($remaining) >= 2) {
                    $data['middle_name'] = cleanName(implode(' ', array_slice($remaining, 1)));
                }
            }
        }
    }
    
    if (preg_match('/Address\s*[:.]?\s*([A-Z0-9\s,\.]+)/i', $fullText, $matches)) {
        $data['address'] = trim(preg_replace('/\s+/', ' ', $matches[1]));
    }
    
    return $data;
}

// ============================================================
// PARSER FUNCTIONS - VOTER'S ID
// ============================================================

function parseVotersID($text) {
    $data = [
        'last_name' => '',
        'first_name' => '',
        'middle_name' => '',
        'date_of_birth' => '',
        'address' => '',
        'id_number' => '',
        'id_type' => "Voter's ID"
    ];
    
    $fullText = ' ' . $text . ' ';
    
    if (preg_match('/Name\s*[:.]?\s*([A-Z\s,]+)/i', $fullText, $matches)) {
        $name = trim($matches[1]);
        if (strpos($name, ',') !== false) {
            $parts = explode(',', $name);
            $data['last_name'] = cleanName($parts[0]);
            $remaining = explode(' ', trim($parts[1]));
            if (count($remaining) >= 1) {
                $data['first_name'] = cleanName($remaining[0]);
                if (count($remaining) >= 2) {
                    $data['middle_name'] = cleanName(implode(' ', array_slice($remaining, 1)));
                }
            }
        }
    }
    
    if (preg_match('/Address\s*[:.]?\s*([A-Z0-9\s,\.]+)/i', $fullText, $matches)) {
        $data['address'] = trim(preg_replace('/\s+/', ' ', $matches[1]));
    }
    
    return $data;
}

// ============================================================
// PARSER FUNCTIONS - GENERIC (Fallback)
// ============================================================

function parseGenericID($text) {
    $data = [
        'last_name' => '',
        'first_name' => '',
        'middle_name' => '',
        'date_of_birth' => '',
        'address' => '',
        'id_number' => '',
        'id_type' => 'Government ID'
    ];
    
    $fullText = ' ' . $text . ' ';
    
    $idPatterns = [
        '/(\d{4}[- ]\d{4}[- ]\d{4}[- ]\d{4})/',
        '/(\d{2}-\d{9}-\d{1})/',
        '/([A-Z]{1,2}\d{2}-\d{2}-\d{6,7})/',
        '/([A-Z]\d{7,8})/',
        '/(\d{10,16})/',
        '/(\d{10,12})/',
    ];
    
    foreach ($idPatterns as $pattern) {
        if (preg_match($pattern, $fullText, $matches)) {
            $data['id_number'] = trim($matches[1]);
            break;
        }
    }
    
    if (preg_match('/Name\s*[:.]?\s*([A-Z\s,]+)/i', $fullText, $matches)) {
        $name = trim($matches[1]);
        if (strpos($name, ',') !== false) {
            $parts = explode(',', $name);
            $data['last_name'] = cleanName($parts[0]);
            $remaining = explode(' ', trim($parts[1]));
            if (count($remaining) >= 1) {
                $data['first_name'] = cleanName($remaining[0]);
                if (count($remaining) >= 2) {
                    $data['middle_name'] = cleanName(implode(' ', array_slice($remaining, 1)));
                }
            }
        } else {
            $parts = explode(' ', $name);
            if (count($parts) >= 2) {
                $data['first_name'] = cleanName($parts[0]);
                $data['last_name'] = cleanName(end($parts));
                if (count($parts) >= 3) {
                    $data['middle_name'] = cleanName(implode(' ', array_slice($parts, 1, -1)));
                }
            }
        }
    }
    
    if (preg_match('/(?:DOB|Date of Birth|Birthdate)\s*[:.]?\s*([A-Z]+\s+\d{1,2},?\s+\d{4})/i', $fullText, $matches)) {
        $data['date_of_birth'] = parseDate($matches[1]);
    } elseif (preg_match('/(\d{1,2})[-/](\d{1,2})[-/](\d{4})/', $fullText, $matches)) {
        $data['date_of_birth'] = $matches[3] . '-' . str_pad($matches[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT);
    }
    
    if (preg_match('/Address\s*[:.]?\s*([A-Z0-9\s,\.]+)/i', $fullText, $matches)) {
        $data['address'] = trim(preg_replace('/\s+/', ' ', $matches[1]));
    }
    
    return $data;
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================

function cleanName($name) {
    $name = trim($name);
    $name = preg_replace('/\s*(?:APELYIDO|LAST NAME|MGA PANGALAN|GIVEN NAMES|GITNANG APELYIDO|MIDDLE NAME|ETSA NG|DATE OF BIRTH|TIRAHAN|ADDRESS|SEX|GENDER).*$/i', '', $name);
    $name = preg_replace('/[^A-Za-z\s-]/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim($name);
    return strtoupper($name);
}

function parseBackID($text) {
    $data = [
        'sex' => '',
        'blood_type' => '',
        'marital_status' => '',
        'place_of_birth' => '',
        'date_of_issue' => '',
        'id_number' => ''
    ];
    
    $lines = explode("\n", $text);
    $fullText = ' ' . $text . ' ';
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/MALE/i', $line)) {
            $data['sex'] = 'Male';
            break;
        }
        if (preg_match('/FEMALE/i', $line)) {
            $data['sex'] = 'Female';
            break;
        }
        if (preg_match('/Sex\s*[:.]?\s*([A-Z]+)/i', $line, $matches)) {
            $sex = strtoupper($matches[1]);
            $data['sex'] = ($sex === 'M' || $sex === 'MALE') ? 'Male' : 
                          (($sex === 'F' || $sex === 'FEMALE') ? 'Female' : $sex);
            break;
        }
        if (preg_match('/Kasarian\s*[:.]?\s*([A-Z]+)/i', $line, $matches)) {
            $sex = strtoupper($matches[1]);
            $data['sex'] = ($sex === 'M' || $sex === 'MALE') ? 'Male' : 
                          (($sex === 'F' || $sex === 'FEMALE') ? 'Female' : $sex);
            break;
        }
    }
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/Blood Type\s*[:.]?\s*([A-Z+]+)/i', $line, $matches)) {
            $data['blood_type'] = $matches[1];
            break;
        }
        if (preg_match('/Uring Dugo\s*[:.]?\s*([A-Z+]+)/i', $line, $matches)) {
            $data['blood_type'] = $matches[1];
            break;
        }
        if (preg_match('/UNKNOWN/i', $line)) {
            $data['blood_type'] = 'UNKNOWN';
            break;
        }
    }
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/SINGLE/i', $line)) {
            $data['marital_status'] = 'SINGLE';
            break;
        }
        if (preg_match('/MARRIED/i', $line)) {
            $data['marital_status'] = 'MARRIED';
            break;
        }
        if (preg_match('/Marital Status\s*[:.]?\s*([A-Z\s]+)/i', $line, $matches)) {
            $candidate = trim($matches[1]);
            if (!preg_match('/Kalagayang|Sibil|Uring|Dugo|Lugar|Kapanganakan|If|found|return|nearest|Office|www|psa|gov|ph/i', $candidate)) {
                $data['marital_status'] = $candidate;
                break;
            }
        }
        if (preg_match('/Kalagayang Sibil\s*[:.]?\s*([A-Z\s]+)/i', $line, $matches)) {
            $candidate = trim($matches[1]);
            if (!preg_match('/Kalagayang|Sibil|Uring|Dugo|Lugar|Kapanganakan|If|found|return|nearest|Office|www|psa|gov|ph/i', $candidate)) {
                $data['marital_status'] = $candidate;
                break;
            }
        }
    }
    
    for ($i = 0; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        
        if (preg_match('/Place of Birth\s*[:.]?\s*(.+)/i', $line, $matches)) {
            $place = trim($matches[1]);
            if (strpos($place, 'If found') !== false) {
                $place = substr($place, 0, strpos($place, 'If found'));
            }
            $data['place_of_birth'] = trim($place);
            break;
        }
        if (preg_match('/Lugar ng Kapanganakan\s*[:.]?\s*(.+)/i', $line, $matches)) {
            $place = trim($matches[1]);
            if (strpos($place, 'If found') !== false) {
                $place = substr($place, 0, strpos($place, 'If found'));
            }
            $data['place_of_birth'] = trim($place);
            break;
        }
    }
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/Date of issue\s*[:.]?\s*([\d\sA-Za-z,]+)/i', $line, $matches)) {
            $data['date_of_issue'] = parseDate($matches[1]);
            break;
        }
        if (preg_match('/Araw ng pagkakaloob\s*[:.]?\s*([\d\sA-Za-z,]+)/i', $line, $matches)) {
            $data['date_of_issue'] = parseDate($matches[1]);
            break;
        }
    }
    
    return $data;
}

function parseDate($dateStr) {
    if (empty($dateStr)) return '';
    
    $months = [
        'JANUARY' => '01', 'FEBRUARY' => '02', 'MARCH' => '03',
        'APRIL' => '04', 'MAY' => '05', 'JUNE' => '06',
        'JULY' => '07', 'AUGUST' => '08', 'SEPTEMBER' => '09',
        'OCTOBER' => '10', 'NOVEMBER' => '11', 'DECEMBER' => '12',
        'JAN' => '01', 'FEB' => '02', 'MAR' => '03',
        'APR' => '04', 'MAY' => '05', 'JUN' => '06',
        'JUL' => '07', 'AUG' => '08', 'SEP' => '09',
        'OCT' => '10', 'NOV' => '11', 'DEC' => '12'
    ];
    
    $dateStr = strtoupper(trim($dateStr));
    
    if (preg_match('/([A-Z]{3,9})\s+(\d{1,2}),?\s+(\d{4})/', $dateStr, $matches)) {
        $month = $months[$matches[1]] ?? '01';
        return $matches[3] . '-' . $month . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT);
    }
    
    if (preg_match('/(\d{1,2})\s+([A-Z]{3,9})\s+(\d{4})/', $dateStr, $matches)) {
        $month = $months[$matches[2]] ?? '01';
        return $matches[3] . '-' . $month . '-' . str_pad($matches[1], 2, '0', STR_PAD_LEFT);
    }
    
    if (preg_match('/(\d{4})[-/](\d{1,2})[-/](\d{1,2})/', $dateStr, $matches)) {
        return $matches[1] . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($matches[3], 2, '0', STR_PAD_LEFT);
    }
    
    if (preg_match('/(\d{1,2})[-/](\d{1,2})[-/](\d{4})/', $dateStr, $matches)) {
        return $matches[3] . '-' . str_pad($matches[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT);
    }
    
    return $dateStr;
}

// ============================================================
// SHARED FUNCTIONS
// ============================================================

function processFileUploadSimple($file, $subdir) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload failed. Please try again.'];
    }
    
    $allowed = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
    if (!in_array($file['type'], $allowed)) {
        return ['success' => false, 'error' => 'Only JPG, PNG, and WebP images are allowed.'];
    }
    
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'error' => 'Image must be less than 5MB.'];
    }
    
    $target_dir = 'uploads/' . $subdir;
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = time() . '_' . uniqid() . '.' . $extension;
    $filepath = $target_dir . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => false, 'error' => 'Failed to save image.'];
    }
    
    return ['success' => true, 'path' => $filepath];
}

function cleanOCRData($data) {
    $cleaned = [
        'last_name' => '',
        'first_name' => '',
        'middle_name' => '',
        'date_of_birth' => '',
        'sex' => '',
        'address' => '',
        'id_number' => '',
        'id_type' => 'Government ID',
        'confidence' => 0
    ];
    
    foreach ($cleaned as $key => $value) {
        if (isset($data[$key]) && !empty($data[$key])) {
            $cleaned[$key] = trim($data[$key]);
        }
    }
    
    $filledFields = 0;
    $keyFields = ['last_name', 'first_name', 'date_of_birth'];
    foreach ($keyFields as $field) {
        if (!empty($cleaned[$field])) {
            $filledFields++;
        }
    }
    $cleaned['confidence'] = round(($filledFields / count($keyFields)) * 100);
    
    return $cleaned;
}

function verifyResidentInMasterlist($data, $ocrData) {
    global $conn;
    
    $ocrLastName = !empty($ocrData['last_name']) ? trim($ocrData['last_name']) : '';
    $ocrFirstName = !empty($ocrData['first_name']) ? trim($ocrData['first_name']) : '';
    $ocrMiddleName = !empty($ocrData['middle_name']) ? trim($ocrData['middle_name']) : '';
    $ocrDob = !empty($ocrData['date_of_birth']) ? $ocrData['date_of_birth'] : '';
    $ocrSex = !empty($ocrData['sex']) ? $ocrData['sex'] : '';
    
    if (strtoupper($ocrSex) == 'M' || strtoupper($ocrSex) == 'MALE') $ocrSex = 'M';
    elseif (strtoupper($ocrSex) == 'F' || strtoupper($ocrSex) == 'FEMALE') $ocrSex = 'F';
    else $ocrSex = '';
    
    if (empty($ocrLastName) || empty($ocrFirstName) || empty($ocrDob)) {
        return [
            'success' => false,
            'message' => '❌ Incomplete Information Extracted<br><br>' .
            'The following required fields could not be read from your ID:<br>' .
            (empty($ocrLastName) ? '• Last Name<br>' : '') .
            (empty($ocrFirstName) ? '• First Name<br>' : '') .
            (empty($ocrDob) ? '• Date of Birth<br>' : '') .
            '<br>💡 Please upload clearer, well-lit images.'
        ];
    }
    
    $combinations = [
        ['last' => $ocrLastName, 'first' => $ocrFirstName],
        ['last' => $ocrFirstName, 'first' => $ocrLastName],
        ['last' => $ocrMiddleName, 'first' => $ocrFirstName],
        ['last' => $ocrMiddleName, 'first' => $ocrLastName],
    ];
    
    $foundMatch = null;
    $foundDobMatch = false;
    
    foreach ($combinations as $combo) {
        $lastName = $combo['last'];
        $firstName = $combo['first'];
        
        if (empty($lastName) || empty($firstName)) continue;
        
        $query = "
            SELECT hm.*, h.address as household_address, h.purok
            FROM household_members hm
            JOIN households h ON hm.household_id = h.id
            WHERE hm.last_name = ? 
            AND hm.first_name = ?
            AND hm.date_of_birth = ?
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sss", $lastName, $firstName, $ocrDob);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $foundMatch = $result->fetch_assoc();
            $foundDobMatch = true;
            $stmt->close();
            break;
        }
        $stmt->close();
        
        $queryName = "
            SELECT hm.*, h.address as household_address, h.purok
            FROM household_members hm
            JOIN households h ON hm.household_id = h.id
            WHERE hm.last_name = ? 
            AND hm.first_name = ?
        ";
        
        $stmt = $conn->prepare($queryName);
        $stmt->bind_param("ss", $lastName, $firstName);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $foundMatch = $result->fetch_assoc();
            $foundDobMatch = false;
            $stmt->close();
            break;
        }
        $stmt->close();
    }
    
    if ($foundMatch) {
        $message = '✅ Resident Verified Successfully<br><br>';
        $message .= '<div style="background:#e8f5e9;padding:15px;border-radius:8px;">';
        $message .= '<p style="font-size:14px;">Your identity has been verified against the barangay masterlist.</p>';
        $message .= '<p style="font-size:13px;color:#555;"><strong>Name on ID:</strong> ' . htmlspecialchars($ocrFirstName . ' ' . $ocrLastName) . '</p>';
        
        if ($foundDobMatch) {
            $message .= '<p style="font-size:13px;color:#2e7d32;"><strong>✅ Date of Birth:</strong> Matches record</p>';
        } else {
            $message .= '<p style="color:#e67e22;font-size:13px;"><strong>⚠️ Date of Birth:</strong> Please verify and update if needed</p>';
        }
        
        if (!empty($foundMatch['household_address'])) {
            $message .= '<p style="font-size:13px;color:#555;"><strong>Address:</strong> ' . htmlspecialchars($foundMatch['household_address']) . '</p>';
        }
        if (!empty($foundMatch['purok'])) {
            $message .= '<p style="font-size:13px;color:#555;"><strong>Purok:</strong> ' . htmlspecialchars($foundMatch['purok']) . '</p>';
        }
        
        $message .= '<p style="font-size:13px;color:#666;margin-top:8px;">Please review the auto-filled information below.</p>';
        $message .= '</div>';
        
        return [
            'success' => true,
            'resident_data' => $foundMatch,
            'confidence' => $foundDobMatch ? 100 : 80,
            'message' => $message
        ];
    }
    
    $message = '❌ Resident Not Found in Masterlist<br><br>';
    $message .= '<div style="background:#ffebee;padding:15px;border-radius:8px;">';
    $message .= '<p style="font-size:14px;font-weight:600;color:#d33;">Information Extracted from Your ID:</p>';
    $message .= '<ul style="font-size:13px;list-style:none;padding:0;">';
    $message .= '<li style="padding:4px 0;"><strong>Name:</strong> ' . htmlspecialchars($ocrFirstName . ' ' . $ocrLastName) . '</li>';
    $message .= '<li style="padding:4px 0;"><strong>Date of Birth:</strong> ' . htmlspecialchars(date('F d, Y', strtotime($ocrDob))) . '</li>';
    if (!empty($ocrSex)) $message .= '<li style="padding:4px 0;"><strong>Sex:</strong> ' . htmlspecialchars($ocrSex) . '</li>';
    $message .= '</ul>';
    $message .= '<hr style="border:1px solid #ffcdd2;margin:10px 0;">';
    $message .= '<p style="color:#d33;font-weight:600;">⚠️ Cannot Proceed with Registration</p>';
    $message .= '<p style="font-size:13px;color:#666;">Please visit the barangay office to verify your registration or update your record in the masterlist.</p>';
    $message .= '</div>';
    
    return [
        'success' => false,
        'message' => $message
    ];
}

function checkAge($dob) {
    if (empty($dob)) {
        return ['valid' => false, 'message' => '❌ Date of Birth Missing<br><br>Please upload a clearer image of your ID.'];
    }
    
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
    
    if ($age < 18) {
        return [
            'valid' => false, 
            'message' => '❌ Age Restriction<br><br>You must be at least 18 years old to register.<br><br>Your age: <strong>' . $age . ' years old</strong><br><br>Only residents aged 18 and above are eligible.'
        ];
    }
    
    return ['valid' => true, 'age' => $age];
}

function processFullRegistration($data) {
    global $conn;
    
    $required = ['first_name', 'last_name', 'date_of_birth', 'gender', 'email', 'username', 'password', 'phone_number'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            return ['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required.', 'step' => 3];
        }
    }
    
    $stmt = $conn->prepare("SELECT id FROM resident WHERE username = ?");
    $stmt->bind_param("s", $data['username']);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        return ['success' => false, 'message' => 'Username is already taken. Please choose another.', 'step' => 4];
    }
    
    $stmt = $conn->prepare("SELECT id FROM resident WHERE email = ?");
    $stmt->bind_param("s", $data['email']);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        return ['success' => false, 'message' => 'Email is already registered. Please use a different email.', 'step' => 1];
    }
    
    $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
    
    $addressParts = [];
    if (!empty($data['address'])) {
        $addressParts[] = $data['address'];
    }
    $fullAddress = implode(', ', array_filter($addressParts));
    
    $stmt = $conn->prepare("
        INSERT INTO resident (
            username, email, phone_number, password, first_name, last_name, middle_name,
            date_of_birth, gender, address, scanned_document, is_verified, is_active, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, NOW())
    ");
    
    $scannedDoc = $_SESSION['id_image_front_path'] ?? '';
    $middleName = $data['middle_name'] ?? '';
    
    $stmt->bind_param(
        "sssssssssss",
        $data['username'],
        $data['email'],
        $data['phone_number'],
        $hashedPassword,
        $data['first_name'],
        $data['last_name'],
        $middleName,
        $data['date_of_birth'],
        $data['gender'],
        $fullAddress,
        $scannedDoc
    );
    
    if ($stmt->execute()) {
        return [
            'success' => true,
            'message' => 'Registration successful!',
            'username' => $data['username'],
            'id_type' => $_SESSION['ocr_data']['id_type'] ?? 'Government ID'
        ];
    } else {
        return ['success' => false, 'message' => 'Registration failed: ' . $conn->error, 'step' => 4];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Sign Up - BRITE</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        <?php include 'sign.css'; ?>
        
        .swal2-popup {
            animation: fadeInUp 0.5s ease-out;
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
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
            font-size: 14px;
            margin-bottom: 8px;
            transition: all 0.3s ease;
        }
        
        .step-item.active .step-circle {
            background: #1e7e6c;
            color: white;
            box-shadow: 0 0 0 3px rgba(30, 126, 108, 0.3);
        }
        
        .step-item.completed .step-circle {
            background: #1e7e6c;
            color: white;
        }
        
        .step-label {
            font-size: 12px;
            color: #999;
        }
        
        .step-item.active .step-label {
            color: #1e7e6c;
            font-weight: bold;
        }
        
        .form-step {
            display: none;
            animation: fadeIn 0.5s ease;
        }
        
        .form-step.active-step {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .navigation-buttons {
            display: flex;
            gap: 10px;
            margin-top: 25px;
        }
        
        .navigation-buttons button {
            flex: 1;
            padding: 12px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        .btn {
            background: #1e7e6c;
            color: white;
        }
        
        .btn:hover {
            background: #166b5a;
            transform: translateY(-2px);
        }
        
        .btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        
        .email-input-wrapper {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .email-input-wrapper input {
            flex: 1;
            padding: 12px 12px 12px 40px !important;
        }
        
        .email-input-wrapper button {
            flex-shrink: 0;
            padding: 12px 20px;
            white-space: nowrap;
            width: auto;
            min-width: 110px;
            font-size: 14px;
            background: #1e7e6c;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .email-input-wrapper button:hover {
            background: #166b5a;
            transform: translateY(-2px);
        }
        
        .email-input-wrapper button:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        
        .otp-input-wrapper input {
            font-size: 20px !important;
            letter-spacing: 10px !important;
            text-align: center !important;
            padding: 12px !important;
            font-weight: bold;
        }
        
        .email-verified-badge {
            background: #e8f5e9;
            padding: 10px 15px;
            border-radius: 8px;
            margin-top: 10px;
            color: #2e7d32;
            font-size: 13px;
            display: none;
        }
        
        .email-verified-badge i {
            margin-right: 8px;
        }
        
        .password-wrapper {
            position: relative;
            width: 100%;
        }
        
        .password-wrapper input {
            padding: 12px 40px 12px 40px !important;
        }
        
        .password-toggle-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #999;
            z-index: 2;
        }
        
        .password-toggle-icon:hover {
            color: #1e7e6c;
        }
        
        .password-requirements {
            margin-top: 8px;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 6px;
            font-size: 11px;
        }
        
        .password-requirements .req {
            margin: 3px 0;
            color: #999;
        }
        
        .password-requirements .req.valid {
            color: #1e7e6c;
        }
        
        .password-requirements .req.invalid {
            color: #d33;
        }
        
        /* ID Upload Boxes - Vertical Stack */
        .id-upload-container {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .id-upload-box {
            border: 2px dashed #ccc;
            border-radius: 12px;
            background: #fafafa;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            min-height: 160px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            width: 100%;
        }
        
        .id-upload-box:hover {
            border-color: #1e7e6c;
            background: #f0fdf4;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .id-upload-box.uploaded {
            border-color: #1e7e6c;
            border-style: solid;
            background: #e8f5e9;
        }
        
        .id-upload-box .box-content {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
        }
        
        .id-upload-box .box-icon {
            font-size: 36px;
            color: #1e7e6c;
            opacity: 0.5;
            transition: all 0.3s ease;
        }
        
        .id-upload-box.uploaded .box-icon {
            opacity: 0.3;
            font-size: 28px;
        }
        
        .id-upload-box .box-label {
            font-size: 14px;
            font-weight: 600;
            color: #333;
        }
        
        .id-upload-box .box-status {
            font-size: 12px;
            color: #999;
            transition: all 0.3s ease;
        }
        
        .id-upload-box .box-status i {
            margin-right: 5px;
        }
        
        .id-upload-box.uploaded .box-status {
            color: #1e7e6c;
        }
        
        .id-upload-box .box-preview {
            display: none;
            width: 100%;
            margin-top: 5px;
            border-radius: 8px;
            overflow: hidden;
            max-height: 120px;
        }
        
        .id-upload-box .box-preview img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            max-height: 120px;
            background: white;
        }
        
        .id-upload-box .box-preview.show {
            display: block;
        }
        
        .id-upload-box .box-actions {
            display: none;
            width: 100%;
            margin-top: 8px;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }
        
        .id-upload-box .box-actions.show {
            display: flex;
        }
        
        .id-upload-box .box-actions .file-name {
            font-size: 11px;
            color: #666;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 120px;
        }
        
        .id-upload-box .box-actions .btn-remove {
            background: #dc3545;
            color: white;
            border: none;
            padding: 3px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            transition: all 0.3s ease;
        }
        
        .id-upload-box .box-actions .btn-remove:hover {
            background: #c82333;
        }
        
        .id-upload-box .badge-uploaded {
            display: none;
            position: absolute;
            top: 10px;
            right: 10px;
            background: #1e7e6c;
            color: white;
            font-size: 10px;
            padding: 2px 10px;
            border-radius: 10px;
            font-weight: 600;
        }
        
        .id-upload-box.uploaded .badge-uploaded {
            display: block;
        }
        
        .btn-scan-id {
            background: #1e7e6c;
            color: white;
            border: none;
            padding: 14px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s ease;
            width: 100%;
            touch-action: manipulation;
            margin-bottom: 10px;
        }
        
        .btn-scan-id:hover {
            background: #166b5a;
            transform: translateY(-2px);
        }
        
        .btn-scan-id:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-scan-id i {
            margin-right: 8px;
        }
        
        /* Camera Container */
        .camera-container {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.95);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }
        
        .camera-container.active {
            display: flex;
        }
        
        .camera-container .camera-wrapper {
            position: relative;
            background: #000;
            border-radius: 12px;
            overflow: hidden;
            max-width: 500px;
            width: 95%;
            aspect-ratio: 4/5;
        }
        
        .camera-container .camera-wrapper video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            background: #000;
        }
        
        /* ID Guide Overlay - Proper ID card size (85.6mm x 54mm ratio ~ 1.586:1) */
        .id-guide {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 85%;
            height: 55%;
            border: 3px solid rgba(255, 255, 255, 0.6);
            border-radius: 8px;
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.5);
            pointer-events: none;
            animation: pulse-border 2s ease-in-out infinite;
        }
        
        /* For mobile, make it a bit bigger */
        @media (max-width: 768px) {
            .id-guide {
                width: 88%;
                height: 58%;
            }
        }
        
        .id-guide .corner {
            position: absolute;
            width: 20px;
            height: 20px;
            border-color: #43e97b;
            border-style: solid;
            border-width: 0;
        }
        
        .id-guide .corner-tl { top: -2px; left: -2px; border-top-width: 4px; border-left-width: 4px; border-radius: 4px 0 0 0; }
        .id-guide .corner-tr { top: -2px; right: -2px; border-top-width: 4px; border-right-width: 4px; border-radius: 0 4px 0 0; }
        .id-guide .corner-bl { bottom: -2px; left: -2px; border-bottom-width: 4px; border-left-width: 4px; border-radius: 0 0 0 4px; }
        .id-guide .corner-br { bottom: -2px; right: -2px; border-bottom-width: 4px; border-right-width: 4px; border-radius: 0 0 4px 0; }
        
        .id-guide .guide-text {
            position: absolute;
            bottom: -40px;
            left: 50%;
            transform: translateX(-50%);
            color: white;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
            text-shadow: 0 2px 4px rgba(0,0,0,0.8);
            white-space: nowrap;
        }
        
        .id-guide .guide-text .fa-arrows-alt {
            display: block;
            font-size: 20px;
            margin-bottom: 5px;
            color: #43e97b;
        }
        
        @keyframes pulse-border {
            0%, 100% { border-color: rgba(255, 255, 255, 0.6); }
            50% { border-color: rgba(67, 233, 123, 0.9); }
        }
        
        .camera-container .camera-controls {
            display: flex;
            gap: 20px;
            justify-content: center;
            align-items: center;
            padding: 20px;
            background: #1a1a1a;
            width: 100%;
            max-width: 500px;
            border-radius: 0 0 12px 12px;
        }
        
        .camera-container .camera-controls .btn-capture {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            border: 4px solid white;
            background: rgba(255,255,255,0.2);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            touch-action: manipulation;
        }
        
        .camera-container .camera-controls .btn-capture:hover {
            background: rgba(67, 233, 123, 0.3);
            transform: scale(1.05);
        }
        
        .camera-container .camera-controls .btn-capture .inner-circle {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            background: white;
            transition: all 0.3s ease;
        }
        
        .camera-container .camera-controls .btn-capture:hover .inner-circle {
            background: #43e97b;
        }
        
        .camera-container .camera-controls .btn-close-camera {
            padding: 12px 20px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            touch-action: manipulation;
        }
        
        .camera-container .camera-controls .btn-close-camera:hover {
            background: #c82333;
            transform: scale(1.05);
        }
        
        .camera-container .camera-step-label {
            color: white;
            font-size: 18px;
            font-weight: 700;
            padding: 15px;
            background: rgba(0,0,0,0.6);
            width: 100%;
            max-width: 500px;
            text-align: center;
            border-radius: 12px 12px 0 0;
        }
        
        .camera-container .camera-step-label .step-number {
            color: #43e97b;
        }
        
        /* Loading Overlay */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.85);
            z-index: 9998;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }
        
        .loading-overlay.active {
            display: flex;
        }
        
        .loading-overlay .loader {
            width: 60px;
            height: 60px;
            border: 4px solid rgba(67, 233, 123, 0.2);
            border-top: 4px solid #43e97b;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .loading-overlay .loading-text {
            color: white;
            font-size: 18px;
            font-weight: 600;
            margin-top: 20px;
        }
        
        .loading-overlay .loading-sub {
            color: rgba(255,255,255,0.6);
            font-size: 14px;
            margin-top: 8px;
        }
        
        .loading-overlay .loading-progress {
            width: 80%;
            max-width: 300px;
            height: 4px;
            background: rgba(255,255,255,0.1);
            border-radius: 2px;
            margin-top: 20px;
            overflow: hidden;
        }
        
        .loading-overlay .loading-progress .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #43e97b, #4facfe);
            border-radius: 2px;
            width: 0%;
            animation: progress-anim 3s ease-in-out forwards;
        }
        
        @keyframes progress-anim {
            0% { width: 0%; }
            20% { width: 20%; }
            50% { width: 50%; }
            80% { width: 80%; }
            100% { width: 100%; }
        }
        
        /* Verification Badge */
        .verification-badge {
            display: none;
            background: #e8f5e9;
            padding: 15px;
            border-radius: 10px;
            border-left: 4px solid #1e7e6c;
            margin-top: 15px;
        }
        
        .verification-badge.show {
            display: block;
        }
        
        .verification-badge .badge-icon {
            color: #1e7e6c;
            font-size: 20px;
            margin-right: 10px;
        }
        
        .verification-badge .badge-title {
            font-weight: 700;
            color: #1e7e6c;
            font-size: 16px;
        }
        
        .verification-badge .badge-details {
            color: #555;
            font-size: 13px;
            margin-top: 5px;
        }
        
        /* Step 2 layout */
        .step2-content {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .btn-next-ready {
            background: #1e7e6c;
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            touch-action: manipulation;
        }
        
        .btn-next-ready:hover {
            background: #166b5a;
            transform: translateY(-2px);
        }
        
        .btn-next-ready:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-next-ready .fa-check {
            margin-right: 8px;
        }
        
        .match-card {
            background: #e8f5e9;
            padding: 15px;
            border-radius: 10px;
            margin: 10px 0;
            border-left: 4px solid #1e7e6c;
        }
        
        .match-card h4 {
            color: #2e7d32;
            margin-bottom: 8px;
        }
        
        .match-card p {
            margin: 4px 0;
            font-size: 13px;
        }
        
        .match-card .label {
            font-weight: 600;
            color: #555;
        }
        
        .match-card .value {
            color: #333;
        }
        
        .success-badge {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid #1e7e6c;
        }
        
        .error-badge {
            background: #ffebee;
            color: #d33;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid #d33;
        }
        
        .info-box {
            background: #e3f2fd;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid #2196f3;
        }
        
        .info-box i {
            color: #2196f3;
            margin-right: 8px;
        }
        
        .document-info {
            background: #e8f5e9;
            padding: 12px 15px;
            border-radius: 8px;
            margin-top: 10px;
        }
        
        .document-info i {
            color: #1e7e6c;
            margin-right: 6px;
        }
        
        .document-info p {
            margin: 4px 0;
            font-size: 12px;
            color: #2e7d32;
        }
        
        .link {
            margin-top: 20px;
            text-align: center;
        }
        
        .link a {
            text-decoration: none;
        }
        
        .link button {
            padding: 10px 20px;
            background: #4facfe;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
        }
        
        .link button:hover {
            background: #3a8edb;
            transform: translateY(-2px);
        }
        
        .name-error {
            color: #d33;
            font-size: 11px;
            margin-top: 3px;
            display: none;
        }
        
        .success-check {
            color: #1e7e6c;
            font-size: 11px;
            margin-top: 3px;
            display: none;
        }
        
        /* ========== INPUT VALIDATION STYLES ========== */
        .input-group {
            margin-bottom: 18px;
            position: relative;
        }
        
        .input-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
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
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            box-sizing: border-box;
            background: #fafafa;
        }
        
        .input-group select:focus,
        .input-group input:focus {
            border-color: #1e7e6c;
            outline: none;
            box-shadow: 0 0 0 3px rgba(30, 126, 108, 0.1);
            background: white;
        }
        
        /* Validation States */
        .input-group input.valid,
        .input-group select.valid {
            border-color: #28a745;
            background-color: #f0fff4;
        }
        
        .input-group input.invalid,
        .input-group select.invalid {
            border-color: #dc3545;
            background-color: #fff5f5;
        }
        
        .input-group input.warning,
        .input-group select.warning {
            border-color: #ff9800;
            background-color: #fff8e6;
        }
        
        .input-group i {
            position: absolute;
            left: 12px;
            top: 40px;
            color: #999;
            z-index: 1;
            transition: color 0.3s ease;
        }
        
        .input-group input.valid + i,
        .input-group select.valid + i {
            color: #28a745;
        }
        
        .input-group input.invalid + i,
        .input-group select.invalid + i {
            color: #dc3545;
        }
        
        .input-group input.warning + i,
        .input-group select.warning + i {
            color: #ff9800;
        }
        
        /* Validation message icons */
        .input-group .validation-icon {
            position: absolute;
            right: 12px;
            top: 40px;
            font-size: 18px;
            display: none;
            z-index: 1;
        }
        
        .input-group input.valid ~ .validation-icon,
        .input-group select.valid ~ .validation-icon {
            display: block;
            color: #28a745;
        }
        
        .input-group input.invalid ~ .validation-icon,
        .input-group select.invalid ~ .validation-icon {
            display: block;
            color: #dc3545;
        }
        
        .input-group input.warning ~ .validation-icon,
        .input-group select.warning ~ .validation-icon {
            display: block;
            color: #ff9800;
        }
        
        .input-group .validation-msg {
            font-size: 11px;
            margin-top: 3px;
            display: none;
        }
        
        .input-group .validation-msg.valid {
            display: block;
            color: #28a745;
        }
        
        .input-group .validation-msg.invalid {
            display: block;
            color: #dc3545;
        }
        
        .input-group .validation-msg.warning {
            display: block;
            color: #ff9800;
        }
        
        /* Auto-fill highlight */
        .input-group input.auto-filled {
            border-color: #1e7e6c;
            background-color: #f0fdf4;
        }
        
        .input-group input.auto-filled + i {
            color: #1e7e6c;
        }
        
        @media (max-width: 768px) {
            .email-input-wrapper {
                flex-direction: column;
            }
            .email-input-wrapper button {
                width: 100%;
            }
            .step-item {
                min-width: 40px;
            }
            .step-circle {
                width: 32px;
                height: 32px;
                font-size: 12px;
            }
            .step-label {
                font-size: 9px;
            }
            .camera-container .camera-wrapper {
                aspect-ratio: 4/5;
            }
            .id-guide {
                width: 92%;
                height: 60%;
            }
            .id-guide .guide-text {
                font-size: 11px;
                bottom: -35px;
            }
            .id-guide .guide-text .fa-arrows-alt {
                font-size: 18px;
            }
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
            <p>Create your account to easily request documents, file complaints, and stay connected with your barangay.</p>
        </div>

        <div class="login-form">
            <div class="container">
                <h1 class="form-title">Create Account</h1>
                
                <?php if ($error_message): ?>
                <div class="error-badge">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($success_message): ?>
                <div class="success-badge">
                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                </div>
                <?php endif; ?>
                
                <!-- Step Indicator -->
                <div class="step-indicator">
                    <div class="step-item <?php echo $current_step >= 1 ? 'active' : ''; ?> <?php echo $current_step > 1 ? 'completed' : ''; ?>">
                        <div class="step-circle">1</div>
                        <div class="step-label">Email</div>
                    </div>
                    <div class="step-item <?php echo $current_step >= 2 ? 'active' : ''; ?> <?php echo $current_step > 2 ? 'completed' : ''; ?>">
                        <div class="step-circle">2</div>
                        <div class="step-label">Verify ID</div>
                    </div>
                    <div class="step-item <?php echo $current_step >= 3 ? 'active' : ''; ?> <?php echo $current_step > 3 ? 'completed' : ''; ?>">
                        <div class="step-circle">3</div>
                        <div class="step-label">Personal Info</div>
                    </div>
                    <div class="step-item <?php echo $current_step >= 4 ? 'active' : ''; ?> <?php echo $current_step > 4 ? 'completed' : ''; ?>">
                        <div class="step-circle">4</div>
                        <div class="step-label">Account</div>
                    </div>
                    <div class="step-item <?php echo $current_step >= 5 ? 'active' : ''; ?> <?php echo $current_step > 5 ? 'completed' : ''; ?>">
                        <div class="step-circle">5</div>
                        <div class="step-label">Submit</div>
                    </div>
                </div>
                
                <form method="post" id="signupForm" enctype="multipart/form-data">
                    <input type="hidden" name="current_step" id="current_step" value="<?php echo $current_step; ?>">
                    <input type="hidden" name="action" id="action" value="">
                    <input type="hidden" name="front_image_data" id="front_image_data" value="">
                    <input type="hidden" name="back_image_data" id="back_image_data" value="">
                    
                    <!-- ============ STEP 1: Email Verification ============ -->
                    <div class="form-step <?php echo $current_step == 1 ? 'active-step' : ''; ?>" id="step1">
                        <div class="info-box">
                            <i class="fas fa-info-circle"></i> Enter your email address to receive a verification code.
                        </div>
                        
                        <div class="input-group">
                            <label for="email">Email Address</label>
                            <i class="fas fa-envelope"></i>
                            <span class="validation-icon" id="email_validation_icon"></span>
                            <div class="email-input-wrapper">
                                <input type="email" name="email" id="email" placeholder="Enter your email address" 
                                       value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>" 
                                       oninput="checkEmailAvailability()" required>
                                <button type="button" id="sendOtpBtn" onclick="sendOTP()" disabled>Send OTP</button>
                            </div>
                            <div id="email_error" class="name-error"><span></span></div>
                            <div id="email_success" class="success-check">✓ Email is available</div>
                        </div>
                        
                        <div id="otpSection" style="display: none;">
                            <div class="input-group">
                                <label for="otp_code">Enter OTP Code</label>
                                <i class="fas fa-key"></i>
                                <div class="otp-input-wrapper">
                                    <input type="text" name="otp_code" id="otp_code" placeholder="••••••" maxlength="6">
                                </div>
                                <div id="otp_error" class="name-error"><span></span></div>
                                <div id="otp_success" class="success-check">✓ Email verified!</div>
                                <div style="font-size:11px; color:#999; margin-top:5px;">OTP expires in 5 minutes</div>
                            </div>
                        </div>
                        
                        <div id="verifiedBadge" class="email-verified-badge">
                            <i class="fas fa-check-circle"></i> Email address verified successfully!
                        </div>
                        
                        <div class="navigation-buttons">
                            <button type="button" class="btn" onclick="validateStep1AndNext()">Next</button>
                        </div>
                    </div>
                    
                    <!-- ============ STEP 2: ID Verification ============ -->
                    <div class="form-step <?php echo $current_step == 2 ? 'active-step' : ''; ?>" id="step2">
                        <div class="info-box">
                            <i class="fas fa-info-circle"></i> Upload a photo of your ID or take a photo with your camera. The system will automatically detect your ID type.
                        </div>
                        
                        <div class="step2-content">
                            <!-- ID Upload Container - Vertical Stack -->
                            <div class="id-upload-container">
                                <!-- Front ID Box -->
                                <div class="id-upload-box" id="frontBox" onclick="triggerUpload('front')">
                                    <div class="badge-uploaded">✓ Uploaded</div>
                                    <div class="box-content">
                                        <div class="box-icon">
                                            <i class="fas fa-id-card"></i>
                                        </div>
                                        <div class="box-label">Front of ID</div>
                                        <div class="box-status" id="frontStatus">
                                            <i class="fas fa-cloud-upload-alt"></i> Tap to upload
                                        </div>
                                        <div class="box-preview" id="frontPreview">
                                            <img id="frontPreviewImg" src="">
                                        </div>
                                        <div class="box-actions" id="frontActions">
                                            <span class="file-name" id="frontFileName">image.jpg</span>
                                            <button type="button" class="btn-remove" onclick="event.stopPropagation(); removeIDPhoto('front')">
                                                <i class="fas fa-times"></i> Remove
                                            </button>
                                        </div>
                                    </div>
                                    <input type="file" id="front_file_input" accept="image/*" style="display:none;" onchange="handleFileUpload('front', this)">
                                </div>
                                
                                <!-- Back ID Box -->
                                <div class="id-upload-box" id="backBox" onclick="triggerUpload('back')">
                                    <div class="badge-uploaded">✓ Uploaded</div>
                                    <div class="box-content">
                                        <div class="box-icon">
                                            <i class="fas fa-id-card"></i>
                                        </div>
                                        <div class="box-label">Back of ID</div>
                                        <div class="box-status" id="backStatus">
                                            <i class="fas fa-cloud-upload-alt"></i> Tap to upload
                                        </div>
                                        <div class="box-preview" id="backPreview">
                                            <img id="backPreviewImg" src="">
                                        </div>
                                        <div class="box-actions" id="backActions">
                                            <span class="file-name" id="backFileName">image.jpg</span>
                                            <button type="button" class="btn-remove" onclick="event.stopPropagation(); removeIDPhoto('back')">
                                                <i class="fas fa-times"></i> Remove
                                            </button>
                                        </div>
                                    </div>
                                    <input type="file" id="back_file_input" accept="image/*" style="display:none;" onchange="handleFileUpload('back', this)">
                                </div>
                            </div>
                            
                            <!-- Take Photo Button -->
                            <button type="button" class="btn-scan-id" id="scanIdBtn" onclick="startIDScan()">
                                <i class="fas fa-camera"></i> Scan ID with Camera
                            </button>
                            
                            <!-- Detected ID Type Display -->
                            <div id="detectedIdTypeDisplay" style="display: none; background: #e3f2fd; padding: 10px 15px; border-radius: 8px; border-left: 4px solid #2196f3; margin-top: 5px;">
                                <i class="fas fa-id-card" style="color: #2196f3; margin-right: 8px;"></i>
                                <span style="font-weight: 600;">Detected ID Type:</span>
                                <span id="detectedIdTypeText"><?php echo htmlspecialchars($detected_id_type ?: ''); ?></span>
                            </div>
                            
                            <!-- Verification Badge - Only show when actually verified AND no errors -->
                            <div class="verification-badge <?php echo ($id_verified && empty($_SESSION['verification_errors'])) ? 'show' : ''; ?>" id="verificationBadge">
                                <div>
                                    <i class="fas fa-check-circle badge-icon"></i>
                                    <span class="badge-title">Identity Verified Successfully</span>
                                    <div class="badge-details">
                                        <?php if (!empty($ocr_data['first_name']) && !empty($ocr_data['last_name'])): ?>
                                        <p><strong>Name:</strong> <?php echo htmlspecialchars($ocr_data['first_name'] . ' ' . $ocr_data['last_name']); ?></p>
                                        <p><strong>Date of Birth:</strong> <?php echo htmlspecialchars(date('F d, Y', strtotime($ocr_data['date_of_birth'] ?? ''))); ?></p>
                                        <p><strong>ID Type:</strong> <?php echo htmlspecialchars($detected_id_type ?: 'Auto-detected'); ?></p>
                                        <p><strong>Status:</strong> <span style="color:#1e7e6c;">✓ Verified</span></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Next Button -->
                            <button type="button" class="btn-next-ready" id="nextBtn" <?php echo ($id_verified && empty($_SESSION['verification_errors'])) ? '' : 'disabled'; ?> onclick="goToNextStep()">
                                <i class="fas fa-check"></i> Proceed to Personal Information
                            </button>
                        </div>
                    </div>
                    
                    <!-- ============ STEP 3: Personal Information ============ -->
                    <div class="form-step <?php echo $current_step == 3 ? 'active-step' : ''; ?>" id="step3">
                        <div class="info-box">
                            <i class="fas fa-info-circle"></i> Information auto-filled from your ID. Please review and complete.
                        </div>
                        
                        <?php if (!empty($resident_match) && $id_verified): ?>
                        <div class="match-card">
                            <h4><i class="fas fa-check-circle"></i> Resident Verified</h4>
                            <p><span class="label">Address:</span> <span class="value"><?php echo htmlspecialchars($resident_match['household_address'] ?? ''); ?></span></p>
                            <p><span class="label">Purok:</span> <span class="value"><?php echo htmlspecialchars($resident_match['purok'] ?? ''); ?></span></p>
                            <p><span class="label">Match Confidence:</span> <span class="value"><?php echo round($verification_status['confidence'] ?? 0); ?>%</span></p>
                        </div>
                        <?php endif; ?>
                        
                        <div class="input-group">
                            <label for="first_name">First Name</label>
                            <i class="fas fa-user"></i>
                            <span class="validation-icon" id="first_name_icon"></span>
                            <input type="text" name="first_name" id="first_name" placeholder="Enter your first name" 
                                   value="<?php echo htmlspecialchars($form_data['first_name'] ?? ''); ?>" 
                                   oninput="validatePersonalInfo('first_name')" required>
                            <div class="validation-msg" id="first_name_msg"></div>
                        </div>
                        
                        <div class="input-group">
                            <label for="last_name">Last Name</label>
                            <i class="fas fa-user"></i>
                            <span class="validation-icon" id="last_name_icon"></span>
                            <input type="text" name="last_name" id="last_name" placeholder="Enter your last name" 
                                   value="<?php echo htmlspecialchars($form_data['last_name'] ?? ''); ?>" 
                                   oninput="validatePersonalInfo('last_name')" required>
                            <div class="validation-msg" id="last_name_msg"></div>
                        </div>
                        
                        <div class="input-group">
                            <label for="middle_name">Middle Name <span class="optional">(Optional)</span></label>
                            <i class="fas fa-user"></i>
                            <span class="validation-icon" id="middle_name_icon"></span>
                            <input type="text" name="middle_name" id="middle_name" placeholder="Enter your middle name" 
                                   value="<?php echo htmlspecialchars($form_data['middle_name'] ?? ''); ?>" 
                                   oninput="validatePersonalInfo('middle_name')">
                            <div class="validation-msg" id="middle_name_msg"></div>
                        </div>
                        
                        <div class="input-group">
                            <label for="date_of_birth">Date of Birth</label>
                            <i class="fas fa-calendar-alt"></i>
                            <span class="validation-icon" id="date_of_birth_icon"></span>
                            <input type="date" name="date_of_birth" id="date_of_birth" 
                                   value="<?php echo htmlspecialchars($form_data['date_of_birth'] ?? ''); ?>" 
                                   onchange="validatePersonalInfo('date_of_birth'); calculateAge();" required>
                            <div class="validation-msg" id="date_of_birth_msg"></div>
                            <div id="age_display" style="font-size:12px; margin-top:3px; color:#666;"></div>
                        </div>
                        
                        <div class="input-group">
                            <label for="gender">Gender</label>
                            <i class="fas fa-venus-mars"></i>
                            <span class="validation-icon" id="gender_icon"></span>
                            <select name="gender" id="gender" onchange="validatePersonalInfo('gender')" required>
                                <option value="" disabled <?php echo empty($form_data['gender']) ? 'selected' : ''; ?>>Select Gender</option>
                                <option value="Male" <?php echo ($form_data['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($form_data['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                            <div class="validation-msg" id="gender_msg"></div>
                        </div>
                        
                        <div class="input-group">
                            <label for="phone_number">Phone Number</label>
                            <i class="fas fa-phone-alt"></i>
                            <span class="validation-icon" id="phone_number_icon"></span>
                            <input type="tel" name="phone_number" id="phone_number" placeholder="09123456789" 
                                   value="<?php echo htmlspecialchars($form_data['phone_number'] ?? ''); ?>" 
                                   oninput="validatePersonalInfo('phone_number')" maxlength="13" required>
                            <div class="validation-msg" id="phone_number_msg"></div>
                        </div>
                        
                        <div class="input-group">
                            <label for="address">Complete Address</label>
                            <i class="fas fa-map-marker-alt"></i>
                            <span class="validation-icon" id="address_icon"></span>
                            <input type="text" name="address" id="address" placeholder="Enter your complete address" 
                                   value="<?php echo htmlspecialchars($form_data['address'] ?? ''); ?>" 
                                   oninput="validatePersonalInfo('address')" required>
                            <div class="validation-msg" id="address_msg"></div>
                            <div style="font-size:11px; color:#999; margin-top:3px;">
                                <i class="fas fa-info-circle"></i> Include house number, street, barangay, city/municipality, province
                            </div>
                        </div>
                        
                        <div class="input-group">
                            <label for="purok">Purok</label>
                            <i class="fas fa-tag"></i>
                            <span class="validation-icon" id="purok_icon"></span>
                            <input type="text" name="purok" id="purok" placeholder="Enter purok number or name" 
                                   value="<?php echo htmlspecialchars($form_data['purok'] ?? ''); ?>" 
                                   oninput="validatePersonalInfo('purok')" required>
                            <div class="validation-msg" id="purok_msg"></div>
                            <div style="font-size:11px; color:#999; margin-top:3px;">
                                <i class="fas fa-info-circle"></i> Enter the purok number or name (e.g., 1, 2, Purok 3, etc.)
                            </div>
                        </div>
                        
                        <div class="navigation-buttons">
                            <button type="button" class="btn-secondary" onclick="prevStep()">Previous</button>
                            <button type="button" class="btn" onclick="validateStep3AndNext()">Next</button>
                        </div>
                    </div>
                    
                    <!-- ============ STEP 4: Account Setup ============ -->
                    <div class="form-step <?php echo $current_step == 4 ? 'active-step' : ''; ?>" id="step4">
                        <div class="info-box">
                            <i class="fas fa-info-circle"></i> Choose your username and password.
                        </div>
                        
                        <div class="input-group">
                            <label for="username">Username</label>
                            <i class="fas fa-user-circle"></i>
                            <span class="validation-icon" id="username_icon"></span>
                            <input type="text" name="username" id="username" placeholder="Choose a username" 
                                   value="<?php echo htmlspecialchars($form_data['username'] ?? ''); ?>" 
                                   oninput="validatePersonalInfo('username')" required>
                            <div class="validation-msg" id="username_msg"></div>
                        </div>
                        
                        <div class="input-group">
                            <label for="password">Password</label>
                            <div class="password-wrapper">
                                <i class="fas fa-lock" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#999; z-index:1;"></i>
                                <span class="validation-icon" id="password_icon" style="right:45px; left:auto;"></span>
                                <input type="password" name="password" id="password" placeholder="Create a strong password" 
                                       oninput="validatePersonalInfo('password')" required>
                                <i class="fas fa-eye password-toggle-icon" id="togglePassword"></i>
                            </div>
                            <div class="validation-msg" id="password_msg"></div>
                            <div class="password-requirements" id="password_requirements">
                                <div class="req" id="req_length">• At least 8 characters</div>
                                <div class="req" id="req_uppercase">• At least one uppercase letter</div>
                                <div class="req" id="req_lowercase">• At least one lowercase letter</div>
                                <div class="req" id="req_number">• At least one number</div>
                                <div class="req" id="req_special">• At least one special character</div>
                            </div>
                        </div>
                        
                        <div class="input-group">
                            <label for="confirm_password">Confirm Password</label>
                            <div class="password-wrapper">
                                <i class="fas fa-lock" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#999; z-index:1;"></i>
                                <span class="validation-icon" id="confirm_password_icon" style="right:45px; left:auto;"></span>
                                <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm your password" 
                                       oninput="validatePersonalInfo('confirm_password')" required>
                                <i class="fas fa-eye password-toggle-icon" id="toggleConfirmPassword"></i>
                            </div>
                            <div class="validation-msg" id="confirm_password_msg"></div>
                        </div>
                        
                        <div class="navigation-buttons">
                            <button type="button" class="btn-secondary" onclick="prevStep()">Previous</button>
                            <button type="button" class="btn" onclick="validateStep4AndNext()">Next</button>
                        </div>
                    </div>
                    
                    <!-- ============ STEP 5: Submit ============ -->
                    <div class="form-step <?php echo $current_step == 5 ? 'active-step' : ''; ?>" id="step5">
                        <div style="background:#f8f9fa; padding:15px; border-radius:10px; margin-bottom:20px;">
                            <h3 style="margin-top:0; font-size:16px;">Review Your Information</h3>
                            <p><strong>Name:</strong> <span id="review_name"></span></p>
                            <p><strong>Email:</strong> <span id="review_email"></span></p>
                            <p><strong>Phone:</strong> <span id="review_phone"></span></p>
                            <p><strong>Username:</strong> <span id="review_username"></span></p>
                            <p><strong>Address:</strong> <span id="review_address"></span></p>
                            <p><strong>Purok:</strong> <span id="review_purok"></span></p>
                            <p><strong>ID Type:</strong> <span id="review_id_type"><?php echo htmlspecialchars($detected_id_type ?: 'Auto-detected'); ?></span></p>
                        </div>
                        
                        <div style="background:#e8f5e9; padding:15px; border-radius:10px; margin-bottom:20px;">
                            <p><i class="fas fa-check-circle" style="color:#1e7e6c;"></i> <strong>Verification Complete:</strong></p>
                            <ul style="font-size:13px; color:#2e7d32; margin:5px 0; padding-left:20px;">
                                <li>✅ Email verified</li>
                                <li>✅ ID verified (Front & Back)</li>
                                <li>✅ Resident verified</li>
                                <li>✅ Age verified (18+)</li>
                            </ul>
                        </div>
                        
                        <div class="navigation-buttons">
                            <button type="button" class="btn-secondary" onclick="prevStep()">Previous</button>
                            <button type="button" class="btn" id="submitButton" onclick="submitForm()">Submit Registration</button>
                        </div>
                    </div>
                </form>
                
                <div class="link">
                    <p>Already have an account?</p>
                    <a href="sign_in.php"><button type="button">Sign In</button></a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ===== CAMERA OVERLAY ===== -->
    <div class="camera-container" id="cameraOverlay">
        <div class="camera-step-label" id="cameraStepLabel">
            <span class="step-number" id="cameraStepNumber">Step 1 of 2</span>
            <span id="cameraSideLabel">📸 Scanning Front of ID</span>
        </div>
        <div class="camera-wrapper">
            <video id="cameraVideo" autoplay playsinline muted></video>
            <div class="id-guide" id="idGuide">
                <div class="corner corner-tl"></div>
                <div class="corner corner-tr"></div>
                <div class="corner corner-bl"></div>
                <div class="corner corner-br"></div>
                <div class="guide-text">
                    <i class="fas fa-arrows-alt"></i>
                    Align your ID within the frame
                    <span style="display:block; font-size:11px; color:#43e97b; margin-top:5px;">Tap capture when ready</span>
                </div>
            </div>
        </div>
        <div class="camera-controls">
            <button type="button" class="btn-close-camera" onclick="closeCamera()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="button" class="btn-capture" onclick="captureFromCamera()" id="captureBtn">
                <div class="inner-circle"></div>
            </button>
        </div>
    </div>
    
    <!-- ===== LOADING OVERLAY ===== -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loader"></div>
        <div class="loading-text" id="loadingText">Verifying ID...</div>
        <div class="loading-sub" id="loadingSub">Please wait while we verify your ID</div>
        <div class="loading-progress">
            <div class="progress-bar"></div>
        </div>
    </div>

    <script>
        // ==================== VARIABLES ====================
        let emailAvailable = false;
        let usernameAvailable = false;
        let isPasswordStrong = false;
        let isPhoneValid = false;
        let isEmailVerified = false;
        let countdownTimer = null;
        let cameraStream = null;
        let currentCameraType = 'front';
        let isFrontCaptured = false;
        let isBackCaptured = false;
        let isProcessing = false;
        let verificationSuccess = false;

        // ==================== REAL-TIME VALIDATION ====================
        function validatePersonalInfo(field) {
            const value = document.getElementById(field)?.value || '';
            const icon = document.getElementById(field + '_icon');
            const msg = document.getElementById(field + '_msg');
            const input = document.getElementById(field);
            
            if (!icon || !msg || !input) return;
            
            input.classList.remove('valid', 'invalid', 'warning', 'auto-filled');
            
            if (value.trim() !== '' && ['first_name', 'last_name', 'middle_name', 'date_of_birth', 'gender', 'address'].includes(field)) {
                input.classList.add('auto-filled');
            }
            
            let isValid = true;
            let isWarning = false;
            let message = '';
            
            switch(field) {
                case 'first_name':
                case 'last_name':
                    if (value.trim() === '') {
                        isValid = false;
                        message = 'This field is required';
                    } else if (value.trim().length < 2) {
                        isValid = false;
                        message = 'Must be at least 2 characters';
                    } else {
                        message = '✓ Looks good';
                    }
                    break;
                    
                case 'middle_name':
                    if (value.trim() !== '' && value.trim().length < 2) {
                        isValid = false;
                        message = 'If provided, must be at least 2 characters';
                    } else if (value.trim() !== '') {
                        message = '✓ Optional - OK';
                    } else {
                        message = '✓ Optional';
                    }
                    break;
                    
                case 'date_of_birth':
                    if (value === '') {
                        isValid = false;
                        message = 'Please select your date of birth';
                    } else {
                        const birthDate = new Date(value);
                        const today = new Date();
                        let age = today.getFullYear() - birthDate.getFullYear();
                        const monthDiff = today.getMonth() - birthDate.getMonth();
                        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) age--;
                        if (age < 18) {
                            isValid = false;
                            message = 'You must be 18 or older (Age: ' + age + ')';
                        } else {
                            message = '✓ Age: ' + age + ' (Valid)';
                        }
                    }
                    break;
                    
                case 'gender':
                    if (value === '') {
                        isValid = false;
                        message = 'Please select your gender';
                    } else {
                        message = '✓ Selected';
                    }
                    break;
                    
                case 'phone_number':
                    const phone = value.replace(/[^0-9]/g, '');
                    if (phone === '') {
                        isValid = false;
                        message = 'Phone number is required';
                    } else if (phone.length < 10) {
                        isValid = false;
                        message = 'Must be at least 10 digits';
                    } else if (phone.length > 13) {
                        isValid = false;
                        message = 'Must not exceed 13 digits';
                    } else {
                        message = '✓ Valid phone number';
                    }
                    break;
                    
                case 'address':
                    if (value.trim() === '') {
                        isValid = false;
                        message = 'Address is required';
                    } else if (value.trim().length < 5) {
                        isValid = false;
                        message = 'Please enter a complete address';
                    } else {
                        message = '✓ Address provided';
                    }
                    break;
                    
                case 'purok':
                    if (value.trim() === '') {
                        isValid = false;
                        message = 'Purok is required';
                    } else {
                        message = '✓ Purok provided';
                    }
                    break;
                    
                case 'username':
                    if (value.trim() === '') {
                        isValid = false;
                        message = 'Username is required';
                    } else if (value.trim().length < 3) {
                        isValid = false;
                        message = 'Must be at least 3 characters';
                    } else if (value.trim().length > 20) {
                        isValid = false;
                        message = 'Must not exceed 20 characters';
                    } else if (!/^[a-zA-Z0-9_]+$/.test(value)) {
                        isValid = false;
                        message = 'Only letters, numbers, and underscores allowed';
                    } else {
                        fetch('check_availability.php?username=' + encodeURIComponent(value.trim()))
                            .then(response => response.json())
                            .then(data => {
                                if (data.exists) {
                                    input.classList.remove('valid');
                                    input.classList.add('invalid');
                                    icon.className = 'validation-icon fas fa-times';
                                    msg.className = 'validation-msg invalid';
                                    msg.textContent = '❌ Username already taken';
                                    usernameAvailable = false;
                                } else {
                                    input.classList.remove('invalid');
                                    input.classList.add('valid');
                                    icon.className = 'validation-icon fas fa-check';
                                    msg.className = 'validation-msg valid';
                                    msg.textContent = '✓ Username is available';
                                    usernameAvailable = true;
                                }
                            })
                            .catch(() => {});
                        return;
                    }
                    break;
                    
                case 'password':
                    const hasLength = value.length >= 8;
                    const hasUppercase = /[A-Z]/.test(value);
                    const hasLowercase = /[a-z]/.test(value);
                    const hasNumber = /[0-9]/.test(value);
                    const hasSpecial = /[^A-Za-z0-9]/.test(value);
                    
                    updateReq('req_length', hasLength);
                    updateReq('req_uppercase', hasUppercase);
                    updateReq('req_lowercase', hasLowercase);
                    updateReq('req_number', hasNumber);
                    updateReq('req_special', hasSpecial);
                    
                    isPasswordStrong = hasLength && hasUppercase && hasLowercase && hasNumber && hasSpecial;
                    
                    if (value === '') {
                        isValid = false;
                        message = 'Password is required';
                    } else if (!isPasswordStrong) {
                        isValid = false;
                        message = 'Does not meet all requirements';
                    } else {
                        message = '✓ Strong password!';
                    }
                    
                    const confirmVal = document.getElementById('confirm_password')?.value || '';
                    if (confirmVal && isValid) {
                        validatePersonalInfo('confirm_password');
                    }
                    break;
                    
                case 'confirm_password':
                    const passwordVal = document.getElementById('password')?.value || '';
                    if (value === '') {
                        isValid = false;
                        message = 'Please confirm your password';
                    } else if (value !== passwordVal) {
                        isValid = false;
                        message = 'Passwords do not match!';
                    } else {
                        message = '✓ Passwords match';
                    }
                    break;
                    
                default:
                    return;
            }
            
            if (field !== 'username') {
                if (isValid) {
                    input.classList.add('valid');
                    icon.className = 'validation-icon fas fa-check';
                    msg.className = 'validation-msg valid';
                } else if (isWarning) {
                    input.classList.add('warning');
                    icon.className = 'validation-icon fas fa-exclamation-triangle';
                    msg.className = 'validation-msg warning';
                } else {
                    input.classList.add('invalid');
                    icon.className = 'validation-icon fas fa-times';
                    msg.className = 'validation-msg invalid';
                }
                msg.textContent = message;
            }
        }

        function updateReq(id, valid) {
            const el = document.getElementById(id);
            if (el) {
                el.className = 'req ' + (valid ? 'valid' : 'invalid');
                const text = el.textContent.substring(2);
                el.innerHTML = (valid ? '✅' : '❌') + ' ' + text;
            }
        }

        // ==================== STEP 1: Email ====================
        function isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }

        function checkEmailAvailability() {
            const email = document.getElementById('email').value.trim();
            const errorDiv = document.getElementById('email_error');
            const successDiv = document.getElementById('email_success');
            const sendBtn = document.getElementById('sendOtpBtn');
            const input = document.getElementById('email');
            const icon = document.getElementById('email_validation_icon');
            
            if (isEmailVerified) {
                isEmailVerified = false;
                document.getElementById('otpSection').style.display = 'none';
                document.getElementById('verifiedBadge').style.display = 'none';
                document.getElementById('otp_code').value = '';
                document.getElementById('otp_code').disabled = false;
                sendBtn.textContent = 'Send OTP';
            }
            
            if (!email) { 
                errorDiv.style.display = 'none'; 
                successDiv.style.display = 'none'; 
                emailAvailable = false; 
                sendBtn.disabled = true;
                input.classList.remove('valid', 'invalid');
                if (icon) icon.className = 'validation-icon';
                return; 
            }
            
            if (!isValidEmail(email)) {
                errorDiv.querySelector('span').innerHTML = 'Invalid email';
                errorDiv.style.display = 'block';
                successDiv.style.display = 'none';
                emailAvailable = false;
                sendBtn.disabled = true;
                input.classList.remove('valid');
                input.classList.add('invalid');
                if (icon) icon.className = 'validation-icon fas fa-times';
                return;
            }
            
            fetch('check_availability.php?email=' + encodeURIComponent(email))
                .then(response => response.json())
                .then(data => {
                    if (data.exists) {
                        errorDiv.querySelector('span').innerHTML = 'Email already registered';
                        errorDiv.style.display = 'block';
                        successDiv.style.display = 'none';
                        emailAvailable = false;
                        sendBtn.disabled = true;
                        input.classList.remove('valid');
                        input.classList.add('invalid');
                        if (icon) icon.className = 'validation-icon fas fa-times';
                    } else {
                        errorDiv.style.display = 'none';
                        successDiv.style.display = 'block';
                        emailAvailable = true;
                        sendBtn.disabled = false;
                        input.classList.remove('invalid');
                        input.classList.add('valid');
                        if (icon) icon.className = 'validation-icon fas fa-check';
                    }
                })
                .catch(() => {});
        }

        function sendOTP() {
            const email = document.getElementById('email').value.trim();
            const sendBtn = document.getElementById('sendOtpBtn');
            
            if (!email || !emailAvailable) { 
                showToast('error', 'Please enter a valid email');
                return; 
            }
            
            sendBtn.disabled = true;
            sendBtn.textContent = 'Sending...';
            
            fetch('send_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'send_otp', email: email })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('success', 'OTP sent! Check your email.');
                    document.getElementById('otpSection').style.display = 'block';
                    document.getElementById('otp_code').value = '';
                    document.getElementById('otp_code').focus();
                    startResendCountdown();
                } else {
                    showToast('error', data.message);
                    sendBtn.disabled = false;
                    sendBtn.textContent = 'Send OTP';
                }
            })
            .catch(() => {
                showToast('error', 'Failed to send OTP');
                sendBtn.disabled = false;
                sendBtn.textContent = 'Send OTP';
            });
        }

        function startResendCountdown() {
            const sendBtn = document.getElementById('sendOtpBtn');
            let timeLeft = 60;
            if (countdownTimer) clearInterval(countdownTimer);
            countdownTimer = setInterval(function() {
                if (timeLeft <= 0) {
                    clearInterval(countdownTimer);
                    sendBtn.disabled = false;
                    sendBtn.textContent = 'Resend OTP';
                } else {
                    sendBtn.disabled = true;
                    sendBtn.textContent = 'Resend in ' + timeLeft + 's';
                    timeLeft--;
                }
            }, 1000);
        }

        function verifyOTP(otp) {
            const errorDiv = document.getElementById('otp_error');
            const successDiv = document.getElementById('otp_success');
            const otpInput = document.getElementById('otp_code');
            
            fetch('send_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'verify_otp', otp: otp })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    errorDiv.style.display = 'none';
                    successDiv.style.display = 'block';
                    isEmailVerified = true;
                    document.getElementById('verifiedBadge').style.display = 'block';
                    otpInput.disabled = true;
                    document.getElementById('sendOtpBtn').disabled = true;
                    document.getElementById('sendOtpBtn').textContent = 'Verified';
                    showToast('success', 'Email verified successfully!');
                } else {
                    errorDiv.querySelector('span').innerHTML = data.message;
                    errorDiv.style.display = 'block';
                    successDiv.style.display = 'none';
                    if (otpInput.value.length === 6) {
                        setTimeout(() => { otpInput.value = ''; otpInput.focus(); }, 500);
                    }
                }
            })
            .catch(() => {
                showToast('error', 'Failed to verify OTP');
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const otpInput = document.getElementById('otp_code');
            if (otpInput) {
                otpInput.addEventListener('input', function() {
                    if (this.value.length === 6 && /^\d+$/.test(this.value)) {
                        verifyOTP(this.value);
                    }
                });
            }
            calculateAge();
            updateReview();
            
            ['first_name', 'last_name', 'middle_name', 'date_of_birth', 'gender', 'phone_number', 'address', 'purok'].forEach(function(field) {
                if (document.getElementById(field)) {
                    validatePersonalInfo(field);
                }
            });
            
            const detectedType = document.getElementById('detectedIdTypeText')?.textContent;
            if (detectedType && detectedType.trim() !== '') {
                document.getElementById('detectedIdTypeDisplay').style.display = 'block';
            }
        });

        function validateStep1() {
            const email = document.getElementById('email').value.trim();
            if (!email || !isValidEmail(email)) { 
                showToast('error', 'Please enter a valid email');
                return false; 
            }
            if (!emailAvailable) { 
                showToast('error', 'Email already registered');
                return false; 
            }
            if (!isEmailVerified) { 
                showToast('error', 'Please verify your email with OTP');
                return false; 
            }
            return true;
        }

        function validateStep1AndNext() {
            if (validateStep1()) nextStep();
        }

        // ==================== STEP 2: Upload Boxes ====================
        function triggerUpload(type) {
            if (isProcessing) return;
            
            if (type === 'front' && isFrontCaptured) {
                Swal.fire({
                    title: 'Replace Front ID?',
                    text: 'You already have a front ID uploaded. Do you want to replace it?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, replace',
                    cancelButtonText: 'Cancel',
                    allowOutsideClick: false,
                    allowEscapeKey: false
                }).then((result) => {
                    if (result.isConfirmed) {
                        removeIDPhoto('front');
                        setTimeout(() => {
                            document.getElementById('front_file_input').click();
                        }, 300);
                    }
                });
                return;
            }
            
            if (type === 'back' && isBackCaptured) {
                Swal.fire({
                    title: 'Replace Back ID?',
                    text: 'You already have a back ID uploaded. Do you want to replace it?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, replace',
                    cancelButtonText: 'Cancel',
                    allowOutsideClick: false,
                    allowEscapeKey: false
                }).then((result) => {
                    if (result.isConfirmed) {
                        removeIDPhoto('back');
                        setTimeout(() => {
                            document.getElementById('back_file_input').click();
                        }, 300);
                    }
                });
                return;
            }
            
            if (type === 'front') {
                document.getElementById('front_file_input').click();
            } else {
                document.getElementById('back_file_input').click();
            }
        }

        function handleFileUpload(type, input) {
            const file = input.files[0];
            if (!file) return;
            
            if (file.size > 5 * 1024 * 1024) {
                showToast('error', 'File must be less than 5MB');
                input.value = '';
                return;
            }
            
            const reader = new FileReader();
            reader.onload = function(event) {
                const imageData = event.target.result;
                
                if (type === 'front') {
                    document.getElementById('front_image_data').value = imageData;
                    document.getElementById('frontPreviewImg').src = imageData;
                    document.getElementById('frontPreview').classList.add('show');
                    document.getElementById('frontActions').classList.add('show');
                    document.getElementById('frontBox').classList.add('uploaded');
                    document.getElementById('frontFileName').textContent = file.name;
                    document.getElementById('frontStatus').innerHTML = '<i class="fas fa-check-circle"></i> Uploaded';
                    isFrontCaptured = true;
                    showToast('success', '✅ Front ID uploaded!');
                    
                    checkBothUploaded();
                    
                } else {
                    document.getElementById('back_image_data').value = imageData;
                    document.getElementById('backPreviewImg').src = imageData;
                    document.getElementById('backPreview').classList.add('show');
                    document.getElementById('backActions').classList.add('show');
                    document.getElementById('backBox').classList.add('uploaded');
                    document.getElementById('backFileName').textContent = file.name;
                    document.getElementById('backStatus').innerHTML = '<i class="fas fa-check-circle"></i> Uploaded';
                    isBackCaptured = true;
                    showToast('success', '✅ Back ID uploaded!');
                    
                    checkBothUploaded();
                }
            };
            reader.readAsDataURL(file);
        }

        function checkBothUploaded() {
            if (isFrontCaptured && isBackCaptured && !isProcessing) {
                setTimeout(function() {
                    autoProcessID();
                }, 800);
            }
        }

        function removeIDPhoto(type) {
            if (type === 'front') {
                document.getElementById('front_image_data').value = '';
                document.getElementById('front_file_input').value = '';
                document.getElementById('frontPreview').classList.remove('show');
                document.getElementById('frontActions').classList.remove('show');
                document.getElementById('frontBox').classList.remove('uploaded');
                document.getElementById('frontStatus').innerHTML = '<i class="fas fa-cloud-upload-alt"></i> Tap to upload';
                isFrontCaptured = false;
            } else {
                document.getElementById('back_image_data').value = '';
                document.getElementById('back_file_input').value = '';
                document.getElementById('backPreview').classList.remove('show');
                document.getElementById('backActions').classList.remove('show');
                document.getElementById('backBox').classList.remove('uploaded');
                document.getElementById('backStatus').innerHTML = '<i class="fas fa-cloud-upload-alt"></i> Tap to upload';
                isBackCaptured = false;
            }
            document.getElementById('nextBtn').disabled = true;
            document.getElementById('verificationBadge').classList.remove('show');
            document.getElementById('detectedIdTypeDisplay').style.display = 'none';
            verificationSuccess = false;
        }

        // ==================== STEP 2: Camera ====================
        function startIDScan() {
            if (isProcessing) return;
            
            currentCameraType = 'front';
            isFrontCaptured = false;
            isBackCaptured = false;
            
            document.getElementById('frontPreview').classList.remove('show');
            document.getElementById('backPreview').classList.remove('show');
            document.getElementById('frontActions').classList.remove('show');
            document.getElementById('backActions').classList.remove('show');
            document.getElementById('frontBox').classList.remove('uploaded');
            document.getElementById('backBox').classList.remove('uploaded');
            document.getElementById('frontStatus').innerHTML = '<i class="fas fa-cloud-upload-alt"></i> Tap to upload';
            document.getElementById('backStatus').innerHTML = '<i class="fas fa-cloud-upload-alt"></i> Tap to upload';
            document.getElementById('verificationBadge').classList.remove('show');
            document.getElementById('detectedIdTypeDisplay').style.display = 'none';
            document.getElementById('nextBtn').disabled = true;
            verificationSuccess = false;
            
            openCamera('front');
        }

        function openCamera(type) {
            currentCameraType = type;
            const overlay = document.getElementById('cameraOverlay');
            const video = document.getElementById('cameraVideo');
            const sideLabel = document.getElementById('cameraSideLabel');
            const stepNumber = document.getElementById('cameraStepNumber');
            
            if (type === 'front') {
                sideLabel.textContent = '📸 Scanning Front of ID';
                stepNumber.textContent = 'Step 1 of 2';
            } else {
                sideLabel.textContent = '📸 Scanning Back of ID';
                stepNumber.textContent = 'Step 2 of 2';
            }
            
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            document.getElementById('captureBtn').style.display = 'flex';
            
            const constraints = {
                video: {
                    facingMode: 'environment',
                    width: { ideal: 640 },
                    height: { ideal: 480 }
                },
                audio: false
            };
            
            const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
            if (isIOS) {
                constraints.video.width = { ideal: 480 };
                constraints.video.height = { ideal: 640 };
            }
            
            if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                navigator.mediaDevices.getUserMedia(constraints)
                .then(function(mediaStream) {
                    cameraStream = mediaStream;
                    video.srcObject = mediaStream;
                    video.setAttribute('playsinline', '');
                    video.setAttribute('muted', '');
                    video.play().catch(function(e) {
                        if (e.name === 'NotAllowedError') {
                            showToast('error', 'Please tap the screen to start the camera');
                            video.addEventListener('click', function() {
                                video.play().catch(function() {});
                            }, { once: true });
                        }
                    });
                })
                .catch(function(err) {
                    console.error('Camera error:', err);
                    let msg = 'Camera error. Please use the upload boxes above instead.';
                    if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                        msg = 'Camera access denied. Please use the upload boxes above instead.';
                    } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
                        msg = 'No camera found. Please use the upload boxes above instead.';
                    }
                    showToast('error', msg);
                    closeCamera();
                });
            } else {
                showToast('error', 'Camera not supported. Please use the upload boxes above.');
                closeCamera();
            }
        }

        function closeCamera() {
            const overlay = document.getElementById('cameraOverlay');
            const video = document.getElementById('cameraVideo');
            
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => {
                    track.stop();
                    track.enabled = false;
                });
                cameraStream = null;
            }
            video.srcObject = null;
            video.pause();
            overlay.classList.remove('active');
            document.body.style.overflow = '';
            
            if (isFrontCaptured && isBackCaptured && !isProcessing) {
                autoProcessID();
            }
        }

        function captureFromCamera() {
            const video = document.getElementById('cameraVideo');
            const canvas = document.createElement('canvas');
            
            const width = video.videoWidth || 640;
            const height = video.videoHeight || 480;
            
            canvas.width = width;
            canvas.height = height;
            
            const context = canvas.getContext('2d');
            context.drawImage(video, 0, 0, width, height);
            
            const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
            const quality = isMobile ? 0.7 : 0.9;
            const imageData = canvas.toDataURL('image/jpeg', quality);
            
            if (currentCameraType === 'front') {
                document.getElementById('front_image_data').value = imageData;
                document.getElementById('frontPreviewImg').src = imageData;
                document.getElementById('frontPreview').classList.add('show');
                document.getElementById('frontActions').classList.add('show');
                document.getElementById('frontBox').classList.add('uploaded');
                document.getElementById('frontStatus').innerHTML = '<i class="fas fa-check-circle"></i> Captured';
                isFrontCaptured = true;
                
                showToast('success', '✅ Front ID captured!');
                
                setTimeout(function() {
                    if (!isBackCaptured) {
                        openCamera('back');
                    }
                }, 800);
                
            } else {
                document.getElementById('back_image_data').value = imageData;
                document.getElementById('backPreviewImg').src = imageData;
                document.getElementById('backPreview').classList.add('show');
                document.getElementById('backActions').classList.add('show');
                document.getElementById('backBox').classList.add('uploaded');
                document.getElementById('backStatus').innerHTML = '<i class="fas fa-check-circle"></i> Captured';
                isBackCaptured = true;
                
                showToast('success', '✅ Back ID captured!');
                
                setTimeout(function() {
                    closeCamera();
                    if (isFrontCaptured && isBackCaptured && !isProcessing) {
                        autoProcessID();
                    }
                }, 500);
            }
        }

        function autoProcessID() {
            if (isProcessing) return;
            isProcessing = true;
            
            const loadingOverlay = document.getElementById('loadingOverlay');
            const loadingText = document.getElementById('loadingText');
            const loadingSub = document.getElementById('loadingSub');
            
            loadingOverlay.classList.add('active');
            loadingText.textContent = 'Verifying Your ID...';
            loadingSub.textContent = 'Please wait while we check your information';
            
            const formData = new FormData(document.getElementById('signupForm'));
            formData.append('action', 'process_id');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                loadingOverlay.classList.remove('active');
                isProcessing = false;
                
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = html;
                
                const errorEl = tempDiv.querySelector('.error-badge');
                if (errorEl) {
                    const errorText = errorEl.innerHTML || errorEl.textContent || '';
                    
                    if (errorText.includes('already have an account') || 
                        errorText.includes('Account already exists') ||
                        errorText.includes('You already have an account') ||
                        errorText.includes('already has an account')) {
                        
                        document.getElementById('nextBtn').disabled = true;
                        document.getElementById('verificationBadge').classList.remove('show');
                        verificationSuccess = false;
                        
                        Swal.fire({
                            title: 'ℹ️ Account Already Exists',
                            text: 'You already have an account with us. Please proceed to login.',
                            icon: 'info',
                            confirmButtonText: 'Go to Login',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            confirmButtonColor: '#1e7e6c'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                window.location.href = 'sign_in.php';
                            }
                        });
                        return;
                    }
                }
                
                const verificationBadge = tempDiv.querySelector('.verification-badge.show');
                const detectedTypeEl = tempDiv.querySelector('#detectedIdTypeText');
                
                if (detectedTypeEl && detectedTypeEl.textContent.trim() !== '') {
                    document.getElementById('detectedIdTypeText').textContent = detectedTypeEl.textContent;
                    document.getElementById('detectedIdTypeDisplay').style.display = 'block';
                }
                
                if (errorEl) {
                    document.getElementById('nextBtn').disabled = true;
                    document.getElementById('verificationBadge').classList.remove('show');
                    verificationSuccess = false;
                    
                    let errorText = errorEl.innerHTML || errorEl.textContent || '';
                    errorText = errorText.replace(/[⚠️❌✅]/g, '').trim();
                    
                    let title = '😕 Verification Issue';
                    let helpHTML = '';
                    
                    if (errorText.includes('clearer') || errorText.includes('blurry') || errorText.includes('well-lit') || 
                        errorText.includes('read') || errorText.includes('OCR') || errorText.includes('text')) {
                        title = '📸 ID Image Not Clear Enough';
                        helpHTML = '<div style="text-align:left; margin-top:10px;">' +
                                   '<p style="color:#555; margin-bottom:12px;">We couldn\'t read your ID clearly from the photo you uploaded.</p>' +
                                   '<hr style="border:1px solid #ffcdd2; margin:10px 0;">' +
                                   '<p style="font-weight:600; color:#e65100;">💡 Tips for a better photo:</p>' +
                                   '<ul style="text-align:left; padding-left:20px; margin:5px 0;">' +
                                   '<li style="margin:3px 0;">Make sure the room is well-lit</li>' +
                                   '<li style="margin:3px 0;">Hold your camera steady</li>' +
                                   '<li style="margin:3px 0;">Avoid shadows or glare on the ID</li>' +
                                   '<li style="margin:3px 0;">Make sure all text is in focus and readable</li>' +
                                   '<li style="margin:3px 0;">Try taking the photo again with better lighting</li>' +
                                   '</ul></div>';
                    } else if (errorText.includes('Masterlist') || errorText.includes('masterlist') || errorText.includes('resident')) {
                        title = '📋 Not Found in Barangay Records';
                        helpHTML = '<div style="text-align:left; margin-top:10px;">' +
                                   '<p style="color:#555; margin-bottom:12px;">The information on your ID doesn\'t match any record in our barangay masterlist.</p>' +
                                   '<hr style="border:1px solid #ffcdd2; margin:10px 0;">' +
                                   '<p style="font-weight:600; color:#e65100;">💡 What to do next:</p>' +
                                   '<ul style="text-align:left; padding-left:20px; margin:5px 0;">' +
                                   '<li style="margin:3px 0;">Visit the Barangay Office in person</li>' +
                                   '<li style="margin:3px 0;">Bring your valid ID for verification</li>' +
                                   '<li style="margin:3px 0;">Ask the staff to add you to the masterlist</li>' +
                                   '<li style="margin:3px 0;">Once added, you can register online</li>' +
                                   '</ul></div>';
                    } else if (errorText.includes('Age') || errorText.includes('18') || errorText.includes('age')) {
                        title = '🔞 Age Requirement Not Met';
                        helpHTML = '<div style="text-align:left; margin-top:10px;">' +
                                   '<p style="color:#555; margin-bottom:12px;">You need to be at least 18 years old to register for barangay services.</p>' +
                                   '<hr style="border:1px solid #ffcdd2; margin:10px 0;">' +
                                   '<p style="font-weight:600; color:#e65100;">💡 Important:</p>' +
                                   '<ul style="text-align:left; padding-left:20px; margin:5px 0;">' +
                                   '<li style="margin:3px 0;">You need to be at least 18 years old to register</li>' +
                                   '<li style="margin:3px 0;">If you are 18 or older, please verify your birthdate</li>' +
                                   '<li style="margin:3px 0;">If this is an error, visit the Barangay Office for assistance</li>' +
                                   '</ul></div>';
                    } else if (errorText.includes('mismatch') || errorText.includes('type') || errorText.includes('ID Type')) {
                        title = '🔄 ID Type Mismatch';
                        helpHTML = '<div style="text-align:left; margin-top:10px;">' +
                                   '<p style="color:#555; margin-bottom:12px;">The system detected a different type of ID than what was uploaded.</p>' +
                                   '<hr style="border:1px solid #ffcdd2; margin:10px 0;">' +
                                   '<p style="font-weight:600; color:#e65100;">💡 How to fix:</p>' +
                                   '<ul style="text-align:left; padding-left:20px; margin:5px 0;">' +
                                   '<li style="margin:3px 0;">Make sure you\'re uploading the correct ID</li>' +
                                   '<li style="margin:3px 0;">Try uploading a clearer photo</li>' +
                                   '<li style="margin:3px 0;">If the issue persists, contact the Barangay Office</li>' +
                                   '</ul></div>';
                    } else if (errorText.includes('missing') || errorText.includes('incomplete')) {
                        title = '📝 Incomplete ID Information';
                        helpHTML = '<div style="text-align:left; margin-top:10px;">' +
                                   '<p style="color:#555; margin-bottom:12px;">Some required information on your ID couldn\'t be read.</p>' +
                                   '<hr style="border:1px solid #ffcdd2; margin:10px 0;">' +
                                   '<p style="font-weight:600; color:#e65100;">💡 What to do:</p>' +
                                   '<ul style="text-align:left; padding-left:20px; margin:5px 0;">' +
                                   '<li style="margin:3px 0;">Make sure your ID is fully visible</li>' +
                                   '<li style="margin:3px 0;">Avoid covering any part of the ID</li>' +
                                   '<li style="margin:3px 0;">Ensure all text is clearly readable</li>' +
                                   '<li style="margin:3px 0;">Try taking the photo from a different angle</li>' +
                                   '</ul></div>';
                    } else if (errorText.includes('server') || errorText.includes('400') || errorText.includes('500') || 
                               errorText.includes('connection') || errorText.includes('timeout')) {
                        title = '🌐 Service Temporarily Unavailable';
                        helpHTML = '<div style="text-align:left; margin-top:10px;">' +
                                   '<p style="color:#555; margin-bottom:12px;">We\'re having trouble connecting to the verification service. This is usually a temporary issue.</p>' +
                                   '<hr style="border:1px solid #ffcdd2; margin:10px 0;">' +
                                   '<p style="font-weight:600; color:#e65100;">💡 What to do:</p>' +
                                   '<ul style="text-align:left; padding-left:20px; margin:5px 0;">' +
                                   '<li style="margin:3px 0;">Check your internet connection</li>' +
                                   '<li style="margin:3px 0;">Wait a few moments and try again</li>' +
                                   '<li style="margin:3px 0;">If the problem continues, try again later</li>' +
                                   '<li style="margin:3px 0;">You can also visit the Barangay Office for assistance</li>' +
                                   '</ul></div>';
                    } else {
                        if (errorText.includes('already') && errorText.includes('account')) {
                            title = 'ℹ️ Account Already Exists';
                            helpHTML = '<div style="text-align:left; margin-top:10px;">' +
                                       '<p style="color:#555; margin-bottom:12px;">You already have an account with us. Please proceed to login.</p>' +
                                       '<hr style="border:1px solid #ffcdd2; margin:10px 0;">' +
                                       '<p style="font-weight:600; color:#e65100;">💡 Next Steps:</p>' +
                                       '<ul style="text-align:left; padding-left:20px; margin:5px 0;">' +
                                       '<li style="margin:3px 0;">Click the "Go to Login" button below</li>' +
                                       '<li style="margin:3px 0;">Use your username and password to sign in</li>' +
                                       '<li style="margin:3px 0;">If you forgot your password, use the "Forgot Password" feature</li>' +
                                       '</ul></div>';
                            
                            Swal.fire({
                                title: title,
                                html: helpHTML,
                                icon: 'info',
                                confirmButtonText: 'Go to Login',
                                allowOutsideClick: false,
                                allowEscapeKey: false,
                                confirmButtonColor: '#1e7e6c'
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    window.location.href = 'sign_in.php';
                                }
                            });
                            return;
                        }
                        
                        title = '😕 Something Went Wrong';
                        helpHTML = '<div style="text-align:left; margin-top:10px;">' +
                                   '<p style="color:#555; margin-bottom:12px;">We couldn\'t complete the verification process at this time.</p>' +
                                   '<hr style="border:1px solid #ffcdd2; margin:10px 0;">' +
                                   '<p style="font-weight:600; color:#e65100;">💡 Please try the following:</p>' +
                                   '<ul style="text-align:left; padding-left:20px; margin:5px 0;">' +
                                   '<li style="margin:3px 0;">Make sure the ID is well-lit and in focus</li>' +
                                   '<li style="margin:3px 0;">Hold the camera steady when taking the photo</li>' +
                                   '<li style="margin:3px 0;">Try uploading a different photo</li>' +
                                   '<li style="margin:3px 0;">If the problem continues, visit the Barangay Office</li>' +
                                   '</ul></div>';
                    }
                    
                    Swal.fire({
                        title: title,
                        html: helpHTML,
                        icon: 'error',
                        confirmButtonText: 'Try Again',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        confirmButtonColor: '#dc3545',
                        width: '520px'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            isProcessing = false;
                        }
                    });
                    return;
                }
                
                if (verificationBadge && !errorEl) {
                    verificationSuccess = true;
                    document.getElementById('verificationBadge').classList.add('show');
                    document.getElementById('nextBtn').disabled = false;
                    
                    Swal.fire({
                        title: '✅ Identity Verified!',
                        text: 'Your ID has been verified successfully. You can now continue with your registration.',
                        icon: 'success',
                        confirmButtonText: 'Continue',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        confirmButtonColor: '#1e7e6c'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            document.getElementById('action').value = 'next';
                            document.getElementById('signupForm').submit();
                        }
                    });
                    return;
                }
                
                if (!errorEl && !verificationBadge) {
                    const currentStepInput = tempDiv.querySelector('#current_step');
                    if (currentStepInput && currentStepInput.value == '3') {
                        verificationSuccess = true;
                        document.getElementById('verificationBadge').classList.add('show');
                        document.getElementById('nextBtn').disabled = false;
                        
                        Swal.fire({
                            title: '✅ Identity Verified!',
                            text: 'Your ID has been verified successfully. You can now continue with your registration.',
                            icon: 'success',
                            confirmButtonText: 'Continue',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            confirmButtonColor: '#1e7e6c'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                document.getElementById('action').value = 'next';
                                document.getElementById('signupForm').submit();
                            }
                        });
                    }
                    return;
                }
                
                document.getElementById('nextBtn').disabled = true;
                document.getElementById('verificationBadge').classList.remove('show');
                verificationSuccess = false;
                
                Swal.fire({
                    title: '😕 Something Went Wrong',
                    text: 'We couldn\'t verify your ID. Please make sure your ID is well-lit, in focus, and try again.',
                    icon: 'error',
                    confirmButtonText: 'Try Again',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    confirmButtonColor: '#dc3545'
                }).then((result) => {
                    if (result.isConfirmed) {
                        isProcessing = false;
                    }
                });
            })
            .catch(function(error) {
                loadingOverlay.classList.remove('active');
                isProcessing = false;
                
                document.getElementById('nextBtn').disabled = true;
                document.getElementById('verificationBadge').classList.remove('show');
                verificationSuccess = false;
                
                Swal.fire({
                    title: '🌐 Connection Issue',
                    text: 'We couldn\'t connect to the verification service. Please check your internet connection and try again.',
                    icon: 'error',
                    confirmButtonText: 'Try Again',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    confirmButtonColor: '#dc3545'
                }).then((result) => {
                    if (result.isConfirmed) {
                        isProcessing = false;
                    }
                });
            });
        }

        function goToNextStep() {
            if (verificationSuccess) {
                document.getElementById('action').value = 'next';
                document.getElementById('signupForm').submit();
            } else {
                showToast('error', 'Please verify your ID first');
            }
        }

        // ==================== STEP 3 ====================
        function calculateAge() {
            const dob = document.getElementById('date_of_birth').value;
            const display = document.getElementById('age_display');
            if (dob) {
                const birthDate = new Date(dob);
                const today = new Date();
                let age = today.getFullYear() - birthDate.getFullYear();
                const monthDiff = today.getMonth() - birthDate.getMonth();
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) age--;
                display.innerHTML = age >= 18 ? '✅ Age: ' + age + ' (Valid - 18+)' : '❌ Age: ' + age + ' (Must be 18+)';
                display.style.color = age >= 18 ? '#1e7e6c' : '#d33';
            }
        }

        function validateStep3() {
            const fields = ['first_name', 'last_name', 'date_of_birth', 'gender', 'phone_number', 'address', 'purok'];
            let allValid = true;
            
            fields.forEach(function(field) {
                validatePersonalInfo(field);
                const input = document.getElementById(field);
                if (input) {
                    if (input.classList.contains('invalid')) {
                        allValid = false;
                    }
                }
            });
            
            if (!allValid) {
                showToast('error', 'Please fill in all required fields correctly');
                return false;
            }
            
            const dob = document.getElementById('date_of_birth').value;
            if (dob) {
                const birthDate = new Date(dob);
                const today = new Date();
                let age = today.getFullYear() - birthDate.getFullYear();
                const monthDiff = today.getMonth() - birthDate.getMonth();
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) age--;
                if (age < 18) { 
                    showToast('error', 'You must be at least 18 years old to register');
                    return false; 
                }
            }
            
            return true;
        }

        function validateStep3AndNext() {
            if (validateStep3()) nextStep();
        }

        // ==================== STEP 4 ====================
        function validateStep4() {
            validatePersonalInfo('username');
            validatePersonalInfo('password');
            validatePersonalInfo('confirm_password');
            
            const usernameInput = document.getElementById('username');
            const passwordInput = document.getElementById('password');
            const confirmInput = document.getElementById('confirm_password');
            
            if (usernameInput && usernameInput.classList.contains('invalid')) {
                showToast('error', 'Please enter a valid username');
                return false;
            }
            if (!usernameAvailable) {
                showToast('error', 'Username is not available');
                return false;
            }
            if (passwordInput && passwordInput.classList.contains('invalid')) {
                showToast('error', 'Please create a strong password');
                return false;
            }
            if (confirmInput && confirmInput.classList.contains('invalid')) {
                showToast('error', 'Passwords do not match');
                return false;
            }
            return true;
        }

        function validateStep4AndNext() {
            if (validateStep4()) nextStep();
        }

        // ==================== TOAST NOTIFICATION ====================
        function showToast(icon, message) {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true,
                didOpen: (toast) => {
                    toast.addEventListener('mouseenter', Swal.stopTimer);
                    toast.addEventListener('mouseleave', Swal.resumeTimer);
                }
            });
            
            Toast.fire({
                icon: icon,
                title: message
            });
        }

        // ==================== NAVIGATION ====================
        function nextStep() {
            const currentStep = parseInt(document.getElementById('current_step').value);
            let isValid = true;
            if (currentStep === 1) isValid = validateStep1();
            else if (currentStep === 3) isValid = validateStep3();
            else if (currentStep === 4) isValid = validateStep4();
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
            document.getElementById('submitButton').disabled = true;
            document.getElementById('submitButton').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            document.getElementById('action').value = 'submit';
            document.getElementById('signupForm').submit();
        }

        // ==================== PASSWORD TOGGLE ====================
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('togglePassword').addEventListener('click', function() {
                const input = document.getElementById('password');
                input.type = input.type === 'password' ? 'text' : 'password';
                this.classList.toggle('fa-eye');
                this.classList.toggle('fa-eye-slash');
            });
            
            document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
                const input = document.getElementById('confirm_password');
                input.type = input.type === 'password' ? 'text' : 'password';
                this.classList.toggle('fa-eye');
                this.classList.toggle('fa-eye-slash');
            });
        });

        function updateReview() {
            document.getElementById('review_name').textContent = (document.getElementById('first_name')?.value || '') + ' ' + (document.getElementById('last_name')?.value || '');
            document.getElementById('review_email').textContent = document.getElementById('email')?.value || '';
            document.getElementById('review_phone').textContent = document.getElementById('phone_number')?.value || '';
            document.getElementById('review_username').textContent = document.getElementById('username')?.value || '';
            document.getElementById('review_address').textContent = document.getElementById('address')?.value || '';
            document.getElementById('review_purok').textContent = document.getElementById('purok')?.value || '';
        }
        
        document.addEventListener('input', function(e) {
            if (['first_name', 'last_name', 'email', 'phone_number', 'username', 'address', 'purok'].includes(e.target.id)) {
                updateReview();
            }
        });
    </script>
</body>
</html>