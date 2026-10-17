<?php
// BRITE Reports AJAX endpoint. Follows household_ajax.php conventions.
session_start();
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}
function brReportTableExists($conn, $table) {
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 's', $table); mysqli_stmt_execute($stmt); mysqli_stmt_bind_result($stmt, $n); mysqli_stmt_fetch($stmt); mysqli_stmt_close($stmt);
    return (int)$n > 0;
}
function brReportQuery($conn, $sql, $types = '', $params = []) {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return [];
    if ($types !== '') {
        $refs = []; foreach ($params as $k => &$v) $refs[$k] = &$v;
        mysqli_stmt_bind_param($stmt, $types, ...$refs);
    }
    if (!mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); return []; }
    $result = mysqli_stmt_get_result($stmt); $rows = [];
    if ($result) while ($row = mysqli_fetch_assoc($result)) $rows[] = $row;
    mysqli_stmt_close($stmt); return $rows;
}
function brReportCount($conn, $sql, $types = '', $params = []) {
    $rows = brReportQuery($conn, $sql, $types, $params); return (int)($rows[0]['total'] ?? 0);
}
function brReportDate($value, $fallback) {
    $dt = DateTime::createFromFormat('!Y-m-d', (string)$value);
    return $dt && $dt->format('Y-m-d') === $value ? $value : $fallback;
}
function brReportRowsConfig($conn, $type, $from, $to) {
    // Use readable names instead of internal foreign-key IDs in both the on-screen table and Excel export.
    $configs = [
        'residents' => [
            'title' => 'Residents & Accounts', 'table'=>'resident', 'date'=>'created_at',
            'sql' => "SELECT CONCAT_WS(' ', NULLIF(first_name,''), NULLIF(middle_name,''), NULLIF(last_name,'')) AS resident_name, username, email, COALESCE(phone_number, phone, '') AS phone_number, address, CASE WHEN is_verified=1 THEN 'Verified' ELSE 'Unverified' END AS verification_status, CASE WHEN is_active=1 THEN 'Active' ELSE 'Inactive' END AS account_status, created_at FROM resident WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC LIMIT 1000",
            'columns' => ['resident_name'=>'Resident Name','username'=>'Username','email'=>'Email','phone_number'=>'Contact Number','address'=>'Address','verification_status'=>'Verification Status','account_status'=>'Account Status','created_at'=>'Registration Date']
        ],
        'documents' => [
            'title' => 'Document Requests', 'table'=>'document_requests', 'date'=>'request_date',
            'sql' => "SELECT d.document_type AS document_type, CONCAT_WS(' ', NULLIF(r.first_name,''), NULLIF(r.middle_name,''), NULLIF(r.last_name,'')) AS resident_name, d.purpose, d.status, d.payment_status, d.fee, d.request_date, d.processed_date, d.claimed_at FROM document_requests d LEFT JOIN resident r ON r.id=d.resident_id WHERE DATE(d.request_date) BETWEEN ? AND ? ORDER BY d.request_date DESC LIMIT 1000",
            'columns' => ['document_type'=>'Document Type','resident_name'=>'Resident Name','purpose'=>'Purpose','status'=>'Request Status','payment_status'=>'Payment Status','fee'=>'Fee (PHP)','request_date'=>'Request Date','processed_date'=>'Processed Date','claimed_at'=>'Claimed Date']
        ],
        'complaints' => [
            'title' => 'Complaints & Lupon', 'table'=>'complaints', 'date'=>'created_at',
            'sql' => "SELECT c.reference_number, c.title, c.complaint_type, c.complaint_subject, CASE WHEN c.is_anonymous=1 THEN 'Anonymous Resident' ELSE CONCAT_WS(' ', NULLIF(r.first_name,''), NULLIF(r.middle_name,''), NULLIF(r.last_name,'')) END AS complainant_name, c.status, c.priority, c.created_at, c.hearing_date, c.resolved_at FROM complaints c LEFT JOIN resident r ON r.id=c.created_by WHERE DATE(c.created_at) BETWEEN ? AND ? ORDER BY c.created_at DESC LIMIT 1000",
            'columns' => ['reference_number'=>'Complaint Reference','title'=>'Complaint Title','complaint_type'=>'Complaint Type','complaint_subject'=>'Subject / Classification','complainant_name'=>'Complainant Name','status'=>'Case Status','priority'=>'Priority','created_at'=>'Date Filed','hearing_date'=>'Hearing Date','resolved_at'=>'Resolved Date']
        ],
        'services' => [
            'title' => 'Barangay Service Requests', 'table'=>'requests', 'date'=>'request_date',
            'sql' => "SELECT q.reference AS request_reference, CONCAT_WS(' ', NULLIF(r.first_name,''), NULLIF(r.middle_name,''), NULLIF(r.last_name,'')) AS resident_name, q.request_type, q.request_date, q.start_datetime, q.end_datetime, q.quantity, q.purpose, q.status, q.item_global_id AS item_reference, q.urgency FROM requests q LEFT JOIN resident r ON r.id=q.resident_id WHERE DATE(q.request_date) BETWEEN ? AND ? ORDER BY q.request_date DESC LIMIT 1000",
            'columns' => ['request_reference'=>'Request Reference','resident_name'=>'Resident Name','request_type'=>'Service Type','request_date'=>'Request Date','start_datetime'=>'Start Date / Time','end_datetime'=>'End Date / Time','quantity'=>'Quantity','purpose'=>'Purpose','status'=>'Request Status','item_reference'=>'Item Reference','urgency'=>'Urgency']
        ],
        'emergencies' => [
            'title' => 'Emergency Type Report', 'table'=>'emergencies', 'date'=>'created_at',
            'sql' => "SELECT external_id AS emergency_reference, emergency_type, name AS caller_name, barangay, caller_number, latitude, longitude, accepted_by_name, created_at FROM emergencies WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC LIMIT 1000",
            'columns' => ['emergency_reference'=>'Emergency Reference','emergency_type'=>'Emergency Type','caller_name'=>'Caller Name','barangay'=>'Barangay','caller_number'=>'Contact Number','latitude'=>'Latitude','longitude'=>'Longitude','accepted_by_name'=>'Accepted By','created_at'=>'Reported Date / Time']
        ],
        'audit' => [
            'title' => 'Audit Trail', 'table'=>'admin_activity_log', 'date'=>'created_at',
            'sql' => "SELECT COALESCE(NULLIF(a.full_name,''), a.username, 'Unknown Administrator') AS admin_name, a.admin_role AS admin_role, l.action, l.details, l.ip_address, l.created_at FROM admin_activity_log l LEFT JOIN admin a ON a.id=l.admin_id WHERE DATE(l.created_at) BETWEEN ? AND ? ORDER BY l.created_at DESC LIMIT 1000",
            'columns' => ['admin_name'=>'Administrator Name','admin_role'=>'Role','action'=>'Action','details'=>'Activity Details','ip_address'=>'IP Address','created_at'=>'Activity Date / Time']
        ]
    ];
    if (!isset($configs[$type])) $type = 'residents';
    $c = $configs[$type];
    if (!brReportTableExists($conn, $c['table'])) return ['title'=>$c['title'], 'columns'=>[], 'rows'=>[]];
    $rows = brReportQuery($conn, $c['sql'], 'ss', [$from, $to]);
    $columns = [];
    foreach ($c['columns'] as $key=>$label) $columns[] = ['key'=>$key, 'label'=>$label];
    return ['title'=>$c['title'], 'columns'=>$columns, 'rows'=>$rows];
}
function brReportExcelCell($value) {
    $value = (string)($value ?? '');
    // Stop formula execution when the exported workbook is opened in spreadsheet software.
    if (preg_match('/^\s*[=+@-]/', $value)) $value = "'" . $value;
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$today = date('Y-m-d');
$from = brReportDate($_GET['from'] ?? '', date('Y-m-01'));
$to = brReportDate($_GET['to'] ?? '', $today);
if ($from > $to) { $tmp=$from; $from=$to; $to=$tmp; }
$type = $_GET['type'] ?? 'overview';
if ($action === 'get_report') {
    $stats = []; $monthly = []; $classifications = []; $emergencyTypes = []; $serviceTypes = [];
    $types = [
      'overview' => ['residents','documents','complaints','services','emergencies'],
      'residents' => ['residents'], 'documents' => ['documents'],
      'complaints' => ['complaints'], 'services' => ['services'],
      'emergencies' => ['emergencies'], 'audit' => ['audit']
    ];
    if (!isset($types[$type])) $type='overview';
    $countMap = [
      'residents'=>['resident','created_at','Resident Accounts','fa-users','is_active = 1'],
      'documents'=>['document_requests','request_date','Document Requests','fa-file-alt','1=1'],
      'complaints'=>['complaints','created_at','Complaints & Lupon','fa-scale-balanced','1=1'],
      'services'=>['requests','request_date','Service Requests','fa-clipboard-list','1=1'],
      'emergencies'=>['emergencies','created_at','Emergency Reports','fa-triangle-exclamation','1=1'],
      'audit'=>['admin_activity_log','created_at','Audit Events','fa-shield-alt','1=1']
    ];
    foreach ($types[$type] as $key) {
        [$table,$dateCol,$label,$icon,$extra] = $countMap[$key];
        $count = brReportTableExists($conn,$table) ? brReportCount($conn,"SELECT COUNT(*) AS total FROM `$table` WHERE DATE(`$dateCol`) BETWEEN ? AND ? AND $extra",'ss',[$from,$to]) : 0;
        if ($key==='residents' && brReportTableExists($conn,'resident')) {
            $verified = brReportCount($conn,"SELECT COUNT(*) AS total FROM resident WHERE DATE(created_at) BETWEEN ? AND ? AND is_active=1 AND is_verified=1",'ss',[$from,$to]);
            $stats[]=['label'=>'Verified Resident Accounts','value'=>number_format($verified),'icon'=>'fa-user-check'];
        }
        $stats[]=['label'=>$label,'value'=>number_format($count),'icon'=>$icon];
    }
    if ($type==='overview') {
        $stats = [
          ['label'=>'Active Residents','value'=>number_format(brReportTableExists($conn,'resident')?brReportCount($conn,'SELECT COUNT(*) AS total FROM resident WHERE is_active=1'):0),'icon'=>'fa-users'],
          ['label'=>'Document Requests','value'=>number_format(brReportTableExists($conn,'document_requests')?brReportCount($conn,'SELECT COUNT(*) AS total FROM document_requests WHERE DATE(request_date) BETWEEN ? AND ?','ss',[$from,$to]):0),'icon'=>'fa-file-alt'],
          ['label'=>'Complaints & Lupon','value'=>number_format(brReportTableExists($conn,'complaints')?brReportCount($conn,'SELECT COUNT(*) AS total FROM complaints WHERE DATE(created_at) BETWEEN ? AND ?','ss',[$from,$to]):0),'icon'=>'fa-scale-balanced'],
          ['label'=>'Service Requests','value'=>number_format(brReportTableExists($conn,'requests')?brReportCount($conn,'SELECT COUNT(*) AS total FROM requests WHERE DATE(request_date) BETWEEN ? AND ?','ss',[$from,$to]):0),'icon'=>'fa-clipboard-list'],
          ['label'=>'Emergency Reports','value'=>number_format(brReportTableExists($conn,'emergencies')?brReportCount($conn,'SELECT COUNT(*) AS total FROM emergencies WHERE DATE(created_at) BETWEEN ? AND ?','ss',[$from,$to]):0),'icon'=>'fa-triangle-exclamation']
        ];
    }
    for ($i=5; $i>=0; $i--) {
        $monthStart=date('Y-m-01',strtotime("-$i months")); $monthEnd=date('Y-m-t',strtotime($monthStart));
        $monthRow=['label'=>date('M Y',strtotime($monthStart)),'total'=>0];
        if ($type==='overview') {
            $monthRow['documents']=brReportTableExists($conn,'document_requests')?brReportCount($conn,"SELECT COUNT(*) AS total FROM document_requests WHERE request_date >= ? AND request_date < DATE_ADD(?, INTERVAL 1 DAY)",'ss',[$monthStart,$monthEnd]):0;
            $monthRow['complaints']=brReportTableExists($conn,'complaints')?brReportCount($conn,"SELECT COUNT(*) AS total FROM complaints WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)",'ss',[$monthStart,$monthEnd]):0;
            $monthRow['services']=brReportTableExists($conn,'requests')?brReportCount($conn,"SELECT COUNT(*) AS total FROM requests WHERE request_date >= ? AND request_date < DATE_ADD(?, INTERVAL 1 DAY)",'ss',[$monthStart,$monthEnd]):0;
            $monthRow['emergencies']=brReportTableExists($conn,'emergencies')?brReportCount($conn,"SELECT COUNT(*) AS total FROM emergencies WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)",'ss',[$monthStart,$monthEnd]):0;
            $monthRow['total']=$monthRow['documents']+$monthRow['complaints']+$monthRow['services']+$monthRow['emergencies'];
        } else {
            $table=$countMap[$type][0] ?? 'resident';
            $dateCol=($table==='document_requests'||$table==='requests')?'request_date':'created_at';
            if (brReportTableExists($conn,$table)) $monthRow['total']=brReportCount($conn,"SELECT COUNT(*) AS total FROM `$table` WHERE `$dateCol` >= ? AND `$dateCol` < DATE_ADD(?, INTERVAL 1 DAY)",'ss',[$monthStart,$monthEnd]);
        }
        $monthly[]=$monthRow;
    }

    // Type/classification breakdown: intentionally not grouped by workflow status.
    if ($type==='overview') {
        $parts=[];
        if (brReportTableExists($conn,'document_requests')) $parts[]="SELECT CONCAT('Document: ', COALESCE(NULLIF(document_type,''),'Unspecified')) AS label, COUNT(*) AS total FROM document_requests WHERE DATE(request_date) BETWEEN '$from' AND '$to' GROUP BY document_type";
        if (brReportTableExists($conn,'complaints')) $parts[]="SELECT CONCAT('Complaint: ', COALESCE(NULLIF(complaint_subject,''), NULLIF(complaint_type,''),'Unspecified')) AS label, COUNT(*) AS total FROM complaints WHERE DATE(created_at) BETWEEN '$from' AND '$to' GROUP BY complaint_subject, complaint_type";
        if (brReportTableExists($conn,'requests')) $parts[]="SELECT CONCAT('Service: ', COALESCE(NULLIF(request_type,''),'Unspecified')) AS label, COUNT(*) AS total FROM requests WHERE DATE(request_date) BETWEEN '$from' AND '$to' GROUP BY request_type";
        if (brReportTableExists($conn,'emergencies')) $parts[]="SELECT CONCAT('Emergency: ', COALESCE(NULLIF(emergency_type,''),'Unspecified')) AS label, COUNT(*) AS total FROM emergencies WHERE DATE(created_at) BETWEEN '$from' AND '$to' GROUP BY emergency_type";
        if ($parts) $classifications=brReportQuery($conn,'SELECT label, SUM(total) AS total FROM ('.implode(' UNION ALL ',$parts).') AS all_types GROUP BY label ORDER BY total DESC LIMIT 12');
    } elseif ($type==='residents' && brReportTableExists($conn,'resident')) {
        $classifications=brReportQuery($conn,"SELECT CASE WHEN is_verified=1 THEN 'Verified account' ELSE 'Unverified account' END AS label, COUNT(*) AS total FROM resident WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY label ORDER BY total DESC",'ss',[$from,$to]);
    } elseif ($type==='documents' && brReportTableExists($conn,'document_requests')) {
        $classifications=brReportQuery($conn,"SELECT COALESCE(NULLIF(document_type,''),'Unspecified') AS label, COUNT(*) AS total FROM document_requests WHERE DATE(request_date) BETWEEN ? AND ? GROUP BY document_type ORDER BY total DESC LIMIT 12",'ss',[$from,$to]);
    } elseif ($type==='complaints' && brReportTableExists($conn,'complaints')) {
        $classifications=brReportQuery($conn,"SELECT COALESCE(NULLIF(complaint_subject,''),NULLIF(complaint_type,''),'Unspecified') AS label, COUNT(*) AS total FROM complaints WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY complaint_subject, complaint_type ORDER BY total DESC LIMIT 12",'ss',[$from,$to]);
    } elseif ($type==='services' && brReportTableExists($conn,'requests')) {
        $classifications=brReportQuery($conn,"SELECT COALESCE(NULLIF(request_type,''),'Unspecified') AS label, COUNT(*) AS total FROM requests WHERE DATE(request_date) BETWEEN ? AND ? GROUP BY request_type ORDER BY total DESC",'ss',[$from,$to]);
    } elseif ($type==='emergencies' && brReportTableExists($conn,'emergencies')) {
        $classifications=brReportQuery($conn,"SELECT COALESCE(NULLIF(emergency_type,''),'Unspecified') AS label, COUNT(*) AS total FROM emergencies WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY emergency_type ORDER BY total DESC",'ss',[$from,$to]);
    } elseif ($type==='audit' && brReportTableExists($conn,'admin_activity_log')) {
        $classifications=brReportQuery($conn,"SELECT COALESCE(NULLIF(action,''),'Unspecified action') AS label, COUNT(*) AS total FROM admin_activity_log WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY action ORDER BY total DESC LIMIT 12",'ss',[$from,$to]);
    }
    if (brReportTableExists($conn,'emergencies')) $emergencyTypes=brReportQuery($conn,"SELECT COALESCE(NULLIF(emergency_type,''),'Unspecified') AS label, COUNT(*) AS total FROM emergencies WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY emergency_type ORDER BY total DESC",'ss',[$from,$to]);
    if (brReportTableExists($conn,'requests')) $serviceTypes=brReportQuery($conn,"SELECT COALESCE(NULLIF(request_type,''),'Unspecified') AS label, COUNT(*) AS total FROM requests WHERE DATE(request_date) BETWEEN ? AND ? GROUP BY request_type ORDER BY total DESC",'ss',[$from,$to]);

    $configType = ($type==='overview')?'complaints':$type;
    $config = brReportRowsConfig($conn,$configType,$from,$to); $columns=$config['columns']; $rows=$config['rows'];
    $emergencyPoints = [];
    if (brReportTableExists($conn,'emergencies')) {
        $emergencyPoints = brReportQuery($conn, "SELECT latitude AS lat, longitude AS lng, emergency_type AS type, created_at FROM emergencies WHERE DATE(created_at) BETWEEN ? AND ? AND latitude IS NOT NULL AND longitude IS NOT NULL AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180 ORDER BY created_at DESC LIMIT 5000", 'ss', [$from,$to]);
    }
    echo json_encode(['success'=>true,'stats'=>$stats,'monthly'=>$monthly,'classifications'=>$classifications,'emergency_types'=>$emergencyTypes,'service_types'=>$serviceTypes,'emergency_points'=>$emergencyPoints,'columns'=>$columns,'rows'=>$rows,'from'=>$from,'to'=>$to]); exit;
}
if ($action === 'export_excel' || $action === 'export_csv') {
    $configType = ($type==='overview')?'complaints':$type;
    $config = brReportRowsConfig($conn,$configType,$from,$to);
    $isExcel = ($action === 'export_excel');
    $fileType = preg_replace('/[^a-z0-9_-]/i', '-', $configType);
    if ($isExcel) {
        // Excel-compatible HTML workbook: real table, bold column labels, title and reporting period.
        header_remove('Content-Type');
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="BRITE-'.$fileType.'-report-'.$from.'-to-'.$to.'.xls"');
        echo "\xEF\xBB\xBF";
        echo '<html><head><meta charset="UTF-8"><style>';
        echo 'body{font-family:Arial,sans-serif;color:#24352a}';
        echo '.title{font-size:18pt;font-weight:bold;color:#174b2a;padding:12px 8px}';
        echo '.meta{font-size:10pt;color:#526357;padding:4px 8px}';
        echo 'table{border-collapse:collapse;width:100%;font-size:10pt}';
        echo 'th{font-weight:bold;background:#236b3b;color:#fff;border:1px solid #174b2a;padding:9px;text-align:left}';
        echo 'td{border:1px solid #cfdacf;padding:7px;vertical-align:top}';
        echo 'tr:nth-child(even) td{background:#f2f7f3}';
        echo '.empty{font-style:italic;color:#66756b;padding:12px}';
        echo '</style></head><body>';
        echo '<table><tr><td class="title" colspan="'.max(count($config['columns']),1).'">BRITE — '.brReportExcelCell($config['title'] ?? 'Administrative Report').'</td></tr>';
        echo '<tr><td class="meta" colspan="'.max(count($config['columns']),1).'"><b>Reporting Period:</b> '.brReportExcelCell($from).' to '.brReportExcelCell($to).'</td></tr>';
        echo '<tr><td class="meta" colspan="'.max(count($config['columns']),1).'"><b>Generated:</b> '.brReportExcelCell(date('Y-m-d H:i:s')).' &nbsp; | &nbsp; <b>Source:</b> BRITE system records</td></tr><tr><td colspan="'.max(count($config['columns']),1).'">&nbsp;</td></tr></table>';
        echo '<table><thead><tr>';
        if (!empty($config['columns'])) foreach ($config['columns'] as $col) echo '<th>'.brReportExcelCell($col['label']).'</th>';
        else echo '<th>Report Result</th>';
        echo '</tr></thead><tbody>';
        if (!empty($config['rows'])) {
            foreach ($config['rows'] as $row) {
                echo '<tr>';
                foreach ($config['columns'] as $col) echo '<td>'.brReportExcelCell($row[$col['key']] ?? '').'</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td class="empty" colspan="'.max(count($config['columns']),1).'">No records found for the selected reporting period.</td></tr>';
        }
        echo '</tbody></table></body></html>';
        exit;
    }
    // Retain CSV compatibility for existing links, but use the same readable, joined columns.
    header_remove('Content-Type'); header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="brite-'.$fileType.'-'.$from.'-to-'.$to.'.csv"');
    $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
    if (!empty($config['columns'])) fputcsv($out,array_column($config['columns'],'label')); else fputcsv($out,['No records found']);
    foreach ($config['rows'] as $row) { $line=[]; foreach ($config['columns'] as $col) { $v=(string)($row[$col['key']]??''); if (preg_match('/^\s*[=+@-]/',$v)) $v="'".$v; $line[]=$v; } fputcsv($out,$line); }
    fclose($out); exit;
}
http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid report action']);
