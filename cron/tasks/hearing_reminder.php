<?php
// cron/tasks/hearing_reminder.php
// ============================================================
// TASK: Hearing reminders + 5-day explain-window enforcement
//       + 15-day mediation deadline enforcement
//
// NOTE: cronRemindAdmin() and cronRemindParties()/cronRemindCreator()
//       are defined in cron_bootstrap.php — DO NOT redefine them here.
// ============================================================

function cron_task_hearing_reminder($conn) {
    $sent = 0;
    $sent += cron_hearing_reminders_today($conn);
    $sent += cron_explain_window_warning($conn);
    $sent += cron_explain_window_expired($conn);
    $sent += cron_mediation_deadline_expired($conn);
    $sent += cron_repudiation_window_expired($conn);   // ← NEW
    return $sent;
}


/* ============================================================
   1. Same-day hearing reminders
   ============================================================ */
function cron_hearing_reminders_today($conn) {
    $today = date('Y-m-d');

    $sql = "SELECT h.id AS hearing_id, h.complaint_id, h.session_type, h.session_number,
                   h.hearing_date, h.hearing_time, h.location, h.conducted_by,
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
        $isConcil = ($h['session_type'] === 'conciliation');
        $niceLabel = $isConcil
            ? "Conciliation Hearing #{$h['session_number']}"
            : "Mediation Hearing #{$h['session_number']}";

        cronNotifyPartiesSameDay($conn, $h['complaint_id'], $niceLabel, $niceTime, $h['location'], $h['reference_number'], $h['hearing_id']);

        if (!empty($h['conducted_by'])) {
            cronRemindAdmin($conn, (int)$h['conducted_by'], $h['complaint_id'],
                "Hearing Today - {$niceLabel}",
                "Complaint #{$h['reference_number']} - {$niceLabel} is scheduled today at {$niceTime}, {$h['location']}.",
                $isConcil ? '/BRITE/admin/dashboards/lupon_dashboard.php' : '/BRITE/admin/dashboards/captain_dashboard.php');
        }

        if ($isConcil) {
            cronRemindPangkatMembers($conn, $h['complaint_id'],
                'Conciliation Hearing Today',
                "Complaint #{$h['reference_number']} - hearing is scheduled today at {$niceTime}, {$h['location']}.",
                $h['hearing_id']);
        }

        $sent++;
    }

    return $sent;
}

