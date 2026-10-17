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

// ═══════════════════════════════════════════════════════════
// ANNOUNCEMENTS — setup
// ═══════════════════════════════════════════════════════════
define('ANN_UPLOAD_DIR', __DIR__ . '/../uploads/announcements/');
define('ANN_UPLOAD_URL', 'uploads/announcements/');

function annEnsureUploadDir() {
    if (!is_dir(ANN_UPLOAD_DIR)) @mkdir(ANN_UPLOAD_DIR, 0755, true);
}

function annFormatRow($r) {
    $now = time();
    $expTs = !empty($r['expires_at']) ? strtotime($r['expires_at']) : null;
    return [
        'id'           => (int)$r['id'],
        'title'        => $r['title'],
        'body'         => $r['body'],
        'category'     => $r['category'],
        'image_url'    => $r['image_path'] ? ANN_UPLOAD_URL . $r['image_path'] : null,
        'is_pinned'    => (int)$r['is_pinned'],
        'is_active'    => (int)$r['is_active'],
        'expires_at'   => $r['expires_at'],
        'event_date'   => $r['event_date'] ?? null,
        'event_time'   => $r['event_time'] ?? null,
        'is_expired'   => $expTs !== null && $expTs < $now,
        'auto_deactivated' => (int)($r['auto_deactivated'] ?? 0),
        'created_by'   => (int)$r['created_by'],
        'created_at'   => $r['created_at'],
        'updated_at'   => $r['updated_at'],
        'author'       => $r['author_name'] ?? null,
    ];
}

/**
 * Auto-deactivate any announcements whose expires_at has passed.
 * Returns the number of rows flipped.
 */
