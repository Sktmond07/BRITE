<?php
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
// Check for payment status messages
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

// Check URL parameters
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
        /* Notification Bar Styles */
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

        .notification-bar:hover {
            transform: translateY(-2px);
        }

        .notification-bar.success {
            background: linear-gradient(135deg, #11998e, #38ef7d);
        }

        .notification-bar.warning {
            background: linear-gradient(135deg, #f2994a, #f2c94c);
        }

        .notification-bar.info {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
        }

        .notification-icon {
            font-size: 24px;
            margin-right: 10px;
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: bold;
            font-size: 1rem;
            margin-bottom: 4px;
        }

        .notification-message {
            font-size: 0.85rem;
            opacity: 0.95;
        }

        .notification-count {
            background: rgba(255,255,255,0.3);
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 0.85rem;
            font-weight: bold;
        }

        .notification-close {
            background: none;
            border: none;
            color: white;
            font-size: 18px;
            cursor: pointer;
            opacity: 0.8;
            transition: opacity 0.3s;
        }

        .notification-close:hover {
            opacity: 1;
        }

        @keyframes slideDown {
            from {
                transform: translateY(-100%);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @keyframes slideUp {
            from {
                transform: translateY(0);
                opacity: 1;
            }
            to {
                transform: translateY(-100%);
                opacity: 0;
            }
        }

        @keyframes pulse-green {
            0% {
                box-shadow: 0 0 0 0 rgba(56, 239, 125, 0.7);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(56, 239, 125, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(56, 239, 125, 0);
            }
        }

        .notification-pulse {
            animation: pulse-green 1s ease-out;
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
    <div class="nav-item has-submenu" id="documentsParent">
        <i class="fas fa-folder-open"></i><span>Documents</span>
        <i class="fas fa-chevron-down chevron-icon"></i>
    </div>
    <ul class="submenu" id="documentsSubmenu">
        <li data-subview="request"><i class="fas fa-file-alt"></i> Request Document</li>
        <li data-subview="history"><i class="fas fa-history"></i> Request History</li>
    </ul>
    
    <!-- NEW: Equipment & Facilities Submenu -->
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
                <div class="profile-area" id="profileArea">
                    <div class="profile-text">
                        <div class="name"><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></div>
                        <div class="role">Resident</div>
                    </div>
                    <div class="avatar-container">
                        <div class="avatar-fallback"><i class="fas fa-user-circle"></i></div>
                    </div>
                    <div class="profile-dropdown" id="profileDropdown">
                        <div class="dropdown-header">
                            <div class="user-name"><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></div>
                            <div class="user-email"><?php echo htmlspecialchars($resident['email']); ?></div>
                        </div>
                        <div class="dropdown-divider"></div>
                        <div class="dropdown-item" id="settingsBtn"><i class="fas fa-cog"></i><span>Settings</span></div>
                        <div class="dropdown-item logout-item" id="logoutBtn"><i class="fas fa-sign-out-alt"></i><span>Logout</span></div>
                    </div>
                </div>
            </div>

            <div id="alertContainer"></div>
            <div class="dashboard-body" id="dashboardBody">
                <div class="loading">Loading...</div>
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

    <form id="logoutForm" method="POST" action="../logout.php" style="display: none;"></form>

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

    <!-- Animated Modal Popups -->
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
                <!-- GCash Option -->
                <div class="payment-option-card" onclick="processPayment('gcash')" style="border: 2px solid #ddd; border-radius: 16px; padding: 20px; cursor: pointer; transition: all 0.3s; background: white;">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="background: linear-gradient(135deg, #0066B3, #00B4D8); width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-mobile-alt" style="font-size: 28px; color: white;"></i>
                        </div>
                        <div style="flex: 1;">
                            <h3 style="margin: 0 0 5px 0; color: #0066B3;">GCash</h3>
                            <p style="margin: 0; color: #666; font-size: 14px;">Pay using GCash (Instant confirmation)</p>
                        </div>
                        <div>
                            <i class="fas fa-chevron-right" style="color: #0066B3;"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Maya Option -->
                <div class="payment-option-card" onclick="processPayment('maya')" style="border: 2px solid #ddd; border-radius: 16px; padding: 20px; cursor: pointer; transition: all 0.3s; background: white;">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="background: linear-gradient(135deg, #00A86B, #00C853); width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-mobile-alt" style="font-size: 28px; color: white;"></i>
                        </div>
                        <div style="flex: 1;">
                            <h3 style="margin: 0 0 5px 0; color: #00A86B;">Maya</h3>
                            <p style="margin: 0; color: #666; font-size: 14px;">Pay using Maya (Instant confirmation)</p>
                        </div>
                        <div>
                            <i class="fas fa-chevron-right" style="color: #00A86B;"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Pay at Claim Option -->
                <div class="payment-option-card" onclick="processPayAtClaim()" style="border: 2px solid #ddd; border-radius: 16px; padding: 20px; cursor: pointer; transition: all 0.3s; background: white;">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="background: linear-gradient(135deg, #ff9800, #f57c00); width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-building" style="font-size: 28px; color: white;"></i>
                        </div>
                        <div style="flex: 1;">
                            <h3 style="margin: 0 0 5px 0; color: #ff9800;">Pay at Claim</h3>
                            <p style="margin: 0; color: #666; font-size: 14px;">Pay when you claim the document at Barangay Hall</p>
                        </div>
                        <div>
                            <i class="fas fa-chevron-right" style="color: #ff9800;"></i>
                        </div>
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

<!-- Payment Confirmation Modal (Simple) -->
<!-- Payment Confirmation Modal (Simple) -->
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
<!-- Online Payment Modal (GCash/Maya) -->
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
            
            <!-- QR Code -->
            <div style="background: white; padding: 20px; border-radius: 16px; margin-bottom: 20px;">
                <img id="qrCodeImage" src="" alt="QR Code" style="width: 200px; height: 200px; margin: 0 auto;">
                <p style="margin-top: 10px; color: #666;">
                    <i class="fas fa-mobile-alt"></i> Scan with GCash or Maya
                </p>
            </div>
            
            <!-- Payment Details -->
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
            
            <!-- Upload Proof Form - NO REFERENCE NUMBER FIELD -->
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

<!-- Pay at Claim Confirmation Modal -->
<div id="payAtClaimModal" class="request-modal">
    <div class="request-modal-content" style="max-width: 450px;">
        <div class="request-modal-header" style="background: linear-gradient(135deg, #11998e, #38ef7d);">
            <h3><i class="fas fa-hand-holding-usd"></i> Pay at Claim</h3>
            <button class="close-modal" onclick="closePayAtClaimModal()">&times;</button>
        </div>
        <div class="request-modal-body">
            <div style="text-align: center; margin-bottom: 20px;">
                <i class="fas fa-building" style="font-size: 60px; color: #11998e;"></i>
            </div>
            
            <div class="selected-doc-info" style="background: #e8f5e9; margin-bottom: 20px;">
                <div>
                    <strong>Document:</strong> <span id="claimDocName"></span><br>
                    <strong>Amount to Pay:</strong> <span id="claimAmount" style="color: #11998e; font-size: 1.3rem; font-weight: bold;"></span>
                </div>
            </div>
            
            <div style="background: #fff3cd; padding: 15px; border-radius: 12px; margin-bottom: 20px;">
                <h5><i class="fas fa-info-circle"></i> Important Information</h5>
                <ul style="margin-top: 10px; padding-left: 20px;">
                    <li>Bring <strong>exact amount</strong> to Barangay Hall</li>
                    <li>Bring your <strong>valid ID</strong> for verification</li>
                    <li>Payment must be made within <strong>30 days</strong> of approval</li>
                    <li>Visit during office hours: <strong id="barangayHours"></strong></li>
                    <li>Address: <strong id="barangayAddress"></strong></li>
                </ul>
            </div>
            
            <button onclick="confirmPayAtClaim()" class="submit-btn" style="background: linear-gradient(135deg, #11998e, #38ef7d);">
                <i class="fas fa-check-circle"></i> Confirm Pay at Claim
            </button>
            <button onclick="closePayAtClaimModal()" class="submit-btn" style="background: #6c757d; margin-top: 10px;">
                <i class="fas fa-arrow-left"></i> Go Back
            </button>
        </div>
    </div>
</div>




    <script>
     const resident = {
    name: <?php echo json_encode($resident['first_name'] . ' ' . $resident['last_name']); ?>,
    firstName: <?php echo json_encode($resident['first_name']); ?>,
    lastName: <?php echo json_encode($resident['last_name']); ?>,
    email: <?php echo json_encode($resident['email']); ?>,
    phone: <?php echo json_encode($resident['phone'] ?: 'Not provided'); ?>,
    address: <?php echo json_encode($resident['address'] ?: 'Not specified'); ?>,
    isVerified: <?php echo $resident['is_verified'] == 1 ? 'true' : 'false'; ?>
};

let currentBaseFee = 0;
let currentDocName = '';
let currentDocId = 0;
let currentEditRequestId = null;
let pendingCancelRequestId = null;
let pendingCallback = null;
let currentUnreadNotes = {};
let notificationCheckEnabled = true;
let lastCheckedStats = {
    approved: 0,
    pending: 0,
    rejected: 0
};
let notificationTimeout = null;

// Payment related variables
let currentPaymentRequest = null;
let currentPaymentMethod = null;

// ============ PAYMENT FUNCTIONS ============

// Show payment options for an approved request
async function showPaymentOptions(requestId, docName, totalAmount) {
    console.log('showPaymentOptions called:', {requestId, docName, totalAmount});
    
    currentPaymentRequest = {
        id: requestId,
        docName: docName,
        totalAmount: totalAmount
    };
    
    const docNameSpan = document.getElementById('paymentDocName');
    const amountSpan = document.getElementById('paymentTotalAmount');
    
    if (docNameSpan) docNameSpan.innerHTML = docName;
    if (amountSpan) amountSpan.innerHTML = `₱${totalAmount.toFixed(2)}`;
    
    const modal = document.getElementById('paymentOptionsModal');
    if (modal) {
        modal.style.display = 'flex';
    } else {
        showErrorModal('Payment options modal not found');
    }
}

function closePaymentOptionsModal() {
    const modal = document.getElementById('paymentOptionsModal');
    if (modal) modal.style.display = 'none';
}

// Process online payment (GCash/Maya)
async function processPayment(method) {
    if (!currentPaymentRequest) {
        showErrorModal('No payment request selected');
        return;
    }
    
    currentPaymentMethod = method;
    
    // Show confirmation modal
    const confirmDocName = document.getElementById('confirmDocName');
    const confirmAmount = document.getElementById('confirmAmount');
    const confirmMethod = document.getElementById('confirmMethod');
    
    if (confirmDocName) confirmDocName.innerHTML = currentPaymentRequest.docName;
    if (confirmAmount) confirmAmount.innerHTML = `₱${currentPaymentRequest.totalAmount.toFixed(2)}`;
    if (confirmMethod) confirmMethod.innerHTML = method.toUpperCase();
    
    closePaymentOptionsModal();
    
    const modal = document.getElementById('paymentConfirmModal');
    if (modal) modal.style.display = 'flex';
}

function closePaymentConfirmModal() {
    const modal = document.getElementById('paymentConfirmModal');
    if (modal) modal.style.display = 'none';
}

async function confirmPayment() {
    if (!currentPaymentRequest) {
        showErrorModal('No payment request selected');
        return;
    }
    
    closePaymentConfirmModal();
    showLoading('Processing payment...');
    
    try {
        const formData = new FormData();
        formData.append('action', 'simulate_payment');
        formData.append('request_id', currentPaymentRequest.id);
        formData.append('payment_method', currentPaymentMethod);
        
        const response = await fetch('ajax_handler.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        console.log('Payment response:', data);
        
        if (data.success) {
            showSuccessModal('Payment successful! Your document will be processed.');
            loadHistory(); // Refresh the history view
        } else {
            showErrorModal(data.message || 'Failed to process payment');
        }
    } catch (error) {
        console.error('Error:', error);
        showErrorModal('Error processing payment: ' + error.message);
    } finally {
        hideLoading();
        currentPaymentRequest = null;
    }
}

// Process Pay at Claim
function processPayAtClaim() {
    if (!currentPaymentRequest) {
        showErrorModal('No payment request selected');
        return;
    }
    
    // Update modal content
    const claimDocName = document.getElementById('claimDocName');
    const claimAmount = document.getElementById('claimAmount');
    
    if (claimDocName) claimDocName.innerHTML = currentPaymentRequest.docName;
    if (claimAmount) claimAmount.innerHTML = `₱${currentPaymentRequest.totalAmount.toFixed(2)}`;
    
    closePaymentOptionsModal();
    
    const modal = document.getElementById('payAtClaimModal');
    if (modal) modal.style.display = 'flex';
}

function closePayAtClaimModal() {
    const modal = document.getElementById('payAtClaimModal');
    if (modal) modal.style.display = 'none';
}

async function confirmPayAtClaim() {
    if (!currentPaymentRequest) {
        showErrorModal('No payment request selected');
        return;
    }
    
    closePayAtClaimModal();
    showLoading('Processing...');
    
    try {
        const formData = new FormData();
        formData.append('action', 'pay_at_claim');
        formData.append('request_id', currentPaymentRequest.id);
        
        const response = await fetch('ajax_handler.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        console.log('Pay at claim response:', data);
        
        if (data.success) {
            showSuccessModal(data.message);
            loadHistory(); // Refresh the history view
        } else {
            showErrorModal(data.message || 'Failed to process pay at claim');
        }
    } catch (error) {
        console.error('Error:', error);
        showErrorModal('Error processing payment: ' + error.message);
    } finally {
        hideLoading();
        currentPaymentRequest = null;
    }
}

function showLoading(message) {
    let loadingDiv = document.getElementById('loadingOverlay');
    if (!loadingDiv) {
        loadingDiv = document.createElement('div');
        loadingDiv.id = 'loadingOverlay';
        loadingDiv.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 10000;
            display: flex;
            justify-content: center;
            align-items: center;
        `;
        loadingDiv.innerHTML = `
            <div style="background: white; padding: 30px; border-radius: 16px; text-align: center;">
                <i class="fas fa-spinner fa-spin" style="font-size: 40px; color: #28a745;"></i>
                <p style="margin-top: 15px;">${message}</p>
            </div>
        `;
        document.body.appendChild(loadingDiv);
    } else {
        loadingDiv.style.display = 'flex';
        const msgPara = loadingDiv.querySelector('p');
        if (msgPara) msgPara.innerHTML = message;
    }
}

function hideLoading() {
    const loadingDiv = document.getElementById('loadingOverlay');
    if (loadingDiv) {
        loadingDiv.style.display = 'none';
    }
}

// ============ HELPER FUNCTIONS ============
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function getDocumentIcon(docName) {
    const icons = {
        'Barangay Clearance': 'fa-id-card', 
        'Certificate of Residency': 'fa-home',
        'Indigency Certificate': 'fa-hand-holding-heart', 
        'Business Clearance': 'fa-store',
        'Certificate of Good Moral': 'fa-star', 
        'First Time Job Seeker': 'fa-briefcase'
    };
    return icons[docName] || 'fa-file-alt';
}

// ============ AGE CALCULATION & DATE VALIDATION ============
function calculateAgeFromBirthdate(birthdate) {
    if (!birthdate) return null;
    const today = new Date();
    const birthDate = new Date(birthdate);
    
    if (isNaN(birthDate.getTime())) return null;
    
    let age = today.getFullYear() - birthDate.getFullYear();
    const monthDiff = today.getMonth() - birthDate.getMonth();
    
    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
        age--;
    }
    return age;
}

function validateDateOfBirth(dateString, fieldName = 'Birthdate') {
    if (!dateString) return { valid: false, message: `${fieldName} is required` };
    
    const selectedDate = new Date(dateString);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    if (selectedDate > today) {
        return { valid: false, message: `${fieldName} cannot be in the future` };
    }
    
    const minDate = new Date();
    minDate.setFullYear(today.getFullYear() - 120);
    if (selectedDate < minDate) {
        return { valid: false, message: `Please enter a valid ${fieldName.toLowerCase()}` };
    }
    
    const age = calculateAgeFromBirthdate(dateString);
    return { valid: true, message: '', age: age };
}

function validateAge(ageValue, fieldName = 'Age') {
    if (!ageValue && ageValue !== 0) return { valid: true };
    
    const age = parseInt(ageValue);
    if (isNaN(age)) return { valid: false, message: `${fieldName} must be a valid number` };
    
    if (age < 0) {
        return { valid: false, message: `${fieldName} cannot be negative` };
    }
    
    if (age > 120) {
        return { valid: false, message: `${fieldName} cannot exceed 120 years` };
    }
    
    return { valid: true };
}

// ============ AUTO-CALCULATE AGE FOR REGULAR FIELDS ============
function setupAutoAgeCalculation() {
    const birthdateFields = document.querySelectorAll('input[type="date"][name*="custom_fields"], input[type="date"][id*="birthdate"]');
    
    birthdateFields.forEach(birthdateInput => {
        birthdateInput.removeEventListener('change', handleBirthdateChange);
        birthdateInput.addEventListener('change', handleBirthdateChange);
    });
}

function handleBirthdateChange(event) {
    const birthdateInput = event.target;
    const birthdate = birthdateInput.value;
    
    let parentContainer = birthdateInput.closest('.form-group');
    if (!parentContainer) parentContainer = birthdateInput.closest('.child-entry');
    if (!parentContainer) return;
    
    let ageField = parentContainer.querySelector('input[type="number"][name*="age"], input[name*="age"]');
    
    if (!ageField) {
        ageField = parentContainer.querySelector('[name*="age"], [id*="age"]');
    }
    
    if (ageField && ageField.tagName === 'INPUT') {
        if (birthdate) {
            const validation = validateDateOfBirth(birthdate);
            if (validation.valid && validation.age !== null) {
                ageField.value = validation.age;
                const ageValidation = validateAge(validation.age);
                if (!ageValidation.valid) {
                    showFieldError(ageField, ageValidation.message);
                } else {
                    clearFieldError(ageField);
                }
            } else if (!validation.valid) {
                showFieldError(birthdateInput, validation.message);
                ageField.value = '';
            } else {
                ageField.value = '';
                clearFieldError(birthdateInput);
            }
        } else {
            ageField.value = '';
        }
    }
    
    if (birthdate) {
        const validation = validateDateOfBirth(birthdate);
        if (!validation.valid) {
            showFieldError(birthdateInput, validation.message);
        } else {
            clearFieldError(birthdateInput);
        }
    }
}

function showFieldError(field, message) {
    clearFieldError(field);
    
    field.style.borderColor = '#dc3545';
    field.style.backgroundColor = '#fff8f8';
    
    const errorSpan = document.createElement('span');
    errorSpan.className = 'field-error-message';
    errorSpan.style.cssText = 'color: #dc3545; font-size: 11px; display: block; margin-top: 5px;';
    errorSpan.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
    field.parentNode.appendChild(errorSpan);
}

function clearFieldError(field) {
    field.style.borderColor = '';
    field.style.backgroundColor = '';
    
    const parent = field.parentNode;
    const existingError = parent.querySelector('.field-error-message');
    if (existingError) {
        existingError.remove();
    }
}

// ============ CHILDREN LIST FIELD HANDLERS ============
function initChildrenFieldWithData(fieldName, existingChildren) {
    let childrenArray = existingChildren || [];
    const container = document.getElementById(`children-list-${fieldName}`);
    const hiddenInput = document.getElementById(`children-data-${fieldName}`);
    
    if (!container) return;
    
    function renderChildrenList() {
        container.innerHTML = '';
        
        childrenArray.forEach((child, index) => {
            const age = child.birthdate ? calculateAgeFromBirthdate(child.birthdate) : (child.age || '');
            
            const childDiv = document.createElement('div');
            childDiv.className = 'child-entry';
            childDiv.setAttribute('data-child-index', index);
            childDiv.style.cssText = 'border:1px solid #ddd; padding:15px; margin:10px 0; border-radius:8px; background:#f9f9f9;';
            childDiv.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <h5 style="margin:0; color:#1a472a;">Child ${index + 1}</h5>
                    <button type="button" class="remove-child-btn" data-index="${index}" style="background:#ff4444; color:white; border:none; border-radius:5px; padding:5px 10px; cursor:pointer;">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>
                <div class="row" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px;">
                    <div>
                        <label style="display:block; font-size:12px; margin-bottom:5px;">Full Name <span style="color:red;">*</span></label>
                        <input type="text" class="child-full-name" data-index="${index}" value="${escapeHtml(child.full_name || '')}" 
                               style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; margin-bottom:5px;">Birthdate <span style="color:red;">*</span></label>
                        <input type="date" class="child-birthdate" data-index="${index}" value="${child.birthdate || ''}" 
                               style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; margin-bottom:5px;">Age</label>
                        <input type="number" class="child-age" data-index="${index}" value="${age !== null ? age : ''}" 
                               style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px; background:#f0f0f0;" readonly>
                    </div>
                </div>
            `;
            container.appendChild(childDiv);
        });
        
        attachChildEventListeners(fieldName);
    }
    
    function attachChildEventListeners(fieldName) {
        document.querySelectorAll(`#children-list-${fieldName} .child-birthdate`).forEach(input => {
            input.removeEventListener('change', handleChildBirthdateChange);
            input.addEventListener('change', handleChildBirthdateChange);
        });
        
        document.querySelectorAll(`#children-list-${fieldName} .remove-child-btn`).forEach(btn => {
            btn.removeEventListener('click', handleRemoveChild);
            btn.addEventListener('click', handleRemoveChild);
        });
        
        document.querySelectorAll(`#children-list-${fieldName} .child-full-name`).forEach(input => {
            input.removeEventListener('input', handleChildNameInput);
            input.addEventListener('input', handleChildNameInput);
        });
    }
    
    function handleChildBirthdateChange(event) {
        const input = event.target;
        const index = parseInt(input.getAttribute('data-index'));
        const birthdate = input.value;
        
        if (birthdate) {
            const validation = validateDateOfBirth(birthdate, 'Child birthdate');
            
            if (!validation.valid) {
                showFieldError(input, validation.message);
                const ageField = document.querySelector(`.child-age[data-index="${index}"]`);
                if (ageField) ageField.value = '';
                childrenArray[index].age = '';
                childrenArray[index].birthdate = birthdate;
            } else {
                clearFieldError(input);
                if (validation.age !== null) {
                    const ageField = document.querySelector(`.child-age[data-index="${index}"]`);
                    if (ageField) ageField.value = validation.age;
                    childrenArray[index].age = validation.age;
                    childrenArray[index].birthdate = birthdate;
                }
            }
        } else {
            clearFieldError(input);
            const ageField = document.querySelector(`.child-age[data-index="${index}"]`);
            if (ageField) ageField.value = '';
            childrenArray[index].age = '';
            childrenArray[index].birthdate = '';
        }
        
        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(childrenArray);
        }
    }
    
    function handleRemoveChild(event) {
        const btn = event.target.closest('.remove-child-btn');
        if (!btn) return;
        const index = parseInt(btn.getAttribute('data-index'));
        childrenArray.splice(index, 1);
        renderChildrenList();
        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(childrenArray);
        }
    }
    
    function handleChildNameInput(event) {
        const index = parseInt(event.target.getAttribute('data-index'));
        childrenArray[index].full_name = event.target.value;
        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(childrenArray);
        }
    }
    
    renderChildrenList();
    
    const addBtn = document.querySelector(`.add-child-btn[data-field="${fieldName}"]`);
    if (addBtn) {
        addBtn.removeEventListener('click', handleAddChild);
        addBtn.addEventListener('click', handleAddChild);
    }
    
    function handleAddChild() {
        childrenArray.push({
            full_name: '',
            age: '',
            birthdate: ''
        });
        renderChildrenList();
        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(childrenArray);
        }
    }
}

function initChildrenFieldDynamic(fieldName) {
    let childrenArray = [];
    const container = document.getElementById(`children-list-${fieldName}`);
    const hiddenInput = document.getElementById(`children-data-${fieldName}`);
    
    if (!container) return;
    
    function renderChildrenList() {
        container.innerHTML = '';
        
        childrenArray.forEach((child, index) => {
            const age = child.birthdate ? calculateAgeFromBirthdate(child.birthdate) : (child.age || '');
            
            const childDiv = document.createElement('div');
            childDiv.className = 'child-entry';
            childDiv.setAttribute('data-child-index', index);
            childDiv.style.cssText = 'border:1px solid #ddd; padding:15px; margin:10px 0; border-radius:8px; background:#f9f9f9;';
            childDiv.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <h5 style="margin:0; color:#1a472a;">Child ${index + 1}</h5>
                    <button type="button" class="remove-child-btn" data-index="${index}" style="background:#ff4444; color:white; border:none; border-radius:5px; padding:5px 10px; cursor:pointer;">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>
                <div class="row" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px;">
                    <div>
                        <label style="display:block; font-size:12px; margin-bottom:5px;">Full Name <span style="color:red;">*</span></label>
                        <input type="text" class="child-full-name" data-index="${index}" value="${escapeHtml(child.full_name || '')}" 
                               style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; margin-bottom:5px;">Birthdate <span style="color:red;">*</span></label>
                        <input type="date" class="child-birthdate" data-index="${index}" value="${child.birthdate || ''}" 
                               style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; margin-bottom:5px;">Age</label>
                        <input type="number" class="child-age" data-index="${index}" value="${age !== null ? age : ''}" 
                               style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px; background:#f0f0f0;" readonly>
                    </div>
                </div>
            `;
            container.appendChild(childDiv);
        });
        
        attachChildEventListeners();
    }
    
    function attachChildEventListeners() {
        document.querySelectorAll(`#children-list-${fieldName} .child-birthdate`).forEach(input => {
            input.removeEventListener('change', handleChildBirthdateChange);
            input.addEventListener('change', handleChildBirthdateChange);
        });
        
        document.querySelectorAll(`#children-list-${fieldName} .remove-child-btn`).forEach(btn => {
            btn.removeEventListener('click', handleRemoveChild);
            btn.addEventListener('click', handleRemoveChild);
        });
        
        document.querySelectorAll(`#children-list-${fieldName} .child-full-name`).forEach(input => {
            input.removeEventListener('input', handleChildNameInput);
            input.addEventListener('input', handleChildNameInput);
        });
    }
    
    function handleChildBirthdateChange(event) {
        const input = event.target;
        const index = parseInt(input.getAttribute('data-index'));
        const birthdate = input.value;
        
        if (birthdate) {
            const validation = validateDateOfBirth(birthdate, 'Child birthdate');
            
            if (!validation.valid) {
                showFieldError(input, validation.message);
                const ageField = document.querySelector(`.child-age[data-index="${index}"]`);
                if (ageField) ageField.value = '';
                childrenArray[index].age = '';
                childrenArray[index].birthdate = birthdate;
            } else {
                clearFieldError(input);
                if (validation.age !== null) {
                    const ageField = document.querySelector(`.child-age[data-index="${index}"]`);
                    if (ageField) ageField.value = validation.age;
                    childrenArray[index].age = validation.age;
                    childrenArray[index].birthdate = birthdate;
                }
            }
        } else {
            clearFieldError(input);
            const ageField = document.querySelector(`.child-age[data-index="${index}"]`);
            if (ageField) ageField.value = '';
            childrenArray[index].age = '';
            childrenArray[index].birthdate = '';
        }
        
        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(childrenArray);
        }
    }
    
    function handleRemoveChild(event) {
        const btn = event.target.closest('.remove-child-btn');
        if (!btn) return;
        const index = parseInt(btn.getAttribute('data-index'));
        childrenArray.splice(index, 1);
        renderChildrenList();
        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(childrenArray);
        }
    }
    
    function handleChildNameInput(event) {
        const index = parseInt(event.target.getAttribute('data-index'));
        childrenArray[index].full_name = event.target.value;
        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(childrenArray);
        }
    }
    
    renderChildrenList();
    
    const addBtn = document.querySelector(`.add-child-btn[data-field="${fieldName}"]`);
    if (addBtn) {
        addBtn.removeEventListener('click', handleAddChild);
        addBtn.addEventListener('click', handleAddChild);
    }
    
    function handleAddChild() {
        childrenArray.push({
            full_name: '',
            age: '',
            birthdate: ''
        });
        renderChildrenList();
        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(childrenArray);
        }
    }
}

