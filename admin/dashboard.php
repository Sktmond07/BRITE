<?php
session_start();

// Include Composer autoloader (if you have it)
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Include database configuration
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/email_helper.php';
require_once __DIR__ . '/../includes/DocumentGenerator.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../sign_in.php');
    exit();
}

// Add cache control headers to prevent back button after logout
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Function to get resident details by ID
function getResidentDetails($conn, $id) {
    $id = intval($id);
    $sql = "SELECT id, username, email, first_name, last_name, phone, address, 
                   date_of_birth, gender, is_verified, scanned_document, 
                   created_at, updated_at, last_login 
            FROM resident 
            WHERE id = ? AND is_active = 1";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result && mysqli_num_rows($result) > 0) {
        return mysqli_fetch_assoc($result);
    }
    return null;
}

// Function to get verified residents
function getVerifiedResidents($conn) {
    $sql = "SELECT id, first_name, last_name, email, address, phone, is_verified, created_at 
            FROM resident 
            WHERE is_verified = 1 AND is_active = 1 
            ORDER BY created_at DESC";
    $result = mysqli_query($conn, $sql);
    
    $residents = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $residents[] = [
                'id' => $row['id'],
                'name' => $row['first_name'] . ' ' . $row['last_name'],
                'email' => $row['email'],
                'phone' => $row['phone'] ?: 'Not provided',
                'address' => $row['address'] ?: 'Not specified',
                'status' => 'verified',
                'created_at' => $row['created_at']
            ];
        }
    }
    return $residents;
}

// Function to get unverified residents
function getUnverifiedResidents($conn) {
    $sql = "SELECT id, first_name, last_name, email, address, phone, is_verified, created_at, scanned_document 
            FROM resident 
            WHERE is_verified = 0 AND is_active = 1 
            ORDER BY created_at DESC";
    $result = mysqli_query($conn, $sql);
    
    $residents = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $docPath = $row['scanned_document'];
            if ($docPath && !empty($docPath)) {
                $docPath = ltrim($docPath, '/');
                if (strpos($docPath, 'uploads/') !== 0) {
                    $docPath = 'uploads/' . $docPath;
                }
            }
            
            $residents[] = [
                'id' => $row['id'],
                'name' => $row['first_name'] . ' ' . $row['last_name'],
                'email' => $row['email'],
                'phone' => $row['phone'] ?: 'Not provided',
                'address' => $row['address'] ?: 'Not specified',
                'status' => 'unverified',
                'created_at' => $row['created_at'],
                'scanned_document' => $docPath
            ];
        }
    }
    return $residents;
}

// Function to get total counts
function getDashboardCounts($conn) {
    $verified = 0;
    $unverified = 0;
    $total = 0;
    
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM resident WHERE is_verified = 1 AND is_active = 1");
    if ($result) {
        $verified = mysqli_fetch_assoc($result)['count'];
    }
    
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM resident WHERE is_verified = 0 AND is_active = 1");
    if ($result) {
        $unverified = mysqli_fetch_assoc($result)['count'];
    }
    
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM resident WHERE is_active = 1");
    if ($result) {
        $total = mysqli_fetch_assoc($result)['count'];
    }
    
    return ['verified' => $verified, 'unverified' => $unverified, 'total' => $total];
}

// Function to verify a resident and send email confirmation
function verifyResident($conn, $id) {
    $id = intval($id);
    
    $resident = getResidentDetails($conn, $id);
    if (!$resident) {
        return ['success' => false, 'message' => 'Resident not found'];
    }
    
    if ($resident['is_verified'] == 1) {
        return ['success' => false, 'message' => 'Account is already verified'];
    }
    
    $sql = "UPDATE resident SET is_verified = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND is_verified = 0";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    
    if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
        $emailSent = sendVerificationConfirmationEmail(
            $resident['email'], 
            $resident['first_name'] . ' ' . $resident['last_name']
        );
        
        return [
            'success' => true, 
            'email_sent' => $emailSent,
            'message' => $resident['first_name'] . ' ' . $resident['last_name'] . ' has been verified successfully.',
            'email_status' => $emailSent ? 'Email notification sent to ' . $resident['email'] : 'Account verified but email notification failed'
        ];
    }
    
    return ['success' => false, 'message' => 'Failed to verify account.'];
}

// Handle AJAX requests for verification
if (isset($_POST['action']) && $_POST['action'] === 'verify_account') {
    header('Content-Type: application/json');
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    
    if ($id > 0) {
        $result = verifyResident($conn, $id);
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid resident ID']);
    }
    exit;
}

// Handle AJAX requests to get resident details
if (isset($_GET['action']) && $_GET['action'] === 'get_resident' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = intval($_GET['id']);
    $details = getResidentDetails($conn, $id);
    if ($details) {
        if ($details['scanned_document'] && !empty($details['scanned_document'])) {
            $docPath = ltrim($details['scanned_document'], '/');
            if (strpos($docPath, 'uploads/') !== 0) {
                $docPath = 'uploads/' . $docPath;
            }
            $details['scanned_document'] = $docPath;
        }
        echo json_encode(['success' => true, 'data' => $details]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Resident not found']);
    }
    exit;
}

// Handle AJAX requests to get counts
if (isset($_GET['action']) && $_GET['action'] === 'get_counts') {
    header('Content-Type: application/json');
    $counts = getDashboardCounts($conn);
    echo json_encode($counts);
    exit;
}

// ============ DOCUMENT MANAGEMENT FUNCTIONS ============

// Get all document types
function getDocumentTypes($conn) {
    $sql = "SELECT * FROM document_types ORDER BY name ASC";
    $result = mysqli_query($conn, $sql);
    $documents = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $documents[] = $row;
        }
    }
    return $documents;
}

// Get document type by ID
function getDocumentTypeById($conn, $id) {
    $id = intval($id);
    $sql = "SELECT * FROM document_types WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result && mysqli_num_rows($result) > 0) {
        return mysqli_fetch_assoc($result);
    }
    return null;
}

// Add new document type
function addDocumentType($conn, $name, $description, $fee) {
    $name = mysqli_real_escape_string($conn, $name);
    $description = mysqli_real_escape_string($conn, $description);
    $fee = floatval($fee);
    
    $sql = "INSERT INTO document_types (name, description, fee, is_active) VALUES (?, ?, ?, 1)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ssd", $name, $description, $fee);
    
    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'id' => mysqli_insert_id($conn)];
    }
    return ['success' => false, 'message' => 'Failed to add document type'];
}

// Update document type
function updateDocumentType($conn, $id, $name, $description, $fee, $is_active) {
    $id = intval($id);
    $name = mysqli_real_escape_string($conn, $name);
    $description = mysqli_real_escape_string($conn, $description);
    $fee = floatval($fee);
    $is_active = intval($is_active);
    
    $sql = "UPDATE document_types SET name = ?, description = ?, fee = ?, is_active = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ssdii", $name, $description, $fee, $is_active, $id);
    
    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true];
    }
    return ['success' => false, 'message' => 'Failed to update document type'];
}

// Delete document type
function deleteDocumentType($conn, $id) {
    $id = intval($id);
    $sql = "DELETE FROM document_types WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    
    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true];
    }
    return ['success' => false, 'message' => 'Failed to delete document type'];
}

// Get document requests with resident info
function getDocumentRequests($conn, $status = null, $page = 1, $perPage = 10) {
    $offset = ($page - 1) * $perPage;
    $status = $status && $status !== 'all' ? mysqli_real_escape_string($conn, $status) : null;
    
    $countSql = "SELECT COUNT(*) as total FROM document_requests dr JOIN resident r ON dr.resident_id = r.id";
    if ($status) {
        $countSql .= " WHERE dr.status = '$status'";
    }
    $countResult = mysqli_query($conn, $countSql);
    $totalRecords = $countResult ? mysqli_fetch_assoc($countResult)['total'] : 0;
    $totalPages = ceil($totalRecords / $perPage);
    
    $sql = "SELECT dr.*, r.first_name, r.last_name, r.email, r.phone 
            FROM document_requests dr 
            JOIN resident r ON dr.resident_id = r.id";
    
    if ($status) {
        $sql .= " WHERE dr.status = '$status'";
    }
    
    $sql .= " ORDER BY dr.request_date DESC LIMIT $offset, $perPage";
    
    $result = mysqli_query($conn, $sql);
    $requests = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $requests[] = $row;
        }
    }
    
    return ['requests' => $requests, 'totalPages' => $totalPages, 'currentPage' => $page, 'totalRecords' => $totalRecords];
}

// Get pending count for badge
function getPendingCount($conn) {
    $sql = "SELECT COUNT(*) as count FROM document_requests WHERE status = 'pending'";
    $result = mysqli_query($conn, $sql);
    if ($result) {
        return mysqli_fetch_assoc($result)['count'];
    }
    return 0;
}

// Handle AJAX requests for filtering with pagination
if (isset($_GET['doc_action']) && $_GET['doc_action'] === 'filter_requests') {
    header('Content-Type: application/json');
    $status = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : null;
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $perPage = 10;
    
    $offset = ($page - 1) * $perPage;
    
    $sql = "SELECT dr.*, r.first_name, r.last_name, r.email, r.phone 
            FROM document_requests dr 
            JOIN resident r ON dr.resident_id = r.id";
    
    $countSql = "SELECT COUNT(*) as total FROM document_requests dr JOIN resident r ON dr.resident_id = r.id";
    
    if ($status && $status !== 'all') {
        $status = mysqli_real_escape_string($conn, $status);
        $sql .= " WHERE dr.status = '$status'";
        $countSql .= " WHERE dr.status = '$status'";
    }
    
    $sql .= " ORDER BY dr.request_date DESC LIMIT $offset, $perPage";
    
    $result = mysqli_query($conn, $sql);
    $requests = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $requests[] = $row;
        }
    }
    
    $countResult = mysqli_query($conn, $countSql);
    $totalRecords = $countResult ? mysqli_fetch_assoc($countResult)['total'] : 0;
    $totalPages = ceil($totalRecords / $perPage);
    
    echo json_encode([
        'success' => true, 
        'data' => $requests, 
        'totalPages' => $totalPages, 
        'currentPage' => $page, 
        'totalRecords' => $totalRecords
    ]);
    exit;
}


// ============ DASHBOARD STATS AJAX HANDLERS ============

// Get document statistics for dashboard
if (isset($_GET['dashboard_action']) && $_GET['dashboard_action'] === 'get_document_stats') {
    header('Content-Type: application/json');
    
    // Get document request stats
    $docSql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
            FROM document_requests";
    $docResult = mysqli_query($conn, $docSql);
    $docStats = mysqli_fetch_assoc($docResult);
    
    echo json_encode(['success' => true, 'document' => $docStats]);
    exit;
}

// Get recent document requests
if (isset($_GET['dashboard_action']) && $_GET['dashboard_action'] === 'get_recent_documents') {
    header('Content-Type: application/json');
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 5;
    $sql = "SELECT dr.*, r.first_name, r.last_name, r.email 
            FROM document_requests dr 
            JOIN resident r ON dr.resident_id = r.id 
            ORDER BY dr.request_date DESC 
            LIMIT $limit";
    $result = mysqli_query($conn, $sql);
    $requests = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $requests[] = $row;
        }
    }
    echo json_encode(['success' => true, 'requests' => $requests]);
    exit;
}






// Handle AJAX requests to get pending count
if (isset($_GET['doc_action']) && $_GET['doc_action'] === 'get_pending_count') {
    header('Content-Type: application/json');
    $sql = "SELECT COUNT(*) as count FROM document_requests WHERE status = 'pending'";
    $result = mysqli_query($conn, $sql);
    $count = 0;
    if ($result) {
        $count = mysqli_fetch_assoc($result)['count'];
    }
    echo json_encode(['success' => true, 'pending_count' => $count]);
    exit;
}

