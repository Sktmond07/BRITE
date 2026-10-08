<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/document_generators/DocumentGenerator.php';
require_once __DIR__ . '/../includes/email_helper.php';
require_once __DIR__ . '/../admin/push_send.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'resident') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$resident_id = $_SESSION['user_id'];
$raw_input  = file_get_contents('php://input');
$json_input = json_decode($raw_input, true);
if (!is_array($json_input)) $json_input = [];

$action = $_POST['action'] ?? $_GET['action'] ?? ($json_input['action'] ?? '');

// ============ NOTIFICATION HELPER FUNCTIONS ============

/**
 * Create a new notification for a resident
 */
function createNotification($conn, $resident_id, $type, $title, $message, $reference_id = null, $reference_type = null) {
    $sql = "INSERT INTO notifications (resident_id, type, title, message, reference_id, reference_type, is_read, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, 0, NOW())";
    
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log("Failed to prepare notification insert: " . mysqli_error($conn));
        return false;
    }
    
    mysqli_stmt_bind_param($stmt, "isssis", $resident_id, $type, $title, $message, $reference_id, $reference_type);
    
    if (mysqli_stmt_execute($stmt)) {
        $notification_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        error_log("Notification created: ID=$notification_id, resident=$resident_id, type=$type");
        return $notification_id;
    }
    
    error_log("Failed to create notification: " . mysqli_stmt_error($stmt));
    mysqli_stmt_close($stmt);
    return false;
}

/**
 * Check if a similar notification was already sent recently
 */
function wasNotificationSentRecently($conn, $resident_id, $type, $reference_id, $hours = 24) {
    $sql = "SELECT COUNT(*) as sent FROM notifications 
            WHERE resident_id = ? 
            AND type = ? 
            AND reference_id = ?
            AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)";
    
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log("wasNotificationSentRecently prepare failed: " . mysqli_error($conn));
        return false;
    }
    
    mysqli_stmt_bind_param($stmt, "isii", $resident_id, $type, $reference_id, $hours);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : ['sent' => 0];
    mysqli_stmt_close($stmt);
    
    return ($row['sent'] ?? 0) > 0;
}

/**
 * Check if notification was sent today for a specific reference
 */
function wasNotificationSentToday($conn, $resident_id, $type, $reference_id) {
    $sql = "SELECT COUNT(*) as sent FROM notifications 
            WHERE resident_id = ? 
            AND type = ? 
            AND reference_id = ?
            AND DATE(created_at) = CURDATE()";
    
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log("wasNotificationSentToday prepare failed: " . mysqli_error($conn));
        return false;
    }
    
    mysqli_stmt_bind_param($stmt, "isi", $resident_id, $type, $reference_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : ['sent' => 0];
    mysqli_stmt_close($stmt);
    
    return ($row['sent'] ?? 0) > 0;
}

// ============ QR CODE GENERATION FUNCTION ============
function generateQRCode($conn, $request_id) {
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
        return ['success' => false, 'message' => 'Request not found'];
    }
    
    $qrDir = __DIR__ . '/../generated_qrcodes/';
    if (!file_exists($qrDir)) {
        if (!mkdir($qrDir, 0777, true)) {
            return ['success' => false, 'message' => 'Failed to create QR directory'];
        }
    }
    
    if (!is_writable($qrDir)) {
        chmod($qrDir, 0777);
    }
    
    $qrLibPath = __DIR__ . '/qrcode/phpqrcode.php';
    if (!file_exists($qrLibPath)) {
        $qrLibPath = __DIR__ . '/../admin/qrcode/phpqrcode.php';
        if (!file_exists($qrLibPath)) {
            error_log("QR library not found at: " . $qrLibPath);
            return ['success' => false, 'message' => 'QR library not found'];
        }
    }
    
    require_once $qrLibPath;
    
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
    
    try {
        QRcode::png($qrPayload, $qrPath, QR_ECLEVEL_H, 10, 2);
        
        if (file_exists($qrPath) && filesize($qrPath) > 0) {
            $relativePath = 'generated_qrcodes/' . $qrFilename;
            
            $updateSql = "UPDATE document_requests SET qr_code_path = ?, qr_verification_code = ? WHERE id = ?";
            $updateStmt = mysqli_prepare($conn, $updateSql);
            mysqli_stmt_bind_param($updateStmt, "ssi", $relativePath, $verificationCode, $request_id);
            
            if (mysqli_stmt_execute($updateStmt)) {
                error_log("QR Code generated for request ID: $request_id - Path: $relativePath");
                return [
                    'success' => true, 
                    'qr_path' => $relativePath, 
                    'verification_code' => $verificationCode
                ];
            }
        }
        return ['success' => false, 'message' => 'QR generation failed'];
    } catch (Exception $e) {
        error_log("QR Code generation exception: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ============ RESIDENT PROFILE FUNCTIONS ============

function searchMasterlist($conn) {
    $firstName = isset($_GET['first_name']) ? trim($_GET['first_name']) : '';
    $lastName = isset($_GET['last_name']) ? trim($_GET['last_name']) : '';
    $middleName = isset($_GET['middle_name']) ? trim($_GET['middle_name']) : '';
    
    if (empty($firstName) && empty($lastName)) {
        return ['success' => false, 'message' => 'No search criteria provided'];
    }
    
    $sql = "SELECT 
                hm.*,
                h.address as household_address,
                h.purok as household_purok,
                h.id as household_id,
                CONCAT(hm.first_name, ' ', COALESCE(hm.middle_name, ''), ' ', hm.last_name) as full_name
            FROM household_members hm
            JOIN households h ON hm.household_id = h.id
            WHERE 1=1";
    
    $params = [];
    $types = "";
    
    if (!empty($firstName)) {
        $sql .= " AND LOWER(hm.first_name) LIKE LOWER(?)";
        $params[] = '%' . $firstName . '%';
        $types .= "s";
    }
    
    if (!empty($lastName)) {
        $sql .= " AND LOWER(hm.last_name) LIKE LOWER(?)";
        $params[] = '%' . $lastName . '%';
        $types .= "s";
    }
    
    if (!empty($middleName)) {
        $sql .= " AND LOWER(hm.middle_name) LIKE LOWER(?)";
        $params[] = '%' . $middleName . '%';
        $types .= "s";
    }
    
    $sql .= " ORDER BY hm.id DESC LIMIT 1";
    
    $stmt = mysqli_prepare($conn, $sql);
    
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $data = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if ($data) {
        $fullName = trim(
            ($data['first_name'] ?? '') . ' ' . 
            ($data['middle_name'] ? $data['middle_name'] . ' ' : '') . 
            ($data['last_name'] ?? '')
        );
        
        return [
            'success' => true,
            'data' => [
                'id' => $data['id'],
                'full_name' => $fullName,
                'first_name' => $data['first_name'] ?? '',
                'last_name' => $data['last_name'] ?? '',
                'middle_name' => $data['middle_name'] ?? '',
                'ext' => $data['ext'] ?? '',
                'place_of_birth' => $data['place_of_birth'] ?? '',
                'date_of_birth' => $data['date_of_birth'] ?? '',
                'age' => $data['age'] ?? '',
                'sex' => $data['sex'] ?? '',
                'civil_status' => $data['civil_status'] ?? '',
                'citizenship' => $data['citizenship'] ?? 'Filipino',
                'occupation' => $data['occupation'] ?? '',
                'employment_status' => $data['employment_status'] ?? '',
                'address' => $data['household_address'] ?? '',
                'purok' => $data['household_purok'] ?? '',
                'household_id' => $data['household_id'] ?? 0,
                'household_member_id' => $data['id'] ?? 0
            ]
        ];
    }
    
    return ['success' => true, 'data' => null];
}

function getResidentProfileData($conn, $resident_id) {
    $residentSql = "SELECT id, email, first_name, last_name, address FROM resident WHERE id = ?";
    $residentStmt = mysqli_prepare($conn, $residentSql);
    mysqli_stmt_bind_param($residentStmt, "i", $resident_id);
    mysqli_stmt_execute($residentStmt);
    $residentResult = mysqli_stmt_get_result($residentStmt);
    $residentData = mysqli_fetch_assoc($residentResult);
    mysqli_stmt_close($residentStmt);
    
    if (!$residentData) {
        return null;
    }
    
    $sql = "SELECT 
                hm.*,
                h.address as household_address,
                h.purok as household_purok,
                h.id as household_id
            FROM household_members hm
            JOIN households h ON hm.household_id = h.id
            WHERE LOWER(hm.first_name) = LOWER(?) 
            AND LOWER(hm.last_name) = LOWER(?)
            ORDER BY hm.id DESC
            LIMIT 1";
    
    $firstName = $residentData['first_name'];
    $lastName = $residentData['last_name'];
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $firstName, $lastName);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $data = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if (!$data) {
        $sql = "SELECT 
                    hm.*,
                    h.address as household_address,
                    h.purok as household_purok,
                    h.id as household_id
                FROM household_members hm
                JOIN households h ON hm.household_id = h.id
                WHERE LOWER(hm.first_name) LIKE LOWER(?) 
                AND LOWER(hm.last_name) LIKE LOWER(?)
                ORDER BY hm.id DESC
                LIMIT 1";
        
        $firstNameLike = '%' . $residentData['first_name'] . '%';
        $lastNameLike = '%' . $residentData['last_name'] . '%';
        
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ss", $firstNameLike, $lastNameLike);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $data = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
    }
    
    return $data;
}

function getResidentProfile($conn, $resident_id) {
    $data = getResidentProfileData($conn, $resident_id);
    
    if ($data) {
        $sexDisplay = '';
        if ($data['sex'] === 'M') {
            $sexDisplay = 'Male';
        } elseif ($data['sex'] === 'F') {
            $sexDisplay = 'Female';
        } else {
            $sexDisplay = $data['sex'] ?? '';
        }
        
        $civilStatus = $data['civil_status'] ?? '';
        if ($civilStatus) {
            $civilStatus = ucwords(strtolower($civilStatus));
        }
        
        $employmentStatus = $data['employment_status'] ?? '';
        if ($employmentStatus) {
            $employmentStatus = ucwords(strtolower($employmentStatus));
        }
        
        $dateOfBirth = $data['date_of_birth'] ?? '';
        $dateOfBirthFormatted = '';
        if ($dateOfBirth) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth)) {
                $dateOfBirthFormatted = $dateOfBirth;
            } elseif (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dateOfBirth, $matches)) {
                $dateOfBirthFormatted = $matches[3] . '-' . $matches[2] . '-' . $matches[1];
            } else {
                try {
                    $dateObj = new DateTime($dateOfBirth);
                    $dateOfBirthFormatted = $dateObj->format('Y-m-d');
                } catch (Exception $e) {
                    $dateOfBirthFormatted = $dateOfBirth;
                }
            }
        }
        
        $citizenship = $data['citizenship'] ?? 'Filipino';
        if ($citizenship) {
            $citizenship = ucwords(strtolower($citizenship));
        }
        
        $occupation = $data['occupation'] ?? '';
        if ($occupation && $occupation !== 'N/A' && $occupation !== 'None' && $occupation !== 'n/a') {
            $occupation = ucwords(strtolower($occupation));
        } elseif ($occupation === 'N/A' || $occupation === 'None' || $occupation === 'n/a') {
            $occupation = '';
        }
        
        $fullName = trim(
            ($data['first_name'] ?? '') . ' ' . 
            ($data['middle_name'] ? $data['middle_name'] . ' ' : '') . 
            ($data['last_name'] ?? '') . 
            ($data['ext'] ? ' ' . $data['ext'] : '')
        );
        
        $formattedData = [
            'full_name' => $fullName,
            'first_name' => $data['first_name'] ?? '',
            'last_name' => $data['last_name'] ?? '',
            'middle_name' => $data['middle_name'] ?? '',
            'ext' => $data['ext'] ?? '',
            'place_of_birth' => $data['place_of_birth'] ?? '',
            'date_of_birth' => $dateOfBirthFormatted,
            'age' => $data['age'] ?? '',
            'sex' => $sexDisplay,
            'sex_code' => $data['sex'] ?? '',
            'civil_status' => $civilStatus,
            'citizenship' => $citizenship,
            'occupation' => $occupation,
            'employment_status' => $employmentStatus,
            'address' => $data['household_address'] ?? '',
            'purok' => $data['household_purok'] ?? '',
            'household_id' => $data['household_id'] ?? 0,
            'household_member_id' => $data['id'] ?? 0
        ];
        
        error_log("Formatted Profile Data: " . print_r($formattedData, true));
        
        echo json_encode(['success' => true, 'data' => $formattedData]);
    } else {
        error_log("No profile data found for resident ID: " . $resident_id);
        echo json_encode(['success' => false, 'data' => null]);
    }
}

