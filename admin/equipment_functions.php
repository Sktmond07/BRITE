<?php
// equipment_functions.php - Updated for equipment_types + equipment_items structure

// Get predefined items by category
function getPredefinedItemsByCategory($category) {
    $predefined = [
        'Equipment' => ['Tent', 'Table'],
        'Facility' => ['Covered Court'],
        'Vehicle' => ['Truck', 'Van', 'Barangay Patrol']
    ];
    
    return isset($predefined[$category]) ? $predefined[$category] : [];
}

// Check if category allows multiple quantity
function categoryAllowsMultipleQuantity($category) {
    // Only Equipment can have multiple quantity
    return $category === 'Equipment';
}

// Get all equipment types
function getAllEquipmentTypes($conn, $category = null, $status = null) {
    $sql = "SELECT * FROM equipment_types WHERE 1=1";
    $params = [];
    $types = "";
    
    if ($category && $category !== 'all') {
        $sql .= " AND category = ?";
        $params[] = $category;
        $types .= "s";
    }
    if ($status && $status !== 'all') {
        $sql .= " AND status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    $sql .= " ORDER BY FIELD(category, 'Equipment', 'Facility', 'Vehicle'), name ASC";
    
    $stmt = mysqli_prepare($conn, $sql);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $equipment = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $equipment[] = $row;
        }
    }
    return $equipment;
}

// Get equipment type with item counts by status
function getEquipmentTypeWithCounts($conn, $id) {
    $id = intval($id);
    $sql = "SELECT et.*, 
            (SELECT COUNT(*) FROM equipment_items WHERE equipment_type_id = et.id) as total_items,
            (SELECT COUNT(*) FROM equipment_items WHERE equipment_type_id = et.id AND status = 'available') as available_count,
            (SELECT COUNT(*) FROM equipment_items WHERE equipment_type_id = et.id AND status = 'borrowed') as borrowed_count,
            (SELECT COUNT(*) FROM equipment_items WHERE equipment_type_id = et.id AND status = 'maintenance') as maintenance_count,
            (SELECT COUNT(*) FROM equipment_items WHERE equipment_type_id = et.id AND status = 'lost') as lost_count
            FROM equipment_types et 
            WHERE et.id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result && mysqli_num_rows($result) > 0) {
        return mysqli_fetch_assoc($result);
    }
    return null;
}

// Get all equipment types with item counts
function getAllEquipmentTypesWithCounts($conn, $category = null, $status = null) {
    $sql = "SELECT et.*, 
            (SELECT COUNT(*) FROM equipment_items WHERE equipment_type_id = et.id) as total_items,
            (SELECT COUNT(*) FROM equipment_items WHERE equipment_type_id = et.id AND status = 'available') as available_count,
            (SELECT COUNT(*) FROM equipment_items WHERE equipment_type_id = et.id AND status = 'borrowed') as borrowed_count,
            (SELECT COUNT(*) FROM equipment_items WHERE equipment_type_id = et.id AND status = 'maintenance') as maintenance_count,
            (SELECT COUNT(*) FROM equipment_items WHERE equipment_type_id = et.id AND status = 'lost') as lost_count
            FROM equipment_types et WHERE 1=1";
    $params = [];
    $types = "";
    
    if ($category && $category !== 'all') {
        $sql .= " AND et.category = ?";
        $params[] = $category;
        $types .= "s";
    }
    if ($status && $status !== 'all') {
        $sql .= " AND et.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    $sql .= " ORDER BY FIELD(et.category, 'Equipment', 'Facility', 'Vehicle'), et.name ASC";
    
    $stmt = mysqli_prepare($conn, $sql);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $equipment = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $equipment[] = $row;
        }
    }
    return $equipment;
}

