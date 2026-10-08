<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../config/database.php';

$complaint_id = 33;
$tables = [
    'complaint_parties' => 'SELECT * FROM complaint_parties WHERE complaint_id = ?',
    'complaint_incidents' => 'SELECT * FROM complaint_incidents WHERE complaint_id = ?',
    'complaint_hearings' => 'SELECT * FROM complaint_hearings WHERE complaint_id = ?',
    'complaint_updates' => 'SELECT * FROM complaint_updates WHERE complaint_id = ?',
    'complaint_evidence' => 'SELECT * FROM complaint_evidence WHERE complaint_id = ?',
    'complaint_pangkat' => 'SELECT * FROM complaint_pangkat WHERE complaint_id = ?',
    'complaint_pangkat_documents' => 'SELECT * FROM complaint_pangkat_documents WHERE complaint_id = ?',
    'complaint_assignments' => 'SELECT * FROM complaint_assignments WHERE complaint_id = ?',
];

foreach ($tables as $name => $sql) {
    $s = mysqli_prepare($conn, $sql);
    if (!$s) {
        echo "<b style='color:red'>✗ $name FAILED:</b> " . mysqli_error($conn) . "<br>";
        continue;
    }
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $count = 0;
    while ($row = mysqli_fetch_assoc($r)) $count++;
    echo "<b style='color:green'>✓ $name OK</b> ($count rows)<br>";
    mysqli_stmt_close($s);
}

// Also check the main complaints join
echo "<hr>";
$s = mysqli_prepare($conn,
    "SELECT c.*, r.first_name, r.last_name, r.email AS resident_email,
            a.full_name AS assigned_to_name
       FROM complaints c
       JOIN resident r ON c.created_by = r.id
       LEFT JOIN admin a ON c.assigned_to = a.id
      WHERE c.id = ?");
if (!$s) {
    echo "<b style='color:red'>✗ Main complaints JOIN FAILED:</b> " . mysqli_error($conn) . "<br>";
} else {
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    echo "<b style='color:green'>✓ Main complaints JOIN OK</b><br>";
    mysqli_stmt_close($s);
}