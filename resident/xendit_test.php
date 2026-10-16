<?php
// resident/xendit_test.php — quick diagnostic

header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== Xendit Integration Diagnostic ===\n\n";

// 1. Check vendor/autoload.php
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];

$autoloadFound = false;
foreach ($autoloadPaths as $path) {
    $exists = file_exists($path) ? '✅' : '❌';
    echo "$exists vendor/autoload.php at: $path\n";
    if (file_exists($path)) $autoloadFound = true;
}

if (!$autoloadFound) {
    echo "\n❌ FATAL: Could not find vendor/autoload.php anywhere.\n";
    echo "You need to run: composer require xendit/xendit-php\n";
    exit;
}

// 2. Load config
require_once __DIR__ . '/../config/xendit_config.php';
echo "\n=== Config ===\n";
echo "Secret Key: " . (defined('XENDIT_SECRET_KEY') ? substr(XENDIT_SECRET_KEY, 0, 20) . '...' : 'NOT SET') . "\n";
echo "Webhook Token: " . (defined('XENDIT_WEBHOOK_TOKEN') ? substr(XENDIT_WEBHOOK_TOKEN, 0, 10) . '...' : 'NOT SET') . "\n";

// 3. Load autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// 4. Check Xendit classes exist
echo "\n=== Xendit Classes ===\n";
$classes = [
    'Xendit\\Configuration',
    'Xendit\\PaymentRequest\\PaymentRequestApi',
    'Xendit\\PaymentRequest\\PaymentRequestParameters',
];
foreach ($classes as $cls) {
    $exists = class_exists($cls) ? '✅' : '❌';
    echo "$exists $cls\n";
}

// 5. Test Xendit connection
echo "\n=== API Connection Test ===\n";
try {
    \Xendit\Configuration::setXenditKey(XENDIT_SECRET_KEY);
    $api = new \Xendit\PaymentRequest\PaymentRequestApi();
    echo "✅ Xendit client initialized\n";
    echo "SDK version: " . (\Xendit\Configuration::getVersion() ?? 'unknown') . "\n";
} catch (\Exception $e) {
    echo "❌ " . $e->getMessage() . "\n";
}

echo "\n=== Done ===\n";