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

// POST - Receive emergency from Android app
if ($method === 'POST') {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // If no JSON, try POST data
    if (!$input && isset($_POST['type'])) {
        $input = $_POST;
    }
    
    // Validate required fields
    $required = ['type', 'barangay', 'name', 'latitude', 'longitude', 'caller_number'];
    $missing = [];
    
    foreach ($required as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            $missing[] = $field;
        }
    }
    
    if (!empty($missing)) {
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields: ' . implode(', ', $missing)
        ]);
        exit();
    }
    
    // Sanitize input
    $type = mysqli_real_escape_string($conn, $input['type']);
    $name = mysqli_real_escape_string($conn, $input['name']);
    $barangay = mysqli_real_escape_string($conn, $input['barangay']);
    $latitude = floatval($input['latitude']);
    $longitude = floatval($input['longitude']);
    $caller_number = mysqli_real_escape_string($conn, $input['caller_number']);
    
    // Insert emergency into database
    $sql = "INSERT INTO emergencies (emergency_type, name, barangay, latitude, longitude, caller_number, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "sssdds", $type, $name, $barangay, $latitude, $longitude, $caller_number);
    
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
    
// GET - Fetch emergencies for dashboard
} elseif ($method === 'GET') {
    $since = isset($_GET['since']) ? intval($_GET['since']) : 0;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
    
    if ($since > 0) {
        // Get emergencies newer than the given ID
        $sql = "SELECT * FROM emergencies WHERE id > ? ORDER BY id DESC LIMIT ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $since, $limit);
    } else {
        // Get recent emergencies
        $sql = "SELECT * FROM emergencies ORDER BY created_at DESC LIMIT ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $limit);
    }
    
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $emergencies = [];
    while ($row = mysqli_fetch_assoc($result)) {
        // Format timestamp for display
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