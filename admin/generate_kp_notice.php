<?php
// admin/generate_kp_notice.php
// ============================================================
//  Katarungang Pambarangay — Form Generator & Storage
//
//  Covered in this file (KP #8 through KP #27):
//  • KP #8  → Notice of Hearing (Mediation — complainant) — PER HEARING
//  • KP #9  → Summons (respondent) — PER HEARING
//  • KP #10 → Notice for Constitution of Pangkat (both)
//  • KP #11 → Notice to Chosen Pangkat Member (each member)
//  • KP #12 → Notice of Hearing (Conciliation Proceedings) — PER HEARING
//  • KP #13 → Subpoena
//  • KP #14 → Agreement for Arbitration
//  • KP #15 → Arbitration Award
//  • KP #16 → Amicable Settlement (2 modes: mediation / conciliation)
//  • KP #17 → Repudiation
//  • KP #18 → Notice of Hearing for Complainant (Re: Failure to Appear)
//  • KP #19 → Notice of Hearing for Respondent (Re: Failure to Appear)
//  • KP #20 → Certification to File Action (from Lupon Secretary)
//  • KP #21 → Certification to File Action (from Pangkat Secretary)
//  • KP #22 → Certification to File Action (from Pangkat Chairman)
//  • KP #23 → Certification to Bar Action
//  • KP #24 → Certification to Bar Counterclaim
//  • KP #25 → Motion for Execution
//  • KP #26 → Notice of Hearing (Re: Motion for Execution)
//  • KP #27 → Notice of Execution
//
//  Reference: RA 7160 §408–422; KP Handbook pp. 45–46, 52–54, 57–58, 108–109, 112.
// ============================================================
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

$IS_INTERNAL = defined('KP_NOTICE_INTERNAL');

if (!$IS_INTERNAL) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

if (!$IS_INTERNAL) {
    if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    $admin_id = $_SESSION['user_id'];
    $s = mysqli_prepare($conn, "SELECT admin_role, full_name FROM admin WHERE id = ?");
    mysqli_stmt_bind_param($s, "i", $admin_id);
    mysqli_stmt_execute($s);
    $adminRow = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    if (!$adminRow || !in_array($adminRow['admin_role'], ['captain', 'secretary', 'lupon'], true)) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Not allowed to generate KP notices.']);
        exit;
    }
}

/* ============================================================
   Barangay constants
   ============================================================ */
if (!defined('KP_BARANGAY_NAME'))   define('KP_BARANGAY_NAME',  'BARANGAY SAN BARTOLOME');
if (!defined('KP_MUNICIPALITY'))    define('KP_MUNICIPALITY',   'Municipality of Sto. Tomas');
if (!defined('KP_PROVINCE'))        define('KP_PROVINCE',       'Province of Pampanga');
if (!defined('KP_UPLOAD_SUBDIR'))   define('KP_UPLOAD_SUBDIR',  'uploads/kp_notices');

if (!defined('KP_FALLBACK_CAPTAIN_NAME'))   define('KP_FALLBACK_CAPTAIN_NAME',   'HON. BARANGAY CAPTAIN');
if (!defined('KP_FALLBACK_SECRETARY_NAME')) define('KP_FALLBACK_SECRETARY_NAME', 'BARANGAY SECRETARY');

/* ============================================================
   Helper: fetch the active Captain / Secretary row from admin
   ============================================================ */
function kp_get_officer($conn, $role, $preferred_id = 0) {
    $role = ($role === 'captain') ? 'captain' : 'secretary';
    $row  = null;

    if ($preferred_id > 0) {
        $s = mysqli_prepare($conn,
            "SELECT id, full_name, signature_path
             FROM admin
             WHERE id = ? AND admin_role = ? AND is_active = 1
             LIMIT 1");
        mysqli_stmt_bind_param($s, "is", $preferred_id, $role);
        mysqli_stmt_execute($s);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        mysqli_stmt_close($s);
    }

    if (!$row) {
        $s = mysqli_prepare($conn,
            "SELECT id, full_name, signature_path
             FROM admin
             WHERE admin_role = ? AND is_active = 1
             ORDER BY id ASC
             LIMIT 1");
        mysqli_stmt_bind_param($s, "s", $role);
        mysqli_stmt_execute($s);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        mysqli_stmt_close($s);
    }

    return $row ?: null;
}

/* ============================================================
   Helper: fetch the Pangkat Chairperson / Secretary
   ============================================================ */
function kp_get_pangkat_officer($conn, $complaint_id, $role) {
    $role = ($role === 'chair') ? 'chair' : 'secretary';

    $s = mysqli_prepare($conn,
        "SELECT a.id, a.full_name, a.signature_path
           FROM complaint_pangkat cp
           JOIN admin a ON a.id = cp.member_id
          WHERE cp.complaint_id = ? AND cp.role = ?
          LIMIT 1");
    mysqli_stmt_bind_param($s, "is", $complaint_id, $role);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);

    return $row ?: null;
}

function kp_draw_signature($pdf, $signature_rel_path) {
    if (empty($signature_rel_path)) return;
    $abs = __DIR__ . '/../' . $signature_rel_path;
    if (!file_exists($abs)) return;
    try {
        $pdf->Image($abs, '', '', 45, 0, '', '', '', false, 300, '', false, false, 0);
    } catch (Exception $e) {}
}

function kp_render_header_block($pdf) {
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 5, 'Republic of the Philippines', 0, 1, 'C');
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 5, KP_PROVINCE,    0, 1, 'C');
    $pdf->Cell(0, 5, KP_MUNICIPALITY,0, 1, 'C');
    $pdf->SetFont('times', 'B', 13);
    $pdf->Cell(0, 6, KP_BARANGAY_NAME, 0, 1, 'C');
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 5, 'OFFICE OF THE LUPONG TAGAPAMAYAPA', 0, 1, 'C');
    $pdf->Ln(3);
    $pdf->SetDrawColor(46, 125, 50);
    $pdf->SetLineWidth(0.6);
    $pdf->Line(18, $pdf->GetY(), 192, $pdf->GetY());
    $pdf->Ln(6);
}

function kp_render_signature_block($pdf, $captainName, $captainSignature, $secretaryName, $secretarySignature) {
    $pdf->Ln(8);

    if (!empty($captainSignature)) {
        kp_draw_signature($pdf, $captainSignature);
        $pdf->Ln(1);
    } else {
        $pdf->Ln(12);
    }

    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, strtoupper($captainName), 0, 1, 'R');
    $pdf->SetFont('times', 'I', 10);
    $pdf->Cell(0, 5, 'Punong Barangay / Lupon Chairman', 0, 1, 'R');

    $pdf->Ln(8);
    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(0, 5, 'ATTESTED:', 0, 1);

    if (!empty($secretarySignature)) {
        kp_draw_signature($pdf, $secretarySignature);
        $pdf->Ln(1);
    } else {
        $pdf->Ln(12);
    }

    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 5, strtoupper($secretaryName), 0, 1);
    $pdf->Cell(0, 5, 'Barangay / Lupon Secretary', 0, 1);
}

function kp_render_footer($pdf) {
    $pdf->Ln(6);
    $pdf->SetFont('times', 'I', 8);
    $pdf->SetTextColor(120);
    $pdf->MultiCell(0, 4,
        'This document is issued pursuant to Republic Act No. 7160 (Local Government Code of 1991), Sections 408–422. '
        . 'Generated by BRITE on ' . date('F j, Y g:i A') . '.',
        0, 'C'
    );
    $pdf->SetTextColor(0);
}

/* ============================================================
   Helper: load complaint + parties payload
   ============================================================ */
function kp_load_complaint($conn, $complaint_id) {
    $cs = mysqli_prepare($conn,
        "SELECT id, reference_number, title, complaint_subject, description,
                hearing_date, hearing_time, hearing_location, mediation_session_count,
                assigned_to, status
         FROM complaints WHERE id = ?");
    mysqli_stmt_bind_param($cs, "i", $complaint_id);
    mysqli_stmt_execute($cs);
    $complaint = mysqli_fetch_assoc(mysqli_stmt_get_result($cs));
    mysqli_stmt_close($cs);
    return $complaint;
}

function kp_load_parties($conn, $complaint_id) {
    $ps = mysqli_prepare($conn,
        "SELECT id, party_type, full_name, address, contact_number, matched_resident_id
         FROM complaint_parties
         WHERE complaint_id = ?
         ORDER BY FIELD(party_type,'complainant','respondent','witness'), id");
    mysqli_stmt_bind_param($ps, "i", $complaint_id);
    mysqli_stmt_execute($ps);
    $pr = mysqli_stmt_get_result($ps);
    $complainants = []; $respondents = []; $witnesses = [];
    while ($row = mysqli_fetch_assoc($pr)) {
        if ($row['party_type'] === 'complainant')    $complainants[] = $row;
        elseif ($row['party_type'] === 'respondent') $respondents[]  = $row;
        else                                          $witnesses[]    = $row;
    }
    mysqli_stmt_close($ps);
    return [$complainants, $respondents, $witnesses];
}

/* ============================================================
   Generic single-page KP PDF bootstrap
   ============================================================ */
function kp_new_pdf($title, $authorName) {
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('BRITE — ' . KP_BARANGAY_NAME);
    $pdf->SetAuthor($authorName);
    $pdf->SetTitle($title);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(18, 15, 18);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();
    kp_render_header_block($pdf);
    return $pdf;
}

function kp_standard_case_block($pdf, $complaint) {
    $refNo   = $complaint['reference_number'] ?? '';
    $subject = $complaint['complaint_subject'] ?? '';
    $title   = $complaint['title'] ?? '';
    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(35, 6, 'Barangay Case No.:', 0, 0);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(60, 6, $refNo, 0, 1);
    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(35, 6, 'For:', 0, 0);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 6, $subject ?: $title, 0, 1);
    $pdf->Ln(4);
}

function kp_parties_rows_html($complainants, $respondents) {
    $cNames = implode(', ', array_map(fn($p) => $p['full_name'] ?? '', $complainants));
    $rNames = implode(', ', array_map(fn($p) => $p['full_name'] ?? '', $respondents));
    return "<b>Complainant(s):</b> " . htmlspecialchars($cNames) . "<br><br>"
         . "<b>Respondent(s):</b> "  . htmlspecialchars($rNames);
}

/* ============================================================
   Core renderer — KP Form #8 / #9  (Mediation)
   ============================================================ */
function kp_render_notice(
    $conn,
    $complaint,
    $party_type,
    $recipient,
    $complainants,
    $respondents,
    $issuedByName
) {
    $refNo       = $complaint['reference_number'] ?? '';
    $title       = $complaint['title'] ?? '';
    $subject     = $complaint['complaint_subject'] ?? '';
    $hearingDate = $complaint['hearing_date'] ?? null;
    $hearingTime = $complaint['hearing_time'] ?? null;
    $hearingLoc  = $complaint['hearing_location'] ?? 'Barangay Hall';
    $sessionNo   = (int)($complaint['mediation_session_count'] ?? 1);

    $niceDate  = $hearingDate ? date('F j, Y', strtotime($hearingDate)) : '[DATE]';
    $niceTime  = $hearingTime ? date('g:i A', strtotime($hearingTime)) : '[TIME]';
    $dayOfWeek = $hearingDate ? date('l', strtotime($hearingDate)) : '';

    $issuedAt = KP_BARANGAY_NAME . ', ' . KP_MUNICIPALITY . ', ' . KP_PROVINCE;
    $recipientName = $recipient['full_name'] ?? '';

    $preferredCaptainId = (int)($complaint['assigned_to'] ?? 0);
    $captain   = kp_get_officer($conn, 'captain',   $preferredCaptainId);
    $secretary = kp_get_officer($conn, 'secretary');

    $captainName       = $captain['full_name']       ?? KP_FALLBACK_CAPTAIN_NAME;
    $captainSignature  = $captain['signature_path']  ?? null;
    $secretaryName     = $secretary['full_name']     ?? KP_FALLBACK_SECRETARY_NAME;
    $secretarySignature = $secretary['signature_path'] ?? null;

    $safeRef  = preg_replace('/[^A-Za-z0-9\-]/', '-', $refNo);
    $folder   = KP_UPLOAD_SUBDIR . '/' . ($party_type === 'complainant' ? 'complainant' : 'respondent');
    $absDir   = __DIR__ . '/../' . $folder;
    if (!file_exists($absDir)) @mkdir($absDir, 0777, true);

        $partyId  = (int)($recipient['id'] ?? 0);
    $formTag  = ($party_type === 'complainant') ? 'kp8' : 'kp9';
    $filename = $safeRef . '-' . $formTag . '-' . $party_type . '-' . $partyId . '.pdf';
    $absPath  = $absDir . '/' . $filename;

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('BRITE — ' . KP_BARANGAY_NAME);
    $pdf->SetAuthor($captainName);
    $pdf->SetTitle(
        $party_type === 'complainant'
            ? 'KP Form #8 — Notice of Hearing (Mediation Proceedings)'
            : 'KP Form #9 — Summons'
    );
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(18, 15, 18);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    kp_render_header_block($pdf);

    if ($party_type === 'complainant') {
        $pdf->SetFont('times', 'B', 15);
        $pdf->Cell(0, 8, 'NOTICE OF HEARING', 0, 1, 'C');
        $pdf->SetFont('times', 'I', 11);
        $pdf->Cell(0, 5, '(Mediation Proceedings — KP Form #8)', 0, 1, 'C');
    } else {
        $pdf->SetFont('times', 'B', 15);
        $pdf->Cell(0, 8, 'SUMMONS', 0, 1, 'C');
        $pdf->SetFont('times', 'I', 11);
        $pdf->Cell(0, 5, '(KP Form #9)', 0, 1, 'C');
    }
    $pdf->Ln(5);

    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(35, 6, 'Barangay Case No.:', 0, 0);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(60, 6, $refNo, 0, 1);

    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(35, 6, 'For:', 0, 0);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 6, $subject ?: $title, 0, 1);

    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(35, 6, 'Session No.:', 0, 0);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 6, (string)$sessionNo, 0, 1);

    $pdf->Ln(4);

    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, 'TO:', 0, 1);
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, strtoupper($recipientName), 0, 1);
    $pdf->SetFont('times', '', 10);
    if (!empty($recipient['address'])) {
        $pdf->MultiCell(0, 5, $recipient['address'], 0, 'L');
    }
    $pdf->Ln(3);

    $pdf->SetFont('times', '', 11);

    if ($party_type === 'complainant') {
        $body = "You are hereby required to appear before me/the Punong Barangay on <b>{$dayOfWeek}, {$niceDate}</b> at <b>{$niceTime}</b>, at the <b>{$hearingLoc}</b>, for the hearing of your complaint through <b>mediation</b> under the Katarungang Pambarangay (Republic Act No. 7160).";
        $pdf->writeHTMLCell(0, 0, '', '', $body, 0, 1, false, true, 'J');

        $pdf->Ln(3);
        $pdf->SetFont('times', 'B', 11);
        $pdf->Cell(0, 6, 'IMPORTANT REMINDERS:', 0, 1);
        $pdf->SetFont('times', '', 10);

        $rules = <<<HTML
<b>1.</b> You must appear <b>in person</b> on the scheduled date and time. Bring any witness or evidence you may have.<br><br>
<b>2.</b> If you, the <b>complainant</b>, fail to appear <b>without justifiable cause</b>:
<ul>
  <li>Your complaint will be <b>DISMISSED</b>;</li>
  <li>You will be <b>barred from filing a case in court</b> arising from the same complaint; and</li>
  <li>You may be <b>punished or reprimanded for indirect contempt</b> of court.</li>
</ul>
<b>3.</b> Mediation is a confidential, friendly, and non-adversarial process. No lawyer may appear on your behalf during mediation.<br><br>
<b>4.</b> Please come on time. If you cannot appear for a justifiable reason, notify this Office in writing <b>before</b> the scheduled hearing.
HTML;
        $pdf->writeHTMLCell(0, 0, '', '', $rules, 0, 1, false, true, 'J');
    } else {
        $body = "You are hereby <b>SUMMONED</b> to appear before me/the Punong Barangay <b>in person</b>, together with your witnesses, on <b>{$dayOfWeek}, {$niceDate}</b> at <b>{$niceTime}</b>, at the <b>{$hearingLoc}</b>, then and there to answer to a complaint made before me, a copy of which is attached hereto, for <b>mediation</b> of the dispute with the complainant under the Katarungang Pambarangay (Republic Act No. 7160).";
        $pdf->writeHTMLCell(0, 0, '', '', $body, 0, 1, false, true, 'J');

        $pdf->Ln(3);
        $pdf->SetFont('times', 'B', 11);
        $pdf->SetTextColor(183, 28, 28);
        $pdf->Cell(0, 6, 'WARNING:', 0, 1);
        $pdf->SetTextColor(0);
        $pdf->SetFont('times', '', 10);

        $rules = <<<HTML
If you, the <b>respondent</b>, <b>refuse or willfully fail to appear</b> in obedience to this Summons <b>without justifiable cause</b>:
<ul>
  <li>Any <b>counterclaim</b> you may have arising from the complaint will be <b>DISMISSED</b>;</li>
  <li>You will be <b>barred from filing that counterclaim in court</b> or any government office; and</li>
  <li>You may be <b>punished for indirect contempt of court</b>.</li>
</ul>
<b>FAIL NOT</b>, or else face punishment as for contempt of court.
HTML;
        $pdf->writeHTMLCell(0, 0, '', '', $rules, 0, 1, false, true, 'J');
    }

    $pdf->Ln(4);
    $pdf->SetFont('times', 'I', 10);
    $pdf->MultiCell(0, 5,
        'Given this ' . date('jS \of F, Y') . ' at ' . $issuedAt . '.',
        0, 'L'
    );

    kp_render_signature_block($pdf, $captainName, $captainSignature, $secretaryName, $secretarySignature);
    kp_render_footer($pdf);

    $pdf->Output($absPath, 'F');

    return [
        'abs_path'  => $absPath,
        'rel_path'  => $folder . '/' . $filename,
        'filename'  => $filename,
    ];
}

