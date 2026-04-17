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

// Get the document file path
$sql = "SELECT generated_document_path, document_type FROM document_requests WHERE id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($result && $row = mysqli_fetch_assoc($result)) {
    $filePath = $row['generated_document_path'];
    $documentType = $row['document_type'];
    
    if ($filePath && file_exists(__DIR__ . '/../' . $filePath)) {
        $fullPath = __DIR__ . '/../' . $filePath;
        $fileExt = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        
        if ($fileExt === 'pdf') {
            // For PDF, we'll display it with print dialog
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <title>Print Document - <?php echo htmlspecialchars($documentType); ?></title>
                <style>
                    body { margin: 0; padding: 0; }
                    embed { width: 100%; height: 100vh; }
                    @media print {
                        body { margin: 0; }
                        .no-print { display: none; }
                    }
                </style>
            </head>
            <body>
                <div class="no-print" style="position: fixed; bottom: 20px; right: 20px; z-index: 1000;">
                    <button onclick="window.print();" style="padding: 10px 20px; background: #2c5e3c; color: white; border: none; border-radius: 5px; cursor: pointer;">
                        <i class="fas fa-print"></i> Print Document
                    </button>
                    <button onclick="window.close();" style="padding: 10px 20px; background: #dc3545; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px;">
                        Close
                    </button>
                </div>
                <embed src="view_document.php?request_id=<?php echo $request_id; ?>" type="application/pdf">
                <script>
                    // Auto-open print dialog after a short delay
                    setTimeout(function() {
                        window.print();
                    }, 1000);
                </script>
            </body>
            </html>
            <?php
            exit;
        } elseif ($fileExt === 'html') {
            // For HTML files, display with print styles
            $htmlContent = file_get_contents($fullPath);
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <title>Print Document - <?php echo htmlspecialchars($documentType); ?></title>
                <style>
                    @media print {
                        body { margin: 0; padding: 20px; }
                        .no-print { display: none; }
                    }
                    .no-print {
                        position: fixed;
                        bottom: 20px;
                        right: 20px;
                        z-index: 1000;
                    }
                    .print-btn {
                        padding: 10px 20px;
                        background: #2c5e3c;
                        color: white;
                        border: none;
                        border-radius: 5px;
                        cursor: pointer;
                        margin-right: 10px;
                    }
                    .close-btn {
                        padding: 10px 20px;
                        background: #dc3545;
                        color: white;
                        border: none;
                        border-radius: 5px;
                        cursor: pointer;
                    }
                </style>
            </head>
            <body>
                <div class="no-print">
                    <button class="print-btn" onclick="window.print();"><i class="fas fa-print"></i> Print</button>
                    <button class="close-btn" onclick="window.close();">Close</button>
                </div>
                <div class="document-content">
                    <?php echo $htmlContent; ?>
                </div>
                <script>
                    setTimeout(function() {
                        window.print();
                    }, 500);
                </script>
            </body>
            </html>
            <?php
            exit;
        }
    } else {
        die('Document file not found.');
    }
} else {
    die('Request not found.');
}
?>