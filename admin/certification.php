<?php
session_start();

require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../sign_in.php');
    exit();
}

$request_id = isset($_GET['request_id']) ? intval($_GET['request_id']) : 0;
$admin_id = $_SESSION['user_id'];

// Get current admin role for proper redirect
$roleSql = "SELECT admin_role, full_name FROM admin WHERE id = ?";
$roleStmt = mysqli_prepare($conn, $roleSql);
mysqli_stmt_bind_param($roleStmt, "i", $admin_id);
mysqli_stmt_execute($roleStmt);
$roleResult = mysqli_stmt_get_result($roleStmt);
$adminData = mysqli_fetch_assoc($roleResult);
$adminRole = $adminData['admin_role'];
$adminFullName = $adminData['full_name'];

// Determine dashboard URL based on role
function getDashboardUrl($role) {
    switch($role) {
        case 'captain':
            return 'dashboards/captain_dashboard.php';
        case 'secretary':
            return 'dashboards/secretary_dashboard.php';
        case 'kagawad':
            return 'dashboards/kagawad_dashboard.php';
        case 'lupon':
            return 'dashboards/lupon_dashboard.php';
        case 'super_admin':
        default:
            return 'dashboard.php';
    }
}

$dashboardUrl = getDashboardUrl($adminRole);
$dashboardLabel =' Dashboard';

// Get document info if request_id is provided
$documentInfo = null;
$filename = null;
$documentType = null;
$residentName = null;
$residentId = null;
$requestDate = null;
$status = null;
$admin_notes = null;
$purpose = null;
$quantity = null;
$fee = null;
$customFieldsList = [];
$idDocumentPath = null;