function detectResidentType($conn, $resident_id) {
    $profileData = getResidentProfileData($conn, $resident_id);
    
    if (!$profileData) {
        return ['success' => true, 'type' => 'regular', 'free' => false];
    }
    
    $age = intval($profileData['age'] ?? 0);
    $employment = strtolower($profileData['employment_status'] ?? '');
    $occupation = strtolower($profileData['occupation'] ?? '');
    
    if ($age >= 60) {
        return ['success' => true, 'type' => 'senior', 'free' => true];
    }
    
    if (strpos($employment, 'student') !== false || 
        strpos($occupation, 'student') !== false ||
        strpos($occupation, 'studying') !== false ||
        strpos($occupation, 'college') !== false ||
        strpos($occupation, 'university') !== false) {
        return ['success' => true, 'type' => 'student', 'free' => true];
    }
    
    return ['success' => true, 'type' => 'regular', 'free' => false];
}

// ============ DOCUMENT FUNCTIONS ============

function getDocuments($conn) {
    $sql = "SELECT id, name, description, fee FROM document_types WHERE is_active = 1 ORDER BY name ASC";
    $result = mysqli_query($conn, $sql);
    
    $documents = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $documents[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'description' => $row['description'] ?: 'Official document from the barangay',
            'fee' => floatval($row['fee'])
        ];
    }
    
    echo json_encode(['success' => true, 'documents' => $documents]);
}

function getDocumentFields($conn) {
    $document_id = intval($_GET['document_id'] ?? 0);
    
    $sql = "SELECT * FROM document_custom_fields 
            WHERE document_type_id = ? AND is_active = 1 
            ORDER BY sort_order ASC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $document_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $fields = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $fields[] = [
            'field_name' => $row['field_name'],
            'field_label' => $row['field_label'],
            'field_type' => $row['field_type'],
            'field_required' => (bool)$row['field_required'],
            'field_options' => $row['field_options'] ? json_decode($row['field_options'], true) : null
        ];
    }
    
    echo json_encode(['success' => true, 'fields' => $fields]);
}

function getRequests($conn, $resident_id) {
    $sql = "SELECT r.*, 
        p.id as payment_id, 
        p.payment_status as payment_status, 
        p.amount as paid_amount, 
        p.payment_method, 
        p.expiry_date,
        r.claimed_at,
        GROUP_CONCAT(CONCAT(cd.field_name, '|||', cd.field_value) SEPARATOR ';;;') as custom_data
        FROM document_requests r
        LEFT JOIN document_requests_custom_data cd ON r.id = cd.request_id
        LEFT JOIN document_payments p ON r.id = p.request_id
        WHERE r.resident_id = ?
        GROUP BY r.id
        ORDER BY r.request_date DESC";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $resident_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $requests = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $custom_data = [];
        if ($row['custom_data']) {
            $pairs = explode(';;;', $row['custom_data']);
            foreach ($pairs as $pair) {
                $parts = explode('|||', $pair, 2);
                if (count($parts) == 2) {
                    $custom_data[$parts[0]] = $parts[1];
                }
            }
        }
        
        $payment_status = 'not_created';
        
        if ($row['payment_method'] === 'pay_at_claim') {
            $payment_status = 'pay_at_claim';
        } elseif ($row['payment_status'] === 'pay_at_claim') {
            $payment_status = 'pay_at_claim';
        } elseif ($row['payment_status'] === 'paid') {
            $payment_status = 'paid';
        } elseif ($row['fee_type'] === 'student' || $row['fee_type'] === 'senior' || $row['fee'] == 0) {
            $payment_status = 'free';
        }
        
        if ($payment_status !== 'pay_at_claim') {
            $payment_check_sql = "SELECT payment_method FROM document_payments WHERE request_id = ? AND payment_method = 'pay_at_claim' LIMIT 1";
            $payment_check_stmt = mysqli_prepare($conn, $payment_check_sql);
            mysqli_stmt_bind_param($payment_check_stmt, "i", $row['id']);
            mysqli_stmt_execute($payment_check_stmt);
            $payment_check_result = mysqli_stmt_get_result($payment_check_stmt);
            $payment_check = mysqli_fetch_assoc($payment_check_result);
            if ($payment_check && $payment_check['payment_method'] === 'pay_at_claim') {
                $payment_status = 'pay_at_claim';
            }
            mysqli_stmt_close($payment_check_stmt);
        }

        $requests[] = [
            'id' => $row['id'],
            'document_type' => $row['document_type'],
            'document_type_id' => $row['document_type_id'],
            'purpose' => $row['purpose'],
            'notes' => $row['notes'],
            'fee_type' => $row['fee_type'],
            'fee' => floatval($row['fee']),
            'status' => $row['status'],
            'admin_notes' => $row['admin_notes'],
            'request_date' => $row['request_date'],
            'processed_date' => $row['processed_date'],
            'quantity' => $row['quantity'],
            'id_document_path' => $row['id_document_path'],
            'custom_data' => $custom_data,
            'payment_status' => $payment_status,
            'payment_id' => $row['payment_id'],
            'paid_amount' => floatval($row['paid_amount'] ?? 0),
            'payment_method' => $row['payment_method'],
            'payment_expiry' => $row['expiry_date'],
            'pickup_date' => $row['pickup_date'] ?? '',
            'pickup_time' => $row['pickup_time'] ?? '',
            'claimed_at' => $row['claimed_at'] ?? null
        ];
    }
    
    echo json_encode(['success' => true, 'requests' => $requests]);
}