function annAutoDeactivateExpired($conn) {
    $stmt = mysqli_prepare($conn,
        "UPDATE announcements
         SET is_active = 0, auto_deactivated = 1
         WHERE is_active = 1
           AND expires_at IS NOT NULL
           AND expires_at < NOW()");
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $affected;
}

$admin_id = (int)$_SESSION['user_id'];

// ═══════════════════════════════════════════════════════════
// ANNOUNCEMENTS — AJAX (all inside this file)
// ═══════════════════════════════════════════════════════════
if (isset($_REQUEST['ann_action'])) {
    header('Content-Type: application/json');
    $act = $_REQUEST['ann_action'];

    // ── Auto-deactivate expired first (runs on every list/get) ──
    $autoDeactivated = annAutoDeactivateExpired($conn);

    // ── LIST ──
    if ($act === 'list') {
        $activeOnly = isset($_GET['active_only']) && $_GET['active_only'] === '1';
        $sql = "SELECT a.*, ad.full_name AS author_name
                FROM announcements a
                LEFT JOIN admin ad ON ad.id = a.created_by
                WHERE 1=1";
        if ($activeOnly) $sql .= " AND a.is_active = 1";
        $sql .= " ORDER BY a.is_pinned DESC, a.created_at DESC LIMIT 100";
        $res = mysqli_query($conn, $sql);
        $out = [];
        while ($r = mysqli_fetch_assoc($res)) $out[] = annFormatRow($r);
        echo json_encode([
            'success' => true,
            'data'    => $out,
            'auto_deactivated_count' => $autoDeactivated,
        ]);
        exit;
    }

    // ── GET ONE ──
    if ($act === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = mysqli_prepare($conn,
            "SELECT a.*, ad.full_name AS author_name
             FROM announcements a
             LEFT JOIN admin ad ON ad.id = a.created_by
             WHERE a.id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $r = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$r) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
        echo json_encode(['success'=>true,'data'=>annFormatRow($r)]);
        exit;
    }

    // ── SAVE (create or update) ──
    if ($act === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $title      = trim($_POST['title'] ?? '');
        $body       = trim($_POST['body'] ?? '');
        $category   = $_POST['category'] ?? 'general';
        $is_pinned  = (isset($_POST['is_pinned']) && $_POST['is_pinned'] === '1') ? 1 : 0;
        $is_active  = (isset($_POST['is_active']) && $_POST['is_active'] === '1') ? 1 : 0;
        $expires_at = !empty($_POST['expires_at']) ? trim($_POST['expires_at']) : null;

        // Optional event schedule
        $event_date = !empty($_POST['event_date']) ? trim($_POST['event_date']) : null;
        $event_time = !empty($_POST['event_time']) ? trim($_POST['event_time']) : null;
        if ($event_date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $event_date)) $event_date = null;
        if ($event_time !== null && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $event_time)) $event_time = null;
        if ($event_time !== null && strlen($event_time) === 5) $event_time .= ':00';

        $allowed = ['general','urgent','event','advisory','health','safety'];
        if (!in_array($category, $allowed)) $category = 'general';

        if ($title === '' || $body === '') {
            echo json_encode(['success'=>false,'message'=>'Title and message are required.']); exit;
        }
        if (mb_strlen($title) > 255) $title = mb_substr($title, 0, 255);

        // Validate expiry
        if ($expires_at !== null) {
            // Accept 'YYYY-MM-DDTHH:MM' or 'YYYY-MM-DD HH:MM:SS' or 'YYYY-MM-DD'
            $expires_at = str_replace('T', ' ', $expires_at);
            if (strlen($expires_at) === 10) $expires_at .= ' 23:59:59';
            elseif (strlen($expires_at) === 16) $expires_at .= ':00';
            $ts = strtotime($expires_at);
            if ($ts === false) {
                echo json_encode(['success'=>false,'message'=>'Invalid expiry date.']); exit;
            }
            $expires_at = date('Y-m-d H:i:s', $ts);
        }

        // ── IMAGE UPLOAD ──
        $imageName = null;
        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            annEnsureUploadDir();
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                echo json_encode(['success'=>false,'message'=>'Only JPG, PNG, GIF, WEBP allowed.']); exit;
            }
            if ($_FILES['image']['size'] > 5 * 1024 * 1024) {
                echo json_encode(['success'=>false,'message'=>'Image too large (max 5 MB).']); exit;
            }
            $imageName = 'ann_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['image']['tmp_name'], ANN_UPLOAD_DIR . $imageName)) {
                echo json_encode(['success'=>false,'message'=>'Failed to save image.']); exit;
            }
        }

        // ── UPDATE ──
        if ($id > 0) {
            if ($imageName) {
                $old = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT image_path FROM announcements WHERE id = $id"));
                if ($old && $old['image_path'] && file_exists(ANN_UPLOAD_DIR . $old['image_path'])) {
                    @unlink(ANN_UPLOAD_DIR . $old['image_path']);
                }
                $stmt = mysqli_prepare($conn,
                    "UPDATE announcements
                     SET title=?, body=?, category=?, image_path=?,
                         is_pinned=?, is_active=?, expires_at=?,
                         event_date=?, event_time=?, auto_deactivated=0
                     WHERE id=?");
                mysqli_stmt_bind_param($stmt, "ssssisissi",
                    $title, $body, $category, $imageName,
                    $is_pinned, $is_active, $expires_at,
                    $event_date, $event_time, $id);
            } else {
                $stmt = mysqli_prepare($conn,
                    "UPDATE announcements
                     SET title=?, body=?, category=?,
                         is_pinned=?, is_active=?, expires_at=?,
                         event_date=?, event_time=?, auto_deactivated=0
                     WHERE id=?");
                mysqli_stmt_bind_param($stmt, "sssisissi",
                    $title, $body, $category,
                    $is_pinned, $is_active, $expires_at,
                    $event_date, $event_time, $id);
            }
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            echo json_encode(['success'=>true,'id'=>$id,'message'=>'Announcement updated.']);
            exit;
        }

        // ── CREATE ──
        $stmt = mysqli_prepare($conn,
            "INSERT INTO announcements
                (title, body, category, image_path, is_pinned, is_active, expires_at,
                 event_date, event_time, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)");
        mysqli_stmt_bind_param($stmt, "ssssisissi",
            $title, $body, $category, $imageName,
            $is_pinned, $is_active, $expires_at,
            $event_date, $event_time, $admin_id);
        mysqli_stmt_execute($stmt);
        $newId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        echo json_encode(['success'=>true,'id'=>$newId,'message'=>'Announcement created.']);
        exit;
    }

    // ── DELETE ──
    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $old = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT image_path FROM announcements WHERE id = $id"));
        if ($old && $old['image_path'] && file_exists(ANN_UPLOAD_DIR . $old['image_path'])) {
            @unlink(ANN_UPLOAD_DIR . $old['image_path']);
        }
        $stmt = mysqli_prepare($conn, "DELETE FROM announcements WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['success'=>true]);
        exit;
    }

    // ── TOGGLE PIN / ACTIVE ──
    if ($act === 'toggle') {
        $id    = (int)($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        if (!in_array($field, ['is_pinned','is_active'])) {
            echo json_encode(['success'=>false,'message'=>'Invalid field']); exit;
        }
        // When reactivating, also clear the auto_deactivated flag
        $extra = ($field === 'is_active') ? ", auto_deactivated = 0" : "";
        $stmt = mysqli_prepare($conn,
            "UPDATE announcements SET {$field} = 1 - {$field}{$extra} WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['success'=>true]);
        exit;
    }

    // ── FORCE EXPIRE CHECK (manual trigger) ──
    if ($act === 'check_expired') {
        echo json_encode([
            'success' => true,
            'deactivated' => $autoDeactivated,
        ]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']);
    exit;
}

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

// ═══════════════════════════════════════════════════════════
// DASHBOARD ANALYTICS (charts + calendar + KPI data)
// ═══════════════════════════════════════════════════════════
function dxRows($conn, $sql) {
    try {
        $r = mysqli_query($conn, $sql);
        if (!$r) return [];
        $out = [];
        while ($row = mysqli_fetch_assoc($r)) $out[] = $row;
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}
function dxCount($conn, $sql) {
    $r = dxRows($conn, $sql);
    return $r ? (int)array_values($r[0])[0] : 0;
}

$dxMonths = [];
for ($i = 5; $i >= 0; $i--) {
    $d = new DateTime('first day of this month');
    $d->modify("-$i month");
    $dxMonths[$d->format('Y-m')] = $d->format('M');
}
$dxDocMonthly  = array_fill_keys(array_keys($dxMonths), 0);
$dxCompMonthly = array_fill_keys(array_keys($dxMonths), 0);
$dxResMonthly  = array_fill_keys(array_keys($dxMonths), 0);

foreach (dxRows($conn, "SELECT DATE_FORMAT(request_date,'%Y-%m') ym, COUNT(*) c FROM document_requests
                        WHERE request_date >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH,'%Y-%m-01') GROUP BY ym") as $row) {
    if (isset($dxDocMonthly[$row['ym']])) $dxDocMonthly[$row['ym']] = (int)$row['c'];
}
foreach (dxRows($conn, "SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) c FROM complaints
                        WHERE created_at >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH,'%Y-%m-01') GROUP BY ym") as $row) {
    if (isset($dxCompMonthly[$row['ym']])) $dxCompMonthly[$row['ym']] = (int)$row['c'];
}
foreach (dxRows($conn, "SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) c FROM resident
                        WHERE created_at >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH,'%Y-%m-01') GROUP BY ym") as $row) {
    if (isset($dxResMonthly[$row['ym']])) $dxResMonthly[$row['ym']] = (int)$row['c'];
}

$dxDocStatus = ['pending' => 0, 'approved' => 0, 'unclaimed' => 0, 'claimed' => 0, 'rejected' => 0];
foreach (dxRows($conn, "SELECT status, COUNT(*) c FROM document_requests GROUP BY status") as $row) {
    if (isset($dxDocStatus[$row['status']])) $dxDocStatus[$row['status']] = (int)$row['c'];
}

$dxComplaintStatus = [];
foreach (dxRows($conn, "SELECT status, COUNT(*) c FROM complaints GROUP BY status") as $row) {
    $dxComplaintStatus[$row['status']] = (int)$row['c'];
}

$dxTopDocs = [];
foreach (dxRows($conn, "SELECT document_type, COUNT(*) c FROM document_requests
                        GROUP BY document_type ORDER BY c DESC LIMIT 6") as $row) {
    $dxTopDocs[] = ['name' => $row['document_type'], 'count' => (int)$row['c']];
}

$dxEvents = [];
foreach (dxRows($conn, "SELECT id, reference_number, title, status, hearing_date, hearing_time
                        FROM complaints
                        WHERE hearing_date IS NOT NULL
                          AND hearing_date BETWEEN CURDATE() - INTERVAL 90 DAY AND CURDATE() + INTERVAL 270 DAY
                          AND status <> 'dismissed'
                        ORDER BY hearing_date ASC LIMIT 400") as $row) {
    $dxEvents[] = [
        'kind' => 'hearing', 'id' => (int)$row['id'],
        'date' => substr($row['hearing_date'], 0, 10), 'time' => $row['hearing_time'],
        'title' => $row['title'], 'sub' => $row['reference_number'], 'status' => $row['status'],
    ];
}
foreach (dxRows($conn, "SELECT dr.id, dr.document_type, dr.status, dr.pickup_date, dr.pickup_time,
                               r.first_name, r.last_name
                        FROM document_requests dr
                        JOIN resident r ON dr.resident_id = r.id
                        WHERE dr.pickup_date IS NOT NULL
                          AND dr.pickup_date BETWEEN CURDATE() - INTERVAL 90 DAY AND CURDATE() + INTERVAL 270 DAY
                          AND dr.status IN ('approved','unclaimed','claimed')
                        ORDER BY dr.pickup_date ASC LIMIT 400") as $row) {
    $dxEvents[] = [
        'kind' => 'pickup', 'id' => (int)$row['id'],
        'date' => substr($row['pickup_date'], 0, 10), 'time' => $row['pickup_time'],
        'title' => $row['document_type'], 'sub' => trim($row['first_name'] . ' ' . $row['last_name']),
        'status' => $row['status'],
    ];
}

$dxData = [
    'months'           => array_values($dxMonths),
    'docMonthly'       => array_values($dxDocMonthly),
    'complaintMonthly' => array_values($dxCompMonthly),
    'residentMonthly'  => array_values($dxResMonthly),
    'docStatus'        => $dxDocStatus,
    'complaintStatus'  => $dxComplaintStatus,
    'topDocs'          => $dxTopDocs,
    'events'           => $dxEvents,
    'kpi' => [
        'pendingRequests' => $dxDocStatus['pending'],
        'todayRequests'   => dxCount($conn, "SELECT COUNT(*) FROM document_requests WHERE DATE(request_date) = CURDATE()"),
        'casesToReview'   => (int)($dxComplaintStatus['pending_review'] ?? 0),
        'escalated'       => (int)($dxComplaintStatus['escalated'] ?? 0),
    ],
    'equip' => null, // filled in by JS from equipment_ajax.php
];
$dxJson = json_encode($dxData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR);
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
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
/* ===== Dashboard widgets + announcements (same look as Secretary dashboard) ===== */
/* ACTIVITY GRID */
.recent-activity-grid {
    display: grid; grid-template-columns: 1fr; gap: 18px;
}
@media (min-width: 900px) {
    .recent-activity-grid { grid-template-columns: 1.5fr 1fr; }
}
.activity-card {
    background: #fff; border-radius: 16px;
    border: 1px solid #e2efe8;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    overflow: hidden;
}
.activity-header {
    padding: 16px 20px;
    display: flex; justify-content: space-between; align-items: center;
    border-bottom: 1px solid #f0f4f2;
}
.activity-header h4 {
    margin: 0; font-size: 0.92rem;
    color: #1a472a;
    display: flex; align-items: center; gap: 8px;
}
.activity-header h4 i { color: #2E7D32; }
.view-all-btn {
    background: none; border: none; color: #2E7D32;
    font-size: 0.78rem; font-weight: 700; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 10px; border-radius: 8px;
    transition: all 0.15s;
}
.view-all-btn:hover { background: #e8f5e9; }
.activity-list { padding: 10px 14px; max-height: 340px; overflow-y: auto; }
.activity-item {
    display: flex; justify-content: space-between;
    align-items: center; gap: 12px;
    padding: 12px 14px; border-radius: 10px;
    cursor: pointer; transition: all 0.15s;
}
.activity-item:hover { background: #f1f8e9; }
.activity-info { flex: 1; min-width: 0; }
.activity-title {
    font-weight: 700; font-size: 0.85rem;
    color: #1a2b22;
    overflow: hidden; text-overflow: ellipsis;
    white-space: nowrap;
}
.activity-detail {
    font-size: 0.72rem; color: #7a8a82; margin-top: 3px;
}
.activity-status {
    padding: 3px 10px; border-radius: 12px;
    font-size: 0.65rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.4px;
}
.activity-status.status-pending   { background: #fff3e0; color: #e65100; }
.activity-status.status-approved  { background: #e8f5e9; color: #2e7d32; }
.activity-status.status-unclaimed { background: #fff3e0; color: #ef6c00; }
.activity-status.status-claimed   { background: #e3f2fd; color: #1565c0; }
.activity-status.status-rejected  { background: #ffebee; color: #c62828; }
/* ═══════════════════════════════════════════════════════════
   ANNOUNCEMENTS — image background cards
   ═══════════════════════════════════════════════════════════ */
.annc-section { margin-bottom: 6px; }

.annc-section-head {
    display: flex; justify-content: space-between;
    align-items: center; margin-bottom: 14px;
}
.annc-section-title {
    font-size: 1rem; font-weight: 800; color: #1a472a;
    display: flex; align-items: center; gap: 10px;
}
.annc-section-title i { color: #e65100; }
.annc-add-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 18px; border: none; border-radius: 22px;
    background: linear-gradient(135deg, #2E7D32, #43a047);
    color: #fff; font-size: 0.82rem; font-weight: 700;
    cursor: pointer;
    box-shadow: 0 4px 14px rgba(46,125,50,0.3);
    transition: all 0.15s;
}
.annc-add-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(46,125,50,0.45);
}

.annc-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 18px;
}

.annc-card {
    position: relative;
    min-height: 260px;
    border-radius: 18px;
    overflow: hidden;
    background: #1a1a1a;
    cursor: pointer;
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
    transition: all 0.28s ease;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
}
.annc-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 16px 40px rgba(0,0,0,0.25);
}
.annc-card::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(
        180deg,
        rgba(0,0,0,0.10) 0%,
        rgba(0,0,0,0.35) 35%,
        rgba(0,0,0,0.85) 100%
    );
    z-index: 1;
    transition: background 0.25s;
}
.annc-card:hover::before {
    background: linear-gradient(
        180deg,
        rgba(0,0,0,0.20) 0%,
        rgba(0,0,0,0.50) 35%,
        rgba(0,0,0,0.92) 100%
    );
}
.annc-card-image {
    position: absolute; inset: 0; z-index: 0;
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    transition: transform 0.5s ease;
}
.annc-card:hover .annc-card-image { transform: scale(1.06); }
.annc-card-image.fallback {
    background: linear-gradient(135deg, #1b5e20 0%, #2E7D32 40%, #66bb6a 100%);
    display: flex; align-items: center; justify-content: center;
}
.annc-card-image.fallback::after {
    content: '\f0a1';
    font-family: 'Font Awesome 6 Free';
    font-weight: 900;
    font-size: 5rem;
    color: rgba(255,255,255,0.2);
}

.annc-card-top {
    position: absolute; top: 14px; left: 14px; right: 14px;
    display: flex; gap: 8px; align-items: flex-start;
    z-index: 3; flex-wrap: wrap;
}
.annc-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 12px; border-radius: 14px;
    font-size: 0.68rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.5px;
    backdrop-filter: blur(10px);
    background: rgba(255,255,255,0.25);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.3);
}
.annc-badge.urgent   { background: #e53935; border-color: #e53935; }
.annc-badge.event    { background: #7b1fa2; border-color: #7b1fa2; }
.annc-badge.advisory { background: #f57c00; border-color: #f57c00; }
.annc-badge.health   { background: #00897b; border-color: #00897b; }
.annc-badge.safety   { background: #c62828; border-color: #c62828; }
.annc-badge.pinned   { background: #ffb300; color: #4a2f00; border-color: #ffb300; }
.annc-badge.draft    { background: #546e7a; border-color: #546e7a; }
.annc-badge.expiry   {
    background: rgba(0,0,0,0.55);
    border-color: rgba(255,255,255,0.35);
    color: #fff;
    font-weight: 700;
}
.annc-badge.expiry.soon   { background: #f57c00; border-color: #f57c00; }
.annc-badge.expiry.urgent { background: #e53935; border-color: #e53935; }

.annc-card-actions {
    position: absolute; top: 14px; right: 14px;
    display: flex; gap: 6px; z-index: 4;
    opacity: 0; transform: translateY(-4px);
    transition: all 0.2s;
}
.annc-card:hover .annc-card-actions {
    opacity: 1; transform: translateY(0);
}
.annc-icon-btn {
    width: 32px; height: 32px; border-radius: 50%;
    border: 1px solid rgba(255,255,255,0.3);
    background: rgba(0,0,0,0.45); color: #fff;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.78rem;
    backdrop-filter: blur(8px);
    transition: all 0.15s;
}
.annc-icon-btn:hover {
    background: rgba(255,255,255,0.95);
    color: #1a1a1a;
    transform: scale(1.08);
}
.annc-icon-btn.danger:hover { background: #c62828; color: #fff; }

.annc-card-body {
    position: relative; z-index: 2;
    padding: 22px 20px 20px;
    color: #fff;
}
.annc-card-title {
    font-size: 1.18rem; font-weight: 800;
    line-height: 1.3; margin: 0 0 8px;
    text-shadow: 0 2px 8px rgba(0,0,0,0.5);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.annc-card-text {
    font-size: 0.85rem; line-height: 1.55;
    color: rgba(255,255,255,0.92);
    margin: 0;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-shadow: 0 1px 4px rgba(0,0,0,0.4);
}
.annc-card-meta {
    display: flex; gap: 14px;
    font-size: 0.72rem;
    color: rgba(255,255,255,0.85);
    flex-wrap: wrap; margin-top: 12px;
}
.annc-card-meta span {
    display: inline-flex; align-items: center; gap: 5px;
}

.annc-empty {
    text-align: center;
    padding: 50px 20px;
    background: linear-gradient(135deg, #f1f8e9, #e8f5e9);
    border: 2px dashed #a5d6a7;
    border-radius: 18px;
    color: #4a5f56;
}
.annc-empty i {
    font-size: 3rem; color: #a5d6a7;
    margin-bottom: 14px; display: block;
}
.annc-empty h4 { margin: 0 0 6px; color: #1a472a; font-size: 1rem; }
.annc-empty p  { margin: 0; font-size: 0.85rem; }

@media (max-width: 640px) {
    .annc-grid { grid-template-columns: 1fr; }
    .annc-card { min-height: 220px; }
}

/* ═══════════════════════════════════════════════════════════
   ANNOUNCEMENT MODAL
   ═══════════════════════════════════════════════════════════ */
.annc-modal { text-align: left; font-family: 'Segoe UI', Roboto, sans-serif; }
.annc-modal-header {
    background: linear-gradient(135deg, #1b5e20, #2E7D32 55%, #43a047);
    color: #fff;
    padding: 20px 26px;
    display: flex; align-items: center; gap: 14px;
    border-radius: 14px 14px 0 0;
}
.annc-modal-header i {
    font-size: 1.5rem;
    background: rgba(255,255,255,0.2);
    width: 46px; height: 46px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.annc-modal-header h3 { margin: 0; font-size: 1.1rem; font-weight: 800; }
.annc-modal-header p  { margin: 3px 0 0; font-size: 0.8rem; opacity: 0.9; }

.annc-modal-body {
    padding: 22px 26px;
    background: #fff;
    max-height: 72vh; overflow-y: auto;
}
.annc-field { margin-bottom: 16px; }
.annc-field label {
    display: block; font-weight: 700; font-size: 0.82rem;
    color: #1a472a; margin-bottom: 6px;
}
.annc-field label .req { color: #c62828; }
.annc-field label .opt { color: #999; font-weight: 400; font-size: 0.75rem; }
.annc-input, .annc-textarea, .annc-select {
    width: 100%; padding: 11px 13px;
    border: 1.5px solid #cfd8dc; border-radius: 9px;
    font-size: 0.9rem; font-family: inherit;
    box-sizing: border-box; background: #fff;
}
.annc-input:focus, .annc-textarea:focus, .annc-select:focus {
    outline: none; border-color: #2E7D32;
    box-shadow: 0 0 0 3px rgba(46,125,50,0.12);
}
.annc-textarea { resize: vertical; min-height: 90px; }
.annc-row-2 {
    display: grid; grid-template-columns: 1fr 1fr; gap: 14px;
}
@media (max-width: 600px) { .annc-row-2 { grid-template-columns: 1fr; } }

.annc-img-drop {
    border: 2px dashed #a5d6a7;
    border-radius: 12px; padding: 24px;
    text-align: center; cursor: pointer;
    background: #f8faf8;
    transition: all 0.15s;
    position: relative; overflow: hidden;
}
.annc-img-drop:hover { border-color: #2E7D32; background: #f1f8e9; }
.annc-img-drop.has-image {
    padding: 0;
    border-style: solid;
    border-color: #2E7D32;
    background: #000;
}
.annc-img-drop-icon {
    font-size: 2.2rem; color: #a5d6a7;
    margin-bottom: 8px;
}
.annc-img-drop-title { font-weight: 700; color: #1a472a; margin-bottom: 4px; }
.annc-img-drop-sub   { font-size: 0.78rem; color: #7a8a82; }
.annc-img-preview {
    display: none;
    position: relative;
    width: 100%; aspect-ratio: 16 / 9;
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
}
.annc-img-drop.has-image .annc-img-preview { display: block; }
.annc-img-drop.has-image .annc-img-placeholder { display: none; }
.annc-img-remove {
    position: absolute; top: 10px; right: 10px;
    width: 34px; height: 34px; border-radius: 50%;
    background: rgba(0,0,0,0.6); color: #fff;
    border: none; cursor: pointer;
    font-size: 0.9rem;
    display: flex; align-items: center; justify-content: center;
}
.annc-img-remove:hover { background: #c62828; }

.annc-toggle-row {
    display: flex; gap: 24px; flex-wrap: wrap;
    padding: 14px 16px;
    background: #f8faf8; border-radius: 10px;
    border: 1px solid #e2efe8;
}
.annc-toggle {
    display: flex; align-items: center; gap: 10px;
    cursor: pointer; font-size: 0.85rem;
    font-weight: 600; color: #1a2b22;
}
.annc-switch {
    position: relative;
    width: 42px; height: 24px;
    background: #cfd8dc; border-radius: 24px;
    transition: background 0.2s; flex-shrink: 0;
}
.annc-switch::after {
    content: ''; position: absolute;
    top: 3px; left: 3px;
    width: 18px; height: 18px;
    background: #fff; border-radius: 50%;
    transition: transform 0.2s;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.annc-toggle input { display: none; }
.annc-toggle input:checked ~ .annc-switch { background: #2E7D32; }
.annc-toggle input:checked ~ .annc-switch::after { transform: translateX(18px); }

.annc-modal-footer {
    display: flex; justify-content: space-between;
    gap: 10px; padding: 16px 26px;
    background: #f8faf8;
    border-top: 1px solid #e2efe8;
    border-radius: 0 0 14px 14px;
    flex-wrap: wrap;
}
.annc-modal-footer .right { display: flex; gap: 10px; margin-left: auto; }
.annc-btn {
    padding: 11px 22px; border-radius: 9px;
    border: none; cursor: pointer;
    font-size: 0.88rem; font-weight: 700;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.15s;
}
.annc-btn:hover { transform: translateY(-1px); }
.annc-btn.primary {
    background: linear-gradient(135deg, #2E7D32, #43a047);
    color: #fff;
    box-shadow: 0 3px 10px rgba(46,125,50,0.3);
}
.annc-btn.danger { background: #c62828; color: #fff; }
.annc-btn.ghost {
    background: #fff; color: #555;
    border: 1.5px solid #cfd8dc;
}
.annc-btn.ghost:hover { background: #f0f0f0; }

/* Modal popup container for announcements */
.annc-popup {
    border-radius: 16px !important;
    padding: 0 !important;
    overflow: hidden;
    max-width: 640px !important;
    width: 95vw !important;
}
.annc-popup .swal2-html-container { margin: 0 !important; padding: 0 !important; }
.annc-popup .swal2-actions { display: none !important; }
.annc-popup .swal2-title { display: none !important; }
/* ═══════════════════════════════════════════════════════════
   ANNOUNCEMENT CAROUSEL
   ═══════════════════════════════════════════════════════════ */
.annc-carousel {
    position: relative;
    width: 100%;
    overflow: hidden;
    border-radius: 18px;
}

.annc-carousel-track {
    display: flex;
    width: 100%;
    transition: transform 0.5s cubic-bezier(0.65, 0, 0.35, 1);
    will-change: transform;
}

.annc-carousel-slide {
    flex: 0 0 100%;
    min-width: 0;
    padding: 0;                     /* slide has no padding; card handles it */
}

.annc-carousel-slide .annc-card {
    width: 100%;
    min-height: 420px;              /* tall, matches stats column */
    border-radius: 18px;
    margin: 0;
}

/* Nav arrows */
.annc-nav-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.55);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.25);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    z-index: 10;
    backdrop-filter: blur(8px);
    transition: all 0.15s;
    opacity: 0.85;
}
.annc-nav-btn:hover {
    background: rgba(0, 0, 0, 0.85);
    opacity: 1;
    transform: translateY(-50%) scale(1.06);
}
.annc-nav-btn.prev { left: 14px; }
.annc-nav-btn.next { right: 14px; }

/* Dots */
.annc-dots {
    position: absolute;
    bottom: 16px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 8px;
    padding: 8px 14px;
    background: rgba(0, 0, 0, 0.45);
    border-radius: 20px;
    backdrop-filter: blur(8px);
    z-index: 10;
}
.annc-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: rgba(255,255,255,0.4);
    border: none;
    cursor: pointer;
    transition: all 0.25s ease;
    padding: 0;
}
.annc-dot:hover {
    background: rgba(255,255,255,0.75);
}
.annc-dot.active {
    background: #fff;
    width: 24px;
    border-radius: 4px;
}

/* Left column: fill the space with a single, tall card */
.dash-left .annc-grid {
    grid-template-columns: 1fr;
    gap: 18px;
}
.dash-left .annc-card {
    min-height: 420px;
}

@media (max-width: 900px) {
    .annc-carousel-slide .annc-card { min-height: 320px; }
    .annc-nav-btn { width: 38px; height: 38px; font-size: 0.9rem; }
    .annc-nav-btn.prev { left: 8px; }
    .annc-nav-btn.next { right: 8px; }
}

/* ═══════════════════════════════════════════════════════════
   ANALYTICS DASHBOARD  (dx-*)
   ═══════════════════════════════════════════════════════════ */
.dx { display: flex; flex-direction: column; gap: 14px; }

/* top bar */
.dx-top {
    display: flex; justify-content: space-between; align-items: center;
    gap: 14px; flex-wrap: wrap;
    background: linear-gradient(135deg, #1b5e20, #2E7D32 55%, #43a047);
    color: #fff; border-radius: 16px; padding: 14px 20px;
    box-shadow: 0 8px 22px rgba(46,125,50,0.22);
    position: relative; overflow: hidden;
}
.dx-top::after {
    content: ''; position: absolute; right: -60px; top: -90px;
    width: 220px; height: 220px; border-radius: 50%;
    background: rgba(255,255,255,0.08); pointer-events: none;
}
.dx-top-text { position: relative; z-index: 1; min-width: 0; }
.dx-top-text h2 { margin: 0; font-size: 1.15rem; font-weight: 800; }
.dx-top-text p  { margin: 3px 0 0; font-size: 0.8rem; opacity: 0.9; display: flex; align-items: center; gap: 6px; }
.dx-top-actions { position: relative; z-index: 1; display: flex; gap: 8px; flex-wrap: wrap; }
.dx-top-btn {
    border: 1px solid rgba(255,255,255,0.35); background: rgba(255,255,255,0.15);
    color: #fff; padding: 8px 14px; border-radius: 10px; cursor: pointer;
    font-size: 0.78rem; font-weight: 700; display: inline-flex; align-items: center; gap: 7px;
    transition: all .15s; backdrop-filter: blur(6px);
}
.dx-top-btn:hover { background: #fff; color: #1b5e20; transform: translateY(-1px); }

/* KPI strip */
.dx-kpis { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
.dx-kpi {
    background: #fff; border: 1px solid #e2efe8; border-radius: 14px;
    padding: 10px 12px; display: flex; align-items: center; gap: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04); transition: all .18s; min-width: 0;
}
.dx-kpi[data-go] { cursor: pointer; }
.dx-kpi[data-go]:hover { transform: translateY(-2px); border-color: #a5d6a7; box-shadow: 0 8px 18px rgba(0,0,0,0.08); }
.dx-kpi-ic {
    width: 38px; height: 38px; border-radius: 11px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 1rem;
}
.dx-kpi-num { font-size: 1.35rem; font-weight: 800; color: #1a2b22; line-height: 1; letter-spacing: -0.4px; }
.dx-kpi-lbl {
    font-size: 0.64rem; font-weight: 700; color: #7a8a82; margin-top: 3px;
    text-transform: uppercase; letter-spacing: 0.3px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.dx-kpi > div:last-child { min-width: 0; }

/* grid + cards */
.dx-grid { display: grid; grid-template-columns: 1fr; gap: 14px; }
.dx-card {
    background: #fff; border: 1px solid #e2efe8; border-radius: 16px;
    padding: 14px 16px; display: flex; flex-direction: column;
    min-height: 0; min-width: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}
.dx-card-h { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; flex-shrink: 0; }
.dx-card-h h4 { margin: 0; font-size: 0.9rem; color: #1a472a; display: flex; align-items: center; gap: 8px; }
.dx-card-h h4 i { color: #2E7D32; }
.dx-chart { position: relative; height: 260px; min-height: 0; }
.dx-chart canvas { max-width: 100%; }

/* segmented toggle */
.dx-seg { display: inline-flex; background: #f1f6f3; border-radius: 10px; padding: 3px; gap: 2px; }
.dx-seg button {
    border: none; background: transparent; padding: 5px 11px; border-radius: 8px;
    font-size: 0.72rem; font-weight: 700; color: #5f7f6e; cursor: pointer; transition: all .15s;
}
.dx-seg button.active { background: #2E7D32; color: #fff; box-shadow: 0 2px 6px rgba(46,125,50,0.3); }

.dx-delta {
    display: inline-flex; align-items: center; gap: 5px; font-size: 0.7rem; font-weight: 800;
    padding: 3px 9px; border-radius: 12px; background: #e8f5e9; color: #2e7d32;
}
.dx-delta.down { background: #ffebee; color: #c62828; }
.dx-delta.flat { background: #eceff1; color: #546e7a; }

/* calendar */
.dx-cal-nav { display: flex; align-items: center; gap: 4px; }
.dx-cal-nav button {
    width: 28px; height: 28px; border-radius: 8px; border: 1px solid #e2efe8; background: #fff;
    color: #2E7D32; cursor: pointer; font-size: 0.72rem; transition: all .15s;
}
.dx-cal-nav button:hover { background: #e8f5e9; }
.dx-cal-nav button.today { width: auto; padding: 0 10px; font-weight: 700; font-size: 0.7rem; }
.dx-cal-title { font-weight: 800; color: #1a472a; font-size: 0.86rem; min-width: 112px; text-align: center; }
.dx-cal-wrap { display: flex; flex-direction: column; flex: 1; min-height: 0; }
.dx-cal-dow { display: grid; grid-template-columns: repeat(7, 1fr); text-align: center; font-size: 0.64rem; font-weight: 800; color: #8a9a92; text-transform: uppercase; letter-spacing: .4px; padding-bottom: 4px; flex-shrink: 0; }
.dx-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; flex: 1; min-height: 190px; }
.dx-day {
    border: 1.5px solid transparent; background: #f8faf8; border-radius: 9px; cursor: pointer;
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px;
    font-size: 0.76rem; font-weight: 700; color: #1a2b22; padding: 2px; min-height: 32px;
    transition: all .12s; font-family: inherit;
}
.dx-day:hover { background: #e8f5e9; }
.dx-day.blank { background: transparent; cursor: default; pointer-events: none; }
.dx-day.today { background: #2E7D32; color: #fff; }
.dx-day.today:hover { background: #1b5e20; }
.dx-day.sel { border-color: #2E7D32; box-shadow: 0 0 0 2px rgba(46,125,50,0.15); }
.dx-dots { display: flex; gap: 2px; height: 6px; align-items: center; }
.dx-dot { width: 5px; height: 5px; border-radius: 50%; }
.dx-dot.hearing { background: #8e24aa; }
.dx-dot.pickup  { background: #43a047; }
.dx-day.today .dx-dot { box-shadow: 0 0 0 1px #fff; }
.dx-legend { display: flex; gap: 14px; font-size: 0.68rem; color: #6b7a72; margin: 8px 0 6px; flex-shrink: 0; flex-wrap: wrap; }
.dx-legend span { display: inline-flex; align-items: center; gap: 5px; }
.dx-agenda { border-top: 1px dashed #d7e2dc; padding-top: 8px; max-height: 150px; overflow-y: auto; flex-shrink: 0; }
.dx-agenda-h { font-size: 0.72rem; font-weight: 800; color: #1a472a; text-transform: uppercase; letter-spacing: .3px; margin-bottom: 6px; }
.dx-ev {
    display: flex; gap: 9px; align-items: center; padding: 6px 8px; border-radius: 9px;
    cursor: pointer; border-left: 4px solid; margin-bottom: 5px; background: #fafbfa;
}
.dx-ev:hover { background: #f1f8e9; }
.dx-ev.hearing { border-left-color: #8e24aa; }
.dx-ev.pickup  { border-left-color: #43a047; }
.dx-ev-time { font-size: 0.68rem; font-weight: 800; color: #5f7f6e; min-width: 54px; }
.dx-ev-body { min-width: 0; flex: 1; }
.dx-ev-title { font-size: 0.78rem; font-weight: 700; color: #1a2b22; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dx-ev-sub { font-size: 0.68rem; color: #7a8a82; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dx-empty { font-size: 0.78rem; color: #99a69f; text-align: center; padding: 8px 0; }

/* announcements (compact) */
.dx-annc-body { flex: 1; min-height: 240px; position: relative; }
.dx-annc-body #anncGridWrap,
.dx-annc-body .annc-carousel,
.dx-annc-body .annc-carousel-track,
.dx-annc-body .annc-carousel-slide { height: 100%; }
.dx-annc-body .annc-carousel-slide .annc-card,
.dx-annc-body > #anncGridWrap > .annc-carousel-slide > .annc-card { height: 100%; min-height: 0; border-radius: 14px; }
.dx-annc-body .annc-card-body { padding: 14px 16px 34px; }
.dx-annc-body .annc-card-title { font-size: 1rem; margin-bottom: 5px; }
.dx-annc-body .annc-card-text { font-size: 0.78rem; -webkit-line-clamp: 2; }
.dx-annc-body .annc-card-meta { margin-top: 8px; font-size: 0.68rem; }
.dx-annc-body .annc-dots { bottom: 8px; padding: 5px 10px; }
.dx-annc-body .annc-nav-btn { width: 32px; height: 32px; font-size: 0.8rem; }
.dx-annc-body .annc-carousel { border-radius: 14px; }
.dx-annc-body .annc-empty { padding: 24px 14px; height: 100%; display: flex; flex-direction: column; justify-content: center; box-sizing: border-box; }
.dx-annc-body .annc-empty i { font-size: 2rem; margin-bottom: 8px; }
.dx-add-btn {
    border: none; background: linear-gradient(135deg, #2E7D32, #43a047); color: #fff;
    padding: 6px 12px; border-radius: 16px; font-size: 0.72rem; font-weight: 700; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 3px 10px rgba(46,125,50,0.25);
}
.dx-add-btn:hover { transform: translateY(-1px); }
@media (hover: none) {
    .annc-card-actions { opacity: 1; transform: none; }
}

/* recent requests */
.dx-recent-list { flex: 1; min-height: 0; overflow-y: auto; margin: 0 -6px; padding: 0 6px; }
.dx-card .activity-item { padding: 9px 10px; }
.dx-card .activity-list { max-height: none; padding: 0; }

/* ── tablet ── */
@media (min-width: 700px) {
    .dx-kpis { grid-template-columns: repeat(4, 1fr); }
    .dx-grid { grid-template-columns: repeat(2, 1fr); }
    .dx-trend { grid-column: span 2; }
    .dx-chart { height: 280px; }
}
/* ── desktop: fits one screen (no page scrolling) ── */
@media (min-width: 1200px) {
    .dx { height: calc(100dvh - 150px); min-height: 700px; }
    .dx-kpis { grid-template-columns: repeat(8, 1fr); flex-shrink: 0; }
    .dx-kpi { flex-direction: column; align-items: flex-start; gap: 8px; padding: 10px 12px; }
    .dx-kpi-ic { width: 32px; height: 32px; font-size: 0.88rem; }
    .dx-top { flex-shrink: 0; }
    .dx-grid { flex: 1; min-height: 0; grid-template-columns: repeat(12, 1fr); grid-template-rows: 1fr 1fr; }
    .dx-trend  { grid-column: span 5; }
    .dx-status { grid-column: span 3; }
    .dx-cal    { grid-column: span 4; grid-row: span 2; }
    .dx-annc   { grid-column: span 4; }
    .dx-recent { grid-column: span 4; }
    .dx-chart { flex: 1; height: auto; }
    .dx-annc-body { min-height: 0; }
}
@media (max-width: 480px) {
    .dx-top-btn span { display: none; }
    .dx-top-btn { padding: 9px 12px; }
    .dx-cal-title { min-width: 96px; }
}

  </style>
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
const DX = <?php echo $dxJson ?: '{}'; ?>;

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


// ═══════════════════════════════════════════════════════════
// ADMIN DASHBOARD — KPIs, charts, calendar, announcements
// ═══════════════════════════════════════════════════════════
function renderDashboardDefault() {
    const kpi = (icon, bg, fg, num, label, go, id) => `
        <div class="dx-kpi" ${go ? `data-go="${go}" onclick="dxGo('${go}')"` : ''}>
            <div class="dx-kpi-ic" style="background:${bg};color:${fg};"><i class="fas ${icon}"></i></div>
            <div><div class="dx-kpi-num" ${id ? `id="${id}"` : ''}>${num}</div><div class="dx-kpi-lbl">${label}</div></div>
        </div>`;
    const K = DX.kpi || {};

    return `
    <div class="dx">

        <!-- TOP BAR -->
        <div class="dx-top">
            <div class="dx-top-text">
                <h2><span id="dxGreeting">Welcome</span>, Admin!</h2>
                <p><i class="fas fa-calendar-alt"></i><span id="currentDate"></span></p>
            </div>
            <div class="dx-top-actions">
                <button class="dx-top-btn" onclick="dxGo('verify')"><i class="fas fa-user-check"></i><span>Verify</span></button>
                <button class="dx-top-btn" onclick="dxGo('requests')"><i class="fas fa-clipboard-list"></i><span>Requests</span></button>
                <button class="dx-top-btn" onclick="dxGo('bookings')"><i class="fas fa-calendar-check"></i><span>Bookings</span></button>
                <button class="dx-top-btn" onclick="openAnnouncementModal()"><i class="fas fa-bullhorn"></i><span>Announce</span></button>
            </div>
        </div>

        <!-- KPI STRIP -->
        <div class="dx-kpis">
            ${kpi('fa-users',          '#e3f2fd', '#1565c0', dashboardCounts.total,      'Residents',        'residents')}
            ${kpi('fa-user-clock',     '#e0f2f1', '#00897b', dashboardCounts.unverified, 'To Verify',        'verify')}
            ${kpi('fa-clock',          '#fff3e0', '#e65100', K.pendingRequests || 0,     'Pending Requests', 'requests')}
            ${kpi('fa-calendar-day',   '#f3e5f5', '#9c27b0', K.todayRequests || 0,       'Requests Today',   'requests')}
            ${kpi('fa-hourglass-half', '#e8f5e9', '#2e7d32', '-',                        'Pending Bookings', 'bookings', 'dxKpiBookings')}
            ${kpi('fa-tools',          '#fff8e1', '#f57c00', '-',                        'Equip. Available', 'equipment', 'dxKpiEquip')}
            ${kpi('fa-clipboard-check','#fce4ec', '#ad1457', K.casesToReview || 0,       'Cases to Review', '')}
            ${kpi('fa-exclamation-triangle','#ffebee','#c62828', K.escalated || 0,       'Escalated Cases',  '')}
        </div>

        <!-- MAIN GRID -->
        <div class="dx-grid">

            <!-- Trend / Top documents / New residents -->
            <div class="dx-card dx-trend">
                <div class="dx-card-h">
                    <h4><i class="fas fa-chart-line"></i> <span id="dxTrendTitle">6-Month Trend</span></h4>
                    <span id="dxDelta"></span>
                    <div class="dx-seg">
                        <button class="active" onclick="dxSetTrend('trend', this)">Trend</button>
                        <button onclick="dxSetTrend('top', this)">Top Documents</button>
                        <button onclick="dxSetTrend('residents', this)">Residents</button>
                    </div>
                </div>
                <div class="dx-chart"><canvas id="dxTrendCanvas"></canvas></div>
            </div>

            <!-- Status doughnut: documents / cases / equipment -->
            <div class="dx-card dx-status">
                <div class="dx-card-h">
                    <h4><i class="fas fa-chart-pie"></i> Status</h4>
                    <div class="dx-seg">
                        <button class="active" onclick="dxSetStatus('docs', this)">Docs</button>
                        <button onclick="dxSetStatus('cases', this)">Cases</button>
                        <button onclick="dxSetStatus('equip', this)">Equip.</button>
                    </div>
                </div>
                <div class="dx-chart"><canvas id="dxStatusCanvas"></canvas></div>
            </div>

            <!-- Calendar -->
            <div class="dx-card dx-cal">
                <div class="dx-card-h">
                    <h4><i class="fas fa-calendar-alt"></i> Calendar</h4>
                    <div class="dx-cal-nav">
                        <button onclick="dxCalMove(-1)" aria-label="Previous month"><i class="fas fa-chevron-left"></i></button>
                        <div class="dx-cal-title" id="dxCalTitle"></div>
                        <button onclick="dxCalMove(1)" aria-label="Next month"><i class="fas fa-chevron-right"></i></button>
                        <button class="today" onclick="dxCalToday()">Today</button>
                    </div>
                </div>
                <div class="dx-cal-wrap">
                    <div class="dx-cal-dow"><div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div></div>
                    <div class="dx-cal-grid" id="dxCalGrid"></div>
                    <div class="dx-legend">
                        <span><i class="dx-dot hearing"></i> Hearings</span>
                        <span><i class="dx-dot pickup"></i> Document pickups</span>
                    </div>
                    <div class="dx-agenda" id="dxAgenda"></div>
                </div>
            </div>

            <!-- Announcements (add / edit / pin / delete) -->
            <div class="dx-card dx-annc">
                <div class="dx-card-h">
                    <h4><i class="fas fa-bullhorn" style="color:#e65100;"></i> Announcements</h4>
                    <button class="dx-add-btn" onclick="openAnnouncementModal()"><i class="fas fa-plus"></i> New</button>
                </div>
                <div class="dx-annc-body">
                    <div id="anncGridWrap">
                        <div style="text-align:center;padding:30px;color:#999;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
                    </div>
                </div>
            </div>

            <!-- Recent: requests / bookings -->
            <div class="dx-card dx-recent">
                <div class="dx-card-h">
                    <h4><i class="fas fa-file-alt"></i> <span id="dxRecentTitle">Recent Requests</span></h4>
                    <div class="dx-seg">
                        <button class="active" onclick="dxSetRecent('requests', this)">Requests</button>
                        <button onclick="dxSetRecent('bookings', this)">Bookings</button>
                    </div>
                    <button class="view-all-btn" onclick="dxRecentViewAll()">View all <i class="fas fa-arrow-right"></i></button>
                </div>
                <div class="dx-recent-list activity-list" id="dxRecentList">
                    <div class="loading-spinner-mini"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                </div>
            </div>

        </div>
    </div>`;
}

let __dxCharts = {};
let __dxTrendTab = 'trend';
let __dxStatusTab = 'docs';
let __dxRecentTab = 'requests';
const __dxToday = new Date();
let __dxCal = { y: __dxToday.getFullYear(), m: __dxToday.getMonth(), sel: null };

const DX_DOC_STATUS = [
    { key: 'pending',   label: 'Pending',   color: '#f57c00' },
    { key: 'approved',  label: 'Approved',  color: '#43a047' },
    { key: 'unclaimed', label: 'Unclaimed', color: '#ffca28' },
    { key: 'claimed',   label: 'Claimed',   color: '#1976d2' },
    { key: 'rejected',  label: 'Rejected',  color: '#e53935' },
];
const DX_CASE_STAGES = [
    { label: 'Under Review',       color: '#f57c00', st: ['pending_review', 'pending_captain_action'] },
    { label: 'Mediation',          color: '#6a1b9a', st: ['summoned', 'for_mediation', 'mediation_scheduled', 'in_mediation'] },
    { label: 'Pangkat',            color: '#00838f', st: ['failed_mediation', 'pangkat_constitution_scheduled', 'pangkat_constituted', 'pangkat_scheduled'] },
    { label: 'Settlement Pending', color: '#66bb6a', st: ['settled_pending'] },
    { label: 'Resolved',           color: '#1b5e20', st: ['settled', 'certificate_issued'] },
    { label: 'Unresolved',         color: '#b71c1c', st: ['unresolved', 'failed_conciliation_final'] },
    { label: 'Referred',           color: '#8e24aa', st: ['escalated', 'pending_captain_review', 'referred_dispatched', 'released', 'referred'] },
    { label: 'Dismissed',          color: '#78909c', st: ['dismissed'] },
];

function dxPad(n) { return String(n).padStart(2, '0'); }
function dxYmd(y, m, d) { return `${y}-${dxPad(m + 1)}-${dxPad(d)}`; }
function dxDestroy(key) { if (__dxCharts[key]) { try { __dxCharts[key].destroy(); } catch (e) {} delete __dxCharts[key]; } }

// Quick-links used by KPI cards, top buttons and "View all"
function dxGo(target) {
    switch (target) {
        case 'verify':    goToUnverifiedAccounts(); break;
        case 'requests':  goToDocumentRequests(); break;
        case 'bookings':  goToEquipmentBookings(); break;
        case 'equipment': goToEquipmentList(); break;
        case 'residents': {
            const p = document.getElementById('accountsParent');
            const sub = document.getElementById('accountsSubmenu');
            if (p && sub && !sub.classList.contains('open')) { sub.classList.add('open'); const ic = p.querySelector('.toggle-icon'); if (ic) ic.style.transform = 'rotate(180deg)'; }
            const o = document.querySelector('#accountsSubmenu .sub-option[data-subview="verified"]');
            if (o) o.click();
            break;
        }
    }
}

function initAdminDashboard() {
    displayCurrentDate();
    loadAnnouncements();
    initDashboardWidgets();
    dxLoadRecent();
    dxLoadEquipment();
}

function initDashboardWidgets() {
    Object.keys(__dxCharts).forEach(dxDestroy);
    __dxTrendTab = 'trend';
    __dxStatusTab = 'docs';
    __dxRecentTab = 'requests';
    __dxCal = { y: __dxToday.getFullYear(), m: __dxToday.getMonth(),
                sel: dxYmd(__dxToday.getFullYear(), __dxToday.getMonth(), __dxToday.getDate()) };

    const g = document.getElementById('dxGreeting');
    if (g) {
        const h = new Date().getHours();
        g.textContent = h < 12 ? 'Good morning' : (h < 18 ? 'Good afternoon' : 'Good evening');
    }
    if (typeof Chart === 'undefined') {
        document.querySelectorAll('.dx-chart').forEach(el => {
            el.innerHTML = '<div class="dx-empty">Charts could not load (Chart.js unavailable).</div>';
        });
    } else {
        Chart.defaults.font.family = "'Segoe UI', Roboto, sans-serif";
        Chart.defaults.color = '#5f6f66';
        dxRenderTrend();
        dxRenderStatus();
    }
    dxRenderCalendar();
}

function dxSetSeg(btn) {
    if (!btn) return;
    btn.parentElement.querySelectorAll('button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}
function dxSetTrend(tab, btn)  { dxSetSeg(btn); __dxTrendTab = tab;  dxRenderTrend(); }
function dxSetStatus(tab, btn) { dxSetSeg(btn); __dxStatusTab = tab; dxRenderStatus(); }

function dxDeltaHtml(arr, noun) {
    const cur = arr[arr.length - 1] || 0, prev = arr[arr.length - 2] || 0;
    let cls = 'flat', txt = 'No change vs last month', ic = 'fa-minus';
    if (prev > 0) {
        const pct = Math.round(((cur - prev) / prev) * 100);
        if (pct > 0)      { cls = '';     ic = 'fa-arrow-up';   txt = `${pct}% vs last month`; }
        else if (pct < 0) { cls = 'down'; ic = 'fa-arrow-down'; txt = `${Math.abs(pct)}% vs last month`; }
    } else if (cur > 0) { cls = ''; ic = 'fa-arrow-up'; txt = `${cur} new this month`; }
    return `<span class="dx-delta ${cls}"><i class="fas ${ic}"></i> ${noun} ${txt}</span>`;
}

// ── Trend / Top documents / New residents ──
function dxRenderTrend() {
    const el = document.getElementById('dxTrendCanvas');
    if (!el || typeof Chart === 'undefined') return;
    dxDestroy('trend');

    const title = document.getElementById('dxTrendTitle');
    const deltaEl = document.getElementById('dxDelta');
    const docs = DX.docMonthly || [], comps = DX.complaintMonthly || [], res = DX.residentMonthly || [];
    const tip = { backgroundColor: '#1a2b22', padding: 10, cornerRadius: 8 };

    if (__dxTrendTab === 'trend') {
        if (title) title.textContent = '6-Month Trend';
        if (deltaEl) deltaEl.innerHTML = dxDeltaHtml(docs, 'Requests');
        const grad = (hex) => (ctx) => {
            const a = ctx.chart.chartArea;
            if (!a) return hex + '33';
            const g = ctx.chart.ctx.createLinearGradient(0, a.top, 0, a.bottom);
            g.addColorStop(0, hex + '55'); g.addColorStop(1, hex + '05');
            return g;
        };
        const ds = (label, data, hex) => ({ label, data, borderColor: hex, backgroundColor: grad(hex), fill: true, tension: 0.4,
            borderWidth: 3, pointRadius: 4, pointBackgroundColor: '#fff', pointBorderWidth: 2, pointHoverRadius: 6 });
        __dxCharts.trend = new Chart(el, {
            type: 'line',
            data: { labels: DX.months || [], datasets: [
                ds('Document requests', docs, '#2E7D32'),
                ds('Complaints', comps, '#8e24aa'),
                ds('New residents', res, '#1976d2'),
            ] },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, font: { size: 11 } } }, tooltip: tip },
                scales: { x: { grid: { display: false } },
                          y: { beginAtZero: true, ticks: { precision: 0, maxTicksLimit: 5 }, grid: { color: '#eef2f0' }, border: { display: false } } }
            }
        });
    } else if (__dxTrendTab === 'residents') {
        if (title) title.textContent = 'New Residents';
        if (deltaEl) deltaEl.innerHTML = dxDeltaHtml(res, 'Sign-ups');
        __dxCharts.trend = new Chart(el, {
            type: 'bar',
            data: { labels: DX.months || [], datasets: [{ label: 'New residents', data: res, backgroundColor: '#1976d2',
                    borderRadius: 8, borderSkipped: false, maxBarThickness: 36 }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: tip },
                scales: { x: { grid: { display: false } },
                          y: { beginAtZero: true, ticks: { precision: 0, maxTicksLimit: 5 }, grid: { color: '#eef2f0' }, border: { display: false } } }
            }
        });
    } else {
        if (title) title.textContent = 'Most Requested Documents';
        if (deltaEl) deltaEl.innerHTML = '';
        const top = DX.topDocs || [];
        const palette = ['#1b5e20', '#2E7D32', '#43a047', '#66bb6a', '#81c784', '#a5d6a7'];
        __dxCharts.trend = new Chart(el, {
            type: 'bar',
            data: { labels: top.map(t => t.name), datasets: [{ label: 'Requests', data: top.map(t => t.count),
                    backgroundColor: top.map((_, i) => palette[i % palette.length]), borderRadius: 8, borderSkipped: false,
                    barThickness: 'flex', maxBarThickness: 26 }] },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: tip },
                scales: { x: { beginAtZero: true, ticks: { precision: 0, maxTicksLimit: 5 }, grid: { color: '#eef2f0' }, border: { display: false } },
                          y: { grid: { display: false } } }
            }
        });
    }
}

// ── Status doughnut ──
const dxCenterPlugin = {
    id: 'dxCenter',
    afterDraw(chart) {
        const meta = chart.getDatasetMeta(0);
        if (!meta || !meta.data || !meta.data.length) return;
        const total = (chart.data.datasets[0].data || []).reduce((a, b) => a + b, 0);
        const { x, y } = meta.data[0];
        const c = chart.ctx;
        c.save();
        c.textAlign = 'center'; c.textBaseline = 'middle';
        c.fillStyle = '#1a2b22'; c.font = "800 26px 'Segoe UI', Roboto, sans-serif";
        c.fillText(total, x, y - 6);
        c.fillStyle = '#7a8a82'; c.font = "700 10px 'Segoe UI', Roboto, sans-serif";
        c.fillText('TOTAL', x, y + 15);
        c.restore();
    }
};

function dxRenderStatus() {
    const el = document.getElementById('dxStatusCanvas');
    if (!el || typeof Chart === 'undefined') return;
    dxDestroy('status');

    let labels = [], data = [], colors = [];
    if (__dxStatusTab === 'docs') {
        DX_DOC_STATUS.forEach(s => { labels.push(s.label); data.push((DX.docStatus || {})[s.key] || 0); colors.push(s.color); });
    } else if (__dxStatusTab === 'cases') {
        const counts = {}; let other = 0;
        Object.entries(DX.complaintStatus || {}).forEach(([st, n]) => {
            const idx = DX_CASE_STAGES.findIndex(s => s.st.includes(st));
            if (idx < 0) other += n; else counts[idx] = (counts[idx] || 0) + n;
        });
        DX_CASE_STAGES.forEach((s, i) => { if (counts[i]) { labels.push(s.label); data.push(counts[i]); colors.push(s.color); } });
        if (other) { labels.push('Other'); data.push(other); colors.push('#b0bec5'); }
    } else {
        const e = DX.equip;
        if (e) {
            [['Available', e.available, '#43a047'], ['Borrowed', e.borrowed, '#1976d2'], ['Maintenance', e.maintenance, '#f57c00']]
                .forEach(([l, v, c]) => { labels.push(l); data.push(Number(v) || 0); colors.push(c); });
        }
    }
    const empty = data.reduce((a, b) => a + b, 0) === 0;
    if (empty) { labels = ['No data']; data = [1]; colors = ['#e5ece8']; }

    __dxCharts.status = new Chart(el, {
        type: 'doughnut',
        data: { labels, datasets: [{ data, backgroundColor: colors, borderColor: '#fff', borderWidth: 3, hoverOffset: 6 }] },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '68%',
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 10, font: { size: 11 } } },
                tooltip: { enabled: !empty, backgroundColor: '#1a2b22', padding: 10, cornerRadius: 8 }
            }
        },
        plugins: [dxCenterPlugin]
    });
}

// ── Equipment numbers (KPI cards + Equip. doughnut) ──
async function dxLoadEquipment() {
    try {
        const r = await fetch('equipment_ajax.php?action=get_counts');
        const d = await r.json();
        if (!d.success) return;
        DX.equip = {
            total: d.counts.total || 0, available: d.counts.available || 0,
            borrowed: d.counts.borrowed || 0, maintenance: d.counts.maintenance || 0,
            pendingBookings: d.pending_bookings || 0
        };
        const b = document.getElementById('dxKpiBookings'); if (b) b.textContent = DX.equip.pendingBookings;
        const a = document.getElementById('dxKpiEquip');    if (a) a.textContent = DX.equip.available;
        if (__dxStatusTab === 'equip') dxRenderStatus();
    } catch (e) { console.error('Error loading equipment stats:', e); }
}

// ── Recent requests / bookings ──
function dxSetRecent(tab, btn) { dxSetSeg(btn); __dxRecentTab = tab; dxLoadRecent(); }
function dxRecentViewAll() { dxGo(__dxRecentTab === 'bookings' ? 'bookings' : 'requests'); }

function dxAgo(dateString) {
    const diff = Math.floor((new Date() - new Date(dateString)) / 86400000);
    if (diff <= 0) return 'Today';
    if (diff === 1) return 'Yesterday';
    return `${diff} days ago`;
}

async function dxLoadRecent() {
    const c = document.getElementById('dxRecentList');
    const t = document.getElementById('dxRecentTitle');
    if (!c) return;
    const tab = __dxRecentTab;
    if (t) t.textContent = tab === 'bookings' ? 'Recent Bookings' : 'Recent Requests';
    c.innerHTML = '<div class="loading-spinner-mini"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    try {
        if (tab === 'bookings') {
            const r = await fetch('equipment_ajax.php?action=get_recent_bookings&limit=6');
            const d = await r.json();
            if (tab !== __dxRecentTab) return;
            if (d.success && d.bookings && d.bookings.length) {
                c.innerHTML = d.bookings.map(b => `
                    <div class="activity-item" onclick="viewBookingDetails(${b.id})">
                        <div class="activity-info">
                            <div class="activity-title">${escapeHtml(b.equipment_name || b.item_name || '')}</div>
                            <div class="activity-detail">${escapeHtml(b.first_name || '')} ${escapeHtml(b.last_name || '')} · ${b.duration_days || 1} day(s)</div>
                        </div>
                        <div class="activity-status status-${escapeHtml(b.status)}">${escapeHtml(b.status)}</div>
                    </div>`).join('');
            } else c.innerHTML = '<div class="empty-state-mini"><p>No recent bookings</p></div>';
        } else {
            const r = await fetch('?dashboard_action=get_recent_documents&limit=6');
            const d = await r.json();
            if (tab !== __dxRecentTab) return;
            if (d.success && d.requests && d.requests.length) {
                c.innerHTML = d.requests.map(q => `
                    <div class="activity-item" onclick="viewRequestDetails(${q.id})">
                        <div class="activity-info">
                            <div class="activity-title">${escapeHtml(q.document_type)}</div>
                            <div class="activity-detail">${escapeHtml(q.first_name)} ${escapeHtml(q.last_name)} · ${dxAgo(q.request_date)}</div>
                        </div>
                        <div class="activity-status status-${escapeHtml(q.status)}">${escapeHtml(q.status)}</div>
                    </div>`).join('');
            } else c.innerHTML = '<div class="empty-state-mini"><p>No recent requests</p></div>';
        }
    } catch (e) {
        c.innerHTML = '<div class="empty-state-mini"><p>Unable to load data</p></div>';
    }
}

// ── Calendar ──
function dxEventsMap() {
    const map = {};
    (DX.events || []).forEach(e => { (map[e.date] = map[e.date] || []).push(e); });
    return map;
}
function dxFmtTime(t) {
    if (!t) return 'All day';
    const m = String(t).match(/^(\d{1,2}):(\d{2})/);
    if (!m) return escapeHtml(String(t));
    let h = parseInt(m[1], 10); const ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return `${h}:${m[2]} ${ap}`;
}
function dxCalMove(delta) {
    let m = __dxCal.m + delta, y = __dxCal.y;
    if (m < 0) { m = 11; y--; } else if (m > 11) { m = 0; y++; }
    __dxCal.m = m; __dxCal.y = y;
    dxRenderCalendar();
}
function dxCalToday() {
    __dxCal = { y: __dxToday.getFullYear(), m: __dxToday.getMonth(),
                sel: dxYmd(__dxToday.getFullYear(), __dxToday.getMonth(), __dxToday.getDate()) };
    dxRenderCalendar();
}
function dxCalSelect(ds) { __dxCal.sel = ds; dxRenderCalendar(); }

function dxRenderCalendar() {
    const grid = document.getElementById('dxCalGrid');
    const title = document.getElementById('dxCalTitle');
    if (!grid) return;
    const { y, m } = __dxCal;
    const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    if (title) title.textContent = `${monthNames[m]} ${y}`;

    const first = new Date(y, m, 1).getDay();
    const days = new Date(y, m + 1, 0).getDate();
    const rows = Math.ceil((first + days) / 7);
    const map = dxEventsMap();
    const todayStr = dxYmd(__dxToday.getFullYear(), __dxToday.getMonth(), __dxToday.getDate());

    let html = '';
    for (let i = 0; i < first; i++) html += '<div class="dx-day blank"></div>';
    for (let d = 1; d <= days; d++) {
        const ds = dxYmd(y, m, d);
        const evs = map[ds] || [];
        const dots = [...new Set(evs.map(e => e.kind))].map(k => `<i class="dx-dot ${k}"></i>`).join('');
        html += `<button type="button" class="dx-day ${ds === todayStr ? 'today' : ''} ${ds === __dxCal.sel ? 'sel' : ''}"
                    onclick="dxCalSelect('${ds}')" title="${evs.length ? evs.length + ' event(s)' : ''}">
                    <span>${d}</span><span class="dx-dots">${dots}</span></button>`;
    }
    const trailing = rows * 7 - (first + days);
    for (let i = 0; i < trailing; i++) html += '<div class="dx-day blank"></div>';
    grid.style.gridTemplateRows = `repeat(${rows}, minmax(32px, 1fr))`;
    grid.innerHTML = html;
    dxRenderAgenda(map);
}

// Hearings have no detail screen in the admin panel, so show a small summary.
function dxShowHearing(id) {
    const e = (DX.events || []).find(x => x.kind === 'hearing' && Number(x.id) === Number(id));
    if (!e) return;
    Swal.fire({
        icon: 'info', title: escapeHtml(e.title || 'Hearing'),
        html: `<div style="text-align:left;font-size:0.9rem;line-height:1.7;">
            <div><b>Reference:</b> ${escapeHtml(e.sub || '-')}</div>
            <div><b>Date:</b> ${escapeHtml(new Date(e.date + 'T00:00:00').toLocaleDateString('en-US', { dateStyle: 'long' }))}</div>
            <div><b>Time:</b> ${dxFmtTime(e.time)}</div>
            <div><b>Status:</b> ${escapeHtml(String(e.status || '').replace(/_/g, ' '))}</div></div>`,
        confirmButtonColor: '#2E7D32'
    });
}

function dxEventHtml(e, showDate) {
    const click = e.kind === 'hearing' ? `dxShowHearing(${e.id})` : `viewRequestDetails(${e.id})`;
    const when = showDate
        ? new Date(e.date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
        : dxFmtTime(e.time);
    const label = e.kind === 'hearing' ? 'Hearing' : 'Pickup';
    return `<div class="dx-ev ${e.kind}" onclick="${click}">
        <div class="dx-ev-time">${when}</div>
        <div class="dx-ev-body">
            <div class="dx-ev-title">${escapeHtml(label)}: ${escapeHtml(e.title || '')}</div>
            <div class="dx-ev-sub">${escapeHtml(e.sub || '')}</div>
        </div></div>`;
}

function dxRenderAgenda(map) {
    const box = document.getElementById('dxAgenda');
    if (!box) return;
    const sel = __dxCal.sel;
    const evs = (map[sel] || []).slice().sort((a, b) => String(a.time || '').localeCompare(String(b.time || '')));
    const niceDate = sel ? new Date(sel + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' }) : '';

    let html = `<div class="dx-agenda-h">${escapeHtml(niceDate)}</div>`;
    if (evs.length) {
        html += evs.map(e => dxEventHtml(e, false)).join('');
    } else {
        html += '<div class="dx-empty">Nothing scheduled on this day.</div>';
        const upcoming = (DX.events || []).filter(e => e.date > sel)
            .sort((a, b) => a.date.localeCompare(b.date) || String(a.time || '').localeCompare(String(b.time || '')))
            .slice(0, 2);
        if (upcoming.length) {
            html += '<div class="dx-agenda-h" style="margin-top:6px;">Up next</div>' + upcoming.map(e => dxEventHtml(e, true)).join('');
        }
    }
    box.innerHTML = html;
}


function timeAgo(dt) {
    if (!dt) return '';
    const then = new Date(dt.replace(' ', 'T'));
    const secs = Math.floor((Date.now() - then.getTime()) / 1000);
    if (secs < 60) return 'just now';
    if (secs < 3600) return Math.floor(secs/60) + 'm ago';
    if (secs < 86400) return Math.floor(secs/3600) + 'h ago';
    if (secs < 604800) return Math.floor(secs/86400) + 'd ago';
    return then.toLocaleDateString();
}

// ═══════════════════════════════════════════════════════════
// ANNOUNCEMENTS MODULE
// ═══════════════════════════════════════════════════════════
const ANN_CATEGORIES = {
    general:  { label: 'General',  icon: 'fa-bullhorn' },
    urgent:   { label: 'Urgent',   icon: 'fa-exclamation-circle' },
    event:    { label: 'Event',    icon: 'fa-calendar-check' },
    advisory: { label: 'Advisory', icon: 'fa-info-circle' },
    health:   { label: 'Health',   icon: 'fa-heartbeat' },
    safety:   { label: 'Safety',   icon: 'fa-shield-alt' },
};

function renderAnnouncementsSection() {
    return `
        <div class="annc-section" id="anncSection">
            <div class="annc-section-head">
                <div class="annc-section-title">
                    <i class="fas fa-bullhorn"></i> Announcements
                </div>
                <button class="annc-add-btn" onclick="openAnnouncementModal()">
                    <i class="fas fa-plus"></i> New Announcement
                </button>
            </div>
            <div id="anncGridWrap">
                <div style="text-align:center;padding:40px;color:#999;">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p style="margin-top:10px;">Loading announcements…</p>
                </div>
            </div>
        </div>
    `;
}

async function loadAnnouncements() {
    const wrap = document.getElementById('anncGridWrap');
    if (!wrap) return;
    try {
        const r = await fetch(`?ann_action=list`, { cache: 'no-store' });
        const d = await r.json();
        if (!d.success) {
            wrap.innerHTML = `<div class="annc-empty">
                <i class="fas fa-exclamation-triangle"></i>
                <h4>Could not load announcements</h4>
                <p>${escapeHtml(d.message || 'Try again later.')}</p>
            </div>`;
            return;
        }
        renderAnnouncementGrid(d.data);

        if (d.auto_deactivated_count > 0) {
            console.log(`[announcements] ${d.auto_deactivated_count} expired announcement(s) auto-deactivated.`);
        }
        scheduleNextExpiryRefresh(d.data);
    } catch (e) {
        wrap.innerHTML = `<div class="annc-empty">
            <i class="fas fa-exclamation-triangle"></i>
            <h4>Network error</h4>
            <p>Could not reach the server.</p>
        </div>`;
    }
}

function renderAnnouncementGrid(items) {
    const wrap = document.getElementById('anncGridWrap');
    if (!wrap) return;

    if (!items.length) {
        wrap.innerHTML = `
            <div class="annc-empty">
                <i class="fas fa-bullhorn"></i>
                <h4>No announcements yet</h4>
                <p>Click <b>New Announcement</b> above to post the first one.</p>
            </div>`;
        return;
    }

    // ── Single announcement → plain card, no carousel chrome ──
    if (items.length === 1) {
        window.__anncItems = items;
        window.__anncIndex = 0;
        window.__anncFocusId = null;
        wrap.innerHTML = renderAnnouncementCard(items[0]);
        return;
    }
    // ── Multiple → carousel ──
    window.__anncItems = items;
    window.__anncIndex = 0;

    wrap.innerHTML = `
        <div class="annc-carousel">
            <div class="annc-carousel-track" id="anncTrack">
                ${items.map((it) => renderAnnouncementCard(it)).join('')}
            </div>

            <button type="button" class="annc-nav-btn prev" onclick="anncPrev()" aria-label="Previous">
                <i class="fas fa-chevron-left"></i>
            </button>
            <button type="button" class="annc-nav-btn next" onclick="anncNext()" aria-label="Next">
                <i class="fas fa-chevron-right"></i>
            </button>

            <div class="annc-dots" id="anncDots">
                ${items.map((_, i) => `
                    <button type="button" class="annc-dot ${i === 0 ? 'active' : ''}"
                            onclick="anncGoTo(${i})" aria-label="Go to slide ${i+1}"></button>
                `).join('')}
            </div>
        </div>`;

    // Auto-advance every 6s
    clearInterval(window.__anncAutoTimer);
    window.__anncAutoTimer = setInterval(anncNext, 6000);

    // Find the slide we should jump to (the one just toggled) — fall back to slide 0
    let targetIndex = 0;
    if (window.__anncFocusId != null) {
        const idx = items.findIndex(x => Number(x.id) === Number(window.__anncFocusId));
        if (idx >= 0) targetIndex = idx;
        window.__anncFocusId = null;
    }

    const track = document.getElementById('anncTrack');
    if (track) track.style.transform = `translateX(-${targetIndex * 100}%)`;
    window.__anncIndex = targetIndex;
    document.querySelectorAll('#anncDots .annc-dot').forEach((dot, i) => {
        dot.classList.toggle('active', i === targetIndex);
    });
}
function anncGoTo(index) {
    const items = window.__anncItems || [];
    if (!items.length) return;

    // Wrap index
    index = (index + items.length) % items.length;
    window.__anncIndex = index;

    const track = document.getElementById('anncTrack');
    if (track) {
        track.style.transform = `translateX(-${index * 100}%)`;
    }

    document.querySelectorAll('#anncDots .annc-dot').forEach((dot, i) => {
        dot.classList.toggle('active', i === index);
    });

    // Reset the auto timer on manual navigation
    clearInterval(window.__anncAutoTimer);
    window.__anncAutoTimer = setInterval(anncNext, 6000);
}

function anncNext() {
    const items = window.__anncItems || [];
    if (items.length < 2) return;
    anncGoTo((window.__anncIndex || 0) + 1);
}

function anncPrev() {
    const items = window.__anncItems || [];
    if (items.length < 2) return;
    anncGoTo((window.__anncIndex || 0) - 1);
}
function renderAnnouncementCard(a) {
    const catMeta = ANN_CATEGORIES[a.category] || ANN_CATEGORIES.general;

    let expiryBadge = '';
    if (a.is_expired) {
        expiryBadge = `<span class="annc-badge expiry urgent"><i class="fas fa-clock"></i> Expired</span>`;
    } else if (a.expires_at) {
        const ts = new Date(a.expires_at.replace(' ', 'T')).getTime();
        const msLeft = ts - Date.now();
        const daysLeft = Math.floor(msLeft / 86400000);
        const niceDate = new Date(ts).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });

        if (daysLeft <= 0) {
            expiryBadge = `<span class="annc-badge expiry urgent"><i class="fas fa-exclamation-triangle"></i> Expires today</span>`;
        } else if (daysLeft <= 3) {
            expiryBadge = `<span class="annc-badge expiry soon"><i class="fas fa-hourglass-half"></i> ${daysLeft}d left</span>`;
        } else {
            expiryBadge = `<span class="annc-badge expiry"><i class="fas fa-calendar-check"></i> Until ${escapeHtml(niceDate)}</span>`;
        }
    } else {
        expiryBadge = `<span class="annc-badge expiry"><i class="fas fa-infinity"></i> No expiry</span>`;
    }

    const isExpired = !!a.is_expired;
    const imageStyle = a.image_url
        ? `background-image:url('../${escapeHtml(a.image_url)}')`
        : '';

    // .annc-carousel-slide is the outer 100%-width wrapper used by the carousel track
    return `
    <div class="annc-carousel-slide">
        <div class="annc-card" style="${isExpired ? 'opacity:0.72;' : ''}">
            <div class="annc-card-image ${a.image_url ? '' : 'fallback'}" style="${imageStyle}"></div>

            <div class="annc-card-top">
                <span class="annc-badge ${a.category}">
                    <i class="fas ${catMeta.icon}"></i> ${catMeta.label}
                </span>
                ${a.is_pinned ? `<span class="annc-badge pinned"><i class="fas fa-thumbtack"></i> Pinned</span>` : ''}
                ${!a.is_active ? `<span class="annc-badge draft"><i class="fas fa-eye-slash"></i> Inactive</span>` : ''}
                ${a.event_date ? `
                    <span class="annc-badge" style="background:#2E7D32;border-color:#2E7D32;">
                        <i class="fas fa-calendar-check"></i>
                        ${escapeHtml(new Date(a.event_date + 'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric'}))}
                        ${a.event_time ? ' · ' + escapeHtml((() => {
                            const [h,m] = a.event_time.split(':');
                            let hh = parseInt(h,10);
                            const ap = hh >= 12 ? 'PM' : 'AM';
                            hh = hh % 12 || 12;
                            return hh + ':' + m + ' ' + ap;
                        })()) : ''}
                    </span>
                ` : ''}
                ${expiryBadge}
            </div>

            <div class="annc-card-actions" onclick="event.stopPropagation()">
                <button class="annc-icon-btn" title="Edit" onclick="openAnnouncementModal(${a.id})">
                    <i class="fas fa-pen"></i>
                </button>
                <button class="annc-icon-btn" title="${a.is_pinned ? 'Unpin' : 'Pin'}"
                        onclick="toggleAnnouncement(${a.id}, 'is_pinned')">
                    <i class="fas fa-thumbtack"></i>
                </button>
                <button class="annc-icon-btn" title="${a.is_active ? 'Deactivate' : 'Activate'}"
                        onclick="toggleAnnouncement(${a.id}, 'is_active')">
                    <i class="fas ${a.is_active ? 'fa-eye' : 'fa-eye-slash'}"></i>
                </button>
                <button class="annc-icon-btn danger" title="Delete"
                        onclick="deleteAnnouncement(${a.id})">
                    <i class="fas fa-trash"></i>
                </button>
            </div>

            <div class="annc-card-body">
                <h3 class="annc-card-title">${escapeHtml(a.title)}</h3>
                <p class="annc-card-text">${escapeHtml(a.body)}</p>
                <div class="annc-card-meta">
                    <span><i class="fas fa-user-tie"></i> ${escapeHtml(a.author || 'Barangay')}</span>
                    <span><i class="fas fa-clock"></i> ${timeAgo(a.created_at)}</span>
                </div>
            </div>
        </div>
    </div>`;
}

function scheduleNextExpiryRefresh(items) {
    clearTimeout(window.__anncExpiryTimer);
    if (!Array.isArray(items)) return;
    const now = Date.now();
    const upcoming = items
        .filter(a => a.expires_at && new Date(a.expires_at.replace(' ', 'T')).getTime() > now)
        .map(a => new Date(a.expires_at.replace(' ', 'T')).getTime())
        .sort((x, y) => x - y);
    if (!upcoming.length) return;
    const delay = Math.min(upcoming[0] - now + 2000, 3600000);
    window.__anncExpiryTimer = setTimeout(() => loadAnnouncements(), Math.max(delay, 5000));
}

// ═══════════════════════════════════════════════════════════
// MODAL — Add / Edit
// ═══════════════════════════════════════════════════════════
async function openAnnouncementModal(id = null) {
    let a = {
        id: 0, title: '', body: '', category: 'general',
        image_url: null, is_pinned: 0, is_active: 1, expires_at: null,
        event_date: null, event_time: null,
    };
    if (id) {
        try {
            const r = await fetch(`?ann_action=get&id=${id}`);
            const d = await r.json();
            if (d.success) a = d.data;
        } catch (e) {}
    }

    const catOptions = Object.entries(ANN_CATEGORIES).map(([k, v]) =>
        `<option value="${k}" ${a.category === k ? 'selected' : ''}>${v.label}</option>`
    ).join('');

    const expiresValue = a.expires_at ? a.expires_at.replace(' ', 'T').slice(0, 16) : '';

    await Swal.fire({
        title: '',
        html: `
            <div class="annc-modal">
                <div class="annc-modal-header">
                    <i class="fas fa-bullhorn"></i>
                    <div>
                        <h3>${id ? 'Edit Announcement' : 'New Announcement'}</h3>
                        <p>Announcements appear on the Admin and Secretary dashboards until they expire.</p>
                    </div>
                </div>
                <div class="annc-modal-body">
                    <div class="annc-field">
                        <label>Title <span class="req">*</span></label>
                        <input type="text" id="anncTitle" class="annc-input"
                               maxlength="255" value="${escapeHtml(a.title)}"
                               placeholder="e.g. Barangay Assembly on October 20">
                    </div>

                    <div class="annc-field">
                        <label>Message <span class="req">*</span></label>
                        <textarea id="anncBody" class="annc-textarea" rows="5"
                                  placeholder="Full announcement text…">${escapeHtml(a.body)}</textarea>
                    </div>

                    <div class="annc-row-2">
                        <div class="annc-field">
                            <label>Category</label>
                            <select id="anncCategory" class="annc-select">${catOptions}</select>
                        </div>
                        <div class="annc-field">
                            <label>Expiry date <span class="opt">(optional)</span></label>
                            <input type="datetime-local" id="anncExpiresAt" class="annc-input"
                                   value="${escapeHtml(expiresValue)}"
                                   min="${new Date(Date.now() + 60000).toISOString().slice(0, 16)}"
                                   onchange="validateExpiryLive()"
                                   oninput="validateExpiryLive()">
                            <div style="font-size:0.72rem;color:#888;margin-top:6px;">
                                <i class="fas fa-info-circle"></i>
                                The announcement will be auto-deactivated when this time passes.
                                Leave blank for no expiry.
                            </div>
                            <div id="anncExpiryError"
                                 style="display:none;margin-top:8px;padding:8px 12px;
                                        background:#ffebee;border-left:3px solid #c62828;
                                        border-radius:6px;font-size:0.75rem;color:#b71c1c;
                                        align-items:flex-start;gap:6px;line-height:1.45;">
                                <i class="fas fa-exclamation-triangle" style="margin-top:1px;flex-shrink:0;"></i>
                                <span id="anncExpiryErrorText"></span>
                            </div>
                        </div>
                    </div>

                    <!-- ══════════ OPTIONAL EVENT SCHEDULE ══════════ -->
                    <div class="annc-field" style="margin-top:16px;">
                        <label style="display:flex;align-items:center;gap:8px;">
                            <i class="fas fa-calendar-check" style="color:#2E7D32;"></i>
                            Event Schedule <span class="opt">(optional)</span>
                        </label>
                        <div style="background:#e8f5e9;border-left:4px solid #2E7D32;border-radius:8px;
                                    padding:10px 14px;font-size:0.78rem;color:#1b5e20;
                                    margin-bottom:12px;line-height:1.5;">
                            <i class="fas fa-lightbulb"></i>
                            Fill this <b>only if the announcement has a specific date and time</b>
                            (e.g. Relief Goods Distribution). Leave blank for a normal announcement.
                        </div>
                        <div class="annc-row-2">
                            <div class="annc-field" style="margin-bottom:0;">
                                <label style="font-size:0.78rem;">Event Date <span class="opt">(optional)</span></label>
                                <input type="date" id="anncEventDate" class="annc-input"
                                       value="${escapeHtml(a.event_date || '')}"
                                       min="${new Date().toISOString().split('T')[0]}"
                                       onchange="validateEventScheduleLive()"
                                       oninput="validateEventScheduleLive()">
                            </div>
                            <div class="annc-field" style="margin-bottom:0;">
                                <label style="font-size:0.78rem;">Event Time <span class="opt">(optional)</span></label>
                                <input type="time" id="anncEventTime" class="annc-input"
                                       value="${escapeHtml(a.event_time || '')}"
                                       onchange="validateEventScheduleLive()"
                                       oninput="validateEventScheduleLive()">
                            </div>
                        </div>
                        <div id="anncEventError"
                             style="display:none;margin-top:10px;padding:10px 14px;
                                    background:#ffebee;border-left:4px solid #c62828;
                                    border-radius:8px;font-size:0.78rem;color:#b71c1c;
                                    align-items:flex-start;gap:8px;line-height:1.5;">
                            <i class="fas fa-exclamation-triangle" style="margin-top:2px;flex-shrink:0;"></i>
                            <span id="anncEventErrorText"></span>
                        </div>
                    </div>

                    <div class="annc-field">
                        <label>Background image <span class="opt">(optional)</span></label>
                        <div class="annc-img-drop ${a.image_url ? 'has-image' : ''}" id="anncImgDrop"
                             onclick="document.getElementById('anncImage').click()">
                            <div class="annc-img-placeholder">
                                <div class="annc-img-drop-icon"><i class="fas fa-image"></i></div>
                                <div class="annc-img-drop-title">Click to upload an image</div>
                                <div class="annc-img-drop-sub">JPG, PNG, GIF, WEBP — max 5 MB</div>
                            </div>
                            <div class="annc-img-preview" id="anncImgPreview"
                                 style="${a.image_url ? `background-image:url('../${escapeHtml(a.image_url)}')` : ''}">
                                ${a.image_url ? `
                                <button type="button" class="annc-img-remove"
                                        onclick="event.stopPropagation(); clearAnncImage();">
                                    <i class="fas fa-times"></i>
                                </button>` : ''}
                            </div>
                        </div>
                        <input type="file" id="anncImage" accept="image/*"
                               style="display:none" onchange="previewAnncImage(this)">
                    </div>

                    <div class="annc-toggle-row">
                        <label class="annc-toggle">
                            <input type="checkbox" id="anncPinned" ${a.is_pinned ? 'checked' : ''}>
                            <span class="annc-switch"></span>
                            <span><i class="fas fa-thumbtack" style="color:#f57c00;"></i> Pin to top</span>
                        </label>
                        <label class="annc-toggle">
                            <input type="checkbox" id="anncActive" ${a.is_active ? 'checked' : ''}>
                            <span class="annc-switch"></span>
                            <span><i class="fas fa-eye" style="color:#2E7D32;"></i> Active</span>
                        </label>
                    </div>

                    <input type="hidden" id="anncId" value="${a.id}">
                    <input type="hidden" id="anncClearImg" value="0">
                </div>
                <div class="annc-modal-footer">
                    <button type="button" class="annc-btn ghost" onclick="Swal.close()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <div class="right">
                        <button type="button" class="annc-btn primary" onclick="saveAnnouncement()">
                            <i class="fas fa-save"></i> ${id ? 'Update' : 'Publish'}
                        </button>
                    </div>
                </div>
            </div>`,
        width: 640,
        showConfirmButton: false,
        showCancelButton: false,
        allowOutsideClick: false,
        customClass: { popup: 'annc-popup' },
        didOpen: () => {
            // Attach real-time validation as soon as the modal is rendered
            attachAnnouncementValidation();
        }
    });
}

// ═══════════════════════════════════════════════════════════
// REAL-TIME VALIDATION — Expiry & Event Schedule
// ═══════════════════════════════════════════════════════════
function _anncNow() {
    // Returns a Date object for "now" (local time)
    return new Date();
}

function _anncFmtDateTime(d) {
    return d.toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * EXPIRY: must be strictly in the future (with 1-minute grace).
 */
function validateExpiryLive() {
    const el = document.getElementById('anncExpiresAt');
    const errBox = document.getElementById('anncExpiryError');
    const errTxt = document.getElementById('anncExpiryErrorText');
    if (!el || !errBox || !errTxt) return true;

    const val = el.value;
    if (!val) {
        errBox.style.display = 'none';
        el.style.borderColor = '';
        return true;
    }

    const chosen = new Date(val);
    const now = _anncNow();
    const diffMs = chosen.getTime() - now.getTime();

    // Reject anything in the past OR within the next 60 seconds
    if (isNaN(chosen.getTime())) {
        errTxt.textContent = 'Invalid expiry date.';
        errBox.style.display = 'flex';
        el.style.borderColor = '#c62828';
        return false;
    }
    if (diffMs <= 60000) {
        errTxt.innerHTML =
            `Expiry must be at least <b>1 minute in the future</b>. ` +
            `Now is <b>${_anncFmtDateTime(now)}</b>.`;
        errBox.style.display = 'flex';
        el.style.borderColor = '#c62828';
        return false;
    }

    errBox.style.display = 'none';
    el.style.borderColor = '';
    return true;
}

/**
 * EVENT SCHEDULE:
 *   - Event date must be today or future.
 *   - If event date is today, event time must be strictly in the future.
 *   - If event date is in the future, event time can be anything.
 *   - If event time is provided but event date is not → error.
 */
function validateEventScheduleLive() {
    const dEl = document.getElementById('anncEventDate');
    const tEl = document.getElementById('anncEventTime');
    const errBox = document.getElementById('anncEventError');
    const errTxt = document.getElementById('anncEventErrorText');
    if (!dEl || !tEl || !errBox || !errTxt) return true;

    const dVal = dEl.value;
    const tVal = tEl.value;

    // Clear state
    dEl.style.borderColor = '';
    tEl.style.borderColor = '';
    errBox.style.display = 'none';

    // No date and no time → fine
    if (!dVal && !tVal) return true;

    // Time but no date → error
    if (!dVal && tVal) {
        errTxt.innerHTML = 'Please pick an <b>Event Date</b> first before setting an Event Time.';
        errBox.style.display = 'flex';
        tEl.style.borderColor = '#c62828';
        return false;
    }

    // Date provided — build a Date object
    const now = _anncNow();
    const todayStr = now.toISOString().split('T')[0];

    // Date in the past → error
    if (dVal < todayStr) {
        errTxt.innerHTML =
            `Event date cannot be in the past. ` +
            `Earliest allowed is <b>today (${todayStr})</b>.`;
        errBox.style.display = 'flex';
        dEl.style.borderColor = '#c62828';
        return false;
    }

    // Date is today → check time
    if (dVal === todayStr) {
        if (!tVal) {
            // If today but no time → not necessarily an error, but warn
            // (user might want an all-day event today — we allow it)
            return true;
        }
        const [hh, mm] = tVal.split(':').map(n => parseInt(n, 10));
        const chosen = new Date(now);
        chosen.setHours(hh, mm, 0, 0);

        if (chosen.getTime() <= now.getTime()) {
            errTxt.innerHTML =
                `For an event <b>today</b>, the time must be in the future. ` +
                `Current time is <b>${now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}</b>.`;
            errBox.style.display = 'flex';
            tEl.style.borderColor = '#c62828';
            return false;
        }
    }

    // Future date — any time is fine
    return true;
}

// Attach live validation to both expiry + event fields as soon as the modal opens
function attachAnnouncementValidation() {
    // These will be re-attached every time the modal opens (safe: uses onchange/oninput in HTML)
    // But we also add a MutationObserver fallback in case the HTML attrs were stripped.
    const exp = document.getElementById('anncExpiresAt');
    const evD = document.getElementById('anncEventDate');
    const evT = document.getElementById('anncEventTime');

    if (exp && !exp.dataset.vBound) {
        exp.dataset.vBound = '1';
        exp.addEventListener('input',  validateExpiryLive);
        exp.addEventListener('change', validateExpiryLive);
    }
    if (evD && !evD.dataset.vBound) {
        evD.dataset.vBound = '1';
        evD.addEventListener('input',  validateEventScheduleLive);
        evD.addEventListener('change', validateEventScheduleLive);
    }
    if (evT && !evT.dataset.vBound) {
        evT.dataset.vBound = '1';
        evT.addEventListener('input',  validateEventScheduleLive);
        evT.addEventListener('change', validateEventScheduleLive);
    }

    // Initial validation pass
    validateExpiryLive();
    validateEventScheduleLive();
}

function previewAnncImage(input) {
    const drop = document.getElementById('anncImgDrop');
    const prev = document.getElementById('anncImgPreview');
    if (!input.files || !input.files[0]) return;
    const url = URL.createObjectURL(input.files[0]);
    prev.style.backgroundImage = `url('${url}')`;
    drop.classList.add('has-image');
    if (!prev.querySelector('.annc-img-remove')) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'annc-img-remove';
        btn.innerHTML = '<i class="fas fa-times"></i>';
        btn.onclick = (e) => { e.stopPropagation(); clearAnncImage(); };
        prev.appendChild(btn);
    }
}

function clearAnncImage() {
    const drop = document.getElementById('anncImgDrop');
    const prev = document.getElementById('anncImgPreview');
    const file = document.getElementById('anncImage');
    drop.classList.remove('has-image');
    prev.style.backgroundImage = '';
    prev.innerHTML = '';
    if (file) file.value = '';
    document.getElementById('anncClearImg').value = '1';
}

async function saveAnnouncement() {
    const id       = document.getElementById('anncId').value;
    const title    = document.getElementById('anncTitle').value.trim();
    const body     = document.getElementById('anncBody').value.trim();
    const category = document.getElementById('anncCategory').value;
    const expires  = document.getElementById('anncExpiresAt').value;
    const eventDate = document.getElementById('anncEventDate').value;
    const eventTime = document.getElementById('anncEventTime').value;
    const pinned   = document.getElementById('anncPinned').checked ? '1' : '0';
    const active   = document.getElementById('anncActive').checked ? '1' : '0';
    const imgFile  = document.getElementById('anncImage').files[0];

    if (!title) { Swal.fire('Error', 'Title is required.', 'error'); return; }
    if (!body)  { Swal.fire('Error', 'Message body is required.', 'error'); return; }

    // Hard-block on any invalid field
    const expiryOk = validateExpiryLive();
    const eventOk  = validateEventScheduleLive();

    if (!expiryOk) {
        Swal.fire('Error', 'Please fix the expiry date before saving.', 'error');
        return;
    }
    if (!eventOk) {
        Swal.fire('Error', 'Please fix the event schedule before saving.', 'error');
        return;
    }

    // Belt-and-braces: re-check expiry server-side-equivalent
    if (expires) {
        const ts = new Date(expires).getTime();
        if (isNaN(ts) || ts <= Date.now() + 60000) {
            Swal.fire('Error', 'Expiry date must be at least 1 minute in the future.', 'error');
            return;
        }
    }

    const fd = new FormData();
    fd.append('ann_action', 'save');
    fd.append('id', id);
    fd.append('title', title);
    fd.append('body', body);
    fd.append('category', category);
    fd.append('expires_at', expires);
    fd.append('event_date', eventDate);
    fd.append('event_time', eventTime);
    fd.append('is_pinned', pinned);
    fd.append('is_active', active);
    if (imgFile) fd.append('image', imgFile);

    Swal.fire({ title: 'Saving…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    try {
        const r = await fetch('?', { method: 'POST', body: fd });
        const d = await r.json();
        Swal.close();
        if (d.success) {
            Swal.fire({ icon: 'success', title: 'Saved', timer: 1200, showConfirmButton: false });
            loadAnnouncements();
        } else {
            Swal.fire('Error', d.message || 'Failed to save.', 'error');
        }
    } catch (e) {
        Swal.close();
        Swal.fire('Error', 'Network error.', 'error');
    }
}

async function toggleAnnouncement(id, field) {
    const fd = new FormData();
    fd.append('ann_action', 'toggle');
    fd.append('id', id);
    fd.append('field', field);
    try {
        const r = await fetch('?', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) {
            // Remember which announcement we just toggled so the carousel
            // can jump to its slide after reload — no confusing "wrong card" flash.
            window.__anncFocusId = Number(id);
            loadAnnouncements();
        } else {
            Swal.fire('Error', d.message || 'Failed.', 'error');
        }
    } catch (e) {
        Swal.fire('Error', 'Network error.', 'error');
    }
}

async function deleteAnnouncement(id) {
    const c = await Swal.fire({
        icon: 'warning',
        title: 'Delete announcement?',
        text: 'This cannot be undone.',
        showCancelButton: true,
        confirmButtonText: 'Delete',
        confirmButtonColor: '#c62828',
        cancelButtonColor: '#6c757d',
    });
    if (!c.isConfirmed) return;

    const fd = new FormData();
    fd.append('ann_action', 'delete');
    fd.append('id', id);

    try {
        const r = await fetch('?', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) {
            Swal.fire({ icon: 'success', title: 'Deleted', timer: 1200, showConfirmButton: false });
            window.__anncFocusId = null;
            loadAnnouncements();
        } else Swal.fire('Error', d.message || 'Failed.', 'error');
    } catch (e) {
        Swal.fire('Error', 'Network error.', 'error');
    }
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
    return window.briteRenderReportsView
        ? window.briteRenderReportsView()
        : '<div class="content-card">Loading Reports...</div>';
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
            setTimeout(initAdminDashboard, 50);
       } else if (view === 'reports') {
    document.getElementById('dashboardBody').innerHTML = renderReports();
    closeCertification();

    setTimeout(function () {
        if (typeof briteInitReports === 'function') {
            briteInitReports();
        }
    }, 50);
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
initAdminDashboard();

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
<script src="reports.js"></script>
</body>
</html> 