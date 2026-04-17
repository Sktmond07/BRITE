<?php
// equipment_ajax.php - Handles all AJAX requests for equipment management

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
    case 'get_predefined_items':
        $category = isset($_GET['category']) ? $_GET['category'] : '';
        $items = getPredefinedItems($conn, $category);
        echo json_encode(['success' => true, 'data' => $items]);
        break;
        
   case 'get_equipment':
    // Get equipment types with item counts
    $category = isset($_GET['category']) ? $_GET['category'] : null;
    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $equipment = getAllEquipmentTypesWithCounts($conn, $category, $status);
    echo json_encode(['success' => true, 'data' => $equipment]);
    break;
        
    case 'get_equipment_by_id':
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if ($id > 0) {
            $equipment = getEquipmentTypeById($conn, $id);
            if ($equipment) {
                echo json_encode(['success' => true, 'data' => $equipment]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Equipment not found']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        }
        break;
        
    case 'get_equipment_items':
        $typeId = isset($_GET['type_id']) ? intval($_GET['type_id']) : 0;
        if ($typeId > 0) {
            $items = getEquipmentItems($conn, $typeId);
            echo json_encode(['success' => true, 'data' => $items]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid type ID']);
        }
        break;
        
    case 'get_available_items':
        $typeId = isset($_GET['type_id']) ? intval($_GET['type_id']) : 0;
        if ($typeId > 0) {
            $items = getAvailableItems($conn, $typeId);
            echo json_encode(['success' => true, 'data' => $items]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid type ID']);
        }
        break;
        
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
        
        // Prepare data for addEquipmentType
        $data = [
            'name' => $_POST['name'],
            'description' => $_POST['description'],
            'category' => $_POST['category'],
            'total_quantity' => $_POST['total_quantity'],
            'is_predefined' => isset($_POST['is_predefined']) ? $_POST['is_predefined'] : 0
        ];
        
        $result = addEquipmentType($conn, $data, $imagePath);
        echo json_encode($result);
        break;
        
    case 'update_equipment':
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
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
        
        if ($removeImage) {
            $oldEquipment = getEquipmentTypeById($conn, $id);
            if ($oldEquipment && $oldEquipment['image'] && file_exists(__DIR__ . '/../' . $oldEquipment['image'])) {
                unlink(__DIR__ . '/../' . $oldEquipment['image']);
            }
            $imagePath = '';
        }
        
        $data = [
            'name' => $_POST['name'],
            'description' => $_POST['description'],
            'category' => $_POST['category'],
            'total_quantity' => $_POST['total_quantity'],
            'status' => $_POST['status']
        ];
        
        if ($id > 0) {
            $result = updateEquipmentType($conn, $id, $data, $imagePath);
            echo json_encode($result);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        }
        break;
        
    case 'delete_equipment':
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id > 0) {
            $result = deleteEquipmentType($conn, $id);
            echo json_encode($result);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        }
        break;
        
    case 'get_equipment_bookings':
        // Get bookings for a specific equipment item
        $itemId = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if ($itemId > 0) {
            $sql = "SELECT b.*, ei.item_name, ei.item_number, et.name as equipment_type_name, et.category, et.image,
                    r.first_name, r.last_name, r.email, r.phone
                    FROM bookings b 
                    JOIN equipment_items ei ON b.equipment_item_id = ei.id
                    JOIN equipment_types et ON ei.equipment_type_id = et.id
                    JOIN resident r ON b.resident_id = r.id 
                    WHERE b.equipment_item_id = ?
                    ORDER BY b.booking_date DESC";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "i", $itemId);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $bookings = [];
            if ($result && mysqli_num_rows($result) > 0) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $bookings[] = $row;
                }
            }
            echo json_encode(['success' => true, 'bookings' => $bookings]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid item ID']);
        }
        break;
        
    case 'get_bookings':
        $status = isset($_GET['status']) ? $_GET['status'] : null;
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $result = getAllBookings($conn, $status, $page);
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
        
    case 'get_counts':
        $counts = getEquipmentCounts($conn);
        $pendingBookings = getPendingBookingsCount($conn);
        echo json_encode(['success' => true, 'counts' => $counts, 'pending_bookings' => $pendingBookings]);
        break;
     case 'update_item_status':
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $status = isset($_POST['status']) ? $_POST['status'] : '';
    $condition_notes = isset($_POST['condition_notes']) ? $_POST['condition_notes'] : null;
    
    if ($id > 0 && $status) {
        $condition_notes = $condition_notes ? mysqli_real_escape_string($conn, $condition_notes) : null;
        
        $sql = "UPDATE equipment_items SET status = ?, condition_notes = ?, updated_at = NOW() WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ssi", $status, $condition_notes, $id);
        
        if (mysqli_stmt_execute($stmt)) {
            // Update counts in equipment_types
            $item = getEquipmentItemById($conn, $id);
            if ($item) {
                $typeId = $item['equipment_type_id'];
                
                // Get updated counts
                $countSql = "SELECT 
                                COUNT(*) as total,
                                SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available
                             FROM equipment_items WHERE equipment_type_id = ?";
                $countStmt = mysqli_prepare($conn, $countSql);
                mysqli_stmt_bind_param($countStmt, "i", $typeId);
                mysqli_stmt_execute($countStmt);
                $countResult = mysqli_stmt_get_result($countStmt);
                $counts = mysqli_fetch_assoc($countResult);
                
                // Update equipment_types
                $updateSql = "UPDATE equipment_types SET available_quantity = ? WHERE id = ?";
                $updateStmt = mysqli_prepare($conn, $updateSql);
                mysqli_stmt_bind_param($updateStmt, "ii", $counts['available'], $typeId);
                mysqli_stmt_execute($updateStmt);
            }
            
            echo json_encode(['success' => true, 'message' => 'Item status updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update item status: ' . mysqli_error($conn)]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
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

 

case 'get_recent_bookings':
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 5;
    $sql = "SELECT b.*, ei.item_name, et.name as equipment_name, et.category,
            r.first_name, r.last_name, r.email, r.phone
            FROM bookings b 
            JOIN equipment_items ei ON b.equipment_item_id = ei.id
            JOIN equipment_types et ON ei.equipment_type_id = et.id
            JOIN resident r ON b.resident_id = r.id 
            ORDER BY b.created_at DESC 
            LIMIT $limit";
    $result = mysqli_query($conn, $sql);
    $bookings = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $bookings[] = $row;
        }
    }
    echo json_encode(['success' => true, 'bookings' => $bookings]);
    break;

    
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>