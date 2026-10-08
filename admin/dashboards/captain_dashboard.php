<?php
// admin/dashboards/captain_dashboard.php
// ============================================================
//  CAPTAIN DASHBOARD — Unified Complaints View
//  Full flow (RA 7160 / KP Handbook):
//    • First schedule issues KP Form #8 + KP Form #9
//    • If any party is No Show → Captain sets appearance date
//      → KP Form #18 (complainant) / #19 (respondent)
//    • Absent party appears in person; Captain logs reason
//    • Captain decides Justified / Unjustified:
//         – Complainant Unjustified → KP Form #23 auto-issued → dismissed
//         – Respondent Unjustified  → KP Form #24 auto-issued → to Pangkat
//         – All Justified           → reschedule + warning
//    • Settled → KP Form #16 (mediation mode) auto-issued
//    • Pangkat constitution in two steps:
//         Step A – Set constitution meeting → issue KP Form #10 to both parties
//         Step B – After meeting date, pick 3 Lupon members
//                  → KP Form #11 issued to each chosen member
//                  → first-selected member assigns positions in Lupon Portal
// ============================================================
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

if ($adminRole !== 'captain') {
    switch($adminRole) {
        case 'secretary':   header('Location: secretary_dashboard.php'); break;
        case 'kagawad':     header('Location: kagawad_dashboard.php'); break;
        case 'lupon':       header('Location: lupon_dashboard.php'); break;
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
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM resident WHERE is_active = 1");
$stats['total_residents'] = mysqli_fetch_assoc($result)['total'];
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM document_requests WHERE status = 'pending'");
$stats['pending_approvals'] = mysqli_fetch_assoc($result)['total'];
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM resident WHERE is_verified = 1 AND is_active = 1");
$stats['verified'] = mysqli_fetch_assoc($result)['total'];
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM resident WHERE is_verified = 0 AND is_active = 1");
$stats['unverified'] = mysqli_fetch_assoc($result)['total'];

$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM complaints WHERE status = 'pending_captain_action'");
$stats['pending_action'] = mysqli_fetch_assoc($result)['total'] ?? 0;
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM complaints WHERE status IN ('for_mediation','mediation_scheduled') AND assigned_to = $admin_id");
$stats['in_mediation'] = mysqli_fetch_assoc($result)['total'] ?? 0;
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM complaints WHERE status IN ('pending_captain_review','referred_dispatched')");
$stats['referrals'] = mysqli_fetch_assoc($result)['total'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Captain Dashboard | BRITE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        <?php include '../admin.css'; ?>

        /* Documentable modal UI pattern */
        .dm-form { text-align: left; font-family: 'Segoe UI', Roboto, sans-serif; font-size: 0.9rem; }
        .dm-body { padding: 22px 26px; background: #fff; max-height: 74vh; overflow-y: auto; }
        .dm-info { background: #e3f2fd; border-left: 4px solid #1976d2; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; font-size: 0.82rem; color: #0d47a1; display: flex; gap: 10px; align-items: flex-start; }
        .dm-info > i { font-size: 1.1rem; margin-top: 2px; flex-shrink: 0; }
        .dm-info-title { font-weight: 700; margin-bottom: 4px; }
        .dm-info ul { margin: 6px 0 0 18px; padding: 0; line-height: 1.6; }
        .dm-warning { background: #fff8e1; border-left: 4px solid #f57c00; border-radius: 10px; padding: 12px 14px; font-size: 0.85rem; color: #e65100; display: flex; gap: 10px; margin-bottom: 16px; }
        .dm-warning > i { font-size: 1.4rem; flex-shrink: 0; }
        .dm-danger { background: #ffebee; border: 1px solid #ef9a9a; border-radius: 10px; padding: 12px 14px; font-size: 0.85rem; color: #b71c1c; margin-bottom: 16px; }
        .dm-field { margin-bottom: 16px; }
        .dm-field label { display: block; font-weight: 600; font-size: 0.85rem; color: #1a472a; margin-bottom: 6px; }
        .dm-field label .req { color: #c62828; }
        .dm-field label .opt { color: #999; font-weight: 400; font-size: 0.75rem; }
        .dm-field input, .dm-field select, .dm-field textarea { width: 100%; padding: 10px 12px; border: 1.5px solid #cfd8dc; border-radius: 8px; font-size: 0.92rem; font-family: inherit; box-sizing: border-box; resize: vertical; background: #fff; }
        .dm-field input:focus, .dm-field select:focus, .dm-field textarea:focus { outline: none; border-color: #2E7D32; box-shadow: 0 0 0 3px rgba(46,125,50,0.12); }
        .dm-helper { font-size: 0.75rem; color: #888; margin-top: 5px; display: flex; align-items: flex-start; gap: 6px; line-height: 1.45; }
        .dm-helper i { color: #ffb300; margin-top: 2px; flex-shrink: 0; }
        .dm-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .dm-conflict { display: none; background: #ffebee; border: 1px solid #ef9a9a; border-radius: 8px; padding: 10px 14px; color: #b71c1c; font-size: 0.82rem; margin-top: 8px; align-items: flex-start; gap: 8px; }
        .dm-conflict.show { display: flex; animation: shake 0.3s ease-in-out; }
        .dm-conflict i { margin-top: 2px; flex-shrink: 0; }
        @keyframes shake { 0%,100% { transform: translateX(0); } 25% { transform: translateX(-3px); } 75% { transform: translateX(3px); } }
        .dm-available { display: none; background: #e8f5e9; border: 1px solid #a5d6a7; border-radius: 8px; padding: 10px 14px; color: #1b5e20; font-size: 0.82rem; margin-top: 8px; align-items: flex-start; gap: 8px; }
        .dm-available.show { display: flex; }
        .dm-available i { margin-top: 2px; flex-shrink: 0; }
        .dm-header { color: #fff; padding: 18px 22px; display: flex; justify-content: space-between; align-items: center; gap: 14px; }
        .dm-header.orange { background: linear-gradient(135deg, #e65100, #f57c00 55%, #ff9800); }
        .dm-header.blue   { background: linear-gradient(135deg, #1565c0, #1976d2 55%, #42a5f5); }
        .dm-header.green  { background: linear-gradient(135deg, #1b5e20, #2E7D32 55%, #43a047); }
        .dm-header.red    { background: linear-gradient(135deg, #b71c1c, #c62828 55%, #e53935); }
        .dm-header.purple { background: linear-gradient(135deg, #6a1b9a, #8e24aa 55%, #ab47bc); }
        .dm-header-left { display: flex; align-items: center; gap: 14px; }
        .dm-header-left > i { font-size: 1.8rem; opacity: 0.9; }
        .dm-header-title { font-size: 1.15rem; font-weight: 700; }
        .dm-header-sub   { font-size: 0.8rem; opacity: 0.85; margin-top: 2px; }
        .dm-header-ref   { text-align: right; }
        .dm-header-ref-label { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 1px; opacity: 0.8; }
        .dm-header-ref-value { font-size: 0.9rem; font-weight: 700; margin-top: 2px; }
        .dm-header-pill  { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 20px; background: rgba(255,255,255,0.22); font-size: 0.72rem; font-weight: 700; letter-spacing: 0.4px; margin-top: 6px; }
        .dm-section-title { font-weight: 700; font-size: 0.9rem; color: #1a472a; margin: 22px 0 12px; display: flex; align-items: center; gap: 8px; padding-bottom: 6px; border-bottom: 1px solid #e2efe8; }
        .dm-section-title .dm-step { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 50%; background: #2E7D32; color: #fff; font-size: 0.72rem; font-weight: 700; flex-shrink: 0; }
        .dm-section-title.warn .dm-step { background: #c62828; }
        .dm-inline-rule { font-size: 0.75rem; color: #666; margin: 6px 0 12px; padding: 6px 10px; background: #f8faf8; border-radius: 6px; display: flex; align-items: center; gap: 8px; }
        .dm-inline-rule i { color: #2E7D32; }

        /* Party row */
        .dm-party { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px; background: #fafbfa; border: 1px solid #e2efe8; margin-bottom: 6px; font-size: 0.82rem; transition: all 0.15s; }
        .dm-party.no-account { background: #ffebee; border-color: #ef9a9a; border-left: 4px solid #c62828; }
        .dm-party.has-account { border-left: 4px solid #2E7D32; }
        .dm-party-name { flex: 1; font-weight: 600; color: #1a2b22; }
        .dm-party-role { padding: 2px 8px; border-radius: 10px; font-size: 0.62rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
        .dm-party-role.complainant { background: #e8f5e9; color: #2E7D32; }
        .dm-party-role.respondent  { background: #fff3e0; color: #ef6c00; }
        .dm-party-role.witness     { background: #e3f2fd; color: #1565c0; }
        .dm-account-badge { padding: 3px 9px; border-radius: 10px; font-size: 0.62rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.4px; display: inline-flex; align-items: center; gap: 4px; }
        .dm-account-badge.yes { background: #c8e6c9; color: #1b5e20; }
        .dm-account-badge.no  { background: #c62828; color: #fff; }

        .dm-option-group { display: grid; gap: 10px; }
        .dm-option-group.cols-2 { grid-template-columns: 1fr 1fr; }
        .dm-option-group.cols-3 { grid-template-columns: repeat(3, 1fr); }
        @media (max-width: 700px) { .dm-option-group.cols-2, .dm-option-group.cols-3 { grid-template-columns: 1fr; } }
        .dm-option { position: relative; display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px; border: 2px solid #e2efe8; border-radius: 12px; background: #fff; cursor: pointer; user-select: none; transition: all .18s; }
        .dm-option:hover { border-color: #a5d6a7; background: #fafff9; transform: translateY(-1px); }
        .dm-option.selected { border-color: #2E7D32; background: #f1f8e9; box-shadow: 0 0 0 3px rgba(46,125,50,0.10); }
        .dm-option.selected.warn   { border-color:#e65100; background:#fff8e1; }
        .dm-option.selected.danger { border-color:#c62828; background:#ffebee; }
        .dm-option-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; background: #f3f6f4; color: #2E7D32; }
        .dm-option-icon.success { background:#e8f5e9; color:#2E7D32; }
        .dm-option-icon.warn    { background:#fff3e0; color:#e65100; }
        .dm-option-icon.info    { background:#e3f2fd; color:#1565c0; }
        .dm-option-icon.danger  { background:#ffebee; color:#c62828; }
        .dm-option-text  { flex: 1; min-width: 0; }
        .dm-option-title { font-weight: 700; font-size: 0.9rem; color: #1a2b22; line-height: 1.3; }
        .dm-option-desc  { font-size: 0.76rem; color: #667; margin-top: 3px; line-height: 1.45; }
        .dm-option-check { position: absolute; top: 12px; right: 12px; width: 20px; height: 20px; border-radius: 50%; border: 2px solid #cfd8dc; background: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.6rem; color: transparent; transition: all .18s; }
        .dm-option.selected .dm-option-check { border-color:#2E7D32; background:#2E7D32; color:#fff; }
        .dm-option.selected.warn   .dm-option-check { border-color:#e65100; background:#e65100; }
        .dm-option.selected.danger .dm-option-check { border-color:#c62828; background:#c62828; }

        .dm-chip-group { display: flex; flex-wrap: wrap; gap: 8px; }
        .dm-chip { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 20px; border: 1.5px solid #e2efe8; background: #fff; cursor: pointer; font-size: 0.8rem; color: #555; font-weight: 500; user-select: none; transition: all .15s; }
        .dm-chip:hover { border-color: #2E7D32; color: #2E7D32; background: #f7fbf7; }
        .dm-chip.selected { background: #2E7D32; border-color: #2E7D32; color: #fff; font-weight: 600; }

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
        .att-btn:hover { transform: translateY(-1px); }
        .att-btn.attend.active  { background:#2E7D32; border-color:#2E7D32; color:#fff; }
        .att-btn.no_show.active { background:#c62828; border-color:#c62828; color:#fff; }
        .att-nudge-row { display: none; margin-top: 10px; padding: 10px 12px; background: #e3f2fd; border-radius: 8px; border-left: 3px solid #1976d2; font-size: 0.78rem; align-items: center; gap: 8px; flex-wrap: wrap; }
        .att-nudge-row.show { display: flex; }
        .att-nudge-row > span { flex: 1; color:#0d47a1; line-height: 1.4; min-width: 160px; }
        .att-nudge-btn { background:#1976d2; color:#fff; border:none; padding: 6px 12px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .att-nudge-btn:hover { background:#0d47a1; }
        .att-nudge-btn:disabled { background:#90caf9; cursor: not-allowed; }
        .att-status-pill { font-size: 0.65rem; padding: 3px 9px; border-radius: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; white-space: nowrap; }
        .att-status-pill.attend  { background:#c8e6c9; color:#1b5e20; }
        .att-status-pill.no_show { background:#ffcdd2; color:#b71c1c; }
        .att-status-pill.pending { background:#e0e0e0; color:#666; }
        .att-status-line { margin-top:12px; padding:10px 14px; background:#f8faf8; border-radius:8px; font-size:0.82rem; color:#555; }
        .att-absent-banner { display: none; margin: 14px 0 6px; padding: 12px 14px; background: #ffebee; border: 1px solid #ef9a9a; border-radius: 10px; color: #b71c1c; font-size: 0.85rem; align-items: flex-start; gap: 10px; }
        .att-absent-banner.show { display: flex; }
        .att-absent-banner i { font-size: 1.2rem; margin-top: 1px; flex-shrink: 0; }

        /* Absence decision cards */
        .abs-card { background: #fff; border: 1.5px solid #ef9a9a; border-left: 4px solid #c62828; border-radius: 12px; padding: 14px 16px; margin-bottom: 12px; }
        .abs-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .abs-name { font-weight: 700; font-size: 0.92rem; color:#1a2b22; }
        .abs-role { padding: 2px 8px; border-radius: 10px; font-size: 0.62rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
        .abs-role.complainant { background: #e8f5e9; color: #2E7D32; }
        .abs-role.respondent  { background: #fff3e0; color: #ef6c00; }

        .abs-radio-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 12px; }
        @media (max-width: 700px) { .abs-radio-row { grid-template-columns: 1fr; } }
        .abs-radio { position: relative; display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border: 2px solid #e2efe8; border-radius: 10px; background: #fff; cursor: pointer; transition: all .15s; }
        .abs-radio:hover { border-color: #a5d6a7; }
        .abs-radio.selected.justified   { border-color:#2E7D32; background:#f1f8e9; }
        .abs-radio.selected.unjustified { border-color:#c62828; background:#ffebee; }
        .abs-radio input { display: none; }
        .abs-radio-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.9rem; }
        .abs-radio.justified .abs-radio-icon   { background:#e8f5e9; color:#2E7D32; }
        .abs-radio.unjustified .abs-radio-icon { background:#ffebee; color:#c62828; }
        .abs-radio-text { flex: 1; min-width: 0; }
        .abs-radio-title { font-weight: 700; font-size: 0.85rem; color:#1a2b22; }
        .abs-radio-sub   { font-size: 0.72rem; color:#667; margin-top: 2px; }
        .abs-radio-legal { font-size: 0.68rem; color:#b71c1c; font-weight: 600; margin-top: 4px; padding: 4px 8px; background: #ffebee; border-radius: 6px; display: flex; gap: 5px; align-items: flex-start; }
        .abs-radio-legal i { margin-top: 1px; flex-shrink: 0; }
        .abs-radio-legal.pangkat-ok { background: #fff8e1; color: #e65100; }

        /* Handbook quote */
        .handbook-quote { background: #fff8e1; border: 1.5px solid #ffe0b2; border-left: 4px solid #c62828; border-radius: 10px; padding: 14px 16px; font-size: 0.82rem; color: #4e342e; line-height: 1.6; margin-bottom: 16px; }
        .handbook-quote-title { font-weight: 800; color: #b71c1c; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; font-size: 0.85rem; letter-spacing: 0.3px; text-transform: uppercase; }
        .handbook-quote p { margin: 6px 0; }

        .appear-info-banner { padding: 10px 14px; background:#fff3e0; border-left:4px solid #f57c00; border-radius:8px; margin-bottom:14px; font-size:0.85rem; color:#8a3e00; display:flex; align-items:center; gap:10px; }
        .appear-info-banner i { color:#e65100; font-size:1.1rem; }
        .appear-info-banner strong { color:#c62828; }

        /* Custom 3-button footer */
        .swal-session-footer { display: flex; gap: 10px; justify-content: space-between; padding: 14px 22px; background: #f8faf8; border-top: 1px solid #e2efe8; flex-wrap: wrap; }
        .swal-session-footer .footer-right { display: flex; gap: 10px; margin-left: auto; }
        .ses-btn { padding: 10px 20px; border-radius: 8px; font-size: 0.88rem; font-weight: 700; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all .15s; }
        .ses-btn:hover { transform: translateY(-1px); }
        .ses-btn:disabled { cursor: not-allowed; transform: none; opacity: 0.6; }
        .ses-btn.close   { background: #e0e0e0; color: #555; }
        .ses-btn.close:hover { background: #bdbdbd; color:#333; }
        .ses-btn.primary { background: #2E7D32; color: #fff; }
        .ses-btn.primary.warn { background:#e65100; }
        .ses-btn.primary.danger { background:#c62828; }
        .ses-btn.secondary { background: #546e7a; color: #fff; }

        /* Session record block */
        .session-record-block { background:#f8f9fa; border:1.5px solid #d0d7de; border-radius:10px; padding:14px 16px; margin-bottom:16px; }
        .session-record-title { font-weight:800; font-size:0.85rem; color:#1a472a; text-transform:uppercase; letter-spacing:0.3px; margin-bottom:12px; display:flex; align-items:center; gap:8px; padding-bottom:8px; border-bottom:1px solid #e2efe8; }
        .session-record-title i { color:#2E7D32; }

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
        .pstmt-audio-row { display: flex; align-items: center; gap: 8px; margin-top: 8px; flex-wrap: wrap; }
        .pstmt-audio-btn { padding: 7px 14px; border-radius: 20px; border: none; cursor: pointer; font-size: 0.78rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; transition: all .15s; }
        .pstmt-audio-btn.rec  { background: #c62828; color: #fff; }
        .pstmt-audio-btn.stop { background: #546e7a; color: #fff; }
        .pstmt-audio-btn.rec:hover  { background: #b71c1c; }
        .pstmt-audio-btn.stop:hover { background: #37474f; }
        .pstmt-audio-btn:disabled { background: #ccc; cursor: not-allowed; }
        .pstmt-audio-status { font-size: 0.75rem; color: #555; }
        .pstmt-audio-play { background: #1565c0; color: #fff; }
        .pstmt-audio-play:hover { background: #0d47a1; }
        .pstmt-notice { font-size: 0.75rem; color: #b71c1c; background: #ffebee; border-left: 3px solid #c62828; padding: 8px 12px; border-radius: 6px; margin-bottom: 12px; display: flex; gap: 8px; align-items: flex-start; }

        /* ── Party Statements — recorder UI ─────────────────────── */
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

        /* Live waveform bars while recording */
        .pstmt-wave {
            display: flex; align-items: center; gap: 3px; height: 28px; padding: 0 4px;
        }
        .pstmt-wave .bar {
            width: 3px; background: #c62828; border-radius: 2px;
            animation: waveBounce 0.9s infinite ease-in-out;
        }
        .pstmt-wave .bar:nth-child(1) { animation-delay: 0.0s; height: 8px; }
        .pstmt-wave .bar:nth-child(2) { animation-delay: 0.1s; height: 16px; }
        .pstmt-wave .bar:nth-child(3) { animation-delay: 0.2s; height: 22px; }
        .pstmt-wave .bar:nth-child(4) { animation-delay: 0.3s; height: 14px; }
        .pstmt-wave .bar:nth-child(5) { animation-delay: 0.4s; height: 20px; }
        .pstmt-wave .bar:nth-child(6) { animation-delay: 0.5s; height: 10px; }
        .pstmt-wave .bar:nth-child(7) { animation-delay: 0.6s; height: 18px; }
        @keyframes waveBounce {
            0%, 100% { transform: scaleY(0.4); opacity: 0.7; }
            50%      { transform: scaleY(1.4); opacity: 1; }
        }
        .pstmt-timer {
            font-family: 'Courier New', monospace; font-size: 0.85rem;
            font-weight: 700; color: #c62828; letter-spacing: 0.5px;
            min-width: 52px; text-align: right;
        }

        /* Preview player after stop */
        .pstmt-preview {
            display: flex; align-items: center; gap: 10px; margin-top: 10px;
            padding: 10px 12px; background: #f0f7ff;
            border-left: 4px solid #1565c0; border-radius: 10px;
            animation: previewIn 0.25s ease-out;
        }
        @keyframes previewIn {
            from { opacity: 0; transform: translateY(-4px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .pstmt-play-btn {
            width: 38px; height: 38px; border-radius: 50%; border: none; cursor: pointer;
            background: #1565c0; color: #fff; display: flex; align-items: center;
            justify-content: center; font-size: 0.9rem; flex-shrink: 0;
            transition: all .15s;
        }
        .pstmt-play-btn:hover { background: #0d47a1; transform: scale(1.05); }
        .pstmt-play-btn.playing { background: #c62828; }

        /* Playing waveform (only animates while playing) */
        .pstmt-play-wave {
            display: flex; align-items: center; gap: 3px; height: 26px;
            flex: 1; overflow: hidden;
        }
        .pstmt-play-wave .bar {
            width: 3px; background: #1565c0; border-radius: 2px;
            height: 6px;
            transition: height 0.15s ease;
        }
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
        @keyframes playWave {
            0%, 100% { transform: scaleY(0.5); opacity: 0.7; }
            50%      { transform: scaleY(1.8); opacity: 1; }
        }
        .pstmt-preview-meta {
            display: flex; flex-direction: column; gap: 2px; min-width: 0;
        }
        .pstmt-preview-label {
            font-size: 0.78rem; font-weight: 700; color: #0d47a1;
        }
        .pstmt-preview-dur {
            font-size: 0.72rem; color: #666;
        }
        .pstmt-remove-btn {
            width: 32px; height: 32px; border-radius: 50%; border: none;
            background: #ffebee; color: #c62828; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.85rem; flex-shrink: 0; transition: all .15s;
        }
        .pstmt-remove-btn:hover { background: #c62828; color: #fff; transform: scale(1.05); }
        .pstmt-required-note {
            font-size: 0.72rem; color: #b71c1c; font-weight: 600;
            margin-top: 6px; display: flex; align-items: center; gap: 5px;
        }
        .pstmt-card.err { border-color: #c62828; background: #fff5f5; }
        .pstmt-card.ok  { border-color: #a5d6a7; background: #f8fff8; }

        /* Previous mediation hearings */
        .prev-hearings-block { background:#f0f4ff; border:1.5px solid #bbdefb; border-radius:10px; margin-bottom:16px; overflow:hidden; }
        .prev-hearings-header { padding:12px 16px; background:#e3f2fd; display:flex; justify-content:space-between; align-items:center; cursor:pointer; user-select:none; font-weight:700; font-size:0.85rem; color:#0d47a1; }
        .prev-hearings-header:hover { background:#bbdefb; }
        .prev-hearings-header .toggle-icon { transition: transform 0.2s; }
        .prev-hearings-block.expanded .prev-hearings-header .toggle-icon { transform: rotate(180deg); }
        .prev-hearings-body { display:none; padding:12px 16px; background:#fff; }
        .prev-hearings-block.expanded .prev-hearings-body { display:block; }
        .prev-hearing-item { padding:10px 14px; background:#fafbfa; border-left:4px solid #1565c0; border-radius:8px; margin-bottom:8px; font-size:0.82rem; cursor:pointer; transition: all .15s; }
        .prev-hearing-item:hover { background:#eff6ff; border-left-color:#0d47a1; transform: translateX(2px); }
        .prev-hearing-item:last-child { margin-bottom:0; }
        .prev-hearing-head { font-weight:700; color:#1a472a; margin-bottom:4px; display:flex; align-items:center; justify-content:space-between; gap:8px; }
        .prev-hearing-meta { font-size:0.72rem; color:#666; display:flex; flex-wrap:wrap; gap:12px; margin-bottom:6px; }
        .prev-hearing-summary { font-size:0.8rem; color:#333; line-height:1.55; padding:6px 10px; background:#fff; border-radius:6px; border:1px dashed #d0d7de; }
        .prev-hearing-absent { font-size:0.75rem; color:#c62828; font-weight:600; }
        .prev-hearing-view-btn { font-size:0.68rem; padding:4px 10px; border-radius:6px; background:#1565c0; color:#fff; border:none; cursor:pointer; font-weight:700; display:inline-flex; align-items:center; gap:4px; }
        .prev-hearing-view-btn:hover { background:#0d47a1; }

        /* Wheel of Fortune */
        .wheel-stage { position: relative; width: 280px; height: 280px; margin: 18px auto 8px; filter: drop-shadow(0 6px 18px rgba(0,0,0,0.15)); }
        .wheel-disc { width: 100%; height: 100%; border-radius: 50%; position: relative; overflow: hidden; transition: transform 3.6s cubic-bezier(0.17, 0.67, 0.22, 1); transform: rotate(0deg); border: 6px solid #2E7D32; background: #fff; }
        .wheel-pointer { position: absolute; top: -6px; left: 50%; transform: translateX(-50%); width: 0; height: 0; border-left: 14px solid transparent; border-right: 14px solid transparent; border-top: 26px solid #e65100; z-index: 10; filter: drop-shadow(0 2px 3px rgba(0,0,0,0.3)); }
        .wheel-center { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 56px; height: 56px; border-radius: 50%; background: radial-gradient(circle at 35% 30%, #fff, #e8f5e9); border: 4px solid #2E7D32; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.75rem; color: #2E7D32; z-index: 9; text-align: center; line-height: 1; }
        .wheel-result { text-align: center; margin-top: 12px; font-size: 0.85rem; color: #1a472a; font-weight: 600; min-height: 20px; }
        .wheel-selected-list { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; justify-content: center; }
        .wheel-selected-chip { background: #e8f5e9; color: #1b5e20; padding: 6px 14px; border-radius: 20px; font-size: 0.8rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; border: 1.5px solid #a5d6a7; }
        .wheel-spin-btn { display: block; margin: 14px auto 0; padding: 12px 28px; background: linear-gradient(135deg, #e65100, #f57c00); color: #fff; border: none; border-radius: 30px; font-size: 0.95rem; font-weight: 800; cursor: pointer; box-shadow: 0 4px 14px rgba(230,81,0,0.35); transition: all .18s; }
        .wheel-spin-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(230,81,0,0.5); }
        .wheel-spin-btn:disabled { background: #ccc; cursor: not-allowed; box-shadow: none; transform: none; }

        .manual-pangkat-row { display:flex; align-items:center; gap:10px; padding:8px 10px; border-radius:6px; cursor:pointer; font-size:0.88rem; border:1px solid #eee; margin-bottom:5px; transition: all .15s; }
        .manual-pangkat-row:hover { border-color: #a5d6a7; background: #fafff9; }
        .manual-pangkat-row.checked { border-color:#a5d6a7; background:#f1f8e9; }

        /* Waiting screen */
        .waiting-card { padding: 22px; background:#f3e5f5; border-left: 5px solid #6a1b9a; border-radius: 12px; text-align: center; margin-bottom: 16px; }
        .waiting-card .wc-icon { font-size: 2.4rem; color: #6a1b9a; margin-bottom: 12px; }
        .waiting-card .wc-title { font-weight: 800; color: #4a148c; font-size: 1.05rem; margin-bottom: 6px; }
        .waiting-card .wc-date  { font-weight: 700; color: #6a1b9a; font-size: 1rem; }
        .waiting-card .wc-sub   { font-size: 0.85rem; color: #666; margin-top: 10px; line-height: 1.5; }

        .sidebar { background: linear-gradient(180deg, #2E7D32 20%, #43a047 80%); }
        .sidebar::-webkit-scrollbar-thumb { background-color: #81c784; }
        .welcome-card { background: linear-gradient(135deg, #2E7D32, #43a047); }
        .stat-icon.residents { background: #e8f5e9; color: #2e7d32; }
        .stat-icon.verified { background: #e8f5e9; color: #43e97b; }
        .stat-icon.pending { background: #fff3e0; color: #e65100; }
        .stat-icon.action { background: #e3f2fd; color: #1565c0; }
        .stat-icon.mediation { background: #f3e5f5; color: #6a1b9a; }
        .stat-icon.referral { background: #fff8e1; color: #f57c00; }
        .quick-action-btn:hover { background: #8B0000; border-color: #8B0000; }

        .unified-header { background: #fff; border-radius: 16px; padding: 22px 26px; margin-bottom: 20px; border: 1px solid #e2efe8; }
        .unified-title { font-size: 1.1rem; font-weight: 700; color: #1a472a; display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
        .unified-sub { font-size: 0.85rem; color: #666; margin-bottom: 16px; }
        .unified-chips { display: flex; gap: 8px; flex-wrap: wrap; }
        .u-chip { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 22px; border: 1.5px solid #e2efe8; background: #fff; cursor: pointer; font-size: 0.85rem; font-weight: 600; color: #555; transition: all 0.2s; }
        .u-chip:hover { border-color: #2E7D32; color: #2E7D32; }
        .u-chip.active { background: #2E7D32; border-color: #2E7D32; color: #fff; }
        .u-chip .u-count { background: rgba(255,255,255,0.3); padding: 2px 8px; border-radius: 10px; font-size: 0.72rem; font-weight: 700; }
        .u-chip:not(.active) .u-count { background: #f0f0f0; color: #666; }

        .documents-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 18px; }
        .document-card { background: white; border-radius: 14px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.06); transition: all 0.25s ease; border: 1px solid #e2efe8; display: flex; flex-direction: column; }
        .document-card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        .document-card-header { background: linear-gradient(135deg, #2E7D32, #43a047); padding: 14px 18px; color: white; display: flex; justify-content: space-between; align-items: center; min-height: 52px; }
        .document-card-header.pending  { background: linear-gradient(135deg, #e65100, #f57c00); }
        .document-card-header.mediation { background: linear-gradient(135deg, #1565c0, #1976d2); }
        .document-card-header.waiting  { background: linear-gradient(135deg, #6a1b9a, #8e24aa); }
        .document-card-header.constitution { background: linear-gradient(135deg, #4a148c, #6a1b9a); }
        .document-card-header.positions_pending { background: linear-gradient(135deg, #b71c1c, #e53935); }
        .document-card-header.positions_set { background: linear-gradient(135deg, #1b5e20, #2E7D32); }
        .document-card-header.referral { background: linear-gradient(135deg, #8e24aa, #ab47bc); }
        .document-card-header.settled  { background: linear-gradient(135deg, #2E7D32, #43a047); }
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
        .doc-action-btn.sign     { background: #2E7D32; color: white; }
        .doc-action-btn.pdf      { background: #1565c0; color: white; }
        .doc-action-btn.mediate  { background: #6a1b9a; color: white; }
        .doc-action-btn.resume   { background: #e65100; color: white; }
        .doc-action-btn.constitute { background: #4a148c; color: white; }
        .doc-action-btn.schedule { background: #e65100; color: white; }
        .doc-action-btn.locked   { background: #e0e0e0; color: #888; cursor: not-allowed; }
        .doc-action-btn.notice   { background: #00838f; color: white; }

        .status-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 12px; font-size: 0.68rem; font-weight: 700; transition: all 0.3s ease; }
        .status-pill.safe     { background: #e8f5e9; color: #2e7d32; }
        .status-pill.warning  { background: #fff8e1; color: #f57c00; }
        .status-pill.critical { background: #ffebee; color: #c62828; animation: pulseRed 1.5s infinite; }
        .status-pill.waiting  { background: #f3e5f5; color: #6a1b9a; }
        .status-pill.hearing-today { background: #e8f5e9; color: #1b5e20; border: 1px solid #a5d6a7; }
        @keyframes pulseRed { 0%, 100% { box-shadow: 0 0 0 0 rgba(198, 40, 40, 0.4); } 50% { box-shadow: 0 0 0 6px rgba(198, 40, 40, 0); } }

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

        .notice-panel { margin-top: 18px; padding: 16px; background: #f8faf8; border-radius: 12px; border: 1px solid #e2efe8; }
        .notice-panel-title { font-weight: 800; font-size: 0.9rem; color: #1a472a; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; padding-bottom: 8px; border-bottom: 2px solid #e2efe8; }
        .notice-panel-title i { color: #00838f; }
        .notice-item { display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; border-radius: 10px; margin-bottom: 8px; border-left: 4px solid; }
        .notice-item.kp8 { background: #e0f2f1; border-left-color: #00838f; }
        .notice-item.kp9 { background: #f3e5f5; border-left-color: #6a1b9a; }
        .notice-item.kp10 { background: #f3e5f5; border-left-color: #4a148c; }
        .notice-item.kp11 { background: #e8f5e9; border-left-color: #2E7D32; }
        .notice-item.kp18 { background: #fff3e0; border-left-color: #e65100; }
        .notice-item.kp19 { background: #fce4ec; border-left-color: #ad1457; }
        .notice-item.kp23 { background: #ffebee; border-left-color: #b71c1c; }
        .notice-item.kp24 { background: #ffebee; border-left-color: #c62828; }
        .notice-item.print-required { background: #ffebee !important; border-left-color: #c62828 !important; border: 2px solid #ef9a9a; }
        .notice-item-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.95rem; }
        .notice-item-icon.kp8 { background: #b2dfdb; color: #00695c; }
        .notice-item-icon.kp9 { background: #e1bee7; color: #4a148c; }
        .notice-item-icon.kp10 { background: #e1bee7; color: #4a148c; }
        .notice-item-icon.kp11 { background: #c8e6c9; color: #1b5e20; }
        .notice-item-icon.kp18 { background: #ffe0b2; color: #e65100; }
        .notice-item-icon.kp19 { background: #f8bbd0; color: #ad1457; }
        .notice-item-icon.kp23 { background: #ffcdd2; color: #b71c1c; }
        .notice-item-icon.kp24 { background: #ffcdd2; color: #b71c1c; }
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
        .notice-item-btn.kp11 { background: #2E7D32; }
        .notice-item-btn.kp18 { background: #e65100; }
        .notice-item-btn.kp19 { background: #ad1457; }
        .notice-item-btn.kp23 { background: #b71c1c; }
        .notice-item-btn.kp24 { background: #c62828; }
        .notice-item-btn:hover { opacity: 0.9; }

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

        .cd-popup { border-radius: 16px !important; padding: 0 !important; overflow: hidden; max-width: 1080px !important; width: 95vw !important; }
        .cd-popup .swal2-html-container { margin: 0 !important; padding: 0 !important; }
        .cd-popup .swal2-actions { display: none !important; }
        .cd-popup .swal2-title { display: none !important; }
        .cd-modal { text-align: left; font-family: 'Segoe UI', Roboto, sans-serif; }
        .cd-header { background: linear-gradient(135deg, #1b5e20, #2E7D32 55%, #43a047); color: #fff; padding: 18px 24px; display: flex; justify-content: space-between; align-items: center; }
        .cd-header.cd-pending { background: linear-gradient(135deg, #e65100, #f57c00 55%, #ff9800); }
        .cd-header.cd-referral { background: linear-gradient(135deg, #8e24aa, #ab47bc); }
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
        .cd-person-meta { font-size: 0.8rem; color: #555; margin-top: 4px; display: flex; flex-wrap: wrap; gap: 12px; }
        .cd-history-list { display: flex; flex-direction: column; gap: 8px; }
        .cd-history-item { background: #f8faf8; border-radius: 10px; padding: 10px 14px; border-left: 3px solid #43e97b; }
        .cd-history-time { font-size: 0.7rem; color: #999; }
        .cd-history-notes { font-size: 0.85rem; color: #1a2b22; margin-top: 3px; line-height: 1.5; }
        .cd-history-meta { font-size: 0.7rem; color: #666; margin-top: 3px; font-style: italic; }
        .cd-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; border: none; cursor: pointer; transition: all 0.15s; text-decoration: none; }
        .cd-btn.primary   { background: #2E7D32; color: #fff; }
        .cd-btn.schedule  { background: #e65100; color: #fff; }
        .cd-btn.secondary { background: #6c757d; color: #fff; }
        .cd-actions { display: flex; gap: 10px; justify-content: flex-end; padding: 14px 22px; background: #f8faf8; border-top: 1px solid #e2efe8; flex-wrap: wrap; }

        /* Evidence block */
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

        @media (max-width: 768px) {
            .documents-grid { grid-template-columns: 1fr; }
            .document-card-actions { flex-direction: column; }
            .notif-dropdown { width: 300px; }
            .cd-body { padding: 16px; }
            .cd-actions { flex-direction: column-reverse; }
            .dm-grid-2 { grid-template-columns: 1fr; }
            .wheel-stage { width: 220px; height: 220px; }
            .swal-session-footer { flex-direction: column; }
            .swal-session-footer .footer-right { margin-left: 0; width: 100%; }
            .swal-session-footer .ses-btn { flex: 1; }
        }

        .pick-banner {
            display: none;
            margin: 12px 0 4px;
            padding: 10px 14px;
            border-radius: 10px;
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border-left: 4px solid #2E7D32;
            color: #1b5e20;
            font-size: 0.85rem;
            font-weight: 700;
            align-items: center;
            gap: 8px;
            animation: pickFadeIn .35s ease-out;
        }
        .pick-banner.show { display: flex; }
        .pick-banner i { font-size: 1rem; }
        .pick-banner.warn {
            background: linear-gradient(135deg, #fff8e1, #ffe0b2);
            border-left-color: #f57c00;
            color: #e65100;
        }
        @keyframes pickFadeIn {
            from { opacity: 0; transform: translateY(-6px); }
            to   { opacity: 1; transform: translateY(0); }
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
                <div class="logo-container">
                    <img src="../../logo.jpg" alt="Barangay Logo" onerror="this.onerror=null; this.parentElement.innerHTML='<i class=\'fas fa-landmark\'></i>';">
                </div>
                <h2>BRITE</h2>
            </div>
            <div class="sidebar-sub">San Bartolome, Sto Tomas, Pampanga</div>
        </div>
        <div class="nav-menu">
            <div class="nav-item active" data-view="dashboard"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></div>
            <div class="nav-item" data-view="complaints">
                <i class="fas fa-gavel"></i><span>Complaints</span>
                <span class="pending-count" id="complaintsBadge" style="display:none;margin-left:auto;background:#e65100;color:#fff;border-radius:10px;padding:2px 8px;font-size:0.65rem;">0</span>
            </div>
            <div class="nav-item" onclick="window.location.href='residents.php'"><i class="fas fa-users"></i><span>Residents</span></div>
            <div class="nav-item" onclick="window.location.href='documents.php'"><i class="fas fa-file-alt"></i><span>Documents</span></div>
            <div class="nav-item" onclick="window.location.href='reports.php'"><i class="fas fa-chart-line"></i><span>Reports</span></div>
        </div>
        <div class="sidebar-footer">
            <div class="admin-badge">
                <div class="mini-avatar"><i class="fas fa-user-tie"></i></div>
                <div class="admin-info">
                    <h5><?php echo htmlspecialchars($admin['full_name']); ?></h5>
                    <p>Barangay Captain</p>
                </div>
            </div>
        </div>
    </aside>

    <main class="main-content">
        <div class="top-header">
            <div class="page-title"><h1>Captain's Dashboard</h1><p>Manage complaints, mediation, and referrals</p></div>

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
                    <div class="profile-text">
                        <div class="name"><?php echo htmlspecialchars($admin['full_name']); ?></div>
                        <div class="role">Barangay Captain</div>
                    </div>
                    <div class="avatar-container">
                        <?php if (!empty($admin['profile_image']) && file_exists(__DIR__ . '/../../' . $admin['profile_image'])): ?>
                            <img src="../../<?php echo htmlspecialchars($admin['profile_image']); ?>" alt="Photo"
                                 style="width:100%;height:100%;border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                            <div class="avatar-fallback"><i class="fas fa-user-tie"></i></div>
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

<script>
// ============================================================
// HELPERS
// ============================================================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
function pad2(n) { return String(n).padStart(2, '0'); }

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

// ============================================================
// LIVE COUNTDOWNS
// ============================================================
function formatDuration(ms) {
    if (ms <= 0) return { d:0,h:0,m:0,s:0,total:0 };
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
// SESSION RECORD — single summary textarea
// ============================================================
function sessionRecordBlockHtml(prefix, opts = {}) {
    const prefill = opts.prefill || '';
    return `
    <div class="session-record-block" id="${prefix}-session-record">
        <div class="session-record-title">
            <i class="fas fa-clipboard-list"></i> Mediation Hearing Record
        </div>
        <div class="dm-field" style="margin-bottom:0;">
            <label>Summary of this mediation hearing <span class="req">*</span></label>
            <textarea id="${prefix}-sr-summary" rows="3" placeholder="Summarize what happened during this mediation hearing — key points, parties' positions, decisions, and next steps.">${escapeHtml(prefill)}</textarea>
        </div>
    </div>`;
}
function collectSessionRecord(prefix) {
    const el = document.getElementById(prefix + '-sr-summary');
    return { summary: el ? el.value.trim() : '' };
}
function isSessionRecordValid(prefix) {
    const el = document.getElementById(prefix + '-sr-summary');
    return !!(el && el.value.trim());
}

// ============================================================
// PARTY STATEMENTS — Record / Preview / Playback / Deferred upload
// • Recording stays local until finalize (no server writes per take)
// • Only ONE take per party is kept — re-record replaces
// • Playback shows animated waveform + duration
// • Required: text OR audio, validated before save
// ============================================================
window.__recorders        = window.__recorders        || {};
window.__stmtBlobs        = window.__stmtBlobs        || {};
window.__stmtPlayAudio    = window.__stmtPlayAudio    || {};
window.__stmtTimers       = window.__stmtTimers       || {};
window.__stmtStartTime    = window.__stmtStartTime    || {};

function partyStatementsBlockHtml(prefix, opts = {}) {
    const csText = opts.csText || '';
    const rsText = opts.rsText || '';
    return `
    <div class="pstmt-block" id="${prefix}-pstmt-block">
        <div class="pstmt-title">
            <i class="fas fa-microphone"></i> Party Statements
            <span style="font-weight:400;font-size:0.72rem;color:#888;text-transform:none;letter-spacing:0;">
                (type OR record — required for each party)
            </span>
        </div>
        <div class="pstmt-required-note">
            <i class="fas fa-exclamation-circle"></i>
            Each party must have a typed statement or a recording before you can save.
        </div>

        ${_pstmtCardHtml(prefix, 'complainant', csText, opts)}
        ${_pstmtCardHtml(prefix, 'respondent',  rsText, opts)}
    </div>`;
}

function _pstmtCardHtml(prefix, party, text, opts) {
    const roleLc   = party;
    const sc       = party === 'complainant' ? 'cs' : 'rs';
    const label    = party === 'complainant' ? "Complainant's Statement" : "Respondent's Statement";
    const icon     = party === 'complainant' ? 'fa-user' : 'fa-user-friends';

    return `
    <div class="pstmt-card" data-party="${roleLc}" id="${prefix}-${sc}-card">
        <div class="pstmt-head">
            <div class="pstmt-avatar ${roleLc}"><i class="fas ${icon}"></i></div>
            <div>
                <div class="pstmt-name">${label}</div>
                <div class="pstmt-sub">Type below, or click Record to attach an audio statement.</div>
            </div>
        </div>

        <div class="dm-field" style="margin-bottom:0;">
            <textarea id="${prefix}-${sc}-text" rows="3"
                      placeholder="Type ${party}'s statement here…"
                      oninput="_pstmtValidateCard('${prefix}','${roleLc}')">${escapeHtml(text)}</textarea>
        </div>

        <div class="pstmt-rec-wrap" id="${prefix}-${sc}-rec-wrap">
            <button type="button" class="pstmt-rec-btn rec"
                    id="${prefix}-${sc}-rec"
                    onclick="startStatementRecording('${prefix}','${party}')">
                <i class="fas fa-circle"></i> Record
            </button>
            <button type="button" class="pstmt-rec-btn stop"
                    id="${prefix}-${sc}-stop"
                    style="display:none;"
                    onclick="stopStatementRecording('${prefix}','${party}')">
                <i class="fas fa-stop"></i> Stop
            </button>

            <div class="pstmt-wave" id="${prefix}-${sc}-wave" style="display:none;">
                <div class="bar"></div><div class="bar"></div><div class="bar"></div>
                <div class="bar"></div><div class="bar"></div><div class="bar"></div>
                <div class="bar"></div>
            </div>
            <span class="pstmt-timer" id="${prefix}-${sc}-timer" style="display:none;">00:00</span>
        </div>

        <div class="pstmt-preview" id="${prefix}-${sc}-preview" style="display:none;">
            <button type="button" class="pstmt-play-btn"
                    id="${prefix}-${sc}-play"
                    onclick="toggleStatementPlayback('${prefix}','${party}')">
                <i class="fas fa-play" id="${prefix}-${sc}-play-icon"></i>
            </button>
            <div class="pstmt-play-wave" id="${prefix}-${sc}-play-wave">
                <div class="bar"></div><div class="bar"></div><div class="bar"></div>
                <div class="bar"></div><div class="bar"></div><div class="bar"></div>
                <div class="bar"></div><div class="bar"></div><div class="bar"></div>
                <div class="bar"></div>
            </div>
            <div class="pstmt-preview-meta">
                <div class="pstmt-preview-label">Recording ready</div>
                <div class="pstmt-preview-dur" id="${prefix}-${sc}-dur">0:00</div>
            </div>
            <button type="button" class="pstmt-remove-btn"
                    id="${prefix}-${sc}-remove"
                    title="Remove recording (you can record again)"
                    onclick="removeStatementRecording('${prefix}','${party}')">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>`;
}

async function startStatementRecording(prefix, party) {
    const sc = party === 'complainant' ? 'cs' : 'rs';
    const key = prefix + '-' + party;

    if (window.__recorders[key]) return;

    if (!navigator.mediaDevices || !window.MediaRecorder) {
        Swal.fire('Not supported', 'Audio recording is not supported in this browser.', 'warning');
        return;
    }

    let stream;
    try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch (err) {
        Swal.fire('Microphone Error', 'Could not access microphone: ' + (err.name || 'unknown'), 'error');
        return;
    }

    let rec;
    try {
        rec = new MediaRecorder(stream);
    } catch (e) {
        stream.getTracks().forEach(t => t.stop());
        Swal.fire('Recorder Error', 'Could not create MediaRecorder.', 'error');
        return;
    }

    const chunks = [];
    rec.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };

    rec.onstop = () => {
        stream.getTracks().forEach(t => t.stop());
        delete window.__recorders[key];
        _pstmtStopTimer(key);

        const blob = new Blob(chunks, { type: rec.mimeType || 'audio/webm' });
        if (!blob.size) {
            _pstmtHideRecordingUi(prefix, party);
            Swal.fire('Empty recording', 'No audio was captured. Please try again.', 'warning');
            return;
        }

        if (window.__stmtBlobs[key] && window.__stmtBlobs[key].url) {
            URL.revokeObjectURL(window.__stmtBlobs[key].url);
        }

        const url = URL.createObjectURL(blob);
        window.__stmtBlobs[key] = { blob, url, dur: _pstmtDuration(blob) };
        _pstmtShowPreview(prefix, party);
        _pstmtValidateCard(prefix, party);
    };

    try { rec.start(); }
    catch (e) {
        stream.getTracks().forEach(t => t.stop());
        Swal.fire('Recorder Error', 'Could not start recording: ' + (e.name || ''), 'error');
        return;
    }

    window.__recorders[key]     = rec;
    window.__stmtStartTime[key] = Date.now();
    _pstmtShowRecordingUi(prefix, party);
    _pstmtStartTimer(prefix, party);
}

function stopStatementRecording(prefix, party) {
    const key = prefix + '-' + party;
    const rec = window.__recorders[key];
    if (!rec) return;
    if (rec.state !== 'inactive') rec.stop();
}

function _pstmtStartTimer(prefix, party) {
    const sc  = party === 'complainant' ? 'cs' : 'rs';
    const key = prefix + '-' + party;
    const el  = document.getElementById(prefix + '-' + sc + '-timer');
    if (window.__stmtTimers[key]) clearInterval(window.__stmtTimers[key]);
    window.__stmtTimers[key] = setInterval(() => {
        const start = window.__stmtStartTime[key] || Date.now();
        const secs  = Math.floor((Date.now() - start) / 1000);
        const m     = String(Math.floor(secs / 60)).padStart(2, '0');
        const s     = String(secs % 60).padStart(2, '0');
        if (el) el.textContent = m + ':' + s;
    }, 250);
}
function _pstmtStopTimer(key) {
    if (window.__stmtTimers[key]) {
        clearInterval(window.__stmtTimers[key]);
        delete window.__stmtTimers[key];
    }
}
function _pstmtShowRecordingUi(prefix, party) {
    const sc = party === 'complainant' ? 'cs' : 'rs';
    _pstmtHidePreview(prefix, party);
    document.getElementById(prefix + '-' + sc + '-rec').style.display = 'none';
    document.getElementById(prefix + '-' + sc + '-stop').style.display = 'inline-flex';
    document.getElementById(prefix + '-' + sc + '-wave').style.display = 'flex';
    const t = document.getElementById(prefix + '-' + sc + '-timer');
    t.style.display = 'inline-block';
    t.textContent   = '00:00';
}
function _pstmtHideRecordingUi(prefix, party) {
    const sc = party === 'complainant' ? 'cs' : 'rs';
    document.getElementById(prefix + '-' + sc + '-rec').style.display = 'inline-flex';
    document.getElementById(prefix + '-' + sc + '-stop').style.display = 'none';
    document.getElementById(prefix + '-' + sc + '-wave').style.display = 'none';
    document.getElementById(prefix + '-' + sc + '-timer').style.display = 'none';
}
function _pstmtDuration(blob) {
    return Math.max(1, Math.round(blob.size / 8000));
}
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
    const sc  = party === 'complainant' ? 'cs' : 'rs';
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
        audio.addEventListener('loadedmetadata', () => {
            const durEl = document.getElementById(prefix + '-' + sc + '-dur');
            if (isFinite(audio.duration) && audio.duration > 0) {
                durEl.textContent = _pstmtFmtDur(audio.duration);
            }
        });
        audio.addEventListener('ended', () => {
            _pstmtSetPlayIcon(prefix, party, false);
            document.getElementById(prefix + '-' + sc + '-play-wave').classList.remove('playing');
            document.getElementById(prefix + '-' + sc + '-play').classList.remove('playing');
        });
        audio.addEventListener('pause', () => {
            _pstmtSetPlayIcon(prefix, party, false);
            document.getElementById(prefix + '-' + sc + '-play-wave').classList.remove('playing');
            document.getElementById(prefix + '-' + sc + '-play').classList.remove('playing');
        });
        audio.addEventListener('play', () => {
            _pstmtSetPlayIcon(prefix, party, true);
            document.getElementById(prefix + '-' + sc + '-play-wave').classList.add('playing');
            document.getElementById(prefix + '-' + sc + '-play').classList.add('playing');
        });
        window.__stmtPlayAudio[key] = audio;
    }

    audio.currentTime = 0;
    audio.play().catch(err => {
        Swal.fire('Playback error', err.message || 'Could not play audio.', 'error');
    });
}
function _pstmtSetPlayIcon(prefix, party, playing) {
    const sc = party === 'complainant' ? 'cs' : 'rs';
    const icon = document.getElementById(prefix + '-' + sc + '-play-icon');
    if (icon) icon.className = playing ? 'fas fa-pause' : 'fas fa-play';
}
function _pstmtFmtDur(secs) {
    const m = Math.floor(secs / 60);
    const s = Math.floor(secs % 60);
    return m + ':' + String(s).padStart(2, '0');
}

function removeStatementRecording(prefix, party) {
    const sc  = party === 'complainant' ? 'cs' : 'rs';
    const key = prefix + '-' + party;

    if (window.__stmtPlayAudio[key]) {
        try { window.__stmtPlayAudio[key].pause(); } catch (e) {}
        delete window.__stmtPlayAudio[key];
    }
    if (window.__stmtBlobs[key] && window.__stmtBlobs[key].url) {
        URL.revokeObjectURL(window.__stmtBlobs[key].url);
    }
    delete window.__stmtBlobs[key];

    _pstmtHidePreview(prefix, party);
    document.getElementById(prefix + '-' + sc + '-rec').style.display = 'inline-flex';
    _pstmtMarkCardState(prefix, party);
    _pstmtValidateCard(prefix, party);
}

function _pstmtValidateCard(prefix, party) {
    const sc = party === 'complainant' ? 'cs' : 'rs';
    const card = document.getElementById(prefix + '-' + sc + '-card');
    if (!card) return true;
    const hasText  = (document.getElementById(prefix + '-' + sc + '-text')?.value.trim() || '').length > 0;
    const hasAudio = !!window.__stmtBlobs[prefix + '-' + party]
                  || card.dataset.serverAudio === '1';
    card.classList.toggle('err', !(hasText || hasAudio));
    card.classList.toggle('ok',  (hasText || hasAudio));
    return hasText || hasAudio;
}
function _pstmtMarkCardState(prefix, party) {
    const sc = party === 'complainant' ? 'cs' : 'rs';
    const card = document.getElementById(prefix + '-' + sc + '-card');
    if (!card) return;
    const hasText  = (document.getElementById(prefix + '-' + sc + '-text')?.value.trim() || '').length > 0;
    const hasAudio = !!window.__stmtBlobs[prefix + '-' + party];
    card.classList.toggle('err', !(hasText || hasAudio));
    card.classList.toggle('ok',  (hasText || hasAudio));
}

function validatePartyStatements(prefix) {
    const csOk = _pstmtValidateCard(prefix, 'complainant');
    const rsOk = _pstmtValidateCard(prefix, 'respondent');
    if (csOk && rsOk) return { ok: true };
    const missing = [];
    if (!csOk) missing.push("Complainant's statement");
    if (!rsOk) missing.push("Respondent's statement");
    return { ok: false, message: 'Required: ' + missing.join(' and ') + '.' };
}

async function uploadAllPendingStatements(prefix, complaintId, hearingId) {
    const results = {};
    for (const party of ['complainant', 'respondent']) {
        const key  = prefix + '-' + party;
        const data = window.__stmtBlobs[key];
        if (!data) continue;
        try {
            const fd = new FormData();
            fd.append('action', 'upload_party_statement_audio');
            fd.append('complaint_id', complaintId);
            fd.append('hearing_id', hearingId || '');
            fd.append('party_type', party);
            const ext = (data.blob.type.includes('ogg')) ? 'ogg'
                      : (data.blob.type.includes('wav')) ? 'wav'
                      : (data.blob.type.includes('mp4')) ? 'm4a'
                      : 'webm';
            fd.append('audio', data.blob, party + '-statement.' + ext);
            const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
            const d = await r.json();
            results[party] = d;
            if (d.success) {
                URL.revokeObjectURL(data.url);
                delete window.__stmtBlobs[key];
            }
        } catch (e) {
            results[party] = { success: false, message: 'Network error.' };
        }
    }
    return results;
}

function _pstmtReleaseAll(prefix) {
    ['complainant', 'respondent'].forEach(party => {
        const key = prefix + '-' + party;
        if (window.__stmtPlayAudio[key]) {
            try { window.__stmtPlayAudio[key].pause(); } catch (e) {}
            delete window.__stmtPlayAudio[key];
        }
        if (window.__stmtBlobs[key] && window.__stmtBlobs[key].url) {
            URL.revokeObjectURL(window.__stmtBlobs[key].url);
        }
        delete window.__stmtBlobs[key];
        _pstmtStopTimer(key);
        delete window.__recorders[key];
    });
}

// ============================================================
// PREVIOUS MEDIATION HEARINGS
// ============================================================
function renderPreviousHearingsBlock(previousHearings) {
    if (!Array.isArray(previousHearings) || !previousHearings.length) return '';
    const items = previousHearings.map(h => {
        const niceDate = h.hearing_date
            ? new Date(h.hearing_date).toLocaleDateString('en-US', {dateStyle:'long'})
            : '—';
        const niceTime = h.hearing_time || '';
        const attended = parseInt(h.attended || 0);
        const absent   = parseInt(h.absent || 0);
        let summaryLine = '';
        if (h.session_record && h.session_record.summary) {
            summaryLine = `<div class="prev-hearing-summary">${escapeHtml(h.session_record.summary).replace(/\n/g,'<br>')}</div>`;
        } else if (h.summary) {
            summaryLine = `<div class="prev-hearing-summary">${escapeHtml(h.summary).replace(/\n/g,'<br>')}</div>`;
        }
        const absentLine = absent > 0
            ? `<div class="prev-hearing-absent"><i class="fas fa-user-slash"></i> ${absent} no-show</div>` : '';
        const outcomeLbl = h.outcome ? String(h.outcome).replace(/_/g,' ').toUpperCase() : '—';
        return `
            <div class="prev-hearing-item" onclick="openHearingDetail(${parseInt(h.id)})" title="Click to view full details">
                <div class="prev-hearing-head">
                    <span>Mediation Hearing #${parseInt(h.session_number || 1)} — ${escapeHtml(outcomeLbl)}</span>
                    <button type="button" class="prev-hearing-view-btn" onclick="event.stopPropagation(); openHearingDetail(${parseInt(h.id)})">
                        <i class="fas fa-eye"></i> View
                    </button>
                </div>
                <div class="prev-hearing-meta">
                    <span><i class="fas fa-calendar"></i> ${escapeHtml(niceDate)} ${escapeHtml(niceTime)}</span>
                    <span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(h.location || 'Barangay Hall')}</span>
                    <span><i class="fas fa-users"></i> ${attended} attended</span>
                    ${absentLine}
                </div>
                ${summaryLine}
            </div>`;
    }).join('');

    return `
        <div class="prev-hearings-block" id="prevHearingsBlock">
            <div class="prev-hearings-header" onclick="togglePrevHearings()">
                <span><i class="fas fa-history"></i> Previous Mediation Hearings (${previousHearings.length})</span>
                <i class="fas fa-chevron-down toggle-icon"></i>
            </div>
            <div class="prev-hearings-body">
                ${items}
            </div>
        </div>`;
}
function togglePrevHearings() {
    const el = document.getElementById('prevHearingsBlock');
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
    if (!d || !d.success) {
        Swal.fire('Error', d.message || 'Could not load hearing.', 'error');
        return;
    }

    const h = d.hearing;
    const attendance = d.attendance || [];
    const parties = d.parties || [];
    const cs = d.complainant_statement || null;
    const rs = d.respondent_statement || null;

    const isMediation   = h.session_type === 'mediation';
    const isConciliation= h.session_type === 'conciliation';
    const hdHeaderCls   = isMediation ? 'mediation' : (isConciliation ? 'conciliation' : '');

    const niceDate = h.hearing_date
        ? new Date(h.hearing_date).toLocaleDateString('en-US', {dateStyle:'full'})
        : '—';
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
                            ? `<button type="button" class="notice-item-btn ${r.party_type === 'complainant' ? 'kp18' : 'kp19'}" style="margin-top:0;padding:4px 10px;font-size:0.68rem;" onclick="openKpFailureNoticePdf(${h.complaint_id}, ${h.id}, ${r.party_id})"><i class="fas fa-file-pdf"></i> KP</button>`
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
    return `
        <div class="modern-dashboard">
            <div class="welcome-card">
                <div class="welcome-content">
                    <div class="welcome-text">
                        <h2>Welcome, Captain <?php echo htmlspecialchars($admin['full_name']); ?>!</h2>
                        <p>Here's an overview of your barangay.</p>
                    </div>
                    <div class="welcome-date">
                        <i class="fas fa-calendar-alt"></i>
                        <span id="currentDate"></span>
                    </div>
                </div>
            </div>
            <div class="stats-grid-modern">
                <div class="stat-card-modern"><div class="stat-icon residents"><i class="fas fa-users"></i></div><div class="stat-info"><h3><?php echo number_format($stats['total_residents']); ?></h3><p>Total Residents</p></div></div>
                <div class="stat-card-modern"><div class="stat-icon verified"><i class="fas fa-check-circle"></i></div><div class="stat-info"><h3><?php echo number_format($stats['verified']); ?></h3><p>Verified</p></div></div>
                <div class="stat-card-modern"><div class="stat-icon pending"><i class="fas fa-clock"></i></div><div class="stat-info"><h3><?php echo number_format($stats['pending_approvals']); ?></h3><p>Pending Documents</p></div></div>
                <div class="stat-card-modern"><div class="stat-icon action"><i class="fas fa-inbox"></i></div><div class="stat-info"><h3><?php echo $stats['pending_action']; ?></h3><p>Pending Action</p></div></div>
                <div class="stat-card-modern"><div class="stat-icon mediation"><i class="fas fa-gavel"></i></div><div class="stat-info"><h3><?php echo $stats['in_mediation']; ?></h3><p>In Mediation</p></div></div>
                <div class="stat-card-modern"><div class="stat-icon referral"><i class="fas fa-file-signature"></i></div><div class="stat-info"><h3><?php echo $stats['referrals']; ?></h3><p>Referrals</p></div></div>
            </div>
            <div class="quick-actions">
                <h4><i class="fas fa-bolt"></i> Quick Actions</h4>
                <div class="action-buttons">
                    <button class="quick-action-btn" onclick="navigateTo('complaints')"><i class="fas fa-gavel"></i> Manage Complaints</button>
                    <button class="quick-action-btn" onclick="window.location.href='reports.php'"><i class="fas fa-chart-line"></i> View Reports</button>
                </div>
            </div>
        </div>
    `;
}

// ============================================================
// COMPLAINTS
// ============================================================
let currentComplaintView = 'pending';
let cachedData = { pending: [], mediation: [], pangkat: [], referrals: [], handled: [] };

async function renderComplaintsView() {
    document.getElementById('dashboardBody').innerHTML = `
        <div class="unified-header">
            <div class="unified-title"><i class="fas fa-gavel"></i> Complaint Management</div>
            <div class="unified-sub">All complaints requiring your action — review, mediate, sign.</div>
                        <div class="unified-chips">
                <button class="u-chip active" onclick="switchComplaintView('pending')" id="chip-pending"><i class="fas fa-inbox"></i> Pending Action <span class="u-count" id="count-pending">0</span></button>
                <button class="u-chip" onclick="switchComplaintView('mediation')" id="chip-mediation"><i class="fas fa-gavel"></i> Mediation <span class="u-count" id="count-mediation">0</span></button>
                <button class="u-chip" onclick="switchComplaintView('pangkat')" id="chip-pangkat"><i class="fas fa-balance-scale"></i> For Pangkat <span class="u-count" id="count-pangkat">0</span></button>
                <button class="u-chip" onclick="switchComplaintView('referrals')" id="chip-referrals"><i class="fas fa-file-signature"></i> Referrals <span class="u-count" id="count-referrals">0</span></button>
                <button class="u-chip" onclick="switchComplaintView('handled')" id="chip-handled"><i class="fas fa-check-double"></i> Handled <span class="u-count" id="count-handled">0</span></button>
            </div>
        </div>
        <div id="complaintsList"><div style="text-align:center;padding:40px;color:#999;"><i class="fas fa-spinner fa-spin fa-2x"></i><p style="margin-top:10px;">Loading…</p></div></div>
    `;
    await loadAllComplaints();
    switchComplaintView('pending');
}

async function loadAllComplaints() {
    try {
        const r = await fetch('../admin_complaint_ajax.php?action=get_captain_all_complaints');
        const d = await r.json();
        if (!d.success) throw new Error('Failed');
        cachedData.pending   = d.pending   || [];
        cachedData.mediation = d.mediation || [];
        cachedData.pangkat   = d.pangkat   || [];
        cachedData.referrals = d.referrals || [];
        cachedData.handled   = d.handled   || [];
        document.getElementById('count-pending').textContent   = cachedData.pending.length;
        document.getElementById('count-mediation').textContent = cachedData.mediation.length;
        document.getElementById('count-pangkat').textContent   = cachedData.pangkat.length;
        document.getElementById('count-referrals').textContent = cachedData.referrals.length;
        document.getElementById('count-handled').textContent   = cachedData.handled.length;
        const badge = document.getElementById('complaintsBadge');
        if (badge) {
            const total = cachedData.pending.length;
            if (total > 0) { badge.style.display = 'inline-block'; badge.textContent = total; }
            else badge.style.display = 'none';
        }
    } catch (e) { console.error(e); }
}

function switchComplaintView(view) {
    currentComplaintView = view;
    document.querySelectorAll('.u-chip').forEach(c => c.classList.remove('active'));
    document.getElementById('chip-' + view)?.classList.add('active');
    const list = cachedData[view] || [];
    const container = document.getElementById('complaintsList');
    if (!list.length) {
        const msgs = {
            pending:   { icon: 'fa-check-circle', text: 'No complaints awaiting action.' },
            mediation: { icon: 'fa-check-circle', text: 'No active mediations.' },
            pangkat:   { icon: 'fa-check-circle', text: 'No cases waiting for Pangkat.' },
            referrals: { icon: 'fa-check-circle', text: 'No referrals pending.' },
            handled:   { icon: 'fa-inbox',         text: 'You have not handled any complaints yet.' }
        };
        const m = msgs[view] || { icon: 'fa-inbox', text: 'Nothing to show.' };
        container.innerHTML = `<div style="text-align:center;padding:60px;color:#999;"><i class="fas ${m.icon} fa-3x" style="color:#c8e6c9;"></i><p style="margin-top:12px;">${m.text}</p></div>`;
        return;
    }
    let html = '<div class="documents-grid">';
    if (view === 'pending')   html += list.map(renderPendingCard).join('');
    if (view === 'mediation') html += list.map(renderMediationCard).join('');
    if (view === 'pangkat')   html += list.map(renderPangkatCard).join('');
    if (view === 'referrals') html += list.map(renderReferralCard).join('');
    if (view === 'handled')   html += list.map(renderHandledCard).join('');
    html += '</div>';
    container.innerHTML = html;
    startLiveCountdowns();
}


// ============================================================
// HANDLED CARD — complaints the captain has already finished
// ============================================================
function renderHandledCard(x) {
    const statusConfig = {
        settled:                   { icon: 'fa-handshake',        label: 'Settled',                 cls: 'settled' },
        dismissed:                 { icon: 'fa-ban',              label: 'Dismissed',               cls: 'pending' },
        certificate_issued:        { icon: 'fa-certificate',      label: 'Certificate Issued',      cls: 'settled' },
        released:                  { icon: 'fa-check-circle',     label: 'Released',                cls: 'settled' },
        failed_mediation:          { icon: 'fa-times-circle',     label: 'Mediation Failed',        cls: 'failed' },
        failed_conciliation_final: { icon: 'fa-times-circle',     label: 'Conciliation Failed',     cls: 'failed' },
        referred_dispatched:       { icon: 'fa-check-double',     label: 'Referral Dispatched',     cls: 'referral' },
    };
    const cfg = statusConfig[x.status] || { icon: 'fa-file-alt', label: (x.status || '').replace(/_/g,' '), cls: 'lupon' };

    const filedDate = x.created_at ? new Date(String(x.created_at).replace(' ','T')).toLocaleDateString('en-US', {dateStyle:'medium'}) : '—';
    const resolvedDate = x.resolved_at ? new Date(String(x.resolved_at).replace(' ','T')).toLocaleDateString('en-US', {dateStyle:'medium'}) : null;

    return `
        <div class="document-card" onclick="viewComplaint(${x.id})" style="cursor:pointer;">
            <div class="document-card-header ${cfg.cls}">
                <div class="document-title"><i class="fas ${cfg.icon}"></i><span>${escapeHtml(x.title)}</span></div>
                <div class="document-status"><i class="fas ${cfg.icon}"></i> ${cfg.label}</div>
            </div>
            <div class="document-card-body">
                <div class="document-info-row"><div class="document-info-label">Ref:</div><div class="document-info-value"><strong>${escapeHtml(x.reference_number)}</strong></div></div>
                <div class="document-info-row"><div class="document-info-label">Complainant:</div><div class="document-info-value">${escapeHtml(x.first_name || '')} ${escapeHtml(x.last_name || '')}</div></div>
                <div class="document-info-row"><div class="document-info-label">Filed:</div><div class="document-info-value">${escapeHtml(filedDate)}</div></div>
                ${resolvedDate ? `<div class="document-info-row" style="background:#e8f5e9;padding:6px 8px;border-radius:6px;margin-top:4px;">
                    <div class="document-info-value" style="color:#1b5e20;font-weight:600;font-size:0.78rem;">
                        <i class="fas fa-check-circle"></i> Resolved: ${escapeHtml(resolvedDate)}
                    </div></div>` : ''}
            </div>
            <div class="document-card-actions" onclick="event.stopPropagation()">
                <button class="doc-action-btn view" onclick="event.stopPropagation(); viewComplaint(${x.id})"><i class="fas fa-eye"></i> View</button>
            </div>
        </div>`;
}



function renderPendingCard(x) {
    return `
        <div class="document-card" onclick="viewComplaint(${x.id})" style="cursor:pointer;">
            <div class="document-card-header pending">
                <div class="document-title"><i class="fas fa-inbox"></i><span>${escapeHtml(x.title)}</span></div>
                <div class="document-status">Action Needed</div>
            </div>
            <div class="document-card-body">
                <div class="document-info-row"><div class="document-info-label">Ref:</div><div class="document-info-value"><strong>${escapeHtml(x.reference_number)}</strong></div></div>
                <div class="document-info-row"><div class="document-info-label">Complainant:</div><div class="document-info-value">${escapeHtml(x.first_name)} ${escapeHtml(x.last_name)}</div></div>
                <div class="document-info-row"><div class="document-info-label">Subject:</div><div class="document-info-value">${escapeHtml(x.complaint_subject || '-')}</div></div>
            </div>
            <div class="document-card-actions" onclick="event.stopPropagation()">
                <button class="doc-action-btn schedule" onclick="event.stopPropagation(); openScheduleModal(${x.id})"><i class="fas fa-calendar-plus"></i> Schedule Mediation</button>
                <button class="doc-action-btn view" onclick="event.stopPropagation(); viewComplaint(${x.id})"><i class="fas fa-eye"></i> View</button>
            </div>
        </div>`;
}

function renderMediationCard(x) {
    const sessionCount = x.mediation_session_count || 1;
    const hearingDate = x.hearing_date ? x.hearing_date.split(' ')[0] : null;
    const today = new Date().toISOString().split('T')[0];
    const isHearingToday = (hearingDate === today);
    const canStart = hearingDate && hearingDate <= today;
    const isWaiting = !!x.absence_appearance_at;
    const headerCls = isWaiting ? 'waiting' : 'mediation';
    const statusLabel = isWaiting ? 'Awaiting Appearance' : `Mediation Hearing #${sessionCount}`;
    let actionButtons;
    if (isWaiting) actionButtons = `<button class="doc-action-btn resume" onclick="event.stopPropagation(); openMediationSessionModal(${x.id})"><i class="fas fa-play"></i> Continue Mediation</button>`;
    else if (canStart) actionButtons = `<button class="doc-action-btn mediate" onclick="event.stopPropagation(); openMediationSessionModal(${x.id})"><i class="fas fa-play-circle"></i> Start Mediation</button>`;
    else actionButtons = `<button class="doc-action-btn locked"><i class="fas fa-lock"></i> Available ${hearingDate ? new Date(hearingDate).toLocaleDateString('en-US',{month:'short',day:'numeric'}) : 'on hearing day'}</button>`;
    const inMediation = ['for_mediation', 'mediation_scheduled'].includes(x.status);
    const deadlineIso = inMediation ? (x.mediation_deadline || null) : null;
    const appearanceIso = x.absence_appearance_at || null;
    return `
        <div class="document-card" onclick="viewComplaint(${x.id})" style="cursor:pointer;">
            <div class="document-card-header ${headerCls}">
                <div class="document-title"><i class="fas fa-gavel"></i><span>${escapeHtml(x.title)}</span></div>
                <div class="document-status">${statusLabel}</div>
            </div>
            <div class="document-card-body">
                <div class="document-info-row"><div class="document-info-label">Ref:</div><div class="document-info-value"><strong>${escapeHtml(x.reference_number)}</strong></div></div>
                <div class="document-info-row"><div class="document-info-label">Complainant:</div><div class="document-info-value">${escapeHtml(x.first_name)} ${escapeHtml(x.last_name)}</div></div>
                ${x.hearing_date ? `<div class="document-info-row" style="background:${isHearingToday ? '#e8f5e9' : '#f3e5f5'};padding:6px 8px;border-radius:6px;margin-top:4px;">
                    <div class="document-info-label" style="color:${isHearingToday ? '#1b5e20' : '#6a1b9a'};font-weight:700;width:auto;margin-right:6px;">📅 Hearing:</div>
                    <div class="document-info-value" style="color:${isHearingToday ? '#1b5e20' : '#6a1b9a'};font-weight:600;">
                        ${new Date(x.hearing_date).toLocaleDateString('en-US',{dateStyle:'medium'})} ${x.hearing_time || ''}
                        ${isHearingToday ? ' <span class="status-pill hearing-today"><i class="fas fa-bell"></i> TODAY</span>' : ''}
                    </div></div>` : ''}
                ${appearanceIso ? `<div class="document-info-row" style="background:#fff3e0;padding:6px 8px;border-radius:6px;margin-top:4px;">
                    <div class="document-info-value" style="color:#e65100;font-weight:600;font-size:0.78rem;">
                        <i class="fas fa-user-clock"></i> Appearance set: ${new Date(appearanceIso.replace(' ','T')).toLocaleString('en-US',{dateStyle:'medium',timeStyle:'short'})}
                    </div></div>` : ''}
                ${deadlineIso ? `<div class="document-info-row" style="background:#f8faf8;padding:6px 8px;border-radius:6px;margin-top:4px;">
                    <div class="document-info-label" style="color:#555;font-weight:700;width:auto;margin-right:6px;">⏳ 15-Day:</div>
                    <div class="document-info-value live-countdown" data-deadline="${escapeHtml(deadlineIso)}"><span class="status-pill safe"><i class="fas fa-hourglass-half"></i> Calculating…</span></div>
                </div>` : ''}
            </div>
            <div class="document-card-actions" onclick="event.stopPropagation()">
                ${actionButtons}
               
                <button class="doc-action-btn view" onclick="event.stopPropagation(); viewComplaint(${x.id})"><i class="fas fa-eye"></i> View</button>
            </div>
        </div>`;
}

function renderPangkatCard(x) {
    const panelCount = x.pangkat_count || 0;
    const isConstituted = panelCount === 3;
    const isScheduled = !!x.hearing_date;
    const isConstitutionScheduled = x.status === 'pangkat_constitution_scheduled';
    const constitutionIso = x.pangkat_constitution_at || null;
    let rolesPending = false;
    if (x.pangkat_summary) {
        const parts = String(x.pangkat_summary).split('||');
        for (const p of parts) {
            const seg = p.split('|');
            if (seg[1] === 'pending') { rolesPending = true; break; }
        }
    }
    let statusLabel, statusCls;
    if (isScheduled) { statusLabel = 'Scheduled'; statusCls = 'referral'; }
    else if (isConstituted && !rolesPending) { statusLabel = 'Positions Set'; statusCls = 'positions_set'; }
    else if (isConstituted && rolesPending) { statusLabel = 'Awaiting Position Assignment'; statusCls = 'positions_pending'; }
    else if (isConstitutionScheduled) { statusLabel = 'Constitution Scheduled'; statusCls = 'constitution'; }
    else { statusLabel = 'Ready for Pangkat Constitution'; statusCls = 'referral'; }
    let memberChips = '';
    if (x.pangkat_summary) {
        const parts = String(x.pangkat_summary).split('||');
        memberChips = parts.map(p => {
            const [name, role] = p.split('|');
            if (!name) return '';
            const roleLabel = (role === 'pending') ? 'Pending' : role.charAt(0).toUpperCase() + role.slice(1);
            const cls = (role === 'pending') ? 'pending' : role;
            const bg = { chair:'#c8e6c9;color:#1b5e20', secretary:'#bbdefb;color:#0d47a1', member:'#ffe0b2;color:#e65100', pending:'#fff8e1;color:#f57c00' }[cls] || '#f0f0f0;color:#666';
            return `<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;font-size:0.65rem;font-weight:700;background:${bg};margin-right:4px;margin-top:2px;">
                <i class="fas fa-user-tie"></i> ${escapeHtml(name)} · ${escapeHtml(roleLabel)}
            </span>`;
        }).join('');
    }
    return `
        <div class="document-card" onclick="viewComplaint(${x.id})" style="cursor:pointer;">
            <div class="document-card-header ${statusCls}">
                <div class="document-title"><i class="fas fa-balance-scale"></i><span>${escapeHtml(x.title)}</span></div>
                <div class="document-status">${statusLabel}</div>
            </div>
            <div class="document-card-body">
                <div class="document-info-row"><div class="document-info-label">Ref:</div><div class="document-info-value"><strong>${escapeHtml(x.reference_number)}</strong></div></div>
                <div class="document-info-row"><div class="document-info-label">Complainant:</div><div class="document-info-value">${escapeHtml(x.first_name)} ${escapeHtml(x.last_name)}</div></div>
                <div class="document-info-row"><div class="document-info-label">Hearings:</div><div class="document-info-value">${x.mediation_session_count || 1}</div></div>
                ${memberChips ? `<div style="margin-top:6px;">${memberChips}</div>` : ''}
                ${isConstitutionScheduled && constitutionIso ? `<div class="document-info-row" style="background:#f3e5f5;padding:6px 8px;border-radius:6px;margin-top:4px;">
                    <div class="document-info-value" style="color:#4a148c;font-weight:600;font-size:0.78rem;">
                        <i class="fas fa-users-cog"></i> Constitution meeting: ${new Date(constitutionIso.replace(' ','T')).toLocaleString('en-US',{dateStyle:'medium',timeStyle:'short'})}
                    </div></div>` : ''}
                ${isScheduled ? `<div class="document-info-row" style="background:#e3f2fd;padding:6px 8px;border-radius:6px;margin-top:4px;">
                    <div class="document-info-value" style="color:#1565c0;font-weight:600;font-size:0.78rem;">
                        <i class="fas fa-calendar"></i> Conciliation: ${new Date(x.hearing_date).toLocaleDateString('en-US',{dateStyle:'medium'})} ${x.hearing_time || ''}
                    </div></div>` : (isConstituted && rolesPending ? `<div class="document-info-row" style="background:#fff3e0;padding:6px 8px;border-radius:6px;margin-top:4px;">
                    <div class="document-info-value" style="color:#e65100;font-weight:600;font-size:0.78rem;">
                        <i class="fas fa-hourglass-half"></i> Waiting for first-selected member to assign positions
                    </div></div>` : (isConstituted ? `<div class="document-info-row" style="background:#e8f5e9;padding:6px 8px;border-radius:6px;margin-top:4px;">
                    <div class="document-info-value" style="color:#1b5e20;font-weight:600;font-size:0.78rem;">
                        <i class="fas fa-check-circle"></i> Chairperson will schedule the conciliation from the Lupon Portal
                    </div></div>` : ''))}
            </div>
            <div class="document-card-actions" onclick="event.stopPropagation()">
                ${isConstitutionScheduled ? `<button class="doc-action-btn constitute" onclick="event.stopPropagation(); openMediationSessionModal(${x.id})"><i class="fas fa-users-cog"></i> Continue Constitution</button>` : (x.status === 'failed_mediation' ? `<button class="doc-action-btn schedule" onclick="event.stopPropagation(); openMediationSessionModal(${x.id})"><i class="fas fa-calendar-plus"></i> Set Constitution Meeting</button>` : `<button class="doc-action-btn view" onclick="event.stopPropagation(); viewComplaint(${x.id})"><i class="fas fa-eye"></i> View</button>`)}
            </div>
        </div>`;
}

function renderReferralCard(x) {
    const statusConfig = {
        pending_captain_review: { icon: 'fa-hourglass-half', label: 'Pending Signature', cls: 'referral' },
        referred_dispatched:    { icon: 'fa-check-double',   label: 'Dispatched',        cls: 'settled' },
        released:               { icon: 'fa-check-circle',   label: 'Released',          cls: 'settled' },
        settled:                { icon: 'fa-handshake',      label: 'Settled',           cls: 'settled' },
        certificate_issued:     { icon: 'fa-certificate',    label: 'Certificate Issued', cls: 'settled' },
        dismissed:              { icon: 'fa-ban',            label: 'Dismissed',         cls: 'pending' },
    };
    const cfg = statusConfig[x.status] || { icon: 'fa-file-alt', label: x.status, cls: 'referral' };
    const isPendingSignature = x.status === 'pending_captain_review';
    const showPdf = !!x.referral_pdf_path;
    return `
        <div class="document-card" onclick="viewComplaint(${x.id})" style="cursor:pointer;">
            <div class="document-card-header ${cfg.cls}">
                <div class="document-title"><i class="fas ${cfg.icon}"></i><span>${escapeHtml(x.title)}</span></div>
                <div class="document-status"><i class="fas ${cfg.icon}"></i> ${cfg.label}</div>
            </div>
            <div class="document-card-body">
                <div class="document-info-row"><div class="document-info-label">Ref:</div><div class="document-info-value"><strong>${escapeHtml(x.reference_number)}</strong></div></div>
                <div class="document-info-row"><div class="document-info-label">Complainant:</div><div class="document-info-value">${escapeHtml(x.first_name)} ${escapeHtml(x.last_name)}</div></div>
                <div class="document-info-row"><div class="document-info-label">Refer To:</div><div class="document-info-value">${escapeHtml(x.escalation_refer_to || '—')}</div></div>
            </div>
            <div class="document-card-actions" onclick="event.stopPropagation()">
                ${showPdf ? `<button class="doc-action-btn pdf" onclick="event.stopPropagation(); window.open('../../${escapeHtml(x.referral_pdf_path)}', '_blank')"><i class="fas fa-file-pdf"></i> PDF</button>` : ''}
                ${isPendingSignature ? `<button class="doc-action-btn sign" onclick="event.stopPropagation(); openSignReferralModal(${x.id})"><i class="fas fa-signature"></i> Sign & Dispatch</button>` : `<button class="doc-action-btn view" onclick="event.stopPropagation(); viewComplaint(${x.id})"><i class="fas fa-eye"></i> View</button>`}
            </div>
        </div>`;
}

// ============================================================
// PARTY HAS ACCOUNT
// ============================================================
function partyHasAccount(p) {
    return !!(p.matched_resident_id && Number(p.matched_resident_id) > 0);
}

// ============================================================
// OPEN A STORED PARTY PDF
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

async function openBarCertPdf(complaintId, type) {
    Swal.fire({ title: 'Loading PDF…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_bar_cert_pdf&complaint_id=${complaintId}&type=${type}`);
        const d = await r.json();
        Swal.close();
        if (d.success && d.pdf_url) window.open('../../' + d.pdf_url, '_blank');
        else Swal.fire('Error', d.message || 'Could not open PDF.', 'error');
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
        if (pdfUrl) window.open('../../' + pdfUrl, '_blank');
        else Swal.fire('Info', 'No PDF available for this hearing.', 'info');
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}

// ============================================================
// KP FORMS GROUPED BY RECIPIENT (Complainant / Respondent / Pangkat / Both)
// ============================================================
function groupKpFormsByRecipient(kpForms) {
    const groups = {
        complainant: { label: 'For Complainant', icon: 'fa-user', forms: [] },
        respondent:  { label: 'For Respondent',  icon: 'fa-user-friends', forms: [] },
        pangkat:     { label: 'Pangkat Documents', icon: 'fa-balance-scale', forms: [] },
        both:        { label: 'For Both Parties', icon: 'fa-users', forms: [] },
        other:       { label: 'Other Forms', icon: 'fa-file-alt', forms: [] },
    };

    (kpForms || []).forEach(f => {
        const tag = f.form_tag || '';
        const toText = (f.to || '').toLowerCase();

        if (tag === 'kp8') {
            groups.complainant.forms.push(f);
        } else if (tag === 'kp9') {
            groups.respondent.forms.push(f);
        } else if (tag === 'kp11') {
            groups.pangkat.forms.push(f);
        } else if (tag === 'kp12') {
            if (toText.includes('complainant') && !toText.includes('both')) {
                groups.complainant.forms.push(f);
            } else if (toText.includes('respondent')) {
                groups.respondent.forms.push(f);
            } else {
                groups.both.forms.push(f);
            }
        } else if (tag === 'kp10') {
            if (toText.includes('complainant')) groups.complainant.forms.push(f);
            else if (toText.includes('respondent')) groups.respondent.forms.push(f);
            else groups.both.forms.push(f);
        } else if (tag === 'kp18') {
            groups.complainant.forms.push(f);
        } else if (tag === 'kp19') {
            groups.respondent.forms.push(f);
        } else if (toText === 'both parties' || toText === 'both') {
            groups.both.forms.push(f);
        } else if (toText.includes('complainant')) {
            groups.complainant.forms.push(f);
        } else if (toText.includes('respondent')) {
            groups.respondent.forms.push(f);
        } else if (toText.includes('pangkat')) {
            groups.pangkat.forms.push(f);
        } else {
            groups.other.forms.push(f);
        }
    });

    return groups;
}

function renderKpFormEntry(f) {
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

    const actionBtn = isGen
        ? `<button type="button" class="kp-entry-btn open" onclick='openKpFormFromList(${JSON.stringify(f).replace(/'/g,"&#39;")})'><i class="fas fa-file-pdf"></i> Open PDF</button>`
        : (f.on_demand
            ? `<button type="button" class="kp-entry-btn gen" onclick="generateKpFormFromList(${f.form_no}, '${f.form_tag}', ${f.mode ? `'${f.mode}'` : 'null'})"><i class="fas fa-plus-circle"></i> Generate</button>`
            : '');

    return `<div class="kp-entry ${isGen ? 'generated' : 'not-generated'}">
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
}

function renderKpFormsGroupedInner(kpForms) {
    const groups = groupKpFormsByRecipient(kpForms);
    const order = ['complainant', 'respondent', 'pangkat', 'both', 'other'];
    const colorMap = {
        complainant: '#2E7D32',
        respondent:  '#ef6c00',
        pangkat:     '#6a1b9a',
        both:        '#1565c0',
        other:       '#546e7a',
    };

    let html = '';
    order.forEach(key => {
        const g = groups[key];
        if (!g.forms.length) return;
        html += `<div class="kp-phase-section">
            <div class="kp-phase-header" style="border-left-color:${colorMap[key]};">
                <span><i class="fas ${g.icon}" style="color:${colorMap[key]};margin-right:6px;"></i> ${g.label}</span>
                <span class="kp-count">${g.forms.length}</span>
            </div>
            ${g.forms.map(renderKpFormEntry).join('')}
        </div>`;
    });

    if (!html) {
        html = '<div style="color:#999;text-align:center;padding:20px;">No KP forms for this complaint yet.</div>';
    }
    return html;
}

function renderKpFormsBlock(kpForms) {
    if (!Array.isArray(kpForms) || !kpForms.length) {
        return `<div class="cd-block collapsed" id="captainKpFormsBlock">
            <div class="cd-block-title toggleable" onclick="toggleCdBlock('captainKpFormsBlock')">
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

    return `<div class="cd-block" id="captainKpFormsBlock">
        <div class="cd-block-title toggleable" onclick="toggleCdBlock('captainKpFormsBlock')">
            <span style="display:flex;align-items:center;gap:12px;">
                <i class="fas fa-file-signature"></i> KP Forms (${kpForms.length})
            </span>
            <i class="fas fa-chevron-down cd-arrow"></i>
        </div>
        <div class="cd-block-body">${renderKpFormsGroupedInner(kpForms)}</div>
    </div>`;
}

function renderKpFormsList(kpForms) {
    return renderKpFormsGroupedInner(kpForms);
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
        kp15: () => Swal.fire({
            title: 'Arbitration Award',
            input: 'textarea',
            inputLabel: 'Award text',
            inputPlaceholder: 'Enter the award decision…',
            showCancelButton: true,
        }).then(r => r.isConfirmed ? { award_text: r.value } : null),
        kp16: () => Swal.fire({
            title: 'Amicable Settlement',
            input: 'textarea',
            inputLabel: 'Settlement terms',
            inputPlaceholder: 'Enter the settlement terms…',
            showCancelButton: true,
        }).then(r => r.isConfirmed ? { terms_text: r.value, mode: mode || 'mediation' } : null),
        kp17: () => Swal.fire({
            title: 'Repudiation',
            input: 'textarea',
            inputLabel: 'Reason (Fraud / Violence / Intimidation)',
            showCancelButton: true,
        }).then(r => r.isConfirmed ? { reason: r.value } : null),
        kp20: () => null,
        kp21: () => null,
        kp22: () => null,
        kp25: () => null,
        kp26: () => null,
        kp27: () => Swal.fire({
            title: 'Notice of Execution',
            html: `<input id="swal-obliged" class="swal2-input" placeholder="Party obliged (name)">
                   <input id="swal-amount" class="swal2-input" placeholder="Amount">`,
            showCancelButton: true,
            preConfirm: () => ({
                obliged_name: document.getElementById('swal-obliged').value,
                amount: document.getElementById('swal-amount').value,
            })
        }).then(r => r.isConfirmed ? r.value : null),
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
        } else {
            Swal.fire('Error', d.message || 'Failed.', 'error');
        }
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}

// ============================================================
// NOTICES DIALOG — phased KP list
// ============================================================
async function openAllNoticesDialog(complaintId) {
    window.__kpComplaintId = complaintId;
    Swal.fire({ title: 'Loading notices…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    let d;
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${complaintId}`);
        d = await r.json();
    } catch (e) { Swal.close(); Swal.fire('Error', 'Could not load.', 'error'); return; }
    Swal.close();
    if (!d || !d.success) { Swal.fire('Error', d.message || 'Error', 'error'); return; }

    const c = d.complaint;
    const kpForms = d.kp_forms || [];

    Swal.fire({
        title: '',
        html: `<div class="dm-form">
            <div class="dm-header green">
                <div class="dm-header-left">
                    <i class="fas fa-file-signature"></i>
                    <div>
                        <div class="dm-header-title">KP Forms for this Complaint</div>
                        <div class="dm-header-sub">Ref: ${escapeHtml(c.reference_number)} · ${kpForms.length} form(s)</div>
                    </div>
                </div>
            </div>
            <div class="dm-body">${renderKpFormsList(kpForms)}</div>
        </div>`,
        width: 780,
        showConfirmButton: true,
        confirmButtonText: 'Close',
        confirmButtonColor: '#2E7D32'
    });
}

// ============================================================
// CASE KP FORMS — shows only forms RELEVANT to the current status,
// lets the user Generate/Open each one.
// ============================================================
async function openCaseKpFormsModal(complaintId) {
    if (!complaintId) return;
    Swal.fire({ title: 'Loading KP forms…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    let d;
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${complaintId}`);
        d = await r.json();
    } catch (e) { Swal.close(); Swal.fire('Error', 'Could not load.', 'error'); return; }
    Swal.close();
    if (!d || !d.success) { Swal.fire('Error', d.message || 'Error', 'error'); return; }

    const c = d.complaint;
    // NOTE: the backend already filtered build_kp_forms_list to generated-only.
    // For this modal we want to show relevant phases even if not generated yet,
    // so we RELOAD the raw form list from a wider query here.
    const kpForms = await fetchAllRelevantKpForms(complaintId);
    if (!Array.isArray(kpForms) || !kpForms.length) {
        Swal.fire({
            icon: 'info',
            title: 'No KP Forms',
            text: 'No KP forms are available for this case in its current state.',
            confirmButtonColor: '#2E7D32'
        });
        return;
    }

    window.__kpComplaintId = complaintId;

    const renderRow = (f) => {
        const isGen = !!f.is_generated;
        const formLabel = `KP #${f.form_no} — ${escapeHtml(f.form_name)}`;
        const action = isGen
            ? `<button type="button" class="kp-entry-btn open"
                       onclick='openKpFormFromList(${JSON.stringify(f).replace(/'/g,"&#39;")})'>
                    <i class="fas fa-file-pdf"></i> Open PDF
               </button>`
            : `<button type="button" class="kp-entry-btn gen"
                       onclick="generateKpFormFromList(${f.form_no}, '${f.form_tag}', ${f.mode ? `'${f.mode}'` : 'null'})">
                    <i class="fas fa-plus-circle"></i> Generate
               </button>`;
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

    const grouped = {};
    kpForms.forEach(f => {
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
                        <div class="dm-header-sub">Ref: ${escapeHtml(c.reference_number)} · ${kpForms.length} form(s)</div>
                    </div>
                </div>
            </div>
            <div class="dm-body">${inner}</div>
        </div>`,
        width: 780,
        showConfirmButton: true,
        confirmButtonText: 'Close',
        confirmButtonColor: '#2E7D32'
    });
}

// Fetches all KP forms for the case WITHOUT the generated-only filter,
// then limits to the phases that matter for the current status.
async function fetchAllRelevantKpForms(complaintId) {
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_case_kp_forms&id=${complaintId}`);
        const d = await r.json();
        if (d && d.success && Array.isArray(d.forms)) return d.forms;
    } catch (e) {}
    return [];
}

// ============================================================
// APPEARANCE DATE SUB-MODAL
// ============================================================
async function openAppearanceModal(meta) {
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const defDate = tomorrow.toISOString().split('T')[0];

    const absentListHtml = meta.absent.map(p => `
        <div style="padding:8px 12px;background:#ffebee;border-left:3px solid #c62828;border-radius:6px;margin-bottom:6px;font-size:0.85rem;">
            <b>${escapeHtml(p.full_name || '—')}</b> — <span style="text-transform:uppercase;font-size:0.7rem;color:#c62828;font-weight:700;">${escapeHtml(p.role || '')}</span>
        </div>
    `).join('');

    const res = await Swal.fire({
        title: '',
        html: `
        <div class="dm-form">
            <div class="dm-header red">
                <div class="dm-header-left">
                    <i class="fas fa-user-clock"></i>
                    <div>
                        <div class="dm-header-title">Set Appearance Date</div>
                        <div class="dm-header-sub">Absent part${meta.absent.length > 1 ? 'ies' : 'y'} must come in person</div>
                    </div>
                </div>
                <div class="dm-header-ref">
                    <div class="dm-header-ref-label">Reference</div>
                    <div class="dm-header-ref-value">${escapeHtml(meta.reference_number)}</div>
                </div>
            </div>
            <div class="dm-body">
                <div class="dm-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <b>${meta.absent.length} part${meta.absent.length > 1 ? 'ies' : 'y'} did NOT appear.</b>
                        <div style="margin-top:6px;">${absentListHtml}</div>
                    </div>
                </div>
                <div class="dm-info" style="background:#fff3e0;border-left-color:#f57c00;color:#8a3e00;">
                    <i class="fas fa-gavel"></i>
                    <div>
                        <div class="dm-info-title" style="color:#c62828;">Per KP Handbook</div>
                        <div style="font-size:0.82rem;line-height:1.6;">You must <b>set a date</b> for the absent party to appear before you and explain the reasons for their failure to appear. A <b>Notice of Hearing (Re: Failure to Appear)</b> — KP Form #18 (complainant) or #19 (respondent) — will be issued automatically.</div>
                    </div>
                </div>
                <div class="dm-grid-2">
                    <div class="dm-field" style="margin-bottom:0;">
                        <label>Appearance Date <span class="req">*</span></label>
                        <input type="date" id="appearDate" min="${new Date().toISOString().split('T')[0]}" value="${defDate}">
                    </div>
                    <div class="dm-field" style="margin-bottom:0;">
                        <label>Appearance Time <span class="req">*</span></label>
                        <input type="time" id="appearTime" value="09:00" min="08:00" max="18:00">
                    </div>
                </div>
                <div class="dm-helper" style="margin-top:8px;">
                    <i class="fas fa-info-circle"></i>
                    <span>Office hours only: <b>8:00 AM to 6:00 PM</b>. The default is tomorrow, but you may choose any later date.</span>
                </div>
            </div>
        </div>`,
        width: 640,
        allowOutsideClick: false,
        allowEscapeKey: false,
        allowEnterKey: false,
        showCancelButton: false,
        showConfirmButton: false,
        didOpen: () => {
            const actions = Swal.getActions();
            const confirmBtn = Swal.getConfirmButton();
            if (confirmBtn) confirmBtn.style.display = 'none';

            const footer = document.createElement('div');
            footer.className = 'swal-session-footer';
            footer.innerHTML = `
                <button type="button" id="appearBtnCancel" class="ses-btn close">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
                <div class="footer-right">
                    <button type="button" id="appearBtnSave" class="ses-btn primary danger">
                        <i class="fas fa-check"></i> Set & Continue
                    </button>
                </div>
            `;
            const html = Swal.getHtmlContainer();
            if (html && html.parentNode) html.parentNode.insertBefore(footer, html.nextSibling);
            else actions.parentNode.insertBefore(footer, actions);

            document.getElementById('appearBtnCancel').onclick = () => {
                Swal.close();
                window.__appearanceCancelled = true;
            };
            document.getElementById('appearBtnSave').onclick = () => {
                const d = document.getElementById('appearDate').value;
                const t = document.getElementById('appearTime').value;
                if (!d) { Swal.showValidationMessage('Pick a date.'); return; }
                if (!t) { Swal.showValidationMessage('Pick a time.'); return; }
                const ts = new Date(d + 'T' + t);
                const hh = ts.getHours();
                if (hh < 8 || hh >= 18) { Swal.showValidationMessage('Time must be between 8:00 AM and 6:00 PM.'); return; }
                window.__appearanceResult = { date: d, time: t };
                Swal.close();
            };
        }
    });

    if (window.__appearanceCancelled) { window.__appearanceCancelled = false; return null; }
    return window.__appearanceResult || null;
}

// ============================================================
// MEDIATION SESSION MODAL
// ============================================================
function sessionFooter() {
    const actions = Swal.getActions();
    if (!actions) return null;
    actions.style.display = 'none';
    const nativeConfirm = Swal.getConfirmButton();
    const nativeCancel  = Swal.getCancelButton();
    if (nativeConfirm) nativeConfirm.style.display = 'none';
    if (nativeCancel)  nativeCancel.style.display  = 'none';

    let footer = document.getElementById('sesCustomFooter');
    if (!footer) {
        footer = document.createElement('div');
        footer.id = 'sesCustomFooter';
        footer.className = 'swal-session-footer';
        footer.innerHTML = `
            <button type="button" id="sesBtnClose" class="ses-btn close" onclick="attemptSessionClose()">
                <i class="fas fa-times"></i> Close Hearing
            </button>
            <div class="footer-right">
                <button type="button" id="sesBtnSecondary" class="ses-btn secondary" style="display:none;"></button>
                <button type="button" id="sesBtnPrimary" class="ses-btn primary" onclick="sesBtnPrimaryClick()">
                    <i class="fas fa-arrow-right"></i> Continue
                </button>
            </div>
        `;
        const html = Swal.getHtmlContainer();
        if (html && html.parentNode) html.parentNode.insertBefore(footer, html.nextSibling);
        else actions.parentNode.insertBefore(footer, actions);
    }
    return footer;
}

function sesBtnPrimaryClick() {
    const step = window.__step || 'attendance';
    if (step === 'attendance')           return onAttContinue();
    if (step === 'absence_decision')     return onDecisionConfirm();
    if (step === 'result')               return onResultContinue();
    if (step === 'settlement_terms')     return onSaveSettlement();
    if (step === 'no_settlement')        return onNotSettledContinue();
    if (step === 'schedule_next')        return onSaveScheduleNext();
    if (step === 'constitute_pangkat')   return onSaveConstitutionMeeting();
    if (step === 'pangkat_members' || step === 'pangkat_constitution_waiting') {
        return onSaveConstitutePangkat();
    }
}

function setFooterButtons(opts) {
    const footer = sessionFooter();
    if (!footer) return;
    const btnClose  = document.getElementById('sesBtnClose');
    const btnPrim   = document.getElementById('sesBtnPrimary');
    const btnSecond = document.getElementById('sesBtnSecondary');
    if (!btnClose || !btnPrim || !btnSecond) return;

    btnClose.style.display = (opts.hideClose ? 'none' : '');
    btnClose.disabled = !!opts.disableClose;

    if (opts.primaryLabel) { btnPrim.innerHTML = opts.primaryLabel; btnPrim.style.display = ''; }
    else btnPrim.style.display = 'none';
    btnPrim.disabled = !!opts.disablePrimary;
    btnPrim.className = 'ses-btn primary' + (opts.primaryVariant ? ' ' + opts.primaryVariant : '');

    if (opts.secondaryLabel) {
        btnSecond.innerHTML = opts.secondaryLabel;
        btnSecond.style.display = '';
        btnSecond.disabled = !!opts.disableSecondary;
        btnSecond.onclick = opts.secondaryHandler || null;
    } else { btnSecond.style.display = 'none'; btnSecond.onclick = null; }
}

async function openMediationSessionModal(id, opts = {}) {
    window.__sessionSaved = false;

    let resume = { step: 'attendance', waiting: false, appearance_at: null, hearing_id: 0, session_no: 1, case_status: '', attendance: [], constitution: null, previous_hearings: [] };
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_mediation_resume_state&complaint_id=${id}`);
        const d = await r.json();
        if (d.success) resume = d;
    } catch (e) { Swal.fire('Error', 'Could not load session state.', 'error'); return; }

    let complaint = null, parties = [], hearing = null;
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_attendance_state&id=${id}`);
        const d = await r.json();
        if (d.success) { complaint = d.complaint; parties = d.parties || []; hearing = d.hearing || null; }
    } catch (e) { Swal.fire('Error', 'Could not load complaint.', 'error'); return; }
    if (!complaint) { Swal.fire('Error', 'Not found.', 'error'); return; }

    const daysRem = (complaint.mediation_days_remaining !== null && complaint.mediation_days_remaining !== undefined) ? parseInt(complaint.mediation_days_remaining) : null;
    const deadlineNice = complaint.mediation_deadline ? new Date(complaint.mediation_deadline).toLocaleDateString('en-US', {dateStyle:'long'}) : '';
    const sessionCount = complaint.mediation_session_count || 1;
    const isExpired = (daysRem !== null && daysRem <= 0);

    const justificationByPid = {};
    (resume.attendance || []).forEach(a => {
        justificationByPid[a.party_id] = {
            reason: a.justification_reason || '',
            status: a.justification_status || null,
            no_response: Number(a.no_response_declared) === 1,
            appeared: a.appeared_at ? true : false,
            appearance_notes: a.appearance_notes || '',
            kp_pdf: a.kp18_or_19_pdf_path || null,
        };
    });

    let startStep = resume.step;
    if (opts.startStep) startStep = opts.startStep;

    const headerHtml = `<div class="dm-header purple"><div class="dm-header-left"><i class="fas fa-play-circle"></i><div><div class="dm-header-title">Mediation Hearing</div><div class="dm-header-sub">Mediation Hearing #${sessionCount}${hearing && hearing.hearing_date ? ' · ' + new Date(hearing.hearing_date).toLocaleDateString('en-US',{dateStyle:'medium'}) : ''}</div></div></div><div class="dm-header-ref"><div class="dm-header-ref-label">Reference</div><div class="dm-header-ref-value">${escapeHtml(complaint.reference_number)}</div></div></div>`;

    const deadlinePill = (daysRem !== null) ? `<div style="display:flex;justify-content:flex-end;margin-bottom:14px;"><span class="dm-header-pill" style="background:${isExpired ? '#c62828' : (daysRem <= 7 ? '#e65100' : '#2E7D32')};color:#fff;"><i class="fas fa-hourglass-half"></i> ${isExpired ? '15-day period EXPIRED' : daysRem + ' day(s) left · ' + deadlineNice}</span></div>` : '';

    const constitutionAt = resume.constitution && resume.constitution.constitution_at;
    const canConstitute  = resume.constitution && resume.constitution.can_constitute;
    const constitutionNice = constitutionAt ? new Date(constitutionAt.replace(' ','T')).toLocaleString('en-US', {dateStyle:'full', timeStyle:'short'}) : '';

    const previousHearingsHtml = renderPreviousHearingsBlock(resume.previous_hearings || []);

    const bodyHtml = `
        ${headerHtml}
        <div class="dm-body">
            ${deadlinePill}
            ${previousHearingsHtml}

            <div id="step-attendance" style="display:none;">
                <div class="dm-section-title"><span class="dm-step">1</span> Attendance</div>
                <div id="attCardsHost"></div>
                <div class="att-absent-banner" id="absentBanner"><i class="fas fa-exclamation-triangle"></i><div><b>Some parties did not show up.</b><br>You will be asked to set a date for them to appear in person and explain.</div></div>
                <div id="attendanceStatusLine" class="att-status-line"><i class="fas fa-info-circle"></i> Mark all parties to continue.</div>
            </div>

            <div id="step-decision" style="display:none;">
                <div class="dm-section-title warn"><span class="dm-step">2</span> Absence Decision</div>
                <div id="appearanceInfoBox" style="margin-bottom:14px;"></div>
                <div class="handbook-quote"><div class="handbook-quote-title"><i class="fas fa-gavel"></i> RA 7160 — Consequences of Non-Appearance</div><p><b>IF THE COMPLAINANT CANNOT APPEAR BEFORE YOU WITHOUT JUSTIFIABLE CAUSE, HIS/HER COMPLAINT WILL BE DISMISSED AND EVENTUALLY HE/SHE CANNOT FILE A CASE IN COURT. HE CAN ALSO BE PUNISHED/REPRIMANDED FOR INDIRECT CONTEMPT.</b></p><p><b>HOWEVER, IF THE RESPONDENT CANNOT ALSO APPEAR WITHOUT JUSTIFIABLE CAUSE, HIS/HER COUNTERCLAIM IF THERE IS ANY, WILL BE DISMISSED AND HE WILL BE BARRED FROM FILING IN COURT AND BE PUNISHED FOR INDIRECT CONTEMPT OF COURT.</b></p></div>
                <div id="decisionCardsHost"></div>
                ${sessionRecordBlockHtml('dec')}
            </div>

            <div id="step-result" style="display:none;">
                <div class="dm-section-title"><span class="dm-step">3</span> Result</div>
                <div id="step-result-pstmt-host"></div>
                <div class="dm-option-group cols-2" id="medResultGroup">
                    <div class="dm-option" data-value="1" onclick="selectMedResult('1')"><div class="dm-option-icon success"><i class="fas fa-handshake"></i></div><div class="dm-option-text"><div class="dm-option-title">Settlement Reached</div><div class="dm-option-desc">Case closed with agreement.</div></div><div class="dm-option-check"><i class="fas fa-check"></i></div></div>
                    <div class="dm-option" data-value="0" onclick="selectMedResult('0')"><div class="dm-option-icon warn"><i class="fas fa-hourglass-half"></i></div><div class="dm-option-text"><div class="dm-option-title">No Settlement</div><div class="dm-option-desc">Continue or end mediation.</div></div><div class="dm-option-check"><i class="fas fa-check"></i></div></div>
                </div>
                <input type="hidden" id="medSettled" value="">
            </div>

            <div id="step-settlement" style="display:none;">
                <div class="dm-section-title"><span class="dm-step">3A</span> Settlement Terms</div>
                <div class="dm-field" style="margin-bottom:0;"><label>Terms <span class="req">*</span></label><textarea id="medSettleSummary" rows="4" placeholder="e.g. Payment in two installments..."></textarea></div>
                ${sessionRecordBlockHtml('set')}
            </div>

            <div id="step-nosettle" style="display:none;">
                <div class="dm-section-title"><span class="dm-step">3B</span> Why not settled?</div>
                <div class="dm-chip-group" id="medReasonGroup">
                    ${['No amicable settlement reached','Parties requested another mediation hearing','Further discussion needed','Parties considering a proposed settlement','One or both parties requested additional time'].map((r, i) => `<div class="dm-chip${i===0?' selected':''}" data-value="${escapeHtml(r)}" onclick="selectMedReason(this)">${escapeHtml(r)}</div>`).join('')}
                </div>
                <input type="hidden" id="medReason" value="No amicable settlement reached">
                <div class="dm-field" style="margin-top:18px;"><label>Hearing Notes <span class="req">*</span></label><textarea id="medNoSummary" rows="3" placeholder="Describe what happened..."></textarea></div>
                ${sessionRecordBlockHtml('noset')}
                ${!isExpired ? `<div class="dm-section-title" style="margin-top:22px;"><span class="dm-step">4</span> What's next?</div><div class="dm-option-group cols-2" id="medNextGroup"><div class="dm-option" data-value="schedule" onclick="selectMedNext('schedule')"><div class="dm-option-icon info"><i class="fas fa-calendar-plus"></i></div><div class="dm-option-text"><div class="dm-option-title">Schedule Another Hearing</div></div><div class="dm-option-check"><i class="fas fa-check"></i></div></div><div class="dm-option" data-value="end" onclick="selectMedNext('end')"><div class="dm-option-icon danger"><i class="fas fa-balance-scale"></i></div><div class="dm-option-text"><div class="dm-option-title">End Mediation → Pangkat</div></div><div class="dm-option-check"><i class="fas fa-check"></i></div></div></div><input type="hidden" id="medNextAction" value="schedule">` : `<input type="hidden" id="medNextAction" value="end">`}
            </div>

            <div id="step-schednext" style="display:none;">
                <div class="dm-section-title"><span class="dm-step">4A</span> Schedule Next Mediation Hearing</div>
                <div class="dm-grid-2"><div class="dm-field" style="margin-bottom:0;"><label>Date <span class="req">*</span></label><input type="date" id="medNextDate" min="${new Date().toISOString().split('T')[0]}"></div><div class="dm-field" style="margin-bottom:0;"><label>Time <span class="req">*</span></label><input type="time" id="medNextTime" value="09:00"></div></div>
                <div class="dm-inline-rule"><i class="fas fa-info-circle"></i><span>Office hours: <b>8:00 AM – 6:30 PM</b> · min. 2 hours from now.</span></div>
                <div class="dm-conflict" id="medConflict"><i class="fas fa-exclamation-triangle"></i><div></div></div>
                <div class="dm-available" id="medAvailable"><i class="fas fa-check-circle"></i><span>Available.</span></div>
            </div>

            <div id="step-constitute" style="display:none;">
                <div class="dm-section-title"><span class="dm-step">5A</span> Set Pangkat Constitution Meeting</div>
                <div class="handbook-quote"><div class="handbook-quote-title"><i class="fas fa-users-cog"></i> Per KP Handbook p.52</div><p>You must set a date for <b>both parties</b> to meet and agree on the 3 members of the Pangkat. <b>KP Form #10</b> will be issued to both parties.</p><p>Should the parties fail to agree on the Pangkat membership, or fail to appear on the said date, <b>the Punong Barangay shall determine the membership by drawing lots.</b></p></div>
                <div class="dm-grid-2"><div class="dm-field" style="margin-bottom:0;"><label>Constitution Meeting Date <span class="req">*</span></label><input type="date" id="constitDate" min="${new Date().toISOString().split('T')[0]}"></div><div class="dm-field" style="margin-bottom:0;"><label>Meeting Time <span class="req">*</span></label><input type="time" id="constitTime" value="09:00" min="08:00" max="18:00"></div></div>
                <div class="dm-helper" style="margin-top:8px;"><i class="fas fa-info-circle"></i><span>Office hours only: <b>8:00 AM to 6:00 PM</b>.</span></div>
            </div>

            <div id="step-pangkat" style="display:none;">
                <div class="dm-section-title"><span class="dm-step">5B</span> Constitute Pangkat</div>
                <div id="constitutionInfoBox" style="margin-bottom:14px;"></div>
                <div class="handbook-quote"><div class="handbook-quote-title"><i class="fas fa-users-cog"></i> Position Assignment Flow</div><p>Pick <b>3 Lupon members</b> to form the Pangkat. <b>Roles are NOT assigned here</b> — the Pangkat members themselves will decide their Chairperson, Secretary, and Member.</p><p>Each chosen member will receive <b>KP Form #11 (Notice to Chosen Pangkat Member)</b>.</p><p>The <b>FIRST-selected member</b> will assign the three positions from the Lupon Portal. The other two members will wait for that assignment.</p></div>
                <div class="dm-field" style="margin-bottom:12px;"><label>How were the <b>members</b> chosen? <span class="req">*</span></label><div class="dm-chip-group" id="pangkatSelectedByGroup"><div class="dm-chip selected" data-value="parties" onclick="selectPangkatBy(this)"><i class="fas fa-handshake"></i> Parties agreed</div><div class="dm-chip" data-value="lot" onclick="selectPangkatBy(this)"><i class="fas fa-dice"></i> Drawn by lots</div><div class="dm-chip" data-value="captain" onclick="selectPangkatBy(this)"><i class="fas fa-user-tie"></i> Captain decided</div></div><input type="hidden" id="pangkatSelectedBy" value="parties"></div>
                <div id="manualPickWrap"><div class="dm-field" style="margin-bottom:8px;"><label>Select 3 Lupon members <span class="req">*</span></label><div id="manualPangkatList" style="max-height:260px;overflow-y:auto;border:1.5px solid #cfd8dc;border-radius:8px;padding:10px;background:#fff;"><div style="text-align:center;color:#999;padding:14px;font-size:0.85rem;"><i class="fas fa-spinner fa-spin"></i> Loading…</div></div><div class="dm-helper" style="margin-top:6px;"><i class="fas fa-lightbulb"></i><span>Selected: <b id="manualSelectedCount">0</b> of 3 · <span id="manualOrderHint" style="color:#1565c0;font-weight:600;">The first one checked becomes the first-selected member.</span></span></div></div></div>
                <div id="wheelModeWrap" style="display:none;"><div id="wheelStage" class="wheel-stage"><div class="wheel-pointer"></div><div id="wheelDisc" class="wheel-disc"></div></div><div class="pick-banner" id="pickBanner"><i class="fas fa-check-circle"></i><span id="pickBannerText">—</span></div><div class="wheel-result" id="wheelResult">Ready — spin to pick member #1</div><button type="button" id="spinBtn" class="wheel-spin-btn" onclick="spinWheel()" disabled><i class="fas fa-dice"></i> Loading…</button></div>
                <div class="wheel-selected-list" id="wheelSelectedList" style="margin-top:14px;"></div>
            </div>
        </div>
    `;

    await Swal.fire({
        title: '',
        html: `<div class="dm-form">${bodyHtml}</div>`,
        width: 720,
        allowOutsideClick: false,
        allowEscapeKey: false,
        allowEnterKey: false,
        showCancelButton: false,
        showConfirmButton: false,
        showDenyButton: false,
        willClose: () => {
            try { _pstmtReleaseAll('res'); } catch (e) {}
        },
        didOpen: async () => {
            await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));

            window.__attendanceState = {};
            parties.forEach(p => {
                let st = (p.status && p.status !== 'pending') ? p.status : 'pending';
                if (st === 'present' || st === 'late') st = 'pending';
                if (st === 'absent') st = 'no_show';
                window.__attendanceState[p.id] = st;
            });
            window.__hearingId = resume.hearing_id || 0;
            window.__sessionNo = sessionCount;
            window.__complaintId = id;
            window.__parties = parties;
            window.__justificationByPid = justificationByPid;
            window.__decisionState = {};
            window.__appearanceAt = resume.appearance_at || null;
            window.__constitutionAt = constitutionAt || null;
            window.__canConstitute = !!canConstitute;

            sessionFooter();
            renderAttCards(parties, justificationByPid);
            renderDecisionCards(parties, justificationByPid);
            initStep2Validation();

            const infoBox = document.getElementById('appearanceInfoBox');
            if (infoBox && window.__appearanceAt) {
                infoBox.innerHTML = `<div class="appear-info-banner"><i class="fas fa-user-clock"></i><div><div>Appearance was set for: <strong>${new Date(window.__appearanceAt.replace(' ','T')).toLocaleString('en-US',{dateStyle:'full',timeStyle:'short'})}</strong></div><div style="font-size:0.78rem;color:#666;margin-top:3px;">Decide below whether the absence is Justified or Unjustified. Use the notes box to record what they said.</div></div></div>`;
            }

            const cbox = document.getElementById('constitutionInfoBox');
            if (cbox && window.__constitutionAt) {
                cbox.innerHTML = `<div class="appear-info-banner"><i class="fas fa-users-cog"></i><div><div>Constitution meeting was scheduled for: <strong>${constitutionNice}</strong></div><div style="font-size:0.78rem;color:#666;margin-top:3px;">Pick the 3 members below — roles assigned by the first-selected member afterward.</div></div></div>`;
            }

            goToStep(startStep);
        }
    });
}

function renderAttCards(parties, justificationByPid) {
    const host = document.getElementById('attCardsHost');
    if (!host) return;
    if (!parties.length) { host.innerHTML = '<div class="dm-info"><div>No parties.</div></div>'; return; }
    host.innerHTML = parties.map(p => {
        const roleLc = (p.role || '').toLowerCase();
        const roleLabel = roleLc.charAt(0).toUpperCase() + roleLc.slice(1);
        const st = window.__attendanceState[p.id] || 'pending';
        const canNudge = !!p.matched_resident_id;
        const nudgeCount = p.nudge_count || 0;
        const canNudgeAgain = canNudge && nudgeCount < 3;
        return `<div class="att-card ${st !== 'pending' ? st : ''}" data-party-id="${p.id}"><div class="att-head"><div class="att-avatar ${roleLc}"><i class="fas ${roleLc === 'complainant' ? 'fa-user' : (roleLc === 'respondent' ? 'fa-user-friends' : 'fa-user-tie')}"></i></div><div class="att-meta"><div class="att-name">${escapeHtml(p.full_name || '—')}</div><div class="att-role ${roleLc}">${roleLabel}</div></div><span class="att-status-pill ${st}" id="pill-${p.id}">${st === 'no_show' ? 'NO SHOW' : st.toUpperCase()}</span></div><div class="att-btn-row"><button type="button" class="att-btn attend ${st==='attend'?'active':''}" onclick="setAttendance(${p.id},'attend',this)"><i class="fas fa-check"></i> Attend</button><button type="button" class="att-btn no_show ${st==='no_show'?'active':''}" onclick="setAttendance(${p.id},'no_show',this)"><i class="fas fa-times"></i> No Show</button></div><div class="att-nudge-row" id="nudge-row-${p.id}"><span><i class="fas fa-bell"></i> ${nudgeCount > 0 ? `Reminder sent ${nudgeCount} time(s).` : 'Send a reminder to come now.'}</span><button type="button" class="att-nudge-btn" onclick="sendNudge(${p.id}, ${p.matched_resident_id || 0}, this)" ${!canNudgeAgain ? 'disabled' : ''}><i class="fas fa-paper-plane"></i> ${nudgeCount > 0 ? 'Nudge Again' : 'Send Nudge'}</button></div></div>`;
    }).join('');
    parties.forEach(p => { if (window.__attendanceState[p.id] !== 'attend') { const n = document.getElementById('nudge-row-' + p.id); if (n) n.classList.add('show'); } });
    updateAttendanceStatusLine();
}

function renderDecisionCards(parties, justificationByPid) {
    const host = document.getElementById('decisionCardsHost');
    if (!host) return;
    const absent = parties.filter(p => (window.__attendanceState[p.id] === 'no_show'));
    if (!absent.length) { host.innerHTML = '<div class="dm-info"><div>No absent parties to decide.</div></div>'; return; }
    host.innerHTML = absent.map(p => {
        const roleLc = (p.role || '').toLowerCase();
        const roleLabel = roleLc.charAt(0).toUpperCase() + roleLc.slice(1);
        const saved = justificationByPid[p.id] || {};
        const savedStatus = saved.status || null;
        const savedAppNotes = saved.appearance_notes || '';
        const kpPdf = saved.kp_pdf || null;
        const kpBtn = kpPdf ? `<div class="dm-helper" style="margin:6px 0 10px;color:#e65100;"><i class="fas fa-file-pdf"></i><span>KP Form ${roleLc === 'complainant' ? '#18' : '#19'} was issued.<a href="#" onclick="event.preventDefault(); openKpFailureNoticePdf(${window.__complaintId}, ${window.__hearingId}, ${p.id});" style="color:#e65100;font-weight:700;text-decoration:underline;margin-left:6px;">Open PDF</a></span></div>` : '';
        const justifiedLegal = 'Absence accepted — mediation rescheduled with strict warning.';
        const unjustifiedLegalComplainant = '⚠️ Per RA 7160: complaint will be DISMISSED; complainant barred from filing in court; may be cited for indirect contempt.';
        const unjustifiedLegalRespondent  = '⚠️ Per RA 7160: counterclaim (if any) will be DISMISSED; respondent barred from filing it in court. Case proceeds to Pangkat.';
        const unjustifiedLegal = (roleLc === 'complainant') ? unjustifiedLegalComplainant : unjustifiedLegalRespondent;
        return `<div class="abs-card" data-decision-pid="${p.id}"><div class="abs-head"><div class="att-avatar ${roleLc}"><i class="fas ${roleLc === 'complainant' ? 'fa-user' : 'fa-user-friends'}"></i></div><div><div class="abs-name">${escapeHtml(p.full_name || '—')}</div><div class="att-role ${roleLc}">${roleLabel}</div></div></div>${kpBtn}<div class="dm-field" style="margin-bottom:12px;"><label>Reason / what they said <span class="opt">(optional)</span></label><textarea rows="2" data-role="appearance_notes" placeholder="e.g. Sick, hospitalized / no reason given / etc.">${escapeHtml(savedAppNotes)}</textarea></div><div style="font-weight:700;font-size:0.85rem;color:#1a472a;margin:0 0 6px;">RA 7160 — Decision</div><div class="abs-radio-row"><label class="abs-radio justified ${savedStatus === 'justified' ? 'selected' : ''}" onclick="pickDecision(${p.id}, 'justified', this)"><input type="radio" name="dec-${p.id}" value="justified" ${savedStatus === 'justified' ? 'checked' : ''}><div class="abs-radio-icon"><i class="fas fa-check-circle"></i></div><div class="abs-radio-text"><div class="abs-radio-title">Justified</div><div class="abs-radio-sub">Reschedule + strict warning.</div><div class="abs-radio-legal"><i class="fas fa-check-circle"></i> ${justifiedLegal}</div></div></label><label class="abs-radio unjustified ${savedStatus === 'unjustified' ? 'selected':''}" onclick="pickDecision(${p.id}, 'unjustified', this)"><input type="radio" name="dec-${p.id}" value="unjustified" ${savedStatus === 'unjustified' ? 'checked' : ''}><div class="abs-radio-icon"><i class="fas fa-times-circle"></i></div><div class="abs-radio-text"><div class="abs-radio-title">Unjustified</div><div class="abs-radio-sub">RA 7160 penalties apply (see below).</div><div class="abs-radio-legal ${roleLc === 'respondent' ? 'pangkat-ok' : ''}"><i class="fas fa-gavel"></i> ${unjustifiedLegal}</div></div></label></div></div>`;
    }).join('');
    window.__decisionState = {};
    absent.forEach(p => {
        const saved = justificationByPid[p.id] || {};
        window.__decisionState[p.id] = { status: saved.status || null, reason: saved.appearance_notes || '', appeared: saved.appeared === true ? 'yes' : (saved.appeared === false ? 'no' : null) };
    });
}

function goToStep(step) {
    window.__step = step;
    setFooterButtons({ primaryLabel: null, secondaryLabel: null });
    ['attendance','decision','result','settlement','nosettle','schednext','constitute','pangkat'].forEach(s => { const el = document.getElementById('step-' + s); if (el) el.style.display = 'none'; });
    const show = (id) => { const el = document.getElementById(id); if (el) el.style.display = 'block'; };
    if (step === 'attendance') { show('step-attendance'); updateContinueButton(); }
    else if (step === 'absence_decision') { show('step-decision'); setFooterButtons({ primaryLabel: '<i class="fas fa-gavel"></i> Confirm Decision', disablePrimary: !isDecisionComplete(), primaryVariant: 'danger' }); }
    else if (step === 'result') { show('step-result'); populateResultStatements(); setFooterButtons({ primaryLabel: '<i class="fas fa-arrow-right"></i> Continue', disablePrimary: !document.getElementById('medSettled').value }); }
    else if (step === 'settlement_terms') { show('step-settlement'); setFooterButtons({ primaryLabel: '<i class="fas fa-save"></i> Save Record', disablePrimary: false }); }
    else if (step === 'no_settlement') { show('step-nosettle'); setFooterButtons({ primaryLabel: '<i class="fas fa-arrow-right"></i> Continue', disablePrimary: false }); }
    else if (step === 'schedule_next') { show('step-schednext'); setFooterButtons({ primaryLabel: '<i class="fas fa-calendar-check"></i> Save & Schedule', disablePrimary: false, primaryVariant: 'warn' }); }
    else if (step === 'constitute_pangkat') { show('step-constitute'); setFooterButtons({ primaryLabel: '<i class="fas fa-file-signature"></i> Save & Issue KP Form #10', disablePrimary: false, primaryVariant: 'danger' }); }
    else if (step === 'pangkat_constitution_waiting') {
        if (!window.__canConstitute) {
            show('step-constitute');
            const cDate = window.__constitutionAt ? new Date(window.__constitutionAt.replace(' ','T')).toLocaleString('en-US',{dateStyle:'full',timeStyle:'short'}) : '';
            document.getElementById('step-constitute').innerHTML = `<div class="dm-section-title"><span class="dm-step">5</span> Waiting for Constitution Meeting</div><div class="waiting-card"><div class="wc-icon"><i class="fas fa-users-cog"></i></div><div class="wc-title">KP Form #10 has been issued to both parties</div><div class="wc-date">${escapeHtml(cDate)}</div><div class="wc-sub">You can constitute the Pangkat <b>on or after the day of the meeting</b>.<br>Come back on that day to pick the 3 members.</div></div>`;
            setFooterButtons({ primaryLabel: '<i class="fas fa-clock"></i> Waiting…', disablePrimary: true, primaryVariant: 'warn' });
        } else {
            window.__step = 'pangkat_members';
            show('step-pangkat');
            loadPangkatMembers();
            setFooterButtons({ primaryLabel: '<i class="fas fa-balance-scale"></i> Save & Constitute', disablePrimary: false, primaryVariant: 'danger' });
        }
    }
    const closeBtn = document.getElementById('sesBtnClose');
    if (closeBtn) closeBtn.style.display = '';
}

function isAttendanceComplete() {
    const parties = window.__parties || [];
    if (!parties.length) return true;
    return parties.every(p => { const st = window.__attendanceState[p.id]; return st && st !== 'pending'; });
}
function hasAnyAbsent() { return Object.values(window.__attendanceState || {}).some(s => s === 'no_show'); }
function setAttendance(partyId, status, btnEl) {
    if (!window.__attendanceState) window.__attendanceState = {};
    window.__attendanceState[partyId] = status;
    const card = document.querySelector(`.att-card[data-party-id="${partyId}"]`);
    if (!card) return;
    card.classList.remove('attend', 'no_show');
    if (status !== 'pending') card.classList.add(status);
    card.querySelectorAll('.att-btn').forEach(b => b.classList.remove('active'));
    btnEl.classList.add('active');
    const pill = card.querySelector('.att-status-pill');
    if (pill) { pill.classList.remove('attend', 'no_show', 'pending'); pill.classList.add(status); pill.textContent = status === 'no_show' ? 'NO SHOW' : status.toUpperCase(); }
    const nudgeRow = card.querySelector('.att-nudge-row');
    if (nudgeRow) { if (status === 'attend') nudgeRow.classList.remove('show'); else nudgeRow.classList.add('show'); }
    updateAttendanceStatusLine();
    updateContinueButton();
}
function updateAttendanceStatusLine() {
    const line = document.getElementById('attendanceStatusLine');
    if (!line) return;
    const total = Object.keys(window.__attendanceState || {}).length;
    const marked = Object.values(window.__attendanceState || {}).filter(s => s && s !== 'pending').length;
    const absent = Object.values(window.__attendanceState || {}).filter(s => s === 'no_show').length;
    const banner = document.getElementById('absentBanner');
    if (banner) banner.classList.toggle('show', absent > 0);
    if (marked < total) { line.innerHTML = `<i class="fas fa-info-circle"></i> Marked ${marked} of ${total}.`; line.style.background = '#f8faf8'; line.style.color = '#555'; }
    else if (absent > 0) { line.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${absent} no-show. Continue to set an appearance date.`; line.style.background = '#ffebee'; line.style.color = '#b71c1c'; }
    else { line.innerHTML = `<i class="fas fa-check-circle"></i> All accounted for. Click Continue.`; line.style.background = '#e8f5e9'; line.style.color = '#1b5e20'; }
}
function updateContinueButton() {
    if (window.__step !== 'attendance') return;
    if (!isAttendanceComplete()) setFooterButtons({ primaryLabel: '<i class="fas fa-arrow-right"></i> Continue', disablePrimary: true });
    else if (hasAnyAbsent()) setFooterButtons({ primaryLabel: '<i class="fas fa-user-clock"></i> Set Appearance Date', primaryVariant: 'danger', disablePrimary: false });
    else setFooterButtons({ primaryLabel: '<i class="fas fa-arrow-right"></i> Continue', disablePrimary: false });
}

async function sendNudge(partyId, residentId, btnEl) {
    if (!residentId) { Swal.fire('Not possible', 'No linked resident account.', 'info'); return; }
    const original = btnEl.innerHTML;
    btnEl.disabled = true;
    btnEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
    try {
        const fd = new FormData();
        fd.append('action', 'nudge_party');
        fd.append('party_id', partyId);
        fd.append('resident_id', residentId);
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: d.message, showConfirmButton: false, timer: 2200 });
            const row = document.getElementById('nudge-row-' + partyId);
            if (row) { const span = row.querySelector('span'); if (span) span.innerHTML = `<i class="fas fa-bell"></i> Reminder sent ${d.nudge_count || 1} time(s).`; btnEl.innerHTML = '<i class="fas fa-paper-plane"></i> Nudge Again'; btnEl.disabled = (d.nudge_count >= 3); }
        } else { Swal.fire('Error', d.message || 'Failed.', 'error'); btnEl.innerHTML = original; btnEl.disabled = false; }
    } catch (e) { Swal.fire('Error', 'Network error.', 'error'); btnEl.innerHTML = original; btnEl.disabled = false; }
}

async function onAttContinue() {
    const parties = window.__parties || [];
    const missing = parties.filter(p => { const st = window.__attendanceState[p.id]; return !st || st === 'pending'; });
    if (missing.length) { Swal.fire('Incomplete', 'Mark attendance for: ' + missing.map(p => p.full_name).join(', '), 'warning'); return; }
    const anyAbsent = hasAnyAbsent();
    let appearance_at = null;
    if (anyAbsent) {
        const absentList = parties.filter(p => window.__attendanceState[p.id] === 'no_show').map(p => ({ id: p.id, full_name: p.full_name, role: p.role }));
        const meta = { reference_number: (cachedData.mediation.find(x => x.id === window.__complaintId) || {}).reference_number || '', session_number: window.__sessionNo, absent: absentList };
        Swal.close();
        const appt = await openAppearanceModal(meta);
        if (!appt) { setTimeout(() => openMediationSessionModal(window.__complaintId, { startStep: 'attendance' }), 200); return; }
        appearance_at = `${appt.date} ${appt.time}`;
        setTimeout(async () => { await saveAttendanceAndAdvance(appearance_at, true); }, 200);
        return;
    }
    await saveAttendanceAndAdvance(null, false);
}

async function saveAttendanceAndAdvance(appearance_at, immediate) {
    if (!immediate) { setTimeout(() => openMediationSessionModal(window.__complaintId, { startStep: 'result' }), 200); return; }
    Swal.fire({ title: 'Saving attendance…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const fdA = new FormData();
        fdA.append('action', 'save_attendance');
        fdA.append('complaint_id', window.__complaintId);
        fdA.append('attendance', JSON.stringify(Object.entries(window.__attendanceState).map(([pid, st]) => ({ party_id: Number(pid), status: st }))));
        if (appearance_at) fdA.append('appearance_at', appearance_at);
        const rA = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fdA });
        const dA = await rA.json();
        Swal.close();
        if (!dA.success) { Swal.fire('Error', dA.message || 'Failed.', 'error'); return; }
        if (dA.waiting && dA.notices && dA.notices.length) {
            const notices = dA.notices || [];
            const printRequired = notices.filter(n => n.print);
            const list = notices.map(n => {
                const cls = (n.form === 'KP18') ? 'kp18' : 'kp19';
                const label = escapeHtml(n.form_label || ('KP Form ' + (n.form === 'KP18' ? '#18' : '#19')));
                const printCls = n.print ? 'print-required' : '';
                const iconCls  = n.print ? 'print-required' : cls;
                const badge = n.notified ? `<span class="notice-item-notified yes"><i class="fas fa-check"></i> Resident notified</span>` : `<span class="notice-item-notified no"><i class="fas fa-print"></i> ⚠ PRINT REQUIRED</span>`;
                return `<div class="notice-item ${cls} ${printCls}"><div class="notice-item-icon ${iconCls}"><i class="fas ${(n.form === 'KP18') ? 'fa-file-alt' : 'fa-file-signature'}"></i></div><div class="notice-item-body"><div class="notice-item-title">${escapeHtml(n.name)} — ${label}</div><div class="notice-item-meta"><span><i class="fas fa-folder"></i> Saved on disk</span>${badge}</div><button type="button" class="notice-item-btn ${cls}" onclick="openKpFailureNoticePdf(${window.__complaintId}, ${window.__hearingId}, ${n.party_id})"><i class="fas fa-file-pdf"></i> Open PDF</button></div></div>`;
            }).join('') || '<div style="padding:10px;color:#999;text-align:center;">No notices.</div>';
            const printBanner = printRequired.length ? `<div class="dm-danger" style="margin-top:16px;animation: pulseBadge 1.5s infinite;"><i class="fas fa-print"></i><div><b>⚠ PRINT REQUIRED — ${printRequired.length} notice(s)</b><br>The Secretary has been notified to print and hand-deliver the Failure-to-Appear notice.</div></div>` : '';
            const niceAppear = new Date(appearance_at.replace(' ', 'T')).toLocaleString('en-US', {dateStyle:'full',timeStyle:'short'});
            await Swal.fire({ icon: 'success', title: 'Appearance Date Set', html: `<div style="text-align:left;font-size:0.9rem;"><p>The absent part${notices.length>1?'ies':'y'} must appear before you on:</p><p style="font-weight:700;color:#c62828;font-size:1rem;">${escapeHtml(niceAppear)}</p><div class="notice-panel"><div class="notice-panel-title"><i class="fas fa-file-signature"></i> Failure-to-Appear Notices (${notices.length})</div>${list}</div>${printBanner}</div>`, confirmButtonText: '<i class="fas fa-check"></i> Done', confirmButtonColor: '#2E7D32', width: 720, allowOutsideClick: false }).then(() => reloadComplaints());
        } else if (dA.waiting) {
            Swal.fire({ icon: 'success', title: 'Appearance Date Set', text: 'The absent party must appear on the scheduled date.', confirmButtonColor: '#2E7D32' }).then(() => reloadComplaints());
        } else { setTimeout(() => openMediationSessionModal(window.__complaintId, { startStep: 'result' }), 200); }
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}

function pickDecision(pid, status, el) {
    const card = el.closest('.abs-card');
    if (!card) return;
    card.querySelectorAll('.abs-radio').forEach(r => r.classList.remove('selected', 'justified', 'unjustified'));
    el.classList.add('selected', status === 'justified' ? 'justified' : 'unjustified');
    const radio = el.querySelector('input[type=radio]');
    if (radio) radio.checked = true;
    if (!window.__decisionState) window.__decisionState = {};
    window.__decisionState[pid] = window.__decisionState[pid] || {};
    window.__decisionState[pid].status = status;
    if (window.__step === 'absence_decision') setFooterButtons({ primaryLabel: '<i class="fas fa-gavel"></i> Confirm Decision', disablePrimary: !isDecisionComplete(), primaryVariant: 'danger' });
}

function isDecisionComplete() {
    const parties = window.__parties || [];
    const absent = parties.filter(p => window.__attendanceState[p.id] === 'no_show');
    if (!absent.length) return true;
    return absent.every(p => { const d = window.__decisionState[p.id]; if (!d) return false; return (d.status === 'justified' || d.status === 'unjustified'); });
}

async function onDecisionConfirm() {
    const parties = window.__parties || [];
    const absent = parties.filter(p => window.__attendanceState[p.id] === 'no_show');
    const decisions = absent.map(p => {
        const card = document.querySelector(`.abs-card[data-decision-pid="${p.id}"]`);
        const ta = card?.querySelector('textarea[data-role="appearance_notes"]');
        const d = window.__decisionState[p.id] || {};
        const appNotes = (ta?.value || '').trim();
        return { party_id: p.id, party_type: p.role, status: d.status, reason: appNotes, appeared: 0, appearance_notes: appNotes };
    });
    if (!isSessionRecordValid('dec')) { Swal.fire('Incomplete', 'Please fill the "Summary of this mediation hearing" field.', 'warning'); return; }
    const summary = decisions.map(d => { const pname = (parties.find(p => p.id === d.party_id) || {}).full_name || ''; return `<li><b>${escapeHtml(pname)}</b> — ${escapeHtml(d.party_type)}<br>Decision: <b style="color:${d.status === 'unjustified' ? '#c62828' : '#2E7D32'}">${(d.status || '').toUpperCase()}</b></li>`; }).join('');
    const confirm = await Swal.fire({ icon: 'warning', title: 'Confirm Absence Decision?', html: `<div style="text-align:left;font-size:0.9rem;"><p>This will be executed immediately:</p><ul style="margin-top:8px;line-height:1.7;">${summary}</ul><p style="margin-top:12px;font-size:0.82rem;color:#b71c1c;font-weight:700;"><i class="fas fa-exclamation-triangle"></i>Unjustified complainant → COMPLAINT DISMISSED + KP Form #23 auto-issued.<br>Unjustified respondent → counterclaim barred + KP Form #24 auto-issued + case to Pangkat.</p></div>`, showCancelButton: true, confirmButtonText: '<i class="fas fa-gavel"></i> Confirm Decision', confirmButtonColor: '#c62828', cancelButtonText: 'Back', cancelButtonColor: '#6c757d', allowOutsideClick: false, allowEscapeKey: false });
    if (!confirm.isConfirmed) return;
    Swal.fire({ title: 'Recording decision…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const sr = collectSessionRecord('dec');
        const fd = new FormData();
        fd.append('action', 'resolve_absence_decisions');
        fd.append('complaint_id', window.__complaintId);
        fd.append('hearing_id', window.__hearingId);
        fd.append('decisions', JSON.stringify(decisions));
        fd.append('session_record', JSON.stringify(sr));
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        Swal.close();
        if (!d.success) { Swal.fire('Error', d.message || 'Failed.', 'error'); return; }
        window.__sessionSaved = true;
        if (d.outcome === 'reschedule') {
            Swal.close();
            Swal.fire({ icon: 'info', title: 'Absences Justified', html: `<div style="text-align:left;font-size:0.9rem;"><p>All absences were justified. The complaint is now marked <b>Rescheduled</b>.</p><p style="margin-top:8px;">Please pick the new mediation date & time in the next screen.</p></div>`, confirmButtonText: '<i class="fas fa-calendar-plus"></i> Schedule Next Hearing', confirmButtonColor: '#e65100', allowOutsideClick: false }).then(() => { reloadComplaints(); openScheduleModal(window.__complaintId); });
            return;
        }
        const cert = d.cert_info;
        const certBlock = cert ? `<div style="margin-top:12px;padding:12px 14px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.85rem;color:#b71c1c;"><b><i class="fas fa-gavel"></i> ${escapeHtml(cert.label)}</b><br>Cert No: <b>${escapeHtml(cert.cert_no)}</b><br><a href="#" onclick="event.preventDefault(); window.open('../../${escapeHtml(cert.pdf_url)}', '_blank');" style="display:inline-block;margin-top:6px;padding:5px 12px;background:#c62828;color:#fff;border-radius:6px;text-decoration:none;font-size:0.78rem;font-weight:700;"><i class="fas fa-file-pdf"></i> Open PDF</a></div>` : '';
        let outcomeTitle = 'Decision Recorded';
        let outcomeMsg = '';
        if (d.outcome === 'dismissed_complaint') { outcomeTitle = 'Complaint Dismissed'; outcomeMsg = `Complaint was DISMISSED because the complainant did not appear without justifiable cause.`; }
        else if (d.outcome === 'end_to_pangkat') { outcomeTitle = 'Mediation Ended — Proceeding to Pangkat'; outcomeMsg = `The respondent did not appear without justifiable cause. The counterclaim is barred. The case will now proceed to the Pangkat.`; }
        Swal.fire({ icon: d.outcome === 'dismissed_complaint' ? 'error' : 'info', title: outcomeTitle, html: `<div style="text-align:left;font-size:0.9rem;"><p>${escapeHtml(outcomeMsg)}</p>${certBlock}</div>`, confirmButtonText: 'Done', confirmButtonColor: '#2E7D32', allowOutsideClick: false }).then(() => reloadComplaints());
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}

function selectMedResult(val) {
    const group = document.getElementById('medResultGroup');
    if (!group) return;
    group.querySelectorAll('.dm-option').forEach(o => o.classList.remove('selected'));
    const sel = group.querySelector(`.dm-option[data-value="${val}"]`);
    if (sel) sel.classList.add('selected');
    document.getElementById('medSettled').value = (val === '1') ? '1' : '0';
    if (window.__step === 'result') setFooterButtons({ primaryLabel: '<i class="fas fa-arrow-right"></i> Continue', disablePrimary: false });
}

async function onResultContinue() {
    const settled = document.getElementById('medSettled').value === '1';
    if (settled) goToStep('settlement_terms'); else goToStep('no_settlement');
}

function selectMedReason(el) {
    const group = el.parentElement;
    group.querySelectorAll('.dm-chip').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('medReason').value = el.dataset.value;
}

function buildAttendancePayload() {
    const out = [];
    Object.entries(window.__attendanceState || {}).forEach(([pid, st]) => { if (st === 'attend' || st === 'no_show') out.push({ party_id: Number(pid), status: st }); });
    return out;
}

function populateResultStatements() {
    const host = document.getElementById('step-result-pstmt-host');
    if (!host) return;
    const anyAbsent = Object.values(window.__attendanceState || {}).some(s => s === 'no_show');
    host.innerHTML = partyStatementsBlockHtml('res', { disabled: anyAbsent });
}

async function onSaveSettlement() {
    const summary = document.getElementById('medSettleSummary').value.trim();
    if (!summary) { Swal.fire('Missing', 'Please describe the settlement terms.', 'warning'); return; }
    if (!isSessionRecordValid('set')) { Swal.fire('Incomplete', 'Please fill the "Summary of this mediation hearing" field.', 'warning'); return; }
    await finalizeMediation({ settled: true, summary, next_action: 'end', sr_prefix: 'set' });
}

function onNotSettledContinue() {
    const summary = document.getElementById('medNoSummary').value.trim();
    if (!summary) { Swal.fire('Missing', 'Please describe what happened in this mediation hearing.', 'warning'); return; }
    if (!isSessionRecordValid('noset')) { Swal.fire('Incomplete', 'Please fill the "Summary of this mediation hearing" field.', 'warning'); return; }
    window.__nosetSummary = summary;
    const nextAction = document.getElementById('medNextAction').value;
    if (nextAction === 'schedule') goToStep('schedule_next'); else goToStep('constitute_pangkat');
}

function selectMedNext(val) {
    document.getElementById('medNextAction').value = val;
    const group = document.getElementById('medNextGroup');
    if (group) { group.querySelectorAll('.dm-option').forEach(o => o.classList.remove('selected')); const sel = group.querySelector(`.dm-option[data-value="${val}"]`); if (sel) sel.classList.add('selected'); }
}

async function onSaveScheduleNext() {
    const summary = window.__nosetSummary || document.getElementById('medNoSummary').value.trim();
    const reason  = document.getElementById('medReason').value;
    const date    = document.getElementById('medNextDate').value;
    const time    = document.getElementById('medNextTime').value;
    if (!date) { Swal.fire('Missing', 'Pick a date.', 'warning'); return; }
    if (!time) { Swal.fire('Missing', 'Pick a time.', 'warning'); return; }
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=check_scheduling_live&date=${date}&time=${time}`);
        const d = await r.json();
        if (d.success && !d.allowed) { Swal.fire('Cannot schedule', d.reason, 'warning'); return; }
    } catch (e) {}
    await finalizeMediation({ settled: false, summary, reason, next_action: 'schedule', next_date: date, next_time: time, sr_prefix: 'noset' });
}

async function onSaveConstitutionMeeting() {
    const date = document.getElementById('constitDate').value;
    const time = document.getElementById('constitTime').value;
    if (!date) { Swal.fire('Missing', 'Pick a date for the constitution meeting.', 'warning'); return; }
    if (!time) { Swal.fire('Missing', 'Pick a time for the constitution meeting.', 'warning'); return; }
    Swal.fire({ title: 'Issuing KP Form #10…', html: 'Saving PDFs for both parties…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const fd = new FormData();
        fd.append('action', 'schedule_pangkat_constitution');
        fd.append('complaint_id', window.__complaintId);
        fd.append('constitution_date', date);
        fd.append('constitution_time', time);
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        Swal.close();
        if (!d.success) { Swal.fire('Error', d.message || 'Failed.', 'error'); return; }
        window.__sessionSaved = true;
        const notices = d.notices || [];
        const printRequired = notices.filter(n => n.print);
        const list = notices.map(n => {
            const printCls = n.print ? 'print-required' : '';
            const iconCls  = n.print ? 'print-required' : 'kp10';
            const badge = n.notified ? `<span class="notice-item-notified yes"><i class="fas fa-check"></i> Resident notified</span>` : `<span class="notice-item-notified no"><i class="fas fa-print"></i> ⚠ PRINT REQUIRED</span>`;
            return `<div class="notice-item kp10 ${printCls}"><div class="notice-item-icon ${iconCls}"><i class="fas fa-users-cog"></i></div><div class="notice-item-body"><div class="notice-item-title">${escapeHtml(n.name)} — KP Form #10 — Notice for Constitution of Pangkat</div><div class="notice-item-meta"><span><i class="fas fa-folder"></i> Saved on disk</span>${badge}</div><button type="button" class="notice-item-btn kp10" onclick="openKp10Pdf(${window.__complaintId}, ${n.party_id})"><i class="fas fa-file-pdf"></i> Open PDF</button></div></div>`;
        }).join('') || '<div style="padding:10px;color:#999;text-align:center;">No notices.</div>';
        const printBanner = printRequired.length ? `<div class="dm-danger" style="margin-top:16px;animation: pulseBadge 1.5s infinite;"><i class="fas fa-print"></i><div><b>⚠ PRINT REQUIRED — ${printRequired.length} notice(s)</b><br>The Secretary has been notified to print and hand-deliver KP Form #10.</div></div>` : '';
        await Swal.fire({
            icon: 'success', title: 'KP Form #10 Issued',
            html: `<div style="text-align:left;font-size:0.9rem;"><p>Constitution meeting scheduled for:</p><p style="font-weight:700;color:#4a148c;font-size:1rem;">${escapeHtml(d.constitution_date)} at ${escapeHtml(d.constitution_time)}</p><div class="notice-panel"><div class="notice-panel-title"><i class="fas fa-users-cog"></i> KP Form #10 — Notices Issued (${notices.length})</div>${list}</div>${printBanner}<p style="font-size:0.82rem;color:#666;margin-top:14px;"><b>Reminder:</b> You may constitute the Pangkat <b>on or after the day of the meeting</b>. Reopen this case then to pick the 3 Lupon members.</p></div>`,
            confirmButtonText: '<i class="fas fa-check"></i> Done', confirmButtonColor: '#2E7D32', width: 720, allowOutsideClick: false
        }).then(() => reloadComplaints());
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}

async function onSaveConstitutePangkat() {
    if (window.__wheelSelected.length !== 3) { Swal.fire('Incomplete', 'Select exactly 3 Pangkat members.', 'warning'); return; }
    const selectedBy = document.getElementById('pangkatSelectedBy').value || 'parties';
    Swal.fire({ title: 'Constituting Pangkat…', html: 'Issuing KP Form #11 to each chosen member…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const fdP = new FormData();
        fdP.append('action', 'constitute_pangkat');
        fdP.append('complaint_id', window.__complaintId);
        fdP.append('selected_by', selectedBy);
        fdP.append('members', JSON.stringify(window.__wheelSelected.map(mid => ({ member_id: Number(mid) }))));
        const rP = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fdP });
        const dP = await rP.json();
        Swal.close();
        if (dP.success) {
            window.__sessionSaved = true;
            const firstMember = (dP.members || []).find(m => m.is_first);
            const firstName = firstMember ? firstMember.name : 'the first-selected member';
            Swal.fire({
                icon: 'success', title: 'Pangkat Constituted',
                html: `<div style="text-align:left;font-size:0.9rem;"><p>Members selected:</p><ul>${(dP.members || []).map(m => `<li>${escapeHtml(m.name)}${m.is_first ? ' <span style="color:#2E7D32;font-weight:700;">(first-selected)</span>' : ''} — KP Form #11 issued</li>`).join('')}</ul><div style="margin-top:12px;padding:10px 12px;background:#e8f5e9;border-left:4px solid #2E7D32;border-radius:8px;font-size:0.82rem;color:#1b5e20;"><b>Next steps:</b><br>• <b>${escapeHtml(firstName)}</b> will assign Chairperson / Secretary / Member in the Lupon Portal<br>• The <b>Chairperson</b> will then schedule the conciliation hearing<br>• You and the Secretary will be notified</div></div>`,
                confirmButtonColor: '#2E7D32', width: 620
            }).then(() => reloadComplaints());
        } else { Swal.fire('Error', dP.message || 'Constitution failed.', 'error'); }
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}

async function finalizeMediation(payload) {
    const pstmtHost = document.getElementById('step-result-pstmt-host');
    if (pstmtHost && pstmtHost.innerHTML.trim()) {
        const v = validatePartyStatements('res');
        if (!v.ok) { Swal.fire('Missing Statement', v.message, 'warning'); return; }
    }

    Swal.fire({ title: 'Saving…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const uploadRes = await uploadAllPendingStatements('res', window.__complaintId, window.__hearingId);
        const failedUploads = Object.entries(uploadRes)
            .filter(([_, r]) => !r.success)
            .map(([p, r]) => p + ': ' + (r.message || 'unknown'));
        if (failedUploads.length) {
            Swal.close();
            Swal.fire('Upload Failed', 'Could not upload: <br>' + failedUploads.join('<br>'), 'error');
            return;
        }

        const sr = collectSessionRecord(payload.sr_prefix || 'noset');
        const fd = new FormData();
        fd.append('action', 'captain_mediate');
        fd.append('complaint_id', window.__complaintId);
        fd.append('settled', payload.settled ? '1' : '0');
        fd.append('summary', payload.summary);
        if (payload.reason) fd.append('reason', payload.reason);
        if (payload.next_action) fd.append('next_action', payload.next_action);
        if (payload.next_date) fd.append('next_date', payload.next_date);
        if (payload.next_time) fd.append('next_time', payload.next_time);
        fd.append('session_record', JSON.stringify(sr));

        const csText = document.getElementById('res-cs-text')?.value.trim() || '';
        const rsText = document.getElementById('res-rs-text')?.value.trim() || '';
        if (csText) fd.append('complainant_statement', csText);
        if (rsText) fd.append('respondent_statement',  rsText);

        const attPayload = buildAttendancePayload();
        if (attPayload.length) fd.append('attendance', JSON.stringify(attPayload));

        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (!d.success) { Swal.close(); Swal.fire('Error', d.message || 'Failed.', 'error'); return; }
        window.__sessionSaved = true;
        Swal.close();

        const kp16Url = (d.kp16 && d.kp16.ok) ? d.kp16.pdf_path : null;
        const wasSettled = !!payload.settled && !!d.final;
        const complaintId = window.__complaintId;

        Swal.fire({
            icon: 'success',
            title: d.final ? 'Case Closed' : 'Mediation Hearing Recorded',
            html: `<div style="text-align:left;font-size:0.9rem;">
                <p>${escapeHtml(d.message || '')}</p>
                ${kp16Url ? `
                    <div style="margin-top:12px;padding:12px 14px;background:#e8f5e9;border-left:4px solid #2E7D32;border-radius:8px;font-size:0.83rem;color:#1b5e20;">
                        <b><i class="fas fa-file-signature"></i> KP Form #16 (Amicable Settlement) was auto-issued.</b><br>
                        <a href="#" onclick="event.preventDefault(); window.open('../../${escapeHtml(kp16Url)}','_blank');"
                           style="display:inline-block;margin-top:6px;padding:5px 12px;background:#2E7D32;color:#fff;border-radius:6px;text-decoration:none;font-size:0.78rem;font-weight:700;">
                            <i class="fas fa-file-pdf"></i> View KP #16
                        </a>
                    </div>` : ''}
                ${wasSettled ? `
                    <p style="margin-top:14px;font-size:0.82rem;color:#666;">
                        You can now generate the follow-up KP forms for this settled case (KP #17 Repudiation, KP #25–27 Execution, etc.).
                    </p>` : ''}
            </div>`,
            confirmButtonText: wasSettled
                ? '<i class="fas fa-file-signature"></i> Manage KP Forms'
                : '<i class="fas fa-check"></i> Done',
            confirmButtonColor: '#2E7D32'
        }).then((res) => {
            reloadComplaints();
            if (wasSettled && res.isConfirmed) {
                setTimeout(() => openCaseKpFormsModal(complaintId), 250);
            }
        });
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}

function attemptSessionClose() {
    if (window.__sessionSaved) { Swal.close(); return; }
    Swal.fire({
        icon: 'warning', title: 'Mediation hearing not saved',
        html: `<div style="text-align:left;font-size:0.9rem;"><p>You have not recorded an outcome for this mediation hearing.</p><p style="font-size:0.85rem;color:#666;margin-top:8px;">Attendance already saved will remain on the server, but any unsaved notes will be lost.</p></div>`,
        showCancelButton: true, confirmButtonText: '<i class="fas fa-times"></i> Close Hearing', confirmButtonColor: '#c62828', cancelButtonText: '<i class="fas fa-arrow-left"></i> Continue Hearing', cancelButtonColor: '#2E7D32', allowOutsideClick: false, allowEscapeKey: false, allowEnterKey: false
    }).then(r => { if (r.isConfirmed) { Swal.close(); reloadComplaints(); } });
}

function initStep2Validation() {
    const dEl = document.getElementById('medNextDate');
    const tEl = document.getElementById('medNextTime');
    const warn = document.getElementById('medConflict');
    const avail = document.getElementById('medAvailable');
    if (!dEl || !tEl || !warn) return;
    if (dEl.dataset.bound) return;
    dEl.dataset.bound = '1';
    let debounceTimer = null;
    const liveCheck = () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(async () => {
            if (!dEl.value || !tEl.value) return;
            try {
                const r = await fetch(`../admin_complaint_ajax.php?action=check_scheduling_live&date=${dEl.value}&time=${tEl.value}`);
                const d = await r.json();
                if (!d.success) return;
                if (d.min_time) tEl.setAttribute('min', d.min_time);
                if (d.max_time) tEl.setAttribute('max', d.max_time);
                if (d.allowed) { warn.classList.remove('show'); if (avail) avail.classList.add('show'); }
                else { warn.querySelector('div').innerHTML = `<b>Not allowed:</b> ${escapeHtml(d.reason)}`; warn.classList.add('show'); if (avail) avail.classList.remove('show'); }
            } catch (e) {}
        }, 300);
    };
    const onDateChange = async () => {
        try {
            const r = await fetch(`../admin_complaint_ajax.php?action=get_scheduling_limits&date=${dEl.value}`);
            const d = await r.json();
            if (!d.success) return;
            if (!d.allowed) { warn.querySelector('div').innerHTML = `<b>Not allowed:</b> ${escapeHtml(d.reason)}`; warn.classList.add('show'); if (avail) avail.classList.remove('show'); tEl.value = ''; return; }
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

/* PANGKAT: manual + wheel */
window.__allLupon = [];
window.__wheelSelected = [];
window.__wheelAngle = 0;
window.__wheelSpinning = false;
window.__pangkatMode = 'parties';

async function loadPangkatMembers() {
    try {
        const r = await fetch('../admin_complaint_ajax.php?action=get_active_lupon');
        const d = await r.json();
        if (!d.success) throw new Error('Failed');
        window.__allLupon = d.members || [];
        window.__wheelSelected = [];
        window.__wheelAngle = 0;
        window.__pangkatMode = 'parties';
        const chipGroup = document.getElementById('pangkatSelectedByGroup');
        if (chipGroup) { chipGroup.querySelectorAll('.dm-chip').forEach(c => c.classList.remove('selected')); const partiesChip = chipGroup.querySelector('.dm-chip[data-value="parties"]'); if (partiesChip) partiesChip.classList.add('selected'); }
        const hidden = document.getElementById('pangkatSelectedBy');
        if (hidden) hidden.value = 'parties';
        updatePickedList();
        applyPangkatMode('parties');
    } catch (e) {
        console.error(e);
        const list = document.getElementById('manualPangkatList');
        if (list) list.innerHTML = '<div style="padding:14px;color:#c62828;">Failed to load Lupon members.</div>';
    }
}

function applyPangkatMode(mode) {
    window.__pangkatMode = mode;
    const manualWrap = document.getElementById('manualPickWrap');
    const wheelWrap  = document.getElementById('wheelModeWrap');
    window.__wheelSelected = [];
    window.__wheelAngle = 0;
    updatePickedList();
    if (mode === 'lot') {
        if (manualWrap) manualWrap.style.display = 'none';
        if (wheelWrap)  wheelWrap.style.display  = 'block';
        renderWheelDisc();
        const btn = document.getElementById('spinBtn');
        if (btn) { btn.disabled = window.__allLupon.length < 3; btn.innerHTML = '<i class="fas fa-dice"></i> Spin to Pick Member #1'; }
        const res = document.getElementById('wheelResult');
        if (res) res.textContent = window.__allLupon.length < 3 ? 'Need at least 3 active Lupon members.' : 'Ready — spin to pick member #1';
    } else {
        if (manualWrap) manualWrap.style.display = 'block';
        if (wheelWrap)  wheelWrap.style.display  = 'none';
        renderManualPickList();
    }
}

function renderManualPickList() {
    const list = document.getElementById('manualPangkatList');
    if (!list) return;
    if (!window.__allLupon.length) { list.innerHTML = '<div style="padding:14px;color:#c62828;font-size:0.85rem;">No active Lupon members found.</div>'; return; }
    const selectedSet = new Set((window.__wheelSelected || []).map(Number));
    list.innerHTML = window.__allLupon.map(m => {
        const checked = selectedSet.has(Number(m.id));
        const idx = window.__wheelSelected.indexOf(Number(m.id));
        const orderBadge = (idx >= 0) ? `<span style="background:#2E7D32;color:#fff;border-radius:10px;padding:2px 8px;font-size:0.65rem;font-weight:700;">#${idx + 1}</span>` : '';
        return `<label class="manual-pangkat-row ${checked ? 'checked' : ''}"><input type="checkbox" class="manual-pangkat-cb" value="${m.id}" ${checked ? 'checked' : ''} onchange="onManualPickChange(this)" style="width:16px;height:16px;cursor:pointer;"><span style="flex:1;">${escapeHtml(m.full_name)}</span>${orderBadge}</label>`;
    }).join('');
    const countEl = document.getElementById('manualSelectedCount');
    if (countEl) countEl.textContent = String((window.__wheelSelected || []).length);
}

function onManualPickChange(cb) {
    const val = Number(cb.value);
    if (cb.checked) {
        if (window.__wheelSelected.length >= 3) { cb.checked = false; showPickBanner('You can only select 3 members.', 'warn'); return; }
        window.__wheelSelected.push(val);
    } else { window.__wheelSelected = window.__wheelSelected.filter(x => x !== val); }
    document.querySelectorAll('.manual-pangkat-cb').forEach(input => { const label = input.closest('.manual-pangkat-row'); if (!label) return; if (input.checked) label.classList.add('checked'); else label.classList.remove('checked'); });
    renderManualPickList();
    updatePickedList();
    const countEl = document.getElementById('manualSelectedCount');
    if (countEl) countEl.textContent = String(window.__wheelSelected.length);
}

function renderWheelDisc() {
    const disc = document.getElementById('wheelDisc');
    if (!disc) return;
    const members = window.__allLupon.filter(m => !window.__wheelSelected.includes(m.id));
    if (!members.length) { disc.innerHTML = '<div class="wheel-center">DONE</div>'; return; }
    const n = members.length;
    const colors = ['#E53935','#8E24AA','#3949AB','#039BE5','#00897B','#7CB342','#FDD835','#FB8C00','#6D4C41','#546E7A','#D81B60','#5E35B1','#1E88E5','#43A047','#F4511E'];
    const segAngle = 360 / n;
    const stops = members.map((m, i) => { const c1 = colors[i % colors.length]; const from = (i * segAngle).toFixed(2); const to   = ((i + 1) * segAngle).toFixed(2); return `${c1} ${from}deg ${to}deg`; }).join(', ');
    let labels = '';
    members.forEach((m, i) => {
        const mid = (i * segAngle) + segAngle / 2;
        const fullName = m.full_name || '';
        const fontSize = n <= 6 ? '0.72rem' : (n <= 10 ? '0.62rem' : '0.54rem');
        const shortName = fullName.length > 22 ? fullName.slice(0, 20) + '…' : fullName;
        labels += `<div style="position:absolute; top:50%; left:50%;transform: translate(-50%,-50%) rotate(${mid}deg) translateY(-88px) rotate(${-mid}deg);font-size:${fontSize}; font-weight:700; color:#fff;text-shadow:0 1px 3px rgba(0,0,0,0.65);pointer-events:none; z-index:5;white-space:nowrap; max-width:150px;overflow:hidden; text-overflow:ellipsis;text-align:center; line-height:1.1;">${escapeHtml(shortName)}</div>`;
    });
    disc.innerHTML = `<div style="position:absolute;inset:0;border-radius:50%;background:conic-gradient(${stops});"></div>${labels}`;
    disc.style.transform = `rotate(${window.__wheelAngle}deg)`;
}

async function spinWheel() {
    if (window.__wheelSpinning) return;
    if (window.__pangkatMode !== 'lot') return;
    if (window.__wheelSelected.length >= 3) { showPickBanner('All 3 members already picked.', 'warn'); return; }
    const members = window.__allLupon.filter(m => !window.__wheelSelected.includes(m.id));
    if (!members.length) return;
    const disc = document.getElementById('wheelDisc');
    const btn  = document.getElementById('spinBtn');
    const res  = document.getElementById('wheelResult');
    if (!disc || !btn) return;
    window.__wheelSpinning = true;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Spinning…';
    if (res) res.textContent = 'Spinning…';
    hidePickBanner();
    btn.style.pointerEvents = 'none';
    const n = members.length;
    const segAngle = 360 / n;
    const winnerIdx = Math.floor(Math.random() * n);
    const winnerMid = winnerIdx * segAngle + segAngle / 2;
    const spins = 4;
    const currentAngle = window.__wheelAngle % 360;
    const targetAngle = window.__wheelAngle + (360 * spins) + ((360 - winnerMid) - currentAngle);
    disc.style.transition = 'transform 3.6s cubic-bezier(0.17, 0.67, 0.22, 1)';
    disc.style.transform = `rotate(${targetAngle}deg)`;
    window.__wheelAngle = targetAngle;
    await new Promise(r => setTimeout(r, 3700));
    const winner = members[winnerIdx];
    window.__wheelSelected.push(winner.id);
    disc.style.transition = 'none';
    window.__wheelAngle = 0;
    disc.style.transform = 'rotate(0deg)';
    renderWheelDisc();
    if (res) res.textContent = `Picked: ${winner.full_name}`;
    showPickBanner(`✔ ${winner.full_name} selected`, 'success');
    window.__wheelSpinning = false;
    btn.style.pointerEvents = 'auto';
    updatePickedList();
    if (window.__wheelSelected.length >= 3) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-check"></i> All 3 Picked'; if (res) res.textContent = 'All 3 selected. Roles will be assigned by the first-selected member.'; }
    else { btn.disabled = false; btn.innerHTML = `<i class="fas fa-dice"></i> Spin to Pick Member #${window.__wheelSelected.length + 1}`; }
}

function showPickBanner(text, variant = 'success') {
    const banner = document.getElementById('pickBanner');
    const txt    = document.getElementById('pickBannerText');
    if (!banner || !txt) return;
    txt.textContent = text;
    banner.classList.remove('warn');
    if (variant === 'warn') banner.classList.add('warn');
    banner.classList.add('show');
    if (banner._hideTimer) clearTimeout(banner._hideTimer);
    banner._hideTimer = setTimeout(() => banner.classList.remove('show'), 2500);
}
function hidePickBanner() { const banner = document.getElementById('pickBanner'); if (banner) banner.classList.remove('show'); }

function updatePickedList() {
    const list = document.getElementById('wheelSelectedList');
    if (!list) return;
    if (!window.__wheelSelected.length) { list.innerHTML = ''; return; }
    list.innerHTML = window.__wheelSelected.map((id, idx) => { const m = window.__allLupon.find(x => x.id === id); const label = idx === 0 ? ' (first-selected)' : ''; return `<div class="wheel-selected-chip"><i class="fas fa-user-check"></i>${escapeHtml(m?.full_name || '')}${label}</div>`; }).join('');
}

function selectPangkatBy(el) {
    const group = el.parentElement;
    const mode = el.dataset.value;
    const prev = window.__pangkatMode;
    group.querySelectorAll('.dm-chip').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('pangkatSelectedBy').value = mode;
    if (prev !== mode) applyPangkatMode(mode);
}

// ============================================================
// SCHEDULE MEDIATION MODAL
// ============================================================
async function openScheduleModal(id) {
    let complaint = null;
    let parties = [];
    let complainants = [], respondents = [], witnesses = [];
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${id}`);
        const d = await r.json();
        if (d.success) {
            complaint = d.complaint;
            complainants = d.complainants || [];
            respondents  = d.respondents  || [];
            witnesses    = d.witnesses    || [];
            parties = [
                ...complainants.map(p => ({ ...p, role: 'Complainant' })),
                ...respondents.map(p => ({ ...p, role: 'Respondent' }))
            ];
        }
    } catch (e) { Swal.fire('Error', 'Could not load.', 'error'); return; }
    if (!complaint) { Swal.fire('Error', 'Not found.', 'error'); return; }

    const isFirst = (complaint.status === 'pending_captain_action');
    const today = new Date().toISOString().split('T')[0];
    const deadlineDate = new Date();
    deadlineDate.setDate(deadlineDate.getDate() + 15);
    const deadlineStr = deadlineDate.toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });

    const linkables = [...complainants, ...respondents];
    const noAccountCount = linkables.filter(p => !partyHasAccount(p)).length;

    const result = await Swal.fire({
        title: '',
        html: `
        <div class="dm-form">
            <div class="dm-header orange">
                <div class="dm-header-left">
                    <i class="fas fa-calendar-plus"></i>
                    <div>
                        <div class="dm-header-title">${isFirst ? 'Set Mediation Schedule' : 'Schedule Another Mediation Hearing'}</div>
                        <div class="dm-header-sub">${isFirst ? 'Issues KP Form #8 (complainant) + KP Form #9 (respondent)' : 'Add a follow-up mediation hearing'}</div>
                    </div>
                </div>
                <div class="dm-header-ref">
                    <div class="dm-header-ref-label">Reference</div>
                    <div class="dm-header-ref-value">${escapeHtml(complaint.reference_number)}</div>
                </div>
            </div>
            <div class="dm-body">
                ${isFirst ? `<div class="dm-warning"><i class="fas fa-hourglass-half"></i><div><div style="font-weight:700;">⏳ 15-Day Mediation Period</div><div style="margin-top:4px;">Mediation must conclude by <b>${deadlineStr}</b>.</div></div></div>` : ''}
                ${isFirst && noAccountCount > 0 ? `<div class="dm-danger" style="animation: pulseBadge 1.5s infinite;"><i class="fas fa-exclamation-triangle"></i><div><b>⚠ ${noAccountCount} part${noAccountCount > 1 ? 'ies' : 'y'} have NO resident account.</b><br>Their KP Form #8 / #9 will still be saved on disk, but no push notification can be delivered. The <b>Secretary will need to print and deliver</b> those notices.</div></div>` : ''}
                <div class="dm-field"><label>Reason <span class="req">*</span></label><select id="schReason"><option value="">— Select —</option>${isFirst ? `<option value="Complaint validated for mediation">Complaint validated</option><option value="Initial mediation conference">Initial conference</option><option value="Both parties willing to mediate">Parties willing</option>` : `<option value="Previous mediation hearing did not settle">Previous hearing did not settle</option><option value="Parties requested another mediation hearing">Parties requested</option><option value="Further discussion needed">Further discussion</option>`}<option value="Other">Other</option></select></div>
                <div class="dm-grid-2"><div class="dm-field"><label>Date <span class="req">*</span></label><input type="date" id="schDate" min="${today}" value="${today}"></div><div class="dm-field"><label>Time <span class="req">*</span></label><input type="time" id="schTime" value="09:00"></div></div>
                <div class="dm-inline-rule"><i class="fas fa-info-circle"></i><span>Office hours: <b>8:00 AM – 6:30 PM</b> · min. 2 hours from now · no overlaps.</span></div>
                <div class="dm-conflict" id="schConflict"><i class="fas fa-exclamation-triangle"></i><div></div></div>
                <div class="dm-available" id="schAvailable"><i class="fas fa-check-circle"></i><span>Available.</span></div>
                <div class="dm-field"><label>Location</label><input type="text" id="schLocation" value="Barangay Hall"></div>
                <div class="dm-field"><label>Notes <span class="opt">(optional)</span></label><textarea id="schNotes" rows="3"></textarea></div>
                <div class="dm-section-title"><i class="fas fa-users"></i> Parties for Mediation (${parties.length})</div>
                ${parties.map(p => { const hasAcct = partyHasAccount(p); const cls = p.role === 'Witness' ? '' : (hasAcct ? 'has-account' : 'no-account'); const badge = p.role === 'Witness' ? '' : (hasAcct ? `<span class="dm-account-badge yes"><i class="fas fa-check-circle"></i> Linked</span>` : `<span class="dm-account-badge no"><i class="fas fa-exclamation-triangle"></i> No Account</span>`); return `<div class="dm-party ${cls}"><i class="fas fa-user" style="color:${hasAcct ? '#2E7D32' : (p.role === 'Witness' ? '#1565c0' : '#c62828')};"></i><div class="dm-party-name">${escapeHtml(p.full_name)}</div>${badge}<div class="dm-party-role ${p.role.toLowerCase()}">${p.role}</div></div>`; }).join('')}
            </div>
        </div>`,
        width: 700,
        allowOutsideClick: false,
        allowEscapeKey: false,
        allowEnterKey: false,
        showCancelButton: true,
        confirmButtonText: `<i class="fas fa-paper-plane"></i> ${isFirst ? 'Issue Notices & Summons' : 'Schedule'}`,
        confirmButtonColor: '#e65100',
        cancelButtonColor: '#6c757d',
        didOpen: async () => {
            const dEl = document.getElementById('schDate');
            const tEl = document.getElementById('schTime');
            const warn = document.getElementById('schConflict');
            const avail = document.getElementById('schAvailable');
            let debounceTimer = null;
            const liveCheck = () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(async () => {
                    if (!dEl.value || !tEl.value) return;
                    try {
                        const r = await fetch(`../admin_complaint_ajax.php?action=check_scheduling_live&date=${dEl.value}&time=${tEl.value}`);
                        const d = await r.json();
                        if (!d.success) return;
                        if (d.min_time) tEl.setAttribute('min', d.min_time);
                        if (d.max_time) tEl.setAttribute('max', d.max_time);
                        if (d.allowed) { warn.classList.remove('show'); avail.classList.add('show'); }
                        else { warn.querySelector('div').innerHTML = `<strong>Not allowed:</strong> ${escapeHtml(d.reason)}`; warn.classList.add('show'); avail.classList.remove('show'); }
                    } catch (e) {}
                }, 300);
            };
            const onDateChange = async () => {
                try {
                    const r = await fetch(`../admin_complaint_ajax.php?action=get_scheduling_limits&date=${dEl.value}`);
                    const d = await r.json();
                    if (!d.success) return;
                    if (!d.allowed) { warn.querySelector('div').innerHTML = `<strong>Not allowed:</strong> ${escapeHtml(d.reason)}`; warn.classList.add('show'); avail.classList.remove('show'); tEl.value = ''; return; }
                    tEl.setAttribute('min', d.min_time);
                    tEl.setAttribute('max', d.max_time);
                    if (!tEl.value || tEl.value < d.min_time || tEl.value > d.max_time) tEl.value = d.min_time;
                    liveCheck();
                } catch (e) {}
            };
            dEl.addEventListener('change', onDateChange);
            tEl.addEventListener('change', liveCheck);
            await onDateChange();
        },
        preConfirm: async () => {
            const reason = document.getElementById('schReason').value;
            const d = document.getElementById('schDate').value;
            const t = document.getElementById('schTime').value;
            const loc = document.getElementById('schLocation').value.trim() || 'Barangay Hall';
            const notes = document.getElementById('schNotes').value.trim();
            if (!reason) { Swal.showValidationMessage('Select a reason.'); return false; }
            if (!d) { Swal.showValidationMessage('Set date.'); return false; }
            if (!t) { Swal.showValidationMessage('Set time.'); return false; }
            try {
                const r = await fetch(`../admin_complaint_ajax.php?action=check_scheduling_live&date=${d}&time=${t}`);
                const resp = await r.json();
                if (resp.success && !resp.allowed) { Swal.showValidationMessage(resp.reason); return false; }
            } catch (e) { Swal.showValidationMessage('Cannot verify.'); return false; }
            return { reason, date: d, time: t, location: loc, notes };
        }
    });

    if (!result.isConfirmed) return;

    const fd = new FormData();
    fd.append('action', 'captain_summon_and_schedule');
    fd.append('complaint_id', id);
    fd.append('hearing_date', result.value.date);
    fd.append('hearing_time', result.value.time);
    fd.append('hearing_location', result.value.location);
    fd.append('schedule_reason', result.value.reason);
    fd.append('notes', result.value.notes);

    Swal.fire({ title: 'Issuing notices & summons…', html: 'Saving PDFs and notifying parties…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        Swal.close();
        if (d.success) {
            const notices = d.notices || [];
            const printRequired = notices.filter(n => !n.notified);
            const noticesHtml = notices.map(n => {
                const isKp8 = n.form === 'KP_FORM_8';
                const cls = isKp8 ? 'kp8' : 'kp9';
                const icon = isKp8 ? 'fa-file-alt' : 'fa-file-signature';
                const label = isKp8 ? 'KP Form #8 — Notice of Hearing' : 'KP Form #9 — Summons';
                const printCls = n.notified ? '' : 'print-required';
                const iconCls = n.notified ? cls : 'print-required';
                const badge = n.notified ? `<span class="notice-item-notified yes"><i class="fas fa-check"></i> Resident notified</span>` : `<span class="notice-item-notified no"><i class="fas fa-print"></i> ⚠ PRINT REQUIRED</span>`;
                return `<div class="notice-item ${cls} ${printCls}"><div class="notice-item-icon ${iconCls}"><i class="fas ${icon}"></i></div><div class="notice-item-body"><div class="notice-item-title">${escapeHtml(n.name)} — ${label}</div><div class="notice-item-meta"><span><i class="fas fa-folder"></i> Saved on disk</span>${badge}</div><button type="button" class="notice-item-btn ${cls}" onclick="openPartyNoticePdf(${n.party_id})"><i class="fas fa-file-pdf"></i> Open PDF</button></div></div>`;
            }).join('') || '<div style="padding:10px;color:#999;font-size:0.85rem;text-align:center;">No notices generated.</div>';
            const printBanner = printRequired.length ? `<div class="dm-danger" style="margin-top:16px;animation: pulseBadge 1.5s infinite;"><i class="fas fa-print"></i><div><b>⚠ PRINT REQUIRED — ${printRequired.length} notice(s)</b><br>The following parties have <b>no resident account</b>. The Secretary has been notified to print and deliver their notices:<ul style="margin-top:8px;font-size:0.82rem;line-height:1.6;">${printRequired.map(n => `<li>${escapeHtml(n.name)} — ${n.form === 'KP_FORM_8' ? 'KP Form #8 (Notice of Hearing)' : 'KP Form #9 (Summons)'}</li>`).join('')}</ul></div></div>` : '';
            Swal.fire({ icon: 'success', title: 'Notices & Summons Issued', html: `<div style="text-align:left;font-size:0.9rem;"><p><b>Mediation Hearing #${d.session_number}</b> · ${escapeHtml(d.hearing_date)} at ${escapeHtml(d.hearing_time)}</p><p style="font-size:0.82rem;color:#555;margin-top:4px;">${escapeHtml(d.hearing_location)}</p><div class="notice-panel"><div class="notice-panel-title"><i class="fas fa-file-signature"></i> Notices Issued (${notices.length})</div>${noticesHtml}</div>${printBanner}</div>`, confirmButtonText: '<i class="fas fa-check"></i> Done', confirmButtonColor: '#2E7D32', width: 720, allowOutsideClick: false }).then(() => reloadComplaints());
        } else { Swal.fire('Error', d.message || 'Failed.', 'error'); }
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}

// ============================================================
// SIGN REFERRAL
// ============================================================
async function openSignReferralModal(id) {
    const result = await Swal.fire({
        title: '',
        html: `<div class="dm-form"><div class="dm-header green"><div class="dm-header-left"><i class="fas fa-signature"></i><div><div class="dm-header-title">Sign & Dispatch Referral</div><div class="dm-header-sub">Escalated case</div></div></div></div><div class="dm-body"><div class="dm-warning"><i class="fas fa-exclamation-triangle"></i><div><b>Final action.</b><br>You certify you reviewed and signed.</div></div><div class="dm-field"><label>Notes <span class="opt">(optional)</span></label><textarea id="signNotes" rows="3"></textarea></div></div></div>`,
        width: 620, allowOutsideClick: false, allowEscapeKey: false, allowEnterKey: false,
        showCancelButton: true, confirmButtonText: '<i class="fas fa-signature"></i> Sign & Dispatch', confirmButtonColor: '#2E7D32', cancelButtonColor: '#6c757d',
        preConfirm: () => ({ notes: document.getElementById('signNotes').value.trim() })
    });
    if (!result.isConfirmed) return;
    Swal.fire({ title: 'Processing…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    const fd = new FormData();
    fd.append('action', 'captain_dispatch_referral');
    fd.append('complaint_id', id);
    fd.append('sign_notes', result.value.notes || '');
    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        Swal.close();
        if (d.success) Swal.fire({ icon: 'success', title: 'Referral Dispatched', confirmButtonColor: '#2E7D32' }).then(() => reloadComplaints());
        else Swal.fire('Error', d.message || 'Failed.', 'error');
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}

// ============================================================
// VIEW COMPLAINT — Audit Trail + Evidence collapsible
// ============================================================
function toggleCdBlock(blockId) { const el = document.getElementById(blockId); if (el) el.classList.toggle('collapsed'); }

function renderEvidenceBlock(evidence) {
    if (!Array.isArray(evidence) || !evidence.length) {
        return `<div class="cd-block collapsed" id="evidenceBlock"><div class="cd-block-title toggleable" onclick="toggleCdBlock('evidenceBlock')"><span style="display:flex;align-items:center;gap:12px;"><i class="fas fa-paperclip"></i> Evidence (0)</span><i class="fas fa-chevron-down cd-arrow"></i></div><div class="cd-block-body"><div style="color:#999;">No evidence uploaded.</div></div></div>`;
    }
    const rows = evidence.map(e => {
        const fname = e.file_name || e.original_name || e.filename || 'evidence-file';
        const fpath = e.file_path || e.filepath || '';
        const niceDate = e.uploaded_at ? new Date(String(e.uploaded_at).replace(' ','T')).toLocaleString('en-US',{dateStyle:'medium',timeStyle:'short'}) : '';
        return `<div class="ev-row"><div class="ev-icon"><i class="fas fa-file-alt"></i></div><div class="ev-body"><div class="ev-name">${escapeHtml(fname)}</div><div class="ev-meta">${niceDate ? `<span><i class="fas fa-clock"></i> ${escapeHtml(niceDate)}</span>` : ''}${e.file_size ? `<span><i class="fas fa-hdd"></i> ${(Number(e.file_size)/1024).toFixed(1)} KB</span>` : ''}</div>${e.description ? `<div class="ev-desc">${escapeHtml(e.description)}</div>` : ''}</div>${fpath ? `<button type="button" class="ev-open" onclick="window.open('../../${escapeHtml(fpath)}','_blank')"><i class="fas fa-external-link-alt"></i> Open</button>` : ''}</div>`;
    }).join('');
    return `<div class="cd-block collapsed" id="evidenceBlock"><div class="cd-block-title toggleable" onclick="toggleCdBlock('evidenceBlock')"><span style="display:flex;align-items:center;gap:12px;"><i class="fas fa-paperclip"></i> Evidence (${evidence.length})</span><i class="fas fa-chevron-down cd-arrow"></i></div><div class="cd-block-body">${rows}</div></div>`;
}

async function viewComplaint(id) {
    let d;
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${id}`);
        d = await r.json();
    } catch (e) { Swal.fire('Error', 'Could not load.', 'error'); return; }
    if (!d || !d.success) { Swal.fire('Error', d.message || 'Error', 'error'); return; }

    const c = d.complaint;
    const updates = d.updates || [];
    const pangkat = d.pangkat || [];
    const evidence = d.evidence || [];
    const kpForms = d.kp_forms || [];
    const isPendingAction = c.status === 'pending_captain_action';
    const isInMediation = ['for_mediation','mediation_scheduled'].includes(c.status);
    const isReferral = ['pending_captain_review','referred_dispatched'].includes(c.status);
    const isWaiting = !!c.absence_appearance_at;
    const isConstitutionScheduled = c.status === 'pangkat_constitution_scheduled';
    const isPangkatReady = c.status === 'failed_mediation';
    const headerClass = isPendingAction ? 'cd-pending' : (isReferral ? 'cd-referral' : '');
    const headerIcon = isPendingAction ? 'fa-inbox' : (isReferral ? 'fa-file-signature' : (isInMediation ? 'fa-gavel' : 'fa-folder-open'));

    const sessions = (d.hearings || []).filter(h => h.session_type === 'mediation' || h.session_type === 'conciliation');
    const sessionHtml = sessions.length ? `<div class="cd-block"><div class="cd-block-title"><i class="fas fa-list-ol"></i> Mediation / Conciliation Hearings (${sessions.length})</div>${sessions.map(h => { const sr = h.session_record || {}; const srLines = []; if (sr.summary) srLines.push(`<div><b>Hearing summary:</b> ${escapeHtml(sr.summary).replace(/\n/g,'<br>')}</div>`); return `<div style="background:#fafbfa;border-left:4px solid #1565c0;border-radius:10px;padding:12px 16px;margin-bottom:10px;cursor:pointer;" onclick="openHearingDetail(${parseInt(h.id)})" title="Click to view full details"><div style="font-weight:700;color:#1a472a;display:flex;justify-content:space-between;align-items:center;gap:8px;"><span>Hearing #${h.session_number || 1} — ${escapeHtml(h.session_type || '')}</span><button type="button" class="prev-hearing-view-btn" onclick="event.stopPropagation(); openHearingDetail(${parseInt(h.id)})"><i class="fas fa-eye"></i> View</button></div><div style="font-size:0.82rem;color:#555;margin-top:6px;"><div><i class="fas fa-calendar"></i> ${new Date(h.hearing_date).toLocaleDateString('en-US',{dateStyle:'long'})} at ${h.hearing_time || '—'}</div>${h.summary ? `<div style="margin-top:4px;"><b>Notes:</b> ${escapeHtml(h.summary)}</div>` : ''}${srLines.length ? `<div style="margin-top:8px;padding-top:8px;border-top:1px dashed #ccc;font-size:0.8rem;line-height:1.55;">${srLines.join('')}</div>` : ''}</div></div>`; }).join('')}</div>` : '';

    const pangkatHtml = pangkat.length ? `<div class="cd-block"><div class="cd-block-title"><i class="fas fa-balance-scale"></i> Pangkat Panel (${pangkat.length})</div>${pangkat.map((p, i) => { const roleText = (p.role === 'pending' || !p.role) ? 'Awaiting Position Assignment' : p.role.toUpperCase(); const isFirst = (i === 0); return `<div class="cd-person" style="margin-bottom:8px;"><div class="cd-person-avatar" style="background:#f1f8e9;color:#2E7D32;"><i class="fas fa-user-tie"></i></div><div style="flex:1;"><div class="cd-person-name">${escapeHtml(p.full_name)} ${isFirst ? '<span style="font-size:0.7rem;color:#2E7D32;font-weight:700;">(first-selected)</span>' : ''}</div><div class="cd-person-meta"><span style="text-transform:uppercase;font-weight:700;font-size:0.7rem;color:#666;">${escapeHtml(roleText)}</span>${p.kp11_pdf_path ? `<button type="button" class="notice-item-btn kp11" style="padding:4px 10px;font-size:0.68rem;" onclick="openKp11Pdf(${c.id}, ${p.member_id})"><i class="fas fa-file-pdf"></i> KP #11</button>` : ''}</div></div></div>`; }).join('')}</div>` : '';

    let certHtml = '';
    if (c.bar_action_cert_no || c.bar_counterclaim_cert_no) {
        certHtml = `<div class="cd-block"><div class="cd-block-title"><i class="fas fa-gavel"></i> Auto-issued Certifications</div>${c.bar_action_cert_no ? `<div class="notice-item kp23" style="margin-bottom:8px;"><div class="notice-item-icon kp23"><i class="fas fa-gavel"></i></div><div class="notice-item-body"><div class="notice-item-title">KP Form #23 — Certification to Bar Action</div><div class="notice-item-meta"><span>Cert No: <b>${escapeHtml(c.bar_action_cert_no)}</b></span></div><button type="button" class="notice-item-btn kp23" onclick="openBarCertPdf(${c.id}, 'action')"><i class="fas fa-file-pdf"></i> Open PDF</button></div></div>` : ''}${c.bar_counterclaim_cert_no ? `<div class="notice-item kp24" style="margin-bottom:8px;"><div class="notice-item-icon kp24"><i class="fas fa-ban"></i></div><div class="notice-item-body"><div class="notice-item-title">KP Form #24 — Certification to Bar Counterclaim</div><div class="notice-item-meta"><span>Cert No: <b>${escapeHtml(c.bar_counterclaim_cert_no)}</b></span></div><button type="button" class="notice-item-btn kp24" onclick="openBarCertPdf(${c.id}, 'counterclaim')"><i class="fas fa-file-pdf"></i> Open PDF</button></div></div>` : ''}</div>`;
    }

    const auditTrailHtml = `<div class="cd-block collapsed" id="auditTrailBlock"><div class="cd-block-title toggleable" onclick="toggleCdBlock('auditTrailBlock')"><span style="display:flex;align-items:center;gap:12px;"><i class="fas fa-history"></i> Audit Trail (${updates.length})</span><i class="fas fa-chevron-down cd-arrow"></i></div><div class="cd-block-body">${updates.length ? updates.map(u => `<div class="cd-history-item" style="margin-bottom:8px;"><div class="cd-history-time">${new Date(u.created_at).toLocaleString()}</div><div class="cd-history-notes">${escapeHtml(u.notes || '').replace(/\n/g,'<br>')}</div><div class="cd-history-meta">${u.updated_by_role ? 'by ' + escapeHtml(u.updated_by_role) : ''}</div></div>`).join('') : '<div style="color:#999;">No history.</div>'}</div></div>`;

    await Swal.fire({
        title: '',
        html: `
        <div class="cd-modal">
            <div class="cd-header ${headerClass}">
                <div class="cd-header-left"><i class="fas ${headerIcon}"></i><div><div class="cd-title">${escapeHtml(c.title)}</div><div class="cd-sub">Ref: <b>${escapeHtml(c.reference_number)}</b></div></div></div>
                <div class="cd-status">${(c.status || '').replace(/_/g,' ').toUpperCase()}</div>
            </div>
            <div class="cd-body">
                <div class="cd-block"><div class="cd-block-title"><span class="cd-num">1</span> Info</div><div class="cd-grid"><div class="cd-grid-item"><label>Ref</label><span>${escapeHtml(c.reference_number)}</span></div><div class="cd-grid-item"><label>Filed</label><span>${new Date(c.created_at).toLocaleString('en-US',{dateStyle:'medium',timeStyle:'short'})}</span></div><div class="cd-grid-item"><label>Subject</label><span>${escapeHtml(c.complaint_subject || '-')}</span></div><div class="cd-grid-item"><label>Priority</label><span>${(c.priority || 'medium').toUpperCase()}</span></div></div><div class="cd-text-block"><label>Description</label><p>${escapeHtml(c.description || '-').replace(/\n/g,'<br>')}</p></div></div>
                <div class="cd-block"><div class="cd-block-title"><span class="cd-num">2</span> Complainants (${(d.complainants || []).length})</div>${(d.complainants || []).map((p,i) => `<div class="cd-person"><div class="cd-person-avatar" style="background:#e8f5e9;color:#2E7D32;"><i class="fas fa-user"></i></div><div><div class="cd-person-name">${i+1}. ${escapeHtml(p.full_name)}</div><div class="cd-person-meta">${p.contact_number ? `<span><i class="fas fa-phone"></i> ${escapeHtml(p.contact_number)}</span>` : ''}</div></div></div>`).join('')}</div>
                <div class="cd-block"><div class="cd-block-title"><span class="cd-num">3</span> Respondents (${(d.respondents || []).length})</div>${(d.respondents || []).map((p,i) => `<div class="cd-person"><div class="cd-person-avatar" style="background:#fff3e0;color:#e65100;"><i class="fas fa-user-friends"></i></div><div><div class="cd-person-name">${i+1}. ${escapeHtml(p.full_name)}</div></div></div>`).join('')}</div>
                ${certHtml}
                ${pangkatHtml}
                ${sessionHtml}
                ${renderKpFormsBlock(kpForms)}
                ${renderEvidenceBlock(evidence)}
                ${auditTrailHtml}
            </div>
            <div class="cd-actions">
                ${isPendingAction ? `<button class="cd-btn schedule" onclick="Swal.close(); openScheduleModal(${c.id});"><i class="fas fa-calendar-plus"></i> Schedule Mediation</button>` : ''}
                ${isInMediation ? `<button class="cd-btn primary" onclick="Swal.close(); openMediationSessionModal(${c.id});"><i class="fas fa-play-circle"></i> ${isWaiting ? 'Continue Mediation' : 'Start Mediation'}</button>` : ''}
                ${isPangkatReady ? `<button class="cd-btn schedule" onclick="Swal.close(); openMediationSessionModal(${c.id});"><i class="fas fa-calendar-plus"></i> Set Constitution Meeting</button>` : ''}
                ${isConstitutionScheduled ? `<button class="cd-btn primary" onclick="Swal.close(); openMediationSessionModal(${c.id});"><i class="fas fa-users-cog"></i> Continue Constitution</button>` : ''}
                ${c.status === 'pending_captain_review' ? `<button class="cd-btn primary" onclick="Swal.close(); openSignReferralModal(${c.id});"><i class="fas fa-signature"></i> Sign & Dispatch</button>` : ''}
                <button class="cd-btn" style="background:#1565c0;color:#fff;" onclick="openCaseKpFormsModal(${c.id})"><i class="fas fa-file-signature"></i> Generate KP Forms</button>
                <button class="cd-btn secondary" onclick="Swal.close()">Close</button>
            </div>
        </div>`,
        width: 1080, showConfirmButton: false, showCancelButton: false, customClass: { popup: 'cd-popup' }
    });
}

async function reloadComplaints() {
    await loadAllComplaints();
    switchComplaintView(currentComplaintView);
}

function navigateTo(view) {
    document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active'));
    const item = document.querySelector(`.nav-item[data-view="${view}"]`);
    if (item) item.classList.add('active');
    if (view === 'dashboard') { document.getElementById('dashboardBody').innerHTML = renderDashboard(); displayCurrentDate(); }
    else if (view === 'complaints') { document.getElementById('dashboardBody').innerHTML = ''; renderComplaintsView(); }
}
document.querySelectorAll('.nav-item[data-view]').forEach(item => { item.addEventListener('click', () => navigateTo(item.getAttribute('data-view'))); });
function displayCurrentDate() { const el = document.getElementById('currentDate'); if (el) el.innerHTML = new Date().toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }); }
navigateTo('dashboard');

// ============================================================
// NOTIFICATIONS + PUSH (with select-all + delete)
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

    selectAll.addEventListener('change', () => {
        document.querySelectorAll('.notif-item .notif-check').forEach(cb => { cb.checked = selectAll.checked; });
        updateDeleteBtn();
    });

    deleteBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        const ids = Array.from(document.querySelectorAll('.notif-item .notif-check:checked')).map(cb => cb.value);
        if (!ids.length) return;
        const confirm = await Swal.fire({
            icon: 'warning', title: 'Delete notifications?', text: `${ids.length} notification(s) will be removed.`,
            showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#c62828', cancelButtonColor: '#6c757d'
        });
        if (!confirm.isConfirmed) return;
        const fd = new FormData();
        fd.append('action', 'delete_notifications');
        fd.append('notification_ids', JSON.stringify(ids));
        try {
            const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d.success) {
                selectAll.checked = false;
                fetchNotifications();
                Swal.fire({ icon: 'success', title: 'Deleted', timer: 1200, showConfirmButton: false });
            } else Swal.fire('Error', d.message || 'Failed.', 'error');
        } catch (err) { Swal.fire('Error', 'Network error.', 'error'); }
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
    fetch('../admin_complaint_ajax.php', { method: 'POST', body: new URLSearchParams({ action: 'mark_notification_read', notification_id: id }) }).catch(() => {});
    if (refType === 'complaint' && refId) {
        navigateTo('complaints');
        setTimeout(() => { if (typeof viewComplaint === 'function') viewComplaint(refId); }, 300);
    } else setTimeout(fetchNotifications, 300);
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

document.addEventListener('DOMContentLoaded', initNotifications);
</script>
</body>
</html>