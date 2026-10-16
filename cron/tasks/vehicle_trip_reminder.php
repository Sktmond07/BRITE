<?php
// cron/tasks/vehicle_trip_reminder.php
// ============================================================
// TASK: Vehicle trip reminders + auto-complete.
//   • 30-min before pickup_time → notify resident
//   • Past trip end time → flip 'approved'/'borrowed' → 'completed'
// ============================================================

function cron_task_vehicle_trip_reminder($conn) {
    $sent = 0;

    // A) TRIPS WITHIN NEXT 30 MINUTES
    $upcomingSql = "SELECT r.id AS request_id,
                           r.resident_id,
                           r.`reference`,
                           vr.vehicle_name,
                           vr.pickup_time,
                           vr.pickup_location
                    FROM requests r
                    JOIN vehicle_requests vr ON r.id = vr.request_id
                    WHERE r.request_type = 'vehicle'
                      AND r.status IN ('approved','borrowed')
                      AND CONCAT(r.trip_date, ' ', vr.pickup_time) BETWEEN NOW()
                                                                    AND DATE_ADD(NOW(), INTERVAL 30 MINUTE)";

    $stmt = mysqli_prepare($conn, $upcomingSql);
    if (!$stmt) {
        cronLog("vehicle_trip_reminder: PREPARE (upcoming) FAILED — " . mysqli_error($conn));
    } else {
        mysqli_stmt_execute($stmt);
        $res  = mysqli_stmt_get_result($stmt);
        $rows = [];
        while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
        mysqli_free_result($res);
        mysqli_stmt_close($stmt);

        foreach ($rows as $row) {
            $residentId = (int)$row['resident_id'];
            $requestId  = (int)$row['request_id'];

            if (cronWasNotificationSentToday($conn, $residentId, 'vehicle_trip_reminder', $requestId)) {
                continue;
            }

            $pickupNice = date('g:i A', strtotime($row['pickup_time']));
            $title = "🚐 Vehicle Pickup in 30 Minutes";
            $msg   = "Your {$row['vehicle_name']} trip is scheduled for pick up at "
                   . "{$row['pickup_location']} at {$pickupNice}. Please be there on time.";

            cronSendNotification(
                $conn, 'resident', $residentId, null,
                $title, $msg, 'vehicle_trip_reminder',
                null,
                $requestId, 'vehicle_request'
            );

            cronSafePushResident(
                $conn, $residentId,
                $title, $msg,
                '/BRITE/resident/dashboard.php'
            );

            $upd = mysqli_prepare($conn,
                "UPDATE requests SET last_return_reminder_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($upd, "i", $requestId);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);

            $sent++;
            cronLog("vehicle_trip_reminder: sent to resident=$residentId for request=$requestId");
        }
    }

    // B) AUTO-COMPLETE PAST THEIR END TIME
    $autoCompleteSql = "SELECT r.id AS request_id,
                               r.resident_id,
                               vr.vehicle_name
                        FROM requests r
                        JOIN vehicle_requests vr ON r.id = vr.request_id
                        WHERE r.request_type = 'vehicle'
                          AND r.status IN ('approved','borrowed')
                          AND CONCAT(r.trip_date, ' ',
                                     COALESCE(r.trip_slot_end,
                                              ADDTIME(vr.pickup_time,
                                                      SEC_TO_TIME(vr.estimated_hours * 3600))
                                     )
                              ) < NOW()";

    $autoStmt = mysqli_prepare($conn, $autoCompleteSql);
    if (!$autoStmt) {
        cronLog("vehicle_trip_reminder: PREPARE (auto-complete) FAILED — " . mysqli_error($conn));
    } else {
        mysqli_stmt_execute($autoStmt);
        $autoRes  = mysqli_stmt_get_result($autoStmt);
        $autoRows = [];
        while ($row = mysqli_fetch_assoc($autoRes)) $autoRows[] = $row;
        mysqli_free_result($autoRes);
        mysqli_stmt_close($autoStmt);

        foreach ($autoRows as $row) {
            $requestId  = (int)$row['request_id'];
            $residentId = (int)$row['resident_id'];

            $upd = mysqli_prepare($conn,
                "UPDATE requests
                    SET status = 'completed',
                        completed_at = NOW(),
                        processed_at = NOW()
                  WHERE id = ? AND status IN ('approved','borrowed')");
            mysqli_stmt_bind_param($upd, "i", $requestId);
            mysqli_stmt_execute($upd);
            $affected = mysqli_stmt_affected_rows($upd);
            mysqli_stmt_close($upd);

            if ($affected > 0) {
                $title = "✅ Trip Completed";
                $msg   = "Your {$row['vehicle_name']} trip has been marked as completed. Thank you!";

                cronSendNotification(
                    $conn, 'resident', $residentId, null,
                    $title, $msg, 'vehicle_trip_completed',
                    null,
                    $requestId, 'vehicle_request'
                );

                cronSafePushResident(
                    $conn, $residentId,
                    $title, $msg,
                    '/BRITE/resident/dashboard.php'
                );

                $sent++;
                cronLog("vehicle_trip_reminder: auto-completed request=$requestId (vehicle={$row['vehicle_name']})");
            }
        }
    }

    return $sent;
}