function cronNotifyPartiesSameDay($conn, $complaint_id, $label, $time, $location, $ref, $hearing_id) {
    // ✅ NEW: fetch the stored KP PDF paths for this hearing
    $kp8  = null; $kp9  = null;
    $kp12c = null; $kp12r = null;

    if ($hearing_id) {
        $hs = mysqli_prepare($conn,
            "SELECT kp8_complainant_pdf_path, kp9_respondent_pdf_path,
                    kp12_complainant_pdf_path, kp12_respondent_pdf_path
               FROM complaint_hearings WHERE id = ?");
        mysqli_stmt_bind_param($hs, "i", $hearing_id);
        mysqli_stmt_execute($hs);
        $hrow = mysqli_fetch_assoc(mysqli_stmt_get_result($hs));
        mysqli_stmt_close($hs);
        if ($hrow) {
            $kp8   = $hrow['kp8_complainant_pdf_path']  ?? null;
            $kp9   = $hrow['kp9_respondent_pdf_path']   ?? null;
            $kp12c = $hrow['kp12_complainant_pdf_path'] ?? null;
            $kp12r = $hrow['kp12_respondent_pdf_path']  ?? null;
        }
    }

    $q = mysqli_prepare($conn,
        "SELECT p.id, p.full_name, p.party_type, p.matched_resident_id
           FROM complaint_parties p
          WHERE p.complaint_id = ?
            AND p.party_type IN ('complainant','respondent')");
    mysqli_stmt_bind_param($q, "i", $complaint_id);
    mysqli_stmt_execute($q);
    $r = mysqli_stmt_get_result($q);

    $printNeeded = [];
    $fallbackPdf = null; // used only for the secretary print notification
    while ($p = mysqli_fetch_assoc($r)) {
        $rid = (int)($p['matched_resident_id'] ?? 0);
        $roleLabel = ($p['party_type'] === 'complainant') ? 'Complainant' : 'Respondent';

        // ✅ choose the correct KP PDF per party
        if ($p['party_type'] === 'complainant') {
            $pdf = $kp12c ?: $kp8;
        } else {
            $pdf = $kp12r ?: $kp9;
        }
        if ($pdf && !$fallbackPdf) $fallbackPdf = $pdf;

        if ($rid) {
            $title = "Your {$roleLabel} Hearing is Today";
            $msg   = "Reminder: As the {$roleLabel}, you must appear at the {$label} for complaint #{$ref} "
                   . "today at {$time}, {$location}. Please be on time.";
            cronSendNotification($conn, 'resident', $rid, $complaint_id, $title, $msg, 'hearing', $pdf);
            cronSafePushResident($conn, $rid, $title, $msg, '/BRITE/resident/dashboard.php', $pdf);
        } else {
            $printNeeded[] = $roleLabel . ' ' . $p['full_name'];
        }
    }
    mysqli_stmt_close($q);

    if (!empty($printNeeded)) {
        cronNotifySecretaryPrint($conn, $complaint_id,
            'Print Required - Hearing Reminder',
            "Complaint #{$ref} - {$label} is scheduled today at {$time}, {$location}.\n\n"
            . "The following part" . (count($printNeeded) > 1 ? 'ies have' : 'y has') . " no resident account. "
            . "Please print and hand-deliver the hearing reminder:\n- " . implode("\n- ", $printNeeded),
            $hearing_id,
            $fallbackPdf);   // ✅ NEW
    }
}

/* ============================================================
   2. 1-day-before warning for the 5-day explain window
   ============================================================ */
function cron_explain_window_warning($conn) {
    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    $s = mysqli_prepare($conn,
        "SELECT h.id AS hearing_id, h.complaint_id, h.session_type,
                h.explain_window_deadline,
                c.reference_number, c.status AS complaint_status
           FROM complaint_hearings h
           JOIN complaints c ON c.id = h.complaint_id
          WHERE h.explain_window_deadline IS NOT NULL
            AND h.explain_window_resolved_at IS NULL
            AND DATE(h.explain_window_deadline) = ?
            AND c.status IN ('for_mediation','mediation_scheduled','pangkat_scheduled')");
    mysqli_stmt_bind_param($s, "s", $tomorrow);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $rows = [];
    while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    mysqli_stmt_close($s);

    $sent = 0;
    foreach ($rows as $c) {
        $cid = (int)$c['complaint_id'];
        $hearing_id = (int)$c['hearing_id'];
        $isConcil = ($c['session_type'] === 'conciliation');
        $niceDeadline = date('F j, Y g:i A', strtotime($c['explain_window_deadline']));

        $aq = mysqli_prepare($conn,
            "SELECT p.id, p.full_name, p.party_type, p.matched_resident_id,
                    a.kp18_or_19_pdf_path
               FROM complaint_parties p
               JOIN complaint_attendance a ON a.party_id = p.id
              WHERE a.complaint_id = ? AND a.hearing_id = ?
                AND a.status = 'no_show'
                AND (a.justification_status IS NULL OR a.justification_status = '')");
        mysqli_stmt_bind_param($aq, "ii", $cid, $hearing_id);
        mysqli_stmt_execute($aq);
        $ar = mysqli_stmt_get_result($aq);
        $absent = [];
        while ($row = mysqli_fetch_assoc($ar)) $absent[] = $row;
        mysqli_stmt_close($aq);
        if (!$absent) continue;

        $present = cronGetPresentParties($conn, $cid, $hearing_id);
        $absentNames = array_map(fn($p) => $p['full_name'] . ' (' . $p['party_type'] . ')', $absent);

        $absentPrintList = [];
        foreach ($absent as $p) {
            $rid = (int)($p['matched_resident_id'] ?? 0);
            if (!$rid) $rid = (int)(cronTryMatchResident($conn, $p['id']) ?? 0);
            $roleLabel = ($p['party_type'] === 'complainant') ? 'Complainant' : 'Respondent';

            $kpHearingPath = $p['kp18_or_19_pdf_path'] ?? null;

            if ($rid) {
                $title = "Final Warning - Explain Your Absence ({$roleLabel})";
                $msg   = "As the {$roleLabel}, you have until {$niceDeadline} to explain your absence "
                       . "for complaint #{$c['reference_number']} to the "
                       . ($isConcil ? 'Pangkat Chairperson' : 'Punong Barangay') . ". "
                       . "If you do not explain, your absence will be declared unjustified.";
                cronSendNotification($conn, 'resident', $rid, $cid, $title, $msg, 'warning', $kpHearingPath);
                cronSafePushResident($conn, $rid, $title, $msg, '/BRITE/resident/dashboard.php', $kpHearingPath);
            } else {
                $absentPrintList[] = $roleLabel . ' ' . $p['full_name'];
            }
        }

        $presentPrintList = [];
        foreach ($present as $p) {
            $rid = (int)($p['matched_resident_id'] ?? 0);
            if (!$rid) $rid = (int)(cronTryMatchResident($conn, $p['id']) ?? 0);
            $roleLabel = ($p['party_type'] === 'complainant') ? 'Complainant' : 'Respondent';
            $absentList = implode(', ', array_map(fn($a) => $a['full_name'], $absent));

            if ($rid) {
                $title = "Update - The Other Party Has 1 Day Left to Explain";
                $msg   = "As the {$roleLabel}, you are informed that the following did not appear: {$absentList}. "
                       . "They have until {$niceDeadline} to explain.";
                cronSendNotification($conn, 'resident', $rid, $cid, $title, $msg, 'info');
                cronSafePushResident($conn, $rid, $title, $msg, '/BRITE/resident/dashboard.php');
            } else {
                $presentPrintList[] = $roleLabel . ' ' . $p['full_name'];
            }
        }

        if (!empty($absentPrintList)) {
            cronNotifySecretaryPrint($conn, $cid,
                'Print Required - Final Warning Notice',
                "Complaint #{$c['reference_number']} - the following absent part"
                . (count($absentPrintList) > 1 ? 'ies have' : 'y has') . " no resident account.\n\n"
                . "Please print and hand-deliver the final warning by {$niceDeadline}:\n- "
                . implode("\n- ", $absentPrintList),
                $hearing_id);
        }

        if (!empty($presentPrintList)) {
            cronNotifySecretaryPrint($conn, $cid,
                'Print Required - Case Update Notice',
                "Complaint #{$c['reference_number']} - the following present part"
                . (count($presentPrintList) > 1 ? 'ies have' : 'y has') . " no resident account.\n\n"
                . "Please print and hand-deliver the case update:\n- "
                . implode("\n- ", $presentPrintList),
                $hearing_id);
        }

        $captainId = cronGetComplaintCaptain($conn, $cid);
        if ($captainId) {
            cronRemindAdmin($conn, $captainId, $cid,
                '5-Day Explain Window Ends Tomorrow',
                "Complaint #{$c['reference_number']} - " . count($absent) . " absent part(ies) have 1 day left to explain. "
                . "Absent: " . implode(', ', $absentNames),
                '/BRITE/admin/dashboards/captain_dashboard.php');
        }

        if ($isConcil) {
            cronRemindPangkatMembers($conn, $cid,
                '5-Day Explain Window Closing Tomorrow',
                "Complaint #{$c['reference_number']} - " . count($absent) . " absent part(ies) have 1 day left to explain.",
                $hearing_id);
        }

        $sent++;
        cronLog("explain_warning: case=#{$c['reference_number']} absent=" . count($absent)
              . " present=" . count($present) . " print_absent=" . count($absentPrintList)
              . " print_present=" . count($presentPrintList));
    }

    return $sent;
}

/* ============================================================
   Resident matcher — cron context

   Given a party row ID (from complaint_parties), tries to find
   the matching resident account. Mirrors tryMatchResident() in
   admin_complaint_ajax.php but is prefixed with cron_ so it
   doesn't collide with the admin-side helper.
   ============================================================ */
function cronTryMatchResident($conn, $party_id) {
    if (!$party_id) return null;

    // 1. Fetch the party row
    $s = mysqli_prepare($conn,
        "SELECT id, full_name, contact_number, matched_resident_id
           FROM complaint_parties
          WHERE id = ? LIMIT 1");
    if (!$s) return null;
    mysqli_stmt_bind_param($s, "i", $party_id);
    mysqli_stmt_execute($s);
    $party = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);

    if (!$party) return null;

    // 2. Already matched → return it
    $matched = (int)($party['matched_resident_id'] ?? 0);
    if ($matched > 0) return $matched;

    // 3. Match by phone (resident.phone OR resident.phone_number)
    if (!empty($party['contact_number'])) {
        $ms = mysqli_prepare($conn,
            "SELECT id FROM resident
              WHERE (phone = ? OR phone_number = ?)
                AND is_active = 1
              LIMIT 1");
        if ($ms) {
            mysqli_stmt_bind_param($ms, "ss",
                $party['contact_number'], $party['contact_number']);
            mysqli_stmt_execute($ms);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($ms));
            mysqli_stmt_close($ms);
            if (!empty($row['id'])) return (int)$row['id'];
        }
    }

    // 4. Match by first/last name
    if (!empty($party['full_name'])) {
        $parts = preg_split('/\s+/', trim($party['full_name']));
        if (count($parts) >= 2) {
            $first = $parts[0];
            $last  = end($parts);

            $ms = mysqli_prepare($conn,
                "SELECT id FROM resident
                  WHERE LOWER(first_name) = LOWER(?)
                    AND LOWER(last_name)  = LOWER(?)
                    AND is_active = 1
                  LIMIT 1");
            if ($ms) {
                mysqli_stmt_bind_param($ms, "ss", $first, $last);
                mysqli_stmt_execute($ms);
                $row = mysqli_fetch_assoc(mysqli_stmt_get_result($ms));
                mysqli_stmt_close($ms);
                if (!empty($row['id'])) return (int)$row['id'];
            }
        }
    }

    return null;
}


/* ============================================================
   5. Repudiation window EXPIRED
   ------------------------------------------------------------
   When the 10-day (KP #16) or 5-day (KP #14) repudiation window
   lapses without a filed repudiation, the agreement becomes
   FINAL and the case advances. This also makes the "File
   Repudiation" button disappear on the resident side, because
   complaint_ajax.php's get_repudiation_state() only returns
   window_open = true while status is settled_pending /
   arbitration_pending.
   ============================================================ */
function cron_repudiation_window_expired($conn) {
    $now = date('Y-m-d H:i:s');
    $sent = 0;

    $s = mysqli_prepare($conn,
        "SELECT id, reference_number, status,
                repudiation_deadline,
                kp16_pdf_path, kp14_pdf_path,
                amicable_repudiated, arbitration_repudiated,
                assigned_to
           FROM complaints
          WHERE repudiation_deadline IS NOT NULL
            AND repudiation_deadline <= ?
            AND status IN ('settled_pending','arbitration_pending')
            AND (amicable_repudiated = 0 OR arbitration_repudiated = 0)");
    mysqli_stmt_bind_param($s, "s", $now);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $rows = [];
    while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    mysqli_stmt_close($s);

    if (!$rows) return 0;

    foreach ($rows as $c) {
        $cid      = (int)$c['id'];
        $ref      = $c['reference_number'];
        $status   = $c['status'];
        $isSettle = ($status === 'settled_pending');

        // ── Did the other party already file? Then just close quietly ──
        $alreadyFiled = $isSettle
            ? (int)$c['amicable_repudiated'] === 1
            : (int)$c['arbitration_repudiated'] === 1;

        if ($alreadyFiled) {
            // Nothing to do — the file_repudiation handler already
            // flipped the status when the repudiation was filed.
            continue;
        }

        // ── Advance the case ──
        if ($isSettle) {
            // KP #16 → settlement becomes final and executory
            $newStatus = 'settled';

            $u = mysqli_prepare($conn,
                "UPDATE complaints
                    SET status = 'settled',
                        is_executory = 1,
                        repudiation_deadline = NULL,
                        repudiation_window_closed_at = NOW()
                  WHERE id = ? AND status = 'settled_pending'");
            mysqli_stmt_bind_param($u, "i", $cid);
            mysqli_stmt_execute($u);
            $changed = mysqli_stmt_affected_rows($u);
            mysqli_stmt_close($u);
            if ($changed < 1) continue;

            $logNote = "Repudiation window (10 days) expired with no filing. "
                     . "Amicable Settlement (KP #16) is now FINAL and EXECUTORY.";
        } else {
            // KP #14 → arbitration agreement becomes final; case moves
            // to arbitration award stage
            $newStatus = 'arbitration_final';

            $u = mysqli_prepare($conn,
                "UPDATE complaints
                    SET status = 'arbitration_final',
                        repudiation_deadline = NULL,
                        repudiation_window_closed_at = NOW()
                  WHERE id = ? AND status = 'arbitration_pending'");
            mysqli_stmt_bind_param($u, "i", $cid);
            mysqli_stmt_execute($u);
            $changed = mysqli_stmt_affected_rows($u);
            mysqli_stmt_close($u);
            if ($changed < 1) continue;

            $logNote = "Repudiation window (5 days) expired with no filing. "
                     . "Agreement for Arbitration (KP #14) is now FINAL. "
                     . "The Punong Barangay must render the Arbitration Award (KP #15).";
        }

        // ── Timeline ──
        cronLogUpdate($conn, $cid, 'status_change', $status, $newStatus, $logNote);

        // ── Notify the resident parties ──
        $notifyTitle = $isSettle
            ? 'Settlement is Now Final (KP #16)'
            : 'Arbitration Agreement is Now Final (KP #14)';
        $notifyMsg = $isSettle
            ? "The 10-day repudiation window for complaint #{$ref} has closed. "
            . "The Amicable Settlement is now final and executory. "
            . "Either party may move for execution (KP #25) if the other party fails to comply."
            : "The 5-day repudiation window for complaint #{$ref} has closed. "
            . "The Agreement for Arbitration is now final. "
            . "The Punong Barangay will issue the Arbitration Award (KP #15).";

        // Populate the PDF that the notification carries
        $docPath = $isSettle ? $c['kp16_pdf_path'] : $c['kp14_pdf_path'];

        $q = mysqli_prepare($conn,
            "SELECT id, full_name, party_type, matched_resident_id
               FROM complaint_parties
              WHERE complaint_id = ?
                AND party_type IN ('complainant','respondent')");
        mysqli_stmt_bind_param($q, "i", $cid);
        mysqli_stmt_execute($q);
        $pr = mysqli_stmt_get_result($q);
        $printList = [];
        while ($p = mysqli_fetch_assoc($pr)) {
            $rid = (int)($p['matched_resident_id'] ?? 0);
            if (!$rid) $rid = (int)(cronTryMatchResident($conn, $p['id']) ?? 0);
            $roleLabel = ($p['party_type'] === 'complainant') ? 'Complainant' : 'Respondent';

            if ($rid) {
                cronSendNotification($conn, 'resident', $rid, $cid,
                    $notifyTitle, $notifyMsg, 'info', $docPath);
                cronSafePushResident($conn, $rid, $notifyTitle, $notifyMsg,
                    '/BRITE/resident/dashboard.php', $docPath);
            } else {
                $printList[] = $roleLabel . ' ' . $p['full_name'];
            }
        }
        mysqli_stmt_close($q);

        // ── Notify Captain + Secretary ──
        $captainId = !empty($c['assigned_to'])
            ? (int)$c['assigned_to']
            : cronGetComplaintCaptain($conn, $cid);

        $adminTitle = $isSettle
            ? 'Settlement Final — Executory'
            : 'Arbitration Final — Award Due';
        $adminMsg = $isSettle
            ? "Complaint #{$ref} — 10-day repudiation window closed with no filing. "
            . "KP #16 is final and executory."
            : "Complaint #{$ref} — 5-day repudiation window closed with no filing. "
            . "KP #14 is final. Render the Arbitration Award (KP #15).";

        if ($captainId) {
            cronRemindAdmin($conn, $captainId, $cid,
                $adminTitle, $adminMsg,
                '/BRITE/admin/dashboards/captain_dashboard.php',
                $docPath);
        }

        $secMsg = $adminMsg . "\n\n";
        if (!empty($printList)) {
            $secMsg .= "Print & hand-deliver (no resident account):\n- "
                     . implode("\n- ", $printList) . "\n";
        } else {
            $secMsg .= "All parties have resident accounts. PDF is saved on disk for the record.\n";
        }
        cronNotifySecretaryPrint($conn, $cid,
            $isSettle ? 'KP #16 Now Final — No Repudiation Filed'
                      : 'KP #14 Now Final — No Repudiation Filed',
            $secMsg, 0, $docPath);

        cronLog("repudiation_window_expired: case=#{$ref} "
              . "{$status} -> {$newStatus} print_needed=" . count($printList));
        $sent++;
    }

    return $sent;
}

/* ============================================================
   3. 5-day explain window EXPIRED -> auto-unjustified
   Respondent no-show -> ALWAYS generates KP #24
   Complainant no-show -> ALWAYS generates KP #23
   ============================================================ */
function cron_explain_window_expired($conn) {
    $now = date('Y-m-d H:i:s');

    $s = mysqli_prepare($conn,
        "SELECT h.id AS hearing_id, h.complaint_id, h.session_type,
                h.explain_window_deadline,
                c.reference_number, c.status AS complaint_status
           FROM complaint_hearings h
           JOIN complaints c ON c.id = h.complaint_id
          WHERE h.explain_window_deadline IS NOT NULL
            AND h.explain_window_resolved_at IS NULL
            AND h.explain_window_deadline <= ?
            AND c.status IN ('for_mediation','mediation_scheduled','pangkat_scheduled')");
    mysqli_stmt_bind_param($s, "s", $now);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $rows = [];
    while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    mysqli_stmt_close($s);

    $sent = 0;
    foreach ($rows as $c) {
        $cid = (int)$c['complaint_id'];
        $hearing_id = (int)$c['hearing_id'];
        $isConcil = ($c['session_type'] === 'conciliation');
        if (!$hearing_id) continue;

        // Find absent parties without explanation
        $aq = mysqli_prepare($conn,
            "SELECT p.id, p.full_name, p.party_type, p.matched_resident_id
               FROM complaint_parties p
               JOIN complaint_attendance a ON a.party_id = p.id
              WHERE a.complaint_id = ? AND a.hearing_id = ?
                AND a.status = 'no_show'
                AND (a.justification_status IS NULL OR a.justification_status = '')");
        mysqli_stmt_bind_param($aq, "ii", $cid, $hearing_id);
        mysqli_stmt_execute($aq);
        $ar = mysqli_stmt_get_result($aq);
        $absent = [];
        while ($row = mysqli_fetch_assoc($ar)) $absent[] = $row;
        mysqli_stmt_close($aq);

        if (!$absent) {
            $u = mysqli_prepare($conn,
                "UPDATE complaint_hearings SET explain_window_resolved_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($u, "i", $hearing_id);
            mysqli_stmt_execute($u);
            mysqli_stmt_close($u);
            continue;
        }

        $present = cronGetPresentParties($conn, $cid, $hearing_id);

        // Mark as unjustified
        $compUnjust = false;
        $respUnjust = false;
        $unjustParties = [];

        foreach ($absent as $p) {
            $u = mysqli_prepare($conn,
                "UPDATE complaint_attendance
                    SET justification_status = 'unjustified',
                        justification_reason = 'No explanation given within 5 days after the appearance date.',
                        justification_decided_at = NOW()
                  WHERE complaint_id = ? AND hearing_id = ? AND party_id = ?");
            mysqli_stmt_bind_param($u, "iii", $cid, $hearing_id, $p['id']);
            mysqli_stmt_execute($u);
            mysqli_stmt_close($u);

            if ($p['party_type'] === 'complainant') $compUnjust = true;
            if ($p['party_type'] === 'respondent')  $respUnjust  = true;
            $unjustParties[] = $p;
        }

        $newStatus = $c['complaint_status'];
        $certInfo  = null;
        $kpFormTag = null;

        if ($compUnjust) {
            // Complainant unjustified -> dismissed + KP #23
            $newStatus = 'dismissed';
            $kpFormTag = 'kp23';

            $certRes = cronIssueBarCert($conn, $cid, 'action',
                'Complainant failed to explain absence within 5 days.');
            if ($certRes) {
                $certInfo = $certRes;
                cronLog("KP#23 generated for #{$c['reference_number']} -> {$certRes['pdf_url']}");
            } else {
                cronLog("KP#23 GENERATION FAILED for #{$c['reference_number']}");
            }

        } elseif ($respUnjust) {
            // Respondent unjustified -> always generate KP #24
            $kpFormTag = 'kp24';

            $certRes = cronIssueBarCert($conn, $cid, 'counterclaim',
                'Respondent failed to explain absence within 5 days.');
            if ($certRes) {
                $certInfo = $certRes;
                cronLog("KP#24 generated for #{$c['reference_number']} -> {$certRes['pdf_url']}");
            } else {
                cronLog("KP#24 GENERATION FAILED for #{$c['reference_number']}");
            }

            if ($isConcil) {
                $newStatus = 'failed_conciliation_final';
            } else {
                $newStatus = 'failed_mediation';
            }
            
        }
                // ✅ Extract the PDF path once, so every notification below can attach it
        $certPdfPath = $certInfo['pdf_url'] ?? null;

        // Update complaint + close hearing
        $u = mysqli_prepare($conn,
            "UPDATE complaints
                SET status = ?,
                    concil_absence_waiting_since   = NULL,
                    concil_absence_waiting_hearing = NULL,
                    concil_absence_appearance_at   = NULL,
                    concil_hearing_started_at      = NULL,
                    absence_waiting_since          = NULL,
                    absence_waiting_hearing        = NULL,
                    absence_appearance_at          = NULL
              WHERE id = ?");
        mysqli_stmt_bind_param($u, "si", $newStatus, $cid);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);

        $uh = mysqli_prepare($conn,
            "UPDATE complaint_hearings
                SET explain_window_resolved_at = NOW(),
                    explain_window_auto_closed = 1,
                    status = 'completed',
                    outcome = 'no_settlement',
                    completed_at = NOW()
              WHERE id = ?");
        mysqli_stmt_bind_param($uh, "i", $hearing_id);
        mysqli_stmt_execute($uh);
        mysqli_stmt_close($uh);

        cronLogUpdate($conn, $cid, 'resolution', $c['complaint_status'], $newStatus,
            "Absent parties failed to explain within 5 days - marked UNJUSTIFIED. " .
            ($compUnjust ? 'Complainant unjustified -> DISMISSED (KP #23). ' : '') .
            ($respUnjust ? 'Respondent unjustified -> ' . ($isConcil ? 'Certification (KP #24).' : 'Pangkat constitution (KP #24).') : ''));

        // Notify unjustified absent parties
        $printUnjust = [];
        foreach ($unjustParties as $p) {
            $rid = (int)($p['matched_resident_id'] ?? 0);
            if (!$rid) $rid = (int)(cronTryMatchResident($conn, $p['id']) ?? 0);
            $roleLabel = ($p['party_type'] === 'complainant') ? 'Complainant' : 'Respondent';

            if ($rid) {
                if ($p['party_type'] === 'complainant') {
                    $title = "Complaint Dismissed - You Did Not Explain";
                    $msg   = "As the Complainant, you failed to explain your absence within 5 days "
                           . "for complaint #{$c['reference_number']}. Under RA 7160, the complaint is dismissed "
                           . "and you are barred from filing in court."
                           . ($certInfo ? "\n\nKP Form #23 Cert No: {$certInfo['cert_no']}" : "");
                } else {
                    if ($isConcil) {
                        $title = "Counterclaim Barred - You Did Not Explain";
                        $msg   = "As the Respondent, you failed to explain your absence within 5 days "
                               . "for complaint #{$c['reference_number']}. Your counterclaim is barred. "
                               . "The case will proceed to Certification to File Action."
                               . ($certInfo ? "\n\nKP Form #24 Cert No: {$certInfo['cert_no']}" : "");
                    } else {
                        $title = "Counterclaim Barred - You Did Not Explain";
                        $msg   = "As the Respondent, you failed to explain your absence within 5 days "
                               . "for complaint #{$c['reference_number']}. Your counterclaim is barred. "
                               . "Mediation is deemed failed and the case will now proceed to the Pangkat."
                               . ($certInfo ? "\n\nKP Form #24 Cert No: {$certInfo['cert_no']}" : "");
                    }
                }
                cronSendNotification($conn, 'resident', $rid, $cid, $title, $msg, 'error', $certPdfPath);
                cronSafePushResident($conn, $rid, $title, $msg, '/BRITE/resident/dashboard.php', $certPdfPath);
            } else {
                $printUnjust[] = $roleLabel . ' ' . $p['full_name'];
            }
        }

        // Notify present parties
        $printPresent = [];
        foreach ($present as $p) {
            $rid = (int)($p['matched_resident_id'] ?? 0);
            if (!$rid) $rid = (int)(cronTryMatchResident($conn, $p['id']) ?? 0);
            $roleLabel = ($p['party_type'] === 'complainant') ? 'Complainant' : 'Respondent';

            if ($compUnjust) {
                $title = "Case Update - Complaint Dismissed";
                $msg   = "As the {$roleLabel}, you are informed that the complainant failed to explain "
                       . "their absence within 5 days for complaint #{$c['reference_number']}. "
                       . "The complaint has been dismissed.";
            } elseif ($respUnjust) {
                if ($isConcil) {
                    $title = "Case Update - Respondent Barred";
                    $msg   = "As the {$roleLabel}, you are informed that the respondent failed to explain "
                           . "their absence within 5 days for complaint #{$c['reference_number']}. "
                           . "The respondent's counterclaim is barred. You may now secure a Certificate to File Action.";
                } else {
                    $title = "Case Update - Proceeding to Pangkat";
                    $msg   = "As the {$roleLabel}, you are informed that the respondent failed to explain "
                           . "their absence within 5 days for complaint #{$c['reference_number']}. "
                           . "The case will now proceed to the Pangkat.";
                }
            }

            if ($rid) {
                cronSendNotification($conn, 'resident', $rid, $cid, $title, $msg, 'info', $certPdfPath);
                cronSafePushResident($conn, $rid, $title, $msg, '/BRITE/resident/dashboard.php', $certPdfPath);
            } else {
                $printPresent[] = $roleLabel . ' ' . $p['full_name'] . ' - ' . $title;
            }
        }

        // Notify Captain
        $captainId = cronGetComplaintCaptain($conn, $cid);
        if ($captainId) {
            if ($compUnjust) {
                cronRemindAdmin($conn, $captainId, $cid,
                    'Complainant Unjustified - Case Dismissed',
                    "Complaint #{$c['reference_number']} - the complainant failed to explain. "
                    . "KP Form #23 issued. "
                    . ($certInfo ? "Cert No: {$certInfo['cert_no']}" : ""),
                    '/BRITE/admin/dashboards/captain_dashboard.php',
                    $certPdfPath);
            } elseif ($respUnjust) {
                if ($isConcil) {
                    cronRemindAdmin($conn, $captainId, $cid,
                        'Respondent Unjustified - Certification to File Action',
                        "Complaint #{$c['reference_number']} - the respondent failed to explain. "
                        . "KP Form #24 issued. "
                        . ($certInfo ? "Cert No: {$certInfo['cert_no']}" : ""),
                        '/BRITE/admin/dashboards/captain_dashboard.php',
                        $certPdfPath);
                } else {
                    cronRemindAdmin($conn, $captainId, $cid,
                        'Action Required - Set Pangkat Constitution Date',
                        "Complaint #{$c['reference_number']} - the respondent failed to explain their absence. "
                        . "Mediation is deemed failed. KP Form #24 issued. "
                        . ($certInfo ? "Cert No: {$certInfo['cert_no']}. " : "")
                        . "Please set the Pangkat constitution meeting date.",
                        '/BRITE/admin/dashboards/captain_dashboard.php',
                        $certPdfPath);
                }
            }
        }

        // Notify Secretary
        if ($kpFormTag && $certInfo) {
            $formLabel = ($kpFormTag === 'kp23')
                ? 'KP Form #23 (Certification to Bar Action)'
                : 'KP Form #24 (Certification to Bar Counterclaim)';

            $secMsg = "Complaint #{$c['reference_number']} - 5-day explain window expired.\n"
                    . "Decision: " . ($compUnjust
                        ? 'Complainant unjustified - DISMISSED'
                        : 'Respondent unjustified - counterclaim barred') . ".\n\n"
                    . "{$formLabel}\n"
                    . "   Cert No: {$certInfo['cert_no']}\n"
                    . "   PDF: {$certInfo['pdf_url']}\n";

            if (!empty($printUnjust)) {
                $secMsg .= "\nPrint & hand-deliver (no resident account):\n- "
                         . implode("\n- ", $printUnjust) . "\n";
            }
            if (!empty($printPresent)) {
                $secMsg .= "\nPrint & deliver case update (no resident account):\n- "
                         . implode("\n- ", $printPresent) . "\n";
            }
            if (empty($printUnjust) && empty($printPresent)) {
                $secMsg .= "\nAll parties have resident accounts. PDF is saved on disk for the record.\n";
            }

            cronNotifySecretaryPrint($conn, $cid,
                'KP ' . ($kpFormTag === 'kp23' ? '#23' : '#24') . ' Generated - '
                . (empty($printUnjust) && empty($printPresent) ? 'For Records' : 'Print Required'),
                $secMsg,
                $hearing_id,
                $certPdfPath);
        }

        // Notify Pangkat members (if conciliation)
        if ($isConcil) {
            cronRemindPangkatMembers($conn, $cid,
                '5-Day Explain Window Expired - Unjustified',
                "Complaint #{$c['reference_number']} - the 5-day explain window has expired. "
                . ($compUnjust ? 'Complainant unjustified - dismissed.' : '')
                . ($respUnjust ? 'Respondent unjustified - proceeding to certification.' : ''),
                $hearing_id,
                $certPdfPath);
        }

        cronLog("explain_expired: case=#{$c['reference_number']} status={$newStatus} "
              . "kp=" . ($kpFormTag ?? 'none')
              . " compUnjust=" . ($compUnjust ? 'yes' : 'no')
              . " respUnjust=" . ($respUnjust ? 'yes' : 'no')
              . " print_unjust=" . count($printUnjust)
              . " print_present=" . count($printPresent));

        $sent++;
    }

    return $sent;
}

/* ============================================================
   4. 15-day mediation deadline EXPIRED
   ============================================================ */
function cron_mediation_deadline_expired($conn) {
    $today = date('Y-m-d');

    $s = mysqli_prepare($conn,
        "SELECT c.id, c.reference_number, c.status, c.mediation_deadline,
                c.assigned_to, c.created_by
           FROM complaints c
          WHERE c.status IN ('for_mediation','mediation_scheduled')
            AND c.mediation_deadline IS NOT NULL
            AND DATE(c.mediation_deadline) < ?");
    mysqli_stmt_bind_param($s, "s", $today);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $expired = [];
    while ($row = mysqli_fetch_assoc($r)) $expired[] = $row;
    mysqli_stmt_close($s);

    $sent = 0;
    foreach ($expired as $c) {
        $cid = (int)$c['id'];
        $complainantNoShow = cronHasComplainantNoShow($conn, $cid);

        if ($complainantNoShow) {
            // Complainant no-show -> dismiss + KP #23
            $newStatus = 'dismissed';

            $certInfo = cronIssueBarCert($conn, $cid, 'action',
                'Complainant failed to appear despite due notice.');
            if ($certInfo) {
                cronLog("KP#23 generated for #{$c['reference_number']}: {$certInfo['pdf_url']}");
            } else {
                cronLog("KP#23 GENERATION FAILED for #{$c['reference_number']}");
            }

            $u = mysqli_prepare($conn,
                "UPDATE complaints SET status = ?, mediation_last_result = 'no_settlement' WHERE id = ?");
            mysqli_stmt_bind_param($u, "si", $newStatus, $cid);
            mysqli_stmt_execute($u);
            mysqli_stmt_close($u);

            cronLogUpdate($conn, $cid, 'status_change', $c['status'], 'dismissed',
                "15-day mediation expired. Complainant did not appear. Complaint dismissed.");

            $printList = [];
            // ✅ NEW: capture cert PDF path once
            $certPdfPath = $certInfo['pdf_url'] ?? null;

            cronNotifyOrPrintComplainant($conn, $cid, $c['reference_number'], $c['created_by'],
                "Complaint Dismissed - You Did Not Appear",
                "As the Complainant, you failed to appear during the 15-day mediation period for "
                . "complaint #{$c['reference_number']}. The complaint is dismissed and you are barred "
                . "from filing in court. "
                . ($certInfo ? "KP Form #23 Cert No: {$certInfo['cert_no']}" : ""),
                'error', $printList, $certPdfPath);

            cronNotifyOrPrintRespondent($conn, $cid, $c['reference_number'],
                "Case Update - Complaint Dismissed",
                "As the Respondent, you are informed that the complainant failed to appear during the "
                . "15-day mediation period for complaint #{$c['reference_number']}. The complaint has "
                . "been dismissed.",
                'info', $printList, $certPdfPath);

            $captainId = !empty($c['assigned_to']) ? (int)$c['assigned_to'] : cronGetComplaintCaptain($conn, $cid);
            if ($captainId) {
                cronRemindAdmin($conn, $captainId, $cid,
                    'Complainant No-Show - Case Dismissed',
                    "Complaint #{$c['reference_number']} - 15-day mediation expired and the complainant "
                    . "never appeared. The complaint is dismissed. KP Form #23 issued. "
                    . ($certInfo ? "Cert No: {$certInfo['cert_no']}" : ""),
                    '/BRITE/admin/dashboards/captain_dashboard.php',
                    $certPdfPath);
            }

            if ($certInfo) {
                $secMsg = "Complaint #{$c['reference_number']} - 15-day mediation expired, complainant never appeared.\n\n"
                        . "KP Form #23 (Certification to Bar Action)\n"
                        . "   Cert No: {$certInfo['cert_no']}\n"
                        . "   PDF: {$certInfo['pdf_url']}\n";

                if (!empty($printList)) {
                    $secMsg .= "\nPrint & hand-deliver (no resident account):\n- "
                             . implode("\n- ", $printList) . "\n";
                } else {
                    $secMsg .= "\nAll parties have resident accounts. PDF is saved on disk for the record.\n";
                }

                cronNotifySecretaryPrint($conn, $cid,
                    'KP #23 Generated - '
                    . (empty($printList) ? 'For Records' : 'Print Required'),
                    $secMsg,
                    0,
                    $certPdfPath);
            }

            cronLog("mediation_deadline_expired: case=#{$c['reference_number']} -> DISMISSED kp=kp23"
                  . " print_list=" . count($printList));

        } else {
            // Normal expiry -> failed_mediation
            $newStatus = 'failed_mediation';

            $u = mysqli_prepare($conn,
                "UPDATE complaints
                    SET status = ?,
                        mediation_last_result = 'no_settlement',
                        resolution_notes = CONCAT(COALESCE(resolution_notes,''), '\n\n15-day mediation period expired.')
                  WHERE id = ? AND status IN ('for_mediation','mediation_scheduled')");
            mysqli_stmt_bind_param($u, "si", $newStatus, $cid);
            mysqli_stmt_execute($u);
            $changed = mysqli_stmt_affected_rows($u);
            mysqli_stmt_close($u);

            if ($changed < 1) continue;

            $h = mysqli_prepare($conn,
                "UPDATE complaint_hearings
                 SET status = 'cancelled',
                     session_reason = CONCAT(COALESCE(session_reason,''), '\nCancelled: 15-day mediation expired.')
                 WHERE complaint_id = ? AND session_type = 'mediation' AND status = 'scheduled'");
            mysqli_stmt_bind_param($h, "i", $cid);
            mysqli_stmt_execute($h);
            mysqli_stmt_close($h);

            cronLogUpdate($conn, $cid, 'status_change', $c['status'], 'failed_mediation',
                "15-day mediation period expired. Captain to schedule Pangkat constitution.");

            $printList = [];
            cronNotifyOrPrintComplainant($conn, $cid, $c['reference_number'], $c['created_by'],
                "Mediation Period Ended - Case Proceeding to Pangkat",
                "The 15-day mediation period for complaint #{$c['reference_number']} has ended without "
                . "a settlement. As the Complainant, the case will now proceed to the Pangkat for conciliation.",
                'info', $printList);

            cronNotifyOrPrintRespondent($conn, $cid, $c['reference_number'],
                "Mediation Period Ended - Case Proceeding to Pangkat",
                "The 15-day mediation period for complaint #{$c['reference_number']} has ended. "
                . "As the Respondent, you will be notified of the Pangkat constitution meeting date.",
                'info', $printList);

            $captainId = !empty($c['assigned_to']) ? (int)$c['assigned_to'] : cronGetComplaintCaptain($conn, $cid);
            if ($captainId) {
                cronRemindAdmin($conn, $captainId, $cid,
                    'Action Required - Set Pangkat Constitution Date',
                    "Complaint #{$c['reference_number']} - the 15-day mediation period has expired. "
                    . "Please set the Pangkat constitution meeting date. KP Form #10 will be issued to both parties.",
                    '/BRITE/admin/dashboards/captain_dashboard.php');
            }

            $secMsg = "Complaint #{$c['reference_number']} - mediation failed.\n\n"
                    . "Captain will schedule the Pangkat constitution meeting. "
                    . "Prepare KP Form #10 for both parties.\n\n";
            if (!empty($printList)) {
                $secMsg .= "Print & hand-deliver (no resident account):\n- "
                         . implode("\n- ", $printList) . "\n";
            }
            cronNotifySecretaryPrint($conn, $cid,
                'Prepare Documents - Pangkat Constitution', $secMsg);

            cronLog("mediation_deadline_expired: case=#{$c['reference_number']} -> failed_mediation"
                  . " print_needed=" . count($printList));
        }

        $sent++;
    }

    return $sent;
}

/* ============================================================
   Helper — Get present parties
   ============================================================ */
function cronGetPresentParties($conn, $complaint_id, $hearing_id) {
    $q = mysqli_prepare($conn,
        "SELECT p.id, p.full_name, p.party_type, p.matched_resident_id
           FROM complaint_parties p
           JOIN complaint_attendance a ON a.party_id = p.id
          WHERE a.complaint_id = ? AND a.hearing_id = ?
            AND a.status = 'attend'
            AND p.party_type IN ('complainant','respondent')");
    mysqli_stmt_bind_param($q, "ii", $complaint_id, $hearing_id);
    mysqli_stmt_execute($q);
    $r = mysqli_stmt_get_result($q);
    $list = [];
    while ($row = mysqli_fetch_assoc($r)) $list[] = $row;
    mysqli_stmt_close($q);
    return $list;
}

function cronNotifyOrPrintComplainant($conn, $complaint_id, $ref, $created_by, $title, $msg, $type, &$printList, $document_path = null) {
    if ($created_by) {
        cronSendNotification($conn, 'resident', (int)$created_by, $complaint_id, $title, $msg, $type, $document_path);
        cronSafePushResident($conn, (int)$created_by, $title, $msg, '/BRITE/resident/dashboard.php', $document_path);
    }

    $q = mysqli_prepare($conn,
        "SELECT id, full_name, matched_resident_id FROM complaint_parties
          WHERE complaint_id = ? AND party_type = 'complainant'");
    mysqli_stmt_bind_param($q, "i", $complaint_id);
    mysqli_stmt_execute($q);
    $r = mysqli_stmt_get_result($q);
    while ($row = mysqli_fetch_assoc($r)) {
        $rid = (int)($row['matched_resident_id'] ?? 0);
        if ($rid && $rid !== (int)$created_by) {
            cronSendNotification($conn, 'resident', $rid, $complaint_id, $title, $msg, $type, $document_path);
            cronSafePushResident($conn, $rid, $title, $msg, '/BRITE/resident/dashboard.php', $document_path);
        } elseif (!$rid) {
            $printList[] = 'Complainant ' . $row['full_name'] . ' - ' . $title;
        }
    }
    mysqli_stmt_close($q);
}

function cronNotifyOrPrintRespondent($conn, $complaint_id, $ref, $title, $msg, $type, &$printList, $document_path = null) {
    $q = mysqli_prepare($conn,
        "SELECT id, full_name, matched_resident_id FROM complaint_parties
          WHERE complaint_id = ? AND party_type = 'respondent'");
    mysqli_stmt_bind_param($q, "i", $complaint_id);
    mysqli_stmt_execute($q);
    $r = mysqli_stmt_get_result($q);
    while ($row = mysqli_fetch_assoc($r)) {
        $rid = (int)($row['matched_resident_id'] ?? 0);
        if (!$rid) $rid = (int)(cronTryMatchResident($conn, $row['id']) ?? 0);

        if ($rid) {
            cronSendNotification($conn, 'resident', $rid, $complaint_id, $title, $msg, $type, $document_path);
            cronSafePushResident($conn, $rid, $title, $msg, '/BRITE/resident/dashboard.php', $document_path);
        } else {
            $printList[] = 'Respondent ' . $row['full_name'] . ' - ' . $title;
        }
    }
    mysqli_stmt_close($q);
}

function cronNotifySecretaryPrint($conn, $complaint_id, $title, $msg, $hearing_id = 0, $document_path = null) {
    $targets = [];

    $q = mysqli_prepare($conn,
        "SELECT cp.member_id FROM complaint_pangkat cp
          WHERE cp.complaint_id = ? AND cp.role = 'secretary' LIMIT 1");
    mysqli_stmt_bind_param($q, "i", $complaint_id);
    mysqli_stmt_execute($q);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
    mysqli_stmt_close($q);
    if ($r) $targets[] = (int)$r['member_id'];

    $q3 = mysqli_query($conn,
        "SELECT id FROM admin WHERE admin_role = 'secretary' AND is_active = 1");
    if ($q3) {
        while ($s3 = mysqli_fetch_assoc($q3)) {
            $sid = (int)$s3['id'];
            if (!in_array($sid, $targets, true)) $targets[] = $sid;
        }
    }

    if (empty($targets)) {
        cronLog("cronNotifySecretaryPrint: NO active secretary for complaint=$complaint_id");
        return false;
    }

    foreach ($targets as $sid) {
        cronSendNotification($conn, 'admin', $sid, $complaint_id, $title, $msg, 'warning', $document_path);
        cronSafePush($conn, $sid, $title, $msg,
            '/BRITE/admin/dashboards/secretary_dashboard.php',
            $document_path);
    }

    cronLog("cronNotifySecretaryPrint: complaint=$complaint_id notified " . count($targets) . " secretary(ies)");
    return true;
}

function cronRemindPangkatMembers($conn, $complaint_id, $title, $msg, $hearing_id = 0, $document_path = null) {
    $q = mysqli_prepare($conn,
        "SELECT DISTINCT member_id FROM complaint_pangkat WHERE complaint_id = ?");
    mysqli_stmt_bind_param($q, "i", $complaint_id);
    mysqli_stmt_execute($q);
    $r = mysqli_stmt_get_result($q);
    $ids = [];
    while ($row = mysqli_fetch_assoc($r)) $ids[] = (int)$row['member_id'];
    mysqli_stmt_close($q);
    if (!$ids) return 0;

    $count = 0;
    foreach ($ids as $mid) {
        cronSendNotification($conn, 'admin', $mid, $complaint_id, $title, $msg, 'warning', $document_path);
        cronSafePush($conn, $mid, $title, $msg,
            '/BRITE/admin/dashboards/lupon_dashboard.php',
            $document_path);
        $count++;
    }
    return $count;
}

function cronGetComplaintCaptain($conn, $complaint_id) {
    $s = mysqli_prepare($conn,
        "SELECT assigned_to FROM complaints WHERE id = ? AND assigned_to IS NOT NULL LIMIT 1");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    if ($row && !empty($row['assigned_to'])) return (int)$row['assigned_to'];

    $q = mysqli_query($conn,
        "SELECT id FROM admin WHERE admin_role = 'captain' AND is_active = 1 ORDER BY id ASC LIMIT 1");
    if ($q) {
        $r = mysqli_fetch_assoc($q);
        if ($r) return (int)$r['id'];
    }
    return null;
}

function cronHasComplainantNoShow($conn, $complaint_id) {
    $s = mysqli_prepare($conn,
        "SELECT id FROM complaint_hearings
          WHERE complaint_id = ? AND session_type = 'mediation'
          ORDER BY session_number DESC, id DESC LIMIT 1");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $h = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    if (!$h) return false;
    $hearing_id = (int)$h['id'];

    $q = mysqli_prepare($conn,
        "SELECT COUNT(*) AS c
           FROM complaint_attendance a
           JOIN complaint_parties p ON p.id = a.party_id
          WHERE a.complaint_id = ? AND a.hearing_id = ?
            AND p.party_type = 'complainant' AND a.status = 'no_show'");
    mysqli_stmt_bind_param($q, "ii", $complaint_id, $hearing_id);
    mysqli_stmt_execute($q);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
    mysqli_stmt_close($q);
    return ((int)($r['c'] ?? 0)) > 0;
}

function cronIssueBarCert($conn, $complaint_id, $type, $reason) {
    cronLog("=== cronIssueBarCert START - complaint={$complaint_id} type={$type} ===");

    $fn = ($type === 'action') ? 'save_bar_action_cert' : 'save_bar_counterclaim_cert';

    if (!function_exists($fn)) {
        $helperPath = __DIR__ . '/../../admin/generate_kp_notice.php';

        cronLog("cronIssueBarCert: __DIR__ = " . __DIR__);
        cronLog("cronIssueBarCert: looking for helper at = {$helperPath}");
        cronLog("cronIssueBarCert: file exists = " . (file_exists($helperPath) ? 'YES' : 'NO'));

        if (!file_exists($helperPath)) {
            cronLog("cronIssueBarCert: ABORT - helper file not found");
            return null;
        }

        if (!defined('KP_NOTICE_INTERNAL')) {
            define('KP_NOTICE_INTERNAL', true);
        }

        try {
            $prevErrorReporting = error_reporting(E_ALL);
            $prevDisplayErrors  = ini_get('display_errors');

            ini_set('display_errors', '1');
            error_reporting(E_ALL);

            require_once $helperPath;

            ini_set('display_errors', $prevDisplayErrors);
            error_reporting($prevErrorReporting);

            cronLog("cronIssueBarCert: require_once completed OK");
        } catch (Throwable $e) {
            cronLog("cronIssueBarCert: EXCEPTION during require: " . $e->getMessage());
            cronLog("cronIssueBarCert: in file: " . $e->getFile() . " line " . $e->getLine());
            return null;
        }

        if (!function_exists($fn)) {
            cronLog("cronIssueBarCert: {$fn} STILL not defined after require");
            cronLog("cronIssueBarCert: Available functions starting with 'save_bar_' or 'kp_':");

            $allFns = get_defined_functions()['user'];
            foreach ($allFns as $fname) {
                if (strpos($fname, 'save_bar_') === 0 || strpos($fname, 'kp_') === 0) {
                    cronLog("  - {$fname}");
                }
            }
            return null;
        }
        cronLog("cronIssueBarCert: {$fn} loaded successfully");
    } else {
        cronLog("cronIssueBarCert: {$fn} already loaded");
    }

    cronLog("cronIssueBarCert: calling {$fn}()");
    try {
        $res = $fn($conn, $complaint_id, date('Y-m-d'), $reason, 'PANGKAT SECRETARY');
        cronLog("cronIssueBarCert: {$fn} returned: " . json_encode($res));
    } catch (Throwable $e) {
        cronLog("cronIssueBarCert: EXCEPTION: " . $e->getMessage());
        cronLog("cronIssueBarCert: in file: " . $e->getFile() . " line " . $e->getLine());
        cronLog("cronIssueBarCert: trace: " . $e->getTraceAsString());
        return null;
    }

    if (empty($res['ok'])) {
        cronLog("cronIssueBarCert: returned not OK: " . ($res['error'] ?? 'unknown'));
        return null;
    }

    $relPath = $res['pdf_path'] ?? null;
    if ($relPath) {
        $absPath = __DIR__ . '/../../' . $relPath;
        cronLog("cronIssueBarCert: checking PDF at = {$absPath}");
        cronLog("cronIssueBarCert: PDF exists = " . (file_exists($absPath) ? 'YES' : 'NO'));
        if (file_exists($absPath)) {
            cronLog("cronIssueBarCert: PDF size = " . filesize($absPath) . " bytes");
        }
    }

    $prefix = ($type === 'action') ? 'CBA' : 'CBC';
    $certNo = $prefix . '-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));

    $col   = ($type === 'action') ? 'bar_action_cert_no' : 'bar_counterclaim_cert_no';
    $atCol = ($type === 'action') ? 'bar_action_issued_at' : 'bar_counterclaim_issued_at';

    $u = mysqli_prepare($conn, "UPDATE complaints SET $col = ?, $atCol = NOW() WHERE id = ?");
    if ($u) {
        mysqli_stmt_bind_param($u, "si", $certNo, $complaint_id);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);
        cronLog("cronIssueBarCert: DB updated {$col} = {$certNo}");
    } else {
        cronLog("cronIssueBarCert: DB prepare failed: " . mysqli_error($conn));
    }

    cronLog("=== cronIssueBarCert DONE - certNo={$certNo} pdf={$relPath} ===");

    return [
        'form'    => ($type === 'action') ? 'KP_FORM_23' : 'KP_FORM_24',
        'cert_no' => $certNo,
        'pdf_url' => $relPath,
    ];
}

function cronLogUpdate($conn, $complaint_id, $type, $prev, $new, $notes) {
    $s = mysqli_prepare($conn,
        "INSERT INTO complaint_updates
            (complaint_id, update_type, previous_status, new_status, notes, updated_by, updated_by_role)
         VALUES (?, ?, ?, ?, ?, 0, 'system')");
    mysqli_stmt_bind_param($s, "issss", $complaint_id, $type, $prev, $new, $notes);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);
}