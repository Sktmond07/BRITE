<?php
// equipment_functions.php - Updated with equipment_status for quantity tracking

// Get predefined items by category
function getPredefinedItemsByCategory($category) {
    $predefined = [
        'Equipment' => ['Tent', 'Table', 'Chair'],
        'Facility' => ['Covered Court', 'Multi-purpose Hall', 'Barangay Hall'],
        'Vehicle' => ['Barangay Patrol', 'Truck', 'Van', 'Jeep', 'Ambulance']
    ];
    return isset($predefined[$category]) ? $predefined[$category] : [];
}

// ============ GET FUNCTIONS ============

// Get all equipment (from equipment table only)
function getAllEquipment($conn) {
    $sql = "SELECT *, 'equipment' as source_table, 'Equipment' as category, quantity as total_items 
            FROM equipment WHERE status != 'inactive' ORDER BY name ASC";
    $result = mysqli_query($conn, $sql);
    $items = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            // Get status quantities from equipment_status
            $statusQuantities = getEquipmentStatusQuantities($conn, $row['id']);
            $row['available_count'] = $statusQuantities['available'];
            $row['maintenance_count'] = $statusQuantities['maintenance'];
            $row['lost_count'] = $statusQuantities['lost'];
            $row['borrowed_count'] = 0;
            $items[] = $row;
        }
    }
    return $items;
}

// Get all facilities
function getAllFacilities($conn) {
    $sql = "SELECT *, 'facility' as source_table, 'Facility' as category, 1 as total_items, 
            CASE WHEN status = 'available' THEN 1 ELSE 0 END as available_count,
            0 as borrowed_count,
            CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END as maintenance_count,
            0 as lost_count
            FROM facilities WHERE status != 'inactive' ORDER BY name ASC";
    $result = mysqli_query($conn, $sql);
    $items = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $items[] = $row;
        }
    }
    return $items;
}

// Get all vehicles
function getAllVehicles($conn) {
    $sql = "SELECT *, 'vehicle' as source_table, 'Vehicle' as category, 1 as total_items,
            CASE WHEN status = 'available' THEN 1 ELSE 0 END as available_count,
            0 as borrowed_count,
            CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END as maintenance_count,
            0 as lost_count
            FROM vehicles WHERE status != 'inactive' ORDER BY name ASC";
    $result = mysqli_query($conn, $sql);
    $items = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $items[] = $row;
        }
    }
    return $items;
}

// Get all items unified
function getAllEquipmentTypesWithCounts($conn, $category = null) {
    $items = [];
    if (!$category || $category === 'all' || $category === 'Equipment') {
        $items = array_merge($items, getAllEquipment($conn));
    }
    if (!$category || $category === 'all' || $category === 'Facility') {
        $items = array_merge($items, getAllFacilities($conn));
    }
    if (!$category || $category === 'all' || $category === 'Vehicle') {
        $items = array_merge($items, getAllVehicles($conn));
    }
    return $items;
}

// Get equipment status quantities
// Fix getEquipmentStatusQuantities - ensure it returns integers
function getEquipmentStatusQuantities($conn, $equipmentId) {
    $equipmentId = intval($equipmentId);
    $sql = "SELECT status, quantity FROM equipment_status WHERE equipment_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $equipmentId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $quantities = ['available' => 0, 'maintenance' => 0, 'lost' => 0];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $quantities[$row['status']] = intval($row['quantity']);
        }
    }
    return $quantities;
}

// Get equipment by ID
function getEquipmentById($conn, $id) {
    $id = intval($id);
    $sql = "SELECT *, 'equipment' as source_table, 'Equipment' as category FROM equipment WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result && mysqli_num_rows($result) > 0) {
        $item = mysqli_fetch_assoc($result);
        $item['total_quantity'] = $item['quantity'];
        $statusQuantities = getEquipmentStatusQuantities($conn, $id);
        $item['available_count'] = $statusQuantities['available'];
        $item['maintenance_count'] = $statusQuantities['maintenance'];
        $item['lost_count'] = $statusQuantities['lost'];
        return $item;
    }
    return null;
}

// Get facility by ID
function getFacilityById($conn, $id) {
    $id = intval($id);
    $sql = "SELECT *, 'facility' as source_table, 'Facility' as category FROM facilities WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result && mysqli_num_rows($result) > 0) {
        $item = mysqli_fetch_assoc($result);
        $item['total_quantity'] = 1;
        $item['available_count'] = ($item['status'] === 'available') ? 1 : 0;
        return $item;
    }
    return null;
}

// Get vehicle by ID
function getVehicleById($conn, $id) {
    $id = intval($id);
    $sql = "SELECT *, 'vehicle' as source_table, 'Vehicle' as category FROM vehicles WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result && mysqli_num_rows($result) > 0) {
        $item = mysqli_fetch_assoc($result);
        $item['total_quantity'] = 1;
        $item['available_count'] = ($item['status'] === 'available') ? 1 : 0;
        return $item;
    }
    return null;
}

// Get item by global_id
function getItemByGlobalId($conn, $globalId) {
    if (strpos($globalId, 'EQ') === 0) {
        $id = intval(substr($globalId, 2));
        return getEquipmentById($conn, $id);
    } elseif (strpos($globalId, 'FA') === 0) {
        $id = intval(substr($globalId, 2));
        return getFacilityById($conn, $id);
    } elseif (strpos($globalId, 'VE') === 0) {
        $id = intval(substr($globalId, 2));
        return getVehicleById($conn, $id);
    }
    return null;
}

// ============ VALIDATION FUNCTIONS ============

function checkEquipmentExists($conn, $name, $excludeId = null) {
    $name = mysqli_real_escape_string($conn, trim($name));
    $sql = "SELECT id FROM equipment WHERE name = '$name'";
    if ($excludeId) $sql .= " AND id != " . intval($excludeId);
    $result = mysqli_query($conn, $sql);
    return mysqli_num_rows($result) > 0;
}

function checkVehicleExists($conn, $name, $plateNumber, $excludeId = null) {
    $name = mysqli_real_escape_string($conn, trim($name));
    $plateNumber = mysqli_real_escape_string($conn, trim($plateNumber));
    $sql = "SELECT id FROM vehicles WHERE name = '$name' AND plate_number = '$plateNumber'";
    if ($excludeId) $sql .= " AND id != " . intval($excludeId);
    $result = mysqli_query($conn, $sql);
    return mysqli_num_rows($result) > 0;
}

function checkFacilityExists($conn, $name, $excludeId = null) {
    $name = mysqli_real_escape_string($conn, trim($name));
    $sql = "SELECT id FROM facilities WHERE name = '$name'";
    if ($excludeId) $sql .= " AND id != " . intval($excludeId);
    $result = mysqli_query($conn, $sql);
    return mysqli_num_rows($result) > 0;
}

// ============ ADD FUNCTIONS ============

