<?php
// admin_notifications.php - Notification handler for admin panel
session_start();

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

switch($action) {
    case 'get_notifications':
        getNotifications($conn);
        break;
    case 'get_unread_count':
        getUnreadCount($conn);
        break;
    case 'get_realtime_updates':
        getRealtimeUpdates($conn);
        break;
    case 'mark_read':
        markNotificationRead($conn);
        break;
    case 'mark_all_read':
        markAllNotificationsRead($conn);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function getNotifications($conn) {
    $notifications = [];
    $lastCheck = isset($_GET['last_check']) ? intval($_GET['last_check']) : 0;
    
    // 1. Pending Document Requests
    $docSql = "SELECT 
                dr.id as request_id,
                dr.document_type,
                dr.status,
                dr.request_date,
                r.first_name,
                r.last_name,
                'document' as type,
                'pending' as notif_status
            FROM document_requests dr
            JOIN resident r ON dr.resident_id = r.id
            WHERE dr.status = 'pending'
            ORDER BY dr.request_date DESC";
    
    $docResult = mysqli_query($conn, $docSql);
    $pendingDocs = 0;
    if ($docResult) {
        while ($row = mysqli_fetch_assoc($docResult)) {
            $pendingDocs++;
            $notifications[] = [
                'id' => 'doc_' . $row['request_id'],
                'type' => 'document_pending',
                'title' => '📄 New Document Request',
                'message' => $row['first_name'] . ' ' . $row['last_name'] . ' requested a ' . $row['document_type'],
                'link' => '#',
                'section' => 'documents',
                'status_filter' => 'pending',
                'request_id' => $row['request_id'],
                'is_read' => false,
                'created_at' => $row['request_date'],
                'timestamp' => strtotime($row['request_date'])
            ];
        }
    }
    
    // 2. Pending Equipment/Vehicle/Facility Bookings
    $bookingSql = "SELECT 
                    r.id as request_id,
                    r.request_type,
                    r.status,
                    r.request_date,
                    r.item_global_id,
                    res.first_name,
                    res.last_name,
                    CASE 
                        WHEN r.request_type = 'equipment' THEN 'Equipment'
                        WHEN r.request_type = 'facility' THEN 'Facility'
                        WHEN r.request_type = 'vehicle' THEN 'Vehicle'
                    END as item_type
                FROM requests r
                JOIN resident res ON r.resident_id = res.id
                WHERE r.status = 'pending'
                ORDER BY r.request_date DESC";
    
    $bookingResult = mysqli_query($conn, $bookingSql);
    $pendingBookings = 0;
    if ($bookingResult) {
        while ($row = mysqli_fetch_assoc($bookingResult)) {
            $pendingBookings++;
            
            $icon = '';
            if ($row['request_type'] == 'equipment') $icon = '🔧';
            elseif ($row['request_type'] == 'facility') $icon = '🏢';
            else $icon = '🚗';
            
            $notifications[] = [
                'id' => 'booking_' . $row['request_id'],
                'type' => 'booking_pending',
                'title' => $icon . ' New ' . ucfirst($row['request_type']) . ' Booking',
                'message' => $row['first_name'] . ' ' . $row['last_name'] . ' booked a ' . $row['item_type'],
                'link' => '#',
                'section' => 'bookings',
                'status_filter' => 'pending',
                'request_id' => $row['request_id'],
                'is_read' => false,
                'created_at' => $row['request_date'],
                'timestamp' => strtotime($row['request_date'])
            ];
        }
    }
    
    // 3. Unverified Accounts (New Registrations)
    $unverifiedSql = "SELECT 
                        id as resident_id,
                        first_name,
                        last_name,
                        email,
                        created_at
                    FROM resident
                    WHERE is_verified = 0 AND is_active = 1
                    ORDER BY created_at DESC";
    
    $unverifiedResult = mysqli_query($conn, $unverifiedSql);
    $unverifiedCount = 0;
    if ($unverifiedResult) {
        while ($row = mysqli_fetch_assoc($unverifiedResult)) {
            $unverifiedCount++;
            $notifications[] = [
                'id' => 'resident_' . $row['resident_id'],
                'type' => 'resident_unverified',
                'title' => '👤 New Resident Registration',
                'message' => $row['first_name'] . ' ' . $row['last_name'] . ' registered and needs verification',
                'link' => '#',
                'section' => 'accounts',
                'subview' => 'unverified',
                'resident_id' => $row['resident_id'],
                'is_read' => false,
                'created_at' => $row['created_at'],
                'timestamp' => strtotime($row['created_at'])
            ];
        }
    }
    
    // Sort by date (newest first)
    usort($notifications, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    // Get unread notifications from session
    $readNotifications = isset($_SESSION['read_admin_notifications']) ? $_SESSION['read_admin_notifications'] : [];
    
    foreach ($notifications as &$notif) {
        if (in_array($notif['id'], $readNotifications)) {
            $notif['is_read'] = true;
        }
    }
    
    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'counts' => [
            'pending_documents' => $pendingDocs,
            'pending_bookings' => $pendingBookings,
            'unverified_residents' => $unverifiedCount,
            'total' => count($notifications)
        ],
        'last_check' => time()
    ]);
}

