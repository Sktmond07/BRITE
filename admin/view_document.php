<?php
session_start();

require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../sign_in.php');
    exit();
}

$request_id = isset($_GET['request_id']) ? intval($_GET['request_id']) : 0;

if ($request_id <= 0) {
    die('Invalid request ID');
}

// Check database connection
if (!$conn) {
    die('Database connection failed: ' . mysqli_connect_error());
}

// Get the document file path - using document_path column which stores just the filename
$sql = "SELECT dr.document_path, dr.document_type, 
               r.first_name, r.last_name, dr.status, dr.admin_notes
        FROM document_requests dr 
        JOIN resident r ON dr.resident_id = r.id 
        WHERE dr.id = ?";
$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die('SQL prepare error: ' . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($result && $row = mysqli_fetch_assoc($result)) {
    // document_path stores just the filename (e.g., "Barangay_Clearance_Raymond_Guevarra_2026-04-09_1775722861.pdf")
    $filename = $row['document_path'];
    $documentType = $row['document_type'];
    $residentName = $row['first_name'] . ' ' . $row['last_name'];
    $status = $row['status'];
    $adminNotes = $row['admin_notes'];
    
    // The generated documents are stored in the 'generated_documents' directory
    $fullPath = __DIR__ . '/../generated_documents/' . $filename;
    
    if ($filename && file_exists($fullPath)) {
        $fileExt = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>View Document - <?php echo htmlspecialchars($documentType); ?> | Barangay System</title>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background: #1a1a2e;
                    height: 100vh;
                    overflow: hidden;
                }
                
                /* Header Bar */
                .document-header {
                    background: linear-gradient(135deg, #1a472a, #2b9b54);
                    color: white;
                    padding: 15px 25px;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    flex-wrap: wrap;
                    gap: 15px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.2);
                }
                
                .document-info h1 {
                    font-size: 1.3rem;
                    margin-bottom: 5px;
                }
                
                .document-info p {
                    font-size: 0.85rem;
                    opacity: 0.9;
                }
                
                .document-info p i {
                    margin-right: 5px;
                }
                
                .action-buttons {
                    display: flex;
                    gap: 12px;
                    flex-wrap: wrap;
                }
                
                .action-btn {
                    padding: 10px 20px;
                    border: none;
                    border-radius: 8px;
                    cursor: pointer;
                    font-size: 0.9rem;
                    font-weight: 600;
                    transition: all 0.2s;
                    display: inline-flex;
                    align-items: center;
                    gap: 8px;
                }
                
                .action-btn.print {
                    background: #28a745;
                    color: white;
                }
                
                .action-btn.print:hover {
                    background: #218838;
                    transform: translateY(-2px);
                }
                
                .action-btn.download {
                    background: #17a2b8;
                    color: white;
                }
                
                .action-btn.download:hover {
                    background: #138496;
                    transform: translateY(-2px);
                }
                
                .action-btn.close {
                    background: #dc3545;
                    color: white;
                }
                
                .action-btn.close:hover {
                    background: #c82333;
                    transform: translateY(-2px);
                }
                
                .action-btn.back {
                    background: #6c757d;
                    color: white;
                }
                
                .action-btn.back:hover {
                    background: #5a6268;
                    transform: translateY(-2px);
                }
                
                /* Document Container */
                .document-container {
                    height: calc(100vh - 80px);
                    background: #f5f5f5;
                    position: relative;
                }
                
                /* PDF Viewer */
                .pdf-viewer {
                    width: 100%;
                    height: 100%;
                    border: none;
                }
                
                /* HTML Viewer */
                .html-viewer {
                    padding: 30px;
                    overflow-y: auto;
                    height: 100%;
                    background: white;
                }
                
                /* Loading Overlay */
                .loading-overlay {
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0,0,0,0.7);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    z-index: 1000;
                    flex-direction: column;
                    gap: 20px;
                }
                
                .loading-spinner {
                    width: 60px;
                    height: 60px;
                    border: 5px solid #f3f3f3;
                    border-top: 5px solid #43e97b;
                    border-radius: 50%;
                    animation: spin 1s linear infinite;
                }
                
                .loading-text {
                    color: white;
                    font-size: 1.1rem;
                }
                
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                
                /* Error Message */
                .error-container {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    height: 100%;
                    text-align: center;
                    padding: 40px;
                }
                
                .error-icon {
                    font-size: 80px;
                    color: #dc3545;
                    margin-bottom: 20px;
                }
                
                .error-title {
                    font-size: 1.5rem;
                    color: #333;
                    margin-bottom: 10px;
                }
                
                .error-message {
                    color: #666;
                    margin-bottom: 30px;
                }
                
                /* Status Badge */
                .status-badge {
                    display: inline-block;
                    padding: 4px 12px;
                    border-radius: 20px;
                    font-size: 0.7rem;
                    font-weight: 600;
                    margin-left: 10px;
                }
                
                .status-badge.approved {
                    background: #d4edda;
                    color: #155724;
                }
                
                .status-badge.completed {
                    background: #cce5ff;
                    color: #004085;
                }
                
                /* Admin Notes */
                .admin-notes {
                    background: #fff3cd;
                    border-left: 4px solid #ffc107;
                    padding: 8px 15px;
                    margin-top: 8px;
                    border-radius: 5px;
                    font-size: 0.8rem;
                }
                
                .admin-notes i {
                    margin-right: 5px;
                    color: #856404;
                }
                
                /* Responsive */
                @media (max-width: 768px) {
                    .document-header {
                        padding: 12px 15px;
                    }
                    
                    .document-info h1 {
                        font-size: 1rem;
                    }
                    
                    .document-info p {
                        font-size: 0.7rem;
                    }
                    
                    .action-btn {
                        padding: 6px 12px;
                        font-size: 0.75rem;
                    }
                    
                    .action-buttons {
                        gap: 8px;
                    }
                }
            </style>
        </head>
        <body>
            <div class="document-header">
                <div class="document-info">
                    <h1>
                        <i class="fas fa-file-alt"></i> 
                        <?php echo htmlspecialchars($documentType); ?>
                        <span class="status-badge <?php echo strtolower($status); ?>">
                            <i class="fas <?php echo $status === 'approved' ? 'fa-check-circle' : 'fa-check-double'; ?>"></i>
                            <?php echo ucfirst($status); ?>
                        </span>
                    </h1>
                    <p>
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($residentName); ?> | 
                        <i class="fas fa-file-pdf"></i> Generated Document | 
                        <i class="fas fa-calendar"></i> <?php echo date('F d, Y'); ?>
                    </p>
                    <?php if ($adminNotes): ?>
                    <div class="admin-notes">
                        <i class="fas fa-sticky-note"></i> <strong>Admin Notes:</strong> <?php echo htmlspecialchars($adminNotes); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="action-buttons">
                    <button class="action-btn print" onclick="printDocument()">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <button class="action-btn download" onclick="downloadDocument()">
                        <i class="fas fa-download"></i> Download
                    </button>
                    <button class="action-btn back" onclick="goBack()">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </button>
                    <button class="action-btn close" onclick="closeWindow()">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            </div>
            
            <div class="document-container">
                <?php if ($fileExt === 'pdf'): ?>
                    <iframe id="pdfFrame" class="pdf-viewer" src="stream_pdf.php?file=<?php echo urlencode($filename); ?>"></iframe>
                <?php elseif ($fileExt === 'html'): ?>
                    <div class="html-viewer" id="htmlContent">
                        <?php echo file_get_contents($fullPath); ?>
                    </div>
                <?php else: ?>
                    <div class="error-container">
                        <div class="error-icon"><i class="fas fa-file-alt"></i></div>
                        <div class="error-title">Unsupported File Type</div>
                        <div class="error-message">The document file type (.<?php echo $fileExt; ?>) cannot be displayed directly.</div>
                        <button class="action-btn download" onclick="downloadDocument()">Download Document</button>
                    </div>
                <?php endif; ?>
            </div>
            
            <div id="loadingOverlay" class="loading-overlay" style="display: none;">
                <div class="loading-spinner"></div>
                <div class="loading-text">Loading document...</div>
            </div>
            
            <script>
                const filename = <?php echo json_encode($filename); ?>;
                const documentType = <?php echo json_encode($documentType); ?>;
                const requestId = <?php echo $request_id; ?>;
                
                function printDocument() {
                    const pdfFrame = document.getElementById('pdfFrame');
                    if (pdfFrame && pdfFrame.contentWindow) {
                        pdfFrame.contentWindow.print();
                    } else {
                        window.print();
                    }
                }
                
                function downloadDocument() {
                    window.location.href = 'download_document.php?request_id=' + requestId;
                }
                
                function goBack() {
                    window.location.href = 'dashboard.php';
                }
                
                function closeWindow() {
                    window.close();
                }
                
                // Show loading while iframe loads
                const pdfFrame = document.getElementById('pdfFrame');
                if (pdfFrame) {
                    pdfFrame.onload = function() {
                        document.getElementById('loadingOverlay').style.display = 'none';
                    };
                    setTimeout(function() {
                        document.getElementById('loadingOverlay').style.display = 'flex';
                    }, 100);
                }
                
                // Keyboard shortcuts
                document.addEventListener('keydown', function(e) {
                    if (e.ctrlKey && e.key === 'p') {
                        e.preventDefault();
                        printDocument();
                    } else if (e.key === 'Escape') {
                        closeWindow();
                    }
                });
            </script>
        </body>
        </html>
        <?php
        exit;
    } else {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Document Not Found</title>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
            <style>
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background: #f0f4f9;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    height: 100vh;
                    margin: 0;
                }
                .error-box {
                    text-align: center;
                    background: white;
                    padding: 40px;
                    border-radius: 24px;
                    box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                    max-width: 500px;
                }
                .error-icon {
                    font-size: 80px;
                    color: #dc3545;
                    margin-bottom: 20px;
                }
                h2 {
                    color: #1a472a;
                    margin-bottom: 10px;
                }
                p {
                    color: #666;
                    margin-bottom: 20px;
                }
                .btn {
                    padding: 10px 25px;
                    background: #43e97b;
                    color: white;
                    border: none;
                    border-radius: 8px;
                    cursor: pointer;
                    text-decoration: none;
                    display: inline-block;
                }
                .btn:hover {
                    background: #2b9b54;
                }
                .file-info {
                    background: #f8f9fa;
                    padding: 10px;
                    border-radius: 8px;
                    margin-top: 15px;
                    font-size: 12px;
                    color: #666;
                    word-break: break-all;
                }
            </style>
        </head>
        <body>
            <div class="error-box">
                <div class="error-icon"><i class="fas fa-file-pdf"></i></div>
                <h2>Document Not Found</h2>
                <p>The document file could not be located. It may have been moved or deleted.</p>
                <div class="file-info">
                    <i class="fas fa-file"></i> Expected file: <?php echo htmlspecialchars($filename); ?>
                </div>
                <a href="dashboard.php" class="btn" style="margin-top: 20px;"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
} else {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Request Not Found</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
        <style>
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: #f0f4f9;
                display: flex;
                align-items: center;
                justify-content: center;
                height: 100vh;
                margin: 0;
            }
            .error-box {
                text-align: center;
                background: white;
                padding: 40px;
                border-radius: 24px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                max-width: 500px;
            }
            .error-icon {
                font-size: 80px;
                color: #dc3545;
                margin-bottom: 20px;
            }
            h2 {
                color: #1a472a;
                margin-bottom: 10px;
            }
            p {
                color: #666;
                margin-bottom: 20px;
            }
            .btn {
                padding: 10px 25px;
                background: #43e97b;
                color: white;
                border: none;
                border-radius: 8px;
                cursor: pointer;
                text-decoration: none;
                display: inline-block;
            }
            .btn:hover {
                background: #2b9b54;
            }
        </style>
    </head>
    <body>
        <div class="error-box">
            <div class="error-icon"><i class="fas fa-question-circle"></i></div>
            <h2>Request Not Found</h2>
            <p>The document request could not be found.</p>
            <a href="dashboard.php" class="btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

mysqli_stmt_close($stmt);
?>