// Handle AJAX request to get generated document file
if (isset($_GET['doc_action']) && $_GET['doc_action'] === 'get_document_file') {
    header('Content-Type: application/json');
    $request_id = isset($_GET['request_id']) ? intval($_GET['request_id']) : 0;
    if ($request_id > 0) {
        $sql = "SELECT document_path, qr_code_path FROM document_requests WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($result && $row = mysqli_fetch_assoc($result)) {
            $filename = $row['document_path'];
            if ($filename && file_exists(__DIR__ . '/../generated_documents/' . $filename)) {
                echo json_encode(['success' => true, 'file_path' => $filename, 'qr_code' => $row['qr_code_path']]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Document file not found']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Request not found']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
    }
    exit;
}

// Handle AJAX request to get QR code
if (isset($_GET['doc_action']) && $_GET['doc_action'] === 'get_qr_code') {
    header('Content-Type: application/json');
    $request_id = isset($_GET['request_id']) ? intval($_GET['request_id']) : 0;
    if ($request_id > 0) {
        $sql = "SELECT qr_code_path, qr_verification_code FROM document_requests WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($result && $row = mysqli_fetch_assoc($result)) {
            $fullPath = $row['qr_code_path'] ? '../' . $row['qr_code_path'] : null;
            echo json_encode([
                'success' => true, 
                'qr_code_path' => $row['qr_code_path'],
                'full_url' => $fullPath,
                'verification_code' => $row['qr_verification_code']
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'QR code not found']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
    }
    exit;
}

// Get custom fields for a document type
function getCustomFields($conn, $document_type_id) {
    $document_type_id = intval($document_type_id);
    $sql = "SELECT * FROM document_custom_fields WHERE document_type_id = ? AND is_active = 1 ORDER BY sort_order ASC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $document_type_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $fields = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            if ($row['field_options']) {
                $row['field_options'] = json_decode($row['field_options'], true);
            }
            $fields[] = $row;
        }
    }
    return $fields;
}

// Get custom data for a request
function getRequestCustomData($conn, $request_id) {
    $request_id = intval($request_id);
    $sql = "SELECT field_name, field_value FROM document_requests_custom_data WHERE request_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $request_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $data = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $data[$row['field_name']] = $row['field_value'];
        }
    }
    return $data;
}

