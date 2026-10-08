<?php
session_start();
// Include database configuration
require 'config/database.php';

$error_message = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=yes" />
  <title>Connect BRITE Account - Mobile App</title>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
    }

    body {
      min-height: 100vh;
      background: linear-gradient(150deg, #43e97b 20%, #11612e 80%);
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 20px;
    }

    .login-container {
      background: #ffffff;
      border-radius: 12px;
      width: 100%;
      max-width: 400px;
      box-shadow: 0 6px 16px rgba(0, 0, 0, 0.15);
      overflow: hidden;
    }

    .logo-area {
      background: linear-gradient(135deg, #43e97b, #2d8c4e);
      padding: 30px;
      text-align: center;
    }

    .logo-circle {
      width: 80px;
      height: 80px;
      background: white;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 15px auto;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    .logo-circle img {
      width: 60px;
      height: 60px;
      object-fit: contain;
    }

    .logo-area h2 {
      color: white;
      font-size: 20px;
      margin-bottom: 5px;
    }

    .logo-area p {
      color: rgba(255,255,255,0.9);
      font-size: 12px;
    }

    .form-area {
      padding: 30px;
    }

    .form-title {
      font-size: 22px;
      color: #333;
      text-align: center;
      margin-bottom: 25px;
    }

    .input-group {
      margin-bottom: 20px;
      position: relative;
    }

    .input-group label {
      display: block;
      margin-bottom: 8px;
      font-size: 14px;
      color: #444;
      font-weight: 500;
    }

    .input-group i {
      position: absolute;
      top: 40px;
      left: 12px;
      color: #999;
    }

    .input-group input {
      width: 100%;
      padding: 12px 12px 12px 40px;
      border: 1px solid #e0e0e0;
      border-radius: 8px;
      font-size: 14px;
      transition: all 0.3s;
    }

    .input-group input:focus {
      outline: none;
      border-color: #43e97b;
      box-shadow: 0 0 5px rgba(67, 233, 123, 0.3);
    }

    .btn-login {
      width: 100%;
      padding: 12px;
      background: #43e97b;
      color: white;
      border: none;
      border-radius: 8px;
      font-size: 16px;
      font-weight: bold;
      cursor: pointer;
      transition: background 0.3s;
      margin-top: 10px;
    }

    .btn-login:hover {
      background: #36c76d;
    }

    .info-text {
      text-align: center;
      font-size: 12px;
      color: #999;
      margin-top: 20px;
    }

    .error-message {
      background: #ffebee;
      color: #c62828;
      padding: 10px;
      border-radius: 8px;
      font-size: 13px;
      margin-bottom: 20px;
      text-align: center;
    }
    
    .fixed-barangay {
      text-align: center;
      font-size: 11px;
      color: #D32F2F;
      margin-top: 15px;
      padding: 8px;
      background: #FFF3E0;
      border-radius: 6px;
    }
  </style>
</head>
<body>
  <div class="login-container">
    <div class="logo-area">
      <div class="logo-circle">
        <img src="logo.jpg" alt="Logo" onerror="this.src='https://via.placeholder.com/60'">
      </div>
      <h2>BRITE San Bartolome</h2>
      <p>Connect your account to mobile app</p>
    </div>
    <div class="form-area">
      <h3 class="form-title">Sign In to Connect</h3>
      
      <?php if ($error_message): ?>
      <div class="error-message">
        <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
      </div>
      <?php endif; ?>
      
      <form method="POST" action="mobile_app_auth.php">
        <div class="input-group">
          <label for="username">Username or Email</label>
          <i class="fas fa-user"></i>
          <input type="text" name="username" id="username" placeholder="Enter your username or email" required>
        </div>
        
        <div class="input-group">
          <label for="password">Password</label>
          <i class="fas fa-lock"></i>
          <input type="password" name="password" id="password" placeholder="Enter your password" required>
        </div>
        
        <button type="submit" class="btn-login">
          <i class="fas fa-link"></i> Connect App
        </button>
      </form>
      
      <div class="fixed-barangay">
        <i class="fas fa-map-marker-alt"></i> Barangay: San Bartolome (Fixed)
      </div>
      
      <div class="info-text">
        <i class="fas fa-shield-alt"></i> Your account info will be securely synced
      </div>
    </div>
  </div>
</body>
</html>