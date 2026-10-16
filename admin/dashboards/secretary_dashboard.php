<?php
// admin/dashboards/secretary_dashboard.php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../../sign_in.php');
    exit();
}

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/email_helper.php';

// ═══════════════════════════════════════════════════════════
// ANNOUNCEMENTS — setup
// ═══════════════════════════════════════════════════════════
define('ANN_UPLOAD_DIR', __DIR__ . '/../../uploads/announcements/');
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

$admin_id = $_SESSION['user_id'];
$roleSql = "SELECT admin_role FROM admin WHERE id = ?";
$roleStmt = mysqli_prepare($conn, $roleSql);
mysqli_stmt_bind_param($roleStmt, "i", $admin_id);
mysqli_stmt_execute($roleStmt);
$roleResult = mysqli_stmt_get_result($roleStmt);
$adminRole = mysqli_fetch_assoc($roleResult)['admin_role'];
mysqli_stmt_close($roleStmt);

if ($adminRole !== 'secretary') {
    switch($adminRole) {
        case 'captain':     header('Location: captain_dashboard.php'); break;
        case 'kagawad':     header('Location: kagawad_dashboard.php'); break;
        case 'lupon':       header('Location: lupon_dashboard.php'); break;
        case 'super_admin': header('Location: ../dashboard.php'); break;
        default:            header('Location: ../dashboard.php');
    }
    exit();
}

$adminSql = "SELECT * FROM admin WHERE id = ?";
$adminStmt = mysqli_prepare($conn, $adminSql);
mysqli_stmt_bind_param($adminStmt, "i", $admin_id);
mysqli_stmt_execute($adminStmt);
$adminResult = mysqli_stmt_get_result($adminStmt);
$admin = mysqli_fetch_assoc($adminResult);
mysqli_stmt_close($adminStmt);

// ============ FUNCTIONS ============

function getDocumentTypes($conn) {
    $sql = "SELECT * FROM document_types ORDER BY name ASC";
    $result = mysqli_query($conn, $sql);
    $documents = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) $documents[] = $row;
    }
    return $documents;
}

function getDocumentTypeById($conn, $id) {
    $id = intval($id);
    $sql = "SELECT * FROM document_types WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result && mysqli_num_rows($result) > 0) return mysqli_fetch_assoc($result);
    return null;
}

function addDocumentType($conn, $name, $description, $fee) {
    $name = mysqli_real_escape_string($conn, $name);
    $description = mysqli_real_escape_string($conn, $description);
    $fee = floatval($fee);
    $sql = "INSERT INTO document_types (name, description, fee, is_active) VALUES (?, ?, ?, 1)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ssd", $name, $description, $fee);
    if (mysqli_stmt_execute($stmt)) return ['success' => true, 'id' => mysqli_insert_id($conn)];
    return ['success' => false, 'message' => 'Failed to add document type'];
}

function updateDocumentType($conn, $id, $name, $description, $fee, $is_active) {
    $id = intval($id);
    $name = mysqli_real_escape_string($conn, $name);
    $description = mysqli_real_escape_string($conn, $description);
    $fee = floatval($fee);
    $is_active = intval($is_active);
    $sql = "UPDATE document_types SET name = ?, description = ?, fee = ?, is_active = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ssdii", $name, $description, $fee, $is_active, $id);
    if (mysqli_stmt_execute($stmt)) return ['success' => true];
    return ['success' => false, 'message' => 'Failed to update document type'];
}

function deleteDocumentType($conn, $id) {
    $id = intval($id);
    $sql = "DELETE FROM document_types WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    if (mysqli_stmt_execute($stmt)) return ['success' => true];
    return ['success' => false, 'message' => 'Failed to delete document type'];
}

function getDocumentRequests($conn, $status = null, $page = 1, $perPage = 10) {
    $offset = ($page - 1) * $perPage;
    $status = $status && $status !== 'all' ? mysqli_real_escape_string($conn, $status) : null;

    $countSql = "SELECT COUNT(*) as total FROM document_requests dr JOIN resident r ON dr.resident_id = r.id";
    if ($status) $countSql .= " WHERE dr.status = '$status'";
    $countResult = mysqli_query($conn, $countSql);
    $totalRecords = $countResult ? mysqli_fetch_assoc($countResult)['total'] : 0;
    $totalPages = ceil($totalRecords / $perPage);

    $sql = "SELECT dr.*, r.first_name, r.last_name, r.email, r.phone_number, r.address,
                   dr.approved_by_name, dr.approved_at, dr.completed_by_name, dr.completed_at,
                   dr.rejected_by_name, dr.rejected_at
            FROM document_requests dr
            JOIN resident r ON dr.resident_id = r.id";
    if ($status) $sql .= " WHERE dr.status = '$status'";
    $sql .= " ORDER BY dr.request_date DESC LIMIT $offset, $perPage";

    $result = mysqli_query($conn, $sql);
    $requests = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $customSql = "SELECT field_name, field_value FROM document_requests_custom_data WHERE request_id = " . $row['id'];
            $customResult = mysqli_query($conn, $customSql);
            $customFields = [];
            if ($customResult && mysqli_num_rows($customResult) > 0) {
                while ($customRow = mysqli_fetch_assoc($customResult)) {
                    $customFields[$customRow['field_name']] = $customRow['field_value'];
                }
            }
            $row['custom_fields'] = $customFields;
            $requests[] = $row;
        }
    }
    return ['requests' => $requests, 'totalPages' => $totalPages, 'currentPage' => $page, 'totalRecords' => $totalRecords];
}

function getPendingCount($conn) {
    $sql = "SELECT COUNT(*) as count FROM document_requests WHERE status = 'pending'";
    $result = mysqli_query($conn, $sql);
    if ($result) return mysqli_fetch_assoc($result)['count'];
    return 0;
}

$stats = [];
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM document_requests WHERE status = 'pending'");
$stats['pending_requests'] = mysqli_fetch_assoc($result)['total'];
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM document_requests WHERE status = 'approved'");
$stats['approved_requests'] = mysqli_fetch_assoc($result)['total'];
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM document_requests WHERE status = 'unclaimed'");
$stats['unclaimed_requests'] = mysqli_fetch_assoc($result)['total'];
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM document_requests WHERE status = 'claimed'");
$stats['claimed_requests'] = mysqli_fetch_assoc($result)['total'];
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM document_requests WHERE status = 'rejected'");
$stats['rejected_requests'] = mysqli_fetch_assoc($result)['total'];
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM document_requests WHERE DATE(request_date) = CURDATE()");
$stats['today_requests'] = mysqli_fetch_assoc($result)['total'];
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM resident WHERE is_verified = 0 AND is_active = 1");
$stats['pending_verifications'] = mysqli_fetch_assoc($result)['total'];

// COMPLAINT STATS
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM complaints WHERE status = 'pending_review'");
$stats['pending_review'] = mysqli_fetch_assoc($result)['total'] ?? 0;

$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM complaints WHERE status = 'pending_captain_action'");
$stats['pending_captain_action'] = mysqli_fetch_assoc($result)['total'] ?? 0;

$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM complaints WHERE status = 'escalated'");
$stats['escalated_complaints'] = mysqli_fetch_assoc($result)['total'] ?? 0;

$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM complaints WHERE status = 'pending_captain_review'");
$stats['pending_captain_review'] = mysqli_fetch_assoc($result)['total'] ?? 0;

$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM complaints WHERE status = 'referred_dispatched'");
$stats['for_release'] = mysqli_fetch_assoc($result)['total'] ?? 0;

// Print queue count (parties with print-only notices)
$result = mysqli_query($conn,
    "SELECT COUNT(DISTINCT p.complaint_id) as total
     FROM complaint_parties p
     JOIN complaints c ON p.complaint_id = c.id
     WHERE p.notified_via = 'print'
       AND p.notice_pdf_path IS NOT NULL
       AND c.status IN ('for_mediation','mediation_scheduled','failed_mediation',
                        'pangkat_constituted','pangkat_scheduled')");
$stats['print_queue'] = mysqli_fetch_assoc($result)['total'] ?? 0;


// ═══════════════════════════════════════════════════════════
// DASHBOARD ANALYTICS (charts + calendar data)
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

$dxMonths = [];
for ($i = 5; $i >= 0; $i--) {
    $d = new DateTime('first day of this month');
    $d->modify("-$i month");
    $dxMonths[$d->format('Y-m')] = $d->format('M');
}
$dxDocMonthly  = array_fill_keys(array_keys($dxMonths), 0);
$dxCompMonthly = array_fill_keys(array_keys($dxMonths), 0);

foreach (dxRows($conn, "SELECT DATE_FORMAT(request_date,'%Y-%m') ym, COUNT(*) c
                        FROM document_requests
                        WHERE request_date >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH,'%Y-%m-01')
                        GROUP BY ym") as $row) {
    if (isset($dxDocMonthly[$row['ym']])) $dxDocMonthly[$row['ym']] = (int)$row['c'];
}
foreach (dxRows($conn, "SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) c
                        FROM complaints
                        WHERE created_at >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH,'%Y-%m-01')
                        GROUP BY ym") as $row) {
    if (isset($dxCompMonthly[$row['ym']])) $dxCompMonthly[$row['ym']] = (int)$row['c'];
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
        'kind'  => 'hearing',
        'id'    => (int)$row['id'],
        'date'  => substr($row['hearing_date'], 0, 10),
        'time'  => $row['hearing_time'],
        'title' => $row['title'],
        'sub'   => $row['reference_number'],
        'status'=> $row['status'],
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
        'kind'  => 'pickup',
        'id'    => (int)$row['id'],
        'date'  => substr($row['pickup_date'], 0, 10),
        'time'  => $row['pickup_time'],
        'title' => $row['document_type'],
        'sub'   => trim($row['first_name'] . ' ' . $row['last_name']),
        'status'=> $row['status'],
    ];
}

$dxData = [
    'months'          => array_values($dxMonths),
    'docMonthly'      => array_values($dxDocMonthly),
    'complaintMonthly'=> array_values($dxCompMonthly),
    'docStatus'       => [
        'pending'   => (int)$stats['pending_requests'],
        'approved'  => (int)$stats['approved_requests'],
        'unclaimed' => (int)$stats['unclaimed_requests'],
        'claimed'   => (int)$stats['claimed_requests'],
        'rejected'  => (int)$stats['rejected_requests'],
    ],
    'complaintStatus' => $dxComplaintStatus,
    'topDocs'         => $dxTopDocs,
    'events'          => $dxEvents,
];
$dxJson = json_encode($dxData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR);

$documentTypes = getDocumentTypes($conn);
$pendingCount = getPendingCount($conn);

$recentRequestsResult = getDocumentRequests($conn, null, 1, 10);
$recentRequests = $recentRequestsResult['requests'];
$totalPages = $recentRequestsResult['totalPages'];
$totalRecords = $recentRequestsResult['totalRecords'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    switch($_POST['ajax_action']) {
        case 'add_document':
            echo json_encode(addDocumentType($conn, $_POST['name'], $_POST['description'], $_POST['fee']));
            break;
        case 'update_document':
            echo json_encode(updateDocumentType($conn, $_POST['id'], $_POST['name'], $_POST['description'], $_POST['fee'], $_POST['is_active']));
            break;
        case 'delete_document':
            echo json_encode(deleteDocumentType($conn, $_POST['id']));
            break;
        case 'get_document':
            $doc = getDocumentTypeById($conn, $_POST['id']);
            if ($doc) echo json_encode(['success' => true, 'data' => $doc]);
            else echo json_encode(['success' => false, 'message' => 'Document not found']);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    exit;
}

if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'filter_requests') {
    header('Content-Type: application/json');
    $status = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : null;
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $perPage = 10;
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT dr.*, r.first_name, r.last_name, r.email, r.phone_number, r.address
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
            $customSql = "SELECT field_name, field_value FROM document_requests_custom_data WHERE request_id = " . intval($row['id']);
            $customResult = mysqli_query($conn, $customSql);
            $customFields = [];
            if ($customResult && mysqli_num_rows($customResult) > 0) {
                while ($customRow = mysqli_fetch_assoc($customResult)) {
                    $customFields[$customRow['field_name']] = $customRow['field_value'];
                }
            }
            $row['custom_fields'] = $customFields;
            $requests[] = $row;
        }
    }
    $countResult = mysqli_query($conn, $countSql);
    $totalRecords = $countResult ? mysqli_fetch_assoc($countResult)['total'] : 0;
    $totalPages = ceil($totalRecords / $perPage);

    echo json_encode([
        'success' => true, 'data' => $requests,
        'totalPages' => $totalPages, 'currentPage' => $page, 'totalRecords' => $totalRecords
    ]);
    exit;
}