/* ============================================================
   Core renderer — KP Form #18 / #19 (Failure to Appear)
   ============================================================ */
function kp_render_failure_to_appear_notice(
    $conn,
    $complaint,
    $party_type,
    $recipient,
    $appearance_date,
    $appearance_time,
    $missed_hearing_date,
    $missed_hearing_time,
    $issuedByName
) {
    $refNo       = $complaint['reference_number'] ?? '';
    $title       = $complaint['title'] ?? '';
    $subject     = $complaint['complaint_subject'] ?? '';
    $sessionNo   = (int)($complaint['mediation_session_count'] ?? 1);
    $hearingLoc  = $complaint['hearing_location'] ?? 'Barangay Hall';

    $niceAppearDate = $appearance_date
        ? date('l, F j, Y', strtotime($appearance_date))
        : '[DATE]';
    $niceAppearTime = $appearance_time
        ? date('g:i A', strtotime($appearance_time))
        : '[TIME]';
    $niceMissedDate = $missed_hearing_date
        ? date('F j, Y', strtotime($missed_hearing_date))
        : '[DATE]';

    $issuedAt = KP_BARANGAY_NAME . ', ' . KP_MUNICIPALITY . ', ' . KP_PROVINCE;
    $recipientName = $recipient['full_name'] ?? '';

    $preferredCaptainId = (int)($complaint['assigned_to'] ?? 0);
    $captain   = kp_get_officer($conn, 'captain',   $preferredCaptainId);
    $secretary = kp_get_officer($conn, 'secretary');

    $captainName       = $captain['full_name']       ?? KP_FALLBACK_CAPTAIN_NAME;
    $captainSignature  = $captain['signature_path']  ?? null;
    $secretaryName     = $secretary['full_name']     ?? KP_FALLBACK_SECRETARY_NAME;
    $secretarySignature = $secretary['signature_path'] ?? null;

    $safeRef  = preg_replace('/[^A-Za-z0-9\-]/', '-', $refNo);
    $folder   = KP_UPLOAD_SUBDIR . '/' . ($party_type === 'complainant' ? 'complainant' : 'respondent');
    $absDir   = __DIR__ . '/../' . $folder;
    if (!file_exists($absDir)) @mkdir($absDir, 0777, true);

    $partyId   = (int)($recipient['id'] ?? 0);
    $formTag   = ($party_type === 'complainant') ? 'kp18' : 'kp19';
    $filename  = $safeRef . '-' . $formTag . '-' . $party_type . '-' . $partyId . '.pdf';
    $absPath   = $absDir . '/' . $filename;

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('BRITE — ' . KP_BARANGAY_NAME);
    $pdf->SetAuthor($captainName);
    $pdf->SetTitle(
        $party_type === 'complainant'
            ? 'KP Form #18 — Notice of Hearing (Re: Failure to Appear)'
            : 'KP Form #19 — Notice of Hearing (Re: Failure to Appear)'
    );
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(18, 15, 18);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    kp_render_header_block($pdf);

    $pdf->SetFont('times', 'B', 15);
    $pdf->Cell(0, 8, 'NOTICE OF HEARING', 0, 1, 'C');
    $pdf->SetFont('times', 'I', 11);
    $pdf->Cell(0, 5,
        '(Re: Failure to Appear — ' . ($party_type === 'complainant' ? 'KP Form #18' : 'KP Form #19') . ')',
        0, 1, 'C');
    $pdf->Ln(5);

    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(35, 6, 'Barangay Case No.:', 0, 0);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(60, 6, $refNo, 0, 1);

    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(35, 6, 'For:', 0, 0);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 6, $subject ?: $title, 0, 1);

    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(35, 6, 'Missed Session:', 0, 0);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 6, 'Session No. ' . $sessionNo . ' — ' . $niceMissedDate, 0, 1);

    $pdf->Ln(4);

    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, 'TO:', 0, 1);
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, strtoupper($recipientName), 0, 1);
    $pdf->SetFont('times', '', 10);
    if (!empty($recipient['address'])) {
        $pdf->MultiCell(0, 5, $recipient['address'], 0, 'L');
    }
    $pdf->Ln(3);

    $pdf->SetFont('times', '', 11);

    if ($party_type === 'complainant') {
        $body = "You are hereby required to appear before me/the <b>Punong Barangay</b> on "
              . "<b>{$niceAppearDate}</b> at <b>{$niceAppearTime}</b>, at the <b>{$hearingLoc}</b>, "
              . "to explain why you failed to appear for <b>mediation</b> scheduled on "
              . "<b>{$niceMissedDate}</b> and why your complaint should <b>not</b> be dismissed, "
              . "a certificate to bar the filing of your action in court/government office should <b>not</b> be issued, "
              . "and contempt proceedings should <b>not</b> be initiated in court for willful failure or refusal to appear "
              . "before the Punong Barangay.";
        $pdf->writeHTMLCell(0, 0, '', '', $body, 0, 1, false, true, 'J');

        $pdf->Ln(3);
        $pdf->SetFont('times', 'B', 11);
        $pdf->SetTextColor(183, 28, 28);
        $pdf->Cell(0, 6, 'IMPORTANT:', 0, 1);
        $pdf->SetTextColor(0);
        $pdf->SetFont('times', '', 10);

        $rules = <<<HTML
If you fail to appear on the scheduled date and time, the Punong Barangay may proceed with the case in your absence. Under RA 7160:
<ul>
  <li>Your complaint may be <b>DISMISSED</b>;</li>
  <li>You may be <b>barred from filing this action in court</b> or any government office; and</li>
  <li>You may be cited for <b>indirect contempt of court</b>.</li>
</ul>
Please bring any evidence or witnesses you wish to present.
HTML;
        $pdf->writeHTMLCell(0, 0, '', '', $rules, 0, 1, false, true, 'J');
    } else {
        $body = "You are hereby required to appear before me/the <b>Punong Barangay</b> on "
              . "<b>{$niceAppearDate}</b> at <b>{$niceAppearTime}</b>, at the <b>{$hearingLoc}</b>, "
              . "to explain why you failed to appear for <b>mediation</b> scheduled on "
              . "<b>{$niceMissedDate}</b> and why your counterclaim (if any) arising from the complaint "
              . "should <b>not</b> be dismissed, a certificate to bar the filing of said counterclaim in "
              . "court/government office should <b>not</b> be issued, and contempt proceedings should <b>not</b> "
              . "be initiated in court for willful failure or refusal to appear before the Punong Barangay.";
        $pdf->writeHTMLCell(0, 0, '', '', $body, 0, 1, false, true, 'J');

        $pdf->Ln(3);
        $pdf->SetFont('times', 'B', 11);
        $pdf->SetTextColor(183, 28, 28);
        $pdf->Cell(0, 6, 'WARNING:', 0, 1);
        $pdf->SetTextColor(0);
        $pdf->SetFont('times', '', 10);

        $rules = <<<HTML
If you fail to appear on the scheduled date and time, the Punong Barangay may proceed with the case in your absence. Under RA 7160:
<ul>
  <li>Any <b>counterclaim</b> you may have arising from the complaint may be <b>DISMISSED</b>;</li>
  <li>You may be <b>barred from filing that counterclaim in court</b> or any government office; and</li>
  <li>You may be cited for <b>indirect contempt of court</b>.</li>
</ul>
<b>FAIL NOT</b>, or else face punishment as for contempt of court.
HTML;
        $pdf->writeHTMLCell(0, 0, '', '', $rules, 0, 1, false, true, 'J');
    }

    $pdf->Ln(4);
    $pdf->SetFont('times', 'I', 10);
    $pdf->MultiCell(0, 5,
        'Given this ' . date('jS \of F, Y') . ' at ' . $issuedAt . '.',
        0, 'L'
    );

    kp_render_signature_block($pdf, $captainName, $captainSignature, $secretaryName, $secretarySignature);
    kp_render_footer($pdf);

    $pdf->Output($absPath, 'F');

    return [
        'abs_path'  => $absPath,
        'rel_path'  => $folder . '/' . $filename,
        'filename'  => $filename,
        'form_tag'  => $formTag,
    ];
}

/* ============================================================
   Core renderer — KP Form #10  (Constitution of Pangkat)
   ============================================================ */
function kp_render_pangkat_constitution_notice(
    $conn,
    $complaint,
    $recipient,
    $party_type,
    $constitution_date,
    $constitution_time,
    $issuedByName
) {
    $refNo       = $complaint['reference_number'] ?? '';
    $title       = $complaint['title'] ?? '';
    $subject     = $complaint['complaint_subject'] ?? '';
    $sessionNo   = (int)($complaint['mediation_session_count'] ?? 1);
    $hearingLoc  = $complaint['hearing_location'] ?? 'Barangay Hall';

    $niceDate = $constitution_date
        ? date('l, F j, Y', strtotime($constitution_date))
        : '[DATE]';
    $niceTime = $constitution_time
        ? date('g:i A', strtotime($constitution_time))
        : '[TIME]';

    $issuedAt = KP_BARANGAY_NAME . ', ' . KP_MUNICIPALITY . ', ' . KP_PROVINCE;
    $recipientName = $recipient['full_name'] ?? '';

    $preferredCaptainId = (int)($complaint['assigned_to'] ?? 0);
    $captain   = kp_get_officer($conn, 'captain',   $preferredCaptainId);
    $secretary = kp_get_officer($conn, 'secretary');

    $captainName       = $captain['full_name']       ?? KP_FALLBACK_CAPTAIN_NAME;
    $captainSignature  = $captain['signature_path']  ?? null;
    $secretaryName     = $secretary['full_name']     ?? KP_FALLBACK_SECRETARY_NAME;
    $secretarySignature = $secretary['signature_path'] ?? null;

    $safeRef  = preg_replace('/[^A-Za-z0-9\-]/', '-', $refNo);
    $folder   = KP_UPLOAD_SUBDIR . '/' . ($party_type === 'complainant' ? 'complainant' : 'respondent');
    $absDir   = __DIR__ . '/../' . $folder;
    if (!file_exists($absDir)) @mkdir($absDir, 0777, true);

    $partyId  = (int)($recipient['id'] ?? 0);
    $filename = $safeRef . '-kp10-' . $party_type . '-' . $partyId . '.pdf';
    $absPath  = $absDir . '/' . $filename;

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('BRITE — ' . KP_BARANGAY_NAME);
    $pdf->SetAuthor($captainName);
    $pdf->SetTitle('KP Form #10 — Notice for Constitution of Pangkat');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(18, 15, 18);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    kp_render_header_block($pdf);

    $pdf->SetFont('times', 'B', 15);
    $pdf->Cell(0, 8, 'NOTICE FOR CONSTITUTION OF PANGKAT', 0, 1, 'C');
    $pdf->SetFont('times', 'I', 11);
    $pdf->Cell(0, 5, '(KP Form #10)', 0, 1, 'C');
    $pdf->Ln(5);

    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(35, 6, 'Barangay Case No.:', 0, 0);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(60, 6, $refNo, 0, 1);

    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(35, 6, 'For:', 0, 0);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 6, $subject ?: $title, 0, 1);

    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(35, 6, 'Mediation Session:', 0, 0);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 6, 'Session No. ' . $sessionNo . ' (mediation failed)', 0, 1);

    $pdf->Ln(4);

    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, 'TO:', 0, 1);
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, strtoupper($recipientName), 0, 1);
    $pdf->SetFont('times', '', 10);
    if (!empty($recipient['address'])) {
        $pdf->MultiCell(0, 5, $recipient['address'], 0, 'L');
    }
    $pdf->Ln(3);

    $pdf->SetFont('times', '', 11);
    $body = "You are hereby required to appear before me/the <b>Punong Barangay</b> on "
          . "<b>{$niceDate}</b> at <b>{$niceTime}</b>, at the <b>{$hearingLoc}</b>, "
          . "for the <b>constitution of the Pangkat ng Tagapagkasundo</b> which shall conciliate your dispute.";
    $pdf->writeHTMLCell(0, 0, '', '', $body, 0, 1, false, true, 'J');

    $pdf->Ln(3);
    $pdf->SetFont('times', 'B', 11);
    $pdf->SetTextColor(183, 28, 28);
    $pdf->Cell(0, 6, 'IMPORTANT:', 0, 1);
    $pdf->SetTextColor(0);
    $pdf->SetFont('times', '', 10);

    $rules = <<<HTML
