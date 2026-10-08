<?php
// admin/print_document.php
// Combined print + claim handler.
// — Loads the document preview (GET)
// — Marks as claimed when called with POST action=mark_claimed

session_start();

require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../sign_in.php');
    exit();
}

/* =====================================================================
   POST HANDLER — mark as claimed (called via fetch from the same page)
   ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $raw     = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    $reqId = intval($payload['request_id'] ?? ($_POST['request_id'] ?? 0));
    $paid  = isset($payload['paid']) ? (bool)$payload['paid']
                                     : (isset($_POST['paid']) && $_POST['paid'] === '1');

    if ($reqId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
        exit;
    }

    // Load request
    $q = "SELECT id, status, fee, fee_type, payment_status
          FROM document_requests WHERE id = ? LIMIT 1";
    $s = mysqli_prepare($conn, $q);
    mysqli_stmt_bind_param($s, "i", $reqId);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $req = $r ? mysqli_fetch_assoc($r) : null;
    mysqli_stmt_close($s);

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found']);
        exit;
    }

    $currentStatus  = $req['status'];
    $currentPayment = $req['payment_status'];
    $fee            = floatval($req['fee']);
    $feeType        = $req['fee_type'];
    $isFree         = ($feeType === 'student' || $feeType === 'senior' || $fee == 0);

    // Current admin's name for audit trail
    $adminId   = $_SESSION['user_id'];
    $adminName = '';
    $ns = mysqli_prepare($conn, "SELECT full_name FROM admin WHERE id = ?");
    mysqli_stmt_bind_param($ns, "i", $adminId);
    mysqli_stmt_execute($ns);
    $nr = mysqli_stmt_get_result($ns);
    if ($nr && $nRow = mysqli_fetch_assoc($nr)) $adminName = $nRow['full_name'] ?? '';
    mysqli_stmt_close($ns);

    // Final payment status
    if ($isFree) {
        $newPayment = 'not_required';
    } elseif ($paid) {
        $newPayment = 'paid';
    } else {
        $newPayment = ($currentPayment && $currentPayment !== 'not_required')
                      ? $currentPayment
                      : 'pay_at_claim';
    }

    // Update the request to 'claimed' if it was approved/unclaimed
    if (in_array($currentStatus, ['approved', 'unclaimed'], true)) {
        $upd = "UPDATE document_requests
                SET status = 'claimed',
                    claimed_at = NOW(),
                    completed_date = NOW(),
                    completed_at = NOW(),
                    completed_by = ?,
                    completed_by_name = ?,
                    payment_status = ?
                WHERE id = ? AND status IN ('approved','unclaimed')";
        $us = mysqli_prepare($conn, $upd);
        mysqli_stmt_bind_param($us, "issi", $adminId, $adminName, $newPayment, $reqId);

        if (!mysqli_stmt_execute($us)) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to update request: ' . mysqli_stmt_error($us)
            ]);
            mysqli_stmt_close($us);
            exit;
        }
        mysqli_stmt_close($us);

        // If paid, also update document_payments
        if ($paid && !$isFree) {
            $ps = mysqli_prepare($conn,
                "UPDATE document_payments
                 SET payment_status = 'paid', payment_date = NOW()
                 WHERE request_id = ? AND payment_status <> 'paid'");
            if ($ps) {
                mysqli_stmt_bind_param($ps, "i", $reqId);
                mysqli_stmt_execute($ps);
                mysqli_stmt_close($ps);
            }
        }
    }

    echo json_encode([
        'success'         => true,
        'message'         => 'Request marked as claimed.',
        'request_id'      => $reqId,
        'status'          => 'claimed',
        'payment_status'  => $newPayment,
        'claimed_at'      => date('Y-m-d H:i:s'),
        'already_claimed' => ($currentStatus === 'claimed')
    ]);
    exit;
}

/* =====================================================================
   GET HANDLER — load + render the document preview
   ===================================================================== */
