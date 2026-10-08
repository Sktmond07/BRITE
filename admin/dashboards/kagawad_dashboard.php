<?php
// admin/dashboards/kagawad_dashboard.php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../../sign_in.php');
    exit();
}

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../../config/database.php';

// Current admin
$admin_id = $_SESSION['user_id'];
$roleSql = "SELECT admin_role FROM admin WHERE id = ?";
$roleStmt = mysqli_prepare($conn, $roleSql);
mysqli_stmt_bind_param($roleStmt, "i", $admin_id);
mysqli_stmt_execute($roleStmt);
$roleResult = mysqli_stmt_get_result($roleStmt);
$adminRole = mysqli_fetch_assoc($roleResult)['admin_role'];
mysqli_stmt_close($roleStmt);

if ($adminRole !== 'kagawad') {
    switch($adminRole) {
        case 'captain':     header('Location: captain_dashboard.php'); break;
        case 'secretary':   header('Location: secretary_dashboard.php'); break;
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
$admin = mysqli_fetch_assoc(mysqli_stmt_get_result($adminStmt));
mysqli_stmt_close($adminStmt);

// ============ STATS ============
$stats = [];

$r = mysqli_query($conn, "SELECT COUNT(*) AS total FROM complaints WHERE assigned_to = $admin_id AND status IN ('pending','for_investigation')");
$stats['pending'] = $r ? mysqli_fetch_assoc($r)['total'] : 0;

$r = mysqli_query($conn, "SELECT COUNT(*) AS total FROM complaints WHERE assigned_to = $admin_id AND status = 'investigating'");
$stats['investigating'] = $r ? mysqli_fetch_assoc($r)['total'] : 0;

$r = mysqli_query($conn, "SELECT COUNT(*) AS total FROM complaints WHERE assigned_to = $admin_id AND status = 'settled'");
$stats['settled'] = $r ? mysqli_fetch_assoc($r)['total'] : 0;

$r = mysqli_query($conn, "SELECT COUNT(*) AS total FROM complaints WHERE assigned_to = $admin_id AND status = 'escalated'");
$stats['escalated'] = $r ? mysqli_fetch_assoc($r)['total'] : 0;

$r = mysqli_query($conn, "SELECT COUNT(*) AS total FROM complaints WHERE assigned_to = $admin_id");
$stats['total'] = $r ? mysqli_fetch_assoc($r)['total'] : 0;

// Recent complaints
$recentComplaints = [];
$r = mysqli_query($conn, "
    SELECT c.id, c.title, c.description, c.status, c.reference_number, c.created_at,
           c.complaint_subject, c.priority,
           r.first_name, r.last_name
    FROM complaints c
    LEFT JOIN resident r ON c.resident_id = r.id
    WHERE c.assigned_to = $admin_id
    ORDER BY c.created_at DESC
    LIMIT 10
");
if ($r) while ($row = mysqli_fetch_assoc($r)) $recentComplaints[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>Kagawad Dashboard | BRITE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        <?php include '../admin.css'; ?>

        .sidebar { background: linear-gradient(180deg, #2E7D32 20%, #43a047 80%); }
        .sidebar::-webkit-scrollbar-thumb { background-color: #81c784; }
        .welcome-card { background: linear-gradient(135deg, #2E7D32, #43a047); }
        .stat-icon.pending { background: #fff3e0; color: #e65100; }
        .stat-icon.investigating { background: #e3f2fd; color: #1565c0; }
        .stat-icon.resolved { background: #e8f5e9; color: #2e7d32; }
        .stat-icon.total { background: #f3e5f5; color: #9c27b0; }
        .stat-icon.escalated { background: #fce4ec; color: #c2185b; }
        .btn-verify { background: #1565C0; }
        .btn-verify:hover { background: #0d47a1; }
        .quick-action-btn:hover { background: #1565C0; border-color: #1565C0; }

        .complaint-item { background: white; border-radius: 12px; padding: 15px; margin-bottom: 15px; cursor: pointer; transition: all 0.3s; border-left: 4px solid #1565C0; }
        .complaint-item:hover { transform: translateX(5px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .complaint-title { font-weight: 600; color: #1a472a; margin-bottom: 8px; }
        .complaint-detail { font-size: 0.82rem; color: #666; }
        .complaint-status { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; margin-top: 8px; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-investigating, .status-for_investigation { background: #cce5ff; color: #004085; }
        .status-settled, .status-resolved { background: #d4edda; color: #155724; }
        .status-dismissed { background: #f8d7da; color: #721c24; }
        .status-escalated { background: #fce4ec; color: #c2185b; }

        /* ============ Notification Bell ============ */
        .header-right-group { display: flex; align-items: center; gap: 6px; margin-left: auto; }
        .notification-bell-wrap { position: relative; }
        .notification-bell {
            width: 44px; height: 44px; border-radius: 50%;
            background: #f3f6f4; border: 1px solid #e2efe8;
            color: #2E7D32; font-size: 1.1rem;
            cursor: pointer; position: relative; transition: all .2s;
        }
        .notification-bell:hover { background: #e8f5e9; transform: scale(1.05); }
        .notification-bell.has-new { animation: bellRing .9s ease-in-out 1; }
        @keyframes bellRing {
            0%,100% { transform: rotate(0); }
            20% { transform: rotate(-15deg); }
            40% { transform: rotate(15deg); }
            60% { transform: rotate(-10deg); }
            80% { transform: rotate(10deg); }
        }
        .notif-badge {
            position: absolute; top: -2px; right: -2px;
            min-width: 20px; height: 20px; padding: 0 5px;
            background: #dc3545; color: #fff;
            font-size: 0.68rem; font-weight: 700; border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 0 0 2px #fff;
        }
        .notif-dropdown {
            display: none; position: absolute; top: 52px; right: 0;
            width: 360px; max-width: 92vw;
            background: #fff; border-radius: 14px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            border: 1px solid #e2efe8; z-index: 1000; overflow: hidden;
        }
        .notif-dropdown.show { display: block; }
        .notif-header {
            padding: 12px 16px; background: linear-gradient(135deg,#2E7D32,#43a047);
            color: #fff; display: flex; justify-content: space-between; align-items: center;
            font-weight: 600; font-size: 0.9rem;
        }
        .notif-mark-read {
            background: rgba(255,255,255,0.2); color: #fff;
            border: none; padding: 4px 10px; border-radius: 20px;
            font-size: 0.7rem; cursor: pointer;
        }
        .notif-mark-read:hover { background: rgba(255,255,255,0.35); }
        .notif-list { max-height: 380px; overflow-y: auto; }
        .notif-item {
            padding: 12px 16px; border-bottom: 1px solid #f0f0f0;
            display: flex; gap: 10px; cursor: pointer; transition: background .15s;
        }
        .notif-item:hover { background: #f8faf8; }
        .notif-item.unread { background: #f1f8e9; }
        .notif-icon {
            width: 34px; height: 34px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; font-size: 0.9rem;
        }
        .notif-icon.assignment { background:#e3f2fd; color:#1565c0; }
        .notif-icon.hearing    { background:#f3e5f5; color:#6a1b9a; }
        .notif-icon.info       { background:#e8f5e9; color:#2E7D32; }
        .notif-icon.warning    { background:#fff3e0; color:#e65100; }
        .notif-icon.success    { background:#e8f5e9; color:#2E7D32; }
        .notif-body { flex: 1; min-width: 0; }
        .notif-title { font-weight: 700; font-size: 0.85rem; color: #1a472a; }
        .notif-msg   { font-size: 0.78rem; color: #555; margin-top: 3px; line-height: 1.35; }
        .notif-time  { font-size: 0.68rem; color: #999; margin-top: 4px; }
        .notif-empty { padding: 30px; text-align: center; color: #999; font-size: 0.85rem; }
        .notif-footer { padding: 10px 16px; background: #f8faf8; border-top: 1px solid #e2efe8; font-size: 0.78rem; }
        .notif-permission-label { display: flex; align-items: center; gap: 8px; cursor: pointer; color: #555; }

        /* ============ Full Case Details Modal ============ */
        .case-modal-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.65);
            z-index: 9999;
            align-items: flex-start;
            justify-content: center;
            padding: 30px 20px;
            overflow-y: auto;
        }
        .case-modal-overlay.show { display: flex; }
        .case-modal {
            background: #eef2ef;
            border-radius: 18px;
            max-width: 980px;
            width: 100%;
            max-height: 92vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.4);
            animation: caseIn .25s ease;
        }
        @keyframes caseIn {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .case-modal-header {
            background: linear-gradient(135deg, #1b5e20, #2E7D32 55%, #43a047);
            color: #fff;
            padding: 16px 22px;
            display: flex; justify-content: space-between; align-items: center;
            flex-shrink: 0;
        }
        .case-modal-header h3 {
            margin: 0; font-size: 1rem; font-weight: 700;
            display: flex; align-items: center; gap: 10px;
        }
        .case-modal-header .ref {
            font-family: 'Courier New', monospace;
            background: rgba(255,255,255,0.18);
            padding: 2px 10px; border-radius: 6px;
            font-size: 0.82rem; margin-left: 8px;
        }
        .case-modal-close {
            background: rgba(255,255,255,0.2);
            color: #fff; border: none;
            width: 36px; height: 36px; border-radius: 50%;
            font-size: 1.4rem; cursor: pointer; line-height: 1;
            transition: all 0.2s;
        }
        .case-modal-close:hover { background: rgba(255,255,255,0.35); }
        .case-modal-body {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
        }
        .case-modal-footer {
            padding: 12px 20px;
            background: #fff;
            border-top: 1px solid #e2efe8;
            display: flex; justify-content: flex-end; gap: 10px;
            flex-shrink: 0;
        }
        .case-btn {
            border: none;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            text-decoration: none;
        }
        .case-btn-primary { background: #1565C0; color: #fff; }
        .case-btn-primary:hover { background: #0d47a1; transform: translateY(-1px); }
        .case-btn-close { background: #f0f0f0; color: #333; }
        .case-btn-close:hover { background: #e0e0e0; }

        /* Document-style complaint card */
        .doc-wrap {
            background: #fff;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e0e6e2;
        }
        .doc-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 18px 24px;
            background: #f9fbfa;
            border-bottom: 3px double #2E7D32;
        }
        .doc-header-left { display: flex; gap: 14px; align-items: center; }
        .doc-seal {
            width: 54px; height: 54px; border-radius: 50%;
            background: #e8f5e9; color: #2E7D32;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.6rem; border: 2px solid #a5d6a7;
        }
        .doc-republic { font-size: 0.72rem; color: #666; text-transform: uppercase; }
        .doc-barangay { font-size: 1.05rem; font-weight: 800; color: #1a472a; }
        .doc-municipality { font-size: 0.78rem; color: #666; }
        .doc-header-right { text-align: right; }
        .doc-docname { font-size: 0.72rem; color: #888; text-transform: uppercase; }
        .doc-ref {
            font-size: 0.95rem; font-weight: 700;
            color: #2E7D32; font-family: 'Courier New', monospace; margin-top: 2px;
        }
        .doc-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            padding: 14px 24px;
            background: #fff;
            border-bottom: 1px solid #eee;
        }
        .doc-meta-item { display: flex; flex-direction: column; }
        .doc-meta-label { font-size: 0.68rem; text-transform: uppercase; color: #999; margin-bottom: 3px; }
        .doc-meta-value { font-size: 0.88rem; color: #333; font-weight: 500; }
        .doc-pill { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; }
        .doc-pill-status { background: #e8f5e9; color: #2E7D32; }
        .doc-section { padding: 18px 24px; border-bottom: 1px solid #f0f0f0; }
        .doc-section:last-child { border-bottom: none; }
        .doc-section-title {
            display: flex; align-items: center; gap: 12px;
            font-size: 0.95rem; font-weight: 800;
            color: #1a472a; text-transform: uppercase; margin-bottom: 14px;
        }
        .doc-num {
            display: inline-flex; align-items: center; justify-content: center;
            width: 28px; height: 28px;
            background: #2E7D32; color: #fff;
            border-radius: 50%; font-size: 0.82rem; font-weight: 700;
            flex-shrink: 0;
        }
        .doc-field { margin-bottom: 14px; }
        .doc-field:last-child { margin-bottom: 0; }
        .doc-field-label { font-size: 0.68rem; text-transform: uppercase; color: #999; margin-bottom: 4px; }
        .doc-field-value { font-size: 0.92rem; color: #1a1a1a; line-height: 1.55; }
        .doc-field-title { font-weight: 700; font-size: 1.05rem; color: #1a472a; }
        .doc-field-description {
            background: #fafbfa;
            border-left: 3px solid #2E7D32;
            padding: 10px 14px;
            border-radius: 0 8px 8px 0;
        }
        .doc-person {
            display: flex; gap: 12px; align-items: flex-start;
            padding: 10px 12px; border-radius: 10px;
            background: #fafbfa; margin-bottom: 8px;
            border: 1px solid #f0f0f0;
        }
        .doc-person:last-child { margin-bottom: 0; }
        .doc-person-avatar {
            width: 38px; height: 38px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .doc-person-name { font-weight: 700; color: #1a1a1a; font-size: 0.92rem; }
        .doc-person-detail {
            font-size: 0.8rem; color: #666; margin-top: 3px;
            display: flex; flex-wrap: wrap; gap: 12px;
        }
        .doc-incident {
            background: #fafbfa; border-radius: 10px;
            padding: 14px 16px; margin-bottom: 12px;
            border-left: 4px solid #6a1b9a;
        }
        .doc-incident:last-child { margin-bottom: 0; }
        .doc-incident-head {
            display: flex; justify-content: space-between;
            align-items: center; margin-bottom: 6px; flex-wrap: wrap;
        }
        .doc-incident-num { font-weight: 800; color: #6a1b9a; font-size: 0.85rem; text-transform: uppercase; }
        .doc-incident-meta { font-size: 0.78rem; color: #666; display: flex; gap: 12px; }
        .doc-incident-loc { font-size: 0.83rem; color: #444; margin-bottom: 6px; }
        .doc-incident-desc { font-size: 0.88rem; color: #222; }
        .doc-hearing {
            background: #f3e5f5; border-left: 4px solid #6a1b9a;
            padding: 12px 16px; border-radius: 0 10px 10px 0;
            margin-bottom: 10px;
        }
        .doc-hearing:last-child { margin-bottom: 0; }
        .doc-hearing-date { font-weight: 700; color: #4a148c; font-size: 0.9rem; }
        .doc-hearing-detail { font-size: 0.82rem; color: #555; margin-top: 4px; }
        .doc-timeline { position: relative; padding-left: 26px; }
        .doc-timeline::before {
            content: ''; position: absolute;
            left: 9px; top: 8px; bottom: 8px;
            width: 2px; background: #d4e6d4;
        }
        .doc-timeline-item { position: relative; padding-bottom: 14px; }
        .doc-timeline-item:last-child { padding-bottom: 0; }
        .doc-timeline-item::before {
            content: ''; position: absolute;
            left: -22px; top: 4px;
            width: 12px; height: 12px; border-radius: 50%;
            background: #43e97b; border: 2px solid #fff;
            box-shadow: 0 0 0 2px #d4e6d4;
        }
        .doc-timeline-title { font-size: 0.85rem; font-weight: 700; color: #1a472a; }
        .doc-timeline-meta { font-size: 0.72rem; color: #888; margin-top: 2px; }
        .doc-timeline-notes {
            font-size: 0.82rem; color: #444; margin-top: 4px;
            background: #f8faf8; padding: 6px 10px; border-radius: 6px;
        }
        .doc-evidence-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 10px;
        }
        .doc-evidence-item {
            background: #fafbfa;
            border: 1px solid #e2efe8;
            border-radius: 8px;
            padding: 10px;
            text-align: center;
            font-size: 0.78rem;
            color: #333;
            text-decoration: none;
            transition: all 0.2s;
        }
        .doc-evidence-item:hover { background: #e8f5e9; border-color: #43e97b; }
        .doc-evidence-item i { font-size: 1.8rem; color: #1565C0; margin-bottom: 6px; display: block; }
        .doc-empty { color: #999; font-style: italic; font-size: 0.85rem; text-align: center; padding: 10px 0; }

        @media (max-width: 768px) {
            .notif-dropdown { width: 300px; }
        }
        @media (max-width: 640px) {
            .case-modal-overlay { padding: 10px; }
            .case-modal { max-height: 96vh; }
            .doc-meta { grid-template-columns: 1fr 1fr; }
            .doc-evidence-list { grid-template-columns: 1fr 1fr; }
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
            <div class="nav-item active"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></div>
            <div class="nav-item" onclick="showMyInvestigations()"><i class="fas fa-search"></i><span>My Investigations</span></div>
            <div class="nav-item" onclick="window.location.href='complaints.php'"><i class="fas fa-list"></i><span>All Complaints</span></div>
            <div class="nav-item" onclick="window.location.href='schedule_hearing.php'"><i class="fas fa-calendar"></i><span>Schedule Hearing</span></div>
            <div class="nav-item" onclick="window.location.href='my_schedule.php'"><i class="fas fa-calendar-alt"></i><span>My Schedule</span></div>
        </div>
        <div class="sidebar-footer">
            <div class="admin-badge">
                <div class="mini-avatar"><i class="fas fa-user-tie"></i></div>
                <div class="admin-info">
                    <h5><?php echo htmlspecialchars($admin['full_name']); ?></h5>
                    <p>Kagawad</p>
                </div>
            </div>
        </div>
    </aside>

    <main class="main-content">
        <div class="top-header">
            <div class="page-title">
                <h1>Kagawad Dashboard</h1>
                <p>Manage and resolve resident complaints</p>
            </div>

            <!-- Bell + Profile -->
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
                        <div class="role">Kagawad</div>
                    </div>
                    <div class="avatar-container">
                        <div class="avatar-fallback"><i class="fas fa-user-tie"></i></div>
                    </div>
                    <div class="profile-dropdown" id="profileDropdown">
                        <div class="dropdown-header">
                            <div class="user-name"><?php echo htmlspecialchars($admin['full_name']); ?></div>
                            <div class="user-email"><?php echo htmlspecialchars($admin['email']); ?></div>
                        </div>
                        <div class="dropdown-divider"></div>
                        <div class="dropdown-item logout-item" id="logoutBtn">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="dashboard-body" id="dashboardBody">
            <div class="modern-dashboard">
                <div class="welcome-card">
                    <div class="welcome-content">
                        <div class="welcome-text">
                            <h2>Welcome, Kagawad <?php echo htmlspecialchars($admin['full_name']); ?>!</h2>
                            <p>Handle complaints and facilitate resolutions for your constituents.</p>
                        </div>
                        <div class="welcome-date">
                            <i class="fas fa-calendar-alt"></i>
                            <span id="currentDate"></span>
                        </div>
                    </div>
                </div>

                <div class="stats-grid-modern">
                    <div class="stat-card-modern">
                        <div class="stat-icon pending"><i class="fas fa-clock"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['pending']; ?></h3><p>Pending</p><span class="stat-trend"><i class="fas fa-hourglass-half"></i> Needs action</span></div>
                    </div>
                    <div class="stat-card-modern">
                        <div class="stat-icon investigating"><i class="fas fa-search"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['investigating']; ?></h3><p>Investigating</p><span class="stat-trend"><i class="fas fa-gavel"></i> In progress</span></div>
                    </div>
                    <div class="stat-card-modern">
                        <div class="stat-icon resolved"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['settled']; ?></h3><p>Settled</p><span class="stat-trend positive"><i class="fas fa-check"></i> Completed</span></div>
                    </div>
                    <div class="stat-card-modern">
                        <div class="stat-icon escalated"><i class="fas fa-arrow-up"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['escalated']; ?></h3><p>Escalated</p><span class="stat-trend"><i class="fas fa-exclamation"></i> To captain</span></div>
                    </div>
                    <div class="stat-card-modern">
                        <div class="stat-icon total"><i class="fas fa-folder-open"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['total']; ?></h3><p>Total Assigned</p><span class="stat-trend"><i class="fas fa-chart-line"></i> All cases</span></div>
                    </div>
                </div>

                <div class="quick-actions">
                    <h4><i class="fas fa-bolt"></i> Quick Actions</h4>
                    <div class="action-buttons">
                        <button class="quick-action-btn" onclick="showMyInvestigations()"><i class="fas fa-search"></i> My Investigations</button>
                        <button class="quick-action-btn" onclick="window.location.href='complaints.php'"><i class="fas fa-list"></i> All Complaints</button>
                        <button class="quick-action-btn" onclick="window.location.href='schedule_hearing.php'"><i class="fas fa-calendar-plus"></i> Schedule Hearing</button>
                    </div>
                </div>

                <div class="activity-card">
                    <div class="activity-header">
                        <h4><i class="fas fa-exclamation-triangle"></i> Recent Assigned Complaints</h4>
                        <button class="view-all-btn" onclick="showMyInvestigations()">View All <i class="fas fa-arrow-right"></i></button>
                    </div>
                    <div class="activity-list">
                        <?php if (empty($recentComplaints)): ?>
                            <div class="empty-state-mini"><p>No complaints assigned yet.</p></div>
                        <?php else: ?>
                            <?php foreach ($recentComplaints as $c): ?>
                                <div class="complaint-item" onclick="viewComplaintSummary(<?php echo (int)$c['id']; ?>)">
                                    <div class="complaint-title"><?php echo htmlspecialchars($c['title']); ?></div>
                                    <div class="complaint-detail">
                                        <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($c['reference_number'] ?: ('CMP-' . $c['id'])); ?>
                                        &nbsp;|&nbsp; <i class="fas fa-user"></i> <?php echo htmlspecialchars(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))); ?>
                                    </div>
                                    <span class="complaint-status status-<?php echo htmlspecialchars($c['status']); ?>">
                                        <?php echo htmlspecialchars(str_replace('_', ' ', strtoupper($c['status']))); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<form id="logoutForm" method="POST" action="../../logout.php" style="display: none;"></form>

<!-- ============ FULL CASE DETAILS MODAL ============ -->
<div id="caseModal" class="case-modal-overlay">
    <div class="case-modal">
        <div class="case-modal-header">
            <h3>
                <i class="fas fa-folder-open"></i>
                Complaint Case File
                <span class="ref" id="caseModalRef">—</span>
            </h3>
            <button class="case-modal-close" id="caseModalClose" type="button" aria-label="Close">&times;</button>
        </div>
        <div class="case-modal-body" id="caseModalBody">
            <div style="text-align:center;padding:60px 20px;">
                <i class="fas fa-spinner fa-spin fa-3x" style="color:#2E7D32;"></i>
                <p style="color:#666;">Loading case details…</p>
            </div>
        </div>
        <div class="case-modal-footer">
            <button class="case-btn case-btn-close" id="caseModalCancel" type="button">
                <i class="fas fa-times"></i> Close
            </button>
            <button class="case-btn case-btn-primary" id="caseModalSubmitBtn" type="button" style="display:none;">
                <i class="fas fa-edit"></i> Submit Findings
            </button>
        </div>
    </div>
</div>

<script>
// ============ PROFILE / SIDEBAR ============
const profileArea = document.getElementById('profileArea');
const profileDropdown = document.getElementById('profileDropdown');
const logoutBtn = document.getElementById('logoutBtn');
if (profileArea) {
    profileArea.addEventListener('click', (e) => { e.stopPropagation(); profileDropdown.classList.toggle('show'); });
    document.addEventListener('click', (e) => { if (!profileArea.contains(e.target)) profileDropdown.classList.remove('show'); });
}
if (logoutBtn) logoutBtn.addEventListener('click', (e) => { e.preventDefault(); if (confirm('Logout?')) document.getElementById('logoutForm').submit(); });

document.getElementById('currentDate').innerHTML = new Date().toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });

const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
if (menuToggle) menuToggle.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('hide'); });
if (overlay) overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.add('hide'); });

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============ SESSION MANAGEMENT ============
const SESSION_TIMEOUT = 10 * 60 * 1000;
let inactivityTimer;
function resetInactivityTimer() {
    clearTimeout(inactivityTimer);
    inactivityTimer = setTimeout(showTimeoutWarning, SESSION_TIMEOUT - 30000);
}
function showTimeoutWarning() {
    Swal.fire({
        title: 'Session Expiring Soon',
        html: 'Session expires in <strong>30 seconds</strong>.',
        icon: 'warning', confirmButtonText: 'Stay Logged In', cancelButtonText: 'Logout',
        showCancelButton: true, confirmButtonColor: '#3085d6', cancelButtonColor: '#d33',
        timer: 30000, timerProgressBar: true
    }).then(r => {
        if (r.isConfirmed) { refreshSession(); resetInactivityTimer(); }
        else if (r.isDismissed) performLogout();
    });
}
async function refreshSession() { try { await fetch('../refresh_session.php', { method: 'POST' }); } catch (e) {} }
function performLogout() {
    Swal.fire({ title: 'Session Expired', icon: 'info', confirmButtonText: 'OK', allowOutsideClick: false, allowEscapeKey: false, timer: 3000, timerProgressBar: true })
        .then(() => { localStorage.clear(); sessionStorage.clear(); window.location.href = '../logout.php'; });
}
const activityEvents = ['mousedown','mousemove','keypress','scroll','touchstart','click','keydown'];
activityEvents.forEach(ev => document.addEventListener(ev, resetInactivityTimer));
document.addEventListener('DOMContentLoaded', resetInactivityTimer);

// ============ NOTIFICATION BELL + PUSH ============
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
    const bell     = document.getElementById('notifBell');
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
        await fetch('../admin_complaint_ajax.php', {
            method: 'POST',
            body: new URLSearchParams({ action: 'mark_notifications_read' })
        });
        fetchNotifications();
    });

    if ('serviceWorker' in navigator && 'PushManager' in window) {
        try {
            swRegistration = await navigator.serviceWorker.register(SW_URL, { scope: '../' });
            pushSubscription = await swRegistration.pushManager.getSubscription();
            if (pushSubscription && pushToggle) pushToggle.checked = true;
        } catch (err) { console.warn('SW reg failed:', err); }
    } else if (pushToggle) {
        pushToggle.disabled = true;
        pushToggle.parentElement.title = 'Push not supported in this browser.';
    }

    if (pushToggle) {
        pushToggle.addEventListener('change', async () => {
            if (pushToggle.checked) {
                const ok = await enablePush();
                if (!ok) pushToggle.checked = false;
            } else {
                await disablePush();
            }
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
            const iconMap = {
                assignment:'fa-user-check', hearing:'fa-calendar-alt',
                info:'fa-info-circle', warning:'fa-exclamation-triangle', success:'fa-check-circle'
            };
            list.innerHTML = d.notifications.map(n => `
                <div class="notif-item ${n.is_read == 0 ? 'unread' : ''}"
                     onclick="onNotifClick(${n.id}, ${n.reference_id || 'null'}, '${escapeHtml(n.reference_type || '')}')">
                    <div class="notif-icon ${n.type}"><i class="fas ${iconMap[n.type] || 'fa-bell'}"></i></div>
                    <div class="notif-body">
                        <div class="notif-title">${escapeHtml(n.title)}</div>
                        <div class="notif-msg">${escapeHtml(n.message)}</div>
                        <div class="notif-time">${timeAgo(n.created_at)}</div>
                    </div>
                </div>`).join('');
        }
    } catch (e) {}
}

function timeAgo(dt) {
    if (!dt) return '';
    const then = new Date(dt.replace(' ', 'T'));
    const secs = Math.floor((Date.now() - then.getTime()) / 1000);
    if (secs < 60) return 'just now';
    if (secs < 3600) return Math.floor(secs / 60) + 'm ago';
    if (secs < 86400) return Math.floor(secs / 3600) + 'h ago';
    if (secs < 604800) return Math.floor(secs / 86400) + 'd ago';
    return then.toLocaleDateString();
}

function onNotifClick(id, refId, refType) {
    document.getElementById('notifDropdown').classList.remove('show');
    fetch('../admin_complaint_ajax.php', {
        method: 'POST',
        body: new URLSearchParams({ action: 'mark_notification_read', notification_id: id })
    }).catch(() => {});

    if (refType === 'complaint' && refId) {
        viewComplaintSummary(refId);
    }
}

async function enablePush() {
    if (!swRegistration) { alert('Push not supported.'); return false; }
    try {
        const perm = await Notification.requestPermission();
        if (perm !== 'granted') { alert('Please allow notifications.'); return false; }
        const sub = await swRegistration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
        });
        const r = await fetch('../push_subscribe.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(sub.toJSON())
        });
        const d = await r.json();
        if (!d.success) { alert('Save failed: ' + d.message); return false; }
        Swal.fire({ icon: 'success', title: 'Push enabled', timer: 1500, showConfirmButton: false });
        return true;
    } catch (err) {
        console.error(err);
        alert('Push enable failed: ' + err.message);
        return false;
    }
}

async function disablePush() {
    try {
        if (pushSubscription) {
            await fetch('../push_unsubscribe.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ endpoint: pushSubscription.endpoint })
            });
            await pushSubscription.unsubscribe();
            pushSubscription = null;
            Swal.fire({ icon: 'success', title: 'Push disabled', timer: 1500, showConfirmButton: false });
        }
    } catch (err) { console.error(err); }
}

document.addEventListener('DOMContentLoaded', initNotifications);

// ============ INVESTIGATION MODULE ============

async function showMyInvestigations() {
    const body = document.getElementById('dashboardBody');
    body.innerHTML = `
        <div class="content-card" style="background:white;border-radius:20px;padding:25px;">
            <div class="section-title" style="margin-bottom:15px;">
                <i class="fas fa-search"></i> My Assigned Investigations
                <button onclick="location.reload()" style="float:right;background:#eee;border:none;padding:6px 12px;border-radius:8px;cursor:pointer;">← Back</button>
            </div>
            <div id="kagawadList"><div class="loading-spinner" style="text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin fa-2x" style="color:#2E7D32;"></i><p>Loading...</p></div></div>
        </div>`;
    loadKagawadComplaints();
}

async function loadKagawadComplaints() {
    const c = document.getElementById('kagawadList');
    if (!c) return;
    try {
        const r = await fetch('../admin_complaint_ajax.php?action=get_my_assigned_complaints');
        const d = await r.json();
        if (!d.success || !d.complaints.length) {
            c.innerHTML = '<div class="empty-state-mini"><p>No investigations assigned to you.</p></div>';
            return;
        }
        c.innerHTML = d.complaints.map(x => `
            <div class="complaint-item" onclick="viewComplaintSummary(${x.id})">
                <div class="complaint-title">${escapeHtml(x.title)}</div>
                <div class="complaint-detail">
                    <i class="fas fa-hashtag"></i> ${escapeHtml(x.reference_number || '')}
                    &nbsp;|&nbsp; <i class="fas fa-user"></i> ${escapeHtml(x.first_name || '')} ${escapeHtml(x.last_name || '')}
                </div>
                <div style="margin-top:8px;">
                    <span class="complaint-status status-${x.status === 'settled' ? 'resolved' : (x.status || 'investigating')}">
                        ${(x.status || '').replace(/_/g, ' ')}
                    </span>
                </div>
            </div>`).join('');
    } catch (e) {
        console.error(e);
        c.innerHTML = '<div class="empty-state-mini"><p>Error loading.</p></div>';
    }
}

// ============ FULL CASE DETAILS MODAL ============
let currentCaseId = null;

async function viewComplaintSummary(id) {
    currentCaseId = id;
    const overlay = document.getElementById('caseModal');
    const body    = document.getElementById('caseModalBody');
    const refEl   = document.getElementById('caseModalRef');
    const submitBtn = document.getElementById('caseModalSubmitBtn');

    overlay.classList.add('show');
    body.innerHTML = '<div style="text-align:center;padding:60px 20px;"><i class="fas fa-spinner fa-spin fa-3x" style="color:#2E7D32;"></i><p style="color:#666;">Loading case details…</p></div>';
    refEl.textContent = '—';
    submitBtn.style.display = 'none';

    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${id}`);
        const d = await r.json();
        if (!d.success || !d.complaint) {
            body.innerHTML = '<div class="doc-empty" style="padding:40px;">Could not load complaint details.</div>';
            return;
        }

        const c = d.complaint;
        refEl.textContent = c.reference_number || ('CMP-' + id);

        const investigationStates = ['for_investigation', 'investigating', 'pending_review', 'pending'];
        if (investigationStates.includes(c.status)) {
            submitBtn.style.display = 'inline-flex';
        }

        body.innerHTML = buildCaseDocumentHtml(c, d);
    } catch (e) {
        console.error(e);
        body.innerHTML = '<div class="doc-empty" style="padding:40px;color:#c62828;">Network error. Please try again.</div>';
    }
}

function closeCaseModal() {
    document.getElementById('caseModal').classList.remove('show');
    currentCaseId = null;
}

function buildCaseDocumentHtml(c, d) {
    const statusLabel = (c.status || '').replace(/_/g, ' ').toUpperCase();
    const prioColor = {
        urgent: '#dc3545',
        high:   '#fd7e14',
        medium: '#f0ad4e',
        low:    '#28a745'
    }[c.priority || 'medium'] || '#6c757d';

    let html = `
        <div class="doc-wrap">
            <div class="doc-header">
                <div class="doc-header-left">
                    <div class="doc-seal"><i class="fas fa-landmark"></i></div>
                    <div>
                        <div class="doc-republic">Republic of the Philippines</div>
                        <div class="doc-barangay">BARANGAY SAN BARTOLOME</div>
                        <div class="doc-municipality">Sto. Tomas, Pampanga</div>
                    </div>
                </div>
                <div class="doc-header-right">
                    <div class="doc-docname">COMPLAINT RECORD</div>
                    <div class="doc-ref">${escapeHtml(c.reference_number || ('CMP-' + c.id))}</div>
                </div>
            </div>

            <div class="doc-meta">
                <div class="doc-meta-item">
                    <span class="doc-meta-label">Status</span>
                    <span class="doc-meta-value"><span class="doc-pill doc-pill-status">${statusLabel}</span></span>
                </div>
                <div class="doc-meta-item">
                    <span class="doc-meta-label">Priority</span>
                    <span class="doc-meta-value"><span class="doc-pill" style="background:${prioColor};color:#fff;">${(c.priority || 'medium').toUpperCase()}</span></span>
                </div>
                <div class="doc-meta-item">
                    <span class="doc-meta-label">Subject</span>
                    <span class="doc-meta-value">${escapeHtml(c.complaint_subject || '-')}</span>
                </div>
                <div class="doc-meta-item">
                    <span class="doc-meta-label">Filed</span>
                    <span class="doc-meta-value">${new Date(c.created_at).toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' })}</span>
                </div>
                ${c.assigned_to_name ? `
                <div class="doc-meta-item">
                    <span class="doc-meta-label">Assigned To</span>
                    <span class="doc-meta-value">${escapeHtml(c.assigned_to_name)}</span>
                </div>` : ''}
                ${c.hearing_date ? `
                <div class="doc-meta-item">
                    <span class="doc-meta-label">Next Hearing</span>
                    <span class="doc-meta-value">${new Date(c.hearing_date).toLocaleDateString('en-US', { dateStyle: 'medium' })} · ${escapeHtml(c.hearing_time || '')}</span>
                </div>` : ''}
            </div>

            <div class="doc-section">
                <div class="doc-section-title"><span class="doc-num">1</span> Nature of Complaint</div>
                <div class="doc-field">
                    <div class="doc-field-label">Title</div>
                    <div class="doc-field-value doc-field-title">${escapeHtml(c.title || '-')}</div>
                </div>
                <div class="doc-field">
                    <div class="doc-field-label">Description</div>
                    <div class="doc-field-value doc-field-description">${escapeHtml(c.description || '').replace(/\n/g, '<br>')}</div>
                </div>
            </div>
    `;

    const complainants = d.complainants || [];
    html += `
            <div class="doc-section">
                <div class="doc-section-title"><span class="doc-num">2</span> Complainant(s) (${complainants.length})</div>
                ${complainants.length
                    ? complainants.map((p, i) => `
                        <div class="doc-person">
                            <div class="doc-person-avatar" style="background:#e8f5e9;color:#2E7D32;"><i class="fas fa-user"></i></div>
                            <div class="doc-person-info">
                                <div class="doc-person-name">${i + 1}. ${escapeHtml(p.full_name || '-')}</div>
                                <div class="doc-person-detail">
                                    ${p.address ? `<span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(p.address)}</span>` : ''}
                                    ${p.contact_number ? `<span><i class="fas fa-phone"></i> ${escapeHtml(p.contact_number)}</span>` : ''}
                                    ${p.age ? `<span><i class="fas fa-birthday-cake"></i> Age ${escapeHtml(String(p.age))}</span>` : ''}
                                </div>
                            </div>
                        </div>`).join('')
                    : '<div class="doc-empty">No complainants listed.</div>'}
            </div>
    `;

    const respondents = d.respondents || [];
    html += `
            <div class="doc-section">
                <div class="doc-section-title"><span class="doc-num">3</span> Respondent(s) (${respondents.length})</div>
                ${respondents.length
                    ? respondents.map((p, i) => `
                        <div class="doc-person">
                            <div class="doc-person-avatar" style="background:#fff3e0;color:#e65100;"><i class="fas fa-user-friends"></i></div>
                            <div class="doc-person-info">
                                <div class="doc-person-name">${i + 1}. ${escapeHtml(p.full_name || '-')}</div>
                                <div class="doc-person-detail">
                                    ${p.address ? `<span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(p.address)}</span>` : ''}
                                    ${p.contact_number ? `<span><i class="fas fa-phone"></i> ${escapeHtml(p.contact_number)}</span>` : ''}
                                    ${p.age ? `<span><i class="fas fa-birthday-cake"></i> Age ${escapeHtml(String(p.age))}</span>` : ''}
                                </div>
                            </div>
                        </div>`).join('')
                    : '<div class="doc-empty">No respondents listed.</div>'}
            </div>
    `;

    const witnesses = d.witnesses || [];
    if (witnesses.length) {
        html += `
            <div class="doc-section">
                <div class="doc-section-title"><span class="doc-num">4</span> Witness(es) (${witnesses.length})</div>
                ${witnesses.map((p, i) => `
                    <div class="doc-person">
                        <div class="doc-person-avatar" style="background:#e3f2fd;color:#1565c0;"><i class="fas fa-eye"></i></div>
                        <div class="doc-person-info">
                            <div class="doc-person-name">${i + 1}. ${escapeHtml(p.full_name || '-')}</div>
                            <div class="doc-person-detail">
                                ${p.address ? `<span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(p.address)}</span>` : ''}
                                ${p.contact_number ? `<span><i class="fas fa-phone"></i> ${escapeHtml(p.contact_number)}</span>` : ''}
                            </div>
                        </div>
                    </div>`).join('')}
            </div>
        `;
    }

    const incidents = d.incidents || [];
    if (incidents.length) {
        html += `
            <div class="doc-section">
                <div class="doc-section-title"><span class="doc-num">5</span> Incident(s) (${incidents.length})</div>
                ${incidents.map((inc, i) => `
                    <div class="doc-incident">
                        <div class="doc-incident-head">
                            <div class="doc-incident-num">Incident ${i + 1}</div>
                            <div class="doc-incident-meta">
                                ${inc.incident_date ? `<span><i class="fas fa-calendar"></i> ${escapeHtml(inc.incident_date)}</span>` : ''}
                                ${inc.incident_time ? `<span><i class="fas fa-clock"></i> ${escapeHtml(inc.incident_time)}</span>` : ''}
                            </div>
                        </div>
                        ${inc.location ? `<div class="doc-incident-loc"><i class="fas fa-map-marker-alt"></i> ${escapeHtml(inc.location)}</div>` : ''}
                        <div class="doc-incident-desc">${escapeHtml(inc.description || '').replace(/\n/g, '<br>')}</div>
                    </div>`).join('')}
            </div>
        `;
    }

    const hearings = d.hearings || [];
    if (hearings.length) {
        html += `
            <div class="doc-section">
                <div class="doc-section-title"><span class="doc-num">6</span> Hearing History (${hearings.length})</div>
                ${hearings.map(h => `
                    <div class="doc-hearing">
                        <div class="doc-hearing-date">
                            <i class="fas fa-gavel"></i>
                            ${h.hearing_date ? new Date(h.hearing_date).toLocaleDateString('en-US', { dateStyle: 'medium' }) : '—'}
                            ${h.hearing_time ? ' · ' + escapeHtml(h.hearing_time) : ''}
                            <span style="background:#6a1b9a;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.68rem;margin-left:6px;">
                                ${escapeHtml((h.status || '').toUpperCase())}
                            </span>
                        </div>
                        <div class="doc-hearing-detail">
                            ${h.location ? `<div><i class="fas fa-map-marker-alt"></i> ${escapeHtml(h.location)}</div>` : ''}
                            ${h.conducted_by_name ? `<div><i class="fas fa-user-tie"></i> Conducted by ${escapeHtml(h.conducted_by_name)}</div>` : ''}
                            ${h.outcome ? `<div style="margin-top:6px;"><strong>Outcome:</strong> ${escapeHtml(h.outcome)}</div>` : ''}
                            ${h.summary ? `<div style="margin-top:4px;background:#fff;padding:8px 10px;border-radius:6px;">${escapeHtml(h.summary)}</div>` : ''}
                        </div>
                    </div>`).join('')}
            </div>
        `;
    }

    const evidence = d.evidence || [];
    if (evidence.length) {
        html += `
            <div class="doc-section">
                <div class="doc-section-title"><span class="doc-num">7</span> Evidence Files (${evidence.length})</div>
                <div class="doc-evidence-list">
                    ${evidence.map(ev => {
                        const isImage = /\.(jpg|jpeg|png|gif|webp)$/i.test(ev.file_path || '');
                        const icon = isImage ? 'fa-file-image' : 'fa-file-alt';
                        return `
                            <a class="doc-evidence-item" href="../../${escapeHtml(ev.file_path || '')}" target="_blank">
                                <i class="fas ${icon}"></i>
                                ${escapeHtml(ev.file_name || 'Evidence')}
                            </a>`;
                    }).join('')}
                </div>
            </div>
        `;
    }

    const updates = d.updates || [];
    if (updates.length) {
        html += `
            <div class="doc-section">
                <div class="doc-section-title"><span class="doc-num">8</span> Case Timeline (${updates.length})</div>
                <div class="doc-timeline">
                    ${updates.map(u => `
                        <div class="doc-timeline-item">
                            <div class="doc-timeline-title">
                                ${escapeHtml((u.update_type || '').replace(/_/g, ' ').replace(/\b\w/g, ch => ch.toUpperCase()))}
                                ${u.previous_status && u.new_status && u.previous_status !== u.new_status
                                    ? `<span style="color:#666;font-weight:400;font-size:0.78rem;margin-left:6px;">
                                        ${escapeHtml(u.previous_status.replace(/_/g, ' '))} → ${escapeHtml(u.new_status.replace(/_/g, ' '))}
                                       </span>`
                                    : ''}
                            </div>
                            <div class="doc-timeline-meta">
                                <i class="fas fa-clock"></i>
                                ${new Date(u.created_at).toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' })}
                                ${u.updated_by_role ? ` · by ${escapeHtml(u.updated_by_role)}` : ''}
                            </div>
                            ${u.notes ? `<div class="doc-timeline-notes">${escapeHtml(u.notes).replace(/\n/g, '<br>')}</div>` : ''}
                        </div>`).join('')}
                </div>
            </div>
        `;
    }

    html += '</div>';
    return html;
}

// ============ INVESTIGATION FORM ============
function openInvestigationForm(id) {
    Swal.fire({
        title: 'Submit Investigation Findings',
        html: `
            <div style="text-align:left;">
                <label style="display:block;margin-bottom:4px;font-weight:600;color:#1a472a;font-size:0.85rem;">Findings:</label>
                <textarea id="invFind" class="swal2-textarea" rows="4" placeholder="What did you find?"></textarea>
                <label style="margin-top:12px;display:block;margin-bottom:4px;font-weight:600;color:#1a472a;font-size:0.85rem;">Recommendation:</label>
                <textarea id="invRec" class="swal2-textarea" rows="3" placeholder="What do you recommend?"></textarea>
                <label style="margin-top:12px;display:block;margin-bottom:4px;font-weight:600;color:#1a472a;font-size:0.85rem;">Update Status:</label>
                <select id="invStatus" class="swal2-input" style="width:100%;">
                    <option value="for_mediation">Endorse to Lupon for Mediation</option>
                    <option value="escalated">Escalate to Captain</option>
                    <option value="settled">Mark as Settled</option>
                    <option value="dismissed">Dismiss</option>
                    <option value="for_investigation">Continue Investigation</option>
                </select>
            </div>`,
        width: 600,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-paper-plane"></i> Submit',
        confirmButtonColor: '#1565C0',
        cancelButtonColor: '#6c757d',
        preConfirm: () => {
            const f = document.getElementById('invFind').value;
            const r = document.getElementById('invRec').value;
            const s = document.getElementById('invStatus').value;
            if (!f.trim()) { Swal.showValidationMessage('Enter findings'); return false; }
            return { f, r, s };
        }
    }).then(res => {
        if (res.isConfirmed) {
            const fd = new FormData();
            fd.append('action', 'update_investigation');
            fd.append('complaint_id', id);
            fd.append('findings', res.value.f);
            fd.append('recommendation', res.value.r);
            fd.append('new_status', res.value.s);

            fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        Swal.fire('Submitted!', d.message || 'Findings submitted.', 'success')
                            .then(() => {
                                if (document.getElementById('kagawadList')) loadKagawadComplaints();
                                fetchNotifications();
                            });
                    } else {
                        Swal.fire('Error', d.message || 'Failed', 'error');
                    }
                })
                .catch(err => {
                    console.error(err);
                    Swal.fire('Network Error', 'Could not reach server.', 'error');
                });
        }
    });
}

// ============ CASE MODAL CONTROLS ============
(function initCaseModal() {
    const overlay    = document.getElementById('caseModal');
    const closeBtn   = document.getElementById('caseModalClose');
    const cancelBtn  = document.getElementById('caseModalCancel');
    const submitBtn  = document.getElementById('caseModalSubmitBtn');
    if (!overlay) return;

    const close = () => closeCaseModal();

    if (closeBtn)  closeBtn.addEventListener('click', close);
    if (cancelBtn) cancelBtn.addEventListener('click', close);

    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) close();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && overlay.classList.contains('show')) close();
    });

    if (submitBtn) {
        submitBtn.addEventListener('click', () => {
            if (currentCaseId) {
                const id = currentCaseId;
                closeCaseModal();
                setTimeout(() => openInvestigationForm(id), 200);
            }
        });
    }
})();
</script>
</body>
</html>