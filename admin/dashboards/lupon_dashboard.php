<?php
// admin/dashboards/lupon_dashboard.php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../../sign_in.php');
    exit();
}
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../../config/database.php';

$admin_id = $_SESSION['user_id'];
$roleSql = "SELECT admin_role FROM admin WHERE id = ?";
$roleStmt = mysqli_prepare($conn, $roleSql);
mysqli_stmt_bind_param($roleStmt, "i", $admin_id);
mysqli_stmt_execute($roleStmt);
$adminRole = mysqli_fetch_assoc(mysqli_stmt_get_result($roleStmt))['admin_role'];

if ($adminRole !== 'lupon') {
    switch($adminRole) {
        case 'captain':     header('Location: captain_dashboard.php'); break;
        case 'secretary':   header('Location: secretary_dashboard.php'); break;
        case 'kagawad':     header('Location: kagawad_dashboard.php'); break;
        case 'super_admin': header('Location: ../dashboard.php'); break;
        default:            header('Location: ../dashboard.php');
    }
    exit();
}

$sql = "SELECT * FROM admin WHERE id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $admin_id);
mysqli_stmt_execute($stmt);
$admin = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// ═══════════════════════════════════════════════════════════
// LUPON STATS — only cases this member is handling
// ═══════════════════════════════════════════════════════════
$stats = [];

// Helper: rows where this admin is a Pangkat member
function luponCaseIds($conn, $admin_id) {
    $ids = [];
    $stmt = mysqli_prepare($conn,
        "SELECT DISTINCT c.id
         FROM complaints c
         JOIN complaint_pangkat cp ON cp.complaint_id = c.id
         WHERE cp.member_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $admin_id);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($r)) $ids[] = (int)$row['id'];
    mysqli_stmt_close($stmt);
    return $ids;
}

$myCaseIds = luponCaseIds($conn, $admin_id);
$myCaseIdList = !empty($myCaseIds) ? implode(',', $myCaseIds) : '0';

// ── Simple counters (from existing queries) ──
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM complaints WHERE id IN ($myCaseIdList) AND status = 'pangkat_scheduled'");
$stats['scheduled'] = (int)mysqli_fetch_assoc($result)['total'];

$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM complaints WHERE id IN ($myCaseIdList) AND status IN ('settled','settled_pending')");
$stats['settled'] = (int)mysqli_fetch_assoc($result)['total'];

$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM complaints WHERE id IN ($myCaseIdList) AND status IN ('unresolved','failed_conciliation_final')");
$stats['failed'] = (int)mysqli_fetch_assoc($result)['total'];

$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM complaints WHERE id IN ($myCaseIdList)");
$stats['total'] = (int)mysqli_fetch_assoc($result)['total'];

$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM complaints WHERE id IN ($myCaseIdList) AND status IN ('pangkat_constituted','pangkat_scheduled')");
$stats['active'] = (int)mysqli_fetch_assoc($result)['total'];

$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM complaints WHERE id IN ($myCaseIdList) AND status = 'certificate_issued'");
$stats['unresolved'] = (int)mysqli_fetch_assoc($result)['total'];

// Positions to assign (first-selected member + pending roles)
$needAssignSql = "SELECT COUNT(*) as total
                  FROM complaint_pangkat cp
                  JOIN complaints c ON cp.complaint_id = c.id
                  WHERE cp.member_id = ?
                    AND cp.role = 'pending'
                    AND c.status IN ('pangkat_constituted','pangkat_scheduled')";
$needAssignStmt = mysqli_prepare($conn, $needAssignSql);
mysqli_stmt_bind_param($needAssignStmt, "i", $admin_id);
mysqli_stmt_execute($needAssignStmt);
$stats['needs_assign'] = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($needAssignStmt))['total'] ?? 0);
mysqli_stmt_close($needAssignStmt);

// ═══════════════════════════════════════════════════════════
// DASHBOARD ANALYTICS — scoped to this Lupon member's cases
// ═══════════════════════════════════════════════════════════
function luponRows($conn, $sql) {
    try {
        $r = mysqli_query($conn, $sql);
        if (!$r) return [];
        $out = [];
        while ($row = mysqli_fetch_assoc($r)) $out[] = $row;
        return $out;
    } catch (Throwable $e) { return []; }
}

// ── Announcements: READ-ONLY list (served from THIS file so the Lupon role is never redirected) ──
if (isset($_GET['ann_action']) && $_GET['ann_action'] === 'list') {
    header('Content-Type: application/json');
    $annRows = luponRows($conn,
        "SELECT a.*, ad.full_name AS author_name
         FROM announcements a
         LEFT JOIN admin ad ON ad.id = a.created_by
         WHERE a.is_active = 1
           AND (a.expires_at IS NULL OR a.expires_at > NOW())
         ORDER BY a.is_pinned DESC, a.created_at DESC
         LIMIT 30");
    $annOut = [];
    foreach ($annRows as $r) {
        $annOut[] = [
            'id'         => (int)$r['id'],
            'title'      => $r['title'],
            'body'       => $r['body'],
            'category'   => $r['category'] ?? 'general',
            'image_url'  => !empty($r['image_path']) ? 'uploads/announcements/' . $r['image_path'] : null,
            'is_pinned'  => (int)($r['is_pinned'] ?? 0),
            'expires_at' => $r['expires_at'] ?? null,
            'event_date' => $r['event_date'] ?? null,
            'event_time' => $r['event_time'] ?? null,
            'created_at' => $r['created_at'],
            'author'     => $r['author_name'] ?? null,
        ];
    }
    echo json_encode(['success' => true, 'data' => $annOut]);
    exit;
}

$dxMonths = [];
for ($i = 5; $i >= 0; $i--) {
    $d = new DateTime('first day of this month');
    $d->modify("-$i month");
    $dxMonths[$d->format('Y-m')] = $d->format('M');
}
$dxCaseMonthly = array_fill_keys(array_keys($dxMonths), 0);

foreach (luponRows($conn,
    "SELECT DATE_FORMAT(c.created_at,'%Y-%m') ym, COUNT(*) c
     FROM complaints c
     WHERE c.id IN ($myCaseIdList)
       AND c.created_at >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH,'%Y-%m-01')
     GROUP BY ym") as $row) {
    if (isset($dxCaseMonthly[$row['ym']])) $dxCaseMonthly[$row['ym']] = (int)$row['c'];
}

$dxCaseStatus = [];
foreach (luponRows($conn,
    "SELECT status, COUNT(*) c FROM complaints WHERE id IN ($myCaseIdList) GROUP BY status") as $row) {
    $dxCaseStatus[$row['status']] = (int)$row['c'];
}

// Calendar — only this member's hearings
$dxEvents = [];
foreach (luponRows($conn,
    "SELECT c.id, c.reference_number, c.title, c.status, c.hearing_date, c.hearing_time
     FROM complaints c
     WHERE c.id IN ($myCaseIdList)
       AND c.hearing_date IS NOT NULL
       AND c.hearing_date BETWEEN CURDATE() - INTERVAL 90 DAY AND CURDATE() + INTERVAL 270 DAY
     ORDER BY c.hearing_date ASC LIMIT 300") as $row) {
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

// Recent cases for the feed
$dxRecentCases = luponRows($conn,
    "SELECT c.id, c.reference_number, c.title, c.status, c.created_at, c.updated_at,
            CONCAT(COALESCE(cp.first_name,''),' ',COALESCE(cp.last_name,'')) AS complainant_name
     FROM complaints c
     LEFT JOIN (
         SELECT complaint_id, MIN(first_name) AS first_name, MIN(last_name) AS last_name
         FROM complaint_parties WHERE party_type='complainant' GROUP BY complaint_id
     ) cp ON cp.complaint_id = c.id
     WHERE c.id IN ($myCaseIdList)
     ORDER BY COALESCE(c.updated_at, c.created_at) DESC
     LIMIT 8");

$dxData = [
    'months'      => array_values($dxMonths),
    'caseMonthly' => array_values($dxCaseMonthly),
    'caseStatus'  => $dxCaseStatus,
    'events'      => $dxEvents,
];
$dxJson = json_encode($dxData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Lupon Dashboard | BRITE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        <?php include '../admin.css'; ?>

        .swal2-container, .swal2-container.swal2-top, .swal2-container.swal2-center, .swal2-container.swal2-bottom {
            z-index: 100000 !important;
        }
        .swal2-popup { z-index: 100001 !important; }

        .sidebar { background: linear-gradient(180deg, #2E7D32 20%, #43a047 80%); }
        .sidebar::-webkit-scrollbar-thumb { background-color: #81c784; }
        .welcome-card { background: linear-gradient(135deg, #2E7D32, #43a047); }
        .stat-icon.pending { background: #fff3e0; color: #e65100; }
        .stat-icon.mediation { background: #f3e5f5; color: #9c27b0; }
        .stat-icon.settled { background: #e8f5e9; color: #2e7d32; }
        .stat-icon.failed { background: #fce4ec; color: #c2185b; }
        .stat-icon.total { background: #f3e5f5; color: #9c27b0; }
        .stat-icon.assign { background: #ffebee; color: #c62828; }
        .quick-action-btn:hover { background: #2e7d32; border-color: #2d7431; }

        .nav-item .nav-badge {
            margin-left: auto;
            background: #dc3545;
            color: #fff;
            border-radius: 10px;
            padding: 2px 8px;
            font-size: 0.65rem;
            font-weight: 700;
        }

        /* Notification bell */
        .header-right-group { display: flex; align-items: center; gap: 6px; margin-left: auto; }
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
        .notif-time  {
            font-size: 0.68rem;
            color: #999;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        /* ✅ PDF chip in notification */
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
        }
        .notif-item.has-doc { cursor: alias; }
        .notif-item.has-doc:hover { background: #e8f5e9; }
        .notif-empty { padding: 30px; text-align: center; color: #999; font-size: 0.85rem; }
        .notif-footer { padding: 10px 16px; background: #f8faf8; border-top: 1px solid #e2efe8; font-size: 0.78rem; }
        .notif-permission-label { display: flex; align-items: center; gap: 8px; cursor: pointer; color: #555; }
        .notif-select-bar { display: flex; align-items: center; gap: 10px; padding: 8px 16px; background: #f8faf8; border-bottom: 1px solid #e2efe8; font-size: 0.78rem; color: #555; }
        .notif-select-bar input[type=checkbox] { width: 15px; height: 15px; cursor: pointer; }
        .notif-delete-btn { margin-left: auto; background: #c62828; color: #fff; border: none; padding: 4px 12px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; cursor: pointer; display: none; }
        .notif-delete-btn.show { display: inline-flex; align-items: center; gap: 5px; }
        .notif-item .notif-check { width: 18px; height: 18px; margin-top: 10px; cursor: pointer; accent-color: #2E7D32; flex-shrink: 0; }

        /* Card grid */
        .documents-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 18px; align-items: start; }
        .document-card { background: white; border-radius: 14px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.06); transition: all 0.25s ease; border: 1px solid #e2efe8; display: flex; flex-direction: column; position: relative; align-self: start; }
        .document-card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        .document-card.assign-needed { border: 2px solid #c62828; box-shadow: 0 0 0 3px rgba(198,40,40,0.15), 0 4px 14px rgba(198,40,40,0.25); }
        .document-card.assign-needed::before { content: ""; position: absolute; inset: -2px; border-radius: 16px; border: 2px solid #c62828; animation: votePulse 2s infinite; pointer-events: none; z-index: 1; }
        @keyframes votePulse { 0%, 100% { opacity: 0.6; transform: scale(1); } 50% { opacity: 0; transform: scale(1.03); } }
        .assign-needed-ribbon { position: absolute; top: 12px; right: -32px; background: linear-gradient(135deg, #c62828, #e53935); color: #fff; padding: 4px 40px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.8px; text-transform: uppercase; transform: rotate(45deg); box-shadow: 0 2px 6px rgba(198,40,40,0.4); z-index: 5; }
        .hearing-ribbon { position: absolute; top: 12px; right: 12px; background: linear-gradient(135deg, #d32f2f, #ff5252); color: #fff; padding: 4px 12px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.8px; text-transform: uppercase; border-radius: 12px; box-shadow: 0 2px 8px rgba(211,47,47,0.5); z-index: 6; animation: hearingPulse 1.5s infinite; }
        @keyframes hearingPulse { 0%, 100% { box-shadow: 0 2px 8px rgba(211,47,47,0.5), 0 0 0 0 rgba(255,82,82,0.7); } 50% { box-shadow: 0 2px 8px rgba(211,47,47,0.5), 0 0 0 8px rgba(255,82,82,0); } }
        .settled-ribbon { position: absolute; top: 12px; right: 12px; background: linear-gradient(135deg, #00838f, #00acc1); color: #fff; padding: 4px 12px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.8px; text-transform: uppercase; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,131,143,0.5); z-index: 6; }

        .document-card-header { background: linear-gradient(135deg, #2E7D32, #43a047); padding: 14px 18px; color: white; display: flex; justify-content: space-between; align-items: center; min-height: 52px; }
        .document-card-header.pending  { background: linear-gradient(135deg, #e65100, #f57c00); }
        .document-card-header.mediation { background: linear-gradient(135deg, #1565c0, #1976d2); }
        .document-card-header.referral { background: linear-gradient(135deg, #8e24aa, #ab47bc); }
        .document-card-header.settled  { background: linear-gradient(135deg, #2E7D32, #43a047); }
        .document-card-header.settled_pending  { background: linear-gradient(135deg, #00838f, #00acc1); }
        .document-card-header.failed   { background: linear-gradient(135deg, #c62828, #d32f2f); }
        .document-card-header.lupon    { background: linear-gradient(135deg, #00838f, #00acc1); }
        .document-card-header.assign   { background: linear-gradient(135deg, #b71c1c, #e53935); }
        .document-card-header.start    { background: linear-gradient(135deg, #6a1b9a, #8e24aa); }
        .document-card-header.inprogress { background: linear-gradient(135deg, #d32f2f, #ff5252); }
        .document-card-header.waiting  { background: linear-gradient(135deg, #c62828, #e53935); }

        .document-title { font-weight: 600; font-size: 0.9rem; display: flex; align-items: center; gap: 8px; overflow: hidden; max-width: 70%; }
        .document-title span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .document-status { font-size: 0.68rem; padding: 4px 10px; border-radius: 20px; background: rgba(255,255,255,0.22); text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap; font-weight: 700; }

        .document-card-body { padding: 14px 18px; flex: 1; display: flex; flex-direction: column; gap: 7px; }
        .document-info-row { display: flex; font-size: 0.8rem; }
        .document-info-row .document-info-label { width: 96px; flex-shrink: 0; color: #5f7f6e; font-weight: 500; }
        .document-info-row .document-info-value { flex: 1; color: #2c3e50; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        .document-card-actions { padding: 12px 18px; background: #f8faf8; border-top: 1px solid #e2efe8; display: flex; gap: 8px; flex-wrap: wrap; }
        .doc-action-btn { flex: 1; min-width: 110px; padding: 9px 12px; border: none; border-radius: 8px; cursor: pointer; font-size: 0.78rem; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.15s; }
        .doc-action-btn:hover { opacity: 0.9; transform: translateY(-1px); }
        .doc-action-btn.view     { background: #17a2b8; color: white; }
        .doc-action-btn.assign   { background: linear-gradient(135deg, #b71c1c, #e53935); color: white; box-shadow: 0 2px 8px rgba(198,40,40,0.35); }
        .doc-action-btn.schedule { background: linear-gradient(135deg, #e65100, #f57c00); color: white; }
        .doc-action-btn.start    { background: linear-gradient(135deg, #6a1b9a, #8e24aa); color: white; }
        .doc-action-btn.enter    { background: linear-gradient(135deg, #d32f2f, #ff5252); color: white; box-shadow: 0 2px 8px rgba(211,47,47,0.5); animation: hearingPulse 1.5s infinite; }
        .doc-action-btn.appear   { background: linear-gradient(135deg, #c62828, #e53935); color: white; }
        .doc-action-btn.decide   { background: linear-gradient(135deg, #6a1b9a, #8e24aa); color: white; }
        .doc-action-btn.resched  { background: linear-gradient(135deg, #00838f, #00acc1); color: white; }
        .doc-action-btn.cert     { background: linear-gradient(135deg, #b71c1c, #e53935); color: white; }

        .pangkat-chip { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 12px; font-size: 0.7rem; font-weight: 700; background: #e8f5e9; color: #1b5e20; border: 1px solid #a5d6a7; }
        .pangkat-chip.role-chair     { background:#c8e6c9; color:#1b5e20; }
        .pangkat-chip.role-secretary { background:#bbdefb; color:#0d47a1; }
        .pangkat-chip.role-member    { background:#ffe0b2; color:#e65100; }
        .pangkat-chip.role-pending   { background:#fff8e1; color:#f57c00; }
        .pangkat-chip.first          { background:#c8e6c9; color:#1b5e20; border-color:#66bb6a; }

        .status-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 12px; font-size: 0.68rem; font-weight: 700; transition: all 0.3s ease; }
        .status-pill.safe     { background: #e8f5e9; color: #2e7d32; }
        .status-pill.warning  { background: #fff8e1; color: #f57c00; }
        .status-pill.critical { background: #ffebee; color: #c62828; animation: pulseRed 1.5s infinite; }
        @keyframes pulseRed { 0%, 100% { box-shadow: 0 0 0 0 rgba(198, 40, 40, 0.4); } 50% { box-shadow: 0 0 0 6px rgba(198, 40, 40, 0); } }

        /* Complaint detail modal */
        .cd-popup { border-radius: 16px !important; padding: 0 !important; overflow: hidden; max-width: 1080px !important; width: 95vw !important; }
        .cd-popup .swal2-html-container { margin: 0 !important; padding: 0 !important; }
        .cd-popup .swal2-actions { display: none !important; }
        .cd-popup .swal2-title { display: none !important; }
        .cd-modal { text-align: left; font-family: 'Segoe UI', Roboto, sans-serif; }
        .cd-header { background: linear-gradient(135deg, #1b5e20, #2E7D32 55%, #43a047); color: #fff; padding: 18px 24px; display: flex; justify-content: space-between; align-items: center; }
        .cd-header.cd-pangkat   { background: linear-gradient(135deg, #00838f, #00acc1); }
        .cd-header.cd-assign    { background: linear-gradient(135deg, #b71c1c, #e53935); }
        .cd-header.cd-settled-pending { background: linear-gradient(135deg, #00838f, #00acc1); }
        .cd-header.cd-settled   { background: linear-gradient(135deg, #1b5e20, #2E7D32); }
        .cd-header-left { display: flex; align-items: center; gap: 14px; }
        .cd-header-left > i { font-size: 1.8rem; opacity: 0.9; }
        .cd-title { font-size: 1.1rem; font-weight: 700; }
        .cd-sub   { font-size: 0.8rem; opacity: 0.85; margin-top: 2px; }
        .cd-status { padding: 6px 14px; border-radius: 20px; background: rgba(255,255,255,0.2); font-size: 0.72rem; font-weight: 700; letter-spacing: 0.5px; }
        .cd-body { padding: 22px 26px; max-height: 76vh; overflow-y: auto; background: #f8faf8; display: flex; flex-direction: column; gap: 16px; }
        .cd-block { background: #fff; border-radius: 12px; padding: 18px 22px; border: 1px solid #e2efe8; }
        .cd-block-title { display: flex; align-items: center; gap: 12px; font-size: 0.95rem; font-weight: 800; color: #1a472a; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 2px solid #e2efe8; }
        .cd-block-title .cd-num { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; background: #2E7D32; color: #fff; border-radius: 50%; font-size: 0.78rem; font-weight: 700; flex-shrink: 0; }
        .cd-block-title.toggleable { cursor:pointer; user-select:none; justify-content:space-between; }
        .cd-block-title .cd-arrow { transition: transform 0.2s; color: #999; }
        .cd-block.collapsed .cd-block-title .cd-arrow { transform: rotate(-90deg); }
        .cd-block.collapsed .cd-block-body { display: none; }
        .cd-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px 20px; }
        .cd-grid-item { display: flex; flex-direction: column; }
        .cd-grid-item label { font-size: 0.7rem; text-transform: uppercase; color: #999; margin-bottom: 3px; letter-spacing: 0.4px; font-weight: 600; }
        .cd-grid-item span { font-size: 0.9rem; color: #1a2b22; font-weight: 600; }
        .cd-text-block { margin-top: 12px; }
        .cd-text-block label { display: block; font-size: 0.7rem; text-transform: uppercase; color: #999; margin-bottom: 5px; letter-spacing: 0.4px; font-weight: 600; }
        .cd-text-block p { color: #1a2b22; font-size: 0.92rem; }
        .cd-person { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border-radius: 10px; background: #fafbfa; border: 1px solid #f0f0f0; }
        .cd-person-avatar { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .cd-person-name { font-weight: 700; color: #1a1a1a; font-size: 0.92rem; }
        .cd-person-meta { font-size: 0.8rem; color: #555; margin-top: 4px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
        .cd-history-item { background: #f8faf8; border-radius: 10px; padding: 10px 14px; border-left: 3px solid #43e97b; }
        .cd-history-time { font-size: 0.7rem; color: #999; }
        .cd-history-notes { font-size: 0.85rem; color: #1a2b22; margin-top: 3px; line-height: 1.5; }
        .cd-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; border: none; cursor: pointer; transition: all 0.15s; text-decoration: none; }
        .cd-btn.assign    { background: #b71c1c; color: #fff; }
        .cd-btn.schedule  { background: #e65100; color: #fff; }
        .cd-btn.start     { background: #6a1b9a; color: #fff; }
        .cd-btn.enter     { background: #d32f2f; color: #fff; }
        .cd-btn.resched   { background: #00838f; color: #fff; }
        .cd-btn.secondary { background: #6c757d; color: #fff; }
        .cd-actions { display: flex; gap: 10px; justify-content: flex-end; padding: 14px 22px; background: #f8faf8; border-top: 1px solid #e2efe8; flex-wrap: wrap; }

        .dm-field { margin-bottom: 16px; }
        .dm-field label { display: block; font-weight: 600; font-size: 0.85rem; color: #1a472a; margin-bottom: 6px; }
        .dm-field label .req { color: #c62828; }
        .dm-field label .opt { color: #999; font-weight: 400; font-size: 0.75rem; }
        .dm-field input, .dm-field select, .dm-field textarea { width: 100%; padding: 10px 12px; border: 1.5px solid #cfd8dc; border-radius: 8px; font-size: 0.92rem; font-family: inherit; box-sizing: border-box; resize: vertical; background: #fff; }
        .dm-field input:focus, .dm-field select:focus, .dm-field textarea:focus { outline: none; border-color: #2E7D32; box-shadow: 0 0 0 3px rgba(46,125,50,0.12); }
        .dm-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        @media (max-width: 700px) { .dm-grid-2 { grid-template-columns: 1fr; } }

        .doc-list-row { display: flex; align-items: center; gap: 10px; padding: 8px 12px; background:#fafbfa; border:1px solid #e2efe8; border-radius:8px; margin-bottom:6px; font-size:0.82rem; }
        .doc-list-row a { flex:1; color:#1565c0; font-weight:600; text-decoration:none; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .doc-list-row .doc-meta { font-size:0.7rem; color:#888; }

        /* Previous hearings block (compact rows) */
        .prev-hearings-block { background:#f0f4ff; border:1.5px solid #bbdefb; border-radius:10px; margin-bottom:16px; overflow:hidden; }
        .prev-hearings-block.concil { background:#f0fbff; border-color:#b2ebf2; }
        .prev-hearings-header { padding:12px 16px; background:#e3f2fd; display:flex; justify-content:space-between; align-items:center; cursor:pointer; user-select:none; font-weight:700; font-size:0.85rem; color:#0d47a1; }
        .prev-hearings-block.concil .prev-hearings-header { background:#e0f7fa; color:#00838f; }
        .prev-hearings-header:hover { background:#bbdefb; }
        .prev-hearings-block.concil .prev-hearings-header:hover { background:#b2ebf2; }
        .prev-hearings-header .toggle-icon { transition: transform 0.2s; }
        .prev-hearings-block.expanded .prev-hearings-header .toggle-icon { transform: rotate(180deg); }
        .prev-hearings-body { display:none; padding:12px 16px; background:#fff; }
        .prev-hearings-block.expanded .prev-hearings-body { display:block; }
        .prev-hearing-item { padding:10px 14px; background:#fafbfa; border-left:4px solid #1565c0; border-radius:8px; margin-bottom:8px; font-size:0.82rem; transition: all .15s; }
        .prev-hearings-block.concil .prev-hearing-item { border-left-color:#00acc1; }
        .prev-hearing-item:hover { background:#eff6ff; transform: translateX(2px); }
        .prev-hearing-item:last-child { margin-bottom:0; }
        .prev-hearing-head { font-weight:700; color:#1a472a; margin-bottom:4px; display:flex; align-items:center; justify-content:space-between; gap:8px; }
        .prev-hearing-meta { font-size:0.72rem; color:#666; display:flex; flex-wrap:wrap; gap:12px; }
        .prev-hearing-view-btn { font-size:0.68rem; padding:4px 10px; border-radius:6px; background:#1565c0; color:#fff; border:none; cursor:pointer; font-weight:700; display:inline-flex; align-items:center; gap:4px; }
        .prev-hearing-view-btn:hover { background:#0d47a1; }

        .ev-row { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background:#fafbfa; border:1px solid #e2efe8; border-radius:8px; margin-bottom:6px; font-size:0.82rem; }
        .ev-row .ev-icon { width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; background:#e3f2fd; color:#1565c0; flex-shrink:0; }
        .ev-row .ev-body { flex:1; min-width:0; }
        .ev-row .ev-name { font-weight:700; color:#1a2b22; }
        .ev-row .ev-meta { font-size:0.7rem; color:#888; margin-top:2px; display:flex; gap:10px; flex-wrap:wrap; }
        .ev-row .ev-open { background:#1565c0; color:#fff; border:none; padding:6px 12px; border-radius:6px; font-size:0.72rem; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:5px; }

        /* Hearing detail modal */
        .hd-modal { text-align:left; font-family:'Segoe UI', Roboto, sans-serif; font-size:0.88rem; }
        .hd-header { background: linear-gradient(135deg, #0d47a1, #1976d2 55%, #42a5f5); color:#fff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center; gap:12px; }
        .hd-header.mediation   { background: linear-gradient(135deg, #6a1b9a, #8e24aa 55%, #ab47bc); }
        .hd-header.conciliation{ background: linear-gradient(135deg, #00838f, #00acc1 55%, #4dd0e1); }
        .hd-header-left { display:flex; align-items:center; gap:12px; }
        .hd-header-left > i { font-size:1.6rem; opacity:0.9; }
        .hd-title { font-size:1.05rem; font-weight:700; }
        .hd-sub   { font-size:0.78rem; opacity:0.85; margin-top:2px; }
        .hd-body  { padding:18px 22px; max-height:70vh; overflow-y:auto; background:#f8faf8; }
        .hd-block { background:#fff; border-radius:10px; padding:14px 18px; border:1px solid #e2efe8; margin-bottom:14px; }
        .hd-block-title { display:flex; align-items:center; gap:10px; font-size:0.85rem; font-weight:800; color:#1a472a; text-transform:uppercase; letter-spacing:0.3px; margin-bottom:10px; padding-bottom:8px; border-bottom:2px solid #e2efe8; }
        .hd-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:10px 18px; }
        .hd-grid-item label { display:block; font-size:0.68rem; text-transform:uppercase; color:#999; margin-bottom:2px; letter-spacing:0.4px; font-weight:600; }
        .hd-grid-item span { font-size:0.88rem; color:#1a2b22; font-weight:600; }
        .hd-att-table { width:100%; border-collapse:collapse; font-size:0.82rem; }
        .hd-att-table th, .hd-att-table td { padding:8px 10px; border-bottom:1px solid #e2efe8; text-align:left; vertical-align:top; }
        .hd-att-table th { background:#f3f6f4; font-weight:700; color:#1a472a; text-transform:uppercase; font-size:0.68rem; letter-spacing:0.4px; }
        .hd-att-pill { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:10px; font-size:0.68rem; font-weight:700; text-transform:uppercase; }
        .hd-att-pill.attend  { background:#c8e6c9; color:#1b5e20; }
        .hd-att-pill.no_show { background:#ffcdd2; color:#b71c1c; }
        .hd-att-pill.pending { background:#e0e0e0; color:#666; }
        .hd-justif-pill { display:inline-block; padding:2px 8px; border-radius:10px; font-size:0.65rem; font-weight:700; text-transform:uppercase; }
        .hd-justif-pill.justified   { background:#e8f5e9; color:#1b5e20; }
        .hd-justif-pill.unjustified { background:#ffebee; color:#b71c1c; }
        .hd-summary  { background:#f8f9fa; border:1px dashed #d0d7de; border-radius:8px; padding:10px 12px; font-size:0.84rem; line-height:1.6; color:#1a2b22; }
        .hd-note     { font-size:0.78rem; color:#666; }

        /* KP forms in complaint detail */
        .kp-entry { display: flex; gap: 12px; padding: 12px 14px; background: #fff; border: 1.5px solid #e2efe8; border-radius: 10px; margin-bottom: 8px; }
        .kp-entry.generated { border-left: 4px solid #2E7D32; }
        .kp-entry-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-weight: 800; font-size: 0.9rem; background: #e8f5e9; color: #1b5e20; }
        .kp-entry-body { flex: 1; min-width: 0; }
        .kp-entry-title { font-weight: 700; font-size: 0.9rem; color: #1a2b22; margin-bottom: 3px; }
        .kp-entry-event { font-size: 0.78rem; color: #555; line-height: 1.45; margin-bottom: 6px; }
        .kp-entry-meta { font-size: 0.72rem; color: #666; display: flex; gap: 14px; flex-wrap: wrap; }
        .kp-entry-actions { display: flex; flex-direction: column; gap: 6px; justify-content: center; }
        .kp-entry-btn { padding: 6px 12px; border-radius: 6px; border: none; font-size: 0.72rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
        .kp-entry-btn.open { background: #1565c0; color: #fff; }
        .kp-entry-btn.gen  { background: #2E7D32; color: #fff; }

        /* Recorder UI */
        .pstmt-block { background: #fff8e1; border: 1.5px solid #ffe0b2; border-left: 4px solid #f57c00; border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; }
        .pstmt-title { font-weight: 800; font-size: 0.85rem; color: #b71c1c; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; padding-bottom: 8px; border-bottom: 1px solid #ffe0b2; }
        .pstmt-card { background: #fff; border: 1.5px solid #e2efe8; border-radius: 10px; padding: 12px 14px; margin-bottom: 12px; }
        .pstmt-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .pstmt-avatar { width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .pstmt-avatar.complainant { background: #e8f5e9; color: #2E7D32; }
        .pstmt-avatar.respondent  { background: #fff3e0; color: #ef6c00; }
        .pstmt-name { font-weight: 700; font-size: 0.88rem; color: #1a2b22; }
        .pstmt-sub  { font-size: 0.72rem; color: #666; margin-top: 2px; }
        .pstmt-rec-wrap { display: flex; align-items: center; gap: 10px; margin-top: 8px; flex-wrap: wrap; }
        .pstmt-rec-btn { padding: 8px 16px; border-radius: 22px; border: none; cursor: pointer; font-size: 0.8rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; transition: all .15s; }
        .pstmt-rec-btn.rec  { background: #c62828; color: #fff; }
        .pstmt-rec-btn.stop { background: #546e7a; color: #fff; }
        .pstmt-wave { display: flex; align-items: center; gap: 3px; height: 28px; padding: 0 4px; }
        .pstmt-wave .bar { width: 3px; background: #c62828; border-radius: 2px; animation: waveBounce 0.9s infinite ease-in-out; }
        .pstmt-wave .bar:nth-child(1) { animation-delay: 0.0s; height: 8px; }
        .pstmt-wave .bar:nth-child(2) { animation-delay: 0.1s; height: 16px; }
        .pstmt-wave .bar:nth-child(3) { animation-delay: 0.2s; height: 22px; }
        .pstmt-wave .bar:nth-child(4) { animation-delay: 0.3s; height: 14px; }
        .pstmt-wave .bar:nth-child(5) { animation-delay: 0.4s; height: 20px; }
        .pstmt-wave .bar:nth-child(6) { animation-delay: 0.5s; height: 10px; }
        .pstmt-wave .bar:nth-child(7) { animation-delay: 0.6s; height: 18px; }
        @keyframes waveBounce { 0%, 100% { transform: scaleY(0.4); opacity: 0.7; } 50% { transform: scaleY(1.4); opacity: 1; } }
        .pstmt-timer { font-family: 'Courier New', monospace; font-size: 0.85rem; font-weight: 700; color: #c62828; letter-spacing: 0.5px; min-width: 52px; text-align: right; }
        .pstmt-preview { display: flex; align-items: center; gap: 10px; margin-top: 10px; padding: 10px 12px; background: #f0f7ff; border-left: 4px solid #1565c0; border-radius: 10px; }
        .pstmt-play-btn { width: 38px; height: 38px; border-radius: 50%; border: none; cursor: pointer; background: #1565c0; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; flex-shrink: 0; }
        .pstmt-play-wave { display: flex; align-items: center; gap: 3px; height: 26px; flex: 1; overflow: hidden; }
        .pstmt-play-wave .bar { width: 3px; background: #1565c0; border-radius: 2px; height: 6px; }
        .pstmt-play-wave.playing .bar { animation: playWave 0.8s infinite ease-in-out; }
        .pstmt-play-wave.playing .bar:nth-child(1) { animation-delay: 0.0s; }
        .pstmt-play-wave.playing .bar:nth-child(2) { animation-delay: 0.08s; }
        .pstmt-play-wave.playing .bar:nth-child(3) { animation-delay: 0.16s; }
        .pstmt-play-wave.playing .bar:nth-child(4) { animation-delay: 0.24s; }
        .pstmt-play-wave.playing .bar:nth-child(5) { animation-delay: 0.32s; }
        .pstmt-play-wave.playing .bar:nth-child(6) { animation-delay: 0.40s; }
        .pstmt-play-wave.playing .bar:nth-child(7) { animation-delay: 0.48s; }
        .pstmt-play-wave.playing .bar:nth-child(8) { animation-delay: 0.56s; }
        @keyframes playWave { 0%, 100% { transform: scaleY(0.5); opacity: 0.7; } 50% { transform: scaleY(1.8); opacity: 1; } }
        .pstmt-preview-meta { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .pstmt-preview-label { font-size: 0.78rem; font-weight: 700; color: #0d47a1; }
        .pstmt-preview-dur { font-size: 0.72rem; color: #666; }
        .pstmt-remove-btn { width: 32px; height: 32px; border-radius: 50%; border: none; background: #ffebee; color: #c62828; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; flex-shrink: 0; }

        .dm-header { color: #fff; padding: 18px 22px; display: flex; justify-content: space-between; align-items: center; gap: 14px; }
        .dm-header.blue   { background: linear-gradient(135deg, #1565c0, #1976d2 55%, #42a5f5); }
        .dm-header.green  { background: linear-gradient(135deg, #1b5e20, #2E7D32 55%, #43a047); }
        .dm-header.red    { background: linear-gradient(135deg, #b71c1c, #c62828 55%, #e53935); }
        .dm-header.purple { background: linear-gradient(135deg, #6a1b9a, #8e24aa 55%, #ab47bc); }
        .dm-header.teal   { background: linear-gradient(135deg, #00838f, #26c6da 55%, #4dd0e1); }
        .dm-header-left { display: flex; align-items: center; gap: 14px; }
        .dm-header-left > i { font-size: 1.8rem; opacity: 0.9; }
        .dm-header-title { font-size: 1.15rem; font-weight: 700; }
        .dm-header-sub   { font-size: 0.8rem; opacity: 0.85; margin-top: 2px; }
        .dm-header-ref { text-align:right; }
        .dm-header-ref-label { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 1px; opacity: 0.8; }
        .dm-header-ref-value { font-size: 0.9rem; font-weight: 700; margin-top: 2px; }

        .handbook-quote { background: #fff8e1; border: 1.5px solid #ffe0b2; border-left: 4px solid #c62828; border-radius: 10px; padding: 14px 16px; font-size: 0.82rem; color: #4e342e; line-height: 1.6; margin-bottom: 16px; }
        .handbook-quote-title { font-weight: 800; color: #b71c1c; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; font-size: 0.85rem; letter-spacing: 0.3px; text-transform: uppercase; }

        /* Attendance cards */
        .att-card { background: #fafbfa; border: 1.5px solid #e2efe8; border-radius: 12px; padding: 12px 14px; margin-bottom: 10px; }
        .att-card.attend  { border-color:#a5d6a7; background:#f1f8e9; }
        .att-card.no_show { border-color:#ef9a9a; background:#ffebee; }
        .att-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .att-avatar { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.9rem; }
        .att-avatar.complainant { background:#e8f5e9; color:#2E7D32; }
        .att-avatar.respondent  { background:#fff3e0; color:#ef6c00; }
        .att-meta { flex: 1; min-width: 0; }
        .att-name { font-weight: 700; font-size: 0.9rem; color:#1a2b22; }
        .att-role { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 700; margin-top: 2px; }
        .att-role.complainant { color:#2E7D32; }
        .att-role.respondent  { color:#ef6c00; }
        .att-btn-row { display: flex; gap: 8px; flex-wrap: wrap; }
        .att-btn { flex: 1; min-width: 90px; padding: 8px 10px; border-radius: 8px; border: 1.5px solid #cfd8dc; background: #fff; font-size: 0.78rem; font-weight: 600; color: #555; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .att-btn.attend.active  { background:#2E7D32; border-color:#2E7D32; color:#fff; }
        .att-btn.no_show.active { background:#c62828; border-color:#c62828; color:#fff; }
        .att-status-pill { font-size: 0.65rem; padding: 3px 9px; border-radius: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap; }
        .att-status-pill.attend  { background:#c8e6c9; color:#1b5e20; }
        .att-status-pill.no_show { background:#ffcdd2; color:#b71c1c; }
        .att-status-pill.pending { background:#e0e0e0; color:#666; }

        .abs-radio { position: relative; display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border: 2px solid #e2efe8; border-radius: 10px; background: #fff; cursor: pointer; transition: all .15s; }
        .abs-radio.selected.justified   { border-color:#2E7D32; background:#f1f8e9; }
        .abs-radio.selected.unjustified { border-color:#c62828; background:#ffebee; }

        /* ============================================================
           INLINE HEARING VIEW — DOCUMENTABLE UI
           ============================================================ */
        .hearing-inline-wrap { background: #eef2f0; border-radius: 14px; padding: 20px; animation: hearingFadeIn .25s ease-out; }
        @keyframes hearingFadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

        .hearing-doc { background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #d8e2dd; overflow: hidden; max-width: 1100px; margin: 0 auto; }

        .hearing-doc-header { background: linear-gradient(135deg, #1b5e20, #2E7D32); color: #fff; padding: 18px 28px; border-bottom: 3px double rgba(255,255,255,0.35); display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
        .hearing-doc-header.mediation    { background: linear-gradient(135deg, #4a148c, #6a1b9a); }
        .hearing-doc-header.conciliation { background: linear-gradient(135deg, #0d47a1, #1565c0); }
        .hearing-doc-header-left { display: flex; align-items: center; gap: 16px; }
        .hearing-doc-seal { width: 52px; height: 52px; border-radius: 50%; background: rgba(255,255,255,0.18); border: 2px solid rgba(255,255,255,0.4); display: flex; align-items: center; justify-content: center; font-size: 1.4rem; flex-shrink: 0; }
        .hearing-doc-title { font-size: 1.15rem; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; }
        .hearing-doc-subtitle { font-size: 0.75rem; opacity: 0.9; margin-top: 3px; letter-spacing: 0.3px; text-transform: uppercase; font-weight: 600; }
        .hearing-doc-ref { text-align: right; font-family: 'Courier New', monospace; }
        .hearing-doc-ref-label { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 1.2px; opacity: 0.85; font-weight: 700; }
        .hearing-doc-ref-value { font-size: 0.95rem; font-weight: 700; margin-top: 2px; padding: 3px 10px; background: rgba(255,255,255,0.2); border-radius: 4px; letter-spacing: 0.5px; display: inline-block; }

        .hearing-doc-body { padding: 24px 28px; background: #fafbfa; }

        .hearing-doc-section { background: #fff; border: 1px solid #d8e2dd; border-radius: 8px; margin-bottom: 18px; overflow: hidden; }
        .hearing-doc-section:last-child { margin-bottom: 0; }
        .hearing-doc-section-title { background: #f3f6f4; border-bottom: 1px solid #d8e2dd; padding: 10px 18px; font-size: 0.78rem; font-weight: 800; color: #1b5e20; text-transform: uppercase; letter-spacing: 1px; display: flex; align-items: center; gap: 10px; justify-content: space-between; }
        .hearing-doc-section-title .section-num { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 50%; background: #2E7D32; color: #fff; font-size: 0.72rem; font-weight: 800; flex-shrink: 0; }
        .hearing-doc-section-title.info    { background: #e3f2fd; color: #0d47a1; border-bottom-color: #bbdefb; }
        .hearing-doc-section-title.info .section-num    { background: #1565c0; }
        .hearing-doc-section-title.warning { background: #fff8e1; color: #e65100; border-bottom-color: #ffe0b2; }
        .hearing-doc-section-title.warning .section-num { background: #e65100; }
        .hearing-doc-section-body { padding: 16px 18px; }

        .hearing-doc-section-title.collapsible { cursor: pointer; user-select: none; transition: background .15s; }
        .hearing-doc-section-title.collapsible:hover { background: #e8eeeb; }
        .hearing-doc-section-title.collapsible .section-arrow { transition: transform 0.2s ease; color: #7a8e85; font-size: 0.85rem; margin-left: 8px; }
        .hearing-doc-section.collapsed .hearing-doc-section-title .section-arrow { transform: rotate(-90deg); }
        .hearing-doc-section.collapsed .hearing-doc-section-body { display: none; }
        .hearing-doc-section-title .section-right { display: flex; align-items: center; gap: 10px; }

        .hearing-doc-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px 24px; }
        .hearing-doc-field { border-bottom: 1px dotted #cfd8dc; padding-bottom: 6px; }
        .hearing-doc-field label { display: block; font-size: 0.62rem; text-transform: uppercase; letter-spacing: 1.2px; color: #7a8e85; font-weight: 700; margin-bottom: 3px; }
        .hearing-doc-field span { display: block; font-size: 0.88rem; color: #1a2b22; font-weight: 600; font-family: 'Courier New', monospace; letter-spacing: 0.2px; }
        .hearing-doc-field span.serif { font-family: 'Segoe UI', Tahoma, sans-serif; letter-spacing: 0; font-weight: 600; }

        .hearing-doc-text-block { background: #f8faf8; border-left: 3px solid #2E7D32; padding: 12px 16px; border-radius: 4px; margin-top: 8px; }
        .hearing-doc-text-block label { display: block; font-size: 0.62rem; text-transform: uppercase; letter-spacing: 1.2px; color: #7a8e85; font-weight: 700; margin-bottom: 6px; }
        .hearing-doc-text-block p { font-size: 0.88rem; color: #1a2b22; line-height: 1.6; margin: 0; }

        .hearing-doc-person-row { display: flex; align-items: center; gap: 12px; padding: 10px 14px; background: #fafbfa; border: 1px solid #e2efe8; border-radius: 6px; margin-bottom: 8px; }
        .hearing-doc-person-row:last-child { margin-bottom: 0; }
        .hearing-doc-person-avatar { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .hearing-doc-person-avatar.complainant { background: #e8f5e9; color: #2E7D32; }
        .hearing-doc-person-avatar.respondent  { background: #fff3e0; color: #ef6c00; }
        .hearing-doc-person-avatar.witness     { background: #e3f2fd; color: #1565c0; }
        .hearing-doc-person-name { font-weight: 700; color: #1a2b22; font-size: 0.9rem; }
        .hearing-doc-person-meta { font-size: 0.72rem; color: #555; margin-top: 3px; display: flex; gap: 12px; flex-wrap: wrap; }
        .hearing-doc-role-tag { display: inline-block; font-size: 0.6rem; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 800; padding: 2px 8px; border-radius: 3px; }
        .hearing-doc-role-tag.complainant { background: #e8f5e9; color: #1b5e20; }
        .hearing-doc-role-tag.respondent  { background: #fff3e0; color: #e65100; }
        .hearing-doc-role-tag.witness     { background: #e3f2fd; color: #0d47a1; }

        .hearing-doc-kp-entry { display: flex; align-items: center; gap: 12px; padding: 10px 14px; background: #fff; border: 1px solid #d8e2dd; border-left: 3px solid #2E7D32; border-radius: 4px; margin-bottom: 8px; }
        .hearing-doc-kp-entry:last-child { margin-bottom: 0; }
        .hearing-doc-kp-icon { width: 36px; height: 36px; border-radius: 6px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-weight: 800; font-size: 0.78rem; font-family: 'Courier New', monospace; background: #e8f5e9; color: #1b5e20; }
        .hearing-doc-kp-body { flex: 1; min-width: 0; }
        .hearing-doc-kp-title { font-weight: 700; font-size: 0.85rem; color: #1a2b22; }
        .hearing-doc-kp-meta { font-size: 0.7rem; color: #666; margin-top: 3px; display: flex; gap: 12px; flex-wrap: wrap; font-family: 'Courier New', monospace; }
        .hearing-doc-kp-btn { padding: 6px 14px; border-radius: 5px; border: none; font-size: 0.72rem; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; letter-spacing: 0.3px; text-transform: uppercase; background: #2E7D32; color: #fff; }
        .hearing-doc-kp-btn:hover { background: #1b5e20; }

        .hearing-doc-action-bar { display: flex; gap: 10px; flex-wrap: wrap; padding: 14px 18px; background: #f3f6f4; border-top: 1px solid #d8e2dd; }
        .hearing-doc-action-btn { padding: 10px 18px; border-radius: 6px; border: none; font-size: 0.82rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all .15s; }
        .hearing-doc-action-btn.back   { background: #e0e0e0; color: #333; margin-right: auto; }
        .hearing-doc-action-btn.primary { background: #2E7D32; color: #fff; }
        .hearing-doc-action-btn.danger  { background: #c62828; color: #fff; }
        .hearing-doc-action-btn.warn    { background: #e65100; color: #fff; }
        .hearing-doc-action-btn.info    { background: #1565c0; color: #fff; }
        .hearing-doc-action-btn.teal    { background: #00838f; color: #fff; }
        .hearing-doc-action-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .hearing-doc-ended-banner { padding: 24px; text-align: center; background: #e8f5e9; border: 1px solid #a5d6a7; border-radius: 8px; margin: 0 0 18px; }
        .hearing-doc-ended-banner i { font-size: 2.4rem; color: #2E7D32; margin-bottom: 10px; display: block; }
        .hearing-doc-ended-banner h3 { margin: 0 0 6px; color: #1b5e20; font-size: 1.1rem; }
        .hearing-doc-ended-banner p { margin: 0; color: #555; font-size: 0.88rem; }

        .hearing-doc-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 12px; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .hearing-doc-chip.safe     { background: #e8f5e9; color: #1b5e20; }
        .hearing-doc-chip.warning  { background: #fff8e1; color: #e65100; }
        .hearing-doc-chip.critical { background: #ffebee; color: #b71c1c; animation: pulseRed 1.5s infinite; }
        .hearing-doc-chip.info     { background: #e3f2fd; color: #0d47a1; }
        .hearing-doc-chip.teal     { background: #e0f7fa; color: #006064; }
        .hearing-doc-chip.muted    { background: #f0f0f0; color: #666; }

        /* Auto-opened attendance CTA */
        .hearing-attn-cta {
            background: linear-gradient(135deg, #1565c0, #1976d2);
            border-radius: 12px;
            padding: 20px 24px;
            color: #fff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            margin-bottom: 18px;
            box-shadow: 0 6px 20px rgba(21,101,192,0.25);
            flex-wrap: wrap;
            animation: ctaPulse 2s infinite;
        }
        @keyframes ctaPulse {
            0%, 100% { box-shadow: 0 6px 20px rgba(21,101,192,0.25), 0 0 0 0 rgba(21,101,192,0.6); }
            50%      { box-shadow: 0 6px 20px rgba(21,101,192,0.25), 0 0 0 12px rgba(21,101,192,0); }
        }
        .hearing-attn-cta.done {
            background: linear-gradient(135deg, #2E7D32, #43a047);
            animation: none;
            box-shadow: 0 4px 14px rgba(46,125,50,0.2);
        }
        .hearing-attn-cta .hac-left { display: flex; align-items: center; gap: 16px; }
        .hearing-attn-cta .hac-icon {
            width: 52px; height: 52px; border-radius: 14px;
            background: rgba(255,255,255,0.2);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; flex-shrink: 0;
        }
        .hearing-attn-cta .hac-title { font-size: 1.05rem; font-weight: 800; margin-bottom: 4px; }
        .hearing-attn-cta .hac-sub { font-size: 0.82rem; opacity: 0.9; line-height: 1.5; }
        .hearing-attn-cta .hac-btn {
            background: #fff;
            color: #1565c0;
            border: none;
            padding: 12px 26px;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.15);
            transition: all .15s;
        }
        .hearing-attn-cta .hac-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,0.25); }

        /* Batch upload */
        .batch-upload-batch { background: #fff; border: 1.5px solid #d8e2dd; border-radius: 10px; padding: 14px 16px; margin-bottom: 12px; transition: all .2s; }
        .batch-upload-batch:last-child { margin-bottom: 0; }
        .batch-upload-batch-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; padding-bottom: 10px; margin-bottom: 10px; border-bottom: 1px dashed #e2efe8; }
        .batch-upload-batch-title { font-weight: 700; color: #1a2b22; font-size: 0.9rem; display: flex; align-items: center; gap: 8px; }
        .batch-upload-batch-desc { font-size: 0.82rem; color: #555; margin-top: 4px; line-height: 1.5; }
        .batch-upload-batch-meta { font-size: 0.7rem; color: #999; margin-top: 4px; display: flex; gap: 12px; }
        .batch-upload-file-row { display: flex; align-items: center; gap: 10px; padding: 8px 12px; background: #f8faf8; border: 1px solid #e2efe8; border-radius: 8px; font-size: 0.82rem; margin-bottom: 6px; }
        .batch-upload-file-row:last-child { margin-bottom: 0; }
        .batch-upload-file-row .buf-name { flex: 1; font-weight: 600; color: #1a2b22; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .batch-upload-file-row .buf-size { font-size: 0.72rem; color: #888; }
        .batch-upload-file-row .buf-open { background: #1565c0; color: #fff; border: none; padding: 4px 10px; border-radius: 5px; font-size: 0.7rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }
        .batch-upload-file-row .buf-del { background: #ffebee; color: #c62828; border: none; padding: 4px 8px; border-radius: 5px; font-size: 0.7rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }
        .batch-upload-file-row .buf-del:hover { background: #c62828; color: #fff; }

        .batch-upload-empty { text-align: center; padding: 20px; color: #999; font-size: 0.85rem; background: #f8faf8; border: 1px dashed #d8e2dd; border-radius: 8px; }

        /* Settlement window banner */
        .settle-window-banner {
            display: flex; justify-content: space-between; align-items: center;
            gap: 14px; padding: 14px 18px;
            background: linear-gradient(135deg, #e0f7fa, #b2ebf2);
            border-left: 5px solid #00838f;
            border-radius: 10px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .settle-window-banner.executory {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border-left-color: #2E7D32;
        }
        .settle-window-banner .sw-label { font-weight: 800; color: #005662; font-size: 0.92rem; display: flex; align-items: center; gap: 8px; }
        .settle-window-banner.executory .sw-label { color: #1b5e20; }
        .settle-window-banner .sw-sub { font-size: 0.8rem; color: #006064; margin-top: 4px; line-height: 1.5; }
        .settle-window-banner.executory .sw-sub { color: #2E7D32; }
        .settle-window-banner .sw-countdown { font-family: 'Courier New', monospace; font-weight: 800; font-size: 1.1rem; color: #c62828; padding: 6px 14px; background: #fff; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); }

        /* Conciliation window banner */
        .concil-window-banner {
            display: flex; justify-content: space-between; align-items: center;
            gap: 14px; padding: 12px 16px;
            background: #e3f2fd; border-left: 4px solid #1565c0;
            border-radius: 10px; font-size: 0.85rem; color: #0d47a1;
            margin-bottom: 14px; flex-wrap: wrap;
        }
        .concil-window-banner.warn   { background:#fff8e1; border-left-color:#f57c00; color:#e65100; }
        .concil-window-banner.danger { background:#ffebee; border-left-color:#c62828; color:#b71c1c; }
        .concil-window-banner .cw-label { font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .concil-window-banner .cw-actions { display: flex; gap: 8px; flex-wrap: wrap; }

        @media (max-width: 768px) {
            .documents-grid { grid-template-columns: 1fr; }
            .document-card-actions { flex-direction: column; }
            .doc-action-btn { width: 100%; }
            .notif-dropdown { width: 300px; }
            .cd-body { padding: 16px; }
            .cd-actions { flex-direction: column-reverse; }
            .dm-grid-2 { grid-template-columns: 1fr; }
            .hearing-doc-header { flex-direction: column; align-items: flex-start; }
            .hearing-doc-action-bar { flex-direction: column-reverse; }
            .hearing-doc-action-btn { width: 100%; justify-content: center; margin-right: 0 !important; }
        }

        .pstmt-status-chip {
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    background: #ffebee;
    color: #c62828;
    border: 1px solid #ffcdd2;
    white-space: nowrap;
    flex-shrink: 0;
}
.pstmt-status-chip.ok {
    background: #e8f5e9;
    color: #2E7D32;
    border-color: #a5d6a7;
}

/* ════════════════════════════════════════════════════════
   CHAIR SAVE HEARING MODAL — Captain-style documentable UI
   ════════════════════════════════════════════════════════ */
.shm-form { text-align: left; font-family: 'Segoe UI', Roboto, sans-serif; font-size: 0.9rem; }
.shm-header {
    color: #fff; padding: 18px 22px;
    display: flex; justify-content: space-between; align-items: center; gap: 14px;
    background: linear-gradient(135deg, #1b5e20, #2E7D32 55%, #43a047);
}
.shm-header-left { display: flex; align-items: center; gap: 14px; }
.shm-header-left > i { font-size: 1.8rem; opacity: 0.9; }
.shm-header-title { font-size: 1.15rem; font-weight: 700; }
.shm-header-sub   { font-size: 0.8rem; opacity: 0.85; margin-top: 2px; }
.shm-header-ref   { text-align: right; }
.shm-header-ref-label { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 1px; opacity: 0.8; }
.shm-header-ref-value { font-size: 0.9rem; font-weight: 700; margin-top: 2px; }

.shm-body { padding: 22px 26px; background: #fff; max-height: 74vh; overflow-y: auto; }

.shm-info {
    background: #e3f2fd; border-left: 4px solid #1565c0;
    border-radius: 10px; padding: 12px 16px; margin-bottom: 16px;
    font-size: 0.82rem; color: #0d47a1;
    display: flex; gap: 10px; align-items: flex-start; line-height: 1.55;
}
.shm-info > i { font-size: 1.05rem; flex-shrink: 0; margin-top: 2px; }

.shm-warning {
    background: #fff8e1; border-left: 4px solid #f57c00;
    border-radius: 10px; padding: 12px 16px; margin-bottom: 16px;
    font-size: 0.82rem; color: #e65100;
    display: flex; gap: 10px; align-items: flex-start; line-height: 1.55;
}
.shm-warning > i { font-size: 1.2rem; flex-shrink: 0; margin-top: 1px; }

.shm-section-title {
    font-weight: 700; font-size: 0.9rem; color: #1a472a;
    margin: 20px 0 12px; display: flex; align-items: center; gap: 8px;
    padding-bottom: 6px; border-bottom: 1px solid #e2efe8;
}
.shm-section-title .shm-step {
    display: inline-flex; align-items: center; justify-content: center;
    width: 24px; height: 24px; border-radius: 50%;
    background: #2E7D32; color: #fff;
    font-size: 0.72rem; font-weight: 700; flex-shrink: 0;
}
.shm-section-title.warn .shm-step { background: #c62828; }

.shm-field { margin-bottom: 16px; }
.shm-field label { display: block; font-weight: 600; font-size: 0.85rem; color: #1a472a; margin-bottom: 6px; }
.shm-field label .req { color: #c62828; }
.shm-field label .opt { color: #999; font-weight: 400; font-size: 0.75rem; }
.shm-field input,
.shm-field select,
.shm-field textarea {
    width: 100%; padding: 10px 12px;
    border: 1.5px solid #cfd8dc; border-radius: 8px;
    font-size: 0.92rem; font-family: inherit;
    box-sizing: border-box; resize: vertical; background: #fff;
}
.shm-field input:focus,
.shm-field select:focus,
.shm-field textarea:focus {
    outline: none; border-color: #2E7D32;
    box-shadow: 0 0 0 3px rgba(46,125,50,0.12);
}

/* Outcome option cards */
.shm-option-group { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 700px) { .shm-option-group { grid-template-columns: 1fr; } }
.shm-option {
    position: relative; display: flex; align-items: flex-start; gap: 12px;
    padding: 14px 16px;
    border: 2px solid #e2efe8; border-radius: 12px; background: #fff;
    cursor: pointer; user-select: none; transition: all .18s;
}
.shm-option:hover { border-color: #a5d6a7; background: #fafff9; transform: translateY(-1px); }
.shm-option.selected { border-color: #2E7D32; background: #f1f8e9; box-shadow: 0 0 0 3px rgba(46,125,50,0.10); }
.shm-option.selected.fail { border-color: #c62828; background: #ffebee; box-shadow: 0 0 0 3px rgba(198,40,40,0.10); }
.shm-option-icon {
    width: 44px; height: 44px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.15rem; flex-shrink: 0; background: #f3f6f4; color: #2E7D32;
}
.shm-option-icon.success { background: #e8f5e9; color: #2E7D32; }
.shm-option-icon.danger  { background: #ffebee; color: #c62828; }
.shm-option-text  { flex: 1; min-width: 0; }
.shm-option-title { font-weight: 700; font-size: 0.92rem; color: #1a2b22; line-height: 1.3; }
.shm-option-desc  { font-size: 0.76rem; color: #667; margin-top: 4px; line-height: 1.45; }
.shm-option-check {
    position: absolute; top: 12px; right: 12px;
    width: 20px; height: 20px; border-radius: 50%;
    border: 2px solid #cfd8dc; background: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.6rem; color: transparent; transition: all .18s;
}
.shm-option.selected .shm-option-check { border-color: #2E7D32; background: #2E7D32; color: #fff; }
.shm-option.selected.fail .shm-option-check { border-color: #c62828; background: #c62828; }

/* Settlement terms textarea (bigger, monospace-ish for legal text) */
.shm-terms-wrap {
    display: none; margin-top: 18px;
    padding: 16px; border-radius: 12px;
    background: #f1f8e9; border: 1.5px solid #a5d6a7;
    animation: shmFadeIn .25s ease-out;
}
.shm-terms-wrap.show { display: block; }
@keyframes shmFadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to   { opacity: 1; transform: translateY(0); }
}
.shm-terms-wrap textarea {
    width: 100%; padding: 12px 14px;
    border: 1.5px solid #a5d6a7; border-radius: 8px;
    font-size: 0.92rem; font-family: inherit;
    resize: vertical; line-height: 1.55; min-height: 150px;
    background: #fff;
}
.shm-terms-wrap textarea:focus {
    outline: none; border-color: #2E7D32;
    box-shadow: 0 0 0 3px rgba(46,125,50,0.12);
}
.shm-terms-hint {
    font-size: 0.75rem; color: #555; margin-top: 6px;
    display: flex; align-items: flex-start; gap: 6px; line-height: 1.5;
}
.shm-terms-hint i { color: #2E7D32; margin-top: 2px; flex-shrink: 0; }

/* Repudiation warning inside terms */
.shm-repudiation-warning {
    margin-top: 14px;
    padding: 12px 14px; border-radius: 10px;
    background: #e0f7fa; border-left: 4px solid #00838f;
    font-size: 0.82rem; color: #006064; line-height: 1.6;
}
.shm-repudiation-warning b { color: #006064; }

/* Conflict warning path */
.shm-nosettle-wrap {
    display: none; margin-top: 18px;
    padding: 16px; border-radius: 12px;
    background: #fff8e1; border: 1.5px solid #ffe0b2;
    animation: shmFadeIn .25s ease-out;
}
.shm-nosettle-wrap.show { display: block; }

/* Custom footer */
.shm-footer {
    display: flex; gap: 10px; justify-content: space-between;
    padding: 14px 22px; background: #f8faf8; border-top: 1px solid #e2efe8;
    flex-wrap: wrap;
}
.shm-footer .shm-footer-right { display: flex; gap: 10px; margin-left: auto; }
.shm-btn {
    padding: 10px 20px; border-radius: 8px;
    font-size: 0.88rem; font-weight: 700; border: none;
    cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
    transition: all .15s;
}
.shm-btn:hover { transform: translateY(-1px); }
.shm-btn:disabled { cursor: not-allowed; transform: none; opacity: 0.55; }
.shm-btn.close   { background: #e0e0e0; color: #555; }
.shm-btn.close:hover { background: #bdbdbd; color: #333; }
.shm-btn.primary { background: #2E7D32; color: #fff; }
.shm-btn.primary:hover { background: #1b5e20; }
.shm-btn.primary.warn   { background: #e65100; }
.shm-btn.primary.warn:hover { background: #bf360c; }

/* Hide Swal native buttons for this modal */
.shm-popup .swal2-actions { display: none !important; }
.shm-popup .swal2-title   { display: none !important; }
.shm-popup .swal2-html-container { margin: 0 !important; padding: 0 !important; }
.shm-reschedule-wrap {
    display: none; margin-top: 18px;
    padding: 16px; border-radius: 12px;
    background: #e0f7fa; border: 1.5px solid #b2ebf2;
    animation: shmFadeIn .25s ease-out;
}
.shm-reschedule-wrap.show { display: block; }

.shm-reschedule-notice {
    margin-top: 12px;
    padding: 10px 12px; border-radius: 8px;
    background: #fff; border-left: 4px solid #00838f;
    font-size: 0.78rem; color: #006064;
    display: flex; gap: 8px; align-items: flex-start; line-height: 1.5;
}
.shm-reschedule-notice i { margin-top: 2px; flex-shrink: 0; }
.shm-reschedule-notice.danger { background:#ffebee; border-left-color:#c62828; color:#b71c1c; }

.shm-input-invalid {
    border-color: #c62828 !important;
    background: #ffebee !important;
}
.shm-input-invalid:focus {
    box-shadow: 0 0 0 3px rgba(198,40,40,0.12) !important;
}

/* ════════════════════════════════════════════════════════════
   CONCILIATION CONTINUE WIZARD (Chairperson)
   Two-phase: decision → schedule
   ════════════════════════════════════════════════════════════ */
.wz-popup {
    border-radius: 16px !important;
    padding: 0 !important;
    overflow: hidden;
    width: 720px !important;
}
.wz-popup .swal2-html-container { margin: 0 !important; padding: 0 !important; }
.wz-popup .swal2-actions { display: none !important; }
.wz-popup .swal2-title { display: none !important; }

.wz-shell {
    text-align: left;
    font-family: 'Segoe UI', Roboto, sans-serif;
    font-size: 0.9rem;
    transition: opacity .18s ease;
}
.wz-shell.wz-fade-out { opacity: 0; }
.wz-shell.wz-fade-in  { animation: wzFadeIn .3s ease-out; }
@keyframes wzFadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

/* ── Header ── */
.wz-header {
    color: #fff;
    padding: 18px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}
.wz-header.red  { background: linear-gradient(135deg, #7f1010, #b71c1c 55%, #e53935); }
.wz-header.teal { background: linear-gradient(135deg, #005662, #00838f 55%, #00acc1); }

.wz-header-left { display: flex; align-items: center; gap: 14px; }
.wz-header-left > i { font-size: 1.7rem; opacity: 0.95; }
.wz-header-title { font-size: 1.08rem; font-weight: 700; letter-spacing: 0.2px; }
.wz-header-sub   { font-size: 0.78rem; opacity: 0.9; margin-top: 3px; }

.wz-header-step { display: flex; gap: 8px; flex-wrap: wrap; }
.wz-step-pill {
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.4px;
    text-transform: uppercase;
    background: rgba(255,255,255,0.18);
    color: #fff;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border: 1px solid rgba(255,255,255,0.25);
}
.wz-step-pill.active { background: #fff; color: #b71c1c; border-color: #fff; }
.wz-header.teal .wz-step-pill.active { color: #005662; }
.wz-step-pill.done { background: rgba(255,255,255,0.35); color: #fff; }

/* ── Body ── */
.wz-body {
    padding: 22px 24px;
    background: #fafbfa;
    max-height: 68vh;
    overflow-y: auto;
}

/* ── Info banners ── */
.wz-info-banner {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    padding: 12px 16px;
    border-radius: 10px;
    background: #fff3e0;
    border-left: 4px solid #f57c00;
    color: #8a3e00;
    font-size: 0.85rem;
    line-height: 1.55;
    margin-bottom: 14px;
}
.wz-info-banner.ok   { background: #e8f5e9; border-left-color: #2E7D32; color: #1b5e20; }
.wz-info-banner.warn { background: #fff8e1; border-left-color: #f57c00; color: #e65100; }
.wz-info-banner > i { font-size: 1.2rem; flex-shrink: 0; margin-top: 1px; }
.wz-info-sub { font-size: 0.78rem; margin-top: 4px; opacity: 0.9; }

/* ── Legal note block ── */
.wz-legal-note {
    background: #fff8e1;
    border: 1.5px solid #ffe0b2;
    border-left: 4px solid #c62828;
    border-radius: 10px;
    padding: 14px 16px;
    font-size: 0.82rem;
    color: #4e342e;
    line-height: 1.6;
    margin-bottom: 16px;
}
.wz-legal-title {
    font-weight: 800;
    color: #b71c1c;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.83rem;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}
.wz-legal-note ul { margin: 0; padding-left: 20px; }
.wz-legal-note li { margin-bottom: 4px; }

/* ── Party cards ── */
.wz-party-card {
    background: #fff;
    border: 1.5px solid #e2efe8;
    border-left: 4px solid #c62828;
    border-radius: 12px;
    padding: 16px 18px;
    margin-bottom: 14px;
    transition: border-color .15s, box-shadow .15s;
}
.wz-party-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.05); }

.wz-party-head {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 14px;
}
.wz-party-avatar {
    width: 40px; height: 40px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.95rem; flex-shrink: 0;
}
.wz-party-avatar.complainant { background: #e8f5e9; color: #2E7D32; }
.wz-party-avatar.respondent  { background: #fff3e0; color: #ef6c00; }

.wz-party-meta { flex: 1; min-width: 0; }
.wz-party-name { font-weight: 700; font-size: 0.92rem; color: #1a2b22; }
.wz-party-role {
    font-size: 0.66rem; text-transform: uppercase;
    font-weight: 700; letter-spacing: 0.4px;
    margin-top: 3px;
}
.wz-party-role.complainant { color: #2E7D32; }
.wz-party-role.respondent  { color: #ef6c00; }

.wz-kp-link {
    background: #fff3e0; color: #e65100;
    border: 1px solid #ffe0b2; border-radius: 6px;
    padding: 6px 12px; font-size: 0.72rem; font-weight: 700;
    cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
    transition: all .15s;
}
.wz-kp-link:hover { background: #e65100; color: #fff; border-color: #e65100; }

/* ── Fields ── */
.wz-field { margin-bottom: 14px; }
.wz-field:last-child { margin-bottom: 0; }
.wz-field label {
    display: block; font-weight: 600; font-size: 0.82rem;
    color: #1a472a; margin-bottom: 6px;
}
.wz-field input,
.wz-field textarea {
    width: 100%; padding: 10px 12px;
    border: 1.5px solid #cfd8dc; border-radius: 8px;
    font-size: 0.9rem; font-family: inherit;
    box-sizing: border-box; resize: vertical;
    background: #fff;
}
.wz-field input:focus,
.wz-field textarea:focus {
    outline: none; border-color: #2E7D32;
    box-shadow: 0 0 0 3px rgba(46,125,50,0.12);
}
.wz-field-row {
    display: grid; grid-template-columns: 1fr 1fr; gap: 14px;
    margin-bottom: 14px;
}
@media (max-width: 560px) { .wz-field-row { grid-template-columns: 1fr; } }

.wz-req { color: #c62828; }
.wz-opt { color: #999; font-weight: 400; font-size: 0.72rem; }

.wz-hint {
    font-size: 0.75rem; color: #666; margin: -4px 0 12px;
    display: flex; align-items: center; gap: 8px;
    padding: 6px 10px; background: #f8faf8; border-radius: 6px;
}
.wz-hint i { color: #2E7D32; }

.wz-conflict {
    display: none; align-items: flex-start; gap: 8px;
    padding: 10px 14px; margin-bottom: 8px;
    background: #ffebee; border: 1px solid #ef9a9a;
    border-radius: 8px; color: #b71c1c; font-size: 0.8rem;
}
.wz-conflict.show { display: flex; }
.wz-conflict > i { margin-top: 2px; flex-shrink: 0; }

.wz-available {
    display: none; align-items: flex-start; gap: 8px;
    padding: 10px 14px; margin-bottom: 8px;
    background: #e8f5e9; border: 1px solid #a5d6a7;
    border-radius: 8px; color: #1b5e20; font-size: 0.8rem;
}
.wz-available.show { display: flex; }
.wz-available > i { margin-top: 2px; flex-shrink: 0; }

/* ── Decision radios ── */
.wz-decision-label {
    font-weight: 700; font-size: 0.82rem;
    color: #1a472a; margin: 4px 0 8px;
}
.wz-radio-row {
    display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
}
@media (max-width: 620px) { .wz-radio-row { grid-template-columns: 1fr; } }

.wz-radio {
    position: relative;
    display: flex; align-items: flex-start; gap: 10px;
    padding: 12px 14px;
    border: 2px solid #e2efe8; border-radius: 10px;
    background: #fff; cursor: pointer;
    transition: all .15s;
}
.wz-radio:hover { border-color: #cfd8dc; background: #fafbfa; }
.wz-radio.selected.justified   { border-color: #2E7D32; background: #f1f8e9; }
.wz-radio.selected.unjustified { border-color: #c62828; background: #ffebee; }
.wz-radio input { display: none; }

.wz-radio-icon {
    width: 32px; height: 32px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; font-size: 0.9rem;
}
.wz-radio.justified .wz-radio-icon   { background: #e8f5e9; color: #2E7D32; }
.wz-radio.unjustified .wz-radio-icon { background: #ffebee; color: #c62828; }

.wz-radio-text { flex: 1; min-width: 0; }
.wz-radio-title { font-weight: 700; font-size: 0.86rem; color: #1a2b22; }
.wz-radio-sub   { font-size: 0.72rem; color: #667; margin-top: 2px; }

.wz-radio-legal {
    font-size: 0.68rem; font-weight: 600;
    margin-top: 6px; padding: 5px 8px;
    border-radius: 6px;
    display: flex; gap: 5px; align-items: flex-start;
    line-height: 1.45;
}
.wz-radio-legal i { margin-top: 1px; flex-shrink: 0; }
.wz-radio-legal.ok   { background: #e8f5e9; color: #1b5e20; }
.wz-radio-legal.bad  { background: #ffebee; color: #b71c1c; }
.wz-radio-legal.bad.warn { background: #fff8e1; color: #e65100; }

/* ── Footer ── */
.wz-footer {
    display: flex; gap: 10px; justify-content: space-between;
    align-items: center;
    padding: 14px 22px;
    background: #fff;
    border-top: 1px solid #e2efe8;
    flex-wrap: wrap;
}
.wz-footer-right { display: flex; gap: 10px; margin-left: auto; }

.wz-btn {
    padding: 10px 20px; border-radius: 8px;
    font-size: 0.86rem; font-weight: 700; border: none;
    cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
    transition: all .15s;
}
.wz-btn:hover:not(:disabled) { transform: translateY(-1px); }
.wz-btn:disabled { cursor: not-allowed; opacity: 0.6; transform: none; }

.wz-btn.close   { background: #f0f0f0; color: #555; }
.wz-btn.close:hover { background: #e0e0e0; color: #333; }

.wz-btn.primary { background: #2E7D32; color: #fff; }
.wz-btn.primary:hover { background: #1b5e20; }
.wz-btn.primary.danger { background: #b71c1c; }
.wz-btn.primary.danger:hover { background: #7f1010; }
.wz-btn.primary.teal { background: #00838f; }
.wz-btn.primary.teal:hover { background: #005662; }

/* ── Inline error ── */
.wz-inline-error {
    display: none;
    align-items: center; gap: 8px;
    padding: 10px 14px;
    background: #ffebee; border: 1px solid #ef9a9a;
    border-left: 4px solid #c62828; border-radius: 8px;
    color: #b71c1c; font-size: 0.84rem;
    margin: 0 24px 12px;
}
.wz-inline-error.show { display: flex; }
.wz-inline-error > i { flex-shrink: 0; }

/* ── Scrollbar polish for body ── */
.wz-body::-webkit-scrollbar { width: 8px; }
.wz-body::-webkit-scrollbar-track { background: transparent; }
.wz-body::-webkit-scrollbar-thumb { background: #c8d6cf; border-radius: 4px; }
.wz-body::-webkit-scrollbar-thumb:hover { background: #a8bcb2; }
.wz-shell {
    text-align: left;
    font-family: 'Segoe UI', Roboto, sans-serif;
    font-size: 0.9rem;
    transition: opacity .18s ease;
    display: flex;
    flex-direction: column;
    max-height: 90vh;
}

.wz-body {
    padding: 22px 24px;
    background: #fafbfa;
    overflow-y: auto;
    flex: 1 1 auto;
    max-height: none;
}

.wz-footer {
    display: flex; gap: 10px; justify-content: space-between;
    align-items: center;
    padding: 14px 22px;
    background: #fff;
    border-top: 1px solid #e2efe8;
    flex-wrap: wrap;
    flex-shrink: 0;
}
.case-filter-bar { display:flex; gap:10px; flex-wrap:wrap; align-items:center;
    padding:10px 12px; background:#f8faf8; border:1px solid #e2efe8;
    border-radius:10px; margin-bottom:14px; }
.case-filter-label { display:flex; align-items:center; gap:6px;
    font-size:0.78rem; color:#1a472a; font-weight:700; }
.case-filter-select { padding:6px 10px; border:1.5px solid #cfd8dc; border-radius:8px;
    font-size:0.8rem; background:#fff; }
.case-filter-select:focus { outline:none; border-color:#2E7D32;
    box-shadow:0 0 0 3px rgba(46,125,50,0.12); }
.case-filter-search { position:relative; flex:1; min-width:180px; }
.case-filter-search > i { position:absolute; left:10px; top:50%; transform:translateY(-50%);
    color:#999; font-size:0.78rem; }
.case-filter-search > input { width:100%; padding:7px 10px 7px 30px;
    border:1.5px solid #cfd8dc; border-radius:8px; font-size:0.8rem; box-sizing:border-box; }
.case-filter-search > input:focus { outline:none; border-color:#2E7D32;
    box-shadow:0 0 0 3px rgba(46,125,50,0.12); }
.case-filter-reset { padding:6px 12px; border:1px solid #cfd8dc; border-radius:8px;
    background:#fff; color:#555; font-size:0.78rem; font-weight:600; cursor:pointer; }
.case-filter-reset:hover { background:#f0f0f0; }
.case-filter-count { font-size:0.75rem; color:#666; margin-left:auto; }

/* ── Scope tabs: Active / Previously Handled / All ── */
.case-scope-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 12px;
    flex-wrap: wrap;
    border-bottom: 2px solid #e2efe8;
    padding-bottom: 4px;
}
.case-scope-tab {
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    padding: 8px 16px;
    font-size: 0.85rem;
    font-weight: 700;
    color: #667;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all .15s;
    border-radius: 6px 6px 0 0;
    margin-bottom: -6px;
}
.case-scope-tab:hover { background: #f3f6f4; color: #1b5e20; }
.case-scope-tab.active {
    color: #1b5e20;
    border-bottom-color: #2E7D32;
    background: #f1f8e9;
}
.case-scope-tab .case-scope-count {
    background: #e0e0e0;
    color: #444;
    padding: 1px 8px;
    border-radius: 10px;
    font-size: 0.72rem;
    font-weight: 700;
    min-width: 22px;
    text-align: center;
}
.case-scope-tab.active .case-scope-count {
    background: #2E7D32;
    color: #fff;
}
/* ── Timeline bar ── */
.case-timeline-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    padding: 10px 12px;
    background: #f0f7ff;
    border: 1px solid #bbdefb;
    border-left: 4px solid #1565c0;
    border-radius: 10px;
    margin-bottom: 10px;
}
.case-timeline-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.78rem;
    color: #0d47a1;
    font-weight: 700;
}
.case-timeline-hint {
    font-size: 0.72rem;
    color: #4a6572;
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

    .shm-branch-wrap,
.shm-arbitration-wrap {
    display: none; margin-top: 18px;
    padding: 16px; border-radius: 12px;
    background: #e8f5e9; border: 1.5px solid #a5d6a7;
    animation: shmFadeIn .25s ease-out;
}
.shm-branch-wrap.show { display: block; }
.shm-arbitration-wrap { background: #e3f2fd; border-color: #90caf9; }
.shm-arbitration-wrap.show { display: block; }

/* ═══════════════════════════════════════════════════════════
   LUPON DASHBOARD — analytics layout (dx-*)
   ═══════════════════════════════════════════════════════════ */
.dx { display: flex; flex-direction: column; gap: 14px; }
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

.dx-grid { display: grid; grid-template-columns: 1fr; gap: 14px; }
.dx-card {
    background: #fff; border: 1px solid #e2efe8; border-radius: 16px;
    padding: 14px 16px; display: flex; flex-direction: column;
    min-height: 0; min-width: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}
.dx-card-h { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; flex-shrink: 0; }
.dx-card-h h4 { margin: 0; font-size: 0.9rem; color: #1a472a; display: flex; align-items: center; gap: 8px; }
.dx-card-h h4 i { color: #2E7D32; }
.dx-chart { position: relative; height: 240px; min-height: 0; }

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
.dx-day.sel { border-color: #2E7D32; box-shadow: 0 0 0 2px rgba(46,125,50,0.15); }
.dx-dots { display: flex; gap: 2px; height: 6px; align-items: center; }
.dx-dot { width: 5px; height: 5px; border-radius: 50%; }
.dx-dot.hearing { background: #8e24aa; }
.dx-day.today .dx-dot { box-shadow: 0 0 0 1px #fff; }
.dx-legend { display: flex; gap: 14px; font-size: 0.68rem; color: #6b7a72; margin: 8px 0 6px; flex-shrink: 0; flex-wrap: wrap; }
.dx-legend span { display: inline-flex; align-items: center; gap: 5px; }
.dx-agenda { border-top: 1px dashed #d7e2dc; padding-top: 8px; max-height: 140px; overflow-y: auto; flex-shrink: 0; }
.dx-agenda-h { font-size: 0.72rem; font-weight: 800; color: #1a472a; text-transform: uppercase; letter-spacing: .3px; margin-bottom: 6px; }
.dx-ev {
    display: flex; gap: 9px; align-items: center; padding: 6px 8px; border-radius: 9px;
    cursor: pointer; border-left: 4px solid; margin-bottom: 5px; background: #fafbfa;
}
.dx-ev:hover { background: #f1f8e9; }
.dx-ev.hearing { border-left-color: #8e24aa; }
.dx-ev-time { font-size: 0.68rem; font-weight: 800; color: #5f7f6e; min-width: 60px; }
.dx-ev-body { min-width: 0; flex: 1; }
.dx-ev-title { font-size: 0.78rem; font-weight: 700; color: #1a2b22; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dx-ev-sub { font-size: 0.68rem; color: #7a8a82; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dx-empty { font-size: 0.78rem; color: #99a69f; text-align: center; padding: 8px 0; }

.dx-recent-list { flex: 1; min-height: 0; overflow-y: auto; }
.dx-recent-item {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 12px; border-radius: 10px;
    cursor: pointer; transition: background .15s;
    border-bottom: 1px solid #f0f4f2;
}
.dx-recent-item:last-child { border-bottom: none; }
.dx-recent-item:hover { background: #f1f8e9; }
.dx-recent-icon { width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 0.95rem; }
.dx-recent-body { flex: 1; min-width: 0; }
.dx-recent-title { font-weight: 700; font-size: 0.85rem; color: #1a2b22;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dx-recent-meta { font-size: 0.7rem; color: #7a8a82; margin-top: 3px;
    display: flex; gap: 12px; flex-wrap: wrap; }
.dx-recent-status {
    padding: 3px 10px; border-radius: 12px;
    font-size: 0.65rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.4px;
    white-space: nowrap; flex-shrink: 0;
}

/* Mini announcement rows */
.dx-annc-mini {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 12px; border-radius: 10px; cursor: pointer;
    background: #fafbfa; border: 1px solid #e2efe8;
    margin-bottom: 8px; transition: all .15s;
}
.dx-annc-mini:hover { background: #f1f8e9; border-color: #a5d6a7; transform: translateX(2px); }
.dx-annc-mini:last-child { margin-bottom: 0; }
.dx-annc-mini-icon {
    width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
    background: linear-gradient(135deg, #e65100, #f57c00); color: #fff;
    display: flex; align-items: center; justify-content: center; font-size: 1rem;
}
.dx-annc-mini-title { font-weight: 700; font-size: 0.85rem; color: #1a2b22;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dx-annc-mini-meta { font-size: 0.7rem; color: #7a8a82; margin-top: 3px; }

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



/* ── Captain layout: BIG read-only announcements on the left ── */
.dx-annc .dx-annc-body { min-height: 340px; }
.dx-annc .annc-card { cursor: pointer; }
.dx-annc .dx-annc-body .annc-card-body { padding: 22px 26px 46px; }
.dx-annc .dx-annc-body .annc-card-title { font-size: 1.45rem; line-height: 1.25; -webkit-line-clamp: 3; margin-bottom: 10px; }
.dx-annc .dx-annc-body .annc-card-text  { font-size: 0.95rem; line-height: 1.6; -webkit-line-clamp: 5; }
.dx-annc .dx-annc-body .annc-card-meta  { margin-top: 14px; font-size: 0.78rem; }
.dx-annc .dx-annc-body .annc-card-top   { top: 18px; left: 20px; right: 20px; }
.dx-annc .dx-annc-body .annc-dots       { bottom: 14px; }
.dx-annc .dx-annc-body .annc-nav-btn    { width: 42px; height: 42px; font-size: 0.95rem; }
.dx-annc .dx-annc-body .annc-card-image.fallback::after { font-size: 7rem; }
.dx-readmore {
    display: inline-flex; align-items: center; gap: 6px; margin-top: 12px;
    font-size: 0.74rem; font-weight: 800; letter-spacing: .3px; text-transform: uppercase;
    color: #fff; background: rgba(255,255,255,0.22); border: 1px solid rgba(255,255,255,0.35);
    padding: 5px 12px; border-radius: 14px; backdrop-filter: blur(6px);
}
.dx-count-chip { font-size: 0.7rem; font-weight: 800; padding: 3px 10px; border-radius: 12px; background: #ffebee; color: #c62828; }
.dx-count-chip.ok { background: #e8f5e9; color: #2e7d32; }
/* Full-bleed announcement modal — image IS the modal */
.annc-full-popup {
    padding: 0 !important;
    background: transparent !important;
    border-radius: 18px !important;
    overflow: hidden !important;
    width: 640px !important;
    max-width: 95vw !important;
}
.annc-full-popup .swal2-html-container {
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
}
.annc-full-popup .swal2-title,
.annc-full-popup .swal2-actions {
    display: none !important;
}
.annc-full-popup .swal2-close {
    color: #fff !important;
    z-index: 20 !important;
    text-shadow: 0 1px 4px rgba(0,0,0,0.6);
}

/* ── layout (same as Captain dashboard) ── */
@media (min-width: 700px) {
    .dx-kpis { grid-template-columns: repeat(3, 1fr); }
    .dx-grid { grid-template-columns: repeat(2, 1fr); }
    .dx-annc  { grid-column: span 2; }
    .dx-annc .dx-annc-body { min-height: 380px; }
    .dx-trend { grid-column: span 2; }
    .dx-chart { height: 280px; }
    .dx-recent { grid-column: span 2; min-height: 280px; }
}
@media (min-width: 1200px) {
    .dx { height: calc(100dvh - 150px); min-height: 700px; }
    .dx-kpis { grid-template-columns: repeat(6, 1fr); flex-shrink: 0; }
    .dx-kpi { flex-direction: column; align-items: flex-start; gap: 8px; padding: 10px 12px; }
    .dx-kpi-ic { width: 32px; height: 32px; font-size: 0.88rem; }
    .dx-top { flex-shrink: 0; }
    .dx-grid { flex: 1; min-height: 0; grid-template-columns: repeat(12, 1fr); grid-template-rows: 1fr 1fr; }
    .dx-annc   { grid-column: span 5; grid-row: span 2; }
    .dx-trend  { grid-column: span 4; }
    .dx-status { grid-column: span 3; }
    .dx-cal    { grid-column: span 4; }
    .dx-recent { grid-column: span 3; min-height: 0; }
    .dx-chart { flex: 1; height: auto; }
    .dx-annc .dx-annc-body { min-height: 0; }
    .dx-annc .dx-annc-body .annc-card-title { font-size: 1.7rem; }
    .dx-cal .dx-agenda { max-height: 96px; }
    .dx-cal .dx-cal-grid { min-height: 130px; }
}
@media (max-width: 480px) {
    .dx-top-btn span { display: none; }
    .dx-top-btn { padding: 9px 12px; }
    .dx-cal-title { min-width: 96px; }
    .dx-annc .dx-annc-body .annc-card-title { font-size: 1.2rem; }
}
    </style>
</head>
<body>
<button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
<div class="sidebar-overlay hide" id="sidebarOverlay"></div>

<div class="dashboard-container">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="brand"><div class="logo-container"><img src="../../logo.jpg" alt="Barangay Logo" onerror="this.onerror=null; this.parentElement.innerHTML='<i class=\'fas fa-landmark\'></i>';"></div><h2>BRITE</h2></div>
            <div class="sidebar-sub">San Bartolome, Sto Tomas, Pampanga</div>
        </div>
        <div class="nav-menu">
            <div class="nav-item active" data-view="dashboard" onclick="renderDashboard()">
                <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
            </div>
            <div class="nav-item" data-view="complaints" onclick="renderMyComplaints()">
                <i class="fas fa-gavel"></i><span>My Complaints</span>
                <?php if ($stats['needs_assign'] > 0): ?>
                    <span class="nav-badge" id="myComplaintsBadge"><?php echo $stats['needs_assign']; ?></span>
                <?php else: ?>
                    <span class="nav-badge" id="myComplaintsBadge" style="display:none;">0</span>
                <?php endif; ?>
            </div>
            <div class="nav-item" onclick="window.location.href='hearings.php'"><i class="fas fa-calendar-alt"></i><span>Hearings</span></div>
            <div class="nav-item" onclick="window.location.href='mediation_report.php'"><i class="fas fa-chart-bar"></i><span>Mediation Report</span></div>
        </div>
        <div class="sidebar-footer">
            <div class="admin-badge">
                <div class="mini-avatar"><i class="fas fa-gavel"></i></div>
                <div class="admin-info">
                    <h5><?php echo htmlspecialchars($admin['full_name']); ?></h5>
                    <p>Lupon Member</p>
                </div>
            </div>
        </div>
    </aside>

    <main class="main-content">
        <div class="top-header">
            <div class="page-title"><h1>Lupon Dashboard</h1><p>Case Management &amp; Conciliation</p></div>

            <div class="header-right-group">
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
                        <div class="notif-select-bar">
                            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                                <input type="checkbox" id="notifSelectAll">
                                <span>Select all</span>
                            </label>
                            <button class="notif-delete-btn" id="notifDeleteBtn" type="button">
                                <i class="fas fa-trash"></i> Delete selected
                            </button>
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
                    <div class="profile-text"><div class="name"><?php echo htmlspecialchars($admin['full_name']); ?></div><div class="role">Lupon Member</div></div>
                    <div class="avatar-container"><div class="avatar-fallback"><i class="fas fa-gavel"></i></div></div>
                    <div class="profile-dropdown" id="profileDropdown">
                        <div class="dropdown-header"><div class="user-name"><?php echo htmlspecialchars($admin['full_name']); ?></div><div class="user-email"><?php echo htmlspecialchars($admin['email']); ?></div></div>
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
<script>
// ============================================================
// GLOBALS
// ============================================================
const ADMIN_ID = <?php echo (int)$admin_id; ?>;

// ============================================================
// HELPERS
// ============================================================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

window.__printModalSeen = window.__printModalSeen || new Set();
function maybeOpenSecretaryPrintModal(notifications) {
    if (!Array.isArray(notifications) || !notifications.length) return;
    if (Swal.isVisible()) return;

    // Only consider UNREAD notifications — once read, never fire again.
    const candidate = notifications.find(n =>
        Number(n.is_read) === 0
        && !window.__printModalSeen.has(String(n.id))
        && String(n.title || '').toUpperCase().includes('PRINT REQUIRED')
    );
    if (!candidate) return;

    // Latch locally so this poll-loop never re-fires the same notification
    // on this page load.
    window.__printModalSeen.add(String(candidate.id));

    // ✅ NEW: mark it read on the server IMMEDIATELY so a page refresh
    //         does not re-open the modal for the same notification.
    fetch('../admin_complaint_ajax.php', {
        method: 'POST',
        body: new URLSearchParams({
            action: 'mark_notification_read',
            notification_id: candidate.id
        })
    }).catch(() => {});

    const hasDoc = candidate.document_path && String(candidate.document_path).trim() !== '';
    const pdfUrl = hasDoc
        ? (String(candidate.document_path).startsWith('http')
            ? candidate.document_path
            : '../../' + String(candidate.document_path).replace(/^\/+/, ''))
        : '';

    Swal.fire({
        icon: 'warning',
        title: 'Print Required — KP Form',
        html: `<div style="text-align:left;font-size:0.9rem;">
            <p>${escapeHtml(candidate.message).replace(/\n/g, '<br>')}</p>
            <div style="margin-top:14px;padding:12px 14px;background:#fff3e0;border-left:4px solid #f57c00;border-radius:8px;font-size:0.82rem;color:#e65100;line-height:1.6;">
                <b><i class="fas fa-print"></i> Physical delivery required.</b><br>
                The named party(ies) do not have a resident account, so the portal
                notification could not reach them. Print this KP form and hand it
                over <b>immediately</b> — you do not need to wait for the hearing to start.
            </div>
        </div>`,
        confirmButtonText: pdfUrl
            ? '<i class="fas fa-file-pdf"></i> Open PDF &amp; Print'
            : 'Understood',
        confirmButtonColor: '#e65100',
        allowOutsideClick: false,
        width: 640,
        didOpen: () => {
            const btn = Swal.getConfirmButton();
            if (!btn) return;
            btn.onclick = async () => {
                if (pdfUrl) {
                    window.open(pdfUrl, '_blank');
                }
                Swal.close();
                // Nudge the bell so the badge count updates immediately.
                fetchNotifications();
            };
        }
    });
}

function getStatementStatus() {
    const caseDetail = window.__hearing && window.__hearing.cachedCaseDetail;
    if (!caseDetail || !caseDetail.success) return { cs: false, rs: false, complete: false };

    const hearings = (caseDetail.hearings || [])
        .filter(h => h.session_type === 'conciliation')
        .sort((a, b) => (Number(b.id) || 0) - (Number(a.id) || 0));

    const latest = hearings[0];
    const sr = (latest && latest.session_record) ? latest.session_record : {};
    const cs = sr.complainant_statement || null;
    const rs = sr.respondent_statement  || null;

    const csOk = !!(cs && (String(cs.text || '').trim() !== '' || cs.audio_path));
    const rsOk = !!(rs && (String(rs.text || '').trim() !== '' || rs.audio_path));

    return { cs: csOk, rs: rsOk, complete: csOk && rsOk };
}

function hearingDocSection(id, opts) {
    const collapsed = opts.collapsed === true ? 'collapsed' : '';
    const variant = opts.variant ? ` ${opts.variant}` : '';
    const badgeHtml = opts.badge || '';
    return `
    <div class="hearing-doc-section ${collapsed}" id="${id}">
        <div class="hearing-doc-section-title collapsible${variant}" onclick="toggleHearingSection('${id}')">
            <span style="display:flex;align-items:center;gap:10px;">
                <span class="section-num">${opts.num}</span> ${escapeHtml(opts.title)}
            </span>
            <span class="section-right">
                ${badgeHtml}
                <i class="fas fa-chevron-down section-arrow"></i>
            </span>
        </div>
        <div class="hearing-doc-section-body">${opts.bodyHtml}</div>
    </div>`;
}

function toggleHearingSection(id) {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('collapsed');
}

const profileArea = document.getElementById('profileArea');
const profileDropdown = document.getElementById('profileDropdown');
const logoutBtn = document.getElementById('logoutBtn');
profileArea.addEventListener('click', (e) => { e.stopPropagation(); profileDropdown.classList.toggle('show'); });
document.addEventListener('click', (e) => { if (!profileArea.contains(e.target)) profileDropdown.classList.remove('show'); });
logoutBtn.addEventListener('click', (e) => { e.preventDefault(); if (confirm('Logout?')) document.getElementById('logoutForm').submit(); });

const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
menuToggle.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('hide'); });
overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.add('hide'); });

function setActiveNav(view) {
    document.querySelectorAll('.nav-item[data-view]').forEach(el => {
        el.classList.toggle('active', el.getAttribute('data-view') === view);
    });
}

// ============================================================
// LIVE COUNTDOWN
// ============================================================
function formatDuration(ms) {
    if (ms <= 0) return { d:0,h:0,m:0,total:0 };
    const totalSec = Math.floor(ms / 1000);
    const d = Math.floor(totalSec / 86400);
    const h = Math.floor((totalSec % 86400) / 3600);
    const m = Math.floor((totalSec % 3600) / 60);
    return { d, h, m, total: totalSec };
}
function renderCountdown(el) {
    const iso = el.getAttribute('data-deadline');
    if (!iso) return;
    const deadline = new Date(iso.replace(' ', 'T'));
    if (isNaN(deadline.getTime())) { el.innerHTML = '<span class="status-pill critical">Invalid</span>'; return; }
    const diff = deadline.getTime() - Date.now();
    if (diff <= 0) { el.innerHTML = `<span class="status-pill critical"><i class="fas fa-exclamation-triangle"></i> EXPIRED</span>`; return; }
    const t = formatDuration(diff);
    let cls = 'safe';
    if (t.d <= 3) cls = 'critical';
    else if (t.d <= 7) cls = 'warning';
    let label = t.d > 0 ? `${t.d}d ${t.h}h ${t.m}m` : (t.h > 0 ? `${t.h}h ${t.m}m` : `${t.m}m`);
    el.innerHTML = `<span class="status-pill ${cls}"><i class="fas fa-hourglass-half"></i> ${label} left</span>`;
}
function startLiveCountdowns() {
    if (window.__liveTimerInterval) clearInterval(window.__liveTimerInterval);
    const tick = () => document.querySelectorAll('.live-countdown[data-deadline]').forEach(renderCountdown);
    tick();
    window.__liveTimerInterval = setInterval(tick, 1000);
}

// ============================================================
// GLOBAL POLLING — real-time list refresh
// ============================================================
window.__listPollTimer = null;

function startListPolling() {
    stopListPolling();
    window.__listPollTimer = setInterval(async () => {
        const navActive = document.querySelector('.nav-item[data-view="complaints"].active');
        if (!navActive) return;
        if (window.__hearing && window.__hearing.active) return;
        if (document.getElementById('hearingPanelOverlay')) return;

        try {
            const r = await fetch('../admin_complaint_ajax.php?action=get_my_assigned_complaints');
            const d = await r.json();
            if (!d || !d.success) return;
            const newList = d.complaints || [];
            const oldSig = JSON.stringify((cachedCases || []).map(x => [x.id, x.status, x.updated_at, x.pangkat_role || '', x.has_pending_roles]));
            const newSig = JSON.stringify(newList.map(x => [x.id, x.status, x.updated_at, x.pangkat_role || '', x.has_pending_roles]));
            if (oldSig === newSig) return;
            cachedCases = newList;
            cachedCases.forEach(row => {
                if (!row.explain_deadline) row.explain_deadline = row.concil_absence_explain_deadline || null;
            });

            let needsAssign = 0;
            cachedCases.forEach(x => {
                const isPending = x.has_pending_roles === true;
                const isFirst = x.is_first_member === true;
                if (isPending && isFirst && (x.status === 'pangkat_constituted' || x.status === 'pangkat_scheduled')) needsAssign++;
            });
            const badge = document.getElementById('myComplaintsBadge');
            if (badge) {
                if (needsAssign > 0) { badge.style.display = 'inline-block'; badge.textContent = needsAssign; }
                else badge.style.display = 'none';
            }

            renderFilteredCases();
        } catch (e) {}
    }, 5000);
}

function stopListPolling() {
    if (window.__listPollTimer) {
        clearInterval(window.__listPollTimer);
        window.__listPollTimer = null;
    }
}

// ============================================================
// DASHBOARD — scoped to this Lupon member
// ============================================================
const DX = <?php echo $dxJson ?: '{}'; ?>;

const LUPON_CASE_GROUPS = [
    { label: 'For Scheduling', color: '#f57c00', statuses: ['pangkat_constituted'] },
    { label: 'Scheduled',      color: '#6a1b9a', statuses: ['pangkat_scheduled'] },
    { label: 'Settled',        color: '#2E7D32', statuses: ['settled', 'settled_pending'] },
    { label: 'Unresolved',     color: '#c62828', statuses: ['unresolved', 'failed_conciliation_final'] },
    { label: 'Certificate',    color: '#b71c1c', statuses: ['certificate_issued'] },
];

let __dxCharts = {};
const __dxToday = new Date();
let __dxCal = { y: __dxToday.getFullYear(), m: __dxToday.getMonth(), sel: null };

function dxPad(n) { return String(n).padStart(2, '0'); }
function dxYmd(y, m, d) { return `${y}-${dxPad(m+1)}-${dxPad(d)}`; }
function dxDestroy(key) { if (__dxCharts[key]) { try { __dxCharts[key].destroy(); } catch (e) {} delete __dxCharts[key]; } }

function renderDashboard() {
    setActiveNav('dashboard');
    stopListPolling();
    if (window.__hearing && window.__hearing.pollTimer) {
        clearInterval(window.__hearing.pollTimer);
        window.__hearing.pollTimer = null;
        window.__hearing.active = false;
    }

    const kpi = (icon, bg, fg, num, label, go) => `
        <div class="dx-kpi" ${go ? `data-go="${go}" onclick="${go}"` : ''}>
            <div class="dx-kpi-ic" style="background:${bg};color:${fg};"><i class="fas ${icon}"></i></div>
            <div><div class="dx-kpi-num">${num}</div><div class="dx-kpi-lbl">${label}</div></div>
        </div>`;

    const body = document.getElementById('dashboardBody');
    body.innerHTML = `
    <div class="dx">
        <div class="dx-top">
            <div class="dx-top-text">
                <h2><span id="dxGreeting">Welcome</span>, <?php echo htmlspecialchars($admin['full_name']); ?>!</h2>
                <p><i class="fas fa-calendar-alt"></i><span id="currentDate"></span></p>
            </div>
            <div class="dx-top-actions">
                <button class="dx-top-btn" onclick="renderMyComplaints()"><i class="fas fa-gavel"></i><span>My Complaints</span></button>
                <button class="dx-top-btn" onclick="window.location.href='hearings.php'"><i class="fas fa-calendar-alt"></i><span>Hearings</span></button>
                <button class="dx-top-btn" onclick="window.location.href='mediation_report.php'"><i class="fas fa-chart-bar"></i><span>Report</span></button>
            </div>
        </div>

        <div class="dx-kpis">
            ${kpi('fa-folder-open',   '#e3f2fd', '#1565c0', <?php echo $stats['total']; ?>,        'Total Cases',    'renderMyComplaints()')}
            ${kpi('fa-calendar-check','#f3e5f5', '#6a1b9a', <?php echo $stats['scheduled']; ?>,     'Scheduled',      'renderMyComplaints()')}
            ${kpi('fa-users-cog',     '#fff8e1', '#f57c00', <?php echo $stats['needs_assign']; ?>,  'To Assign',      'renderMyComplaints()')}
            ${kpi('fa-handshake',     '#e8f5e9', '#2e7d32', <?php echo $stats['settled']; ?>,       'Settled',        'renderMyComplaints()')}
            ${kpi('fa-times-circle',  '#ffebee', '#c62828', <?php echo $stats['failed']; ?>,        'Unresolved',     'renderMyComplaints()')}
            ${kpi('fa-check-double',  '#e8f5e9', '#1b5e20', <?php echo $stats['unresolved']; ?>,    'Certificates',   'renderMyComplaints()')}
        </div>

        <div class="dx-grid">
            <div class="dx-card dx-annc">
                <div class="dx-card-h">
                    <h4><i class="fas fa-bullhorn" style="color:#e65100;"></i> Barangay Announcements</h4>
                    <span class="dx-count-chip ok" id="dxAnncCount" style="display:none;"></span>
                </div>
                <div class="dx-annc-body">
                    <div id="anncGridWrap">
                        <div style="text-align:center;padding:40px;color:#999;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
                    </div>
                </div>
            </div>

            <div class="dx-card dx-trend">
                <div class="dx-card-h">
                    <h4><i class="fas fa-chart-line"></i> My Cases — 6-Month Trend</h4>
                </div>
                <div class="dx-chart"><canvas id="dxTrendCanvas"></canvas></div>
            </div>

            <div class="dx-card dx-status">
                <div class="dx-card-h">
                    <h4><i class="fas fa-chart-pie"></i> Case Status</h4>
                </div>
                <div class="dx-chart"><canvas id="dxStatusCanvas"></canvas></div>
            </div>

            <div class="dx-card dx-cal">
                <div class="dx-card-h">
                    <h4><i class="fas fa-calendar-alt"></i> My Hearings</h4>
                    <div class="dx-cal-nav">
                        <button onclick="dxCalMove(-1)"><i class="fas fa-chevron-left"></i></button>
                        <div class="dx-cal-title" id="dxCalTitle"></div>
                        <button onclick="dxCalMove(1)"><i class="fas fa-chevron-right"></i></button>
                        <button class="today" onclick="dxCalToday()">Today</button>
                    </div>
                </div>
                <div class="dx-cal-wrap">
                    <div class="dx-cal-dow"><div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div></div>
                    <div class="dx-cal-grid" id="dxCalGrid"></div>
                    <div class="dx-legend">
                        <span><i class="dx-dot hearing"></i> My hearings</span>
                    </div>
                    <div class="dx-agenda" id="dxAgenda"></div>
                </div>
            </div>

            <div class="dx-card dx-recent">
                <div class="dx-card-h">
                    <h4><i class="fas fa-list"></i> Recent Cases</h4>
                    <button class="dx-add-btn" onclick="renderMyComplaints()">View all <i class="fas fa-arrow-right"></i></button>
                </div>
                <div class="dx-recent-list" id="dxRecentCases"></div>
            </div>
        </div>
    </div>`;

    document.getElementById('currentDate').innerHTML =
        new Date().toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });

    const g = document.getElementById('dxGreeting');
    if (g) { const h = new Date().getHours(); g.textContent = h < 12 ? 'Good morning' : (h < 18 ? 'Good afternoon' : 'Good evening'); }

    initLuponCharts();
    dxRenderCalendar();
    dxRenderRecentCases();
    renderLuponAnnouncements();
}

// ── Trend chart ──
function initLuponCharts() {
    Object.keys(__dxCharts).forEach(dxDestroy);
    __dxCal = { y: __dxToday.getFullYear(), m: __dxToday.getMonth(),
                sel: dxYmd(__dxToday.getFullYear(), __dxToday.getMonth(), __dxToday.getDate()) };

    if (typeof Chart === 'undefined') {
        document.querySelectorAll('.dx-chart').forEach(el => {
            el.innerHTML = '<div class="dx-empty">Charts could not load.</div>';
        });
        return;
    }
    Chart.defaults.font.family = "'Segoe UI', Roboto, sans-serif";
    Chart.defaults.color = '#5f6f66';

    const trendEl = document.getElementById('dxTrendCanvas');
    if (trendEl) {
        const grad = (hex) => (ctx) => {
            const a = ctx.chart.chartArea;
            if (!a) return hex + '22';
            const g = ctx.chart.ctx.createLinearGradient(0, a.top, 0, a.bottom);
            g.addColorStop(0, hex + '55'); g.addColorStop(1, hex + '05');
            return g;
        };
        __dxCharts.trend = new Chart(trendEl, {
            type: 'line',
            data: {
                labels: DX.months || [],
                datasets: [{
                    label: 'My cases',
                    data: DX.caseMonthly || [],
                    borderColor: '#2E7D32',
                    backgroundColor: grad('#2E7D32'),
                    fill: true, tension: 0.4, borderWidth: 3,
                    pointRadius: 4, pointBackgroundColor: '#fff', pointBorderWidth: 2,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { backgroundColor: '#1a2b22', padding: 10, cornerRadius: 8 }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { precision: 0, maxTicksLimit: 5 },
                         grid: { color: '#eef2f0' }, border: { display: false } }
                }
            }
        });
    }

    const statusEl = document.getElementById('dxStatusCanvas');
    if (statusEl) {
        const cs = DX.caseStatus || {};
        const labels = [], data = [], colors = [];
        LUPON_CASE_GROUPS.forEach(g => {
            const n = g.statuses.reduce((a, st) => a + (cs[st] || 0), 0);
            if (n > 0) { labels.push(g.label); data.push(n); colors.push(g.color); }
        });
        const empty = data.reduce((a, b) => a + b, 0) === 0;
        if (empty) { labels.push('No data'); data.push(1); colors.push('#e5ece8'); }

        __dxCharts.status = new Chart(statusEl, {
            type: 'doughnut',
            data: { labels, datasets: [{ data, backgroundColor: colors, borderColor: '#fff', borderWidth: 3, hoverOffset: 6 }] },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '68%',
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 10, font: { size: 11 } } },
                    tooltip: { enabled: !empty, backgroundColor: '#1a2b22', padding: 10, cornerRadius: 8 }
                }
            }
        });
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
    const names = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    if (title) title.textContent = `${names[m]} ${y}`;

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
        const dots = evs.length ? '<i class="dx-dot hearing"></i>' : '';
        html += `<button type="button" class="dx-day ${ds === todayStr ? 'today' : ''} ${ds === __dxCal.sel ? 'sel' : ''}"
                    onclick="dxCalSelect('${ds}')" title="${evs.length ? evs.length + ' hearing(s)' : ''}">
                    <span>${d}</span><span class="dx-dots">${dots}</span></button>`;
    }
    const trailing = rows * 7 - (first + days);
    for (let i = 0; i < trailing; i++) html += '<div class="dx-day blank"></div>';
    grid.style.gridTemplateRows = `repeat(${rows}, minmax(28px, 1fr))`;
    grid.innerHTML = html;
    dxRenderAgenda(map);
}

function dxRenderAgenda(map) {
    const box = document.getElementById('dxAgenda');
    if (!box) return;
    const sel = __dxCal.sel;
    const evs = (map[sel] || []).slice().sort((a, b) => String(a.time || '').localeCompare(String(b.time || '')));
    const nice = sel ? new Date(sel + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' }) : '';
    let html = `<div class="dx-agenda-h">${escapeHtml(nice)}</div>`;
    if (evs.length) {
        html += evs.map(e => `
            <div class="dx-ev hearing" onclick="openComplaintDetail(${e.id})">
                <div class="dx-ev-time">${dxFmtTime(e.time)}</div>
                <div class="dx-ev-body">
                    <div class="dx-ev-title">Hearing: ${escapeHtml(e.title || '')}</div>
                    <div class="dx-ev-sub">${escapeHtml(e.sub || '')}</div>
                </div>
            </div>`).join('');
    } else {
        html += '<div class="dx-empty">Nothing scheduled on this day.</div>';
        const upcoming = (DX.events || []).filter(e => e.date > sel)
            .sort((a, b) => a.date.localeCompare(b.date) || String(a.time || '').localeCompare(String(b.time || '')))
            .slice(0, 2);
        if (upcoming.length) {
            html += '<div class="dx-agenda-h" style="margin-top:6px;">Up next</div>' + upcoming.map(e => `
                <div class="dx-ev hearing" onclick="openComplaintDetail(${e.id})">
                    <div class="dx-ev-time">${new Date(e.date + 'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric'})}</div>
                    <div class="dx-ev-body">
                        <div class="dx-ev-title">Hearing: ${escapeHtml(e.title || '')}</div>
                        <div class="dx-ev-sub">${escapeHtml(e.sub || '')}</div>
                    </div>
                </div>`).join('');
        }
    }
    box.innerHTML = html;
}

// ── Recent cases ──
function dxRenderRecentCases() {
    const box = document.getElementById('dxRecentCases');
    if (!box) return;
    const cases = <?php echo json_encode($dxRecentCases ?: []); ?>;
    if (!cases.length) {
        box.innerHTML = '<div class="dx-empty" style="padding:30px;">No cases assigned yet.</div>';
        return;
    }
    const colorMap = {
        pangkat_constituted: { color: '#f57c00', icon: 'fa-users-cog', label: 'For Scheduling' },
        pangkat_scheduled:   { color: '#6a1b9a', icon: 'fa-calendar-check', label: 'Scheduled' },
        settled:             { color: '#2E7D32', icon: 'fa-handshake', label: 'Settled' },
        settled_pending:     { color: '#2E7D32', icon: 'fa-hourglass-half', label: 'Settled — Pending' },
        unresolved:          { color: '#c62828', icon: 'fa-times-circle', label: 'Unresolved' },
        failed_conciliation_final: { color: '#c62828', icon: 'fa-times-circle', label: 'Failed' },
        certificate_issued:  { color: '#b71c1c', icon: 'fa-certificate', label: 'Certificate Issued' },
    };
    box.innerHTML = cases.map(c => {
        const cfg = colorMap[c.status] || { color: '#666', icon: 'fa-file-alt', label: c.status };
        return `
        <div class="dx-recent-item" onclick="openComplaintDetail(${c.id})">
            <div class="dx-recent-icon" style="background:${cfg.color}15;color:${cfg.color};">
                <i class="fas ${cfg.icon}"></i>
            </div>
            <div class="dx-recent-body">
                <div class="dx-recent-title">${escapeHtml(c.title || '')}</div>
                <div class="dx-recent-meta">
                    <span><i class="fas fa-hashtag"></i> ${escapeHtml(c.reference_number || '')}</span>
                    <span><i class="fas fa-user"></i> ${escapeHtml(c.complainant_name || '—')}</span>
                </div>
            </div>
            <span class="dx-recent-status" style="background:${cfg.color}20;color:${cfg.color};">${escapeHtml(cfg.label)}</span>
        </div>`;
    }).join('');
}

// ── Announcements (read-only) — same carousel as the Captain dashboard ──
const DX_ANN_CATS = {
    general:  { label: 'General',  icon: 'fa-bullhorn' },
    urgent:   { label: 'Urgent',   icon: 'fa-exclamation-circle' },
    event:    { label: 'Event',    icon: 'fa-calendar-check' },
    advisory: { label: 'Advisory', icon: 'fa-info-circle' },
    health:   { label: 'Health',   icon: 'fa-heartbeat' },
    safety:   { label: 'Safety',   icon: 'fa-shield-alt' },
};

async function renderLuponAnnouncements() {
    const wrap = document.getElementById('anncGridWrap');
    if (!wrap) return;
    try {
        // Served by THIS file (see ann_action handler at the top) — never by captain_dashboard.php
        const r = await fetch('?ann_action=list', { cache: 'no-store' });
        const d = await r.json();
        if (!d.success) throw new Error('bad');
        window.__anncItems = d.data || [];
        const chip = document.getElementById('dxAnncCount');
        if (chip) { chip.style.display = ''; chip.textContent = window.__anncItems.length + ' active'; }
        dxRenderAnnouncements(window.__anncItems);
    } catch (e) {
        wrap.innerHTML = `<div class="annc-empty"><i class="fas fa-exclamation-triangle"></i><h4>Could not load announcements</h4><p>Please refresh the page.</p></div>`;
    }
}

function dxAnncCard(a, i) {
    const cat = DX_ANN_CATS[a.category] || DX_ANN_CATS.general;
    let expiry = '';
    if (a.expires_at) {
        const ts = new Date(a.expires_at.replace(' ', 'T')).getTime();
        const days = Math.floor((ts - Date.now()) / 86400000);
        const nice = new Date(ts).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        expiry = days <= 0
            ? `<span class="annc-badge expiry urgent"><i class="fas fa-exclamation-triangle"></i> Expires today</span>`
            : (days <= 3
                ? `<span class="annc-badge expiry soon"><i class="fas fa-hourglass-half"></i> ${days}d left</span>`
                : `<span class="annc-badge expiry"><i class="fas fa-calendar-check"></i> Until ${escapeHtml(nice)}</span>`);
    }
    const img = a.image_url ? `background-image:url('../../${escapeHtml(a.image_url)}')` : '';
    return `
    <div class="annc-carousel-slide">
        <div class="annc-card" onclick="dxViewAnnouncement(${i})">
            <div class="annc-card-image ${a.image_url ? '' : 'fallback'}" style="${img}"></div>
            <div class="annc-card-top">
                <span class="annc-badge ${escapeHtml(a.category)}"><i class="fas ${cat.icon}"></i> ${cat.label}</span>
                ${a.is_pinned ? `<span class="annc-badge pinned"><i class="fas fa-thumbtack"></i> Pinned</span>` : ''}
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
                ${expiry}
            </div>
            <div class="annc-card-body">
                <h3 class="annc-card-title">${escapeHtml(a.title)}</h3>
                <p class="annc-card-text">${escapeHtml(a.body)}</p>
                <div class="annc-card-meta">
                    <span><i class="fas fa-user-tie"></i> ${escapeHtml(a.author || 'Barangay')}</span>
                    ${a.event_date
                        ? `<span><i class="fas fa-calendar-check"></i> ${escapeHtml(new Date(a.event_date + 'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric'}))}${a.event_time ? ' · ' + escapeHtml((() => {
                                const [h,m] = a.event_time.split(':');
                                let hh = parseInt(h,10);
                                const ap = hh >= 12 ? 'PM' : 'AM';
                                hh = hh % 12 || 12;
                                return hh + ':' + m + ' ' + ap;
                            })()) : ''}</span>`
                        : `<span><i class="fas fa-clock"></i> ${escapeHtml(timeAgo(a.created_at))}</span>`}
                </div>
                <span class="dx-readmore"><i class="fas fa-book-open"></i> Read full announcement</span>
            </div>
        </div>
    </div>`;
}

function dxRenderAnnouncements(items) {
    const wrap = document.getElementById('anncGridWrap');
    if (!wrap) return;
    clearInterval(window.__anncAutoTimer);
    if (!items.length) {
        wrap.innerHTML = `<div class="annc-empty"><i class="fas fa-bullhorn"></i><h4>No active announcements</h4><p>The Secretary has not posted any announcement yet.</p></div>`;
        return;
    }
    window.__anncIndex = 0;
    if (items.length === 1) { wrap.innerHTML = dxAnncCard(items[0], 0); return; }
    wrap.innerHTML = `
        <div class="annc-carousel">
            <div class="annc-carousel-track" id="anncTrack">${items.map((a, i) => dxAnncCard(a, i)).join('')}</div>
            <button type="button" class="annc-nav-btn prev" onclick="anncPrev()" aria-label="Previous"><i class="fas fa-chevron-left"></i></button>
            <button type="button" class="annc-nav-btn next" onclick="anncNext()" aria-label="Next"><i class="fas fa-chevron-right"></i></button>
            <div class="annc-dots" id="anncDots">
                ${items.map((_, i) => `<button type="button" class="annc-dot ${i === 0 ? 'active' : ''}" onclick="anncGoTo(${i})" aria-label="Go to slide ${i + 1}"></button>`).join('')}
            </div>
        </div>`;
    window.__anncAutoTimer = setInterval(anncNext, 7000);
}

function anncGoTo(index) {
    const items = window.__anncItems || [];
    const track = document.getElementById('anncTrack');
    if (!items.length || !track) { clearInterval(window.__anncAutoTimer); return; }
    index = (index + items.length) % items.length;
    window.__anncIndex = index;
    track.style.transform = `translateX(-${index * 100}%)`;
    document.querySelectorAll('#anncDots .annc-dot').forEach((dot, i) => dot.classList.toggle('active', i === index));
    clearInterval(window.__anncAutoTimer);
    window.__anncAutoTimer = setInterval(anncNext, 7000);
}
function anncNext() { if ((window.__anncItems || []).length > 1) anncGoTo((window.__anncIndex || 0) + 1); }
function anncPrev() { if ((window.__anncItems || []).length > 1) anncGoTo((window.__anncIndex || 0) - 1); }

function dxViewAnnouncement(i) {
    const a = (window.__anncItems || [])[i];
    if (!a) return;
    const cat = DX_ANN_CATS[a.category] || DX_ANN_CATS.general;

    const bg = a.image_url
        ? `background-image:url('../../${escapeHtml(a.image_url)}');`
        : `background:linear-gradient(135deg,#1b5e20 0%,#2E7D32 45%,#66bb6a 100%);`;

    Swal.fire({
        title: '',
        html: `
        <div style="position:relative;width:100%;min-height:460px;${bg}
                    background-size:cover;background-position:center;
                    display:flex;align-items:flex-end;justify-content:center;">

            <!-- dark gradient so the text box is readable -->
            <div style="position:absolute;inset:0;
                        background:linear-gradient(180deg,
                            rgba(0,0,0,0.10) 0%,
                            rgba(0,0,0,0.35) 45%,
                            rgba(0,0,0,0.85) 100%);"></div>

            <!-- badge row -->
            <div style="position:absolute;top:16px;left:16px;right:56px;display:flex;gap:8px;flex-wrap:wrap;z-index:3;">
                <span style="background:rgba(0,0,0,0.5);color:#fff;padding:5px 12px;border-radius:14px;
                             font-size:0.7rem;font-weight:800;text-transform:uppercase;
                             backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,0.25);">
                    <i class="fas ${cat.icon}"></i> ${cat.label}
                </span>
                ${a.is_pinned ? '<span style="background:#ffb300;color:#4a2f00;padding:5px 12px;border-radius:14px;font-size:0.7rem;font-weight:800;"><i class="fas fa-thumbtack"></i> PINNED</span>' : ''}
            </div>

            <!-- readable text box — auto-sizes to content -->
            <div style="position:relative;z-index:2;margin:0 18px 18px;
                        background:rgba(0,0,0,0.6);backdrop-filter:blur(12px);
                        border:1px solid rgba(255,255,255,0.18);border-radius:14px;
                        padding:16px 22px;max-width:calc(100% - 36px);
                        box-sizing:border-box;text-align:center;
                        box-shadow:0 10px 30px rgba(0,0,0,0.4);">

                <h2 style="margin:0 0 8px;font-size:1.3rem;color:#fff;line-height:1.3;
                           text-shadow:0 2px 6px rgba(0,0,0,0.6);text-align:center;">
                    ${escapeHtml(a.title)}
                </h2>

                <div style="font-size:0.74rem;color:rgba(255,255,255,0.82);margin-bottom:12px;
                            display:flex;flex-wrap:wrap;gap:14px;justify-content:center;">
                    <span><i class="fas fa-user-tie"></i> ${escapeHtml(a.author || 'Barangay')}</span>
                    ${a.event_date
                        ? `<span><i class="fas fa-calendar-check"></i> ${escapeHtml(new Date(a.event_date + 'T00:00:00').toLocaleDateString('en-US',{dateStyle:'medium'}))}${a.event_time ? ' · ' + escapeHtml((() => {
                                const [h,m] = a.event_time.split(':');
                                let hh = parseInt(h,10);
                                const ap = hh >= 12 ? 'PM' : 'AM';
                                hh = hh % 12 || 12;
                                return hh + ':' + m + ' ' + ap;
                            })()) : ''}</span>`
                        : `<span><i class="fas fa-clock"></i> ${escapeHtml(new Date(a.created_at.replace(' ','T')).toLocaleString('en-US',{dateStyle:'medium',timeStyle:'short'}))}</span>`}
                </div>

                <div style="font-size:0.9rem;line-height:1.65;color:rgba(255,255,255,0.95);
                            white-space:pre-wrap;text-align:center;
                            max-height:26vh;overflow-y:auto;padding:0 4px;">
                    ${escapeHtml(a.body)}
                </div>
            </div>

            <!-- close button -->
            <button type="button" onclick="Swal.close()"
                    style="position:absolute;top:14px;right:14px;z-index:5;
                           width:36px;height:36px;border-radius:50%;border:none;cursor:pointer;
                           background:rgba(0,0,0,0.5);color:#fff;font-size:1rem;
                           backdrop-filter:blur(8px);display:flex;align-items:center;
                           justify-content:center;transition:all .15s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.9)';this.style.color='#1a1a1a';"
                    onmouseout="this.style.background='rgba(0,0,0,0.5)';this.style.color='#fff';">
                <i class="fas fa-times"></i>
            </button>
        </div>`,
        width: 640,
        showConfirmButton: false,
        showCloseButton: false,
        padding: 0,
        background: 'transparent',
        customClass: { popup: 'annc-full-popup' },
        allowOutsideClick: true
    });
}

// ============================================================
// MY COMPLAINTS
// ============================================================
let cachedCases = [];

async function renderMyComplaints() {
    setActiveNav('complaints');
    if (window.__hearing && window.__hearing.pollTimer) {
        clearInterval(window.__hearing.pollTimer);
        window.__hearing.pollTimer = null;
        window.__hearing.active = false;
    }
    const body = document.getElementById('dashboardBody');
    body.innerHTML = `
        <div class="content-card" style="background:#fff;border-radius:16px;padding:25px;">
            <h3 style="margin:0 0 8px;display:flex;justify-content:space-between;align-items:center;">
                <span><i class="fas fa-gavel"></i> My Complaints</span>
                <span style="font-size:0.72rem;font-weight:500;color:#888;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-sync-alt fa-spin" style="color:#2E7D32;"></i> Live updates every 5s
                </span>
            </h3>
            <div style="font-size:0.85rem;color:#666;margin-bottom:16px;">
                Cases where you are a Pangkat member. Roles are assigned by the first-selected member.
            </div>
            <div id="caseList">
                <div style="text-align:center;padding:40px;color:#999;">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p style="margin-top:10px;">Loading…</p>
                </div>
            </div>
        </div>`;
    await loadMyComplaints();
    startListPolling();
}

async function loadMyComplaints() {
    const c = document.getElementById('caseList');
    if (!c) return;
    try {
        const r = await fetch('../admin_complaint_ajax.php?action=get_my_assigned_complaints');
        const d = await r.json();
        if (!d.success) {
            c.innerHTML = `<div style="text-align:center;padding:40px;color:#999;"><p>${escapeHtml(d.message || 'Failed to load.')}</p></div>`;
            return;
        }
        cachedCases = d.complaints || [];

        cachedCases.forEach(row => {
            if (!row.explain_deadline) row.explain_deadline = row.concil_absence_explain_deadline || null;
            row.concil_absence_explain_deadline = row.explain_deadline;
        });

        let needsAssign = 0;
        cachedCases.forEach(x => {
            const isPending = x.has_pending_roles === true;
            const isFirst = x.is_first_member === true;
            if (isPending && isFirst && (x.status === 'pangkat_constituted' || x.status === 'pangkat_scheduled')) needsAssign++;
        });

        const badge = document.getElementById('myComplaintsBadge');
        if (badge) {
            if (needsAssign > 0) { badge.style.display = 'inline-block'; badge.textContent = needsAssign; }
            else badge.style.display = 'none';
        }

        renderFilteredCases();
    } catch (e) {
        console.error(e);
        c.innerHTML = '<div style="text-align:center;padding:40px;color:#999;"><p>Network error.</p></div>';
    }
}
function renderFilteredCases() {
    const c = document.getElementById('caseList');
    if (!c) return;

    const f = window.__caseFilters || {};
    const all      = cachedCases || [];
    const filtered = applyCaseFilters(all);

    const totalActive  = all.filter(x => !isHandledCase(x)).length;
    const totalHandled = all.filter(x =>  isHandledCase(x)).length;

    // ── Scope tabs ──
    const scopeTabs = `
        <div class="case-scope-tabs">
            <button type="button"
                    class="case-scope-tab ${f.scope === 'active' ? 'active' : ''}"
                    onclick="setCaseFilter('scope','active')">
                <i class="fas fa-hourglass-half"></i> Active
                <span class="case-scope-count">${totalActive}</span>
            </button>
            <button type="button"
                    class="case-scope-tab ${f.scope === 'handled' ? 'active' : ''}"
                    onclick="setCaseFilter('scope','handled')">
                <i class="fas fa-archive"></i> Previously Handled
                <span class="case-scope-count">${totalHandled}</span>
            </button>
            <button type="button"
                    class="case-scope-tab ${f.scope === 'all' ? 'active' : ''}"
                    onclick="setCaseFilter('scope','all')">
                <i class="fas fa-list"></i> All
                <span class="case-scope-count">${all.length}</span>
            </button>
        </div>`;

    // ── Timeline dropdown (30 days default) ──
    const windowOptions = [
        { v: 7,   label: 'Last 7 days' },
        { v: 14,  label: 'Last 14 days' },
        { v: 30,  label: 'Last 30 days (default)' },
        { v: 90,  label: 'Last 90 days' },
        { v: 0,   label: 'All time' },
        { v: -1,  label: 'Custom range…' },
    ];
    const showCustom = f.window === -1;

    const timelineBar = `
        <div class="case-timeline-bar">
            <div class="case-timeline-label">
                <i class="fas fa-calendar-alt"></i> Timeline:
            </div>
            <select onchange="onTimelineChange(this.value)" class="case-filter-select">
                ${windowOptions.map(o =>
                    `<option value="${o.v}" ${Number(f.window) === o.v ? 'selected' : ''}>${o.label}</option>`
                ).join('')}
            </select>
            ${showCustom ? `
                <input type="date" class="case-filter-select"
                       value="${escapeHtml(f.customFrom)}"
                       onchange="setCaseFilter('customFrom', this.value)">
                <span style="font-size:0.78rem;color:#666;">to</span>
                <input type="date" class="case-filter-select"
                       value="${escapeHtml(f.customTo)}"
                       onchange="setCaseFilter('customTo', this.value)">
            ` : ''}
            <span class="case-timeline-hint">
                <i class="fas fa-info-circle"></i>
                Shows cases with activity within the selected window.
            </span>
        </div>`;

    // ── Status / role / search filters ──
    const filterBar = `
        <div class="case-filter-bar">
            <div class="case-filter-label"><i class="fas fa-filter"></i> Filters:</div>
            <select onchange="setCaseFilter('status', this.value)" class="case-filter-select">
                <option value="all"        ${f.status==='all'?'selected':''}>All statuses</option>
                <option value="assign"     ${f.status==='assign'?'selected':''}>Needs position assignment</option>
                <option value="scheduled"  ${f.status==='scheduled'?'selected':''}>Scheduled</option>
                <option value="hearing"    ${f.status==='hearing'?'selected':''}>Hearing in progress</option>
                <option value="no_show"    ${f.status==='no_show'?'selected':''}>No-show — set appearance</option>
                <option value="decide"     ${f.status==='decide'?'selected':''}>Decide on absences</option>
                <option value="active"     ${f.status==='active'?'selected':''}>All active hearings</option>
                <option value="settled"    ${f.status==='settled'?'selected':''}>Settled</option>
                <option value="unresolved" ${f.status==='unresolved'?'selected':''}>Unresolved / Certificate</option>
            </select>
            <select onchange="setCaseFilter('role', this.value)" class="case-filter-select">
                <option value="all"       ${f.role==='all'?'selected':''}>All roles</option>
                <option value="chair"     ${f.role==='chair'?'selected':''}>Chairperson</option>
                <option value="secretary" ${f.role==='secretary'?'selected':''}>Secretary</option>
                <option value="member"    ${f.role==='member'?'selected':''}>Member</option>
            </select>
            <div class="case-filter-search">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search reference, title, complainant…"
                       value="${escapeHtml(f.q)}"
                       oninput="setCaseFilter('q', this.value)">
            </div>
            <button type="button" onclick="resetCaseFilters()" class="case-filter-reset">
                <i class="fas fa-times"></i> Reset
            </button>
            <span class="case-filter-count"><b>${filtered.length}</b> of ${all.length}</span>
        </div>`;

    if (!all.length) {
        c.innerHTML = `<div style="text-align:center;padding:60px;color:#999;">
            <i class="fas fa-inbox fa-3x" style="color:#c8e6c9;"></i>
            <p style="margin-top:12px;">No complaints to show yet.</p>
        </div>`;
        return;
    }

    if (!filtered.length) {
        c.innerHTML = scopeTabs + timelineBar + filterBar + `<div style="text-align:center;padding:40px;color:#999;">
            <i class="fas fa-search fa-2x" style="color:#cfd8dc;"></i>
            <p style="margin-top:10px;">No cases match the current filters.</p>
        </div>`;
        return;
    }

    c.innerHTML = scopeTabs + timelineBar + filterBar +
        `<div class="documents-grid">${filtered.map(x => renderCaseCard(x)).join('')}</div>`;
    startLiveCountdowns();
}

// Helper: switching the dropdown to "Custom range…" reveals the date inputs
function onTimelineChange(value) {
    const v = Number(value);
    window.__caseFilters.window = v;

    if (v === -1) {
        // Default custom range = last 30 days
        if (!window.__caseFilters.customFrom) {
            const d = new Date(); d.setDate(d.getDate() - 30);
            window.__caseFilters.customFrom = d.toISOString().split('T')[0];
        }
        if (!window.__caseFilters.customTo) {
            window.__caseFilters.customTo = new Date().toISOString().split('T')[0];
        }
    }
    renderFilteredCases();
}
// ============================================================
// CASE FILTERS  (scope: active | handled | all)
// ============================================================
window.__caseFilters = {
    scope: 'active',      // active | handled | all
    status: 'all',
    role: 'all',
    q: '',
    window: 30,           // 7 | 14 | 30 | 90 | 0 (all) | -1 (custom)
    customFrom: '',
    customTo: ''
};
// ============================================================
// CASE FILTERS  (scope + status + role + search + date window)
// ============================================================
function isHandledCase(x) {
    return [
        'settled',
        'certificate_issued',
        'dismissed',
        'unresolved',
        'failed_conciliation_final',
        'failed_mediation',
        'released',
    ].includes(x.status);
}

// The "reference date" for the timeline: last time the case moved.
function caseReferenceDate(x) {
    const raw = (x.updated_at || x.created_at || x.hearing_date || '1970-01-01')
        .toString().replace(' ', 'T');
    const ts = new Date(raw).getTime();
    return isNaN(ts) ? 0 : ts;
}

function applyCaseFilters(list) {
    const f = window.__caseFilters || {};
    const todayStr = new Date().toISOString().split('T')[0];
    const now = Date.now();

    // ── Scope: active vs previously handled ──
    const scopePred = (x) => {
        if (f.scope === 'all') return true;
        const handled = isHandledCase(x);
        if (f.scope === 'handled') return handled;
        return !handled; // default active
    };

    // ── Date window ──
    const datePred = (x) => {
        const ref = caseReferenceDate(x);
        if (!ref) return true;

        // Custom range
        if (f.window === -1) {
            const fromTs = f.customFrom ? new Date(f.customFrom + 'T00:00:00').getTime() : null;
            const toTs   = f.customTo   ? new Date(f.customTo   + 'T23:59:59').getTime() : null;
            if (fromTs && ref < fromTs) return false;
            if (toTs   && ref > toTs)   return false;
            return true;
        }

        // "All time"
        if (!f.window || f.window === 0) return true;

        // Last N days
        const cutoff = now - (f.window * 86400000);
        return ref >= cutoff;
    };

    // ── Status ──
    const statusPred = (x) => {
        if (f.status === 'all') return true;

        const hearingStarted = !!x.concil_hearing_started_at;
        const waitingForAppearance = !!x.concil_absence_waiting_since && !x.concil_absence_appearance_at;
        const hasAppearanceSet = !!x.concil_absence_appearance_at;
        const appearancePassed = hasAppearanceSet
            && new Date(x.concil_absence_appearance_at.replace(' ','T')).getTime() <= Date.now();
        const hearingDate = x.hearing_date ? String(x.hearing_date).split(' ')[0] : null;

        switch (f.status) {
            case 'assign':     return x.has_pending_roles === true && x.is_first_member === true
                                     && (x.status === 'pangkat_constituted' || x.status === 'pangkat_scheduled');
            case 'scheduled':  return x.status === 'pangkat_scheduled' && !hearingStarted
                                     && hearingDate && hearingDate >= todayStr;
            case 'hearing':    return x.status === 'pangkat_scheduled' && hearingStarted;
            case 'no_show':    return waitingForAppearance;
            case 'decide':     return hasAppearanceSet && appearancePassed;
            case 'active':     return x.status === 'pangkat_scheduled'
                                     && (hearingStarted || waitingForAppearance || hasAppearanceSet);
            case 'settled':    return x.status === 'settled' || x.status === 'settled_pending';
            case 'unresolved': return x.status === 'unresolved'
                                     || x.status === 'failed_conciliation_final'
                                     || x.status === 'certificate_issued';
            default:           return true;
        }
    };

    // ── Role ──
    const rolePred = (x) => (f.role === 'all') ? true : (x.pangkat_role || '') === f.role;

    // ── Search ──
    const q = (f.q || '').trim().toLowerCase();
    const qPred = (x) => {
        if (!q) return true;
        return (x.reference_number || '').toLowerCase().includes(q)
            || (x.title            || '').toLowerCase().includes(q)
            || ((x.first_name || '') + ' ' + (x.last_name || '')).toLowerCase().includes(q);
    };

    return list.filter(x => scopePred(x) && datePred(x) && statusPred(x) && rolePred(x) && qPred(x));
}

function setCaseFilter(key, value) {
    window.__caseFilters[key] = value;
    renderFilteredCases();
}

function resetCaseFilters() {
    window.__caseFilters = {
        scope: 'active',
        status: 'all',
        role: 'all',
        q: '',
        window: 30,
        customFrom: '',
        customTo: ''
    };
    renderFilteredCases();
}

function renderCaseCard(x) {
    const isPangkat = !!x.pangkat_role;
    const myRole = x.pangkat_role || null;
    const isPending = x.has_pending_roles === true;
    const isFirst = x.is_first_member === true;
    const needsAssign = isPangkat && isFirst && isPending && (x.status === 'pangkat_constituted' || x.status === 'pangkat_scheduled');

    const todayStr = new Date().toISOString().split('T')[0];
    const hearingDate = x.hearing_date ? String(x.hearing_date).split(' ')[0] : null;
    const hearingIsTodayOrPast = hearingDate && hearingDate <= todayStr;
    const hearingIsToday = hearingDate && hearingDate === todayStr;
    const hearingStarted = !!x.concil_hearing_started_at;
    const waitingForAppearance = !!x.concil_absence_waiting_since && !x.concil_absence_appearance_at;
    const hasAppearanceSet = !!x.concil_absence_appearance_at;
    const appearancePassed = hasAppearanceSet && new Date(x.concil_absence_appearance_at.replace(' ','T')).getTime() <= Date.now();
    const isHearingInProgress = hearingStarted && x.status === 'pangkat_scheduled' && !waitingForAppearance && !hasAppearanceSet;

    const isSettledPending = x.status === 'settled_pending';
    const isSettledExecutory = x.status === 'settled';
    const isUnresolved = x.status === 'unresolved';
    const isLegacyFailedConcil = x.status === 'failed_conciliation_final';

    const awaitsTerms = x.awaits_secretary_terms === true;

    // ── Arbitration state (set by Chair via lupon_record_conciliation_outcome,
    //    or by the Captain via captain_record_mediation_outcome) ──
    const arbStatus       = x.arbitration_status || null;
    const isArbPending    = arbStatus === 'arbitration_pending';
    const isArbActive     = arbStatus === 'arbitration_agreement_active';
    const isArbAward      = arbStatus === 'arbitration_award_issued';
    const isArbRepudiated = arbStatus === 'arbitration_repudiated';
    const isArbitration   = isArbPending || isArbActive || isArbAward || isArbRepudiated;

    let headerCls = 'lupon';
    let statusLbl = (x.status || '').replace(/_/g,' ').toUpperCase();
    let statusIcon = 'fa-file-alt';

    if (isArbRepudiated) {
        headerCls = 'failed';
        statusLbl = 'ARBITRATION REPUDIATED';
        statusIcon = 'fa-times-circle';
    }
    else if (isArbAward) {
        headerCls = 'settled';
        statusLbl = 'ARBITRATION AWARD ISSUED';
        statusIcon = 'fa-gavel';
    }
    else if (isArbActive) {
        headerCls = 'mediation';
        statusLbl = 'ARBITRATION — KP #15 DUE';
        statusIcon = 'fa-hourglass-half';
    }
    else if (isArbPending) {
        headerCls = 'waiting';
        statusLbl = 'ARBITRATION — 5-DAY WINDOW';
        statusIcon = 'fa-hourglass-half';
    }
    else if (needsAssign) { headerCls = 'assign'; statusLbl = 'ASSIGN POSITIONS'; statusIcon = 'fa-user-tag'; }
    else if (awaitsTerms) { headerCls = 'settled_pending'; statusLbl = 'AWAITING TERMS'; statusIcon = 'fa-file-signature'; }
    else if (isSettledPending) { headerCls = 'settled_pending'; statusLbl = 'SETTLED — 10-DAY WINDOW'; statusIcon = 'fa-hourglass-half'; }
    else if (isSettledExecutory) { headerCls = 'settled'; statusLbl = 'SETTLED — EXECUTORY'; statusIcon = 'fa-check-double'; }
    else if (isHearingInProgress) { headerCls = 'inprogress'; statusLbl = 'HEARING IN PROGRESS'; statusIcon = 'fa-play-circle'; }
    else if (waitingForAppearance) { headerCls = 'waiting'; statusLbl = 'NO-SHOW — SET APPEARANCE'; statusIcon = 'fa-user-clock'; }
    else if (hasAppearanceSet && !appearancePassed) { headerCls = 'waiting'; statusLbl = 'AWAITING APPEARANCE'; statusIcon = 'fa-user-clock'; }
    else if (hasAppearanceSet && appearancePassed) { headerCls = 'assign'; statusLbl = 'DECIDE ON ABSENCES'; statusIcon = 'fa-gavel'; }
    else if (x.status === 'pangkat_scheduled' && hearingStarted) { headerCls = 'start'; statusLbl = 'HEARING IN PROGRESS'; statusIcon = 'fa-play-circle'; }
    else if (x.status === 'pangkat_scheduled') { headerCls = 'mediation'; statusLbl = hearingIsToday ? 'HEARING TODAY' : 'SCHEDULED'; statusIcon = hearingIsToday ? 'fa-bell' : 'fa-calendar-check'; }
    else if (isUnresolved || isLegacyFailedConcil) {
        headerCls = 'failed';
        statusLbl = isUnresolved ? 'UNRESOLVED — FILE IN COURT' : 'CONCILIATION FAILED';
        statusIcon = 'fa-file-certificate';
    }
    else if (x.status === 'failed_mediation') { headerCls = 'failed'; statusLbl = 'MEDIATION FAILED'; statusIcon = 'fa-times-circle'; }
    else if (x.status === 'pangkat_constituted') { headerCls = 'pending'; statusIcon = 'fa-users-cog'; }
    else if (x.status === 'certificate_issued') { headerCls = 'failed'; statusLbl = 'CERTIFICATE ISSUED'; statusIcon = 'fa-certificate'; }

    const roleChips = [];
    if (isPangkat) {
        if (isFirst) roleChips.push(`<span class="pangkat-chip first"><i class="fas fa-star"></i> First-selected</span>`);
        if (myRole && myRole !== 'pending') {
            const roleLabel = myRole.charAt(0).toUpperCase() + myRole.slice(1);
            roleChips.push(`<span class="pangkat-chip role-${myRole}"><i class="fas fa-user-tag"></i> ${escapeHtml(roleLabel)}</span>`);
        } else {
            roleChips.push(`<span class="pangkat-chip role-pending"><i class="fas fa-hourglass-half"></i> Position Pending</span>`);
        }
    }

    let waitingMsg = '';
    if (awaitsTerms && myRole === 'secretary') {
        waitingMsg = `<div style="background:#fff8e1;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#e65100;margin-top:4px;border-left:3px solid #f57c00;"><i class="fas fa-file-signature"></i> <b>Action Required:</b> The Chairperson recorded this case as <b>Settled</b>. Open the hearing page to write the settlement terms and generate KP Form #16.</div>`;
    } else if (isPangkat && isPending && !isFirst) {
        waitingMsg = `<div style="background:#fff3e0;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#e65100;margin-top:4px;"><i class="fas fa-hourglass-half"></i> Waiting for the first-selected member to assign positions.</div>`;
    } else if (isPangkat && myRole === 'chair' && x.status === 'pangkat_constituted') {
        waitingMsg = `<div style="background:#f3e5f5;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#4a148c;margin-top:4px;"><i class="fas fa-calendar-plus"></i> <b>You are the Chairperson.</b> Schedule the conciliation hearing.</div>`;
    } else if (isHearingInProgress && myRole === 'chair') {
        waitingMsg = `<div style="background:#ffebee;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#b71c1c;margin-top:4px;"><i class="fas fa-play-circle"></i> <b>Hearing in progress.</b> Click "Open Hearing" to lead the session.</div>`;
    } else if (isHearingInProgress && myRole === 'secretary') {
        waitingMsg = `<div style="background:#e3f2fd;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#0d47a1;margin-top:4px;"><i class="fas fa-clipboard-list"></i> <b>Hearing in progress.</b> Click "Open Hearing" to record.</div>`;
    } else if (isHearingInProgress && myRole === 'member') {
        waitingMsg = `<div style="background:#fff3e0;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#bf360c;margin-top:4px;"><i class="fas fa-users"></i> <b>Hearing in progress.</b> Click "Open Hearing".</div>`;
    } else if (isPangkat && myRole === 'chair' && x.status === 'pangkat_scheduled' && !hearingStarted) {
        if (hearingIsTodayOrPast) waitingMsg = `<div style="background:#e0f7fa;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#00838f;margin-top:4px;"><i class="fas fa-play-circle"></i> <b>Ready to start?</b> Click "Start Hearing".</div>`;
        else waitingMsg = `<div style="background:#f3e5f5;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#6a1b9a;margin-top:4px;"><i class="fas fa-clock"></i> Hearing scheduled for ${escapeHtml(x.hearing_date)}. Start available on hearing day.</div>`;
    } else if (isPangkat && myRole === 'chair' && waitingForAppearance) {
        waitingMsg = `<div style="background:#ffebee;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#b71c1c;margin-top:4px;"><i class="fas fa-user-clock"></i> <b>Action Required:</b> Set an appearance date.</div>`;
    } else if (isPangkat && myRole === 'chair' && hasAppearanceSet && appearancePassed) {
        waitingMsg = `<div style="background:#f3e5f5;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#4a148c;margin-top:4px;"><i class="fas fa-gavel"></i> <b>Decide:</b> Was the absence justified or unjustified?</div>`;
    } else if (isPangkat && myRole === 'secretary' && !awaitsTerms && !isUnresolved) {
        waitingMsg = `<div style="background:#e3f2fd;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#0d47a1;margin-top:4px;"><i class="fas fa-clipboard-list"></i> <b>You are the Secretary.</b> Record attendance &amp; statements.</div>`;
    } else if (isPangkat && myRole === 'member') {
        waitingMsg = `<div style="background:#fff3e0;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#bf360c;margin-top:4px;"><i class="fas fa-users"></i> <b>You are a Member.</b> Attend the conciliation hearing.</div>`;
    }

    if (isSettledPending && !awaitsTerms) {
        waitingMsg = `<div style="background:#e0f7fa;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#006064;margin-top:4px;"><i class="fas fa-hourglass-half"></i> <b>10-day repudiation window.</b> Either party may contest the settlement on grounds of fraud, violence, or intimidation.</div>`;
    } else if (isSettledExecutory) {
        waitingMsg = `<div style="background:#e8f5e9;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#1b5e20;margin-top:4px;"><i class="fas fa-check-double"></i> <b>Settlement is final and executory.</b></div>`;
    } else if (isUnresolved && myRole === 'secretary' && !x.kp21_pdf_path) {
        waitingMsg = `<div style="background:#ffebee;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#b71c1c;margin-top:4px;border-left:3px solid #c62828;"><i class="fas fa-file-certificate"></i> <b>Action Required:</b> Conciliation ended without settlement. Prepare <b>KP Form #20-A</b> (Certification to File Action).</div>`;
    } else if (isUnresolved && x.kp21_pdf_path) {
        waitingMsg = `<div style="background:#e8f5e9;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#1b5e20;margin-top:4px;"><i class="fas fa-check-circle"></i> <b>KP Form #20-A issued.</b> Print and hand-deliver to both parties.</div>`;
    }

    // ── Arbitration banner (takes precedence over waitingMsg when set) ──
    let arbitrationBanner = '';
    if (isArbitration) {
        const repDeadline = x.arbitration_repudiation_deadline
            ? new Date(String(x.arbitration_repudiation_deadline).replace(' ','T'))
                .toLocaleDateString('en-US', { dateStyle: 'medium' })
            : '—';
        const awardDeadline = x.arbitration_award_deadline
            ? new Date(String(x.arbitration_award_deadline).replace(' ','T'))
                .toLocaleDateString('en-US', { dateStyle: 'medium' })
            : '—';

        let arbColor = '#1565c0';
        let arbBg    = '#e3f2fd';
        let arbIcon  = 'fa-gavel';
        let arbTitle = 'Arbitration in progress';
        let arbNote  = 'The <b>Punong Barangay</b> handles the award (KP #15) and any repudiation decision.';

        if (isArbPending) {
            arbColor = '#e65100'; arbBg = '#fff8e1'; arbIcon = 'fa-hourglass-half';
            arbTitle = 'Arbitration — 5-Day Repudiation Window';
            arbNote  = `Window ends <b>${escapeHtml(repDeadline)}</b>. Either party may still repudiate KP #14.`;
        } else if (isArbActive) {
            arbColor = '#1565c0'; arbBg = '#e3f2fd'; arbIcon = 'fa-gavel';
            arbTitle = 'Arbitration Agreement Final — KP #15 Due';
            arbNote  = `The Punong Barangay must render KP Form #15 by <b>${escapeHtml(awardDeadline)}</b>.`;
        } else if (isArbAward) {
            arbColor = '#2E7D32'; arbBg = '#e8f5e9'; arbIcon = 'fa-check-double';
            arbTitle = 'Arbitration Award Issued (KP #15)';
            arbNote  = 'The award is final and binding. Both parties have been notified.';
        } else if (isArbRepudiated) {
            arbColor = '#c62828'; arbBg = '#ffebee'; arbIcon = 'fa-times-circle';
            arbTitle = 'KP #14 was REPUDIATED';
            arbNote  = `Repudiated by <b>${escapeHtml(String(x.arbitration_repudiated_by_party || 'party').toUpperCase())}</b>. The Punong Barangay will decide the next step.`;
        }

        arbitrationBanner = `
            <div style="margin-top:10px;padding:12px 14px;background:${arbBg};border-left:4px solid ${arbColor};border-radius:8px;font-size:0.78rem;color:${arbColor};line-height:1.6;">
                <div style="font-weight:800;text-transform:uppercase;font-size:0.7rem;letter-spacing:0.4px;margin-bottom:6px;">
                    <i class="fas ${arbIcon}"></i> ${arbTitle}
                </div>
                <div>${arbNote}</div>
                ${x.kp14_pdf_path ? `
                    <button type="button"
                            class="doc-action-btn pdf"
                            style="margin-top:8px;padding:6px 14px;font-size:0.72rem;"
                            onclick="event.stopPropagation(); window.open('../../${escapeHtml(x.kp14_pdf_path)}','_blank');">
                        <i class="fas fa-file-pdf"></i> View KP #14
                    </button>` : ''}
                ${x.kp15_pdf_path ? `
                    <button type="button"
                            class="doc-action-btn pdf"
                            style="margin-top:8px;margin-left:6px;padding:6px 14px;font-size:0.72rem;background:#2E7D32;"
                            onclick="event.stopPropagation(); window.open('../../${escapeHtml(x.kp15_pdf_path)}','_blank');">
                        <i class="fas fa-file-pdf"></i> View KP #15
                    </button>` : ''}
            </div>`;
    }

    const showConcilPill = (x.status === 'pangkat_scheduled') && x.conciliation_deadline;
    const concilPillHtml = showConcilPill ? `<div class="document-info-row" style="background:#e0f7fa;padding:6px 8px;border-radius:6px;margin-top:4px;"><div class="document-info-label" style="color:#00838f;font-weight:700;width:auto;margin-right:6px;">⏳ 15-Day:</div><div class="document-info-value live-countdown" data-deadline="${escapeHtml(x.conciliation_deadline)}"><span class="status-pill safe"><i class="fas fa-hourglass-half"></i> Calculating…</span></div></div>` : '';

    const explainIso = x.explain_deadline || x.concil_absence_explain_deadline || null;
    const explainPillHtml = explainIso ? `<div class="document-info-row" style="background:#ffebee;padding:6px 8px;border-radius:6px;margin-top:4px;">
        <div class="document-info-label" style="color:#b71c1c;font-weight:700;width:auto;margin-right:6px;">⏳ 5-Day Explain:</div>
        <div class="document-info-value live-countdown" data-deadline="${escapeHtml(explainIso)}"><span class="status-pill critical"><i class="fas fa-hourglass-half"></i> Calculating…</span></div>
    </div>` : '';

    const showSettleCountdown = isSettledPending && !awaitsTerms && x.repudiation_deadline;
    const settleCountdownHtml = showSettleCountdown ? `<div class="document-info-row" style="background:#e0f7fa;padding:6px 8px;border-radius:6px;margin-top:4px;"><div class="document-info-label" style="color:#00838f;font-weight:700;width:auto;margin-right:6px;">⏳ Repudiation:</div><div class="document-info-value live-countdown" data-deadline="${escapeHtml(x.repudiation_deadline)}"><span class="status-pill safe"><i class="fas fa-hourglass-half"></i> Calculating…</span></div></div>` : '';

    /* ═════════════════════════════════════════════════════════════════
       ARBITRATION SHORT-CIRCUIT
       ─────────────────────────────────────────────────────────────────
       When the case has entered arbitration, the Chair/Secretary/Lupon
       members have no further actions — the PUNONG BARANGAY owns KP #15
       and any repudiation decision. Render a read-only card.
       ═════════════════════════════════════════════════════════════════ */
    if (isArbitration) {
        const arbActions = [];
        if (x.kp14_pdf_path) {
            arbActions.push(`<button class="doc-action-btn pdf" onclick="event.stopPropagation(); window.open('../../${escapeHtml(x.kp14_pdf_path)}','_blank');"><i class="fas fa-file-pdf"></i> View KP #14</button>`);
        }
        if (x.kp15_pdf_path) {
            arbActions.push(`<button class="doc-action-btn pdf" style="background:#2E7D32;" onclick="event.stopPropagation(); window.open('../../${escapeHtml(x.kp15_pdf_path)}','_blank');"><i class="fas fa-file-pdf"></i> View KP #15</button>`);
        }
        arbActions.push(`<button class="doc-action-btn view" onclick="event.stopPropagation(); openComplaintDetail(${x.id})"><i class="fas fa-eye"></i> View Case</button>`);

        return `
            <div class="document-card">
                <div class="document-card-header ${headerCls}">
                    <div class="document-title"><i class="fas ${statusIcon}"></i><span>${escapeHtml(x.title)}</span></div>
                    <div class="document-status"><i class="fas ${statusIcon}"></i> ${escapeHtml(statusLbl)}</div>
                </div>
                <div class="document-card-body">
                    <div class="document-info-row"><div class="document-info-label">Ref:</div><div class="document-info-value"><strong>${escapeHtml(x.reference_number)}</strong></div></div>
                    <div class="document-info-row"><div class="document-info-label">Complainant:</div><div class="document-info-value">${escapeHtml(x.first_name || '')} ${escapeHtml(x.last_name || '')}</div></div>
                    ${x.hearing_date ? `<div class="document-info-row"><div class="document-info-label">Hearing:</div><div class="document-info-value">${escapeHtml(String(x.hearing_date))} ${escapeHtml(x.hearing_time || '')}</div></div>` : ''}
                    ${x.conciliation_hearing && x.conciliation_hearing.session_number ? `<div class="document-info-row"><div class="document-info-label">Session:</div><div class="document-info-value"><strong>#${escapeHtml(String(x.conciliation_hearing.session_number))}</strong>${x.conciliation_hearing.status ? ` <span style="font-size:0.72rem;color:#666;">(${escapeHtml(String(x.conciliation_hearing.status).toUpperCase())})</span>` : ''}</div></div>` : ''}
                    ${roleChips.length ? `<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;">${roleChips.join('')}</div>` : ''}
                    ${arbitrationBanner}
                </div>
                <div class="document-card-actions" onclick="event.stopPropagation()">
                    ${arbActions.join('')}
                </div>
            </div>`;
    }

    const actions = [];
    if (needsAssign) actions.push(`<button class="doc-action-btn assign" onclick="event.stopPropagation(); openAssignPositionsModal(${x.id})"><i class="fas fa-user-tag"></i> Assign Positions</button>`);
    if (myRole === 'chair' && x.status === 'pangkat_constituted') actions.push(`<button class="doc-action-btn schedule" onclick="event.stopPropagation(); openChairScheduleModal(${x.id})"><i class="fas fa-calendar-plus"></i> Schedule Hearing</button>`);
    if (isHearingInProgress || awaitsTerms) actions.push(`<button class="doc-action-btn enter" onclick="event.stopPropagation(); openHearingPage(${x.id})"><i class="fas fa-play-circle"></i> Open Hearing</button>`);
    if (myRole === 'chair' && x.status === 'pangkat_scheduled' && hearingIsTodayOrPast && !hearingStarted && !waitingForAppearance && !hasAppearanceSet) actions.push(`<button class="doc-action-btn start" onclick="event.stopPropagation(); chairStartHearing(${x.id})"><i class="fas fa-play-circle"></i> Start Hearing</button>`);

    // ── Chair only: single "Continue Conciliation" entry point ──
    const chairCanContinue = myRole === 'chair'
        && x.status === 'pangkat_scheduled'
        && (waitingForAppearance || hasAppearanceSet);
    if (chairCanContinue) {
        actions.push(`<button class="doc-action-btn decide"
            onclick="event.stopPropagation(); openConciliationContinueModal(${x.id})">
            <i class="fas fa-gavel"></i> Continue Conciliation
        </button>`);
    }

    if (myRole === 'chair' && hasAppearanceSet && appearancePassed) actions.push(`<button class="doc-action-btn decide" onclick="event.stopPropagation(); openChairAbsenceDecisionModal(${x.id})"><i class="fas fa-gavel"></i> Decide on Absences</button>`);
    if (myRole === 'secretary' && x.status === 'pangkat_scheduled' && !hearingStarted) actions.push(`<button class="doc-action-btn cert" onclick="event.stopPropagation(); openIssueCertificateModal(${x.id})"><i class="fas fa-file-certificate"></i> Issue Certificate</button>`);

    // ── Unresolved via respondent no-show → KP #22 + KP #24 issued ──
    const isRespondentNoShowUnresolved = isUnresolved && !!x.bar_counterclaim_cert_no;
    // ── Unresolved via normal No Settlement → KP #20-A still needed ──
    const isNoSettlementUnresolved = isUnresolved && !x.bar_counterclaim_cert_no && !x.bar_action_cert_no;

    if (myRole === 'secretary' && isNoSettlementUnresolved && !x.kp21_pdf_path) {
        actions.push(`<button class="doc-action-btn cert" onclick="event.stopPropagation(); openPrepareKp20aModal(${x.id})"><i class="fas fa-file-certificate"></i> Prepare KP #20-A</button>`);
    }
    if (isNoSettlementUnresolved && x.kp21_pdf_path) {
        actions.push(`<button class="doc-action-btn cert" onclick="event.stopPropagation(); window.open('../../${escapeHtml(x.kp21_pdf_path)}','_blank')"><i class="fas fa-file-pdf"></i> View KP #20-A</button>`);
    }
    if (isRespondentNoShowUnresolved && x.kp21_pdf_path) {
        actions.push(`<button class="doc-action-btn cert" onclick="event.stopPropagation(); window.open('../../${escapeHtml(x.kp21_pdf_path)}','_blank')"><i class="fas fa-file-pdf"></i> View KP #22</button>`);
    }

    actions.push(`<button class="doc-action-btn view" onclick="event.stopPropagation(); openComplaintDetail(${x.id})"><i class="fas fa-eye"></i> View Case</button>`);

    const settledRibbon = isSettledPending && !awaitsTerms ? '<div class="settled-ribbon"><i class="fas fa-hourglass-half"></i> 10-DAY WINDOW</div>' : (awaitsTerms ? '<div class="settled-ribbon" style="background:linear-gradient(135deg,#e65100,#f57c00);"><i class="fas fa-file-signature"></i> AWAITING TERMS</div>' : '');

    return `
        <div class="document-card ${needsAssign ? 'assign-needed' : ''}">
            ${needsAssign ? '<div class="assign-needed-ribbon">Assign</div>' : ''}
            ${isHearingInProgress ? '<div class="hearing-ribbon"><i class="fas fa-circle" style="font-size:0.5rem;margin-right:4px;"></i>IN PROGRESS</div>' : ''}
            ${settledRibbon}
            <div class="document-card-header ${headerCls}">
                <div class="document-title"><i class="fas ${statusIcon}"></i><span>${escapeHtml(x.title)}</span></div>
                <div class="document-status"><i class="fas ${statusIcon}"></i> ${escapeHtml(statusLbl)}</div>
            </div>
            <div class="document-card-body">
                <div class="document-info-row"><div class="document-info-label">Ref:</div><div class="document-info-value"><strong>${escapeHtml(x.reference_number)}</strong></div></div>
                <div class="document-info-row"><div class="document-info-label">Complainant:</div><div class="document-info-value">${escapeHtml(x.first_name || '')} ${escapeHtml(x.last_name || '')}</div></div>
                ${x.hearing_date ? `<div class="document-info-row"><div class="document-info-label">Hearing:</div><div class="document-info-value">${escapeHtml(x.hearing_date)} ${escapeHtml(x.hearing_time || '')}</div></div>` : ''}
                ${x.conciliation_hearing && x.conciliation_hearing.session_number ? `<div class="document-info-row"><div class="document-info-label">Session:</div><div class="document-info-value"><strong>#${escapeHtml(String(x.conciliation_hearing.session_number))}</strong>${x.conciliation_hearing.status ? ` <span style="font-size:0.72rem;color:#666;">(${escapeHtml(String(x.conciliation_hearing.status).toUpperCase())})</span>` : ''}</div></div>` : ''}
                ${concilPillHtml}
                ${explainPillHtml}
                ${settleCountdownHtml}
                ${roleChips.length ? `<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;">${roleChips.join('')}</div>` : ''}
                ${waitingMsg}
                ${arbitrationBanner}
            </div>
            <div class="document-card-actions" onclick="event.stopPropagation()">${actions.join('')}</div>
        </div>`;
}

// ============================================================
// START HEARING
// ============================================================
async function chairStartHearing(caseId) {
    const confirm = await Swal.fire({
        icon: 'question',
        title: 'Start the Conciliation Hearing?',
        html: `<div style="text-align:left;font-size:0.9rem;">
            <p>This will open the hearing session. The Secretary will be able to record attendance and statements.</p>
            <div style="padding:10px 12px;background:#f3e5f5;border-left:4px solid #6a1b9a;border-radius:8px;font-size:0.82rem;color:#4a148c;margin-top:10px;"><b>Note:</b> All members may leave and return anytime.</div>
        </div>`,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-play-circle"></i> Start Hearing',
        confirmButtonColor: '#6a1b9a',
        cancelButtonText: 'Cancel',
        cancelButtonColor: '#6c757d',
        allowOutsideClick: false
    });
    if (!confirm.isConfirmed) return;

    Swal.fire({ title: 'Starting hearing…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    const fd = new FormData();
    fd.append('action', 'lupon_start_conciliation_hearing');
    fd.append('complaint_id', caseId);
    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        Swal.close();
        if (d.success) {
            openHearingPage(caseId);
            Swal.fire({ icon: 'success', title: 'Hearing Started', text: 'All members have been notified.', confirmButtonColor: '#2E7D32', timer: 1200, showConfirmButton: false });
        } else Swal.fire('Error', d.message || 'Could not start hearing.', 'error');
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}

// ============================================================
// RESCHEDULE CONCILIATION MODAL (Standalone — from list card)
// ============================================================
async function openRescheduleConciliationModal(caseId) {
    let state = null;
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_lupon_hearing_state&complaint_id=${caseId}`);
        state = await r.json();
    } catch (e) {}
    const hard = state && state.conciliation_hard_deadline;
    const extDays = state && state.conciliation_extension_days ? state.conciliation_extension_days : 0;
    const extended = state && state.conciliation_extended === 1;
    const firstIso = state && state.conciliation_first_hearing_date;
    const today = new Date().toISOString().split('T')[0];

    const minDate = firstIso ? String(firstIso).split(' ')[0] : today;
    let maxDate = null;
    if (hard) {
        maxDate = String(hard).split(' ')[0];
    } else if (firstIso) {
        const totalDays = 15 + (extended ? extDays : 0);
        const dt = new Date(String(firstIso).split(' ')[0] + 'T00:00:00');
        dt.setDate(dt.getDate() + totalDays);
        maxDate = dt.toISOString().split('T')[0];
    }

    const windowLabel = extended
        ? `+15 days extended (30-day total)`
        : `Standard 15-day window`;

    const todayForInput = today;
    let seedDate = todayForInput;
    if (seedDate < minDate) seedDate = minDate;
    if (maxDate && seedDate > maxDate) seedDate = maxDate;

    const result = await Swal.fire({
        title: '',
        html: `<div style="text-align:left;">
            <div style="font-weight:800;color:#00838f;font-size:1.05rem;display:flex;align-items:center;gap:8px;padding-bottom:10px;margin-bottom:12px;border-bottom:2px solid #b2ebf2;">
                <i class="fas fa-calendar-alt"></i> Reschedule Conciliation
            </div>
            ${hard ? `<div class="concil-window-banner" style="margin-bottom:14px;">
                <div class="cw-label"><i class="fas fa-hourglass-half"></i> Window: ${new Date(minDate+'T00:00:00').toLocaleDateString('en-US',{dateStyle:'medium'})} → ${new Date(maxDate+'T00:00:00').toLocaleDateString('en-US',{dateStyle:'medium'})}</div>
                <span class="hearing-doc-chip teal">${extended ? '+15 Extended' : 'Standard 15-day'}</span>
            </div>` : ''}
            <div class="dm-field">
                <label>Reason for Reschedule <span class="req">*</span></label>
                <select id="reschedReason">
                    <option value="">— Select —</option>
                    <option value="Absent party justified — rescheduled">Absent party justified</option>
                    <option value="Both parties requested postponement">Both parties requested</option>
                    <option value="Additional evidence to be presented">Additional evidence</option>
                    <option value="Witness unavailable">Witness unavailable</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div class="dm-grid-2">
                <div class="dm-field">
                    <label>New Date <span class="req">*</span></label>
                    <input type="date" id="reschedDate"
                           min="${minDate}" ${maxDate ? `max="${maxDate}"` : ''}
                           value="${seedDate}"
                           title="${escapeHtml(windowLabel)}">
                </div>
                <div class="dm-field">
                    <label>New Time <span class="req">*</span></label>
                    <input type="time" id="reschedTime" value="09:00">
                </div>
            </div>
            <div class="dm-field"><label>Location</label><input type="text" id="reschedLocation" value="Barangay Hall"></div>
            <div class="dm-field"><label>Notes <span class="opt">(optional)</span></label><textarea id="reschedNotes" rows="2"></textarea></div>
            <div style="margin-top:6px;padding:10px 12px;background:#e0f7fa;border-left:4px solid #00838f;border-radius:8px;font-size:0.78rem;color:#006064;line-height:1.5;">
                <i class="fas fa-info-circle"></i>
                The new date must fall within the conciliation window:
                <b>${new Date(minDate+'T00:00:00').toLocaleDateString('en-US',{dateStyle:'medium'})}</b>
                →
                <b>${new Date(maxDate+'T00:00:00').toLocaleDateString('en-US',{dateStyle:'medium'})}</b>.
            </div>
        </div>`,
        width: 580,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-calendar-check"></i> Reschedule',
        confirmButtonColor: '#00838f',
        cancelButtonColor: '#6c757d',
        allowOutsideClick: false,
        preConfirm: () => {
            const reason = document.getElementById('reschedReason').value;
            const d = document.getElementById('reschedDate').value;
            const t = document.getElementById('reschedTime').value;
            if (!reason) { Swal.showValidationMessage('Select a reason.'); return false; }
            if (!d || !t) { Swal.showValidationMessage('Set date and time.'); return false; }
            if (d < minDate) { Swal.showValidationMessage('Cannot be before the first conciliation hearing (' + minDate + ').'); return false; }
            if (maxDate && d > maxDate) { Swal.showValidationMessage('Cannot be after the conciliation window end (' + maxDate + ').'); return false; }
            return {
                reason, date: d, time: t,
                location: document.getElementById('reschedLocation').value.trim() || 'Barangay Hall',
                notes: document.getElementById('reschedNotes').value.trim(),
            };
        }
    });
    if (!result.isConfirmed) return;

    Swal.fire({ title: 'Rescheduling…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    const fd = new FormData();
    fd.append('action', 'lupon_reschedule_conciliation');
    fd.append('complaint_id', caseId);
    fd.append('hearing_date', result.value.date);
    fd.append('hearing_time', result.value.time);
    fd.append('hearing_location', result.value.location);
    fd.append('reason', result.value.reason);
    fd.append('notes', result.value.notes);

    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        Swal.close();
        if (d.success) {
            Swal.fire({
                icon: 'success',
                title: 'Conciliation Rescheduled',
                html: `<div style="text-align:left;font-size:0.9rem;">
                    <p>New hearing on <b>${escapeHtml(result.value.date)}</b> at <b>${escapeHtml(result.value.time)}</b>.</p>
                    <p style="margin-top:8px;color:#666;font-size:0.82rem;">Session #${d.session_number} created.</p>
                </div>`,
                confirmButtonColor: '#2E7D32'
            }).then(() => loadMyComplaints());
        } else {
            Swal.fire('Error', d.message || 'Failed.', 'error');
        }
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}

// ============================================================
// EXTEND CONCILIATION MODAL
// ============================================================
async function openExtendConciliationModal(caseId) {
    const result = await Swal.fire({
        title: '',
        html: `<div style="text-align:left;">
            <div style="font-weight:800;color:#e65100;font-size:1.05rem;display:flex;align-items:center;gap:8px;padding-bottom:10px;margin-bottom:12px;border-bottom:2px solid #ffe0b2;">
                <i class="fas fa-clock"></i> Extend Conciliation +15 Days
            </div>
            <div class="handbook-quote">
                <div class="handbook-quote-title"><i class="fas fa-gavel"></i> RA 7160 / KP Handbook</div>
                <p>Per the Katarungang Pambarangay rules, the Pangkat may <b>extend the 15-day conciliation period by an additional 15 days</b> if the parties still need time to settle.</p>
                <p>Maximum total period: <b>30 days</b>.</p>
            </div>
            <div class="dm-field"><label>Reason for Extension <span class="req">*</span></label><textarea id="extendReason" rows="3" placeholder="e.g. Parties are finalizing the agreement terms."></textarea></div>
        </div>`,
        width: 580,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-clock"></i> Extend +15 Days',
        confirmButtonColor: '#e65100',
        cancelButtonColor: '#6c757d',
        allowOutsideClick: false,
        preConfirm: () => {
            const reason = document.getElementById('extendReason').value.trim();
            if (!reason) { Swal.showValidationMessage('Please give a reason.'); return false; }
            return { reason };
        }
    });
    if (!result.isConfirmed) return;

    Swal.fire({ title: 'Extending conciliation…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    const fd = new FormData();
    fd.append('action', 'lupon_extend_conciliation');
    fd.append('complaint_id', caseId);
    fd.append('reason', result.value.reason);

    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        Swal.close();
        if (d.success) {
            Swal.fire({
                icon: 'success',
                title: 'Conciliation Extended',
                html: `<div style="text-align:left;font-size:0.9rem;">
                    <p>Extension granted. New hard deadline:</p>
                    <p style="font-weight:700;color:#e65100;font-size:1rem;">${escapeHtml(d.hard_deadline_nice)}</p>
                </div>`,
                confirmButtonColor: '#2E7D32'
            }).then(() => loadMyComplaints());
        } else {
            Swal.fire('Error', d.message || 'Failed.', 'error');
        }
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}

// ============================================================
// ISSUE CERTIFICATE TO FILE ACTION MODAL (Secretary)
// ============================================================
async function openIssueCertificateModal(caseId) {
    let state = null;
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_lupon_hearing_state&complaint_id=${caseId}`);
        state = await r.json();
    } catch (e) {}

    const hard = state && state.conciliation_hard_deadline;
    const isWithinWindow = state && state.is_within_window;

    if (isWithinWindow) {
        Swal.fire({
            icon: 'info',
            title: 'Cannot Issue Yet',
            html: `<div style="text-align:left;font-size:0.9rem;">
                <p>The conciliation window has not yet expired.</p>
                <p style="margin-top:8px;"><b>Hard deadline:</b> ${hard ? new Date(hard.replace(' ','T')).toLocaleDateString('en-US',{dateStyle:'long'}) : '—'}</p>
                <p style="margin-top:8px;color:#666;font-size:0.82rem;">The Secretary may only issue the Certificate to File Action after the conciliation period ends.</p>
            </div>`,
            confirmButtonColor: '#2E7D32'
        });
        return;
    }

    const result = await Swal.fire({
        title: '',
        html: `<div style="text-align:left;">
            <div style="font-weight:800;color:#b71c1c;font-size:1.05rem;display:flex;align-items:center;gap:8px;padding-bottom:10px;margin-bottom:14px;border-bottom:2px solid #ffcdd2;">
                <i class="fas fa-file-certificate"></i> Issue Certificate to File Action
            </div>
            <div style="padding:12px 14px;background:#fff3e0;border-left:4px solid #f57c00;border-radius:8px;font-size:0.82rem;color:#e65100;line-height:1.55;margin-bottom:14px;">
                <b>⚠️ Physical Delivery Only.</b><br>
                This certificate will be saved as a soft copy for records — <b>NO digital signature</b> and <b>NO portal push</b>. The Secretary must print and hand-deliver it to the parties.
            </div>
            <div class="dm-field"><label>Reason / Notes <span class="req">*</span></label><textarea id="certReason" rows="3" placeholder="e.g. No settlement was reached after the conciliation period expired."></textarea></div>
        </div>`,
        width: 580,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-file-certificate"></i> Issue Certificate',
        confirmButtonColor: '#b71c1c',
        cancelButtonColor: '#6c757d',
        allowOutsideClick: false,
        preConfirm: () => {
            const reason = document.getElementById('certReason').value.trim();
            if (!reason) { Swal.showValidationMessage('Please give a reason.'); return false; }
            return { reason };
        }
    });
    if (!result.isConfirmed) return;

    Swal.fire({ title: 'Issuing certificate…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    const fd = new FormData();
    fd.append('action', 'lupon_issue_certificate_to_file');
    fd.append('complaint_id', caseId);
    fd.append('reason', result.value.reason);

    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        Swal.close();
        if (d.success) {
            Swal.fire({
                icon: 'success',
                title: 'Certificate Issued',
                html: `<div style="text-align:left;font-size:0.9rem;">
                    <p>Certificate to File Action issued.</p>
                    <p style="margin-top:8px;"><b>Cert No:</b> <span style="font-family:'Courier New',monospace;">${escapeHtml(d.certificate_no)}</span></p>
                    <p style="margin-top:8px;color:#b71c1c;font-size:0.85rem;"><b>⚠️ PHYSICAL DELIVERY REQUIRED.</b> Print and hand-deliver to both parties.</p>
                </div>`,
                confirmButtonColor: '#2E7D32'
            }).then(() => loadMyComplaints());
        } else {
            Swal.fire('Error', d.message || 'Failed.', 'error');
        }
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}

// ============================================================
// PREPARE KP #20-A (Pangkat Secretary CFA)
// ============================================================
async function openPrepareKp20aModal(caseId) {
    const result = await Swal.fire({
        title: '',
        html: `<div style="text-align:left;">
            <div style="font-weight:800;color:#b71c1c;font-size:1.05rem;display:flex;align-items:center;gap:8px;padding-bottom:10px;margin-bottom:14px;border-bottom:2px solid #ffcdd2;">
                <i class="fas fa-file-certificate"></i> Prepare KP Form #20-A
            </div>
            <div style="padding:12px 14px;background:#e3f2fd;border-left:4px solid #1565c0;border-radius:8px;font-size:0.85rem;color:#0d47a1;line-height:1.6;margin-bottom:14px;">
                <b>Certification to File Action</b> — issued by the <b>Pangkat Secretary</b>, attested by the <b>Pangkat Chairman</b>.
                <br>Both parties were present but <b>no settlement</b> was reached during conciliation.
            </div>
            <div style="padding:12px 14px;background:#fff3e0;border-left:4px solid #f57c00;border-radius:8px;font-size:0.82rem;color:#e65100;line-height:1.55;">
                <b>⚠️ Physical Delivery Only.</b><br>
                This certificate is stored as a soft copy for records. <b>No digital signature</b> and <b>no portal push</b>.
                The Secretary must print and hand-deliver it to both parties.
            </div>
        </div>`,
        width: 580,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-file-certificate"></i> Generate KP #20-A',
        confirmButtonColor: '#b71c1c',
        cancelButtonColor: '#6c757d',
        allowOutsideClick: false
    });
    if (!result.isConfirmed) return;

    Swal.fire({ title: 'Generating KP #20-A…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    const fd = new FormData();
    fd.append('action', 'generate_kp_form');
    fd.append('complaint_id', caseId);
    fd.append('form_tag', 'kp21');
    fd.append('issued_by', 'PANGKAT SECRETARY');

    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        Swal.close();
        if (d.success && d.pdf_url) {
            Swal.fire({
                icon: 'success',
                title: 'KP #20-A Generated',
                html: `<div style="text-align:left;font-size:0.9rem;">
                    <p>The Certification to File Action has been prepared.</p>
                    <p style="margin-top:8px;color:#b71c1c;font-size:0.85rem;"><b>⚠️ PHYSICAL DELIVERY REQUIRED.</b> Print and hand-deliver to both parties.</p>
                </div>`,
                confirmButtonColor: '#2E7D32'
            }).then(() => {
                window.open('../../' + d.pdf_url, '_blank');
                loadMyComplaints();
            });
        } else {
            Swal.fire('Error', d.message || 'Failed to generate KP #20-A.', 'error');
        }
    } catch (e) {
        Swal.close();
        Swal.fire('Error', 'Network error.', 'error');
    }
}

// ============================================================
// INLINE HEARING VIEW — DOCUMENTABLE UI
// ============================================================
window.__hearing = {
    active: false,
    complaintId: null,
    myRole: null,
    reference: null,
    sessionType: null,
    pollTimer: null,
    cachedCaseDetail: null,
    cachedKpForms: [],
    lastSignature: null,
    lastState: null,
    previousHearings: { conciliation: [], mediation: [] },
    parties: [],
    hearingId: 0,
    conciliationWindow: null,
    attendanceAutoOpened: false,
    __prevAttendanceFullyMarked: null,
    termsModalOpen: false,
    termsModalAutoOpened: false,
};

async function openHearingPage(caseId) {
    window.__hearing.active = true;
    window.__hearing.complaintId = caseId;
    window.__hearing.attendanceAutoOpened = false;
    window.__hearing.__prevAttendanceFullyMarked = null;
    window.__hearing.termsModalOpen = false;
    window.__hearing.termsModalAutoOpened = false;
    window.__hearing.__autoAppearanceKey = null;
    if (window.__hearing.pollTimer) clearInterval(window.__hearing.pollTimer);
    stopListPolling();

    const body = document.getElementById('dashboardBody');
    body.innerHTML = `
        <div class="hearing-inline-wrap">
            <div id="hearingInlineHost">
                <div style="text-align:center;padding:60px;color:#999;">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p style="margin-top:10px;">Loading hearing…</p>
                </div>
            </div>
        </div>`;

    await refreshHearingView();
    window.__hearing.pollTimer = setInterval(refreshHearingView, 3000);
}

function closeHearingPage() {
    if (window.__hearing.pollTimer) { clearInterval(window.__hearing.pollTimer); window.__hearing.pollTimer = null; }
    window.__hearing.active = false;
    window.__hearing.complaintId = null;
    window.__hearing.cachedCaseDetail = null;
    window.__hearing.cachedKpForms = [];
    window.__hearing.lastSignature = null;
    window.__hearing.lastState = null;
    window.__hearing.previousHearings = { conciliation: [], mediation: [] };
    window.__hearing.conciliationWindow = null;
    window.__hearing.attendanceAutoOpened = false;
    window.__hearing.__prevAttendanceFullyMarked = null;
    window.__hearing.termsModalOpen = false;
    window.__hearing.termsModalAutoOpened = false;
    window.__hearing.__autoAppearanceKey = null;
    renderMyComplaints();
}

async function refreshHearingView() {
    const cid = window.__hearing.complaintId;
    if (!cid) return;

    const host = document.getElementById('hearingInlineHost');
    if (!host) {
        if (window.__hearing.pollTimer) { clearInterval(window.__hearing.pollTimer); window.__hearing.pollTimer = null; }
        window.__hearing.active = false;
        return;
    }

    let d;
    try {
        const r = await fetch('../admin_complaint_ajax.php?action=get_lupon_hearing_state&complaint_id=' + cid);
        d = await r.json();
    } catch (e) { return; }

    if (!d || !d.success) {
        host.innerHTML = `
            <div class="hearing-doc">
                <div class="hearing-doc-header">
                    <div class="hearing-doc-header-left">
                        <div class="hearing-doc-seal"><i class="fas fa-exclamation-triangle"></i></div>
                        <div>
                            <div class="hearing-doc-title">Error</div>
                            <div class="hearing-doc-subtitle">Could not load hearing</div>
                        </div>
                    </div>
                </div>
                <div class="hearing-doc-body">
                    <div class="hearing-doc-ended-banner" style="background:#ffebee;border-color:#ef9a9a;">
                        <i class="fas fa-exclamation-circle" style="color:#c62828;"></i>
                        <h3>${escapeHtml(d?.message || 'Could not load hearing state.')}</h3>
                    </div>
                </div>
                <div class="hearing-doc-action-bar">
                    <button class="hearing-doc-action-btn back" onclick="closeHearingPage()">
                        <i class="fas fa-arrow-left"></i> Back to Complaints
                    </button>
                </div>
            </div>`;
        return;
    }

    /* ═════════════════════════════════════════════════════════════════
       SECRETARY — SETTLED, AWAITING TERMS
       ═════════════════════════════════════════════════════════════════ */
    if (d.my_role === 'secretary' && d.awaits_secretary_terms === true) {
        window.__hearing.myRole = d.my_role;
        window.__hearing.reference = d.reference;
        window.__hearing.complaintId = d.complaint_id;

        host.innerHTML = `
            <div class="hearing-doc">
                <div class="hearing-doc-header" style="background:linear-gradient(135deg,#1b5e20,#2E7D32 55%,#43a047);">
                    <div class="hearing-doc-header-left">
                        <div class="hearing-doc-seal"><i class="fas fa-file-signature"></i></div>
                        <div>
                            <div class="hearing-doc-title">Awaiting Settlement Terms</div>
                            <div class="hearing-doc-subtitle">Chairperson recorded: SETTLED</div>
                        </div>
                    </div>
                    <div class="hearing-doc-ref">
                        <div class="hearing-doc-ref-label">Reference No.</div>
                        <div class="hearing-doc-ref-value">${escapeHtml(d.reference || '—')}</div>
                    </div>
                </div>
                <div class="hearing-doc-body">
                    <div style="display:flex;gap:14px;align-items:flex-start;padding:20px 24px;margin-bottom:18px;background:linear-gradient(135deg,#fff8e1,#ffe0b2);border-left:5px solid #f57c00;border-radius:12px;box-shadow:0 4px 14px rgba(245,124,0,0.15);">
                        <i class="fas fa-hourglass-half" style="font-size:2rem;color:#e65100;flex-shrink:0;margin-top:2px;"></i>
                        <div>
                            <div style="font-weight:800;color:#8a3e00;font-size:1.05rem;margin-bottom:6px;">
                                Action Required — Write Settlement Terms
                            </div>
                            <div style="font-size:0.88rem;color:#5d2c00;line-height:1.6;">
                                The <b>Chairperson</b> recorded this case as <b>Settled</b>. Write the exact terms agreed by both parties
                                and generate <b>KP Form #16 (Amicable Settlement)</b>. The 10-day repudiation window will start once you save.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="hearing-doc-action-bar">
                    <button class="hearing-doc-action-btn back" onclick="closeHearingPage()">
                        <i class="fas fa-arrow-left"></i> Back to Complaints
                    </button>
                    <button class="hearing-doc-action-btn primary" style="background:#2E7D32;margin-left:auto;" onclick="openSettlementTermsModal()">
                        <i class="fas fa-file-signature"></i> Write Settlement Terms & Generate KP #16
                    </button>
                </div>
            </div>`;

        if (!window.__hearing.termsModalOpen && !Swal.isVisible()) {
            window.__hearing.termsModalOpen = true;
            setTimeout(() => {
                openSettlementTermsModal().then(() => {
                    window.__hearing.termsModalOpen = false;
                });
            }, 400);
        }
        return;
    }

    /* ═════════════════════════════════════════════════════════════════
       HEARING HAS ENDED — show closed banner, stop polling
       ═════════════════════════════════════════════════════════════════ */
    if (!d.is_active) {
        if (window.__hearing.pollTimer) { clearInterval(window.__hearing.pollTimer); window.__hearing.pollTimer = null; }
        host.innerHTML = `
            <div class="hearing-doc">
                <div class="hearing-doc-header">
                    <div class="hearing-doc-header-left">
                        <div class="hearing-doc-seal"><i class="fas fa-check-double"></i></div>
                        <div>
                            <div class="hearing-doc-title">Hearing Ended</div>
                            <div class="hearing-doc-subtitle">Proceedings · Closed</div>
                        </div>
                    </div>
                    <div class="hearing-doc-ref">
                        <div class="hearing-doc-ref-label">Reference No.</div>
                        <div class="hearing-doc-ref-value">${escapeHtml(d.reference || '—')}</div>
                    </div>
                </div>
                <div class="hearing-doc-body">
                    <div class="hearing-doc-ended-banner">
                        <i class="fas fa-check-circle"></i>
                        <h3>The hearing has been saved by the Chairperson.</h3>
                        <p>All parties have been notified. You may return to your complaints list.</p>
                    </div>
                </div>
                <div class="hearing-doc-action-bar">
                    <button class="hearing-doc-action-btn back" onclick="closeHearingPage()">
                        <i class="fas fa-arrow-left"></i> Back to Complaints
                    </button>
                </div>
            </div>`;
        return;
    }

    /* ═════════════════════════════════════════════════════════════════
       LIVE HEARING — stash state and render
       ═════════════════════════════════════════════════════════════════ */
    window.__hearing.myRole = d.my_role;
    window.__hearing.reference = d.reference;
    window.__hearing.sessionType = (d.hearing && d.hearing.session_type) ? d.hearing.session_type : 'conciliation';
    window.__hearing.parties = d.attendance || [];
    window.__hearing.hearingId = d.hearing_id || 0;
    window.__hearing.explainDeadline = d.concil_absence_explain_deadline || null;
    window.__hearing.conciliationWindow = {
        hard_deadline: d.conciliation_hard_deadline || null,
        extended: d.conciliation_extended || 0,
        extension_days: d.conciliation_extension_days || 0,
        first_hearing_date: d.conciliation_first_hearing_date || null,
        is_within_window: d.is_within_window !== false,
    };

    await ensureCaseDetailsForPage(d.complaint_id, true);
    await ensurePreviousHearings(d.complaint_id);
    await ensureKpForms(d.complaint_id);

    renderHearingInline(d);

    /* ═════════════════════════════════════════════════════════════════
       CHAIR — REAL-TIME AUTO-OPEN of the Set Appearance Date modal
       ----------------------------------------------------------------
       • Fires the instant the Secretary records a no-show.
       • Closes any pending toast first so the modal can open cleanly.
       • Runs ONLY once per unique no-show event (latched on
         complaint_id + hearing_id + waiting_since).
       • Latch resets the moment the appearance date is saved.
       ═════════════════════════════════════════════════════════════════ */
    if (d.my_role === 'chair' && d.complaint_id) {
        const waitingSince    = d.concil_absence_waiting_since || null;
        const appearanceAt    = d.concil_absence_appearance_at || null;
        const latestHearingId = d.hearing_id || 0;

        const eventKey = waitingSince
            ? `absence:${d.complaint_id}:${latestHearingId}:${waitingSince}`
            : null;

        const needsAppearanceModal = !!waitingSince && !appearanceAt;

        if (needsAppearanceModal) {
            if (window.__hearing.__autoAppearanceKey !== eventKey) {
                window.__hearing.__autoAppearanceKey = eventKey;

                // Clear any leftover toast so Swal.isVisible() is false
                // by the time we try to open the modal.
                Swal.close();

                setTimeout(() => {
                    // Only open if the user hasn't already opened a modal themselves
                    if (!Swal.isVisible()) {
                        openChairAppearanceModal(d.complaint_id, true);
                    }
                }, 400);
            }
        } else {
            // Reset the latch once the appearance date is set (or no waiting state)
            if (appearanceAt || !waitingSince) {
                window.__hearing.__autoAppearanceKey = null;
            }
        }
    }

    /* ═════════════════════════════════════════════════════════════════
       CHAIR — TOAST when the Secretary finishes marking attendance
       ----------------------------------------------------------------
       Suppressed when a no-show is pending, otherwise the modal and
       toast fight over the same Swal slot.
       ═════════════════════════════════════════════════════════════════ */
    if (d.my_role === 'chair') {
        const att = d.attendance || [];
        const totalParties = att.length;
        const marked = att.filter(p => p.status === 'attend' || p.status === 'no_show').length;
        const fullyMarked = totalParties > 0 && marked === totalParties;

        const waitingForAppearance = !!d.concil_absence_waiting_since && !d.concil_absence_appearance_at;

        if (!waitingForAppearance
            && window.__hearing.__prevAttendanceFullyMarked === false
            && fullyMarked === true) {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: 'Secretary has saved attendance — you may now save the hearing record.',
                showConfirmButton: false,
                timer: 4500
            });
        }
        window.__hearing.__prevAttendanceFullyMarked = fullyMarked;
    }

    /* ═════════════════════════════════════════════════════════════════
       SECRETARY — AUTO-OPEN the attendance modal once per session
       ═════════════════════════════════════════════════════════════════ */
    if (d.my_role === 'secretary' && !window.__hearing.attendanceAutoOpened) {
        window.__hearing.attendanceAutoOpened = true;
        setTimeout(() => { openSecretaryAttendanceModal(); }, 400);
    }

    /* ═════════════════════════════════════════════════════════════════
       PANGKAT MEMBER — AUTO-CLOSE only on NO-SHOW
       ----------------------------------------------------------------
       All-present hearing continues → member stays on the panel.
       No-show recorded → member's job is done → return to complaints.
       ═════════════════════════════════════════════════════════════════ */
    if (d.my_role === 'member' && d.is_active === true) {
        const att = d.attendance || [];
        const totalParties = att.length;
        const marked = att.filter(p => p.status === 'attend' || p.status === 'no_show').length;
        const fullyMarked = totalParties > 0 && marked === totalParties;
        const hasNoShow = att.some(p => p.status === 'no_show');

        if (fullyMarked && hasNoShow && !window.__hearing.__memberAutoClosed) {
            window.__hearing.__memberAutoClosed = true;
            if (window.__hearing.pollTimer) {
                clearInterval(window.__hearing.pollTimer);
                window.__hearing.pollTimer = null;
            }
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'info',
                title: 'A no-show was recorded. Returning to complaints…',
                showConfirmButton: false,
                timer: 1800
            });
            setTimeout(() => closeHearingPage(), 900);
            return;
        }
    }
}
async function ensureCaseDetailsForPage(complaintId, force = false) {
    if (!force && window.__hearing.cachedCaseDetail && Number(window.__hearing.cachedCaseDetail.complaint.id) === Number(complaintId)) return window.__hearing.cachedCaseDetail;
    try { const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${complaintId}`); const d = await r.json(); if (d && d.success) window.__hearing.cachedCaseDetail = d; } catch (e) {}
    return window.__hearing.cachedCaseDetail;
}

async function ensurePreviousHearings(complaintId) {
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${complaintId}`);
        const d = await r.json();
        if (d && d.success && Array.isArray(d.hearings)) {
            window.__hearing.previousHearings.conciliation = d.hearings.filter(h => h.session_type === 'conciliation' && h.status === 'completed');
            window.__hearing.previousHearings.mediation = d.hearings.filter(h => h.session_type === 'mediation' && h.status === 'completed');
        }
    } catch (e) {}
}

async function ensureKpForms(complaintId) {
    if (window.__hearing.cachedKpForms && window.__hearing.cachedKpForms.length) return window.__hearing.cachedKpForms;
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${complaintId}`);
        const d = await r.json();
        if (d && d.success && Array.isArray(d.kp_forms)) {
            window.__hearing.cachedKpForms = d.kp_forms.filter(f => f.is_generated);
        }
    } catch (e) {}
    return window.__hearing.cachedKpForms;
}

// ------------------------------------------------------------
// PRIOR HEARINGS — compact rows
// ------------------------------------------------------------
function buildPreviousHearingsBlockHtml(kind, hearings) {
    if (!Array.isArray(hearings) || !hearings.length) return '';
    const isConcil = kind === 'conciliation';
    const label = isConcil ? 'Previous Conciliation Hearings' : 'Previous Mediation Hearings';
    const blockId = isConcil ? 'prevConcilBlock' : 'prevMedBlock';
    const icon = isConcil ? 'fa-balance-scale' : 'fa-gavel';

    const items = hearings.map(h => {
        const niceDate = h.hearing_date ? new Date(h.hearing_date).toLocaleDateString('en-US', {dateStyle:'long'}) : '—';
        const niceTime = h.hearing_time || '';
        const outcomeLbl = h.outcome ? String(h.outcome).replace(/_/g,' ').toUpperCase() : (h.status ? String(h.status).toUpperCase() : '—');
        const sessionNo = parseInt(h.session_number || 1);
        return `<div class="prev-hearing-item" onclick="openPriorHearingDetail(${parseInt(h.id)})" style="cursor:pointer;" title="Click to view full details">
            <div class="prev-hearing-head">
                <span>${isConcil ? 'Conciliation' : 'Mediation'} Hearing #${sessionNo} — ${escapeHtml(outcomeLbl)}</span>
                <button type="button" class="prev-hearing-view-btn" onclick="event.stopPropagation(); openPriorHearingDetail(${parseInt(h.id)})"><i class="fas fa-eye"></i> View</button>
            </div>
            <div class="prev-hearing-meta">
                <span><i class="fas fa-calendar"></i> ${escapeHtml(niceDate)} ${escapeHtml(niceTime)}</span>
                <span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(h.location || 'Barangay Hall')}</span>
            </div>
        </div>`;
    }).join('');

    return `<div class="prev-hearings-block ${isConcil ? 'concil' : ''}" id="${blockId}">
        <div class="prev-hearings-header" onclick="togglePrevBlock('${blockId}')">
            <span><i class="fas ${icon}"></i> ${label} (${hearings.length})</span>
            <i class="fas fa-chevron-down toggle-icon"></i>
        </div>
        <div class="prev-hearings-body">${items}</div>
    </div>`;
}

function togglePrevBlock(id) {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('expanded');
}

// ------------------------------------------------------------
// PRIOR HEARING DETAIL MODAL
// ------------------------------------------------------------
async function openPriorHearingDetail(hearingId) {
    if (!hearingId) return;
    Swal.fire({ title: 'Loading hearing…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    let d;
    try { const r = await fetch(`../admin_complaint_ajax.php?action=get_hearing_details&hearing_id=${hearingId}`); d = await r.json(); }
    catch (e) { Swal.close(); Swal.fire('Error', 'Could not load hearing.', 'error'); return; }
    Swal.close();
    if (!d || !d.success) { Swal.fire('Error', d.message || 'Could not load hearing.', 'error'); return; }

    const h = d.hearing;
    const attendance = d.attendance || [];
    const parties = d.parties || [];
    const cs = d.complainant_statement || null;
    const rs = d.respondent_statement || null;

    const isMediation = h.session_type === 'mediation';
    const isConciliation = h.session_type === 'conciliation';
    const hdHeaderCls = isMediation ? 'mediation' : (isConciliation ? 'conciliation' : '');
    const sessionLabel = isMediation ? 'Mediation Hearing' : (isConciliation ? 'Conciliation Hearing' : 'Hearing');

    const niceDate = h.hearing_date ? new Date(h.hearing_date).toLocaleDateString('en-US', {dateStyle:'full'}) : '—';
    const niceTime = h.hearing_time || '—';
    const statusLbl = (h.status || '').replace(/_/g,' ').toUpperCase();
    const outcomeLbl = h.outcome ? String(h.outcome).replace(/_/g,' ').toUpperCase() : '—';

    let sessionRecordHtml = '';
    if (h.session_record && (h.session_record.summary || h.session_record.recorded_by_role)) {
        const sr = h.session_record;
        sessionRecordHtml = `<div class="hd-block"><div class="hd-block-title"><i class="fas fa-clipboard-list"></i> Hearing Record</div>${sr.summary ? `<div class="hd-summary">${escapeHtml(sr.summary).replace(/\n/g,'<br>')}</div>` : '<div class="hd-note">No written summary.</div>'}<div class="hd-note" style="margin-top:8px;">${sr.recorded_by_role ? `Recorded by <b>${escapeHtml(String(sr.recorded_by_role).toUpperCase())}</b>.` : ''}${sr.recorded_at ? ` On ${new Date(sr.recorded_at.replace(' ','T')).toLocaleString('en-US', {dateStyle:'medium', timeStyle:'short'})}.` : ''}</div></div>`;
    } else if (h.summary) {
        sessionRecordHtml = `<div class="hd-block"><div class="hd-block-title"><i class="fas fa-clipboard-list"></i> Hearing Record</div><div class="hd-summary">${escapeHtml(h.summary).replace(/\n/g,'<br>')}</div></div>`;
    } else {
        sessionRecordHtml = `<div class="hd-block"><div class="hd-block-title"><i class="fas fa-clipboard-list"></i> Hearing Record</div><div class="hd-note">No written record for this hearing.</div></div>`;
    }

    let statementsHtml = '';
    if ((cs && (cs.text || cs.audio_path)) || (rs && (rs.text || rs.audio_path))) {
        const renderStatement = (label, obj, kind) => {
            if (!obj || (!obj.text && !obj.audio_path)) {
                return `<div style="background:#fafbfa;border-left:4px solid #cfd8dc;border-radius:8px;padding:10px 14px;margin-bottom:8px;"><div style="font-weight:700;color:#1a2b22;font-size:0.85rem;">${escapeHtml(label)}</div><div style="margin-top:6px;color:#999;font-size:0.84rem;font-style:italic;">No statement recorded.</div></div>`;
            }
            const audio = obj.audio_path ? `<audio controls style="width:100%;margin-top:6px;"><source src="../../${escapeHtml(obj.audio_path)}"></audio>` : '';
            return `<div style="background:#fafbfa;border-left:4px solid ${kind === 'complainant' ? '#2E7D32' : '#ef6c00'};border-radius:8px;padding:10px 14px;margin-bottom:8px;"><div style="font-weight:700;color:#1a2b22;font-size:0.85rem;">${escapeHtml(label)}</div>${obj.text ? `<div style="margin-top:6px;font-size:0.84rem;line-height:1.55;">${escapeHtml(obj.text).replace(/\n/g,'<br>')}</div>` : ''}${audio}</div>`;
        };
        statementsHtml = `<div class="hd-block"><div class="hd-block-title"><i class="fas fa-microphone"></i> Party Statements</div>${renderStatement("Complainant's Statement", cs, 'complainant')}${renderStatement("Respondent's Statement", rs, 'respondent')}</div>`;
    } else {
        statementsHtml = `<div class="hd-block"><div class="hd-block-title"><i class="fas fa-microphone"></i> Party Statements</div><div class="hd-note">No party statements were recorded for this hearing.</div></div>`;
    }

    const mergeAttendance = () => {
        const byPid = {};
        attendance.forEach(a => { byPid[Number(a.party_id)] = a; });
        return parties.map(p => {
            const rec = byPid[Number(p.id)] || {};
            return { party_id: p.id, party_type: p.party_type, full_name: p.full_name, status: rec.status || 'pending', justification_status: rec.justification_status || null, appearance_notes: rec.appearance_notes || '' };
        });
    };
    const rows = mergeAttendance();
    const attHtml = rows.length ? `<div class="hd-block"><div class="hd-block-title"><i class="fas fa-users"></i> Attendance (${rows.length})</div><table class="hd-att-table"><thead><tr><th>Party</th><th>Role</th><th>Status</th><th>Decision</th><th>Notes</th></tr></thead><tbody>${rows.map(r => {
        const roleLabel = (r.party_type || '').charAt(0).toUpperCase() + (r.party_type || '').slice(1);
        const statusPill = r.status === 'no_show' ? '<span class="hd-att-pill no_show"><i class="fas fa-times"></i> No Show</span>' : (r.status === 'attend' ? '<span class="hd-att-pill attend"><i class="fas fa-check"></i> Attend</span>' : '<span class="hd-att-pill pending">Pending</span>');
        const justifPill = r.justification_status ? `<span class="hd-justif-pill ${r.justification_status}">${escapeHtml(r.justification_status.toUpperCase())}</span>` : '—';
        return `<tr><td><b>${escapeHtml(r.full_name || '—')}</b></td><td>${escapeHtml(roleLabel)}</td><td>${statusPill}</td><td>${justifPill}</td><td style="font-size:0.78rem;color:#555;">${escapeHtml(r.appearance_notes || '—')}</td></tr>`;
    }).join('')}</tbody></table></div>` : '';

    let hearingKpHtml = '';
    const kpData = [];
    if (isMediation) {
        kpData.push({ label: 'KP Form #8 — Notice of Hearing (Complainant)', pdf: h.kp8?.pdf_url, at: h.kp8?.issued_at, notified: h.kp8?.notified });
        kpData.push({ label: 'KP Form #9 — Summons (Respondent)', pdf: h.kp9?.pdf_url, at: h.kp9?.issued_at, notified: h.kp9?.notified });
    } else if (isConciliation) {
        kpData.push({ label: 'KP Form #12 — Notice of Hearing (Complainant)', pdf: h.kp12?.complainant?.pdf_url, at: h.kp12?.complainant?.issued_at, notified: h.kp12?.complainant?.notified });
        kpData.push({ label: 'KP Form #12 — Notice of Hearing (Respondent)', pdf: h.kp12?.respondent?.pdf_url, at: h.kp12?.respondent?.issued_at, notified: h.kp12?.respondent?.notified });
    }
    const kpRows = kpData.filter(k => k.pdf).map(k => {
        const issuedNice = k.at ? new Date(k.at.replace(' ','T')).toLocaleString('en-US', {dateStyle:'medium', timeStyle:'short'}) : '—';
        const badge = k.notified ? `<span class="hd-att-pill attend"><i class="fas fa-check"></i> Notified</span>` : `<span class="hd-att-pill no_show"><i class="fas fa-print"></i> Print</span>`;
        return `<div style="background:#fafbfa;border-left:4px solid #1565c0;border-radius:8px;padding:10px 14px;margin-bottom:8px;"><div style="font-weight:700;font-size:0.85rem;color:#1a2b22;display:flex;justify-content:space-between;align-items:center;gap:8px;"><span>${escapeHtml(k.label)}</span> ${badge}</div><div style="font-size:0.75rem;color:#666;margin-top:4px;">Issued: ${escapeHtml(issuedNice)}</div><a href="../../${escapeHtml(k.pdf)}" target="_blank" style="display:inline-block;margin-top:6px;padding:5px 12px;background:#1565c0;color:#fff;border-radius:6px;text-decoration:none;font-size:0.72rem;font-weight:700;"><i class="fas fa-file-pdf"></i> Open PDF</a></div>`;
    }).join('');
    if (kpRows) hearingKpHtml = `<div class="hd-block"><div class="hd-block-title"><i class="fas fa-file-signature"></i> KP Forms Issued for This Hearing</div>${kpRows}</div>`;

    await Swal.fire({
        title: '',
        html: `<div class="hd-modal"><div class="hd-header ${hdHeaderCls}"><div class="hd-header-left"><i class="fas fa-${isMediation ? 'gavel' : 'balance-scale'}"></i><div><div class="hd-title">${sessionLabel} #${parseInt(h.session_number || 1)}</div><div class="hd-sub">Ref: <b>${escapeHtml(h.reference_number)}</b> · ${escapeHtml(h.title || '')}</div></div></div></div><div class="hd-body"><div class="hd-block"><div class="hd-block-title"><i class="fas fa-info-circle"></i> Hearing Info</div><div class="hd-grid"><div class="hd-grid-item"><label>Date</label><span>${escapeHtml(niceDate)}</span></div><div class="hd-grid-item"><label>Time</label><span>${escapeHtml(niceTime)}</span></div><div class="hd-grid-item"><label>Location</label><span>${escapeHtml(h.location || 'Barangay Hall')}</span></div><div class="hd-grid-item"><label>Status</label><span>${escapeHtml(statusLbl)}</span></div><div class="hd-grid-item"><label>Outcome</label><span>${escapeHtml(outcomeLbl)}</span></div><div class="hd-grid-item"><label>Conducted By</label><span>${escapeHtml(h.conducted_by_name || '—')}</span></div></div></div>${sessionRecordHtml}${statementsHtml}${hearingKpHtml}${attHtml}</div></div>`,
        width: 900,
        showConfirmButton: true,
        confirmButtonText: 'Close',
        confirmButtonColor: '#1565c0',
        customClass: { popup: 'cd-popup' }
    });
}

// ------------------------------------------------------------
// SECTION BUILDERS
// ------------------------------------------------------------
function buildConciliationWindowBanner(state) {
    const w = window.__hearing.conciliationWindow;
    if (!w || !w.hard_deadline) return '';
    const deadline = new Date(w.hard_deadline.replace(' ','T'));
    const isExpired = Date.now() >= deadline.getTime();
    const daysLeft = Math.ceil((deadline.getTime() - Date.now()) / 86400000);
    const cls = isExpired ? 'danger' : (daysLeft <= 5 ? 'warn' : '');

    let extLabel = '';
    if (w.extended && w.extension_days > 0) extLabel = `<span class="hearing-doc-chip teal"><i class="fas fa-plus-circle"></i> +${w.extension_days} days extended</span>`;
    else extLabel = `<span class="hearing-doc-chip info">Standard 15-day</span>`;

    const canExtend = !w.extended && state.my_role === 'chair' && !isExpired;
    const canReschedule = state.my_role === 'chair' && !isExpired;

    const actions = [];
    if (canReschedule) actions.push(`<button class="hearing-doc-action-btn teal" style="padding:6px 12px;font-size:0.78rem;" onclick="openRescheduleConciliationModal(${state.complaint_id})"><i class="fas fa-calendar-alt"></i> Reschedule</button>`);
    if (canExtend) actions.push(`<button class="hearing-doc-action-btn warn" style="padding:6px 12px;font-size:0.78rem;" onclick="openExtendConciliationModal(${state.complaint_id})"><i class="fas fa-clock"></i> Extend +15 Days</button>`);

    return `<div class="concil-window-banner ${cls}">
        <div class="cw-label"><i class="fas fa-hourglass-half"></i> Conciliation window ends: <b>${deadline.toLocaleDateString('en-US',{dateStyle:'long'})}</b>${isExpired ? ' — EXPIRED' : ` — ${daysLeft} day(s) left`}</div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">${extLabel}${actions.join('')}</div>
    </div>`;
}

function buildSettlementBanner(state) {
    const c = window.__hearing.cachedCaseDetail;
    if (!c || !c.success) return '';
    const cc = c.complaint;
    if (cc.status !== 'settled_pending' && cc.status !== 'settled') return '';

    const isPending = cc.status === 'settled_pending';
    const deadline = cc.repudiation_deadline ? new Date(cc.repudiation_deadline.replace(' ','T')) : null;
    const daysLeft = deadline ? Math.max(0, Math.ceil((deadline.getTime() - Date.now()) / 86400000)) : 0;

    if (isPending) {
        return `<div class="settle-window-banner">
            <div>
                <div class="sw-label"><i class="fas fa-hourglass-half"></i> 10-Day Repudiation Window Active</div>
                <div class="sw-sub">Either party may contest this settlement on grounds of <b>fraud</b>, <b>violence</b>, or <b>intimidation</b>. After the window closes, the settlement becomes final and executory.</div>
                <div style="margin-top:8px;font-size:0.78rem;color:#006064;"><b>Signed on:</b> ${cc.settlement_signed_at ? new Date(cc.settlement_signed_at.replace(' ','T')).toLocaleDateString('en-US',{dateStyle:'long'}) : '—'}</div>
            </div>
            <div class="sw-countdown">${daysLeft} day${daysLeft === 1 ? '' : 's'} left</div>
        </div>`;
    } else {
        return `<div class="settle-window-banner executory">
            <div>
                <div class="sw-label"><i class="fas fa-check-double"></i> Settlement is Final &amp; Executory</div>
                <div class="sw-sub">The 10-day repudiation window has closed. The Amicable Settlement is now enforceable.</div>
            </div>
        </div>`;
    }
}

function buildCaseInfoSection() {
    const d = window.__hearing.cachedCaseDetail;
    if (!d || !d.success) return '';
    const c = d.complaint;
    const niceDate = c.created_at ? new Date(c.created_at.replace(' ','T')).toLocaleDateString('en-US', {dateStyle:'long'}) : '—';
    const bodyHtml = `
        <div class="hearing-doc-grid">
            <div class="hearing-doc-field"><label>Reference No.</label><span>${escapeHtml(c.reference_number || '—')}</span></div>
            <div class="hearing-doc-field"><label>Subject / Nature</label><span class="serif">${escapeHtml(c.complaint_subject || '—')}</span></div>
            <div class="hearing-doc-field"><label>Date Filed</label><span>${escapeHtml(niceDate)}</span></div>
            <div class="hearing-doc-field"><label>Priority Level</label><span>${escapeHtml((c.priority || 'medium').toUpperCase())}</span></div>
        </div>
        <div class="hearing-doc-text-block" style="margin-top:14px;">
            <label>Statement of the Complaint</label>
            <p>${escapeHtml(c.description || '—').replace(/\n/g,'<br>')}</p>
        </div>`;
    return hearingDocSection('sec-case-info', { num: 'I', title: 'Case Information', badge: `<span class="hearing-doc-chip info">${escapeHtml(c.reference_number || '')}</span>`, collapsed: false, bodyHtml });
}

function buildPartiesSection() {
    const d = window.__hearing.cachedCaseDetail;
    if (!d || !d.success) return '';
    const complainants = d.complainants || [];
    const respondents  = d.respondents  || [];
    const witnesses    = d.witnesses    || [];
    const personRow = (p, role) => {
        const roleLabel = role.charAt(0).toUpperCase() + role.slice(1);
        return `<div class="hearing-doc-person-row"><div class="hearing-doc-person-avatar ${role}"><i class="fas ${role === 'complainant' ? 'fa-user' : (role === 'respondent' ? 'fa-user-friends' : 'fa-user-tag')}"></i></div><div style="flex:1;min-width:0;"><div class="hearing-doc-person-name">${escapeHtml(p.full_name || '—')}</div><div class="hearing-doc-person-meta"><span class="hearing-doc-role-tag ${role}">${escapeHtml(roleLabel)}</span>${p.contact_number ? `<span><i class="fas fa-phone"></i> ${escapeHtml(p.contact_number)}</span>` : ''}${p.address ? `<span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(p.address)}</span>` : ''}</div></div></div>`;
    };
    let inner = '';
    if (complainants.length) inner += `<div style="margin-bottom:12px;"><div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:1.2px;color:#7a8e85;font-weight:700;margin-bottom:6px;">Complainant(s)</div>${complainants.map(p => personRow(p, 'complainant')).join('')}</div>`;
    if (respondents.length) inner += `<div style="margin-bottom:12px;"><div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:1.2px;color:#7a8e85;font-weight:700;margin-bottom:6px;">Respondent(s)</div>${respondents.map(p => personRow(p, 'respondent')).join('')}</div>`;
    if (witnesses.length) inner += `<div><div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:1.2px;color:#7a8e85;font-weight:700;margin-bottom:6px;">Witness(es)</div>${witnesses.map(p => personRow(p, 'witness')).join('')}</div>`;
    if (!inner) inner = '<div style="color:#999;font-size:0.85rem;">No parties on record.</div>';
    const totalPeople = complainants.length + respondents.length;
    return hearingDocSection('sec-parties', { num: 'II', title: 'Parties Involved', badge: `<span class="hearing-doc-chip muted">${totalPeople} principal · ${witnesses.length} witness</span>`, collapsed: false, bodyHtml: inner });
}

function buildAttendanceSection(state, role) {
    const att = state.attendance || [];
    const isSecretary = role === 'secretary';
    const totalParties = att.length;
    const marked = att.filter(p => p.status === 'attend' || p.status === 'no_show').length;
    const fullyMarked = totalParties > 0 && marked === totalParties;

    let titleBadge = fullyMarked ? `<span class="hearing-doc-chip safe"><i class="fas fa-check"></i> Fully Recorded</span>` : `<span class="hearing-doc-chip warning"><i class="fas fa-hourglass-half"></i> ${marked}/${totalParties} Marked</span>`;

    let rows = '';
    if (!att.length) {
        rows = `<div style="color:#999;font-size:0.85rem;">No attendance on record yet.</div>`;
    } else {
        rows = att.map(p => {
            const r = (p.party_type || '').toLowerCase();
            const st = p.status || 'pending';
            const pillClass = st === 'attend' ? 'safe' : (st === 'no_show' ? 'critical' : 'warning');
            const pillText = st === 'attend' ? 'ATTEND' : (st === 'no_show' ? 'NO SHOW' : 'PENDING');
            const roleLabel = r.charAt(0).toUpperCase() + r.slice(1);
            return `<div class="hearing-doc-person-row"><div class="hearing-doc-person-avatar ${r}"><i class="fas ${r === 'complainant' ? 'fa-user' : 'fa-user-friends'}"></i></div><div style="flex:1;min-width:0;"><div class="hearing-doc-person-name">${escapeHtml(p.full_name)}</div><div class="hearing-doc-person-meta"><span class="hearing-doc-role-tag ${r}">${escapeHtml(roleLabel)}</span></div></div><span class="hearing-doc-chip ${pillClass}">${pillText}</span></div>`;
        }).join('');
    }

    const readOnlyNote = !isSecretary ? `<div class="sec-readonly-note" style="margin-top:12px;padding:14px 16px;background:#f8faf8;border:1px dashed #cfd8dc;border-radius:8px;font-size:0.85rem;color:#666;display:flex;gap:10px;align-items:flex-start;line-height:1.55;"><i class="fas fa-info-circle" style="color:#999;flex-shrink:0;margin-top:2px;"></i><div>Only the <b>Pangkat Secretary</b> can record or modify attendance. You are viewing it in read-only mode.</div></div>` : '';
    return hearingDocSection('sec-attendance', { num: 'III', title: 'Attendance Record', badge: titleBadge, collapsed: false, bodyHtml: rows + readOnlyNote });
}

function buildStatementsSection(state, role) {
    const isSecretary = role === 'secretary';
    const d = window.__hearing.cachedCaseDetail;
    if (!d || !d.success) return '';

    const att = state.attendance || [];
    const totalParties = att.length;
    const marked = att.filter(p => p.status === 'attend' || p.status === 'no_show').length;
    const fullyMarked = totalParties > 0 && marked === totalParties;

    if (isSecretary && !fullyMarked) {
        return `<div class="hearing-doc-section">
            <div class="hearing-doc-section-title info">
                <span style="display:flex;align-items:center;gap:10px;"><span class="section-num">IV</span> Party Statements</span>
                <span class="hearing-doc-chip muted">Locked</span>
            </div>
            <div class="hearing-doc-section-body">
                <div class="batch-upload-empty">
                    <i class="fas fa-lock" style="font-size:1.5rem;display:block;margin-bottom:8px;color:#ccc;"></i>
                    <b>Attendance not yet recorded.</b><br>
                    Please record attendance first (Section III) to unlock the statements section.
                </div>
            </div>
        </div>`;
    }

    const hearings = d.hearings || [];
    const concilHearings = hearings.filter(h => h.session_type === 'conciliation');
    concilHearings.sort((a, b) => (Number(b.id) || 0) - (Number(a.id) || 0));
    const latest = concilHearings[0];
    const sr = (latest && latest.session_record) ? latest.session_record : {};
    const cs = sr.complainant_statement || null;
    const rs = sr.respondent_statement || null;

    const renderStmt = (label, obj, kind) => {
        if (!obj || (!obj.text && !obj.audio_path)) {
            return `<div class="hearing-doc-text-block" style="border-left-color:${kind === 'complainant' ? '#2E7D32' : '#ef6c00'};"><label>${escapeHtml(label)}</label><p style="color:#999;font-style:italic;">No statement recorded yet.</p></div>`;
        }
        const audio = obj.audio_path ? `<audio controls style="width:100%;margin-top:8px;"><source src="../../${escapeHtml(obj.audio_path)}"></audio>` : '';
        return `<div class="hearing-doc-text-block" style="border-left-color:${kind === 'complainant' ? '#2E7D32' : '#ef6c00'};"><label>${escapeHtml(label)}</label>${obj.text ? `<p>${escapeHtml(obj.text).replace(/\n/g,'<br>')}</p>` : '<p style="color:#999;font-style:italic;">(audio only)</p>'}${audio}</div>`;
    };

    const readOnlyNote = !isSecretary ? `<div class="sec-readonly-note" style="margin-top:12px;padding:14px 16px;background:#f8faf8;border:1px dashed #cfd8dc;border-radius:8px;font-size:0.85rem;color:#666;display:flex;gap:10px;align-items:flex-start;line-height:1.55;"><i class="fas fa-info-circle" style="color:#999;flex-shrink:0;margin-top:2px;"></i><div>Only the <b>Pangkat Secretary</b> can record or update party statements.</div></div>` : '';
    const actionBtn = isSecretary ? `<div style="margin-top:14px;padding-top:14px;border-top:1px dashed #cfd8dc;"><button class="hearing-doc-action-btn info" onclick="openSecretaryStatementStep()"><i class="fas fa-microphone"></i> ${(cs || rs) ? 'Edit Statements' : 'Record Statements'}</button></div>` : '';

    const hasCs = cs && (cs.text || cs.audio_path);
    const hasRs = rs && (rs.text || rs.audio_path);
    let stmtBadge = '';
    if (hasCs && hasRs) stmtBadge = `<span class="hearing-doc-chip safe"><i class="fas fa-check"></i> Both Recorded</span>`;
    else if (hasCs || hasRs) stmtBadge = `<span class="hearing-doc-chip warning"><i class="fas fa-hourglass-half"></i> Partial</span>`;
    else stmtBadge = `<span class="hearing-doc-chip muted">Not Recorded</span>`;

    const bodyHtml = `${renderStmt("Complainant's Statement", cs, 'complainant')}<div style="height:12px;"></div>${renderStmt("Respondent's Statement", rs, 'respondent')}${readOnlyNote}${actionBtn}`;
    return hearingDocSection('sec-statements', { num: 'IV', title: 'Party Statements', badge: stmtBadge, collapsed: false, bodyHtml });
}

function buildKpFormsSection() {
    const kpForms = window.__hearing.cachedKpForms || [];
    let bodyHtml = '';
    let badgeHtml = '';
    if (!kpForms.length) {
        badgeHtml = `<span class="hearing-doc-chip muted">None yet</span>`;
        bodyHtml = `<div style="color:#999;font-size:0.85rem;text-align:center;padding:14px;">No KP forms have been generated for this case yet.</div>`;
    } else {
        badgeHtml = `<span class="hearing-doc-chip safe">${kpForms.length} form(s)</span>`;
        bodyHtml = kpForms.map(f => {
            const issuedBy = escapeHtml(f.issued_by || '—');
            const toWhom = escapeHtml(f.to || '—');
            const issuedAt = f.generated_at ? new Date(f.generated_at.replace(' ','T')).toLocaleDateString('en-US',{dateStyle:'medium'}) : null;
            const noSigBadge = f.no_signature ? `<span class="hearing-doc-chip teal" style="font-size:0.62rem;"><i class="fas fa-file"></i> Soft copy only</span>` : '';
            const displayNo = (f.form_tag === 'kp21') ? '20-A' : f.form_no;
            return `<div class="hearing-doc-kp-entry"><div class="hearing-doc-kp-icon">✓</div><div class="hearing-doc-kp-body"><div class="hearing-doc-kp-title">KP #${displayNo} — ${escapeHtml(f.form_name)} ${noSigBadge}</div><div class="hearing-doc-kp-meta"><span><i class="fas fa-user-tie"></i> ${issuedBy}</span><span><i class="fas fa-user"></i> To: ${toWhom}</span>${issuedAt ? `<span><i class="fas fa-clock"></i> ${escapeHtml(issuedAt)}</span>` : ''}</div></div><button class="hearing-doc-kp-btn" onclick='openKpFormFromList(${JSON.stringify(f).replace(/'/g,"&#39;")})'><i class="fas fa-file-pdf"></i> View</button></div>`;
        }).join('');
    }
    return hearingDocSection('sec-kp-forms', { num: 'V', title: 'Generated KP Forms', badge: badgeHtml, collapsed: false, bodyHtml });
}

function buildPriorHearingsSection() {
    const medHtml = buildPreviousHearingsBlockHtml('mediation', window.__hearing.previousHearings.mediation || []);
    const concilHtml = buildPreviousHearingsBlockHtml('conciliation', window.__hearing.previousHearings.conciliation || []);
    if (!medHtml && !concilHtml) return '';
    const totalMed = (window.__hearing.previousHearings.mediation || []).length;
    const totalConcil = (window.__hearing.previousHearings.conciliation || []).length;
    const badgeHtml = `<span class="hearing-doc-chip muted">${totalMed + totalConcil} record(s)</span>`;
    return hearingDocSection('sec-prior', { num: 'VI', title: 'Prior Hearings & Records', badge: badgeHtml, collapsed: true, bodyHtml: `${medHtml}${concilHtml}` });
}

// ============================================================
// UPLOADED EVIDENCE — batch list
// ============================================================
function buildUploadedEvidenceSection(role) {
    const d = window.__hearing.cachedCaseDetail;
    if (!d || !d.success) return '';
    const docs = d.pangkat_documents || [];
    const isSecretary = role === 'secretary';
    const isChair = role === 'chair';

    const batches = [];
    const map = {};
    docs.forEach(doc => {
        const key = (doc.uploaded_by_role || 'unknown') + '|' + (doc.description || '') + '|' + (doc.created_at || '').substring(0, 16);
        if (!map[key]) { map[key] = { files: [], sample: doc }; batches.push(map[key]); }
        map[key].files.push(doc);
    });

    let badgeHtml = docs.length ? `<span class="hearing-doc-chip safe">${docs.length} file(s)</span>` : `<span class="hearing-doc-chip muted">Empty</span>`;

    let inner = '';
    if (!batches.length) {
        inner = `<div class="batch-upload-empty">
            <i class="fas fa-inbox" style="font-size:1.5rem;display:block;margin-bottom:8px;color:#ccc;"></i>
            No evidence uploaded yet.
        </div>`;
    } else {
        inner = batches.map((b, bi) => {
            const s = b.sample;
            const uploadedBy = escapeHtml(s.uploader_name || s.uploaded_by_role || '—');
            const when = s.created_at ? new Date(String(s.created_at).replace(' ','T')).toLocaleString('en-US',{dateStyle:'medium',timeStyle:'short'}) : '';
            const desc = escapeHtml(s.description || '(no description)');
            const fileRows = b.files.map(f => {
                const fname = escapeHtml(f.file_name || 'evidence-file');
                const fpath = f.file_path || '';
                const fsize = f.file_size ? (Number(f.file_size)/1024).toFixed(1) + ' KB' : '';
                const delBtn = (isSecretary || isChair) ? `<button class="buf-del" onclick="deletePangkatDoc(${f.id}, ${window.__hearing.complaintId})"><i class="fas fa-trash"></i></button>` : '';
                return `<div class="batch-upload-file-row">
                    <i class="fas fa-file-alt" style="color:#1565c0;"></i>
                    <span class="buf-name">${fname}</span>
                    <span class="buf-size">${fsize}</span>
                    ${fpath ? `<button class="buf-open" onclick="window.open('../../${escapeHtml(fpath)}','_blank')"><i class="fas fa-external-link-alt"></i> Open</button>` : ''}
                    ${delBtn}
                </div>`;
            }).join('');
            return `<div class="batch-upload-batch">
                <div class="batch-upload-batch-head">
                    <div>
                        <div class="batch-upload-batch-title">
                            <i class="fas fa-folder-open" style="color:#2E7D32;"></i>
                            Batch #${bi + 1} — ${b.files.length} file(s)
                        </div>
                        <div class="batch-upload-batch-desc">${desc}</div>
                        <div class="batch-upload-batch-meta">
                            <span><i class="fas fa-user-tie"></i> ${uploadedBy}</span>
                            <span><i class="fas fa-clock"></i> ${escapeHtml(when)}</span>
                        </div>
                    </div>
                </div>
                <div>${fileRows}</div>
            </div>`;
        }).join('');
    }

    const canUpload = isSecretary;
    const uploadBtn = canUpload ? `<div style="margin-top:14px;padding-top:14px;border-top:1px dashed #cfd8dc;">
        <button class="hearing-doc-action-btn warn" onclick="openSecretaryMultiUploadModal()">
            <i class="fas fa-plus-circle"></i> Upload
        </button>
    </div>` : '';

    return hearingDocSection('sec-evidence', {
        num: 'VII',
        title: 'Uploads & Evidence',
        badge: badgeHtml,
        collapsed: false,
        bodyHtml: inner + uploadBtn
    });
}

async function deletePangkatDoc(docId, complaintId) {
    const confirm = await Swal.fire({
        icon: 'warning',
        title: 'Delete this file?',
        text: 'This action cannot be undone.',
        showCancelButton: true,
        confirmButtonText: 'Delete',
        confirmButtonColor: '#c62828',
        cancelButtonColor: '#6c757d'
    });
    if (!confirm.isConfirmed) return;
    const fd = new FormData();
    fd.append('action', 'lupon_delete_document');
    fd.append('document_id', docId);
    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) {
            Swal.fire({ icon: 'success', title: 'Deleted', timer: 1000, showConfirmButton: false });
            window.__hearing.cachedCaseDetail = null;
            window.__hearing.lastSignature = null;
            const h = document.getElementById('hearingInlineHost');
            if (h) delete h.dataset.signature;
            refreshHearingView();
        } else Swal.fire('Error', d.message || 'Failed.', 'error');
    } catch (e) { Swal.fire('Error', 'Network error.', 'error'); }
}

// ------------------------------------------------------------
// ATTENDANCE MODAL
// ------------------------------------------------------------
function openSecretaryAttendanceModal() {
    const caseId = window.__hearing.complaintId;
    if (!caseId) { Swal.fire('Error', 'No active hearing.', 'error'); return; }
    fetch('../admin_complaint_ajax.php?action=get_lupon_hearing_state&complaint_id=' + caseId)
        .then(r => r.json())
        .then(state => {
            if (!state.success || !state.is_active) { Swal.fire('Info', 'This hearing is no longer active.', 'info'); return; }
            if (state.my_role !== 'secretary') { Swal.fire('Not Allowed', 'Only the Secretary may record attendance.', 'info'); return; }
            const cards = (state.attendance || []).map(p => {
                const r = (p.party_type || '').toLowerCase();
                const rl = r.charAt(0).toUpperCase() + r.slice(1);
                const st = p.status || 'pending';
                return `<div class="att-card ${st !== 'pending' ? st : ''}" data-lock-party-id="${p.party_id}"><div class="att-head"><div class="att-avatar ${r}"><i class="fas ${r === 'complainant' ? 'fa-user' : 'fa-user-friends'}"></i></div><div class="att-meta"><div class="att-name">${escapeHtml(p.full_name)}</div><div class="att-role ${r}">${escapeHtml(rl)}</div></div><span class="att-status-pill ${st}">${st === 'no_show' ? 'NO SHOW' : (st === 'attend' ? 'ATTEND' : 'PENDING')}</span></div><div class="att-btn-row"><button type="button" class="att-btn attend ${st === 'attend' ? 'active' : ''}" onclick="lockSetAtt(${p.party_id}, 'attend', this)"><i class="fas fa-check"></i> Attend</button><button type="button" class="att-btn no_show ${st === 'no_show' ? 'active' : ''}" onclick="lockSetAtt(${p.party_id}, 'no_show', this)"><i class="fas fa-times"></i> No Show</button></div></div>`;
            }).join('');

            Swal.fire({
                title: '',
                html: `<div class="dm-form"><div class="dm-header blue" style="border-radius:8px 8px 0 0;"><div class="dm-header-left"><i class="fas fa-clipboard-list"></i><div><div class="dm-header-title">Record Attendance</div><div class="dm-header-sub">Ref: ${escapeHtml(state.reference || '')}</div></div></div></div><div style="padding:20px 24px;background:#fff;max-height:64vh;overflow-y:auto;"><div style="padding:10px 12px;background:#e3f2fd;border-left:4px solid #1565c0;border-radius:8px;font-size:0.82rem;color:#0d47a1;line-height:1.55;margin-bottom:16px;">Mark each party as <b>Attend</b> or <b>No Show</b>, then click <b>Save Attendance</b>.</div><div id="lockAttHost">${cards}</div></div></div>`,
                width: 620,
                showCancelButton: false,
                confirmButtonText: '<i class="fas fa-save"></i> Save Attendance',
                confirmButtonColor: '#1565c0',
                allowOutsideClick: false,
                focusConfirm: false,
                didOpen: () => { window.__secretaryAttHearingId = state.hearing_id ? Number(state.hearing_id) : 0; },
                preConfirm: () => {
                    const rows = document.querySelectorAll('#lockAttHost .att-card');
                    const payload = [];
                    let allMarked = true;
                    rows.forEach(row => {
                        const pid = Number(row.dataset.lockPartyId);
                        const active = row.querySelector('.att-btn.active');
                        if (active) payload.push({ party_id: pid, status: active.classList.contains('attend') ? 'attend' : 'no_show' });
                        else allMarked = false;
                    });
                    if (!allMarked) { Swal.showValidationMessage('Please mark attendance for all parties.'); return false; }
                    return payload;
                }
            }).then(async (result) => {
                if (!result.isConfirmed || !result.value) return;
                const hearingId = window.__secretaryAttHearingId;
                if (!hearingId) { Swal.fire('Error', 'Hearing ID not found.', 'error'); return; }
                Swal.fire({ title: 'Saving attendance…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                const fd = new FormData();
                fd.append('action', 'secretary_save_concil_attendance');
                fd.append('complaint_id', caseId);
                fd.append('hearing_id', hearingId);
                fd.append('attendance', JSON.stringify(result.value));
                try {
                    const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
                    const d = await r.json();
                    Swal.close();
                    if (!d.success) { Swal.fire('Error', d.message || 'Failed to save attendance.', 'error'); return; }
                    if (d.absent_detected) {
                        // ── NO-SHOW → warn, then CLOSE the Secretary's panel ──
                        Swal.fire({
                            icon: 'warning',
                            title: 'Attendance Saved — No-Show Detected',
                            html: `<div style="text-align:left;font-size:0.9rem;">
                                <p>Some parties did not appear. The <b>Chairperson</b> has been notified and will be prompted to <b>Set Appearance Date</b> in real time.</p>
                                <p style="margin-top:8px;font-size:0.82rem;color:#b71c1c;">
                                    <b>5-Day Explain Window:</b> The absent party has 5 days after that appearance date to explain. If they fail to explain, the absence is <b>auto-declared UNJUSTIFIED</b>.
                                </p>
                            </div>`,
                            confirmButtonColor: '#e65100',
                            allowOutsideClick: false
                        }).then(() => {
                            closeHearingPage();   // ← Secretary leaves ONLY on no-show
                        });
                    } else {
                        // ── ALL PRESENT → success toast, but STAY on the panel ──
                        Swal.fire({
                            icon: 'success',
                            title: 'Attendance Saved',
                            text: 'All parties are marked present. The hearing continues.',
                            confirmButtonColor: '#2E7D32',
                            timer: 1500,
                            showConfirmButton: false
                        });
                        // Do NOT close — just refresh the panel to reflect the new attendance state
                        if (window.__hearing.active) {
                            window.__hearing.cachedCaseDetail = null;
                            window.__hearing.lastSignature = null;
                            const h = document.getElementById('hearingInlineHost');
                            if (h) delete h.dataset.signature;
                            refreshHearingView();
                        }
                    }
                } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
            });
        })
        .catch(() => Swal.fire('Error', 'Could not load attendance.', 'error'));
}

function lockSetAtt(partyId, status, btnEl) {
    const card = btnEl.closest('.att-card');
    if (!card) return;
    card.classList.remove('attend', 'no_show');
    card.classList.add(status);
    card.querySelectorAll('.att-btn').forEach(b => b.classList.remove('active'));
    btnEl.classList.add('active');
    const pill = card.querySelector('.att-status-pill');
    if (pill) { pill.classList.remove('attend', 'no_show', 'pending'); pill.classList.add(status); pill.textContent = status === 'attend' ? 'ATTEND' : 'NO SHOW'; }
}

// ------------------------------------------------------------
// STATEMENTS MODAL
// ------------------------------------------------------------
function openSecretaryStatementsModal() {
    const caseId = window.__hearing.complaintId;
    if (!caseId) { Swal.fire('Error', 'No active hearing.', 'error'); return; }
    fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${caseId}`)
        .then(r => r.json())
        .then(d => {
            if (!d || !d.success) { Swal.fire('Error', 'Could not load case.', 'error'); return; }
            const hearings = (d.hearings || []).filter(h => h.session_type === 'conciliation');
            hearings.sort((a, b) => (Number(b.id) || 0) - (Number(a.id) || 0));
            const latestHearing = hearings[0];
            const sr = latestHearing && latestHearing.session_record ? latestHearing.session_record : {};
            const cs = sr.complainant_statement || {};
            const rs = sr.respondent_statement || {};
            const hearingId = latestHearing ? latestHearing.id : (window.__hearing.hearingId || 0);

            Swal.fire({
                title: '',
                html: `<div class="dm-form"><div class="dm-header purple" style="border-radius:8px 8px 0 0;"><div class="dm-header-left"><i class="fas fa-microphone"></i><div><div class="dm-header-title">Record Party Statements</div><div class="dm-header-sub">Ref: ${escapeHtml(d.complaint.reference_number)}</div></div></div></div><div style="padding:22px 26px;background:#fff;max-height:70vh;overflow-y:auto;" id="stmtHost"><div style="padding:10px 12px;background:#f3e5f5;border-left:4px solid #6a1b9a;border-radius:8px;font-size:0.82rem;color:#4a148c;line-height:1.55;margin-bottom:16px;">For each party, either <b>type</b> the statement or <b>record</b> it as audio.</div>${_stmtCardHtml('sec', 'complainant', cs.text || '')}${_stmtCardHtml('sec', 'respondent', rs.text || '')}</div></div>`,
                width: 720,
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-save"></i> Save Statements',
                confirmButtonColor: '#6a1b9a',
                cancelButtonColor: '#6c757d',
                allowOutsideClick: false,
                didOpen: () => {
                    window.__secStmtHearingId = hearingId;
                    if (cs.audio_path) { const c = document.getElementById('sec-cs-card'); if (c) c.dataset.serverAudio = '1'; }
                    if (rs.audio_path) { const c = document.getElementById('sec-rs-card'); if (c) c.dataset.serverAudio = '1'; }
                    _pstmtValidateCard('sec', 'complainant');
                    _pstmtValidateCard('sec', 'respondent');
                },
                preConfirm: async () => {
                    const csText = document.getElementById('sec-cs-text').value.trim();
                    const rsText = document.getElementById('sec-rs-text').value.trim();
                    const csCard = document.getElementById('sec-cs-card');
                    const rsCard = document.getElementById('sec-rs-card');
                    const csHasNew   = !!window.__stmtBlobs['sec-complainant'];
                    const csHasSaved = csCard && csCard.dataset.serverAudio === '1';
                    const rsHasNew   = !!window.__stmtBlobs['sec-respondent'];
                    const rsHasSaved = rsCard && rsCard.dataset.serverAudio === '1';
                    const csOk = csText !== '' || csHasNew || csHasSaved;
                    const rsOk = rsText !== '' || rsHasNew || rsHasSaved;
                    if (!csOk && !rsOk) { Swal.showValidationMessage("Both Complainant's and Respondent's statements are required (text or audio)."); return false; }
                    if (!csOk) { Swal.showValidationMessage("Complainant's statement is required — type it or record audio."); return false; }
                    if (!rsOk) { Swal.showValidationMessage("Respondent's statement is required — type it or record audio."); return false; }
                    return { csText, rsText };
                }
            }).then(async (result) => {
                if (!result.isConfirmed || !result.value) return;
                Swal.fire({ title: 'Saving statements…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                const uploadRes = await uploadAllPendingStatements('sec', caseId, window.__secStmtHearingId);
                const failedUploads = Object.entries(uploadRes).filter(([_, r]) => !r.success).map(([p, r]) => p + ': ' + (r.message || 'unknown'));
                if (failedUploads.length) { Swal.close(); Swal.fire('Upload Failed', 'Could not upload: <br>' + failedUploads.join('<br>'), 'error'); return; }
                const fd = new FormData();
                fd.append('action', 'lupon_save_statement_records');
                fd.append('complaint_id', caseId);
                fd.append('hearing_id', window.__secStmtHearingId);
                fd.append('complainant_statement', result.value.csText);
                fd.append('respondent_statement', result.value.rsText);
                try {
                    const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
                    const d = await r.json();
                    Swal.close();
                    if (d.success) {
                        _pstmtReleaseAll('sec');
                        Swal.fire({ icon: 'success', title: 'Statements Saved', timer: 1500, showConfirmButton: false });
                        if (window.__hearing.active) {
                            window.__hearing.cachedCaseDetail = null;
                            window.__hearing.lastSignature = null;
                            const h = document.getElementById('hearingInlineHost');
                            if (h) delete h.dataset.signature;
                            refreshHearingView();
                        }
                    } else Swal.fire('Error', d.message || 'Failed.', 'error');
                } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
            });
        })
        .catch(() => Swal.fire('Error', 'Could not load case.', 'error'));
}

function openSecretaryStatementStep() { openSecretaryStatementsModal(); }

// ------------------------------------------------------------
// MULTI-FILE UPLOAD
// ------------------------------------------------------------
function openSecretaryMultiUploadModal() {
    const caseId = window.__hearing.complaintId;
    if (!caseId) { Swal.fire('Error', 'No active hearing.', 'error'); return; }
    Swal.fire({
        title: '',
        html: `<div class="dm-form"><div class="dm-header green" style="border-radius:8px 8px 0 0;"><div class="dm-header-left"><i class="fas fa-upload"></i><div><div class="dm-header-title">Upload Documents</div><div class="dm-header-sub">Attach supporting documents (multiple allowed)</div></div></div></div><div style="padding:22px 26px;background:#fff;"><div style="padding:10px 12px;background:#e8f5e9;border-left:4px solid #2E7D32;border-radius:8px;font-size:0.82rem;color:#1b5e20;line-height:1.55;margin-bottom:16px;">Upload signed minutes, photos, or any supporting documents. Max <b>10 MB</b> per file. You can upload as many batches as you need.</div><div class="dm-field"><label>Description <span class="req">*</span></label><input type="text" id="secBatchDesc" placeholder="e.g. Signed minutes from hearing #1" required></div><div class="dm-field"><label>Select Files <span class="req">*</span></label><input type="file" id="secMultiFiles" multiple accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx"><div style="font-size:0.72rem;color:#888;margin-top:4px;">PDF, image, or Word file</div></div><div class="multi-upload-list" id="secMultiList"></div></div></div>`,
        width: 620,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-upload"></i> Upload Batch',
        confirmButtonColor: '#2E7D32',
        cancelButtonColor: '#6c757d',
        allowOutsideClick: false,
        didOpen: () => {
            const input = document.getElementById('secMultiFiles');
            const list = document.getElementById('secMultiList');
            window.__secMultiFiles = [];
            input.addEventListener('change', () => {
                list.innerHTML = '';
                window.__secMultiFiles = Array.from(input.files || []);
                window.__secMultiFiles.forEach((f, idx) => {
                    const row = document.createElement('div');
                    row.className = 'batch-upload-file-row';
                    row.innerHTML = `<i class="fas fa-file" style="color:#1565c0;"></i><span class="buf-name">${escapeHtml(f.name)}</span><span class="buf-size">${(f.size/1024).toFixed(1)} KB</span><button type="button" class="buf-del" title="Remove"><i class="fas fa-times"></i></button>`;
                    row.querySelector('.buf-del').onclick = () => { window.__secMultiFiles.splice(idx, 1); row.remove(); };
                    list.appendChild(row);
                });
            });
        },
        preConfirm: () => {
            const desc = document.getElementById('secBatchDesc').value.trim();
            if (!desc) { Swal.showValidationMessage('Please provide a description for this batch.'); return false; }
            if (!window.__secMultiFiles || !window.__secMultiFiles.length) { Swal.showValidationMessage('Choose at least one file.'); return false; }
            for (const f of window.__secMultiFiles) if (f.size > 10 * 1024 * 1024) { Swal.showValidationMessage(`File "${f.name}" exceeds 10 MB.`); return false; }
            return { files: window.__secMultiFiles, description: desc };
        }
    }).then(async (result) => {
        if (!result.isConfirmed || !result.value) return;
        Swal.fire({ title: 'Uploading batch…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const fd = new FormData();
        fd.append('action', 'lupon_upload_multiple_documents');
        fd.append('complaint_id', caseId);
        fd.append('description', result.value.description);
        result.value.files.forEach((f) => fd.append('documents[]', f));
        try {
            const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
            const d = await r.json();
            Swal.close();
            if (d.success) {
                Swal.fire({ icon: 'success', title: 'Batch Uploaded', text: `${d.uploaded_count} file(s) uploaded.`, confirmButtonColor: '#2E7D32', timer: 1500, showConfirmButton: false });
                if (window.__hearing.active) {
                    window.__hearing.cachedCaseDetail = null;
                    window.__hearing.lastSignature = null;
                    const h = document.getElementById('hearingInlineHost');
                    if (h) delete h.dataset.signature;
                    refreshHearingView();
                }
            } else Swal.fire('Error', d.message || 'Upload failed.', 'error');
        } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
    });
}

// ============================================================
// STATEMENT RECORDER UI
// ============================================================
window.__recorders     = window.__recorders     || {};
window.__stmtBlobs     = window.__stmtBlobs     || {};
window.__stmtPlayAudio = window.__stmtPlayAudio || {};
window.__stmtTimers    = window.__stmtTimers    || {};
window.__stmtStartTime = window.__stmtStartTime || {};

function _stmtCardHtml(prefix, party, text) {
    const roleLc = party;
    const sc = party === 'complainant' ? 'cs' : 'rs';
    const label = party === 'complainant' ? "Complainant's Statement" : "Respondent's Statement";
    const icon = party === 'complainant' ? 'fa-user' : 'fa-user-friends';
    return `
    <div class="pstmt-card" data-party="${roleLc}" id="${prefix}-${sc}-card">
        <div class="pstmt-head">
            <div class="pstmt-avatar ${roleLc}"><i class="fas ${icon}"></i></div>
            <div style="flex:1;min-width:0;">
                <div class="pstmt-name">
                    ${label} <span class="pstmt-required" style="color:#c62828;font-weight:800;">*</span>
                </div>
                <div class="pstmt-sub">Type below <b>or</b> record an audio statement — at least one is required.</div>
            </div>
            <span class="pstmt-status-chip" id="${prefix}-${sc}-chip">Required</span>
        </div>
        <div class="dm-field" style="margin-bottom:0;">
            <textarea id="${prefix}-${sc}-text" rows="3"
                placeholder="Type ${party}'s statement here…"
                oninput="_pstmtValidateCard('${prefix}','${roleLc}')">${escapeHtml(text)}</textarea>
        </div>
        <div class="pstmt-rec-wrap" id="${prefix}-${sc}-rec-wrap">
            <button type="button" class="pstmt-rec-btn rec" id="${prefix}-${sc}-rec" onclick="startStatementRecording('${prefix}','${party}')"><i class="fas fa-circle"></i> Record</button>
            <button type="button" class="pstmt-rec-btn stop" id="${prefix}-${sc}-stop" style="display:none;" onclick="stopStatementRecording('${prefix}','${party}')"><i class="fas fa-stop"></i> Stop</button>
            <div class="pstmt-wave" id="${prefix}-${sc}-wave" style="display:none;"><div class="bar"></div><div class="bar"></div><div class="bar"></div><div class="bar"></div><div class="bar"></div><div class="bar"></div><div class="bar"></div></div>
            <span class="pstmt-timer" id="${prefix}-${sc}-timer" style="display:none;">00:00</span>
        </div>
        <div class="pstmt-preview" id="${prefix}-${sc}-preview" style="display:none;">
            <button type="button" class="pstmt-play-btn" id="${prefix}-${sc}-play" onclick="toggleStatementPlayback('${prefix}','${party}')"><i class="fas fa-play" id="${prefix}-${sc}-play-icon"></i></button>
            <div class="pstmt-play-wave" id="${prefix}-${sc}-play-wave"><div class="bar"></div><div class="bar"></div><div class="bar"></div><div class="bar"></div><div class="bar"></div><div class="bar"></div><div class="bar"></div><div class="bar"></div></div>
            <div class="pstmt-preview-meta"><div class="pstmt-preview-label">Recording ready</div><div class="pstmt-preview-dur" id="${prefix}-${sc}-dur">0:00</div></div>
            <button type="button" class="pstmt-remove-btn" id="${prefix}-${sc}-remove" title="Remove recording" onclick="removeStatementRecording('${prefix}','${party}')"><i class="fas fa-times"></i></button>
        </div>
    </div>`;
}

async function startStatementRecording(prefix, party) {
    const key = prefix + '-' + party;
    if (window.__recorders[key]) return;
    if (!navigator.mediaDevices || !window.MediaRecorder) { Swal.fire('Not supported', 'Audio recording is not supported.', 'warning'); return; }
    let stream;
    try { stream = await navigator.mediaDevices.getUserMedia({ audio: true }); }
    catch (err) { Swal.fire('Microphone Error', 'Could not access microphone: ' + (err.name || 'unknown'), 'error'); return; }
    let rec;
    try { rec = new MediaRecorder(stream); }
    catch (e) { stream.getTracks().forEach(t => t.stop()); Swal.fire('Recorder Error', 'Could not create MediaRecorder.', 'error'); return; }
    const chunks = [];
    rec.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
    rec.onstop = () => {
        stream.getTracks().forEach(t => t.stop());
        delete window.__recorders[key];
        _pstmtStopTimer(key);
        const blob = new Blob(chunks, { type: rec.mimeType || 'audio/webm' });
        if (!blob.size) { _pstmtHideRecordingUi(prefix, party); Swal.fire('Empty recording', 'No audio captured.', 'warning'); return; }
        if (window.__stmtBlobs[key] && window.__stmtBlobs[key].url) URL.revokeObjectURL(window.__stmtBlobs[key].url);
        const url = URL.createObjectURL(blob);
        window.__stmtBlobs[key] = { blob, url, dur: _pstmtDuration(blob) };
        _pstmtShowPreview(prefix, party);
        _pstmtValidateCard(prefix, party);
    };
    try { rec.start(); }
    catch (e) { stream.getTracks().forEach(t => t.stop()); Swal.fire('Recorder Error', 'Could not start recording: ' + (e.name || ''), 'error'); return; }
    window.__recorders[key] = rec;
    window.__stmtStartTime[key] = Date.now();
    _pstmtShowRecordingUi(prefix, party);
    _pstmtStartTimer(prefix, party);
}
function stopStatementRecording(prefix, party) {
    const key = prefix + '-' + party;
    const rec = window.__recorders[key];
    if (rec && rec.state !== 'inactive') rec.stop();
}
function _pstmtStartTimer(prefix, party) {
    const sc = party === 'complainant' ? 'cs' : 'rs';
    const key = prefix + '-' + party;
    const el = document.getElementById(prefix + '-' + sc + '-timer');
    if (window.__stmtTimers[key]) clearInterval(window.__stmtTimers[key]);
    window.__stmtTimers[key] = setInterval(() => {
        const start = window.__stmtStartTime[key] || Date.now();
        const secs = Math.floor((Date.now() - start) / 1000);
        const m = String(Math.floor(secs / 60)).padStart(2, '0');
        const s = String(secs % 60).padStart(2, '0');
        if (el) el.textContent = m + ':' + s;
    }, 250);
}
function _pstmtStopTimer(key) { if (window.__stmtTimers[key]) { clearInterval(window.__stmtTimers[key]); delete window.__stmtTimers[key]; } }
function _pstmtShowRecordingUi(prefix, party) {
    const sc = party === 'complainant' ? 'cs' : 'rs';
    _pstmtHidePreview(prefix, party);
    document.getElementById(prefix + '-' + sc + '-rec').style.display = 'none';
    document.getElementById(prefix + '-' + sc + '-stop').style.display = 'inline-flex';
    document.getElementById(prefix + '-' + sc + '-wave').style.display = 'flex';
    const t = document.getElementById(prefix + '-' + sc + '-timer'); t.style.display = 'inline-block'; t.textContent = '00:00';
}
function _pstmtHideRecordingUi(prefix, party) {
    const sc = party === 'complainant' ? 'cs' : 'rs';
    document.getElementById(prefix + '-' + sc + '-rec').style.display = 'inline-flex';
    document.getElementById(prefix + '-' + sc + '-stop').style.display = 'none';
    document.getElementById(prefix + '-' + sc + '-wave').style.display = 'none';
    document.getElementById(prefix + '-' + sc + '-timer').style.display = 'none';
}
function _pstmtDuration(blob) { return Math.max(1, Math.round(blob.size / 8000)); }
function _pstmtShowPreview(prefix, party) {
    const sc = party === 'complainant' ? 'cs' : 'rs';
    _pstmtHideRecordingUi(prefix, party);
    document.getElementById(prefix + '-' + sc + '-preview').style.display = 'flex';
    const key = prefix + '-' + party;
    const data = window.__stmtBlobs[key];
    const durEl = document.getElementById(prefix + '-' + sc + '-dur');
    if (data) durEl.textContent = data.dur + 's';
    _pstmtMarkCardState(prefix, party);
}
function _pstmtHidePreview(prefix, party) {
    const sc = party === 'complainant' ? 'cs' : 'rs';
    document.getElementById(prefix + '-' + sc + '-preview').style.display = 'none';
}
function toggleStatementPlayback(prefix, party) {
    const sc = party === 'complainant' ? 'cs' : 'rs';
    const key = prefix + '-' + party;
    const data = window.__stmtBlobs[key];
    if (!data) return;
    let audio = window.__stmtPlayAudio[key];
    if (audio && !audio.paused) {
        audio.pause();
        _pstmtSetPlayIcon(prefix, party, false);
        document.getElementById(prefix + '-' + sc + '-play-wave').classList.remove('playing');
        document.getElementById(prefix + '-' + sc + '-play').classList.remove('playing');
        return;
    }
    if (!audio) {
        audio = new Audio(data.url);
        audio.addEventListener('ended', () => { _pstmtSetPlayIcon(prefix, party, false); document.getElementById(prefix + '-' + sc + '-play-wave').classList.remove('playing'); document.getElementById(prefix + '-' + sc + '-play').classList.remove('playing'); });
        audio.addEventListener('pause', () => { _pstmtSetPlayIcon(prefix, party, false); document.getElementById(prefix + '-' + sc + '-play-wave').classList.remove('playing'); document.getElementById(prefix + '-' + sc + '-play').classList.remove('playing'); });
        audio.addEventListener('play', () => { _pstmtSetPlayIcon(prefix, party, true); document.getElementById(prefix + '-' + sc + '-play-wave').classList.add('playing'); document.getElementById(prefix + '-' + sc + '-play').classList.add('playing'); });
        window.__stmtPlayAudio[key] = audio;
    }
    audio.currentTime = 0;
    audio.play().catch(err => Swal.fire('Playback error', err.message || 'Could not play audio.', 'error'));
}
function _pstmtSetPlayIcon(prefix, party, playing) {
    const sc = party === 'complainant' ? 'cs' : 'rs';
    const icon = document.getElementById(prefix + '-' + sc + '-play-icon');
    if (icon) icon.className = playing ? 'fas fa-pause' : 'fas fa-play';
}
function removeStatementRecording(prefix, party) {
    const sc = party === 'complainant' ? 'cs' : 'rs';
    const key = prefix + '-' + party;
    if (window.__stmtPlayAudio[key]) { try { window.__stmtPlayAudio[key].pause(); } catch (e) {} delete window.__stmtPlayAudio[key]; }
    if (window.__stmtBlobs[key] && window.__stmtBlobs[key].url) URL.revokeObjectURL(window.__stmtBlobs[key].url);
    delete window.__stmtBlobs[key];
    _pstmtHidePreview(prefix, party);
    document.getElementById(prefix + '-' + sc + '-rec').style.display = 'inline-flex';
    _pstmtMarkCardState(prefix, party);
}
function _pstmtValidateCard(prefix, party) {
    const sc = party === 'complainant' ? 'cs' : 'rs';
    const card = document.getElementById(prefix + '-' + sc + '-card');
    if (!card) return true;

    const hasText  = (document.getElementById(prefix + '-' + sc + '-text')?.value.trim() || '').length > 0;
    const hasNewAudio = !!window.__stmtBlobs[prefix + '-' + party];
    const hasSavedAudio = card.dataset.serverAudio === '1';
    const hasAudio = hasNewAudio || hasSavedAudio;
    const valid = hasText || hasAudio;

    card.classList.toggle('err', !valid);
    card.classList.toggle('ok',  valid);
    card.style.borderColor = valid ? '#a5d6a7' : '#ef9a9a';
    card.style.borderLeftColor = valid ? '#2E7D32' : '#c62828';

    const chip = document.getElementById(prefix + '-' + sc + '-chip');
    if (chip) {
        if (valid) {
            chip.textContent = hasText && hasAudio ? 'Text + Audio' : (hasText ? 'Text Recorded' : 'Audio Recorded');
            chip.classList.add('ok');
        } else {
            chip.textContent = 'Required';
            chip.classList.remove('ok');
        }
    }
    _pstmtRefreshSaveBtn(prefix);
    return valid;
}

function _pstmtMarkCardState(prefix, party) {
    return _pstmtValidateCard(prefix, party);
}

function _pstmtRefreshSaveBtn(prefix) {
    const swal = Swal.getPopup();
    if (!swal) return;
    const btn = swal.querySelector('.swal2-confirm');
    if (!btn) return;
    const csOk = _pstmtCardValid(prefix, 'complainant');
    const rsOk = _pstmtCardValid(prefix, 'respondent');
    const allOk = csOk && rsOk;
    btn.disabled = !allOk;
    btn.style.opacity = allOk ? '1' : '0.5';
    btn.style.cursor  = allOk ? 'pointer' : 'not-allowed';
}

function _pstmtCardValid(prefix, party) {
    const sc = party === 'complainant' ? 'cs' : 'rs';
    const card = document.getElementById(prefix + '-' + sc + '-card');
    if (!card) return false;
    const hasText = (document.getElementById(prefix + '-' + sc + '-text')?.value.trim() || '').length > 0;
    const hasNewAudio = !!window.__stmtBlobs[prefix + '-' + party];
    const hasSavedAudio = card.dataset.serverAudio === '1';
    return hasText || hasNewAudio || hasSavedAudio;
}
async function uploadAllPendingStatements(prefix, complaintId, hearingId) {
    const results = {};
    for (const party of ['complainant', 'respondent']) {
        const key = prefix + '-' + party;
        const data = window.__stmtBlobs[key];
        if (!data) continue;
        try {
            const fd = new FormData();
            fd.append('action', 'upload_party_statement_audio');
            fd.append('complaint_id', complaintId);
            fd.append('hearing_id', hearingId || '');
            fd.append('party_type', party);
            const ext = (data.blob.type.includes('ogg')) ? 'ogg' : (data.blob.type.includes('wav')) ? 'wav' : (data.blob.type.includes('mp4')) ? 'm4a' : 'webm';
            fd.append('audio', data.blob, party + '-statement.' + ext);
            const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
            const d = await r.json();
            results[party] = d;
            if (d.success) { URL.revokeObjectURL(data.url); delete window.__stmtBlobs[key]; }
        } catch (e) { results[party] = { success: false, message: 'Network error.' }; }
    }
    return results;
}
function _pstmtReleaseAll(prefix) {
    ['complainant', 'respondent'].forEach(party => {
        const key = prefix + '-' + party;
        if (window.__stmtPlayAudio[key]) { try { window.__stmtPlayAudio[key].pause(); } catch (e) {} delete window.__stmtPlayAudio[key]; }
        if (window.__stmtBlobs[key] && window.__stmtBlobs[key].url) URL.revokeObjectURL(window.__stmtBlobs[key].url);
        delete window.__stmtBlobs[key];
        _pstmtStopTimer(key);
        delete window.__recorders[key];
    });
}

// ============================================================
// CHAIR: Continue Conciliation — Two-phase wizard
//   Phase 1: Decide absences (Justified / Unjustified)
//   Phase 2: If all justified → schedule next hearing
// Footer is INLINE in each phase's HTML so it cannot be clipped.
// ============================================================
window.__concilWizard = {
    caseId: 0,
    state: null,
    absent: [],
    decisions: [],
    cachedSummary: '',
    lastJustifiedCount: 0,
};

async function openConciliationContinueModal(caseId) {
    if (!caseId) return;

    let state;
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_concil_resume_state&complaint_id=${caseId}`);
        state = await r.json();
    } catch (e) {
        Swal.fire('Error', 'Could not load state.', 'error');
        return;
    }
    if (!state || !state.success) {
        Swal.fire('Error', state?.message || 'Could not load.', 'error');
        return;
    }
    if (state.my_role !== 'chair') {
        Swal.fire('Not Allowed', 'Only the Pangkat Chairperson can continue the conciliation absence flow.', 'info');
        return;
    }
    if (!state.appearance_at) {
        return openChairAppearanceModal(caseId);
    }

    const absent = (state.attendance || []).filter(p => p.status === 'no_show');
    if (!absent.length) {
        Swal.fire('Info', 'No absent parties on record.', 'info');
        return;
    }

    window.__concilWizard = {
        caseId,
        state,
        absent,
        decisions: [],
        cachedSummary: '',
        lastJustifiedCount: 0,
    };

    absent.forEach(p => {
        if (p.justification_status) {
            window.__concilWizard.decisions.push({
                party_id: p.party_id,
                party_type: p.party_type,
                status: p.justification_status,
                reason: p.appearance_notes || '',
                appearance_notes: p.appearance_notes || '',
            });
        }
    });

    await Swal.fire({
        title: '',
        html: `<div class="wz-shell">${buildDecisionPhaseHtml(state, absent)}</div>`,
        width: 720,
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        showCancelButton: false,
        customClass: { popup: 'wz-popup' },
        didOpen: () => {
            mountWizardFooter('decision');
            restoreSavedDecisions(absent);
        },
    });
}

// ------------------------------------------------------------
// PHASE 1 — Decision HTML (footer is INSIDE this HTML)
// ------------------------------------------------------------
function buildDecisionPhaseHtml(state, absent) {
    const appearanceNice = new Date(state.appearance_at.replace(' ', 'T'))
        .toLocaleString('en-US', { dateStyle: 'full', timeStyle: 'short' });
    const explainNice = state.explain_deadline
        ? new Date(state.explain_deadline.replace(' ', 'T'))
            .toLocaleString('en-US', { dateStyle: 'full', timeStyle: 'short' })
        : '—';
    const caseRef = window.__hearing?.reference || state.reference_number || '';

    const cards = absent.map(p => {
        const roleLc = (p.party_type || '').toLowerCase();
        const roleLabel = roleLc.charAt(0).toUpperCase() + roleLc.slice(1);
        const saved = p.justification_status || null;
        const savedNotes = p.appearance_notes || '';
        const kpPdf = p.kp18_or_19_pdf_path || null;

        const kpBtn = kpPdf ? `
            <button type="button" class="wz-kp-link"
                onclick="event.preventDefault(); openKpFailureNoticePdf(${state.complaint_id}, ${state.hearing_id}, ${p.party_id});">
                <i class="fas fa-file-pdf"></i>
                KP Form ${roleLc === 'complainant' ? '#18' : '#19'} — Open PDF
            </button>` : '';

        const justifiedLegal = 'Absence accepted — conciliation rescheduled with strict warning.';
        const unjustifiedLegalComplainant = 'Per RA 7160: complaint will be DISMISSED; complainant barred from filing in court.';
        const unjustifiedLegalRespondent  = 'Per RA 7160: counterclaim (if any) will be DISMISSED; respondent barred from filing it in court. Case proceeds to Certification.';
        const unjustifiedLegal = (roleLc === 'complainant') ? unjustifiedLegalComplainant : unjustifiedLegalRespondent;

        return `
        <div class="wz-party-card" data-decision-pid="${p.party_id}" data-party-type="${roleLc}">
            <div class="wz-party-head">
                <div class="wz-party-avatar ${roleLc}">
                    <i class="fas ${roleLc === 'complainant' ? 'fa-user' : 'fa-user-friends'}"></i>
                </div>
                <div class="wz-party-meta">
                    <div class="wz-party-name">${escapeHtml(p.full_name || '—')}</div>
                    <div class="wz-party-role ${roleLc}">${roleLabel}</div>
                </div>
                ${kpBtn}
            </div>

            <div class="wz-field">
                <label>Reason / what they said <span class="wz-opt">(optional)</span></label>
                <textarea rows="2" data-role="appearance_notes"
                    placeholder="e.g. Sick, hospitalized / no reason given / etc.">${escapeHtml(savedNotes)}</textarea>
            </div>

            <div class="wz-decision-label">RA 7160 — Decision</div>
            <div class="wz-radio-row">
                <label class="wz-radio justified ${saved === 'justified' ? 'selected' : ''}"
                       onclick="wzPickDecision(${p.party_id}, 'justified', this)">
                    <input type="radio" name="dec-${p.party_id}" value="justified" ${saved === 'justified' ? 'checked' : ''}>
                    <div class="wz-radio-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="wz-radio-text">
                        <div class="wz-radio-title">Justified</div>
                        <div class="wz-radio-sub">Reschedule + strict warning.</div>
                        <div class="wz-radio-legal ok"><i class="fas fa-check-circle"></i> ${justifiedLegal}</div>
                    </div>
                </label>
                <label class="wz-radio unjustified ${saved === 'unjustified' ? 'selected' : ''}"
                       onclick="wzPickDecision(${p.party_id}, 'unjustified', this)">
                    <input type="radio" name="dec-${p.party_id}" value="unjustified" ${saved === 'unjustified' ? 'checked' : ''}>
                    <div class="wz-radio-icon"><i class="fas fa-times-circle"></i></div>
                    <div class="wz-radio-text">
                        <div class="wz-radio-title">Unjustified</div>
                        <div class="wz-radio-sub">RA 7160 penalties apply.</div>
                        <div class="wz-radio-legal bad ${roleLc === 'respondent' ? 'warn' : ''}">
                            <i class="fas fa-gavel"></i> ${unjustifiedLegal}
                        </div>
                    </div>
                </label>
            </div>
        </div>`;
    }).join('');

    return `
        <div class="wz-header red">
            <div class="wz-header-left">
                <i class="fas fa-gavel"></i>
                <div>
                    <div class="wz-header-title">Continue Conciliation — Decide on Absences</div>
                    <div class="wz-header-sub">Ref: ${escapeHtml(caseRef)}</div>
                </div>
            </div>
            <div class="wz-header-step">
                <span class="wz-step-pill active">Step 1 of 2 — Decision</span>
            </div>
        </div>

        <div class="wz-body">
            <div class="wz-info-banner">
                <i class="fas fa-user-clock"></i>
                <div>
                    <div><b>Appearance was set for:</b> ${escapeHtml(appearanceNice)}</div>
                    <div class="wz-info-sub">Explain window ends: <b>${escapeHtml(explainNice)}</b></div>
                </div>
            </div>

            <div class="wz-legal-note">
                <div class="wz-legal-title"><i class="fas fa-gavel"></i> RA 7160 — Consequences of Non-Appearance</div>
                <ul>
                    <li><b>Complainant unjustified</b> → Complaint DISMISSED and barred from court (KP Form #23 auto-issued).</li>
                    <li><b>Respondent unjustified</b> → Counterclaim barred; case forwarded to Certification (KP Form #24 auto-issued).</li>
                    <li><b>All justified</b> → Conciliation rescheduled with strict warning.</li>
                </ul>
            </div>

            <div id="concilDecisionCards">${cards}</div>
        </div>

        <div class="wz-footer" id="wzFooter">
            <button type="button" class="wz-btn close" onclick="wzCancel()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <div class="wz-footer-right">
                <button type="button" class="wz-btn primary danger" id="wzBtnDecision">
                    <i class="fas fa-gavel"></i> Confirm Decision
                </button>
            </div>
        </div>
    `;
}

// ------------------------------------------------------------
// PHASE 2 — Schedule HTML (footer is INSIDE this HTML)
// ------------------------------------------------------------
function buildSchedulePhaseHtml() {
    const caseRef = window.__hearing?.reference || window.__concilWizard.state?.reference_number || '';
    const summary = window.__concilWizard.cachedSummary || '';
    const count = window.__concilWizard.lastJustifiedCount;

    return `
        <div class="wz-header teal">
            <div class="wz-header-left">
                <i class="fas fa-calendar-plus"></i>
                <div>
                    <div class="wz-header-title">Schedule Next Conciliation Hearing</div>
                    <div class="wz-header-sub">Ref: ${escapeHtml(caseRef)}</div>
                </div>
            </div>
            <div class="wz-header-step">
                <span class="wz-step-pill done"><i class="fas fa-check"></i> Decision saved</span>
                <span class="wz-step-pill active">Step 2 of 2 — Schedule</span>
            </div>
        </div>

        <div class="wz-body">
            <div class="wz-info-banner ok">
                <i class="fas fa-check-circle"></i>
                <div>
                    <div><b>Decision recorded: Justified</b></div>
                    <div class="wz-info-sub">
                        ${count} absence${count > 1 ? 's' : ''} recorded as justified. Per RA 7160, the absent part${count > 1 ? 'ies were' : 'y was'} rescheduled with a strict warning.
                    </div>
                </div>
            </div>

            <div class="wz-info-banner warn">
                <i class="fas fa-hourglass-half"></i>
                <div>
                    <div><b>A new hearing date is required.</b></div>
                    <div class="wz-info-sub">
                        The new date must fall within the conciliation window. If you close this modal without scheduling, the case stays on the conciliation track and you must pick a date later from the card.
                    </div>
                </div>
            </div>

            <div class="wz-field-row">
                <div class="wz-field">
                    <label>New Hearing Date <span class="wz-req">*</span></label>
                    <input type="date" id="wzJustDate" min="">
                </div>
                <div class="wz-field">
                    <label>Time <span class="wz-req">*</span></label>
                    <input type="time" id="wzJustTime" value="09:00">
                </div>
            </div>

            <div class="wz-hint"><i class="fas fa-info-circle"></i>
                Office hours: <b>8:00 AM – 6:30 PM</b> · min. 2 hours from now · no overlaps.
            </div>

            <div class="wz-conflict" id="wzJustConflict"><i class="fas fa-exclamation-triangle"></i><div></div></div>
            <div class="wz-available" id="wzJustAvailable"><i class="fas fa-check-circle"></i><span>Time slot is available.</span></div>

            <div class="wz-field" style="margin-top:16px;">
                <label>Remarks to absent party <span class="wz-opt">(optional — appears in audit trail)</span></label>
                <textarea id="wzJustRemarks" rows="2" placeholder="e.g. Stated reason: hospitalized. Warning issued in person.">${escapeHtml(summary)}</textarea>
            </div>
        </div>

        <div class="wz-footer" id="wzFooter">
            <button type="button" class="wz-btn close" onclick="wzLeaveWithoutSchedule()">
                <i class="fas fa-arrow-left"></i> Schedule Later
            </button>
            <div class="wz-footer-right">
                <button type="button" class="wz-btn primary teal" id="wzBtnSchedule">
                    <i class="fas fa-calendar-check"></i> Confirm &amp; Schedule
                </button>
            </div>
        </div>
    `;
}

// ------------------------------------------------------------
// Wizard footer — BIND ONLY (no HTML injection)
// ------------------------------------------------------------
function mountWizardFooter(phase) {
    // Hide Swal's native action buttons — we render our own footer inside the HTML
    const actions = Swal.getActions();
    if (actions) actions.style.display = 'none';

    if (phase === 'decision') {
        const btn = document.getElementById('wzBtnDecision');
        if (btn) btn.onclick = wzSubmitDecision;
    } else if (phase === 'schedule') {
        const btn = document.getElementById('wzBtnSchedule');
        if (btn) btn.onclick = wzSubmitSchedule;
    }
}

// ------------------------------------------------------------
// Phase 1 — decision helpers
// ------------------------------------------------------------
function wzPickDecision(pid, status, el) {
    const card = el.closest('.wz-party-card');
    if (!card) return;
    card.querySelectorAll('.wz-radio').forEach(r => r.classList.remove('selected', 'justified', 'unjustified'));
    el.classList.add('selected', status);
    const radio = el.querySelector('input[type=radio]');
    if (radio) radio.checked = true;

    const decisions = window.__concilWizard.decisions;
    const existing = decisions.find(d => d.party_id === pid);
    if (existing) existing.status = status;
    else decisions.push({ party_id: pid, party_type: card.dataset.partyType || '', status, reason: '', appearance_notes: '' });
}

function restoreSavedDecisions(absent) {
    absent.forEach(p => {
        if (!p.justification_status) return;
        const card = document.querySelector(`.wz-party-card[data-decision-pid="${p.party_id}"]`);
        if (!card) return;
        const target = card.querySelector(`.wz-radio.${p.justification_status}`);
        if (target) {
            card.querySelectorAll('.wz-radio').forEach(r => r.classList.remove('selected', 'justified', 'unjustified'));
            target.classList.add('selected', p.justification_status);
            const radio = target.querySelector('input[type=radio]');
            if (radio) radio.checked = true;
        }
    });
}

function wzCollectDecisions() {
    const host = document.getElementById('concilDecisionCards');
    if (!host) return { ok: false, decisions: [] };
    const cards = host.querySelectorAll('.wz-party-card');
    const out = [];
    let allDecided = true;
    cards.forEach(card => {
        const pid = Number(card.dataset.decisionPid);
        const ptype = card.dataset.partyType || '';
        const selected = card.querySelector('.wz-radio.selected');
        const ta = card.querySelector('textarea[data-role="appearance_notes"]');
        const notes = ta ? ta.value.trim() : '';
        if (!selected) { allDecided = false; return; }
        const status = selected.classList.contains('justified') ? 'justified' : 'unjustified';
        out.push({ party_id: pid, party_type: ptype, status, reason: notes, appearance_notes: notes });
    });
    return { ok: allDecided, decisions: out };
}

// ------------------------------------------------------------
// Phase 1 — submit
// ------------------------------------------------------------
async function wzSubmitDecision() {
    const btn = document.getElementById('wzBtnDecision');
    const collected = wzCollectDecisions();
    if (!collected.ok) {
        wzInlineError('Please decide for all absent parties.');
        return;
    }

    window.__concilWizard.decisions = collected.decisions;

    const caseId = window.__concilWizard.caseId;
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…'; }

    const fd = new FormData();
    fd.append('action', 'chair_resolve_concil_absence');
    fd.append('complaint_id', caseId);
    fd.append('decisions', JSON.stringify(collected.decisions));

    let d;
    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        d = await r.json();
    } catch (e) {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-gavel"></i> Confirm Decision'; }
        wzInlineError('Network error while saving.');
        return;
    }

    if (!d.success) {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-gavel"></i> Confirm Decision'; }
        wzInlineError(d.message || 'Failed to save decision.');
        return;
    }

    if (d.outcome === 'reschedule') {
        const justified = collected.decisions.filter(x => x.status === 'justified').length;
        window.__concilWizard.lastJustifiedCount = justified;
        window.__concilWizard.cachedSummary = collected.decisions.map(x => {
            const pname = (window.__concilWizard.absent.find(p => p.party_id === x.party_id) || {}).full_name || '';
            return `${pname} (${x.party_type}) — justified${x.appearance_notes ? ': ' + x.appearance_notes : ''}`;
        }).join('; ');

        await wzSwapToSchedulePhase();
        return;
    }

    if (d.outcome === 'dismissed_complaint') {
        Swal.close();
        await Swal.fire({
            icon: 'error',
            title: 'Complaint Dismissed',
            html: `<div style="text-align:left;font-size:0.9rem;">
                <p>Complaint was <b>DISMISSED</b>. The complainant did not appear without justifiable cause and is barred from filing in court.</p>
                ${d.cert_info ? `
                    <div style="margin-top:12px;padding:12px 14px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.83rem;color:#b71c1c;">
                        <b><i class="fas fa-gavel"></i> ${escapeHtml(d.cert_info.label || 'KP Form #23 — Certification to Bar Action')}</b><br>
                        Cert No: <b>${escapeHtml(d.cert_info.cert_no || '')}</b>
                        ${d.cert_info.pdf_url ? `<br><a href="#" onclick="event.preventDefault(); window.open('../../${escapeHtml(d.cert_info.pdf_url)}','_blank');"
                            style="display:inline-block;margin-top:6px;padding:5px 12px;background:#c62828;color:#fff;border-radius:6px;text-decoration:none;font-size:0.78rem;font-weight:700;">
                            <i class="fas fa-file-pdf"></i> Open KP #23</a>` : ''}
                    </div>` : ''}
            </div>`,
            confirmButtonText: 'Done',
            confirmButtonColor: '#2E7D32',
            width: 620
        });
        if (window.__hearing.active) {
            window.__hearing.cachedCaseDetail = null;
            window.__hearing.lastSignature = null;
            const h = document.getElementById('hearingInlineHost');
            if (h) delete h.dataset.signature;
            refreshHearingView();
        } else {
            loadMyComplaints();
        }
        return;
    }

    if (d.outcome === 'respondent_no_show') {
        Swal.close();
        await Swal.fire({
            icon: 'warning',
            title: 'Case Forwarded to Certification',
            html: `<div style="text-align:left;font-size:0.9rem;">
                <p>The respondent did not appear without justifiable cause. Their counterclaim (if any) is <b>barred</b>. The case is now ready for a Certificate to File Action.</p>
                ${d.cert_info ? `
                    <div style="margin-top:12px;padding:12px 14px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.83rem;color:#b71c1c;">
                        <b><i class="fas fa-gavel"></i> ${escapeHtml(d.cert_info.label || 'KP Form #24 — Certification to Bar Counterclaim')}</b><br>
                        Cert No: <b>${escapeHtml(d.cert_info.cert_no || '')}</b>
                        ${d.cert_info.pdf_url ? `<br><a href="#" onclick="event.preventDefault(); window.open('../../${escapeHtml(d.cert_info.pdf_url)}','_blank');"
                            style="display:inline-block;margin-top:6px;padding:5px 12px;background:#c62828;color:#fff;border-radius:6px;text-decoration:none;font-size:0.78rem;font-weight:700;">
                            <i class="fas fa-file-pdf"></i> Open KP #24</a>` : ''}
                    </div>` : ''}
            </div>`,
            confirmButtonText: 'Done',
            confirmButtonColor: '#2E7D32',
            width: 620
        });
        if (window.__hearing.active) {
            window.__hearing.cachedCaseDetail = null;
            window.__hearing.lastSignature = null;
            const h = document.getElementById('hearingInlineHost');
            if (h) delete h.dataset.signature;
            refreshHearingView();
        } else {
            loadMyComplaints();
        }
        return;
    }
}

// ------------------------------------------------------------
// Phase 1 → Phase 2 swap (no close, just replace HTML)
// ------------------------------------------------------------
async function wzSwapToSchedulePhase() {
    const host = Swal.getHtmlContainer();
    if (!host) return;
    const shell = host.querySelector('.wz-shell');
    if (!shell) return;

    shell.classList.add('wz-fade-out');
    await new Promise(r => setTimeout(r, 180));
    shell.innerHTML = buildSchedulePhaseHtml();
    shell.classList.remove('wz-fade-out');
    shell.classList.add('wz-fade-in');
    setTimeout(() => shell.classList.remove('wz-fade-in'), 300);

    mountWizardFooter('schedule');
    initWzJustifiedScheduling();
}

// ------------------------------------------------------------
// Phase 2 — schedule validation + submit
// ------------------------------------------------------------
async function initWzJustifiedScheduling() {
    const dEl = document.getElementById('wzJustDate');
    const tEl = document.getElementById('wzJustTime');
    const warn = document.getElementById('wzJustConflict');
    const avail = document.getElementById('wzJustAvailable');
    if (!dEl || !tEl || !warn) return;

    const caseId = window.__concilWizard.caseId;
    const todayStr = new Date().toISOString().split('T')[0];

    let minDate = todayStr;
    let maxDate = null;
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_lupon_hearing_state&complaint_id=${caseId}`);
        const s = await r.json();
        if (s && s.success) {
            const firstIso = s.conciliation_first_hearing_date;
            const hardIso  = s.conciliation_hard_deadline;
            const extDays  = Number(s.conciliation_extension_days || 0);
            const extended = s.conciliation_extended === 1 || s.conciliation_extended === true;

            if (firstIso) minDate = String(firstIso).split(' ')[0];
            if (hardIso) {
                maxDate = String(hardIso).split(' ')[0];
            } else if (firstIso) {
                const totalDays = 15 + (extended ? extDays : 0);
                const dt = new Date(String(firstIso).split(' ')[0] + 'T00:00:00');
                dt.setDate(dt.getDate() + totalDays);
                maxDate = dt.toISOString().split('T')[0];
            }
        }
    } catch (e) { /* silent */ }

    dEl.min = minDate;
    if (maxDate) dEl.max = maxDate;

    const tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate() + 1);
    let prefill = tomorrow.toISOString().split('T')[0];
    if (prefill < minDate) prefill = minDate;
    if (maxDate && prefill > maxDate) prefill = maxDate;
    dEl.value = prefill;

    let debounce = null;
    const liveCheck = () => {
        clearTimeout(debounce);
        debounce = setTimeout(async () => {
            if (!dEl.value || !tEl.value) return;
            try {
                const r = await fetch(`../admin_complaint_ajax.php?action=check_scheduling_live&date=${dEl.value}&time=${tEl.value}`);
                const d = await r.json();
                if (!d.success) return;
                if (d.min_time) tEl.setAttribute('min', d.min_time);
                if (d.max_time) tEl.setAttribute('max', d.max_time);
                if (d.allowed) {
                    warn.classList.remove('show');
                    avail.classList.add('show');
                } else {
                    warn.querySelector('div').innerHTML = `<b>Not allowed:</b> ${escapeHtml(d.reason)}`;
                    warn.classList.add('show');
                    avail.classList.remove('show');
                }
            } catch (e) {}
        }, 300);
    };

    const onDateChange = async () => {
        try {
            const r = await fetch(`../admin_complaint_ajax.php?action=get_scheduling_limits&date=${dEl.value}`);
            const d = await r.json();
            if (!d.success) return;
            if (!d.allowed) {
                warn.querySelector('div').innerHTML = `<b>Not allowed:</b> ${escapeHtml(d.reason)}`;
                warn.classList.add('show');
                avail.classList.remove('show');
                tEl.value = '';
                return;
            }
            tEl.setAttribute('min', d.min_time);
            tEl.setAttribute('max', d.max_time);
            if (!tEl.value || tEl.value < d.min_time || tEl.value > d.max_time) tEl.value = d.min_time;
            liveCheck();
        } catch (e) {}
    };

    dEl.addEventListener('change', onDateChange);
    tEl.addEventListener('change', liveCheck);
    onDateChange();
}

async function wzSubmitSchedule() {
    const caseId = window.__concilWizard.caseId;
    const date = document.getElementById('wzJustDate')?.value || '';
    const time = document.getElementById('wzJustTime')?.value || '';
    const remarks = (document.getElementById('wzJustRemarks')?.value || '').trim();

    if (!date) { wzInlineError('Pick a date for the new hearing.'); return; }
    if (!time) { wzInlineError('Pick a time for the new hearing.'); return; }

    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=check_scheduling_live&date=${date}&time=${time}`);
        const d = await r.json();
        if (d.success && !d.allowed) { wzInlineError(d.reason); return; }
    } catch (e) { /* fall through */ }

    const btn = document.getElementById('wzBtnSchedule');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Scheduling…'; }

    const summary = window.__concilWizard.cachedSummary || 'Conciliation rescheduled after justified absence decision';
    const fd = new FormData();
    fd.append('action', 'lupon_reschedule_conciliation');
    fd.append('complaint_id', caseId);
    fd.append('hearing_date', date);
    fd.append('hearing_time', time);
    fd.append('hearing_location', 'Barangay Hall');
    fd.append('reason', 'Rescheduled after justified absence decision');
    fd.append('notes', remarks || summary);

    let d;
    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        d = await r.json();
    } catch (e) {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-calendar-check"></i> Confirm &amp; Schedule'; }
        wzInlineError('Network error while scheduling.');
        return;
    }

    if (!d.success) {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-calendar-check"></i> Confirm &amp; Schedule'; }
        wzInlineError(d.message || 'Failed to schedule.');
        return;
    }

    try {
        const fdLog = new FormData();
        fdLog.append('action', 'add_complaint_note');
        fdLog.append('complaint_id', caseId);
        fdLog.append('note', `[Justified Absence] Next conciliation hearing scheduled for ${date} ${time}. ${remarks || ''}`);
        await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fdLog });
    } catch (e) { /* silent */ }

    Swal.close();

    await Swal.fire({
        icon: 'success',
        title: 'Conciliation Rescheduled',
        html: `<div style="text-align:left;font-size:0.9rem;">
            <p><b>Next hearing set:</b></p>
            <div style="margin-top:12px;padding:14px 16px;background:#e0f7fa;border-left:4px solid #00838f;border-radius:10px;font-size:0.88rem;color:#006064;">
                <b>${escapeHtml(date)}</b> at <b>${escapeHtml(time)}</b><br>
                Barangay Hall
            </div>
            <p style="margin-top:12px;font-size:0.82rem;color:#666;">
                <b>KP Form #12</b> (Notice of Hearing) has been issued to both parties. All Pangkat members have been notified.
            </p>
        </div>`,
        confirmButtonText: 'Done',
        confirmButtonColor: '#2E7D32',
        width: 620,
        allowOutsideClick: false
    });

    if (window.__hearing.active) {
        window.__hearing.cachedCaseDetail = null;
        window.__hearing.lastSignature = null;
        const h = document.getElementById('hearingInlineHost');
        if (h) delete h.dataset.signature;
        refreshHearingView();
    } else {
        loadMyComplaints();
    }
}

// ------------------------------------------------------------
// Phase 2 — abandon schedule (keep decision, close)
// ------------------------------------------------------------
async function wzLeaveWithoutSchedule() {
    const r = await Swal.fire({
        icon: 'warning',
        title: 'Schedule later?',
        html: `<div style="text-align:left;font-size:0.9rem;">
            <p>The Justified decision is already <b>saved</b>. If you close now, you'll need to come back and set the next hearing date from the case card.</p>
            <div style="margin-top:12px;padding:10px 12px;background:#fff8e1;border-left:4px solid #f57c00;border-radius:8px;font-size:0.82rem;color:#e65100;">
                <b>Reminder:</b> The new hearing must still fall within the conciliation window.
            </div>
        </div>`,
        showCancelButton: true,
        confirmButtonText: 'Yes, schedule later',
        confirmButtonColor: '#e65100',
        cancelButtonText: 'Stay here',
        cancelButtonColor: '#2E7D32',
        allowOutsideClick: false
    });
    if (!r.isConfirmed) return;

    Swal.close();
    if (window.__hearing.active) {
        window.__hearing.cachedCaseDetail = null;
        window.__hearing.lastSignature = null;
        const h = document.getElementById('hearingInlineHost');
        if (h) delete h.dataset.signature;
        refreshHearingView();
    } else {
        loadMyComplaints();
    }
}

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------
function wzCancel() {
    Swal.fire({
        icon: 'question',
        title: 'Close without saving?',
        text: 'Any selection you made will be lost.',
        showCancelButton: true,
        confirmButtonText: 'Yes, close',
        confirmButtonColor: '#c62828',
        cancelButtonText: 'Stay',
        cancelButtonColor: '#6c757d'
    }).then(r => { if (r.isConfirmed) Swal.close(); });
}

function wzInlineError(msg) {
    let host = document.getElementById('wzInlineError');
    if (!host) {
        const swal = Swal.getHtmlContainer();
        if (!swal) { alert(msg); return; }
        host = document.createElement('div');
        host.id = 'wzInlineError';
        host.className = 'wz-inline-error';
        swal.insertBefore(host, swal.firstChild);
    }
    host.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${escapeHtml(msg)}`;
    host.style.display = 'flex';
    clearTimeout(window.__wzErrTimer);
    window.__wzErrTimer = setTimeout(() => { host.style.display = 'none'; }, 5000);
}

function pickConcilDecision(pid, status, el) { wzPickDecision(pid, status, el); }

// ------------------------------------------------------------
// RENDER — main entry for hearing page
// ------------------------------------------------------------
function renderHearingInline(state) {
    const host = document.getElementById('hearingInlineHost');
    if (!host) return;

    window.__hearing.lastState = state;

    const role = state.my_role || 'member';
    const isChair = role === 'chair';
    const isSecretary = role === 'secretary';
    const sessionType = state.hearing && state.hearing.session_type ? state.hearing.session_type : 'conciliation';
    const sessionLabel = sessionType === 'mediation' ? 'Mediation Hearing' : 'Conciliation Hearing';
    const sessionSubtitle = sessionType === 'mediation' ? 'Mediation Proceedings · Punong Barangay' : 'Conciliation Proceedings · Pangkat ng Tagapagkasundo';
    const headerClass = sessionType === 'mediation' ? 'mediation' : 'conciliation';
    const headerIcon = sessionType === 'mediation' ? 'fa-gavel' : 'fa-balance-scale';

    const hearingDate = state.hearing && state.hearing.hearing_date ? new Date(state.hearing.hearing_date).toLocaleDateString('en-US', { dateStyle: 'full' }) : '—';
    const hearingTime = state.hearing && state.hearing.hearing_time ? state.hearing.hearing_time : '—';
    const hearingLoc  = state.hearing && state.hearing.location ? state.hearing.location : 'Barangay Hall';

    // ── Arbitration state — Chair can only view once arbitration has started ──
    const arbStatus       = state.arbitration_status || null;
    const isArbPending    = arbStatus === 'arbitration_pending';
    const isArbActive     = arbStatus === 'arbitration_agreement_active';
    const isArbAward      = arbStatus === 'arbitration_award_issued';
    const isArbRepudiated = arbStatus === 'arbitration_repudiated';
    const isInArbitration = isArbPending || isArbActive || isArbAward || isArbRepudiated;

    const roleBanner = isChair
        ? `<div class="sec-notice info" style="padding:12px 16px;border-radius:8px;font-size:0.85rem;line-height:1.55;margin-bottom:16px;display:flex;gap:10px;align-items:flex-start;background:#e3f2fd;border-left:4px solid #1565c0;color:#0d47a1;"><i class="fas fa-user-tie" style="font-size:1.1rem;flex-shrink:0;margin-top:2px;"></i><div><b>You are the Chairperson.</b> Review the case, lead the discussion, and save the hearing record when ready.</div></div>`
        : (isSecretary
            ? `<div class="sec-notice success" style="padding:12px 16px;border-radius:8px;font-size:0.85rem;line-height:1.55;margin-bottom:16px;display:flex;gap:10px;align-items:flex-start;background:#e8f5e9;border-left:4px solid #2E7D32;color:#1b5e20;"><i class="fas fa-clipboard-list" style="font-size:1.1rem;flex-shrink:0;margin-top:2px;"></i><div><b>You are the Secretary.</b> Record attendance, statements, and upload evidence. Sections unlock as you complete each step.</div></div>`
            : `<div class="sec-notice warn" style="padding:12px 16px;border-radius:8px;font-size:0.85rem;line-height:1.55;margin-bottom:16px;display:flex;gap:10px;align-items:flex-start;background:#fff8e1;border-left:4px solid #f57c00;color:#e65100;"><i class="fas fa-users" style="font-size:1.1rem;flex-shrink:0;margin-top:2px;"></i><div><b>You are a Pangkat Member.</b> Review the case details below.</div></div>`);

    const settlementBanner = buildSettlementBanner(state);
    const concilWindowBanner = buildConciliationWindowBanner(state);

    let attendanceCtaHtml = '';
    if (isSecretary) {
        const att = state.attendance || [];
        const totalParties = att.length;
        const marked = att.filter(p => p.status === 'attend' || p.status === 'no_show').length;
        const fullyMarked = totalParties > 0 && marked === totalParties;
        if (fullyMarked) {
            attendanceCtaHtml = `<div class="hearing-attn-cta done">
                <div class="hac-left">
                    <div class="hac-icon"><i class="fas fa-check-circle"></i></div>
                    <div>
                        <div class="hac-title">Attendance Recorded</div>
                        <div class="hac-sub">All ${totalParties} parties have been marked. You may edit anytime.</div>
                    </div>
                </div>
                <button class="hac-btn" onclick="openSecretaryAttendanceModal()">
                    <i class="fas fa-edit"></i> Edit Attendance
                </button>
            </div>`;
        } else {
            attendanceCtaHtml = `<div class="hearing-attn-cta">
                <div class="hac-left">
                    <div class="hac-icon"><i class="fas fa-clipboard-list"></i></div>
                    <div>
                        <div class="hac-title">Record Attendance First</div>
                        <div class="hac-sub">Mark each party as <b>Attend</b> or <b>No Show</b>. Other sections unlock once completed.</div>
                    </div>
                </div>
                <button class="hac-btn" onclick="openSecretaryAttendanceModal()">
                    <i class="fas fa-clipboard-list"></i> Open Attendance
                </button>
            </div>`;
        }
    }

    const casePanel = buildCaseInfoSection();
    const partiesPanel = buildPartiesSection();
    const attendancePanel = buildAttendanceSection(state, role);
    const statementsPanel = buildStatementsSection(state, role);
    const evidencePanel = buildUploadedEvidenceSection(role);
    const kpFormsPanel = buildKpFormsSection();
    const priorHearingsPanel = buildPriorHearingsSection();

    /* ═════════════════════════════════════════════════════════════════
       ACTION BAR
       ─────────────────────────────────────────────────────────────────
       If the case has entered arbitration, the Chair/Secretary have
       nothing left to do — the PUNONG BARANGAY owns KP #15 and any
       repudiation decision. Show only view/refresh buttons.
       ═════════════════════════════════════════════════════════════════ */
    let actionBar = '';

    if (isInArbitration) {
        let arbColor = '#1565c0';
        let arbBg    = '#e3f2fd';
        let arbIcon  = 'fa-gavel';
        let arbTitle = 'Arbitration in progress';
        let arbNote  = 'The <b>Punong Barangay</b> handles the award (KP #15) and any repudiation decision.';

        if (isArbPending) {
            arbColor = '#e65100'; arbBg = '#fff8e1'; arbIcon = 'fa-hourglass-half';
            arbTitle = 'Arbitration — 5-Day Repudiation Window';
            arbNote  = state.arbitration_repudiation_deadline
                ? `Window ends <b>${new Date(state.arbitration_repudiation_deadline.replace(' ','T')).toLocaleDateString('en-US',{dateStyle:'long'})}</b>. Either party may still repudiate KP #14.`
                : 'Either party may still repudiate KP #14 within the 5-day window.';
        } else if (isArbActive) {
            arbColor = '#1565c0'; arbBg = '#e3f2fd'; arbIcon = 'fa-gavel';
            arbTitle = 'Arbitration Agreement Final — KP #15 Due';
            arbNote  = state.arbitration_award_deadline
                ? `The Punong Barangay must render KP Form #15 (Arbitration Award) by <b>${new Date(state.arbitration_award_deadline.replace(' ','T')).toLocaleDateString('en-US',{dateStyle:'long'})}</b>.`
                : 'The Punong Barangay must render KP Form #15 (Arbitration Award) within 15 days.';
        } else if (isArbAward) {
            arbColor = '#2E7D32'; arbBg = '#e8f5e9'; arbIcon = 'fa-check-double';
            arbTitle = 'Arbitration Award Issued (KP #15)';
            arbNote  = 'The award is final and binding. Both parties have been notified.';
        } else if (isArbRepudiated) {
            arbColor = '#c62828'; arbBg = '#ffebee'; arbIcon = 'fa-times-circle';
            arbTitle = 'KP #14 was REPUDIATED';
            arbNote  = `Repudiated by <b>${escapeHtml(String(state.arbitration_repudiated_by_party || 'party').toUpperCase())}</b>. The Punong Barangay will decide the next step (remediate or issue KP #20 CFA).`;
        }

        actionBar = `
            <button class="hearing-doc-action-btn back" onclick="closeHearingPage()">
                <i class="fas fa-arrow-left"></i> Back to Complaints
            </button>
            <button class="hearing-doc-action-btn info" onclick="refreshHearingView()">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
            <div style="margin-left:auto;padding:10px 18px;background:${arbBg};border-left:4px solid ${arbColor};border-radius:8px;font-size:0.85rem;color:${arbColor};display:inline-flex;align-items:flex-start;gap:10px;max-width:560px;">
                <i class="fas ${arbIcon}" style="font-size:1.1rem;flex-shrink:0;margin-top:2px;"></i>
                <div style="line-height:1.5;">
                    <div style="font-weight:800;text-transform:uppercase;font-size:0.72rem;letter-spacing:0.4px;margin-bottom:4px;">
                        ${arbTitle}
                    </div>
                    <div style="font-size:0.8rem;color:#333;">${arbNote}</div>
                    ${state.kp14_pdf_path ? `
                        <button type="button"
                                style="margin-top:8px;padding:6px 14px;font-size:0.72rem;font-weight:700;background:#1565c0;color:#fff;border:none;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"
                                onclick="window.open('../../${escapeHtml(state.kp14_pdf_path)}','_blank');">
                            <i class="fas fa-file-pdf"></i> View KP #14
                        </button>` : ''}
                    ${state.kp15_pdf_path ? `
                        <button type="button"
                                style="margin-top:8px;margin-left:6px;padding:6px 14px;font-size:0.72rem;font-weight:700;background:#2E7D32;color:#fff;border:none;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"
                                onclick="window.open('../../${escapeHtml(state.kp15_pdf_path)}','_blank');">
                            <i class="fas fa-file-pdf"></i> View KP #15
                        </button>` : ''}
                </div>
            </div>`;
    }
    else if (isChair) {
        const att = state.attendance || [];
        const totalParties = att.length;
        const marked = att.filter(p => p.status === 'attend' || p.status === 'no_show').length;
        const fullyMarked = totalParties > 0 && marked === totalParties;
        const noShowPending = !!state.concil_absence_waiting_since && !state.concil_absence_appearance_at;
        const decidePending = !!state.concil_absence_appearance_at;

        const stmtStatus = getStatementStatus();
        const statementsComplete = stmtStatus.complete;

        const canSave = fullyMarked && !noShowPending && !decidePending && statementsComplete;

        let extraBtn = '';
        if (noShowPending || decidePending) {
            extraBtn = `<button class="hearing-doc-action-btn decide"
                onclick="openConciliationContinueModal(${state.complaint_id})">
                <i class="fas fa-gavel"></i> Continue Conciliation
            </button>`;
        }

        let saveLabel = '<i class="fas fa-save"></i> Save Hearing Record';
        let saveTitle = '';
        if (!fullyMarked) {
            saveLabel = `<i class="fas fa-hourglass-half"></i> Waiting for Secretary (${marked}/${totalParties})`;
            saveTitle = 'The Secretary must first record attendance for all parties.';
        } else if (!statementsComplete) {
            saveLabel = `<i class="fas fa-microphone"></i> Waiting for Secretary's Statements`;
            saveTitle = 'The Secretary must record and save both parties\' statements (Section IV).';
        } else if (noShowPending) {
            saveLabel = `<i class="fas fa-user-clock"></i> Set Appearance Date First`;
            saveTitle = 'Absent parties must be given an appearance date.';
        } else if (decidePending) {
            saveLabel = `<i class="fas fa-gavel"></i> Decide on Absences First`;
            saveTitle = 'You must decide Justified / Unjustified for absent parties.';
        }

        actionBar = `
            <button class="hearing-doc-action-btn back" onclick="closeHearingPage()"><i class="fas fa-arrow-left"></i> Back to Complaints</button>
            <button class="hearing-doc-action-btn info" onclick="refreshHearingView()" title="Refresh"><i class="fas fa-sync-alt"></i> Refresh</button>
            ${extraBtn}
            <button class="hearing-doc-action-btn danger" ${canSave ? '' : 'disabled'} title="${escapeHtml(saveTitle)}" onclick="${canSave ? 'openSaveHearingModal()' : 'hearingSaveDisabledNotice()'}">
                ${saveLabel}
            </button>`;
    }
    else {
        actionBar = `<button class="hearing-doc-action-btn back" onclick="closeHearingPage()"><i class="fas fa-arrow-left"></i> Back to Complaints</button>`;
    }

    const docsCount = (window.__hearing.cachedCaseDetail && window.__hearing.cachedCaseDetail.pangkat_documents || []).length;
    const stmtSig = getStatementStatus();
    const signature = `${role}|${state.complaint_id}`
        + `|${(state.hearing && state.hearing.session_number) || 1}`
        + `|${(state.attendance||[]).map(p=>p.party_id+':'+p.status).join(',')}`
        + `|${state.concil_absence_waiting_since||''}`
        + `|${state.concil_absence_appearance_at||''}`
        + `|${window.__hearing.cachedKpForms.length}`
        + `|${docsCount}`
        + `|S:${stmtSig.cs ? 1 : 0}${stmtSig.rs ? 1 : 0}`
        + `|ARB:${arbStatus || ''}`
        + `|K14:${state.kp14_pdf_path ? 1 : 0}`
        + `|K15:${state.kp15_pdf_path ? 1 : 0}`;

    if (host.dataset.signature === signature && host.querySelector('.hearing-doc')) return;

    host.innerHTML = `
        <div class="hearing-doc">
            <div class="hearing-doc-header ${headerClass}">
                <div class="hearing-doc-header-left">
                    <div class="hearing-doc-seal"><i class="fas ${headerIcon}"></i></div>
                    <div>
                        <div class="hearing-doc-title">${escapeHtml(sessionLabel)}</div>
                        <div class="hearing-doc-subtitle">${escapeHtml(sessionSubtitle)} · Hearing #${escapeHtml(String((state.hearing && state.hearing.session_number) || 1))}</div>
                    </div>
                </div>
                <div class="hearing-doc-ref">
                    <div class="hearing-doc-ref-label">Reference No.</div>
                    <div class="hearing-doc-ref-value">${escapeHtml(state.reference || '—')}</div>
                </div>
            </div>
            <div class="hearing-doc-body">
                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
                    <span class="hearing-doc-chip info"><i class="fas fa-hashtag"></i> Hearing #${escapeHtml(String((state.hearing && state.hearing.session_number) || 1))}</span>
                    <span class="hearing-doc-chip info"><i class="fas fa-calendar"></i> ${escapeHtml(hearingDate)}</span>
                    <span class="hearing-doc-chip info"><i class="fas fa-clock"></i> ${escapeHtml(hearingTime)}</span>
                    <span class="hearing-doc-chip info"><i class="fas fa-map-marker-alt"></i> ${escapeHtml(hearingLoc)}</span>
                    <span class="hearing-doc-chip safe"><i class="fas fa-circle" style="font-size:0.5rem;"></i> LIVE</span>
                    ${isInArbitration ? `<span class="hearing-doc-chip warning"><i class="fas fa-gavel"></i> ARBITRATION</span>` : ''}
                </div>
                ${settlementBanner}
                ${concilWindowBanner}
                ${attendanceCtaHtml}
                ${roleBanner}
                ${casePanel}
                ${partiesPanel}
                ${attendancePanel}
                ${statementsPanel}
                ${evidencePanel}
                ${kpFormsPanel}
                ${priorHearingsPanel}
            </div>
            <div class="hearing-doc-action-bar">${actionBar}</div>
        </div>`;
    host.dataset.signature = signature;
}

async function openSaveHearingModal() {
    const caseId = window.__hearing.complaintId;
    if (!caseId) return;

    let referenceNumber = window.__hearing.reference || '—';
    if (window.__hearing.cachedCaseDetail && window.__hearing.cachedCaseDetail.success) {
        referenceNumber = window.__hearing.cachedCaseDetail.complaint.reference_number || referenceNumber;
    }

    await Swal.fire({
        title: '',
        html: `
        <div class="shm-form">
            <div class="shm-header">
                <div class="shm-header-left">
                    <i class="fas fa-gavel"></i>
                    <div>
                        <div class="shm-header-title">Save Conciliation Hearing</div>
                        <div class="shm-header-sub">Pick the outcome of this hearing.</div>
                    </div>
                </div>
                <div class="shm-header-ref">
                    <div class="shm-header-ref-label">Reference</div>
                    <div class="shm-header-ref-value">${escapeHtml(referenceNumber)}</div>
                </div>
            </div>

            <div class="shm-body">

                <div class="shm-info">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <b>Ensure before saving:</b><br>
                        ✓ Secretary recorded attendance for all parties<br>
                        ✓ Secretary saved both party statements (Section IV)
                    </div>
                </div>

                <div class="shm-section-title">
                    <span class="shm-step">1</span> Select Outcome
                </div>

                <div class="shm-option-group" id="shmOutcomeGroup">
                    <div class="shm-option" data-value="settled" onclick="shmPickOutcome('settled')">
                        <div class="shm-option-icon success"><i class="fas fa-handshake"></i></div>
                        <div class="shm-option-text">
                            <div class="shm-option-title">✅ Settled</div>
                            <div class="shm-option-desc">
                                Both parties reached an amicable agreement. You will then pick whether
                                the parties <b>write their own terms (KP #16)</b> or <b>hand the decision to you (KP #14 Arbitration)</b>.
                            </div>
                        </div>
                        <div class="shm-option-check"><i class="fas fa-check"></i></div>
                    </div>

                    <div class="shm-option" data-value="unresolved" onclick="shmPickOutcome('unresolved')">
                        <div class="shm-option-icon danger"><i class="fas fa-times-circle"></i></div>
                        <div class="shm-option-text">
                            <div class="shm-option-title">❌ No Settlement</div>
                            <div class="shm-option-desc">
                                Conciliation failed. You will then choose: <b>Unresolved</b> (end conciliation) or <b>Reschedule</b> (set a new hearing).
                            </div>
                        </div>
                        <div class="shm-option-check"><i class="fas fa-check"></i></div>
                    </div>
                </div>

                <!-- ─────────────────────────────────────────────
                     SETTLED → pick how the parties settled
                     ───────────────────────────────────────────── -->
                <div class="shm-branch-wrap" id="shmSettleBranchWrap" style="display:none;">

                    <div class="shm-section-title">
                        <span class="shm-step">2</span> How will the parties resolve this?
                    </div>

                    <div class="shm-option-group" id="shmSettleBranchGroup">
                        <div class="shm-option" data-value="settlement" onclick="shmPickSettleBranch('settlement')">
                            <div class="shm-option-icon success"><i class="fas fa-file-signature"></i></div>
                            <div class="shm-option-text">
                                <div class="shm-option-title">Amicable Settlement — KP Form #16</div>
                                <div class="shm-option-desc">
                                    The parties <b>agree on their own terms</b>. The <b>Pangkat Secretary</b> writes
                                    KP Form #16; both parties sign it.<br><br>
                                    <b>Repudiation window:</b> 10 days from signing (fraud / violence / intimidation).
                                </div>
                            </div>
                            <div class="shm-option-check"><i class="fas fa-check"></i></div>
                        </div>

                        <div class="shm-option" data-value="arbitration" onclick="shmPickSettleBranch('arbitration')">
                            <div class="shm-option-icon info"><i class="fas fa-gavel"></i></div>
                            <div class="shm-option-text">
                                <div class="shm-option-title">Arbitration — KP Form #14 → KP Form #15</div>
                                <div class="shm-option-desc">
                                    The parties <b>hand decision power to the Punong Barangay</b>. You (Chairperson)
                                    generate <b>KP Form #14</b> now. The <b>Punong Barangay</b> renders <b>KP Form #15</b>
                                    within 15 days.<br><br>
                                    <b>Repudiation window:</b> 5 days from signing KP Form #14.<br>
                                    <b>Repudiation decision:</b> handled by the <b>Captain</b>, not the Pangkat.
                                </div>
                            </div>
                            <div class="shm-option-check"><i class="fas fa-check"></i></div>
                        </div>
                    </div>

                    <!-- Only shown when "arbitration" is picked -->
                    <div class="shm-arbitration-wrap" id="shmArbitrationWrap" style="display:none;">
                        <div class="shm-field" style="margin-top:14px;margin-bottom:0;">
                            <label>Date Parties Signed KP Form #14 <span class="req">*</span></label>
                            <input type="date" id="shmArbSignedDate"
                                   max="${new Date().toISOString().split('T')[0]}"
                                   value="${new Date().toISOString().split('T')[0]}">
                            <div class="shm-terms-hint" style="margin-top:6px;">
                                <i class="fas fa-info-circle"></i>
                                <span>The <b>5-day repudiation window</b> and <b>15-day award deadline</b> are counted from this date. The Captain handles the award and any repudiation.</span>
                            </div>
                        </div>
                    </div>

                    <!-- Only shown when "settlement" is picked -->
                    <div class="shm-repudiation-warning" id="shmSettleNoteWrap" style="display:none;">
                        <b><i class="fas fa-user-tie"></i> Next step: the Pangkat Secretary writes the terms</b><br>
                        Once you confirm <b>Amicable Settlement</b>, the Pangkat Secretary will be prompted to
                        write the exact terms agreed by both parties and generate <b>KP Form #16</b>.
                        The <b>10-day repudiation window</b> begins when the Secretary saves the terms.
                    </div>
                </div>

                <!-- ─────────────────────────────────────────────
                     NO SETTLEMENT → existing Unresolved / Reschedule flow
                     ───────────────────────────────────────────── -->
                <div class="shm-nosettle-wrap" id="shmNoSettleWrap">
                    <div class="shm-section-title" style="margin-top:0;">
                        <span class="shm-step" style="background:#c62828;">2</span> Why No Settlement Was Reached
                    </div>
                    <div class="shm-field" style="margin-bottom:18px;">
                        <label style="color:#b71c1c;font-weight:700;">
                            <i class="fas fa-comment-slash"></i> Reason <span class="req">*</span>
                        </label>
                        <textarea id="shmNoSettleReason" rows="3"
                            placeholder="e.g. Respondent refused the proposed terms; parties could not agree."></textarea>
                    </div>

                    <div class="shm-section-title">
                        <span class="shm-step" style="background:#c62828;">3</span> What's Next?
                    </div>
                    <div class="shm-option-group" id="shmNextStepGroup">
                        <div class="shm-option" data-value="unresolved" onclick="shmPickNextStep('unresolved')">
                            <div class="shm-option-icon danger"><i class="fas fa-times-circle"></i></div>
                            <div class="shm-option-text">
                                <div class="shm-option-title">🚫 Unresolved</div>
                                <div class="shm-option-desc">
                                    End conciliation. The case will be marked <b>Unresolved</b> and the Pangkat Secretary will prepare <b>KP Form #20-A</b> (Certification to File Action).
                                </div>
                            </div>
                            <div class="shm-option-check"><i class="fas fa-check"></i></div>
                        </div>

                        <div class="shm-option" data-value="reschedule" onclick="shmPickNextStep('reschedule')">
                            <div class="shm-option-icon" style="background:#e0f7fa;color:#00838f;"><i class="fas fa-calendar-alt"></i></div>
                            <div class="shm-option-text">
                                <div class="shm-option-title">🔄 Reschedule</div>
                                <div class="shm-option-desc">
                                    Set a new conciliation hearing date. The new date must fall within the conciliation window.
                                </div>
                            </div>
                            <div class="shm-option-check"><i class="fas fa-check"></i></div>
                        </div>
                    </div>

                    <div class="shm-reschedule-wrap" id="shmRescheduleWrap">
                        <div class="shm-section-title" style="margin-top:0;">
                            <span class="shm-step" style="background:#00838f;">4</span> Schedule New Hearing
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                            <div class="shm-field" style="margin-bottom:0;">
                                <label>New Date <span class="req">*</span></label>
                                <input type="date" id="shmRescheduleDate" min="">
                            </div>
                            <div class="shm-field" style="margin-bottom:0;">
                                <label>New Time <span class="req">*</span></label>
                                <input type="time" id="shmRescheduleTime" value="09:00">
                            </div>
                        </div>
                        <div class="shm-field" style="margin-top:14px;">
                            <label>Location</label>
                            <input type="text" id="shmRescheduleLocation" value="Barangay Hall">
                        </div>
                        <div class="shm-reschedule-notice" id="shmRescheduleNotice">
                            <i class="fas fa-info-circle"></i>
                            <span>New hearing must fall within the conciliation window.</span>
                        </div>
                    </div>
                </div>

                <div class="shm-section-title">
                    <span class="shm-step">•</span> Hearing Notes <span style="font-weight:400;font-size:0.75rem;color:#999;text-transform:none;">(optional)</span>
                </div>
                <div class="shm-field">
                    <textarea id="shmHearingNotes" rows="3" placeholder="Optional summary."></textarea>
                </div>
            </div>
        </div>`,

        width: 720,
        allowOutsideClick: false,
        allowEscapeKey: false,
        allowEnterKey: false,
        showConfirmButton: false,
        showCancelButton: false,
        customClass: { popup: 'shm-popup' },

        didOpen: () => {
            window.__shmOutcome  = null;
            window.__shmNextStep = null;
            window.__shmSettleBranch = null;
            window.__shmReschedulePossible = null;

            const actions = Swal.getActions();
            if (actions) actions.style.display = 'none';

            const footer = document.createElement('div');
            footer.className = 'shm-footer';
            footer.innerHTML = `
                <button type="button" class="shm-btn close" id="shmBtnCancel">
                    <i class="fas fa-arrow-left"></i> Cancel
                </button>
                <div class="shm-footer-right">
                    <button type="button" class="shm-btn primary" id="shmBtnSave" disabled>
                        <i class="fas fa-save"></i> Save Hearing Record
                    </button>
                </div>
            `;
            const html = Swal.getHtmlContainer();
            if (html && html.parentNode) html.parentNode.insertBefore(footer, html.nextSibling);

            document.getElementById('shmBtnCancel').onclick = () => Swal.close();
            document.getElementById('shmBtnSave').onclick = () => shmSubmitSave(caseId);

            window.__shmRefreshBtn = () => {
                const btn = document.getElementById('shmBtnSave');
                if (btn) btn.disabled = !shmIsReadyToSave();
            };

            ['shmNoSettleReason', 'shmRescheduleDate', 'shmRescheduleTime', 'shmRescheduleLocation', 'shmArbSignedDate']
                .forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.addEventListener('input',  () => window.__shmRefreshBtn());
                    if (el) el.addEventListener('change', () => window.__shmRefreshBtn());
                });
        }
    });
}


function shmPickSettleBranch(value) {
    const group = document.getElementById('shmSettleBranchGroup');
    if (!group) return;
    group.querySelectorAll('.shm-option').forEach(o => o.classList.remove('selected'));
    const sel = group.querySelector(`.shm-option[data-value="${value}"]`);
    if (sel) sel.classList.add('selected');

    const arbWrap   = document.getElementById('shmArbitrationWrap');
    const settleNote= document.getElementById('shmSettleNoteWrap');
    const btn       = document.getElementById('shmBtnSave');

    if (value === 'arbitration') {
        if (arbWrap)    arbWrap.style.display    = 'block';
        if (settleNote) settleNote.style.display = 'none';
        if (btn) btn.innerHTML = '<i class="fas fa-gavel"></i> Save & Issue KP Form #14';
    } else {
        if (arbWrap)    arbWrap.style.display    = 'none';
        if (settleNote) settleNote.style.display = 'block';
        if (btn) btn.innerHTML = '<i class="fas fa-handshake"></i> Confirm Settled';
    }

    window.__shmSettleBranch = value;
    if (typeof window.__shmRefreshBtn === 'function') window.__shmRefreshBtn();
}

function shmPickOutcome(value) {
    const group = document.getElementById('shmOutcomeGroup');
    if (!group) return;
    group.querySelectorAll('.shm-option').forEach(o => o.classList.remove('selected', 'fail'));
    const sel = group.querySelector(`.shm-option[data-value="${value}"]`);
    if (sel) {
        sel.classList.add('selected');
        if (value === 'unresolved') sel.classList.add('fail');
    }

    const branchWrap  = document.getElementById('shmSettleBranchWrap');
    const noSettleWrap= document.getElementById('shmNoSettleWrap');
    const btn         = document.getElementById('shmBtnSave');

    // Reset sub-selections when switching outcomes
    window.__shmSettleBranch = null;
    window.__shmNextStep     = null;

    const branchGroup = document.getElementById('shmSettleBranchGroup');
    if (branchGroup) branchGroup.querySelectorAll('.shm-option').forEach(o => o.classList.remove('selected'));
    const nextGroup = document.getElementById('shmNextStepGroup');
    if (nextGroup) nextGroup.querySelectorAll('.shm-option').forEach(o => o.classList.remove('selected'));
    const arbWrap = document.getElementById('shmArbitrationWrap');
    if (arbWrap) arbWrap.style.display = 'none';
    const noteWrap = document.getElementById('shmSettleNoteWrap');
    if (noteWrap) noteWrap.style.display = 'none';
    const reschedWrap = document.getElementById('shmRescheduleWrap');
    if (reschedWrap) reschedWrap.classList.remove('show');

    if (value === 'settled') {
        if (branchWrap)   branchWrap.style.display   = 'block';
        if (noSettleWrap) noSettleWrap.classList.remove('show');
        if (btn) btn.innerHTML = '<i class="fas fa-handshake"></i> Continue…';
    } else {
        if (branchWrap)   branchWrap.style.display   = 'none';
        if (noSettleWrap) noSettleWrap.classList.add('show');
        if (btn) btn.innerHTML = '<i class="fas fa-save"></i> Save Hearing Record';
    }
    window.__shmOutcome = value;
    if (typeof window.__shmRefreshBtn === 'function') window.__shmRefreshBtn();
}

function shmPickNextStep(value) {
    const group = document.getElementById('shmNextStepGroup');
    if (!group) return;
    group.querySelectorAll('.shm-option').forEach(o => o.classList.remove('selected', 'fail'));
    const sel = group.querySelector(`.shm-option[data-value="${value}"]`);
    if (sel) {
        sel.classList.add('selected');
        if (value === 'unresolved') sel.classList.add('fail');
    }

    const reschedWrap = document.getElementById('shmRescheduleWrap');
    const btn         = document.getElementById('shmBtnSave');

    if (value === 'reschedule') {
        if (reschedWrap) reschedWrap.classList.add('show');
        if (btn) btn.innerHTML = '<i class="fas fa-calendar-check"></i> Save & Reschedule Hearing';
        shmInitRescheduleDefaults();
    } else {
        if (reschedWrap) reschedWrap.classList.remove('show');
        if (btn) btn.innerHTML = '<i class="fas fa-file-certificate"></i> Save as Unresolved';
    }

    window.__shmNextStep = value;
    if (typeof window.__shmRefreshBtn === 'function') window.__shmRefreshBtn();
}

async function shmInitRescheduleDefaults() {
    const dateEl = document.getElementById('shmRescheduleDate');
    const timeEl = document.getElementById('shmRescheduleTime');
    const notice = document.getElementById('shmRescheduleNotice');
    if (!dateEl) return;

    const todayStr = new Date().toISOString().split('T')[0];

    dateEl.removeAttribute('min');
    dateEl.removeAttribute('max');
    dateEl.classList.remove('shm-input-invalid');

    let state = null;
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_lupon_hearing_state&complaint_id=${window.__hearing.complaintId}`);
        state = await r.json();
    } catch (e) { /* silent */ }

    if (!state || !state.success) {
        dateEl.min = todayStr;
        if (!dateEl.value) dateEl.value = todayStr;
        return;
    }

    const firstIso = state.conciliation_first_hearing_date || null;
    const hardIso  = state.conciliation_hard_deadline     || null;
    const extended = state.conciliation_extended === 1 || state.conciliation_extended === true;
    const extDays  = Number(state.conciliation_extension_days || 0);

    const minDate = firstIso ? String(firstIso).split(' ')[0] : todayStr;

    let maxDate = null;
    if (hardIso) {
        maxDate = String(hardIso).split(' ')[0];
    } else if (firstIso) {
        const totalDays = 15 + (extended ? extDays : 0);
        const dt = new Date(String(firstIso).split(' ')[0] + 'T00:00:00');
        dt.setDate(dt.getDate() + totalDays);
        maxDate = dt.toISOString().split('T')[0];
    }

    dateEl.min = minDate;
    if (maxDate) dateEl.max = maxDate;

    let seed = new Date(Math.max(
        new Date(todayStr + 'T00:00:00').getTime(),
        new Date(minDate + 'T00:00:00').getTime()
    ));
    const seedStr = seed.toISOString().split('T')[0];

    if (maxDate && seedStr > maxDate) {
        dateEl.value = '';
        dateEl.classList.add('shm-input-invalid');
        if (notice) {
            notice.classList.add('danger');
            notice.innerHTML = `<i class="fas fa-exclamation-triangle"></i>
                <span><b>Conciliation window has already ended</b> (max was
                <b>${new Date(maxDate + 'T23:59:59').toLocaleDateString('en-US',{dateStyle:'long'})}</b>).
                You can only mark this hearing as <b>Unresolved</b>.</span>`;
        }
        window.__shmReschedulePossible = false;
        if (typeof window.__shmRefreshBtn === 'function') window.__shmRefreshBtn();
        return;
    }

    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const tomorrowStr = tomorrow.toISOString().split('T')[0];

    let prefill = tomorrowStr;
    if (prefill < minDate) prefill = minDate;
    if (maxDate && prefill > maxDate) prefill = maxDate;

    dateEl.value = prefill;
    window.__shmReschedulePossible = true;

    if (notice) {
        notice.classList.remove('danger');
        const minNice = new Date(minDate + 'T00:00:00').toLocaleDateString('en-US',{dateStyle:'long'});
        const maxNice = maxDate
            ? new Date(maxDate + 'T00:00:00').toLocaleDateString('en-US',{dateStyle:'long'})
            : '—';

        const daysLabel = extended
            ? `Extended (30-day window, +${extDays} days)`
            : `Standard (15-day window)`;

        const daysLeft = maxDate
            ? Math.max(0, Math.ceil((new Date(maxDate + 'T23:59:59').getTime() - Date.now()) / 86400000))
            : null;

        notice.innerHTML = `<i class="fas fa-hourglass-half"></i>
            <div>
                <div><b>Allowed range:</b> ${escapeHtml(minNice)} → ${escapeHtml(maxNice)}</div>
                <div style="margin-top:4px;font-size:0.75rem;opacity:0.85;">
                    ${escapeHtml(daysLabel)}
                    ${daysLeft !== null ? ` · ${daysLeft} day${daysLeft===1?'':'s'} left` : ''}
                </div>
            </div>`;
    }
}

function shmIsReadyToSave() {
    const outcome = window.__shmOutcome;
    if (!outcome) return false;

    // ── Settled → must pick "settlement" or "arbitration" ──
    if (outcome === 'settled') {
        const branch = window.__shmSettleBranch;
        if (!branch) return false;

        if (branch === 'arbitration') {
            const signed = (document.getElementById('shmArbSignedDate')?.value || '').trim();
            if (!signed) return false;
            const ts = new Date(signed + 'T00:00:00').getTime();
            if (isNaN(ts) || ts > Date.now() + 86400000) return false;
        }
        return true;
    }

    // ── Unresolved → existing reason + next-step flow ──
    if (outcome === 'unresolved') {
        const reason = (document.getElementById('shmNoSettleReason')?.value || '').trim();
        if (reason.length < 5) return false;

        const next = window.__shmNextStep;
        if (!next) return false;

        if (next === 'reschedule') {
            if (window.__shmReschedulePossible === false) return false;

            const dateEl  = document.getElementById('shmRescheduleDate');
            const timeEl  = document.getElementById('shmRescheduleTime');
            const date    = (dateEl?.value || '').trim();
            const time    = (timeEl?.value || '').trim();
            if (!date || !time) return false;

            const minAttr = dateEl?.getAttribute('min');
            const maxAttr = dateEl?.getAttribute('max');

            if (minAttr && date < minAttr) return false;
            if (maxAttr && date > maxAttr) return false;
        }

        return true;
    }

    return false;
}
async function shmSubmitSave(caseId) {
    const outcome      = window.__shmOutcome;
    const nextStep     = window.__shmNextStep;
    const settleBranch = window.__shmSettleBranch;

    if (!outcome) { Swal.showValidationMessage('Please select an outcome.'); return; }

    const reason = (document.getElementById('shmNoSettleReason')?.value || '').trim();
    const notes  = (document.getElementById('shmHearingNotes')?.value || '').trim();

    /* ============================================================
       CASE 1 — SETTLED → Settlement (KP #16) OR Arbitration (KP #14)
       ============================================================ */
    if (outcome === 'settled') {
        if (!settleBranch) {
            Swal.showValidationMessage('Please pick Amicable Settlement or Arbitration.');
            return;
        }

        // ── Read the signed date BEFORE we close the original modal ──
        let signedDate = '';
        if (settleBranch === 'arbitration') {
            signedDate = (document.getElementById('shmArbSignedDate')?.value || '').trim();
            if (!signedDate) {
                Swal.showValidationMessage('Please enter the date parties signed KP Form #14.');
                return;
            }
            const ts = new Date(signedDate + 'T00:00:00').getTime();
            if (isNaN(ts) || ts > Date.now() + 86400000) {
                Swal.showValidationMessage('The signed date cannot be in the future.');
                return;
            }
        }

        const payloadNotes = notes || (
            settleBranch === 'arbitration'
                ? 'Parties agreed to submit the case to arbitration. Chairperson issued KP Form #14.'
                : 'Conciliation concluded with a settlement. Awaiting Pangkat Secretary\'s terms.'
        );

        Swal.fire({ title: 'Saving…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        const fd = new FormData();
        fd.append('action', 'lupon_record_conciliation_outcome');
        fd.append('complaint_id', caseId);
        fd.append('outcome_kind', settleBranch);
        fd.append('summary', payloadNotes);

        if (settleBranch === 'arbitration') {
            fd.append('signed_date', signedDate);
        } else {
            // Settlement: Secretary will write terms. Pass a placeholder.
            fd.append('reason', 'Settlement recorded by Chairperson — Pangkat Secretary to write final terms.');
        }
        let resp;
        try {
            const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
            const raw = await r.text();
            try {
                resp = JSON.parse(raw);
            } catch (parseErr) {
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Server returned non-JSON',
                    html: `<div style="text-align:left;font-size:0.82rem;font-family:monospace;max-height:300px;overflow:auto;background:#f8f8f8;padding:10px;border-radius:6px;">${escapeHtml(raw).substring(0, 1500)}</div>`,
                    width: 720
                });
                return;
            }
        } catch (e) {
            Swal.close();
            Swal.fire('Error', 'Network error.', 'error');
            return;
        }

        if (!resp.success) {
            Swal.close();
            Swal.fire({
                icon: 'error',
                title: 'Could not save',
                html: `<div style="text-align:left;font-size:0.9rem;">${escapeHtml(resp.message || 'Unknown error.')}</div>`,
                confirmButtonColor: '#c62828'
            });
            return;
        }

        Swal.close();
        Swal.close();

        if (settleBranch === 'arbitration') {
            // Confirm KP #14 issued
            const repDeadline = resp.repudiation_deadline
                ? new Date(resp.repudiation_deadline.replace(' ','T')).toLocaleDateString('en-US',{dateStyle:'long'})
                : '—';
            const awardDeadline = resp.award_deadline
                ? new Date(resp.award_deadline.replace(' ','T')).toLocaleDateString('en-US',{dateStyle:'long'})
                : '—';

            await Swal.fire({
                icon: 'success',
                title: 'KP Form #14 Issued — Arbitration',
                html: `<div style="text-align:left;font-size:0.9rem;">
                    <p>The Agreement for Arbitration (KP Form #14) has been recorded.</p>
                    <div style="margin-top:12px;padding:12px 14px;background:#fff8e1;border-left:4px solid #f57c00;border-radius:8px;font-size:0.82rem;color:#e65100;">
                        <b>5-Day Repudiation Window</b><br>
                        Ends: <b>${escapeHtml(repDeadline)}</b>
                    </div>
                    <div style="margin-top:12px;padding:12px 14px;background:#e3f2fd;border-left:4px solid #1565c0;border-radius:8px;font-size:0.82rem;color:#0d47a1;">
                        <b>KP Form #15 (Arbitration Award) Due</b><br>
                        By: <b>${escapeHtml(awardDeadline)}</b><br>
                        The <b>Punong Barangay (Captain)</b> handles the award and any repudiation.
                    </div>
                </div>`,
                confirmButtonColor: '#2E7D32',
                width: 620
            });
        } else {
            // Settlement → Secretary writes terms
            await Swal.fire({
                icon: 'success',
                title: 'Waiting for Secretary\'s Terms',
                html: `<div style="text-align:left;font-size:0.9rem;">
                    <p><b>Settled recorded.</b></p>
                    <div style="margin-top:12px;padding:12px 14px;background:#e8f5e9;border-left:4px solid #2E7D32;border-radius:8px;font-size:0.85rem;color:#1b5e20;">
                        <b><i class="fas fa-user-tie"></i> The Pangkat Secretary is now being prompted</b><br>
                        They will write the settlement terms and generate <b>KP Form #16 (Amicable Settlement)</b>.
                    </div>
                </div>`,
                confirmButtonColor: '#2E7D32',
                width: 640
            });
        }

        closeHearingPage();
        return;
    }

    /* ============================================================
       CASE 2 — UNRESOLVED → existing Reschedule / Unresolved flow
       ============================================================ */
    let reschedDate = null;
    let reschedTime = null;
    let reschedLocation = null;

    if (outcome === 'unresolved' && nextStep === 'reschedule') {
        if (window.__shmReschedulePossible === false) {
            Swal.showValidationMessage('The conciliation window has ended — you cannot reschedule. Pick Unresolved instead.');
            return;
        }
        const dateEl = document.getElementById('shmRescheduleDate');
        const timeEl = document.getElementById('shmRescheduleTime');
        const locEl  = document.getElementById('shmRescheduleLocation');

        reschedDate     = (dateEl?.value || '').trim();
        reschedTime     = (timeEl?.value || '').trim();
        reschedLocation = (locEl?.value  || 'Barangay Hall').trim();

        if (!reschedDate || !reschedTime) {
            Swal.showValidationMessage('Please set the new hearing date and time.');
            return;
        }
        const minAttr = dateEl ? dateEl.getAttribute('min') : null;
        const maxAttr = dateEl ? dateEl.getAttribute('max') : null;
        if (minAttr && reschedDate < minAttr) {
            Swal.showValidationMessage(`The new hearing must be on or after the first conciliation hearing (${minAttr}).`);
            return;
        }
        if (maxAttr && reschedDate > maxAttr) {
            Swal.showValidationMessage(`The new hearing must be on or before the conciliation window end (${maxAttr}).`);
            return;
        }
    }

    if (outcome === 'unresolved') {
        if (reason.length < 5) {
            Swal.showValidationMessage('Please describe why no settlement was reached.');
            return;
        }
        if (!nextStep) {
            Swal.showValidationMessage('Please pick what happens next: Unresolved or Reschedule.');
            return;
        }
    }

    const payloadNotes = reason + (notes ? '\n\n' + notes : '');

    Swal.fire({ title: 'Saving…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    const fd = new FormData();
    fd.append('action', 'chair_end_concil_hearing');
    fd.append('complaint_id', caseId);
    fd.append('outcome', outcome);
    fd.append('next_step', nextStep || '');
    fd.append('settlement_terms', '');
    fd.append('notes', payloadNotes);

    let saveRes;
    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        saveRes = await r.json();
    } catch (e) {
        Swal.close();
        Swal.fire('Error', 'Network error.', 'error');
        return;
    }

    if (!saveRes.success) {
        Swal.close();
        if (saveRes.code === 'statements_incomplete') {
            Swal.fire({
                icon: 'warning',
                title: 'Statements Not Yet Complete',
                text: saveRes.message,
                confirmButtonColor: '#e65100'
            }).then(() => refreshHearingView());
        } else {
            Swal.fire('Error', saveRes.message || 'Could not save hearing.', 'error');
        }
        return;
    }

    if (outcome === 'unresolved' && nextStep === 'reschedule') {
        Swal.fire({ title: 'Creating new hearing…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        const fd2 = new FormData();
        fd2.append('action', 'lupon_reschedule_conciliation');
        fd2.append('complaint_id', caseId);
        fd2.append('hearing_date', reschedDate);
        fd2.append('hearing_time', reschedTime);
        fd2.append('hearing_location', reschedLocation);
        fd2.append('reason', 'Rescheduled after No-Settlement decision');
        fd2.append('notes', reason);

        try {
            const r2 = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd2 });
            const d2 = await r2.json();
            Swal.close();

            if (!d2.success) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Hearing Saved, Reschedule Failed',
                    html: `<div style="text-align:left;font-size:0.9rem;">
                        <p>The hearing was marked as <b>No Settlement</b>, but the reschedule failed.</p>
                        <p style="margin-top:8px;color:#b71c1c;"><b>Reason:</b> ${escapeHtml(d2.message || 'Unknown error')}</p>
                        <p style="margin-top:10px;font-size:0.82rem;color:#666;">You may reschedule manually from the complaints list using the <b>Reschedule</b> button.</p>
                    </div>`,
                    confirmButtonColor: '#e65100'
                }).then(() => closeHearingPage());
                return;
            }

            Swal.fire({
                icon: 'success',
                title: 'Hearing Rescheduled',
                html: `<div style="text-align:left;font-size:0.9rem;">
                    <p>No settlement recorded for the current hearing.</p>
                    <div style="margin-top:12px;padding:12px 14px;background:#e0f7fa;border-left:4px solid #00838f;border-radius:8px;font-size:0.85rem;color:#006064;">
                        <b>New hearing:</b><br>
                        <b>${escapeHtml(reschedDate)}</b> at <b>${escapeHtml(reschedTime)}</b><br>
                        ${escapeHtml(reschedLocation)}
                    </div>
                    <p style="margin-top:12px;font-size:0.82rem;color:#666;">All Pangkat members have been notified.</p>
                </div>`,
                confirmButtonColor: '#2E7D32',
                width: 620
            }).then(() => closeHearingPage());
        } catch (e) {
            Swal.close();
            Swal.fire('Error', 'Network error while rescheduling.', 'error');
        }
        return;
    }

    Swal.close();
    let html = `<div style="text-align:left;font-size:0.9rem;">`;
    html += `<p><b>Hearing record saved.</b> Case marked as <b>Unresolved</b>.</p>
             <div style="margin-top:10px;padding:10px 12px;background:#fff8e1;border-left:4px solid #f57c00;border-radius:8px;font-size:0.82rem;color:#e65100;">
                The Pangkat Secretary will now prepare <b>KP Form #20-A</b> (Certification to File Action).
             </div>`;
    html += `</div>`;

    Swal.fire({
        icon: 'success',
        title: 'Hearing Record Saved',
        html,
        confirmButtonText: 'OK',
        confirmButtonColor: '#2E7D32',
        width: 640
    }).then(() => closeHearingPage());
}

function hearingSaveDisabledNotice() {
    const state = window.__hearing && window.__hearing.lastState;
    const att = (state && state.attendance) || [];
    const totalParties = att.length;
    const marked = att.filter(p => p.status === 'attend' || p.status === 'no_show').length;
    const fullyMarked = totalParties > 0 && marked === totalParties;
    const noShowPending = !!(state && state.concil_absence_waiting_since && !state.concil_absence_appearance_at);
    const decidePending = !!(state && state.concil_absence_appearance_at);

    const stmtStatus = getStatementStatus();

    const items = [];
    if (!fullyMarked) {
        items.push(`<li>The <b>Secretary</b> must record attendance for all parties (currently <b>${marked}/${totalParties}</b> marked).</li>`);
    }
    if (!stmtStatus.complete) {
        const missing = [];
        if (!stmtStatus.cs) missing.push('Complainant');
        if (!stmtStatus.rs) missing.push('Respondent');
        items.push(`<li>The <b>Secretary</b> must record and <b>save</b> both parties' statements (Section IV). Missing: <b>${missing.join(' and ')}</b>.</li>`);
    }
    if (noShowPending) {
        items.push(`<li>You have <b>absent parties</b>. Click <b>Set Appearance Date</b> to schedule when they must explain.</li>`);
    }
    if (decidePending) {
        items.push(`<li>You must <b>decide on the absences</b> (Justified / Unjustified) before saving.</li>`);
    }
    if (!items.length) {
        items.push(`<li>All conditions appear met. If the Save button stays disabled, please click <b>Refresh</b>.</li>`);
    }

    Swal.fire({
        icon: 'info',
        title: 'Cannot Save Yet',
        html: `<div style="text-align:left;font-size:0.9rem;">
            <p>The hearing record cannot be saved yet. Please complete the following:</p>
            <ul style="line-height:1.7;margin-top:8px;">${items.join('')}</ul>
            <div style="margin-top:12px;padding:10px 12px;background:#e3f2fd;border-left:4px solid #1565c0;border-radius:8px;font-size:0.82rem;color:#0d47a1;">
                <b>Who does what:</b>
                <ul style="margin-top:6px;padding-left:18px;line-height:1.7;">
                    <li><b>Secretary</b> → Records attendance &amp; party statements</li>
                    <li><b>Chairperson</b> → Sets appearance date, decides on absences, then saves</li>
                </ul>
            </div>
        </div>`,
        confirmButtonColor: '#2E7D32'
    });
}

async function openChairAppearanceModalFromPage() { const cid = window.__hearing.complaintId; if (!cid) return; return openChairAppearanceModal(cid, true); }
async function openChairAbsenceDecisionFromPage() { const cid = window.__hearing.complaintId; if (!cid) return; return openChairAbsenceDecisionModal(cid, true); }

// ============================================================
// SECRETARY — Settlement Terms Modal (auto-opened)
// ============================================================
async function openSettlementTermsModal() {
    const caseId = window.__hearing.complaintId;
    if (!caseId) { Swal.fire('Error', 'No active case.', 'error'); return; }

    let referenceNumber = window.__hearing.reference || '—';
    if (window.__hearing.cachedCaseDetail && window.__hearing.cachedCaseDetail.success) {
        referenceNumber = window.__hearing.cachedCaseDetail.complaint.reference_number || referenceNumber;
    }

    await Swal.fire({
        title: '',
        html: `
        <div class="shm-form">
            <div class="shm-header" style="background:linear-gradient(135deg,#1b5e20,#2E7D32 55%,#43a047);">
                <div class="shm-header-left">
                    <i class="fas fa-file-signature"></i>
                    <div>
                        <div class="shm-header-title">Write Settlement Terms</div>
                        <div class="shm-header-sub">These become the body of KP Form #16 (Amicable Settlement).</div>
                    </div>
                </div>
                <div class="shm-header-ref">
                    <div class="shm-header-ref-label">Reference</div>
                    <div class="shm-header-ref-value">${escapeHtml(referenceNumber)}</div>
                </div>
            </div>

            <div class="shm-body">
                <div class="shm-info">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        The <b>Chairperson</b> recorded this case as <b>Settled</b>. Write the exact terms both parties agreed on.
                        Once you save, <b>KP Form #16</b> is generated and the <b>10-day repudiation window</b> begins.
                    </div>
                </div>

                <div class="shm-section-title">
                    <span class="shm-step">1</span> Settlement Terms <span class="req" style="color:#c62828;">*</span>
                </div>

                <div class="shm-field" style="margin-bottom:0;">
                    <textarea id="settleTermsText" rows="12"
                        placeholder="Write the exact settlement terms agreed by both parties.

Example:
1. Respondent shall pay complainant ₱5,000.00 in two installments.
2. Respondent shall vacate the disputed premises by [date].
3. Both parties waive all other claims arising from this dispute."
                        style="min-height:240px;line-height:1.6;"></textarea>
                    <div class="shm-terms-hint">
                        <i class="fas fa-lightbulb"></i>
                        <span>Be specific — dates, amounts, obligations. This becomes the legal body of KP Form #16.</span>
                    </div>
                </div>

                <div class="shm-repudiation-warning" style="margin-top:18px;">
                    <b><i class="fas fa-hourglass-half"></i> What happens when you save</b><br>
                    ✓ <b>KP Form #16 (Amicable Settlement)</b> is auto-generated.<br>
                    ✓ The case enters a <b>10-day repudiation window</b> starting today.<br>
                    ✓ Either party may contest the settlement on grounds of <b>fraud, violence, or intimidation</b>.
                </div>
            </div>
        </div>`,

        width: 720,
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        showCancelButton: false,
        customClass: { popup: 'shm-popup' },

        didOpen: () => {
            const actions = Swal.getActions();
            if (actions) actions.style.display = 'none';

            const footer = document.createElement('div');
            footer.className = 'shm-footer';
            footer.innerHTML = `
                <button type="button" class="shm-btn close" id="settleBtnCancel">
                    <i class="fas fa-arrow-left"></i> Cancel
                </button>
                <div class="shm-footer-right">
                    <button type="button" class="shm-btn primary" id="settleBtnSave" disabled>
                        <i class="fas fa-file-signature"></i> Save Terms & Generate KP #16
                    </button>
                </div>
            `;
            const html = Swal.getHtmlContainer();
            if (html && html.parentNode) html.parentNode.insertBefore(footer, html.nextSibling);

            const ta  = document.getElementById('settleTermsText');
            const btn = document.getElementById('settleBtnSave');

            const refresh = () => { btn.disabled = (ta.value || '').trim().length < 10; };
            ta.addEventListener('input', refresh);
            refresh();

            document.getElementById('settleBtnCancel').onclick = () => {
                window.__hearing.termsModalOpen = false;
                Swal.close();
            };
            btn.onclick = () => settleSubmitTerms(caseId, ta.value.trim());
        }
    });
}

async function settleSubmitTerms(caseId, terms) {
    if (terms.length < 10) { Swal.showValidationMessage('Please write the full settlement terms.'); return; }

    Swal.fire({ title: 'Generating KP Form #16…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    const fd = new FormData();
    fd.append('action', 'lupon_save_settlement_terms');
    fd.append('complaint_id', caseId);
    fd.append('terms', terms);

    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        Swal.close();

        if (!d.success) { Swal.fire('Error', d.message || 'Failed.', 'error'); return; }

        const repudiationNice = d.repudiation_deadline
            ? new Date(d.repudiation_deadline.replace(' ','T')).toLocaleDateString('en-US',{dateStyle:'long'})
            : '';

        Swal.fire({
            icon: 'success',
            title: 'Terms Saved — KP #16 Generated',
            html: `<div style="text-align:left;font-size:0.9rem;">
                <p>The Amicable Settlement has been recorded.</p>
                ${repudiationNice ? `
                    <div style="margin-top:12px;padding:10px 14px;background:#e0f7fa;border-left:4px solid #00838f;border-radius:8px;font-size:0.82rem;color:#006064;">
                        <b>10-day repudiation window ends:</b> ${escapeHtml(repudiationNice)}
                    </div>` : ''}
                ${d.kp16 && d.kp16.pdf_path ? `
                    <div style="margin-top:12px;padding:12px 14px;background:#e8f5e9;border-left:4px solid #2E7D32;border-radius:8px;font-size:0.82rem;color:#1b5e20;">
                        <b><i class="fas fa-file-signature"></i> KP Form #16 (Amicable Settlement)</b> is ready.
                        <br><a href="#" onclick="event.preventDefault(); window.open('../../${escapeHtml(d.kp16.pdf_path)}','_blank');"
                            style="display:inline-block;margin-top:8px;padding:6px 14px;background:#2E7D32;color:#fff;border-radius:6px;text-decoration:none;font-size:0.78rem;font-weight:700;">
                            <i class="fas fa-file-pdf"></i> View KP #16
                        </a>
                        <div style="margin-top:8px;color:#b71c1c;font-weight:600;font-size:0.78rem;">
                            No digital signature — print and hand-deliver to both parties.
                        </div>
                    </div>` : ''}
            </div>`,
            confirmButtonText: 'OK',
            confirmButtonColor: '#2E7D32',
            width: 620
        }).then(() => {
            if (d.kp16 && d.kp16.pdf_path) {
                window.open('../../' + d.kp16.pdf_path, '_blank');
            }

            if (window.__hearing && window.__hearing.active) {
                window.__hearing.cachedCaseDetail = null;
                window.__hearing.lastSignature = null;
                window.__hearing.termsModalOpen = false;
                const h = document.getElementById('hearingInlineHost');
                if (h) delete h.dataset.signature;
                refreshHearingView();
            }
        });

    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}

// ============================================================
// CHAIR: Set Appearance Date (LOCKED modal — 5-day explain window)
// ------------------------------------------------------------
// Rules enforced:
//   • Opens automatically when a no-show is recorded (real-time).
//   • CANNOT be closed by Esc, backdrop click, or Cancel button.
//   • Only way out: submit a valid date + time (today+, 8 AM – 6 PM).
//   • On success: closes, shows confirmation, refreshes hearing view.
// ============================================================
async function openChairAppearanceModal(caseId, fromPage) {
    const tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate() + 1);
    const defDate = tomorrow.toISOString().split('T')[0];
    const todayStr = new Date().toISOString().split('T')[0];

    await Swal.fire({
        title: '',
        html: `<div style="text-align:left;">
            <div class="dm-header red" style="margin:-22px -22px 18px -22px;border-radius:8px 8px 0 0;">
                <div class="dm-header-left">
                    <i class="fas fa-user-clock"></i>
                    <div>
                        <div class="dm-header-title">Set Appearance Date</div>
                        <div class="dm-header-sub">Absent parties must explain in person</div>
                    </div>
                </div>
            </div>

            <div class="handbook-quote">
                <div class="handbook-quote-title"><i class="fas fa-gavel"></i> Per RA 7160</div>
                <p>You must set a date for the absent party to appear and explain.
                   <b>KP Form #18 (complainant)</b> or <b>#19 (respondent)</b> will be issued automatically.</p>
            </div>

            <div style="padding:10px 14px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.82rem;color:#b71c1c;line-height:1.6;margin-bottom:14px;">
                <b><i class="fas fa-hourglass-half"></i> 5-Day Explain Window</b><br>
                The absent party has <b>5 days</b> from this appearance date to explain. If they do not appear or fail to give a justified reason within 5 days, their absence is <b>automatically declared UNJUSTIFIED</b>:
                <ul style="margin-top:6px;padding-left:18px;line-height:1.7;">
                    <li><b>Complainant unjustified</b> → Complaint DISMISSED + barred from court (KP #23)</li>
                    <li><b>Respondent unjustified</b> → Counterclaim BARRED + case forwarded to Certification (KP #24)</li>
                </ul>
            </div>

            <div style="padding:10px 14px;background:#e3f2fd;border-left:4px solid #1565c0;border-radius:8px;font-size:0.82rem;color:#0d47a1;line-height:1.55;margin-bottom:14px;">
                <b><i class="fas fa-lock"></i> Required Action</b><br>
                This step must be completed before the hearing can proceed.
                The window cannot be dismissed until an appearance date is set.
            </div>

            <div class="dm-grid-2">
                <div class="dm-field">
                    <label>Date <span class="req">*</span></label>
                    <input type="date" id="chairAppearDate" min="${todayStr}" value="${defDate}">
                </div>
                <div class="dm-field">
                    <label>Time <span class="req">*</span></label>
                    <input type="time" id="chairAppearTime" value="09:00">
                </div>
            </div>

            <div id="chairAppearError" style="display:none;margin-top:12px;padding:10px 12px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.82rem;color:#b71c1c;line-height:1.5;"></div>
        </div>`,

        width: 620,

        // ── LOCKED: no way out except by submitting ──
        allowOutsideClick: false,
        allowEscapeKey: false,
        allowEnterKey: false,
        showCancelButton: false,
        showConfirmButton: false,

        customClass: { popup: 'chair-appearance-popup' },

        didOpen: () => {
            // Hide Swal's native action row — we render our own footer
            const actions = Swal.getActions();
            if (actions) actions.style.display = 'none';

            // ── Custom footer with a single red submit button ──
            const footer = document.createElement('div');
            footer.className = 'shm-footer';
            footer.innerHTML = `
                <div style="flex:1;font-size:0.78rem;color:#b71c1c;">
                    <i class="fas fa-lock"></i> Required — cannot be closed until a date is set.
                </div>
                <div class="shm-footer-right">
                    <button type="button" class="shm-btn primary"
                            id="chairAppearBtn"
                            style="background:#c62828;">
                        <i class="fas fa-paper-plane"></i> Set Date &amp; Issue KP #18/#19
                    </button>
                </div>
            `;
            const html = Swal.getHtmlContainer();
            if (html && html.parentNode) html.parentNode.insertBefore(footer, html.nextSibling);

            const dateEl = document.getElementById('chairAppearDate');
            const timeEl = document.getElementById('chairAppearTime');
            const btn    = document.getElementById('chairAppearBtn');

            const showErr = (msg) => {
                const box = document.getElementById('chairAppearError');
                if (!box) return;
                box.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${escapeHtml(msg)}`;
                box.style.display = 'flex';
            };
            const clearErr = () => {
                const box = document.getElementById('chairAppearError');
                if (box) box.style.display = 'none';
            };

            [dateEl, timeEl].forEach(el => {
                if (el) el.addEventListener('change', clearErr);
            });

            // ── Submit handler ──
            const submit = async () => {
                const d = (dateEl?.value || '').trim();
                const t = (timeEl?.value || '').trim();

                if (!d || !t) { showErr('Please set both date and time.'); return; }
                if (d < todayStr) { showErr('Date cannot be in the past.'); return; }

                const hh = Number((t.split(':')[0] || ''));
                if (isNaN(hh) || hh < 8 || hh >= 18) {
                    showErr('Time must be between 8:00 AM and 6:00 PM.');
                    return;
                }

                clearErr();
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Issuing notices…';

                const fd = new FormData();
                fd.append('action', 'chair_set_appearance_date');
                fd.append('complaint_id', caseId);
                fd.append('appearance_at', d + ' ' + t);

                let resp;
                try {
                    const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
                    resp = await r.json();
                } catch (e) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Set Date &amp; Issue KP #18/#19';
                    showErr('Network error. Please try again.');
                    return;
                }

                if (!resp || !resp.success) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Set Date &amp; Issue KP #18/#19';
                    showErr(resp?.message || 'Failed to set appearance date.');
                    return;
                }

                // ── SUCCESS → close this modal, then show confirmation ──
                Swal.close();

                const niceAppear = new Date(resp.appearance_at.replace(' ','T'))
                    .toLocaleString('en-US', { dateStyle:'full', timeStyle:'short' });
                const niceDeadline = resp.explain_deadline
                    ? new Date(resp.explain_deadline.replace(' ','T'))
                        .toLocaleString('en-US', { dateStyle:'full', timeStyle:'short' })
                    : '';

                await Swal.fire({
                    icon: 'success',
                    title: 'Appearance Date Set',
                    html: `<div style="text-align:left;font-size:0.9rem;">
                        <p>The absent part${(resp.notices || []).length > 1 ? 'ies' : 'y'} must appear on:</p>
                        <p style="font-weight:700;color:#c62828;font-size:1rem;">${escapeHtml(niceAppear)}</p>
                        ${niceDeadline ? `
                            <div style="margin-top:12px;padding:10px 12px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.82rem;color:#b71c1c;">
                                <b>5-Day Explain Deadline:</b> ${escapeHtml(niceDeadline)}<br>
                                If no explanation is received by then, the absence will be auto-declared <b>UNJUSTIFIED</b>.
                            </div>` : ''}
                    </div>`,
                    confirmButtonColor: '#2E7D32',
                    width: 620,
                    allowOutsideClick: false
                });

                // Chair's panel closes and returns to the complaints list.
                // (Chair's next step is deciding Justified/Unjustified from the card.)
                if (fromPage && window.__hearing.active) {
                    window.__hearing.cachedCaseDetail = null;
                    window.__hearing.lastSignature = null;
                    window.__hearing.__autoAppearanceKey = null;
                    closeHearingPage();
                } else {
                    loadMyComplaints();
                }
            };

            btn.onclick = submit;
            if (dateEl) dateEl.focus();
        }
    });

    // The modal drives its own lifecycle; the awaited promise resolves only
    // when Swal.close() is called from the success path above.
}

// ============================================================
// CHAIR: Decide Justified / Unjustified
// ============================================================
async function openChairAbsenceDecisionModal(caseId, fromPage) {
    let d;
    try { const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${caseId}`); d = await r.json(); }
    catch (e) { Swal.fire('Error', 'Could not load.', 'error'); return; }
    if (!d || !d.success) { Swal.fire('Error', d.message || 'Error', 'error'); return; }
    const c = d.complaint;
    const parties = [...(d.complainants || []), ...(d.respondents || [])];
    const hearings = (d.hearings || []).filter(h => h.session_type === 'conciliation');
    if (!hearings.length) { Swal.fire('Info', 'No conciliation hearing found.', 'info'); return; }
    hearings.sort((a, b) => (Number(b.id) || 0) - (Number(a.id) || 0));
    const latestHearing = hearings[0];
    let att = [];
    try { const r = await fetch(`../admin_complaint_ajax.php?action=get_hearing_details&hearing_id=${latestHearing.id}`); const hd = await r.json(); att = (hd.attendance || []).filter(a => a.status === 'no_show'); } catch (e) {}
    if (!att.length) { Swal.fire('Info', 'No absent parties on record.', 'info'); return; }

    const explainDeadlineIso = latestHearing.explain_window_deadline || c.concil_absence_explain_deadline || c.explain_deadline || null;

    const cards = att.map(a => {
        const party = parties.find(p => Number(p.id) === Number(a.party_id));
        const role = party ? party.party_type : 'respondent';
        const legalC = 'Complaint will be DISMISSED and barred from court (KP #23 issued by Pangkat Secretary).';
        const legalR = 'Counterclaim barred; case proceeds to Certification to File Action (KP #24 issued by Pangkat Secretary).';
        const legal = (role === 'complainant') ? legalC : legalR;
        return `<div class="abs-card" data-decision-pid="${a.party_id}" data-party-type="${role}" style="background:#fff;border:1.5px solid #ef9a9a;border-left:4px solid #c62828;border-radius:12px;padding:14px 16px;margin-bottom:12px;"><div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;"><div class="att-avatar ${role}" style="width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:${role === 'complainant' ? '#e8f5e9' : '#fff3e0'};color:${role === 'complainant' ? '#2E7D32' : '#ef6c00'};"><i class="fas ${role === 'complainant' ? 'fa-user' : 'fa-user-friends'}"></i></div><div><div style="font-weight:700;font-size:0.92rem;color:#1a2b22;">${escapeHtml(party?.full_name || '')}</div><div style="font-size:0.68rem;text-transform:uppercase;font-weight:700;color:${role === 'complainant' ? '#2E7D32' : '#ef6c00'};">${escapeHtml(role)}</div></div></div><div class="dm-field" style="margin-bottom:12px;"><label>Reason (optional)</label><textarea rows="2" data-role="appearance_notes" style="width:100%;padding:10px 12px;border:1.5px solid #cfd8dc;border-radius:8px;font-size:0.92rem;"></textarea></div><div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;"><label class="abs-radio justified" onclick="chairPickDecision(${a.party_id}, 'justified', this)"><input type="radio" name="dec-${a.party_id}" value="justified" style="display:none;"><div style="width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:#e8f5e9;color:#2E7D32;"><i class="fas fa-check-circle"></i></div><div><div style="font-weight:700;font-size:0.85rem;">Justified</div></div></label><label class="abs-radio unjustified" onclick="chairPickDecision(${a.party_id}, 'unjustified', this)"><input type="radio" name="dec-${a.party_id}" value="unjustified" style="display:none;"><div style="width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:#ffebee;color:#c62828;"><i class="fas fa-times-circle"></i></div><div><div style="font-weight:700;font-size:0.85rem;">Unjustified</div><div style="font-size:0.68rem;color:#b71c1c;margin-top:4px;">${escapeHtml(legal)}</div></div></label></div></div>`;
    }).join('');

    await Swal.fire({
        title: '',
        html: `<div style="text-align:left;">
            <div class="dm-header red" style="margin:-22px -22px 18px -22px;border-radius:8px 8px 0 0;">
                <div class="dm-header-left">
                    <i class="fas fa-gavel"></i>
                    <div>
                        <div class="dm-header-title">Decide on Absences</div>
                        <div class="dm-header-sub">Ref: ${escapeHtml(c.reference_number)}</div>
                    </div>
                </div>
            </div>
            ${explainDeadlineIso ? `<div style="padding:10px 14px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.82rem;color:#b71c1c;line-height:1.6;margin-bottom:14px;">
                <b><i class="fas fa-hourglass-half"></i> 5-Day Explain Deadline:</b>
                ${new Date(explainDeadlineIso.replace(' ','T')).toLocaleString('en-US',{dateStyle:'full',timeStyle:'short'})}
                <div style="margin-top:4px;font-size:0.78rem;">If the absent party does not explain by then, the absence will be automatically declared <b>UNJUSTIFIED</b>.</div>
            </div>` : ''}
            <div class="handbook-quote">
                <div class="handbook-quote-title"><i class="fas fa-gavel"></i> RA 7160 — Consequences</div>
                <p><b>Complainant unjustified</b> → Complaint DISMISSED and barred from court. <b>KP Form #23</b> auto-issued by the Pangkat Secretary (soft copy only — physical delivery required).</p>
                <p><b>Respondent unjustified</b> → Counterclaim barred; case forwarded to <b>Certification to File Action</b>. <b>KP Form #24</b> auto-issued by the Pangkat Secretary (soft copy only — physical delivery required).</p>
                <p><b>All justified</b> → Conciliation rescheduled.</p>
                <p style="margin-top:8px;color:#b71c1c;font-weight:700;"><i class="fas fa-hourglass-half"></i> Note: If the absent party does not explain within <b>5 days</b> after the appearance date, the absence is automatically declared <b>UNJUSTIFIED</b> by the cron reminder — the same KP #23 / KP #24 outcomes apply.</p>
            </div>
            <div id="chairDecisionCards">${cards}</div>
        </div>`,
        width: 720,
        allowOutsideClick: false,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-gavel"></i> Confirm Decision',
        confirmButtonColor: '#c62828',
        cancelButtonColor: '#6c757d',
        preConfirm: () => {
            const cards = document.querySelectorAll('#chairDecisionCards .abs-card');
            const out = [];
            let allDecided = true;
            cards.forEach(card => {
                const pid = Number(card.dataset.decisionPid);
                const ptype = card.dataset.partyType || '';
                const selected = card.querySelector('.abs-radio.selected');
                const ta = card.querySelector('textarea[data-role="appearance_notes"]');
                const notes = ta ? ta.value.trim() : '';
                if (!selected) { allDecided = false; return; }
                const status = selected.classList.contains('justified') ? 'justified' : 'unjustified';
                out.push({ party_id: pid, status, reason: notes, appearance_notes: notes, party_type: ptype });
            });
            if (!allDecided) { Swal.showValidationMessage('Please decide for all absent parties.'); return false; }
            return out;
        }
    }).then(async (result) => {
        if (!result.isConfirmed || !result.value) return;
        Swal.fire({ title: 'Recording decision…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const fd = new FormData();
        fd.append('action', 'chair_resolve_concil_absence');
        fd.append('complaint_id', caseId);
        fd.append('decisions', JSON.stringify(result.value));
        try {
            const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
            const resp = await r.json();
            Swal.close();
            if (!resp.success) { Swal.fire('Error', resp.message || 'Failed.', 'error'); return; }
            let outcomeTitle = 'Decision Recorded';
            let outcomeMsg = '';
            if (resp.outcome === 'dismissed_complaint') { outcomeTitle = 'Complaint Dismissed'; outcomeMsg = 'Complaint was DISMISSED.'; }
            else if (resp.outcome === 'respondent_no_show') { outcomeTitle = 'Proceeding to Certification'; outcomeMsg = 'Respondent did not appear.'; }
            else { outcomeTitle = 'Conciliation Rescheduled'; outcomeMsg = 'All absences were justified.'; }
            Swal.fire({
                icon: resp.outcome === 'dismissed_complaint' ? 'error' : 'info',
                title: outcomeTitle,
                html: `<div style="text-align:left;font-size:0.9rem;">
                    <p>${escapeHtml(outcomeMsg)}</p>
                    ${showBarCertInfo(resp.cert_info)}
                </div>`,
                confirmButtonText: 'Done',
                confirmButtonColor: '#2E7D32',
                width: 620
            }).then(() => {
                if (fromPage && window.__hearing.active) refreshHearingView();
                else loadMyComplaints();
            });
        } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
    });
}

function chairPickDecision(pid, status, el) {
    const card = el.closest('.abs-card');
    if (!card) return;
    card.querySelectorAll('.abs-radio').forEach(r => { r.classList.remove('selected', 'justified', 'unjustified'); r.style.borderColor = '#e2efe8'; r.style.background = '#fff'; });
    el.classList.add('selected', status);
    if (status === 'justified') { el.style.borderColor = '#2E7D32'; el.style.background = '#f1f8e9'; }
    else { el.style.borderColor = '#c62828'; el.style.background = '#ffebee'; }
    const radio = el.querySelector('input[type=radio]');
    if (radio) radio.checked = true;
}

// ============================================================
// BAR CERT HELPER (KP #23 / KP #24)
// ============================================================
function showBarCertInfo(certInfo) {
    if (!certInfo) return '';
    const isKp23 = certInfo.form === 'KP_FORM_23';
    const label = isKp23
        ? 'KP Form #23 — Certification to Bar Action'
        : 'KP Form #24 — Certification to Bar Counterclaim';
    const note = isKp23
        ? 'Complainant is barred from filing a case in court. The complaint is DISMISSED.'
        : 'Respondent\'s counterclaim (if any) is barred. The case proceeds to Certification to File Action.';
    return `<div style="margin-top:12px;padding:12px 14px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.85rem;color:#b71c1c;">
        <b><i class="fas fa-gavel"></i> ${escapeHtml(label)}</b><br>
        Cert No: <b>${escapeHtml(certInfo.cert_no || '')}</b><br>
        <span style="color:#b71c1c;">${escapeHtml(note)}</span><br>
        <span style="font-size:0.78rem;color:#666;display:block;margin-top:4px;">Stored as soft copy only — NO digital signature. Physical delivery by the Pangkat Secretary required.</span>
        ${certInfo.pdf_url ? `<a href="#" onclick="event.preventDefault(); window.open('../../${escapeHtml(certInfo.pdf_url)}', '_blank');" style="display:inline-block;margin-top:8px;padding:6px 14px;background:#c62828;color:#fff;border-radius:6px;text-decoration:none;font-size:0.78rem;font-weight:700;"><i class="fas fa-file-pdf"></i> Open PDF</a>` : ''}
    </div>`;
}

// ============================================================
// ASSIGN POSITIONS MODAL
// ============================================================
function openAssignPositionsModal(caseId) {
    const caseData = cachedCases.find(c => Number(c.id) === Number(caseId));
    if (!caseData) { Swal.fire('Error', 'Case not found.', 'error'); return; }
    const members = caseData.pangkat_members || [];
    if (members.length !== 3) { Swal.fire('Error', 'Pangkat must have exactly 3 members.', 'error'); return; }
    const optHtml = (m) => `<option value="${m.member_id}">${escapeHtml(m.full_name)}${Number(m.member_id) === ADMIN_ID ? ' (You)' : ''}</option>`;
    Swal.fire({
        title: '',
        html: `<div style="text-align:left;"><div class="dm-field"><label style="font-weight:800;color:#b71c1c;text-transform:uppercase;">Assign Pangkat Positions</label><div style="font-size:0.82rem;color:#555;margin-top:4px;margin-bottom:10px;">Ref: <b>${escapeHtml(caseData.reference_number)}</b></div><div style="padding:10px 14px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.78rem;color:#b71c1c;"><b>⚠️ This action is FINAL</b></div></div><div class="dm-field"><label>Chairperson <span class="req">*</span></label><select id="posChair"><option value="">— Select —</option>${members.map(optHtml).join('')}</select></div><div class="dm-field"><label>Secretary <span class="req">*</span></label><select id="posSecretary" disabled><option value="">— Select —</option></select></div><div class="dm-field"><label>Member <span class="req">*</span></label><select id="posMember" disabled><option value="">— Select —</option></select></div></div>`,
        width: 560,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-check"></i> Save Positions',
        confirmButtonColor: '#b71c1c',
        cancelButtonColor: '#6c757d',
        allowOutsideClick: false,
        didOpen: () => {
            const selChair = document.getElementById('posChair');
            const selSec   = document.getElementById('posSecretary');
            const selMem   = document.getElementById('posMember');
            const rebuildSec = () => {
                const chair = selChair.value;
                selSec.innerHTML = '<option value="">— Select —</option>';
                members.forEach(m => { if (String(m.member_id) === String(chair)) return; selSec.innerHTML += `<option value="${m.member_id}">${escapeHtml(m.full_name)}${Number(m.member_id) === ADMIN_ID ? ' (You)' : ''}</option>`; });
                selSec.disabled = !chair; selSec.value = ''; rebuildMem();
            };
            const rebuildMem = () => {
                const chair = selChair.value, sec = selSec.value;
                selMem.innerHTML = '<option value="">— Select —</option>';
                members.forEach(m => { if (String(m.member_id) === String(chair)) return; if (String(m.member_id) === String(sec)) return; selMem.innerHTML += `<option value="${m.member_id}">${escapeHtml(m.full_name)}${Number(m.member_id) === ADMIN_ID ? ' (You)' : ''}</option>`; });
                selMem.disabled = !(chair && sec); selMem.value = '';
            };
            selChair.addEventListener('change', rebuildSec);
            selSec.addEventListener('change', rebuildMem);
        },
        preConfirm: () => {
            const chair = Number(document.getElementById('posChair').value);
            const sec = Number(document.getElementById('posSecretary').value);
            const mem = Number(document.getElementById('posMember').value);
            if (!chair || !sec || !mem) { Swal.showValidationMessage('Select all three positions.'); return false; }
            if (chair === sec || chair === mem || sec === mem) { Swal.showValidationMessage('Each member can only hold one position.'); return false; }
            return { chair_id: chair, secretary_id: sec, member_id: mem };
        }
    }).then(async (result) => {
        if (!result.isConfirmed || !result.value) return;
        const chairName = members.find(m => Number(m.member_id) === result.value.chair_id)?.full_name || '';
        const secName = members.find(m => Number(m.member_id) === result.value.secretary_id)?.full_name || '';
        const memName = members.find(m => Number(m.member_id) === result.value.member_id)?.full_name || '';
        const confirm = await Swal.fire({
            icon: 'warning',
            title: 'Confirm Final Positions?',
            html: `<div style="text-align:left;font-size:0.9rem;"><div style="padding:12px 14px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.83rem;color:#b71c1c;"><b>This action is FINAL.</b></div><ul style="line-height:1.8; margin-top:12px;"><li><b>Chairperson:</b> ${escapeHtml(chairName)}</li><li><b>Secretary:</b> ${escapeHtml(secName)}</li><li><b>Member:</b> ${escapeHtml(memName)}</li></ul></div>`,
            showCancelButton: true,
            confirmButtonText: 'Yes, Confirm',
            confirmButtonColor: '#b71c1c',
            cancelButtonText: 'Cancel',
            cancelButtonColor: '#6c757d',
            allowOutsideClick: false
        });
        if (!confirm.isConfirmed) return;
        Swal.fire({ title: 'Saving…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const fd = new FormData();
        fd.append('action', 'lupon_assign_pangkat_positions');
        fd.append('complaint_id', caseId);
        fd.append('chair_id', result.value.chair_id);
        fd.append('secretary_id', result.value.secretary_id);
        fd.append('member_id', result.value.member_id);
        try {
            const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
            const d = await r.json();
            Swal.close();
            if (d.success) {
                Swal.fire({ icon: 'success', title: 'Positions Assigned', text: 'Next step: Chairperson schedules the conciliation.', confirmButtonColor: '#2E7D32' }).then(() => loadMyComplaints());
            } else Swal.fire('Error', d.message || 'Failed.', 'error');
        } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
    });
}

// ============================================================
// CHAIR: Schedule Conciliation
// ============================================================
async function openChairScheduleModal(caseId) {
    const today = new Date().toISOString().split('T')[0];
    let witnesses = [];
    try { const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${caseId}`); const d = await r.json(); if (d.success) witnesses = d.witnesses || []; } catch (e) {}
    const hasWitnesses = witnesses.length > 0;
    const result = await Swal.fire({
        title: '',
        html: `<div style="text-align:left;"><div style="font-weight:800;color:#e65100;font-size:1.05rem;display:flex;align-items:center;gap:8px;padding-bottom:10px;margin-bottom:12px;border-bottom:2px solid #ffe0b2;"><i class="fas fa-calendar-plus"></i> Schedule Conciliation Hearing</div><div class="dm-field"><label>Date <span class="req">*</span></label><input type="date" id="chairDate" min="${today}" value="${today}"></div><div class="dm-field"><label>Time <span class="req">*</span></label><input type="time" id="chairTime" value="09:00"></div><div class="dm-field"><label>Location</label><input type="text" id="chairLocation" value="Barangay Hall"></div><div class="dm-field"><label>Notes (optional)</label><textarea id="chairNotes" rows="2"></textarea></div><div class="dm-field" style="margin-top:16px;"><label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:10px 14px;background:#e3f2fd;border-left:4px solid #1565c0;border-radius:8px;"><input type="checkbox" id="chairIssueWitnessSubpoenas" ${hasWitnesses ? '' : 'disabled'} style="width:18px;height:18px;"><span><b>Also issue Subpoena (KP #13)</b> for witnesses</span></label></div><div style="padding:10px 14px;background:#e0f7fa;border-left:4px solid #00838f;border-radius:8px;font-size:0.78rem;color:#005662;line-height:1.55;margin-top:14px;"><b>What happens next:</b><br>• <b>KP Form #12</b> will be issued to both parties.<br>• The 15-day conciliation period starts on the hearing date.<br>• You may extend by +15 days later if needed (max 30).</div></div>`,
        width: 580,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-paper-plane"></i> Schedule & Issue Notices',
        confirmButtonColor: '#e65100',
        cancelButtonColor: '#6c757d',
        allowOutsideClick: false,
        preConfirm: () => {
            const date = document.getElementById('chairDate').value;
            const time = document.getElementById('chairTime').value;
            if (!date || !time) { Swal.showValidationMessage('Set date and time.'); return false; }
            return { date, time, location: document.getElementById('chairLocation').value.trim() || 'Barangay Hall', notes: document.getElementById('chairNotes').value.trim(), issue_witness_subpoenas: document.getElementById('chairIssueWitnessSubpoenas').checked ? '1' : '0' };
        }
    });
    if (!result.isConfirmed || !result.value) return;
    Swal.fire({ title: 'Scheduling…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    const fd = new FormData();
    fd.append('action', 'pangkat_chairman_schedule');
    fd.append('complaint_id', caseId);
    fd.append('hearing_date', result.value.date);
    fd.append('hearing_time', result.value.time);
    fd.append('hearing_location', result.value.location);
    fd.append('notes', result.value.notes);
    fd.append('issue_witness_subpoenas', result.value.issue_witness_subpoenas);
    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        Swal.close();
        if (d.success) {
            const deadlineNice = d.conciliation_hard_deadline ? new Date(d.conciliation_hard_deadline.replace(' ','T')).toLocaleDateString('en-US', {dateStyle:'long'}) : '';
            Swal.fire({
                icon: 'success',
                title: 'Conciliation Scheduled',
                html: `<div style="text-align:left;font-size:0.9rem;">
                    <p>Hearing set for <b>${escapeHtml(result.value.date)}</b> at <b>${escapeHtml(result.value.time)}</b>.</p>
                    <p style="font-size:0.82rem;color:#666;">${escapeHtml(result.value.location)}</p>
                    ${deadlineNice ? `<div style="margin-top:12px;padding:10px 12px;background:#e0f7fa;border-left:4px solid #00838f;border-radius:8px;font-size:0.82rem;color:#005662;"><i class="fas fa-hourglass-half"></i> Hard deadline: <b>${escapeHtml(deadlineNice)}</b></div>` : ''}
                </div>`,
                confirmButtonColor: '#2E7D32',
                width: 620
            }).then(() => loadMyComplaints());
        } else Swal.fire('Error', d.message || 'Failed.', 'error');
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}

// ============================================================
// COMPLAINT DETAIL MODAL
// ============================================================
function toggleCdBlock(blockId) { const el = document.getElementById(blockId); if (el) el.classList.toggle('collapsed'); }

async function openComplaintDetail(id) {
    let d;
    try { const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${id}`); d = await r.json(); } catch (e) { Swal.fire('Error', 'Could not load case.', 'error'); return; }
    if (!d || !d.success) { Swal.fire('Error', d.message || 'Error', 'error'); return; }
    const c = d.complaint;
    const updates = d.updates || [];
    const kpForms = d.kp_forms || [];
    const myPangkat = d.my_pangkat || {};
    const myRole = myPangkat.my_role || null;
    const isFirst = myPangkat.is_first === true;
    const allPending = myPangkat.all_pending === true;
    const needsAssign = isFirst && allPending && (c.status === 'pangkat_constituted' || c.status === 'pangkat_scheduled');
    const isChair = myRole === 'chair';
    const waitingForAppearance = !!c.concil_absence_waiting_since && !c.concil_absence_appearance_at;
    const hasAppearanceSet = !!c.concil_absence_appearance_at;
    const appearancePassed = hasAppearanceSet && new Date(c.concil_absence_appearance_at.replace(' ','T')).getTime() <= Date.now();
    const canScheduleNow = isChair && c.status === 'pangkat_constituted';
    const canSetAppearance = isChair && waitingForAppearance;
    const canDecideAbsence = isChair && hasAppearanceSet && appearancePassed;
    const isSettledPending = c.status === 'settled_pending';
    const isSettledExecutory = c.status === 'settled';
    const awaitsTerms = !!(c.status === 'settled_pending' && !c.kp16_pdf_path && !c.concil_hearing_started_at);

    let headerCls = needsAssign ? 'cd-assign' : 'cd-pangkat';
    if (isSettledPending) headerCls = 'cd-settled-pending';
    else if (isSettledExecutory) headerCls = 'cd-settled';

    const pangkatHtml = (d.pangkat || []).length ? `<div class="cd-block"><div class="cd-block-title"><i class="fas fa-balance-scale"></i> Pangkat Panel (${d.pangkat.length})</div>${d.pangkat.map((p, i) => { const isFirstMember = i === 0; const roleText = (p.role === 'pending' || !p.role) ? 'Awaiting Position Assignment' : p.role.toUpperCase(); return `<div class="cd-person"><div class="cd-person-avatar" style="background:#f1f8e9;color:#2E7D32;"><i class="fas fa-user-tie"></i></div><div style="flex:1;"><div class="cd-person-name">${escapeHtml(p.full_name)} ${isFirstMember ? '<span style="font-size:0.7rem;color:#2E7D32;font-weight:700;">(first-selected)</span>' : ''}</div><div style="font-size:0.7rem;text-transform:uppercase;font-weight:700;color:#666;">${escapeHtml(roleText)}</div></div></div>`; }).join('')}</div>` : '';
    const docsHtml = (d.pangkat_documents || []).length ? d.pangkat_documents.map(doc => `<div class="doc-list-row"><i class="fas fa-file-alt" style="color:#2E7D32;"></i><a href="../../${escapeHtml(doc.file_path)}" target="_blank">${escapeHtml(doc.file_name)}</a><span class="doc-meta">${new Date(doc.created_at).toLocaleDateString()}</span></div>`).join('') : '<div style="color:#888;font-size:0.85rem;">No documents.</div>';
    const kpHtml = kpForms.length ? kpForms.map(f => {
        const displayNo = (f.form_tag === 'kp21') ? '20-A' : f.form_no;
        return `<div class="kp-entry generated"><div class="kp-entry-icon">✓</div><div class="kp-entry-body"><div class="kp-entry-title">KP #${displayNo} — ${escapeHtml(f.form_name)}</div><div class="kp-entry-event">${escapeHtml(f.event || '')}</div><div class="kp-entry-meta"><span><i class="fas fa-user-tie"></i> ${escapeHtml(f.issued_by || '—')}</span></div></div><div class="kp-entry-actions"><button class="kp-entry-btn open" onclick='openKpFormFromList(${JSON.stringify(f).replace(/'/g,"&#39;")})'><i class="fas fa-file-pdf"></i> Open</button></div></div>`;
    }).join('') : '<div style="color:#999;text-align:center;padding:20px;">No KP forms.</div>';

    let settlementBlock = '';
    if (isSettledPending || isSettledExecutory) {
        const s = d.settlement || {};
        const deadline = s.repudiation_deadline ? new Date(s.repudiation_deadline.replace(' ','T')) : null;
        settlementBlock = `<div class="cd-block"><div class="cd-block-title"><i class="fas fa-handshake"></i> Amicable Settlement</div>
            <div class="cd-grid">
                <div class="cd-grid-item"><label>Signed On</label><span>${s.settlement_signed_at ? new Date(s.settlement_signed_at.replace(' ','T')).toLocaleDateString('en-US',{dateStyle:'long'}) : '—'}</span></div>
                <div class="cd-grid-item"><label>Repudiation Window Ends</label><span>${deadline ? deadline.toLocaleDateString('en-US',{dateStyle:'long'}) : '—'}</span></div>
                <div class="cd-grid-item"><label>Status</label><span>${isSettledExecutory ? '✓ EXECUTORY' : (awaitsTerms ? 'Awaiting Secretary Terms' : (s.days_until_executory !== null ? s.days_until_executory + ' day(s) left' : 'PENDING'))}</span></div>
            </div>
            <div style="margin-top:12px;padding:10px 14px;background:#e0f7fa;border-left:4px solid #00838f;border-radius:8px;font-size:0.82rem;color:#006064;">
                <b>Note:</b> The Amicable Settlement is stored as a soft copy for records. Per RA 7160, no digital signature is applied — parties must sign the physical copy.
            </div>
        </div>`;
    }

    const actions = [];
    if (needsAssign) actions.push(`<button class="cd-btn assign" onclick="Swal.close(); openAssignPositionsModal(${id})"><i class="fas fa-user-tag"></i> Assign Positions</button>`);
    if (canScheduleNow) actions.push(`<button class="cd-btn schedule" onclick="Swal.close(); openChairScheduleModal(${id})"><i class="fas fa-calendar-plus"></i> Schedule Conciliation</button>`);
    if (canSetAppearance) actions.push(`<button class="cd-btn" style="background:#c62828;color:#fff;" onclick="Swal.close(); openChairAppearanceModal(${id})"><i class="fas fa-user-clock"></i> Set Appearance Date</button>`);
    if (canDecideAbsence) actions.push(`<button class="cd-btn start" onclick="Swal.close(); openChairAbsenceDecisionModal(${id})"><i class="fas fa-gavel"></i> Decide on Absences</button>`);
    if (awaitsTerms && myRole === 'secretary') {
        actions.push(`<button class="cd-btn" style="background:#2E7D32;color:#fff;" onclick="Swal.close(); window.__hearing.complaintId=${id}; window.__hearing.reference='${escapeHtml(c.reference_number)}'; openSettlementTermsModal();"><i class="fas fa-file-signature"></i> Write Settlement Terms</button>`);
    }
    if (myRole === 'secretary' && c.status === 'unresolved' && !c.kp21_pdf_path) {
        actions.push(`<button class="cd-btn" style="background:#b71c1c;color:#fff;" onclick="Swal.close(); openPrepareKp20aModal(${id})"><i class="fas fa-file-certificate"></i> Prepare KP #20-A</button>`);
    }
    actions.push(`<button class="cd-btn secondary" onclick="Swal.close()">Close</button>`);

    await Swal.fire({
        title: '',
        html: `<div class="cd-modal"><div class="cd-header ${headerCls}"><div class="cd-header-left"><i class="fas fa-balance-scale"></i><div><div class="cd-title">${escapeHtml(c.title)}</div><div class="cd-sub">Ref: <b>${escapeHtml(c.reference_number)}</b></div></div></div><div class="cd-status">${(c.status || '').replace(/_/g,' ').toUpperCase()}</div></div><div class="cd-body">
            <div class="cd-block"><div class="cd-block-title"><span class="cd-num">1</span> Case Information</div><div class="cd-grid"><div class="cd-grid-item"><label>Ref</label><span>${escapeHtml(c.reference_number)}</span></div><div class="cd-grid-item"><label>Status</label><span>${(c.status || '').replace(/_/g,' ').toUpperCase()}</span></div></div><div class="cd-text-block"><label>Description</label><p>${escapeHtml(c.description || '-').replace(/\n/g,'<br>')}</p></div></div>
            <div class="cd-block"><div class="cd-block-title"><span class="cd-num">2</span> Complainant(s)</div>${(d.complainants || []).map(p => `<div class="cd-person"><div class="cd-person-avatar" style="background:#e8f5e9;color:#2E7D32;"><i class="fas fa-user"></i></div><div class="cd-person-name">${escapeHtml(p.full_name)}</div></div>`).join('')}</div>
            <div class="cd-block"><div class="cd-block-title"><span class="cd-num">3</span> Respondent(s)</div>${(d.respondents || []).map(p => `<div class="cd-person"><div class="cd-person-avatar" style="background:#fff3e0;color:#e65100;"><i class="fas fa-user-friends"></i></div><div class="cd-person-name">${escapeHtml(p.full_name)}</div></div>`).join('')}</div>
            ${settlementBlock}
            ${pangkatHtml}
            <div class="cd-block"><div class="cd-block-title"><i class="fas fa-paperclip"></i> Documents</div>${docsHtml}</div>
            <div class="cd-block"><div class="cd-block-title"><i class="fas fa-file-signature"></i> KP Forms (${kpForms.length})</div>${kpHtml}</div>
        </div><div class="cd-actions">${actions.join('')}</div></div>`,
        width: 1080,
        showConfirmButton: false,
        customClass: { popup: 'cd-popup' }
    });
}

// ============================================================
// KP FORM OPENERS
// ============================================================
async function openKpFormFromList(f) {
    const tag = f.form_tag;
    if (f.pdf_path) { window.open('../../' + f.pdf_path, '_blank'); return; }
    if ((tag === 'kp8' || tag === 'kp9' || tag === 'kp12') && f.hearing_id) return openHearingKpNoticePdf(f.hearing_id, tag, f.party_id);
    if (tag === 'kp8' || tag === 'kp9') { if (f.party_id) return openPartyNoticePdf(f.party_id); }
    if (tag === 'kp10') return openKp10Pdf(window.__hearing?.complaintId || window.__kpComplaintId, f.party_id || null);
    if (tag === 'kp11') return openKp11Pdf(window.__hearing?.complaintId || window.__kpComplaintId, f.member_id);
    if (tag === 'kp18' || tag === 'kp19') return openKpFailureNoticePdf(window.__hearing?.complaintId || window.__kpComplaintId, f.hearing_id, f.party_id);
    Swal.fire('Info', 'No PDF available.', 'info');
}

async function openHearingKpNoticePdf(hearingId, formTag, partyId) {
    Swal.fire({ title: 'Loading PDF…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_hearing_kp_notices&hearing_id=${hearingId}`);
        const d = await r.json();
        Swal.close();
        if (!d.success) { Swal.fire('Error', d.message || 'Could not load.', 'error'); return; }
        let pdfUrl = null;
        if (formTag === 'kp8') pdfUrl = d.kp8?.pdf_url;
        if (formTag === 'kp9') pdfUrl = d.kp9?.pdf_url;
        if (formTag === 'kp12') { const isC = partyId && d.kp12?.complainant?.pdf_url; pdfUrl = isC ? d.kp12.complainant.pdf_url : (d.kp12?.respondent?.pdf_url || d.kp12?.complainant?.pdf_url); }
        if (pdfUrl) window.open('../../' + pdfUrl, '_blank');
        else Swal.fire('Info', 'No PDF available.', 'info');
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}

async function openPartyNoticePdf(partyId) {
    Swal.fire({ title: 'Loading…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try { const r = await fetch(`../admin_complaint_ajax.php?action=get_party_notice_pdf&party_id=${partyId}`); const d = await r.json(); Swal.close(); if (d.success && d.pdf_url) window.open('../../' + d.pdf_url, '_blank'); else Swal.fire('Error', d.message || 'Error', 'error'); }
    catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}
async function openKpFailureNoticePdf(complaintId, hearingId, partyId) {
    Swal.fire({ title: 'Loading…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try { const r = await fetch(`../admin_complaint_ajax.php?action=get_kp_failure_notice_pdf&complaint_id=${complaintId}&hearing_id=${hearingId}&party_id=${partyId}`); const d = await r.json(); Swal.close(); if (d.success && d.pdf_url) window.open('../../' + d.pdf_url, '_blank'); else Swal.fire('Error', d.message || 'Error', 'error'); }
    catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}
async function openKp10Pdf(complaintId, partyId) {
    Swal.fire({ title: 'Loading…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try { const url = `../admin_complaint_ajax.php?action=get_kp10_pdf&complaint_id=${complaintId}` + (partyId ? `&party_id=${partyId}` : ''); const r = await fetch(url); const d = await r.json(); Swal.close(); if (d.success && d.pdf_url) window.open('../../' + d.pdf_url, '_blank'); else Swal.fire('Error', d.message || 'Error', 'error'); }
    catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}
async function openKp11Pdf(complaintId, memberId) {
    Swal.fire({ title: 'Loading…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try { const r = await fetch(`../admin_complaint_ajax.php?action=get_kp11_pdf&complaint_id=${complaintId}&member_id=${memberId}`); const d = await r.json(); Swal.close(); if (d.success && d.pdf_url) window.open('../../' + d.pdf_url, '_blank'); else Swal.fire('Error', d.message || 'Error', 'error'); }
    catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}

// ============================================================
// NOTIFICATIONS + PUSH
// ============================================================
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

async function initNotifications() {
    const bell = document.getElementById('notifBell');
    const dropdown = document.getElementById('notifDropdown');
    const markRead = document.getElementById('notifMarkRead');
    const pushToggle = document.getElementById('notifPushToggle');
    const selectAll = document.getElementById('notifSelectAll');
    const deleteBtn = document.getElementById('notifDeleteBtn');
    if (!bell) return;
    bell.addEventListener('click', (e) => { e.stopPropagation(); dropdown.classList.toggle('show'); if (dropdown.classList.contains('show')) fetchNotifications(); });
    document.addEventListener('click', (e) => { const wrap = document.getElementById('notifBellWrap'); if (wrap && !wrap.contains(e.target)) dropdown.classList.remove('show'); });
    markRead.addEventListener('click', async (e) => { e.stopPropagation(); await fetch('../admin_complaint_ajax.php', { method: 'POST', body: new URLSearchParams({ action: 'mark_notifications_read' }) }); fetchNotifications(); });
    selectAll.addEventListener('change', () => { document.querySelectorAll('.notif-item .notif-check').forEach(cb => { cb.checked = selectAll.checked; }); updateDeleteBtn(); });
    deleteBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        const ids = Array.from(document.querySelectorAll('.notif-item .notif-check:checked')).map(cb => cb.value);
        if (!ids.length) return;
        const confirm = await Swal.fire({ icon: 'warning', title: 'Delete?', text: `${ids.length} notification(s).`, showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#c62828' });
        if (!confirm.isConfirmed) return;
        const fd = new FormData();
        fd.append('action', 'delete_notifications');
        fd.append('notification_ids', JSON.stringify(ids));
        try {
            const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d.success) { selectAll.checked = false; fetchNotifications(); Swal.fire({ icon: 'success', title: 'Deleted', timer: 1200, showConfirmButton: false }); }
        } catch (err) {}
    });
    if ('serviceWorker' in navigator && 'PushManager' in window) {
        try { swRegistration = await navigator.serviceWorker.register(SW_URL, { scope: '../' }); pushSubscription = await swRegistration.pushManager.getSubscription(); if (pushSubscription && pushToggle) pushToggle.checked = true; } catch (err) {}
    } else if (pushToggle) pushToggle.disabled = true;
    if (pushToggle) { pushToggle.addEventListener('change', async () => { if (pushToggle.checked) { const ok = await enablePush(); if (!ok) pushToggle.checked = false; } else await disablePush(); }); }
    fetchNotifications();
    setInterval(fetchNotifications, 5000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) fetchNotifications(); });
    window.addEventListener('focus', fetchNotifications);
}
function updateDeleteBtn() {
    const btn = document.getElementById('notifDeleteBtn');
    if (!btn) return;
    const allChecks = document.querySelectorAll('.notif-item .notif-check');
    const anyChecked = Array.from(allChecks).some(cb => cb.checked);
    btn.classList.toggle('show', anyChecked);
}
async function fetchNotifications() {
    try {
        const r = await fetch('../admin_complaint_ajax.php?action=get_my_notifications');
        const d = await r.json();
        if (!d.success) return;
                try { maybeOpenSecretaryPrintModal(d.notifications || []); } catch (e) {}

        const badge = document.getElementById('notifBadge');
        const list = document.getElementById('notifList');
        const bell = document.getElementById('notifBell');
        const checkedIds = new Set(Array.from(document.querySelectorAll('.notif-item .notif-check:checked')).map(cb => String(cb.value)));
        if (d.unread_count > 0) { badge.style.display = 'flex'; badge.textContent = d.unread_count > 99 ? '99+' : d.unread_count; bell.classList.add('has-new'); setTimeout(() => bell.classList.remove('has-new'), 1000); }
        else badge.style.display = 'none';
        if (!d.notifications.length) { list.innerHTML = '<div class="notif-empty"><i class="fas fa-inbox fa-2x"></i><br>No notifications.</div>'; }
        else {
            const iconMap = { assignment:'fa-user-check', hearing:'fa-calendar-alt', info:'fa-info-circle', warning:'fa-exclamation-triangle', success:'fa-check-circle', error:'fa-times-circle' };

            list.innerHTML = d.notifications.map(n => {
                const isChecked = checkedIds.has(String(n.id));

                const hasDoc = n.document_path && String(n.document_path).trim() !== '';
                const pdfUrl = hasDoc
                    ? (String(n.document_path).startsWith('http')
                        ? n.document_path
                        : '../../' + String(n.document_path).replace(/^\/+/, ''))
                    : '';

                const checkbox = `<input type="checkbox" class="notif-check" value="${n.id}" ${isChecked ? 'checked' : ''} onclick="event.stopPropagation(); updateDeleteBtn();">`;

                const inner = `
                    <div class="notif-icon ${n.type}"><i class="fas ${iconMap[n.type]||'fa-bell'}"></i></div>
                    <div class="notif-body">
                        <div class="notif-title">${escapeHtml(n.title)}</div>
                        <div class="notif-msg">${escapeHtml(n.message)}</div>
                        <div class="notif-time">
                            ${timeAgo(n.created_at)}
                            ${hasDoc ? '<span class="notif-doc-chip"><i class="fas fa-external-link-alt"></i> Open PDF</span>' : ''}
                        </div>
                    </div>`;

                if (hasDoc) {
                    return `
                    <div class="notif-item has-doc ${n.is_read == 0 ? 'unread' : ''}">
                        ${checkbox}
                        <a href="${escapeHtml(pdfUrl)}"
                           target="_blank"
                           rel="noopener noreferrer"
                           title="Open the attached KP form in a new tab"
                           onclick="markNotifRead(${n.id})"
                           style="display:flex;gap:10px;flex:1;cursor:pointer;color:inherit;text-decoration:none;">
                            ${inner}
                        </a>
                    </div>`;
                }

                return `
                <div class="notif-item ${n.is_read == 0 ? 'unread' : ''}">
                    ${checkbox}
                    <div onclick="onNotifClick(${n.id}, ${n.reference_id || 'null'}, '${escapeHtml(n.reference_type || '')}')" style="display:flex;gap:10px;flex:1;cursor:pointer;">
                        ${inner}
                    </div>
                </div>`;
            }).join('');
        }
        const selectAllEl = document.getElementById('notifSelectAll');
        if (selectAllEl) { const all = document.querySelectorAll('.notif-item .notif-check'); const allChecked = all.length > 0 && Array.from(all).every(cb => cb.checked); selectAllEl.checked = allChecked; }
        updateDeleteBtn();
    } catch (e) {}
}
function timeAgo(dt) {
    if (!dt) return '';
    const then = new Date(String(dt).replace(' ', 'T'));
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
    fetch('../admin_complaint_ajax.php', { method: 'POST', body: new URLSearchParams({ action: 'mark_notification_read', notification_id: id }) }).catch(() => {});
    if (refType === 'complaint' && refId) { renderMyComplaints(); setTimeout(() => { openComplaintDetail(refId); }, 300); }
    else setTimeout(fetchNotifications, 300);
}
async function enablePush() {
    if (!swRegistration) { Swal.fire('Error', 'Not supported.', 'error'); return false; }
    try {
        const perm = await Notification.requestPermission();
        if (perm !== 'granted') { Swal.fire('Warning', 'Allow notifications.', 'warning'); return false; }
        const sub = await swRegistration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY) });
        const r = await fetch('../push_subscribe.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(sub.toJSON()) });
        const d = await r.json();
        if (!d.success) { Swal.fire('Error', 'Save failed.', 'error'); return false; }
        Swal.fire({ icon:'success', title:'Enabled!', timer:2000, showConfirmButton:false });
        return true;
    } catch (err) { Swal.fire('Error', 'Enable failed.', 'error'); return false; }
}
async function disablePush() {
    try {
        if (pushSubscription) {
            await fetch('../push_unsubscribe.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: pushSubscription.endpoint }) });
            await pushSubscription.unsubscribe();
            pushSubscription = null;
            Swal.fire({ icon:'success', title:'Disabled', timer:2000, showConfirmButton:false });
        }
    } catch (err) {}
}

// ============================================================
// INIT
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    renderDashboard();
    initNotifications();
});
</script>
</body>
</html>