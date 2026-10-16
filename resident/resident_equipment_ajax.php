<?php
// resident_equipment_ajax.php - Updated with auto-approval, transaction safety
// Cancel/pending functionality removed

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/vehicle_flow.php';


header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/vehicle_debug.log');

// Append a header line per request so the log is easy to read
$__DBG_LOG = __DIR__ . '/vehicle_debug.log';
function vdbg($msg) {
    global $__DBG_LOG;
    @file_put_contents(
        $__DBG_LOG,
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL,
        FILE_APPEND
    );
}
function vdbg_arr($label, $arr) {
    vdbg($label . ': ' . json_encode($arr, JSON_UNESCAPED_UNICODE));
}

vdbg('===== ' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' ' . ($_SERVER['REQUEST_URI'] ?? '?') . ' =====');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'resident') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$resident_id = $_SESSION['user_id'];

// Notice/office-hour rules below depend on local time (barangay is in the Philippines)
date_default_timezone_set('Asia/Manila');

// ============ VEHICLE REQUEST POLICY ============
// A vehicle is NOT a rental: it is released for a valid purpose only.
//   urgent    -> any hour, same-day, approved immediately (a conflict goes to staff as URGENT)
//   flexible  -> any hour, needs 2h notice, auto-approved when the vehicle is free
//   scheduled -> office hours only, needs 24h notice, auto-approved when the vehicle is free
//   other     -> always PENDING, the captain / secretary must approve
const VEHICLE_BUFFER_MINUTES = 30;   // gap kept before and after every trip (driver, fuel, travel back)
const VEHICLE_MAX_HOURS      = 8;
const VEHICLE_OFFICE_START   = '06:00';
const VEHICLE_OFFICE_END     = '18:00';

function vehicleTierRules() {
    return [
        'urgent'    => ['notice_hours' => 0,  'office_only' => false],
        'flexible'  => ['notice_hours' => 2,  'office_only' => false],
        'scheduled' => ['notice_hours' => 24, 'office_only' => true],
        'other'     => ['notice_hours' => 24, 'office_only' => true],
    ];
}

// code => [label, tier]   (keep in sync with VEHICLE_PURPOSES in resident_equipment.js)
function vehiclePurposes() {
    return [
        'emergency_medical'     => ['Emergency medical transportation', 'urgent'],
        'evacuation_disaster'   => ['Evacuation due to flood, fire, or other disaster', 'urgent'],
        'evacuation_center'     => ['Transportation to an evacuation center', 'urgent'],
        'urgent_family'         => ['Urgent family or humanitarian emergency', 'urgent'],

        'hospital_appointment'  => ['Hospital appointment or checkup', 'flexible'],
        'hospital_admission'    => ['Hospital admission or discharge', 'flexible'],
        'dialysis_treatment'    => ['Dialysis or regular medical treatment', 'flexible'],
        'laboratory_procedure'  => ['Laboratory tests or medical procedures', 'flexible'],
        'prenatal_checkup'      => ['Prenatal checkup', 'flexible'],
        'senior_medical'        => ['Senior citizen medical appointment', 'flexible'],
        'pharmacy_pickup'       => ['Pharmacy or medicine pickup for an ill resident', 'flexible'],
        'funeral'               => ['Funeral-related transportation', 'flexible'],

        'pwd_transport'         => ['Transportation for a person with disability', 'scheduled'],
        'limited_mobility'      => ['Transportation for a resident with limited mobility', 'scheduled'],
        'indigent_services'     => ['Transportation for indigent residents needing essential services', 'scheduled'],
        'government_office'     => ['Government office appointment', 'scheduled'],
        'dswd_mswdo'            => ['DSWD or MSWDO assistance appointment', 'scheduled'],
        'barangay_hearing'      => ['Barangay mediation or hearing', 'scheduled'],
        'barangay_document'     => ['Barangay document-related appointment', 'scheduled'],

        'other'                 => ['Other valid personal or emergency reason', 'other'],
    ];
}

