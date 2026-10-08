<?php
// cron/tasks/return_reminder.php
// ============================================================
// TASK: Remind residents about equipment due soon / overdue.
//
//   • Due within 3 days     → daily reminder
//   • Overdue               → daily reminder
//
// Guards:
//   - notifications table same-day check (per request per type)
// ============================================================

function cron_task_return_reminder($conn) {
    $sent = 0;

    // ------------------------------------------------------------
    // 1) DUE WITHIN 3 DAYS
    // ------------------------------------------------------------
    $dueSql = "SELECT r.id AS request_id,
                      r.resident_id,
                      r.`reference`,
                      r.end_datetime,
                      er.equipment_name,
                      er.quantity,
                      DATEDIFF(r.end_datetime, NOW()) AS days_until_due
               FROM requests r
               JOIN equipment_requests er ON r.id = er.request_id
               WHERE r.status IN ('approved', 'borrowed')
                 AND r.request_type = 'equipment'
                 AND r.end_datetime >= NOW()
                 AND DATEDIFF(r.end_datetime, NOW()) BETWEEN 0 AND 3";

    $dueStmt = mysqli_prepare($conn, $dueSql);

    if (!$dueStmt) {
        cronLog("return_reminder: PREPARE (due) FAILED — " . mysqli_error($conn));
        cronLog("return_reminder: SQL was:\n" . $dueSql);
    } else {
        mysqli_stmt_execute($dueStmt);
        $dueRes = mysqli_stmt_get_result($dueStmt);

        $dueRows = [];
        while ($row = mysqli_fetch_assoc($dueRes)) $dueRows[] = $row;
        mysqli_free_result($dueRes);
        mysqli_stmt_close($dueStmt);

        foreach ($dueRows as $row) {
            $residentId = (int)$row['resident_id'];
            $requestId  = (int)$row['request_id'];
            $days       = (int)$row['days_until_due'];

            if (cronWasNotificationSentToday($conn, $residentId, 'return_reminder', $requestId)) {
                continue;
            }

            if ($days === 0) {
                $title = "⚠️ Return Due Today!";
                $msg   = "Your borrowed {$row['equipment_name']} ({$row['quantity']} item(s)) is due TODAY. Please return it before the end of the day.";
            } elseif ($days === 1) {
                $title = "📅 Return Reminder — Due Tomorrow";
                $msg   = "Your borrowed {$row['equipment_name']} ({$row['quantity']} item(s)) is due tomorrow.";
            } else {
                $title = "📅 Return Reminder — {$days} Days Left";
                $msg   = "Your borrowed {$row['equipment_name']} ({$row['quantity']} item(s)) is due in {$days} days.";
            }

            cronSendNotification(
                $conn, 'resident', $residentId, null,
                $title, $msg, 'return_reminder'
            );

            cronSafePushResident(
                $conn, $residentId,
                $title, $msg,
                '/BRITE/resident/dashboard.php'
            );

            $sent++;
            cronLog("return_reminder: sent to resident=$residentId for request=$requestId (days=$days)");
        }
    }

    // ------------------------------------------------------------
// 2) OVERDUE - Send every 3 hours, even if notification was deleted
// ------------------------------------------------------------
$overSql = "SELECT r.id AS request_id,
                   r.resident_id,
                   r.`reference`,
                   r.end_datetime,
                   r.last_overdue_notification_at,
                   er.equipment_name,
                   er.quantity,
                   DATEDIFF(NOW(), r.end_datetime) AS days_overdue
            FROM requests r
            JOIN equipment_requests er ON r.id = er.request_id
            WHERE r.status IN ('approved', 'borrowed')
              AND r.request_type = 'equipment'
              AND r.end_datetime < NOW()";

$overStmt = mysqli_prepare($conn, $overSql);

if (!$overStmt) {
    cronLog("return_reminder: PREPARE (overdue) FAILED — " . mysqli_error($conn));
    cronLog("return_reminder: SQL was:\n" . $overSql);
} else {
    mysqli_stmt_execute($overStmt);
    $overRes = mysqli_stmt_get_result($overStmt);

    $overRows = [];
    while ($row = mysqli_fetch_assoc($overRes)) $overRows[] = $row;
    mysqli_free_result($overRes);
    mysqli_stmt_close($overStmt);

    foreach ($overRows as $row) {
        $residentId = (int)$row['resident_id'];
        $requestId  = (int)$row['request_id'];
        $days       = (int)$row['days_overdue'];

        // ------------------------------------------------------------
        // 3-HOUR GUARD: Check last_overdue_notification_at timestamp
        // This persists even if the notification was deleted.
        // ------------------------------------------------------------
        $lastSent = $row['last_overdue_notification_at'];

        if ($lastSent !== null) {
            $hoursSinceLast = (time() - strtotime($lastSent)) / 3600;
            if ($hoursSinceLast < 3) {
                // Less than 3 hours since last overdue notification — skip
                cronLog("equipment_overdue: SKIPPED resident=$residentId request=$requestId (last sent " . round($hoursSinceLast, 1) . "h ago)");
                continue;
            }
        }

        $title = "🚨 Equipment Overdue — Action Required!";
        $msg   = "Your borrowed {$row['equipment_name']} ({$row['quantity']} item(s)) is {$days} day(s) overdue. Please return it immediately.";

        cronSendNotification(
            $conn, 'resident', $residentId, null,
            $title, $msg, 'equipment_overdue'
        );

        cronSafePushResident(
            $conn, $residentId,
            $title, $msg,
            '/BRITE/resident/dashboard.php'
        );

        // ------------------------------------------------------------
        // Update the tracking timestamp — this is the key part.
        // Even if the notification row is deleted, this timestamp
        // remains and prevents re-sending for 3 hours.
        // ------------------------------------------------------------
        $upd = mysqli_prepare($conn,
            "UPDATE requests SET last_overdue_notification_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($upd, "i", $requestId);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);

        $sent++;
        cronLog("equipment_overdue: sent to resident=$residentId for request=$requestId (overdue=$days)");
    }
}

    return $sent;
}

/**
 * Guard helper — same-day notification check.
 */
function cronWasNotificationSentToday($conn, $residentId, $type, $requestId) {
    $sql = "SELECT COUNT(*) AS c FROM notifications
            WHERE resident_id = ?
              AND type = ?
              AND reference_id = ?
              AND DATE(created_at) = CURDATE()";
    $s = mysqli_prepare($conn, $sql);
    if (!$s) {
        cronLog("cronWasNotificationSentToday prepare failed: " . mysqli_error($conn));
        return false;
    }
    mysqli_stmt_bind_param($s, "isi", $residentId, $type, $requestId);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    return ((int)($row['c'] ?? 0)) > 0;
}