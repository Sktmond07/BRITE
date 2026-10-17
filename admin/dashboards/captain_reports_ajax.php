<?php
// BRITE Reports AJAX endpoint. Follows household_ajax.php conventions.
session_start();
require_once __DIR__ . '/../../config/database.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'admin') { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
$roleStmt = mysqli_prepare($conn, 'SELECT admin_role FROM admin WHERE id = ? LIMIT 1');
if (!$roleStmt) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Unable to verify Captain access']); exit; }
mysqli_stmt_bind_param($roleStmt, 'i', $_SESSION['user_id']); mysqli_stmt_execute($roleStmt); mysqli_stmt_bind_result($roleStmt, $captainRole); mysqli_stmt_fetch($roleStmt); mysqli_stmt_close($roleStmt);
if (strtolower((string)$captainRole) !== 'captain') { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Captain access only']); exit; }
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
        'households' => [
            'title' => 'Residents & Household Registry', 'table'=>'household_members', 'date'=>'created_at',
            'sql' => "SELECT hm.id AS resident_reference, CONCAT_WS(' ', NULLIF(hm.first_name,''), NULLIF(hm.middle_name,''), NULLIF(hm.last_name,''), NULLIF(hm.ext,'')) AS resident_name, h.purok, h.address AS household_address, hm.age, CASE hm.sex WHEN 'M' THEN 'Male' WHEN 'F' THEN 'Female' ELSE 'Not specified' END AS gender, hm.civil_status, hm.occupation, hm.employment_status, hm.phone_number, hm.created_at FROM household_members hm LEFT JOIN households h ON h.id=hm.household_id WHERE DATE(hm.created_at) BETWEEN ? AND ? ORDER BY hm.created_at DESC LIMIT 1000",
            'columns' => ['resident_reference'=>'Resident Record','resident_name'=>'Resident Name','purok'=>'Purok','household_address'=>'Household Address','age'=>'Age','gender'=>'Gender','civil_status'=>'Civil Status','occupation'=>'Occupation','employment_status'=>'Employment Status','phone_number'=>'Contact Number','created_at'=>'Registry Date']
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
        'vehicles' => [
            'title' => 'Barangay Vehicle Services', 'table'=>'requests', 'date'=>'request_date',
            'sql' => "SELECT q.reference AS request_reference, CONCAT_WS(' ', NULLIF(r.first_name,''), NULLIF(r.middle_name,''), NULLIF(r.last_name,'')) AS resident_name, q.purpose, q.urgency, q.status, q.trip_date, q.trip_slot_start, q.trip_slot_end, q.request_date, q.completed_at FROM requests q LEFT JOIN resident r ON r.id=q.resident_id WHERE q.request_type='vehicle' AND DATE(q.request_date) BETWEEN ? AND ? ORDER BY q.request_date DESC LIMIT 1000",
            'columns' => ['request_reference'=>'Request Reference','resident_name'=>'Resident Name','purpose'=>'Trip Purpose','urgency'=>'Urgency','status'=>'Request Status','trip_date'=>'Trip Date','trip_slot_start'=>'Trip Start','trip_slot_end'=>'Trip End','request_date'=>'Request Date','completed_at'=>'Completed At']
        ],
        'resources' => [
            'title' => 'Equipment & Facility Usage', 'table'=>'requests', 'date'=>'request_date',
            'sql' => "SELECT q.reference AS request_reference, CONCAT_WS(' ', NULLIF(r.first_name,''), NULLIF(r.middle_name,''), NULLIF(r.last_name,'')) AS resident_name, q.request_type, COALESCE(NULLIF(er.equipment_name,''),NULLIF(fr.facility_name,''),NULLIF(q.item_global_id,''),'Unspecified') AS requested_item, q.quantity, q.purpose, q.status, q.start_datetime, q.end_datetime, q.request_date FROM requests q LEFT JOIN resident r ON r.id=q.resident_id LEFT JOIN equipment_requests er ON er.request_id=q.id LEFT JOIN facility_requests fr ON fr.request_id=q.id WHERE q.request_type IN ('equipment','facility') AND DATE(q.request_date) BETWEEN ? AND ? ORDER BY q.request_date DESC LIMIT 1000",
            'columns' => ['request_reference'=>'Request Reference','resident_name'=>'Resident Name','request_type'=>'Resource Type','requested_item'=>'Equipment / Facility','quantity'=>'Quantity','purpose'=>'Purpose','status'=>'Request Status','start_datetime'=>'Start Date / Time','end_datetime'=>'End Date / Time','request_date'=>'Request Date']
        ],
        'emergencies' => [
            'title' => 'Emergency Type Report', 'table'=>'emergencies', 'date'=>'created_at',
            'sql' => "SELECT external_id AS emergency_reference, emergency_type, name AS caller_name, barangay, caller_number, latitude, longitude, accepted_by_name, created_at FROM emergencies WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC LIMIT 1000",
            'columns' => ['emergency_reference'=>'Emergency Reference','emergency_type'=>'Emergency Type','caller_name'=>'Caller Name','barangay'=>'Barangay','caller_number'=>'Contact Number','latitude'=>'Latitude','longitude'=>'Longitude','accepted_by_name'=>'Accepted By','created_at'=>'Reported Date / Time']
        ]
    ];
    if (!isset($configs[$type])) $type = 'households';
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
      'overview' => ['households','documents','complaints','vehicles','resources','emergencies'],
      'demand' => ['documents','vehicles','resources'],
      'households' => ['households'], 'documents' => ['documents'],
      'complaints' => ['complaints'], 'vehicles' => ['vehicles'], 'resources' => ['resources'],
      'emergencies' => ['emergencies']
    ];
    if (!isset($types[$type])) $type='overview';
    $countMap = [
      'households'=>['households','created_at','Households','fa-house','1=1'],
      'documents'=>['document_requests','request_date','Document Requests','fa-file-alt','1=1'],
      'complaints'=>['complaints','created_at','Complaints & Lupon','fa-scale-balanced','1=1'],
      'vehicles'=>['requests','request_date','Vehicle Requests','fa-van-shuttle',"request_type = 'vehicle'"],
      'resources'=>['requests','request_date','Equipment & Facility Requests','fa-toolbox',"request_type IN ('equipment','facility')"],
      'emergencies'=>['emergencies','created_at','Emergency Reports','fa-triangle-exclamation','1=1']
    ];
    foreach ($types[$type] as $key) {
        [$table,$dateCol,$label,$icon,$extra] = $countMap[$key];
        $count = brReportTableExists($conn,$table) ? brReportCount($conn,"SELECT COUNT(*) AS total FROM `$table` WHERE DATE(`$dateCol`) BETWEEN ? AND ? AND $extra",'ss',[$from,$to]) : 0;
        if ($key==='households' && brReportTableExists($conn,'household_members')) {
            $members = brReportCount($conn,"SELECT COUNT(*) AS total FROM household_members WHERE DATE(created_at) BETWEEN ? AND ?",'ss',[$from,$to]);
            $stats[]=['label'=>'Total Residents','value'=>number_format($members),'icon'=>'fa-users'];
            $registered = brReportTableExists($conn,'resident') ? brReportCount($conn,"SELECT COUNT(*) AS total FROM resident WHERE DATE(created_at) BETWEEN ? AND ?",'ss',[$from,$to]) : 0;
            $stats[]=['label'=>'Registered Resident Accounts','value'=>number_format($registered),'icon'=>'fa-user-check'];
        }
        $stats[]=['label'=>$label,'value'=>number_format($count),'icon'=>$icon];
    }
    if ($type==='overview') {
        $stats = [
          ['label'=>'Households','value'=>number_format(brReportTableExists($conn,'households')?brReportCount($conn,'SELECT COUNT(*) AS total FROM households'):0),'icon'=>'fa-house'],
          ['label'=>'Total Residents','value'=>number_format(brReportTableExists($conn,'household_members')?brReportCount($conn,'SELECT COUNT(*) AS total FROM household_members'):0),'icon'=>'fa-users'],
          ['label'=>'Document Requests','value'=>number_format(brReportTableExists($conn,'document_requests')?brReportCount($conn,'SELECT COUNT(*) AS total FROM document_requests WHERE DATE(request_date) BETWEEN ? AND ?','ss',[$from,$to]):0),'icon'=>'fa-file-alt'],
          ['label'=>'Complaints & Lupon','value'=>number_format(brReportTableExists($conn,'complaints')?brReportCount($conn,'SELECT COUNT(*) AS total FROM complaints WHERE DATE(created_at) BETWEEN ? AND ?','ss',[$from,$to]):0),'icon'=>'fa-scale-balanced'],
          ['label'=>'Vehicle Requests','value'=>number_format(brReportTableExists($conn,'requests')?brReportCount($conn,"SELECT COUNT(*) AS total FROM requests WHERE request_type='vehicle' AND DATE(request_date) BETWEEN ? AND ?",'ss',[$from,$to]):0),'icon'=>'fa-truck'],
          ['label'=>'Equipment & Facility Requests','value'=>number_format(brReportTableExists($conn,'requests')?brReportCount($conn,"SELECT COUNT(*) AS total FROM requests WHERE request_type IN ('equipment','facility') AND DATE(request_date) BETWEEN ? AND ?",'ss',[$from,$to]):0),'icon'=>'fa-toolbox'],
          ['label'=>'Emergency Reports','value'=>number_format(brReportTableExists($conn,'emergencies')?brReportCount($conn,'SELECT COUNT(*) AS total FROM emergencies WHERE DATE(created_at) BETWEEN ? AND ?','ss',[$from,$to]):0),'icon'=>'fa-triangle-exclamation']
        ];
    }
    for ($i=5; $i>=0; $i--) {
        $monthStart=date('Y-m-01',strtotime("-$i months")); $monthEnd=date('Y-m-t',strtotime($monthStart));
        $monthRow=['label'=>date('M Y',strtotime($monthStart)),'total'=>0];
        if ($type==='households') {
            $monthRow['residents']=brReportTableExists($conn,'household_members')?brReportCount($conn,"SELECT COUNT(*) AS total FROM household_members WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)",'ss',[$monthStart,$monthEnd]):0;
            $monthRow['registered_residents']=brReportTableExists($conn,'resident')?brReportCount($conn,"SELECT COUNT(*) AS total FROM resident WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)",'ss',[$monthStart,$monthEnd]):0;
            $monthRow['total']=$monthRow['residents'];
        } elseif ($type==='overview' || $type==='demand') {
            $monthRow['documents']=brReportTableExists($conn,'document_requests')?brReportCount($conn,"SELECT COUNT(*) AS total FROM document_requests WHERE request_date >= ? AND request_date < DATE_ADD(?, INTERVAL 1 DAY)",'ss',[$monthStart,$monthEnd]):0;
            $monthRow['complaints']=brReportTableExists($conn,'complaints')?brReportCount($conn,"SELECT COUNT(*) AS total FROM complaints WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)",'ss',[$monthStart,$monthEnd]):0;
            $monthRow['vehicles']=brReportTableExists($conn,'requests')?brReportCount($conn,"SELECT COUNT(*) AS total FROM requests WHERE request_type='vehicle' AND request_date >= ? AND request_date < DATE_ADD(?, INTERVAL 1 DAY)",'ss',[$monthStart,$monthEnd]):0;
            $monthRow['resources']=brReportTableExists($conn,'requests')?brReportCount($conn,"SELECT COUNT(*) AS total FROM requests WHERE request_type IN ('equipment','facility') AND request_date >= ? AND request_date < DATE_ADD(?, INTERVAL 1 DAY)",'ss',[$monthStart,$monthEnd]):0;
            $monthRow['emergencies']=brReportTableExists($conn,'emergencies')?brReportCount($conn,"SELECT COUNT(*) AS total FROM emergencies WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)",'ss',[$monthStart,$monthEnd]):0;
            $monthRow['total']=$monthRow['documents']+$monthRow['complaints']+$monthRow['vehicles']+$monthRow['resources']+$monthRow['emergencies'];
        } else {
            $table=$countMap[$type][0] ?? 'households';
            $dateCol=($table==='document_requests'||$table==='requests')?'request_date':'created_at';
            if (brReportTableExists($conn,$table)) {
                $extra = $type==='vehicles' ? " AND request_type='vehicle'" : ($type==='resources' ? " AND request_type IN ('equipment','facility')" : '');
                $monthRow['total']=brReportCount($conn,"SELECT COUNT(*) AS total FROM `$table` WHERE `$dateCol` >= ? AND `$dateCol` < DATE_ADD(?, INTERVAL 1 DAY)$extra",'ss',[$monthStart,$monthEnd]);
            }
        }
        $monthly[]=$monthRow;
    }

    // Type/classification breakdown: intentionally not grouped by workflow status.
    if ($type==='overview' || $type==='demand') {
        $parts=[];
        if (brReportTableExists($conn,'document_requests')) $parts[]="SELECT CONCAT('Document: ', COALESCE(NULLIF(document_type,''),'Unspecified')) AS label, COUNT(*) AS total FROM document_requests WHERE DATE(request_date) BETWEEN '$from' AND '$to' GROUP BY document_type";
        if (brReportTableExists($conn,'complaints')) $parts[]="SELECT CONCAT('Complaint: ', COALESCE(NULLIF(complaint_subject,''), NULLIF(complaint_type,''),'Unspecified')) AS label, COUNT(*) AS total FROM complaints WHERE DATE(created_at) BETWEEN '$from' AND '$to' GROUP BY complaint_subject, complaint_type";
        if (brReportTableExists($conn,'requests')) $parts[]="SELECT CONCAT('Request: ', COALESCE(NULLIF(request_type,''),'Unspecified')) AS label, COUNT(*) AS total FROM requests WHERE DATE(request_date) BETWEEN '$from' AND '$to' GROUP BY request_type";
        if (brReportTableExists($conn,'emergencies')) $parts[]="SELECT CONCAT('Emergency: ', COALESCE(NULLIF(emergency_type,''),'Unspecified')) AS label, COUNT(*) AS total FROM emergencies WHERE DATE(created_at) BETWEEN '$from' AND '$to' GROUP BY emergency_type";
        if ($parts) $classifications=brReportQuery($conn,'SELECT label, SUM(total) AS total FROM ('.implode(' UNION ALL ',$parts).') AS all_types GROUP BY label ORDER BY total DESC LIMIT 12');
    } elseif ($type==='households' && brReportTableExists($conn,'household_members')) {
        $classifications=brReportQuery($conn,"SELECT CASE WHEN hm.age IS NULL OR hm.age < 0 THEN 'Age not specified' WHEN hm.age <= 12 THEN '0–12 years' WHEN hm.age <= 17 THEN '13–17 years' WHEN hm.age <= 24 THEN '18–24 years' WHEN hm.age <= 44 THEN '25–44 years' WHEN hm.age <= 59 THEN '45–59 years' ELSE '60 years and above' END AS label, COUNT(*) AS total FROM household_members hm WHERE DATE(hm.created_at) BETWEEN ? AND ? GROUP BY label ORDER BY MIN(COALESCE(hm.age,999)) ASC LIMIT 12",'ss',[$from,$to]);
    } elseif ($type==='documents' && brReportTableExists($conn,'document_requests')) {
        $classifications=brReportQuery($conn,"SELECT COALESCE(NULLIF(document_type,''),'Unspecified') AS label, COUNT(*) AS total FROM document_requests WHERE DATE(request_date) BETWEEN ? AND ? GROUP BY document_type ORDER BY total DESC LIMIT 12",'ss',[$from,$to]);
    } elseif ($type==='complaints' && brReportTableExists($conn,'complaints')) {
        $classifications=brReportQuery($conn,"SELECT COALESCE(NULLIF(complaint_subject,''),NULLIF(complaint_type,''),'Unspecified') AS label, COUNT(*) AS total FROM complaints WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY complaint_subject, complaint_type ORDER BY total DESC LIMIT 12",'ss',[$from,$to]);
    } elseif (($type==='vehicles' || $type==='resources') && brReportTableExists($conn,'requests')) {
        $extra = $type==='vehicles' ? " AND request_type='vehicle'" : " AND request_type IN ('equipment','facility')";
        $classifications=brReportQuery($conn,"SELECT COALESCE(NULLIF(request_type,''),'Unspecified') AS label, COUNT(*) AS total FROM requests WHERE DATE(request_date) BETWEEN ? AND ?$extra GROUP BY request_type ORDER BY total DESC",'ss',[$from,$to]);
    } elseif ($type==='emergencies' && brReportTableExists($conn,'emergencies')) {
        $classifications=brReportQuery($conn,"SELECT COALESCE(NULLIF(emergency_type,''),'Unspecified') AS label, COUNT(*) AS total FROM emergencies WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY emergency_type ORDER BY total DESC",'ss',[$from,$to]);
    }
    if (brReportTableExists($conn,'emergencies')) $emergencyTypes=brReportQuery($conn,"SELECT COALESCE(NULLIF(emergency_type,''),'Unspecified') AS label, COUNT(*) AS total FROM emergencies WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY emergency_type ORDER BY total DESC",'ss',[$from,$to]);
    if (brReportTableExists($conn,'requests')) $serviceTypes=brReportQuery($conn,"SELECT COALESCE(NULLIF(request_type,''),'Unspecified') AS label, COUNT(*) AS total FROM requests WHERE DATE(request_date) BETWEEN ? AND ? GROUP BY request_type ORDER BY total DESC",'ss',[$from,$to]);

    $configType = in_array($type,['overview','demand'],true)?'complaints':$type;
    $config = brReportRowsConfig($conn,$configType,$from,$to); $columns=$config['columns']; $rows=$config['rows'];
    if ($type === 'demand') {
        $config['title'] = 'Most Requested Services';
        $columns = [['key'=>'label','label'=>'Service / Request Category'],['key'=>'total','label'=>'Number of Requests']];
        $rows = $classifications;
    }
    $emergencyPoints = [];
    if (brReportTableExists($conn,'emergencies')) {
        $emergencyPoints = brReportQuery($conn, "SELECT latitude AS lat, longitude AS lng, emergency_type AS type, created_at FROM emergencies WHERE DATE(created_at) BETWEEN ? AND ? AND latitude IS NOT NULL AND longitude IS NOT NULL AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180 ORDER BY created_at DESC LIMIT 5000", 'ss', [$from,$to]);
    }
    echo json_encode(['success'=>true,'stats'=>$stats,'monthly'=>$monthly,'classifications'=>$classifications,'emergency_types'=>$emergencyTypes,'service_types'=>$serviceTypes,'emergency_points'=>$emergencyPoints,'columns'=>$columns,'rows'=>$rows,'from'=>$from,'to'=>$to]); exit;
}
if ($action === 'export_excel' || $action === 'export_csv') {
    $configType = in_array($type,['overview','demand'],true)?'complaints':$type;
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
        echo '<table><tr><td class="title" colspan="'.max(count($config['columns']),1).'">BRITE — '.brReportExcelCell($config['title'] ?? 'Captain Report').'</td></tr>';
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
