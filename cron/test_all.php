<?php
// cron/test_all.php — FULLY GUARDED
// ============================================================
// Runs every cron task once with temporary test data,
// so you can verify Web Push end-to-end for each type.
//
// URL:
//   /BRITE/cron/test_all.php?resident_id=7
//   /BRITE/cron/test_all.php?resident_id=7&cleanup=1
//   /BRITE/cron/test_all.php?resident_id=7&keep=1
//
// Every mysqli_prepare is checked. If a table/column is
// wrong, you'll see the exact SQL and MySQL error.
// ============================================================

session_start();
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
        http_response_code(403);
        exit('Forbidden — log in as admin, or run from CLI.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/cron_bootstrap.php';

$pushPath = __DIR__ . '/../admin/push_send.php';
if (file_exists($pushPath)) require_once $pushPath;

function out($m) { echo $m . "\n"; }
function hr()      { out(str_repeat('─', 70)); }
function title($t) { hr(); out("  $t"); hr(); }

/* ============================================================
   GUARDED QUERY HELPERS
   ============================================================ */

/**
 * Prepare + bind + execute. Throws with the SQL and MySQL error
 * on any failure so we always know exactly what went wrong.
 */
function safeExec($conn, $sql, $types = '', $params = [], $label = '') {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        $err = mysqli_error($conn);
        throw new Exception(
            "PREPARE FAILED" . ($label ? " [$label]" : "") . ":\n" .
            "  MySQL error: $err\n" .
            "  SQL:\n" . $sql
        );
    }
    if ($types !== '' && !empty($params)) {
        if (!mysqli_stmt_bind_param($stmt, $types, ...$params)) {
            $err = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            throw new Exception(
                "BIND FAILED" . ($label ? " [$label]" : "") . ":\n" .
                "  MySQL error: $err\n" .
                "  Types: $types  Params: " . json_encode($params)
            );
        }
    }
    if (!mysqli_stmt_execute($stmt)) {
        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception(
            "EXECUTE FAILED" . ($label ? " [$label]" : "") . ":\n" .
            "  MySQL error: $err\n" .
            "  SQL:\n" . $sql
        );
    }
    return $stmt;
}

/* ============================================================
   PARAMS
   ============================================================ */
if ($isCli) {
    $residentId  = (int)($argv[1] ?? 0);
    $cleanupOnly = in_array('--cleanup', $argv);
    $keep        = in_array('--keep', $argv);
} else {
    $residentId  = (int)($_GET['resident_id'] ?? 0);
    $cleanupOnly = !empty($_GET['cleanup']);
    $keep        = !empty($_GET['keep']);
}

if ($residentId <= 0) {
    out('❌ Missing resident_id. Usage: ?resident_id=7');
    exit(1);
}

/* ============================================================
   VERIFY RESIDENT
   ============================================================ */
