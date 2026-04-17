<?php
// resident_equipment_ajax.php - Handles AJAX requests for resident equipment booking

session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'resident') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$resident_id = $_SESSION['user_id'];

// Handle JSON input for POST requests
$input = json_decode(file_get_contents('php://input'), true);
$action = $_POST['action'] ?? $_GET['action'] ?? $input['action'] ?? '';

switch($action) {
    case 'get_equipment_types':
        getEquipmentTypes($conn);
        break;
    case 'get_available_items_for_date':
        getAvailableItemsForDate($conn);
        break;
    case 'create_multiple_bookings':
        createMultipleBookings($conn, $resident_id, $input);
        break;
    case 'get_my_bookings':
        getMyBookings($conn, $resident_id);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function getEquipmentTypes($conn) {
    $sql = "SELECT et.*, 
            (SELECT COUNT(*) FROM equipment_items WHERE equipment_type_id = et.id AND status = 'available') as available_quantity,
            (SELECT COUNT(*) FROM equipment_items WHERE equipment_type_id = et.id) as total_quantity
            FROM equipment_types et 
            WHERE et.status = 'active'
            ORDER BY et.category, et.name ASC";
    
    $result = mysqli_query($conn, $sql);
    $equipment = [];
    
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $equipment[] = $row;
        }
    }
    
    echo json_encode(['success' => true, 'data' => $equipment]);
}

function getAvailableItemsForDate($conn) {
    $type_id = intval($_GET['type_id'] ?? 0);
    $booking_date = $_GET['booking_date'] ?? '';
    $duration_days = intval($_GET['duration_days'] ?? 1);
    
    if ($type_id == 0 || empty($booking_date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        return;
    }
    
    $booking_start = date('Y-m-d H:i:s', strtotime($booking_date));
    $booking_end = date('Y-m-d H:i:s', strtotime($booking_date . ' + ' . $duration_days . ' days'));
    
    // Get all items for this equipment type
    $items_sql = "SELECT ei.* FROM equipment_items ei WHERE ei.equipment_type_id = ? ORDER BY ei.item_number ASC";
    $items_stmt = mysqli_prepare($conn, $items_sql);
    mysqli_stmt_bind_param($items_stmt, "i", $type_id);
    mysqli_stmt_execute($items_stmt);
    $items_result = mysqli_stmt_get_result($items_stmt);
    
    $available_items = [];
    $unavailable_items = [];
    
    while ($item = mysqli_fetch_assoc($items_result)) {
        // Check if item is available (status = available)
        if ($item['status'] !== 'available') {
            $unavailable_items[] = $item;
            continue;
        }
        
        // Check for overlapping bookings
        $booking_sql = "SELECT b.*, DATE_FORMAT(b.booking_date, '%Y-%m-%d %H:%i') as booking_time
                        FROM bookings b 
                        WHERE b.equipment_item_id = ? 
                        AND b.status IN ('pending', 'approved', 'borrowed')
                        AND b.booking_date < ? 
                        AND b.expected_return_date > ?
                        LIMIT 1";
        $booking_stmt = mysqli_prepare($conn, $booking_sql);
        mysqli_stmt_bind_param($booking_stmt, "iss", $item['id'], $booking_end, $booking_start);
        mysqli_stmt_execute($booking_stmt);
        $booking_result = mysqli_stmt_get_result($booking_stmt);
        $conflicting_booking = mysqli_fetch_assoc($booking_result);
        mysqli_stmt_close($booking_stmt);
        
        if ($conflicting_booking) {
            $item['conflicting_booking'] = $conflicting_booking['booking_time'];
            $unavailable_items[] = $item;
        } else {
            $available_items[] = $item;
        }
    }
    mysqli_stmt_close($items_stmt);
    
    echo json_encode([
        'success' => true,
        'available_items' => $available_items,
        'unavailable_items' => $unavailable_items,
        'total_available' => count($available_items),
        'total_unavailable' => count($unavailable_items)
    ]);
}

function createMultipleBookings($conn, $resident_id, $input) {
    $item_ids = $input['item_ids'] ?? [];
    $booking_date = $input['booking_date'] ?? '';
    $duration_days = intval($input['duration_days'] ?? 1);
    $purpose = mysqli_real_escape_string($conn, $input['purpose'] ?? '');
    
    if (empty($item_ids) || empty($booking_date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        return;
    }
    
    if (empty($purpose)) {
        echo json_encode(['success' => false, 'message' => 'Purpose is required']);
        return;
    }
    
    $booking_start = date('Y-m-d H:i:s', strtotime($booking_date));
    $expected_return = date('Y-m-d H:i:s', strtotime($booking_date . ' + ' . $duration_days . ' days'));
    
    $success_count = 0;
    $errors = [];
    
    mysqli_begin_transaction($conn);
    
    foreach ($item_ids as $item_id) {
        // Verify item is available
        $item_sql = "SELECT id, status FROM equipment_items WHERE id = ? AND status = 'available' FOR UPDATE";
        $item_stmt = mysqli_prepare($conn, $item_sql);
        mysqli_stmt_bind_param($item_stmt, "i", $item_id);
        mysqli_stmt_execute($item_stmt);
        $item_result = mysqli_stmt_get_result($item_stmt);
        $item = mysqli_fetch_assoc($item_result);
        mysqli_stmt_close($item_stmt);
        
        if (!$item) {
            $errors[] = "Item ID $item_id is not available";
            continue;
        }
        
        // Check for overlapping bookings
        $check_sql = "SELECT COUNT(*) as count FROM bookings 
                      WHERE equipment_item_id = ? 
                      AND status IN ('pending', 'approved', 'borrowed')
                      AND booking_date < ? 
                      AND expected_return_date > ?";
        $check_stmt = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "iss", $item_id, $expected_return, $booking_start);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        $check_data = mysqli_fetch_assoc($check_result);
        $overlap_count = intval($check_data['count']);
        mysqli_stmt_close($check_stmt);
        
        if ($overlap_count > 0) {
            $errors[] = "Item ID $item_id is already booked for this time";
            continue;
        }
        
        // Create booking
        $sql = "INSERT INTO bookings (equipment_item_id, resident_id, booking_date, duration_days, expected_return_date, purpose, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "iisiss", $item_id, $resident_id, $booking_start, $duration_days, $expected_return, $purpose);
        
        if (mysqli_stmt_execute($stmt)) {
            $success_count++;
        } else {
            $errors[] = "Failed to book item ID $item_id: " . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    }
    
    if ($success_count > 0) {
        mysqli_commit($conn);
        echo json_encode([
            'success' => true,
            'booked_count' => $success_count,
            'total_requested' => count($item_ids),
            'errors' => $errors,
            'message' => "Successfully booked $success_count item(s)!"
        ]);
    } else {
        mysqli_rollback($conn);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create bookings: ' . implode(', ', $errors)
        ]);
    }
}

function getMyBookings($conn, $resident_id) {
    $sql = "SELECT b.*, ei.item_name, et.name as equipment_name, et.category, et.image as equipment_image
            FROM bookings b 
            JOIN equipment_items ei ON b.equipment_item_id = ei.id
            JOIN equipment_types et ON ei.equipment_type_id = et.id
            WHERE b.resident_id = ?
            ORDER BY b.created_at DESC";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $resident_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $bookings = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $bookings[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
    
    echo json_encode(['success' => true, 'bookings' => $bookings]);
}
?>