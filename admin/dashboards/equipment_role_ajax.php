<?php
// equipment_role_ajax.php
// Shared equipment AJAX endpoint for Captain + Secretary dashboards.
// - Reads admin_role from the `admin` table.
// - Captain:   full access (mirror of equipment_ajax.php) + status override w/ reason.
// - Secretary: view + individual item status edit + booking handling.
//              No add / no delete / no quantity add/deduct / no undo / no edit-info.
// - Reuses the same DB tables as the admin module.
// - Notifies all secretaries when the Captain overturns an item status.

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../equipment_functions.php';

header('Content-Type: application/json');

// ── Resolve current role ─────────────────────────────────────
$admin_id = (int)$_SESSION['user_id'];
$roleStmt = mysqli_prepare($conn, "SELECT admin_role, full_name FROM admin WHERE id = ?");
mysqli_stmt_bind_param($roleStmt, "i", $admin_id);
mysqli_stmt_execute($roleStmt);
$roleRow = mysqli_fetch_assoc(mysqli_stmt_get_result($roleStmt));
mysqli_stmt_close($roleStmt);

$currentRole = $roleRow['admin_role'] ?? '';
$currentName = $roleRow['full_name'] ?? '';

if (!in_array($currentRole, ['captain', 'secretary'], true)) {
    echo json_encode(['success' => false, 'message' => 'Role not permitted for this endpoint']);
    exit;
}

$isCaptain   = ($currentRole === 'captain');
$isSecretary = ($currentRole === 'secretary');

