<?php
session_start();
require 'config/database.php';

// PHPMailer configuration - UPDATE THESE VALUES
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load PHPMailer - adjust path as needed
require 'vendor/autoload.php'; // If using Composer

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    $email = $data['email'] ?? '';
    
    if ($action === 'send_otp') {
        // Generate 6-digit OTP
        $otp = sprintf("%06d", mt_rand(1, 999999));
        
        // Store OTP in session
        $_SESSION['otp_code'] = $otp;
        $_SESSION['otp_email'] = $email;
        $_SESSION['otp_expires'] = time() + 300; // 5 minutes expiration
        
        // Send email
        $mail = new PHPMailer(true);
        
        try {
            // SMTP Configuration - UPDATE THESE VALUES
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';        // Your SMTP server
            $mail->SMTPAuth   = true;
            $mail->Username   = 'guevarraraymond10@gmail.com';  // Your email
            $mail->Password   = 'nqbu andg mwqt rrml';     // Your app password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            
            // Recipients
            $mail->setFrom('no-reply@brite.com', 'BRITE - San Bartolome');
            $mail->addAddress($email);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Email Verification OTP - BRITE Registration';
            $mail->Body = '
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; background: #f4f4f4; }
                    .header { background: #43e97b; padding: 20px; text-align: center; color: white; }
                    .content { background: white; padding: 30px; border-radius: 5px; }
                    .otp-code { font-size: 36px; font-weight: bold; color: #43e97b; text-align: center; padding: 20px; letter-spacing: 8px; }
                    .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
                    .warning { color: #ff9800; font-size: 12px; text-align: center; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h2>BRITE - San Bartolome</h2>
                    </div>
                    <div class="content">
                        <h3>Email Verification</h3>
                        <p>Hello,</p>
                        <p>Thank you for registering with BRITE. Please use the following OTP code to verify your email address:</p>
                        <div class="otp-code">' . $otp . '</div>
                        <p>This OTP is valid for <strong>5 minutes</strong>.</p>
                        <p class="warning"><strong>Note:</strong> Do not share this OTP with anyone.</p>
                        <p>If you didn\'t request this verification, please ignore this email.</p>
                    </div>
                    <div class="footer">
                        <p>&copy; ' . date('Y') . ' BRITE - San Bartolome. All rights reserved.</p>
                    </div>
                </div>
            </body>
            </html>';
            
            $mail->AltBody = "Your OTP code is: $otp\n\nThis code is valid for 5 minutes.\n\nDo not share this OTP with anyone.";
            
            $mail->send();
            echo json_encode(['success' => true, 'message' => 'OTP sent successfully to ' . $email]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to send OTP. Please try again. Error: ' . $mail->ErrorInfo]);
        }
        
    } elseif ($action === 'verify_otp') {
        $entered_otp = $data['otp'] ?? '';
        $stored_otp = $_SESSION['otp_code'] ?? '';
        $stored_email = $_SESSION['otp_email'] ?? '';
        $expires = $_SESSION['otp_expires'] ?? 0;
        
        if (empty($stored_otp)) {
            echo json_encode(['success' => false, 'message' => 'No OTP found. Please request a new verification code.']);
        } elseif (time() > $expires) {
            echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new verification code.']);
            unset($_SESSION['otp_code'], $_SESSION['otp_email'], $_SESSION['otp_expires']);
        } elseif ($entered_otp === $stored_otp) {
            $_SESSION['email_verified'] = true;
            unset($_SESSION['otp_code'], $_SESSION['otp_email'], $_SESSION['otp_expires']);
            echo json_encode(['success' => true, 'message' => 'Email verified successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid OTP. Please try again.']);
        }
    }
}
?>