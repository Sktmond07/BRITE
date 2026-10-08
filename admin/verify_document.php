<?php
session_start();

require_once __DIR__ . '/../config/database.php';

// No login required - this is for scanning QR codes

$verification_status = null;
$document_data = null;
$error_message = null;

// Function to format custom field values (supports children_list, JSON data, etc.)
function formatCustomFieldValue($fieldName, $value, $fieldType = null) {
    // Handle children_list field type (stored as JSON)
    if (is_string($value) && (strpos($value, '[') === 0 || strpos($value, '{') === 0)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $value = $decoded;
        }
    }
    
    if (is_array($value)) {
        // Check if it's children data (has full_name, age, birthdate)
        if (isset($value[0]) && is_array($value[0])) {
            $firstItem = $value[0];
            if (isset($firstItem['full_name']) || isset($firstItem['age']) || isset($firstItem['birthdate'])) {
                $html = '<table class="children-table">';
                $html .= '<thead><tr><th>Full Name</th><th>Age</th><th>Birth Date</th></tr></thead><tbody>';
                foreach ($value as $child) {
                    $html .= '<tr>';
                    $html .= '<td>' . htmlspecialchars($child['full_name'] ?? 'N/A') . '</td>';
                    $html .= '<td>' . htmlspecialchars($child['age'] ?? 'N/A') . '</td>';
                    $html .= '<td>' . htmlspecialchars($child['birthdate'] ?? 'N/A') . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
                return $html;
            }
        }
        
        // Check if it's an associative array
        $isAssoc = !isset($value[0]);
        if ($isAssoc) {
            $html = '<div class="assoc-fields">';
            foreach ($value as $key => $val) {
                if (is_array($val)) {
                    $html .= '<div class="assoc-item"><strong>' . htmlspecialchars(ucwords(str_replace('_', ' ', $key))) . ':</strong><br>';
                    $html .= '<div style="margin-left: 15px;">' . formatCustomFieldValue($key, $val) . '</div></div>';
                } else {
                    $html .= '<div class="assoc-item"><strong>' . htmlspecialchars(ucwords(str_replace('_', ' ', $key))) . ':</strong> ' . htmlspecialchars($val) . '</div>';
                }
            }
            $html .= '</div>';
            return $html;
        }
        
        // Generic array display
        $html = '<div class="nested-fields">';
        foreach ($value as $index => $item) {
            if (is_array($item)) {
                $html .= '<div class="nested-item"><strong>Item ' . ($index + 1) . ':</strong><br>';
                foreach ($item as $key => $val) {
                    $html .= '<span class="nested-field"><strong>' . htmlspecialchars(ucwords(str_replace('_', ' ', $key))) . ':</strong> ' . htmlspecialchars($val) . '</span><br>';
                }
                $html .= '</div>';
            } else {
                $html .= '<div class="nested-item">' . htmlspecialchars($item) . '</div>';
            }
        }
        $html .= '</div>';
        return $html;
    }
    
    return htmlspecialchars($value);
}

// Handle QR code data from query parameter
$qr_data = isset($_GET['qr_data']) ? $_GET['qr_data'] : (isset($_POST['qr_data']) ? $_POST['qr_data'] : null);