$s = mysqli_prepare($conn,
    "SELECT id, first_name, last_name,
            (push_subscription IS NOT NULL) AS has_sub
     FROM resident WHERE id = ? AND is_active = 1");
mysqli_stmt_bind_param($s, "i", $residentId);
mysqli_stmt_execute($s);
$resident = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
mysqli_stmt_close($s);

if (!$resident) { out("❌ Resident #$residentId not found."); exit(1); }

title('BRITE CRON TEST — ALL TASKS');
out("Resident: #{$resident['id']} {$resident['first_name']} {$resident['last_name']}");
out("Push subscription: " . ($resident['has_sub'] ? '✅ YES' : '❌ NO — enable push first'));
out("Mode: " . ($cleanupOnly ? 'CLEANUP ONLY' : 'RUN ALL TASKS'));
out("Keep temp rows: " . ($keep ? 'yes' : 'no'));
out('');

if (!$resident['has_sub'] && !$cleanupOnly) {
    out('⚠️  Resident has no push subscription.');
    out('    Log in as them, open the notification bell, click the Push toggle,');
    out('    and accept the browser prompt. Then re-run this test.');
    out('');
    out('    (The test will still run so you can verify DB rows,');
    out('     but no desktop popup will appear.)');
    out('');
}

/* ============================================================
   CLEANUP
   ============================================================ */
function cleanupTestRows($conn, $residentId, $verbose = true) {
    $deleted = ['requests' => 0, 'docs' => 0, 'complaints' => 0, 'notifications' => 0];

    $q = mysqli_query($conn,
        "SELECT id FROM requests WHERE resident_id = $residentId AND purpose LIKE 'CRON-TEST-%'");
    $ids = [];
    while ($row = mysqli_fetch_assoc($q)) $ids[] = (int)$row['id'];
    if ($ids) {
        $in = implode(',', $ids);
        mysqli_query($conn, "DELETE FROM equipment_requests WHERE request_id IN ($in)");
        mysqli_query($conn, "DELETE FROM requests WHERE id IN ($in)");
        $deleted['requests'] = count($ids);
    }

    $q = mysqli_query($conn,
        "SELECT id FROM document_requests WHERE resident_id = $residentId AND purpose LIKE 'CRON-TEST-%'");
    $ids = [];
    while ($row = mysqli_fetch_assoc($q)) $ids[] = (int)$row['id'];
    if ($ids) {
        $in = implode(',', $ids);
        mysqli_query($conn, "DELETE FROM document_requests_custom_data WHERE request_id IN ($in)");
        mysqli_query($conn, "DELETE FROM document_requests WHERE id IN ($in)");
        $deleted['docs'] = count($ids);
    }

    $q = mysqli_query($conn,
        "SELECT id FROM complaints WHERE created_by = $residentId AND reference_number LIKE 'CRON-TEST-%'");
    $ids = [];
    while ($row = mysqli_fetch_assoc($q)) $ids[] = (int)$row['id'];
    if ($ids) {
        $in = implode(',', $ids);
        mysqli_query($conn, "DELETE FROM complaint_hearings WHERE complaint_id IN ($in)");
        mysqli_query($conn, "DELETE FROM complaint_parties WHERE complaint_id IN ($in)");
        mysqli_query($conn, "DELETE FROM complaint_updates WHERE complaint_id IN ($in)");
        mysqli_query($conn, "DELETE FROM complaints WHERE id IN ($in)");
        $deleted['complaints'] = count($ids);
    }

    mysqli_query($conn,
        "DELETE FROM notifications
         WHERE resident_id = $residentId
           AND DATE(created_at) = CURDATE()
           AND type IN ('return_reminder','equipment_overdue',
                        'document_pickup_today','document_unclaimed',
                        'hearing_today')");
    $deleted['notifications'] = mysqli_affected_rows($conn);

    if ($verbose) {
        out("Cleaned: requests={$deleted['requests']} " .
            "docs={$deleted['docs']} " .
            "complaints={$deleted['complaints']} " .
            "notifs={$deleted['notifications']}");
    }
    return $deleted;
}

if ($cleanupOnly) {
    title('CLEANUP');
    cleanupTestRows($conn, $residentId);
    out('✅ Done.');
    exit(0);
}

/* ============================================================
   STEP 0 — Pre-clean
   ============================================================ */
title('STEP 0 — Pre-cleanup');
cleanupTestRows($conn, $residentId);
out('');

/* ============================================================
   STEP 1 — Schema check (BEFORE we inject)
   ============================================================ */
title('STEP 1 — Schema check');

$required = [
    'requests' => [
        'id','resident_id','request_type','request_date',
        'start_datetime','end_datetime','duration_days','quantity',
        'purpose','status','item_global_id','reference',
        'approved_at','created_at'
    ],
    'equipment_requests' => [
        'id','request_id','equipment_global_id','equipment_name',
        'quantity','duration_days','booking_date','pickup_time',
        'return_date','notes'
    ],
    'document_requests' => [
        'id','resident_id','document_type_id','document_type',
        'purpose','notes','fee_type','fee','quantity',
        'id_document_path','pickup_date','pickup_time',
        'status','request_date','processed_date','claimed_at'
    ],
    'complaints' => [
        'id','reference_number','title','description','complaint_type',
        'complaint_subject','status','priority','created_by',
        'has_evidence','is_anonymous','assigned_to','assigned_role',
        'hearing_date','hearing_time','hearing_location'
    ],
    'complaint_hearings' => [
        'id','complaint_id','session_type','session_number',
        'hearing_date','hearing_time','location','status',
        'conducted_by','created_by','session_reason',
        'reminder_same_day_sent'
    ],
    'complaint_parties' => [
        'id','complaint_id','party_type','full_name','matched_resident_id'
    ],
    'notifications' => [
        'id','user_type','user_id','resident_id','type',
        'title','message','reference_id','reference_type',
        'is_read','created_at'
    ],
];

$schemaErrors = [];
foreach ($required as $table => $cols) {
    $r = mysqli_query($conn, "SHOW COLUMNS FROM `$table`");
    if (!$r) {
        $schemaErrors[] = "❌ Table `$table` missing";
        continue;
    }
    $have = [];
    while ($row = mysqli_fetch_assoc($r)) $have[] = strtolower($row['Field']);

    $missing = array_diff(array_map('strtolower', $cols), $have);
    if ($missing) {
        $schemaErrors[] = "❌ `$table` missing columns: " . implode(', ', $missing);
    } else {
        out("  ✅ `$table` — all required columns present");
    }
}

if ($schemaErrors) {
    out('');
    out('SCHEMA ERRORS — fix these before running the test:');
    foreach ($schemaErrors as $e) out('  ' . $e);
    out('');
    out('Add the missing columns or update the injector SQL, then re-run.');
    exit(1);
}
out('');

/* ============================================================
   STEP 2 — Inject temp rows
   ============================================================ */
title('STEP 2 — Injecting temporary test data');

$eq = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id, name, global_id FROM equipment WHERE status = 'active' LIMIT 1"));
$doc = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id, name FROM document_types WHERE is_active = 1 LIMIT 1"));

if (!$eq)  out('⚠️  No active equipment found — return_reminder will fire 0.');
if (!$doc) out('⚠️  No document type found — pickup/unclaimed will fire 0.');

mysqli_begin_transaction($conn);
try {
    $injected = [];

    // ---- 2a. Equipment OVERDUE ----
    if ($eq) {
        $ref = 'CRON-TEST-' . date('YmdHis') . '-OVERDUE';

        $sql = "INSERT INTO requests
                (resident_id, request_type, request_date,
                 start_datetime, end_datetime, duration_days, quantity,
                 purpose, status, item_global_id, reference,
                 approved_at, created_at)
                VALUES (?, 'equipment', NOW(),
                        DATE_SUB(NOW(), INTERVAL 6 DAY),
                        DATE_SUB(NOW(), INTERVAL 5 DAY),
                        1, 1,
                        'CRON-TEST-OVERDUE',
                        'borrowed', ?, ?, NOW(), NOW())";
        $stmt = safeExec($conn, $sql, "iss",
            [$residentId, $eq['global_id'], $ref], 'requests-overdue');
        $rid = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        $sql = "INSERT INTO equipment_requests
                (request_id, equipment_global_id, equipment_name,
                 quantity, duration_days, booking_date, pickup_time,
                 return_date, notes)
                VALUES (?, ?, ?, 1, 1,
                        DATE_SUB(CURDATE(), INTERVAL 6 DAY),
                        '09:00:00',
                        DATE_SUB(CURDATE(), INTERVAL 5 DAY),
                        'CRON-TEST-OVERDUE')";
        $stmt = safeExec($conn, $sql, "iss",
            [$rid, $eq['global_id'], $eq['name']], 'equipment_requests-overdue');
        mysqli_stmt_close($stmt);

        $injected[] = "overdue equipment → request #$rid";
    }

    // ---- 2b. Equipment DUE TODAY ----
    if ($eq) {
        $ref = 'CRON-TEST-' . date('YmdHis') . '-DUETODAY';

        $sql = "INSERT INTO requests
                (resident_id, request_type, request_date,
                 start_datetime, end_datetime, duration_days, quantity,
                 purpose, status, item_global_id, reference,
                 approved_at, created_at)
                VALUES (?, 'equipment', NOW(),
                        DATE_SUB(NOW(), INTERVAL 1 DAY),
                        NOW(), 1, 1,
                        'CRON-TEST-DUETODAY',
                        'approved', ?, ?, NOW(), NOW())";
        $stmt = safeExec($conn, $sql, "iss",
            [$residentId, $eq['global_id'], $ref], 'requests-duetoday');
        $rid = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        $sql = "INSERT INTO equipment_requests
                (request_id, equipment_global_id, equipment_name,
                 quantity, duration_days, booking_date, pickup_time,
                 return_date, notes)
                VALUES (?, ?, ?, 1, 1, CURDATE(), '09:00:00', CURDATE(),
                        'CRON-TEST-DUETODAY')";
        $stmt = safeExec($conn, $sql, "iss",
            [$rid, $eq['global_id'], $eq['name']], 'equipment_requests-duetoday');
        mysqli_stmt_close($stmt);

        $injected[] = "due-today equipment → request #$rid";
    }

    // ---- 2c. Document for pickup TODAY ----
    if ($doc) {
        $sql = "INSERT INTO document_requests
                (resident_id, document_type_id, document_type,
                 purpose, notes, fee_type, fee, quantity,
                 id_document_path, pickup_date, pickup_time,
                 status, request_date, processed_date)
                VALUES (?, ?, ?,
                        'CRON-TEST-PICKUP-TODAY', '', 'regular', 0, 1,
                        '', CURDATE(), '10:00:00',
                        'approved', NOW(), NOW())";
        $stmt = safeExec($conn, $sql, "iis",
            [$residentId, $doc['id'], $doc['name']], 'documents-pickup-today');
        $did = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        $injected[] = "pickup-today document → doc #$did";
    }

    // ---- 2d. Approved document overdue for pickup ----
    if ($doc) {
        $sql = "INSERT INTO document_requests
                (resident_id, document_type_id, document_type,
                 purpose, notes, fee_type, fee, quantity,
                 id_document_path, pickup_date, pickup_time,
                 status, request_date, processed_date)
                VALUES (?, ?, ?,
                        'CRON-TEST-UNCLAIMED', '', 'regular', 0, 1,
                        '', DATE_SUB(CURDATE(), INTERVAL 3 DAY), '10:00:00',
                        'approved', NOW(), NOW())";
        $stmt = safeExec($conn, $sql, "iis",
            [$residentId, $doc['id'], $doc['name']], 'documents-unclaimed');
        $did = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        $injected[] = "unclaimed document → doc #$did";
    }

    // ---- 2e. Complaint with hearing TODAY ----
    $adminRow = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM admin WHERE is_active = 1 LIMIT 1"));
    $adminId = (int)($adminRow['id'] ?? 1);
    $ref = 'CRON-TEST-' . date('YmdHis') . '-HEARING';

    $sql = "INSERT INTO complaints
            (reference_number, title, description, complaint_type,
             complaint_subject, status, priority, created_by,
             has_evidence, is_anonymous, assigned_to, assigned_role,
             hearing_date, hearing_time, hearing_location)
            VALUES (?, 'CRON-TEST Hearing',
                    'Temp complaint for cron hearing reminder test.',
                    'dispute', 'Neighbor Dispute',
                    'for_mediation', 'medium', ?, 0, 0, ?, 'captain',
                    CURDATE(), '14:00:00', 'Barangay Hall')";
    $stmt = safeExec($conn, $sql, "sii",
        [$ref, $residentId, $adminId], 'complaints-insert');
    $cid = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $sql = "INSERT INTO complaint_hearings
            (complaint_id, session_type, session_number,
             hearing_date, hearing_time, location, status,
             conducted_by, created_by, session_reason,
             reminder_same_day_sent)
            VALUES (?, 'mediation', 1,
                    CURDATE(), '14:00:00', 'Barangay Hall', 'scheduled',
                    ?, ?, 'CRON-TEST hearing today', 0)";
    $stmt = safeExec($conn, $sql, "iii",
        [$cid, $adminId, $adminId], 'complaint_hearings-insert');
    mysqli_stmt_close($stmt);

    $name = $resident['first_name'] . ' ' . $resident['last_name'];
    $sql = "INSERT INTO complaint_parties
            (complaint_id, party_type, full_name, matched_resident_id)
            VALUES (?, 'complainant', ?, ?)";
    $stmt = safeExec($conn, $sql, "isi",
        [$cid, $name, $residentId], 'complaint_parties-insert');
    mysqli_stmt_close($stmt);

    $injected[] = "hearing-today complaint → complaint #$cid";

    mysqli_commit($conn);

    foreach ($injected as $i) out("  ✅ $i");

} catch (Throwable $e) {
    mysqli_rollback($conn);
    out('');
    out('❌ Injection failed:');
    out($e->getMessage());
    out('');
    out('Fix the SQL above (compare column names to your actual schema),');
    out('then re-run this test.');
    exit(1);
}

out('');

/* ============================================================
   STEP 3 — Run tasks
   ============================================================ */
title('STEP 3 — Running tasks');

$tasks = [
    'return_reminder'    => __DIR__ . '/tasks/return_reminder.php',
    'pickup_reminder'    => __DIR__ . '/tasks/pickup_reminder.php',
    'unclaimed_document' => __DIR__ . '/tasks/unclaimed_document.php',
    'hearing_reminder'   => __DIR__ . '/tasks/hearing_reminder.php',
];

$results = [];

foreach ($tasks as $key => $file) {
    if (!file_exists($file)) {
        out("▶ $key  ❌ file not found: $file");
        $results[$key] = ['sent' => 0, 'error' => 'file missing'];
        continue;
    }

    require_once $file;
    $func = 'cron_task_' . $key;

    out("▶ $key");
    if (!function_exists($func)) {
        out("   ❌ function $func() not defined");
        $results[$key] = ['sent' => 0, 'error' => 'not defined'];
        continue;
    }

    $t0 = microtime(true);
    try {
        $sent = $func($conn);
        $ms = round((microtime(true) - $t0) * 1000, 1);
        out("   ✅ returned sent=$sent ({$ms}ms)");
        $results[$key] = ['sent' => (int)$sent, 'error' => null];
    } catch (Throwable $e) {
        $ms = round((microtime(true) - $t0) * 1000, 1);
        out("   ❌ THREW: " . $e->getMessage());
        out("      at " . basename($e->getFile()) . ':' . $e->getLine());
        $results[$key] = ['sent' => 0, 'error' => $e->getMessage()];
    }
}

out('');

/* ============================================================
   STEP 4 — Notifications created
   ============================================================ */
title('STEP 4 — Notifications created for resident today');

$q = mysqli_query($conn,
    "SELECT id, type, title, is_read, created_at
     FROM notifications
     WHERE resident_id = $residentId
       AND DATE(created_at) = CURDATE()
       AND type IN ('return_reminder','equipment_overdue',
                    'document_pickup_today','document_unclaimed',
                    'hearing_today')
     ORDER BY id DESC");

$count = 0;
while ($row = mysqli_fetch_assoc($q)) {
    $count++;
    printf("  #%-5d [%-25s] read=%d  %s\n",
        $row['id'], $row['type'], $row['is_read'],
        mb_strimwidth($row['title'], 0, 50, '…'));
}
if ($count === 0) out('  (none)');
out('');

/* ============================================================
   STEP 5 — Cleanup
   ============================================================ */
if (!$keep) {
    title('STEP 5 — Cleanup');
    cleanupTestRows($conn, $residentId);
    out('✅ Temp rows removed.');
} else {
    title('STEP 5 — Skipped (keep=1)');
    out('⚠️  Temp rows left in place.');
    out('   Cleanup: ?resident_id=' . $residentId . '&cleanup=1');
}

out('');

/* ============================================================
   SUMMARY
   ============================================================ */
title('SUMMARY');
foreach ($results as $k => $r) {
    $icon = $r['error'] ? '❌' : ($r['sent'] > 0 ? '✅' : '⚠️ ');
    out(sprintf('  %s %-22s sent=%d%s',
        $icon, $k, $r['sent'],
        $r['error'] ? '  ERROR: ' . $r['error'] : ''));
}
out('');
exit(0);