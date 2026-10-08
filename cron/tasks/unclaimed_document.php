<?php
// cron/tasks/unclaimed_document.php
// ============================================================
// TASK: Auto-convert approved → unclaimed once pickup_date
//       has passed by 1+ day. Notifies the resident once.
// ============================================================

function cron_task_unclaimed_document($conn) {
    $sent = 0;

    $sql = "SELECT id, resident_id, document_type, pickup_date
            FROM document_requests
            WHERE status = 'approved'
              AND claimed_at IS NULL
              AND pickup_date IS NOT NULL
              AND pickup_date < DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
    $s = mysqli_prepare($conn, $sql);
    mysqli_stmt_execute($s);
    $res = mysqli_stmt_get_result($s);

    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    mysqli_free_result($res);
    mysqli_stmt_close($s);

    foreach ($rows as $row) {
        $requestId  = (int)$row['id'];
        $residentId = (int)$row['resident_id'];

        // Atomic flip
        $upd = mysqli_prepare($conn,
            "UPDATE document_requests
             SET status = 'unclaimed'
             WHERE id = ? AND status = 'approved' AND claimed_at IS NULL");
        mysqli_stmt_bind_param($upd, "i", $requestId);
        mysqli_stmt_execute($upd);
        $affected = mysqli_stmt_affected_rows($upd);
        mysqli_stmt_close($upd);

        if ($affected <= 0) continue;

        $title = "📭 Document Not Claimed";
        $msg   = "Your {$row['document_type']} was scheduled for pickup on "
               . date('M d, Y', strtotime($row['pickup_date']))
               . " but was not claimed. Please visit the Barangay Hall to claim it.";

        cronSendNotification(
            $conn, 'resident', $residentId, null,
            $title, $msg, 'document_unclaimed'
        );

        cronSafePushResident(
            $conn, $residentId,
            $title, $msg,
            '/BRITE/resident/dashboard.php'
        );

        $sent++;
        cronLog("unclaimed_document: marked request=$requestId unclaimed for resident=$residentId");
    }

    return $sent;
}