<b>1.</b> You must appear <b>in person</b> on the scheduled date and time.<br><br>
<b>2.</b> You and the other party shall <b>agree on the 3 members</b> of the Pangkat ng Tagapagkasundo chosen from the Lupong Tagapamayapa.<br><br>
<b>3.</b> Should you <b>fail to agree</b> on the Pangkat membership, or <b>fail to appear</b> on the aforesaid date for the constitution of the Pangkat, the <b>Punong Barangay shall determine the membership thereof by drawing lots</b>.<br><br>
<b>4.</b> The members so chosen shall elect from among themselves a <b>Chairperson</b> and a <b>Secretary</b>; the Lupon Secretary shall turn over all records of the case to the Pangkat Secretary for the Pangkat to study.
HTML;
    $pdf->writeHTMLCell(0, 0, '', '', $rules, 0, 1, false, true, 'J');

    $pdf->Ln(4);
    $pdf->SetFont('times', 'I', 10);
    $pdf->MultiCell(0, 5,
        'Given this ' . date('jS \of F, Y') . ' at ' . $issuedAt . '.',
        0, 'L'
    );

    kp_render_signature_block($pdf, $captainName, $captainSignature, $secretaryName, $secretarySignature);
    kp_render_footer($pdf);

    $pdf->Output($absPath, 'F');

    return [
        'abs_path'  => $absPath,
        'rel_path'  => $folder . '/' . $filename,
        'filename'  => $filename,
        'form_tag'  => 'kp10',
    ];
}

/* ============================================================
   Core renderer — KP Form #11  (Notice to Chosen Pangkat Member)
   ============================================================ */
function kp_render_chosen_member_notice($conn, $complaint, $recipient, $issuedByName) {
    $refNo     = $complaint['reference_number'] ?? '';
    $title     = $complaint['title'] ?? '';
    $subject   = $complaint['complaint_subject'] ?? '';

    $issuedAt = KP_BARANGAY_NAME . ', ' . KP_MUNICIPALITY . ', ' . KP_PROVINCE;
    $recipientName = $recipient['full_name'] ?? '';

    $preferredCaptainId = (int)($complaint['assigned_to'] ?? 0);
    $captain   = kp_get_officer($conn, 'captain',   $preferredCaptainId);
    $secretary = kp_get_officer($conn, 'secretary');

    $captainName       = $captain['full_name']       ?? KP_FALLBACK_CAPTAIN_NAME;
    $captainSignature  = $captain['signature_path']  ?? null;
    $secretaryName     = $secretary['full_name']     ?? KP_FALLBACK_SECRETARY_NAME;
    $secretarySignature = $secretary['signature_path'] ?? null;

    $safeRef  = preg_replace('/[^A-Za-z0-9\-]/', '-', $refNo);
    $folder   = KP_UPLOAD_SUBDIR . '/pangkat';
    $absDir   = __DIR__ . '/../' . $folder;
    if (!file_exists($absDir)) @mkdir($absDir, 0777, true);

    $memberId  = (int)($recipient['id'] ?? 0);
    $filename  = $safeRef . '-kp11-' . $memberId . '.pdf';
    $absPath   = $absDir . '/' . $filename;

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('BRITE — ' . KP_BARANGAY_NAME);
    $pdf->SetAuthor($captainName);
    $pdf->SetTitle('KP Form #11 — Notice to Chosen Pangkat Member');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(18, 15, 18);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    kp_render_header_block($pdf);

    $pdf->SetFont('times', 'B', 15);
    $pdf->Cell(0, 8, 'NOTICE TO CHOSEN PANGKAT MEMBER', 0, 1, 'C');
    $pdf->SetFont('times', 'I', 11);
    $pdf->Cell(0, 5, '(KP Form #11)', 0, 1, 'C');
    $pdf->Ln(5);

    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(35, 6, 'Barangay Case No.:', 0, 0);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(60, 6, $refNo, 0, 1);

    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(35, 6, 'For:', 0, 0);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 6, $subject ?: $title, 0, 1);

    $pdf->Ln(4);

    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, 'TO:', 0, 1);
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, strtoupper($recipientName), 0, 1);

    $pdf->Ln(3);

    $pdf->SetFont('times', '', 11);
    $body = "Notice is hereby given that you have been chosen member of the "
          . "<b>Pangkat ng Tagapagkasundo</b> to amicably conciliate the dispute between the "
          . "parties in the above-entitled case.";
    $pdf->writeHTMLCell(0, 0, '', '', $body, 0, 1, false, true, 'J');

    $pdf->Ln(3);
    $pdf->SetFont('times', 'B', 11);
    $pdf->SetTextColor(183, 28, 28);
    $pdf->Cell(0, 6, 'IMPORTANT:', 0, 1);
    $pdf->SetTextColor(0);
    $pdf->SetFont('times', '', 10);

    $rules = <<<HTML
<b>1.</b> As members of the Pangkat, you shall <b>elect from among yourselves a Chairperson and a Secretary</b>.<br><br>
<b>2.</b> The <b>Lupon Secretary shall give/turn over all records of the case</b> to the Pangkat Secretary for the Pangkat to study.<br><br>
<b>3.</b> The Pangkat shall meet, hear both parties, and explore possibilities for amicable settlement within <b>fifteen (15) days</b>, which may be extended for another fifteen (15) days in a meritorious case.
HTML;
    $pdf->writeHTMLCell(0, 0, '', '', $rules, 0, 1, false, true, 'J');

    $pdf->Ln(4);
    $pdf->SetFont('times', 'I', 10);
    $pdf->MultiCell(0, 5,
        'Given this ' . date('jS \of F, Y') . ' at ' . $issuedAt . '.',
        0, 'L'
    );

    kp_render_signature_block($pdf, $captainName, $captainSignature, $secretaryName, $secretarySignature);
    kp_render_footer($pdf);

    $pdf->Output($absPath, 'F');

    return [
        'abs_path'  => $absPath,
        'rel_path'  => $folder . '/' . $filename,
        'filename'  => $filename,
        'form_tag'  => 'kp11',
    ];
}

/* ============================================================
   Core renderer — KP Form #23 (Certification to Bar Action)
   ============================================================ */
function kp_render_bar_action_cert(
    $conn,
    $complaint,
    $complainants,
    $dismissed_at,
    $reason,
    $issuedByName
) {
    $refNo   = $complaint['reference_number'] ?? '';
    $title   = $complaint['title'] ?? '';
    $subject = $complaint['complaint_subject'] ?? '';

    $niceDate = $dismissed_at
        ? date('F j, Y', strtotime($dismissed_at))
        : date('F j, Y');

    $issuedAt = KP_BARANGAY_NAME . ', ' . KP_MUNICIPALITY . ', ' . KP_PROVINCE;

    $preferredCaptainId = (int)($complaint['assigned_to'] ?? 0);
    $captain   = kp_get_officer($conn, 'captain',   $preferredCaptainId);
    $secretary = kp_get_officer($conn, 'secretary');

    $captainName       = $captain['full_name']       ?? KP_FALLBACK_CAPTAIN_NAME;
    $captainSignature  = $captain['signature_path']  ?? null;
    $secretaryName     = $secretary['full_name']     ?? KP_FALLBACK_SECRETARY_NAME;
    $secretarySignature = $secretary['signature_path'] ?? null;

    $compNames = [];
    foreach ($complainants as $p) {
        $compNames[] = $p['full_name'] ?? '';
    }
    $compNamesText = implode(', ', array_filter($compNames));

    $safeRef = preg_replace('/[^A-Za-z0-9\-]/', '-', $refNo);
    $folder  = KP_UPLOAD_SUBDIR . '/complainant';
    $absDir  = __DIR__ . '/../' . $folder;
    if (!file_exists($absDir)) @mkdir($absDir, 0777, true);

    $filename = $safeRef . '-kp23-complainant.pdf';
    $absPath  = $absDir . '/' . $filename;

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('BRITE — ' . KP_BARANGAY_NAME);
    $pdf->SetAuthor($secretaryName);
    $pdf->SetTitle('KP Form #23 — Certification to Bar Action');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(18, 15, 18);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    kp_render_header_block($pdf);

    $pdf->SetFont('times', 'B', 15);
    $pdf->Cell(0, 8, 'CERTIFICATION TO BAR ACTION', 0, 1, 'C');
    $pdf->SetFont('times', 'I', 11);
    $pdf->Cell(0, 5, '(KP Form #23)', 0, 1, 'C');
    $pdf->Ln(5);

    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(35, 6, 'Barangay Case No.:', 0, 0);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(60, 6, $refNo, 0, 1);

    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(35, 6, 'For:', 0, 0);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 6, $subject ?: $title, 0, 1);

    $pdf->Ln(4);

    $pdf->SetFont('times', '', 11);
    $body = "This is to certify that the above-captioned case was dismissed pursuant to the Order dated "
          . "<b>{$niceDate}</b>, on the ground of the <b>willful failure or refusal of the complainant</b> "
          . "<b>(" . htmlspecialchars($compNamesText) . ")</b> to appear for hearing before the Punong Barangay "
          . "despite due notice, without justifiable cause.";
    $pdf->writeHTMLCell(0, 0, '', '', $body, 0, 1, false, true, 'J');

    $pdf->Ln(3);
    $pdf->SetFont('times', 'B', 11);
    $pdf->SetTextColor(183, 28, 28);
    $pdf->Cell(0, 6, 'EFFECT OF THIS CERTIFICATION:', 0, 1);
    $pdf->SetTextColor(0);
    $pdf->SetFont('times', '', 10);

    $effect = <<<HTML
Pursuant to the Katarungang Pambarangay Law (Republic Act No. 7160):
<ul>
  <li>The complainant is <b>barred from filing an action</b> in court or any government office arising from the same complaint; and</li>
  <li>The complainant may be <b>punished for indirect contempt of court</b>.</li>
</ul>
<b>Reason provided:</b> " . nl2br(htmlspecialchars($reason)) . "
HTML;
    $pdf->writeHTMLCell(0, 0, '', '', $effect, 0, 1, false, true, 'J');

    $pdf->Ln(4);
    $pdf->SetFont('times', 'I', 10);
    $pdf->MultiCell(0, 5,
        'Given this ' . date('jS \of F, Y') . ' at ' . $issuedAt . '.',
        0, 'L'
    );

    kp_render_signature_block($pdf, $captainName, $captainSignature, $secretaryName, $secretarySignature);
    kp_render_footer($pdf);

    $pdf->Output($absPath, 'F');

    return [
        'abs_path'  => $absPath,
        'rel_path'  => $folder . '/' . $filename,
        'filename'  => $filename,
        'form_tag'  => 'kp23',
    ];
}

/* ============================================================
   Core renderer — KP Form #24 (Certification to Bar Counterclaim)
   ============================================================ */
function kp_render_bar_counterclaim_cert(
    $conn,
    $complaint,
    $respondents,
    $barred_at,
    $reason,
    $issuedByName
) {
    $refNo   = $complaint['reference_number'] ?? '';
    $title   = $complaint['title'] ?? '';
    $subject = $complaint['complaint_subject'] ?? '';

    $niceDate = $barred_at
        ? date('F j, Y', strtotime($barred_at))
        : date('F j, Y');

    $issuedAt = KP_BARANGAY_NAME . ', ' . KP_MUNICIPALITY . ', ' . KP_PROVINCE;

    $preferredCaptainId = (int)($complaint['assigned_to'] ?? 0);
    $captain   = kp_get_officer($conn, 'captain',   $preferredCaptainId);
    $secretary = kp_get_officer($conn, 'secretary');

    $captainName       = $captain['full_name']       ?? KP_FALLBACK_CAPTAIN_NAME;
    $captainSignature  = $captain['signature_path']  ?? null;
    $secretaryName     = $secretary['full_name']     ?? KP_FALLBACK_SECRETARY_NAME;
    $secretarySignature = $secretary['signature_path'] ?? null;

    $respNames = [];
    foreach ($respondents as $p) {
        $respNames[] = $p['full_name'] ?? '';
    }
    $respNamesText = implode(', ', array_filter($respNames));

    $safeRef = preg_replace('/[^A-Za-z0-9\-]/', '-', $refNo);
    $folder  = KP_UPLOAD_SUBDIR . '/respondent';
    $absDir  = __DIR__ . '/../' . $folder;
    if (!file_exists($absDir)) @mkdir($absDir, 0777, true);

    $filename = $safeRef . '-kp24-respondent.pdf';
    $absPath  = $absDir . '/' . $filename;

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('BRITE — ' . KP_BARANGAY_NAME);
    $pdf->SetAuthor($secretaryName);
    $pdf->SetTitle('KP Form #24 — Certification to Bar Counterclaim');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(18, 15, 18);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    kp_render_header_block($pdf);

    $pdf->SetFont('times', 'B', 15);
    $pdf->Cell(0, 8, 'CERTIFICATION TO BAR COUNTERCLAIM', 0, 1, 'C');
    $pdf->SetFont('times', 'I', 11);
    $pdf->Cell(0, 5, '(KP Form #24)', 0, 1, 'C');
    $pdf->Ln(5);

    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(35, 6, 'Barangay Case No.:', 0, 0);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(60, 6, $refNo, 0, 1);

    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(35, 6, 'For:', 0, 0);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 6, $subject ?: $title, 0, 1);

    $pdf->Ln(4);

    $pdf->SetFont('times', '', 11);
    $body = "This is to certify that after prior notice and hearing, the respondent(s) "
          . "<b>(" . htmlspecialchars($respNamesText) . ")</b> have been found to have <b>willfully failed or "
          . "refused to appear</b> without justifiable reason before the Punong Barangay "
          . "on <b>{$niceDate}</b>.";
    $pdf->writeHTMLCell(0, 0, '', '', $body, 0, 1, false, true, 'J');

    $pdf->Ln(3);
    $pdf->SetFont('times', 'B', 11);
    $pdf->SetTextColor(183, 28, 28);
    $pdf->Cell(0, 6, 'EFFECT OF THIS CERTIFICATION:', 0, 1);
    $pdf->SetTextColor(0);
    $pdf->SetFont('times', '', 10);

    $effect = <<<HTML
