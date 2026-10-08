<?php
// admin/dashboard.php
session_start();

// ============ AJAX HANDLERS - MUST BE FIRST, BEFORE ANY OUTPUT ============

$isAjaxRequest = isset($_GET['dashboard_action']) || isset($_GET['doc_action']) || isset($_GET['action']) || isset($_POST['action']) || isset($_POST['doc_action']);

if ($isAjaxRequest) {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/email_helper.php';
    require_once __DIR__ . '/../includes/DocumentGenerator.php';
    
    header('Content-Type: application/json');
    
    // ============ DOCUMENT STATS AJAX ============
    if (isset($_GET['dashboard_action']) && $_GET['dashboard_action'] === 'get_document_stats') {
    $docSql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'unclaimed' THEN 1 ELSE 0 END) as unclaimed,
                SUM(CASE WHEN status = 'claimed' THEN 1 ELSE 0 END) as claimed,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
            FROM document_requests";
        $docResult = mysqli_query($conn, $docSql);
        $docStats = mysqli_fetch_assoc($docResult);
        echo json_encode(['success' => true, 'document' => $docStats]);
        exit;
    }
    
    // ============ RECENT DOCUMENTS AJAX ============
    if (isset($_GET['dashboard_action']) && $_GET['dashboard_action'] === 'get_recent_documents') {
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
    
    // ============ FILTER REQUESTS AJAX ============
if (isset($_GET['doc_action']) && $_GET['doc_action'] === 'filter_requests') {
    $status = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : null;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $perPage = 10;
    $offset = ($page - 1) * $perPage;
    
    $sql = "SELECT dr.*, r.first_name, r.last_name, r.email, r.phone_number 
            FROM document_requests dr 
            JOIN resident r ON dr.resident_id = r.id";
    
    $countSql = "SELECT COUNT(*) as total FROM document_requests dr JOIN resident r ON dr.resident_id = r.id";
    
    $where = [];
    
    if ($status && $status !== 'all') {
        $status = mysqli_real_escape_string($conn, $status);
        $where[] = "dr.status = '$status'";
    }
    
    if ($search !== '') {
        $searchEsc = mysqli_real_escape_string($conn, $search);
        $where[] = "(r.first_name LIKE '%$searchEsc%' 
                     OR r.last_name LIKE '%$searchEsc%' 
                     OR CONCAT(r.first_name, ' ', r.last_name) LIKE '%$searchEsc%'
                     OR r.email LIKE '%$searchEsc%' 
                     OR dr.document_type LIKE '%$searchEsc%'
                     OR dr.purpose LIKE '%$searchEsc%')";
    }
    
    if (!empty($where)) {
        $whereClause = " WHERE " . implode(" AND ", $where);
        $sql .= $whereClause;
        $countSql .= $whereClause;
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
    
    // ============ GET RESIDENT DETAILS ============
    if (isset($_GET['action']) && $_GET['action'] === 'get_resident' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $sql = "SELECT id, username, email, first_name, last_name, phone_number, address, 
                       date_of_birth, gender, is_verified, scanned_document, 
                       created_at, updated_at, last_login 
                FROM resident 
                WHERE id = ? AND is_active = 1";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $details = null;
        if ($result && mysqli_num_rows($result) > 0) {
            $details = mysqli_fetch_assoc($result);
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
    
    // ============ GET COUNTS ============
    if (isset($_GET['action']) && $_GET['action'] === 'get_counts') {
        $verified = 0;
        $unverified = 0;
        $total = 0;
        $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM resident WHERE is_verified = 1 AND is_active = 1");
        if ($result) $verified = mysqli_fetch_assoc($result)['count'];
        $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM resident WHERE is_verified = 0 AND is_active = 1");
        if ($result) $unverified = mysqli_fetch_assoc($result)['count'];
        $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM resident WHERE is_active = 1");
        if ($result) $total = mysqli_fetch_assoc($result)['count'];
        echo json_encode(['verified' => $verified, 'unverified' => $unverified, 'total' => $total]);
        exit;
    }
    
    // ============ VERIFY ACCOUNT ============
    if (isset($_POST['action']) && $_POST['action'] === 'verify_account') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id > 0) {
            $residentSql = "SELECT id, email, first_name, last_name FROM resident WHERE id = ? AND is_active = 1";
            $residentStmt = mysqli_prepare($conn, $residentSql);
            mysqli_stmt_bind_param($residentStmt, "i", $id);
            mysqli_stmt_execute($residentStmt);
            $residentResult = mysqli_stmt_get_result($residentStmt);
            $resident = mysqli_fetch_assoc($residentResult);
            
            if (!$resident) {
                echo json_encode(['success' => false, 'message' => 'Resident not found']);
                exit;
            }
            
            if ($resident['is_verified'] == 1) {
                echo json_encode(['success' => false, 'message' => 'Account is already verified']);
                exit;
            }
            
            $sql = "UPDATE resident SET is_verified = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND is_verified = 0";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "i", $id);
            
            if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
                $emailSent = sendVerificationConfirmationEmail(
                    $resident['email'], 
                    $resident['first_name'] . ' ' . $resident['last_name']
                );
                echo json_encode([
                    'success' => true, 
                    'email_sent' => $emailSent,
                    'message' => $resident['first_name'] . ' ' . $resident['last_name'] . ' has been verified successfully.',
                    'email_status' => $emailSent ? 'Email notification sent to ' . $resident['email'] : 'Account verified but email notification failed'
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to verify account.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid resident ID']);
        }
        exit;
    }
    
    // ============ GET REQUEST DETAILS ============
    if (isset($_POST['doc_action']) && $_POST['doc_action'] === 'get_request_details') {
        $request_id = intval($_POST['request_id']);
        $sql = "SELECT dr.*, r.first_name, r.last_name, r.email, r.phone_number, r.address
                FROM document_requests dr 
                JOIN resident r ON dr.resident_id = r.id 
                WHERE dr.id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($result && $row = mysqli_fetch_assoc($result)) {
            echo json_encode(['success' => true, 'request' => $row]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Request not found']);
        }
        exit;
    }
    
    // ============ UPDATE REQUEST STATUS (COMPLETED / REJECTED ONLY) ============
    // NOTE: Approval, document generation, and QR generation are handled
    // automatically on the resident portal. Admin only marks COMPLETED or REJECTED.
    if (isset($_POST['doc_action']) && $_POST['doc_action'] === 'update_request_status') {
        $request_id = intval($_POST['request_id']);
        $status = mysqli_real_escape_string($conn, $_POST['status']);
        $admin_notes = isset($_POST['admin_notes']) ? mysqli_real_escape_string($conn, $_POST['admin_notes']) : null;
        
        $admin_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
        $admin_name = '';
        if ($admin_id > 0) {
            $adminSql = "SELECT full_name, username FROM admin WHERE id = $admin_id";
            $adminResult = mysqli_query($conn, $adminSql);
            if ($adminResult && $row = mysqli_fetch_assoc($adminResult)) {
                $admin_name = $row['full_name'] ?: $row['username'];
            }
        }
        
        $additionalFields = "";
        if ($status === 'claimed') {
            $additionalFields = ", completed_by = $admin_id, completed_by_name = '$admin_name', completed_at = CURRENT_TIMESTAMP";
        } elseif ($status === 'rejected') {
            $additionalFields = ", rejected_by = $admin_id, rejected_by_name = '$admin_name', rejected_at = CURRENT_TIMESTAMP";
        }
        
        $sql = "UPDATE document_requests SET status = ?, admin_notes = ?, processed_date = CURRENT_TIMESTAMP $additionalFields WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ssi", $status, $admin_notes, $request_id);
        
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode([
                'success' => true, 
                'message' => 'Request status updated successfully',
                'processed_by' => $admin_name
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update request status']);
        }
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid AJAX action']);
    exit;
}

// ============ END OF AJAX HANDLERS - NORMAL PAGE LOAD BELOW ============

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/email_helper.php';
require_once __DIR__ . '/../includes/DocumentGenerator.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../sign_in.php');
    exit();
}

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// ============ FUNCTION DEFINITIONS ============

function getVerifiedResidents($conn) {
    $sql = "SELECT id, first_name, last_name, email, address, phone_number, is_verified, created_at 
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
                'phone_number' => $row['phone_number'] ?: 'Not provided',
                'address' => $row['address'] ?: 'Not specified',
                'status' => 'verified',
                'created_at' => $row['created_at']
            ];
        }
    }
    return $residents;
}