function addEquipmentItem($conn, $data, $imagePath = null) {
    $name = mysqli_real_escape_string($conn, $data['name']);
    $description = mysqli_real_escape_string($conn, $data['description']);
    $totalQuantity = intval($data['total_quantity']);
    
    $sql = "INSERT INTO equipment (name, description, quantity, status, image, created_at) 
            VALUES (?, ?, ?, 'active', ?, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)];
    }
    // Fix: 4 placeholders -> 4 variables, type string "ssis" (string, string, int, string)
    mysqli_stmt_bind_param($stmt, "ssis", $name, $description, $totalQuantity, $imagePath);
    
    if (mysqli_stmt_execute($stmt)) {
        $equipmentId = mysqli_insert_id($conn);
        
        // Create equipment_status entries
        $statuses = ['available' => $totalQuantity, 'maintenance' => 0, 'lost' => 0];
        foreach ($statuses as $status => $qty) {
            $statusSql = "INSERT INTO equipment_status (equipment_id, status, quantity) VALUES (?, ?, ?)";
            $statusStmt = mysqli_prepare($conn, $statusSql);
            if ($statusStmt) {
                mysqli_stmt_bind_param($statusStmt, "isi", $equipmentId, $status, $qty);
                mysqli_stmt_execute($statusStmt);
            }
        }
        
        return ['success' => true, 'id' => $equipmentId];
    }
    return ['success' => false, 'message' => 'Failed to add: ' . mysqli_error($conn)];
}

function addFacilityItem($conn, $data, $imagePath = null) {
    $name = mysqli_real_escape_string($conn, $data['name']);
    $description = mysqli_real_escape_string($conn, $data['description']);
    $location = mysqli_real_escape_string($conn, $data['location'] ?? '');
    $capacity = intval($data['capacity'] ?? 0);
    
    $sql = "INSERT INTO facilities (name, description, location, capacity, status, image, created_at) 
            VALUES (?, ?, ?, ?, 'available', ?, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)];
    }
    mysqli_stmt_bind_param($stmt, "sssis", $name, $description, $location, $capacity, $imagePath);
    
    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'id' => mysqli_insert_id($conn)];
    }
    return ['success' => false, 'message' => 'Failed to add facility: ' . mysqli_error($conn)];
}

function addVehicleItem($conn, $data, $imagePath = null) {
    $name = mysqli_real_escape_string($conn, $data['name']);
    $description = mysqli_real_escape_string($conn, $data['description']);
    $plateNumber = mysqli_real_escape_string($conn, $data['plate_number'] ?? '');
    $capacity = intval($data['capacity'] ?? 0);
    
    $sql = "INSERT INTO vehicles (name, description, plate_number, capacity, status, image, created_at) 
            VALUES (?, ?, ?, ?, 'available', ?, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)];
    }
    mysqli_stmt_bind_param($stmt, "sssiss", $name, $description, $plateNumber, $capacity, $imagePath);
    
    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'id' => mysqli_insert_id($conn)];
    }
    return ['success' => false, 'message' => 'Failed to add vehicle: ' . mysqli_error($conn)];
}

// ============ UPDATE FUNCTIONS ============

function updateEquipmentItem($conn, $id, $data, $imagePath = null) {
    $id = intval($id);
    $name = mysqli_real_escape_string($conn, $data['name']);
    $description = mysqli_real_escape_string($conn, $data['description']);
    $status = mysqli_real_escape_string($conn, $data['status']);
    
    $oldItem = getEquipmentById($conn, $id);
    
    if ($imagePath !== null) {
        if ($imagePath === '') {
            if ($oldItem && $oldItem['image'] && file_exists(__DIR__ . '/../' . $oldItem['image'])) {
                unlink(__DIR__ . '/../' . $oldItem['image']);
            }
            $sql = "UPDATE equipment SET name = ?, description = ?, status = ?, image = NULL WHERE id = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "sssi", $name, $description, $status, $id);
        } else {
            if ($oldItem && $oldItem['image'] && file_exists(__DIR__ . '/../' . $oldItem['image'])) {
                unlink(__DIR__ . '/../' . $oldItem['image']);
            }
            $sql = "UPDATE equipment SET name = ?, description = ?, status = ?, image = ? WHERE id = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "ssssi", $name, $description, $status, $imagePath, $id);
        }
    } else {
        $sql = "UPDATE equipment SET name = ?, description = ?, status = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sssi", $name, $description, $status, $id);
    }
    
    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Equipment updated successfully'];
    }
    return ['success' => false, 'message' => 'Failed to update: ' . mysqli_error($conn)];
}

function updateFacilityItem($conn, $id, $data, $imagePath = null) {
    $id = intval($id);
    $name = mysqli_real_escape_string($conn, $data['name']);
    $description = mysqli_real_escape_string($conn, $data['description']);
    $location = mysqli_real_escape_string($conn, $data['location'] ?? '');
    $capacity = intval($data['capacity'] ?? 0);
    $status = mysqli_real_escape_string($conn, $data['status']);
    
    $oldItem = getFacilityById($conn, $id);
    
    if ($imagePath !== null) {
        if ($imagePath === '') {
            if ($oldItem && $oldItem['image'] && file_exists(__DIR__ . '/../' . $oldItem['image'])) {
                unlink(__DIR__ . '/../' . $oldItem['image']);
            }
            $sql = "UPDATE facilities SET name = ?, description = ?, location = ?, capacity = ?, status = ?, image = NULL WHERE id = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "sssisi", $name, $description, $location, $capacity, $status, $id);
        } else {
            if ($oldItem && $oldItem['image'] && file_exists(__DIR__ . '/../' . $oldItem['image'])) {
                unlink(__DIR__ . '/../' . $oldItem['image']);
            }
            $sql = "UPDATE facilities SET name = ?, description = ?, location = ?, capacity = ?, status = ?, image = ? WHERE id = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "sssisis", $name, $description, $location, $capacity, $status, $imagePath, $id);
        }
    } else {
        $sql = "UPDATE facilities SET name = ?, description = ?, location = ?, capacity = ?, status = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sssisi", $name, $description, $location, $capacity, $status, $id);
    }
    
    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Facility updated successfully'];
    }
    return ['success' => false, 'message' => 'Failed to update: ' . mysqli_error($conn)];
}