// Delete individual equipment item
function deleteEquipmentItem($conn, $id) {
    $id = intval($id);
    
    // Get item details first
    $item = getEquipmentItemById($conn, $id);
    if (!$item) {
        return ['success' => false, 'message' => 'Item not found'];
    }
    
    // Check if item has active bookings
    $checkSql = "SELECT COUNT(*) as count FROM bookings WHERE equipment_item_id = ? AND status IN ('pending', 'approved', 'borrowed')";
    $checkStmt = mysqli_prepare($conn, $checkSql);
    mysqli_stmt_bind_param($checkStmt, "i", $id);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);
    $activeBookings = mysqli_fetch_assoc($checkResult)['count'];
    
    if ($activeBookings > 0) {
        return ['success' => false, 'message' => 'Cannot delete: Item has active bookings'];
    }
    
    // Delete the item
    $sql = "DELETE FROM equipment_items WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    
    if (mysqli_stmt_execute($stmt)) {
        // Update the equipment_type total_quantity and available_quantity
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
        $updateSql = "UPDATE equipment_types SET total_quantity = ?, available_quantity = ? WHERE id = ?";
        $updateStmt = mysqli_prepare($conn, $updateSql);
        mysqli_stmt_bind_param($updateStmt, "iii", $counts['total'], $counts['available'], $typeId);
        mysqli_stmt_execute($updateStmt);
        
        return ['success' => true, 'message' => 'Item deleted successfully'];
    }
    return ['success' => false, 'message' => 'Failed to delete item'];
}

// Get equipment type by ID
function getEquipmentTypeById($conn, $id) {
    $id = intval($id);
    $sql = "SELECT * FROM equipment_types WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result && mysqli_num_rows($result) > 0) {
        return mysqli_fetch_assoc($result);
    }
    return null;
}

// Get all individual items for an equipment type
function getEquipmentItems($conn, $equipmentTypeId) {
    $equipmentTypeId = intval($equipmentTypeId);
    $sql = "SELECT * FROM equipment_items WHERE equipment_type_id = ? ORDER BY item_number ASC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $equipmentTypeId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $items = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $items[] = $row;
        }
    }
    return $items;
}

// Get available items for an equipment type
function getAvailableItems($conn, $equipmentTypeId) {
    $equipmentTypeId = intval($equipmentTypeId);
    $sql = "SELECT * FROM equipment_items WHERE equipment_type_id = ? AND status = 'available' ORDER BY item_number ASC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $equipmentTypeId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $items = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $items[] = $row;
        }
    }
    return $items;
}

// Get individual equipment item by ID (with type info)
function getEquipmentItemById($conn, $id) {
    $id = intval($id);
    $sql = "SELECT ei.*, et.name as type_name, et.category, et.image 
            FROM equipment_items ei 
            JOIN equipment_types et ON ei.equipment_type_id = et.id 
            WHERE ei.id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result && mysqli_num_rows($result) > 0) {
        return mysqli_fetch_assoc($result);
    }
    return null;
}

