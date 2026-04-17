<?php
// PayMongo Configuration
// Get your keys from: https://dashboard.paymongo.com/developers

// TEST MODE (for development)
define('PAYMONGO_SECRET_KEY_TEST', 'sk_test_7qzyFSzf5r7EadAQz4nRmYAf');
define('PAYMONGO_PUBLIC_KEY_TEST', 'pk_test_WqhahPw9oMbQtBAqu3StzBjw');

// LIVE MODE (for production)
define('PAYMONGO_SECRET_KEY_LIVE', 'sk_live_RRqRm3dHkFjBgJ5iioUYxMmi');
define('PAYMONGO_PUBLIC_KEY_LIVE', 'pk_live_ZHjSgT4dbNYk5X575dBNeudd');

// Set to false for live mode
define('PAYMONGO_TEST_MODE', true);

// Select which key to use
define('PAYMONGO_SECRET_KEY', PAYMONGO_TEST_MODE ? PAYMONGO_SECRET_KEY_TEST : PAYMONGO_SECRET_KEY_LIVE);
define('PAYMONGO_PUBLIC_KEY', PAYMONGO_TEST_MODE ? PAYMONGO_PUBLIC_KEY_TEST : PAYMONGO_PUBLIC_KEY_LIVE);

// Your website URLs - UPDATE THESE WITH YOUR ACTUAL DOMAIN
// For local development, use your local URL
// IMPORTANT: The {CHECKOUT_SESSION_ID} will be replaced by PayMongo automatically
define('PAYMONGO_SUCCESS_URL', 'http://localhost/BRITE/resident/payment_success.php?session_id={CHECKOUT_SESSION_ID}');
define('PAYMONGO_FAILED_URL', 'http://localhost/BRITE/resident/payment_failed.php');
?>