function updateVehicleItem($conn, $id, $data, $imagePath = null) {
    $id = intval($id);
    $name = mysqli_real_escape_string($conn, $data['name']);
    $description = mysqli_real_escape_string($conn, $data['description']);
    $plateNumber = mysqli_real_escape_string($conn, $data['plate_number'] ?? '');
    $capacity = intval($data['capacity'] ?? 0);
    $status = mysqli_real_escape_string($conn, $data['status']);
    
    $oldItem = getVehicleById($conn, $id);
    
    if ($imagePath !== null) {
        if ($imagePath === '') {
            if ($oldItem && $oldItem['image'] && file_exists(__DIR__ . '/../' . $oldItem['image'])) {
                unlink(__DIR__ . '/../' . $oldItem['image']);
            }
            $sql = "UPDATE vehicles SET name = ?, description = ?, plate_number = ?, capacity = ?, status = ?, image = NULL WHERE id = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "sssssi", $name, $description, $plateNumber, $capacity, $status, $id);
        } else {
            if ($oldItem && $oldItem['image'] && file_exists(__DIR__ . '/../' . $oldItem['image'])) {
                unlink(__DIR__ . '/../' . $oldItem['image']);
            }
            $sql = "UPDATE vehicles SET name = ?, description = ?, plate_number = ?, capacity = ?, status = ?, image = ? WHERE id = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "ssssisi", $name, $description, $plateNumber, $capacity, $status, $imagePath, $id);
        }
    } else {
        $sql = "UPDATE vehicles SET name = ?, description = ?, plate_number = ?, capacity = ?, status = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sssssi", $name, $description, $plateNumber, $capacity, $status, $id);
    }
    
    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Vehicle updated successfully'];
    }
    return ['success' => false, 'message' => 'Failed to update: ' . mysqli_error($conn)];
}

// ============ DELETE FUNCTIONS ============

function deleteItem($conn, $id, $type) {
    $id = intval($id);
    $table = '';
    if ($type === 'equipment') {
        $table = 'equipment';
        // Also delete from equipment_status
        $delStatusSql = "DELETE FROM equipment_status WHERE equipment_id = ?";
        $delStatusStmt = mysqli_prepare($conn, $delStatusSql);
        mysqli_stmt_bind_param($delStatusStmt, "i", $id);
        mysqli_stmt_execute($delStatusStmt);
    } elseif ($type === 'facility') {
        $table = 'facilities';
    } else {
        $table = 'vehicles';
    }
    
    $sql = "DELETE FROM $table WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    
    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Deleted successfully'];
    }
    return ['success' => false, 'message' => 'Failed to delete'];
}

// ============ STATUS DISTRIBUTION FUNCTIONS ============

// Update equipment status only - FIXED validation
function updateEquipmentStatusOnly($conn, $equipmentId, $available, $maintenance, $lost) {
    $equipmentId = intval($equipmentId);
    $available = intval($available);
    $maintenance = intval($maintenance);
    $lost = intval($lost);
    
    // Get total quantity from equipment table
    $totalSql = "SELECT quantity FROM equipment WHERE id = ?";
    $totalStmt = mysqli_prepare($conn, $totalSql);
    mysqli_stmt_bind_param($totalStmt, "i", $equipmentId);
    mysqli_stmt_execute($totalStmt);
    $totalResult = mysqli_stmt_get_result($totalStmt);
    $totalRow = mysqli_fetch_assoc($totalResult);
    $totalQuantity = $totalRow['quantity'] ?? 0;
    
    $newTotal = $available + $maintenance + $lost;
    if ($newTotal != $totalQuantity) {
        return false;
    }
    
    // Update equipment_status table
    $statuses = ['available', 'maintenance', 'lost'];
    $quantities = ['available' => $available, 'maintenance' => $maintenance, 'lost' => $lost];
    
    foreach ($statuses as $status) {
        $qty = $quantities[$status];
        $checkSql = "SELECT id FROM equipment_status WHERE equipment_id = ? AND status = ?";
        $checkStmt = mysqli_prepare($conn, $checkSql);
        mysqli_stmt_bind_param($checkStmt, "is", $equipmentId, $status);
        mysqli_stmt_execute($checkStmt);
        $checkResult = mysqli_stmt_get_result($checkStmt);
        
        if (mysqli_num_rows($checkResult) > 0) {
            $updateSql = "UPDATE equipment_status SET quantity = ? WHERE equipment_id = ? AND status = ?";
            $updateStmt = mysqli_prepare($conn, $updateSql);
            mysqli_stmt_bind_param($updateStmt, "iis", $qty, $equipmentId, $status);
            mysqli_stmt_execute($updateStmt);
        } else {
            $insertSql = "INSERT INTO equipment_status (equipment_id, status, quantity) VALUES (?, ?, ?)";
            $insertStmt = mysqli_prepare($conn, $insertSql);
            mysqli_stmt_bind_param($insertStmt, "isi", $equipmentId, $status, $qty);
            mysqli_stmt_execute($insertStmt);
        }
    }
    
    return true;
}



