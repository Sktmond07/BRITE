<?php
// admin/view_document.php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$id = intval($_GET['request_id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Bad request'); }

$sql = "SELECT document_path FROM document_requests WHERE id = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = $res ? mysqli_fetch_assoc($res) : null;

if (!$row || empty($row['document_path'])) {
    http_response_code(404);
    exit('Document not found');
}

// Hard-coded folder prefix — PDFs live in /BRITE/generated_documents/
$path = __DIR__ . '/../generated_documents/' . $row['document_path'];

if (!file_exists($path) || !is_file($path)) {
    http_response_code(404);
    exit('File missing on disk: ' . htmlspecialchars($row['document_path']));
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($path);
exit;