// Update request status with auto document generation
// Update request status with auto document generation and QR code
// Update request status with auto document generation and QR code (silent QR generation)
function updateRequestStatus($conn, $request_id, $status, $admin_notes = null) {
    $request_id = intval($request_id);
    $status = mysqli_real_escape_string($conn, $status);
    $admin_notes = $admin_notes ? mysqli_real_escape_string($conn, $admin_notes) : null;
    
    $sql = "UPDATE document_requests SET status = ?, admin_notes = ?, processed_date = CURRENT_TIMESTAMP WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ssi", $status, $admin_notes, $request_id);
    
    if (mysqli_stmt_execute($stmt)) {
        if ($status === 'approved') {
            try {
                // 1. Generate the main document
                require_once __DIR__ . '/../includes/document_generators/DocumentGeneratorFactory.php';
                $factory = new DocumentGeneratorFactory($conn);
                $result = $factory->generateDocument($request_id);
                
                // 2. Generate QR Code Silently (No display, just save to database)
                require_once __DIR__ . '/qrcode/phpqrcode.php';
                
                // Get request details for QR code
                $detailsSql = "SELECT dr.*, r.first_name, r.last_name, r.email 
                              FROM document_requests dr 
                              JOIN resident r ON dr.resident_id = r.id 
                              WHERE dr.id = ?";
                $detailsStmt = mysqli_prepare($conn, $detailsSql);
                mysqli_stmt_bind_param($detailsStmt, "i", $request_id);
                mysqli_stmt_execute($detailsStmt);
                $detailsResult = mysqli_stmt_get_result($detailsStmt);
                $requestDetails = mysqli_fetch_assoc($detailsResult);
                
                if ($requestDetails) {
                    // Create QR code directory if not exists
                    $qrDir = __DIR__ . '/../generated_qrcodes/';
                    if (!file_exists($qrDir)) {
                        mkdir($qrDir, 0777, true);
                    }
                    
                    // Generate unique verification code
                    $verificationCode = md5($request_id . $requestDetails['email'] . time() . uniqid());
                    
                    // Create QR data payload
                    $qrPayload = json_encode([
                        'request_id' => $request_id,
                        'document_type' => $requestDetails['document_type'],
                        'resident_name' => $requestDetails['first_name'] . ' ' . $requestDetails['last_name'],
                        'resident_email' => $requestDetails['email'],
                        'issue_date' => date('Y-m-d H:i:s'),
                        'verification_code' => $verificationCode,
                        'verified' => false
                    ]);
                    
                    // Generate QR code using phpqrcode
                    $qrFilename = 'qr_document_' . $request_id . '_' . time() . '.png';
                    $qrPath = $qrDir . $qrFilename;
                    
                    // Generate the QR code image
                    QRcode::png($qrPayload, $qrPath, QR_ECLEVEL_H, 10, 2);
                    
                    // Update database with QR code path and verification code (silent)
                    if (file_exists($qrPath) && filesize($qrPath) > 0) {
                        $relativePath = 'generated_qrcodes/' . $qrFilename;
                        $updateQrSql = "UPDATE document_requests SET qr_code_path = ?, qr_verification_code = ? WHERE id = ?";
                        $updateQrStmt = mysqli_prepare($conn, $updateQrSql);
                        mysqli_stmt_bind_param($updateQrStmt, "ssi", $relativePath, $verificationCode, $request_id);
                        mysqli_stmt_execute($updateQrStmt);
                        
                        // Log success (optional - remove if you don't want logs)
                        error_log("QR Code generated for request {$request_id}");
                    }
                }
                
                $message = 'Request approved and document generated successfully';
                
                return [
                    'success' => true, 
                    'message' => $message,
                    'document_path' => $result['filename'] ?? ''
                ];
            } catch (Exception $e) {
                error_log("Document generation error: " . $e->getMessage());
                return ['success' => true, 'message' => 'Request approved but document generation error: ' . $e->getMessage()];
            }
        }
        return ['success' => true, 'message' => 'Request status updated successfully'];
    }
    return ['success' => false, 'message' => 'Failed to update request status'];
}
// Handle AJAX requests for document management
if (isset($_POST['doc_action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['doc_action']) {
        case 'add_document':
            $result = addDocumentType($conn, $_POST['name'], $_POST['description'], $_POST['fee']);
            echo json_encode($result);
            break;
            
        case 'update_document':
            $result = updateDocumentType($conn, $_POST['id'], $_POST['name'], $_POST['description'], $_POST['fee'], $_POST['is_active']);
            echo json_encode($result);
            break;
            
        case 'delete_document':
            $result = deleteDocumentType($conn, $_POST['id']);
            echo json_encode($result);
            break;
            
        case 'get_document':
            $doc = getDocumentTypeById($conn, $_POST['id']);
            if ($doc) {
                echo json_encode(['success' => true, 'data' => $doc]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Document not found']);
            }
            break;
            
       case 'update_request_status':
            $result = updateRequestStatus($conn, $_POST['request_id'], $_POST['status'], $_POST['admin_notes'] ?? null);
            echo json_encode($result);
            break;
            
        case 'get_request_details':
            $request_id = intval($_POST['request_id']);
            $sql = "SELECT dr.*, r.first_name, r.last_name, r.email, r.phone, r.address 
                    FROM document_requests dr 
                    JOIN resident r ON dr.resident_id = r.id 
                    WHERE dr.id = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "i", $request_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            if ($result && $row = mysqli_fetch_assoc($result)) {
                $custom_data = getRequestCustomData($conn, $request_id);
                $custom_fields = getCustomFields($conn, $row['document_type_id']);
                echo json_encode(['success' => true, 'request' => $row, 'custom_data' => $custom_data, 'custom_fields' => $custom_fields]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Request not found']);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    exit;
}

// Get initial data for page load
$verifiedAccounts = getVerifiedResidents($conn);
$unverifiedAccounts = getUnverifiedResidents($conn);
$counts = getDashboardCounts($conn);
$documentTypes = getDocumentTypes($conn);
$documentRequestsResult = getDocumentRequests($conn, null, 1);
$documentRequests = $documentRequestsResult['requests'];
$totalPages = $documentRequestsResult['totalPages'];
$currentPage = $documentRequestsResult['currentPage'];
$totalRecords = $documentRequestsResult['totalRecords'];
$pendingCount = getPendingCount($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
  <title>Barangay System | Admin Dashboard</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

  <style>
   <?php include 'admin.css'; ?>
  </style>

  <!-- Cropper.js CSS and JS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
</head>
<body>
<!-- Mobile Menu Toggle Button -->
<button class="menu-toggle" id="menuToggle">
  <i class="fas fa-bars"></i>
</button>

<!-- Overlay for mobile -->
<div class="sidebar-overlay hide" id="sidebarOverlay"></div>

<div class="dashboard-container">
  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <div class="brand">
        <div class="logo-container">
          <img src="logo.jpg" alt="Barangay Logo" onerror="this.onerror=null; this.parentElement.innerHTML='<i class=\'fas fa-landmark\'></i>';">
        </div>
        <h2>BRITE</h2>
      </div>
      <div class="sidebar-sub">San Bartolome, Sto Tomas, Pampanga</div>
    </div>
    <div class="nav-menu">
      <div class="nav-item standalone" data-view="dashboard">
        <i class="fas fa-tachometer-alt"></i>
        <span>Dashboard</span>
      </div>
      
      <div class="nav-item has-submenu" id="accountsParent">
        <i class="fas fa-id-card"></i>
        <span>Accounts</span>
        <i class="fas fa-chevron-down toggle-icon"></i>
      </div>
      <ul class="submenu" id="accountsSubmenu">
        <li><a data-subview="verified" class="sub-option"><i class="fas fa-check-circle"></i> Verified Accounts</a></li>
        <li><a data-subview="unverified" class="sub-option"><i class="fas fa-clock"></i> Not Verified Accounts</a></li>
      </ul>
      
      <div class="nav-item has-submenu" id="documentsParent">
        <i class="fas fa-file-alt"></i>
        <span>Documents</span>
        <i class="fas fa-chevron-down toggle-icon"></i>
      </div>
      <ul class="submenu" id="documentsSubmenu">
        <li><a data-subview="manage_documents" class="sub-option"><i class="fas fa-cog"></i> Manage Documents</a></li>
        <li><a data-subview="request_list" class="sub-option"><i class="fas fa-list-alt"></i> Request List</a></li>
        <li><a data-subview="certification" class="sub-option" id="certificationSubmenu"><i class="fas fa-certificate"></i> Certification</a></li>
      </ul>
     
<div class="nav-item has-submenu" id="equipmentParent">
  <i class="fas fa-tools"></i>
  <span>Equipment & Facilities</span>
  <i class="fas fa-chevron-down toggle-icon"></i>
</div>
<ul class="submenu" id="equipmentSubmenu">
     
  <li><a data-subview="equipment_list" class="sub-option"><i class="fas fa-list"></i> Equipment List</a></li>
  <li><a data-subview="equipment_bookings" class="sub-option"><i class="fas fa-calendar-alt"></i> Bookings</a></li>
 
</ul>
<div class="nav-item has-submenu" id="officialsParent">
    <i class="fas fa-users-cog"></i>
    <span>Officials & Staff</span>
    <i class="fas fa-chevron-down toggle-icon"></i>
</div>
<ul class="submenu" id="officialsSubmenu">
    <li><a data-subview="manage_officials" class="sub-option"><i class="fas fa-user-tie"></i> Manage Officials</a></li>
</ul>


      <div class="nav-item standalone" data-view="reports">
        <i class="fas fa-chart-line"></i>
        <span>Reports</span>
      </div>
      <div class="nav-item standalone" data-view="settings">
        <i class="fas fa-cog"></i>
        <span>Settings</span>
      </div>
    </div>
    <div class="sidebar-footer">
      <div class="admin-badge">
        <div class="mini-avatar">
          <i class="fas fa-user-shield"></i>
        </div>
        <div class="admin-info">
          <h5>Barangay Admin</h5>
          <p>Administrator</p>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="main-content">
    <div class="top-header">
      <div class="page-title">
        <h1>Barangay Dashboard</h1>
        <p>Manage residents and barangay services</p>
      </div>
      
      <div class="profile-area" id="profileArea">
        <div class="profile-text">
          <div class="name">Barangay Admin</div>
          <div class="role">Administrator</div>
        </div>
        <div class="avatar-container">
          <div class="avatar-fallback"><i class="fas fa-user-circle"></i></div>
        </div>
        
        <div class="profile-dropdown" id="profileDropdown">
          <div class="dropdown-header">
            <div class="user-name">Barangay Admin</div>
            <div class="user-email">admin@barangay.gov.ph</div>
          </div>
          <div class="dropdown-divider"></div>
          <div class="dropdown-item" id="settingsBtn">
            <i class="fas fa-cog"></i>
            <span>Settings</span>
          </div>
          <div class="dropdown-item logout-item" id="logoutBtn">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Certification Section -->
    <div id="certificationSection" class="certification-section">
      <div class="certification-header">
        <div class="certification-title">
          <h3><i class="fas fa-certificate"></i> <span id="certificationDocTitle">Certification Document</span></h3>
          <p id="certificationDocInfo">Select a document to view</p>
        </div>
        <div class="certification-actions">
          <button class="cert-action-btn print" onclick="printCertification()">
            <i class="fas fa-print"></i> Print
          </button>
          <button class="cert-action-btn download" onclick="downloadCertification()">
            <i class="fas fa-download"></i> Download
          </button>
          
          <button class="cert-action-btn close" onclick="closeCertification()">
            <i class="fas fa-times"></i> Close
          </button>
        </div>
      </div>
      <div class="certification-content">
        <iframe id="certificationIframe" class="certification-iframe" src=""></iframe>
      </div>
    </div>

    <!-- QR Code Section -->
    

    <div class="dashboard-body" id="dashboardBody">
      <!-- Dynamic content will be loaded here -->
    </div>
  </main>
</div>

<!-- Logout Form -->
<form id="logoutForm" method="POST" action="../logout.php" style="display: none;"></form>

<!-- Modals -->
<div id="residentModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2><i class="fas fa-user-circle"></i> Resident Details</h2>
      <button class="close-modal" onclick="closeModal()">&times;</button>
    </div>
    <div class="modal-body" id="modalBody">
      <div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading details...</p></div>
    </div>
    <div class="modal-footer" id="residentModalFooter">
      <button class="btn-close" onclick="closeModal()">Close</button>
    </div>
  </div>
</div>

<div id="verifyModal" class="modal">
  <div class="modal-content verify-modal">
    <div class="modal-header">
      <h2><i class="fas fa-user-check"></i> Verify Account</h2>
      <button class="close-modal" onclick="closeVerifyModal()">&times;</button>
    </div>
    <div class="modal-body" id="verifyModalBody"></div>
  </div>
</div>

<div id="documentModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2 id="documentModalTitle"><i class="fas fa-file-alt"></i> Manage Document</h2>
      <button class="close-modal" onclick="closeDocumentModal()">&times;</button>
    </div>
    <div class="modal-body" id="documentModalBody"></div>
  </div>
</div>

<div id="requestModal" class="modal">
  <div class="modal-content" style="max-width: 700px;">
    <div class="modal-header">
      <h2><i class="fas fa-file-alt"></i> Request Details</h2>
      <button class="close-modal" onclick="closeRequestModal()">&times;</button>
    </div>
    <div class="modal-body" id="requestModalBody">
      <div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading request details...</p></div>
    </div>
    <div class="modal-footer">
      <button class="btn-close" onclick="closeRequestModal()">Close</button>
    </div>
  </div>
</div>

<!-- Approval Modal with Notes -->
<div id="approvalModal" class="modal">
  <div class="modal-content approval-modal">
    <div class="modal-header">
      <h2><i class="fas fa-check-circle"></i> Approve Request</h2>
      <button class="close-modal" onclick="closeApprovalModal()">&times;</button>
    </div>
    <div class="modal-body">
      <div class="notification-icon warning" style="text-align:center;"><i class="fas fa-pen-alt"></i></div>
      <div class="notification-title" style="text-align:center;">Add Admin Notes/Response</div>
      <div class="notification-message" style="text-align:center;">You can add optional notes or response that will be included in the request record.</div>
      <textarea id="approvalNotes" class="approval-notes" rows="4" placeholder="Enter notes or response for this approval... (Optional)"></textarea>
      <div class="verify-buttons">
        <button class="btn-confirm" id="confirmApprovalBtn" onclick="submitApproval()"><i class="fas fa-check-circle"></i> Confirm Approval</button>
        <button class="btn-cancel" onclick="closeApprovalModal()"><i class="fas fa-times"></i> Cancel</button>
      </div>
      <div id="approvalStatus" class="email-status" style="display:none;"></div>
    </div>
  </div>
</div>

<!-- Equipment Management Modals -->
<div id="equipmentModal" class="modal">
  <div class="modal-content" style="max-width: 600px;">
    <div class="modal-header">
      <h2 id="equipmentModalTitle"><i class="fas fa-tools"></i> Equipment</h2>
      <button class="close-modal" onclick="closeEquipmentModal()">&times;</button>
    </div>
    <div class="modal-body" id="equipmentModalBody"></div>
  </div>
</div>

<div id="bookingModal" class="modal">
  <div class="modal-content" style="max-width: 700px;">
    <div class="modal-header">
      <h2><i class="fas fa-calendar-check"></i> Booking Details</h2>
      <button class="close-modal" onclick="closeBookingModal()">&times;</button>
    </div>
    <div class="modal-body" id="bookingModalBody"></div>
    <div class="modal-footer">
      <button class="btn-close" onclick="closeBookingModal()">Close</button>
    </div>
  </div>
</div>

<div id="bookingApprovalModal" class="modal">
  <div class="modal-content approval-modal">
    <div class="modal-header">
      <h2><i class="fas fa-check-circle"></i> Process Booking</h2>
      <button class="close-modal" onclick="closeBookingApprovalModal()">&times;</button>
    </div>
    <div class="modal-body">
      <div class="notification-icon warning" style="text-align:center;"><i class="fas fa-pen-alt"></i></div>
      <div class="notification-title" style="text-align:center;" id="bookingActionTitle">Approve Booking</div>
      <div class="notification-message" style="text-align:center;">Add optional notes for this transaction.</div>
      <textarea id="bookingApprovalNotes" class="approval-notes" rows="4" placeholder="Enter notes... (Optional)"></textarea>
      <div class="verify-buttons">
        <button class="btn-confirm" id="confirmBookingBtn" onclick="submitBookingAction()"><i class="fas fa-check-circle"></i> Confirm</button>
        <button class="btn-cancel" onclick="closeBookingApprovalModal()"><i class="fas fa-times"></i> Cancel</button>
      </div>
      <div id="bookingApprovalStatus" class="email-status" style="display:none;"></div>
    </div>
  </div>
</div>

<div id="returnModal" class="modal">
  <div class="modal-content approval-modal">
    <div class="modal-header">
      <h2><i class="fas fa-undo-alt"></i> Mark as Returned</h2>
      <button class="close-modal" onclick="closeReturnModal()">&times;</button>
    </div>
    <div class="modal-body">
      <div class="notification-icon success" style="text-align:center;"><i class="fas fa-box-open"></i></div>
      <div class="notification-title" style="text-align:center;">Confirm Return</div>
      <div class="notification-message" style="text-align:center;">Mark this item as returned by the resident.</div>
      <textarea id="returnNotes" class="approval-notes" rows="3" placeholder="Condition notes... (Optional)"></textarea>
      <div class="verify-buttons">
        <button class="btn-confirm" onclick="confirmReturn()"><i class="fas fa-check-circle"></i> Confirm Return</button>
        <button class="btn-cancel" onclick="closeReturnModal()"><i class="fas fa-times"></i> Cancel</button>
      </div>
    </div>
  </div>
</div>


<!-- Schedule Modal -->
<div id="scheduleModal" class="modal">
    <div class="modal-content" style="max-width: 900px; width: 95%;">
        <div class="modal-header">
            <h2 id="scheduleModalTitle"><i class="fas fa-calendar-alt"></i> Equipment Schedule</h2>
            <button class="close-modal" onclick="closeScheduleModal()">&times;</button>
        </div>
        <div class="modal-body" id="scheduleModalBody" style="max-height: 70vh; overflow-y: auto;">
            <div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading schedule...</p></div>
        </div>
        <div class="modal-footer">
            <button class="btn-close" onclick="closeScheduleModal()">Close</button>
        </div>
    </div>
</div>

<!-- Day Bookings Modal -->
<div id="dayBookingsModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h2 id="dayBookingsModalTitle"><i class="fas fa-calendar-day"></i> Bookings for Date</h2>
            <button class="close-modal" onclick="closeDayBookingsModal()">&times;</button>
        </div>
        <div class="modal-body" id="dayBookingsModalBody" style="max-height: 60vh; overflow-y: auto;">
            <div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div>
        </div>
        <div class="modal-footer">
            <button class="btn-close" onclick="closeDayBookingsModal()">Close</button>
        </div>
    </div>
</div>

<!-- Equipment Items Modal -->
<div id="equipmentItemsModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h2 id="equipmentItemsModalTitle"><i class="fas fa-boxes"></i> Equipment Items</h2>
            <button class="close-modal" onclick="closeEquipmentItemsModal()">&times;</button>
        </div>
        <div class="modal-body" id="equipmentItemsModalBody"></div>
        <div class="modal-footer">
            <button class="btn-close" onclick="closeEquipmentItemsModal()">Close</button>
        </div>
    </div>
</div>

<!-- Manage Individual Item Modal -->
<div id="manageItemModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h2 id="manageItemModalTitle"><i class="fas fa-tools"></i> Manage Item</h2>
            <button class="close-modal" onclick="closeManageItemModal()">&times;</button>
        </div>
        <div class="modal-body" id="manageItemModalBody">
            <div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div>
        </div>
        <div class="modal-footer">
            <button class="btn-close" onclick="closeManageItemModal()">Close</button>
        </div>
    </div>
</div>

<!-- External JavaScript -->
<script src="equipment.js"></script>
<script src="admin_management.js"></script>


<script>
// ============ OFFICIALS & STAFF MANAGEMENT ============

// Add event listener for officials menu
const officialsParent = document.getElementById('officialsParent');
const officialsSubmenu = document.getElementById('officialsSubmenu');
let officialsExpanded = false;

function toggleOfficialsSubmenu(expand) {
    if (expand === undefined) officialsExpanded = !officialsExpanded;
    else officialsExpanded = expand;
    
    if (officialsExpanded) {
        officialsSubmenu.classList.add('open');
        officialsParent.querySelector('.toggle-icon').style.transform = 'rotate(180deg)';
    } else {
        officialsSubmenu.classList.remove('open');
        officialsParent.querySelector('.toggle-icon').style.transform = 'rotate(0deg)';
    }
}

toggleOfficialsSubmenu(false);
if (officialsParent) {
    officialsParent.addEventListener('click', (e) => {
        e.stopPropagation();
        toggleOfficialsSubmenu();
    });
}

const officialSubOptions = document.querySelectorAll('#officialsSubmenu .sub-option');
officialSubOptions.forEach(opt => {
    opt.addEventListener('click', async (e) => {
        e.preventDefault();
        const view = opt.getAttribute('data-subview');
        
        officialSubOptions.forEach(sub => sub.classList.remove('active-sub'));
        opt.classList.add('active-sub');
        document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active-parent', 'active'));
        if (officialsParent) officialsParent.classList.add('active-parent');
        
      // Inside the officials menu click handler, change:
if (view === 'manage_officials') {
    document.getElementById('dashboardBody').innerHTML = renderAdminManagement();
    await loadAdmins(1);  // Changed from loadAdmins('all', 1)
    closeCertification();
}
        
        if (!officialsExpanded) toggleOfficialsSubmenu(true);
        closeDropdown();
    });
});



// PHP data passed to JavaScript
const verifiedAccountsData = <?php echo json_encode($verifiedAccounts); ?>;
const unverifiedAccountsData = <?php echo json_encode($unverifiedAccounts); ?>;
const dashboardCounts = <?php echo json_encode($counts); ?>;
const documentTypesData = <?php echo json_encode($documentTypes); ?>;
const initialPendingCount = <?php echo $pendingCount; ?>;

// Variables
let pendingVerifyId = null;
let pendingVerifyName = null;
let pendingVerifyEmail = null;
let pendingApprovalRequestId = null;
let currentCertificationRequestId = null;
let currentCertificationFilename = null;
let currentCertificationDocType = null;
let currentCertificationResident = null;
let currentQRCodePath = null;

// Request list variables
let currentFilterStatus = 'pending';
let currentPage = 1;
let totalPages = 1;
let currentRequestsData = [];


function closeEquipmentItemsModal() {
    const modal = document.getElementById('equipmentItemsModal');
    if (modal) modal.style.display = 'none';
}



// ============ MODAL NOTIFICATION SYSTEM ============




function showSuccessModal(message, title = 'Success!', autoClose = true) {
    showNotificationModal('success', title, message, autoClose);
}

function showErrorModal(message, title = 'Error!', autoClose = true) {
    showNotificationModal('error', title, message, autoClose);
}

function showWarningModal(message, title = 'Warning!', autoClose = true) {
    showNotificationModal('warning', title, message, autoClose);
}

function showNotificationModal(type, title, message, autoClose = true) {
    const existingModal = document.querySelector('.notification-modal');
    if (existingModal) existingModal.remove();
    
    let iconClass = '', iconHtml = '';
    switch(type) {
        case 'success': iconClass = 'success'; iconHtml = '<i class="fas fa-check-circle"></i>'; break;
        case 'error': iconClass = 'error'; iconHtml = '<i class="fas fa-times-circle"></i>'; break;
        case 'warning': iconClass = 'warning'; iconHtml = '<i class="fas fa-exclamation-triangle"></i>'; break;
        case 'info': iconClass = 'info'; iconHtml = '<i class="fas fa-info-circle"></i>'; break;
    }
    
    const modal = document.createElement('div');
    modal.className = 'notification-modal';
    modal.innerHTML = `
        <div class="notification-modal-content">
            <div class="notification-icon ${iconClass}">${iconHtml}</div>
            <div class="notification-title">${escapeHtml(title)}</div>
            <div class="notification-message">${escapeHtml(message)}</div>
            <button class="notification-btn" onclick="this.closest('.notification-modal').remove()">OK</button>
        </div>
    `;
    document.body.appendChild(modal);
    if (autoClose && type === 'success') {
        setTimeout(() => { if (modal && modal.parentNode) modal.remove(); }, 2500);
    }
}

function showConfirmationModal(message, title, onConfirm, onCancel = null) {
    const existingModal = document.querySelector('.confirmation-modal');
    if (existingModal) existingModal.remove();
    
    const modal = document.createElement('div');
    modal.className = 'confirmation-modal';
    modal.innerHTML = `
        <div class="confirmation-modal-content">
            <div class="notification-icon warning"><i class="fas fa-question-circle"></i></div>
            <div class="notification-title">${escapeHtml(title)}</div>
            <div class="notification-message">${escapeHtml(message)}</div>
            <div class="confirmation-buttons">
                <button class="confirmation-btn-confirm" id="confirmYesBtn">Yes, Proceed</button>
                <button class="confirmation-btn-cancel" id="confirmNoBtn">Cancel</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    document.getElementById('confirmYesBtn').addEventListener('click', () => { modal.remove(); if (onConfirm) onConfirm(); });
    document.getElementById('confirmNoBtn').addEventListener('click', () => { modal.remove(); if (onCancel) onCancel(); });
}

function showPromptModal(title, placeholder, onConfirm, onCancel = null) {
    const existingModal = document.querySelector('.prompt-modal');
    if (existingModal) existingModal.remove();
    
    const modal = document.createElement('div');
    modal.className = 'prompt-modal';
    modal.innerHTML = `
        <div class="prompt-modal-content">
            <div class="prompt-title">${escapeHtml(title)}</div>
            <textarea id="promptInput" class="prompt-textarea" rows="4" placeholder="${escapeHtml(placeholder)}"></textarea>
            <div class="prompt-buttons">
                <button class="confirmation-btn-confirm" id="promptConfirmBtn">Confirm</button>
                <button class="confirmation-btn-cancel" id="promptCancelBtn">Cancel</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    document.getElementById('promptConfirmBtn').addEventListener('click', () => {
        const value = document.getElementById('promptInput').value;
        modal.remove();
        if (onConfirm && value.trim()) onConfirm(value.trim());
        else if (onConfirm && !value.trim()) showWarningModal('Please enter a value.', 'Input Required');
    });
    document.getElementById('promptCancelBtn').addEventListener('click', () => { modal.remove(); if (onCancel) onCancel(); });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function openDocument(documentPath) {
    if (!documentPath) { showWarningModal('No document available.', 'Document Not Found'); return; }
    let fullPath = documentPath;
    if (!fullPath.startsWith('http://') && !fullPath.startsWith('https://') && !fullPath.startsWith('/')) fullPath = '../' + fullPath;
    window.open(fullPath, '_blank');
}

// ============ QR CODE FUNCTIONS ============







// ============ CERTIFICATION SECTION FUNCTIONS ============

function showCertification(filename, requestId, documentType, residentName) {
    currentCertificationFilename = filename;
    currentCertificationRequestId = requestId;
    currentCertificationDocType = documentType;
    currentCertificationResident = residentName;
    
    document.getElementById('certificationDocTitle').innerHTML = `<i class="fas fa-certificate"></i> ${escapeHtml(documentType)}`;
    document.getElementById('certificationDocInfo').innerHTML = `<i class="fas fa-user"></i> ${escapeHtml(residentName)} | <i class="fas fa-calendar"></i> ${new Date().toLocaleDateString()}`;
    
    const iframe = document.getElementById('certificationIframe');
    iframe.src = `stream_pdf.php?file=${encodeURIComponent(filename)}`;
    
    const section = document.getElementById('certificationSection');
    section.classList.add('visible');
    
    setTimeout(() => {
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 100);
}

function closeCertification() {
    const section = document.getElementById('certificationSection');
    const iframe = document.getElementById('certificationIframe');
    section.classList.remove('visible');
    
    setTimeout(() => {
        iframe.src = '';
    }, 300);
    
    currentCertificationRequestId = null;
    currentCertificationFilename = null;

}

function printCertification() {
    const iframe = document.getElementById('certificationIframe');
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.print();
    }
}

function downloadCertification() {
    if (currentCertificationRequestId) {
        window.location.href = `download_document.php?request_id=${currentCertificationRequestId}`;
    }
}

// Load filtered requests
async function loadFilteredRequests(status, page = 1) {
    currentFilterStatus = status;
    currentPage = page;
    try {
        const url = `${window.location.href}?doc_action=filter_requests&status=${status}&page=${page}`;
        const response = await fetch(url);
        const data = await response.json();
        if (data.success) {
            currentRequestsData = data.data;
            totalPages = data.totalPages;
            const dashboardBody = document.getElementById('dashboardBody');
            if (dashboardBody) dashboardBody.innerHTML = renderRequestList();
        }
    } catch (error) { showErrorModal('Failed to load document requests.', 'Error'); }
}

// Generate pagination HTML
function generatePaginationHtml() {
    if (totalPages <= 1) return '';
    let html = '<div class="pagination-wrapper"><div class="pagination">';
    if (currentPage > 1) html += `<button class="page-btn" onclick="loadFilteredRequests('${currentFilterStatus}', ${currentPage - 1})"><i class="fas fa-chevron-left"></i> Prev</button>`;
    else html += `<button class="page-btn disabled" disabled><i class="fas fa-chevron-left"></i> Prev</button>`;
    
    const maxVisible = 5;
    let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
    let endPage = Math.min(totalPages, startPage + maxVisible - 1);
    if (endPage - startPage < maxVisible - 1) startPage = Math.max(1, endPage - maxVisible + 1);
    
    if (startPage > 1) { html += `<button class="page-btn" onclick="loadFilteredRequests('${currentFilterStatus}', 1)">1</button>`; if (startPage > 2) html += `<span class="page-dots">...</span>`; }
    for (let i = startPage; i <= endPage; i++) html += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="loadFilteredRequests('${currentFilterStatus}', ${i})">${i}</button>`;
    if (endPage < totalPages) { if (endPage < totalPages - 1) html += `<span class="page-dots">...</span>`; html += `<button class="page-btn" onclick="loadFilteredRequests('${currentFilterStatus}', ${totalPages})">${totalPages}</button>`; }
    
    if (currentPage < totalPages) html += `<button class="page-btn" onclick="loadFilteredRequests('${currentFilterStatus}', ${currentPage + 1})">Next <i class="fas fa-chevron-right"></i></button>`;
    else html += `<button class="page-btn disabled" disabled>Next <i class="fas fa-chevron-right"></i></button>`;
    html += '</div></div>';
    return html;
}

// Render Request Cards
function renderRequestList() {
    const requestData = currentRequestsData;
    
    const formatDate = (dateString) => {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    };
    
    const getStatusClass = (status) => {
        switch(status) {
            case 'pending': return 'pending';
            case 'approved': return 'approved';
            case 'completed': return 'completed';
            case 'rejected': return 'rejected';
            default: return 'pending';
        }
    };
    
    const getStatusText = (status) => {
        switch(status) {
            case 'pending': return 'Pending';
            case 'approved': return 'Approved';
            case 'completed': return 'Completed';
            case 'rejected': return 'Rejected';
            default: return status;
        }
    };
    
    if (requestData.length === 0) {
        return `
            <div class="content-card">
                <div class="section-title"><i class="fas fa-list-alt"></i> Document Requests</div>
                <div class="section-sub">Manage and process document requests from residents</div>
                <div class="filter-bar">
                    <button class="filter-chip ${currentFilterStatus === 'pending' ? 'active' : ''}" onclick="loadFilteredRequests('pending', 1)">Pending ${initialPendingCount > 0 ? `<span class="pending-count">${initialPendingCount}</span>` : ''}</button>
                    <button class="filter-chip ${currentFilterStatus === 'approved' ? 'active' : ''}" onclick="loadFilteredRequests('approved', 1)">Approved</button>
                    <button class="filter-chip ${currentFilterStatus === 'completed' ? 'active' : ''}" onclick="loadFilteredRequests('completed', 1)">Completed</button>
                    <button class="filter-chip ${currentFilterStatus === 'rejected' ? 'active' : ''}" onclick="loadFilteredRequests('rejected', 1)">Rejected</button>
                </div>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No ${currentFilterStatus} document requests found.</p>
                </div>
            </div>
        `;
    }
    
    const cardsHtml = requestData.map(req => `
        <div class="data-card" onclick="viewRequestDetails(${req.id})">
            <div class="card-header-gradient ${getStatusClass(req.status)}">
                <div class="card-title-large">
                    <i class="fas fa-file-alt"></i>
                    <span>${escapeHtml(req.document_type)}</span>
                </div>
                <div class="status-chip">
                    <i class="fas ${req.status === 'pending' ? 'fa-clock' : req.status === 'approved' ? 'fa-check-circle' : req.status === 'completed' ? 'fa-check-double' : 'fa-times-circle'}"></i>
                    ${getStatusText(req.status)}
                </div>
            </div>
            <div class="card-content">
                <div class="info-section">
                    <div class="info-row-card">
                        <div class="info-icon"><i class="fas fa-user"></i></div>
                        <div class="info-label-card">Resident:</div>
                        <div class="info-value-card"><strong>${escapeHtml(req.first_name)} ${escapeHtml(req.last_name)}</strong></div>
                    </div>
                    <div class="info-row-card">
                        <div class="info-icon"><i class="fas fa-envelope"></i></div>
                        <div class="info-label-card">Email:</div>
                        <div class="info-value-card">${escapeHtml(req.email)}</div>
                    </div>
                    <div class="info-row-card">
                        <div class="info-icon"><i class="fas fa-calendar"></i></div>
                        <div class="info-label-card">Request Date:</div>
                        <div class="info-value-card">${formatDate(req.request_date)}</div>
                    </div>
                    <div class="info-row-card">
                        <div class="info-icon"><i class="fas fa-copy"></i></div>
                        <div class="info-label-card">Quantity:</div>
                        <div class="info-value-card">${req.quantity || 1}</div>
                    </div>
                    <div class="info-row-card">
                        <div class="info-icon"><i class="fas fa-money-bill"></i></div>
                        <div class="info-label-card">Fee:</div>
                        <div class="info-value-card">₱${parseFloat(req.fee || 0).toFixed(2)}</div>
                    </div>
                    
                </div>
            </div>
            <div class="click-hint">
                <i class="fas fa-mouse-pointer"></i> Click to view details and take action
            </div>
        </div>
    `).join('');
    
    return `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-list-alt"></i> Document Requests</div>
            <div class="section-sub">Click on any card to view details and process requests</div>
            <div class="filter-bar">
                <button class="filter-chip ${currentFilterStatus === 'pending' ? 'active' : ''}" onclick="loadFilteredRequests('pending', 1)">Pending ${initialPendingCount > 0 ? `<span class="pending-count">${initialPendingCount}</span>` : ''}</button>
                <button class="filter-chip ${currentFilterStatus === 'approved' ? 'active' : ''}" onclick="loadFilteredRequests('approved', 1)">Approved</button>
                <button class="filter-chip ${currentFilterStatus === 'completed' ? 'active' : ''}" onclick="loadFilteredRequests('completed', 1)">Completed</button>
                <button class="filter-chip ${currentFilterStatus === 'rejected' ? 'active' : ''}" onclick="loadFilteredRequests('rejected', 1)">Rejected</button>
            </div>
            <div class="cards-grid">
                ${cardsHtml}
            </div>
            ${generatePaginationHtml()}
        </div>
    `;
}

// Account Card Rendering
function renderVerifiedAccounts() {
    if (verifiedAccountsData.length === 0) {
        return `<div class="content-card"><div class="section-title"><i class="fas fa-check-circle"></i> Verified Accounts</div><div class="empty-state"><i class="fas fa-users fa-3x"></i><p>No verified accounts yet.</p></div></div>`;
    }
    
    const cardsHtml = verifiedAccountsData.map(acc => `
        <div class="data-card" onclick="showResidentDetails(${acc.id}, '${escapeHtml(acc.name)}')">
            <div class="card-header-gradient verified">
                <div class="card-title-large">
                    <i class="fas fa-user-check"></i>
                    <span>${escapeHtml(acc.name)}</span>
                </div>
                <div class="status-chip"><i class="fas fa-check-circle"></i> Verified</div>
            </div>
            <div class="card-content">
                <div class="info-section">
                    <div class="info-row-card">
                        <div class="info-icon"><i class="fas fa-envelope"></i></div>
                        <div class="info-label-card">Email:</div>
                        <div class="info-value-card">${escapeHtml(acc.email)}</div>
                    </div>
                    <div class="info-row-card">
                        <div class="info-icon"><i class="fas fa-phone"></i></div>
                        <div class="info-label-card">Phone:</div>
                        <div class="info-value-card">${escapeHtml(acc.phone)}</div>
                    </div>
                    <div class="info-row-card">
                        <div class="info-icon"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="info-label-card">Address:</div>
                        <div class="info-value-card">${escapeHtml(acc.address)}</div>
                    </div>
                </div>
            </div>
            <div class="click-hint">
                <i class="fas fa-mouse-pointer"></i> Click to view full details
            </div>
        </div>
    `).join('');
    
    return `<div class="content-card"><div class="section-title"><i class="fas fa-check-circle"></i> Verified Accounts</div><div class="section-sub">Click on any card to view full resident details</div><div class="cards-grid">${cardsHtml}</div></div>`;
}

function renderUnverifiedAccounts() {
    if (unverifiedAccountsData.length === 0) {
        return `<div class="content-card"><div class="section-title"><i class="fas fa-clock"></i> Not Verified Accounts</div><div class="empty-state"><i class="fas fa-check-double fa-3x"></i><p>No pending verifications.</p></div></div>`;
    }
    
    const cardsHtml = unverifiedAccountsData.map(acc => `
        <div class="data-card" onclick="showResidentDetails(${acc.id}, '${escapeHtml(acc.name)}')">
            <div class="card-header-gradient unverified">
                <div class="card-title-large">
                    <i class="fas fa-user-clock"></i>
                    <span>${escapeHtml(acc.name)}</span>
                </div>
                <div class="status-chip"><i class="fas fa-hourglass-half"></i> Pending</div>
            </div>
            <div class="card-content">
                <div class="info-section">
                    <div class="info-row-card">
                        <div class="info-icon"><i class="fas fa-envelope"></i></div>
                        <div class="info-label-card">Email:</div>
                        <div class="info-value-card">${escapeHtml(acc.email)}</div>
                    </div>
                    <div class="info-row-card">
                        <div class="info-icon"><i class="fas fa-phone"></i></div>
                        <div class="info-label-card">Phone:</div>
                        <div class="info-value-card">${escapeHtml(acc.phone)}</div>
                    </div>
                    <div class="info-row-card">
                        <div class="info-icon"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="info-label-card">Address:</div>
                        <div class="info-value-card">${escapeHtml(acc.address)}</div>
                    </div>
                </div>
            </div>
            <div class="click-hint">
                <i class="fas fa-mouse-pointer"></i> Click to verify or view details
            </div>
        </div>
    `).join('');
    
    return `<div class="content-card"><div class="section-title"><i class="fas fa-clock"></i> Not Verified Accounts</div><div class="section-sub">Click on any card to verify the account or view full details</div><div class="cards-grid">${cardsHtml}</div></div>`;
}

function renderManageDocuments() {
    if (documentTypesData.length === 0) {
        return `<div class="content-card"><div class="section-title"><i class="fas fa-cog"></i> Manage Documents</div><div class="section-sub">Configure document types and fees</div><div style="margin-bottom:20px;"><button class="btn-verify" onclick="showAddDocumentModal()"><i class="fas fa-plus"></i> Add New Document</button></div><div class="empty-state"><i class="fas fa-file-alt fa-3x"></i><p>No document types found.</p></div></div>`;
    }
    
    const cardsHtml = documentTypesData.map(doc => `
        <div class="data-card">
            <div class="card-header-gradient ${doc.is_active ? 'verified' : 'unverified'}">
                <div class="card-title-large">
                    <i class="fas fa-file-contract"></i>
                    <span>${escapeHtml(doc.name)}</span>
                </div>
                <div class="status-chip">${doc.is_active ? 'Active' : 'Inactive'}</div>
            </div>
            <div class="card-content">
                <div class="info-section">
                    <div class="info-row-card">
                        <div class="info-icon"><i class="fas fa-align-left"></i></div>
                        <div class="info-label-card">Description:</div>
                        <div class="info-value-card">${escapeHtml(doc.description || '-')}</div>
                    </div>
                    <div class="info-row-card">
                        <div class="info-icon"><i class="fas fa-money-bill"></i></div>
                        <div class="info-label-card">Fee:</div>
                        <div class="info-value-card"><strong>₱${parseFloat(doc.fee).toFixed(2)}</strong></div>
                    </div>
                </div>
            </div>
            <div class="click-hint">
                <i class="fas fa-edit"></i> Click edit/delete below
            </div>
        </div>
    `).join('');
    
    return `<div class="content-card"><div class="section-title"><i class="fas fa-cog"></i> Manage Documents</div><div class="section-sub">Configure document types, fees, and availability</div><div style="margin-bottom:20px;"><button class="btn-verify" onclick="showAddDocumentModal()"><i class="fas fa-plus"></i> Add New Document</button></div><div class="cards-grid">${cardsHtml}</div></div>`;
}

function renderDashboardDefault() {
    return `
    <div class="modern-dashboard">
        <!-- Welcome Section -->
        <div class="welcome-card">
            <div class="welcome-content">
                <div class="welcome-text">
                    <h2>Welcome back, Admin!</h2>
                    <p>Here's what's happening with your barangay today.</p>
                </div>
                <div class="welcome-date">
                    <i class="fas fa-calendar-alt"></i>
                    <span id="currentDate"></span>
                </div>
            </div>
        </div>
        
        <!-- Stats Grid - Main Metrics -->
        <div class="stats-grid-modern">
            <div class="stat-card-modern">
                <div class="stat-icon residents">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3>${dashboardCounts.total}</h3>
                    <p>Total Residents</p>
                    <span class="stat-trend"><i class="fas fa-user-plus"></i> ${dashboardCounts.unverified} pending</span>
                </div>
            </div>
            
            <div class="stat-card-modern">
                <div class="stat-icon verified">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <h3>${dashboardCounts.verified}</h3>
                    <p>Verified Accounts</p>
                    <span class="stat-trend positive"><i class="fas fa-chart-line"></i> ${Math.round((dashboardCounts.verified/dashboardCounts.total)*100)}% verified</span>
                </div>
            </div>
            
            <div class="stat-card-modern">
                <div class="stat-icon documents">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="stat-info">
                    <h3 id="docTotalCount">-</h3>
                    <p>Document Requests</p>
                    <span class="stat-trend" id="docPendingBadge"><i class="fas fa-clock"></i> Loading...</span>
                </div>
            </div>
            
            <div class="stat-card-modern">
                <div class="stat-icon equipment">
                    <i class="fas fa-tools"></i>
                </div>
                <div class="stat-info">
                    <h3 id="equipTotalItems">-</h3>
                    <p>Equipment Items</p>
                    <span class="stat-trend" id="equipAvailableBadge"><i class="fas fa-check-circle"></i> Loading...</span>
                </div>
            </div>
        </div>
        
        <!-- Secondary Stats Row -->
        <div class="stats-row-modern">
            <div class="stat-card-secondary">
                <div class="stat-header">
                    <i class="fas fa-file-signature"></i>
                    <span>Document Status</span>
                </div>
                <div class="stat-values">
                    <div class="value-item">
                        <span class="value-label">Pending</span>
                        <span class="value-number" id="docPendingCount">-</span>
                    </div>
                    <div class="value-item">
                        <span class="value-label">Approved</span>
                        <span class="value-number" id="docApprovedCount">-</span>
                    </div>
                    <div class="value-item">
                        <span class="value-label">Completed</span>
                        <span class="value-number" id="docCompletedCount">-</span>
                    </div>
                    <div class="value-item">
                        <span class="value-label">Rejected</span>
                        <span class="value-number" id="docRejectedCount">-</span>
                    </div>
                </div>
            </div>
            
            <div class="stat-card-secondary">
                <div class="stat-header">
                    <i class="fas fa-tools"></i>
                    <span>Equipment Status</span>
                </div>
                <div class="stat-values">
                    <div class="value-item">
                        <span class="value-label">Available</span>
                        <span class="value-number success" id="equipAvailableCount">-</span>
                    </div>
                    <div class="value-item">
                        <span class="value-label">Borrowed</span>
                        <span class="value-number warning" id="equipBorrowedCount">-</span>
                    </div>
                    <div class="value-item">
                        <span class="value-label">Maintenance</span>
                        <span class="value-number danger" id="equipMaintenanceCount">-</span>
                    </div>
                    <div class="value-item">
                        <span class="value-label">Pending Bookings</span>
                        <span class="value-number info" id="equipPendingBookings">-</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Activity Section -->
        <div class="recent-activity-grid">
            <div class="activity-card">
                <div class="activity-header">
                    <h4><i class="fas fa-file-alt"></i> Recent Document Requests</h4>
                    <button class="view-all-btn" onclick="goToDocumentRequests()">
                        View All <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
                <div class="activity-list" id="recentDocumentsList">
                    <div class="loading-spinner-mini"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                </div>
            </div>
            
            <div class="activity-card">
                <div class="activity-header">
                    <h4><i class="fas fa-calendar-alt"></i> Recent Booking Requests</h4>
                    <button class="view-all-btn" onclick="goToEquipmentBookings()">
                        View All <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
                <div class="activity-list" id="recentBookingsList">
                    <div class="loading-spinner-mini"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <h4><i class="fas fa-bolt"></i> Quick Actions</h4>
            <div class="action-buttons">
                <button class="quick-action-btn" onclick="goToUnverifiedAccounts()">
                    <i class="fas fa-user-check"></i> Verify Residents
                </button>
                <button class="quick-action-btn" onclick="goToDocumentRequests()">
                    <i class="fas fa-file-signature"></i> Process Documents
                </button>
                <button class="quick-action-btn" onclick="goToEquipmentList()">
                    <i class="fas fa-plus-circle"></i> Add Equipment
                </button>
                <button class="quick-action-btn" onclick="goToEquipmentBookings()">
                    <i class="fas fa-calendar-check"></i> Manage Bookings
                </button>
            </div>
        </div>
    </div>`;
}

// Navigation helper functions
function goToUnverifiedAccounts() {
    const accountsParent = document.getElementById('accountsParent');
    if (accountsParent) {
        // Expand accounts submenu if collapsed
        const submenu = document.getElementById('accountsSubmenu');
        if (submenu && !submenu.classList.contains('open')) {
            const toggleIcon = accountsParent.querySelector('.toggle-icon');
            submenu.classList.add('open');
            if (toggleIcon) toggleIcon.style.transform = 'rotate(180deg)';
        }
        // Click on unverified option
        const unverifiedOption = document.querySelector('#accountsSubmenu .sub-option[data-subview="unverified"]');
        if (unverifiedOption) {
            unverifiedOption.click();
        }
    }
}

function goToDocumentRequests() {
    const documentsParent = document.getElementById('documentsParent');
    if (documentsParent) {
        const submenu = document.getElementById('documentsSubmenu');
        if (submenu && !submenu.classList.contains('open')) {
            const toggleIcon = documentsParent.querySelector('.toggle-icon');
            submenu.classList.add('open');
            if (toggleIcon) toggleIcon.style.transform = 'rotate(180deg)';
        }
        const requestListOption = document.querySelector('#documentsSubmenu .sub-option[data-subview="request_list"]');
        if (requestListOption) {
            requestListOption.click();
        }
    }
}

function goToEquipmentList() {
    const equipmentParent = document.getElementById('equipmentParent');
    if (equipmentParent) {
        const submenu = document.getElementById('equipmentSubmenu');
        if (submenu && !submenu.classList.contains('open')) {
            const toggleIcon = equipmentParent.querySelector('.toggle-icon');
            submenu.classList.add('open');
            if (toggleIcon) toggleIcon.style.transform = 'rotate(180deg)';
        }
        const equipmentListOption = document.querySelector('#equipmentSubmenu .sub-option[data-subview="equipment_list"]');
        if (equipmentListOption) {
            equipmentListOption.click();
        }
    }
}

function goToEquipmentBookings() {
    const equipmentParent = document.getElementById('equipmentParent');
    if (equipmentParent) {
        const submenu = document.getElementById('equipmentSubmenu');
        if (submenu && !submenu.classList.contains('open')) {
            const toggleIcon = equipmentParent.querySelector('.toggle-icon');
            submenu.classList.add('open');
            if (toggleIcon) toggleIcon.style.transform = 'rotate(180deg)';
        }
        const bookingsOption = document.querySelector('#equipmentSubmenu .sub-option[data-subview="equipment_bookings"]');
        if (bookingsOption) {
            bookingsOption.click();
        }
    }
}

/// Load document statistics for dashboard
async function loadDocumentStats() {
    try {
        const response = await fetch(`${window.location.href}?dashboard_action=get_document_stats`);
        const data = await response.json();
        if (data.success && data.document) {
            document.getElementById('docTotalCount').innerText = data.document.total || 0;
            document.getElementById('docPendingCount').innerText = data.document.pending || 0;
            document.getElementById('docApprovedCount').innerText = data.document.approved || 0;
            document.getElementById('docCompletedCount').innerText = data.document.completed || 0;
            document.getElementById('docRejectedCount').innerText = data.document.rejected || 0;
            const pendingBadge = document.getElementById('docPendingBadge');
            if (pendingBadge) {
                pendingBadge.innerHTML = `<i class="fas fa-clock"></i> ${data.document.pending || 0} pending requests`;
            }
        }
    } catch (error) {
        console.error('Error loading document stats:', error);
    }
}

// Load equipment stats for dashboard
async function loadDashboardEquipmentStats() {
    try {
        const response = await fetch('equipment_ajax.php?action=get_counts');
        const data = await response.json();
        if (data.success) {
            document.getElementById('equipTotalItems').innerText = data.counts.total || 0;
            document.getElementById('equipAvailableCount').innerText = data.counts.available || 0;
            document.getElementById('equipBorrowedCount').innerText = data.counts.borrowed || 0;
            document.getElementById('equipMaintenanceCount').innerText = data.counts.maintenance || 0;
            document.getElementById('equipPendingBookings').innerText = data.pending_bookings || 0;
            const availBadge = document.getElementById('equipAvailableBadge');
            if (availBadge) {
                availBadge.innerHTML = `<i class="fas fa-check-circle"></i> ${data.counts.available || 0} available`;
            }
        }
    } catch (error) {
        console.error('Error loading equipment stats:', error);
    }
}

// Load recent document requests
async function loadRecentDocuments() {
    try {
        const response = await fetch(`${window.location.href}?dashboard_action=get_recent_documents&limit=5`);
        const data = await response.json();
        const container = document.getElementById('recentDocumentsList');
        if (container) {
            if (data.success && data.requests && data.requests.length > 0) {
                const formatDate = (dateString) => {
                    const date = new Date(dateString);
                    const now = new Date();
                    const diff = Math.floor((now - date) / (1000 * 60 * 60 * 24));
                    if (diff === 0) return 'Today';
                    if (diff === 1) return 'Yesterday';
                    return `${diff} days ago`;
                };
                container.innerHTML = data.requests.map(req => `
                    <div class="activity-item" onclick="viewRequestDetails(${req.id})">
                        <div class="activity-info">
                            <div class="activity-title">${escapeHtml(req.document_type)}</div>
                            <div class="activity-detail">
                                <i class="fas fa-user"></i> ${escapeHtml(req.first_name)} ${escapeHtml(req.last_name)}
                                <i class="fas fa-calendar" style="margin-left: 10px;"></i> ${formatDate(req.request_date)}
                            </div>
                        </div>
                        <div class="activity-status status-${req.status}">${req.status}</div>
                    </div>
                `).join('');
            } else {
                container.innerHTML = '<div class="empty-state-mini"><p>No recent document requests</p></div>';
            }
        }
    } catch (error) {
        console.error('Error loading recent documents:', error);
        const container = document.getElementById('recentDocumentsList');
        if (container) container.innerHTML = '<div class="empty-state-mini"><p>Unable to load documents</p></div>';
    }
}

// Load recent bookings
async function loadRecentBookings() {
    try {
        const response = await fetch('equipment_ajax.php?action=get_recent_bookings&limit=5');
        const data = await response.json();
        const container = document.getElementById('recentBookingsList');
        if (container) {
            if (data.success && data.bookings && data.bookings.length > 0) {
                const formatDate = (dateString) => {
                    const date = new Date(dateString);
                    const now = new Date();
                    const diff = Math.floor((now - date) / (1000 * 60 * 60 * 24));
                    if (diff === 0) return 'Today';
                    if (diff === 1) return 'Yesterday';
                    return `${diff} days ago`;
                };
                container.innerHTML = data.bookings.map(booking => `
                    <div class="activity-item" onclick="viewBookingDetails(${booking.id})">
                        <div class="activity-info">
                            <div class="activity-title">${escapeHtml(booking.equipment_name || booking.item_name)}</div>
                            <div class="activity-detail">
                                <i class="fas fa-user"></i> ${escapeHtml(booking.first_name)} ${escapeHtml(booking.last_name)}
                                <i class="fas fa-hourglass-half" style="margin-left: 10px;"></i> ${booking.duration_days || 1} day(s)
                            </div>
                        </div>
                        <div class="activity-status status-${booking.status}">${booking.status}</div>
                    </div>
                `).join('');
            } else {
                container.innerHTML = '<div class="empty-state-mini"><p>No recent booking requests</p></div>';
            }
        }
    } catch (error) {
        console.error('Error loading recent bookings:', error);
        const container = document.getElementById('recentBookingsList');
        if (container) container.innerHTML = '<div class="empty-state-mini"><p>Unable to load bookings</p></div>';
    }
}

// Display current date
function displayCurrentDate() {
    const dateElement = document.getElementById('currentDate');
    if (dateElement) {
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        dateElement.innerHTML = new Date().toLocaleDateString('en-US', options);
    }
}

function renderReports() {
    return `<div class="content-card"><div class="section-title"><i class="fas fa-chart-line"></i> Reports & Analytics</div><div class="section-sub">Generate barangay reports</div><p style="padding:20px 0;">📊 Statistical reports will be available here.</p></div>`;
}

function renderSettings() {
    return `<div class="content-card"><div class="section-title"><i class="fas fa-cog"></i> System Settings</div><div class="section-sub">Preferences and user roles</div><p style="padding:20px 0;">⚙️ Configure barangay system parameters.</p></div>`;
}

// Show approval modal with notes
function showApprovalModal(requestId) {
    pendingApprovalRequestId = requestId;
    document.getElementById('approvalModal').style.display = 'block';
    document.getElementById('approvalNotes').value = '';
    document.getElementById('approvalStatus').style.display = 'none';
}

function closeApprovalModal() {
    document.getElementById('approvalModal').style.display = 'none';
    pendingApprovalRequestId = null;
}

async function submitApproval() {
    const notes = document.getElementById('approvalNotes').value;
    const statusDiv = document.getElementById('approvalStatus');
    const confirmBtn = document.getElementById('confirmApprovalBtn');
    const requestId = pendingApprovalRequestId;
    
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    statusDiv.style.display = 'block';
    statusDiv.className = 'email-status';
    statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating document...';
    
    const formData = new FormData();
    formData.append('doc_action', 'update_request_status');
    formData.append('request_id', requestId);
    formData.append('status', 'approved');
    formData.append('admin_notes', notes);
    
    try {
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            statusDiv.className = 'email-status success';
            statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
            
            setTimeout(() => {
                closeApprovalModal();
                showSuccessModal(data.message, 'Approved');
                loadFilteredRequests(currentFilterStatus, currentPage);
                
                if (requestId) {
                    setTimeout(() => {
                        window.location.href = `certification.php?request_id=${requestId}`;
                    }, 1500);
                }
            }, 1500);
        } else {
            statusDiv.className = 'email-status error';
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.message || 'Failed to approve request');
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Approval';
        }
    } catch (error) {
        statusDiv.className = 'email-status error';
        statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> An error occurred';
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Approval';
    }
}

// Request action functions
function showRejectPrompt(requestId) {
    showPromptModal('Reject Request', 'Please enter a reason for rejection...', (reason) => { rejectRequest(requestId, reason); });
}

async function approveRequest(requestId) {
    showApprovalModal(requestId);
}

async function rejectRequest(requestId, reason) {
    const formData = new FormData();
    formData.append('doc_action', 'update_request_status');
    formData.append('request_id', requestId);
    formData.append('status', 'rejected');
    formData.append('admin_notes', reason);
    try {
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await response.json();
        if (data.success) {
            showSuccessModal('Request rejected successfully!', 'Rejected');
            closeRequestModal();
            await loadFilteredRequests(currentFilterStatus, currentPage);
        } else showErrorModal(data.message || 'Failed to reject request', 'Rejection Failed');
    } catch (error) { showErrorModal('An error occurred.', 'Error'); }
}

async function markAsCompleted(requestId) {
    showConfirmationModal('Mark as completed? The resident can now claim the document.', 'Confirm Completion', async () => {
        const formData = new FormData();
        formData.append('doc_action', 'update_request_status');
        formData.append('request_id', requestId);
        formData.append('status', 'completed');
        try {
            const response = await fetch(window.location.href, { method: 'POST', body: formData });
            const data = await response.json();
            if (data.success) {
                showSuccessModal('Request marked as completed!', 'Completed');
                closeRequestModal();
                await loadFilteredRequests(currentFilterStatus, currentPage);
            } else showErrorModal(data.message || 'Failed to update request', 'Update Failed');
        } catch (error) { showErrorModal('An error occurred.', 'Error'); }
    });
}

async function viewRequestDetails(requestId) {
    const modal = document.getElementById('requestModal');
    const modalBody = document.getElementById('requestModalBody');
    modal.style.display = 'block';
    modalBody.innerHTML = `<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading request details...</p></div>`;
    try {
        const formData = new FormData();
        formData.append('doc_action', 'get_request_details');
        formData.append('request_id', requestId);
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await response.json();
        if (data.success) {
            const req = data.request;
            const formatDate = (dateString) => {
                if (!dateString) return '-';
                const date = new Date(dateString);
                return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
            };
            const getStatusBadge = (status) => {
                switch(status) {
                    case 'pending': return '<span class="badge-unverified"><i class="fas fa-clock"></i> Pending</span>';
                    case 'approved': return '<span class="badge-verified"><i class="fas fa-check-circle"></i> Approved</span>';
                    case 'completed': return '<span class="status-verified" style="background:#d4edda;color:#155724;padding:4px 12px;border-radius:40px;"><i class="fas fa-check-double"></i> Completed</span>';
                    case 'rejected': return '<span class="status-unverified" style="background:#f8d7da;color:#721c24;padding:4px 12px;border-radius:40px;"><i class="fas fa-times-circle"></i> Rejected</span>';
                    default: return '<span class="badge-unverified">' + status + '</span>';
                }
            };
            
            let actionButtonsHtml = '';
            if (req.status === 'pending') {
                actionButtonsHtml = `
                    <div class="modal-action-buttons">
                        <button class="btn-verify" onclick="approveRequest(${req.id}); closeRequestModal();"><i class="fas fa-check-circle"></i> Approve Request</button>
                        <button class="btn-close" onclick="showRejectFromModal(${req.id})" style="background: #dc3545;"><i class="fas fa-times-circle"></i> Reject Request</button>
                    </div>
                `;
            } else if (req.status === 'approved' || req.status === 'completed') {
                actionButtonsHtml = `
                    <div class="modal-action-buttons">
                        <button class="btn-verify" onclick="openCertificationPage(${req.id})" style="background: #17a2b8;"><i class="fas fa-certificate"></i> View Certification</button>
                       
                        <button class="btn-verify" onclick="printRequestDocument(${req.id})" style="background: #28a745;"><i class="fas fa-print"></i> Print</button>
                        <button class="btn-verify" onclick="downloadRequestDocument(${req.id})" style="background: #6c757d;"><i class="fas fa-download"></i> Download</button>
                        ${req.status === 'approved' ? `<button class="btn-verify" onclick="markAsCompleted(${req.id}); closeRequestModal();" style="background: #17a2b8;"><i class="fas fa-check-double"></i> Mark as Completed</button>` : ''}
                    </div>
                `;
            }
            
            modalBody.innerHTML = `
                <div class="detail-section"><h4>Resident Information</h4>
                    <div class="detail-row"><div class="detail-label">Full Name:</div><div class="detail-value"><strong>${escapeHtml(req.first_name)} ${escapeHtml(req.last_name)}</strong></div></div>
                    <div class="detail-row"><div class="detail-label">Email:</div><div class="detail-value">${escapeHtml(req.email)}</div></div>
                    <div class="detail-row"><div class="detail-label">Phone:</div><div class="detail-value">${escapeHtml(req.phone || '-')}</div></div>
                    <div class="detail-row"><div class="detail-label">Address:</div><div class="detail-value">${escapeHtml(req.address || '-')}</div></div>
                </div>
                <div class="detail-section"><h4>Request Information</h4>
                    <div class="detail-row"><div class="detail-label">Document Type:</div><div class="detail-value">${escapeHtml(req.document_type)}</div></div>
                    <div class="detail-row"><div class="detail-label">Purpose:</div><div class="detail-value">${escapeHtml(req.purpose || '-')}</div></div>
                    <div class="detail-row"><div class="detail-label">Quantity:</div><div class="detail-value">${req.quantity || 1}</div></div>
                    <div class="detail-row"><div class="detail-label">Fee:</div><div class="detail-value">₱${parseFloat(req.fee || 0).toFixed(2)}</div></div>
                    <div class="detail-row"><div class="detail-label">Status:</div><div class="detail-value">${getStatusBadge(req.status)}</div></div>
                    <div class="detail-row"><div class="detail-label">Request Date:</div><div class="detail-value">${formatDate(req.request_date)}</div></div>
                    ${req.processed_date ? `<div class="detail-row"><div class="detail-label">Processed Date:</div><div class="detail-value">${formatDate(req.processed_date)}</div></div>` : ''}
                    ${req.admin_notes ? `<div class="detail-row"><div class="detail-label">Admin Notes:</div><div class="detail-value"><strong>${escapeHtml(req.admin_notes)}</strong></div></div>` : ''}
                   
                    ${req.id_document_path ? `<div class="detail-row"><div class="detail-label">ID Document:</div><div class="detail-value"><button class="view-doc-btn" onclick="openDocument('${req.id_document_path}')"><i class="fas fa-id-card"></i> View ID Document</button></div></div>` : ''}
                </div>
                ${actionButtonsHtml}
            `;
        } else modalBody.innerHTML = `<div class="empty-state-mini"><p>${data.message}</p></div>`;
    } catch (error) { modalBody.innerHTML = `<div class="empty-state-mini"><p>An error occurred.</p></div>`; }
}


function openCertificationPage(requestId) {
    window.location.href = `certification.php?request_id=${requestId}`;
}

function printRequestDocument(requestId) {
    window.open(`print_document.php?request_id=${requestId}`, '_blank');
}

function downloadRequestDocument(requestId) {
    window.location.href = `download_document.php?request_id=${requestId}`;
}

function showRejectFromModal(requestId) {
    const modalBody = document.getElementById('requestModalBody');
    modalBody.innerHTML = `
        <div class="detail-section"><h4>Reject Request</h4><p>Please provide a reason for rejection:</p>
        <textarea id="rejectReason" class="form-control" rows="4" placeholder="Enter reason for rejection..."></textarea>
        <div class="modal-action-buttons">
            <button class="btn-verify" onclick="submitRejectionFromModal(${requestId})" style="background: #dc3545;"><i class="fas fa-times-circle"></i> Confirm Rejection</button>
            <button class="btn-close" onclick="viewRequestDetails(${requestId})">Back</button>
        </div></div>
    `;
}

async function submitRejectionFromModal(requestId) {
    const reason = document.getElementById('rejectReason').value;
    if (!reason.trim()) { showWarningModal('Please enter a reason for rejection.', 'Input Required'); return; }
    await rejectRequest(requestId, reason);
    closeRequestModal();
}

function closeRequestModal() { const modal = document.getElementById('requestModal'); if (modal) modal.style.display = 'none'; }

// Verification functions
function showVerifyModal(id, name, email) {
    pendingVerifyId = id;
    pendingVerifyName = name;
    pendingVerifyEmail = email;
    const modal = document.getElementById('verifyModal');
    const modalBody = document.getElementById('verifyModalBody');
    modalBody.innerHTML = `
        <div style="text-align: center;">
            <div class="verify-warning-icon"><i class="fas fa-shield-alt"></i></div>
            <h3 style="color: #1a472a; margin-bottom: 15px;">Confirm Account Verification</h3>
            <p>You are about to verify the account of:</p>
            <div class="resident-name-highlight">${escapeHtml(name)}</div>
            <div class="email-info"><i class="fas fa-envelope"></i> ${escapeHtml(email)}<br><small>A confirmation email will be sent</small></div>
            <div class="verify-buttons">
                <button class="btn-confirm" onclick="confirmVerification()"><i class="fas fa-check-circle"></i> Yes, Verify</button>
                <button class="btn-cancel" onclick="closeVerifyModal()"><i class="fas fa-times"></i> Cancel</button>
            </div>
            <div id="verifyEmailStatus" class="email-status"></div>
        </div>
    `;
    modal.style.display = 'block';
}

function confirmVerification() {
    if (!pendingVerifyId) return;
    const confirmBtn = document.querySelector('#verifyModal .btn-confirm');
    const originalText = confirmBtn.innerHTML;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    confirmBtn.disabled = true;
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=verify_account&id=${pendingVerifyId}`
    })
    .then(response => response.json())
    .then(data => {
        const statusDiv = document.getElementById('verifyEmailStatus');
        if (data.success) {
            statusDiv.className = 'email-status success';
            statusDiv.innerHTML = data.email_sent ? `<i class="fas fa-check-circle"></i> ${data.message}<br><i class="fas fa-envelope"></i> ${data.email_status}` : `<i class="fas fa-check-circle"></i> ${data.message}<br><i class="fas fa-exclamation-triangle"></i> ${data.email_status}`;
            setTimeout(() => { closeVerifyModal(); showSuccessModal(data.message, 'Verification Complete'); window.location.reload(); }, 2000);
        } else {
            statusDiv.className = 'email-status error';
            statusDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${data.message}`;
            confirmBtn.innerHTML = originalText;
            confirmBtn.disabled = false;
        }
    })
    .catch(error => { console.error('Error:', error); document.getElementById('verifyEmailStatus').innerHTML = '<i class="fas fa-exclamation-circle"></i> An error occurred.'; confirmBtn.innerHTML = originalText; confirmBtn.disabled = false; });
}

function closeVerifyModal() { document.getElementById('verifyModal').style.display = 'none'; pendingVerifyId = null; }

// Resident details
async function showResidentDetails(id, name) {
    const modal = document.getElementById('residentModal');
    const modalBody = document.getElementById('modalBody');
    const modalFooter = document.getElementById('residentModalFooter');
    modal.style.display = 'block';
    modalBody.innerHTML = `<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading details for ${escapeHtml(name)}...</p></div>`;
    try {
        const response = await fetch(`${window.location.href}?action=get_resident&id=${id}`);
        const data = await response.json();
        if (data.success) {
            const resident = data.data;
            const isVerified = resident.is_verified == 1;
            const formatDate = (dateString) => { if (!dateString) return 'Not provided'; const date = new Date(dateString); return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }); };
            modalBody.innerHTML = `
                <div class="detail-row"><div class="detail-label">Full Name:</div><div class="detail-value"><strong>${escapeHtml(resident.first_name)} ${escapeHtml(resident.last_name)}</strong></div></div>
                <div class="detail-row"><div class="detail-label">Username:</div><div class="detail-value">${escapeHtml(resident.username || 'Not set')}</div></div>
                <div class="detail-row"><div class="detail-label">Email:</div><div class="detail-value">${escapeHtml(resident.email)}</div></div>
                <div class="detail-row"><div class="detail-label">Phone:</div><div class="detail-value">${escapeHtml(resident.phone || 'Not provided')}</div></div>
                <div class="detail-row"><div class="detail-label">Address:</div><div class="detail-value">${escapeHtml(resident.address || 'Not specified')}</div></div>
                <div class="detail-row"><div class="detail-label">Date of Birth:</div><div class="detail-value">${resident.date_of_birth ? formatDate(resident.date_of_birth) : 'Not provided'}</div></div>
                <div class="detail-row"><div class="detail-label">Gender:</div><div class="detail-value">${escapeHtml(resident.gender || 'Not specified')}</div></div>
                <div class="detail-row"><div class="detail-label">Status:</div><div class="detail-value"><span class="status-badge ${isVerified ? 'status-verified' : 'status-unverified'}">${isVerified ? '<i class="fas fa-check-circle"></i> Verified' : '<i class="fas fa-clock"></i> Not Verified'}</span></div></div>
                ${resident.scanned_document ? `<div class="detail-row"><div class="detail-label">Document:</div><div class="detail-value"><button class="view-doc-btn" onclick="openDocument('${escapeHtml(resident.scanned_document)}')"><i class="fas fa-file-pdf"></i> View Document</button></div></div>` : ''}
                <div class="detail-row"><div class="detail-label">Registered:</div><div class="detail-value">${formatDate(resident.created_at)}</div></div>
                <div class="detail-row"><div class="detail-label">Last Updated:</div><div class="detail-value">${formatDate(resident.updated_at)}</div></div>
                ${resident.last_login ? `<div class="detail-row"><div class="detail-label">Last Login:</div><div class="detail-value">${formatDate(resident.last_login)}</div></div>` : ''}
            `;
            
            if (!isVerified) {
                modalFooter.innerHTML = `
                    <button class="btn-verify" onclick="showVerifyModal(${resident.id}, '${escapeHtml(resident.first_name)} ${escapeHtml(resident.last_name)}', '${escapeHtml(resident.email)}')"><i class="fas fa-user-check"></i> Verify Account</button>
                    <button class="btn-close" onclick="closeModal()">Close</button>
                `;
            } else {
                modalFooter.innerHTML = `<button class="btn-close" onclick="closeModal()">Close</button>`;
            }
        } else modalBody.innerHTML = `<div class="empty-state-mini"><p>${data.message || 'Failed to load'}</p></div>`;
    } catch (error) { modalBody.innerHTML = `<div class="empty-state-mini"><p>An error occurred.</p></div>`; }
}

function closeModal() { document.getElementById('residentModal').style.display = 'none'; }

// Document management functions
function showAddDocumentModal() {
    const modal = document.getElementById('documentModal');
    document.getElementById('documentModalTitle').innerHTML = '<i class="fas fa-plus"></i> Add New Document';
    document.getElementById('documentModalBody').innerHTML = `<form id="documentForm" onsubmit="saveDocument(event)"><div class="form-group"><label>Document Name <span style="color:red;">*</span></label><input type="text" id="docName" class="form-control" required placeholder="e.g., Barangay Clearance"></div><div class="form-group"><label>Description</label><textarea id="docDescription" class="form-control" rows="3"></textarea></div><div class="form-group"><label>Fee (₱) <span style="color:red;">*</span></label><input type="number" id="docFee" class="form-control" step="0.01" min="0" required value="0"></div><div class="form-group"><label>Status</label><select id="docStatus" class="form-control"><option value="1">Active</option><option value="0">Inactive</option></select></div><div class="form-buttons"><button type="submit" class="btn-verify"><i class="fas fa-save"></i> Save</button><button type="button" class="btn-close" onclick="closeDocumentModal()">Cancel</button></div></form>`;
    modal.style.display = 'block';
}

async function editDocument(id) {
    const modal = document.getElementById('documentModal');
    document.getElementById('documentModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Document';
    document.getElementById('documentModalBody').innerHTML = `<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div>`;
    modal.style.display = 'block';
    try {
        const formData = new FormData();
        formData.append('doc_action', 'get_document');
        formData.append('id', id);
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await response.json();
        if (data.success) {
            const doc = data.data;
            document.getElementById('documentModalBody').innerHTML = `<form id="documentForm" onsubmit="updateDocument(event, ${doc.id})"><div class="form-group"><label>Document Name</label><input type="text" id="docName" class="form-control" required value="${escapeHtml(doc.name)}"></div><div class="form-group"><label>Description</label><textarea id="docDescription" class="form-control" rows="3">${escapeHtml(doc.description || '')}</textarea></div><div class="form-group"><label>Fee (₱)</label><input type="number" id="docFee" class="form-control" step="0.01" min="0" required value="${doc.fee}"></div><div class="form-group"><label>Status</label><select id="docStatus" class="form-control"><option value="1" ${doc.is_active == 1 ? 'selected' : ''}>Active</option><option value="0" ${doc.is_active == 0 ? 'selected' : ''}>Inactive</option></select></div><div class="form-buttons"><button type="submit" class="btn-verify"><i class="fas fa-save"></i> Update</button><button type="button" class="btn-close" onclick="closeDocumentModal()">Cancel</button></div></form>`;
        }
    } catch (error) { document.getElementById('documentModalBody').innerHTML = `<div class="empty-state-mini"><p>Error loading document.</p></div>`; }
}

async function saveDocument(event) {
    event.preventDefault();
    const formData = new FormData();
    formData.append('doc_action', 'add_document');
    formData.append('name', document.getElementById('docName').value);
    formData.append('description', document.getElementById('docDescription').value);
    formData.append('fee', document.getElementById('docFee').value);
    formData.append('is_active', document.getElementById('docStatus').value);
    const response = await fetch(window.location.href, { method: 'POST', body: formData });
    const data = await response.json();
    if (data.success) { showSuccessModal('Document added successfully!', 'Success'); closeDocumentModal(); window.location.reload(); }
    else showErrorModal(data.message || 'Failed to add document', 'Error');
}

async function updateDocument(event, id) {
    event.preventDefault();
    const formData = new FormData();
    formData.append('doc_action', 'update_document');
    formData.append('id', id);
    formData.append('name', document.getElementById('docName').value);
    formData.append('description', document.getElementById('docDescription').value);
    formData.append('fee', document.getElementById('docFee').value);
    formData.append('is_active', document.getElementById('docStatus').value);
    const response = await fetch(window.location.href, { method: 'POST', body: formData });
    const data = await response.json();
    if (data.success) { showSuccessModal('Document updated successfully!', 'Success'); closeDocumentModal(); window.location.reload(); }
    else showErrorModal(data.message || 'Failed to update document', 'Error');
}

function deleteDocument(id, name) {
    showConfirmationModal(`Delete "${name}"? This action cannot be undone.`, 'Confirm Delete', async () => {
        const formData = new FormData();
        formData.append('doc_action', 'delete_document');
        formData.append('id', id);
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await response.json();
        if (data.success) { showSuccessModal('Document deleted!', 'Deleted'); window.location.reload(); }
        else showErrorModal(data.message || 'Failed to delete document', 'Error');
    });
}

function closeDocumentModal() { document.getElementById('documentModal').style.display = 'none'; }

// Mobile menu
const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
function toggleSidebar() { sidebar.classList.toggle('open'); overlay.classList.toggle('hide'); }
function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.add('hide'); }
menuToggle.addEventListener('click', toggleSidebar);
overlay.addEventListener('click', closeSidebar);

// Profile Dropdown
const profileArea = document.getElementById('profileArea');
const profileDropdown = document.getElementById('profileDropdown');
const logoutBtn = document.getElementById('logoutBtn');
const settingsBtn = document.getElementById('settingsBtn');
function toggleDropdown() { profileDropdown.classList.toggle('show'); }
function closeDropdown() { profileDropdown.classList.remove('show'); }
profileArea.addEventListener('click', (e) => { e.stopPropagation(); toggleDropdown(); });
document.addEventListener('click', (e) => { if (!profileArea.contains(e.target)) closeDropdown(); });
logoutBtn.addEventListener('click', (e) => { e.preventDefault(); showConfirmationModal('Are you sure you want to logout?', 'Confirm Logout', () => { document.getElementById('logoutForm').submit(); }); });
settingsBtn.addEventListener('click', () => { closeDropdown(); document.querySelectorAll('.nav-item').forEach(nav => nav.classList.remove('active-parent', 'active')); document.querySelectorAll('.sub-option').forEach(sub => sub.classList.remove('active-sub')); document.querySelector('.nav-item.standalone[data-view="settings"]').classList.add('active'); document.getElementById('dashboardBody').innerHTML = renderSettings(); });
document.querySelectorAll('.nav-item, .sub-option').forEach(item => { item.addEventListener('click', () => { if (window.innerWidth <= 768) closeSidebar(); }); });

// Accounts Sidebar
const accountsParent = document.getElementById('accountsParent');
const accountsSubmenu = document.getElementById('accountsSubmenu');
let accountsExpanded = false;
function toggleAccountsSubmenu(expand) {
    if (expand === undefined) accountsExpanded = !accountsExpanded;
    else accountsExpanded = expand;
    if (accountsExpanded) { accountsSubmenu.classList.add('open'); accountsParent.querySelector('.toggle-icon').style.transform = 'rotate(180deg)'; }
    else { accountsSubmenu.classList.remove('open'); accountsParent.querySelector('.toggle-icon').style.transform = 'rotate(0deg)'; }
}
toggleAccountsSubmenu(false);
accountsParent.addEventListener('click', (e) => { e.stopPropagation(); toggleAccountsSubmenu(); });
const accountSubOptions = document.querySelectorAll('#accountsSubmenu .sub-option');
accountSubOptions.forEach(opt => {
    opt.addEventListener('click', (e) => {
        e.preventDefault();
        const view = opt.getAttribute('data-subview');
        accountSubOptions.forEach(sub => sub.classList.remove('active-sub'));
        opt.classList.add('active-sub');
        document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active-parent', 'active'));
        accountsParent.classList.add('active-parent');
        if (view === 'verified') {
            document.getElementById('dashboardBody').innerHTML = renderVerifiedAccounts();
            closeCertification();
        } else if (view === 'unverified') {
            document.getElementById('dashboardBody').innerHTML = renderUnverifiedAccounts();
            closeCertification();
        }
        if (!accountsExpanded) toggleAccountsSubmenu(true);
        closeDropdown();
    });
});

// Documents Sidebar
const documentsParent = document.getElementById('documentsParent');
const documentsSubmenu = document.getElementById('documentsSubmenu');
let documentsExpanded = false;

function toggleDocumentsSubmenu(expand) {
    if (expand === undefined) documentsExpanded = !documentsExpanded;
    else documentsExpanded = expand;
    if (documentsExpanded) { documentsSubmenu.classList.add('open'); documentsParent.querySelector('.toggle-icon').style.transform = 'rotate(180deg)'; }
    else { documentsSubmenu.classList.remove('open'); documentsParent.querySelector('.toggle-icon').style.transform = 'rotate(0deg)'; }
}
toggleDocumentsSubmenu(false);
documentsParent.addEventListener('click', (e) => { e.stopPropagation(); toggleDocumentsSubmenu(); });

const documentSubOptions = document.querySelectorAll('#documentsSubmenu .sub-option');
documentSubOptions.forEach(opt => {
    opt.addEventListener('click', async (e) => {
        e.preventDefault();
        const view = opt.getAttribute('data-subview');
        documentSubOptions.forEach(sub => sub.classList.remove('active-sub'));
        opt.classList.add('active-sub');
        document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active-parent', 'active'));
        documentsParent.classList.add('active-parent');
        
        if (view === 'manage_documents') {
            document.getElementById('dashboardBody').innerHTML = renderManageDocuments();
            const certSection = document.getElementById('certificationSection');
            if (certSection) certSection.classList.remove('visible');
        } else if (view === 'request_list') { 
            currentFilterStatus = 'pending'; 
            currentPage = 1; 
            await loadFilteredRequests('pending', 1);
            const certSection = document.getElementById('certificationSection');
            if (certSection) certSection.classList.remove('visible');
        } else if (view === 'certification') {
            window.location.href = 'certification.php';
            return;
        }
        
        if (!documentsExpanded) toggleDocumentsSubmenu(true);
        closeDropdown();
    });
});

// Standalone menu
const standaloneItems = document.querySelectorAll('.nav-item.standalone');
standaloneItems.forEach(item => {
    item.addEventListener('click', (e) => {
        const view = item.getAttribute('data-view');
        document.querySelectorAll('.nav-item').forEach(nav => nav.classList.remove('active-parent', 'active'));
        item.classList.add('active');
        document.querySelectorAll('.sub-option').forEach(sub => sub.classList.remove('active-sub'));
        if (view === 'dashboard') {
            document.getElementById('dashboardBody').innerHTML = renderDashboardDefault();
            closeCertification();
        } else if (view === 'reports') {
            document.getElementById('dashboardBody').innerHTML = renderReports();
            closeCertification();
        } else if (view === 'settings') {
            document.getElementById('dashboardBody').innerHTML = renderSettings();
            closeCertification();
        }
        closeDropdown();
    });
});

// Load default dashboard with all stats
document.querySelector('.nav-item.standalone[data-view="dashboard"]').classList.add('active');
document.getElementById('dashboardBody').innerHTML = renderDashboardDefault();
displayCurrentDate();
loadDocumentStats();
loadDashboardEquipmentStats();
loadRecentDocuments();
loadRecentBookings();

// Prevent back button after logout
(function() { history.pushState(null, null, location.href); window.onpopstate = function() { history.go(1); }; })();
document.addEventListener('keydown', function(e) {
    if (e.altKey && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) e.preventDefault();
    if (e.key === 'Backspace') { const target = e.target; if (target.tagName !== 'INPUT' && target.tagName !== 'TEXTAREA' && !target.isContentEditable) e.preventDefault(); }
});

// Close modals on outside click
window.onclick = function(event) {
    if (event.target === document.getElementById('residentModal')) closeModal();
    if (event.target === document.getElementById('verifyModal')) closeVerifyModal();
    if (event.target === document.getElementById('documentModal')) closeDocumentModal();
    if (event.target === document.getElementById('requestModal')) closeRequestModal();
    if (event.target === document.getElementById('approvalModal')) closeApprovalModal();
};
</script>
// Make sure helper functions are available globally
if (typeof escapeHtml === 'undefined') {
    window.escapeHtml = function(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    };
}

if (typeof showSuccessModal === 'undefined') {
    window.showSuccessModal = function(message, title) {
        alert(title + '\n' + message);
    };
}

if (typeof showErrorModal === 'undefined') {
    window.showErrorModal = function(message, title) {
        alert(title + '\n' + message);
    };
}

if (typeof showWarningModal === 'undefined') {
    window.showWarningModal = function(message, title) {
        alert(title + '\n' + message);
    };
}

if (typeof showConfirmationModal === 'undefined') {
    window.showConfirmationModal = function(message, title, onConfirm) {
        if (confirm(title + '\n' + message)) {
            onConfirm();
        }
    };
}


<script>
   
</script>
</body>
</html>