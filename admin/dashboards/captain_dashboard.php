<?php
// admin/dashboards/captain_dashboard.php
// ============================================================
//  CAPTAIN DASHBOARD — Unified Complaints View
//  Full flow (RA 7160 / KP Handbook):
//    • First schedule issues KP Form #8 + KP Form #9
//    • If any party is No Show → Captain sets appearance date
//      → KP Form #18 (complainant) / #19 (respondent)
//    • 5-DAY EXPLAIN WINDOW starts on the appearance date
//      (deadline is stored on the complaint_hearings row)
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

// ═══════════════════════════════════════════════════════════
// DASHBOARD ANALYTICS  (complaints · equipment · emergencies · announcements)
// ═══════════════════════════════════════════════════════════
function dxRowsOrNull($conn, $sql) {
    try {
        $r = mysqli_query($conn, $sql);
        if (!$r) return null;
        $out = [];
        while ($row = mysqli_fetch_assoc($r)) $out[] = $row;
        return $out;
    } catch (Throwable $e) {
        return null;
    }
}
function dxRows($conn, $sql) {
    $r = dxRowsOrNull($conn, $sql);
    return $r === null ? [] : $r;
}
function dxTableExists($conn, $t) {
    $t = mysqli_real_escape_string($conn, $t);
    return !empty(dxRows($conn, "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$t' LIMIT 1"));
}
function dxCols($conn, $t) {
    $out = [];
    foreach (dxRows($conn, "SHOW COLUMNS FROM `$t`") as $r) $out[] = $r['Field'];
    return $out;
}
function dxFirst($cols, $candidates) {
    foreach ($candidates as $c) if (in_array($c, $cols, true)) return $c;
    return null;
}

// ── Announcements: READ-ONLY list for the Captain ──
if (isset($_GET['ann_action']) && $_GET['ann_action'] === 'list') {
    header('Content-Type: application/json');
    $rows = dxRows($conn,
        "SELECT a.*, ad.full_name AS author_name
         FROM announcements a
         LEFT JOIN admin ad ON ad.id = a.created_by
         WHERE a.is_active = 1
           AND (a.expires_at IS NULL OR a.expires_at > NOW())
         ORDER BY a.is_pinned DESC, a.created_at DESC
         LIMIT 30");
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'         => (int)$r['id'],
            'title'      => $r['title'],
            'body'       => $r['body'],
            'category'   => $r['category'],
            'image_url'  => !empty($r['image_path']) ? 'uploads/announcements/' . $r['image_path'] : null,
            'is_pinned'  => (int)$r['is_pinned'],
            'expires_at' => $r['expires_at'],
            'event_date' => $r['event_date'] ?? null,
            'event_time' => $r['event_time'] ?? null,
            'created_at' => $r['created_at'],
            'author'     => $r['author_name'] ?? null,
        ];
    }
    echo json_encode(['success' => true, 'data' => $out]);
    exit;
}

// ── Month buckets (last 6 months) ──
$dxMonths = [];
for ($i = 5; $i >= 0; $i--) {
    $d = new DateTime('first day of this month');
    $d->modify("-$i month");
    $dxMonths[$d->format('Y-m')] = $d->format('M');
}
$dxZero = array_fill_keys(array_keys($dxMonths), 0);
$dxMonthStart = "DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH,'%Y-%m-01')";

