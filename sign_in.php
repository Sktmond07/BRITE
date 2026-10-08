<?php
session_start(); // Make sure session is started

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_type'] === 'resident') {
        header('Location: resident/dashboard.php');
        exit();
    } elseif ($_SESSION['user_type'] === 'admin') {
        // Check role for admin redirection
        require 'config/database.php';
        $user_id = $_SESSION['user_id'];
        $roleQuery = "SELECT admin_role FROM admin WHERE id = ?";
        $stmt = mysqli_prepare($conn, $roleQuery);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($result)) {
            $role = $row['admin_role'];
            switch($role) {
                case 'captain':
                    header('Location: admin/dashboards/captain_dashboard.php');
                    break;
                case 'secretary':
                    header('Location: admin/dashboards/secretary_dashboard.php');
                    break;
                case 'kagawad':
                    header('Location: admin/dashboards/kagawad_dashboard.php');
                    break;
                case 'lupon':
                    header('Location: admin/dashboards/lupon_dashboard.php');
                    break;
                case 'super_admin':
                    header('Location: admin/dashboard.php');
                    break;
                default:
                    header('Location: admin/dashboard.php');
            }
        } else {
            header('Location: admin/dashboard.php');
        }
        exit();
    }
}

// Include database configuration
require 'config/database.php';