function submitRequest($conn, $resident_id) {
    $profileData = getResidentProfileData($conn, $resident_id);
    
    $resident_sql = "SELECT first_name, last_name, middle_name, email, phone, address FROM resident WHERE id = ?";
    $resident_stmt = mysqli_prepare($conn, $resident_sql);
    mysqli_stmt_bind_param($resident_stmt, "i", $resident_id);
    mysqli_stmt_execute($resident_stmt);
    $resident_result = mysqli_stmt_get_result($resident_stmt);
    $resident_data = mysqli_fetch_assoc($resident_result);
    mysqli_stmt_close($resident_stmt);
    
    if (!$resident_data) {
        echo json_encode(['success' => false, 'message' => 'Resident not found']);
        return;
    }
    
    $document_type = mysqli_real_escape_string($conn, $_POST['document_type'] ?? '');
    $document_type_id = intval($_POST['document_id'] ?? 0);
    $purpose = mysqli_real_escape_string($conn, $_POST['purpose'] ?? 'Document Request');
    $notes = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
    $fee_type = mysqli_real_escape_string($conn, $_POST['fee_type'] ?? 'regular');
    $fee = floatval($_POST['fee'] ?? 0);
    $quantity = intval($_POST['quantity'] ?? 1);
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'online');
    $pickup_date = mysqli_real_escape_string($conn, $_POST['pickup_date'] ?? '');
    $pickup_time = mysqli_real_escape_string($conn, $_POST['pickup_time'] ?? '');
    
    if (empty($document_type) || $document_type_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid document type selected']);
        return;
    }
    
    $id_document_path = '';
    
    $request_first_name = $profileData['first_name'] ?? $resident_data['first_name'];
    $request_last_name = $profileData['last_name'] ?? $resident_data['last_name'];
    $request_middle_name = $profileData['middle_name'] ?? ($resident_data['middle_name'] ?? '');
    $request_ext = $profileData['ext'] ?? '';
    $request_place_of_birth = $profileData['place_of_birth'] ?? '';
    $request_date_of_birth = $profileData['date_of_birth_raw'] ?? $profileData['date_of_birth'] ?? '';
    $request_age = $profileData['age'] ?? '';
    $request_sex = $profileData['sex_code'] ?? $profileData['sex'] ?? '';
    $request_civil_status = $profileData['civil_status'] ?? '';
    $request_citizenship = $profileData['citizenship'] ?? 'Filipino';
    $request_occupation = $profileData['occupation'] ?? '';
    $request_employment_status = $profileData['employment_status'] ?? '';
    $request_address = $profileData['address'] ?? ($resident_data['address'] ?? '');
    $request_purok = $profileData['purok'] ?? '';
    $household_id = $profileData['household_id'] ?? 0;
    
    mysqli_begin_transaction($conn);
    
    try {
        $profileCustomData = [
            'full_name' => trim($request_first_name . ' ' . ($request_middle_name ? $request_middle_name . ' ' : '') . $request_last_name . ($request_ext ? ' ' . $request_ext : '')),
            'first_name' => $request_first_name,
            'last_name' => $request_last_name,
            'middle_name' => $request_middle_name,
            'ext' => $request_ext,
            'place_of_birth' => $request_place_of_birth,
            'date_of_birth' => $request_date_of_birth,
            'age' => $request_age,
            'sex' => $request_sex,
            'civil_status' => $request_civil_status,
            'citizenship' => $request_citizenship,
            'occupation' => $request_occupation,
            'employment_status' => $request_employment_status,
            'address' => $request_address,
            'purok' => $request_purok,
            'household_id' => $household_id
        ];
        
        $sql = "INSERT INTO document_requests (
                    resident_id, 
                    document_type_id, 
                    document_type, 
                    purpose, 
                    notes, 
                    fee_type, 
                    fee, 
                    quantity,
                    id_document_path,
                    pickup_date,
                    pickup_time,
                    status, 
                    request_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
        
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare statement: ' . mysqli_error($conn));
        }
        
        mysqli_stmt_bind_param($stmt, "iissssdisss", 
            $resident_id, 
            $document_type_id, 
            $document_type, 
            $purpose, 
            $notes, 
            $fee_type, 
            $fee,
            $quantity,
            $id_document_path,
            $pickup_date,
            $pickup_time
        );
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('Failed to insert request: ' . mysqli_stmt_error($stmt));
        }
        
        $request_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        
        if (isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
            $custom_sql = "INSERT INTO document_requests_custom_data (request_id, field_name, field_value) VALUES (?, ?, ?)";
            $custom_stmt = mysqli_prepare($conn, $custom_sql);
            
            if ($custom_stmt) {
                foreach ($_POST['custom_fields'] as $field_name => $field_value) {
                    if ($field_name === 'children' && is_array($field_value)) {
                        $json_value = json_encode($field_value);
                        mysqli_stmt_bind_param($custom_stmt, "iss", $request_id, $field_name, $json_value);
                    } else {
                        $field_value = mysqli_real_escape_string($conn, $field_value);
                        mysqli_stmt_bind_param($custom_stmt, "iss", $request_id, $field_name, $field_value);
                    }
                    
                    if (!mysqli_stmt_execute($custom_stmt)) {
                        throw new Exception('Failed to insert custom data');
                    }
                }
                mysqli_stmt_close($custom_stmt);
            }
        }
        
        $custom_sql = "INSERT INTO document_requests_custom_data (request_id, field_name, field_value) VALUES (?, ?, ?)";
        $custom_stmt = mysqli_prepare($conn, $custom_sql);
        
        if ($custom_stmt) {
            foreach ($profileCustomData as $field_name => $field_value) {
                if ($field_value !== null && $field_value !== '') {
                    $field_value = mysqli_real_escape_string($conn, $field_value);
                    mysqli_stmt_bind_param($custom_stmt, "iss", $request_id, $field_name, $field_value);
                    mysqli_stmt_execute($custom_stmt);
                }
            }
            mysqli_stmt_close($custom_stmt);
        }
        
        $shouldAutoApprove = false;
        $autoApproveStatus = 'pending';
        $payment_status = 'not_created';
        $payment_id = null;
        
        $isFree = ($fee_type === 'student' || $fee_type === 'senior' || $fee == 0);
        $totalAmount = $fee * $quantity;
        
        if ($isFree) {
            $shouldAutoApprove = true;
            $autoApproveStatus = 'approved';
            $payment_status = 'free';
        } else if ($payment_method === 'pay_at_claim') {
            $shouldAutoApprove = true;
            $autoApproveStatus = 'approved';
            $payment_status = 'pay_at_claim';
            
            $paySql = "INSERT INTO document_payments (request_id, resident_id, amount, payment_method, payment_status) 
                       VALUES (?, ?, ?, 'pay_at_claim', 'pay_at_claim')";
            $payStmt = mysqli_prepare($conn, $paySql);
            mysqli_stmt_bind_param($payStmt, "iid", $request_id, $resident_id, $totalAmount);
            mysqli_stmt_execute($payStmt);
            $payment_id = mysqli_insert_id($conn);
            mysqli_stmt_close($payStmt);
            
            $updatePaySql = "UPDATE document_requests SET payment_id = ? WHERE id = ?";
            $updatePayStmt = mysqli_prepare($conn, $updatePaySql);
            mysqli_stmt_bind_param($updatePayStmt, "ii", $payment_id, $request_id);
            mysqli_stmt_execute($updatePayStmt);
            mysqli_stmt_close($updatePayStmt);
        } else if ($payment_method === 'online' && $fee > 0) {
            $shouldAutoApprove = false;
            $autoApproveStatus = 'pending';
            $payment_status = 'pending';
            
            $paySql = "INSERT INTO document_payments (request_id, resident_id, amount, payment_method, payment_status) 
                       VALUES (?, ?, ?, 'online', 'pending')";
            $payStmt = mysqli_prepare($conn, $paySql);
            mysqli_stmt_bind_param($payStmt, "iid", $request_id, $resident_id, $totalAmount);
            mysqli_stmt_execute($payStmt);
            $payment_id = mysqli_insert_id($conn);
            mysqli_stmt_close($payStmt);
            
            $updatePaySql = "UPDATE document_requests SET payment_id = ? WHERE id = ?";
            $updatePayStmt = mysqli_prepare($conn, $updatePaySql);
            mysqli_stmt_bind_param($updatePayStmt, "ii", $payment_id, $request_id);
            mysqli_stmt_execute($updatePayStmt);
            mysqli_stmt_close($updatePayStmt);
        } else {
            $shouldAutoApprove = false;
            $autoApproveStatus = 'pending';
            $payment_status = 'pending';
            
            $paySql = "INSERT INTO document_payments (request_id, resident_id, amount, payment_method, payment_status) 
                       VALUES (?, ?, ?, 'pending', 'pending')";
            $payStmt = mysqli_prepare($conn, $paySql);
            mysqli_stmt_bind_param($payStmt, "iid", $request_id, $resident_id, $totalAmount);
            mysqli_stmt_execute($payStmt);
            $payment_id = mysqli_insert_id($conn);
            mysqli_stmt_close($payStmt);
            
            $updatePaySql = "UPDATE document_requests SET payment_id = ? WHERE id = ?";
            $updatePayStmt = mysqli_prepare($conn, $updatePaySql);
            mysqli_stmt_bind_param($updatePayStmt, "ii", $payment_id, $request_id);
            mysqli_stmt_execute($updatePayStmt);
            mysqli_stmt_close($updatePayStmt);
        }
        
        if ($shouldAutoApprove) {
            $updateSql = "UPDATE document_requests SET 
                          status = ?, 
                          processed_date = NOW(),
                          payment_status = ?
                          WHERE id = ?";
            $updateStmt = mysqli_prepare($conn, $updateSql);
            mysqli_stmt_bind_param($updateStmt, "ssi", $autoApproveStatus, $payment_status, $request_id);
            mysqli_stmt_execute($updateStmt);
            mysqli_stmt_close($updateStmt);
        } else {
            $updateSql = "UPDATE document_requests SET payment_status = ? WHERE id = ?";
            $updateStmt = mysqli_prepare($conn, $updateSql);
            mysqli_stmt_bind_param($updateStmt, "si", $payment_status, $request_id);
            mysqli_stmt_execute($updateStmt);
            mysqli_stmt_close($updateStmt);
        }
        
        // ============ GENERATE DOCUMENT AND QR CODE FOR AUTO-APPROVED ============
        $documentGenerated = false;
        $documentPath = null;
        $qrGenerated = false;
        $qrCodePath = null;
        
        if ($shouldAutoApprove && $autoApproveStatus === 'approved') {
            try {
                $generator = new DocumentGenerator($conn);
                $docResult = $generator->generateDocument($request_id);
                
                if ($docResult['success']) {
                    $documentGenerated = true;
                    $documentPath = $docResult['filename'];
                    error_log("Document generated for auto-approved request ID: $request_id");
                } else {
                    error_log("Document generation failed for request ID: $request_id - " . ($docResult['message'] ?? 'Unknown error'));
                }
                
                $qrResult = generateQRCode($conn, $request_id);
                if ($qrResult['success']) {
                    $qrGenerated = true;
                    $qrCodePath = $qrResult['qr_path'];
                    error_log("QR Code generated for auto-approved request ID: $request_id - Path: $qrCodePath");
                } else {
                    error_log("QR Code generation failed for request ID: $request_id - " . ($qrResult['message'] ?? 'Unknown error'));
                }
                
            } catch (Exception $e) {
                error_log("Exception during generation for auto-approved request: " . $e->getMessage());
            }
        }
        
         
        
        if (!$shouldAutoApprove) {
            $notifTitle = "📋 Document Request Received";
            $notifMessage = "Your request for {$document_type} has been received and is pending review. Reference: #{$request_id}";
            createNotification($conn, $resident_id, 'document_pending', $notifTitle, $notifMessage, $request_id, 'document');
        }

        // ============ NOTIFY SECRETARIES ABOUT THE NEW DOCUMENT REQUEST ============
        // Uniform message for every request — just alert that a new request came in.
        // Includes the payment method the resident selected.
        $resident_full_name = trim(
            ($resident_data['first_name'] ?? '') . ' ' . ($resident_data['last_name'] ?? '')
        );

        // Make the payment method human-readable
        $payment_label = '';
        if (!empty($payment_method)) {
            $payment_map = [
                'walk_in'      => 'Walk-In',
                'online'       => 'Online Payment',
                'pay_at_claim' => 'Pay at Claim',
                'cash'         => 'Cash',
                'gcash'        => 'GCash',
                'maya'         => 'Maya',
                'pending'      => 'Not Yet Selected',
            ];
            $raw = strtolower(trim($payment_method));
            $payment_label = $payment_map[$raw] ?? ucwords(str_replace('_', ' ', $raw));
        }

        $adminTitle = 'New Document Request';
        $adminMsg   = "A new {$document_type} request (Ref #{$request_id}) was submitted by {$resident_full_name}"
                    . ($payment_label !== '' ? " via {$payment_label}." : ".")
                    . ($isFree ? " (FREE)" : "")
                    . " Please review it in the Document Requests list.";
        $adminType  = 'info';

        $secQ = mysqli_query($conn, "SELECT id FROM admin WHERE admin_role = 'secretary' AND is_active = 1");
        if ($secQ) {
            while ($sec = mysqli_fetch_assoc($secQ)) {
                $sec_id = (int)$sec['id'];

                // 1) Bell notification row (drives the secretary's dropdown)
                $nSql = "INSERT INTO notifications
                            (user_type, user_id, resident_id, type, title, message, reference_id, reference_type)
                         VALUES ('admin', ?, NULL, ?, ?, ?, ?, 'document_request')";
                $nStmt = @mysqli_prepare($conn, $nSql);
                if ($nStmt) {
                    mysqli_stmt_bind_param($nStmt, "isssi",
                        $sec_id, $adminType, $adminTitle, $adminMsg, $request_id);
                    mysqli_stmt_execute($nStmt);
                    mysqli_stmt_close($nStmt);
                }

                // 2) Real desktop push via FCM
                if (function_exists('pushToUser')) {
                    pushToUser(
                        $conn,
                        $sec_id,
                        $adminTitle,
                        $adminMsg,
                        '/BRITE/admin/dashboards/secretary_dashboard.php'
                    );
                }
            }
        }
        
        mysqli_commit($conn);
        
        $message = 'Document request submitted successfully!';
        if ($shouldAutoApprove && $autoApproveStatus === 'approved') {
            if ($isFree) {
                $message = 'Document request submitted and approved! No payment required.';
            } else {
                $message = 'Document request submitted and approved! You have selected Pay at Claim.';
            }
        } else {
            $message = 'Document request submitted! Please complete the payment to proceed.';
        }
        
        echo json_encode([
            'success' => true, 
            'message' => $message,
            'auto_approved' => $shouldAutoApprove,
            'status' => $autoApproveStatus,
            'payment_status' => $payment_status,
            'request_id' => $request_id,
            'document_generated' => $documentGenerated,
            'document_path' => $documentPath,
            'qr_generated' => $qrGenerated,
            'qr_path' => $qrCodePath
        ]);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getRequestDetails($conn, $resident_id) {
    $request_id = intval($_GET['request_id'] ?? 0);
    
    if ($request_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
        return;
    }
    
    $sql = "SELECT r.*, 
            GROUP_CONCAT(CONCAT(cd.field_name, '|||', cd.field_value) SEPARATOR ';;;') as custom_data
            FROM document_requests r
            LEFT JOIN document_requests_custom_data cd ON r.id = cd.request_id
            WHERE r.id = ? AND r.resident_id = ?
            GROUP BY r.id";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $request_id, $resident_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $request = mysqli_fetch_assoc($result);
    
    if (!$request) {
        echo json_encode(['success' => false, 'message' => 'Request not found']);
        return;
    }
    
    $custom_data = [];
    if ($request['custom_data']) {
        $pairs = explode(';;;', $request['custom_data']);
        foreach ($pairs as $pair) {
            $parts = explode('|||', $pair, 2);
            if (count($parts) == 2) {
                if ($parts[0] === 'children') {
                    $decoded = json_decode($parts[1], true);
                    $custom_data[$parts[0]] = $decoded ?: $parts[1];
                } else {
                    $custom_data[$parts[0]] = $parts[1];
                }
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'request' => [
            'id' => $request['id'],
            'document_type' => $request['document_type'],
            'document_type_id' => $request['document_type_id'],
            'purpose' => $request['purpose'],
            'notes' => $request['notes'],
            'fee_type' => $request['fee_type'],
            'fee' => floatval($request['fee']),
            'status' => $request['status'],
            'quantity' => $request['quantity'],
            'custom_data' => $custom_data,
            'pickup_date' => $request['pickup_date'] ?? '',
            'pickup_time' => $request['pickup_time'] ?? '',
            'claimed_at' => $request['claimed_at'] ?? null
        ]
    ]);
}

function updateRequest($conn, $resident_id) {
    $request_id = intval($_POST['edit_request_id'] ?? 0);
    
    if ($request_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
        return;
    }
    
    $check_sql = "SELECT id, status, id_document_path FROM document_requests WHERE id = ? AND resident_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($check_stmt, "ii", $request_id, $resident_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $request = mysqli_fetch_assoc($check_result);
    
    if (!$request) {
        echo json_encode(['success' => false, 'message' => 'Request not found']);
        return;
    }
    
    if ($request['status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'Only pending requests can be edited']);
        return;
    }
    
    $quantity = intval($_POST['quantity'] ?? 1);
    $notes = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
    $fee_type = mysqli_real_escape_string($conn, $_POST['fee_type'] ?? 'regular');
    $fee = floatval($_POST['fee'] ?? 0);
    
    $id_document_path = $request['id_document_path'];
    if (isset($_FILES['id_document']) && $_FILES['id_document']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../uploads/id_documents/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES['id_document']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        
        if (in_array($file_extension, $allowed)) {
            $new_filename = 'id_' . $resident_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['id_document']['tmp_name'], $upload_path)) {
                if ($request['id_document_path'] && file_exists(__DIR__ . '/../' . $request['id_document_path'])) {
                    unlink(__DIR__ . '/../' . $request['id_document_path']);
                }
                $id_document_path = 'uploads/id_documents/' . $new_filename;
            }
        }
    }
    
    mysqli_begin_transaction($conn);
    
    try {
        $update_sql = "UPDATE document_requests 
                       SET quantity = ?, notes = ?, fee_type = ?, fee = ?, id_document_path = ?
                       WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "issdsi", $quantity, $notes, $fee_type, $fee, $id_document_path, $request_id);
        
        if (!mysqli_stmt_execute($update_stmt)) {
            throw new Exception('Failed to update request');
        }
        mysqli_stmt_close($update_stmt);
        
        $delete_sql = "DELETE FROM document_requests_custom_data WHERE request_id = ?";
        $delete_stmt = mysqli_prepare($conn, $delete_sql);
        mysqli_stmt_bind_param($delete_stmt, "i", $request_id);
        mysqli_stmt_execute($delete_stmt);
        mysqli_stmt_close($delete_stmt);
        
        if (isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
            $custom_sql = "INSERT INTO document_requests_custom_data (request_id, field_name, field_value) VALUES (?, ?, ?)";
            $custom_stmt = mysqli_prepare($conn, $custom_sql);
            
            if ($custom_stmt) {
                foreach ($_POST['custom_fields'] as $field_name => $field_value) {
                    if ($field_name === 'children' && is_array($field_value)) {
                        $json_value = json_encode($field_value);
                        mysqli_stmt_bind_param($custom_stmt, "iss", $request_id, $field_name, $json_value);
                    } else {
                        $field_value = mysqli_real_escape_string($conn, $field_value);
                        mysqli_stmt_bind_param($custom_stmt, "iss", $request_id, $field_name, $field_value);
                    }
                    
                    if (!mysqli_stmt_execute($custom_stmt)) {
                        throw new Exception('Failed to insert custom data');
                    }
                }
                mysqli_stmt_close($custom_stmt);
            }
        }
        
        mysqli_commit($conn);
        echo json_encode(['success' => true, 'message' => 'Request updated successfully!']);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    mysqli_stmt_close($check_stmt);
}

