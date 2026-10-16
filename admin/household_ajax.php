<?php
// household_ajax.php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

switch ($action) {
    case 'get_households':              getHouseholds($conn); break;
    case 'get_household':               getHousehold($conn); break;
    case 'get_household_with_members':  getHouseholdWithMembers($conn); break;
    case 'get_household_members':       getHouseholdMembers($conn); break;
    case 'add_household':               addHousehold($conn); break;
    case 'edit_household':              editHousehold($conn); break;
    case 'delete_household':            deleteHousehold($conn); break;
    case 'add_member':                  addMember($conn); break;
    case 'edit_member':                 editMember($conn); break;
    case 'delete_member':               deleteMember($conn); break;
    case 'get_puroks':                  getPuroks($conn); break;
    case 'get_household_counts':        getHouseholdCounts($conn); break;
    case 'get_member':                  getMember($conn); break;
    case 'get_resident_analytics':      getResidentAnalytics($conn); break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

// ============ GETTERS ============

function getHouseholds($conn) {
    $purok = isset($_GET['purok']) ? mysqli_real_escape_string($conn, $_GET['purok']) : null;
    $search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : null;
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $perPage = isset($_GET['per_page']) ? intval($_GET['per_page']) : 50;
    $offset = ($page - 1) * $perPage;

    // acc_id lives on household_members — no join needed.
    $accountSelect = "m.acc_id AS resident_id";

    $sql = "SELECT 
                h.id as household_id,
                h.purok,
                h.address as household_address,
                h.no_of_members,
                m.id as member_id,
                m.last_name,
                m.first_name,
                m.middle_name,
                m.ext,
                m.place_of_birth,
                m.date_of_birth,
                m.age,
                m.sex,
                m.civil_status,
                m.citizenship,
                m.occupation,
                m.employment_status,
                m.email,
                m.phone_number,
                $accountSelect
            FROM households h
            LEFT JOIN household_members m ON h.id = m.household_id
            WHERE 1=1";

    $countSql = "SELECT COUNT(DISTINCT h.id) as total FROM households h";
    $where = [];

    if ($purok && $purok !== 'all') {
        $where[] = "h.purok = '$purok'";
    }
    if ($search) {
        $where[] = "(h.address LIKE '%$search%' OR h.purok LIKE '%$search%' OR 
                    m.last_name LIKE '%$search%' OR m.first_name LIKE '%$search%')";
    }

    if (!empty($where)) {
        $sql .= " AND " . implode(" AND ", $where);
        $countSql .= " WHERE " . implode(" AND ", $where);
    }

    $sql .= " ORDER BY h.purok, h.address, m.id ASC LIMIT $offset, $perPage";

    $result = mysqli_query($conn, $sql);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row['resident_id'] = !empty($row['resident_id']) ? intval($row['resident_id']) : 0;
        $row['has_account'] = $row['resident_id'] > 0 ? 1 : 0;
        $rows[] = $row;
    }

    $countResult = mysqli_query($conn, $countSql);
    $total = $countResult ? mysqli_fetch_assoc($countResult)['total'] : 0;
    $totalPages = ceil($total / $perPage);

    $purokCounts = [];
    $purokSql = "SELECT purok, COUNT(*) as count FROM households GROUP BY purok";
    $purokResult = mysqli_query($conn, $purokSql);
    while ($row = mysqli_fetch_assoc($purokResult)) {
        $purokCounts[$row['purok']] = $row['count'];
    }

    echo json_encode([
        'success' => true,
        'data' => $rows,
        'total' => $total,
        'totalPages' => $totalPages,
        'currentPage' => $page,
        'perPage' => $perPage,
        'purokCounts' => $purokCounts
    ]);
}

function getHousehold($conn) {
    $id = intval($_GET['id']);
    $sql = "SELECT * FROM households WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $household = mysqli_fetch_assoc($result);
    echo json_encode(['success' => true, 'data' => $household]);
}

