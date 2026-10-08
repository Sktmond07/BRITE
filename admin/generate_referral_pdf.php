<?php
// admin/generate_referral_pdf.php
// Generates an Endorsement Letter PDF — TEMPLATED BY ESCALATION TYPE.
// Optional: attach an Evidence page (photos + file list) as a 2nd page.
// Saves to /uploads/referrals/
// TWO-STAGE FLOW: Print first → Send to Captain later
// Optionally also generates a Certificate of Non-Jurisdiction (if flagged)
// ONLY the Secretary can call this.

ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

// ═══════════════════════════════════════════════════════════
// AUTH
// ═══════════════════════════════════════════════════════════
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

$admin_id = $_SESSION['user_id'];
$s = mysqli_prepare($conn, "SELECT admin_role, full_name FROM admin WHERE id = ?");
mysqli_stmt_bind_param($s, "i", $admin_id);
mysqli_stmt_execute($s);
$adminRow = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
if (!$adminRow || $adminRow['admin_role'] !== 'secretary') {
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode(['success'=>false,'message'=>'Only the Secretary can generate referrals.']);
    exit;
}

// ═══════════════════════════════════════════════════════════
// INPUT
// ═══════════════════════════════════════════════════════════
$complaint_id          = (int)($_POST['complaint_id'] ?? 0);
$refer_to              = trim($_POST['refer_to'] ?? '');
$referral_date         = trim($_POST['referral_date'] ?? '');
$actions_json          = $_POST['actions'] ?? '{}';
$notes                 = trim($_POST['notes'] ?? '');
$also_non_jurisdiction = ($_POST['also_non_jurisdiction'] ?? '0') === '1';
$nj_reason             = trim($_POST['nj_reason'] ?? '');
$attach_evidence       = ($_POST['attach_evidence'] ?? '0') === '1';   // ← NEW

if (!$complaint_id || !$refer_to || !$referral_date) {
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode(['success'=>false,'message'=>'Missing required fields.']);
    exit;
}

$actions = json_decode($actions_json, true) ?: [];

// ═══════════════════════════════════════════════════════════
// FETCH COMPLAINT + PARTIES
// ═══════════════════════════════════════════════════════════
$stmt = mysqli_prepare($conn,
    "SELECT c.*, r.first_name, r.last_name, r.address AS res_address, r.email AS res_email
     FROM complaints c
     JOIN resident r ON c.created_by = r.id
     WHERE c.id = ?");
mysqli_stmt_bind_param($stmt, "i", $complaint_id);
mysqli_stmt_execute($stmt);
$complaint = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$complaint) {
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode(['success'=>false,'message'=>'Complaint not found.']);
    exit;
}