function cancelRequest($conn, $resident_id) {
    $request_id = intval($_POST['request_id'] ?? 0);
    
    if ($request_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
        return;
    }
    
    $check_sql = "SELECT id, status, id_document_path FROM document_requests WHERE id = ? AND resident_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($check_stmt, "ii", $request_id, $resident_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $request = mysqli_fetch_assoc($check_result);
    
    if (!$request) {
        echo json_encode(['success' => false, 'message' => 'Request not found']);
        return;
    }
    
    if ($request['status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'Only pending requests can be cancelled']);
        return;
    }
    
    mysqli_begin_transaction($conn);
    
    try {
        $delete_custom_sql = "DELETE FROM document_requests_custom_data WHERE request_id = ?";
        $delete_custom_stmt = mysqli_prepare($conn, $delete_custom_sql);
        mysqli_stmt_bind_param($delete_custom_stmt, "i", $request_id);
        mysqli_stmt_execute($delete_custom_stmt);
        mysqli_stmt_close($delete_custom_stmt);
        
        $delete_sql = "DELETE FROM document_requests WHERE id = ?";
        $delete_stmt = mysqli_prepare($conn, $delete_sql);
        mysqli_stmt_bind_param($delete_stmt, "i", $request_id);
        
        if (!mysqli_stmt_execute($delete_stmt)) {
            throw new Exception('Failed to delete request');
        }
        
        if ($request['id_document_path'] && file_exists(__DIR__ . '/../' . $request['id_document_path'])) {
            unlink(__DIR__ . '/../' . $request['id_document_path']);
        }
        
        mysqli_commit($conn);
        echo json_encode(['success' => true, 'message' => 'Request cancelled and deleted successfully']);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => 'Failed to cancel request: ' . $e->getMessage()]);
    }
    
    mysqli_stmt_close($check_stmt);
}

