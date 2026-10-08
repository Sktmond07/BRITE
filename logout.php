<?php
session_start();

// Clear all session variables
$_SESSION = array();

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-3600, '/');
}

// Destroy the session
session_destroy();

// Clear any remember me cookies if set
if (isset($_COOKIE['remember_me'])) {
    setcookie('remember_me', '', time()-3600, '/');
}

// Prevent caching of the login page after logout
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Redirect to login page
header('Location: sign_in.php');
exit();
?>