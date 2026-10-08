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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>Secretary Dashboard | Document Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        .notif-item { padding: 12px 16px; border-bottom: 1px solid #f0f0f0; display: flex; gap: 10px; cursor: pointer; }
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
            <div class="nav-item" data-view="verify_residents"><i class="fas fa-user-check"></i><span>Verify Residents</span></div>
            <div class="nav-item" data-view="resident_list"><i class="fas fa-users"></i><span>Residents</span></div>
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

<script>
// ============ GLOBAL ============
let currentFilterStatus = 'all';
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

// ============ DASHBOARD VIEW ============
function renderDashboard() {
    return `
        <div class="modern-dashboard">
            <div class="welcome-card">
                <div class="welcome-content">
                    <div class="welcome-text">
                        <h2>Welcome, Secretary <?php echo htmlspecialchars($admin['full_name']); ?>!</h2>
                        <p>Manage document requests, complaints, and process resident applications.</p>
                    </div>
                    <div class="welcome-date"><i class="fas fa-calendar-alt"></i><span id="currentDate"></span></div>
                </div>
            </div>
            <div class="stats-grid-modern">
                <div class="stat-card-modern"><div class="stat-icon pending"><i class="fas fa-clock"></i></div><div class="stat-info"><h3><?php echo $stats['pending_requests']; ?></h3><p>Pending Requests</p></div></div>
                <div class="stat-card-modern"><div class="stat-icon approved"><i class="fas fa-check-circle"></i></div><div class="stat-info"><h3><?php echo $stats['approved_requests']; ?></h3><p>Approved</p></div></div>
                <div class="stat-card-modern"><div class="stat-icon completed"><i class="fas fa-check-double"></i></div><div class="stat-info"><h3><?php echo $stats['claimed_requests']; ?></h3><p>Claimed</p></div></div>
                <div class="stat-card-modern"><div class="stat-icon today"><i class="fas fa-calendar-day"></i></div><div class="stat-info"><h3><?php echo $stats['today_requests']; ?></h3><p>Today's Requests</p></div></div>
                <div class="stat-card-modern"><div class="stat-icon verified"><i class="fas fa-user-clock"></i></div><div class="stat-info"><h3><?php echo $stats['pending_verifications']; ?></h3><p>Pending Verifications</p></div></div>
                <div class="stat-card-modern"><div class="stat-icon pending" style="background:#fff3e0;color:#e65100;"><i class="fas fa-clipboard-check"></i></div><div class="stat-info"><h3><?php echo $stats['pending_review']; ?></h3><p>Complaints to Review</p></div></div>
                <div class="stat-card-modern"><div class="stat-icon escalated"><i class="fas fa-exclamation-triangle"></i></div><div class="stat-info"><h3><?php echo $stats['escalated_complaints']; ?></h3><p>Escalated Complaints</p></div></div>
                <div class="stat-card-modern"><div class="stat-icon print-queue"><i class="fas fa-print"></i></div><div class="stat-info"><h3><?php echo $stats['print_queue']; ?></h3><p>Notices to Print</p></div></div>
            </div>
            <div class="quick-actions">
                <h4><i class="fas fa-bolt"></i> Quick Actions</h4>
                <div class="action-buttons">
                    <button class="quick-action-btn" onclick="navigateTo('complaints')"><i class="fas fa-gavel"></i> Review Complaints</button>
                    <button class="quick-action-btn" onclick="navigateTo('document_requests')"><i class="fas fa-clipboard-list"></i> Process Requests</button>
                    <button class="quick-action-btn" onclick="navigateTo('manage_documents')"><i class="fas fa-cog"></i> Manage Documents</button>
                    <button class="quick-action-btn" onclick="generateReport()"><i class="fas fa-print"></i> Generate Report</button>
                </div>
            </div>
            <div class="recent-activity-grid">
                <div class="activity-card">
                    <div class="activity-header">
                        <h4><i class="fas fa-file-alt"></i> Recent Document Requests</h4>
                        <button class="view-all-btn" onclick="navigateTo('document_requests')">View All <i class="fas fa-arrow-right"></i></button>
                    </div>
                    <div class="activity-list" id="recentRequestsList">
                        <div class="loading-spinner-mini"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                    </div>
                </div>
                <div class="activity-card">
                    <div class="activity-header"><h4><i class="fas fa-chart-pie"></i> Document Statistics</h4></div>
                    <div class="stat-values" style="padding: 20px;">
                        <div class="value-item"><span class="value-label">Pending</span><span class="value-number warning"><?php echo $stats['pending_requests']; ?></span></div>
                        <div class="value-item"><span class="value-label">Approved</span><span class="value-number success"><?php echo $stats['approved_requests']; ?></span></div>
                        <div class="value-item"><span class="value-label">Unclaimed</span><span class="value-number info"><?php echo $stats['unclaimed_requests']; ?></span></div>
                        <div class="value-item"><span class="value-label">Claimed</span><span class="value-number success"><?php echo $stats['claimed_requests']; ?></span></div>
                        <div class="value-item"><span class="value-label">Rejected</span><span class="value-number danger"><?php echo $stats['rejected_requests']; ?></span></div>
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
let currentComplaintFilter = 'pending_review';
let complaintData = [];

function renderComplaintsView() {
    document.getElementById('dashboardBody').innerHTML = `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-gavel"></i> Complaint Management</div>
            <div class="section-sub">Review complaints, validate escalations, and process resolutions.</div>
            <div class="filter-bar" id="complaintFilterBar">
                <button class="filter-chip" onclick="loadComplaints('all')">All</button>
                <button class="filter-chip" onclick="loadComplaints('pending_review')" style="background:#fff3e0;color:#e65100;border-color:#ffcc80;">
                    ⏳ Pending Review <span class="pending-count" id="pendingCount" style="background:#e65100;"></span>
                </button>
                <button class="filter-chip" onclick="loadComplaints('pangkat_constituted')" style="background:#e8f5e9;color:#2e7d32;border-color:#a5d6a7;">
                    ⚖️ Pangkat Constituted <span class="pending-count" id="pangkatConstitutedCount" style="background:#2e7d32;"></span>
                </button>
                <button class="filter-chip" onclick="loadComplaints('pangkat_scheduled')">Pangkat Scheduled</button>
                <button class="filter-chip" onclick="loadComplaints('pending_captain_action')">Forwarded to Captain</button>
                <button class="filter-chip" onclick="loadComplaints('for_mediation')">For Mediation</button>
                <button class="filter-chip" onclick="loadComplaints('mediation_scheduled')">Conciliation</button>
                <button class="filter-chip" onclick="loadComplaints('failed_mediation')">Mediation Failed</button>
                <button class="filter-chip" onclick="loadComplaints('failed_conciliation_final')">Conciliation Failed</button>
                <button class="filter-chip" onclick="loadComplaints('settled')">Settled</button>
                <button class="filter-chip" onclick="loadComplaints('escalated')" style="background:#ffebee;color:#c62828;border-color:#ef9a9a;">
                    🚨 Escalated <span class="pending-count" id="escalatedCount" style="background:#c62828;"></span>
                </button>
                <button class="filter-chip" onclick="loadComplaints('pending_captain_review')" style="background:#fff8e1;color:#f57c00;border-color:#ffcc80;">
                    ⏳ Pending Captain <span class="pending-count" id="pendingCaptainCount" style="background:#f57c00;"></span>
                </button>
                <button class="filter-chip" onclick="loadComplaints('referred_dispatched')" style="background:#e8f5e9;color:#2e7d32;border-color:#a5d6a7;">
                    📬 For Release <span class="pending-count" id="forReleaseCount" style="background:#2e7d32;"></span>
                </button>
                <button class="filter-chip" onclick="loadComplaints('released')">Released</button>
                <button class="filter-chip" onclick="loadComplaints('certificate_issued')">Certificates Issued</button>
                <button class="filter-chip" onclick="loadComplaints('dismissed')">Dismissed</button>
            </div>
            <div id="complaintsContainer">
                <div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div>
            </div>
        </div>`;
    loadComplaints('pending_review');
}

async function loadComplaints(status = 'all') {
    currentComplaintFilter = status;
    const c = document.getElementById('complaintsContainer');
    if (!c) return;
    c.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div>';
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_all_complaints&status=${status}`);
        const d = await r.json();
        if (!d.success) { c.innerHTML = '<div class="empty-state"><p>Failed to load.</p></div>'; return; }
        complaintData = d.complaints;
        document.querySelectorAll('#complaintFilterBar .filter-chip').forEach(b => {
            b.classList.remove('active');
            if ((b.getAttribute('onclick') || '').includes(`'${status}'`)) b.classList.add('active');
        });
        const pc = document.getElementById('pendingCount');
        if (pc) pc.textContent = d.counts && d.counts.pending_review > 0 ? d.counts.pending_review : '';
        const ec = document.getElementById('escalatedCount');
        if (ec) ec.textContent = d.counts && d.counts.escalated > 0 ? d.counts.escalated : '';
        const pcc = document.getElementById('pendingCaptainCount');
        if (pcc) pcc.textContent = d.counts && d.counts.pending_captain_review > 0 ? d.counts.pending_captain_review : '';
        const frc = document.getElementById('forReleaseCount');
        if (frc) frc.textContent = d.counts && d.counts.referred_dispatched > 0 ? d.counts.referred_dispatched : '';
        const pcc2 = document.getElementById('pangkatConstitutedCount');
        if (pcc2) pcc2.textContent = d.counts && d.counts.pangkat_constituted > 0 ? d.counts.pangkat_constituted : '';
        renderComplaintsGrid(d.complaints);
    } catch (e) {
        console.error(e);
        c.innerHTML = '<div class="empty-state"><p>Error loading.</p></div>';
    }
}