// Add new items - FIXED VERSION
function addEquipmentItems($conn, $equipmentId, $quantity, $reason) {
    $equipmentId = intval($equipmentId);
    $quantity = intval($quantity);
    $reason = mysqli_real_escape_string($conn, trim($reason));
    
    if ($quantity <= 0) {
        return ['success' => false, 'message' => 'Quantity must be greater than 0'];
    }
    
    if (empty($reason)) {
        return ['success' => false, 'message' => 'Please provide a reason for adding items'];
    }
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Get current equipment
        $sql = "SELECT quantity FROM equipment WHERE id = ? FOR UPDATE";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $equipmentId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $equipment = mysqli_fetch_assoc($result);
        
        if (!$equipment) {
            throw new Exception('Equipment not found');
        }
        
        $currentQuantity = intval($equipment['quantity']);
        $newQuantity = $currentQuantity + $quantity;
        
        // Update equipment quantity
        $updateSql = "UPDATE equipment SET quantity = ? WHERE id = ?";
        $updateStmt = mysqli_prepare($conn, $updateSql);
        mysqli_stmt_bind_param($updateStmt, "ii", $newQuantity, $equipmentId);
        
        if (!mysqli_stmt_execute($updateStmt)) {
            throw new Exception('Failed to update equipment quantity');
        }
        
        // Get current status quantities
        $currentStatus = getEquipmentStatusQuantities($conn, $equipmentId);
        $newAvailable = $currentStatus['available'] + $quantity;
        
        // Update equipment_status available quantity
        updateEquipmentStatusOnly($conn, $equipmentId, $newAvailable, $currentStatus['maintenance'], $currentStatus['lost']);
        
        // Log the addition
        $adminId = $_SESSION['user_id'] ?? null;
        $logSql = "INSERT INTO quantity_change_logs (equipment_id, action, quantity_change, new_quantity, affected_status, reason, admin_id, created_at) 
                    VALUES (?, 'add', ?, ?, 'available', ?, ?, NOW())";
        $logStmt = mysqli_prepare($conn, $logSql);
        mysqli_stmt_bind_param($logStmt, "iiisi", $equipmentId, $quantity, $newQuantity, $reason, $adminId);
        
        if (!mysqli_stmt_execute($logStmt)) {
            throw new Exception('Failed to log addition');
        }
        
        mysqli_commit($conn);
        return ['success' => true, 'message' => "Added $quantity new item(s). New total: $newQuantity"];
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
// ============ QUANTITY CHANGE FUNCTIONS ============

function logQuantityChange($conn, $equipmentId, $action, $quantityChange, $newQuantity, $affectedStatus, $reason, $adminId = null) {
    $equipmentId = intval($equipmentId);
    $quantityChange = intval($quantityChange);
    $newQuantity = intval($newQuantity);
    $reason = mysqli_real_escape_string($conn, $reason);
    
    // Get admin ID from session if not provided
    if ($adminId === null && isset($_SESSION['user_id'])) {
        $adminId = intval($_SESSION['user_id']);
    }
    
    $sql = "INSERT INTO quantity_change_logs (equipment_id, action, quantity_change, new_quantity, affected_status, reason, admin_id, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "isiiisi", $equipmentId, $action, $quantityChange, $newQuantity, $affectedStatus, $reason, $adminId);
    return mysqli_stmt_execute($stmt);
}

function updateEquipmentQuantity($conn, $equipmentId, $newQuantity, $reason, $adminId = null) {
    $equipmentId = intval($equipmentId);
    $newQuantity = intval($newQuantity);
    $reason = trim($reason);
    
    // Validate reason
    if (empty($reason)) {
        return ['success' => false, 'message' => 'Please provide a reason for the quantity change.'];
    }
    
    // Get current equipment
    $equipment = getEquipmentById($conn, $equipmentId);
    if (!$equipment) {
        return ['success' => false, 'message' => 'Equipment not found'];
    }
    
    $currentQuantity = intval($equipment['quantity']);
    
    if ($newQuantity < 1) {
        return ['success' => false, 'message' => 'Quantity cannot be less than 1'];
    }
    
    if ($newQuantity == $currentQuantity) {
        return ['success' => false, 'message' => 'Quantity is the same. No changes made.'];
    }
    
    $diff = $newQuantity - $currentQuantity;
    
    if ($diff > 0) {
        // ADDING QUANTITY - Add to available status
        $currentStatus = getEquipmentStatusQuantities($conn, $equipmentId);
        $newAvailable = $currentStatus['available'] + $diff;
        $affectedStatus = 'available';
        
        // Update equipment quantity
        $sql = "UPDATE equipment SET quantity = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $newQuantity, $equipmentId);
        
        if (mysqli_stmt_execute($stmt)) {
            // Update equipment_status available quantity
            updateEquipmentStatusOnly($conn, $equipmentId, $newAvailable, $currentStatus['maintenance'], $currentStatus['lost']);
            
            // Log the change
            logQuantityChange($conn, $equipmentId, 'add', $diff, $newQuantity, $affectedStatus, $reason, $adminId);
            
            return ['success' => true, 'message' => "Added $diff item(s). New total quantity: $newQuantity. Reason: " . substr($reason, 0, 100)];
        }
        
    } else {
        // REMOVING QUANTITY - Ask which status to deduct from
        $decrease = abs($diff);
        $currentStatus = getEquipmentStatusQuantities($conn, $equipmentId);
        
        // Return available statuses for selection
        return [
            'success' => false, 
            'needs_status_selection' => true,
            'decrease_amount' => $decrease,
            'current_available' => $currentStatus['available'],
            'current_maintenance' => $currentStatus['maintenance'],
            'current_lost' => $currentStatus['lost'],
            'message' => 'Please select which status to deduct the quantity from.'
        ];
    }
    
    return ['success' => false, 'message' => 'Failed to update quantity: ' . mysqli_error($conn)];
}

function updateEquipmentQuantityWithStatus($conn, $equipmentId, $newQuantity, $deductFrom, $reason, $adminId = null) {
    $equipmentId = intval($equipmentId);
    $newQuantity = intval($newQuantity);
    $deductFrom = mysqli_real_escape_string($conn, $deductFrom);
    $reason = trim($reason);
    
    // Validate reason
    if (empty($reason)) {
        return ['success' => false, 'message' => 'Please provide a reason for reducing quantity.'];
    }
    
    // Validate deductFrom
    if (!in_array($deductFrom, ['available', 'maintenance', 'lost'])) {
        return ['success' => false, 'message' => 'Invalid status selection.'];
    }
    
    // Get current equipment
    $equipment = getEquipmentById($conn, $equipmentId);
    if (!$equipment) {
        return ['success' => false, 'message' => 'Equipment not found'];
    }
    
    $currentQuantity = intval($equipment['quantity']);
    
    if ($newQuantity < 1) {
        return ['success' => false, 'message' => 'Quantity cannot be less than 1'];
    }
    
    if ($newQuantity == $currentQuantity) {
        return ['success' => false, 'message' => 'Quantity is the same. No changes made.'];
    }
    
    $diff = $newQuantity - $currentQuantity;
    
    if ($diff >= 0) {
        return ['success' => false, 'message' => 'This function is only for reducing quantity.'];
    }
    
    $decrease = abs($diff);
    $currentStatus = getEquipmentStatusQuantities($conn, $equipmentId);
    
    // Check if selected status has enough quantity
    if ($currentStatus[$deductFrom] < $decrease) {
        return [
            'success' => false, 
            'message' => "Not enough items in '$deductFrom' status. Available: {$currentStatus[$deductFrom]}, Need: $decrease"
        ];
    }
    
    // Update equipment quantity
    $sql = "UPDATE equipment SET quantity = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $newQuantity, $equipmentId);
    
    if (mysqli_stmt_execute($stmt)) {
        // Update the selected status quantity
        $newSelectedStatusQty = $currentStatus[$deductFrom] - $decrease;
        
        if ($deductFrom === 'available') {
            updateEquipmentStatusOnly($conn, $equipmentId, $newSelectedStatusQty, $currentStatus['maintenance'], $currentStatus['lost']);
        } elseif ($deductFrom === 'maintenance') {
            updateEquipmentStatusOnly($conn, $equipmentId, $currentStatus['available'], $newSelectedStatusQty, $currentStatus['lost']);
        } else {
            updateEquipmentStatusOnly($conn, $equipmentId, $currentStatus['available'], $currentStatus['maintenance'], $newSelectedStatusQty);
        }
        
        // Log the change
        logQuantityChange($conn, $equipmentId, 'remove', $decrease, $newQuantity, $deductFrom, $reason, $adminId);
        
        return ['success' => true, 'message' => "Removed $decrease item(s) from '$deductFrom'. New total quantity: $newQuantity. Reason: " . substr($reason, 0, 100)];
    }
    
    return ['success' => false, 'message' => 'Failed to update quantity: ' . mysqli_error($conn)];
}
// ============ BOOKING FUNCTIONS ============

function getAllBookings($conn, $status = null, $type = null, $page = 1, $perPage = 10) {
    $offset = ($page - 1) * $perPage;
    
    $sql = "SELECT r.*, 
            CASE 
                WHEN r.request_type = 'equipment' THEN e.name
                WHEN r.request_type = 'facility' THEN f.name
                WHEN r.request_type = 'vehicle' THEN v.name
            END as equipment_name,
            r.request_type as category,
            res.first_name, res.last_name, res.email, res.phone_number as phone, res.address,
            -- Equipment specific fields
            eq_req.quantity as equipment_quantity,
            eq_req.duration_days as equipment_duration,
            eq_req.booking_date as equipment_booking_date,
            eq_req.return_date as equipment_return_date,
            -- Facility specific fields
            fac_req.start_time as facility_start_time,
            fac_req.end_time as facility_end_time,
            fac_req.duration_hours as facility_duration_hours,
            fac_req.expected_attendees,
            fac_req.event_type,
            -- Vehicle specific fields
            veh_req.vehicle_capacity,
            veh_req.trip_date as vehicle_trip_date,
            veh_req.pickup_time,
            veh_req.pickup_location,
            veh_req.dropoff_location,
            veh_req.passenger_count,
            veh_req.estimated_hours as vehicle_estimated_hours,
            veh_req.special_requests
            FROM requests r
            LEFT JOIN equipment e ON r.request_type = 'equipment' AND r.item_global_id = CONCAT('EQ', LPAD(e.id, 6, '0'))
            LEFT JOIN facilities f ON r.request_type = 'facility' AND r.item_global_id = CONCAT('FA', LPAD(f.id, 6, '0'))
            LEFT JOIN vehicles v ON r.request_type = 'vehicle' AND r.item_global_id = CONCAT('VE', LPAD(v.id, 6, '0'))
            LEFT JOIN equipment_requests eq_req ON r.request_type = 'equipment' AND r.id = eq_req.request_id
            LEFT JOIN facility_requests fac_req ON r.request_type = 'facility' AND r.id = fac_req.request_id
            LEFT JOIN vehicle_requests veh_req ON r.request_type = 'vehicle' AND r.id = veh_req.request_id
            JOIN resident res ON r.resident_id = res.id";
    
    $countSql = "SELECT COUNT(*) as total FROM requests r JOIN resident res ON r.resident_id = res.id";
    
    $whereConditions = [];
    $params = [];
    $types = "";
    
    if ($status && $status !== 'all') {
        $whereConditions[] = "r.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    if ($type && $type !== 'all') {
        $whereConditions[] = "r.request_type = ?";
        $params[] = $type;
        $types .= "s";
    }
    
    if (!empty($whereConditions)) {
        $whereClause = " WHERE " . implode(" AND ", $whereConditions);
        $sql .= $whereClause;
        $countSql .= $whereClause;
    }
    
    $sql .= " ORDER BY r.request_date DESC LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = mysqli_prepare($conn, $sql);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $bookings = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            // Build custom_data JSON for easy parsing in JS
            $custom_data = [];
            if ($row['request_type'] === 'equipment') {
                $custom_data = [
                    'quantity' => $row['equipment_quantity'],
                    'duration_days' => $row['equipment_duration'],
                    'booking_date' => $row['equipment_booking_date'],
                    'return_date' => $row['equipment_return_date']
                ];
                // Override the main fields with child table data
                $row['quantity'] = $row['equipment_quantity'] ?: $row['quantity'];
            } elseif ($row['request_type'] === 'facility') {
                $custom_data = [
                    'start_time' => $row['facility_start_time'],
                    'end_time' => $row['facility_end_time'],
                    'duration_hours' => $row['facility_duration_hours'],
                    'expected_attendees' => $row['expected_attendees'],
                    'event_type' => $row['event_type']
                ];
                // Override start/end times from facility_requests
                if ($row['facility_start_time']) {
                    $row['start_datetime'] = date('Y-m-d H:i:s', strtotime($row['start_datetime']));
                }
            } elseif ($row['request_type'] === 'vehicle') {
                $custom_data = [
                    'vehicle_capacity' => $row['vehicle_capacity'],
                    'trip_date' => $row['vehicle_trip_date'],
                    'pickup_time' => $row['pickup_time'],
                    'pickup_location' => $row['pickup_location'],
                    'dropoff_location' => $row['dropoff_location'],
                    'passenger_count' => $row['passenger_count'],
                    'estimated_hours' => $row['vehicle_estimated_hours'],
                    'special_requests' => $row['special_requests']
                ];
            }
            $row['custom_data'] = json_encode($custom_data);
            $bookings[] = $row;
        }
    }
    
    $countStmt = mysqli_prepare($conn, $countSql);
    if (!empty($params) && count($params) > 2) {
        $countParams = array_slice($params, 0, -2);
        $countTypes = substr($types, 0, -2);
        if (!empty($countParams)) {
            mysqli_stmt_bind_param($countStmt, $countTypes, ...$countParams);
        }
    }
    mysqli_stmt_execute($countStmt);
    $countResult = mysqli_stmt_get_result($countStmt);
    $totalRecords = $countResult ? mysqli_fetch_assoc($countResult)['total'] : 0;
    $totalPages = ceil($totalRecords / $perPage);
    
    return ['bookings' => $bookings, 'totalPages' => $totalPages, 'currentPage' => $page, 'totalRecords' => $totalRecords];
}