function getUnverifiedResidents($conn) {
    $sql = "SELECT id, first_name, last_name, email, address, phone_number, is_verified, created_at, scanned_document 
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
                'phone_number' => $row['phone_number'] ?: 'Not provided',
                'address' => $row['address'] ?: 'Not specified',
                'status' => 'unverified',
                'created_at' => $row['created_at'],
                'scanned_document' => $docPath
            ];
        }
    }
    return $residents;
}

function getDashboardCounts($conn) {
    $verified = 0;
    $unverified = 0;
    $total = 0;
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM resident WHERE is_verified = 1 AND is_active = 1");
    if ($result) $verified = mysqli_fetch_assoc($result)['count'];
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM resident WHERE is_verified = 0 AND is_active = 1");
    if ($result) $unverified = mysqli_fetch_assoc($result)['count'];
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM resident WHERE is_active = 1");
    if ($result) $total = mysqli_fetch_assoc($result)['count'];
    return ['verified' => $verified, 'unverified' => $unverified, 'total' => $total];
}

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

function getDocumentRequests($conn, $status = null, $page = 1, $perPage = 10) {
    $offset = ($page - 1) * $perPage;
    $status = $status && $status !== 'all' ? mysqli_real_escape_string($conn, $status) : null;
    $countSql = "SELECT COUNT(*) as total FROM document_requests dr JOIN resident r ON dr.resident_id = r.id";
    if ($status) $countSql .= " WHERE dr.status = '$status'";
    $countResult = mysqli_query($conn, $countSql);
    $totalRecords = $countResult ? mysqli_fetch_assoc($countResult)['total'] : 0;
    $totalPages = ceil($totalRecords / $perPage);
    $sql = "SELECT dr.*, r.first_name, r.last_name, r.email, r.phone_number, r.address
            FROM document_requests dr 
            JOIN resident r ON dr.resident_id = r.id";
    if ($status) $sql .= " WHERE dr.status = '$status'";
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

function getPendingCount($conn) {
    $sql = "SELECT COUNT(*) as count FROM document_requests WHERE status = 'pending'";
    $result = mysqli_query($conn, $sql);
    return $result ? mysqli_fetch_assoc($result)['count'] : 0;
}

// ============ GET INITIAL DATA ============
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
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
</head>
<body>
<button class="menu-toggle" id="menuToggle">
  <i class="fas fa-bars"></i>
</button>

<div class="sidebar-overlay hide" id="sidebarOverlay"></div>

<div class="dashboard-container">
  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <div class="brand">
         <div class="logo-container"><img src="../logo.jpg" alt="Logo"></div>
        <h2>BRITE</h2>
      </div>
      <div class="sidebar-sub">San Bartolome, Sto Tomas, Pampanga</div>
    </div>
    <div class="nav-menu">
      <div class="nav-item standalone" data-view="dashboard">
        <i class="fas fa-tachometer-alt"></i>
        <span>Dashboard</span>
      </div>

<div class="nav-item standalone" data-view="household">
    <i class="fas fa-home"></i>
    <span>Household</span>
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
    <li><a data-subview="manage_officials" class="sub-option"><i class="fas fa-user-tie"></i> Barangay Officials</a></li>
    <li><a data-subview="manage_lupon" class="sub-option"><i class="fas fa-gavel"></i> Lupon Tagapamayapa</a></li>
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

    <div class="dashboard-body" id="dashboardBody">
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

<!-- NOTE: Approval Modal removed - auto-approved on resident portal -->

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

  </main>
</div>

<!-- External JavaScript -->
<script src="equipment.js"></script>
<script src="admin_management.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="emergency_checker.js"></script>

<script>
// Initialize emergency checker when page is fully loaded
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM ready - initializing emergency checker');
    if (typeof initEmergencyChecker === 'function') {
        initEmergencyChecker();
    } else {
        console.error('initEmergencyChecker not found - check if emergency_checker.js loaded');
    }
    if (!document.getElementById('emergencyListContainer')) {
        const emergencySection = document.querySelector('.emergency-section');
        if (emergencySection) {
            const container = document.createElement('div');
            container.id = 'emergencyListContainer';
            container.className = 'activity-list';
            container.style.maxHeight = '400px';
            container.style.overflowY = 'auto';
            emergencySection.querySelector('.activity-card').appendChild(container);
        }
    }
});

function refreshEmergencyList() {
    if (typeof loadExistingEmergencies === 'function') {
        loadExistingEmergencies();
    } else {
        location.reload();
    }
}

function renderEmergencyView() {
    return `
        <div class="content-card">
            <div class="section-title">
                <i class="fas fa-bell" style="color: #dc3545;"></i> Emergency Alerts
            </div>
            <div class="section-sub">
                Live emergency reports from the hotline system
            </div>
            <div id="emergencyListContainer" class="activity-list" style="max-height: 600px; overflow-y: auto;">
                <div class="loading-spinner-mini"><i class="fas fa-spinner fa-spin"></i> Loading emergencies...</div>
            </div>
        </div>
    `;
}

setTimeout(addEmergencyNavItem, 1000);
</script>

