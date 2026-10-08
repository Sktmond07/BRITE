<?php
// QR Code generator using phpqrcode library - Silent mode (no display)
session_start();

require_once __DIR__ . '/../config/database.php';

// **FIX: Correct path to phpqrcode library**
$qrLibPath = __DIR__ . '/qrcode/phpqrcode.php';

if (!file_exists($qrLibPath)) {
    error_log("QR library not found at: " . $qrLibPath);
    http_response_code(500);
    exit();
}

require_once $qrLibPath;

// Handle QR generation request (silent mode - no output)
if (isset($_GET['request_id'])) {
    $request_id = intval($_GET['request_id']);
    
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
        http_response_code(404);
        exit();
    }
    
    // Create QR code directory
    $qrDir = __DIR__ . '/../generated_qrcodes/';
    if (!file_exists($qrDir)) {
        mkdir($qrDir, 0777, true);
    }
    
    // Generate verification code
    $verificationCode = md5($request_id . $request['email'] . time() . uniqid());
    
    // Create QR data payload
    $qrPayload = json_encode([
        'request_id' => $request_id,
        'document_type' => $request['document_type'],
        'resident_name' => $request['first_name'] . ' ' . $request['last_name'],
        'resident_email' => $request['email'],
        'issue_date' => date('Y-m-d H:i:s'),
        'verification_code' => $verificationCode,
        'verified' => false
    ]);
    
    // Generate QR code using phpqrcode
    $qrFilename = 'qr_document_' . $request_id . '_' . time() . '.png';
    $qrPath = $qrDir . $qrFilename;
    
    // Parameters: text, outfile, error_correction_level, pixel_size, margin
    QRcode::png($qrPayload, $qrPath, QR_ECLEVEL_H, 10, 2);
    
    // Check if file was created successfully
    if (file_exists($qrPath) && filesize($qrPath) > 0) {
        // Update database with QR code path
        $relativePath = 'generated_qrcodes/' . $qrFilename;
        $updateSql = "UPDATE document_requests SET qr_code_path = ?, qr_verification_code = ? WHERE id = ?";
        $updateStmt = mysqli_prepare($conn, $updateSql);
        mysqli_stmt_bind_param($updateStmt, "ssi", $relativePath, $verificationCode, $request_id);
        mysqli_stmt_execute($updateStmt);
        error_log("QR Code generated: " . $relativePath);
    } else {
        error_log("Failed to generate QR code for request_id: " . $request_id);
    }
    
    // Silent exit - no output
    exit();
}

// Test endpoint
if (isset($_GET['test'])) {
    header('Content-Type: text/html');
    echo "<h1>PHP QR Code Test</h1>";
    
    // Check GD extension
    if (extension_loaded('gd')) {
        echo "<p style='color:green'>✓ GD extension is loaded</p>";
    } else {
        echo "<p style='color:red'>✗ GD extension is NOT loaded</p>";
        echo "<p>Please enable GD extension in php.ini</p>";
    }
    
    // Check if phpqrcode.php exists
    if (file_exists(__DIR__ . '/qrcode/phpqrcode.php')) {
        echo "<p style='color:green'>✓ phpqrcode.php found</p>";
        echo "<p>Path: " . __DIR__ . "/qrcode/phpqrcode.php</p>";
    } else {
        echo "<p style='color:red'>✗ phpqrcode.php not found at: " . __DIR__ . "/qrcode/phpqrcode.php</p>";
    }
    
    // Create test directory
    $testDir = __DIR__ . '/../generated_qrcodes/';
    if (!file_exists($testDir)) {
        mkdir($testDir, 0777, true);
        echo "<p>Created directory: $testDir</p>";
    }
    
    echo "<p>QR library will be used when approving documents.</p>";
    echo "<p><a href='?request_id=54'>Test QR for request ID 54</a></p>";
    exit();
}