function getBookingById($conn, $id) {
    $id = intval($id);
    $sql = "SELECT r.*, 
            CASE 
                WHEN r.request_type = 'equipment' THEN e.name
                WHEN r.request_type = 'facility' THEN f.name
                WHEN r.request_type = 'vehicle' THEN v.name
            END as equipment_name,
            r.request_type as category,
            res.first_name, res.last_name, res.email, res.phone_number as phone, res.address,
            -- Equipment specific fields
            eq_req.quantity as equipment_quantity,
            eq_req.duration_days as equipment_duration,
            eq_req.booking_date as equipment_booking_date,
            eq_req.return_date as equipment_return_date,
            eq_req.notes as equipment_notes,
            -- Facility specific fields
            fac_req.start_time as facility_start_time,
            fac_req.end_time as facility_end_time,
            fac_req.duration_hours as facility_duration_hours,
            fac_req.expected_attendees,
            fac_req.event_type,
            fac_req.notes as facility_notes,
            -- Vehicle specific fields
            veh_req.vehicle_capacity,
            veh_req.trip_date as vehicle_trip_date,
            veh_req.pickup_time,
            veh_req.pickup_location,
            veh_req.dropoff_location,
            veh_req.passenger_count,
            veh_req.estimated_hours as vehicle_estimated_hours,
            veh_req.purpose as vehicle_purpose,
            veh_req.special_requests
            FROM requests r
            LEFT JOIN equipment e ON r.request_type = 'equipment' AND r.item_global_id = CONCAT('EQ', LPAD(e.id, 6, '0'))
            LEFT JOIN facilities f ON r.request_type = 'facility' AND r.item_global_id = CONCAT('FA', LPAD(f.id, 6, '0'))
            LEFT JOIN vehicles v ON r.request_type = 'vehicle' AND r.item_global_id = CONCAT('VE', LPAD(v.id, 6, '0'))
            LEFT JOIN equipment_requests eq_req ON r.request_type = 'equipment' AND r.id = eq_req.request_id
            LEFT JOIN facility_requests fac_req ON r.request_type = 'facility' AND r.id = fac_req.request_id
            LEFT JOIN vehicle_requests veh_req ON r.request_type = 'vehicle' AND r.id = veh_req.request_id
            JOIN resident res ON r.resident_id = res.id
            WHERE r.id = ?";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $booking = mysqli_fetch_assoc($result);
        
        // Build custom_data JSON
        $custom_data = [];
        if ($booking['request_type'] === 'equipment') {
            $custom_data = [
                'quantity' => $booking['equipment_quantity'],
                'duration_days' => $booking['equipment_duration'],
                'booking_date' => $booking['equipment_booking_date'],
                'return_date' => $booking['equipment_return_date'],
                'notes' => $booking['equipment_notes']
            ];
            $booking['quantity'] = $booking['equipment_quantity'] ?: $booking['quantity'];
        } elseif ($booking['request_type'] === 'facility') {
            $custom_data = [
                'start_time' => $booking['facility_start_time'],
                'end_time' => $booking['facility_end_time'],
                'duration_hours' => $booking['facility_duration_hours'],
                'expected_attendees' => $booking['expected_attendees'],
                'event_type' => $booking['event_type'],
                'notes' => $booking['facility_notes']
            ];
        } elseif ($booking['request_type'] === 'vehicle') {
            $custom_data = [
                'vehicle_capacity' => $booking['vehicle_capacity'],
                'trip_date' => $booking['vehicle_trip_date'],
                'pickup_time' => $booking['pickup_time'],
                'pickup_location' => $booking['pickup_location'],
                'dropoff_location' => $booking['dropoff_location'],
                'passenger_count' => $booking['passenger_count'],
                'estimated_hours' => $booking['vehicle_estimated_hours'],
                'special_requests' => $booking['special_requests']
            ];
            if ($booking['vehicle_purpose']) {
                $booking['purpose'] = $booking['vehicle_purpose'];
            }
        }
        $booking['custom_data'] = json_encode($custom_data);
        
        // Ensure request_type is set
        if (!isset($booking['request_type']) && isset($booking['category'])) {
            $booking['request_type'] = $booking['category'];
        }
        return $booking;
    }
    return null;
}