<script>
// ============ OFFICIALS & STAFF MANAGEMENT ============

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
        
        if (view === 'manage_officials') {
            document.getElementById('dashboardBody').innerHTML = renderAdminManagement();
            await loadAdmins(1);
            closeCertification();
        } else if (view === 'manage_lupon') {
            document.getElementById('dashboardBody').innerHTML = renderLuponManagement();
            await loadLuponMembers(1);
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
let currentCertificationRequestId = null;
let currentCertificationFilename = null;
let currentCertificationDocType = null;
let currentCertificationResident = null;
let currentQRCodePath = null;

// Request list variables
let currentFilterStatus = 'all';
let currentPage = 1;
let totalPages = 1;
let currentRequestsData = [];
let currentSearchQuery = '';

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
    setTimeout(() => { iframe.src = ''; }, 300);
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

async function loadFilteredRequests(status, page = 1, search = null) {
    currentFilterStatus = status;
    currentPage = page;
    // If search is null, use the stored global search value (so pagination preserves it)
    if (search === null) {
        search = currentSearchQuery;
    } else {
        currentSearchQuery = search;
    }
    try {
        let url = `${window.location.href}?doc_action=filter_requests&status=${status}&page=${page}`;
        if (search && search.trim() !== '') {
            url += `&search=${encodeURIComponent(search.trim())}`;
        }
        const response = await fetch(url);
        const data = await response.json();
               if (data.success) {
            currentRequestsData = data.data;
            totalPages = data.totalPages;
            // Only refresh the results area, keep the search input + filter bar intact
            if (document.getElementById('requestResultsContainer')) {
                renderRequestResultsOnly();
            } else {
                const dashboardBody = document.getElementById('dashboardBody');
                if (dashboardBody) dashboardBody.innerHTML = renderRequestList();
            }
        }
    } catch (error) { showErrorModal('Failed to load document requests.', 'Error'); }
}

// Search handler (debounced)
let searchDebounceTimer = null;
function onSearchInput(value) {
    clearTimeout(searchDebounceTimer);
    // Persist input value so re-render doesn't lose it
    currentSearchQuery = value;
    searchDebounceTimer = setTimeout(() => {
        loadFilteredRequests(currentFilterStatus, 1, value);
    }, 400);
}

// Clear search
function clearRequestSearch() {
    currentSearchQuery = '';
    const input = document.getElementById('requestSearchInput');
    if (input) input.value = '';
    loadFilteredRequests(currentFilterStatus, 1, '');
}
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
        case 'unclaimed': return 'approved';
        case 'claimed': return 'completed';
        case 'rejected': return 'rejected';
        default: return 'pending';
    }
};
    
       const getStatusText = (status) => {
        switch(status) {
            case 'pending': return 'Pending';
            case 'approved': return 'Approved';
            case 'unclaimed': return 'Unclaimed';
            case 'claimed': return 'Claimed';
            case 'rejected': return 'Rejected';
            default: return status;
        }
    };
    
    // Filter bar HTML (reused for empty state and list)
    const filterBarHtml = `
        <div class="filter-bar" style="display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
            <button class="filter-chip ${currentFilterStatus === 'all' ? 'active' : ''}" onclick="loadFilteredRequests('all', 1)">All Requests</button>
            <button class="filter-chip ${currentFilterStatus === 'approved' ? 'active' : ''}" onclick="loadFilteredRequests('approved', 1)">Approved</button>
            <button class="filter-chip ${currentFilterStatus === 'unclaimed' ? 'active' : ''}" onclick="loadFilteredRequests('unclaimed', 1)">Unclaimed</button>
            <button class="filter-chip ${currentFilterStatus === 'claimed' ? 'active' : ''}" onclick="loadFilteredRequests('claimed', 1)">Claimed</button>
            <button class="filter-chip ${currentFilterStatus === 'rejected' ? 'active' : ''}" onclick="loadFilteredRequests('rejected', 1)">Rejected</button>
            
            <div class="request-search-wrapper" style="position:relative; flex:1; min-width:220px; max-width:380px; margin-left:auto;">
                <i class="fas fa-search" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#8ba88e; pointer-events:none;"></i>
                <input 
                    type="text" 
                    id="requestSearchInput"
                    class="request-search-input"
                    placeholder="Search by name, email, document type, or purpose..."
                    value="${escapeHtml(currentSearchQuery)}"
                    oninput="onSearchInput(this.value)"
                    style="width:100%; padding:9px 36px 9px 38px; border:1px solid #e2efe8; border-radius:40px; font-size:13px; outline:none; transition:all 0.2s; background:#f8faf8;"
                />
                ${currentSearchQuery ? `
                    <button 
                        onclick="clearRequestSearch()" 
                        title="Clear search"
                        style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:#8ba88e; cursor:pointer; font-size:14px; padding:4px 8px; border-radius:50%; transition:all 0.2s;"
                        onmouseover="this.style.background='#e2efe8'; this.style.color='#1a472a';"
                        onmouseout="this.style.background='none'; this.style.color='#8ba88e';"
                    >
                        <i class="fas fa-times"></i>
                    </button>
                ` : ''}
            </div>
        </div>
    `;
    
    // Empty state
    if (requestData.length === 0) {
        let emptyMessage = `No ${currentFilterStatus === 'all' ? '' : currentFilterStatus + ' '}document requests found.`;
        if (currentSearchQuery) {
            emptyMessage = `No requests match "${escapeHtml(currentSearchQuery)}". Try a different search term.`;
        }
             return `
            <div class="content-card">
                <div class="section-title"><i class="fas fa-list-alt"></i> Document Requests</div>
                <div class="section-sub">Manage and process document requests from residents</div>
                ${filterBarHtml}
                <div id="requestResultsContainer">
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>${emptyMessage}</p>
                    </div>
                </div>
            </div>
        `;
    }
    
    const cardsHtml = requestData.map(req => `
        <div class="data-card" onclick="viewRequestDetails(${req.id})" data-id="${req.id}">
            <div class="card-header-gradient ${getStatusClass(req.status)}">
                <div class="card-title-large">
                    <i class="fas fa-file-alt"></i>
                    <span>${escapeHtml(req.document_type)}</span>
                </div>
                <div class="status-chip">
                   <i class="fas ${req.status === 'pending' ? 'fa-clock' : (req.status === 'approved' || req.status === 'unclaimed') ? 'fa-check-circle' : req.status === 'claimed' ? 'fa-check-double' : 'fa-times-circle'}"></i>
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
    
    // Result count summary
    const resultSummary = currentSearchQuery ? `
        <div style="margin-bottom:14px; font-size:13px; color:#5f7f6e;">
            <i class="fas fa-search"></i> Found <strong>${requestData.length}</strong> matching request(s) for "<strong>${escapeHtml(currentSearchQuery)}</strong>"
        </div>
    ` : '';
    
       return `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-list-alt"></i> Document Requests</div>
            <div class="section-sub">Click on any card to view details and process requests</div>
            ${filterBarHtml}
            <div id="requestResultsContainer">
                ${resultSummary}
                <div class="cards-grid" id="requestsCardsGrid">
                    ${cardsHtml}
                </div>
                ${generatePaginationHtml()}
            </div>
        </div>
    `;
}

// Renders ONLY the results area (cards + pagination) without touching the search input
function renderRequestResultsOnly() {
    const container = document.getElementById('requestResultsContainer');
    if (!container) return;
    
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
        let emptyMessage = `No ${currentFilterStatus === 'all' ? '' : currentFilterStatus + ' '}document requests found.`;
        if (currentSearchQuery) {
            emptyMessage = `No requests match "${escapeHtml(currentSearchQuery)}". Try a different search term.`;
        }
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>${emptyMessage}</p>
            </div>
        `;
        return;
    }
    
    const cardsHtml = requestData.map(req => `
        <div class="data-card" onclick="viewRequestDetails(${req.id})" data-id="${req.id}">
            <div class="card-header-gradient ${getStatusClass(req.status)}">
                <div class="card-title-large">
                    <i class="fas fa-file-alt"></i>
                    <span>${escapeHtml(req.document_type)}</span>
                </div>
                <div class="status-chip">
                   <i class="fas ${req.status === 'pending' ? 'fa-clock' : (req.status === 'approved' || req.status === 'unclaimed') ? 'fa-check-circle' : req.status === 'claimed' ? 'fa-check-double' : 'fa-times-circle'}"></i>
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
    
    const resultSummary = currentSearchQuery ? `
        <div style="margin-bottom:14px; font-size:13px; color:#5f7f6e;">
            <i class="fas fa-search"></i> Found <strong>${requestData.length}</strong> matching request(s) for "<strong>${escapeHtml(currentSearchQuery)}</strong>"
        </div>
    ` : '';
    
    container.innerHTML = `
        ${resultSummary}
        <div class="cards-grid" id="requestsCardsGrid">
            ${cardsHtml}
        </div>
        ${generatePaginationHtml()}
    `;
}

function renderVerifiedAccounts() {
    if (verifiedAccountsData.length === 0) {
        return `<div class="content-card"><div class="section-title"><i class="fas fa-check-circle"></i> Verified Accounts</div><div class="empty-state"><i class="fas fa-users fa-3x"></i><p>No verified accounts yet.</p></div></div>`;
    }
    
    const cardsHtml = verifiedAccountsData.map(acc => `
        <div class="data-card" onclick="showResidentDetails(${acc.id}, '${escapeHtml(acc.name)}')" data-id="${acc.id}">
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
                        <div class="info-value-card">${escapeHtml(acc.phone_number)}</div>
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
    
    return `<div class="content-card"><div class="section-title"><i class="fas fa-check-circle"></i> Verified Accounts</div><div class="section-sub">Click on any card to view full resident details</div><div class="cards-grid" id="verifiedCardsGrid">${cardsHtml}</div></div>`;
}

