<?php
// includes/email_helper.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

function sendVerificationConfirmationEmail($toEmail, $residentName) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->SMTPDebug = SMTP::DEBUG_OFF;                      // Disable debug output
        $mail->isSMTP();                                         // Send using SMTP
        $mail->Host       = 'smtp.gmail.com';                    // Set the SMTP server
        $mail->SMTPAuth   = true;                                // Enable SMTP authentication
        $mail->Username   = 'guevarraraymond10@gmail.com';              // SMTP username
        $mail->Password   = 'imsv dvyk jmti lmys';                 // SMTP password (use app password for Gmail)
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;         // Enable SSL encryption
        $mail->Port       = 465;                                 // TCP port
        
        // Recipients
        $mail->setFrom('noreply@barangay.com', 'Barangay System');
        $mail->addAddress($toEmail, $residentName);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Welcome to BRITE  - Account Verified';
        $mail->Body    = "
        <html>
        <head>
            <title>Account Verification Confirmation</title>
        </head>
        <body>
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <h2 style='color: #1a472a;'>Account Verified!</h2>
                <p>Dear <strong>$residentName</strong>,</p>
                <p>Your Brite account has been successfully verified by the admin.</p>
                <p>You can now:</p>
                <ul>
                    <li>Request barangay clearances</li>
                    <li>Schedule appointments</li>
                    <li>Access all barangay services</li>
                </ul>
                <p>Thank you for registering with our Barangay system.</p>
                <hr>
                <p style='font-size: 12px; color: #666;'>This is an automated message, please do not reply.</p>
            </div>
        </body>
        </html>
        ";
        
        $mail->AltBody = "Dear $residentName,\n\nYour Barangay account has been successfully verified.\n\nThank you for registering.";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email could not be sent. Error: {$mail->ErrorInfo}");
        return false;
    }
}
?>