function getUnreadCount($conn) {
    // Get counts of pending items
    $docCount = 0;
    $docResult = mysqli_query($conn, "SELECT COUNT(*) as count FROM document_requests WHERE status = 'pending'");
    if ($docResult) $docCount = mysqli_fetch_assoc($docResult)['count'];
    
    $bookingCount = 0;
    $bookingResult = mysqli_query($conn, "SELECT COUNT(*) as count FROM requests WHERE status = 'pending'");
    if ($bookingResult) $bookingCount = mysqli_fetch_assoc($bookingResult)['count'];
    
    $residentCount = 0;
    $residentResult = mysqli_query($conn, "SELECT COUNT(*) as count FROM resident WHERE is_verified = 0 AND is_active = 1");
    if ($residentResult) $residentCount = mysqli_fetch_assoc($residentResult)['count'];
    
    $readNotifications = isset($_SESSION['read_admin_notifications']) ? $_SESSION['read_admin_notifications'] : [];
    $totalUnread = ($docCount + $bookingCount + $residentCount) - count($readNotifications);
    if ($totalUnread < 0) $totalUnread = 0;
    
    echo json_encode([
        'success' => true,
        'unread_count' => $totalUnread,
        'details' => [
            'documents' => $docCount,
            'bookings' => $bookingCount,
            'residents' => $residentCount
        ]
    ]);
}

function getRealtimeUpdates($conn) {
    $lastTimestamp = isset($_GET['last_timestamp']) ? intval($_GET['last_timestamp']) : 0;
    $newItems = [];
    
    // Check for new document requests
    $docSql = "SELECT 
                dr.id as request_id,
                dr.document_type,
                dr.request_date,
                r.first_name,
                r.last_name
            FROM document_requests dr
            JOIN resident r ON dr.resident_id = r.id
            WHERE dr.status = 'pending' AND UNIX_TIMESTAMP(dr.request_date) > ?
            ORDER BY dr.request_date DESC";
    
    $stmt = mysqli_prepare($conn, $docSql);
    mysqli_stmt_bind_param($stmt, "i", $lastTimestamp);
    mysqli_stmt_execute($stmt);
    $docResult = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($docResult)) {
        $newItems[] = [
            'type' => 'document',
            'id' => $row['request_id'],
            'title' => 'New Document Request',
            'message' => $row['first_name'] . ' ' . $row['last_name'] . ' requested a ' . $row['document_type'],
            'timestamp' => strtotime($row['request_date'])
        ];
    }
    
    // Check for new booking requests
    $bookingSql = "SELECT 
                    r.id as request_id,
                    r.request_type,
                    r.request_date,
                    res.first_name,
                    res.last_name,
                    CASE 
                        WHEN r.request_type = 'equipment' THEN 'Equipment'
                        WHEN r.request_type = 'facility' THEN 'Facility'
                        WHEN r.request_type = 'vehicle' THEN 'Vehicle'
                    END as item_type
                FROM requests r
                JOIN resident res ON r.resident_id = res.id
                WHERE r.status = 'pending' AND UNIX_TIMESTAMP(r.request_date) > ?
                ORDER BY r.request_date DESC";
    
    $stmt = mysqli_prepare($conn, $bookingSql);
    mysqli_stmt_bind_param($stmt, "i", $lastTimestamp);
    mysqli_stmt_execute($stmt);
    $bookingResult = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($bookingResult)) {
        $icon = '';
        if ($row['request_type'] == 'equipment') $icon = '🔧';
        elseif ($row['request_type'] == 'facility') $icon = '🏢';
        else $icon = '🚗';
        
        $newItems[] = [
            'type' => 'booking',
            'id' => $row['request_id'],
            'title' => $icon . ' New ' . ucfirst($row['request_type']) . ' Booking',
            'message' => $row['first_name'] . ' ' . $row['last_name'] . ' booked a ' . $row['item_type'],
            'timestamp' => strtotime($row['request_date'])
        ];
    }
    
    // Check for new resident registrations
    $residentSql = "SELECT 
                        id as resident_id,
                        first_name,
                        last_name,
                        created_at
                    FROM resident
                    WHERE is_verified = 0 AND is_active = 1 AND UNIX_TIMESTAMP(created_at) > ?
                    ORDER BY created_at DESC";
    
    $stmt = mysqli_prepare($conn, $residentSql);
    mysqli_stmt_bind_param($stmt, "i", $lastTimestamp);
    mysqli_stmt_execute($stmt);
    $residentResult = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($residentResult)) {
        $newItems[] = [
            'type' => 'resident',
            'id' => $row['resident_id'],
            'title' => '👤 New Resident Registration',
            'message' => $row['first_name'] . ' ' . $row['last_name'] . ' registered and needs verification',
            'timestamp' => strtotime($row['created_at'])
        ];
    }
    
    // Sort by timestamp descending
    usort($newItems, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    echo json_encode([
        'success' => true,
        'new_items' => $newItems,
        'has_updates' => count($newItems) > 0,
        'current_time' => time()
    ]);
}

function markNotificationRead($conn) {
    $notification_id = isset($_POST['notification_id']) ? $_POST['notification_id'] : '';
    
    if (empty($notification_id)) {
        echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
        return;
    }
    
    if (!isset($_SESSION['read_admin_notifications'])) {
        $_SESSION['read_admin_notifications'] = [];
    }
    
    if (!in_array($notification_id, $_SESSION['read_admin_notifications'])) {
        $_SESSION['read_admin_notifications'][] = $notification_id;
    }
    
    echo json_encode(['success' => true]);
}

function markAllNotificationsRead($conn) {
    $_SESSION['read_admin_notifications'] = [];
    echo json_encode(['success' => true]);
}
?>