function getStats($conn, $resident_id) {
    $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'approved'  THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'pending'   THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'rejected'  THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'unclaimed' THEN 1 ELSE 0 END) as unclaimed,
                SUM(CASE WHEN status = 'claimed'   THEN 1 ELSE 0 END) as claimed
            FROM document_requests 
            WHERE resident_id = ?";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $resident_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $stats = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'total'     => intval($stats['total']),
            'approved'  => intval($stats['approved']),
            'pending'   => intval($stats['pending']),
            'rejected'  => intval($stats['rejected']),
            'unclaimed' => intval($stats['unclaimed']),
            'claimed'   => intval($stats['claimed'])
        ]
    ]);
}

// ============ UNCLAIMED DOCUMENT CHECKER ============

function checkUnclaimedDocuments($conn, $resident_id = null) {
    $sql = "SELECT id, resident_id, document_type, pickup_date, pickup_time
            FROM document_requests 
            WHERE status = 'approved' 
            AND claimed_at IS NULL
            AND pickup_date IS NOT NULL
            AND pickup_date < DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
    
    if ($resident_id) {
        $sql .= " AND resident_id = ?";
    }
    
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['success' => false, 'message' => 'DB error: ' . mysqli_error($conn)];
    }
    
    if ($resident_id) {
        mysqli_stmt_bind_param($stmt, "i", $resident_id);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $updatedCount = 0;
    $updatedRequests = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $updateSql = "UPDATE document_requests 
                      SET status = 'unclaimed' 
                      WHERE id = ? AND status = 'approved' AND claimed_at IS NULL";
        $updateStmt = mysqli_prepare($conn, $updateSql);
        mysqli_stmt_bind_param($updateStmt, "i", $row['id']);
        
        if (mysqli_stmt_execute($updateStmt) && mysqli_stmt_affected_rows($updateStmt) > 0) {
            $updatedCount++;
            $updatedRequests[] = [
                'id' => $row['id'],
                'document_type' => $row['document_type'],
                'pickup_date' => $row['pickup_date']
            ];
            
            createNotification(
                $conn,
                $row['resident_id'],
                'document_unclaimed',
                "📭 Document Not Claimed",
                "Your {$row['document_type']} was scheduled for pickup on "
                    . date('M d, Y', strtotime($row['pickup_date']))
                    . " but was not claimed. Please visit the Barangay Hall to claim it.",
                $row['id'],
                'document'
            );
        }
        mysqli_stmt_close($updateStmt);
    }
    mysqli_stmt_close($stmt);
    
    return [
        'success' => true,
        'updated_count' => $updatedCount,
        'updated_requests' => $updatedRequests
    ];
}

// ============ PAYMENT FUNCTIONS ============

function getQRCodeInfo($conn, $resident_id) {
    $request_id = intval($_GET['request_id'] ?? 0);
    
    if ($request_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
        return;
    }
    
        $sql = "SELECT r.*, res.first_name, res.last_name, res.email
            FROM document_requests r
            JOIN resident res ON r.resident_id = res.id
            WHERE r.id = ? AND r.resident_id = ? AND r.status IN ('approved', 'unclaimed')";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $request_id, $resident_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $request = mysqli_fetch_assoc($result);
    
    if (!$request) {
        echo json_encode(['success' => false, 'message' => 'Request not found']);
        return;
    }
    
    $qr_code_path = $request['qr_code_path'] ?? null;
    $qr_code_url = null;
    
    if ($qr_code_path && file_exists(__DIR__ . '/../' . $qr_code_path)) {
        $qr_code_url = '../' . $qr_code_path;
    }
    
    echo json_encode([
        'success' => true,
        'qr_code_url' => $qr_code_url,
        'request' => [
            'id' => $request['id'],
            'document_type' => $request['document_type'],
            'resident_name' => $request['first_name'] . ' ' . $request['last_name'],
            'request_date' => $request['request_date'],
            'status' => $request['status'],
            'qr_verification_code' => $request['qr_verification_code']
        ]
    ]);
}

function createPayment($conn, $resident_id) {
    $request_id = intval($_POST['request_id'] ?? 0);
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'gcash');
    
    if ($request_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
        return;
    }
    
    $check_sql = "SELECT id, fee, fee_type, status, quantity, document_type FROM document_requests 
                  WHERE id = ? AND resident_id = ? AND status = 'approved'";
    $check_stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($check_stmt, "ii", $request_id, $resident_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $request = mysqli_fetch_assoc($check_result);
    
    if (!$request) {
        echo json_encode(['success' => false, 'message' => 'Request not found or not approved']);
        return;
    }
    
    if ($request['fee_type'] === 'student' || $request['fee_type'] === 'senior' || $request['fee'] == 0) {
        echo json_encode(['success' => false, 'message' => 'This document is FREE. No payment required.']);
        return;
    }
    
    $amount = floatval($request['fee']) * intval($request['quantity']);
    
    $existing_sql = "SELECT id, payment_status FROM document_payments 
                     WHERE request_id = ? AND payment_status IN ('pending', 'paid')";
    $existing_stmt = mysqli_prepare($conn, $existing_sql);
    mysqli_stmt_bind_param($existing_stmt, "i", $request_id);
    mysqli_stmt_execute($existing_stmt);
    $existing_result = mysqli_stmt_get_result($existing_stmt);
    $existing = mysqli_fetch_assoc($existing_result);
    
    if ($existing && $existing['payment_status'] == 'paid') {
        echo json_encode(['success' => false, 'message' => 'Payment already completed']);
        return;
    }
    
    if ($existing && $existing['payment_status'] == 'pending') {
        echo json_encode([
            'success' => true,
            'payment_exists' => true,
            'payment_id' => $existing['id'],
            'amount' => $amount,
            'document_type' => $request['document_type'],
            'message' => 'Payment already pending'
        ]);
        return;
    }
    
    $sql = "INSERT INTO document_payments (request_id, resident_id, amount, payment_method, payment_status) 
            VALUES (?, ?, ?, ?, 'pending')";
    $stmt = mysqli_prepare($conn, $sql);
    
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        return;
    }
    
    mysqli_stmt_bind_param($stmt, "iids", $request_id, $resident_id, $amount, $payment_method);
    
    if (mysqli_stmt_execute($stmt)) {
        $payment_id = mysqli_insert_id($conn);
        
        $update_sql = "UPDATE document_requests SET payment_status = 'pending', payment_id = ? WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_sql);
        
        if ($update_stmt) {
            mysqli_stmt_bind_param($update_stmt, "ii", $payment_id, $request_id);
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);
        }
        
        echo json_encode([
            'success' => true,
            'payment_id' => $payment_id,
            'amount' => $amount,
            'document_type' => $request['document_type'],
            'message' => 'Payment created successfully. Please upload proof of payment.'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create payment: ' . mysqli_error($conn)]);
    }
    mysqli_stmt_close($stmt);
}

function getPaymentStatus($conn, $resident_id) {
    $request_id = intval($_GET['request_id'] ?? 0);
    
    if ($request_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
        return;
    }
    
    $sql = "SELECT p.*, r.status as request_status, r.fee_type, r.fee, r.quantity
            FROM document_payments p
            JOIN document_requests r ON p.request_id = r.id
            WHERE p.request_id = ? AND p.resident_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $request_id, $resident_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $payment = mysqli_fetch_assoc($result);
    
    if ($payment) {
        echo json_encode([
            'success' => true,
            'payment' => [
                'id' => $payment['id'],
                'amount' => floatval($payment['amount']),
                'payment_status' => $payment['payment_status'],
                'payment_method' => $payment['payment_method'],
                'expiry_date' => $payment['expiry_date'],
                'payment_date' => $payment['payment_date'],
                'transaction_id' => $payment['transaction_id'],
                'request_status' => $payment['request_status'],
                'fee_type' => $payment['fee_type']
            ]
        ]);
    } else {
        $free_sql = "SELECT fee_type, fee FROM document_requests WHERE id = ? AND resident_id = ?";
        $free_stmt = mysqli_prepare($conn, $free_sql);
        mysqli_stmt_bind_param($free_stmt, "ii", $request_id, $resident_id);
        mysqli_stmt_execute($free_stmt);
        $free_result = mysqli_stmt_get_result($free_stmt);
        $free_request = mysqli_fetch_assoc($free_result);
        
        $is_free = ($free_request && ($free_request['fee_type'] === 'student' || $free_request['fee_type'] === 'senior' || $free_request['fee'] == 0));
        
        echo json_encode([
            'success' => true,
            'payment' => null,
            'is_free' => $is_free,
            'message' => $is_free ? 'This document is FREE. No payment required.' : 'No payment created yet'
        ]);
    }
}

