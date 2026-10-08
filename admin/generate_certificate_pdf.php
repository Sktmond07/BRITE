<?php
// admin/generate_certificate_pdf.php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use TCPDF;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}

$admin_id = $_SESSION['user_id'];
$s = mysqli_prepare($conn, "SELECT admin_role, full_name FROM admin WHERE id = ?");
mysqli_stmt_bind_param($s, "i", $admin_id);
mysqli_stmt_execute($s);
$adminRow = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
if (!$adminRow || $adminRow['admin_role'] !== 'secretary') {
    echo json_encode(['success'=>false,'message'=>'Only the Secretary can generate certificates.']);
    exit;
}

$complaint_id = (int)($_POST['complaint_id'] ?? 0);
$reason       = trim($_POST['reason'] ?? '');

if (!$complaint_id || !$reason) {
    echo json_encode(['success'=>false,'message'=>'Missing fields.']); exit;
}

$stmt = mysqli_prepare($conn,
    "SELECT c.*, r.first_name, r.last_name, r.address AS res_address
     FROM complaints c JOIN resident r ON c.created_by = r.id WHERE c.id = ?");
mysqli_stmt_bind_param($stmt, "i", $complaint_id);
mysqli_stmt_execute($stmt);
$complaint = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$complaint) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }

if (!in_array($complaint['status'], ['failed_conciliation_final','failed_mediation'])) {
    echo json_encode(['success'=>false,'message'=>'Certificate can only be issued after failed conciliation.']); exit;
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

$certNo = 'CFT-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));

$upload_dir = __DIR__ . '/../uploads/certificates/';
if (!file_exists($upload_dir)) @mkdir($upload_dir, 0777, true);
$filename = $certNo . '.pdf';
$filepath = $upload_dir . $filename;
$rel_path = 'uploads/certificates/' . $filename;

try {
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('BRITE - Barangay San Bartolome');
    $pdf->SetAuthor($adminRow['full_name']);
    $pdf->SetTitle('Certificate to File Action - ' . $certNo);
    $pdf->SetSubject('Certificate to File Action');
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
    $pdf->Cell(0, 10, 'CERTIFICATE TO FILE ACTION', 0, 1, 'C');
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
        'This is to certify that a complaint was filed with this Barangay and that the required conciliation proceedings under the Katarungang Pambarangay (RA 7160, Sections 408-422) were conducted, but the same FAILED.',
        0, 'L');
    $pdf->Ln(4);

    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(60, 7, 'Complaint Reference:', 0, 0);
    $pdf->SetFont('times', '', 11);
    $pdf->Cell(0, 7, $complaint['reference_number'], 0, 1);

    $pdf->SetFont('times', 'B', 11);
    $pdf->Cell(60, 7, 'Complaint Title:', 0, 0);
    $pdf->SetFont('times', '', 11);
    $pdf->Cell(0, 7, $complaint['title'], 0, 1);

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
    $pdf->Cell(0, 7, 'Reason for Failure of Conciliation:', 0, 1);
    $pdf->SetFont('times', '', 11);
    $pdf->MultiCell(0, 6, $reason, 0, 'L');
    $pdf->Ln(4);

    $pdf->MultiCell(0, 7,
        'This certificate is issued pursuant to Section 412 and Section 415 of Republic Act No. 7160, otherwise known as the Local Government Code of 1991. The complainant may now file the appropriate action in court or with the Office of the City/Provincial Prosecutor.',
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
    echo json_encode(['success'=>false,'message'=>'PDF error: ' . $e->getMessage()]);
    exit;
}

// Save to DB
$s = mysqli_prepare($conn,
    "UPDATE complaints
     SET certificate_no = ?,
         certificate_issued_at = NOW(),
         certificate_reason = ?,
         certificate_pdf_path = ?,
         status = 'certificate_issued'
     WHERE id = ?");
mysqli_stmt_bind_param($s, "sssi", $certNo, $reason, $rel_path, $complaint_id);
mysqli_stmt_execute($s);

$s = mysqli_prepare($conn,
    "INSERT INTO complaint_updates
        (complaint_id, update_type, previous_status, new_status, notes, updated_by, updated_by_role)
     VALUES (?, 'resolution', ?, 'certificate_issued', ?, ?, 'secretary')");
$log = "Certificate to File Action issued (No. {$certNo}). PDF saved. Reason: {$reason}";
mysqli_stmt_bind_param($s, "issi", $complaint_id, $complaint['status'], $log, $admin_id);
mysqli_stmt_execute($s);

// Notify complainant
$s = mysqli_prepare($conn, "SELECT created_by FROM complaints WHERE id = ?");
mysqli_stmt_bind_param($s, "i", $complaint_id);
mysqli_stmt_execute($s);
$created_by = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($s))['created_by'];

$s = mysqli_prepare($conn,
    "INSERT INTO notifications
        (user_type, user_id, resident_id, type, title, message, reference_id, reference_type)
     VALUES ('resident', ?, ?, 'success', 'Certificate to File Action Ready', ?, ?, 'complaint')");
$msg = "Your Certificate to File Action (No. {$certNo}) for complaint #{$complaint['reference_number']} is ready. Please claim it at the Barangay Hall.";
mysqli_stmt_bind_param($s, "iisi", $created_by, $created_by, $msg, $complaint_id);
mysqli_stmt_execute($s);

echo json_encode([
    'success'        => true,
    'message'        => 'Certificate generated.',
    'pdf_url'        => '../' . $rel_path,
    'pdf_path'       => $rel_path,
    'certificate_no' => $certNo
]);