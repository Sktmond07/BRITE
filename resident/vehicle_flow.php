<?php
// vehicle_flow.php
// Cache-first vehicle request engine — slots, checks, recommendation, commit.
// Included by resident_equipment_ajax.php.

// ── Timezone (must match resident_equipment_ajax.php) ────────
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/resident_equipment_ajax.php';
date_default_timezone_set('Asia/Manila');

// ── Constants (hard-coded per requirement) ───────────────────
if (!defined('VEH_TRIP_HOURS'))   define('VEH_TRIP_HOURS',   2);
if (!defined('VEH_BUFFER_MIN'))   define('VEH_BUFFER_MIN',   30);
if (!defined('VEH_OFFICE_OPEN'))  define('VEH_OFFICE_OPEN',  '05:00');
if (!defined('VEH_OFFICE_CLOSE')) define('VEH_OFFICE_CLOSE', '23:59');
if (!defined('VEH_MAX_HOURS'))    define('VEH_MAX_HOURS',    8);
if (!defined('VEH_DRAFT_TTL'))    define('VEH_DRAFT_TTL',    1800); // 30 min
if (!defined('VEH_FUNERAL_OPEN')) define('VEH_FUNERAL_OPEN', '08:00');
if (!defined('VEH_FUNERAL_CLOSE'))define('VEH_FUNERAL_CLOSE','17:00');

// ── 24h → 12h AM/PM label ────────────────────────────────────
function vehFmtTime($hm) {
    if (!$hm) return '';
    $parts = explode(':', $hm);
    $h = (int)$parts[0];
    $m = $parts[1] ?? '00';
    $ampm = $h >= 12 ? 'PM' : 'AM';
    $h12  = $h % 12; if ($h12 === 0) $h12 = 12;
    return "{$h12}:{$m} {$ampm}";
}

// ── Reason → [label, tier] ───────────────────────────────────
function vehiclePurposesV2() {
    return [
        'medical_appointment' => ['Medical appointment or hospital checkup',              'flexible'],
        'dialysis_treatment'  => ['Dialysis or scheduled medical treatment',              'flexible'],
        'hospital_discharge'  => ['Hospital discharge or return home',                    'flexible'],
        'senior_assistance'   => ['Senior citizen transportation assistance',             'scheduled'],
        'pwd_assistance'      => ['PWD transportation assistance',                        'scheduled'],
        'urgent_medical'      => ['Urgent medical assistance',                            'emergency'],
        'government_office'   => ['Government office or social service appointment',      'scheduled'],
        'funeral'             => ['Funeral or bereavement assistance',                    'flexible'],
        'disaster_evacuation' => ['Disaster evacuation or emergency assistance',          'emergency'],
        'court_hearing'       => ['Court hearing or official legal appointment',          'scheduled'],
        'family_crisis'       => ['Transportation assistance during a family crisis',     'urgent'],
        'other'               => ['Other valid reason',                                   'other'],
    ];
}

function vehicleTierRulesV2() {
    return [
        'emergency' => ['notice_hours' => 0,  'office_only' => false, 'all_day' => true],
        'urgent'    => ['notice_hours' => 0,  'office_only' => false, 'all_day' => false],
        'flexible'  => ['notice_hours' => 2,  'office_only' => false, 'all_day' => false],
        'scheduled' => ['notice_hours' => 24, 'office_only' => false, 'all_day' => false],
        'other'     => ['notice_hours' => 24, 'office_only' => false, 'all_day' => false],
    ];
}

// Funeral / bereavement: reserve the whole vehicle, let the resident set the time,
// and restrict the time window to VEH_FUNERAL_OPEN – VEH_FUNERAL_CLOSE.
function vehicleIsFullVehicleReason($code) {
    return $code === 'funeral';
}