function getPaymentInfo($conn, $resident_id) {
    $payment_id = intval($_GET['payment_id'] ?? 0);
    
    if ($payment_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment ID']);
        return;
    }
    
    $sql = "SELECT p.*, r.document_type, r.quantity, r.fee 
            FROM document_payments p
            JOIN document_requests r ON p.request_id = r.id
            WHERE p.id = ? AND p.resident_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $payment_id, $resident_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $payment = mysqli_fetch_assoc($result);
    
    if (!$payment) {
        echo json_encode(['success' => false, 'message' => 'Payment not found']);
        return;
    }
    
    echo json_encode([
        'success' => true,
        'payment' => [
            'id' => $payment['id'],
            'amount' => floatval($payment['amount']),
            'document_type' => $payment['document_type'],
            'gcash_number' => '09123456789',
            'maya_number' => '09123456789',
            'barangay_name' => 'Barangay San Bartolome'
        ]
    ]);
}

function getBarangayPaymentInfo($conn) {
    echo json_encode([
        'success' => true,
        'info' => [
            'gcash_number' => '09123456789',
            'gcash_name' => 'Barangay San Bartolome',
            'maya_number' => '09123456789',
            'maya_name' => 'Barangay San Bartolome',
            'barangay_address' => 'San Bartolome, Sto Tomas, Pampanga',
            'barangay_hours' => 'Monday-Friday, 8:00 AM - 5:00 PM'
        ]
    ]);
}

function simulatePayment($conn, $resident_id) {
    $request_id = intval($_POST['request_id'] ?? 0);
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'gcash');
    
    if ($request_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
        return;
    }
    
    $sql = "SELECT r.*, res.email, res.first_name, res.last_name 
            FROM document_requests r
            JOIN resident res ON r.resident_id = res.id
            WHERE r.id = ? AND r.resident_id = ? AND r.status = 'approved'";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $request_id, $resident_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $request = mysqli_fetch_assoc($result);
    
    if (!$request) {
        echo json_encode(['success' => false, 'message' => 'Request not found or not approved']);
        return;
    }
    
    if ($request['fee_type'] === 'student' || $request['fee_type'] === 'senior' || $request['fee'] == 0) {
        echo json_encode(['success' => false, 'message' => 'This document is FREE. No payment required.']);
        return;
    }
    
    $amount = floatval($request['fee'] * $request['quantity']);
    
    $existing_sql = "SELECT id, payment_status FROM document_payments 
                     WHERE request_id = ? AND payment_status = 'paid'";
    $existing_stmt = mysqli_prepare($conn, $existing_sql);
    mysqli_stmt_bind_param($existing_stmt, "i", $request_id);
    mysqli_stmt_execute($existing_stmt);
    $existing_result = mysqli_stmt_get_result($existing_stmt);
    $existing = mysqli_fetch_assoc($existing_result);
    
    if ($existing && $existing['payment_status'] == 'paid') {
        echo json_encode(['success' => false, 'message' => 'Payment already completed']);
        return;
    }
    
    $delete_sql = "DELETE FROM document_payments WHERE request_id = ? AND payment_status IN ('pending', 'pay_at_claim')";
    $delete_stmt = mysqli_prepare($conn, $delete_sql);
    mysqli_stmt_bind_param($delete_stmt, "i", $request_id);
    mysqli_stmt_execute($delete_stmt);
    mysqli_stmt_close($delete_stmt);
    
    $transaction_id = 'SIM_' . strtoupper(uniqid());
    $insert_sql = "INSERT INTO document_payments (request_id, resident_id, amount, payment_method, payment_status, transaction_id, payment_date) 
                   VALUES (?, ?, ?, ?, 'paid', ?, NOW())";
    $insert_stmt = mysqli_prepare($conn, $insert_sql);
    mysqli_stmt_bind_param($insert_stmt, "iidss", $request_id, $resident_id, $amount, $payment_method, $transaction_id);
    
    if (mysqli_stmt_execute($insert_stmt)) {
        $payment_id = mysqli_insert_id($conn);
        
        $update_sql = "UPDATE document_requests SET payment_status = 'paid', payment_id = ? WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "ii", $payment_id, $request_id);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
        
        // Create notification for payment completion
        $notifTitle = "💳 Payment Successful";
        $notifMessage = "Your payment of ₱" . number_format($amount, 2) . " for {$request['document_type']} has been received. Your document will be processed shortly.";
        createNotification($conn, $resident_id, 'payment_completed', $notifTitle, $notifMessage, $request_id, 'document');
        
        echo json_encode([
            'success' => true,
            'payment_id' => $payment_id,
            'amount' => $amount,
            'transaction_id' => $transaction_id,
            'document_type' => $request['document_type'],
            'message' => 'Payment successful! Your document will be processed.'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create payment record']);
    }
    mysqli_stmt_close($insert_stmt);
}

function payAtClaim($conn, $resident_id) {
    $request_id = intval($_POST['request_id'] ?? 0);
    
    if ($request_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
        return;
    }
    
    $sql = "SELECT r.* FROM document_requests r
            WHERE r.id = ? AND r.resident_id = ? AND r.status = 'approved'";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $request_id, $resident_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $request = mysqli_fetch_assoc($result);
    
    if (!$request) {
        echo json_encode(['success' => false, 'message' => 'Request not found or not approved']);
        return;
    }
    
    if ($request['fee_type'] === 'student' || $request['fee_type'] === 'senior' || $request['fee'] == 0) {
        echo json_encode(['success' => false, 'message' => 'This document is FREE. No payment required.']);
        return;
    }
    
    $amount = floatval($request['fee'] * $request['quantity']);
    
    $existing_sql = "SELECT id, payment_status FROM document_payments 
                     WHERE request_id = ? AND payment_status = 'paid'";
    $existing_stmt = mysqli_prepare($conn, $existing_sql);
    mysqli_stmt_bind_param($existing_stmt, "i", $request_id);
    mysqli_stmt_execute($existing_stmt);
    $existing_result = mysqli_stmt_get_result($existing_stmt);
    $existing = mysqli_fetch_assoc($existing_result);
    
    if ($existing && $existing['payment_status'] == 'paid') {
        echo json_encode(['success' => false, 'message' => 'Payment already completed']);
        return;
    }
    
    $check_pay_at_claim = "SELECT id FROM document_payments WHERE request_id = ? AND payment_method = 'pay_at_claim'";
    $check_stmt = mysqli_prepare($conn, $check_pay_at_claim);
    mysqli_stmt_bind_param($check_stmt, "i", $request_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    if (mysqli_fetch_assoc($check_result)) {
        echo json_encode(['success' => false, 'message' => 'Pay at claim already selected for this request']);
        mysqli_stmt_close($check_stmt);
        return;
    }
    mysqli_stmt_close($check_stmt);
    
    $delete_sql = "DELETE FROM document_payments WHERE request_id = ? AND payment_status IN ('pending')";
    $delete_stmt = mysqli_prepare($conn, $delete_sql);
    mysqli_stmt_bind_param($delete_stmt, "i", $request_id);
    mysqli_stmt_execute($delete_stmt);
    mysqli_stmt_close($delete_stmt);
    
    $insert_sql = "INSERT INTO document_payments (request_id, resident_id, amount, payment_method, payment_status) 
                   VALUES (?, ?, ?, 'pay_at_claim', 'pay_at_claim')";
    $insert_stmt = mysqli_prepare($conn, $insert_sql);
    mysqli_stmt_bind_param($insert_stmt, "iid", $request_id, $resident_id, $amount);
    
    if (mysqli_stmt_execute($insert_stmt)) {
        $payment_id = mysqli_insert_id($conn);
        
        $update_sql = "UPDATE document_requests SET payment_status = 'pay_at_claim', payment_id = ? WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "ii", $payment_id, $request_id);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
        
        echo json_encode([
            'success' => true,
            'payment_id' => $payment_id,
            'amount' => $amount,
            'document_type' => $request['document_type'],
            'payment_status' => 'pay_at_claim',
            'payment_method' => 'pay_at_claim',
            'message' => 'You have chosen to pay when claiming the document. Please bring exact amount to Barangay Hall.'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create payment record: ' . mysqli_error($conn)]);
    }
    mysqli_stmt_close($insert_stmt);
}

function uploadPaymentProof($conn, $resident_id) {
    echo json_encode(['success' => true, 'message' => 'Payment completed']);
}

function getPendingPayments($conn, $resident_id) {
    $sql = "SELECT p.*, r.document_type, r.document_type_id, r.status as request_status, r.quantity
            FROM document_payments p
            JOIN document_requests r ON p.request_id = r.id
            WHERE p.resident_id = ? AND p.payment_status = 'pending'
            ORDER BY p.created_at DESC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $resident_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $payments = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $payments[] = [
            'id' => $row['id'],
            'request_id' => $row['request_id'],
            'document_type' => $row['document_type'],
            'amount' => floatval($row['amount']),
            'payment_method' => $row['payment_method'],
            'expiry_date' => $row['expiry_date'],
            'created_at' => $row['created_at']
        ];
    }
    
    echo json_encode(['success' => true, 'payments' => $payments]);
}

function confirmPayAtClaim($conn, $resident_id) {
    $payment_id = intval($_POST['payment_id'] ?? 0);
    
    if ($payment_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment ID']);
        return;
    }
    
    $check_sql = "SELECT p.*, r.document_type FROM document_payments p
                  JOIN document_requests r ON p.request_id = r.id
                  WHERE p.id = ? AND p.resident_id = ? AND p.payment_method = 'pay_at_claim'";
    $check_stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($check_stmt, "ii", $payment_id, $resident_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $payment = mysqli_fetch_assoc($check_result);
    
    if (!$payment) {
        echo json_encode(['success' => false, 'message' => 'Payment record not found']);
        return;
    }
    
    $sql = "UPDATE document_payments 
            SET payment_status = 'pay_at_claim' 
            WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $payment_id);
    
    if (mysqli_stmt_execute($stmt)) {
        $update_sql = "UPDATE document_requests SET payment_status = 'pay_at_claim' WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "i", $payment['request_id']);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
        
        echo json_encode([
            'success' => true,
            'message' => 'You have chosen to pay when claiming the document. Please bring exact amount to Barangay Hall.'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to confirm payment method: ' . mysqli_error($conn)]);
    }
    mysqli_stmt_close($stmt);
}

// ============ NOTIFICATION FUNCTIONS ============

/**
 * Get all notifications for a resident (from notifications table)
 */
function getAllNotifications($conn, $resident_id) {
    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    
    $sql = "SELECT * FROM notifications 
            WHERE resident_id = ? 
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        return;
    }
    
    mysqli_stmt_bind_param($stmt, "iii", $resident_id, $limit, $offset);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $notifications = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $notifications[] = [
            'id' => $row['id'],
            'type' => $row['type'],
            'title' => $row['title'],
            'message' => $row['message'],
            'reference_id' => $row['reference_id'],
            'reference_type' => $row['reference_type'],
            'is_read' => (bool)$row['is_read'],
            'created_at' => $row['created_at'],
            'read_at' => $row['read_at'] ?? null
        ];
    }
    mysqli_stmt_close($stmt);
    
    // Get unread count
    $countSql = "SELECT COUNT(*) as unread FROM notifications WHERE resident_id = ? AND is_read = 0";
    $countStmt = mysqli_prepare($conn, $countSql);
    mysqli_stmt_bind_param($countStmt, "i", $resident_id);
    mysqli_stmt_execute($countStmt);
    $countResult = mysqli_stmt_get_result($countStmt);
    $countRow = mysqli_fetch_assoc($countResult);
    mysqli_stmt_close($countStmt);
    
    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'unread_count' => (int)$countRow['unread'],
        'total' => count($notifications)
    ]);
}


function markNotificationRead($conn, $resident_id) {
    $notification_id = intval($_POST['notification_id'] ?? 0);
    
    if ($notification_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
        return;
    }
    
    $sql = "UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE id = ? AND resident_id = ?";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $notification_id, $resident_id);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update notification']);
    }
    mysqli_stmt_close($stmt);
}