if ($request_id > 0) {
    // Check if connection exists
    if (!$conn) {
        die("Database connection failed");
    }
    
    // Main document request query - includes processor info
    $sql = "SELECT dr.document_path, dr.document_type, dr.status, dr.request_date, dr.admin_notes, 
                   dr.purpose, dr.quantity, dr.fee, dr.id_document_path,
                   dr.approved_by_name, dr.approved_at, dr.completed_by_name, dr.completed_at,
                   dr.rejected_by_name, dr.rejected_at,
                   r.first_name, r.last_name, r.id as resident_id
            FROM document_requests dr 
            JOIN resident r ON dr.resident_id = r.id 
            WHERE dr.id = ?";
    
    $stmt = mysqli_prepare($conn, $sql);
    
    if ($stmt === false) {
        die("MySQL prepare error: " . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "i", $request_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result && $row = mysqli_fetch_assoc($result)) {
        $filename = $row['document_path'];
        $documentType = $row['document_type'];
        $residentName = $row['first_name'] . ' ' . $row['last_name'];
        $residentId = $row['resident_id'];
        $requestDate = $row['request_date'];
        $status = $row['status'];
        $admin_notes = $row['admin_notes'];
        $purpose = $row['purpose'];
        $quantity = $row['quantity'];
        $fee = $row['fee'];
        $idDocumentPath = $row['id_document_path'];
        $documentInfo = $row;
        
        // Fetch custom fields from document_requests_custom_data table
        $customSql = "SELECT field_name, field_value FROM document_requests_custom_data WHERE request_id = ? ORDER BY id";
        $customStmt = mysqli_prepare($conn, $customSql);
        if ($customStmt) {
            mysqli_stmt_bind_param($customStmt, "i", $request_id);
            mysqli_stmt_execute($customStmt);
            $customResult = mysqli_stmt_get_result($customStmt);
            if ($customResult) {
                while ($customRow = mysqli_fetch_assoc($customResult)) {
                    $fieldName = $customRow['field_name'];
                    $fieldValue = $customRow['field_value'];
                    
                    // Try to decode JSON - handle both regular JSON and escaped JSON
                    $decodedValue = null;
                    
                    // First try normal json_decode
                    $decodedValue = json_decode($fieldValue, true);
                    
                    // If that fails, try stripping slashes first (for escaped JSON)
                    if ($decodedValue === null && is_string($fieldValue)) {
                        $stripped = stripslashes($fieldValue);
                        $decodedValue = json_decode($stripped, true);
                    }
                    
                    // If still not decoded, try to fix common issues
                    if ($decodedValue === null && is_string($fieldValue)) {
                        // Remove any extra escaping
                        $cleaned = preg_replace('/\\\\"/', '"', $fieldValue);
                        $decodedValue = json_decode($cleaned, true);
                    }
                    
                    if (is_array($decodedValue) && !empty($decodedValue)) {
                        // Successfully decoded JSON
                        $customFieldsList[$fieldName] = $decodedValue;
                    } else {
                        // Not JSON, store as plain text
                        $customFieldsList[$fieldName] = $fieldValue;
                    }
                }
            }
            mysqli_stmt_close($customStmt);
        }
    }
    mysqli_stmt_close($stmt);
}

// Helper function to format custom field values
function formatCustomFieldValue($fieldName, $value) {
    // If value is a string that looks like JSON, try to decode it
    if (is_string($value) && (strpos($value, '[') === 0 || strpos($value, '{') === 0)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $value = $decoded;
        }
    }
    
    if (is_array($value)) {
        // Check if it's an array of children (has full_name, age, birthdate)
        if (isset($value[0]) && is_array($value[0])) {
            $firstItem = $value[0];
            // Check if this looks like children data
            if (isset($firstItem['full_name']) || isset($firstItem['age']) || isset($firstItem['birthdate'])) {
                // Display as table for children
                $html = '<div class="children-table-container">';
                $html .= '<table class="children-table">';
                $html .= '<thead>';
                $html .= '<tr>';
                $html .= '<th>Name</th>';
                $html .= '<th>Age</th>';
                $html .= '<th>Birth Date</th>';
                $html .= '</tr>';
                $html .= '</thead>';
                $html .= '<tbody>';
                foreach ($value as $child) {
                    $html .= '<tr>';
                    $html .= '<td>' . htmlspecialchars($child['full_name'] ?? 'N/A') . '</td>';
                    $html .= '<td>' . htmlspecialchars($child['age'] ?? 'N/A') . '</td>';
                    $html .= '<td>' . htmlspecialchars($child['birthdate'] ?? 'N/A') . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody>';
                $html .= '</table>';
                $html .= '</div>';
                return $html;
            }
        }
        
        // Check if it's a simple associative array (not indexed)
        $isAssoc = false;
        if (!isset($value[0])) {
            $isAssoc = true;
        }
        
        if ($isAssoc) {
            // Display as key-value pairs
            $html = '<div class="assoc-fields">';
            foreach ($value as $key => $val) {
                if (is_array($val)) {
                    $html .= '<div class="assoc-item">';
                    $html .= '<strong>' . htmlspecialchars(ucwords(str_replace('_', ' ', $key))) . ':</strong><br>';
                    $html .= '<div style="margin-left: 15px;">' . formatCustomFieldValue($key, $val) . '</div>';
                    $html .= '</div>';
                } else {
                    $html .= '<div class="assoc-item">';
                    $html .= '<strong>' . htmlspecialchars(ucwords(str_replace('_', ' ', $key))) . ':</strong> ' . htmlspecialchars($val);
                    $html .= '</div>';
                }
            }
            $html .= '</div>';
            return $html;
        }
        
        // Generic array display
        $html = '<div class="nested-fields">';
        foreach ($value as $index => $item) {
            if (is_array($item)) {
                $html .= '<div class="nested-item">';
                $html .= '<strong>Item ' . ($index + 1) . ':</strong><br>';
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
    } else {
        return htmlspecialchars($value);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Certification Viewer | Barangay System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f4f9;
            min-height: 100vh;
        }
        
        /* Header */
        .cert-header {
            background: linear-gradient(135deg, #1a472a, #2b9b54);
            color: white;
            padding: 15px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .logo-area h1 {
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .logo-area p {
            font-size: 0.75rem;
            opacity: 0.9;
            margin-top: 3px;
        }
        
        .nav-buttons {
            display: flex;
            gap: 12px;
        }
        
        .nav-btn {
            padding: 8px 18px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .nav-btn.dashboard {
            background: #ffffff20;
            color: white;
            border: 1px solid white;
        }
        
        .nav-btn.dashboard:hover {
            background: white;
            color: #1a472a;
            transform: translateY(-2px);
        }
        
        /* Main Layout */
        .main-layout {
            display: flex;
            height: calc(100vh - 80px);
        }
        
        /* PDF Viewer Side */
        .pdf-side {
            flex: 2;
            background: #e9ecef;
            padding: 20px;
            display: flex;
            flex-direction: column;
        }
        
        .pdf-container {
            flex: 1;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .pdf-frame {
            width: 100%;
            height: 100%;
            border: none;
        }
        
        /* Info Side */
        .info-side {
            flex: 1;
            background: white;
            border-left: 1px solid #e2efe8;
            padding: 20px;
            overflow-y: auto;
        }
        
        .info-side h3 {
            color: #1a472a;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #43e97b;
        }
        
        .info-group {
            margin-bottom: 20px;
        }
        
        .info-group label {
            display: block;
            font-weight: 600;
            color: #1a472a;
            margin-bottom: 8px;
            font-size: 0.85rem;
        }
        
        .info-group .info-value {
            background: #f8faf8;
            padding: 10px 12px;
            border-radius: 8px;
            color: #2c3e50;
            font-size: 0.9rem;
            border: 1px solid #e2efe8;
            word-break: break-word;
        }
        
        .info-group .info-value i {
            margin-right: 8px;
            color: #43e97b;
            width: 20px;
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
        
        .status-badge.rejected {
            background: #f8d7da;
            color: #721c24;
        }
        
        /* ID Document Preview */
        .id-document-preview {
            background: #fef9e6;
            border: 1px solid #ffe0a3;
            border-radius: 12px;
            padding: 12px;
            text-align: center;
            margin-top: 5px;
        }
        
        .id-document-preview img {
            max-width: 100%;
            max-height: 200px;
            border-radius: 8px;
            border: 1px solid #ddd;
            object-fit: contain;
        }
        
        .id-document-preview .pdf-icon {
            font-size: 48px;
            color: #d9534f;
            margin: 10px 0;
        }
        
        .id-document-preview .id-missing {
            color: #b85c00;
            font-style: italic;
        }
        
        .doc-link-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #43e97b20;
            color: #1a472a;
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            text-decoration: none;
            margin-top: 8px;
            transition: all 0.2s;
        }
        
        .doc-link-btn:hover {
            background: #43e97b;
            color: white;
        }
        
        /* Custom Fields Styles */
        .custom-fields-grid {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .custom-field-item {
            padding: 8px 0;
            border-bottom: 1px solid #e2efe8;
        }
        
        .custom-field-item:last-child {
            border-bottom: none;
        }
        
        .custom-field-label {
            font-weight: 700;
            color: #1a472a;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        
        .custom-field-value {
            color: #2c3e50;
            font-size: 0.9rem;
        }
        
        /* Children Table Styles */
        .children-table-container {
            overflow-x: auto;
            margin-top: 5px;
        }
        
        .children-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        
        .children-table th {
            background: #e8f5e9;
            color: #1a472a;
            padding: 10px 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #43e97b;
        }
        
        .children-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #e2efe8;
        }
        
        .children-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Associative Fields */
        .assoc-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .assoc-item {
            padding: 4px 0;
        }
        
        .nested-fields {
            background: #f0f7f0;
            padding: 10px;
            border-radius: 8px;
            margin-top: 5px;
        }
        
        .nested-item {
            background: white;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 8px;
            border: 1px solid #d4e6d4;
        }
        
        .nested-item:last-child {
            margin-bottom: 0;
        }
        
        .nested-field {
            font-size: 0.85rem;
            line-height: 1.6;
        }
        
        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e2efe8;
        }
        
        .action-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .action-btn.print {
            background: #28a745;
            color: white;
        }
        
        .action-btn.print:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        
        .action-btn.download {
            background: #17a2b8;
            color: white;
        }
        
        .action-btn.download:hover {
            background: #138496;
            transform: translateY(-2px);
        }
        
        .action-btn.back {
            background: #6c757d;
            color: white;
        }
        
        .action-btn.back:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            background: white;
            border-radius: 16px;
            margin: 30px;
        }
        
        .empty-state i {
            font-size: 80px;
            color: #c8dfc8;
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            color: #1a472a;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: #8ba88e;
            margin-bottom: 25px;
        }
        
        .empty-state .btn {
            padding: 12px 25px;
            background: #43e97b;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        
        .request-list {
            margin-top: 30px;
            text-align: left;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .request-item {
            background: #f8faf8;
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid #e2efe8;
        }
        
        .request-item:hover {
            background: #e9f9ef;
            transform: translateX(5px);
        }
        
        .request-info strong {
            color: #1a472a;
            font-size: 0.9rem;
        }
        
        .request-info p {
            font-size: 0.75rem;
            color: #8ba88e;
            margin-top: 3px;
        }
        
        .view-badge {
            background: #43e97b;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
        }
        
        @media (max-width: 768px) {
            .cert-header {
                padding: 12px 15px;
            }
            .logo-area h1 {
                font-size: 1rem;
            }
            .main-layout {
                flex-direction: column;
                height: auto;
            }
            .pdf-side {
                min-height: 400px;
            }
            .info-side {
                border-left: none;
                border-top: 1px solid #e2efe8;
            }
            .action-buttons {
                flex-wrap: wrap;
            }
            .action-btn {
                flex: 1;
                justify-content: center;
            }
            .children-table th,
            .children-table td {
                padding: 6px 8px;
                font-size: 0.75rem;
            }
        }
        
        @media (max-width: 480px) {
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="cert-header">
        <div class="logo-area">
            <h1><i class="fas fa-certificate"></i> Certification Viewer</h1>
            <p>View barangay certifications & custom fields</p>
        </div>
        <div class="nav-buttons">
            <button class="action-btn print" onclick="printDocument()"><i class="fas fa-print"></i> Print</button>
            <button class="action-btn download" onclick="downloadDocument()"><i class="fas fa-download"></i> Download</button>
            <a href="<?php echo $dashboardUrl; ?>" class="nav-btn dashboard">
                <i class="fas fa-tachometer-alt"></i> <?php echo $dashboardLabel; ?>
            </a>
        </div>
    </div>
    
    <?php if ($filename && file_exists(__DIR__ . '/../generated_documents/' . $filename)): ?>
        <div class="main-layout">
            <!-- Left Side - PDF Viewer -->
            <div class="pdf-side">
                <div class="pdf-container">
                    <iframe id="pdfFrame" class="pdf-frame" src="../generated_documents/<?php echo urlencode($filename); ?>"></iframe>
                </div>
            </div>
            
            <!-- Right Side - Information Panel -->
            <div class="info-side">
                <h3><i class="fas fa-info-circle"></i> Document Details</h3>
                
                <div class="info-group">
                    <label><i class="fas fa-file-alt"></i> Document Type</label>
                    <div class="info-value"><i class="fas fa-file-pdf"></i> <?php echo htmlspecialchars($documentType); ?></div>
                </div>
                
                <div class="info-group">
                    <label><i class="fas fa-user"></i> Resident Name</label>
                    <div class="info-value"><i class="fas fa-user"></i> <?php echo htmlspecialchars($residentName); ?></div>
                </div>
                
                <div class="info-group">
                    <label><i class="fas fa-calendar"></i> Request Date</label>
                    <div class="info-value"><i class="fas fa-calendar-alt"></i> <?php echo date('F d, Y', strtotime($requestDate)); ?></div>
                </div>
                
                <div class="info-group">
                    <label><i class="fas fa-tag"></i> Status</label>
                    <div class="info-value">
                        <span class="status-badge <?php echo strtolower($status); ?>">
                           <i class="fas <?php echo $status == 'approved' ? 'fa-check-circle' : (($status == 'unclaimed' || $status == 'claimed') ? 'fa-check-double' : ($status == 'pending' ? 'fa-clock' : 'fa-times-circle')); ?>"></i>
                        </span>
                    </div>
                </div>

                <!-- Processed By Information -->
                <div class="info-group">
                    <label><i class="fas fa-user-check"></i> Processed By</label>
                    <div class="info-value">
                        <?php if ($status === 'approved' && isset($documentInfo['approved_by_name'])): ?>
                            <i class="fas fa-check-circle" style="color: #28a745;"></i>
                            <?php echo htmlspecialchars($documentInfo['approved_by_name']); ?>
                            <small style="display: block; color: #666;">on <?php echo date('F d, Y h:i A', strtotime($documentInfo['approved_at'])); ?></small>
                        <?php elseif ($status === 'claimed' && isset($documentInfo['completed_by_name'])): ?>
                            <i class="fas fa-check-double" style="color: #17a2b8;"></i>
                            <?php echo htmlspecialchars($documentInfo['completed_by_name']); ?>
                            <small style="display: block; color: #666;">on <?php echo date('F d, Y h:i A', strtotime($documentInfo['completed_at'])); ?></small>
                        <?php elseif ($status === 'rejected' && isset($documentInfo['rejected_by_name'])): ?>
                            <i class="fas fa-times-circle" style="color: #dc3545;"></i>
                            <?php echo htmlspecialchars($documentInfo['rejected_by_name']); ?>
                            <small style="display: block; color: #666;">on <?php echo date('F d, Y h:i A', strtotime($documentInfo['rejected_at'])); ?></small>
                        <?php else: ?>
                            <i class="fas fa-clock"></i> Not processed yet
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Custom Fields Section from document_requests_custom_data -->
                <?php if (!empty($customFieldsList)): ?>
                <div class="info-group">
                    <label><i class="fas fa-tasks"></i> Certificate Information</label>
                    <div class="info-value">
                        <div class="custom-fields-grid">
                            <?php foreach ($customFieldsList as $fieldName => $fieldValue): ?>
                                <div class="custom-field-item">
                                    <div class="custom-field-label">
                                        <i class="fas fa-tag"></i> <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $fieldName))); ?>
                                    </div>
                                    <div class="custom-field-value">
                                        <?php echo formatCustomFieldValue($fieldName, $fieldValue); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Resident Valid ID Document Viewer -->
                <div class="info-group">
                    <label><i class="fas fa-id-card"></i> Resident Valid ID</label>
                    <div class="info-value">
                        <?php if (!empty($idDocumentPath) && file_exists(__DIR__ . '/../' . $idDocumentPath)): ?>
                            <div class="id-document-preview">
                                <?php 
                                $fileExt = strtolower(pathinfo($idDocumentPath, PATHINFO_EXTENSION));
                                $fullIdPath = '../' . $idDocumentPath;
                                if (in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'])): ?>
                                    <img src="<?php echo $fullIdPath; ?>" alt="Resident ID Document" style="max-width:100%; max-height:180px; border-radius:8px;">
                                <?php elseif ($fileExt === 'pdf'): ?>
                                    <div class="pdf-icon"><i class="fas fa-file-pdf fa-3x"></i></div>
                                    <p>PDF Document</p>
                                <?php else: ?>
                                    <i class="fas fa-file-alt fa-3x"></i>
                                    <p>Document attached</p>
                                <?php endif; ?>
                                <div>
                                    <a href="<?php echo $fullIdPath; ?>" target="_blank" class="doc-link-btn">
                                        <i class="fas fa-external-link-alt"></i> View Full ID
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="id-document-preview id-missing">
                                <i class="fas fa-id-card fa-2x"></i>
                                <p>No valid ID document uploaded for this request.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="info-group">
                    <label><i class="fas fa-sticky-note"></i> Admin Notes</label>
                    <div class="info-value">
                        <?php if (!empty($admin_notes)): ?>
                            <i class="fas fa-comment"></i> <?php echo nl2br(htmlspecialchars($admin_notes)); ?>
                        <?php else: ?>
                            <i class="fas fa-comment-dots"></i> No notes added
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="info-group">
                    <label><i class="fas fa-align-left"></i> Purpose</label>
                    <div class="info-value"><?php echo nl2br(htmlspecialchars($purpose)); ?></div>
                </div>
                
                <div class="info-group">
                    <label><i class="fas fa-copy"></i> Quantity</label>
                    <div class="info-value"><i class="fas fa-files"></i> <?php echo $quantity; ?> copy/copies</div>
                </div>
                
                <div class="info-group">
                    <label><i class="fas fa-money-bill"></i> Fee</label>
                    <div class="info-value"><i class="fas fa-tag"></i> ₱<?php echo number_format($fee, 2); ?></div>
                </div>
                
               
            </div>
        </div>
        
        <script>
            const requestId = <?php echo $request_id; ?>;
            
            function printDocument() {
                const pdfFrame = document.getElementById('pdfFrame');
                if (pdfFrame && pdfFrame.contentWindow) {
                    pdfFrame.contentWindow.print();
                } else {
                    const iframeSrc = pdfFrame?.src;
                    if (iframeSrc) {
                        const win = window.open(iframeSrc, '_blank');
                        win?.print();
                    }
                }
            }
            
            function downloadDocument() {
                window.location.href = 'download_document.php?request_id=' + requestId;
            }
        </script>
        
    <?php else: ?>
        <!-- Empty State - No Document Selected / File missing -->
        <div class="empty-state">
            <i class="fas fa-certificate"></i>
            <h3>No Document Available</h3>
            <p>The requested document could not be found or hasn't been generated yet.</p>
            <a href="<?php echo $dashboardUrl; ?>" class="btn">
                <i class="fas fa-list-alt"></i> Go to <?php echo $dashboardLabel; ?>
            </a>
            
            <!-- Recent Approved Documents -->
            <?php
            $sql = "SELECT dr.id, dr.document_type, dr.request_date, dr.status,
                           r.first_name, r.last_name
                    FROM document_requests dr 
                    JOIN resident r ON dr.resident_id = r.id 
                    WHERE dr.status IN ('approved', 'unclaimed', 'claimed') 
                    AND dr.document_path IS NOT NULL
                    ORDER BY dr.processed_date DESC 
                    LIMIT 10";
            $result = mysqli_query($conn, $sql);
            
            if ($result && mysqli_num_rows($result) > 0):
            ?>
            <div class="request-list">
                <h4 style="margin-bottom: 12px; color: #1a472a; font-size: 0.95rem;">
                    <i class="fas fa-history"></i> Recently Generated Documents
                </h4>
                <?php while ($row = mysqli_fetch_assoc($result)): ?>
                <div class="request-item" onclick="window.location.href='certification.php?request_id=<?php echo $row['id']; ?>'">
                    <div class="request-info">
                        <strong><?php echo htmlspecialchars($row['document_type']); ?></strong>
                        <p>
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?> | 
                            <i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($row['request_date'])); ?>
                        </p>
                    </div>
                    <span class="view-badge">
                        <i class="fas fa-eye"></i> View
                    </span>
                </div>
                <?php endwhile; ?>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</body>
</html>