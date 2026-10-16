<?php
// resident/payment_return.php
// Shown in the payment tab after GCash/Maya redirects back.
// The original BRITE tab checks the payment status on its own and shows
// the success / failed modal, so this page only needs to show a message.

$status = strtolower(trim($_GET['status'] ?? ''));
if (!in_array($status, ['success', 'failed', 'cancelled'], true)) {
    $status = '';
}

switch ($status) {
    case 'success':
        $title   = 'Payment Submitted';
        $message = 'Thank you! You can close this tab and go back to BRITE. Your payment is being verified and the page there will update automatically.';
        $icon    = '⏳';
        break;

    case 'failed':
        $title   = 'Payment Failed';
        $message = 'Your payment was not completed. Please close this tab and go back to BRITE to try again.';
        $icon    = '❌';
        break;

    case 'cancelled':
        $title   = 'Payment Cancelled';
        $message = 'You cancelled the payment. Please close this tab and go back to BRITE.';
        $icon    = '↩️';
        break;

    default:
        $title   = 'Payment Status';
        $message = 'Please close this tab and go back to BRITE to see your payment status.';
        $icon    = 'ℹ️';
        break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> | BRITE</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            box-sizing: border-box;
        }
        .card {
            background: white;
            width: 100%;
            max-width: 430px;
            padding: 32px 24px;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0,0,0,.08);
            text-align: center;
            box-sizing: border-box;
        }
        .icon { font-size: 48px; }
        h1 { font-size: 23px; color: #25243a; }
        p { color: #626274; line-height: 1.6; }
    </style>
</head>
<body>
    <main class="card">
        <div class="icon"><?= htmlspecialchars($icon) ?></div>
        <h1><?= htmlspecialchars($title) ?></h1>
        <p><?= htmlspecialchars($message) ?></p>
    </main>

    <script>
        (function () {
            var status = <?= json_encode($status) ?>;

            // Tell the original BRITE tab right away (works when both tabs share the same origin).
            if (status) {
                try {
                    var ch = new BroadcastChannel('brite-payment');
                    ch.postMessage({ status: status, ts: Date.now() });
                    setTimeout(function () { ch.close(); }, 500);
                } catch (e) { /* not supported - the original tab polls anyway */ }
            }

            // Best effort: close this tab. Browsers only allow this for tabs opened by script,
            // which is how BRITE opens it. If it is blocked, the message above stays on screen.
            setTimeout(function () { window.close(); }, 1500);
        })();
    </script>
</body>
</html>
