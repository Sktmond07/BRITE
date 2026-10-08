<?php
// admin/generate_non_jurisdiction_pdf.php
// Generates a Certificate of Non-Jurisdiction for OUTSIDE-jurisdiction cases.
// Called internally by generate_referral_pdf.php OR standalone by the Secretary.

ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

// ---- Detect if called internally (return array) or as API (return JSON) ----
$IS_INTERNAL = defined('NJ_INTERNAL_CALL');

if (!$IS_INTERNAL) {
    header('Content-Type: application/json');
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    while (ob_get_level() > 0) ob_end_clean();
    if ($IS_INTERNAL) return ['success' => false, 'message' => 'Unauthorized'];
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$admin_id = $_SESSION['user_id'];
$s = mysqli_prepare($conn, "SELECT admin_role, full_name FROM admin WHERE id = ?");
mysqli_stmt_bind_param($s, "i", $admin_id);
mysqli_stmt_execute($s);
$adminRow = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
if (!$adminRow || $adminRow['admin_role'] !== 'secretary') {
    while (ob_get_level() > 0) ob_end_clean();
    if ($IS_INTERNAL) return ['success' => false, 'message' => 'Only the Secretary can generate this certificate.'];
    echo json_encode(['success' => false, 'message' => 'Only the Secretary can generate this certificate.']);
    exit;
}

$complaint_id = (int)($_POST['complaint_id'] ?? 0);
$reason       = trim($_POST['reason'] ?? '');

if (!$complaint_id || !$reason) {
    while (ob_get_level() > 0) ob_end_clean();
    if ($IS_INTERNAL) return ['success' => false, 'message' => 'Missing fields.'];
    echo json_encode(['success' => false, 'message' => 'Missing fields.']);
    exit;
}

$stmt = mysqli_prepare($conn,
    "SELECT c.*, r.first_name, r.last_name, r.address AS res_address
     FROM complaints c JOIN resident r ON c.created_by = r.id WHERE c.id = ?");
mysqli_stmt_bind_param($stmt, "i", $complaint_id);
mysqli_stmt_execute($stmt);
$complaint = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$complaint) {
    while (ob_get_level() > 0) ob_end_clean();
    if ($IS_INTERNAL) return ['success' => false, 'message' => 'Not found.'];
    echo json_encode(['success' => false, 'message' => 'Not found.']);
    exit;
}

if (!in_array($complaint['status'], ['escalated', 'referred'])) {
    while (ob_get_level() > 0) ob_end_clean();
    if ($IS_INTERNAL) return ['success' => false, 'message' => 'Certificate of Non-Jurisdiction is only for escalated cases.'];
    echo json_encode(['success' => false, 'message' => 'Certificate of Non-Jurisdiction is only for escalated cases.']);
    exit;
}

$complainants = []; $respondents = [];
$s = mysqli_prepare($conn,
    "SELECT party_type, full_name, address FROM complaint_parties WHERE complaint_id = ? ORDER BY id");
mysqli_stmt_bind_param($s, "i", $complaint_id);
mysqli_stmt_execute($s);
$r = mysqli_stmt_get_result($s);
while ($row = mysqli_fetch_assoc($r)) {
    if ($row['party_type'] === 'complainant')    $complainants[] = $row;
    elseif ($row['party_type'] === 'respondent') $respondents[]  = $row;
}
$is_anonymous = intval($complaint['is_anonymous'] ?? 0);
if ($is_anonymous === 1) {
    foreach ($complainants as $i => $p) {
        $complainants[$i]['full_name'] = 'Concerned Resident';
        $complainants[$i]['address']   = 'Suppressed for security';
    }
}

$certNo = 'CNJ-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));

$upload_dir = __DIR__ . '/../uploads/non_jurisdiction/';
if (!file_exists($upload_dir)) @mkdir($upload_dir, 0777, true);
$filename = $certNo . '.pdf';
$filepath = $upload_dir . $filename;
$rel_path = 'uploads/non_jurisdiction/' . $filename;

$escType = strtoupper(str_replace('_', ' ', $complaint['escalation_type'] ?: 'NON-JURISDICTIONAL'));

try {
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('BRITE - Barangay San Bartolome');
    $pdf->SetAuthor($adminRow['full_name']);
    $pdf->SetTitle('Certificate of Non-Jurisdiction - ' . $certNo);
    $pdf->SetSubject('Certificate of Non-Jurisdiction');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 20);

    $pdf->AddPage();
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 5, 'Republic of the Philippines', 0, 1, 'C');
    $pdf->SetFont('times', 'B', 13);
    $pdf->Cell(0, 6, 'BARANGAY SAN BARTOLOME', 0, 1, 'C');
    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 5, 'Sto. Tomas, Pampanga', 0, 1, 'C');
    $pdf->Ln(3);

    $pdf->SetDrawColor(46, 125, 50);
    $pdf->SetLineWidth(0.8);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(5);

    $pdf->SetFont('times', 'B', 16);
    $pdf->Cell(0, 10, 'CERTIFICATE OF NON-JURISDICTION', 0, 1, 'C');
    $pdf->Ln(3);

    $pdf->SetFont('times', '', 10);
    $pdf->Cell(0, 5, 'Certificate No: ' . $certNo, 0, 1, 'R');
    $pdf->Cell(0, 5, 'Date Issued: ' . date('F j, Y'), 0, 1, 'R');
    $pdf->Ln(6);

    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, 'TO WHOM IT MAY CONCERN:', 0, 1);
    $pdf->Ln(2);

    $pdf->SetFont('times', '', 11);
    $pdf->MultiCell(0, 7,
        'This is to certify that the complaint described below is NOT COVERED by the Katarungang Pambarangay under Republic Act No. 7160, Sections 408-422, and other applicable laws. The Barangay has NO JURISDICTION to conduct mediation or conciliation proceedings for this case.',
        0, 'L');
    $pdf->Ln(4);

    $pdf->SetFillColor(255, 235, 238);
    $pdf->SetTextColor(183, 28, 28);
    $pdf->SetFont('times', 'B', 10);
    $pdf->MultiCell(0, 6, "NO MEDIATION HAS BEEN OR WILL BE CONDUCTED FOR THIS CASE.", 1, 'C', true);
    $pdf->SetTextColor(0);
    $pdf->Ln(5);

    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(60, 7, 'Complaint Reference:', 0, 0);
    $pdf->SetFont('times', '', 11);
    $pdf->Cell(0, 7, $complaint['reference_number'], 0, 1);

    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(60, 7, 'Complaint Title:', 0, 0);
    $pdf->SetFont('times', '', 11);
    $pdf->Cell(0, 7, $complaint['title'], 0, 1);

    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(60, 7, 'Classification:', 0, 0);
    $pdf->SetFont('times', '', 11);
    $pdf->Cell(0, 7, $escType, 0, 1);

    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(60, 7, 'Complainant(s):', 0, 0);
    $pdf->SetFont('times', '', 11);
    $cNames = array_map(fn($p) => $p['full_name'], $complainants);
    $pdf->Cell(0, 7, implode(', ', $cNames) ?: '—', 0, 1);

    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(60, 7, 'Respondent(s):', 0, 0);
    $pdf->SetFont('times', '', 11);
    $rNames = array_map(fn($p) => $p['full_name'], $respondents);
    $pdf->Cell(0, 7, implode(', ', $rNames) ?: '—', 0, 1);

    $pdf->Ln(3);
    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 7, 'Reason for Non-Jurisdiction:', 0, 1);
    $pdf->SetFont('times', '', 11);
    $pdf->MultiCell(0, 6, $reason, 0, 'L');
    $pdf->Ln(4);

    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 7, 'Basis in Law:', 0, 1);
    $pdf->SetFont('times', '', 11);
    $pdf->MultiCell(0, 7,
        'Under Section 408 of RA 7160 (Local Government Code of 1991), the Katarungang Pambarangay has no authority over cases where the penalty exceeds one (1) year imprisonment, or fine exceeding Five Thousand Pesos (Php 5,000.00); cases involving parties residing in different cities/municipalities; cases arising from non-jurisdictional laws such as RA 9165 (Illegal Drugs), RA 9262 (VAWC), RA 7610 (Child Abuse), PD 1602 (Illegal Gambling), and other cases expressly excluded by law.',
        0, 'L');
    $pdf->Ln(4);

    $pdf->SetFont('times', '', 11);
    $pdf->MultiCell(0, 7,
        'The proper agency or tribunal has jurisdiction over this case. The complainant may proceed directly to the agency indicated in the attached Referral Letter.',
        0, 'L');
    $pdf->Ln(10);

    $pdf->SetFont('times', '', 11);
    $pdf->MultiCell(0, 6,
        'Issued this ' . date('jS \of F, Y') . ' at Barangay San Bartolome, Sto. Tomas, Pampanga.',
        0, 'L');
    $pdf->Ln(14);

    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(0, 6, 'HON. BARANGAY CAPTAIN', 0, 1, 'R');
    $pdf->SetFont('times', 'I', 10);
    $pdf->Cell(0, 5, 'Punong Barangay', 0, 1, 'R');

    $pdf->Ln(8);
    $pdf->SetFont('times', 'I', 8);
    $pdf->SetTextColor(120);
    $pdf->Cell(0, 5, 'Attested by: ' . $adminRow['full_name'] . ' (Secretary) · ' . date('F j, Y g:i A'), 0, 1, 'L');

    $pdf->Output($filepath, 'F');

} catch (Exception $e) {
    while (ob_get_level() > 0) ob_end_clean();
    if ($IS_INTERNAL) return ['success' => false, 'message' => 'PDF error: ' . $e->getMessage()];
    echo json_encode(['success' => false, 'message' => 'PDF error: ' . $e->getMessage()]);
    exit;
}

