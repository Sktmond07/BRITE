<?php
// resident/dashboard.php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'resident') {
    header('Location: ../sign_in.php');
    exit();
}

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

$resident_id = $_SESSION['user_id'];
$resident_name = $_SESSION['username'];

$sql = "SELECT id, first_name, last_name, middle_name, email, phone, address, is_verified 
        FROM resident WHERE id = ? AND is_active = 1";
$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("Database error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, "i", $resident_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$resident = mysqli_fetch_assoc($result);

if (!$resident) {
    session_destroy();
    header('Location: ../sign_in.php?error=account_not_found');
    exit();
}

$payment_message = '';
$payment_error = '';

if (isset($_SESSION['payment_success'])) {
    $payment_message = $_SESSION['payment_success'];
    unset($_SESSION['payment_success']);
}
if (isset($_SESSION['payment_message'])) {
    $payment_message = $_SESSION['payment_message'];
    unset($_SESSION['payment_message']);
}
if (isset($_SESSION['payment_error'])) {
    $payment_error = $_SESSION['payment_error'];
    unset($_SESSION['payment_error']);
}

if (isset($_GET['payment']) && $_GET['payment'] === 'success') {
    $payment_message = 'Payment completed successfully! Your document will be processed.';
} elseif (isset($_GET['payment']) && $_GET['payment'] === 'failed') {
    $payment_error = 'Payment was not completed. Please try again.';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Dashboard - BRITE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style><?php include('resident.css'); ?></style>
    <style>
        /* ===== MODERN LOADING SPINNER ===== */
        .loading-spinner-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 300px;
            width: 100%;
            gap: 20px;
        }
        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 4px solid #e8f5e9;
            border-top: 4px solid #2e7d32;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            position: relative;
        }
        .loading-spinner::before {
            content: '';
            position: absolute;
            top: -8px; left: -8px; right: -8px; bottom: -8px;
            border: 4px solid transparent;
            border-top: 4px solid #43e97b;
            border-radius: 50%;
            animation: spin 1.2s linear infinite reverse;
        }
        .loading-spinner-text {
            font-size: 14px;
            color: #2e7d32;
            font-weight: 500;
            letter-spacing: 0.5px;
            animation: pulse-text 1.5s ease-in-out infinite;
        }
        .loading-dots::after {
            content: '';
            animation: dots 1.5s steps(4, end) infinite;
        }
        .skeleton {
            background: #f0f0f0;
            border-radius: 8px;
            animation: shimmer 1.5s ease-in-out infinite;
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        @keyframes pulse-text { 0%, 100% { opacity: 0.6; } 50% { opacity: 1; } }
        @keyframes dots {
            0% { content: ''; } 25% { content: '.'; } 50% { content: '..'; }
            75% { content: '...'; } 100% { content: ''; }
        }
        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        .loading-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.65);
            backdrop-filter: blur(4px);
            z-index: 10000;
            display: none;
            justify-content: center;
            align-items: center;
        }
        .loading-overlay.active { display: flex; }
        .loading-overlay-content {
            background: white;
            padding: 35px 45px;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            min-width: 200px;
        }
        .loading-overlay-content .spinner {
            width: 50px; height: 50px;
            margin: 0 auto 20px;
            border: 4px solid #e8f5e9;
            border-top: 4px solid #2e7d32;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            position: relative;
        }
        .loading-overlay-content .spinner::before {
            content: '';
            position: absolute;
            top: -8px; left: -8px; right: -8px; bottom: -8px;
            border: 4px solid transparent;
            border-top: 4px solid #43e97b;
            border-radius: 50%;
            animation: spin 1.2s linear infinite reverse;
        }
        .loading-overlay-content p {
            margin: 0; font-size: 15px; color: #1a472a;
            font-weight: 500; letter-spacing: 0.3px;
        }
        .loading-overlay-content .sub-text {
            margin-top: 8px; font-size: 12px; color: #999;
        }

        /* Notification Bar */
        .notification-bar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            animation: slideDown 0.5s ease;
            cursor: pointer;
            transition: transform 0.3s ease;
        }
        .notification-bar:hover { transform: translateY(-2px); }
        .notification-bar.success { background: linear-gradient(135deg, #11998e, #38ef7d); }
        .notification-bar.warning { background: linear-gradient(135deg, #f2994a, #f2c94c); }
        .notification-icon { font-size: 24px; margin-right: 10px; }
        .notification-content { flex: 1; }
        .notification-title { font-weight: bold; font-size: 1rem; margin-bottom: 4px; }
        .notification-message { font-size: 0.85rem; opacity: 0.95; }
        .notification-close {
            background: none; border: none; color: white;
            font-size: 18px; cursor: pointer; opacity: 0.8;
            transition: opacity 0.3s;
        }
        .notification-close:hover { opacity: 1; }
        @keyframes slideDown {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .submenu li { cursor: pointer; }
        .submenu li:hover { background: rgba(67, 233, 123, 0.25); }
        .submenu li.active-sub { background: #2b9b54; color: white; }
        .nav-item.has-submenu { cursor: pointer; }

        /* ===== TOP HEADER ===== */
        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            background: white;
            padding: 16px 32px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            border-bottom: 1px solid #e2e8f0;
            position: relative;
            z-index: 100;
        }

        .top-header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        /* Profile Area */
        .profile-area {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 30px;
            transition: background 0.3s ease;
        }
        .profile-area:hover { background: rgba(0,0,0,0.05); }

        /* Notification Bell */
        .notification-bell-container {
            position: relative;
            cursor: pointer;
        }
        .notification-bell {
            padding: 8px 10px;
            border-radius: 50%;
            transition: all 0.3s ease;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        .notification-bell:hover { background: rgba(0,0,0,0.05); }
        .notification-bell i { font-size: 20px; color: #555; }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: bold;
            min-width: 18px;
            text-align: center;
        }
        .notification-badge.pulse {
            animation: badgePulse 1.5s ease-in-out infinite;
        }
        @keyframes badgePulse {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); }
            50% { transform: scale(1.1); box-shadow: 0 0 0 8px rgba(220, 53, 69, 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
        }

        /* ===== DROPDOWNS (moved to body level) ===== */
        .profile-dropdown {
            position: fixed;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 100000;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            min-width: 220px;
            overflow: hidden;
        }
        .profile-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .notifications-dropdown {
            position: fixed;
            z-index: 100001;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            width: 380px;
            max-height: 450px;
            display: none;
            flex-direction: column;
            overflow: hidden;
        }
        .notifications-dropdown.show {
            display: flex;
        }

        /* Dropdown Content */
        .dropdown-header {
            padding: 16px;
            background: #f8faf8;
            border-bottom: 1px solid #e2efe8;
        }
        .dropdown-header .user-name {
            font-weight: 700; color: #1a472a; margin-bottom: 4px;
        }
        .dropdown-header .user-email {
            font-size: 12px; color: #666;
        }
        .dropdown-divider {
            height: 1px; background: #e2efe8; margin: 8px 0;
        }
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: #333;
            cursor: pointer;
            transition: all 0.2s;
        }
        .dropdown-item:hover { background: #f0f4f9; }
        .dropdown-item i {
            width: 20px; color: #2eaa5e; font-size: 16px;
        }
        .dropdown-item.logout-item { color: #dc3545; }
        .dropdown-item.logout-item i { color: #dc3545; }
        .dropdown-item.logout-item:hover { background: #fee; }

        /* Notifications Header */
        .notifications-header {
            padding: 12px 15px;
            background: linear-gradient(135deg, #1a472a, #2eaa5e);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
            flex-wrap: wrap;
        }
        .notifications-header h4 {
            margin: 0;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .notifications-header .unread-count-badge {
            background: rgba(255,255,255,0.25);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .notif-header-actions {
            display: flex;
            gap: 6px;
            align-items: center;
        }
        .notif-action-btn {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.25);
            color: white;
            padding: 5px 12px;
            border-radius: 16px;
            font-size: 11px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }
        .notif-action-btn:hover { background: rgba(255,255,255,0.3); }
        .notif-action-btn.danger {
            background: rgba(220, 53, 69, 0.5);
            border-color: rgba(220, 53, 69, 0.6);
        }
        .notif-action-btn.danger:hover { background: rgba(220, 53, 69, 0.75); }
        .notif-action-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .notif-action-btn i { font-size: 11px; }

        .notif-selection-bar {
            padding: 8px 15px;
            background: #e3f2fd;
            border-bottom: 1px solid #bbdefb;
            display: none;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
            color: #1565c0;
            font-weight: 500;
        }
        .notif-selection-bar.show { display: flex; }

        .notifications-list {
            overflow-y: auto;
            flex: 1;
            padding: 5px 0;
        }
        .notifications-list::-webkit-scrollbar { width: 4px; }
        .notifications-list::-webkit-scrollbar-thumb {
            background: #c8e6c9; border-radius: 10px;
        }
        .empty-notifications {
            text-align: center; padding: 30px 20px; color: #999;
        }
        .empty-notifications i {
            font-size: 40px; color: #ddd; margin-bottom: 10px;
        }
        .empty-notifications p { margin: 0; font-size: 14px; }

        .notification-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 15px;
            border-bottom: 1px solid #f5f5f5;
            transition: background 0.2s;
            position: relative;
            cursor: pointer;
        }
        .notification-item:hover { background: #f8faf9; }
        .notification-item.unread {
            background: #e8f5e9;
            border-left: 3px solid #2e7d32;
        }
        .notification-item.read {
            background: white;
            border-left: 3px solid transparent;
        }
        .notification-item.selected {
            background: #c8e6c9;
            border-left: 3px solid #2e7d32;
        }
        .notification-checkbox {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            accent-color: #2e7d32;
            cursor: pointer;
            flex-shrink: 0;
            display: none;
        }
        .notification-checkbox.visible { display: block; }
        .notification-content {
            flex: 1;
            min-width: 0;
            cursor: pointer;
        }

        /* ===== MOBILE: DROPDOWNS POSITIONED BELOW HEADER ===== */
        @media (max-width: 768px) {
            .top-header {
                padding: 12px 16px 12px 70px;
                z-index: 50;
            }

            .profile-text { display: none; }

            .profile-area {
                padding: 4px 8px 4px 6px;
                gap: 6px;
            }

            .avatar-container {
                width: 38px;
                height: 38px;
            }

            .notification-bell {
                width: 40px;
                height: 40px;
                padding: 0;
            }
            .notification-bell i { font-size: 18px; }

            /* Profile dropdown - positioned below header, NOT bottom sheet */
            .profile-dropdown {
                position: fixed !important;
                top: 70px !important;
                bottom: auto !important;
                left: 10px !important;
                right: 10px !important;
                width: auto !important;
                max-width: calc(100% - 20px) !important;
                min-width: auto !important;
                border-radius: 16px !important;
                z-index: 100000 !important;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2) !important;
            }

            /* Notifications dropdown - positioned below header, NOT bottom sheet */
            .notifications-dropdown {
                position: fixed !important;
                top: 70px !important;
                bottom: auto !important;
                left: 10px !important;
                right: 10px !important;
                width: auto !important;
                max-width: calc(100% - 20px) !important;
                max-height: 70vh !important;
                border-radius: 16px !important;
                z-index: 100001 !important;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2) !important;
            }

            .profile-dropdown.show,
            .notifications-dropdown.show {
                display: flex !important;
            }
            .profile-dropdown.show {
                display: block !important;
            }
        }
        /* ============ PUSH NOTIFICATION TOGGLE ============ */
.notif-action-btn.push-active {
    background: rgba(76, 175, 80, 0.6);
    border-color: rgba(76, 175, 80, 0.8);
}

.notif-action-btn.push-active:hover {
    background: rgba(76, 175, 80, 0.85);
}

.notif-action-btn.push-inactive {
    background: rgba(158, 158, 158, 0.4);
    border-color: rgba(158, 158, 158, 0.5);
}

.notif-action-btn.push-inactive:hover {
    background: rgba(158, 158, 158, 0.6);
}

.notif-action-btn.push-denied {
    background: rgba(244, 67, 54, 0.4);
    border-color: rgba(244, 67, 54, 0.5);
}

.notif-action-btn.push-denied:hover {
    background: rgba(244, 67, 54, 0.6);
}

/* Push toggle icon animation */
#pushToggleIcon.fa-bell {
    animation: none;
}

#pushToggleIcon.fa-bell-slash {
    opacity: 0.6;
}

/* Push Status Bar */
.push-status-bar {
    padding: 10px 15px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 12px;
    animation: slideDown 0.3s ease;
    border-bottom: 1px solid #eef2ef;
}

.push-status-bar.success {
    background: #e8f5e9;
    color: #2e7d32;
}

.push-status-bar.warning {
    background: #fff3cd;
    color: #856404;
}

.push-status-bar.error {
    background: #f8d7da;
    color: #721c24;
}

.push-status-content {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
}

.push-status-content i {
    font-size: 14px;
}

.push-status-close {
    background: none;
    border: none;
    color: inherit;
    font-size: 16px;
    cursor: pointer;
    opacity: 0.6;
    padding: 0 5px;
    transition: opacity 0.2s;
}

.push-status-close:hover {
    opacity: 1;
}

/* Mobile adjustments for push toggle */
@media (max-width: 768px) {
    .notif-action-btn#pushToggleBtn {
        padding: 5px 8px;
        font-size: 10px;
    }
    
    .notif-action-btn#pushToggleBtn span {
        display: none;
    }
    
    .notif-action-btn#pushToggleBtn i {
        font-size: 14px;
    }
}
/* ============ PUSH CONFIRMATION DIALOG (in-modal) ============ */
.push-confirm-dialog {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255, 255, 255, 0.97);
    backdrop-filter: blur(4px);
    z-index: 10;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    animation: fadeIn 0.2s ease;
}

.push-confirm-content {
    text-align: center;
    max-width: 320px;
    width: 100%;
}

.push-confirm-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    margin: 0 auto 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    background: #e8f5e9;
    color: #2e7d32;
    animation: pushIconPop 0.4s ease;
}

.push-confirm-icon.warning {
    background: #fff3cd;
    color: #856404;
}

.push-confirm-icon.error {
    background: #f8d7da;
    color: #721c24;
}

@keyframes pushIconPop {
    0% { transform: scale(0); }
    60% { transform: scale(1.15); }
    100% { transform: scale(1); }
}

.push-confirm-title {
    font-size: 1rem;
    font-weight: 700;
    color: #1a472a;
    margin-bottom: 8px;
}

.push-confirm-message {
    font-size: 0.8rem;
    color: #666;
    line-height: 1.5;
    margin-bottom: 20px;
}

.push-confirm-message ul {
    text-align: left;
    margin: 10px 0;
    padding-left: 20px;
    font-size: 0.78rem;
}

.push-confirm-message ul li {
    margin-bottom: 4px;
}

.push-confirm-actions {
    display: flex;
    gap: 10px;
}

.push-confirm-btn {
    flex: 1;
    padding: 10px 16px;
    border: none;
    border-radius: 10px;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.push-confirm-btn.cancel {
    background: #f0f0f0;
    color: #666;
}

.push-confirm-btn.cancel:hover {
    background: #e0e0e0;
}

.push-confirm-btn.confirm {
    background: linear-gradient(135deg, #2e7d32, #43e97b);
    color: white;
}

.push-confirm-btn.confirm:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(46, 125, 50, 0.3);
}

.push-confirm-btn.confirm.danger {
    background: linear-gradient(135deg, #dc3545, #c82333);
}

.push-confirm-btn.confirm.danger:hover {
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
}
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay hide" id="sidebarOverlay"></div>

    <div class="dashboard-container">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="brand">
                    <div class="logo-container"><img src="../logo.jpg" alt="Logo"></div>
                    <h2>BRITE</h2>
                </div>
                <div class="sidebar-sub">San Bartolome, Sto Tomas, Pampanga</div>
            </div>
            <div class="nav-menu">
                <div class="nav-item active" data-view="dashboard">
                    <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
                </div>
                <div class="nav-item has-submenu" id="complaintsParent">
                    <i class="fas fa-gavel"></i>
                    <span>Complaints</span>
                    <i class="fas fa-chevron-down chevron-icon"></i>
                </div>
                <ul class="submenu" id="complaintsSubmenu">
                    <li data-subview="file_complaint"><i class="fas fa-pen-alt"></i> File Complaint</li>
                    <li data-subview="my_complaints"><i class="fas fa-list"></i> My Complaints</li>
                </ul>
                <div class="nav-item has-submenu" id="documentsParent">
                    <i class="fas fa-folder-open"></i><span>Documents</span>
                    <i class="fas fa-chevron-down chevron-icon"></i>
                </div>
                <ul class="submenu" id="documentsSubmenu">
                    <li data-subview="request"><i class="fas fa-file-alt"></i> Request Document</li>
                    <li data-subview="history"><i class="fas fa-history"></i> Request History</li>
                </ul>
                <div class="nav-item has-submenu" id="equipmentParent">
                    <i class="fas fa-tools"></i><span>Equipment & Facilities</span>
                    <i class="fas fa-chevron-down chevron-icon"></i>
                </div>
                <ul class="submenu" id="equipmentSubmenu">
                    <li data-subview="equipment_list"><i class="fas fa-list"></i> Available Equipment</li>
                    <li data-subview="equipment_bookings"><i class="fas fa-calendar-alt"></i> My Bookings</li>
                </ul>
                <div class="nav-item" data-view="profile">
                    <i class="fas fa-user-circle"></i><span>My Profile</span>
                </div>
            </div>
            <div class="sidebar-footer">
                <div class="resident-badge">
                    <div class="mini-avatar"><i class="fas fa-user"></i></div>
                    <div class="resident-info">
                        <h5><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></h5>
                        <p>Resident</p>
                    </div>
                </div>
            </div>
        </aside>

        <main class="main-content">
            <div class="top-header">
                <div class="page-title">
                    <h1>Resident Dashboard</h1>
                    <p>Manage your document requests</p>
                </div>

                <div class="top-header-right">
                    <div class="profile-area" id="profileArea">
                        <div class="profile-text">
                            <div class="name"><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></div>
                            <div class="role">Resident</div>
                        </div>
                        <div class="avatar-container">
                            <div class="avatar-fallback"><i class="fas fa-user-circle"></i></div>
                        </div>
                    </div>

                    <div class="notification-bell-container" id="notificationBellContainer">
                        <div class="notification-bell" id="notificationBell">
                            <i class="fas fa-bell"></i>
                            <span class="notification-badge" id="notificationBadge" style="display: none;">0</span>
                        </div>
                    </div>
                </div>
            </div>

            <div id="alertContainer"></div>
            <div class="dashboard-body" id="dashboardBody">
                <div class="loading-spinner-container" id="initialLoading">
                    <div class="loading-spinner"></div>
                    <div class="loading-spinner-text">Loading your dashboard<span class="loading-dots"></span></div>
                </div>
                <?php if ($payment_message): ?>
                <div class="notification-bar success" style="margin-bottom: 20px;">
                    <div style="display: flex; align-items: center;">
                        <i class="fas fa-check-circle notification-icon"></i>
                        <div class="notification-content">
                            <div class="notification-title">Payment Successful!</div>
                            <div class="notification-message"><?php echo htmlspecialchars($payment_message); ?></div>
                        </div>
                    </div>
                    <button class="notification-close" onclick="this.parentElement.remove()">×</button>
                </div>
                <?php endif; ?>

                <?php if ($payment_error): ?>
                <div class="notification-bar warning" style="margin-bottom: 20px;">
                    <div style="display: flex; align-items: center;">
                        <i class="fas fa-exclamation-triangle notification-icon"></i>
                        <div class="notification-content">
                            <div class="notification-title">Payment Issue</div>
                            <div class="notification-message"><?php echo htmlspecialchars($payment_error); ?></div>
                        </div>
                    </div>
                    <button class="notification-close" onclick="this.parentElement.remove()">×</button>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- ============ DROPDOWN BACKDROP (for mobile) ============ -->
    <div class="dropdown-backdrop" id="dropdownBackdrop"></div>

    <!-- ============ PROFILE DROPDOWN (Body-level) ============ -->
    <div class="profile-dropdown" id="profileDropdown">
        <div class="dropdown-header">
            <div class="user-name"><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></div>
            <div class="user-email"><?php echo htmlspecialchars($resident['email']); ?></div>
        </div>
        <div class="dropdown-divider"></div>
        <div class="dropdown-item" id="settingsBtn"><i class="fas fa-cog"></i><span>Settings</span></div>
        <div class="dropdown-item logout-item" id="logoutBtn"><i class="fas fa-sign-out-alt"></i><span>Logout</span></div>
    </div>

   <!-- ============ NOTIFICATIONS DROPDOWN (Body-level) ============ -->
<div class="notifications-dropdown" id="notificationsDropdown">
    <div class="notifications-header">
        <h4>
            <i class="fas fa-bell"></i> Notifications
            <span class="unread-count-badge" id="unreadCountBadge" style="display:none;">0</span>
        </h4>
        <div class="notif-header-actions" id="notifHeaderActions">
            <button class="notif-action-btn" id="markAllReadBtn" title="Mark all as read">
                <i class="fas fa-check-double"></i> Read All
            </button>
            <button class="notif-action-btn" id="selectModeBtn" title="Select notifications to delete">
                <i class="fas fa-check-square"></i> Select
            </button>
            <!-- ADD THIS PUSH TOGGLE BUTTON -->
            <button class="notif-action-btn" id="pushToggleBtn" title="Toggle push notifications" style="position: relative;">
                <i class="fas fa-bell" id="pushToggleIcon"></i>
                <span id="pushToggleLabel" style="font-size: 10px;">Push</span>
            </button>
        </div>
        <div class="notif-header-actions" id="notifSelectionActions" style="display:none;">
            <button class="notif-action-btn danger" id="deleteSelectedBtn" disabled>
                <i class="fas fa-trash-alt"></i> Delete Selected
            </button>
            <button class="notif-action-btn" id="cancelSelectBtn">
                <i class="fas fa-times"></i> Cancel
            </button>
        </div>
    </div>
    
    <!-- ADD THIS PUSH STATUS BAR -->
    <div class="push-status-bar" id="pushStatusBar" style="display: none;">
        <div class="push-status-content">
            <i class="fas fa-info-circle" id="pushStatusIcon"></i>
            <span id="pushStatusText">Push notifications are enabled</span>
        </div>
        <button class="push-status-close" onclick="hidePushStatusBar()">&times;</button>
    </div>
    
    <div class="notif-selection-bar" id="notifSelectionBar">
        <span id="selectionInfo">0 selected</span>
        <span style="font-size:11px; opacity:0.7;">
            <i class="fas fa-info-circle"></i> Check the boxes to delete
        </span>
    </div>
    <div class="notifications-list" id="notificationsList">
        <div class="empty-notifications">
            <i class="fas fa-bell-slash"></i>
            <p>No notifications</p>
        </div>
    </div>

    <!-- ============ PUSH CONFIRMATION DIALOG (in-modal) ============ -->
<div class="push-confirm-dialog" id="pushConfirmDialog" style="display: none;">
    <div class="push-confirm-content">
        <div class="push-confirm-icon" id="pushConfirmIcon">
            <i class="fas fa-bell"></i>
        </div>
        <div class="push-confirm-title" id="pushConfirmTitle">Enable Push Notifications?</div>
        <div class="push-confirm-message" id="pushConfirmMessage">
            You will receive real-time alerts about document pickups, equipment returns, and overdue items.
        </div>
        <div class="push-confirm-actions">
            <button class="push-confirm-btn cancel" id="pushConfirmCancelBtn">Cancel</button>
            <button class="push-confirm-btn confirm" id="pushConfirmYesBtn">Enable</button>
        </div>
    </div>
</div>
</div>

    <form id="logoutForm" method="POST" action="../logout.php" style="display: none;"></form>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-overlay-content">
            <div class="spinner"></div>
            <p id="loadingMessage">Processing...<span class="loading-dots"></span></p>
            <div class="sub-text">Please wait</div>
        </div>
    </div>

    <!-- Dynamic Request Modal -->
    <div id="requestModal" class="request-modal">
        <div class="request-modal-content">
            <div class="request-modal-header">
                <h3><i class="fas fa-file-alt"></i> <span id="modalTitle">Request Document</span></h3>
                <button class="close-modal" onclick="closeRequestModal()">&times;</button>
            </div>
            <div class="request-modal-body">
                <form id="documentRequestForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="submit_request">
                    <input type="hidden" name="document_type" id="selectedDocType">
                    <input type="hidden" name="document_id" id="selectedDocId">
                    <input type="hidden" name="fee" id="selectedDocFee">
                    <div class="selected-doc-info">
                        <span class="selected-doc-name" id="selectedDocName"></span>
                        <span class="selected-doc-fee" id="selectedDocFeeDisplay"></span>
                    </div>
                    <div id="dynamicFieldsContainer"></div>
                    <div class="fee-type-group">
                        <label class="required">Fee Type</label>
                        <div class="fee-options">
                            <div class="fee-option">
                                <input type="radio" name="fee_type" id="regular" value="regular" checked>
                                <label for="regular"><i class="fas fa-user"></i><span class="fee-label">Regular</span><span class="fee-price" id="regularPrice">₱0.00</span></label>
                            </div>
                            <div class="fee-option">
                                <input type="radio" name="fee_type" id="student" value="student">
                                <label for="student"><i class="fas fa-graduation-cap"></i><span class="fee-label">Student</span><span class="fee-price free">FREE</span></label>
                            </div>
                            <div class="fee-option">
                                <input type="radio" name="fee_type" id="senior" value="senior">
                                <label for="senior"><i class="fas fa-user-plus"></i><span class="fee-label">Senior</span><span class="fee-price free">FREE</span></label>
                            </div>
                        </div>
                    </div>
                    <div id="profileNotice" style="display: none;" class="profile-notice">
                        <i class="fas fa-id-card"></i>
                        <div class="profile-status">
                            <strong>Your information has been pre-filled</strong>
                            <div id="profileStatusText">Please review and verify your details before submitting.</div>
                        </div>
                        <span class="profile-verified"><i class="fas fa-check-circle"></i> Verified</span>
                    </div>
                    <div class="form-group">
                        <label class="required">Payment Method <span style="color:red;">*</span></label>
                        <select name="payment_method" id="paymentMethodSelect" required>
                            <option value="online">Online Payment (GCash/Maya)</option>
                            <option value="pay_at_claim">Pay at Claim (Barangay Hall)</option>
                        </select>
                        <small id="paymentMethodHelp">Choose how you want to pay for this document</small>
                    </div>
                    <div id="paymentMethodInfo" class="payment-method-info">
                        <i class="fas fa-info-circle"></i>
                        <span id="paymentMethodInfoText"></span>
                    </div>
                    <div id="idUploadSection" style="background:#fff8e1; padding:15px; border-radius:12px; margin-bottom:20px;">
                        <h4 style="color:#e65100;"><i class="fas fa-id-card"></i> <span id="idLabel">Valid Government ID</span></h4>
                        <div class="form-group">
                            <label class="required" id="idUploadLabel">Upload ID Document</label>
                            <input type="file" name="id_document" id="id_document" required accept=".jpg,.jpeg,.png,.pdf">
                            <small>Accepted: JPG, PNG, PDF (Max 5MB)</small>
                        </div>
                        <div id="idRequirement" class="id-requirement regular">
                            <i class="fas fa-info-circle"></i>
                            <span id="requirementText">Please upload a valid government-issued ID</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Additional Notes</label>
                        <textarea name="notes" id="notesField" rows="2" placeholder="Any additional information..."></textarea>
                    </div>
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label>Quantity (Number of Copies)</label>
                        <input type="number" name="quantity" id="quantity" value="1" min="1" max="10" style="font-size: 16px; padding: 10px;">
                        <small>Maximum of 10 copies per request</small>
                    </div>
                    <button type="submit" class="submit-btn"><i class="fas fa-paper-plane"></i> Submit Request</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <div id="successModal" class="modal-popup">
        <div class="modal-popup-content">
            <div class="modal-popup-header success">
                <i class="fas fa-check-circle"></i>
                <h3>Success!</h3>
            </div>
            <div class="modal-popup-body">
                <p id="successMessage">Operation completed successfully.</p>
            </div>
            <div class="modal-popup-footer">
                <button class="modal-popup-btn success-btn" onclick="closeSuccessModal()">OK</button>
            </div>
        </div>
    </div>

    <div id="errorModal" class="modal-popup">
        <div class="modal-popup-content">
            <div class="modal-popup-header error">
                <i class="fas fa-times-circle"></i>
                <h3>Error!</h3>
            </div>
            <div class="modal-popup-body">
                <p id="errorMessage">An error occurred.</p>
            </div>
            <div class="modal-popup-footer">
                <button class="modal-popup-btn error-btn" onclick="closeErrorModal()">OK</button>
            </div>
        </div>
    </div>

    <div id="confirmModal" class="modal-popup">
        <div class="modal-popup-content">
            <div class="modal-popup-header warning">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Confirm Action</h3>
            </div>
            <div class="modal-popup-body">
                <p id="confirmMessage">Are you sure you want to proceed?</p>
                <p class="warning-text" id="confirmWarning">This action cannot be undone.</p>
            </div>
            <div class="modal-popup-footer">
                <button class="modal-popup-btn cancel-btn" onclick="closeConfirmModal()">Cancel</button>
                <button class="modal-popup-btn confirm-btn" id="confirmYesBtn">Confirm</button>
            </div>
        </div>
    </div>

    <div id="deleteConfirmModal" class="modal-popup">
        <div class="modal-popup-content">
            <div class="modal-popup-header warning">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Delete Notification(s)?</h3>
            </div>
            <div class="modal-popup-body">
                <p id="deleteConfirmMessage">Are you sure you want to delete the selected notification(s)?</p>
                <p class="warning-text">This action cannot be undone.</p>
            </div>
            <div class="modal-popup-footer">
                <button class="modal-popup-btn cancel-btn" onclick="closeDeleteConfirmModal()">Cancel</button>
                <button class="modal-popup-btn error-btn" id="deleteConfirmYesBtn">Delete</button>
            </div>
        </div>
    </div>

    <div id="infoModal" class="modal-popup">
        <div class="modal-popup-content">
            <div class="modal-popup-header info">
                <i class="fas fa-info-circle"></i>
                <h3>Information</h3>
            </div>
            <div class="modal-popup-body">
                <p id="infoMessage">Information message.</p>
            </div>
            <div class="modal-popup-footer">
                <button class="modal-popup-btn info-btn" onclick="closeInfoModal()">OK</button>
            </div>
        </div>
    </div>

    <!-- Payment Options Modal -->
    <div id="paymentOptionsModal" class="request-modal">
        <div class="request-modal-content" style="max-width: 500px;">
            <div class="request-modal-header" style="background: linear-gradient(135deg, #28a745, #1e7e34);">
                <h3><i class="fas fa-credit-card"></i> Payment Options</h3>
                <button class="close-modal" onclick="closePaymentOptionsModal()">&times;</button>
            </div>
            <div class="request-modal-body">
                <div id="paymentOptionsInfo" style="margin-bottom: 20px;">
                    <div class="selected-doc-info" style="background: #e8f5e9;">
                        <div>
                            <strong>Document:</strong> <span id="paymentDocName"></span><br>
                            <strong>Total Amount:</strong> <span id="paymentTotalAmount" style="color: #28a745; font-size: 1.3rem; font-weight: bold;"></span>
                        </div>
                    </div>
                </div>
                <h4 style="margin-bottom: 15px;"><i class="fas fa-credit-card"></i> Choose Payment Method</h4>
                <div class="payment-methods-grid" style="display: grid; gap: 15px; margin-bottom: 20px;">
                    <div class="payment-option-card" onclick="processPayment('gcash')" style="border: 2px solid #ddd; border-radius: 16px; padding: 20px; cursor: pointer; transition: all 0.3s; background: white;">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <div style="background: linear-gradient(135deg, #0066B3, #00B4D8); width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-mobile-alt" style="font-size: 28px; color: white;"></i>
                            </div>
                            <div style="flex: 1;">
                                <h3 style="margin: 0 0 5px 0; color: #0066B3;">GCash</h3>
                                <p style="margin: 0; color: #666; font-size: 14px;">Pay using GCash (Instant confirmation)</p>
                            </div>
                            <div><i class="fas fa-chevron-right" style="color: #0066B3;"></i></div>
                        </div>
                    </div>
                    <div class="payment-option-card" onclick="processPayment('maya')" style="border: 2px solid #ddd; border-radius: 16px; padding: 20px; cursor: pointer; transition: all 0.3s; background: white;">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <div style="background: linear-gradient(135deg, #00A86B, #00C853); width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-mobile-alt" style="font-size: 28px; color: white;"></i>
                            </div>
                            <div style="flex: 1;">
                                <h3 style="margin: 0 0 5px 0; color: #00A86B;">Maya</h3>
                                <p style="margin: 0; color: #666; font-size: 14px;">Pay using Maya (Instant confirmation)</p>
                            </div>
                            <div><i class="fas fa-chevron-right" style="color: #00A86B;"></i></div>
                        </div>
                    </div>
                    <div class="payment-option-card" onclick="processPayAtClaim()" style="border: 2px solid #ddd; border-radius: 16px; padding: 20px; cursor: pointer; transition: all 0.3s; background: white;">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <div style="background: linear-gradient(135deg, #ff9800, #f57c00); width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-building" style="font-size: 28px; color: white;"></i>
                            </div>
                            <div style="flex: 1;">
                                <h3 style="margin: 0 0 5px 0; color: #ff9800;">Pay at Claim</h3>
                                <p style="margin: 0; color: #666; font-size: 14px;">Pay when you claim the document at Barangay Hall</p>
                            </div>
                            <div><i class="fas fa-chevron-right" style="color: #ff9800;"></i></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Pay at Claim Confirmation Modal -->
    <div id="payAtClaimModal" class="request-modal">
        <div class="request-modal-content" style="max-width: 450px;">
            <div class="request-modal-header" style="background: linear-gradient(135deg, #ff9800, #f57c00);">
                <h3><i class="fas fa-hand-holding-usd"></i> Pay at Claim</h3>
                <button class="close-modal" onclick="closePayAtClaimModal()">&times;</button>
            </div>
            <div class="request-modal-body">
                <div style="text-align: center; margin-bottom: 20px;">
                    <i class="fas fa-building" style="font-size: 60px; color: #ff9800;"></i>
                </div>
                <div class="selected-doc-info" style="background: #fff3e0; margin-bottom: 20px;">
                    <div>
                        <strong>Document:</strong> <span id="claimDocName"></span><br>
                        <strong>Amount to Pay:</strong> <span id="claimAmount" style="color: #ff9800; font-size: 1.3rem; font-weight: bold;"></span>
                    </div>
                </div>
                <div style="background: #fff3cd; padding: 15px; border-radius: 12px; margin-bottom: 20px;">
                    <h5><i class="fas fa-info-circle"></i> Important Information</h5>
                    <ul style="margin-top: 10px; padding-left: 20px;">
                        <li>Bring <strong>exact amount</strong> to Barangay Hall</li>
                        <li>Bring your <strong>valid ID</strong> for verification</li>
                        <li>Payment must be made at the time of claiming</li>
                        <li>Visit during office hours: <strong>Monday-Friday, 8:00 AM - 5:00 PM</strong></li>
                        <li>Address: <strong>San Bartolome, Sto Tomas, Pampanga</strong></li>
                    </ul>
                </div>
                <button onclick="confirmPayAtClaim()" class="submit-btn" style="background: linear-gradient(135deg, #ff9800, #f57c00);">
                    <i class="fas fa-check-circle"></i> Confirm Pay at Claim
                </button>
                <button onclick="closePayAtClaimModal()" class="submit-btn" style="background: #6c757d; margin-top: 10px;">
                    <i class="fas fa-arrow-left"></i> Go Back
                </button>
            </div>
        </div>
    </div>

    <!-- Payment Confirmation Modal -->
    <div id="paymentConfirmModal" class="request-modal">
        <div class="request-modal-content" style="max-width: 400px;">
            <div class="request-modal-header" style="background: linear-gradient(135deg, #28a745, #1e7e34);">
                <h3><i class="fas fa-check-circle"></i> Confirm Payment</h3>
                <button class="close-modal" onclick="closePaymentConfirmModal()">&times;</button>
            </div>
            <div class="request-modal-body" style="text-align: center;">
                <div style="margin-bottom: 20px;">
                    <i class="fas fa-credit-card" style="font-size: 60px; color: #28a745;"></i>
                </div>
                <div class="selected-doc-info" style="background: #e8f5e9; margin-bottom: 20px;">
                    <div>
                        <strong>Document:</strong> <span id="confirmDocName"></span><br>
                        <strong>Amount to Pay:</strong>
                        <span id="confirmAmount" style="color: #28a745; font-size: 1.8rem; font-weight: bold; display: block;"></span>
                    </div>
                </div>
                <div style="background: #fff3cd; padding: 15px; border-radius: 12px; margin-bottom: 20px;">
                    <p><i class="fas fa-info-circle"></i> <strong>Payment Method:</strong> <span id="confirmMethod"></span></p>
                    <p>Click "Confirm Payment" to complete your transaction.</p>
                </div>
                <button onclick="confirmPayment()" class="submit-btn" style="background: linear-gradient(135deg, #28a745, #1e7e34);">
                    <i class="fas fa-check-circle"></i> Confirm Payment
                </button>
                <button onclick="closePaymentConfirmModal()" class="submit-btn" style="background: #6c757d; margin-top: 10px;">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- Online Payment Modal -->
    <div id="onlinePaymentModal" class="request-modal">
        <div class="request-modal-content" style="max-width: 500px;">
            <div class="request-modal-header" style="background: linear-gradient(135deg, #00b4d8, #0077b6);">
                <h3><i class="fas fa-qrcode"></i> Complete Your Payment</h3>
                <button class="close-modal" onclick="closeOnlinePaymentModal()">&times;</button>
            </div>
            <div class="request-modal-body" style="text-align: center;">
                <div class="selected-doc-info" style="background: #e0f7fa; margin-bottom: 20px;">
                    <div>
                        <strong>Document:</strong> <span id="onlineDocName"></span><br>
                        <strong>Amount to Pay:</strong> <span id="onlineAmount" style="color: #0077b6; font-size: 1.5rem; font-weight: bold;"></span>
                    </div>
                </div>
                <div style="background: white; padding: 20px; border-radius: 16px; margin-bottom: 20px;">
                    <img id="qrCodeImage" src="" alt="QR Code" style="width: 200px; height: 200px; margin: 0 auto;">
                    <p style="margin-top: 10px; color: #666;">
                        <i class="fas fa-mobile-alt"></i> Scan with GCash or Maya
                    </p>
                </div>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 12px; margin-bottom: 20px;">
                    <h4><i class="fas fa-info-circle"></i> Send payment to:</h4>
                    <p style="font-size: 1.2rem; font-weight: bold; color: #0077b6;" id="paymentNumberDisplay">09949293657</p>
                    <p><strong>Account Name:</strong> Barangay San Bartolome</p>
                    <p><strong>Reference:</strong> <span id="paymentRefDisplay"></span></p>
                    <p class="warning-text" style="color: #ff9800; margin-top: 10px;">
                        <i class="fas fa-exclamation-triangle"></i>
                        After payment, upload your screenshot below
                    </p>
                </div>
                <form id="onlinePaymentForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_payment_proof">
                    <input type="hidden" name="payment_id" id="onlinePaymentId">
                    <input type="hidden" name="reference_number" id="autoReference" value="">
                    <div class="form-group">
                        <label class="required">Upload Payment Screenshot</label>
                        <input type="file" name="payment_proof" id="paymentProof" required accept=".jpg,.jpeg,.png,.pdf">
                        <small>Upload a screenshot of your successful payment</small>
                    </div>
                    <button type="submit" class="submit-btn" style="background: linear-gradient(135deg, #00b4d8, #0077b6);">
                        <i class="fas fa-upload"></i> Submit Payment Proof
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Booking Details Modal -->
    <div id="bookingDetailsModal" class="request-modal">
        <div class="request-modal-content" style="max-width: 700px;">
            <div class="request-modal-header" style="background: linear-gradient(135deg, #1a472a, #43e97b);">
                <h3><i class="fas fa-calendar-alt"></i> Booking Details</h3>
                <button class="close-modal" onclick="closeBookingDetailsModal()">&times;</button>
            </div>
            <div class="request-modal-body" id="bookingDetailsBody"></div>
        </div>
    </div>

    <!-- QR Code Modal -->
    <div id="qrCodeModal" class="request-modal">
        <div class="request-modal-content" style="max-width: 450px;">
            <div class="request-modal-header" style="background: linear-gradient(135deg, #1a472a, #2eaa5e);">
                <h3><i class="fas fa-qrcode"></i> Document QR Code</h3>
                <button class="close-modal" onclick="closeQRCodeModal()">&times;</button>
            </div>
            <div class="request-modal-body" style="text-align: center;">
                <div id="qrCodeLoading" style="display: none; text-align: center; padding: 20px;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 40px; color: #2eaa5e;"></i>
                    <p>Loading QR Code...</p>
                </div>
                <div id="qrCodeContent" style="display: none;">
                    <div id="qrCodeCaptureArea" style="background: white; border-radius: 16px; padding: 20px; margin-bottom: 20px;">
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 16px; margin-bottom: 20px; text-align: center;">
                            <img id="qrCodeDisplay" src="" alt="QR Code" style="width: 200px; height: 200px; margin: 0 auto; display: block;">
                        </div>
                        <div class="selected-doc-info" style="background: #e8f5e9; margin-bottom: 20px;">
                            <div style="text-align: left;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                    <strong>Document:</strong>
                                    <span id="qrDocType" style="color: #333;"></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                    <strong>Resident:</strong>
                                    <span id="qrResidentName" style="color: #333;"></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                    <strong>Request Date:</strong>
                                    <span id="qrRequestDate" style="color: #333;"></span>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <strong>Barangay:</strong>
                                    <span style="color: #333;">San Bartolome, Sto Tomas, Pampanga</span>
                                </div>
                            </div>
                        </div>
                        <div style="background: #fff3cd; padding: 12px; border-radius: 12px;">
                            <p style="margin: 0; font-size: 0.8rem; text-align: center;">
                                <i class="fas fa-info-circle" style="color: #ff9800;"></i>
                                Present this QR code when claiming your document at the Barangay Hall.
                            </p>
                        </div>
                    </div>
                    <div style="display: flex; gap: 12px;">
                        <button onclick="downloadQRCodeCapture()" class="submit-btn" style="background: linear-gradient(135deg, #1a472a, #2eaa5e); flex: 1;">
                            <i class="fas fa-download"></i> Download QR Slip
                        </button>
                        <button onclick="closeQRCodeModal()" class="submit-btn" style="background: #6c757d; flex: 1;">
                            <i class="fas fa-times"></i> Close
                        </button>
                    </div>
                </div>
                <div id="qrCodeError" style="display: none;">
                    <div style="background: #f8d7da; padding: 20px; border-radius: 12px; color: #721c24;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 40px; margin-bottom: 10px;"></i>
                        <p>QR Code not available. The document may still be processing.</p>
                        <button onclick="closeQRCodeModal()" class="submit-btn" style="background: #6c757d; margin-top: 15px;">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

<script>
    window.resident = {
        name: <?php echo json_encode($resident['first_name'] . ' ' . $resident['last_name']); ?>,
        firstName: <?php echo json_encode($resident['first_name']); ?>,
        lastName: <?php echo json_encode($resident['last_name']); ?>,
        email: <?php echo json_encode($resident['email']); ?>,
        phone: <?php echo json_encode($resident['phone'] ?: 'Not provided'); ?>,
        address: <?php echo json_encode($resident['address'] ?: 'Not specified'); ?>,
        isVerified: <?php echo $resident['is_verified'] == 1 ? 'true' : 'false'; ?>
    };
</script>

<script src="resident_documents.js"></script>
<script src="resident_equipment.js"></script>
<script src="resident_complaint.js"></script>


<script>
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Initializing dashboard...');

        // ---- Sidebar submenu toggles ----
        const docsParent = document.getElementById('documentsParent');
        const docsSubmenu = document.getElementById('documentsSubmenu');
        if (docsParent && docsSubmenu) {
            docsParent.addEventListener('click', function(e) {
                e.stopPropagation();
                this.classList.toggle('open');
                docsSubmenu.classList.toggle('open');
            });
        }

        const equipParent = document.getElementById('equipmentParent');
        const equipSubmenu = document.getElementById('equipmentSubmenu');
        if (equipParent && equipSubmenu) {
            equipParent.addEventListener('click', function(e) {
                e.stopPropagation();
                this.classList.toggle('open');
                equipSubmenu.classList.toggle('open');
            });
        }

        const compParent = document.getElementById('complaintsParent');
        const compSubmenu = document.getElementById('complaintsSubmenu');
        if (compParent && compSubmenu) {
            compParent.addEventListener('click', function(e) {
                e.stopPropagation();
                this.classList.toggle('open');
                compSubmenu.classList.toggle('open');
            });
        }

        // ---- Sidebar submenu items ----
        document.querySelectorAll('#documentsSubmenu li').forEach(function(item) {
            item.addEventListener('click', function(e) {
                e.stopPropagation();
                const subview = this.dataset.subview;
                document.querySelectorAll('.nav-item').forEach(function(nav) { nav.classList.remove('active'); });
                document.getElementById('documentsParent').classList.add('active');
                document.querySelectorAll('[data-subview]').forEach(function(sub) { sub.classList.remove('active-sub'); });
                this.classList.add('active-sub');
                document.getElementById('equipmentParent').classList.remove('open');
                document.getElementById('equipmentSubmenu').classList.remove('open');
                document.getElementById('complaintsParent').classList.remove('open');
                document.getElementById('complaintsSubmenu').classList.remove('open');
                if (subview === 'request') { if (typeof loadDocuments === 'function') loadDocuments(); }
                else if (subview === 'history') { if (typeof loadHistory === 'function') loadHistory(); }
                if (window.innerWidth <= 768) {
                    document.getElementById('sidebar').classList.remove('open');
                    document.getElementById('sidebarOverlay').classList.add('hide');
                }
            });
        });

        document.querySelectorAll('#equipmentSubmenu li').forEach(function(item) {
            item.addEventListener('click', function(e) {
                e.stopPropagation();
                const subview = this.dataset.subview;
                document.querySelectorAll('.nav-item').forEach(function(nav) { nav.classList.remove('active'); });
                document.getElementById('equipmentParent').classList.add('active');
                document.querySelectorAll('[data-subview]').forEach(function(sub) { sub.classList.remove('active-sub'); });
                this.classList.add('active-sub');
                document.getElementById('documentsParent').classList.remove('open');
                document.getElementById('documentsSubmenu').classList.remove('open');
                document.getElementById('complaintsParent').classList.remove('open');
                document.getElementById('complaintsSubmenu').classList.remove('open');
                if (subview === 'equipment_list') { if (typeof loadEquipmentList === 'function') loadEquipmentList(); }
                else if (subview === 'equipment_bookings') { if (typeof loadMyBookings === 'function') loadMyBookings(); }
                if (window.innerWidth <= 768) {
                    document.getElementById('sidebar').classList.remove('open');
                    document.getElementById('sidebarOverlay').classList.add('hide');
                }
            });
        });

        document.querySelectorAll('#complaintsSubmenu li').forEach(function(item) {
            item.addEventListener('click', function(e) {
                e.stopPropagation();
                const subview = this.dataset.subview;
                document.querySelectorAll('.nav-item').forEach(function(nav) { nav.classList.remove('active'); });
                document.getElementById('complaintsParent').classList.add('active');
                document.querySelectorAll('[data-subview]').forEach(function(sub) { sub.classList.remove('active-sub'); });
                this.classList.add('active-sub');
                document.getElementById('documentsParent').classList.remove('open');
                document.getElementById('documentsSubmenu').classList.remove('open');
                document.getElementById('equipmentParent').classList.remove('open');
                document.getElementById('equipmentSubmenu').classList.remove('open');
                if (subview === 'file_complaint') {
                    if (typeof showSubmitComplaintForm === 'function') showSubmitComplaintForm();
                    else if (typeof initComplaintModule === 'function') initComplaintModule();
                } else if (subview === 'my_complaints') {
                    if (typeof showMyComplaints === 'function') showMyComplaints();
                    else if (typeof initComplaintModule === 'function') {
                        initComplaintModule();
                        setTimeout(function() { if (typeof showMyComplaints === 'function') showMyComplaints(); }, 100);
                    }
                }
                if (window.innerWidth <= 768) {
                    document.getElementById('sidebar').classList.remove('open');
                    document.getElementById('sidebarOverlay').classList.add('hide');
                }
            });
        });

        document.querySelectorAll('.nav-item[data-view]').forEach(function(item) {
            item.addEventListener('click', function(e) {
                const view = this.dataset.view;
                document.querySelectorAll('.nav-item').forEach(function(nav) { nav.classList.remove('active'); });
                this.classList.add('active');
                document.getElementById('documentsParent').classList.remove('open');
                document.getElementById('documentsSubmenu').classList.remove('open');
                document.getElementById('equipmentParent').classList.remove('open');
                document.getElementById('equipmentSubmenu').classList.remove('open');
                document.getElementById('complaintsParent').classList.remove('open');
                document.getElementById('complaintsSubmenu').classList.remove('open');
                if (view === 'dashboard') { if (typeof loadDashboard === 'function') loadDashboard(); }
                else if (view === 'profile') { if (typeof renderProfile === 'function') renderProfile(); }
            });
        });

        // ---- Menu toggle ----
        document.getElementById('menuToggle').addEventListener('click', function(e) {
            e.stopPropagation();
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('hide');
        });

        document.getElementById('sidebarOverlay').addEventListener('click', function(e) {
            e.stopPropagation();
            document.getElementById('sidebar').classList.remove('open');
            this.classList.add('hide');
        });

        // ============ POSITION DROPDOWN (for desktop) ============
        function positionDropdown(dropdown, anchorElement) {
            if (window.innerWidth <= 768) {
                // On mobile, use fixed positioning below the header
                dropdown.style.position = 'fixed';
                dropdown.style.top = '70px';
                dropdown.style.left = '10px';
                dropdown.style.right = '10px';
                dropdown.style.bottom = 'auto';
                dropdown.style.width = 'auto';
                return;
            }
            const rect = anchorElement.getBoundingClientRect();
            dropdown.style.position = 'fixed';
            dropdown.style.top = (rect.bottom + 8) + 'px';
            dropdown.style.right = (window.innerWidth - rect.right) + 'px';
            dropdown.style.left = 'auto';
            dropdown.style.bottom = 'auto';
        }

        // ============ DROPDOWN BACKDROP ============
        const dropdownBackdrop = document.getElementById('dropdownBackdrop');
        
        function showDropdownBackdrop() {
            if (window.innerWidth <= 768) {
                dropdownBackdrop.classList.add('show');
            }
        }
        
        function hideDropdownBackdrop() {
            dropdownBackdrop.classList.remove('show');
        }
        
        // Close dropdowns when backdrop is clicked
        dropdownBackdrop.addEventListener('click', function(e) {
            e.stopPropagation();
            closeAllDropdowns();
        });

        function closeAllDropdowns() {
            const profileDropdown = document.getElementById('profileDropdown');
            const notificationsDropdown = document.getElementById('notificationsDropdown');
            if (profileDropdown) profileDropdown.classList.remove('show');
            if (notificationsDropdown) notificationsDropdown.classList.remove('show');
            hideDropdownBackdrop();
        }

        // ============ PROFILE DROPDOWN ============
        document.getElementById('profileArea').addEventListener('click', function(e) {
            e.stopPropagation();
            e.preventDefault();

            const profileDropdown = document.getElementById('profileDropdown');
            const notificationsDropdown = document.getElementById('notificationsDropdown');

            if (notificationsDropdown) notificationsDropdown.classList.remove('show');

            const willShow = !profileDropdown.classList.contains('show');
            profileDropdown.classList.toggle('show');

            if (willShow) {
                positionDropdown(profileDropdown, document.getElementById('profileArea'));
                showDropdownBackdrop();
            } else {
                hideDropdownBackdrop();
            }
        });

        // ============ NOTIFICATION BELL ============
document.getElementById('notificationBell').addEventListener('click', function(e) {
    e.stopPropagation();
    e.preventDefault();

    const dropdown = document.getElementById('notificationsDropdown');
    const profileDropdown = document.getElementById('profileDropdown');

    if (profileDropdown) profileDropdown.classList.remove('show');

    const willShow = !dropdown.classList.contains('show');
    dropdown.classList.toggle('show');

    if (willShow) {
        positionDropdown(dropdown, document.getElementById('notificationBell'));
        if (typeof loadNotifications === 'function') loadNotifications();
        showDropdownBackdrop();
        
        // ADD THIS: Initialize/refresh push toggle when dropdown opens
        if (typeof initPushToggle === 'function') {
            initPushToggle();
        }
    } else {
        hideDropdownBackdrop();
    }
});

        // ============ CLOSE ON OUTSIDE CLICK ============
        document.addEventListener('click', function(e) {
            const profileDropdown = document.getElementById('profileDropdown');
            const notificationsDropdown = document.getElementById('notificationsDropdown');
            const profileArea = document.getElementById('profileArea');
            const notificationBell = document.getElementById('notificationBell');
            const backdrop = document.getElementById('dropdownBackdrop');

            // If click is on backdrop, close all
            if (e.target === backdrop) {
                closeAllDropdowns();
                return;
            }

            if (profileDropdown && profileArea && 
                !profileArea.contains(e.target) && 
                !profileDropdown.contains(e.target)) {
                profileDropdown.classList.remove('show');
            }

            if (notificationsDropdown && notificationBell && 
                !notificationBell.contains(e.target) && 
                !notificationsDropdown.contains(e.target)) {
                notificationsDropdown.classList.remove('show');
            }
            
            // Hide backdrop if no dropdowns are open
            if (!profileDropdown.classList.contains('show') && 
                !notificationsDropdown.classList.contains('show')) {
                hideDropdownBackdrop();
            }
        });

        // Prevent closing when clicking inside dropdowns
        document.querySelectorAll('.profile-dropdown, .notifications-dropdown').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });

        // ============ LOGOUT ============
        document.getElementById('logoutBtn').addEventListener('click', function() {
            showConfirmModal(
                'Are you sure you want to logout?',
                '',
                function() {
                    showLoading('Logging out...');
                    setTimeout(function() {
                        document.getElementById('logoutForm').submit();
                    }, 500);
                }
            );
        });

        // ============ MODAL OUTSIDE CLICK ============
        window.onclick = function(event) {
            if (event.target.classList.contains('modal-popup')) {
                event.target.style.display = 'none';
            }
            if (event.target === document.getElementById('requestModal')) {
                if (typeof closeRequestModal === 'function') closeRequestModal();
            }
        };

        // Prevent backspace navigation
        (function() {
            history.pushState(null, null, location.href);
            window.onpopstate = function() { history.go(1); };
        })();
        document.addEventListener('keydown', function(e) {
            if (e.altKey && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) e.preventDefault();
            if (e.key === 'Backspace') {
                const target = e.target;
                if (target.tagName !== 'INPUT' && target.tagName !== 'TEXTAREA' && !target.isContentEditable) {
                    e.preventDefault();
                }
            }
        });

        // ============ INITIAL LOAD ============
        if (typeof loadDashboardWithCalendar === 'function') loadDashboardWithCalendar();
        else if (typeof loadDashboard === 'function') loadDashboard();

        if (typeof checkForNewNotes === 'function') {
            setInterval(checkForNewNotes, 60000);
            checkForNewNotes();
        }

               setTimeout(function() { loadNotifications(); }, 1000);
        setInterval(function() { loadNotifications(); }, 60000);

       

        setTimeout(window.checkUnclaimedDocuments, 3000);       // once, 3s after load
        setInterval(window.checkUnclaimedDocuments, 300000);    // every 5 minutes

        // Reposition dropdowns on window resize
        window.addEventListener('resize', function() {
            const profileDropdown = document.getElementById('profileDropdown');
            const notificationsDropdown = document.getElementById('notificationsDropdown');
            if (profileDropdown && profileDropdown.classList.contains('show')) {
                positionDropdown(profileDropdown, document.getElementById('profileArea'));
            }
            if (notificationsDropdown && notificationsDropdown.classList.contains('show')) {
                positionDropdown(notificationsDropdown, document.getElementById('notificationBell'));
            }
        });

        // ============ PUSH NOTIFICATIONS ============
        initPushNotifications();

        // Notification action buttons
        const selectBtn = document.getElementById('selectModeBtn');
        if (selectBtn) selectBtn.addEventListener('click', function(e) { e.stopPropagation(); enterSelectionMode(); });

        const cancelBtn = document.getElementById('cancelSelectBtn');
        if (cancelBtn) cancelBtn.addEventListener('click', function(e) { e.stopPropagation(); exitSelectionMode(); });

        const deleteBtn = document.getElementById('deleteSelectedBtn');
        if (deleteBtn) deleteBtn.addEventListener('click', function(e) { e.stopPropagation(); confirmDeleteSelected(); });

        const markAllBtn = document.getElementById('markAllReadBtn');
        if (markAllBtn) markAllBtn.addEventListener('click', function(e) { e.stopPropagation(); markAllAsRead(); });
    });

    // ============ PUSH NOTIFICATION SYSTEM ============
    let lastNotificationCheck = new Date().toISOString();
    let notificationPollingInterval = null;
    let browserNotificationPermission = 'default';
    let shownNotificationIds = new Set();
    let selectedNotificationIds = new Set();
    let isSelectionMode = false;

    function initPushNotifications() {
        console.log('🔔 Initializing push notification system...');
        if ('Notification' in window) {
            browserNotificationPermission = Notification.permission;
            if (browserNotificationPermission === 'default') {
                setTimeout(function() {
                    if (confirm('Would you like to enable push notifications for important updates?\n\nYou will receive notifications for:\n• Document pickup reminders\n• Equipment return reminders\n• Overdue item alerts')) {
                        Notification.requestPermission().then(function(permission) {
                            browserNotificationPermission = permission;
                            if (permission === 'granted') {
                            
                            }
                        });
                    }
                }, 5000);
            }
        }
        startNotificationPolling();
    }

    function startNotificationPolling() {
    // Only refresh the badge + dropdown list
    refreshNotificationUI();
    notificationPollingInterval = setInterval(refreshNotificationUI, 30000);
}