if (isset($_GET['dashboard_action']) && $_GET['dashboard_action'] === 'get_document_stats') {
    header('Content-Type: application/json');
    $docSql = "SELECT COUNT(*) as total,
                SUM(CASE WHEN status = 'pending'   THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved'  THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'unclaimed' THEN 1 ELSE 0 END) as unclaimed,
                SUM(CASE WHEN status = 'claimed'   THEN 1 ELSE 0 END) as claimed,
                SUM(CASE WHEN status = 'rejected'  THEN 1 ELSE 0 END) as rejected
            FROM document_requests";
    $docResult = mysqli_query($conn, $docSql);
    echo json_encode(['success' => true, 'document' => mysqli_fetch_assoc($docResult)]);
    exit;
}


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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>Secretary Dashboard | Document Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
   <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        <?php include '../admin.css'; ?>

        .sidebar { background: linear-gradient(180deg, #2E7D32 20%, #43a047 80%); }
        .sidebar::-webkit-scrollbar-thumb { background-color: #81c784; }
        .welcome-card { background: linear-gradient(135deg, #2E7D32, #43a047); }
        .btn-verify { background: #2E7D32; }
        .btn-verify:hover { background: #1b5e20; }
        .filter-chip.active { background: #2E7D32; }
        .quick-action-btn:hover { background: #2E7D32; border-color: #2E7D32; }
        .stat-icon.pending { background: #fff3e0; color: #e65100; }
        .stat-icon.approved { background: #e8f5e9; color: #2e7d32; }
        .stat-icon.completed { background: #e3f2fd; color: #1565c0; }
        .stat-icon.today { background: #f3e5f5; color: #9c27b0; }
        .stat-icon.verified { background: #e8f5e9; color: #43e97b; }
        .stat-icon.escalated { background: #ffebee; color: #c62828; }
        .stat-icon.pending-captain { background: #fff8e1; color: #f57c00; }
        .stat-icon.for-release { background: #e8f5e9; color: #2e7d32; }
        .stat-icon.forwarded { background: #e3f2fd; color: #1565c0; }
        .stat-icon.print-queue { background: #ffebee; color: #c62828; }

        .documents-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px; margin-top: 20px; }
        /* ═══════════════════════════════════════════════════════════
   COMPLAINT CARDS — auto-adjusting layout
   ═══════════════════════════════════════════════════════════ */
.complaints-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 16px;
    align-items: start;
    margin-top: 20px;
}

.complaint-card {
    background: #fff;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e2efe8;
    display: flex;
    flex-direction: column;
    position: relative;
    cursor: pointer;
    transition: all 0.2s ease;
}
.complaint-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 22px rgba(0,0,0,0.10);
}
.complaint-card.print-queue { border: 2px solid #c62828; }

.complaint-card-header {
    padding: 12px 18px;
    color: #fff;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
    flex-wrap: wrap;
    flex-shrink: 0;
    min-height: 50px;
}
.complaint-card-title {
    font-weight: 600;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
    min-width: 0;
}
.complaint-card-title span {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    line-height: 1.25;
}
.complaint-card-status {
    font-size: 0.65rem;
    padding: 4px 9px;
    border-radius: 20px;
    background: rgba(255,255,255,0.22);
    white-space: nowrap;
    flex-shrink: 0;
}

.complaint-card-body {
    padding: 12px 18px;
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.complaint-card-actions {
    padding: 10px 18px;
    background: #f8faf8;
    border-top: 1px solid #e2efe8;
    flex-shrink: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.complaint-card-actions .doc-action-btn { flex: 1; min-width: 0; }
        .document-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 1px solid #e2efe8;
            height: 260px;
            display: flex;
            flex-direction: column;
            position: relative;
        }
        .document-card:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(0,0,0,0.12); }
        .document-card.print-queue { border: 2px solid #c62828; }
        .document-card-header {
            background: linear-gradient(135deg, #2E7D32, #43a047);
            padding: 12px 18px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
            min-height: 50px;
        }
        .document-title {
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            overflow: hidden;
            max-width: 65%;
        }
        .document-title span {
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
            max-height: 1.2em;
        }
        .document-status {
            font-size: 0.65rem;
            padding: 4px 9px;
            border-radius: 20px;
            background: rgba(255,255,255,0.2);
            text-transform: capitalize;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .print-queue-badge {
            position: absolute;
            top: 58px; right: 12px;
            background: #c62828;
            color: #fff;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            display: inline-flex;
            align-items: center; gap: 5px;
            box-shadow: 0 3px 10px rgba(198,40,40,0.4);
            z-index: 5;
            animation: pulseBadge 1.6s infinite;
        }
        @keyframes pulseBadge {
            0%,100% { box-shadow: 0 3px 10px rgba(198,40,40,0.4); }
            50%     { box-shadow: 0 3px 18px rgba(198,40,40,0.8); }
        }

        .document-card-body {
            padding: 12px 18px;
            flex: 1;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .document-info-row { display: flex; font-size: 0.78rem; overflow: hidden; }
        .document-info-row .document-info-label {
            width: 92px;
            flex-shrink: 0;
            color: #5f7f6e;
            font-weight: 500;
        }
        .document-info-row .document-info-value {
            flex: 1;
            color: #2c3e50;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }
        .document-card-actions {
            padding: 10px 18px;
            background: #f8faf8;
            border-top: 1px solid #e2efe8;
            flex-shrink: 0;
            display: flex;
            gap: 8px;
            min-height: 50px;
        }
        .document-card-actions .doc-action-btn { flex: 1; }

        .doc-action-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .doc-action-btn.approve { background: #28a745; color: white; }
        .doc-action-btn.reject  { background: #dc3545; color: white; }
        .doc-action-btn.view    { background: #17a2b8; color: white; }
        .doc-action-btn.print   { background: #6c757d; color: white; }
        .doc-action-btn.release { background: #2E7D32; color: white; }
        .doc-action-btn.forward { background: #1565c0; color: white; }
        .doc-action-btn.schedule{ background: #2E7D32; color: white; }
        .doc-action-btn.cert    { background: #b71c1c; color: white; }
        .doc-action-btn.kp8     { background: #00838f; color: white; }
        .doc-action-btn.kp9     { background: #6a1b9a; color: white; }
        .doc-action-btn.print-notice { background: #c62828; color: white; }

        .document-card-header.pending   { background: linear-gradient(135deg, #e65100, #f57c00); }
        .document-card-header.approved  { background: linear-gradient(135deg, #2E7D32, #43a047); }
        .document-card-header.unclaimed { background: linear-gradient(135deg, #ef6c00, #fb8c00); }
        .document-card-header.claimed   { background: linear-gradient(135deg, #1565c0, #1976d2); }
        .document-card-header.rejected  { background: linear-gradient(135deg, #c62828, #d32f2f); }

        .document-card-header.pending_review      { background: linear-gradient(135deg, #e65100, #f57c00); }
        .document-card-header.summoned            { background: linear-gradient(135deg, #6a1b9a, #8e24aa); }
        .document-card-header.mediation           { background: linear-gradient(135deg, #1565c0, #1976d2); }
        .document-card-header.lupon               { background: linear-gradient(135deg, #00838f, #00acc1); }
        .document-card-header.settled             { background: linear-gradient(135deg, #2E7D32, #43a047); }
        .document-card-header.failed              { background: linear-gradient(135deg, #c62828, #d32f2f); }
        .document-card-header.escalated           { background: linear-gradient(135deg, #b71c1c, #e53935); }
        .document-card-header.dismissed           { background: linear-gradient(135deg, #546e7a, #78909c); }
        .document-card-header.failed_conciliation_final { background: linear-gradient(135deg, #4a148c, #6a1b9a); }
        .document-card-header.pending_captain     { background: linear-gradient(135deg, #f57c00, #ff9800); }
        .document-card-header.forwarded_captain   { background: linear-gradient(135deg, #1565c0, #1976d2); }
        .document-card-header.for_release         { background: linear-gradient(135deg, #2E7D32, #43a047); }
        .document-card-header.pangkat_ready       { background: linear-gradient(135deg, #1b5e20, #2E7D32); }
        /* ── Additional stage header colors (mirrors COMPLAINT_STAGES palette) ── */
.document-card-header.lupon                { background: linear-gradient(135deg, #6a1b9a, #8e24aa); }   /* Mediation (violet) */
.document-card-header.pangkat_ready        { background: linear-gradient(135deg, #4a148c, #6a1b9a); }   /* Pangkat Forming (deep violet) */
.document-card-header.conciliation         { background: linear-gradient(135deg, #00838f, #00acc1); }   /* Pangkat Hearing (teal) */
.document-card-header.settlement_pending   { background: linear-gradient(135deg, #2e7d32, #43a047); }   /* Settlement Pending (green) */

        .doc-type-card { background: white; border-radius: 16px; padding: 15px; border: 1px solid #e2efe8; }
        .doc-type-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #e2efe8; }
        .doc-type-name { font-weight: 700; color: #1a472a; font-size: 1rem; }
        .doc-type-fee  { color: #2E7D32; font-weight: 600; }
        .doc-type-actions { display: flex; gap: 8px; margin-top: 10px; }
        .type-action-btn { padding: 5px 12px; border: none; border-radius: 6px; cursor: pointer; font-size: 0.7rem; }
        .type-action-btn.edit   { background: #2196F3; color: white; }
        .type-action-btn.delete { background: #dc3545; color: white; }

        .doc-modal { max-width: 500px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #1a472a; }
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid #e2efe8; border-radius: 8px; font-size: 14px; }

        .id-document-preview { background: #fef9e6; border: 1px solid #ffe0a3; border-radius: 12px; padding: 15px; text-align: center; }
        .id-document-preview img { max-width: 100%; max-height: 200px; border-radius: 8px; margin-bottom: 10px; object-fit: contain; }
        .view-id-btn { display: inline-flex; align-items: center; gap: 8px; background: #43e97b; color: white; padding: 8px 16px; border-radius: 8px; text-decoration: none; margin-top: 10px; border: none; cursor: pointer; }
        .children-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }

        .req-detail-wrap { text-align: left; }
        .req-detail-badge { display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 15px; }
        .req-detail-badge.pending { background: #fff3e0; color: #e65100; }
        .req-detail-badge.approved { background: #e8f5e9; color: #2e7d32; }
        .req-detail-badge.unclaimed { background: #fff3e0; color: #ef6c00; }
        .req-detail-badge.claimed { background: #e3f2fd; color: #1565c0; }
        .req-detail-badge.rejected { background: #ffebee; color: #c62828; }
        .req-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 20px; background: #f8faf8; padding: 14px; border-radius: 10px; margin-bottom: 14px; }
        .req-detail-item { font-size: 0.85rem; }
        .req-detail-item .lbl { color: #7a8a82; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.4px; display: block; margin-bottom: 2px; }
        .req-detail-item .val { color: #1a2b22; font-weight: 600; }
        .req-detail-section { margin-top: 16px; }
        .req-detail-section h4 { color: #1a472a; font-size: 0.95rem; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; padding-bottom: 6px; border-bottom: 1px solid #e2efe8; }
        .req-detail-section h4 i { color: #43e97b; }
        .req-custom-table { width: 100%; border-collapse: collapse; font-size: 0.83rem; }
        .req-custom-table td { padding: 8px 6px; vertical-align: top; }
        .req-custom-table td:first-child { color: #5f7f6e; width: 42%; font-weight: 500; }
        .req-custom-table td:last-child  { color: #1a2b22; font-weight: 600; }

        .header-right-group { display: flex; align-items: center; gap: 6px; margin-left: auto; }
        .camera-scan-btn { width: 44px; height: 44px; border-radius: 50%; background: #f3f6f4; border: 1px solid #e2efe8; color: #2E7D32; font-size: 1.1rem; cursor: pointer; transition: all .2s; display: flex; align-items: center; justify-content: center; }
        .camera-scan-btn:hover { background: #e8f5e9; transform: scale(1.05); }

        .notification-bell-wrap { position: relative; }
        .notification-bell { width: 44px; height: 44px; border-radius: 50%; background: #f3f6f4; border: 1px solid #e2efe8; color: #2E7D32; font-size: 1.1rem; cursor: pointer; position: relative; transition: all .2s; }
        .notification-bell:hover { background: #e8f5e9; transform: scale(1.05); }
        .notification-bell.has-new { animation: bellRing .9s ease-in-out 1; }
        @keyframes bellRing { 0%,100% { transform: rotate(0); } 20% { transform: rotate(-15deg); } 40% { transform: rotate(15deg); } 60% { transform: rotate(-10deg); } 80% { transform: rotate(10deg); } }
        .notif-badge { position: absolute; top: -2px; right: -2px; min-width: 20px; height: 20px; padding: 0 5px; background: #dc3545; color: #fff; font-size: 0.68rem; font-weight: 700; border-radius: 20px; display: flex; align-items: center; justify-content: center; box-shadow: 0 0 0 2px #fff; }
        .notif-dropdown { display: none; position: absolute; top: 52px; right: 0; width: 360px; max-width: 92vw; background: #fff; border-radius: 14px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); border: 1px solid #e2efe8; z-index: 1000; overflow: hidden; }
        .notif-dropdown.show { display: block; }
        .notif-header { padding: 12px 16px; background: linear-gradient(135deg,#2E7D32,#43a047); color: #fff; display: flex; justify-content: space-between; align-items: center; font-weight: 600; font-size: 0.9rem; }
        .notif-mark-read { background: rgba(255,255,255,0.2); color: #fff; border: none; padding: 4px 10px; border-radius: 20px; font-size: 0.7rem; cursor: pointer; }
        .notif-list { max-height: 380px; overflow-y: auto; }
        .notif-item {
            padding: 12px 16px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            gap: 10px;
            cursor: pointer;
            /* make anchor-based notifications look like plain divs */
            color: inherit;
            text-decoration: none;
            transition: background 0.15s;
        }
        .notif-item.has-doc { cursor: alias; }
        .notif-item.has-doc:hover { background: #e8f5e9; }
        .notif-doc-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #70e97a;
            color: #195b1d;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.62rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-left: 4px;
        }
        .notif-item.unread { background: #f1f8e9; }
        .notif-item.action-required { background: #ffebee !important; border-left: 4px solid #c62828; }
        .notif-icon { width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.9rem; }
        .notif-icon.assignment { background:#e3f2fd; color:#1565c0; }
        .notif-icon.hearing    { background:#f3e5f5; color:#6a1b9a; }
        .notif-icon.info       { background:#e8f5e9; color:#2E7D32; }
        .notif-icon.warning    { background:#fff3e0; color:#e65100; }
        .notif-icon.success    { background:#e8f5e9; color:#2E7D32; }
        .notif-icon.error      { background:#ffebee; color:#c62828; }
        .notif-body { flex: 1; min-width: 0; }
        .notif-title { font-weight: 700; font-size: 0.85rem; color: #1a472a; }
        .notif-msg   { font-size: 0.78rem; color: #555; margin-top: 3px; line-height: 1.35; }
        .notif-time  { font-size: 0.68rem; color: #999; margin-top: 4px; }
        .notif-empty { padding: 30px; text-align: center; color: #999; font-size: 0.85rem; }
        .notif-footer { padding: 10px 16px; background: #f8faf8; border-top: 1px solid #e2efe8; font-size: 0.78rem; }
        .notif-permission-label { display: flex; align-items: center; gap: 8px; cursor: pointer; color: #555; }

        #qrCameraOverlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100vw; height: 100dvh; background: #000; z-index: 5000; overflow: hidden; }
        #qrCameraOverlay.active { display: block; }
        #qrCameraOverlay video { width: 100%; height: 100dvh; object-fit: cover; display: block; }
        .qr-frame { position: absolute; top: 50%; left: 50%; width: min(70vmin, 420px); height: min(70vmin, 420px); transform: translate(-50%, -50%); border: 2px solid rgba(255,255,255,0.35); border-radius: 18px; box-shadow: 0 0 0 9999px rgba(0,0,0,0.45); pointer-events: none; }
        .qr-corner { position: absolute; width: 44px; height: 44px; border: 5px solid #43e97b; }
        .qr-tl { top: -2px; left: -2px; border-right: none; border-bottom: none; border-top-left-radius: 18px; }
        .qr-tr { top: -2px; right: -2px; border-left: none; border-bottom: none; border-top-right-radius: 18px; }
        .qr-bl { bottom: -2px; left: -2px; border-right: none; border-top: none; border-bottom-left-radius: 18px; }
        .qr-br { bottom: -2px; right: -2px; border-left: none; border-top: none; border-bottom-right-radius: 18px; }
        .qr-overlay-pill { position: absolute; bottom: calc(40px + env(safe-area-inset-bottom)); left: 50%; transform: translateX(-50%); background: #1b5e20; color: #fff; padding: 12px 22px; border-radius: 30px; font-size: 0.95rem; font-weight: 600; display: flex; align-items: center; gap: 10px; z-index: 2; }
        .qr-overlay-close { position: absolute; top: calc(20px + env(safe-area-inset-top)); right: calc(20px + env(safe-area-inset-right)); width: 46px; height: 46px; border-radius: 50%; background: rgba(0,0,0,0.55); color: #fff; border: 1px solid rgba(255,255,255,0.3); font-size: 1.2rem; cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 3; }
        .qr-overlay-hint { position: absolute; top: calc(24px + env(safe-area-inset-top)); left: 50%; transform: translateX(-50%); background: rgba(0,0,0,0.55); color: #fff; padding: 8px 18px; border-radius: 20px; font-size: 0.8rem; display: flex; align-items: center; gap: 8px; z-index: 3; }

        .sm-popup { border-radius: 16px !important; padding: 0 !important; overflow: hidden; max-width: 720px !important; }
        .sm-popup .swal2-html-container { margin: 0 !important; padding: 0 !important; }
        .sm-popup .swal2-actions { padding: 14px 22px 18px; margin: 0; background: #f8faf8; border-top: 1px solid #e2efe8; width: 100%; }
        .sm-popup .swal2-confirm, .sm-popup .swal2-cancel, .sm-popup .swal2-deny { border-radius: 8px !important; font-weight: 600; padding: 9px 20px; }
        .sm-popup .swal2-title { display: none !important; }
        .sm-modal { text-align:left; font-family: 'Segoe UI', Roboto, sans-serif; }
        .sm-header { background: linear-gradient(135deg, #1b5e20, #2E7D32 55%, #43a047); color: #fff; padding: 18px 22px; display: flex; justify-content: space-between; align-items: center; }
        .sm-header-left { display: flex; align-items: center; gap: 14px; }
        .sm-header-left i { font-size: 1.8rem; opacity: 0.9; }
        .sm-title-main { font-size: 1.15rem; font-weight: 700; }
        .sm-title-sub  { font-size: 0.8rem; opacity: 0.85; margin-top: 2px; }
        .sm-ref { text-align: right; }
        .sm-ref-label { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 1px; opacity: 0.8; }
        .sm-ref-value { font-size: 0.9rem; font-weight: 700; margin-top: 2px; }
        .sm-body { padding: 22px 26px; background: #fff; }
        .sm-field { margin-bottom: 16px; }
        .sm-label { display: block; font-weight: 600; font-size: 0.85rem; color: #1a472a; margin-bottom: 6px; }
        .sm-label .req { color: #c62828; }
        .sm-input, .sm-textarea { width: 100%; padding: 10px 12px; border: 1.5px solid #cfd8dc; border-radius: 8px; font-size: 0.92rem; font-family: inherit; box-sizing: border-box; }
        .sm-input:focus, .sm-textarea:focus { outline: none; border-color: #2E7D32; box-shadow: 0 0 0 3px rgba(46,125,50,0.12); }
        .sm-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .sm-note { background: #e3f2fd; border-left: 4px solid #1976d2; padding: 10px 14px; border-radius: 8px; font-size: 0.82rem; color: #0d47a1; display: flex; gap: 10px; align-items: flex-start; margin-bottom: 16px; }
        .sm-section-title { font-weight: 700; font-size: 0.85rem; color: #1a472a; margin: 20px 0 8px; display: flex; justify-content: space-between; align-items: center; }
        .sm-recipient { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 10px; background: #fafbfa; border: 1px solid #e2efe8; margin-bottom: 6px; }
        .sm-recipient .avatar { width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: #e8f5e9; color: #2E7D32; flex-shrink: 0; }
        .sm-recipient-info { flex: 1; min-width: 0; }
        .sm-recipient-name { font-weight: 700; font-size: 0.85rem; color: #1a2b22; }
        .sm-recipient-sub  { font-size: 0.72rem; color: #666; margin-top: 2px; }
        .sm-role { padding: 2px 8px; border-radius: 10px; font-size: 0.62rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
        .sm-role.complainant { background: #e8f5e9; color: #2E7D32; }
        .sm-role.respondent  { background: #fff3e0; color: #ef6c00; }
        .sm-role.witness     { background: #e3f2fd; color: #1565c0; }

        .cd-popup { border-radius: 16px !important; padding: 0 !important; overflow: hidden; max-width: 1080px !important; width: 95vw !important; }
        .cd-popup .swal2-html-container { margin: 0 !important; padding: 0 !important; }
        .cd-popup .swal2-actions { display: none !important; }
        .cd-popup .swal2-title { display: none !important; }

        .cd-modal { text-align: left; font-family: 'Segoe UI', Roboto, sans-serif; }
        .cd-header { background: linear-gradient(135deg, #1b5e20, #2E7D32 55%, #43a047); color: #fff; padding: 18px 24px; display: flex; justify-content: space-between; align-items: center; }
        .cd-header.cd-escalated { background: linear-gradient(135deg, #b71c1c, #c62828 55%, #e53935); }
        .cd-header.cd-forwarded { background: linear-gradient(135deg, #1565c0, #1976d2 55%, #42a5f5); }
        .cd-header.cd-pangkat   { background: linear-gradient(135deg, #1b5e20, #2E7D32 55%, #43a047); }
        .cd-header.cd-mediation { background: linear-gradient(135deg, #6a1b9a, #8e24aa 55%, #ab47bc); }
        .cd-header-left { display: flex; align-items: center; gap: 14px; }
        .cd-header-left > i { font-size: 1.8rem; opacity: 0.9; }
        .cd-title { font-size: 1.1rem; font-weight: 700; }
        .cd-sub   { font-size: 0.8rem; opacity: 0.85; margin-top: 2px; }
        .cd-status { padding: 6px 14px; border-radius: 20px; background: rgba(255,255,255,0.2); font-size: 0.72rem; font-weight: 700; letter-spacing: 0.5px; }

        .cd-body { padding: 22px 26px; max-height: 76vh; overflow-y: auto; background: #f8faf8; display: flex; flex-direction: column; gap: 16px; }

        .cd-escalation-alert { background: linear-gradient(135deg, #ffebee, #ffcdd2); border-left: 6px solid #c62828; border-radius: 14px; padding: 16px 20px; color: #b71c1c; display: flex; gap: 14px; align-items: flex-start; }
        .cd-escalation-alert > i { font-size: 1.8rem; flex-shrink: 0; }
        .cd-escalation-alert .cd-esc-title { font-weight: 800; font-size: 1rem; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
        .cd-escalation-alert .cd-esc-body { font-size: 0.88rem; line-height: 1.6; color: #333; }

        .cd-block { background: #fff; border-radius: 12px; padding: 18px 22px; border: 1px solid #e2efe8; }
        .cd-block-title { display: flex; align-items: center; gap: 12px; font-size: 0.95rem; font-weight: 800; color: #1a472a; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 2px solid #e2efe8; }
        .cd-block-title .cd-num { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; background: #2E7D32; color: #fff; border-radius: 50%; font-size: 0.78rem; font-weight: 700; flex-shrink: 0; }
        .cd-block-title i { color: #2E7D32; }

        .cd-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px 20px; margin-bottom: 14px; }
        .cd-grid-item { display: flex; flex-direction: column; }
        .cd-grid-item label { font-size: 0.7rem; text-transform: uppercase; color: #999; margin-bottom: 3px; letter-spacing: 0.4px; font-weight: 600; }
        .cd-grid-item span { font-size: 0.9rem; color: #1a2b22; font-weight: 600; }

        .cd-text-block { margin-top: 12px; }
        .cd-text-block label { display: block; font-size: 0.7rem; text-transform: uppercase; color: #999; margin-bottom: 5px; letter-spacing: 0.4px; font-weight: 600; }
        .cd-text-block p { color: #1a2b22; font-size: 0.92rem; }

        .cd-people-list { display: flex; flex-direction: column; gap: 10px; }
        .cd-person { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border-radius: 10px; background: #fafbfa; border: 1px solid #f0f0f0; }
        .cd-person-avatar { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .cd-person-name { font-weight: 700; color: #1a1a1a; font-size: 0.92rem; }
        .cd-person-meta { font-size: 0.8rem; color: #555; margin-top: 4px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
        .cd-person-meta span { display: inline-flex; align-items: center; gap: 4px; }
        .cd-role-pill { padding: 2px 10px; border-radius: 10px; font-weight: 700; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.3px; }

        /* KP Notice panel */
        .notice-panel { margin-top: 0; padding: 16px; background: #f8faf8; border-radius: 12px; border: 1px solid #e2efe8; }
        .notice-panel-title { font-weight: 800; font-size: 0.9rem; color: #1a472a; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; padding-bottom: 8px; border-bottom: 2px solid #e2efe8; }
        .notice-panel-title i { color: #00838f; }
        .notice-item { display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; border-radius: 10px; margin-bottom: 8px; border-left: 4px solid; }
        .notice-item.kp8 { background: #e0f2f1; border-left-color: #00838f; }
        .notice-item.kp9 { background: #f3e5f5; border-left-color: #6a1b9a; }
        .notice-item.kp10 { background: #f3e5f5; border-left-color: #4a148c; }
        .notice-item.kp11 { background: #e8f5e9; border-left-color: #2E7D32; }
        .notice-item.print-required {
            background: #ffebee !important;
            border-left-color: #c62828 !important;
            border: 2px solid #ef9a9a;
            box-shadow: 0 3px 12px rgba(198,40,40,0.15);
        }
        .notice-item-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.95rem; }
        .notice-item-icon.kp8 { background: #b2dfdb; color: #00695c; }
        .notice-item-icon.kp9 { background: #e1bee7; color: #4a148c; }
        .notice-item-icon.kp10 { background: #e1bee7; color: #4a148c; }
        .notice-item-icon.kp11 { background: #c8e6c9; color: #1b5e20; }
        .notice-item-icon.print-required { background: #ffcdd2; color: #b71c1c; }
        .notice-item-body { flex: 1; min-width: 0; }
        .notice-item-title { font-weight: 700; font-size: 0.85rem; color: #1a2b22; }
        .notice-item-meta { font-size: 0.72rem; color: #666; margin-top: 3px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .notice-item-notified { padding: 2px 8px; border-radius: 10px; font-size: 0.62rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
        .notice-item-notified.yes { background: #e8f5e9; color: #1b5e20; }
        .notice-item-notified.no  { background: #c62828; color: #fff; animation: pulseBadge2 1.5s infinite; }
        @keyframes pulseBadge2 {
            0%,100% { box-shadow: 0 0 0 0 rgba(198,40,40,0.4); }
            50%     { box-shadow: 0 0 0 6px rgba(198,40,40,0); }
        }
        .notice-item-btn { margin-top: 8px; padding: 6px 12px; border: none; border-radius: 6px; font-size: 0.75rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; color: #fff; }
        .notice-item-btn.kp8 { background: #00838f; }
        .notice-item-btn.kp9 { background: #6a1b9a; }
        .notice-item-btn.kp10 { background: #4a148c; }
        .notice-item-btn.kp11 { background: #2E7D32; }
        .notice-item-btn.print-required { background: #c62828; }
        .notice-item-btn:hover { opacity: 0.9; }

        .cd-incident { background: #fafbfa; border-radius: 10px; padding: 14px 16px; margin-bottom: 14px; border-left: 4px solid #6a1b9a; }
        .cd-incident-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 8px; }
        .cd-incident-head strong { color: #6a1b9a; font-size: 0.92rem; }
        .cd-incident-meta { font-size: 0.78rem; color: #666; display: flex; gap: 10px; }
        .cd-incident-loc { font-size: 0.85rem; color: #444; margin-bottom: 6px; }
        .cd-incident-desc { font-size: 0.9rem; color: #222; line-height: 1.55; }

        .cd-evidence-block { margin-top: 12px; padding-top: 12px; border-top: 1px dashed #ccc; }
        .cd-evidence-title { font-size: 0.72rem; color: #666; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 10px; font-weight: 700; }
        .cd-carousel { display: flex; gap: 12px; overflow-x: auto; padding-bottom: 8px; scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; }
        .cd-carousel::-webkit-scrollbar { height: 6px; }
        .cd-carousel::-webkit-scrollbar-thumb { background: #c8e6c9; border-radius: 3px; }

        .ev-card { display: flex; flex-direction: column; flex-shrink: 0; width: 160px; height: 160px; border-radius: 10px; overflow: hidden; border: 2px solid #e2efe8; background: #fff; text-decoration: none; color: inherit; transition: all 0.2s; scroll-snap-align: start; position: relative; }
        .ev-card:hover { border-color: #2E7D32; transform: translateY(-3px); box-shadow: 0 6px 16px rgba(46,125,50,0.2); }
        .ev-card img { width: 100%; height: 110px; object-fit: cover; background: #f5f5f5; }
        .ev-card .ev-fallback { width: 100%; height: 110px; background: #f5f5f5; align-items: center; justify-content: center; }
        .ev-card .ev-label { padding: 6px 8px; font-size: 0.68rem; color: #333; font-weight: 600; display: flex; align-items: center; gap: 4px; background: #fff; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        .ev-card .ev-label span { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }

        .cd-notes-input { margin-bottom: 8px; }
        .cd-textarea { width: 100%; padding: 10px 12px; border: 1.5px solid #cfd8dc; border-radius: 8px; font-size: 0.9rem; font-family: inherit; resize: vertical; box-sizing: border-box; }
        .cd-textarea:focus { outline: none; border-color: #2E7D32; box-shadow: 0 0 0 3px rgba(46,125,50,0.12); }

        .cd-history-list { display: flex; flex-direction: column; gap: 8px; }
        .cd-history-item { background: #f8faf8; border-radius: 10px; padding: 10px 14px; border-left: 3px solid #43e97b; }
        .cd-history-time { font-size: 0.7rem; color: #999; }
        .cd-history-notes { font-size: 0.85rem; color: #1a2b22; margin-top: 3px; line-height: 1.5; }
        .cd-history-meta { font-size: 0.7rem; color: #666; margin-top: 3px; font-style: italic; }
        .cd-empty { color: #999; font-style: italic; font-size: 0.85rem; }

        .cd-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; border: none; cursor: pointer; transition: all 0.15s; text-decoration: none; }
        .cd-btn.primary   { background: #2E7D32; color: #fff; }
        .cd-btn.primary:hover { background: #1b5e20; }
        .cd-btn.forward   { background: #1565c0; color: #fff; }
        .cd-btn.forward:hover { background: #0d47a1; }
        .cd-btn.danger    { background: #dc3545; color: #fff; }
        .cd-btn.danger:hover { background: #b02a37; }
        .cd-btn.warning   { background: #ef6c00; color: #fff; }
        .cd-btn.warning:hover { background: #e65100; }
        .cd-btn.secondary { background: #6c757d; color: #fff; }
        .cd-btn.secondary:hover { background: #5a6268; }
        .cd-btn.cert      { background: #b71c1c; color: #fff; }
        .cd-btn.cert:hover{ background: #7f0000; }

        .cd-actions { display: flex; gap: 10px; justify-content: flex-end; padding: 14px 22px; background: #f8faf8; border-top: 1px solid #e2efe8; flex-wrap: wrap; }

        .cd-info-banner {
            background: #e3f2fd;
            border-left: 4px solid #1565c0;
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 0.85rem;
            color: #1565c0;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-right: auto;
            max-width: 100%;
        }
        .cd-info-banner i { flex-shrink: 0; margin-top: 1px; }

        @media (max-width: 768px) {
            .documents-grid { grid-template-columns: 1fr; }
            .document-card-actions { flex-direction: column; }
            .doc-action-btn { width: 100%; }
            .notif-dropdown { width: 300px; }
            .req-detail-grid { grid-template-columns: 1fr; }
            .cd-body { padding: 16px; }
            .cd-actions { flex-direction: column-reverse; }
            .cd-actions .cd-btn { width: 100%; justify-content: center; }
            .cd-info-banner { width: 100%; margin-bottom: 8px; }
        }
        /* ── CASE TOOLBAR ──────────────────────────────────── */
.case-toolbar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    margin-top: 16px;
    padding: 12px 14px;
    background: #f8faf8;
    border: 1px solid #e2efe8;
    border-radius: 12px;
}
.case-search {
    position: relative;
    flex: 1 1 240px;
    min-width: 200px;
}
.case-search > i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #999;
    font-size: 0.85rem;
    pointer-events: none;
}
.case-search > input {
    width: 100%;
    padding: 9px 12px 9px 34px;
    border: 1.5px solid #cfd8dc;
    border-radius: 8px;
    font-size: 0.85rem;
    box-sizing: border-box;
}
.case-search > input:focus {
    outline: none;
    border-color: #2E7D32;
    box-shadow: 0 0 0 3px rgba(46,125,50,0.12);
}
.case-date-select,
.case-date-input {
    padding: 9px 12px;
    border: 1.5px solid #cfd8dc;
    border-radius: 8px;
    font-size: 0.85rem;
    background: #fff;
}
.case-date-select:focus,
.case-date-input:focus {
    outline: none;
    border-color: #2E7D32;
    box-shadow: 0 0 0 3px rgba(46,125,50,0.12);
}
.case-reset-btn {
    padding: 9px 14px;
    border: 1px solid #cfd8dc;
    background: #fff;
    border-radius: 8px;
    color: #555;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
}
.case-reset-btn:hover { background: #f0f0f0; }
.case-result-count {
    font-size: 0.78rem;
    color: #666;
    margin-left: auto;
    font-weight: 600;
}

/* ── PAGINATION ────────────────────────────────────── */
.case-pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 6px;
    margin-top: 22px;
    padding: 12px 0;
    flex-wrap: wrap;
}
.case-page-btn {
    min-width: 38px;
    height: 38px;
    padding: 0 12px;
    border: 1.5px solid #e2efe8;
    background: #fff;
    border-radius: 8px;
    color: #555;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
    transition: all .15s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.case-page-btn:hover:not(:disabled) {
    border-color: #2E7D32;
    color: #2E7D32;
    background: #f7fbf7;
}
.case-page-btn.active {
    background: #2E7D32;
    border-color: #2E7D32;
    color: #fff;
}
.case-page-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.case-page-info {
    margin-left: 12px;
    font-size: 0.78rem;
    color: #888;
}

/* ============================================================
   EQUIPMENT MODAL — Professional UI (shared styles)
   ============================================================ */
.eq-mgmt-wrap { display: flex; flex-direction: column; gap: 16px; padding: 4px 2px; }
.eq-mgmt-loading { text-align: center; padding: 50px 20px; color: #999; }
.eq-mgmt-loading i { color: #2E7D32; margin-bottom: 12px; display: block; }

.eq-mgmt-banner {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 16px;
    background: linear-gradient(135deg, #fff8e1, #ffecb3);
    border-left: 5px solid #f57c00;
    border-radius: 10px;
    color: #8a3e00;
}
.eq-mgmt-banner > i { font-size: 1.4rem; color: #e65100; flex-shrink: 0; margin-top: 2px; }
.eq-mgmt-banner-title {
    font-weight: 800;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 3px;
}
.eq-mgmt-banner-sub { font-size: 0.82rem; line-height: 1.5; color: #6d4c00; }

.eq-mgmt-hero {
    display: grid;
    grid-template-columns: 140px 1fr;
    gap: 18px;
    padding: 18px;
    background: linear-gradient(135deg, #f1f8e9, #e8f5e9);
    border-radius: 14px;
    border: 1.5px solid #c8e6c9;
}
@media (max-width: 600px) { .eq-mgmt-hero { grid-template-columns: 1fr; } }

.eq-mgmt-hero-image {
    width: 100%;
    aspect-ratio: 1 / 1;
    border-radius: 12px;
    overflow: hidden;
    background: #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
.eq-mgmt-hero-image img { width: 100%; height: 100%; object-fit: cover; }
.eq-mgmt-hero-fallback {
    width: 100%; height: 100%;
    display: flex; align-items: center; justify-content: center;
    font-size: 3rem; color: #43a047;
    background: linear-gradient(135deg, #ffffff, #f1f8e9);
}
.eq-mgmt-hero-info { display: flex; flex-direction: column; justify-content: center; gap: 8px; }
.eq-mgmt-hero-title { font-size: 1.3rem; font-weight: 800; color: #1a472a; margin: 0; line-height: 1.25; }
.eq-mgmt-hero-total { font-size: 0.85rem; color: #4a5f56; }
.eq-mgmt-hero-total i { color: #2E7D32; margin-right: 5px; }
.eq-mgmt-hero-total strong { color: #1a472a; font-size: 1rem; }

.eq-cat-badge {
    display: inline-block;
    align-self: flex-start;
    padding: 4px 12px;
    border-radius: 20px;
    background: #2E7D32;
    color: #fff;
    font-size: 0.65rem;
    font-weight: 800;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

.eq-hero-desc { font-size: 0.86rem; color: #4a5f56; line-height: 1.5; margin: 0; }
.eq-hero-desc.muted { color: #999; font-style: italic; }

.eq-mgmt-section {
    background: #fff;
    border: 1.5px solid #e2efe8;
    border-radius: 14px;
    padding: 16px 18px;
}
.eq-mgmt-section-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.85rem;
    font-weight: 800;
    color: #1a472a;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 14px;
    padding-bottom: 10px;
    border-bottom: 2px solid #f1f8e9;
}

.eq-mgmt-status-bar {
    display: flex;
    height: 18px;
    border-radius: 10px;
    overflow: hidden;
    background: #eef2f0;
    margin-bottom: 16px;
    box-shadow: inset 0 1px 3px rgba(0,0,0,0.06);
}
.eq-mgmt-bar-seg { transition: width 0.4s ease; }
.eq-mgmt-bar-seg.available   { background: linear-gradient(180deg, #66bb6a, #2E7D32); }
.eq-mgmt-bar-seg.borrowed    { background: linear-gradient(180deg, #42a5f5, #1976d2); }
.eq-mgmt-bar-seg.maintenance { background: linear-gradient(180deg, #ffa726, #f57c00); }
.eq-mgmt-bar-seg.lost        { background: linear-gradient(180deg, #ef5350, #c62828); }

.eq-mgmt-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}
@media (max-width: 600px) { .eq-mgmt-stats-grid { grid-template-columns: repeat(2, 1fr); } }

.eq-mgmt-stat {
    padding: 14px 10px;
    border-radius: 12px;
    text-align: center;
    border: 1.5px solid;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
}
.eq-mgmt-stat i { font-size: 1.1rem; }
.eq-mgmt-stat-num { font-size: 1.6rem; font-weight: 900; line-height: 1; margin-top: 4px; }
.eq-mgmt-stat-lbl { font-size: 0.66rem; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 700; opacity: 0.9; }
.eq-mgmt-stat-pct { font-size: 0.65rem; font-weight: 700; background: rgba(255,255,255,0.7); padding: 1px 7px; border-radius: 8px; margin-top: 4px; }
.eq-mgmt-stat.available   { background: #e8f5e9; border-color: #a5d6a7; color: #1b5e20; }
.eq-mgmt-stat.borrowed    { background: #e3f2fd; border-color: #90caf9; color: #0d47a1; }
.eq-mgmt-stat.maintenance { background: #fff3e0; border-color: #ffcc80; color: #e65100; }
.eq-mgmt-stat.lost        { background: #ffebee; border-color: #ef9a9a; color: #b71c1c; }

.eq-mgmt-meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px 20px;
}
.eq-mgmt-meta-item { display: flex; flex-direction: column; gap: 3px; }
.eq-mgmt-meta-item label {
    font-size: 0.68rem;
    text-transform: uppercase;
    color: #999;
    letter-spacing: 0.4px;
    font-weight: 700;
}
.eq-mgmt-meta-item span { font-size: 0.9rem; color: #1a2b22; font-weight: 600; }

.eq-empty { text-align: center; padding: 40px 20px; color: #999; }
.eq-empty i { font-size: 2.5rem; color: #c8e6c9; margin-bottom: 10px; display: block; }
.eq-empty p { margin: 0; font-size: 0.9rem; }

.eq-mgmt-footer {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding-top: 12px;
    border-top: 1px solid #e2efe8;
    flex-wrap: wrap;
}
.eq-mgmt-footer .eq-btn { flex: 1; min-width: 120px; padding: 11px 18px; }

.eq-btn {
    padding: 10px 18px;
    border: none;
    border-radius: 9px;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    transition: all 0.15s;
}
.eq-btn:hover { transform: translateY(-1px); }
.eq-btn.primary { background: #2E7D32; color: #fff; }
.eq-btn.primary:hover { background: #1b5e20; }
.eq-btn.ghost { background: #fff; color: #2E7D32; border: 1.5px solid #c8e6c9; }
.eq-btn.ghost:hover { background: #f1f8e9; }

#equipmentItemsModal .modal-content { max-width: 820px; width: 95vw; }
#equipmentModal .modal-content       { max-width: 760px; width: 95vw; }
/* ══════════════════════════════════════════════════════════
   FILTER BAR — STACKED LAYOUT (Type on top, Status below)
   ══════════════════════════════════════════════════════════ */
.filter-bar.filter-bar-stacked {
    display: flex !important;
    flex-direction: column !important;
    flex-wrap: nowrap !important;
    align-items: stretch !important;
    gap: 10px !important;
    padding: 12px 14px !important;
    background: #f8faf8 !important;
    border: 1px solid #e2efe8 !important;
    border-radius: 12px !important;
    overflow: visible !important;
}
.filter-bar.filter-bar-stacked .filter-row {
    display: flex !important;
    flex-wrap: wrap !important;
    align-items: center !important;
    gap: 8px !important;
    width: 100% !important;
}
.filter-bar.filter-bar-stacked .filter-row-type {
    padding-bottom: 10px !important;
    border-bottom: 1px dashed #d7e2dc !important;
}
.filter-bar.filter-bar-stacked .filter-row-label {
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    font-size: 0.72rem !important;
    font-weight: 800 !important;
    color: #6b7a72 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    padding-right: 6px !important;
    margin-right: 4px !important;
    border-right: 1px solid #d7e2dc !important;
    height: 22px !important;
    flex-shrink: 0 !important;
}
.filter-bar.filter-bar-stacked .filter-row-actions {
    margin-left: auto !important;
    display: inline-flex !important;
    gap: 8px !important;
    flex-wrap: wrap !important;
    align-items: center !important;
}
.filter-bar.filter-bar-stacked .filter-row .filter-chip,
.filter-bar.filter-bar-stacked .filter-row .btn-verify {
    flex-shrink: 0 !important;
}
@media (max-width: 720px) {
    .filter-bar.filter-bar-stacked .filter-row-actions {
        margin-left: 0 !important;
        width: 100% !important;
    }
    .filter-bar.filter-bar-stacked .filter-row-actions .btn-verify {
        flex: 1 !important;
        justify-content: center !important;
    }
    .filter-bar.filter-bar-stacked .filter-row-label {
        width: 100% !important;
        border-right: none !important;
        margin-bottom: -2px !important;
    }
}
/* ═══════════════════════════════════════════════════════════
   VEHICLE CALENDAR BARS – grouped by vehicle per day
   ═══════════════════════════════════════════════════════════ */
.booking-bar.vehicle-bar {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 4px 8px;
    font-size: 10px;
    border-radius: 4px;
    border-left: 3px solid #1b5e20;
    background: #e8f5e9;
    color: #1b5e20;
    font-weight: 700;
    transition: all 0.15s ease;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}
.booking-bar.vehicle-bar:hover {
    transform: translateX(2px);
    box-shadow: 0 2px 6px rgba(27,94,32,0.25);
}
.booking-bar.vehicle-bar i.fa-truck {
    color: #1b5e20;
    flex-shrink: 0;
}
.booking-bar.vehicle-bar .vehicle-trip-count {
    margin-left: auto;
    background: #1b5e20;
    color: #fff;
    padding: 1px 7px;
    border-radius: 10px;
    font-size: 9px;
    font-weight: 800;
    flex-shrink: 0;
}
.booking-bar.vehicle-bar .vehicle-status-icon {
    font-size: 9px;
    opacity: 0.75;
    flex-shrink: 0;
}

/* Status-colored vehicle bars */
.booking-bar.vehicle-bar.pending   { background:#fff3e0; color:#e65100; border-left-color:#e65100; }
.booking-bar.vehicle-bar.pending   .vehicle-trip-count { background:#e65100; }
.booking-bar.vehicle-bar.pending   i.fa-truck { color:#e65100; }

.booking-bar.vehicle-bar.approved  { background:#e8f5e9; color:#1b5e20; border-left-color:#1b5e20; }
.booking-bar.vehicle-bar.approved  .vehicle-trip-count { background:#1b5e20; }
.booking-bar.vehicle-bar.approved  i.fa-truck { color:#1b5e20; }

.booking-bar.vehicle-bar.borrowed  { background:#e3f2fd; color:#0d47a1; border-left-color:#0d47a1; }
.booking-bar.vehicle-bar.borrowed  .vehicle-trip-count { background:#0d47a1; }
.booking-bar.vehicle-bar.borrowed  i.fa-truck { color:#0d47a1; }

.booking-bar.vehicle-bar.returned  { background:#eceff1; color:#455a64; border-left-color:#455a64; }
.booking-bar.vehicle-bar.returned  .vehicle-trip-count { background:#455a64; }
.booking-bar.vehicle-bar.returned  i.fa-truck { color:#455a64; }

.booking-bar.vehicle-bar.rejected  { background:#ffebee; color:#b71c1c; border-left-color:#b71c1c; }
.booking-bar.vehicle-bar.rejected  .vehicle-trip-count { background:#b71c1c; }
.booking-bar.vehicle-bar.rejected  i.fa-truck { color:#b71c1c; }

.booking-row.vehicle-row {
    margin-top: 3px;
}
/* ═══════════════════════════════════════════════════════════
   BADGE — CANCELLED STATUS
   ═══════════════════════════════════════════════════════════ */
.badge-cancelled {
    background: #eceff1;
    color: #455a64;
    padding: 4px 10px;
    border-radius: 40px;
    font-size: 0.72rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.status-cancelled {
    background: #eceff1;
    color: #455a64;
}

/* Card header gradient — cancelled variant (optional) */
.card-header-gradient.cancelled {
    background: linear-gradient(135deg, #546e7a, #78909c);
}

/* Calendar booking bar — cancelled status (optional) */
.booking-bar.cancelled {
    background: #eceff1;
    color: #455a64;
    border-left-color: #455a64;
}
/* ═══════════════════════════════════════════════════════════
   MODERN DASHBOARD
   ═══════════════════════════════════════════════════════════ */
.modern-dashboard { display: flex; flex-direction: column; gap: 22px; }

.welcome-card {
    position: relative;
    background: linear-gradient(135deg, #1b5e20, #2E7D32 55%, #43a047);
    border-radius: 20px;
    padding: 26px 32px;
    color: #fff;
    overflow: hidden;
    box-shadow: 0 12px 32px rgba(46,125,50,0.25);
}
.welcome-card::after {
    content: ''; position: absolute;
    right: -80px; top: -80px;
    width: 280px; height: 280px;
    border-radius: 50%;
    background: rgba(255,255,255,0.08);
    pointer-events: none;
}
.welcome-content {
    position: relative; z-index: 2;
    display: flex; justify-content: space-between;
    align-items: center; gap: 24px; flex-wrap: wrap;
}
.welcome-text h2 { font-size: 1.5rem; font-weight: 800; margin: 0 0 6px; }
.welcome-text p  { font-size: 0.9rem; margin: 0; opacity: 0.9; }
.welcome-date {
    background: rgba(255,255,255,0.18);
    backdrop-filter: blur(8px);
    padding: 12px 20px; border-radius: 12px;
    font-size: 0.9rem; font-weight: 700;
    display: inline-flex; align-items: center; gap: 10px;
    border: 1px solid rgba(255,255,255,0.25);
}

/* STATS */
.stats-grid-modern {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
}
.stat-card-modern {
    background: #fff; border-radius: 16px;
    padding: 18px 20px;
    display: flex; align-items: center; gap: 16px;
    border: 1px solid #e2efe8;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    transition: all 0.22s ease;
    position: relative; overflow: hidden;
}
.stat-card-modern::before {
    content: ''; position: absolute;
    left: 0; top: 0; bottom: 0; width: 4px;
    background: linear-gradient(180deg, #2E7D32, #66bb6a);
    opacity: 0; transition: opacity 0.22s;
}
.stat-card-modern:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 24px rgba(0,0,0,0.08);
    border-color: #a5d6a7;
}
.stat-card-modern:hover::before { opacity: 1; }

.stat-icon {
    width: 52px; height: 52px; border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.35rem; flex-shrink: 0;
}
.stat-icon.pending   { background: #fff3e0; color: #e65100; }
.stat-icon.approved  { background: #e8f5e9; color: #2e7d32; }
.stat-icon.completed { background: #e3f2fd; color: #1565c0; }
.stat-icon.today     { background: #f3e5f5; color: #9c27b0; }
.stat-icon.verified  { background: #e8f5e9; color: #43e97b; }
.stat-icon.escalated { background: #ffebee; color: #c62828; }
.stat-icon.print-queue { background: #ffebee; color: #c62828; }

.stat-info { flex: 1; min-width: 0; }
.stat-info h3 {
    font-size: 1.75rem; font-weight: 800;
    color: #1a2b22; margin: 0; line-height: 1.1;
    letter-spacing: -0.5px;
}
.stat-info p {
    margin: 4px 0 0; font-size: 0.78rem;
    color: #7a8a82; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.4px;
}

/* QUICK ACTIONS */
.quick-actions {
    background: #fff; border-radius: 16px;
    padding: 20px 22px;
    border: 1px solid #e2efe8;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}
.quick-actions h4 {
    margin: 0 0 14px; font-size: 0.95rem;
    color: #1a472a; display: flex; align-items: center; gap: 8px;
}
.quick-actions h4 i { color: #2E7D32; }
.action-buttons {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
}
.quick-action-btn {
    padding: 14px 18px;
    border: 1.5px solid #e2efe8; border-radius: 12px;
    background: #fff; font-size: 0.85rem;
    font-weight: 700; color: #1a472a;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 10px;
    transition: all 0.18s ease;
}
.quick-action-btn i { color: #2E7D32; font-size: 1rem; }
.quick-action-btn:hover {
    background: #2E7D32; border-color: #2E7D32;
    color: #fff; transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(46,125,50,0.25);
}
.quick-action-btn:hover i { color: #fff; }

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

.stat-values { padding: 10px 20px 20px; }
.value-item {
    display: flex; justify-content: space-between;
    align-items: center; padding: 12px 0;
    border-bottom: 1px dashed #eef2f0;
    font-size: 0.85rem;
}
.value-item:last-child { border-bottom: none; }
.value-label { color: #555; font-weight: 600; }
.value-number {
    font-weight: 800; font-size: 1rem;
    padding: 2px 12px; border-radius: 10px;
}
.value-number.warning { color: #e65100; background: #fff3e0; }
.value-number.success { color: #2e7d32; background: #e8f5e9; }
.value-number.info    { color: #1565c0; background: #e3f2fd; }
.value-number.danger  { color: #c62828; background: #ffebee; }

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
   TWO-COLUMN DASHBOARD LAYOUT
   Left  = Announcements  |  Right = Stats (2-per-row grid)
   ═══════════════════════════════════════════════════════════ */
.dash-two-col {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 22px;
    align-items: start;
}

.dash-left {
    display: flex;
    flex-direction: column;
    gap: 18px;
    min-width: 0;
}

.dash-right {
    display: flex;
    flex-direction: column;
    gap: 18px;
    min-width: 0;
}

/* Stats: 2 per row */
.stats-grid-compact {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

.stats-grid-compact .stat-card-modern {
    padding: 12px 12px;
    gap: 10px;
    flex-direction: column;
    align-items: flex-start;
    min-height: 96px;
    justify-content: space-between;
}

.stats-grid-compact .stat-icon {
    width: 34px;
    height: 34px;
    font-size: 0.9rem;
    border-radius: 9px;
}

.stats-grid-compact .stat-info h3 {
    font-size: 1.4rem;
    letter-spacing: -0.4px;
    line-height: 1;
}

.stats-grid-compact .stat-info p {
    font-size: 0.65rem;
    letter-spacing: 0.3px;
    margin-top: 3px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Announcement cards in left column */
/* In the left column: one big card per row (feels tall and prominent) */
.dash-left .annc-grid {
    grid-template-columns: 1fr;              /* one card per row */
    gap: 18px;
}
.dash-left .annc-card {
    min-height: 420px;                        /* tall, fills the column */
}

/* On wide screens: 2 cards side by side */
@media (min-width: 1400px) {
    .dash-left .annc-grid {
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    }
    .dash-left .annc-card {
        min-height: 400px;
    }
}

/* ═══════════════════════════════════════════════════════════
   RESPONSIVE
   ═══════════════════════════════════════════════════════════ */
@media (max-width: 1100px) {
    .dash-two-col {
        grid-template-columns: 1fr 300px;
        gap: 18px;
    }
}

@media (max-width: 900px) {
    .dash-two-col {
        grid-template-columns: 1fr;
    }
    .stats-grid-compact {
        grid-template-columns: repeat(2, 1fr);
    }
}
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
<button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
<div class="sidebar-overlay hide" id="sidebarOverlay"></div>

<div class="dashboard-container">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="brand">
                <div class="logo-container"><img src="../../logo.jpg" alt="Logo"></div>
                <h2>BRITE</h2>
            </div>
            <div class="sidebar-sub">San Bartolome, Sto Tomas, Pampanga</div>
        </div>
        <div class="nav-menu">
            <div class="nav-item active" data-view="dashboard"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></div>
            <div class="nav-item" data-view="complaints">
                <i class="fas fa-gavel"></i><span>Complaints</span>
                <span class="pending-count" id="complaintsPrintBadge" style="display:none;margin-left:auto;background:#c62828;color:#fff;border-radius:10px;padding:2px 8px;font-size:0.65rem;">0</span>
            </div>
            <div class="nav-item" data-view="document_requests"><i class="fas fa-clipboard-list"></i><span>Document Requests</span></div>
            <div class="nav-item" data-view="manage_documents"><i class="fas fa-cog"></i><span>Manage Document Types</span></div>

            <div class="nav-item" id="equipmentParent">
    <i class="fas fa-tools"></i><span>Equipments</span>
    <i class="fas fa-chevron-down toggle-icon" style="margin-left:auto;font-size:0.75rem;transition:transform .2s;"></i>
</div>
<div class="submenu" id="equipmentSubmenu">

    <div class="sub-option" data-subview="equipment_list"><i class="fas fa-list"></i> Equipment List</div>
    <div class="sub-option" data-subview="equipment_bookings"><i class="fas fa-calendar-alt"></i> Bookings</div>
</div>
            <div class="nav-item" data-view="household"><i class="fas fa-home"></i><span>Households</span></div>
            <div class="nav-item" data-view="reports"><i class="fas fa-chart-line"></i><span>Reports</span></div>
        </div>
        <div class="sidebar-footer">
            <div class="admin-badge">
                <div class="mini-avatar"><i class="fas fa-file-alt"></i></div>
                <div class="admin-info">
                    <h5><?php echo htmlspecialchars($admin['full_name']); ?></h5>
                    <p>Secretary</p>
                </div>
            </div>
        </div>
    </aside>

    <main class="main-content">
        <div class="top-header">
            <div class="page-title">
                <h1>Secretary Dashboard</h1>
                <p>Manage documents, process requests, and verify residents</p>
            </div>

            <div class="header-right-group">
                <button class="camera-scan-btn" id="qrScanBtn" type="button" aria-label="Scan QR" title="Scan QR to claim document">
                    <i class="fas fa-camera"></i>
                </button>

                <div class="notification-bell-wrap" id="notifBellWrap">
                    <button class="notification-bell" id="notifBell" type="button" aria-label="Notifications">
                        <i class="fas fa-bell"></i>
                        <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
                    </button>
                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-header">
                            <span><i class="fas fa-bell"></i> Notifications</span>
                            <button class="notif-mark-read" id="notifMarkRead" type="button">Mark all read</button>
                        </div>
                        <div class="notif-list" id="notifList">
                            <div class="notif-empty">Loading…</div>
                        </div>
                        <div class="notif-footer">
                            <label class="notif-permission-label">
                                <input type="checkbox" id="notifPushToggle">
                                <span>Enable desktop push notifications</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="profile-area" id="profileArea">
                    <div class="profile-text">
                        <div class="name"><?php echo htmlspecialchars($admin['full_name']); ?></div>
                        <div class="role">Secretary</div>
                    </div>
                    <div class="avatar-container">
                        <?php if (!empty($admin['profile_image']) && file_exists(__DIR__ . '/../../' . $admin['profile_image'])): ?>
                            <img src="../../<?php echo htmlspecialchars($admin['profile_image']); ?>" alt="Photo"
                                 style="width:100%;height:100%;border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                            <div class="avatar-fallback"><i class="fas fa-user-circle"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="profile-dropdown" id="profileDropdown">
                        <div class="dropdown-header">
                            <div class="user-name"><?php echo htmlspecialchars($admin['full_name']); ?></div>
                            <div class="user-email"><?php echo htmlspecialchars($admin['email']); ?></div>
                        </div>
                        <div class="dropdown-divider"></div>
                        <div class="dropdown-item" onclick="openPersonalSettings()">
                            <i class="fas fa-user-cog"></i><span>Personal Settings</span>
                        </div>
                        <div class="dropdown-divider"></div>
                        <div class="dropdown-item logout-item" id="logoutBtn"><i class="fas fa-sign-out-alt"></i><span>Logout</span></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="dashboard-body" id="dashboardBody"></div>
    </main>
</div>

<form id="logoutForm" method="POST" action="../../logout.php" style="display: none;"></form>

<?php include __DIR__ . '/admin_settings_modal.php'; ?>

<div id="qrCameraOverlay">
    <video id="qrHiddenVideo" playsinline webkit-playsinline muted autoplay></video>
    <canvas id="qrHiddenCanvas" style="display:none;"></canvas>
    <div class="qr-frame">
        <div class="qr-corner qr-tl"></div><div class="qr-corner qr-tr"></div>
        <div class="qr-corner qr-bl"></div><div class="qr-corner qr-br"></div>
    </div>
    <div class="qr-overlay-pill" id="qrScanPill"><i class="fas fa-camera"></i><span id="qrScanPillText">Scanning…</span></div>
    <button class="qr-overlay-close" id="qrOverlayClose" type="button" aria-label="Close scanner"><i class="fas fa-times"></i></button>
    <div class="qr-overlay-hint"><i class="fas fa-info-circle"></i> Point the QR code at the camera · Tap anywhere to close</div>
</div>

<div id="documentTypeModal" class="modal">
    <div class="modal-content doc-modal">
        <div class="modal-header">
            <h2 id="docTypeModalTitle"><i class="fas fa-file-alt"></i> Manage Document Type</h2>
            <button class="close-modal" onclick="closeDocumentTypeModal()">&times;</button>
        </div>
        <div class="modal-body" id="docTypeModalBody"></div>
    </div>
</div>

<div id="requestDetailsModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h2><i class="fas fa-file-alt"></i> Request Details</h2>
            <button class="close-modal" onclick="closeRequestDetailsModal()">&times;</button>
        </div>
        <div class="modal-body" id="requestDetailsModalBody">
            <div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div>
        </div>
        <div class="modal-footer"><button class="btn-close" onclick="closeRequestDetailsModal()">Close</button></div>
    </div>
</div>

<!-- ============ EQUIPMENT MODALS (Captain) ============ -->
<div id="equipmentModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width: 720px;">
        <div class="modal-header">
            <h2 id="equipmentModalTitle"><i class="fas fa-tools"></i> Equipment</h2>
            <button class="close-modal" onclick="closeEquipmentModal()">&times;</button>
        </div>
        <div class="modal-body" id="equipmentModalBody"></div>
    </div>
</div>

<div id="equipmentItemsModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h2 id="equipmentItemsModalTitle"><i class="fas fa-boxes"></i> Items</h2>
            <button class="close-modal" onclick="closeEquipmentItemsModal()">&times;</button>
        </div>
        <div class="modal-body" id="equipmentItemsModalBody"></div>
    </div>
</div>

<div id="manageItemModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width: 560px;">
        <div class="modal-header">
            <h2 id="manageItemModalTitle"><i class="fas fa-tools"></i> Manage Item</h2>
            <button class="close-modal" onclick="closeManageItemModal()">&times;</button>
        </div>
        <div class="modal-body" id="manageItemModalBody"></div>
    </div>
</div>

<div id="scheduleModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h2 id="scheduleModalTitle"><i class="fas fa-calendar-alt"></i> Schedule</h2>
            <button class="close-modal" onclick="closeScheduleModal()">&times;</button>
        </div>
        <div class="modal-body" id="scheduleModalBody"></div>
    </div>
</div>

<div id="dayBookingsModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h2 id="dayBookingsModalTitle"><i class="fas fa-calendar-day"></i> Bookings</h2>
            <button class="close-modal" onclick="closeDayBookingsModal()">&times;</button>
        </div>
        <div class="modal-body" id="dayBookingsModalBody"></div>
    </div>
</div>

<div id="bookingModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width: 720px;">
        <div class="modal-header">
            <h2><i class="fas fa-info-circle"></i> Booking Details</h2>
            <button class="close-modal" onclick="closeBookingModal()">&times;</button>
        </div>
        <div class="modal-body" id="bookingModalBody"></div>
    </div>
</div>

<div id="bookingApprovalModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width: 560px;">
        <div class="modal-header">
            <h2 id="bookingActionTitle"><i class="fas fa-check-circle"></i> Booking Action</h2>
            <button class="close-modal" onclick="closeBookingApprovalModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="bookingApprovalStatus" class="email-status" style="display:none;"></div>
            <div class="form-group">
                <label>Admin Notes (Optional)</label>
                <textarea id="bookingApprovalNotes" class="form-control" rows="3" placeholder="Add remarks..."></textarea>
            </div>
            <div class="form-buttons">
                <button type="button" id="confirmBookingBtn" class="btn-verify" onclick="submitBookingAction()"><i class="fas fa-check-circle"></i> Confirm</button>
                <button type="button" class="btn-close" onclick="closeBookingApprovalModal()">Cancel</button>
            </div>
        </div>
    </div>
</div>

<div id="returnModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width: 520px;">
        <div class="modal-header">
            <h2><i class="fas fa-undo-alt"></i> Return Item</h2>
            <button class="close-modal" onclick="closeReturnModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Return Notes (Optional)</label>
                <textarea id="returnNotes" class="form-control" rows="3" placeholder="Condition of returned item..."></textarea>
            </div>
            <div class="form-buttons">
                <button type="button" class="btn-verify" onclick="confirmReturn()"><i class="fas fa-check-circle"></i> Confirm Return</button>
                <button type="button" class="btn-close" onclick="closeReturnModal()">Cancel</button>
            </div>
        </div>
    </div>
</div>
<!-- ============ /EQUIPMENT MODALS ============ -->

<script>
    window.CURRENT_ROLE = 'secretary';
    window.CURRENT_ROLE_LABEL = 'Secretary';
</script>
<script src="secretary_equipment.js"></script>
<script src="secretary_household.js"></script>

<script>
// ============ GLOBAL ============
let currentFilterStatus = 'approved';
const DX = <?php echo $dxJson ?: '{}'; ?>;

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
        ? `background-image:url('../../${escapeHtml(a.image_url)}')`
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
                        <p>Announcements appear on the Secretary Dashboard until they expire.</p>
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
                                 style="${a.image_url ? `background-image:url('../../${escapeHtml(a.image_url)}')` : ''}">
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
let currentPage = 1;
let totalPages = 1;
let currentRequestsData = [];
let documentTypesData = <?php echo json_encode($documentTypes); ?>;
let initialPendingCount = <?php echo $pendingCount; ?>;

let pendingCaptainReviewCount = <?php echo $stats['pending_captain_review']; ?>;
let forReleaseCount = <?php echo $stats['for_release']; ?>;
let escalatedCount = <?php echo $stats['escalated_complaints']; ?>;
let pendingReviewCount = <?php echo $stats['pending_review']; ?>;
let initialPrintQueueCount = <?php echo $stats['print_queue'] ?? 0; ?>;

// ── Complaint data + toolbar state ──
let currentComplaintFilter = null;
let complaintData = [];

let caseFilters = {
    search: '',
    days: 30,
    dateFrom: '',
    dateTo: ''
};
let casePage = 1;
const CASES_PER_PAGE = 6;

// ============ HELPERS ============
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showSuccessModal(message, title = 'Success!') {
    Swal.fire({ title, text: message, icon: 'success', confirmButtonColor: '#2E7D32', timer: 2000, timerProgressBar: true });
}
function showErrorModal(message, title = 'Error!') {
    Swal.fire({ title, text: message, icon: 'error', confirmButtonColor: '#d33' });
}
function showWarningModal(message, title = 'Warning!') {
    Swal.fire({ title, text: message, icon: 'warning', confirmButtonColor: '#f39c12' });
}
function showConfirmationModal(message, title, onConfirm) {
    Swal.fire({
        title, text: message, icon: 'question',
        showCancelButton: true, confirmButtonColor: '#2E7D32', cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, proceed', cancelButtonText: 'Cancel'
    }).then((result) => { if (result.isConfirmed) onConfirm(); });
}
function formatDate(dateString) {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}
function ucwords(str) { return String(str || '').replace(/\b\w/g, l => l.toUpperCase()); }

function formatNestedValue(obj) {
    if (!obj) return '-';
    if (typeof obj === 'string') return escapeHtml(obj);
    if (Array.isArray(obj)) {
        if (obj.length === 0) return '-';
        if (obj[0] && typeof obj[0] === 'object') {
            let html = '<table class="children-table" style="width:100%; border-collapse: collapse;">';
            html += '<thead><tr><th style="background: #e8f5e9; padding: 8px;">Name</th><th style="background: #e8f5e9; padding: 8px;">Age</th><th style="background: #e8f5e9; padding: 8px;">Birth Date</th></tr></thead><tbody>';
            obj.forEach(child => {
                html += `<tr>
                    <td style="border-bottom: 1px solid #e2efe8; padding: 8px;">${escapeHtml(child.full_name || child.name || '-')}</td>
                    <td style="border-bottom: 1px solid #e2efe8; padding: 8px;">${escapeHtml(child.age || '-')}</td>
                    <td style="border-bottom: 1px solid #e2efe8; padding: 8px;">${escapeHtml(child.birthdate || '-')}</td>
                </tr>`;
            });
            html += '</tbody></table>';
            return html;
        }
        return obj.map(item => typeof item === 'object' ? formatNestedValue(item) : escapeHtml(item)).join(', ');
    }
    if (typeof obj === 'object') {
        let html = '<div style="margin-left: 10px;">';
        for (const [key, value] of Object.entries(obj)) {
            html += `<div><strong>${escapeHtml(ucwords(key.replace(/_/g, ' ')))}:</strong> ${formatNestedValue(value)}</div>`;
        }
        html += '</div>';
        return html;
    }
    return escapeHtml(String(obj));
}

// ============ DASHBOARD VIEW (analytics) ============
function renderDashboard() {
    const kpi = (icon, bg, fg, num, label, go) => `
        <div class="dx-kpi" ${go ? `data-go="${go}" onclick="navigateTo('${go}')"` : ''}>
            <div class="dx-kpi-ic" style="background:${bg};color:${fg};"><i class="fas ${icon}"></i></div>
            <div><div class="dx-kpi-num">${num}</div><div class="dx-kpi-lbl">${label}</div></div>
        </div>`;

    return `
    <div class="dx">

        <!-- TOP BAR -->
        <div class="dx-top">
            <div class="dx-top-text">
                <h2><span id="dxGreeting">Welcome</span>, Secretary <?php echo htmlspecialchars($admin['full_name']); ?>!</h2>
                <p><i class="fas fa-calendar-alt"></i><span id="currentDate"></span></p>
            </div>
            <div class="dx-top-actions">
                <button class="dx-top-btn" onclick="navigateTo('complaints')"><i class="fas fa-gavel"></i><span>Complaints</span></button>
                <button class="dx-top-btn" onclick="navigateTo('document_requests')"><i class="fas fa-clipboard-list"></i><span>Requests</span></button>
                <button class="dx-top-btn" onclick="navigateTo('manage_documents')"><i class="fas fa-cog"></i><span>Documents</span></button>
                <button class="dx-top-btn" onclick="openAnnouncementModal()"><i class="fas fa-bullhorn"></i><span>Announce</span></button>
            </div>
        </div>

        <!-- KPI STRIP -->
        <div class="dx-kpis">
            ${kpi('fa-clock',          '#fff3e0', '#e65100', <?php echo (int)$stats['pending_requests']; ?>,       'Pending',      'document_requests')}
            ${kpi('fa-check-circle',   '#e8f5e9', '#2e7d32', <?php echo (int)$stats['approved_requests']; ?>,      'Approved',     'document_requests')}
            ${kpi('fa-check-double',   '#e3f2fd', '#1565c0', <?php echo (int)$stats['claimed_requests']; ?>,       'Claimed',      'document_requests')}
            ${kpi('fa-calendar-day',   '#f3e5f5', '#9c27b0', <?php echo (int)$stats['today_requests']; ?>,         'Today',        'document_requests')}
            ${kpi('fa-user-clock',     '#e0f2f1', '#00897b', <?php echo (int)$stats['pending_verifications']; ?>,  'Verifications', '')}
            ${kpi('fa-clipboard-check','#fff8e1', '#f57c00', <?php echo (int)$stats['pending_review']; ?>,         'To Review',    'complaints')}
            ${kpi('fa-exclamation-triangle','#ffebee','#c62828', <?php echo (int)$stats['escalated_complaints']; ?>,'Escalated',    'complaints')}
            ${kpi('fa-print',          '#fce4ec', '#ad1457', <?php echo (int)$stats['print_queue']; ?>,            'To Print',     'complaints')}
        </div>

        <!-- MAIN GRID -->
        <div class="dx-grid">

            <!-- Trend -->
            <div class="dx-card dx-trend">
                <div class="dx-card-h">
                    <h4><i class="fas fa-chart-line"></i> <span id="dxTrendTitle">6-Month Trend</span></h4>
                    <span id="dxDelta"></span>
                    <div class="dx-seg" id="dxTrendSeg">
                        <button class="active" onclick="dxSetTrend('trend', this)">Trend</button>
                        <button onclick="dxSetTrend('top', this)">Top Documents</button>
                    </div>
                </div>
                <div class="dx-chart"><canvas id="dxTrendCanvas"></canvas></div>
            </div>

            <!-- Status doughnut -->
            <div class="dx-card dx-status">
                <div class="dx-card-h">
                    <h4><i class="fas fa-chart-pie"></i> Status</h4>
                    <div class="dx-seg" id="dxStatusSeg">
                        <button class="active" onclick="dxSetStatus('docs', this)">Documents</button>
                        <button onclick="dxSetStatus('complaints', this)">Cases</button>
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

            <!-- Announcements -->
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

            <!-- Recent requests -->
            <div class="dx-card dx-recent">
                <div class="dx-card-h">
                    <h4><i class="fas fa-file-alt"></i> Recent Requests</h4>
                    <button class="view-all-btn" onclick="navigateTo('document_requests')">View all <i class="fas fa-arrow-right"></i></button>
                </div>
                <div class="dx-recent-list activity-list" id="recentRequestsList">
                    <div class="loading-spinner-mini"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                </div>
            </div>

        </div>
    </div>
    `;
}

// ============ DOCUMENT REQUESTS ============
async function loadFilteredRequests(status = 'all', page = 1) {
    currentFilterStatus = status;
    currentPage = page;
    const container = document.getElementById('requestsContainer');
    if (container) {
        container.innerHTML = `
            <div class="filter-bar">
                <button class="filter-chip ${status === 'all' ? 'active' : ''}" onclick="loadFilteredRequests('all', 1)">All</button>
                <button class="filter-chip ${status === 'pending' ? 'active' : ''}" onclick="loadFilteredRequests('pending', 1)">Pending ${initialPendingCount > 0 ? `<span class="pending-count">${initialPendingCount}</span>` : ''}</button>
                <button class="filter-chip ${status === 'approved' ? 'active' : ''}" onclick="loadFilteredRequests('approved', 1)">Approved</button>
                <button class="filter-chip ${status === 'unclaimed' ? 'active' : ''}" onclick="loadFilteredRequests('unclaimed', 1)">Unclaimed</button>
                <button class="filter-chip ${status === 'claimed' ? 'active' : ''}" onclick="loadFilteredRequests('claimed', 1)">Claimed</button>
                <button class="filter-chip ${status === 'rejected' ? 'active' : ''}" onclick="loadFilteredRequests('rejected', 1)">Rejected</button>
            </div>
            <div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading requests...</p></div>
        `;
    }
    try {
        const response = await fetch(`?ajax_action=filter_requests&status=${status}&page=${page}`);
        const data = await response.json();
        if (data.success) {
            currentRequestsData = data.data;
            totalPages = data.totalPages;
            renderRequestsList();
        }
    } catch (error) { console.error(error); }
}

function renderRequestsList() {
    const container = document.getElementById('requestsContainer');
    if (!container) return;
    let filterBarHtml = `
        <div class="filter-bar">
            <button class="filter-chip ${currentFilterStatus === 'all' ? 'active' : ''}" onclick="loadFilteredRequests('all', 1)">All</button>
            <button class="filter-chip ${currentFilterStatus === 'pending' ? 'active' : ''}" onclick="loadFilteredRequests('pending', 1)">Pending ${initialPendingCount > 0 ? `<span class="pending-count">${initialPendingCount}</span>` : ''}</button>
            <button class="filter-chip ${currentFilterStatus === 'approved' ? 'active' : ''}" onclick="loadFilteredRequests('approved', 1)">Approved</button>
            <button class="filter-chip ${currentFilterStatus === 'unclaimed' ? 'active' : ''}" onclick="loadFilteredRequests('unclaimed', 1)">Unclaimed</button>
            <button class="filter-chip ${currentFilterStatus === 'claimed' ? 'active' : ''}" onclick="loadFilteredRequests('claimed', 1)">Claimed</button>
            <button class="filter-chip ${currentFilterStatus === 'rejected' ? 'active' : ''}" onclick="loadFilteredRequests('rejected', 1)">Rejected</button>
        </div>`;
    if (currentRequestsData.length === 0) {
        container.innerHTML = `${filterBarHtml}<div class="empty-state"><i class="fas fa-inbox fa-3x"></i><p>No document requests found.</p></div>`;
        return;
    }
    const cardsHtml = currentRequestsData.map(req => {
        let certificateHolder = '';
        if (req.custom_fields) {
            const fields = ['full_name','resident_name','name','certificate_name','applicant_name','requestor_name','fullname'];
            for (const field of fields) {
                if (req.custom_fields[field]) {
                    let v = req.custom_fields[field];
                    try {
                        const p = JSON.parse(v);
                        certificateHolder = (typeof p === 'object' && p.full_name) ? p.full_name : (typeof p === 'string' ? p : v);
                    } catch(e) { certificateHolder = v; }
                    break;
                }
            }
        }

        const statusMap = {
            pending:   { icon: 'fa-clock',         label: 'Pending'   },
            approved:  { icon: 'fa-check-circle',  label: 'Approved'  },
            unclaimed: { icon: 'fa-inbox',         label: 'Unclaimed' },
            claimed:   { icon: 'fa-check-double',  label: 'Claimed'   },
            rejected:  { icon: 'fa-times-circle',  label: 'Rejected'  }
        };
        const st = statusMap[req.status] || { icon: 'fa-file-alt', label: req.status };

        return `
            <div class="document-card" onclick="viewRequestDetails(${req.id})">
                <div class="document-card-header ${req.status}">
                    <div class="document-title"><i class="fas fa-file-alt"></i> <span>${escapeHtml(req.document_type)}</span></div>
                    <div class="document-status"><i class="fas ${st.icon}"></i> ${st.label}</div>
                </div>
                <div class="document-card-body">
                    <div class="document-info-row"><div class="document-info-label">Resident:</div><div class="document-info-value"><strong>${escapeHtml(req.first_name)} ${escapeHtml(req.last_name)}</strong></div></div>
                    <div class="document-info-row"><div class="document-info-label">Cert. For:</div><div class="document-info-value">${certificateHolder ? escapeHtml(certificateHolder) : 'Same as resident'}</div></div>
                    <div class="document-info-row"><div class="document-info-label">Date:</div><div class="document-info-value">${formatDate(req.request_date)}</div></div>
                    <div class="document-info-row"><div class="document-info-label">Purpose:</div><div class="document-info-value">${escapeHtml(req.purpose || '-')}</div></div>
                </div>
                <div class="document-card-actions" onclick="event.stopPropagation()">
                    ${req.status === 'pending'
                        ? `<button class="doc-action-btn reject" onclick="event.stopPropagation(); showRejectPrompt(${req.id})"><i class="fas fa-times-circle"></i> Reject</button>
                           <button class="doc-action-btn view" onclick="event.stopPropagation(); viewRequestDetails(${req.id})"><i class="fas fa-eye"></i> View</button>`
                        : (req.status === 'approved' || req.status === 'unclaimed' || req.status === 'claimed')
                        ? `<button class="doc-action-btn view" onclick="event.stopPropagation(); viewGeneratedDocument(${req.id})"><i class="fas fa-file-pdf"></i> View</button>
                           <button class="doc-action-btn print" onclick="event.stopPropagation(); printDocument(${req.id})"><i class="fas fa-print"></i> Print</button>`
                        : `<button class="doc-action-btn view" onclick="event.stopPropagation(); viewRequestDetails(${req.id})"><i class="fas fa-eye"></i> View</button>`}
                </div>
            </div>`;
    }).join('');
    container.innerHTML = `${filterBarHtml}<div class="documents-grid">${cardsHtml}</div>`;
}

function renderDocumentRequestsView() {
    return `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-clipboard-list"></i> Document Requests</div>
            <div class="section-sub">Process and manage document requests from residents</div>
            <div id="requestsContainer">
                <div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading requests...</p></div>
            </div>
        </div>`;
}

// ============ MANAGE DOCUMENTS ============
function renderManageDocumentsView() {
    const cardsHtml = (documentTypesData || []).map(doc => `
        <div class="doc-type-card">
            <div class="doc-type-header">
                <span class="doc-type-name"><i class="fas fa-file-contract"></i> ${escapeHtml(doc.name)}</span>
                <span class="doc-type-fee">₱${parseFloat(doc.fee).toFixed(2)}</span>
            </div>
            <div style="font-size: 0.8rem; color: #666; margin-bottom: 10px;">${escapeHtml(doc.description || 'No description')}</div>
            <div><span class="status-badge ${doc.is_active ? 'status-verified' : 'status-unverified'}">${doc.is_active ? 'Active' : 'Inactive'}</span></div>
            <div class="doc-type-actions">
                <button class="type-action-btn edit" onclick="editDocumentType(${doc.id})"><i class="fas fa-edit"></i> Edit</button>
                <button class="type-action-btn delete" onclick="deleteDocumentType(${doc.id}, '${escapeHtml(doc.name)}')"><i class="fas fa-trash"></i> Delete</button>
            </div>
        </div>`).join('');
    return `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-cog"></i> Manage Document Types</div>
            <div class="section-sub">Configure document types, fees, and availability</div>
            <div style="margin-bottom:20px;"><button class="btn-verify" onclick="showAddDocumentTypeModal()"><i class="fas fa-plus"></i> Add New Document Type</button></div>
            ${cardsHtml ? `<div class="documents-grid">${cardsHtml}</div>` : '<div class="empty-state"><p>No document types found.</p></div>'}
        </div>`;
}

function showAddDocumentTypeModal() {
    const modal = document.getElementById('documentTypeModal');
    document.getElementById('docTypeModalTitle').innerHTML = '<i class="fas fa-plus"></i> Add New Document Type';
    document.getElementById('docTypeModalBody').innerHTML = `
        <form onsubmit="saveDocumentType(event)">
            <div class="form-group"><label>Document Name *</label><input type="text" id="docName" class="form-control" required></div>
            <div class="form-group"><label>Description</label><textarea id="docDescription" class="form-control" rows="3"></textarea></div>
            <div class="form-group"><label>Fee (₱) *</label><input type="number" id="docFee" class="form-control" step="0.01" min="0" required value="0"></div>
            <div class="form-group"><label>Status</label><select id="docStatus" class="form-control"><option value="1">Active</option><option value="0">Inactive</option></select></div>
            <div class="form-buttons">
                <button type="submit" class="btn-verify"><i class="fas fa-save"></i> Save</button>
                <button type="button" class="btn-close" onclick="closeDocumentTypeModal()">Cancel</button>
            </div>
        </form>`;
    modal.style.display = 'block';
}

async function saveDocumentType(event) {
    event.preventDefault();
    const fd = new FormData();
    fd.append('ajax_action', 'add_document');
    fd.append('name', document.getElementById('docName').value);
    fd.append('description', document.getElementById('docDescription').value);
    fd.append('fee', document.getElementById('docFee').value);
    fd.append('is_active', document.getElementById('docStatus').value);
    try {
        const r = await fetch(window.location.href, { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) { showSuccessModal('Document type added!'); closeDocumentTypeModal(); setTimeout(() => window.location.reload(), 800); }
        else showErrorModal(d.message || 'Failed');
    } catch (e) { showErrorModal('Error'); }
}

async function editDocumentType(id) {
    const modal = document.getElementById('documentTypeModal');
    document.getElementById('docTypeModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Document Type';
    document.getElementById('docTypeModalBody').innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    modal.style.display = 'block';
    const fd = new FormData();
    fd.append('ajax_action', 'get_document');
    fd.append('id', id);
    try {
        const r = await fetch(window.location.href, { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) {
            const doc = d.data;
            document.getElementById('docTypeModalBody').innerHTML = `
                <form onsubmit="updateDocumentType(event, ${doc.id})">
                    <div class="form-group"><label>Document Name *</label><input type="text" id="docName" class="form-control" required value="${escapeHtml(doc.name)}"></div>
                    <div class="form-group"><label>Description</label><textarea id="docDescription" class="form-control" rows="3">${escapeHtml(doc.description || '')}</textarea></div>
                    <div class="form-group"><label>Fee (₱) *</label><input type="number" id="docFee" class="form-control" step="0.01" min="0" required value="${doc.fee}"></div>
                    <div class="form-group"><label>Status</label><select id="docStatus" class="form-control">
                        <option value="1" ${doc.is_active == 1 ? 'selected' : ''}>Active</option>
                        <option value="0" ${doc.is_active == 0 ? 'selected' : ''}>Inactive</option>
                    </select></div>
                    <div class="form-buttons">
                        <button type="submit" class="btn-verify"><i class="fas fa-save"></i> Update</button>
                        <button type="button" class="btn-close" onclick="closeDocumentTypeModal()">Cancel</button>
                    </div>
                </form>`;
        }
    } catch (e) {}
}

async function updateDocumentType(event, id) {
    event.preventDefault();
    const fd = new FormData();
    fd.append('ajax_action', 'update_document');
    fd.append('id', id);
    fd.append('name', document.getElementById('docName').value);
    fd.append('description', document.getElementById('docDescription').value);
    fd.append('fee', document.getElementById('docFee').value);
    fd.append('is_active', document.getElementById('docStatus').value);
    const r = await fetch(window.location.href, { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success) { showSuccessModal('Updated!'); closeDocumentTypeModal(); setTimeout(() => window.location.reload(), 800); }
    else showErrorModal(d.message || 'Failed');
}

function deleteDocumentType(id, name) {
    showConfirmationModal(`Delete "${name}"?`, 'Confirm Delete', async () => {
        const fd = new FormData();
        fd.append('ajax_action', 'delete_document');
        fd.append('id', id);
        const r = await fetch(window.location.href, { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) { showSuccessModal('Deleted!'); setTimeout(() => window.location.reload(), 800); }
        else showErrorModal(d.message || 'Failed');
    });
}

function closeDocumentTypeModal() {
    const m = document.getElementById('documentTypeModal');
    if (m) m.style.display = 'none';
}

// ============ REQUEST ACTIONS ============
function showRejectPrompt(id) {
    Swal.fire({
        title: 'Reject Request',
        input: 'textarea',
        inputLabel: 'Reason for rejection',
        inputPlaceholder: 'Enter the reason...',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Reject',
        inputValidator: (value) => { if (!value || !value.trim()) return 'Please enter a reason'; }
    }).then((result) => { if (result.isConfirmed) rejectRequest(id, result.value.trim()); });
}

async function rejectRequest(id, reason) {
    const fd = new FormData();
    fd.append('doc_action', 'update_request_status');
    fd.append('request_id', id);
    fd.append('status', 'rejected');
    fd.append('admin_notes', reason);
    try {
        const r = await fetch('../dashboard.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) { showSuccessModal('Request rejected'); loadFilteredRequests(currentFilterStatus, currentPage); }
        else showErrorModal(d.message || 'Failed');
    } catch (e) { showErrorModal('Network error'); }
}

async function viewRequestDetails(id) {
    const modal = document.getElementById('requestDetailsModal');
    const body  = document.getElementById('requestDetailsModalBody');
    if (!modal || !body) return;
    modal.style.display = 'block';
    body.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div>';
    const fd = new FormData();
    fd.append('doc_action', 'get_request_details');
    fd.append('request_id', id);
    try {
        const r = await fetch('../dashboard.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (!d.success || !d.request) {
            body.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-triangle fa-3x"></i><p>${escapeHtml((d && d.message) || 'Could not load request details.')}</p></div>`;
            return;
        }
        const req = d.request;
        const custom = req.custom_fields || req.custom_data || {};
        const statusMap = {
            pending:   { icon: 'fa-clock',        label: 'Pending'   },
            approved:  { icon: 'fa-check-circle', label: 'Approved'  },
            unclaimed: { icon: 'fa-inbox',        label: 'Unclaimed' },
            claimed:   { icon: 'fa-check-double', label: 'Claimed'   },
            rejected:  { icon: 'fa-times-circle', label: 'Rejected'  }
        };
        const st = statusMap[req.status] || { icon: 'fa-file-alt', label: req.status || '-' };
        const skipKeys = ['detected_type'];
        let customHtml = '';
        if (custom && typeof custom === 'object') {
            const rows = [];
            for (const [key, value] of Object.entries(custom)) {
                if (skipKeys.includes(key)) continue;
                if (value === null || value === '' || value === ' ') continue;
                let displayValue;
                if (key === 'children') {
                    try {
                        const parsed = typeof value === 'string' ? JSON.parse(value) : value;
                        displayValue = formatNestedValue(parsed);
                    } catch(e) { displayValue = escapeHtml(String(value)); }
                } else displayValue = escapeHtml(String(value));
                rows.push(`<tr><td>${escapeHtml(ucwords(key.replace(/_/g, ' ')))}</td><td>${displayValue}</td></tr>`);
            }
            if (rows.length) {
                customHtml = `<div class="req-detail-section"><h4><i class="fas fa-clipboard-list"></i> Additional Information</h4><table class="req-custom-table">${rows.join('')}</table></div>`;
            }
        }
        let idDocHtml = '';
        if (req.id_document_path) {
            const isPdf = req.id_document_path.toLowerCase().endsWith('.pdf');
            idDocHtml = `
                <div class="req-detail-section">
                    <h4><i class="fas fa-id-card"></i> Valid ID Submitted</h4>
                    ${isPdf ? `<a class="view-id-btn" href="../../${escapeHtml(req.id_document_path)}" target="_blank"><i class="fas fa-file-pdf"></i> Open PDF ID</a>`
                            : `<div class="id-document-preview"><img src="../../${escapeHtml(req.id_document_path)}" alt="ID Document"><a class="view-id-btn" href="../../${escapeHtml(req.id_document_path)}" target="_blank"><i class="fas fa-external-link-alt"></i> View Full Size</a></div>`}
                </div>`;
        }
        let adminNotesHtml = '';
        if (req.admin_notes && req.admin_notes.trim() !== '') {
            adminNotesHtml = `<div class="req-detail-section"><h4><i class="fas fa-user-shield"></i> Admin Notes / Response</h4><div style="background:#f8faf8; padding:12px; border-radius:8px; border-left:4px solid #43e97b; font-size:0.85rem; color:#333;">${escapeHtml(req.admin_notes).replace(/\n/g,'<br>')}</div></div>`;
        }
        const tRows = [];
        if (req.request_date)   tRows.push(['Requested',        formatDate(req.request_date)]);
        if (req.approved_at)    tRows.push(['Approved',         formatDate(req.approved_at)]);
        if (req.rejected_at)    tRows.push(['Rejected',         formatDate(req.rejected_at)]);
        if (req.claimed_at)     tRows.push(['Claimed',          formatDate(req.claimed_at)]);
        if (req.pickup_date)    tRows.push(['Pickup Schedule',  formatDate(req.pickup_date) + (req.pickup_time ? ' at ' + escapeHtml(req.pickup_time) : '')]);
        const timestampsHtml = tRows.length ? `<div class="req-detail-section"><h4><i class="fas fa-clock"></i> Timeline</h4><table class="req-custom-table">${tRows.map(([k,v]) => `<tr><td>${k}</td><td>${v}</td></tr>`).join('')}</table></div>` : '';
        body.innerHTML = `
            <div class="req-detail-wrap">
                <div class="req-detail-badge ${req.status}"><i class="fas ${st.icon}"></i> ${st.label}</div>
                <div class="req-detail-grid">
                    <div class="req-detail-item"><span class="lbl">Document</span><span class="val">${escapeHtml(req.document_type || '-')}</span></div>
                    <div class="req-detail-item"><span class="lbl">Quantity</span><span class="val">${escapeHtml(String(req.quantity || 1))} copy/copies</span></div>
                    <div class="req-detail-item"><span class="lbl">Resident</span><span class="val">${escapeHtml((req.first_name || '') + ' ' + (req.last_name || ''))}</span></div>
                    <div class="req-detail-item"><span class="lbl">Email</span><span class="val">${escapeHtml(req.email || '-')}</span></div>
                    <div class="req-detail-item"><span class="lbl">Fee Type</span><span class="val">${escapeHtml(ucwords(req.fee_type || 'regular'))}</span></div>
                    <div class="req-detail-item"><span class="lbl">Fee</span><span class="val">₱${parseFloat(req.fee || 0).toFixed(2)}</span></div>
                    <div class="req-detail-item" style="grid-column: 1 / -1;"><span class="lbl">Purpose</span><span class="val">${escapeHtml(req.purpose || '-')}</span></div>
                </div>
                ${customHtml}
                ${idDocHtml}
                ${adminNotesHtml}
                ${timestampsHtml}
            </div>
        `;
    } catch (e) {
        body.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle fa-3x"></i><p>Network error. Please try again.</p></div>';
    }
}

function closeRequestDetailsModal() {
    const m = document.getElementById('requestDetailsModal');
    if (m) m.style.display = 'none';
}

function viewGeneratedDocument(id) { window.open(`../certification.php?request_id=${id}`, '_blank'); }
function printDocument(id) { window.open(`../print_document.php?request_id=${id}`, '_blank'); }

// ============ OTHER VIEWS ============
function renderVerifyResidentsView() {
    return `<div class="content-card"><div class="section-title"><i class="fas fa-user-check"></i> Pending Verifications</div><div class="empty-state"><p>Verification module coming soon.</p></div></div>`;
}
function renderResidentListView() {
    return `<div class="content-card"><div class="section-title"><i class="fas fa-users"></i> Residents</div><div class="empty-state"><p>Resident list coming soon.</p></div></div>`;
}
function renderReportsView() {
    return `<div class="content-card"><div class="section-title"><i class="fas fa-chart-line"></i> Reports</div><div class="empty-state"><p>Reports coming soon.</p></div></div>`;
}
function generateReport() { navigateTo('reports'); }

// ============================================================
// COMPLAINT MODULE (SECRETARY)
// ============================================================
const COMPLAINT_STAGES = {
    all: {
        label: 'All', icon: 'fa-list', color: '#2E7D32',
        description: 'All complaints',
        statuses: null,
    },
    intake: {
        label: 'Under Review', icon: 'fa-file-alt', color: '#f57c00',
        description: 'Filed and awaiting Captain\'s decision',
        statuses: ['pending_review', 'pending_captain_action'],
        actor: 'Secretary / Captain',
    },
    mediation: {
        label: 'Mediation', icon: 'fa-handshake', color: '#6a1b9a',
        description: 'Lupon mediation in progress',
        statuses: ['summoned', 'for_mediation', 'mediation_scheduled', 'in_mediation'],
        actor: 'Captain / Lupon',
    },
    pangkat_constitution: {
        label: 'Pangkat Forming', icon: 'fa-users-cog', color: '#4a148c',
        description: 'Captain is constituting the Pangkat ng Tagapagkasundo',
        statuses: ['failed_mediation', 'pangkat_constitution_scheduled', 'pangkat_constituted'],
        actor: 'Captain',
    },
    pangkat_conciliation: {
        label: 'Pangkat Hearing', icon: 'fa-gavel', color: '#00838f',
        description: 'Pangkat ng Tagapagkasundo is hearing the case',
        statuses: ['pangkat_scheduled'],
        actor: 'Pangkat',
    },
    settled_pending: {
        label: 'Settlement Pending', icon: 'fa-hourglass-half', color: '#2e7d32',
        description: 'Amicable settlement signed — 10-day repudiation window',
        statuses: ['settled_pending'],
        actor: 'Barangay',
    },
    resolved: {
        label: 'Resolved', icon: 'fa-check-double', color: '#1b5e20',
        description: 'Settled / certificate issued',
        statuses: ['settled', 'certificate_issued'],
        actor: 'Captain / Pangkat',
    },
    unresolved: {
        label: 'Unresolved', icon: 'fa-times-circle', color: '#b71c1c',
        description: 'Mediation and conciliation both failed',
        statuses: ['unresolved', 'failed_conciliation_final'],
        actor: 'Pangkat',
    },
    referred: {
        label: 'Referred / Escalated', icon: 'fa-paper-plane', color: '#8e24aa',
        description: 'Referred to proper agency (outside KP jurisdiction)',
        statuses: ['escalated', 'pending_captain_review', 'referred_dispatched', 'released', 'referred'],
        actor: 'Barangay / PNP / Court',
    },
    dismissed: {
        label: 'Dismissed', icon: 'fa-ban', color: '#555',
        description: 'Withdrawn or dropped',
        statuses: ['dismissed'],
        actor: 'Complainant / Barangay',
    },
};

const STATUS_TO_STAGE = (() => {
    const map = {};
    for (const [key, stage] of Object.entries(COMPLAINT_STAGES)) {
        if (!stage.statuses) continue;
        for (const s of stage.statuses) {
            if (!map[s]) map[s] = key;
        }
    }
    return map;
})();

function stageForStatus(status) {
    return STATUS_TO_STAGE[status] || 'intake';
}

function computeStageCounts(list) {
    const counts = { all: list.length };
    for (const key of Object.keys(COMPLAINT_STAGES)) {
        if (key === 'all') continue;
        counts[key] = 0;
    }
    list.forEach(c => {
        const stageKey = stageForStatus(c.status);
        if (counts[stageKey] !== undefined) counts[stageKey]++;
    });
    return counts;
}

function filterByStage(list, stageKey) {
    if (stageKey === 'all') return list;
    const stage = COMPLAINT_STAGES[stageKey];
    if (!stage || !stage.statuses) return list;
    const set = new Set(stage.statuses);
    return list.filter(c => set.has(c.status));
}

// ============================================================
// INTEGRITY GUARD — Category-based content checks
// ============================================================

const _GUARD_PLACEHOLDERS = [
    'test','testing','tester','sample','n/a','na','none','null','nil',
    'anonymous','anon','asdf','asdfasdf','asdfgh','qwerty','qwertyuiop',
    'zxcvbn','zxcvbnm','hjkl','xxx','xxxx','aaaa','bbbb','cccc',
    'abc','abcd','abcde','abcdef','123','1234','12345','name','fullname',
    'firstname','lastname','first name','last name','no name','noname',
    'unknown','noone','nobody','john doe','jane doe','juan dela cruz',
    'juandelacruz','juandc','juan d','juan','pedro','pedro penduko',
    'lorem ipsum','lorem','ipsum','placeholder','tbd','tba','todo',
    'asdfasdfasdf','asdasd','asdasdasd','hahaha','hahahaha','hehehe',
    'jejeje','lol','lmao','wtf','test test','test name','name here'
];

const _GUARD_JUNK = [
    'test','testing','sample','asdf','asdfasdf','asdfgh','qwerty','zxcvbn',
    'hjkl','123','1234','12345','aaaa','bbbb','cccc','dddd','abc','abcd',
    'abcde','lorem','ipsum','none','n/a','na','null','xxx','yyy','zzz'
];

const _GUARD_PROFANITY = [
    'bobo','tanga','gago','gaga','tarantado','tarantada','putangina',
    'putang ina','puta','pakyu','fuck','fuckyou','shit','bitch','bastard',
    'asshole','dick','pussy','ulol','engot','inutil','lintik','hayop ka',
    'leche','punyeta','peste','pokpok','burat','titi','puke','kantok',
    'kantot','jakol','bilat','bayag','pekpek'
];

const GUARD_CATEGORIES = {
    placeholder: {
        label: 'Placeholder Content',
        short: 'Placeholder',
        icon: 'fa-user-slash',
        color: '#e65100',
        advice: 'The complainant name looks like a placeholder (e.g. "test", "Juan Dela Cruz"). Confirm the person is real.',
    },
    gibberish: {
        label: 'Gibberish Text',
        short: 'Gibberish',
        icon: 'fa-keyboard',
        color: '#c62828',
        advice: 'The title or description contains random characters or keyboard mash. Ask the complainant to resubmit with readable text.',
    },
    spam: {
        label: 'Spam / Repeated Filing',
        short: 'Spam',
        icon: 'fa-bolt',
        color: '#c62828',
        advice: 'The same user filed another complaint within 60 seconds. Confirm this is a distinct incident.',
    },
    duplicate: {
        label: 'Duplicate Complaint',
        short: 'Duplicate',
        icon: 'fa-copy',
        color: '#b71c1c',
        advice: 'An IDENTICAL complaint (same title AND same description) was already filed by the same user. Recommend dismissing this as a duplicate.',
    },
    possible_duplicate: {
        label: 'Possible Duplicate',
        short: 'Possible Dup',
        icon: 'fa-clone',
        color: '#e65100',
        advice: 'A SIMILAR complaint (same title OR same description) was already filed by the same user. Verify whether this is a distinct incident.',
    },
    inappropriate: {
        label: 'Inappropriate Language',
        short: 'Inappropriate',
        icon: 'fa-exclamation-triangle',
        color: '#b71c1c',
        advice: 'The complaint contains profanity or offensive terms. Ask the complainant to resubmit professionally.',
    },
    incomplete: {
        label: 'Incomplete / Too Short',
        short: 'Incomplete',
        icon: 'fa-ruler-horizontal',
        color: '#f57c00',
        advice: 'The description is too short to act on. Request more details from the complainant.',
    },
    suspicious: {
        label: 'Suspicious Pattern',
        short: 'Suspicious',
        icon: 'fa-shield-alt',
        color: '#b71c1c',
        advice: 'Multiple issues detected. Review carefully before forwarding.',
    },
};

function _guardNorm(s) {
    return String(s || '')
        .toLowerCase()
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function _guardCheckName(name) {
    const raw = String(name || '').trim();
    if (!raw) return { bad: true, reason: 'Missing name' };

    const norm = raw.toLowerCase().replace(/\s+/g, ' ');
    const normNoSpace = norm.replace(/\s/g, '');

    if (_GUARD_PLACEHOLDERS.includes(norm) || _GUARD_PLACEHOLDERS.includes(normNoSpace)) {
        return { bad: true, reason: `Placeholder name "${raw}"` };
    }

    const mash = ['asdf','asdfg','asdfgh','qwerty','qwertyu','zxcvb','zxcvbn',
                  'hjkl','fghj','dfgh','sdfg','sdfgh','xcvbn'];
    for (const w of norm.split(' ')) {
        if (mash.includes(w)) return { bad: true, reason: `Keyboard mash in name "${raw}"` };
    }

    if (/(.)\1{2,}/u.test(raw)) {
        return { bad: true, reason: `Name has repeated characters "${raw}"` };
    }

    const flat = raw.toLowerCase().replace(/[^a-zñáéíóú]/g, '');
    if (/[bcdfghjklmnpqrstvwxzñ]{4,}/i.test(flat)) {
        return { bad: true, reason: `Name looks gibberish "${raw}"` };
    }

    return { bad: false, reason: '' };
}

function _guardCheckWording(text, minLen, label) {
    const raw = String(text || '').trim();
    if (!raw) return { bad: true, reason: `${label} is empty` };
    if (raw.length < minLen) return { bad: true, reason: `${label} is too short (${raw.length} chars)` };

    if (/(.)\1{3,}/u.test(raw)) {
        return { bad: true, reason: `${label} repeats characters` };
    }

    const norm = raw.toLowerCase().replace(/[^\p{L}\p{N}\s]/gu, ' ').replace(/\s+/g, ' ').trim();
    const stripped = norm.replace(/\s/g, '');

    if (_GUARD_JUNK.includes(norm) || _GUARD_JUNK.includes(stripped)) {
        return { bad: true, reason: `${label} is junk text` };
    }

    if (!/[aeiouáéíóú]/i.test(raw)) {
        return { bad: true, reason: `${label} has no vowels` };
    }

    const tokens = norm.split(' ').filter(Boolean);
    if (tokens.length >= 4) {
        let run = 1, maxRun = 1, prev = tokens[0];
        for (let i = 1; i < tokens.length; i++) {
            if (tokens[i] === prev) { run++; maxRun = Math.max(maxRun, run); }
            else { run = 1; prev = tokens[i]; }
        }
        if (maxRun >= 4) return { bad: true, reason: `${label} repeats the same word` };
    }

    if (tokens.length > 0) {
        const allProfane = tokens.every(w =>
            _GUARD_PROFANITY.some(p => w.includes(p) || w === p)
        );
        if (allProfane) return { bad: true, reason: `${label} contains only profanity` };
    }

    return { bad: false, reason: '' };
}

function guardScoreComplaint(c, allList) {
    if (c.status !== 'pending_review') {
        return { level: 'clean', categories: [], reasons: [], tags: [] };
    }

    const reasons = [];
    const found   = new Set();

    const compNames = (c.complainant_names || c.complainants_full_names || '').split('|').filter(Boolean);
    if (compNames.length) {
        for (const nm of compNames) {
            const chk = _guardCheckName(nm);
            if (chk.bad) {
                reasons.push(chk.reason);
                if (/placeholder|juan|pedro|name here/i.test(chk.reason)) found.add('placeholder');
                else if (/gibberish|mash|repeated/i.test(chk.reason))     found.add('gibberish');
                else if (/missing/i.test(chk.reason))                     found.add('incomplete');
                break;
            }
        }
    } else if (c.first_name || c.last_name) {
        const full = `${c.first_name || ''} ${c.last_name || ''}`.trim();
        const chk = _guardCheckName(full);
        if (chk.bad) {
            reasons.push(chk.reason);
            if (/placeholder|juan|pedro|name here/i.test(chk.reason)) found.add('placeholder');
            else if (/gibberish|mash|repeated/i.test(chk.reason))     found.add('gibberish');
        }
    }

    {
        const chk = _guardCheckWording(c.title, 5, 'Title');
        if (chk.bad) {
            reasons.push(chk.reason);
            if (/profanity/i.test(chk.reason))                   found.add('inappropriate');
            else if (/junk|gibberish|repeats/i.test(chk.reason)) found.add('gibberish');
            else if (/short|empty/i.test(chk.reason))            found.add('incomplete');
        }
    }

    {
        const chk = _guardCheckWording(c.description, 15, 'Description');
        if (chk.bad) {
            reasons.push(chk.reason);
            if (/profanity/i.test(chk.reason))                            found.add('inappropriate');
            else if (/junk|gibberish|repeats|vowels/i.test(chk.reason))   found.add('gibberish');
            else if (/short|empty/i.test(chk.reason))                     found.add('incomplete');
        }
    }

    if ((c.description || '').trim().length < 25) {
        reasons.push('Very short description');
        found.add('incomplete');
    }

    if (c.status !== 'pending_review') {
        // skip duplicate check
    } else {
        const norm   = _guardNorm;
        const myTitle = norm(c.title);
        const myDesc  = norm(c.description).slice(0, 200);

        const myCompList = (
            (c.complainant_names || '')
                .split('|').map(s => norm(s)).filter(Boolean).sort()
        ).join('|');
        const myCompFallback = norm((c.first_name || '') + ' ' + (c.last_name || ''));

        const myRespList = (
            (c.respondent_names || '')
                .split('|').map(s => norm(s)).filter(Boolean).sort()
        ).join('|');

        const myIncDate = (c.first_incident_date || '').trim();
        const myIncLoc  = norm(c.first_incident_location || '');

        const myFingerprint = {
            title: myTitle,
            desc : myDesc,
            comp : myCompList || myCompFallback,
            resp : myRespList,
            date : myIncDate,
            loc  : myIncLoc,
        };

        let exactMatch   = null;
        let partialMatch = null;

        for (const other of allList) {
            if (!other || other.id === c.id) continue;
            if (other.status !== 'pending_review') continue;
            if (other.created_by !== c.created_by) continue;

            const oTitle = norm(other.title);
            const oDesc  = norm(other.description).slice(0, 200);

            const oCompList = (
                (other.complainant_names || '')
                    .split('|').map(s => norm(s)).filter(Boolean).sort()
            ).join('|');
            const oCompFallback = norm((other.first_name || '') + ' ' + (other.last_name || ''));

            const oRespList = (
                (other.respondent_names || '')
                    .split('|').map(s => norm(s)).filter(Boolean).sort()
            ).join('|');

            const oIncDate = (other.first_incident_date || '').trim();
            const oIncLoc  = norm(other.first_incident_location || '');

            const sameTitle = myFingerprint.title && oTitle && myFingerprint.title === oTitle;
            const sameDesc  = myFingerprint.desc  && oDesc  && myFingerprint.desc  === oDesc;
            const sameComp  = (myFingerprint.comp && (oCompList || oCompFallback))
                            && myFingerprint.comp === (oCompList || oCompFallback);
            const sameResp  = myFingerprint.resp && oRespList && myFingerprint.resp === oRespList;
            const sameDate  = myFingerprint.date && oIncDate && myFingerprint.date === oIncDate;
            const sameLoc   = myFingerprint.loc  && oIncLoc  && myFingerprint.loc  === oIncLoc;

            const isExact = sameTitle && sameDesc && sameComp && sameResp && sameDate && sameLoc;
            if (isExact) {
                exactMatch = other;
                break;
            }

            const coreMatch = sameTitle && sameDesc && sameComp;
            const anyDiff   = (!sameResp) || (!sameDate) || (!sameLoc);
            if (coreMatch && anyDiff) {
                if (!partialMatch) partialMatch = other;
            }
        }

        if (exactMatch) {
            reasons.push(`Exact duplicate of ${exactMatch.reference_number} (same complainant, respondent, incident, and description).`);
            found.add('duplicate');
        } else if (partialMatch) {
            reasons.push(`Possible duplicate of ${partialMatch.reference_number} — same complainant & complaint details, but different respondent, incident date, or location.`);
            found.add('possible_duplicate');
        }
    }

    if (c.created_at) {
        const ts = new Date(c.created_at.replace(' ', 'T')).getTime();
        for (const other of allList) {
            if (!other || other.id === c.id) continue;
            if (other.created_by !== c.created_by) continue;
            if (!other.created_at) continue;
            const ots = new Date(other.created_at.replace(' ', 'T')).getTime();
            const gap = Math.abs(ots - ts);
            if (gap > 0 && gap < 60000) {
                reasons.push('Filed within 60s of another complaint');
                found.add('spam');
                break;
            }
        }
    }

    const categories = Array.from(found);

    if (categories.length >= 2 && !categories.includes('suspicious')) {
        categories.push('suspicious');
    }

    let level = 'clean';
    if (categories.length === 1) {
        if (categories[0] === 'incomplete' || categories[0] === 'placeholder' || categories[0] === 'possible_duplicate') {
            level = 'low';
        } else {
            level = 'medium';
        }
    } else if (categories.length === 2) {
        level = 'medium';
    } else if (categories.length >= 3) {
        level = 'high';
    }
    if (categories.includes('suspicious') || categories.includes('duplicate') || categories.includes('inappropriate')) {
        level = 'high';
    }

    const tags = categories.map(k => ({ key: k, ...GUARD_CATEGORIES[k] }));

    return {
        level,
        categories,
        reasons: Array.from(new Set(reasons)),
        tags,
    };
}

function guardAnnotateList(list) {
    return list.map(c => {
        const g = guardScoreComplaint(c, list);
        return { ...c, __guard: g };
    });
}

function buildDuplicateGroups(list) {
    const fp = (c) => {
        const norm = _guardNorm;
        const compList = (c.complainant_names || '')
            .split('|').map(s => norm(s)).filter(Boolean).sort().join('|');
        const respList = (c.respondent_names || '')
            .split('|').map(s => norm(s)).filter(Boolean).sort().join('|');
        return {
            title : norm(c.title),
            desc  : norm(c.description).slice(0, 200),
            comp  : compList || norm((c.first_name || '') + ' ' + (c.last_name || '')),
            resp  : respList,
            date  : (c.first_incident_date || '').trim(),
            loc   : norm(c.first_incident_location || ''),
        };
    };

    const byUser = new Map();
    list.forEach(c => {
        if (!c || c.id == null) return;
        if (c.status !== 'pending_review') return;
        const uid = c.created_by ?? '__anon__';
        if (!byUser.has(uid)) byUser.set(uid, []);
        byUser.get(uid).push(c);
    });

    const groups     = [];
    const groupedIds = new Set();

    function cluster(bucket, predicate) {
        const edges = [];
        for (let i = 0; i < bucket.length; i++) {
            for (let j = i + 1; j < bucket.length; j++) {
                const A = fp(bucket[i]);
                const B = fp(bucket[j]);
                if (predicate(A, B)) edges.push([bucket[i].id, bucket[j].id]);
            }
        }
        if (!edges.length) return [];

        const parent = new Map();
        const find = x => {
            while (parent.get(x) !== x) { parent.set(x, parent.get(parent.get(x))); x = parent.get(x); }
            return x;
        };
        const union = (x, y) => {
            const rx = find(x), ry = find(y);
            if (rx !== ry) parent.set(rx, ry);
        };

        bucket.forEach(c => parent.set(c.id, c.id));
        edges.forEach(([a, b]) => union(a, b));

        const clusters = new Map();
        bucket.forEach(c => {
            const root = find(c.id);
            if (!clusters.has(root)) clusters.set(root, []);
            clusters.get(root).push(c);
        });

        return Array.from(clusters.values()).filter(m => m.length >= 2);
    }

    const isExact = (A, B) =>
        A.title && B.title && A.title === B.title &&
        A.desc  && B.desc  && A.desc  === B.desc  &&
        A.comp  && B.comp  && A.comp  === B.comp  &&
        A.resp  && B.resp  && A.resp  === B.resp  &&
        A.date  && B.date  && A.date  === B.date  &&
        A.loc   && B.loc   && A.loc   === B.loc;

    const isPossible = (A, B) => {
        const coreMatch =
            A.title && B.title && A.title === B.title &&
            A.desc  && B.desc  && A.desc  === B.desc  &&
            A.comp  && B.comp  && A.comp  === B.comp;
        if (!coreMatch) return false;

        const respDiff = !(A.resp && B.resp && A.resp === B.resp);
        const dateDiff = !(A.date && B.date && A.date === B.date);
        const locDiff  = !(A.loc  && B.loc  && A.loc  === B.loc);

        return respDiff || dateDiff || locDiff;
    };

    byUser.forEach(bucket => {
        if (bucket.length < 2) return;

        cluster(bucket, isExact).forEach(members => {
            members.sort((x, y) => new Date(y.created_at) - new Date(x.created_at));
            const groupId = 'exact-' + members.map(m => m.id).sort((a,b)=>a-b).join('-');
            groups.push({ id: groupId, kind: 'exact', members });
            members.forEach(m => groupedIds.add(m.id));
        });

        const remaining = bucket.filter(c => !groupedIds.has(c.id));
        cluster(remaining, isPossible).forEach(members => {
            members.sort((x, y) => new Date(y.created_at) - new Date(x.created_at));
            const groupId = 'partial-' + members.map(m => m.id).sort((a,b)=>a-b).join('-');
            groups.push({ id: groupId, kind: 'partial', members });
            members.forEach(m => groupedIds.add(m.id));
        });
    });

    return { groups, groupedIds };
}

function guardLabel(g) {
    if (!g || g.level === 'clean' || !g.tags || !g.tags.length) {
        return {
            text: '', short: '', color: '#666', bg: '#f0f0f0',
            icon: 'fa-info-circle', tooltip: '', headline: '', advice: '',
        };
    }

    const primary = g.tags[0];
    const n = g.tags.length;

    const badgeText = n > 1 ? `${primary.label} (+${n - 1})` : primary.label;
    const tooltip   = g.tags.map(t => `• ${t.label}: ${t.advice}`).join('\n');
    const headline  = n > 1
        ? `⚠️ ${n} CONTENT ISSUES DETECTED — REVIEW BEFORE FORWARDING`
        : `⚠️ ${primary.label.toUpperCase()} — REVIEW BEFORE FORWARDING`;
    const advice = n > 1
        ? 'This complaint triggered multiple content checks. Review each flag below before forwarding.'
        : primary.advice;

    return {
        text: badgeText,
        short: primary.short,
        color: primary.color,
        bg: `${primary.color}15`,
        icon: primary.icon,
        tooltip,
        headline,
        advice,
    };
}

function guardTagFor(categoryKey) {
    const cat = GUARD_CATEGORIES[categoryKey];
    if (!cat) {
        return { key: 'unknown', label: 'Content Issue', short: 'Issue',
                 icon: 'fa-flag', color: '#666',
                 advice: 'Content flagged by the system.' };
    }
    return { key: categoryKey, ...cat };
}

// ============================================================
// TOOLBAR FILTERS (Search + Date Range)
// ============================================================
function applyToolbarFilters(list) {
    const f = caseFilters;

    let datePred = () => true;
    if (f.days === -1 && f.dateFrom && f.dateTo) {
        const fromTs = new Date(f.dateFrom + 'T00:00:00').getTime();
        const toTs   = new Date(f.dateTo   + 'T23:59:59').getTime();
        datePred = (c) => {
            if (!c.created_at) return true;
            const ts = new Date(String(c.created_at).replace(' ', 'T')).getTime();
            return ts >= fromTs && ts <= toTs;
        };
    } else if (f.days > 0) {
        const cutoff = Date.now() - (f.days * 86400000);
        datePred = (c) => {
            if (!c.created_at) return true;
            const ts = new Date(String(c.created_at).replace(' ', 'T')).getTime();
            return ts >= cutoff;
        };
    }

    const q = (f.search || '').trim().toLowerCase();
    const searchPred = (c) => {
        if (!q) return true;
        const ref   = (c.reference_number || '').toLowerCase();
        const title = (c.title || '').toLowerCase();
        const comp  = ((c.first_name || '') + ' ' + (c.last_name || '')).toLowerCase();
        const compNames = (c.complainant_names || '').toLowerCase();
        return ref.includes(q) || title.includes(q) || comp.includes(q) || compNames.includes(q);
    };

    return list.filter(c => datePred(c) && searchPred(c));
}

function _getCurrentFilteredList() {
    const stageKey = currentComplaintFilter || 'all';
    let filtered;
    if (stageKey === '__suspicious') {
        filtered = complaintData.filter(c => c.__guard && c.__guard.level !== 'clean');
    } else {
        filtered = filterByStage(complaintData, stageKey);
    }
    return applyToolbarFilters(filtered);
}

let __secSearchDebounce = null;
function onCaseSearch(val) {
    clearTimeout(__secSearchDebounce);
    __secSearchDebounce = setTimeout(() => {
        caseFilters.search = val.trim();
        casePage = 1;
        renderComplaintsGrid(_getCurrentFilteredList());
    }, 300);
}

function onDateRangeChange(val) {
    const v = Number(val);
    caseFilters.days = v;
    const customWrap = document.getElementById('caseCustomRange');
    if (v === -1) {
        if (customWrap) customWrap.style.display = 'flex';
        if (!caseFilters.dateFrom) {
            const d = new Date();
            d.setDate(d.getDate() - 30);
            caseFilters.dateFrom = d.toISOString().split('T')[0];
            document.getElementById('caseDateFrom').value = caseFilters.dateFrom;
        }
        if (!caseFilters.dateTo) {
            caseFilters.dateTo = new Date().toISOString().split('T')[0];
            document.getElementById('caseDateTo').value = caseFilters.dateTo;
        }
    } else {
        if (customWrap) customWrap.style.display = 'none';
        caseFilters.dateFrom = '';
        caseFilters.dateTo = '';
    }
    casePage = 1;
    renderComplaintsGrid(_getCurrentFilteredList());
}

function onCustomRangeChange() {
    caseFilters.dateFrom = document.getElementById('caseDateFrom').value;
    caseFilters.dateTo   = document.getElementById('caseDateTo').value;
    if (!caseFilters.dateFrom || !caseFilters.dateTo) return;
    casePage = 1;
    renderComplaintsGrid(_getCurrentFilteredList());
}

function resetCaseToolbar() {
    caseFilters = { search: '', days: 30, dateFrom: '', dateTo: '' };
    casePage = 1;
    const s = document.getElementById('caseSearch'); if (s) s.value = '';
    const sel = document.getElementById('caseDateRange'); if (sel) sel.value = '30';
    const cw = document.getElementById('caseCustomRange'); if (cw) cw.style.display = 'none';
    renderComplaintsGrid(_getCurrentFilteredList());
}

// ============================================================
// PAGINATION HELPERS
// ============================================================
function renderCasePagination(totalPages, totalItems) {
    if (totalPages <= 1) return '';

    const buttons = [];
    buttons.push(`<button class="case-page-btn" ${casePage === 1 ? 'disabled' : ''} onclick="gotoCasePage(${casePage - 1})"><i class="fas fa-chevron-left"></i></button>`);

    const maxVisible = 5;
    let startP = Math.max(1, casePage - Math.floor(maxVisible / 2));
    let endP   = Math.min(totalPages, startP + maxVisible - 1);
    if (endP - startP + 1 < maxVisible) startP = Math.max(1, endP - maxVisible + 1);

    if (startP > 1) {
        buttons.push(`<button class="case-page-btn" onclick="gotoCasePage(1)">1</button>`);
        if (startP > 2) buttons.push(`<span style="color:#999;padding:0 6px;">…</span>`);
    }

    for (let p = startP; p <= endP; p++) {
        buttons.push(`<button class="case-page-btn ${p === casePage ? 'active' : ''}" onclick="gotoCasePage(${p})">${p}</button>`);
    }

    if (endP < totalPages) {
        if (endP < totalPages - 1) buttons.push(`<span style="color:#999;padding:0 6px;">…</span>`);
        buttons.push(`<button class="case-page-btn" onclick="gotoCasePage(${totalPages})">${totalPages}</button>`);
    }

    buttons.push(`<button class="case-page-btn" ${casePage === totalPages ? 'disabled' : ''} onclick="gotoCasePage(${casePage + 1})"><i class="fas fa-chevron-right"></i></button>`);

    const startItem = (casePage - 1) * CASES_PER_PAGE + 1;
    const endItem = Math.min(casePage * CASES_PER_PAGE, totalItems);

    return `
        <div class="case-pagination">
            ${buttons.join('')}
            <span class="case-page-info">Showing ${startItem}–${endItem} of ${totalItems}</span>
        </div>`;
}

function gotoCasePage(p) {
    const filtered = _getCurrentFilteredList();
    const totalPages = Math.max(1, Math.ceil(filtered.length / CASES_PER_PAGE));
    if (p < 1 || p > totalPages) return;
    casePage = p;
    renderComplaintsGrid(filtered);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ============ COMPLAINTS VIEW ============
function renderComplaintsView() {
    document.getElementById('dashboardBody').innerHTML = `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-gavel"></i> Complaint Management</div>
            <div class="section-sub">Review complaints, validate escalations, and process resolutions.</div>
            <div class="filter-bar" id="complaintFilterBar">
                <button class="filter-chip" data-stage="all" onclick="loadComplaints('all')">All</button>
                <button class="filter-chip" data-stage="intake" onclick="loadComplaints('intake')">
                    <i class="fas fa-file-alt"></i> Under Review <span class="pending-count" id="stageCountIntake"></span>
                </button>
                <button class="filter-chip" data-stage="mediation" onclick="loadComplaints('mediation')">
                    <i class="fas fa-handshake"></i> Mediation <span class="pending-count" id="stageCountMediation"></span>
                </button>
                <button class="filter-chip" data-stage="pangkat_constitution" onclick="loadComplaints('pangkat_constitution')">
                    <i class="fas fa-users-cog"></i> Pangkat Forming <span class="pending-count" id="stageCountPangkatConstitution"></span>
                </button>
                <button class="filter-chip" data-stage="pangkat_conciliation" onclick="loadComplaints('pangkat_conciliation')">
                    <i class="fas fa-gavel"></i> Pangkat Hearing <span class="pending-count" id="stageCountPangkatConciliation"></span>
                </button>
                <button class="filter-chip" data-stage="settled_pending" onclick="loadComplaints('settled_pending')">
                    <i class="fas fa-hourglass-half"></i> Settlement Pending <span class="pending-count" id="stageCountSettledPending"></span>
                </button>
                <button class="filter-chip" data-stage="resolved" onclick="loadComplaints('resolved')">
                    <i class="fas fa-check-double"></i> Resolved <span class="pending-count" id="stageCountResolved"></span>
                </button>
                <button class="filter-chip" data-stage="unresolved" onclick="loadComplaints('unresolved')">
                    <i class="fas fa-times-circle"></i> Unresolved <span class="pending-count" id="stageCountUnresolved"></span>
                </button>
                <button class="filter-chip" data-stage="referred" onclick="loadComplaints('referred')">
                    <i class="fas fa-paper-plane"></i> Referred <span class="pending-count" id="stageCountReferred"></span>
                </button>
                <button class="filter-chip" data-stage="dismissed" onclick="loadComplaints('dismissed')">
                    <i class="fas fa-ban"></i> Dismissed <span class="pending-count" id="stageCountDismissed"></span>
                </button>
            </div>
            <div class="case-toolbar">
                <div class="case-search">
                    <i class="fas fa-search"></i>
                    <input type="text" id="caseSearch" placeholder="Search reference, title, complainant…"
                           oninput="onCaseSearch(this.value)">
                </div>
                <select id="caseDateRange" class="case-date-select" onchange="onDateRangeChange(this.value)">
                    <option value="7">Last 7 days</option>
                    <option value="30" selected>Last 30 days (default)</option>
                    <option value="90">Last 90 days</option>
                    <option value="365">Last year</option>
                    <option value="0">All time</option>
                    <option value="-1">Custom range…</option>
                </select>
                <div id="caseCustomRange" style="display:none;gap:6px;align-items:center;">
                    <input type="date" id="caseDateFrom" class="case-date-input" onchange="onCustomRangeChange()">
                    <span style="color:#666;">→</span>
                    <input type="date" id="caseDateTo" class="case-date-input" onchange="onCustomRangeChange()">
                </div>
                <button type="button" class="case-reset-btn" onclick="resetCaseToolbar()">
                    <i class="fas fa-times"></i> Reset
                </button>
                <div class="case-result-count" id="caseResultCount"></div>
            </div>
            <div id="complaintsContainer">
                <div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div>
            </div>
        </div>`;
    loadComplaints('all');
}

async function loadComplaints(stageKey = 'all') {
    currentComplaintFilter = stageKey;
    casePage = 1;
    const c = document.getElementById('complaintsContainer');
    if (!c) return;
    c.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div>';
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_all_complaints&status=all`);
        const d = await r.json();
        if (!d.success) { c.innerHTML = '<div class="empty-state"><p>Failed to load.</p></div>'; return; }

        const normalized = (d.complaints || []).map(c => ({ ...c, id: Number(c.id) }));
        complaintData = guardAnnotateList(normalized);

        document.querySelectorAll('#complaintFilterBar .filter-chip').forEach(b => {
            b.classList.toggle('active', b.getAttribute('data-stage') === stageKey);
        });

        const stageCounts = computeStageCounts(complaintData);
        document.getElementById('stageCountIntake').textContent              = stageCounts.intake              ? stageCounts.intake : '';
        document.getElementById('stageCountMediation').textContent           = stageCounts.mediation           ? stageCounts.mediation : '';
        document.getElementById('stageCountPangkatConstitution').textContent = stageCounts.pangkat_constitution ? stageCounts.pangkat_constitution : '';
        document.getElementById('stageCountPangkatConciliation').textContent= stageCounts.pangkat_conciliation ? stageCounts.pangkat_conciliation : '';
        document.getElementById('stageCountSettledPending').textContent      = stageCounts.settled_pending     ? stageCounts.settled_pending : '';
        document.getElementById('stageCountResolved').textContent            = stageCounts.resolved            ? stageCounts.resolved : '';
        document.getElementById('stageCountUnresolved').textContent          = stageCounts.unresolved          ? stageCounts.unresolved : '';
        document.getElementById('stageCountReferred').textContent            = stageCounts.referred            ? stageCounts.referred : '';
        document.getElementById('stageCountDismissed').textContent           = stageCounts.dismissed           ? stageCounts.dismissed : '';

        const suspCount = complaintData.filter(c => c.__guard && c.__guard.level !== 'clean').length;
        const suspEl = document.getElementById('stageCountSuspicious');
        if (suspEl) suspEl.textContent = suspCount > 0 ? suspCount : '';

        let filtered;
        if (stageKey === '__suspicious') {
            filtered = complaintData.filter(c => c.__guard && c.__guard.level !== 'clean');
        } else {
            filtered = filterByStage(complaintData, stageKey);
        }

        filtered = applyToolbarFilters(filtered);
        casePage = 1;
        renderComplaintsGrid(filtered);

    } catch (e) {
        console.error(e);
        c.innerHTML = '<div class="empty-state"><p>Error loading.</p></div>';
    }
}

function renderComplaintsGrid(list) {
    const c = document.getElementById('complaintsContainer');
    if (!c) return;

    // ── Pagination: slice the list BEFORE grouping ──
    const totalItems = list.length;
    const totalPages = Math.max(1, Math.ceil(totalItems / CASES_PER_PAGE));
    if (casePage > totalPages) casePage = totalPages;
    const startIdx = (casePage - 1) * CASES_PER_PAGE;
    const pageItems = list.slice(startIdx, startIdx + CASES_PER_PAGE);

    // Update result count
    const countEl = document.getElementById('caseResultCount');
    if (countEl) {
        countEl.textContent = totalItems === 0
            ? '0 results'
            : `${totalItems} result${totalItems === 1 ? '' : 's'}`;
    }

    if (!list.length) {
        const stageInfo = COMPLAINT_STAGES[currentComplaintFilter];
        const label = currentComplaintFilter === 'all'
            ? 'No complaints found.'
            : (currentComplaintFilter === '__suspicious'
                ? 'No suspicious complaints. ✅'
                : `No complaints in the "${stageInfo ? stageInfo.label : currentComplaintFilter}" stage.`);
        c.innerHTML = `
            <div class="empty-state">
                <i class="fas ${stageInfo ? stageInfo.icon : 'fa-inbox'} fa-3x"></i>
                <p>${label}</p>
                ${currentComplaintFilter !== 'all'
                    ? `<button type="button" class="btn-verify" onclick="loadComplaints('all')" style="margin-top:12px;"><i class="fas fa-list"></i> Show All</button>`
                    : ''}
            </div>`;
        return;
    }

    const statusMeta = {
        pending_review:                   { header: 'pending_review',            icon: 'fa-clock',                label: 'Pending Your Review' },
        pending_captain_action:           { header: 'forwarded_captain',         icon: 'fa-paper-plane',          label: 'Awaiting Captain Action' },
        summoned:                         { header: 'summoned',                  icon: 'fa-envelope-open-text',   label: 'Summons Issued' },
        for_mediation:                    { header: 'mediation',                 icon: 'fa-gavel',                label: 'For Mediation' },
        mediation_scheduled:              { header: 'lupon',                     icon: 'fa-calendar-check',       label: 'Mediation Hearing Scheduled' },
        in_mediation:                     { header: 'lupon',                     icon: 'fa-handshake',            label: 'In Mediation' },
        failed_mediation:                 { header: 'lupon',                     icon: 'fa-hourglass-half',       label: 'Awaiting Pangkat Constitution' },
        pangkat_constitution_scheduled:   { header: 'pangkat_ready',             icon: 'fa-calendar-check',       label: 'Constitution Meeting Set' },
        pangkat_constituted:              { header: 'pangkat_ready',             icon: 'fa-users-cog',            label: 'Awaiting Position Assignment' },
        pangkat_scheduled:                { header: 'lupon',                     icon: 'fa-gavel',                label: 'Conciliation Hearing Scheduled' },
        settled_pending:                  { header: 'settled',                   icon: 'fa-hourglass-half',       label: 'Awaiting Repudiation Window' },
        settled:                          { header: 'settled',                   icon: 'fa-check-circle',         label: 'Settled — Executory' },
        certificate_issued:               { header: 'settled',                   icon: 'fa-certificate',          label: 'Certificate Issued' },
        released:                         { header: 'settled',                   icon: 'fa-check-double',         label: 'Released to Complainant' },
        unresolved:                       { header: 'failed_conciliation_final', icon: 'fa-file-certificate',     label: 'Unresolved — For Certification' },
        failed_conciliation_final:        { header: 'failed_conciliation_final', icon: 'fa-file-certificate',     label: 'Unresolved — For Certification' },
        escalated:                        { header: 'escalated',                 icon: 'fa-exclamation-triangle', label: 'ESCALATED — Non-Jurisdictional' },
        pending_captain_review:           { header: 'pending_captain',           icon: 'fa-signature',            label: 'Awaiting Captain Signature' },
        referred_dispatched:              { header: 'for_release',               icon: 'fa-inbox',                label: 'For Release to Complainant' },
        referred:                         { header: 'escalated',                 icon: 'fa-paper-plane',          label: 'Referred to Agency' },
        dismissed:                        { header: 'dismissed',                 icon: 'fa-ban',                  label: 'Dismissed' },
    };

    const prio = p => {
        const cl = { urgent:'#dc3545', high:'#fd7e14', medium:'#ffc107', low:'#28a745' };
        return `<span style="background:${cl[p] || '#6c757d'};color:#fff;padding:2px 8px;border-radius:10px;font-size:0.65rem;">${(p || 'medium').toUpperCase()}</span>`;
    };

    const now = Date.now();
    const pendingItems = [];
    const upcoming = [];
    const others = [];

    const isAllFilter = (currentComplaintFilter === 'all');

    pageItems.forEach(x => {
        const isPending = (x.status === 'pending_review' || x.status === 'pending_captain_action');

        if (isAllFilter && isPending) {
            pendingItems.push(x);
            return;
        }

        if (!x.hearing_date) { others.push(x); return; }

        const dtStr = x.hearing_date + (x.hearing_time ? ' ' + x.hearing_time : ' 00:00:00');
        const ts = new Date(dtStr.replace(' ', 'T')).getTime();
        if (isNaN(ts) || ts <= now) others.push(x);
        else upcoming.push({ ...x, __hearingTs: ts });
    });

    pendingItems.sort((a, b) => {
        const order = { pending_review: 0, pending_captain_action: 1 };
        const oa = order[a.status] ?? 99;
        const ob = order[b.status] ?? 99;
        if (oa !== ob) return oa - ob;
        return new Date(b.created_at) - new Date(a.created_at);
    });

    upcoming.sort((a, b) => a.__hearingTs - b.__hearingTs);

    const renderCard = (x) => {
        const meta = statusMeta[x.status] || { header: 'pending_review', icon: 'fa-file-alt', label: x.status };
        const needsPrint = Number(x.print_queue_count || 0) > 0;

        return `
            <div class="complaint-card ${needsPrint ? 'print-queue' : ''}" onclick="viewComplaintDetail(${x.id})" style="${x.__guard && x.__guard.level !== 'clean' && x.__guard.tags && x.__guard.tags.length ? 'border:2px solid ' + x.__guard.tags[0].color + ';' : ''}">
                ${needsPrint ? `<div class="print-queue-badge"><i class="fas fa-print"></i> ${x.print_queue_count} to Print</div>` : ''}
                <div class="complaint-card-header document-card-header ${meta.header}">
                    <div class="complaint-card-title">
                        <i class="fas ${x.status === 'escalated' ? 'fa-exclamation-triangle' : (x.status === 'pangkat_constituted' ? 'fa-users-cog' : (x.status === 'pending_captain_review' ? 'fa-hourglass-half' : (x.status === 'referred_dispatched' ? 'fa-inbox' : (x.status === 'pending_captain_action' ? 'fa-paper-plane' : 'fa-file-alt'))))}"></i>
                        <span>${escapeHtml(x.title)}</span>
                    </div>
                    <div class="complaint-card-status"><i class="fas ${meta.icon}"></i> ${escapeHtml(meta.label)}</div>
                </div>
                <div class="complaint-card-body">
                    <div class="document-info-row"><div class="document-info-label">Ref:</div><div class="document-info-value"><strong>${escapeHtml(x.reference_number)}</strong></div></div>
                    <div class="document-info-row"><div class="document-info-label">Complainant:</div><div class="document-info-value">${escapeHtml(x.first_name)} ${escapeHtml(x.last_name)}</div></div>
                    <div class="document-info-row"><div class="document-info-label">Subject:</div><div class="document-info-value">${escapeHtml(x.complaint_subject || '-')}</div></div>
                    ${x.status === 'escalated' ? `
                        <div class="document-info-row" style="background:#ffebee;padding:8px;border-radius:6px;margin-top:6px;">
                            <div class="document-info-label" style="color:#c62828;font-weight:700;">⚠️ ESCALATED:</div>
                            <div class="document-info-value" style="color:#c62828;font-weight:600;">Refer to ${escapeHtml(x.escalation_refer_to || 'PNP / DSWD / DOLE')}.</div>
                        </div>` : ''}
                    ${x.status === 'pending_captain_action' ? `
                        <div class="document-info-row" style="background:#e3f2fd;padding:8px;border-radius:6px;margin-top:6px;">
                            <div class="document-info-label" style="color:#1565c0;font-weight:700;">📤 FORWARDED:</div>
                            <div class="document-info-value" style="color:#1565c0;font-weight:600;">Awaiting Captain to set mediation schedule.</div>
                        </div>` : ''}
                    ${x.status === 'pending_captain_review' ? `
                        <div class="document-info-row" style="background:#fff8e1;padding:8px;border-radius:6px;margin-top:6px;">
                            <div class="document-info-label" style="color:#f57c00;font-weight:700;">⏳ WAITING:</div>
                            <div class="document-info-value" style="color:#f57c00;font-weight:600;">Awaiting Captain signature.</div>
                        </div>` : ''}
                    ${x.status === 'referred_dispatched' ? `
                        <div class="document-info-row" style="background:#e8f5e9;padding:8px;border-radius:6px;margin-top:6px;">
                            <div class="document-info-label" style="color:#2e7d32;font-weight:700;">📬 FOR RELEASE:</div>
                            <div class="document-info-value" style="color:#2e7d32;font-weight:600;">Ready to release to complainant.</div>
                        </div>` : ''}
                    ${x.status === 'pangkat_constituted' ? `
                        <div class="document-info-row" style="background:#e3f2fd;padding:8px;border-radius:6px;margin-top:6px;">
                            <div class="document-info-label" style="color:#1565c0;font-weight:700;">⚖️ PANGKAT:</div>
                            <div class="document-info-value" style="color:#1565c0;font-weight:600;">Chairperson will schedule the conciliation.</div>
                        </div>` : ''}
                    ${x.status === 'pangkat_scheduled' ? `
                        <div class="document-info-row" style="background:#e3f2fd;padding:8px;border-radius:6px;margin-top:6px;">
                            <div class="document-info-label" style="color:#1565c0;font-weight:700;">📅 SCHEDULED:</div>
                            <div class="document-info-value" style="color:#1565c0;font-weight:600;">${x.hearing_date ? new Date(x.hearing_date).toLocaleDateString('en-US',{dateStyle:'medium'}) : 'Conciliation scheduled'} ${x.hearing_time || ''}</div>
                        </div>` : ''}
                    <div class="document-info-row"><div class="document-info-label">Priority:</div><div class="document-info-value">${prio(x.priority)}</div></div>
                    <div class="document-info-row" style="margin-bottom:auto;"><div class="document-info-label">Filed:</div><div class="document-info-value">${new Date(x.created_at).toLocaleDateString()}</div></div>

                    ${x.__guard && x.__guard.level !== 'clean' && x.__guard.tags && x.__guard.tags.length ? (() => {
                        const gl = guardLabel(x.__guard);
                        const isHigh = x.__guard.level === 'high';

                        const chips = x.__guard.tags.slice(0, 3).map(t => `
                            <span style="display:inline-flex;align-items:center;gap:4px;
                                         background:#fff;color:${t.color};
                                         padding:2px 7px;border-radius:10px;
                                         font-size:0.62rem;font-weight:700;
                                         border:1px solid ${t.color}55;white-space:nowrap;">
                                <i class="fas ${t.icon}" style="font-size:0.55rem;"></i>
                                ${escapeHtml(t.short)}
                            </span>`).join('');
                        const extra = x.__guard.tags.length > 3
                            ? `<span style="font-size:0.6rem;color:#666;">+${x.__guard.tags.length - 3}</span>`
                            : '';

                        return `
                        <div title="${escapeHtml(gl.tooltip)}"
                             onclick="event.stopPropagation();"
                             style="margin-top:8px;
                                    background:${gl.bg};
                                    border-left:4px solid ${gl.color};
                                    color:${gl.color};
                                    padding:6px 10px;border-radius:6px;
                                    cursor:help;">
                            <div style="display:flex;align-items:center;gap:6px;
                                        font-size:0.68rem;font-weight:800;
                                        letter-spacing:0.3px;margin-bottom:4px;">
                                <i class="fas ${gl.icon}"></i>
                                <span style="flex:1;">${escapeHtml(gl.text)}</span>
                                ${isHigh ? '<i class="fas fa-circle" style="font-size:0.35rem;animation:pulseBadge 1.6s infinite;"></i>' : ''}
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;">
                                ${chips}${extra}
                            </div>
                        </div>`;
                    })() : ''}
                </div>
                <div class="complaint-card-actions">
                    <button class="doc-action-btn view" onclick="event.stopPropagation(); viewComplaintDetail(${x.id})">
                        <i class="fas fa-eye"></i> View Details
                    </button>
                    ${needsPrint ? `
                        <button class="doc-action-btn print-notice" onclick="event.stopPropagation(); viewComplaintDetail(${x.id})">
                            <i class="fas fa-print"></i> Print Notices
                        </button>` : ''}
                    ${x.status === 'pending_review' ? `
                        <button class="doc-action-btn forward" onclick="event.stopPropagation(); forwardToCaptain(${x.id})">
                            <i class="fas fa-paper-plane"></i> Forward
                        </button>` : ''}
                    ${x.status === 'escalated' ? `
                        <button class="doc-action-btn approve" onclick="event.stopPropagation(); openEscalationReferralModal(${x.id})">
                            <i class="fas fa-file-export"></i> Referral
                        </button>` : ''}
                    ${x.status === 'referred_dispatched' ? `
                        <button class="doc-action-btn release" onclick="event.stopPropagation(); releaseReferral(${x.id})">
                            <i class="fas fa-hand-holding"></i> Release
                        </button>` : ''}
                </div>
            </div>`;
    };

    const sectionHeader = (icon, label, count, color = '#2E7D32') => `
        <div style="display:flex;align-items:center;gap:10px;margin:0 0 14px;padding:10px 14px;
                    background:${color}0D;border-left:4px solid ${color};border-radius:10px;">
            <i class="fas ${icon}" style="color:${color};font-size:1rem;"></i>
            <div style="font-weight:800;color:${color};font-size:0.9rem;letter-spacing:0.3px;text-transform:uppercase;">
                ${label}
            </div>
            <span style="background:${color};color:#fff;padding:2px 10px;border-radius:12px;font-size:0.7rem;font-weight:800;">${count}</span>
        </div>`;

    const divider = (label = 'Other Complaints') => `
        <div style="display:flex;align-items:center;gap:12px;margin:28px 0 20px;">
            <div style="flex:1;height:1px;background:linear-gradient(to right, transparent, #cfd8dc, transparent);"></div>
            <div style="display:inline-flex;align-items:center;gap:8px;padding:6px 16px;border-radius:20px;
                        background:#f8faf8;border:1px solid #e2efe8;color:#666;font-size:0.72rem;font-weight:800;
                        letter-spacing:0.4px;text-transform:uppercase;">
                <i class="fas fa-ellipsis-h"></i> ${label}
            </div>
            <div style="flex:1;height:1px;background:linear-gradient(to right, transparent, #cfd8dc, transparent);"></div>
        </div>`;

    const renderDuplicateGroup = (group) => {
        const kindMeta = {
            exact: {
                color: '#b71c1c',
                bg:    '#ffebee',
                icon:  'fa-copy',
                label: 'Duplicate Complaints',
                desc : 'Same title AND same description — filed by the same user.',
            },
            partial: {
                color: '#e65100',
                bg:    '#fff3e0',
                icon:  'fa-clone',
                label: 'Possible Duplicate Complaints',
                desc : 'Same title OR same description — verify if these are distinct incidents.',
            },
        }[group.kind];

        return `
        <div class="dup-group" style="
            background: ${kindMeta.bg};
            border: 2px dashed ${kindMeta.color}55;
            border-left: 6px solid ${kindMeta.color};
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 20px;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
                <div style="width:42px;height:42px;border-radius:50%;
                            background:${kindMeta.color};color:#fff;
                            display:flex;align-items:center;justify-content:center;
                            font-size:1.1rem;flex-shrink:0;">
                    <i class="fas ${kindMeta.icon}"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:800;color:${kindMeta.color};
                                font-size:0.95rem;text-transform:uppercase;
                                letter-spacing:0.4px;">
                        ${kindMeta.label} — ${group.members.length}
                    </div>
                    <div style="font-size:0.78rem;color:#555;margin-top:2px;">
                        ${kindMeta.desc}
                    </div>
                </div>
                <span style="background:${kindMeta.color};color:#fff;
                             padding:3px 12px;border-radius:14px;
                             font-size:0.7rem;font-weight:800;white-space:nowrap;">
                    GROUPED
                </span>
            </div>
            <div class="complaints-grid" style="margin-top:0;">
                ${group.members.map(renderCard).join('')}
            </div>
        </div>`;
    };

    const renderListWithGroups = (items) => {
        if (!items.length) return '';
        const { groups } = buildDuplicateGroups(items);
        const groupByMemberId = new Map();
        groups.forEach(g => g.members.forEach(m => groupByMemberId.set(m.id, g)));

        const rendered = new Set();
        let html = '';
        let soloBuffer = [];

        const flushSolo = () => {
            if (soloBuffer.length) {
                html += `<div class="complaints-grid">${soloBuffer.map(renderCard).join('')}</div>`;
                soloBuffer = [];
            }
        };

        items.forEach(item => {
            const g = groupByMemberId.get(item.id);
            if (g) {
                if (!rendered.has(g.id)) {
                    rendered.add(g.id);
                    flushSolo();
                    html += renderDuplicateGroup(g);
                }
            } else {
                soloBuffer.push(item);
            }
        });
        flushSolo();
        return html;
    };

    let html = '';
    let hasSection = false;

    if (isAllFilter) {
        if (pendingItems.length) {
            html += sectionHeader('fa-clipboard-check', 'Pending Action', pendingItems.length, '#f57c00');
            html += renderListWithGroups(pendingItems);
            hasSection = true;
        }
        if (upcoming.length) {
            if (hasSection) html += divider('Upcoming Hearings');
            else html += sectionHeader('fa-calendar-check', 'Upcoming Hearings', upcoming.length, '#6a1b9a');
            html += renderListWithGroups(upcoming);
            hasSection = true;
        }
        if (others.length) {
            if (hasSection) html += divider('Other Complaints');
            else html += sectionHeader('fa-folder-open', 'All Complaints', others.length, '#2E7D32');
            html += renderListWithGroups(others);
        }
    } else {
        if (upcoming.length) {
            html += sectionHeader('fa-calendar-check', 'Upcoming Hearings', upcoming.length, '#6a1b9a');
            html += renderListWithGroups(upcoming);
            hasSection = true;
        }
        if (others.length) {
            if (hasSection) html += divider('Other Complaints');
            else {
                const stageInfo = COMPLAINT_STAGES[currentComplaintFilter];
                const label = `${stageInfo.label} Complaints`;
                const icon  = stageInfo.icon;
                const color = stageInfo.color;
                html += sectionHeader(icon, label, others.length, color);
            }
            html += renderListWithGroups(others);
        }
    }

    // ── Append pagination ──
    html += renderCasePagination(totalPages, totalItems);
    c.innerHTML = html;
}

/* ============================================================
   FORWARD NORMAL COMPLAINT TO CAPTAIN — professional modal
   ============================================================ */
async function forwardToCaptain(id) {
    let complaint = null;
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${id}`);
        const d = await r.json();
        if (d.success) complaint = d.complaint;
    } catch (e) { showErrorModal('Could not load complaint.'); return; }
    if (!complaint) { showErrorModal('Complaint not found.'); return; }

    const cached = complaintData.find(x => Number(x.id) === Number(id));
    let guard = cached ? cached.__guard : null;
    if (!guard) {
        guard = guardScoreComplaint({
            ...complaint,
            complainant_names: (cached && cached.complainant_names) || complaint.complainant_names || '',
        }, complaintData);
    }

    const isFlagged = guard && guard.level !== 'clean' && guard.tags && guard.tags.length;
    const isHigh    = isFlagged && guard.level === 'high';

    const flagBlockHtml = isFlagged ? (() => {
        const chips = guard.tags.map(t => `
            <span style="display:inline-flex;align-items:center;gap:5px;
                         background:#fff;color:${t.color};
                         padding:4px 11px;border-radius:14px;
                         font-size:0.73rem;font-weight:700;
                         border:1px solid ${t.color}55;">
                <i class="fas ${t.icon}" style="font-size:0.62rem;"></i>
                ${escapeHtml(t.label)}
            </span>`).join('');
        return `
        <div style="background:${isHigh ? '#ffebee' : '#fff8e1'};
                    border-left:4px solid ${isHigh ? '#c62828' : '#f57c00'};
                    border-radius:10px;padding:14px 16px;margin-bottom:16px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;
                        font-size:0.82rem;font-weight:800;
                        color:${isHigh ? '#b71c1c' : '#e65100'}">
                <i class="fas fa-shield-alt"></i>
                Content checker flagged ${guard.tags.length} issue${guard.tags.length === 1 ? '' : 's'}
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">${chips}</div>
            <div style="font-size:0.78rem;color:#555;line-height:1.5;">
                ${isHigh
                    ? 'This complaint has <b>high-severity flags</b>. Verify it is genuine and not a duplicate before forwarding.'
                    : 'Please verify this complaint is genuine before forwarding to the Punong Barangay.'}
            </div>
        </div>`;
    })() : '';

    const { value, isConfirmed } = await Swal.fire({
        title: '',
        html: `
            <div style="text-align:left;font-family:'Segoe UI',Roboto,sans-serif;">
                <div style="background:linear-gradient(135deg,#0d47a1,#1565c0 55%,#42a5f5);
                            color:#fff;padding:18px 22px;border-radius:14px 14px 0 0;
                            margin:-1px -1px 0 -1px;display:flex;align-items:center;gap:14px;">
                    <div style="width:48px;height:48px;border-radius:50%;
                                background:rgba(255,255,255,0.2);
                                display:flex;align-items:center;justify-content:center;
                                flex-shrink:0;font-size:1.3rem;">
                        <i class="fas fa-paper-plane"></i>
                    </div>
                    <div>
                        <div style="font-size:1.08rem;font-weight:800;letter-spacing:0.3px;">
                            Forward to Punong Barangay
                        </div>
                        <div style="font-size:0.78rem;opacity:0.9;margin-top:3px;">
                            The Captain will be notified and will set the mediation schedule.
                        </div>
                    </div>
                </div>
                <div style="padding:20px 22px;background:#fff;">
                    ${flagBlockHtml}
                    <div style="background:#f8faf8;border:1px solid #e2efe8;border-radius:10px;
                                padding:14px 16px;margin-bottom:16px;">
                        <div style="font-size:0.72rem;color:#7a8a82;
                                    text-transform:uppercase;letter-spacing:0.4px;
                                    margin-bottom:6px;">
                            Case Reference
                        </div>
                        <div style="font-weight:800;color:#1a2b22;font-size:1rem;">
                            ${escapeHtml(complaint.reference_number || '')}
                        </div>
                        <div style="font-size:0.85rem;color:#333;margin-top:6px;
                                    line-height:1.5;">
                            <div><strong>Title:</strong> ${escapeHtml(complaint.title || '')}</div>
                            <div style="margin-top:4px;">
                                <strong>Complainant:</strong>
                                ${escapeHtml((complaint.first_name || '') + ' ' + (complaint.last_name || ''))}
                            </div>
                        </div>
                    </div>
                    <div style="background:#e3f2fd;border-left:4px solid #1565c0;
                                border-radius:10px;padding:14px 16px;margin-bottom:16px;">
                        <div style="font-size:0.82rem;font-weight:800;color:#0d47a1;
                                    margin-bottom:8px;display:flex;align-items:center;gap:8px;">
                            <i class="fas fa-info-circle"></i> What happens next
                        </div>
                        <ul style="margin:0 0 0 18px;padding:0;
                                   font-size:0.83rem;color:#1a2b22;line-height:1.7;">
                            <li>The <b>Punong Barangay</b> is notified immediately.</li>
                            <li>The Captain sets the <b>mediation schedule</b>.</li>
                            <li><b>KP Form #8</b> (Notice of Hearing) is issued to the complainant.</li>
                            <li><b>KP Form #9</b> (Summons) is issued to the respondent.</li>
                        </ul>
                    </div>
                    <label style="display:block;font-weight:700;font-size:0.85rem;
                                  color:#1a472a;margin-bottom:6px;">
                        Review Notes <span style="color:#888;font-weight:500;">(optional)</span>
                    </label>
                    <textarea id="fwdNotes"
                              rows="3"
                              placeholder="e.g. Complete details, valid complaint, verified with the complainant."
                              style="width:100%;padding:11px 12px;border:1.5px solid #cfd8dc;
                                     border-radius:8px;font-size:0.9rem;font-family:inherit;
                                     resize:vertical;box-sizing:border-box;
                                     line-height:1.5;"></textarea>
                    ${isFlagged ? `
                    <label style="display:flex;align-items:flex-start;gap:10px;
                                  margin-top:16px;cursor:pointer;
                                  background:#fff8e1;border:1px solid #ffe082;
                                  border-radius:10px;padding:12px 14px;">
                        <input type="checkbox" id="fwdConfirmFlagged"
                               style="margin-top:3px;width:16px;height:16px;
                                      cursor:pointer;flex-shrink:0;"
                               onchange="const b=document.querySelector('.swal2-confirm');if(b){b.disabled=!this.checked;b.style.opacity=this.checked?'1':'0.5';b.style.cursor=this.checked?'pointer':'not-allowed';}">
                        <span style="font-size:0.83rem;color:#5d4037;line-height:1.55;">
                            I have verified this complaint is <b>genuine and not a duplicate</b>,
                            and I want to forward it to the Punong Barangay.
                        </span>
                    </label>` : ''}
                </div>
            </div>`,
        width: 640,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-paper-plane"></i> Forward to Captain',
        confirmButtonColor: '#1565c0',
        cancelButtonText: '<i class="fas fa-times"></i> Cancel',
        cancelButtonColor: '#6c757d',
        allowOutsideClick: false,
        customClass: { popup: 'sm-popup' },
        didOpen: () => {
            if (isFlagged) {
                const b = document.querySelector('.swal2-confirm');
                if (b) {
                    b.disabled = true;
                    b.style.opacity = '0.5';
                    b.style.cursor = 'not-allowed';
                }
            }
        },
        preConfirm: () => {
            if (isFlagged) {
                const cb = document.getElementById('fwdConfirmFlagged');
                if (!cb || !cb.checked) {
                    Swal.showValidationMessage('Please confirm you have verified this complaint.');
                    return false;
                }
            }
            const notesEl = document.getElementById('fwdNotes');
            return { notes: notesEl ? notesEl.value.trim() : '' };
        }
    });

    if (!isConfirmed || !value) return;

    const fd = new FormData();
    fd.append('action', 'forward_to_captain');
    fd.append('complaint_id', id);
    fd.append('notes', value.notes);

    Swal.fire({ title: 'Forwarding…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        Swal.close();

        if (d.success) {
            await Swal.fire({
                icon: 'success',
                title: 'Forwarded to Captain',
                html: `
                    <div style="text-align:left;font-size:0.9rem;">
                        <p style="margin:6px 0 12px;">
                            The complaint has been forwarded to
                            <strong>${escapeHtml(d.captain_name || 'the Punong Barangay')}</strong>.
                        </p>
                        <div style="background:#e3f2fd;border-left:4px solid #1565c0;
                                    border-radius:8px;padding:12px 14px;">
                            <div style="font-weight:700;color:#0d47a1;margin-bottom:6px;">
                                <i class="fas fa-info-circle"></i> The Captain will now:
                            </div>
                            <ul style="margin:0 0 0 18px;padding:0;
                                       font-size:0.84rem;color:#1a2b22;line-height:1.7;">
                                <li>Set the mediation schedule</li>
                                <li>Issue <b>KP Form #8</b> (Notice of Hearing)</li>
                                <li>Issue <b>KP Form #9</b> (Summons)</li>
                            </ul>
                        </div>
                    </div>`,
                confirmButtonColor: '#2E7D32',
                confirmButtonText: '<i class="fas fa-check"></i> Got it'
            });

            const idx = complaintData.findIndex(x => Number(x.id) === Number(id));
            if (idx !== -1) {
                complaintData[idx].status = 'pending_captain_action';
                complaintData[idx].__guard = { level: 'clean', categories: [], reasons: [], tags: [] };
            }
            loadComplaints(currentComplaintFilter);
        } else {
            showErrorModal(d.message || 'Failed to forward.');
        }
    } catch (e) {
        Swal.close();
        showErrorModal('Network error.');
    }
}

/* ============================================================
   ESCALATED CASE — Validate & Forward to Captain
   ============================================================ */
async function openEscalationReferralModal(id) {
    let complaint = null, evidenceCount = 0;
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${id}`);
        const d = await r.json();
        if (d.success) { complaint = d.complaint; evidenceCount = (d.evidence || []).length; }
    } catch (e) { showErrorModal('Could not load case.'); return; }
    if (!complaint) { showErrorModal('Complaint not found.'); return; }

    let escalationType = (complaint.escalation_type || '').toLowerCase().trim();
    if (!escalationType) {
        const desc = (complaint.description || '').toLowerCase();
        const patterns = {
            rape:             ['ginahasa','gahasa','rape','pinilit na makipagtalik','sexual assault'],
            vawc:             ['sinasaktan ako ng asawa','binubugbog ako','sinakal ako ng asawa','vawc','ra 9262'],
            child_abuse:      ['pang-aabuso sa bata','child abuse','ra 7610','pinagtatrabaho ang bata'],
            drugs:            ['shabu','droga','marijuana','nagbebenta ng droga','drug den','ra 9165'],
            illegal_gambling: ['jueteng','tupada','sabong','illegal gambling','pd 1602'],
            labor:            ['hindi sumasahod','delayed sahod','labor dispute','dole','nlrc'],
            government:       ['gobyerno','barangay official','kapitan','public officer'],
            high_penalty:     ['murder','homicide','kidnapping','robbery with violence','terrorism'],
        };
        for (const [type, kws] of Object.entries(patterns)) {
            for (const k of kws) { if (desc.includes(k)) { escalationType = type; break; } }
            if (escalationType) break;
        }
    }
    if (!escalationType) escalationType = 'government';

    const typeConfig = {
        rape:             { label: 'Sexual Assault / Rape',                          referTo: 'PNP Women & Children Protection Desk (WCPD) — call 911', actions: { blotter:true, referral:true, pnp:true, dswd:true, bpo:true }, showActions:['blotter','referral','pnp','dswd','bpo','bantay_bata'] },
        vawc:             { label: 'Violence Against Women and Children (RA 9262)',  referTo: "PNP Women's Desk — call 911",                            actions: { blotter:true, referral:true, bpo:true, dswd:true, pnp:true }, showActions:['blotter','referral','bpo','dswd','pnp','bantay_bata'] },
        child_abuse:      { label: 'Child Abuse (RA 7610)',                          referTo: 'PNP-WCPD, DSWD, Bantay Bata 163 — call 911',             actions: { blotter:true, referral:true, pnp:true, dswd:true, bantay_bata:true }, showActions:['blotter','referral','pnp','dswd','bantay_bata','bpo'] },
        drugs:            { label: 'Illegal Drugs (RA 9165)',                        referTo: 'PNP Anti-Illegal Drugs Group / PDEA — call 911',         actions: { blotter:true, referral:true, pnp:true }, showActions:['blotter','referral','pnp','pdea'] },
        illegal_gambling: { label: 'Illegal Gambling (PD 1602)',                     referTo: 'PNP — call 911',                                          actions: { blotter:true, referral:true, pnp:true }, showActions:['blotter','referral','pnp'] },
        labor:            { label: 'Labor Dispute',                                  referTo: 'DOLE / NLRC',                                             actions: { blotter:true, referral:true, dole:true }, showActions:['blotter','referral','dole'] },
        government:       { label: 'Case Involving Government',                      referTo: 'Proper Government Agency / Court',                        actions: { blotter:true, referral:true }, showActions:['blotter','referral'] },
        no_private_party: { label: 'Offense with No Private Party',                  referTo: "PNP / Prosecutor's Office",                               actions: { blotter:true, referral:true, pnp:true }, showActions:['blotter','referral','pnp'] },
        high_penalty:     { label: 'Offense with Penalty Exceeding 1 Year',          referTo: "PNP / Prosecutor's Office",                               actions: { blotter:true, referral:true, pnp:true }, showActions:['blotter','referral','pnp'] }
    };
    const cfg = typeConfig[escalationType] || typeConfig.government;
    const today = new Date().toISOString().split('T')[0];

    const actionItems = [
        { key: 'blotter',     icon: 'fa-book',          label: 'Recorded in Barangay Blotter' },
        { key: 'referral',    icon: 'fa-file-export',   label: 'Referral Letter prepared' },
        { key: 'bpo',         icon: 'fa-shield-alt',    label: 'Barangay Protection Order (BPO) issued' },
        { key: 'dswd',        icon: 'fa-hands-helping', label: 'Coordinated with DSWD / MSWDO' },
        { key: 'pnp',         icon: 'fa-user-shield',   label: 'PNP-WCPD / PNP notified' },
        { key: 'pdea',        icon: 'fa-pills',         label: 'PDEA notified' },
        { key: 'bantay_bata', icon: 'fa-child',         label: 'Bantay Bata 163 notified' },
        { key: 'dole',        icon: 'fa-briefcase',     label: 'DOLE / NLRC notified' }
    ];
    const visibleActions = actionItems.filter(a => cfg.showActions.includes(a.key));
    const actionCheckboxes = visibleActions.map(a => `
        <label style="display:flex;align-items:center;gap:8px;padding:6px 0;font-size:0.9rem;cursor:pointer;">
            <input type="checkbox" id="escAction_${a.key}" ${cfg.actions[a.key] ? 'checked' : ''}>
            <i class="fas ${a.icon}" style="color:#c62828;width:16px;"></i>
            <span>${a.label}</span>
        </label>`).join('');

    const result = await Swal.fire({
        title: '',
        html: `
            <div class="sm-modal">
                <div class="sm-header" style="background: linear-gradient(135deg, #b71c1c, #c62828 55%, #e53935);">
                    <div class="sm-header-left"><i class="fas fa-exclamation-triangle"></i>
                        <div>
                            <div class="sm-title-main">Escalated Case — Prepare Referral</div>
                            <div class="sm-title-sub">NOT under Katarungang Pambarangay</div>
                        </div>
                    </div>
                    <div class="sm-ref">
                        <div class="sm-ref-label">Reference</div>
                        <div class="sm-ref-value">${escapeHtml(complaint.reference_number)}</div>
                    </div>
                </div>
                <div class="sm-body">
                    <div class="sm-note" style="background:#ffebee;border-left-color:#c62828;color:#b71c1c;">
                        <i class="fas fa-ban"></i>
                        <div><strong>NO MEDIATION is allowed for this case.</strong><br>The Barangay must only: record in the Blotter · issue a Referral Letter · issue a BPO if VAWC · coordinate with DSWD / PNP.</div>
                    </div>
                    <div class="sm-grid-2">
                        <div class="sm-field"><label class="sm-label">Auto-Detected Type</label>
                            <input type="text" class="sm-input" value="${escapeHtml(cfg.label)}" readonly style="background:#fff3e0;color:#b71c1c;font-weight:700;border-color:#ef9a9a;">
                        </div>
                        <div class="sm-field"><label class="sm-label">Referral Date</label>
                            <input type="date" id="escDate" class="sm-input" value="${today}">
                        </div>
                    </div>
                    <div class="sm-field"><label class="sm-label">Refer To <span class="req">*</span></label>
                        <input type="text" id="escReferTo" class="sm-input" value="${escapeHtml(cfg.referTo)}">
                    </div>
                    <div class="sm-field"><label class="sm-label">Actions Taken (auto-checked for this case type)</label>
                        <div style="background:#fafbfa;border:1px solid #e2efe8;border-radius:10px;padding:10px 14px;">${actionCheckboxes}</div>
                    </div>
                    <div class="sm-field"><label class="sm-label">Notes / Details of Referral</label>
                        <textarea id="escNotes" class="sm-textarea" rows="3" placeholder="e.g. Called PNP-WCPD at 3:45 PM."></textarea>
                    </div>
                    <div class="sm-field" style="background:#fff8e1;border:1px solid #ffe082;border-radius:10px;padding:12px 14px;">
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:600;color:#5d4037;">
                            <input type="checkbox" id="escAlsoNJ" onchange="document.getElementById('njReasonWrap').style.display=this.checked?'block':'none';">
                            <i class="fas fa-certificate"></i> Also issue Certificate of Non-Jurisdiction
                        </label>
                        <div id="njReasonWrap" style="display:none;margin-top:10px;">
                            <label class="sm-label" style="font-size:0.8rem;">Reason (optional)</label>
                            <textarea id="escNJReason" class="sm-textarea" rows="2"></textarea>
                        </div>
                    </div>
                    ${evidenceCount > 0 ? `
                    <div class="sm-field" style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:10px;padding:12px 14px;">
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:600;color:#1b5e20;">
                            <input type="checkbox" id="escAttachEvidence">
                            <i class="fas fa-paperclip"></i> Attach evidence files (${evidenceCount} file${evidenceCount === 1 ? '' : 's'})
                        </label>
                    </div>` : ''}
                </div>
            </div>`,
        width: 720,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-check-circle"></i> Validate & Forward to Captain',
        confirmButtonColor: '#2E7D32',
        cancelButtonText: 'Cancel',
        cancelButtonColor: '#6c757d',
        allowOutsideClick: false,
        customClass: { popup: 'sm-popup' },
        preConfirm: () => collectReferralForm(visibleActions)
    });

    if (!result.isConfirmed || !result.value) return;
    await submitReferralForward(id, result.value);
}

function collectReferralForm(visibleActions) {
    const referTo = document.getElementById('escReferTo').value.trim();
    const date    = document.getElementById('escDate').value;
    if (!referTo) { Swal.showValidationMessage('Refer To is required.'); return false; }
    if (!date)    { Swal.showValidationMessage('Referral date is required.'); return false; }
    const actions = {};
    visibleActions.forEach(a => {
        const el = document.getElementById('escAction_' + a.key);
        actions[a.key] = el ? el.checked : false;
    });
    const alsoNJ = document.getElementById('escAlsoNJ').checked;
    const attachEvidence = document.getElementById('escAttachEvidence')?.checked || false;
    return {
        referTo, date, actions,
        notes: document.getElementById('escNotes').value || '',
        alsoNJ,
        njReason: alsoNJ ? (document.getElementById('escNJReason').value || '') : '',
        attachEvidence
    };
}

async function submitReferralForward(id, form) {
    const fd = new FormData();
    fd.append('action', 'forward_escalated_to_captain');
    fd.append('complaint_id', id);
    fd.append('refer_to', form.referTo);
    fd.append('referral_date', form.date);
    fd.append('actions', JSON.stringify(form.actions));
    fd.append('notes', form.notes);
    fd.append('also_non_jurisdiction', form.alsoNJ ? '1' : '0');
    fd.append('nj_reason', form.njReason);
    fd.append('attach_evidence', form.attachEvidence ? '1' : '0');

    Swal.fire({ title: 'Validating & Forwarding…', html: 'Generating referral PDF…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    let response;
    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        response = await r.json();
    } catch (e) { Swal.close(); showErrorModal('Network error.'); return; }
    Swal.close();
    if (!response.success) { showErrorModal(response.message || 'Failed.'); return; }
    await showForwardSuccessModal(id, response);
}

async function showForwardSuccessModal(id, response) {
    const njBlock = response.nj_pdf_url
        ? `<div style="margin-top:14px;padding:12px 14px;background:#e3f2fd;border-left:4px solid #1976d2;border-radius:8px;text-align:left;">
              <div style="font-weight:700;color:#0d47a1;margin-bottom:6px;"><i class="fas fa-certificate"></i> Certificate of Non-Jurisdiction</div>
              <div style="font-size:0.82rem;color:#555;">Cert No: <strong>${escapeHtml(response.nj_certificate_no || '—')}</strong></div>
              <a href="../../${response.nj_pdf_url}" target="_blank" rel="noopener" style="display:inline-block;margin-top:8px;padding:6px 14px;background:#1976d2;color:#fff;border-radius:6px;text-decoration:none;font-size:0.82rem;font-weight:600;"><i class="fas fa-external-link-alt"></i> Open Non-Jurisdiction PDF</a>
           </div>` : '';

    await Swal.fire({
        icon: 'success',
        title: 'Forwarded to Captain',
        html: `
            <div style="text-align:left;font-size:0.9rem;">
                <p style="margin:6px 0 12px;">The escalated case has been <strong>validated and forwarded</strong> to the Punong Barangay.</p>
                <div style="padding:12px 14px;background:#e8f5e9;border-left:4px solid #2E7D32;border-radius:8px;">
                    <div style="font-weight:700;color:#1b5e20;margin-bottom:6px;"><i class="fas fa-file-export"></i> Referral Letter</div>
                    <a href="../../${response.pdf_url}" target="_blank" rel="noopener" style="display:inline-block;margin-top:8px;padding:6px 14px;background:#2E7D32;color:#fff;border-radius:6px;text-decoration:none;font-size:0.82rem;font-weight:600;"><i class="fas fa-external-link-alt"></i> Open Referral PDF</a>
                </div>
                ${njBlock}
            </div>`,
        width: 600,
        confirmButtonText: '<i class="fas fa-check"></i> Got it',
        confirmButtonColor: '#2E7D32',
        allowOutsideClick: false
    });
    if (typeof loadComplaints === 'function') loadComplaints(currentComplaintFilter);
}

/* ============================================================
   RELEASE REFERRAL
   ============================================================ */
async function releaseReferral(id) {
    const { value, isConfirmed } = await Swal.fire({
        title: 'Release Referral to Complainant',
        html: `
            <div style="text-align:left;">
                <p style="font-size:0.85rem;color:#555;margin-bottom:12px;">Confirm that the signed referral letter has been physically handed to the complainant.</p>
                <label style="font-weight:600;font-size:0.85rem;color:#1a472a;">Release Notes (optional)</label>
                <textarea id="releaseNotes" class="swal2-textarea" rows="3" placeholder="e.g. Received by complainant in person."></textarea>
            </div>`,
        width: 550,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-hand-holding"></i> Confirm Release',
        confirmButtonColor: '#2E7D32',
        cancelButtonColor: '#6c757d',
        preConfirm: () => ({ notes: document.getElementById('releaseNotes').value.trim() })
    });
    if (!isConfirmed) return;

    const fd = new FormData();
    fd.append('action', 'claim_referral_release');
    fd.append('complaint_id', id);
    fd.append('release_notes', value?.notes || '');

    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) { showSuccessModal('Referral marked as released.'); loadComplaints(currentComplaintFilter); }
        else showErrorModal(d.message || 'Failed to release.');
    } catch (e) { showErrorModal('Network error.'); }
}

/* ============================================================
   ISSUE CERTIFICATE
   ============================================================ */
function openIssueCertificateModal(id) {
    Swal.fire({
        title: 'Issue Certificate to File Action',
        html: `<div style="text-align:left;">
            <p style="font-size:0.82rem;color:#555;">This will generate the Certificate PDF and save it. Only the Secretary can issue it.</p>
            <label style="display:block;font-weight:600;font-size:0.85rem;color:#1a472a;margin-top:10px;">Reason</label>
            <textarea id="certReason" class="swal2-textarea" rows="4" placeholder="e.g. Conciliation failed."></textarea>
        </div>`,
        width: 600,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-file-certificate"></i> Generate Certificate',
        confirmButtonColor: '#2E7D32',
        cancelButtonColor: '#6c757d',
        preConfirm: () => {
            const reason = document.getElementById('certReason').value.trim();
            if (!reason) { Swal.showValidationMessage('Reason is required'); return false; }
            return reason;
        }
    }).then(async res => {
        if (!res.isConfirmed) return;
        const fd = new FormData();
        fd.append('complaint_id', id);
        fd.append('reason', res.value);
        let d;
        try {
            const r = await fetch('../generate_certificate_pdf.php', { method: 'POST', body: fd });
            d = await r.json();
        } catch (e) { showErrorModal('Network error.'); return; }
        if (!d.success) { showErrorModal(d.message || 'Failed'); return; }
        Swal.fire({
            icon: 'success',
            title: 'Certificate Generated',
            html: `<p style="margin:6px 0;">Certificate No: <strong>${escapeHtml(d.certificate_no)}</strong></p>`,
            confirmButtonText: '<i class="fas fa-file-pdf"></i> Open PDF',
            confirmButtonColor: '#2E7D32',
            showCancelButton: true,
            cancelButtonText: 'Close'
        }).then(r2 => {
            if (r2.isConfirmed && d.pdf_url) window.open('../../' + d.pdf_url, '_blank');
            loadComplaints(currentComplaintFilter);
        });
    });
}

/* ============================================================
   OPEN STORED PDFs
   ============================================================ */
async function openPartyNoticePdf(partyId) {
    Swal.fire({ title: 'Loading PDF…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_party_notice_pdf&party_id=${partyId}`);
        const d = await r.json();
        Swal.close();
        if (d.success && d.pdf_url) window.open('../../' + d.pdf_url, '_blank');
        else Swal.fire('Error', d.message || 'Could not open PDF.', 'error');
    } catch (e) {
        Swal.close();
        Swal.fire('Error', 'Network error.', 'error');
    }
}

async function openKp10Pdf(complaintId, partyId) {
    Swal.fire({ title: 'Loading PDF…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const url = `../admin_complaint_ajax.php?action=get_kp10_pdf&complaint_id=${complaintId}` + (partyId ? `&party_id=${partyId}` : '');
        const r = await fetch(url);
        const d = await r.json();
        Swal.close();
        if (d.success && d.pdf_url) window.open('../../' + d.pdf_url, '_blank');
        else Swal.fire('Error', d.message || 'Could not open PDF.', 'error');
    } catch (e) {
        Swal.close();
        Swal.fire('Error', 'Network error.', 'error');
    }
}

async function openKp11Pdf(complaintId, memberId) {
    Swal.fire({ title: 'Loading PDF…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_kp11_pdf&complaint_id=${complaintId}&member_id=${memberId}`);
        const d = await r.json();
        Swal.close();
        if (d.success && d.pdf_url) window.open('../../' + d.pdf_url, '_blank');
        else Swal.fire('Error', d.message || 'Could not open PDF.', 'error');
    } catch (e) {
        Swal.close();
        Swal.fire('Error', 'Network error.', 'error');
    }
}

/* ============================================================
   COMPLAINT DETAILS MODAL
   ============================================================ */
async function viewComplaintDetail(id) {
    let d;
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${id}`);
        d = await r.json();
    } catch (e) { showErrorModal('Error loading complaint.'); return; }
    if (!d || !d.success) { showErrorModal((d && d.message) || 'Could not load'); return; }

    const c = d.complaint;
    const updates = d.updates || [];
    const pangkat = d.pangkat || [];
    const isEscalated = c.status === 'escalated';
    const isPendingCaptain = c.status === 'pending_captain_review';
    const isForRelease = c.status === 'referred_dispatched';
    const isPendingReview = c.status === 'pending_review';
    const isForwarded = c.status === 'pending_captain_action';
    const isPangkatConstituted = c.status === 'pangkat_constituted';
    const isPangkatScheduled = c.status === 'pangkat_scheduled';
    const isInMediation = ['for_mediation','mediation_scheduled'].includes(c.status);

    const escalationType = c.escalation_type || '';
    const referTo = c.escalation_refer_to || 'PNP / DSWD / DOLE';

    const cachedRow = complaintData.find(x => Number(x.id) === Number(id));
    if (!c.__guard && cachedRow && cachedRow.__guard) {
        c.__guard = cachedRow.__guard;
    }

    const evidenceByIncident = {};
    const orphanEvidence = [];
    (d.evidence || []).forEach(ev => {
        const m = (ev.description || '').match(/Incident\s+(\d+)/i);
        if (m) {
            const idx = parseInt(m[1]) - 1;
            if (!evidenceByIncident[idx]) evidenceByIncident[idx] = [];
            evidenceByIncident[idx].push(ev);
        } else orphanEvidence.push(ev);
    });

    const renderEvidenceCard = (ev) => {
        const ext   = (ev.file_name || '').split('.').pop().toLowerCase();
        const isImg = ['jpg','jpeg','png','gif','webp'].includes(ext);
        const isPdf = ext === 'pdf';
        const icon  = isImg ? 'fa-image' : (isPdf ? 'fa-file-pdf' : 'fa-file');
        const color = isImg ? '#1976d2' : (isPdf ? '#c62828' : '#666');
        const href  = `../../${ev.file_path}`;
        if (isImg) {
            return `<a href="${href}" target="_blank" class="ev-card" title="${escapeHtml(ev.file_name)}">
                <img src="${href}" alt="${escapeHtml(ev.file_name)}" onerror="this.style.display='none'; this.parentElement.querySelector('.ev-fallback').style.display='flex';">
                <div class="ev-fallback" style="display:none;"><i class="fas fa-image" style="color:${color};font-size:2rem;"></i></div>
                <div class="ev-label"><i class="fas fa-image" style="color:${color};"></i><span>${escapeHtml(ev.file_name)}</span></div>
            </a>`;
        }
        return `<a href="${href}" target="_blank" class="ev-card" title="${escapeHtml(ev.file_name)}">
            <div class="ev-fallback" style="display:flex;"><i class="fas ${icon}" style="color:${color};font-size:2rem;"></i></div>
            <div class="ev-label"><i class="fas ${icon}" style="color:${color};"></i><span>${escapeHtml(ev.file_name)}</span></div>
        </a>`;
    };

    const kpRows = [];
    (d.complainants || []).forEach(p => {
        if (p.notice_pdf_path) kpRows.push({ party_id: p.id, type: 'kp8',
            label: 'KP Form #8 — Notice of Hearing (Mediation Proceedings)',
            recipient: p.full_name, role: 'Complainant',
            sent_at: p.notice_of_hearing_sent_at || null, notified: p.notified_via === 'push' });
        if (p.kp10_pdf_path) kpRows.push({ party_id: p.id, type: 'kp10',
            label: 'KP Form #10 — Notice for Constitution of Pangkat',
            recipient: p.full_name, role: 'Complainant',
            sent_at: p.kp10_issued_at || null, notified: p.kp10_notified_via === 'push' });
    });
    (d.respondents || []).forEach(p => {
        if (p.notice_pdf_path) kpRows.push({ party_id: p.id, type: 'kp9',
            label: 'KP Form #9 — Summons',
            recipient: p.full_name, role: 'Respondent',
            sent_at: p.summons_sent_at || null, notified: p.notified_via === 'push' });
        if (p.kp10_pdf_path) kpRows.push({ party_id: p.id, type: 'kp10',
            label: 'KP Form #10 — Notice for Constitution of Pangkat',
            recipient: p.full_name, role: 'Respondent',
            sent_at: p.kp10_issued_at || null, notified: p.kp10_notified_via === 'push' });
    });
    (d.pangkat || []).forEach(m => {
        if (m.kp11_pdf_path) kpRows.push({ member_id: m.member_id, type: 'kp11',
            label: 'KP Form #11 — Notice to Chosen Pangkat Member',
            recipient: m.full_name, role: 'Pangkat Member',
            sent_at: m.kp11_issued_at || null, notified: true });
    });

    kpRows.sort((a, b) => (a.notified === b.notified) ? 0 : (a.notified ? 1 : -1));
    const printRequiredCount = kpRows.filter(r => !r.notified).length;

    const kpHtml = kpRows.length ? `
        <div class="cd-block">
            <div class="cd-block-title">
                <i class="fas fa-file-signature"></i> Forms & Notices Issued (${kpRows.length})
                ${printRequiredCount > 0 ? `<span style="margin-left:auto;background:#c62828;color:#fff;padding:3px 10px;border-radius:12px;font-size:0.7rem;font-weight:800;animation:pulseBadge2 1.5s infinite;"><i class="fas fa-print"></i> ${printRequiredCount} to PRINT</span>` : ''}
            </div>
            ${printRequiredCount > 0 ? `
                <div style="padding:12px 14px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;margin-bottom:12px;color:#b71c1c;font-size:0.85rem;">
                    <b>⚠ ${printRequiredCount} notice(s) require printing.</b><br>
                    These parties have no resident account. Please <b>print the PDF and deliver</b> it to them in person.
                </div>` : ''}
            ${kpRows.map(r => {
                const sent = !!r.sent_at;
                const nice = sent ? new Date(r.sent_at).toLocaleString('en-US', { dateStyle:'medium', timeStyle:'short' }) : 'Not yet issued';
                const isKp8 = r.type === 'kp8';
                const printCls = r.notified ? '' : 'print-required';
                const iconCls  = r.notified ? r.type : 'print-required';
                const icon = isKp8 ? 'fa-file-alt' : (r.type === 'kp9' ? 'fa-file-signature' : (r.type === 'kp10' ? 'fa-users-cog' : 'fa-user-tag'));
                const badge = r.notified
                    ? `<span class="notice-item-notified yes"><i class="fas fa-check"></i> Resident notified</span>`
                    : `<span class="notice-item-notified no"><i class="fas fa-print"></i> ⚠ PRINT REQUIRED</span>`;
                let btn = '';
                if (r.type === 'kp10') {
                    btn = `<button type="button" class="notice-item-btn kp10" onclick="openKp10Pdf(${c.id}, ${r.party_id})"><i class="fas fa-file-pdf"></i> Open PDF</button>`;
                } else if (r.type === 'kp11') {
                    btn = `<button type="button" class="notice-item-btn kp11" onclick="openKp11Pdf(${c.id}, ${r.member_id})"><i class="fas fa-file-pdf"></i> Open PDF</button>`;
                } else if (r.party_id) {
                    btn = `<button type="button" class="notice-item-btn ${r.notified ? r.type : 'print-required'}" onclick="openPartyNoticePdf(${r.party_id})"><i class="fas fa-file-pdf"></i> ${r.notified ? 'Open PDF' : '🖨 Open & Print PDF'}</button>`;
                }
                return `
                    <div class="notice-item ${r.type} ${printCls}" style="margin-bottom:8px;">
                        <div class="notice-item-icon ${iconCls}"><i class="fas ${icon}"></i></div>
                        <div class="notice-item-body">
                            <div class="notice-item-title">${escapeHtml(r.recipient)} — ${escapeHtml(r.label)}</div>
                            <div class="notice-item-meta">
                                <span><i class="fas fa-user"></i> ${escapeHtml(r.role)}</span>
                                <span><i class="fas fa-clock"></i> ${escapeHtml(nice)}</span>
                                ${badge}
                            </div>
                            ${btn}
                        </div>
                    </div>`;
            }).join('')}
        </div>` : '';

    const pangkatHtml = pangkat.length ? `
        <div class="cd-block">
            <div class="cd-block-title"><i class="fas fa-balance-scale"></i> Pangkat Panel (${pangkat.length})</div>
            <div class="cd-people-list">
                ${pangkat.map((p, i) => {
                    const roleText = (p.role === 'pending' || !p.role) ? 'Awaiting Position Assignment' : ucwords(p.role);
                    const roleBg = (p.role === 'chair') ? '#e8f5e9' : (p.role === 'secretary') ? '#e3f2fd' : (p.role === 'member') ? '#fff3e0' : '#fff8e1';
                    const roleColor = (p.role === 'chair') ? '#2E7D32' : (p.role === 'secretary') ? '#1565c0' : (p.role === 'member') ? '#e65100' : '#f57c00';
                    const isFirstMember = (i === 0);
                    return `<div class="cd-person">
                        <div class="cd-person-avatar" style="background:#f1f8e9;color:#2E7D32;"><i class="fas fa-user-tie"></i></div>
                        <div style="flex:1;">
                            <div class="cd-person-name">${i + 1}. ${escapeHtml(p.full_name)} ${isFirstMember ? '<span style="font-size:0.7rem;color:#2E7D32;font-weight:700;">(first-selected)</span>' : ''}</div>
                            <div class="cd-person-meta">
                                <span class="cd-role-pill" style="background:${roleBg};color:${roleColor};">${escapeHtml(roleText)}</span>
                                ${p.selected_by ? `<span style="font-size:0.72rem;color:#888;">Chosen by: ${escapeHtml(p.selected_by)}</span>` : ''}
                            </div>
                        </div>
                    </div>`;
                }).join('')}
            </div>
        </div>` : '';

    const guardIsActive = c.__guard
        && c.__guard.level !== 'clean'
        && c.__guard.tags && c.__guard.tags.length
        && c.status === 'pending_review';

    let headerClass = '';
    if (isEscalated) headerClass = 'cd-escalated';
    else if (guardIsActive && c.__guard.level === 'high')   headerClass = 'cd-guard-high';
    else if (guardIsActive && c.__guard.level === 'medium') headerClass = 'cd-guard-warn';
    else if (isInMediation) headerClass = 'cd-mediation';
    else if (isForwarded || isPangkatConstituted || isPangkatScheduled) headerClass = 'cd-forwarded';

    const headerIcon = guardIsActive
        ? c.__guard.tags[0].icon
        : (isEscalated ? 'fa-exclamation-triangle'
            : (isInMediation ? 'fa-gavel'
            : (isForwarded ? 'fa-paper-plane'
            : (isPangkatConstituted ? 'fa-users-cog'
            : (isPendingCaptain ? 'fa-hourglass-half'
            : (isForRelease ? 'fa-inbox' : 'fa-folder-open'))))));

    await Swal.fire({
        title: '',
        html: `
        <div class="cd-modal">
            <div class="cd-header ${headerClass}">
                <div class="cd-header-left">
                    <i class="fas ${headerIcon}"></i>
                    <div>
                        <div class="cd-title">${escapeHtml(c.title)}</div>
                        <div class="cd-sub">Ref: <strong>${escapeHtml(c.reference_number)}</strong></div>
                    </div>
                </div>
                <div class="cd-status">${(c.status || '').replace(/_/g,' ').toUpperCase()}</div>
            </div>
            <div class="cd-body">
                ${c.__guard && c.__guard.level !== 'clean' && c.__guard.tags && c.__guard.tags.length && c.status === 'pending_review' ? (() => {
                    const gl = guardLabel(c.__guard);
                    const isHigh = c.__guard.level === 'high';
                    const tags = c.__guard.tags;
                    const wrapperBg = isHigh
                        ? 'linear-gradient(135deg,#ffebee,#ffcdd2)'
                        : 'linear-gradient(135deg,#fff8e1,#ffe0b2)';

                    return `
                    <div style="background:${wrapperBg};
                                border:2px solid ${gl.color};
                                border-left:8px solid ${gl.color};
                                border-radius:14px;padding:18px 22px;
                                color:${gl.color};
                                box-shadow:0 6px 18px ${gl.color}22;">
                        <div style="display:flex;gap:14px;align-items:flex-start;">
                            <div style="width:52px;height:52px;border-radius:50%;
                                        background:${gl.color};color:#fff;
                                        display:flex;align-items:center;justify-content:center;
                                        flex-shrink:0;font-size:1.35rem;
                                        box-shadow:0 4px 14px ${gl.color}66;">
                                <i class="fas ${gl.icon}"></i>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:900;font-size:1.05rem;
                                            text-transform:uppercase;letter-spacing:0.7px;
                                            margin-bottom:6px;line-height:1.25;">
                                    ${escapeHtml(gl.headline)}
                                </div>
                                <div style="font-size:0.86rem;line-height:1.55;color:#333;
                                            margin-bottom:14px;">
                                    ${escapeHtml(gl.advice)}
                                </div>

                                <div style="background:rgba(255,255,255,0.75);border-radius:10px;
                                            padding:14px 16px;border:1px solid ${gl.color}22;">
                                    <div style="font-size:0.72rem;font-weight:800;
                                                color:${gl.color};text-transform:uppercase;
                                                letter-spacing:0.5px;margin-bottom:10px;
                                                display:flex;align-items:center;gap:6px;">
                                        <i class="fas fa-clipboard-list"></i>
                                        Why this was flagged (${tags.length})
                                    </div>
                                    <div style="display:flex;flex-direction:column;gap:10px;">
                                        ${tags.map(t => `
                                            <div style="display:flex;gap:10px;align-items:flex-start;
                                                        padding:10px 12px;border-radius:8px;
                                                        background:#fff;
                                                        border-left:4px solid ${t.color};">
                                                <div style="width:28px;height:28px;border-radius:50%;
                                                            background:${t.color}22;color:${t.color};
                                                            display:flex;align-items:center;justify-content:center;
                                                            flex-shrink:0;font-size:0.75rem;">
                                                    <i class="fas ${t.icon}"></i>
                                                </div>
                                                <div style="flex:1;">
                                                    <div style="font-weight:800;font-size:0.82rem;
                                                                color:${t.color};margin-bottom:3px;">
                                                        ${escapeHtml(t.label)}
                                                    </div>
                                                    <div style="font-size:0.78rem;color:#444;line-height:1.5;">
                                                        ${escapeHtml(t.advice)}
                                                    </div>
                                                </div>
                                            </div>`).join('')}
                                    </div>
                                </div>

                                ${c.__guard.reasons && c.__guard.reasons.length ? `
                                <details style="margin-top:12px;">
                                    <summary style="cursor:pointer;font-size:0.72rem;color:#666;
                                                     padding:4px 0;user-select:none;">
                                        Show raw detection log (${c.__guard.reasons.length})
                                    </summary>
                                    <ul style="margin:6px 0 0 18px;padding:0;
                                                font-size:0.72rem;color:#555;line-height:1.6;">
                                        ${c.__guard.reasons.map(r => `<li>${escapeHtml(r)}</li>`).join('')}
                                    </ul>
                                </details>` : ''}

                                <div style="display:flex;gap:14px;margin-top:12px;
                                            font-size:0.74rem;color:#666;flex-wrap:wrap;
                                            background:rgba(255,255,255,0.55);
                                            padding:8px 12px;border-radius:8px;">
                                    <span><i class="fas fa-info-circle" style="color:${gl.color};"></i>
                                        This is a hint only — forwarding is still allowed if you believe it is genuine.</span>
                                </div>
                            </div>
                        </div>
                    </div>`;
                })() : ''}

                ${isEscalated ? `
                <div class="cd-escalation-alert">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <div class="cd-esc-title">⚠️ ESCALATED — NOT UNDER KATARUNGANG PAMBARANGAY</div>
                        <div class="cd-esc-body">
                            <strong>Escalation Type:</strong> ${escapeHtml(escalationType || 'Unknown')}<br>
                            <strong>Refer To:</strong> ${escapeHtml(referTo)}<br>
                            <strong>NO MEDIATION</strong> may be conducted for this case.
                        </div>
                    </div>
                </div>` : ''}

                ${isPendingReview ? `
                <div style="background:#fff3e0;border-left:6px solid #e65100;border-radius:14px;padding:16px 20px;color:#e65100;display:flex;gap:14px;align-items:flex-start;">
                    <i class="fas fa-clipboard-check" style="font-size:1.8rem;flex-shrink:0;"></i>
                    <div>
                        <div style="font-weight:800;font-size:1rem;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">⏳ PENDING YOUR REVIEW</div>
                        <div style="font-size:0.88rem;line-height:1.6;color:#333;">
                            Please review this complaint. If valid, click <strong>Forward to Captain</strong>. The Captain will then set the mediation schedule and issue:
                            <ul style="margin-top:8px;font-size:0.85rem;line-height:1.6;">
                                <li><b>KP Form #8</b> (Notice of Hearing) → Complainant</li>
                                <li><b>KP Form #9</b> (Summons) → Respondent</li>
                            </ul>
                        </div>
                    </div>
                </div>` : ''}

                ${isForwarded ? `
                <div style="background:#e3f2fd;border-left:6px solid #1565c0;border-radius:14px;padding:16px 20px;color:#1565c0;display:flex;gap:14px;align-items:flex-start;">
                    <i class="fas fa-paper-plane" style="font-size:1.8rem;flex-shrink:0;"></i>
                    <div><div style="font-weight:800;font-size:1rem;text-transform:uppercase;">📤 FORWARDED TO CAPTAIN</div>
                        <div style="font-size:0.88rem;line-height:1.6;color:#333;margin-top:6px;">The Captain has been notified. They will set the mediation schedule and issue summons and notices.</div>
                    </div>
                </div>` : ''}

                ${isPangkatConstituted ? `
                <div style="background:#e3f2fd;border-left:6px solid #1565c0;border-radius:14px;padding:16px 20px;color:#1565c0;display:flex;gap:14px;align-items:flex-start;">
                    <i class="fas fa-users-cog" style="font-size:1.8rem;flex-shrink:0;"></i>
                    <div><div style="font-weight:800;font-size:1rem;text-transform:uppercase;">⚖️ PANGKAT CONSTITUTED</div>
                        <div style="font-size:0.88rem;line-height:1.6;color:#333;margin-top:6px;">
                            The <b>Chairperson</b> will assign positions and schedule the conciliation hearing.
                            You will be notified once the hearing is scheduled.
                        </div>
                    </div>
                </div>` : ''}

                ${isPangkatScheduled ? `
                <div style="background:#e3f2fd;border-left:6px solid #1565c0;border-radius:14px;padding:16px 20px;color:#1565c0;display:flex;gap:14px;align-items:flex-start;">
                    <i class="fas fa-calendar-check" style="font-size:1.8rem;flex-shrink:0;"></i>
                    <div><div style="font-weight:800;font-size:1rem;text-transform:uppercase;">📅 PANGKAT CONCILIATION SCHEDULED</div>
                        <div style="font-size:0.88rem;line-height:1.6;color:#333;margin-top:6px;">Lupon members have been notified.</div>
                    </div>
                </div>` : ''}

                ${isPendingCaptain ? `
                <div style="background:#fff8e1;border-left:6px solid #f57c00;border-radius:14px;padding:16px 20px;color:#e65100;display:flex;gap:14px;align-items:flex-start;">
                    <i class="fas fa-hourglass-half" style="font-size:1.8rem;flex-shrink:0;"></i>
                    <div><div style="font-weight:800;font-size:1rem;text-transform:uppercase;">⏳ PENDING CAPTAIN REVIEW</div>
                        <div style="font-size:0.88rem;line-height:1.6;color:#333;margin-top:6px;">Awaiting the Captain's signature and dispatch.</div>
                    </div>
                </div>` : ''}

                ${isForRelease ? `
                <div style="background:#e8f5e9;border-left:6px solid #2e7d32;border-radius:14px;padding:16px 20px;color:#1b5e20;display:flex;gap:14px;align-items:flex-start;">
                    <i class="fas fa-inbox" style="font-size:1.8rem;flex-shrink:0;"></i>
                    <div><div style="font-weight:800;font-size:1rem;text-transform:uppercase;">📬 FOR RELEASE</div>
                        <div style="font-size:0.88rem;line-height:1.6;color:#333;margin-top:6px;">Please release the referral letter to the complainant.</div>
                    </div>
                </div>` : ''}

                <div class="cd-block">
                    <div class="cd-block-title"><span class="cd-num">1</span> Complaint Information</div>
                    <div class="cd-grid">
                        <div class="cd-grid-item"><label>Reference</label><span>${escapeHtml(c.reference_number)}</span></div>
                        <div class="cd-grid-item"><label>Filed</label><span>${new Date(c.created_at).toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' })}</span></div>
                        <div class="cd-grid-item"><label>Subject</label><span>${escapeHtml(c.complaint_subject || '-')}</span></div>
                        <div class="cd-grid-item"><label>Priority</label><span>${(c.priority || 'medium').toUpperCase()}</span></div>
                        <div class="cd-grid-item"><label>Status</label><span>${(c.status || '').replace(/_/g,' ').toUpperCase()}</span></div>
                        ${c.assigned_to_name ? `<div class="cd-grid-item"><label>Assigned To</label><span>${escapeHtml(c.assigned_to_name)}</span></div>` : ''}
                        ${c.hearing_date ? `<div class="cd-grid-item"><label>Hearing</label><span>${new Date(c.hearing_date).toLocaleDateString('en-US', { dateStyle: 'medium' })}${c.hearing_time ? ' · ' + escapeHtml(c.hearing_time) : ''}</span></div>` : ''}
                    </div>
                    <div class="cd-text-block">
                        <label>Title</label>
                        <p style="font-weight:700;font-size:1rem;color:#1a472a;margin:0;">${escapeHtml(c.title)}</p>
                    </div>
                    <div class="cd-text-block">
                        <label>Description</label>
                        <p style="margin:0;line-height:1.6;">${escapeHtml(c.description || '-').replace(/\n/g, '<br>')}</p>
                    </div>
                </div>

                <div class="cd-block">
                    <div class="cd-block-title"><span class="cd-num">2</span> Complainant(s) — ${(d.complainants || []).length}</div>
                    ${(d.complainants || []).length ? `
                        <div class="cd-people-list">
                            ${d.complainants.map((p, i) => `
                                <div class="cd-person">
                                    <div class="cd-person-avatar" style="background:#e8f5e9;color:#2E7D32;"><i class="fas fa-user"></i></div>
                                    <div>
                                        <div class="cd-person-name">${i + 1}. ${escapeHtml(p.full_name)}</div>
                                        <div class="cd-person-meta">
                                            ${p.address ? `<span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(p.address)}</span>` : ''}
                                            ${p.contact_number ? `<span><i class="fas fa-phone"></i> ${escapeHtml(p.contact_number)}</span>` : ''}
                                        </div>
                                    </div>
                                </div>`).join('')}
                        </div>` : '<p class="cd-empty">No complainants listed.</p>'}
                </div>

                <div class="cd-block">
                    <div class="cd-block-title"><span class="cd-num">3</span> Respondent(s) — ${(d.respondents || []).length}</div>
                    ${(d.respondents || []).length ? `
                        <div class="cd-people-list">
                            ${d.respondents.map((p, i) => `
                                <div class="cd-person">
                                    <div class="cd-person-avatar" style="background:#fff3e0;color:#e65100;"><i class="fas fa-user-friends"></i></div>
                                    <div>
                                        <div class="cd-person-name">${i + 1}. ${escapeHtml(p.full_name)}</div>
                                        <div class="cd-person-meta">
                                            ${p.address ? `<span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(p.address)}</span>` : ''}
                                            ${p.contact_number ? `<span><i class="fas fa-phone"></i> ${escapeHtml(p.contact_number)}</span>` : ''}
                                        </div>
                                    </div>
                                </div>`).join('')}
                        </div>` : '<p class="cd-empty">No respondents listed.</p>'}
                </div>

                ${kpHtml}
                ${pangkatHtml}

                ${(d.witnesses || []).length ? `
                <div class="cd-block">
                    <div class="cd-block-title"><span class="cd-num">4</span> Witness(es) — ${d.witnesses.length}</div>
                    <div class="cd-people-list">
                        ${d.witnesses.map((p, i) => `
                            <div class="cd-person">
                                <div class="cd-person-avatar" style="background:#e3f2fd;color:#1565c0;"><i class="fas fa-eye"></i></div>
                                <div><div class="cd-person-name">${i + 1}. ${escapeHtml(p.full_name)}</div></div>
                            </div>`).join('')}
                    </div>
                </div>` : ''}

                <div class="cd-block">
                    <div class="cd-block-title"><span class="cd-num">5</span> Incidents — ${(d.incidents || []).length}</div>
                    ${(d.incidents || []).length ? `
                        ${d.incidents.map((inc, i) => `
                            <div class="cd-incident">
                                <div class="cd-incident-head">
                                    <strong><i class="fas fa-exclamation-circle" style="color:#6a1b9a;"></i> Incident ${i + 1}</strong>
                                    <span class="cd-incident-meta">
                                        ${inc.incident_date ? `<i class="fas fa-calendar"></i> ${inc.incident_date}` : ''}
                                        ${inc.incident_time ? ` <i class="fas fa-clock"></i> ${inc.incident_time}` : ''}
                                    </span>
                                </div>
                                ${inc.location ? `<div class="cd-incident-loc"><i class="fas fa-map-marker-alt"></i> ${escapeHtml(inc.location)}</div>` : ''}
                                <div class="cd-incident-desc">${escapeHtml(inc.description || '-').replace(/\n/g, '<br>')}</div>
                                ${(evidenceByIncident[i] || []).length ? `
                                    <div class="cd-evidence-block">
                                        <div class="cd-evidence-title"><i class="fas fa-paperclip"></i> Evidence (${evidenceByIncident[i].length})</div>
                                        <div class="cd-carousel">${evidenceByIncident[i].map(renderEvidenceCard).join('')}</div>
                                    </div>` : ''}
                            </div>`).join('')}
                    ` : '<p class="cd-empty">No incidents recorded.</p>'}
                </div>

                ${orphanEvidence.length ? `
                <div class="cd-block">
                    <div class="cd-block-title"><i class="fas fa-paperclip"></i> Other Evidence — ${orphanEvidence.length}</div>
                    <div class="cd-carousel">${orphanEvidence.map(renderEvidenceCard).join('')}</div>
                </div>` : ''}

                <div class="cd-block">
                    <div class="cd-block-title"><i class="fas fa-comments"></i> Notes & History (${updates.length})</div>
                    <div class="cd-notes-input">
                        <textarea id="cdNoteInput" class="cd-textarea" rows="2" placeholder="Write a note for the case history..."></textarea>
                        <button class="cd-btn primary" onclick="cdSubmitNote(${c.id})" style="margin-top:8px;">
                            <i class="fas fa-plus-circle"></i> Add Note
                        </button>
                    </div>
                    <div class="cd-history-list" style="margin-top:16px;">
                        ${updates.length ? updates.map(u => `
                            <div class="cd-history-item">
                                <div class="cd-history-time">${new Date(u.created_at).toLocaleString()}</div>
                                <div class="cd-history-notes">${escapeHtml(u.notes || '')}</div>
                                <div class="cd-history-meta">${u.updated_by_role ? 'by ' + escapeHtml(u.updated_by_role) : ''}</div>
                            </div>`).join('') : '<div class="cd-empty">No history yet.</div>'}
                    </div>
                </div>
            </div>

            <div class="cd-actions">
                ${c.status === 'pending_review' ? `
                    <button class="cd-btn forward" onclick="Swal.close(); setTimeout(() => forwardToCaptain(${c.id}), 250);"><i class="fas fa-paper-plane"></i> Forward to Captain</button>
                    <button class="cd-btn warning" onclick="cdDismiss(${c.id})"><i class="fas fa-ban"></i> Dismiss as Prank</button>
                ` : ''}

                ${c.status === 'pending_captain_action' ? `
                    <div class="cd-info-banner">
                        <i class="fas fa-hourglass-half"></i>
                        <span>Forwarded to Captain — awaiting mediation schedule</span>
                    </div>
                ` : ''}

                ${c.status === 'pangkat_constituted' ? `
                    <div class="cd-info-banner">
                        <i class="fas fa-hourglass-half"></i>
                        <span>Pangkat constituted — the <b>Chairperson</b> will schedule the conciliation hearing from the Lupon Portal.</span>
                    </div>
                ` : ''}

                ${c.status === 'pangkat_scheduled' ? `
                    <div class="cd-info-banner">
                        <i class="fas fa-calendar-check"></i>
                        <span>Pangkat conciliation scheduled — awaiting Lupon hearing</span>
                    </div>
                ` : ''}

                ${c.status === 'escalated' ? `
                    <button class="cd-btn danger" onclick="Swal.close(); openEscalationReferralModal(${c.id});"><i class="fas fa-file-export"></i> Validate & Forward to Captain</button>
                    <button class="cd-btn warning" onclick="cdDismiss(${c.id})"><i class="fas fa-ban"></i> Mark as PRANK / INVALID</button>
                ` : ''}

                ${c.status === 'failed_mediation' ? `
                    <div class="cd-info-banner" style="background:#fff3e0;border-left-color:#e65100;color:#e65100;">
                        <i class="fas fa-hourglass-half"></i>
                        <span>Waiting for Captain to constitute the Pangkat</span>
                    </div>
                    <button class="cd-btn warning" onclick="cdDismiss(${c.id})"><i class="fas fa-ban"></i> Dismiss</button>
                ` : ''}

                ${c.status === 'failed_conciliation_final' ? `
                    <button class="cd-btn cert" onclick="Swal.close(); openIssueCertificateModal(${c.id});"><i class="fas fa-certificate"></i> Issue Certificate to File Action</button>
                ` : ''}

                ${c.status === 'pending_captain_review' ? `
                    <div class="cd-info-banner" style="background:#fff8e1;border-left-color:#f57c00;color:#e65100;">
                        <i class="fas fa-hourglass-half"></i>
                        <span>Awaiting Captain's signature &amp; dispatch</span>
                    </div>
                    ${c.referral_pdf_path ? `<a href="../../${escapeHtml(c.referral_pdf_path)}" target="_blank" class="cd-btn primary" style="text-decoration:none;"><i class="fas fa-file-pdf"></i> Open Referral PDF</a>` : ''}
                ` : ''}

                ${c.status === 'referred_dispatched' ? `
                    <div class="cd-info-banner" style="background:#e8f5e9;border-left-color:#2e7d32;color:#1b5e20;">
                        <i class="fas fa-inbox"></i>
                        <span>Ready for release to complainant</span>
                    </div>
                    ${c.referral_pdf_path ? `<a href="../../${escapeHtml(c.referral_pdf_path)}" target="_blank" class="cd-btn primary" style="text-decoration:none;"><i class="fas fa-file-pdf"></i> Open Referral PDF</a>` : ''}
                    <button class="cd-btn primary" onclick="Swal.close(); releaseReferral(${c.id});"><i class="fas fa-hand-holding"></i> Release to Complainant</button>
                ` : ''}

                ${c.status === 'released' ? `<span style="color:#2E7D32;font-weight:600;font-size:0.85rem;margin-right:auto;"><i class="fas fa-check-double"></i> Released — case closed</span>` : ''}
                ${c.status === 'certificate_issued' ? `
                    <span style="color:#1565c0;font-weight:600;font-size:0.85rem;display:inline-flex;align-items:center;gap:6px;margin-right:auto;">
                        <i class="fas fa-certificate"></i> Certificate issued — No. ${escapeHtml(c.certificate_no || 'N/A')}
                    </span>
                    ${c.certificate_pdf_path ? `<a href="../../${escapeHtml(c.certificate_pdf_path)}" target="_blank" class="cd-btn primary" style="text-decoration:none;"><i class="fas fa-file-pdf"></i> Open Certificate PDF</a>` : ''}
                ` : ''}
                ${c.status === 'settled' ? `<span style="color:#2E7D32;font-weight:600;font-size:0.85rem;margin-right:auto;"><i class="fas fa-handshake"></i> Settled — case closed</span>` : ''}
                ${c.status === 'dismissed' ? `<span style="color:#6c757d;font-weight:600;font-size:0.85rem;margin-right:auto;"><i class="fas fa-ban"></i> Dismissed — case closed</span>` : ''}

                <button class="cd-btn secondary" onclick="Swal.close()">Close</button>
            </div>
        </div>`,
        width: 1080,
        showConfirmButton: false,
        showCancelButton: false,
        customClass: { popup: 'cd-popup' }
    });
}

/* ============================================================
   DISMISS REASON CATEGORIES
   ============================================================ */
const DISMISS_CATEGORIES = [
    { key: 'prank',             label: 'Prank / Invalid Complaint', icon: 'fa-face-grin-tongue', hint: 'Submitted as a joke or with no genuine grievance.', autoText: 'This complaint was submitted as a prank and does not involve a genuine grievance.' },
    { key: 'duplicate',         label: 'Duplicate Complaint', icon: 'fa-copy', hint: 'Identical to an existing complaint from the same user.', autoText: 'This complaint is an exact duplicate of an existing complaint already filed by the same complainant.' },
    { key: 'possible_duplicate',label: 'Possible Duplicate', icon: 'fa-clone', hint: 'Similar to an existing complaint — verify before deciding.', autoText: 'This complaint is materially similar to an existing complaint filed by the same complainant. Please confirm whether this is a distinct incident.' },
    { key: 'placeholder',       label: 'Placeholder / Fake Names', icon: 'fa-user-slash', hint: 'Complainant or respondent name looks like a placeholder.', autoText: 'The party names in this complaint appear to be placeholders rather than real persons.' },
    { key: 'gibberish',         label: 'Gibberish / Unreadable Text', icon: 'fa-keyboard', hint: 'Title or description contains random characters or keyboard mash.', autoText: 'The complaint title or description contains unreadable or gibberish text, making it impossible to act on.' },
    { key: 'incomplete',        label: 'Incomplete / Insufficient Details', icon: 'fa-ruler-horizontal', hint: 'Not enough details to determine jurisdiction or action.', autoText: 'This complaint does not contain enough details to determine the appropriate action.' },
    { key: 'inappropriate',     label: 'Inappropriate Language', icon: 'fa-exclamation-triangle', hint: 'Contains profanity or offensive terms.', autoText: 'This complaint contains inappropriate or offensive language and cannot be processed as filed.' },
    { key: 'spam',              label: 'Spam / Rapid Re-filing', icon: 'fa-bolt', hint: 'Filed within seconds of another complaint from the same user.', autoText: 'This complaint was filed within seconds of another complaint by the same user and appears to be spam.' },
    { key: 'other',             label: 'Other (please specify below)', icon: 'fa-ellipsis', hint: 'Any other valid reason — describe it in the field below.', autoText: '' },
];

function pickSuggestedDismissCategory(guard) {
    if (!guard || guard.level === 'clean' || !guard.tags || !guard.tags.length) return '';
    const primary = guard.tags[0].key;
    const map = {
        placeholder:        'placeholder',
        gibberish:          'gibberish',
        spam:               'spam',
        duplicate:          'duplicate',
        possible_duplicate: 'possible_duplicate',
        inappropriate:      'inappropriate',
        incomplete:         'incomplete',
        suspicious:         'other',
    };
    return map[primary] || '';
}

async function cdDismiss(id) {
    let row = Array.isArray(complaintData)
        ? complaintData.find(x => Number(x.id) === Number(id))
        : null;

    if (!row) {
        Swal.fire({ title: 'Loading…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        try {
            const r = await fetch(`../admin_complaint_ajax.php?action=get_all_complaints&status=all`);
            const d = await r.json();
            if (d.success && Array.isArray(d.complaints)) {
                const normalized = d.complaints.map(c => ({ ...c, id: Number(c.id) }));
                complaintData = guardAnnotateList(normalized);
                row = complaintData.find(x => Number(x.id) === Number(id));
            }
        } catch (e) {
            Swal.close();
            showErrorModal('Network error — could not load the complaint.');
            return;
        }
        Swal.close();
        if (!row) { showErrorModal('Complaint not found.'); return; }
    }

    const guard       = row.__guard;
    const isEscalated = row.status === 'escalated';
    const suggestedCat = pickSuggestedDismissCategory(guard);
    const suggestedText = (() => {
        if (!guard || guard.level === 'clean') return '';
        const c = DISMISS_CATEGORIES.find(x => x.key === suggestedCat);
        return c ? c.autoText : '';
    })();

    const optionsHtml = DISMISS_CATEGORIES.map(c => {
        const selected = (c.key === suggestedCat) ? 'selected' : '';
        return `<option value="${c.key}" ${selected}>${escapeHtml(c.label)}</option>`;
    }).join('');

    const guardHintHtml = (guard && guard.level !== 'clean' && guard.tags && guard.tags.length) ? (() => {
        const gl = guardLabel(guard);
        const isHigh = guard.level === 'high';
        const chips = guard.tags.map(t => `
            <span style="display:inline-flex;align-items:center;gap:5px;
                         background:#fff;color:${t.color};
                         padding:4px 10px;border-radius:14px;
                         font-size:0.72rem;font-weight:700;
                         border:1px solid ${t.color}55;">
                <i class="fas ${t.icon}" style="font-size:0.62rem;"></i>
                ${escapeHtml(t.label)}
            </span>`).join('');
        return `
        <div style="background:${isHigh ? '#ffebee' : '#fff8e1'};
                    border-left:4px solid ${isHigh ? '#c62828' : '#f57c00'};
                    border-radius:10px;padding:12px 14px;margin-bottom:16px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;
                        font-size:0.78rem;font-weight:800;
                        color:${isHigh ? '#b71c1c' : '#e65100'};">
                <i class="fas fa-magic"></i>
                Content checker suggests: <strong>${escapeHtml(guard.tags[0].label)}</strong>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;">${chips}</div>
            <div style="font-size:0.72rem;color:#666;line-height:1.5;">
                The dropdown below is pre-selected based on this flag. Change it if the reason is different.
            </div>
        </div>`;
    })() : '';

    const { value, isConfirmed } = await Swal.fire({
        title: '',
        html: `
            <div style="text-align:left;font-family:'Segoe UI',Roboto,sans-serif;">
                <div style="background:linear-gradient(135deg,#b71c1c,#c62828 55%,#e53935);
                            color:#fff;padding:18px 22px;border-radius:14px 14px 0 0;
                            margin:-1px -1px 0 -1px;display:flex;align-items:center;gap:14px;">
                    <div style="width:48px;height:48px;border-radius:50%;
                                background:rgba(255,255,255,0.2);
                                display:flex;align-items:center;justify-content:center;
                                flex-shrink:0;font-size:1.3rem;">
                        <i class="fas fa-ban"></i>
                    </div>
                    <div>
                        <div style="font-size:1.08rem;font-weight:800;letter-spacing:0.3px;">
                            ${isEscalated ? 'Mark as PRANK / INVALID' : 'Dismiss Complaint'}
                        </div>
                        <div style="font-size:0.78rem;opacity:0.9;margin-top:3px;">
                            Pick a reason and confirm — the complainant will be notified.
                        </div>
                    </div>
                </div>
                <div style="padding:20px 22px;background:#fff;">
                    ${guardHintHtml}
                    <div style="background:#f8faf8;border:1px solid #e2efe8;border-radius:10px;
                                padding:12px 14px;margin-bottom:16px;display:flex;
                                gap:14px;align-items:flex-start;">
                        <i class="fas fa-folder-open" style="color:#2E7D32;font-size:1.1rem;margin-top:2px;"></i>
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:0.75rem;color:#7a8a82;
                                        text-transform:uppercase;letter-spacing:0.4px;">
                                Reference
                            </div>
                            <div style="font-weight:800;color:#1a2b22;font-size:0.92rem;">
                                ${escapeHtml(row.reference_number || '')}
                            </div>
                            <div style="font-size:0.82rem;color:#555;margin-top:3px;
                                        line-height:1.4;">
                                ${escapeHtml(row.title || '')}
                            </div>
                        </div>
                    </div>
                    <label style="display:block;font-weight:700;font-size:0.85rem;
                                  color:#1a472a;margin-bottom:6px;">
                        Reason category <span style="color:#c62828;">*</span>
                    </label>
                    <select id="cdReasonCategory"
                            style="width:100%;padding:11px 12px;border:1.5px solid #cfd8dc;
                                   border-radius:8px;font-size:0.92rem;font-family:inherit;
                                   background:#fff;cursor:pointer;box-sizing:border-box;"
                            onchange="cdOnCategoryChange()">
                        <option value="">— Select a reason —</option>
                        ${optionsHtml}
                    </select>
                    <div id="cdCategoryHint"
                         style="font-size:0.75rem;color:#666;margin-top:6px;
                                min-height:16px;line-height:1.4;"></div>
                    <label style="display:block;font-weight:700;font-size:0.85rem;
                                  color:#1a472a;margin-top:16px;margin-bottom:6px;">
                        Explanation / Notes
                        <span style="color:#c62828;">*</span>
                    </label>
                    <textarea id="cdReasonText"
                              rows="4"
                              placeholder="Explain briefly why this complaint is being dismissed. This will be saved to the case file and sent to the complainant."
                              style="width:100%;padding:11px 12px;border:1.5px solid #cfd8dc;
                                     border-radius:8px;font-size:0.9rem;font-family:inherit;
                                     resize:vertical;box-sizing:border-box;
                                     line-height:1.5;">${escapeHtml(suggestedText)}</textarea>
                    <div style="display:flex;gap:10px;align-items:flex-start;
                                background:#e3f2fd;border-left:4px solid #1565c0;
                                border-radius:8px;padding:10px 12px;margin-top:14px;">
                        <i class="fas fa-info-circle" style="color:#1565c0;margin-top:2px;"></i>
                        <div style="font-size:0.78rem;color:#0d47a1;line-height:1.5;">
                            The complainant will receive a notification with this reason.
                            This action cannot be undone.
                        </div>
                    </div>
                </div>
            </div>`,
        width: 640,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-ban"></i> Confirm Dismissal',
        confirmButtonColor: '#d33',
        cancelButtonText: '<i class="fas fa-times"></i> Cancel',
        cancelButtonColor: '#6c757d',
        allowOutsideClick: false,
        customClass: { popup: 'sm-popup' },
        didOpen: () => {
            if (window.cdOnCategoryChange) window.cdOnCategoryChange();
        },
        preConfirm: () => {
            const catEl  = document.getElementById('cdReasonCategory');
            const textEl = document.getElementById('cdReasonText');
            const cat    = catEl  ? catEl.value.trim()  : '';
            const text   = textEl ? textEl.value.trim() : '';

            if (!cat) { Swal.showValidationMessage('Please select a reason category.'); return false; }
            if (!text) { Swal.showValidationMessage('Please write an explanation.'); return false; }
            return { category: cat, reason: text };
        }
    });

    if (!isConfirmed || !value) return;

    const fd = new FormData();
    if (isEscalated) fd.append('action', 'mark_prank_invalid');
    else             fd.append('action', 'dismiss_complaint');

    fd.append('complaint_id',    String(row.id));
    fd.append('reason',          value.reason);
    fd.append('reason_category', value.category);

    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) {
            showSuccessModal(isEscalated ? 'Marked as PRANK / INVALID' : 'Complaint dismissed.');
            loadComplaints(currentComplaintFilter);
        } else {
            showErrorModal(d.message || 'Failed to dismiss.');
        }
    } catch (e) {
        showErrorModal('Network error.');
    }
}

function cdOnCategoryChange() {
    const catEl  = document.getElementById('cdReasonCategory');
    const textEl = document.getElementById('cdReasonText');
    const hintEl = document.getElementById('cdCategoryHint');
    if (!catEl || !hintEl) return;

    const catKey = catEl.value;
    const cat = DISMISS_CATEGORIES.find(c => c.key === catKey);
    if (!cat) { hintEl.textContent = ''; return; }

    hintEl.innerHTML = `<i class="fas ${cat.icon}" style="color:#2E7D32;margin-right:6px;"></i>${escapeHtml(cat.hint)}`;

    if (textEl) {
        const current = textEl.value.trim();
        const previousAuto = DISMISS_CATEGORIES
            .map(c => c.autoText)
            .filter(t => t && t.length);
        const looksAuto = !current || previousAuto.includes(current);
        if (looksAuto && cat.autoText) {
            textEl.value = cat.autoText;
        }
    }
}

async function cdSubmitNote(id) {
    const input = document.getElementById('cdNoteInput');
    if (!input || !input.value.trim()) { showWarningModal('Please enter a note.'); return; }
    const fd = new FormData();
    fd.append('action', 'add_complaint_note');
    fd.append('complaint_id', id);
    fd.append('note', input.value.trim());
    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) {
            input.value = '';
            showSuccessModal('Note added');
            viewComplaintDetail(id);
        } else showErrorModal(d.message || 'Failed');
    } catch (e) { showErrorModal('Network error'); }
}

// ============ NAVIGATION ============
function navigateTo(view) {
    document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active'));
    const item = document.querySelector(`.nav-item[data-view="${view}"]`);
    if (item) item.classList.add('active');

    switch(view) {
        case 'dashboard':
            document.getElementById('dashboardBody').innerHTML = renderDashboard();
            loadRecentRequests();
            displayCurrentDate();
            loadAnnouncements();
            initDashboardWidgets();
            break;
        case 'complaints':
            document.getElementById('dashboardBody').innerHTML = '';
            renderComplaintsView();
            break;
        case 'document_requests':
            document.getElementById('dashboardBody').innerHTML = renderDocumentRequestsView();
            loadFilteredRequests('approved', 1);
            break;
        case 'manage_documents':
            document.getElementById('dashboardBody').innerHTML = renderManageDocumentsView();
            break;
        case 'household':
            document.getElementById('dashboardBody').innerHTML = '';
            if (typeof window.__renderHouseholdDashboard === 'function') {
                window.__renderHouseholdDashboard();
            }
            break;
        case 'reports':
            document.getElementById('dashboardBody').innerHTML = renderReportsView();
            break;
    }
}

async function loadRecentRequests() {
    try {
        const r = await fetch(`?ajax_action=filter_requests&status=all&page=1`);
        const d = await r.json();
        const c = document.getElementById('recentRequestsList');
        if (c && d.success && d.data.length > 0) {
            c.innerHTML = d.data.slice(0, 5).map(req => `
                <div class="activity-item" onclick="viewRequestDetails(${req.id})">
                    <div class="activity-info">
                        <div class="activity-title">${escapeHtml(req.document_type)}</div>
                        <div class="activity-detail">${escapeHtml(req.first_name)} ${escapeHtml(req.last_name)} · ${formatDate(req.request_date)}</div>
                    </div>
                    <div class="activity-status status-${req.status}">${req.status}</div>
                </div>`).join('');
        } else if (c) c.innerHTML = '<div class="empty-state-mini"><p>No recent requests</p></div>';
    } catch (e) {}
}


// ═══════════════════════════════════════════════════════════
// DASHBOARD WIDGETS — charts + calendar
// ═══════════════════════════════════════════════════════════
let __dxCharts = {};
let __dxTrendTab = 'trend';
let __dxStatusTab = 'docs';
const __dxToday = new Date();
let __dxCal = { y: __dxToday.getFullYear(), m: __dxToday.getMonth(), sel: null };

const DX_DOC_STATUS = [
    { key: 'pending',   label: 'Pending',   color: '#f57c00' },
    { key: 'approved',  label: 'Approved',  color: '#43a047' },
    { key: 'unclaimed', label: 'Unclaimed', color: '#ffca28' },
    { key: 'claimed',   label: 'Claimed',   color: '#1976d2' },
    { key: 'rejected',  label: 'Rejected',  color: '#e53935' },
];

function dxPad(n) { return String(n).padStart(2, '0'); }
function dxYmd(y, m, d) { return `${y}-${dxPad(m + 1)}-${dxPad(d)}`; }
function dxDestroy(key) { if (__dxCharts[key]) { try { __dxCharts[key].destroy(); } catch (e) {} delete __dxCharts[key]; } }

function initDashboardWidgets() {
    Object.keys(__dxCharts).forEach(dxDestroy);
    __dxTrendTab = 'trend';
    __dxStatusTab = 'docs';
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

// ── Trend / Top documents ──
function dxRenderTrend() {
    const el = document.getElementById('dxTrendCanvas');
    if (!el || typeof Chart === 'undefined') return;
    dxDestroy('trend');

    const title = document.getElementById('dxTrendTitle');
    const deltaEl = document.getElementById('dxDelta');
    const docs = (DX.docMonthly || []), comps = (DX.complaintMonthly || []);

    if (__dxTrendTab === 'trend') {
        if (title) title.textContent = '6-Month Trend';
        if (deltaEl) {
            const cur = docs[docs.length - 1] || 0, prev = docs[docs.length - 2] || 0;
            let cls = 'flat', txt = 'No change vs last month', ic = 'fa-minus';
            if (prev > 0) {
                const pct = Math.round(((cur - prev) / prev) * 100);
                if (pct > 0)      { cls = '';     ic = 'fa-arrow-up';   txt = `${pct}% vs last month`; }
                else if (pct < 0) { cls = 'down'; ic = 'fa-arrow-down'; txt = `${Math.abs(pct)}% vs last month`; }
            } else if (cur > 0) { cls = ''; ic = 'fa-arrow-up'; txt = `${cur} new this month`; }
            deltaEl.innerHTML = `<span class="dx-delta ${cls}"><i class="fas ${ic}"></i> Requests ${txt}</span>`;
        }

        const grad = (hex) => (ctx) => {
            const { chart } = ctx; const a = chart.chartArea;
            if (!a) return hex + '33';
            const g = chart.ctx.createLinearGradient(0, a.top, 0, a.bottom);
            g.addColorStop(0, hex + '55'); g.addColorStop(1, hex + '05');
            return g;
        };
        __dxCharts.trend = new Chart(el, {
            type: 'line',
            data: {
                labels: DX.months || [],
                datasets: [
                    { label: 'Document requests', data: docs, borderColor: '#2E7D32', backgroundColor: grad('#2E7D32'),
                      fill: true, tension: 0.4, borderWidth: 3, pointRadius: 4, pointBackgroundColor: '#fff', pointBorderWidth: 2, pointHoverRadius: 6 },
                    { label: 'Complaints', data: comps, borderColor: '#8e24aa', backgroundColor: grad('#8e24aa'),
                      fill: true, tension: 0.4, borderWidth: 3, pointRadius: 4, pointBackgroundColor: '#fff', pointBorderWidth: 2, pointHoverRadius: 6 },
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, font: { size: 11 } } },
                    tooltip: { backgroundColor: '#1a2b22', padding: 10, cornerRadius: 8 }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { precision: 0, maxTicksLimit: 5 }, grid: { color: '#eef2f0' }, border: { display: false } }
                }
            }
        });
    } else {
        if (title) title.textContent = 'Most Requested Documents';
        if (deltaEl) deltaEl.innerHTML = '';
        const top = DX.topDocs || [];
        const palette = ['#1b5e20', '#2E7D32', '#43a047', '#66bb6a', '#81c784', '#a5d6a7'];
        __dxCharts.trend = new Chart(el, {
            type: 'bar',
            data: {
                labels: top.map(t => t.name),
                datasets: [{ label: 'Requests', data: top.map(t => t.count),
                             backgroundColor: top.map((_, i) => palette[i % palette.length]),
                             borderRadius: 8, borderSkipped: false, barThickness: 'flex', maxBarThickness: 26 }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { backgroundColor: '#1a2b22', padding: 10, cornerRadius: 8 } },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0, maxTicksLimit: 5 }, grid: { color: '#eef2f0' }, border: { display: false } },
                    y: { grid: { display: false } }
                }
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
    } else {
        const counts = {};
        Object.entries(DX.complaintStatus || {}).forEach(([st, n]) => {
            const k = (typeof stageForStatus === 'function') ? stageForStatus(st) : 'intake';
            counts[k] = (counts[k] || 0) + n;
        });
        Object.entries(COMPLAINT_STAGES).forEach(([k, st]) => {
            if (k === 'all' || !counts[k]) return;
            labels.push(st.label); data.push(counts[k]); colors.push(st.color);
        });
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
        const kinds = [...new Set(evs.map(e => e.kind))];
        const dots = kinds.map(k => `<i class="dx-dot ${k}"></i>`).join('');
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

function dxEventHtml(e, showDate) {
    const click = e.kind === 'hearing' ? `viewComplaintDetail(${e.id})` : `viewRequestDetails(${e.id})`;
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

function displayCurrentDate() {
    const el = document.getElementById('currentDate');
    if (el) el.innerHTML = new Date().toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
}

// ============ INIT ============
document.querySelectorAll('.nav-item').forEach(item => {
    item.addEventListener('click', () => navigateTo(item.getAttribute('data-view')));
});

const profileArea = document.getElementById('profileArea');
const profileDropdown = document.getElementById('profileDropdown');
const logoutBtn = document.getElementById('logoutBtn');

if (profileArea && profileDropdown) {
    profileArea.addEventListener('click', (e) => { e.stopPropagation(); profileDropdown.classList.toggle('show'); });
    document.addEventListener('click', (e) => { if (!profileArea.contains(e.target)) profileDropdown.classList.remove('show'); });
}
if (logoutBtn) {
    logoutBtn.addEventListener('click', (e) => {
        e.preventDefault();
        if (confirm('Logout?')) document.getElementById('logoutForm').submit();
    });
}

const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
if (menuToggle) menuToggle.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('hide'); });
if (overlay) overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.add('hide'); });

// Print Queue badge on sidebar
(function() {
    const badge = document.getElementById('complaintsPrintBadge');
    if (badge && initialPrintQueueCount > 0) {
        badge.style.display = 'inline-block';
        badge.textContent = initialPrintQueueCount;
    }
})();

navigateTo('dashboard');

// ============ NOTIFICATION BELL + WEB PUSH ============
const VAPID_PUBLIC_KEY = 'BBZsq_4dt_q371blgDoM-5GnfyxZi6jSvngIRw_h8tYzNr0AxO5tscRJ8WCj8kBxJNMGouU1U-ON9EF5MLy5NCg';
const SW_URL = '../sw.js';

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    const arr = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; ++i) arr[i] = raw.charCodeAt(i);
    return arr;
}

let swRegistration = null;
let pushSubscription = null;
let lastNotifIds = new Set();

async function initNotifications() {
    const bell = document.getElementById('notifBell');
    const dropdown = document.getElementById('notifDropdown');
    const markRead = document.getElementById('notifMarkRead');
    const pushToggle = document.getElementById('notifPushToggle');
    if (!bell) return;
    bell.addEventListener('click', (e) => {
        e.stopPropagation();
        dropdown.classList.toggle('show');
        if (dropdown.classList.contains('show')) fetchNotifications();
    });
    document.addEventListener('click', (e) => {
        const wrap = document.getElementById('notifBellWrap');
        if (wrap && !wrap.contains(e.target)) dropdown.classList.remove('show');
    });
    markRead.addEventListener('click', async (e) => {
        e.stopPropagation();
        await fetch('../admin_complaint_ajax.php', { method: 'POST', body: new URLSearchParams({ action: 'mark_notifications_read' }) });
        fetchNotifications();
    });
    if ('serviceWorker' in navigator && 'PushManager' in window) {
        try {
            swRegistration = await navigator.serviceWorker.register(SW_URL, { scope: '../' });
            pushSubscription = await swRegistration.pushManager.getSubscription();
            if (pushSubscription && pushToggle) pushToggle.checked = true;
        } catch (err) {}
    } else if (pushToggle) pushToggle.disabled = true;
    if (pushToggle) {
        pushToggle.addEventListener('change', async () => {
            if (pushToggle.checked) { const ok = await enablePush(); if (!ok) pushToggle.checked = false; }
            else await disablePush();
        });
    }
    fetchNotifications();
    setInterval(fetchNotifications, 5000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) fetchNotifications(); });
    window.addEventListener('focus', fetchNotifications);
}

async function fetchNotifications() {
    try {
        const r = await fetch('../admin_complaint_ajax.php?action=get_my_notifications');
        const d = await r.json();
        if (!d.success) return;
        const badge = document.getElementById('notifBadge');
        const list  = document.getElementById('notifList');
        const bell  = document.getElementById('notifBell');
        if (d.unread_count > 0) {
            badge.style.display = 'flex';
            badge.textContent = d.unread_count > 99 ? '99+' : d.unread_count;
            bell.classList.add('has-new');
            setTimeout(() => bell.classList.remove('has-new'), 1000);
        } else badge.style.display = 'none';
        if (!d.notifications.length) {
            list.innerHTML = '<div class="notif-empty"><i class="fas fa-inbox fa-2x"></i><br>No notifications yet.</div>';
        } else {
            const iconMap = { assignment:'fa-user-check', hearing:'fa-calendar-alt', info:'fa-info-circle', warning:'fa-exclamation-triangle', success:'fa-check-circle', error:'fa-times-circle' };

            list.innerHTML = d.notifications.map(n => {
                const isActionRequired = (n.title || '').toUpperCase().indexOf('[ACTION REQUIRED]') === 0;

                const hasDoc = n.document_path && String(n.document_path).trim() !== '';
                const pdfUrl = hasDoc
                    ? (String(n.document_path).startsWith('http')
                        ? n.document_path
                        : '../../' + String(n.document_path).replace(/^\/+/, ''))
                    : '';

                const inner = `
                    <div class="notif-icon ${n.type}"><i class="fas ${iconMap[n.type]||'fa-bell'}"></i></div>
                    <div class="notif-body">
                        <div class="notif-title">${isActionRequired ? '⚠ ' : ''}${escapeHtml(n.title)}</div>
                        <div class="notif-msg">${escapeHtml(n.message)}</div>
                        <div class="notif-time">
                            ${timeAgo(n.created_at)}
                            ${hasDoc ? '<span class="notif-doc-chip"><i class="fas fa-external-link-alt"></i> Open PDF</span>' : ''}
                        </div>
                    </div>`;

                if (hasDoc) {
                    return `
                    <a class="notif-item has-doc ${n.is_read == 0 ? 'unread' : ''} ${isActionRequired ? 'action-required' : ''}"
                       href="${escapeHtml(pdfUrl)}"
                       target="_blank"
                       rel="noopener noreferrer"
                       title="Open the attached KP form in a new tab"
                       onclick="markNotifRead(${n.id})">
                        ${inner}
                    </a>`;
                }

                return `
                <div class="notif-item ${n.is_read == 0 ? 'unread' : ''} ${isActionRequired ? 'action-required' : ''}"
                     onclick="onNotifClick(${n.id}, ${n.reference_id || 'null'}, '${escapeHtml(n.reference_type || '')}')">
                    ${inner}
                </div>`;
            }).join('');
        }
        lastNotifIds = new Set(d.notifications.map(n => n.id));
    } catch (e) {}
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

function markNotifRead(id) {
    document.getElementById('notifDropdown').classList.remove('show');
    fetch('../admin_complaint_ajax.php', {
        method: 'POST',
        body: new URLSearchParams({ action: 'mark_notification_read', notification_id: id })
    }).then(() => setTimeout(fetchNotifications, 500)).catch(() => {});
}

function onNotifClick(id, refId, refType) {
    document.getElementById('notifDropdown').classList.remove('show');
    fetch('../admin_complaint_ajax.php', {
        method: 'POST',
        body: new URLSearchParams({ action: 'mark_notification_read', notification_id: id })
    }).catch(() => {});

    if (refType === 'complaint' && refId) {
        navigateTo('complaints');
        setTimeout(() => { if (typeof viewComplaintDetail === 'function') viewComplaintDetail(refId); }, 300);
    } else if (refType === 'document_request' && refId) {
        navigateTo('document_requests');
        setTimeout(() => {
            if (typeof viewRequestDetails === 'function') viewRequestDetails(refId);
        }, 400);
    } else {
        setTimeout(fetchNotifications, 300);
    }
}

async function enablePush() {
    if (!swRegistration) { showErrorModal('Push not supported.'); return false; }
    try {
        const perm = await Notification.requestPermission();
        if (perm !== 'granted') { showWarningModal('Please allow notifications in browser settings.'); return false; }
        const sub = await swRegistration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY) });
        const r = await fetch('../push_subscribe.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(sub.toJSON())
        });
        const d = await r.json();
        if (!d.success) { showErrorModal('Save failed: ' + d.message); return false; }
        showSuccessModal('Desktop push notifications enabled!');
        return true;
    } catch (err) { showErrorModal('Push enable failed: ' + err.message); return false; }
}
async function disablePush() {
    try {
        if (pushSubscription) {
            await fetch('../push_unsubscribe.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ endpoint: pushSubscription.endpoint })
            });
            await pushSubscription.unsubscribe();
            pushSubscription = null;
            showSuccessModal('Desktop push notifications disabled.');
        }
    } catch (err) {}
}
document.addEventListener('DOMContentLoaded', initNotifications);
</script>

<!-- QR SCANNER -->
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script>
(function pureQRScanner() {
    const btn     = document.getElementById('qrScanBtn');
    const overlay = document.getElementById('qrCameraOverlay');
    const video   = document.getElementById('qrHiddenVideo');
    const canvas  = document.getElementById('qrHiddenCanvas');
    const pill    = document.getElementById('qrScanPill');
    const pillTxt = document.getElementById('qrScanPillText');
    const closeBtn= document.getElementById('qrOverlayClose');
    if (!btn || !overlay || !video) return;

    let stream = null, rafId = null, active = false, processing = false, lastText = '', lastAt = 0;

    function showPill(msg, color) {
        pill.style.display = 'flex';
        pill.style.background = color || '#1b5e20';
        pillTxt.textContent = msg;
    }
    function hidePill() { pill.style.display = 'none'; }

    async function getCameraPermissionState() {
        try {
            if (navigator.permissions && navigator.permissions.query) {
                const status = await navigator.permissions.query({ name: 'camera' });
                return status.state;
            }
        } catch (e) {}
        return 'unknown';
    }
    async function requestCameraPermission() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            throw new Error('NO_API: getUserMedia not supported in this browser.');
        }
        return await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
    }
    async function getCameraStream() {
        const isSecure = window.isSecureContext || location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
        if (!isSecure) throw new Error('INSECURE: Camera requires HTTPS.');
        let firstStream = await requestCameraPermission();
        const refinements = [
            { video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false },
            { video: { facingMode: { ideal: 'environment' } }, audio: false }
        ];
        for (const constraints of refinements) {
            try {
                const better = await navigator.mediaDevices.getUserMedia(constraints);
                firstStream.getTracks().forEach(t => t.stop());
                return better;
            } catch (e) {}
        }
        return firstStream;
    }

    async function start() {
        const state = await getCameraPermissionState();
        if (state === 'denied') {
            showPill('❌ Camera blocked. Tap 🔒 → Site settings → Camera → Allow, then reload.', '#c62828');
            setTimeout(hidePill, 8000);
            return;
        }
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
        showPill(state === 'prompt' || state === 'unknown' ? 'Please allow camera access…' : 'Opening camera…', '#1565c0');
        try {
            stream = await getCameraStream();
        } catch (err) {
            let msg = 'Could not access camera.';
            if (err && err.name === 'NotAllowedError') msg = '❌ Camera permission denied.';
            else if (err && err.name === 'NotFoundError') msg = '❌ No camera found.';
            else if (err && err.name === 'NotReadableError') msg = '❌ Camera in use by another app.';
            else if (err && err.message) msg = err.message;
            showPill(msg, '#c62828');
            setTimeout(() => { hidePill(); overlay.classList.remove('active'); document.body.style.overflow = ''; }, 8000);
            active = false;
            return;
        }
        try {
            video.srcObject = stream;
            video.setAttribute('playsinline', 'true');
            video.setAttribute('webkit-playsinline', 'true');
            video.muted = true;
            await new Promise((resolve) => {
                if (video.readyState >= 2) return resolve();
                video.onloadedmetadata = () => resolve();
                setTimeout(resolve, 1500);
            });
            try { await video.play(); } catch (e) {}
            active = true;
            processing = false;
            lastText = '';
            btn.style.background = '#e8f5e9';
            btn.style.color = '#1b5e20';
            btn.innerHTML = '<i class="fas fa-stop"></i>';
            showPill('📷 Camera active — scanning…', '#1b5e20');
            setTimeout(() => { if (active && !processing) showPill('Scanning…', '#1b5e20'); }, 900);
            loop();
        } catch (err) {
            showPill('Failed to start video: ' + (err.message || err), '#c62828');
            stop();
        }
    }

    function stop() {
        active = false;
        if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
        if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
        video.srcObject = null;
        overlay.classList.remove('active');
        document.body.style.overflow = '';
        btn.style.background = '';
        btn.style.color = '';
        btn.innerHTML = '<i class="fas fa-camera"></i>';
        hidePill();
    }

    function loop() {
        if (!active) return;
        if (video.readyState === video.HAVE_ENOUGH_DATA && !processing) {
            const MAX_W = 640;
            const vw = video.videoWidth || 640;
            const vh = video.videoHeight || 480;
            const scale = Math.min(1, MAX_W / vw);
            canvas.width  = Math.round(vw * scale);
            canvas.height = Math.round(vh * scale);
            const ctx = canvas.getContext('2d', { willReadFrequently: true });
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
            if (window.jsQR) {
                const code = window.jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
                if (code && code.data) {
                    const now = Date.now();
                    if (!(code.data === lastText && (now - lastAt) < 3000)) {
                        lastText = code.data;
                        lastAt   = now;
                        handle(code.data);
                    }
                }
            }
        }
        rafId = requestAnimationFrame(loop);
    }

    async function handle(qrData) {
        processing = true;
        showPill('Verifying…', '#1565c0');
        let res, rawText, data;
        try {
            res = await fetch('/BRITE/admin/qr_scan_verify.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ qr_data: qrData })
            });
            rawText = await res.text();
            try { data = JSON.parse(rawText); }
            catch (parseErr) {
                let hint = 'Server returned invalid response.';
                if (res.status === 404) hint = 'Server returned 404 — qr_scan_verify.php not found.';
                else if (rawText.trim().startsWith('<!DOCTYPE')) hint = 'Server returned HTML instead of JSON.';
                showPill('❌ ' + hint, '#c62828');
                setTimeout(() => { if (active) showPill('Scanning…', '#1b5e20'); processing = false; }, 5000);
                return;
            }
            if (data.success) {
                const already = data.already_claimed === true;
                showPill(already ? '⚠️ Already claimed' : '✅ Verified — claimed', already ? '#ef6c00' : '#1b5e20');
                if (data.print_url) {
                    const w = window.open(data.print_url, '_blank');
                    if (!w) window.location.href = data.print_url;
                }
                if (typeof loadFilteredRequests === 'function' && document.getElementById('requestsContainer')) {
                    loadFilteredRequests(currentFilterStatus, currentPage);
                }
                if (typeof fetchNotifications === 'function') fetchNotifications();
                setTimeout(stop, 1800);
            } else {
                showPill(data.message || 'Invalid QR', '#c62828');
                setTimeout(() => { if (active) showPill('Scanning…', '#1b5e20'); processing = false; }, 1800);
            }
        } catch (err) {
            showPill('❌ Network error — is XAMPP running?', '#c62828');
            setTimeout(() => { if (active) showPill('Scanning…', '#1b5e20'); processing = false; }, 2200);
        }
        if (processing) setTimeout(() => { processing = false; }, 1500);
    }

    btn.addEventListener('click', () => { if (active) stop(); else start(); });
    if (closeBtn) closeBtn.addEventListener('click', stop);
    overlay.addEventListener('click', (e) => { if (e.target === overlay || e.target === video) stop(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && active) stop(); });
    window.addEventListener('orientationchange', () => {
        setTimeout(() => { if (active && video) { video.style.height = '100vh'; video.style.height = '100dvh'; } }, 300);
    });
    document.addEventListener('visibilitychange', () => { if (document.hidden && active) stop(); });
    window.addEventListener('popstate', () => { if (active) stop(); });
    window.addEventListener('beforeunload', stop);
})();
</script>
</body>
</html>