$request_id = isset($_GET['request_id']) ? intval($_GET['request_id']) : 0;
if ($request_id <= 0) {
    die('Invalid request ID');
}

$sql = "SELECT
            dr.document_type,
            dr.document_path,
            dr.document_generated,
            dr.qr_code_path,
            dr.status,
            dr.payment_status,
            dr.quantity,
            dr.fee,
            dr.fee_type,
            r.first_name,
            r.last_name,
            r.email
        FROM document_requests dr
        JOIN resident r ON dr.resident_id = r.id
        WHERE dr.id = ?
        LIMIT 1";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    die('SQL prepare failed: ' . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result || !($row = mysqli_fetch_assoc($result))) {
    die('Request not found.');
}

$documentType   = $row['document_type'] ?? 'Document';
$filePath       = $row['document_path'] ?? '';
$residentName   = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
$quantity       = intval($row['quantity'] ?? 1);
$status         = $row['status'] ?? 'pending';
$paymentStatus  = $row['payment_status'] ?? 'not_required';
$fee            = floatval($row['fee'] ?? 0);
$feeType        = $row['fee_type'] ?? 'regular';
$isFree         = ($feeType === 'student' || $feeType === 'senior' || $fee == 0);
$alreadyClaimed = ($status === 'claimed');
$isPaid         = ($paymentStatus === 'paid');

$foundFile = false;
$fullPath  = '';
$fileExt   = '';