function markAllNotificationsRead($conn, $resident_id) {
    $sql = "UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE resident_id = ? AND is_read = 0";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $resident_id);
    
    if (mysqli_stmt_execute($stmt)) {
        $affected = mysqli_stmt_affected_rows($stmt);
        echo json_encode([
            'success' => true, 
            'message' => "Marked {$affected} notification(s) as read",
            'affected' => $affected
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update notifications']);
    }
    mysqli_stmt_close($stmt);
}  


function deleteSelectedNotifications($conn, $resident_id) {
    $ids_input = $_POST['notification_ids'] ?? '';
    
    if (empty($ids_input)) {
        echo json_encode(['success' => false, 'message' => 'No notifications selected']);
        return;
    }
    
    // Parse comma-separated IDs
    $ids = array_filter(array_map('intval', explode(',', $ids_input)), function($id) {
        return $id > 0;
    });
    
    if (empty($ids)) {
        echo json_encode(['success' => false, 'message' => 'No valid notification IDs provided']);
        return;
    }
    
    // Build placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids) + 1);
    $params = array_merge([$resident_id], $ids);
    
    $sql = "DELETE FROM notifications 
            WHERE resident_id = ? 
            AND id IN ($placeholders)";
    
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
        return;
    }
    
    // Bind params dynamically
    $bind_params = array_merge([$types], $params);
    $refs = [];
    foreach ($bind_params as $key => $value) {
        $refs[$key] = &$bind_params[$key];
    }
    call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt], $refs));
    
    if (mysqli_stmt_execute($stmt)) {
        $deleted = mysqli_stmt_affected_rows($stmt);
        echo json_encode([
            'success' => true, 
            'message' => "Deleted {$deleted} notification(s)",
            'deleted' => $deleted
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete notifications']);
    }
    mysqli_stmt_close($stmt);
}


function getUnreadCount($conn, $resident_id) {
    $sql = "SELECT COUNT(*) as unread FROM notifications WHERE resident_id = ? AND is_read = 0";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $resident_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    echo json_encode(['success' => true, 'unread_count' => (int)$row['unread']]);
}

/**
 * Check for new notifications since last check (for push polling)
 */
function checkNewNotifications($conn, $resident_id) {
    $lastCheck = $_GET['last_check'] ?? date('Y-m-d H:i:s', strtotime('-5 minutes'));
    
    // Convert ISO 8601 format to MySQL datetime if needed
    if (strpos($lastCheck, 'T') !== false) {
        $timestamp = strtotime($lastCheck);
        if ($timestamp !== false) {
            $lastCheck = date('Y-m-d H:i:s', $timestamp);
        }
    }
    
    // Validate format - fallback if invalid
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $lastCheck)) {
        $lastCheck = date('Y-m-d H:i:s', strtotime('-5 minutes'));
    }
    
    $newNotifications = [];
    
    // Check if notifications table exists
    $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
    if (!$tableCheck || mysqli_num_rows($tableCheck) === 0) {
        echo json_encode([
            'success' => true,
            'new_notifications' => [],
            'count' => 0,
            'check_time' => date('Y-m-d H:i:s'),
            'warning' => 'Notifications table not found'
        ]);
        return;
    }
    
    $sql = "SELECT * FROM notifications 
            WHERE resident_id = ? 
            AND created_at > ?
            ORDER BY created_at DESC
            LIMIT 20";
    
    $stmt = mysqli_prepare($conn, $sql);
    
    if (!$stmt) {
        error_log("checkNewNotifications: Prepare failed - " . mysqli_error($conn));
        echo json_encode([
            'success' => true,
            'new_notifications' => [],
            'count' => 0,
            'check_time' => date('Y-m-d H:i:s')
        ]);
        return;
    }
    
    mysqli_stmt_bind_param($stmt, "is", $resident_id, $lastCheck);
    
    if (!mysqli_stmt_execute($stmt)) {
        error_log("checkNewNotifications: Execute failed - " . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        echo json_encode([
            'success' => true,
            'new_notifications' => [],
            'count' => 0,
            'check_time' => date('Y-m-d H:i:s')
        ]);
        return;
    }
    
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $newNotifications[] = [
                'id' => $row['id'],
                'type' => $row['type'],
                'title' => $row['title'],
                'message' => $row['message'],
                'created_at' => $row['created_at']
            ];
        }
    }
    mysqli_stmt_close($stmt);
    
    echo json_encode([
        'success' => true,
        'new_notifications' => $newNotifications,
        'count' => count($newNotifications),
        'check_time' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Check return reminders and create notifications for equipment due soon/overdue
 */
function checkReturnReminders($conn, $resident_id) {
    $remindersCreated = 0;
    $overdueCreated = 0;
    
    // First verify the notifications table exists
    $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
    if (!$tableCheck || mysqli_num_rows($tableCheck) === 0) {
        echo json_encode([
            'success' => true,
            'reminders_created' => 0,
            'overdue_created' => 0,
            'warning' => 'Notifications table not found'
        ]);
        return;
    }
    
    // Verify equipment_requests table exists
    $eqTableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'equipment_requests'");
    if (!$eqTableCheck || mysqli_num_rows($eqTableCheck) === 0) {
        echo json_encode([
            'success' => true,
            'reminders_created' => 0,
            'overdue_created' => 0,
            'warning' => 'Equipment requests table not found'
        ]);
        return;
    }
    
    // ============ 1. CHECK FOR EQUIPMENT DUE WITHIN 3 DAYS ============
    $dueSoonSql = "SELECT r.id, r.reference, r.end_datetime, er.equipment_name, er.quantity,
                   DATEDIFF(r.end_datetime, NOW()) as days_until_due
                   FROM requests r
                   JOIN equipment_requests er ON r.id = er.request_id
                   WHERE r.resident_id = ? 
                   AND r.status IN ('approved', 'borrowed')
                   AND r.request_type = 'equipment'
                   AND r.end_datetime >= NOW()
                   AND DATEDIFF(r.end_datetime, NOW()) <= 3
                   AND DATEDIFF(r.end_datetime, NOW()) >= 0";
    
    $dueStmt = mysqli_prepare($conn, $dueSoonSql);
    
    if ($dueStmt) {
        mysqli_stmt_bind_param($dueStmt, "i", $resident_id);
        mysqli_stmt_execute($dueStmt);
        $dueResult = mysqli_stmt_get_result($dueStmt);
        
        if ($dueResult) {
            while ($row = mysqli_fetch_assoc($dueResult)) {
                $daysUntilDue = (int)$row['days_until_due'];
                
                if (!wasNotificationSentToday($conn, $resident_id, 'return_reminder', $row['id'])) {
                    if ($daysUntilDue == 0) {
                        $title = "⚠️ Return Due Today!";
                        $message = "Your borrowed {$row['equipment_name']} ({$row['quantity']} item(s)) is due TODAY. Please return it before the end of the day.";
                    } elseif ($daysUntilDue == 1) {
                        $title = "📅 Return Reminder - Due Tomorrow";
                        $message = "Your borrowed {$row['equipment_name']} ({$row['quantity']} item(s)) is due tomorrow. Please prepare to return it.";
                    } else {
                        $title = "📅 Return Reminder - {$daysUntilDue} Days Left";
                        $message = "Your borrowed {$row['equipment_name']} ({$row['quantity']} item(s)) is due in {$daysUntilDue} days.";
                    }
                    
                    if (createNotification($conn, $resident_id, 'return_reminder', $title, $message, $row['id'], 'equipment')) {
                        $remindersCreated++;
                    }
                }
            }
        }
        mysqli_stmt_close($dueStmt);
    }
    
    // ============ 2. CHECK FOR OVERDUE EQUIPMENT ============
    $overdueSql = "SELECT r.id, r.reference, r.end_datetime, er.equipment_name, er.quantity,
                   DATEDIFF(NOW(), r.end_datetime) as days_overdue
                   FROM requests r
                   JOIN equipment_requests er ON r.id = er.request_id
                   WHERE r.resident_id = ? 
                   AND r.status IN ('approved', 'borrowed')
                   AND r.request_type = 'equipment'
                   AND r.end_datetime < NOW()";
    
    $overdueStmt = mysqli_prepare($conn, $overdueSql);
    
    if ($overdueStmt) {
        mysqli_stmt_bind_param($overdueStmt, "i", $resident_id);
        mysqli_stmt_execute($overdueStmt);
        $overdueResult = mysqli_stmt_get_result($overdueStmt);
        
        if ($overdueResult) {
            while ($row = mysqli_fetch_assoc($overdueResult)) {
                $daysOverdue = (int)$row['days_overdue'];
                
                if (!wasNotificationSentRecently($conn, $resident_id, 'equipment_overdue', $row['id'], 24)) {
                    $title = "🚨 Equipment Overdue - Action Required!";
                    $message = "Your borrowed {$row['equipment_name']} ({$row['quantity']} item(s)) is {$daysOverdue} day(s) overdue. Please return it immediately to avoid penalties.";
                    
                    if (createNotification($conn, $resident_id, 'equipment_overdue', $title, $message, $row['id'], 'equipment')) {
                        $overdueCreated++;
                    }
                }
            }
        }
        mysqli_stmt_close($overdueStmt);
    }
    
    echo json_encode([
        'success' => true,
        'reminders_created' => $remindersCreated,
        'overdue_created' => $overdueCreated
    ]);
}

/**
 * Check for documents whose pickup date is TODAY and send a real-time reminder.
 * 
 * - Fires ONLY on the actual pickup date (not before, not after)
 * - Resident can claim anytime — this is just a reminder
 * - Real-time via client polling (every 30s)
 * - Sends ONE reminder per request per day
 */
function checkPickupReminders($conn, $resident_id) {
    $created = 0;
    $todayDate = date('Y-m-d');
    
    // Verify notifications table exists
    $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
    if (!$tableCheck || mysqli_num_rows($tableCheck) === 0) {
        echo json_encode(['success' => true, 'created' => 0]);
        return;
    }
    
    /*
     * Find approved/completed documents where:
     *  - pickup_date == TODAY (pickup day has arrived)
     *  - claimed_at IS NULL (not yet claimed)
     *  - No "document_pickup_today" notification was already sent today for this request
     */
        $sql = "SELECT r.id, r.document_type, r.pickup_date, r.pickup_time
            FROM document_requests r
            WHERE r.resident_id = ?
            AND r.status = 'approved'
            AND r.claimed_at IS NULL
            AND r.pickup_date = ?
            AND NOT EXISTS (
                SELECT 1 FROM notifications n
                WHERE n.resident_id = r.resident_id
                AND n.type = 'document_pickup_today'
                AND n.reference_id = r.id
                AND n.reference_type = 'document'
                AND DATE(n.created_at) = CURDATE()
            )
            ORDER BY r.pickup_time ASC";
    
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log("checkPickupReminders: Prepare failed - " . mysqli_error($conn));
        echo json_encode(['success' => true, 'created' => 0]);
        return;
    }
    
    mysqli_stmt_bind_param($stmt, "is", $resident_id, $todayDate);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $docName = $row['document_type'];
        $pickupTime = $row['pickup_time'];
        
        $timeDisplay = !empty($pickupTime) 
            ? ' at ' . date('g:i A', strtotime($pickupTime)) 
            : '';
        
        $notifTitle = "📄 Document Pickup Today!";
        $notifMessage = "Reminder: Your {$docName} is scheduled for pickup today{$timeDisplay}. " .
            "You may claim it anytime during office hours (Mon–Fri, 8:00 AM – 5:00 PM). " .
            "Bring a valid ID and your QR code.";
        
        $notifId = createNotification(
            $conn, 
            $resident_id, 
            'document_pickup_today', 
            $notifTitle, 
            $notifMessage, 
            $row['id'], 
            'document'
        );
        
        if ($notifId) {
            $created++;
            error_log("checkPickupReminders: Sent pickup-today notification for request #{$row['id']}");
        }
    }
    
    mysqli_stmt_close($stmt);
    
    echo json_encode([
        'success' => true,
        'created' => $created
    ]);
}

