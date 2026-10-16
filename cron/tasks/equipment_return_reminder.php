<?php
// cron/tasks/equipment_return_reminder.php
// ============================================================
// TASK: Equipment past its end_datetime → flip 'borrowed'
//       to 'unreturned' and send a one-time notification.
//
//   • Only flips status 'borrowed' → 'unreturned'
//   • Only sends ONE notification (3-hour follow-ups are
//     handled by cron_task_return_reminder)
// ============================================================

function cron_task_equipment_return_reminder($conn) {
    $sent = 0;

    $overSql = "SELECT r.id AS request_id,
                       r.resident_id,
                       r.`reference`,
                       r.end_datetime,
                       er.equipment_name,
                       er.quantity,
                       DATEDIFF(NOW(), r.end_datetime) AS days_overdue
                FROM requests r
                JOIN equipment_requests er ON r.id = er.request_id
                WHERE r.request_type = 'equipment'
                  AND r.status = 'borrowed'
                  AND r.end_datetime < NOW()";

    $stmt = mysqli_prepare($conn, $overSql);
    if (!$stmt) {
        cronLog("equipment_return_reminder: PREPARE FAILED — " . mysqli_error($conn));
        return $sent;
    }

    mysqli_stmt_execute($stmt);
    $res  = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    mysqli_free_result($res);
    mysqli_stmt_close($stmt);

    foreach ($rows as $row) {
        $requestId  = (int)$row['request_id'];
        $residentId = (int)$row['resident_id'];
        $days       = (int)$row['days_overdue'];

        // Atomic flip: 'borrowed' → 'unreturned'
        $upd = mysqli_prepare($conn,
            "UPDATE requests
                SET status = 'unreturned'
              WHERE id = ? AND status = 'borrowed'");
        mysqli_stmt_bind_param($upd, "i", $requestId);
        mysqli_stmt_execute($upd);
        $affected = mysqli_stmt_affected_rows($upd);
        mysqli_stmt_close($upd);

        if ($affected > 0) {
            $title = "⏰ Equipment Not Returned";
            $msg   = "Your borrowed {$row['equipment_name']} ({$row['quantity']} item(s)) "
                   . "was due on " . date('M j, Y g:i A', strtotime($row['end_datetime']))
                   . " and is now {$days} day(s) overdue. Please return it to the Barangay Hall immediately.";

            cronSendNotification(
                $conn, 'resident', $residentId, null,
                $title, $msg, 'equipment_unreturned',
                null,
                $requestId, 'equipment_request'
            );

            cronSafePushResident(
                $conn, $residentId,
                $title, $msg,
                '/BRITE/resident/dashboard.php'
            );

            $sent++;
            cronLog("equipment_return_reminder: marked request=$requestId unreturned (resident=$residentId, overdue={$days}d)");
        }
    }

    return $sent;
}