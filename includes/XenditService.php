<?php
// includes/XenditService.php
// Xendit SDK v7 integration — two-step payment flow with Customer

require_once __DIR__ . '/../config/xendit_config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Xendit\Configuration;

class XenditService {

    private $conn;

    public function __construct($conn = null) {
        $this->conn = $conn;
        Configuration::setXenditKey(XENDIT_SECRET_KEY);
    }

    /**
     * Create a direct payment for a specific channel.
     * Flow:
     *   1. Create Payment Method  → returns payment_method_id
     *   2. Create Customer        → returns customer_id
     *   3. Create Payment Request → references both IDs
     */
 
public function createDirectPayment($params)
{
    try {
        if (
            empty($params['reference_id']) ||
            !isset($params['request_amount']) ||
            empty($params['channel_code'])
        ) {
            throw new \InvalidArgumentException(
                'Missing required payment parameters.'
            );
        }

        $channelCode = strtoupper(trim($params['channel_code']));

        // Normalize Maya's channel code.
        if ($channelCode === 'MAYA') {
            $channelCode = 'PAYMAYA';
        }

        if (!in_array($channelCode, ['GCASH', 'PAYMAYA'], true)) {
            throw new \InvalidArgumentException(
                'Unsupported payment channel: ' . $channelCode
            );
        }

        $amount = round((float) $params['request_amount'], 2);

        if ($amount <= 0) {
            throw new \InvalidArgumentException(
                'Payment amount must be greater than zero.'
            );
        }

        $returnUrl = defined('XENDIT_RETURN_URL')
            ? XENDIT_RETURN_URL
            : '';

        if (
            !filter_var($returnUrl, FILTER_VALIDATE_URL) ||
            parse_url($returnUrl, PHP_URL_SCHEME) !== 'https'
        ) {
            throw new \RuntimeException(
                'A valid HTTPS XENDIT_RETURN_URL is required.'
            );
        }

        // V2 requires exactly one payment method definition.
        // This request uses payment_method, not payment_method_id.
        $reqParams = [
            'reference_id'  => (string) $params['reference_id'],
            'currency'      => 'PHP',
            'amount'        => $amount,
            'payment_method' => $this->buildPaymentMethodPayload(
                $channelCode
            ),
            'description' => $params['description']
                ?? 'BRITE Barangay Document Fee',
            'metadata' => $params['metadata'] ?? []
        ];

        error_log(
            'Xendit v2 request: ' .
            json_encode([
                'endpoint' => '/payment_requests',
                'reference_id' => $reqParams['reference_id'],
                'amount' => $amount,
                'channel' => $channelCode,
                'payment_method_present' => true,
                'payment_method_id_present' => false
            ])
        );

        $result = $this->xenditRequest(
            'POST',
            '/payment_requests',
            $reqParams
        );

        if (
            !is_array($result) ||
            empty($result['id'])
        ) {
            throw new \RuntimeException(
                'Xendit did not return a valid payment request ID: ' .
                json_encode($result)
            );
        }

        if (!empty($params['metadata']['request_id'])) {
            $this->savePaymentInfo(
                $params['metadata']['request_id'],
                $result,
                $channelCode
            );
        }

        return $result;

    } catch (\Throwable $e) {
        error_log(
            'Xendit createDirectPayment error: ' .
            $e->getMessage()
        );

        throw new \Exception(
            'Payment gateway error: ' . $e->getMessage(),
            0,
            $e
        );
    }
}



    /**
     * Make a raw Xendit API request via curl.
     * Bypasses the SDK to avoid class/argument bugs.
     */
    private function xenditRequest($method, $endpoint, $body = null) {
        $url = 'https://api.xendit.co' . $endpoint;

        $ch = curl_init($url);

        
$headers = [
    'Authorization: Basic ' . base64_encode(XENDIT_SECRET_KEY . ':'),
    'Content-Type: application/json',
    'Accept: application/json'
];


        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        } elseif ($method === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new Exception('Network error: ' . $curlErr);
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $errMsg = $decoded['message'] ?? $decoded['error_code'] ?? $response;

            // Include field-level validation errors
            if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
                $parts = [];
                foreach ($decoded['errors'] as $e) {
                    $field = $e['field'] ?? ($e['path'] ?? '');
                    $msg   = $e['message'] ?? json_encode($e);
                    $parts[] = ($field ? "$field: " : "") . $msg;
                }
                $errMsg .= ' — ' . implode('; ', $parts);
            }

