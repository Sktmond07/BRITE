<?php
// admin/complaint_integrity_engine.php
// ============================================================
//  COMPLAINT INTEGRITY ENGINE
//  Automatically analyzes every new complaint and produces:
//    - auto_flag_score (0-100)
//    - auto_flag_tier  (0-4)
//    - auto_flag_reasons (JSON list of signals)
//
//  The Secretary only REVIEWS the output. No manual flagging.
// ============================================================

if (!function_exists('analyzeComplaintIntegrity')) {

/**
 * @return array {
 *   score: int 0-100,
 *   tier: int 0-4,
 *   reasons: array<string>,
 *   signals: array<string, mixed>
 * }
 */
function analyzeComplaintIntegrity($conn, $complaint_id) {
    // Load complaint + resident history in one pass
    $c = mysqli_prepare($conn,
        "SELECT c.*, r.id AS rid
           FROM complaints c
           JOIN resident r ON r.id = c.created_by
          WHERE c.id = ?");
    mysqli_stmt_bind_param($c, "i", $complaint_id);
    mysqli_stmt_execute($c);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($c));
    mysqli_stmt_close($c);
    if (!$row) return ['score'=>0,'tier'=>0,'reasons'=>[],'signals'=>[]];

    $rid = (int)$row['rid'];
    $reasons = [];
    $signals = [];
    $score = 0;

    // ---- Signal 1: Filing velocity (last 24h, 7d, 30d) ----
    $v = mysqli_prepare($conn,
        "SELECT
            SUM(created_at >= NOW() - INTERVAL 1 DAY)  AS d1,
            SUM(created_at >= NOW() - INTERVAL 7 DAY)  AS d7,
            SUM(created_at >= NOW() - INTERVAL 30 DAY) AS d30,
            COUNT(*) AS total
         FROM complaints WHERE created_by = ? AND id != ?");
    mysqli_stmt_bind_param($v, "ii", $rid, $complaint_id);
    mysqli_stmt_execute($v);
    $vel = mysqli_fetch_assoc(mysqli_stmt_get_result($v));
    mysqli_stmt_close($v);

    if ((int)$vel['d1'] >= 3) { $score += 25; $reasons[] = 'High filing velocity: ' . $vel['d1'] . ' complaints in 24 hours.'; }
    elseif ((int)$vel['d1'] >= 2) { $score += 10; $reasons[] = 'Multiple complaints filed within 24 hours.'; }
    if ((int)$vel['d7'] >= 5) { $score += 15; $reasons[] = 'Frequent submissions in the last 7 days (' . $vel['d7'] . ').'; }
    if ((int)$vel['d30'] >= 10) { $score += 10; $reasons[] = 'Very high 30-day volume (' . $vel['d30'] . ').'; }
    $signals['velocity'] = $vel;

    // ---- Signal 2: Duplicate content (text similarity) ----
    $desc = mb_strtolower(trim($row['description'] ?? ''));
    if (strlen($desc) > 0) {
        $dup = mysqli_prepare($conn,
            "SELECT id, reference_number, description
               FROM complaints
              WHERE created_by = ? AND id != ?
                AND created_at >= NOW() - INTERVAL 30 DAY");
        mysqli_stmt_bind_param($dup, "ii", $rid, $complaint_id);
        mysqli_stmt_execute($dup);
        $dupRes = mysqli_stmt_get_result($dup);
        $duplicates = [];
        while ($d = mysqli_fetch_assoc($dupRes)) {
            $other = mb_strtolower(trim($d['description'] ?? ''));
            if ($other === '') continue;
            similar_text($desc, $other, $pct);
            if ($pct >= 85) {
                $duplicates[] = ['ref'=>$d['reference_number'],'similarity'=>round($pct,1)];
            }
        }
        mysqli_stmt_close($dup);
        if (count($duplicates) > 0) {
            $score += 30;
            $reasons[] = 'Near-identical content to ' . count($duplicates) . ' previous complaint(s): '
                       . implode(', ', array_map(fn($x) => $x['ref'] . ' (' . $x['similarity'] . '%)', $duplicates));
        }
        $signals['duplicates'] = $duplicates;
    }

    // ---- Signal 3: Very short / low-information description ----
    $wordCount = str_word_count($desc);
    if ($wordCount > 0 && $wordCount < 4) {
        $score += 15;
        $reasons[] = 'Description is too short to evaluate (' . $wordCount . ' word(s)).';
    }
    $signals['word_count'] = $wordCount;

    // ---- Signal 4: Prior unfounded / warning history ----
    $h = mysqli_prepare($conn,
        "SELECT
            SUM(status = 'flagged')       AS flagged,
            SUM(status = 'warned')        AS warned,
            SUM(status = 'manual_review') AS manual_review,
            SUM(status = 'restricted')    AS restricted
         FROM prank_tracking WHERE resident_id = ?");
    // NOTE: legacy table name — will be replaced by complaint_integrity_reviews in later migration.
    // We keep reading it during transition so no data is lost.
    if ($h) {
        mysqli_stmt_bind_param($h, "i", $rid);
        mysqli_stmt_execute($h);
        $hist = mysqli_fetch_assoc(mysqli_stmt_get_result($h));
        mysqli_stmt_close($h);

        if ((int)$hist['restricted'] > 0)    { $score += 40; $reasons[] = 'Resident currently under filing restriction.'; }
        if ((int)$hist['manual_review'] > 0) { $score += 25; $reasons[] = 'Resident has prior pattern-of-abuse finding.'; }
        if ((int)$hist['warned'] > 0)        { $score += 15; $reasons[] = 'Resident has prior verified unfounded finding.'; }
        if ((int)$hist['flagged'] > 0)       { $score += 5;  $reasons[] = 'Resident has prior low-confidence flag.'; }
        $signals['history'] = $hist;
    }

    // ---- Signal 5: Required fields missing / generic ----
    if (empty($row['complaint_subject']) || $row['complaint_subject'] === 'Other') {
        $score += 5; $reasons[] = 'Complaint subject not specified.';
    }

    // ---- Signal 6: Reported but no evidence + no witness on a serious type ----
    if ((int)$row['has_evidence'] === 0 && empty($row['witnesses'])) {
        $score += 5; $reasons[] = 'No evidence and no witnesses provided.';
    }

    // Clamp
    if ($score > 100) $score = 100;

    // Map score → tier
    // 0-19  = Tier 0 (no flag)
    // 20-39 = Tier 1 (low confidence)
    // 40-59 = Tier 2 (medium)
    // 60-79 = Tier 3 (high - requires review)
    // 80+   = Tier 4 (critical - auto-restriction review)
    $tier = 0;
    if ($score >= 80)      $tier = 4;
    elseif ($score >= 60)  $tier = 3;
    elseif ($score >= 40)  $tier = 2;
    elseif ($score >= 20)  $tier = 1;

    return [
        'score'   => $score,
        'tier'    => $tier,
        'reasons' => $reasons,
        'signals' => $signals,
    ];
}

/**
 * Auto-run on complaint insert. Writes a row to complaint_integrity_reviews
 * if tier >= 1. Never touches the complaints table.
 */
function runComplaintIntegrityCheck($conn, $complaint_id) {
    $result = analyzeComplaintIntegrity($conn, $complaint_id);
    if ($result['tier'] < 1) return $result;

    // Get resident id
    $q = mysqli_prepare($conn, "SELECT created_by FROM complaints WHERE id = ?");
    mysqli_stmt_bind_param($q, "i", $complaint_id);
    mysqli_stmt_execute($q);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
    mysqli_stmt_close($q);
    if (!$r) return $result;
    $rid = (int)$r['created_by'];

    $reasonsJson = json_encode($result['reasons'], JSON_UNESCAPED_UNICODE);

    $ins = mysqli_prepare($conn,
        "INSERT INTO complaint_integrity_reviews
            (complaint_id, resident_id, auto_flag_score, auto_flag_reasons,
             auto_flag_tier, auto_flagged_at, review_status)
         VALUES (?, ?, ?, ?, ?, NOW(), 'pending')
         ON DUPLICATE KEY UPDATE
            auto_flag_score = VALUES(auto_flag_score),
            auto_flag_reasons = VALUES(auto_flag_reasons),
            auto_flag_tier = VALUES(auto_flag_tier),
            auto_flagged_at = NOW()");
    mysqli_stmt_bind_param($ins, "iiisi",
        $complaint_id, $rid, $result['score'], $reasonsJson, $result['tier']);
    mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);

    return $result;
}

/**
 * Get active filing restriction for a resident (if any).
 * Returns array or null.
 */
function getActiveFilingRestriction($conn, $resident_id) {
    $s = mysqli_prepare($conn,
        "SELECT * FROM complaint_filing_restrictions
          WHERE resident_id = ?
            AND status = 'active'
            AND (restriction_end IS NULL OR restriction_end > NOW())
          ORDER BY created_at DESC LIMIT 1");
    if (!$s) return null;
    mysqli_stmt_bind_param($s, "i", $resident_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    return $row ?: null;
}
}
?>