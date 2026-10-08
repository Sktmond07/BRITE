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
    case 'get_households':
        getHouseholds($conn);
        break;
    case 'get_household':
        getHousehold($conn);
        break;
    case 'get_household_members':
        getHouseholdMembers($conn);
        break;
    case 'add_household':
        addHousehold($conn);
        break;
    case 'edit_household':
        editHousehold($conn);
        break;
    case 'delete_household':
        deleteHousehold($conn);
        break;
    case 'add_member':
        addMember($conn);
        break;
    case 'edit_member':
        editMember($conn);
        break;
    case 'delete_member':
        deleteMember($conn);
        break;
    case 'get_puroks':
        getPuroks($conn);
        break;
    case 'get_household_counts':
        getHouseholdCounts($conn);
        break;
    case 'get_member':
        getMember($conn);
        break;
    case 'save_all':
        saveAll($conn);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function getHouseholds($conn) {
    $purok = isset($_GET['purok']) ? mysqli_real_escape_string($conn, $_GET['purok']) : null;
    $search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : null;
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $perPage = isset($_GET['per_page']) ? intval($_GET['per_page']) : 50;
    $offset = ($page - 1) * $perPage;
    
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
                m.employment_status
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
    
    // Check if household has members
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
    
    $sql = "INSERT INTO household_members (household_id, last_name, first_name, middle_name, ext, 
            place_of_birth, date_of_birth, age, sex, civil_status, citizenship, occupation, 
            employment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "issssssisssss", 
        $household_id, $last_name, $first_name, $middle_name, $ext,
        $place_of_birth, $date_of_birth, $age, $sex, $civil_status, $citizenship,
        $occupation, $employment_status
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
    
    $sql = "UPDATE household_members SET 
            last_name = ?, first_name = ?, middle_name = ?, ext = ?,
            place_of_birth = ?, date_of_birth = ?, age = ?, sex = ?, 
            civil_status = ?, citizenship = ?, occupation = ?, 
            employment_status = ? 
            WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "sssssssissssi", 
        $last_name, $first_name, $middle_name, $ext,
        $place_of_birth, $date_of_birth, $age, $sex, $civil_status, $citizenship,
        $occupation, $employment_status, $id
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
    // Get household_id first
    $sql = "SELECT household_id FROM household_members WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    $household_id = $row['household_id'];
    
    $sql = "DELETE FROM household_members WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    
    if (mysqli_stmt_execute($stmt)) {
        updateHouseholdMemberCount($conn, $household_id);
        echo json_encode(['success' => true, 'message' => 'Member deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete member']);
    }
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

function updateHouseholdMemberCount($conn, $household_id) {
    $sql = "UPDATE households SET no_of_members = (SELECT COUNT(*) FROM household_members WHERE household_id = ?) WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $household_id, $household_id);
    mysqli_stmt_execute($stmt);
}

function saveAll($conn) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !isset($data['rows'])) {
        echo json_encode(['success' => false, 'message' => 'No data to save']);
        return;
    }
    
    $errors = [];
    $success = 0;
    $newHouseholdIds = [];
    
    foreach ($data['rows'] as $row) {
        // Check if this is a new household
        if (!isset($row['household_id']) || !$row['household_id']) {
            // New household - allow duplicate addresses (multiple families)
            $purok = mysqli_real_escape_string($conn, $row['purok'] ?? '');
            $address = mysqli_real_escape_string($conn, $row['household_address'] ?? '');
            
            if (!$purok || !$address) continue;
            
            // Create new household even if address exists (multiple families)
            $sql = "INSERT INTO households (purok, address) VALUES ('$purok', '$address')";
            if (mysqli_query($conn, $sql)) {
                $household_id = mysqli_insert_id($conn);
                $success++;
                $newHouseholdIds[] = $household_id;
                
                // If there's a last_name for this row, add as first member
                if (!empty($row['last_name'])) {
                    $last_name = mysqli_real_escape_string($conn, $row['last_name']);
                    $first_name = mysqli_real_escape_string($conn, $row['first_name'] ?? '');
                    $middle_name = mysqli_real_escape_string($conn, $row['middle_name'] ?? '');
                    $ext = mysqli_real_escape_string($conn, $row['ext'] ?? '');
                    $place_of_birth = mysqli_real_escape_string($conn, $row['place_of_birth'] ?? '');
                    $date_of_birth = mysqli_real_escape_string($conn, $row['date_of_birth'] ?? '');
                    $age = intval($row['age'] ?? 0);
                    $sex = mysqli_real_escape_string($conn, $row['sex'] ?? '');
                    $civil_status = mysqli_real_escape_string($conn, $row['civil_status'] ?? '');
                    $citizenship = mysqli_real_escape_string($conn, $row['citizenship'] ?? 'Filipino');
                    $occupation = mysqli_real_escape_string($conn, $row['occupation'] ?? '');
                    $employment_status = mysqli_real_escape_string($conn, $row['employment_status'] ?? '');
                    
                    $sql2 = "INSERT INTO household_members (household_id, last_name, first_name, middle_name, ext, 
                            place_of_birth, date_of_birth, age, sex, civil_status, citizenship, occupation, 
                            employment_status) VALUES ($household_id, '$last_name', '$first_name', '$middle_name', 
                            '$ext', '$place_of_birth', '$date_of_birth', $age, '$sex', '$civil_status', '$citizenship',
                            '$occupation', '$employment_status')";
                    
                    if (mysqli_query($conn, $sql2)) {
                        $success++;
                    } else {
                        $errors[] = mysqli_error($conn);
                    }
                }
            } else {
                $errors[] = mysqli_error($conn);
            }
            continue;
        }
        
        $household_id = intval($row['household_id']);
        $member_id = isset($row['member_id']) ? intval($row['member_id']) : 0;
        $last_name = mysqli_real_escape_string($conn, $row['last_name'] ?? '');
        $first_name = mysqli_real_escape_string($conn, $row['first_name'] ?? '');
        $middle_name = mysqli_real_escape_string($conn, $row['middle_name'] ?? '');
        $ext = mysqli_real_escape_string($conn, $row['ext'] ?? '');
        $place_of_birth = mysqli_real_escape_string($conn, $row['place_of_birth'] ?? '');
        $date_of_birth = mysqli_real_escape_string($conn, $row['date_of_birth'] ?? '');
        $age = intval($row['age'] ?? 0);
        $sex = mysqli_real_escape_string($conn, $row['sex'] ?? '');
        $civil_status = mysqli_real_escape_string($conn, $row['civil_status'] ?? '');
        $citizenship = mysqli_real_escape_string($conn, $row['citizenship'] ?? 'Filipino');
        $occupation = mysqli_real_escape_string($conn, $row['occupation'] ?? '');
        $employment_status = mysqli_real_escape_string($conn, $row['employment_status'] ?? '');
        $deleted = isset($row['_deleted']) && $row['_deleted'] == true;
        
        // Check if this household's purok/address needs updating
        if (isset($row['purok']) && isset($row['household_address'])) {
            $newPurok = mysqli_real_escape_string($conn, $row['purok']);
            $newAddress = mysqli_real_escape_string($conn, $row['household_address']);
            $updateSql = "UPDATE households SET purok = '$newPurok', address = '$newAddress' WHERE id = $household_id";
            mysqli_query($conn, $updateSql);
        }
        
        if ($deleted && $member_id > 0) {
            $sql = "DELETE FROM household_members WHERE id = $member_id";
            if (mysqli_query($conn, $sql)) {
                $success++;
                updateHouseholdMemberCount($conn, $household_id);
            }
            continue;
        }
        
        if ($member_id > 0) {
            // Update existing member
            $sql = "UPDATE household_members SET 
                    last_name = '$last_name', first_name = '$first_name', middle_name = '$middle_name', 
                    ext = '$ext', place_of_birth = '$place_of_birth', date_of_birth = '$date_of_birth', 
                    age = $age, sex = '$sex', civil_status = '$civil_status', citizenship = '$citizenship',
                    occupation = '$occupation', employment_status = '$employment_status'
                    WHERE id = $member_id";
            
            if (mysqli_query($conn, $sql)) {
                $success++;
                updateHouseholdMemberCount($conn, $household_id);
            } else {
                $errors[] = mysqli_error($conn);
            }
        } else if ($last_name) {
            // New member
            $sql = "INSERT INTO household_members (household_id, last_name, first_name, middle_name, ext, 
                    place_of_birth, date_of_birth, age, sex, civil_status, citizenship, occupation, 
                    employment_status) VALUES ($household_id, '$last_name', '$first_name', '$middle_name', 
                    '$ext', '$place_of_birth', '$date_of_birth', $age, '$sex', '$civil_status', '$citizenship',
                    '$occupation', '$employment_status')";
            
            if (mysqli_query($conn, $sql)) {
                $success++;
                updateHouseholdMemberCount($conn, $household_id);
            } else {
                $errors[] = mysqli_error($conn);
            }
        }
    }
    
    // Update all household member counts
    if (!empty($newHouseholdIds)) {
        foreach ($newHouseholdIds as $hid) {
            updateHouseholdMemberCount($conn, $hid);
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Saved $success records successfully" . (count($errors) > 0 ? " with " . count($errors) . " errors" : ""),
        'errors' => $errors
    ]);
}
?>