$error_message = '';
$success_message = '';
$redirect_url = '';
$verification_pending = false; // Flag for unverified resident

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
   
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Validation
    $errors = [];
    
    if (empty($username)) {
        $errors[] = 'Username or email is required';
    }
    
    if (empty($password)) {
        $errors[] = 'Password is required';
    }
    
   
    if (empty($errors)) {
        try {
           
            global $conn;
            
            $logged_in = false;
            $user_type = '';
            $user_id = '';
            $user_name = '';
            $user_email = '';
            $user_role = '';
            
            // First, check in admin table
            $adminQuery = "SELECT id, username, email, password, admin_role FROM admin WHERE (username = ? OR email = ?) AND is_active = 1";
            $stmt = mysqli_prepare($conn, $adminQuery);
            mysqli_stmt_bind_param($stmt, "ss", $username, $username);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if (mysqli_num_rows($result) > 0) {
                $admin = mysqli_fetch_assoc($result);
                
                // Verify password
                if (password_verify($password, $admin['password'])) {
                    $logged_in = true;
                    $user_type = 'admin';
                    $user_id = $admin['id'];
                    $user_name = $admin['username'];
                    $user_email = $admin['email'];
                    $user_role = $admin['admin_role'];
                }
            }
            mysqli_stmt_close($stmt);
            
            // If not admin, check in resident table
            if (!$logged_in) {
                // Check resident including verification status
                $residentQuery = "SELECT id, username, email, password, is_verified FROM resident WHERE (username = ? OR email = ?) AND is_active = 1";
                $stmt = mysqli_prepare($conn, $residentQuery);
                mysqli_stmt_bind_param($stmt, "ss", $username, $username);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                
                if (mysqli_num_rows($result) > 0) {
                    $resident = mysqli_fetch_assoc($result);
                    
                    // Verify password
                    if (password_verify($password, $resident['password'])) {
                        // Check if resident is verified
                        if ($resident['is_verified'] == 1) {
                            $logged_in = true;
                            $user_type = 'resident';
                            $user_id = $resident['id'];
                            $user_name = $resident['username'];
                            $user_email = $resident['email'];
                        } else {
                            // Resident exists but not verified
                            $verification_pending = true;
                            $error_message = 'Your account is pending verification. Please wait for admin confirmation.';
                        }
                    }
                }
                mysqli_stmt_close($stmt);
            }
            
            // If logged in successfully
            if ($logged_in) {
               
                $_SESSION['user_id'] = $user_id;
                $_SESSION['username'] = $user_name;
                $_SESSION['email'] = $user_email;
                $_SESSION['user_type'] = $user_type;
                $_SESSION['logged_in'] = true;
                $_SESSION['login_time'] = time();
                $_SESSION['last_activity'] = time();
                
                // Store role in session for admin users
                if ($user_type === 'admin') {
                    $_SESSION['admin_role'] = $user_role;
                }
                
                // Update last login based on user type
                if ($user_type === 'admin') {
                    $updateQuery = "UPDATE admin SET last_login = NOW() WHERE id = ?";
                    // Set redirect URL based on role
                    switch($user_role) {
                        case 'captain':
                            $redirect_url = 'admin/dashboards/captain_dashboard.php';
                            break;
                        case 'secretary':
                            $redirect_url = 'admin/dashboards/secretary_dashboard.php';
                            break;
                        case 'kagawad':
                            $redirect_url = 'admin/dashboards/kagawad_dashboard.php';
                            break;
                        case 'lupon':
                            $redirect_url = 'admin/dashboards/lupon_dashboard.php';
                            break;
                        case 'super_admin':
                            $redirect_url = 'admin/dashboard.php';
                            break;
                        default:
                            $redirect_url = 'admin/dashboard.php';
                    }
                } else {
                    $updateQuery = "UPDATE resident SET last_login = NOW() WHERE id = ?";
                    $redirect_url = 'resident/dashboard.php';
                }
                
                $updateStmt = mysqli_prepare($conn, $updateQuery);
                mysqli_stmt_bind_param($updateStmt, "i", $user_id);
                mysqli_stmt_execute($updateStmt);
                mysqli_stmt_close($updateStmt);
                
                $success_message = "Welcome back, $user_name!";
                
               
            } elseif (!$verification_pending) {
                // Only show invalid credentials if not already showing verification message
                $error_message = 'Invalid username/email or password';
            }
            
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $error_message = 'An error occurred. Please try again later.';
        }
    } else {
        $error_message = implode(', ', $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Sign In - BRITE</title>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    <?php include 'sign.css'; ?>
    
  
    .swal2-popup {
      animation: fadeInUp 0.5s ease-out;
    }
    
    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(30px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
    
    /* Success check animation */
    .swal2-success-circular-line-left,
    .swal2-success-circular-line-right,
    .swal2-success-fix {
      animation: none !important;
    }
    
    .swal2-success-ring {
      animation: pulseRing 0.8s ease-out;
    }
    
    @keyframes pulseRing {
      0% {
        transform: scale(0.5);
        opacity: 0;
      }
      50% {
        transform: scale(1);
        opacity: 0.5;
      }
      100% {
        transform: scale(1);
        opacity: 1;
      }
    }
    
    .swal2-success-line-tip,
    .swal2-success-line-long {
      animation: drawCheck 0.6s ease-out forwards;
    }
    
    @keyframes drawCheck {
      0% {
        stroke-dashoffset: 100;
        opacity: 0;
      }
      100% {
        stroke-dashoffset: 0;
        opacity: 1;
      }
    }
    
    /* Shake animation for error modal */
    @keyframes shake {
      0%, 100% {
        transform: translateX(0);
      }
      10%, 30%, 50%, 70%, 90% {
        transform: translateX(-5px);
      }
      20%, 40%, 60%, 80% {
        transform: translateX(5px);
      }
    }
    
    /* Pending verification specific animation */
    @keyframes pulseWarning {
      0% {
        transform: scale(1);
        opacity: 1;
      }
      50% {
        transform: scale(1.1);
        opacity: 0.8;
      }
      100% {
        transform: scale(1);
        opacity: 1;
      }
    }
    
    .pending-icon {
      animation: pulseWarning 1.5s ease-in-out infinite;
    }
    /* Override the existing input-group i styles for password field */
.password-field i {
  position: absolute !important;
  top: 70% !important;
  transform: translateY(-50%) !important;
}

.input-group .password-field i:first-child {
  left: 12px;
}

#togglePassword {
  right: 12px;
  left: auto !important;
}

/* Make sure input has proper padding */
.password-field input {
  padding-left: 40px;
  padding-right: 40px;
}

  </style>
</head>
<body>
  <div class="login-wrapper">
    <div class="login-info">
      <div class="logo-container">
        <img src="logo.jpg" alt="Barangay logo" onerror="this.src='https://via.placeholder.com/120'">
      </div>
      <h1>BRITE - San Bartolome</h1>
      <p>Welcome to BRITE – your easy and convenient way to request documents, file complaints, and connect with your barangay..</p>
    </div>

    <div class="login-form">
      <div class="container">
        <h1 class="form-title">Sign In</h1>
        
        <?php if ($error_message && !$verification_pending): ?>
        <script>
       
          Swal.fire({
            title: 'Login Failed',
            text: '<?php echo addslashes($error_message); ?>',
            icon: 'error',
            confirmButtonColor: '#d33',
            showConfirmButton: false,
            showCancelButton: false,
            allowOutsideClick: false,
            allowEscapeKey: false,
            timer: 1300,
            timerProgressBar: true,
            didOpen: () => {
              // Add shake animation to the error icon
              const errorIcon = document.querySelector('.swal2-icon');
              if (errorIcon) {
                errorIcon.style.animation = 'shake 0.5s ease-out';
              }
              
              // Add auto-close text
              const timerText = document.createElement('div');
              timerText.style.marginTop = '10px';
              timerText.style.fontSize = '12px';
              timerText.style.color = '#d33';
              timerText.style.opacity = '0.8';
              timerText.innerHTML = 'Refreshing in 1 second...';
              
              // Insert after the content
              const content = document.querySelector('.swal2-html-container');
              if (content) {
                content.appendChild(timerText);
              } else {
                const htmlContainer = document.querySelector('.swal2-content') || document.querySelector('.swal2-html-container');
                if (htmlContainer) {
                  htmlContainer.appendChild(timerText);
                } else {
                  document.querySelector('.swal2-popup').appendChild(timerText);
                }
              }
              
              // Update timer text countdown
              let timeLeft = 1;
              const timerInterval = setInterval(() => {
                timeLeft--;
                if (timeLeft >= 0) {
                  if (timerText) timerText.innerHTML = `Refreshing in ${timeLeft} second${timeLeft !== 1 ? 's' : ''}...`;
                }
                if (timeLeft <= 0) {
                  clearInterval(timerInterval);
                }
              }, 1000);
            },
            willClose: () => {
              // Simply refresh the page without any parameters
              window.location.reload();
            }
          });
        </script>
        <?php endif; ?>
        
        <?php if ($verification_pending): ?>
        <script>
          // Special modal for unverified residents
          Swal.fire({
            title: 'Account Not Verified',
            html: `
              <div style="text-align: center;">
                <i class="fas fa-clock" style="font-size: 48px; color: #f39c12; margin-bottom: 15px; animation: pulseWarning 1.5s ease-in-out infinite;"></i>
                <p style="font-size: 16px; margin-bottom: 10px;">Your account is currently <strong>pending verification</strong>.</p>
                <p style="font-size: 14px; color: #666;">Please wait for an administrator to verify your account before logging in.</p>
                <p style="font-size: 13px; color: #999; margin-top: 15px;">You will be notified once your account is verified.</p>
              </div>
            `,
            icon: 'info',
            confirmButtonColor: '#f39c12',
            confirmButtonText: 'OK',
            showConfirmButton: true,
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: () => {
              // Add custom style for the clock icon
              const style = document.createElement('style');
              style.textContent = `
                @keyframes pulseWarning {
                  0% { transform: scale(1); opacity: 1; }
                  50% { transform: scale(1.15); opacity: 0.8; }
                  100% { transform: scale(1); opacity: 1; }
                }
              `;
              document.head.appendChild(style);
            }
          }).then(() => {
            // Clear the form after modal closes to prevent accidental resubmission
            document.getElementById('username').value = '';
            document.getElementById('password').value = '';
          });
        </script>
        <?php endif; ?>
        
        <?php if ($success_message): ?>
        <script>
          // Success modal with auto-close
          Swal.fire({
            title: 'Success!',
            text: '<?php echo addslashes($success_message); ?>',
            icon: 'success',
            confirmButtonColor: '#1e7e6c',
            confirmButtonText: 'Continue',
            showConfirmButton: false,
            allowOutsideClick: false,
            allowEscapeKey: false,
            timer: 2000,
            timerProgressBar: true,
            didOpen: () => {
              // Add bounce animation to the success icon
              const successIcon = document.querySelector('.swal2-success');
              if (successIcon) {
                successIcon.style.animation = 'bounceIn 0.8s ease-out';
              }
              
              // Add timer text
              const timerText = document.createElement('div');
              timerText.style.marginTop = '10px';
              timerText.style.fontSize = '12px';
              timerText.style.color = '#666';
              timerText.innerHTML = 'Redirecting in 2 seconds...';
              
              // Insert after the content
              const content = document.querySelector('.swal2-html-container');
              if (content) {
                content.appendChild(timerText);
              } else {
                const htmlContainer = document.querySelector('.swal2-content') || document.querySelector('.swal2-html-container');
                if (htmlContainer) {
                  htmlContainer.appendChild(timerText);
                } else {
                  document.querySelector('.swal2-popup').appendChild(timerText);
                }
              }
              
              // Update timer text countdown
              let timeLeft = 2;
              const timerInterval = setInterval(() => {
                timeLeft--;
                if (timeLeft >= 0) {
                  if (timerText) timerText.innerHTML = `Redirecting in ${timeLeft} second${timeLeft !== 1 ? 's' : ''}...`;
                }
                if (timeLeft <= 0) {
                  clearInterval(timerInterval);
                }
              }, 1000);
            },
            willClose: () => {
              // Redirect after modal closes
              window.location.href = '<?php echo $redirect_url; ?>';
            }
          });
          
          // Add bounce animation style
          const style = document.createElement('style');
          style.textContent = `
            @keyframes bounceIn {
              0% {
                transform: scale(0);
                opacity: 0;
              }
              50% {
                transform: scale(1.2);
              }
              70% {
                transform: scale(0.95);
              }
              100% {
                transform: scale(1);
                opacity: 1;
              }
            }
          `;
          document.head.appendChild(style);
        </script>
        <?php endif; ?>
        
        <form method="post" id="loginForm">
          <div class="input-group">
            <label for="username">Username or Email</label>
            <i class="fas fa-user"></i>
            <input type="text" name="username" id="username" placeholder="Username or Email" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
          </div>

  <div class="input-group">
  <label for="password">Password</label>
  <div class="password-field">
    <i class="fas fa-lock"></i>
    <input type="password" name="password" id="password" placeholder="Password" required>
    <i class="fas fa-eye" id="togglePassword"></i>
  </div>
</div>

          <p class="recover"><a href="recover_password.php">Recover Password</a></p>
          <input type="submit" class="btn" value="Sign In" name="signIn" id="signInBtn">
        </form>

        <div class="link">
          <p>Don't have an account yet?</p>
          <a href="sign_up.php"><button type="button">Create Account</button></a>
        </div>
      </div>
    </div>
  </div>

  <script>
   // Toggle password visibility
const togglePassword = document.getElementById('togglePassword');
const passwordInput = document.getElementById('password');

if (togglePassword && passwordInput) {
  togglePassword.addEventListener('click', function() {
    // Toggle the type attribute
    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
    passwordInput.setAttribute('type', type);
    
    // Toggle the eye icon
    this.classList.toggle('fa-eye');
    this.classList.toggle('fa-eye-slash');
    
    // Add a subtle animation effect
    this.style.transform = 'scale(1.1)';
    setTimeout(() => {
      this.style.transform = 'translateY(-50%) scale(1)';
    }, 200);
  });
}
    
    // Prevent back button after logout
    if (window.history && window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
    
    // Client-side validation
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
      loginForm.addEventListener('submit', function(e) {
        const username = document.getElementById('username').value.trim();
        const password = document.getElementById('password').value.trim();
        const signInBtn = document.getElementById('signInBtn');
        
        if (username === '') {
          e.preventDefault();
          Swal.fire({
            title: 'Validation Error',
            text: 'Please enter your username or email address.',
            icon: 'error',
            confirmButtonColor: '#d33',
            confirmButtonText: 'OK',
            allowOutsideClick: true,
            allowEscapeKey: true
          }).then(() => {
            document.getElementById('username').focus();
          });
          return false;
        }
        
        if (password === '') {
          e.preventDefault();
          Swal.fire({
            title: 'Validation Error',
            text: 'Please enter your password.',
            icon: 'error',
            confirmButtonColor: '#d33',
            confirmButtonText: 'OK',
            allowOutsideClick: true,
            allowEscapeKey: true
          }).then(() => {
            document.getElementById('password').focus();
          });
          return false;
        }
        
        // Show loading state
        signInBtn.value = 'Signing in...';
        signInBtn.disabled = true;
        signInBtn.style.opacity = '0.7';
      });
    }
    
    // Add inline validation styles
    const usernameInput = document.getElementById('username');
    const passwordInputField = document.getElementById('password');
    
    if (usernameInput) {
      usernameInput.addEventListener('input', function() {
        if (this.value.trim() !== '') {
          this.style.borderColor = '#1e7e6c';
          this.style.borderWidth = '2px';
        } else {
          this.style.borderColor = '#e2e8f0';
          this.style.borderWidth = '1px';
        }
      });
    }
    
    if (passwordInputField) {
      passwordInputField.addEventListener('input', function() {
        if (this.value.trim() !== '') {
          this.style.borderColor = '#1e7e6c';
          this.style.borderWidth = '2px';
        } else {
          this.style.borderColor = '#e2e8f0';
          this.style.borderWidth = '1px';
        }
      });
    }
  </script>


<script>
    // Prevent going back to login page after logout
    history.pushState(null, null, location.href);
    window.onpopstate = function () {
        history.go(1);
    };
    
    // Also check if user is logged in via AJAX
    function checkSession() {
        fetch('check_session.php')
            .then(response => response.json())
            .then(data => {
                if (data.logged_in) {
                    window.location.href = data.redirect;
                }
            });
    }
    
    // Check session every minute
    setInterval(checkSession, 60000);
</script>
</body>
</html>