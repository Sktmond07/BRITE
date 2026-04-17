<?php
// QR Code generator using phpqrcode library - Silent mode (no display)
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/qrcode/phpqrcode.php';  // Use phpqrcode library

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
    // QR_ECLEVEL_H = High error correction (30%)
    // Size 10 = good readable size
    // Margin 2 = 2 modules margin
    QRcode::png($qrPayload, $qrPath, QR_ECLEVEL_H, 10, 2);
    
    // Check if file was created successfully
    if (file_exists($qrPath) && filesize($qrPath) > 0) {
        // Update database with QR code path
        $relativePath = 'generated_qrcodes/' . $qrFilename;
        $updateSql = "UPDATE document_requests SET qr_code_path = ?, qr_verification_code = ? WHERE id = ?";
        $updateStmt = mysqli_prepare($conn, $updateSql);
        mysqli_stmt_bind_param($updateStmt, "ssi", $relativePath, $verificationCode, $request_id);
        mysqli_stmt_execute($updateStmt);
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
        echo "<p style='color:red'>✗ GD extension is NOT loaded - phpqrcode requires GD</p>";
        echo "<p>Please enable GD extension in php.ini</p>";
        exit();
    }
    
    // Check if phpqrcode library exists
    $qrLibPath = __DIR__ . '/qrcode/phpqrcode.php';
    if (file_exists($qrLibPath)) {
        echo "<p style='color:green'>✓ phpqrcode.php found at: $qrLibPath</p>";
    } else {
        echo "<p style='color:red'>✗ phpqrcode.php not found at: $qrLibPath</p>";
        exit();
    }
    
    // Create test directory
    $testDir = __DIR__ . '/../generated_qrcodes/';
    if (!file_exists($testDir)) {
        mkdir($testDir, 0777, true);
        echo "<p>Created directory: $testDir</p>";
    }
    
    // Check if directory is writable
    if (is_writable($testDir)) {
        echo "<p style='color:green'>✓ Directory is writable: $testDir</p>";
    } else {
        echo "<p style='color:red'>✗ Directory is NOT writable: $testDir</p>";
    }
    
    // Generate test QR code
    $testData = "Test QR Code - " . date('Y-m-d H:i:s');
    $testFile = $testDir . 'test_phpqrcode.png';
    
    QRcode::png($testData, $testFile, QR_ECLEVEL_H, 10, 2);
    
    if (file_exists($testFile) && filesize($testFile) > 0) {
        echo "<p style='color:green'>✓ Test QR code generated successfully!</p>";
        echo "<img src='../generated_qrcodes/test_phpqrcode.png' style='border:1px solid #ccc;padding:10px;'>";
        echo "<p>File size: " . filesize($testFile) . " bytes</p>";
        echo "<p>File path: " . realpath($testFile) . "</p>";
    } else {
        echo "<p style='color:red'>✗ Failed to generate test QR code</p>";
        echo "<p>Check error logs for more details.</p>";
    }
    
    exit();
}

// Batch generate for all approved requests without QR
if (isset($_GET['batch_generate']) && $_GET['batch_generate'] == 'silent') {
    $sql = "SELECT dr.id, dr.document_type, r.first_name, r.last_name, r.email 
            FROM document_requests dr 
            JOIN resident r ON dr.resident_id = r.id 
            WHERE dr.status = 'approved' AND (dr.qr_code_path IS NULL OR dr.qr_code_path = '')";
    $result = mysqli_query($conn, $sql);
    
    $generated = 0;
    $failed = 0;
    
    while ($row = mysqli_fetch_assoc($result)) {
        $verificationCode = md5($row['id'] . $row['email'] . time() . $generated);
        $qrPayload = json_encode([
            'request_id' => $row['id'],
            'document_type' => $row['document_type'],
            'resident_name' => $row['first_name'] . ' ' . $row['last_name'],
            'resident_email' => $row['email'],
            'issue_date' => date('Y-m-d H:i:s'),
            'verification_code' => $verificationCode
        ]);
        
        $qrDir = __DIR__ . '/../generated_qrcodes/';
        if (!file_exists($qrDir)) mkdir($qrDir, 0777, true);
        
        $qrFilename = 'qr_document_' . $row['id'] . '_' . time() . '_' . $generated . '.png';
        $qrPath = $qrDir . $qrFilename;
        
        // Generate QR code using phpqrcode
        QRcode::png($qrPayload, $qrPath, QR_ECLEVEL_H, 10, 2);
        
        if (file_exists($qrPath) && filesize($qrPath) > 0) {
            $relativePath = 'generated_qrcodes/' . $qrFilename;
            $updateSql = "UPDATE document_requests SET qr_code_path = ?, qr_verification_code = ? WHERE id = ?";
            $updateStmt = mysqli_prepare($conn, $updateSql);
            mysqli_stmt_bind_param($updateStmt, "ssi", $relativePath, $verificationCode, $row['id']);
            
            if (mysqli_stmt_execute($updateStmt)) {
                $generated++;
            } else {
                $failed++;
            }
        } else {
            $failed++;
        }
        
        // Small delay to avoid overwhelming the server
        usleep(50000);
    }
    
    // Silent exit
    exit();
}
?>