Pursuant to the Katarungang Pambarangay Law (Republic Act No. 7160):
<ul>
  <li>The respondent(s) <b>is/are barred from filing his/their counterclaim (if any)</b> arising from the complaint in court or any government office; and</li>
  <li>The respondent(s) may be <b>punished for indirect contempt of court</b>.</li>
</ul>
<b>Reason provided:</b> " . nl2br(htmlspecialchars($reason)) . "
HTML;
    $pdf->writeHTMLCell(0, 0, '', '', $effect, 0, 1, false, true, 'J');

    $pdf->Ln(4);
    $pdf->SetFont('times', 'I', 10);
    $pdf->MultiCell(0, 5,
        'Given this ' . date('jS \of F, Y') . ' at ' . $issuedAt . '.',
        0, 'L'
    );

    kp_render_signature_block($pdf, $captainName, $captainSignature, $secretaryName, $secretarySignature);
    kp_render_footer($pdf);

    $pdf->Output($absPath, 'F');

    return [
        'abs_path'  => $absPath,
        'rel_path'  => $folder . '/' . $filename,
        'filename'  => $filename,
        'form_tag'  => 'kp24',
    ];
}

/* ============================================================
   NEW renderer — KP Form #12 (Notice of Hearing — Conciliation)
   Signed by: Pangkat Chairman   Attested: Pangkat Secretary
   ============================================================ */
function kp_render_conciliation_hearing_notice(
    $conn, $complaint, $complainants, $respondents, $hearing_date, $hearing_time,
    $location, $issuedByName
) {
    $refNo = $complaint['reference_number'] ?? '';
    $subject = $complaint['complaint_subject'] ?? '';
    $title = $complaint['title'] ?? '';
    $niceDate = $hearing_date ? date('l, F j, Y', strtotime($hearing_date)) : '[DATE]';
    $niceTime = $hearing_time ? date('g:i A', strtotime($hearing_time)) : '[TIME]';
    $loc = $location ?: ($complaint['hearing_location'] ?? 'Barangay Hall');

    $chair = kp_get_pangkat_officer($conn, (int)$complaint['id'], 'chair');
    $sec   = kp_get_pangkat_officer($conn, (int)$complaint['id'], 'secretary');
    $chairName = $chair['full_name'] ?? 'PANGKAT CHAIRPERSON';
    $secName   = $sec['full_name']   ?? 'PANGKAT SECRETARY';

    $safeRef = preg_replace('/[^A-Za-z0-9\-]/', '-', $refNo);
    $folder  = KP_UPLOAD_SUBDIR . '/pangkat';
    $absDir  = __DIR__ . '/../' . $folder;
    if (!file_exists($absDir)) @mkdir($absDir, 0777, true);
    $filename = $safeRef . '-kp12-conciliation.pdf';
    $absPath  = $absDir . '/' . $filename;

    $pdf = kp_new_pdf('KP Form #12 — Notice of Hearing (Conciliation)', $chairName);
    $pdf->SetFont('times', 'B', 15);
    $pdf->Cell(0, 8, 'NOTICE OF HEARING', 0, 1, 'C');
    $pdf->SetFont('times', 'I', 11);
    $pdf->Cell(0, 5, '(Conciliation Proceedings — KP Form #12)', 0, 1, 'C');
    $pdf->Ln(5);
    kp_standard_case_block($pdf, $complaint);

    $pdf->SetFont('times', '', 11);
    $body = "You are hereby required to appear before the <b>Pangkat ng Tagapagkasundo</b> on "
          . "<b>{$niceDate}</b> at <b>{$niceTime}</b>, at the <b>{$loc}</b>, for a hearing of the "
          . "above-entitled case for <b>conciliation</b>.<br><br>"
          . kp_parties_rows_html($complainants, $respondents);
    $pdf->writeHTMLCell(0, 0, '', '', $body, 0, 1, false, true, 'J');

    $pdf->Ln(4);
    $pdf->SetFont('times', 'B', 11);
    $pdf->SetTextColor(183, 28, 28);
    $pdf->Cell(0, 6, 'REMINDER:', 0, 1);
    $pdf->SetTextColor(0);
    $pdf->SetFont('times', '', 10);
    $rules = "Both parties must appear <b>in person</b>. Failure to appear without justifiable cause "
           . "may result in the dismissal of the complaint or counterclaim and may be punished for "
           . "indirect contempt.";
    $pdf->writeHTMLCell(0, 0, '', '', $rules, 0, 1, false, true, 'J');

    $pdf->Ln(4);
    $pdf->SetFont('times', 'I', 10);
    $pdf->MultiCell(0, 5, 'Given this ' . date('jS \of F, Y') . ' at ' . KP_BARANGAY_NAME . '.', 0, 'L');

    // Signature block — Pangkat Chairperson / Pangkat Secretary
    $pdf->Ln(8);
    if (!empty($chair['signature_path'])) { kp_draw_signature($pdf, $chair['signature_path']); $pdf->Ln(1); } else { $pdf->Ln(12); }
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, strtoupper($chairName), 0, 1, 'R');
    $pdf->SetFont('times', 'I', 10);
    $pdf->Cell(0, 5, 'Pangkat Chairman', 0, 1, 'R');

    $pdf->Ln(8);
    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(0, 5, 'ATTESTED:', 0, 1);
    if (!empty($sec['signature_path'])) { kp_draw_signature($pdf, $sec['signature_path']); $pdf->Ln(1); } else { $pdf->Ln(12); }
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 5, strtoupper($secName), 0, 1);
    $pdf->Cell(0, 5, 'Pangkat Secretary', 0, 1);

    kp_render_footer($pdf);
    $pdf->Output($absPath, 'F');

    return ['abs_path'=>$absPath, 'rel_path'=>$folder.'/'.$filename, 'filename'=>$filename, 'form_tag'=>'kp12'];
}

/* ============================================================
   NEW renderer — KP Form #13 (Subpoena)
   ============================================================ */
function kp_render_subpoena($conn, $complaint, $witnesses, $hearing_date, $hearing_time, $issuedByName) {
    $refNo = $complaint['reference_number'] ?? '';
    $niceDate = $hearing_date ? date('F j, Y', strtotime($hearing_date)) : '[DATE]';
    $niceTime = $hearing_time ? date('g:i A', strtotime($hearing_time)) : '[TIME]';

    $chair = kp_get_pangkat_officer($conn, (int)$complaint['id'], 'chair');
    $captain = kp_get_officer($conn, 'captain', (int)($complaint['assigned_to'] ?? 0));
    $signer  = $chair['full_name'] ?? ($captain['full_name'] ?? 'PUNONG BARANGAY');
    $signaturePath = $chair['signature_path'] ?? ($captain['signature_path'] ?? null);
    $roleLabel = $chair ? 'Punong Barangay / Pangkat Chairman' : 'Punong Barangay / Lupon Chairman';

    $safeRef = preg_replace('/[^A-Za-z0-9\-]/', '-', $refNo);
    $folder  = KP_UPLOAD_SUBDIR . '/pangkat';
    $absDir  = __DIR__ . '/../' . $folder;
    if (!file_exists($absDir)) @mkdir($absDir, 0777, true);
    $filename = $safeRef . '-kp13-subpoena.pdf';
    $absPath  = $absDir . '/' . $filename;

    $pdf = kp_new_pdf('KP Form #13 — Subpoena', $signer);
    $pdf->SetFont('times', 'B', 15);
    $pdf->Cell(0, 8, 'SUBPOENA', 0, 1, 'C');
    $pdf->SetFont('times', 'I', 11);
    $pdf->Cell(0, 5, '(KP Form #13)', 0, 1, 'C');
    $pdf->Ln(5);
    kp_standard_case_block($pdf, $complaint);

    $wNames = [];
    foreach ($witnesses as $w) $wNames[] = $w['full_name'] ?? '';
    if (!$wNames) $wNames[] = '________________________';

    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, 'TO:', 0, 1);
    $pdf->SetFont('times', 'B', 11);
    foreach ($wNames as $n) $pdf->Cell(0, 6, strtoupper($n), 0, 1);
    $pdf->SetFont('times', 'I', 10);
    $pdf->Cell(0, 6, 'Witnesses', 0, 1);
    $pdf->Ln(3);

    $pdf->SetFont('times', '', 11);
    $body = "You are hereby commanded to appear before me on <b>{$niceDate}</b> at <b>{$niceTime}</b>, "
          . "then and there to testify in the hearing of the above-captioned case.";
    $pdf->writeHTMLCell(0, 0, '', '', $body, 0, 1, false, true, 'J');

    $pdf->Ln(4);
    $pdf->SetFont('times', 'I', 10);
    $pdf->MultiCell(0, 5, 'Given this ' . date('jS \of F, Y') . ' at ' . KP_BARANGAY_NAME . '.', 0, 'L');

    $pdf->Ln(12);
    if (!empty($signaturePath)) { kp_draw_signature($pdf, $signaturePath); $pdf->Ln(1); } else { $pdf->Ln(12); }
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, strtoupper($signer), 0, 1, 'R');
    $pdf->SetFont('times', 'I', 10);
    $pdf->Cell(0, 5, $roleLabel, 0, 1, 'R');

    kp_render_footer($pdf);
    $pdf->Output($absPath, 'F');

    return ['abs_path'=>$absPath, 'rel_path'=>$folder.'/'.$filename, 'filename'=>$filename, 'form_tag'=>'kp13'];
}

/* ============================================================
   NEW renderer — KP Form #14 (Agreement for Arbitration)
   ============================================================ */
function kp_render_agreement_for_arbitration($conn, $complaint, $complainants, $respondents, $issuedByName) {
    $refNo = $complaint['reference_number'] ?? '';

    $captain = kp_get_officer($conn, 'captain', (int)($complaint['assigned_to'] ?? 0));
    $chair   = kp_get_pangkat_officer($conn, (int)$complaint['id'], 'chair');
    $attester = $chair['full_name'] ?? ($captain['full_name'] ?? 'PUNONG BARANGAY');
    $attesterSignature = $chair['signature_path'] ?? ($captain['signature_path'] ?? null);
    $attesterRole = $chair ? 'Punong Barangay / Pangkat Chairman' : 'Punong Barangay / Lupon Chairman';

    $safeRef = preg_replace('/[^A-Za-z0-9\-]/', '-', $refNo);
    $folder  = KP_UPLOAD_SUBDIR . '/pangkat';
    $absDir  = __DIR__ . '/../' . $folder;
    if (!file_exists($absDir)) @mkdir($absDir, 0777, true);
    $filename = $safeRef . '-kp14-arbitration-agreement.pdf';
    $absPath  = $absDir . '/' . $filename;

    $pdf = kp_new_pdf('KP Form #14 — Agreement for Arbitration', $attester);
    $pdf->SetFont('times', 'B', 15);
    $pdf->Cell(0, 8, 'AGREEMENT FOR ARBITRATION', 0, 1, 'C');
    $pdf->SetFont('times', 'I', 11);
    $pdf->Cell(0, 5, '(KP Form #14)', 0, 1, 'C');
    $pdf->Ln(5);
    kp_standard_case_block($pdf, $complaint);

    $pdf->SetFont('times', '', 11);
    $body = "We hereby agree to submit our dispute for <b>arbitration</b> to the "
          . "<b>Punong Barangay / Pangkat ng Tagapagkasundo</b> and bind ourselves to comply with "
          . "the award that may be rendered thereon. We have made this agreement freely with a "
          . "full understanding of its nature and consequences.<br><br>"
          . kp_parties_rows_html($complainants, $respondents);
    $pdf->writeHTMLCell(0, 0, '', '', $body, 0, 1, false, true, 'J');

    $pdf->Ln(6);
    $pdf->SetFont('times', '', 10);
    $pdf->MultiCell(0, 5, 'Entered into this ' . date('jS \of F, Y') . ' at ' . KP_BARANGAY_NAME . '.', 0, 'L');

    $pdf->Ln(12);
    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(90, 5, '_____________________________', 0, 0);
    $pdf->Cell(90, 5, '_____________________________', 0, 1);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(90, 5, 'Complainant(s)', 0, 0);
    $pdf->Cell(90, 5, 'Respondent(s)', 0, 1);

    $pdf->Ln(10);
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, 'ATTESTATION', 0, 1, 'C');
    $pdf->SetFont('times', '', 10);
    $pdf->MultiCell(0, 5,
        'I hereby certify that the foregoing Agreement for Arbitration was entered into by the parties '
        . 'freely and voluntarily, after I had explained to them the nature and consequences of such agreement.',
        0, 'J');

    $pdf->Ln(10);
    if (!empty($attesterSignature)) { kp_draw_signature($pdf, $attesterSignature); $pdf->Ln(1); } else { $pdf->Ln(12); }
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, strtoupper($attester), 0, 1, 'R');
    $pdf->SetFont('times', 'I', 10);
    $pdf->Cell(0, 5, $attesterRole, 0, 1, 'R');

    kp_render_footer($pdf);
    $pdf->Output($absPath, 'F');

    return ['abs_path'=>$absPath, 'rel_path'=>$folder.'/'.$filename, 'filename'=>$filename, 'form_tag'=>'kp14'];
}

/* ============================================================
   NEW renderer — KP Form #15 (Arbitration Award)
   ============================================================ */