// Save to DB
$s = mysqli_prepare($conn,
    "UPDATE complaints
     SET non_jurisdiction_cert_no = ?,
         non_jurisdiction_issued_at = NOW(),
         non_jurisdiction_reason = ?,
         non_jurisdiction_pdf_path = ?
     WHERE id = ?");
mysqli_stmt_bind_param($s, "sssi", $certNo, $reason, $rel_path, $complaint_id);
mysqli_stmt_execute($s);

// Timeline entry
$s = mysqli_prepare($conn,
    "INSERT INTO complaint_updates
        (complaint_id, update_type, previous_status, new_status, notes, updated_by, updated_by_role)
     VALUES (?, 'resolution', ?, ?, ?, ?, 'secretary')");
$log = "Certificate of Non-Jurisdiction issued (No. {$certNo}). Reason: {$reason}";
mysqli_stmt_bind_param($s, "isssi", $complaint_id, $complaint['status'], $complaint['status'], $log, $admin_id);
mysqli_stmt_execute($s);

// Notify complainant (for standalone calls)
if (!$IS_INTERNAL) {
    $s = mysqli_prepare($conn, "SELECT created_by FROM complaints WHERE id = ?");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $created_by = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($s))['created_by'];

    $s = mysqli_prepare($conn,
        "INSERT INTO notifications
            (user_type, user_id, resident_id, type, title, message, reference_id, reference_type)
         VALUES ('resident', ?, ?, 'success', 'Certificate of Non-Jurisdiction Ready', ?, ?, 'complaint')");
    $msg = "Your Certificate of Non-Jurisdiction (No. {$certNo}) for complaint #{$complaint['reference_number']} is ready.";
    mysqli_stmt_bind_param($s, "iisi", $created_by, $created_by, $msg, $complaint_id);
    mysqli_stmt_execute($s);
}

while (ob_get_level() > 1) ob_end_clean();

$result = [
    'success'        => true,
    'message'        => 'Certificate of Non-Jurisdiction generated.',
    'pdf_url'        => $rel_path,
    'pdf_path'       => $rel_path,
    'certificate_no' => $certNo,
    'filename'       => $filename
];

if ($IS_INTERNAL) return $result;

echo json_encode($result);