if (!empty($filePath)) {
    // PDFs live in /BRITE/generated_documents/
    $candidate = __DIR__ . '/../generated_documents/' . $filePath;
    if (file_exists($candidate)) {
        $foundFile = true;
        $fullPath  = $candidate;
        $fileExt   = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Document — <?php echo htmlspecialchars($documentType); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Segoe UI', Roboto, sans-serif;
            background: #eef2ef;
            min-height: 100vh;
        }

        .top-bar {
            position: sticky; top: 0; z-index: 100;
            background: linear-gradient(135deg, #1b5e20, #2E7D32 55%, #43a047);
            color: #fff; padding: 14px 22px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            flex-wrap: wrap; gap: 12px;
        }
        .top-bar .info { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
        .top-bar .info i { font-size: 1.6rem; }
        .top-bar .info .title { font-weight: 700; font-size: 1rem; }
        .top-bar .info .sub { font-size: 0.78rem; opacity: 0.85; margin-top: 2px; }
        .top-bar .actions { display: flex; gap: 10px; flex-wrap: wrap; }

        .status-chip {
            display: inline-block; padding: 3px 12px; border-radius: 20px;
            font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px; background: rgba(255,255,255,0.2);
            color: #fff; margin-left: 6px;
        }
        .status-chip.paid        { background: #43e97b; color: #0d3b1f; }
        .status-chip.pay-at-claim { background: #ffb74d; color: #4a2600; }
        .status-chip.claimed     { background: #bbdefb; color: #0d47a1; }
        .status-chip.free        { background: #b2dfdb; color: #004d40; }

        .btn {
            border: none; padding: 10px 20px; border-radius: 10px;
            font-weight: 600; font-size: 0.9rem; cursor: pointer;
            display: inline-flex; align-items: center; gap: 8px;
            transition: all 0.2s; text-decoration: none;
        }
        .btn-print   { background: #fff; color: #1b5e20; }
        .btn-print:hover { background: #f1f8e9; transform: translateY(-1px); }
        .btn-print:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-close   { background: rgba(255,255,255,0.18); color: #fff; border: 1px solid rgba(255,255,255,0.3); }
        .btn-close:hover { background: rgba(255,255,255,0.28); }
        .btn-download{ background: rgba(255,255,255,0.18); color: #fff; border: 1px solid rgba(255,255,255,0.3); }
        .btn-download:hover { background: rgba(255,255,255,0.28); }

        .doc-area {
            max-width: 900px; margin: 24px auto; background: #fff;
            border-radius: 14px; box-shadow: 0 8px 28px rgba(0,0,0,0.08);
            overflow: hidden; min-height: 400px;
        }
        .doc-area iframe,
        .doc-area embed { width: 100%; height: 80vh; border: none; display: block; }
        .doc-area .html-content { padding: 30px; font-size: 14px; line-height: 1.6; color: #222; }

        .missing { padding: 60px 30px; text-align: center; color: #666; }
        .missing i { font-size: 64px; color: #cfd8dc; margin-bottom: 16px; }
        .missing h2 { color: #1a472a; margin: 0 0 10px; }
        .missing p { color: #777; max-width: 480px; margin: 0 auto 20px; }
        .missing .hint {
            background: #f1f8e9; border-left: 4px solid #43e97b;
            padding: 12px 18px; border-radius: 8px;
            text-align: left; font-size: 0.85rem;
            color: #2e7d32; margin-top: 20px; display: inline-block;
        }

        @media print {
            .top-bar, .no-print { display: none !important; }
            .doc-area { margin: 0; border-radius: 0; box-shadow: none; max-width: 100%; }
            .doc-area iframe, .doc-area embed { height: auto; min-height: 100vh; }
            body { background: #fff; }
        }
    </style>
</head>
<body>

<div class="top-bar no-print">
    <div class="info">
        <i class="fas fa-file-alt"></i>
        <div>
            <div class="title">
                <?php echo htmlspecialchars($documentType); ?> — Request #<?php echo $request_id; ?>
                <?php
                // Status chip
                if ($alreadyClaimed) {
                    echo '<span class="status-chip claimed">CLAIMED</span>';
                } elseif ($isFree) {
                    echo '<span class="status-chip free">FREE</span>';
                } elseif ($isPaid) {
                    echo '<span class="status-chip paid">PAID</span>';
                } elseif ($paymentStatus === 'pay_at_claim') {
                    echo '<span class="status-chip pay-at-claim">PAY AT CLAIM</span>';
                } else {
                    echo '<span class="status-chip">' . htmlspecialchars(strtoupper($status)) . '</span>';
                }
                ?>
            </div>
            <div class="sub">
                <?php echo htmlspecialchars($residentName); ?>
                · <?php echo $quantity; ?> copy/copies
                · Fee: <?php echo $isFree ? 'FREE' : '₱' . number_format($fee, 2); ?>
                · Payment: <?php echo htmlspecialchars($paymentStatus); ?>
            </div>
        </div>
    </div>
    <div class="actions">
        <?php if ($foundFile): ?>
            <button class="btn btn-print" id="btnConfirmPrint">
                <i class="fas fa-print"></i>
                <?php echo $alreadyClaimed ? 'Print' : 'Confirm &amp; Print'; ?>
            </button>
        <?php endif; ?>
        <?php if ($foundFile && $fileExt === 'pdf'): ?>
            <a class="btn btn-download" href="view_document.php?request_id=<?php echo $request_id; ?>&download=1" download>
                <i class="fas fa-download"></i> Download
            </a>
        <?php endif; ?>
        <button class="btn btn-close" id="btnClose">
            <i class="fas fa-times"></i> Close
        </button>
    </div>
</div>

<div class="doc-area" id="docArea">
<?php if (!$foundFile): ?>
    <div class="missing">
        <i class="fas fa-file-excel"></i>
        <h2>Document Not Available</h2>
        <p>
            The PDF file <code><?php echo htmlspecialchars($filePath); ?></code> was not
            found in <code>generated_documents/</code>.
        </p>
        <div class="hint">
            <strong>Tip:</strong> Confirm the file exists at
            <code>C:\xampp\htdocs\BRITE\generated_documents\<?php echo htmlspecialchars($filePath); ?></code>
        </div>
    </div>
<?php elseif ($fileExt === 'pdf'): ?>
    <iframe src="view_document.php?request_id=<?php echo $request_id; ?>#toolbar=0"></iframe>
<?php elseif ($fileExt === 'html'): ?>
    <div class="html-content">
        <?php echo file_get_contents($fullPath); ?>
    </div>
<?php elseif (in_array($fileExt, ['png','jpg','jpeg','gif','webp'])): ?>
    <div style="text-align:center; padding: 20px;">
        <img src="view_document.php?request_id=<?php echo $request_id; ?>" style="max-width:100%; border-radius:8px;">
    </div>
<?php else: ?>
    <div class="missing">
        <i class="fas fa-file"></i>
        <h2>Preview Not Supported</h2>
        <p>This document type cannot be previewed in the browser. You can download it instead.</p>
    </div>
<?php endif; ?>
</div>

<script>
(function () {
    const btnPrint       = document.getElementById('btnConfirmPrint');
    const btnClose       = document.getElementById('btnClose');

    const REQUEST_ID      = <?php echo (int)$request_id; ?>;
    const ALREADY_CLAIMED = <?php echo $alreadyClaimed ? 'true' : 'false'; ?>;
    const IS_FREE         = <?php echo $isFree ? 'true' : 'false'; ?>;
    const IS_PAID         = <?php echo $isPaid ? 'true' : 'false'; ?>;
    const FEE             = <?php echo json_encode($fee); ?>;
    const RESIDENT_NAME   = <?php echo json_encode($residentName); ?>;
    const DOC_TYPE        = <?php echo json_encode($documentType); ?>;

    function openPrintDialog() {
        try {
            const frame = document.querySelector('.doc-area iframe, .doc-area embed');
            if (frame && frame.contentWindow) {
                try {
                    frame.contentWindow.focus();
                    frame.contentWindow.print();
                    return;
                } catch (e) { /* cross-origin fallback */ }
            }
            window.print();
        } catch (e) {
            window.print();
        }
    }

    // Posts back to THIS SAME FILE with the request id + paid flag
    async function markClaimed(paid) {
        try {
            const res = await fetch('print_document.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ request_id: REQUEST_ID, paid: paid })
            });
            const data = await res.json();
            if (!data.success) {
                await Swal.fire({
                    icon: 'error',
                    title: 'Could not mark as claimed',
                    text: data.message || 'Unknown error'
                });
                return false;
            }
            return true;
        } catch (err) {
            console.error(err);
            await Swal.fire({
                icon: 'error',
                title: 'Network error',
                text: 'Could not reach server. Please try again.'
            });
            return false;
        }
    }

    if (btnPrint) {
        btnPrint.addEventListener('click', async () => {

            /* -------- 1. Already claimed → just print -------- */
            if (ALREADY_CLAIMED) {
                btnPrint.disabled = true;
                btnPrint.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Opening print…';
                setTimeout(() => {
                    openPrintDialog();
                    setTimeout(() => {
                        btnPrint.disabled = false;
                        btnPrint.innerHTML = '<i class="fas fa-print"></i> Print';
                    }, 900);
                }, 250);
                return;
            }

            /* -------- 2. Free document → simple confirm -------- */
            if (IS_FREE) {
                const confirm = await Swal.fire({
                    title: 'Confirm Pickup',
                    html:
                        `<div style="text-align:left;font-size:0.9rem;line-height:1.55;">` +
                        `<div><strong>Resident:</strong> ${RESIDENT_NAME}</div>` +
                        `<div><strong>Document:</strong> ${DOC_TYPE}</div>` +
                        `<div><strong>Fee:</strong> FREE</div>` +
                        `<hr style="margin:10px 0;">` +
                        `<div>Mark this request as <strong>Claimed</strong> and print the document?</div>` +
                        `</div>`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-check"></i> Confirm &amp; Print',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#2E7D32',
                    cancelButtonColor: '#6c757d'
                });
                if (!confirm.isConfirmed) return;

                btnPrint.disabled = true;
                btnPrint.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';

                const ok = await markClaimed(false);
                if (!ok) {
                    btnPrint.disabled = false;
                    btnPrint.innerHTML = '<i class="fas fa-print"></i> Confirm &amp; Print';
                    return;
                }

                Swal.fire({
                    icon: 'success',
                    title: 'Marked as Claimed',
                    text: 'Opening print dialog…',
                    timer: 1200,
                    showConfirmButton: false
                });

                setTimeout(openPrintDialog, 700);
                return;
            }

            /* -------- 3. Paid doc, but payment_status !== 'paid' → ask -------- */
            if (!IS_PAID) {
                const answer = await Swal.fire({
                    title: 'Has the resident paid?',
                    html:
                        `<div style="text-align:left;font-size:0.9rem;line-height:1.55;">` +
                        `<div><strong>Resident:</strong> ${RESIDENT_NAME}</div>` +
                        `<div><strong>Document:</strong> ${DOC_TYPE}</div>` +
                        `<div><strong>Amount due:</strong> ₱${FEE.toFixed(2)}</div>` +
                        `<hr style="margin:10px 0;">` +
                        `<div>Confirm the payment status before marking as claimed and printing.</div>` +
                        `</div>`,
                    icon: 'question',
                    showDenyButton: true,
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-money-bill-wave"></i> Yes, Paid',
                    denyButtonText:   '<i class="fas fa-hand-holding-usd"></i> No, Pay at Claim',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#2E7D32',
                    denyButtonColor:    '#ff9800',
                    cancelButtonColor:  '#6c757d'
                });

                let paid = null;
                if (answer.isConfirmed)   paid = true;
                else if (answer.isDenied) paid = false;
                else                      return; // cancelled

                btnPrint.disabled = true;
                btnPrint.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';

                const ok = await markClaimed(paid);
                if (!ok) {
                    btnPrint.disabled = false;
                    btnPrint.innerHTML = '<i class="fas fa-print"></i> Confirm &amp; Print';
                    return;
                }

                Swal.fire({
                    icon: 'success',
                    title: 'Marked as Claimed',
                    html: paid
                        ? 'Payment recorded as <strong>Paid</strong>.<br>Opening print dialog…'
                        : 'Payment recorded as <strong>Pay at Claim</strong>.<br>Opening print dialog…',
                    timer: 1400,
                    showConfirmButton: false
                });

                setTimeout(openPrintDialog, 800);
                return;
            }

            /* -------- 4. Paid doc, IS_PAID = true → skip question, just confirm -------- */
            const confirm = await Swal.fire({
                title: 'Confirm & Print',
                html:
                    `<div style="text-align:left;font-size:0.9rem;line-height:1.55;">` +
                    `<div><strong>Resident:</strong> ${RESIDENT_NAME}</div>` +
                    `<div><strong>Document:</strong> ${DOC_TYPE}</div>` +
                    `<div><strong>Payment:</strong> <span style="color:#2E7D32;font-weight:700;">Paid ✓</span></div>` +
                    `<hr style="margin:10px 0;">` +
                    `<div>Mark this request as <strong>Claimed</strong> and print?</div>` +
                    `</div>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-check"></i> Confirm &amp; Print',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#2E7D32',
                cancelButtonColor: '#6c757d'
            });
            if (!confirm.isConfirmed) return;

            btnPrint.disabled = true;
            btnPrint.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';

            const ok = await markClaimed(true);
            if (!ok) {
                btnPrint.disabled = false;
                btnPrint.innerHTML = '<i class="fas fa-print"></i> Confirm &amp; Print';
                return;
            }

            Swal.fire({
                icon: 'success',
                title: 'Marked as Claimed',
                text: 'Opening print dialog…',
                timer: 1200,
                showConfirmButton: false
            });

            setTimeout(openPrintDialog, 700);
        });
    }

    if (btnClose) {
        btnClose.addEventListener('click', () => {
            window.close();
            setTimeout(() => {
                window.location.href = 'dashboards/secretary_dashboard.php';
            }, 200);
        });
    }

    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'p') {
            if (btnPrint) {
                e.preventDefault();
                btnPrint.click();
            }
        }
        if (e.key === 'Escape' && btnClose) {
            btnClose.click();
        }
    });
})();
</script>
</body>
</html>