function kp_render_arbitration_award($conn, $complaint, $awardText, $issuedByName) {
    $refNo = $complaint['reference_number'] ?? '';

    $captain = kp_get_officer($conn, 'captain', (int)($complaint['assigned_to'] ?? 0));
    $chair   = kp_get_pangkat_officer($conn, (int)$complaint['id'], 'chair');
    $sec     = kp_get_pangkat_officer($conn, (int)$complaint['id'], 'secretary');

    $arbiter = $chair['full_name'] ?? ($captain['full_name'] ?? 'ARBITER');
    $arbiterSignature = $chair['signature_path'] ?? ($captain['signature_path'] ?? null);
    $arbiterRole = $chair ? 'Punong Barangay / Pangkat Chairman' : 'Punong Barangay / Lupon Chairman';

    $attester = $sec['full_name'] ?? ($captain['full_name'] ?? 'ATTESTER');
    $attesterSignature = $sec['signature_path'] ?? ($captain['signature_path'] ?? null);
    $attesterRole = $sec ? 'Pangkat Secretary' : 'Lupon Secretary';

    $safeRef = preg_replace('/[^A-Za-z0-9\-]/', '-', $refNo);
    $folder  = KP_UPLOAD_SUBDIR . '/pangkat';
    $absDir  = __DIR__ . '/../' . $folder;
    if (!file_exists($absDir)) @mkdir($absDir, 0777, true);
    $filename = $safeRef . '-kp15-arbitration-award.pdf';
    $absPath  = $absDir . '/' . $filename;

    $pdf = kp_new_pdf('KP Form #15 — Arbitration Award', $arbiter);
    $pdf->SetFont('times', 'B', 15);
    $pdf->Cell(0, 8, 'ARBITRATION AWARD', 0, 1, 'C');
    $pdf->SetFont('times', 'I', 11);
    $pdf->Cell(0, 5, '(KP Form #15)', 0, 1, 'C');
    $pdf->Ln(5);
    kp_standard_case_block($pdf, $complaint);

    $pdf->SetFont('times', '', 11);
    $pdf->MultiCell(0, 6,
        'After hearing the testimonies given and careful examination of the evidence presented in this case, '
        . 'award is hereby made as follows:',
        0, 'J');
    $pdf->Ln(2);

    $safeAward = nl2br(htmlspecialchars($awardText ?: '[Insert award here]'));
    $pdf->SetFont('times', '', 11);
    $pdf->writeHTMLCell(0, 0, '', '', $safeAward, 0, 1, false, true, 'J');

    $pdf->Ln(6);
    $pdf->SetFont('times', 'I', 10);
    $pdf->MultiCell(0, 5, 'Made this ' . date('jS \of F, Y') . ' at ' . KP_BARANGAY_NAME . '.', 0, 'L');

    $pdf->Ln(12);
    if (!empty($arbiterSignature)) { kp_draw_signature($pdf, $arbiterSignature); $pdf->Ln(1); } else { $pdf->Ln(12); }
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, strtoupper($arbiter), 0, 1, 'R');
    $pdf->SetFont('times', 'I', 10);
    $pdf->Cell(0, 5, $arbiterRole, 0, 1, 'R');

    $pdf->Ln(8);
    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(0, 5, 'ATTESTED:', 0, 1);
    if (!empty($attesterSignature)) { kp_draw_signature($pdf, $attesterSignature); $pdf->Ln(1); } else { $pdf->Ln(12); }
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 5, strtoupper($attester), 0, 1);
    $pdf->Cell(0, 5, $attesterRole, 0, 1);

    kp_render_footer($pdf);
    $pdf->Output($absPath, 'F');

    return ['abs_path'=>$absPath, 'rel_path'=>$folder.'/'.$filename, 'filename'=>$filename, 'form_tag'=>'kp15'];
}

/* ============================================================
   NEW renderer — KP Form #16 (Amicable Settlement, 2 modes)
   ============================================================ */
function kp_render_amicable_settlement($conn, $complaint, $complainants, $respondents, $termsText, $mode, $issuedByName) {
    $refNo = $complaint['reference_number'] ?? '';
    $mode  = ($mode === 'conciliation') ? 'conciliation' : 'mediation';

    if ($mode === 'conciliation') {
        $chair = kp_get_pangkat_officer($conn, (int)$complaint['id'], 'chair');
        $sec   = kp_get_pangkat_officer($conn, (int)$complaint['id'], 'secretary');
        $signerName   = $chair['full_name'] ?? 'PANGKAT CHAIRMAN';
        $signerSig    = $chair['signature_path'] ?? null;
        $signerRole   = 'Pangkat Chairman';
        $attesterName = $sec['full_name']   ?? 'PANGKAT SECRETARY';
        $attesterSig  = $sec['signature_path'] ?? null;
        $attesterRole = 'Pangkat Secretary';
    } else {
        $captain   = kp_get_officer($conn, 'captain', (int)($complaint['assigned_to'] ?? 0));
        $secretary = kp_get_officer($conn, 'secretary');
        $signerName   = $captain['full_name']   ?? KP_FALLBACK_CAPTAIN_NAME;
        $signerSig    = $captain['signature_path'] ?? null;
        $signerRole   = 'Punong Barangay / Lupon Chairman';
        $attesterName = $secretary['full_name'] ?? KP_FALLBACK_SECRETARY_NAME;
        $attesterSig  = $secretary['signature_path'] ?? null;
        $attesterRole = 'Lupon Secretary';
    }

    $safeRef = preg_replace('/[^A-Za-z0-9\-]/', '-', $refNo);
    $folder  = KP_UPLOAD_SUBDIR . ($mode === 'conciliation' ? '/pangkat' : '/complainant');
    $absDir  = __DIR__ . '/../' . $folder;
    if (!file_exists($absDir)) @mkdir($absDir, 0777, true);
    $filename = $safeRef . '-kp16-amicable-settlement-' . $mode . '.pdf';
    $absPath  = $absDir . '/' . $filename;

    $pdf = kp_new_pdf('KP Form #16 — Amicable Settlement', $signerName);
    $pdf->SetFont('times', 'B', 15);
    $pdf->Cell(0, 8, 'AMICABLE SETTLEMENT', 0, 1, 'C');
    $pdf->SetFont('times', 'I', 11);
    $pdf->Cell(0, 5, '(KP Form #16)', 0, 1, 'C');
    $pdf->Ln(5);
    kp_standard_case_block($pdf, $complaint);

    $pdf->SetFont('times', '', 11);
    $safeTerms = nl2br(htmlspecialchars($termsText ?: '[Insert settlement terms here]'));
    $body = "We, complainant/s and respondent/s in the above-captioned case, do hereby agree to settle "
          . "our dispute as follows:<br><br>{$safeTerms}<br><br>"
          . "and bind ourselves to comply honestly and faithfully with the above terms of settlement.";
    $pdf->writeHTMLCell(0, 0, '', '', $body, 0, 1, false, true, 'J');

    $pdf->Ln(6);
    $pdf->SetFont('times', '', 10);
    $pdf->MultiCell(0, 5, 'Entered into this ' . date('jS \of F, Y') . ' at ' . KP_BARANGAY_NAME . '.', 0, 'L');

    $pdf->Ln(12);
    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(90, 5, '_____________________________', 0, 0);
    $pdf->Cell(90, 5, '_____________________________', 0, 1);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(90, 5, 'Complainant(s)', 0, 0);
    $pdf->Cell(90, 5, 'Respondent(s)', 0, 1);

    $pdf->Ln(10);
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, 'ATTESTATION', 0, 1, 'C');
    $pdf->SetFont('times', '', 10);
    $pdf->MultiCell(0, 5,
        'I hereby certify that the foregoing amicable settlement was entered into by the parties freely '
        . 'and voluntarily, after I had explained to them the nature and consequence of such settlement.',
        0, 'J');

    $pdf->Ln(10);
    if (!empty($signerSig)) { kp_draw_signature($pdf, $signerSig); $pdf->Ln(1); } else { $pdf->Ln(12); }
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, strtoupper($signerName), 0, 1, 'R');
    $pdf->SetFont('times', 'I', 10);
    $pdf->Cell(0, 5, $signerRole, 0, 1, 'R');

    $pdf->Ln(8);
    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(0, 5, 'ATTESTED:', 0, 1);
    if (!empty($attesterSig)) { kp_draw_signature($pdf, $attesterSig); $pdf->Ln(1); } else { $pdf->Ln(12); }
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 5, strtoupper($attesterName), 0, 1);
    $pdf->Cell(0, 5, $attesterRole, 0, 1);

    kp_render_footer($pdf);
    $pdf->Output($absPath, 'F');

    return [
        'abs_path'=>$absPath, 'rel_path'=>$folder.'/'.$filename,
        'filename'=>$filename, 'form_tag'=>'kp16', 'mode'=>$mode,
    ];
}

/* ============================================================
   NEW renderer — KP Form #17 (Repudiation)
   ============================================================ */
function kp_render_repudiation($conn, $complaint, $reason, $issuedByName) {
    $refNo = $complaint['reference_number'] ?? '';
    $captain = kp_get_officer($conn, 'captain', (int)($complaint['assigned_to'] ?? 0));
    $chair   = kp_get_pangkat_officer($conn, (int)$complaint['id'], 'chair');
    $attester = $chair['full_name'] ?? ($captain['full_name'] ?? 'PUNONG BARANGAY');
    $attesterSig = $chair['signature_path'] ?? ($captain['signature_path'] ?? null);
    $attesterRole = $chair ? 'Pangkat Chairman' : 'Punong Barangay / Lupon Chairman';

    $safeRef = preg_replace('/[^A-Za-z0-9\-]/', '-', $refNo);
    $folder  = KP_UPLOAD_SUBDIR . '/pangkat';
    $absDir  = __DIR__ . '/../' . $folder;
    if (!file_exists($absDir)) @mkdir($absDir, 0777, true);
    $filename = $safeRef . '-kp17-repudiation.pdf';
    $absPath  = $absDir . '/' . $filename;

    $pdf = kp_new_pdf('KP Form #17 — Repudiation', $attester);
    $pdf->SetFont('times', 'B', 15);
    $pdf->Cell(0, 8, 'REPUDIATION', 0, 1, 'C');
    $pdf->SetFont('times', 'I', 11);
    $pdf->Cell(0, 5, '(KP Form #17)', 0, 1, 'C');
    $pdf->Ln(5);
    kp_standard_case_block($pdf, $complaint);

    $pdf->SetFont('times', '', 11);
    $safeReason = nl2br(htmlspecialchars($reason ?: '[State ground: Fraud / Violence / Intimidation]'));
    $body = "I/WE hereby repudiate the settlement / agreement for arbitration on the ground that "
          . "my/our consent was vitiated by:<br><br>{$safeReason}";
    $pdf->writeHTMLCell(0, 0, '', '', $body, 0, 1, false, true, 'J');

    $pdf->Ln(6);
    $pdf->SetFont('times', 'I', 10);
    $pdf->MultiCell(0, 5, 'Filed this ' . date('jS \of F, Y') . ' at ' . KP_BARANGAY_NAME . '.', 0, 'L');

    $pdf->Ln(12);
    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(90, 5, '_____________________________', 0, 0);
    $pdf->Cell(90, 5, '_____________________________', 0, 1);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(90, 5, 'Complainant(s)', 0, 0);
    $pdf->Cell(90, 5, 'Respondent(s)', 0, 1);

    $pdf->Ln(10);
    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(0, 5, 'SUBSCRIBED AND SWORN TO before me this ' . date('jS \of F, Y') . '.', 0, 1);
    $pdf->Ln(10);
    if (!empty($attesterSig)) { kp_draw_signature($pdf, $attesterSig); $pdf->Ln(1); } else { $pdf->Ln(12); }
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, strtoupper($attester), 0, 1, 'R');
    $pdf->SetFont('times', 'I', 10);
    $pdf->Cell(0, 5, $attesterRole, 0, 1, 'R');

    kp_render_footer($pdf);
    $pdf->Output($absPath, 'F');

    return ['abs_path'=>$absPath, 'rel_path'=>$folder.'/'.$filename, 'filename'=>$filename, 'form_tag'=>'kp17'];
}

/* ============================================================
   NEW renderer — KP Form #20 (Certification from Lupon Secretary)
   ============================================================ */
function kp_render_cert_from_lupon_sec($conn, $complaint, $repudiationReason, $repudiationBy, $issuedByName) {
    $refNo = $complaint['reference_number'] ?? '';
    $captain = kp_get_officer($conn, 'captain', (int)($complaint['assigned_to'] ?? 0));
    $secretary = kp_get_officer($conn, 'secretary');
    $secName   = $secretary['full_name'] ?? KP_FALLBACK_SECRETARY_NAME;
    $secSig    = $secretary['signature_path'] ?? null;
    $capName   = $captain['full_name']   ?? KP_FALLBACK_CAPTAIN_NAME;
    $capSig    = $captain['signature_path'] ?? null;

    $safeRef = preg_replace('/[^A-Za-z0-9\-]/', '-', $refNo);
    $folder  = KP_UPLOAD_SUBDIR . '/complainant';
    $absDir  = __DIR__ . '/../' . $folder;
    if (!file_exists($absDir)) @mkdir($absDir, 0777, true);
    $filename = $safeRef . '-kp20-cfa-lupon-secretary.pdf';
    $absPath  = $absDir . '/' . $filename;

    $pdf = kp_new_pdf('KP Form #20 — Certification to File Action', $secName);
    $pdf->SetFont('times', 'B', 15);
    $pdf->Cell(0, 8, 'CERTIFICATION TO FILE ACTION', 0, 1, 'C');
    $pdf->SetFont('times', 'I', 11);
    $pdf->Cell(0, 5, '(From Lupon Secretary — KP Form #20)', 0, 1, 'C');
    $pdf->Ln(5);
    kp_standard_case_block($pdf, $complaint);

    $pdf->SetFont('times', '', 11);
    $body = "This is to certify that:<br><br>"
          . "1. There has been a personal confrontation between the parties before the Punong Barangay / "
          . "Pangkat ng Tagapagkasundo;<br><br>"
          . "2. A settlement was reached;<br><br>"
          . "3. The settlement has been <b>repudiated</b> in a statement sworn to before the Punong Barangay "
          . "by <b>" . htmlspecialchars($repudiationBy ?: '_______________') . "</b> on the ground of "
          . "<b>" . htmlspecialchars($repudiationReason ?: '_______________') . "</b>; and<br><br>"
          . "4. Therefore, the corresponding complaint for the dispute may now be filed in court / government office.";
    $pdf->writeHTMLCell(0, 0, '', '', $body, 0, 1, false, true, 'J');

    $pdf->Ln(6);
    $pdf->SetFont('times', 'I', 10);
    $pdf->MultiCell(0, 5, 'Issued this ' . date('jS \of F, Y') . ' at ' . KP_BARANGAY_NAME . '.', 0, 'L');

    $pdf->Ln(12);
    if (!empty($secSig)) { kp_draw_signature($pdf, $secSig); $pdf->Ln(1); } else { $pdf->Ln(12); }
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, strtoupper($secName), 0, 1);
    $pdf->SetFont('times', 'I', 10);
    $pdf->Cell(0, 5, 'Lupon Secretary', 0, 1);

    $pdf->Ln(8);
    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(0, 5, 'ATTESTED:', 0, 1);
    if (!empty($capSig)) { kp_draw_signature($pdf, $capSig); $pdf->Ln(1); } else { $pdf->Ln(12); }
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 5, strtoupper($capName), 0, 1);
    $pdf->Cell(0, 5, 'Lupon Chairman', 0, 1);

    kp_render_footer($pdf);
    $pdf->Output($absPath, 'F');

    return ['abs_path'=>$absPath, 'rel_path'=>$folder.'/'.$filename, 'filename'=>$filename, 'form_tag'=>'kp20'];
}

/* ============================================================
   NEW renderer — KP Form #21 (Certification from Pangkat Secretary)
   ============================================================ */
