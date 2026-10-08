<?php
// cron/cron_bootstrap.php
// ============================================================
// Shared helpers for ALL cron tasks.
// Loaded once by cron/run.php before any task runs.
//
// Function names are prefixed with cron_* to avoid colliding
// with anything in admin_complaint_ajax.php.
// ============================================================

date_default_timezone_set('Asia/Manila');

if (!defined('CRON_LOG_FILE')) {
    define('CRON_LOG_FILE', __DIR__ . '/../logs/cron.log');
}

/* ---------------- Logging ---------------- */
function cronLog($msg) {
    $dir = dirname(CRON_LOG_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents(CRON_LOG_FILE, $line, FILE_APPEND);
}

/* ---------------- Safe push wrapper ---------------- */
/**
 * Never throws. If pushToUser() is broken or missing in CLI context,
 * logs the reason and returns false so the caller can continue.
 */
function cronSafePush($conn, $user_id, $title, $body, $url = '') {
    cronLog("cronSafePush: called for user=$user_id");
    if (!function_exists('pushToUser')) {
        cronLog("cronSafePush: pushToUser NOT DEFINED (push_send.php not loaded)");
        return false;
    }
    try {
        $sent = pushToUser($conn, $user_id, $title, $body, $url);
        if ($sent > 0) {
            cronLog("cronSafePush: OK user=$user_id sent=$sent");
            return true;
        }
        cronLog("cronSafePush: user=$user_id has NO subscription (returned 0)");
        return false;
    } catch (Throwable $e) {
        cronLog("cronSafePush: FAILED user=$user_id: " . $e->getMessage());
        return false;
    }
}
function cronSafePushResident($conn, $resident_id, $title, $body, $url = '') {
    if (!function_exists('pushToResident')) {
        cronLog("cronSafePushResident: pushToResident not defined");
        return false;
    }
    try {
        $sent = pushToResident($conn, $resident_id, $title, $body, $url);
        if ($sent > 0) {
            cronLog("cronSafePushResident: OK resident=$resident_id sent=$sent");
            return true;
        }
        cronLog("cronSafePushResident: resident=$resident_id has NO subscription");
        return false;
    } catch (Throwable $e) {
        cronLog("cronSafePushResident FAILED resident=$resident_id: " . $e->getMessage());
        return false;
    }
}

/* ---------------- Notifications ---------------- */
function cronSendNotification($conn, $user_type, $user_id, $complaint_id, $title, $message, $type = 'info') {
    $resident_id    = ($user_type === 'resident') ? $user_id : null;
    $reference_id   = $complaint_id ?: null;
    $reference_type = $complaint_id ? 'complaint' : null;

    $sql = "INSERT INTO notifications
              (user_type, user_id, resident_id, type, title, message, reference_id, reference_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $s = mysqli_prepare($conn, $sql);
    if (!$s) { cronLog("sendNotification prepare failed: " . mysqli_error($conn)); return false; }
    mysqli_stmt_bind_param($s, "siisssis",
        $user_type, $user_id, $resident_id,
        $type, $title, $message,
        $reference_id, $reference_type);
    $ok = mysqli_stmt_execute($s);
    if (!$ok) cronLog("sendNotification execute failed: " . mysqli_stmt_error($s));
    mysqli_stmt_close($s);
    return $ok;
}

function cronGetComplaintCreator($conn, $complaint_id) {
    $s = mysqli_prepare($conn, "SELECT created_by FROM complaints WHERE id = ?");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $res = mysqli_stmt_get_result($s);
    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);
    mysqli_stmt_close($s);
    return $row ? (int)$row['created_by'] : null;
}

/* ---------------- Reminder helpers ---------------- */

/**
 * Send a reminder to every matched party on a complaint.
 */
function cronRemindParties($conn, $complaint_id, $title, $messageBody, &$notifiedIds = []) {
    $st = mysqli_prepare($conn,
        "SELECT DISTINCT matched_resident_id FROM complaint_parties
         WHERE complaint_id = ? AND matched_resident_id IS NOT NULL");
    mysqli_stmt_bind_param($st, "i", $complaint_id);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);

    $count = 0;
    while ($p = mysqli_fetch_assoc($res)) {
        $rid = (int)$p['matched_resident_id'];
        if (!$rid) continue;
        if (in_array($rid, $notifiedIds, true)) continue;   // avoid dupes in same run

        cronSendNotification($conn, 'resident', $rid, $complaint_id, $title, $messageBody, 'hearing');
        cronSafePushResident($conn, $rid, $title, $messageBody,
            '/BRITE/resident/dashboard.php');

        $notifiedIds[] = $rid;
        $count++;
    }
    mysqli_free_result($res);
    mysqli_stmt_close($st);
    return $count;
}

function cronRemindAdmin($conn, $admin_id, $complaint_id, $title, $messageBody, $link = '') {
    if (!$admin_id) return false;
    cronSendNotification($conn, 'admin', (int)$admin_id, $complaint_id, $title, $messageBody, 'hearing');
    if ($link) {
        cronSafePush($conn, (int)$admin_id, $title, $messageBody, $link);
    }
    return true;
}

function cronRemindCreator($conn, $complaint_id, $title, $messageBody, &$notifiedIds = []) {
    $creator = cronGetComplaintCreator($conn, $complaint_id);
    if (!$creator) return false;
    if (in_array($creator, $notifiedIds, true)) return false;   // already notified

    cronSendNotification($conn, 'resident', $creator, $complaint_id, $title, $messageBody, 'hearing');
    cronSafePushResident($conn, $creator, $title, $messageBody,
        '/BRITE/resident/dashboard.php');

    $notifiedIds[] = $creator;
    return true;
}

/**
 * Atomically claim a hearing for a reminder type.
 * Returns TRUE only if this process is the one that set the flag.
 */
function cronClaimHearingReminder($conn, $hearing_id, $flag_column) {
    $allowed = ['reminder_day_before_sent', 'reminder_same_day_sent',
                'reminder_1hr_sent', 'reminder_30min_sent'];
    if (!in_array($flag_column, $allowed, true)) return false;

    $sql = "UPDATE complaint_hearings
            SET $flag_column = 1
            WHERE id = ? AND $flag_column = 0";
    $s = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($s, "i", $hearing_id);
    mysqli_stmt_execute($s);
    $affected = mysqli_stmt_affected_rows($s);
    mysqli_stmt_close($s);
    return $affected === 1;
}