// Bell notification (+ push when available) for the captain / secretary
function notifyVehicleStaff($conn, $requestId, $residentId, $status, $purpose, $vehicleName, $startDateTime, $note = '') {
    try {
        $pushFile = __DIR__ . '/../admin/push_send.php';
        if (is_file($pushFile)) require_once $pushFile;

        $stmt = mysqli_prepare($conn, "SELECT first_name, last_name FROM resident WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $residentId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $name = trim(($res['first_name'] ?? '') . ' ' . ($res['last_name'] ?? ''));
        if ($name === '') $name = 'A resident';

        $when = date('M j, Y g:i A', strtotime($startDateTime));

        if ($status === 'pending') {
            $title = ($note !== '') ? 'Urgent Vehicle Request - Review Needed' : 'Vehicle Request Needs Approval';
            $msg   = "{$name} requested {$vehicleName} on {$when}. Reason: {$purpose}."
                   . ($note !== '' ? " {$note}." : '')
                   . " Please review and approve or decline it.";
            $roles = "'secretary','captain'";     // adjust to your admin_role values
        } else {
            $title = 'Vehicle Trip Scheduled';
            $msg   = "{$name} was approved for {$vehicleName} on {$when}. Reason: {$purpose}.";
            $roles = "'secretary','captain'";     // both get the heads-up
        }

        $q = mysqli_query($conn, "SELECT id, admin_role FROM admin WHERE admin_role IN ($roles) AND is_active = 1");
        if (!$q) return;

        while ($admin = mysqli_fetch_assoc($q)) {
            $adminId = (int)$admin['id'];
            $type    = 'info';

            $nStmt = mysqli_prepare($conn,
                "INSERT INTO notifications (user_type, user_id, resident_id, type, title, message, reference_id, reference_type)
                 VALUES ('admin', ?, NULL, ?, ?, ?, ?, 'vehicle_request')");
            if ($nStmt) {
                mysqli_stmt_bind_param($nStmt, "isssi", $adminId, $type, $title, $msg, $requestId);
                mysqli_stmt_execute($nStmt);
                mysqli_stmt_close($nStmt);
            }

            if (function_exists('pushToUser')) {
                pushToUser($conn, $adminId, $title, $msg,
                    '/BRITE/admin/dashboards/' . $admin['admin_role'] . '_dashboard.php');
            }
        }
    } catch (Throwable $e) {
        error_log("notifyVehicleStaff failed for request #$requestId: " . $e->getMessage());
    }
}

// Trips already approved for one vehicle on one date (drives the calendar hint in the form)
function checkVehicleAvailability($conn) {
    $globalId = $_GET['global_id'] ?? '';
    $date     = $_GET['date'] ?? '';

    if ($globalId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        return;
    }

    $dayStart = $date . ' 00:00:00';
    $dayEnd   = $date . ' 23:59:59';

    $stmt = mysqli_prepare($conn,
        "SELECT start_datetime, end_datetime FROM requests
         WHERE item_global_id = ? AND request_type = 'vehicle'
           AND status IN ('approved', 'borrowed')
           AND start_datetime < ? AND end_datetime > ?
         ORDER BY start_datetime ASC");
    mysqli_stmt_bind_param($stmt, "sss", $globalId, $dayEnd, $dayStart);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $busy = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $busy[] = [
            'start' => date('H:i', max(strtotime($row['start_datetime']), strtotime($dayStart))),
            'end'   => date('H:i', min(strtotime($row['end_datetime']), strtotime($dayEnd))),
        ];
    }
    mysqli_stmt_close($stmt);

    echo json_encode(['success' => true, 'busy' => $busy, 'buffer_minutes' => VEHICLE_BUFFER_MINUTES]);
}

// Handle JSON input for POST requests
$input = json_decode(file_get_contents('php://input'), true);
$action = $_POST['action'] ?? $_GET['action'] ?? $input['action'] ?? '';

error_log("Action received: " . $action);

switch($action) {
    case 'get_all_equipment':
        getAllEquipment($conn);
        break;
    case 'check_availability':
        checkAvailability($conn);
        break;
    case 'check_facility_availability':
        checkFacilityAvailability($conn);
        break;
    case 'check_vehicle_availability':
        checkVehicleAvailability($conn);
        break;
    case 'create_booking':
        createBooking($conn, $resident_id, $input);
        break;

    // ── Cache-first vehicle flow ─────────────────────────────
    case 'vehicle_draft_save':
        echo json_encode(vehicleDraftSave($resident_id, $input));
        break;
    case 'vehicle_check':
        echo json_encode(vehicleCheck($conn, $resident_id, $input));
        break;
    case 'vehicle_recommend':
        echo json_encode(vehicleRecommend($conn, $resident_id, $input));
        break;
    case 'vehicle_commit':
        vdbg('--- vehicle_commit called ---');
        vdbg_arr('input', $input);
        $__result = vehicleCommit($conn, $resident_id, $input);
        vdbg_arr('result', $__result);
        echo json_encode($__result);
        break;
    case 'vehicle_draft_discard':
        echo json_encode(vehicleDraftDiscard());
        break;
    case 'vehicle_text_check':
        echo json_encode(vehicleTextCheck($input));
        break;
    case 'get_my_bookings':
        getMyBookings($conn, $resident_id);
        break;
    case 'cancel_request':
        cancelRequest($conn, $resident_id, $input);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
}


// ============ CANCEL REQUEST (immediate) ============
// Cancels the trip right away and notifies secretary + captain.
function cancelRequest($conn, $resident_id, $input) {
    $requestId = intval($input['request_id'] ?? 0);
    $reason    = trim($input['reason'] ?? '');

    if ($requestId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing request id.']);
        return;
    }
    if (mb_strlen($reason) < 10) {
        echo json_encode(['success' => false, 'message' => 'Please give a reason (at least 10 characters).']);
        return;
    }
    if (mb_strlen($reason) > 500) {
        echo json_encode(['success' => false, 'message' => 'Reason is too long (max 500 characters).']);
        return;
    }

    $stmt = mysqli_prepare($conn,
        "SELECT id, request_type, status,
                item_global_id, reference,
                trip_date, trip_slot_start, trip_slot_end
           FROM requests
          WHERE id = ? AND resident_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $requestId, $resident_id);
    mysqli_stmt_execute($stmt);
    $req = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found.']);
        return;
    }
    if ($req['request_type'] !== 'vehicle') {
        echo json_encode(['success' => false, 'message' => 'Only vehicle requests can be cancelled here.']);
        return;
    }
    if (in_array($req['status'], ['cancelled','rejected','completed'], true)) {
        echo json_encode(['success' => false, 'message' => 'This request can no longer be cancelled.']);
        return;
    }

    // Vehicle name for the notification message.
    $vehStmt = mysqli_prepare($conn,
        "SELECT vehicle_name FROM vehicle_requests WHERE request_id = ?");
    mysqli_stmt_bind_param($vehStmt, "i", $requestId);
    mysqli_stmt_execute($vehStmt);
    $vehRow = mysqli_fetch_assoc(mysqli_stmt_get_result($vehStmt));
    mysqli_stmt_close($vehStmt);
    $vehName = $vehRow['vehicle_name'] ?? 'the vehicle';

    // Cancel immediately.
    $upd = mysqli_prepare($conn,
        "UPDATE requests
            SET status       = 'cancelled',
                cancelled_by = ?,
                cancelled_at = NOW(),
                admin_notes  = CONCAT(
                                   COALESCE(admin_notes, ''),
                                   IF(COALESCE(admin_notes, '') = '', '', '\n'),
                                   'Cancelled by resident. Reason: ', ?
                               )
          WHERE id = ? AND resident_id = ?");
    mysqli_stmt_bind_param($upd, "isii", $resident_id, $reason, $requestId, $resident_id);
    if (!mysqli_stmt_execute($upd)) {
        $err = mysqli_stmt_error($upd);
        mysqli_stmt_close($upd);
        echo json_encode(['success' => false, 'message' => 'Could not cancel: ' . $err]);
        return;
    }
    mysqli_stmt_close($upd);

    notifyCancellation($conn, $requestId, $resident_id, $reason, $req, $vehName);

    echo json_encode([
        'success'    => true,
        'message'    => 'Request cancelled. The Captain and Secretary have been notified.',
        'request_id' => $requestId,
    ]);
}

// Notify secretary + captain that a resident cancelled their trip.
function notifyCancellation($conn, $requestId, $residentId, $reason, $req, $vehName) {
    try {
        $pushFile = __DIR__ . '/../admin/push_send.php';
        if (is_file($pushFile)) require_once $pushFile;

        $stmt = mysqli_prepare($conn, "SELECT first_name, last_name FROM resident WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $residentId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $name = trim(($res['first_name'] ?? '') . ' ' . ($res['last_name'] ?? ''));
        if ($name === '') $name = 'A resident';

        $when = !empty($req['trip_date']) && !empty($req['trip_slot_start'])
              ? date('M j, Y g:i A', strtotime($req['trip_date'] . ' ' . $req['trip_slot_start']))
              : 'the scheduled time';

        $title = 'Vehicle Trip Cancelled';
        $msg   = "{$name} cancelled their trip on {$when} ({$vehName}). Reason: {$reason}.";

        $q = mysqli_query($conn,
            "SELECT id, admin_role FROM admin
              WHERE admin_role IN ('secretary','captain') AND is_active = 1");
        if (!$q) return;

        while ($admin = mysqli_fetch_assoc($q)) {
            $adminId = (int)$admin['id'];

            $nStmt = mysqli_prepare($conn,
                "INSERT INTO notifications (user_type, user_id, resident_id, type, title, message, reference_id, reference_type)
                 VALUES ('admin', ?, NULL, 'warning', ?, ?, ?, 'vehicle_cancel')");
            if ($nStmt) {
                mysqli_stmt_bind_param($nStmt, "issi", $adminId, $title, $msg, $requestId);
                mysqli_stmt_execute($nStmt);
                mysqli_stmt_close($nStmt);
            }

            if (function_exists('pushToUser')) {
                pushToUser($conn, $adminId, $title, $msg,
                    '/BRITE/admin/dashboards/' . $admin['admin_role'] . '_dashboard.php');
            }
        }
    } catch (Throwable $e) {
        error_log("notifyCancellation failed for #$requestId: " . $e->getMessage());
    }
}

function getAllEquipment($conn) {
    $equipment = [];
    
    // Get Equipment
    $eqSql = "SELECT e.*, 'Equipment' as category, 
              COALESCE((SELECT SUM(quantity) FROM equipment_status WHERE equipment_id = e.id AND status = 'available'), 0) as available_count,
              e.quantity as total_quantity,
              e.global_id,
              NULL as capacity
              FROM equipment e 
              WHERE e.status = 'active'
              ORDER BY e.name ASC";
    $eqResult = mysqli_query($conn, $eqSql);
    
    if ($eqResult) {
        while ($row = mysqli_fetch_assoc($eqResult)) {
            $equipment[] = $row;
        }
    }
    
    // Get Facilities
    $facSql = "SELECT *, 'Facility' as category, 
              1 as total_quantity,
              CASE WHEN status = 'available' THEN 1 ELSE 0 END as available_count,
              global_id,
              NULL as capacity
              FROM facilities 
              WHERE status != 'inactive'
              ORDER BY name ASC";
    $facResult = mysqli_query($conn, $facSql);
    
    if ($facResult) {
        while ($row = mysqli_fetch_assoc($facResult)) {
            $equipment[] = $row;
        }
    }
    
    // Vehicles are intentionally NOT included here.
    // Vehicle requests use their own dedicated entry card on the resident page.
    
    echo json_encode(['success' => true, 'data' => $equipment]);
}

// ============ EQUIPMENT AVAILABILITY CHECK ============
function checkAvailability($conn) {
    $globalId = $_GET['global_id'] ?? '';
    $startDate = $_GET['start_date'] ?? '';
    $endDate = $_GET['end_date'] ?? '';
    $requestedQuantity = intval($_GET['quantity'] ?? 1);
    
    if (empty($globalId) || empty($startDate) || empty($endDate)) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        return;
    }
    
    // Only for equipment (EQ prefix)
    if (strpos($globalId, 'EQ') !== 0) {
        echo json_encode(['success' => true, 'available' => true, 'message' => 'Not applicable']);
        return;
    }
    
    $equipmentId = intval(substr($globalId, 2));
    
    // Get total available quantity from equipment_status
    $statusSql = "SELECT COALESCE(SUM(quantity), 0) as total_available 
                  FROM equipment_status 
                  WHERE equipment_id = ? AND status = 'available'";
    $statusStmt = mysqli_prepare($conn, $statusSql);
    mysqli_stmt_bind_param($statusStmt, "i", $equipmentId);
    mysqli_stmt_execute($statusStmt);
    $statusResult = mysqli_stmt_get_result($statusStmt);
    $statusRow = mysqli_fetch_assoc($statusResult);
    $totalAvailable = $statusRow['total_available'] ?? 0;
    
    if ($totalAvailable == 0) {
        echo json_encode(['success' => true, 'available' => false, 'available_count' => 0, 'message' => 'No items available']);
        return;
    }
    
    // Get booked quantity for this date range from approved/borrowed bookings
    $bookingSql = "SELECT SUM(er.quantity) as booked_quantity
                   FROM equipment_requests er
                   JOIN requests r ON er.request_id = r.id
                   WHERE er.equipment_global_id = ?
                   AND r.status IN ('approved', 'borrowed')
                   AND er.booking_date <= ?
                   AND er.return_date >= ?";
    $bookingStmt = mysqli_prepare($conn, $bookingSql);
    $startDateOnly = date('Y-m-d', strtotime($startDate));
    $endDateOnly = date('Y-m-d', strtotime($endDate));
    mysqli_stmt_bind_param($bookingStmt, "sss", $globalId, $endDateOnly, $startDateOnly);
    mysqli_stmt_execute($bookingStmt);
    $bookingResult = mysqli_stmt_get_result($bookingStmt);
    $bookingRow = mysqli_fetch_assoc($bookingResult);
    $bookedQuantity = $bookingRow['booked_quantity'] ?? 0;
    
    $availableForBooking = $totalAvailable - $bookedQuantity;
    if ($availableForBooking < 0) $availableForBooking = 0;
    
    $isAvailable = $availableForBooking >= $requestedQuantity;
    
    echo json_encode([
        'success' => true,
        'available' => $isAvailable,
        'available_count' => $availableForBooking,
        'total_available' => $totalAvailable,
        'booked_count' => $bookedQuantity,
        'requested' => $requestedQuantity,
        'message' => $isAvailable ? "{$availableForBooking} item(s) available for this date range" : "Only {$availableForBooking} item(s) available, you requested {$requestedQuantity}"
    ]);
}

// ============ FACILITY AVAILABILITY CHECK ============
function checkFacilityAvailability($conn) {
    $globalId = $_GET['global_id'] ?? '';
    $bookingDate = $_GET['booking_date'] ?? '';
    $durationHours = intval($_GET['duration_hours'] ?? 1);
    
    if (empty($globalId) || empty($bookingDate)) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        return;
    }
    
    error_log("Checking availability for facility: $globalId on date: $bookingDate for $durationHours hour(s)");
    
    // Get ONLY approved bookings for this facility on the selected date
    $sql = "SELECT r.start_datetime, r.end_datetime, r.status, r.id
            FROM requests r
            WHERE r.item_global_id = ? 
            AND DATE(r.start_datetime) = ?
            AND r.status = 'approved'
            AND r.request_type = 'facility'
            ORDER BY r.start_datetime ASC";
    
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database prepare error']);
        return;
    }
    
    mysqli_stmt_bind_param($stmt, "ss", $globalId, $bookingDate);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $bookedSlots = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $startTime = date('H:i', strtotime($row['start_datetime']));
        $endTime = date('H:i', strtotime($row['end_datetime']));
        $bookedSlots[] = [
            'start' => $startTime,
            'end' => $endTime,
            'status' => $row['status'],
            'id' => $row['id']
        ];
    }
    
    error_log("Found " . count($bookedSlots) . " approved bookings");
    
    // Generate available time slots based on duration
    $availableSlots = [];
    $blockedSlots = [];
    
    $startHour = 6;  // 6:00 AM
    $endHour = 22;   // 10:00 PM
    $maxStartHour = $endHour - $durationHours;
    
    for ($hour = $startHour; $hour <= $maxStartHour; $hour++) {
        $slotStart = sprintf("%02d:00", $hour);
        $slotEnd = sprintf("%02d:00", $hour + $durationHours);
        
        $isBlocked = false;
        $blockedByBooking = null;
        $slotStartMinutes = $hour * 60;
        $slotEndMinutes = ($hour + $durationHours) * 60;
        
        // Check against approved bookings
        foreach ($bookedSlots as $booked) {
            $bookedStartMinutes = (int)substr($booked['start'], 0, 2) * 60 + (int)substr($booked['start'], 3, 2);
            $bookedEndMinutes = (int)substr($booked['end'], 0, 2) * 60 + (int)substr($booked['end'], 3, 2);
            
            if (!($slotEndMinutes <= $bookedStartMinutes || $slotStartMinutes >= $bookedEndMinutes)) {
                $isBlocked = true;
                $blockedByBooking = $booked;
                break;
            }
        }
        
        if ($isBlocked) {
            $blockedSlots[] = [
                'start' => $slotStart,
                'end' => $slotEnd,
                'display' => date("g:i A", strtotime($slotStart)) . " - " . date("g:i A", strtotime($slotEnd)),
                'conflict' => $blockedByBooking
            ];
        } else {
            $availableSlots[] = [
                'start' => $slotStart,
                'end' => $slotEnd,
                'display' => date("g:i A", strtotime($slotStart)) . " - " . date("g:i A", strtotime($slotEnd))
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'available_slots' => $availableSlots,
        'blocked_slots' => $blockedSlots,
        'has_bookings' => count($bookedSlots) > 0,
        'facility_id' => $globalId,
        'booking_date' => $bookingDate,
        'duration_hours' => $durationHours
    ]);
}

// ============ CREATE BOOKING WITH AUTO-APPROVAL ============
function createBooking($conn, $resident_id, $input) {
    $globalId = $input['global_id'] ?? '';
    $category = $input['category'] ?? '';
    $startDateTime = $input['start_datetime'] ?? '';
    $endDateTime = $input['end_datetime'] ?? '';
    $durationDays = floatval($input['duration_days'] ?? 1);
    $quantity = intval($input['quantity'] ?? 1);
    $purpose = mysqli_real_escape_string($conn, $input['purpose'] ?? '');
    $customData = $input['custom_data'] ?? [];
    
    // ============ VALIDATION ============
    
    if (empty($globalId)) {
        echo json_encode(['success' => false, 'message' => 'Missing equipment selection']);
        return;
    }
    
    if (empty($startDateTime)) {
        echo json_encode(['success' => false, 'message' => 'Please select a booking date and time']);
        return;
    }
    
    if (empty($purpose)) {
        echo json_encode(['success' => false, 'message' => 'Please state the purpose of booking']);
        return;
    }
    
    if ($quantity < 1) {
        echo json_encode(['success' => false, 'message' => 'Quantity must be at least 1']);
        return;
    }
    
    $startDateTimeFormatted = date('Y-m-d H:i:s', strtotime($startDateTime));
    $endDateTimeFormatted = date('Y-m-d H:i:s', strtotime($endDateTime));
    
    if ($startDateTimeFormatted >= $endDateTimeFormatted) {
        echo json_encode(['success' => false, 'message' => 'End date/time must be after start date/time']);
        return;
    }
    
    // ============ START TRANSACTION ============
    mysqli_begin_transaction($conn);
    
    try {
        $requestType = $category === 'Equipment' ? 'equipment' : ($category === 'Facility' ? 'facility' : 'vehicle');
        $reviewNote  = '';   // set when a vehicle request needs staff attention
        
        // ============ CATEGORY-SPECIFIC VALIDATION ============
        
        if ($category === 'Equipment') {
            $eqCheckSql = "SELECT id, quantity, global_id, status FROM equipment WHERE global_id = ? AND status = 'active'";
            $eqCheckStmt = mysqli_prepare($conn, $eqCheckSql);
            mysqli_stmt_bind_param($eqCheckStmt, "s", $globalId);
            mysqli_stmt_execute($eqCheckStmt);
            $eqCheckResult = mysqli_stmt_get_result($eqCheckStmt);
            $equipment = mysqli_fetch_assoc($eqCheckResult);
            
            if (!$equipment) {
                throw new Exception('Equipment not found or inactive');
            }
            
            $statusSql = "SELECT COALESCE(SUM(quantity), 0) as total_available 
                          FROM equipment_status 
                          WHERE equipment_id = ? AND status = 'available'";
            $statusStmt = mysqli_prepare($conn, $statusSql);
            mysqli_stmt_bind_param($statusStmt, "i", $equipment['id']);
            mysqli_stmt_execute($statusStmt);
            $statusResult = mysqli_stmt_get_result($statusStmt);
            $statusRow = mysqli_fetch_assoc($statusResult);
            $totalAvailable = $statusRow['total_available'] ?? 0;
            
            if ($totalAvailable == 0) {
                throw new Exception('No items available for booking');
            }
            
            $bookingDate = date('Y-m-d', strtotime($startDateTime));
            $returnDate = date('Y-m-d', strtotime($endDateTime));
            
            $bookingSql = "SELECT SUM(er.quantity) as booked_quantity
                           FROM equipment_requests er
                           JOIN requests r ON er.request_id = r.id
                           WHERE er.equipment_global_id = ?
                           AND r.status IN ('approved', 'borrowed')
                           AND er.booking_date <= ?
                           AND er.return_date >= ?";
            $bookingStmt = mysqli_prepare($conn, $bookingSql);
            mysqli_stmt_bind_param($bookingStmt, "sss", $globalId, $returnDate, $bookingDate);
            mysqli_stmt_execute($bookingStmt);
            $bookingResult = mysqli_stmt_get_result($bookingStmt);
            $bookingRow = mysqli_fetch_assoc($bookingResult);
            $bookedQuantity = $bookingRow['booked_quantity'] ?? 0;
            
            $availableForBooking = $totalAvailable - $bookedQuantity;
            if ($availableForBooking < 0) $availableForBooking = 0;
            
            if ($quantity > $availableForBooking) {
                throw new Exception("Only {$availableForBooking} item(s) available for the selected date range");
            }
            
            $statusToSet = 'approved';
            
        } elseif ($category === 'Facility') {
            $facCheckSql = "SELECT id, global_id, status FROM facilities WHERE global_id = ? AND status != 'inactive'";
            $facCheckStmt = mysqli_prepare($conn, $facCheckSql);
            mysqli_stmt_bind_param($facCheckStmt, "s", $globalId);
            mysqli_stmt_execute($facCheckStmt);
            $facCheckResult = mysqli_stmt_get_result($facCheckStmt);
            $facility = mysqli_fetch_assoc($facCheckResult);
            
            if (!$facility) {
                throw new Exception('Facility not found or inactive');
            }
            
            $overlapSql = "SELECT COUNT(*) as overlap_count
                           FROM requests r
                           WHERE r.item_global_id = ?
                           AND r.status = 'approved'
                           AND r.request_type = 'facility'
                           AND r.start_datetime < ?
                           AND r.end_datetime > ?";
            $overlapStmt = mysqli_prepare($conn, $overlapSql);
            mysqli_stmt_bind_param($overlapStmt, "sss", $globalId, $endDateTimeFormatted, $startDateTimeFormatted);
            mysqli_stmt_execute($overlapStmt);
            $overlapResult = mysqli_stmt_get_result($overlapStmt);
            $overlapRow = mysqli_fetch_assoc($overlapResult);
            
            if (($overlapRow['overlap_count'] ?? 0) > 0) {
                throw new Exception('This time slot is already booked. Please select a different time.');
            }
            
            $statusToSet = 'approved';
            
        } elseif ($category === 'Vehicle') {
            // Lock the vehicle row: two residents can never take the same slot at the same time
            $vehCheckSql = "SELECT id, global_id, status, capacity, name FROM vehicles WHERE global_id = ? AND status != 'inactive' FOR UPDATE";
            $vehCheckStmt = mysqli_prepare($conn, $vehCheckSql);
            mysqli_stmt_bind_param($vehCheckStmt, "s", $globalId);
            mysqli_stmt_execute($vehCheckStmt);
            $vehCheckResult = mysqli_stmt_get_result($vehCheckStmt);
            $vehicle = mysqli_fetch_assoc($vehCheckResult);

            if (!$vehicle) {
                throw new Exception('Vehicle not found or inactive');
            }
            $vehName = $vehicle['name'] ?? 'Vehicle';

            if (in_array(strtolower($vehicle['status'] ?? ''), ['maintenance', 'under_maintenance', 'repair', 'unavailable'], true)) {
                throw new Exception("{$vehName} is currently unavailable (maintenance).");
            }

            // ---- reason for the request (must be one of the allowed purposes) ----
            $purposeCode = trim($customData['purpose_code'] ?? '');
            $purposeMap  = vehiclePurposes();
            if (!isset($purposeMap[$purposeCode])) {
                throw new Exception('Please select the reason for your vehicle request');
            }
            list($purposeLabel, $tier) = $purposeMap[$purposeCode];

            $otherReason = trim($customData['other_reason'] ?? '');
            if ($tier === 'other') {
                if (mb_strlen($otherReason) < 10) {
                    throw new Exception('Please describe your reason (at least 10 characters)');
                }
                $purpose = $purposeLabel . ': ' . mb_substr($otherReason, 0, 300);
            } else {
                $purpose = $purposeLabel;
            }

            // ---- trip details ----
            $passengerCount  = intval($customData['passenger_count'] ?? 1);
            $vehicleCapacity = intval($vehicle['capacity'] ?? 12);

            if ($passengerCount > $vehicleCapacity) {
                throw new Exception("Vehicle capacity is {$vehicleCapacity} passengers. You requested {$passengerCount}.");
            }
            if ($passengerCount < 1) {
                throw new Exception('At least 1 passenger is required');
            }

            $pickupLocation  = trim($customData['pickup_location'] ?? '');
            $dropoffLocation = trim($customData['dropoff_location'] ?? '');
            if ($pickupLocation === '')  throw new Exception('Pickup location is required');
            if ($dropoffLocation === '') throw new Exception('Drop-off location is required');

            // ---- scheduling rules ----
            $rules   = vehicleTierRules()[$tier];
            $startTs = strtotime($startDateTimeFormatted);
            $endTs   = strtotime($endDateTimeFormatted);

            if ($startTs < time() - 300) {
                throw new Exception('The pick-up time has already passed');
            }
            if ($rules['notice_hours'] > 0 && $startTs < time() + $rules['notice_hours'] * 3600) {
                throw new Exception("This kind of trip needs at least {$rules['notice_hours']} hour(s) notice. For an emergency, choose an emergency reason.");
            }
            if (($endTs - $startTs) > VEHICLE_MAX_HOURS * 3600) {
                throw new Exception('A vehicle trip can be at most ' . VEHICLE_MAX_HOURS . ' hours');
            }
            if ($rules['office_only']) {
                $startHm = date('H:i', $startTs);
                $endHm   = date('H:i', $endTs);
                if ($startHm < VEHICLE_OFFICE_START || $endHm > VEHICLE_OFFICE_END
                    || date('Y-m-d', $startTs) !== date('Y-m-d', $endTs)) {
                    throw new Exception('This kind of trip must be within office hours ('
                        . date('g:i A', strtotime(VEHICLE_OFFICE_START)) . ' - '
                        . date('g:i A', strtotime(VEHICLE_OFFICE_END)) . ')');
                }
            }

            // ---- the resident cannot hold two vehicle requests at the same time ----
            $selfSql = "SELECT COUNT(*) AS c FROM requests
                        WHERE resident_id = ? AND request_type = 'vehicle'
                          AND status IN ('pending', 'approved', 'borrowed')
                          AND start_datetime < ? AND end_datetime > ?";
            $selfStmt = mysqli_prepare($conn, $selfSql);
            mysqli_stmt_bind_param($selfStmt, "iss", $resident_id, $endDateTimeFormatted, $startDateTimeFormatted);
            mysqli_stmt_execute($selfStmt);
            $selfRow = mysqli_fetch_assoc(mysqli_stmt_get_result($selfStmt));
            if (($selfRow['c'] ?? 0) > 0) {
                throw new Exception('You already have a vehicle request during this time');
            }

            // ---- is the vehicle free? (trip + buffer before and after) ----
            $bufferSec = VEHICLE_BUFFER_MINUTES * 60;
            $winStart  = date('Y-m-d H:i:s', $startTs - $bufferSec);
            $winEnd    = date('Y-m-d H:i:s', $endTs + $bufferSec);

            $overlapSql = "SELECT COUNT(*) as overlap_count
                           FROM requests r
                           WHERE r.item_global_id = ?
                           AND r.status IN ('approved', 'borrowed')
                           AND r.request_type = 'vehicle'
                           AND r.start_datetime < ?
                           AND r.end_datetime > ?";
            $overlapStmt = mysqli_prepare($conn, $overlapSql);
            mysqli_stmt_bind_param($overlapStmt, "sss", $globalId, $winEnd, $winStart);
            mysqli_stmt_execute($overlapStmt);
            $overlapRow  = mysqli_fetch_assoc(mysqli_stmt_get_result($overlapStmt));
            $hasConflict = (($overlapRow['overlap_count'] ?? 0) > 0);

            // ---- decide the status ----
            if ($hasConflict && $tier === 'urgent') {
                // never turn an emergency away: staff decide which trip gets the vehicle
                $statusToSet = 'pending';
                $reviewNote  = "The vehicle is already booked at that time, please reassign";
            } elseif ($hasConflict) {
                throw new Exception("{$vehName} is unavailable during the selected time");
            } elseif ($tier === 'other') {
                $statusToSet = 'pending';        // captain / secretary must approve
            } else {
                $statusToSet = 'approved';
            }

        } else {
            throw new Exception('Invalid category');
        }
        
        // ============ CREATE THE BOOKING WITH STATUS = 'approved' ============
        
        $reference = $globalId . '-' . date('YmdHis') . '-' . rand(100, 999);
        $approvedAtSql = ($statusToSet === 'approved') ? 'NOW()' : 'NULL';   // pending requests are not approved yet
        
        $sql = "INSERT INTO requests (
            resident_id, 
            request_type, 
            request_date, 
            start_datetime, 
            end_datetime, 
            duration_days, 
            quantity, 
            purpose, 
            status, 
            item_global_id,
            reference,
            approved_at,
            created_at
        ) VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, {$approvedAtSql}, NOW())";
        
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param(
            $stmt, 
            "isssdissss", 
            $resident_id, 
            $requestType, 
            $startDateTimeFormatted, 
            $endDateTimeFormatted, 
            $durationDays, 
            $quantity, 
            $purpose, 
            $statusToSet, 
            $globalId,
            $reference
        );
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('Failed to create request: ' . mysqli_error($conn));
        }
        
        $requestId = mysqli_insert_id($conn);
        
        // ============ INSERT CATEGORY-SPECIFIC DETAILS ============
        
        if ($category === 'Equipment') {
            $equipmentSql = "SELECT name FROM equipment WHERE global_id = ?";
            $equipmentStmt = mysqli_prepare($conn, $equipmentSql);
            mysqli_stmt_bind_param($equipmentStmt, "s", $globalId);
            mysqli_stmt_execute($equipmentStmt);
            $equipmentResult = mysqli_stmt_get_result($equipmentStmt);
            $equipment = mysqli_fetch_assoc($equipmentResult);
            $equipmentName = $equipment['name'] ?? '';
            
            $bookingDate = date('Y-m-d', strtotime($startDateTime));
            $returnDate = date('Y-m-d', strtotime($endDateTime));
            $pickupTime = date('H:i:s', strtotime($startDateTime));
            
            $eqReqSql = "INSERT INTO equipment_requests (
                request_id, 
                equipment_global_id, 
                equipment_name, 
                quantity, 
                duration_days, 
                booking_date, 
                pickup_time, 
                return_date, 
                notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $eqReqStmt = mysqli_prepare($conn, $eqReqSql);
            mysqli_stmt_bind_param(
                $eqReqStmt, 
                "issiissss", 
                $requestId, 
                $globalId, 
                $equipmentName, 
                $quantity, 
                $durationDays, 
                $bookingDate, 
                $pickupTime, 
                $returnDate, 
                $purpose
            );
            
            if (!mysqli_stmt_execute($eqReqStmt)) {
                throw new Exception('Failed to create equipment request');
            }
            
        } elseif ($category === 'Facility') {
            $facilitySql = "SELECT name FROM facilities WHERE global_id = ?";
            $facilityStmt = mysqli_prepare($conn, $facilitySql);
            mysqli_stmt_bind_param($facilityStmt, "s", $globalId);
            mysqli_stmt_execute($facilityStmt);
            $facilityResult = mysqli_stmt_get_result($facilityStmt);
            $facility = mysqli_fetch_assoc($facilityResult);
            $facilityName = $facility['name'] ?? '';
            
            $bookingDate = date('Y-m-d', strtotime($startDateTime));
            
            $startDateTimeObj = new DateTime($startDateTime);
            $endDateTimeObj = new DateTime($endDateTime);
            
            $startTime = $startDateTimeObj->format('H:i:s');
            $endTime = $endDateTimeObj->format('H:i:s');
            
            $durationHours = (strtotime($endDateTime) - strtotime($startDateTime)) / 3600;
            $expectedAttendees = $customData['expected_attendees'] ?? null;
            $eventType = $customData['event_type'] ?? null;
            
            $facReqSql = "INSERT INTO facility_requests (
                request_id, 
                facility_global_id, 
                facility_name, 
                booking_date, 
                start_time, 
                end_time, 
                duration_hours, 
                expected_attendees, 
                event_type, 
                notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $facReqStmt = mysqli_prepare($conn, $facReqSql);
            mysqli_stmt_bind_param(
                $facReqStmt, 
                "issssdsiss", 
                $requestId, 
                $globalId, 
                $facilityName, 
                $bookingDate, 
                $startTime, 
                $endTime, 
                $durationHours, 
                $expectedAttendees, 
                $eventType, 
                $purpose
            );
            
            if (!mysqli_stmt_execute($facReqStmt)) {
                throw new Exception('Failed to create facility request: ' . mysqli_error($conn));
            }
            
        } elseif ($category === 'Vehicle') {
            $vehicleSql = "SELECT name, capacity FROM vehicles WHERE global_id = ?";
            $vehicleStmt = mysqli_prepare($conn, $vehicleSql);
            mysqli_stmt_bind_param($vehicleStmt, "s", $globalId);
            mysqli_stmt_execute($vehicleStmt);
            $vehicleResult = mysqli_stmt_get_result($vehicleStmt);
            $vehicle = mysqli_fetch_assoc($vehicleResult);
            $vehicleName = $vehicle['name'] ?? '';
            $vehicleCapacity = $vehicle['capacity'] ?? 12;
            
            $tripDate = date('Y-m-d', strtotime($startDateTime));
            $pickupTime = date('H:i:s', strtotime($startDateTime));
            $pickupLocation = $customData['pickup_location'] ?? '';
            $dropoffLocation = $customData['dropoff_location'] ?? '';
            $passengerCount = intval($customData['passenger_count'] ?? 1);
            $estimatedHours = floatval($customData['estimated_hours'] ?? $durationDays * 24);
            $specialRequests = $customData['special_requests'] ?? null;
            
            $vehReqSql = "INSERT INTO vehicle_requests (
                request_id, 
                vehicle_global_id, 
                vehicle_name, 
                vehicle_capacity, 
                trip_date, 
                pickup_time, 
                pickup_location, 
                dropoff_location, 
                passenger_count, 
                estimated_hours, 
                purpose, 
                special_requests
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $vehReqStmt = mysqli_prepare($conn, $vehReqSql);
            mysqli_stmt_bind_param(
                $vehReqStmt, 
                "ississssidss", 
                $requestId, 
                $globalId, 
                $vehicleName, 
                $vehicleCapacity, 
                $tripDate, 
                $pickupTime, 
                $pickupLocation, 
                $dropoffLocation, 
                $passengerCount, 
                $estimatedHours, 
                $purpose, 
                $specialRequests
            );
            
            if (!mysqli_stmt_execute($vehReqStmt)) {
                throw new Exception('Failed to create vehicle request');
            }
        }
        
        // ============ COMMIT TRANSACTION ============
        mysqli_commit($conn);

        // Notify AFTER the commit so a notification problem can never undo the request
        if ($category === 'Vehicle') {
            notifyVehicleStaff($conn, $requestId, $resident_id, $statusToSet, $purpose, $vehName, $startDateTimeFormatted, $reviewNote);
        }

        $successMessage = ($statusToSet === 'pending')
            ? 'Request submitted. Waiting for approval from the Barangay Captain or Secretary.'
            : 'Booking approved successfully!';

        echo json_encode([
            'success' => true,
            'message' => $successMessage,
            'reference' => $reference,
            'request_id' => $requestId,
            'status' => $statusToSet
        ]);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ============ GET MY BOOKINGS ============
function getMyBookings($conn, $resident_id) {
    $bookings = [];
    
    $sql = "SELECT r.*,
            r.request_type as category
            FROM requests r
            WHERE r.resident_id = ?
            ORDER BY r.created_at DESC";
    
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'DB Error: ' . mysqli_error($conn)]);
        return;
    }
    
    mysqli_stmt_bind_param($stmt, "i", $resident_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (!$result) {
        echo json_encode(['success' => false, 'message' => 'Query Error: ' . mysqli_error($conn)]);
        return;
    }
    
    while ($row = mysqli_fetch_assoc($result)) {
        if ($row['request_type'] === 'equipment') {
            $detailSql = "SELECT * FROM equipment_requests WHERE request_id = ?";
            $detailStmt = mysqli_prepare($conn, $detailSql);
            mysqli_stmt_bind_param($detailStmt, "i", $row['id']);
            mysqli_stmt_execute($detailStmt);
            $detailResult = mysqli_stmt_get_result($detailStmt);
            $details = mysqli_fetch_assoc($detailResult);
            if ($details) {
                $row['equipment_name'] = $details['equipment_name'];
                $row['booking_date'] = $details['booking_date'];
                $row['return_date'] = $details['return_date'];
                $row['quantity'] = $details['quantity'];
                $row['duration_days'] = $details['duration_days'];
                $row['notes'] = $details['notes'];
            }
        } elseif ($row['request_type'] === 'facility') {
            $detailSql = "SELECT * FROM facility_requests WHERE request_id = ?";
            $detailStmt = mysqli_prepare($conn, $detailSql);
            mysqli_stmt_bind_param($detailStmt, "i", $row['id']);
            mysqli_stmt_execute($detailStmt);
            $detailResult = mysqli_stmt_get_result($detailStmt);
            $details = mysqli_fetch_assoc($detailResult);
            if ($details) {
                $row['facility_name'] = $details['facility_name'];
                $row['booking_date'] = $details['booking_date'];
                $row['start_time'] = $details['start_time'];
                $row['end_time'] = $details['end_time'];
                $row['duration_hours'] = $details['duration_hours'];
                $row['expected_attendees'] = $details['expected_attendees'];
                $row['event_type'] = $details['event_type'];
                $row['equipment_name'] = $details['facility_name'];
            }
        } elseif ($row['request_type'] === 'vehicle') {
            $detailSql = "SELECT * FROM vehicle_requests WHERE request_id = ?";
            $detailStmt = mysqli_prepare($conn, $detailSql);
            mysqli_stmt_bind_param($detailStmt, "i", $row['id']);
            mysqli_stmt_execute($detailStmt);
            $detailResult = mysqli_stmt_get_result($detailStmt);
            $details = mysqli_fetch_assoc($detailResult);
            if ($details) {
                $row['vehicle_name']      = $details['vehicle_name'];
                $row['vehicle_capacity']  = $details['vehicle_capacity'];
                $row['trip_date']         = $details['trip_date'];
                $row['pickup_time']       = $details['pickup_time'];
                $row['pickup_window_end'] = $details['pickup_window_end'] ?? null;
                $row['pickup_location']   = $details['pickup_location'];
                $row['dropoff_location']  = $details['dropoff_location'];
                $row['passenger_count']   = $details['passenger_count'];
                $row['estimated_hours']   = $details['estimated_hours'];
                $row['special_requests']  = $details['special_requests'];
                $row['equipment_name']    = $details['vehicle_name'];
                $row['is_emergency']      = (int)($details['is_emergency'] ?? 0);
            }
            // expose new request-level columns
            $row['trip_slot_start'] = $row['trip_slot_start'] ?? null;
            $row['trip_slot_end']   = $row['trip_slot_end']   ?? null;
            $row['urgency']         = $row['urgency']         ?? 'scheduled';
            $row['cancelled_at']    = $row['cancelled_at']    ?? null;
            $row['cancelled_by']    = $row['cancelled_by']    ?? null;
        }
        
        $bookings[] = $row;
    }
    
    echo json_encode(['success' => true, 'bookings' => $bookings]);
}
?>