function getHouseholdWithMembers($conn) {
    $id = intval($_GET['household_id']);

    $sql = "SELECT * FROM households WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $household = mysqli_fetch_assoc($res);

    if (!$household) {
        echo json_encode(['success' => false, 'message' => 'Household not found']);
        return;
    }

    $msql = "SELECT * FROM household_members WHERE household_id = ? ORDER BY id ASC";
    $mstmt = mysqli_prepare($conn, $msql);
    mysqli_stmt_bind_param($mstmt, "i", $id);
    mysqli_stmt_execute($mstmt);
    $mres = mysqli_stmt_get_result($mstmt);
    $members = [];
    while ($row = mysqli_fetch_assoc($mres)) {
        $members[] = $row;
    }

    echo json_encode([
        'success' => true,
        'household' => $household,
        'members' => $members
    ]);
}

function getMember($conn) {
    $id = intval($_GET['id']);
    $sql = "SELECT * FROM household_members WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $member = mysqli_fetch_assoc($result);
    echo json_encode(['success' => true, 'data' => $member]);
}

function getHouseholdMembers($conn) {
    $householdId = intval($_GET['household_id']);
    $sql = "SELECT * FROM household_members WHERE household_id = ? ORDER BY last_name ASC, first_name ASC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $householdId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $members = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $members[] = $row;
    }
    echo json_encode(['success' => true, 'data' => $members]);
}

function getPuroks($conn) {
    $sql = "SELECT DISTINCT purok FROM households ORDER BY purok ASC";
    $result = mysqli_query($conn, $sql);
    $puroks = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $puroks[] = $row['purok'];
    }
    echo json_encode(['success' => true, 'data' => $puroks]);
}

function getHouseholdCounts($conn) {
    $sql = "SELECT purok, COUNT(*) as count FROM households GROUP BY purok";
    $result = mysqli_query($conn, $sql);
    $counts = [];
    $totalMembers = 0;
    while ($row = mysqli_fetch_assoc($result)) {
        $counts[$row['purok']] = $row['count'];
    }

    $memberSql = "SELECT COUNT(*) as total FROM household_members";
    $memberResult = mysqli_query($conn, $memberSql);
    if ($memberResult) {
        $totalMembers = mysqli_fetch_assoc($memberResult)['total'];
    }

    echo json_encode([
        'success' => true,
        'data' => $counts,
        'totalHouseholds' => array_sum($counts),
        'totalMembers' => $totalMembers
    ]);
}

// ============ RESIDENT ANALYTICS ============

