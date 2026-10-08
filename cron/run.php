<?php
// cron/run.php
// ============================================================
// Generic cron entry point.
// - Loads config + shared helpers
// - Scans cron/tasks/*.php
// - Runs each task function named cron_task_<filename>
// - Logs the result
//
// Called every 5 minutes by Windows Task Scheduler.
// ============================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/cron_bootstrap.php';

// Load push helper (optional)
$pushPath = __DIR__ . '/../admin/push_send.php';
if (file_exists($pushPath)) require_once $pushPath;

$taskDir = __DIR__ . '/tasks';
$files   = glob($taskDir . '/*.php') ?: [];
sort($files);

$runStamp = date('Y-m-d H:i:s');
cronLog("=== RUN START ($runStamp) — " . count($files) . " task(s) ===");

$totalSent = 0;
$failed    = 0;

foreach ($files as $file) {
    $base = basename($file, '.php');
    $func = 'cron_task_' . $base;

    require_once $file;

    if (!function_exists($func)) {
        cronLog("SKIP $base — function $func() not defined");
        $failed++;
        continue;
    }

    $t0 = microtime(true);
    try {
        $sent = $func($conn);
        $ms = round((microtime(true) - $t0) * 1000, 1);
        cronLog("OK   $base — sent=$sent ({$ms}ms)");
        $totalSent += (int)$sent;
    } catch (Throwable $e) {
        $ms = round((microtime(true) - $t0) * 1000, 1);
        cronLog("FAIL $base — " . $e->getMessage() . " ({$ms}ms)");
        $failed++;
    }
}

cronLog("=== RUN END — total_sent=$totalSent failed=$failed ===");
echo "[$runStamp] done — total_sent=$totalSent failed=$failed\n";
exit($failed > 0 ? 1 : 0);