// ── Helpers ──────────────────────────────────────────────────
function deny($msg = 'You do not have permission to perform this action.') {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

/**
 * Insert a notification for every secretary.
 */
function notifySecretaries($conn, $title, $message, $refType = 'equipment', $refId = 0) {
    $sql = "SELECT id FROM admin WHERE admin_role = 'secretary' AND is_active = 1";
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        $res = mysqli_query($conn, "SELECT id FROM admin WHERE admin_role = 'secretary'");
        if (!$res) return;
    }
    while ($row = mysqli_fetch_assoc($res)) {
        $rid = (int)$row['id'];
        $stmt = mysqli_prepare($conn,
            "INSERT INTO notifications
                (user_type, user_id, resident_id, type, title, message,
                 reference_id, reference_type, document_path, is_read, created_at, read_at)
             VALUES
                ('admin', ?, NULL, 'warning', ?, ?, ?, ?, NULL, 0, NOW(), NULL)");
        if ($stmt) {
            $refIdInt = (int)$refId;
            mysqli_stmt_bind_param($stmt, "issis", $rid, $title, $message, $refIdInt, $refType);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
}

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

// ============================================================
// ACTION ROUTER
// ============================================================
switch ($action) {

    // ── Validation endpoints (both roles) ────────────────────
    case 'check_equipment_exists':
        $name = $_GET['name'] ?? '';
        $excludeId = isset($_GET['exclude_id']) ? intval($_GET['exclude_id']) : null;
        $exists = checkEquipmentExists($conn, $name, $excludeId);
        echo json_encode(['success' => true, 'exists' => $exists]);
        break;

    case 'check_vehicle_exists':
        $name = $_GET['name'] ?? '';
        $plate = $_GET['plate_number'] ?? '';
        $excludeId = isset($_GET['exclude_id']) ? intval($_GET['exclude_id']) : null;
        $exists = checkVehicleExists($conn, $name, $plate, $excludeId);
        echo json_encode(['success' => true, 'exists' => $exists]);
        break;

    case 'check_facility_exists':
        $name = $_GET['name'] ?? '';
        $excludeId = isset($_GET['exclude_id']) ? intval($_GET['exclude_id']) : null;
        $exists = checkFacilityExists($conn, $name, $excludeId);
        echo json_encode(['success' => true, 'exists' => $exists, 'warning' => $exists]);
        break;

    // ── Read endpoints (both roles) ──────────────────────────
    case 'get_predefined_items':
        $category = $_GET['category'] ?? '';
        echo json_encode(['success' => true, 'data' => getPredefinedItems($conn, $category)]);
        break;

    case 'get_equipment':
        $category = $_GET['category'] ?? null;
        echo json_encode(['success' => true, 'data' => getAllEquipmentTypesWithCounts($conn, $category)]);
        break;

    case 'get_item_by_global_id':
        $globalId = $_GET['global_id'] ?? '';
        if ($globalId) {
            $item = getItemByGlobalId($conn, $globalId);
            if ($item) {
                $item['global_id'] = $globalId;
                echo json_encode(['success' => true, 'data' => $item]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Item not found']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid global ID']);
        }
        break;

    case 'get_equipment_by_id':
        $id = intval($_GET['id'] ?? 0);
        $type = $_GET['type'] ?? 'equipment';
        if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ID']); break; }
        if ($type === 'equipment') $equipment = getEquipmentTypeById($conn, $id);
        elseif ($type === 'facility') $equipment = getFacilityById($conn, $id);
        else $equipment = getVehicleById($conn, $id);
        if ($equipment) echo json_encode(['success' => true, 'data' => $equipment]);
        else echo json_encode(['success' => false, 'message' => 'Item not found']);
        break;

    case 'get_equipment_items':
        $typeId = intval($_GET['type_id'] ?? 0);
        echo json_encode(['success' => true, 'data' => getEquipmentItems($conn, $typeId)]);
        break;

    case 'get_equipment_bookings':
        $itemId = intval($_GET['id'] ?? 0);
        $type = $_GET['type'] ?? 'equipment';
        if ($itemId > 0) {
            echo json_encode(['success' => true, 'bookings' => getEquipmentBookings($conn, $itemId, $type)]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid item ID']);
        }
        break;

    case 'get_all_bookings':
        $status = (isset($_GET['status']) && $_GET['status'] !== 'all') ? $_GET['status'] : null;
        $type   = (isset($_GET['type'])   && $_GET['type']   !== 'all') ? $_GET['type']   : null;
        // "unreturned" is a REAL value in the requests.status enum — passed straight through to SQL
        echo json_encode(['success' => true, 'data' => getAllBookings($conn, $status, $type, 1, 9999)]);
        break;

case 'get_bookings':
    $status = (isset($_GET['status']) && $_GET['status'] !== 'all') ? $_GET['status'] : null;
    $type   = (isset($_GET['type'])   && $_GET['type']   !== 'all') ? $_GET['type']   : null;
    $page   = intval($_GET['page'] ?? 1);
    echo json_encode(['success' => true, 'data' => getAllBookings($conn, $status, $type, $page)]);
    break;

    case 'get_booking_by_id':
        $id = intval($_GET['id'] ?? 0);
        if ($id > 0) {
            $booking = getBookingById($conn, $id);
            if ($booking) echo json_encode(['success' => true, 'data' => $booking]);
            else echo json_encode(['success' => false, 'message' => 'Booking not found']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        }
        break;

    case 'get_counts':
        echo json_encode([
            'success' => true,
            'counts'  => getEquipmentCounts($conn),
            'pending_bookings' => getPendingBookingsCount($conn)
        ]);
        break;

    case 'get_recent_bookings':
        $limit = intval($_GET['limit'] ?? 5);
        echo json_encode(['success' => true, 'bookings' => getRecentBookings($conn, $limit)]);
        break;

    case 'get_equipment_status':
        $equipmentId = intval($_GET['equipment_id'] ?? 0);
        if ($equipmentId > 0) {
            echo json_encode(['success' => true, 'data' => getEquipmentStatusQuantities($conn, $equipmentId)]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid equipment ID']);
        }
        break;

    case 'get_equipment_status_quantities':
        $equipmentId = intval($_GET['equipment_id'] ?? 0);
        if ($equipmentId > 0) {
            echo json_encode(['success' => true, 'data' => getEquipmentStatusQuantities($conn, $equipmentId)]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid equipment ID']);
        }
        break;

    case 'get_change_logs':
        $equipmentId = intval($_GET['equipment_id'] ?? 0);
        if ($equipmentId > 0) {
            echo json_encode(['success' => true, 'data' => getChangeLogs($conn, $equipmentId)]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid equipment ID']);
        }
        break;

    // ── Booking action (both roles) ──────────────────────────
   case 'update_booking_status':
    $id          = intval($_POST['id'] ?? 0);
    $status      = $_POST['status'] ?? '';
    $admin_notes = $_POST['admin_notes'] ?? null;
    $actionDate  = trim($_POST['action_date'] ?? '');
    $actionTime  = trim($_POST['action_time'] ?? '');

    if ($id <= 0 || !$status) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        break;
    }

    // Build datetime for the picked date/time
    $pickedDatetime = null;
    if ($actionDate) {
        $time = $actionTime ?: date('H:i');
        // validate
        $dt = DateTime::createFromFormat('Y-m-d H:i', $actionDate . ' ' . $time);
        if ($dt && $dt->format('Y-m-d') === $actionDate) {
            $pickedDatetime = $dt->format('Y-m-d H:i:s');
        }
    }

    // Run base update
    $base = updateBookingStatus($conn, $id, $status, $admin_notes);

    if (!empty($base['success'])) {
        // Map status → status_dates key + native column name
        $map = [
            'approved'  => ['key' => 'approved_at',  'col' => 'approved_at'],
            'rejected'  => ['key' => 'rejected_at',  'col' => 'rejected_at'],
            'borrowed'  => ['key' => 'borrowed_at',  'col' => 'borrowed_at'],
            'returned'  => ['key' => 'returned_at',  'col' => 'completed_at'],
            'completed' => ['key' => 'completed_at', 'col' => 'completed_at'],
            'cancelled' => ['key' => 'cancelled_at', 'col' => 'cancelled_at'],
        ];

        if (isset($map[$status])) {
            $key = $map[$status]['key'];
            $col = $map[$status]['col'];
            $ts  = $pickedDatetime ?: date('Y-m-d H:i:s');

            // 1) merge into custom_data JSON
            $row = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT custom_data FROM requests WHERE id = " . (int)$id));
            $json = [];
            if ($row && !empty($row['custom_data'])) {
                $decoded = json_decode($row['custom_data'], true);
                if (is_array($decoded)) $json = $decoded;
            }
            if (!isset($json['status_dates']) || !is_array($json['status_dates'])) {
                $json['status_dates'] = [];
            }
            $json['status_dates'][$key] = $ts;
            if (!empty($admin_notes)) {
                $json['status_dates'][$key . '_notes'] = $admin_notes;
            }

            $jsonEsc = mysqli_real_escape_string($conn, json_encode($json));
            $colEsc  = preg_replace('/[^a-z_]/', '', $col); // whitelist
            mysqli_query($conn,
                "UPDATE requests
                    SET custom_data = '{$jsonEsc}',
                        {$colEsc}   = '" . mysqli_real_escape_string($conn, $ts) . "'
                  WHERE id = " . (int)$id);
        }
    }

    echo json_encode($base);
    break;

    // ============================================================
    // SECRETARY-ONLY ACTIONS
    // ============================================================

    // Update a single item's status + condition notes
    case 'update_item_status':
        if (!$isSecretary && !$isCaptain) deny();
        $id      = intval($_POST['id'] ?? 0);
        $status  = $_POST['status'] ?? '';
        $notes   = $_POST['condition_notes'] ?? '';
        if ($id <= 0 || !$status) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            break;
        }

        $stmt = mysqli_prepare($conn,
            "UPDATE equipment_items SET status = ?, condition_notes = ?, updated_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "ssi", $status, $notes, $id);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if ($ok && $isCaptain) {
            notifySecretaries(
                $conn,
                'Equipment status changed by Captain',
                "Captain {$currentName} changed item #{$id} status to '" . strtoupper($status) . "'.",
                'equipment',
                $id
            );
        }

        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Item status updated successfully!' : 'Failed to update item']);
        break;

    // ============================================================
    // CAPTAIN-ONLY ACTIONS (mirror of admin equipment_ajax.php)
    // ============================================================

    case 'add_equipment':
        if (!$isCaptain) deny();
        $imagePath = null;
        if (isset($_FILES['equipment_image']) && $_FILES['equipment_image']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = uploadEquipmentImage($_FILES['equipment_image']);
            if ($uploadResult['success']) $imagePath = $uploadResult['path'];
            else { echo json_encode(['success' => false, 'message' => $uploadResult['message']]); exit; }
        }
        $category = $_POST['category'];
        $data = [
            'name' => $_POST['name'],
            'description' => $_POST['description'] ?? '',
            'category' => $category,
            'total_quantity' => intval($_POST['total_quantity'] ?? 1),
            'location' => $_POST['location'] ?? '',
            'capacity' => $_POST['capacity'] ?? 0,
            'plate_number' => $_POST['plate_number'] ?? '',
            'vehicle_type' => $_POST['vehicle_type'] ?? 'Other'
        ];
        if ($category === 'Equipment') $result = addEquipmentItem($conn, $data, $imagePath);
        elseif ($category === 'Facility') $result = addFacilityItem($conn, $data, $imagePath);
        else $result = addVehicleItem($conn, $data, $imagePath);
        echo json_encode($result);
        break;

    case 'update_equipment':
        if (!$isCaptain) deny();
        $globalId = $_POST['id'] ?? '';
        $type     = $_POST['type'] ?? 'equipment';
        $id       = intval(preg_replace('/[^0-9]/', '', $globalId));

        $imagePath = null;
        if (isset($_FILES['equipment_image']) && $_FILES['equipment_image']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = uploadEquipmentImage($_FILES['equipment_image']);
            if ($uploadResult['success']) $imagePath = $uploadResult['path'];
            else { echo json_encode(['success' => false, 'message' => $uploadResult['message']]); exit; }
        }
        $removeImage = (isset($_POST['remove_image']) && $_POST['remove_image'] === 'true');
        if ($removeImage) $imagePath = '';

        $data = [
            'name' => $_POST['name'],
            'description' => $_POST['description'] ?? '',
            'category' => $_POST['category'] ?? 'Equipment',
            'total_quantity' => intval($_POST['total_quantity'] ?? 1),
            'status' => $_POST['status'] ?? 'active',
            'location' => $_POST['location'] ?? '',
            'capacity' => $_POST['capacity'] ?? 0,
            'plate_number' => $_POST['plate_number'] ?? '',
            'vehicle_type' => $_POST['vehicle_type'] ?? 'Other'
        ];
        if ($type === 'equipment') $result = updateEquipmentItem($conn, $id, $data, $imagePath);
        elseif ($type === 'facility') $result = updateFacilityItem($conn, $id, $data, $imagePath);
        else $result = updateVehicleItem($conn, $id, $data, $imagePath);
        echo json_encode($result);
        break;

    case 'delete_equipment':
        if (!$isCaptain) deny();
        $id   = intval($_POST['id'] ?? 0);
        $type = $_POST['type'] ?? 'equipment';
        if ($id > 0) echo json_encode(deleteItem($conn, $id, $type));
        else echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        break;

    case 'delete_equipment_item':
        if (!$isCaptain) deny();
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) echo json_encode(deleteEquipmentItem($conn, $id));
        else echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        break;

    case 'update_equipment_status':
        if (!$isCaptain) deny();
        $equipmentId = intval($_POST['equipment_id'] ?? 0);
        $available   = intval($_POST['available'] ?? 0);
        $maintenance = intval($_POST['maintenance'] ?? 0);
        $lost        = intval($_POST['lost'] ?? 0);
        $reason      = $_POST['reason'] ?? 'Status override by Captain';
        if ($equipmentId > 0) {
            $result = updateEquipmentStatusOnly($conn, $equipmentId, $available, $maintenance, $lost);
            if (!empty($result['success'])) {
                notifySecretaries(
                    $conn,
                    'Equipment status overridden by Captain',
                    "Captain {$currentName} overrode the status distribution of equipment #{$equipmentId}. Reason: {$reason}",
                    'equipment',
                    $equipmentId
                );
            }
            echo json_encode($result);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid equipment ID']);
        }
        break;

    case 'update_equipment_quantity':
        if (!$isCaptain) deny();
        $equipmentId = intval($_POST['equipment_id'] ?? 0);
        $newQuantity = intval($_POST['new_quantity'] ?? 0);
        $reason      = $_POST['reason'] ?? '';
        if ($equipmentId > 0 && $newQuantity > 0) {
            echo json_encode(updateEquipmentQuantity($conn, $equipmentId, $newQuantity, $reason));
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        }
        break;

    case 'update_equipment_quantity_with_status':
        if (!$isCaptain) deny();
        $equipmentId = intval($_POST['equipment_id'] ?? 0);
        $newQuantity = intval($_POST['new_quantity'] ?? 0);
        $deductFrom  = $_POST['deduct_from'] ?? '';
        $reason      = $_POST['reason'] ?? '';
        if ($equipmentId > 0 && $newQuantity > 0 && $deductFrom) {
            echo json_encode(updateEquipmentQuantityWithStatus($conn, $equipmentId, $newQuantity, $deductFrom, $reason));
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        }
        break;

    case 'add_equipment_items':
        if (!$isCaptain) deny();
        $equipmentId = intval($_POST['equipment_id'] ?? 0);
        $quantity    = intval($_POST['quantity'] ?? 0);
        $reason      = $_POST['reason'] ?? '';
        if ($equipmentId > 0 && $quantity > 0) {
            echo json_encode(addEquipmentItems($conn, $equipmentId, $quantity, $reason));
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        }
        break;

    case 'deduct_items':
        if (!$isCaptain) deny();
        $equipmentId = intval($_POST['equipment_id'] ?? 0);
        $quantity    = intval($_POST['quantity'] ?? 0);
        $toStatus    = $_POST['to_status'] ?? '';
        $reason      = $_POST['reason'] ?? '';
        if ($equipmentId > 0 && $quantity > 0 && $toStatus && $reason) {
            echo json_encode(deductItems($conn, $equipmentId, $quantity, $toStatus, $reason));
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        }
        break;

    case 'transfer_items':
        if (!$isCaptain) deny();
        $equipmentId = intval($_POST['equipment_id'] ?? 0);
        $fromStatus  = $_POST['from_status'] ?? '';
        $toStatus    = $_POST['to_status'] ?? '';
        $quantity    = intval($_POST['quantity'] ?? 0);
        $reason      = $_POST['reason'] ?? '';
        $totalQty    = intval($_POST['total_quantity'] ?? 0);
        if ($equipmentId > 0 && $fromStatus && $toStatus && $quantity > 0) {
            echo json_encode(transferItems($conn, $equipmentId, $fromStatus, $toStatus, $quantity, $reason, $totalQty));
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        }
        break;

    case 'undo_change':
        if (!$isCaptain) deny();
        $logId = intval($_POST['log_id'] ?? 0);
        if ($logId > 0) echo json_encode(undoChange($conn, $logId));
        else echo json_encode(['success' => false, 'message' => 'Invalid log ID']);
        break;

    // ── Unknown ──────────────────────────────────────────────
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}