function getResidentAnalytics($conn) {
    $memberId = intval($_GET['member_id'] ?? 0);
    if ($memberId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid member id']);
        return;
    }

    // Look up the household member + its linked resident
    $sql = "SELECT m.*, h.purok, h.address AS household_address
            FROM household_members m
            LEFT JOIN households h ON h.id = m.household_id
            WHERE m.id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $memberId);
    mysqli_stmt_execute($stmt);
    $member = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$member) {
        echo json_encode(['success' => false, 'message' => 'Member not found']);
        return;
    }

    $residentId = intval($member['acc_id'] ?? 0);
    if ($residentId <= 0) {
        echo json_encode(['success' => false, 'message' => 'This member has no registered account yet.']);
        return;
    }

    // Fetch the resident account
    $rStmt = mysqli_prepare($conn, "SELECT id, username, email, phone_number, first_name, last_name, middle_name, date_of_birth, gender, address, is_active, is_verified, created_at, last_login FROM resident WHERE id = ?");
    mysqli_stmt_bind_param($rStmt, "i", $residentId);
    mysqli_stmt_execute($rStmt);
    $resident = mysqli_fetch_assoc(mysqli_stmt_get_result($rStmt));

    // ============ DOCUMENT REQUESTS ============
    $docTotal = 0;
    $docByType = [];
    $docByStatus = [];
    $docStmt = mysqli_prepare($conn,
        "SELECT document_type, status, COUNT(*) AS cnt
         FROM document_requests
         WHERE resident_id = ?
         GROUP BY document_type, status");
    mysqli_stmt_bind_param($docStmt, "i", $residentId);
    mysqli_stmt_execute($docStmt);
    $docRes = mysqli_stmt_get_result($docStmt);
    while ($row = mysqli_fetch_assoc($docRes)) {
        $type = $row['document_type'] ?: 'Unknown';
        $cnt = intval($row['cnt']);
        $docTotal += $cnt;
        if (!isset($docByType[$type])) $docByType[$type] = 0;
        $docByType[$type] += $cnt;
        if (!isset($docByStatus[$row['status']])) $docByStatus[$row['status']] = 0;
        $docByStatus[$row['status']] += $cnt;
    }

    // ============ EQUIPMENT + FACILITY + VEHICLE REQUESTS (combined) ============
    // All three request types share the same `requests` table with a request_type column,
    // plus a dedicated detail table (equipment_requests / facility_requests / vehicle_requests).
    // We combine them into ONE grouped list with type prefixes so a single bar chart can show them.

    $eqTotal = 0;
    $eqByItem = [];        // e.g. [ "Equipment: Chair" => 3, "Facility: Court" => 1, "Vehicle: Van" => 2 ]
    $eqByStatus = [];      // e.g. [ "returned" => 3, "approved" => 1 ]

    // ---- 1) Equipment ----
    $eqStmt = mysqli_prepare($conn,
        "SELECT er.equipment_name AS item_name, r.status, COUNT(*) AS cnt
         FROM equipment_requests er
         JOIN requests r ON r.id = er.request_id
         WHERE r.resident_id = ?
         GROUP BY er.equipment_name, r.status");
    mysqli_stmt_bind_param($eqStmt, "i", $residentId);
    mysqli_stmt_execute($eqStmt);
    $eqRes = mysqli_stmt_get_result($eqStmt);
    while ($row = mysqli_fetch_assoc($eqRes)) {
        $item = $row['item_name'] ?: 'Unknown';
        $cnt = intval($row['cnt']);
        $eqTotal += $cnt;

        $key = 'Equipment: ' . $item;
        if (!isset($eqByItem[$key])) $eqByItem[$key] = 0;
        $eqByItem[$key] += $cnt;

        $st = $row['status'] ?: 'unknown';
        if (!isset($eqByStatus[$st])) $eqByStatus[$st] = 0;
        $eqByStatus[$st] += $cnt;
    }

    // ---- 2) Facilities ----
    $facStmt = mysqli_prepare($conn,
        "SELECT fr.facility_name AS item_name, r.status, COUNT(*) AS cnt
         FROM facility_requests fr
         JOIN requests r ON r.id = fr.request_id
         WHERE r.resident_id = ?
         GROUP BY fr.facility_name, r.status");
    mysqli_stmt_bind_param($facStmt, "i", $residentId);
    mysqli_stmt_execute($facStmt);
    $facRes = mysqli_stmt_get_result($facStmt);
    while ($row = mysqli_fetch_assoc($facRes)) {
        $item = $row['item_name'] ?: 'Unknown';
        $cnt = intval($row['cnt']);
        $eqTotal += $cnt;

        $key = 'Facility: ' . $item;
        if (!isset($eqByItem[$key])) $eqByItem[$key] = 0;
        $eqByItem[$key] += $cnt;

        $st = $row['status'] ?: 'unknown';
        if (!isset($eqByStatus[$st])) $eqByStatus[$st] = 0;
        $eqByStatus[$st] += $cnt;
    }

    // ---- 3) Vehicles ----
    $vehStmt = mysqli_prepare($conn,
        "SELECT vr.vehicle_name AS item_name, r.status, COUNT(*) AS cnt
         FROM vehicle_requests vr
         JOIN requests r ON r.id = vr.request_id
         WHERE r.resident_id = ?
         GROUP BY vr.vehicle_name, r.status");
    mysqli_stmt_bind_param($vehStmt, "i", $residentId);
    mysqli_stmt_execute($vehStmt);
    $vehRes = mysqli_stmt_get_result($vehStmt);
    while ($row = mysqli_fetch_assoc($vehRes)) {
        $item = $row['item_name'] ?: 'Unknown';
        $cnt = intval($row['cnt']);
        $eqTotal += $cnt;

        $key = 'Vehicle: ' . $item;
        if (!isset($eqByItem[$key])) $eqByItem[$key] = 0;
        $eqByItem[$key] += $cnt;

        $st = $row['status'] ?: 'unknown';
        if (!isset($eqByStatus[$st])) $eqByStatus[$st] = 0;
        $eqByStatus[$st] += $cnt;
    }

    // ============ COMPLAINTS — as Complainant & as Respondent ============
    $resolvedStatuses = [
        'settled', 'settled_pending', 'dismissed', 'released',
        'certificate_issued', 'unresolved', 'failed_conciliation_final',
        'amicable_repudiated', 'arbitration_repudiated'
    ];

    $classifyStatus = function($status) use ($resolvedStatuses) {
        $s = strtolower($status ?: '');
        if (in_array($s, $resolvedStatuses, true)) return 'resolved';
        if ($s === 'pending_review' || $s === 'pending_captain_action' || $s === 'pending_captain_review') return 'pending';
        return 'unresolved';
    };

    $resFullName = trim(($resident['first_name'] ?? '') . ' ' . ($resident['last_name'] ?? ''));

    // ----- As COMPLAINANT -----
    $filedBy = [];
    $filedByResolved = 0;
    $filedByUnresolved = 0;
    $filedByPending = 0;

    $fbSql = "SELECT id, reference_number, title, status, complaint_type, priority, created_at
              FROM complaints
              WHERE created_by = ?
              ORDER BY created_at DESC";
    $fbStmt = mysqli_prepare($conn, $fbSql);
    mysqli_stmt_bind_param($fbStmt, "i", $residentId);
    mysqli_stmt_execute($fbStmt);
    $fbRes = mysqli_stmt_get_result($fbStmt);
    while ($row = mysqli_fetch_assoc($fbRes)) {
        $cls = $classifyStatus($row['status']);
        if ($cls === 'resolved') $filedByResolved++;
        else if ($cls === 'pending') $filedByPending++;
        else $filedByUnresolved++;

        $row['_class'] = $cls;
        $row['_role']  = 'complainant';
        $filedBy[] = $row;
    }

    // ----- As RESPONDENT -----
    $filedAgainst = [];
    $filedAgainstResolved = 0;
    $filedAgainstUnresolved = 0;
    $filedAgainstPending = 0;

    if ($resFullName !== '') {
        $faSql = "SELECT c.id, c.reference_number, c.title, c.status, c.complaint_type, c.priority, c.created_at,
                         cp.full_name AS respondent_name
                  FROM complaints c
                  JOIN complaint_parties cp ON cp.complaint_id = c.id
                  WHERE cp.party_type = 'respondent'
                    AND LOWER(TRIM(cp.full_name)) = LOWER(?)
                  ORDER BY c.created_at DESC";
        $faStmt = mysqli_prepare($conn, $faSql);
        mysqli_stmt_bind_param($faStmt, "s", $resFullName);
        mysqli_stmt_execute($faStmt);
        $faRes = mysqli_stmt_get_result($faStmt);
        while ($row = mysqli_fetch_assoc($faRes)) {
            $cls = $classifyStatus($row['status']);
            if ($cls === 'resolved') $filedAgainstResolved++;
            else if ($cls === 'pending') $filedAgainstPending++;
            else $filedAgainstUnresolved++;

            $row['_class'] = $cls;
            $row['_role']  = 'respondent';
            $filedAgainst[] = $row;
        }
    }

    // Totals
    $filedByTotal = count($filedBy);
    $filedAgainstTotal = count($filedAgainst);
    $complaintTotal = $filedByTotal + $filedAgainstTotal;
    $complaintResolved = $filedByResolved + $filedAgainstResolved;
    $complaintUnresolved = $filedByUnresolved + $filedAgainstUnresolved;
    $complaintPending = $filedByPending + $filedAgainstPending;

    // Most recent complaint (either role)
    $allComplaints = array_merge($filedBy, $filedAgainst);
    usort($allComplaints, function($a, $b) {
        return strcmp($b['created_at'], $a['created_at']);
    });
    $mostRecentComplaint = !empty($allComplaints) ? $allComplaints[0] : null;

    // Sort combined item list by count (desc) for chart readability
    arsort($eqByItem);
    arsort($docByType);

    echo json_encode([
        'success' => true,
        'member'  => $member,
        'resident'=> $resident,
        'documents' => [
            'total'  => $docTotal,
            'byType' => $docByType,
            'byStatus' => $docByStatus
        ],
        'equipment' => [
            'total'  => $eqTotal,
            'byItem' => $eqByItem,
            'byStatus' => $eqByStatus
        ],
        'complaints' => [
            'total'             => $complaintTotal,
            'resolved'          => $complaintResolved,
            'unresolved'        => $complaintUnresolved,
            'pending'           => $complaintPending,
            'as_complainant'    => [
                'total'      => $filedByTotal,
                'resolved'   => $filedByResolved,
                'unresolved' => $filedByUnresolved,
                'pending'    => $filedByPending,
                'list'       => $filedBy
            ],
            'as_respondent'     => [
                'total'      => $filedAgainstTotal,
                'resolved'   => $filedAgainstResolved,
                'unresolved' => $filedAgainstUnresolved,
                'pending'    => $filedAgainstPending,
                'list'       => $filedAgainst
            ],
            'most_recent'       => $mostRecentComplaint
        ]
    ]);
}

// ============ HOUSEHOLD CRUD ============

function addHousehold($conn) {
    $purok = mysqli_real_escape_string($conn, $_POST['purok']);
    $address = mysqli_real_escape_string($conn, $_POST['address']);

    $sql = "INSERT INTO households (purok, address) VALUES (?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $purok, $address);

    if (mysqli_stmt_execute($stmt)) {
        $id = mysqli_insert_id($conn);
        echo json_encode(['success' => true, 'message' => 'Household added successfully', 'id' => $id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add household: ' . mysqli_error($conn)]);
    }
}

function editHousehold($conn) {
    $id = intval($_POST['id']);
    $purok = mysqli_real_escape_string($conn, $_POST['purok']);
    $address = mysqli_real_escape_string($conn, $_POST['address']);

    $sql = "UPDATE households SET purok = ?, address = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ssi", $purok, $address, $id);

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true, 'message' => 'Household updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update household']);
    }
}

function deleteHousehold($conn) {
    $id = intval($_POST['id']);

    $checkSql = "SELECT COUNT(*) as member_count FROM household_members WHERE household_id = ?";
    $checkStmt = mysqli_prepare($conn, $checkSql);
    mysqli_stmt_bind_param($checkStmt, "i", $id);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);
    $row = mysqli_fetch_assoc($checkResult);

    if ($row['member_count'] > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Cannot delete household with existing members. Please delete all members first.',
            'member_count' => $row['member_count']
        ]);
        return;
    }

    $sql = "DELETE FROM households WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true, 'message' => 'Household deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete household']);
    }
}