// Add equipment type with automatic item creation
function addEquipmentType($conn, $data, $imagePath = null) {
    $name = mysqli_real_escape_string($conn, $data['name']);
    $description = mysqli_real_escape_string($conn, $data['description']);
    $category = mysqli_real_escape_string($conn, $data['category']);
    $totalQuantity = intval($data['total_quantity']);
    $isPredefined = isset($data['is_predefined']) ? intval($data['is_predefined']) : 0;
    
    // Check if category allows multiple quantity
    if (!categoryAllowsMultipleQuantity($category) && $totalQuantity > 1) {
        return ['success' => false, 'message' => "$category cannot have quantity more than 1. Only one item allowed."];
    }
    
    // Insert equipment type
    $sql = "INSERT INTO equipment_types (name, description, category, total_quantity, available_quantity, status, image, is_predefined, created_at) 
            VALUES (?, ?, ?, ?, ?, 'active', ?, ?, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    $availableQuantity = $totalQuantity;
    mysqli_stmt_bind_param($stmt, "sssiiss", $name, $description, $category, $totalQuantity, $availableQuantity, $imagePath, $isPredefined);
    
    if (mysqli_stmt_execute($stmt)) {
        $typeId = mysqli_insert_id($conn);
        
        // Create individual items
        $successCount = 0;
        for ($i = 1; $i <= $totalQuantity; $i++) {
            $itemName = $totalQuantity > 1 ? $name . ' ' . $i : $name;
            $itemSql = "INSERT INTO equipment_items (equipment_type_id, item_name, item_number, status) VALUES (?, ?, ?, 'available')";
            $itemStmt = mysqli_prepare($conn, $itemSql);
            mysqli_stmt_bind_param($itemStmt, "isi", $typeId, $itemName, $i);
            if (mysqli_stmt_execute($itemStmt)) {
                $successCount++;
            }
        }
        
        return ['success' => true, 'id' => $typeId, 'items_created' => $successCount];
    }
    return ['success' => false, 'message' => 'Failed to add equipment type: ' . mysqli_error($conn)];
}

// Update equipment type
function updateEquipmentType($conn, $id, $data, $imagePath = null) {
    $id = intval($id);
    $name = mysqli_real_escape_string($conn, $data['name']);
    $description = mysqli_real_escape_string($conn, $data['description']);
    $category = mysqli_real_escape_string($conn, $data['category']);
    $totalQuantity = intval($data['total_quantity']);
    $status = mysqli_real_escape_string($conn, $data['status']);
    
    $oldType = getEquipmentTypeById($conn, $id);
    
    if (!categoryAllowsMultipleQuantity($category) && $totalQuantity > 1) {
        return ['success' => false, 'message' => "$category cannot have quantity more than 1. Only one item allowed."];
    }
    
    if ($imagePath) {
        if ($oldType && $oldType['image'] && file_exists(__DIR__ . '/../' . $oldType['image'])) {
            unlink(__DIR__ . '/../' . $oldType['image']);
        }
        $sql = "UPDATE equipment_types SET name = ?, description = ?, category = ?, total_quantity = ?, status = ?, image = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sssissi", $name, $description, $category, $totalQuantity, $status, $imagePath, $id);
    } else {
        $sql = "UPDATE equipment_types SET name = ?, description = ?, category = ?, total_quantity = ?, status = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sssisi", $name, $description, $category, $totalQuantity, $status, $id);
    }
    
    if (mysqli_stmt_execute($stmt)) {
        // Update items if quantity changed
        $currentItems = getEquipmentItems($conn, $id);
        $currentCount = count($currentItems);
        
        if ($totalQuantity > $currentCount) {
            // Add new items
            for ($i = $currentCount + 1; $i <= $totalQuantity; $i++) {
                $itemName = $totalQuantity > 1 ? $name . ' ' . $i : $name;
                $itemSql = "INSERT INTO equipment_items (equipment_type_id, item_name, item_number, status) VALUES (?, ?, ?, 'available')";
                $itemStmt = mysqli_prepare($conn, $itemSql);
                mysqli_stmt_bind_param($itemStmt, "isi", $id, $itemName, $i);
                mysqli_stmt_execute($itemStmt);
            }
        } elseif ($totalQuantity < $currentCount) {
            // Delete extra items that are available
            $deleteSql = "DELETE FROM equipment_items WHERE equipment_type_id = ? AND item_number > ? AND status = 'available'";
            $deleteStmt = mysqli_prepare($conn, $deleteSql);
            mysqli_stmt_bind_param($deleteStmt, "ii", $id, $totalQuantity);
            mysqli_stmt_execute($deleteStmt);
        }
        
        return ['success' => true];
    }
    return ['success' => false, 'message' => 'Failed to update: ' . mysqli_error($conn)];
}

// Delete equipment type
function deleteEquipmentType($conn, $id) {
    $id = intval($id);
    
    // Check if there are active bookings
    $checkSql = "SELECT COUNT(*) as count FROM bookings b 
                 JOIN equipment_items ei ON b.equipment_item_id = ei.id 
                 WHERE ei.equipment_type_id = ? AND b.status IN ('pending', 'approved', 'borrowed')";
    $checkStmt = mysqli_prepare($conn, $checkSql);
    mysqli_stmt_bind_param($checkStmt, "i", $id);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);
    $activeBookings = mysqli_fetch_assoc($checkResult)['count'];
    
    if ($activeBookings > 0) {
        return ['success' => false, 'message' => 'Cannot delete: Some items have active bookings'];
    }
    
    $type = getEquipmentTypeById($conn, $id);
    
    $sql = "DELETE FROM equipment_types WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    
    if (mysqli_stmt_execute($stmt)) {
        if ($type && $type['image'] && file_exists(__DIR__ . '/../' . $type['image'])) {
            unlink(__DIR__ . '/../' . $type['image']);
        }
        return ['success' => true];
    }
    return ['success' => false, 'message' => 'Failed to delete'];
}

