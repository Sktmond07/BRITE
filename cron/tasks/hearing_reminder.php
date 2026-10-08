<?php
// cron/tasks/hearing_reminder.php
// ============================================================
// TASK: On the day of a complaint hearing, notify the resident
//       who filed the complaint + all matched parties.
// Uses the claim-flag pattern (reminder_same_day_sent).
// ============================================================

function cron_task_hearing_reminder($conn) {
    $today = date('Y-m-d');

    $sql = "SELECT h.id AS hearing_id, h.complaint_id, h.hearing_date,
                   h.hearing_time, h.location, h.conducted_by,
                   c.reference_number, c.title
            FROM complaint_hearings h
            JOIN complaints c ON h.complaint_id = c.id
            WHERE h.status = 'scheduled'
              AND h.hearing_date = ?
              AND h.reminder_same_day_sent = 0";
    $s = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($s, "s", $today);
    mysqli_stmt_execute($s);
    $res = mysqli_stmt_get_result($s);

    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    mysqli_free_result($res);
    mysqli_stmt_close($s);

    $sent = 0;
    foreach ($rows as $h) {
        if (!cronClaimHearingReminder($conn, $h['hearing_id'], 'reminder_same_day_sent')) {
            continue;
        }

        $niceTime = date('g:i A', strtotime($h['hearing_time']));

        $notified = [];

        // 1) All matched parties on the complaint
        cronRemindParties(
            $conn, $h['complaint_id'],
            'Hearing TODAY',
            "Reminder: Your mediation hearing for complaint #{$h['reference_number']} is TODAY at {$niceTime}, {$h['location']}. Please be on time.",
            $notified
        );

        // 2) Creator (skip if already notified as party)
        cronRemindCreator(
            $conn, $h['complaint_id'],
            'Hearing TODAY',
            "Your complaint #{$h['reference_number']} has a hearing TODAY at {$niceTime}, {$h['location']}.",
            $notified
        );

        // 3) Captain (admin) — informational
        if (!empty($h['conducted_by'])) {
            cronRemindAdmin(
                $conn, (int)$h['conducted_by'], $h['complaint_id'],
                'Hearing TODAY',
                "Mediation for #{$h['reference_number']} is TODAY at {$niceTime}, {$h['location']}.",
                '/BRITE/admin/dashboards/captain_dashboard.php'
            );
        }

        $sent++;
        cronLog("hearing_reminder: processed hearing_id={$h['hearing_id']} for #{$h['reference_number']}");
    }

    return $sent;
}