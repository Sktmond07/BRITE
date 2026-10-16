<?php
// config/xendit_config.php
// Xendit API Configuration

// ⚠️ Replace with YOUR actual Secret Key from Xendit Dashboard
// Settings → Developers → API Keys → Generate Secret Key
define('XENDIT_SECRET_KEY', 'xnd_development_8cpqH7nPpvVfjd3tu9RBZqM9dQikUfNrjWCMuByt07PyBhmyGjrl48X3S3ylhU');

// ⚠️ Replace with YOUR Webhook Verification Token
// Settings → Webhooks → (the token shown at the top of the page)
define('XENDIT_WEBHOOK_TOKEN', '5Hxcn7iuUxGnVM8ZF4IAdEppww7DpWqNfEIOFHoHkuzrgJ5B');

define(
    'XENDIT_RETURN_URL',
    'https://chunky-aydan-unstably.ngrok-free.dev/BRITE/resident/payment_return.php'
);

define(
    'XENDIT_CALLBACK_URL',
    'https://chunky-aydan-unstably.ngrok-free.dev/BRITE/resident/xendit_webhook.php'
);

define('XENDIT_ENVIRONMENT', 'development');