// ── Household resolver (single source of truth: resident.household_id) ─────
function resolveResidentHouseholdId($conn, $resident_id) {
    // 1. Authoritative value from the resident row.
    $stmt = mysqli_prepare($conn,
        "SELECT household_id, first_name, last_name FROM resident WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $resident_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row) return null;

    if (!empty($row['household_id'])) {
        return (int)$row['household_id'];
    }

    // 2. Fallback: match against household_members by trimmed, case-insensitive name.
    //    Also accept the resident's middle name if present.
    $stmt = mysqli_prepare($conn,
        "SELECT hm.household_id
           FROM household_members hm
          WHERE LOWER(TRIM(hm.first_name)) = LOWER(TRIM(?))
            AND LOWER(TRIM(hm.last_name))  = LOWER(TRIM(?))
          ORDER BY hm.id DESC
          LIMIT 1");
    mysqli_stmt_bind_param($stmt, "ss", $row['first_name'], $row['last_name']);
    mysqli_stmt_execute($stmt);
    $hm = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (empty($hm['household_id'])) {
        // Nothing to link to — caller decides what to do.
        return null;
    }

    $hid = (int)$hm['household_id'];

    // 3. Persist it back so future calls don't need the name match.
    $upd = mysqli_prepare($conn, "UPDATE resident SET household_id = ? WHERE id = ?");
    mysqli_stmt_bind_param($upd, "ii", $hid, $resident_id);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    return $hid;
}

// ── Load all active vehicles ─────────────────────────────────
function getActiveVehiclesV2($conn) {
    $out = [];
    $q = mysqli_query($conn,
        "SELECT id, global_id, name, capacity, status, image
           FROM vehicles WHERE status != 'inactive' ORDER BY name ASC");
    if ($q) while ($r = mysqli_fetch_assoc($q)) $out[] = $r;
    return $out;
}

// ── Core slot generator ─────────────────────────────────────
function generateVehicleSlots($conn, $vehicleGlobalId, $date, $requestedPax = 1) {
    $stmt = mysqli_prepare($conn,
        "SELECT capacity FROM vehicles WHERE global_id = ? AND status != 'inactive'");
    mysqli_stmt_bind_param($stmt, "s", $vehicleGlobalId);
    mysqli_stmt_execute($stmt);
    $v = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$v) return [];

    $capacity  = (int)$v['capacity'];
    $tripMin   = VEH_TRIP_HOURS  * 60;
    $bufferMin = VEH_BUFFER_MIN;
    $returnMin = $tripMin;

    // Existing approved trips on that date
    $stmt = mysqli_prepare($conn,
        "SELECT r.id, r.trip_slot_start, r.trip_slot_end,
                COALESCE(SUM(vr.passenger_count),0) AS taken
           FROM requests r
           LEFT JOIN vehicle_requests vr ON vr.request_id = r.id
          WHERE r.item_global_id = ?
            AND r.request_type = 'vehicle'
            AND r.trip_date = ?
            AND r.status IN ('approved','borrowed')
          GROUP BY r.id
          ORDER BY r.trip_slot_start ASC");
    mysqli_stmt_bind_param($stmt, "ss", $vehicleGlobalId, $date);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $blocked   = [];
    $shareable = [];
    while ($t = mysqli_fetch_assoc($res)) {
        $start = strtotime("$date {$t['trip_slot_start']}");
        $end   = strtotime("$date {$t['trip_slot_end']}");
        $blocked[] = ['start' => $start, 'end' => $end + ($returnMin + $bufferMin) * 60];

        $seatsLeft = $capacity - (int)$t['taken'];
        if ($seatsLeft >= $requestedPax) {
            $s = substr($t['trip_slot_start'],0,5);
            $e = substr($t['trip_slot_end'],0,5);
            $shareable[] = [
                'type'        => 'share',
                'start'       => $s,
                'end'         => $e,
                'start_label' => vehFmtTime($s),
                'end_label'   => vehFmtTime($e),
                'label'       => vehFmtTime($s) . ' – ' . vehFmtTime($e),
                'trip_id'     => (int)$t['id'],
                'seats_left'  => $seatsLeft,
                'capacity'    => $capacity,
                'taken'       => (int)$t['taken'],
            ];
        }
    }
    mysqli_stmt_close($stmt);

    usort($blocked, fn($a,$b) => $a['start'] - $b['start']);
    $merged = [];
    foreach ($blocked as $b) {
        if (!$merged || $b['start'] > end($merged)['end']) $merged[] = $b;
        else $merged[count($merged)-1]['end'] = max(end($merged)['end'], $b['end']);
    }

    // Emergency tier → 24-hour window. Everyone else → office window.
    $tierRules = vehicleTierRulesV2();
    $isAllDay  = false;
    foreach ($tierRules as $tier => $r) {
        if (!empty($r['all_day'])) $isAllDay = true;   // flag detection only
    }

    // The generator doesn't know the tier yet, so the caller passes it.
    // Default: use office window. Emergency override comes from the caller.
    // Emergency tier runs all day; every other tier uses the office window.
    $tierRules = vehicleTierRulesV2();
    $allDay    = false;
    foreach ($tierRules as $r) { if (!empty($r['all_day'])) { $allDay = true; break; } }

    // The generator is called once per vehicle; it doesn't know the tier,
    // so we read the current request's tier from a global override if set.
    // Callers that want 24h can set $GLOBALS['__veh_all_day'] = true.
    if (!empty($GLOBALS['__veh_all_day'])) $allDay = true;

    $openStart = $allDay ? '00:00' : VEH_OFFICE_OPEN;
    $openEnd   = $allDay ? '23:59' : VEH_OFFICE_CLOSE;

    $dayStart = strtotime("$date " . $openStart);
    $dayEnd   = strtotime("$date " . $openEnd);
    $open = [];
    $cursor = $dayStart;
    $step   = ($tripMin + $returnMin + $bufferMin) * 60;

    // Walk the whole day; skip around any booked interval
    $safety = 0;
    while ($cursor + $tripMin * 60 <= $dayEnd && $safety++ < 50) {
        // Are we inside a blocked interval?
        $inside = null;
        foreach ($merged as $b) {
            if ($cursor >= $b['start'] && $cursor < $b['end']) { $inside = $b; break; }
        }
        if ($inside) { $cursor = $inside['end']; continue; }

        // Do we have room before the next booked interval?
        $nextBlockedStart = null;
        foreach ($merged as $b) {
            if ($b['start'] >= $cursor) {
                if ($nextBlockedStart === null || $b['start'] < $nextBlockedStart) $nextBlockedStart = $b['start'];
            }
        }
        if ($nextBlockedStart !== null && $cursor + $tripMin * 60 > $nextBlockedStart) {
            foreach ($merged as $b) {
                if ($b['start'] === $nextBlockedStart) { $cursor = $b['end']; break; }
            }
            continue;
        }

        $s = date('H:i', $cursor);
        $e = date('H:i', $cursor + $tripMin * 60);
        $open[] = [
            'type'        => 'open',
            'start'       => $s,
            'end'         => $e,
            'start_label' => vehFmtTime($s),
            'end_label'   => vehFmtTime($e),
            'label'       => vehFmtTime($s) . ' – ' . vehFmtTime($e),
        ];
        $cursor += $step;
    }

    return array_merge($shareable, $open);
}

// ── Gibberish checker (server-side, source of truth) ─────────
function isGibberish($text) {
    $t = trim((string)$text);
    if (mb_strlen($t) < 10)        return 'Please write at least 10 characters.';
    if (mb_strlen($t) > 300)       return 'Please keep it under 300 characters.';

    $words = preg_split('/\s+/u', $t);
    if (count($words) < 2)         return 'Please write a full sentence (at least 2 words).';
    if (!preg_match('/[aeiou]/i', $t)) return 'That does not look like a real reason.';
    if (preg_match('/(.)\1{3,}/u', $t))  return 'Too many repeated characters.';
    if (preg_match('/(\b\w+\b)(?:\s+\1){3,}/iu', $t)) return 'Too many repeated words.';

    $letters   = preg_replace('/[^a-z]/i','',$t);
    if ($letters) {
        $vowels = preg_match_all('/[aeiou]/i', $letters);
        $ratio  = $vowels / strlen($letters);
        if ($ratio < 0.15 || $ratio > 0.85) return 'That does not read like a real reason.';
    }

    foreach ($words as $w) {
        if (preg_match('/[bcdfghjklmnpqrstvwxz]{5,}/i', $w)) return 'Please use real words.';
    }

    $bad = ['test','asdf','qwerty','sample','none','n/a','na','xxx','placeholder','bobo','tanga','gago'];
    foreach ($words as $w) {
        if (in_array(strtolower($w), $bad, true)) return 'Please describe your reason properly.';
    }
    return null;   // ok
}

// ── Step 2 — checks + candidates ────────────────────────────
function checkVehicleRequestV2($conn, $draft, $resident_id) {
    $errors   = [];
    $warnings = [];

    $purposes = vehiclePurposesV2();
    if (!isset($purposes[$draft['reason_code']])) {
        $errors[] = 'Please choose a valid reason.';
    } else {
        [$label, $tier] = $purposes[$draft['reason_code']];
        $draft['reason_label'] = $label;
        $draft['urgency']      = $tier;
    }

    if ($draft['reason_code'] === 'other') {
        $g = isGibberish($draft['other_reason'] ?? '');
        if ($g) $errors[] = 'Other reason: ' . $g;
    }

    // Date — only check format + not in the past.
    // The notice rule is validated against the actual departure slot in commit.
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $draft['date'])) {
        $errors[] = 'Please pick a date.';
    } else {
        $reqTs = strtotime($draft['date'] . ' 00:00:00');
        if ($reqTs < strtotime('today')) {
            $errors[] = 'The date is in the past.';
        }
    }

    // Household guard
    $householdId = resolveResidentHouseholdId($conn, $resident_id);
    $draft['household_id'] = $householdId;

    

    // Persist into the session draft so vehicleCommit can read it.
    if (isset($_SESSION['vehicle_draft'])
        && ($_SESSION['vehicle_draft']['token'] ?? '') === ($draft['token'] ?? '')) {
        $_SESSION['vehicle_draft']['household_id'] = $householdId;
    }

    $isEmergencyTier = in_array($tier, ['emergency','urgent'], true);
    if (!$isEmergencyTier) {
        if ($householdId === null) {
            // No household on file. Refuse so one person can't spam unlimited trips.
            $errors[] = 'Your account is not linked to a household. '
                      . 'Please visit the barangay office to update your household record before requesting a vehicle.';
        } else {
            // Duplicate-trip check for the household.
            // NOTE: join through resident.household_id (live), not requests.household_id,
            //       so legacy rows with NULL still count.
            $stmt = mysqli_prepare($conn,
                "SELECT COUNT(*) AS c
                   FROM requests r
                   JOIN resident res ON r.resident_id = res.id
                  WHERE res.household_id = ?
                    AND r.request_type = 'vehicle'
                    AND r.status IN ('pending','approved','borrowed')
                    AND r.trip_date = ?");
            mysqli_stmt_bind_param($stmt, "is", $householdId, $draft['date']);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (($row['c'] ?? 0) > 0) {
                $errors[] = 'Someone in your household already has a vehicle request on this date.';
            }
        }
        
    }

    // Candidates — emergency requests see the full 24-hour day
    $GLOBALS['__veh_all_day'] = ($tier === 'emergency');

    $candidates = [];
    foreach (getActiveVehiclesV2($conn) as $v) {
        if ((int)$v['capacity'] < (int)$draft['passengers']) continue;
        if (in_array(strtolower($v['status']), ['maintenance','repair','unavailable'], true)) continue;
        $slots = generateVehicleSlots($conn, $v['global_id'], $draft['date'], (int)$draft['passengers']);
        if (!$slots) continue;
        $candidates[] = ['vehicle' => $v, 'slots' => $slots];
    }

    if (!$candidates) {
        $warnings[] = 'No vehicle has an available slot on this date. Try another date.';
    }

    return [
        'success'    => empty($errors),
        'errors'     => $errors,
        'warnings'   => $warnings,
        'draft'      => $draft,
        'candidates' => $candidates,
    ];
}

// ── Step 3 — recommendation ─────────────────────────────────
function recommendVehicleV2($candidates, $draft) {
    $rules      = vehicleTierRulesV2()[$draft['urgency']] ?? ['notice_hours'=>0,'office_only'=>false];
    $noticeSecs = $rules['notice_hours'] * 3600;
    $minTs      = time() + $noticeSecs;

    $scored = [];
    foreach ($candidates as $c) {
        foreach ($c['slots'] as $slot) {
            $slotTs = strtotime($draft['date'] . ' ' . $slot['start']);

            // Skip slots that violate the notice rule
            if ($slotTs < $minTs) continue;

            // Skip slots outside office hours if the tier requires it
            if (!empty($rules['office_only'])) {
                $openTs = strtotime($draft['date'] . ' ' . VEH_OFFICE_OPEN);
                $clsTs  = strtotime($draft['date'] . ' ' . VEH_OFFICE_CLOSE);
                $endTs  = strtotime($draft['date'] . ' ' . $slot['end']);
                if ($slotTs < $openTs || $endTs > $clsTs) continue;
            }

            $score = 0;
            if ($slot['type'] === 'share') $score += 30;
            $score += (24 - (int)substr($slot['start'],0,2));
            $slack  = (int)$c['vehicle']['capacity'] - (int)$draft['passengers'];
            $score -= $slack;
            $scored[] = ['score'=>$score,'vehicle'=>$c['vehicle'],'slot'=>$slot];
        }
    }
    usort($scored, fn($a,$b)=>$b['score']-$a['score']);
    return $scored;
}

// ── Step 4 — commit ─────────────────────────────────────────
function commitVehicleRequestV2($conn, $resident_id, $draft, $chosen) {
    vdbg('commitVehicleRequestV2: start');
    vdbg_arr('draft', $draft);
    vdbg_arr('chosen', $chosen);

    mysqli_begin_transaction($conn);
    vdbg('transaction begun');

    try {
        $vehGlobal = $chosen['vehicle']['global_id'] ?? '';
        $slotStart = $chosen['slot']['start'] ?? '';
        $slotEnd   = $chosen['slot']['end']   ?? '';
        vdbg("vehGlobal=$vehGlobal slotStart=$slotStart slotEnd=$slotEnd");

        // Row-lock the vehicle + re-verify capacity
        $stmt = mysqli_prepare($conn,
            "SELECT id, capacity, name, status FROM vehicles
              WHERE global_id = ? FOR UPDATE");
        if (!$stmt) {
            vdbg('prepare FAILED (vehicle SELECT): ' . mysqli_error($conn));
            throw new Exception('DB prepare failed: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, "s", $vehGlobal);
        mysqli_stmt_execute($stmt);
        $v = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        vdbg_arr('vehicle row', $v);
        if (!$v) throw new Exception('Vehicle not found.');

        // Funeral / bereavement reserves the whole vehicle and lets the
        // resident set the exact start time via $draft['custom_time'].
        $isFullVehicle = vehicleIsFullVehicleReason($draft['reason_code'] ?? '');
        if ($isFullVehicle) {
            $draft['passengers'] = (int)$v['capacity'];
            vdbg("full-vehicle reason — passengers forced to {$draft['passengers']}");
        }

        if ($isFullVehicle && !empty($draft['custom_time'])) {
            $customStart = $draft['custom_time'];
            $customEnd   = date('H:i', strtotime($customStart) + VEH_TRIP_HOURS * 3600);
            vdbg("using custom time $customStart – $customEnd");
            $slotStart = $customStart;
            $slotEnd   = $customEnd;
        }

        // Funeral window: 08:00 – 17:00 (start and end must both fit).
        if ($isFullVehicle) {
            $openTs  = strtotime($draft['date'] . ' ' . VEH_FUNERAL_OPEN);
            $closeTs = strtotime($draft['date'] . ' ' . VEH_FUNERAL_CLOSE);
            $startTs = strtotime($draft['date'] . ' ' . $slotStart);
            $endTs   = strtotime($draft['date'] . ' ' . $slotEnd);
            if ($startTs < $openTs || $endTs > $closeTs) {
                throw new Exception(
                    'Funeral / bereavement trips must be between '
                  . date('g:i A', $openTs) . ' and ' . date('g:i A', $closeTs) . '. '
                  . 'Please pick a pick-up time that finishes by '
                  . date('g:i A', $closeTs) . '.'
                );
            }
            vdbg("funeral window OK: $slotStart – $slotEnd");
        }

        // Load ALL approved/pending trips on this vehicle+date
        $stmt = mysqli_prepare($conn,
            "SELECT COALESCE(SUM(vr.passenger_count),0) AS taken
               FROM requests r
               LEFT JOIN vehicle_requests vr ON vr.request_id = r.id
              WHERE r.item_global_id = ?
                AND r.trip_date = ?
                AND r.trip_slot_start = ?
                AND r.status IN ('approved','borrowed','pending')");
        if (!$stmt) {
            vdbg('prepare FAILED (taken SELECT): ' . mysqli_error($conn));
            throw new Exception('DB prepare failed: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, "sss", $vehGlobal, $draft['date'], $slotStart);
        mysqli_stmt_execute($stmt);
        $taken = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['taken'] ?? 0);
        mysqli_stmt_close($stmt);
        vdbg("taken=$taken capacity={$v['capacity']} passengers={$draft['passengers']}");

        if ($taken + (int)$draft['passengers'] > (int)$v['capacity']) {
            $left = max(0, (int)$v['capacity'] - $taken);
            throw new Exception(
                "Only {$left} seat(s) left on this slot, but you requested {$draft['passengers']}. "
              . "Please pick another time slot or reduce the number of passengers."
            );
        }

        // ── Notice rule ──
        $urgency = $draft['urgency'] ?? 'scheduled';
        $rules      = vehicleTierRulesV2()[$urgency] ?? ['notice_hours'=>0,'office_only'=>false];
        $departTs   = strtotime($draft['date'] . ' ' . $slotStart);
        $noticeSecs = $rules['notice_hours'] * 3600;
        vdbg("urgency=$urgency noticeHours={$rules['notice_hours']} departTs=$departTs now=" . time());

        if ($departTs < time() + $noticeSecs) {
            throw new Exception(
                "This kind of trip needs at least {$rules['notice_hours']} hour(s) notice. "
              . "The earliest possible departure is "
              . date('g:i A', time() + $noticeSecs) . "."
            );
        }

        // ── Office-hours rule ──
        if (!empty($rules['office_only'])) {
            $endTs  = strtotime($draft['date'] . ' ' . $slotEnd);
            $openTs = strtotime($draft['date'] . ' ' . VEH_OFFICE_OPEN);
            $clsTs  = strtotime($draft['date'] . ' ' . VEH_OFFICE_CLOSE);
            if ($departTs < $openTs || $endTs > $clsTs) {
                throw new Exception(
                    'This kind of trip must be within office hours ('
                  . date('g:i A', $openTs) . ' – ' . date('g:i A', $clsTs) . ').'
                );
            }
        }

        // Overlap check — critical for full-vehicle reasons, safety net for the rest.
        $bufferSec = VEH_BUFFER_MIN * 60;
        $ovStart   = date('Y-m-d H:i:s', strtotime($draft['date'] . ' ' . $slotStart) - $bufferSec);
        $ovEnd     = date('Y-m-d H:i:s', strtotime($draft['date'] . ' ' . $slotEnd)   + $bufferSec);

        $ovStmt = mysqli_prepare($conn,
            "SELECT COUNT(*) AS c
               FROM requests
              WHERE item_global_id = ?
                AND request_type = 'vehicle'
                AND status IN ('approved','borrowed','pending')
                AND trip_date = ?
                AND start_datetime < ?
                AND end_datetime > ?");
        if (!$ovStmt) {
            vdbg('prepare FAILED (overlap SELECT): ' . mysqli_error($conn));
            throw new Exception('DB prepare failed: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($ovStmt, "ssss", $vehGlobal, $draft['date'], $ovEnd, $ovStart);
        mysqli_stmt_execute($ovStmt);
        $ovRow = mysqli_fetch_assoc(mysqli_stmt_get_result($ovStmt));
        mysqli_stmt_close($ovStmt);
        vdbg("overlap count=" . ($ovRow['c'] ?? 0));

        if (($ovRow['c'] ?? 0) > 0) {
            if ($isFullVehicle) {
                throw new Exception(
                    'The vehicle is already booked near that time. '
                  . 'Please pick another time — the open slots are shown on the previous screen.'
                );
            }
            throw new Exception('That time is no longer available. Please pick another slot.');
        }

        // Auto vs manual
        $autoApprove = true;
        if ($draft['reason_code'] === 'other') $autoApprove = false;
        $status = $autoApprove ? 'approved' : 'pending';
        vdbg("autoApprove=" . ($autoApprove ? 'true' : 'false') . " status=$status");

        $startDT = $draft['date'] . ' ' . $slotStart . ':00';
        $endDT   = $draft['date'] . ' ' . $slotEnd   . ':00';
        $reference = $vehGlobal . '-' . date('YmdHis') . '-' . rand(100,999);
        $purpose   = $draft['reason_label'] ?? ($draft['reason_code'] ?? 'Vehicle request');
        if ($draft['reason_code'] === 'other') {
            $purpose .= ': ' . mb_substr($draft['other_reason'] ?? '', 0, 200);
        }
        $approvedAtSql = $autoApprove ? 'NOW()' : 'NULL';
        $tripDate = $draft['date'];

        // Prefer the value stashed by checkVehicleRequestV2(); otherwise resolve fresh.
        $householdIdRaw = $draft['household_id'] ?? null;
        if ($householdIdRaw === null) {
            $householdIdRaw = resolveResidentHouseholdId($conn, $resident_id);
        }
        // bind_param cannot take null by reference — 0 + NULLIF(?, 0) stores NULL.
        $householdId = ($householdIdRaw === null) ? 0 : (int)$householdIdRaw;

        vdbg("INSERT requests: startDT=$startDT endDT=$endDT tripDate=$tripDate householdId=" . var_export($householdId, true));

$sql = "INSERT INTO requests
        (resident_id, request_type, request_date,
         start_datetime, end_datetime, duration_days, quantity, purpose,
         status, item_global_id, reference, approved_at, created_at,
         trip_date, trip_slot_start, trip_slot_end, urgency, household_id)
        VALUES (?, 'vehicle', NOW(), ?, ?, 1, 1, ?,
                ?, ?, ?, {$approvedAtSql}, NOW(),
                ?, ?, ?, ?, NULLIF(?, 0))";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            vdbg('prepare FAILED (requests INSERT): ' . mysqli_error($conn));
            throw new Exception('DB prepare failed: ' . mysqli_error($conn));
        }

        // Try to bind with a null-safe householdId
        // If household_id is null, the DB column must accept NULL
        mysqli_stmt_bind_param($stmt, "issssssssssi",
    $resident_id, $startDT, $endDT, $purpose,
    $status, $vehGlobal, $reference,
    $tripDate, $slotStart, $slotEnd, $urgency, $householdId);

        if (!mysqli_stmt_execute($stmt)) {
            vdbg('EXECUTE FAILED (requests INSERT): ' . mysqli_stmt_error($stmt));
            throw new Exception('requests INSERT failed: ' . mysqli_stmt_error($stmt));
        }
        $reqId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        vdbg("requests INSERT OK, reqId=$reqId");

        // vehicle_requests detail row
        $isEmergency = ($urgency === 'emergency') ? 1 : 0;
        $winEnd      = date('H:i', strtotime($slotStart) + 3600);
        $reqHours    = VEH_TRIP_HOURS;

$sql2 = "INSERT INTO vehicle_requests
            (request_id, vehicle_global_id, vehicle_name, vehicle_capacity,
             trip_date, pickup_time, pickup_window_end, pickup_location,
             dropoff_location, passenger_count, estimated_hours, purpose,
             special_requests, household_id, is_emergency)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, 0), ?)";
        $stmt = mysqli_prepare($conn, $sql2);
        if (!$stmt) {
            vdbg('prepare FAILED (vehicle_requests INSERT): ' . mysqli_error($conn));
            throw new Exception('DB prepare failed: ' . mysqli_error($conn));
        }

        $special     = $draft['special_requests'] ?? '';
        $passengerCt = (int)$draft['passengers'];
        mysqli_stmt_bind_param($stmt, "ississsssidssii",
            $reqId, $vehGlobal, $v['name'], $v['capacity'],
            $tripDate, $slotStart, $winEnd,
            $draft['pickup_location'], $draft['destination'],
            $passengerCt, $reqHours, $purpose,
            $special, $householdId, $isEmergency);

        if (!mysqli_stmt_execute($stmt)) {
            vdbg('EXECUTE FAILED (vehicle_requests INSERT): ' . mysqli_stmt_error($stmt));
            throw new Exception('vehicle_requests INSERT failed: ' . mysqli_stmt_error($stmt));
        }
        mysqli_stmt_close($stmt);
        vdbg('vehicle_requests INSERT OK');

        mysqli_commit($conn);
        vdbg('transaction committed');

        return [
            'success'       => true,
            'request_id'    => $reqId,
            'reference'     => $reference,
            'status'        => $status,
            'auto_approved' => $autoApprove,
            'slot_start'    => $slotStart,
            'slot_end'      => $slotEnd,
            'vehicle'       => $v['name'],
        ];

    } catch (Throwable $e) {
        mysqli_rollback($conn);
        vdbg('EXCEPTION: ' . get_class($e) . ': ' . $e->getMessage()
             . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        return [
            'success' => false,
            'message' => $e->getMessage(),
            'debug'   => [
                'class' => get_class($e),
                'file'  => basename($e->getFile()),
                'line'  => $e->getLine(),
            ],
        ];
    }
}

// ── AJAX handlers ────────────────────────────────────────────
function vehicleDraftSave($resident_id, $input) {
    if (empty($input['reason_code']))   return ['success'=>false,'message'=>'Reason is required.'];
    if (empty($input['date']))          return ['success'=>false,'message'=>'Date is required.'];
    if (empty($input['destination']))   return ['success'=>false,'message'=>'Destination is required.'];
    if (empty($input['pickup_location'])) return ['success'=>false,'message'=>'Pickup location is required.'];

    $token = bin2hex(random_bytes(16));
    $_SESSION['vehicle_draft'] = [
        'token'           => $token,
        'resident_id'     => $resident_id,
        'reason_code'     => $input['reason_code'],
        'other_reason'    => trim($input['other_reason'] ?? ''),
        'destination'     => trim($input['destination']),
        'pickup_location' => trim($input['pickup_location']),
        'date'            => $input['date'],
        'passengers'      => max(1, (int)($input['passengers'] ?? 1)),
        'special_requests'=> trim($input['special_requests'] ?? ''),
        'custom_time'     => trim($input['custom_time'] ?? ''),
        'created_at'      => time(),
    ];
    return ['success'=>true, 'token'=>$token];
}

function vehicleDraftLoad($resident_id, $token) {
    $d = $_SESSION['vehicle_draft'] ?? null;
    if (!$d || $d['token'] !== $token || $d['resident_id'] !== $resident_id) return null;
    if (time() - $d['created_at'] > VEH_DRAFT_TTL) { unset($_SESSION['vehicle_draft']); return null; }

    // Resolve + persist the tier from reason_code so later steps can rely on it
    if (empty($d['urgency'])) {
        $purposes = vehiclePurposesV2();
        if (isset($purposes[$d['reason_code']])) {
            [$label, $tier] = $purposes[$d['reason_code']];
            $d['urgency']      = $tier;
            $d['reason_label'] = $label;
            $_SESSION['vehicle_draft'] = $d;   // save back
        }
    }
    return $d;
}

function vehicleDraftDiscard() {
    unset($_SESSION['vehicle_draft']);
    return ['success'=>true];
}

function vehicleCheck($conn, $resident_id, $input) {
    $draft = vehicleDraftLoad($resident_id, $input['token'] ?? '');
    if (!$draft) return ['success'=>false,'message'=>'Draft expired. Please start again.'];
    $result = checkVehicleRequestV2($conn, $draft, $resident_id);
    // Guarantee urgency is always present in the response
    if (empty($result['draft']['urgency'])) {
        $result['draft']['urgency'] = 'scheduled';
    }
    return $result;
}

function vehicleRecommend($conn, $resident_id, $input) {
    $draft = vehicleDraftLoad($resident_id, $input['token'] ?? '');
    if (!$draft) return ['success'=>false,'message'=>'Draft expired.'];
    $chk = checkVehicleRequestV2($conn, $draft, $resident_id);
    if (!$chk['success']) return $chk;
    $ranked = recommendVehicleV2($chk['candidates'], $draft);
    if (!$ranked) return ['success'=>false,'message'=>'No slots available.'];
    return [
        'success'      => true,
        'urgency'      => $draft['urgency'] ?? 'scheduled',   // ← fallback
        'recommended'  => $ranked[0],
        'alternatives' => array_slice($ranked, 1, 10),
    ];
}

function vehicleCommit($conn, $resident_id, $input) {
    $draft = vehicleDraftLoad($resident_id, $input['token'] ?? '');
    if (!$draft) return ['success'=>false,'message'=>'Draft expired.'];

    // Rebuild candidate from chosen selection
    $vehGlobal = $input['vehicle_global_id'] ?? '';
    $slotStart = $input['slot_start'] ?? '';
    $slotEnd   = $input['slot_end']   ?? '';
    if (!$vehGlobal || !$slotStart || !$slotEnd) {
        return ['success'=>false,'message'=>'Please pick a vehicle and slot.'];
    }

    $stmt = mysqli_prepare($conn, "SELECT global_id,name,capacity FROM vehicles WHERE global_id = ? AND status != 'inactive'");
    mysqli_stmt_bind_param($stmt, "s", $vehGlobal);
    mysqli_stmt_execute($stmt);
    $v = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$v) return ['success'=>false,'message'=>'Vehicle not found.'];

    $chosen = [
        'vehicle' => $v,
        'slot'    => ['start'=>$slotStart,'end'=>$slotEnd,'type'=>$input['slot_type'] ?? 'open'],
    ];

    $result = commitVehicleRequestV2($conn, $resident_id, $draft, $chosen);
    if ($result['success']) unset($_SESSION['vehicle_draft']);
    return $result;
}

function vehicleTextCheck($input) {
    $msg = isGibberish($input['text'] ?? '');
    return ['success'=>true, 'ok'=>$msg === null, 'message'=>$msg];
}