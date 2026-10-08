<?php
// ai_proxy.php — Secure server-side proxy for OpenAI API
session_start();
header('Content-Type: application/json');

// Only allow logged-in residents
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Read the prompt from the JS request
$input = json_decode(file_get_contents('php://input'), true);
$prompt = $input['prompt'] ?? '';

if (empty($prompt) || strlen($prompt) > 8000) {
    echo json_encode(['error' => 'Invalid prompt']);
    exit;
}

// ==== PUT YOUR NEW API KEY HERE (server-side only) ====
$apiKey = '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';
$model  = 'gpt-4o-mini';

$payload = [
    'model' => $model,
    'messages' => [
        ['role' => 'system', 'content' => 'You are a strict Philippine Barangay Justice System legal classifier. Always return valid JSON.'],
        ['role' => 'user',   'content' => $prompt]
    ],
    'temperature' => 0.0,
    'max_tokens'  => 500,
    'response_format' => ['type' => 'json_object']
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    error_log("AI Proxy cURL error: $curlErr");
    http_response_code(500);
    echo json_encode(['error' => 'AI service unavailable', 'detail' => $curlErr]);
    exit;
}

// Log errors for debugging
if ($httpCode !== 200) {
    error_log("AI Proxy HTTP $httpCode: " . substr($response, 0, 500));
    http_response_code($httpCode);
    echo $response;
    exit;
}

// Forward the OpenAI response as-is
echo $response;