function kp_render_cert_from_pangkat_sec($conn, $complaint, $issuedByName) {
    $refNo = $complaint['reference_number'] ?? '';
    $chair = kp_get_pangkat_officer($conn, (int)$complaint['id'], 'chair');
    $sec   = kp_get_pangkat_officer($conn, (int)$complaint['id'], 'secretary');
    $secName   = $sec['full_name']   ?? 'PANGKAT SECRETARY';
    $secSig    = $sec['signature_path'] ?? null;
    $chairName = $chair['full_name'] ?? 'PANGKAT CHAIRMAN';
    $chairSig  = $chair['signature_path'] ?? null;

    $safeRef = preg_replace('/[^A-Za-z0-9\-]/', '-', $refNo);
    $folder  = KP_UPLOAD_SUBDIR . '/pangkat';
    $absDir  = __DIR__ . '/../' . $folder;
    if (!file_exists($absDir)) @mkdir($absDir, 0777, true);
    $filename = $safeRef . '-kp21-cfa-pangkat-secretary.pdf';
    $absPath  = $absDir . '/' . $filename;

    $pdf = kp_new_pdf('KP Form #21 — Certification to File Action', $secName);
    $pdf->SetFont('times', 'B', 15);
    $pdf->Cell(0, 8, 'CERTIFICATION TO FILE ACTION', 0, 1, 'C');
    $pdf->SetFont('times', 'I', 11);
    $pdf->Cell(0, 5, '(From Pangkat Secretary — KP Form #21)', 0, 1, 'C');
    $pdf->Ln(5);
    kp_standard_case_block($pdf, $complaint);

    $pdf->SetFont('times', '', 11);
    $body = "This is to certify that:<br><br>"
          . "1. There has been a personal confrontation between the parties before the Punong Barangay "
          . "but mediation failed;<br><br>"
          . "2. The Pangkat ng Tagapagkasundo was constituted but the personal confrontation before the "
          . "Pangkat likewise did not result into a settlement; and<br><br>"
          . "3. Therefore, the corresponding complaint for the dispute may now be filed in court / government office.";
    $pdf->writeHTMLCell(0, 0, '', '', $body, 0, 1, false, true, 'J');

    $pdf->Ln(6);
    $pdf->SetFont('times', 'I', 10);
    $pdf->MultiCell(0, 5, 'Issued this ' . date('jS \of F, Y') . ' at ' . KP_BARANGAY_NAME . '.', 0, 'L');

    $pdf->Ln(12);
    if (!empty($secSig)) { kp_draw_signature($pdf, $secSig); $pdf->Ln(1); } else { $pdf->Ln(12); }
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, strtoupper($secName), 0, 1);
    $pdf->SetFont('times', 'I', 10);
    $pdf->Cell(0, 5, 'Pangkat Secretary', 0, 1);

    $pdf->Ln(8);
    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(0, 5, 'ATTESTED BY:', 0, 1);
    if (!empty($chairSig)) { kp_draw_signature($pdf, $chairSig); $pdf->Ln(1); } else { $pdf->Ln(12); }
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 5, strtoupper($chairName), 0, 1);
    $pdf->Cell(0, 5, 'Pangkat Chairman', 0, 1);

    kp_render_footer($pdf);
    $pdf->Output($absPath, 'F');

    return ['abs_path'=>$absPath, 'rel_path'=>$folder.'/'.$filename, 'filename'=>$filename, 'form_tag'=>'kp21'];
}

/* ============================================================
   NEW renderer — KP Form #22 (Certification from Pangkat Chairman)
   ============================================================ */
function kp_render_cert_to_file_action($conn, $complaint, $issuedByName) {
    $refNo = $complaint['reference_number'] ?? '';
    $chair = kp_get_pangkat_officer($conn, (int)$complaint['id'], 'chair');
    $sec   = kp_get_pangkat_officer($conn, (int)$complaint['id'], 'secretary');
    $chairName = $chair['full_name'] ?? 'PANGKAT CHAIRMAN';
    $chairSig  = $chair['signature_path'] ?? null;
    $secName   = $sec['full_name']   ?? 'PANGKAT SECRETARY';
    $secSig    = $sec['signature_path'] ?? null;

    $safeRef = preg_replace('/[^A-Za-z0-9\-]/', '-', $refNo);
    $folder  = KP_UPLOAD_SUBDIR . '/pangkat';
    $absDir  = __DIR__ . '/../' . $folder;
    if (!file_exists($absDir)) @mkdir($absDir, 0777, true);
    $filename = $safeRef . '-kp22-cfa-pangkat-chairman.pdf';
    $absPath  = $absDir . '/' . $filename;

    $pdf = kp_new_pdf('KP Form #22 — Certification to File Action', $chairName);
    $pdf->SetFont('times', 'B', 15);
    $pdf->Cell(0, 8, 'CERTIFICATION TO FILE ACTION', 0, 1, 'C');
    $pdf->SetFont('times', 'I', 11);
    $pdf->Cell(0, 5, '(From Pangkat Chairman — KP Form #22)', 0, 1, 'C');
    $pdf->Ln(5);
    kp_standard_case_block($pdf, $complaint);

    $pdf->SetFont('times', '', 11);
    $body = "This is to certify that:<br><br>"
          . "1. There was a personal confrontation between the parties before the Punong Barangay "
          . "but mediation failed;<br><br>"
          . "2. The Punong Barangay set the meeting of the parties for the constitution of the Pangkat;<br><br>"
          . "3. The <b>respondent willfully failed or refused to appear</b> without justifiable reason at the "
          . "conciliation proceedings before the Pangkat; and<br><br>"
          . "4. Therefore, the corresponding complaint for the dispute may now be filed in court / government office.";
    $pdf->writeHTMLCell(0, 0, '', '', $body, 0, 1, false, true, 'J');

    $pdf->Ln(6);
    $pdf->SetFont('times', 'I', 10);
    $pdf->MultiCell(0, 5, 'Issued this ' . date('jS \of F, Y') . ' at ' . KP_BARANGAY_NAME . '.', 0, 'L');

    $pdf->Ln(12);
    if (!empty($secSig)) { kp_draw_signature($pdf, $secSig); $pdf->Ln(1); } else { $pdf->Ln(12); }
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, strtoupper($secName), 0, 1);
    $pdf->SetFont('times', 'I', 10);
    $pdf->Cell(0, 5, 'Pangkat Secretary', 0, 1);

    $pdf->Ln(8);
    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(0, 5, 'ATTESTED BY:', 0, 1);
    if (!empty($chairSig)) { kp_draw_signature($pdf, $chairSig); $pdf->Ln(1); } else { $pdf->Ln(12); }
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 5, strtoupper($chairName), 0, 1);
    $pdf->Cell(0, 5, 'Pangkat Chairman', 0, 1);

    kp_render_footer($pdf);
    $pdf->Output($absPath, 'F');

    return ['abs_path'=>$absPath, 'rel_path'=>$folder.'/'.$filename, 'filename'=>$filename, 'form_tag'=>'kp22'];
}

/* ============================================================
   NEW renderer — KP Form #25 (Motion for Execution)
   ============================================================ */
function kp_render_motion_for_execution($conn, $complaint, $complainants, $respondents, $issuedByName) {
    $refNo = $complaint['reference_number'] ?? '';
    $captain = kp_get_officer($conn, 'captain', (int)($complaint['assigned_to'] ?? 0));
    $signer  = $captain['full_name'] ?? KP_FALLBACK_CAPTAIN_NAME;

    $safeRef = preg_replace('/[^A-Za-z0-9\-]/', '-', $refNo);
    $folder  = KP_UPLOAD_SUBDIR . '/complainant';
    $absDir  = __DIR__ . '/../' . $folder;
    if (!file_exists($absDir)) @mkdir($absDir, 0777, true);
    $filename = $safeRef . '-kp25-motion-for-execution.pdf';
    $absPath  = $absDir . '/' . $filename;

    $pdf = kp_new_pdf('KP Form #25 — Motion for Execution', $signer);
    $pdf->SetFont('times', 'B', 15);
    $pdf->Cell(0, 8, 'MOTION FOR EXECUTION', 0, 1, 'C');
    $pdf->SetFont('times', 'I', 11);
    $pdf->Cell(0, 5, '(KP Form #25)', 0, 1, 'C');
    $pdf->Ln(5);
    kp_standard_case_block($pdf, $complaint);

    $pdf->SetFont('times', '', 11);
    $body = "Complainant/s / Respondent/s state as follows:<br><br>"
          . "1. On <b>_______________</b> the parties in this case signed an amicable settlement / "
          . "received the arbitration award rendered by the Lupon / Chairman / Pangkat ng Tagapagkasundo;<br><br>"
          . "2. The period of ten (10) days from the above-stated date has expired without any of the parties "
          . "filing a sworn statement of repudiation of the settlement before the Lupon Chairman or a petition "
          . "for nullification of the arbitration award in court; and<br><br>"
          . "3. The amicable settlement / arbitration award is now <b>final and executory</b>.<br><br>"
          . "WHEREFORE, Complainant/s / Respondent/s request that the corresponding <b>Writ of Execution</b> be "
          . "issued by the Lupon Chairman in this case.";
    $pdf->writeHTMLCell(0, 0, '', '', $body, 0, 1, false, true, 'J');

    $pdf->Ln(6);
    $pdf->SetFont('times', 'I', 10);
    $pdf->MultiCell(0, 5, 'Filed this ' . date('jS \of F, Y') . ' at ' . KP_BARANGAY_NAME . '.', 0, 'L');

    $pdf->Ln(12);
    $pdf->SetFont('times', 'B', 10);
    $pdf->Cell(0, 5, '_____________________________', 0, 1);
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 5, 'Complainant/s / Respondent/s', 0, 1);

    kp_render_footer($pdf);
    $pdf->Output($absPath, 'F');

    return ['abs_path'=>$absPath, 'rel_path'=>$folder.'/'.$filename, 'filename'=>$filename, 'form_tag'=>'kp25'];
}

/* ============================================================
   NEW renderer — KP Form #26 (Notice of Hearing Re: Motion for Execution)
   ============================================================ */
function kp_render_notice_motion_execution($conn, $complaint, $hearing_date, $hearing_time, $issuedByName) {
    $refNo = $complaint['reference_number'] ?? '';
    $niceDate = $hearing_date ? date('l, F j, Y', strtotime($hearing_date)) : '[DATE]';
    $niceTime = $hearing_time ? date('g:i A', strtotime($hearing_time)) : '[TIME]';
    $captain = kp_get_officer($conn, 'captain', (int)($complaint['assigned_to'] ?? 0));
    $signer  = $captain['full_name'] ?? KP_FALLBACK_CAPTAIN_NAME;
    $signaturePath = $captain['signature_path'] ?? null;

    $safeRef = preg_replace('/[^A-Za-z0-9\-]/', '-', $refNo);
    $folder  = KP_UPLOAD_SUBDIR . '/complainant';
    $absDir  = __DIR__ . '/../' . $folder;
    if (!file_exists($absDir)) @mkdir($absDir, 0777, true);
    $filename = $safeRef . '-kp26-notice-motion-execution.pdf';
    $absPath  = $absDir . '/' . $filename;

    $pdf = kp_new_pdf('KP Form #26 — Notice of Hearing (Re: Motion for Execution)', $signer);
    $pdf->SetFont('times', 'B', 15);
    $pdf->Cell(0, 8, 'NOTICE OF HEARING', 0, 1, 'C');
    $pdf->SetFont('times', 'I', 11);
    $pdf->Cell(0, 5, '(Re: Motion for Execution — KP Form #26)', 0, 1, 'C');
    $pdf->Ln(5);
    kp_standard_case_block($pdf, $complaint);

    $pdf->SetFont('times', '', 11);
    $body = "You are hereby required to appear before me on <b>{$niceDate}</b> at <b>{$niceTime}</b>, "
          . "for the hearing of the <b>Motion for Execution</b>, copy of which is attached hereto.";
    $pdf->writeHTMLCell(0, 0, '', '', $body, 0, 1, false, true, 'J');

    $pdf->Ln(6);
    $pdf->SetFont('times', 'I', 10);
    $pdf->MultiCell(0, 5, 'Issued this ' . date('jS \of F, Y') . ' at ' . KP_BARANGAY_NAME . '.', 0, 'L');

    $pdf->Ln(12);
    if (!empty($signaturePath)) { kp_draw_signature($pdf, $signaturePath); $pdf->Ln(1); } else { $pdf->Ln(12); }
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, strtoupper($signer), 0, 1, 'R');
    $pdf->SetFont('times', 'I', 10);
    $pdf->Cell(0, 5, 'Punong Barangay / Lupon Chairman', 0, 1, 'R');

    kp_render_footer($pdf);
    $pdf->Output($absPath, 'F');

    return ['abs_path'=>$absPath, 'rel_path'=>$folder.'/'.$filename, 'filename'=>$filename, 'form_tag'=>'kp26'];
}

/* ============================================================
   NEW renderer — KP Form #27 (Notice of Execution)
   ============================================================ */
function kp_render_notice_of_execution($conn, $complaint, $obligedName, $amount, $issuedByName) {
    $refNo = $complaint['reference_number'] ?? '';
    $captain = kp_get_officer($conn, 'captain', (int)($complaint['assigned_to'] ?? 0));
    $signer  = $captain['full_name'] ?? KP_FALLBACK_CAPTAIN_NAME;
    $signaturePath = $captain['signature_path'] ?? null;

    $safeRef = preg_replace('/[^A-Za-z0-9\-]/', '-', $refNo);
    $folder  = KP_UPLOAD_SUBDIR . '/complainant';
    $absDir  = __DIR__ . '/../' . $folder;
    if (!file_exists($absDir)) @mkdir($absDir, 0777, true);
    $filename = $safeRef . '-kp27-notice-of-execution.pdf';
    $absPath  = $absDir . '/' . $filename;

    $pdf = kp_new_pdf('KP Form #27 — Notice of Execution', $signer);
    $pdf->SetFont('times', 'B', 15);
    $pdf->Cell(0, 8, 'NOTICE OF EXECUTION', 0, 1, 'C');
    $pdf->SetFont('times', 'I', 11);
    $pdf->Cell(0, 5, '(KP Form #27)', 0, 1, 'C');
    $pdf->Ln(5);
    kp_standard_case_block($pdf, $complaint);

    $safeObliged = htmlspecialchars($obligedName ?: '_______________');
    $safeAmount  = htmlspecialchars($amount ?: '_______________');
    $pdf->SetFont('times', '', 11);
    $body = "WHEREAS, on the date indicated in the settlement / award, an amicable settlement was signed by "
          . "the parties in the above-entitled case [or an arbitration award was rendered by the Punong Barangay / "
          . "Pangkat ng Tagapagkasundo];<br><br>"
          . "WHEREAS, the terms and conditions of the settlement, the dispositive portion of the award read:<br><br>"
          . "&nbsp;&nbsp;&nbsp;&nbsp;<i>[Terms of settlement / award here]</i><br><br>"
          . "The said settlement / award is now <b>final and executory</b>;<br><br>"
          . "WHEREAS, the party obliged <b>{$safeObliged}</b> has not complied voluntarily with the aforestated "
          . "amicable settlement / arbitration award, within the period of five (5) days from the date of hearing "
          . "on the motion for execution;<br><br>"
          . "NOW, THEREFORE, in behalf of the Lupong Tagapamayapa and by virtue of the powers vested in me and the "
          . "Lupon by the Katarungang Pambarangay Law and Rules, I shall cause to be realized from the goods and "
          . "personal property of <b>{$safeObliged}</b> the sum of <b>{$safeAmount}</b> upon the said amicable "
          . "settlement [or adjudged in the said arbitration award], unless voluntary compliance of said settlement "
          . "or award shall have been made upon receipt hereof.";
    $pdf->writeHTMLCell(0, 0, '', '', $body, 0, 1, false, true, 'J');

    $pdf->Ln(6);
    $pdf->SetFont('times', 'I', 10);
    $pdf->MultiCell(0, 5, 'Signed this ' . date('jS \of F, Y') . ' at ' . KP_BARANGAY_NAME . '.', 0, 'L');

    $pdf->Ln(12);
    if (!empty($signaturePath)) { kp_draw_signature($pdf, $signaturePath); $pdf->Ln(1); } else { $pdf->Ln(12); }
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, strtoupper($signer), 0, 1, 'R');
    $pdf->SetFont('times', 'I', 10);
    $pdf->Cell(0, 5, 'Punong Barangay', 0, 1, 'R');

    kp_render_footer($pdf);
    $pdf->Output($absPath, 'F');

    return ['abs_path'=>$absPath, 'rel_path'=>$folder.'/'.$filename, 'filename'=>$filename, 'form_tag'=>'kp27'];
}