function renderUnverifiedAccounts() {
    if (unverifiedAccountsData.length === 0) {
        return `<div class="content-card"><div class="section-title"><i class="fas fa-clock"></i> Not Verified Accounts</div><div class="empty-state"><i class="fas fa-check-double fa-3x"></i><p>No pending verifications.</p></div></div>`;
    }
    
    const cardsHtml = unverifiedAccountsData.map(acc => `
        <div class="data-card" onclick="showResidentDetails(${acc.id}, '${escapeHtml(acc.name)}')" data-id="${acc.id}">
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
                        <div class="info-value-card">${escapeHtml(acc.phone_number)}</div>
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
    
    return `<div class="content-card"><div class="section-title"><i class="fas fa-clock"></i> Not Verified Accounts</div><div class="section-sub">Click on any card to verify the account or view full details</div><div class="cards-grid" id="unverifiedCardsGrid">${cardsHtml}</div></div>`;
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
    
    return `<div class="content-card"><div class="section-title"><i class="fas fa-cog"></i> Manage Documents</div><div class="section-sub">Configure document types, fees, and availability</div><div style="margin-bottom:20px;"></div><div class="cards-grid">${cardsHtml}</div></div>`;
}

function renderDashboardDefault() {
    return `
    <div class="modern-dashboard">
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
        
        <div class="stats-grid-modern">
            <div class="stat-card-modern">
                <div class="stat-icon residents"><i class="fas fa-users"></i></div>
                <div class="stat-info">
                    <h3>${dashboardCounts.total}</h3>
                    <p>Total Residents</p>
                    <span class="stat-trend"><i class="fas fa-user-plus"></i> ${dashboardCounts.unverified} pending</span>
                </div>
            </div>
            
            <div class="stat-card-modern">
                <div class="stat-icon verified"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info">
                    <h3>${dashboardCounts.verified}</h3>
                    <p>Verified Accounts</p>
                    <span class="stat-trend positive"><i class="fas fa-chart-line"></i> ${dashboardCounts.total > 0 ? Math.round((dashboardCounts.verified/dashboardCounts.total)*100) : 0}% verified</span>
                </div>
            </div>
            
            <div class="stat-card-modern">
                <div class="stat-icon documents"><i class="fas fa-file-alt"></i></div>
                <div class="stat-info">
                    <h3 id="docTotalCount">-</h3>
                    <p>Document Requests</p>
                    <span class="stat-trend" id="docPendingBadge"><i class="fas fa-clock"></i> Loading...</span>
                </div>
            </div>
            
            <div class="stat-card-modern">
                <div class="stat-icon equipment"><i class="fas fa-tools"></i></div>
                <div class="stat-info">
                    <h3 id="equipTotalItems">-</h3>
                    <p>Equipment Items</p>
                    <span class="stat-trend" id="equipAvailableBadge"><i class="fas fa-check-circle"></i> Loading...</span>
                </div>
            </div>
        </div>
        
        <div class="stats-row-modern">
            <div class="stat-card-secondary">
                <div class="stat-header"><i class="fas fa-file-signature"></i><span>Document Status</span></div>
                <div class="stat-values">
                    <div class="value-item"><span class="value-label">Pending</span><span class="value-number" id="docPendingCount">-</span></div>
<div class="value-item"><span class="value-label">Approved</span><span class="value-number" id="docApprovedCount">-</span></div>
<div class="value-item"><span class="value-label">Unclaimed</span><span class="value-number" id="docUnclaimedCount">-</span></div>
<div class="value-item"><span class="value-label">Claimed</span><span class="value-number" id="docClaimedCount">-</span></div>
<div class="value-item"><span class="value-label">Rejected</span><span class="value-number" id="docRejectedCount">-</span></div>
                </div>
            </div>
            
            <div class="stat-card-secondary">
                <div class="stat-header"><i class="fas fa-tools"></i><span>Equipment Status</span></div>
                <div class="stat-values">
                    <div class="value-item"><span class="value-label">Available</span><span class="value-number success" id="equipAvailableCount">-</span></div>
                    <div class="value-item"><span class="value-label">Borrowed</span><span class="value-number warning" id="equipBorrowedCount">-</span></div>
                    <div class="value-item"><span class="value-label">Maintenance</span><span class="value-number danger" id="equipMaintenanceCount">-</span></div>
                    <div class="value-item"><span class="value-label">Pending Bookings</span><span class="value-number info" id="equipPendingBookings">-</span></div>
                </div>
            </div>
        </div>
        
        <div class="recent-activity-grid">
            <div class="activity-card">
                <div class="activity-header">
                    <h4><i class="fas fa-file-alt"></i> Recent Document Requests</h4>
                    <button class="view-all-btn" onclick="goToDocumentRequests()">View All <i class="fas fa-arrow-right"></i></button>
                </div>
                <div class="activity-list" id="recentDocumentsList">
                    <div class="loading-spinner-mini"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                </div>
            </div>
            
            <div class="activity-card">
                <div class="activity-header">
                    <h4><i class="fas fa-calendar-alt"></i> Recent Booking Requests</h4>
                    <button class="view-all-btn" onclick="goToEquipmentBookings()">View All <i class="fas fa-arrow-right"></i></button>
                </div>
                <div class="activity-list" id="recentBookingsList">
                    <div class="loading-spinner-mini"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                </div>
            </div>
        </div>
        
        <div class="quick-actions">
            <h4><i class="fas fa-bolt"></i> Quick Actions</h4>
            <div class="action-buttons">
                <button class="quick-action-btn" onclick="goToUnverifiedAccounts()"><i class="fas fa-user-check"></i> Verify Residents</button>
                <button class="quick-action-btn" onclick="goToDocumentRequests()"><i class="fas fa-file-signature"></i> Process Documents</button>
                <button class="quick-action-btn" onclick="goToEquipmentList()"><i class="fas fa-plus-circle"></i> Add Equipment</button>
                <button class="quick-action-btn" onclick="goToEquipmentBookings()"><i class="fas fa-calendar-check"></i> Manage Bookings</button>
            </div>
        </div>
    </div>`;
}

function goToUnverifiedAccounts() {
    const accountsParent = document.getElementById('accountsParent');
    if (accountsParent) {
        const submenu = document.getElementById('accountsSubmenu');
        if (submenu && !submenu.classList.contains('open')) {
            const toggleIcon = accountsParent.querySelector('.toggle-icon');
            submenu.classList.add('open');
            if (toggleIcon) toggleIcon.style.transform = 'rotate(180deg)';
        }
        const unverifiedOption = document.querySelector('#accountsSubmenu .sub-option[data-subview="unverified"]');
        if (unverifiedOption) unverifiedOption.click();
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
        if (requestListOption) requestListOption.click();
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
        if (equipmentListOption) equipmentListOption.click();
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
        if (bookingsOption) bookingsOption.click();
    }
}

async function loadDocumentStats() {
    try {
        const response = await fetch(`${window.location.href}?dashboard_action=get_document_stats`);
        const data = await response.json();
        if (data.success && data.document) {
            document.getElementById('docTotalCount').innerText = data.document.total || 0;
            document.getElementById('docPendingCount').innerText = data.document.pending || 0;
            document.getElementById('docApprovedCount').innerText = data.document.approved || 0;
            document.getElementById('docUnclaimedCount').innerText = data.document.unclaimed || 0;
document.getElementById('docClaimedCount').innerText = data.document.claimed || 0;
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

// Request action functions (Approve removed - auto-approved on resident portal)
function showRejectPrompt(requestId) {
    showPromptModal('Reject Request', 'Please enter a reason for rejection...', (reason) => { rejectRequest(requestId, reason); });
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
    showConfirmationModal('Mark as claimed? The resident has already received the document.', 'Confirm Claimed', async () => {
        const formData = new FormData();
        formData.append('doc_action', 'update_request_status');
        formData.append('request_id', requestId);
        formData.append('status', 'claimed');
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
        case 'unclaimed': return '<span class="badge-verified" style="background:#cce5ff;color:#004085;"><i class="fas fa-box"></i> Unclaimed</span>';
        case 'claimed': return '<span class="status-verified" style="background:#d4edda;color:#155724;padding:4px 12px;border-radius:40px;"><i class="fas fa-check-double"></i> Claimed</span>';
        case 'rejected': return '<span class="status-unverified" style="background:#f8d7da;color:#721c24;padding:4px 12px;border-radius:40px;"><i class="fas fa-times-circle"></i> Rejected</span>';
        default: return '<span class="badge-unverified">' + status + '</span>';
    }
};
            
            // NOTE: Approve button REMOVED. Auto-approved on resident portal.
            let actionButtonsHtml = '';
            if (req.status === 'pending') {
                actionButtonsHtml = `
                    <div class="modal-action-buttons">
                        <button class="btn-close" onclick="showRejectFromModal(${req.id})" style="background: #dc3545;"><i class="fas fa-times-circle"></i> Reject Request</button>
                    </div>
                `;
            } else if (req.status === 'approved' || req.status === 'unclaimed' || req.status === 'claimed') {
    actionButtonsHtml = `
        <div class="modal-action-buttons">
            <button class="btn-verify" onclick="openCertificationPage(${req.id})" style="background: #17a2b8;"><i class="fas fa-certificate"></i> View Certification</button>
            <button class="btn-verify" onclick="printRequestDocument(${req.id})" style="background: #28a745;"><i class="fas fa-print"></i> Print</button>
            <button class="btn-verify" onclick="downloadRequestDocument(${req.id})" style="background: #6c757d;"><i class="fas fa-download"></i> Download</button>
            ${(req.status === 'approved' || req.status === 'unclaimed') ? `<button class="btn-verify" onclick="markAsCompleted(${req.id}); closeRequestModal();" style="background: #17a2b8;"><i class="fas fa-check-double"></i> Mark as Claimed</button>` : ''}
        </div>
    `;
}
            
            modalBody.innerHTML = `
                <div class="detail-section"><h4>Resident Information</h4>
                    <div class="detail-row"><div class="detail-label">Full Name:</div><div class="detail-value"><strong>${escapeHtml(req.first_name)} ${escapeHtml(req.last_name)}</strong></div></div>
                    <div class="detail-row"><div class="detail-label">Email:</div><div class="detail-value">${escapeHtml(req.email)}</div></div>
                    <div class="detail-row"><div class="detail-label">Phone:</div><div class="detail-value">${escapeHtml(req.phone_number || '-')}</div></div>
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
                <div class="detail-row"><div class="detail-label">Phone:</div><div class="detail-value">${escapeHtml(resident.phone_number || 'Not provided')}</div></div>
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
    currentFilterStatus = 'all'; 
    currentPage = 1;
    currentSearchQuery = '';
    await loadFilteredRequests('all', 1);
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
            setTimeout(() => {
                displayCurrentDate();
                loadDocumentStats();
                loadDashboardEquipmentStats();
                loadRecentDocuments();
                loadRecentBookings();
            }, 100);
        } else if (view === 'reports') {
            document.getElementById('dashboardBody').innerHTML = renderReports();
            closeCertification();
        } else if (view === 'settings') {
            document.getElementById('dashboardBody').innerHTML = renderSettings();
            closeCertification();
        } else if (view === 'emergencies') {
            document.getElementById('dashboardBody').innerHTML = renderEmergencyView();
            if (typeof loadExistingEmergencies === 'function') {
                setTimeout(loadExistingEmergencies, 100);
            }
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

// Close modals on outside click (approvalModal removed)
window.onclick = function(event) {
    if (event.target === document.getElementById('residentModal')) closeModal();
    if (event.target === document.getElementById('verifyModal')) closeVerifyModal();
    if (event.target === document.getElementById('documentModal')) closeDocumentModal();
    if (event.target === document.getElementById('requestModal')) closeRequestModal();
};

// ============ REAL-TIME ADMIN NOTIFICATION SYSTEM ============

let adminNotifications = [];
let adminNotificationCheckInterval = null;
let adminRealtimeCheckInterval = null;
let adminBellAnimating = false;
let lastNotificationTimestamp = 0;
let isNotificationDropdownOpen = false;

function initAdminNotificationBell() {
    const topHeader = document.querySelector('.top-header');
    if (!topHeader) return;
    if (document.querySelector('.admin-notification-bell-container')) return;
    const profileArea = document.querySelector('.top-header .profile-area');
    if (!profileArea) return;
    
    let flexWrapper = topHeader.querySelector('.header-right-wrapper');
    if (!flexWrapper) {
        flexWrapper = document.createElement('div');
        flexWrapper.className = 'header-right-wrapper';
        flexWrapper.style.cssText = 'display: flex; align-items: center; gap: 20px;';
        if (profileArea && profileArea.parentNode === topHeader) {
            profileArea.parentNode.insertBefore(flexWrapper, profileArea);
            flexWrapper.appendChild(profileArea);
        }
    }
    
    const bellHtml = `
        <div class="admin-notification-bell-container" id="adminNotificationBellContainer">
            <div class="admin-notification-bell" id="adminNotificationBell">
                <i class="fas fa-bell"></i>
                <span class="admin-notification-badge" id="adminNotificationBadge" style="display: none;">0</span>
            </div>
            <div class="admin-notifications-dropdown" id="adminNotificationsDropdown">
                <div class="admin-notifications-header">
                    <h4><i class="fas fa-bell"></i> Notifications</h4>
                    <button class="admin-mark-all-read" id="adminMarkAllReadBtn">Mark all as read</button>
                </div>
                <div class="admin-notifications-list" id="adminNotificationsList">
                    <div class="admin-empty-notifications">
                        <i class="fas fa-bell-slash"></i>
                        <p>No notifications</p>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    flexWrapper.insertAdjacentHTML('afterbegin', bellHtml);
    
    const bellContainer = document.getElementById('adminNotificationBellContainer');
    const dropdown = document.getElementById('adminNotificationsDropdown');
    const markAllBtn = document.getElementById('adminMarkAllReadBtn');
    
    if (bellContainer) {
        bellContainer.addEventListener('click', (e) => {
            e.stopPropagation();
            isNotificationDropdownOpen = !dropdown.classList.contains('show');
            dropdown.classList.toggle('show');
            if (dropdown.classList.contains('show')) {
                renderAdminNotificationsList();
            }
        });
    }
    
    document.addEventListener('click', () => {
        if (dropdown) {
            dropdown.classList.remove('show');
            isNotificationDropdownOpen = false;
        }
    });
    
    if (markAllBtn) {
        markAllBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            markAllAdminNotificationsRead();
        });
    }
}

function updatePendingCountBadges(counts) {
    const docFilterBtn = document.querySelector('.filter-chip[onclick*="pending"]');
    if (docFilterBtn && counts.pending_documents > 0) {
        let badge = docFilterBtn.querySelector('.pending-count');
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'pending-count';
            docFilterBtn.appendChild(badge);
        }
        badge.textContent = counts.pending_documents;
        badge.style.display = 'inline-flex';
    } else if (docFilterBtn) {
        const badge = docFilterBtn.querySelector('.pending-count');
        if (badge) badge.style.display = 'none';
    }
    
    const bookingFilterBtn = document.querySelector('#equipmentSubmenu .sub-option[data-subview="equipment_bookings"]');
    if (bookingFilterBtn && counts.pending_bookings > 0) {
        let badge = bookingFilterBtn.querySelector('.pending-count');
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'pending-count';
            bookingFilterBtn.appendChild(badge);
        }
        badge.textContent = counts.pending_bookings;
        badge.style.display = 'inline-flex';
    } else if (bookingFilterBtn) {
        const badge = bookingFilterBtn.querySelector('.pending-count');
        if (badge) badge.style.display = 'none';
    }
    
    const unverifiedBtn = document.querySelector('#accountsSubmenu .sub-option[data-subview="unverified"]');
    if (unverifiedBtn && counts.unverified_residents > 0) {
        let badge = unverifiedBtn.querySelector('.pending-count');
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'pending-count';
            unverifiedBtn.appendChild(badge);
        }
        badge.textContent = counts.unverified_residents;
        badge.style.display = 'inline-flex';
    } else if (unverifiedBtn) {
        const badge = unverifiedBtn.querySelector('.pending-count');
        if (badge) badge.style.display = 'none';
    }
}

async function fetchAdminNotifications() {
    try {
        const response = await fetch('admin_notifications.php?action=get_notifications');
        const data = await response.json();
        
        if (data.success) {
            let readNotifications = [];
            try {
                const stored = localStorage.getItem('admin_read_notifications');
                if (stored) {
                    readNotifications = JSON.parse(stored);
                }
            } catch(e) {}
            
            let sessionRead = [];
            try {
                const sessionStored = sessionStorage.getItem('admin_read_notifications');
                if (sessionStored) {
                    sessionRead = JSON.parse(sessionStored);
                    readNotifications = [...new Set([...readNotifications, ...sessionRead])];
                }
            } catch(e) {}
            
            data.notifications.forEach(notif => {
                if (readNotifications.includes(notif.id)) {
                    notif.is_read = true;
                } else {
                    notif.is_read = false;
                }
            });
            
            const oldUnreadCount = adminNotifications.filter(n => !n.is_read).length;
            adminNotifications = data.notifications;
            const newUnreadCount = adminNotifications.filter(n => !n.is_read).length;
            
            updateAdminNotificationBell(newUnreadCount);
            
            if (newUnreadCount > oldUnreadCount && newUnreadCount > 0) {
                animateAdminBell();
            }
            
            if (data.counts) {
                updatePendingCountBadges(data.counts);
            }
            
            renderAdminNotificationsList();
        }
    } catch (error) {
        console.error('Error fetching admin notifications:', error);
    }
}

async function checkRealtimeNotifications() {
    try {
        const response = await fetch(`admin_notifications.php?action=get_realtime_updates&last_timestamp=${lastNotificationTimestamp}`);
        const data = await response.json();
        
        if (data.success && data.has_updates && data.new_items.length > 0) {
            const newCount = data.new_items.length;
            const latestTimestamp = Math.max(...data.new_items.map(i => i.timestamp));
            if (latestTimestamp > lastNotificationTimestamp) {
                lastNotificationTimestamp = latestTimestamp;
            }
            
            animateAdminBell();
            
            const toShow = data.new_items.slice(0, 3);
            toShow.forEach(item => {
                showRealtimeToast(item.title, item.message);
            });
            
            if (newCount > 3) {
                showRealtimeToast(`${newCount - 3} more notifications`, 'Click bell to view all');
            }
            
            await fetchAdminNotifications();
            refreshPendingCounts();
        }
    } catch (error) {
        console.error('Error checking realtime updates:', error);
    }
}

function clearAllReadStatus() {
    localStorage.removeItem('admin_read_notifications');
    sessionStorage.removeItem('admin_read_notifications');
    adminNotifications.forEach(n => n.is_read = false);
    updateAdminNotificationBell(adminNotifications.length);
    renderAdminNotificationsList();
    showSimpleToast('Read status cleared');
}

window.clearAllReadStatus = clearAllReadStatus;

async function refreshPendingCounts() {
    try {
        const response = await fetch('admin_notifications.php?action=get_unread_count');
        const data = await response.json();
        
        if (data.success) {
            const docFilterBtn = document.querySelector('.filter-chip[onclick*="pending"]');
            if (docFilterBtn) {
                let badge = docFilterBtn.querySelector('.pending-count');
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'pending-count';
                    docFilterBtn.appendChild(badge);
                }
                if (data.details.documents > 0) {
                    badge.textContent = data.details.documents;
                    badge.style.display = 'inline-flex';
                } else {
                    badge.style.display = 'none';
                }
            }
            
            const bookingFilterBtn = document.querySelector('#equipmentSubmenu .sub-option[data-subview="equipment_bookings"]');
            if (bookingFilterBtn) {
                let badge = bookingFilterBtn.querySelector('.pending-count');
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'pending-count';
                    bookingFilterBtn.appendChild(badge);
                }
                if (data.details.bookings > 0) {
                    badge.textContent = data.details.bookings;
                    badge.style.display = 'inline-flex';
                } else {
                    badge.style.display = 'none';
                }
            }
            
            const unverifiedBtn = document.querySelector('#accountsSubmenu .sub-option[data-subview="unverified"]');
            if (unverifiedBtn) {
                let badge = unverifiedBtn.querySelector('.pending-count');
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'pending-count';
                    unverifiedBtn.appendChild(badge);
                }
                if (data.details.residents > 0) {
                    badge.textContent = data.details.residents;
                    badge.style.display = 'inline-flex';
                } else {
                    badge.style.display = 'none';
                }
            }
        }
    } catch (error) {
        console.error('Error refreshing counts:', error);
    }
}

function showRealtimeToast(title, message) {
    const toast = document.createElement('div');
    toast.className = 'admin-toast realtime-toast';
    toast.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: linear-gradient(135deg, #1a472a, #2eaa5e);
        color: white;
        padding: 12px 20px;
        border-radius: 12px;
        z-index: 10001;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        animation: slideInRight 0.3s ease;
        max-width: 350px;
        cursor: pointer;
    `;
    toast.innerHTML = `
        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="background: rgba(255,255,255,0.2); width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-bell" style="font-size: 20px;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-weight: bold; font-size: 14px;">🚨 ${escapeHtml(title)}</div>
                <div style="font-size: 11px; opacity: 0.9;">${escapeHtml(message.substring(0, 100))}</div>
            </div>
            <button onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; color: white; font-size: 18px; cursor: pointer;">×</button>
        </div>
    `;
    
    toast.onclick = (e) => {
        if (!e.target.closest('button')) {
            toast.remove();
            document.getElementById('adminNotificationBellContainer')?.click();
        }
    };
    
    document.body.appendChild(toast);
    setTimeout(() => { if (toast.parentNode) toast.remove(); }, 8000);
}

function updateAdminNotificationBell(count) {
    const badge = document.getElementById('adminNotificationBadge');
    if (!badge) return;
    
    if (count > 0) {
        badge.textContent = count > 99 ? '99+' : count;
        badge.style.display = 'flex';
        badge.classList.add('pulse');
    } else {
        badge.style.display = 'none';
        badge.classList.remove('pulse');
    }
}

function animateAdminBell() {
    if (adminBellAnimating) return;
    adminBellAnimating = true;
    const bell = document.getElementById('adminNotificationBell');
    if (bell) {
        bell.classList.add('bell-ring-animation');
        setTimeout(() => {
            bell.classList.remove('bell-ring-animation');
            adminBellAnimating = false;
        }, 500);
    }
}

function renderAdminNotificationsList() {
    const container = document.getElementById('adminNotificationsList');
    if (!container) return;
    
    if (adminNotifications.length === 0) {
        container.innerHTML = `
            <div class="admin-empty-notifications">
                <i class="fas fa-bell-slash"></i>
                <p>No notifications</p>
            </div>
        `;
        return;
    }
    
    const getTimeAgo = (date) => {
        const seconds = Math.floor((new Date() - new Date(date)) / 1000);
        if (seconds < 60) return 'just now';
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) return `${minutes} min ago`;
        const hours = Math.floor(minutes / 60);
        if (hours < 24) return `${hours} hour${hours > 1 ? 's' : ''} ago`;
        const days = Math.floor(hours / 24);
        if (days < 7) return `${days} day${days > 1 ? 's' : ''} ago`;
        return new Date(date).toLocaleDateString();
    };
    
    container.innerHTML = adminNotifications.slice(0, 50).map(notif => `
        <div class="admin-notification-item ${!notif.is_read ? 'unread' : ''}" 
             data-notif-id="${notif.id}"
             data-section="${notif.section}"
             data-subview="${notif.subview || ''}"
             data-status-filter="${notif.status_filter || ''}"
             data-request-id="${notif.request_id || ''}"
             data-resident-id="${notif.resident_id || ''}">
            <div class="admin-notification-icon">
                <i class="fas ${notif.type === 'document_pending' ? 'fa-file-alt' : (notif.type === 'booking_pending' ? 'fa-calendar-check' : 'fa-user-plus')}"></i>
            </div>
            <div class="admin-notification-content">
                <div class="admin-notification-title">${escapeHtml(notif.title)}</div>
                <div class="admin-notification-message">${escapeHtml(notif.message)}</div>
                <div class="admin-notification-time">${getTimeAgo(notif.created_at)}</div>
            </div>
            ${!notif.is_read ? '<div class="notification-badge-dot"></div>' : ''}
        </div>
    `).join('');
    
    document.querySelectorAll('.admin-notification-item').forEach(item => {
        item.addEventListener('click', async (e) => {
            e.stopPropagation();
            const notifId = item.dataset.notifId;
            const section = item.dataset.section;
            const subview = item.dataset.subview;
            const statusFilter = item.dataset.statusFilter;
            const requestId = item.dataset.requestId;
            const residentId = item.dataset.residentId;
            
            await markAdminNotificationRead(notifId);
            document.getElementById('adminNotificationsDropdown').classList.remove('show');
            
            if (section === 'documents') {
                await navigateToDocumentRequests(statusFilter, requestId);
            } else if (section === 'bookings') {
                await navigateToBookings(statusFilter, requestId);
            } else if (section === 'accounts' && subview === 'unverified') {
                await navigateToUnverifiedAccounts(residentId);
            }
        });
    });
}

async function markAdminNotificationRead(notifId) {
    try {
        const formData = new FormData();
        formData.append('action', 'mark_read');
        formData.append('notification_id', notifId);
        await fetch('admin_notifications.php', { method: 'POST', body: formData });
        
        let readNotifications = [];
        try {
            const stored = localStorage.getItem('admin_read_notifications');
            if (stored) readNotifications = JSON.parse(stored);
        } catch(e) {}
        
        if (!readNotifications.includes(notifId)) {
            readNotifications.push(notifId);
            localStorage.setItem('admin_read_notifications', JSON.stringify(readNotifications));
            sessionStorage.setItem('admin_read_notifications', JSON.stringify(readNotifications));
        }
        
        const notif = adminNotifications.find(n => n.id === notifId);
        if (notif) notif.is_read = true;
        
        updateAdminNotificationBell(adminNotifications.filter(n => !n.is_read).length);
        renderAdminNotificationsList();
    } catch (error) {
        console.error('Error marking notification read:', error);
    }
}

async function markAllAdminNotificationsRead() {
    try {
        const formData = new FormData();
        formData.append('action', 'mark_all_read');
        await fetch('admin_notifications.php', { method: 'POST', body: formData });
        
        adminNotifications.forEach(n => n.is_read = true);
        const allNotifIds = adminNotifications.map(n => n.id);
        localStorage.setItem('admin_read_notifications', JSON.stringify(allNotifIds));
        sessionStorage.setItem('admin_read_notifications', JSON.stringify(allNotifIds));
        
        updateAdminNotificationBell(0);
        renderAdminNotificationsList();
        showSimpleToast('All notifications marked as read');
    } catch (error) {
        console.error('Error marking all read:', error);
    }
}

function showSimpleToast(message) {
    const toast = document.createElement('div');
    toast.className = 'admin-toast';
    toast.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: linear-gradient(135deg, #28a745, #1e7e34);
        color: white;
        padding: 10px 15px;
        border-radius: 10px;
        z-index: 10000;
        font-size: 12px;
        animation: slideInRight 0.3s ease;
        cursor: pointer;
    `;
    toast.innerHTML = `<i class="fas fa-check-circle"></i> ${escapeHtml(message)}`;
    toast.onclick = () => toast.remove();
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

async function navigateToDocumentRequests(statusFilter, requestId) {
    const documentsParent = document.getElementById('documentsParent');
    const documentsSubmenu = document.getElementById('documentsSubmenu');
    const requestListOption = document.querySelector('#documentsSubmenu .sub-option[data-subview="request_list"]');
    
    if (documentsParent && documentsSubmenu && !documentsSubmenu.classList.contains('open')) {
        documentsParent.click();
        await new Promise(r => setTimeout(r, 300));
    }
    
    if (requestListOption) {
        requestListOption.click();
        await new Promise(r => setTimeout(r, 800));
    }
    
    if (statusFilter) {
        const filterBtn = document.querySelector(`.filter-chip[onclick*="loadFilteredRequests('${statusFilter}'"]`);
        if (filterBtn) {
            filterBtn.click();
        } else {
            if (typeof loadFilteredRequests === 'function') {
                await loadFilteredRequests(statusFilter, 1);
            }
        }
        await new Promise(r => setTimeout(r, 500));
    }
    
    if (requestId) {
        highlightAndShakeCard(null, requestId);
    }
}

async function navigateToBookings(statusFilter, requestId) {
    const equipmentParent = document.getElementById('equipmentParent');
    const equipmentSubmenu = document.getElementById('equipmentSubmenu');
    const bookingsOption = document.querySelector('#equipmentSubmenu .sub-option[data-subview="equipment_bookings"]');
    
    if (equipmentParent && equipmentSubmenu && !equipmentSubmenu.classList.contains('open')) {
        equipmentParent.click();
        await new Promise(r => setTimeout(r, 300));
    }
    
    if (bookingsOption) {
        bookingsOption.click();
        await new Promise(r => setTimeout(r, 500));
    }
    
    if (statusFilter && typeof loadFilteredBookings === 'function') {
        await loadFilteredBookings(statusFilter, 1);
        await new Promise(r => setTimeout(r, 300));
    }
    
    if (requestId) {
        highlightAndShakeCard(`.data-card[onclick*="viewBookingDetails(${requestId})"]`, requestId);
    }
}

async function navigateToUnverifiedAccounts(residentId) {
    const accountsParent = document.getElementById('accountsParent');
    const accountsSubmenu = document.getElementById('accountsSubmenu');
    const unverifiedOption = document.querySelector('#accountsSubmenu .sub-option[data-subview="unverified"]');
    
    if (accountsParent && accountsSubmenu && !accountsSubmenu.classList.contains('open')) {
        accountsParent.click();
        await new Promise(r => setTimeout(r, 300));
    }
    
    if (unverifiedOption) {
        unverifiedOption.click();
        await new Promise(r => setTimeout(r, 1000));
    }
    
    if (residentId) {
        setTimeout(() => {
            highlightAndShakeCard(null, residentId);
        }, 1200);
    }
}

function highlightAndShakeCard(selector, id) {
    setTimeout(() => {
        let card = null;
        
        if (selector) card = document.querySelector(selector);
        if (!card && id) card = document.querySelector(`.data-card[data-id="${id}"]`);
        if (!card && id) card = document.querySelector(`.data-card[onclick*="${id}"]`);
        
        if (card) {
            const cardRect = card.getBoundingClientRect();
            const absoluteCardTop = cardRect.top + window.pageYOffset;
            const offset = absoluteCardTop - (window.innerHeight / 2) + (cardRect.height / 2);
            
            window.scrollTo({ top: Math.max(0, offset), behavior: 'smooth' });
            
            setTimeout(() => {
                card.classList.add('card-shake');
                card.style.transition = 'all 0.3s ease';
                card.style.boxShadow = '0 0 0 3px #ff9800, 0 4px 20px rgba(0,0,0,0.15)';
                card.style.zIndex = '100';
                card.style.position = 'relative';
                card.style.border = '2px solid #ff9800';
                
                let flashCount = 0;
                const originalBg = card.style.backgroundColor;
                const flashInterval = setInterval(() => {
                    if (flashCount >= 8) {
                        clearInterval(flashInterval);
                        card.style.backgroundColor = originalBg || '';
                    } else {
                        card.style.backgroundColor = flashCount % 2 === 0 ? '#fff8e1' : '#ffe0b2';
                        flashCount++;
                    }
                }, 150);
                
                setTimeout(() => {
                    card.classList.remove('card-shake');
                    setTimeout(() => {
                        card.style.boxShadow = '';
                        card.style.zIndex = '';
                        card.style.position = '';
                        card.style.border = '';
                    }, 1500);
                }, 800);
                
                if (navigator.vibrate) navigator.vibrate(100);
            }, 300);
        }
    }, 100);
}

function initAdminNotifications() {
    initAdminNotificationBell();
    fetchAdminNotifications();
    refreshPendingCounts();
    
    lastNotificationTimestamp = Math.floor(Date.now() / 1000) - 300;
    
    if (adminRealtimeCheckInterval) clearInterval(adminRealtimeCheckInterval);
    adminRealtimeCheckInterval = setInterval(checkRealtimeNotifications, 5000);
    
    if (adminNotificationCheckInterval) clearInterval(adminNotificationCheckInterval);
    adminNotificationCheckInterval = setInterval(fetchAdminNotifications, 30000);
    
    console.log('🔔 Real-time notification system started (vibration enabled)');
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdminNotifications);
} else {
    initAdminNotifications();
}
</script>

<script>
    // ============ GLOBAL CONFIRMATION MODAL ============
function showGlobalConfirmModal(title, message, onConfirm, onCancel = null, type = 'warning') {
    const existing = document.querySelector('.global-confirm-modal');
    if (existing) existing.remove();

    let iconHtml = '';
    switch(type) {
        case 'danger':  iconHtml = '<i class="fas fa-exclamation-circle" style="color:#dc3545;"></i>'; break;
        case 'warning': iconHtml = '<i class="fas fa-exclamation-triangle" style="color:#ff9800;"></i>'; break;
        case 'success': iconHtml = '<i class="fas fa-check-circle" style="color:#28a745;"></i>'; break;
        case 'info':    iconHtml = '<i class="fas fa-info-circle" style="color:#17a2b8;"></i>'; break;
        default:        iconHtml = '<i class="fas fa-question-circle" style="color:#ff9800;"></i>';
    }

    const btnColor = type === 'danger' ? '#dc3545' : (type === 'success' ? '#28a745' : '#ff9800');

    const modal = document.createElement('div');
    modal.className = 'global-confirm-modal';
    modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:99999;display:flex;align-items:center;justify-content:center;animation:fadeIn 0.3s ease;';

    modal.innerHTML = `
        <div style="background:white;border-radius:20px;max-width:420px;width:90%;padding:30px 25px;text-align:center;animation:slideDown 0.3s ease;box-shadow:0 25px 50px rgba(0,0,0,0.3);">
            <div style="font-size:60px;margin-bottom:15px;">${iconHtml}</div>
            <div style="font-size:1.4rem;font-weight:700;color:#1a472a;margin-bottom:12px;">${escapeHtml(title)}</div>
            <div style="color:#5f7f6e;margin-bottom:25px;line-height:1.5;white-space:pre-line;text-align:left;">${escapeHtml(message)}</div>
            <div style="display:flex;gap:12px;justify-content:center;">
                <button id="globalConfirmYes" style="background:${btnColor};color:white;border:none;padding:12px 28px;border-radius:10px;font-weight:600;font-size:14px;cursor:pointer;">
                    <i class="fas fa-check"></i> Yes, Proceed
                </button>
                <button id="globalConfirmNo" style="background:#6c757d;color:white;border:none;padding:12px 28px;border-radius:10px;font-weight:600;font-size:14px;cursor:pointer;">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </div>
    `;

    document.body.appendChild(modal);

    document.getElementById('globalConfirmYes').onclick = () => {
        modal.remove();
        if (onConfirm) onConfirm();
    };
    document.getElementById('globalConfirmNo').onclick = () => {
        modal.remove();
        if (onCancel) onCancel();
    };
    modal.addEventListener('click', (e) => {
        if (e.target === modal) { modal.remove(); if (onCancel) onCancel(); }
    });
}

// ============ GLOBAL NOTIFICATION MODAL ============
function showGlobalNotificationModal(type, title, message, autoClose = true) {
    const existing = document.querySelector('.global-notification-modal');
    if (existing) existing.remove();

    let iconHtml = '', iconColor = '';
    switch(type) {
        case 'success': iconHtml = '<i class="fas fa-check-circle"></i>';          iconColor = '#28a745'; break;
        case 'error':   iconHtml = '<i class="fas fa-times-circle"></i>';          iconColor = '#dc3545'; break;
        case 'warning': iconHtml = '<i class="fas fa-exclamation-triangle"></i>';  iconColor = '#ff9800'; break;
        case 'info':    iconHtml = '<i class="fas fa-info-circle"></i>';           iconColor = '#17a2b8'; break;
    }

    const modal = document.createElement('div');
    modal.className = 'global-notification-modal';
    modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:99999;display:flex;align-items:center;justify-content:center;animation:fadeIn 0.3s ease;';

    modal.innerHTML = `
        <div style="background:white;border-radius:20px;max-width:420px;width:90%;padding:30px 25px;text-align:center;animation:slideDown 0.3s ease;box-shadow:0 25px 50px rgba(0,0,0,0.3);">
            <div style="font-size:60px;margin-bottom:15px;color:${iconColor};">${iconHtml}</div>
            <div style="font-size:1.4rem;font-weight:700;color:#1a472a;margin-bottom:12px;">${escapeHtml(title)}</div>
            <div style="color:#5f7f6e;margin-bottom:25px;line-height:1.6;white-space:pre-line;text-align:left;">${escapeHtml(message)}</div>
            <button onclick="this.closest('.global-notification-modal').remove()" style="background:${iconColor};color:white;border:none;padding:12px 35px;border-radius:10px;font-weight:600;font-size:15px;cursor:pointer;">
                OK
            </button>
        </div>
    `;

    document.body.appendChild(modal);

    if (autoClose && type === 'success') {
        setTimeout(() => { if (modal.parentNode) modal.remove(); }, 2500);
    }
    modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.remove();
    });
}
if (typeof escapeHtml === 'undefined') {
    window.escapeHtml = function(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    };
}

if (typeof showSuccessModal === 'undefined') {
    window.showSuccessModal = function(message, title) { alert(title + '\n' + message); };
}

if (typeof showErrorModal === 'undefined') {
    window.showErrorModal = function(message, title) { alert(title + '\n' + message); };
}

if (typeof showWarningModal === 'undefined') {
    window.showWarningModal = function(message, title) { alert(title + '\n' + message); };
}

if (typeof showConfirmationModal === 'undefined') {
    window.showConfirmationModal = function(message, title, onConfirm) {
        if (confirm(title + '\n' + message)) onConfirm();
    };
}
</script>

<!-- Household Management JavaScript -->
<script src="household.js"></script>
</body>
</html> 