<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'resident') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$resident_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

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
    default:
    
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

// Simulate online payment (no screenshot needed)
function simulatePayment($conn, $resident_id) {
    $request_id = intval($_POST['request_id'] ?? 0);
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'gcash');
    
    if ($request_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
        return;
    }
    
    // Get request details
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
    
    // Check if free document
    if ($request['fee_type'] === 'student' || $request['fee_type'] === 'senior' || $request['fee'] == 0) {
        echo json_encode(['success' => false, 'message' => 'This document is FREE. No payment required.']);
        return;
    }
    
    // Calculate amount
    $amount = floatval($request['fee'] * $request['quantity']);
    
    // Check if payment already exists and is paid
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
    
    // Delete any existing pending payment for this request
    $delete_sql = "DELETE FROM document_payments WHERE request_id = ? AND payment_status IN ('pending', 'pay_at_claim')";
    $delete_stmt = mysqli_prepare($conn, $delete_sql);
    mysqli_stmt_bind_param($delete_stmt, "i", $request_id);
    mysqli_stmt_execute($delete_stmt);
    mysqli_stmt_close($delete_stmt);
    
    // Create payment record - mark as paid immediately (simulated)
    $transaction_id = 'SIM_' . strtoupper(uniqid());
    $insert_sql = "INSERT INTO document_payments (request_id, resident_id, amount, payment_method, payment_status, transaction_id, payment_date) 
                   VALUES (?, ?, ?, ?, 'paid', ?, NOW())";
    $insert_stmt = mysqli_prepare($conn, $insert_sql);
    mysqli_stmt_bind_param($insert_stmt, "iidss", $request_id, $resident_id, $amount, $payment_method, $transaction_id);
    
    if (mysqli_stmt_execute($insert_stmt)) {
        $payment_id = mysqli_insert_id($conn);
        
        // Update document_requests
        $update_sql = "UPDATE document_requests SET payment_status = 'paid', payment_id = ? WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "ii", $payment_id, $request_id);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
        
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

// Pay at Claim function
function payAtClaim($conn, $resident_id) {
    $request_id = intval($_POST['request_id'] ?? 0);
    
    if ($request_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
        return;
    }
    
    // Get request details
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
    
    // Check if free document
    if ($request['fee_type'] === 'student' || $request['fee_type'] === 'senior' || $request['fee'] == 0) {
        echo json_encode(['success' => false, 'message' => 'This document is FREE. No payment required.']);
        return;
    }
    
    // Calculate amount
    $amount = floatval($request['fee'] * $request['quantity']);
    
    // Check if payment already exists and is paid
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
    
    // Check if pay_at_claim already exists
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
    
    // Delete any existing pending payment for this request
    $delete_sql = "DELETE FROM document_payments WHERE request_id = ? AND payment_status IN ('pending')";
    $delete_stmt = mysqli_prepare($conn, $delete_sql);
    mysqli_stmt_bind_param($delete_stmt, "i", $request_id);
    mysqli_stmt_execute($delete_stmt);
    mysqli_stmt_close($delete_stmt);
    
    // Create payment record with pay_at_claim - set both payment_method and payment_status
    $insert_sql = "INSERT INTO document_payments (request_id, resident_id, amount, payment_method, payment_status) 
                   VALUES (?, ?, ?, 'pay_at_claim', 'pay_at_claim')";
    $insert_stmt = mysqli_prepare($conn, $insert_sql);
    mysqli_stmt_bind_param($insert_stmt, "iid", $request_id, $resident_id, $amount);
    
    if (mysqli_stmt_execute($insert_stmt)) {
        $payment_id = mysqli_insert_id($conn);
        
        // Update document_requests with payment_status = 'pay_at_claim'
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
    // Function kept for compatibility but not used
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

function createPayment($conn, $resident_id) {
    $request_id = intval($_POST['request_id'] ?? 0);
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'gcash');
    
    if ($request_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
        return;
    }
    
    // Verify request belongs to resident and is approved
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
    
    // Check if free document
    if ($request['fee_type'] === 'student' || $request['fee_type'] === 'senior' || $request['fee'] == 0) {
        echo json_encode(['success' => false, 'message' => 'This document is FREE. No payment required.']);
        return;
    }
    
    // Calculate total amount
    $amount = floatval($request['fee']) * intval($request['quantity']);
    
    // Check if payment already exists
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
    
    // Create payment record
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
        
        // Update document_requests
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

function confirmPayAtClaim($conn, $resident_id) {
    $payment_id = intval($_POST['payment_id'] ?? 0);
    
    if ($payment_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment ID']);
        return;
    }
    
    // Verify payment belongs to resident
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
    
    // Mark as pay_at_claim confirmed
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
        // Check if free document
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

function markNotesAsRead($conn, $resident_id) {
    $request_id = intval($_POST['request_id'] ?? 0);
    
    if ($request_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
        return;
    }
    
    // Verify the request belongs to this resident
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
    
    // Mark notes as read by updating the last_viewed timestamp
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

function checkNewNotes($conn, $resident_id) {
    // Check for requests with admin notes that have not been viewed
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

function getRequestDetails($conn, $resident_id) {
    $request_id = intval($_GET['request_id'] ?? 0);
    
    if ($request_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
        return;
    }
    
    // Get request details
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
    
    // Parse custom data
    $custom_data = [];
    if ($request['custom_data']) {
        $pairs = explode(';;;', $request['custom_data']);
        foreach ($pairs as $pair) {
            $parts = explode('|||', $pair, 2);
            if (count($parts) == 2) {
                // Try to decode JSON for children field
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
            'custom_data' => $custom_data
        ]
    ]);
}

function updateRequest($conn, $resident_id) {
    $request_id = intval($_POST['edit_request_id'] ?? 0);
    
    if ($request_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
        return;
    }
    
    // Verify the request belongs to this resident and is pending
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
    
    // Get form data
    $quantity = intval($_POST['quantity'] ?? 1);
    $notes = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
    $fee_type = mysqli_real_escape_string($conn, $_POST['fee_type'] ?? 'regular');
    $fee = floatval($_POST['fee'] ?? 0);
    
    // Handle file upload if new file is provided
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
                // Delete old file if exists
                if ($request['id_document_path'] && file_exists(__DIR__ . '/../' . $request['id_document_path'])) {
                    unlink(__DIR__ . '/../' . $request['id_document_path']);
                }
                $id_document_path = 'uploads/id_documents/' . $new_filename;
            }
        }
    }
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Update main request
        $update_sql = "UPDATE document_requests 
                       SET quantity = ?, notes = ?, fee_type = ?, fee = ?, id_document_path = ?
                       WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "issdsi", $quantity, $notes, $fee_type, $fee, $id_document_path, $request_id);
        
        if (!mysqli_stmt_execute($update_stmt)) {
            throw new Exception('Failed to update request');
        }
        mysqli_stmt_close($update_stmt);
        
        // Delete existing custom data
        $delete_sql = "DELETE FROM document_requests_custom_data WHERE request_id = ?";
        $delete_stmt = mysqli_prepare($conn, $delete_sql);
        mysqli_stmt_bind_param($delete_stmt, "i", $request_id);
        mysqli_stmt_execute($delete_stmt);
        mysqli_stmt_close($delete_stmt);
        
        // Insert updated custom fields
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
    
    // Verify the request belongs to this resident and is pending
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
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Delete custom data first
        $delete_custom_sql = "DELETE FROM document_requests_custom_data WHERE request_id = ?";
        $delete_custom_stmt = mysqli_prepare($conn, $delete_custom_sql);
        mysqli_stmt_bind_param($delete_custom_stmt, "i", $request_id);
        mysqli_stmt_execute($delete_custom_stmt);
        mysqli_stmt_close($delete_custom_stmt);
        
        // Delete the request
        $delete_sql = "DELETE FROM document_requests WHERE id = ?";
        $delete_stmt = mysqli_prepare($conn, $delete_sql);
        mysqli_stmt_bind_param($delete_stmt, "i", $request_id);
        
        if (!mysqli_stmt_execute($delete_stmt)) {
            throw new Exception('Failed to delete request');
        }
        
        // Delete the uploaded ID document if it exists
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

// First check if there's a payment record with pay_at_claim
if ($row['payment_method'] === 'pay_at_claim') {
    $payment_status = 'pay_at_claim';
} elseif ($row['payment_status'] === 'pay_at_claim') {
    $payment_status = 'pay_at_claim';
} elseif ($row['payment_status'] === 'paid') {
    $payment_status = 'paid';
} elseif ($row['fee_type'] === 'student' || $row['fee_type'] === 'senior' || $row['fee'] == 0) {
    $payment_status = 'free';
}

// Double check payments table for any pay_at_claim record
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
    'payment_expiry' => $row['expiry_date']
];
    }
    
    echo json_encode(['success' => true, 'requests' => $requests]);
}

