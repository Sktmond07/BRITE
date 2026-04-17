// Create check_debug.php
<?php
$debug_log = __DIR__ . '/approval_debug.log';
if (file_exists($debug_log)) {
    echo "<pre>";
    echo file_get_contents($debug_log);
    echo "</pre>";
} else {
    echo "No debug log found yet. Try approving a request first.";
}
?>