/* ============================================================
   Public helpers — save / batch-save
   ============================================================ */
function save_kp_notice_for_party($conn, $complaint_id, $party_id, $party_type, $issuedByName = 'BARANGAY SECRETARY') {
    $out = [
        'ok' => false, 'party_id' => $party_id,
        'party_type' => $party_type,
        'role' => $party_type === 'complainant' ? 'Complainant' : 'Respondent',
        'name' => '', 'pdf_path' => null, 'error' => null,
    ];

    $complaint = kp_load_complaint($conn, $complaint_id);
    if (!$complaint) { $out['error'] = 'Complaint not found.'; return $out; }

    $ps = mysqli_prepare($conn,
        "SELECT id, party_type, full_name, address, contact_number
         FROM complaint_parties
         WHERE id = ? AND complaint_id = ? AND party_type = ?");
    mysqli_stmt_bind_param($ps, "iis", $party_id, $complaint_id, $party_type);
    mysqli_stmt_execute($ps);
    $party = mysqli_fetch_assoc(mysqli_stmt_get_result($ps));
    mysqli_stmt_close($ps);
    if (!$party) { $out['error'] = 'Party not found.'; return $out; }
    $out['name'] = $party['full_name'];

    list($complainants, $respondents, ) = kp_load_parties($conn, $complaint_id);

    try {
        $result = kp_render_notice($conn, $complaint, $party_type, $party, $complainants, $respondents, $issuedByName);
    } catch (Exception $e) {
        $out['error'] = 'PDF error: ' . $e->getMessage();
        return $out;
    }

    $up = mysqli_prepare($conn,
        "UPDATE complaint_parties
         SET notice_pdf_path = ?, notice_pdf_generated_at = NOW()
         WHERE id = ?");
    if (!$up) { $out['error'] = 'DB prepare failed: ' . mysqli_error($conn); return $out; }
    mysqli_stmt_bind_param($up, "si", $result['rel_path'], $party_id);
    if (!mysqli_stmt_execute($up)) {
        $out['error'] = 'DB update failed: ' . mysqli_stmt_error($up);
        mysqli_stmt_close($up);
        return $out;
    }
    mysqli_stmt_close($up);

    $out['ok'] = true;
    $out['pdf_path'] = $result['rel_path'];
    return $out;
}

function save_all_kp_notices($conn, $complaint_id, $issuedByName = 'BARANGAY SECRETARY') {
    $resp = ['success' => true, 'complainants' => [], 'respondents' => [], 'count_ok' => 0, 'count_failed' => 0];

    $ps = mysqli_prepare($conn,
        "SELECT id, party_type FROM complaint_parties
         WHERE complaint_id = ?
         ORDER BY FIELD(party_type,'complainant','respondent','witness'), id");
    mysqli_stmt_bind_param($ps, "i", $complaint_id);
    mysqli_stmt_execute($ps);
    $pr = mysqli_stmt_get_result($ps);
    $rows = [];
    while ($row = mysqli_fetch_assoc($pr)) $rows[] = $row;
    mysqli_stmt_close($ps);

    foreach ($rows as $r) {
        if ($r['party_type'] !== 'complainant' && $r['party_type'] !== 'respondent') continue;
        $res = save_kp_notice_for_party($conn, $complaint_id, (int)$r['id'], $r['party_type'], $issuedByName);
        if ($res['ok']) $resp['count_ok']++; else $resp['count_failed']++;
        if ($r['party_type'] === 'complainant') $resp['complainants'][] = $res;
        else                                    $resp['respondents'][]  = $res;
    }
    if ($resp['count_failed'] > 0) $resp['success'] = false;
    return $resp;
}

function save_kp_failure_notice_for_party(
    $conn, $complaint_id, $party_id, $party_type,
    $appearance_date, $appearance_time,
    $missed_hearing_date, $missed_hearing_time = null,
    $issuedByName = 'BARANGAY CAPTAIN'
) {
    $out = [
        'ok' => false, 'party_id' => $party_id, 'party_type' => $party_type,
        'role' => $party_type === 'complainant' ? 'Complainant' : 'Respondent',
        'form_tag' => ($party_type === 'complainant' ? 'kp18' : 'kp19'),
        'name' => '', 'pdf_path' => null, 'error' => null,
    ];

    if (!in_array($party_type, ['complainant','respondent'], true)) { $out['error'] = 'Invalid party_type.'; return $out; }
    if (!$appearance_date) { $out['error'] = 'Missing appearance date.'; return $out; }

    $complaint = kp_load_complaint($conn, $complaint_id);
    if (!$complaint) { $out['error'] = 'Complaint not found.'; return $out; }

    $ps = mysqli_prepare($conn,
        "SELECT id, party_type, full_name, address, contact_number
         FROM complaint_parties
         WHERE id = ? AND complaint_id = ? AND party_type = ?");
    mysqli_stmt_bind_param($ps, "iis", $party_id, $complaint_id, $party_type);
    mysqli_stmt_execute($ps);
    $party = mysqli_fetch_assoc(mysqli_stmt_get_result($ps));
    mysqli_stmt_close($ps);
    if (!$party) { $out['error'] = 'Party not found.'; return $out; }
    $out['name'] = $party['full_name'];

    try {
        $result = kp_render_failure_to_appear_notice(
            $conn, $complaint, $party_type, $party,
            $appearance_date, $appearance_time,
            $missed_hearing_date, $missed_hearing_time,
            $issuedByName
        );
    } catch (Exception $e) {
        $out['error'] = 'PDF error: ' . $e->getMessage();
        return $out;
    }

    $out['ok'] = true;
    $out['pdf_path'] = $result['rel_path'];
    $out['form_tag'] = $result['form_tag'];
    return $out;
}

function save_all_kp_failure_notices(
    $conn, $complaint_id,
    $appearance_date, $appearance_time,
    $missed_hearing_date, $missed_hearing_time,
    $absent_parties, $issuedByName = 'BARANGAY CAPTAIN'
) {
    $resp = ['success' => true, 'notices' => [], 'count_ok' => 0, 'count_failed' => 0];

    if (!is_array($absent_parties)) $absent_parties = [];

    foreach ($absent_parties as $ap) {
        $pid = (int)($ap['id'] ?? 0);
        $ptype = $ap['party_type'] ?? ($ap['role'] ?? '');
        if (!$pid || !in_array($ptype, ['complainant','respondent'], true)) {
            $resp['count_failed']++;
            continue;
        }
        $res = save_kp_failure_notice_for_party(
            $conn, $complaint_id, $pid, $ptype,
            $appearance_date, $appearance_time,
            $missed_hearing_date, $missed_hearing_time,
            $issuedByName
        );
        if ($res['ok']) $resp['count_ok']++; else $resp['count_failed']++;
        $resp['notices'][] = $res;
    }
    if ($resp['count_failed'] > 0) $resp['success'] = false;
    return $resp;
}

function save_kp_form_10_for_party(
    $conn, $complaint_id, $party_id, $party_type,
    $constitution_date, $constitution_time,
    $issuedByName = 'BARANGAY CAPTAIN'
) {
    $out = [
        'ok' => false, 'party_id' => $party_id, 'party_type' => $party_type,
        'role' => $party_type === 'complainant' ? 'Complainant' : 'Respondent',
        'form_tag' => 'kp10', 'name' => '', 'pdf_path' => null, 'error' => null,
    ];

    if (!in_array($party_type, ['complainant','respondent'], true)) { $out['error'] = 'Invalid party_type.'; return $out; }
    if (!$constitution_date) { $out['error'] = 'Missing constitution date.'; return $out; }

    $complaint = kp_load_complaint($conn, $complaint_id);
    if (!$complaint) { $out['error'] = 'Complaint not found.'; return $out; }

    $ps = mysqli_prepare($conn,
        "SELECT id, party_type, full_name, address, contact_number
         FROM complaint_parties
         WHERE id = ? AND complaint_id = ? AND party_type = ?");
    mysqli_stmt_bind_param($ps, "iis", $party_id, $complaint_id, $party_type);
    mysqli_stmt_execute($ps);
    $party = mysqli_fetch_assoc(mysqli_stmt_get_result($ps));
    mysqli_stmt_close($ps);
    if (!$party) { $out['error'] = 'Party not found.'; return $out; }
    $out['name'] = $party['full_name'];

    try {
        $result = kp_render_pangkat_constitution_notice(
            $conn, $complaint, $party, $party_type,
            $constitution_date, $constitution_time,
            $issuedByName
        );
    } catch (Exception $e) {
        $out['error'] = 'PDF error: ' . $e->getMessage();
        return $out;
    }

    $up = mysqli_prepare($conn,
        "UPDATE complaint_parties
         SET kp10_pdf_path = ?, kp10_issued_at = NOW()
         WHERE id = ?");
    if ($up) {
        mysqli_stmt_bind_param($up, "si", $result['rel_path'], $party_id);
        mysqli_stmt_execute($up);
        mysqli_stmt_close($up);
    }

    $out['ok'] = true;
    $out['pdf_path'] = $result['rel_path'];
    return $out;
}

function save_all_kp_form_10($conn, $complaint_id, $constitution_date, $constitution_time, $issuedByName = 'BARANGAY CAPTAIN') {
    $resp = ['success' => true, 'notices' => [], 'count_ok' => 0, 'count_failed' => 0];

    $ps = mysqli_prepare($conn,
        "SELECT id, party_type FROM complaint_parties
         WHERE complaint_id = ?
           AND party_type IN ('complainant','respondent')
         ORDER BY FIELD(party_type,'complainant','respondent'), id");
    mysqli_stmt_bind_param($ps, "i", $complaint_id);
    mysqli_stmt_execute($ps);
    $pr = mysqli_stmt_get_result($ps);
    $rows = [];
    while ($row = mysqli_fetch_assoc($pr)) $rows[] = $row;
    mysqli_stmt_close($ps);

    foreach ($rows as $r) {
        $res = save_kp_form_10_for_party(
            $conn, $complaint_id, (int)$r['id'], $r['party_type'],
            $constitution_date, $constitution_time,
            $issuedByName
        );
        if ($res['ok']) $resp['count_ok']++; else $resp['count_failed']++;
        $resp['notices'][] = $res;
    }
    if ($resp['count_failed'] > 0) $resp['success'] = false;
    return $resp;
}

function save_kp_form_11_for_member($conn, $complaint_id, $member_id, $issuedByName = 'BARANGAY CAPTAIN') {
    $out = [
        'ok' => false, 'member_id' => $member_id, 'form_tag' => 'kp11',
        'name' => '', 'pdf_path' => null, 'error' => null,
    ];

    if (!$member_id) { $out['error'] = 'Missing member_id.'; return $out; }

    $complaint = kp_load_complaint($conn, $complaint_id);
    if (!$complaint) { $out['error'] = 'Complaint not found.'; return $out; }

    $ms = mysqli_prepare($conn,
        "SELECT id, full_name FROM admin
         WHERE id = ? AND admin_role = 'lupon' AND is_active = 1");
    mysqli_stmt_bind_param($ms, "i", $member_id);
    mysqli_stmt_execute($ms);
    $member = mysqli_fetch_assoc(mysqli_stmt_get_result($ms));
    mysqli_stmt_close($ms);
    if (!$member) { $out['error'] = 'Member not found.'; return $out; }
    $out['name'] = $member['full_name'];

    try {
        $result = kp_render_chosen_member_notice($conn, $complaint, $member, $issuedByName);
    } catch (Exception $e) {
        $out['error'] = 'PDF error: ' . $e->getMessage();
        return $out;
    }

    $up = mysqli_prepare($conn,
        "UPDATE complaint_pangkat
         SET kp11_pdf_path = ?, kp11_issued_at = NOW()
         WHERE complaint_id = ? AND member_id = ?");
    if ($up) {
        mysqli_stmt_bind_param($up, "sii", $result['rel_path'], $complaint_id, $member_id);
        mysqli_stmt_execute($up);
        mysqli_stmt_close($up);
    }

    $out['ok'] = true;
    $out['pdf_path'] = $result['rel_path'];
    return $out;
}

function save_all_kp_form_11($conn, $complaint_id, $issuedByName = 'BARANGAY CAPTAIN') {
    $resp = ['success' => true, 'notices' => [], 'count_ok' => 0, 'count_failed' => 0];

    $ps = mysqli_prepare($conn,
        "SELECT member_id FROM complaint_pangkat
         WHERE complaint_id = ?
         ORDER BY id ASC");
    mysqli_stmt_bind_param($ps, "i", $complaint_id);
    mysqli_stmt_execute($ps);
    $pr = mysqli_stmt_get_result($ps);
    $memberIds = [];
    while ($row = mysqli_fetch_assoc($pr)) $memberIds[] = (int)$row['member_id'];
    mysqli_stmt_close($ps);

    foreach ($memberIds as $mid) {
        $res = save_kp_form_11_for_member($conn, $complaint_id, $mid, $issuedByName);
        if ($res['ok']) $resp['count_ok']++; else $resp['count_failed']++;
        $resp['notices'][] = $res;
    }
    if ($resp['count_failed'] > 0) $resp['success'] = false;
    return $resp;
}

function save_bar_action_cert($conn, $complaint_id, $dismissed_at, $reason, $issuedByName = 'BARANGAY SECRETARY') {
    $out = ['ok' => false, 'pdf_path' => null, 'error' => null, 'form_tag' => 'kp23'];

    $complaint = kp_load_complaint($conn, $complaint_id);
    if (!$complaint) { $out['error'] = 'Complaint not found.'; return $out; }

    $complainants = [];
    $as = mysqli_prepare($conn,
        "SELECT id, party_type, full_name, address, contact_number
         FROM complaint_parties
         WHERE complaint_id = ? AND party_type = 'complainant'
         ORDER BY id");
    mysqli_stmt_bind_param($as, "i", $complaint_id);
    mysqli_stmt_execute($as);
    $ar = mysqli_stmt_get_result($as);
    while ($row = mysqli_fetch_assoc($ar)) $complainants[] = $row;
    mysqli_stmt_close($as);

    try {
        $result = kp_render_bar_action_cert($conn, $complaint, $complainants, $dismissed_at, $reason, $issuedByName);
    } catch (Exception $e) {
        $out['error'] = 'PDF error: ' . $e->getMessage();
        return $out;
    }

    $out['ok'] = true;
    $out['pdf_path'] = $result['rel_path'];
    return $out;
}

function save_bar_counterclaim_cert($conn, $complaint_id, $barred_at, $reason, $issuedByName = 'BARANGAY SECRETARY') {
    $out = ['ok' => false, 'pdf_path' => null, 'error' => null, 'form_tag' => 'kp24'];

    $complaint = kp_load_complaint($conn, $complaint_id);
    if (!$complaint) { $out['error'] = 'Complaint not found.'; return $out; }

    $respondents = [];
    $as = mysqli_prepare($conn,
        "SELECT id, party_type, full_name, address, contact_number
         FROM complaint_parties
         WHERE complaint_id = ? AND party_type = 'respondent'
         ORDER BY id");
    mysqli_stmt_bind_param($as, "i", $complaint_id);
    mysqli_stmt_execute($as);
    $ar = mysqli_stmt_get_result($as);
    while ($row = mysqli_fetch_assoc($ar)) $respondents[] = $row;
    mysqli_stmt_close($as);

    try {
        $result = kp_render_bar_counterclaim_cert($conn, $complaint, $respondents, $barred_at, $reason, $issuedByName);
    } catch (Exception $e) {
        $out['error'] = 'PDF error: ' . $e->getMessage();
        return $out;
    }

    $out['ok'] = true;
    $out['pdf_path'] = $result['rel_path'];
    return $out;
}

/* ============================================================
   KP #16 auto on settle
   ============================================================ */
function save_kp_form_16_on_settlement($conn, $complaint_id, $mode, $termsText = '') {
    $out = ['ok' => false, 'pdf_path' => null, 'error' => null, 'form_tag' => 'kp16', 'mode' => $mode];

    $complaint = kp_load_complaint($conn, $complaint_id);
    if (!$complaint) { $out['error'] = 'Complaint not found.'; return $out; }

    list($complainants, $respondents, ) = kp_load_parties($conn, $complaint_id);

    if (!$termsText) {
        $ns = mysqli_prepare($conn, "SELECT resolution_notes FROM complaints WHERE id = ?");
        mysqli_stmt_bind_param($ns, "i", $complaint_id);
        mysqli_stmt_execute($ns);
        $nrow = mysqli_fetch_assoc(mysqli_stmt_get_result($ns));
        mysqli_stmt_close($ns);
        $termsText = $nrow['resolution_notes'] ?? '';
    }

    $issuedByName = 'BRITE SYSTEM';
    try {
        $result = kp_render_amicable_settlement(
            $conn, $complaint, $complainants, $respondents, $termsText, $mode, $issuedByName
        );
    } catch (Exception $e) {
        $out['error'] = 'PDF error: ' . $e->getMessage();
        return $out;
    }

    $u = mysqli_prepare($conn,
        "UPDATE complaints SET kp16_pdf_path = ?, kp16_generated_at = NOW() WHERE id = ?");
    if ($u) {
        mysqli_stmt_bind_param($u, "si", $result['rel_path'], $complaint_id);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);
    }

    $out['ok'] = true;
    $out['pdf_path'] = $result['rel_path'];
    return $out;
}

/* ============================================================
   On-demand KP form dispatcher
   $form_tag: kp12 | kp13 | kp14 | kp15 | kp16 | kp17 |
              kp20 | kp21 | kp22 | kp25 | kp26 | kp27
   ============================================================ */
function generate_kp_form_on_demand($conn, $complaint_id, $form_tag, $extra = []) {
    $out = ['ok' => false, 'form_tag' => $form_tag, 'pdf_path' => null, 'error' => null];

    $complaint = kp_load_complaint($conn, $complaint_id);
    if (!$complaint) { $out['error'] = 'Complaint not found.'; return $out; }

    list($complainants, $respondents, $witnesses) = kp_load_parties($conn, $complaint_id);

    $issuedByName = $extra['issued_by'] ?? 'BRITE SYSTEM';

    try {
        switch ($form_tag) {
            case 'kp12':
                $res = kp_render_conciliation_hearing_notice(
                    $conn, $complaint, $complainants, $respondents,
                    $extra['hearing_date'] ?? ($complaint['hearing_date'] ?? date('Y-m-d')),
                    $extra['hearing_time'] ?? ($complaint['hearing_time'] ?? '09:00'),
                    $extra['location'] ?? ($complaint['hearing_location'] ?? 'Barangay Hall'),
                    $issuedByName
                );
                break;

            case 'kp13':
                $res = kp_render_subpoena(
                    $conn, $complaint, $witnesses,
                    $extra['hearing_date'] ?? ($complaint['hearing_date'] ?? date('Y-m-d')),
                    $extra['hearing_time'] ?? ($complaint['hearing_time'] ?? '09:00'),
                    $issuedByName
                );
                break;

            case 'kp14':
                $res = kp_render_agreement_for_arbitration(
                    $conn, $complaint, $complainants, $respondents, $issuedByName
                );
                break;

            case 'kp15':
                $res = kp_render_arbitration_award(
                    $conn, $complaint, $extra['award_text'] ?? '', $issuedByName
                );
                break;

            case 'kp16':
                $res = kp_render_amicable_settlement(
                    $conn, $complaint, $complainants, $respondents,
                    $extra['terms_text'] ?? '', $extra['mode'] ?? 'mediation', $issuedByName
                );
                break;

            case 'kp17':
                $res = kp_render_repudiation(
                    $conn, $complaint, $extra['reason'] ?? '', $issuedByName
                );
                break;

            case 'kp20':
                $res = kp_render_cert_from_lupon_sec(
                    $conn, $complaint,
                    $extra['reason'] ?? '',
                    $extra['repudiated_by'] ?? '',
                    $issuedByName
                );
                break;

            case 'kp21':
                $res = kp_render_cert_from_pangkat_sec($conn, $complaint, $issuedByName);
                break;

            case 'kp22':
                $res = kp_render_cert_to_file_action($conn, $complaint, $issuedByName);
                break;

            case 'kp25':
                $res = kp_render_motion_for_execution(
                    $conn, $complaint, $complainants, $respondents, $issuedByName
                );
                break;

            case 'kp26':
                $res = kp_render_notice_motion_execution(
                    $conn, $complaint,
                    $extra['hearing_date'] ?? date('Y-m-d'),
                    $extra['hearing_time'] ?? '09:00',
                    $issuedByName
                );
                break;

            case 'kp27':
                $res = kp_render_notice_of_execution(
                    $conn, $complaint,
                    $extra['obliged_name'] ?? '',
                    $extra['amount'] ?? '',
                    $issuedByName
                );
                break;

            default:
                $out['error'] = 'Unsupported form_tag: ' . $form_tag;
                return $out;
        }
    } catch (Exception $e) {
        $out['error'] = 'PDF error: ' . $e->getMessage();
        return $out;
    }

    // Persist path on complaints for the forms that have a column
    $colMap = [
        'kp15' => 'kp15_pdf_path',
        'kp16' => 'kp16_pdf_path',
        'kp17' => 'kp17_pdf_path',
        'kp20' => 'kp20_pdf_path',
        'kp21' => 'kp21_pdf_path',
        'kp22' => 'kp22_pdf_path',
    ];
    if (isset($colMap[$form_tag])) {
        $col = $colMap[$form_tag];
        $genCol = str_replace('_pdf_path', '_generated_at', $col);
        $u = mysqli_prepare($conn,
            "UPDATE complaints SET {$col} = ?, {$genCol} = NOW() WHERE id = ?");
        if ($u) {
            mysqli_stmt_bind_param($u, "si", $res['rel_path'], $complaint_id);
            mysqli_stmt_execute($u);
            mysqli_stmt_close($u);
        }
    }

    $out['ok'] = true;
    $out['pdf_path'] = $res['rel_path'];
    $out['form_tag'] = $res['form_tag'] ?? $form_tag;
    return $out;
}

/* ============================================================
   HTTP entrypoint — only runs when NOT called internally
   ============================================================ */
if (!$IS_INTERNAL) {
    $complaint_id = (int)($_POST['complaint_id'] ?? $_GET['complaint_id'] ?? 0);
    $party_type   = trim($_POST['party_type'] ?? $_GET['party_type'] ?? '');
    $party_id     = (int)($_POST['party_id'] ?? $_GET['party_id'] ?? 0);
    $form         = trim($_POST['form'] ?? $_GET['form'] ?? '');

    if (!$complaint_id) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Missing complaint_id.']);
        exit;
    }

    // KP Form #11 reprint by member_id
    if ($form === 'kp11' && $party_id) {
        $res = save_kp_form_11_for_member($conn, $complaint_id, $party_id, $adminRow['full_name'] ?? 'BARANGAY CAPTAIN');
        while (ob_get_level() > 1) ob_end_clean();
        header('Content-Type: application/json');
        if ($res['ok']) {
            echo json_encode([
                'success'   => true,
                'message'   => "KP Form #11 regenerated for {$res['name']}.",
                'form'      => 'KP_FORM_11',
                'pdf_url'   => $res['pdf_path'],
                'filename'  => basename($res['pdf_path']),
            ]);
        } else {
            echo json_encode(['success'=>false,'message'=>$res['error'] ?? 'Failed.']);
        }
        exit;
    }

    // On-demand single form
    if (in_array($form, ['kp12','kp13','kp14','kp15','kp16','kp17','kp20','kp21','kp22','kp25','kp26','kp27'], true)) {
        $extra = [
            'issued_by'     => $adminRow['full_name'] ?? 'BRITE SYSTEM',
            'hearing_date'  => $_POST['hearing_date']  ?? $_GET['hearing_date']  ?? null,
            'hearing_time'  => $_POST['hearing_time']  ?? $_GET['hearing_time']  ?? null,
            'location'      => $_POST['location']      ?? $_GET['location']      ?? null,
            'award_text'    => $_POST['award_text']    ?? $_GET['award_text']    ?? '',
            'terms_text'    => $_POST['terms_text']    ?? $_GET['terms_text']    ?? '',
            'mode'          => $_POST['mode']          ?? $_GET['mode']          ?? 'mediation',
            'reason'        => $_POST['reason']        ?? $_GET['reason']        ?? '',
            'repudiated_by' => $_POST['repudiated_by'] ?? $_GET['repudiated_by'] ?? '',
            'obliged_name'  => $_POST['obliged_name']  ?? $_GET['obliged_name']  ?? '',
            'amount'        => $_POST['amount']        ?? $_GET['amount']        ?? '',
        ];
        $res = generate_kp_form_on_demand($conn, $complaint_id, $form, $extra);
        while (ob_get_level() > 1) ob_end_clean();
        header('Content-Type: application/json');
        if ($res['ok']) {
            echo json_encode([
                'success'  => true,
                'message'  => strtoupper($form) . ' generated.',
                'form'     => strtoupper($form),
                'pdf_url'  => $res['pdf_path'],
                'filename' => basename($res['pdf_path']),
            ]);
        } else {
            echo json_encode(['success'=>false,'message'=>$res['error'] ?? 'Failed.']);
        }
        exit;
    }

    // Party notice / summons (KP #8 / #9)
    if (in_array($party_type, ['complainant','respondent'], true)) {
        if (!$party_id) {
            $fs = mysqli_prepare($conn,
                "SELECT id FROM complaint_parties
                 WHERE complaint_id = ? AND party_type = ? ORDER BY id LIMIT 1");
            mysqli_stmt_bind_param($fs, "is", $complaint_id, $party_type);
            mysqli_stmt_execute($fs);
            $party_id = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($fs))['id'] ?? 0);
            mysqli_stmt_close($fs);
        }
        if (!$party_id) {
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No party of that type found.']);
            exit;
        }

        $res = save_kp_notice_for_party(
            $conn, $complaint_id, $party_id, $party_type, $adminRow['full_name'] ?? 'BARANGAY SECRETARY'
        );

        if (!$res['ok']) {
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Failed to generate.']);
            exit;
        }

        $formLabel = $party_type === 'complainant' ? 'KP Form #8 (Notice of Hearing)' : 'KP Form #9 (Summons)';
        $note = "$formLabel regenerated for {$res['name']}.";
        $ls = mysqli_prepare($conn, "INSERT INTO complaint_updates
            (complaint_id, update_type, previous_status, new_status, notes, updated_by, updated_by_role)
            VALUES (?, 'note', NULL, NULL, ?, ?, ?)");
        mysqli_stmt_bind_param($ls, "isis", $complaint_id, $note, $admin_id, $adminRow['admin_role']);
        mysqli_stmt_execute($ls);
        mysqli_stmt_close($ls);

        while (ob_get_level() > 1) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success'   => true,
            'message'   => $note,
            'form'      => $party_type === 'complainant' ? 'KP_FORM_8' : 'KP_FORM_9',
            'party_type'=> $party_type,
            'pdf_url'   => $res['pdf_path'],
            'pdf_path'  => $res['pdf_path'],
            'filename'  => basename($res['pdf_path']),
        ]);
        exit;
    }

    // Batch: all parties' notices
    $batch = save_all_kp_notices($conn, $complaint_id, $adminRow['full_name'] ?? 'BARANGAY SECRETARY');

    while (ob_get_level() > 1) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success'      => $batch['success'],
        'message'      => "Generated {$batch['count_ok']} notice(s); {$batch['count_failed']} failed.",
        'complainants' => $batch['complainants'],
        'respondents'  => $batch['respondents'],
    ]);
    exit;
}

// Internal callers just fall through and use the functions above.