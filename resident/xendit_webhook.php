<?php
// resident/xendit_webhook.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/xendit_config.php';
require_once __DIR__ . '/../includes/XenditService.php';

// Log raw payload
$rawInput = file_get_contents('php://input');
file_put_contents(__DIR__ . '/xendit_webhook.log',
    date('Y-m-d H:i:s') . "\n" . $rawInput . "\n\n", FILE_APPEND);

// Read webhook token
$token = $_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? '';

$payload = json_decode($rawInput, true);

if (!$payload || !is_array($payload)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

if ($token !== XENDIT_WEBHOOK_TOKEN) {
    error_log("Xendit Webhook: Invalid token");
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

$service = new XenditService($conn);
$result = $service->handleWebhook($payload);

http_response_code($result['success'] ? 200 : 400);
header('Content-Type: application/json');
echo json_encode($result);