if ($qr_data) {
    // Decode the QR data
    $decoded_data = json_decode($qr_data, true);
    
    if ($decoded_data && isset($decoded_data['request_id'])) {
        $request_id = intval($decoded_data['request_id']);
        $verification_code = isset($decoded_data['verification_code']) ? $decoded_data['verification_code'] : '';
        
        // Get document details from database
        $sql = "SELECT dr.*, r.first_name, r.last_name, r.email, r.address, r.phone 
                FROM document_requests dr 
                JOIN resident r ON dr.resident_id = r.id 
                WHERE dr.id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $document = mysqli_fetch_assoc($result);
        
        if ($document) {
            // Verify the verification code matches
            if ($verification_code === $document['qr_verification_code']) {
                // Get custom fields data from document_requests_custom_data
                $customSql = "SELECT field_name, field_value FROM document_requests_custom_data WHERE request_id = ?";
                $customStmt = mysqli_prepare($conn, $customSql);
                mysqli_stmt_bind_param($customStmt, "i", $request_id);
                mysqli_stmt_execute($customStmt);
                $customResult = mysqli_stmt_get_result($customStmt);
                $custom_fields = [];
                while ($customRow = mysqli_fetch_assoc($customResult)) {
                    $custom_fields[$customRow['field_name']] = $customRow['field_value'];
                }
                mysqli_stmt_close($customStmt);
                
                // Also get field types from document_custom_fields for proper formatting
                $fieldTypesSql = "SELECT field_name, field_type FROM document_custom_fields WHERE document_type_id = ?";
                $fieldTypesStmt = mysqli_prepare($conn, $fieldTypesSql);
                mysqli_stmt_bind_param($fieldTypesStmt, "i", $document['document_type_id']);
                mysqli_stmt_execute($fieldTypesStmt);
                $fieldTypesResult = mysqli_stmt_get_result($fieldTypesStmt);
                $field_types = [];
                while ($typeRow = mysqli_fetch_assoc($fieldTypesResult)) {
                    $field_types[$typeRow['field_name']] = $typeRow['field_type'];
                }
                mysqli_stmt_close($fieldTypesStmt);
                
                $document_data = [
                    'document' => $document,
                    'custom_fields' => $custom_fields,
                    'field_types' => $field_types,
                    'qr_verified' => true
                ];
                $verification_status = 'verified';
            } else {
                $error_message = 'Invalid verification code. The document may have been tampered with.';
                $verification_status = 'invalid';
            }
        } else {
            $error_message = 'Document not found in our records.';
            $verification_status = 'not_found';
        }
        mysqli_stmt_close($stmt);
    } else {
        $error_message = 'Invalid QR code format. Please scan a valid document QR code.';
        $verification_status = 'invalid';
    }
}