// Create booking for an equipment item
function createBooking($conn, $equipmentItemId, $residentId, $purpose, $durationDays) {
    $equipmentItemId = intval($equipmentItemId);
    $residentId = intval($residentId);
    $durationDays = intval($durationDays);
    $purpose = mysqli_real_escape_string($conn, $purpose);
    
    $bookingDate = date('Y-m-d H:i:s');
    $expectedReturnDate = date('Y-m-d H:i:s', strtotime("+$durationDays days"));
    
    $item = getEquipmentItemById($conn, $equipmentItemId);
    if (!$item) {
        return ['success' => false, 'message' => 'Item not found'];
    }
    
    if ($item['status'] !== 'available') {
        return ['success' => false, 'message' => "{$item['item_name']} is not available"];
    }
    
    $sql = "INSERT INTO bookings (equipment_item_id, resident_id, booking_date, duration_days, expected_return_date, purpose, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "iisiss", $equipmentItemId, $residentId, $bookingDate, $durationDays, $expectedReturnDate, $purpose);
    
    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'booking_id' => mysqli_insert_id($conn)];
    }
    return ['success' => false, 'message' => 'Failed to create booking: ' . mysqli_error($conn)];
}

// Get all bookings
function getAllBookings($conn, $status = null, $page = 1, $perPage = 10) {
    $offset = ($page - 1) * $perPage;
    
    $sql = "SELECT b.*, ei.item_name, ei.item_number, et.name as equipment_type_name, et.category, et.image,
            r.first_name, r.last_name, r.email, r.phone
            FROM bookings b 
            JOIN equipment_items ei ON b.equipment_item_id = ei.id
            JOIN equipment_types et ON ei.equipment_type_id = et.id
            JOIN resident r ON b.resident_id = r.id";
    
    $countSql = "SELECT COUNT(*) as total FROM bookings b 
                 JOIN equipment_items ei ON b.equipment_item_id = ei.id
                 JOIN equipment_types et ON ei.equipment_type_id = et.id
                 JOIN resident r ON b.resident_id = r.id";
    
    if ($status && $status !== 'all') {
        $status = mysqli_real_escape_string($conn, $status);
        $sql .= " WHERE b.status = '$status'";
        $countSql .= " WHERE b.status = '$status'";
    }
    
    $sql .= " ORDER BY b.booking_date DESC, b.created_at DESC LIMIT $offset, $perPage";
    
    $result = mysqli_query($conn, $sql);
    $bookings = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $bookings[] = $row;
        }
    }
    
    $countResult = mysqli_query($conn, $countSql);
    $totalRecords = $countResult ? mysqli_fetch_assoc($countResult)['total'] : 0;
    $totalPages = ceil($totalRecords / $perPage);
    
    return ['bookings' => $bookings, 'totalPages' => $totalPages, 'currentPage' => $page, 'totalRecords' => $totalRecords];
}

// Get booking by ID
function getBookingById($conn, $id) {
    $id = intval($id);
    $sql = "SELECT b.*, ei.item_name, ei.item_number, et.name as equipment_type_name, et.category, et.image,
            r.first_name, r.last_name, r.email, r.phone, r.address
            FROM bookings b 
            JOIN equipment_items ei ON b.equipment_item_id = ei.id
            JOIN equipment_types et ON ei.equipment_type_id = et.id
            JOIN resident r ON b.resident_id = r.id 
            WHERE b.id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result && mysqli_num_rows($result) > 0) {
        return mysqli_fetch_assoc($result);
    }
    return null;
}