function updateBookingStatus($conn, $id, $status, $admin_notes = null) {
    $id = intval($id);
    $status = mysqli_real_escape_string($conn, $status);
    $admin_notes = $admin_notes ? mysqli_real_escape_string($conn, $admin_notes) : null;
    
    // Get the current admin's info
    $admin_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
    $admin_name = '';
    
    if ($admin_id > 0) {
        $adminSql = "SELECT full_name, username FROM admin WHERE id = $admin_id";
        $adminResult = mysqli_query($conn, $adminSql);
        if ($adminResult && $row = mysqli_fetch_assoc($adminResult)) {
            $admin_name = $row['full_name'] ?: $row['username'];
        }
    }
    
    // Build the SQL based on status
    $sql = "UPDATE requests SET status = ?, admin_notes = ?, processed_at = NOW()";
    
    if ($status === 'approved') {
        $sql .= ", approved_by = $admin_id, approved_by_name = '$admin_name', approved_at = NOW()";
    } elseif ($status === 'borrowed') {
        $sql .= ", borrowed_at = NOW()";
    } elseif ($status === 'rejected') {
        $sql .= ", rejected_by = $admin_id, rejected_by_name = '$admin_name', rejected_at = NOW()";
    } elseif ($status === 'returned') {
        $sql .= ", completed_at = NOW()";
    }
    
    $sql .= " WHERE id = ?";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ssi", $status, $admin_notes, $id);
    
    if (mysqli_stmt_execute($stmt)) {
        return ['success' => true, 'message' => 'Booking status updated successfully',
                'processed_by' => $admin_name, 'status' => $status];
    }
    return ['success' => false, 'message' => 'Failed to update status: ' . mysqli_error($conn)];
}



// ============ DASHBOARD FUNCTIONS ============
// Get change logs for equipment - FIXED to show correct data
function getChangeLogs($conn, $equipmentId) {
    $equipmentId = intval($equipmentId);
    $sql = "SELECT * FROM quantity_change_logs WHERE equipment_id = ? ORDER BY created_at DESC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $equipmentId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $logs = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $logs[] = $row;
    }
    return $logs;
}