            throw new Exception("Xendit API error ($httpCode): $errMsg");
        }

        if (!is_array($decoded)) {
            throw new Exception('Invalid response from Xendit: ' . $response);
        }

        return $decoded;
    }

 private function buildPaymentMethodPayload($channelCode) {
    // Where the resident lands after paying on GCash/Maya
    $returnUrl = defined('XENDIT_RETURN_URL') ? XENDIT_RETURN_URL : 'https://example.com/payment-return';

    switch ($channelCode) {
        case 'GCASH':
        case 'PAYMAYA':
            return [
                'type'        => 'EWALLET',
                'reusability' => 'ONE_TIME_USE',
                'ewallet'     => [
                    'channel_code'       => $channelCode,
                    'channel_properties' => [
                        'success_return_url' => $returnUrl . '?status=success',
                        'failure_return_url' => $returnUrl . '?status=failed',
                        'cancel_return_url'  => $returnUrl . '?status=cancelled',
                    ],
                ],
            ];

        case 'QRPH':
            return [
                'type'        => 'QR_CODE',
                'reusability' => 'ONE_TIME_USE',
                'qr_code'     => [
                    'channel_code' => 'QRPH',
                ],
            ];

        case 'BANK_TRANSFER':
            return [
                'type'          => 'BANK_TRANSFER',
                'reusability'   => 'ONE_TIME_USE',
                'bank_transfer' => [
                    'channel_code' => 'PH_UBP',
                ],
            ];

        default:
            throw new Exception("Unsupported channel: $channelCode");
    }
}

    /**
     * Create a Xendit Customer.
     * Xendit requires: reference_id, type=INDIVIDUAL, and either email or mobile_number.
     * `given_names` and `surname` go INSIDE individual_detail.
     */
    private function createXenditCustomer($params) {
        $customerData = [
            'reference_id'      => $params['reference_id'],
            'type'              => 'INDIVIDUAL',
            'individual_detail' => [
                'given_names' => $params['customer_name']    ?? 'Barangay',
                'surname'     => $params['customer_surname'] ?? 'Resident',
            ],
        ];

        // Xendit requires at least one of email or mobile_number
        if (!empty($params['customer_email'])) {
            $customerData['email'] = $params['customer_email'];
        } elseif (!empty($params['customer_phone'])) {
            $customerData['mobile_number'] = $params['customer_phone'];
        } else {
            // Test-mode fallback so Xendit accepts the request
            $customerData['email'] = 'resident@example.com';
        }

        return $this->xenditRequest('POST', '/customers', $customerData);
    }

    /**
     * Get payment request details for polling
     */
    public function getPaymentRequest($paymentRequestId) {
        try {
            return $this->xenditRequest('GET', '/payment_requests/' . urlencode($paymentRequestId));
        } catch (Exception $e) {
            error_log("getPaymentRequest error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Save Xendit info to DB
     */
    private function savePaymentInfo($requestId, $result, $channel) {
        if (!$this->conn) return;

      $paymentRequestId = $result['payment_request_id']
    ?? $result['id']
    ?? null;
        $referenceId      = $result['reference_id'] ?? null;

        $methodMap = [
            'GCASH'         => 'gcash',
            'PAYMAYA'       => 'maya',
            'QRPH'          => 'qrph',
            'BANK_TRANSFER' => 'bank_transfer',
        ];
        $methodEnum = $methodMap[$channel] ?? 'online';

        $sql = "UPDATE document_payments
                SET xendit_invoice_id      = ?,
                    xendit_external_id     = ?,
                    xendit_payment_channel = ?,
                    xendit_expiry_date     = DATE_ADD(NOW(), INTERVAL 15 MINUTE),
                    payment_provider       = 'xendit',
                    payment_method         = ?,
                    payment_status         = 'pending'
                WHERE request_id = ?";

        $stmt = mysqli_prepare($this->conn, $sql);
        if (!$stmt) {
            error_log("savePaymentInfo prepare failed: " . mysqli_error($this->conn));
            return;
        }
        mysqli_stmt_bind_param($stmt, "ssssi",
            $paymentRequestId, $referenceId, $channel, $methodEnum, $requestId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    /**
     * Handle webhook from Xendit
     */
    public function handleWebhook($payload) {
        $event = $payload['event'] ?? '';
        $data  = $payload['data'] ?? [];

        error_log("Xendit Webhook event: $event");

       switch ($event) {
    case 'payment.capture':
    case 'payment.succeeded':
    case 'payment_request.succeeded':
        return $this->handlePaymentSucceeded($data);

    case 'payment.failure':
    case 'payment.failed':
    case 'payment_request.failed':
        return $this->handlePaymentFailed($data);

    case 'payment.pending':
    case 'payment_request.pending':
        return [
            'success' => true,
            'message' => 'Pending noted'
        ];

    default:
        return [
            'success' => true,
            'message' => 'Event ignored: ' . $event
        ];
}
    }

 private function handlePaymentSucceeded($data) {
    if (!$this->conn) return ['success' => false, 'message' => 'No DB'];

   $referenceId = $data['reference_id'] ?? '';
$paymentId   = $data['payment_id'] ?? $data['id'] ?? '';
    $pm          = $data['payment_method'] ?? [];
   $channel     = $data['channel_code'] ?? '';
    $paidAmount  = $data['request_amount'] ?? $data['amount'] ?? 0;
    $paidAt      = $data['updated'] ?? date('Y-m-d H:i:s');
    $metadata    = $data['metadata'] ?? [];

    $requestId = $metadata['request_id'] ?? null;
    if (!$requestId && $referenceId) {
        $parts = explode('-', $referenceId);
        $requestId = $parts[1] ?? null;
    }
    if (!$requestId) {
        error_log("Webhook: cannot determine request_id from $referenceId");
        return ['success' => false, 'message' => 'Missing request_id'];
    }

    $paidAtFormatted = date('Y-m-d H:i:s', strtotime($paidAt));

    $sql = "UPDATE document_payments
            SET payment_status = 'paid',
                payment_date = ?,
                xendit_payment_method = ?,
                xendit_payment_channel = ?,
                xendit_paid_at = ?,
                transaction_ref = ?
            WHERE request_id = ? AND payment_status <> 'paid'";   // <-- guard
    $stmt = mysqli_prepare($this->conn, $sql);
    mysqli_stmt_bind_param($stmt, "sssssi",
        $paidAtFormatted, $channel, $channel, $paidAtFormatted, $paymentId, $requestId);
    $success = mysqli_stmt_execute($stmt);
    $changed = mysqli_stmt_affected_rows($stmt);                  // <-- 1 if it just flipped, 0 if already paid
    mysqli_stmt_close($stmt);

    if ($success && $changed > 0) {
        $stmt2 = mysqli_prepare($this->conn,
            "UPDATE document_requests
             SET payment_status = 'paid', status = 'approved', processed_date = NOW()
             WHERE id = ?");
        mysqli_stmt_bind_param($stmt2, "i", $requestId);
        mysqli_stmt_execute($stmt2);
        mysqli_stmt_close($stmt2);

        $this->notifyResident($requestId, $paidAmount);
        $this->notifySecretariesPaid($requestId);                 // <-- the new method from last time

        error_log("Webhook: Payment recorded for request #$requestId");
    }

    return ['success' => true];
}

    public function notifySecretariesPaid($requestId) {
    $q = mysqli_prepare($this->conn,
        "SELECT dr.document_type, r.first_name, r.last_name
         FROM document_requests dr JOIN resident r ON dr.resident_id = r.id WHERE dr.id = ?");
    mysqli_stmt_bind_param($q, "i", $requestId);
    mysqli_stmt_execute($q);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
    mysqli_stmt_close($q);
    if (!$row) return;

    $title = 'New Document Request';
    $msg   = "A new {$row['document_type']} request (Ref #{$requestId}) was submitted and PAID online by "
           . trim($row['first_name'] . ' ' . $row['last_name']) . ". Please review it in the Document Requests list.";

    $secQ = mysqli_query($this->conn, "SELECT id FROM admin WHERE admin_role = 'secretary' AND is_active = 1");
    while ($secQ && $sec = mysqli_fetch_assoc($secQ)) {
        $sid = (int)$sec['id']; $type = 'info';
        $n = mysqli_prepare($this->conn,
            "INSERT INTO notifications (user_type, user_id, resident_id, type, title, message, reference_id, reference_type)
             VALUES ('admin', ?, NULL, ?, ?, ?, ?, 'document_request')");
        mysqli_stmt_bind_param($n, "isssi", $sid, $type, $title, $msg, $requestId);
        mysqli_stmt_execute($n);
        mysqli_stmt_close($n);
    }
}

    private function handlePaymentFailed($data) {
        if (!$this->conn) return ['success' => false];

        $metadata  = $data['metadata'] ?? [];
        $requestId = $metadata['request_id'] ?? null;

        if (!$requestId) {
            $referenceId = $data['reference_id'] ?? '';
            $parts = explode('-', $referenceId);
            $requestId = $parts[1] ?? null;
        }

        if (!$requestId) return ['success' => false];

        $sql = "UPDATE document_payments
                SET payment_status = 'failed'
                WHERE request_id = ? AND payment_status = 'pending'";
        $stmt = mysqli_prepare($this->conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $requestId);
        $success = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return ['success' => $success];
    }

    private function notifyResident($requestId, $amount) {
        $sql = "SELECT resident_id, document_type FROM document_requests WHERE id = ?";
        $stmt = mysqli_prepare($this->conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $requestId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $request = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if (!$request) return;

        $title   = "Payment Successful";
        $message = "Your payment of PHP " . number_format($amount, 2) . " for {$request['document_type']} has been received.";

        $nSql = "INSERT INTO notifications
                 (resident_id, type, title, message, reference_id, reference_type, is_read, created_at)
                 VALUES (?, 'payment_completed', ?, ?, ?, 'document', 0, NOW())";
        $nStmt = mysqli_prepare($this->conn, $nSql);
        mysqli_stmt_bind_param($nStmt, "issi",
            $request['resident_id'], $title, $message, $requestId);
        mysqli_stmt_execute($nStmt);
        mysqli_stmt_close($nStmt);
    }
}