// Update booking status
// Update booking status - ITEM STATUS NEVER CHANGES TO BORROWED
function updateBookingStatus($conn, $id, $status, $admin_notes = null) {
    $id = intval($id);
    $status = mysqli_real_escape_string($conn, $status);
    $admin_notes = $admin_notes ? mysqli_real_escape_string($conn, $admin_notes) : null;
    
    $booking = getBookingById($conn, $id);
    if (!$booking) {
        return ['success' => false, 'message' => 'Booking not found'];
    }
    
    // ITEM STATUS - Only available, maintenance, or lost (NO borrowed)
    // The item status NEVER changes when booking is approved or borrowed
    
    if ($status === 'returned' && ($booking['status'] === 'borrowed' || $booking['status'] === 'approved')) {
        // Item is returned - record return date only, NO item status change
        $returnedAt = date('Y-m-d H:i:s');
        $updateBookingSql = "UPDATE bookings SET returned_at = ?, actual_return_date = ? WHERE id = ?";
        $updateBookingStmt = mysqli_prepare($conn, $updateBookingSql);
        mysqli_stmt_bind_param($updateBookingStmt, "ssi", $returnedAt, $returnedAt, $id);
        mysqli_stmt_execute($updateBookingStmt);
        
    } elseif ($status === 'borrowed' && $booking['status'] === 'approved') {
        // Resident takes the item - record borrowed date only, NO item status change
        $borrowedAt = date('Y-m-d H:i:s');
        $updateBookingSql = "UPDATE bookings SET borrowed_at = ? WHERE id = ?";
        $updateBookingStmt = mysqli_prepare($conn, $updateBookingSql);
        mysqli_stmt_bind_param($updateBookingStmt, "si", $borrowedAt, $id);
        mysqli_stmt_execute($updateBookingStmt);
    }
    
    // For approved, rejected, pending - NO item status changes at all
    
    $sql = "UPDATE bookings SET status = ?, admin_notes = ?, processed_at = NOW() WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ssi", $status, $admin_notes, $id);
    
    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Booking status updated'];
    }
    return ['success' => false, 'message' => 'Failed to update status'];
}

// Get dashboard counts
function getEquipmentCounts($conn) {
    $total = 0;
    $available = 0;
    $borrowed = 0;
    $maintenance = 0;
    $categories = [];
    
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM equipment_items");
    if ($result) $total = mysqli_fetch_assoc($result)['count'];
    
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM equipment_items WHERE status = 'available'");
    if ($result) $available = mysqli_fetch_assoc($result)['count'];
    
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM bookings WHERE status = 'borrowed'");
    if ($result) $borrowed = mysqli_fetch_assoc($result)['count'];
    
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM equipment_items WHERE status = 'maintenance'");
    if ($result) $maintenance = mysqli_fetch_assoc($result)['count'];
    
    $result = mysqli_query($conn, "SELECT et.category, COUNT(*) as count FROM equipment_items ei JOIN equipment_types et ON ei.equipment_type_id = et.id GROUP BY et.category");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $categories[] = $row;
        }
    }
    
    return ['total' => $total, 'available' => $available, 'borrowed' => $borrowed, 'maintenance' => $maintenance, 'categories' => $categories];
}

// Get pending bookings count
function getPendingBookingsCount($conn) {
    $sql = "SELECT COUNT(*) as count FROM bookings WHERE status = 'pending'";
    $result = mysqli_query($conn, $sql);
    if ($result) {
        return mysqli_fetch_assoc($result)['count'];
    }
    return 0;
}

// Get predefined items for AJAX
function getPredefinedItems($conn, $category) {
    return getPredefinedItemsByCategory($category);
}

// Handle image upload
function uploadEquipmentImage($file) {
    $targetDir = __DIR__ . '/../uploads/equipment/';
    
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    
    $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($file['name']));
    $targetFile = $targetDir . $fileName;
    $imageFileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
    
    $check = getimagesize($file['tmp_name']);
    if ($check === false) {
        return ['success' => false, 'message' => 'File is not an image.'];
    }
    
    if ($file['size'] > 5000000) {
        return ['success' => false, 'message' => 'File is too large. Max 5MB.'];
    }
    
    $allowedFormats = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'jfif'];
    if (!in_array($imageFileType, $allowedFormats)) {
        return ['success' => false, 'message' => 'Only JPG, JPEG, PNG, GIF & WEBP files are allowed.'];
    }
    
    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        return ['success' => true, 'path' => 'uploads/equipment/' . $fileName];
    }
    
    return ['success' => false, 'message' => 'Failed to upload image.'];
}
?>