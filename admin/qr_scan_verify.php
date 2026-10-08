<?php
// admin/qr_scan_verify.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

$qrData = null;
if (is_array($payload) && isset($payload['qr_data'])) {
    $qrData = $payload['qr_data'];
} elseif (is_string($raw)) {
    $qrData = trim($raw, "\" \n\r\t");
}

if (!$qrData) {
    echo json_encode(['success' => false, 'message' => 'No QR data received']);
    exit;
}

$decoded = json_decode($qrData, true);
$requestId = 0;
$verificationCode = '';

if (is_array($decoded)) {
    $requestId = intval($decoded['request_id'] ?? 0);
    $verificationCode = $decoded['verification_code'] ?? '';
} else {
    $verificationCode = $qrData;
}

if (!$requestId && !$verificationCode) {
    echo json_encode(['success' => false, 'message' => 'Invalid QR content']);
    exit;
}

if ($requestId && $verificationCode) {
    $sql = "SELECT dr.*, r.first_name, r.last_name, r.email
            FROM document_requests dr
            JOIN resident r ON dr.resident_id = r.id
            WHERE dr.id = ? AND dr.qr_verification_code = ?
            LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "is", $requestId, $verificationCode);
} else {
    $sql = "SELECT dr.*, r.first_name, r.last_name, r.email
            FROM document_requests dr
            JOIN resident r ON dr.resident_id = r.id
            WHERE dr.qr_verification_code = ?
            LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $verificationCode);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$request = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($stmt);

if (!$request) {
    echo json_encode(['success' => false, 'message' => 'QR code not recognized. No matching document found.']);
    exit;
}

$currentStatus = $request['status'];
$reqId = intval($request['id']);

if ($currentStatus === 'claimed') {
    echo json_encode([
        'success' => true,
        'already_claimed' => true,
        'message' => 'This document has already been claimed.',
        'request_id' => $reqId,
        'document_type' => $request['document_type'],
        'resident_name' => trim(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? '')),
        'print_url' => '../print_document.php?request_id=' . $reqId,
        'status' => 'claimed'
    ]);
    exit;
}

if (!in_array($currentStatus, ['approved', 'unclaimed'], true)) {
    echo json_encode([
        'success' => false,
        'message' => "Cannot claim. Current status is '{$currentStatus}'.",
        'request_id' => $reqId,
        'status' => $currentStatus
    ]);
    exit;
}

$upd = "UPDATE document_requests
        SET status = 'claimed',
            claimed_at = NOW(),
            completed_date = NOW(),
            completed_at = NOW(),
            completed_by = ?
        WHERE id = ? AND status IN ('approved','unclaimed')";
$stmt = mysqli_prepare($conn, $upd);
mysqli_stmt_bind_param($stmt, "ii", $_SESSION['user_id'], $reqId);

if (!mysqli_stmt_execute($stmt)) {
    echo json_encode(['success' => false, 'message' => 'Failed to update status: ' . mysqli_stmt_error($stmt)]);
    mysqli_stmt_close($stmt);
    exit;
}
mysqli_stmt_close($stmt);

$nameStmt = mysqli_prepare($conn, "SELECT full_name FROM admin WHERE id = ?");
mysqli_stmt_bind_param($nameStmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($nameStmt);
$nameRes = mysqli_stmt_get_result($nameStmt);
$adminName = $nameRes ? (mysqli_fetch_assoc($nameRes)['full_name'] ?? '') : '';
mysqli_stmt_close($nameStmt);

if ($adminName !== '') {
    $nStmt = mysqli_prepare($conn, "UPDATE document_requests SET completed_by_name = ? WHERE id = ?");
    mysqli_stmt_bind_param($nStmt, "si", $adminName, $reqId);
    mysqli_stmt_execute($nStmt);
    mysqli_stmt_close($nStmt);
}

echo json_encode([
    'success' => true,
    'message' => 'Document verified and marked as claimed.',
    'request_id' => $reqId,
    'document_type' => $request['document_type'],
    'resident_name' => trim(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? '')),
    'print_url' => '../print_document.php?request_id=' . $reqId,
    'status' => 'claimed',
    'claimed_at' => date('Y-m-d H:i:s')
]);