<?php

require_once __DIR__ . '/../vendor/autoload.php';
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

function sendPushToRows($subs, $title, $body, $url) {
    if (empty($subs)) return 0;

    $vapid = require __DIR__ . '/push_config.php';
    $webPush = new WebPush([
        'VAPID' => [
            'subject'    => $vapid['subject'],
            'publicKey'  => $vapid['publicKey'],
            'privateKey' => $vapid['privateKey']
        ]
    ]);
    $webPush->setDefaultOptions(['TTL' => 86400, 'urgency' => 'high']);

    $payload = json_encode([
        'title' => $title,
        'body'  => $body,
        'icon'  => '/BRITE/logo.jpg',
        'badge' => '/BRITE/logo.jpg',
        'url'   => $url,
        'tag'   => 'brite-' . time()
    ]);

    foreach ($subs as $sub) {
        try {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint'  => $sub['endpoint'],
                    'publicKey' => $sub['p256dh'],
                    'authToken' => $sub['auth']
                ]),
                $payload
            );
        } catch (Exception $e) { error_log('Push queue: ' . $e->getMessage()); }
    }

    $sent = 0;
    foreach ($webPush->flush() as $report) {
        if ($report->isSuccess()) $sent++;
        else error_log('Push fail: ' . $report->getReason());
    }
    return $sent;
}

function pushToUser($conn, $user_id, $title, $body, $url) {
    $s = mysqli_prepare($conn, "SELECT push_subscription FROM admin WHERE id = ? AND is_active = 1");
    if (!$s) return 0;
    mysqli_stmt_bind_param($s, "i", $user_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    if (!$row || empty($row['push_subscription'])) return 0;
    $sub = json_decode($row['push_subscription'], true);
    if (!$sub) return 0;
    return sendPushToRows([$sub], $title, $body, $url);
}

function pushToRole($conn, $admin_role, $title, $body, $url) {
    $s = mysqli_prepare($conn, "SELECT push_subscription FROM admin
                                WHERE admin_role = ? AND is_active = 1 AND push_subscription IS NOT NULL");
    if (!$s) return 0;
    mysqli_stmt_bind_param($s, "s", $admin_role);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $subs = [];
    while ($row = mysqli_fetch_assoc($r)) {
        $sub = json_decode($row['push_subscription'], true);
        if ($sub) $subs[] = $sub;
    }
    return sendPushToRows($subs, $title, $body, $url);
}
function pushToResident($conn, $resident_id, $title, $body, $url) {
    $s = mysqli_prepare($conn,
        "SELECT push_subscription FROM resident
         WHERE id = ? AND is_active = 1 AND push_subscription IS NOT NULL");
    if (!$s) return 0;
    mysqli_stmt_bind_param($s, "i", $resident_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    if (!$row || empty($row['push_subscription'])) return 0;
    $sub = json_decode($row['push_subscription'], true);
    if (!$sub) return 0;
    return sendPushToRows([$sub], $title, $body, $url);
}