function submitRequest($conn, $resident_id) {
    // Get resident's existing data from database
    $resident_sql = "SELECT first_name, last_name, middle_name FROM resident WHERE id = ?";
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
    
    // Validate required fields
    $document_type = mysqli_real_escape_string($conn, $_POST['document_type'] ?? '');
    $document_type_id = intval($_POST['document_id'] ?? 0);
    $purpose = mysqli_real_escape_string($conn, $_POST['purpose'] ?? 'Document Request');
    $notes = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
    $fee_type = mysqli_real_escape_string($conn, $_POST['fee_type'] ?? 'regular');
    $fee = floatval($_POST['fee'] ?? 0);
    $quantity = intval($_POST['quantity'] ?? 1);
    
    // Validate required fields
    if (empty($document_type) || $document_type_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid document type selected']);
        return;
    }
    
    // Use resident's data from database (not from form)
    $request_first_name = $resident_data['first_name'];
    $request_last_name = $resident_data['last_name'];
    $request_middle_name = $resident_data['middle_name'] ?? '';
    
    // Handle file upload
    if (!isset($_FILES['id_document']) || $_FILES['id_document']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Please upload a valid ID document']);
        return;
    }
    
    $upload_dir = __DIR__ . '/../uploads/id_documents/';
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
            echo json_encode(['success' => false, 'message' => 'Failed to create upload directory']);
            return;
        }
    }
    
    $file_extension = strtolower(pathinfo($_FILES['id_document']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
    
    if (!in_array($file_extension, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: JPG, PNG, PDF']);
        return;
    }
    
    $new_filename = 'id_' . $resident_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
    $upload_path = $upload_dir . $new_filename;
    
    if (!move_uploaded_file($_FILES['id_document']['tmp_name'], $upload_path)) {
        echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
        return;
    }
    
    $id_document_path = 'uploads/id_documents/' . $new_filename;
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Insert main request
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
                    status, 
                    request_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
        
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare statement: ' . mysqli_error($conn));
        }
        
        mysqli_stmt_bind_param($stmt, "iissssdis", 
            $resident_id, 
            $document_type_id, 
            $document_type, 
            $purpose, 
            $notes, 
            $fee_type, 
            $fee,
            $quantity,
            $id_document_path
        );
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('Failed to insert request: ' . mysqli_stmt_error($stmt));
        }
        
        $request_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        
        // Insert custom fields if any
        if (isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
            $custom_sql = "INSERT INTO document_requests_custom_data (request_id, field_name, field_value) VALUES (?, ?, ?)";
            $custom_stmt = mysqli_prepare($conn, $custom_sql);
            
            if ($custom_stmt) {
                foreach ($_POST['custom_fields'] as $field_name => $field_value) {
                    // Check if this is a children array (JSON encoded)
                    if ($field_name === 'children' && is_array($field_value)) {
                        // Convert children array to JSON for storage
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
        echo json_encode(['success' => true, 'message' => 'Document request submitted successfully!']);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getStats($conn, $resident_id) {
    $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status IN ('approved', 'completed') THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
            FROM document_requests 
            WHERE resident_id = ?";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $resident_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $stats = mysqli_fetch_assoc($result);
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'total' => intval($stats['total']),
            'approved' => intval($stats['approved']),
            'pending' => intval($stats['pending']),
            'rejected' => intval($stats['rejected'])
        ]
    ]);
}
?>