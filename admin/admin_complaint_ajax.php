<?php
// admin/admin_complaint_ajax.php — ADMIN SIDE
// ============================================================
//  FLOW (Normal Complaints) — Katarungang Pambarangay (RA 7160)
// ============================================================
//  1. Resident files complaint             → pending_review
//  2. Secretary reviews & validates        → pending_captain_action
//  3. Captain schedules 1st mediation:
//       • KP Form #8 (complainant) + #9 (respondent) issued
//                                       → for_mediation
//  4. Captain starts mediation session
//       • No-show → appearance date → KP #18/#19 issued
//       • 5-DAY EXPLAIN WINDOW lives on complaint_hearings
//       • Justified/Unjustified decisions:
//           – Complainant Unjustified → KP #23 → dismissed
//           – Respondent Unjustified  → KP #24 → failed_mediation
//           – All Justified           → reschedule + warning
//       • Settled → KP #16 (mediation mode) auto-issued
//  5. Captain constitutes Pangkat:
//       • Step A — Set constitution meeting → KP Form #10 issued
//       • Step B — After meeting date, pick 3 Lupon members
//           – roles = 'pending'
//           – KP Form #11 issued to each chosen member
//           – status: pangkat_constituted
//  6. First-selected Pangkat member assigns positions
//  7. Chairperson schedules conciliation
//       – KP Form #12 (Notice of Hearing — Conciliation) issued to BOTH parties
//       – OPTIONAL: KP Form #13 (Subpoena) for witnesses
//       – conciliation_deadline = hearing_date + 15 days
//       – conciliation_hard_deadline = first_hearing_date + 15 (or 30 if extended)
//       – status: pangkat_scheduled
//  8. Chairperson STARTS the conciliation hearing
//       • All Pangkat members + Secretary notified
//       • NO LOCK — all members may leave/return freely
//  9. Secretary records attendance
//       • All present → continue
//       • No-show → Chairperson must set appearance date (KP #18/#19)
//         ** 5-DAY EXPLAIN WINDOW on the hearing **
//  10. Chairperson sets appearance date (KP #18/#19 auto-issued)
//       • Conciliation hearing is PAUSED until appearance resolved
//       • After 5 days with no explanation → AUTO-UNJUSTIFIED (cron)
//  11. Chairperson decides Justified/Unjustified
//       • Complainant Unjustified → KP #23 → dismissed/barred
//       • Respondent Unjustified  → KP #24 → proceed to Certification
//       • All Justified           → reschedule conciliation
//  12. Pangkat hears case → outcome
//       • Settled    → KP #16 (conciliation mode) issued → settled_pending
//       • No Settled → reason + optional reschedule OR extend +15 days
//       • After 30 days w/o settlement → certificate_issued (physical only)
//  13. settled_pending → 10-day repudiation countdown → settled (executory)
// ============================================================

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'fatal'   => true,
            'message' => $err['message'],
            'file'    => basename($err['file']),
            'line'    => $err['line'],
        ]);
    }
});

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

$pushPath = __DIR__ . '/push_send.php';
if (file_exists($pushPath)) {
    require_once $pushPath;
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$admin_id = $_SESSION['user_id'];
$action   = $_POST['action'] ?? $_GET['action'] ?? '';

$s = mysqli_prepare($conn, "SELECT admin_role, full_name FROM admin WHERE id = ?");
mysqli_stmt_bind_param($s, "i", $admin_id);
mysqli_stmt_execute($s);
$adminRow  = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
$adminRole = $adminRow['admin_role'] ?? '';
$adminName = $adminRow['full_name']  ?? '';

define('MEDIATION_DAYS',     15);
define('CONCILIATION_DAYS',  15);
define('EXPLAIN_WINDOW_DAYS', 5);
define('MIN_LEAD_HOURS',     2);
define('OPEN_TIME',          '08:00');
define('CLOSE_TIME',         '18:30');

if (!defined('KP_NOTICE_INTERNAL')) define('KP_NOTICE_INTERNAL', true);
require_once __DIR__ . '/generate_kp_notice.php';

/* ---------------- Helpers ---------------- */

/**
 * Send an in-app notification.
 *
 * $document_path — relative path to the associated PDF/document (optional).
 *                  When provided, the notification stores a direct link.
 */
function sendNotification($conn, $user_type, $user_id, $complaint_id, $title, $message, $type = 'info', $document_path = null) {
    $resident_id    = ($user_type === 'resident') ? $user_id : null;
    $reference_id   = $complaint_id ?: null;
    $reference_type = $complaint_id ? 'complaint' : null;

    $sql = "INSERT INTO notifications
              (user_type, user_id, resident_id, type, title, message, reference_id, reference_type, document_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $s = @mysqli_prepare($conn, $sql);
    if (!$s) { error_log('sendNotification prepare failed: ' . mysqli_error($conn)); return false; }
    mysqli_stmt_bind_param($s, "siisssiss",
        $user_type, $user_id, $resident_id,
        $type, $title, $message,
        $reference_id, $reference_type,
        $document_path);
    $ok = mysqli_stmt_execute($s);
    if (!$ok) error_log('sendNotification execute failed: ' . mysqli_stmt_error($s));
    mysqli_stmt_close($s);
    return $ok;
}

function getComplaintCreator($conn, $complaint_id) {
    $s = mysqli_prepare($conn, "SELECT created_by FROM complaints WHERE id = ?");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    return $row ? (int)$row['created_by'] : null;
}

function getComplaintMeta($conn, $complaint_id) {
    $s = mysqli_prepare($conn, "SELECT * FROM complaints WHERE id = ?");
    if (!$s) { error_log('getComplaintMeta prepare failed: ' . mysqli_error($conn)); return null; }
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    if (!$row) return null;

    // Pull the latest scheduled mediation hearing from the hearings table.
    // Fall back to the complaint column only when no scheduled hearing exists.
    $hearing_date = null;
    $hearing_time = null;
    $hs = mysqli_prepare($conn,
        "SELECT hearing_date, hearing_time
           FROM complaint_hearings
          WHERE complaint_id = ?
            AND session_type = 'mediation'
            AND status = 'scheduled'
          ORDER BY hearing_date ASC, hearing_time ASC, id ASC
          LIMIT 1");
    if ($hs) {
        mysqli_stmt_bind_param($hs, "i", $complaint_id);
        mysqli_stmt_execute($hs);
        $hrow = mysqli_fetch_assoc(mysqli_stmt_get_result($hs));
        mysqli_stmt_close($hs);
        if ($hrow) {
            $hearing_date = $hrow['hearing_date'];
            $hearing_time = $hrow['hearing_time'];
        }
    }
    if ($hearing_date === null) $hearing_date = $row['hearing_date'] ?? null;
    if ($hearing_time === null) $hearing_time = $row['hearing_time'] ?? null;

    return [
        'reference_number'              => $row['reference_number']  ?? null,
        'title'                         => $row['title']             ?? null,
        'status'                        => $row['status']            ?? null,
        'mediation_started_at'          => $row['mediation_started_at'] ?? null,
        'mediation_deadline'            => $row['mediation_deadline']   ?? null,
        'mediation_session_count'       => $row['mediation_session_count'] ?? 0,
        'conciliation_deadline'         => $row['conciliation_deadline'] ?? null,
        'concil_absence_waiting_since'  => $row['concil_absence_waiting_since']  ?? null,
        'concil_absence_waiting_hearing'=> $row['concil_absence_waiting_hearing'] ?? null,
        'concil_absence_appearance_at'  => $row['concil_absence_appearance_at']   ?? null,
        'concil_hearing_started_at'     => $row['concil_hearing_started_at']      ?? null,
        'hearing_date'                  => $hearing_date,
        'hearing_time'                  => $hearing_time,
    ];
}

function logUpdate($conn, $complaint_id, $type, $prev, $new, $notes, $admin_id, $role) {
    $s = mysqli_prepare($conn, "INSERT INTO complaint_updates
        (complaint_id, update_type, previous_status, new_status, notes, updated_by, updated_by_role)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($s, "issssis", $complaint_id, $type, $prev, $new, $notes, $admin_id, $role);
    return mysqli_stmt_execute($s);
}

function mediationDeadlineEndTs($deadline) {
    if (!$deadline) return null;
    $ts = strtotime($deadline);
    if (!$ts) return null;
    return strtotime(date('Y-m-d', $ts) . ' 23:59:59');
}

function computeMediationDaysRemaining($deadline) {
    $end = mediationDeadlineEndTs($deadline);
    if ($end === null) return null;
    $seconds = $end - time();
    if ($seconds <= 0) return 0;
    return (int)ceil($seconds / 86400);
}

function computeDaysRemaining($deadline) {
    if (!$deadline) return null;
    $end = strtotime(date('Y-m-d', strtotime($deadline)) . ' 23:59:59');
    if ($end === false) return null;
    $seconds = $end - time();
    if ($seconds <= 0) return 0;
    return (int)ceil($seconds / 86400);
}

function checkWithinMediationDeadline($deadline, $date) {
    $end = mediationDeadlineEndTs($deadline);
    if ($end === null || !$date) return null;
    $d = strtotime($date . ' 00:00:00');
    if ($d !== false && $d > $end) {
        return 'The next session must be on or before the end of the 15-day mediation period ('
             . date('F j, Y', $end) . ').';
    }
    return null;
}

function getSchedulingLimits($date) {
    if (!$date) return [null, null, 'Invalid date.'];
    $today = date('Y-m-d');
    $now   = time();
    $minLead = $now + (MIN_LEAD_HOURS * 3600);
    $openTs  = strtotime($date . ' ' . OPEN_TIME);
    $closeTs = strtotime($date . ' ' . CLOSE_TIME);
    if ($date < $today) return [null, null, 'Past dates are not allowed.'];
    if ($date === $today) {
        $earliestTs = max($minLead, $openTs);
        if ($earliestTs > $closeTs) return [null, null, 'No valid slots left today.'];
        return [date('H:i', $earliestTs), date('H:i', $closeTs), 'At least ' . MIN_LEAD_HOURS . ' hour(s) from now.'];
    }
    return [OPEN_TIME, CLOSE_TIME, 'Between ' . OPEN_TIME . ' and ' . CLOSE_TIME . '.'];
}

function checkScheduleConflict($conn, $date, $time, $admin_id, $duration_minutes = 60, $exclude_hearing_id = 0) {
    if (!$date || !$time) return ['conflict'=>false];
    $startTs = strtotime("$date $time");
    $today = date('Y-m-d');
    if ($date < $today) return ['conflict'=>true,'reason'=>"The date must be today or later.",'type'=>'past_date'];
    $minLeadTs = time() + (MIN_LEAD_HOURS * 3600);
    if ($startTs < $minLeadTs) return ['conflict'=>true,'reason'=>"Must be at least " . MIN_LEAD_HOURS . " hours from now.",'type'=>'min_lead'];
    $hour   = (int)date('G', $startTs);
    $minute = (int)date('i', $startTs);
    $totalMinutes = ($hour * 60) + $minute;
    if ($totalMinutes < 480 || $totalMinutes > 1110) return ['conflict'=>true,'reason'=>"Only between 8:00 AM and 6:30 PM.",'type'=>'outside_hours'];

    $endTs = $startTs + ($duration_minutes * 60);
    $sql = "SELECT h.id, h.hearing_date, h.hearing_time, h.location,
                   c.reference_number, c.title
            FROM complaint_hearings h
            JOIN complaints c ON h.complaint_id = c.id
            WHERE h.hearing_date = ? AND h.status = 'scheduled' AND h.conducted_by = ? AND h.id != ?";
    $s = mysqli_prepare($conn, $sql);
    if (!$s) return ['conflict'=>false];
    mysqli_stmt_bind_param($s, "sii", $date, $admin_id, $exclude_hearing_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    while ($row = mysqli_fetch_assoc($r)) {
        $existingStart = strtotime($row['hearing_date'] . ' ' . $row['hearing_time']);
        $existingEnd   = $existingStart + ($duration_minutes * 60);
        if ($startTs < $existingEnd && $endTs > $existingStart) {
            mysqli_stmt_close($s);
            return ['conflict'=>true,'reason'=>"Already booked at " . date('g:i A', $existingStart),'type'=>'booked'];
        }
    }
    mysqli_stmt_close($s);
    return ['conflict' => false];
}

function getLatestMediationHearingId($conn, $complaint_id) {
    $hs = mysqli_prepare($conn,
        "SELECT id FROM complaint_hearings
         WHERE complaint_id = ? AND session_type = 'mediation'
         ORDER BY session_number DESC, id DESC LIMIT 1");
    if (!$hs) return 0;
    mysqli_stmt_bind_param($hs, "i", $complaint_id);
    mysqli_stmt_execute($hs);
    $h = mysqli_fetch_assoc(mysqli_stmt_get_result($hs));
    mysqli_stmt_close($hs);
    return (int)($h['id'] ?? 0);
}

function getLatestConciliationHearing($conn, $complaint_id) {
    $hs = mysqli_prepare($conn,
        "SELECT id, hearing_date, hearing_time, location, status, session_number,
                explain_window_started_at, explain_window_deadline,
                explain_window_resolved_at, explain_window_auto_closed
           FROM complaint_hearings
          WHERE complaint_id = ? AND session_type = 'conciliation'
          ORDER BY session_number DESC, id DESC LIMIT 1");
    if (!$hs) return null;
    mysqli_stmt_bind_param($hs, "i", $complaint_id);
    mysqli_stmt_execute($hs);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($hs));
    mysqli_stmt_close($hs);
    return $row ?: null;
}

function latestSessionAbsentRoles($conn, $complaint_id) {
    $hid = getLatestMediationHearingId($conn, $complaint_id);
    if (!$hid) return [];

    $s = mysqli_prepare($conn,
        "SELECT DISTINCT p.party_type
         FROM complaint_parties p
         JOIN complaint_attendance a ON a.party_id = p.id AND a.hearing_id = ?
         WHERE p.complaint_id = ? AND a.status = 'no_show'");
    if (!$s) return [];
    mysqli_stmt_bind_param($s, "ii", $hid, $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $roles = [];
    while ($row = mysqli_fetch_assoc($r)) $roles[] = $row['party_type'];
    mysqli_stmt_close($s);
    return $roles;
}

function notifyAdminsByRole($conn, $roles, $complaint_id, $title, $msg, $type = 'info', $excludeAdminId = null, $document_path = null) {
    // If no explicit exclude passed, use the currently logged-in admin
    if ($excludeAdminId === null && isset($GLOBALS['admin_id'])) {
        $excludeAdminId = (int)$GLOBALS['admin_id'];
    }
    $excludeAdminId = (int)$excludeAdminId;

    $allowed = array_values(array_intersect($roles, ['captain', 'secretary', 'lupon']));
    if (!$allowed) return;
    $in = "'" . implode("','", $allowed) . "'";
    $q = mysqli_query($conn, "SELECT id, admin_role FROM admin WHERE admin_role IN ($in) AND is_active = 1");
    if (!$q) return;
    while ($a = mysqli_fetch_assoc($q)) {
        $aid = (int)$a['id'];
        // Skip the admin who performed the action
        if ($excludeAdminId > 0 && $aid === $excludeAdminId) continue;

        // ✅ Pass document_path so the notification stores the PDF link
        sendNotification($conn, 'admin', $aid, $complaint_id, $title, $msg, $type, $document_path);
        if (function_exists('pushToUser')) {
            pushToUser($conn, $aid, $title, $msg,
                '/BRITE/admin/dashboards/' . $a['admin_role'] . '_dashboard.php',
                $document_path);   // ✅ NEW
        }
    }
}


/* ============================================================
   Resolve the Pangkat Secretary's admin ID for a complaint.
   Returns 0 if the Pangkat has not yet assigned positions.
   ============================================================ */
function getPangkatSecretaryId($conn, $complaint_id) {
    $s = mysqli_prepare($conn,
        "SELECT member_id FROM complaint_pangkat
          WHERE complaint_id = ? AND role = 'secretary'
          LIMIT 1");
    if (!$s) return 0;
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    return $row ? (int)$row['member_id'] : 0;
}

/* ============================================================
   Send a "PRINT REQUIRED" notification to the Pangkat Secretary.
   If the Pangkat Secretary isn't assigned yet, broadcast to all
   Lupon members instead. Always passes the PDF path so the
   frontend modal can open it.
   ============================================================ */
function notifyPangkatSecretaryPrintRequired($conn, $complaint_id, $title, $msg, $pdf_path) {
    $secId = getPangkatSecretaryId($conn, $complaint_id);

    if ($secId > 0) {
        sendNotification($conn, 'admin', $secId, $complaint_id, $title, $msg, 'warning', $pdf_path);
        if (function_exists('pushToUser')) {
            pushToUser($conn, $secId, $title, $msg,
                '/BRITE/admin/dashboards/lupon_dashboard.php',
                $pdf_path);
        }
        return;
    }

    // Fallback ONLY when no Pangkat Secretary is assigned.
    // Exclude the acting admin so the Chair who issued the notice doesn't get pinged.
    $actingAdminId = isset($GLOBALS['admin_id']) ? (int)$GLOBALS['admin_id'] : 0;
    notifyAdminsByRole($conn, ['lupon'], $complaint_id, $title, $msg, 'warning', $actingAdminId, $pdf_path);
}
function tryMatchResident($conn, $partyRow) {
    $matched = (int)($partyRow['matched_resident_id'] ?? 0);
    if ($matched) return $matched;
    if (!empty($partyRow['contact_number'])) {
        $ms = mysqli_prepare($conn,
            "SELECT id FROM resident
              WHERE (phone = ? OR phone_number = ?) AND is_active = 1 LIMIT 1");
        if ($ms) {
            mysqli_stmt_bind_param($ms, "ss", $partyRow['contact_number'], $partyRow['contact_number']);
            mysqli_stmt_execute($ms);
            $matched = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($ms))['id'] ?? 0);
            mysqli_stmt_close($ms);
            if ($matched) return $matched;
        }
    }
    if (!empty($partyRow['full_name'])) {
        $parts = preg_split('/\s+/', trim($partyRow['full_name']));
        if (count($parts) >= 2) {
            $first = $parts[0]; $last = end($parts);
            $ms = mysqli_prepare($conn,
                "SELECT id FROM resident
                  WHERE LOWER(first_name)=LOWER(?) AND LOWER(last_name)=LOWER(?) AND is_active=1 LIMIT 1");
            if ($ms) {
                mysqli_stmt_bind_param($ms, "ss", $first, $last);
                mysqli_stmt_execute($ms);
                $matched = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($ms))['id'] ?? 0);
                mysqli_stmt_close($ms);
            }
        }
    }
    return $matched;
}

function getPangkatConstitutionState($conn, $complaint_id) {
    $s = mysqli_prepare($conn,
        "SELECT id, status, pangkat_constitution_at,
                pangkat_constitution_reminder_sent
           FROM complaints WHERE id = ?");
    if (!$s) return null;
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    if (!$row) return null;

    $today = date('Y-m-d');
    $constitutionDate = $row['pangkat_constitution_at']
        ? date('Y-m-d', strtotime($row['pangkat_constitution_at']))
        : null;

    $canConstitute   = false;
    $constitutionPassed = false;

    if ($constitutionDate) {
        if ($constitutionDate < $today) $constitutionPassed = true;
        if ($constitutionDate <= $today) $canConstitute = true;
    }

    return [
        'status'                => $row['status'],
        'constitution_at'       => $row['pangkat_constitution_at'],
        'constitution_date'     => $constitutionDate,
        'constitution_passed'   => $constitutionPassed,
        'can_constitute'        => $canConstitute,
        'reminder_sent'         => (int)$row['pangkat_constitution_reminder_sent'],
    ];
}

function getPangkatMemberContext($conn, $complaint_id, $admin_id) {
    $s = mysqli_prepare($conn,
        "SELECT id, member_id, role, kp11_pdf_path, kp11_issued_at
           FROM complaint_pangkat
          WHERE complaint_id = ?
          ORDER BY id ASC");
    if (!$s) {
        error_log('getPangkatMemberContext prepare failed: ' . mysqli_error($conn));
        return null;
    }
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $rows = [];
    while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    mysqli_stmt_close($s);

    if (!$rows) return null;

    $firstMemberId = (int)$rows[0]['member_id'];
    $mine = null;
    foreach ($rows as $row) {
        if ((int)$row['member_id'] === (int)$admin_id) {
            $mine = $row;
            break;
        }
    }
    if (!$mine) return null;

    $allPending = true;
    foreach ($rows as $row) {
        if ($row['role'] !== 'pending') { $allPending = false; break; }
    }

    return [
        'complaint_id'   => $complaint_id,
        'my_role'        => $mine['role'],
        'is_first'       => ($firstMemberId === (int)$admin_id),
        'all_pending'    => $allPending,
        'members'        => $rows,
        'first_member_id'=> $firstMemberId,
    ];
}

/* ============================================================
   CONCILIATION WINDOW HELPERS (15 days or 30 if extended)
   ============================================================ */
function getConciliationHardDeadline($conn, $complaint_id) {
    $s = mysqli_prepare($conn,
        "SELECT conciliation_first_hearing_date,
                conciliation_hard_deadline,
                conciliation_extended,
                conciliation_extension_days
           FROM complaints WHERE id = ?");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    if (!$r) return null;

    if (!empty($r['conciliation_hard_deadline'])) {
        return $r['conciliation_hard_deadline'];
    }

    if (empty($r['conciliation_first_hearing_date'])) return null;
    $days = 15 + (int)$r['conciliation_extension_days'];
    return date('Y-m-d H:i:s',
        strtotime($r['conciliation_first_hearing_date'] . ' +' . $days . ' days 23:59:59'));
}

/* ============================================================
   10-DAY REPUDIATION WINDOW HELPERS
   ============================================================ */
function enterSettledPendingState($conn, $complaint_id, $signedAt = null) {
    $signedAt = $signedAt ?: date('Y-m-d H:i:s');
    $repudiationDeadline = date('Y-m-d H:i:s', strtotime($signedAt . ' +10 days'));

    $u = mysqli_prepare($conn,
        "UPDATE complaints
            SET status = 'settled_pending',
                settled_pending_at = ?,
                settlement_signed_at = ?,
                repudiation_deadline = ?,
                resolved_at = NULL,
                is_executory = 0
          WHERE id = ?");
    mysqli_stmt_bind_param($u, "sssi", $signedAt, $signedAt, $repudiationDeadline, $complaint_id);
    mysqli_stmt_execute($u);
    mysqli_stmt_close($u);

    return $repudiationDeadline;
}

function autoPromoteSettlements($conn) {
    $now = date('Y-m-d H:i:s');
    $q = mysqli_query($conn,
        "SELECT id, reference_number, repudiation_deadline
           FROM complaints
          WHERE status = 'settled_pending'
            AND repudiation_deadline IS NOT NULL
            AND repudiation_deadline <= '" . mysqli_real_escape_string($conn, $now) . "'");
    if (!$q) return;
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;

    foreach ($rows as $c) {
        $cid = (int)$c['id'];
        $u = mysqli_prepare($conn,
            "UPDATE complaints
                SET status = 'settled',
                    is_executory = 1,
                    resolved_at = NOW()
              WHERE id = ? AND status = 'settled_pending'");
        mysqli_stmt_bind_param($u, "i", $cid);
        mysqli_stmt_execute($u);
        $changed = mysqli_stmt_affected_rows($u);
        mysqli_stmt_close($u);
        if ($changed < 1) continue;

        logUpdate($conn, $cid, 'status_change', 'settled_pending', 'settled',
            "10-day repudiation window expired. Settlement is now executory.", 0, 'system');

        $creator = getComplaintCreator($conn, $cid);
        if ($creator) {
            sendNotification($conn, 'resident', $creator, $cid,
                'Settlement Now Final',
                "The 10-day repudiation window for complaint #{$c['reference_number']} has ended. The Amicable Settlement is now executory.",
                'success');
        }
        notifyAdminsByRole($conn, ['captain','secretary','lupon'], $cid,
            'Settlement Executory',
            "Complaint #{$c['reference_number']} — settlement is now final and enforceable.",
            'success');
    }
}


/* ============================================================
   ARBITRATION HELPERS — Captain Mediation Flow
   ============================================================ */

function getArbitrationState($conn, $complaint_id) {
    $s = mysqli_prepare($conn,
        "SELECT status,
                mediation_outcome,
                kp14_pdf_path,
                kp14_generated_at,
                kp15_pdf_path,
                kp15_generated_at,
                arbitration_agreement_signed_at,
                repudiation_deadline,
                arbitration_repudiated,
                arbitration_repudiated_at,
                arbitration_repudiated_by_party,
                arbitration_award_deadline
           FROM complaints WHERE id = ?");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    return $row ?: null;
}

function enterArbitrationPendingState($conn, $complaint_id, $kp14PdfPath, $signedAt = null) {
    // 1. Sanitize $signedAt — reject null, empty, zero-dates
    $signedAt = trim((string)$signedAt);

    if (
        $signedAt === '' ||
        $signedAt === '0000-00-00 00:00:00' ||
        $signedAt === '0000-00-00'
    ) {
        $signedAt = date('Y-m-d H:i:s');
    }

    // 2. Verify strtotime can parse it BEFORE using it
    $signedTs = strtotime($signedAt);
    if ($signedTs === false) {
        // Fallback: use today
        $signedAt = date('Y-m-d H:i:s');
        $signedTs = strtotime($signedAt);
    }

    // 3. Anchor to the date-only portion (so time-of-day doesn't drift)
    $signedDate = date('Y-m-d', $signedTs);

    // 4. Compute deadlines with explicit end-of-day
    $repudiationDeadline = date('Y-m-d H:i:s',
        strtotime($signedDate . ' +5 days 23:59:59'));

    $awardDeadline = date('Y-m-d H:i:s',
        strtotime($signedDate . ' +15 days 23:59:59'));

    // 5. Final safety net — if anything is still wrong, abort
    if (
        $repudiationDeadline === '1970-01-01 00:00:00' ||
        $repudiationDeadline === '1969-12-31 23:59:59' ||
        $awardDeadline === '1970-01-01 00:00:00' ||
        $awardDeadline === '1969-12-31 23:59:59'
    ) {
        error_log('enterArbitrationPendingState FAILED to compute deadlines for complaint #' . $complaint_id
            . ' — signedAt=' . var_export($signedAt, true));
        return [
            'signed_at'            => $signedAt,
            'repudiation_deadline' => null,
            'award_deadline'       => null,
        ];
    }

    $u = mysqli_prepare($conn,
        "UPDATE complaints
            SET status = 'arbitration_pending',
                mediation_outcome = 'arbitration',
                kp14_pdf_path = ?,
                kp14_generated_at = NOW(),
                arbitration_agreement_signed_at = ?,
                repudiation_deadline = ?,
                arbitration_repudiated = 0,
                arbitration_repudiated_at = NULL,
                arbitration_repudiated_by_party = NULL,
                arbitration_award_deadline = ?,
                resolved_at = NULL,
                is_executory = 0
          WHERE id = ?");
    mysqli_stmt_bind_param($u, "ssssi",
        $kp14PdfPath, $signedAt, $repudiationDeadline,
        $awardDeadline, $complaint_id);
    mysqli_stmt_execute($u);
    mysqli_stmt_close($u);

    return [
        'signed_at'            => $signedAt,
        'repudiation_deadline' => $repudiationDeadline,
        'award_deadline'       => $awardDeadline,
    ];
}

function autoPromoteArbitration($conn) {
    $now = date('Y-m-d H:i:s');
    $q = mysqli_query($conn,
        "SELECT id, reference_number
           FROM complaints
          WHERE status = 'arbitration_pending'
            AND arbitration_repudiated = 0
            AND repudiation_deadline IS NOT NULL
            AND repudiation_deadline <= '" . mysqli_real_escape_string($conn, $now) . "'");
    if (!$q) return;
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;

    foreach ($rows as $c) {
        $cid = (int)$c['id'];
        $u = mysqli_prepare($conn,
            "UPDATE complaints
                SET status = 'arbitration_agreement_active'
              WHERE id = ? AND status = 'arbitration_pending'");
        mysqli_stmt_bind_param($u, "i", $cid);
        mysqli_stmt_execute($u);
        $changed = mysqli_stmt_affected_rows($u);
        mysqli_stmt_close($u);
        if ($changed < 1) continue;

        logUpdate($conn, $cid, 'status_change',
            'arbitration_pending', 'arbitration_agreement_active',
            "5-day repudiation window on KP Form #14 (Agreement for Arbitration) expired. Agreement is now final. KP Form #15 (Arbitration Award) is due within the 15-day award window.",
            0, 'system');

        notifyAdminsByRole($conn, ['captain','secretary','lupon'], $cid,
            'KP Form #14 Final — Prepare KP Form #15',
            "Complaint #{$c['reference_number']} — parties did not repudiate KP Form #14. You must render the Arbitration Award (KP Form #15) within 15 days.",
            'warning');
    }
}

function autoExpireArbitrationAward($conn) {
    $now = date('Y-m-d H:i:s');
    $q = mysqli_query($conn,
        "SELECT id, reference_number
           FROM complaints
          WHERE status IN ('arbitration_pending','arbitration_agreement_active')
            AND kp15_generated_at IS NULL
            AND arbitration_award_deadline IS NOT NULL
            AND arbitration_award_deadline <= '" . mysqli_real_escape_string($conn, $now) . "'");
    if (!$q) return;
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;

    foreach ($rows as $c) {
        $cid = (int)$c['id'];
        logUpdate($conn, $cid, 'note', null, null,
            "[AUTO] 15-day window to render KP Form #15 (Arbitration Award) has expired. Issue the award immediately.",
            0, 'system');
        notifyAdminsByRole($conn, ['captain'], $cid,
            '⚠ KP Form #15 (Arbitration Award) Overdue',
            "Complaint #{$c['reference_number']} — 15-day award window has expired. Please render KP Form #15 now.",
            'error');
    }
}
/* ============================================================
   Build the full phased KP list for a complaint
   ============================================================ */
function build_kp_forms_list($conn, $complaint_id, $onlyGenerated = true) {
    $cs = mysqli_prepare($conn,
        "SELECT c.id, c.reference_number, c.title, c.status,
                c.hearing_date, c.hearing_time, c.hearing_location,
                c.assigned_to,
                c.kp15_pdf_path, c.kp15_generated_at,
                c.kp16_pdf_path, c.kp16_generated_at,
                c.kp17_pdf_path, c.kp17_generated_at,
                c.kp20_pdf_path, c.kp20_generated_at,
                c.kp21_pdf_path, c.kp21_generated_at,
                c.kp22_pdf_path, c.kp22_generated_at
           FROM complaints c WHERE c.id = ?");
    if (!$cs) { error_log('build_kp_forms_list[complaints] failed: ' . mysqli_error($conn)); return []; }
    mysqli_stmt_bind_param($cs, "i", $complaint_id);
    mysqli_stmt_execute($cs);
    $c = mysqli_fetch_assoc(mysqli_stmt_get_result($cs));
    mysqli_stmt_close($cs);
    if (!$c) return [];

    $ps = mysqli_prepare($conn,
        "SELECT id, party_type, full_name, notice_pdf_path, notice_pdf_generated_at,
                notice_of_hearing_sent_at, summons_sent_at, notified_via,
                kp10_pdf_path, kp10_issued_at, kp10_notified_via
           FROM complaint_parties
          WHERE complaint_id = ?
            AND party_type IN ('complainant','respondent')
          ORDER BY FIELD(party_type,'complainant','respondent'), id");
    if (!$ps) { error_log('build_kp_forms_list[parties] failed: ' . mysqli_error($conn)); return []; }
    mysqli_stmt_bind_param($ps, "i", $complaint_id);
    mysqli_stmt_execute($ps);
    $pr = mysqli_stmt_get_result($ps);
    $complainants = []; $respondents = []; $witnesses = [];
    while ($row = mysqli_fetch_assoc($pr)) {
        if ($row['party_type'] === 'complainant')    $complainants[] = $row;
        elseif ($row['party_type'] === 'respondent') $respondents[]  = $row;
        else                                          $witnesses[]    = $row;
    }
    mysqli_stmt_close($ps);

    $mhStmt = mysqli_prepare($conn,
        "SELECT id, session_number, hearing_date, hearing_time, location,
                kp8_complainant_pdf_path, kp8_complainant_issued_at, kp8_complainant_notified,
                kp9_respondent_pdf_path,  kp9_respondent_issued_at,  kp9_respondent_notified
           FROM complaint_hearings
          WHERE complaint_id = ? AND session_type = 'mediation'
          ORDER BY session_number ASC, id ASC");
    $mediationHearings = [];
    if ($mhStmt) {
        mysqli_stmt_bind_param($mhStmt, "i", $complaint_id);
        mysqli_stmt_execute($mhStmt);
        $mhRes = mysqli_stmt_get_result($mhStmt);
        while ($row = mysqli_fetch_assoc($mhRes)) $mediationHearings[] = $row;
        mysqli_stmt_close($mhStmt);
    }

    $chStmt = mysqli_prepare($conn,
        "SELECT id, session_number, hearing_date, hearing_time, location,
                kp12_complainant_pdf_path, kp12_complainant_issued_at, kp12_complainant_notified,
                kp12_respondent_pdf_path,  kp12_respondent_issued_at,  kp12_respondent_notified
           FROM complaint_hearings
          WHERE complaint_id = ? AND session_type = 'conciliation'
          ORDER BY session_number ASC, id ASC");
    $conciliationHearings = [];
    if ($chStmt) {
        mysqli_stmt_bind_param($chStmt, "i", $complaint_id);
        mysqli_stmt_execute($chStmt);
        $chRes = mysqli_stmt_get_result($chStmt);
        while ($row = mysqli_fetch_assoc($chRes)) $conciliationHearings[] = $row;
        mysqli_stmt_close($chStmt);
    }

    $pkStmt = mysqli_prepare($conn,
        "SELECT cp.member_id, cp.role, cp.kp11_pdf_path, cp.kp11_issued_at, a.full_name
           FROM complaint_pangkat cp
           JOIN admin a ON a.id = cp.member_id
          WHERE cp.complaint_id = ?
          ORDER BY cp.id ASC");
    if (!$pkStmt) { error_log('build_kp_forms_list[pangkat] failed: ' . mysqli_error($conn)); return []; }
    mysqli_stmt_bind_param($pkStmt, "i", $complaint_id);
    mysqli_stmt_execute($pkStmt);
    $pkRes = mysqli_stmt_get_result($pkStmt);
    $pangkatMembers = [];
    while ($row = mysqli_fetch_assoc($pkRes)) $pangkatMembers[] = $row;
    mysqli_stmt_close($pkStmt);

    $attStmt = mysqli_prepare($conn,
        "SELECT a.hearing_id, a.party_id, a.status, a.kp18_or_19_pdf_path,
                a.kp18_or_19_generated_at, p.party_type, p.full_name
           FROM complaint_attendance a
           JOIN complaint_parties p ON p.id = a.party_id
          WHERE a.complaint_id = ? AND a.kp18_or_19_pdf_path IS NOT NULL
          ORDER BY a.recorded_at DESC");
    $failureNotices = [];
    if ($attStmt) {
        mysqli_stmt_bind_param($attStmt, "i", $complaint_id);
        mysqli_stmt_execute($attStmt);
        $attRes = mysqli_stmt_get_result($attStmt);
        while ($row = mysqli_fetch_assoc($attRes)) $failureNotices[] = $row;
        mysqli_stmt_close($attStmt);
    }

    $captain   = kp_get_officer($conn, 'captain', (int)($c['assigned_to'] ?? 0));
    $secretary = kp_get_officer($conn, 'secretary');
    $pangkatChair = kp_get_pangkat_officer($conn, $complaint_id, 'chair');
    $pangkatSec   = kp_get_pangkat_officer($conn, $complaint_id, 'secretary');

    $captainName   = $captain['full_name']   ?? 'Barangay Captain';
    $secretaryName = $secretary['full_name'] ?? 'Barangay Secretary';
    $chairName     = $pangkatChair['full_name'] ?? 'Pangkat Chairman';
    $secName       = $pangkatSec['full_name']   ?? 'Pangkat Secretary';

    $forms = [];
    $complainantName = $complainants[0]['full_name'] ?? 'Complainant';
    $respondentName  = $respondents[0]['full_name']  ?? 'Respondent';
    $complainantId   = (int)($complainants[0]['id'] ?? 0);
    $respondentId    = (int)($respondents[0]['id']  ?? 0);

    if (!empty($mediationHearings)) {
        foreach ($mediationHearings as $mh) {
            $sessionNo = (int)($mh['session_number'] ?? 1);
            $hDate = $mh['hearing_date'] ? date('M j, Y', strtotime($mh['hearing_date'])) : '—';
            $hTime = $mh['hearing_time'] ? date('g:i A', strtotime($mh['hearing_time'])) : '';

            $kp8Path     = $mh['kp8_complainant_pdf_path'] ?? null;
            $kp8Issued   = $mh['kp8_complainant_issued_at'] ?? null;
            $kp8Notified = (int)($mh['kp8_complainant_notified'] ?? 0) === 1;
            if (empty($kp8Path) && !empty($complainants[0]['notice_pdf_path'])) {
                $kp8Path     = $complainants[0]['notice_pdf_path'];
                $kp8Issued   = $complainants[0]['notice_pdf_generated_at'] ?? null;
                $kp8Notified = ($complainants[0]['notified_via'] ?? '') === 'push';
            }

            $kp9Path     = $mh['kp9_respondent_pdf_path'] ?? null;
            $kp9Issued   = $mh['kp9_respondent_issued_at'] ?? null;
            $kp9Notified = (int)($mh['kp9_respondent_notified'] ?? 0) === 1;
            if (empty($kp9Path) && !empty($respondents[0]['notice_pdf_path'])) {
                $kp9Path     = $respondents[0]['notice_pdf_path'];
                $kp9Issued   = $respondents[0]['notice_pdf_generated_at'] ?? null;
                $kp9Notified = ($respondents[0]['notified_via'] ?? '') === 'push';
            }

            $forms[] = [
                'form_tag'     => 'kp8',
                'form_no'      => 8,
                'form_name'    => 'Notice of Hearing (Mediation Proceedings)',
                'phase'        => 'scheduling',
                'phase_label'  => 'Phase 1 — Scheduling',
                'event'        => "Notifies the complainant of Mediation Hearing #{$sessionNo} on {$hDate}" . ($hTime ? " at {$hTime}" : '') . '.',
                'issued_by'    => $captainName . ' (Punong Barangay)',
                'to'           => $complainantName,
                'pdf_path'     => $kp8Path,
                'generated_at' => $kp8Issued,
                'is_generated' => !empty($kp8Path),
                'on_demand'    => false,
                'party_id'     => $complainantId,
                'hearing_id'   => (int)$mh['id'],
                'session_no'   => $sessionNo,
                'notified'     => $kp8Notified,
            ];

            $forms[] = [
                'form_tag'     => 'kp9',
                'form_no'      => 9,
                'form_name'    => 'Summons',
                'phase'        => 'scheduling',
                'phase_label'  => 'Phase 1 — Scheduling',
                'event'        => "Requires the respondent to appear at Mediation Hearing #{$sessionNo} on {$hDate}" . ($hTime ? " at {$hTime}" : '') . '.',
                'issued_by'    => $captainName . ' (Punong Barangay)',
                'to'           => $respondentName,
                'pdf_path'     => $kp9Path,
                'generated_at' => $kp9Issued,
                'is_generated' => !empty($kp9Path),
                'on_demand'    => false,
                'party_id'     => $respondentId,
                'hearing_id'   => (int)$mh['id'],
                'session_no'   => $sessionNo,
                'notified'     => $kp9Notified,
            ];
        }
    } else {
        foreach ($complainants as $p) {
            if (empty($p['notice_pdf_path'])) continue;
            $forms[] = [
                'form_tag' => 'kp8', 'form_no' => 8,
                'form_name' => 'Notice of Hearing (Mediation Proceedings)',
                'phase' => 'scheduling', 'phase_label' => 'Phase 1 — Scheduling',
                'event' => 'Notifies the complainant of the first mediation hearing.',
                'issued_by' => $captainName . ' (Punong Barangay)',
                'to' => $p['full_name'], 'pdf_path' => $p['notice_pdf_path'],
                'generated_at' => $p['notice_pdf_generated_at'] ?? null,
                'is_generated' => true, 'on_demand' => false,
                'party_id' => (int)$p['id'],
                'notified' => ($p['notified_via'] ?? '') === 'push',
            ];
        }
        foreach ($respondents as $p) {
            if (empty($p['notice_pdf_path'])) continue;
            $forms[] = [
                'form_tag' => 'kp9', 'form_no' => 9,
                'form_name' => 'Summons',
                'phase' => 'scheduling', 'phase_label' => 'Phase 1 — Scheduling',
                'event' => 'Requires the respondent to appear at the first mediation hearing.',
                'issued_by' => $captainName . ' (Punong Barangay)',
                'to' => $p['full_name'], 'pdf_path' => $p['notice_pdf_path'],
                'generated_at' => $p['notice_pdf_generated_at'] ?? null,
                'is_generated' => true, 'on_demand' => false,
                'party_id' => (int)$p['id'],
                'notified' => ($p['notified_via'] ?? '') === 'push',
            ];
        }
    }

    $kp16Path = $c['kp16_pdf_path'] ?? null;
    $kp16Mode = null;
    if ($kp16Path) {
        $kp16Mode = (strpos($kp16Path, 'conciliation') !== false) ? 'conciliation' : 'mediation';
    }
    if ($kp16Mode === 'mediation' && !empty($kp16Path)) {
        $forms[] = [
            'form_tag'     => 'kp16',
            'form_no'      => 16,
            'form_name'    => 'Amicable Settlement',
            'phase'        => 'mediation',
            'phase_label'  => 'Phase 2 — Mediation',
            'event'        => 'Records the agreement reached by the parties during mediation.',
            'issued_by'    => $captainName . ' (Punong Barangay)',
            'to'           => 'Both parties',
            'pdf_path'     => $kp16Path,
            'generated_at' => $c['kp16_generated_at'] ?? null,
            'is_generated' => true,
            'on_demand'    => false,
            'mode'         => 'mediation',
            'no_signature' => true,
        ];
    }

    foreach ($failureNotices as $f) {
        $isComplainant = ($f['party_type'] === 'complainant');
        $forms[] = [
            'form_tag'     => $isComplainant ? 'kp18' : 'kp19',
            'form_no'      => $isComplainant ? 18 : 19,
            'form_name'    => $isComplainant
                                ? 'Notice of Hearing for Complainant (Re: Failure to Appear)'
                                : 'Notice of Hearing for Respondent (Re: Failure to Appear)',
            'phase'        => 'mediation',
            'phase_label'  => 'Phase 2 — Mediation',
            'event'        => 'Issued when a party fails to appear for a scheduled mediation hearing.',
            'issued_by'    => $captainName . ' (Punong Barangay)',
            'to'           => $f['full_name'],
            'pdf_path'     => $f['kp18_or_19_pdf_path'],
            'generated_at' => $f['kp18_or_19_generated_at'],
            'is_generated' => true,
            'on_demand'    => false,
            'party_id'     => (int)$f['party_id'],
            'hearing_id'   => (int)$f['hearing_id'],
        ];
    }

    foreach ($complainants as $p) {
        if (!empty($p['kp10_pdf_path'])) {
            $forms[] = [
                'form_tag'     => 'kp10',
                'form_no'      => 10,
                'form_name'    => 'Notice for Constitution of Pangkat',
                'phase'        => 'constitution',
                'phase_label'  => 'Phase 3 — Pangkat Constitution',
                'event'        => 'Requires both parties to appear and agree on the 3 Pangkat members.',
                'issued_by'    => $captainName . ' (Punong Barangay)',
                'to'           => $p['full_name'],
                'pdf_path'     => $p['kp10_pdf_path'],
                'generated_at' => $p['kp10_issued_at'],
                'is_generated' => true,
                'on_demand'    => false,
                'party_id'     => (int)$p['id'],
            ];
        }
    }
    foreach ($respondents as $p) {
        if (!empty($p['kp10_pdf_path'])) {
            $forms[] = [
                'form_tag'     => 'kp10',
                'form_no'      => 10,
                'form_name'    => 'Notice for Constitution of Pangkat',
                'phase'        => 'constitution',
                'phase_label'  => 'Phase 3 — Pangkat Constitution',
                'event'        => 'Requires both parties to appear and agree on the 3 Pangkat members.',
                'issued_by'    => $captainName . ' (Punong Barangay)',
                'to'           => $p['full_name'],
                'pdf_path'     => $p['kp10_pdf_path'],
                'generated_at' => $p['kp10_issued_at'],
                'is_generated' => true,
                'on_demand'    => false,
                'party_id'     => (int)$p['id'],
            ];
        }
    }
    foreach ($pangkatMembers as $m) {
        if (!empty($m['kp11_pdf_path'])) {
            $roleLabel = ($m['role'] && $m['role'] !== 'pending')
                            ? ucfirst($m['role']) . ' of the Pangkat'
                            : 'Chosen Pangkat Member';
            $forms[] = [
                'form_tag'     => 'kp11',
                'form_no'      => 11,
                'form_name'    => 'Notice to Chosen Pangkat Member',
                'phase'        => 'constitution',
                'phase_label'  => 'Phase 3 — Pangkat Constitution',
                'event'        => 'Issued to each Lupon member chosen to sit on the Pangkat.',
                'issued_by'    => $captainName . ' (Punong Barangay)',
                'to'           => $m['full_name'] . ' (' . $roleLabel . ')',
                'pdf_path'     => $m['kp11_pdf_path'],
                'generated_at' => $m['kp11_issued_at'],
                'is_generated' => true,
                'on_demand'    => false,
                'member_id'    => (int)$m['member_id'],
            ];
        }
    }

    foreach ($conciliationHearings as $ch) {
        $sessionNo = (int)($ch['session_number'] ?? 1);
        $hDate = $ch['hearing_date'] ? date('M j, Y', strtotime($ch['hearing_date'])) : '—';
        $hTime = $ch['hearing_time'] ? date('g:i A', strtotime($ch['hearing_time'])) : '';

        $forms[] = [
            'form_tag'     => 'kp12',
            'form_no'      => 12,
            'form_name'    => 'Notice of Hearing (Conciliation Proceedings)',
            'phase'        => 'conciliation',
            'phase_label'  => 'Phase 4 — Conciliation',
            'event'        => "Notifies the complainant of Conciliation Hearing #{$sessionNo} before the Pangkat on {$hDate}" . ($hTime ? " at {$hTime}" : '') . '.',
            'issued_by'    => $chairName . ' (Pangkat Chairman)',
            'to'           => $complainantName,
            'pdf_path'     => $ch['kp12_complainant_pdf_path'] ?? null,
            'generated_at' => $ch['kp12_complainant_issued_at'] ?? null,
            'is_generated' => !empty($ch['kp12_complainant_pdf_path']),
            'on_demand'    => false,
            'party_id'     => $complainantId,
            'hearing_id'   => (int)$ch['id'],
            'session_no'   => $sessionNo,
            'notified'     => (int)($ch['kp12_complainant_notified'] ?? 0) === 1,
        ];
        $forms[] = [
            'form_tag'     => 'kp12',
            'form_no'      => 12,
            'form_name'    => 'Notice of Hearing (Conciliation Proceedings)',
            'phase'        => 'conciliation',
            'phase_label'  => 'Phase 4 — Conciliation',
            'event'        => "Notifies the respondent of Conciliation Hearing #{$sessionNo} before the Pangkat on {$hDate}" . ($hTime ? " at {$hTime}" : '') . '.',
            'issued_by'    => $chairName . ' (Pangkat Chairman)',
            'to'           => $respondentName,
            'pdf_path'     => $ch['kp12_respondent_pdf_path'] ?? null,
            'generated_at' => $ch['kp12_respondent_issued_at'] ?? null,
            'is_generated' => !empty($ch['kp12_respondent_pdf_path']),
            'on_demand'    => false,
            'party_id'     => $respondentId,
            'hearing_id'   => (int)$ch['id'],
            'session_no'   => $sessionNo,
            'notified'     => (int)($ch['kp12_respondent_notified'] ?? 0) === 1,
        ];
    }

    if (empty($conciliationHearings)
        && in_array($c['status'], ['pangkat_scheduled','failed_conciliation_final','unresolved','settled','settled_pending','certificate_issued'], true)
        && !empty($c['hearing_date'])) {
        $forms[] = [
            'form_tag'     => 'kp12',
            'form_no'      => 12,
            'form_name'    => 'Notice of Hearing (Conciliation Proceedings)',
            'phase'        => 'conciliation',
            'phase_label'  => 'Phase 4 — Conciliation',
            'event'        => 'Notifies both parties of a conciliation hearing before the Pangkat.',
            'issued_by'    => $chairName . ' (Pangkat Chairman)',
            'to'           => 'Both parties',
            'pdf_path'     => null,
            'generated_at' => null,
            'is_generated' => false,
            'on_demand'    => true,
        ];
    }

    $forms[] = [
        'form_tag'     => 'kp13',
        'form_no'      => 13,
        'form_name'    => 'Subpoena',
        'phase'        => 'scheduling',
        'phase_label'  => 'Phase 1 — Scheduling',
        'event'        => 'Issued to compel a witness to appear and testify before the Pangkat.',
        'issued_by'    => $chairName . ' (Pangkat Chairman)',
        'to'           => 'Witness(es)',
        'pdf_path'     => null,
        'generated_at' => null,
        'is_generated' => false,
        'on_demand'    => true,
    ];

    if ($kp16Mode === 'conciliation') {
        $forms[] = [
            'form_tag'     => 'kp16',
            'form_no'      => 16,
            'form_name'    => 'Amicable Settlement',
            'phase'        => 'conciliation',
            'phase_label'  => 'Phase 4 — Conciliation',
            'event'        => 'Records the agreement reached by the parties during conciliation before the Pangkat. STORED AS SOFT COPY — NO DIGITAL SIGNATURE.',
            'issued_by'    => $secName . ' (Pangkat Secretary)',
            'to'           => 'Both parties',
            'pdf_path'     => $kp16Path,
            'generated_at' => $c['kp16_generated_at'] ?? null,
            'is_generated' => true,
            'on_demand'    => false,
            'mode'         => 'conciliation',
            'no_signature' => true,
        ];
    }

   if (in_array($c['status'], [
    'pangkat_scheduled',
    'failed_conciliation_final',
    'unresolved',
    'arbitration_pending',
    'arbitration_agreement_active',
    'arbitration_award_issued',
    'arbitration_repudiated',
], true)) {
       $forms[] = [
    'form_tag'     => 'kp14',
    'form_no'      => 14,
    'form_name'    => 'Agreement for Arbitration',
    'phase'        => 'arbitration',
    'phase_label'  => 'Phase 5 — Arbitration & Settlement',
    'event'        => 'A written agreement by both parties to submit the dispute to the Lupon/Pangkat Chairman for a binding award.',
    'issued_by'    => $captainName . ' (Punong Barangay)',
    'to'           => 'Both parties',
    'pdf_path'     => $c['kp14_pdf_path'] ?? null,
    'generated_at' => $c['kp14_generated_at'] ?? null,
    'is_generated' => !empty($c['kp14_pdf_path']),
    'on_demand'    => true,
];
       $forms[] = [
    'form_tag'     => 'kp15',
    'form_no'      => 15,
    'form_name'    => 'Arbitration Award',
    'phase'        => 'arbitration',
    'phase_label'  => 'Phase 5 — Arbitration & Settlement',
    'event'        => 'The written decision issued by the Lupon/Pangkat Chairman after hearing the parties under the Arbitration Agreement.',
    'issued_by'    => $chairName . ' (Pangkat Chairman)',
    'to'           => 'Both parties',
    'pdf_path'     => $c['kp15_pdf_path'] ?? null,       // ← was null
    'generated_at' => $c['kp15_generated_at'] ?? null,   // ← already correct
    'is_generated' => !empty($c['kp15_pdf_path']),       // ← already correct
    'on_demand'    => true,
];
    }
    // ── KP #17 (Repudiation) ──
    // Show it whenever a settlement/arbitration exists OR the case has been repudiated.
    $hasRepudiationContext = (
        $kp16Mode
        || in_array($c['status'], ['settled','settled_pending','certificate_issued','amicable_repudiated','arbitration_repudiated'], true)
        || (int)($c['amicable_repudiated'] ?? 0) === 1
        || (int)($c['arbitration_repudiated'] ?? 0) === 1
    );
    if ($hasRepudiationContext) {
        $repudiationSource = '';
        if ((int)($c['amicable_repudiated'] ?? 0) === 1) {
            $repudiationSource = ' KP #16 (Amicable Settlement) was repudiated by ' 
                . strtoupper($c['amicable_repudiated_by_party'] ?? 'party') . '.';
        } elseif ((int)($c['arbitration_repudiated'] ?? 0) === 1) {
            $repudiationSource = ' KP #14 (Agreement for Arbitration) was repudiated by ' 
                . strtoupper($c['arbitration_repudiated_by_party'] ?? 'party') . '.';
        }
        $forms[] = [
            'form_tag'     => 'kp17',
            'form_no'      => 17,
            'form_name'    => 'Repudiation',
            'phase'        => 'arbitration',
            'phase_label'  => 'Phase 5 — Arbitration & Settlement',
            'event'        => 'Filed when a party protests the settlement or arbitration agreement on grounds of fraud, violence, or intimidation.' . $repudiationSource,
            'issued_by'    => $chairName . ' (Pangkat Chairman)',
            'to'           => 'Both parties',
            'pdf_path'     => $c['kp17_pdf_path'] ?? null,
            'generated_at' => $c['kp17_generated_at'] ?? null,
            'is_generated' => !empty($c['kp17_pdf_path']),
            'on_demand'    => true,
        ];
    }

    if (in_array($c['status'], ['failed_mediation','failed_conciliation_final','unresolved','certificate_issued','settled','settled_pending'], true)) {
        $forms[] = [
            'form_tag'     => 'kp20',
            'form_no'      => 20,
            'form_name'    => 'Certification to File Action (from Lupon Secretary)',
            'phase'        => 'certifications',
            'phase_label'  => 'Phase 6 — Certifications',
            'event'        => 'Issued after mediation fails or the settlement was repudiated.',
            'issued_by'    => $secretaryName . ' (Lupon Secretary)',
            'to'           => 'Both parties',
            'pdf_path'     => $c['kp20_pdf_path'] ?? null,
            'generated_at' => $c['kp20_generated_at'] ?? null,
            'is_generated' => !empty($c['kp20_pdf_path']),
            'on_demand'    => true,
        ];
        $forms[] = [
            'form_tag'     => 'kp21',
            'form_no'      => 21,
            'form_name'    => 'Certification to File Action (from Pangkat Secretary)',
            'phase'        => 'certifications',
            'phase_label'  => 'Phase 6 — Certifications',
            'event'        => 'Issued after both mediation and conciliation failed to reach a settlement. PHYSICAL DELIVERY ONLY.',
            'issued_by'    => $secName . ' (Pangkat Secretary)',
            'to'           => 'Both parties',
            'pdf_path'     => $c['kp21_pdf_path'] ?? null,
            'generated_at' => $c['kp21_generated_at'] ?? null,
            'is_generated' => !empty($c['kp21_pdf_path']),
            'on_demand'    => true,
            'no_signature' => true,
        ];
    }
    if (in_array($c['status'], ['failed_conciliation_final','unresolved'], true)) {
        $forms[] = [
            'form_tag'     => 'kp22',
            'form_no'      => 22,
            'form_name'    => 'Certification to File Action (from Pangkat Chairman)',
            'phase'        => 'certifications',
            'phase_label'  => 'Phase 6 — Certifications',
            'event'        => 'Issued when the respondent willfully failed to appear at conciliation.',
            'issued_by'    => $chairName . ' (Pangkat Chairman)',
            'to'           => 'Both parties',
            'pdf_path'     => $c['kp22_pdf_path'] ?? null,
            'generated_at' => $c['kp22_generated_at'] ?? null,
            'is_generated' => !empty($c['kp22_pdf_path']),
            'on_demand'    => true,
        ];
    }

    if (in_array($c['status'], ['settled','settled_pending','certificate_issued'], true)) {
        $forms[] = [
            'form_tag'     => 'kp25',
            'form_no'      => 25,
            'form_name'    => 'Motion for Execution',
            'phase'        => 'execution',
            'phase_label'  => 'Phase 7 — Execution',
            'event'        => 'Filed by the prevailing party to enforce the final settlement or award.',
            'issued_by'    => $captainName . ' (Punong Barangay)',
            'to'           => 'Both parties',
            'pdf_path'     => null,
            'generated_at' => null,
            'is_generated' => false,
            'on_demand'    => true,
        ];
        $forms[] = [
            'form_tag'     => 'kp26',
            'form_no'      => 26,
            'form_name'    => 'Notice of Hearing (Re: Motion for Execution)',
            'phase'        => 'execution',
            'phase_label'  => 'Phase 7 — Execution',
            'event'        => 'Notifies the parties of the hearing on the Motion for Execution.',
            'issued_by'    => $captainName . ' (Punong Barangay)',
            'to'           => 'Both parties',
            'pdf_path'     => null,
            'generated_at' => null,
            'is_generated' => false,
            'on_demand'    => true,
        ];
        $forms[] = [
            'form_tag'     => 'kp27',
            'form_no'      => 27,
            'form_name'    => 'Notice of Execution',
            'phase'        => 'execution',
            'phase_label'  => 'Phase 7 — Execution',
            'event'        => 'Issued when the party obliged fails to comply with the settlement or award.',
            'issued_by'    => $captainName . ' (Punong Barangay)',
            'to'           => 'Both parties',
            'pdf_path'     => null,
            'generated_at' => null,
            'is_generated' => false,
            'on_demand'    => true,
        ];
    }

    if ($onlyGenerated) {
        $forms = array_values(array_filter($forms, function ($f) {
            return !empty($f['is_generated']) && !empty($f['pdf_path']);
        }));
    }

    return $forms;
}

/* ---------------- AUTO: expire mediation if deadline passed ---------------- */
function autoExpireMediation($conn) {
    $today = date('Y-m-d');
    $s = mysqli_prepare($conn,
        "SELECT id, reference_number, status
         FROM complaints
         WHERE status IN ('for_mediation','mediation_scheduled','arbitration_repudiated')
           AND mediation_deadline IS NOT NULL
           AND DATE(mediation_deadline) < ?");
    if (!$s) return;
    mysqli_stmt_bind_param($s, "s", $today);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $expired = [];
    while ($row = mysqli_fetch_assoc($r)) $expired[] = $row;
    mysqli_stmt_close($s);

    foreach ($expired as $c) {
        $cid = (int)$c['id'];

        // ── Guard: skip if the case has already moved past mediation ──
        $stageCheck = mysqli_prepare($conn,
            "SELECT status FROM complaints WHERE id = ?");
        mysqli_stmt_bind_param($stageCheck, "i", $cid);
        mysqli_stmt_execute($stageCheck);
        $stageRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stageCheck));
        mysqli_stmt_close($stageCheck);

        if ($stageRow && in_array($stageRow['status'], [
            'pangkat_constituted',
            'pangkat_constitution_scheduled',
            'pangkat_scheduled',
            'failed_conciliation_final',
            'amicable_repudiated',
            'arbitration_repudiated',
            'unresolved',
            'settled_pending',
            'settled',
            'certificate_issued',
            'dismissed'
        ], true)) {
            continue; // never regress a case that has moved past mediation
        }

        $complainantNoShow = in_array('complainant', latestSessionAbsentRoles($conn, $cid), true);
        $newStatus = $complainantNoShow ? 'dismissed' : 'failed_mediation';
        $marker    = $complainantNoShow
            ? '[AUTO][COMPLAINANT NO-SHOW] 15-day mediation period expired; the complainant did not appear. Complaint is DISMISSED.'
            : '[AUTO] 15-day mediation period expired.';

        $u = mysqli_prepare($conn,
            "UPDATE complaints
             SET status = ?, mediation_last_result = 'no_settlement',
                 resolution_notes = CONCAT(COALESCE(resolution_notes,''), '\n\n', ?)
             WHERE id = ? AND status IN ('for_mediation','mediation_scheduled')");
        mysqli_stmt_bind_param($u, "ssi", $newStatus, $marker, $cid);
        mysqli_stmt_execute($u);
        $changed = mysqli_stmt_affected_rows($u);
        mysqli_stmt_close($u);
        if ($changed < 1) continue;

        $h = mysqli_prepare($conn,
            "UPDATE complaint_hearings
             SET status = 'cancelled',
                 session_reason = CONCAT(COALESCE(session_reason,''), '\n[AUTO-CANCELLED] 15-day mediation period expired.')
             WHERE complaint_id = ? AND session_type = 'mediation' AND status = 'scheduled'");
        mysqli_stmt_bind_param($h, "i", $cid);
        mysqli_stmt_execute($h);
        mysqli_stmt_close($h);

        $creator = getComplaintCreator($conn, $cid);
        if ($complainantNoShow) {
            $certRes = save_bar_action_cert(
                $conn, $cid, date('Y-m-d'),
                'Complainant failed to appear despite due notice (auto-issued on 15-day expiry).',
                'BARANGAY SECRETARY'
            );
            $certNo = 'CBA-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
            $certPdfPath = (!empty($certRes['ok']) && !empty($certRes['pdf_path'])) ? $certRes['pdf_path'] : null;
            if (!empty($certRes['ok'])) {
                $uq = mysqli_prepare($conn,
                    "UPDATE complaints SET bar_action_cert_no = ?, bar_action_issued_at = NOW() WHERE id = ?");
                mysqli_stmt_bind_param($uq, "si", $certNo, $cid);
                mysqli_stmt_execute($uq);
                mysqli_stmt_close($uq);
            }
            logUpdate($conn, $cid, 'status_change', $c['status'], 'dismissed',
                "15-day mediation period expired and complainant did not appear. Complaint DISMISSED.",
                0, 'system');
            if ($creator) {
                sendNotification($conn, 'resident', $creator, $cid,
                    'Complaint Dismissed',
                    "Complaint #{$c['reference_number']} was DISMISSED because you did not appear.",
                    'error',
                    $certPdfPath);  // ← NEW
            }
        } else {
            logUpdate($conn, $cid, 'status_change', $c['status'], 'failed_mediation',
                "15-day mediation period expired. Captain to schedule the Pangkat constitution meeting.",
                0, 'system');
            if ($creator) {
                sendNotification($conn, 'resident', $creator, $cid,
                    'Mediation Period Expired',
                    "The 15-day mediation period for complaint #{$c['reference_number']} has ended.",
                    'info');
            }
            notifyAdminsByRole($conn, ['captain'], $cid,
                'Mediation Period Expired — Constitute Pangkat',
                "Complaint #{$c['reference_number']} — schedule the Pangkat constitution meeting.",
                'warning',
                0   // 0 = system action → notify all captains
            );
        }
    }
}

autoExpireMediation($conn);
autoPromoteSettlements($conn);
autoPromoteArbitration($conn);
autoExpireArbitrationAward($conn);

/* ============================================================
   AUTO: Auto-unjustified absent conciliation parties after 5 days
   Reads/writes the explain-window columns on complaint_hearings.
   ============================================================ */
function autoResolveConcilAbsences($conn) {
    $now = date('Y-m-d H:i:s');

    // Find conciliation hearings whose explain window has expired
    // and are not yet resolved.
    $s = mysqli_prepare($conn,
        "SELECT h.id AS hearing_id, h.complaint_id,
                h.explain_window_started_at, h.explain_window_deadline,
                c.reference_number, c.status AS complaint_status
           FROM complaint_hearings h
           JOIN complaints c ON c.id = h.complaint_id
          WHERE h.session_type = 'conciliation'
            AND h.explain_window_deadline IS NOT NULL
            AND h.explain_window_resolved_at IS NULL
            AND h.explain_window_deadline <= ?
            AND c.status = 'pangkat_scheduled'");
    if (!$s) return;
    mysqli_stmt_bind_param($s, "s", $now);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $expired = [];
    while ($row = mysqli_fetch_assoc($r)) $expired[] = $row;
    mysqli_stmt_close($s);

    foreach ($expired as $c) {
        $cid = (int)$c['complaint_id'];
        $hearing_id = (int)$c['hearing_id'];

        // Get absent parties who never got a decision for this hearing
        $aq = mysqli_prepare($conn,
            "SELECT p.id, p.full_name, p.party_type, p.matched_resident_id
               FROM complaint_parties p
               JOIN complaint_attendance a ON a.party_id = p.id
              WHERE a.complaint_id = ?
                AND a.hearing_id = ?
                AND a.status = 'no_show'
                AND (a.justification_status IS NULL OR a.justification_status = '')");
        mysqli_stmt_bind_param($aq, "ii", $cid, $hearing_id);
        mysqli_stmt_execute($aq);
        $ar = mysqli_stmt_get_result($aq);
        $absent = [];
        while ($row = mysqli_fetch_assoc($ar)) $absent[] = $row;
        mysqli_stmt_close($aq);
        if (!$absent) {
            // Nothing pending → just close the window silently
            $u = mysqli_prepare($conn,
                "UPDATE complaint_hearings
                    SET explain_window_resolved_at = NOW()
                  WHERE id = ?");
            mysqli_stmt_bind_param($u, "i", $hearing_id);
            mysqli_stmt_execute($u);
            mysqli_stmt_close($u);
            continue;
        }

        $compUnjust = false;
        $respUnjust = false;

        foreach ($absent as $p) {
            $u = mysqli_prepare($conn,
                "UPDATE complaint_attendance
                    SET justification_status = 'unjustified',
                        justification_reason = 'AUTO: No explanation given within 5 days after the appearance date.',
                        justification_decided_at = NOW()
                  WHERE complaint_id = ? AND hearing_id = ? AND party_id = ?");
            mysqli_stmt_bind_param($u, "iii", $cid, $hearing_id, $p['id']);
            mysqli_stmt_execute($u);
            mysqli_stmt_close($u);

            if ($p['party_type'] === 'complainant') $compUnjust = true;
            if ($p['party_type'] === 'respondent')  $respUnjust  = true;

            $matched = !empty($p['matched_resident_id'])
                ? (int)$p['matched_resident_id']
                : tryMatchResident($conn, $p);
            if ($matched) {
                if ($p['party_type'] === 'complainant') {
                    $title = 'Complaint DISMISSED — No Explanation Given';
                    $msg   = "You failed to explain your absence within 5 days for complaint #{$c['reference_number']}. "
                           . "Per RA 7160, the complaint is DISMISSED and you are barred from filing in court.";
                    sendNotification($conn, 'resident', $matched, $cid, $title, $msg, 'error');
                    if (function_exists('pushToUser')) {
                        pushToUser($conn, $matched, $title, $msg,
                            '/BRITE/resident/dashboards/resident_dashboard.php');
                    }
                } else {
                    $title = 'Counterclaim Barred — No Explanation Given';
                    $msg   = "You failed to explain your absence within 5 days for complaint #{$c['reference_number']}. "
                           . "Per RA 7160, your counterclaim (if any) is DISMISSED and you are barred from filing in court. "
                           . "The case will proceed to Certification to File Action.";
                    sendNotification($conn, 'resident', $matched, $cid, $title, $msg, 'error');
                    if (function_exists('pushToUser')) {
                        pushToUser($conn, $matched, $title, $msg,
                            '/BRITE/resident/dashboards/resident_dashboard.php');
                    }
                }
            }
        }

        $newStatus = 'pangkat_scheduled';
        $certInfo = null;
        $certPdfPathForNotification = null;

        if ($compUnjust) {
            $newStatus = 'dismissed';
            $certRes = save_bar_action_cert(
                $conn, $cid, date('Y-m-d'),
                'Complainant failed to explain absence within 5 days (auto-unjustified).',
                'PANGKAT SECRETARY'
            );
            $certNo = 'CBA-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
            if (!empty($certRes['ok'])) {
                $uq = mysqli_prepare($conn,
                    "UPDATE complaints SET bar_action_cert_no = ?, bar_action_issued_at = NOW() WHERE id = ?");
                mysqli_stmt_bind_param($uq, "si", $certNo, $cid);
                mysqli_stmt_execute($uq);
                mysqli_stmt_close($uq);
                $certPdfPathForNotification = $certRes['pdf_path'] ?? null;
                $certInfo = ['form' => 'KP_FORM_23', 'cert_no' => $certNo, 'pdf_url' => $certRes['pdf_path']];
            }
                } elseif ($respUnjust) {
            $newStatus = 'unresolved';
            $certRes = save_bar_counterclaim_cert(
                $conn, $cid, date('Y-m-d'),
                'Respondent failed to explain absence within 5 days (auto-unjustified).',
                'PANGKAT SECRETARY'
            );
            $certNo = 'CBC-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
            if (!empty($certRes['ok'])) {
                $uq = mysqli_prepare($conn,
                    "UPDATE complaints SET bar_counterclaim_cert_no = ?, bar_counterclaim_issued_at = NOW() WHERE id = ?");
                mysqli_stmt_bind_param($uq, "si", $certNo, $cid);
                mysqli_stmt_execute($uq);
                mysqli_stmt_close($uq);
                $certPdfPathForNotification = $certRes['pdf_path'] ?? null;
                $certInfo = ['form' => 'KP_FORM_24', 'cert_no' => $certNo, 'pdf_url' => $certRes['pdf_path']];
            }

            // Auto-issue KP #22 (Certification to File Action — Chairman)
            $kp22Res = generate_kp_form_on_demand($conn, $cid, 'kp22', [
                'issued_by' => 'PANGKAT CHAIRPERSON',
            ]);
            if (!empty($kp22Res['ok'])) {
                logUpdate($conn, $cid, 'note', null, null,
                    "KP Form #22 auto-issued → {$kp22Res['pdf_path']}",
                    0, 'system');
            }

            // Notify both parties with a copy of KP #24
            $nq = mysqli_prepare($conn,
                "SELECT id, party_type, full_name, matched_resident_id, contact_number
                   FROM complaint_parties
                  WHERE complaint_id = ? AND party_type IN ('complainant','respondent')");
            mysqli_stmt_bind_param($nq, "i", $cid);
            mysqli_stmt_execute($nq);
            $nqr = mysqli_stmt_get_result($nq);
            while ($prow = mysqli_fetch_assoc($nqr)) {
                $matched = tryMatchResident($conn, $prow);
                if (!$matched) continue;
                $roleLabel = ($prow['party_type'] === 'complainant') ? 'Complainant' : 'Respondent';
                sendNotification($conn, 'resident', $matched, $cid,
                    'Certificate to File Action — KP #24 (' . $roleLabel . ')',
                    "The respondent failed to explain their absence for complaint #{$c['reference_number']}. "
                    . "The case proceeds to Certification to File Action.",
                    'info',
                    $certPdfPathForNotification);
                if (function_exists('pushToUser')) {
                    pushToUser($conn, $matched,
                        'Certificate to File Action Ready',
                        "Complaint #{$c['reference_number']}.",
                        '/BRITE/resident/dashboards/resident_dashboard.php',
                        $certPdfPathForNotification);
                }
            }
            mysqli_stmt_close($nq);
        }

        $u = mysqli_prepare($conn,
            "UPDATE complaints
                SET status = ?,
                    concil_absence_waiting_since   = NULL,
                    concil_absence_waiting_hearing = NULL,
                    concil_absence_appearance_at   = NULL,
                    concil_hearing_started_at      = NULL
              WHERE id = ?");
        mysqli_stmt_bind_param($u, "si", $newStatus, $cid);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);

        $uh = mysqli_prepare($conn,
            "UPDATE complaint_hearings
                SET explain_window_resolved_at = NOW(),
                    explain_window_auto_closed = 1
              WHERE id = ?");
        mysqli_stmt_bind_param($uh, "i", $hearing_id);
        mysqli_stmt_execute($uh);
        mysqli_stmt_close($uh);

        logUpdate($conn, $cid, 'resolution', 'pangkat_scheduled', $newStatus,
            "AUTO: Absent parties failed to explain within 5 days — marked UNJUSTIFIED. " .
            ($compUnjust ? 'Complainant unjustified → DISMISSED (KP #23). ' : '') .
            ($respUnjust ? 'Respondent unjustified → Certification (KP #24).' : ''),
            0, 'system');

        $creator = getComplaintCreator($conn, $cid);
        if ($creator) {
            if ($compUnjust) {
                sendNotification($conn, 'resident', $creator, $cid,
                    'Complaint Dismissed — No Explanation',
                    "The complainant did not explain their absence within 5 days for complaint #{$c['reference_number']}. The complaint is DISMISSED.",
                    'error',
                    $certPdfPathForNotification);   // ← NEW
            } elseif ($respUnjust) {
                sendNotification($conn, 'resident', $creator, $cid,
                    'Case Proceeding to Certification',
                    "The respondent did not explain their absence within 5 days for complaint #{$c['reference_number']}. You may now secure a Certificate to File Action.",
                    'info',
                    $certPdfPathForNotification);   // ← NEW
            }
        }

        notifyAdminsByRole($conn, ['lupon','secretary','captain'], $cid,
            'Auto-Unjustified Absence',
            "Complaint #{$c['reference_number']} — 5-day explain window expired. " .
            ($compUnjust ? 'Complainant unjustified → DISMISSED.' : '') .
            ($respUnjust  ? 'Respondent unjustified → certification.' : ''),
            $compUnjust ? 'error' : 'warning',
            null,
            $certPdfPathForNotification);   // ✅ NEW
    }
}

autoResolveConcilAbsences($conn);

/* ============================================================
   GET: Active Lupon members
   ============================================================ */
if ($action === 'get_active_lupon') {
    $q = mysqli_query($conn,
        "SELECT id, full_name, email
         FROM admin
         WHERE admin_role = 'lupon' AND is_active = 1
         ORDER BY full_name ASC");
    $list = [];
    if ($q) while ($row = mysqli_fetch_assoc($q)) $list[] = $row;
    echo json_encode(['success' => true, 'members' => $list]);
    exit;
}

/* ============================================================
   GET: Attendance state (mediation)
   ============================================================ */
if ($action === 'get_attendance_state' && $adminRole === 'captain') {
    $complaint_id = (int)($_GET['id'] ?? 0);
    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing id']); exit; }

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
    $meta['mediation_days_remaining'] = computeMediationDaysRemaining($meta['mediation_deadline'] ?? null);

   $hs = mysqli_prepare($conn,
    "SELECT * FROM complaint_hearings
     WHERE complaint_id = ?
       AND session_type = 'mediation'
       AND status = 'scheduled'
     ORDER BY session_number DESC, id DESC
     LIMIT 1");
mysqli_stmt_bind_param($hs, "i", $complaint_id);
mysqli_stmt_execute($hs);
$hearing = mysqli_fetch_assoc(mysqli_stmt_get_result($hs));
mysqli_stmt_close($hs);
$hearing_id = $hearing['id'] ?? 0;

// Fallback: if there's no scheduled hearing (e.g. already completed and
// we're resuming the absence-decision step), grab the most recent one
// that has an active (unresolved) explain window.
if (!$hearing_id) {
    $hs = mysqli_prepare($conn,
        "SELECT * FROM complaint_hearings
         WHERE complaint_id = ?
           AND session_type = 'mediation'
           AND explain_window_deadline IS NOT NULL
           AND explain_window_resolved_at IS NULL
         ORDER BY session_number DESC, id DESC
         LIMIT 1");
    mysqli_stmt_bind_param($hs, "i", $complaint_id);
    mysqli_stmt_execute($hs);
    $hearing = mysqli_fetch_assoc(mysqli_stmt_get_result($hs));
    mysqli_stmt_close($hs);
    $hearing_id = $hearing['id'] ?? 0;
}

// Last fallback: just the newest hearing
if (!$hearing_id) {
    $hearing_id = getLatestMediationHearingId($conn, $complaint_id);
    if ($hearing_id && !$hearing) {
        $hs = mysqli_prepare($conn, "SELECT * FROM complaint_hearings WHERE id = ?");
        mysqli_stmt_bind_param($hs, "i", $hearing_id);
        mysqli_stmt_execute($hs);
        $hearing = mysqli_fetch_assoc(mysqli_stmt_get_result($hs));
        mysqli_stmt_close($hs);
    }
}

    $ps = mysqli_prepare($conn,
        "SELECT p.id, p.party_type AS role, p.full_name, p.contact_number, p.matched_resident_id,
                p.notice_of_hearing_sent_at, p.summons_sent_at, p.notice_pdf_path,
                COALESCE(a.status, 'pending') AS status,
                COALESCE(a.nudge_count, 0) AS nudge_count,
                a.nudge_last_at
         FROM complaint_parties p
         LEFT JOIN complaint_attendance a
                ON a.party_id = p.id
               AND a.complaint_id = p.complaint_id
               AND a.hearing_id = ?
         WHERE p.complaint_id = ?
           AND p.party_type IN ('complainant','respondent')
         ORDER BY FIELD(p.party_type, 'complainant','respondent'), p.id");
    mysqli_stmt_bind_param($ps, "ii", $hearing_id, $complaint_id);
    mysqli_stmt_execute($ps);
    $pr = mysqli_stmt_get_result($ps);
    $parties = [];
    while ($row = mysqli_fetch_assoc($pr)) {
        $st = $row['status'] ?? 'pending';
        if (in_array($st, ['present','late'])) $row['status'] = 'pending';
        elseif ($st === 'absent') $row['status'] = 'no_show';
        $parties[] = $row;
    }
    mysqli_stmt_close($ps);

    // 5-day explain deadline (mediation-level) — lives on the latest mediation hearing
    $explainDeadline = null;
    if (!empty($hearing['explain_window_deadline'])) {
        $explainDeadline = $hearing['explain_window_deadline'];
    }

    echo json_encode([
        'success'   => true,
        'complaint' => $meta,
        'hearing'   => $hearing ?: null,
        'parties'   => $parties,
        'explain_deadline' => $explainDeadline,
    ]);
    exit;
}

/* ============================================================
   POST: Save attendance (mediation level)
   ============================================================ */
if ($action === 'save_attendance' && $adminRole === 'captain') {
    $complaint_id  = (int)($_POST['complaint_id'] ?? 0);
    $rows          = json_decode($_POST['attendance'] ?? '[]', true);
    $appearance_at = trim($_POST['appearance_at'] ?? '');

    if (!$complaint_id || !is_array($rows) || !$rows) {
        echo json_encode(['success'=>false,'message'=>'No attendance data.']); exit;
    }

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }

    $hearing_id = getLatestMediationHearingId($conn, $complaint_id);

    $hRow = null;
    if ($hearing_id) {
        $hs = mysqli_prepare($conn,
            "SELECT id, hearing_date, hearing_time, location
             FROM complaint_hearings WHERE id = ?");
        mysqli_stmt_bind_param($hs, "i", $hearing_id);
        mysqli_stmt_execute($hs);
        $hRow = mysqli_fetch_assoc(mysqli_stmt_get_result($hs));
        mysqli_stmt_close($hs);
    }
    $missed_hearing_date = $hRow['hearing_date'] ?? ($meta['hearing_date'] ?? date('Y-m-d'));
    $missed_hearing_time = $hRow['hearing_time'] ?? ($meta['hearing_time'] ?? null);

    $allowed = ['attend','no_show'];
    $absent = [];
    foreach ($rows as $r) {
        $pid = (int)($r['party_id'] ?? 0);
        $st  = $r['status'] ?? '';
        if (!$pid || !in_array($st, $allowed)) continue;

        $up = mysqli_prepare($conn,
            "INSERT INTO complaint_attendance
                (complaint_id, hearing_id, party_id, status, recorded_by, recorded_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                recorded_by = VALUES(recorded_by),
                recorded_at = NOW()");
        mysqli_stmt_bind_param($up, "iiisi", $complaint_id, $hearing_id, $pid, $st, $admin_id);
        mysqli_stmt_execute($up);
        mysqli_stmt_close($up);

        if ($st === 'no_show') {
            $pi = mysqli_prepare($conn,
                "SELECT id, full_name, party_type, matched_resident_id, contact_number
                 FROM complaint_parties WHERE id = ?");
            mysqli_stmt_bind_param($pi, "i", $pid);
            mysqli_stmt_execute($pi);
            $p = mysqli_fetch_assoc(mysqli_stmt_get_result($pi));
            mysqli_stmt_close($pi);
            if ($p) $absent[] = $p;
        }
    }

    if (!empty($absent)) {
        if ($appearance_at === '') {
            echo json_encode([
                'success' => false,
                'message' => 'You must set a date and time for the absent party to appear and explain.',
                'need_appearance' => true,
            ]);
            exit;
        }

        $appTs = strtotime($appearance_at);
        if ($appTs === false) {
            echo json_encode(['success'=>false,'message'=>'Invalid appearance date/time.']); exit;
        }
        $appHour = (int)date('G', $appTs);
        if ($appHour < 8 || $appHour >= 18) {
            echo json_encode(['success'=>false,'message'=>'Appearance time must be between 8:00 AM and 6:00 PM.']); exit;
        }
        $appearance_sql = date('Y-m-d H:i:s', $appTs);
        $explainDeadlineSql = date('Y-m-d H:i:s', strtotime($appearance_sql . ' +' . EXPLAIN_WINDOW_DAYS . ' days'));

        // Update complaint flags (backward compat) — kept for legacy UI hints
        $upd = mysqli_prepare($conn,
            "UPDATE complaints
                SET absence_waiting_since     = NOW(),
                    absence_waiting_hearing   = ?,
                    absence_appearance_at     = ?
              WHERE id = ?");
        mysqli_stmt_bind_param($upd, "isi", $hearing_id, $appearance_sql, $complaint_id);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);

        // Write the explain window on the hearing itself
        $uhearing = mysqli_prepare($conn,
            "UPDATE complaint_hearings
                SET explain_window_started_at  = NOW(),
                    explain_window_deadline    = ?,
                    explain_window_resolved_at = NULL,
                    explain_window_auto_closed = 0
              WHERE id = ?");
        mysqli_stmt_bind_param($uhearing, "si", $explainDeadlineSql, $hearing_id);
        mysqli_stmt_execute($uhearing);
        mysqli_stmt_close($uhearing);

        $appearanceDateOnly = date('Y-m-d', $appTs);
$appearanceTimeOnly = date('H:i',   $appTs);

// ── Which side(s) failed to appear?
$complainantAbsent = false;
$respondentAbsent  = false;
foreach ($absent as $ap) {
    if ($ap['party_type'] === 'complainant') $complainantAbsent = true;
    if ($ap['party_type'] === 'respondent')  $respondentAbsent  = true;
}
if ($complainantAbsent && $respondentAbsent)     $noShowOutcome = 'both_no_show';
elseif ($complainantAbsent)                      $noShowOutcome = 'complainant_no_show';
elseif ($respondentAbsent)                       $noShowOutcome = 'respondent_no_show';
else                                             $noShowOutcome = 'parties_no_show';

$noShowLabel = ($noShowOutcome === 'complainant_no_show') ? 'Complainant did not appear'
             : (($noShowOutcome === 'respondent_no_show')  ? 'Respondent did not appear'
             : (($noShowOutcome === 'both_no_show')        ? 'Both parties did not appear'
             : 'A party did not appear'));
$appearPretty = date('F j, Y g:i A', $appTs);

// ── Close the hearing with the specific outcome (NOT 'no_settlement')
$closeHearing = mysqli_prepare($conn,
    "UPDATE complaint_hearings
        SET status = 'completed',
            outcome = ?,
            completed_at = NOW(),
            summary = CONCAT(
                COALESCE(summary,''),
                '\n[NO-SHOW] ', ?, '. Appearance set for ', ?
            )
      WHERE id = ?");
mysqli_stmt_bind_param($closeHearing, "sssi", $noShowOutcome, $noShowLabel, $appearPretty, $hearing_id);
mysqli_stmt_execute($closeHearing);
mysqli_stmt_close($closeHearing);



    $noticeResult = save_all_kp_failure_notices(
    $conn,
    $complaint_id,
    $appearanceDateOnly,
    $appearanceTimeOnly,
    $missed_hearing_date,
    $missed_hearing_time,
    $absent,
    $adminName ?: 'BARANGAY CAPTAIN',
    'mediation'          // ← ADD THIS
);

        $noticesOut = [];
        foreach ($noticeResult['notices'] as $n) {
            $pid       = (int)$n['party_id'];
            $pdfPath   = $n['pdf_path'] ?? null;
            $ok        = !empty($n['ok']);
            $formLabel = ($n['form_tag'] === 'kp18')
                            ? 'KP Form #18 — Notice of Hearing (Re: Failure to Appear)'
                            : 'KP Form #19 — Notice of Hearing (Re: Failure to Appear)';

            if ($ok && $pdfPath) {
                $u = mysqli_prepare($conn,
                    "UPDATE complaint_attendance
                        SET kp18_or_19_pdf_path     = ?,
                            kp18_or_19_generated_at = NOW()
                      WHERE complaint_id = ? AND hearing_id = ? AND party_id = ?");
                mysqli_stmt_bind_param($u, "siii", $pdfPath, $complaint_id, $hearing_id, $pid);
                mysqli_stmt_execute($u);
                mysqli_stmt_close($u);
            }

            $pidRows = null;
            foreach ($absent as $ap) if ((int)$ap['id'] === $pid) { $pidRows = $ap; break; }

            $notifiedResidentId = null;
            $printRequired      = true;

            if ($pidRows) {
                $matched = tryMatchResident($conn, $pidRows);
                if ($matched) {
                    $notifiedResidentId = $matched;
                    $printRequired      = false;

                    $us = mysqli_prepare($conn,
                        "UPDATE complaint_parties SET matched_resident_id = ? WHERE id = ?");
                    mysqli_stmt_bind_param($us, "ii", $matched, $pid);
                    mysqli_stmt_execute($us);
                    mysqli_stmt_close($us);

                    $roleLabel = ($n['party_type'] === 'complainant') ? 'complainant' : 'respondent';
                    $title = 'You failed to appear — Explain before the Punong Barangay';
                    $msg   = "You did not appear at the mediation for complaint #{$meta['reference_number']} "
                           . "as a {$roleLabel}. Per RA 7160 / KP Form " . strtoupper($n['form_tag'])
                           . ", you are required to appear before the Punong Barangay on "
                           . date('F j, Y', $appTs) . " at " . date('g:i A', $appTs)
                           . " to explain why you failed to appear. "
                           . "If you do not explain within " . EXPLAIN_WINDOW_DAYS . " days after that date, "
                           . "your absence will be automatically declared UNJUSTIFIED.";

                    // ✅ NEW: pass the PDF path so the resident can open the KP form directly
                    sendNotification($conn, 'resident', $matched, $complaint_id, $title, $msg, 'warning', $pdfPath);
                    if (function_exists('pushToUser')) {
                        pushToUser($conn, $matched, $title, $msg,
                            '/BRITE/resident/dashboards/resident_dashboard.php',
                            $pdfPath);  // ✅ NEW
                    }
                } else {
                    $us = mysqli_prepare($conn,
                        "UPDATE complaint_parties SET notified_via = 'print' WHERE id = ?");
                    mysqli_stmt_bind_param($us, "i", $pid);
                    mysqli_stmt_execute($us);
                    mysqli_stmt_close($us);
                }
            }

            $noticesOut[] = [
                'party_id'   => $pid,
                'party_type' => $n['party_type'],
                'role'       => $n['role'],
                'name'       => $n['name'],
                'form'       => strtoupper($n['form_tag']),
                'form_label' => $formLabel,
                'pdf_url'    => $pdfPath,
                'ok'         => $ok,
                'error'      => $n['error'] ?? null,
                'notified'   => $notifiedResidentId ? true : false,
                'print'      => $printRequired,
            ];
        }

        $printList = [];
        $firstKp1819Pdf = null;
        foreach ($noticesOut as $n) {
            if (!empty($n['print'])) $printList[] = $n['role'] . ' ' . $n['name'];
            if ($firstKp1819Pdf === null && !empty($n['pdf_url'])) $firstKp1819Pdf = $n['pdf_url'];
        }
        if (!empty($printList)) {
            notifyAdminsByRole($conn, ['secretary'], $complaint_id,
                '⚠ PRINT REQUIRED — Failure-to-Appear Notice',
                "Complaint #{$meta['reference_number']} — "
                . count($printList) . " part" . (count($printList) > 1 ? 'ies' : 'y')
                . " without resident account. Print & hand-deliver before "
                . date('F j, Y g:i A', $appTs) . ":\n• " . implode("\n• ", $printList),
                'warning',
                null,
                $firstKp1819Pdf);   // ✅ NEW
        }

        logUpdate($conn, $complaint_id, 'note', null, null,
            "[Mediation Hearing #{$meta['mediation_session_count']}] No-show registered. Appearance set for "
            . date('F j, Y g:i A', $appTs) . ". KP #18/#19 issued. "
            . EXPLAIN_WINDOW_DAYS . "-day explain deadline: " . date('F j, Y g:i A', strtotime($explainDeadlineSql)) . ".",
            $admin_id, 'captain');

        echo json_encode([
            'success'         => true,
            'absent_parties'  => array_map(fn($a) => ['id'=>$a['id'], 'full_name'=>$a['full_name'], 'role'=>$a['party_type']], $absent),
            'waiting'         => true,
            'appearance_at'   => $appearance_sql,
            'outcome'         => $noShowOutcome,
            'explain_deadline'=> $explainDeadlineSql,
            'notices'         => $noticesOut,
            'notices_ok'      => $noticeResult['count_ok'],
            'notices_failed'  => $noticeResult['count_failed'],
        ]);
        exit;
    }

    $upd = mysqli_prepare($conn,
        "UPDATE complaints
            SET absence_waiting_since   = NULL,
                absence_waiting_hearing = NULL,
                absence_appearance_at   = NULL
          WHERE id = ?");
    mysqli_stmt_bind_param($upd, "i", $complaint_id);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    logUpdate($conn, $complaint_id, 'note', null, null,
        "[Mediation Hearing #{$meta['mediation_session_count']}] All parties attended.",
        $admin_id, 'captain');

    echo json_encode([
        'success'         => true,
        'absent_parties'  => [],
        'waiting'         => false,
    ]);
    exit;
}

/* ============================================================
   POST: Resolve absence decisions (mediation level)
   ============================================================ */
if ($action === 'resolve_absence_decisions' && $adminRole === 'captain') {
    $complaint_id   = (int)($_POST['complaint_id'] ?? 0);
    $hearing_id     = (int)($_POST['hearing_id']   ?? 0);
    $decisions      = json_decode($_POST['decisions'] ?? '[]', true);
    $session_record_json = trim($_POST['session_record'] ?? '');
    $outcome_choice = trim($_POST['outcome_choice'] ?? 'end_mediation');

    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint_id.']); exit; }
    if (!is_array($decisions) || !$decisions) { echo json_encode(['success'=>false,'message'=>'No decisions given.']); exit; }
    if (!$hearing_id) $hearing_id = getLatestMediationHearingId($conn, $complaint_id);
    if (!$hearing_id) { echo json_encode(['success'=>false,'message'=>'No mediation hearing found.']); exit; }

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }
    if (!in_array($meta['status'], ['for_mediation','mediation_scheduled'], true)) {
        echo json_encode(['success'=>false,'message'=>'This complaint is not in mediation.']); exit;
    }

    $complainantUnjustified = false;
    $respondentUnjustified  = false;

    mysqli_begin_transaction($conn);
    try {
        $upd = mysqli_prepare($conn,
            "UPDATE complaint_attendance
                SET justification_status     = ?,
                    justification_reason     = ?,
                    justification_decided_at = NOW(),
                    appeared_at              = ?,
                    appearance_notes         = ?
              WHERE complaint_id = ?
                AND hearing_id   = ?
                AND party_id     = ?");

        foreach ($decisions as $d) {
            $pid       = (int)($d['party_id'] ?? 0);
            $status    = $d['status'] ?? '';
            $reason    = trim($d['reason'] ?? '');
            $appeared  = !empty($d['appeared']) ? 1 : 0;
            $appNotes  = trim($d['appearance_notes'] ?? '');
            if (!$pid) continue;
            if (!in_array($status, ['justified','unjustified'], true)) continue;

            $ptype = $d['party_type'] ?? '';
            if ($status === 'unjustified') {
                if ($ptype === 'complainant') $complainantUnjustified = true;
                if ($ptype === 'respondent')  $respondentUnjustified  = true;
            }

            $appearedAt = $appeared ? date('Y-m-d H:i:s') : null;

            mysqli_stmt_bind_param($upd, "ssissii",
                $status, $reason,
                $appearedAt, $appNotes,
                $complaint_id, $hearing_id, $pid);
            mysqli_stmt_execute($upd);
        }
        mysqli_stmt_close($upd);

        if ($session_record_json !== '') {
            $sr = json_decode($session_record_json, true);
            if (is_array($sr)) {
                $srJson = json_encode($sr, JSON_UNESCAPED_UNICODE);
                $usr = mysqli_prepare($conn,
                    "UPDATE complaint_hearings SET session_record = ? WHERE id = ?");
                mysqli_stmt_bind_param($usr, "si", $srJson, $hearing_id);
                mysqli_stmt_execute($usr);
                mysqli_stmt_close($usr);
            }
        }

        if ($complainantUnjustified) {
            $outcome   = 'dismissed_complaint';
            $newStatus = 'dismissed';
        } elseif ($respondentUnjustified) {
            // Only one path exists now: end mediation → Pangkat
            $outcome   = 'end_to_pangkat';
            $newStatus = 'failed_mediation';
        } else {
            $outcome   = 'reschedule';
            $newStatus = 'mediation_scheduled';
        }

        $updCase = mysqli_prepare($conn,
            "UPDATE complaints
                SET status                  = ?,
                    absence_waiting_since   = NULL,
                    absence_waiting_hearing = NULL,
                    absence_appearance_at   = NULL,
                    mediation_last_result   = 'no_settlement'
              WHERE id = ?");
        mysqli_stmt_bind_param($updCase, "si", $newStatus, $complaint_id);
        mysqli_stmt_execute($updCase);
        mysqli_stmt_close($updCase);

// Close the explain window on the hearing.
// For the JUSTIFIED (reschedule) path, DO NOT mark the hearing as completed yet —
// the follow-up captain_summon_and_schedule call will close it atomically
// while inserting the new hearing, so the audit trail stays consistent.
$absSummary = $complainantUnjustified
    ? 'Complainant unjustifiably absent — complaint dismissed.'
    : ($respondentUnjustified
        ? 'Respondent unjustifiably absent — mediation ended, case to Pangkat.'
        : 'All absences justified — rescheduled with warning.');

if ($outcome === 'reschedule') {
    // Only resolve the explain window; leave the hearing 'scheduled' so the
    // subsequent summon/schedule call can close it.
    $uh = mysqli_prepare($conn,
        "UPDATE complaint_hearings
            SET explain_window_resolved_at = NOW(),
                summary = CONCAT(COALESCE(summary,''), '\n[Absence decision] ', ?)
          WHERE id = ?");
    mysqli_stmt_bind_param($uh, "si", $absSummary, $hearing_id);
    mysqli_stmt_execute($uh);
    mysqli_stmt_close($uh);
} else {
    $uh = mysqli_prepare($conn,
        "UPDATE complaint_hearings
            SET status='completed', outcome='no_settlement', completed_at=NOW(),
                explain_window_resolved_at = NOW(),
                summary = CONCAT(COALESCE(summary,''), '\n[Absence decision] ', ?)
          WHERE id = ?");
    mysqli_stmt_bind_param($uh, "si", $absSummary, $hearing_id);
    mysqli_stmt_execute($uh);
    mysqli_stmt_close($uh);
}

        logUpdate($conn, $complaint_id, 'status_change', $meta['status'], $newStatus,
            "[Mediation Hearing #{$meta['mediation_session_count']} — Absence Decision] " . $absSummary,
            $admin_id, 'captain');

        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log('resolve_absence_decisions failed: ' . $e->getMessage());
        echo json_encode(['success'=>false,'message'=>'Could not save decisions.']);
        exit;
    }

    $certInfo = null;
    $certPdfPathForNotification = null;

    if ($outcome === 'dismissed_complaint') {
        $certRes = save_bar_action_cert(
            $conn, $complaint_id, date('Y-m-d'),
            'Complainant failed to appear without justifiable cause.',
            $adminName ?: 'BARANGAY SECRETARY'
        );
        $certNo = 'CBA-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
        if (!empty($certRes['ok'])) {
            $uq = mysqli_prepare($conn,
                "UPDATE complaints SET bar_action_cert_no = ?, bar_action_issued_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($uq, "si", $certNo, $complaint_id);
            mysqli_stmt_execute($uq);
            mysqli_stmt_close($uq);

            $certPdfPathForNotification = $certRes['pdf_path'] ?? null;
            $certInfo = [
                'form'      => 'KP_FORM_23',
                'label'     => 'KP Form #23 — Certification to Bar Action',
                'cert_no'   => $certNo,
                'pdf_url'   => $certRes['pdf_path'],
            ];
            notifyAdminsByRole($conn, ['secretary'], $complaint_id,
                'Certification to Bar Action — Ready',
                "Complaint #{$meta['reference_number']} — KP Form #23 was auto-generated ($certNo).",
                'warning');
        }
    } elseif ($outcome === 'end_to_pangkat') {
        $certRes = save_bar_counterclaim_cert(
            $conn, $complaint_id, date('Y-m-d'),
            'Respondent failed to appear without justifiable cause.',
            $adminName ?: 'BARANGAY SECRETARY'
        );
        $certNo = 'CBC-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
        if (!empty($certRes['ok'])) {
            $uq = mysqli_prepare($conn,
                "UPDATE complaints SET bar_counterclaim_cert_no = ?, bar_counterclaim_issued_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($uq, "si", $certNo, $complaint_id);
            mysqli_stmt_execute($uq);
            mysqli_stmt_close($uq);

            $certPdfPathForNotification = $certRes['pdf_path'] ?? null;
            $certInfo = [
                'form'      => 'KP_FORM_24',
                'label'     => 'KP Form #24 — Certification to Bar Counterclaim',
                'cert_no'   => $certNo,
                'pdf_url'   => $certRes['pdf_path'],
            ];
        }
        notifyAdminsByRole($conn, ['captain'], $complaint_id,
            'Constitute Pangkat — Schedule Constitution Meeting',
            "Complaint #{$meta['reference_number']} — schedule the Pangkat constitution meeting.",
            'warning');
    }

    $complainantTitle = '';
    $complainantMsg   = '';
    if ($outcome === 'dismissed_complaint') {
        $complainantTitle = 'Complaint Dismissed';
        $complainantMsg   = "Complaint #{$meta['reference_number']} was DISMISSED because you did not appear without justifiable cause.";
    } elseif ($outcome === 'end_to_pangkat') {
        $complainantTitle = 'Case Proceeding to Pangkat';
        $complainantMsg   = "The respondent did not appear for complaint #{$meta['reference_number']}. The case will now proceed to the Pangkat.";
    } else {
        $complainantTitle = 'Mediation Rescheduled — Strict Warning';
        $complainantMsg   = "You missed your scheduled mediation for complaint #{$meta['reference_number']}.";
    }

    foreach ($decisions as $d) {
        $pid   = (int)($d['party_id'] ?? 0);
        $st    = $d['status'] ?? '';
        $ptype = $d['party_type'] ?? '';
        if (!$pid || $st !== 'unjustified') continue;

        $pq = mysqli_prepare($conn,
            "SELECT id, full_name, party_type, matched_resident_id, contact_number
               FROM complaint_parties WHERE id = ?");
        mysqli_stmt_bind_param($pq, "i", $pid);
        mysqli_stmt_execute($pq);
        $prow = mysqli_fetch_assoc(mysqli_stmt_get_result($pq));
        mysqli_stmt_close($pq);
        if (!$prow) continue;

        $targetResident = tryMatchResident($conn, $prow);
        if (!$targetResident) continue;

        if ($ptype === 'complainant') {
            $title = 'Complaint Dismissed — You Did Not Appear';
            $msg   = "You did not appear at the mediation for complaint #{$meta['reference_number']} without justifiable cause.";
            $ntype = 'error';
        } else {
            $title = 'Counterclaim Barred — You Did Not Appear';
            $msg   = "You did not appear at the mediation for complaint #{$meta['reference_number']} without justifiable cause. The case will now proceed to the Pangkat.";
            $ntype = 'error';
        }

        // ✅ NEW: pass the cert PDF path so the resident can open it directly
        sendNotification($conn, 'resident', $targetResident, $complaint_id, $title, $msg, $ntype, $certPdfPathForNotification);
        if (function_exists('pushToUser')) {
            pushToUser($conn, $targetResident, $title, $msg,
                '/BRITE/resident/dashboards/resident_dashboard.php',
                $certPdfPathForNotification);  // ✅ NEW
        }
    }

    $creator = getComplaintCreator($conn, $complaint_id);
    if ($creator && $complainantTitle) {
        sendNotification($conn, 'resident', $creator, $complaint_id,
            $complainantTitle, $complainantMsg,
            $outcome === 'dismissed_complaint' ? 'error' : 'warning',
            $certPdfPathForNotification);   // ✅ NEW
        if (function_exists('pushToUser')) {
            pushToUser($conn, $creator, $complainantTitle,
                "Complaint #{$meta['reference_number']}.",
                '/BRITE/resident/dashboards/resident_dashboard.php',
                $certPdfPathForNotification);  // ✅ NEW
        }
    }

    echo json_encode([
        'success'          => true,
        'outcome'          => $outcome,
        'status'           => $newStatus,
        'cert_info'        => $certInfo,
        'reference_number' => $meta['reference_number'] ?? '',
    ]);
    exit;
}

/* ============================================================
   POST: Schedule Pangkat constitution meeting (KP Form #10)
   ============================================================ */
if ($action === 'schedule_pangkat_constitution' && $adminRole === 'captain') {
    $complaint_id  = (int)($_POST['complaint_id'] ?? 0);
    $constitution_date = trim($_POST['constitution_date'] ?? '');
    $constitution_time = trim($_POST['constitution_time'] ?? '');

    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint_id.']); exit; }
    if (!$constitution_date) { echo json_encode(['success'=>false,'message'=>'Missing constitution date.']); exit; }
    if (!$constitution_time) { echo json_encode(['success'=>false,'message'=>'Missing constitution time.']); exit; }

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }

    if ($meta['status'] !== 'failed_mediation') {
        echo json_encode(['success'=>false,'message'=>'This complaint is not ready for Pangkat constitution.']); exit;
    }

    $ts = strtotime($constitution_date . ' ' . $constitution_time);
    if ($ts === false) { echo json_encode(['success'=>false,'message'=>'Invalid date/time.']); exit; }
    $appHour = (int)date('G', $ts);
    if ($appHour < 8 || $appHour >= 18) {
        echo json_encode(['success'=>false,'message'=>'Constitution time must be between 8:00 AM and 6:00 PM.']); exit;
    }
    $constitution_sql = date('Y-m-d H:i:s', $ts);

    mysqli_begin_transaction($conn);
    try {
        $u = mysqli_prepare($conn,
            "UPDATE complaints
                SET pangkat_constitution_at = ?,
                    status = 'pangkat_constitution_scheduled',
                    pangkat_constitution_reminder_sent = 0
              WHERE id = ?");
        mysqli_stmt_bind_param($u, "si", $constitution_sql, $complaint_id);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);

        $niceDate = date('F j, Y', $ts);
        $niceTime = date('g:i A', $ts);
        logUpdate($conn, $complaint_id, 'status_change', $meta['status'], 'pangkat_constitution_scheduled',
            "Pangkat constitution meeting scheduled for $niceDate at $niceTime. Issuing KP Form #10 to both parties.",
            $admin_id, 'captain');

        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success'=>false,'message'=>'Could not schedule the meeting.']); exit;
    }

    $dateOnly = date('Y-m-d', $ts);
    $timeOnly = date('H:i', $ts);

    $noticeResult = save_all_kp_form_10(
        $conn,
        $complaint_id,
        $dateOnly,
        $timeOnly,
        $adminName ?: 'BARANGAY CAPTAIN'
    );

    $noticesOut = [];
    foreach ($noticeResult['notices'] as $n) {
        $pid     = (int)$n['party_id'];
        $pdfPath = $n['pdf_path'] ?? null;
        $ok      = !empty($n['ok']);

        $pq = mysqli_prepare($conn,
            "SELECT id, full_name, party_type, matched_resident_id, contact_number
               FROM complaint_parties WHERE id = ?");
        mysqli_stmt_bind_param($pq, "i", $pid);
        mysqli_stmt_execute($pq);
        $prow = mysqli_fetch_assoc(mysqli_stmt_get_result($pq));
        mysqli_stmt_close($pq);
        if (!$prow) continue;

        $matched = tryMatchResident($conn, $prow);
        $printRequired = true;
        $notifiedResidentId = null;

        if ($matched) {
            $notifiedResidentId = $matched;
            $printRequired = false;

            $us = mysqli_prepare($conn,
                "UPDATE complaint_parties
                    SET matched_resident_id = ?, kp10_notified_via = 'push'
                  WHERE id = ?");
            mysqli_stmt_bind_param($us, "ii", $matched, $pid);
            mysqli_stmt_execute($us);
            mysqli_stmt_close($us);

            $title = 'Pangkat Constitution Meeting — Notice';
            $msg   = "You are required to appear before the Punong Barangay on "
                   . date('F j, Y', $ts) . " at " . date('g:i A', $ts)
                   . " for the constitution of the Pangkat for complaint #{$meta['reference_number']}.";

            // ✅ NEW: pass the PDF path
            sendNotification($conn, 'resident', $matched, $complaint_id, $title, $msg, 'hearing', $pdfPath);
            if (function_exists('pushToUser')) {
                pushToUser($conn, $matched, $title, $msg,
                    '/BRITE/resident/dashboards/resident_dashboard.php',
                    $pdfPath);  // ✅ NEW
            }
        } else {
            $us = mysqli_prepare($conn,
                "UPDATE complaint_parties SET kp10_notified_via = 'print' WHERE id = ?");
            mysqli_stmt_bind_param($us, "i", $pid);
            mysqli_stmt_execute($us);
            mysqli_stmt_close($us);
        }

        $noticesOut[] = [
            'party_id'   => $pid,
            'party_type' => $n['party_type'],
            'role'       => $n['role'],
            'name'       => $n['name'],
            'form'       => 'KP_FORM_10',
            'form_label' => 'KP Form #10 — Notice for Constitution of Pangkat',
            'pdf_url'    => $pdfPath,
            'ok'         => $ok,
            'error'      => $n['error'] ?? null,
            'notified'   => $notifiedResidentId ? true : false,
            'print'      => $printRequired,
        ];
    }

    $printList = [];
    $firstKp10Pdf = null;
    foreach ($noticesOut as $n) {
        if (!empty($n['print'])) $printList[] = $n['role'] . ' ' . $n['name'];
        if ($firstKp10Pdf === null && !empty($n['pdf_url'])) $firstKp10Pdf = $n['pdf_url'];
    }
    if (!empty($printList)) {
        notifyAdminsByRole($conn, ['secretary'], $complaint_id,
            '⚠ PRINT REQUIRED — KP Form #10',
            "Complaint #{$meta['reference_number']} — constitution meeting on "
            . date('F j, Y g:i A', $ts) . ". Print & hand-deliver KP Form #10:\n• "
            . implode("\n• ", $printList),
            'warning',
            null,
            $firstKp10Pdf);   // ✅ NEW
    }

    echo json_encode([
        'success'          => true,
        'constitution_at'  => $constitution_sql,
        'constitution_date'=> date('F j, Y', $ts),
        'constitution_time'=> date('g:i A', $ts),
        'status'           => 'pangkat_constitution_scheduled',
        'notices'          => $noticesOut,
        'notices_ok'       => $noticeResult['count_ok'],
        'notices_failed'   => $noticeResult['count_failed'],
    ]);
    exit;
}

/* ============================================================
   GET: Mediation resume state
   ============================================================ */
if ($action === 'get_mediation_resume_state' && $adminRole === 'captain') {
    $complaint_id = (int)($_GET['complaint_id'] ?? $_POST['complaint_id'] ?? 0);
    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint_id.']); exit; }

    $s = mysqli_prepare($conn,
        "SELECT id, status, mediation_session_count, mediation_deadline,
                absence_waiting_since, absence_waiting_hearing, absence_appearance_at,
                pangkat_constitution_at
           FROM complaints WHERE id = ?");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $case = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    if (!$case) { echo json_encode(['success'=>false,'message'=>'Complaint not found.']); exit; }

    $step = 'attendance';
    $waiting = false;

    if (!empty($case['absence_appearance_at'])) {
        $waiting = true;
        $step    = 'absence_decision';
    } elseif (in_array($case['status'], ['for_mediation','mediation_scheduled'], true)) {
        $step = 'attendance';
    } elseif ($case['status'] === 'failed_mediation') {
        $step = 'constitute_pangkat';
    } elseif ($case['status'] === 'pangkat_constitution_scheduled') {
        $step = 'pangkat_constitution_waiting';
    }
    // ── Detect "justified absences recorded, but next hearing not scheduled yet" ──
    // Happens when the captain saved a Justified decision but closed the modal
    // before picking the new date. Reopening lands him right back on the schedule screen.
    if ($case['status'] === 'mediation_scheduled' && empty($case['absence_appearance_at'])) {
        $hasScheduledHearing = false;
        $sh = mysqli_prepare($conn,
            "SELECT COUNT(*) AS c
               FROM complaint_hearings
              WHERE complaint_id = ?
                AND session_type = 'mediation'
                AND status = 'scheduled'");
        if ($sh) {
            mysqli_stmt_bind_param($sh, "i", $complaint_id);
            mysqli_stmt_execute($sh);
            $hasScheduledHearing = ((int)mysqli_fetch_assoc(mysqli_stmt_get_result($sh))['c']) > 0;
            mysqli_stmt_close($sh);
        }
        if (!$hasScheduledHearing) {
            $step = 'schedule_justified';
        }
    }
   // Prefer the hearing that has an active (unresolved) explain window
$hearing_id = (int)($case['absence_waiting_hearing'] ?? 0);

if (!$hearing_id) {
    // Try to find a scheduled hearing first (this is the current live session)
    $sh = mysqli_prepare($conn,
        "SELECT id FROM complaint_hearings
          WHERE complaint_id = ?
            AND session_type = 'mediation'
            AND status = 'scheduled'
          ORDER BY session_number DESC, id DESC
          LIMIT 1");
    mysqli_stmt_bind_param($sh, "i", $complaint_id);
    mysqli_stmt_execute($sh);
    $shrow = mysqli_fetch_assoc(mysqli_stmt_get_result($sh));
    mysqli_stmt_close($sh);
    $hearing_id = (int)($shrow['id'] ?? 0);
}

if (!$hearing_id) {
    // Fallback: latest hearing with an unresolved explain window
    $eh2 = mysqli_prepare($conn,
        "SELECT id FROM complaint_hearings
          WHERE complaint_id = ?
            AND session_type = 'mediation'
            AND explain_window_deadline IS NOT NULL
            AND explain_window_resolved_at IS NULL
          ORDER BY session_number DESC, id DESC
          LIMIT 1");
    mysqli_stmt_bind_param($eh2, "i", $complaint_id);
    mysqli_stmt_execute($eh2);
    $ehrow = mysqli_fetch_assoc(mysqli_stmt_get_result($eh2));
    mysqli_stmt_close($eh2);
    $hearing_id = (int)($ehrow['id'] ?? 0);
}

if (!$hearing_id) {
    $hearing_id = getLatestMediationHearingId($conn, $complaint_id);
}

    // Read the mediation explain deadline from the hearing (if any)
    $explainDeadline = null;
    if ($hearing_id) {
        $eh = mysqli_prepare($conn,
            "SELECT explain_window_deadline FROM complaint_hearings WHERE id = ?");
        mysqli_stmt_bind_param($eh, "i", $hearing_id);
        mysqli_stmt_execute($eh);
        $erow = mysqli_fetch_assoc(mysqli_stmt_get_result($eh));
        mysqli_stmt_close($eh);
        if (!empty($erow['explain_window_deadline'])) $explainDeadline = $erow['explain_window_deadline'];
    }

    $prevHearings = [];
    $phStmt = mysqli_prepare($conn,
        "SELECT h.id, h.session_number, h.hearing_date, h.hearing_time,
                h.location, h.status, h.outcome, h.summary, h.session_record,
                (SELECT COUNT(*) FROM complaint_attendance a WHERE a.hearing_id = h.id AND a.status = 'attend')   AS attended,
                (SELECT COUNT(*) FROM complaint_attendance a WHERE a.hearing_id = h.id AND a.status = 'no_show') AS absent
           FROM complaint_hearings h
          WHERE h.complaint_id = ? AND h.session_type = 'mediation'
            AND h.status = 'completed'
          ORDER BY h.hearing_date DESC, h.hearing_time DESC, h.id DESC");
    mysqli_stmt_bind_param($phStmt, "i", $complaint_id);
    mysqli_stmt_execute($phStmt);
    $phRes = mysqli_stmt_get_result($phStmt);
    while ($row = mysqli_fetch_assoc($phRes)) {
        if (!empty($row['session_record'])) {
            $row['session_record'] = json_decode($row['session_record'], true);
        }
        $prevHearings[] = $row;
    }
    mysqli_stmt_close($phStmt);

    $constitution = getPangkatConstitutionState($conn, $complaint_id);

    echo json_encode([
        'success'           => true,
        'step'              => $step,
        'waiting'           => $waiting,
        'appearance_at'     => $case['absence_appearance_at'],
        'explain_deadline'  => $explainDeadline,
        'hearing_id'        => $hearing_id,
        'session_no'        => (int)($case['mediation_session_count'] ?? 1),
        'case_status'       => $case['status'],
        'attendance'        => $attendance,
        'constitution'      => $constitution,
        'previous_hearings' => $prevHearings,
        'awaiting_reschedule_after_justified' => ($step === 'schedule_justified'),
    ]);
    exit;
}

/* ============================================================
   POST: Nudge a party
   ============================================================ */
if ($action === 'nudge_party' && $adminRole === 'captain') {
    $party_id    = (int)($_POST['party_id'] ?? 0);
    $resident_id = (int)($_POST['resident_id'] ?? 0);
    if (!$party_id || !$resident_id) { echo json_encode(['success'=>false,'message'=>'Missing ids.']); exit; }

    $ps = mysqli_prepare($conn,
        "SELECT p.complaint_id, p.full_name, p.party_type,
                c.reference_number, c.hearing_date, c.hearing_time, c.hearing_location
         FROM complaint_parties p
         JOIN complaints c ON p.complaint_id = c.id
         WHERE p.id = ?");
    mysqli_stmt_bind_param($ps, "i", $party_id);
    mysqli_stmt_execute($ps);
    $info = mysqli_fetch_assoc(mysqli_stmt_get_result($ps));
    mysqli_stmt_close($ps);
    if (!$info) { echo json_encode(['success'=>false,'message'=>'Party not found.']); exit; }

    $hearing_id = getLatestMediationHearingId($conn, $info['complaint_id']);

    $cs = mysqli_prepare($conn,
        "INSERT INTO complaint_attendance
            (complaint_id, hearing_id, party_id, status, nudge_count, nudge_last_at, recorded_by, recorded_at)
         VALUES (?, ?, ?, 'pending', 1, NOW(), ?, NOW())
         ON DUPLICATE KEY UPDATE
            nudge_count = nudge_count + 1,
            nudge_last_at = NOW()");
    mysqli_stmt_bind_param($cs, "iiii", $info['complaint_id'], $hearing_id, $party_id, $admin_id);
    mysqli_stmt_execute($cs);
    mysqli_stmt_close($cs);

    $rs = mysqli_prepare($conn, "SELECT nudge_count FROM complaint_attendance WHERE complaint_id=? AND party_id=? LIMIT 1");
    mysqli_stmt_bind_param($rs, "ii", $info['complaint_id'], $party_id);
    mysqli_stmt_execute($rs);
    $nudge_count = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($rs))['nudge_count'] ?? 1);
    mysqli_stmt_close($rs);

    $niceDate = $info['hearing_date'] ? date('F j, Y', strtotime($info['hearing_date'])) : 'today';
    $niceTime = $info['hearing_time'] ?? '';

    $title = 'Reminder: Please Come to Your Mediation Now';
    $msg   = "You are expected at the Barangay Hall for complaint #{$info['reference_number']} "
           . "({$info['party_type']} — {$info['full_name']}). Scheduled: {$niceDate} at {$niceTime} "
           . "({$info['hearing_location']}). Please come immediately.";

    sendNotification($conn, 'resident', $resident_id, $info['complaint_id'], $title, $msg, 'warning');
    if (function_exists('pushToUser')) {
        pushToUser($conn, $resident_id, $title, $msg,
            '/BRITE/resident/dashboards/resident_dashboard.php');
    }

    logUpdate($conn, $info['complaint_id'], 'note', null, null,
        "Captain nudged {$info['party_type']} {$info['full_name']} (nudge #{$nudge_count}).",
        $admin_id, 'captain');

    echo json_encode([
        'success' => true,
        'message' => "Reminder #{$nudge_count} sent to {$info['full_name']}.",
        'nudge_count' => $nudge_count
    ]);
    exit;
}

/* ============================================================
   GET: KP Notice data
   ============================================================ */
if ($action === 'get_kp_notice_data') {
    $complaint_id = (int)($_GET['id'] ?? 0);
    $party_type   = $_GET['party_type'] ?? '';
    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing id']); exit; }
    if (!in_array($party_type, ['complainant','respondent'], true)) {
        echo json_encode(['success'=>false,'message'=>'party_type must be complainant or respondent']); exit;
    }

    $cs = mysqli_prepare($conn,
        "SELECT c.id, c.reference_number, c.title, c.complaint_subject,
                c.description, c.hearing_date, c.hearing_time, c.hearing_location,
                c.mediation_session_count, c.created_at
         FROM complaints c WHERE c.id = ?");
    mysqli_stmt_bind_param($cs, "i", $complaint_id);
    mysqli_stmt_execute($cs);
    $complaint = mysqli_fetch_assoc(mysqli_stmt_get_result($cs));
    mysqli_stmt_close($cs);
    if (!$complaint) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }

    $ps = mysqli_prepare($conn,
        "SELECT id, party_type, full_name, address, contact_number, notice_pdf_path
         FROM complaint_parties
         WHERE complaint_id = ?
         ORDER BY FIELD(party_type,'complainant','respondent','witness'), id");
    mysqli_stmt_bind_param($ps, "i", $complaint_id);
    mysqli_stmt_execute($ps);
    $pr = mysqli_stmt_get_result($ps);
    $complainants = []; $respondents = []; $witnesses = [];
    while ($row = mysqli_fetch_assoc($pr)) {
        if ($row['party_type'] === 'complainant')    $complainants[] = $row;
        elseif ($row['party_type'] === 'respondent') $respondents[]  = $row;
        else                                          $witnesses[]    = $row;
    }
    mysqli_stmt_close($ps);

    echo json_encode([
        'success'      => true,
        'form'         => $party_type === 'complainant' ? 'KP_FORM_8' : 'KP_FORM_9',
        'complaint'    => $complaint,
        'complainants' => $complainants,
        'respondents'  => $respondents,
        'witnesses'    => $witnesses
    ]);
    exit;
}

/* ============================================================
   GET: Fetch a stored KP notice PDF (by party_id)
   ============================================================ */
if ($action === 'get_party_notice_pdf') {
    $party_id = (int)($_GET['party_id'] ?? 0);
    if (!$party_id) { echo json_encode(['success'=>false,'message'=>'Missing party_id']); exit; }

    $s = mysqli_prepare($conn,
        "SELECT p.id, p.complaint_id, p.party_type, p.full_name,
                p.notice_pdf_path, p.notice_pdf_generated_at,
                c.reference_number
         FROM complaint_parties p
         JOIN complaints c ON p.complaint_id = c.id
         WHERE p.id = ?");
    mysqli_stmt_bind_param($s, "i", $party_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);

    if (!$row) { echo json_encode(['success'=>false,'message'=>'Party not found.']); exit; }
    if (empty($row['notice_pdf_path'])) {
        echo json_encode(['success'=>false,'message'=>'No PDF has been generated for this party yet.']);
        exit;
    }

    $absPath = __DIR__ . '/../' . $row['notice_pdf_path'];
    if (!file_exists($absPath)) {
        $res = save_kp_notice_for_party(
            $conn,
            (int)$row['complaint_id'],
            (int)$row['id'],
            $row['party_type'],
            $adminName ?: 'BARANGAY SECRETARY'
        );
        if (!$res['ok']) {
            echo json_encode(['success'=>false,'message'=>'PDF not on disk and regeneration failed: ' . ($res['error'] ?? 'unknown')]);
            exit;
        }
        $row['notice_pdf_path'] = $res['pdf_path'];
    }

    echo json_encode([
        'success'        => true,
        'party_id'       => (int)$row['id'],
        'party_type'     => $row['party_type'],
        'full_name'      => $row['full_name'],
        'reference'      => $row['reference_number'],
        'pdf_url'        => $row['notice_pdf_path'],
        'generated_at'   => $row['notice_pdf_generated_at'],
    ]);
    exit;
}

/* ============================================================
   GET: Per-hearing KP notice PDFs (KP #8, #9, #12)
   ============================================================ */
if ($action === 'get_hearing_kp_notices') {
    $hearing_id = (int)($_GET['hearing_id'] ?? 0);
    if (!$hearing_id) { echo json_encode(['success'=>false,'message'=>'Missing hearing_id']); exit; }

    $s = mysqli_prepare($conn,
        "SELECT h.id, h.complaint_id, h.session_type, h.session_number,
                h.hearing_date, h.hearing_time,
                h.kp8_complainant_pdf_path,  h.kp8_complainant_issued_at,  h.kp8_complainant_notified,
                h.kp9_respondent_pdf_path,   h.kp9_respondent_issued_at,   h.kp9_respondent_notified,
                h.kp12_complainant_pdf_path, h.kp12_complainant_issued_at, h.kp12_complainant_notified,
                h.kp12_respondent_pdf_path,  h.kp12_respondent_issued_at,  h.kp12_respondent_notified,
                c.reference_number
           FROM complaint_hearings h
           JOIN complaints c ON c.id = h.complaint_id
          WHERE h.id = ?");
    if (!$s) { echo json_encode(['success'=>false,'message'=>'DB error']); exit; }
    mysqli_stmt_bind_param($s, "i", $hearing_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    if (!$row) { echo json_encode(['success'=>false,'message'=>'Hearing not found']); exit; }

    echo json_encode([
        'success' => true,
        'hearing' => [
            'id'             => (int)$row['id'],
            'session_type'   => $row['session_type'],
            'session_number' => (int)$row['session_number'],
            'hearing_date'   => $row['hearing_date'],
            'hearing_time'   => $row['hearing_time'],
            'reference'      => $row['reference_number'],
        ],
        'kp8'  => [
            'pdf_url'   => $row['kp8_complainant_pdf_path'],
            'issued_at' => $row['kp8_complainant_issued_at'],
            'notified'  => (int)$row['kp8_complainant_notified'] === 1,
        ],
        'kp9'  => [
            'pdf_url'   => $row['kp9_respondent_pdf_path'],
            'issued_at' => $row['kp9_respondent_issued_at'],
            'notified'  => (int)$row['kp9_respondent_notified'] === 1,
        ],
        'kp12' => [
            'complainant' => [
                'pdf_url'   => $row['kp12_complainant_pdf_path'],
                'issued_at' => $row['kp12_complainant_issued_at'],
                'notified'  => (int)$row['kp12_complainant_notified'] === 1,
            ],
            'respondent' => [
                'pdf_url'   => $row['kp12_respondent_pdf_path'],
                'issued_at' => $row['kp12_respondent_issued_at'],
                'notified'  => (int)$row['kp12_respondent_notified'] === 1,
            ],
        ],
    ]);
    exit;
}

/* ============================================================
   GET: Fetch a stored KP Form #18/#19 PDF
   ============================================================ */
if ($action === 'get_kp_failure_notice_pdf') {
    $complaint_id = (int)($_GET['complaint_id'] ?? 0);
    $hearing_id   = (int)($_GET['hearing_id'] ?? 0);
    $party_id     = (int)($_GET['party_id'] ?? 0);
    if (!$complaint_id || !$party_id) {
        echo json_encode(['success'=>false,'message'=>'Missing complaint_id / party_id']); exit;
    }

    $q = "SELECT a.kp18_or_19_pdf_path, a.kp18_or_19_generated_at,
                 p.party_type, p.full_name,
                 c.reference_number
            FROM complaint_attendance a
            JOIN complaint_parties p ON p.id = a.party_id
            JOIN complaints c ON c.id = a.complaint_id
           WHERE a.complaint_id = ? AND a.party_id = ?";
    if ($hearing_id) $q .= " AND a.hearing_id = ?";
    $q .= " ORDER BY a.id DESC LIMIT 1";

    $s = mysqli_prepare($conn, $q);
    if ($hearing_id) mysqli_stmt_bind_param($s, "iii", $complaint_id, $party_id, $hearing_id);
    else              mysqli_stmt_bind_param($s, "ii",  $complaint_id, $party_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);

    if (!$row || empty($row['kp18_or_19_pdf_path'])) {
        echo json_encode(['success'=>false,'message'=>'No KP #18/#19 PDF on file.']); exit;
    }
    echo json_encode([
        'success'   => true,
        'pdf_url'   => $row['kp18_or_19_pdf_path'],
        'party_type'=> $row['party_type'],
        'full_name' => $row['full_name'],
        'reference' => $row['reference_number'],
        'generated_at' => $row['kp18_or_19_generated_at'],
    ]);
    exit;
}

/* ============================================================
   GET: Fetch a stored KP Form #10 PDF
   ============================================================ */
if ($action === 'get_kp10_pdf') {
    $complaint_id = (int)($_GET['complaint_id'] ?? 0);
    $party_id     = (int)($_GET['party_id'] ?? 0);
    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint_id']); exit; }

    if ($party_id) {
        $s = mysqli_prepare($conn,
            "SELECT p.id, p.party_type, p.full_name, p.kp10_pdf_path, p.kp10_issued_at,
                    c.reference_number
             FROM complaint_parties p
             JOIN complaints c ON p.complaint_id = c.id
             WHERE p.id = ? AND p.complaint_id = ?");
        mysqli_stmt_bind_param($s, "ii", $party_id, $complaint_id);
    } else {
        $s = mysqli_prepare($conn,
            "SELECT p.id, p.party_type, p.full_name, p.kp10_pdf_path, p.kp10_issued_at,
                    c.reference_number
             FROM complaint_parties p
             JOIN complaints c ON p.complaint_id = c.id
             WHERE p.complaint_id = ? AND p.kp10_pdf_path IS NOT NULL
             ORDER BY FIELD(p.party_type,'complainant','respondent'), p.id
             LIMIT 1");
        mysqli_stmt_bind_param($s, "i", $complaint_id);
    }
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);

    if (!$row || empty($row['kp10_pdf_path'])) {
        echo json_encode(['success'=>false,'message'=>'No KP Form #10 PDF on file.']); exit;
    }
    echo json_encode([
        'success'    => true,
        'pdf_url'    => $row['kp10_pdf_path'],
        'party_id'   => (int)$row['id'],
        'party_type' => $row['party_type'],
        'full_name'  => $row['full_name'],
        'reference'  => $row['reference_number'],
        'issued_at'  => $row['kp10_issued_at'],
    ]);
    exit;
}

/* ============================================================
   GET: Fetch a stored KP Form #11 PDF
   ============================================================ */
if ($action === 'get_kp11_pdf') {
    $complaint_id = (int)($_GET['complaint_id'] ?? 0);
    $member_id    = (int)($_GET['member_id'] ?? 0);
    if (!$complaint_id || !$member_id) {
        echo json_encode(['success'=>false,'message'=>'Missing complaint_id or member_id']); exit;
    }

    $s = mysqli_prepare($conn,
        "SELECT cp.member_id, cp.kp11_pdf_path, cp.kp11_issued_at,
                a.full_name,
                c.reference_number
         FROM complaint_pangkat cp
         JOIN admin a ON a.id = cp.member_id
         JOIN complaints c ON c.id = cp.complaint_id
         WHERE cp.complaint_id = ? AND cp.member_id = ?
         LIMIT 1");
    mysqli_stmt_bind_param($s, "ii", $complaint_id, $member_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);

    if (!$row) { echo json_encode(['success'=>false,'message'=>'Member not on this Pangkat.']); exit; }

    if (empty($row['kp11_pdf_path'])) {
        $res = save_kp_form_11_for_member($conn, $complaint_id, $member_id, $adminName ?: 'BARANGAY CAPTAIN');
        if (!$res['ok']) {
            echo json_encode(['success'=>false,'message'=>'KP #11 regeneration failed: ' . ($res['error'] ?? 'unknown')]);
            exit;
        }
        $row['kp11_pdf_path'] = $res['pdf_path'];
    }

    echo json_encode([
        'success'    => true,
        'pdf_url'    => $row['kp11_pdf_path'],
        'member_id'  => (int)$row['member_id'],
        'full_name'  => $row['full_name'],
        'reference'  => $row['reference_number'],
        'issued_at'  => $row['kp11_issued_at'],
    ]);
    exit;
}

/* ============================================================
   GET: Fetch auto-issued bar certification (KP #23 / #24)
   ============================================================ */
if ($action === 'get_bar_cert_pdf') {
    $complaint_id = (int)($_GET['complaint_id'] ?? 0);
    $type         = $_GET['type'] ?? '';
    if (!$complaint_id || !in_array($type, ['action','counterclaim'], true)) {
        echo json_encode(['success'=>false,'message'=>'Missing complaint_id or invalid type.']); exit;
    }

    if ($type === 'action') {
        $s = mysqli_prepare($conn,
            "SELECT c.reference_number, c.bar_action_cert_no, c.bar_action_issued_at
               FROM complaints c WHERE c.id = ?");
    } else {
        $s = mysqli_prepare($conn,
            "SELECT c.reference_number, c.bar_counterclaim_cert_no, c.bar_counterclaim_issued_at
               FROM complaints c WHERE c.id = ?");
    }
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);

    if (!$row) { echo json_encode(['success'=>false,'message'=>'Complaint not found.']); exit; }

    $certNoCol = ($type === 'action') ? 'bar_action_cert_no' : 'bar_counterclaim_cert_no';
    $issuedCol = ($type === 'action') ? 'bar_action_issued_at' : 'bar_counterclaim_issued_at';
    $certNo    = $row[$certNoCol] ?? null;
    $issuedAt  = $row[$issuedCol] ?? null;

    if (!$certNo) {
        echo json_encode(['success'=>false,'message'=>'No certification has been issued yet.']); exit;
    }

    $safeRef = preg_replace('/[^A-Za-z0-9\-]/', '-', $row['reference_number']);
    $folder  = ($type === 'action') ? 'complainant' : 'respondent';
$for = $_GET['for'] ?? '';
if ($type === 'action') {
    // KP #23 — primary copy is for the complainant; extra copy for the respondent
    $suffix = ($for === 'respondent')
        ? 'kp23-respondent.pdf'
        : 'kp23-complainant.pdf';
    $folder = ($for === 'respondent') ? 'respondent' : 'complainant';
} else {
    // KP #24 — primary copy is for the respondent; extra copy for the complainant
    $suffix = ($for === 'complainant')
        ? 'kp24-complainant.pdf'
        : 'kp24-respondent.pdf';
    $folder = ($for === 'complainant') ? 'complainant' : 'respondent';
}
    $rel     = 'uploads/kp_notices/' . $folder . '/' . $safeRef . '-' . $suffix;

    echo json_encode([
        'success'   => true,
        'pdf_url'   => $rel,
        'cert_no'   => $certNo,
        'issued_at' => $issuedAt,
    ]);
    exit;
}


/* ============================================================
   GET: Fetch the Certificate to File Action PDF (KP #20)
   ============================================================ */
if ($action === 'get_cfa_pdf') {
    $complaint_id = (int)($_GET['complaint_id'] ?? 0);
    $forWhom      = $_GET['for'] ?? 'complainant';
    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint_id.']); exit; }

    $s = mysqli_prepare($conn,
        "SELECT reference_number, certificate_no, certificate_issued_at,
                kp20_pdf_path, kp20_generated_at
           FROM complaints WHERE id = ?");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);

    if (!$row) { echo json_encode(['success'=>false,'message'=>'Complaint not found.']); exit; }

    $pdfPath  = $row['kp20_pdf_path'];
    $issuedAt = $row['kp20_generated_at'];

    if (!$pdfPath) {
        echo json_encode(['success'=>false,'message'=>'No CFA PDF has been generated yet.']); exit;
    }

    echo json_encode([
        'success'    => true,
        'pdf_url'    => $pdfPath,
        'cert_no'    => $row['certificate_no'],
        'issued_at'  => $issuedAt,
        'reference'  => $row['reference_number'],
    ]);
    exit;
}

/* ============================================================
   GET: Hearing details
   ============================================================ */
if ($action === 'get_hearing_details') {
    $hearing_id = (int)($_GET['hearing_id'] ?? 0);
    if ($hearing_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing hearing_id.']);
        exit;
    }

    $s = mysqli_prepare($conn,
        "SELECT h.*,
                c.reference_number, c.title, c.complaint_subject,
                c.description, c.status AS complaint_status,
                a.full_name AS conducted_by_name
           FROM complaint_hearings h
           JOIN complaints c ON h.complaint_id = c.id
           LEFT JOIN admin a ON h.conducted_by = a.id
          WHERE h.id = ?");
    mysqli_stmt_bind_param($s, "i", $hearing_id);
    mysqli_stmt_execute($s);
    $hearing = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);

    if (!$hearing) {
        echo json_encode(['success' => false, 'message' => 'Hearing not found.']);
        exit;
    }

    $complaint_id = (int)$hearing['complaint_id'];

    if ($adminRole === 'lupon') {
        $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
        if (!$ctx) {
            echo json_encode(['success' => false, 'message' => 'You are not a member of this Pangkat.']);
            exit;
        }
    } elseif (!in_array($adminRole, ['captain', 'secretary'], true)) {
        echo json_encode(['success' => false, 'message' => 'Not allowed.']);
        exit;
    }

    $hearing['kp8'] = [
        'pdf_url'   => $hearing['kp8_complainant_pdf_path'] ?? null,
        'issued_at' => $hearing['kp8_complainant_issued_at'] ?? null,
        'notified'  => (int)($hearing['kp8_complainant_notified'] ?? 0) === 1,
    ];
    $hearing['kp9'] = [
        'pdf_url'   => $hearing['kp9_respondent_pdf_path'] ?? null,
        'issued_at' => $hearing['kp9_respondent_issued_at'] ?? null,
        'notified'  => (int)($hearing['kp9_respondent_notified'] ?? 0) === 1,
    ];
    $hearing['kp12'] = [
        'complainant' => [
            'pdf_url'   => $hearing['kp12_complainant_pdf_path'] ?? null,
            'issued_at' => $hearing['kp12_complainant_issued_at'] ?? null,
            'notified'  => (int)($hearing['kp12_complainant_notified'] ?? 0) === 1,
        ],
        'respondent' => [
            'pdf_url'   => $hearing['kp12_respondent_pdf_path'] ?? null,
            'issued_at' => $hearing['kp12_respondent_issued_at'] ?? null,
            'notified'  => (int)($hearing['kp12_respondent_notified'] ?? 0) === 1,
        ],
    ];

    $sr = null;
    if (!empty($hearing['session_record'])) {
        $sr = json_decode($hearing['session_record'], true);
        $hearing['session_record'] = $sr;
    }

    $attendance = [];
    $att = mysqli_prepare($conn,
        "SELECT a.party_id, a.status, a.recorded_at,
                a.justification_status, a.justification_reason, a.justification_decided_at,
                a.no_response_declared, a.appeared_at, a.appearance_notes,
                a.kp18_or_19_pdf_path, a.kp18_or_19_generated_at,
                p.party_type, p.full_name, p.contact_number, p.matched_resident_id
           FROM complaint_attendance a
           JOIN complaint_parties p ON p.id = a.party_id
          WHERE a.hearing_id = ?
          ORDER BY FIELD(p.party_type,'complainant','respondent','witness'), p.id");
    mysqli_stmt_bind_param($att, "i", $hearing_id);
    mysqli_stmt_execute($att);
    $ar = mysqli_stmt_get_result($att);
    while ($row = mysqli_fetch_assoc($ar)) $attendance[] = $row;
    mysqli_stmt_close($att);

    $parties = [];
    $ps = mysqli_prepare($conn,
        "SELECT id, party_type, full_name, contact_number, matched_resident_id
           FROM complaint_parties
          WHERE complaint_id = ?
          ORDER BY FIELD(party_type,'complainant','respondent','witness'), id");
    mysqli_stmt_bind_param($ps, "i", $complaint_id);
    mysqli_stmt_execute($ps);
    $pr = mysqli_stmt_get_result($ps);
    while ($row = mysqli_fetch_assoc($pr)) $parties[] = $row;
    mysqli_stmt_close($ps);

    $complainantStatement = $sr['complainant_statement'] ?? null;
    $respondentStatement  = $sr['respondent_statement']  ?? null;

    echo json_encode([
        'success'                => true,
        'hearing'                => $hearing,
        'attendance'             => $attendance,
        'parties'                => $parties,
        'complainant_statement'  => $complainantStatement,
        'respondent_statement'   => $respondentStatement,
    ]);
    exit;
}

/* ============================================================
   CAPTAIN: Constitute the Pangkat
   ============================================================ */
if ($action === 'constitute_pangkat' && $adminRole === 'captain') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $selected_by  = $_POST['selected_by'] ?? 'parties';
    $notes        = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');

    $membersJson  = $_POST['members'] ?? '[]';
    $membersInput = json_decode($membersJson, true);
    if (!is_array($membersInput)) $membersInput = [];

    if (empty($membersInput) && !empty($_POST['member_ids'])) {
        foreach ((array)$_POST['member_ids'] as $id) {
            $membersInput[] = ['member_id' => (int)$id];
        }
    }

    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint']); exit; }
    if (count($membersInput) !== 3) {
        echo json_encode(['success'=>false,'message'=>'Please select exactly 3 Pangkat members.']);
        exit;
    }
    if (!in_array($selected_by, ['parties','lot','captain'])) $selected_by = 'parties';

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }

    if ($meta['status'] !== 'pangkat_constitution_scheduled') {
        echo json_encode([
            'success' => false,
            'message' => 'The Pangkat constitution meeting must be scheduled first.'
        ]);
        exit;
    }

    $cstate = getPangkatConstitutionState($conn, $complaint_id);
    if (!$cstate || empty($cstate['constitution_at'])) {
        echo json_encode([
            'success' => false,
            'message' => 'No constitution meeting has been scheduled yet.'
        ]);
        exit;
    }

    if (!$cstate['can_constitute']) {
        $when = $cstate['constitution_date']
            ? date('F j, Y', strtotime($cstate['constitution_date']))
            : 'the scheduled date';
        echo json_encode([
            'success' => false,
            'message' => "The constitution meeting is scheduled for $when."
        ]);
        exit;
    }

    $ids = [];
    foreach ($membersInput as $m) {
        $mid = (int)($m['member_id'] ?? 0);
        if (!$mid) { echo json_encode(['success'=>false,'message'=>'Invalid member ID.']); exit; }
        $ids[] = $mid;
    }
    $ids = array_values(array_unique($ids));
    if (count($ids) !== 3) {
        echo json_encode(['success'=>false,'message'=>'Each member must be distinct.']); exit;
    }

    $in    = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $vStmt = mysqli_prepare($conn,
        "SELECT id, full_name FROM admin
         WHERE id IN ($in) AND admin_role = 'lupon' AND is_active = 1");
    mysqli_stmt_bind_param($vStmt, $types, ...$ids);
    mysqli_stmt_execute($vStmt);
    $r = mysqli_stmt_get_result($vStmt);
    $validById = [];
    while ($row = mysqli_fetch_assoc($r)) $validById[(int)$row['id']] = $row['full_name'];
    mysqli_stmt_close($vStmt);

    foreach ($ids as $mid) {
        if (!isset($validById[$mid])) {
            echo json_encode(['success'=>false,'message'=>'One or more selected members are not active Lupon members.']);
            exit;
        }
    }

    mysqli_begin_transaction($conn);
    try {
        $d = mysqli_prepare($conn, "DELETE FROM complaint_pangkat WHERE complaint_id = ?");
        mysqli_stmt_bind_param($d, "i", $complaint_id);
        mysqli_stmt_execute($d);
        mysqli_stmt_close($d);

        foreach ($ids as $mid) {
            $ins = mysqli_prepare($conn,
                "INSERT INTO complaint_pangkat
                    (complaint_id, member_id, role, selected_by, voted_at)
                 VALUES (?, ?, 'pending', ?, NULL)");
            mysqli_stmt_bind_param($ins, "iis", $complaint_id, $mid, $selected_by);
            mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);
        }

        $u = mysqli_prepare($conn,
            "UPDATE complaints SET status = 'pangkat_constituted' WHERE id = ?");
        mysqli_stmt_bind_param($u, "i", $complaint_id);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);

        $nameList = [];
        foreach ($ids as $mid) $nameList[] = $validById[$mid];
        logUpdate($conn, $complaint_id, 'status_change', $meta['status'], 'pangkat_constituted',
            "Pangkat constituted (members chosen by: $selected_by). Members: " . implode(', ', $nameList)
            . ". Positions to be assigned by the first-selected member. $notes",
            $admin_id, 'captain');
        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success'=>false,'message'=>'Database error while constituting Pangkat.']);
        exit;
    }

    $kp11Result = save_all_kp_form_11($conn, $complaint_id, $adminName ?: 'BARANGAY CAPTAIN');

    $firstId = $ids[0];
    $firstName = $validById[$firstId] ?? 'the first member';
    $noticeOut = [];
    foreach ($ids as $i => $mid) {
        if ((int)$mid === (int)$admin_id) continue; // skip the captain who just constituted
        $isFirst = ($i === 0);
        $title = 'You have been chosen as a Pangkat member';
        $msg  = "Complaint #{$meta['reference_number']} — you are one of the three Pangkat members.\n\n"
              . "Per KP Handbook: THEY SHALL ELECT FROM AMONG THEMSELVES A CHAIRPERSON AND A SECRETARY.\n\n"
              . ($isFirst
                    ? "⚠️ As the FIRST-selected member, please open the case in your Lupon Dashboard and assign "
                      . "the Chairperson, Secretary, and Member positions for your Pangkat."
                    : "Waiting for {$firstName} (first-selected member) to assign the positions.");
        // ✅ NEW: pass the KP #11 PDF path (per member)
        $memberKp11Path = $kp11Result['notices'][$i]['pdf_path'] ?? null;
        sendNotification($conn, 'admin', $mid, $complaint_id, $title, $msg, 'assignment', $memberKp11Path);
        if (function_exists('pushToUser')) {
            pushToUser($conn, $mid, $title,
                "Complaint #{$meta['reference_number']} — you are a Pangkat member.",
                '/BRITE/admin/dashboards/lupon_dashboard.php',
                $memberKp11Path);  // ✅ NEW
        }
        $noticeOut[] = [
            'member_id' => $mid,
            'name' => $validById[$mid],
            'is_first' => $isFirst,
            'kp11_ok' => !empty($kp11Result['notices'][$i]['ok']),
            'kp11_pdf_url' => $kp11Result['notices'][$i]['pdf_path'] ?? null,
        ];
    }

    // Grab the first member's KP #11 PDF for the secretary's record
    $firstKp11 = null;
    foreach ($kp11Result['notices'] as $n) {
        if (!empty($n['pdf_path'])) { $firstKp11 = $n['pdf_path']; break; }
    }
    notifyAdminsByRole($conn, ['secretary'], $complaint_id,
        'Pangkat Constituted — Awaiting Position Assignment',
        "Complaint #{$meta['reference_number']} — 3 Lupon members chosen. "
        . "{$firstName} will assign positions in the Lupon Portal.",
        'info',
        null,
        $firstKp11);   // ✅ NEW

    $creator = getComplaintCreator($conn, $complaint_id);
    if ($creator) {
        sendNotification($conn, 'resident', $creator, $complaint_id,
            'Case Forwarded to Pangkat',
            "Mediation did not result in a settlement. Your case has been forwarded to the Pangkat.",
            'info');
    }

    echo json_encode([
        'success'         => true,
        'message'         => 'Pangkat constituted. Waiting for the first-selected member to assign positions.',
        'members'         => $noticeOut,
        'first_member_id' => $firstId,
        'status'          => 'pangkat_constituted',
        'kp11'            => $kp11Result,
    ]);
    exit;
}

/* ============================================================
   LUPON: Assign positions (only first-selected member)
   ============================================================ */
if ($action === 'lupon_assign_pangkat_positions' && $adminRole === 'lupon') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $chair_id     = (int)($_POST['chair_id'] ?? 0);
    $secretary_id = (int)($_POST['secretary_id'] ?? 0);
    $member_id    = (int)($_POST['member_id'] ?? 0);

    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint_id.']); exit; }
    if (!$chair_id || !$secretary_id || !$member_id) {
        echo json_encode(['success'=>false,'message'=>'All three positions must be assigned.']); exit;
    }

    $unique = array_unique([$chair_id, $secretary_id, $member_id]);
    if (count($unique) !== 3) {
        echo json_encode(['success'=>false,'message'=>'Each member can only hold one position.']); exit;
    }

    $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
    if (!$ctx) {
        echo json_encode(['success'=>false,'message'=>'You are not a member of this Pangkat.']); exit;
    }
    if (!$ctx['is_first']) {
        echo json_encode(['success'=>false,'message'=>'Only the first-selected member may assign the positions.']); exit;
    }
    if (!$ctx['all_pending']) {
        echo json_encode(['success'=>false,'message'=>'Positions have already been assigned.']); exit;
    }

    $realMembers = [];
    foreach ($ctx['members'] as $row) $realMembers[(int)$row['member_id']] = true;
    if (!isset($realMembers[$chair_id]) || !isset($realMembers[$secretary_id]) || !isset($realMembers[$member_id])) {
        echo json_encode(['success'=>false,'message'=>'One or more IDs are not members of this Pangkat.']); exit;
    }

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }

    mysqli_begin_transaction($conn);
    try {
        $roleUpdates = [
            ['id' => $chair_id,     'role' => 'chair'],
            ['id' => $secretary_id, 'role' => 'secretary'],
            ['id' => $member_id,    'role' => 'member'],
        ];
        foreach ($roleUpdates as $ru) {
            $u = mysqli_prepare($conn,
                "UPDATE complaint_pangkat SET role = ?
                 WHERE complaint_id = ? AND member_id = ?");
            mysqli_stmt_bind_param($u, "sii", $ru['role'], $complaint_id, $ru['id']);
            mysqli_stmt_execute($u);
            mysqli_stmt_close($u);
        }

        $namesQ = mysqli_query($conn,
            "SELECT id, full_name FROM admin WHERE id IN (" . implode(',', array_map('intval', [$chair_id, $secretary_id, $member_id])) . ")");
        $names = [];
        if ($namesQ) while ($n = mysqli_fetch_assoc($namesQ)) $names[(int)$n['id']] = $n['full_name'];

        logUpdate($conn, $complaint_id, 'note', null, null,
            "Pangkat positions assigned by first-selected member: "
            . "Chairperson = " . ($names[$chair_id] ?? "#$chair_id") . "; "
            . "Secretary = "   . ($names[$secretary_id] ?? "#$secretary_id") . "; "
            . "Member = "      . ($names[$member_id] ?? "#$member_id") . ".",
            $admin_id, 'lupon');

        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success'=>false,'message'=>'Failed to assign positions.']);
        exit;
    }

    $dutyText = [
        'chair' =>
            "**Chairperson** — Leads the Pangkat proceedings.\n"
          . "• Conducts the Pangkat meetings.\n"
          . "• Makes sure both parties are given an opportunity to explain their side.\n"
          . "• Leads the discussion toward an amicable settlement.\n"
          . "• Maintains order during the proceedings.\n"
          . "• Guides the Pangkat in discussing the dispute.\n"
          . "• Signs or approves documents required from the Pangkat.\n"
          . "• Schedules the conciliation hearing from the Lupon Portal.\n"
          . "• Starts the hearing and sets appearance dates for absent parties.\n"
          . "• Decides whether absences are justified or unjustified.",
        'secretary' =>
            "**Secretary** — Records and manages Pangkat documents.\n"
          . "• Keeps the records of the Pangkat proceedings.\n"
          . "• Records attendance at the conciliation hearing.\n"
          . "• Records what happens during each hearing.\n"
          . "• Prepares or maintains notices and other Pangkat documents.\n"
          . "• Uploads documents to the case file.\n"
          . "• Generates KP forms (except scheduling forms).\n"
          . "• Prepares the certification/documentation required after the Pangkat proceedings.",
        'member' =>
            "**Member** — Participates in the actual conciliation process.\n"
          . "• Attends Pangkat meetings and hearings.\n"
          . "• Listens to both parties.\n"
          . "• Participates in discussions.\n"
          . "• Helps explore possible amicable settlements.\n"
          . "• Gives opinions during the Pangkat's deliberations.\n"
          . "• Participates in decisions requiring a vote.",
    ];

    $roleUpdates = [
        $chair_id     => 'chair',
        $secretary_id => 'secretary',
        $member_id    => 'member',
    ];
    foreach ($roleUpdates as $mid => $role) {
        if ((int)$mid === (int)$admin_id) continue; // skip the assigning member
        $title = 'You are the Pangkat ' . ucfirst($role);
        $msg   = "Complaint #{$meta['reference_number']} — you have been assigned as "
              . ucfirst($role) . " of the Pangkat.\n\n" . $dutyText[$role];
        sendNotification($conn, 'admin', $mid, $complaint_id, $title, $msg, 'success');
        if (function_exists('pushToUser')) {
            pushToUser($conn, $mid, $title,
                "Complaint #{$meta['reference_number']} — you are the " . ucfirst($role) . ".",
                '/BRITE/admin/dashboards/lupon_dashboard.php');
        }
    }

    notifyAdminsByRole($conn, ['secretary', 'captain'], $complaint_id,
        'Pangkat Positions Assigned',
        "Complaint #{$meta['reference_number']} — Chairperson: "
        . ($names[$chair_id] ?? "#$chair_id")
        . "; Secretary: " . ($names[$secretary_id] ?? "#$secretary_id")
        . "; Member: "    . ($names[$member_id] ?? "#$member_id") . ".",
        'success');

    echo json_encode([
        'success'   => true,
        'message'   => 'Positions assigned successfully.',
        'chair'     => ['id' => $chair_id,     'name' => $names[$chair_id] ?? null],
        'secretary' => ['id' => $secretary_id, 'name' => $names[$secretary_id] ?? null],
        'member'    => ['id' => $member_id,    'name' => $names[$member_id] ?? null],
    ]);
    exit;
}

if ($action === 'lupon_save_settlement_terms' && $adminRole === 'lupon') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $terms        = trim($_POST['terms'] ?? '');

    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint_id.']); exit; }
    if (strlen($terms) < 10) { echo json_encode(['success'=>false,'message'=>'Please write the full settlement terms (minimum 10 characters).']); exit; }

    $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
    if (!$ctx) { echo json_encode(['success'=>false,'message'=>'You are not a member of this Pangkat.']); exit; }
    if ($ctx['my_role'] !== 'secretary') { echo json_encode(['success'=>false,'message'=>'Only the Pangkat Secretary may write the settlement terms.']); exit; }

    $vq = mysqli_prepare($conn, "SELECT status, kp16_pdf_path, concil_hearing_started_at FROM complaints WHERE id = ?");
    mysqli_stmt_bind_param($vq, "i", $complaint_id);
    mysqli_stmt_execute($vq);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($vq));
    mysqli_stmt_close($vq);

    if (!$row) { echo json_encode(['success'=>false,'message'=>'Complaint not found.']); exit; }
    if (!($row['status'] === 'settled_pending' && empty($row['kp16_pdf_path']) && empty($row['concil_hearing_started_at']))) {
        echo json_encode(['success'=>false,'message'=>'This case is not awaiting settlement terms.']); exit;
    }

    $meta = getComplaintMeta($conn, $complaint_id);

    $kp16Result = save_kp_form_16_on_settlement($conn, $complaint_id, 'conciliation', $terms);
    if (empty($kp16Result['ok'])) {
        echo json_encode(['success'=>false,'message'=>'Failed to generate KP Form #16: ' . ($kp16Result['error'] ?? 'unknown error')]); exit;
    }

    $kp16Path = $kp16Result['pdf_path'] ?? null;

    $repudiationDeadline = enterSettledPendingState($conn, $complaint_id);

    logUpdate($conn, $complaint_id, 'status_change', 'settled_pending', 'settled_pending',
        "Pangkat Secretary wrote the settlement terms and generated KP Form #16. 10-day repudiation window starts. Deadline: " .
        date('F j, Y', strtotime($repudiationDeadline)) . ".", $admin_id, 'lupon');

    // ✅ NEW: Notify both parties with the KP #16 PDF
    $meta = getComplaintMeta($conn, $complaint_id);
    $pq = mysqli_prepare($conn,
        "SELECT id, party_type, full_name, matched_resident_id, contact_number
           FROM complaint_parties
          WHERE complaint_id = ? AND party_type IN ('complainant','respondent')");
    mysqli_stmt_bind_param($pq, "i", $complaint_id);
    mysqli_stmt_execute($pq);
    $pqr = mysqli_stmt_get_result($pq);
    while ($prow = mysqli_fetch_assoc($pqr)) {
        $matched = tryMatchResident($conn, $prow);
        if ($matched) {
            sendNotification($conn, 'resident', $matched, $complaint_id,
                'Amicable Settlement — KP Form #16 Ready',
                "The Amicable Settlement for complaint #{$meta['reference_number']} has been recorded. "
                . "You have 10 days to repudiate it on grounds of fraud, violence, or intimidation.",
                'success',
                $kp16Path);   // ✅ NEW
            if (function_exists('pushToUser')) {
                pushToUser($conn, $matched, 'Amicable Settlement Ready',
                    "Complaint #{$meta['reference_number']} — KP #16 available.",
                    '/BRITE/resident/dashboards/resident_dashboard.php',
                    $kp16Path);
            }
        }
    }
    mysqli_stmt_close($pq);

    // ✅ NEW: Notify admins with the KP #16 PDF
    notifyAdminsByRole($conn, ['captain','secretary'], $complaint_id,
        'KP #16 — Amicable Settlement Generated',
        "Complaint #{$meta['reference_number']} — settlement terms recorded by Pangkat Secretary.",
        'success',
        null,
        $kp16Path);

    echo json_encode([
        'success' => true,
        'message' => 'Settlement terms saved and KP Form #16 generated.',
        'kp16' => $kp16Result,
        'repudiation_deadline' => $repudiationDeadline,
    ]);
    exit;
}



/* ============================================================
   LUPON — CHAIRPERSON: Schedule conciliation (KP #12 to both parties)
   ============================================================ */
if ($action === 'pangkat_chairman_schedule' && $adminRole === 'lupon') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $date         = mysqli_real_escape_string($conn, $_POST['hearing_date'] ?? '');
    $time         = mysqli_real_escape_string($conn, $_POST['hearing_time'] ?? '');
    $location     = mysqli_real_escape_string($conn, $_POST['hearing_location'] ?? 'Barangay Hall');
    $notes        = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
    $issueWitnessSubpoenas = ($_POST['issue_witness_subpoenas'] ?? '0') === '1';

    if (!$complaint_id || !$date || !$time) {
        echo json_encode(['success'=>false,'message'=>'Missing fields.']); exit;
    }

    $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
    if (!$ctx) {
        echo json_encode(['success'=>false,'message'=>'You are not a member of this Pangkat.']); exit;
    }
    if ($ctx['my_role'] !== 'chair') {
        echo json_encode(['success'=>false,'message'=>'Only the Pangkat Chairperson may schedule the conciliation.']); exit;
    }

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }
    if ($meta['status'] !== 'pangkat_constituted') {
        echo json_encode(['success'=>false,'message'=>'This case is not ready for scheduling.']); exit;
    }

    $conflict = checkScheduleConflict($conn, $date, $time, $admin_id, 60);
    if (!empty($conflict['conflict'])) {
        echo json_encode(['success'=>false,'message'=>$conflict['reason']]); exit;
    }

    $conciliationDeadlineTs  = strtotime("+" . CONCILIATION_DAYS . " days", strtotime($date));
    $conciliationDeadlineSql = date('Y-m-d H:i:s', $conciliationDeadlineTs);

    $hardDeadlineTs  = strtotime($date . ' +15 days 23:59:59');
    $hardDeadlineSql = date('Y-m-d H:i:s', $hardDeadlineTs);

    mysqli_begin_transaction($conn);
    try {
        $s = mysqli_prepare($conn,
            "UPDATE complaints SET assigned_to = ?, assigned_role = 'lupon',
                status = 'pangkat_scheduled',
                hearing_date = ?, hearing_time = ?, hearing_location = ?,
                conciliation_deadline = ?,
                conciliation_first_hearing_date = ?,
                conciliation_hard_deadline = ?,
                conciliation_extended = 0,
                conciliation_extension_days = 0,
                concil_hearing_started_at = NULL,
                concil_absence_waiting_since = NULL,
                concil_absence_waiting_hearing = NULL,
                concil_absence_appearance_at = NULL
             WHERE id = ?");
        mysqli_stmt_bind_param($s, "issssssi",
            $admin_id, $date, $time, $location, $conciliationDeadlineSql,
            $date, $hardDeadlineSql, $complaint_id);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);

        $s = mysqli_prepare($conn, "INSERT INTO complaint_assignments
            (complaint_id, assigned_to, assigned_role, assigned_by, notes)
            VALUES (?, ?, 'lupon', ?, ?)");
        mysqli_stmt_bind_param($s, "iiis", $complaint_id, $admin_id, $admin_id, $notes);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);

        $s = mysqli_prepare($conn, "INSERT INTO complaint_hearings
            (complaint_id, session_type, session_number, hearing_date, hearing_time,
             location, status, conducted_by, created_by, session_reason)
            VALUES (?, 'conciliation', 1, ?, ?, ?, 'scheduled', ?, ?, ?)");
        $reasonText = "Pangkat conciliation scheduled by Chairperson.";
        mysqli_stmt_bind_param($s, "isssiis",
            $complaint_id, $date, $time, $location, $admin_id, $admin_id, $reasonText);
        mysqli_stmt_execute($s);
        $new_concil_hearing_id = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($s);
        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success'=>false,'message'=>'Failed to schedule.']); exit;
    }

    $kp12Result = generate_kp_form_on_demand($conn, $complaint_id, 'kp12', [
        'hearing_date' => $date,
        'hearing_time' => $time,
        'location'     => $location,
        'issued_by'    => $adminName ?: 'PANGKAT CHAIRPERSON',
    ]);

    if (!empty($kp12Result['ok']) && !empty($kp12Result['pdf_path']) && $new_concil_hearing_id > 0) {
        $uh = mysqli_prepare($conn,
            "UPDATE complaint_hearings
                SET kp12_complainant_pdf_path = ?,
                    kp12_complainant_issued_at = NOW(),
                    kp12_respondent_pdf_path  = ?,
                    kp12_respondent_issued_at = NOW()
              WHERE id = ?");
        mysqli_stmt_bind_param($uh, "ssi", $kp12Result['pdf_path'], $kp12Result['pdf_path'], $new_concil_hearing_id);
        mysqli_stmt_execute($uh);
        mysqli_stmt_close($uh);
    }

    $kp13Result = null;
    if ($issueWitnessSubpoenas) {
        $ws = mysqli_prepare($conn,
            "SELECT id, full_name, address FROM complaint_parties
              WHERE complaint_id = ? AND party_type = 'witness'");
        mysqli_stmt_bind_param($ws, "i", $complaint_id);
        mysqli_stmt_execute($ws);
        $wr = mysqli_stmt_get_result($ws);
        $witnesses = [];
        while ($row = mysqli_fetch_assoc($wr)) $witnesses[] = $row;
        mysqli_stmt_close($ws);

        if (!empty($witnesses)) {
            $kp13Result = generate_kp_form_on_demand($conn, $complaint_id, 'kp13', [
                'issued_by'    => $adminName ?: 'PANGKAT CHAIRPERSON',
                'hearing_date' => $date,
                'hearing_time' => $time,
                'location'     => $location,
                'witnesses'    => $witnesses,
            ]);
            logUpdate($conn, $complaint_id, 'note', null, null,
                "KP Form #13 (Subpoena) issued for " . count($witnesses) . " witness(es).",
                $admin_id, 'lupon');
        }
    }

    $nice = date('F j, Y', strtotime($date));
    $niceDeadline = date('F j, Y', $conciliationDeadlineTs);
    $niceHardDeadline = date('F j, Y', $hardDeadlineTs);
    logUpdate($conn, $complaint_id, 'hearing_scheduled', $meta['status'], 'pangkat_scheduled',
        "Chairperson scheduled Pangkat conciliation on $nice at $time. Conciliation period ends $niceDeadline. " .
        "Hard deadline: $niceHardDeadline. " .
        "KP #12 issued " . ($kp12Result['ok'] ? "→ {$kp12Result['pdf_path']}" : "(failed)"),
        $admin_id, 'lupon');

    foreach ($ctx['members'] as $row) {
        $mid = (int)$row['member_id'];
        if ($mid === (int)$admin_id) continue; // skip the acting chair
        sendNotification($conn, 'admin', $mid, $complaint_id,
            'Pangkat Conciliation Scheduled',
            "Conciliation for #{$meta['reference_number']} on $nice at $time, $location.",
            'hearing');
        if (function_exists('pushToUser')) {
            pushToUser($conn, $mid, 'Pangkat Conciliation Scheduled',
                "Complaint #{$meta['reference_number']} — $nice at $time.",
                '/BRITE/admin/dashboards/lupon_dashboard.php');
        }
    }

    $creator = getComplaintCreator($conn, $complaint_id);
    if ($creator) {
        sendNotification($conn, 'resident', $creator, $complaint_id,
            'Conciliation Scheduled',
            "Pangkat hearing for #{$meta['reference_number']} on $nice at $time.",
            'hearing');
        if (function_exists('pushToUser')) {
            pushToUser($conn, $creator, 'Conciliation Scheduled',
                "Complaint #{$meta['reference_number']} — $nice at $time.",
                '/BRITE/resident/dashboards/resident_dashboard.php');
        }
    }

    $pq = mysqli_prepare($conn,
        "SELECT id, party_type, full_name, matched_resident_id, contact_number
           FROM complaint_parties
          WHERE complaint_id = ? AND party_type IN ('complainant','respondent')");
    mysqli_stmt_bind_param($pq, "i", $complaint_id);
    mysqli_stmt_execute($pq);
    $pqr = mysqli_stmt_get_result($pq);

    $printList = [];   // parties without a resident account → must be printed

    while ($prow = mysqli_fetch_assoc($pqr)) {
        $matched = tryMatchResident($conn, $prow);
        if ($matched) {
            $title = 'Pangkat Conciliation — Notice of Hearing (KP Form #12)';
            $msg   = "You are required to appear before the Pangkat on $nice at $time, $location "
                   . "for the conciliation of complaint #{$meta['reference_number']}.";
            sendNotification($conn, 'resident', $matched, $complaint_id, $title, $msg, 'hearing', $kp12Result['pdf_path'] ?? null);
            if (function_exists('pushToUser')) {
                pushToUser($conn, $matched, $title, $msg,
                    '/BRITE/resident/dashboards/resident_dashboard.php',
                    $kp12Result['pdf_path'] ?? null);
            }
            if ($new_concil_hearing_id > 0) {
                if ($prow['party_type'] === 'complainant') {
                    mysqli_query($conn, "UPDATE complaint_hearings SET kp12_complainant_notified = 1 WHERE id = " . (int)$new_concil_hearing_id);
                } else {
                    mysqli_query($conn, "UPDATE complaint_hearings SET kp12_respondent_notified = 1 WHERE id = " . (int)$new_concil_hearing_id);
                }
            }
        } else {
            $us = mysqli_prepare($conn,
                "UPDATE complaint_parties SET notified_via = 'print' WHERE id = ?");
            mysqli_stmt_bind_param($us, "i", $prow['id']);
            mysqli_stmt_execute($us);
            mysqli_stmt_close($us);

            $printList[] = ucfirst($prow['party_type']) . ' ' . $prow['full_name'];
        }
    }
    mysqli_stmt_close($pq);

    if (!empty($printList)) {
        notifyPangkatSecretaryPrintRequired(
            $conn,
            $complaint_id,
            '⚠ PRINT REQUIRED — KP Form #12',
            "Complaint #{$meta['reference_number']} — "
              . count($printList) . " part" . (count($printList) > 1 ? 'ies' : 'y')
              . " without a resident account. Print & hand-deliver KP Form #12 "
              . "before the conciliation on $nice at $time, $location:\n• "
              . implode("\n• ", $printList),
            $kp12Result['pdf_path'] ?? null
        );
    }

    notifyAdminsByRole($conn, ['captain'], $complaint_id,
        'Pangkat Conciliation Scheduled',
        "Complaint #{$meta['reference_number']} — hearing on $nice.", 'info',
        null,
        $kp12Result['pdf_path'] ?? null);   // ✅ NEW
    notifyAdminsByRole($conn, ['secretary'], $complaint_id,
        'Pangkat Conciliation Scheduled',
        "Complaint #{$meta['reference_number']} — hearing on $nice.", 'info',
        null,
        $kp12Result['pdf_path'] ?? null);   // ✅ NEW

    echo json_encode([
        'success' => true,
        'message' => 'Conciliation scheduled.',
        'conciliation_deadline' => $conciliationDeadlineSql,
        'conciliation_hard_deadline' => $hardDeadlineSql,
        'kp12' => $kp12Result,
        'kp13' => $kp13Result,
    ]);
    exit;
}

/* ============================================================
   LUPON — CHAIR: Reschedule conciliation (within window)
   ============================================================ */
if ($action === 'lupon_reschedule_conciliation' && $adminRole === 'lupon') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $date         = mysqli_real_escape_string($conn, $_POST['hearing_date'] ?? '');
    $time         = mysqli_real_escape_string($conn, $_POST['hearing_time'] ?? '');
    $location     = mysqli_real_escape_string($conn, $_POST['hearing_location'] ?? 'Barangay Hall');
    $reason       = mysqli_real_escape_string($conn, $_POST['reason'] ?? '');
    $notes        = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');

    if (!$complaint_id || !$date || !$time) {
        echo json_encode(['success'=>false,'message'=>'Missing fields.']); exit;
    }

    $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
    if (!$ctx) { echo json_encode(['success'=>false,'message'=>'Not a member.']); exit; }
    if ($ctx['my_role'] !== 'chair') {
        echo json_encode(['success'=>false,'message'=>'Only the Chairperson may reschedule.']); exit;
    }

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Complaint not found.']); exit; }

    if (!in_array($meta['status'], [
        'pangkat_constituted',          // ← allow first-time schedule via the wizard
        'pangkat_scheduled',
        'failed_conciliation_final',
        'unresolved'
    ], true)) {
        echo json_encode(['success'=>false,'message'=>'Case is not at the conciliation stage.']); exit;
    }


    // ── Enforce the lower bound: cannot schedule before the first conciliation hearing ──
$firstQ = mysqli_prepare($conn,
    "SELECT conciliation_first_hearing_date FROM complaints WHERE id = ?");
mysqli_stmt_bind_param($firstQ, "i", $complaint_id);
mysqli_stmt_execute($firstQ);
$firstRow = mysqli_fetch_assoc(mysqli_stmt_get_result($firstQ));
mysqli_stmt_close($firstQ);

if (!empty($firstRow['conciliation_first_hearing_date'])) {
    $firstDate = substr($firstRow['conciliation_first_hearing_date'], 0, 10);
    if ($date < $firstDate) {
        echo json_encode([
            'success' => false,
            'message' => 'The new hearing cannot be earlier than the first conciliation hearing ('
                       . date('F j, Y', strtotime($firstDate)) . ').'
        ]);
        exit;
    }
}

    $hardDeadline = getConciliationHardDeadline($conn, $complaint_id);
    if ($hardDeadline && strtotime($date . ' 23:59:59') > strtotime($hardDeadline)) {
        echo json_encode([
            'success' => false,
            'message' => 'The new hearing must be on or before ' .
                         date('F j, Y', strtotime($hardDeadline)) .
                         '. Extend the conciliation period if you need more time.'
        ]);
        exit;
    }

    $conflict = checkScheduleConflict($conn, $date, $time, $admin_id, 60);
    if (!empty($conflict['conflict'])) {
        echo json_encode(['success'=>false,'message'=>$conflict['reason']]); exit;
    }

    mysqli_begin_transaction($conn);
    try {
        $sq = mysqli_prepare($conn,
            "SELECT COALESCE(MAX(session_number), 0) + 1 AS n
               FROM complaint_hearings
              WHERE complaint_id = ? AND session_type = 'conciliation'");
        mysqli_stmt_bind_param($sq, "i", $complaint_id);
        mysqli_stmt_execute($sq);
        $nextNum = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($sq))['n'];
        mysqli_stmt_close($sq);

        $ins = mysqli_prepare($conn,
            "INSERT INTO complaint_hearings
                (complaint_id, session_type, session_number, hearing_date, hearing_time,
                 location, status, conducted_by, created_by, session_reason)
             VALUES (?, 'conciliation', ?, ?, ?, ?, 'scheduled', ?, ?, ?)");
        $reasonText = 'Rescheduled by Chairperson. Reason: ' . $reason;
        mysqli_stmt_bind_param($ins, "iisssiis",
            $complaint_id, $nextNum, $date, $time, $location, $admin_id, $admin_id, $reasonText);
        mysqli_stmt_execute($ins);
        $newHearingId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($ins);

        $u = mysqli_prepare($conn,
            "UPDATE complaints
                SET status = 'pangkat_scheduled',
                    hearing_date = ?, hearing_time = ?, hearing_location = ?,
                    concil_hearing_started_at = NULL,
                    concil_absence_waiting_since = NULL,
                    concil_absence_waiting_hearing = NULL,
                    concil_absence_appearance_at = NULL
              WHERE id = ?");
        mysqli_stmt_bind_param($u, "sssi", $date, $time, $location, $complaint_id);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);

        logUpdate($conn, $complaint_id, 'hearing_scheduled', $meta['status'], 'pangkat_scheduled',
            "Conciliation rescheduled to {$date} {$time}. Reason: {$reason}. {$notes}",
            $admin_id, 'lupon');

        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success'=>false,'message'=>'Could not reschedule.']); exit;
    }

    foreach ($ctx['members'] as $m) {
        $mid = (int)$m['member_id'];
        if ($mid === (int)$admin_id) continue;
        sendNotification($conn, 'admin', $mid, $complaint_id,
            'Conciliation Rescheduled',
            "Complaint #{$meta['reference_number']} — new hearing on {$date} at {$time}.",
            'hearing');
    }
    notifyAdminsByRole($conn, ['captain','secretary'], $complaint_id,
        'Conciliation Rescheduled',
        "Complaint #{$meta['reference_number']} — {$date} at {$time}.",
        'info');

    echo json_encode([
        'success' => true,
        'message' => 'Conciliation rescheduled.',
        'hearing_id' => $newHearingId,
        'session_number' => $nextNum,
    ]);
    exit;
}

/* ============================================================
   LUPON — CHAIR: Extend conciliation by 15 more days (max 30)
   ============================================================ */
if ($action === 'lupon_extend_conciliation' && $adminRole === 'lupon') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $reason       = mysqli_real_escape_string($conn, $_POST['reason'] ?? '');

    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint_id.']); exit; }

    $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
    if (!$ctx) { echo json_encode(['success'=>false,'message'=>'Not a member.']); exit; }
    if ($ctx['my_role'] !== 'chair') {
        echo json_encode(['success'=>false,'message'=>'Only the Chairperson may extend.']); exit;
    }

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }
    if (!in_array($meta['status'], ['pangkat_scheduled', 'failed_conciliation_final', 'unresolved'], true)) {
        echo json_encode(['success'=>false,'message'=>'Case is not at the conciliation stage.']); exit;
    }

    $s = mysqli_prepare($conn,
        "SELECT conciliation_first_hearing_date, conciliation_extension_days
           FROM complaints WHERE id = ?");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);

    if (empty($row['conciliation_first_hearing_date'])) {
        echo json_encode(['success'=>false,'message'=>'No first hearing date on record.']); exit;
    }
    if ((int)$row['conciliation_extension_days'] >= 15) {
        echo json_encode(['success'=>false,'message'=>'The conciliation period has already been extended. Max total is 30 days.']); exit;
    }

    $newExtDays = (int)$row['conciliation_extension_days'] + 15;
    $newHardDeadline = date('Y-m-d H:i:s',
        strtotime($row['conciliation_first_hearing_date'] . ' +' . (15 + $newExtDays) . ' days 23:59:59'));

    mysqli_begin_transaction($conn);
    try {
        $u = mysqli_prepare($conn,
            "UPDATE complaints
                SET conciliation_extended = 1,
                    conciliation_extension_days = ?,
                    conciliation_hard_deadline = ?
              WHERE id = ?");
        mysqli_stmt_bind_param($u, "isi", $newExtDays, $newHardDeadline, $complaint_id);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);

        logUpdate($conn, $complaint_id, 'note', null, null,
            "Conciliation period extended by 15 days. New hard deadline: " . date('F j, Y', strtotime($newHardDeadline)) . ". Reason: {$reason}",
            $admin_id, 'lupon');
        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success'=>false,'message'=>'Could not extend.']); exit;
    }

    foreach ($ctx['members'] as $m) {
        $mid = (int)$m['member_id'];
        if ($mid === (int)$admin_id) continue;
        sendNotification($conn, 'admin', $mid, $complaint_id,
            'Conciliation Period Extended',
            "Complaint #{$meta['reference_number']} — new hard deadline: " . date('F j, Y', strtotime($newHardDeadline)) . ".",
            'info');
    }
    notifyAdminsByRole($conn, ['captain','secretary'], $complaint_id,
        'Conciliation Extended +15 Days',
        "Complaint #{$meta['reference_number']} — hard deadline: " . date('F j, Y', strtotime($newHardDeadline)) . ".",
        'info');

    echo json_encode([
        'success' => true,
        'message' => 'Conciliation extended by 15 days.',
        'hard_deadline' => $newHardDeadline,
        'hard_deadline_nice' => date('F j, Y', strtotime($newHardDeadline)),
    ]);
    exit;
}

/* ============================================================
   LUPON — CHAIR: Start the Conciliation Hearing
   ============================================================ */
if ($action === 'lupon_start_conciliation_hearing' && $adminRole === 'lupon') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint_id.']); exit; }

    $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
    if (!$ctx) {
        echo json_encode(['success'=>false,'message'=>'You are not a member of this Pangkat.']); exit;
    }
    if ($ctx['my_role'] !== 'chair') {
        echo json_encode(['success'=>false,'message'=>'Only the Chairperson may start the hearing.']); exit;
    }

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Complaint not found.']); exit; }
    if ($meta['status'] !== 'pangkat_scheduled') {
        echo json_encode(['success'=>false,'message'=>'No conciliation hearing is currently scheduled.']); exit;
    }

    $latestHearing = getLatestConciliationHearing($conn, $complaint_id);
    if (!$latestHearing) {
        echo json_encode(['success'=>false,'message'=>'No conciliation hearing record found.']); exit;
    }

    $today = date('Y-m-d');
    $hd = substr($latestHearing['hearing_date'], 0, 10);
    if ($hd > $today) {
        echo json_encode(['success'=>false,'message'=>'Cannot start the hearing before the scheduled date.']); exit;
    }

    $u = mysqli_prepare($conn,
        "UPDATE complaints SET concil_hearing_started_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($u, "i", $complaint_id);
    mysqli_stmt_execute($u);
    mysqli_stmt_close($u);

    logUpdate($conn, $complaint_id, 'note', null, null,
        "Conciliation hearing #{$latestHearing['id']} STARTED by Chairperson. All members may open/leave the hearing page freely.",
        $admin_id, 'lupon');

    foreach ($ctx['members'] as $m) {
        $mid = (int)$m['member_id'];
        if ($mid === (int)$admin_id) continue;
        sendNotification($conn, 'admin', $mid, $complaint_id,
            'Conciliation Hearing Started',
            "Complaint #{$meta['reference_number']} — the Chairperson has started the hearing. You may review the case details.",
            'hearing');
        if (function_exists('pushToUser')) {
            pushToUser($conn, $mid, 'Hearing Started',
                "Complaint #{$meta['reference_number']} — hearing has started.",
                '/BRITE/admin/dashboards/lupon_dashboard.php');
        }
    }
    notifyAdminsByRole($conn, ['captain','secretary'], $complaint_id,
        'Conciliation Hearing Started',
        "Complaint #{$meta['reference_number']} — Chairperson started the hearing.",
        'info');

    echo json_encode([
        'success' => true,
        'message' => 'Conciliation hearing started.',
        'hearing_id' => (int)$latestHearing['id'],
    ]);
    exit;
}

/* ============================================================
   LUPON — CHAIR: Record conciliation outcome — Settlement OR Arbitration
   ------------------------------------------------------------
   Mirrors the Captain's captain_record_mediation_outcome flow.

   • Settlement  → Chairperson records "Settled". The Pangkat Secretary
                   writes the terms and generates KP Form #16.
   • Arbitration → Chairperson generates KP Form #14 (Agreement for
                   Arbitration). The 5-day repudiation window starts.
                   The PUNONG BARANGAY (Captain) renders KP Form #15
                   (Arbitration Award) after the window closes.
   ============================================================ */
if ($action === 'lupon_record_conciliation_outcome' && $adminRole === 'lupon') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $outcome_kind = trim($_POST['outcome_kind'] ?? '');
    $summary      = mysqli_real_escape_string($conn, $_POST['summary'] ?? '');
    $terms        = mysqli_real_escape_string($conn, $_POST['reason'] ?? '');

    if (!$complaint_id || !in_array($outcome_kind, ['settlement','arbitration'], true)) {
        echo json_encode(['success'=>false,'message'=>'Missing complaint_id or invalid outcome_kind.']);
        exit;
    }
    if (!$summary) { echo json_encode(['success'=>false,'message'=>'Hearing summary is required.']); exit; }

    // Verify the caller is the Pangkat Chairperson
    $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
    if (!$ctx) { echo json_encode(['success'=>false,'message'=>'You are not a member of this Pangkat.']); exit; }
    if ($ctx['my_role'] !== 'chair') {
        echo json_encode(['success'=>false,'message'=>'Only the Pangkat Chairperson can record the conciliation outcome.']);
        exit;
    }

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Complaint not found.']); exit; }
    if ($meta['status'] !== 'pangkat_scheduled') {
        echo json_encode(['success'=>false,'message'=>'No active conciliation hearing.']);
        exit;
    }

    $latestHearing = getLatestConciliationHearing($conn, $complaint_id);
    if (!$latestHearing) { echo json_encode(['success'=>false,'message'=>'No conciliation hearing record found.']); exit; }
    $hearing_id = (int)$latestHearing['id'];

    /* ============================================================
       BRANCH A — Amicable Settlement (KP Form #16, Secretary writes)
       ============================================================ */
    if ($outcome_kind === 'settlement') {
        if ($terms === '') { echo json_encode(['success'=>false,'message'=>'Settlement terms are required.']); exit; }

        mysqli_begin_transaction($conn);
        try {
            // Close the hearing
            $uh = mysqli_prepare($conn,
                "UPDATE complaint_hearings
                    SET status='completed', outcome='settled', completed_at=NOW(),
                        explain_window_resolved_at = NOW(),
                        summary = CONCAT(COALESCE(summary,''), '\n[Chairperson] ', ?)
                  WHERE id = ?");
            mysqli_stmt_bind_param($uh, "si", $summary, $hearing_id);
            mysqli_stmt_execute($uh);
            mysqli_stmt_close($uh);

            // Move the case to "settled_pending" — Secretary will generate KP #16
            $u = mysqli_prepare($conn,
                "UPDATE complaints
                    SET status = 'settled_pending',
                        mediation_outcome = 'settlement',
                        settled_pending_at = NOW(),
                        settlement_signed_at = NULL,
                        repudiation_deadline = NULL,
                        kp16_pdf_path = NULL,
                        kp16_generated_at = NULL,
                        concil_hearing_started_at = NULL
                  WHERE id = ?");
            mysqli_stmt_bind_param($u, "i", $complaint_id);
            mysqli_stmt_execute($u);
            mysqli_stmt_close($u);

            logUpdate($conn, $complaint_id, 'resolution', $meta['status'], 'settled_pending',
                "Chairperson recorded SETTLED at conciliation. Awaiting the Pangkat Secretary's terms and KP Form #16.",
                $admin_id, 'lupon');

            mysqli_commit($conn);
        } catch (Exception $e) {
            mysqli_rollback($conn);
            error_log('lupon_record_conciliation_outcome[settlement] failed: ' . $e->getMessage());
            echo json_encode(['success'=>false,'message'=>'Could not save the settlement.']);
            exit;
        }

        // Notify Pangkat Secretary
        notifyAdminsByRole($conn, ['secretary'], $complaint_id,
            'Write Settlement Terms — KP Form #16 Needed',
            "Complaint #{$meta['reference_number']} — the Pangkat Chairperson recorded SETTLED. " .
            "Open the case in your Lupon Dashboard to write the settlement terms and generate KP Form #16.",
            'assignment');

        echo json_encode([
            'success'          => true,
            'outcome'          => 'settlement',
            'status'           => 'settled_pending',
            'awaits_secretary' => true,
            'message'          => 'Settled recorded. The Pangkat Secretary will write the terms and generate KP Form #16.',
        ]);
        exit;
    }

    /* ============================================================
       BRANCH B — Arbitration (KP Form #14 from the PANGKAT CHAIR)
       ------------------------------------------------------------
       The Pangkat Chairperson generates KP Form #14.
       The PUNONG BARANGAY (Captain) will render KP Form #15 after
       the 5-day repudiation window closes.
       ============================================================ */
    $signed_date = trim($_POST['signed_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $signed_date)) {
        $signed_date = date('Y-m-d');
    }

    // Generate KP Form #14 as the Pangkat Chairperson
    $kp14Result = generate_kp_form_on_demand($conn, $complaint_id, 'kp14', [
        'issued_by'   => 'PANGKAT CHAIRPERSON: ' . $adminName,
        'signed_date' => $signed_date,
    ]);
    if (empty($kp14Result['ok'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to generate KP Form #14: ' . ($kp14Result['error'] ?? 'unknown error'),
        ]);
        exit;
    }

    // Record KP #14 metadata on the complaint. Do NOT touch complaints.status here:
    // the arbitration window still belongs to the conciliation track, and the
    // Captain's dashboard reads the arbitration_* columns directly.
    $signedTs     = strtotime($signed_date);
    $signedDate   = date('Y-m-d', $signedTs);
    $repDeadline  = date('Y-m-d H:i:s', strtotime($signedDate . ' +5 days 23:59:59'));
    $awardDeadline= date('Y-m-d H:i:s', strtotime($signedDate . ' +15 days 23:59:59'));

    mysqli_begin_transaction($conn);
    try {
        // ── Extract to plain variables so bind_param can pass them by reference ──
        $kp14PdfPath       = $kp14Result['pdf_path'];
        $signedAtSql       = $signedDate . ' 00:00:00';
        $repDeadlineSql    = $repDeadline;
        $awardDeadlineSql  = $awardDeadline;

        $u = mysqli_prepare($conn,
            "UPDATE complaints
                SET status = 'arbitration_pending',
                    mediation_outcome = 'arbitration',
                    kp14_pdf_path = ?,
                    kp14_generated_at = NOW(),
                    arbitration_agreement_signed_at = ?,
                    repudiation_deadline = ?,
                    arbitration_repudiated = 0,
                    arbitration_repudiated_at = NULL,
                    arbitration_repudiated_by_party = NULL,
                    arbitration_award_deadline = ?,
                    concil_hearing_started_at = NULL,
                    resolved_at = NULL,
                    is_executory = 0
              WHERE id = ?");
        mysqli_stmt_bind_param($u, "ssssi",
            $kp14PdfPath,
            $signedAtSql,
            $repDeadlineSql,
            $awardDeadlineSql,
            $complaint_id);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);

        // Close the hearing
        $uh = mysqli_prepare($conn,
            "UPDATE complaint_hearings
                SET status='completed', outcome='settled', completed_at=NOW(),
                    explain_window_resolved_at = NOW(),
                    summary = CONCAT(COALESCE(summary,''), '\n[Chairperson → Arbitration] ', ?)
              WHERE id = ?");
        mysqli_stmt_bind_param($uh, "si", $summary, $hearing_id);
        mysqli_stmt_execute($uh);
        mysqli_stmt_close($uh);

        logUpdate($conn, $complaint_id, 'resolution', $meta['status'], 'arbitration_pending',
            "Chairperson issued KP Form #14 (Agreement for Arbitration). " .
            "5-day repudiation window ends " . date('F j, Y', strtotime($repDeadline)) . ". " .
            "The Punong Barangay will render KP Form #15 by " . date('F j, Y', strtotime($awardDeadline)) . ". " .
            $summary,
            $admin_id, 'lupon');

        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log('lupon_record_conciliation_outcome[arbitration] failed: ' . $e->getMessage());
        echo json_encode(['success'=>false,'message'=>'Could not save the arbitration agreement.']);
        exit;
    }

    // Notify BOTH parties
    $pq = mysqli_prepare($conn,
        "SELECT id, party_type, full_name, matched_resident_id, contact_number
           FROM complaint_parties
          WHERE complaint_id = ? AND party_type IN ('complainant','respondent')");
    mysqli_stmt_bind_param($pq, "i", $complaint_id);
    mysqli_stmt_execute($pq);
    $pqr = mysqli_stmt_get_result($pq);
    while ($prow = mysqli_fetch_assoc($pqr)) {
        $matched = tryMatchResident($conn, $prow);
        if (!$matched) continue;
        sendNotification($conn, 'resident', $matched, $complaint_id,
            'Agreement for Arbitration Signed — KP Form #14',
            "You have agreed to submit complaint #{$meta['reference_number']} to arbitration before the Pangkat. " .
            "You have 5 days (until " . date('F j, Y', strtotime($repDeadline)) . ") to repudiate KP Form #14 " .
            "on grounds of fraud, violence, or intimidation. " .
            "After that, the Punong Barangay must render KP Form #15 (Arbitration Award) within 15 days.",
            'warning',
            $kp14Result['pdf_path']);
        if (function_exists('pushToUser')) {
            pushToUser($conn, $matched,
                'KP Form #14 — Agreement for Arbitration',
                "Complaint #{$meta['reference_number']}.",
                '/BRITE/resident/dashboards/resident_dashboard.php',
                $kp14Result['pdf_path']);
        }
    }
    mysqli_stmt_close($pq);

    // Notify the PUNONG BARANGAY (Captain) — he owns the award/CFA decision
    notifyAdminsByRole($conn, ['captain'], $complaint_id,
        'Pangkat Arbitration — Prepare KP Form #15',
        "Complaint #{$meta['reference_number']} — parties agreed to ARBITRATION before the Pangkat. " .
        "You must render KP Form #15 (Arbitration Award) by " . date('F j, Y', strtotime($awardDeadline)) . ". " .
        "5-day repudiation window ends " . date('F j, Y', strtotime($repDeadline)) . ".",
        'assignment',
        null,
        $kp14Result['pdf_path']);

    // Also notify the Secretary so they can print KP #14
    notifyAdminsByRole($conn, ['secretary'], $complaint_id,
        '⚠ PRINT REQUIRED — KP Form #14 (Agreement for Arbitration)',
        "Complaint #{$meta['reference_number']} — parties agreed to arbitration. " .
        "Print and hand-deliver KP Form #14 to both parties immediately. " .
        "5-day repudiation window ends " . date('F j, Y', strtotime($repDeadline)) . ".",
        'warning',
        null,
        $kp14Result['pdf_path']);

    echo json_encode([
        'success'              => true,
        'outcome'              => 'arbitration',
        'status'               => 'arbitration_pending',
        'kp14'                 => $kp14Result,
        'repudiation_deadline' => $repDeadline,
        'award_deadline'       => $awardDeadline,
        'message'              => 'KP Form #14 (Agreement for Arbitration) recorded. The Punong Barangay will render KP Form #15 after the 5-day repudiation window.',
    ]);
    exit;
}


/* ============================================================
   LUPON — CHAIR: Save the conciliation hearing
   ============================================================ */
if ($action === 'chair_end_concil_hearing' && $adminRole === 'lupon') {


    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $outcome      = trim($_POST['outcome'] ?? 'failed_conciliation_final');
    $notes        = trim($_POST['notes'] ?? '');

    if (!$complaint_id) {
        echo json_encode(['success'=>false,'message'=>'Missing complaint_id.']); exit;
    }
    if (!in_array($outcome, ['settled','unresolved','dismissed','reschedule'], true)) {
        $outcome = 'unresolved';


    }

 
    $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
    if (!$ctx) { echo json_encode(['success'=>false,'message'=>'You are not a member of this Pangkat.']); exit; }
    if ($ctx['my_role'] !== 'chair') {
        echo json_encode(['success'=>false,'message'=>'Only the Chairperson may save the hearing.']); exit;
    }

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Complaint not found.']); exit; }
    if ($meta['status'] !== 'pangkat_scheduled') {
        echo json_encode(['success'=>false,'message'=>'No active conciliation hearing to save.']); exit;
    }

    if (!empty($meta['concil_absence_waiting_since']) && empty($meta['concil_absence_appearance_at'])) {
        echo json_encode([
            'success' => false,
            'message' => 'There are absent parties awaiting an appearance date. Please use "Set Appearance Date" before saving.'
        ]);
        exit;
    }
    if (!empty($meta['concil_absence_appearance_at'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Please decide on the absences (Justified / Unjustified) before saving.'
        ]);
        exit;
    }

    $latestHearing = getLatestConciliationHearing($conn, $complaint_id);
    if (!$latestHearing) { echo json_encode(['success'=>false,'message'=>'No conciliation hearing record found.']); exit; }
    $hearing_id = (int)$latestHearing['id'];

    $ac = mysqli_prepare($conn,
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN a.status IN ('attend','no_show') THEN 1 ELSE 0 END) AS marked
           FROM complaint_parties p
           LEFT JOIN complaint_attendance a
                  ON a.party_id = p.id AND a.hearing_id = ?
          WHERE p.complaint_id = ?
            AND p.party_type IN ('complainant','respondent')");
    mysqli_stmt_bind_param($ac, "ii", $hearing_id, $complaint_id);
    mysqli_stmt_execute($ac);
    $acRow = mysqli_fetch_assoc(mysqli_stmt_get_result($ac));
    mysqli_stmt_close($ac);
    if ((int)$acRow['total'] !== (int)$acRow['marked']) {
        echo json_encode([
            'success' => false,
            'message' => 'The Secretary must record attendance for all parties before you can save.'
        ]);
        exit;
    }
     
    /* ----------------------------------------------------------
   GATE: Statements must be recorded AND uploaded by Secretary
   ---------------------------------------------------------- */
$srq = mysqli_prepare($conn, "SELECT session_record FROM complaint_hearings WHERE id = ?");
mysqli_stmt_bind_param($srq, "i", $hearing_id);
mysqli_stmt_execute($srq);
$srr = mysqli_fetch_assoc(mysqli_stmt_get_result($srq));
mysqli_stmt_close($srq);

$sr = !empty($srr['session_record']) ? json_decode($srr['session_record'], true) : [];
if (!is_array($sr)) $sr = [];

$cs = $sr['complainant_statement'] ?? null;
$rs = $sr['respondent_statement']  ?? null;

$csHasContent = $cs && (trim($cs['text'] ?? '') !== '' || !empty($cs['audio_path']));
$rsHasContent = $rs && (trim($rs['text'] ?? '') !== '' || !empty($rs['audio_path']));

if (!$csHasContent || !$rsHasContent) {
    $missing = [];
    if (!$csHasContent) $missing[] = 'Complainant';
    if (!$rsHasContent) $missing[] = 'Respondent';

    echo json_encode([
        'success' => false,
        'code'    => 'statements_incomplete',
        'message' => 'The Pangkat Secretary must record and upload both parties\' statements before you can save the hearing record. Missing: ' . implode(' and ', $missing) . '.'
    ]);
    exit;
}


    $nextStep = trim($_POST['next_step'] ?? '');

    if ($outcome === 'settled') {
        $newStatus = 'settled_pending';
    } elseif ($outcome === 'unresolved' && $nextStep === 'reschedule') {
        // No Settlement + Reschedule → keep the case alive on the conciliation track.
        // The follow-up lupon_reschedule_conciliation call will set the new date/time.
        $newStatus = 'pangkat_scheduled';
    } elseif ($outcome === 'reschedule') {
        $newStatus = 'pangkat_scheduled';
    } else {
        // Final Unresolved → ends conciliation, KP #20-A will be issued.
        $newStatus = 'unresolved';
    }

    $hearingOutcome = ($outcome === 'settled') ? 'settled' : 'no_settlement';

    mysqli_begin_transaction($conn);
    try {
        $u = mysqli_prepare($conn,
            "UPDATE complaints
                SET status = ?,
                    concil_hearing_started_at      = NULL,
                    concil_absence_waiting_since   = NULL,
                    concil_absence_waiting_hearing = NULL,
                    concil_absence_appearance_at   = NULL,
                    resolution_notes = CONCAT(COALESCE(resolution_notes,''), '\n\n[CONCIL SAVED] ', ?)
              WHERE id = ?");
        mysqli_stmt_bind_param($u, "ssi", $newStatus, $notes, $complaint_id);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);

        $uh = mysqli_prepare($conn,
            "UPDATE complaint_hearings
                SET status='completed', outcome=?, completed_at=NOW(),
                    explain_window_resolved_at = NOW(),
                    summary = CONCAT(COALESCE(summary,''), '\n', ?)
              WHERE id = ?");
        mysqli_stmt_bind_param($uh, "ssi", $hearingOutcome, $notes, $hearing_id);
        mysqli_stmt_execute($uh);
        mysqli_stmt_close($uh);

        $outcomeLabel = ($outcome === 'unresolved' && $nextStep === 'reschedule')
            ? 'NO SETTLEMENT — RESCHEDULED'
            : strtoupper($outcome);

        logUpdate($conn, $complaint_id, 'resolution', $meta['status'], $newStatus,
            "Chairperson saved conciliation hearing. Outcome: " . $outcomeLabel . ". " . $notes,
            $admin_id, 'lupon');

        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log('chair_end_concil_hearing failed: ' . $e->getMessage());
        echo json_encode(['success'=>false,'message'=>'Could not save hearing.']); exit;
    }

    $kp16Result = null;
$repudiationDeadline = null;

if ($outcome === 'settled') {
    // DO NOT generate KP #16 or start the 10-day window here.
    // The Pangkat Secretary will do it. We just clear kp16 columns
    // so the client can detect the "awaiting terms" state.
    $u = mysqli_prepare($conn,
        "UPDATE complaints
            SET settled_pending_at = NOW(),
                settlement_signed_at = NULL,
                repudiation_deadline = NULL,
                kp16_pdf_path = NULL,
                kp16_generated_at = NULL
          WHERE id = ?");
    mysqli_stmt_bind_param($u, "i", $complaint_id);
    mysqli_stmt_execute($u);
    mysqli_stmt_close($u);

    logUpdate($conn, $complaint_id, 'status_change', $meta['status'], 'settled_pending',
        "Chairperson recorded SETTLED. Awaiting the Pangkat Secretary's settlement terms and KP Form #16.",
        $admin_id, 'lupon');
}

/* ✅ NEW: Auto-issue KP #20-A (underlying KP #21 renderer) when conciliation ends as Unresolved */
if ($outcome === 'unresolved' && $nextStep !== 'reschedule') {
    $kp20aRes = generate_kp_form_on_demand($conn, $complaint_id, 'kp21', [
        'issued_by' => $adminName ?: 'PANGKAT SECRETARY',
    ]);
    if (!empty($kp20aRes['ok'])) {
        logUpdate($conn, $complaint_id, 'note', null, null,
            "KP Form #20-A (Certification to File Action — Pangkat Secretary) auto-issued → {$kp20aRes['pdf_path']}",
            $admin_id, 'lupon');
    }
    notifyAdminsByRole($conn, ['captain','secretary'], $complaint_id,
        'Case Unresolved — KP #20-A Ready',
        "Complaint #{$meta['reference_number']} — conciliation ended without settlement. KP #20-A is ready for printing and delivery.",
        'info',
        null,
        $kp20aRes['pdf_path'] ?? null);

    if (!empty($kp20aRes['ok'])) {
        notifyPangkatSecretaryPrintRequired(
            $conn,
            $complaint_id,
            '⚠ PRINT REQUIRED — KP Form #20-A',
            "Complaint #{$meta['reference_number']} — conciliation ended without settlement. "
              . "Print and hand-deliver KP Form #20-A (Certification to File Action) to both parties.",
            $kp20aRes['pdf_path'] ?? null
        );
    }
}

    foreach ($ctx['members'] as $m) {
        $mid = (int)$m['member_id'];
        if ($mid === (int)$admin_id) continue;
        sendNotification($conn, 'admin', $mid, $complaint_id,
            'Conciliation Hearing Saved',
            "Complaint #{$meta['reference_number']} — the Chairperson saved the hearing. Outcome: {$outcomeLabel}.",
            'info');
    }
    notifyAdminsByRole($conn, ['captain','secretary'], $complaint_id,
        'Conciliation Hearing Saved',
        "Complaint #{$meta['reference_number']} — outcome: {$outcomeLabel}.",
        'info');

    $creator = getComplaintCreator($conn, $complaint_id);
    if ($creator && $outcome !== 'settled') {
        if ($outcome === 'dismissed') {
            sendNotification($conn, 'resident', $creator, $complaint_id,
                'Complaint Dismissed',
                "Complaint #{$meta['reference_number']} was dismissed.", 'error');
        } elseif ($outcome === 'reschedule') {
            sendNotification($conn, 'resident', $creator, $complaint_id,
                'Conciliation Rescheduled',
                "The conciliation for complaint #{$meta['reference_number']} will be rescheduled.", 'info');
        } else {
            sendNotification($conn, 'resident', $creator, $complaint_id,
                'Conciliation Ended',
                "Conciliation for complaint #{$meta['reference_number']} ended. You may now secure a Certificate to File Action.", 'info');
        }
    }

    echo json_encode([
    'success'          => true,
    'message'          => ($outcome === 'settled')
        ? 'Settled recorded. Waiting for the Pangkat Secretary to write the settlement terms.'
        : 'Hearing record saved.',
    'outcome'          => $outcome,
    'awaits_secretary' => ($outcome === 'settled'),
    'kp16'             => $kp16Result,
    'repudiation_deadline' => $repudiationDeadline,
]);
exit;
}

/* ============================================================
   LUPON — SECRETARY: Issue Certificate to File Action
   ============================================================ */
if ($action === 'lupon_issue_certificate_to_file' && $adminRole === 'lupon') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $reason       = trim($_POST['reason'] ?? '');

    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint_id.']); exit; }

    $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
    if (!$ctx) { echo json_encode(['success'=>false,'message'=>'Not a member.']); exit; }
    if ($ctx['my_role'] !== 'secretary') {
        echo json_encode(['success'=>false,'message'=>'Only the Secretary may issue this certificate.']); exit;
    }

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }
    if ($meta['status'] !== 'pangkat_scheduled') {
        echo json_encode(['success'=>false,'message'=>'No active conciliation.']); exit;
    }

    $hardDeadline = getConciliationHardDeadline($conn, $complaint_id);
    if ($hardDeadline && time() < strtotime($hardDeadline)) {
        echo json_encode([
            'success' => false,
            'message' => 'The conciliation window has not yet expired. Hard deadline: ' .
                         date('F j, Y', strtotime($hardDeadline)) . '.'
        ]);
        exit;
    }

    mysqli_begin_transaction($conn);
    try {
        $certNo = 'CFT-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));

        $u = mysqli_prepare($conn,
            "UPDATE complaints
                SET status = 'certificate_issued',
                    certificate_no = ?,
                    certificate_issued_at = NOW(),
                    certificate_reason = ?,
                    resolved_at = NOW()
              WHERE id = ?");
        mysqli_stmt_bind_param($u, "ssi", $certNo, $reason, $complaint_id);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);

        logUpdate($conn, $complaint_id, 'resolution', $meta['status'], 'certificate_issued',
            "Certificate to File Action issued by Pangkat Secretary. Cert No: {$certNo}. " .
            "PHYSICAL DELIVERY ONLY. Reason: {$reason}",
            $admin_id, 'lupon');

        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success'=>false,'message'=>'Could not issue certificate.']); exit;
    }

    // Try to find the generated certificate PDF path
    $certPdf = null;
    $cpq = mysqli_prepare($conn,
        "SELECT kp21_pdf_path, kp20_pdf_path, kp22_pdf_path
           FROM complaints WHERE id = ?");
    mysqli_stmt_bind_param($cpq, "i", $complaint_id);
    mysqli_stmt_execute($cpq);
    $cprow = mysqli_fetch_assoc(mysqli_stmt_get_result($cpq));
    mysqli_stmt_close($cpq);
    if ($cprow) {
        $certPdf = $cprow['kp21_pdf_path'] ?: ($cprow['kp20_pdf_path'] ?: $cprow['kp22_pdf_path']);
    }

    notifyAdminsByRole($conn, ['captain'], $complaint_id,
        'Certificate to File Action Ready',
        "Complaint #{$meta['reference_number']} — Cert No {$certNo}. Physical delivery required.",
        'info',
        null,
        $certPdf);

    notifyPangkatSecretaryPrintRequired(
        $conn,
        $complaint_id,
        '⚠ PRINT REQUIRED — Certificate to File Action',
        "Complaint #{$meta['reference_number']} — Cert No {$certNo}. "
          . "Print and hand-deliver the Certificate to File Action to both parties.",
        $certPdf
    );

    echo json_encode([
        'success' => true,
        'message' => 'Certificate to File Action issued.',
        'certificate_no' => $certNo,
    ]);
    exit;
}



/* ============================================================
   LUPON — CHAIR: Get conciliation resume state
   Mirrors the Captain's get_mediation_resume_state endpoint.
   ============================================================ */
if ($action === 'get_concil_resume_state' && $adminRole === 'lupon') {
    $complaint_id = (int)($_GET['complaint_id'] ?? $_POST['complaint_id'] ?? 0);
    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint_id.']); exit; }

    $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
    if (!$ctx) { echo json_encode(['success'=>false,'message'=>'Not a member.']); exit; }

    $s = mysqli_prepare($conn,
        "SELECT id, status, concil_absence_waiting_since,
                concil_absence_waiting_hearing, concil_absence_appearance_at,
                concil_hearing_started_at
           FROM complaints WHERE id = ?");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $case = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    if (!$case) { echo json_encode(['success'=>false,'message'=>'Complaint not found.']); exit; }

    $step = 'attendance';
    $waiting = false;

    if (!empty($case['concil_absence_appearance_at'])) {
        $waiting = true;
        $step    = 'absence_decision';
    } elseif ($case['status'] === 'pangkat_scheduled' && !empty($case['concil_hearing_started_at'])) {
        $step = 'attendance';
    }

    $hearing = getLatestConciliationHearing($conn, $complaint_id);
    $hearing_id = (int)($hearing['id'] ?? 0);
    $explainDeadline = $hearing['explain_window_deadline'] ?? null;

    $attendance = [];
    if ($hearing_id) {
        $aq = mysqli_prepare($conn,
            "SELECT p.id AS party_id, p.party_type, p.full_name,
                    COALESCE(a.status, 'pending') AS status,
                    a.justification_status, a.justification_reason,
                    a.appearance_notes, a.kp18_or_19_pdf_path
               FROM complaint_parties p
               LEFT JOIN complaint_attendance a
                      ON a.party_id = p.id
                     AND a.complaint_id = p.complaint_id
                     AND a.hearing_id = ?
              WHERE p.complaint_id = ?
                AND p.party_type IN ('complainant','respondent')
              ORDER BY FIELD(p.party_type,'complainant','respondent'), p.id");
        mysqli_stmt_bind_param($aq, "ii", $hearing_id, $complaint_id);
        mysqli_stmt_execute($aq);
        $ar = mysqli_stmt_get_result($aq);
        while ($row = mysqli_fetch_assoc($ar)) $attendance[] = $row;
        mysqli_stmt_close($aq);
    }

    echo json_encode([
        'success'         => true,
        'step'            => $step,
        'waiting'         => $waiting,
        'my_role'         => $ctx['my_role'],
        'case_status'     => $case['status'],
        'appearance_at'   => $case['concil_absence_appearance_at'],
        'explain_deadline'=> $explainDeadline,
        'hearing_id'      => $hearing_id,
        'attendance'      => $attendance,
    ]);
    exit;
}
/* ============================================================
   LUPON: Get hearing state (inline hearing view — NO LOCK)
   Now returns the 5-day explain window from complaint_hearings.
   ============================================================ */
if ($action === 'get_lupon_hearing_state' && $adminRole === 'lupon') {
    $complaint_id = (int)($_GET['complaint_id'] ?? 0);

    $isActive = false;
    $myRole = null;
    $ref = null;
    $title = null;
    $hearing = null;
    $attendance = [];
    $waitingSince = null;
    $appearanceAt = null;
    $explainDeadline = null;
    $startedAt = null;
    $activeComplaintId = 0;

    if ($complaint_id > 0) {
        $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
        if (!$ctx) { echo json_encode(['success'=>false,'message'=>'Not a member.']); exit; }
        $myRole = $ctx['my_role'];

               $ms = mysqli_prepare($conn,
            "SELECT id, reference_number, title, status,
                    concil_hearing_started_at, concil_absence_waiting_since,
                    concil_absence_appearance_at, kp16_pdf_path,
                    mediation_outcome,
                    kp14_pdf_path, kp14_generated_at,
                    arbitration_agreement_signed_at,
                    repudiation_deadline,
                    arbitration_repudiated, arbitration_repudiated_at, arbitration_repudiated_by_party,
                    arbitration_award_deadline,
                    kp15_pdf_path, kp15_generated_at
               FROM complaints WHERE id = ?");
        mysqli_stmt_bind_param($ms, "i", $complaint_id);
        mysqli_stmt_execute($ms);
        $meta = mysqli_fetch_assoc(mysqli_stmt_get_result($ms));
        mysqli_stmt_close($ms);
        if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }

        $awaitsTermsNow = (
    $meta['status'] === 'settled_pending' &&
    empty($meta['kp16_pdf_path']) &&
    empty($meta['concil_hearing_started_at'])
);

$isActive = (
    (!empty($meta['concil_hearing_started_at']) && $meta['status'] === 'pangkat_scheduled')
    || $awaitsTermsNow
);
        $activeComplaintId = $complaint_id;
        $ref = $meta['reference_number'];
        $title = $meta['title'];
        $waitingSince = $meta['concil_absence_waiting_since'];
        $appearanceAt = $meta['concil_absence_appearance_at'];
        $startedAt = $meta['concil_hearing_started_at'];
    } else {
        $q = mysqli_prepare($conn,
            "SELECT c.id, c.reference_number, c.title,
                    c.concil_hearing_started_at, c.concil_absence_waiting_since,
                    c.concil_absence_appearance_at,
                    c.status,
                    cp.role AS my_role
               FROM complaints c
               JOIN complaint_pangkat cp ON cp.complaint_id = c.id AND cp.member_id = ?
              WHERE c.status = 'pangkat_scheduled'
                AND c.concil_hearing_started_at IS NOT NULL
              ORDER BY c.concil_hearing_started_at DESC
              LIMIT 1");
        mysqli_stmt_bind_param($q, "i", $admin_id);
        mysqli_stmt_execute($q);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
        mysqli_stmt_close($q);
        if ($row) {
            $activeComplaintId = (int)$row['id'];
            $isActive = true;
            $myRole = $row['my_role'];
            $ref    = $row['reference_number'];
            $title  = $row['title'];
            $waitingSince = $row['concil_absence_waiting_since'];
            $appearanceAt = $row['concil_absence_appearance_at'];
            $startedAt = $row['concil_hearing_started_at'];
        }
    }

    if ($isActive && $activeComplaintId) {
        $hq = mysqli_prepare($conn,
            "SELECT id, hearing_date, hearing_time, location, status, session_number,
                    explain_window_started_at, explain_window_deadline,
                    explain_window_resolved_at, explain_window_auto_closed
               FROM complaint_hearings
              WHERE complaint_id = ? AND session_type = 'conciliation'
              ORDER BY hearing_date DESC, hearing_time DESC, id DESC LIMIT 1");
        mysqli_stmt_bind_param($hq, "i", $activeComplaintId);
        mysqli_stmt_execute($hq);
        $hearing = mysqli_fetch_assoc(mysqli_stmt_get_result($hq));
        mysqli_stmt_close($hq);
        $hearingId = (int)($hearing['id'] ?? 0);

        if (!empty($hearing['explain_window_deadline'])) {
            $explainDeadline = $hearing['explain_window_deadline'];
        }

        $att = mysqli_prepare($conn,
            "SELECT p.id AS party_id, p.party_type, p.full_name,
                    COALESCE(a.status, 'pending') AS status,
                    a.recorded_at
               FROM complaint_parties p
               LEFT JOIN complaint_attendance a
                      ON a.party_id = p.id
                     AND a.complaint_id = p.complaint_id
                     AND a.hearing_id = ?
              WHERE p.complaint_id = ?
                AND p.party_type IN ('complainant','respondent')
              ORDER BY FIELD(p.party_type,'complainant','respondent'), p.id");
        mysqli_stmt_bind_param($att, "ii", $hearingId, $activeComplaintId);
        mysqli_stmt_execute($att);
        $ar = mysqli_stmt_get_result($att);
        while ($r = mysqli_fetch_assoc($ar)) $attendance[] = $r;
        mysqli_stmt_close($att);
    }

    $hardDeadline = $activeComplaintId ? getConciliationHardDeadline($conn, $activeComplaintId) : null;
$extended = 0; $extDays = 0; $firstHearingDate = null;
if ($activeComplaintId) {
    $wq = mysqli_prepare($conn,
        "SELECT conciliation_extended, conciliation_extension_days,
                conciliation_first_hearing_date
           FROM complaints WHERE id = ?");
    mysqli_stmt_bind_param($wq, "i", $activeComplaintId);
    mysqli_stmt_execute($wq);
    $wr = mysqli_fetch_assoc(mysqli_stmt_get_result($wq));
    mysqli_stmt_close($wq);
    if ($wr) {
        $extended = (int)$wr['conciliation_extended'];
        $extDays  = (int)$wr['conciliation_extension_days'];
        $firstHearingDate = $wr['conciliation_first_hearing_date'];
    }
}

    echo json_encode([
        'success'      => true,
        'is_live'      => $isActive,
        'is_active'    => $isActive,
        'complaint_id' => $activeComplaintId,
        'my_role'      => $myRole,
        'reference'    => $ref,
        'title'        => $title,
        'hearing'      => $hearing,
        'hearing_id'   => $hearing ? (int)$hearing['id'] : 0,
        'attendance'   => $attendance,
        'concil_absence_waiting_since'   => $waitingSince,
        'concil_absence_appearance_at'   => $appearanceAt,
        'concil_absence_explain_deadline'=> $explainDeadline,
        'concil_hearing_started_at'      => $startedAt,
        'conciliation_hard_deadline'     => $hardDeadline,
        'conciliation_extended'          => $extended,
        'conciliation_extension_days'    => $extDays,
        'conciliation_first_hearing_date'=> $firstHearingDate,
        'awaits_secretary_terms' => $awaitsTermsNow,
        'is_within_window'               => $hardDeadline ? (time() <= strtotime($hardDeadline)) : true
    ]);
    exit;
}

/* ============================================================
   LUPON — SECRETARY: Save attendance for conciliation hearing
   ============================================================ */
if ($action === 'secretary_save_concil_attendance' && $adminRole === 'lupon') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $hearing_id   = (int)($_POST['hearing_id']   ?? 0);
    $rows         = json_decode($_POST['attendance'] ?? '[]', true);

    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint_id.']); exit; }
    if (!is_array($rows) || !$rows) { echo json_encode(['success'=>false,'message'=>'No attendance data.']); exit; }

    $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
    if (!$ctx) { echo json_encode(['success'=>false,'message'=>'You are not a member of this Pangkat.']); exit; }
    if ($ctx['my_role'] !== 'secretary') {
        echo json_encode(['success'=>false,'message'=>'Only the Secretary may record attendance.']); exit;
    }

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Complaint not found.']); exit; }

    $validHearingId = 0;
    if ($hearing_id > 0) {
        $vq = mysqli_prepare($conn,
            "SELECT id FROM complaint_hearings
              WHERE id = ? AND complaint_id = ? AND session_type = 'conciliation'
              LIMIT 1");
        mysqli_stmt_bind_param($vq, "ii", $hearing_id, $complaint_id);
        mysqli_stmt_execute($vq);
        $vr = mysqli_fetch_assoc(mysqli_stmt_get_result($vq));
        mysqli_stmt_close($vq);
        if ($vr) $validHearingId = (int)$vr['id'];
    }

    if (!$validHearingId) {
        $latestHearing = getLatestConciliationHearing($conn, $complaint_id);
        if ($latestHearing) $validHearingId = (int)$latestHearing['id'];
    }

    if (!$validHearingId) {
        echo json_encode(['success'=>false,'message'=>'No conciliation hearing found for this case.']);
        exit;
    }
    $hearing_id = $validHearingId;

    $allowed = ['attend','no_show'];
    $absent = [];
    foreach ($rows as $r) {
        $pid = (int)($r['party_id'] ?? 0);
        $st  = $r['status'] ?? '';
        if (!$pid || !in_array($st, $allowed, true)) continue;

        $up = mysqli_prepare($conn,
            "INSERT INTO complaint_attendance
                (complaint_id, hearing_id, party_id, status, recorded_by, recorded_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                recorded_by = VALUES(recorded_by),
                recorded_at = NOW()");
        mysqli_stmt_bind_param($up, "iiisi", $complaint_id, $hearing_id, $pid, $st, $admin_id);
        mysqli_stmt_execute($up);
        mysqli_stmt_close($up);

        if ($st === 'no_show') {
            $pi = mysqli_prepare($conn,
                "SELECT id, full_name, party_type, matched_resident_id, contact_number
                 FROM complaint_parties WHERE id = ?");
            mysqli_stmt_bind_param($pi, "i", $pid);
            mysqli_stmt_execute($pi);
            $p = mysqli_fetch_assoc(mysqli_stmt_get_result($pi));
            mysqli_stmt_close($pi);
            if ($p) $absent[] = $p;
        }
    }

    if (!empty($absent)) {
        $u = mysqli_prepare($conn,
            "UPDATE complaints
                SET concil_absence_waiting_since   = NOW(),
                    concil_absence_waiting_hearing = ?
              WHERE id = ?");
        mysqli_stmt_bind_param($u, "ii", $hearing_id, $complaint_id);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);

        logUpdate($conn, $complaint_id, 'note', null, null,
            "Secretary recorded attendance (conciliation hearing #{$hearing_id}) — " . count($absent) . " party(ies) NO SHOW. Escalated to Chairperson.",
            $admin_id, 'lupon');

        $chairId = null;
        foreach ($ctx['members'] as $m) {
            if ($m['role'] === 'chair') { $chairId = (int)$m['member_id']; break; }
        }
        if ($chairId) {
            $mname = implode(', ', array_map(fn($a) => $a['full_name'], $absent));
            sendNotification($conn, 'admin', $chairId, $complaint_id,
                '⚠️ No-Show at Conciliation — Set Appearance Date',
                "Complaint #{$meta['reference_number']}: {$mname} did not appear. Please set an appearance date.",
                'warning');
            if (function_exists('pushToUser')) {
                pushToUser($conn, $chairId, 'No-Show — Action Required',
                    "Set appearance date for absent party.",
                    '/BRITE/admin/dashboards/lupon_dashboard.php');
            }
        }

        echo json_encode([
            'success' => true,
            'hearing_id' => $hearing_id,
            'absent_detected' => true,
            'absent_parties' => array_map(fn($a) => ['id'=>$a['id'], 'full_name'=>$a['full_name'], 'role'=>$a['party_type']], $absent),
            'message' => 'Attendance saved. No-show detected — the Chairperson must now set an appearance date.',
        ]);
        exit;
    }

    $u = mysqli_prepare($conn,
        "UPDATE complaints
            SET concil_absence_waiting_since   = NULL,
                concil_absence_waiting_hearing = NULL,
                concil_absence_appearance_at   = NULL
          WHERE id = ?");
    mysqli_stmt_bind_param($u, "i", $complaint_id);
    mysqli_stmt_execute($u);
    mysqli_stmt_close($u);

    logUpdate($conn, $complaint_id, 'note', null, null,
        "Secretary recorded attendance for conciliation hearing #{$hearing_id}. All parties present.",
        $admin_id, 'lupon');

    echo json_encode([
        'success' => true,
        'hearing_id' => $hearing_id,
        'message' => 'Attendance saved. All parties present.'
    ]);
    exit;
}

/* ============================================================
   LUPON — SECRETARY: Save statement records (text)
   ============================================================ */
if ($action === 'lupon_save_statement_records' && $adminRole === 'lupon') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $hearing_id   = (int)($_POST['hearing_id'] ?? 0);
    $csText       = trim($_POST['complainant_statement'] ?? '');
    $rsText       = trim($_POST['respondent_statement'] ?? '');

    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint_id.']); exit; }

    $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
    if (!$ctx) { echo json_encode(['success'=>false,'message'=>'You are not a member of this Pangkat.']); exit; }
    if (!in_array($ctx['my_role'], ['chair','secretary'], true)) {
        echo json_encode(['success'=>false,'message'=>'Only the Chairperson or Secretary may record statements.']); exit;
    }

    if (!$hearing_id) {
        $latestHearing = getLatestConciliationHearing($conn, $complaint_id);
        if ($latestHearing) $hearing_id = (int)$latestHearing['id'];
    }

    if (!$hearing_id) {
        echo json_encode(['success'=>false,'message'=>'No conciliation hearing found.']); exit;
    }

    $es = mysqli_prepare($conn, "SELECT session_record FROM complaint_hearings WHERE id = ?");
    mysqli_stmt_bind_param($es, "i", $hearing_id);
    mysqli_stmt_execute($es);
    $er = mysqli_fetch_assoc(mysqli_stmt_get_result($es));
    mysqli_stmt_close($es);
    $sr = !empty($er['session_record']) ? json_decode($er['session_record'], true) : [];
    if (!is_array($sr)) $sr = [];

    if ($csText !== '') {
        if (!isset($sr['complainant_statement']) || !is_array($sr['complainant_statement'])) $sr['complainant_statement'] = [];
        $sr['complainant_statement']['text'] = $csText;
        $sr['complainant_statement']['updated_at'] = date('Y-m-d H:i:s');
        $sr['complainant_statement']['updated_by'] = $admin_id;
    }
    if ($rsText !== '') {
        if (!isset($sr['respondent_statement']) || !is_array($sr['respondent_statement'])) $sr['respondent_statement'] = [];
        $sr['respondent_statement']['text'] = $rsText;
        $sr['respondent_statement']['updated_at'] = date('Y-m-d H:i:s');
        $sr['respondent_statement']['updated_by'] = $admin_id;
    }

    $srJson = json_encode($sr, JSON_UNESCAPED_UNICODE);
    $u = mysqli_prepare($conn, "UPDATE complaint_hearings SET session_record = ? WHERE id = ?");
    mysqli_stmt_bind_param($u, "si", $srJson, $hearing_id);
    mysqli_stmt_execute($u);
    mysqli_stmt_close($u);

    logUpdate($conn, $complaint_id, 'note', null, null,
        "Party statements recorded/updated by " . strtoupper($ctx['my_role']) . ".",
        $admin_id, 'lupon');

    echo json_encode(['success'=>true,'message'=>'Statements saved.']);
    exit;
}

/* ============================================================
   LUPON — CHAIR: Set appearance date for absent parties
   Writes explain window to complaint_hearings.
   ============================================================ */
if ($action === 'chair_set_appearance_date' && $adminRole === 'lupon') {
    $complaint_id  = (int)($_POST['complaint_id'] ?? 0);
    $appearance_at = trim($_POST['appearance_at'] ?? '');

    if (!$complaint_id || !$appearance_at) {
        echo json_encode(['success'=>false,'message'=>'Missing complaint_id or appearance_at.']); exit;
    }

    $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
    if (!$ctx) { echo json_encode(['success'=>false,'message'=>'Not a member.']); exit; }
    if ($ctx['my_role'] !== 'chair') {
        echo json_encode(['success'=>false,'message'=>'Only the Chairperson may set the appearance date.']); exit;
    }

    $ts = strtotime($appearance_at);
    if ($ts === false) { echo json_encode(['success'=>false,'message'=>'Invalid date/time.']); exit; }
    $appHour = (int)date('G', $ts);
    if ($appHour < 8 || $appHour >= 18) {
        echo json_encode(['success'=>false,'message'=>'Appearance time must be between 8:00 AM and 6:00 PM.']); exit;
    }
    $appearance_sql = date('Y-m-d H:i:s', $ts);
    $explainDeadlineSql = date('Y-m-d H:i:s', strtotime($appearance_sql . ' +' . EXPLAIN_WINDOW_DAYS . ' days'));

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Complaint not found.']); exit; }

    $latestHearing = getLatestConciliationHearing($conn, $complaint_id);
    if (!$latestHearing) { echo json_encode(['success'=>false,'message'=>'No conciliation hearing found.']); exit; }
    $hearing_id = (int)$latestHearing['id'];

    $aq = mysqli_prepare($conn,
        "SELECT p.id, p.full_name, p.party_type, p.matched_resident_id, p.contact_number
           FROM complaint_parties p
           JOIN complaint_attendance a ON a.party_id = p.id
          WHERE a.complaint_id = ?
            AND a.hearing_id   = ?
            AND a.status       = 'no_show'");
    mysqli_stmt_bind_param($aq, "ii", $complaint_id, $hearing_id);
    mysqli_stmt_execute($aq);
    $ar = mysqli_stmt_get_result($aq);
    $absent = [];
    while ($row = mysqli_fetch_assoc($ar)) $absent[] = $row;
    mysqli_stmt_close($aq);

    if (empty($absent)) {
        echo json_encode(['success'=>false,'message'=>'No absent parties on record for this hearing.']); exit;
    }

    // Update complaint flags for backward compat
    $u = mysqli_prepare($conn,
        "UPDATE complaints
            SET concil_absence_appearance_at = ?
          WHERE id = ?");
    mysqli_stmt_bind_param($u, "si", $appearance_sql, $complaint_id);
    mysqli_stmt_execute($u);
    mysqli_stmt_close($u);

    // Write the explain window on the hearing
    $uh = mysqli_prepare($conn,
        "UPDATE complaint_hearings
            SET explain_window_started_at  = NOW(),
                explain_window_deadline    = ?,
                explain_window_resolved_at = NULL,
                explain_window_auto_closed = 0
          WHERE id = ?");
    mysqli_stmt_bind_param($uh, "si", $explainDeadlineSql, $hearing_id);
    mysqli_stmt_execute($uh);
    mysqli_stmt_close($uh);

    $notices = [];
    $printList = [];       // parties with no account → print required
    $firstKp1819Pdf = null;

    foreach ($absent as $p) {
        $formTag = ($p['party_type'] === 'complainant') ? 'kp18' : 'kp19';

        $res = generate_kp_form_on_demand($conn, $complaint_id, $formTag, [
            'issued_by'    => $adminName ?: 'PANGKAT CHAIRPERSON',
            'hearing_date' => date('Y-m-d', $ts),
            'hearing_time' => date('H:i', $ts),
            'location'     => 'Barangay Hall',
            'party_id'     => $p['id'],
            'hearing_id'   => $hearing_id,
            'context'      => 'conciliation',
            'missed_hearing_date' => $latestHearing['hearing_date'] ?? null,
            'missed_hearing_time' => $latestHearing['hearing_time'] ?? null,
        ]);

        // Persist the generated PDF onto the attendance row so build_kp_forms_list picks it up.
        $pdfPath = $res['pdf_path'] ?? null;
        if (!empty($res['ok']) && $pdfPath) {
            $u = mysqli_prepare($conn,
                "UPDATE complaint_attendance
                    SET kp18_or_19_pdf_path     = ?,
                        kp18_or_19_generated_at = NOW()
                  WHERE complaint_id = ? AND hearing_id = ? AND party_id = ?");
            mysqli_stmt_bind_param($u, "siii", $pdfPath, $complaint_id, $hearing_id, $p['id']);
            mysqli_stmt_execute($u);
            mysqli_stmt_close($u);
            if ($firstKp1819Pdf === null) $firstKp1819Pdf = $pdfPath;
        }

        $matchedId   = tryMatchResident($conn, $p);
        $printNeeded = !$matchedId;

        if ($matchedId) {
            // Party has a resident account → push to them
            $us = mysqli_prepare($conn,
                "UPDATE complaint_parties SET matched_resident_id = ? WHERE id = ?");
            mysqli_stmt_bind_param($us, "ii", $matchedId, $p['id']);
            mysqli_stmt_execute($us);
            mysqli_stmt_close($us);

            $title = 'You failed to appear at conciliation — Explain before the Pangkat Chairperson';
            $msg   = "You did not appear at the conciliation hearing for complaint #{$meta['reference_number']} "
                   . "as a {$p['party_type']}. Per RA 7160, you are required to appear before the Pangkat Chairperson on "
                   . date('F j, Y', $ts) . " at " . date('g:i A', $ts)
                   . " to explain why you failed to appear. "
                   . "If you do not explain within " . EXPLAIN_WINDOW_DAYS . " days after that date, "
                   . "your absence will be automatically declared UNJUSTIFIED.";
            sendNotification($conn, 'resident', $matchedId, $complaint_id, $title, $msg, 'warning', $pdfPath);
            if (function_exists('pushToUser')) {
                pushToUser($conn, $matchedId, $title, $msg,
                    '/BRITE/resident/dashboards/resident_dashboard.php',
                    $pdfPath);
            }
        } else {
            // No account → mark for print & record
            $us = mysqli_prepare($conn,
                "UPDATE complaint_parties SET notified_via = 'print' WHERE id = ?");
            mysqli_stmt_bind_param($us, "i", $p['id']);
            mysqli_stmt_execute($us);
            mysqli_stmt_close($us);

            $printList[] = [
                'party_id'   => (int)$p['id'],
                'name'       => $p['full_name'],
                'role'       => $p['party_type'],
                'form_tag'   => $formTag,
                'form_label' => ($formTag === 'kp18')
                    ? 'KP Form #18 — Notice of Hearing (Re: Failure to Appear)'
                    : 'KP Form #19 — Notice of Hearing (Re: Failure to Appear)',
                'pdf_url'    => $pdfPath,
            ];
        }

        $notices[] = [
            'party_id'   => (int)$p['id'],
            'name'       => $p['full_name'],
            'role'       => $p['party_type'],
            'form_tag'   => $formTag,
            'ok'         => !empty($res['ok']),
            'pdf_url'    => $pdfPath,
            'print'      => $printNeeded,
        ];
    }

    // Notify ONLY the Pangkat Secretary that printing is required.
    if (!empty($printList)) {
        $names = array_map(fn($n) => $n['role'] . ' ' . $n['name'], $printList);
        notifyPangkatSecretaryPrintRequired(
            $conn,
            $complaint_id,
            '⚠ PRINT REQUIRED — KP #18/#19',
            "Complaint #{$meta['reference_number']} — "
            . count($printList) . " part" . (count($printList) > 1 ? 'ies' : 'y')
            . " without a resident account. Print & hand-deliver before "
            . date('F j, Y g:i A', $ts) . ":\n• " . implode("\n• ", $names),
            $firstKp1819Pdf
        );
    }

    logUpdate($conn, $complaint_id, 'note', null, null,
        "Chairperson set appearance date for " . count($absent) . " absent part(ies) on " . date('F j, Y g:i A', $ts) . ". KP #18/#19 issued. "
        . EXPLAIN_WINDOW_DAYS . "-day explain deadline: " . date('F j, Y g:i A', strtotime($explainDeadlineSql)) . ".",
        $admin_id, 'lupon');

    foreach ($ctx['members'] as $m) {
        $mid = (int)$m['member_id'];
        if ($mid === (int)$admin_id) continue;
        sendNotification($conn, 'admin', $mid, $complaint_id,
            'Appearance Date Set',
            "Complaint #{$meta['reference_number']} — Chairperson set an appearance date for absent parties.",
            'info');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Appearance date set and notices issued.',
        'notices' => $notices,
        'appearance_at' => $appearance_sql,
        'explain_deadline' => $explainDeadlineSql,
    ]);
    exit;
}

/* ============================================================
   LUPON — CHAIR: Decide Justified/Unjustified after appearance
   ============================================================ */
if ($action === 'chair_resolve_concil_absence' && $adminRole === 'lupon') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $decisions    = json_decode($_POST['decisions'] ?? '[]', true);

    if (!$complaint_id || !is_array($decisions)) {
        echo json_encode(['success'=>false,'message'=>'Missing fields.']); exit;
    }

    $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
    if (!$ctx) { echo json_encode(['success'=>false,'message'=>'Not a member.']); exit; }
    if ($ctx['my_role'] !== 'chair') {
        echo json_encode(['success'=>false,'message'=>'Only the Chairperson may decide on absences.']); exit;
    }

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Complaint not found.']); exit; }

    $complainantUnjustified = false;
    $respondentUnjustified  = false;

    $latestHearing = getLatestConciliationHearing($conn, $complaint_id);
    if (!$latestHearing) { echo json_encode(['success'=>false,'message'=>'No conciliation hearing.']); exit; }
    $hearing_id = (int)$latestHearing['id'];

    $u = mysqli_prepare($conn,
        "UPDATE complaint_attendance
            SET justification_status     = ?,
                justification_reason     = ?,
                justification_decided_at = NOW(),
                appearance_notes         = ?
          WHERE complaint_id = ? AND hearing_id = ? AND party_id = ?");
    foreach ($decisions as $d) {
        $pid    = (int)($d['party_id'] ?? 0);
        $status = $d['status'] ?? '';
        $notes  = trim($d['appearance_notes'] ?? '');
        $ptype  = $d['party_type'] ?? '';
        if (!$pid || !in_array($status, ['justified','unjustified'], true)) continue;
        if ($status === 'unjustified') {
            if ($ptype === 'complainant') $complainantUnjustified = true;
            if ($ptype === 'respondent')  $respondentUnjustified  = true;
        }
        mysqli_stmt_bind_param($u, "sssiii", $status, $notes, $notes, $complaint_id, $hearing_id, $pid);
        mysqli_stmt_execute($u);
    }
    mysqli_stmt_close($u);

    if ($complainantUnjustified) {
        $outcome = 'dismissed_complaint';
        $newStatus = 'dismissed';
} elseif ($respondentUnjustified) {
    $outcome = 'respondent_no_show';
    $newStatus = 'unresolved';
}else {
        $outcome = 'reschedule';
        $newStatus = 'pangkat_constituted';
    }

    $u = mysqli_prepare($conn,
        "UPDATE complaints
            SET status = ?,
                concil_absence_waiting_since   = NULL,
                concil_absence_waiting_hearing = NULL,
                concil_absence_appearance_at   = NULL,
                concil_hearing_started_at      = NULL
          WHERE id = ?");
    mysqli_stmt_bind_param($u, "si", $newStatus, $complaint_id);
    mysqli_stmt_execute($u);
    mysqli_stmt_close($u);

    // ✅ Clear the explain window on the hearing itself so autoResolveConcilAbsences()
    //    does not re-process this hearing.
    $uh = mysqli_prepare($conn,
        "UPDATE complaint_hearings
            SET status = 'completed',
                outcome = 'no_settlement',
                completed_at = NOW(),
                explain_window_started_at  = NULL,
                explain_window_deadline    = NULL,
                explain_window_resolved_at = NOW(),
                explain_window_auto_closed = 0
          WHERE id = ?");
    mysqli_stmt_bind_param($uh, "i", $hearing_id);
    mysqli_stmt_execute($uh);
    mysqli_stmt_close($uh);

    $uh = mysqli_prepare($conn,
        "UPDATE complaint_hearings
            SET status='completed', outcome='no_settlement', completed_at=NOW(),
                explain_window_resolved_at = NOW()
          WHERE id = ?");
    mysqli_stmt_bind_param($uh, "i", $hearing_id);
    mysqli_stmt_execute($uh);
    mysqli_stmt_close($uh);

    $certInfo = null;
    $certPdfPathForNotification = null;

        if ($outcome === 'dismissed_complaint') {
        $certRes = save_bar_action_cert(
            $conn, $complaint_id, date('Y-m-d'),
            'Complainant failed to appear without justifiable cause (conciliation level).',
            $adminName ?: 'PANGKAT CHAIRPERSON'
        );
        $certNo = 'CBA-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
        if (!empty($certRes['ok'])) {
            $uq = mysqli_prepare($conn,
                "UPDATE complaints SET bar_action_cert_no = ?, bar_action_issued_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($uq, "si", $certNo, $complaint_id);
            mysqli_stmt_execute($uq);
            mysqli_stmt_close($uq);
            $certPdfPathForNotification = $certRes['pdf_path'] ?? null;
            $certInfo = [
                'form' => 'KP_FORM_23',
                'label' => 'KP Form #23 — Certification to Bar Action',
                'cert_no' => $certNo,
                'pdf_url' => $certRes['pdf_path'],
            ];
        }

        // ── Notify BOTH parties with a copy of KP #23 ──
        $pq = mysqli_prepare($conn,
            "SELECT id, party_type, full_name, matched_resident_id, contact_number
               FROM complaint_parties
              WHERE complaint_id = ? AND party_type IN ('complainant','respondent')");
        mysqli_stmt_bind_param($pq, "i", $complaint_id);
        mysqli_stmt_execute($pq);
        $pqr = mysqli_stmt_get_result($pq);
        while ($prow = mysqli_fetch_assoc($pqr)) {
            $matched = tryMatchResident($conn, $prow);
            if (!$matched) continue;

            $roleLabel = ($prow['party_type'] === 'complainant') ? 'Complainant' : 'Respondent';
            $title = 'Complaint Dismissed — KP Form #23 (' . $roleLabel . ')';
            $msg   = ($prow['party_type'] === 'complainant')
                ? "You failed to appear at conciliation for complaint #{$meta['reference_number']} without justifiable cause. "
                  . "The complaint is DISMISSED and you are barred from filing in court."
                : "The complainant failed to appear at conciliation for complaint #{$meta['reference_number']}. "
                  . "The complaint is DISMISSED.";

            sendNotification($conn, 'resident', $matched, $complaint_id,
                $title, $msg, 'error', $certPdfPathForNotification);
            if (function_exists('pushToUser')) {
                pushToUser($conn, $matched, $title, $msg,
                    '/BRITE/resident/dashboards/resident_dashboard.php',
                    $certPdfPathForNotification);
            }
        }
        mysqli_stmt_close($pq);

        notifyAdminsByRole($conn, ['secretary'], $complaint_id,
            'Certification to Bar Action Ready',
            "Complaint #{$meta['reference_number']} — KP Form #23 auto-issued.",
            'warning',
            null,
            $certPdfPathForNotification);

        notifyPangkatSecretaryPrintRequired(
            $conn,
            $complaint_id,
            '⚠ PRINT REQUIRED — KP Form #23',
            "Complaint #{$meta['reference_number']} — complainant unjustified. "
              . "Print and hand-deliver KP Form #23 (Certification to Bar Action) to both parties — ONE copy each.",
            $certPdfPathForNotification
        );
    }

    logUpdate($conn, $complaint_id, 'resolution', null, $newStatus,
        "Chairperson decided on conciliation absence: " . strtoupper($outcome),
        $admin_id, 'lupon');

    $creator = getComplaintCreator($conn, $complaint_id);
    if ($creator) {
        if ($outcome === 'dismissed_complaint') {
            sendNotification($conn, 'resident', $creator, $complaint_id,
                'Complaint Dismissed',
                "Complaint #{$meta['reference_number']} was DISMISSED because you did not appear at conciliation without justifiable cause.",
                'error',
                $certPdfPathForNotification);   // ✅ NEW
        } elseif ($outcome === 'respondent_no_show') {
        $certRes = save_bar_counterclaim_cert(
            $conn, $complaint_id, date('Y-m-d'),
            'Respondent failed to appear without justifiable cause (conciliation level).',
            $adminName ?: 'PANGKAT CHAIRPERSON'
        );
        $certNo = 'CBC-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
        if (!empty($certRes['ok'])) {
            $uq = mysqli_prepare($conn,
                "UPDATE complaints SET bar_counterclaim_cert_no = ?, bar_counterclaim_issued_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($uq, "si", $certNo, $complaint_id);
            mysqli_stmt_execute($uq);
            mysqli_stmt_close($uq);
            $certPdfPathForNotification = $certRes['pdf_path'] ?? null;
            $certInfo = [
                'form' => 'KP_FORM_24',
                'label' => 'KP Form #24 — Certification to Bar Counterclaim',
                'cert_no' => $certNo,
                'pdf_url' => $certRes['pdf_path'],
            ];
        }

        // ── Auto-generate KP #22 (Certification to File Action — Pangkat Chairman) ──
        $kp22Res = generate_kp_form_on_demand($conn, $complaint_id, 'kp22', [
            'issued_by' => $adminName ?: 'PANGKAT CHAIRPERSON',
        ]);
        if (!empty($kp22Res['ok'])) {
            logUpdate($conn, $complaint_id, 'note', null, null,
                "KP Form #22 (Certification to File Action — Pangkat Chairman) auto-issued → {$kp22Res['pdf_path']}",
                $admin_id, 'lupon');
        }

        // Notify BOTH parties about KP #24
        $pq = mysqli_prepare($conn,
            "SELECT id, party_type, full_name, matched_resident_id, contact_number
               FROM complaint_parties
              WHERE complaint_id = ? AND party_type IN ('complainant','respondent')");
        mysqli_stmt_bind_param($pq, "i", $complaint_id);
        mysqli_stmt_execute($pq);
        $pqr = mysqli_stmt_get_result($pq);
        while ($prow = mysqli_fetch_assoc($pqr)) {
            $matched = tryMatchResident($conn, $prow);
            if (!$matched) continue;

            $roleLabel = ($prow['party_type'] === 'complainant') ? 'Complainant' : 'Respondent';
            $title = 'Certificate to Bar Counterclaim — KP #24 (' . $roleLabel . ')';
            $msg   = "The respondent failed to appear at conciliation for complaint #{$meta['reference_number']} without justifiable cause. "
                   . "The case proceeds to Certification to File Action. please visit the Barangay Hall to obtain KP Form #22 (Certification to File Action — Pangkat Chairman) and file the case in court.";

            sendNotification($conn, 'resident', $matched, $complaint_id,
                $title, $msg, 'info', $certPdfPathForNotification);
            if (function_exists('pushToUser')) {
                pushToUser($conn, $matched, $title, $msg,
                    '/BRITE/resident/dashboards/resident_dashboard.php',
                    $certPdfPathForNotification);
            }
        }
        mysqli_stmt_close($pq);

        // ── PRINT REQUIRED notification for the Pangkat Secretary ──
        // KP #22 (Certification to File Action from Pangkat Chairman) is the key document
        // that the complainant needs to file the case in court. It's auto-generated
        // but stored as a soft copy — the Pangkat Secretary must print and hand-deliver it.
        if (!empty($kp22Res['ok']) && !empty($kp22Res['pdf_path'])) {
            notifyPangkatSecretaryPrintRequired(
                $conn,
                $complaint_id,
                '⚠ PRINT REQUIRED — KP Form #22',
                "Complaint #{$meta['reference_number']} — respondent unjustified (did not appear at conciliation). "
                  . "KP Form #22 (Certification to File Action — Pangkat Chairman) has been auto-generated. "
                  . "Print and hand-deliver it to the complainant so they can file the case in court.",
                $kp22Res['pdf_path']
            );
        }
    } else {
            sendNotification($conn, 'resident', $creator, $complaint_id,
                'Conciliation Rescheduled',
                "The conciliation for complaint #{$meta['reference_number']} will be rescheduled.",
                'info');
        }
    }

    echo json_encode([
        'success' => true,
        'outcome' => $outcome,
        'status'  => $newStatus,
        'cert_info' => $certInfo,
    ]);
    exit;
}

/* ============================================================
   LUPON — CHAIR/SECRETARY: Save session record
   ============================================================ */
if ($action === 'lupon_save_session_record' && $adminRole === 'lupon') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $record_json  = trim($_POST['session_record'] ?? '');
    $hearing_id   = (int)($_POST['hearing_id'] ?? 0);

    if (!$complaint_id || !$record_json) {
        echo json_encode(['success'=>false,'message'=>'Missing data.']); exit;
    }

    $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
    if (!$ctx) {
        echo json_encode(['success'=>false,'message'=>'You are not a member of this Pangkat.']); exit;
    }
    if (!in_array($ctx['my_role'], ['chair','secretary'], true)) {
        echo json_encode(['success'=>false,'message'=>'Only the Chairperson or Secretary may record the session.']); exit;
    }

    $latestHearingRow = getLatestConciliationHearing($conn, $complaint_id);
    if (!$latestHearingRow) {
        echo json_encode(['success'=>false,'message'=>'No conciliation hearing has been scheduled yet.']); exit;
    }

    $today = date('Y-m-d');
    $hd = $latestHearingRow['hearing_date'] ? substr($latestHearingRow['hearing_date'], 0, 10) : null;
    if (!$hd || $hd > $today) {
        echo json_encode(['success'=>false,'message'=>'The conciliation hearing has not started yet.']); exit;
    }

    $sr = json_decode($record_json, true);
    if (!is_array($sr)) {
        echo json_encode(['success'=>false,'message'=>'Invalid session record JSON.']); exit;
    }
    $sr['recorded_by']      = $admin_id;
    $sr['recorded_by_role'] = $ctx['my_role'];
    $sr['recorded_at']      = date('Y-m-d H:i:s');

    if (!$hearing_id) $hearing_id = (int)$latestHearingRow['id'];

    $existing = null;
    $es = mysqli_prepare($conn, "SELECT session_record FROM complaint_hearings WHERE id = ?");
    mysqli_stmt_bind_param($es, "i", $hearing_id);
    mysqli_stmt_execute($es);
    $er = mysqli_fetch_assoc(mysqli_stmt_get_result($es));
    mysqli_stmt_close($es);
    if (!empty($er['session_record'])) {
        $existing = json_decode($er['session_record'], true);
        if (is_array($existing)) {
            $sr = array_merge($existing, $sr);
        }
    }

    $srJson = json_encode($sr, JSON_UNESCAPED_UNICODE);

    $u = mysqli_prepare($conn,
        "UPDATE complaint_hearings SET session_record = ? WHERE id = ?");
    mysqli_stmt_bind_param($u, "si", $srJson, $hearing_id);
    mysqli_stmt_execute($u);
    mysqli_stmt_close($u);

    logUpdate($conn, $complaint_id, 'note', null, null,
        "Pangkat hearing record added by " . strtoupper($ctx['my_role']) . ".", $admin_id, 'lupon');

    echo json_encode(['success'=>true,'message'=>'Session record saved.']);
    exit;
}

/* ============================================================
   LUPON — CHAIR/SECRETARY: Upload SINGLE document
   ============================================================ */
if ($action === 'lupon_upload_document' && $adminRole === 'lupon') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $description  = trim($_POST['description'] ?? '');

    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint_id.']); exit; }
    if (empty($_FILES['document']) || !is_uploaded_file($_FILES['document']['tmp_name'])) {
        echo json_encode(['success'=>false,'message'=>'No file uploaded.']); exit;
    }

    $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
    if (!$ctx) {
        echo json_encode(['success'=>false,'message'=>'You are not a member of this Pangkat.']); exit;
    }
    if (!in_array($ctx['my_role'], ['chair','secretary'], true)) {
        echo json_encode(['success'=>false,'message'=>'Only the Chairperson or Secretary may upload documents.']); exit;
    }

    $uploadDir = __DIR__ . '/../uploads/pangkat_documents';
    if (!file_exists($uploadDir)) @mkdir($uploadDir, 0777, true);

    $origName = basename($_FILES['document']['name']);
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed  = ['pdf','jpg','jpeg','png','gif','webp','doc','docx'];
    if (!in_array($ext, $allowed, true)) {
        echo json_encode(['success'=>false,'message'=>'File type not allowed.']); exit;
    }
    if ($_FILES['document']['size'] > 10 * 1024 * 1024) {
        echo json_encode(['success'=>false,'message'=>'File exceeds 10 MB limit.']); exit;
    }

    $safeName = preg_replace('/[^A-Za-z0-9\.\-_]/', '_', $origName);
    $filename = 'comp' . $complaint_id . '_' . time() . '_' . $safeName;
    $absPath  = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($_FILES['document']['tmp_name'], $absPath)) {
        echo json_encode(['success'=>false,'message'=>'Failed to save file.']); exit;
    }

    $relPath = 'uploads/pangkat_documents/' . $filename;
    $mime    = $_FILES['document']['type'] ?? null;
    $size    = (int)$_FILES['document']['size'];

    $s = mysqli_prepare($conn,
        "INSERT INTO complaint_pangkat_documents
            (complaint_id, uploaded_by, uploaded_by_role, file_name, file_path, file_size, mime_type, description)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($s, "iisssiss",
        $complaint_id, $admin_id, $ctx['my_role'],
        $origName, $relPath, $size, $mime, $description);
    mysqli_stmt_execute($s);
    $newDocId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($s);

    logUpdate($conn, $complaint_id, 'note', null, null,
        "Pangkat document uploaded by " . strtoupper($ctx['my_role']) . ": $origName", $admin_id, 'lupon');

    echo json_encode([
        'success' => true,
        'document' => [
            'id'          => $newDocId,
            'file_name'   => $origName,
            'file_path'   => $relPath,
            'file_size'   => $size,
            'description' => $description,
            'uploaded_by' => $admin_id,
            'uploaded_by_role' => $ctx['my_role'],
            'created_at'  => date('Y-m-d H:i:s'),
        ],
    ]);
    exit;
}

/* ============================================================
   LUPON — CHAIR/SECRETARY: Upload MULTIPLE documents
   ============================================================ */
if ($action === 'lupon_upload_multiple_documents' && $adminRole === 'lupon') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $description  = trim($_POST['description'] ?? '');

    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint_id.']); exit; }

    $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
    if (!$ctx) { echo json_encode(['success'=>false,'message'=>'You are not a member of this Pangkat.']); exit; }
    if (!in_array($ctx['my_role'], ['chair','secretary'], true)) {
        echo json_encode(['success'=>false,'message'=>'Only the Chairperson or Secretary may upload documents.']); exit;
    }

    if (empty($_FILES['documents']) || !is_array($_FILES['documents']['name'])) {
        echo json_encode(['success'=>false,'message'=>'No files uploaded.']); exit;
    }

    $uploadDir = __DIR__ . '/../uploads/pangkat_documents';
    if (!file_exists($uploadDir)) @mkdir($uploadDir, 0777, true);

    $allowed = ['pdf','jpg','jpeg','png','gif','webp','doc','docx'];
    $files = $_FILES['documents'];
    $uploadedCount = 0;
    $uploadedDocs = [];
    $errors = [];

    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = $files['name'][$i] . ': upload error ' . $files['error'][$i];
            continue;
        }
        $origName = basename($files['name'][$i]);
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $errors[] = $origName . ': file type not allowed';
            continue;
        }
        if ($files['size'][$i] > 10 * 1024 * 1024) {
            $errors[] = $origName . ': exceeds 10 MB';
            continue;
        }
        $safeName = preg_replace('/[^A-Za-z0-9\.\-_]/', '_', $origName);
        $filename = 'comp' . $complaint_id . '_' . time() . '_' . $i . '_' . $safeName;
        $absPath  = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($files['tmp_name'][$i], $absPath)) {
            $errors[] = $origName . ': failed to save';
            continue;
        }

        $relPath = 'uploads/pangkat_documents/' . $filename;
        $mime    = $files['type'][$i] ?? null;
        $size    = (int)$files['size'][$i];

        $s = mysqli_prepare($conn,
            "INSERT INTO complaint_pangkat_documents
                (complaint_id, uploaded_by, uploaded_by_role, file_name, file_path, file_size, mime_type, description)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($s, "iisssiss",
            $complaint_id, $admin_id, $ctx['my_role'],
            $origName, $relPath, $size, $mime, $description);
        mysqli_stmt_execute($s);
        $newDocId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($s);

        $uploadedDocs[] = [
            'id' => $newDocId,
            'file_name' => $origName,
            'file_path' => $relPath,
            'file_size' => $size,
        ];
        $uploadedCount++;
    }

    if ($uploadedCount > 0) {
        logUpdate($conn, $complaint_id, 'note', null, null,
            "Pangkat documents uploaded by " . strtoupper($ctx['my_role']) . ": $uploadedCount file(s).",
            $admin_id, 'lupon');
    }

    echo json_encode([
        'success' => $uploadedCount > 0,
        'uploaded_count' => $uploadedCount,
        'documents' => $uploadedDocs,
        'errors' => $errors,
        'message' => $uploadedCount > 0
            ? "$uploadedCount file(s) uploaded."
            : 'No files were uploaded.',
    ]);
    exit;
}

/* ============================================================
   LUPON: Get documents
   ============================================================ */
if ($action === 'lupon_get_documents' && $adminRole === 'lupon') {
    $complaint_id = (int)($_GET['complaint_id'] ?? 0);
    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint_id.']); exit; }

    $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
    if (!$ctx) {
        echo json_encode(['success'=>false,'message'=>'You are not a member of this Pangkat.']); exit;
    }

    $s = mysqli_prepare($conn,
        "SELECT d.*, a.full_name AS uploader_name
         FROM complaint_pangkat_documents d
         LEFT JOIN admin a ON a.id = d.uploaded_by
         WHERE d.complaint_id = ?
         ORDER BY d.created_at DESC");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $docs = [];
    while ($row = mysqli_fetch_assoc($r)) $docs[] = $row;
    mysqli_stmt_close($s);

    echo json_encode(['success'=>true,'documents'=>$docs]);
    exit;
}

/* ============================================================
   LUPON: Delete a document
   ============================================================ */
if ($action === 'lupon_delete_document' && $adminRole === 'lupon') {
    $doc_id = (int)($_POST['document_id'] ?? 0);
    if (!$doc_id) { echo json_encode(['success'=>false,'message'=>'Missing document_id.']); exit; }

    $q = mysqli_prepare($conn,
        "SELECT complaint_id, uploaded_by, file_path
         FROM complaint_pangkat_documents WHERE id = ?");
    mysqli_stmt_bind_param($q, "i", $doc_id);
    mysqli_stmt_execute($q);
    $doc = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
    mysqli_stmt_close($q);
    if (!$doc) { echo json_encode(['success'=>false,'message'=>'Document not found.']); exit; }

    $ctx = getPangkatMemberContext($conn, (int)$doc['complaint_id'], $admin_id);
    if (!$ctx || !in_array($ctx['my_role'], ['chair','secretary'], true)) {
        echo json_encode(['success'=>false,'message'=>'Not allowed.']); exit;
    }

    $abs = __DIR__ . '/../' . $doc['file_path'];
    if (file_exists($abs)) @unlink($abs);

    $d = mysqli_prepare($conn, "DELETE FROM complaint_pangkat_documents WHERE id = ?");
    mysqli_stmt_bind_param($d, "i", $doc_id);
    mysqli_stmt_execute($d);
    mysqli_stmt_close($d);

    echo json_encode(['success'=>true,'message'=>'Document deleted.']);
    exit;
}

/* ============================================================
   LUPON — SECRETARY: Record conciliation outcome (legacy path)
   ============================================================ */
if ($action === 'lupon_record_outcome' && $adminRole === 'lupon') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $outcome      = trim($_POST['outcome'] ?? 'settled');
    $notes        = trim($_POST['notes'] ?? '');
    if ($outcome === 'escalated') $outcome = 'respondent_no_show';

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }

    $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
    if (!$ctx) {
        echo json_encode(['success'=>false,'message'=>'You are not a member of this Pangkat.']); exit;
    }
    if ($ctx['my_role'] !== 'secretary') {
        echo json_encode(['success'=>false,'message'=>'Only the Secretary may record the outcome.']); exit;
    }
    if (!in_array($meta['status'], ['pangkat_constituted','pangkat_scheduled','failed_mediation'], true)) {
        echo json_encode(['success'=>false,'message'=>'This case is not at the Pangkat stage.']); exit;
    }

    $map = [
        'settled'                   => ['status'=>'settled_pending',           'hearing'=>'settled',       'marker'=>''],
        'failed_conciliation_final' => ['status'=>'failed_conciliation_final', 'hearing'=>'no_settlement', 'marker'=>''],
        'failed_mediation'          => ['status'=>'failed_conciliation_final', 'hearing'=>'no_settlement', 'marker'=>''],
        'respondent_no_show'        => ['status'=>'failed_conciliation_final', 'hearing'=>'no_settlement', 'marker'=>'[RESPONDENT NO-SHOW] '],
        'complainant_no_show'       => ['status'=>'dismissed',                 'hearing'=>'no_settlement', 'marker'=>'[COMPLAINANT NO-SHOW] '],
    ];
    if (!isset($map[$outcome])) $outcome = 'failed_conciliation_final';
    $new        = $map[$outcome]['status'];
    $hearingOut = $map[$outcome]['hearing'];
    $fullNotes  = $map[$outcome]['marker'] . $notes;

    $s = mysqli_prepare($conn, "UPDATE complaint_hearings SET status='completed',
        outcome=?, summary=?, completed_at=NOW(),
        explain_window_resolved_at = COALESCE(explain_window_resolved_at, NOW())
        WHERE complaint_id=? AND status='scheduled'
        ORDER BY hearing_date DESC, hearing_time DESC LIMIT 1");
    mysqli_stmt_bind_param($s, "ssi", $hearingOut, $fullNotes, $complaint_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);

    $resolvedSql = ($new === 'settled_pending') ? 'NULL' : 'NOW()';
    $s = mysqli_prepare($conn, "UPDATE complaints SET status=?, resolution_notes=?,
        resolved_at=$resolvedSql WHERE id=?");
    mysqli_stmt_bind_param($s, "ssi", $new, $fullNotes, $complaint_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);

    logUpdate($conn, $complaint_id, 'resolution', $meta['status'], $new,
        "Pangkat outcome: $outcome. $notes", $admin_id, 'lupon');

    $kp16Result = null;
    $repudiationDeadline = null;
    if ($outcome === 'settled') {
        $kp16Result = save_kp_form_16_on_settlement($conn, $complaint_id, 'conciliation', $fullNotes);
        if (!empty($kp16Result['ok'])) {
            logUpdate($conn, $complaint_id, 'note', null, null,
                "KP Form #16 (Amicable Settlement — conciliation) stored as soft copy (no digital signature) → {$kp16Result['pdf_path']}",
                $admin_id, 'lupon');
        }
        $repudiationDeadline = enterSettledPendingState($conn, $complaint_id);
    }

    $creator = getComplaintCreator($conn, $complaint_id);
    if ($creator) {
        switch ($outcome) {
            case 'settled':
                $t = 'Amicable Settlement Signed';
                $msg = "The Amicable Settlement for complaint #{$meta['reference_number']} has been signed. " .
                       "You have 10 days to repudiate it on grounds of fraud, violence, or intimidation.";
                $nt = 'success'; break;
            case 'respondent_no_show':
                $t = 'Conciliation Update';
                $msg = "The respondent did not appear despite due notice. You may now secure a Certificate to File Action.";
                $nt = 'info'; break;
            case 'complainant_no_show':
                $t = 'Complaint Dismissed';
                $msg = "Complaint #{$meta['reference_number']} was DISMISSED because you did not appear.";
                $nt = 'error'; break;
            default:
                $t = 'Conciliation Update';
                $msg = "Conciliation failed. You may now secure a Certificate to File Action.";
                $nt = 'info';
        }
        sendNotification($conn, 'resident', $creator, $complaint_id, $t, $msg, $nt);
    }

    $label = strtoupper(str_replace('_', ' ', $outcome));
    $secMsg = $outcome === 'complainant_no_show'
        ? "Complaint #{$meta['reference_number']} — dismissed. Issue the Certification to Bar Action."
        : ($outcome === 'respondent_no_show'
            ? "Complaint #{$meta['reference_number']} — respondent no-show. Issue Certificate to File Action."
            : "Complaint #{$meta['reference_number']} — outcome: $label.");
    notifyAdminsByRole($conn, ['secretary'], $complaint_id, 'Pangkat Outcome Recorded', $secMsg, 'info');
    notifyAdminsByRole($conn, ['captain'], $complaint_id, 'Pangkat Outcome Recorded',
        "Complaint #{$meta['reference_number']} — $label.", 'info');

    echo json_encode([
        'success'   => true,
        'message'   => 'Outcome recorded.',
        'kp16'      => $kp16Result,
        'repudiation_deadline' => $repudiationDeadline,
    ]);
    exit;
}

/* ============================================================
   POST: generate a KP form on demand
   ============================================================ */
if ($action === 'generate_kp_form') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $form_tag     = trim($_POST['form_tag'] ?? '');
    if (!$complaint_id || !$form_tag) {
        echo json_encode(['success'=>false,'message'=>'Missing complaint_id or form_tag.']); exit;
    }

        $allowed = ['kp12','kp13','kp14','kp15','kp16','kp17','kp18','kp19','kp20','kp21','kp22','kp25','kp26','kp27'];
    if (!in_array($form_tag, $allowed, true)) {
        echo json_encode(['success'=>false,'message'=>'Unsupported form_tag.']); exit;
    }

    if ($adminRole === 'lupon') {
        $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
        if (!$ctx) {
            echo json_encode(['success'=>false,'message'=>'Not assigned to this case.']); exit;
        }

        $myRole = $ctx['my_role'] ?? '';
                $chairTags     = ['kp13','kp14','kp18','kp19'];
        $secretaryTags = ['kp14','kp15','kp16','kp17','kp18','kp19','kp20','kp21','kp22','kp25','kp26','kp27'];

        if ($myRole === 'member') {
            echo json_encode(['success'=>false,'message'=>'Members cannot generate KP forms. Only the Chairperson and Secretary can.']); exit;
        }
        if ($myRole === 'chair' && !in_array($form_tag, $chairTags, true)) {
            echo json_encode(['success'=>false,'message'=>'Chairperson may only generate Subpoena (KP #13) and Agreement for Arbitration (KP #14).']); exit;
        }
        if ($myRole === 'secretary' && !in_array($form_tag, $secretaryTags, true)) {
            echo json_encode(['success'=>false,'message'=>'Secretary cannot generate this KP form.']); exit;
        }
    } elseif (!in_array($adminRole, ['captain','secretary'], true)) {
        echo json_encode(['success'=>false,'message'=>'Not allowed.']); exit;
    }

        $extra = [
        'issued_by'     => $adminName ?: 'BRITE SYSTEM',
        'hearing_date'  => $_POST['hearing_date']  ?? null,
        'hearing_time'  => $_POST['hearing_time']  ?? null,
        'location'      => $_POST['location']      ?? null,
        'award_text'    => $_POST['award_text']    ?? '',
        'terms_text'    => $_POST['terms_text']    ?? '',
        'mode'          => $_POST['mode']          ?? 'mediation',
        'reason'        => $_POST['reason']        ?? '',
        'repudiated_by' => $_POST['repudiated_by'] ?? '',
        'obliged_name'  => $_POST['obliged_name']  ?? '',
        'amount'        => $_POST['amount']        ?? '',
        // ── NEW: fields for KP #18 / #19 ──
        'context'             => $_POST['context']             ?? 'mediation',
        'party_id'            => (int)($_POST['party_id']     ?? 0),
        'hearing_id'          => (int)($_POST['hearing_id']   ?? 0),
        'missed_hearing_date' => $_POST['missed_hearing_date'] ?? null,
        'missed_hearing_time' => $_POST['missed_hearing_time'] ?? null,
    ];

    $res = generate_kp_form_on_demand($conn, $complaint_id, $form_tag, $extra);
    if (!$res['ok']) {
        echo json_encode(['success'=>false,'message'=>$res['error'] ?? 'Failed to generate.']); exit;
    }

    logUpdate($conn, $complaint_id, 'note', null, null,
        strtoupper($form_tag) . " generated on demand → {$res['pdf_path']} (soft copy, no digital signature).",
        $admin_id, $adminRole);

    echo json_encode([
        'success'  => true,
        'message'  => strtoupper($form_tag) . ' generated.',
        'form'     => strtoupper($form_tag),
        'pdf_url'  => $res['pdf_path'],
        'filename' => basename($res['pdf_path']),
    ]);
    exit;
}

/* ============================================================
   POST: upload party statement audio
   ============================================================ */
if ($action === 'upload_party_statement_audio') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $hearing_id   = (int)($_POST['hearing_id'] ?? 0);
    $party_type   = trim($_POST['party_type'] ?? '');

    if (!$complaint_id || !in_array($party_type, ['complainant','respondent'], true)) {
        echo json_encode(['success'=>false,'message'=>'Missing complaint_id or invalid party_type.']); exit;
    }

    if ($adminRole === 'lupon') {
        $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
        if (!$ctx) {
            echo json_encode(['success'=>false,'message'=>'Not assigned to this case.']); exit;
        }
    } elseif (!in_array($adminRole, ['captain','secretary'], true)) {
        echo json_encode(['success'=>false,'message'=>'Not allowed.']); exit;
    }

    if (empty($_FILES['audio']) || !is_uploaded_file($_FILES['audio']['tmp_name'])) {
        echo json_encode(['success'=>false,'message'=>'No audio uploaded.']); exit;
    }

    if ($_FILES['audio']['size'] > 25 * 1024 * 1024) {
        echo json_encode(['success'=>false,'message'=>'Audio exceeds 25 MB limit.']); exit;
    }

    $uploadDir = __DIR__ . '/../uploads/party_statements';
    if (!file_exists($uploadDir)) @mkdir($uploadDir, 0777, true);

    $ext = 'webm';
    $mime = $_FILES['audio']['type'] ?? 'audio/webm';
    if (strpos($mime, 'ogg') !== false) $ext = 'ogg';
    elseif (strpos($mime, 'wav') !== false) $ext = 'wav';
    elseif (strpos($mime, 'mp4') !== false) $ext = 'm4a';

    $safeRef = 'comp' . $complaint_id;
    $filename = $safeRef . '-' . $party_type . '-' . time() . '.' . $ext;
    $absPath  = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($_FILES['audio']['tmp_name'], $absPath)) {
        echo json_encode(['success'=>false,'message'=>'Failed to save audio.']); exit;
    }

    $relPath = 'uploads/party_statements/' . $filename;

    if ($hearing_id) {
        $es = mysqli_prepare($conn, "SELECT session_record FROM complaint_hearings WHERE id = ?");
        mysqli_stmt_bind_param($es, "i", $hearing_id);
        mysqli_stmt_execute($es);
        $er = mysqli_fetch_assoc(mysqli_stmt_get_result($es));
        mysqli_stmt_close($es);
        $sr = !empty($er['session_record']) ? json_decode($er['session_record'], true) : [];
        if (!is_array($sr)) $sr = [];

        $key = $party_type . '_statement';
        if (!isset($sr[$key]) || !is_array($sr[$key])) $sr[$key] = [];
        $sr[$key]['audio_path'] = $relPath;
        $sr[$key]['audio_uploaded_at'] = date('Y-m-d H:i:s');
        $sr[$key]['audio_uploaded_by'] = $admin_id;

        $srJson = json_encode($sr, JSON_UNESCAPED_UNICODE);
        $u = mysqli_prepare($conn, "UPDATE complaint_hearings SET session_record = ? WHERE id = ?");
        mysqli_stmt_bind_param($u, "si", $srJson, $hearing_id);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);
    }

    echo json_encode([
        'success'   => true,
        'message'   => 'Audio saved.',
        'audio_url' => $relPath,
    ]);
    exit;
}

/* ============================================================
   POST: delete notifications (bulk)
   ============================================================ */
if ($action === 'delete_notifications') {
    $ids = json_decode($_POST['notification_ids'] ?? '[]', true);
    if (!is_array($ids) || !$ids) {
        echo json_encode(['success'=>false,'message'=>'No notifications selected.']); exit;
    }
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) { echo json_encode(['success'=>false,'message'=>'No valid ids.']); exit; }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $params = array_merge([$admin_id], $ids);
    $types_full = 'i' . $types;

    $s = mysqli_prepare($conn,
        "DELETE FROM notifications
          WHERE user_type = 'admin' AND user_id = ? AND id IN ($in)");
    mysqli_stmt_bind_param($s, $types_full, ...$params);
    $ok = mysqli_stmt_execute($s);
    $deleted = mysqli_stmt_affected_rows($s);
    mysqli_stmt_close($s);

    echo json_encode([
        'success' => (bool)$ok,
        'deleted' => (int)$deleted,
        'message' => $deleted . ' notification(s) deleted.',
    ]);
    exit;
}

/* ============================================================
   GET: All KP forms for a complaint (NO generated-only filter)
   ============================================================ */
if ($action === 'get_case_kp_forms') {
    $complaint_id = (int)($_GET['id'] ?? 0);
    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing id']); exit; }

    $forms = build_kp_forms_list($conn, $complaint_id, false);

    echo json_encode(['success' => true, 'forms' => $forms]);
    exit;
}

/* ============================================================
   CAPTAIN: Schedule mediation + issue KP #8 / #9
   ============================================================ */
if ($action === 'captain_summon_and_schedule' && $adminRole === 'captain') {
    $complaint_id     = (int)($_POST['complaint_id'] ?? 0);
    $hearing_date     = mysqli_real_escape_string($conn, $_POST['hearing_date'] ?? '');
    $hearing_time     = mysqli_real_escape_string($conn, $_POST['hearing_time'] ?? '');
    $hearing_location = mysqli_real_escape_string($conn, $_POST['hearing_location'] ?? 'Barangay Hall');
    $schedule_reason  = mysqli_real_escape_string($conn, $_POST['schedule_reason'] ?? '');
    $notes            = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');

    if (!$complaint_id || !$hearing_date || !$hearing_time) {
        echo json_encode(['success'=>false,'message'=>'Missing fields.']); exit;
    }
    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
    $allowed = ['pending_captain_action', 'for_mediation', 'mediation_scheduled'];
    if (!in_array($meta['status'], $allowed)) {
        echo json_encode(['success'=>false,'message'=>'Not schedulable.']); exit;
    }
    $isFirst = ($meta['status'] === 'pending_captain_action');

    if (!$isFirst) {
        $dl = checkWithinMediationDeadline($meta['mediation_deadline'] ?? null, $hearing_date);
        if ($dl) { echo json_encode(['success'=>false,'message'=>$dl]); exit; }
    }

    $conflict = checkScheduleConflict($conn, $hearing_date, $hearing_time, $admin_id, 60);
    if (!empty($conflict['conflict'])) {
        echo json_encode(['success'=>false,'message'=>$conflict['reason'],'type'=>$conflict['type'] ?? 'conflict']);
        exit;
    }

    $captain_full_name = $adminName;
    $sessQ = mysqli_prepare($conn,
        "SELECT COALESCE(MAX(session_number), 0) + 1 AS next_num
         FROM complaint_hearings WHERE complaint_id = ? AND session_type = 'mediation'");
    mysqli_stmt_bind_param($sessQ, "i", $complaint_id);
    mysqli_stmt_execute($sessQ);
    $sessionNumber = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($sessQ))['next_num'];
    mysqli_stmt_close($sessQ);

    if ($isFirst) {
        $startTs    = strtotime($hearing_date);
        $deadlineTs = strtotime("+".MEDIATION_DAYS." days", $startTs);
        $deadlineSql = date('Y-m-d H:i:s', $deadlineTs);

        $u = mysqli_prepare($conn,
            "UPDATE complaints SET status = 'for_mediation', summons_sent = 1,
                 assigned_to = ?, assigned_role = 'captain',
                 hearing_date = ?, hearing_time = ?, hearing_location = ?,
                 mediation_started_at = NOW(), mediation_deadline = ?,
                 mediation_session_count = 1, mediation_last_result = 'none'
             WHERE id = ?");
        mysqli_stmt_bind_param($u, "issssi",
            $admin_id, $hearing_date, $hearing_time, $hearing_location, $deadlineSql, $complaint_id);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);
    } else {
        $u = mysqli_prepare($conn,
            "UPDATE complaints SET status = 'mediation_scheduled',
                 hearing_date = ?, hearing_time = ?, hearing_location = ?,
                 mediation_session_count = ?
             WHERE id = ?");
        mysqli_stmt_bind_param($u, "sssii",
            $hearing_date, $hearing_time, $hearing_location, $sessionNumber, $complaint_id);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);
    }

    $insH = mysqli_prepare($conn,
        "INSERT INTO complaint_hearings
           (complaint_id, session_type, session_number, hearing_date, hearing_time,
            location, status, conducted_by, created_by, session_reason)
         VALUES (?, 'mediation', ?, ?, ?, ?, 'scheduled', ?, ?, ?)");
    mysqli_stmt_bind_param($insH, "iisssiis",
        $complaint_id, $sessionNumber, $hearing_date, $hearing_time, $hearing_location,
        $admin_id, $admin_id, $schedule_reason);
    mysqli_stmt_execute($insH);
    $new_hearing_id = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($insH);

    $insA = mysqli_prepare($conn, "INSERT INTO complaint_assignments
           (complaint_id, assigned_to, assigned_role, assigned_by, notes)
         VALUES (?, ?, 'captain', ?, ?)");
    mysqli_stmt_bind_param($insA, "iiis", $complaint_id, $admin_id, $admin_id, $notes);
    mysqli_stmt_execute($insA);
    mysqli_stmt_close($insA);

    $niceDate = date('F j, Y', strtotime($hearing_date));
    $niceDeadline = $isFirst ? date('F j, Y', $deadlineTs) : null;

    if ($isFirst) {
        logUpdate($conn, $complaint_id, 'status_change', $meta['status'], 'for_mediation',
            "Mediation Hearing #{$sessionNumber} on $niceDate at $hearing_time, $hearing_location. Deadline: $niceDeadline.",
            $admin_id, 'captain');
    } else {
        logUpdate($conn, $complaint_id, 'hearing_scheduled', $meta['status'], 'mediation_scheduled',
            "Mediation Hearing #{$sessionNumber} on $niceDate at $hearing_time.", $admin_id, 'captain');
    }

    $generatedNotices = [];
    $savedCount = 0; $savedFailed = 0;

    $pq = mysqli_prepare($conn,
        "SELECT id, party_type, full_name, address, contact_number, matched_resident_id
         FROM complaint_parties
         WHERE complaint_id = ?
         ORDER BY FIELD(party_type,'complainant','respondent','witness'), id");
    mysqli_stmt_bind_param($pq, "i", $complaint_id);
    mysqli_stmt_execute($pq);
    $pr = mysqli_stmt_get_result($pq);
    $allParties = [];
    while ($row = mysqli_fetch_assoc($pr)) $allParties[] = $row;
    mysqli_stmt_close($pq);

    foreach ($allParties as $p) {
        if ($p['party_type'] !== 'complainant' && $p['party_type'] !== 'respondent') continue;

        // ── Pass $new_hearing_id so each reschedule creates a unique PDF ──
        $res = save_kp_notice_for_party(
            $conn,
            $complaint_id,
            (int)$p['id'],
            $p['party_type'],
            $captain_full_name ?: 'BARANGAY SECRETARY',
            $new_hearing_id
        );

        if ($res['ok']) {
            $savedCount++;

            if ($new_hearing_id > 0 && !empty($res['pdf_path'])) {
                if ($p['party_type'] === 'complainant') {
                    $uh = mysqli_prepare($conn,
                        "UPDATE complaint_hearings
                            SET kp8_complainant_pdf_path = ?,
                                kp8_complainant_issued_at = NOW()
                          WHERE id = ?");
                    mysqli_stmt_bind_param($uh, "si", $res['pdf_path'], $new_hearing_id);
                    mysqli_stmt_execute($uh);
                    mysqli_stmt_close($uh);
                } else {
                    $uh = mysqli_prepare($conn,
                        "UPDATE complaint_hearings
                            SET kp9_respondent_pdf_path = ?,
                                kp9_respondent_issued_at = NOW()
                          WHERE id = ?");
                    mysqli_stmt_bind_param($uh, "si", $res['pdf_path'], $new_hearing_id);
                    mysqli_stmt_execute($uh);
                    mysqli_stmt_close($uh);
                }
            }

            if ($p['party_type'] === 'complainant') {
                $us = mysqli_prepare($conn,
                    "UPDATE complaint_parties
                     SET notice_of_hearing_sent_at = NOW(), notified_via = COALESCE(notified_via,'push')
                     WHERE id = ?");
            } else {
                $us = mysqli_prepare($conn,
                    "UPDATE complaint_parties
                     SET summons_sent_at = NOW(), notified_via = COALESCE(notified_via,'push')
                     WHERE id = ?");
            }
            mysqli_stmt_bind_param($us, "i", $p['id']);
            mysqli_stmt_execute($us);
            mysqli_stmt_close($us);

            $formLabel = $p['party_type'] === 'complainant'
                ? 'KP Form #8 (Notice of Hearing)'
                : 'KP Form #9 (Summons)';
            logUpdate($conn, $complaint_id, 'note', null, null,
                "$formLabel saved for {$p['full_name']} → {$res['pdf_path']}",
                $admin_id, 'captain');

            $notifiedResidentId = null;
            $matched = tryMatchResident($conn, $p);

            if ($matched) {
                $notifiedResidentId = $matched;
                $us = mysqli_prepare($conn,
                    "UPDATE complaint_parties SET matched_resident_id = ? WHERE id = ?");
                mysqli_stmt_bind_param($us, "ii", $matched, $p['id']);
                mysqli_stmt_execute($us);
                mysqli_stmt_close($us);

                if ($p['party_type'] === 'complainant') {
                    $title = 'Punong Barangay sent you a Notice';
                    $msg   = "The Punong Barangay has issued a Notice of Hearing (KP Form #8) for complaint #{$meta['reference_number']}. "
                           . "Hearing: $niceDate at $hearing_time, $hearing_location. Please appear in person.";
                } else {
                    $title = 'Punong Barangay sent you a Summons';
                    $msg   = "The Punong Barangay has issued a Summons (KP Form #9) for complaint #{$meta['reference_number']}. "
                           . "Hearing: $niceDate at $hearing_time, $hearing_location. You are required to appear in person.";
                }

                // ✅ NEW: pass the PDF path so the resident can open the KP form directly
                sendNotification($conn, 'resident', $matched, $complaint_id, $title, $msg, 'warning', $res['pdf_path']);
                if (function_exists('pushToUser')) {
                    pushToUser($conn, $matched, $title, $msg,
                        '/BRITE/resident/dashboards/resident_dashboard.php',
                        $res['pdf_path']);  // ✅ NEW
                }

                if ($new_hearing_id > 0) {
                    if ($p['party_type'] === 'complainant') {
                        mysqli_query($conn, "UPDATE complaint_hearings SET kp8_complainant_notified = 1 WHERE id = " . (int)$new_hearing_id);
                    } else {
                        mysqli_query($conn, "UPDATE complaint_hearings SET kp9_respondent_notified = 1 WHERE id = " . (int)$new_hearing_id);
                    }
                }
            } else {
                $us = mysqli_prepare($conn,
                    "UPDATE complaint_parties SET notified_via='print' WHERE id = ?");
                mysqli_stmt_bind_param($us, "i", $p['id']);
                mysqli_stmt_execute($us);
                mysqli_stmt_close($us);
            }

            $generatedNotices[] = [
                'party_id'    => (int)$p['id'],
                'party_type'  => $p['party_type'],
                'role'        => $p['party_type'] === 'complainant' ? 'Complainant' : 'Respondent',
                'name'        => $p['full_name'],
                'form'        => $p['party_type'] === 'complainant' ? 'KP_FORM_8' : 'KP_FORM_9',
                'form_label'  => $p['party_type'] === 'complainant'
                                   ? 'KP Form #8 — Notice of Hearing'
                                   : 'KP Form #9 — Summons',
                'pdf_url'     => $res['pdf_path'],
                'notified'    => $notifiedResidentId ? true : false,
                'notified_id' => $notifiedResidentId,
            ];
        } else {
            $savedFailed++;
        }
    }

    $creator = getComplaintCreator($conn, $complaint_id);
    if ($creator) {
        $cTitle = $isFirst ? 'Notice of Hearing Issued' : "Mediation Hearing #{$sessionNumber} Scheduled";
        $cMsg   = $isFirst
            ? "Complaint #{$meta['reference_number']} — mediation before Captain {$captain_full_name} on $niceDate at $hearing_time."
            : "Mediation Hearing #{$sessionNumber} for complaint #{$meta['reference_number']} is on $niceDate at $hearing_time.";
        sendNotification($conn, 'resident', $creator, $complaint_id, $cTitle, $cMsg, 'hearing');
    }

    notifyAdminsByRole($conn, ['secretary'], $complaint_id,
        'Captain Scheduled Mediation',
        "Complaint #{$meta['reference_number']} — Mediation Hearing #{$sessionNumber} on $niceDate at $hearing_time.",
        'info');

    echo json_encode([
        'success'         => true,
        'message'         => 'Summons issued and notices saved.',
        'session_number'  => $sessionNumber,
        'hearing_date'    => $niceDate,
        'hearing_time'    => $hearing_time,
        'hearing_location'=> $hearing_location,
        'is_first'        => $isFirst,
        'captain_name'    => $captain_full_name,
        'hearing_id'      => $new_hearing_id,
        'notices_ok'      => $savedCount,
        'notices_failed'  => $savedFailed,
        'notices'         => $generatedNotices,
    ]);
    exit;
};



/* ============================================================
   CAPTAIN: Record mediation outcome — Settlement OR Arbitration
   POST: complaint_id, outcome_kind, summary, reason,
         session_record, attendance, complainant_statement, respondent_statement
   ============================================================ */
if ($action === 'captain_record_mediation_outcome' && $adminRole === 'captain') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $outcome_kind = trim($_POST['outcome_kind'] ?? '');
    $summary      = mysqli_real_escape_string($conn, $_POST['summary'] ?? '');
    $terms        = mysqli_real_escape_string($conn, $_POST['reason'] ?? '');
    $session_record_json  = trim($_POST['session_record'] ?? '');
    $attendance_json      = trim($_POST['attendance'] ?? '');
    $complainantStatement = trim($_POST['complainant_statement'] ?? '');
    $respondentStatement  = trim($_POST['respondent_statement'] ?? '');

    if (!$complaint_id || !in_array($outcome_kind, ['settlement','arbitration'], true)) {
        echo json_encode(['success'=>false,'message'=>'Missing complaint_id or invalid outcome_kind.']);
        exit;
    }
    if (!$summary) { echo json_encode(['success'=>false,'message'=>'Hearing summary is required.']); exit; }

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
    if (!in_array($meta['status'], ['for_mediation','mediation_scheduled'], true)) {
        echo json_encode(['success'=>false,'message'=>'Not in mediation.']); exit;
    }

    // Save attendance snapshot
    $latestHearingId = getLatestMediationHearingId($conn, $complaint_id);
    if ($attendance_json !== '' && $latestHearingId) {
        $attRows = json_decode($attendance_json, true);
        if (is_array($attRows)) {
            foreach ($attRows as $r) {
                $pid = (int)($r['party_id'] ?? 0);
                $st  = $r['status'] ?? '';
                if (!$pid || !in_array($st, ['attend','no_show'], true)) continue;
                $upA = mysqli_prepare($conn,
                    "INSERT INTO complaint_attendance
                        (complaint_id, hearing_id, party_id, status, recorded_by, recorded_at)
                     VALUES (?, ?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        recorded_by = VALUES(recorded_by),
                        recorded_at = NOW()");
                mysqli_stmt_bind_param($upA, "iiisi", $complaint_id, $latestHearingId, $pid, $st, $admin_id);
                mysqli_stmt_execute($upA);
                mysqli_stmt_close($upA);
            }
        }
    }

    // Close current hearing
    if ($latestHearingId) {
        $s = mysqli_prepare($conn, "UPDATE complaint_hearings SET status='completed',
            outcome='settled', summary=?, completed_at=NOW(),
            explain_window_resolved_at = COALESCE(explain_window_resolved_at, NOW())
            WHERE id = ?");
        mysqli_stmt_bind_param($s, "si", $summary, $latestHearingId);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);

        $es = mysqli_prepare($conn, "SELECT session_record FROM complaint_hearings WHERE id = ?");
        mysqli_stmt_bind_param($es, "i", $latestHearingId);
        mysqli_stmt_execute($es);
        $er = mysqli_fetch_assoc(mysqli_stmt_get_result($es));
        mysqli_stmt_close($es);
        $sr = !empty($er['session_record']) ? json_decode($er['session_record'], true) : [];
        if (!is_array($sr)) $sr = [];

        if ($session_record_json !== '') {
            $decoded = json_decode($session_record_json, true);
            if (is_array($decoded)) $sr = array_merge($sr, $decoded);
        }
        if ($complainantStatement !== '') {
            if (!isset($sr['complainant_statement']) || !is_array($sr['complainant_statement'])) $sr['complainant_statement'] = [];
            $sr['complainant_statement']['text'] = $complainantStatement;
        }
        if ($respondentStatement !== '') {
            if (!isset($sr['respondent_statement']) || !is_array($sr['respondent_statement'])) $sr['respondent_statement'] = [];
            $sr['respondent_statement']['text'] = $respondentStatement;
        }

        $srJson = json_encode($sr, JSON_UNESCAPED_UNICODE);
        $usr = mysqli_prepare($conn, "UPDATE complaint_hearings SET session_record = ? WHERE id = ?");
        mysqli_stmt_bind_param($usr, "si", $srJson, $latestHearingId);
        mysqli_stmt_execute($usr);
        mysqli_stmt_close($usr);
    }

    // ═══ BRANCH A — Amicable Settlement (KP Form #16) ═══
    if ($outcome_kind === 'settlement') {
        if ($terms === '') { echo json_encode(['success'=>false,'message'=>'Settlement terms are required.']); exit; }

        $s = mysqli_prepare($conn, "UPDATE complaints SET
            status='settled_pending',
            mediation_outcome='settlement',
            resolution_notes=?,
            mediation_last_result='settled'
            WHERE id=?");
        mysqli_stmt_bind_param($s, "si", $summary, $complaint_id);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);

        logUpdate($conn, $complaint_id, 'resolution', $meta['status'], 'settled_pending',
            "Amicable Settlement reached at mediation. $summary", $admin_id, 'captain');

        $kp16Result = save_kp_form_16_on_settlement($conn, $complaint_id, 'mediation', $terms);
        if (!empty($kp16Result['ok'])) {
            logUpdate($conn, $complaint_id, 'note', null, null,
                "KP Form #16 (Amicable Settlement) generated → {$kp16Result['pdf_path']}",
                $admin_id, 'captain');
        }

        $repudiationDeadline = enterSettledPendingState($conn, $complaint_id);

        $creator = getComplaintCreator($conn, $complaint_id);
        if ($creator) {
            sendNotification($conn, 'resident', $creator, $complaint_id,
                'Amicable Settlement Signed — KP Form #16',
                "Your complaint #{$meta['reference_number']} has been settled amicably (KP Form #16). " .
                "You have 10 days to repudiate on grounds of fraud, violence, or intimidation. " .
                "Window ends " . date('F j, Y', strtotime($repudiationDeadline)) . ".",
                'success',
                $kp16Result['pdf_path'] ?? null);
        }

        echo json_encode([
            'success'  => true,
            'outcome'  => 'settlement',
            'status'   => 'settled_pending',
            'kp16'     => $kp16Result,
            'repudiation_deadline' => $repudiationDeadline,
            'message'  => 'KP Form #16 (Amicable Settlement) recorded. 10-day repudiation window started.',
        ]);
        exit;
    }

    // ═══ BRANCH B — Arbitration (KP Form #14 → KP Form #15) ═══
// ═══ BRANCH B — Arbitration (KP Form #14 → KP Form #15) ═══

// Get the signing date from POST (YYYY-MM-DD) or fallback to today
$signed_date = trim($_POST['signed_date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $signed_date)) {
    $signed_date = date('Y-m-d');
}

$kp14Result = generate_kp_form_on_demand($conn, $complaint_id, 'kp14', [
    'issued_by'   => $adminName ?: 'PUNONG BARANGAY',
    'signed_date' => $signed_date,   // ← pass it through
]);
    if (empty($kp14Result['ok'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to generate KP Form #14: ' . ($kp14Result['error'] ?? 'unknown error'),
        ]);
        exit;
    }

    $state = enterArbitrationPendingState($conn, $complaint_id, $kp14Result['pdf_path']);

    logUpdate($conn, $complaint_id, 'resolution', $meta['status'], 'arbitration_pending',
        "KP Form #14 (Agreement for Arbitration) signed. " .
        "5-day repudiation window ends " . date('F j, Y', strtotime($state['repudiation_deadline'])) . ". " .
        "KP Form #15 (Arbitration Award) due by " . date('F j, Y', strtotime($state['award_deadline'])) . ". " .
        $summary,
        $admin_id, 'captain');

    $pq = mysqli_prepare($conn,
        "SELECT id, party_type, full_name, matched_resident_id, contact_number
           FROM complaint_parties
          WHERE complaint_id = ? AND party_type IN ('complainant','respondent')");
    mysqli_stmt_bind_param($pq, "i", $complaint_id);
    mysqli_stmt_execute($pq);
    $pqr = mysqli_stmt_get_result($pq);
    while ($prow = mysqli_fetch_assoc($pqr)) {
        $matched = tryMatchResident($conn, $prow);
        if (!$matched) continue;
        sendNotification($conn, 'resident', $matched, $complaint_id,
            'Agreement for Arbitration Signed — KP Form #14',
            "You have agreed to submit complaint #{$meta['reference_number']} to arbitration. " .
            "You have 5 days (until " . date('F j, Y', strtotime($state['repudiation_deadline'])) . ") " .
            "to repudiate KP Form #14 on grounds of fraud, violence, or intimidation. " .
            "After that, the Punong Barangay must render KP Form #15 (Arbitration Award) within 15 days.",
            'warning',
            $kp14Result['pdf_path']);
        if (function_exists('pushToUser')) {
            pushToUser($conn, $matched,
                'KP Form #14 — Agreement for Arbitration',
                "Complaint #{$meta['reference_number']}.",
                '/BRITE/resident/dashboards/resident_dashboard.php',
                $kp14Result['pdf_path']);
        }
    }
    mysqli_stmt_close($pq);

   // ── Notify ONLY the Barangay Secretary (not Lupon, not other captains) ──
$secRow = null;
$secQ = mysqli_prepare($conn,
    "SELECT id, full_name FROM admin
      WHERE admin_role = 'secretary' AND is_active = 1
      ORDER BY id ASC LIMIT 1");
mysqli_stmt_execute($secQ);
$secRow = mysqli_fetch_assoc(mysqli_stmt_get_result($secQ));
mysqli_stmt_close($secQ);

if ($secRow) {
    $secId = (int)$secRow['id'];

    sendNotification(
        $conn, 'admin', $secId, $complaint_id,
        '⚠ PRINT REQUIRED — KP Form #14 (Agreement for Arbitration)',
        "Complaint #{$meta['reference_number']} — parties agreed to arbitration. " .
        "Print and hand-deliver KP Form #14 to both parties immediately. " .
        "5-day repudiation window ends " . date('F j, Y', strtotime($state['repudiation_deadline'])) . ".",
        'warning',
        $kp14Result['pdf_path']
    );
    if (function_exists('pushToUser')) {
        pushToUser(
            $conn, $secId,
            'KP Form #14 — Print Required',
            "Complaint #{$meta['reference_number']} — hand-deliver KP Form #14 to both parties.",
            '/BRITE/admin/dashboards/secretary_dashboard.php',
            $kp14Result['pdf_path']
        );
    }
}

    echo json_encode([
        'success'              => true,
        'outcome'              => 'arbitration',
        'status'               => 'arbitration_pending',
        'kp14'                 => $kp14Result,
        'repudiation_deadline' => $state['repudiation_deadline'],
        'award_deadline'       => $state['award_deadline'],
        'message'              => 'KP Form #14 (Agreement for Arbitration) recorded.',
    ]);
    exit;
}

/* ============================================================
   CAPTAIN: Issue KP Form #15 — Arbitration Award
   ============================================================ */
if ($action === 'captain_issue_arbitration_award' && $adminRole === 'captain') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $award_text   = trim($_POST['award_text'] ?? '');

    if (!$complaint_id || !$award_text) {
        echo json_encode(['success'=>false,'message'=>'Complaint ID and award text are required.']); exit;
    }

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }

    $arb = getArbitrationState($conn, $complaint_id);
    if (!$arb) { echo json_encode(['success'=>false,'message'=>'No arbitration state.']); exit; }
    if ($arb['arbitration_repudiated'] == 1) {
        echo json_encode(['success'=>false,'message'=>'KP Form #14 was repudiated.']); exit;
    }
    if (!in_array($meta['status'], ['arbitration_pending','arbitration_agreement_active'], true)) {
        echo json_encode(['success'=>false,'message'=>'Case is not in arbitration.']); exit;
    }
    if ($meta['status'] === 'arbitration_pending') {
        echo json_encode(['success'=>false,'message'=>'5-day repudiation window has not yet expired.']); exit;
    }

    $kp15Result = generate_kp_form_on_demand($conn, $complaint_id, 'kp15', [
        'issued_by'  => $adminName ?: 'PUNONG BARANGAY',
        'award_text' => $award_text,
    ]);
    if (empty($kp15Result['ok'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to generate KP Form #15: ' . ($kp15Result['error'] ?? 'unknown error'),
        ]);
        exit;
    }

    mysqli_begin_transaction($conn);
    try {
        $u = mysqli_prepare($conn,
            "UPDATE complaints
                SET status = 'arbitration_award_issued',
                    resolved_at = NOW()
              WHERE id = ?");
        mysqli_stmt_bind_param($u, "i", $complaint_id);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);

        logUpdate($conn, $complaint_id, 'resolution',
            $meta['status'], 'arbitration_award_issued',
            "KP Form #15 (Arbitration Award) rendered. " . substr($award_text, 0, 200),
            $admin_id, 'captain');

        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success'=>false,'message'=>'DB error saving award.']); exit;
    }

    $pq = mysqli_prepare($conn,
        "SELECT id, party_type, full_name, matched_resident_id, contact_number
           FROM complaint_parties
          WHERE complaint_id = ? AND party_type IN ('complainant','respondent')");
    mysqli_stmt_bind_param($pq, "i", $complaint_id);
    mysqli_stmt_execute($pq);
    $pqr = mysqli_stmt_get_result($pq);
    while ($prow = mysqli_fetch_assoc($pqr)) {
        $matched = tryMatchResident($conn, $prow);
        if (!$matched) continue;
        sendNotification($conn, 'resident', $matched, $complaint_id,
            'Arbitration Award Issued — KP Form #15',
            "The Arbitration Award for complaint #{$meta['reference_number']} has been rendered (KP Form #15). " .
            "The award is final and binding.",
            'success',
            $kp15Result['pdf_path']);
        if (function_exists('pushToUser')) {
            pushToUser($conn, $matched,
                'KP Form #15 — Arbitration Award',
                "Complaint #{$meta['reference_number']}.",
                '/BRITE/resident/dashboards/resident_dashboard.php',
                $kp15Result['pdf_path']);
        }
    }
    mysqli_stmt_close($pq);

    notifyPangkatSecretaryPrintRequired(
        $conn,
        $complaint_id,
        '⚠ PRINT REQUIRED — KP Form #15 (Arbitration Award)',
        "Complaint #{$meta['reference_number']} — KP Form #15 rendered. " .
        "Print and hand-deliver to both parties.",
        $kp15Result['pdf_path']
    );

    echo json_encode([
        'success' => true,
        'status'  => 'arbitration_award_issued',
        'kp15'    => $kp15Result,
        'message' => 'KP Form #15 (Arbitration Award) issued.',
    ]);
    exit;
}



/* ============================================================
   CAPTAIN: Issue Certificate to File Action (KP #20)
   ------------------------------------------------------------
   Called after a settlement (KP #16) was repudiated, OR after
   mediation/conciliation ended without settlement. Moves the
   case to 'certificate_issued' and generates the PDF for
   PRINTING and hand-delivery to the complainant.
   ============================================================ */
if ($action === 'captain_issue_certificate_to_file' && $adminRole === 'captain') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $issued_to    = trim($_POST['issued_to'] ?? '');
    $reason       = trim($_POST['reason'] ?? '');
    $issued_date  = trim($_POST['issued_date'] ?? date('Y-m-d'));

    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint_id.']); exit; }
    if ($issued_to === '') { echo json_encode(['success'=>false,'message'=>'Issued To is required.']); exit; }
    if ($reason === '') { echo json_encode(['success'=>false,'message'=>'Reason is required.']); exit; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issued_date)) $issued_date = date('Y-m-d');

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Complaint not found.']); exit; }

    // ── Allowed statuses to issue CFA ──
    $allowedStatuses = [
        'amicable_repudiated',        // KP #16 was repudiated → straight to CFA
        'failed_conciliation_final',  // legacy: conciliation failed for good
        'failed_mediation',           // mediation ended without settlement
        'unresolved',                 // conciliation ended without settlement
    ];

    $isAllowed = in_array($meta['status'], $allowedStatuses, true);

    // Special case: arbitration was repudiated AND the captain decided not to re-mediate
    if (!$isAllowed) {
        $arb = getArbitrationState($conn, $complaint_id);
        $isRepudiatedArb = $arb && (int)$arb['arbitration_repudiated'] === 1;
        if ($isRepudiatedArb && $meta['status'] === 'arbitration_repudiated') {
            $isAllowed = true;
        }
    }

    if (!$isAllowed) {
        echo json_encode([
            'success' => false,
            'message' => 'This case is not ready for a Certificate to File Action. Current status: ' . $meta['status']
        ]);
        exit;
    }

    // ── Determine the repudiation source for the reason text ──
    $repudiationBy   = '';
    $repudiationNote = '';
    if ((int)($meta['amicable_repudiated'] ?? 0) === 1) {
        $repudiationBy   = strtoupper($meta['amicable_repudiated_by_party'] ?? '');
        $repudiationNote = ' Source: KP #16 (Amicable Settlement) repudiated by ' . ($repudiationBy ?: 'party') . '.';
    } elseif ((int)($meta['arbitration_repudiated'] ?? 0) === 1) {
        $repudiationBy   = strtoupper($meta['arbitration_repudiated_by_party'] ?? '');
        $repudiationNote = ' Source: KP #14 (Agreement for Arbitration) repudiated by ' . ($repudiationBy ?: 'party') . '.';
    }

    mysqli_begin_transaction($conn);
    try {
        $certNo = 'CFA-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));

        $u = mysqli_prepare($conn,
            "UPDATE complaints
                SET status = 'unresolved',
                    certificate_no = ?,
                    certificate_issued_at = NOW(),
                    certificate_reason = ?,
                    cfa_issued_at = NOW(),
                    cfa_issued_by = ?
              WHERE id = ?");
        mysqli_stmt_bind_param($u, "ssii", $certNo, $reason, $admin_id, $complaint_id);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);

        logUpdate($conn, $complaint_id, 'resolution',
            $meta['status'], 'unresolved',
            "KP Form #20 (Certificate to File Action) issued by Captain. " .
            "Cert No: {$certNo}. Issued to: {$issued_to}.{$repudiationNote} " .
            "Reason: {$reason}. Status set to UNRESOLVED — awaiting physical delivery of the CFA to the complainant before final closure.",
            $admin_id, 'captain');

        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log('captain_issue_certificate_to_file failed: ' . $e->getMessage());
        echo json_encode(['success'=>false,'message'=>'Could not issue the certificate.']); exit;
    }

    // ── Generate KP Form #20 ──
    $genResult = generate_kp_form_on_demand($conn, $complaint_id, 'kp20', [
        'issued_by'     => $adminName ?: 'PUNONG BARANGAY',
        'reason'        => $reason,
        'repudiated_by' => $repudiationBy ?: $issued_to,
    ]);

    $pdfPath = $genResult['pdf_path'] ?? null;

    if (empty($genResult['ok'])) {
        logUpdate($conn, $complaint_id, 'note', null, null,
            "⚠ Failed to generate KP Form #20 PDF: " . ($genResult['error'] ?? 'unknown'),
            $admin_id, 'captain');
    }

    // ── Notify the complainant with the PDF ──
    $creator = getComplaintCreator($conn, $complaint_id);
    if ($creator) {
        $title = 'Certificate to File Action Issued';
        $msg   = "The Certificate to File Action (KP Form #20) for complaint #{$meta['reference_number']} " .
                 "has been issued. Please claim your printed copy at the Barangay Hall. " .
                 "You may now file the case in court.";
        sendNotification($conn, 'resident', $creator, $complaint_id, $title, $msg, 'success', $pdfPath);
        if (function_exists('pushToUser')) {
            pushToUser($conn, $creator, $title, $msg,
                '/BRITE/resident/dashboards/resident_dashboard.php', $pdfPath);
        }
    }

    // ── Notify the respondent (they get a copy too) ──
    $pq = mysqli_prepare($conn,
        "SELECT id, party_type, full_name, matched_resident_id, contact_number
           FROM complaint_parties
          WHERE complaint_id = ? AND party_type = 'respondent'");
    mysqli_stmt_bind_param($pq, "i", $complaint_id);
    mysqli_stmt_execute($pq);
    $pqr = mysqli_stmt_get_result($pq);
    while ($prow = mysqli_fetch_assoc($pqr)) {
        $matched = tryMatchResident($conn, $prow);
        if (!$matched) continue;
        $title = 'Certificate to File Action Issued';
        $msg   = "A Certificate to File Action (KP Form #20) has been issued for complaint #{$meta['reference_number']}. " .
                 "Please claim your copy at the Barangay Hall.";
        sendNotification($conn, 'resident', $matched, $complaint_id, $title, $msg, 'info', $pdfPath);
        if (function_exists('pushToUser')) {
            pushToUser($conn, $matched, $title, $msg,
                '/BRITE/resident/dashboards/resident_dashboard.php', $pdfPath);
        }
    }
    mysqli_stmt_close($pq);

    // ── Secretary: PRINT REQUIRED ──
    notifyAdminsByRole($conn, ['secretary'], $complaint_id,
        '⚠ PRINT REQUIRED — KP Form #20 (Certificate to File Action)',
        "Complaint #{$meta['reference_number']} — CFA issued by Captain (Cert No: {$certNo}). " .
        "Print the CFA and hand-deliver it to the complainant: {$issued_to}. " .
        "The respondent receives a copy for their records.",
        'warning',
        null,
        $pdfPath);

    // ── Pangkat Secretary (if one exists) ──
    $luponSecId = getPangkatSecretaryId($conn, $complaint_id);
    if ($luponSecId > 0 && $luponSecId !== $admin_id) {
        sendNotification($conn, 'admin', $luponSecId, $complaint_id,
            '⚠ PRINT REQUIRED — KP Form #20 (CFA)',
            "Complaint #{$meta['reference_number']} — print and hand-deliver the CFA to the complainant.",
            'warning',
            $pdfPath);
        if (function_exists('pushToUser')) {
            pushToUser($conn, $luponSecId,
                'CFA — Print Required',
                "Complaint #{$meta['reference_number']}.",
                '/BRITE/admin/dashboards/lupon_dashboard.php', $pdfPath);
        }
    }

    echo json_encode([
        'success'        => true,
        'message'        => 'Certificate to File Action issued. Case is now UNRESOLVED — print and hand-deliver to the complainant.',
        'certificate_no' => $certNo,
        'pdf_url'        => $pdfPath,
        'status'         => 'unresolved',
        'awaits_delivery'=> true,
    ]);
    exit;
}
/* ============================================================
   CAPTAIN: Record mediation outcome
   ============================================================ */
if ($action === 'captain_mediate' && $adminRole === 'captain') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $settled      = ($_POST['settled'] ?? '0') === '1';
    $summary      = mysqli_real_escape_string($conn, $_POST['summary'] ?? '');
    $reason       = mysqli_real_escape_string($conn, $_POST['reason'] ?? '');
    $next_action  = mysqli_real_escape_string($conn, $_POST['next_action'] ?? 'end');
    $next_date    = mysqli_real_escape_string($conn, $_POST['next_date'] ?? '');
    $next_time    = mysqli_real_escape_string($conn, $_POST['next_time'] ?? '');
    $session_record_json = trim($_POST['session_record'] ?? '');
    $attendance_json     = trim($_POST['attendance'] ?? '');
    $complainantStatement = trim($_POST['complainant_statement'] ?? '');
    $respondentStatement  = trim($_POST['respondent_statement'] ?? '');

    if (!$summary) { echo json_encode(['success'=>false,'message'=>'Summary required.']); exit; }
    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
    if (!in_array($meta['status'], ['for_mediation','mediation_scheduled'])) {
        echo json_encode(['success'=>false,'message'=>'Not in mediation.']); exit;
    }
    // Only block if the hearing we're actually trying to save hasn't happened yet.
    // Do NOT block on the *next scheduled* hearing — that's the one we're about to create.
    $latestHearingId = getLatestMediationHearingId($conn, $complaint_id);
    if ($latestHearingId) {
        $hCheck = mysqli_prepare($conn,
            "SELECT hearing_date, status FROM complaint_hearings WHERE id = ?");
        mysqli_stmt_bind_param($hCheck, "i", $latestHearingId);
        mysqli_stmt_execute($hCheck);
        $hRow = mysqli_fetch_assoc(mysqli_stmt_get_result($hCheck));
        mysqli_stmt_close($hCheck);

        if ($hRow && $hRow['status'] === 'scheduled') {
            $today = date('Y-m-d');
            $hDate = substr($hRow['hearing_date'], 0, 10);
            if ($hDate > $today) {
                echo json_encode(['success'=>false,'message'=>'Cannot record outcome yet.']); exit;
            }
        }
    }

    $daysLeft = computeMediationDaysRemaining($meta['mediation_deadline'] ?? null);

    if (!$settled && !($next_action === 'schedule' && $next_date && $next_time)) {
        if (in_array('complainant', latestSessionAbsentRoles($conn, $complaint_id), true)) {
            echo json_encode(['success'=>false,'message'=>
                'The complainant did not appear this session, so the case cannot go to the Pangkat.']);
            exit;
        }
    }

    $latestHearingId = getLatestMediationHearingId($conn, $complaint_id);

    if ($attendance_json !== '' && $latestHearingId) {
        $attRows = json_decode($attendance_json, true);
        if (is_array($attRows)) {
            $allowedAtt = ['attend','no_show'];
            foreach ($attRows as $r) {
                $pid = (int)($r['party_id'] ?? 0);
                $st  = $r['status'] ?? '';
                if (!$pid || !in_array($st, $allowedAtt)) continue;

                $upA = mysqli_prepare($conn,
                    "INSERT INTO complaint_attendance
                        (complaint_id, hearing_id, party_id, status, recorded_by, recorded_at)
                     VALUES (?, ?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        recorded_by = VALUES(recorded_by),
                        recorded_at = NOW()");
                mysqli_stmt_bind_param($upA, "iiisi", $complaint_id, $latestHearingId, $pid, $st, $admin_id);
                mysqli_stmt_execute($upA);
                mysqli_stmt_close($upA);
            }
        }
    }

    $s = mysqli_prepare($conn, "UPDATE complaint_hearings SET status='completed',
        outcome=?, summary=?, completed_at=NOW(),
        explain_window_resolved_at = COALESCE(explain_window_resolved_at, NOW())
        WHERE complaint_id=? AND status='scheduled'
        ORDER BY hearing_date DESC, hearing_time DESC LIMIT 1");
    $outcomeLabel = $settled ? 'settled' : 'no_settlement';
    mysqli_stmt_bind_param($s, "ssi", $outcomeLabel, $summary, $complaint_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);

    if ($latestHearingId) {
        $es = mysqli_prepare($conn, "SELECT session_record FROM complaint_hearings WHERE id = ?");
        mysqli_stmt_bind_param($es, "i", $latestHearingId);
        mysqli_stmt_execute($es);
        $er = mysqli_fetch_assoc(mysqli_stmt_get_result($es));
        mysqli_stmt_close($es);
        $existing = !empty($er['session_record']) ? json_decode($er['session_record'], true) : [];
        if (!is_array($existing)) $existing = [];

        $sr = $existing;
        if ($session_record_json !== '') {
            $decoded = json_decode($session_record_json, true);
            if (is_array($decoded)) $sr = array_merge($sr, $decoded);
        }
        if ($complainantStatement !== '') {
            if (!isset($sr['complainant_statement']) || !is_array($sr['complainant_statement'])) $sr['complainant_statement'] = [];
            $sr['complainant_statement']['text'] = $complainantStatement;
        }
        if ($respondentStatement !== '') {
            if (!isset($sr['respondent_statement']) || !is_array($sr['respondent_statement'])) $sr['respondent_statement'] = [];
            $sr['respondent_statement']['text'] = $respondentStatement;
        }

        $srJson = json_encode($sr, JSON_UNESCAPED_UNICODE);
        $usr = mysqli_prepare($conn,
            "UPDATE complaint_hearings SET session_record = ? WHERE id = ?");
        mysqli_stmt_bind_param($usr, "si", $srJson, $latestHearingId);
        mysqli_stmt_execute($usr);
        mysqli_stmt_close($usr);
    }

    if ($settled) {
        $s = mysqli_prepare($conn, "UPDATE complaints SET status='settled_pending',
            resolution_notes=?, mediation_last_result='settled' WHERE id=?");
        mysqli_stmt_bind_param($s, "si", $summary, $complaint_id);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);

        logUpdate($conn, $complaint_id, 'resolution', $meta['status'], 'settled_pending',
            "Settlement reached at mediation. $summary", $admin_id, 'captain');

        $kp16Result = save_kp_form_16_on_settlement($conn, $complaint_id, 'mediation', $summary);
        if (!empty($kp16Result['ok'])) {
            logUpdate($conn, $complaint_id, 'note', null, null,
                "KP Form #16 (Amicable Settlement — mediation) stored as soft copy (no digital signature) → {$kp16Result['pdf_path']}",
                $admin_id, 'captain');
        }

        $repudiationDeadline = enterSettledPendingState($conn, $complaint_id);

        $creator = getComplaintCreator($conn, $complaint_id);
        if ($creator) {
            sendNotification($conn, 'resident', $creator, $complaint_id,
                'Amicable Settlement Signed',
                "Your complaint #{$meta['reference_number']} has been settled. " .
                "You have 10 days to repudiate the settlement on grounds of fraud, violence, or intimidation. " .
                "Window ends " . date('F j, Y', strtotime($repudiationDeadline)) . ".",
                'success',
                $kp16Result['pdf_path'] ?? null);   // ✅ NEW
        }
        echo json_encode([
            'success'=>true, 'final'=>true,
            'message'=>'Settlement recorded. 10-day repudiation window started.',
            'kp16'=>$kp16Result,
            'repudiation_deadline'=>$repudiationDeadline,
        ]);
        exit;
    }

    if ($daysLeft !== null && $daysLeft <= 0) {
        $new = 'failed_mediation';
        $s = mysqli_prepare($conn, "UPDATE complaints SET status=?,
            mediation_last_result='no_settlement', resolution_notes=? WHERE id=?");
        mysqli_stmt_bind_param($s, "ssi", $new, $summary, $complaint_id);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);

        logUpdate($conn, $complaint_id, 'status_change', $meta['status'], $new,
            "15-day period expired. Pangkat constitution to be scheduled. $summary",
            $admin_id, 'captain');

        notifyAdminsByRole($conn, ['captain'], $complaint_id,
            'Mediation Period Expired — Constitute Pangkat',
            "Complaint #{$meta['reference_number']} — schedule the Pangkat constitution meeting.",
            'warning');

        echo json_encode(['success'=>true,'final'=>true,'message'=>'Forwarded to Pangkat constitution stage.']);
        exit;
    }

    if ($next_action === 'schedule' && $next_date && $next_time) {
        $dl = checkWithinMediationDeadline($meta['mediation_deadline'] ?? null, $next_date);
        if ($dl) { echo json_encode(['success'=>false,'message'=>$dl]); exit; }
        $conflict = checkScheduleConflict($conn, $next_date, $next_time, $admin_id, 60);
        if (!empty($conflict['conflict'])) {
            echo json_encode(['success'=>false,'message'=>$conflict['reason']]); exit;
        }
        $nextSession = ($meta['mediation_session_count'] ?? 1) + 1;
        $s = mysqli_prepare($conn, "INSERT INTO complaint_hearings
            (complaint_id, session_type, session_number, hearing_date, hearing_time,
             location, status, conducted_by, created_by, session_reason)
            VALUES (?, 'mediation', ?, ?, ?, 'Barangay Hall', 'scheduled', ?, ?, ?)");
        $reasonText = "Mediation Hearing #$nextSession — previous session did not settle.";
        mysqli_stmt_bind_param($s, "iissiis",
            $complaint_id, $nextSession, $next_date, $next_time, $admin_id, $admin_id, $reasonText);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);

        $s = mysqli_prepare($conn, "UPDATE complaints SET
            status = 'mediation_scheduled',
            mediation_session_count = ?,
            mediation_last_result = 'no_settlement',
            hearing_date = ?, hearing_time = ?, hearing_location = 'Barangay Hall'
            WHERE id = ?");
        mysqli_stmt_bind_param($s, "issi", $nextSession, $next_date, $next_time, $complaint_id);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);

        $nice = date('F j, Y', strtotime($next_date));
        logUpdate($conn, $complaint_id, 'hearing_scheduled', $meta['status'], 'mediation_scheduled',
            "Mediation Hearing #{$nextSession} on $nice at $next_time.", $admin_id, 'captain');

        $creator = getComplaintCreator($conn, $complaint_id);
        if ($creator) {
            sendNotification($conn, 'resident', $creator, $complaint_id,
                'Next Mediation Hearing Scheduled',
                "Mediation Hearing #{$nextSession} for complaint #{$meta['reference_number']} on $nice at $next_time.",
                'hearing');
        }
        echo json_encode(['success'=>true,'final'=>false,'message'=>"Mediation Hearing #$nextSession scheduled."]);
        exit;
    }

    $new = 'failed_mediation';
    $s = mysqli_prepare($conn, "UPDATE complaints SET status=?,
        mediation_last_result='no_settlement', resolution_notes=? WHERE id=?");
    mysqli_stmt_bind_param($s, "ssi", $new, $summary, $complaint_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);

    logUpdate($conn, $complaint_id, 'status_change', $meta['status'], $new,
        "Ended without settlement. $summary", $admin_id, 'captain');

    notifyAdminsByRole($conn, ['captain'], $complaint_id,
        'Mediation Ended — Constitute Pangkat',
        "Complaint #{$meta['reference_number']} — schedule the Pangkat constitution meeting.",
        'warning');

    echo json_encode(['success'=>true,'final'=>true,'message'=>'Mediation ended. Ready for Pangkat constitution.']);
    exit;
}

/* ============================================================
   CAPTAIN: Cancel hearing
   ============================================================ */
if ($action === 'cancel_hearing' && $adminRole === 'captain') {
    $hearing_id = (int)($_POST['hearing_id'] ?? 0);
    $reason     = mysqli_real_escape_string($conn, $_POST['reason'] ?? '');
    if (!$hearing_id || !$reason) { echo json_encode(['success'=>false,'message'=>'Required.']); exit; }
    $s = mysqli_prepare($conn,
        "SELECT h.*, c.reference_number FROM complaint_hearings h
         JOIN complaints c ON h.complaint_id = c.id         WHERE h.id = ? AND h.conducted_by = ?");
    mysqli_stmt_bind_param($s, "ii", $hearing_id, $admin_id);
    mysqli_stmt_execute($s);
    $h = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    if (!$h) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }

    $u = mysqli_prepare($conn, "UPDATE complaint_hearings SET status='cancelled',
        session_reason=CONCAT(COALESCE(session_reason,''), '\n[CANCELLED] ', ?) WHERE id = ?");
    mysqli_stmt_bind_param($u, "si", $reason, $hearing_id);
    mysqli_stmt_execute($u);
    mysqli_stmt_close($u);

    logUpdate($conn, $h['complaint_id'], 'note', null, null, "Hearing cancelled. Reason: $reason", $admin_id, 'captain');
    echo json_encode(['success'=>true,'message'=>'Hearing cancelled.']);
    exit;
}

/* ============================================================
   SECRETARY: Forward escalated to Captain
   ============================================================ */
if ($action === 'forward_escalated_to_captain' && $adminRole === 'secretary') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing ID.']); exit; }
    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }
    if ($meta['status'] !== 'escalated') {
        echo json_encode(['success'=>false,'message'=>'Only escalated cases.']); exit;
    }

    $refer_to              = trim($_POST['refer_to'] ?? '');
    $referral_date         = trim($_POST['referral_date'] ?? date('Y-m-d'));
    $actions_json          = $_POST['actions'] ?? '{}';
    $notes                 = trim($_POST['notes'] ?? '');
    $also_non_jurisdiction = ($_POST['also_non_jurisdiction'] ?? '0') === '1';
    $nj_reason             = trim($_POST['nj_reason'] ?? '');
    $attach_evidence       = ($_POST['attach_evidence'] ?? '0') === '1';
    if (!$refer_to) { echo json_encode(['success'=>false,'message'=>'Refer to required.']); exit; }

    $_POST['complaint_id']          = $complaint_id;
    $_POST['refer_to']              = $refer_to;
    $_POST['referral_date']         = $referral_date;
    $_POST['actions']               = $actions_json;
    $_POST['notes']                 = $notes;
    $_POST['also_non_jurisdiction'] = $also_non_jurisdiction ? '1' : '0';
    $_POST['nj_reason']             = $nj_reason;
    $_POST['attach_evidence']       = $attach_evidence ? '1' : '0';

    ob_start();
    require __DIR__ . '/generate_referral_pdf.php';
    $pdf_raw  = ob_get_clean();
    $pdf_resp = json_decode($pdf_raw, true);

    if (!$pdf_resp || empty($pdf_resp['success'])) {
        echo json_encode(['success'=>false,'message'=>$pdf_resp['message'] ?? 'PDF failed.']);
        exit;
    }

    $s = mysqli_prepare($conn,
        "UPDATE complaints SET status = 'pending_captain_review', escalation_saved_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);

    logUpdate($conn, $complaint_id, 'status_change', 'escalated', 'pending_captain_review',
        "Forwarded to Captain. Refer: $refer_to.", $admin_id, 'secretary');

    notifyAdminsByRole($conn, ['captain'], $complaint_id,
        'Referral Ready for Review',
        "Complaint #{$meta['reference_number']} — referral PDF ready.",
        'assignment');

    echo json_encode([
        'success'=>true,
        'message'=>'Forwarded to Captain.',
        'pdf_url'=>$pdf_resp['pdf_url'] ?? null,
        'filename'=>$pdf_resp['filename'] ?? null,
        'nj_pdf_url'=>$pdf_resp['nj_pdf_url'] ?? null,
        'nj_filename'=>$pdf_resp['nj_filename'] ?? null,
        'nj_certificate_no'=>$pdf_resp['nj_certificate_no'] ?? null,
    ]);
    exit;
}

/* ============================================================
   CAPTAIN: Sign & Dispatch Referral
   ============================================================ */
if ($action === 'captain_dispatch_referral' && $adminRole === 'captain') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing.']); exit; }
    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }
    if ($meta['status'] !== 'pending_captain_review') {
        echo json_encode(['success'=>false,'message'=>'Not pending captain review.']); exit;
    }
    $s = mysqli_prepare($conn,
        "UPDATE complaints SET status = 'referred_dispatched',
             referral_dispatched_at = NOW(), referral_dispatched_by = ?
         WHERE id = ?");
    mysqli_stmt_bind_param($s, "ii", $admin_id, $complaint_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);
    logUpdate($conn, $complaint_id, 'resolution', $meta['status'], 'referred_dispatched',
        "Signed by Captain.", $admin_id, 'captain');

    $creator = getComplaintCreator($conn, $complaint_id);
    if ($creator) {
        sendNotification($conn, 'resident', $creator, $complaint_id,
            'Referral Letter Released',
            "Please claim it at the Barangay Hall.",
            'success');
    }
    notifyAdminsByRole($conn, ['secretary'], $complaint_id,
        'Signed Referral Ready for Release',
        "Complaint #{$meta['reference_number']} — ready to release.",
        'info');

    echo json_encode(['success'=>true,'message'=>'Referral dispatched.']);
    exit;
}

/* ============================================================
   SECRETARY: Release referral
   ============================================================ */
if ($action === 'claim_referral_release' && $adminRole === 'secretary') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $release_notes = mysqli_real_escape_string($conn, $_POST['release_notes'] ?? '');
    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing.']); exit; }
    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }
    if ($meta['status'] !== 'referred_dispatched') {
        echo json_encode(['success'=>false,'message'=>'Not ready for release.']); exit;
    }
    $s = mysqli_prepare($conn,
        "UPDATE complaints SET status = 'released',
             released_at = NOW(), released_by = ?, release_notes = ?
         WHERE id = ?");
    mysqli_stmt_bind_param($s, "isi", $admin_id, $release_notes, $complaint_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);
    logUpdate($conn, $complaint_id, 'resolution', 'referred_dispatched', 'released',
        "Released to complainant. $release_notes", $admin_id, 'secretary');
    echo json_encode(['success'=>true,'message'=>'Referral released.']);
    exit;
}

/* ============================================================
   NOTIFICATIONS
   ============================================================ */
if ($action === 'get_my_notifications') {
    $s = mysqli_prepare($conn, "SELECT id, type, title, message, reference_id, reference_type, 
                                       document_path, is_read, created_at
                                FROM notifications WHERE user_type = 'admin' AND user_id = ?
                                ORDER BY created_at DESC LIMIT 20");
    mysqli_stmt_bind_param($s, "i", $admin_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $list = [];
    while ($row = mysqli_fetch_assoc($r)) $list[] = $row;
    mysqli_stmt_close($s);

    $s2 = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM notifications
                                 WHERE user_type = 'admin' AND user_id = ? AND is_read = 0");
    mysqli_stmt_bind_param($s2, "i", $admin_id);
    mysqli_stmt_execute($s2);
    $unread = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($s2))['c'];
    mysqli_stmt_close($s2);

    echo json_encode(['success'=>true,'notifications'=>$list,'unread_count'=>$unread]);
    exit;
}

if ($action === 'mark_notifications_read') {
    $s = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1, read_at = NOW()
                                WHERE user_type = 'admin' AND user_id = ? AND is_read = 0");
    mysqli_stmt_bind_param($s, "i", $admin_id);
    $ok = mysqli_stmt_execute($s);
    mysqli_stmt_close($s);
    echo json_encode(['success' => (bool)$ok]);
    exit;
}

if ($action === 'mark_notification_read') {
    $nid = (int)($_POST['notification_id'] ?? 0);
    if ($nid <= 0) { echo json_encode(['success'=>false]); exit; }
    $s = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1, read_at = NOW()
                                WHERE id = ? AND user_type = 'admin' AND user_id = ?");
    mysqli_stmt_bind_param($s, "ii", $nid, $admin_id);
    $ok = mysqli_stmt_execute($s);
    mysqli_stmt_close($s);
    echo json_encode(['success' => (bool)$ok]);
    exit;
}

/* ============================================================
   GET: Officials, assigned complaints
   ============================================================ */
if ($action === 'get_pending_complaints') {
    $sql = "SELECT c.*, r.first_name, r.last_name, r.email AS resident_email
            FROM complaints c JOIN resident r ON c.created_by = r.id
            WHERE c.status = 'pending_review'
            ORDER BY FIELD(c.priority,'urgent','high','medium','low'), c.created_at ASC";
    $r = mysqli_query($conn, $sql);
    $list = [];
    if ($r) while ($row = mysqli_fetch_assoc($r)) $list[] = $row;
    echo json_encode(['success'=>true,'complaints'=>$list]);
    exit;
}

if ($action === 'get_available_officials') {
    $role = $_GET['role'] ?? '';
    $s = mysqli_prepare($conn, "SELECT id, full_name, admin_role FROM admin WHERE admin_role = ? AND is_active = 1");
    mysqli_stmt_bind_param($s, "s", $role);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $list = [];
    while ($row = mysqli_fetch_assoc($r)) $list[] = $row;
    echo json_encode(['success'=>true,'officials'=>$list]);
    exit;
}

/* ============================================================
   GET: My assigned / Pangkat cases (LUPON)
   Returns conciliation explain deadline from complaint_hearings
   ============================================================ */
if ($action === 'get_my_assigned_complaints') {
    $s = mysqli_prepare($conn,
        "SELECT c.*, r.first_name, r.last_name, r.email AS resident_email,
                ab.full_name AS assigned_by_name, ab.admin_role AS assigned_by_role,
                cp.role AS pangkat_role,
                cp.kp11_pdf_path, cp.kp11_issued_at,
                (SELECT COUNT(*) FROM complaint_pangkat WHERE complaint_id = c.id) AS pangkat_count
         FROM complaints c
         JOIN resident r ON c.created_by = r.id
         LEFT JOIN admin ab ON ab.id = (
             SELECT assigned_by FROM complaint_assignments
             WHERE complaint_id = c.id AND assigned_to = ?
             ORDER BY assigned_at DESC LIMIT 1
         )
         LEFT JOIN complaint_pangkat cp
                ON cp.complaint_id = c.id AND cp.member_id = ?
         WHERE (
             c.assigned_to = ?
             OR cp.member_id = ?
         )
         ORDER BY FIELD(c.priority,'urgent','high','medium','low'), c.updated_at DESC");
    mysqli_stmt_bind_param($s, "iiii", $admin_id, $admin_id, $admin_id, $admin_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $list = [];
    while ($row = mysqli_fetch_assoc($r)) {
        if (!empty($row['pangkat_role'])) {
            $ms = mysqli_prepare($conn,
                "SELECT cp.member_id, cp.role, cp.kp11_pdf_path, cp.kp11_issued_at, a.full_name
                 FROM complaint_pangkat cp
                 JOIN admin a ON cp.member_id = a.id
                 WHERE cp.complaint_id = ?
                 ORDER BY cp.id ASC");
            mysqli_stmt_bind_param($ms, "i", $row['id']);
            mysqli_stmt_execute($ms);
            $mres = mysqli_stmt_get_result($ms);
            $members = [];
            $firstMemberId = null;
            $hasPending = false;
            while ($m = mysqli_fetch_assoc($mres)) {
                if ($firstMemberId === null) $firstMemberId = (int)$m['member_id'];
                if ($m['role'] === 'pending') $hasPending = true;
                $members[] = $m;
            }
            mysqli_stmt_close($ms);
            $row['pangkat_members'] = $members;
            $row['first_member_id'] = $firstMemberId;
            $row['has_pending_roles'] = $hasPending;
            $row['is_first_member'] = ($firstMemberId === (int)$admin_id);
            $row['my_member_id'] = $admin_id;
        }

        // ── Arbitration state (set by Pangkat Chair or Captain) ──
        $arb = getArbitrationState($conn, $row['id']);
        if ($arb) {
            $row['arbitration_status']               = $arb['status'] ?? null;
            $row['mediation_outcome']                = $arb['mediation_outcome'] ?? null;
            $row['kp14_pdf_path']                    = $arb['kp14_pdf_path'] ?? null;
            $row['arbitration_agreement_signed_at']  = $arb['arbitration_agreement_signed_at'] ?? null;
            $row['arbitration_repudiation_deadline'] = $arb['repudiation_deadline'] ?? null;
            $row['arbitration_repudiated']           = (int)($arb['arbitration_repudiated'] ?? 0);
            $row['arbitration_repudiated_at']        = $arb['arbitration_repudiated_at'] ?? null;
            $row['arbitration_repudiated_by_party']  = $arb['arbitration_repudiated_by_party'] ?? null;
            $row['arbitration_award_deadline']       = $arb['arbitration_award_deadline'] ?? null;
            $row['kp15_pdf_path']                    = $arb['kp15_pdf_path'] ?? null;
            $row['kp15_generated_at']                = $arb['kp15_generated_at'] ?? null;

            if (!empty($arb['repudiation_deadline']) && $arb['status'] === 'arbitration_pending') {
                $diff = strtotime($arb['repudiation_deadline']) - time();
                $row['arbitration_days_until_repudiation_expires'] = $diff > 0 ? (int)ceil($diff / 86400) : 0;
            }
            if (!empty($arb['arbitration_award_deadline']) && empty($arb['kp15_generated_at'])) {
                $diff = strtotime($arb['arbitration_award_deadline']) - time();
                $row['arbitration_days_until_award_due'] = $diff > 0 ? (int)ceil($diff / 86400) : 0;
            }
        }

        // Latest conciliation hearing — carries the 5-day explain window
        $latestHearing = getLatestConciliationHearing($conn, $row['id']);
        if ($latestHearing) {
            $row['conciliation_hearing'] = $latestHearing;
            $row['hearing_date'] = $latestHearing['hearing_date'];
            $row['hearing_time'] = $latestHearing['hearing_time'];
            $row['hearing_location'] = $latestHearing['location'];
            $row['concil_absence_explain_deadline'] = $latestHearing['explain_window_deadline'] ?? null;
            $row['explain_deadline'] = $latestHearing['explain_window_deadline'] ?? null;

            $srq = mysqli_prepare($conn, "SELECT session_record FROM complaint_hearings WHERE id = ?");
            mysqli_stmt_bind_param($srq, "i", $latestHearing['id']);
            mysqli_stmt_execute($srq);
            $srr = mysqli_fetch_assoc(mysqli_stmt_get_result($srq));
            mysqli_stmt_close($srq);
            $row['session_record'] = !empty($srr['session_record']) ? json_decode($srr['session_record'], true) : null;
        }

        $dq = mysqli_prepare($conn,
            "SELECT COUNT(*) AS c FROM complaint_pangkat_documents WHERE complaint_id = ?");
        mysqli_stmt_bind_param($dq, "i", $row['id']);
        mysqli_stmt_execute($dq);
        $row['document_count'] = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($dq))['c'] ?? 0);
        mysqli_stmt_close($dq);

        $list[] = $row;
    }
    mysqli_stmt_close($s);
    echo json_encode(['success'=>true,'complaints'=>$list,'user_role'=>$adminRole]);
    exit;
}

/* ============================================================
   SECRETARY: Mark prank / dismiss
   ============================================================ */
if ($action === 'mark_prank_invalid' && $adminRole === 'secretary') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $reason = mysqli_real_escape_string($conn, $_POST['reason'] ?? '');
    if (!$complaint_id || $reason === '') { echo json_encode(['success'=>false,'message'=>'Reason required.']); exit; }
    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }
    if ($meta['status'] !== 'escalated') { echo json_encode(['success'=>false,'message'=>'Only escalated.']); exit; }
    $s = mysqli_prepare($conn,
        "UPDATE complaints SET status = 'dismissed',
             resolution_notes = CONCAT(COALESCE(resolution_notes,''), '\n\n[PRANK/INVALID] ', ?)
         WHERE id = ?");
    mysqli_stmt_bind_param($s, "si", $reason, $complaint_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);
    logUpdate($conn, $complaint_id, 'resolution', $meta['status'], 'dismissed',
        "Marked PRANK/INVALID. $reason", $admin_id, 'secretary');
    $creator = getComplaintCreator($conn, $complaint_id);
    if ($creator) {
        sendNotification($conn, 'resident', $creator, $complaint_id,
            'Complaint Returned', "Marked PRANK/INVALID.", 'error');
    }
    echo json_encode(['success'=>true,'message'=>'Marked.']);
    exit;
}

/* ============================================================
   SECRETARY / CAPTAIN: Dismiss complaint with structured reason
   ------------------------------------------------------------
   Accepts:
     • reason           — free-text explanation (required)
     • reason_category  — one of:
         'prank', 'duplicate', 'incomplete', 'gibberish',
         'inappropriate', 'spam', 'placeholder', 'other'
       (optional — auto-derived from the client-side guard if
        the caller doesn't send one)
   Stores everything into resolution_notes + the audit log.
   ============================================================ */
if ($action === 'dismiss_complaint' && in_array($adminRole, ['secretary','captain'])) {
    $complaint_id    = (int)($_POST['complaint_id'] ?? 0);
    $reason          = trim($_POST['reason'] ?? '');
    $reason_category = trim($_POST['reason_category'] ?? '');

    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint ID.']); exit; }
    if ($reason === '') { echo json_encode(['success'=>false,'message'=>'Reason required.']); exit; }

    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }

    // Whitelist the category
    $allowedCats = ['prank','duplicate','possible_duplicate','incomplete',
                    'gibberish','inappropriate','spam','placeholder','other'];
    if ($reason_category !== '' && !in_array($reason_category, $allowedCats, true)) {
        $reason_category = 'other';
    }

    $label = $reason_category !== '' ? '[Dismissed: ' . strtoupper($reason_category) . '] ' : '[Dismissed] ';

    $s = mysqli_prepare($conn,
        "UPDATE complaints
            SET status='dismissed',
                resolution_notes = CONCAT(COALESCE(resolution_notes,''), '\n\n', ?, ?)
          WHERE id=?");
    mysqli_stmt_bind_param($s, "ssi", $label, $reason, $complaint_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);

    logUpdate($conn, $complaint_id, 'resolution', $meta['status'], 'dismissed',
        "Dismissed by {$adminRole}. " . $label . $reason,
        $admin_id, $adminRole);

    // Notify the complainant
    $creator = getComplaintCreator($conn, $complaint_id);
    if ($creator) {
        $title = 'Complaint Dismissed';
        $msg   = "Complaint #{$meta['reference_number']} has been dismissed.\n\nReason: {$reason}";
        sendNotification($conn, 'resident', $creator, $complaint_id, $title, $msg, 'error');
    }

    echo json_encode([
        'success'         => true,
        'message'         => 'Complaint dismissed.',
        'reason_category' => $reason_category,
    ]);
    exit;
}

if ($action === 'add_complaint_note' && in_array($adminRole, ['secretary','captain','lupon'])) {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $note = mysqli_real_escape_string($conn, $_POST['note'] ?? '');
    if (!$complaint_id || $note === '') { echo json_encode(['success'=>false,'message'=>'Note required.']); exit; }
    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }
    if ($adminRole === 'lupon') {
        $ctx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
        if (!$ctx) {
            echo json_encode(['success'=>false,'message'=>'Not assigned to you.']); exit;
        }
    }
    $s = mysqli_prepare($conn, "INSERT INTO complaint_updates
        (complaint_id, update_type, previous_status, new_status, notes, updated_by, updated_by_role)
        VALUES (?, 'note', NULL, NULL, ?, ?, ?)");
    mysqli_stmt_bind_param($s, "isis", $complaint_id, $note, $admin_id, $adminRole);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);
    echo json_encode(['success'=>true,'message'=>'Note added.']);
    exit;
}

/* ============================================================
   SECRETARY: Issue Certificate to File Action (legacy)
   ============================================================ */
if ($action === 'issue_certificate_to_file' && $adminRole === 'secretary') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $reason       = trim($_POST['reason'] ?? '');
    $cert_type    = $_POST['cert_type'] ?? 'file_action';
    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }

    $ns = mysqli_prepare($conn, "SELECT resolution_notes FROM complaints WHERE id = ?");
    mysqli_stmt_bind_param($ns, "i", $complaint_id);
    mysqli_stmt_execute($ns);
    $notesRow = mysqli_fetch_assoc(mysqli_stmt_get_result($ns));
    mysqli_stmt_close($ns);
    $resNotes = $notesRow['resolution_notes'] ?? '';

    if ($cert_type === 'bar_action') {
        if ($meta['status'] !== 'dismissed' || strpos($resNotes, '[COMPLAINANT NO-SHOW]') === false) {
            echo json_encode(['success'=>false,'message'=>'A Certification to Bar Action requires a complaint dismissed for complainant non-appearance.']); exit;
        }
        $prefix = 'CBA'; $label = 'Certification to Bar Action';
        if ($reason === '') $reason = 'Complainant failed to appear despite due notice.';
    } else {
        if (!in_array($meta['status'], ['failed_mediation','failed_conciliation_final'], true)) {
            echo json_encode(['success'=>false,'message'=>'Cannot issue.']); exit;
        }
        $prefix = 'CFT'; $label = 'Certificate to File Action';
    }

    $certNo = $prefix . '-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
    $s = mysqli_prepare($conn, "UPDATE complaints SET certificate_no=?, certificate_issued_at=NOW(),
        certificate_reason=?, status='certificate_issued' WHERE id=?");
    mysqli_stmt_bind_param($s, "ssi", $certNo, $reason, $complaint_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);
    logUpdate($conn, $complaint_id, 'resolution', $meta['status'], 'certificate_issued',
        "$label issued: $certNo (soft copy only, physical delivery required)", $admin_id, 'secretary');
    echo json_encode(['success'=>true,'message'=>"$label issued.",'certificate_no'=>$certNo,'cert_type'=>$cert_type]);
    exit;
}

/* ============================================================
   GET: Full complaint details
   Includes conciliation explain deadline from complaint_hearings.
   ============================================================ */
if ($action === 'get_admin_complaint_details') {
    $complaint_id = (int)($_GET['id'] ?? 0);
    if ($complaint_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id']); exit; }

    $s = mysqli_prepare($conn, "SELECT c.*, r.first_name, r.last_name, r.email AS resident_email,
                                       a.full_name AS assigned_to_name
                                FROM complaints c
                                JOIN resident r ON c.created_by = r.id
                                LEFT JOIN admin a ON c.assigned_to = a.id
                                WHERE c.id = ?");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $complaint = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    if (!$complaint) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }

    // ── Override hearing_date/time from the hearings table (authoritative) ──
$hs = mysqli_prepare($conn,
    "SELECT hearing_date, hearing_time
       FROM complaint_hearings
      WHERE complaint_id = ?
        AND session_type = 'mediation'
        AND status = 'scheduled'
      ORDER BY hearing_date ASC, hearing_time ASC, id ASC
      LIMIT 1");
if ($hs) {
    mysqli_stmt_bind_param($hs, "i", $complaint_id);
    mysqli_stmt_execute($hs);
    $hrow = mysqli_fetch_assoc(mysqli_stmt_get_result($hs));
    mysqli_stmt_close($hs);
    if ($hrow) {
        $complaint['hearing_date'] = $hrow['hearing_date'];
        $complaint['hearing_time'] = $hrow['hearing_time'];
    }
}

    $luponCtx = null;
    if ($adminRole === 'lupon') {
        $luponCtx = getPangkatMemberContext($conn, $complaint_id, $admin_id);
        if (!$luponCtx) {
            if ((int)$complaint['assigned_to'] !== $admin_id) {
                echo json_encode(['success'=>false,'message'=>'Not assigned.']); exit;
            }
        }
    }

    if (in_array($complaint['status'], ['for_mediation','mediation_scheduled']) && !empty($complaint['mediation_deadline'])) {
        $complaint['mediation_days_remaining'] = computeMediationDaysRemaining($complaint['mediation_deadline']);
    }
    if (!empty($complaint['conciliation_deadline'])) {
        $complaint['conciliation_days_remaining'] = computeDaysRemaining($complaint['conciliation_deadline']);
    }

    // Pull conciliation explain deadline from the latest conciliation hearing
    $latestConcil = getLatestConciliationHearing($conn, $complaint_id);
    $complaint['concil_absence_explain_deadline'] = $latestConcil['explain_window_deadline'] ?? null;
    $complaint['conciliation_explain_deadline'] = $latestConcil['explain_window_deadline'] ?? null;

    // Same for the latest mediation hearing (mediation-level 5-day explain window)
    $latestMediationExplain = null;
    $medId = getLatestMediationHearingId($conn, $complaint_id);
    if ($medId) {
        $eh = mysqli_prepare($conn,
            "SELECT explain_window_deadline FROM complaint_hearings WHERE id = ?");
        mysqli_stmt_bind_param($eh, "i", $medId);
        mysqli_stmt_execute($eh);
        $erow = mysqli_fetch_assoc(mysqli_stmt_get_result($eh));
        mysqli_stmt_close($eh);
        $latestMediationExplain = $erow['explain_window_deadline'] ?? null;
    }
    $complaint['absence_explain_deadline'] = $latestMediationExplain;
    $complaint['explain_deadline'] = $latestMediationExplain;

    $out = ['success' => true, 'complaint' => $complaint];
    $out['complainants'] = $out['respondents'] = $out['witnesses'] = [];
    $s = mysqli_prepare($conn, "SELECT * FROM complaint_parties WHERE complaint_id=? ORDER BY id");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    while ($row = mysqli_fetch_assoc($r)) {
        if ($row['party_type'] === 'complainant')    $out['complainants'][] = $row;
        elseif ($row['party_type'] === 'respondent') $out['respondents'][]  = $row;
        else                                          $out['witnesses'][]    = $row;
    }
    mysqli_stmt_close($s);

    if (intval($complaint['is_anonymous']) === 1) {
        $out['complaint']['first_name'] = "Confidential";
        $out['complaint']['last_name']  = "Source";
        foreach ($out['complainants'] as $i => $p) {
            $out['complainants'][$i]['full_name'] = "Confidential Whistleblower (Anonymous)";
            $out['complainants'][$i]['address']   = "Suppressed";
            $out['complainants'][$i]['contact_number'] = "Redacted";
        }
    }

    $out['incidents'] = [];
    $s = mysqli_prepare($conn, "SELECT * FROM complaint_incidents WHERE complaint_id=? ORDER BY incident_date, id");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    while ($row = mysqli_fetch_assoc($r)) $out['incidents'][] = $row;
    mysqli_stmt_close($s);

    $out['hearings'] = [];
    $s = mysqli_prepare($conn, "SELECT h.*, a.full_name AS conducted_by_name FROM complaint_hearings h
                                LEFT JOIN admin a ON h.conducted_by=a.id
                                WHERE h.complaint_id=? ORDER BY h.hearing_date DESC, h.hearing_time DESC");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    while ($row = mysqli_fetch_assoc($r)) {
        if (!empty($row['session_record'])) {
            $row['session_record'] = json_decode($row['session_record'], true);
        }
        $out['hearings'][] = $row;
    }
    mysqli_stmt_close($s);

    $out['updates'] = [];
    $s = mysqli_prepare($conn, "SELECT * FROM complaint_updates WHERE complaint_id=? ORDER BY created_at DESC");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    while ($row = mysqli_fetch_assoc($r)) $out['updates'][] = $row;
    mysqli_stmt_close($s);

    $out['evidence'] = [];
    $s = mysqli_prepare($conn, "SELECT * FROM complaint_evidence WHERE complaint_id=? ORDER BY uploaded_at DESC");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    while ($row = mysqli_fetch_assoc($r)) $out['evidence'][] = $row;
    mysqli_stmt_close($s);

    $out['pangkat'] = [];
    $s = mysqli_prepare($conn,
        "SELECT cp.member_id, cp.role, cp.selected_by, cp.kp11_pdf_path, cp.kp11_issued_at, a.full_name
         FROM complaint_pangkat cp
         JOIN admin a ON cp.member_id = a.id
         WHERE cp.complaint_id = ?
         ORDER BY cp.id ASC");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    while ($row = mysqli_fetch_assoc($r)) $out['pangkat'][] = $row;
    mysqli_stmt_close($s);

    $out['pangkat_documents'] = [];
    $s = mysqli_prepare($conn,
        "SELECT d.*, a.full_name AS uploader_name
         FROM complaint_pangkat_documents d
         LEFT JOIN admin a ON a.id = d.uploaded_by
         WHERE d.complaint_id = ?
         ORDER BY d.created_at DESC");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    while ($row = mysqli_fetch_assoc($r)) $out['pangkat_documents'][] = $row;
    mysqli_stmt_close($s);

    $out['assignments'] = [];
    $s = mysqli_prepare($conn,
        "SELECT ca.*, a.full_name AS assignee_name, ab.full_name AS assigned_by_name
         FROM complaint_assignments ca
         LEFT JOIN admin a  ON ca.assigned_to = a.id
         LEFT JOIN admin ab ON ca.assigned_by = ab.id
         WHERE ca.complaint_id = ? ORDER BY ca.assigned_at DESC");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    while ($row = mysqli_fetch_assoc($r)) $out['assignments'][] = $row;
    mysqli_stmt_close($s);

    $out['summary'] = generateComplaintSummary(
        $conn, $complaint, $out['complainants'], $out['respondents'], $out['witnesses'],
        $out['incidents'], $out['updates']
    );

    $out['constitution_state'] = getPangkatConstitutionState($conn, $complaint_id);

    if ($adminRole === 'lupon' && $luponCtx) {
        $out['my_pangkat'] = [
            'my_role'       => $luponCtx['my_role'],
            'is_first'      => $luponCtx['is_first'],
            'all_pending'   => $luponCtx['all_pending'],
            'first_member_id' => $luponCtx['first_member_id'],
        ];
    }

    $out['kp_forms'] = build_kp_forms_list($conn, $complaint_id, true);

    $out['kp_paths'] = [
        'kp15' => $complaint['kp15_pdf_path'] ?? null,
        'kp16' => $complaint['kp16_pdf_path'] ?? null,
        'kp17' => $complaint['kp17_pdf_path'] ?? null,
        'kp20' => $complaint['kp20_pdf_path'] ?? null,
        'kp21' => $complaint['kp21_pdf_path'] ?? null,
        'kp22' => $complaint['kp22_pdf_path'] ?? null,
        // ✅ NEW: KP #20-A alias (underlying column is kp21_pdf_path)
        'kp20a' => $complaint['kp21_pdf_path'] ?? null,
    ];

    $out['conciliation_window'] = [
        'first_hearing_date'    => $complaint['conciliation_first_hearing_date'] ?? null,
        'hard_deadline'         => getConciliationHardDeadline($conn, $complaint_id),
        'extended'              => (int)($complaint['conciliation_extended'] ?? 0),
        'extension_days'        => (int)($complaint['conciliation_extension_days'] ?? 0),
    ];

    // Conciliation absence state (5-day explain window from the hearing)
    $out['conciliation_absence'] = [
        'waiting_since'     => $complaint['concil_absence_waiting_since'] ?? null,
        'appearance_at'     => $complaint['concil_absence_appearance_at'] ?? null,
        'explain_deadline'  => $latestConcil['explain_window_deadline'] ?? null,
    ];

    $out['settlement'] = [
        'settled_pending_at'         => $complaint['settled_pending_at'] ?? null,
        'settlement_signed_at'       => $complaint['settlement_signed_at'] ?? null,
        'amicable_agreement_signed_at' => $complaint['amicable_agreement_signed_at'] ?? null,
        'repudiation_deadline'       => $complaint['repudiation_deadline'] ?? null,
        'repudiated'                 => (int)($complaint['amicable_repudiated'] ?? 0),
        'repudiated_at'              => $complaint['amicable_repudiated_at'] ?? null,
        'repudiated_by_party'        => $complaint['amicable_repudiated_by_party'] ?? null,
        'is_executory'               => (int)($complaint['is_executory'] ?? 0),
        'days_until_executory'       => null,
    ];

        $arb = getArbitrationState($conn, $complaint_id);
    $out['arbitration'] = [
        'mediation_outcome'              => $arb['mediation_outcome'] ?? null,
        'kp14_pdf_path'                  => $arb['kp14_pdf_path'] ?? null,
        'kp14_generated_at'              => $arb['kp14_generated_at'] ?? null,
        'agreement_signed_at'            => $arb['arbitration_agreement_signed_at'] ?? null,
        'repudiation_deadline'           => $arb['repudiation_deadline'] ?? null,
        'repudiated'                     => (int)($arb['arbitration_repudiated'] ?? 0),
        'repudiated_at'                  => $arb['arbitration_repudiated_at'] ?? null,
        'repudiated_by_party'            => $arb['arbitration_repudiated_by_party'] ?? null,
        'award_deadline'                 => $arb['arbitration_award_deadline'] ?? null,
        'kp15_pdf_path'                  => $arb['kp15_pdf_path'] ?? null,
        'kp15_generated_at'              => $arb['kp15_generated_at'] ?? null,
        'days_until_repudiation_expires' => null,
        'days_until_award_due'           => null,
    ];
    if (!empty($arb['repudiation_deadline']) && $arb['status'] === 'arbitration_pending') {
        $diff = strtotime($arb['repudiation_deadline']) - time();
        $out['arbitration']['days_until_repudiation_expires'] = $diff > 0 ? (int)ceil($diff / 86400) : 0;
    }
    if (!empty($arb['arbitration_award_deadline']) && empty($arb['kp15_generated_at'])) {
        $diff = strtotime($arb['arbitration_award_deadline']) - time();
        $out['arbitration']['days_until_award_due'] = $diff > 0 ? (int)ceil($diff / 86400) : 0;
    }
    if (!empty($complaint['repudiation_deadline']) && $complaint['status'] === 'settled_pending') {
        $diff = strtotime($complaint['repudiation_deadline']) - time();
        $out['settlement']['days_until_executory'] = $diff > 0 ? (int)ceil($diff / 86400) : 0;
    }

    echo json_encode($out);
    exit;
}

/* ============================================================
   SUMMARY GENERATOR
   ============================================================ */
function generateComplaintSummary($conn, $complaint, $complainants, $respondents, $witnesses, $incidents, $updates) {
    $ref = $complaint['reference_number'] ?? '';
    $title = $complaint['title'] ?? '';
    $subject = $complaint['complaint_subject'] ?? '';
    $status = ucwords(str_replace('_', ' ', $complaint['status'] ?? ''));
    $compNames = array_map(fn($p) => $p['full_name'], $complainants);
    $respNames = array_map(fn($p) => $p['full_name'], $respondents);
    $created = $complaint['created_at'] ?? null;
    $createdTxt = $created ? date('F j, Y', strtotime($created)) : '—';

    $lines = [];
    $lines[] = "Complaint **{$ref}** — \"**{$title}**\".";
    if ($subject) $lines[] = "Filed as a **{$subject}** on **{$createdTxt}**.";
    if ($compNames) $lines[] = "Complainant: **" . implode('**, **', $compNames) . "**";
    if ($respNames) $lines[] = "Respondent: **" . implode('**, **', $respNames) . "**";
    $lines[] = "Status: **{$status}**.";
    return implode("\n", $lines);
}

/* ============================================================
   SCHEDULING CHECKS
   ============================================================ */
if ($action === 'check_schedule_conflict') {
    $date    = $_GET['date'] ?? '';
    $time    = $_GET['time'] ?? '';
    $exclude = (int)($_GET['exclude_hearing_id'] ?? 0);
    $res = checkScheduleConflict($conn, $date, $time, $admin_id, 60, $exclude);
    echo json_encode(['success' => true] + $res);
    exit;
}

if ($action === 'get_scheduling_limits') {
    $date = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['success'=>false,'message'=>'Invalid date']); exit;
    }
    list($minTime, $maxTime, $reason) = getSchedulingLimits($date);
    echo json_encode([
        'success'=>true,'allowed'=>($minTime !== null && $maxTime !== null),
        'min_time'=>$minTime,'max_time'=>$maxTime,'reason'=>$reason,'date'=>$date
    ]);
    exit;
}

if ($action === 'check_scheduling_live') {
    $date    = $_GET['date'] ?? '';
    $time    = $_GET['time'] ?? '';
    $exclude = (int)($_GET['exclude_hearing_id'] ?? 0);
    list($minTime, $maxTime, $limitReason) = getSchedulingLimits($date);
    $result = ['success'=>true,'allowed'=>true,'min_time'=>$minTime,'max_time'=>$maxTime,'conflict'=>false,'reason'=>''];
    if ($minTime === null || $maxTime === null) {
        $result['allowed'] = false; $result['reason'] = $limitReason;
        echo json_encode($result); exit;
    }
    if (!$date || !$time) { $result['reason'] = $limitReason; echo json_encode($result); exit; }
    $check = checkScheduleConflict($conn, $date, $time, $admin_id, 60, $exclude);
    if (!empty($check['conflict'])) {
        $result['allowed']  = false;
        $result['conflict'] = true;
        $result['reason']   = $check['reason'];
        $result['type']     = $check['type'] ?? 'conflict';
    } else {
        $result['reason'] = 'Time slot is available.';
    }
    echo json_encode($result);
    exit;
}

if ($action === 'get_mediation_period_info') {
    $cid = (int)($_GET['complaint_id'] ?? 0);
    if (!$cid) { echo json_encode(['success'=>false,'message'=>'Missing id']); exit; }
    $s = mysqli_prepare($conn,
        "SELECT mediation_started_at, mediation_deadline, mediation_session_count, mediation_last_result
         FROM complaints WHERE id = ?");
    mysqli_stmt_bind_param($s, "i", $cid);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    if (!$row) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
    $days = computeMediationDaysRemaining($row['mediation_deadline']);
    echo json_encode(['success'=>true,'info'=>[
        'started_at'=>$row['mediation_started_at'],'deadline'=>$row['mediation_deadline'],
        'days_remaining'=>$days,'session_count'=>(int)$row['mediation_session_count'],
        'last_result'=>$row['mediation_last_result'],'period_active'=>($days !== null && $days > 0)
    ]]);
    exit;
}

/* ============================================================
   GET: All complaints
   ------------------------------------------------------------
   Extended with material fields used by the client-side strict
   duplicate detector:
     • complainant_names      → pipe-joined, normalized on client
     • respondent_names       → pipe-joined, normalized on client
     • respondent_ids         → raw IDs (debug / future use)
     • primary_complainant    → first complainant (fallback)
     • primary_respondent     → first respondent (fallback)
     • first_incident_date    → earliest incident date
     • first_incident_time    → earliest incident time
     • first_incident_location→ location of the earliest incident
     • incident_count         → how many incidents this complaint has
   ============================================================ */
if ($action === 'get_all_complaints') {
    $status = $_GET['status'] ?? '';
    $role   = $_GET['role']   ?? '';
    $search = $_GET['search'] ?? '';

    // ── Subqueries feeding the client-side duplicate detector ──
    $complainantNames = "
        (SELECT GROUP_CONCAT(cp.full_name SEPARATOR '|')
           FROM complaint_parties cp
          WHERE cp.complaint_id = c.id
            AND cp.party_type = 'complainant') AS complainant_names
    ";
    $respondentNames = "
        (SELECT GROUP_CONCAT(cp.full_name SEPARATOR '|')
           FROM complaint_parties cp
          WHERE cp.complaint_id = c.id
            AND cp.party_type = 'respondent') AS respondent_names
    ";
    $respondentIds = "
        (SELECT GROUP_CONCAT(cp.id SEPARATOR '|')
           FROM complaint_parties cp
          WHERE cp.complaint_id = c.id
            AND cp.party_type = 'respondent') AS respondent_ids
    ";
    $primaryComplainant = "
        (SELECT cp.full_name FROM complaint_parties cp
          WHERE cp.complaint_id = c.id AND cp.party_type = 'complainant'
          ORDER BY cp.id LIMIT 1) AS primary_complainant
    ";
    $primaryRespondent = "
        (SELECT cp.full_name FROM complaint_parties cp
          WHERE cp.complaint_id = c.id AND cp.party_type = 'respondent'
          ORDER BY cp.id LIMIT 1) AS primary_respondent
    ";
    $firstIncidentDate = "
        (SELECT MIN(i.incident_date) FROM complaint_incidents i
          WHERE i.complaint_id = c.id) AS first_incident_date
    ";
    $firstIncidentTime = "
        (SELECT MIN(i.incident_time) FROM complaint_incidents i
          WHERE i.complaint_id = c.id) AS first_incident_time
    ";
    $firstIncidentLocation = "
        (SELECT MIN(i.location) FROM complaint_incidents i
          WHERE i.complaint_id = c.id) AS first_incident_location
    ";
    $incidentCount = "
        (SELECT COUNT(*) FROM complaint_incidents i
          WHERE i.complaint_id = c.id) AS incident_count
    ";

    $sql = "SELECT c.*, r.first_name, r.last_name, r.email AS resident_email,
                   a.full_name AS assigned_to_name, a.admin_role AS assigned_role_name,
                   $complainantNames,
                   $respondentNames,
                   $respondentIds,
                   $primaryComplainant,
                   $primaryRespondent,
                   $firstIncidentDate,
                   $firstIncidentTime,
                   $firstIncidentLocation,
                   $incidentCount
            FROM complaints c
            JOIN resident r ON c.created_by = r.id
            LEFT JOIN admin a ON c.assigned_to = a.id";

    $where = []; $params = []; $types = "";
    if ($status && $status !== 'all') { $where[] = "c.status = ?";        $params[] = $status; $types .= "s"; }
    if ($role   && $role   !== 'all') { $where[] = "c.assigned_role = ?"; $params[] = $role;   $types .= "s"; }
    if ($search) {
        $where[] = "(c.title LIKE ? OR c.reference_number LIKE ? OR r.first_name LIKE ? OR r.last_name LIKE ?)";
        $like = "%$search%";
        array_push($params, $like, $like, $like, $like);
        $types .= "ssss";
    }
    if ($where) $sql .= " WHERE " . implode(" AND ", $where);
    $sql .= " ORDER BY FIELD(c.priority,'urgent','high','medium','low'), c.created_at DESC";

    if ($params) {
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
    } else {
        $result = mysqli_query($conn, $sql);
    }

    $complaints = [];
    while ($row = mysqli_fetch_assoc($result)) {
        if ($row['status'] === 'for_mediation' && !empty($row['mediation_deadline'])) {
            $row['mediation_days_remaining'] = computeMediationDaysRemaining($row['mediation_deadline']);
        }
        $complaints[] = $row;
    }

    $counts = [];
    foreach ([
        'pending_review', 'pending_captain_action', 'summoned',
        'for_mediation', 'mediation_scheduled', 'in_mediation',
        'settled', 'settled_pending', 'failed_mediation', 'failed_conciliation_final',
        'pangkat_constitution_scheduled', 'pangkat_constituted', 'pangkat_scheduled',
        'escalated', 'pending_captain_review',
        'referred', 'referred_dispatched', 'released',
        'certificate_issued', 'dismissed',
    ] as $k) {
        $v = "'$k'";
        $r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM complaints WHERE status = $v");
        $counts[$k] = (int)mysqli_fetch_assoc($r)['c'];
    }
    $r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM complaints");
    $counts['total'] = (int)mysqli_fetch_assoc($r)['c'];

    echo json_encode(['success' => true, 'complaints' => $complaints, 'counts' => $counts]);
    exit;
}

if ($action === 'get_captain_all_complaints' && $adminRole === 'captain') {

    // ─────────────────────────────────────────────────────────────
    // Filters coming from the frontend
    // ─────────────────────────────────────────────────────────────
    $search      = trim($_GET['search']      ?? '');
    $daysBack    = (int)($_GET['days']       ?? 30);   // 0 = all time
    $customFrom  = trim($_GET['date_from']   ?? '');
    $customTo    = trim($_GET['date_to']     ?? '');
    $useCustom   = ($daysBack === -1);

    // Build the date filter fragment used by every bucket query.
    // NOTE: uses created_at as the reference date — safe on every schema.
    $dateWhere = '';
    if ($useCustom && $customFrom !== '' && $customTo !== '') {
        $df = mysqli_real_escape_string($conn, $customFrom);
        $dt = mysqli_real_escape_string($conn, $customTo);
        $dateWhere = " AND DATE(c.created_at) BETWEEN '$df' AND '$dt'";
    } elseif ($daysBack > 0) {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$daysBack} days"));
        $cutoffEsc = mysqli_real_escape_string($conn, $cutoff);
        $dateWhere = " AND c.created_at >= '$cutoffEsc'";
    }

    // Search filter fragment (matches ref, title, or complainant name)
    $searchWhere = '';
    if ($search !== '') {
        $like = '%' . mysqli_real_escape_string($conn, $search) . '%';
        $searchWhere = " AND (
            c.reference_number LIKE '$like'
            OR c.title LIKE '$like'
            OR EXISTS (
                SELECT 1 FROM complaint_parties cp
                 WHERE cp.complaint_id = c.id
                   AND cp.party_type = 'complainant'
                   AND cp.full_name LIKE '$like'
            )
        )";
    }

    // ═══════════════════════════════════════════════════════════════
    // BUCKET 1 — Pending Captain Action
    // ═══════════════════════════════════════════════════════════════
    $pending = [];
    $sql = "SELECT c.*, r.first_name, r.last_name, r.email AS resident_email
              FROM complaints c
              JOIN resident r ON c.created_by = r.id
             WHERE c.status = 'pending_captain_action'
             $dateWhere
             $searchWhere
             ORDER BY FIELD(c.priority,'urgent','high','medium','low'), c.created_at ASC";
    $q = mysqli_query($conn, $sql);
    if ($q) while ($row = mysqli_fetch_assoc($q)) $pending[] = $row;

    // ═══════════════════════════════════════════════════════════════
    // BUCKET 2 — Mediation (includes arbitration states)
    // ═══════════════════════════════════════════════════════════════
    $mediation = [];
    $sql = "SELECT DISTINCT c.*, r.first_name, r.last_name, r.email AS resident_email,
                   h.hearing_date    AS next_hearing_date,
                   h.hearing_time    AS next_hearing_time,
                   h.location        AS next_hearing_location,
                   h.session_number  AS next_session_number,
                   (SELECT outcome
                      FROM complaint_hearings
                     WHERE complaint_id = c.id
                       AND session_type = 'mediation'
                     ORDER BY session_number DESC, id DESC
                     LIMIT 1) AS last_hearing_outcome
              FROM complaints c
              JOIN resident r ON c.created_by = r.id
              LEFT JOIN complaint_assignments ca
                     ON ca.complaint_id = c.id AND ca.assigned_role = 'captain'
              LEFT JOIN complaint_hearings h
                     ON h.id = (
                         SELECT id FROM complaint_hearings
                          WHERE complaint_id = c.id
                            AND session_type = 'mediation'
                            AND status = 'scheduled'
                          ORDER BY session_number DESC, id DESC
                          LIMIT 1
                     )
             WHERE c.status IN (
                 'for_mediation','mediation_scheduled',
                 'arbitration_pending','arbitration_agreement_active','arbitration_award_issued'
             )
               AND (c.assigned_to = ? OR ca.assigned_to = ?)
               $dateWhere
               $searchWhere
             ORDER BY FIELD(c.priority,'urgent','high','medium','low'),
                      h.hearing_date ASC, h.hearing_time ASC";
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ii", $admin_id, $admin_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            if (!empty($row['mediation_deadline'])) {
                $row['mediation_days_remaining'] = computeMediationDaysRemaining($row['mediation_deadline']);
            }
            $row['hearing_date']     = $row['next_hearing_date'];
            $row['hearing_time']     = $row['next_hearing_time'];
            $row['hearing_location'] = $row['next_hearing_location'];
            $row['session_number']   = $row['next_session_number'] ?? $row['mediation_session_count'] ?? 1;
            unset($row['next_hearing_date'], $row['next_hearing_time'],
                  $row['next_hearing_location'], $row['next_session_number']);

            $medId = getLatestMediationHearingId($conn, $row['id']);
            $row['explain_deadline'] = null;
            if ($medId) {
                $eh = mysqli_prepare($conn,
                    "SELECT explain_window_deadline FROM complaint_hearings WHERE id = ?");
                mysqli_stmt_bind_param($eh, "i", $medId);
                mysqli_stmt_execute($eh);
                $erow = mysqli_fetch_assoc(mysqli_stmt_get_result($eh));
                mysqli_stmt_close($eh);
                $row['explain_deadline'] = $erow['explain_window_deadline'] ?? null;
            }
            $mediation[] = $row;
        }
        mysqli_stmt_close($stmt);
    }

    // ═══════════════════════════════════════════════════════════════
    // BUCKET 3 — For Pangkat (includes unresolved so it doesn't vanish)
    // ═══════════════════════════════════════════════════════════════
    $pangkat = [];
    $sql = "SELECT DISTINCT c.*, r.first_name, r.last_name, r.email AS resident_email,
                   (SELECT COUNT(*) FROM complaint_pangkat WHERE complaint_id = c.id) AS pangkat_count,
                   (SELECT GROUP_CONCAT(CONCAT(a.full_name, '|', cp.role) SEPARATOR '||')
                      FROM complaint_pangkat cp
                      JOIN admin a ON a.id = cp.member_id
                     WHERE cp.complaint_id = c.id) AS pangkat_summary
              FROM complaints c
              JOIN resident r ON c.created_by = r.id
              LEFT JOIN complaint_assignments ca
                     ON ca.complaint_id = c.id AND ca.assigned_role = 'captain'
             WHERE c.status IN (
                 'failed_mediation','pangkat_constitution_scheduled',
                 'pangkat_constituted','pangkat_scheduled'
             )
               AND (c.assigned_to = ? OR ca.assigned_to = ?)
               $dateWhere
               $searchWhere
             ORDER BY FIELD(c.priority,'urgent','high','medium','low'), c.created_at DESC";
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ii", $admin_id, $admin_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            if (!empty($row['conciliation_deadline'])) {
                $row['conciliation_days_remaining'] = computeDaysRemaining($row['conciliation_deadline']);
            }
            $latestConcil = getLatestConciliationHearing($conn, $row['id']);
            $row['explain_deadline'] = $latestConcil['explain_window_deadline'] ?? null;
            $pangkat[] = $row;
        }
        mysqli_stmt_close($stmt);
    }

    // ═══════════════════════════════════════════════════════════════
    // BUCKET 4 — Repudiated (amicable + arbitration)
    // ═══════════════════════════════════════════════════════════════
    $repudiated = [];
    $sql = "SELECT DISTINCT c.*, r.first_name, r.last_name, r.email AS resident_email,
                   (SELECT GROUP_CONCAT(CONCAT(a.full_name, '|', cp.role) SEPARATOR '||')
                      FROM complaint_pangkat cp
                      JOIN admin a ON a.id = cp.member_id
                     WHERE cp.complaint_id = c.id) AS pangkat_summary,
                   (SELECT COUNT(*) FROM complaint_pangkat WHERE complaint_id = c.id) AS pangkat_count
              FROM complaints c
              JOIN resident r ON c.created_by = r.id
             WHERE c.status IN ('amicable_repudiated','arbitration_repudiated')
               $dateWhere
               $searchWhere
             ORDER BY c.created_at DESC";
    $q = mysqli_query($conn, $sql);
    if ($q) while ($row = mysqli_fetch_assoc($q)) $repudiated[] = $row;

    // ═══════════════════════════════════════════════════════════════
    // BUCKET 5 — Unresolved (CFA issued, waiting for physical delivery)
    // ═══════════════════════════════════════════════════════════════
    $unresolved = [];
    $sql = "SELECT DISTINCT c.*, r.first_name, r.last_name, r.email AS resident_email
              FROM complaints c
              JOIN resident r ON c.created_by = r.id
             WHERE c.status IN ('unresolved','failed_conciliation_final')
               $dateWhere
               $searchWhere
             ORDER BY c.created_at DESC";
    $q = mysqli_query($conn, $sql);
    if ($q) while ($row = mysqli_fetch_assoc($q)) $unresolved[] = $row;

    // ═══════════════════════════════════════════════════════════════
    // BUCKET 6 — Referrals
    // ═══════════════════════════════════════════════════════════════
    $referrals = [];
    $sql = "SELECT DISTINCT c.*, r.first_name, r.last_name, r.email AS resident_email
              FROM complaints c
              JOIN resident r ON c.created_by = r.id
             WHERE (
                 c.status IN ('pending_captain_review','referred_dispatched','released')
                 OR (c.referral_pdf_path IS NOT NULL
                     AND c.status NOT IN ('pending_captain_review','referred_dispatched','released'))
             )
               $dateWhere
               $searchWhere
             ORDER BY FIELD(c.priority,'urgent','high','medium','low'), c.created_at DESC";
    $q = mysqli_query($conn, $sql);
    if ($q) while ($row = mysqli_fetch_assoc($q)) $referrals[] = $row;

    // ═══════════════════════════════════════════════════════════════
    // BUCKET 7 — Handled (resolved cases INCLUDING unresolved)
    // ═══════════════════════════════════════════════════════════════
    $handled = [];
    $sql = "SELECT DISTINCT c.*, r.first_name, r.last_name, r.email AS resident_email
              FROM complaints c
              JOIN resident r ON c.created_by = r.id
              LEFT JOIN complaint_assignments ca
                     ON ca.complaint_id = c.id AND ca.assigned_role = 'captain'
             WHERE (
                 c.assigned_to = ?
                 OR ca.assigned_to = ?
                 OR c.referral_dispatched_by = ?
                 OR c.assigned_to IS NULL
             )
               AND c.status IN (
                   'settled','settled_pending','dismissed','certificate_issued','released',
                   'failed_mediation','referred_dispatched',
                   'amicable_repudiated','arbitration_repudiated',
                   'unresolved','failed_conciliation_final'
               )
               $dateWhere
               $searchWhere
             ORDER BY c.created_at DESC";
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "iii", $admin_id, $admin_id, $admin_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) $handled[] = $row;
        mysqli_stmt_close($stmt);
    }

    echo json_encode([
        'success'    => true,
        'pending'    => $pending,
        'mediation'  => $mediation,
        'pangkat'    => $pangkat,
        'repudiated' => $repudiated,
        'unresolved' => $unresolved,
        'referrals'  => $referrals,
        'handled'    => $handled,
        'counts'     => [
            'pending'    => count($pending),
            'mediation'  => count($mediation),
            'pangkat'    => count($pangkat),
            'repudiated' => count($repudiated),
            'unresolved' => count($unresolved),
            'referrals'  => count($referrals),
            'handled'    => count($handled),
        ],
    ]);
    exit;
}

/* ============================================================
   SECRETARY: Forward to Captain
   ============================================================ */
if ($action === 'forward_to_captain' && $adminRole === 'secretary') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $notes        = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint ID.']); exit; }
    $meta = getComplaintMeta($conn, $complaint_id);
    if (!$meta) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }
    if ($meta['status'] !== 'pending_review') {
        echo json_encode(['success'=>false,'message'=>'Only complaints in Pending Review can be forwarded.']); exit;
    }
    $cStmt = mysqli_prepare($conn,
        "SELECT id, full_name FROM admin WHERE admin_role = 'captain' AND is_active = 1 ORDER BY id ASC LIMIT 1");
    mysqli_stmt_execute($cStmt);
    $captain = mysqli_fetch_assoc(mysqli_stmt_get_result($cStmt));
    mysqli_stmt_close($cStmt);
    if (!$captain) { echo json_encode(['success'=>false,'message'=>'No active Captain.']); exit; }
    $captain_id = (int)$captain['id'];

    $s = mysqli_prepare($conn,
        "UPDATE complaints SET status = 'pending_captain_action', assigned_to = ?, assigned_role = 'captain' WHERE id = ?");
    mysqli_stmt_bind_param($s, "ii", $captain_id, $complaint_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);

    logUpdate($conn, $complaint_id, 'status_change', 'pending_review', 'pending_captain_action',
        "Secretary forwarded to Captain. {$notes}", $admin_id, 'secretary');

    $title = 'Complaint was sent to you — Set Mediation Schedule';
    $msg   = "Complaint #{$meta['reference_number']} — validated. Please set the mediation schedule.";
    sendNotification($conn, 'admin', $captain_id, $complaint_id, $title, $msg, 'assignment');

    echo json_encode(['success' => true, 'message' => 'Forwarded to Captain.', 'captain_name' => $captain['full_name']]);
    exit;
}

/* ============================================================
   Fallback
   ============================================================ */
echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);