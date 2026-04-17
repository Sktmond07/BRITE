<?php
// test_email.php
require_once __DIR__ . '/includes/email_helper.php';

// Test email
$testEmail = "guevarraraymond10@gmail.com"; // Change this
$testName = "Test Resident";

echo "Testing email send to: $testEmail<br>";
$result = sendVerificationConfirmationEmail($testEmail, $testName);

if ($result) {
    echo "✅ Email sent successfully!";
} else {
    echo "❌ Email failed to send.<br>";
    echo "Check your PHP mail configuration.<br>";
    
    // Get mail error info
    $error = error_get_last();
    if ($error) {
        echo "Error: " . $error['message'];
    }
}
?>