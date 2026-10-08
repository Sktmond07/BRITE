<?php
// cron/tasks/same_day_reminder.php
// ============================================================
// TASK: On the day of a hearing, remind the Captain, all parties,
// and the complaint creator that the hearing is today.
// Fires once per hearing (guarded by reminder_same_day_sent).
// ============================================================

function cron_task_same_day_reminder($conn) {
    $today = date('Y-m-d');

    $sql = "SELECT h.id AS hearing_id, h.complaint_id, h.hearing_date, h.hearing_time,
                   h.location, h.conducted_by, c.reference_number
            FROM complaint_hearings h
            JOIN complaints c ON h.complaint_id = c.id
            WHERE h.status = 'scheduled'
              AND h.hearing_date = ?
              AND h.reminder_same_day_sent = 0";
    $s = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($s, "s", $today);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $rows = [];
    while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    mysqli_free_result($r);
    mysqli_stmt_close($s);

    $sent = 0;
    foreach ($rows as $h) {
        // Atomically claim. If someone else already claimed it, skip.
        if (!cronClaimHearingReminder($conn, $h['hearing_id'], 'reminder_same_day_sent')) {
            continue;
        }
$niceTime = date('g:i A', strtotime($h['hearing_time']));

// Shared list to track who has already been notified
$notifiedThisHearing = [];

cronRemindParties(
    $conn, $h['complaint_id'],
    'Hearing TODAY',
    "Reminder: Your mediation hearing for complaint #{$h['reference_number']} is TODAY at {$niceTime}, {$h['location']}. Please be on time.",
    $notifiedThisHearing
);

if ($h['conducted_by']) {
    cronRemindAdmin(
        $conn, $h['conducted_by'], $h['complaint_id'],
        'Hearing TODAY',
        "Mediation for #{$h['reference_number']} is TODAY at {$niceTime}, {$h['location']}.",
        '/BRITE/admin/dashboards/captain_dashboard.php'
    );
}

// Creator — will skip if already in $notifiedThisHearing (as complainant/respondent)
cronRemindCreator(
    $conn, $h['complaint_id'],
    'Hearing TODAY',
    "Your complaint #{$h['reference_number']} has a hearing TODAY at {$niceTime}, {$h['location']}.",
    $notifiedThisHearing
);

        $sent++;
    }

    return $sent;
}