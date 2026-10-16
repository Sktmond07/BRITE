<?php

require_once __DIR__ . '/../vendor/autoload.php';
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * Send a Web Push to one or more subscriptions.
 *
 * @param array       $subs           Array of ['endpoint','p256dh','auth']
 * @param string      $title
 * @param string      $body
 * @param string      $url            Deep link (e.g. dashboard URL)
 * @param string|null $document_path  Relative path to the KP form PDF (optional)
 */
function sendPushToRows($subs, $title, $body, $url, $document_path = null) {
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

    // ✅ Include document_path in the payload so the service worker can deep-link
    $payloadData = [
        'title' => $title,
        'body'  => $body,
        'icon'  => '/BRITE/logo.jpg',
        'badge' => '/BRITE/logo.jpg',
        'url'   => $url,
        'tag'   => 'brite-' . time()
    ];
    if (!empty($document_path)) {
        $payloadData['document_path'] = $document_path;
        // Pre-absolute URL so the service worker doesn't need to know the base path
        $payloadData['document_url']  = '/BRITE/' . ltrim($document_path, '/');
    }
    $payload = json_encode($payloadData);

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

/**
 * Push to a single admin by ID.
 */
function pushToUser($conn, $user_id, $title, $body, $url, $document_path = null) {
    $s = mysqli_prepare($conn, "SELECT push_subscription FROM admin WHERE id = ? AND is_active = 1");
    if (!$s) return 0;
    mysqli_stmt_bind_param($s, "i", $user_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    if (!$row || empty($row['push_subscription'])) return 0;
    $sub = json_decode($row['push_subscription'], true);
    if (!$sub) return 0;
    return sendPushToRows([$sub], $title, $body, $url, $document_path);
}

/**
 * Push to every active admin with a given role (captain/secretary/lupon).
 */
function pushToRole($conn, $admin_role, $title, $body, $url, $document_path = null) {
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
    mysqli_stmt_close($s);
    return sendPushToRows($subs, $title, $body, $url, $document_path);
}

/**
 * Push to a single resident by ID.
 */
function pushToResident($conn, $resident_id, $title, $body, $url, $document_path = null) {
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
    return sendPushToRows([$sub], $title, $body, $url, $document_path);
}