// Handle marking document as completed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_completed']) && isset($_POST['request_id'])) {
    $request_id = intval($_POST['request_id']);
    
   $updateSql = "UPDATE document_requests SET status = 'claimed', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status IN ('approved', 'unclaimed')";
    $updateStmt = mysqli_prepare($conn, $updateSql);
    mysqli_stmt_bind_param($updateStmt, "i", $request_id);
    
    if (mysqli_stmt_execute($updateStmt) && mysqli_stmt_affected_rows($updateStmt) > 0) {
        $completion_status = 'success';
    } else {
        $completion_status = 'error';
    }
    mysqli_stmt_close($updateStmt);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Document Verification | Barangay System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- Include Instascan for QR code scanning -->
    <script src="https://rawgit.com/schmich/instascan-builds/master/instascan.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1a472a 0%, #2b9b54 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        /* Header */
        .header {
            text-align: center;
            padding: 30px 20px;
            color: white;
        }
        
        .header h1 {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 1rem;
            opacity: 0.9;
        }
        
        /* Scanner Section */
        .scanner-section {
            background: white;
            border-radius: 24px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        
        .scanner-title {
            text-align: center;
            margin-bottom: 25px;
        }
        
        .scanner-title h2 {
            color: #1a472a;
            margin-bottom: 8px;
        }
        
        .scanner-title p {
            color: #6c757d;
        }
        
        .scanner-input-area {
            max-width: 600px;
            margin: 0 auto;
        }
        
        .qr-input-group {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .qr-input {
            flex: 1;
            padding: 15px;
            border: 2px solid #e2efe8;
            border-radius: 12px;
            font-size: 14px;
            font-family: monospace;
            transition: all 0.3s;
        }
        
        .qr-input:focus {
            outline: none;
            border-color: #43e97b;
        }
        
        .btn-scan {
            background: #43e97b;
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn-scan:hover {
            background: #2b9b54;
            transform: translateY(-2px);
        }
        
        .or-divider {
            text-align: center;
            margin: 20px 0;
            position: relative;
        }
        
        .or-divider::before,
        .or-divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 45%;
            height: 1px;
            background: #e2efe8;
        }
        
        .or-divider::before { left: 0; }
        .or-divider::after { right: 0; }
        
        .or-divider span {
            background: white;
            padding: 0 15px;
            color: #8ba88e;
        }
        
        /* Camera Scanner */
        .camera-scanner-container {
            margin-top: 20px;
            display: none;
        }
        
        .camera-scanner-container.active {
            display: block;
        }
        
        .video-container {
            position: relative;
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
            border-radius: 16px;
            overflow: hidden;
            border: 2px solid #e2efe8;
        }
        
        #preview {
            width: 100%;
            height: auto;
            background: #000;
        }
        
        .scan-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
        }
        
        .scan-frame {
            width: 80%;
            height: 80%;
            border: 2px solid #43e97b;
            border-radius: 12px;
            box-shadow: 0 0 0 1000px rgba(0,0,0,0.3);
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0% { border-color: #43e97b; box-shadow: 0 0 0 1000px rgba(0,0,0,0.3); }
            50% { border-color: #2b9b54; box-shadow: 0 0 0 1000px rgba(0,0,0,0.4); }
            100% { border-color: #43e97b; box-shadow: 0 0 0 1000px rgba(0,0,0,0.3); }
        }
        
        .camera-controls {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 15px;
        }
        
        .btn-camera {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn-camera.start {
            background: #28a745;
        }
        
        .btn-camera.start:hover {
            background: #218838;
        }
        
        .btn-camera.stop {
            background: #dc3545;
        }
        
        .btn-camera.stop:hover {
            background: #c82333;
        }
        
        .btn-camera:hover {
            transform: translateY(-2px);
        }
        
        /* Document Details Section */
        .document-section {
            background: white;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            display: none;
        }
        
        .document-section.visible {
            display: block;
            animation: slideUp 0.4s ease;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .verification-badge {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .verification-badge i {
            font-size: 30px;
        }
        
        .verification-badge h3 {
            margin: 0;
            font-size: 1.1rem;
        }
        
        .verification-badge p {
            margin: 0;
            font-size: 0.8rem;
            opacity: 0.9;
        }
        
        .invalid-badge {
            background: linear-gradient(135deg, #dc3545, #c82333);
        }
        
        .document-content {
            padding: 25px;
        }
        
        .doc-header {
            border-bottom: 2px solid #43e97b;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .doc-header h2 {
            color: #1a472a;
            font-size: 1.5rem;
        }
        
        .doc-header p {
            color: #6c757d;
            font-size: 0.85rem;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .info-card {
            background: #f8faf8;
            border-radius: 16px;
            padding: 18px;
            border: 1px solid #e2efe8;
        }
        
        .info-card h4 {
            color: #1a472a;
            margin-bottom: 15px;
            font-size: 1rem;
            border-left: 3px solid #43e97b;
            padding-left: 10px;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 10px;
            padding: 5px 0;
            border-bottom: 1px solid #eef2ef;
        }
        
        .info-label {
            width: 130px;
            font-weight: 600;
            color: #5f7f6e;
            font-size: 0.85rem;
        }
        
        .info-value {
            flex: 1;
            color: #2c3e50;
            font-size: 0.9rem;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-badge.approved {
            background: #d4edda;
            color: #155724;
        }
        
        .status-badge.completed {
            background: #cce5ff;
            color: #004085;
        }
        
        .status-badge.pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .custom-fields-section {
            margin-top: 20px;
        }
        
        .custom-field-item {
            padding: 15px;
            background: white;
            border-radius: 12px;
            margin-bottom: 12px;
            border: 1px solid #e2efe8;
        }
        
        .custom-field-label {
            font-weight: 700;
            color: #1a472a;
            font-size: 0.85rem;
            text-transform: uppercase;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #e2efe8;
        }
        
        .custom-field-label i {
            margin-right: 8px;
            color: #43e97b;
        }
        
        .custom-field-value {
            color: #2c3e50;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        
        .children-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            margin-top: 8px;
        }
        
        .children-table th {
            background: #e8f5e9;
            color: #1a472a;
            padding: 10px;
            text-align: left;
            font-weight: 600;
        }
        
        .children-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #e2efe8;
        }
        
        .children-table tr:last-child td {
            border-bottom: none;
        }
        
        .assoc-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .assoc-item {
            padding: 5px 0;
        }
        
        .nested-fields {
            background: #f0f7f0;
            padding: 12px;
            border-radius: 10px;
            margin-top: 5px;
        }
        
        .nested-item {
            background: white;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 8px;
            border: 1px solid #d4e6d4;
        }
        
        .nested-item:last-child {
            margin-bottom: 0;
        }
        
        .nested-field {
            font-size: 0.85rem;
            line-height: 1.6;
            display: inline-block;
            margin-right: 15px;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e2efe8;
        }
        
        .btn-complete {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn-complete:hover {
            background: #138496;
            transform: translateY(-2px);
        }
        
        .btn-complete:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }
        
        .btn-download {
            background: #28a745;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        
        .btn-download:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .scanning-status {
            text-align: center;
            margin-top: 10px;
            color: #6c757d;
            font-size: 0.85rem;
        }
        
        @media (max-width: 768px) {
            .qr-input-group {
                flex-direction: column;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .info-row {
                flex-direction: column;
            }
            
            .info-label {
                width: 100%;
                margin-bottom: 5px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-complete, .btn-download {
                width: 100%;
                justify-content: center;
            }
            
            .children-table {
                font-size: 0.7rem;
            }
            
            .children-table th,
            .children-table td {
                padding: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-qrcode"></i> Document Verification System</h1>
            <p>Scan QR code to verify document authenticity and claim your document</p>
        </div>
        
        <div class="scanner-section">
            <div class="scanner-title">
                <h2><i class="fas fa-camera"></i> QR Code Scanner</h2>
                <p>Position the QR code in front of your camera for automatic detection</p>
            </div>
            
            <div class="scanner-input-area">
                <div class="qr-input-group">
                    <input type="text" id="qrInput" class="qr-input" placeholder="Or enter QR code data manually..." value="<?php echo htmlspecialchars($qr_data ?? ''); ?>">
                    <button class="btn-scan" onclick="scanQRCode()">
                        <i class="fas fa-search"></i> Verify
                    </button>
                </div>
                
                <div class="or-divider">
                    <span>OR</span>
                </div>
                
                <button class="btn-camera start" id="startCameraBtn" onclick="startScanner()">
                    <i class="fas fa-camera"></i> Start Camera Scanner
                </button>
                
                <div class="camera-scanner-container" id="cameraScanner">
                    <div class="video-container">
                        <video id="preview" playsinline autoplay></video>
                        <div class="scan-overlay">
                            <div class="scan-frame"></div>
                        </div>
                    </div>
                    <div class="camera-controls">
                        <button class="btn-camera stop" onclick="stopScanner()">
                            <i class="fas fa-stop"></i> Stop Scanner
                        </button>
                    </div>
                    <div class="scanning-status" id="scanningStatus">
                        <i class="fas fa-spinner fa-spin"></i> Waiting for QR code...
                    </div>
                </div>
            </div>
        </div>
        
        <?php if (isset($completion_status)): ?>
            <?php if ($completion_status === 'success'): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> 
                    <strong>Document Marked as Completed!</strong> The resident can now claim their document.
                </div>
            <?php else: ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> 
                    <strong>Error!</strong> Failed to mark document as completed. The document may already be completed.
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> 
                <strong>Verification Failed!</strong> <?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($document_data && $verification_status === 'verified'): ?>
            <?php $doc = $document_data['document']; ?>
            <div class="document-section visible" id="documentSection">
                <div class="verification-badge">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <h3>Document Verified ✓</h3>
                        <p>This document is authentic and valid</p>
                    </div>
                </div>
                
                <div class="document-content">
                    <div class="doc-header">
                        <h2><i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($doc['document_type']); ?></h2>
                        <p>Issued by Barangay System | Request #<?php echo $doc['id']; ?></p>
                    </div>
                    
                    <div class="info-grid">
                        <div class="info-card">
                            <h4><i class="fas fa-user"></i> Resident Information</h4>
                            <div class="info-row">
                                <div class="info-label">Full Name:</div>
                                <div class="info-value"><strong><?php echo htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']); ?></strong></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Email:</div>
                                <div class="info-value"><?php echo htmlspecialchars($doc['email']); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Phone:</div>
                                <div class="info-value"><?php echo htmlspecialchars($doc['phone'] ?? 'Not provided'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Address:</div>
                                <div class="info-value"><?php echo htmlspecialchars($doc['address'] ?? 'Not specified'); ?></div>
                            </div>
                        </div>
                        
                        <div class="info-card">
                            <h4><i class="fas fa-info-circle"></i> Document Information</h4>
                            <div class="info-row">
                                <div class="info-label">Document Type:</div>
                                <div class="info-value"><?php echo htmlspecialchars($doc['document_type']); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Request Date:</div>
                                <div class="info-value"><?php echo date('F d, Y', strtotime($doc['request_date'])); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Processed Date:</div>
                                <div class="info-value"><?php echo $doc['processed_date'] ? date('F d, Y', strtotime($doc['processed_date'])) : 'Pending'; ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Status:</div>
                                <div class="info-value">
                                    <span class="status-badge <?php echo strtolower($doc['status']); ?>">
                                        <i class="fas <?php echo $doc['status'] == 'approved' ? 'fa-check-circle' : ($doc['status'] == 'claimed' ? 'fa-check-double' : 'fa-clock'); ?>"></i>
<?php echo ucfirst($doc['status']); ?>
                                        <?php echo ucfirst($doc['status']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Purpose:</div>
                                <div class="info-value"><?php echo nl2br(htmlspecialchars($doc['purpose'] ?? 'Not specified')); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Quantity:</div>
                                <div class="info-value"><?php echo $doc['quantity'] ?? 1; ?> copy/copies</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Fee Paid:</div>
                                <div class="info-value">₱<?php echo number_format($doc['fee'] ?? 0, 2); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Custom Fields Section -->
                    <?php if (!empty($document_data['custom_fields'])): ?>
                        <div class="info-card" style="margin-top: 0;">
                            <h4><i class="fas fa-tasks"></i> Additional Information</h4>
                            <div class="custom-fields-section">
                                <?php foreach ($document_data['custom_fields'] as $fieldName => $fieldValue): ?>
                                    <?php if (!empty($fieldValue)): ?>
                                        <div class="custom-field-item">
                                            <div class="custom-field-label">
                                                <i class="fas fa-tag"></i> <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $fieldName))); ?>
                                            </div>
                                            <div class="custom-field-value">
                                                <?php 
                                                    $fieldType = isset($document_data['field_types'][$fieldName]) ? $document_data['field_types'][$fieldName] : null;
                                                    echo formatCustomFieldValue($fieldName, $fieldValue, $fieldType); 
                                                ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Admin Notes -->
                    <?php if ($doc['admin_notes']): ?>
                        <div class="info-card">
                            <h4><i class="fas fa-sticky-note"></i> Admin Notes</h4>
                            <div class="info-value">
                                <?php echo nl2br(htmlspecialchars($doc['admin_notes'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Verification Code -->
                    <div class="info-card">
                        <h4><i class="fas fa-shield-alt"></i> Verification Details</h4>
                        <div class="info-row">
                            <div class="info-label">Verification Code:</div>
                            <div class="info-value"><code><?php echo htmlspecialchars(substr($doc['qr_verification_code'], 0, 20) . '...'); ?></code></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Verified On:</div>
                            <div class="info-value"><?php echo date('F d, Y h:i A'); ?></div>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <?php if ($doc['status'] === 'approved' || $doc['status'] === 'unclaimed'): ?>
    <form method="POST" action="" style="display: inline;">
        <input type="hidden" name="request_id" value="<?php echo $doc['id']; ?>">
        <button type="submit" name="mark_completed" class="btn-complete" onclick="return confirm('Mark this document as claimed? The resident has received the document.');">
            <i class="fas fa-check-double"></i> Mark as Claimed
        </button>
    </form>
<?php endif; ?>
                        
                        <?php if ($doc['document_path'] && file_exists(__DIR__ . '/../generated_documents/' . $doc['document_path'])): ?>
                            <a href="../generated_documents/<?php echo urlencode($doc['document_path']); ?>" class="btn-download" target="_blank">
                                <i class="fas fa-file-pdf"></i> View Document
                            </a>
                            <a href="download_document.php?request_id=<?php echo $doc['id']; ?>" class="btn-download">
                                <i class="fas fa-download"></i> Download Document
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php elseif ($qr_data && $verification_status !== 'verified'): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> 
                <strong>Invalid Document!</strong> The QR code you scanned is not valid or has been tampered with.
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
    <script>
        let scanner = null;
        let video = null;
        let scanning = false;
        let animationId = null;
        
        function startScanner() {
            const cameraScanner = document.getElementById('cameraScanner');
            cameraScanner.classList.add('active');
            
            const startBtn = document.getElementById('startCameraBtn');
            startBtn.style.display = 'none';
            
            video = document.getElementById('preview');
            
            if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } })
                    .then(function(stream) {
                        video.srcObject = stream;
                        video.setAttribute("playsinline", true);
                        video.play();
                        scanning = true;
                        requestAnimationFrame(tick);
                        document.getElementById('scanningStatus').innerHTML = '<i class="fas fa-camera"></i> Scanning for QR code...';
                    })
                    .catch(function(error) {
                        console.error("Camera error:", error);
                        alert('Unable to access camera. Please ensure you have granted camera permissions.');
                        stopScanner();
                    });
            } else {
                alert('Camera is not supported on this device');
            }
        }
        
        function tick() {
            if (!scanning) return;
            
            if (video.readyState === video.HAVE_ENOUGH_DATA) {
                const canvas = document.createElement('canvas');
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                
                const code = jsQR(imageData.data, imageData.width, imageData.height, {
                    inversionAttempts: "dontInvert",
                });
                
                if (code) {
                    // QR Code detected!
                    const qrData = code.data;
                    document.getElementById('scanningStatus').innerHTML = '<i class="fas fa-check-circle"></i> QR Code detected! Verifying...';
                    
                    // Stop scanning
                    stopScanner();
                    
                    // Redirect to verify with QR data
                    window.location.href = 'verify_document.php?qr_data=' + encodeURIComponent(qrData);
                    return;
                }
            }
            
            animationId = requestAnimationFrame(tick);
        }
        
        function stopScanner() {
            scanning = false;
            if (animationId) {
                cancelAnimationFrame(animationId);
                animationId = null;
            }
            
            if (video && video.srcObject) {
                const tracks = video.srcObject.getTracks();
                tracks.forEach(track => track.stop());
                video.srcObject = null;
            }
            
            const cameraScanner = document.getElementById('cameraScanner');
            cameraScanner.classList.remove('active');
            
            const startBtn = document.getElementById('startCameraBtn');
            startBtn.style.display = 'inline-flex';
            
            document.getElementById('scanningStatus').innerHTML = '<i class="fas fa-info-circle"></i> Scanner stopped';
        }
        
        function scanQRCode() {
            const qrData = document.getElementById('qrInput').value.trim();
            if (qrData) {
                window.location.href = 'verify_document.php?qr_data=' + encodeURIComponent(qrData);
            } else {
                alert('Please enter QR code data');
            }
        }
        
        // Allow Enter key in input field
        document.getElementById('qrInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                scanQRCode();
            }
        });
        
        // Clean up on page unload
        window.addEventListener('beforeunload', function() {
            if (video && video.srcObject) {
                const tracks = video.srcObject.getTracks();
                tracks.forEach(track => track.stop());
            }
        });
    </script>
</body>
</html>