// Transfer items between statuses
function transferItems($conn, $equipmentId, $fromStatus, $toStatus, $quantity, $reason, $totalQuantity) {
    $equipmentId = intval($equipmentId);
    $quantity = intval($quantity);
    $reason = mysqli_real_escape_string($conn, trim($reason));
    
    if (empty($reason)) {
        return ['success' => false, 'message' => 'Please provide a reason for the transfer.'];
    }
    
    // Get current status quantities
    $currentStatus = getEquipmentStatusQuantities($conn, $equipmentId);
    
    // Check if enough items in source
    if ($currentStatus[$fromStatus] < $quantity) {
        return ['success' => false, 'message' => "Not enough items in " . ucfirst($fromStatus) . ". Available: {$currentStatus[$fromStatus]}"];
    }
    
    // Calculate new quantities
    $newFrom = $currentStatus[$fromStatus] - $quantity;
    $newTo = $currentStatus[$toStatus] + $quantity;
    
    // Update statuses
    if ($fromStatus === 'available' && $toStatus === 'maintenance') {
        updateEquipmentStatusOnly($conn, $equipmentId, $newFrom, $newTo, $currentStatus['lost']);
    } elseif ($fromStatus === 'available' && $toStatus === 'lost') {
        updateEquipmentStatusOnly($conn, $equipmentId, $newFrom, $currentStatus['maintenance'], $newTo);
    } elseif ($fromStatus === 'maintenance' && $toStatus === 'available') {
        updateEquipmentStatusOnly($conn, $equipmentId, $newTo, $newFrom, $currentStatus['lost']);
    } elseif ($fromStatus === 'lost' && $toStatus === 'available') {
        updateEquipmentStatusOnly($conn, $equipmentId, $newTo, $currentStatus['maintenance'], $newFrom);
    }
    
    // Log the transfer
    $sql = "INSERT INTO quantity_change_logs (equipment_id, action, quantity_change, new_quantity, transfer_from, transfer_to, reason, admin_id, created_at) 
            VALUES (?, 'transfer', ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    $adminId = $_SESSION['user_id'] ?? null;
    $newQuantity = $currentStatus['available'] + $currentStatus['maintenance'] + $currentStatus['lost'];
    mysqli_stmt_bind_param($stmt, "iiisssi", $equipmentId, $quantity, $newQuantity, $fromStatus, $toStatus, $reason, $adminId);
    mysqli_stmt_execute($stmt);
    
    return ['success' => true, 'message' => "Successfully transferred $quantity item(s) from " . ucfirst($fromStatus) . " to " . ucfirst($toStatus)];
}

// Undo a change - FIXED VERSION
// Undo a change - FIXED to prevent negative totals
function undoChange($conn, $logId) {
    $logId = intval($logId);
    
    // Get the log entry
    $sql = "SELECT * FROM quantity_change_logs WHERE id = ? AND is_undone = 0";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $logId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $log = mysqli_fetch_assoc($result);
    
    if (!$log) {
        return ['success' => false, 'message' => 'Log not found or already undone'];
    }
    
    $equipmentId = $log['equipment_id'];
    $currentStatus = getEquipmentStatusQuantities($conn, $equipmentId);
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        if ($log['action'] === 'add') {
            // Reverse add: remove from available and decrease total quantity in equipment table
            $newAvailable = $currentStatus['available'] - $log['quantity_change'];
            
            // Prevent negative available
            if ($newAvailable < 0) {
                throw new Exception('Cannot undo: Would result in negative available items');
            }
            
            // Get current total quantity from equipment table
            $totalSql = "SELECT quantity FROM equipment WHERE id = ? FOR UPDATE";
            $totalStmt = mysqli_prepare($conn, $totalSql);
            mysqli_stmt_bind_param($totalStmt, "i", $equipmentId);
            mysqli_stmt_execute($totalStmt);
            $totalResult = mysqli_stmt_get_result($totalStmt);
            $totalRow = mysqli_fetch_assoc($totalResult);
            $newTotal = $totalRow['quantity'] - $log['quantity_change'];
            
            // Prevent negative total
            if ($newTotal < 0) {
                throw new Exception('Cannot undo: Would result in negative total quantity');
            }
            
            // Update equipment total quantity
            $updateTotalSql = "UPDATE equipment SET quantity = ? WHERE id = ?";
            $updateTotalStmt = mysqli_prepare($conn, $updateTotalSql);
            mysqli_stmt_bind_param($updateTotalStmt, "ii", $newTotal, $equipmentId);
            if (!mysqli_stmt_execute($updateTotalStmt)) {
                throw new Exception('Failed to update equipment quantity');
            }
            
            // Update status distribution
            if (!updateEquipmentStatusOnly($conn, $equipmentId, $newAvailable, $currentStatus['maintenance'], $currentStatus['lost'])) {
                throw new Exception('Failed to update status distribution');
            }
            
        } elseif ($log['action'] === 'deduct') {
            // Reverse deduct: move items back from lost/maintenance to available
            $fromStatus = $log['transfer_to']; // Where items were moved to (lost or maintenance)
            $quantity = $log['quantity_change'];
            
            $newAvailable = $currentStatus['available'] + $quantity;
            
            if ($fromStatus === 'maintenance') {
                $newMaintenance = $currentStatus['maintenance'] - $quantity;
                if ($newMaintenance < 0) $newMaintenance = 0;
                updateEquipmentStatusOnly($conn, $equipmentId, $newAvailable, $newMaintenance, $currentStatus['lost']);
            } else { // lost
                $newLost = $currentStatus['lost'] - $quantity;
                if ($newLost < 0) $newLost = 0;
                updateEquipmentStatusOnly($conn, $equipmentId, $newAvailable, $currentStatus['maintenance'], $newLost);
            }
        } elseif ($log['action'] === 'undo') {
            throw new Exception('Cannot undo an undo action');
        }
        
        // Mark log as undone
        $adminId = $_SESSION['user_id'] ?? null;
        $updateLogSql = "UPDATE quantity_change_logs SET is_undone = 1, undone_by = ?, undone_at = NOW() WHERE id = ?";
        $updateLogStmt = mysqli_prepare($conn, $updateLogSql);
        mysqli_stmt_bind_param($updateLogStmt, "ii", $adminId, $logId);
        if (!mysqli_stmt_execute($updateLogStmt)) {
            throw new Exception('Failed to mark log as undone');
        }
        
        // Create undo record
        $undoSql = "INSERT INTO quantity_change_logs (equipment_id, action, quantity_change, new_quantity, transfer_from, transfer_to, reason, admin_id, original_log_id, created_at) 
                    VALUES (?, 'undo', ?, ?, ?, ?, ?, ?, ?, NOW())";
        $undoStmt = mysqli_prepare($conn, $undoSql);
        $undoReason = "Undid: " . substr($log['reason'], 0, 200);
        $newTotal = $currentStatus['available'] + $currentStatus['maintenance'] + $currentStatus['lost'];
        mysqli_stmt_bind_param($undoStmt, "iiisssii", $equipmentId, $log['quantity_change'], $newTotal, 
                              $log['transfer_to'], $log['transfer_from'], $undoReason, $adminId, $logId);
        if (!mysqli_stmt_execute($undoStmt)) {
            throw new Exception('Failed to create undo record');
        }
        
        // Commit transaction
        mysqli_commit($conn);
        
        return ['success' => true, 'message' => 'Change undone successfully'];
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}



// Deduct items - Reason is now optional
function deductItems($conn, $equipmentId, $quantity, $toStatus, $reason) {
    $equipmentId = intval($equipmentId);
    $quantity = intval($quantity);
    $toStatus = mysqli_real_escape_string($conn, $toStatus);
    $reason = mysqli_real_escape_string($conn, trim($reason));
    
    if (!in_array($toStatus, ['maintenance', 'lost'])) {
        return ['success' => false, 'message' => 'Invalid target status'];
    }
    
    // Reason is now optional - default to empty string if not provided
    if (empty($reason)) {
        $reason = 'No reason provided';
    }
    
    if ($quantity <= 0) {
        return ['success' => false, 'message' => 'Quantity must be greater than 0'];
    }
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Get current status quantities
        $currentStatus = getEquipmentStatusQuantities($conn, $equipmentId);
        
        if ($currentStatus['available'] < $quantity) {
            throw new Exception("Not enough available items. Available: {$currentStatus['available']}, Requested: $quantity");
        }
        
        $newAvailable = $currentStatus['available'] - $quantity;
        $newTarget = $currentStatus[$toStatus] + $quantity;
        
        if ($toStatus === 'maintenance') {
            updateEquipmentStatusOnly($conn, $equipmentId, $newAvailable, $newTarget, $currentStatus['lost']);
        } else {
            updateEquipmentStatusOnly($conn, $equipmentId, $newAvailable, $currentStatus['maintenance'], $newTarget);
        }
        
        $totalSql = "SELECT quantity FROM equipment WHERE id = ?";
        $totalStmt = mysqli_prepare($conn, $totalSql);
        mysqli_stmt_bind_param($totalStmt, "i", $equipmentId);
        mysqli_stmt_execute($totalStmt);
        $totalResult = mysqli_stmt_get_result($totalStmt);
        $totalRow = mysqli_fetch_assoc($totalResult);
        $totalQuantity = $totalRow['quantity'] ?? 0;
        
        $adminId = $_SESSION['user_id'] ?? null;
        
        $sql = "INSERT INTO quantity_change_logs (equipment_id, action, quantity_change, new_quantity, transfer_to, reason, admin_id, created_at) 
                VALUES (?, 'deduct', ?, ?, ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "iiissi", $equipmentId, $quantity, $totalQuantity, $toStatus, $reason, $adminId);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('Failed to log deduction: ' . mysqli_error($conn));
        }
        
        mysqli_commit($conn);
        
        $statusName = $toStatus === 'maintenance' ? 'Maintenance' : 'Lost/Damaged';
        return ['success' => true, 'message' => "Successfully deducted $quantity item(s) to $statusName"];
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function getEquipmentCounts($conn) {
    $total = 0;
    $available = 0;
    $borrowed = 0;
    $maintenance = 0;
    $categories = [];
    
    // Equipment
    $eqResult = mysqli_query($conn, "SELECT SUM(quantity) as total FROM equipment WHERE status != 'inactive'");
    if ($eqResult) $total += mysqli_fetch_assoc($eqResult)['total'] ?? 0;
    
    $availEq = mysqli_query($conn, "SELECT SUM(quantity) as avail FROM equipment_status WHERE status = 'available'");
    if ($availEq) $available += mysqli_fetch_assoc($availEq)['avail'] ?? 0;
    
    $maintEq = mysqli_query($conn, "SELECT SUM(quantity) as maint FROM equipment_status WHERE status = 'maintenance'");
    if ($maintEq) $maintenance += mysqli_fetch_assoc($maintEq)['maint'] ?? 0;
    
    // Facilities
    $facResult = mysqli_query($conn, "SELECT COUNT(*) as total FROM facilities WHERE status != 'inactive'");
    if ($facResult) $total += mysqli_fetch_assoc($facResult)['total'] ?? 0;
    
    $availFac = mysqli_query($conn, "SELECT COUNT(*) as avail FROM facilities WHERE status = 'available'");
    if ($availFac) $available += mysqli_fetch_assoc($availFac)['avail'] ?? 0;
    
    $maintFac = mysqli_query($conn, "SELECT COUNT(*) as maint FROM facilities WHERE status = 'maintenance'");
    if ($maintFac) $maintenance += mysqli_fetch_assoc($maintFac)['maint'] ?? 0;
    
    // Vehicles
    $vehResult = mysqli_query($conn, "SELECT COUNT(*) as total FROM vehicles WHERE status != 'inactive'");
    if ($vehResult) $total += mysqli_fetch_assoc($vehResult)['total'] ?? 0;
    
    $availVeh = mysqli_query($conn, "SELECT COUNT(*) as avail FROM vehicles WHERE status = 'available'");
    if ($availVeh) $available += mysqli_fetch_assoc($availVeh)['avail'] ?? 0;
    
    $maintVeh = mysqli_query($conn, "SELECT COUNT(*) as maint FROM vehicles WHERE status = 'maintenance'");
    if ($maintVeh) $maintenance += mysqli_fetch_assoc($maintVeh)['maint'] ?? 0;
    
    // Borrowed from requests
    $borrowedResult = mysqli_query($conn, "SELECT SUM(quantity) as borrowed FROM requests WHERE status IN ('approved', 'borrowed')");
    if ($borrowedResult) $borrowed = mysqli_fetch_assoc($borrowedResult)['borrowed'] ?? 0;
    
    // Categories
    $eqCat = mysqli_query($conn, "SELECT 'Equipment' as category, SUM(quantity) as count FROM equipment");
    if ($eqCat) {
        $row = mysqli_fetch_assoc($eqCat);
        if ($row['count'] > 0) $categories[] = $row;
    }
    
    $facCat = mysqli_query($conn, "SELECT 'Facility' as category, COUNT(*) as count FROM facilities WHERE status != 'inactive'");
    if ($facCat) {
        $row = mysqli_fetch_assoc($facCat);
        if ($row['count'] > 0) $categories[] = $row;
    }
    
    $vehCat = mysqli_query($conn, "SELECT 'Vehicle' as category, COUNT(*) as count FROM vehicles WHERE status != 'inactive'");
    if ($vehCat) {
        $row = mysqli_fetch_assoc($vehCat);
        if ($row['count'] > 0) $categories[] = $row;
    }
    
    return [
        'total' => $total, 
        'available' => $available, 
        'borrowed' => $borrowed, 
        'maintenance' => $maintenance,
        'categories' => $categories
    ];
}

function getPendingBookingsCount($conn) {
    $sql = "SELECT COUNT(*) as count FROM requests WHERE status = 'pending'";
    $result = mysqli_query($conn, $sql);
    return $result ? mysqli_fetch_assoc($result)['count'] : 0;
}

function getRecentBookings($conn, $limit = 5) {
    $limit = intval($limit);
    $sql = "SELECT r.*, 
            CASE 
                WHEN r.request_type = 'equipment' THEN e.name
                WHEN r.request_type = 'facility' THEN f.name
                WHEN r.request_type = 'vehicle' THEN v.name
            END as equipment_name,
            res.first_name, res.last_name
            FROM requests r
            LEFT JOIN equipment e ON r.request_type = 'equipment' AND r.item_id = e.id
            LEFT JOIN facilities f ON r.request_type = 'facility' AND r.item_id = f.id
            LEFT JOIN vehicles v ON r.request_type = 'vehicle' AND r.item_id = v.id
            JOIN resident res ON r.resident_id = res.id
            ORDER BY r.created_at DESC LIMIT $limit";
    
    $result = mysqli_query($conn, $sql);
    $bookings = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $bookings[] = $row;
        }
    }
    return $bookings;
}