function renderComplaintsGrid(list) {
    const c = document.getElementById('complaintsContainer');
    if (!c) return;
    if (!list.length) {
        c.innerHTML = '<div class="empty-state"><i class="fas fa-inbox fa-3x"></i><p>No complaints found.</p></div>';
        return;
    }

    const statusMeta = {
        pending_review:            { header: 'pending_review', icon: 'fa-clock',                label: 'Pending Review' },
        pending_captain_action:    { header: 'forwarded_captain', icon: 'fa-paper-plane',      label: 'Forwarded to Captain' },
        summoned:                  { header: 'summoned',       icon: 'fa-paper-plane',          label: 'Summoned' },
        for_mediation:             { header: 'mediation',      icon: 'fa-gavel',                label: 'For Mediation' },
        mediation_scheduled:       { header: 'lupon',          icon: 'fa-balance-scale',        label: 'Conciliation' },
        in_mediation:              { header: 'lupon',          icon: 'fa-handshake',            label: 'In Mediation' },
        settled:                   { header: 'settled',        icon: 'fa-check-circle',         label: 'Settled' },
        failed_mediation:          { header: 'failed',         icon: 'fa-times-circle',         label: 'Mediation Failed' },
        failed_conciliation_final: { header: 'failed_conciliation_final', icon: 'fa-file-certificate', label: 'Conciliation Failed' },
        escalated:                 { header: 'escalated',      icon: 'fa-exclamation-triangle', label: 'ESCALATED' },
        dismissed:                 { header: 'dismissed',      icon: 'fa-ban',                  label: 'Dismissed' },
        referred:                  { header: 'escalated',      icon: 'fa-hourglass-half',       label: 'Referred' },
        pending_captain_review:    { header: 'pending_captain', icon: 'fa-hourglass-half',      label: 'Pending Captain' },
        referred_dispatched:       { header: 'for_release',    icon: 'fa-inbox',                label: 'For Release' },
        released:                  { header: 'settled',        icon: 'fa-check-double',         label: 'Released' },
        certificate_issued:        { header: 'settled',        icon: 'fa-certificate',          label: 'Certificate Issued' },
        pangkat_constituted:       { header: 'pangkat_ready',  icon: 'fa-users-cog',            label: 'Pangkat Constituted' },
        pangkat_scheduled:         { header: 'lupon',          icon: 'fa-calendar-check',       label: 'Pangkat Scheduled' }
    };

    const prio = p => {
        const cl = { urgent:'#dc3545', high:'#fd7e14', medium:'#ffc107', low:'#28a745' };
        return `<span style="background:${cl[p] || '#6c757d'};color:#fff;padding:2px 8px;border-radius:10px;font-size:0.65rem;">${(p || 'medium').toUpperCase()}</span>`;
    };

    c.innerHTML = `<div class="documents-grid">${list.map(x => {
        const meta = statusMeta[x.status] || { header: 'pending_review', icon: 'fa-file-alt', label: x.status };

        const needsPrint = Number(x.print_queue_count || 0) > 0;

        return `
            <div class="document-card ${needsPrint ? 'print-queue' : ''}" onclick="viewComplaintDetail(${x.id})" style="cursor:pointer;">
                ${needsPrint ? `<div class="print-queue-badge"><i class="fas fa-print"></i> ${x.print_queue_count} to Print</div>` : ''}
                <div class="document-card-header ${meta.header}">
                    <div class="document-title">
                        <i class="fas ${x.status === 'escalated' ? 'fa-exclamation-triangle' : (x.status === 'pangkat_constituted' ? 'fa-users-cog' : (x.status === 'pending_captain_review' ? 'fa-hourglass-half' : (x.status === 'referred_dispatched' ? 'fa-inbox' : (x.status === 'pending_captain_action' ? 'fa-paper-plane' : 'fa-file-alt'))))}"></i>
                        <span>${escapeHtml(x.title)}</span>
                    </div>
                    <div class="document-status"><i class="fas ${meta.icon}"></i> ${meta.label}</div>
                </div>
                <div class="document-card-body">
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
                    <div class="document-info-row"><div class="document-info-label">Filed:</div><div class="document-info-value">${new Date(x.created_at).toLocaleDateString()}</div></div>
                </div>
                <div class="document-card-actions">
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
    }).join('')}</div>`;
}

/* ============================================================
   FORWARD NORMAL COMPLAINT TO CAPTAIN
   ============================================================ */
async function forwardToCaptain(id) {
    let complaint = null;
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${id}`);
        const d = await r.json();
        if (d.success) complaint = d.complaint;
    } catch (e) { showErrorModal('Could not load complaint.'); return; }
    if (!complaint) { showErrorModal('Complaint not found.'); return; }

    const result = await Swal.fire({
        title: 'Forward to Punong Barangay',
        html: `
            <div style="text-align:left;font-size:0.9rem;">
                <div style="padding:12px 14px;background:#e3f2fd;border-left:4px solid #1565c0;border-radius:8px;margin-bottom:14px;">
                    <div style="font-weight:700;color:#0d47a1;margin-bottom:4px;"><i class="fas fa-info-circle"></i> What happens next?</div>
                    <ul style="margin:6px 0 0 18px;padding:0;line-height:1.6;font-size:0.85rem;">
                        <li>The Captain will be notified</li>
                        <li>The Captain will set the mediation schedule</li>
                        <li><b>KP Form #8</b> (Notice of Hearing) will be issued to the complainant</li>
                        <li><b>KP Form #9</b> (Summons) will be issued to the respondent</li>
                    </ul>
                </div>
                <div style="background:#f8faf8;padding:12px;border-radius:8px;margin-bottom:14px;">
                    <div style="font-weight:700;color:#1a472a;">${escapeHtml(complaint.title)}</div>
                    <div style="font-size:0.8rem;color:#666;margin-top:4px;">Ref: <strong>${escapeHtml(complaint.reference_number)}</strong></div>
                </div>
                <label style="font-weight:600;font-size:0.85rem;color:#1a472a;display:block;margin-bottom:6px;">Review Notes (optional)</label>
                <textarea id="fwdNotes" class="swal2-textarea" rows="3" placeholder="e.g. Complete details, valid complaint."></textarea>
            </div>`,
        width: 600,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-paper-plane"></i> Forward to Captain',
        confirmButtonColor: '#1565c0',
        cancelButtonText: 'Cancel',
        cancelButtonColor: '#6c757d',
        preConfirm: () => ({ notes: document.getElementById('fwdNotes').value.trim() })
    });

    if (!result.isConfirmed) return;

    const fd = new FormData();
    fd.append('action', 'forward_to_captain');
    fd.append('complaint_id', id);
    fd.append('notes', result.value.notes);

    Swal.fire({ title: 'Forwarding…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        Swal.close();
        if (d.success) {
            await Swal.fire({
                icon: 'success',
                title: 'Forwarded to Captain',
                html: `<div style="text-align:left;font-size:0.9rem;">
                        <p>The complaint has been forwarded to <strong>${escapeHtml(d.captain_name || 'the Punong Barangay')}</strong>.</p>
                        <p style="margin-top:10px;font-size:0.85rem;color:#666;">The Captain will set the mediation schedule and issue:</p>
                        <ul style="margin:6px 0 0 18px;font-size:0.85rem;line-height:1.6;">
                            <li><b>KP Form #8</b> (Notice of Hearing) → Complainant</li>
                            <li><b>KP Form #9</b> (Summons) → Respondent</li>
                        </ul>
                    </div>`,
                confirmButtonColor: '#2E7D32'
            });
            loadComplaints(currentComplaintFilter);
        } else showErrorModal(d.message || 'Failed to forward.');
    } catch (e) { Swal.close(); showErrorModal('Network error.'); }
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
   OPEN A STORED PARTY PDF
   ============================================================ */
async function openPartyNoticePdf(partyId) {
    Swal.fire({ title: 'Loading PDF…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_party_notice_pdf&party_id=${partyId}`);
        const d = await r.json();
        Swal.close();
        if (d.success && d.pdf_url) {
            window.open('../../' + d.pdf_url, '_blank');
        } else {
            Swal.fire('Error', d.message || 'Could not open PDF.', 'error');
        }
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
        if (d.success && d.pdf_url) {
            window.open('../../' + d.pdf_url, '_blank');
        } else {
            Swal.fire('Error', d.message || 'Could not open PDF.', 'error');
        }
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
        if (d.success && d.pdf_url) {
            window.open('../../' + d.pdf_url, '_blank');
        } else {
            Swal.fire('Error', d.message || 'Could not open PDF.', 'error');
        }
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

    // KP Forms / Notices
    const kpRows = [];
    (d.complainants || []).forEach(p => {
        if (p.notice_pdf_path) {
            kpRows.push({
                party_id: p.id, type: 'kp8',
                label: 'KP Form #8 — Notice of Hearing (Mediation Proceedings)',
                recipient: p.full_name, role: 'Complainant',
                sent_at: p.notice_of_hearing_sent_at || null,
                notified: p.notified_via === 'push',
            });
        }
        if (p.kp10_pdf_path) {
            kpRows.push({
                party_id: p.id, type: 'kp10',
                label: 'KP Form #10 — Notice for Constitution of Pangkat',
                recipient: p.full_name, role: 'Complainant',
                sent_at: p.kp10_issued_at || null,
                notified: p.kp10_notified_via === 'push',
            });
        }
    });
    (d.respondents || []).forEach(p => {
        if (p.notice_pdf_path) {
            kpRows.push({
                party_id: p.id, type: 'kp9',
                label: 'KP Form #9 — Summons',
                recipient: p.full_name, role: 'Respondent',
                sent_at: p.summons_sent_at || null,
                notified: p.notified_via === 'push',
            });
        }
        if (p.kp10_pdf_path) {
            kpRows.push({
                party_id: p.id, type: 'kp10',
                label: 'KP Form #10 — Notice for Constitution of Pangkat',
                recipient: p.full_name, role: 'Respondent',
                sent_at: p.kp10_issued_at || null,
                notified: p.kp10_notified_via === 'push',
            });
        }
    });
    // KP #11 per Pangkat member
    (d.pangkat || []).forEach(m => {
        if (m.kp11_pdf_path) {
            kpRows.push({
                member_id: m.member_id, type: 'kp11',
                label: 'KP Form #11 — Notice to Chosen Pangkat Member',
                recipient: m.full_name, role: 'Pangkat Member',
                sent_at: m.kp11_issued_at || null,
                notified: true,
            });
        }
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
                const icon = isKp8 ? 'fa-file-alt'
                           : (r.type === 'kp9' ? 'fa-file-signature'
                           : (r.type === 'kp10' ? 'fa-users-cog' : 'fa-user-tag'));
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

    // Pangkat panel
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

    const headerClass = isEscalated ? 'cd-escalated' : (isInMediation ? 'cd-mediation' : ((isForwarded || isPangkatConstituted || isPangkatScheduled) ? 'cd-forwarded' : ''));
    const headerIcon = isEscalated ? 'fa-exclamation-triangle' : (isInMediation ? 'fa-gavel' : (isForwarded ? 'fa-paper-plane' : (isPangkatConstituted ? 'fa-users-cog' : (isPendingCaptain ? 'fa-hourglass-half' : (isForRelease ? 'fa-inbox' : 'fa-folder-open')))));

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
                    <button class="cd-btn forward" onclick="Swal.close(); forwardToCaptain(${c.id});"><i class="fas fa-paper-plane"></i> Forward to Captain</button>
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

async function cdDismiss(id) {
    let isEscalatedCase = false;
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${id}`);
        const d = await r.json();
        if (d.success) isEscalatedCase = d.complaint.status === 'escalated';
    } catch (e) { }

    const placeholder = isEscalatedCase
        ? 'e.g. Prank/false report; complainant unreachable; duplicate case.'
        : 'e.g. Complainant withdrew; parties settled outside the barangay.';

    const { value: reason, isConfirmed } = await Swal.fire({
        title: isEscalatedCase ? 'Mark as PRANK / INVALID' : 'Dismiss Complaint',
        input: 'textarea',
        inputLabel: 'Reason for dismissal / notes',
        inputPlaceholder: placeholder,
        showCancelButton: true,
        confirmButtonText: 'Confirm',
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        inputValidator: v => (!v || !v.trim()) ? 'Reason is required' : undefined
    });
    if (!isConfirmed) return;

    const fd = new FormData();
    if (isEscalatedCase) fd.append('action', 'mark_prank_invalid');
    else fd.append('action', 'dismiss_complaint');
    fd.append('complaint_id', id);
    fd.append('reason', reason.trim());

    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) {
            Swal.close();
            showSuccessModal(isEscalatedCase ? 'Marked as PRANK / INVALID' : 'Complaint dismissed');
            loadComplaints(currentComplaintFilter);
        } else showErrorModal(d.message || 'Failed');
    } catch (e) { showErrorModal('Network error'); }
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
            break;
        case 'complaints':
            document.getElementById('dashboardBody').innerHTML = '';
            renderComplaintsView();
            break;
        case 'document_requests':
            document.getElementById('dashboardBody').innerHTML = renderDocumentRequestsView();
            loadFilteredRequests('all', 1);
            break;
        case 'manage_documents':
            document.getElementById('dashboardBody').innerHTML = renderManageDocumentsView();
            break;
        case 'verify_residents':
            document.getElementById('dashboardBody').innerHTML = renderVerifyResidentsView();
            break;
        case 'resident_list':
            document.getElementById('dashboardBody').innerHTML = renderResidentListView();
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
                return `
                <div class="notif-item ${n.is_read == 0 ? 'unread' : ''} ${isActionRequired ? 'action-required' : ''}"
                     onclick="onNotifClick(${n.id}, ${n.reference_id || 'null'}, '${escapeHtml(n.reference_type || '')}')">
                    <div class="notif-icon ${n.type}"><i class="fas ${iconMap[n.type]||'fa-bell'}"></i></div>
                    <div class="notif-body">
                        <div class="notif-title">${isActionRequired ? '⚠ ' : ''}${escapeHtml(n.title)}</div>
                        <div class="notif-msg">${escapeHtml(n.message)}</div>
                        <div class="notif-time">${timeAgo(n.created_at)}</div>
                    </div>
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
    } else setTimeout(fetchNotifications, 300);
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