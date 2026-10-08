<?php
// cron/tasks/pickup_reminder.php
// ============================================================
// TASK: On pickup day, remind residents to claim their document.
// Fires once per request per day (guarded by notifications table).
// ============================================================

function cron_task_pickup_reminder($conn) {
    $sent  = 0;
    $today = date('Y-m-d');

    $sql = "SELECT r.id AS request_id, r.resident_id, r.document_type,
                   r.pickup_date, r.pickup_time
            FROM document_requests r
            WHERE r.status = 'approved'
              AND r.claimed_at IS NULL
              AND r.pickup_date = ?";
    $s = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($s, "s", $today);
    mysqli_stmt_execute($s);
    $res = mysqli_stmt_get_result($s);

    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    mysqli_free_result($res);
    mysqli_stmt_close($s);

    foreach ($rows as $row) {
        $residentId = (int)$row['resident_id'];
        $requestId  = (int)$row['request_id'];

        // Guard: already sent document_pickup_today for this request today?
        if (cronWasNotificationSentToday($conn, $residentId, 'document_pickup_today', $requestId)) {
            continue;
        }

        $timeDisplay = !empty($row['pickup_time'])
            ? ' at ' . date('g:i A', strtotime($row['pickup_time']))
            : '';

        $title = "📄 Document Pickup Today!";
        $msg   = "Reminder: Your {$row['document_type']} is scheduled for pickup today{$timeDisplay}. "
               . "You may claim it anytime during office hours (Mon–Fri, 8:00 AM – 5:00 PM). "
               . "Bring a valid ID and your QR code.";

        cronSendNotification(
            $conn, 'resident', $residentId, null,
            $title, $msg, 'document_pickup_today'
        );

        cronSafePushResident(
            $conn, $residentId,
            $title, $msg,
            '/BRITE/resident/dashboard.php'
        );

        $sent++;
        cronLog("pickup_reminder: sent to resident=$residentId for request=$requestId");
    }

    return $sent;
}