// ── Complaints (only the ones that reach the Captain; pending_review is the Secretary's stage) ──
$dxCompMonthly = $dxZero;
foreach (dxRows($conn, "SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) c FROM complaints
                        WHERE status <> 'pending_review' AND created_at >= $dxMonthStart GROUP BY ym") as $r) {
    if (isset($dxCompMonthly[$r['ym']])) $dxCompMonthly[$r['ym']] = (int)$r['c'];
}
$dxComplaintStatus = [];
foreach (dxRows($conn, "SELECT status, COUNT(*) c FROM complaints WHERE status <> 'pending_review' GROUP BY status") as $r) {
    $dxComplaintStatus[$r['status']] = (int)$r['c'];
}

// ── Emergencies ──
$dxEmgMonthly = $dxZero;
foreach (dxRows($conn, "SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) c FROM emergencies
                        WHERE created_at >= $dxMonthStart GROUP BY ym") as $r) {
    if (isset($dxEmgMonthly[$r['ym']])) $dxEmgMonthly[$r['ym']] = (int)$r['c'];
}
$dxEmgStatus = [];
foreach (dxRows($conn, "SELECT UPPER(status) s, COUNT(*) c FROM emergencies GROUP BY s") as $r) {
    $dxEmgStatus[$r['s']] = (int)$r['c'];
}
$dxEmgTypes = [];
foreach (dxRows($conn, "SELECT emergency_type t, COUNT(*) c FROM emergencies GROUP BY t ORDER BY c DESC") as $r) {
    $dxEmgTypes[] = ['name' => $r['t'], 'count' => (int)$r['c']];
}
$dxEmgRecent = dxRows($conn, "SELECT id, emergency_type, name, barangay, latitude, longitude, caller_number,
                                     status, accepted_by_name, external_id, created_at
                              FROM emergencies ORDER BY created_at DESC, id DESC LIMIT 8");
$dxEmgToday = 0;
foreach (dxRows($conn, "SELECT COUNT(*) c FROM emergencies WHERE DATE(created_at) = CURDATE()") as $r) $dxEmgToday = (int)$r['c'];

// ── Equipment bookings (table / columns are auto-detected) ──
$dxEq = ['found' => false, 'table' => null, 'monthly' => array_values($dxZero), 'status' => [], 'pending' => 0, 'borrowed' => 0];
$dxEqEvents = [];
$dxEqTable = null;
foreach (['equipment_bookings','equipment_booking','equipment_borrowings','equipment_borrow_requests',
          'equipment_requests','equipment_reservations','borrowings','bookings'] as $t) {
    if (dxTableExists($conn, $t)) { $dxEqTable = $t; break; }
}
if ($dxEqTable) {
    $cols       = dxCols($conn, $dxEqTable);
    $statusCol  = dxFirst($cols, ['status']);
    $dateCol    = dxFirst($cols, ['start_date','borrow_date','date_from','booking_date','date_needed','from_date','pickup_date','created_at']);
    $createdCol = dxFirst($cols, ['created_at']) ?: $dateCol;
    $idCol      = dxFirst($cols, ['id']);
    $titleCol   = dxFirst($cols, ['equipment_name','item_name','equipment','purpose']);
    $dxEq['found'] = true;
    $dxEq['table'] = $dxEqTable;

    if ($statusCol) {
        foreach (dxRows($conn, "SELECT LOWER(`$statusCol`) s, COUNT(*) c FROM `$dxEqTable` GROUP BY s") as $r) {
            $dxEq['status'][$r['s']] = (int)$r['c'];
        }
        $dxEq['pending']  = $dxEq['status']['pending']  ?? 0;
        $dxEq['borrowed'] = $dxEq['status']['borrowed'] ?? 0;
    }
    if ($createdCol) {
        $m = $dxZero;
        foreach (dxRows($conn, "SELECT DATE_FORMAT(`$createdCol`,'%Y-%m') ym, COUNT(*) c FROM `$dxEqTable`
                                WHERE `$createdCol` >= $dxMonthStart GROUP BY ym") as $r) {
            if (isset($m[$r['ym']])) $m[$r['ym']] = (int)$r['c'];
        }
        $dxEq['monthly'] = array_values($m);
    }
    if ($dateCol) {
        $sel   = ($idCol ? "b.`$idCol`" : "0") . " AS id, b.`$dateCol` AS d, "
               . ($titleCol ? "b.`$titleCol`" : "NULL") . " AS t, "
               . ($statusCol ? "b.`$statusCol`" : "NULL") . " AS s";
        $where = "b.`$dateCol` IS NOT NULL
                  AND b.`$dateCol` BETWEEN CURDATE() - INTERVAL 90 DAY AND CURDATE() + INTERVAL 270 DAY"
               . ($statusCol ? " AND LOWER(b.`$statusCol`) NOT IN ('rejected','cancelled')" : "");

        // Prefer the real equipment name when an `equipment` table can be joined
        $rows = null;
        if (in_array('equipment_id', $cols, true) && dxTableExists($conn, 'equipment')
            && in_array('name', dxCols($conn, 'equipment'), true)) {
            $rows = dxRowsOrNull($conn, "SELECT " . ($idCol ? "b.`$idCol`" : "0") . " AS id, b.`$dateCol` AS d,
                                                COALESCE(e.name, " . ($titleCol ? "b.`$titleCol`" : "'Equipment'") . ") AS t,
                                                " . ($statusCol ? "b.`$statusCol`" : "NULL") . " AS s
                                         FROM `$dxEqTable` b LEFT JOIN equipment e ON e.id = b.equipment_id
                                         WHERE $where ORDER BY b.`$dateCol` ASC LIMIT 400");
        }
        if ($rows === null) {
            $rows = dxRows($conn, "SELECT $sel FROM `$dxEqTable` b WHERE $where ORDER BY b.`$dateCol` ASC LIMIT 400");
        }
        foreach ($rows as $r) {
            $dxEqEvents[] = [
                'kind'   => 'equipment',
                'id'     => (int)$r['id'],
                'date'   => substr((string)$r['d'], 0, 10),
                'time'   => strlen((string)$r['d']) > 10 ? substr((string)$r['d'], 11, 8) : null,
                'title'  => $r['t'] ?: 'Equipment booking',
                'sub'    => $r['s'] ? ucfirst(strtolower($r['s'])) : 'Booking',
                'status' => $r['s'],
            ];
        }
    }
}

// ── Calendar events: hearings + equipment + emergencies ──
$dxEvents = [];
foreach (dxRows($conn, "SELECT id, reference_number, title, status, hearing_date, hearing_time
                        FROM complaints
                        WHERE hearing_date IS NOT NULL
                          AND hearing_date BETWEEN CURDATE() - INTERVAL 90 DAY AND CURDATE() + INTERVAL 270 DAY
                          AND status NOT IN ('pending_review','dismissed')
                        ORDER BY hearing_date ASC LIMIT 400") as $r) {
    $dxEvents[] = [
        'kind' => 'hearing', 'id' => (int)$r['id'],
        'date' => substr($r['hearing_date'], 0, 10), 'time' => $r['hearing_time'],
        'title' => $r['title'], 'sub' => $r['reference_number'], 'status' => $r['status'],
    ];
}
foreach ($dxEqEvents as $e) $dxEvents[] = $e;
foreach (dxRows($conn, "SELECT id, emergency_type, name, barangay, status, created_at
                        FROM emergencies
                        WHERE created_at >= CURDATE() - INTERVAL 90 DAY
                        ORDER BY created_at ASC LIMIT 300") as $r) {
    $dxEvents[] = [
        'kind' => 'emergency', 'id' => (int)$r['id'],
        'date' => substr($r['created_at'], 0, 10), 'time' => substr($r['created_at'], 11, 8),
        'title' => $r['emergency_type'] . ' — ' . $r['name'],
        'sub' => $r['barangay'], 'status' => $r['status'],
    ];
}

$dxData = [
    'months'           => array_values($dxMonths),
    'complaintMonthly' => array_values($dxCompMonthly),
    'emergencyMonthly' => array_values($dxEmgMonthly),
    'equipmentMonthly' => $dxEq['monthly'],
    'complaintStatus'  => $dxComplaintStatus,
    'emergencyStatus'  => $dxEmgStatus,
    'emergencyTypes'   => $dxEmgTypes,
    'emergencies'      => $dxEmgRecent,
    'equipment'        => ['found' => $dxEq['found'], 'table' => $dxEq['table'], 'status' => $dxEq['status']],
    'events'           => $dxEvents,
];
$dxEmgPending = (int)($dxEmgStatus['PENDING'] ?? 0);
$dxJson = json_encode($dxData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Captain Dashboard | BRITE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
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

        /* ═══════════════════════════════════════════════════════════
           COMPLAINT FILTER ACCORDION (collapsible filters bar)
           ═══════════════════════════════════════════════════════════ */
        .complaint-filter-accordion { margin-bottom: 16px; }

        .complaint-filter-accordion-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: #f8faf8;
            border: 1px solid #e2efe8;
            border-radius: 10px;
            cursor: pointer;
            user-select: none;
            transition: background 0.15s, border-color 0.15s, box-shadow 0.15s;
        }
        .complaint-filter-accordion-header:hover {
            background: #f1f8e9;
            border-color: #c8e6c9;
            box-shadow: 0 2px 8px rgba(46,125,50,0.10);
        }
        .complaint-filter-accordion-header .cfa-icon {
            color: #2E7D32;
            font-size: 0.95rem;
            flex-shrink: 0;
        }
        .complaint-filter-accordion-header .cfa-title {
            font-weight: 800;
            color: #1a472a;
            font-size: 0.85rem;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            flex-shrink: 0;
        }
        .complaint-filter-accordion-header .cfa-summary {
            flex: 1;
            min-width: 0;
            font-size: 0.78rem;
            color: #5f7f6e;
            font-weight: 600;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }
        .complaint-filter-accordion-header .cfa-summary .cfa-sep {
            color: #cfd8dc;
            margin: 0 6px;
        }
        .complaint-filter-accordion-header .cfa-chevron {
            color: #2E7D32;
            font-size: 0.8rem;
            transition: transform 0.25s ease;
            flex-shrink: 0;
        }
        .complaint-filter-accordion-body {
            overflow: hidden;
            transition: max-height 0.3s ease, opacity 0.25s ease, margin-top 0.25s ease;
        }
        .complaint-filter-accordion-body .unified-chips {
            padding-top: 12px;
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
   EQUIPMENT MANAGEMENT MODAL — Professional UI
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

        /* 3-column variant — used when Borrowed is hidden (Facility / Vehicle) */
        .eq-mgmt-stats-grid-3 {
            grid-template-columns: repeat(3, 1fr) !important;
        }
        @media (max-width: 600px) {
            .eq-mgmt-stats-grid-3 {
                grid-template-columns: repeat(3, 1fr) !important;
            }
        }

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

.eq-mgmt-form-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: flex-end;
}
.eq-mgmt-field {
    display: flex;
    flex-direction: column;
    gap: 5px;
    min-width: 0;
}
.eq-mgmt-field label {
    font-size: 0.72rem;
    font-weight: 700;
    color: #1a472a;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.eq-mgmt-field label .req { color: #c62828; }
.eq-mgmt-field label .opt { color: #999; font-weight: 500; text-transform: none; letter-spacing: 0; }
.eq-mgmt-field input,
.eq-mgmt-field select {
    padding: 9px 12px;
    border: 1.5px solid #cfd8dc;
    border-radius: 8px;
    font-size: 0.88rem;
    font-family: inherit;
    background: #fff;
    box-sizing: border-box;
    width: 100%;
}
.eq-mgmt-field input:focus,
.eq-mgmt-field select:focus {
    outline: none;
    border-color: #2E7D32;
    box-shadow: 0 0 0 3px rgba(46,125,50,0.12);
}

.eq-mgmt-btn {
    padding: 10px 20px;
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
    white-space: nowrap;
}
.eq-mgmt-btn:hover { transform: translateY(-1px); }
.eq-mgmt-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
.eq-mgmt-btn.add    { background: #2E7D32; color: #fff; }
.eq-mgmt-btn.add:hover { background: #1b5e20; }
.eq-mgmt-btn.deduct { background: #c62828; color: #fff; }
.eq-mgmt-btn.deduct:hover { background: #b71c1c; }

.eq-mgmt-history-toggle {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 11px 16px;
    background: #f1f8e9;
    border: 1.5px dashed #a5d6a7;
    border-radius: 10px;
    color: #1b5e20;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s;
}
.eq-mgmt-history-toggle:hover { background: #e8f5e9; border-style: solid; }
.eq-mgmt-history-toggle > span { flex: 1; text-align: left; }
.eq-mgmt-history-toggle > i:last-child { transition: transform 0.2s; }

.eq-mgmt-history {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
}
.eq-mgmt-history.open {
    max-height: 420px;
    overflow-y: auto;
    margin-top: 12px;
}

.eq-mgmt-history-empty {
    text-align: center;
    padding: 30px;
    color: #999;
    font-size: 0.85rem;
}
.eq-mgmt-history-empty i {
    font-size: 2rem;
    color: #c8e6c9;
    margin-bottom: 8px;
    display: block;
}

.eq-mgmt-history-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.eq-mgmt-history-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 14px;
    background: #fafbfa;
    border-left: 4px solid #43a047;
    border-radius: 10px;
    transition: all 0.15s;
}
.eq-mgmt-history-item.undone {
    opacity: 0.55;
    background: #f0f0f0;
    border-left-color: #bdbdbd;
}

.eq-mgmt-history-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    flex-shrink: 0;
    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
}

.eq-mgmt-history-body { flex: 1; min-width: 0; }
.eq-mgmt-history-action {
    font-size: 0.85rem;
    color: #1a2b22;
    font-weight: 600;
    line-height: 1.4;
}
.eq-mgmt-history-reason {
    font-size: 0.75rem;
    color: #666;
    margin-top: 4px;
    font-style: italic;
}
.eq-mgmt-history-reason i { color: #999; margin-right: 3px; }
.eq-mgmt-history-meta {
    display: flex;
    gap: 10px;
    align-items: center;
    font-size: 0.7rem;
    color: #888;
    margin-top: 5px;
}
.eq-mgmt-undone-tag {
    background: #c62828;
    color: #fff;
    padding: 1px 7px;
    border-radius: 8px;
    font-size: 0.6rem;
    font-weight: 800;
    letter-spacing: 0.4px;
}

.eq-mgmt-undo-btn {
    padding: 6px 12px;
    background: #f57c00;
    color: #fff;
    border: none;
    border-radius: 7px;
    font-size: 0.72rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.15s;
    flex-shrink: 0;
}
.eq-mgmt-undo-btn:hover { background: #e65100; }

.eq-mgmt-footer {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding-top: 12px;
    border-top: 1px solid #e2efe8;
    flex-wrap: wrap;
}
.eq-mgmt-footer .eq-btn { flex: 1; min-width: 120px; padding: 11px 18px; }

.eq-empty { text-align: center; padding: 40px 20px; color: #999; }
.eq-empty i { font-size: 2.5rem; color: #c8e6c9; margin-bottom: 10px; display: block; }
.eq-empty p { margin: 0; font-size: 0.9rem; }
/* ============================================================
   EQUIPMENT UI — Cards, Details Modal, Item Cards
   ============================================================ */

/* ── Equipment Cards Grid ─────────────────────────────── */
.shopee-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 18px;
    margin-top: 20px;
}

.eq-card {
    background: #fff;
    border-radius: 16px;
    overflow: hidden;
    border: 1px solid #e2efe8;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    display: flex;
    flex-direction: column;
    transition: all 0.2s ease;
}
.eq-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 28px rgba(0,0,0,0.10);
    border-color: #c8e6c9;
}

.eq-card-image {
    position: relative;
    width: 100%;
    aspect-ratio: 16 / 10;
    overflow: hidden;
    cursor: pointer;
    background: linear-gradient(135deg, #f1f8e9, #e8f5e9);
}
.eq-card-image img {
    width: 100%; height: 100%;
    object-fit: cover;
    transition: transform 0.35s ease;
}
.eq-card:hover .eq-card-image img { transform: scale(1.05); }

.eq-img-fallback {
    width: 100%; height: 100%;
    display: flex; align-items: center; justify-content: center;
    color: #43a047; font-size: 3rem;
    background: linear-gradient(135deg, #f1f8e9, #e8f5e9);
}

.eq-cat-chip {
    position: absolute; top: 10px; left: 10px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.65rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background: rgba(255,255,255,0.92);
    color: #2E7D32;
    backdrop-filter: blur(6px);
    box-shadow: 0 2px 6px rgba(0,0,0,0.12);
}
.eq-cat-chip.facility { color: #1565c0; }
.eq-cat-chip.vehicle  { color: #e65100; }

.eq-status-chip {
    position: absolute; top: 10px; right: 10px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.65rem;
    font-weight: 800;
    color: #fff;
    box-shadow: 0 2px 6px rgba(0,0,0,0.18);
}
.eq-status-chip.status-available     { background: #2E7D32; }
.eq-status-chip.status-borrowed      { background: #17a2b8; }
.eq-status-chip.status-maintenance   { background: #f57c00; }
.eq-status-chip.status-unavailable   { background: #c62828; }

.eq-card-body {
    padding: 14px 16px 10px;
    cursor: pointer;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.eq-card-title {
    font-weight: 800;
    font-size: 1rem;
    color: #1a472a;
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}
.eq-card-desc {
    font-size: 0.75rem;
    color: #7a8a82;
    line-height: 1.5;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}

.eq-avail-bar {
    height: 6px;
    background: #eef2f0;
    border-radius: 6px;
    overflow: hidden;
    margin-top: 4px;
}
.eq-avail-fill {
    height: 100%;
    border-radius: 6px;
    transition: width 0.4s ease;
}
.eq-avail-label {
    display: flex;
    justify-content: space-between;
    font-size: 0.68rem;
    color: #7a8a82;
    margin-top: -2px;
}

.eq-stats-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
    margin-top: 6px;
}
.eq-stat-pill {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 10px;
    border-radius: 8px;
    font-size: 0.72rem;
}
.eq-stat-pill i { font-size: 0.85rem; }
.eq-stat-pill b { font-size: 0.9rem; display: block; line-height: 1; }
.eq-stat-pill small { font-size: 0.6rem; text-transform: uppercase; letter-spacing: 0.4px; opacity: 0.8; }
.eq-stat-pill.available   { background: #e8f5e9; color: #1b5e20; }
.eq-stat-pill.borrowed    { background: #e3f2fd; color: #0d47a1; }
.eq-stat-pill.maintenance { background: #fff3e0; color: #e65100; }
.eq-stat-pill.lost        { background: #ffebee; color: #b71c1c; }

.eq-card-footer {
    display: flex;
    gap: 8px;
    padding: 10px 14px;
    background: #f8faf8;
    border-top: 1px solid #e2efe8;
}

.eq-btn {
    padding: 9px 14px;
    border: none;
    border-radius: 9px;
    font-size: 0.78rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: all 0.15s;
    flex: 1;
}
.eq-btn:hover { transform: translateY(-1px); }
.eq-btn.primary { background: #2E7D32; color: #fff; }
.eq-btn.primary:hover { background: #1b5e20; }
.eq-btn.ghost { background: #fff; color: #2E7D32; border: 1.5px solid #c8e6c9; }
.eq-btn.ghost:hover { background: #f1f8e9; }
.eq-btn.danger { background: #c62828; color: #fff; }
.eq-btn.danger:hover { background: #b71c1c; }
.eq-btn.small { padding: 7px 11px; font-size: 0.72rem; }

/* ── Equipment Details Modal ───────────────────────────── */
.eq-details-wrap {
    display: flex;
    flex-direction: column;
    gap: 18px;
    padding: 4px 2px;
}

.eq-hero {
    display: grid;
    grid-template-columns: 200px 1fr;
    gap: 18px;
    padding: 18px;
    background: linear-gradient(135deg, #f1f8e9, #e8f5e9);
    border-radius: 16px;
    border: 1px solid #c8e6c9;
}
@media (max-width: 600px) { .eq-hero { grid-template-columns: 1fr; } }

.eq-hero-image {
    width: 100%;
    aspect-ratio: 1 / 1;
    border-radius: 12px;
    overflow: hidden;
    background: #fff;
    box-shadow: 0 4px 14px rgba(0,0,0,0.08);
}
.eq-hero-image img { width: 100%; height: 100%; object-fit: cover; }
.eq-hero-fallback {
    width: 100%; height: 100%;
    display: flex; align-items: center; justify-content: center;
    font-size: 3.5rem; color: #43a047;
    background: linear-gradient(135deg, #ffffff, #f1f8e9);
}

.eq-hero-info { display: flex; flex-direction: column; justify-content: center; gap: 8px; }
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
.eq-hero-title {
    font-size: 1.5rem;
    font-weight: 800;
    color: #1a472a;
    line-height: 1.25;
    margin: 0;
}
.eq-hero-desc {
    font-size: 0.88rem;
    color: #4a5f56;
    line-height: 1.55;
    margin: 0;
}
.eq-hero-desc.muted { color: #999; font-style: italic; }
.eq-hero-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    margin-top: 6px;
    font-size: 0.82rem;
    color: #555;
}
.eq-hero-meta i { color: #2E7D32; margin-right: 4px; }

/* Availability panel */
.eq-avail-panel {
    padding: 16px 18px;
    background: #fff;
    border: 1.5px solid #e2efe8;
    border-radius: 14px;
}
.eq-avail-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.8rem;
    font-weight: 700;
    color: #1a472a;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 10px;
}
.eq-avail-pct { font-size: 1.1rem; font-weight: 900; color: #2E7D32; }
.eq-avail-bar-lg {
    height: 12px;
    background: #eef2f0;
    border-radius: 8px;
    overflow: hidden;
}
.eq-avail-fill-lg {
    height: 100%;
    border-radius: 8px;
    transition: width 0.5s ease;
}
.eq-avail-stats {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-top: 14px;
    font-size: 0.82rem;
    color: #444;
}
.eq-avail-stat { display: inline-flex; align-items: center; gap: 6px; }
.eq-avail-stat b { color: #1a472a; font-size: 0.95rem; }
.eq-avail-stat.total {
    margin-left: auto;
    padding-left: 14px;
    border-left: 1px solid #e2efe8;
    color: #666;
    font-weight: 600;
}
.dot {
    width: 10px; height: 10px; border-radius: 50%;
    display: inline-block;
}
.dot.available   { background: #2E7D32; }
.dot.borrowed    { background: #17a2b8; }
.dot.maintenance { background: #f57c00; }
.dot.lost        { background: #c62828; }

/* Stats cards */
.eq-stat-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}
@media (max-width: 600px) { .eq-stat-cards { grid-template-columns: repeat(2, 1fr); } }

.eq-stat-card {
    padding: 14px;
    border-radius: 12px;
    text-align: center;
    border: 1.5px solid;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
}
.eq-stat-card i { font-size: 1.2rem; }
.eq-stat-card .num { font-size: 1.6rem; font-weight: 900; line-height: 1; }
.eq-stat-card .lbl {
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    opacity: 0.85;
}
.eq-stat-card.available   { background: #e8f5e9; border-color: #a5d6a7; color: #1b5e20; }
.eq-stat-card.borrowed    { background: #e3f2fd; border-color: #90caf9; color: #0d47a1; }
.eq-stat-card.maintenance { background: #fff3e0; border-color: #ffcc80; color: #e65100; }
.eq-stat-card.lost        { background: #ffebee; border-color: #ef9a9a; color: #b71c1c; }

/* Sections */
.eq-section {
    background: #fff;
    border: 1.5px solid #e2efe8;
    border-radius: 14px;
    padding: 16px 18px;
}
.eq-section-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.88rem;
    font-weight: 800;
    color: #1a472a;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 2px solid #f1f8e9;
}
.eq-section-title i { color: #2E7D32; }
.eq-section-badge {
    margin-left: auto;
    background: #2E7D32;
    color: #fff;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 800;
}
.eq-section-badge.warn { background: #f57c00; }

/* Items preview rows */
.eq-items-list { display: flex; flex-direction: column; gap: 6px; }
.eq-item-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-radius: 8px;
    background: #f8faf8;
    border: 1px solid #eef2f0;
    font-size: 0.82rem;
}
.eq-item-num {
    background: #2E7D32;
    color: #fff;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 800;
    min-width: 42px;
    text-align: center;
}
.eq-item-name { flex: 1; font-weight: 600; color: #1a2b22; }
.eq-item-badge {
    padding: 3px 9px;
    border-radius: 10px;
    font-size: 0.65rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.eq-item-badge.available   { background: #c8e6c9; color: #1b5e20; }
.eq-item-badge.borrowed    { background: #bbdefb; color: #0d47a1; }
.eq-item-badge.maintenance { background: #ffe0b2; color: #e65100; }
.eq-item-badge.lost        { background: #ffcdd2; color: #b71c1c; }

.eq-see-all {
    display: block;
    width: 100%;
    margin-top: 10px;
    padding: 10px;
    background: #f1f8e9;
    color: #2E7D32;
    border: 1.5px dashed #a5d6a7;
    border-radius: 10px;
    font-size: 0.8rem;
    font-weight: 700;
    cursor: pointer;
    text-align: center;
    transition: all 0.15s;
}
.eq-see-all:hover { background: #e8f5e9; border-style: solid; }

.eq-empty {
    text-align: center;
    padding: 24px;
    color: #999;
}
.eq-empty i { font-size: 2.4rem; color: #c8e6c9; margin-bottom: 8px; display: block; }

/* Bookings preview */
.eq-bookings-list { display: flex; flex-direction: column; gap: 6px; }
.eq-booking-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 10px;
    background: #fafbfa;
    border: 1px solid #eef2f0;
    cursor: pointer;
    transition: all 0.15s;
}
.eq-booking-row:hover { background: #f1f8e9; border-color: #c8e6c9; }
.eq-booking-avatar {
    width: 32px; height: 32px;
    border-radius: 50%;
    background: #e8f5e9;
    color: #2E7D32;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.eq-booking-info { flex: 1; min-width: 0; }
.eq-booking-info b { font-size: 0.85rem; color: #1a2b22; display: block; }
.eq-booking-info small { font-size: 0.72rem; color: #666; display: block; margin-top: 2px; }
.eq-booking-status {
    padding: 3px 10px;
    border-radius: 10px;
    font-size: 0.65rem;
    font-weight: 800;
    text-transform: uppercase;
}
.eq-booking-status.pending   { background: #fff3e0; color: #e65100; }
.eq-booking-status.approved  { background: #e8f5e9; color: #1b5e20; }
.eq-booking-status.borrowed  { background: #e3f2fd; color: #0d47a1; }

.eq-meta-row {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    padding: 10px 14px;
    background: #f8faf8;
    border-radius: 10px;
    font-size: 0.75rem;
    color: #666;
}

.eq-details-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    padding-top: 8px;
    border-top: 1px solid #e2efe8;
    flex-wrap: wrap;
}
.eq-details-actions .eq-btn { flex: 0 0 auto; padding: 10px 18px; }

/* ── Individual Item Cards (in View Items modal) ─────── */
.eq-items-full-list {
    display: grid;
    gap: 12px;
}

.eq-item-card {
    background: #fff;
    border: 1.5px solid #e2efe8;
    border-radius: 12px;
    padding: 14px 16px;
    transition: all 0.15s;
}
.eq-item-card:hover { border-color: #c8e6c9; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }

.eq-item-card-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}
.eq-item-card-id { display: flex; align-items: center; gap: 8px; flex: 1; min-width: 0; }
.eq-item-card-num {
    background: #2E7D32;
    color: #fff;
    padding: 3px 10px;
    border-radius: 8px;
    font-size: 0.72rem;
    font-weight: 800;
    flex-shrink: 0;
}
.eq-item-card-name {
    font-weight: 700;
    font-size: 0.95rem;
    color: #1a2b22;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.eq-item-status {
    padding: 4px 11px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.eq-item-status.available   { background: #e8f5e9; color: #1b5e20; }
.eq-item-status.borrowed    { background: #e3f2fd; color: #0d47a1; }
.eq-item-status.maintenance { background: #fff3e0; color: #e65100; }
.eq-item-status.lost        { background: #ffebee; color: #b71c1c; }
.eq-item-status.default     { background: #f0f0f0; color: #666; }

.eq-item-card-notes {
    display: flex;
    gap: 8px;
    padding: 8px 12px;
    background: #f8faf8;
    border-left: 3px solid #43a047;
    border-radius: 6px;
    font-size: 0.78rem;
    color: #4a5f56;
    margin-bottom: 10px;
    line-height: 1.5;
}
.eq-item-card-notes i { color: #2E7D32; margin-top: 2px; flex-shrink: 0; }

.eq-item-card-meta {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    font-size: 0.7rem;
    color: #888;
    margin-bottom: 10px;
}
.eq-item-card-meta i { color: #a5d6a7; margin-right: 3px; }

.eq-item-card-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    padding-top: 10px;
    border-top: 1px dashed #e2efe8;
}
.eq-item-card-actions .eq-btn { flex: 1; min-width: 110px; }

/* ── Make modals wider for details ────────────────────── */
#equipmentItemsModal .modal-content { max-width: 820px; width: 95vw; }
#equipmentModal .modal-content       { max-width: 760px; width: 95vw; }

/* Ensure filter bar wraps instead of scrolling off-screen */
.filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    overflow-x: visible;      /* override any horizontal-scroll rule */
}

/* Visible divider between status chips and type chips */
.filter-sep {
    display: inline-block;
    width: 1px;
    height: 24px;
    background: #cfd8dc;
    margin: 0 6px;
    align-self: center;
}

/* Make Calendar View / Refresh buttons wrap onto their own row cleanly */
.filter-bar .btn-verify {
    flex-shrink: 0;
}

@media (max-width: 900px) {
    /* On narrow screens, force the last two buttons to their own row */
    .filter-bar .btn-verify {
        margin-left: 0 !important;
    }
}
/* ══════════════════════════════════════════════════════════
   FILTER BAR — STACKED LAYOUT (Type on top, Status below)
   Forced with !important so nothing else can override it
   ══════════════════════════════════════════════════════════ */

/* Container — stacks rows vertically */
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

/* Each row inside the stacked bar */
.filter-bar.filter-bar-stacked .filter-row {
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: wrap !important;
    align-items: center !important;
    gap: 8px !important;
    width: 100% !important;
}

/* Row 1 (Type) — divider under it */
.filter-bar.filter-bar-stacked .filter-row-type {
    padding-bottom: 10px !important;
    border-bottom: 1px dashed #d7e2dc !important;
}

/* Small inline label at the start of each row */
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

/* Action buttons (Calendar View / Refresh) — pushed to the right */
.filter-bar.filter-bar-stacked .filter-row-actions {
    margin-left: auto !important;
    display: inline-flex !important;
    gap: 8px !important;
    flex-wrap: wrap !important;
    align-items: center !important;
}

/* Keep chips and buttons from being squashed */
.filter-bar.filter-bar-stacked .filter-row .filter-chip,
.filter-bar.filter-bar-stacked .filter-row .btn-verify {
    flex-shrink: 0 !important;
}

/* Kill the old flat filter-bar flex rule for stacked variant */
.filter-bar.filter-bar-stacked {
    overflow-x: visible !important;
}

/* ── Mobile: stack everything neatly ── */
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

/* equipment + emergency dots/events */
.dx-dot.equipment { background: #1976d2; }
.dx-dot.emergency { background: #e53935; }
.dx-ev.equipment  { border-left-color: #1976d2; }
.dx-ev.emergency  { border-left-color: #e53935; }

/* emergency feed */
.dx-emg-list { flex: 1; min-height: 0; overflow-y: auto; margin: 0 -6px; padding: 0 6px; }
.dx-emg {
    display: flex; gap: 10px; align-items: center; padding: 8px 10px; border-radius: 11px;
    cursor: pointer; transition: background .15s; border-left: 4px solid #e53935; background: #fafbfa; margin-bottom: 6px;
}
.dx-emg:hover { background: #fff5f5; }
.dx-emg.accepted { border-left-color: #43a047; }
.dx-emg.accepted:hover { background: #f1f8e9; }
.dx-emg-ic { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.85rem; }
.dx-emg-body { min-width: 0; flex: 1; }
.dx-emg-t { font-size: 0.78rem; font-weight: 800; color: #1a2b22; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dx-emg-s { font-size: 0.68rem; color: #7a8a82; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }
.dx-emg-chip { font-size: 0.6rem; font-weight: 800; padding: 3px 8px; border-radius: 10px; text-transform: uppercase; letter-spacing: .3px; flex-shrink: 0; background: #fff3e0; color: #e65100; }
.dx-emg-chip.accepted { background: #e8f5e9; color: #2e7d32; }
.dx-emg-chip.pending  { animation: dxPulse 1.6s infinite; }
@keyframes dxPulse { 0%,100% { box-shadow: 0 0 0 0 rgba(230,81,0,0.35); } 50% { box-shadow: 0 0 0 5px rgba(230,81,0,0); } }

/* ── tablet ── */
@media (min-width: 700px) {
    .dx-kpis { grid-template-columns: repeat(4, 1fr); }
    .dx-grid { grid-template-columns: repeat(2, 1fr); }
    .dx-annc  { grid-column: span 2; }
    .dx-annc .dx-annc-body { min-height: 380px; }
    .dx-chart { height: 280px; }
    .dx-emerg { min-height: 300px; }
}
/* ── desktop: fits one screen (no page scrolling) ── */
@media (min-width: 1200px) {
    .dx { height: calc(100dvh - 150px); min-height: 700px; }
    .dx-kpis { grid-template-columns: repeat(8, 1fr); flex-shrink: 0; }
    .dx-kpi { flex-direction: column; align-items: flex-start; gap: 8px; padding: 10px 12px; }
    .dx-kpi-ic { width: 32px; height: 32px; font-size: 0.88rem; }
    .dx-top { flex-shrink: 0; }
    .dx-grid { flex: 1; min-height: 0; grid-template-columns: repeat(12, 1fr); grid-template-rows: 1fr 1fr; }
    .dx-annc   { grid-column: span 5; grid-row: span 2; }
    .dx-trend  { grid-column: span 4; }
    .dx-status { grid-column: span 3; }
    .dx-cal    { grid-column: span 4; }
    .dx-emerg  { grid-column: span 3; }
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
    </main>
</div>

<form id="logoutForm" method="POST" action="../../logout.php" style="display: none;"></form>

<?php include __DIR__ . '/admin_settings_modal.php'; ?>
<script>
    window.CURRENT_ROLE = 'captain';
    window.CURRENT_ROLE_LABEL = 'Captain';
</script>
<script src="captain_equipment.js"></script>
<script src="captain_household.js"></script>
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

// Inline error used inside the wizard (avoids Swal.fire which would close the modal)
function alertInlineError(msg) {
    const host = document.getElementById('swalSessionInlineError');
    if (!host) { alert(msg); return; }
    host.textContent = msg;
    host.style.display = 'block';
    clearTimeout(window.__inlineErrTimer);
    window.__inlineErrTimer = setTimeout(() => { host.style.display = 'none'; }, 5000);
}
function clearInlineError() {
    const host = document.getElementById('swalSessionInlineError');
    if (host) host.style.display = 'none';
}

// Inline progress bar (used inside the wizard — no external popup)
function showSessionProgress(label, pct) {
    const wrap = document.getElementById('swalSessionProgress');
    const bar  = document.getElementById('swalSessionProgressBar');
    const lbl  = document.getElementById('swalSessionProgressLabel');
    if (!wrap) return;
    wrap.style.display = 'block';
    if (bar) bar.style.width = (pct || 0) + '%';
    if (lbl) lbl.textContent = label || 'Working…';
}
function hideSessionProgress() {
    const wrap = document.getElementById('swalSessionProgress');
    if (wrap) wrap.style.display = 'none';
    const bar = document.getElementById('swalSessionProgressBar');
    if (bar) bar.style.width = '0%';
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
        alertInlineError('Audio recording is not supported in this browser.');
        return;
    }

    let stream;
    try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch (err) {
        alertInlineError('Could not access microphone: ' + (err.name || 'unknown'));
        return;
    }

    let rec;
    try {
        rec = new MediaRecorder(stream);
    } catch (e) {
        stream.getTracks().forEach(t => t.stop());
        alertInlineError('Could not create MediaRecorder.');
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
            alertInlineError('Empty recording. Please try again.');
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
        alertInlineError('Could not start recording: ' + (e.name || ''));
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
        alertInlineError('Could not play audio: ' + (err.message || ''));
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
// DASHBOARD WIDGETS — announcements · charts · calendar · SOS
// ============================================================
const DX = <?php echo $dxJson ?: '{}'; ?>;

const DX_ANN_CATS = {
    general:  { label: 'General',  icon: 'fa-bullhorn' },
    urgent:   { label: 'Urgent',   icon: 'fa-exclamation-circle' },
    event:    { label: 'Event',    icon: 'fa-calendar-check' },
    advisory: { label: 'Advisory', icon: 'fa-info-circle' },
    health:   { label: 'Health',   icon: 'fa-heartbeat' },
    safety:   { label: 'Safety',   icon: 'fa-shield-alt' },
};

// Complaints the Captain handles (pending_review belongs to the Secretary)
const DX_CASE_GROUPS = [
    { label: 'Pending Action',  color: '#f57c00', statuses: ['pending_captain_action'] },
    { label: 'Mediation',       color: '#8e24aa', statuses: ['summoned','for_mediation','mediation_scheduled','in_mediation'] },
    { label: 'Pangkat',         color: '#00838f', statuses: ['failed_mediation','pangkat_constitution_scheduled','pangkat_constituted','pangkat_scheduled'] },
    { label: 'Settlement',      color: '#66bb6a', statuses: ['settled_pending'] },
    { label: 'Resolved',        color: '#2E7D32', statuses: ['settled','certificate_issued'] },
    { label: 'Unresolved',      color: '#c62828', statuses: ['unresolved','failed_conciliation_final'] },
    { label: 'Referrals',       color: '#1976d2', statuses: ['escalated','pending_captain_review','referred_dispatched','released','referred'] },
    { label: 'Dismissed',       color: '#90a4ae', statuses: ['dismissed'] },
];
const DX_EQ_COLORS = { pending:'#f57c00', approved:'#43a047', borrowed:'#1976d2', returned:'#78909c', rejected:'#e53935', cancelled:'#b0bec5' };
const DX_EMG_COLORS = { PENDING:'#f57c00', ACCEPTED:'#43a047', RESOLVED:'#1976d2', COMPLETED:'#1976d2', CANCELLED:'#b0bec5', REJECTED:'#e53935' };
const DX_EMG_TYPE = {
    medical: { icon: 'fa-briefcase-medical', bg: '#ffebee', fg: '#c62828' },
    fire:    { icon: 'fa-fire',              bg: '#fff3e0', fg: '#e65100' },
    crime:   { icon: 'fa-user-secret',       bg: '#ede7f6', fg: '#4527a0' },
};

let __dxCharts = {};
let __dxTrendTab = 'trend';
let __dxStatusTab = 'complaints';
const __dxToday = new Date();
let __dxCal = { y: __dxToday.getFullYear(), m: __dxToday.getMonth(), sel: null };

function dxPad(n) { return String(n).padStart(2, '0'); }
function dxYmd(y, m, d) { return `${y}-${dxPad(m + 1)}-${dxPad(d)}`; }
function dxDestroy(key) { if (__dxCharts[key]) { try { __dxCharts[key].destroy(); } catch (e) {} delete __dxCharts[key]; } }
function dxCap(t) { t = String(t || ''); return t.charAt(0).toUpperCase() + t.slice(1).toLowerCase(); }

function initDashboardWidgets() {
    Object.keys(__dxCharts).forEach(dxDestroy);
    __dxTrendTab = 'trend';
    __dxStatusTab = 'complaints';
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
    dxRenderEmergencies();
}

function dxSetSeg(btn) {
    if (!btn) return;
    btn.parentElement.querySelectorAll('button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}
function dxSetTrend(tab, btn)  { dxSetSeg(btn); __dxTrendTab = tab;  dxRenderTrend(); }
function dxSetStatus(tab, btn) { dxSetSeg(btn); __dxStatusTab = tab; dxRenderStatus(); }

// ── Trend / SOS types ──
function dxRenderTrend() {
    const el = document.getElementById('dxTrendCanvas');
    if (!el || typeof Chart === 'undefined') return;
    dxDestroy('trend');
    const title = document.getElementById('dxTrendTitle');
    const deltaEl = document.getElementById('dxDelta');

    if (__dxTrendTab === 'trend') {
        if (title) title.textContent = '6-Month Trend';
        const comps = DX.complaintMonthly || [];
        if (deltaEl) {
            const cur = comps[comps.length - 1] || 0, prev = comps[comps.length - 2] || 0;
            let cls = 'flat', txt = 'no change', ic = 'fa-minus';
            if (prev > 0) {
                const pct = Math.round(((cur - prev) / prev) * 100);
                if (pct > 0)      { cls = '';     ic = 'fa-arrow-up';   txt = `${pct}%`; }
                else if (pct < 0) { cls = 'down'; ic = 'fa-arrow-down'; txt = `${Math.abs(pct)}%`; }
            } else if (cur > 0) { cls = ''; ic = 'fa-arrow-up'; txt = `${cur} new`; }
            deltaEl.innerHTML = `<span class="dx-delta ${cls}"><i class="fas ${ic}"></i> Cases ${txt}</span>`;
        }
        const grad = (hex) => (ctx) => {
            const a = ctx.chart.chartArea;
            if (!a) return hex + '22';
            const g = ctx.chart.ctx.createLinearGradient(0, a.top, 0, a.bottom);
            g.addColorStop(0, hex + '40'); g.addColorStop(1, hex + '05');
            return g;
        };
        const ds = (label, data, hex) => ({
            label, data, borderColor: hex, backgroundColor: grad(hex), fill: true, tension: 0.4,
            borderWidth: 3, pointRadius: 4, pointBackgroundColor: '#fff', pointBorderWidth: 2, pointHoverRadius: 6
        });
        __dxCharts.trend = new Chart(el, {
            type: 'line',
            data: { labels: DX.months || [], datasets: [
                ds('Complaints', comps, '#8e24aa'),
                ds('Emergencies', DX.emergencyMonthly || [], '#e53935'),
                ds('Equipment', DX.equipmentMonthly || [], '#1976d2'),
            ]},
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
        if (title) title.textContent = 'Emergencies by Type';
        if (deltaEl) deltaEl.innerHTML = '';
        const types = DX.emergencyTypes || [];
        __dxCharts.trend = new Chart(el, {
            type: 'bar',
            data: {
                labels: types.map(t => t.name),
                datasets: [{ label: 'Reports', data: types.map(t => t.count),
                    backgroundColor: types.map(t => (DX_EMG_TYPE[String(t.name).toLowerCase()] || { fg: '#78909c' }).fg),
                    borderRadius: 8, borderSkipped: false, maxBarThickness: 46 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { backgroundColor: '#1a2b22', padding: 10, cornerRadius: 8 } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { precision: 0, maxTicksLimit: 5 }, grid: { color: '#eef2f0' }, border: { display: false } }
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
        const total = chart.$dxEmpty ? 0 : (chart.data.datasets[0].data || []).reduce((a, b) => a + b, 0);
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
    if (__dxStatusTab === 'complaints') {
        const cs = DX.complaintStatus || {};
        DX_CASE_GROUPS.forEach(g => {
            const n = g.statuses.reduce((a, st) => a + (cs[st] || 0), 0);
            if (n > 0) { labels.push(g.label); data.push(n); colors.push(g.color); }
        });
    } else if (__dxStatusTab === 'emergencies') {
        Object.entries(DX.emergencyStatus || {}).forEach(([st, n]) => {
            labels.push(dxCap(st)); data.push(n); colors.push(DX_EMG_COLORS[st] || '#9e9e9e');
        });
    } else {
        Object.entries((DX.equipment && DX.equipment.status) || {}).forEach(([st, n]) => {
            labels.push(dxCap(st)); data.push(n); colors.push(DX_EQ_COLORS[st] || '#9e9e9e');
        });
    }
    const empty = data.reduce((a, b) => a + b, 0) === 0;
    if (empty) { labels = ['No data']; data = [1]; colors = ['#e5ece8']; }

    const chart = new Chart(el, {
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
    chart.$dxEmpty = empty;
    __dxCharts.status = chart;
}

// ── Calendar (hearings · equipment · SOS) ──
function dxEventsMap() {
    const map = {};
    (DX.events || []).forEach((e, i) => { e.__i = i; (map[e.date] = map[e.date] || []).push(e); });
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
        const kinds = [...new Set(evs.map(e => e.kind))];
        const dots = kinds.map(k => `<i class="dx-dot ${k}"></i>`).join('');
        html += `<button type="button" class="dx-day ${ds === todayStr ? 'today' : ''} ${ds === __dxCal.sel ? 'sel' : ''}"
                    onclick="dxCalSelect('${ds}')" title="${evs.length ? evs.length + ' event(s)' : ''}">
                    <span>${d}</span><span class="dx-dots">${dots}</span></button>`;
    }
    const trailing = rows * 7 - (first + days);
    for (let i = 0; i < trailing; i++) html += '<div class="dx-day blank"></div>';
    grid.style.gridTemplateRows = `repeat(${rows}, minmax(28px, 1fr))`;
    grid.innerHTML = html;
    dxRenderAgenda(map);
}

function dxEventHtml(e, showDate) {
    const when = showDate
        ? new Date(e.date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
        : dxFmtTime(e.time);
    const label = { hearing: 'Hearing', equipment: 'Equipment', emergency: 'SOS' }[e.kind] || 'Event';
    return `<div class="dx-ev ${e.kind}" onclick="dxOpenEvent(${e.__i})">
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
    const nice = sel ? new Date(sel + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' }) : '';
    let html = `<div class="dx-agenda-h">${escapeHtml(nice)}</div>`;
    if (evs.length) {
        html += evs.map(e => dxEventHtml(e, false)).join('');
    } else {
        html += '<div class="dx-empty">Nothing scheduled on this day.</div>';
        const upcoming = (DX.events || []).filter(e => e.date > sel && e.kind !== 'emergency')
            .sort((a, b) => a.date.localeCompare(b.date) || String(a.time || '').localeCompare(String(b.time || '')))
            .slice(0, 2);
        if (upcoming.length) {
            html += '<div class="dx-agenda-h" style="margin-top:6px;">Up next</div>' + upcoming.map(e => dxEventHtml(e, true)).join('');
        }
    }
    box.innerHTML = html;
}

function dxOpenEvent(i) {
    const e = (DX.events || [])[i];
    if (!e) return;
    if (e.kind === 'hearing') {
        if (typeof viewComplaint === 'function') viewComplaint(e.id);
        return;
    }
    if (e.kind === 'emergency') {
        const em = (DX.emergencies || []).find(x => Number(x.id) === Number(e.id));
        if (em) { dxShowEmergency(em); return; }
    }
    const nice = new Date(e.date + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    Swal.fire({
        icon: 'info',
        title: escapeHtml(e.title || 'Event'),
        html: `<div style="text-align:left;font-size:0.9rem;line-height:1.8;">
            <div><b>Type:</b> ${escapeHtml(dxCap(e.kind))}</div>
            <div><b>Date:</b> ${escapeHtml(nice)}${e.time ? ' · ' + dxFmtTime(e.time) : ''}</div>
            <div><b>Details:</b> ${escapeHtml(e.sub || '-')}</div>
            ${e.status ? `<div><b>Status:</b> ${escapeHtml(dxCap(String(e.status).replace(/_/g, ' ')))}</div>` : ''}
        </div>`,
        confirmButtonColor: '#2E7D32'
    });
}

// ── Emergency feed ──
function dxRenderEmergencies() {
    const box = document.getElementById('dxEmgList');
    if (!box) return;
    const list = DX.emergencies || [];
    if (!list.length) { box.innerHTML = '<div class="dx-empty">No emergency reports. 🎉</div>'; return; }
    box.innerHTML = list.map((e, i) => {
        const ty = DX_EMG_TYPE[String(e.emergency_type || '').toLowerCase()] || { icon: 'fa-exclamation-circle', bg: '#eceff1', fg: '#546e7a' };
        const st = String(e.status || '').toUpperCase();
        const cls = st === 'ACCEPTED' ? 'accepted' : (st === 'PENDING' ? 'pending' : '');
        return `<div class="dx-emg ${st === 'ACCEPTED' ? 'accepted' : ''}" onclick="dxShowEmergency(DX.emergencies[${i}])">
            <div class="dx-emg-ic" style="background:${ty.bg};color:${ty.fg};"><i class="fas ${ty.icon}"></i></div>
            <div class="dx-emg-body">
                <div class="dx-emg-t">${escapeHtml(e.emergency_type)} — ${escapeHtml(e.name)}</div>
                <div class="dx-emg-s">${escapeHtml(e.barangay || '')} · ${escapeHtml(timeAgo(e.created_at))}</div>
            </div>
            <span class="dx-emg-chip ${cls}">${escapeHtml(dxCap(st))}</span>
        </div>`;
    }).join('');
}

function dxShowEmergency(e) {
    if (!e) return;
    const ty = DX_EMG_TYPE[String(e.emergency_type || '').toLowerCase()] || { icon: 'fa-exclamation-circle', bg: '#eceff1', fg: '#546e7a' };
    const st = String(e.status || '').toUpperCase();
    const when = e.created_at ? new Date(String(e.created_at).replace(' ', 'T')).toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' }) : '-';
    const hasPos = e.latitude && e.longitude;
    const map = hasPos ? `https://www.google.com/maps?q=${encodeURIComponent(e.latitude + ',' + e.longitude)}` : '';
    const row = (k, v) => `<div style="display:flex;gap:10px;padding:7px 0;border-bottom:1px dashed #eef2f0;">
        <div style="width:110px;color:#7a8a82;font-size:0.78rem;font-weight:700;text-transform:uppercase;">${k}</div>
        <div style="flex:1;font-weight:600;color:#1a2b22;">${v}</div></div>`;
    Swal.fire({
        title: '',
        html: `<div style="text-align:left;font-family:'Segoe UI',Roboto,sans-serif;">
            <div style="display:flex;align-items:center;gap:14px;padding:16px 20px;background:${ty.bg};border-radius:14px 14px 0 0;">
                <div style="width:46px;height:46px;border-radius:50%;background:#fff;color:${ty.fg};display:flex;align-items:center;justify-content:center;font-size:1.2rem;"><i class="fas ${ty.icon}"></i></div>
                <div><div style="font-weight:800;font-size:1.05rem;color:${ty.fg};">${escapeHtml(e.emergency_type)} Emergency</div>
                <div style="font-size:0.78rem;color:#555;">${escapeHtml(e.external_id || ('Report #' + e.id))}</div></div>
            </div>
            <div style="padding:10px 20px 16px;">
                ${row('Reported by', escapeHtml(e.name))}
                ${row('Barangay', escapeHtml(e.barangay || '-'))}
                ${row('Caller', e.caller_number ? `<a href="tel:${escapeHtml(e.caller_number)}" style="color:#1565c0;">${escapeHtml(e.caller_number)}</a>` : '-')}
                ${row('Status', escapeHtml(dxCap(st)) + (e.accepted_by_name ? ` · accepted by ${escapeHtml(e.accepted_by_name)}` : ''))}
                ${row('Reported', escapeHtml(when))}
                ${hasPos ? `<a href="${map}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:8px;margin-top:14px;padding:9px 16px;background:#c62828;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;font-size:0.85rem;"><i class="fas fa-map-marker-alt"></i> Open location in Maps</a>` : ''}
            </div></div>`,
        width: 460, showConfirmButton: true, confirmButtonText: 'Close', confirmButtonColor: '#6c757d',
        customClass: { popup: 'sm-popup' }
    });
}

// ── Announcements (read-only) ──
async function loadAnnouncements() {
    const wrap = document.getElementById('anncGridWrap');
    if (!wrap) return;
    try {
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
// DASHBOARD
// ============================================================
function renderDashboard() {
    const kpi = (icon, bg, fg, num, label, go) => `
        <div class="dx-kpi" ${go ? `data-go="${go}" onclick="navigateTo('${go}')"` : ''}>
            <div class="dx-kpi-ic" style="background:${bg};color:${fg};"><i class="fas ${icon}"></i></div>
            <div><div class="dx-kpi-num">${num}</div><div class="dx-kpi-lbl">${label}</div></div>
        </div>`;
    const eqPending  = (DX.equipment && DX.equipment.status && DX.equipment.status.pending)  || 0;
    const eqBorrowed = (DX.equipment && DX.equipment.status && DX.equipment.status.borrowed) || 0;

    return `
    <div class="dx">

        <!-- TOP BAR -->
        <div class="dx-top">
            <div class="dx-top-text">
                <h2><span id="dxGreeting">Welcome</span>, Captain <?php echo htmlspecialchars($admin['full_name']); ?>!</h2>
                <p><i class="fas fa-calendar-alt"></i><span id="currentDate"></span></p>
            </div>
            <div class="dx-top-actions">
                <button class="dx-top-btn" onclick="navigateTo('complaints')"><i class="fas fa-gavel"></i><span>Complaints</span></button>
                <button class="dx-top-btn" onclick="navigateTo('household')"><i class="fas fa-home"></i><span>Households</span></button>
                <button class="dx-top-btn" onclick="window.location.href='reports.php'"><i class="fas fa-chart-line"></i><span>Reports</span></button>
            </div>
        </div>

        <!-- KPI STRIP -->
        <div class="dx-kpis">
            ${kpi('fa-users',           '#e8f5e9', '#2e7d32', <?php echo (int)$stats['total_residents']; ?>,  'Residents',     'household')}
            ${kpi('fa-clock',           '#fff3e0', '#e65100', <?php echo (int)$stats['pending_approvals']; ?>, 'Pending Docs',  '')}
            ${kpi('fa-inbox',           '#e3f2fd', '#1565c0', <?php echo (int)$stats['pending_action']; ?>,    'Pending Action','complaints')}
            ${kpi('fa-gavel',           '#f3e5f5', '#6a1b9a', <?php echo (int)$stats['in_mediation']; ?>,      'In Mediation', 'complaints')}
            ${kpi('fa-file-signature',  '#fff8e1', '#f57c00', <?php echo (int)$stats['referrals']; ?>,          'Referrals',    'complaints')}

            ${kpi('fa-tools',           '#e0f2f1', '#00897b', eqPending,                                       'Equip. Requests','')}
            ${kpi('fa-hand-holding',    '#e1f5fe', '#0277bd', eqBorrowed,                                      'Borrowed',     '')}
        </div>

        <!-- MAIN GRID -->
        <div class="dx-grid">

            <!-- Announcements (read-only, big) -->
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

            <!-- Trend -->
            <div class="dx-card dx-trend">
                <div class="dx-card-h">
                    <h4><i class="fas fa-chart-line"></i> <span id="dxTrendTitle">6-Month Trend</span></h4>
                    <span id="dxDelta"></span>
                    <div class="dx-seg">
                        <button class="active" onclick="dxSetTrend('trend', this)">Trend</button>
                        <button onclick="dxSetTrend('types', this)">SOS Types</button>
                    </div>
                </div>
                <div class="dx-chart"><canvas id="dxTrendCanvas"></canvas></div>
            </div>

            <!-- Status doughnut -->
            <div class="dx-card dx-status">
                <div class="dx-card-h">
                    <h4><i class="fas fa-chart-pie"></i> Status</h4>
                    <div class="dx-seg">
                        <button class="active" onclick="dxSetStatus('complaints', this)">Cases</button>
                        <button onclick="dxSetStatus('emergencies', this)">SOS</button>
                        <button onclick="dxSetStatus('equipment', this)">Equip.</button>
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
                        <span><i class="dx-dot equipment"></i> Equipment</span>
                        <span><i class="dx-dot emergency"></i> SOS</span>
                    </div>
                    <div class="dx-agenda" id="dxAgenda"></div>
                </div>
            </div>

            <!-- Emergencies -->
            <div class="dx-card dx-emerg">
                <div class="dx-card-h">
                    <h4><i class="fas fa-ambulance" style="color:#c62828;"></i> Emergencies</h4>
                    <span class="dx-count-chip ${<?php echo (int)$dxEmgPending; ?> > 0 ? '' : 'ok'}">${<?php echo (int)$dxEmgPending; ?>} pending</span>
                </div>
                <div class="dx-emg-list" id="dxEmgList"></div>
            </div>

        </div>
    </div>
    `;
}

let currentComplaintView = 'pending';
let cachedData = { pending: [], mediation: [], pangkat: [], repudiated: [], unresolved: [], referrals: [], handled: [] };

// Pagination + toolbar state
let caseFilters = {
    search: '',
    days: 30,
    dateFrom: '',
    dateTo: ''
};
let casePage = 1;
const CASES_PER_PAGE = 6;

// ═══════════════════════════════════════════════════════
// COMPLAINT FILTER BAR — EXPAND / COLLAPSE
// ═══════════════════════════════════════════════════════
let complaintFiltersExpanded = false;

function toggleComplaintFiltersBar() {
    complaintFiltersExpanded = !complaintFiltersExpanded;

    const wrapper = document.getElementById('complaintFiltersWrapper');
    const chevron = document.getElementById('complaintFiltersChevron');
    const summary = document.getElementById('complaintFiltersSummary');

    if (wrapper) {
        if (complaintFiltersExpanded) {
            wrapper.style.display = 'block';
            wrapper.style.maxHeight = '0px';
            wrapper.style.overflow  = 'hidden';
            wrapper.style.transition = 'max-height 0.3s ease, opacity 0.25s ease, margin-top 0.25s ease';
            wrapper.style.opacity   = '0';
            wrapper.style.marginTop = '0px';

            void wrapper.offsetHeight;
            wrapper.style.maxHeight = wrapper.scrollHeight + 'px';
            wrapper.style.opacity   = '1';
            wrapper.style.marginTop = '10px';

            setTimeout(() => {
                if (complaintFiltersExpanded) wrapper.style.maxHeight = 'none';
            }, 320);
        } else {
            wrapper.style.maxHeight = wrapper.scrollHeight + 'px';
            wrapper.style.overflow  = 'hidden';
            void wrapper.offsetHeight;
            wrapper.style.maxHeight = '0px';
            wrapper.style.opacity   = '0';
            wrapper.style.marginTop = '0px';
            setTimeout(() => {
                if (!complaintFiltersExpanded) wrapper.style.display = 'none';
            }, 320);
        }
    }

    if (chevron) chevron.style.transform = complaintFiltersExpanded ? 'rotate(180deg)' : 'rotate(0)';
    if (summary) summary.style.display = complaintFiltersExpanded ? 'none' : 'inline-block';

    try { sessionStorage.setItem('complaintFiltersExpanded', complaintFiltersExpanded ? '1' : '0'); } catch(e) {}
}

(function restoreComplaintFilterBarState() {
    try {
        const saved = sessionStorage.getItem('complaintFiltersExpanded');
        if (saved === '1') complaintFiltersExpanded = true;
    } catch(e) {}
})();

function complaintFiltersSummaryHtml() {
    const parts = [];
    const tabLabels = {
        pending:    'Pending Action',
        mediation:  'Mediation',
        pangkat:    'For Pangkat',
        repudiated: 'Repudiated',
        unresolved: 'Unresolved',
        referrals:  'Referrals',
        handled:    'Handled'
    };
    const tab = tabLabels[currentComplaintView] || 'Pending Action';
    parts.push(`<i class="fas fa-tasks"></i> ${escapeHtml(tab)}`);

    if (caseFilters.search) parts.push(`<i class="fas fa-search"></i> "${escapeHtml(caseFilters.search)}"`);

    const days = caseFilters.days;
    if (days === -1 && caseFilters.dateFrom && caseFilters.dateTo) {
        parts.push(`<i class="fas fa-calendar"></i> ${caseFilters.dateFrom} → ${caseFilters.dateTo}`);
    } else if (days === 0) {
        parts.push(`<i class="fas fa-calendar"></i> All time`);
    } else if (days > 0) {
        parts.push(`<i class="fas fa-calendar"></i> Last ${days} day${days === 1 ? '' : 's'}`);
    }

    return parts.join('<span class="cfa-sep">·</span>');
}

async function loadAllComplaints() {
    try {
        const params = new URLSearchParams();
        params.set('action', 'get_captain_all_complaints');
        if (caseFilters.search) params.set('search', caseFilters.search);
        if (caseFilters.days === -1) {
            params.set('days', '-1');
            if (caseFilters.dateFrom) params.set('date_from', caseFilters.dateFrom);
            if (caseFilters.dateTo)   params.set('date_to',   caseFilters.dateTo);
        } else {
            params.set('days', String(caseFilters.days));
        }

        const r = await fetch('../admin_complaint_ajax.php?' + params.toString());
        const d = await r.json();
        if (!d.success) throw new Error(d.message || 'Failed');

        cachedData.pending    = d.pending    || [];
        cachedData.mediation  = d.mediation  || [];
        cachedData.pangkat    = d.pangkat    || [];
        cachedData.repudiated = d.repudiated || [];
        cachedData.unresolved = d.unresolved || [];
        cachedData.referrals  = d.referrals  || [];
        cachedData.handled    = d.handled    || [];

        cachedData.mediation.forEach(row => {
            if (!row.explain_deadline) row.explain_deadline = row.absence_explain_deadline || null;
        });

        const set = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.textContent = val;
        };
        set('count-pending',    cachedData.pending.length);
        set('count-mediation',  cachedData.mediation.length);
        set('count-pangkat',    cachedData.pangkat.length);
        set('count-repudiated', cachedData.repudiated.length);
        set('count-unresolved', cachedData.unresolved.length);
        set('count-referrals',  cachedData.referrals.length);
        set('count-handled',    cachedData.handled.length);

        const badge = document.getElementById('complaintsBadge');
        if (badge) {
            const total = cachedData.pending.length;
            if (total > 0) { badge.style.display = 'inline-block'; badge.textContent = total; }
            else badge.style.display = 'none';
        }
    } catch (e) {
        console.error('Captain load failed:', e);
    }
}

function switchComplaintView(view) {
    currentComplaintView = view;
    casePage = 1;
    document.querySelectorAll('.u-chip').forEach(c => c.classList.remove('active'));
    document.getElementById('chip-' + view)?.classList.add('active');
    renderCurrentCasePage();
    const sum = document.getElementById('complaintFiltersSummary');
    if (sum) sum.innerHTML = complaintFiltersSummaryHtml();
}

function renderCurrentCasePage() {
    const list = cachedData[currentComplaintView] || [];
    const container = document.getElementById('complaintsList');
    const countEl = document.getElementById('caseResultCount');

    if (!list.length) {
        if (countEl) countEl.textContent = '0 results';
        const msgs = {
            pending:    { icon: 'fa-check-circle', text: 'No complaints awaiting action.' },
            mediation:  { icon: 'fa-check-circle', text: 'No active mediations.' },
            pangkat:    { icon: 'fa-check-circle', text: 'No cases waiting for Pangkat.' },
            repudiated: { icon: 'fa-check-circle', text: 'No repudiated cases.' },
            unresolved: { icon: 'fa-check-circle', text: 'No unresolved cases.' },
            referrals:  { icon: 'fa-check-circle', text: 'No referrals pending.' },
            handled:    { icon: 'fa-inbox',         text: 'You have not handled any complaints yet.' }
        };
        const m = msgs[currentComplaintView] || { icon: 'fa-inbox', text: 'Nothing to show.' };
        container.innerHTML = `<div style="text-align:center;padding:60px;color:#999;"><i class="fas ${m.icon} fa-3x" style="color:#c8e6c9;"></i><p style="margin-top:12px;">${m.text}</p></div>`;
        return;
    }

    const total = list.length;
    const totalPages = Math.max(1, Math.ceil(total / CASES_PER_PAGE));
    if (casePage > totalPages) casePage = totalPages;
    const start = (casePage - 1) * CASES_PER_PAGE;
    const pageItems = list.slice(start, start + CASES_PER_PAGE);

    if (countEl) {
        countEl.textContent = `${total} result${total === 1 ? '' : 's'}`;
    }

    let html = '<div class="documents-grid">';
    const renderFn = {
        pending:    renderPendingCard,
        mediation:  renderMediationCard,
        pangkat:    renderPangkatCard,
        repudiated: renderRepudiatedCard,
        unresolved: renderUnresolvedCard,
        referrals:  renderReferralCard,
        handled:    renderHandledCard
    }[currentComplaintView];
    if (renderFn) html += pageItems.map(renderFn).join('');
    html += '</div>';

    html += renderPagination(totalPages, total);
    container.innerHTML = html;
    startLiveCountdowns();
}

function renderPagination(totalPages, totalItems) {
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
    const list = cachedData[currentComplaintView] || [];
    const totalPages = Math.max(1, Math.ceil(list.length / CASES_PER_PAGE));
    if (p < 1 || p > totalPages) return;
    casePage = p;
    renderCurrentCasePage();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* ── Toolbar events ───────────────────────────────────── */
let __searchDebounce = null;
function onCaseSearch(val) {
    clearTimeout(__searchDebounce);
    __searchDebounce = setTimeout(async () => {
        caseFilters.search = val.trim();
        casePage = 1;
        await loadAllComplaints();
        renderCurrentCasePage();
        const sum = document.getElementById('complaintFiltersSummary');
        if (sum) sum.innerHTML = complaintFiltersSummaryHtml();
    }, 350);
}

async function onDateRangeChange(val) {
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
    await loadAllComplaints();
    renderCurrentCasePage();
    const sum = document.getElementById('complaintFiltersSummary');
    if (sum) sum.innerHTML = complaintFiltersSummaryHtml();
}

async function onCustomRangeChange() {
    caseFilters.dateFrom = document.getElementById('caseDateFrom').value;
    caseFilters.dateTo   = document.getElementById('caseDateTo').value;
    if (!caseFilters.dateFrom || !caseFilters.dateTo) return;
    casePage = 1;
    await loadAllComplaints();
    renderCurrentCasePage();
    const sum = document.getElementById('complaintFiltersSummary');
    if (sum) sum.innerHTML = complaintFiltersSummaryHtml();
}

async function resetCaseToolbar() {
    caseFilters = { search: '', days: 30, dateFrom: '', dateTo: '' };
    casePage = 1;
    const s = document.getElementById('caseSearch'); if (s) s.value = '';
    const sel = document.getElementById('caseDateRange'); if (sel) sel.value = '30';
    const cw = document.getElementById('caseCustomRange'); if (cw) cw.style.display = 'none';
    await loadAllComplaints();
    renderCurrentCasePage();
    const sum = document.getElementById('complaintFiltersSummary');
    if (sum) sum.innerHTML = complaintFiltersSummaryHtml();
}

function renderUnresolvedCard(x) {
    const certNo = x.certificate_no || x.bar_action_cert_no || x.bar_counterclaim_cert_no || null;
    const issuedAt = x.certificate_issued_at
        ? new Date(String(x.certificate_issued_at).replace(' ','T')).toLocaleDateString('en-US', {dateStyle:'medium'})
        : (x.cfa_issued_at
            ? new Date(String(x.cfa_issued_at).replace(' ','T')).toLocaleDateString('en-US', {dateStyle:'medium'})
            : '—');

    return `
        <div class="document-card" onclick="viewComplaint(${x.id})" style="cursor:pointer;border:2px solid #ce93d8;">
            <div class="document-card-header" style="background:linear-gradient(135deg,#6a1b9a,#8e24aa);">
                <div class="document-title"><i class="fas fa-file-certificate"></i><span>${escapeHtml(x.title)}</span></div>
                <div class="document-status"><i class="fas fa-file-certificate"></i> UNRESOLVED</div>
            </div>
            <div class="document-card-body">
                <div class="document-info-row"><div class="document-info-label">Ref:</div><div class="document-info-value"><strong>${escapeHtml(x.reference_number)}</strong></div></div>
                <div class="document-info-row"><div class="document-info-label">Complainant:</div><div class="document-info-value">${escapeHtml(x.first_name || '')} ${escapeHtml(x.last_name || '')}</div></div>
                ${certNo ? `<div class="document-info-row"><div class="document-info-label">Cert No:</div><div class="document-info-value"><strong>${escapeHtml(certNo)}</strong></div></div>` : ''}
                <div class="document-info-row"><div class="document-info-label">Issued:</div><div class="document-info-value">${escapeHtml(issuedAt)}</div></div>
                <div style="margin-top:10px;padding:10px 12px;background:#f3e5f5;border-left:4px solid #8e24aa;border-radius:8px;font-size:0.78rem;color:#4a148c;line-height:1.6;">
                    <b><i class="fas fa-file-signature"></i> Certificate to File Action has been issued.</b><br>
                    Print and hand-deliver to the complainant so they can file the case in court.
                </div>
            </div>
            <div class="document-card-actions" onclick="event.stopPropagation()">
                <button class="doc-action-btn pdf" onclick="event.stopPropagation(); openCfaPdf(${x.id}, 'complainant')">
                    <i class="fas fa-file-pdf"></i> View CFA PDF
                </button>
                <button class="doc-action-btn view" onclick="event.stopPropagation(); viewComplaint(${x.id})">
                    <i class="fas fa-eye"></i> View
                </button>
            </div>
        </div>`;
}

// ============================================================
// HANDLED CARD
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
                ${x.status === 'certificate_issued' ? `
                    <button class="doc-action-btn pdf" onclick="event.stopPropagation(); openCfaPdf(${x.id}, 'complainant')"><i class="fas fa-file-pdf"></i> View CFA (KP #20)</button>
                ` : ''}
                <button class="doc-action-btn view" onclick="event.stopPropagation(); viewComplaint(${x.id})"><i class="fas fa-eye"></i> View</button>
            </div>
        </div>`;
}

// ============================================================
// PENDING CARD
// ============================================================
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

// ============================================================
// MEDIATION CARD
// ============================================================
function renderMediationCard(x) {
    const sessionCount = x.mediation_session_count || 1;
    const hearingDate = x.hearing_date ? x.hearing_date.split(' ')[0] : null;
    const today = new Date().toISOString().split('T')[0];
    const isHearingToday = (hearingDate === today);
    const canStart = hearingDate && hearingDate <= today;
    const lastOutcome = x.last_hearing_outcome || null;
    const wasArbitrationRepudiated = Number(x.arbitration_repudiated || 0) === 1;
    const wasSettlementRepudiated  = Number(x.amicable_repudiated || 0) === 1;
const isNoShow = (lastOutcome === 'complainant_no_show'
              || lastOutcome === 'respondent_no_show'
              || lastOutcome === 'both_no_show'
              || lastOutcome === 'parties_no_show');
const isWaiting = !!x.absence_appearance_at || isNoShow;
const hasExplainDeadline = !!x.explain_deadline;
const headerCls = isWaiting ? 'waiting' : 'mediation';

let statusLabel;
if (isWaiting && !!x.absence_appearance_at) {
    statusLabel = hasExplainDeadline ? 'Awaiting 5-Day Explain Window' : 'Awaiting Appearance';
} else if (isNoShow) {
    statusLabel = (lastOutcome === 'complainant_no_show') ? 'Complainant No-Show'
                : (lastOutcome === 'respondent_no_show')  ? 'Respondent No-Show'
                : (lastOutcome === 'both_no_show')        ? 'Both Parties No-Show'
                : 'Party No-Show';
} else {
    statusLabel = `Mediation Hearing #${sessionCount}`;
}

    let actionButtons;
   if (x.status === 'arbitration_pending') {
    actionButtons = `<button class="doc-action-btn locked"><i class="fas fa-hourglass-half"></i> Awaiting 5-Day Repudiation Window</button>`;
} else if (x.status === 'arbitration_agreement_active') {
    actionButtons = `<button class="doc-action-btn mediate" onclick="event.stopPropagation(); openIssueArbitrationAwardModal(${x.id})"><i class="fas fa-gavel"></i> Render KP Form #15</button>`;
} else if (x.status === 'arbitration_award_issued') {
    actionButtons = `<button class="doc-action-btn view" onclick="event.stopPropagation(); viewComplaint(${x.id})"><i class="fas fa-eye"></i> View Award</button>`;
} else if (x.status === 'arbitration_repudiated') {
    // Arbitration was repudiated → only legal action is to issue the CFA
    // (Repudiated cases now appear in the dedicated "Repudiated" filter.)
    actionButtons = `
        <button class="doc-action-btn" style="background:#c62828;color:#fff;" onclick="event.stopPropagation(); openIssueCfaModal(${x.id}, 'complainant')"><i class="fas fa-print"></i> Issue CFA (KP #20)</button>`;
}else if (x.status === 'certificate_issued') {
    actionButtons = `
        <button class="doc-action-btn pdf" onclick="event.stopPropagation(); openCfaPdf(${x.id}, 'complainant')"><i class="fas fa-file-pdf"></i> View CFA (KP #20)</button>
        <button class="doc-action-btn view" onclick="event.stopPropagation(); viewComplaint(${x.id})"><i class="fas fa-eye"></i> View</button>`;
} else if (isWaiting) {

        actionButtons = `<button class="doc-action-btn resume" onclick="event.stopPropagation(); openMediationSessionModal(${x.id})"><i class="fas fa-play"></i> Continue Mediation</button>`;
    } else if (canStart) {
        actionButtons = `<button class="doc-action-btn mediate" onclick="event.stopPropagation(); openMediationSessionModal(${x.id})"><i class="fas fa-play-circle"></i> Start Mediation</button>`;
    } else {
        actionButtons = `<button class="doc-action-btn locked"><i class="fas fa-lock"></i> Available ${hearingDate ? new Date(hearingDate).toLocaleDateString('en-US',{month:'short',day:'numeric'}) : 'on hearing day'}</button>`;
    }

    const inMediation = ['for_mediation', 'mediation_scheduled'].includes(x.status);
    const deadlineIso = inMediation ? (x.mediation_deadline || null) : null;
    const appearanceIso = x.absence_appearance_at || null;
    const explainIso = x.explain_deadline || null;

    return `
        <div class="document-card" onclick="viewComplaint(${x.id})" style="cursor:pointer;">
            <div class="document-card-header ${headerCls}">
                <div class="document-title"><i class="fas fa-gavel"></i><span>${escapeHtml(x.title)}</span></div>
                <div class="document-status">${statusLabel}</div>
            </div>
            <div class="document-card-body">
                <div class="document-info-row"><div class="document-info-label">Ref:</div><div class="document-info-value"><strong>${escapeHtml(x.reference_number)}</strong></div></div>
                <div class="document-info-row"><div class="document-info-label">Complainant:</div><div class="document-info-value">${escapeHtml(x.first_name)} ${escapeHtml(x.last_name)}</div></div>
                ${wasArbitrationRepudiated ? `
                    <div style="margin-top:10px;padding:10px 12px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.75rem;color:#b71c1c;line-height:1.5;">
                        <i class="fas fa-gavel"></i>
                        <strong>KP #14 (Agreement for Arbitration) was REPUDIATED</strong>
                        by the <strong>${escapeHtml((x.arbitration_repudiated_by_party || 'party').toUpperCase())}</strong>.
                        The case fell back to mediation.
                    </div>` : ''}
                ${wasSettlementRepudiated ? `
                    <div style="margin-top:10px;padding:10px 12px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.75rem;color:#b71c1c;line-height:1.5;">
                        <i class="fas fa-file-signature"></i>
                        <strong>KP #16 (Amicable Settlement) was REPUDIATED</strong>
                        by the <strong>${escapeHtml((x.amicable_repudiated_by_party || 'party').toUpperCase())}</strong>.
                        The case proceeds to Certification to File Action.
                    </div>` : ''}
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
                ${explainIso ? `<div class="document-info-row" style="background:#ffebee;padding:6px 8px;border-radius:6px;margin-top:4px;">
                    <div class="document-info-label" style="color:#b71c1c;font-weight:700;width:auto;margin-right:6px;">⏳ Explain by:</div>
                    <div class="document-info-value live-countdown" data-deadline="${escapeHtml(explainIso)}"><span class="status-pill critical"><i class="fas fa-hourglass-half"></i> Calculating…</span></div>
                </div>` : ''}
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

// ============================================================
// PANGKAT CARD
// ============================================================
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
                ${Number(x.amicable_repudiated || 0) === 1 ? `
                    <div style="margin-top:10px;padding:10px 12px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.75rem;color:#b71c1c;line-height:1.5;">
                        <i class="fas fa-file-signature"></i>
                        <strong>KP #16 (Amicable Settlement) was REPUDIATED</strong>
                        by the <strong>${escapeHtml((x.amicable_repudiated_by_party || 'party').toUpperCase())}</strong>.
                        Issue the Certificate to File Action.
                    </div>` : ''}
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
                ${x.status === 'amicable_repudiated'
                    ? `<button class="doc-action-btn" style="background:#c62828;color:#fff;" onclick="event.stopPropagation(); openIssueCfaModal(${x.id}, 'complainant')"><i class="fas fa-print"></i> Issue CFA to Complainant</button>`
                    : (isConstitutionScheduled
                        ? `<button class="doc-action-btn constitute" onclick="event.stopPropagation(); openMediationSessionModal(${x.id})"><i class="fas fa-users-cog"></i> Continue Constitution</button>`
                        : (x.status === 'failed_mediation'
                            ? `<button class="doc-action-btn schedule" onclick="event.stopPropagation(); openMediationSessionModal(${x.id})"><i class="fas fa-calendar-plus"></i> Set Constitution Meeting</button>`
                            : `<button class="doc-action-btn view" onclick="event.stopPropagation(); viewComplaint(${x.id})"><i class="fas fa-eye"></i> View</button>`))}
            </div>
        </div>`;
}
function renderRepudiatedCard(x) {
    const wasAmicable    = Number(x.amicable_repudiated || 0) === 1;
    const wasArbitration = Number(x.arbitration_repudiated || 0) === 1;

    const repBy   = wasAmicable
        ? (x.amicable_repudiated_by_party || 'party')
        : (x.arbitration_repudiated_by_party || 'party');
    const repAt   = wasAmicable
        ? (x.amicable_repudiated_at || null)
        : (x.arbitration_repudiated_at || null);
    const repLabel = wasAmicable
        ? 'Amicable Settlement (KP #16)'
        : 'Agreement for Arbitration (KP #14)';

    const repDateNice = repAt
        ? new Date(String(repAt).replace(' ','T')).toLocaleDateString('en-US', {dateStyle:'medium'})
        : '—';

    const headerCls = wasAmicable ? 'pending' : 'waiting';
    const headerIcon = wasAmicable ? 'fa-file-signature' : 'fa-gavel';

    const repudiationDeadline = wasAmicable
        ? (x.repudiation_deadline || null)
        : null;

    // KP #17 — only shown if it already exists on disk
    const kp17Path = x.kp17_pdf_path || null;

    return `
        <div class="document-card" onclick="viewComplaint(${x.id})" style="cursor:pointer;border:2px solid #ef9a9a;">
            <div class="document-card-header ${headerCls}" style="background:linear-gradient(135deg,#b71c1c,#c62828);">
                <div class="document-title"><i class="fas ${headerIcon}"></i><span>${escapeHtml(x.title)}</span></div>
                <div class="document-status"><i class="fas fa-times-circle"></i> Repudiated</div>
            </div>
            <div class="document-card-body">
                <div class="document-info-row"><div class="document-info-label">Ref:</div><div class="document-info-value"><strong>${escapeHtml(x.reference_number)}</strong></div></div>
                <div class="document-info-row"><div class="document-info-label">Complainant:</div><div class="document-info-value">${escapeHtml(x.first_name || '')} ${escapeHtml(x.last_name || '')}</div></div>

                <div style="margin-top:10px;padding:12px 14px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.78rem;color:#b71c1c;line-height:1.6;">
                    <div style="font-weight:800;text-transform:uppercase;font-size:0.7rem;letter-spacing:0.4px;margin-bottom:6px;">
                        <i class="fas fa-exclamation-triangle"></i> ${escapeHtml(repLabel)} was REPUDIATED
                    </div>
                    <div><b>Repudiated by:</b> ${escapeHtml(String(repBy).toUpperCase())}</div>
                    <div><b>Filed on:</b> ${escapeHtml(repDateNice)}</div>
                    ${repudiationDeadline ? `<div style="margin-top:6px;padding-top:6px;border-top:1px dashed #ef9a9a;"><b>Repudiation window ended:</b> ${new Date(String(repudiationDeadline).replace(' ','T')).toLocaleDateString('en-US',{dateStyle:'medium'})}</div>` : ''}
                </div>

                ${kp17Path ? `
                <div style="margin-top:10px;padding:12px 14px;background:#f3e5f5;border-left:4px solid #6a1b9a;border-radius:8px;font-size:0.78rem;line-height:1.6;">
                    <div style="font-weight:800;text-transform:uppercase;font-size:0.7rem;letter-spacing:0.4px;margin-bottom:6px;color:#4a148c;">
                        <i class="fas fa-file-signature"></i> KP Form #17 — Repudiation
                    </div>
                    <div style="color:#4a148c;">
                        <b>✔ Repudiation document is on file.</b>
                        ${x.kp17_generated_at ? `<div style="margin-top:4px;font-size:0.72rem;color:#666;">Generated: ${new Date(String(x.kp17_generated_at).replace(' ','T')).toLocaleString('en-US',{dateStyle:'medium',timeStyle:'short'})}</div>` : ''}
                    </div>
                    <button type="button"
                            class="doc-action-btn pdf"
                            style="margin-top:8px;padding:6px 14px;font-size:0.75rem;"
                            onclick="event.stopPropagation(); window.open('../../${escapeHtml(kp17Path)}','_blank');">
                        <i class="fas fa-file-pdf"></i> Open KP #17 PDF
                    </button>
                </div>` : ''}

                <div style="margin-top:10px;padding:8px 12px;background:#fff8e1;border-left:4px solid #f57c00;border-radius:8px;font-size:0.72rem;color:#8a3e00;line-height:1.5;">
                    <b><i class="fas fa-gavel"></i> Next legal step:</b> Issue a <b>Certificate to File Action (KP #20)</b> in printed copy to the complainant.
                </div>
            </div>
            <div class="document-card-actions" onclick="event.stopPropagation()">
                <button class="doc-action-btn" style="background:#c62828;color:#fff;flex:1;" onclick="event.stopPropagation(); openIssueCfaModal(${x.id}, 'complainant')"><i class="fas fa-print"></i> Issue CFA (KP #20)</button>
                <button class="doc-action-btn view" onclick="event.stopPropagation(); viewComplaint(${x.id})"><i class="fas fa-eye"></i> View</button>
            </div>
        </div>`;
}

// ============================================================
// REFERRAL CARD
// ============================================================
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

async function openBarCertPdf(complaintId, type, forWhom) {
    if (!complaintId) {
        Swal.fire('Error', 'Missing complaint ID.', 'error');
        return;
    }
    if (type !== 'action' && type !== 'counterclaim') {
        Swal.fire('Error', 'Invalid certification type.', 'error');
        return;
    }

    Swal.fire({
        title: 'Loading PDF…',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    try {
        let url = `../admin_complaint_ajax.php?action=get_bar_cert_pdf`
                + `&complaint_id=${encodeURIComponent(complaintId)}`
                + `&type=${encodeURIComponent(type)}`;

        if (forWhom === 'complainant' || forWhom === 'respondent') {
            url += `&for=${encodeURIComponent(forWhom)}`;
        }

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
        Swal.fire('Error', 'Network error while loading the certification.', 'error');
    }
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
// KP FORMS GROUPED BY RECIPIENT
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
// NOTICES DIALOG
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
// CASE KP FORMS
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
                <div class="dm-info" style="background:#ffebee;border-left-color:#c62828;color:#b71c1c;margin-top:10px;">
                    <i class="fas fa-hourglass-half"></i>
                    <div>
                        <div class="dm-info-title" style="color:#b71c1c;">5-Day Explain Window</div>
                        <div style="font-size:0.82rem;line-height:1.6;">
                            The absent party has <b>5 days</b> after the appearance date to explain. If they do not appear or fail to give a justified reason within those 5 days, their absence is <b>automatically declared UNJUSTIFIED</b>:
                            <ul style="margin-top:6px;padding-left:16px;">
                                <li><b>Complainant unjustified</b> → Complaint DISMISSED + barred from court (KP #23)</li>
                                <li><b>Respondent unjustified</b> → Counterclaim BARRED + case forwarded to Certification (KP #24)</li>
                            </ul>
                        </div>
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
// MEDIATION SESSION MODAL (WIZARD)
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
    if (step === 'unjustified_outcome')  return onUnjustOutcomeContinue();
    if (step === 'result')               return onResultContinue();
    if (step === 'settle_branch')        return onSettleBranchContinue();
    if (step === 'settlement_terms')     return onSaveSettlement();
    if (step === 'arbitration_terms')    return onSaveArbitrationTerms();
    if (step === 'no_settlement')        return onNotSettledContinue();
    if (step === 'schedule_next')        return onSaveScheduleNext();
    if (step === 'schedule_justified')   return onSaveScheduleJustified();
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
        <div id="swalSessionInlineError" style="display:none;margin:0 22px 12px;padding:10px 14px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.85rem;color:#b71c1c;"></div>
        <div id="swalSessionProgress" style="display:none;margin:0 22px 12px;">
            <div style="height:6px;background:#e0e0e0;border-radius:6px;overflow:hidden;">
                <div id="swalSessionProgressBar" style="height:100%;width:0%;background:linear-gradient(90deg,#2E7D32,#66bb6a);transition:width .4s ease;"></div>
            </div>
            <div id="swalSessionProgressLabel" style="font-size:0.78rem;color:#555;margin-top:6px;text-align:center;">Working…</div>
        </div>
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
            </div>

            <div id="step-unjustified-outcome" style="display:none;">
                <div class="dm-section-title warn"><span class="dm-step">2B</span> Proceed to Pangkat</div>

                <div class="dm-info" style="background:#fff8e1;border-left-color:#f57c00;color:#e65100;">
                    <i class="fas fa-gavel"></i>
                    <div>
                        <div class="dm-info-title" style="color:#c62828;">Unjustified Absence Recorded</div>
                        <div style="font-size:0.82rem;line-height:1.6;">
                            The respondent's absence has been declared <b>UNJUSTIFIED</b>.
                            Per RA 7160, their counterclaim is barred. The case will now proceed to the Pangkat.
                        </div>
                    </div>
                </div>

                <div class="dm-option-group" id="unjustOutcomeGroup">
                    <div class="dm-option selected" data-value="end_mediation" onclick="selectUnjustOutcome('end_mediation')">
                        <div class="dm-option-icon warn"><i class="fas fa-balance-scale"></i></div>
                        <div class="dm-option-text">
                            <div class="dm-option-title">End Mediation → Constitute a Pangkat</div>
                            <div class="dm-option-desc">
                                Issue KP Form #24 (counterclaim barred). Forward the case to the Pangkat and
                                set the <b>constitution meeting date</b> next.
                            </div>
                        </div>
                        <div class="dm-option-check"><i class="fas fa-check"></i></div>
                    </div>
                </div>

                <input type="hidden" id="unjustOutcomeChoice" value="end_mediation">
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
           <div id="step-settle-branch" style="display:none;">
    <div class="dm-section-title"><span class="dm-step">3</span> How will you resolve this?</div>

    <div class="dm-info" style="background:#e8f5e9;border-left-color:#2E7D32;color:#1b5e20;">
        <i class="fas fa-handshake"></i>
        <div>
            <div style="font-weight:700;">Both parties agreed to resolve the case.</div>
            <div style="margin-top:4px;font-size:0.82rem;line-height:1.6;">
                Pick the legal form of the resolution:
            </div>
        </div>
    </div>

    <div class="dm-option-group cols-2" id="settleBranchGroup">

        <div class="dm-option" data-value="settlement" onclick="selectSettleBranch('settlement')">
            <div class="dm-option-icon success"><i class="fas fa-file-signature"></i></div>
            <div class="dm-option-text">
                <div class="dm-option-title">Amicable Settlement — KP Form #16</div>
                <div class="dm-option-desc">
                    The parties <b>agree on their own terms</b>. You write KP Form #16; both parties sign.<br><br>
                    <b>Repudiation window:</b> 10 days from signing (fraud / violence / intimidation).
                </div>
            </div>
            <div class="dm-option-check"><i class="fas fa-check"></i></div>
        </div>

        <div class="dm-option" data-value="arbitration" onclick="selectSettleBranch('arbitration')">
            <div class="dm-option-icon info"><i class="fas fa-gavel"></i></div>
            <div class="dm-option-text">
                <div class="dm-option-title">Arbitration — KP Form #14 → KP Form #15</div>
                <div class="dm-option-desc">
                    The parties <b>hand decision power to you</b>. They sign <b>KP Form #14</b> now; you render <b>KP Form #15</b> within 15 days.<br><br>
                    <b>Repudiation window:</b> 5 days from signing KP Form #14.
                </div>
            </div>
            <div class="dm-option-check"><i class="fas fa-check"></i></div>
        </div>
    </div>
    <input type="hidden" id="settleBranch" value="">
</div>

<div id="step-arbitration-terms" style="display:none;">
    <div class="dm-section-title"><span class="dm-step">3A</span> Agreement for Arbitration — Hearing Summary</div>

    <div class="dm-info" style="background:#e3f2fd;border-left-color:#1565c0;color:#0d47a1;">
        <i class="fas fa-gavel"></i>
        <div>
            <div style="font-weight:700;">KP Form #14 will be issued to both parties.</div>
            <div style="margin-top:6px;font-size:0.82rem;line-height:1.6;">
                <b>Repudiation window:</b> 5 days from signing (fraud / violence / intimidation).<br>
                <b>Award deadline:</b> you must render KP Form #15 within 15 days from signing.
            </div>
        </div>
    </div>

    <div class="dm-field" style="margin-bottom:12px;">
        <label>Date Parties Signed KP Form #14 <span class="req">*</span></label>
        <input type="date" id="arbSignedDate"
               max="${new Date().toISOString().split('T')[0]}"
               value="${new Date().toISOString().split('T')[0]}">
        <div class="dm-helper" style="margin-top:6px;">
            <i class="fas fa-info-circle"></i>
            <span>The <b>5-day repudiation window</b> and <b>15-day award deadline</b> are counted from this date.</span>
        </div>
    </div>

    ${sessionRecordBlockHtml('arb')}
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
              <div class="dm-section-title" style="margin-top:22px;"><span class="dm-step">4</span> What's next?</div>
${!isExpired ? `
  <div class="dm-option-group cols-2" id="medNextGroup">
    <div class="dm-option" data-value="schedule" onclick="selectMedNext('schedule')">
      <div class="dm-option-icon info"><i class="fas fa-calendar-plus"></i></div>
      <div class="dm-option-text">
        <div class="dm-option-title">Schedule Another Hearing</div>
        <div class="dm-option-desc">Continue mediation on a new date.</div>
      </div>
      <div class="dm-option-check"><i class="fas fa-check"></i></div>
    </div>
    <div class="dm-option" data-value="end" onclick="selectMedNext('end')">
      <div class="dm-option-icon danger"><i class="fas fa-balance-scale"></i></div>
      <div class="dm-option-text">
        <div class="dm-option-title">End Mediation → Pangkat</div>
        <div class="dm-option-desc">Forward immediately to the Pangkat, even if the 15-day window has not yet expired.</div>
      </div>
      <div class="dm-option-check"><i class="fas fa-check"></i></div>
    </div>
  </div>
  <input type="hidden" id="medNextAction" value="">
` : `
  <div class="dm-info" style="background:#ffebee;border-left-color:#c62828;color:#b71c1c;">
    <i class="fas fa-hourglass-end"></i>
    <div>The 15-day mediation period has expired. The case must now proceed to the Pangkat.</div>
  </div>
  <input type="hidden" id="medNextAction" value="end">
`}
            </div>

            <div id="step-schednext" style="display:none;">
                <div class="dm-section-title"><span class="dm-step">4A</span> Schedule Next Mediation Hearing</div>
                <div class="dm-grid-2"><div class="dm-field" style="margin-bottom:0;"><label>Date <span class="req">*</span></label><input type="date" id="medNextDate" min="${new Date().toISOString().split('T')[0]}"></div><div class="dm-field" style="margin-bottom:0;"><label>Time <span class="req">*</span></label><input type="time" id="medNextTime" value="09:00"></div></div>
                <div class="dm-inline-rule"><i class="fas fa-info-circle"></i><span>Office hours: <b>8:00 AM – 6:30 PM</b> · min. 2 hours from now.</span></div>
                <div class="dm-conflict" id="medConflict"><i class="fas fa-exclamation-triangle"></i><div></div></div>
                <div class="dm-available" id="medAvailable"><i class="fas fa-check-circle"></i><span>Available.</span></div>
            </div>

            <div id="step-schedjustified" style="display:none;">
                <div class="dm-section-title"><span class="dm-step">4B</span> Schedule Next Mediation Hearing (Justified Absences)</div>

                <div class="dm-info" style="background:#e8f5e9;border-left-color:#2E7D32;color:#1b5e20;margin-bottom:14px;">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <div style="font-weight:700;">Absence decision saved — Justified.</div>
                        <div style="margin-top:4px;">Now pick the next mediation hearing date and time.</div>
                    </div>
                </div>

                <div class="dm-warning" style="background:#fff8e1;border-left-color:#f57c00;">
                    <i class="fas fa-hourglass-half"></i>
                    <div>
                        <div style="font-weight:700;">A new hearing date is required.</div>
                        <div style="margin-top:4px;">
                            The absent part${(window.__lastJustifiedCount || 0) > 1 ? 'ies were' : 'y was'} declared <b>JUSTIFIED</b>.
                            Schedule the next mediation hearing <b>within the 15-day window</b>
                            ${deadlineNice ? `(ends <b>${deadlineNice}</b>)` : ''}.
                        </div>
                    </div>
                </div>

                <div class="dm-inline-rule">
                    <i class="fas fa-gavel"></i>
                    <span>Per RA 7160: a justified absence does not dismiss the case — it is <b>rescheduled with a strict warning</b> to the absent party.</span>
                </div>

                <div class="dm-grid-2">
                    <div class="dm-field" style="margin-bottom:0;">
                        <label>New Hearing Date <span class="req">*</span></label>
                        <input type="date" id="justDate" min="${new Date().toISOString().split('T')[0]}">
                    </div>
                    <div class="dm-field" style="margin-bottom:0;">
                        <label>Time <span class="req">*</span></label>
                        <input type="time" id="justTime" value="09:00">
                    </div>
                </div>
                <div class="dm-inline-rule" style="margin-top:8px;">
                    <i class="fas fa-info-circle"></i>
                    <span>Office hours: <b>8:00 AM – 6:30 PM</b> · min. 2 hours from now · no overlaps.</span>
                </div>
                <div class="dm-conflict" id="justConflict"><i class="fas fa-exclamation-triangle"></i><div></div></div>
                <div class="dm-available" id="justAvailable"><i class="fas fa-check-circle"></i><span>Available.</span></div>

                <div class="dm-field" style="margin-top:18px;margin-bottom:0;">
                    <label>Remarks to absent party <span class="opt">(optional — appears in audit trail)</span></label>
                    <textarea id="justRemarks" rows="2" placeholder="e.g. Stated reason: hospitalized. Warning issued in person."></textarea>
                </div>
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
                <div id="wheelModeWrap" style="display:none;">

    <!-- NEW: Pre-selection step -->
    <div id="wheelPreSelectWrap">
        <div class="dm-field" style="margin-bottom:10px;">
            <label>Who will be included in the draw? <span class="req">*</span></label>
            <div class="dm-helper" style="margin-bottom:10px;">
                <i class="fas fa-info-circle"></i>
                <span>Check <b>at least 3</b> Lupon members. Only checked members will appear on the wheel.</span>
            </div>
            <div id="wheelPreSelectList"
                 style="max-height:260px;overflow-y:auto;border:1.5px solid #cfd8dc;
                        border-radius:8px;padding:10px;background:#fff;">
                <div style="text-align:center;color:#999;padding:14px;font-size:0.85rem;">
                    <i class="fas fa-spinner fa-spin"></i> Loading…
                </div>
            </div>
            <div class="dm-helper" style="margin-top:6px;">
                <i class="fas fa-lightbulb"></i>
                <span>Selected for draw: <b id="wheelPreSelectCount">0</b>
                · <span id="wheelPreSelectHint" style="color:#1565c0;font-weight:600;">
                    Check at least 3 members to enable the wheel.</span></span>
            </div>
        </div>

        <button type="button" id="wheelConfirmPoolBtn"
                class="ses-btn primary"
                style="display:block;margin:10px auto 0;padding:10px 24px;"
                onclick="confirmWheelPool()">
            <i class="fas fa-check-circle"></i> Confirm & Show Wheel
        </button>
    </div>

    <!-- Wheel (hidden until pool confirmed) -->
    <div id="wheelSpinWrap" style="display:none;">
        <div id="wheelStage" class="wheel-stage">
            <div class="wheel-pointer"></div>
            <div id="wheelDisc" class="wheel-disc"></div>
        </div>
        <div class="pick-banner" id="pickBanner">
            <i class="fas fa-check-circle"></i>
            <span id="pickBannerText">—</span>
        </div>
        <div class="wheel-result" id="wheelResult">Ready — spin to pick member #1</div>
        <button type="button" id="spinBtn" class="wheel-spin-btn" onclick="spinWheel()" disabled>
            <i class="fas fa-dice"></i> Loading…
        </button>
        <button type="button" id="wheelResetPoolBtn"
                class="ses-btn close"
                style="display:block;margin:10px auto 0;"
                onclick="resetWheelPool()">
            <i class="fas fa-arrow-left"></i> Change Draw Pool
        </button>
    </div>
</div>
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
            window.__caseStatus = complaint.status || resume.case_status || '';
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
                infoBox.innerHTML = `<div class="appear-info-banner"><i class="fas fa-user-clock"></i><div><div>Appearance was set for: <strong>${new Date(window.__appearanceAt.replace(' ','T')).toLocaleString('en-US',{dateStyle:'full',timeStyle:'short'})}</strong></div><div style="font-size:0.78rem;color:#666;margin-top:3px;">Decide below whether the absence is Justified or Unjustified. If no decision is made within 5 days, it will be automatically declared UNJUSTIFIED.</div></div></div>`;
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
   ['attendance','decision','unjustified_outcome','result','settle_branch','settlement','arbitration_terms','nosettle','schednext','schedjustified','constitute','pangkat'].forEach(s => {
        const el = document.getElementById('step-' + s); if (el) el.style.display = 'none';
    });
    const show = (id) => { const el = document.getElementById(id); if (el) el.style.display = 'block'; };
    if (step === 'attendance') { show('step-attendance'); updateContinueButton(); }
    else if (step === 'absence_decision') { show('step-decision'); setFooterButtons({ primaryLabel: '<i class="fas fa-gavel"></i> Confirm Decision', disablePrimary: !isDecisionComplete(), primaryVariant: 'danger' }); }
    else if (step === 'unjustified_outcome') { show('step-unjustified-outcome'); setFooterButtons({ primaryLabel: '<i class="fas fa-arrow-right"></i> Continue', disablePrimary: false, primaryVariant: 'danger' }); }
    else if (step === 'result') { show('step-result'); populateResultStatements(); setFooterButtons({ primaryLabel: '<i class="fas fa-arrow-right"></i> Continue', disablePrimary: !document.getElementById('medSettled').value }); }
        else if (step === 'settle_branch') {
        show('step-settle-branch');
        setFooterButtons({
            primaryLabel: '<i class="fas fa-arrow-right"></i> Continue',
            disablePrimary: true,
            primaryVariant: '',
        });
    }
    else if (step === 'arbitration_terms') {
        show('step-arbitration-terms');
        setFooterButtons({
            primaryLabel: '<i class="fas fa-gavel"></i> Save & Issue KP Form #14',
            disablePrimary: false,
            primaryVariant: 'danger',
        });
    }
    else if (step === 'settlement_terms') { show('step-settlement'); setFooterButtons({ primaryLabel: '<i class="fas fa-save"></i> Save Record', disablePrimary: false }); }
    else if (step === 'no_settlement') { show('step-nosettle'); setFooterButtons({ primaryLabel: '<i class="fas fa-arrow-right"></i> Continue', disablePrimary: false }); }
    else if (step === 'schedule_next') { show('step-schednext'); setFooterButtons({ primaryLabel: '<i class="fas fa-calendar-check"></i> Save & Schedule', disablePrimary: false, primaryVariant: 'warn' }); }
    else if (step === 'schedule_justified') {
        show('step-schedjustified');

        if (window.__nosetSummary) {
            const noSum = document.getElementById('medNoSummary');
            if (noSum && !noSum.value) noSum.value = window.__nosetSummary;
            const srSum = document.getElementById('noset-sr-summary');
            if (srSum && !srSum.value) srSum.value = window.__nosetSummary;
        }

        setFooterButtons({
            primaryLabel: '<i class="fas fa-check"></i> Confirm & Schedule',
            disablePrimary: false,
            primaryVariant: 'warn',
            hideClose: true
        });
        initJustifiedSchedulingValidation();
    }
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
    if (closeBtn) closeBtn.style.display = (step === 'schedule_justified') ? 'none' : '';
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
    if (!residentId) { alertInlineError('No linked resident account.'); return; }
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
            clearInlineError();
            const row = document.getElementById('nudge-row-' + partyId);
            if (row) { const span = row.querySelector('span'); if (span) span.innerHTML = `<i class="fas fa-bell"></i> Reminder sent ${d.nudge_count || 1} time(s).`; btnEl.innerHTML = '<i class="fas fa-paper-plane"></i> Nudge Again'; btnEl.disabled = (d.nudge_count >= 3); }
        } else { alertInlineError(d.message || 'Failed.'); btnEl.innerHTML = original; btnEl.disabled = false; }
    } catch (e) { alertInlineError('Network error.'); btnEl.innerHTML = original; btnEl.disabled = false; }
}

async function onAttContinue() {
    const parties = window.__parties || [];
    const missing = parties.filter(p => { const st = window.__attendanceState[p.id]; return !st || st === 'pending'; });
    if (missing.length) { alertInlineError('Mark attendance for: ' + missing.map(p => p.full_name).join(', ')); return; }
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
            const niceExplainDeadline = dA.explain_deadline
                ? new Date(dA.explain_deadline.replace(' ', 'T')).toLocaleString('en-US', {dateStyle:'full',timeStyle:'short'})
                : '';
            await Swal.fire({
                icon: 'success',
                title: 'Appearance Date Set',
                html: `<div style="text-align:left;font-size:0.9rem;">
                    <p>The absent part${notices.length>1?'ies':'y'} must appear before you on:</p>
                    <p style="font-weight:700;color:#c62828;font-size:1rem;">${escapeHtml(niceAppear)}</p>
                    ${niceExplainDeadline ? `<div style="margin-top:12px;padding:10px 12px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.82rem;color:#b71c1c;">
                        <b>5-Day Explain Deadline:</b> ${escapeHtml(niceExplainDeadline)}<br>
                        If no explanation is received by then, the absence will be auto-declared <b>UNJUSTIFIED</b>.
                    </div>` : ''}
                    <div class="notice-panel"><div class="notice-panel-title"><i class="fas fa-file-signature"></i> Failure-to-Appear Notices (${notices.length})</div>${list}</div>
                    ${printBanner}
                </div>`,
                confirmButtonText: '<i class="fas fa-check"></i> Done',
                confirmButtonColor: '#2E7D32',
                width: 720,
                allowOutsideClick: false
            }).then(() => reloadComplaints());
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
    clearInlineError();
    const parties = window.__parties || [];
    const absent = parties.filter(p => window.__attendanceState[p.id] === 'no_show');
    const decisions = absent.map(p => {
        const card = document.querySelector(`.abs-card[data-decision-pid="${p.id}"]`);
        const ta = card?.querySelector('textarea[data-role="appearance_notes"]');
        const d = window.__decisionState[p.id] || {};
        const appNotes = (ta?.value || '').trim();
        return {
            party_id: p.id,
            party_type: p.role,
            status: d.status,
            reason: appNotes,
            appeared: 0,
            appearance_notes: appNotes
        };
    });

    if (!decisions.length) { alertInlineError('No absence decisions to confirm.'); return; }
    window.__pendingDecisions = decisions;

    const anyUnjust         = decisions.some(d => d.status === 'unjustified');
    const complainantUnjust = decisions.some(d => d.status === 'unjustified' && d.party_type === 'complainant');
    const respondentUnjustOnly = anyUnjust && !complainantUnjust;

    // Respondent-only unjustified → show the Pangkat confirmation screen
    if (respondentUnjustOnly) {
        goToStep('unjustified_outcome');
        return;
    }

    // Complainant unjustified → keep the existing scary confirmation
    if (anyUnjust) {
        const summary = decisions.map(d => {
            const pname = (parties.find(p => p.id === d.party_id) || {}).full_name || '';
            return `<li><b>${escapeHtml(pname)}</b> — ${escapeHtml(d.party_type)}<br>Decision: <b style="color:${d.status === 'unjustified' ? '#c62828' : '#2E7D32'}">${(d.status || '').toUpperCase()}</b></li>`;
        }).join('');

        const confirm = await Swal.fire({
            icon: 'warning',
            title: 'Confirm Absence Decision?',
            html: `<div style="text-align:left;font-size:0.9rem;">
                <p>This will be executed immediately:</p>
                <ul style="margin-top:8px;line-height:1.7;">${summary}</ul>
                <p style="margin-top:12px;font-size:0.82rem;color:#b71c1c;font-weight:700;">
                    <i class="fas fa-exclamation-triangle"></i>
                    Unjustified complainant → COMPLAINT DISMISSED + KP Form #23 auto-issued.
                </p>
            </div>`,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-gavel"></i> Confirm Decision',
            confirmButtonColor: '#c62828',
            cancelButtonText: 'Back',
            cancelButtonColor: '#6c757d',
            allowOutsideClick: false,
            allowEscapeKey: false
        });
        if (!confirm.isConfirmed) return;

        await submitAbsenceDecisions(null);
        return;
    }

    // All justified → straight through
    await submitAbsenceDecisions(null);
}

function selectUnjustOutcome(val) {
    // Only one option exists now, but keep the hook so the UI stays consistent.
    const group = document.getElementById('unjustOutcomeGroup');
    if (!group) return;
    group.querySelectorAll('.dm-option').forEach(o => o.classList.remove('selected'));
    const sel = group.querySelector(`.dm-option[data-value="${val}"]`);
    if (sel) sel.classList.add('selected');
    document.getElementById('unjustOutcomeChoice').value = val;
}

async function onUnjustOutcomeContinue() {
    // Only one choice exists → always "end_mediation"
    await submitAbsenceDecisions('end_mediation');
}

async function submitAbsenceDecisions(outcomeChoice) {
    clearInlineError();
    const decisions = window.__pendingDecisions || [];
    if (!decisions.length) { alertInlineError('No absence decisions to submit.'); return; }

    showSessionProgress('Saving absence decision…', 15);

    const fd = new FormData();
    fd.append('action', 'resolve_absence_decisions');
    fd.append('complaint_id', window.__complaintId);
    fd.append('hearing_id', window.__hearingId);
    fd.append('decisions', JSON.stringify(decisions));
    if (outcomeChoice) fd.append('outcome_choice', outcomeChoice);

    let d;
    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        showSessionProgress('Saving absence decision…', 60);
        d = await r.json();
    } catch (e) {
        hideSessionProgress();
        alertInlineError('Network error while saving the decision.');
        return;
    }

    if (!d.success) {
        hideSessionProgress();
        alertInlineError(d.message || 'Failed to save decision.');
        return;
    }

    showSessionProgress('Decision saved.', 100);
    window.__sessionSaved = true;

    // ── Justified → inline schedule step ──
    if (d.outcome === 'reschedule') {
        window.__lastJustifiedCount = decisions.filter(x => x.status === 'justified').length;
        const autoSummary = decisions.map(x => {
            const pname = (window.__parties.find(p => p.id === x.party_id) || {}).full_name || '';
            return `${pname} (${x.party_type}) — justified${x.appearance_notes ? ': ' + x.appearance_notes : ''}`;
        }).join('; ');
        window.__nosetSummary = `Mediation Hearing #${window.__sessionNo} — absences justified. ${autoSummary}. Rescheduled with strict warning.`;
        hideSessionProgress();
        goToStep('schedule_justified');
        return;
    }

    // ── End mediation → reopen wizard on the constitution step ──
    if (d.outcome === 'end_to_pangkat') {
        hideSessionProgress();
        Swal.close();
        await Swal.fire({
            icon: 'success',
            title: 'Mediation Ended — Proceeding to Pangkat',
            html: `<div style="text-align:left;font-size:0.9rem;">
                <p>The respondent did not appear without justifiable cause. Their counterclaim is <b>barred</b>.</p>
                <p style="margin-top:6px;">You will now set the <b>Pangkat constitution meeting</b>.</p>
                ${d.cert_info ? `<div style="margin-top:12px;padding:12px 14px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.83rem;color:#b71c1c;">
                    <b><i class="fas fa-gavel"></i> ${escapeHtml(d.cert_info.label)}</b><br>
                    Cert No: <b>${escapeHtml(d.cert_info.cert_no)}</b><br>
                    <a href="#" onclick="event.preventDefault(); window.open('../../${escapeHtml(d.cert_info.pdf_url)}','_blank');"
                       style="display:inline-block;margin-top:6px;padding:5px 12px;background:#c62828;color:#fff;border-radius:6px;text-decoration:none;font-size:0.78rem;font-weight:700;">
                        <i class="fas fa-file-pdf"></i> Open PDF
                    </a>
                </div>` : ''}
            </div>`,
            confirmButtonText: '<i class="fas fa-calendar-plus"></i> Set Constitution Meeting',
            confirmButtonColor: '#2E7D32',
            allowOutsideClick: false
        });
        reloadComplaints();
        setTimeout(() => {
            openMediationSessionModal(window.__complaintId, { startStep: 'constitute_pangkat' });
        }, 250);
        return;
    }

    // ── Fallback: complainant unjustified → dismissed ──
    hideSessionProgress();
    const cert = d.cert_info;
    const certBlock = cert
        ? `<div style="margin-top:12px;padding:12px 14px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.85rem;color:#b71c1c;">
            <b><i class="fas fa-gavel"></i> ${escapeHtml(cert.label)}</b><br>
            Cert No: <b>${escapeHtml(cert.cert_no)}</b><br>
            <a href="#" onclick="event.preventDefault(); window.open('../../${escapeHtml(cert.pdf_url)}', '_blank');"
               style="display:inline-block;margin-top:6px;padding:5px 12px;background:#c62828;color:#fff;border-radius:6px;text-decoration:none;font-size:0.78rem;font-weight:700;">
                <i class="fas fa-file-pdf"></i> Open PDF
            </a>
        </div>` : '';
    Swal.close();
    Swal.fire({
        icon: 'error',
        title: 'Complaint Dismissed',
        html: `<div style="text-align:left;font-size:0.9rem;">
            <p>Complaint was DISMISSED because the complainant did not appear without justifiable cause.</p>
            ${certBlock}
        </div>`,
        confirmButtonText: 'Done',
        confirmButtonColor: '#2E7D32',
        allowOutsideClick: false
    }).then(() => reloadComplaints());
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

function selectSettleBranch(value) {
    const group = document.getElementById('settleBranchGroup');
    if (!group) return;
    group.querySelectorAll('.dm-option').forEach(o => o.classList.remove('selected'));
    const sel = group.querySelector(`.dm-option[data-value="${value}"]`);
    if (sel) sel.classList.add('selected');
    document.getElementById('settleBranch').value = value;

    if (window.__step === 'settle_branch') {
        setFooterButtons({
            primaryLabel: value === 'arbitration'
                ? '<i class="fas fa-gavel"></i> Continue to KP Form #14'
                : '<i class="fas fa-file-signature"></i> Continue to KP Form #16',
            disablePrimary: false,
            primaryVariant: value === 'arbitration' ? 'danger' : '',
        });
    }
}

function onSettleBranchContinue() {
    const branch = document.getElementById('settleBranch').value;
    if (branch === 'settlement') {
        goToStep('settlement_terms');
    } else if (branch === 'arbitration') {
        goToStep('arbitration_terms');
    } else {
        alertInlineError('Please pick a resolution type.');
    }
}

async function onSaveArbitrationTerms() {
const sr = collectSessionRecord('arb');
const summary = (sr.summary || '').trim();
if (!summary) { alertInlineError('Please fill the "Summary of this mediation hearing" field.'); return; }

    const primBtn = document.getElementById('sesBtnPrimary');
    const oldLabel = primBtn ? primBtn.innerHTML : '';
    if (primBtn) { primBtn.disabled = true; primBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…'; }

    try {
        const uploadRes = await uploadAllPendingStatements('res', window.__complaintId, window.__hearingId);
        const failedUploads = Object.entries(uploadRes)
            .filter(([_, r]) => !r.success)
            .map(([p, r]) => p + ': ' + (r.message || 'unknown'));
        if (failedUploads.length) {
            if (primBtn) { primBtn.disabled = false; primBtn.innerHTML = oldLabel; }
            alertInlineError('Could not upload: ' + failedUploads.join(', '));
            return;
        }



// Read the signing date from the input
const signedDate = (document.getElementById('arbSignedDate')?.value || '').trim();
if (!signedDate) {
    alertInlineError('Please enter the date the parties signed KP Form #14.');
    return;
}

const fd = new FormData();
fd.append('action', 'captain_record_mediation_outcome');
fd.append('complaint_id', window.__complaintId);
fd.append('outcome_kind', 'arbitration');
fd.append('summary', summary);
fd.append('session_record', JSON.stringify(sr));
fd.append('signed_date', signedDate);   // ← NEW

        const csText = document.getElementById('res-cs-text')?.value.trim() || '';
        const rsText = document.getElementById('res-rs-text')?.value.trim() || '';
        if (csText) fd.append('complainant_statement', csText);
        if (rsText) fd.append('respondent_statement',  rsText);

        const attPayload = buildAttendancePayload();
        if (attPayload.length) fd.append('attendance', JSON.stringify(attPayload));

        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (!d.success) {
            if (primBtn) { primBtn.disabled = false; primBtn.innerHTML = oldLabel; }
            alertInlineError(d.message || 'Failed.');
            return;
        }
        window.__sessionSaved = true;

        Swal.close();
        Swal.fire({
            icon: 'success',
            title: 'KP Form #14 Issued',
            html: `<div style="text-align:left;font-size:0.9rem;">
                <p>The Agreement for Arbitration (KP Form #14) has been recorded.</p>
                <div style="margin-top:12px;padding:12px 14px;background:#fff8e1;border-left:4px solid #f57c00;border-radius:8px;font-size:0.82rem;color:#e65100;">
                    <b>5-Day Repudiation Window</b><br>
                    Ends: <b>${escapeHtml(d.repudiation_deadline ? new Date(d.repudiation_deadline.replace(' ','T')).toLocaleString('en-US',{dateStyle:'long',timeStyle:'short'}) : '—')}</b>
                </div>
                <div style="margin-top:12px;padding:12px 14px;background:#e3f2fd;border-left:4px solid #1565c0;border-radius:8px;font-size:0.82rem;color:#0d47a1;">
                    <b>KP Form #15 (Arbitration Award) Due</b><br>
                    By: <b>${escapeHtml(d.award_deadline ? new Date(d.award_deadline.replace(' ','T')).toLocaleString('en-US',{dateStyle:'long'}) : '—')}</b>
                </div>
            </div>`,
            confirmButtonText: '<i class="fas fa-check"></i> Done',
            confirmButtonColor: '#2E7D32',
            width: 620
        }).then(() => reloadComplaints());
    } catch (e) {
        if (primBtn) { primBtn.disabled = false; primBtn.innerHTML = oldLabel; }
        alertInlineError('Network error.');
    }
}

async function openIssueArbitrationAwardModal(caseId) {
    const result = await Swal.fire({
        title: '',
        html: `<div class="dm-form">
            <div class="dm-header red">
                <div class="dm-header-left">
                    <i class="fas fa-gavel"></i>
                    <div>
                        <div class="dm-header-title">Render KP Form #15 — Arbitration Award</div>
                        <div class="dm-header-sub">Final and binding decision</div>
                    </div>
                </div>
            </div>
            <div class="dm-body">
                <div class="dm-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <b>The award is final and binding.</b><br>
                        Once rendered, both parties must comply. Enter the full text of your decision.
                    </div>
                </div>
                <div class="dm-field">
                    <label>Award Text <span class="req">*</span></label>
                    <textarea id="arbAwardText" rows="8" placeholder="Write the full arbitration award here…"></textarea>
                </div>
            </div>
        </div>`,
        width: 720,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-file-signature"></i> Render KP Form #15',
        confirmButtonColor: '#b71c1c',
        cancelButtonColor: '#6c757d',
        allowOutsideClick: false,
        preConfirm: () => {
            const text = document.getElementById('arbAwardText').value.trim();
            if (!text) { Swal.showValidationMessage('Please enter the award text.'); return false; }
            return { award_text: text };
        }
    });
    if (!result.isConfirmed) return;

    Swal.fire({ title: 'Rendering award…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    const fd = new FormData();
    fd.append('action', 'captain_issue_arbitration_award');
    fd.append('complaint_id', caseId);
    fd.append('award_text', result.value.award_text);

    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        Swal.close();
        if (d.success) {
            Swal.fire({
                icon: 'success',
                title: 'KP Form #15 Issued',
                html: `<div style="text-align:left;font-size:0.9rem;">
                    <p>The Arbitration Award has been rendered. Both parties have been notified.</p>
                    ${d.kp15 && d.kp15.pdf_path ? `<a href="#" onclick="event.preventDefault(); window.open('../../${escapeHtml(d.kp15.pdf_path)}','_blank');" style="display:inline-block;margin-top:8px;padding:6px 14px;background:#b71c1c;color:#fff;border-radius:6px;text-decoration:none;font-size:0.8rem;font-weight:700;"><i class="fas fa-file-pdf"></i> Open KP Form #15</a>` : ''}
                </div>`,
                confirmButtonColor: '#2E7D32'
            }).then(() => reloadComplaints());
        } else {
            Swal.fire('Error', d.message || 'Failed.', 'error');
        }
    } catch (e) { Swal.close(); Swal.fire('Error', 'Network error.', 'error'); }
}


// ============================================================
// ISSUE CERTIFICATE TO FILE ACTION (KP #20) — PRINTED COPY
// ============================================================
async function openIssueCfaModal(complaintId, forWhom) {
    if (!complaintId) return;

    Swal.fire({ title: 'Loading…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    let d;
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_admin_complaint_details&id=${complaintId}`);
        d = await r.json();
    } catch (e) {
        Swal.close();
        Swal.fire('Error', 'Could not load complaint.', 'error');
        return;
    }
    Swal.close();

    if (!d || !d.success) { Swal.fire('Error', d.message || 'Not found.', 'error'); return; }

    const c = d.complaint;
    const complainants = d.complainants || [];
    const respondents  = d.respondents  || [];

    const isForComplainant = (forWhom === 'complainant');
    const primaryName = isForComplainant
        ? (complainants[0]?.full_name || 'Complainant')
        : (respondents[0]?.full_name || 'Respondent');

    const wasAmicableRep = Number(c.amicable_repudiated || 0) === 1;
    const wasArbRep      = Number(c.arbitration_repudiated || 0) === 1;

    let repudiationBlock = '';
    let defaultReason = '';

    if (wasAmicableRep) {
        const t = c.amicable_repudiated_at
            ? new Date(c.amicable_repudiated_at.replace(' ','T')).toLocaleString('en-US',{dateStyle:'long',timeStyle:'short'})
            : '—';
        const by = (c.amicable_repudiated_by_party || 'party').toUpperCase();
        repudiationBlock = `
            <div style="margin-top:12px;padding:12px 14px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.82rem;color:#b71c1c;line-height:1.6;">
                <b><i class="fas fa-file-signature"></i> KP #16 (Amicable Settlement) was REPUDIATED</b><br>
                Repudiated by: <b>${escapeHtml(by)}</b><br>
                Filed: ${escapeHtml(t)}
            </div>`;
        defaultReason = `The Amicable Settlement (KP Form #16) was repudiated by the ${by.toLowerCase()}. The case cannot proceed to conciliation and must be filed in court.`;
    } else if (wasArbRep) {
        const t = c.arbitration_repudiated_at
            ? new Date(c.arbitration_repudiated_at.replace(' ','T')).toLocaleString('en-US',{dateStyle:'long',timeStyle:'short'})
            : '—';
        const by = (c.arbitration_repudiated_by_party || 'party').toUpperCase();
        repudiationBlock = `
            <div style="margin-top:12px;padding:12px 14px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.82rem;color:#b71c1c;line-height:1.6;">
                <b><i class="fas fa-gavel"></i> KP #14 (Agreement for Arbitration) was REPUDIATED</b><br>
                Repudiated by: <b>${escapeHtml(by)}</b><br>
                Filed: ${escapeHtml(t)}
            </div>`;
        defaultReason = `The Agreement for Arbitration (KP Form #14) was repudiated by the ${by.toLowerCase()}. The case must now proceed to court.`;
    }

    const result = await Swal.fire({
        title: '',
        html: `
        <div class="dm-form">
            <div class="dm-header red">
                <div class="dm-header-left">
                    <i class="fas fa-print"></i>
                    <div>
                        <div class="dm-header-title">Issue Certificate to File Action (KP #20)</div>
                        <div class="dm-header-sub">Printed copy — hand-deliver to the complainant</div>
                    </div>
                </div>
                <div class="dm-header-ref">
                    <div class="dm-header-ref-label">Reference</div>
                    <div class="dm-header-ref-value">${escapeHtml(c.reference_number)}</div>
                </div>
            </div>
            <div class="dm-body">
                <div class="dm-info" style="background:#fff8e1;border-left-color:#f57c00;color:#8a3e00;">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <div class="dm-info-title" style="color:#c62828;">Per KP Handbook / RA 7160</div>
                        <div style="font-size:0.82rem;line-height:1.6;">
                            After the settlement or arbitration agreement was repudiated, the case cannot be mediated again.
                            The complainant must be issued a <b>Certificate to File Action (KP Form #20)</b> so they can
                            file the case in court. This is a <b>printed document</b> — the digital copy is for record only.
                        </div>
                    </div>
                </div>

                ${repudiationBlock}

                <div class="dm-field" style="margin-top:16px;">
                    <label>Issued To <span class="req">*</span></label>
                    <input type="text" id="cfaIssuedTo" value="${escapeHtml(primaryName)}" readonly
                           style="background:#f5f5f5;cursor:not-allowed;">
                    <div class="dm-helper" style="margin-top:6px;">
                        <i class="fas fa-info-circle"></i>
                        <span>The CFA is issued to the <b>complainant</b>. The respondent receives a copy for their records.</span>
                    </div>
                </div>

                <div class="dm-field">
                    <label>Reason for Certification <span class="req">*</span></label>
                    <textarea id="cfaReason" rows="4" placeholder="e.g. The Amicable Settlement (KP Form #16) was repudiated by the respondent on grounds of fraud...">${escapeHtml(defaultReason)}</textarea>
                </div>

                <div class="dm-field">
                    <label>Date of Issuance <span class="req">*</span></label>
                    <input type="date" id="cfaIssuedDate" value="${new Date().toISOString().split('T')[0]}">
                </div>

                <div class="dm-warning" style="margin-top:8px;">
                    <i class="fas fa-print"></i>
                    <div>
                        <b>PRINT & HAND-DELIVER</b><br>
                        After you issue this, the case becomes <b>certificate_issued</b>. You must print the PDF
                        and personally hand-deliver it to the complainant. The Secretary will be notified
                        that printing is required.
                    </div>
                </div>
            </div>
        </div>`,
        width: 720,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-print"></i> Issue CFA (Printed Copy)',
        confirmButtonColor: '#c62828',
        cancelButtonColor: '#6c757d',
        allowOutsideClick: false,
        preConfirm: () => {
            const issuedTo = document.getElementById('cfaIssuedTo').value.trim();
            const reason   = document.getElementById('cfaReason').value.trim();
            const issuedDate = document.getElementById('cfaIssuedDate').value;
            if (!issuedTo)   { Swal.showValidationMessage('Issued To is required.'); return false; }
            if (!reason)     { Swal.showValidationMessage('Reason is required.'); return false; }
            if (!issuedDate) { Swal.showValidationMessage('Date is required.'); return false; }
            return { issued_to: issuedTo, reason, issued_date: issuedDate };
        }
    });

    if (!result.isConfirmed) return;

    Swal.fire({
        title: 'Issuing CFA…',
        html: 'Generating KP Form #20 and preparing for printing…',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    const fd = new FormData();
    fd.append('action', 'captain_issue_certificate_to_file');
    fd.append('complaint_id', complaintId);
    fd.append('issued_to',   result.value.issued_to);
    fd.append('reason',      result.value.reason);
    fd.append('issued_date', result.value.issued_date);

    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const resp = await r.json();
        Swal.close();

        if (resp.success) {
            await Swal.fire({
                icon: 'success',
                title: 'CFA Issued — Ready for Printing',
                html: `
                <div style="text-align:left;font-size:0.9rem;">
                    <p>The Certificate to File Action (KP Form #20) has been issued.</p>
                    <p style="margin-top:8px;font-weight:700;color:#c62828;">
                        Cert No: ${escapeHtml(resp.certificate_no || '—')}
                    </p>
                    <div style="margin-top:12px;padding:12px 14px;background:#e8f5e9;border-left:4px solid #2E7D32;border-radius:8px;font-size:0.82rem;color:#1b5e20;">
                        <b><i class="fas fa-print"></i> Next step: PRINT & HAND-DELIVER</b><br>
                        The Secretary has been notified to print the CFA and deliver it to the complainant.
                    </div>
                    ${resp.pdf_url ? `
                        <a href="#" onclick="event.preventDefault(); window.open('../../${escapeHtml(resp.pdf_url)}','_blank');"
                           style="display:inline-block;margin-top:12px;padding:8px 16px;background:#c62828;color:#fff;border-radius:6px;text-decoration:none;font-size:0.82rem;font-weight:700;">
                            <i class="fas fa-file-pdf"></i> Open KP Form #20 PDF
                        </a>` : ''}
                </div>`,
                confirmButtonText: '<i class="fas fa-check"></i> Done',
                confirmButtonColor: '#2E7D32',
                width: 640
            });
            reloadComplaints();
        } else {
            Swal.fire('Error', resp.message || 'Failed to issue CFA.', 'error');
        }
    } catch (e) {
        Swal.close();
        Swal.fire('Error', 'Network error while issuing CFA.', 'error');
    }
}

// ============================================================
// OPEN THE STORED CFA PDF (KP #20)
// ============================================================
async function openCfaPdf(complaintId, forWhom) {
    if (!complaintId) return;
    Swal.fire({ title: 'Loading CFA…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=get_cfa_pdf&complaint_id=${complaintId}&for=${forWhom || 'complainant'}`);
        const d = await r.json();
        Swal.close();
        if (d.success && d.pdf_url) {
            window.open('../../' + d.pdf_url, '_blank');
        } else {
            Swal.fire('Error', d.message || 'No CFA PDF on file.', 'error');
        }
    } catch (e) {
        Swal.close();
        Swal.fire('Error', 'Network error.', 'error');
    }
}

async function onResultContinue() {
    const settled = document.getElementById('medSettled').value === '1';
    if (settled) goToStep('settle_branch');
    else goToStep('no_settlement');
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
    if (!summary) { alertInlineError('Please describe the settlement terms.'); return; }
    if (!isSessionRecordValid('set')) { alertInlineError('Please fill the "Summary of this mediation hearing" field.'); return; }
    await finalizeMediation({ settled: true, summary, next_action: 'end', sr_prefix: 'set' });
}

function onNotSettledContinue() {
    const summary = document.getElementById('medNoSummary').value.trim();
    if (!summary) { alertInlineError('Please describe what happened in this mediation hearing.'); return; }
    if (!isSessionRecordValid('noset')) { alertInlineError('Please fill the "Summary of this mediation hearing" field.'); return; }

    const nextAction = document.getElementById('medNextAction').value;
    if (nextAction !== 'schedule' && nextAction !== 'end') {
        alertInlineError('Please choose whether to schedule another hearing or to end mediation.');
        return;
    }

    window.__nosetSummary = summary;

    if (nextAction === 'schedule') {
        goToStep('schedule_next');
    } else {
        finalizeMediation({
            settled: false,
            summary: summary,
            reason: document.getElementById('medReason').value,
            next_action: 'end',
            sr_prefix: 'noset'
        }).then((ok) => {
            setTimeout(() => {
                openMediationSessionModal(window.__complaintId, { startStep: 'constitute_pangkat' });
            }, 250);
        });
    }
}

function selectMedNext(val) {
    document.getElementById('medNextAction').value = val;
    const group = document.getElementById('medNextGroup');
    if (group) {
        group.querySelectorAll('.dm-option').forEach(o => o.classList.remove('selected'));
        const sel = group.querySelector(`.dm-option[data-value="${val}"]`);
        if (sel) sel.classList.add('selected');
    }
    if (window.__step === 'no_settlement') {
        if (val === 'schedule') {
            setFooterButtons({ primaryLabel: '<i class="fas fa-calendar-plus"></i> Continue to Schedule', disablePrimary: false });
        } else if (val === 'end') {
            setFooterButtons({ primaryLabel: '<i class="fas fa-balance-scale"></i> Continue to Pangkat', disablePrimary: false, primaryVariant: 'danger' });
        }
    }
}

async function onSaveScheduleNext() {
    const summary = (window.__nosetSummary || document.getElementById('medNoSummary')?.value.trim() || '').trim()
                  || 'Mediation hearing rescheduled.';
    const reason  = document.getElementById('medReason')?.value || 'Mediation hearing rescheduled';
    const date    = document.getElementById('medNextDate').value;
    const time    = document.getElementById('medNextTime').value;
    if (!date) { alertInlineError('Pick a date.'); return; }
    if (!time) { alertInlineError('Pick a time.'); return; }
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=check_scheduling_live&date=${date}&time=${time}`);
        const d = await r.json();
        if (d.success && !d.allowed) { alertInlineError(d.reason); return; }
    } catch (e) {}
    await finalizeMediation({ settled: false, summary, reason, next_action: 'schedule', next_date: date, next_time: time, sr_prefix: 'noset' });
}

function initJustifiedSchedulingValidation() {
    const dEl   = document.getElementById('justDate');
    const tEl   = document.getElementById('justTime');
    const warn  = document.getElementById('justConflict');
    const avail = document.getElementById('justAvailable');
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
                else {
                    warn.querySelector('div').innerHTML = `<b>Not allowed:</b> ${escapeHtml(d.reason)}`;
                    warn.classList.add('show'); if (avail) avail.classList.remove('show');
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
                warn.classList.add('show'); if (avail) avail.classList.remove('show');
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

async function onSaveScheduleJustified() {
    clearInlineError();
    const date    = document.getElementById('justDate').value;
    const time    = document.getElementById('justTime').value;
    const remarks = (document.getElementById('justRemarks')?.value || '').trim();

    if (!date) { alertInlineError('Pick a date for the new hearing.'); return; }
    if (!time) { alertInlineError('Pick a time for the new hearing.'); return; }

    // Live check
    try {
        const r = await fetch(`../admin_complaint_ajax.php?action=check_scheduling_live&date=${date}&time=${time}`);
        const d = await r.json();
        if (d.success && !d.allowed) { alertInlineError(d.reason); return; }
    } catch (e) { /* fall through */ }

    // Save attendance snapshot (already saved, but re-affirm for safety)
    showSessionProgress('Scheduling next mediation hearing…', 15);

    const attPayload = buildAttendancePayload();

    const fd = new FormData();
    fd.append('action', 'captain_summon_and_schedule');
    fd.append('complaint_id', window.__complaintId);
    fd.append('hearing_date', date);
    fd.append('hearing_time', time);
    fd.append('hearing_location', 'Barangay Hall');
    fd.append('schedule_reason', 'Rescheduled after justified absence decision');
    fd.append('notes', remarks);
    if (attPayload.length) fd.append('attendance', JSON.stringify(attPayload));

    let d;
    try {
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        showSessionProgress('Scheduling next mediation hearing…', 60);
        d = await r.json();
    } catch (e) {
        hideSessionProgress();
        alertInlineError('Network error while scheduling.');
        return;
    }

    if (!d.success) {
        hideSessionProgress();
        alertInlineError(d.message || 'Failed to schedule next hearing.');
        return;
    }

    showSessionProgress('Next hearing scheduled.', 100);

    // Log the outcome against the previous hearing too
    try {
        const fdLog = new FormData();
        fdLog.append('action', 'add_complaint_note');
        fdLog.append('complaint_id', window.__complaintId);
        fdLog.append('note', `[Justified Absence] Next mediation hearing scheduled for ${date} ${time}. ${remarks}`);
        await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fdLog });
    } catch (e) { /* silent */ }

    window.__sessionSaved = true;
    hideSessionProgress();

    Swal.close();
    Swal.fire({
        icon: 'success',
        title: 'Next Mediation Hearing Scheduled',
        html: `<div style="text-align:left;font-size:0.9rem;">
            <p><b>Mediation Hearing #${d.session_number || ''}</b> set for:</p>
            <p style="font-weight:700;color:#2E7D32;font-size:1rem;">${escapeHtml(d.hearing_date || date)} at ${escapeHtml(d.hearing_time || time)}</p>
            <p style="font-size:0.83rem;color:#666;margin-top:6px;">
                KP Form #8 (Notice of Hearing) and KP Form #9 (Summons) have been issued and saved on disk.
            </p>
        </div>`,
        confirmButtonText: '<i class="fas fa-check"></i> Done',
        confirmButtonColor: '#2E7D32',
        width: 620,
        allowOutsideClick: false
    }).then(() => reloadComplaints());
}

async function onSaveConstitutionMeeting() {
    const date = document.getElementById('constitDate').value;
    const time = document.getElementById('constitTime').value;
    if (!date) { alertInlineError('Pick a date for the constitution meeting.'); return; }
    if (!time) { alertInlineError('Pick a time for the constitution meeting.'); return; }

    if (window.__caseStatus !== 'failed_mediation') {
        // Keep spinner in footer, no Swal.fire
        const primBtn = document.getElementById('sesBtnPrimary');
        const oldLabel = primBtn ? primBtn.innerHTML : '';
        if (primBtn) { primBtn.disabled = true; primBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Ending…'; }

        const summary = window.__nosetSummary || (document.getElementById('medNoSummary')?.value.trim() || '');
        const reason  = document.getElementById('medReason')?.value || '';

        const sr = collectSessionRecord('noset');
        const fdEnd = new FormData();
        fdEnd.append('action', 'captain_mediate');
        fdEnd.append('complaint_id', window.__complaintId);
        fdEnd.append('settled', '0');
        fdEnd.append('summary', summary || 'Mediation ended by Captain — proceeding to Pangkat.');
        fdEnd.append('reason', reason);
        fdEnd.append('next_action', 'end');
        fdEnd.append('session_record', JSON.stringify(sr));

        const attPayload = buildAttendancePayload();
        if (attPayload.length) fdEnd.append('attendance', JSON.stringify(attPayload));

        try {
            const rEnd = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fdEnd });
            const dEnd = await rEnd.json();
            if (!dEnd.success) {
                if (primBtn) { primBtn.disabled = false; primBtn.innerHTML = oldLabel; }
                alertInlineError(dEnd.message || 'Could not end mediation.');
                return;
            }
            window.__caseStatus = 'failed_mediation';
        } catch (e) {
            if (primBtn) { primBtn.disabled = false; primBtn.innerHTML = oldLabel; }
            alertInlineError('Network error while ending mediation.');
            return;
        }
    }

    const primBtn2 = document.getElementById('sesBtnPrimary');
    const oldLabel2 = primBtn2 ? primBtn2.innerHTML : '';
    if (primBtn2) { primBtn2.disabled = true; primBtn2.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Issuing…'; }

    try {
        const fd = new FormData();
        fd.append('action', 'schedule_pangkat_constitution');
        fd.append('complaint_id', window.__complaintId);
        fd.append('constitution_date', date);
        fd.append('constitution_time', time);
        const r = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (!d.success) {
            if (primBtn2) { primBtn2.disabled = false; primBtn2.innerHTML = oldLabel2; }
            alertInlineError(d.message || 'Failed.');
            return;
        }
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

        Swal.close();
        await Swal.fire({
            icon: 'success', title: 'KP Form #10 Issued',
            html: `<div style="text-align:left;font-size:0.9rem;"><p>Constitution meeting scheduled for:</p><p style="font-weight:700;color:#4a148c;font-size:1rem;">${escapeHtml(d.constitution_date)} at ${escapeHtml(d.constitution_time)}</p><div class="notice-panel"><div class="notice-panel-title"><i class="fas fa-users-cog"></i> KP Form #10 — Notices Issued (${notices.length})</div>${list}</div>${printBanner}<p style="font-size:0.82rem;color:#666;margin-top:14px;"><b>Reminder:</b> You may constitute the Pangkat <b>on or after the day of the meeting</b>. Reopen this case then to pick the 3 Lupon members.</p></div>`,
            confirmButtonText: '<i class="fas fa-check"></i> Done', confirmButtonColor: '#2E7D32', width: 720, allowOutsideClick: false
        }).then(() => reloadComplaints());
    } catch (e) {
        if (primBtn2) { primBtn2.disabled = false; primBtn2.innerHTML = oldLabel2; }
        alertInlineError('Network error.');
    }
}

async function onSaveConstitutePangkat() {
    if (window.__wheelSelected.length !== 3) { alertInlineError('Select exactly 3 Pangkat members.'); return; }
    const selectedBy = document.getElementById('pangkatSelectedBy').value || 'parties';

    const primBtn = document.getElementById('sesBtnPrimary');
    const oldLabel = primBtn ? primBtn.innerHTML : '';
    if (primBtn) { primBtn.disabled = true; primBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Constituting…'; }

    try {
        const fdP = new FormData();
        fdP.append('action', 'constitute_pangkat');
        fdP.append('complaint_id', window.__complaintId);
        fdP.append('selected_by', selectedBy);
        fdP.append('members', JSON.stringify(window.__wheelSelected.map(mid => ({ member_id: Number(mid) }))));
        const rP = await fetch('../admin_complaint_ajax.php', { method: 'POST', body: fdP });
        const dP = await rP.json();
        if (!dP.success) {
            if (primBtn) { primBtn.disabled = false; primBtn.innerHTML = oldLabel; }
            alertInlineError(dP.message || 'Constitution failed.');
            return;
        }
        window.__sessionSaved = true;
        const firstMember = (dP.members || []).find(m => m.is_first);
        const firstName = firstMember ? firstMember.name : 'the first-selected member';

        Swal.close();
        Swal.fire({
            icon: 'success', title: 'Pangkat Constituted',
            html: `<div style="text-align:left;font-size:0.9rem;"><p>Members selected:</p><ul>${(dP.members || []).map(m => `<li>${escapeHtml(m.name)}${m.is_first ? ' <span style="color:#2E7D32;font-weight:700;">(first-selected)</span>' : ''} — KP Form #11 issued</li>`).join('')}</ul><div style="margin-top:12px;padding:10px 12px;background:#e8f5e9;border-left:4px solid #2E7D32;border-radius:8px;font-size:0.82rem;color:#1b5e20;"><b>Next steps:</b><br>• <b>${escapeHtml(firstName)}</b> will assign Chairperson / Secretary / Member in the Lupon Portal<br>• The <b>Chairperson</b> will then schedule the conciliation hearing<br>• You and the Secretary will be notified</div></div>`,
            confirmButtonColor: '#2E7D32', width: 620
        }).then(() => reloadComplaints());
    } catch (e) {
        if (primBtn) { primBtn.disabled = false; primBtn.innerHTML = oldLabel; }
        alertInlineError('Network error.');
    }
}

async function finalizeMediation(payload) {
    clearInlineError();

    const pstmtHost = document.getElementById('step-result-pstmt-host');
    if (pstmtHost && pstmtHost.innerHTML.trim()) {
        const v = validatePartyStatements('res');
        if (!v.ok) { alertInlineError(v.message); return false; }
    }

    const primBtn = document.getElementById('sesBtnPrimary');
    const oldLabel = primBtn ? primBtn.innerHTML : '';
    if (primBtn) { primBtn.disabled = true; primBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…'; }

    try {
        const uploadRes = await uploadAllPendingStatements('res', window.__complaintId, window.__hearingId);
        const failedUploads = Object.entries(uploadRes)
            .filter(([_, r]) => !r.success)
            .map(([p, r]) => p + ': ' + (r.message || 'unknown'));
        if (failedUploads.length) {
            if (primBtn) { primBtn.disabled = false; primBtn.innerHTML = oldLabel; }
            alertInlineError('Could not upload: ' + failedUploads.join(', '));
            return false;
        }

        const sr = collectSessionRecord(payload.sr_prefix || 'noset');
        if (!sr.summary && (payload.summary || window.__nosetSummary)) {
            sr.summary = payload.summary || window.__nosetSummary || '';
        }
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
        if (!d.success) {
            if (primBtn) { primBtn.disabled = false; primBtn.innerHTML = oldLabel; }
            alertInlineError(d.message || 'Failed.');
            return false;
        }
        window.__sessionSaved = true;

        const kp16Url = (d.kp16 && d.kp16.ok) ? d.kp16.pdf_path : null;
        const wasSettled = !!payload.settled && !!d.final;
        const complaintId = window.__complaintId;

        // End the wizard cleanly, then show the summary modal.
        Swal.close();
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
    } catch (e) {
        if (primBtn) { primBtn.disabled = false; primBtn.innerHTML = oldLabel; }
        alertInlineError('Network error.');
    }
}

function attemptSessionClose() {
    if (window.__step === 'schedule_justified' && !window.__sessionSaved) {
        Swal.fire({
            icon: 'warning',
            title: 'Leave without scheduling?',
            html: `<div style="text-align:left;font-size:0.9rem;">
                <p>The absences have been recorded as <b>justified</b>, but no next mediation hearing has been scheduled yet.</p>
                <p style="margin-top:8px;">You will need to come back and schedule the next hearing <b>before the 15-day mediation window expires</b>, otherwise the case will be auto-forwarded to the Pangkat.</p>
            </div>`,
            showCancelButton: true,
            confirmButtonText: 'Yes, leave it for later',
            confirmButtonColor: '#e65100',
            cancelButtonText: 'Stay here',
            cancelButtonColor: '#2E7D32'
        }).then(r => {
            if (r.isConfirmed) {
                window.__sessionSaved = true;
                Swal.close();
                reloadComplaints();
            }
        });
        return;
    }

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
window.__wheelPoolCandidates = [];   // IDs checked for inclusion
window.__wheelPoolConfirmed  = false;

/* ── Render the pre-selection checkbox list ── */
function renderWheelPreSelectList() {
    const list = document.getElementById('wheelPreSelectList');
    if (!list) return;

    if (!window.__allLupon.length) {
        list.innerHTML = '<div style="padding:14px;color:#c62828;font-size:0.85rem;">' +
                         'No active Lupon members found.</div>';
        return;
    }

    const checkedSet = new Set(window.__wheelPoolCandidates.map(Number));
    list.innerHTML = window.__allLupon.map(m => {
        const checked = checkedSet.has(Number(m.id));
        return `<label class="manual-pangkat-row ${checked ? 'checked' : ''}">
            <input type="checkbox" class="wheel-pool-cb" value="${m.id}"
                   ${checked ? 'checked' : ''}
                   onchange="onWheelPoolToggle(this)"
                   style="width:16px;height:16px;cursor:pointer;">
            <span style="flex:1;">${escapeHtml(m.full_name)}</span>
        </label>`;
    }).join('');

    updateWheelPreSelectSummary();
}

function onWheelPoolToggle(cb) {
    const val = Number(cb.value);
    if (cb.checked) {
        if (!window.__wheelPoolCandidates.includes(val)) {
            window.__wheelPoolCandidates.push(val);
        }
    } else {
        window.__wheelPoolCandidates = window.__wheelPoolCandidates.filter(x => x !== val);
    }
    renderWheelPreSelectList();
}

function updateWheelPreSelectSummary() {
    const countEl = document.getElementById('wheelPreSelectCount');
    const hintEl  = document.getElementById('wheelPreSelectHint');
    const btn     = document.getElementById('wheelConfirmPoolBtn');
    const n       = window.__wheelPoolCandidates.length;

    if (countEl) countEl.textContent = String(n);

    if (n < 3) {
        if (hintEl) {
            hintEl.textContent = 'Check at least 3 members to enable the wheel.';
            hintEl.style.color = '#c62828';
        }
        if (btn) btn.disabled = true;
    } else {
        if (hintEl) {
            hintEl.textContent = `${n} member${n > 1 ? 's' : ''} in the draw.`;
            hintEl.style.color = '#2E7D32';
        }
        if (btn) btn.disabled = false;
    }
}

/* ── Confirm the pool and switch to the wheel ── */
function confirmWheelPool() {
    if (window.__wheelPoolCandidates.length < 3) {
        showPickBanner('Select at least 3 members.', 'warn');
        return;
    }
    window.__wheelPoolConfirmed = true;

    const preWrap  = document.getElementById('wheelPreSelectWrap');
    const spinWrap = document.getElementById('wheelSpinWrap');
    if (preWrap)  preWrap.style.display  = 'none';
    if (spinWrap) spinWrap.style.display = 'block';

    renderWheelDisc();
    const btn = document.getElementById('spinBtn');
    if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-dice"></i> Spin to Pick Member #1';
    }
    const res = document.getElementById('wheelResult');
    if (res) res.textContent = 'Ready — spin to pick member #1';
}

/* ── Go back to the pre-selection step ── */
function resetWheelPool() {
    window.__wheelPoolConfirmed = false;
    window.__wheelSelected = [];
    window.__wheelAngle = 0;

    const preWrap  = document.getElementById('wheelPreSelectWrap');
    const spinWrap = document.getElementById('wheelSpinWrap');
    if (preWrap)  preWrap.style.display  = 'block';
    if (spinWrap) spinWrap.style.display = 'none';

    renderWheelPreSelectList();
    updatePickedList();
}

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

    // Reset to pre-selection view
    window.__wheelPoolCandidates = [];
    window.__wheelPoolConfirmed  = false;
    window.__wheelSelected = [];
    window.__wheelAngle    = 0;

    const preWrap  = document.getElementById('wheelPreSelectWrap');
    const spinWrap = document.getElementById('wheelSpinWrap');
    if (preWrap)  preWrap.style.display  = 'block';
    if (spinWrap) spinWrap.style.display = 'none';

    renderWheelPreSelectList();
    updatePickedList();

}else {
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
   const pool = window.__allLupon.filter(m =>
    window.__wheelPoolCandidates.includes(Number(m.id))
);
const members = pool.filter(m => !window.__wheelSelected.includes(m.id));
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
    if (!window.__wheelPoolConfirmed) {
        showPickBanner('Confirm the draw pool first.', 'warn');
        return;
    }
const pool = window.__allLupon.filter(m =>
    window.__wheelPoolCandidates.includes(Number(m.id))
);
const members = pool.filter(m => !window.__wheelSelected.includes(m.id));
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
// SCHEDULE MEDIATION MODAL (first-time scheduling)
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

async function renderComplaintsView() {
    document.getElementById('dashboardBody').innerHTML = `
        <div class="unified-header">
            <div class="unified-title"><i class="fas fa-gavel"></i> Complaint Management</div>
            <div class="unified-sub">All complaints requiring your action — review, mediate, sign.</div>

            <!-- ═══════ COLLAPSIBLE FILTERS ═══════ -->
            <div class="complaint-filter-accordion">
                <div class="complaint-filter-accordion-header"
                     onclick="toggleComplaintFiltersBar()"
                     id="complaintFilterHeader">
                    <i class="fas fa-filter cfa-icon"></i>
                    <div class="cfa-title">Filters</div>
                    <div class="cfa-summary" id="complaintFiltersSummary">
                        ${complaintFiltersSummaryHtml()}
                    </div>
                    <i id="complaintFiltersChevron"
                       class="fas fa-chevron-down cfa-chevron"
                       style="transform:${complaintFiltersExpanded ? 'rotate(180deg)' : 'rotate(0)'};"></i>
                </div>
                <div class="complaint-filter-accordion-body"
                     id="complaintFiltersWrapper"
                     style="display:${complaintFiltersExpanded ? 'block' : 'none'};
                            max-height:${complaintFiltersExpanded ? 'none' : '0'};
                            opacity:${complaintFiltersExpanded ? '1' : '0'};
                            margin-top:${complaintFiltersExpanded ? '10px' : '0'};">

                    <div class="unified-chips">
                        <button class="u-chip active" onclick="switchComplaintView('pending')" id="chip-pending"><i class="fas fa-inbox"></i> Pending Action <span class="u-count" id="count-pending">0</span></button>
                        <button class="u-chip" onclick="switchComplaintView('mediation')" id="chip-mediation"><i class="fas fa-gavel"></i> Mediation <span class="u-count" id="count-mediation">0</span></button>
                        <button class="u-chip" onclick="switchComplaintView('pangkat')" id="chip-pangkat"><i class="fas fa-balance-scale"></i> For Pangkat <span class="u-count" id="count-pangkat">0</span></button>
                        <button class="u-chip" onclick="switchComplaintView('repudiated')" id="chip-repudiated" style="border-color:#c62828;color:#c62828;"><i class="fas fa-file-signature"></i> Repudiated <span class="u-count" id="count-repudiated" style="background:#c62828;color:#fff;">0</span></button>
                        <button class="u-chip" onclick="switchComplaintView('unresolved')" id="chip-unresolved" style="border-color:#8e24aa;color:#8e24aa;"><i class="fas fa-file-certificate"></i> Unresolved <span class="u-count" id="count-unresolved" style="background:#8e24aa;color:#fff;">0</span></button>
                        <button class="u-chip" onclick="switchComplaintView('referrals')" id="chip-referrals"><i class="fas fa-file-signature"></i> Referrals <span class="u-count" id="count-referrals">0</span></button>
                        <button class="u-chip" onclick="switchComplaintView('handled')" id="chip-handled"><i class="fas fa-check-double"></i> Handled <span class="u-count" id="count-handled">0</span></button>
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
                </div>
            </div>
            <!-- ═══════ /COLLAPSIBLE FILTERS ═══════ -->
        </div>
        <div id="complaintsList"><div style="text-align:center;padding:40px;color:#999;"><i class="fas fa-spinner fa-spin fa-2x"></i><p style="margin-top:10px;">Loading…</p></div></div>
    `;
    await loadAllComplaints();
    switchComplaintView('pending');
}

// ============================================================
// VIEW COMPLAINT
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
                
                ${isPangkatReady ? `<button class="cd-btn schedule" onclick="Swal.close(); openMediationSessionModal(${c.id});"><i class="fas fa-calendar-plus"></i> Set Constitution Meeting</button>` : ''}
                ${isConstitutionScheduled ? `<button class="cd-btn primary" onclick="Swal.close(); openMediationSessionModal(${c.id});"><i class="fas fa-users-cog"></i> Continue Constitution</button>` : ''}
                ${(c.status === 'amicable_repudiated'
    || c.status === 'arbitration_repudiated'
    || c.status === 'failed_conciliation_final'
    || c.status === 'failed_mediation') ? `
    <button class="cd-btn" style="background:#c62828;color:#fff;" onclick="Swal.close(); openIssueCfaModal(${c.id}, 'complainant');">
        <i class="fas fa-print"></i> Issue CFA to Complainant (KP #20)
    </button>` : ''}
${c.status === 'unresolved' ? `
    <button class="cd-btn" style="background:#8e24aa;color:#fff;" onclick="openCfaPdf(${c.id}, 'complainant');">
        <i class="fas fa-file-pdf"></i> View CFA PDF (KP #20)
    </button>` : ''}
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

    if (view === 'dashboard') {
        document.getElementById('dashboardBody').innerHTML = renderDashboard();
        displayCurrentDate();
        loadAnnouncements();
        initDashboardWidgets();
    } else if (view === 'complaints') {
        document.getElementById('dashboardBody').innerHTML = '';
        renderComplaintsView();
    } else if (view === 'household') {
        document.getElementById('dashboardBody').innerHTML = '';
        if (typeof window.__renderHouseholdDashboard === 'function') {
            window.__renderHouseholdDashboard();
        }
    }else if (view === 'reports') {
    document.getElementById('dashboardBody').innerHTML =
        window.briteRenderCaptainReportsView();

    setTimeout(function () {
        if (typeof window.briteInitCaptainReports === 'function') {
            window.briteInitCaptainReports();
        }
    }, 50);
}
}
document.querySelectorAll('.nav-item[data-view]').forEach(item => { item.addEventListener('click', () => navigateTo(item.getAttribute('data-view'))); });
function displayCurrentDate() { const el = document.getElementById('currentDate'); if (el) el.innerHTML = new Date().toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }); }
navigateTo('dashboard');

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

<script src="captain_reports.js"></script>
</body>
</html>