function getEquipmentBookings($conn, $itemId, $type) {
    $type = mysqli_real_escape_string($conn, $type);
    $sql = "SELECT r.*, res.first_name, res.last_name, res.email
            FROM requests r
            JOIN resident res ON r.resident_id = res.id
            WHERE r.request_type = ? AND r.item_id = ?
            ORDER BY r.request_date DESC";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "si", $type, $itemId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $bookings = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $bookings[] = $row;
        }
    }
    return $bookings;
}

function getPredefinedItems($conn, $category) {
    return getPredefinedItemsByCategory($category);
}

function getEquipmentItems($conn, $equipmentId) {
    return [];
}

function getAvailableItems($conn, $equipmentId) {
    return [];
}

function getEquipmentItemById($conn, $id) {
    return null;
}

// ============ IMAGE UPLOAD ============

function uploadEquipmentImage($file) {
    $targetDir = __DIR__ . '/../uploads/equipment/';
    if (!file_exists($targetDir)) mkdir($targetDir, 0777, true);
    
    $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($file['name']));
    $targetFile = $targetDir . $fileName;
    $imageFileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
    
    $check = getimagesize($file['tmp_name']);
    if ($check === false) return ['success' => false, 'message' => 'File is not an image.'];
    if ($file['size'] > 5000000) return ['success' => false, 'message' => 'File is too large. Max 5MB.'];
    
    $allowedFormats = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'jfif'];
    if (!in_array($imageFileType, $allowedFormats)) return ['success' => false, 'message' => 'Only JPG, JPEG, PNG, GIF & WEBP files are allowed.'];
    
    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        return ['success' => true, 'path' => 'uploads/equipment/' . $fileName];
    }
    return ['success' => false, 'message' => 'Failed to upload image.'];
}
?>