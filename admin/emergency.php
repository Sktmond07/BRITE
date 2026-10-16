<?php
// emergency.php - API endpoint for Android app
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

// ==================================================================
// POST — Create OR Update (update only used on ACCEPT)
// ==================================================================
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input && isset($_POST['type'])) {
        $input = $_POST;
    }

    $action = isset($input['action']) ? strtolower(trim($input['action'])) : 'create';

    if ($action === 'update') {
        handleUpdate($conn, $input);
    } else {
        handleCreate($conn, $input);
    }
}
// ==================================================================
// GET — Fetch emergencies for the dashboard
// ==================================================================
elseif ($method === 'GET') {
    handleGet($conn);
}
else {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}


// ------------------------------------------------------------------
// Insert new emergency
// ------------------------------------------------------------------
function handleCreate($conn, $input) {
    $required = ['type', 'barangay', 'name', 'latitude', 'longitude', 'caller_number'];
    $missing = [];

    foreach ($required as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            $missing[] = $field;
        }
    }

    if (!empty($missing)) {
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields: ' . implode(', ', $missing)
        ]);
        return;
    }

    $type          = mysqli_real_escape_string($conn, $input['type']);
    $name          = mysqli_real_escape_string($conn, $input['name']);
    $barangay      = mysqli_real_escape_string($conn, $input['barangay']);
    $latitude      = floatval($input['latitude']);
    $longitude     = floatval($input['longitude']);
    $caller_number = mysqli_real_escape_string($conn, $input['caller_number']);

    // Optional external ID (e.g. EMG-013) — used to match later ACCEPT updates
    $external_id = (isset($input['external_id']) && trim($input['external_id']) !== '')
        ? mysqli_real_escape_string($conn, $input['external_id']) : null;

    $status = 'PENDING';

    if ($external_id !== null) {
        $sql = "INSERT INTO emergencies
                (emergency_type, name, barangay, latitude, longitude, caller_number,
                 external_id, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sssddsss",
            $type, $name, $barangay, $latitude, $longitude,
            $caller_number, $external_id, $status);
    } else {
        $sql = "INSERT INTO emergencies
                (emergency_type, name, barangay, latitude, longitude, caller_number,
                 status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sssddss",
            $type, $name, $barangay, $latitude, $longitude,
            $caller_number, $status);
    }

    if (mysqli_stmt_execute($stmt)) {
        $emergency_id = mysqli_insert_id($conn);
        echo json_encode([
            'success' => true,
            'message' => 'Emergency reported successfully',
            'emergency_id' => $emergency_id
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . mysqli_error($conn)
        ]);
    }
}


// ------------------------------------------------------------------
// Update an existing emergency — called on ACCEPT
// ------------------------------------------------------------------
function handleUpdate($conn, $input) {
    $emergency_id = isset($input['emergency_id']) ? intval($input['emergency_id']) : 0;
    $external_id  = isset($input['external_id'])  ? trim($input['external_id'])  : '';

    if ($emergency_id <= 0 && $external_id === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Either emergency_id or external_id is required'
        ]);
        return;
    }

    // Default to ACCEPTED — this endpoint is only used on accept
    $status = isset($input['status']) ? strtoupper(trim($input['status'])) : 'ACCEPTED';
    $responder_name = isset($input['responder_name']) ? trim($input['responder_name']) : '';

    $status         = mysqli_real_escape_string($conn, $status);
    $responder_name = mysqli_real_escape_string($conn, $responder_name);
    $external_id    = mysqli_real_escape_string($conn, $external_id);

    if ($emergency_id > 0) {
        $sql = "UPDATE emergencies
                SET status = '$status',
                    accepted_by_name = '$responder_name'
                WHERE id = $emergency_id";
    } else {
        $sql = "UPDATE emergencies
                SET status = '$status',
                    accepted_by_name = '$responder_name'
                WHERE external_id = '$external_id'";
    }

    if (!mysqli_query($conn, $sql)) {
        echo json_encode([
            'success' => false,
            'message' => 'Update failed: ' . mysqli_error($conn)
        ]);
        return;
    }

    // Confirm the row exists (whether or not we actually changed it)
    if ($emergency_id > 0) {
        $check = mysqli_query($conn, "SELECT id FROM emergencies WHERE id = $emergency_id LIMIT 1");
    } else {
        $check = mysqli_query($conn, "SELECT id FROM emergencies WHERE external_id = '$external_id' LIMIT 1");
    }

    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'No matching emergency found'
        ]);
        return;
    }

    echo json_encode([
        'success' => true,
        'message' => "Marked as $status by $responder_name"
    ]);
}


// ------------------------------------------------------------------
// Fetch recent emergencies
// ------------------------------------------------------------------
function handleGet($conn) {
    $since = isset($_GET['since']) ? intval($_GET['since']) : 0;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;

    if ($since > 0) {
        $sql = "SELECT * FROM emergencies WHERE id > ? ORDER BY id DESC LIMIT ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $since, $limit);
    } else {
        $sql = "SELECT * FROM emergencies ORDER BY created_at DESC LIMIT ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $limit);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $emergencies = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row['timestamp'] = $row['created_at'];
        $emergencies[] = $row;
    }

    echo json_encode([
        'success' => true,
        'data' => $emergencies,
        'last_id' => !empty($emergencies) ? $emergencies[0]['id'] : 0
    ]);
}
?>