// ============ MEMBER CRUD ============

function addMember($conn) {
    $household_id = intval($_POST['household_id']);
    $last_name = mysqli_real_escape_string($conn, $_POST['last_name'] ?? '');
    $first_name = mysqli_real_escape_string($conn, $_POST['first_name'] ?? '');
    $middle_name = mysqli_real_escape_string($conn, $_POST['middle_name'] ?? '');
    $ext = mysqli_real_escape_string($conn, $_POST['ext'] ?? '');
    $place_of_birth = mysqli_real_escape_string($conn, $_POST['place_of_birth'] ?? '');
    $date_of_birth = mysqli_real_escape_string($conn, $_POST['date_of_birth'] ?? '');
    $age = intval($_POST['age'] ?? 0);
    $sex = mysqli_real_escape_string($conn, $_POST['sex'] ?? '');
    $civil_status = mysqli_real_escape_string($conn, $_POST['civil_status'] ?? '');
    $citizenship = mysqli_real_escape_string($conn, $_POST['citizenship'] ?? 'Filipino');
    $occupation = mysqli_real_escape_string($conn, $_POST['occupation'] ?? '');
    $employment_status = mysqli_real_escape_string($conn, $_POST['employment_status'] ?? '');
    $email = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
    $phone_number = mysqli_real_escape_string($conn, $_POST['phone_number'] ?? '');

    $sql = "INSERT INTO household_members (household_id, last_name, first_name, middle_name, ext, 
            place_of_birth, date_of_birth, age, sex, civil_status, citizenship, occupation, 
            employment_status, email, phone_number) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "issssssisssssss",
        $household_id, $last_name, $first_name, $middle_name, $ext,
        $place_of_birth, $date_of_birth, $age, $sex, $civil_status, $citizenship,
        $occupation, $employment_status, $email, $phone_number
    );

    if (mysqli_stmt_execute($stmt)) {
        updateHouseholdMemberCount($conn, $household_id);
        echo json_encode(['success' => true, 'message' => 'Member added successfully', 'id' => mysqli_insert_id($conn)]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add member: ' . mysqli_error($conn)]);
    }
}

