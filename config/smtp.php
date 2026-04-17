<?php

function getSMTPConfig() {
    return [
        // SMTP Server Settings
        'host' => 'smtp.gmail.com',              // SMTP server (gmail: smtp.gmail.com)
        'port' => 587,                            // 587 for TLS, 465 for SSL
        'auth' => true,                           // Enable authentication
        'username' => 'guevarraraymond10@gmail.com',     // Your email address
        'password' => 'mnla crmt xjok olr ',        // Your app-specific password
        'encryption' => 'tls',                    // 'tls' or 'ssl'
        
        // Email Settings
        'from_email' => 'guevarraraymond10@gmail.com',   // Sender email address
        'from_name' => 'BRITE - San Bartolome', // Sender name
        'reply_to' => 'barangay@example.com',     // Reply-to email address
        
        // Debug mode (0 = off, 1 = client, 2 = client and server)
        'debug' => 0
    ];
}
?>