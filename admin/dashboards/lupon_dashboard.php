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

$stats = [];
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM complaints WHERE assigned_to = $admin_id AND status = 'pangkat_scheduled'");
$stats['scheduled'] = mysqli_fetch_assoc($result)['total'];
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM complaints WHERE assigned_to = $admin_id AND status = 'settled'");
$stats['settled'] = mysqli_fetch_assoc($result)['total'];
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM complaints WHERE assigned_to = $admin_id AND status = 'failed_conciliation_final'");
$stats['failed'] = mysqli_fetch_assoc($result)['total'];
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM complaints WHERE assigned_to = $admin_id");
$stats['total'] = mysqli_fetch_assoc($result)['total'];

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Lupon Dashboard | BRITE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        <?php include '../admin.css'; ?>

        /* ============================================================
           Force SweetAlert popups above the hearing panel overlay
           ============================================================ */
        .swal2-container,
        .swal2-container.swal2-top,
        .swal2-container.swal2-center,
        .swal2-container.swal2-bottom {
            z-index: 100000 !important;
        }
        .swal2-popup {
            z-index: 100001 !important;
        }
        #hearingPanelOverlay {
            z-index: 99999;
        }

        .sidebar { background: linear-gradient(180deg, #2E7D32 20%, #43a047 80%); }
        .sidebar::-webkit-scrollbar-thumb { background-color: #81c784; }
        .welcome-card { background: linear-gradient(135deg, #2E7D32, #43a047); }
        .stat-icon.pending { background: #fff3e0; color: #e65100; }
        .stat-icon.mediation { background: #f3e5f5; color: #9c27b0; }
        .stat-icon.settled { background: #e8f5e9; color: #2e7d32; }
        .stat-icon.failed { background: #fce4ec; color: #c2185b; }
        .stat-icon.total { background: #f3e5f5; color: #9c27b0; }
        .stat-icon.assign { background: #ffebee; color: #c62828; }
        .btn-verify { background: #E65100; }
        .btn-verify:hover { background:#2e7d32 }
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
        .notif-time  { font-size: 0.68rem; color: #999; margin-top: 4px; }
        .notif-empty { padding: 30px; text-align: center; color: #999; font-size: 0.85rem; }
        .notif-footer { padding: 10px 16px; background: #f8faf8; border-top: 1px solid #e2efe8; font-size: 0.78rem; }
        .notif-permission-label { display: flex; align-items: center; gap: 8px; cursor: pointer; color: #555; }
        .notif-select-bar { display: flex; align-items: center; gap: 10px; padding: 8px 16px; background: #f8faf8; border-bottom: 1px solid #e2efe8; font-size: 0.78rem; color: #555; }
        .notif-select-bar input[type=checkbox] { width: 15px; height: 15px; cursor: pointer; }
        .notif-delete-btn { margin-left: auto; background: #c62828; color: #fff; border: none; padding: 4px 12px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; cursor: pointer; display: none; }
        .notif-delete-btn.show { display: inline-flex; align-items: center; gap: 5px; }
        .notif-item .notif-check {
            width: 18px;
            height: 18px;
            margin-top: 10px;
            cursor: pointer;
            accent-color: #2E7D32;
            flex-shrink: 0;
        }

        /* Card grid */
        .documents-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 18px; align-items: start; }

        .document-card {
            background: white;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            transition: all 0.25s ease;
            border: 1px solid #e2efe8;
            display: flex;
            flex-direction: column;
            position: relative;
            align-self: start;
        }
        .document-card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }

        .document-card.assign-needed {
            border: 2px solid #c62828;
            box-shadow: 0 0 0 3px rgba(198,40,40,0.15), 0 4px 14px rgba(198,40,40,0.25);
        }
        .document-card.assign-needed::before {
            content: "";
            position: absolute;
            inset: -2px;
            border-radius: 16px;
            border: 2px solid #c62828;
            animation: votePulse 2s infinite;
            pointer-events: none;
            z-index: 1;
        }
        @keyframes votePulse {
            0%, 100% { opacity: 0.6; transform: scale(1); }
            50% { opacity: 0; transform: scale(1.03); }
        }
        .assign-needed-ribbon {
            position: absolute;
            top: 12px; right: -32px;
            background: linear-gradient(135deg, #c62828, #e53935);
            color: #fff;
            padding: 4px 40px;
            font-size: 0.66rem;
            font-weight: 800;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            transform: rotate(45deg);
            box-shadow: 0 2px 6px rgba(198,40,40,0.4);
            z-index: 5;
        }
        .hearing-ribbon {
            position: absolute;
            top: 12px; right: 12px;
            background: linear-gradient(135deg, #d32f2f, #ff5252);
            color: #fff;
            padding: 4px 12px;
            font-size: 0.66rem;
            font-weight: 800;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(211,47,47,0.5);
            z-index: 6;
            animation: hearingPulse 1.5s infinite;
        }
        @keyframes hearingPulse {
            0%, 100% { box-shadow: 0 2px 8px rgba(211,47,47,0.5), 0 0 0 0 rgba(255,82,82,0.7); }
            50%      { box-shadow: 0 2px 8px rgba(211,47,47,0.5), 0 0 0 8px rgba(255,82,82,0); }
        }

        .document-card-header { background: linear-gradient(135deg, #2E7D32, #43a047); padding: 14px 18px; color: white; display: flex; justify-content: space-between; align-items: center; min-height: 52px; }
        .document-card-header.pending  { background: linear-gradient(135deg, #e65100, #f57c00); }
        .document-card-header.mediation { background: linear-gradient(135deg, #1565c0, #1976d2); }
        .document-card-header.referral { background: linear-gradient(135deg, #8e24aa, #ab47bc); }
        .document-card-header.settled  { background: linear-gradient(135deg, #2E7D32, #43a047); }
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
        .doc-action-btn.outcome  { background: linear-gradient(135deg, #2E7D32, #43a047); color: white; }
        .doc-action-btn.schedule { background: linear-gradient(135deg, #e65100, #f57c00); color: white; }
        .doc-action-btn.start    { background: linear-gradient(135deg, #6a1b9a, #8e24aa); color: white; }
        .doc-action-btn.open     { background: linear-gradient(135deg, #1565c0, #1976d2); color: white; }
        .doc-action-btn.enter    { background: linear-gradient(135deg, #d32f2f, #ff5252); color: white; box-shadow: 0 2px 8px rgba(211,47,47,0.5); animation: hearingPulse 1.5s infinite; }
        .doc-action-btn.appear   { background: linear-gradient(135deg, #c62828, #e53935); color: white; }
        .doc-action-btn.decide   { background: linear-gradient(135deg, #6a1b9a, #8e24aa); color: white; }
        .doc-action-btn.notice   { background: #00838f; color: white; }
        .doc-action-btn.locked   { background: #e0e0e0; color: #888; cursor: not-allowed; }

        .pangkat-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 3px 10px; border-radius: 12px;
            font-size: 0.7rem; font-weight: 700;
            background: #e8f5e9; color: #1b5e20; border: 1px solid #a5d6a7;
        }
        .pangkat-chip.role-chair     { background:#c8e6c9; color:#1b5e20; }
        .pangkat-chip.role-secretary { background:#bbdefb; color:#0d47a1; }
        .pangkat-chip.role-member    { background:#ffe0b2; color:#e65100; }
        .pangkat-chip.role-pending   { background:#fff8e1; color:#f57c00; }
        .pangkat-chip.anchor         { background:#e3f2fd; color:#1565c0; border-color:#90caf9; }
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
        .cd-block-title.toggleable:hover { color:#1565c0; }
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
        .cd-people-list { display: flex; flex-direction: column; gap: 10px; }
        .cd-person { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border-radius: 10px; background: #fafbfa; border: 1px solid #f0f0f0; }
        .cd-person-avatar { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .cd-person-name { font-weight: 700; color: #1a1a1a; font-size: 0.92rem; }
        .cd-person-meta { font-size: 0.8rem; color: #555; margin-top: 4px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
        .cd-history-item { background: #f8faf8; border-radius: 10px; padding: 10px 14px; border-left: 3px solid #43e97b; }
        .cd-history-time { font-size: 0.7rem; color: #999; }
        .cd-history-notes { font-size: 0.85rem; color: #1a2b22; margin-top: 3px; line-height: 1.5; }
        .cd-history-meta { font-size: 0.7rem; color: #666; margin-top: 3px; font-style: italic; }
        .cd-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; border: none; cursor: pointer; transition: all 0.15s; text-decoration: none; }
        .cd-btn.primary   { background: #2E7D32; color: #fff; }
        .cd-btn.schedule  { background: #e65100; color: #fff; }
        .cd-btn.assign    { background: #b71c1c; color: #fff; }
        .cd-btn.upload    { background: #1565c0; color: #fff; }
        .cd-btn.record    { background: #6a1b9a; color: #fff; }
        .cd-btn.start     { background: #6a1b9a; color: #fff; }
        .cd-btn.enter     { background: #d32f2f; color: #fff; }
        .cd-btn.secondary { background: #6c757d; color: #fff; }
        .cd-actions { display: flex; gap: 10px; justify-content: flex-end; padding: 14px 22px; background: #f8faf8; border-top: 1px solid #e2efe8; flex-wrap: wrap; }

        .session-record-block { background:#f8f9fa; border:1.5px solid #d0d7de; border-radius:10px; padding:14px 16px; margin-bottom:16px; }
        .session-record-title { font-weight:800; font-size:0.85rem; color:#1a472a; text-transform:uppercase; letter-spacing:0.3px; margin-bottom:12px; display:flex; align-items:center; gap:8px; padding-bottom:8px; border-bottom:1px solid #e2efe8; }
        .session-record-title i { color:#2E7D32; }

        .dm-field { margin-bottom: 16px; }
        .dm-field label { display: block; font-weight: 600; font-size: 0.85rem; color: #1a472a; margin-bottom: 6px; }
        .dm-field label .req { color: #c62828; }
        .dm-field input, .dm-field select, .dm-field textarea { width: 100%; padding: 10px 12px; border: 1.5px solid #cfd8dc; border-radius: 8px; font-size: 0.92rem; font-family: inherit; box-sizing: border-box; resize: vertical; background: #fff; }
        .dm-field input:focus, .dm-field select:focus, .dm-field textarea:focus { outline: none; border-color: #2E7D32; box-shadow: 0 0 0 3px rgba(46,125,50,0.12); }
        .dm-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        @media (max-width: 700px) { .dm-grid-2 { grid-template-columns: 1fr; } }

        .doc-list-row { display: flex; align-items: center; gap: 10px; padding: 8px 12px; background:#fafbfa; border:1px solid #e2efe8; border-radius:8px; margin-bottom:6px; font-size:0.82rem; }
        .doc-list-row a { flex:1; color:#1565c0; font-weight:600; text-decoration:none; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .doc-list-row a:hover { text-decoration:underline; }
        .doc-list-row .doc-meta { font-size:0.7rem; color:#888; }

        .duty-card { padding:14px 16px; border-radius:10px; border-left:4px solid; margin-bottom:10px; }
        .duty-card.chair { background:#e8f5e9; border-left-color:#2E7D32; }
        .duty-card.secretary { background:#e3f2fd; border-left-color:#1565c0; }
        .duty-card.member { background:#fff3e0; border-left-color:#e65100; }
        .duty-card h4 { margin:0 0 8px; font-size:0.9rem; text-transform:uppercase; letter-spacing:0.4px; }
        .duty-card.chair h4 { color:#1b5e20; }
        .duty-card.secretary h4 { color:#0d47a1; }
        .duty-card.member h4 { color:#bf360c; }
        .duty-card ul { margin:0; padding-left:20px; font-size:0.82rem; line-height:1.6; color:#333; }

        .prev-hearings-block { background:#f0fbff; border:1.5px solid #b2ebf2; border-radius:10px; margin-bottom:16px; overflow:hidden; }
        .prev-hearings-block.mediation-tone { background:#f0f4ff; border-color:#bbdefb; }
        .prev-hearings-block.mediation-tone .prev-hearings-header { background:#e3f2fd; color:#0d47a1; }
        .prev-hearings-block.mediation-tone .prev-hearings-header:hover { background:#bbdefb; }
        .prev-hearings-header { padding:12px 16px; background:#e0f7fa; display:flex; justify-content:space-between; align-items:center; cursor:pointer; user-select:none; font-weight:700; font-size:0.85rem; color:#00838f; }
        .prev-hearings-header:hover { background:#b2ebf2; }
        .prev-hearings-header .toggle-icon { transition: transform 0.2s; }
        .prev-hearings-block.expanded .prev-hearings-header .toggle-icon { transform: rotate(180deg); }
        .prev-hearings-body { display:none; padding:12px 16px; background:#fff; }
        .prev-hearings-block.expanded .prev-hearings-body { display:block; }
        .prev-hearing-item { padding:10px 14px; background:#fafbfa; border-left:4px solid #00acc1; border-radius:8px; margin-bottom:8px; font-size:0.82rem; cursor:pointer; transition: all .15s; }
        .prev-hearing-item.mediation-tone { border-left-color:#1565c0; }
        .prev-hearing-item:hover { background:#eff6ff; transform: translateX(2px); }
        .prev-hearing-item:last-child { margin-bottom:0; }
        .prev-hearing-head { font-weight:700; color:#1a472a; margin-bottom:4px; display:flex; align-items:center; justify-content:space-between; gap:8px; }
        .prev-hearing-meta { font-size:0.72rem; color:#666; display:flex; flex-wrap:wrap; gap:12px; margin-bottom:6px; }
        .prev-hearing-summary { font-size:0.8rem; color:#333; line-height:1.55; padding:6px 10px; background:#fff; border-radius:6px; border:1px dashed #d0d7de; }
        .prev-hearing-absent { font-size:0.75rem; color:#c62828; font-weight:600; }
        .prev-hearing-view-btn { font-size:0.68rem; padding:4px 10px; border-radius:6px; background:#1565c0; color:#fff; border:none; cursor:pointer; font-weight:700; display:inline-flex; align-items:center; gap:4px; }
        .prev-hearing-view-btn:hover { background:#0d47a1; }

        .concil-deadline-banner { display:flex; justify-content:space-between; align-items:center; gap:14px; padding:12px 16px; background:#e0f7fa; border-left:4px solid #00838f; border-radius:10px; font-size:0.85rem; color:#005662; margin-bottom:14px; flex-wrap:wrap; }
        .concil-deadline-banner.expired { background:#ffebee; border-left-color:#c62828; color:#b71c1c; }
        .concil-deadline-banner .cd-label { font-weight:700; display:flex; align-items:center; gap:8px; }

        .ev-row { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background:#fafbfa; border:1px solid #e2efe8; border-radius:8px; margin-bottom:6px; font-size:0.82rem; }
        .ev-row .ev-icon { width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; background:#e3f2fd; color:#1565c0; flex-shrink:0; }
        .ev-row .ev-body { flex:1; min-width:0; }
        .ev-row .ev-name { font-weight:700; color:#1a2b22; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .ev-row .ev-meta { font-size:0.7rem; color:#888; margin-top:2px; display:flex; gap:10px; flex-wrap:wrap; }
        .ev-row .ev-desc { font-size:0.75rem; color:#555; margin-top:3px; }
        .ev-row .ev-open { background:#1565c0; color:#fff; border:none; padding:6px 12px; border-radius:6px; font-size:0.72rem; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:5px; }
        .ev-row .ev-open:hover { background:#0d47a1; }

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

        /* KP PHASED LIST */
        .kp-phase-section { margin-bottom: 18px; }
        .kp-phase-header { font-weight: 800; font-size: 0.85rem; color: #1a472a; text-transform: uppercase; letter-spacing: 0.3px; padding: 8px 12px; background: #f3f6f4; border-left: 4px solid #2E7D32; border-radius: 6px; margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between; }
        .kp-phase-header .kp-count { background: rgba(46,125,50,0.15); color: #1b5e20; padding: 2px 8px; border-radius: 10px; font-size: 0.7rem; font-weight: 700; }
        .kp-entry { display: flex; gap: 12px; padding: 12px 14px; background: #fff; border: 1.5px solid #e2efe8; border-radius: 10px; margin-bottom: 8px; transition: all .15s; }
        .kp-entry:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .kp-entry.generated { border-left: 4px solid #2E7D32; }
        .kp-entry.not-generated { border-left: 4px solid #cfd8dc; opacity: 0.9; }
        .kp-entry-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-weight: 800; font-size: 0.9rem; }
        .kp-entry.generated .kp-entry-icon { background: #e8f5e9; color: #1b5e20; }
        .kp-entry.not-generated .kp-entry-icon { background: #f0f0f0; color: #888; }
        .kp-entry-body { flex: 1; min-width: 0; }
        .kp-entry-title { font-weight: 700; font-size: 0.9rem; color: #1a2b22; margin-bottom: 3px; }
        .kp-entry-event { font-size: 0.78rem; color: #555; line-height: 1.45; margin-bottom: 6px; }
        .kp-entry-meta { font-size: 0.72rem; color: #666; display: flex; gap: 14px; flex-wrap: wrap; }
        .kp-entry-meta span { display: inline-flex; align-items: center; gap: 5px; }
        .kp-entry-actions { display: flex; flex-direction: column; gap: 6px; justify-content: center; }
        .kp-entry-btn { padding: 6px 12px; border-radius: 6px; border: none; font-size: 0.72rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
        .kp-entry-btn.open { background: #1565c0; color: #fff; }
        .kp-entry-btn.gen  { background: #2E7D32; color: #fff; }
        .kp-entry-btn.open:hover, .kp-entry-btn.gen:hover { opacity: 0.9; }

        /* Party statements block */
        .pstmt-block { background: #fff8e1; border: 1.5px solid #ffe0b2; border-left: 4px solid #f57c00; border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; }
        .pstmt-title { font-weight: 800; font-size: 0.85rem; color: #b71c1c; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; padding-bottom: 8px; border-bottom: 1px solid #ffe0b2; }
        .pstmt-card { background: #fff; border: 1.5px solid #e2efe8; border-radius: 10px; padding: 12px 14px; margin-bottom: 12px; }
        .pstmt-card:last-child { margin-bottom: 0; }
        .pstmt-card.disabled { background: #f7f7f7; opacity: 0.7; }
        .pstmt-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .pstmt-avatar { width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .pstmt-avatar.complainant { background: #e8f5e9; color: #2E7D32; }
        .pstmt-avatar.respondent  { background: #fff3e0; color: #ef6c00; }
        .pstmt-name { font-weight: 700; font-size: 0.88rem; color: #1a2b22; }
        .pstmt-sub  { font-size: 0.72rem; color: #666; margin-top: 2px; }
        .pstmt-notice { font-size: 0.75rem; color: #b71c1c; background: #ffebee; border-left: 3px solid #c62828; padding: 8px 12px; border-radius: 6px; margin-bottom: 12px; display: flex; gap: 8px; align-items: flex-start; }

        /* Recorder UI */
        .pstmt-rec-wrap { display: flex; align-items: center; gap: 10px; margin-top: 8px; flex-wrap: wrap; }
        .pstmt-rec-btn {
            padding: 8px 16px; border-radius: 22px; border: none; cursor: pointer;
            font-size: 0.8rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;
            transition: all .15s;
        }
        .pstmt-rec-btn.rec  { background: #c62828; color: #fff; }
        .pstmt-rec-btn.rec:hover { background: #b71c1c; }
        .pstmt-rec-btn.stop { background: #546e7a; color: #fff; }
        .pstmt-rec-btn.stop:hover { background: #37474f; }
        .pstmt-rec-btn:disabled { background: #ccc; cursor: not-allowed; }

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

        .pstmt-preview { display: flex; align-items: center; gap: 10px; margin-top: 10px; padding: 10px 12px; background: #f0f7ff; border-left: 4px solid #1565c0; border-radius: 10px; animation: previewIn 0.25s ease-out; }
        @keyframes previewIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
        .pstmt-play-btn { width: 38px; height: 38px; border-radius: 50%; border: none; cursor: pointer; background: #1565c0; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; flex-shrink: 0; transition: all .15s; }
        .pstmt-play-btn:hover { background: #0d47a1; transform: scale(1.05); }
        .pstmt-play-btn.playing { background: #c62828; }

        .pstmt-play-wave { display: flex; align-items: center; gap: 3px; height: 26px; flex: 1; overflow: hidden; }
        .pstmt-play-wave .bar { width: 3px; background: #1565c0; border-radius: 2px; height: 6px; transition: height 0.15s ease; }
        .pstmt-play-wave.playing .bar { animation: playWave 0.8s infinite ease-in-out; }
        .pstmt-play-wave.playing .bar:nth-child(1) { animation-delay: 0.0s; }
        .pstmt-play-wave.playing .bar:nth-child(2) { animation-delay: 0.08s; }
        .pstmt-play-wave.playing .bar:nth-child(3) { animation-delay: 0.16s; }
        .pstmt-play-wave.playing .bar:nth-child(4) { animation-delay: 0.24s; }
        .pstmt-play-wave.playing .bar:nth-child(5) { animation-delay: 0.32s; }
        .pstmt-play-wave.playing .bar:nth-child(6) { animation-delay: 0.40s; }
        .pstmt-play-wave.playing .bar:nth-child(7) { animation-delay: 0.48s; }
        .pstmt-play-wave.playing .bar:nth-child(8) { animation-delay: 0.56s; }
        .pstmt-play-wave.playing .bar:nth-child(9) { animation-delay: 0.64s; }
        .pstmt-play-wave.playing .bar:nth-child(10){ animation-delay: 0.72s; }
        @keyframes playWave { 0%, 100% { transform: scaleY(0.5); opacity: 0.7; } 50% { transform: scaleY(1.8); opacity: 1; } }
        .pstmt-preview-meta { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .pstmt-preview-label { font-size: 0.78rem; font-weight: 700; color: #0d47a1; }
        .pstmt-preview-dur { font-size: 0.72rem; color: #666; }
        .pstmt-remove-btn { width: 32px; height: 32px; border-radius: 50%; border: none; background: #ffebee; color: #c62828; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; flex-shrink: 0; transition: all .15s; }
        .pstmt-remove-btn:hover { background: #c62828; color: #fff; transform: scale(1.05); }
        .pstmt-required-note { font-size: 0.72rem; color: #b71c1c; font-weight: 600; margin-top: 6px; display: flex; align-items: center; gap: 5px; }
        .pstmt-card.err { border-color: #c62828; background: #fff5f5; }
        .pstmt-card.ok  { border-color: #a5d6a7; background: #f8fff8; }

        .dm-header { color: #fff; padding: 18px 22px; display: flex; justify-content: space-between; align-items: center; gap: 14px; }
        .dm-header.orange { background: linear-gradient(135deg, #e65100, #f57c00 55%, #ff9800); }
        .dm-header.green  { background: linear-gradient(135deg, #1b5e20, #2E7D32 55%, #43a047); }
        .dm-header.red    { background: linear-gradient(135deg, #b71c1c, #c62828 55%, #e53935); }
        .dm-header.blue   { background: linear-gradient(135deg, #1565c0, #1976d2 55%, #42a5f5); }
        .dm-header.purple { background: linear-gradient(135deg, #6a1b9a, #8e24aa 55%, #ab47bc); }
        .dm-header.hearing { background: linear-gradient(135deg, #d32f2f, #ff5252); }
        .dm-header-left { display: flex; align-items: center; gap: 14px; }
        .dm-header-left > i { font-size: 1.8rem; opacity: 0.9; }
        .dm-header-title { font-size: 1.15rem; font-weight: 700; }
        .dm-header-sub   { font-size: 0.8rem; opacity: 0.85; margin-top: 2px; }
        .dm-header-ref   { text-align: right; }
        .dm-header-ref-label { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 1px; opacity: 0.8; }
        .dm-header-ref-value { font-size: 0.9rem; font-weight: 700; margin-top: 2px; }
        .dm-section-title { font-weight: 700; font-size: 0.9rem; color: #1a472a; margin: 22px 0 12px; display: flex; align-items: center; gap: 8px; padding-bottom: 6px; border-bottom: 1px solid #e2efe8; }

        .handbook-quote { background: #fff8e1; border: 1.5px solid #ffe0b2; border-left: 4px solid #c62828; border-radius: 10px; padding: 14px 16px; font-size: 0.82rem; color: #4e342e; line-height: 1.6; margin-bottom: 16px; }
        .handbook-quote-title { font-weight: 800; color: #b71c1c; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; font-size: 0.85rem; letter-spacing: 0.3px; text-transform: uppercase; }
        .handbook-quote p { margin: 6px 0; }

        .notice-panel { margin-top: 18px; padding: 16px; background: #f8faf8; border-radius: 12px; border: 1px solid #e2efe8; }
        .notice-panel-title { font-weight: 800; font-size: 0.9rem; color: #1a472a; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; padding-bottom: 8px; border-bottom: 2px solid #e2efe8; }
        .notice-panel-title i { color: #00838f; }
        .notice-item { display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; border-radius: 10px; margin-bottom: 8px; border-left: 4px solid; }
        .notice-item.kp8 { background: #e0f2f1; border-left-color: #00838f; }
        .notice-item.kp9 { background: #f3e5f5; border-left-color: #6a1b9a; }
        .notice-item.kp10 { background: #f3e5f5; border-left-color: #4a148c; }
        .notice-item.kp18 { background: #fff3e0; border-left-color: #e65100; }
        .notice-item.kp19 { background: #fce4ec; border-left-color: #ad1457; }
        .notice-item.print-required { background: #ffebee !important; border-left-color: #c62828 !important; border: 2px solid #ef9a9a; }
        .notice-item-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.95rem; }
        .notice-item-icon.kp8 { background: #b2dfdb; color: #00695c; }
        .notice-item-icon.kp9 { background: #e1bee7; color: #4a148c; }
        .notice-item-icon.kp10 { background: #e1bee7; color: #4a148c; }
        .notice-item-icon.kp18 { background: #ffe0b2; color: #e65100; }
        .notice-item-icon.kp19 { background: #f8bbd0; color: #ad1457; }
        .notice-item-icon.print-required { background: #ffcdd2; color: #b71c1c; }
        .notice-item-body { flex: 1; min-width: 0; }
        .notice-item-title { font-weight: 700; font-size: 0.85rem; color: #1a2b22; }
        .notice-item-meta { font-size: 0.72rem; color: #666; margin-top: 3px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .notice-item-notified { padding: 2px 8px; border-radius: 10px; font-size: 0.62rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
        .notice-item-notified.yes { background: #e8f5e9; color: #1b5e20; }
        .notice-item-notified.no  { background: #c62828; color: #fff; animation: pulseBadge 1.5s infinite; }
        @keyframes pulseBadge { 0%,100% { box-shadow: 0 0 0 0 rgba(198,40,40,0.4); } 50% { box-shadow: 0 0 0 6px rgba(198,40,40,0); } }
        .notice-item-btn { margin-top: 8px; padding: 6px 12px; border: none; border-radius: 6px; font-size: 0.75rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; color: #fff; }
        .notice-item-btn.kp8 { background: #00838f; }
        .notice-item-btn.kp9 { background: #6a1b9a; }
        .notice-item-btn.kp10 { background: #4a148c; }
        .notice-item-btn.kp18 { background: #e65100; }
        .notice-item-btn.kp19 { background: #ad1457; }
        .notice-item-btn:hover { opacity: 0.9; }

        .waiting-card { padding: 22px; background:#f3e5f5; border-left: 5px solid #6a1b9a; border-radius: 12px; text-align: center; margin-bottom: 16px; }
        .waiting-card .wc-icon { font-size: 2.4rem; color: #6a1b9a; margin-bottom: 12px; }
        .waiting-card .wc-title { font-weight: 800; color: #4a148c; font-size: 1.05rem; margin-bottom: 6px; }
        .waiting-card .wc-date  { font-weight: 700; color: #6a1b9a; font-size: 1rem; }
        .waiting-card .wc-sub   { font-size: 0.85rem; color: #666; margin-top: 10px; line-height: 1.5; }

        /* Attendance cards */
        .att-card { background: #fafbfa; border: 1.5px solid #e2efe8; border-radius: 12px; padding: 12px 14px; margin-bottom: 10px; transition: all .18s; }
        .att-card.attend  { border-color:#a5d6a7; background:#f1f8e9; }
        .att-card.no_show { border-color:#ef9a9a; background:#ffebee; }
        .att-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .att-avatar { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.9rem; }
        .att-avatar.complainant { background:#e8f5e9; color:#2E7D32; }
        .att-avatar.respondent  { background:#fff3e0; color:#ef6c00; }
        .att-avatar.witness     { background:#e3f2fd; color:#1565c0; }
        .att-meta { flex: 1; min-width: 0; }
        .att-name { font-weight: 700; font-size: 0.9rem; color:#1a2b22; line-height: 1.25; }
        .att-role { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 700; margin-top: 2px; }
        .att-role.complainant { color:#2E7D32; }
        .att-role.respondent  { color:#ef6c00; }
        .att-role.witness     { color:#1565c0; }
        .att-btn-row { display: flex; gap: 8px; flex-wrap: wrap; }
        .att-btn { flex: 1; min-width: 90px; padding: 8px 10px; border-radius: 8px; border: 1.5px solid #cfd8dc; background: #fff; font-size: 0.78rem; font-weight: 600; color: #555; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all .15s; }
        .att-btn:hover:not(:disabled) { transform: translateY(-1px); }
        .att-btn:disabled { cursor: default; }
        .att-btn.attend.active  { background:#2E7D32; border-color:#2E7D32; color:#fff; }
        .att-btn.no_show.active { background:#c62828; border-color:#c62828; color:#fff; }
        .att-status-pill { font-size: 0.65rem; padding: 3px 9px; border-radius: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap; }
        .att-status-pill.attend  { background:#c8e6c9; color:#1b5e20; }
        .att-status-pill.no_show { background:#ffcdd2; color:#b71c1c; }
        .att-status-pill.pending { background:#e0e0e0; color:#666; }

        .abs-radio { position: relative; display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border: 2px solid #e2efe8; border-radius: 10px; background: #fff; cursor: pointer; transition: all .15s; }
        .abs-radio:hover { border-color: #a5d6a7; }
        .abs-radio.selected.justified   { border-color:#2E7D32; background:#f1f8e9; }
        .abs-radio.selected.unjustified { border-color:#c62828; background:#ffebee; }

        .swal-session-footer { display: flex; gap: 10px; justify-content: space-between; padding: 14px 22px; background: #f8faf8; border-top: 1px solid #e2efe8; flex-wrap: wrap; }
        .ses-btn { padding: 10px 20px; border-radius: 8px; font-size: 0.88rem; font-weight: 700; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all .15s; }
        .ses-btn:hover { transform: translateY(-1px); }
        .ses-btn:disabled { cursor: not-allowed; transform: none; opacity: 0.6; }
        .ses-btn.close   { background: #e0e0e0; color: #555; }
        .ses-btn.close:hover { background: #bdbdbd; color:#333; }
        .ses-btn.primary { background: #2E7D32; color: #fff; }
        .ses-btn.primary.warn { background:#e65100; }
        .ses-btn.primary.danger { background:#c62828; }
        .ses-btn.secondary { background: #546e7a; color: #fff; }

        /* ============================================================
           HEARING PANEL (chair is locked; secretary & member may leave)
           ============================================================ */
        #hearingPanelOverlay {
            position: fixed;
            inset: 0;
            z-index: 99999;
            background: #f1f5f3;
            overflow-y: auto;
            animation: lockIn .25s ease-out;
        }
        @keyframes lockIn { from { opacity: 0; } to { opacity: 1; } }

        .lock-shell {
            max-width: 940px;
            margin: 24px auto 40px;
            padding: 0 16px;
        }
        .lock-header {
            background: linear-gradient(135deg, #b71c1c, #d32f2f 60%, #e53935);
            color: #fff;
            border-radius: 14px 14px 0 0;
            padding: 18px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            box-shadow: 0 4px 14px rgba(183,28,28,0.3);
        }
        .lock-header-left { display: flex; align-items: center; gap: 12px; }
        .lock-hearing-dot {
            width: 12px; height: 12px; border-radius: 50%; background: #fff;
            box-shadow: 0 0 0 0 rgba(255,255,255,0.9);
            animation: lockPulse 1.5s infinite;
            flex-shrink: 0;
        }
        @keyframes lockPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(255,255,255,0.9); }
            50%      { box-shadow: 0 0 0 10px rgba(255,255,255,0); }
        }
        .lock-header-title { font-size: 1.05rem; font-weight: 800; letter-spacing: 0.4px; }
        .lock-header-sub { font-size: 0.8rem; opacity: 0.9; margin-top: 3px; }
        .lock-header-right { display: flex; flex-wrap: wrap; gap: 12px; font-size: 0.8rem; opacity: 0.95; }
        .lock-meta-item { display: inline-flex; align-items: center; gap: 6px; }

        .lock-body {
            background: #fff;
            padding: 22px 26px;
            border: 1px solid #e2efe8;
            border-top: none;
        }
        .lock-banner {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.86rem;
            line-height: 1.55;
            margin-bottom: 18px;
        }
        .lock-banner.chair     { background: #f3e5f5; border-left: 4px solid #6a1b9a; color: #4a148c; }
        .lock-banner.secretary { background: #e3f2fd; border-left: 4px solid #1565c0; color: #0d47a1; }
        .lock-banner.member    { background: #fff3e0; border-left: 4px solid #e65100; color: #bf360c; }

        .lock-block {
            background: #fff;
            border-radius: 12px;
            padding: 16px 20px;
            border: 1px solid #e2efe8;
            margin-bottom: 16px;
        }
        .lock-block.lock-alert { background: #fff5f5; border-color: #ef9a9a; }
        .lock-block-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.92rem;
            font-weight: 800;
            color: #1a472a;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2efe8;
        }
        .lock-block-body { padding: 2px 0; }

        .lock-case-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px 18px;
            margin-bottom: 14px;
        }
        .lock-case-item { display: flex; flex-direction: column; }
        .lock-case-item label { font-size: 0.68rem; text-transform: uppercase; color: #999; margin-bottom: 3px; letter-spacing: 0.4px; font-weight: 600; }
        .lock-case-item span { font-size: 0.9rem; color: #1a2b22; font-weight: 700; }
        .lock-case-desc { margin-bottom: 14px; }
        .lock-case-desc label { display: block; font-size: 0.68rem; text-transform: uppercase; color: #999; margin-bottom: 4px; letter-spacing: 0.4px; font-weight: 600; }
        .lock-case-desc div { font-size: 0.9rem; color: #1a2b22; line-height: 1.55; }

        .lock-subblock { margin-top: 14px; padding-top: 12px; border-top: 1px dashed #e2efe8; }
        .lock-subblock-title { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 700; color: #2E7D32; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }

        .lock-person {
            display: flex; gap: 12px; align-items: flex-start;
            padding: 10px 12px; border-radius: 10px;
            background: #fafbfa; border: 1px solid #f0f0f0;
            margin-bottom: 8px;
        }
        .lock-person:last-child { margin-bottom: 0; }
        .lock-person-avatar {
            width: 36px; height: 36px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .lock-person-avatar.complainant { background: #e8f5e9; color: #2E7D32; }
        .lock-person-avatar.respondent  { background: #fff3e0; color: #ef6c00; }
        .lock-person-avatar.witness     { background: #e3f2fd; color: #1565c0; }
        .lock-person-name { font-weight: 700; color: #1a1a1a; font-size: 0.9rem; }
        .lock-person-meta {
            font-size: 0.75rem; color: #555; margin-top: 4px;
            display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
        }

        .lock-stmt-group {
            background: #fafbfa;
            border: 1px solid #e2efe8;
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 10px;
        }
        .lock-stmt-group:last-child { margin-bottom: 0; }
        .lock-stmt-header {
            font-size: 0.78rem;
            font-weight: 800;
            color: #1a472a;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 10px;
            padding-bottom: 6px;
            border-bottom: 1px dashed #d0d7de;
        }
        .lock-stmt {
            background: #fff;
            border-left: 4px solid #94a3b8;
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 8px;
        }
        .lock-stmt:last-child { margin-bottom: 0; }
        .lock-stmt.complainant { border-left-color: #2E7D32; }
        .lock-stmt.respondent  { border-left-color: #ef6c00; }
        .lock-stmt-title { font-weight: 700; font-size: 0.82rem; color: #1a2b22; margin-bottom: 5px; }
        .lock-stmt-text { font-size: 0.85rem; color: #333; line-height: 1.55; }

        .lock-evidence-row {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; background: #fafbfa;
            border: 1px solid #e2efe8; border-radius: 8px;
            margin-bottom: 6px;
        }
        .lock-evidence-row:last-child { margin-bottom: 0; }
        .lock-evidence-icon {
            width: 32px; height: 32px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            background: #e3f2fd; color: #1565c0; flex-shrink: 0;
        }
        .lock-evidence-name { font-weight: 700; color: #1a2b22; font-size: 0.85rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .lock-evidence-meta { font-size: 0.72rem; color: #888; margin-top: 2px; display: flex; gap: 10px; flex-wrap: wrap; }
        .lock-evidence-desc { font-size: 0.75rem; color: #555; margin-top: 3px; }
        .lock-evidence-open {
            background: #1565c0; color: #fff; border: none;
            padding: 6px 12px; border-radius: 6px;
            font-size: 0.72rem; font-weight: 700; cursor: pointer;
            display: inline-flex; align-items: center; gap: 5px;
        }
        .lock-evidence-open:hover { background: #0d47a1; }

        .lock-note { font-size: 0.82rem; color: #666; line-height: 1.5; }

        .lock-att-ro-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 12px; background: #fafbfa;
            border: 1px solid #e2efe8; border-radius: 8px;
            margin-bottom: 6px;
        }
        .lock-att-ro-row:last-child { margin-bottom: 0; }
        .lock-att-ro-name { font-weight: 700; color: #1a2b22; font-size: 0.88rem; display: flex; align-items: center; gap: 8px; }
        .lock-att-ro-role { font-size: 0.68rem; text-transform: uppercase; color: #666; font-weight: 600; letter-spacing: 0.3px; }

        .lock-footer {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 22px;
            background: #f8faf8;
            border: 1px solid #e2efe8;
            border-top: none;
            border-radius: 0 0 14px 14px;
            flex-wrap: wrap;
        }
        .lock-end-btn {
            background: linear-gradient(135deg, #b71c1c, #c62828);
            color: #fff;
            border: none;
            padding: 12px 22px;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(183,28,28,0.35);
            transition: all .15s;
            margin-left: auto;
        }
        .lock-end-btn:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(183,28,28,0.5); }
        .lock-end-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .lock-save-btn {
            background: #1565c0;
            color: #fff;
            border: none;
            padding: 12px 22px;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all .15s;
        }
        .lock-save-btn:hover { background: #0d47a1; }
        .lock-waiting {
            margin-left: auto;
            padding: 12px 18px;
            background: #f3e5f5;
            border-radius: 10px;
            color: #4a148c;
            font-weight: 700;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .lock-close-btn {
            background: #e0e0e0;
            color: #333;
            border: none;
            padding: 12px 22px;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all .15s;
        }
        .lock-close-btn:hover { background: #bdbdbd; }

        @media (max-width: 768px) {
            .documents-grid { grid-template-columns: 1fr; }
            .document-card-actions { flex-direction: column; }
            .doc-action-btn { width: 100%; }
            .notif-dropdown { width: 300px; }
            .cd-body { padding: 16px; }
            .cd-actions { flex-direction: column-reverse; }
            .cd-actions .cd-btn { width: 100%; justify-content: center; }
            .dm-grid-2 { grid-template-columns: 1fr; }
        }
        @media (max-width: 720px) {
            .lock-header { flex-direction: column; align-items: flex-start; }
            .lock-footer { flex-direction: column-reverse; }
            .lock-end-btn, .lock-save-btn, .lock-waiting, .lock-close-btn { width: 100%; justify-content: center; margin-left: 0; }
        }
    </style>
</head>
<body>
<button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
<div class="sidebar-overlay hide" id="sidebarOverlay"></div>

<div class="dashboard-container">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="brand"><div class="logo-container"><img src="../logo.jpg" alt="Barangay Logo" onerror="this.onerror=null; this.parentElement.innerHTML='<i class=\'fas fa-landmark\'></i>';"></div><h2>BRITE</h2></div>
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
function ucwords(str) { return String(str || '').replace(/\b\w/g, l => l.toUpperCase()); }

const profileArea = document.getElementById('profileArea');
const profileDropdown = document.getElementById('profileDropdown');
const logoutBtn = document.getElementById('logoutBtn');
profileArea.addEventListener('click', (e) => { e.stopPropagation(); profileDropdown.classList.toggle('show'); });
document.addEventListener('click', (e) => { if (!profileArea.contains(e.target)) profileDropdown.classList.remove('show'); });
logoutBtn.addEventListener('click', (e) => {
    e.preventDefault();
    if (window.__hearingLock && window.__hearingLock.active && window.__hearingLock.myRole === 'chair') {
        Swal.fire({
            icon: 'warning',
            title: 'Hearing is Active',
            text: 'You cannot log out until you end the hearing or resolve the absences.',
            confirmButtonColor: '#2E7D32'
        });
        return;
    }
    if (confirm('Logout?')) document.getElementById('logoutForm').submit();
});

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
// PREVIOUS HEARINGS BLOCK
// ============================================================
function renderPreviousHearingsBlock(hearings, opts) {
    if (!Array.isArray(hearings) || !hearings.length) return '';
    const kind = (opts && opts.kind) || 'conciliation';
    const label = kind === 'mediation' ? 'Previous Mediation Hearings' : 'Previous Conciliation Hearings';
    const blockId = kind === 'mediation' ? 'prevMediationHearingsBlock' : 'prevConcilHearingsBlock';
    const toneClass = kind === 'mediation' ? 'mediation-tone' : '';
    const itemToneClass = kind === 'mediation' ? 'mediation-tone' : '';

    const items = hearings.map(h => {
        const niceDate = h.hearing_date
            ? new Date(h.hearing_date).toLocaleDateString('en-US', {dateStyle:'long'})
            : '—';
        const niceTime = h.hearing_time || '';
        let summaryLine = '';
        if (h.session_record && h.session_record.summary) {
            summaryLine = `<div class="prev-hearing-summary">${escapeHtml(h.session_record.summary).replace(/\n/g,'<br>')}</div>`;
        } else if (h.summary) {
            summaryLine = `<div class="prev-hearing-summary">${escapeHtml(h.summary).replace(/\n/g,'<br>')}</div>`;
        }
        const outcomeLbl = h.outcome ? String(h.outcome).replace(/_/g,' ').toUpperCase() : '—';
        const numLabel = (kind === 'mediation') ? 'Mediation Hearing' : 'Conciliation Hearing';
        return `
            <div class="prev-hearing-item ${itemToneClass}" onclick="openHearingDetail(${parseInt(h.id)})" title="Click to view full details">
                <div class="prev-hearing-head">
                    <span>${numLabel} #${parseInt(h.session_number || 1)} — ${escapeHtml(outcomeLbl)}</span>
                    <button type="button" class="prev-hearing-view-btn" onclick="event.stopPropagation(); openHearingDetail(${parseInt(h.id)})">
                        <i class="fas fa-eye"></i> View
                    </button>
                </div>
                <div class="prev-hearing-meta">
                    <span><i class="fas fa-calendar"></i> ${escapeHtml(niceDate)} ${escapeHtml(niceTime)}</span>
                    <span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(h.location || 'Barangay Hall')}</span>
                </div>
                ${summaryLine}
            </div>`;
    }).join('');

    return `
        <div class="prev-hearings-block ${toneClass}" id="${blockId}">
            <div class="prev-hearings-header" onclick="togglePrevHearingsBlock('${blockId}')">
                <span><i class="fas fa-history"></i> ${label} (${hearings.length})</span>
                <i class="fas fa-chevron-down toggle-icon"></i>
            </div>
            <div class="prev-hearings-body">
                ${items}
            </div>
        </div>`;
}
function togglePrevHearingsBlock(blockId) {
    const el = document.getElementById(blockId);
    if (el) el.classList.toggle('expanded');
}

// ============================================================
// HEARING DETAIL MODAL
// ============================================================
async function openHearingDetail(hearingId) {
    if (!hearingId) return;
    Swal.fire({ title: 'Loading hearing…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    let d;
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_hearing_details&hearing_id=${hearingId}`);
        d = await r.json();
    } catch (e) {
        Swal.close();
        Swal.fire('Error', 'Could not load hearing.', 'error');
        return;
    }
    Swal.close();
    if (!d || !d.success) { Swal.fire('Error', d.message || 'Could not load hearing.', 'error'); return; }

    const h = d.hearing;
    const attendance = d.attendance || [];
    const parties = d.parties || [];
    const cs = d.complainant_statement || null;
    const rs = d.respondent_statement || null;

    const isMediation   = h.session_type === 'mediation';
    const isConciliation= h.session_type === 'conciliation';
    const hdHeaderCls   = isMediation ? 'mediation' : (isConciliation ? 'conciliation' : '');

    const niceDate = h.hearing_date ? new Date(h.hearing_date).toLocaleDateString('en-US', {dateStyle:'full'}) : '—';
    const niceTime = h.hearing_time || '—';
    const statusLbl = (h.status || '').replace(/_/g,' ').toUpperCase();
    const outcomeLbl = h.outcome ? String(h.outcome).replace(/_/g,' ').toUpperCase() : '—';
    const typeLbl = isMediation ? 'Mediation Hearing' : (isConciliation ? 'Conciliation Hearing' : 'Hearing');

    let sessionRecordHtml = '';
    if (h.session_record && (h.session_record.summary || h.session_record.recorded_by_role)) {
        const sr = h.session_record;
        sessionRecordHtml = `
            <div class="hd-block">
                <div class="hd-block-title"><i class="fas fa-clipboard-list"></i> Hearing Record</div>
                ${sr.summary ? `<div class="hd-summary">${escapeHtml(sr.summary).replace(/\n/g,'<br>')}</div>` : '<div class="hd-note">No written summary.</div>'}
                <div class="hd-note" style="margin-top:8px;">
                    ${sr.recorded_by_role ? `Recorded by <b>${escapeHtml(String(sr.recorded_by_role).toUpperCase())}</b>.` : ''}
                    ${sr.recorded_at ? ` On ${new Date(sr.recorded_at.replace(' ','T')).toLocaleString('en-US', {dateStyle:'medium', timeStyle:'short'})}.` : ''}
                </div>
            </div>`;
    } else if (h.summary) {
        sessionRecordHtml = `
            <div class="hd-block">
                <div class="hd-block-title"><i class="fas fa-clipboard-list"></i> Hearing Record</div>
                <div class="hd-summary">${escapeHtml(h.summary).replace(/\n/g,'<br>')}</div>
            </div>`;
    } else {
        sessionRecordHtml = `
            <div class="hd-block">
                <div class="hd-block-title"><i class="fas fa-clipboard-list"></i> Hearing Record</div>
                <div class="hd-note">No written record for this hearing yet.</div>
            </div>`;
    }

    let hearingKpHtml = '';
    const hearingKpNum = isMediation ? 8 : (isConciliation ? 12 : null);
    if (hearingKpNum) {
        const kpData = isMediation
            ? [{ label: 'KP Form #8 — Notice of Hearing (Complainant)', pdf: h.kp8?.pdf_url, at: h.kp8?.issued_at, notified: h.kp8?.notified },
               { label: 'KP Form #9 — Summons (Respondent)',          pdf: h.kp9?.pdf_url, at: h.kp9?.issued_at, notified: h.kp9?.notified }]
            : [{ label: 'KP Form #12 — Notice of Hearing (Complainant)', pdf: h.kp12?.complainant?.pdf_url, at: h.kp12?.complainant?.issued_at, notified: h.kp12?.complainant?.notified },
               { label: 'KP Form #12 — Notice of Hearing (Respondent)',  pdf: h.kp12?.respondent?.pdf_url,  at: h.kp12?.respondent?.issued_at,  notified: h.kp12?.respondent?.notified }];

        const rows = kpData.map(k => {
            if (!k.pdf) return '';
            const issuedNice = k.at ? new Date(k.at.replace(' ','T')).toLocaleString('en-US', {dateStyle:'medium', timeStyle:'short'}) : '—';
            const badge = k.notified
                ? `<span class="hd-att-pill attend"><i class="fas fa-check"></i> Notified</span>`
                : `<span class="hd-att-pill no_show"><i class="fas fa-print"></i> Print</span>`;
            return `<div style="background:#fafbfa;border-left:4px solid #1565c0;border-radius:8px;padding:10px 14px;margin-bottom:8px;">
                <div style="font-weight:700;font-size:0.85rem;color:#1a2b22;display:flex;justify-content:space-between;align-items:center;gap:8px;">
                    <span>${escapeHtml(k.label)}</span> ${badge}
                </div>
                <div style="font-size:0.75rem;color:#666;margin-top:4px;">Issued: ${escapeHtml(issuedNice)}</div>
                <a href="../../${escapeHtml(k.pdf)}" target="_blank" style="display:inline-block;margin-top:6px;padding:5px 12px;background:#1565c0;color:#fff;border-radius:6px;text-decoration:none;font-size:0.72rem;font-weight:700;"><i class="fas fa-file-pdf"></i> Open PDF</a>
            </div>`;
        }).join('');

        if (rows) {
            hearingKpHtml = `<div class="hd-block">
                <div class="hd-block-title"><i class="fas fa-file-signature"></i> KP Forms Issued for This Hearing</div>
                ${rows}
            </div>`;
        }
    }

    let statementsHtml = '';
    if ((cs && (cs.text || cs.audio_path)) || (rs && (rs.text || rs.audio_path))) {
        const renderStatement = (label, obj, toneClass) => {
            if (!obj || (!obj.text && !obj.audio_path)) return '';
            const audio = obj.audio_path
                ? `<audio controls style="width:100%;margin-top:6px;"><source src="../../${escapeHtml(obj.audio_path)}"></audio>` : '';
            return `
                <div style="background:#fafbfa;border-left:4px solid ${toneClass === 'complainant' ? '#2E7D32' : '#ef6c00'};border-radius:8px;padding:10px 14px;margin-bottom:8px;">
                    <div style="font-weight:700;color:#1a2b22;font-size:0.85rem;">${escapeHtml(label)}</div>
                    ${obj.text ? `<div style="margin-top:6px;font-size:0.84rem;line-height:1.55;">${escapeHtml(obj.text).replace(/\n/g,'<br>')}</div>` : ''}
                    ${audio}
                </div>`;
        };
        statementsHtml = `
            <div class="hd-block">
                <div class="hd-block-title"><i class="fas fa-microphone"></i> Party Statements</div>
                ${renderStatement('Complainant\'s Statement', cs, 'complainant')}
                ${renderStatement('Respondent\'s Statement', rs, 'respondent')}
            </div>`;
    }

    const mergeAttendance = () => {
        const byPid = {};
        attendance.forEach(a => { byPid[Number(a.party_id)] = a; });
        return parties.map(p => {
            const rec = byPid[Number(p.id)] || {};
            return {
                party_id: p.id, party_type: p.party_type, full_name: p.full_name,
                status: rec.status || 'pending',
                justification_status: rec.justification_status || null,
                appearance_notes: rec.appearance_notes || '',
                kp18_or_19_pdf_path: rec.kp18_or_19_pdf_path || null,
            };
        });
    };
    const rows = mergeAttendance();

    const attHtml = rows.length ? `
        <div class="hd-block">
            <div class="hd-block-title"><i class="fas fa-users"></i> Attendance (${rows.length})</div>
            <table class="hd-att-table">
                <thead>
                    <tr><th>Party</th><th>Role</th><th>Status</th><th>Decision</th><th>Notes</th><th>KP</th></tr>
                </thead>
                <tbody>
                    ${rows.map(r => {
                        const roleLabel = (r.party_type || '').charAt(0).toUpperCase() + (r.party_type || '').slice(1);
                        const statusPill = r.status === 'no_show'
                            ? '<span class="hd-att-pill no_show"><i class="fas fa-times"></i> No Show</span>'
                            : (r.status === 'attend'
                                ? '<span class="hd-att-pill attend"><i class="fas fa-check"></i> Attend</span>'
                                : '<span class="hd-att-pill pending">Pending</span>');
                        const justifPill = r.justification_status
                            ? `<span class="hd-justif-pill ${r.justification_status}">${escapeHtml(r.justification_status.toUpperCase())}</span>`
                            : '—';
                        const kpBtn = r.kp18_or_19_pdf_path
                            ? `<button type="button" class="prev-hearing-view-btn" style="margin-top:0;padding:4px 10px;font-size:0.68rem;" onclick="openKpFailureNoticePdf(${h.complaint_id}, ${h.id}, ${r.party_id})"><i class="fas fa-file-pdf"></i> KP</button>`
                            : '—';
                        return `
                            <tr>
                                <td><b>${escapeHtml(r.full_name || '—')}</b></td>
                                <td>${escapeHtml(roleLabel)}</td>
                                <td>${statusPill}</td>
                                <td>${justifPill}</td>
                                <td style="font-size:0.78rem;color:#555;">${escapeHtml(r.appearance_notes || '—')}</td>
                                <td>${kpBtn}</td>
                            </tr>`;
                    }).join('')}
                </tbody>
            </table>
        </div>` : '';

    await Swal.fire({
        title: '',
        html: `
        <div class="hd-modal">
            <div class="hd-header ${hdHeaderCls}">
                <div class="hd-header-left">
                    <i class="fas fa-${isMediation ? 'gavel' : (isConciliation ? 'balance-scale' : 'calendar-alt')}"></i>
                    <div>
                        <div class="hd-title">${typeLbl} #${parseInt(h.session_number || 1)}</div>
                        <div class="hd-sub">Ref: <b>${escapeHtml(h.reference_number)}</b> · ${escapeHtml(h.title || '')}</div>
                    </div>
                </div>
            </div>
            <div class="hd-body">
                <div class="hd-block">
                    <div class="hd-block-title"><i class="fas fa-info-circle"></i> Hearing Info</div>
                    <div class="hd-grid">
                        <div class="hd-grid-item"><label>Date</label><span>${escapeHtml(niceDate)}</span></div>
                        <div class="hd-grid-item"><label>Time</label><span>${escapeHtml(niceTime)}</span></div>
                        <div class="hd-grid-item"><label>Location</label><span>${escapeHtml(h.location || 'Barangay Hall')}</span></div>
                        <div class="hd-grid-item"><label>Status</label><span>${escapeHtml(statusLbl)}</span></div>
                        <div class="hd-grid-item"><label>Outcome</label><span>${escapeHtml(outcomeLbl)}</span></div>
                        <div class="hd-grid-item"><label>Conducted By</label><span>${escapeHtml(h.conducted_by_name || '—')}</span></div>
                    </div>
                </div>
                ${sessionRecordHtml}
                ${hearingKpHtml}
                ${statementsHtml}
                ${attHtml}
            </div>
        </div>`,
        width: 900,
        showConfirmButton: true,
        confirmButtonText: 'Close',
        confirmButtonColor: '#1565c0',
        customClass: { popup: 'cd-popup' }
    });
}

// ============================================================
// DASHBOARD
// ============================================================
function renderDashboard() {
    setActiveNav('dashboard');
    const body = document.getElementById('dashboardBody');
    body.innerHTML = `
        <div class="modern-dashboard">
            <div class="welcome-card">
                <div class="welcome-content">
                    <div class="welcome-text"><h2>Lupon Tagapamayapa Dashboard</h2><p>Manage cases, schedule hearings, and facilitate settlements.</p></div>
                    <div class="welcome-date"><i class="fas fa-calendar-alt"></i><span id="currentDate"></span></div>
                </div>
            </div>
            <div class="stats-grid-modern">
                <div class="stat-card-modern"><div class="stat-icon pending"><i class="fas fa-clock"></i></div><div class="stat-info"><h3><?php echo $stats['scheduled']; ?></h3><p>Scheduled</p></div></div>
                <div class="stat-card-modern"><div class="stat-icon mediation"><i class="fas fa-handshake"></i></div><div class="stat-info"><h3><?php echo $stats['total']; ?></h3><p>Total Assigned</p></div></div>
                <div class="stat-card-modern"><div class="stat-icon settled"><i class="fas fa-check-circle"></i></div><div class="stat-info"><h3><?php echo $stats['settled']; ?></h3><p>Settled</p></div></div>
                <div class="stat-card-modern"><div class="stat-icon failed"><i class="fas fa-times-circle"></i></div><div class="stat-info"><h3><?php echo $stats['failed']; ?></h3><p>Conciliation Failed</p></div></div>
                <?php if ($stats['needs_assign'] > 0): ?>
                    <div class="stat-card-modern"><div class="stat-icon assign"><i class="fas fa-user-tag"></i></div><div class="stat-info"><h3><?php echo $stats['needs_assign']; ?></h3><p>Position to Assign</p></div></div>
                <?php endif; ?>
            </div>
            <div class="quick-actions">
                <h4><i class="fas fa-bolt"></i> Quick Actions</h4>
                <div class="action-buttons">
                    <button class="quick-action-btn" onclick="renderMyComplaints()"><i class="fas fa-gavel"></i> My Complaints</button>
                    <button class="quick-action-btn" onclick="window.location.href='hearings.php'"><i class="fas fa-calendar-alt"></i> Hearings</button>
                    <button class="quick-action-btn" onclick="window.location.href='mediation_report.php'"><i class="fas fa-chart-bar"></i> Mediation Report</button>
                </div>
            </div>
        </div>
    `;
    document.getElementById('currentDate').innerHTML = new Date().toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
}

// ============================================================
// MY COMPLAINTS
// ============================================================
let cachedCases = [];

async function renderMyComplaints() {
    setActiveNav('complaints');
    const body = document.getElementById('dashboardBody');
    body.innerHTML = `
        <div class="content-card" style="background:#fff;border-radius:16px;padding:25px;">
            <h3 style="margin:0 0 8px;"><i class="fas fa-gavel"></i> My Complaints</h3>
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
    const list = cachedCases;

    if (!list.length) {
        c.innerHTML = '<div style="text-align:center;padding:60px;color:#999;"><i class="fas fa-inbox fa-3x" style="color:#c8e6c9;"></i><p style="margin-top:12px;">No complaints to show yet.</p></div>';
        return;
    }

    c.innerHTML = `<div class="documents-grid">${list.map(x => renderCaseCard(x)).join('')}</div>`;
    startLiveCountdowns();
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

    const concilStartedAt = x.concil_hearing_started_at || null;
    const hearingStarted = !!concilStartedAt;

    const waitingForAppearance = !!x.concil_absence_waiting_since && !x.concil_absence_appearance_at;
    const hasAppearanceSet = !!x.concil_absence_appearance_at;
    const appearancePassed = hasAppearanceSet && new Date(x.concil_absence_appearance_at.replace(' ','T')).getTime() <= Date.now();

    const isHearingInProgress = hearingStarted && x.status === 'pangkat_scheduled' && !waitingForAppearance && !hasAppearanceSet;

    let headerCls = 'lupon';
    let statusLbl = (x.status || '').replace(/_/g,' ').toUpperCase();
    let statusIcon = 'fa-file-alt';

    if (needsAssign) {
        headerCls = 'assign';
        statusLbl = 'ASSIGN POSITIONS';
        statusIcon = 'fa-user-tag';
    } else if (isHearingInProgress) {
        headerCls = 'inprogress';
        statusLbl = 'HEARING IN PROGRESS';
        statusIcon = 'fa-play-circle';
    } else if (waitingForAppearance) {
        headerCls = 'waiting';
        statusLbl = 'NO-SHOW — SET APPEARANCE';
        statusIcon = 'fa-user-clock';
    } else if (hasAppearanceSet && !appearancePassed) {
        headerCls = 'waiting';
        statusLbl = 'AWAITING APPEARANCE';
        statusIcon = 'fa-user-clock';
    } else if (hasAppearanceSet && appearancePassed) {
        headerCls = 'assign';
        statusLbl = 'DECIDE ON ABSENCES';
        statusIcon = 'fa-gavel';
    } else if (x.status === 'pangkat_scheduled' && hearingStarted) {
        headerCls = 'start';
        statusLbl = 'HEARING IN PROGRESS';
        statusIcon = 'fa-play-circle';
    } else if (x.status === 'pangkat_scheduled') {
        headerCls = 'mediation';
        statusLbl = 'SCHEDULED';
        statusIcon = 'fa-calendar-check';
    } else if (x.status === 'settled') {
        headerCls = 'settled';
        statusIcon = 'fa-check-circle';
    } else if (x.status === 'failed_conciliation_final' || x.status === 'failed_mediation') {
        headerCls = 'failed';
        statusIcon = 'fa-times-circle';
    } else if (x.status === 'pangkat_constituted') {
        headerCls = 'pending';
        statusIcon = 'fa-users-cog';
    }

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
    if (isPangkat && isPending && !isFirst) {
        waitingMsg = `<div style="background:#fff3e0;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#e65100;margin-top:4px;">
              <i class="fas fa-hourglass-half"></i> Waiting for the first-selected member to assign positions.
          </div>`;
    } else if (isPangkat && myRole === 'chair' && x.status === 'pangkat_constituted') {
        waitingMsg = `<div style="background:#f3e5f5;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#4a148c;margin-top:4px;">
              <i class="fas fa-calendar-plus"></i> <b>You are the Chairperson.</b> Schedule the conciliation hearing.
          </div>`;
    } else if (isHearingInProgress && myRole === 'chair') {
        waitingMsg = `<div style="background:#ffebee;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#b71c1c;margin-top:4px;">
              <i class="fas fa-play-circle"></i> <b>Hearing in progress.</b> Click "Open Hearing" to lead the session. You must resolve the hearing before you can leave.
          </div>`;
    } else if (isHearingInProgress && myRole === 'secretary') {
        waitingMsg = `<div style="background:#e3f2fd;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#0d47a1;margin-top:4px;">
              <i class="fas fa-clipboard-list"></i> <b>Hearing in progress.</b> Click "Open Hearing" to record attendance. You may leave anytime.
          </div>`;
    } else if (isHearingInProgress && myRole === 'member') {
        waitingMsg = `<div style="background:#fff3e0;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#bf360c;margin-top:4px;">
              <i class="fas fa-users"></i> <b>Hearing in progress.</b> Click "Open Hearing" to view the case. You may leave anytime.
          </div>`;
    } else if (isPangkat && myRole === 'chair' && x.status === 'pangkat_scheduled' && !hearingStarted) {
        waitingMsg = `<div style="background:#e0f7fa;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#00838f;margin-top:4px;">
              <i class="fas fa-play-circle"></i> <b>Ready to start?</b> Click "Start Hearing" when everyone is present.
          </div>`;
    } else if (isPangkat && myRole === 'chair' && waitingForAppearance) {
        waitingMsg = `<div style="background:#ffebee;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#b71c1c;margin-top:4px;">
              <i class="fas fa-user-clock"></i> <b>Action Required:</b> Set an appearance date for the absent party.
          </div>`;
    } else if (isPangkat && myRole === 'chair' && hasAppearanceSet && appearancePassed) {
        waitingMsg = `<div style="background:#f3e5f5;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#4a148c;margin-top:4px;">
              <i class="fas fa-gavel"></i> <b>Decide:</b> Was the absence justified or unjustified?
          </div>`;
    } else if (isPangkat && myRole === 'secretary') {
        if (waitingForAppearance) {
            waitingMsg = `<div style="background:#fff3e0;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#e65100;margin-top:4px;">
                  <i class="fas fa-user-clock"></i> Waiting for the Chairperson to set the appearance date.
              </div>`;
        } else if (!hearingStarted && x.status === 'pangkat_scheduled') {
            waitingMsg = `<div style="background:#e3f2fd;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#0d47a1;margin-top:4px;">
                  <i class="fas fa-hourglass-half"></i> Waiting for the Chairperson to start the hearing.
              </div>`;
        } else {
            waitingMsg = `<div style="background:#e3f2fd;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#0d47a1;margin-top:4px;">
                  <i class="fas fa-clipboard-list"></i> <b>You are the Secretary.</b> Prepare documents & upload as needed.
              </div>`;
        }
    } else if (isPangkat && myRole === 'member') {
        waitingMsg = `<div style="background:#fff3e0;padding:6px 10px;border-radius:6px;font-size:0.78rem;color:#bf360c;margin-top:4px;">
              <i class="fas fa-users"></i> <b>You are a Member.</b> Attend the conciliation hearing.
          </div>`;
    }

    const showConcilPill = (x.status === 'pangkat_scheduled') && x.conciliation_deadline;
    const concilPillHtml = showConcilPill ? `
        <div class="document-info-row" style="background:#e0f7fa;padding:6px 8px;border-radius:6px;margin-top:4px;">
            <div class="document-info-label" style="color:#00838f;font-weight:700;width:auto;margin-right:6px;">⏳ 15-Day Conciliation:</div>
            <div class="document-info-value live-countdown" data-deadline="${escapeHtml(x.conciliation_deadline)}">
                <span class="status-pill safe"><i class="fas fa-hourglass-half"></i> Calculating…</span>
            </div>
        </div>` : '';

    const actions = [];

    if (needsAssign) {
        actions.push(`<button class="doc-action-btn assign" onclick="event.stopPropagation(); openAssignPositionsModal(${x.id})">
            <i class="fas fa-user-tag"></i> Assign Positions
        </button>`);
    }

    if (myRole === 'chair' && x.status === 'pangkat_constituted') {
        actions.push(`<button class="doc-action-btn schedule" onclick="event.stopPropagation(); openChairScheduleModal(${x.id})">
            <i class="fas fa-calendar-plus"></i> Schedule Hearing
        </button>`);
    }

    if (isHearingInProgress) {
        actions.push(`<button class="doc-action-btn enter" onclick="event.stopPropagation(); openHearingPanel(${x.id})">
            <i class="fas fa-play-circle"></i> Open Hearing
        </button>`);
    }

    if (myRole === 'chair' && x.status === 'pangkat_scheduled' && hearingIsTodayOrPast && !hearingStarted && !waitingForAppearance && !hasAppearanceSet) {
        actions.push(`<button class="doc-action-btn start" onclick="event.stopPropagation(); chairStartHearing(${x.id})">
            <i class="fas fa-play-circle"></i> Start Hearing
        </button>`);
    }

    if (myRole === 'chair' && waitingForAppearance) {
        actions.push(`<button class="doc-action-btn appear" onclick="event.stopPropagation(); openChairAppearanceModal(${x.id})">
            <i class="fas fa-user-clock"></i> Set Appearance Date
        </button>`);
    }

    if (myRole === 'chair' && hasAppearanceSet && appearancePassed) {
        actions.push(`<button class="doc-action-btn decide" onclick="event.stopPropagation(); openChairAbsenceDecisionModal(${x.id})">
            <i class="fas fa-gavel"></i> Decide on Absences
        </button>`);
    }

    actions.push(`<button class="doc-action-btn view" onclick="event.stopPropagation(); openComplaintDetail(${x.id})">
        <i class="fas fa-eye"></i> View Case
    </button>`);

    return `
        <div class="document-card ${needsAssign ? 'assign-needed' : ''}">
            ${needsAssign ? '<div class="assign-needed-ribbon">Assign</div>' : ''}
            ${isHearingInProgress ? '<div class="hearing-ribbon"><i class="fas fa-circle" style="font-size:0.5rem;margin-right:4px;"></i>IN PROGRESS</div>' : ''}
            <div class="document-card-header ${headerCls}">
                <div class="document-title">
                    <i class="fas ${statusIcon}"></i>
                    <span>${escapeHtml(x.title)}</span>
                </div>
                <div class="document-status">
                    <i class="fas ${statusIcon}"></i> ${escapeHtml(statusLbl)}
                </div>
            </div>
            <div class="document-card-body">
                <div class="document-info-row">
                    <div class="document-info-label">Ref:</div>
                    <div class="document-info-value"><strong>${escapeHtml(x.reference_number)}</strong></div>
                </div>
                <div class="document-info-row">
                    <div class="document-info-label">Complainant:</div>
                    <div class="document-info-value">${escapeHtml(x.first_name || '')} ${escapeHtml(x.last_name || '')}</div>
                </div>
                ${x.hearing_date ? `
                    <div class="document-info-row">
                        <div class="document-info-label">Hearing:</div>
                        <div class="document-info-value">${escapeHtml(x.hearing_date)} ${escapeHtml(x.hearing_time || '')}</div>
                    </div>
                ` : ''}
                ${concilPillHtml}
                ${roleChips.length ? `
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;">
                        ${roleChips.join('')}
                    </div>
                ` : ''}
                ${waitingMsg}
            </div>
            <div class="document-card-actions" onclick="event.stopPropagation()">
                ${actions.join('')}
            </div>
        </div>`;
}

// ============================================================
// ASSIGN POSITIONS MODAL
// ============================================================
function openAssignPositionsModal(caseId) {
    const caseData = cachedCases.find(c => Number(c.id) === Number(caseId));
    if (!caseData) { Swal.fire('Error', 'Case not found.', 'error'); return; }

    const members = caseData.pangkat_members || [];
    if (members.length !== 3) {
        Swal.fire('Error', 'This Pangkat does not have exactly 3 members.', 'error'); return;
    }

    const optHtml = (m) => `<option value="${m.member_id}">${escapeHtml(m.full_name)}${Number(m.member_id) === ADMIN_ID ? ' (You)' : ''}</option>`;

    Swal.fire({
        title: '',
        html: `
            <div style="text-align:left;">
                <div class="dm-field">
                    <label style="font-weight:800;color:#b71c1c;font-size:0.9rem;text-transform:uppercase;">Assign Pangkat Positions</label>
                    <div style="font-size:0.82rem;color:#555;margin-top:4px;margin-bottom:10px;">
                        Ref: <b>${escapeHtml(caseData.reference_number)}</b>
                    </div>
                    <div style="padding:10px 14px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.78rem;color:#b71c1c;line-height:1.55;">
                        <b>⚠️ Per KP Handbook:</b> The members shall elect from among themselves a Chairperson and a Secretary.
                        This action is <b>FINAL and cannot be changed</b> once confirmed.
                    </div>
                </div>
                <div class="dm-field">
                    <label>Chairperson <span class="req">*</span></label>
                    <select id="posChair">
                        <option value="">— Select Chairperson —</option>
                        ${members.map(optHtml).join('')}
                    </select>
                </div>
                <div class="dm-field">
                    <label>Secretary <span class="req">*</span></label>
                    <select id="posSecretary" disabled>
                        <option value="">— Select Secretary —</option>
                    </select>
                </div>
                <div class="dm-field">
                    <label>Member <span class="req">*</span></label>
                    <select id="posMember" disabled>
                        <option value="">— Select Member —</option>
                    </select>
                </div>
            </div>`,
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
                selSec.innerHTML = '<option value="">— Select Secretary —</option>';
                members.forEach(m => {
                    if (String(m.member_id) === String(chair)) return;
                    selSec.innerHTML += `<option value="${m.member_id}">${escapeHtml(m.full_name)}${Number(m.member_id) === ADMIN_ID ? ' (You)' : ''}</option>`;
                });
                selSec.disabled = !chair;
                selSec.value = '';
                rebuildMem();
            };
            const rebuildMem = () => {
                const chair = selChair.value;
                const sec   = selSec.value;
                selMem.innerHTML = '<option value="">— Select Member —</option>';
                members.forEach(m => {
                    if (String(m.member_id) === String(chair)) return;
                    if (String(m.member_id) === String(sec))   return;
                    selMem.innerHTML += `<option value="${m.member_id}">${escapeHtml(m.full_name)}${Number(m.member_id) === ADMIN_ID ? ' (You)' : ''}</option>`;
                });
                selMem.disabled = !(chair && sec);
                selMem.value = '';
            };

            selChair.addEventListener('change', rebuildSec);
            selSec.addEventListener('change', rebuildMem);
        },
        preConfirm: () => {
            const chair = Number(document.getElementById('posChair').value);
            const sec   = Number(document.getElementById('posSecretary').value);
            const mem   = Number(document.getElementById('posMember').value);
            if (!chair) { Swal.showValidationMessage('Select the Chairperson.'); return false; }
            if (!sec)   { Swal.showValidationMessage('Select the Secretary.'); return false; }
            if (!mem)   { Swal.showValidationMessage('Select the Member.'); return false; }
            if (chair === sec || chair === mem || sec === mem) {
                Swal.showValidationMessage('Each member can only hold one position.');
                return false;
            }
            return { chair_id: chair, secretary_id: sec, member_id: mem };
        }
    }).then(async (result) => {
        if (!result.isConfirmed || !result.value) return;

        const chairName = members.find(m => Number(m.member_id) === result.value.chair_id)?.full_name || '';
        const secName   = members.find(m => Number(m.member_id) === result.value.secretary_id)?.full_name || '';
        const memName   = members.find(m => Number(m.member_id) === result.value.member_id)?.full_name || '';

        const confirm = await Swal.fire({
            icon: 'warning',
            title: 'Confirm Final Positions?',
            html: `<div style="text-align:left;font-size:0.9rem;">
                <div style="padding:12px 14px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.83rem;color:#b71c1c;line-height:1.6;">
                    <b><i class="fas fa-exclamation-triangle"></i> This action is FINAL.</b><br>
                    Once you confirm, the roles cannot be changed. All three members will be notified immediately.
                </div>
                <ul style="line-height:1.8; margin-top:12px;">
                    <li><b>Chairperson:</b> ${escapeHtml(chairName)}</li>
                    <li><b>Secretary:</b> ${escapeHtml(secName)}</li>
                    <li><b>Member:</b> ${escapeHtml(memName)}</li>
                </ul>
            </div>`,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-check-double"></i> Yes, Confirm Final',
            confirmButtonColor: '#b71c1c',
            cancelButtonText: '<i class="fas fa-times"></i> Cancel',
            cancelButtonColor: '#6c757d',
            allowOutsideClick: false,
            allowEscapeKey: false,
            focusCancel: true
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
                Swal.fire({
                    icon: 'success',
                    title: 'Positions Assigned',
                    html: `<div style="text-align:left;font-size:0.9rem;">
                        <p>The positions have been set and all members were notified.</p>
                        <div style="margin-top:10px;padding:12px;background:#e8f5e9;border-left:4px solid #2E7D32;border-radius:8px;font-size:0.83rem;color:#1b5e20;">
                            <b>Next step:</b> The <b>Chairperson</b> (<b>${escapeHtml(d.chair?.name || '')}</b>)
                            will schedule the conciliation hearing from the Lupon Portal.
                        </div>
                    </div>`,
                    confirmButtonColor: '#2E7D32'
                }).then(() => loadMyComplaints());
            } else {
                Swal.fire('Error', d.message || 'Failed to save positions.', 'error');
            }
        } catch (e) {
            Swal.close();
            Swal.fire('Error', 'Network error.', 'error');
        }
    });
}

// ============================================================
// CHAIRPERSON: Schedule Conciliation Hearing
// ============================================================
async function openChairScheduleModal(caseId) {
    const today = new Date().toISOString().split('T')[0];

    let witnesses = [];
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${caseId}`);
        const d = await r.json();
        if (d.success) witnesses = d.witnesses || [];
    } catch (e) {}

    const hasWitnesses = witnesses.length > 0;

    const result = await Swal.fire({
        title: '',
        html: `<div style="text-align:left;">
            <div style="font-weight:800;color:#e65100;font-size:1.05rem;display:flex;align-items:center;gap:8px;padding-bottom:10px;margin-bottom:12px;border-bottom:2px solid #ffe0b2;">
                <i class="fas fa-calendar-plus"></i> Schedule Conciliation Hearing
            </div>
            <div class="dm-field"><label>Hearing Date <span class="req">*</span></label><input type="date" id="chairDate" min="${today}" value="${today}"></div>
            <div class="dm-field"><label>Hearing Time <span class="req">*</span></label><input type="time" id="chairTime" value="09:00"></div>
            <div class="dm-field"><label>Location</label><input type="text" id="chairLocation" value="Barangay Hall"></div>
            <div class="dm-field"><label>Notes <span style="color:#999;font-weight:400;font-size:0.75rem;">(optional)</span></label><textarea id="chairNotes" rows="2" placeholder="e.g. Both parties confirmed attendance."></textarea></div>

            <div class="dm-field" style="margin-top:16px;">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:600;padding:10px 14px;background:#e3f2fd;border-left:4px solid #1565c0;border-radius:8px;">
                    <input type="checkbox" id="chairIssueWitnessSubpoenas" ${hasWitnesses ? '' : 'disabled'} style="width:18px;height:18px;cursor:pointer;">
                    <span style="flex:1;">
                        <b>Also issue Subpoena (KP Form #13)</b> for witnesses
                        ${hasWitnesses ? `<div style="font-size:0.75rem;color:#666;margin-top:3px;">${witnesses.length} witness(es) recorded for this case</div>` : '<div style="font-size:0.75rem;color:#c62828;margin-top:3px;">No witnesses recorded yet</div>'}
                    </span>
                </label>
            </div>

            <div style="padding:10px 14px;background:#e0f7fa;border-left:4px solid #00838f;border-radius:8px;font-size:0.78rem;color:#005662;line-height:1.55;margin-top:14px;">
                <b>What happens next:</b><br>
                • <b>KP Form #12 (Notice of Hearing)</b> will be issued to both parties automatically.<br>
                • The 15-day conciliation period starts on the scheduled hearing date.<br>
                • On the hearing day, click <b>"Start Hearing"</b> to open the hearing session for all members.
            </div>
        </div>`,
        width: 580,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-paper-plane"></i> Schedule & Issue Notices',
        confirmButtonColor: '#e65100',
        cancelButtonColor: '#6c757d',
        allowOutsideClick: false,
        preConfirm: () => {
            const date = document.getElementById('chairDate').value;
            const time = document.getElementById('chairTime').value;
            if (!date) { Swal.showValidationMessage('Hearing date is required.'); return false; }
            if (!time) { Swal.showValidationMessage('Hearing time is required.'); return false; }
            return {
                date,
                time,
                location: document.getElementById('chairLocation').value.trim() || 'Barangay Hall',
                notes: document.getElementById('chairNotes').value.trim(),
                issue_witness_subpoenas: document.getElementById('chairIssueWitnessSubpoenas').checked ? '1' : '0',
            };
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
            const deadlineNice = d.conciliation_deadline ? new Date(d.conciliation_deadline.replace(' ','T')).toLocaleDateString('en-US', {dateStyle:'long'}) : '';
            const kp13Html = (result.value.issue_witness_subpoenas === '1' && d.kp13)
                ? `<div style="margin-top:10px;padding:10px 12px;background:#e3f2fd;border-left:4px solid #1565c0;border-radius:8px;font-size:0.82rem;color:#0d47a1;">
                    <i class="fas fa-file-signature"></i> <b>KP Form #13 (Subpoena)</b> issued for witnesses.
                </div>` : '';
            Swal.fire({
                icon: 'success',
                title: 'Conciliation Scheduled',
                html: `<div style="text-align:left;font-size:0.9rem;">
                    <p>Hearing set for:</p>
                    <p style="font-weight:700;color:#e65100;font-size:1rem;">${escapeHtml(result.value.date)} at ${escapeHtml(result.value.time)}</p>
                    <p style="font-size:0.82rem;color:#666;">${escapeHtml(result.value.location)}</p>
                    ${deadlineNice ? `<div style="margin-top:12px;padding:10px 12px;background:#e0f7fa;border-left:4px solid #00838f;border-radius:8px;font-size:0.82rem;color:#005662;"><i class="fas fa-hourglass-half"></i> 15-day conciliation period ends on <b>${escapeHtml(deadlineNice)}</b></div>` : ''}
                    <div style="margin-top:12px;padding:10px 12px;background:#e8f5e9;border-left:4px solid #2E7D32;border-radius:8px;font-size:0.82rem;color:#1b5e20;">
                        <i class="fas fa-file-signature"></i> <b>KP Form #12 (Notice of Hearing)</b> issued to both parties.
                    </div>
                    ${kp13Html}
                    <p style="margin-top:10px;font-size:0.82rem;color:#555;">All 3 Pangkat members, the complainant, the Captain, and the Secretary have been notified.</p>
                    <div style="margin-top:14px;padding:10px 12px;background:#fff3e0;border-left:4px solid #f57c00;border-radius:8px;font-size:0.82rem;color:#e65100;">
                        <b>On the hearing day:</b> Click <b>"Start Hearing"</b> on the case card to open the session.
                    </div>
                </div>`,
                confirmButtonColor: '#2E7D32',
                width: 620
            }).then(() => loadMyComplaints());
        } else {
            Swal.fire('Error', d.message || 'Failed to schedule.', 'error');
        }
    } catch (e) {
        Swal.close();
        Swal.fire('Error', 'Network error.', 'error');
    }
}

// ============================================================
// CHAIRPERSON: Start Hearing → opens hearing panel + locks chair
// ============================================================
async function chairStartHearing(caseId) {
    const confirm = await Swal.fire({
        icon: 'question',
        title: 'Start the Conciliation Hearing?',
        html: `<div style="text-align:left;font-size:0.9rem;">
            <p>This will open the hearing session. The Secretary will be able to record attendance.</p>
            <div style="padding:10px 12px;background:#f3e5f5;border-left:4px solid #6a1b9a;border-radius:8px;font-size:0.82rem;color:#4a148c;margin-top:10px;">
                <b>Note:</b> As Chairperson, you must <b>resolve the hearing</b> (settle it or handle any absent party) before you can leave the page. Members and the Secretary may come and go freely.
            </div>
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
            Swal.fire({
                icon: 'success',
                title: 'Hearing Started',
                text: 'All members have been notified.',
                confirmButtonColor: '#2E7D32',
                timer: 1200,
                showConfirmButton: false
            }).then(() => {
                openHearingPanel(caseId);
            });
        } else {
            Swal.fire('Error', d.message || 'Could not start hearing.', 'error');
        }
    } catch (e) {
        Swal.close();
        Swal.fire('Error', 'Network error.', 'error');
    }
}

// ============================================================
// HEARING PANEL  (chair is locked; secretary & member may leave)
// ============================================================
window.__hearingLock = {
    active: false,
    complaintId: null,
    myRole: null,
    reference: null,
    pollTimer: null,
    cachedCaseDetail: null,
    lastSignature: null,
};

function chairIsLocked() {
    return window.__hearingLock.active && window.__hearingLock.myRole === 'chair';
}

function openHearingPanel(caseId) {
    // Remove any lingering panel first
    const existing = document.getElementById('hearingPanelOverlay');
    if (existing) existing.remove();

    window.__hearingLock.complaintId = caseId;
    window.__hearingLock.active = true;

    refreshHearingPanel();
    if (window.__hearingLock.pollTimer) clearInterval(window.__hearingLock.pollTimer);
    window.__hearingLock.pollTimer = setInterval(refreshHearingPanel, 3000);

    if (chairIsLocked()) {
        installChairNavigationGuards();
    }
}

function closeHearingPanel(force) {
    if (!force && chairIsLocked()) {
        Swal.fire({
            icon: 'warning',
            title: 'Cannot Leave Yet',
            html: `<div style="text-align:left;font-size:0.9rem;">
                <p>You are the Chairperson and this hearing is still active.</p>
                <ul style="line-height:1.7;margin-top:6px;">
                    <li>If there is a no-show, click <b>Set Appearance Date</b>.</li>
                    <li>Then <b>Decide on Absences</b>.</li>
                    <li>Otherwise, click <b>End Hearing</b> once the Secretary has recorded attendance.</li>
                </ul>
            </div>`,
            confirmButtonColor: '#2E7D32'
        });
        return;
    }

    // Kill polling FIRST so no in-flight tick can re-open or alert
    if (window.__hearingLock.pollTimer) {
        clearInterval(window.__hearingLock.pollTimer);
        window.__hearingLock.pollTimer = null;
    }

    window.__hearingLock.active = false;
    window.__hearingLock.complaintId = null;
    window.__hearingLock.cachedCaseDetail = null;
    window.__hearingLock.lastSignature = null;

    removeChairNavigationGuards();

    const overlay = document.getElementById('hearingPanelOverlay');
    if (overlay) overlay.remove();

    loadMyComplaints();
}

async function refreshHearingPanel() {
    const cid = window.__hearingLock.complaintId;
    if (!cid) return;

    // Only proceed if the panel is actually mounted on the page
    const overlay = document.getElementById('hearingPanelOverlay');
    if (!overlay) {
        // Panel was closed — stop polling quietly
        if (window.__hearingLock.pollTimer) {
            clearInterval(window.__hearingLock.pollTimer);
            window.__hearingLock.pollTimer = null;
        }
        window.__hearingLock.active = false;
        removeChairNavigationGuards();
        return;
    }

    let d;
    try {
        const r = await fetch('../admin_complaint_ajax.php?action=get_lupon_lock_state&complaint_id=' + cid);
        d = await r.json();
    } catch (e) { return; }
    if (!d || !d.success) return;

    if (!d.is_live) {
        // Only alert if the panel is still on screen
        const stillOpen = !!document.getElementById('hearingPanelOverlay');
        if (stillOpen && window.__hearingLock.active) {
            const wasChair = window.__hearingLock.myRole === 'chair';
            Swal.fire({
                icon: 'info',
                title: 'Hearing Ended',
                text: 'The conciliation hearing has ended.',
                confirmButtonColor: '#2E7D32',
                timer: wasChair ? undefined : 1500,
                showConfirmButton: wasChair
            });
        }
        closeHearingPanel(true);
        return;
    }

    window.__hearingLock.active = true;
    window.__hearingLock.complaintId = d.complaint_id;
    window.__hearingLock.myRole = d.my_role;
    window.__hearingLock.reference = d.reference;

    if (chairIsLocked()) {
        installChairNavigationGuards();
    } else {
        removeChairNavigationGuards();
    }

    await ensureCaseDetailsForPanel(d.complaint_id);

    // Only re-render if the panel is still mounted
    if (document.getElementById('hearingPanelOverlay')) {
        renderHearingPanel(d);
    }
}

async function ensureCaseDetailsForPanel(complaintId) {
    if (window.__hearingLock.cachedCaseDetail &&
        Number(window.__hearingLock.cachedCaseDetail.complaint.id) === Number(complaintId)) {
        return window.__hearingLock.cachedCaseDetail;
    }
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${complaintId}`);
        const d = await r.json();
        if (d && d.success) window.__hearingLock.cachedCaseDetail = d;
    } catch (e) {}
    return window.__hearingLock.cachedCaseDetail;
}

function buildCaseDetailsPanel() {
    const d = window.__hearingLock.cachedCaseDetail;
    if (!d || !d.success) {
        return `<div class="lock-block"><div class="lock-block-title"><i class="fas fa-folder-open"></i> Case Details</div><div class="lock-note">Loading case details…</div></div>`;
    }

    const c = d.complaint;
    const complainants = d.complainants || [];
    const respondents  = d.respondents  || [];
    const witnesses    = d.witnesses    || [];
    const evidence     = d.evidence     || [];
    const hearings     = d.hearings     || [];

    const niceDate = c.created_at ? new Date(c.created_at.replace(' ','T')).toLocaleDateString('en-US', {dateStyle:'long'}) : '—';

    const personCard = (p, roleCls, roleLabel) => `
        <div class="lock-person">
            <div class="lock-person-avatar ${roleCls}"><i class="fas ${roleCls === 'complainant' ? 'fa-user' : (roleCls === 'respondent' ? 'fa-user-friends' : 'fa-user-tag')}"></i></div>
            <div style="flex:1;min-width:0;">
                <div class="lock-person-name">${escapeHtml(p.full_name || '—')}</div>
                <div class="lock-person-meta">
                    <span>${escapeHtml(roleLabel)}</span>
                    ${p.contact_number ? `<span><i class="fas fa-phone"></i> ${escapeHtml(p.contact_number)}</span>` : ''}
                    ${p.address ? `<span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(p.address)}</span>` : ''}
                </div>
            </div>
        </div>`;

    const peopleHtml = `
        <div class="lock-subblock">
            <div class="lock-subblock-title"><i class="fas fa-user"></i> Complainant(s)</div>
            ${complainants.length ? complainants.map(p => personCard(p, 'complainant', 'Complainant')).join('') : '<div class="lock-note">No complainant recorded.</div>'}
        </div>
        <div class="lock-subblock">
            <div class="lock-subblock-title"><i class="fas fa-user-friends"></i> Respondent(s)</div>
            ${respondents.length ? respondents.map(p => personCard(p, 'respondent', 'Respondent')).join('') : '<div class="lock-note">No respondent recorded.</div>'}
        </div>
        ${witnesses.length ? `
        <div class="lock-subblock">
            <div class="lock-subblock-title"><i class="fas fa-user-tag"></i> Witness(es)</div>
            ${witnesses.map(p => personCard(p, 'witness', 'Witness')).join('')}
        </div>` : ''}
    `;

    const statementsFor = (h) => {
        if (!h || !h.session_record || typeof h.session_record !== 'object') return '';
        const sr = h.session_record;
        const cs = sr.complainant_statement || null;
        const rs = sr.respondent_statement  || null;
        const renderOne = (label, obj, cls) => {
            if (!obj || (!obj.text && !obj.audio_path)) return '';
            const audio = obj.audio_path
                ? `<audio controls style="width:100%;margin-top:6px;"><source src="../../${escapeHtml(obj.audio_path)}"></audio>` : '';
            return `<div class="lock-stmt ${cls}">
                <div class="lock-stmt-title">${escapeHtml(label)}</div>
                ${obj.text ? `<div class="lock-stmt-text">${escapeHtml(obj.text).replace(/\n/g,'<br>')}</div>` : '<div class="lock-note">No written text.</div>'}
                ${audio}
            </div>`;
        };
        const csHtml = renderOne('Complainant\'s Statement', cs, 'complainant');
        const rsHtml = renderOne('Respondent\'s Statement', rs, 'respondent');
        if (!csHtml && !rsHtml) return '';
        return `<div class="lock-stmt-group">
            <div class="lock-stmt-header">Hearing #${h.session_number || 1} — ${escapeHtml((h.session_type || '').toUpperCase())} · ${h.hearing_date ? new Date(h.hearing_date).toLocaleDateString('en-US',{dateStyle:'medium'}) : ''}</div>
            ${csHtml}${rsHtml}
        </div>`;
    };

    const mediationHearings = hearings.filter(h => h.session_type === 'mediation');
    const statementsHtml = mediationHearings.map(statementsFor).filter(Boolean).join('');
    const statementsBlock = statementsHtml
        ? `<div class="lock-block">
             <div class="lock-block-title"><i class="fas fa-microphone"></i> Party Statements (from prior hearings)</div>
             <div class="lock-block-body">${statementsHtml}</div>
           </div>`
        : `<div class="lock-block"><div class="lock-block-title"><i class="fas fa-microphone"></i> Party Statements (from prior hearings)</div><div class="lock-block-body"><div class="lock-note">No party statements were recorded in previous hearings.</div></div></div>`;

    const evidenceHtml = evidence.length
        ? evidence.map(e => {
            const fname = e.file_name || e.original_name || e.filename || 'evidence-file';
            const fpath = e.file_path || e.filepath || '';
            const nice = e.uploaded_at ? new Date(String(e.uploaded_at).replace(' ','T')).toLocaleString('en-US',{dateStyle:'medium',timeStyle:'short'}) : '';
            return `<div class="lock-evidence-row">
                <div class="lock-evidence-icon"><i class="fas fa-file-alt"></i></div>
                <div style="flex:1;min-width:0;">
                    <div class="lock-evidence-name">${escapeHtml(fname)}</div>
                    <div class="lock-evidence-meta">${nice ? `<span><i class="fas fa-clock"></i> ${escapeHtml(nice)}</span>` : ''}${e.file_size ? `<span><i class="fas fa-hdd"></i> ${(Number(e.file_size)/1024).toFixed(1)} KB</span>` : ''}</div>
                    ${e.description ? `<div class="lock-evidence-desc">${escapeHtml(e.description)}</div>` : ''}
                </div>
                ${fpath ? `<button type="button" class="lock-evidence-open" onclick="window.open('../../${escapeHtml(fpath)}','_blank')"><i class="fas fa-external-link-alt"></i> Open</button>` : ''}
            </div>`;
        }).join('')
        : '<div class="lock-note">No attachments on this case.</div>';

    const evidenceBlock = `<div class="lock-block">
        <div class="lock-block-title"><i class="fas fa-paperclip"></i> Attachments / Evidence (${evidence.length})</div>
        <div class="lock-block-body">${evidenceHtml}</div>
    </div>`;

    return `
        <div class="lock-block">
            <div class="lock-block-title"><i class="fas fa-folder-open"></i> Case Details</div>
            <div class="lock-block-body">
                <div class="lock-case-grid">
                    <div class="lock-case-item"><label>Reference</label><span>${escapeHtml(c.reference_number || '—')}</span></div>
                    <div class="lock-case-item"><label>Subject</label><span>${escapeHtml(c.complaint_subject || '—')}</span></div>
                    <div class="lock-case-item"><label>Filed On</label><span>${escapeHtml(niceDate)}</span></div>
                    <div class="lock-case-item"><label>Priority</label><span>${escapeHtml((c.priority || 'medium').toUpperCase())}</span></div>
                </div>
                <div class="lock-case-desc">
                    <label>Description</label>
                    <div>${escapeHtml(c.description || '—').replace(/\n/g,'<br>')}</div>
                </div>
                ${peopleHtml}
            </div>
        </div>
        ${statementsBlock}
        ${evidenceBlock}`;
}

function buildReadOnlyAttendanceSummary(state) {
    const list = state.attendance || [];
    if (!list.length) return '';
    const rows = list.map(p => {
        const r = (p.party_type || '').toLowerCase();
        const st = p.status || 'pending';
        const pill = st === 'attend' ? 'ATTEND' : (st === 'no_show' ? 'NO SHOW' : 'PENDING');
        return `<div class="lock-att-ro-row">
            <div class="lock-att-ro-name">
                <i class="fas ${r === 'complainant' ? 'fa-user' : 'fa-user-friends'}"></i>
                ${escapeHtml(p.full_name)}
                <span class="lock-att-ro-role">${escapeHtml(r.charAt(0).toUpperCase() + r.slice(1))}</span>
            </div>
            <span class="att-status-pill ${st}">${pill}</span>
        </div>`;
    }).join('');
    return `<div class="lock-block">
        <div class="lock-block-title"><i class="fas fa-clipboard-check"></i> Attendance Summary</div>
        <div class="lock-block-body">${rows}</div>
    </div>`;
}

function renderHearingPanel(state) {
    let overlay = document.getElementById('hearingPanelOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'hearingPanelOverlay';
        document.body.appendChild(overlay);
    }

    const role = state.my_role || 'member';
    const isChair = role === 'chair';
    const isSecretary = role === 'secretary';

    const hearingDate = state.hearing && state.hearing.hearing_date
        ? new Date(state.hearing.hearing_date).toLocaleDateString('en-US', { dateStyle: 'full' })
        : '—';
    const hearingTime = state.hearing && state.hearing.hearing_time ? state.hearing.hearing_time : '—';
    const hearingLoc  = state.hearing && state.hearing.location ? state.hearing.location : 'Barangay Hall';

    const roleBanner = isChair
        ? `<div class="lock-banner chair"><i class="fas fa-user-tie"></i> <b>You are the Chairperson.</b> Review the case details, lead the discussion, and end the hearing when ready.</div>`
        : (isSecretary
            ? `<div class="lock-banner secretary"><i class="fas fa-clipboard-list"></i> <b>You are the Secretary.</b> Click <b>Record Attendance</b> to mark who is present. You may leave anytime.</div>`
            : `<div class="lock-banner member"><i class="fas fa-users"></i> <b>You are a Pangkat Member.</b> Review the case details below. You may leave anytime.</div>`);

    const casePanel = buildCaseDetailsPanel();

    let middleHtml = '';
    const att = state.attendance || [];
    const totalParties = att.length;
    const marked = att.filter(p => p.status === 'attend' || p.status === 'no_show').length;
    const fullyMarked = totalParties > 0 && marked === totalParties;

    if (isSecretary) {
        middleHtml = `<div class="lock-block">
            <div class="lock-block-title"><i class="fas fa-clipboard-list"></i> Attendance</div>
            <div class="lock-block-body">
                <div class="lock-note" style="margin-bottom:12px;">
                    ${fullyMarked
                        ? 'Attendance is fully recorded. You may edit it anytime by clicking the button below.'
                        : 'Not all parties have been marked yet. Click the button below to record attendance.'}
                </div>
                <button type="button" class="lock-save-btn" onclick="openSecretaryAttendanceModal()">
                    <i class="fas fa-clipboard-list"></i>
                    ${fullyMarked ? 'Edit Attendance' : 'Record Attendance'}
                </button>
            </div>
        </div>`;
    } else {
        middleHtml = buildReadOnlyAttendanceSummary(state);

        if (isChair && state.concil_absence_waiting_since && !state.concil_absence_appearance_at) {
            middleHtml += `<div class="lock-block lock-alert">
                <div class="lock-block-title"><i class="fas fa-exclamation-triangle"></i> Absent Party — Action Required</div>
                <div class="lock-block-body">
                    <div class="lock-note">One or more parties did not appear. Please set an appearance date so they can explain.</div>
                    <button type="button" class="lock-end-btn" style="background:linear-gradient(135deg,#c62828,#e53935);margin-top:12px;" onclick="openChairAppearanceModalFromPanel()">
                        <i class="fas fa-user-clock"></i> Set Appearance Date
                    </button>
                </div>
            </div>`;
        } else if (isChair && state.concil_absence_appearance_at) {
            middleHtml += `<div class="lock-block lock-alert">
                <div class="lock-block-title"><i class="fas fa-gavel"></i> Decide on Absences</div>
                <div class="lock-block-body">
                    <div class="lock-note">The appearance date has passed. Please decide whether the absence was justified or unjustified.</div>
                    <button type="button" class="lock-end-btn" style="background:linear-gradient(135deg,#6a1b9a,#8e24aa);margin-top:12px;" onclick="openChairAbsenceDecisionFromPanel()">
                        <i class="fas fa-gavel"></i> Decide on Absences
                    </button>
                </div>
            </div>`;
        }
    }

    let footerHtml = '';
    if (isSecretary) {
        footerHtml = `
            <button type="button" class="lock-close-btn" onclick="closeHearingPanel()">
                <i class="fas fa-sign-out-alt"></i> Leave
            </button>`;
    } else if (isChair) {
        const noShowPending = !!state.concil_absence_waiting_since && !state.concil_absence_appearance_at;
        const decidePending = !!state.concil_absence_appearance_at;
        const canEnd = fullyMarked && !noShowPending && !decidePending;

        footerHtml = `
            <button type="button" class="lock-end-btn" ${canEnd ? '' : 'disabled'} onclick="${canEnd ? 'openEndHearingModal()' : 'lockEndDisabledNotice()'}">
                <i class="fas fa-stop-circle"></i> End Hearing
            </button>`;
    } else {
        footerHtml = `
            <button type="button" class="lock-close-btn" onclick="closeHearingPanel()">
                <i class="fas fa-sign-out-alt"></i> Close
            </button>
            <div class="lock-waiting">
                <i class="fas fa-users"></i> You may leave anytime
            </div>`;
    }

    const signature = `${role}|${state.complaint_id}|${(state.attendance||[]).map(p=>p.party_id+':'+p.status).join(',')}|${state.concil_absence_waiting_since||''}|${state.concil_absence_appearance_at||''}`;

    // Skip DOM churn if nothing meaningful changed (except first render)
    if (overlay.dataset.signature === signature && overlay.querySelector('.lock-shell')) {
        return;
    }

    overlay.innerHTML = `
        <div class="lock-shell">
            <div class="lock-header">
                <div class="lock-header-left">
                    <span class="lock-hearing-dot"></span>
                    <div>
                        <div class="lock-header-title">CONCILIATION HEARING IN PROGRESS</div>
                        <div class="lock-header-sub">
                            Ref: <b>${escapeHtml(state.reference || '')}</b> · ${escapeHtml(state.title || '')}
                        </div>
                    </div>
                </div>
                <div class="lock-header-right">
                    <div class="lock-meta-item"><i class="fas fa-calendar"></i> ${escapeHtml(hearingDate)}</div>
                    <div class="lock-meta-item"><i class="fas fa-clock"></i> ${escapeHtml(hearingTime)}</div>
                    <div class="lock-meta-item"><i class="fas fa-map-marker-alt"></i> ${escapeHtml(hearingLoc)}</div>
                </div>
            </div>

            <div class="lock-body">
                ${roleBanner}
                ${casePanel}
                ${middleHtml}
            </div>

            <div class="lock-footer">
                ${footerHtml}
            </div>
        </div>`;
    overlay.dataset.signature = signature;
}

// ------------------------------------------------------------
// SECRETARY: Attendance modal (separate from the hearing panel)
// ------------------------------------------------------------
function openSecretaryAttendanceModal() {
    const caseId = window.__hearingLock.complaintId;
    if (!caseId) {
        Swal.fire('Error', 'No active hearing.', 'error');
        return;
    }

    // Fetch fresh state
    fetch('../admin_complaint_ajax.php?action=get_lupon_lock_state&complaint_id=' + caseId)
        .then(r => r.json())
        .then(state => {
            if (!state.success || !state.is_live) {
                Swal.fire('Info', 'This hearing is no longer active.', 'info');
                return;
            }
            if (state.my_role !== 'secretary') {
                Swal.fire('Not Allowed', 'Only the Secretary may record attendance.', 'info');
                return;
            }

            const cards = (state.attendance || []).map(p => {
                const r = (p.party_type || '').toLowerCase();
                const rl = r.charAt(0).toUpperCase() + r.slice(1);
                const st = p.status || 'pending';
                return `<div class="att-card ${st !== 'pending' ? st : ''}" data-lock-party-id="${p.party_id}">
                    <div class="att-head">
                        <div class="att-avatar ${r}"><i class="fas ${r === 'complainant' ? 'fa-user' : 'fa-user-friends'}"></i></div>
                        <div class="att-meta">
                            <div class="att-name">${escapeHtml(p.full_name)}</div>
                            <div class="att-role ${r}">${escapeHtml(rl)}</div>
                        </div>
                        <span class="att-status-pill ${st}">${st === 'no_show' ? 'NO SHOW' : (st === 'attend' ? 'ATTEND' : 'PENDING')}</span>
                    </div>
                    <div class="att-btn-row">
                        <button type="button" class="att-btn attend ${st === 'attend' ? 'active' : ''}"
                                onclick="lockSetAtt(${p.party_id}, 'attend', this)">
                            <i class="fas fa-check"></i> Attend
                        </button>
                        <button type="button" class="att-btn no_show ${st === 'no_show' ? 'active' : ''}"
                                onclick="lockSetAtt(${p.party_id}, 'no_show', this)">
                            <i class="fas fa-times"></i> No Show
                        </button>
                    </div>
                </div>`;
            }).join('');

            Swal.fire({
                title: '',
                html: `<div class="dm-form">
                    <div class="dm-header blue" style="border-radius:8px 8px 0 0;">
                        <div class="dm-header-left">
                            <i class="fas fa-clipboard-list"></i>
                            <div>
                                <div class="dm-header-title">Record Attendance</div>
                                <div class="dm-header-sub">Ref: ${escapeHtml(state.reference || '')}</div>
                            </div>
                        </div>
                    </div>
                    <div style="padding:20px 24px;background:#fff;max-height:64vh;overflow-y:auto;">
                        <div style="padding:10px 12px;background:#e3f2fd;border-left:4px solid #1565c0;border-radius:8px;font-size:0.82rem;color:#0d47a1;line-height:1.55;margin-bottom:16px;">
                            Mark each party as <b>Attend</b> or <b>No Show</b>, then click <b>Save Attendance</b>.
                            If someone is marked No Show, the Chairperson will be asked to set an appearance date.
                        </div>
                        <div id="lockAttHost">${cards}</div>
                    </div>
                </div>`,
                width: 620,
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-save"></i> Save Attendance',
                confirmButtonColor: '#1565c0',
                cancelButtonColor: '#6c757d',
                allowOutsideClick: false,
                focusConfirm: false,
                didOpen: () => {
                    window.__secretaryAttHearingId = (state.hearing && state.hearing.id) ? state.hearing.id : 0;
                },
                preConfirm: () => {
                    const rows = document.querySelectorAll('#lockAttHost .att-card');
                    const payload = [];
                    let allMarked = true;
                    rows.forEach(row => {
                        const pid = Number(row.dataset.lockPartyId);
                        const active = row.querySelector('.att-btn.active');
                        if (active) {
                            payload.push({
                                party_id: pid,
                                status: active.classList.contains('attend') ? 'attend' : 'no_show',
                            });
                        } else {
                            allMarked = false;
                        }
                    });
                    if (!allMarked) {
                        Swal.showValidationMessage('Please mark attendance for all parties.');
                        return false;
                    }
                    return payload;
                }
            }).then(async (result) => {
                if (!result.isConfirmed || !result.value) return;

                const hearingId = window.__secretaryAttHearingId;
                if (!hearingId) {
                    Swal.fire('Error', 'Hearing ID not found.', 'error');
                    return;
                }

                // ----- Confirmation step -----
                const absentCount = result.value.filter(p => p.status === 'no_show').length;
                const attendCount = result.value.filter(p => p.status === 'attend').length;

                const confirmMsg = absentCount > 0
                    ? `<div style="text-align:left;font-size:0.9rem;">
                         <p>You are about to save attendance with <b>${absentCount}</b> No-Show part${absentCount > 1 ? 'ies' : 'y'}.</p>
                         <div style="padding:10px 12px;background:#fff3e0;border-left:4px solid #f57c00;border-radius:8px;font-size:0.82rem;color:#e65100;margin-top:10px;">
                             <b>Note:</b> The Chairperson will be notified to <b>Set an Appearance Date</b> for the absent part${absentCount > 1 ? 'ies' : 'y'}.
                         </div>
                       </div>`
                    : `<div style="text-align:left;font-size:0.9rem;">
                         <p>All <b>${attendCount}</b> part${attendCount > 1 ? 'ies' : 'y'} are marked <b>Attend</b>.</p>
                         <div style="padding:10px 12px;background:#e8f5e9;border-left:4px solid #2E7D32;border-radius:8px;font-size:0.82rem;color:#1b5e20;margin-top:10px;">
                             After saving, the Chairperson may <b>End Hearing</b>.
                         </div>
                       </div>`;

                const confirm = await Swal.fire({
                    icon: 'question',
                    title: 'Save Attendance?',
                    html: confirmMsg,
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-save"></i> Yes, Save',
                    confirmButtonColor: '#1565c0',
                    cancelButtonText: 'Go Back',
                    cancelButtonColor: '#6c757d',
                    allowOutsideClick: false
                });
                if (!confirm.isConfirmed) {
                    // Re-open the attendance modal so nothing is lost
                    openSecretaryAttendanceModal();
                    return;
                }

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

                    if (!d.success) {
                        Swal.fire('Error', d.message || 'Failed to save attendance.', 'error');
                        return;
                    }

                    if (d.absent_detected) {
                        await Swal.fire({
                            icon: 'warning',
                            title: 'Attendance Saved',
                            html: `<div style="text-align:left;font-size:0.9rem;">
                                <p>One or more parties did not appear. The Chairperson must now <b>Set Appearance Date</b>.</p>
                                <p style="font-size:0.82rem;color:#666;margin-top:8px;">You may leave anytime.</p>
                            </div>`,
                            confirmButtonColor: '#e65100'
                        });
                    } else {
                        await Swal.fire({
                            icon: 'success',
                            title: 'Attendance Saved',
                            text: 'All parties are marked present. The Chairperson may now end the hearing.',
                            confirmButtonColor: '#2E7D32',
                            timer: 1800,
                            showConfirmButton: false
                        });
                    }

                    // Force panel refresh
                    if (window.__hearingLock.active) {
                        window.__hearingLock.cachedCaseDetail = null;
                        window.__hearingLock.lastSignature = null;
                        const ov = document.getElementById('hearingPanelOverlay');
                        if (ov) delete ov.dataset.signature;
                        refreshHearingPanel();
                    }
                } catch (e) {
                    Swal.close();
                    Swal.fire('Error', 'Network error.', 'error');
                }
            });
        })
        .catch(() => {
            Swal.fire('Error', 'Could not load attendance.', 'error');
        });
}

// ------------------------------------------------------------
// Attendance toggle (used inside the modal)
// ------------------------------------------------------------
function lockSetAtt(partyId, status, btnEl) {
    const card = btnEl.closest('.att-card');
    if (!card) return;
    card.classList.remove('attend', 'no_show');
    card.classList.add(status);
    card.querySelectorAll('.att-btn').forEach(b => b.classList.remove('active'));
    btnEl.classList.add('active');
    const pill = card.querySelector('.att-status-pill');
    if (pill) {
        pill.classList.remove('attend', 'no_show', 'pending');
        pill.classList.add(status);
        pill.textContent = status === 'attend' ? 'ATTEND' : 'NO SHOW';
    }
}

function lockEndDisabledNotice() {
    Swal.fire({
        icon: 'info',
        title: 'Cannot End Yet',
        html: `<div style="text-align:left;font-size:0.9rem;">
            <p>The hearing cannot be ended yet. Please make sure:</p>
            <ul style="line-height:1.7;margin-top:6px;">
                <li>The <b>Secretary</b> has saved attendance for all parties.</li>
                <li>Any <b>no-show</b> has been resolved — set the appearance date and decide justified / unjustified.</li>
            </ul>
        </div>`,
        confirmButtonColor: '#2E7D32'
    });
}

async function openEndHearingModal() {
    const caseId = window.__hearingLock.complaintId;
    if (!caseId) return;

    const result = await Swal.fire({
        title: '',
        html: `<div style="text-align:left;">
            <div style="font-weight:800;color:#b71c1c;font-size:1.05rem;display:flex;align-items:center;gap:8px;padding-bottom:10px;margin-bottom:14px;border-bottom:2px solid #ffcdd2;">
                <i class="fas fa-stop-circle"></i> End Conciliation Hearing
            </div>
            <div style="padding:10px 14px;background:#fff3e0;border-left:4px solid #f57c00;border-radius:8px;font-size:0.82rem;color:#e65100;line-height:1.55;margin-bottom:14px;">
                <b>This is final.</b> The hearing will be closed and all members will be released.
            </div>
            <div class="dm-field">
                <label>Outcome <span style="color:#c62828;">*</span></label>
                <select id="endHearingOutcome" style="width:100%;padding:10px 12px;border:1.5px solid #cfd8dc;border-radius:8px;font-size:0.92rem;">
                    <option value="failed_conciliation_final">Failed — No Settlement (proceed to Certification)</option>
                    <option value="settled">Settled — Agreement Reached (KP #16)</option>
                    <option value="dismissed">Dismissed</option>
                </select>
            </div>
            <div class="dm-field" style="margin-top:14px;">
                <label>Notes / Summary <span style="color:#999;font-weight:400;font-size:0.75rem;">(optional)</span></label>
                <textarea id="endHearingNotes" rows="3" placeholder="e.g. Parties did not reach an agreement." style="width:100%;padding:10px 12px;border:1.5px solid #cfd8dc;border-radius:8px;font-size:0.92rem;font-family:inherit;resize:vertical;"></textarea>
            </div>
        </div>`,
        width: 560,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-stop-circle"></i> End Hearing',
        confirmButtonColor: '#c62828',
        cancelButtonColor: '#6c757d',
        allowOutsideClick: false,
        allowEscapeKey: false,
        focusCancel: true,
        preConfirm: () => {
            return {
                outcome: document.getElementById('endHearingOutcome').value,
                notes: document.getElementById('endHearingNotes').value.trim(),
            };
        }
    });

    if (!result.isConfirmed || !result.value) return;

    Swal.fire({ title: 'Ending hearing…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    const fd = new FormData();
    fd.append('action', 'chair_end_concil_hearing');
    fd.append('complaint_id', caseId);
    fd.append('outcome', result.value.outcome);
    fd.append('notes', result.value.notes);

    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        Swal.close();
        if (!d.success) { Swal.fire('Error', d.message || 'Could not end hearing.', 'error'); return; }
        Swal.fire({
            icon: 'success',
            title: 'Hearing Ended',
            text: 'The hearing is now closed.',
            confirmButtonColor: '#2E7D32',
            timer: 1500,
            showConfirmButton: false
        }).then(() => closeHearingPanel(true));
    } catch (e) {
        Swal.close();
        Swal.fire('Error', 'Network error.', 'error');
    }
}

async function openChairAppearanceModalFromPanel() {
    const caseId = window.__hearingLock.complaintId;
    if (!caseId) return;
    return openChairAppearanceModal(caseId);
}

async function openChairAbsenceDecisionFromPanel() {
    const caseId = window.__hearingLock.complaintId;
    if (!caseId) return;
    return openChairAbsenceDecisionModal(caseId);
}

// ------------------------------------------------------------
// CHAIR NAVIGATION GUARDS — only the Chair is locked
// ------------------------------------------------------------
let __chairNavGuardInstalled = false;
function __beforeUnloadGuard(e) {
    if (!chairIsLocked()) return;
    e.preventDefault();
    e.returnValue = '';
    return '';
}
function __popStateGuard(e) {
    if (!chairIsLocked()) return;
    history.pushState(null, '', location.href);
    Swal.fire({
        icon: 'warning',
        title: 'Hearing is Active',
        text: 'As Chairperson, you cannot leave until you end the hearing or resolve any absences.',
        confirmButtonColor: '#2E7D32',
        timer: 2200,
        showConfirmButton: false
    });
}
function installChairNavigationGuards() {
    if (__chairNavGuardInstalled) return;
    __chairNavGuardInstalled = true;
    window.addEventListener('beforeunload', __beforeUnloadGuard);
    history.pushState(null, '', location.href);
    window.addEventListener('popstate', __popStateGuard);
}
function removeChairNavigationGuards() {
    if (!__chairNavGuardInstalled) return;
    __chairNavGuardInstalled = false;
    window.removeEventListener('beforeunload', __beforeUnloadGuard);
    window.removeEventListener('popstate', __popStateGuard);
}

// ============================================================
// AUTO-DETECT ACTIVE HEARINGS (for Secretary + Members)
// ============================================================
window.__autoHearingCheckInterval = null;

function startAutoHearingCheck() {
    if (window.__autoHearingCheckInterval) clearInterval(window.__autoHearingCheckInterval);
    window.__autoHearingCheckInterval = setInterval(async () => {
        // If the panel is open, skip entirely
        if (window.__hearingLock.active) return;

        // Only run on the complaints view
        const complaintsView = document.querySelector('.nav-item[data-view="complaints"].active');
        if (!complaintsView) return;

        // Ensure a panel isn't already mounted (extra safety)
        if (document.getElementById('hearingPanelOverlay')) return;

        try {
            const r = await fetch('../admin_complaint_ajax.php?action=get_my_assigned_complaints');
            const d = await r.json();
            if (!d.success) return;

            const activeCase = (d.complaints || []).find(c => {
                const started = !!c.concil_hearing_started_at;
                const status = c.status;
                const hasAppearance = !!c.concil_absence_appearance_at;
                const waiting = !!c.concil_absence_waiting_since;
                return started && status === 'pangkat_scheduled' && !hasAppearance && !waiting;
            });

            if (activeCase) {
                loadMyComplaints();
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 2500,
                    timerProgressBar: true,
                });
                Toast.fire({
                    icon: 'info',
                    title: 'A conciliation hearing has started.'
                });
            }
        } catch (e) {}
    }, 5000);
}

// ============================================================
// CHAIRPERSON: Set Appearance Date Modal
// ============================================================
async function openChairAppearanceModal(caseId) {
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const defDate = tomorrow.toISOString().split('T')[0];

    const result = await Swal.fire({
        title: '',
        html: `<div style="text-align:left;">
            <div class="dm-header red" style="margin:-22px -22px 18px -22px;border-radius:8px 8px 0 0;">
                <div class="dm-header-left">
                    <i class="fas fa-user-clock"></i>
                    <div>
                        <div class="dm-header-title">Set Appearance Date</div>
                        <div class="dm-header-sub">For the party who failed to appear at conciliation</div>
                    </div>
                </div>
            </div>
            <div class="handbook-quote">
                <div class="handbook-quote-title"><i class="fas fa-gavel"></i> Per RA 7160 / KP Handbook</div>
                <p>You must set a date for the absent party to appear before you and explain the reasons for their failure to appear.</p>
                <p>A <b>Notice of Hearing (Re: Failure to Appear)</b> — <b>KP Form #18</b> (complainant) or <b>KP Form #19</b> (respondent) — will be issued automatically.</p>
            </div>
            <div class="dm-grid-2">
                <div class="dm-field">
                    <label>Appearance Date <span class="req">*</span></label>
                    <input type="date" id="chairAppearDate" min="${new Date().toISOString().split('T')[0]}" value="${defDate}">
                </div>
                <div class="dm-field">
                    <label>Appearance Time <span class="req">*</span></label>
                    <input type="time" id="chairAppearTime" value="09:00" min="08:00" max="18:00">
                </div>
            </div>
            <div style="font-size:0.78rem;color:#666;margin-top:8px;padding:8px 12px;background:#f8faf8;border-radius:6px;">
                <i class="fas fa-info-circle" style="color:#2E7D32;"></i> Office hours only: <b>8:00 AM – 6:00 PM</b>.
            </div>
        </div>`,
        width: 620,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-paper-plane"></i> Set Date & Issue Notices',
        confirmButtonColor: '#c62828',
        cancelButtonColor: '#6c757d',
        allowOutsideClick: false,
        preConfirm: () => {
            const d = document.getElementById('chairAppearDate').value;
            const t = document.getElementById('chairAppearTime').value;
            if (!d || !t) { Swal.showValidationMessage('Set both date and time.'); return false; }
            return { appearance_at: d + ' ' + t };
        }
    });

    if (!result.isConfirmed || !result.value) return;

    Swal.fire({ title: 'Issuing notices…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    const fd = new FormData();
    fd.append('action', 'chair_set_appearance_date');
    fd.append('complaint_id', caseId);
    fd.append('appearance_at', result.value.appearance_at);

    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        Swal.close();
        if (!d.success) {
            Swal.fire('Error', d.message || 'Failed.', 'error');
            return;
        }

        const list = (d.notices || []).map(n =>
            `<li><b>${escapeHtml(n.name)}</b> — ${n.form_tag === 'kp18' ? 'KP Form #18' : 'KP Form #19'} ${n.ok ? '✅' : '⚠️'}</li>`
        ).join('');

        Swal.fire({
            icon: 'success',
            title: 'Appearance Date Set',
            html: `<div style="text-align:left;font-size:0.9rem;">
                <p>Set for: <b>${new Date(d.appearance_at.replace(' ','T')).toLocaleString('en-US', {dateStyle:'full', timeStyle:'short'})}</b></p>
                <div style="padding:10px 12px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.82rem;color:#b71c1c;margin-top:10px;">
                    <b>Notices issued:</b>
                    <ul style="margin-top:6px;">${list}</ul>
                </div>
                <p style="font-size:0.82rem;color:#666;margin-top:10px;">
                    All Pangkat members and the Captain have been notified.
                </p>
            </div>`,
            confirmButtonColor: '#2E7D32'
        }).then(() => {
            if (window.__hearingLock.active) refreshHearingPanel();
            else loadMyComplaints();
        });
    } catch (e) {
        Swal.close();
        Swal.fire('Error', 'Network error.', 'error');
    }
}

// ============================================================
// CHAIRPERSON: Decide Justified / Unjustified
// ============================================================
async function openChairAbsenceDecisionModal(caseId) {
    let d;
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${caseId}`);
        d = await r.json();
    } catch (e) { Swal.fire('Error', 'Could not load.', 'error'); return; }
    if (!d || !d.success) { Swal.fire('Error', d.message || 'Error', 'error'); return; }

    const c = d.complaint;
    const parties = [...(d.complainants || []), ...(d.respondents || [])];

    const hearings = (d.hearings || []).filter(h => h.session_type === 'conciliation');
    if (!hearings.length) { Swal.fire('Info', 'No conciliation hearing found.', 'info'); return; }
    const latestHearing = hearings[0];

    let att = [];
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_hearing_details&hearing_id=${latestHearing.id}`);
        const hd = await r.json();
        att = (hd.attendance || []).filter(a => a.status === 'no_show');
    } catch (e) {}

    if (!att.length) {
        Swal.fire('Info', 'No absent parties on record.', 'info');
        return;
    }

    const cards = att.map(a => {
        const party = parties.find(p => Number(p.id) === Number(a.party_id));
        const role = party ? party.party_type : 'respondent';
        const legalC = 'Complaint will be DISMISSED; complainant barred from court.';
        const legalR = 'Counterclaim barred; case proceeds to Certification.';
        const legal = (role === 'complainant') ? legalC : legalR;
        return `<div class="abs-card" data-decision-pid="${a.party_id}" data-party-type="${role}" style="background:#fff;border:1.5px solid #ef9a9a;border-left:4px solid #c62828;border-radius:12px;padding:14px 16px;margin-bottom:12px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                <div class="att-avatar ${role}" style="width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:${role === 'complainant' ? '#e8f5e9' : '#fff3e0'};color:${role === 'complainant' ? '#2E7D32' : '#ef6c00'};"><i class="fas ${role === 'complainant' ? 'fa-user' : 'fa-user-friends'}"></i></div>
                <div>
                    <div style="font-weight:700;font-size:0.92rem;color:#1a2b22;">${escapeHtml(party?.full_name || '')}</div>
                    <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.4px;font-weight:700;color:${role === 'complainant' ? '#2E7D32' : '#ef6c00'};">${escapeHtml(role)}</div>
                </div>
            </div>
            <div class="dm-field" style="margin-bottom:12px;">
                <label style="font-weight:600;font-size:0.85rem;color:#1a472a;">Reason / what they said <span style="color:#999;font-weight:400;font-size:0.75rem;">(optional)</span></label>
                <textarea rows="2" data-role="appearance_notes" placeholder="e.g. Sick, hospitalized / no reason given / etc." style="width:100%;padding:10px 12px;border:1.5px solid #cfd8dc;border-radius:8px;font-size:0.92rem;font-family:inherit;resize:vertical;"></textarea>
            </div>
            <div style="font-weight:700;font-size:0.85rem;color:#1a472a;margin:0 0 6px;">Decision</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <label class="abs-radio justified" onclick="chairPickDecision(${a.party_id}, 'justified', this)">
                    <input type="radio" name="dec-${a.party_id}" value="justified" style="display:none;">
                    <div style="width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:#e8f5e9;color:#2E7D32;"><i class="fas fa-check-circle"></i></div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:700;font-size:0.85rem;color:#1a2b22;">Justified</div>
                        <div style="font-size:0.72rem;color:#667;margin-top:2px;">Reschedule with strict warning.</div>
                    </div>
                </label>
                <label class="abs-radio unjustified" onclick="chairPickDecision(${a.party_id}, 'unjustified', this)">
                    <input type="radio" name="dec-${a.party_id}" value="unjustified" style="display:none;">
                    <div style="width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:#ffebee;color:#c62828;"><i class="fas fa-times-circle"></i></div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:700;font-size:0.85rem;color:#1a2b22;">Unjustified</div>
                        <div style="font-size:0.72rem;color:#667;margin-top:2px;">Penalties apply.</div>
                        <div style="font-size:0.68rem;color:#b71c1c;font-weight:600;margin-top:4px;padding:4px 8px;background:#ffebee;border-radius:6px;">${escapeHtml(legal)}</div>
                    </div>
                </label>
            </div>
        </div>`;
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
            <div class="handbook-quote">
                <div class="handbook-quote-title"><i class="fas fa-gavel"></i> RA 7160 — Consequences</div>
                <p><b>Complainant unjustified</b> → complaint DISMISSED, barred from court, KP #23 issued.</p>
                <p><b>Respondent unjustified</b> → counterclaim barred, KP #24 issued, case proceeds to Certification.</p>
                <p><b>All justified</b> → reschedule conciliation with strict warning.</p>
            </div>
            <div id="chairDecisionCards">${cards}</div>
        </div>`,
        width: 720,
        allowOutsideClick: false,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-gavel"></i> Confirm Decision',
        confirmButtonColor: '#c62828',
        cancelButtonText: 'Cancel',
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
                out.push({
                    party_id: pid,
                    status,
                    reason: notes,
                    appearance_notes: notes,
                    party_type: ptype,
                });
            });
            if (!allDecided) {
                Swal.showValidationMessage('Please decide for all absent parties.');
                return false;
            }
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
            if (!resp.success) {
                Swal.fire('Error', resp.message || 'Failed.', 'error');
                return;
            }

            let outcomeTitle = 'Decision Recorded';
            let outcomeMsg = '';
            if (resp.outcome === 'dismissed_complaint') {
                outcomeTitle = 'Complaint Dismissed';
                outcomeMsg = 'Complaint was DISMISSED because the complainant did not appear without justifiable cause.';
            } else if (resp.outcome === 'respondent_no_show') {
                outcomeTitle = 'Case Proceeding to Certification';
                outcomeMsg = 'The respondent did not appear without justifiable cause. The case proceeds to Certification.';
            } else {
                outcomeTitle = 'Conciliation Rescheduled';
                outcomeMsg = 'All absences were justified. The case is ready for rescheduling.';
            }

            const cert = resp.cert_info;
            const certBlock = cert ? `<div style="margin-top:12px;padding:12px 14px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.85rem;color:#b71c1c;"><b><i class="fas fa-gavel"></i> ${escapeHtml(cert.label)}</b><br>Cert No: <b>${escapeHtml(cert.cert_no)}</b></div>` : '';

            Swal.fire({
                icon: resp.outcome === 'dismissed_complaint' ? 'error' : 'info',
                title: outcomeTitle,
                html: `<div style="text-align:left;font-size:0.9rem;"><p>${escapeHtml(outcomeMsg)}</p>${certBlock}</div>`,
                confirmButtonText: 'Done',
                confirmButtonColor: '#2E7D32'
            }).then(() => {
                if (window.__hearingLock.active) {
                    refreshHearingPanel();
                } else {
                    loadMyComplaints();
                }
            });
        } catch (e) {
            Swal.close();
            Swal.fire('Error', 'Network error.', 'error');
        }
    });
}

function chairPickDecision(pid, status, el) {
    const card = el.closest('.abs-card');
    if (!card) return;
    card.querySelectorAll('.abs-radio').forEach(r => {
        r.classList.remove('selected', 'justified', 'unjustified');
        r.style.borderColor = '#e2efe8';
        r.style.background = '#fff';
    });
    el.classList.add('selected', status);
    if (status === 'justified') {
        el.style.borderColor = '#2E7D32';
        el.style.background = '#f1f8e9';
    } else {
        el.style.borderColor = '#c62828';
        el.style.background = '#ffebee';
    }
    const radio = el.querySelector('input[type=radio]');
    if (radio) radio.checked = true;
}

// ============================================================
// COMPLAINT DETAIL MODAL
// ============================================================
function toggleCdBlock(blockId) {
    const el = document.getElementById(blockId);
    if (el) el.classList.toggle('collapsed');
}

function renderConcilDeadlineBanner(c) {
    if (!c.conciliation_deadline) return '';
    const iso = c.conciliation_deadline;
    const daysRem = (c.conciliation_days_remaining !== null && c.conciliation_days_remaining !== undefined)
        ? parseInt(c.conciliation_days_remaining) : null;
    const niceDeadline = new Date(iso.replace(' ', 'T')).toLocaleDateString('en-US', {dateStyle:'long'});
    const isExpired = (daysRem !== null && daysRem <= 0);
    if (isExpired) {
        return `
            <div class="concil-deadline-banner expired">
                <div class="cd-label"><i class="fas fa-exclamation-triangle"></i> 15-Day Conciliation Period EXPIRED</div>
                <div>Ended on <b>${escapeHtml(niceDeadline)}</b></div>
            </div>`;
    }
    return `
        <div class="concil-deadline-banner">
            <div class="cd-label"><i class="fas fa-hourglass-half"></i> 15-Day Conciliation Period</div>
            <div>
                Ends on <b>${escapeHtml(niceDeadline)}</b> ·
                <span class="live-countdown" data-deadline="${escapeHtml(iso)}">
                    <span class="status-pill safe"><i class="fas fa-hourglass-half"></i> Calculating…</span>
                </span>
            </div>
        </div>`;
}

function renderEvidenceBlock(evidence) {
    if (!Array.isArray(evidence) || !evidence.length) {
        return `<div class="cd-block collapsed" id="luponEvidenceBlock"><div class="cd-block-title toggleable" onclick="toggleCdBlock('luponEvidenceBlock')"><span style="display:flex;align-items:center;gap:12px;"><i class="fas fa-paperclip"></i> Evidence (0)</span><i class="fas fa-chevron-down cd-arrow"></i></div><div class="cd-block-body"><div style="color:#999;">No evidence uploaded.</div></div></div>`;
    }
    const rows = evidence.map(e => {
        const fname = e.file_name || e.original_name || e.filename || 'evidence-file';
        const fpath = e.file_path || e.filepath || '';
        const niceDate = e.uploaded_at ? new Date(String(e.uploaded_at).replace(' ','T')).toLocaleString('en-US',{dateStyle:'medium',timeStyle:'short'}) : '';
        return `<div class="ev-row"><div class="ev-icon"><i class="fas fa-file-alt"></i></div><div class="ev-body"><div class="ev-name">${escapeHtml(fname)}</div><div class="ev-meta">${niceDate ? `<span><i class="fas fa-clock"></i> ${escapeHtml(niceDate)}</span>` : ''}${e.file_size ? `<span><i class="fas fa-hdd"></i> ${(Number(e.file_size)/1024).toFixed(1)} KB</span>` : ''}</div>${e.description ? `<div class="ev-desc">${escapeHtml(e.description)}</div>` : ''}</div>${fpath ? `<button type="button" class="ev-open" onclick="window.open('../../${escapeHtml(fpath)}','_blank')"><i class="fas fa-external-link-alt"></i> Open</button>` : ''}</div>`;
    }).join('');
    return `<div class="cd-block collapsed" id="luponEvidenceBlock"><div class="cd-block-title toggleable" onclick="toggleCdBlock('luponEvidenceBlock')"><span style="display:flex;align-items:center;gap:12px;"><i class="fas fa-paperclip"></i> Evidence (${evidence.length})</span><i class="fas fa-chevron-down cd-arrow"></i></div><div class="cd-block-body">${rows}</div></div>`;
}

function renderKpFormsBlock(kpForms) {
    if (!Array.isArray(kpForms) || !kpForms.length) {
        return `<div class="cd-block" id="luponKpFormsBlock">
            <div class="cd-block-title toggleable" onclick="toggleCdBlock('luponKpFormsBlock')">
                <span style="display:flex;align-items:center;gap:12px;">
                    <i class="fas fa-file-signature"></i> KP Forms (0)
                </span>
                <i class="fas fa-chevron-down cd-arrow"></i>
            </div>
            <div class="cd-block-body">
                <div style="color:#999;">No KP forms generated for this complaint yet.</div>
            </div>
        </div>`;
    }

    const phaseOrder = ['scheduling','mediation','constitution','conciliation','arbitration','certifications','execution'];
    const phaseLabels = {
        scheduling:     'Phase 1 — Scheduling',
        mediation:      'Phase 2 — Mediation',
        constitution:   'Phase 3 — Pangkat Constitution',
        conciliation:   'Phase 4 — Conciliation',
        arbitration:    'Phase 5 — Arbitration & Settlement',
        certifications: 'Phase 6 — Certifications',
        execution:      'Phase 7 — Execution',
    };

    const grouped = {};
    kpForms.forEach(f => {
        const p = f.phase || 'other';
        if (!grouped[p]) grouped[p] = [];
        grouped[p].push(f);
    });

    let inner = '';
    phaseOrder.forEach(phase => {
        const items = grouped[phase];
        if (!items || !items.length) return;
        inner += `<div class="kp-phase-section">
            <div class="kp-phase-header">
                <span>${escapeHtml(phaseLabels[phase] || phase)}</span>
                <span class="kp-count">${items.length}</span>
            </div>`;
        items.forEach(f => {
            const isGen = !!f.is_generated;
            const formLabel = `KP #${f.form_no} — ${escapeHtml(f.form_name)}`;
            const eventLine = escapeHtml(f.event || '');
            const issuedBy = escapeHtml(f.issued_by || '—');
            const toWhom = escapeHtml(f.to || '—');

            const hearingBadge = (f.hearing_id && f.session_no)
                ? `<span style="display:inline-flex;align-items:center;gap:4px;background:#e3f2fd;color:#1565c0;padding:2px 8px;border-radius:10px;font-size:0.68rem;font-weight:700;"><i class="fas fa-calendar"></i> Hearing #${f.session_no}</span>`
                : '';

            let notifiedBadge = '';
            if (f.form_tag === 'kp8' || f.form_tag === 'kp9' || f.form_tag === 'kp12') {
                notifiedBadge = (f.notified === true)
                    ? `<span style="display:inline-flex;align-items:center;gap:4px;background:#e8f5e9;color:#1b5e20;padding:2px 8px;border-radius:10px;font-size:0.68rem;font-weight:700;"><i class="fas fa-check"></i> Notified</span>`
                    : (f.notified === false
                        ? `<span style="display:inline-flex;align-items:center;gap:4px;background:#ffebee;color:#c62828;padding:2px 8px;border-radius:10px;font-size:0.68rem;font-weight:700;"><i class="fas fa-print"></i> Print</span>`
                        : '');
            }

            let actionBtn = '';
            if (isGen) {
                actionBtn = `<button type="button" class="kp-entry-btn open" onclick='openKpFormFromList(${JSON.stringify(f).replace(/'/g,"&#39;")})'><i class="fas fa-file-pdf"></i> Open PDF</button>`;
            } else if (f.on_demand) {
                actionBtn = `<button type="button" class="kp-entry-btn gen" onclick="generateKpFormFromList(${f.form_no}, '${f.form_tag}', ${f.mode ? `'${f.mode}'` : 'null'})"><i class="fas fa-plus-circle"></i> Generate</button>`;
            }

            inner += `<div class="kp-entry ${isGen ? 'generated' : 'not-generated'}">
                <div class="kp-entry-icon">${isGen ? '✓' : f.form_no}</div>
                <div class="kp-entry-body">
                    <div class="kp-entry-title">${formLabel} ${hearingBadge} ${notifiedBadge}</div>
                    <div class="kp-entry-event">${eventLine}</div>
                    <div class="kp-entry-meta">
                        <span><i class="fas fa-user-tie"></i> ${issuedBy}</span>
                        <span><i class="fas fa-user"></i> To: ${toWhom}</span>
                    </div>
                </div>
                <div class="kp-entry-actions">${actionBtn}</div>
            </div>`;
        });
        inner += `</div>`;
    });

    if (!inner) {
        inner = '<div style="color:#999;text-align:center;padding:20px;">No KP forms generated for this complaint yet.</div>';
    }

    return `<div class="cd-block" id="luponKpFormsBlock">
        <div class="cd-block-title toggleable" onclick="toggleCdBlock('luponKpFormsBlock')">
            <span style="display:flex;align-items:center;gap:12px;">
                <i class="fas fa-file-signature"></i> KP Forms (${kpForms.length})
            </span>
            <i class="fas fa-chevron-down cd-arrow"></i>
        </div>
        <div class="cd-block-body">${inner}</div>
    </div>`;
}

async function openComplaintDetail(id) {
    let d;
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${id}`);
        d = await r.json();
    } catch (e) { Swal.fire('Error', 'Could not load case.', 'error'); return; }
    if (!d || !d.success) { Swal.fire('Error', d.message || 'Error', 'error'); return; }

    const c = d.complaint;
    const pangkat = d.pangkat || [];
    const hearings = d.hearings || [];
    const documents = d.pangkat_documents || [];
    const updates = d.updates || [];
    const evidence = d.evidence || [];
    const kpForms = d.kp_forms || [];
    const myPangkat = d.my_pangkat || {};
    const myRole = myPangkat.my_role || null;
    const isFirst = myPangkat.is_first === true;
    const allPending = myPangkat.all_pending === true;

    const needsAssign = isFirst && allPending && (c.status === 'pangkat_constituted' || c.status === 'pangkat_scheduled');
    const isChair = myRole === 'chair';
    const isSecretary = myRole === 'secretary';
    const isMember = myRole === 'member';

    const concilHearings = hearings.filter(h => h.session_type === 'conciliation');
    const activeConcilHearing = concilHearings.find(h => h.status === 'scheduled' || (h.outcome && h.outcome !== 'cancelled'));
    const todayStr = new Date().toISOString().split('T')[0];
    const activeHearingDate = activeConcilHearing && activeConcilHearing.hearing_date
        ? String(activeConcilHearing.hearing_date).split(' ')[0]
        : null;
    const hearingIsToday = activeHearingDate && activeHearingDate <= todayStr;
    const hearingStarted = !!c.concil_hearing_started_at;

    const waitingForAppearance = !!c.concil_absence_waiting_since && !c.concil_absence_appearance_at;
    const hasAppearanceSet = !!c.concil_absence_appearance_at;
    const appearancePassed = hasAppearanceSet && new Date(c.concil_absence_appearance_at.replace(' ','T')).getTime() <= Date.now();

    const isHearingInProgress = hearingStarted && c.status === 'pangkat_scheduled' && !waitingForAppearance && !hasAppearanceSet;

    const canScheduleNow = (isChair) && (c.status === 'pangkat_constituted');
    const canStartHearing = (isChair) && (c.status === 'pangkat_scheduled') && hearingIsToday && !hearingStarted;
    const canSetAppearance = (isChair) && waitingForAppearance;
    const canDecideAbsence  = (isChair) && hasAppearanceSet && appearancePassed;
    const canUpload         = (isChair || isSecretary);
    const canGenerateKp     = (isChair || isSecretary) && !isMember;

    const headerCls = needsAssign ? 'cd-assign' : 'cd-pangkat';

    const pangkatHtml = pangkat.length ? `<div class="cd-block"><div class="cd-block-title"><i class="fas fa-balance-scale"></i> Pangkat Panel (${pangkat.length})</div>${pangkat.map((p, i) => { const isFirstMember = i === 0; const roleText = (p.role === 'pending' || !p.role) ? 'Awaiting Position Assignment' : p.role.toUpperCase(); return `<div class="cd-person" style="margin-bottom:8px;"><div class="cd-person-avatar" style="background:#f1f8e9;color:#2E7D32;"><i class="fas fa-user-tie"></i></div><div style="flex:1;"><div class="cd-person-name">${escapeHtml(p.full_name)} ${isFirstMember ? '<span style="font-size:0.7rem;color:#2E7D32;font-weight:700;">(first-selected)</span>' : ''}</div><div class="cd-person-meta"><span style="text-transform:uppercase;font-weight:700;font-size:0.7rem;color:#666;">${escapeHtml(roleText)}</span></div></div></div>`; }).join('')}</div>` : '';

    const mediationCompleted = hearings.filter(h => h.session_type === 'mediation' && (h.status === 'completed' || h.outcome));
    const concilCompleted     = hearings.filter(h => h.session_type === 'conciliation' && (h.status === 'completed' || h.outcome));
    const scheduledHearings   = hearings.filter(h => !(h.status === 'completed' || h.outcome));

    const previousMediationHtml = renderPreviousHearingsBlock(mediationCompleted, { kind: 'mediation' });
    const previousConcilHtml = renderPreviousHearingsBlock(concilCompleted, { kind: 'conciliation' });

    const scheduledHearingsHtml = scheduledHearings.length ? `<div class="cd-block"><div class="cd-block-title"><i class="fas fa-calendar-alt"></i> Scheduled Hearings (${scheduledHearings.length})</div>${scheduledHearings.map(h => { const typeLbl = h.session_type === 'mediation' ? 'Mediation Hearing' : (h.session_type === 'conciliation' ? 'Conciliation Hearing' : 'Hearing'); return `<div style="background:#fafbfa;border-left:4px solid #00838f;border-radius:10px;padding:12px 16px;margin-bottom:10px;cursor:pointer;" onclick="openHearingDetail(${parseInt(h.id)})" title="Click to view details"><div style="font-weight:700;color:#1a472a;display:flex;justify-content:space-between;align-items:center;gap:8px;"><span>${typeLbl} #${h.session_number || 1}</span><button type="button" class="prev-hearing-view-btn" onclick="event.stopPropagation(); openHearingDetail(${parseInt(h.id)})"><i class="fas fa-eye"></i> View</button></div><div style="font-size:0.8rem;color:#555;margin-top:4px;"><i class="fas fa-calendar"></i> ${new Date(h.hearing_date).toLocaleDateString('en-US',{dateStyle:'long'})} ${h.hearing_time || ''} · ${escapeHtml(h.location || 'Barangay Hall')} · ${escapeHtml((h.status || '').toUpperCase())}</div></div>`; }).join('')}</div>` : '';

    const docsHtml = documents.length ? documents.map(doc => `<div class="doc-list-row"><i class="fas fa-file-alt" style="color:#2E7D32;"></i><a href="../../${escapeHtml(doc.file_path)}" target="_blank">${escapeHtml(doc.file_name)}</a><span class="doc-meta">${escapeHtml(doc.uploaded_by_role || '')} · ${new Date(doc.created_at).toLocaleDateString()}</span>${canUpload ? `<button type="button" style="background:#dc3545;color:#fff;border:none;padding:4px 8px;border-radius:6px;font-size:0.7rem;cursor:pointer;" onclick="deleteDoc(${doc.id}, ${id})">Delete</button>` : ''}</div>`).join('') : '<div style="color:#888;font-size:0.85rem;padding:8px 0;">No documents uploaded yet.</div>';

    const bodyParts = [];
    bodyParts.push(renderConcilDeadlineBanner(c));
    if (previousMediationHtml) bodyParts.push(previousMediationHtml);
    if (previousConcilHtml) bodyParts.push(previousConcilHtml);

    bodyParts.push(`<div class="cd-block"><div class="cd-block-title"><span class="cd-num">1</span> Case Information</div><div class="cd-grid"><div class="cd-grid-item"><label>Ref</label><span>${escapeHtml(c.reference_number)}</span></div><div class="cd-grid-item"><label>Subject</label><span>${escapeHtml(c.complaint_subject || '-')}</span></div><div class="cd-grid-item"><label>Status</label><span>${(c.status || '').replace(/_/g,' ').toUpperCase()}</span></div><div class="cd-grid-item"><label>Priority</label><span>${(c.priority || 'medium').toUpperCase()}</span></div>${c.hearing_date ? `<div class="cd-grid-item"><label>Hearing Date</label><span>${new Date(c.hearing_date).toLocaleDateString('en-US',{dateStyle:'medium'})} ${escapeHtml(c.hearing_time || '')}</span></div>` : ''}</div><div class="cd-text-block"><label>Description</label><p>${escapeHtml(c.description || '-').replace(/\n/g,'<br>')}</p></div></div>`);
    bodyParts.push(`<div class="cd-block"><div class="cd-block-title"><span class="cd-num">2</span> Complainant(s)</div>${(d.complainants || []).map(p => `<div class="cd-person"><div class="cd-person-avatar" style="background:#e8f5e9;color:#2E7D32;"><i class="fas fa-user"></i></div><div><div class="cd-person-name">${escapeHtml(p.full_name)}</div><div class="cd-person-meta">${p.contact_number ? `<span><i class="fas fa-phone"></i> ${escapeHtml(p.contact_number)}</span>` : ''}</div></div></div>`).join('')}</div>`);
    bodyParts.push(`<div class="cd-block"><div class="cd-block-title"><span class="cd-num">3</span> Respondent(s)</div>${(d.respondents || []).map(p => `<div class="cd-person"><div class="cd-person-avatar" style="background:#fff3e0;color:#e65100;"><i class="fas fa-user-friends"></i></div><div><div class="cd-person-name">${escapeHtml(p.full_name)}</div></div></div>`).join('')}</div>`);
    bodyParts.push(pangkatHtml);
    if (scheduledHearingsHtml) bodyParts.push(scheduledHearingsHtml);
    bodyParts.push(`<div class="cd-block"><div class="cd-block-title"><i class="fas fa-paperclip"></i> Pangkat Documents (${documents.length})</div>${docsHtml}</div>`);
    bodyParts.push(renderEvidenceBlock(evidence));
    bodyParts.push(renderKpFormsBlock(kpForms));
    bodyParts.push(`<div class="cd-block collapsed" id="luponAuditTrailBlock"><div class="cd-block-title toggleable" onclick="toggleCdBlock('luponAuditTrailBlock')"><span style="display:flex;align-items:center;gap:12px;"><i class="fas fa-history"></i> Audit Trail (${updates.length})</span><i class="fas fa-chevron-down cd-arrow"></i></div><div class="cd-block-body">${updates.length ? updates.map(u => `<div class="cd-history-item" style="margin-bottom:8px;"><div class="cd-history-time">${new Date(u.created_at).toLocaleString()}</div><div class="cd-history-notes">${escapeHtml(u.notes || '').replace(/\n/g,'<br>')}</div><div class="cd-history-meta">${u.updated_by_role ? 'by ' + escapeHtml(u.updated_by_role) : ''}</div></div>`).join('') : '<div style="color:#999;">No history.</div>'}</div></div>`);

    const actions = [];
    if (needsAssign)      actions.push(`<button class="cd-btn assign" onclick="Swal.close(); openAssignPositionsModal(${id})"><i class="fas fa-user-tag"></i> Assign Positions</button>`);
    if (canScheduleNow)   actions.push(`<button class="cd-btn schedule" onclick="Swal.close(); openChairScheduleModal(${id})"><i class="fas fa-calendar-plus"></i> Schedule Conciliation</button>`);
    if (isHearingInProgress) actions.push(`<button class="cd-btn enter" onclick="Swal.close(); openHearingPanel(${id})"><i class="fas fa-play-circle"></i> Open Hearing</button>`);
    if (canStartHearing)  actions.push(`<button class="cd-btn start" onclick="Swal.close(); chairStartHearing(${id})"><i class="fas fa-play-circle"></i> Start Hearing</button>`);
    if (canSetAppearance) actions.push(`<button class="cd-btn" style="background:#c62828;color:#fff;" onclick="Swal.close(); openChairAppearanceModal(${id})"><i class="fas fa-user-clock"></i> Set Appearance Date</button>`);
    if (canDecideAbsence) actions.push(`<button class="cd-btn start" onclick="Swal.close(); openChairAbsenceDecisionModal(${id})"><i class="fas fa-gavel"></i> Decide on Absences</button>`);
    if (canUpload)        actions.push(`<button class="cd-btn upload" onclick="openUploadDocumentModal(${id})"><i class="fas fa-upload"></i> Upload Document</button>`);
    if (canGenerateKp)    actions.push(`<button class="cd-btn" style="background:#1565c0;color:#fff;" onclick="openCaseKpFormsModalForLupon(${id}, '${myRole}')"><i class="fas fa-file-signature"></i> Generate KP Forms</button>`);
    actions.push(`<button class="cd-btn secondary" onclick="Swal.close()">Close</button>`);

    await Swal.fire({
        title: '',
        html: `<div class="cd-modal"><div class="cd-header ${headerCls}"><div class="cd-header-left"><i class="fas fa-balance-scale"></i><div><div class="cd-title">${escapeHtml(c.title)}</div><div class="cd-sub">Ref: <b>${escapeHtml(c.reference_number)}</b></div></div></div><div class="cd-status">${(c.status || '').replace(/_/g,' ').toUpperCase()}</div></div><div class="cd-body">${bodyParts.join('')}</div><div class="cd-actions">${actions.join('')}</div></div>`,
        width: 1080,
        showConfirmButton: false,
        showCancelButton: false,
        customClass: { popup: 'cd-popup' },
        didOpen: () => { startLiveCountdowns(); }
    });
}

// ============================================================
// CASE KP FORMS — role-filtered
// ============================================================
async function openCaseKpFormsModalForLupon(complaintId, role) {
    if (!complaintId) return;
    if (role === 'member') {
        Swal.fire('Not Allowed', 'Only the Chairperson or Secretary may generate KP forms.', 'info');
        return;
    }

    Swal.fire({ title: 'Loading KP forms…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    let d;
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${complaintId}`);
        d = await r.json();
    } catch (e) { Swal.close(); Swal.fire('Error', 'Could not load.', 'error'); return; }
    Swal.close();
    if (!d || !d.success) { Swal.fire('Error', d.message || 'Error', 'error'); return; }

    const c = d.complaint;
    const kpForms = await fetchAllRelevantKpForms(complaintId);

    if (!Array.isArray(kpForms) || !kpForms.length) {
        Swal.fire({ icon: 'info', title: 'No KP Forms', text: 'No KP forms are available for this case in its current state.', confirmButtonColor: '#2E7D32' });
        return;
    }

    const allowedTagsForRole = (role === 'chair')
        ? ['kp13','kp14']
        : ['kp14','kp15','kp16','kp17','kp20','kp21','kp22','kp25','kp26','kp27'];

    window.__kpComplaintId = complaintId;

    const renderRow = (f) => {
        const isGen = !!f.is_generated;
        const formLabel = `KP #${f.form_no} — ${escapeHtml(f.form_name)}`;
        const action = isGen
            ? `<button type="button" class="kp-entry-btn open" onclick='openKpFormFromList(${JSON.stringify(f).replace(/'/g,"&#39;")})'><i class="fas fa-file-pdf"></i> Open PDF</button>`
            : `<button type="button" class="kp-entry-btn gen" onclick="generateKpFormFromList(${f.form_no}, '${f.form_tag}', ${f.mode ? `'${f.mode}'` : 'null'})"><i class="fas fa-plus-circle"></i> Generate</button>`;
        return `<div class="kp-entry ${isGen ? 'generated' : 'not-generated'}">
            <div class="kp-entry-icon">${isGen ? '✓' : f.form_no}</div>
            <div class="kp-entry-body">
                <div class="kp-entry-title">${formLabel}</div>
                <div class="kp-entry-event">${escapeHtml(f.event || '')}</div>
                <div class="kp-entry-meta">
                    <span><i class="fas fa-user-tie"></i> ${escapeHtml(f.issued_by || '—')}</span>
                </div>
            </div>
            <div class="kp-entry-actions">${action}</div>
        </div>`;
    };

    const filtered = kpForms.filter(f => allowedTagsForRole.includes(f.form_tag));

    if (!filtered.length) {
        Swal.fire({
            icon: 'info',
            title: 'No KP Forms for Your Role',
            html: `<div style="text-align:left;font-size:0.88rem;">
                <p>As <b>${role === 'chair' ? 'Chairperson' : 'Secretary'}</b>, the KP forms you can generate are not available in the case's current state.</p>
                <div style="padding:10px 12px;background:#e3f2fd;border-left:4px solid #1565c0;border-radius:8px;font-size:0.82rem;color:#0d47a1;margin-top:10px;">
                    <b>Chairperson</b> generates: Subpoena (KP #13), Agreement for Arbitration (KP #14).<br>
                    <b>Secretary</b> generates: KP #14–#17, #20–#22, #25–#27.
                </div>
            </div>`,
            confirmButtonColor: '#2E7D32'
        });
        return;
    }

    const grouped = {};
    filtered.forEach(f => {
        const p = f.phase || 'other';
        if (!grouped[p]) grouped[p] = [];
        grouped[p].push(f);
    });
    const order = ['mediation','conciliation','arbitration','certifications','execution','scheduling','constitution'];
    const labels = {
        scheduling:     'Scheduling & Notices',
        mediation:      'Mediation Outcome',
        constitution:   'Pangkat Constitution',
        conciliation:   'Conciliation Outcome',
        arbitration:    'Arbitration & Settlement',
        certifications: 'Certifications',
        execution:      'Execution',
    };

    let inner = '';
    order.forEach(phase => {
        if (!grouped[phase] || !grouped[phase].length) return;
        inner += `<div class="kp-phase-section">
            <div class="kp-phase-header">
                <span>${labels[phase] || phase}</span>
                <span class="kp-count">${grouped[phase].length}</span>
            </div>
            ${grouped[phase].map(renderRow).join('')}
        </div>`;
    });

    await Swal.fire({
        title: '',
        html: `<div class="dm-form">
            <div class="dm-header green">
                <div class="dm-header-left">
                    <i class="fas fa-file-signature"></i>
                    <div>
                        <div class="dm-header-title">KP Forms for this Case</div>
                        <div class="dm-header-sub">Ref: ${escapeHtml(c.reference_number)} · ${filtered.length} form(s) for your role</div>
                    </div>
                </div>
            </div>
            <div style="padding:22px 26px;background:#fff;max-height:74vh;overflow-y:auto;">${inner}</div>
        </div>`,
        width: 780,
        showConfirmButton: true,
        confirmButtonText: 'Close',
        confirmButtonColor: '#2E7D32'
    });
}

async function fetchAllRelevantKpForms(complaintId) {
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_case_kp_forms&id=${complaintId}`);
        const d = await r.json();
        if (d && d.success && Array.isArray(d.forms)) return d.forms;
    } catch (e) {}
    return [];
}

async function openKpFormFromList(f) {
    const tag = f.form_tag;
    if (f.pdf_path) { window.open('../../' + f.pdf_path, '_blank'); return; }
    if ((tag === 'kp8' || tag === 'kp9' || tag === 'kp12') && f.hearing_id) {
        return openHearingKpNoticePdf(f.hearing_id, tag, f.party_id);
    }
    if (tag === 'kp8' || tag === 'kp9') { if (f.party_id) return openPartyNoticePdf(f.party_id); }
    if (tag === 'kp10') return openKp10Pdf(window.__kpComplaintId, f.party_id || null);
    if (tag === 'kp11') return openKp11Pdf(window.__kpComplaintId, f.member_id);
    if (tag === 'kp18' || tag === 'kp19') return openKpFailureNoticePdf(window.__kpComplaintId, f.hearing_id, f.party_id);
    Swal.fire('Info', 'No PDF available for this form.', 'info');
}

async function generateKpFormFromList(formNo, formTag, mode) {
    const prompts = {
        kp12: () => null,
        kp13: () => null,
        kp14: () => null,
        kp15: () => Swal.fire({ title: 'Arbitration Award', input: 'textarea', inputLabel: 'Award text', showCancelButton: true }).then(r => r.isConfirmed ? { award_text: r.value } : null),
        kp16: () => Swal.fire({ title: 'Amicable Settlement', input: 'textarea', inputLabel: 'Settlement terms', showCancelButton: true }).then(r => r.isConfirmed ? { terms_text: r.value, mode: mode || 'conciliation' } : null),
        kp17: () => Swal.fire({ title: 'Repudiation', input: 'textarea', inputLabel: 'Reason (Fraud / Violence / Intimidation)', showCancelButton: true }).then(r => r.isConfirmed ? { reason: r.value } : null),
        kp20: () => null,
        kp21: () => null,
        kp22: () => null,
        kp25: () => null,
        kp26: () => null,
        kp27: () => Swal.fire({ title: 'Notice of Execution', html: `<input id="swal-obliged" class="swal2-input" placeholder="Party obliged (name)"><input id="swal-amount" class="swal2-input" placeholder="Amount">`, showCancelButton: true, preConfirm: () => ({ obliged_name: document.getElementById('swal-obliged').value, amount: document.getElementById('swal-amount').value }) }).then(r => r.isConfirmed ? r.value : null),
    };
    const promptFn = prompts[formTag];
    const extra = promptFn ? await promptFn() : null;
    if (promptFn && !extra) return;

    Swal.fire({ title: 'Generating KP…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    const fd = new FormData();
    fd.append('action', 'generate_kp_form');
    fd.append('complaint_id', window.__kpComplaintId);
    fd.append('form_tag', formTag);
    if (extra) Object.entries(extra).forEach(([k, v]) => fd.append(k, v));
    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        Swal.close();
        if (d.success && d.pdf_url) {
            window.open('../../' + d.pdf_url, '_blank');
            Swal.fire({ icon: 'success', title: 'KP Form Generated', text: `${d.form} saved.`, timer: 1600, showConfirmButton: false });
        } else { Swal.fire('Error', d.message || 'Failed.', 'error'); }
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}

async function openHearingKpNoticePdf(hearingId, formTag, partyId) {
    Swal.fire({ title: 'Loading PDF…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_hearing_kp_notices&hearing_id=${hearingId}`);
        const d = await r.json();
        Swal.close();
        if (!d.success) { Swal.fire('Error', d.message || 'Could not load.', 'error'); return; }

        let pdfUrl = null;
        if (formTag === 'kp8')  pdfUrl = d.kp8?.pdf_url;
        if (formTag === 'kp9')  pdfUrl = d.kp9?.pdf_url;
        if (formTag === 'kp12') {
            const isComplainantPdf = partyId && d.kp12?.complainant?.pdf_url;
            pdfUrl = isComplainantPdf ? d.kp12.complainant.pdf_url : (d.kp12?.respondent?.pdf_url || d.kp12?.complainant?.pdf_url);
        }
        if (pdfUrl) {
            window.open('../../' + pdfUrl, '_blank');
        } else {
            Swal.fire('Info', 'No PDF available for this hearing.', 'info');
        }
    } catch (e) {
        Swal.close();
        Swal.fire('Error', 'Network error.', 'error');
    }
}

// ============================================================
// PDF OPENERS
// ============================================================
async function openPartyNoticePdf(partyId) {
    Swal.fire({ title: 'Loading PDF…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_party_notice_pdf&party_id=${partyId}`);
        const d = await r.json();
        Swal.close();
        if (d.success && d.pdf_url) window.open('../../' + d.pdf_url, '_blank');
        else Swal.fire('Error', d.message || 'Could not open PDF.', 'error');
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}
async function openKpFailureNoticePdf(complaintId, hearingId, partyId) {
    Swal.fire({ title: 'Loading PDF…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_kp_failure_notice_pdf&complaint_id=${complaintId}&hearing_id=${hearingId}&party_id=${partyId}`);
        const d = await r.json();
        Swal.close();
        if (d.success && d.pdf_url) window.open('../../' + d.pdf_url, '_blank');
        else Swal.fire('Error', d.message || 'Could not open PDF.', 'error');
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
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
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}
async function openKp11Pdf(complaintId, memberId) {
    Swal.fire({ title: 'Loading PDF…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_kp11_pdf&complaint_id=${complaintId}&member_id=${memberId}`);
        const d = await r.json();
        Swal.close();
        if (d.success && d.pdf_url) window.open('../../' + d.pdf_url, '_blank');
        else Swal.fire('Error', d.message || 'Could not open PDF.', 'error');
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}

// ============================================================
// UPLOAD DOCUMENT
// ============================================================
async function openUploadDocumentModal(caseId) {
    Swal.fire({
        title: 'Upload Pangkat Document',
        html: `<div style="text-align:left;"><div class="dm-field"><label>File <span class="req">*</span></label><input type="file" id="uploadFile" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx"><div style="font-size:0.72rem;color:#888;margin-top:4px;">Max 10 MB · PDF, image, or Word file.</div></div><div class="dm-field"><label>Description <span style="color:#999;font-weight:400;font-size:0.75rem;">(optional)</span></label><textarea id="uploadDesc" rows="2" placeholder="e.g. Signed agreement of the parties."></textarea></div></div>`,
        width: 520,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-upload"></i> Upload',
        confirmButtonColor: '#1565c0',
        cancelButtonColor: '#6c757d',
        allowOutsideClick: false,
        preConfirm: () => {
            const f = document.getElementById('uploadFile').files[0];
            if (!f) { Swal.showValidationMessage('Choose a file.'); return false; }
            if (f.size > 10 * 1024 * 1024) { Swal.showValidationMessage('File exceeds 10 MB.'); return false; }
            return { file: f, description: document.getElementById('uploadDesc').value.trim() };
        }
    }).then(async (result) => {
        if (!result.isConfirmed || !result.value) return;
        Swal.fire({ title: 'Uploading…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const fd = new FormData();
        fd.append('action', 'lupon_upload_document');
        fd.append('complaint_id', caseId);
        fd.append('description', result.value.description);
        fd.append('document', result.value.file);
        try {
            const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
            const d = await r.json();
            Swal.close();
            if (d.success) {
                Swal.fire({ icon: 'success', title: 'Document Uploaded', text: d.document?.file_name || 'File saved.', confirmButtonColor: '#2E7D32', timer: 1800 }).then(() => openComplaintDetail(caseId));
            } else { Swal.fire('Error', d.message || 'Upload failed.', 'error'); }
        } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
    });
}

async function deleteDoc(docId, caseId) {
    const confirm = await Swal.fire({ icon: 'warning', title: 'Delete this document?', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#dc3545', cancelButtonColor: '#6c757d' });
    if (!confirm.isConfirmed) return;
    const fd = new FormData();
    fd.append('action', 'lupon_delete_document');
    fd.append('document_id', docId);
    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) Swal.fire({ icon: 'success', title: 'Deleted', timer: 1200, showConfirmButton: false }).then(() => openComplaintDetail(caseId));
        else Swal.fire('Error', d.message || 'Failed.', 'error');
    } catch (e) { Swal.fire('Error', 'Network error.', 'error'); }
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
        const confirm = await Swal.fire({ icon: 'warning', title: 'Delete notifications?', text: `${ids.length} notification(s) will be removed.`, showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#c62828', cancelButtonColor: '#6c757d' });
        if (!confirm.isConfirmed) return;
        const fd = new FormData();
        fd.append('action', 'delete_notifications');
        fd.append('notification_ids', JSON.stringify(ids));
        try {
            const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d.success) { selectAll.checked = false; fetchNotifications(); Swal.fire({ icon: 'success', title: 'Deleted', timer: 1200, showConfirmButton: false }); }
            else Swal.fire('Error', d.message || 'Failed.', 'error');
        } catch (err) { Swal.fire('Error', 'Network error.', 'error'); }
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
        const badge = document.getElementById('notifBadge');
        const list = document.getElementById('notifList');
        const bell = document.getElementById('notifBell');

        const checkedIds = new Set(
            Array.from(document.querySelectorAll('.notif-item .notif-check:checked'))
                .map(cb => String(cb.value))
        );

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
                const isChecked = checkedIds.has(String(n.id));
                return `
                <div class="notif-item ${n.is_read == 0 ? 'unread' : ''}">
                    <input type="checkbox" class="notif-check" value="${n.id}" ${isChecked ? 'checked' : ''} onclick="event.stopPropagation(); updateDeleteBtn();">
                    <div onclick="onNotifClick(${n.id}, ${n.reference_id || 'null'}, '${escapeHtml(n.reference_type || '')}')" style="display:flex;gap:10px;flex:1;cursor:pointer;">
                        <div class="notif-icon ${n.type}"><i class="fas ${iconMap[n.type]||'fa-bell'}"></i></div>
                        <div class="notif-body">
                            <div class="notif-title">${escapeHtml(n.title)}</div>
                            <div class="notif-msg">${escapeHtml(n.message)}</div>
                            <div class="notif-time">${timeAgo(n.created_at)}</div>
                        </div>
                    </div>
                </div>`;
            }).join('');
        }

        const selectAllEl = document.getElementById('notifSelectAll');
        if (selectAllEl) {
            const all = document.querySelectorAll('.notif-item .notif-check');
            const allChecked = all.length > 0 && Array.from(all).every(cb => cb.checked);
            selectAllEl.checked = allChecked;
        }

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
function onNotifClick(id, refId, refType) {
    document.getElementById('notifDropdown').classList.remove('show');
    fetch('../admin_complaint_ajax.php', { method: 'POST', body: new URLSearchParams({ action: 'mark_notification_read', notification_id: id }) }).catch(() => {});
    if (refType === 'complaint' && refId) { renderMyComplaints(); setTimeout(() => { openComplaintDetail(refId); }, 300); }
    else setTimeout(fetchNotifications, 300);
}
async function enablePush() {
    if (!swRegistration) { Swal.fire('Error', 'Push not supported.', 'error'); return false; }
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
document.addEventListener('DOMContentLoaded', async () => {
    renderDashboard();
    initNotifications();
    startAutoHearingCheck();

    // If already locked into a hearing, resume the panel for the current user
    try {
        const r = await fetch('../admin_complaint_ajax.php?action=get_lupon_lock_state');
        const d = await r.json();
        if (d && d.success && d.is_live) {
            openHearingPanel(d.complaint_id);
        }
    } catch (e) {}
});
</script>
</body>
</html>