function editMember($conn) {
    $id = intval($_POST['id']);
    $household_id = intval($_POST['household_id']);
    $last_name = mysqli_real_escape_string($conn, $_POST['last_name'] ?? '');
    $first_name = mysqli_real_escape_string($conn, $_POST['first_name'] ?? '');
    $middle_name = mysqli_real_escape_string($conn, $_POST['middle_name'] ?? '');
    $ext = mysqli_real_escape_string($conn, $_POST['ext'] ?? '');
    $place_of_birth = mysqli_real_escape_string($conn, $_POST['place_of_birth'] ?? '');
    $date_of_birth = mysqli_real_escape_string($conn, $_POST['date_of_birth'] ?? '');
    $age = intval($_POST['age'] ?? 0);
    $sex = mysqli_real_escape_string($conn, $_POST['sex'] ?? '');
    $civil_status = mysqli_real_escape_string($conn, $_POST['civil_status'] ?? '');
    $citizenship = mysqli_real_escape_string($conn, $_POST['citizenship'] ?? 'Filipino');
    $occupation = mysqli_real_escape_string($conn, $_POST['occupation'] ?? '');
    $employment_status = mysqli_real_escape_string($conn, $_POST['employment_status'] ?? '');
    $email = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
    $phone_number = mysqli_real_escape_string($conn, $_POST['phone_number'] ?? '');

    $sql = "UPDATE household_members SET 
            last_name = ?, first_name = ?, middle_name = ?, ext = ?,
            place_of_birth = ?, date_of_birth = ?, age = ?, sex = ?, 
            civil_status = ?, citizenship = ?, occupation = ?, 
            employment_status = ?, email = ?, phone_number = ?
            WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "sssssssissssssi",
        $last_name, $first_name, $middle_name, $ext,
        $place_of_birth, $date_of_birth, $age, $sex, $civil_status, $citizenship,
        $occupation, $employment_status, $email, $phone_number, $id
    );

    if (mysqli_stmt_execute($stmt)) {
        updateHouseholdMemberCount($conn, $household_id);
        echo json_encode(['success' => true, 'message' => 'Member updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update member']);
    }
}

function deleteMember($conn) {
    $id = intval($_POST['id']);

    $sql = "SELECT household_id FROM household_members WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    $household_id = $row ? $row['household_id'] : 0;

    $sql = "DELETE FROM household_members WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);

    if (mysqli_stmt_execute($stmt)) {
        if ($household_id) updateHouseholdMemberCount($conn, $household_id);
        echo json_encode(['success' => true, 'message' => 'Member deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete member']);
    }
}

// ============ HELPERS ============

function updateHouseholdMemberCount($conn, $household_id) {
    $sql = "UPDATE households SET no_of_members = (SELECT COUNT(*) FROM household_members WHERE household_id = ?) WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $household_id, $household_id);
    mysqli_stmt_execute($stmt);
}
?>