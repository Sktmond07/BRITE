<?php
// equipment_ajax.php - Complete with validation and global_id support

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/equipment_functions.php';

header('Content-Type: application/json');

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

switch ($action) {
    // Validation endpoints
    case 'check_equipment_exists':
        $name = isset($_GET['name']) ? $_GET['name'] : '';
        $excludeId = isset($_GET['exclude_id']) ? intval($_GET['exclude_id']) : null;
        $exists = checkEquipmentExists($conn, $name, $excludeId);
        echo json_encode(['success' => true, 'exists' => $exists]);
        break;
        
    case 'check_vehicle_exists':
        $name = isset($_GET['name']) ? $_GET['name'] : '';
        $plateNumber = isset($_GET['plate_number']) ? $_GET['plate_number'] : '';
        $excludeId = isset($_GET['exclude_id']) ? intval($_GET['exclude_id']) : null;
        $exists = checkVehicleExists($conn, $name, $plateNumber, $excludeId);
        echo json_encode(['success' => true, 'exists' => $exists]);
        break;
        
    case 'check_facility_exists':
        $name = isset($_GET['name']) ? $_GET['name'] : '';
        $excludeId = isset($_GET['exclude_id']) ? intval($_GET['exclude_id']) : null;
        $exists = checkFacilityExists($conn, $name, $excludeId);
        echo json_encode(['success' => true, 'exists' => $exists, 'warning' => $exists]);
        break;
    
    // Get endpoints
    case 'get_predefined_items':
        $category = isset($_GET['category']) ? $_GET['category'] : '';
        $items = getPredefinedItems($conn, $category);
        echo json_encode(['success' => true, 'data' => $items]);
        break;
        
    case 'get_equipment':
        $category = isset($_GET['category']) ? $_GET['category'] : null;
        $equipment = getAllEquipmentTypesWithCounts($conn, $category);
        echo json_encode(['success' => true, 'data' => $equipment]);
        break;
        
    case 'get_item_by_global_id':
        $globalId = isset($_GET['global_id']) ? $_GET['global_id'] : '';
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
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $type = isset($_GET['type']) ? $_GET['type'] : 'equipment';
        if ($id > 0) {
            if ($type === 'equipment') {
                $equipment = getEquipmentTypeById($conn, $id);
            } elseif ($type === 'facility') {
                $equipment = getFacilityById($conn, $id);
            } else {
                $equipment = getVehicleById($conn, $id);
            }
            if ($equipment) {
                echo json_encode(['success' => true, 'data' => $equipment]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Item not found']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        }
        break;
        
    case 'get_equipment_items':
        $typeId = isset($_GET['type_id']) ? intval($_GET['type_id']) : 0;
        $items = getEquipmentItems($conn, $typeId);
        echo json_encode(['success' => true, 'data' => $items]);
        break;
        
    case 'get_equipment_bookings':
        $itemId = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $type = isset($_GET['type']) ? $_GET['type'] : 'equipment';
        if ($itemId > 0) {
            $bookings = getEquipmentBookings($conn, $itemId, $type);
            echo json_encode(['success' => true, 'bookings' => $bookings]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid item ID']);
        }
        break;
        // Add this case to equipment_ajax.php switch statement
case 'get_all_bookings':
    $status = isset($_GET['status']) && $_GET['status'] !== 'all' ? $_GET['status'] : null;
    $type = isset($_GET['type']) && $_GET['type'] !== 'all' ? $_GET['type'] : null;
    $date = isset($_GET['date']) ? $_GET['date'] : null;
    $result = getAllBookings($conn, $status, $type, 1, 9999);
    echo json_encode(['success' => true, 'data' => $result]);
    break;
        
    case 'get_bookings':
    $status = isset($_GET['status']) && $_GET['status'] !== 'all' ? $_GET['status'] : null;
    $type = isset($_GET['type']) && $_GET['type'] !== 'all' ? $_GET['type'] : null;
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $result = getAllBookings($conn, $status, $type, $page);
    echo json_encode(['success' => true, 'data' => $result]);
    break;
        
    case 'get_booking_by_id':
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if ($id > 0) {
            $booking = getBookingById($conn, $id);
            if ($booking) {
                echo json_encode(['success' => true, 'data' => $booking]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Booking not found']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        }
        break;
        
    case 'get_counts':
        $counts = getEquipmentCounts($conn);
        $pendingBookings = getPendingBookingsCount($conn);
        echo json_encode(['success' => true, 'counts' => $counts, 'pending_bookings' => $pendingBookings]);
        break;
        
    case 'get_recent_bookings':
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 5;
        $bookings = getRecentBookings($conn, $limit);
        echo json_encode(['success' => true, 'bookings' => $bookings]);
        break;
    
    // Add endpoints
    case 'add_equipment':
        $imagePath = null;
        if (isset($_FILES['equipment_image']) && $_FILES['equipment_image']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = uploadEquipmentImage($_FILES['equipment_image']);
            if ($uploadResult['success']) {
                $imagePath = $uploadResult['path'];
            } else {
                echo json_encode(['success' => false, 'message' => $uploadResult['message']]);
                exit;
            }
        }
        
        $category = $_POST['category'];
        $data = [
            'name' => $_POST['name'],
            'description' => $_POST['description'],
            'category' => $category,
            'total_quantity' => isset($_POST['total_quantity']) ? intval($_POST['total_quantity']) : 1,
            'location' => $_POST['location'] ?? '',
            'capacity' => $_POST['capacity'] ?? 0,
            'plate_number' => $_POST['plate_number'] ?? '',
            'vehicle_type' => $_POST['vehicle_type'] ?? 'Other'
        ];
        
        if ($category === 'Equipment') {
            $result = addEquipmentItem($conn, $data, $imagePath);
        } elseif ($category === 'Facility') {
            $result = addFacilityItem($conn, $data, $imagePath);
        } else {
            $result = addVehicleItem($conn, $data, $imagePath);
        }
        echo json_encode($result);
        break;
    
    // Update endpoints
    case 'update_equipment':
        $globalId = isset($_POST['id']) ? $_POST['id'] : '';
        $type = isset($_POST['type']) ? $_POST['type'] : 'equipment';
        $id = intval(preg_replace('/[^0-9]/', '', $globalId));
        
        $imagePath = null;
        if (isset($_FILES['equipment_image']) && $_FILES['equipment_image']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = uploadEquipmentImage($_FILES['equipment_image']);
            if ($uploadResult['success']) {
                $imagePath = $uploadResult['path'];
            } else {
                echo json_encode(['success' => false, 'message' => $uploadResult['message']]);
                exit;
            }
        }
        
        $removeImage = isset($_POST['remove_image']) && $_POST['remove_image'] === 'true';
        if ($removeImage) $imagePath = '';
        
        $data = [
            'name' => $_POST['name'],
            'description' => $_POST['description'],
            'category' => $_POST['category'],
            'total_quantity' => isset($_POST['total_quantity']) ? intval($_POST['total_quantity']) : 1,
            'status' => $_POST['status'],
            'location' => $_POST['location'] ?? '',
            'capacity' => $_POST['capacity'] ?? 0,
            'plate_number' => $_POST['plate_number'] ?? '',
            'vehicle_type' => $_POST['vehicle_type'] ?? 'Other'
        ];
        
        if ($type === 'equipment') {
            $result = updateEquipmentItem($conn, $id, $data, $imagePath);
        } elseif ($type === 'facility') {
            $result = updateFacilityItem($conn, $id, $data, $imagePath);
        } else {
            $result = updateVehicleItem($conn, $id, $data, $imagePath);
        }
        echo json_encode($result);
        break;
        
    case 'update_booking_status':
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $status = isset($_POST['status']) ? $_POST['status'] : '';
    $admin_notes = isset($_POST['admin_notes']) ? $_POST['admin_notes'] : null;
    
    if ($id > 0 && $status) {
        $result = updateBookingStatus($conn, $id, $status, $admin_notes);
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    }
    break;
    
    // Delete endpoints
    case 'delete_equipment':
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $type = isset($_POST['type']) ? $_POST['type'] : 'equipment';
        if ($id > 0) {
            $result = deleteItem($conn, $id, $type);
            echo json_encode($result);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        }
        break;
        
    case 'delete_equipment_item':
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id > 0) {
            $result = deleteEquipmentItem($conn, $id);
            echo json_encode($result);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        }
        break;

    // Add these cases to equipment_ajax.php

case 'get_equipment_status':
    $equipmentId = isset($_GET['equipment_id']) ? intval($_GET['equipment_id']) : 0;
    if ($equipmentId > 0) {
        $quantities = getEquipmentStatusQuantities($conn, $equipmentId);
        echo json_encode(['success' => true, 'data' => $quantities]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid equipment ID']);
    }
    break;
    case 'get_equipment_status_quantities':
    $equipmentId = isset($_GET['equipment_id']) ? intval($_GET['equipment_id']) : 0;
    if ($equipmentId > 0) {
        $quantities = getEquipmentStatusQuantities($conn, $equipmentId);
        echo json_encode(['success' => true, 'data' => $quantities]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid equipment ID']);
    }
    break;

case 'update_equipment_status':
    $equipmentId = isset($_POST['equipment_id']) ? intval($_POST['equipment_id']) : 0;
    $available = isset($_POST['available']) ? intval($_POST['available']) : 0;
    $maintenance = isset($_POST['maintenance']) ? intval($_POST['maintenance']) : 0;
    $lost = isset($_POST['lost']) ? intval($_POST['lost']) : 0;
    
    if ($equipmentId > 0) {
        $result = updateEquipmentStatusOnly($conn, $equipmentId, $available, $maintenance, $lost);
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid equipment ID']);
    }
    break;

case 'update_equipment_quantity':
    $equipmentId = isset($_POST['equipment_id']) ? intval($_POST['equipment_id']) : 0;
    $newQuantity = isset($_POST['new_quantity']) ? intval($_POST['new_quantity']) : 0;
    
    if ($equipmentId > 0 && $newQuantity > 0) {
        $result = updateEquipmentQuantity($conn, $equipmentId, $newQuantity);
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    }
    break;


    case 'update_equipment_quantity':
    $equipmentId = isset($_POST['equipment_id']) ? intval($_POST['equipment_id']) : 0;
    $newQuantity = isset($_POST['new_quantity']) ? intval($_POST['new_quantity']) : 0;
    $reason = isset($_POST['reason']) ? $_POST['reason'] : '';
    
    if ($equipmentId > 0 && $newQuantity > 0) {
        $result = updateEquipmentQuantity($conn, $equipmentId, $newQuantity, $reason);
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    }
    break;

case 'update_equipment_quantity_with_status':
    $equipmentId = isset($_POST['equipment_id']) ? intval($_POST['equipment_id']) : 0;
    $newQuantity = isset($_POST['new_quantity']) ? intval($_POST['new_quantity']) : 0;
    $deductFrom = isset($_POST['deduct_from']) ? $_POST['deduct_from'] : '';
    $reason = isset($_POST['reason']) ? $_POST['reason'] : '';
    
    if ($equipmentId > 0 && $newQuantity > 0 && $deductFrom) {
        $result = updateEquipmentQuantityWithStatus($conn, $equipmentId, $newQuantity, $deductFrom, $reason);
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    }
    break;

    // Add this case to equipment_ajax.php
case 'add_equipment_items':
    $equipmentId = isset($_POST['equipment_id']) ? intval($_POST['equipment_id']) : 0;
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 0;
    $reason = isset($_POST['reason']) ? $_POST['reason'] : '';
    
    if ($equipmentId > 0 && $quantity > 0) {
        $result = addEquipmentItems($conn, $equipmentId, $quantity, $reason);
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    }
    break;

    // Add these cases to equipment_ajax.php

// Add to equipment_ajax.php
case 'get_change_logs':
    $equipmentId = isset($_GET['equipment_id']) ? intval($_GET['equipment_id']) : 0;
    if ($equipmentId > 0) {
        $logs = getChangeLogs($conn, $equipmentId);
        echo json_encode(['success' => true, 'data' => $logs]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid equipment ID']);
    }
    break;

case 'transfer_items':
    $equipmentId = isset($_POST['equipment_id']) ? intval($_POST['equipment_id']) : 0;
    $fromStatus = isset($_POST['from_status']) ? $_POST['from_status'] : '';
    $toStatus = isset($_POST['to_status']) ? $_POST['to_status'] : '';
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 0;
    $reason = isset($_POST['reason']) ? $_POST['reason'] : '';
    $totalQuantity = isset($_POST['total_quantity']) ? intval($_POST['total_quantity']) : 0;
    
    if ($equipmentId > 0 && $fromStatus && $toStatus && $quantity > 0) {
        $result = transferItems($conn, $equipmentId, $fromStatus, $toStatus, $quantity, $reason, $totalQuantity);
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    }
    break;

// In equipment_ajax.php, update the undo_change case:
case 'undo_change':
    $logId = isset($_POST['log_id']) ? intval($_POST['log_id']) : 0;
    if ($logId > 0) {
        $result = undoChange($conn, $logId);
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid log ID']);
    }
    break;

    // Add this case to equipment_ajax.php
case 'deduct_items':
    $equipmentId = isset($_POST['equipment_id']) ? intval($_POST['equipment_id']) : 0;
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 0;
    $toStatus = isset($_POST['to_status']) ? $_POST['to_status'] : '';
    $reason = isset($_POST['reason']) ? $_POST['reason'] : '';
    
    if ($equipmentId > 0 && $quantity > 0 && $toStatus && $reason) {
        $result = deductItems($conn, $equipmentId, $quantity, $toStatus, $reason);
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    }
    break;
    
    

    
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>