// ============ NOTIFICATION FUNCTIONS ============
async function checkForNewApprovals() {
    try {
        const response = await fetch('ajax_handler.php?action=get_stats');
        const data = await response.json();
        
        if (data.success) {
            const currentStats = data.stats;
            
            if (currentStats.approved > lastCheckedStats.approved && lastCheckedStats.approved !== 0) {
                const newApprovals = currentStats.approved - lastCheckedStats.approved;
                showApprovalNotification(newApprovals);
            }
            
            lastCheckedStats = currentStats;
        }
    } catch (error) {
        console.error('Error checking for new approvals:', error);
    }
}

function showApprovalNotification(count) {
    const existingBar = document.getElementById('approvalNotificationBar');
    if (existingBar) {
        existingBar.remove();
    }
    
    if (notificationTimeout) {
        clearTimeout(notificationTimeout);
    }
    
    const notificationBar = document.createElement('div');
    notificationBar.id = 'approvalNotificationBar';
    notificationBar.className = 'notification-bar success notification-pulse';
    notificationBar.innerHTML = `
        <div style="display: flex; align-items: center;">
            <i class="fas fa-check-circle notification-icon"></i>
            <div class="notification-content">
                <div class="notification-title">🎉 New Request Approved!</div>
                <div class="notification-message">${count} of your document request(s) have been approved. Click to view.</div>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <span class="notification-count">+${count}</span>
            <button class="notification-close" onclick="closeNotificationBar(event)">×</button>
        </div>
    `;
    
    const dashboardBody = document.getElementById('dashboardBody');
    const firstChild = dashboardBody.firstChild;
    dashboardBody.insertBefore(notificationBar, firstChild);
    
    notificationTimeout = setTimeout(() => {
        const bar = document.getElementById('approvalNotificationBar');
        if (bar) {
            bar.style.animation = 'slideUp 0.3s ease';
            setTimeout(() => {
                if (bar && bar.parentNode) bar.remove();
            }, 300);
        }
    }, 10000);
    
    notificationBar.addEventListener('click', (e) => {
        if (!e.target.classList.contains('notification-close')) {
            document.querySelector('[data-subview="history"]').click();
            setTimeout(() => {
                filterRequests('approved');
                const approvedCards = document.querySelectorAll('.request-card[data-status="approved"]');
                if (approvedCards.length > 0) {
                    approvedCards[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    approvedCards[0].style.boxShadow = '0 0 0 3px #28a745, 0 4px 20px rgba(0,0,0,0.15)';
                    setTimeout(() => {
                        approvedCards[0].style.boxShadow = '';
                    }, 3000);
                }
            }, 100);
        }
    });
}

function closeNotificationBar(event) {
    event.stopPropagation();
    const bar = document.getElementById('approvalNotificationBar');
    if (bar) {
        bar.style.animation = 'slideUp 0.3s ease';
        setTimeout(() => {
            if (bar && bar.parentNode) bar.remove();
        }, 300);
    }
    if (notificationTimeout) {
        clearTimeout(notificationTimeout);
    }
}

async function checkForNewNotes() {
    if (!notificationCheckEnabled) return;
    
    try {
        const response = await fetch('ajax_handler.php?action=check_new_notes');
        const data = await response.json();
        
        if (data.success && data.unread_count > 0) {
            const unreadRequests = data.unread_requests;
            let hasNewUnread = false;
            
            unreadRequests.forEach(req => {
                if (!currentUnreadNotes[req.id]) {
                    hasNewUnread = true;
                }
                currentUnreadNotes[req.id] = true;
            });
            
            const activeSub = document.querySelector('[data-subview].active-sub');
            if (activeSub && activeSub.dataset.subview === 'history') {
                if (hasNewUnread) {
                    loadHistory();
                }
            } else if (hasNewUnread) {
                updateUnreadNotesIndicator(data.unread_count, unreadRequests);
            }
        }
    } catch (error) {
        console.error('Error checking for new notes:', error);
    }
}

function updateUnreadNotesIndicator(count, unreadRequests) {
    let notificationBadge = document.getElementById('unreadNotesBadge');
    window.unreadRequestsData = unreadRequests;
    
    if (!notificationBadge) {
        notificationBadge = document.createElement('div');
        notificationBadge.id = 'unreadNotesBadge';
        notificationBadge.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #ff9800;
            color: white;
            border-radius: 30px;
            padding: 10px 15px;
            font-size: 12px;
            font-weight: bold;
            z-index: 1000;
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            gap: 8px;
            animation: slideInRight 0.3s ease;
        `;
        notificationBadge.innerHTML = `<i class="fas fa-comment-dots"></i> ${count} new admin message(s)`;
        notificationBadge.onclick = () => {
            window.pendingUnreadRequests = unreadRequests;
            notificationBadge.style.display = 'none';
            document.querySelector('[data-subview="history"]').click();
        };
        document.body.appendChild(notificationBadge);
    } else {
        notificationBadge.innerHTML = `<i class="fas fa-comment-dots"></i> ${count} new admin message(s)`;
        notificationBadge.style.display = 'flex';
        notificationBadge.onclick = () => {
            window.pendingUnreadRequests = unreadRequests;
            notificationBadge.style.display = 'none';
            document.querySelector('[data-subview="history"]').click();
        };
    }
    
    setTimeout(() => {
        if (notificationBadge) {
            notificationBadge.style.opacity = '0';
            setTimeout(() => {
                if (notificationBadge && notificationBadge.style.display !== 'none') {
                    notificationBadge.style.display = 'none';
                }
            }, 300);
        }
    }, 10000);
}

async function autoMarkAsRead(requestId) {
    if (!currentUnreadNotes[requestId]) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'mark_notes_read');
        formData.append('request_id', requestId);
        
        const response = await fetch('ajax_handler.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            delete currentUnreadNotes[requestId];
            
            const card = document.querySelector(`.request-card[data-id="${requestId}"]`);
            if (card) {
                card.classList.remove('has-new-notes');
                const notesIndicator = card.querySelector('.admin-notes-indicator');
                if (notesIndicator) {
                    notesIndicator.remove();
                }
            }
            
            const notesStatus = document.querySelector(`.notes-read-status[data-id="${requestId}"]`);
            if (notesStatus) {
                notesStatus.className = 'notes-read-status read';
                notesStatus.innerHTML = '<i class="fas fa-check-circle"></i> Notes read';
            }
        }
    } catch (error) {
        console.error('Error auto-marking notes as read:', error);
    }
}

function highlightRequestCard(requestId) {
    const requestCard = document.querySelector(`.request-card[data-id="${requestId}"]`);
    if (!requestCard) return false;
    
    document.querySelectorAll('.request-card').forEach(card => {
        card.style.transition = 'all 0.3s ease';
        card.style.boxShadow = '';
    });
    
    requestCard.style.boxShadow = '0 0 0 3px #ff9800, 0 4px 20px rgba(0,0,0,0.15)';
    requestCard.style.transition = 'all 0.3s ease';
    requestCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
    
    let flashCount = 0;
    const flashInterval = setInterval(() => {
        if (flashCount >= 6) {
            clearInterval(flashInterval);
            requestCard.style.boxShadow = '';
            requestCard.style.backgroundColor = '';
            return;
        }
        requestCard.style.backgroundColor = flashCount % 2 === 0 ? '#fff8e1' : '';
        flashCount++;
    }, 300);
    
    return true;
}

// ============ MODAL FUNCTIONS ============
function showSuccessModal(message) {
    const modal = document.getElementById('successModal');
    const messageEl = document.getElementById('successMessage');
    
    messageEl.innerHTML = message;
    modal.classList.add('success-animation');
    modal.style.display = 'flex';
    
    setTimeout(() => {
        modal.classList.remove('success-animation');
    }, 500);
}

function closeSuccessModal() {
    const modal = document.getElementById('successModal');
    modal.style.animation = 'fadeOutBackdrop 0.3s ease';
    setTimeout(() => {
        modal.style.display = 'none';
        modal.style.animation = '';
    }, 300);
}

function showErrorModal(message) {
    const modal = document.getElementById('errorModal');
    const messageEl = document.getElementById('errorMessage');
    
    messageEl.innerHTML = message;
    modal.classList.add('error-animation');
    modal.style.display = 'flex';
    
    setTimeout(() => {
        modal.classList.remove('error-animation');
    }, 500);
}

function closeErrorModal() {
    const modal = document.getElementById('errorModal');
    modal.style.animation = 'fadeOutBackdrop 0.3s ease';
    setTimeout(() => {
        modal.style.display = 'none';
        modal.style.animation = '';
    }, 300);
}

function showConfirmModal(message, warning, onConfirm) {
    const modal = document.getElementById('confirmModal');
    document.getElementById('confirmMessage').innerHTML = message;
    
    if (warning) {
        document.getElementById('confirmWarning').innerHTML = warning;
        document.getElementById('confirmWarning').style.display = 'block';
    } else {
        document.getElementById('confirmWarning').style.display = 'none';
    }
    
    pendingCallback = onConfirm;
    modal.classList.add('warning-animation');
    modal.style.display = 'flex';
    
    setTimeout(() => {
        modal.classList.remove('warning-animation');
    }, 500);
}

function closeConfirmModal() {
    const modal = document.getElementById('confirmModal');
    modal.style.animation = 'fadeOutBackdrop 0.3s ease';
    setTimeout(() => {
        modal.style.display = 'none';
        modal.style.animation = '';
        pendingCallback = null;
        pendingCancelRequestId = null;
    }, 300);
}

function showInfoModal(message) {
    const modal = document.getElementById('infoModal');
    document.getElementById('infoMessage').innerHTML = message;
    modal.classList.add('info-animation');
    modal.style.display = 'flex';
    
    setTimeout(() => {
        modal.classList.remove('info-animation');
    }, 400);
}

function closeInfoModal() {
    const modal = document.getElementById('infoModal');
    modal.style.animation = 'fadeOutBackdrop 0.3s ease';
    setTimeout(() => {
        modal.style.display = 'none';
        modal.style.animation = '';
    }, 300);
}

function showAlert(message, type = 'success') {
    if (type === 'success') {
        showSuccessModal(message);
    } else if (type === 'error') {
        showErrorModal(message);
    } else if (type === 'info') {
        showInfoModal(message);
    }
}

document.getElementById('confirmYesBtn').addEventListener('click', function() {
    if (pendingCallback) {
        pendingCallback();
    }
    closeConfirmModal();
});

window.onclick = function(event) {
    if (event.target.classList.contains('modal-popup')) {
        event.target.style.display = 'none';
    }
    if (event.target === document.getElementById('requestModal')) {
        closeRequestModal();
    }
};

// ============ DASHBOARD FUNCTIONS ============
async function loadDashboard() {
    notificationCheckEnabled = true;
    try {
        const response = await fetch('ajax_handler.php?action=get_stats');
        const data = await response.json();
        if (data.success) {
            if (data.stats.approved > lastCheckedStats.approved && lastCheckedStats.approved !== 0) {
                const newApprovals = data.stats.approved - lastCheckedStats.approved;
                renderDashboard(data.stats);
                showApprovalNotification(newApprovals);
            } else {
                renderDashboard(data.stats);
            }
            lastCheckedStats = data.stats;
        }
    } catch (error) {
        console.error('Error loading dashboard:', error);
    }
}

function renderDashboard(stats) {
    const totalRequests = stats.total || 1;
    const approvalRate = Math.round((stats.approved / totalRequests) * 100);
    
    const html = `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-chart-line"></i> Welcome, ${escapeHtml(resident.name)}!</div>
            <div class="section-sub">Your document request summary</div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #11998e, #38ef7d);">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3>${stats.approved}</h3>
                        <p>Approved Requests</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #f2994a, #f2c94c);">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3>${stats.pending}</h3>
                        <p>Pending Requests</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #eb3349, #f45c43);">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3>${stats.rejected}</h3>
                        <p>Rejected Requests</p>
                    </div>
                </div>
            </div>
            
            <div style="background: #f8f9fa; border-radius: 15px; padding: 20px; margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <span style="font-weight: 600; color: #1a472a;">
                        <i class="fas fa-chart-line"></i> Request Success Rate
                    </span>
                    <span style="color: #28a745; font-weight: bold;">${approvalRate}%</span>
                </div>
                <div style="background: #e0e0e0; border-radius: 10px; height: 12px; overflow: hidden;">
                    <div style="width: ${approvalRate}%; height: 100%; background: linear-gradient(90deg, #28a745, #20c997); border-radius: 10px; transition: width 1s ease;">
                    </div>
                </div>
                <div style="display: flex; justify-content: space-between; margin-top: 8px;">
                    <small style="color: #666;">${stats.approved} approved</small>
                    <small style="color: #666;">${stats.total} total</small>
                </div>
            </div>
            
            <div class="info-card">
                <h3><i class="fas fa-info-circle"></i> Quick Tips</h3>
                <ul>
                    <li><i class="fas fa-check-circle"></i> Provide accurate information in all fields</li>
                    <li><i class="fas fa-clock"></i> Processing takes 2-3 business days</li>
                    <li><i class="fas fa-id-card"></i> Upload correct ID based on fee type</li>
                    <li><i class="fas fa-graduation-cap"></i> Students & Seniors get FREE processing!</li>
                </ul>
            </div>
        </div>
    `;
    document.getElementById('dashboardBody').innerHTML = html;
}

async function loadDocuments() {
    notificationCheckEnabled = true;
    try {
        const response = await fetch('ajax_handler.php?action=get_documents');
        const data = await response.json();
        if (data.success) {
            renderDocuments(data.documents);
        }
    } catch (error) {
        showAlert('Error loading documents', 'error');
    }
}

async function loadDocumentFields(docId, docName, baseFee) {
    try {
        const response = await fetch(`ajax_handler.php?action=get_document_fields&document_id=${docId}`);
        const data = await response.json();
        if (data.success) {
            showRequestModal(docId, docName, baseFee, data.fields);
        }
    } catch (error) {
        showAlert('Error loading form fields', 'error');
    }
}

async function loadHistory() {
    notificationCheckEnabled = false;
    
    try {
        const response = await fetch('ajax_handler.php?action=get_requests');
        const data = await response.json();
        if (data.success) {
            renderHistory(data.requests);
        }
    } catch (error) {
        showAlert('Error loading history', 'error');
    }
}

function updateFeeDisplay() {
    const feeType = document.querySelector('input[name="fee_type"]:checked').value;
    const quantity = parseInt(document.getElementById('quantity').value) || 1;
    
    let totalFee = 0;
    let displayText = '';
    
    if (feeType === 'regular') {
        totalFee = currentBaseFee * quantity;
        displayText = `₱${totalFee.toFixed(2)}`;
    } else {
        totalFee = 0;
        displayText = '<span style="color:#ff9800; font-weight:bold;">FREE</span>';
    }
    
    document.getElementById('selectedDocFeeDisplay').innerHTML = displayText;
    document.getElementById('selectedDocFee').value = totalFee;
    document.getElementById('regularPrice').innerHTML = `₱${currentBaseFee.toFixed(2)}`;
}

function renderDocuments(documents) {
    let cardsHtml = '';
    documents.forEach(doc => {
        cardsHtml += `
            <div class="doc-card" onclick="selectDocument(${doc.id}, '${escapeHtml(doc.name)}', ${doc.fee})">
                <div class="doc-card-icon"><i class="fas ${getDocumentIcon(doc.name)}"></i></div>
                <div class="doc-card-content">
                    <div class="doc-card-title">${escapeHtml(doc.name)}</div>
                    <div class="doc-card-description">${escapeHtml(doc.description)}</div>
                    <div class="doc-card-fee">
                        <span class="fee-amount">₱${doc.fee.toFixed(2)}</span>
                        <span class="select-badge">Select <i class="fas fa-arrow-right"></i></span>
                    </div>
                </div>
            </div>
        `;
    });
    
    const html = `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-file-alt"></i> Select Document Type</div>
            <div class="section-sub">Choose the document you want to request</div>
            <div class="info-card" style="margin-bottom:25px;">
                <h3><i class="fas fa-tag"></i> Fee & ID Requirements</h3>
                <ul><li><i class="fas fa-user"></i> <strong>Regular:</strong> Standard fee + Valid Government ID</li>
                <li><i class="fas fa-graduation-cap"></i> <strong>Student:</strong> FREE + Valid Student ID required</li>
                <li><i class="fas fa-user-plus"></i> <strong>Senior:</strong> FREE + Valid Senior ID required</li></ul>
            </div>
            <div class="documents-grid">${cardsHtml}</div>
        </div>
    `;
    document.getElementById('dashboardBody').innerHTML = html;
}

function renderHistory(requests) {
    if (requests.length === 0) {
        document.getElementById('dashboardBody').innerHTML = `
            <div class="content-card">
                <div class="section-title"><i class="fas fa-history"></i> Request History</div>
                <div class="empty-state"><i class="fas fa-inbox"></i><p>No document requests yet.</p></div>
            </div>`;
        return;
    }

    window.allRequests = requests;
    
    const documentTypes = [...new Set(requests.map(r => r.document_type))];
    
    let documentTypeOptions = '<option value="">All Document Types</option>';
    documentTypes.forEach(type => {
        documentTypeOptions += `<option value="${escapeHtml(type)}">${escapeHtml(type)}</option>`;
    });
    
    const hasPendingUnread = window.pendingUnreadRequests && window.pendingUnreadRequests.length > 0;
    
    let filteredRequests;
    if (hasPendingUnread) {
        const unreadIds = window.pendingUnreadRequests.map(r => r.id);
        filteredRequests = requests.filter(request => unreadIds.includes(request.id));
        const pendingRequests = requests.filter(request => request.status === 'pending' && !unreadIds.includes(request.id));
        filteredRequests = [...filteredRequests, ...pendingRequests];
    } else {
        filteredRequests = requests.filter(request => request.status === 'pending');
    }
    
    let requestsHtml = '';
    filteredRequests.forEach(request => {
        requestsHtml += generateRequestCardHtml(request);
    });
    
    const noRequestsMessage = filteredRequests.length === 0 ? 
        '<div class="empty-state"><i class="fas fa-inbox"></i><p>No requests found.</p></div>' : '';
    
    document.getElementById('dashboardBody').innerHTML = `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-history"></i> Request History</div>
            <div class="section-sub">View and manage your document requests</div>
            
            <div style="background: #f8f9fa; padding: 15px; border-radius: 12px; margin-bottom: 20px;">
                <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
                    <div style="flex: 2; min-width: 200px;">
                        <label style="display: block; font-size: 12px; margin-bottom: 5px; color: #666;">
                            <i class="fas fa-search"></i> Search Requests
                        </label>
                        <input type="text" id="searchRequestsInput" placeholder="Search by document type, requestor name, or status..." 
                               style="width: 100%; padding: 10px 15px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px;">
                    </div>
                    <div style="flex: 1; min-width: 180px;">
                        <label style="display: block; font-size: 12px; margin-bottom: 5px; color: #666;">
                            <i class="fas fa-filter"></i> Filter by Status
                        </label>
                        <select id="statusFilterSelect" style="width: 100%; padding: 10px 15px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px;">
                            <option value="pending" ${!hasPendingUnread ? 'selected' : ''}>Pending</option>
                            <option value="approved">Approved</option>
                            <option value="completed">Completed</option>
                            <option value="rejected">Rejected</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="all">All Requests</option>
                        </select>
                    </div>
                    <div style="flex: 1; min-width: 180px;">
                        <label style="display: block; font-size: 12px; margin-bottom: 5px; color: #666;">
                            <i class="fas fa-file-alt"></i> Filter by Document Type
                        </label>
                        <select id="docTypeFilterSelect" style="width: 100%; padding: 10px 15px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px;">
                            ${documentTypeOptions}
                        </select>
                    </div>
                    <div>
                        <button id="clearFiltersBtn" style="background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer;">
                            <i class="fas fa-eraser"></i> Clear Filters
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="filter-buttons" style="margin-bottom: 20px;">
                <button class="filter-btn ${!hasPendingUnread ? 'active' : ''}" onclick="filterRequests('pending')">Pending</button>
                <button class="filter-btn" onclick="filterRequests('approved')">Approved</button>
                <button class="filter-btn" onclick="filterRequests('completed')">Completed</button>
                <button class="filter-btn" onclick="filterRequests('rejected')">Rejected</button>
                <button class="filter-btn" onclick="filterRequests('cancelled')">Cancelled</button>
                <button class="filter-btn" onclick="filterRequests('all')">All Requests</button>
            </div>
            
            <div id="requestsContainer">
                ${noRequestsMessage}
                ${requestsHtml}
            </div>
        </div>
    `;
    
    const searchInput = document.getElementById('searchRequestsInput');
    const statusFilter = document.getElementById('statusFilterSelect');
    const docTypeFilter = document.getElementById('docTypeFilterSelect');
    const clearBtn = document.getElementById('clearFiltersBtn');
    
    if (searchInput) {
        searchInput.removeEventListener('input', applyFiltersAndSearch);
        searchInput.addEventListener('input', applyFiltersAndSearch);
    }
    
    if (statusFilter) {
        statusFilter.removeEventListener('change', function() {});
        statusFilter.addEventListener('change', function() {
            applyFiltersAndSearch();
            const selectedStatus = this.value;
            document.querySelectorAll('.filter-btn').forEach(btn => {
                if (btn.textContent.toLowerCase().includes(selectedStatus) || 
                    (selectedStatus === 'all' && btn.textContent === 'All Requests')) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        });
    }
    
    if (docTypeFilter) {
        docTypeFilter.removeEventListener('change', applyFiltersAndSearch);
        docTypeFilter.addEventListener('change', applyFiltersAndSearch);
    }
    
    if (clearBtn) {
        clearBtn.removeEventListener('click', function() {});
        clearBtn.addEventListener('click', function() {
            if (searchInput) searchInput.value = '';
            if (statusFilter) statusFilter.value = 'pending';
            if (docTypeFilter) docTypeFilter.value = '';
            applyFiltersAndSearch();
            document.querySelectorAll('.filter-btn').forEach(btn => {
                if (btn.textContent === 'Pending') {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        });
    }
    
    if (hasPendingUnread && window.pendingUnreadRequests) {
        setTimeout(() => {
            window.pendingUnreadRequests.forEach((unreadReq, index) => {
                setTimeout(() => {
                    highlightRequestCard(unreadReq.id);
                }, index * 300);
            });
            setTimeout(() => {
                window.pendingUnreadRequests = null;
            }, 3000);
        }, 300);
    }
}

function applyFiltersAndSearch() {
    if (!window.allRequests) return;
    
    const searchTerm = document.getElementById('searchRequestsInput')?.value.toLowerCase() || '';
    const statusFilter = document.getElementById('statusFilterSelect')?.value || 'pending';
    const docTypeFilter = document.getElementById('docTypeFilterSelect')?.value || '';
    
    let filteredRequests = [...window.allRequests];
    
    if (statusFilter !== 'all') {
        filteredRequests = filteredRequests.filter(r => r.status === statusFilter);
    }
    
    if (docTypeFilter) {
        filteredRequests = filteredRequests.filter(r => r.document_type === docTypeFilter);
    }
    
    if (searchTerm) {
        filteredRequests = filteredRequests.filter(r => {
            let requestorName = resident.name;
            if (r.custom_data && r.custom_data.full_name) {
                requestorName = r.custom_data.full_name;
            } else if (r.custom_data && r.custom_data.fullname) {
                requestorName = r.custom_data.fullname;
            } else if (r.custom_data && r.custom_data['full name']) {
                requestorName = r.custom_data['full name'];
            }
            
            return r.document_type.toLowerCase().includes(searchTerm) ||
                   requestorName.toLowerCase().includes(searchTerm) ||
                   r.status.toLowerCase().includes(searchTerm);
        });
    }
    
    const container = document.getElementById('requestsContainer');
    if (!container) return;
    
    if (filteredRequests.length === 0) {
        container.innerHTML = `<div class="empty-state"><i class="fas fa-search"></i><p>No requests match your search criteria.</p></div>`;
    } else {
        let requestsHtml = '';
        filteredRequests.forEach(request => {
            requestsHtml += generateRequestCardHtml(request);
        });
        container.innerHTML = requestsHtml;
    }
}

function filterRequests(status) {
    const statusFilter = document.getElementById('statusFilterSelect');
    if (statusFilter) {
        statusFilter.value = status;
    }
    
    applyFiltersAndSearch();
    
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.classList.remove('active');
        if ((status === 'all' && btn.textContent === 'All Requests') ||
            (status !== 'all' && btn.textContent.toLowerCase().includes(status))) {
            btn.classList.add('active');
        }
    });
}

function generateRequestCardHtml(request) {
    let statusClass = '', statusIcon = '';
    switch(request.status) {
        case 'pending': statusClass = 'status-pending'; statusIcon = '<i class="fas fa-clock"></i> '; break;
        case 'approved': statusClass = 'status-approved'; statusIcon = '<i class="fas fa-check-circle"></i> '; break;
        case 'rejected': statusClass = 'status-rejected'; statusIcon = '<i class="fas fa-times-circle"></i> '; break;
        case 'completed': statusClass = 'status-completed'; statusIcon = '<i class="fas fa-check-double"></i> '; break;
        case 'cancelled': statusClass = 'status-cancelled'; statusIcon = '<i class="fas fa-ban"></i> '; break;
    }
    
    const feeDisplay = request.fee == 0 ? '<span style="color:#ff9800; font-weight:bold;">FREE</span>' : `₱${request.fee.toFixed(2)}`;
    const totalAmount = (request.fee || 0) * (request.quantity || 1);
    
   // Determine payment status display
let paymentStatusHtml = '';
let paymentActionHtml = '';
const isFree = (request.fee_type === 'student' || request.fee_type === 'senior' || request.fee == 0);

if (isFree) {
    paymentStatusHtml = '<span class="status-badge" style="background: #e8f5e9; color: #2e7d32;"><i class="fas fa-gift"></i> FREE Document</span>';
} else if (request.status === 'approved') {
    // Check payment status from the request object
    let paymentStatus = request.payment_status || 'not_created';
    
    // Also check payment_method for pay_at_claim
    if (request.payment_method === 'pay_at_claim') {
        paymentStatus = 'pay_at_claim';
    }
    
    if (paymentStatus === 'paid') {
        paymentStatusHtml = '<span class="status-badge" style="background: #d4edda; color: #155724;"><i class="fas fa-check-circle"></i> Payment Completed</span>';
    } else if (paymentStatus === 'pay_at_claim') {
        paymentStatusHtml = '<span class="status-badge" style="background: #fff3cd; color: #856404;"><i class="fas fa-hand-holding-usd"></i> Payment Method: Pay at Claim</span>';
    } else {
        // Payment not yet made - show pay button
        paymentStatusHtml = '<span class="status-badge status-pending"><i class="fas fa-credit-card"></i> Payment Required</span>';
        paymentActionHtml = `<button class="pay-now-btn" onclick="event.stopPropagation(); showPaymentOptions(${request.id}, '${escapeHtml(request.document_type)}', ${totalAmount})">
                                <i class="fas fa-credit-card"></i> Pay Now
                            </button>`;
    }
} else if (request.status === 'completed') {
    paymentStatusHtml = '<span class="status-badge" style="background: #d1ecf1; color: #0c5460;"><i class="fas fa-check-double"></i> Document Ready for Claiming</span>';
}
    
    let requestorName = resident.name;
    if (request.custom_data && request.custom_data.full_name) {
        requestorName = request.custom_data.full_name;
    } else if (request.custom_data && request.custom_data.fullname) {
        requestorName = request.custom_data.fullname;
    } else if (request.custom_data && request.custom_data['full name']) {
        requestorName = request.custom_data['full name'];
    }
    
    const canEdit = request.status === 'pending';
    const cancelButton = canEdit ? `<button class="cancel-request-btn" data-id="${request.id}" onclick="showCancelConfirm(${request.id}, event)"><i class="fas fa-trash"></i> Cancel Request</button>` : '';
    const editButton = canEdit ? `<button class="edit-request-btn" data-id="${request.id}" onclick="editRequest(${request.id}, event)"><i class="fas fa-edit"></i> Edit Request</button>` : '';
    
    const hasUnreadNotes = currentUnreadNotes[request.id] === true;
    const notesIndicator = (request.admin_notes && request.admin_notes !== '' && hasUnreadNotes) ? 
        '<span class="admin-notes-indicator"><i class="fas fa-comment"></i> New response!</span>' : '';
    
    const notesStatus = (request.admin_notes && request.admin_notes !== '') ?
        `<div class="notes-read-status ${hasUnreadNotes ? 'unread' : 'read'}" data-id="${request.id}">
            <i class="fas ${hasUnreadNotes ? 'fa-envelope' : 'fa-check-circle'}"></i>
            ${hasUnreadNotes ? 'New response available' : 'Notes read'}
         </div>` : '';
    
    let customDataHtml = '';
    if (request.custom_data && Object.keys(request.custom_data).length > 0) {
        customDataHtml = `
        <div class="details-section">
            <h4><i class="fas fa-clipboard-list"></i> Additional Information</h4>
            <div class="details-grid">
                ${Object.entries(request.custom_data).map(([key, value]) => {
                    if (key === 'full_name' || key === 'fullname' || key === 'full name') {
                        return '';
                    }
                    if (key === 'children') {
                        let childrenHtml = '<div class="detail-item-full"><label><i class="fas fa-child"></i> Children Information:</label><div class="children-list-details">';
                        
                        try {
                            let childrenArray = [];
                            
                            if (typeof value === 'string') {
                                let jsonString = value;
                                if (jsonString.startsWith('"') && jsonString.endsWith('"')) {
                                    jsonString = jsonString.slice(1, -1);
                                }
                                jsonString = jsonString.replace(/\\"/g, '"');
                                childrenArray = JSON.parse(jsonString);
                            } else if (Array.isArray(value)) {
                                childrenArray = value;
                            }
                            
                            if (Array.isArray(childrenArray) && childrenArray.length > 0) {
                                childrenArray.forEach((child, idx) => {
                                    childrenHtml += `
                                        <div class="child-detail-card">
                                            <div class="child-number">Child ${idx + 1}</div>
                                            <div class="child-name"><strong>Name:</strong> ${escapeHtml(child.full_name || 'N/A')}</div>
                                            ${child.age ? `<div class="child-age"><strong>Age:</strong> ${escapeHtml(child.age)}</div>` : ''}
                                            ${child.birthdate ? `<div class="child-birthdate"><strong>Birthdate:</strong> ${escapeHtml(child.birthdate)}</div>` : ''}
                                        </div>
                                    `;
                                });
                            } else {
                                childrenHtml += '<div class="no-data">No children information available</div>';
                            }
                        } catch(e) {
                            console.error('Error parsing children:', e);
                            childrenHtml += '<div class="error-data">Unable to display children information</div>';
                        }
                        childrenHtml += '</div></div>';
                        return childrenHtml;
                    } else {
                        return `
                        <div class="detail-item">
                            <label>${escapeHtml(key.replace(/_/g, ' ').toUpperCase())}:</label>
                            <span>${escapeHtml(String(value))}</span>
                        </div>
                        `;
                    }
                }).join('')}
            </div>
        </div>
        `;
    }
    
    return `
        <div class="request-card ${hasUnreadNotes ? 'has-new-notes' : ''}" data-id="${request.id}" data-status="${request.status}">
            <div class="request-card-header" onclick="toggleRequestDetails(${request.id})">
                <div class="request-card-info">
                    <div class="request-type-badge">
                        <i class="fas ${getDocumentIcon(request.document_type)}"></i>
                        <span class="request-type-name">${escapeHtml(request.document_type)}</span>
                    </div>
                    <div class="status-badge ${statusClass}">${statusIcon} ${request.status}</div>
                    ${paymentStatusHtml}
                    ${notesIndicator}
                </div>
                <div class="request-card-meta">
                    <div class="requestor-name">
                        <i class="fas fa-user"></i> ${escapeHtml(requestorName)}
                    </div>
                    <div class="request-date-simple">
                        <i class="fas fa-calendar-alt"></i> ${new Date(request.request_date).toLocaleDateString()}
                    </div>
                    <div class="request-fee-simple">
                        <i class="fas fa-tag"></i> ${feeDisplay}
                    </div>
                    <i class="fas fa-chevron-down expand-icon" id="expandIcon-${request.id}"></i>
                </div>
            </div>
            <div class="request-card-details" id="details-${request.id}" style="display: none;">
                <div class="details-content">
                    <div class="details-section">
                        <h4><i class="fas fa-info-circle"></i> Request Information</h4>
                        <div class="details-grid">
                            <div class="detail-item">
                                <label>Document Type:</label>
                                <span>${escapeHtml(request.document_type)}</span>
                            </div>
                            <div class="detail-item">
                                <label>Request Date:</label>
                                <span>${new Date(request.request_date).toLocaleString()}</span>
                            </div>
                            <div class="detail-item">
                                <label>Status:</label>
                                <span class="status-badge ${statusClass}">${statusIcon} ${request.status}</span>
                            </div>
                            <div class="detail-item">
                                <label>Quantity:</label>
                                <span>${request.quantity} copy/copies</span>
                            </div>
                            <div class="detail-item">
                                <label>Fee:</label>
                                <span>${feeDisplay}</span>
                            </div>
                            ${request.fee_type ? `<div class="detail-item">
                                <label>Fee Type:</label>
                                <span>${escapeHtml(request.fee_type).charAt(0).toUpperCase() + escapeHtml(request.fee_type).slice(1)}</span>
                            </div>` : ''}
                            ${!isFree && request.fee > 0 ? `<div class="detail-item">
                                <label>Total Amount:</label>
                                <span style="font-weight: bold; color: #ff9800;">₱${totalAmount.toFixed(2)}</span>
                            </div>` : ''}
                        </div>
                    </div>
                    
                    ${customDataHtml}
                    
                    ${request.purpose && request.purpose !== 'Document Request' ? `
                    <div class="details-section">
                        <h4><i class="fas fa-bullhorn"></i> Purpose</h4>
                        <p>${escapeHtml(request.purpose)}</p>
                    </div>
                    ` : ''}
                    
                    ${request.notes ? `
                    <div class="details-section">
                        <h4><i class="fas fa-comment"></i> Additional Notes</h4>
                        <p>${escapeHtml(request.notes)}</p>
                    </div>
                    ` : ''}
                    
                    ${request.admin_notes ? `
                    <div class="details-section">
                        <h4><i class="fas fa-user-shield"></i> Admin Response</h4>
                        <div style="background: #e3f2fd; padding: 12px; border-radius: 8px; border-left: 4px solid #2196f3;">
                            <p style="margin: 0; color: #1565c0;">${escapeHtml(request.admin_notes)}</p>
                            ${notesStatus}
                        </div>
                    </div>
                    ` : ''}
                    
                    ${request.processed_date ? `
                    <div class="details-section">
                        <h4><i class="fas fa-check-circle"></i> Processed Date</h4>
                        <p>${new Date(request.processed_date).toLocaleString()}</p>
                    </div>
                    ` : ''}
                    
                    <div class="details-actions">
                        ${editButton}
                        ${cancelButton}
                        ${paymentActionHtml}
                    </div>
                </div>
            </div>
        </div>
    `;
}

function toggleRequestDetails(requestId) {
    const detailsDiv = document.getElementById(`details-${requestId}`);
    const expandIcon = document.getElementById(`expandIcon-${requestId}`);
    
    if (detailsDiv.style.display === 'none') {
        detailsDiv.style.display = 'block';
        if (expandIcon) expandIcon.style.transform = 'rotate(180deg)';
        autoMarkAsRead(requestId);
    } else {
        detailsDiv.style.display = 'none';
        if (expandIcon) expandIcon.style.transform = 'rotate(0deg)';
    }
}

function showCancelConfirm(requestId, event) {
    event.stopPropagation();
    pendingCancelRequestId = requestId;
    showConfirmModal(
        'Are you sure you want to cancel this request?',
        'This action will permanently delete the request and cannot be undone.',
        function() {
            executeCancelRequest(pendingCancelRequestId);
        }
    );
}

async function executeCancelRequest(requestId) {
    const cancelBtn = document.querySelector(`.cancel-request-btn[data-id="${requestId}"]`);
    let originalText = '';
    if (cancelBtn) {
        originalText = cancelBtn.innerHTML;
        cancelBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cancelling...';
        cancelBtn.disabled = true;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'cancel_request');
        formData.append('request_id', requestId);
        
        const response = await fetch('ajax_handler.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            showSuccessModal('Request cancelled and deleted successfully');
            loadHistory();
        } else {
            showErrorModal(data.message || 'Failed to cancel request');
            if (cancelBtn) {
                cancelBtn.innerHTML = originalText;
                cancelBtn.disabled = false;
            }
        }
    } catch (error) {
        console.error('Error:', error);
        showErrorModal('Error cancelling request. Please try again.');
        if (cancelBtn) {
            cancelBtn.innerHTML = originalText;
            cancelBtn.disabled = false;
        }
    }
    pendingCancelRequestId = null;
}

async function editRequest(requestId, event) {
    event.stopPropagation();
    
    try {
        const response = await fetch(`ajax_handler.php?action=get_request_details&request_id=${requestId}`);
        const data = await response.json();
        
        if (data.success) {
            currentEditRequestId = requestId;
            currentDocId = data.request.document_type_id;
            currentDocName = data.request.document_type;
            currentBaseFee = data.request.fee / (data.request.quantity || 1);
            
            await loadDocumentFieldsForEdit(data.request);
        } else {
            showErrorModal(data.message || 'Failed to load request details');
        }
    } catch (error) {
        console.error('Error:', error);
        showErrorModal('Error loading request for editing');
    }
}

async function loadDocumentFieldsForEdit(request) {
    try {
        const response = await fetch(`ajax_handler.php?action=get_document_fields&document_id=${request.document_type_id}`);
        const data = await response.json();
        if (data.success) {
            showEditRequestModal(request, data.fields);
        }
    } catch (error) {
        showErrorModal('Error loading form fields');
    }
}

function showEditRequestModal(request, fields) {
    document.getElementById('modalTitle').innerHTML = `Edit ${request.document_type} Request`;
    document.getElementById('selectedDocType').value = request.document_type;
    document.getElementById('selectedDocId').value = request.document_type_id;
    document.getElementById('selectedDocName').innerHTML = `<i class="fas ${getDocumentIcon(request.document_type)}"></i> ${request.document_type}`;
    
    const feePerCopy = request.fee / request.quantity;
    document.getElementById('selectedDocFeeDisplay').innerHTML = `₱${feePerCopy.toFixed(2)}`;
    document.getElementById('selectedDocFee').value = request.fee;
    document.getElementById('regularPrice').innerHTML = `₱${feePerCopy.toFixed(2)}`;
    
    document.getElementById('quantity').value = request.quantity;
    
    if (request.fee_type) {
        document.querySelector(`input[name="fee_type"][value="${request.fee_type}"]`).checked = true;
    }
    
    document.getElementById('notesField').value = request.notes || '';
    
    const feeType = request.fee_type || 'regular';
    const idLabel = document.getElementById('idLabel');
    const requirementText = document.getElementById('requirementText');
    const idUploadLabel = document.getElementById('idUploadLabel');
    
    if (feeType === 'regular') {
        idLabel.innerHTML = 'Valid Government ID';
        requirementText.innerHTML = 'Please upload a valid government-issued ID (e.g., Driver\'s License, Passport, Postal ID)';
        idUploadLabel.innerHTML = 'Upload New ID Document (Optional - leave empty to keep current)';
    } else if (feeType === 'student') {
        idLabel.innerHTML = 'Valid Student ID / School ID';
        requirementText.innerHTML = 'Please upload a valid Student ID or School ID for FREE processing';
        idUploadLabel.innerHTML = 'Upload New Student ID (Optional - leave empty to keep current)';
    } else if (feeType === 'senior') {
        idLabel.innerHTML = 'Valid Senior Citizen ID';
        requirementText.innerHTML = 'Please upload a valid Senior Citizen ID for FREE processing';
        idUploadLabel.innerHTML = 'Upload New Senior ID (Optional - leave empty to keep current)';
    }
    
    document.getElementById('id_document').required = false;
    
    renderDynamicFieldsForEdit(fields, request.custom_data);
    
    const submitBtn = document.querySelector('#documentRequestForm button[type="submit"]');
    submitBtn.innerHTML = '<i class="fas fa-save"></i> Update Request';
    
    let editInput = document.querySelector('input[name="edit_request_id"]');
    if (!editInput) {
        editInput = document.createElement('input');
        editInput.type = 'hidden';
        editInput.name = 'edit_request_id';
        document.getElementById('documentRequestForm').appendChild(editInput);
    }
    editInput.value = currentEditRequestId;
    
    let actionInput = document.querySelector('input[name="action"]');
    if (actionInput) {
        actionInput.value = 'update_request';
    }
    
    document.getElementById('requestModal').style.display = 'flex';
}

function renderDynamicFieldsForEdit(fields, customData) {
    let html = '<div style="background:#f0f8ff; padding:15px; border-radius:12px; margin-bottom:20px;">';
    html += '<h4 style="color:#1a472a; margin-bottom:15px;"><i class="fas fa-clipboard-list"></i> Required Information</h4>';
    
    fields.forEach(field => {
        const required = field.field_required ? 'required' : '';
        const requiredStar = field.field_required ? '<span style="color:red;">*</span>' : '';
        const existingValue = (customData && customData[field.field_name]) ? escapeHtml(customData[field.field_name]) : '';
        
        if (field.field_type === 'children_list') {
            html += `<div class="form-group" data-field-type="children">`;
            html += `<label ${requiredStar ? 'class="required"' : ''}>${escapeHtml(field.field_label)} ${requiredStar}</label>`;
            html += `<div id="children-field-${escapeHtml(field.field_name)}" class="children-dynamic-field"></div>`;
            html += `</div>`;
        } else {
            html += `<div class="form-group">`;
            html += `<label ${requiredStar ? 'class="required"' : ''}>${escapeHtml(field.field_label)} ${requiredStar}</label>`;
            
            switch(field.field_type) {
                case 'textarea':
                    html += `<textarea name="custom_fields[${escapeHtml(field.field_name)}]" ${required} rows="3">${existingValue}</textarea>`;
                    break;
                case 'select':
                    html += `<select name="custom_fields[${escapeHtml(field.field_name)}]" ${required}>`;
                    html += `<option value="">Select ${escapeHtml(field.field_label)}</option>`;
                    if (field.field_options) {
                        const options = typeof field.field_options === 'string' ? JSON.parse(field.field_options) : field.field_options;
                        options.forEach(option => {
                            const selected = (option === existingValue) ? 'selected' : '';
                            html += `<option value="${escapeHtml(option)}" ${selected}>${escapeHtml(option)}</option>`;
                        });
                    }
                    html += `</select>`;
                    break;
                case 'date':
                    html += `<input type="date" name="custom_fields[${escapeHtml(field.field_name)}]" ${required} value="${existingValue}">`;
                    break;
                case 'email':
                    html += `<input type="email" name="custom_fields[${escapeHtml(field.field_name)}]" ${required} value="${existingValue}" placeholder="example@email.com">`;
                    break;
                case 'number':
                    html += `<input type="number" name="custom_fields[${escapeHtml(field.field_name)}]" ${required} value="${existingValue}">`;
                    break;
                default:
                    html += `<input type="text" name="custom_fields[${escapeHtml(field.field_name)}]" ${required} value="${existingValue}" placeholder="Enter ${escapeHtml(field.field_label).toLowerCase()}">`;
            }
            
            html += `</div>`;
        }
    });
    
    html += '</div>';
    document.getElementById('dynamicFieldsContainer').innerHTML = html;
    
    setupAutoAgeCalculation();
    
    fields.forEach(field => {
        if (field.field_type === 'children_list') {
            const container = document.getElementById(`children-field-${escapeHtml(field.field_name)}`);
            if (container) {
                let existingChildren = [];
                if (customData && customData.children) {
                    try {
                        existingChildren = typeof customData.children === 'string' ? JSON.parse(customData.children) : customData.children;
                    } catch(e) {
                        existingChildren = [];
                    }
                }
                
                container.innerHTML = `
                    <div class="children-field-container" data-field-name="${escapeHtml(field.field_name)}">
                        <div id="children-list-${escapeHtml(field.field_name)}" class="children-list"></div>
                        <button type="button" class="add-child-btn" data-field="${escapeHtml(field.field_name)}" style="margin-top:10px; padding:8px 15px; background:#4CAF50; color:white; border:none; border-radius:5px; cursor:pointer;">
                            <i class="fas fa-plus"></i> Add Child
                        </button>
                        <input type="hidden" name="custom_fields[${escapeHtml(field.field_name)}]" id="children-data-${escapeHtml(field.field_name)}" value='${JSON.stringify(existingChildren)}'>
                    </div>
                `;
                
                initChildrenFieldWithData(escapeHtml(field.field_name), existingChildren);
            }
        }
    });
}

function renderDynamicFields(fields) {
    let html = '<div style="background:#f0f8ff; padding:15px; border-radius:12px; margin-bottom:20px;">';
    html += '<h4 style="color:#1a472a; margin-bottom:15px;"><i class="fas fa-clipboard-list"></i> Required Information</h4>';
    
    fields.forEach(field => {
        const required = field.field_required ? 'required' : '';
        const requiredStar = field.field_required ? '<span style="color:red;">*</span>' : '';
        
        if (field.field_type === 'children_list') {
            html += `<div class="form-group" data-field-type="children">`;
            html += `<label ${requiredStar ? 'class="required"' : ''}>${escapeHtml(field.field_label)} ${requiredStar}</label>`;
            html += `<div id="children-field-${escapeHtml(field.field_name)}" class="children-dynamic-field"></div>`;
            html += `</div>`;
        } else {
            html += `<div class="form-group">`;
            html += `<label ${requiredStar ? 'class="required"' : ''}>${escapeHtml(field.field_label)} ${requiredStar}</label>`;
            
            switch(field.field_type) {
                case 'textarea':
                    html += `<textarea name="custom_fields[${escapeHtml(field.field_name)}]" ${required} rows="3"></textarea>`;
                    break;
                case 'select':
                    html += `<select name="custom_fields[${escapeHtml(field.field_name)}]" ${required}>`;
                    html += `<option value="">Select ${escapeHtml(field.field_label)}</option>`;
                    if (field.field_options) {
                        const options = typeof field.field_options === 'string' ? JSON.parse(field.field_options) : field.field_options;
                        options.forEach(option => {
                            html += `<option value="${escapeHtml(option)}">${escapeHtml(option)}</option>`;
                        });
                    }
                    html += `</select>`;
                    break;
                case 'date':
                    html += `<input type="date" name="custom_fields[${escapeHtml(field.field_name)}]" ${required}>`;
                    break;
                case 'email':
                    html += `<input type="email" name="custom_fields[${escapeHtml(field.field_name)}]" ${required} placeholder="example@email.com">`;
                    break;
                case 'number':
                    html += `<input type="number" name="custom_fields[${escapeHtml(field.field_name)}]" ${required}>`;
                    break;
                default:
                    html += `<input type="text" name="custom_fields[${escapeHtml(field.field_name)}]" ${required} placeholder="Enter ${escapeHtml(field.field_label).toLowerCase()}">`;
            }
            
            html += `</div>`;
        }
    });
    
    html += '</div>';
    document.getElementById('dynamicFieldsContainer').innerHTML = html;
    
    setupAutoAgeCalculation();
    
    fields.forEach(field => {
        if (field.field_type === 'children_list') {
            const container = document.getElementById(`children-field-${escapeHtml(field.field_name)}`);
            if (container) {
                container.innerHTML = `
                    <div class="children-field-container" data-field-name="${escapeHtml(field.field_name)}">
                        <div id="children-list-${escapeHtml(field.field_name)}" class="children-list"></div>
                        <button type="button" class="add-child-btn" data-field="${escapeHtml(field.field_name)}" style="margin-top:10px; padding:8px 15px; background:#4CAF50; color:white; border:none; border-radius:5px; cursor:pointer;">
                            <i class="fas fa-plus"></i> Add Child
                        </button>
                        <input type="hidden" name="custom_fields[${escapeHtml(field.field_name)}]" id="children-data-${escapeHtml(field.field_name)}" value="[]">
                    </div>
                `;
                initChildrenFieldDynamic(escapeHtml(field.field_name));
            }
        }
    });
}

function selectDocument(docId, docName, baseFee) {
    currentDocId = docId;
    currentDocName = docName;
    currentBaseFee = baseFee;
    
    loadDocumentFields(docId, docName, baseFee);
}

function showRequestModal(docId, docName, baseFee, fields) {
    document.getElementById('modalTitle').innerHTML = `Request ${docName}`;
    document.getElementById('selectedDocType').value = docName;
    document.getElementById('selectedDocId').value = docId;
    document.getElementById('selectedDocName').innerHTML = `<i class="fas ${getDocumentIcon(docName)}"></i> ${docName}`;
    document.getElementById('selectedDocFeeDisplay').innerHTML = `₱${baseFee.toFixed(2)}`;
    document.getElementById('selectedDocFee').value = baseFee;
    document.getElementById('regularPrice').innerHTML = `₱${baseFee.toFixed(2)}`;
    
    document.getElementById('quantity').value = 1;
    
    const feeTypeRadios = document.querySelectorAll('input[name="fee_type"]');
    const quantityInput = document.getElementById('quantity');
    
    feeTypeRadios.forEach(radio => {
        radio.removeEventListener('change', updateFeeDisplay);
        radio.addEventListener('change', updateFeeDisplay);
    });
    
    quantityInput.removeEventListener('input', updateFeeDisplay);
    quantityInput.addEventListener('input', updateFeeDisplay);
    
    feeTypeRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            const idLabel = document.getElementById('idLabel');
            const requirementText = document.getElementById('requirementText');
            
            if (this.value === 'regular') {
                idLabel.innerHTML = 'Valid Government ID';
                requirementText.innerHTML = 'Please upload a valid government-issued ID (e.g., Driver\'s License, Passport, Postal ID)';
            } else if (this.value === 'student') {
                idLabel.innerHTML = 'Valid Student ID / School ID';
                requirementText.innerHTML = 'Please upload a valid Student ID or School ID for FREE processing';
            } else if (this.value === 'senior') {
                idLabel.innerHTML = 'Valid Senior Citizen ID';
                requirementText.innerHTML = 'Please upload a valid Senior Citizen ID for FREE processing';
            }
        });
    });
    
    updateFeeDisplay();
    renderDynamicFields(fields);
    
    document.getElementById('id_document').value = '';
    document.getElementById('requestModal').style.display = 'flex';
}

function closeRequestModal() {
    document.getElementById('requestModal').style.display = 'none';
    document.getElementById('documentRequestForm').reset();
    document.getElementById('dynamicFieldsContainer').innerHTML = '';
    
    currentEditRequestId = null;
    const editInput = document.querySelector('input[name="edit_request_id"]');
    if (editInput) editInput.remove();
    const actionInput = document.querySelector('input[name="action"]');
    if (actionInput) actionInput.value = 'submit_request';
    document.getElementById('id_document').required = true;
    const submitBtn = document.querySelector('#documentRequestForm button[type="submit"]');
    submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
}

function renderProfile() {
    const verificationStatus = resident.isVerified ? 
        '<span class="status-badge status-approved"><i class="fas fa-check-circle"></i> Verified</span>' : 
        '<span class="status-badge status-pending"><i class="fas fa-clock"></i> Pending Verification</span>';
    
    document.getElementById('dashboardBody').innerHTML = `
        <div class="content-card">
            <div class="section-title"><i class="fas fa-user-circle"></i> My Profile</div>
            <div class="info-card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h3>Account Information</h3> ${verificationStatus}
                </div>
                <div style="padding:12px 0; border-bottom:1px solid #eef2ef;">
                    <strong>Full Name:</strong> ${escapeHtml(resident.name)}
                </div>
                <div style="padding:12px 0; border-bottom:1px solid #eef2ef;">
                    <strong>Email:</strong> ${escapeHtml(resident.email)}
                </div>
                <div style="padding:12px 0; border-bottom:1px solid #eef2ef;">
                    <strong>Phone:</strong> ${escapeHtml(resident.phone)}
                </div>
                <div style="padding:12px 0;">
                    <strong>Address:</strong> ${escapeHtml(resident.address)}
                </div>
            </div>
        </div>
    `;
}

document.getElementById('documentRequestForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    let hasError = false;
    const allDateFields = document.querySelectorAll('#documentRequestForm input[type="date"]');
    
    allDateFields.forEach(dateField => {
        if (dateField.value) {
            const validation = validateDateOfBirth(dateField.value, 'Date field');
            if (!validation.valid) {
                showFieldError(dateField, validation.message);
                hasError = true;
            } else {
                clearFieldError(dateField);
            }
        }
    });
    
    if (hasError) {
        showErrorModal('Please fix the date validation errors before submitting');
        return;
    }
    
    const isEdit = document.querySelector('input[name="edit_request_id"]') && document.querySelector('input[name="edit_request_id"]').value;
    const idFile = document.querySelector('[name="id_document"]').files[0];
    
    if (!isEdit && !idFile) {
        showErrorModal('Please upload a valid ID document');
        return;
    }
    
    if (idFile && idFile.size > 5 * 1024 * 1024) {
        showErrorModal('File size must be less than 5MB');
        return;
    }
    
    const formData = new FormData(e.target);
    if (!isEdit) {
        formData.append('action', 'submit_request');
        const quantity = document.querySelector('[name="quantity"]').value;
        const feeType = document.querySelector('input[name="fee_type"]:checked').value;
        formData.append('purpose', `Request for ${currentDocName} - ${quantity} copy/copies (${feeType})`);
    } else {
        formData.append('action', 'update_request');
    }
    
    const submitBtn = e.target.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch('ajax_handler.php', { 
            method: 'POST', 
            body: formData 
        });
        
        const data = await response.json();
        
        if (data.success) {
            showSuccessModal(data.message);
            closeRequestModal();
            currentEditRequestId = null;
            const editInput = document.querySelector('input[name="edit_request_id"]');
            if (editInput) editInput.remove();
            const actionInput = document.querySelector('input[name="action"]');
            if (actionInput) actionInput.value = 'submit_request';
            document.getElementById('id_document').required = true;
            const submitBtnReset = document.querySelector('#documentRequestForm button[type="submit"]');
            submitBtnReset.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
            
            if (document.querySelector('[data-subview="history"]') && 
                document.querySelector('[data-subview="history"]').classList.contains('active-sub')) {
                loadHistory();
            } else {
                loadDashboard();
            }
        } else {
            showErrorModal(data.message);
        }
    } catch (error) {
        console.error('Error:', error);
        showErrorModal('Error processing request. Please try again.');
    } finally {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});

document.querySelectorAll('.nav-item[data-view]').forEach(item => {
    item.addEventListener('click', () => {
        document.querySelectorAll('.nav-item').forEach(nav => nav.classList.remove('active'));
        item.classList.add('active');
        document.getElementById('documentsParent').classList.remove('active', 'open');
        document.getElementById('documentsSubmenu').classList.remove('open');
        
        const view = item.dataset.view;
        if (view === 'dashboard') loadDashboard();
        else if (view === 'profile') renderProfile();
    });
});

document.querySelectorAll('[data-subview]').forEach(item => {
    item.addEventListener('click', () => {
        document.querySelectorAll('.nav-item').forEach(nav => nav.classList.remove('active'));
        document.getElementById('documentsParent').classList.add('active');
        document.querySelectorAll('[data-subview]').forEach(sub => sub.classList.remove('active-sub'));
        item.classList.add('active-sub');
        
        const subview = item.dataset.subview;
        if (subview === 'request') loadDocuments();
        else if (subview === 'history') loadHistory();
        
        if (window.innerWidth <= 768) {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.add('hide');
        }
    });
});

document.getElementById('menuToggle').addEventListener('click', () => {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('hide');
});

document.getElementById('profileArea').addEventListener('click', (e) => {
    e.stopPropagation();
    document.getElementById('profileDropdown').classList.toggle('show');
});

document.addEventListener('click', () => {
    document.getElementById('profileDropdown').classList.remove('show');
});

document.getElementById('logoutBtn').addEventListener('click', () => {
    showConfirmModal(
        'Are you sure you want to logout?',
        '',
        function() {
            document.getElementById('logoutForm').submit();
        }
    );
});

const docsParent = document.getElementById('documentsParent');
const submenu = document.getElementById('documentsSubmenu');

docsParent.addEventListener('click', (e) => {
    e.stopPropagation();
    docsParent.classList.toggle('open');
    submenu.classList.toggle('open');
});

// ============ EQUIPMENT SIDEBAR HANDLERS ============

// Equipment sidebar toggle
const equipmentParent = document.getElementById('equipmentParent');
const equipmentSubmenu = document.getElementById('equipmentSubmenu');

if (equipmentParent && equipmentSubmenu) {
    equipmentParent.addEventListener('click', (e) => {
        e.stopPropagation();
        equipmentParent.classList.toggle('open');
        equipmentSubmenu.classList.toggle('open');
    });
}

// Equipment submenu handlers
document.querySelectorAll('#equipmentSubmenu li').forEach(item => {
    item.addEventListener('click', () => {
        const subview = item.dataset.subview;
        
        // Update active states
        document.querySelectorAll('.nav-item').forEach(nav => nav.classList.remove('active'));
        document.getElementById('documentsParent')?.classList.remove('active');
        document.querySelectorAll('[data-subview]').forEach(sub => sub.classList.remove('active-sub'));
        item.classList.add('active-sub');
        equipmentParent.classList.add('active');
        
        // Load appropriate view
        if (subview === 'equipment_list') {
            loadEquipmentList();
        } else if (subview === 'equipment_bookings') {
            loadMyBookings();
        }
        
        // Close sidebar on mobile
        if (window.innerWidth <= 768) {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.add('hide');
        }
    });
});


(function() { history.pushState(null, null, location.href); window.onpopstate = function() { history.go(1); }; })();
document.addEventListener('keydown', function(e) {
    if (e.altKey && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) e.preventDefault();
    if (e.key === 'Backspace') { const target = e.target; if (target.tagName !== 'INPUT' && target.tagName !== 'TEXTAREA' && !target.isContentEditable) e.preventDefault(); }
});



// Include the equipment.js file
const equipmentScript = document.createElement('script');
equipmentScript.src = 'resident_equipment.js';
document.head.appendChild(equipmentScript);

// Check for new approvals every 30 seconds
setInterval(checkForNewApprovals, 30000);
// Check for new notes every 60 seconds
setInterval(checkForNewNotes, 60000);

checkForNewApprovals();
checkForNewNotes();
loadDashboard();
    </script>
</body>
</html>