async function refreshNotificationUI() {
    try {
        updateNotificationBadgeFromServer();
        const dropdown = document.getElementById('notificationsDropdown');
        if (dropdown && dropdown.classList.contains('show')) loadNotifications();
    } catch (e) { /* ignore */ }
}

    async function pollForNewNotifications() {
    // Renamed conceptually — only refreshes the UI now
    try {
        updateNotificationBadgeFromServer();
        const dropdown = document.getElementById('notificationsDropdown');
        if (dropdown && dropdown.classList.contains('show')) loadNotifications();
    } catch (error) {
        console.error('Error polling notifications:', error);
    }
}

  

    function handleNotificationNavigation(type) {
        if (!type) return;
        if (type.includes('document') || type === 'payment_completed') {
            const historyNav = document.querySelector('[data-subview="history"]');
            if (historyNav) historyNav.click();
        } else if (type.includes('equipment') || type.includes('booking') || type.includes('return') || type.includes('overdue')) {
            const bookingsNav = document.querySelector('[data-subview="equipment_bookings"]');
            if (bookingsNav) bookingsNav.click();
        }
    }

    async function updateNotificationBadgeFromServer() {
        try {
            const response = await fetch('ajax_handler.php?action=get_unread_count');
            const data = await response.json();
            if (data.success) updateNotificationBadge(data.unread_count);
        } catch (error) { console.error('Error updating notification badge:', error); }
    }

    function updateNotificationBadge(count) {
        const badge = document.getElementById('notificationBadge');
        const headerBadge = document.getElementById('unreadCountBadge');
        if (badge) {
            if (count > 0) {
                badge.style.display = 'block';
                badge.textContent = count > 99 ? '99+' : count;
                badge.classList.add('pulse');
            } else {
                badge.style.display = 'none';
                badge.classList.remove('pulse');
            }
        }
        if (headerBadge) {
            if (count > 0) {
                headerBadge.style.display = 'inline-block';
                headerBadge.textContent = count;
            } else {
                headerBadge.style.display = 'none';
            }
        }
    }

    async function loadNotifications() {
        const list = document.getElementById('notificationsList');
        if (!list) return;
        try {
            const response = await fetch('ajax_handler.php?action=get_all_notifications');
            const data = await response.json();
            if (data.success && data.notifications) {
                renderNotificationsList(data.notifications);
                updateNotificationBadge(data.unread_count);
            }
        } catch (error) {
            console.error('Error loading notifications:', error);
            list.innerHTML = `<div class="empty-notifications"><i class="fas fa-exclamation-circle"></i><p>Error loading notifications</p></div>`;
        }
    }

    function renderNotificationsList(notifications) {
        const list = document.getElementById('notificationsList');
        if (!list) return;
        if (!notifications || notifications.length === 0) {
            list.innerHTML = `<div class="empty-notifications"><i class="fas fa-bell-slash"></i><p>No notifications</p></div>`;
            selectedNotificationIds.clear();
            updateSelectionUI();
            return;
        }
        let html = '';
        notifications.forEach(function(notif) {
            const isRead = notif.is_read ? 'read' : 'unread';
            const timeAgo = formatTimeAgo(notif.created_at);
            let icon = 'fa-bell', iconColor = '#2e7d32', iconBg = '#e8f5e9', typeLabel = '';
            switch(notif.type) {
                case 'document_pickup_today': icon = 'fa-calendar-check'; iconColor = '#ff9800'; iconBg = '#fff3cd'; typeLabel = 'Pickup Today'; break;
                case 'document_ready': icon = 'fa-file-check'; iconColor = '#28a745'; iconBg = '#d4edda'; typeLabel = 'Ready'; break;
                case 'document_pending': icon = 'fa-file-signature'; iconColor = '#ffc107'; iconBg = '#fff3cd'; typeLabel = 'Pending'; break;
                case 'document_approved': icon = 'fa-file-signature'; iconColor = '#28a745'; iconBg = '#d4edda'; typeLabel = 'Approved'; break;
                case 'document_rejected': icon = 'fa-file-excel'; iconColor = '#dc3545'; iconBg = '#f8d7da'; typeLabel = 'Rejected'; break;
                case 'document_completed': icon = 'fa-file-alt'; iconColor = '#17a2b8'; iconBg = '#d1ecf1'; typeLabel = 'Completed'; break;
                case 'return_reminder': icon = 'fa-clock'; iconColor = '#ffc107'; iconBg = '#fff3cd'; typeLabel = 'Return Reminder'; break;
                case 'equipment_overdue': icon = 'fa-exclamation-triangle'; iconColor = '#dc3545'; iconBg = '#f8d7da'; typeLabel = 'Overdue'; break;
                case 'equipment_borrowed': icon = 'fa-hand-holding'; iconColor = '#17a2b8'; iconBg = '#d1ecf1'; typeLabel = 'Borrowed'; break;
                case 'equipment_returned': icon = 'fa-check-double'; iconColor = '#28a745'; iconBg = '#d4edda'; typeLabel = 'Returned'; break;
                case 'booking_approved': icon = 'fa-calendar-check'; iconColor = '#28a745'; iconBg = '#d4edda'; typeLabel = 'Approved'; break;
                case 'booking_rejected': icon = 'fa-calendar-times'; iconColor = '#dc3545'; iconBg = '#f8d7da'; typeLabel = 'Rejected'; break;
                               case 'payment_completed': icon = 'fa-credit-card'; iconColor = '#28a745'; iconBg = '#d4edda'; typeLabel = 'Payment'; break;
                case 'document_unclaimed': icon = 'fa-inbox'; iconColor = '#ff9800'; iconBg = '#fff3cd'; typeLabel = 'Unclaimed'; break;
                case 'document_claimed':   icon = 'fa-check-circle'; iconColor = '#28a745'; iconBg = '#d4edda'; typeLabel = 'Claimed'; break;
            }
            
            const isSelected = selectedNotificationIds.has(String(notif.id));
            html += `
                <div class="notification-item ${isRead} ${isSelected ? 'selected' : ''}" data-id="${notif.id}">
                    <input type="checkbox" class="notification-checkbox ${isSelectionMode ? 'visible' : ''}" data-id="${notif.id}" ${isSelected ? 'checked' : ''}
                        onclick="event.stopPropagation(); toggleNotificationSelection('${notif.id}', this.checked)">
                    <div class="notification-content" onclick="handleNotificationItemClick('${notif.id}', '${notif.type}')">
                        <div style="display: flex; gap: 12px;">
                            <div style="flex-shrink: 0; width: 40px; height: 40px; border-radius: 50%; background: ${iconBg}; display: flex; align-items: center; justify-content: center;">
                                <i class="fas ${icon}" style="color: ${iconColor}; font-size: 18px;"></i>
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <div class="notif-title" style="font-weight: 600; font-size: 13px; color: #333; margin-bottom: 4px;">${escapeHtml(notif.title)}</div>
                                <div class="notif-message" style="font-size: 12px; color: #666; line-height: 1.4; word-break: break-word;">${escapeHtml(notif.message)}</div>
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px;">
                                    <span class="notif-time" style="font-size: 10px; color: #bbb;"><i class="far fa-clock"></i> ${timeAgo}</span>
                                    ${typeLabel ? `<span class="notif-type" style="background: ${iconBg}; color: ${iconColor}; font-size: 9px; padding: 2px 8px; border-radius: 10px; font-weight: 500;">${typeLabel}</span>` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });
        list.innerHTML = html;
        updateSelectionUI();
    }

    function formatTimeAgo(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const seconds = Math.floor((now - date) / 1000);
        if (seconds < 60) return 'Just now';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
        if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago';
        if (seconds < 604800) return Math.floor(seconds / 86400) + 'd ago';
        return date.toLocaleDateString();
    }

    async function handleNotificationItemClick(notificationId, type) {
        if (isSelectionMode) {
            const cb = document.querySelector(`.notification-checkbox[data-id="${notificationId}"]`);
            if (cb) { cb.checked = !cb.checked; toggleNotificationSelection(notificationId, cb.checked); }
            return;
        }
        try {
            const formData = new FormData();
            formData.append('action', 'mark_notification_read');
            formData.append('notification_id', notificationId);
            await fetch('ajax_handler.php', { method: 'POST', body: formData });
            const item = document.querySelector(`.notification-item[data-id="${notificationId}"]`);
            if (item) { item.classList.remove('unread'); item.classList.add('read'); }
            updateNotificationBadgeFromServer();
            handleNotificationNavigation(type);
            document.getElementById('notificationsDropdown').classList.remove('show');
            if (typeof hideDropdownBackdrop === 'function') hideDropdownBackdrop();
        } catch (error) { console.error('Error handling notification click:', error); }
    }

    function enterSelectionMode() {
        isSelectionMode = true;
        selectedNotificationIds.clear();
        const dropdown = document.getElementById('notificationsDropdown');
        if (dropdown) dropdown.classList.add('selection-mode');
        document.getElementById('notifHeaderActions').style.display = 'none';
        document.getElementById('notifSelectionActions').style.display = 'flex';
        document.getElementById('notifSelectionBar').classList.add('show');
        document.querySelectorAll('.notification-checkbox').forEach(function(cb) { cb.classList.add('visible'); cb.checked = false; });
        document.querySelectorAll('.notification-item').forEach(function(item) { item.classList.remove('selected'); });
        updateSelectionUI();
    }

    function exitSelectionMode() {
        isSelectionMode = false;
        selectedNotificationIds.clear();
        const dropdown = document.getElementById('notificationsDropdown');
        if (dropdown) dropdown.classList.remove('selection-mode');
        document.getElementById('notifHeaderActions').style.display = 'flex';
        document.getElementById('notifSelectionActions').style.display = 'none';
        document.getElementById('notifSelectionBar').classList.remove('show');
        document.querySelectorAll('.notification-checkbox').forEach(function(cb) { cb.classList.remove('visible'); cb.checked = false; });
        document.querySelectorAll('.notification-item').forEach(function(item) { item.classList.remove('selected'); });
        updateSelectionUI();
    }

    function toggleNotificationSelection(notificationId, isChecked) {
        const id = String(notificationId);
        const item = document.querySelector(`.notification-item[data-id="${id}"]`);
        if (isChecked) { selectedNotificationIds.add(id); if (item) item.classList.add('selected'); }
        else { selectedNotificationIds.delete(id); if (item) item.classList.remove('selected'); }
        updateSelectionUI();
    }

    function updateSelectionUI() {
        const count = selectedNotificationIds.size;
        const selectionInfo = document.getElementById('selectionInfo');
        if (selectionInfo) selectionInfo.textContent = count + ' selected';
        const deleteBtn = document.getElementById('deleteSelectedBtn');
        if (deleteBtn) deleteBtn.disabled = count === 0;
    }

    let pendingDeleteIds = null;

    function confirmDeleteSelected() {
        if (selectedNotificationIds.size === 0) return;
        pendingDeleteIds = Array.from(selectedNotificationIds);
        const modal = document.getElementById('deleteConfirmModal');
        const message = document.getElementById('deleteConfirmMessage');
        if (message) message.textContent = `Are you sure you want to delete ${pendingDeleteIds.length} selected notification(s)?`;
        const confirmBtn = document.getElementById('deleteConfirmYesBtn');
        const newConfirmBtn = confirmBtn.cloneNode(true);
        confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
        newConfirmBtn.addEventListener('click', async function() { await executeDeleteSelected(); });
        modal.style.display = 'flex';
    }

    function closeDeleteConfirmModal() {
        const modal = document.getElementById('deleteConfirmModal');
        if (modal) modal.style.display = 'none';
        pendingDeleteIds = null;
    }

    async function executeDeleteSelected() {
        if (!pendingDeleteIds || pendingDeleteIds.length === 0) { closeDeleteConfirmModal(); return; }
        try {
            const formData = new FormData();
            formData.append('action', 'delete_selected_notifications');
            formData.append('notification_ids', pendingDeleteIds.join(','));
            const response = await fetch('ajax_handler.php', { method: 'POST', body: formData });
            const data = await response.json();
            if (data.success) {
                closeDeleteConfirmModal();
                selectedNotificationIds.clear();
                exitSelectionMode();
                loadNotifications();
                updateNotificationBadgeFromServer();
            } else { alert(data.message || 'Failed to delete notifications'); closeDeleteConfirmModal(); }
        } catch (error) { console.error('Error deleting notifications:', error); alert('Error deleting notifications'); closeDeleteConfirmModal(); }
    }

    async function markAllAsRead() {
        try {
            const formData = new FormData();
            formData.append('action', 'mark_all_notifications_read');
            const response = await fetch('ajax_handler.php', { method: 'POST', body: formData });
            const data = await response.json();
            if (data.success) { loadNotifications(); updateNotificationBadge(0); }
        } catch (error) { console.error('Error marking all as read:', error); }
    }

    window.loadNotifications = loadNotifications;
    window.updateNotificationBadge = updateNotificationBadge;
    window.handleNotificationItemClick = handleNotificationItemClick;
    window.toggleNotificationSelection = toggleNotificationSelection;
    window.confirmDeleteSelected = confirmDeleteSelected;
    window.closeDeleteConfirmModal = closeDeleteConfirmModal;
    window.escapeHtml = escapeHtml || function(t){ if(!t) return ''; const d=document.createElement('div'); d.textContent=t; return d.innerHTML; };

    window.addEventListener('beforeunload', function() {
        if (notificationPollingInterval) clearInterval(notificationPollingInterval);
    });

 // ============ PUSH NOTIFICATION TOGGLE SYSTEM ============
let pushToggleState = {
    isSupported: false,
    permission: 'default',
    isEnabled: false,
    registration: null,
    pendingAction: null  // 'enable' or 'disable'
};

// Initialize push toggle on page load
function initPushToggle() {
    if (!('Notification' in window)) {
        pushToggleState.isSupported = false;
        updatePushToggleUI();
        return;
    }
    
    pushToggleState.isSupported = true;
    pushToggleState.permission = Notification.permission;
    pushToggleState.isEnabled = Notification.permission === 'granted';
    
    updatePushToggleUI();
    
    // Add click listener to the toggle button (avoid duplicate listeners)
    const toggleBtn = document.getElementById('pushToggleBtn');
    if (toggleBtn && !toggleBtn.dataset.listenerAttached) {
        toggleBtn.addEventListener('click', handlePushToggleClick);
        toggleBtn.dataset.listenerAttached = 'true';
    }
    
    // Bind confirmation dialog buttons (avoid duplicate listeners)
    const yesBtn = document.getElementById('pushConfirmYesBtn');
    const cancelBtn = document.getElementById('pushConfirmCancelBtn');
    
    if (yesBtn && !yesBtn.dataset.listenerAttached) {
        yesBtn.addEventListener('click', handlePushConfirmYes);
        yesBtn.dataset.listenerAttached = 'true';
    }
    if (cancelBtn && !cancelBtn.dataset.listenerAttached) {
        cancelBtn.addEventListener('click', handlePushConfirmCancel);
        cancelBtn.dataset.listenerAttached = 'true';
    }
    
    console.log('🔔 Push toggle initialized:', pushToggleState);
}

// Handle toggle button click — shows in-modal dialog instead of browser confirm
function handlePushToggleClick(e) {
    e.stopPropagation();
    
    if (!pushToggleState.isSupported) {
        showPushStatusBar('error', 'Push notifications are not supported in your browser.');
        return;
    }
    
    if (Notification.permission === 'denied') {
        showPushStatusBar('warning',
            'Push notifications are blocked. Click the lock icon 🔒 in the address bar to allow them.');
        return;
    }
    
    if (Notification.permission === 'granted' && pushToggleState.isEnabled) {
        // Currently enabled — show in-modal disable confirmation
        showPushConfirmDialog({
            action: 'disable',
            icon: 'fa-bell-slash',
            iconClass: 'warning',
            title: 'Disable Push Notifications?',
            message: `
                You will stop receiving real-time alerts about:
                <ul>
                    <li>Document pickup reminders</li>
                    <li>Equipment return reminders</li>
                    <li>Overdue item alerts</li>
                </ul>
                You can re-enable this anytime.
            `,
            confirmText: '<i class="fas fa-bell-slash"></i> Disable',
            confirmClass: 'danger'
        });
    } else {
        // Currently default or off — show in-modal enable confirmation
        showPushConfirmDialog({
            action: 'enable',
            icon: 'fa-bell',
            iconClass: '',
            title: 'Enable Push Notifications?',
            message: `
                You will receive real-time alerts about:
                <ul>
                    <li>Document pickup reminders</li>
                    <li>Equipment return reminders</li>
                    <li>Overdue item alerts</li>
                </ul>
            `,
            confirmText: '<i class="fas fa-bell"></i> Enable',
            confirmClass: ''
        });
    }
}

// Show the in-modal confirmation dialog
function showPushConfirmDialog({ action, icon, iconClass, title, message, confirmText, confirmClass }) {
    const dialog = document.getElementById('pushConfirmDialog');
    const iconEl = document.getElementById('pushConfirmIcon');
    const titleEl = document.getElementById('pushConfirmTitle');
    const messageEl = document.getElementById('pushConfirmMessage');
    const yesBtn = document.getElementById('pushConfirmYesBtn');
    
    if (!dialog) return;
    
    pushToggleState.pendingAction = action;
    
    iconEl.className = 'push-confirm-icon' + (iconClass ? ' ' + iconClass : '');
    iconEl.innerHTML = `<i class="fas ${icon}"></i>`;
    titleEl.textContent = title;
    messageEl.innerHTML = message;
    yesBtn.innerHTML = confirmText;
    yesBtn.className = 'push-confirm-btn confirm' + (confirmClass ? ' ' + confirmClass : '');
    
    dialog.style.display = 'flex';
}

// Hide the in-modal confirmation dialog
function hidePushConfirmDialog() {
    const dialog = document.getElementById('pushConfirmDialog');
    if (dialog) dialog.style.display = 'none';
    pushToggleState.pendingAction = null;
}

// Handle "Yes" in the confirmation dialog
async function handlePushConfirmYes() {
    const action = pushToggleState.pendingAction;
    hidePushConfirmDialog();
    
    if (action === 'disable') {
        await disablePushNotifications();
    } else if (action === 'enable') {
        await enablePushNotifications();
    }
}

// Handle "Cancel" in the confirmation dialog
function handlePushConfirmCancel() {
    hidePushConfirmDialog();
}

// Enable push notifications (request browser permission)
async function enablePushNotifications() {
    try {
        const permission = await Notification.requestPermission();
        pushToggleState.permission = permission;
        
        if (permission === 'granted') {
            pushToggleState.isEnabled = true;
            localStorage.removeItem('pushNotificationsDisabled');
            updatePushToggleUI();
            showPushStatusBar('success', 'Push notifications enabled! You will now receive important updates.');
            
            // Send a welcome notification
        
            registerPushSubscription();
            
        } else if (permission === 'denied') {
            pushToggleState.isEnabled = false;
            updatePushToggleUI();
            showPushStatusBar('error', 'Push notifications were blocked. Click the lock icon 🔒 in the address bar to allow them.');
        } else {
            pushToggleState.isEnabled = false;
            updatePushToggleUI();
        }
    } catch (error) {
        console.error('Error requesting notification permission:', error);
        showPushStatusBar('error', 'Failed to enable push notifications. Please try again.');
    }
}

// Disable push notifications
async function disablePushNotifications() {
    try {
        localStorage.setItem('pushNotificationsDisabled', 'true');
        pushToggleState.isEnabled = false;
        updatePushToggleUI();
        showPushStatusBar('warning', 'Push notifications disabled. You will still see in-app notifications.');
        
        // Remove push subscription from server
        try {
            const formData = new FormData();
            formData.append('action', 'remove_push_subscription');
            await fetch('ajax_handler.php', { method: 'POST', body: formData });
        } catch (e) {
            console.warn('Could not remove push subscription from server:', e);
        }
        
        console.log('🔕 Push notifications disabled');
    } catch (error) {
        console.error('Error disabling push notifications:', error);
    }
}

// Update the toggle UI
function updatePushToggleUI() {
    const toggleBtn = document.getElementById('pushToggleBtn');
    const toggleIcon = document.getElementById('pushToggleIcon');
    const toggleLabel = document.getElementById('pushToggleLabel');
    
    if (!toggleBtn || !toggleIcon) return;
    
    toggleBtn.classList.remove('push-active', 'push-inactive', 'push-denied');
    
    if (!pushToggleState.isSupported) {
        toggleBtn.classList.add('push-inactive');
        toggleIcon.className = 'fas fa-bell-slash';
        if (toggleLabel) toggleLabel.textContent = 'N/A';
        toggleBtn.title = 'Push notifications not supported';
    } else if (Notification.permission === 'denied') {
        toggleBtn.classList.add('push-denied');
        toggleIcon.className = 'fas fa-bell-slash';
        if (toggleLabel) toggleLabel.textContent = 'Blocked';
        toggleBtn.title = 'Push notifications blocked';
    } else if (Notification.permission === 'granted' && pushToggleState.isEnabled) {
        toggleBtn.classList.add('push-active');
        toggleIcon.className = 'fas fa-bell';
        if (toggleLabel) toggleLabel.textContent = 'On';
        toggleBtn.title = 'Push notifications enabled';
    } else {
        toggleBtn.classList.add('push-inactive');
        toggleIcon.className = 'fas fa-bell-slash';
        if (toggleLabel) toggleLabel.textContent = 'Off';
        toggleBtn.title = 'Push notifications disabled';
    }
}

// Show push status bar
function showPushStatusBar(type, message) {
    const statusBar = document.getElementById('pushStatusBar');
    const statusIcon = document.getElementById('pushStatusIcon');
    const statusText = document.getElementById('pushStatusText');
    
    if (!statusBar || !statusText) return;
    
    if (statusIcon) {
        if (type === 'success') statusIcon.className = 'fas fa-check-circle';
        else if (type === 'warning') statusIcon.className = 'fas fa-exclamation-triangle';
        else statusIcon.className = 'fas fa-times-circle';
    }
    
    statusText.textContent = message;
    statusBar.className = 'push-status-bar ' + type;
    statusBar.style.display = 'flex';
    
    clearTimeout(window._pushStatusTimeout);
    window._pushStatusTimeout = setTimeout(() => hidePushStatusBar(), 6000);
}

function hidePushStatusBar() {
    const statusBar = document.getElementById('pushStatusBar');
    if (statusBar) statusBar.style.display = 'none';
    clearTimeout(window._pushStatusTimeout);
}

// Check if user previously disabled push
function checkPushPreference() {
    const wasDisabled = localStorage.getItem('pushNotificationsDisabled');
    if (wasDisabled === 'true' && Notification.permission === 'granted') {
        pushToggleState.isEnabled = false;
        updatePushToggleUI();
    }
}

// Register push subscription (Web Push API)
async function registerPushSubscription() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        console.log('Push API not supported');
        return;
    }
    
    try {
        const registration = await navigator.serviceWorker.ready;
        let subscription = await registration.pushManager.getSubscription();
        
        if (!subscription) {
            const vapidPublicKey = 'YOUR_VAPID_PUBLIC_KEY_HERE';
            if (vapidPublicKey && vapidPublicKey !== 'YOUR_VAPID_PUBLIC_KEY_HERE') {
                subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
                });
            }
        }
        
        if (subscription) {
            const formData = new FormData();
            formData.append('action', 'save_push_subscription');
            formData.append('subscription', JSON.stringify(subscription));
            await fetch('ajax_handler.php', { method: 'POST', body: formData });
            console.log('✅ Push subscription registered');
        }
    } catch (error) {
        console.warn('Could not register push subscription:', error);
    }
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

// Check if user previously disabled push notifications
function checkPushPreference() {
    const wasDisabled = localStorage.getItem('pushNotificationsDisabled');
    if (wasDisabled === 'true' && Notification.permission === 'granted') {
        // User previously disabled - update state
        pushToggleState.isEnabled = false;
        updatePushToggleUI();
    }
}

// ============ INITIALIZE PUSH TOGGLE ============
// Call this when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // ... existing code ...
    
    // Initialize push toggle (after a short delay to ensure DOM is ready)
    setTimeout(() => {
        initPushToggle();
        checkPushPreference();
    }, 1500);
});

// Also call initPushToggle when the notifications dropdown is opened
// Add this to the existing notification bell click handler:
// In the notificationBell click handler, add: initPushToggle();

    console.log('✅ Push notification system initialized!');
</script>
</body>
</html>