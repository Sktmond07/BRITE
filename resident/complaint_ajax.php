<?php
// resident/complaint_ajax.php — RESIDENT SIDE ONLY
// ============================================================
//  ROLE-AWARE: A resident is EITHER a complainant OR a respondent
//  on any given complaint. Access rules differ per role:
//    • Complainant — sees own filings
//    • Respondent  — sees case ONLY after captain takes action
//                    (status NOT in pending_review / pending_captain_action / escalated)
//    • Audit trail — HIDDEN from BOTH roles
// ============================================================
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../admin/push_send.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_type = $_SESSION['user_type'];
$user_id   = $_SESSION['user_id'];
$action    = $_POST['action'] ?? $_GET['action'] ?? '';

/* ---------------- Helpers ---------------- */

function generateReferenceNumber() {
    return 'CMP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

/**
 * Statuses where a respondent is allowed to see the case.
 * Hidden: pending_review, pending_captain_action, escalated
 */
function respondentCanSeeStatus($status) {
    $hiddenFromRespondent = ['pending_review', 'pending_captain_action', 'escalated'];
    if (in_array($status, $hiddenFromRespondent, true)) return false;
    return true;
}

/**
 * Sensitive statuses where evidence must be hidden from
 * the respondent (referrals contain captain notes).
 */
function isSensitiveStatus($status) {
    return in_array($status, ['escalated', 'pending_captain_review', 'referred_dispatched', 'released'], true);
}

/**
 * Determine the role of a given resident for a complaint.
 */
function getResidentRoleInComplaint($conn, $complaint_id, $resident_id) {
    $sql = "SELECT party_type FROM complaint_parties
            WHERE complaint_id = ? AND matched_resident_id = ?
            ORDER BY FIELD(party_type,'complainant','respondent','witness')
            LIMIT 1";
    $s = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($s, "ii", $complaint_id, $resident_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    return $row['party_type'] ?? null;
}

/**
 * Build a role-filtered list of KP forms for a resident.
 */
function buildResidentKpForms($conn, $complaint_id, $role) {
    if (!in_array($role, ['complainant', 'respondent'], true)) return [];

    $forms = [];

    $cs = mysqli_prepare($conn, "SELECT reference_number, status, kp16_pdf_path, kp16_generated_at,
                                        kp20_pdf_path, kp20_generated_at
                                 FROM complaints WHERE id = ?");
    mysqli_stmt_bind_param($cs, "i", $complaint_id);
    mysqli_stmt_execute($cs);
    $c = mysqli_fetch_assoc(mysqli_stmt_get_result($cs));
    mysqli_stmt_close($cs);
    if (!$c) return [];

    // ── KP #8 / KP #9 per mediation hearing ──
    $ms = mysqli_prepare($conn,
        "SELECT id, session_number, hearing_date, hearing_time,
                kp8_complainant_pdf_path, kp8_complainant_issued_at,
                kp9_respondent_pdf_path,  kp9_respondent_issued_at
           FROM complaint_hearings
          WHERE complaint_id = ? AND session_type = 'mediation'
          ORDER BY session_number ASC, id ASC");
    mysqli_stmt_bind_param($ms, "i", $complaint_id);
    mysqli_stmt_execute($ms);
    $mr = mysqli_stmt_get_result($ms);
    while ($h = mysqli_fetch_assoc($mr)) {
        $sessionNo = (int)$h['session_number'];
        $hDate = $h['hearing_date'] ? date('M j, Y', strtotime($h['hearing_date'])) : '—';
        $hTime = $h['hearing_time'] ? date('g:i A', strtotime($h['hearing_time'])) : '';

        if ($role === 'complainant' && !empty($h['kp8_complainant_pdf_path'])) {
            $forms[] = [
                'form_tag'   => 'kp8',
                'form_no'    => 8,
                'form_name'  => 'Notice of Hearing (Mediation)',
                'issued_at'  => $h['kp8_complainant_issued_at'],
                'hearing_no' => $sessionNo,
                'event'      => "Mediation Hearing #{$sessionNo} on {$hDate}" . ($hTime ? " at {$hTime}" : ''),
                'pdf_path'   => $h['kp8_complainant_pdf_path'],
            ];
        }
        if ($role === 'respondent' && !empty($h['kp9_respondent_pdf_path'])) {
            $forms[] = [
                'form_tag'   => 'kp9',
                'form_no'    => 9,
                'form_name'  => 'Summons',
                'issued_at'  => $h['kp9_respondent_issued_at'],
                'hearing_no' => $sessionNo,
                'event'      => "Mediation Hearing #{$sessionNo} on {$hDate}" . ($hTime ? " at {$hTime}" : ''),
                'pdf_path'   => $h['kp9_respondent_pdf_path'],
            ];
        }
    }
    mysqli_stmt_close($ms);

    // ── KP #12 conciliation — role-specific copy ──
    $chStmt = mysqli_prepare($conn,
        "SELECT id, session_number, hearing_date, hearing_time,
                kp12_complainant_pdf_path, kp12_complainant_issued_at,
                kp12_respondent_pdf_path,  kp12_respondent_issued_at
           FROM complaint_hearings
          WHERE complaint_id = ? AND session_type = 'conciliation'
          ORDER BY session_number ASC, id ASC");
    mysqli_stmt_bind_param($chStmt, "i", $complaint_id);
    mysqli_stmt_execute($chStmt);
    $chRes = mysqli_stmt_get_result($chStmt);
    while ($h = mysqli_fetch_assoc($chRes)) {
        $sessionNo = (int)$h['session_number'];
        $hDate = $h['hearing_date'] ? date('M j, Y', strtotime($h['hearing_date'])) : '—';
        $hTime = $h['hearing_time'] ? date('g:i A', strtotime($h['hearing_time'])) : '';

        if ($role === 'complainant' && !empty($h['kp12_complainant_pdf_path'])) {
            $forms[] = [
                'form_tag'   => 'kp12',
                'form_no'    => 12,
                'form_name'  => 'Notice of Hearing (Conciliation)',
                'issued_at'  => $h['kp12_complainant_issued_at'],
                'hearing_no' => $sessionNo,
                'event'      => "Conciliation Hearing #{$sessionNo} before the Pangkat on {$hDate}" . ($hTime ? " at {$hTime}" : ''),
                'pdf_path'   => $h['kp12_complainant_pdf_path'],
            ];
        }
        if ($role === 'respondent' && !empty($h['kp12_respondent_pdf_path'])) {
            $forms[] = [
                'form_tag'   => 'kp12',
                'form_no'    => 12,
                'form_name'  => 'Notice of Hearing (Conciliation)',
                'issued_at'  => $h['kp12_respondent_issued_at'],
                'hearing_no' => $sessionNo,
                'event'      => "Conciliation Hearing #{$sessionNo} before the Pangkat on {$hDate}" . ($hTime ? " at {$hTime}" : ''),
                'pdf_path'   => $h['kp12_respondent_pdf_path'],
            ];
        }
    }
    mysqli_stmt_close($chStmt);

    // ── KP #16 ──
    if (!empty($c['kp16_pdf_path'])) {
        $forms[] = [
            'form_tag'  => 'kp16',
            'form_no'   => 16,
            'form_name' => 'Amicable Settlement',
            'issued_at' => $c['kp16_generated_at'],
            'event'     => 'Agreement reached between the parties.',
            'pdf_path'  => $c['kp16_pdf_path'],
        ];
    }

    // ── KP #20 ──
    if (!empty($c['kp20_pdf_path']) && $c['status'] === 'certificate_issued') {
        $forms[] = [
            'form_tag'  => 'kp20',
            'form_no'   => 20,
            'form_name' => 'Certification to File Action',
            'issued_at' => $c['kp20_generated_at'],
            'event'     => 'Issued because mediation did not result in a settlement.',
            'pdf_path'  => $c['kp20_pdf_path'],
        ];
    }

    return $forms;
}

/* ============================================================
   RESIDENT: Submit complaint
   ============================================================ */
if ($action === 'submit_complaint' && $user_type === 'resident') {

    $title              = mysqli_real_escape_string($conn, $_POST['title'] ?? '');
    $description        = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
    $complaint_subject  = mysqli_real_escape_string($conn, $_POST['complaint_subject'] ?? 'Other Concern');
    $priority           = mysqli_real_escape_string($conn, $_POST['priority'] ?? 'medium');
    $additional_notes   = mysqli_real_escape_string($conn, $_POST['additional_notes'] ?? '');

    $escalation_type     = mysqli_real_escape_string($conn, $_POST['escalation_type']     ?? '');
    $escalation_refer_to = mysqli_real_escape_string($conn, $_POST['escalation_refer_to'] ?? '');
    $escalation_note     = mysqli_real_escape_string($conn, $_POST['escalation_note']     ?? '');
    $is_escalated        = $escalation_type !== '' ? 1 : 0;

    $is_anonymous = isset($_POST['is_anonymous']) ? intval($_POST['is_anonymous']) : 0;

    $complainants = json_decode($_POST['complainants'] ?? '[]', true) ?: [];
    $respondents  = json_decode($_POST['respondents']  ?? '[]', true) ?: [];
    $witnesses    = json_decode($_POST['witnesses']    ?? '[]', true) ?: [];
    $incidents    = json_decode($_POST['incidents']    ?? '[]', true) ?: [];

    if ($title === '' || $description === '') {
        echo json_encode(['success' => false, 'message' => 'Title and description required']);
        exit;
    }

    $typeMap = [
        'Lending/Utang Issue' => 'dispute','Property/Boundary Issue' => 'boundary',
        'Neighbor Dispute' => 'dispute','Noise Complaint' => 'nuisance',
        'Waste Management' => 'nuisance','Animal Complaint' => 'nuisance',
        'Road/Infrastructure' => 'other','Physical Altercation' => 'other',
        'Threat/Intimidation' => 'other','Verbal Abuse' => 'other',
        'Scandal' => 'nuisance','Theft/Robbery' => 'property',
        'Cyber Libel' => 'other','Child Protection Concern' => 'domestic',
        'Violence Against Women (VAWC)' => 'domestic','Illegal Gambling' => 'other',
        'Drug Related Issue' => 'other','Illegal Vendors' => 'other',
        'Abandoned Vehicle' => 'nuisance','Obstruction on Public Way' => 'nuisance',
        'Illegal Structure' => 'property','Water Supply Issue' => 'other',
        'Electrical Problem' => 'other','Zoning Violation' => 'property',
        'Health Concern' => 'other','Calamity Assistance' => 'other',
        'Social Services Concern' => 'other','Employment/Livelihood Issue' => 'other',
        'Education Assistance' => 'other','Medical Assistance' => 'other',
        'Death/Burial Assistance' => 'other','Senior Citizen Concern' => 'other',
        'PWD Concern' => 'other','Solo Parent Concern' => 'other','Other Concern' => 'other',
    ];
    $complaint_type = $typeMap[$complaint_subject] ?? 'other';

    $reference_number = generateReferenceNumber();
    $has_evidence     = 0;
    foreach ($_FILES as $k => $f) {
        if (strpos($k, 'incident_evidence_') === 0) { $has_evidence = 1; break; }
    }

    $initial_status = $is_escalated ? 'escalated' : 'pending_review';

    $sql = "INSERT INTO complaints
            (reference_number, title, description, complaint_type, complaint_subject,
             status, priority, created_by, has_evidence, is_anonymous,
             escalation_type, escalation_refer_to, escalation_notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "sssssssiiisss",
        $reference_number, $title, $description, $complaint_type, $complaint_subject,
        $initial_status, $priority, $user_id, $has_evidence, $is_anonymous,
        $escalation_type, $escalation_refer_to, $escalation_note);

    if (!mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => false, 'message' => 'Failed to submit: ' . mysqli_error($conn)]);
        exit;
    }
    $complaint_id = mysqli_insert_id($conn);

    // Complainants
    foreach ($complainants as $p) {
        if (empty($p['full_name'])) continue;
        $name    = mysqli_real_escape_string($conn, $p['full_name']);
        $addr    = mysqli_real_escape_string($conn, $p['address']        ?? '');
        $contact = mysqli_real_escape_string($conn, $p['contact_number'] ?? '');
        $email   = mysqli_real_escape_string($conn, $p['email']          ?? '');

        $matched = null;
        if ($email !== '') {
            $ms = mysqli_prepare($conn, "SELECT id FROM resident WHERE LOWER(email) = LOWER(?) AND is_active = 1 LIMIT 1");
            mysqli_stmt_bind_param($ms, "s", $email);
            mysqli_stmt_execute($ms);
            $matched = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($ms))['id'] ?? 0);
            mysqli_stmt_close($ms);
        }
        if (!$matched && $contact !== '') {
            $ms = mysqli_prepare($conn, "SELECT id FROM resident WHERE (phone=? OR phone_number=?) AND is_active=1 LIMIT 1");
            mysqli_stmt_bind_param($ms, "ss", $contact, $contact);
            mysqli_stmt_execute($ms);
            $matched = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($ms))['id'] ?? 0);
            mysqli_stmt_close($ms);
        }

        $s = mysqli_prepare($conn, "INSERT INTO complaint_parties
                (complaint_id, party_type, full_name, address, contact_number, email, matched_resident_id)
                VALUES (?, 'complainant', ?, ?, ?, ?, ?)");
        $matchedVal = $matched ?: null;
        mysqli_stmt_bind_param($s, "issssi", $complaint_id, $name, $addr, $contact, $email, $matchedVal);
        mysqli_stmt_execute($s);
    }

    // Respondents
    foreach ($respondents as $p) {
        if (empty($p['full_name'])) continue;
        $name    = mysqli_real_escape_string($conn, $p['full_name']);
        $addr    = mysqli_real_escape_string($conn, $p['address']        ?? '');
        $contact = mysqli_real_escape_string($conn, $p['contact_number'] ?? '');
        $rel     = mysqli_real_escape_string($conn, $p['relationship']   ?? '');

        $matched = null;
        if ($contact !== '') {
            $ms = mysqli_prepare($conn, "SELECT id FROM resident WHERE (phone=? OR phone_number=?) AND is_active=1 LIMIT 1");
            mysqli_stmt_bind_param($ms, "ss", $contact, $contact);
            mysqli_stmt_execute($ms);
            $matched = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($ms))['id'] ?? 0);
            mysqli_stmt_close($ms);
        }
        if (!$matched && $name !== '') {
            $parts = preg_split('/\s+/', trim($name));
            if (count($parts) >= 2) {
                $first = $parts[0]; $last = end($parts);
                $ms = mysqli_prepare($conn,
                    "SELECT id FROM resident WHERE LOWER(first_name)=LOWER(?) AND LOWER(last_name)=LOWER(?) AND is_active=1 LIMIT 1");
                mysqli_stmt_bind_param($ms, "ss", $first, $last);
                mysqli_stmt_execute($ms);
                $matched = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($ms))['id'] ?? 0);
                mysqli_stmt_close($ms);
            }
        }

        $s = mysqli_prepare($conn, "INSERT INTO complaint_parties
                (complaint_id, party_type, full_name, address, contact_number, relationship_to_complainant, matched_resident_id)
                VALUES (?, 'respondent', ?, ?, ?, ?, ?)");
        $matchedVal = $matched ?: null;
        mysqli_stmt_bind_param($s, "issssi", $complaint_id, $name, $addr, $contact, $rel, $matchedVal);
        mysqli_stmt_execute($s);
    }

    // Witnesses
    foreach ($witnesses as $p) {
        if (empty($p['full_name'])) continue;
        $name    = mysqli_real_escape_string($conn, $p['full_name']);
        $addr    = mysqli_real_escape_string($conn, $p['address']        ?? '');
        $contact = mysqli_real_escape_string($conn, $p['contact_number'] ?? '');
        $s = mysqli_prepare($conn, "INSERT INTO complaint_parties
                (complaint_id, party_type, full_name, address, contact_number)
                VALUES (?, 'witness', ?, ?, ?)");
        mysqli_stmt_bind_param($s, "isss", $complaint_id, $name, $addr, $contact);
        mysqli_stmt_execute($s);
    }

    // Incidents
    foreach ($incidents as $inc) {
        $date = !empty($inc['incident_date']) ? $inc['incident_date'] : null;
        $time = !empty($inc['incident_time']) ? $inc['incident_time'] : null;
        $loc  = mysqli_real_escape_string($conn, $inc['location']    ?? '');
        $desc = mysqli_real_escape_string($conn, $inc['description'] ?? '');
        $s = mysqli_prepare($conn, "INSERT INTO complaint_incidents
                (complaint_id, incident_date, incident_time, location, description)
                VALUES (?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($s, "issss", $complaint_id, $date, $time, $loc, $desc);
        mysqli_stmt_execute($s);
    }

    // Evidence
    $upload_dir = __DIR__ . '/../uploads/complaint_evidence/';
    if (!file_exists($upload_dir)) @mkdir($upload_dir, 0777, true);

    foreach ($_FILES as $key => $files) {
        if (strpos($key, 'incident_evidence_') !== 0) continue;
        if (!is_array($files['name'])) continue;
        $idx = (int) filter_var($key, FILTER_SANITIZE_NUMBER_INT);
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
            $orig = $files['name'][$i];
            $safe = time() . '_' . $idx . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $orig);
            $rel  = 'uploads/complaint_evidence/' . $safe;
            if (move_uploaded_file($files['tmp_name'][$i], $upload_dir . $safe)) {
                $origEsc = mysqli_real_escape_string($conn, $orig);
                $type    = $files['type'][$i];
                $desc    = 'Evidence for Incident ' . ($idx + 1);
                $s = mysqli_prepare($conn, "INSERT INTO complaint_evidence
                        (complaint_id, file_name, file_path, file_type, description, uploaded_by)
                        VALUES (?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($s, "issssi",
                    $complaint_id, $origEsc, $rel, $type, $desc, $user_id);
                mysqli_stmt_execute($s);
            }
        }
    }

    // Timeline
    if ($is_escalated) {
        $note = 'ESCALATED CASE — NOT under Katarungang Pambarangay. ' . $escalation_note;
    } else {
        $note = 'Complaint submitted and pending review';
        if ($additional_notes) $note .= '. Notes: ' . $additional_notes;
    }
    if ($is_anonymous === 1) $note .= ' [Filed Anonymously]';

    $s = mysqli_prepare($conn, "INSERT INTO complaint_updates
            (complaint_id, update_type, notes, updated_by, updated_by_role)
            VALUES (?, 'status_change', ?, NULL, 'resident')");
    mysqli_stmt_bind_param($s, "is", $complaint_id, $note);
    mysqli_stmt_execute($s);

    // Notify secretaries
    if ($is_escalated) {
        $notifTitle = 'ESCALATED Case Filed';
        $notifMsg   = "A non-jurisdictional case (#{$reference_number}) was filed — Type: {$escalation_type}. Refer to: {$escalation_refer_to}. Prepare Referral Letter. NO MEDIATION.";
        $notifType  = 'error';
    } else {
        $notifTitle = 'New Complaint Filed';
        $notifMsg   = "A new complaint (#{$reference_number}) was filed and is pending your review.";
        $notifType  = 'warning';
    }
    if ($is_anonymous === 1) $notifTitle .= ' [ANONYMOUS]';

    $secQ = mysqli_query($conn, "SELECT id FROM admin WHERE admin_role = 'secretary' AND is_active = 1");
    if ($secQ) {
        while ($sec = mysqli_fetch_assoc($secQ)) {
            $sec_id = (int)$sec['id'];
            $nSql = "INSERT INTO notifications
                        (user_type, user_id, resident_id, type, title, message, reference_id, reference_type)
                     VALUES ('admin', ?, NULL, ?, ?, ?, ?, 'complaint')";
            $nStmt = @mysqli_prepare($conn, $nSql);
            if ($nStmt) {
                mysqli_stmt_bind_param($nStmt, "isssi",
                    $sec_id, $notifType, $notifTitle, $notifMsg, $complaint_id);
                mysqli_stmt_execute($nStmt);
                mysqli_stmt_close($nStmt);
            }
            if (function_exists('pushToUser')) {
                pushToUser($conn, $sec_id, $notifTitle, $notifMsg,
                    '/BRITE/admin/dashboards/secretary_dashboard.php');
            }
        }
    }

    echo json_encode([
        'success'          => true,
        'message'          => $is_escalated ? 'Escalated case recorded successfully.' : 'Complaint submitted successfully!',
        'reference_number' => $reference_number,
        'complaint_id'     => $complaint_id,
        'status'           => $initial_status,
        'is_escalated'     => (bool)$is_escalated,
        'is_anonymous'     => (bool)$is_anonymous
    ]);
    exit;
}

/* ============================================================
   RESIDENT: Get my complaints — role-aware
   ============================================================ */
if ($action === 'get_my_complaints' && $user_type === 'resident') {

    // ── As Complainant (creator) ──
    $sql = "SELECT c.*,
              (SELECT CONCAT(full_name,' (',admin_role,')') FROM admin WHERE id = c.assigned_to) AS assigned_to_name,
              'complainant' AS my_role
            FROM complaints c
            WHERE c.created_by = ?
            ORDER BY c.created_at DESC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $asComplainant = [];
    while ($row = mysqli_fetch_assoc($result)) $asComplainant[] = $row;
    mysqli_stmt_close($stmt);

    // ── As Respondent (listed + captain already acted) ──
    $sql2 = "SELECT c.*,
              (SELECT CONCAT(full_name,' (',admin_role,')') FROM admin WHERE id = c.assigned_to) AS assigned_to_name,
              'respondent' AS my_role,
              cp.id AS respondent_party_id,
              cp.matched_resident_id
            FROM complaints c
            JOIN complaint_parties cp
              ON cp.complaint_id = c.id
             AND cp.party_type = 'respondent'
             AND cp.matched_resident_id = ?
            WHERE c.status NOT IN ('pending_review', 'pending_captain_action', 'escalated')
            ORDER BY c.created_at DESC";
    $stmt2 = mysqli_prepare($conn, $sql2);
    mysqli_stmt_bind_param($stmt2, "i", $user_id);
    mysqli_stmt_execute($stmt2);
    $result2 = mysqli_stmt_get_result($stmt2);
    $asRespondent = [];
    while ($row = mysqli_fetch_assoc($result2)) {
        if (!respondentCanSeeStatus($row['status'])) continue;
        $asRespondent[] = $row;
    }
    mysqli_stmt_close($stmt2);

    $all = array_merge($asComplainant, $asRespondent);
    usort($all, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    echo json_encode([
        'success'         => true,
        'complaints'      => $all,
        'as_complainant'  => $asComplainant,
        'as_respondent'   => $asRespondent,
        'counts'          => [
            'complainant' => count($asComplainant),
            'respondent'  => count($asRespondent),
            'total'       => count($all),
        ]
    ]);
    exit;
}

/* ============================================================
   RESIDENT: Get one complaint with full details — ROLE-AWARE
   ============================================================ */
if ($action === 'get_complaint_details' && $user_type === 'resident') {
    $complaint_id = (int)($_GET['id'] ?? 0);

    $stmt = mysqli_prepare($conn, "SELECT c.*,
              (SELECT CONCAT(full_name,' (',admin_role,')') FROM admin WHERE id = c.assigned_to) AS assigned_to_name
            FROM complaints c WHERE c.id = ?");
    mysqli_stmt_bind_param($stmt, "i", $complaint_id);
    mysqli_stmt_execute($stmt);
    $complaint = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$complaint) { echo json_encode(['success' => false, 'message' => 'Not found']); exit; }

    $role = getResidentRoleInComplaint($conn, $complaint_id, $user_id);
    $isCreator = ((int)$complaint['created_by'] === (int)$user_id);
    $isComplainant = ($isCreator || $role === 'complainant');
    $isRespondent  = ($role === 'respondent');

    if (!$isComplainant && !$isRespondent) {
        echo json_encode(['success' => false, 'message' => 'Not allowed']);
        exit;
    }

    if ($isRespondent && !$isComplainant) {
        if (!respondentCanSeeStatus($complaint['status'])) {
            echo json_encode(['success' => false, 'message' => 'This complaint is not yet available to view.']);
            exit;
        }
    }

    $hideSensitiveForRespondent = ($isRespondent && !$isComplainant && isSensitiveStatus($complaint['status']));
    $effectiveRole = $isComplainant ? 'complainant' : 'respondent';

    $out = [
        'success'   => true,
        'complaint' => $complaint,
        'my_role'   => $effectiveRole,
    ];

    // Parties
    $out['complainants'] = $out['respondents'] = $out['witnesses'] = [];
    $s = mysqli_prepare($conn, "SELECT * FROM complaint_parties WHERE complaint_id = ? ORDER BY id");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    while ($row = mysqli_fetch_assoc($r)) {
        if ($row['party_type'] === 'complainant')    $out['complainants'][] = $row;
        elseif ($row['party_type'] === 'respondent') $out['respondents'][]  = $row;
        else                                         $out['witnesses'][]    = $row;
    }
    mysqli_stmt_close($s);

    // Incidents
    $out['incidents'] = [];
    $s = mysqli_prepare($conn, "SELECT * FROM complaint_incidents WHERE complaint_id = ? ORDER BY incident_date, id");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    while ($row = mysqli_fetch_assoc($r)) $out['incidents'][] = $row;
    mysqli_stmt_close($s);

    // Evidence
    $out['evidence'] = [];
    if (!$hideSensitiveForRespondent) {
        $s = mysqli_prepare($conn, "SELECT * FROM complaint_evidence WHERE complaint_id = ? ORDER BY uploaded_at DESC");
        mysqli_stmt_bind_param($s, "i", $complaint_id);
        mysqli_stmt_execute($s);
        $r = mysqli_stmt_get_result($s);
        while ($row = mysqli_fetch_assoc($r)) $out['evidence'][] = $row;
        mysqli_stmt_close($s);
    }

    // AUDIT TRAIL — HIDDEN FROM BOTH ROLES
    $out['updates'] = [];

    // Hearings — date/time/location/status/outcome ONLY
    $out['hearings'] = [];
    $s = mysqli_prepare($conn,
        "SELECT id, session_type, session_number, hearing_date, hearing_time,
                location, status, outcome
           FROM complaint_hearings
          WHERE complaint_id = ?
          ORDER BY hearing_date DESC, hearing_time DESC, id DESC");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    while ($row = mysqli_fetch_assoc($r)) $out['hearings'][] = $row;
    mysqli_stmt_close($s);

    // KP forms — role-filtered
    $out['kp_forms'] = buildResidentKpForms($conn, $complaint_id, $effectiveRole);

    echo json_encode($out);
    exit;
}

/* ============================================================
   Add a note
   ============================================================ */
if ($action === 'add_complaint_note') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $note         = mysqli_real_escape_string($conn, $_POST['note'] ?? '');

    if (!$complaint_id || $note === '') {
        echo json_encode(['success' => false, 'message' => 'Missing fields']);
        exit;
    }

    if ($user_type === 'resident') {
        $chk = mysqli_prepare($conn, "SELECT id FROM complaints WHERE id = ? AND created_by = ?");
        mysqli_stmt_bind_param($chk, "ii", $complaint_id, $user_id);
        mysqli_stmt_execute($chk);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($chk))) {
            echo json_encode(['success' => false, 'message' => 'Not allowed']);
            exit;
        }
        $s = mysqli_prepare($conn, "INSERT INTO complaint_updates
              (complaint_id, update_type, notes, updated_by, updated_by_role)
              VALUES (?, 'note', ?, NULL, 'resident')");
        mysqli_stmt_bind_param($s, "is", $complaint_id, $note);
    } else {
        $role = $_SESSION['admin_role'] ?? 'admin';
        $s = mysqli_prepare($conn, "INSERT INTO complaint_updates
              (complaint_id, update_type, notes, updated_by, updated_by_role)
              VALUES (?, 'note', ?, ?, ?)");
        mysqli_stmt_bind_param($s, "isis", $complaint_id, $note, $user_id, $role);
    }

    if (mysqli_stmt_execute($s)) echo json_encode(['success' => true, 'message' => 'Note added']);
    else                          echo json_encode(['success' => false, 'message' => 'Failed']);
    exit;
}

/* ============================================================
   Upload evidence
   ============================================================ */
if ($action === 'upload_evidence' && isset($_FILES['evidence_file'])) {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $description  = mysqli_real_escape_string($conn, $_POST['description'] ?? '');

    $dir = __DIR__ . '/../uploads/complaint_evidence/';
    if (!file_exists($dir)) @mkdir($dir, 0777, true);

    $f    = $_FILES['evidence_file'];
    $name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $f['name']);
    $rel  = 'uploads/complaint_evidence/' . $name;

    if (move_uploaded_file($f['tmp_name'], $dir . $name)) {
        $orig = mysqli_real_escape_string($conn, $f['name']);
        $s = mysqli_prepare($conn, "INSERT INTO complaint_evidence
              (complaint_id, file_name, file_path, file_type, description, uploaded_by)
              VALUES (?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($s, "issssi", $complaint_id, $orig, $rel, $f['type'], $description, $user_id);
        echo json_encode(['success' => mysqli_stmt_execute($s)]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Upload failed']);
    }
    exit;
}

/* ============================================================
   RESIDENT: Cancel my complaint
   ============================================================ */
if ($action === 'cancel_complaint' && $user_type === 'resident') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing id']); exit; }

    $s = mysqli_prepare($conn, "SELECT id, status, reference_number FROM complaints WHERE id = ? AND created_by = ?");
    mysqli_stmt_bind_param($s, "ii", $complaint_id, $user_id);
    mysqli_stmt_execute($s);
    $c = mysqli_fetch_assoc(mysqli_stmt_get_result($s));

    if (!$c) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }

    if (!in_array($c['status'], ['pending_review','summoned'])) {
        echo json_encode([
            'success'=>false,
            'message'=>'You can only cancel a complaint before mediation starts.'
        ]);
        exit;
    }

    $s = mysqli_prepare($conn, "INSERT INTO complaint_updates
        (complaint_id, update_type, previous_status, new_status, notes, updated_by, updated_by_role)
        VALUES (?, 'status_change', ?, 'dismissed', 'Complaint withdrawn by the complainant.', NULL, 'resident')");
    mysqli_stmt_bind_param($s, "is", $complaint_id, $c['status']);
    mysqli_stmt_execute($s);

    $s = mysqli_prepare($conn, "UPDATE complaints SET status='dismissed' WHERE id=?");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);

    $nTitle = 'Complaint Withdrawn';
    $nMsg   = "Complaint #{$c['reference_number']} was withdrawn by the complainant.";
    $q = mysqli_query($conn, "SELECT id FROM admin WHERE admin_role='secretary' AND is_active=1");
    while ($row = mysqli_fetch_assoc($q)) {
        $aid = (int)$row['id'];
        $ins = mysqli_prepare($conn, "INSERT INTO notifications
                (user_type, user_id, resident_id, type, title, message, reference_id, reference_type)
                VALUES ('admin', ?, NULL, 'info', ?, ?, ?, 'complaint')");
        mysqli_stmt_bind_param($ins, "issi", $aid, $nTitle, $nMsg, $complaint_id);
        mysqli_stmt_execute($ins);
    }

    echo json_encode(['success'=>true,'message'=>'Complaint withdrawn']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);