/**
 * Mark notes as read (for document requests)
 */
function markNotesAsRead($conn, $resident_id) {
    $request_id = intval($_POST['request_id'] ?? 0);
    
    if ($request_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
        return;
    }
    
    $check_sql = "SELECT id FROM document_requests WHERE id = ? AND resident_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($check_stmt, "ii", $request_id, $resident_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    
    if (mysqli_num_rows($check_result) == 0) {
        echo json_encode(['success' => false, 'message' => 'Request not found']);
        return;
    }
    mysqli_stmt_close($check_stmt);
    
    $sql = "UPDATE document_requests SET last_viewed = NOW() WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $request_id);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true, 'message' => 'Notes marked as read']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to mark as read']);
    }
    mysqli_stmt_close($stmt);
}

/**
 * Check for new notes (admin responses)
 */
function checkNewNotes($conn, $resident_id) {
    $sql = "SELECT id, admin_notes, last_viewed, 
            CASE 
                WHEN admin_notes IS NOT NULL AND admin_notes != '' AND (last_viewed IS NULL OR last_viewed < processed_date) 
                THEN 1 ELSE 0 
            END as has_unread_notes
            FROM document_requests 
            WHERE resident_id = ? AND status IN ('approved', 'rejected', 'completed')
            AND admin_notes IS NOT NULL AND admin_notes != ''";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $resident_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $unreadCount = 0;
    $unreadRequests = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        if ($row['has_unread_notes']) {
            $unreadCount++;
            $unreadRequests[] = [
                'id' => $row['id'],
                'has_unread' => true
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'unread_count' => $unreadCount,
        'unread_requests' => $unreadRequests
    ]);
    mysqli_stmt_close($stmt);
}

// ============ SWITCH STATEMENT ============
switch($action) {
    case 'get_documents':
        getDocuments($conn);
        break;
    case 'get_document_fields':
        getDocumentFields($conn);
        break;
    case 'get_requests':
        getRequests($conn, $resident_id);
        break;
    case 'submit_request':
        submitRequest($conn, $resident_id);
        break;
    case 'get_stats':
        getStats($conn, $resident_id);
        break;
    case 'cancel_request':
        cancelRequest($conn, $resident_id);
        break;  
    case 'get_request_details':
        getRequestDetails($conn, $resident_id);
        break;
    case 'update_request':
        updateRequest($conn, $resident_id);
        break; 
    case 'mark_notes_read':
        markNotesAsRead($conn, $resident_id);
        break;
    case 'check_new_notes':
        checkNewNotes($conn, $resident_id);
        break;
    case 'check_unclaimed_documents':
        $result = checkUnclaimedDocuments($conn, $resident_id);
        echo json_encode($result);
        break;
    case 'create_payment':
        createPayment($conn, $resident_id);
        break;
    case 'get_payment_status':
        getPaymentStatus($conn, $resident_id);
        break;
    case 'upload_payment_proof':
        uploadPaymentProof($conn, $resident_id);
        break;
    case 'get_pending_payments':
        getPendingPayments($conn, $resident_id);
        break;
    case 'confirm_pay_at_claim':
        confirmPayAtClaim($conn, $resident_id);
        break;
    case 'get_barangay_payment_info':
        getBarangayPaymentInfo($conn);
        break;
    case 'get_payment_info':
        getPaymentInfo($conn, $resident_id);
        break;
    case 'simulate_payment':
        simulatePayment($conn, $resident_id);
        break;
    case 'pay_at_claim':
        payAtClaim($conn, $resident_id);
        break;
    case 'get_qr_code_info':
        getQRCodeInfo($conn, $resident_id);
        break;
    case 'get_resident_profile':
        getResidentProfile($conn, $resident_id);
        break;
    case 'detect_resident_type':
        echo json_encode(detectResidentType($conn, $resident_id));
        break;
    case 'search_masterlist':
        echo json_encode(searchMasterlist($conn));
        break;
    // ============ NOTIFICATION CASES ============
    case 'get_all_notifications':
        getAllNotifications($conn, $resident_id);
        break;
    case 'mark_notification_read':
        markNotificationRead($conn, $resident_id);
        break;
    case 'mark_all_notifications_read':
        markAllNotificationsRead($conn, $resident_id);
        break;
    case 'get_unread_count':
        getUnreadCount($conn, $resident_id);
        break;
    case 'check_return_reminders':
        checkReturnReminders($conn, $resident_id);
        break;
    case 'check_pickup_reminders':
        checkPickupReminders($conn, $resident_id);
        break;
    case 'check_new_notifications':
        checkNewNotifications($conn, $resident_id);
        break;
        case 'delete_selected_notifications':
        deleteSelectedNotifications($conn, $resident_id);
        break;

    case 'save_push_subscription':
        $data = $json_input;
        if (!$data || empty($data['endpoint'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid subscription']);
            break;
        }
        unset($data['action']);
        $json = json_encode($data);
        $s = mysqli_prepare($conn, "UPDATE resident SET push_subscription = ? WHERE id = ?");
        mysqli_stmt_bind_param($s, "si", $json, $resident_id);
        $ok = mysqli_stmt_execute($s);
        mysqli_stmt_close($s);
        echo json_encode(['success' => $ok]);
        break;

    case 'remove_push_subscription':
        $s = mysqli_prepare($conn, "UPDATE resident SET push_subscription = NULL WHERE id = ?");
        mysqli_stmt_bind_param($s, "i", $resident_id);
        $ok = mysqli_stmt_execute($s);
        mysqli_stmt_close($s);
        echo json_encode(['success' => $ok]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}


?>