$complainants = []; $respondents = [];
$s = mysqli_prepare($conn,
    "SELECT party_type, full_name, address, contact_number
     FROM complaint_parties WHERE complaint_id = ? ORDER BY id");
mysqli_stmt_bind_param($s, "i", $complaint_id);
mysqli_stmt_execute($s);
$r = mysqli_stmt_get_result($s);
while ($row = mysqli_fetch_assoc($r)) {
    if ($row['party_type'] === 'complainant')    $complainants[] = $row;
    elseif ($row['party_type'] === 'respondent') $respondents[]  = $row;
}

// ANONYMITY REDACTION
$is_anonymous = intval($complaint['is_anonymous'] ?? 0);
if ($is_anonymous === 1) {
    foreach ($complainants as $i => $p) {
        $complainants[$i]['full_name']      = 'Concerned Resident';
        $complainants[$i]['address']        = 'Suppressed for security';
        $complainants[$i]['contact_number'] = 'Redacted';
    }
}

// INCIDENTS
$incidents = [];
$s = mysqli_prepare($conn, "SELECT * FROM complaint_incidents WHERE complaint_id = ? ORDER BY incident_date, id");
mysqli_stmt_bind_param($s, "i", $complaint_id);
mysqli_stmt_execute($s);
$r = mysqli_stmt_get_result($s);
while ($row = mysqli_fetch_assoc($r)) $incidents[] = $row;

// EVIDENCE (only fetched if requested)
$evidenceFiles = [];
if ($attach_evidence) {
    $s = mysqli_prepare($conn,
        "SELECT * FROM complaint_evidence WHERE complaint_id = ? ORDER BY uploaded_at ASC");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    while ($row = mysqli_fetch_assoc($r)) $evidenceFiles[] = $row;
}

// ═══════════════════════════════════════════════════════════
// ACTION LABELS
// ═══════════════════════════════════════════════════════════
$actionLabels = [];
if (!empty($actions['blotter']))     $actionLabels[] = 'Recorded in Barangay Blotter';
if (!empty($actions['referral']))    $actionLabels[] = 'Referral Letter prepared';
if (!empty($actions['bpo']))         $actionLabels[] = 'Barangay Protection Order (BPO) issued';
if (!empty($actions['dswd']))        $actionLabels[] = 'Coordinated with DSWD / MSWDO';
if (!empty($actions['pnp']))         $actionLabels[] = 'PNP-WCPD / PNP notified';
if (!empty($actions['bantay_bata'])) $actionLabels[] = 'Bantay Bata 163 notified';
if (!empty($actions['pdea']))        $actionLabels[] = 'PDEA notified';
if (!empty($actions['dole']))        $actionLabels[] = 'DOLE / NLRC notified';

// ═══════════════════════════════════════════════════════════
// OUTPUT PATHS
// ═══════════════════════════════════════════════════════════
$upload_dir = __DIR__ . '/../uploads/referrals/';
if (!file_exists($upload_dir)) @mkdir($upload_dir, 0777, true);

$filename = 'REF-' . $complaint['reference_number'] . '.pdf';
$filepath = $upload_dir . $filename;
$rel_path = 'uploads/referrals/' . $filename;

// ═══════════════════════════════════════════════════════════
// ESCALATION TYPE DETECTION
// ═══════════════════════════════════════════════════════════
$escalation = strtolower(trim($complaint['escalation_type'] ?: ''));

if ($escalation === '') {
    $desc = strtolower($complaint['description'] ?? '');
    $patterns = [
        'rape'             => ['ginahasa','gahasa','rape','pinilit na makipagtalik','sexual assault'],
        'vawc'             => ['sinasaktan ako ng asawa','binubugbog ako','sinakal ako ng asawa','vawc','ra 9262'],
        'child_abuse'      => ['pang-aabuso sa bata','child abuse','ra 7610','pinagtatrabaho ang bata'],
        'drugs'            => ['shabu','droga','marijuana','nagbebenta ng droga','drug den','ra 9165'],
        'illegal_gambling' => ['jueteng','tupada','sabong','illegal gambling','pd 1602'],
        'labor'            => ['hindi sumasahod','delayed sahod','labor dispute','dole','nlrc'],
        'government'       => ['gobyerno','barangay official','kapitan','public officer'],
        'high_penalty'     => ['murder','homicide','kidnapping','robbery with violence','terrorism'],
    ];
    foreach ($patterns as $type => $kw) {
        foreach ($kw as $k) {
            if (strpos($desc, $k) !== false) { $escalation = $type; break 2; }
        }
    }
}
if ($escalation === '') $escalation = 'government';

// ═══════════════════════════════════════════════════════════
// TEMPLATE VARIABLES
// ═══════════════════════════════════════════════════════════
$client       = $complainants[0] ?? null;
$clientName   = $client['full_name'] ?? '—';
$clientAddr   = $client['address']   ?? ($complaint['res_address'] ?? '—');
$clientAge    = '—';
$respondent   = $respondents[0] ?? null;
$respName     = $respondent['full_name'] ?? '—';
$niceDate     = date('F j, Y', strtotime($referral_date));

// ═══════════════════════════════════════════════════════════
// TEMPLATE LIBRARY — simple titles, per case type
// ═══════════════════════════════════════════════════════════
$templates = [

    // VAWC — VAWC Desk Officer signs, Captain notes
    'vawc' => [
        'title'       => 'Violence Against Women and Children',
        'addressee'   => "THE OFFICER-IN-CHARGE\nPNP Women and Children Protection Desk\nSto. Tomas, Pampanga",
        'salutation'  => 'Dear Sir/Madam:',
        'body'        => "This is to endorse the case of <b>{$clientName}</b>, <b>{$clientAge}</b> years old, "
                       . "a resident of <b>{$clientAddr}</b>, complainant of the case entitled "
                       . "\"<b>{$complaint['title']}</b>\" against <b>{$respName}</b>.<br><br>"
                       . "She/He will discuss with you the entire story of her/his problem "
                       . "<b>for your assessment, immediate attention, and appropriate intervention</b>.<br><br>"
                       . "This case falls under <b>Republic Act No. 9262</b> (Anti-Violence Against Women and Their Children Act of 2004) "
                       . "and is therefore <b>NOT COVERED by the Katarungang Pambarangay</b>. No mediation was conducted.",
        'closing'     => "We are hoping for your positive response on this endorsement. "
                       . "Thank you in advance and warm regards.",
        'sign_off'    => "Truly yours,",
        'sign_name'   => "___________________________<br><b>(Name of VAWC Desk Officer &amp; Signature)</b>",
        'noted_off'   => "Noted:",
        'noted_name'  => "___________________________<br><b>(Name &amp; Signature of Brgy. Captain)</b><br>Punong Barangay",
        'cc'          => "Provincial Prosecutor's Office\nDSWD / MSWDO",
    ],

    // RAPE / SEXUAL ASSAULT
    'rape' => [
        'title'       => 'Sexual Assault / Rape',
        'addressee'   => "THE OFFICER-IN-CHARGE\nPNP Women and Children Protection Desk (WCPD)\nSto. Tomas, Pampanga",
        'salutation'  => 'Dear Sir/Madam:',
        'body'        => "This is to endorse the case of <b>{$clientName}</b>, <b>{$clientAge}</b> years old, "
                       . "a resident of <b>{$clientAddr}</b>, complainant of the case entitled "
                       . "\"<b>{$complaint['title']}</b>\" against <b>{$respName}</b>.<br><br>"
                       . "She/He will discuss with you the entire story of her/his problem "
                       . "<b>for your assessment, immediate attention, and appropriate intervention</b>.<br><br>"
                       . "This case involves <b>alleged sexual assault / rape</b> under <b>Republic Act No. 8353</b> "
                       . "(Anti-Rape Law of 1997) and <b>Republic Act No. 11648</b>, and is therefore "
                       . "<b>expressly excluded from the jurisdiction of the Katarungang Pambarangay</b>. "
                       . "No mediation was conducted, nor shall any be conducted.",
        'closing'     => "We respectfully request your immediate action and coordination with the DSWD "
                       . "and the Municipal Health Office for the victim's protection and medical examination.",
        'sign_off'    => "Truly yours,",
        'sign_name'   => "___________________________<br><b>(Name &amp; Signature of Brgy. Captain)</b><br>Punong Barangay",
        'noted_off'   => null,
        'noted_name'  => null,
        'cc'          => "Provincial Prosecutor's Office\nDSWD / MSWDO\nMunicipal Health Office",
    ],

    // CHILD ABUSE
    'child_abuse' => [
        'title'       => 'Child Abuse',
        'addressee'   => "THE OFFICER-IN-CHARGE\nPNP Women and Children Protection Desk (WCPD)\nSto. Tomas, Pampanga",
        'salutation'  => 'Dear Sir/Madam:',
        'body'        => "This is to endorse the case of <b>{$clientName}</b>, <b>{$clientAge}</b> years old, "
                       . "a resident of <b>{$clientAddr}</b>, complainant of the case entitled "
                       . "\"<b>{$complaint['title']}</b>\" against <b>{$respName}</b>.<br><br>"
                       . "She/He will discuss with you the entire story of her/his problem "
                       . "<b>for your assessment, immediate attention, and appropriate intervention</b>.<br><br>"
                       . "This case involves <b>alleged child abuse / exploitation</b> under <b>Republic Act No. 7610</b> "
                       . "(Special Protection of Children Against Abuse, Exploitation and Discrimination Act), "
                       . "and is therefore <b>expressly excluded from the jurisdiction of the Katarungang Pambarangay</b>. "
                       . "No mediation was conducted.",
        'closing'     => "We respectfully request your immediate intervention. Coordination with the "
                       . "DSWD, MSWDO, and Bantay Bata 163 has been initiated to ensure the child's safety and welfare.",
        'sign_off'    => "Truly yours,",
        'sign_name'   => "___________________________<br><b>(Name &amp; Signature of Brgy. Captain)</b><br>Punong Barangay",
        'noted_off'   => null,
        'noted_name'  => null,
        'cc'          => "DSWD / MSWDO\nBantay Bata 163\nProvincial Prosecutor's Office",
    ],

    // ILLEGAL DRUGS
    'drugs' => [
        'title'       => 'Illegal Drugs',
        'addressee'   => "THE OFFICER-IN-CHARGE\nPNP Anti-Illegal Drugs Group / PDEA\nSto. Tomas, Pampanga",
        'salutation'  => 'Dear Sir/Madam:',
        'body'        => "This is to endorse the report of <b>{$clientName}</b>, "
                       . "a resident of <b>{$clientAddr}</b>, regarding the case entitled "
                       . "\"<b>{$complaint['title']}</b>\" involving <b>{$respName}</b>.<br><br>"
                       . "The complainant will discuss with you the entire details "
                       . "<b>for your assessment, immediate action, and appropriate intervention</b>.<br><br>"
                       . "This case involves <b>alleged illegal drug activities</b> under <b>Republic Act No. 9165</b> "
                       . "(Comprehensive Dangerous Drugs Act of 2002), which is <b>expressly excluded from the "
                       . "jurisdiction of the Katarungang Pambarangay</b>. No mediation was conducted. "
                       . "The Barangay is not authorized to investigate, arrest, or process drug-related offenses.",
        'closing'     => "The Barangay Anti-Drug Abuse Council (BADAC) has been notified and commits full "
                       . "cooperation with your office.",
        'sign_off'    => "Truly yours,",
        'sign_name'   => "___________________________<br><b>(Name &amp; Signature of Brgy. Captain)</b><br>Punong Barangay",
        'noted_off'   => null,
        'noted_name'  => null,
        'cc'          => "Barangay Anti-Drug Abuse Council (BADAC)\nMunicipal Anti-Drug Abuse Council (MADAC)",
    ],

    // ILLEGAL GAMBLING
    'illegal_gambling' => [
        'title'       => 'Illegal Gambling',
        'addressee'   => "THE OFFICER-IN-CHARGE\nPhilippine National Police (PNP)\nSto. Tomas, Pampanga",
        'salutation'  => 'Dear Sir/Madam:',
        'body'        => "This is to endorse the report of <b>{$clientName}</b>, "
                       . "a resident of <b>{$clientAddr}</b>, regarding the case entitled "
                       . "\"<b>{$complaint['title']}</b>\" involving <b>{$respName}</b>.<br><br>"
                       . "The complainant will discuss with you the entire details "
                       . "<b>for your assessment, immediate action, and appropriate intervention</b>.<br><br>"
                       . "This case involves <b>alleged illegal gambling activities</b> under "
                       . "<b>Presidential Decree No. 1602</b>, which is <b>expressly excluded from the "
                       . "jurisdiction of the Katarungang Pambarangay</b>. No mediation was conducted.",
        'closing'     => "The Barangay will provide full assistance to the PNP in the conduct of any lawful operation.",
        'sign_off'    => "Truly yours,",
        'sign_name'   => "___________________________<br><b>(Name &amp; Signature of Brgy. Captain)</b><br>Punong Barangay",
        'noted_off'   => null,
        'noted_name'  => null,
        'cc'          => "Municipal Anti-Illegal Gambling Task Force",
    ],

    // LABOR
    'labor' => [
        'title'       => 'Labor Dispute',
        'addressee'   => "THE REGIONAL DIRECTOR\nDepartment of Labor and Employment (DOLE)\nRegional Office No. III",
        'salutation'  => 'Dear Sir/Madam:',
        'body'        => "This is to endorse the case of <b>{$clientName}</b>, "
                       . "a resident of <b>{$clientAddr}</b>, complainant of the case entitled "
                       . "\"<b>{$complaint['title']}</b>\" against <b>{$respName}</b>.<br><br>"
                       . "The complainant will discuss with you the entire details "
                       . "<b>for your assessment, immediate action, and appropriate intervention</b>.<br><br>"
                       . "This case involves a <b>labor / employment dispute</b>, which is "
                       . "<b>expressly excluded from the jurisdiction of the Katarungang Pambarangay</b> "
                       . "under Section 408 of RA 7160. Labor disputes fall exclusively within the "
                       . "jurisdiction of the DOLE and the NLRC. No mediation was conducted.",
        'closing'     => "The parties have been advised to file the appropriate case with your office.",
        'sign_off'    => "Truly yours,",
        'sign_name'   => "___________________________<br><b>(Name &amp; Signature of Brgy. Captain)</b><br>Punong Barangay",
        'noted_off'   => null,
        'noted_name'  => null,
        'cc'          => "National Labor Relations Commission (NLRC)\nPublic Employment Service Office (PESO)",
    ],

    // GOVERNMENT
    'government' => [
        'title'       => 'Case Involving Government',
        'addressee'   => "THE HONORABLE PROSECUTOR\nProvincial Prosecutor's Office\nPampanga",
        'salutation'  => 'Dear Sir/Madam:',
        'body'        => "This is to endorse the case of <b>{$clientName}</b>, "
                       . "a resident of <b>{$clientAddr}</b>, complainant of the case entitled "
                       . "\"<b>{$complaint['title']}</b>\" against <b>{$respName}</b>.<br><br>"
                       . "The complainant will discuss with you the entire details "
                       . "<b>for your assessment, immediate action, and appropriate intervention</b>.<br><br>"
                       . "This case involves an offense committed by or against a "
                       . "<b>government official or entity</b>, which is <b>expressly excluded from the "
                       . "jurisdiction of the Katarungang Pambarangay</b> under Section 408 of RA 7160. "
                       . "No mediation was conducted.",
        'closing'     => "The case has been recorded in the Barangay Blotter for proper documentation.",
        'sign_off'    => "Truly yours,",
        'sign_name'   => "___________________________<br><b>(Name &amp; Signature of Brgy. Captain)</b><br>Punong Barangay",
        'noted_off'   => null,
        'noted_name'  => null,
        'cc'          => "Office of the Ombudsman\nCivil Service Commission\nCommission on Audit",
    ],

    // HIGH PENALTY
    'high_penalty' => [
        'title'       => 'Serious Offense',
        'addressee'   => "THE OFFICER-IN-CHARGE\nPhilippine National Police (PNP)\nSto. Tomas, Pampanga",
        'salutation'  => 'Dear Sir/Madam:',
        'body'        => "This is to endorse the case of <b>{$clientName}</b>, "
                       . "a resident of <b>{$clientAddr}</b>, complainant of the case entitled "
                       . "\"<b>{$complaint['title']}</b>\" against <b>{$respName}</b>.<br><br>"
                       . "The complainant will discuss with you the entire details "
                       . "<b>for your assessment, immediate action, and appropriate intervention</b>.<br><br>"
                       . "This case involves a <b>serious offense</b> whose penalty exceeds one (1) year "
                       . "imprisonment or a fine exceeding Five Thousand Pesos (Php 5,000.00), and is therefore "
                       . "<b>expressly excluded from the jurisdiction of the Katarungang Pambarangay</b> "
                       . "under Section 408 of RA 7160. No mediation was conducted.",
        'closing'     => "The case is referred for proper police investigation and/or prosecution.",
        'sign_off'    => "Truly yours,",
        'sign_name'   => "___________________________<br><b>(Name &amp; Signature of Brgy. Captain)</b><br>Punong Barangay",
        'noted_off'   => null,
        'noted_name'  => null,
        'cc'          => "Provincial Prosecutor's Office",
    ],

    // NO PRIVATE PARTY
    'no_private_party' => [
        'title'       => 'Offense with No Private Party',
        'addressee'   => "THE OFFICER-IN-CHARGE\nPhilippine National Police (PNP)\nSto. Tomas, Pampanga",
        'salutation'  => 'Dear Sir/Madam:',
        'body'        => "This is to endorse the report regarding the case entitled "
                       . "\"<b>{$complaint['title']}</b>\" involving <b>{$respName}</b>.<br><br>"
                       . "The complainant will discuss with you the entire details "
                       . "<b>for your assessment, immediate action, and appropriate intervention</b>.<br><br>"
                       . "The offense complained of has <b>no private offended party</b>, and is therefore "
                       . "<b>expressly excluded from the jurisdiction of the Katarungang Pambarangay</b>. "
                       . "No mediation was conducted.",
        'closing'     => "The matter is referred for proper police investigation and/or prosecution.",
        'sign_off'    => "Truly yours,",
        'sign_name'   => "___________________________<br><b>(Name &amp; Signature of Brgy. Captain)</b><br>Punong Barangay",
        'noted_off'   => null,
        'noted_name'  => null,
        'cc'          => "Provincial Prosecutor's Office",
    ],

    // DEFAULT
    'default' => [
        'title'       => 'Referral',
        'addressee'   => "THE OFFICER-IN-CHARGE\nProper Government Agency\nSto. Tomas, Pampanga",
        'salutation'  => 'Dear Sir/Madam:',
        'body'        => "This is to endorse the case of <b>{$clientName}</b>, "
                       . "a resident of <b>{$clientAddr}</b>, complainant of the case entitled "
                       . "\"<b>{$complaint['title']}</b>\" against <b>{$respName}</b>.<br><br>"
                       . "The complainant will discuss with you the entire details "
                       . "<b>for your assessment, immediate action, and appropriate intervention</b>.<br><br>"
                       . "This case falls <b>outside the jurisdiction of the Katarungang Pambarangay</b> "
                       . "under Section 408 of RA 7160. No mediation was conducted.",
        'closing'     => "The matter is referred for appropriate action by your office.",
        'sign_off'    => "Truly yours,",
        'sign_name'   => "___________________________<br><b>(Name &amp; Signature of Brgy. Captain)</b><br>Punong Barangay",
        'noted_off'   => null,
        'noted_name'  => null,
        'cc'          => "Provincial Prosecutor's Office",
    ],
];

$tpl = $templates[$escalation] ?? $templates['default'];

// ============================================================
// TCPDF — Endorsement Letter + Optional Evidence Page
// ============================================================
try {
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

    $pdf->SetCreator('BRITE - Barangay San Bartolome');
    $pdf->SetAuthor($adminRow['full_name']);
    $pdf->SetTitle($tpl['title'] . ' - ' . $complaint['reference_number']);
    $pdf->SetSubject('Endorsement of Non-Jurisdictional Case');
    $pdf->SetKeywords('Referral, Endorsement, Barangay, Case');

    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(20, 15, 20);
    $pdf->SetAutoPageBreak(true, 20);

    $pdf->AddPage();

    // ── LETTERHEAD ──
    $pdf->SetFont('times', '', 11);
    $pdf->Cell(0, 5, 'Republic of the Philippines', 0, 1, 'C');
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 5, 'PROVINCE OF PAMPANGA', 0, 1, 'C');
    $pdf->Cell(0, 5, 'MUNICIPALITY OF STO. TOMAS', 0, 1, 'C');
    $pdf->SetFont('times', 'B', 13);
    $pdf->Cell(0, 7, 'BARANGAY SAN BARTOLOME', 0, 1, 'C');
    $pdf->Ln(4);

    // ── TITLE (simple subject line only) ──
    $pdf->SetFont('times', 'B', 14);
    $pdf->Cell(0, 10, $tpl['title'], 0, 1, 'C');
    $pdf->Ln(6);

    // ── DATE ──
    $pdf->SetFont('times', '', 11);
    $pdf->Cell(0, 6, $niceDate, 0, 1, 'L');
    $pdf->Ln(4);

    // ── ADDRESSEE ──
    $pdf->SetFont('times', 'B', 11);
    foreach (explode("\n", $tpl['addressee']) as $line) {
        $pdf->Cell(0, 5.5, $line, 0, 1, 'L');
    }
    $pdf->Ln(4);

    // ── SALUTATION ──
    $pdf->SetFont('times', '', 11);
    $pdf->Cell(0, 6, $tpl['salutation'], 0, 1, 'L');
    $pdf->Ln(2);

    // ── BODY ──
    $pdf->SetFont('times', '', 11);
    $pdf->writeHTMLCell(
        0, 0, '', '',
        '<p style="text-indent:30px;text-align:justify;line-height:1.7;">' . $tpl['body'] . '</p>',
        0, 1, false, true, 'J', true
    );
    $pdf->Ln(2);

    // ── CLOSING ──
    $pdf->writeHTMLCell(
        0, 0, '', '',
        '<p style="text-indent:30px;text-align:justify;line-height:1.7;">' . $tpl['closing'] . '</p>',
        0, 1, false, true, 'J', true
    );
    $pdf->Ln(8);

    // ══════════════════════════════════════════════════════
    // SIGN-OFF BLOCK
    // ══════════════════════════════════════════════════════
    $pdf->SetFont('times', '', 11);
    $pdf->Cell(0, 6, $tpl['sign_off'], 0, 1, 'L');
    $pdf->Ln(12);

    $pdf->SetFont('times', 'B', 11);
    $pdf->writeHTMLCell(
        0, 0, '', '',
        '<div style="line-height:1.6;">' . $tpl['sign_name'] . '</div>',
        0, 1, false, true, 'L', true
    );
    $pdf->Ln(4);

    // Noted-by block (VAWC only)
    if (!empty($tpl['noted_off']) && !empty($tpl['noted_name'])) {
        $pdf->Ln(8);
        $pdf->SetFont('times', 'B', 11);
        $pdf->Cell(0, 6, $tpl['noted_off'], 0, 1, 'L');
        $pdf->Ln(10);
        $pdf->writeHTMLCell(
            0, 0, '', '',
            '<div style="line-height:1.6;">' . $tpl['noted_name'] . '</div>',
            0, 1, false, true, 'L', true
        );
        $pdf->Ln(8);
    }

    // ── CC ──
    if (!empty($tpl['cc'])) {
        $pdf->SetFont('times', 'I', 9);
        $pdf->SetTextColor(80);
        $pdf->MultiCell(0, 5, "cc: " . str_replace("\n", " / ", $tpl['cc']), 0, 'L');
        $pdf->SetTextColor(0);
    }

    // ── ANONYMITY NOTICE ──
    if ($is_anonymous === 1) {
        $pdf->Ln(6);
        $pdf->SetFont('times', 'I', 8);
        $pdf->SetTextColor(150, 0, 0);
        $pdf->MultiCell(0, 4,
            'CONFIDENTIALITY NOTICE: The complainant filed this case anonymously under the Barangay '
          . 'Whistleblower Protection Policy. All communications shall be coursed through the Barangay Secretary.',
          0, 'L');
        $pdf->SetTextColor(0);
    }

    // ── FOOTER ──
    $pdf->Ln(6);
    $pdf->SetFont('times', 'I', 8);
    $pdf->SetTextColor(120);
    $pdf->Cell(0, 5,
        'Prepared by: ' . $adminRow['full_name'] . ' (Secretary) · '
      . 'Ref: ' . $complaint['reference_number'] . ' · '
      . date('F j, Y g:i A'),
        0, 1, 'L');
    $pdf->SetTextColor(0);

    // ══════════════════════════════════════════════════════
    // EVIDENCE PAGE (only if requested AND files exist)
    // ══════════════════════════════════════════════════════
    if ($attach_evidence && !empty($evidenceFiles)) {
        $pdf->AddPage();

        // Page header
        $pdf->SetFont('times', 'B', 14);
        $pdf->Cell(0, 8, 'EVIDENCE', 0, 1, 'C');
        $pdf->SetFont('times', '', 10);
        $pdf->Cell(0, 5, 'Complaint Ref: ' . $complaint['reference_number'], 0, 1, 'C');
        $pdf->Ln(4);

        // Divider
        $pdf->SetDrawColor(46, 125, 50);
        $pdf->SetLineWidth(0.6);
        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(6);

        // Separate images from non-images
        $imageFiles = [];
        $otherFiles = [];
        foreach ($evidenceFiles as $ev) {
            $ext = strtolower(pathinfo($ev['file_name'] ?? '', PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                $imageFiles[] = $ev;
            } else {
                $otherFiles[] = $ev;
            }
        }

        // ---- IMAGES: 3 per row ----
        if (!empty($imageFiles)) {
            $pdf->SetFont('times', 'B', 11);
            $pdf->Cell(0, 6, 'Photographs (' . count($imageFiles) . ')', 0, 1, 'L');
            $pdf->Ln(2);

            $cols       = 3;
            $cellW      = 55;   // 3 × 55 = 165mm, fits in 170mm content width
            $cellH      = 55;   // square thumbs
            $gap        = 3;
            $startX     = 20;   // left margin
            $startY     = $pdf->GetY();
            $rowY       = $startY;
            $colIndex   = 0;
            $pageBreakNeeded = false;

            foreach ($imageFiles as $idx => $ev) {
                $imgPath = __DIR__ . '/../' . $ev['file_path'];
                if (!file_exists($imgPath)) continue;

                // Page-break check (need ~70mm for thumbnail + caption)
                if ($rowY + $cellH + 15 > 270) {
                    $pdf->AddPage();
                    $rowY = $pdf->GetY();
                    $colIndex = 0;
                }

                $x = $startX + $colIndex * ($cellW + $gap);
                $y = $rowY;

                // Thumbnail
                try {
                    $pdf->Image(
                        $imgPath,
                        $x, $y,
                        $cellW, $cellH,
                        '', '', '', true, 300, '', false, false, 0,
                        false, false, false
                    );
                } catch (Exception $imgEx) {
                    // Skip broken image
                }

                // Border
                $pdf->SetDrawColor(220, 220, 220);
                $pdf->SetLineWidth(0.3);
                $pdf->Rect($x, $y, $cellW, $cellH, 'D');

                // Caption
                $pdf->SetXY($x, $y + $cellH + 1);
                $pdf->SetFont('times', '', 7.5);
                $pdf->SetTextColor(80);
                $caption = $ev['file_name'] ?? ('Image ' . ($idx + 1));
                $pdf->MultiCell($cellW, 3.5, $caption, 0, 'L', false, 0, '', '', true, 0, false, true, 3.5, 'T');
                $pdf->SetTextColor(0);

                $colIndex++;
                if ($colIndex >= $cols) {
                    $colIndex = 0;
                    $rowY += $cellH + 8;
                }
            }

            // Advance Y past the last row
            if ($colIndex > 0) {
                $rowY += $cellH + 8;
            }
            $pdf->SetY($rowY + 4);
        }

        // ---- NON-IMAGE FILES ----
        if (!empty($otherFiles)) {
            $pdf->SetFont('times', 'B', 11);
            $pdf->Cell(0, 6, 'Other Attachments (' . count($otherFiles) . ')', 0, 1, 'L');
            $pdf->Ln(1);

            $pdf->SetFont('times', '', 10);
            foreach ($otherFiles as $idx => $ev) {
                $line = ($idx + 1) . '. ' . ($ev['file_name'] ?? 'File');
                $pdf->MultiCell(0, 5, $line, 0, 'L');
            }
            $pdf->Ln(3);
        }

        // Anonymity notice on evidence page too
        if ($is_anonymous === 1) {
            $pdf->Ln(4);
            $pdf->SetFont('times', 'I', 8);
            $pdf->SetTextColor(150, 0, 0);
            $pdf->MultiCell(0, 4,
                'CONFIDENTIALITY NOTICE: The complainant filed this case anonymously. '
              . 'All visual evidence has been preserved by the Barangay Secretary.',
                0, 'L');
            $pdf->SetTextColor(0);
        }

        // Page footer
        $pdf->SetY(-20);
        $pdf->SetFont('times', 'I', 8);
        $pdf->SetTextColor(120);
        $pdf->Cell(0, 4,
            'Evidence page · Ref: ' . $complaint['reference_number'] . ' · '
          . 'Total files: ' . count($evidenceFiles) . ' · ' . date('F j, Y'),
            0, 1, 'C');
        $pdf->SetTextColor(0);
    }

    $pdf->Output($filepath, 'F');

} catch (Exception $e) {
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode(['success'=>false,'message'=>'PDF error: ' . $e->getMessage()]);
    exit;
}

// ============================================================
// UPDATE DB — Two-stage flow
// ============================================================
$summary = 'Endorsement PDF generated. Agency: ' . $refer_to;

$s = mysqli_prepare($conn,
    "UPDATE complaints
     SET referral_pdf_path        = ?,
         escalation_refer_to      = ?,
         escalation_notes         = ?,
         escalation_saved_at      = NOW(),
         referral_printed_at      = NOW(),
         referral_sent_to_captain = 0,
         status                   = 'referred'
     WHERE id = ?");
$combined = $summary . ($notes ? ' | ' . $notes : '');
mysqli_stmt_bind_param($s, "sssi", $rel_path, $refer_to, $combined, $complaint_id);
mysqli_stmt_execute($s);

$old_status = $complaint['status'];
$s = mysqli_prepare($conn,
    "INSERT INTO complaint_updates
        (complaint_id, update_type, previous_status, new_status, notes, updated_by, updated_by_role)
     VALUES (?, 'resolution', ?, 'referred', ?, ?, 'secretary')");
$log = "Endorsement letter PDF printed (pending send to Captain). Refer to: {$refer_to}. Actions: " . implode(', ', $actionLabels);
mysqli_stmt_bind_param($s, "issi", $complaint_id, $old_status, $log, $admin_id);
mysqli_stmt_execute($s);

// ============================================================
// PRESERVE REFERRAL VARS (before NJ require)
// ============================================================
$referral_filename = $filename;
$referral_filepath = $filepath;
$referral_rel_path = $rel_path;

// ============================================================
// OPTIONAL: Also generate Certificate of Non-Jurisdiction
// ============================================================
$nj_result = null;
if ($also_non_jurisdiction) {
    $reason_to_use = $nj_reason !== '' ? $nj_reason : $notes;
    if ($reason_to_use === '') {
        $reason_to_use = 'Case is outside the jurisdiction of the Katarungang Pambarangay.';
    }

    define('NJ_INTERNAL_CALL', true);
    $_POST['complaint_id'] = $complaint_id;
    $_POST['reason']       = $reason_to_use;

    $nj_result = require __DIR__ . '/generate_non_jurisdiction_pdf.php';

    if (!is_array($nj_result) || empty($nj_result['success'])) {
        while (ob_get_level() > 1) ob_end_clean();
        echo json_encode([
            'success'            => true,
            'partial'            => true,
            'message'            => 'Endorsement generated, but Certificate of Non-Jurisdiction failed.',
            'pdf_url'            => $referral_rel_path,
            'pdf_path'           => $referral_rel_path,
            'filename'           => $referral_filename,
            'nj_error'           => $nj_result['message'] ?? 'Unknown error',
            'nj_pdf_url'         => null,
            'nj_filename'        => null,
            'nj_certificate_no'  => null,
        ]);
        exit;
    }
}

while (ob_get_level() > 1) ob_end_clean();

$response = [
    'success'           => true,
    'message'           => 'Endorsement PDF generated (not yet sent to Captain).',
    'pdf_url'           => $referral_rel_path,
    'pdf_path'          => $referral_rel_path,
    'filename'          => $referral_filename,
    'nj_pdf_url'        => $nj_result ? ($nj_result['pdf_url']        ?? null) : null,
    'nj_filename'       => $nj_result ? ($nj_result['filename']       ?? null) : null,
    'nj_certificate_no' => $nj_result ? ($nj_result['certificate_no'] ?? null) : null,
];

echo json_encode($response);