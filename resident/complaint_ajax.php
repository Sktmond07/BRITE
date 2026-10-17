<?php
// resident/complaint_ajax.php — RESIDENT SIDE ONLY
// ============================================================
//  ROLE-AWARE: A resident is EITHER a complainant OR a respondent
//  on any given complaint. Access rules differ per role:
//    • Complainant — sees own filings
//    • Respondent  — sees case ONLY after captain takes action
//                    (status NOT in pending_review / pending_captain_action / escalated)
//    • Audit trail — HIDDEN from BOTH roles
// ============================================================
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../admin/push_send.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_type = $_SESSION['user_type'];
$user_id   = $_SESSION['user_id'];
$action    = $_POST['action'] ?? $_GET['action'] ?? '';

/* ---------------- Helpers ---------------- */

function generateReferenceNumber() {
    return 'CMP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

/**
 * Statuses where a respondent is allowed to see the case.
 * Hidden: pending_review, pending_captain_action, escalated
 */
function respondentCanSeeStatus($status) {
    $hiddenFromRespondent = ['pending_review', 'pending_captain_action', 'escalated'];
    if (in_array($status, $hiddenFromRespondent, true)) return false;
    return true;
}

/**
 * Sensitive statuses where evidence must be hidden from
 * the respondent (referrals contain captain notes).
 */
function isSensitiveStatus($status) {
    return in_array($status, ['escalated', 'pending_captain_review', 'referred_dispatched', 'released'], true);
}

/**
 * Determine the role of a given resident for a complaint.
 */
function getResidentRoleInComplaint($conn, $complaint_id, $resident_id) {
    $sql = "SELECT party_type FROM complaint_parties
            WHERE complaint_id = ? AND matched_resident_id = ?
            ORDER BY FIELD(party_type,'complainant','respondent','witness')
            LIMIT 1";
    $s = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($s, "ii", $complaint_id, $resident_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    return $row['party_type'] ?? null;
}

/**
 * Build a role-filtered list of KP forms for a resident.
 */
function buildResidentKpForms($conn, $complaint_id, $role) {
    if (!in_array($role, ['complainant', 'respondent'], true)) return [];

    $forms = [];

    $cs = mysqli_prepare($conn, "SELECT reference_number, status,
                                        kp16_pdf_path, kp16_generated_at,
                                        kp17_pdf_path, kp17_generated_at,
                                        kp20_pdf_path, kp20_generated_at
                                 FROM complaints WHERE id = ?");
    mysqli_stmt_bind_param($cs, "i", $complaint_id);
    mysqli_stmt_execute($cs);
    $c = mysqli_fetch_assoc(mysqli_stmt_get_result($cs));
    mysqli_stmt_close($cs);
    if (!$c) return [];

    // ── KP #8 / KP #9 per mediation hearing ──
    $ms = mysqli_prepare($conn,
        "SELECT id, session_number, hearing_date, hearing_time,
                kp8_complainant_pdf_path, kp8_complainant_issued_at,
                kp9_respondent_pdf_path,  kp9_respondent_issued_at
           FROM complaint_hearings
          WHERE complaint_id = ? AND session_type = 'mediation'
          ORDER BY session_number ASC, id ASC");
    mysqli_stmt_bind_param($ms, "i", $complaint_id);
    mysqli_stmt_execute($ms);
    $mr = mysqli_stmt_get_result($ms);
    while ($h = mysqli_fetch_assoc($mr)) {
        $sessionNo = (int)$h['session_number'];
        $hDate = $h['hearing_date'] ? date('M j, Y', strtotime($h['hearing_date'])) : '—';
        $hTime = $h['hearing_time'] ? date('g:i A', strtotime($h['hearing_time'])) : '';

        if ($role === 'complainant' && !empty($h['kp8_complainant_pdf_path'])) {
            $forms[] = [
                'form_tag'   => 'kp8',
                'form_no'    => 8,
                'form_name'  => 'Notice of Hearing (Mediation)',
                'issued_at'  => $h['kp8_complainant_issued_at'],
                'hearing_no' => $sessionNo,
                'event'      => "Mediation Hearing #{$sessionNo} on {$hDate}" . ($hTime ? " at {$hTime}" : ''),
                'pdf_path'   => $h['kp8_complainant_pdf_path'],
            ];
        }
        if ($role === 'respondent' && !empty($h['kp9_respondent_pdf_path'])) {
            $forms[] = [
                'form_tag'   => 'kp9',
                'form_no'    => 9,
                'form_name'  => 'Summons',
                'issued_at'  => $h['kp9_respondent_issued_at'],
                'hearing_no' => $sessionNo,
                'event'      => "Mediation Hearing #{$sessionNo} on {$hDate}" . ($hTime ? " at {$hTime}" : ''),
                'pdf_path'   => $h['kp9_respondent_pdf_path'],
            ];
        }
    }
    mysqli_stmt_close($ms);

    // ── KP #12 conciliation — role-specific copy ──
    $chStmt = mysqli_prepare($conn,
        "SELECT id, session_number, hearing_date, hearing_time,
                kp12_complainant_pdf_path, kp12_complainant_issued_at,
                kp12_respondent_pdf_path,  kp12_respondent_issued_at
           FROM complaint_hearings
          WHERE complaint_id = ? AND session_type = 'conciliation'
          ORDER BY session_number ASC, id ASC");
    mysqli_stmt_bind_param($chStmt, "i", $complaint_id);
    mysqli_stmt_execute($chStmt);
    $chRes = mysqli_stmt_get_result($chStmt);
    while ($h = mysqli_fetch_assoc($chRes)) {
        $sessionNo = (int)$h['session_number'];
        $hDate = $h['hearing_date'] ? date('M j, Y', strtotime($h['hearing_date'])) : '—';
        $hTime = $h['hearing_time'] ? date('g:i A', strtotime($h['hearing_time'])) : '';

        if ($role === 'complainant' && !empty($h['kp12_complainant_pdf_path'])) {
            $forms[] = [
                'form_tag'   => 'kp12',
                'form_no'    => 12,
                'form_name'  => 'Notice of Hearing (Conciliation)',
                'issued_at'  => $h['kp12_complainant_issued_at'],
                'hearing_no' => $sessionNo,
                'event'      => "Conciliation Hearing #{$sessionNo} before the Pangkat on {$hDate}" . ($hTime ? " at {$hTime}" : ''),
                'pdf_path'   => $h['kp12_complainant_pdf_path'],
            ];
        }
        if ($role === 'respondent' && !empty($h['kp12_respondent_pdf_path'])) {
            $forms[] = [
                'form_tag'   => 'kp12',
                'form_no'    => 12,
                'form_name'  => 'Notice of Hearing (Conciliation)',
                'issued_at'  => $h['kp12_respondent_issued_at'],
                'hearing_no' => $sessionNo,
                'event'      => "Conciliation Hearing #{$sessionNo} before the Pangkat on {$hDate}" . ($hTime ? " at {$hTime}" : ''),
                'pdf_path'   => $h['kp12_respondent_pdf_path'],
            ];
        }
    }
    mysqli_stmt_close($chStmt);

    // ── KP #16 ──
    if (!empty($c['kp16_pdf_path'])) {
        $forms[] = [
            'form_tag'  => 'kp16',
            'form_no'   => 16,
            'form_name' => 'Amicable Settlement',
            'issued_at' => $c['kp16_generated_at'],
            'event'     => 'Agreement reached between the parties.',
            'pdf_path'  => $c['kp16_pdf_path'],
        ];
    }

    // ── KP #17 (Repudiation) — visible to BOTH parties ──
    if (!empty($c['kp17_pdf_path'])) {
        $forms[] = [
            'form_tag'  => 'kp17',
            'form_no'   => 17,
            'form_name' => 'Repudiation',
            'issued_at' => $c['kp17_generated_at'],
            'event'     => 'Filed by a party to void the agreement on grounds of fraud, violence, or intimidation.',
            'pdf_path'  => $c['kp17_pdf_path'],
        ];
    }

    // ── KP #20 ──
    if (!empty($c['kp20_pdf_path']) && $c['status'] === 'certificate_issued') {
        $forms[] = [
            'form_tag'  => 'kp20',
            'form_no'   => 20,
            'form_name' => 'Certification to File Action',
            'issued_at' => $c['kp20_generated_at'],
            'event'     => 'Issued because mediation did not result in a settlement.',
            'pdf_path'  => $c['kp20_pdf_path'],
        ];
    }

    return $forms;
}


/* ============================================================
   VALIDATION HELPERS — spam / prank / gibberish gate
   ============================================================ */

/** Common placeholder names & junk tokens (lowercase). */
function _validationPlaceholders() {
    return [
        'test','testing','tester','sample','n/a','na','none','null','nil',
        'anonymous','anon','asdf','asdfasdf','asdfgh','qwerty','qwertyuiop',
        'zxcvbn','zxcvbnm','hjkl','xxx','xxxx','aaaa','bbbb','cccc',
        'abc','abcd','abcde','abcdef','123','1234','12345','name','fullname',
        'firstname','lastname','first name','last name','no name','noname',
        'unknown','noone','nobody','john doe','jane doe','juan dela cruz',
        'juandelacruz','juandc','juan d','juan','pedro','pedro penduko',
        'lorem ipsum','lorem','ipsum','placeholder','tbd','tba','todo',
        'asdfasdfasdf','asdasd','asdasdasd','hahaha','hahahaha','hehehe',
        'jejeje','lol','lmao','wtf','test test','test name','name here'
    ];
}

/** Common junk tokens that shouldn't be the entire content of a description. */
function _validationJunkTokens() {
    return [
        'test','testing','sample','asdf','asdfasdf','asdfgh','qwerty','zxcvbn',
        'hjkl','123','1234','12345','aaaa','bbbb','cccc','dddd','abc','abcd',
        'abcde','lorem','ipsum','none','n/a','na','null','xxx','yyy','zzz'
    ];
}

/** Contact must be exactly 11 digits and start with 09. */
function validateContactNumber($value) {
    $value = trim((string)$value);
    if ($value === '') return false;
    if (!preg_match('/^\d+$/', $value)) return false;
    if (strlen($value) !== 11) return false;
    if (substr($value, 0, 2) !== '09') return false;
    return true;
}

/** Basic email format check. Empty string = OK (optional). */
function validateEmailOrEmpty($value) {
    $value = trim((string)$value);
    if ($value === '') return true;
    return (bool)filter_var($value, FILTER_VALIDATE_EMAIL);
}

/**
 * Real-name validator. Returns [true, ''] on pass,
 * [false, 'reason'] on fail.
 */
/**
 * Real-name validator. Returns [true, ''] on pass,
 * [false, 'reason'] on fail.
 */
function validatePersonName($name) {
    $name = trim((string)$name);
    if ($name === '') return [false, 'Name is required.'];

    // Letters, spaces, hyphen, apostrophe, dot, ñ only
    if (!preg_match('/^[\p{L}\s\'\.\-]+$/u', $name)) {
        return [false, 'Name must contain letters only — no numbers or symbols.'];
    }

    if (mb_strlen($name) < 3) {
        return [false, 'Name is too short.'];
    }

    // Reject 3+ identical characters in a row (Juaaaan, sssss)
    if (preg_match('/(.)\1{2,}/u', $name)) {
        return [false, 'Name looks invalid — please type your real name.'];
    }

    // Single-char repeat (aaaa)
    if (preg_match('/^(.)\1+$/u', $name)) {
        return [false, 'Name looks invalid — please type your real name.'];
    }

    // ── Gibberish detection ──
    // Collapse to letters only for pattern checks
    $flat = strtolower(preg_replace('/[^\p{L}]/u', '', $name));
    if ($flat === '') return [false, 'Name looks invalid — please type your real name.'];

    // 1. No vowels anywhere in the whole name (and no apostrophes to stand in)
    if (!preg_match('/[aeiouyáéíóú]/iu', $flat)) {
        return [false, 'Name looks invalid — please type your real name.'];
    }

    // 2. Consonant run of 4+ (hsdbasjbd, sdfghjkl, qwrty)
    if (preg_match('/[bcdfghjklmnpqrstvwxzñ]{4,}/iu', $flat)) {
        return [false, 'Name looks invalid — please type your real name.'];
    }

    // 3. Vowel ratio sanity: fewer than 15% vowels in a 6+ char string is gibberish
    if (strlen($flat) >= 6) {
        $vowelCount = preg_match_all('/[aeiouyáéíóú]/iu', $flat);
        if ($vowelCount / strlen($flat) < 0.15) {
            return [false, 'Name looks invalid — please type your real name.'];
        }
    }

    // 4. Three+ same letters within a 4-char window (asdffs)
    if (preg_match('/([a-z])\1.*?\1/i', $flat)) {
        // tolerate natural names like "Anna" (double but not triple)
        if (preg_match('/(.)\1\1/u', $name)) {
            return [false, 'Name looks invalid — please type your real name.'];
        }
    }

    // 5. Placeholder names
    $norm = strtolower(preg_replace('/\s+/', ' ', $name));
    $normNoSpace = str_replace(' ', '', $norm);
    if (in_array($norm, _validationPlaceholders(), true) || in_array($normNoSpace, _validationPlaceholders(), true)) {
        return [false, 'Please enter a real name — placeholders like "test" are not allowed.'];
    }

    // 6. Known keyboard mashes as whole word
    $mash = ['asdf','asdfg','asdfgh','asdfghj','qwerty','qwertyu','qwertyui',
             'zxcvb','zxcvbn','hjkl','fghj','dfgh','sdfg','sdfgh','sdfghj',
             'hjklm','xcvbn','xcvbnm','ghjkl','bnm,','ghjk'];
    foreach (explode(' ', $norm) as $word) {
        if (in_array($word, $mash, true)) {
            return [false, 'Name looks invalid — please type your real name.'];
        }
    }

    return [true, ''];
}

/**
 * Wording validator for title / description / subject.
 * $minLen — minimum characters required.
 */
function validateWording($text, $minLen = 15, $fieldLabel = 'Description') {
    $text = trim((string)$text);
    if ($text === '') {
        return [false, $fieldLabel . ' is required.'];
    }
    if (mb_strlen($text) < $minLen) {
        return [false, $fieldLabel . ' is too short (minimum ' . $minLen . ' characters).'];
    }

    // 4+ same character in a row
    if (preg_match('/(.)\1{3,}/u', $text)) {
        return [false, $fieldLabel . ' contains repeated characters — please describe properly.'];
    }

    $norm = strtolower($text);
    $norm = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $norm);
    $norm = preg_replace('/\s+/', ' ', trim($norm));

    // Junk phrase as entire content
    $junk = _validationJunkTokens();
    $stripped = str_replace(' ', '', $norm);
    if (in_array($norm, $junk, true) || in_array($stripped, $junk, true)) {
        return [false, $fieldLabel . ' looks invalid — please describe your complaint.'];
    }

    // No vowels at all (whole text)
    if (!preg_match('/[aeiouáéíóú]/iu', $text)) {
        return [false, $fieldLabel . ' looks invalid — please describe your complaint.'];
    }

    // Same word repeated 4+ times in a row (bobo bobo bobo bobo)
    $tokens = explode(' ', $norm);
    if (count($tokens) >= 4) {
        $run = 1; $maxRun = 1; $prev = $tokens[0];
        for ($i = 1; $i < count($tokens); $i++) {
            if ($tokens[$i] === $prev) { $run++; $maxRun = max($maxRun, $run); }
            else { $run = 1; $prev = $tokens[$i]; }
        }
        if ($maxRun >= 4) {
            return [false, $fieldLabel . ' repeats the same word — please describe properly.'];
        }
    }

    // Profanity-only content
    $profanity = ['bobo','tanga','gago','gaga','tarantado','tarantada','putangina',
                  'putang ina','puta','pakyu','fuck','fuckyou','shit','bitch','bastard',
                  'asshole','dick','pussy','ulol','engot','inutil','lintik','hayop ka',
                  'leche','punyeta','peste','pokpok','burat','titi','puke','kantot',
                  'jakol','bilat','bayag','pekpek'];
    $contentWords = array_filter(explode(' ', $norm), fn($w) => $w !== '');
    if (count($contentWords) > 0) {
        $allProfane = true;
        foreach ($contentWords as $w) {
            $hit = false;
            foreach ($profanity as $p) {
                if (str_contains($w, $p) || $w === $p) { $hit = true; break; }
            }
            if (!$hit) { $allProfane = false; break; }
        }
        if ($allProfane) {
            return [false, $fieldLabel . ' contains only profanity — please describe your complaint.'];
        }
    }

    return [true, ''];
}

/* ============================================================
   KATARUNGANG PAMBARANGAY CLASSIFIER
   ------------------------------------------------------------
   Classifies a complaint into one of the KP-covered case types
   listed in the Katarungang Pambarangay Handbook
   (Annex: Cases Under Katarungang Pambarangay, pp. 22–23 / PDF 40–41).

   Returns one of the 27 valid `complaints.complaint_type` ENUM values:
     dispute, nuisance, boundary, domestic, property,
     publication_honor, public_order, documents_identity,
     physical_injury, suicide_related, unlawful_arrest,
     minor_family, abandonment_duty, property_rights,
     threats_coercion, labor_threats, privacy_secrets,
     theft_property, fraud_deceit, mortgaged_property,
     property_damage, sexual_offense, false_accusation,
     check_issuance, non_kp_referral, civil_dispute, unclassified
   ============================================================ */

function classifyComplaintType($description, $subject) {
    $desc = strtolower(' ' . (string)$description . ' ');
    $subj = strtolower(trim((string)$subject));

    $rules = [
        // Art. 154 — Unlawful Use of Means of Publication & Utterances
        'publication_honor' => [
            'libel','cyber libel','paninirang puri','paninira','masamang salita',
            'nagpost','nag-post','nagcomment','post sa facebook','post sa fb',
            'post sa social media','online libel','naglibel','nag-udyok',
            'unlawful utterance','unlawful publication',
        ],
        // Art. 155 — Alarms and Scandals
        'public_order' => [
            'iskandalo','nag-iskandalo','gulo','nagkagulo','sigaw','nagsisigaw',
            'videoke','karaoke','nagpapatugtog','maingay','ingay','alarms',
            'scandal','public disturbance','breach of peace','disturbing the peace',
        ],
        // Art. 175, 178, 179 — Documents / Identity
        'documents_identity' => [
            'pekeng dokumento','pekeng certificate','pekeng id','falsification',
            'forged','forgery','nagpeke','nagpeke ng','pekeng pangalan',
            'fictitious name','alias','concealing true name','pekeng uniporme',
            'pekeng badge','illegal use of uniform','illegal use of insignia',
        ],
        // Art. 252, 265, 266 — Physical Injuries
        'physical_injury' => [
            'sinaktan','sinasaktan','binugbog','binubugbog','sinuntok','sinusuntok',
            'sinipa','sinisipa','sinampal','sinasampal','pinalo','pinapalo',
            'tinulak','tinutulak','sinakal','sinasakal','sinabunutan','kinalmot',
            'kinagat','nasugatan','nasaktan','bugbog','sugat','pasa','namaga',
            'nagbugbugan','nag-rambol','nagrarambol','rumble','affray','brawl',
            'malubhang sugat','seryosong sugat','naospital dahil sa pananakit',
        ],
        // Art. 253 — Giving Assistance to Consummated Suicide
        'suicide_related' => [
            'pagpapakamatay','pagpapatiwakal','nagpakamatay','nagpatiwakal',
            'suicide','suicidal','tulong sa pagpapakamatay','nag-udyok magpakamatay',
        ],
        // Art. 269 — Unlawful Arrest
        'unlawful_arrest' => [
            'unlawful arrest','illegal arrest','illegal na pag-aresto','kinulong',
            'kinukulong','hinuli ng walang warrant','hinuli ng walang kaso',
            'detained','nakakulong','preso','walang warrant','walang kaso',
            'walang basehan','dinakip','dinadakip',
        ],
        // Art. 271, 276, 277, 338 — Minor / Family
        'minor_family' => [
            'bata','batang','menor','minor','sanggol','baby','anak',
            'inudyukan ang bata','batang tumakas','batang lumayas','naglayas',
            'iniwang bata','iniwang sanggol','pinabayaang bata','inabandonang bata',
            'swindling a minor','niloko ang bata','dinaya ang bata',
            'simple seduction','nag-akit','inakit','binuntis','nabuntis',
            'iniwan sa ere','iniwang buntis',
        ],
        // Art. 275, 277 — Abandonment of Person in Danger / Duty
        'abandonment_duty' => [
            'iniwan sa panganib','iniwan sa peligro','iniwan sa sakuna',
            'iniwan sa aksidente','pinabayaan sa panganib','inabandona',
            'hindi tinulungan','hindi sinaklolohan','walang pakialam sa panganib',
            'abandonment','inabandona ang','iniwan sa',
        ],
        // Art. 280, 281 — Trespass
        'property_rights' => [
            'pumasok sa bahay','pinasok ang bahay','pumasok nang walang pahintulot',
            'pumasok sa bakuran','pinasok ang bakuran','trespass','trespassing',
            'nagtrespass','pumasok sa lupa','pinasok ang lupa',
            'pumasok sa property','pinasok ang property',
            'sinakop ang lupa','sinakop ang property','kinuha ang lupa',
            'inasamang lupa','pagsakop ng lupa','occupation of real property',
            'usurpation','usurpation of real rights',
        ],
        // Art. 283, 285, 286, 287, 288, 289 — Threats & Coercion
        'threats_coercion' => [
            'banta','nagbanta','nagbabanta','binantaan','binabantaan','tinakot',
            'tinatakot','pananakot','pangamba','nagbantang sasaktan',
            'nagbantang papatayin','pinagbantaan','grave coercion','light coercion',
            'grave threats','light threats','pinilit','pinipilit','sapilitan',
            'tinutukan','tinutukan ng baril','tinutukan ng patalim',
            'pinilit magbayad','pinilit pumirma','compulsory',
        ],
        // Art. 290, 291 — Secrets / Privacy
        'privacy_secrets' => [
            'binuksan ang liham','binuksan ang sulat','binuksan ang email',
            'binuksan ang mail','ninakaw ang liham','binabasa ang liham',
            'discovering secrets','revealing secrets','abuse of authority',
            'isinumbong ang sikreto','isiniwalat ang sikreto','inihayag ang sekreto',
            'abuso ng kapangyarihan','nagsiwalat','nagsumbong',
        ],
        // Art. 309, 310, PD 1612 — Theft & Fencing
        'theft_property' => [
            'nakaw','nagnakaw','nanakaw','ninakaw','ninakawan','pagnanakaw',
            'magnanakaw','mandurukot','nadukutan','dinukot','dukot',
            'kinuha ang wallet','kinuha ang cellphone','kinuha ang bag',
            'kinuha ang pitaka','kinuha ang pera','akyat bahay','akyat-bahay',
            'theft','stealing','stolen','thief',
            'fencing','fence','stolen property','stolen goods','nakaw na gamit',
            'bumili ng nakaw','tumanggap ng nakaw','nagtago ng nakaw','kinupkop',
            'pd 1612','fencing of stolen',
        ],
        // Art. 312, 313 — Boundary / Real Rights
        'boundary' => [
            'hangganan','mojon','boundary','boundaries','bakod','fence',
            'binago ang hangganan','binago ang mojon','binago ang bakod',
            'inilipat ang mojon','inilipat ang bakod','altering boundaries',
            'altering landmarks','altering boundary',
        ],
        // Art. 315, 316, 317, 318 — Fraud / Swindling / Estafa
        'fraud_deceit' => [
            'estafa','nag-estafa','niloko','niloloko','nanloko','manloloko',
            'dinaya','dinadaya','nangdaya','mandaraya','daya','pandaraya',
            'panloloko','panlilinlang','naglinlang','nilinlang','swindling',
            'other deceits','other forms of swindling','niloko sa pera',
            'niloko sa negosyo','nag-estafa ng pera',
        ],
        // Art. 319 — Mortgaged Property
        'mortgaged_property' => [
            'sangla','sinangla','nakasanla','nagsangla','pagsangla',
            'mortgage','mortgaged','removal of mortgaged property',
            'sale of mortgaged property','pledge of mortgaged property',
            'inalis ang nakasanlang','ibinenta ang nakasanlang',
        ],
        // Art. 328, 329 — Malicious Mischief / Other Mischiefs
        'property_damage' => [
            'sinira','sinisira','nasira','paninira','nagsira','magsira',
            'binasag','binabasag','nagbasag','binaboy','ginulo',
            'sinunog','pagsunog','malicious mischief','mischief','damage',
            'destroy','vandalism','vandalized','winasak','nawasak','giniba',
            'pinutol','napunit','pinunit','tapon ng basura','nagtapon ng basura',
            'nagkakalat ng basura','binuhusan ng dumi',
        ],
        // Art. 338, 339 — Sexual Offenses
        'sexual_offense' => [
            'malaswa','malaswang','kalaswaan','hipo','hinipo','humipo','hipuin',
            'hinawakan','humawak','ginalaw','hinimas','sinipsip','dinilaan',
            'hinagkan','hinalikan','acts of lasciviousness','lascivious',
            'indecent','obscene','lewd','molestation','molested',
            'binastos','binabastos','nambabastos','pambabastos',
        ],
        // Art. 363 — Incriminating Innocent Persons
        'false_accusation' => [
            'bintang','nagbintang','binantaan','paratang','nagparatang',
            'pinagbintangan','pinagparatangan','sinungaling','nagsinungaling',
            'pagsisinungaling','gawa-gawa','gawa-gawang kwento','imbento',
            'nag-imbento','itinuro','itinuturo','incriminating innocent',
            'false accusation','false witness','false testimony',
        ],
        // BP 22 — Issuing Checks Without Sufficient Funds
        'check_issuance' => [
            'tumalbog','tumatalbog','bounced check','bouncing check','bad check',
            'dishonored check','insufficient funds','walang pondo','kulang ang pondo',
            'walang laman ang','tseke','cheke','check','cheque',
            'bp 22','issuing checks',
        ],
        // Civil / neighborhood disputes
        'civil_dispute' => [
            'utang','pautang','lending','hindi nagbabayad','hindi binabayaran',
            'singil','pagsingil','bayad','kontrata','kasunduan','neighbor dispute',
            'kapitbahay','alitan','hidwaan',
        ],
        // Non-KP: RA 9165 / PD 1602 / Labor / Gov / High-penalty offenses
        'non_kp_referral' => [
            'droga','drugs','shabu','marijuana','drug pusher','drug den',
            'jueteng','tupada','sabong','cockfighting','illegal gambling',
            'labor dispute','dole','nlrc','hindi sumasahod','unpaid wages',
            'rape','ginahasa','murder','homicide','kidnapping','carnapping',
            'robbery with violence','terrorism','arson',
        ],
    ];

    // Score
    $scores = [];
    foreach ($rules as $type => $keywords) {
        $score = 0;
        foreach ($keywords as $kw) {
            if (strpos($desc, $kw) !== false) {
                $score += (strpos($kw, ' ') !== false) ? 3 : 1;
            }
        }
        if ($score > 0) $scores[$type] = $score;
    }

    // Subject hint
    $subjectHint = [
        'physical altercation'          => 'physical_injury',
        'threat/intimidation'           => 'threats_coercion',
        'violence against women (vawc)' => 'domestic',
        'child protection concern'      => 'minor_family',
        'drug related issue'            => 'non_kp_referral',
        'theft/robbery'                 => 'theft_property',
        'illegal gambling'              => 'non_kp_referral',
        'illegal structure'             => 'property_rights',
        'noise complaint'               => 'public_order',
        'waste management'              => 'property_damage',
        'scandal'                       => 'public_order',
        'cyber libel'                   => 'publication_honor',
        'verbal abuse'                  => 'publication_honor',
        'property/boundary issue'       => 'boundary',
        'neighbor dispute'              => 'civil_dispute',
        'lending/utang issue'           => 'civil_dispute',
        'abandoned vehicle'             => 'nuisance',
        'obstruction on public way'     => 'public_order',
    ];
    if (isset($subjectHint[$subj])) {
        $hinted = $subjectHint[$subj];
        $scores[$hinted] = ($scores[$hinted] ?? 0) + 4;
    }

    if (empty($scores)) return 'unclassified';
    arsort($scores);
    return array_key_first($scores);
}

/* ============================================================
   DUPLICATE DETECTION — 4-LAYER MODEL
   ============================================================
   Layer 1: normalize text (case, accents, punctuation, spaces, common shorthands)
   Layer 2: compare incident details (date, location, subject, description, respondent)
   Layer 3: search window = 30 days
   Layer 4: caller decides — warn, don't block
   ============================================================ */

/** Layer 1 — Normalize any text for comparison. */
function _norm($s) {
    $s = (string)$s;
    $s = mb_strtolower($s, 'UTF-8');
    // Strip accents (é→e, ñ→n, etc.) so "peke"/"péke" compare equal
    if (function_exists('iconv')) {
        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($conv !== false) $s = $conv;
    }
    // Unify common shorthand
    $s = str_replace(
        [' nang ', ' ng ', ' sa ', ' po ', ' pla ', ' lng ', ' lang ', ' ksi ', ' kc ', ' bkit ', ' tpos '],
        [' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' '],
        ' ' . $s . ' '
    );
    // Remove everything that isn't a letter, number, or space
    $s = preg_replace('/[^a-z0-9\s]/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

/** Build a "skeleton" string for coarse comparison (first 100 chars). */
function _normHead($s, $len = 100) {
    $n = _norm($s);
    return mb_substr($n, 0, $len);
}

/**
 * Layer 2 — Load incident details for a complaint.
 * Returns ['dates'=>[], 'locations'=>[], 'descriptions'=>[]] all normalized.
 */
function _loadIncidentFingerprints($conn, $complaint_id) {
    $out = ['dates' => [], 'locations' => [], 'descriptions' => []];
    $s = mysqli_prepare($conn,
        "SELECT incident_date, location, description
           FROM complaint_incidents
          WHERE complaint_id = ?
          ORDER BY id");
    if (!$s) return $out;
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    while ($row = mysqli_fetch_assoc($r)) {
        if (!empty($row['incident_date'])) $out['dates'][]       = $row['incident_date'];
        if (!empty($row['location']))      $out['locations'][]   = _norm($row['location']);
        if (!empty($row['description']))   $out['descriptions'][]= _normHead($row['description'], 120);
    }
    mysqli_stmt_close($s);
    return $out;
}

/**
 * Layer 2 — Collect respondent names for a complaint (normalized, sorted).
 */
function _loadRespondentNames($conn, $complaint_id) {
    $out = [];
    $s = mysqli_prepare($conn,
        "SELECT full_name FROM complaint_parties
          WHERE complaint_id = ? AND party_type = 'respondent'");
    if (!$s) return $out;
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    while ($row = mysqli_fetch_assoc($r)) {
        $n = _norm($row['full_name']);
        if ($n !== '') $out[] = $n;
    }
    mysqli_stmt_close($s);
    sort($out);
    return $out;
}
/**
 * Layer 2 — Structured fingerprint match.
 *
 * Returns:
 *   [
 *     'score'            => int 0..100,
 *     'same_respondents' => bool,
 *     'same_incident'    => bool,   // date + location + description ALL match
 *     'same_date'        => bool,
 *     'same_location'    => bool,
 *     'same_description' => bool,
 *   ]
 */
function _fingerprintOverlap($a, $b) {
    $out = [
        'score'            => 0,
        'same_respondents' => false,
        'same_incident'    => false,
        'same_date'        => false,
        'same_location'    => false,
        'same_description' => false,
    ];

    // Same incident DATE → +30
    if (!empty($a['dates']) && !empty($b['dates'])) {
        if (array_intersect($a['dates'], $b['dates'])) {
            $out['same_date'] = true;
            $out['score'] += 30;
        }
    }

    // Same normalized incident LOCATION → +25
    if (!empty($a['locations']) && !empty($b['locations'])) {
        foreach ($a['locations'] as $la) {
            foreach ($b['locations'] as $lb) {
                if ($la === $lb) {
                    $out['same_location'] = true;
                    $out['score'] += 25;
                    break 2;
                }
            }
        }
    }

    // Similar incident DESCRIPTION (shared first 60 chars) → +25
    if (!empty($a['descriptions']) && !empty($b['descriptions'])) {
        foreach ($a['descriptions'] as $da) {
            foreach ($b['descriptions'] as $db) {
                $ha = mb_substr($da, 0, 60);
                $hb = mb_substr($db, 0, 60);
                if ($ha !== '' && $hb !== ''
                    && ($ha === $hb
                        || strpos($da, $hb) !== false
                        || strpos($db, $ha) !== false)) {
                    $out['same_description'] = true;
                    $out['score'] += 25;
                    break 2;
                }
            }
        }
    }

    // Same RESPONDENT name → +20
    if (!empty($a['respondents']) && !empty($b['respondents'])) {
        if (array_intersect($a['respondents'], $b['respondents'])) {
            $out['same_respondents'] = true;
            $out['score'] += 20;
        }
    }

    $out['score'] = min(100, $out['score']);

    // "Same incident" = all three incident fields align
    $out['same_incident'] = $out['same_date']
                         && $out['same_location']
                         && $out['same_description'];

    return $out;
}


function findPossibleDuplicate($conn, $resident_id, $title, $description, $subject,
                               $incidents = [], $respondents = []) {
    // Skip if there's nothing meaningful to compare
    $titleNorm = _norm($title);
    $descNorm  = _norm($description);
    $subjNorm  = _norm($subject);
    if ($titleNorm === '' && $descNorm === '') return null;

    $titleHead = mb_substr($titleNorm, 0, 80);
    $descHead  = mb_substr($descNorm, 0, 140);

    // ── Layer 3: 30-day window ──
    $sql = "SELECT id, reference_number, status, title, description,
                   complaint_subject, created_at
              FROM complaints
             WHERE created_by = ?
               AND created_at >= NOW() - INTERVAL 30 DAY
             ORDER BY created_at DESC
             LIMIT 30";
    $s = mysqli_prepare($conn, $sql);
    if (!$s) return null;
    mysqli_stmt_bind_param($s, "i", $resident_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $rows = [];
    while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    mysqli_stmt_close($s);

    if (!$rows) return null;

    // Build fingerprints of the INCOMING complaint once
    $incomingFp = [
        'dates'        => [],
        'locations'    => [],
        'descriptions' => [],
        'respondents'  => [],
    ];
    foreach ($incidents as $inc) {
        if (!empty($inc['incident_date'])) $incomingFp['dates'][]       = $inc['incident_date'];
        if (!empty($inc['location']))      $incomingFp['locations'][]   = _norm($inc['location']);
        if (!empty($inc['description']))   $incomingFp['descriptions'][]= _normHead($inc['description'], 120);
    }
    foreach ($respondents as $p) {
        $n = _norm($p['full_name'] ?? '');
        if ($n !== '') $incomingFp['respondents'][] = $n;
    }
    sort($incomingFp['respondents']);

    $best = null;
        foreach ($rows as $cand) {
        $reason     = [];
        $confidence = 0;

        $candTitleNorm = _norm($cand['title']);
        $candDescNorm  = _norm($cand['description']);
        $candSubjNorm  = _norm($cand['complaint_subject']);

        // Subject must match if both sides have one
        if ($subjNorm !== '' && $candSubjNorm !== '' && $subjNorm !== $candSubjNorm) {
            continue;
        }

        // ── Title comparison ──
        $same_title = false;
        if ($titleNorm !== '' && $candTitleNorm === $titleNorm) {
            $same_title = true;
            $confidence = max($confidence, 85);
            $reason[] = 'Same title';
        } elseif ($titleHead !== '' && strlen($candTitleNorm) >= 20) {
            if (strpos($candTitleNorm, $titleHead) !== false
             || strpos($titleNorm, mb_substr($candTitleNorm, 0, 80)) !== false) {
                $confidence = max($confidence, 70);
                $reason[] = 'Similar title';
            }
        }

        // ── Overall description comparison ──
        if ($descHead !== '' && strlen($candDescNorm) >= 60
            && strpos($candDescNorm, $descHead) !== false) {
            $confidence = max($confidence, 80);
            $reason[] = 'Similar description';
        }

        // ── Incident + respondent fingerprint comparison ──
        $candFp = _loadIncidentFingerprints($conn, (int)$cand['id']);
        $candFp['respondents'] = _loadRespondentNames($conn, (int)$cand['id']);

        $fp = _fingerprintOverlap($incomingFp, $candFp);

        if ($fp['score'] > 0) {
            $confidence = max($confidence, (int)round(($fp['score'] + 50) / 1.5));
        }
        if ($fp['same_date'])         $reason[] = 'Same incident date';
        if ($fp['same_location'])     $reason[] = 'Same incident location';
        if ($fp['same_description'])  $reason[] = 'Similar incident description';
        if ($fp['same_respondents'])  $reason[] = 'Same respondent';

        // Final gate
        if ($confidence < 55 || empty($reason)) continue;

        // ── PERFECT DUPLICATE ──
        // Same title + same respondent + same incident (date+location+desc)
        // + existing complaint still pending_review.
        $isPerfect = $same_title
                  && $fp['same_respondents']
                  && $fp['same_incident']
                  && ($cand['status'] === 'pending_review');

        if ($isPerfect) {
            $confidence = 100;
        }

        if (!$best || $confidence > $best['confidence']) {
            $best = [
                'id'               => (int)$cand['id'],
                'reference_number' => $cand['reference_number'],
                'status'           => $cand['status'],
                'title'            => $cand['title'],
                'created_at'       => $cand['created_at'],
                'reason'           => implode(', ', array_unique($reason)),
                'confidence'       => (int)$confidence,
                'is_blocking'      => $isPerfect,   // ← decided right here
                'same_title'       => $same_title,
                'same_respondents' => $fp['same_respondents'],
                'same_incident'    => $fp['same_incident'],
                'candidate_status' => $cand['status'],
            ];
        }
    }

   

    return $best;
}

/**
 * Rate limit. Returns ['hard' => bool, 'soft' => bool, 'msg' => string].
 *  hard: 3+ in last 24h
 *  soft: 5+ in last 7d
 */
/**
 * Rate limit: max 5 complaints in the last 10 minutes.
 * Returns ['hard' => bool, 'soft' => bool, 'msg' => string].
 */
function checkResidentRateLimit($conn, $resident_id) {
    $s = mysqli_prepare($conn,
        "SELECT COUNT(*) AS n
           FROM complaints
          WHERE created_by = ?
            AND created_at >= NOW() - INTERVAL 10 MINUTE");
    mysqli_stmt_bind_param($s, "i", $resident_id);
    mysqli_stmt_execute($s);
    $n = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($s))['n'] ?? 0);
    mysqli_stmt_close($s);

    if ($n >= 5) {
        return [
            'hard' => true,
            'soft' => false,
            'msg'  => 'You have already filed 5 complaints in the last 10 minutes. Please wait a few minutes before filing another.'
        ];
    }
    if ($n >= 3) {
        // soft warning between 3 and 4 in 10 min
        return [
            'hard' => false,
            'soft' => true,
            'msg'  => "You have filed {$n} complaints in the last 10 minutes. Are you sure you want to file another?"
        ];
    }
    return ['hard' => false, 'soft' => false, 'msg' => ''];
}

/** Check every incident has at least one uploaded evidence file. */
function checkIncidentsHaveEvidence($incidentCount, $files) {
    // $files = $_FILES
    for ($i = 0; $i < $incidentCount; $i++) {
        $key = 'incident_evidence_' . $i;
        $has = false;
        if (isset($files[$key]) && is_array($files[$key]['name'])) {
            foreach ($files[$key]['error'] as $err) {
                if ((int)$err === UPLOAD_ERR_OK) { $has = true; break; }
            }
        }
        if (!$has) {
            return [false, "Incident " . ($i + 1) . " needs at least one evidence file."];
        }
    }
    return [true, ''];
}

/* ============================================================
   AUTO-PRIORITY ENGINE (server-side, authoritative)
   ============================================================ */

/**
 * Detect whether a VULNERABLE person is the one harmed.
 * Returns true only if the description contains a vulnerable-victim
 * pattern AND an injury/harm verb nearby.
 * Supports Tagalog, English, and mixed (Taglish) descriptions.
 */
function _vulnerableVictimHarmed($desc) {
    $desc = strtolower($desc);

    $vuln = [
        // ── Tagalog ──
        'bata','batang','anak','sanggol','menor','minor','musmos','paslit',
        'estudyante','baby','newborn',
        'matanda','lolo','lola','senior','elderly','gurang',
        'babae','asawa','nanay','ina','ate','dalaga','dalagita',
        'pwd','may kapansanan','disabled','wheelchair','bulag','bingi','pilay',
        // ── English ──
        'child','kid','kids','children','minor','minors','baby','infant',
        'toddler','son','daughter','student','pupil',
        'elderly','old man','old woman','senior citizen','grandma','grandpa',
        'grandmother','grandfather',
        'woman','women','wife','mother','sister','girlfriend','partner','spouse',
        'pwd','disabled','handicapped','wheelchair-bound','blind','deaf','mute','lame'
    ];

    $harm = [
        // ── Tagalog ──
        'sinaktan','sinasaktan','binugbog','binubugbog','sinuntok','sinusuntok',
        'sinipa','sinisipa','sinampal','sinasampal','pinalo','pinapalo',
        'tinulak','tinutulak','sinakal','sinasakal','tinali','tinatali',
        'sinabunutan','sinasabunutan','kinalmot','kinakalmot','kinagat','kinakagat',
        'nasugatan','nasusugatan','nasaktan','nasasaktan',
        'pinagbantaan','pinagbabantaan','tinakot','tinatakot',
        'pinilit','pinipilit','sinapilitan','sinasapilitan',
        'iniwan','pinabayaan','pinapabayaan','inabandona','iniiwan',
        'pinagmamalupitan','namalupit','nambubugbog','nangmamalupit',
        'rape','ginahasa','gahasa','nirape',
        'inabuso','binabastos','nambabastos',
        'pinasok ang ari','pinagsamantalahan','pinilit kahit ayaw',
        'hindi pinapakain','hindi pinapaaral','hindi inaalagaan',
        // ── English ──
        'punched','punching','hit','hitting','slapped','slapping','kicked','kicking',
        'beaten','beat','beating','choked','choking','strangled','strangling',
        'pushed','pushing','grabbed','grabbing','dragged','dragging',
        'wounded','wounding','injured','injuring','hurt','hurting',
        'threatened','threatening','threat','threats',
        'forced','forcing','coerced','coercing','coercion',
        'abused','abusing','abuse','molested','molesting','harassed','harassing',
        'raped','raping','sexual assault','sexual abuse',
        'neglected','neglecting','neglect','abandoned','abandoning',
        'starved','starving','denied food','denied care','not fed','not cared for',
        'bullied','bullying','harassment',
        'shoved','shoving','assaulted','assaulting','assault',
        'attacked','attacking','struck','striking','suffocated','suffocating',
        'verbally abused','physically abused','emotionally abused'
    ];

    foreach ($vuln as $v) {
        if (strpos($desc, $v) === false) continue;
        foreach ($harm as $h) {
            if (strpos($desc, $h) !== false) return true;
        }
    }
    return false;
}

/** Auto-classify priority. */
function classifyAutoPriority($description, $subject, $is_escalated) {
    $desc = strtolower((string)$description);
    $subj = strtolower(trim((string)$subject));

    // 1. Escalated always wins → urgent
    if ($is_escalated) return 'urgent';

    // 2. Vulnerable victim harmed → high
    if (_vulnerableVictimHarmed($desc)) return 'high';

    // 3. Subject-based high
    $highSubjects = [
        'physical altercation','threat/intimidation','violence against women (vawc)',
        'child protection concern','drug related issue'
    ];
    foreach ($highSubjects as $k) { if ($subj === $k) return 'high'; }

    // 4. Subject-based medium
    $medSubjects = ['theft/robbery','illegal gambling','illegal structure'];
    foreach ($medSubjects as $k) { if ($subj === $k) return 'medium'; }

    // 5. Nuisance-level → low
    $lowSubjects = [
        'abandoned vehicle','obstruction on public way','noise complaint',
        'waste management','water supply issue','electrical problem',
        'animal complaint'
    ];
    foreach ($lowSubjects as $k) { if ($subj === $k) return 'low'; }

    // 6. Default
    return 'medium';
}

/* ============================================================
   RESIDENT: Submit complaint
   ============================================================ */
if ($action === 'submit_complaint' && $user_type === 'resident') {

    $title              = trim($_POST['title'] ?? '');
    $description        = trim($_POST['description'] ?? '');
    $complaint_subject  = trim($_POST['complaint_subject'] ?? 'Other Concern');
// Priority is ALWAYS server-computed — never trust the client value
$priority = 'medium';
    $additional_notes   = trim($_POST['additional_notes'] ?? '');
    $escalation_type     = trim($_POST['escalation_type'] ?? '');
    $escalation_refer_to = trim($_POST['escalation_refer_to'] ?? '');
    $escalation_note     = trim($_POST['escalation_note'] ?? '');
    $is_escalated        = $escalation_type !== '' ? 1 : 0;
    $is_anonymous        = isset($_POST['is_anonymous']) ? intval($_POST['is_anonymous']) : 0;

    $complainants = json_decode($_POST['complainants'] ?? '[]', true) ?: [];
    $respondents  = json_decode($_POST['respondents']  ?? '[]', true) ?: [];
    $witnesses    = json_decode($_POST['witnesses']    ?? '[]', true) ?: [];
    $incidents    = json_decode($_POST['incidents']    ?? '[]', true) ?: [];

    /* ---------- VALIDATION GATE ---------- */

    // 1. Subject, Title, Description
    if ($complaint_subject === '') {
        echo json_encode(['success'=>false,'error_field'=>'complaintSubject','message'=>'Please select a complaint subject.']);
        exit;
    }
    list($okTitle, $msgTitle) = validateWording($title, 5, 'Title');
    if (!$okTitle) { echo json_encode(['success'=>false,'error_field'=>'complaintTitle','message'=>$msgTitle]); exit; }

    list($okDesc, $msgDesc) = validateWording($description, 15, 'Description');
    if (!$okDesc) { echo json_encode(['success'=>false,'error_field'=>'complaintDescription','message'=>$msgDesc]); exit; }

    // 2. Complainants — full name (valid), contact (09XXXXXXXXX), address required, email optional
    if (empty($complainants)) {
        echo json_encode(['success'=>false,'error_field'=>'complainants','message'=>'At least one complainant is required.']);
        exit;
    }
    foreach ($complainants as $i => $p) {
        $name    = trim($p['full_name'] ?? '');
        $contact = trim($p['contact_number'] ?? '');
        $address = trim($p['address'] ?? '');
        $email   = trim($p['email'] ?? '');

        list($ok, $msg) = validatePersonName($name);
        if (!$ok) {
            echo json_encode(['success'=>false,'error_field'=>"complainant_name_$i",'message'=>"Complainant " . ($i+1) . ": $msg"]);
            exit;
        }
        if (!validateContactNumber($contact)) {
            echo json_encode(['success'=>false,'error_field'=>"complainant_contact_$i",'message'=>"Complainant " . ($i+1) . ": Contact must be 11 digits starting with 09."]);
            exit;
        }
        if ($address === '') {
            echo json_encode(['success'=>false,'error_field'=>"complainant_address_$i",'message'=>"Complainant " . ($i+1) . ": Address is required."]);
            exit;
        }
        if (!validateEmailOrEmpty($email)) {
            echo json_encode(['success'=>false,'error_field'=>"complainant_email_$i",'message'=>"Complainant " . ($i+1) . ": Invalid email."]);
            exit;
        }
    }

    // 3. Respondents — full name only required; contact if typed must follow rule
    foreach ($respondents as $i => $p) {
        $name    = trim($p['full_name'] ?? '');
        $contact = trim($p['contact_number'] ?? '');

        if ($name === '') {
            echo json_encode(['success'=>false,'error_field'=>"respondent_name_$i",'message'=>"Respondent " . ($i+1) . ": Full name is required."]);
            exit;
        }
        list($ok, $msg) = validatePersonName($name);
        if (!$ok) {
            echo json_encode(['success'=>false,'error_field'=>"respondent_name_$i",'message'=>"Respondent " . ($i+1) . ": $msg"]);
            exit;
        }
        if ($contact !== '' && !validateContactNumber($contact)) {
            echo json_encode(['success'=>false,'error_field'=>"respondent_contact_$i",'message'=>"Respondent " . ($i+1) . ": Contact must be 11 digits starting with 09."]);
            exit;
        }
    }

    // 4. Incidents — date, location, description required
    if (empty($incidents)) {
        echo json_encode(['success'=>false,'error_field'=>'incidents','message'=>'At least one incident is required.']);
        exit;
    }
    foreach ($incidents as $i => $inc) {
        $date = trim($inc['incident_date'] ?? '');
        $loc  = trim($inc['location'] ?? '');
        $desc = trim($inc['description'] ?? '');
        if ($date === '') {
            echo json_encode(['success'=>false,'error_field'=>"incident_date_$i",'message'=>"Incident " . ($i+1) . ": Date is required."]);
            exit;
        }
        if ($loc === '') {
            echo json_encode(['success'=>false,'error_field'=>"incident_location_$i",'message'=>"Incident " . ($i+1) . ": Location is required."]);
            exit;
        }
        list($ok, $msg) = validateWording($desc, 10, "Incident " . ($i+1) . " description");
        if (!$ok) {
            echo json_encode(['success'=>false,'error_field'=>"incident_description_$i",'message'=>$msg]);
            exit;
        }
    }

    // 5. Each incident must have at least one uploaded file
    list($okFiles, $msgFiles) = checkIncidentsHaveEvidence(count($incidents), $_FILES);
    if (!$okFiles) {
        echo json_encode(['success'=>false,'error_field'=>'incidents_evidence','message'=>$msgFiles]);
        exit;
    }

    // 6. Rate limit
    $rl = checkResidentRateLimit($conn, $user_id);
    if (!empty($rl['hard'])) {
        echo json_encode(['success'=>false,'error_field'=>'rate_limit','message'=>$rl['msg']]);
        exit;
    }

    $possible_duplicate = null;
    $force_duplicate    = isset($_POST['force_duplicate']) && $_POST['force_duplicate'] === '1';

    if (!$is_escalated && !$force_duplicate) {
        $possible_duplicate = findPossibleDuplicate(
            $conn, $user_id,
            $title, $description, $complaint_subject,
            $incidents, $respondents
        );

        if ($possible_duplicate) {
            // ── BLOCKING duplicate: reject outright, no override ──
            if (!empty($possible_duplicate['is_blocking'])) {
                echo json_encode([
                    'success'            => false,
                    'code'               => 'duplicate_blocked',
                    'message'            => 'This complaint is an exact duplicate of an existing one. Please review your existing complaint instead of filing a new one.',
                    'possible_duplicate' => $possible_duplicate,
                ]);
                exit;
            }

            // ── WARNING duplicate: let the resident decide ──
            echo json_encode([
                'success'            => false,
                'code'               => 'possible_duplicate',
                'message'            => 'A similar complaint already exists.',
                'possible_duplicate' => $possible_duplicate,
            ]);
            exit;
        }
    }
/* ---------- AUTO-PRIORITY (authoritative) ---------- */
$priority = classifyAutoPriority($description, $complaint_subject, $is_escalated === 1);
    /* ---------- INSERT ---------- */

    $title              = mysqli_real_escape_string($conn, $title);
    $description        = mysqli_real_escape_string($conn, $description);
    $complaint_subject  = mysqli_real_escape_string($conn, $complaint_subject);
    $priority           = mysqli_real_escape_string($conn, $priority);
    $additional_notes   = mysqli_real_escape_string($conn, $additional_notes);
    $escalation_type    = mysqli_real_escape_string($conn, $escalation_type);
    $escalation_refer_to= mysqli_real_escape_string($conn, $escalation_refer_to);
    $escalation_note    = mysqli_real_escape_string($conn, $escalation_note);

    /* ============================================================
       AUTO-CLASSIFY complaint_type FROM THE KP HANDBOOK
       ------------------------------------------------------------
       The subject dropdown is only a hint. The actual complaint_type
       is derived from the description using the Katarungang Pambarangay
       Handbook's list of cases (pp. 22–23 / PDF 40–41).
       ============================================================ */
    $complaint_type = classifyComplaintType($description, $complaint_subject);

    // Safety net — guarantee a valid ENUM value
    $validTypes = [
        'dispute','nuisance','boundary','domestic','property',
        'publication_honor','public_order','documents_identity',
        'physical_injury','suicide_related','unlawful_arrest',
        'minor_family','abandonment_duty','property_rights',
        'threats_coercion','labor_threats','privacy_secrets',
        'theft_property','fraud_deceit','mortgaged_property',
        'property_damage','sexual_offense','false_accusation',
        'check_issuance','non_kp_referral','civil_dispute','unclassified',
    ];
    if (!in_array($complaint_type, $validTypes, true)) {
        $complaint_type = 'unclassified';
    }

    $reference_number = generateReferenceNumber();
    $has_evidence = 0;
    foreach ($_FILES as $k => $f) {
        if (strpos($k, 'incident_evidence_') === 0) { $has_evidence = 1; break; }
    }
    $initial_status = $is_escalated ? 'escalated' : 'pending_review';

    $sql = "INSERT INTO complaints
            (reference_number, title, description, complaint_type, complaint_subject,
             status, priority, created_by, has_evidence, is_anonymous,
             escalation_type, escalation_refer_to, escalation_notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "sssssssiiisss",
        $reference_number, $title, $description, $complaint_type, $complaint_subject,
        $initial_status, $priority, $user_id, $has_evidence, $is_anonymous,
        $escalation_type, $escalation_refer_to, $escalation_note);

    if (!mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => false, 'message' => 'Failed to submit: ' . mysqli_error($conn)]);
        exit;
    }
    $complaint_id = mysqli_insert_id($conn);

    // Complainants
    foreach ($complainants as $p) {
        if (empty($p['full_name'])) continue;
        $name    = mysqli_real_escape_string($conn, $p['full_name']);
        $addr    = mysqli_real_escape_string($conn, $p['address']        ?? '');
        $contact = mysqli_real_escape_string($conn, $p['contact_number'] ?? '');
        $email   = mysqli_real_escape_string($conn, $p['email']          ?? '');

        $matched = null;
        if ($email !== '') {
            $ms = mysqli_prepare($conn, "SELECT id FROM resident WHERE LOWER(email) = LOWER(?) AND is_active = 1 LIMIT 1");
            mysqli_stmt_bind_param($ms, "s", $email);
            mysqli_stmt_execute($ms);
            $matched = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($ms))['id'] ?? 0);
            mysqli_stmt_close($ms);
        }
        if (!$matched && $contact !== '') {
            $ms = mysqli_prepare($conn, "SELECT id FROM resident WHERE (phone=? OR phone_number=?) AND is_active=1 LIMIT 1");
            mysqli_stmt_bind_param($ms, "ss", $contact, $contact);
            mysqli_stmt_execute($ms);
            $matched = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($ms))['id'] ?? 0);
            mysqli_stmt_close($ms);
        }

        $s = mysqli_prepare($conn, "INSERT INTO complaint_parties
                (complaint_id, party_type, full_name, address, contact_number, email, matched_resident_id)
                VALUES (?, 'complainant', ?, ?, ?, ?, ?)");
        $matchedVal = $matched ?: null;
        mysqli_stmt_bind_param($s, "issssi", $complaint_id, $name, $addr, $contact, $email, $matchedVal);
        mysqli_stmt_execute($s);
    }

    // Respondents
    foreach ($respondents as $p) {
        if (empty($p['full_name'])) continue;
        $name    = mysqli_real_escape_string($conn, $p['full_name']);
        $addr    = mysqli_real_escape_string($conn, $p['address']        ?? '');
        $contact = mysqli_real_escape_string($conn, $p['contact_number'] ?? '');
        $rel     = mysqli_real_escape_string($conn, $p['relationship']   ?? '');

        $matched = null;
        if ($contact !== '') {
            $ms = mysqli_prepare($conn, "SELECT id FROM resident WHERE (phone=? OR phone_number=?) AND is_active=1 LIMIT 1");
            mysqli_stmt_bind_param($ms, "ss", $contact, $contact);
            mysqli_stmt_execute($ms);
            $matched = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($ms))['id'] ?? 0);
            mysqli_stmt_close($ms);
        }
        if (!$matched && $name !== '') {
            $parts = preg_split('/\s+/', trim($name));
            if (count($parts) >= 2) {
                $first = $parts[0]; $last = end($parts);
                $ms = mysqli_prepare($conn,
                    "SELECT id FROM resident WHERE LOWER(first_name)=LOWER(?) AND LOWER(last_name)=LOWER(?) AND is_active=1 LIMIT 1");
                mysqli_stmt_bind_param($ms, "ss", $first, $last);
                mysqli_stmt_execute($ms);
                $matched = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($ms))['id'] ?? 0);
                mysqli_stmt_close($ms);
            }
        }

        $s = mysqli_prepare($conn, "INSERT INTO complaint_parties
                (complaint_id, party_type, full_name, address, contact_number, relationship_to_complainant, matched_resident_id)
                VALUES (?, 'respondent', ?, ?, ?, ?, ?)");
        $matchedVal = $matched ?: null;
        mysqli_stmt_bind_param($s, "issssi", $complaint_id, $name, $addr, $contact, $rel, $matchedVal);
        mysqli_stmt_execute($s);
    }

    // Witnesses
    foreach ($witnesses as $p) {
        if (empty($p['full_name'])) continue;
        $name    = mysqli_real_escape_string($conn, $p['full_name']);
        $addr    = mysqli_real_escape_string($conn, $p['address']        ?? '');
        $contact = mysqli_real_escape_string($conn, $p['contact_number'] ?? '');
        $s = mysqli_prepare($conn, "INSERT INTO complaint_parties
                (complaint_id, party_type, full_name, address, contact_number)
                VALUES (?, 'witness', ?, ?, ?)");
        mysqli_stmt_bind_param($s, "isss", $complaint_id, $name, $addr, $contact);
        mysqli_stmt_execute($s);
    }

    // Incidents
    foreach ($incidents as $inc) {
        $date = !empty($inc['incident_date']) ? $inc['incident_date'] : null;
        $time = !empty($inc['incident_time']) ? $inc['incident_time'] : null;
        $loc  = mysqli_real_escape_string($conn, $inc['location']    ?? '');
        $desc = mysqli_real_escape_string($conn, $inc['description'] ?? '');
        $s = mysqli_prepare($conn, "INSERT INTO complaint_incidents
                (complaint_id, incident_date, incident_time, location, description)
                VALUES (?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($s, "issss", $complaint_id, $date, $time, $loc, $desc);
        mysqli_stmt_execute($s);
    }

    // Evidence
    $upload_dir = __DIR__ . '/../uploads/complaint_evidence/';
    if (!file_exists($upload_dir)) @mkdir($upload_dir, 0777, true);

    foreach ($_FILES as $key => $files) {
        if (strpos($key, 'incident_evidence_') !== 0) continue;
        if (!is_array($files['name'])) continue;
        $idx = (int) filter_var($key, FILTER_SANITIZE_NUMBER_INT);
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
            $orig = $files['name'][$i];
            $safe = time() . '_' . $idx . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $orig);
            $rel  = 'uploads/complaint_evidence/' . $safe;
            if (move_uploaded_file($files['tmp_name'][$i], $upload_dir . $safe)) {
                $origEsc = mysqli_real_escape_string($conn, $orig);
                $type    = $files['type'][$i];
                $desc    = 'Evidence for Incident ' . ($idx + 1);
                $s = mysqli_prepare($conn, "INSERT INTO complaint_evidence
                        (complaint_id, file_name, file_path, file_type, description, uploaded_by)
                        VALUES (?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($s, "issssi",
                    $complaint_id, $origEsc, $rel, $type, $desc, $user_id);
                mysqli_stmt_execute($s);
            }
        }
    }

    // Timeline
    if ($is_escalated) {
        $note = 'ESCALATED CASE — NOT under Katarungang Pambarangay. ' . $escalation_note;
    } else {
        $note = 'Complaint submitted and pending review';
        if ($additional_notes) $note .= '. Notes: ' . $additional_notes;
    }
    if ($is_anonymous === 1) $note .= ' [Filed Anonymously]';

    $s = mysqli_prepare($conn, "INSERT INTO complaint_updates
            (complaint_id, update_type, notes, updated_by, updated_by_role)
            VALUES (?, 'status_change', ?, NULL, 'resident')");
    mysqli_stmt_bind_param($s, "is", $complaint_id, $note);
    mysqli_stmt_execute($s);

    // Notify secretaries
    if ($is_escalated) {
        $notifTitle = 'ESCALATED Case Filed';
        $notifMsg   = "A non-jurisdictional case (#{$reference_number}) was filed — Type: {$escalation_type}. Refer to: {$escalation_refer_to}. Prepare Referral Letter. NO MEDIATION.";
        $notifType  = 'error';
    } else {
        $notifTitle = 'New Complaint Filed';
        $notifMsg   = "A new complaint (#{$reference_number}) was filed and is pending your review.";
        $notifType  = 'warning';
    }
    if ($is_anonymous === 1) $notifTitle .= ' [ANONYMOUS]';

    $secQ = mysqli_query($conn, "SELECT id FROM admin WHERE admin_role = 'secretary' AND is_active = 1");
    if ($secQ) {
        while ($sec = mysqli_fetch_assoc($secQ)) {
            $sec_id = (int)$sec['id'];
            $nSql = "INSERT INTO notifications
                        (user_type, user_id, resident_id, type, title, message, reference_id, reference_type)
                     VALUES ('admin', ?, NULL, ?, ?, ?, ?, 'complaint')";
            $nStmt = @mysqli_prepare($conn, $nSql);
            if ($nStmt) {
                mysqli_stmt_bind_param($nStmt, "isssi",
                    $sec_id, $notifType, $notifTitle, $notifMsg, $complaint_id);
                mysqli_stmt_execute($nStmt);
                mysqli_stmt_close($nStmt);
            }
            if (function_exists('pushToUser')) {
                pushToUser($conn, $sec_id, $notifTitle, $notifMsg,
                    '/BRITE/admin/dashboards/secretary_dashboard.php');
            }
        }
    }

    echo json_encode([
    'success'          => true,
    'message'          => $is_escalated ? 'Escalated case recorded successfully.' : 'Complaint submitted successfully!',
    'reference_number' => $reference_number,
    'complaint_id'     => $complaint_id,
    'status'           => $initial_status,
    'is_escalated'     => (bool)$is_escalated,
    'is_anonymous'     => (bool)$is_anonymous,
    'auto_priority'    => $priority
]);
    exit;
}

/* ============================================================
   RESIDENT: Get my complaints — role-aware
   ============================================================ */
if ($action === 'get_my_complaints' && $user_type === 'resident') {


    $nextType = "
        (SELECT ch.session_type
           FROM complaint_hearings ch
          WHERE ch.complaint_id = c.id
            AND ch.status = 'scheduled'
            AND (ch.hearing_date IS NULL OR ch.hearing_date >= CURDATE())
          ORDER BY ch.hearing_date ASC, ch.hearing_time ASC, ch.id ASC
          LIMIT 1) AS next_hearing_type
    ";
    $nextDate = "
        (SELECT ch.hearing_date
           FROM complaint_hearings ch
          WHERE ch.complaint_id = c.id
            AND ch.status = 'scheduled'
            AND (ch.hearing_date IS NULL OR ch.hearing_date >= CURDATE())
          ORDER BY ch.hearing_date ASC, ch.hearing_time ASC, ch.id ASC
          LIMIT 1) AS next_hearing_date
    ";
    $nextTime = "
        (SELECT ch.hearing_time
           FROM complaint_hearings ch
          WHERE ch.complaint_id = c.id
            AND ch.status = 'scheduled'
            AND (ch.hearing_date IS NULL OR ch.hearing_date >= CURDATE())
          ORDER BY ch.hearing_date ASC, ch.hearing_time ASC, ch.id ASC
          LIMIT 1) AS next_hearing_time
    ";

    // ── As Complainant (creator) ──
    $sql = "SELECT c.*,
              (SELECT CONCAT(full_name,' (',admin_role,')') FROM admin WHERE id = c.assigned_to) AS assigned_to_name,
              $nextType, $nextDate, $nextTime,
              'complainant' AS my_role
            FROM complaints c
            WHERE c.created_by = ?
            ORDER BY c.created_at DESC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $asComplainant = [];
    while ($row = mysqli_fetch_assoc($result)) $asComplainant[] = $row;
    mysqli_stmt_close($stmt);

    // ── As Respondent (listed + captain already acted) ──
    $sql2 = "SELECT c.*,
              (SELECT CONCAT(full_name,' (',admin_role,')') FROM admin WHERE id = c.assigned_to) AS assigned_to_name,
              $nextType, $nextDate, $nextTime,
              'respondent' AS my_role,
              cp.id AS respondent_party_id,
              cp.matched_resident_id
            FROM complaints c
            JOIN complaint_parties cp
              ON cp.complaint_id = c.id
             AND cp.party_type = 'respondent'
             AND cp.matched_resident_id = ?
            WHERE c.status NOT IN ('pending_review', 'pending_captain_action', 'escalated')
            ORDER BY c.created_at DESC";
    $stmt2 = mysqli_prepare($conn, $sql2);
    mysqli_stmt_bind_param($stmt2, "i", $user_id);
    mysqli_stmt_execute($stmt2);
    $result2 = mysqli_stmt_get_result($stmt2);
    $asRespondent = [];
    while ($row = mysqli_fetch_assoc($result2)) {
        if (!respondentCanSeeStatus($row['status'])) continue;
        $asRespondent[] = $row;
    }
    mysqli_stmt_close($stmt2);

    $all = array_merge($asComplainant, $asRespondent);
    usort($all, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    echo json_encode([
        'success'         => true,
        'complaints'      => $all,
        'as_complainant'  => $asComplainant,
        'as_respondent'   => $asRespondent,
        'counts'          => [
            'complainant' => count($asComplainant),
            'respondent'  => count($asRespondent),
            'total'       => count($all),
        ]
    ]);
    exit;
}
/* ============================================================
   RESIDENT: Get repudiation state
   ------------------------------------------------------------
   Returns the state of the current repudiation window — either
   for the Amicable Settlement (KP #16, 10-day) OR for the
   Agreement for Arbitration (KP #14, 5-day).

   The resident must be a party (complainant or respondent).
   ============================================================ */
if ($action === 'get_repudiation_state' && $user_type === 'resident') {
    $complaint_id = (int)($_GET['complaint_id'] ?? 0);
    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint_id.']); exit; }

    // 1. Load complaint
    $s = mysqli_prepare($conn,
        "SELECT id, reference_number, status,
                repudiation_deadline,
                arbitration_repudiated,
                amicable_repudiated,
                kp16_pdf_path, kp14_pdf_path,
                settlement_signed_at,
                arbitration_agreement_signed_at
           FROM complaints WHERE id = ?");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $c = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    if (!$c) { echo json_encode(['success'=>false,'message'=>'Not found.']); exit; }

    // 2. Resident must be a party
    $ps = mysqli_prepare($conn,
        "SELECT id, party_type, full_name
           FROM complaint_parties
          WHERE complaint_id = ?
            AND matched_resident_id = ?
            AND party_type IN ('complainant','respondent')
          LIMIT 1");
    mysqli_stmt_bind_param($ps, "ii", $complaint_id, $user_id);
    mysqli_stmt_execute($ps);
    $party = mysqli_fetch_assoc(mysqli_stmt_get_result($ps));
    mysqli_stmt_close($ps);
    if (!$party) { echo json_encode(['success'=>false,'message'=>'You are not a party to this complaint.']); exit; }

    // 3. Determine which agreement is currently repudiable
    $now = time();
    $agreement      = null;
    $deadlineIso    = null;
    $deadlineTs     = null;
    $windowDays     = null;
    $sourcePdfPath  = null;

    if ($c['status'] === 'settled_pending'
        && (int)$c['amicable_repudiated'] === 0
        && !empty($c['repudiation_deadline'])) {
        $agreement      = 'amicable_settlement';
        $deadlineIso    = $c['repudiation_deadline'];
        $deadlineTs     = strtotime($c['repudiation_deadline']);
        $windowDays     = 10;
        $sourcePdfPath  = $c['kp16_pdf_path'];
    } elseif ($c['status'] === 'arbitration_pending'
              && (int)$c['arbitration_repudiated'] === 0
              && !empty($c['repudiation_deadline'])) {
        $agreement      = 'arbitration';
        $deadlineIso    = $c['repudiation_deadline'];
        $deadlineTs     = strtotime($c['repudiation_deadline']);
        $windowDays     = 5;
        $sourcePdfPath  = $c['kp14_pdf_path'];
    }

    $windowOpen  = ($agreement !== null && $deadlineTs !== null && $now < $deadlineTs);
    $secondsLeft = $windowOpen ? ($deadlineTs - $now) : 0;
    $daysLeft    = $windowOpen ? (int)ceil($secondsLeft / 86400) : 0;

    // 4. Already filed?
    $rs = mysqli_prepare($conn,
        "SELECT id, ground, reason_text, filed_at, kp17_pdf_path, repudiated_agreement
           FROM complaint_repudiations
          WHERE complaint_id = ?
            AND repudiated_by_party = ?
          ORDER BY filed_at DESC LIMIT 1");
    mysqli_stmt_bind_param($rs, "is", $complaint_id, $party['party_type']);
    mysqli_stmt_execute($rs);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($rs));
    mysqli_stmt_close($rs);

    echo json_encode([
        'success'            => true,
        'complaint_id'       => $complaint_id,
        'reference_number'   => $c['reference_number'],
        'status'             => $c['status'],
        'my_role'            => $party['party_type'],
        'my_name'            => $party['full_name'],
        'agreement'          => $agreement,
        'window_days'        => $windowDays,
        'window_open'        => $windowOpen,
        'deadline'           => $deadlineIso,
        'days_left'          => $daysLeft,
        'seconds_left'       => $secondsLeft,
        'agreement_pdf_path' => $sourcePdfPath,
        'already_filed'      => $existing ?: null,
    ]);
    exit;
}
/* ============================================================
   RESIDENT: File a repudiation (KP Form #17)
   ------------------------------------------------------------
   Handles both:
     • settled_pending      → repudiate Amicable Settlement (KP #16)
     • arbitration_pending  → repudiate Agreement for Arbitration (KP #14)

   Downstream effects:
     • Settlement repudiated  → case → failed_conciliation_final
     • Arbitration repudiated → case → mediation_scheduled (falls back)
   ============================================================ */
if ($action === 'file_repudiation' && $user_type === 'resident') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $ground       = trim($_POST['ground'] ?? '');
    $reason_text  = trim($_POST['reason_text'] ?? '');

    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing complaint_id.']); exit; }
    if (!in_array($ground, ['fraud','violence','intimidation','other'], true)) {
        echo json_encode(['success'=>false,'message'=>'Please choose a valid ground.']); exit;
    }
    if (mb_strlen($reason_text) < 20) {
        echo json_encode(['success'=>false,'message'=>'Please explain the reason (minimum 20 characters).']); exit;
    }

    // 1. Load complaint
    $s = mysqli_prepare($conn,
        "SELECT id, reference_number, status,
                repudiation_deadline,
                arbitration_repudiated,
                amicable_repudiated,
                kp16_pdf_path, kp14_pdf_path
           FROM complaints WHERE id = ?");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $c = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    if (!$c) { echo json_encode(['success'=>false,'message'=>'Complaint not found.']); exit; }

    // 2. Resident must be a party
    $ps = mysqli_prepare($conn,
        "SELECT id, party_type, full_name
           FROM complaint_parties
          WHERE complaint_id = ?
            AND matched_resident_id = ?
            AND party_type IN ('complainant','respondent')
          LIMIT 1");
    mysqli_stmt_bind_param($ps, "ii", $complaint_id, $user_id);
    mysqli_stmt_execute($ps);
    $party = mysqli_fetch_assoc(mysqli_stmt_get_result($ps));
    mysqli_stmt_close($ps);
    if (!$party) { echo json_encode(['success'=>false,'message'=>'You are not a party to this complaint.']); exit; }

    // Never trust the client — use the DB's party_type
    $party_type = $party['party_type'];

    // 3. Determine which agreement is repudiable RIGHT NOW
    $now = time();
    $agreement     = null;
    $deadlineTs    = null;
    $sourcePdfPath = null;

    if ($c['status'] === 'settled_pending'
        && (int)$c['amicable_repudiated'] === 0
        && !empty($c['repudiation_deadline'])) {
        $agreement     = 'amicable_settlement';
        $deadlineTs    = strtotime($c['repudiation_deadline']);
        $sourcePdfPath = $c['kp16_pdf_path'];
    } elseif ($c['status'] === 'arbitration_pending'
              && (int)$c['arbitration_repudiated'] === 0
              && !empty($c['repudiation_deadline'])) {
        $agreement     = 'arbitration';
        $deadlineTs    = strtotime($c['repudiation_deadline']);
        $sourcePdfPath = $c['kp14_pdf_path'];
    } else {
        echo json_encode(['success'=>false,'message'=>'This case is not currently open for repudiation.']); exit;
    }

    if ($deadlineTs === null || $now >= $deadlineTs) {
        echo json_encode(['success'=>false,'message'=>'The repudiation window has already closed.']); exit;
    }

    // 4. Already filed by this party?
    $chk = mysqli_prepare($conn,
        "SELECT id FROM complaint_repudiations
          WHERE complaint_id = ? AND repudiated_by_party = ? LIMIT 1");
    mysqli_stmt_bind_param($chk, "is", $complaint_id, $party_type);
    mysqli_stmt_execute($chk);
    $hasExisting = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
    mysqli_stmt_close($chk);
    if ($hasExisting) {
        echo json_encode(['success'=>false,'message'=>'You have already filed a repudiation for this case.']); exit;
    }

    // 5. Optional evidence upload
    $evidenceRelPath = null;
    if (!empty($_FILES['evidence']) && is_uploaded_file($_FILES['evidence']['tmp_name'])) {
        $f = $_FILES['evidence'];
        if ($f['size'] > 10 * 1024 * 1024) {
            echo json_encode(['success'=>false,'message'=>'Evidence file exceeds 10 MB.']); exit;
        }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf','jpg','jpeg','png','webp'], true)) {
            echo json_encode(['success'=>false,'message'=>'Evidence must be PDF, JPG, PNG, or WEBP.']); exit;
        }
        $dir = __DIR__ . '/../uploads/repudiation_evidence/';
        if (!file_exists($dir)) @mkdir($dir, 0777, true);
        $safe = 'repud_' . $complaint_id . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $f['name']);
        if (move_uploaded_file($f['tmp_name'], $dir . $safe)) {
            $evidenceRelPath = 'uploads/repudiation_evidence/' . $safe;
        }
    }

    // 6. Insert the repudiation row first so we have an ID
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ins = mysqli_prepare($conn,
        "INSERT INTO complaint_repudiations
            (complaint_id, repudiated_agreement, repudiated_by_party, repudiated_by_resident_id,
             ground, reason_text, evidence_path, filed_at, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
    mysqli_stmt_bind_param($ins, "ississss",
        $complaint_id, $agreement, $party_type, $user_id,
        $ground, $reason_text, $evidenceRelPath, $ip);
    if (!mysqli_stmt_execute($ins)) {
        echo json_encode(['success'=>false,'message'=>'Could not save your repudiation.']); exit;
    }
    $repudiation_id = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($ins);

    // 7. Generate KP Form #17 via the shared generator
    if (!defined('KP_NOTICE_INTERNAL')) define('KP_NOTICE_INTERNAL', true);
    require_once __DIR__ . '/../admin/generate_kp_notice.php';

    $kp17Result = generate_kp_form_on_demand($conn, $complaint_id, 'kp17', [
        'issued_by'     => $party['full_name'],
        'ground'        => $ground,        // 'fraud' | 'violence' | 'intimidation' | 'other'
        'reason_text'   => $reason_text,   // the details the resident typed
        'repudiated_by' => $party_type,
    ]);
    $kp17Path = !empty($kp17Result['ok']) ? ($kp17Result['pdf_path'] ?? null) : null;

    if ($kp17Path) {
        $up = mysqli_prepare($conn,
            "UPDATE complaint_repudiations
                SET kp17_pdf_path = ?, kp17_generated_at = NOW()
              WHERE id = ?");
        mysqli_stmt_bind_param($up, "si", $kp17Path, $repudiation_id);
        mysqli_stmt_execute($up);
        mysqli_stmt_close($up);
    }

    // 8. Flip the case
    $prevStatus = $c['status'];

    if ($agreement === 'amicable_settlement') {
        // Settlement void → Captain must issue CFA
        $newStatus = 'amicable_repudiated';
        $logNote   = "Amicable Settlement (KP #16) REPUDIATED by " . strtoupper($party_type)
                   . " — {$party['full_name']}. "
                   . "Ground: {$ground}. Reason: {$reason_text}. "
                   . "The settlement is void and the case proceeds to Certification to File Action.";

        $u = mysqli_prepare($conn,
            "UPDATE complaints
                SET status = ?,
                    repudiation_deadline = NULL,
                    is_executory = 0,
                    amicable_repudiated = 1,
                    amicable_repudiated_at = NOW(),
                    amicable_repudiated_by_party = ?,
                    kp17_pdf_path = ?,
                    kp17_generated_at = NOW()
              WHERE id = ?");
        mysqli_stmt_bind_param($u, "sssi", $newStatus, $party_type, $kp17Path, $complaint_id);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);
    } else {
        // Arbitration void → mark the repudiation, fall back to mediation
        $newStatus = 'arbitration_repudiated';
        $logNote   = "Agreement for Arbitration (KP #14) REPUDIATED by " . strtoupper($party_type)
                   . " — {$party['full_name']}. "
                   . "Ground: {$ground}. Reason: {$reason_text}. "
                   . "The arbitration agreement is void and the case falls back to mediation.";

        $u = mysqli_prepare($conn,
            "UPDATE complaints
                SET status = ?,
                    arbitration_repudiated = 1,
                    arbitration_repudiated_at = NOW(),
                    arbitration_repudiated_by_party = ?,
                    repudiation_deadline = NULL,
                    arbitration_award_deadline = NULL,
                    kp17_pdf_path = ?,
                    kp17_generated_at = NOW()
              WHERE id = ?");
        mysqli_stmt_bind_param($u, "sssi", $newStatus, $party_type, $kp17Path, $complaint_id);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);
    }

    // 9. Log to complaint_updates — with the specific party
    $updaterRole = 'resident_' . $party_type;   // 'resident_complainant' | 'resident_respondent'

    $lu = mysqli_prepare($conn,
        "INSERT INTO complaint_updates
            (complaint_id, update_type, previous_status, new_status, notes, updated_by, updated_by_role)
         VALUES (?, 'note', ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($lu, "isssis",
        $complaint_id, $prevStatus, $newStatus, $logNote, $user_id, $updaterRole);
    mysqli_stmt_execute($lu);
    mysqli_stmt_close($lu);

    // 10. Notifications — title names the party
    $partyLabel  = ucfirst($party_type);   // "Complainant" | "Respondent"
    $notifyTitle = ($agreement === 'amicable_settlement')
        ? 'KP #16 Repudiated by ' . $partyLabel
        : 'KP #14 Repudiated by ' . $partyLabel;
    $notifyMsg   = "The {$party_type} ({$party['full_name']}) in complaint #{$c['reference_number']} "
                 . "filed a Repudiation (KP Form #17). "
                 . "Ground: {$ground}. "
                 . ($agreement === 'amicable_settlement'
                    ? "The settlement is void — the case proceeds to Certification to File Action."
                    : "The arbitration is void — the case returns to mediation.");

    // Notify the OTHER party
    $otherType = ($party_type === 'complainant') ? 'respondent' : 'complainant';
    $oq = mysqli_prepare($conn,
        "SELECT matched_resident_id FROM complaint_parties
          WHERE complaint_id = ? AND party_type = ? AND matched_resident_id IS NOT NULL LIMIT 1");
    mysqli_stmt_bind_param($oq, "is", $complaint_id, $otherType);
    mysqli_stmt_execute($oq);
    if ($orow = mysqli_fetch_assoc(mysqli_stmt_get_result($oq))) {
        $otherRid = (int)$orow['matched_resident_id'];
        $nq = mysqli_prepare($conn,
            "INSERT INTO notifications
                (user_type, user_id, resident_id, type, title, message, reference_id, reference_type, document_path)
             VALUES ('resident', ?, ?, 'warning', ?, ?, ?, 'complaint', ?)");
        mysqli_stmt_bind_param($nq, "iisssis", $otherRid, $otherRid, $notifyTitle, $notifyMsg, $complaint_id, $kp17Path);
        mysqli_stmt_execute($nq);
        mysqli_stmt_close($nq);
        if (function_exists('pushToUser')) {
            pushToUser($conn, $otherRid, $notifyTitle, $notifyMsg,
                '/BRITE/resident/dashboards/resident_dashboard.php', $kp17Path);
        }
    }
    mysqli_stmt_close($oq);

    // Notify secretary + captain
    foreach (['secretary','captain'] as $role) {
        $aq = mysqli_query($conn, "SELECT id FROM admin WHERE admin_role = '$role' AND is_active = 1");
        while ($a = mysqli_fetch_assoc($aq)) {
            $aid = (int)$a['id'];
            $nq = mysqli_prepare($conn,
                "INSERT INTO notifications
                    (user_type, user_id, resident_id, type, title, message, reference_id, reference_type, document_path)
                 VALUES ('admin', ?, NULL, 'warning', ?, ?, ?, 'complaint', ?)");
                       mysqli_stmt_bind_param($nq, "issss", $aid, $notifyTitle, $notifyMsg, $complaint_id, $kp17Path);
            mysqli_stmt_execute($nq);
            mysqli_stmt_close($nq);
            if (function_exists('pushToUser')) {
                pushToUser($conn, $aid, $notifyTitle, $notifyMsg,
                    '/BRITE/admin/dashboards/' . $role . '_dashboard.php', $kp17Path);
            }
        }
    }

    echo json_encode([
        'success'          => true,
        'message'          => 'Repudiation filed. KP Form #17 has been issued.',
        'repudiation_id'   => $repudiation_id,
        'agreement'        => $agreement,
        'new_status'       => $newStatus,
        'kp17_pdf_path'    => $kp17Path,
        'reference_number' => $c['reference_number'],
    ]);
    exit;
}
/* ============================================================
   RESIDENT: Get one complaint with full details — ROLE-AWARE
   ============================================================ */
if ($action === 'get_complaint_details' && $user_type === 'resident') {
    $complaint_id = (int)($_GET['id'] ?? 0);

    $stmt = mysqli_prepare($conn, "SELECT c.*,
              (SELECT CONCAT(full_name,' (',admin_role,')') FROM admin WHERE id = c.assigned_to) AS assigned_to_name
            FROM complaints c WHERE c.id = ?");
    mysqli_stmt_bind_param($stmt, "i", $complaint_id);
    mysqli_stmt_execute($stmt);
    $complaint = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$complaint) { echo json_encode(['success' => false, 'message' => 'Not found']); exit; }

    $role = getResidentRoleInComplaint($conn, $complaint_id, $user_id);
    $isCreator = ((int)$complaint['created_by'] === (int)$user_id);
    $isComplainant = ($isCreator || $role === 'complainant');
    $isRespondent  = ($role === 'respondent');

    if (!$isComplainant && !$isRespondent) {
        echo json_encode(['success' => false, 'message' => 'Not allowed']);
        exit;
    }

    if ($isRespondent && !$isComplainant) {
        if (!respondentCanSeeStatus($complaint['status'])) {
            echo json_encode(['success' => false, 'message' => 'This complaint is not yet available to view.']);
            exit;
        }
    }

    $hideSensitiveForRespondent = ($isRespondent && !$isComplainant && isSensitiveStatus($complaint['status']));
    $effectiveRole = $isComplainant ? 'complainant' : 'respondent';

    $out = [
        'success'   => true,
        'complaint' => $complaint,
        'my_role'   => $effectiveRole,
    ];

    // Parties
    $out['complainants'] = $out['respondents'] = $out['witnesses'] = [];
    $s = mysqli_prepare($conn, "SELECT * FROM complaint_parties WHERE complaint_id = ? ORDER BY id");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    while ($row = mysqli_fetch_assoc($r)) {
        if ($row['party_type'] === 'complainant')    $out['complainants'][] = $row;
        elseif ($row['party_type'] === 'respondent') $out['respondents'][]  = $row;
        else                                         $out['witnesses'][]    = $row;
    }
    mysqli_stmt_close($s);

    // Incidents
    $out['incidents'] = [];
    $s = mysqli_prepare($conn, "SELECT * FROM complaint_incidents WHERE complaint_id = ? ORDER BY incident_date, id");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    while ($row = mysqli_fetch_assoc($r)) $out['incidents'][] = $row;
    mysqli_stmt_close($s);

    // Evidence
    $out['evidence'] = [];
    if (!$hideSensitiveForRespondent) {
        $s = mysqli_prepare($conn, "SELECT * FROM complaint_evidence WHERE complaint_id = ? ORDER BY uploaded_at DESC");
        mysqli_stmt_bind_param($s, "i", $complaint_id);
        mysqli_stmt_execute($s);
        $r = mysqli_stmt_get_result($s);
        while ($row = mysqli_fetch_assoc($r)) $out['evidence'][] = $row;
        mysqli_stmt_close($s);
    }

    // AUDIT TRAIL — HIDDEN FROM BOTH ROLES
    $out['updates'] = [];

    // Hearings — date/time/location/status/outcome ONLY
    $out['hearings'] = [];
    $s = mysqli_prepare($conn,
        "SELECT id, session_type, session_number, hearing_date, hearing_time,
                location, status, outcome
           FROM complaint_hearings
          WHERE complaint_id = ?
          ORDER BY hearing_date DESC, hearing_time DESC, id DESC");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    while ($row = mysqli_fetch_assoc($r)) $out['hearings'][] = $row;
    mysqli_stmt_close($s);

    // KP forms — role-filtered
    $out['kp_forms'] = buildResidentKpForms($conn, $complaint_id, $effectiveRole);

    echo json_encode($out);
    exit;
}

/* ============================================================
   Add a note
   ============================================================ */
if ($action === 'add_complaint_note') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $note         = mysqli_real_escape_string($conn, $_POST['note'] ?? '');

    if (!$complaint_id || $note === '') {
        echo json_encode(['success' => false, 'message' => 'Missing fields']);
        exit;
    }

    if ($user_type === 'resident') {
        $chk = mysqli_prepare($conn, "SELECT id FROM complaints WHERE id = ? AND created_by = ?");
        mysqli_stmt_bind_param($chk, "ii", $complaint_id, $user_id);
        mysqli_stmt_execute($chk);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($chk))) {
            echo json_encode(['success' => false, 'message' => 'Not allowed']);
            exit;
        }
        $s = mysqli_prepare($conn, "INSERT INTO complaint_updates
              (complaint_id, update_type, notes, updated_by, updated_by_role)
              VALUES (?, 'note', ?, NULL, 'resident')");
        mysqli_stmt_bind_param($s, "is", $complaint_id, $note);
    } else {
        $role = $_SESSION['admin_role'] ?? 'admin';
        $s = mysqli_prepare($conn, "INSERT INTO complaint_updates
              (complaint_id, update_type, notes, updated_by, updated_by_role)
              VALUES (?, 'note', ?, ?, ?)");
        mysqli_stmt_bind_param($s, "isis", $complaint_id, $note, $user_id, $role);
    }

    if (mysqli_stmt_execute($s)) echo json_encode(['success' => true, 'message' => 'Note added']);
    else                          echo json_encode(['success' => false, 'message' => 'Failed']);
    exit;
}

/* ============================================================
   Upload evidence
   ============================================================ */
if ($action === 'upload_evidence' && isset($_FILES['evidence_file'])) {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $description  = mysqli_real_escape_string($conn, $_POST['description'] ?? '');

    $dir = __DIR__ . '/../uploads/complaint_evidence/';
    if (!file_exists($dir)) @mkdir($dir, 0777, true);

    $f    = $_FILES['evidence_file'];
    $name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $f['name']);
    $rel  = 'uploads/complaint_evidence/' . $name;

    if (move_uploaded_file($f['tmp_name'], $dir . $name)) {
        $orig = mysqli_real_escape_string($conn, $f['name']);
        $s = mysqli_prepare($conn, "INSERT INTO complaint_evidence
              (complaint_id, file_name, file_path, file_type, description, uploaded_by)
              VALUES (?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($s, "issssi", $complaint_id, $orig, $rel, $f['type'], $description, $user_id);
        echo json_encode(['success' => mysqli_stmt_execute($s)]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Upload failed']);
    }
    exit;
}

/* ============================================================
   RESIDENT: Cancel my complaint
   ============================================================ */
if ($action === 'cancel_complaint' && $user_type === 'resident') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    if (!$complaint_id) { echo json_encode(['success'=>false,'message'=>'Missing id']); exit; }

    $s = mysqli_prepare($conn, "SELECT id, status, reference_number FROM complaints WHERE id = ? AND created_by = ?");
    mysqli_stmt_bind_param($s, "ii", $complaint_id, $user_id);
    mysqli_stmt_execute($s);
    $c = mysqli_fetch_assoc(mysqli_stmt_get_result($s));

    if (!$c) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }

    if (!in_array($c['status'], ['pending_review','summoned'])) {
        echo json_encode([
            'success'=>false,
            'message'=>'You can only cancel a complaint before mediation starts.'
        ]);
        exit;
    }

    $s = mysqli_prepare($conn, "INSERT INTO complaint_updates
        (complaint_id, update_type, previous_status, new_status, notes, updated_by, updated_by_role)
        VALUES (?, 'status_change', ?, 'dismissed', 'Complaint withdrawn by the complainant.', NULL, 'resident')");
    mysqli_stmt_bind_param($s, "is", $complaint_id, $c['status']);
    mysqli_stmt_execute($s);

    $s = mysqli_prepare($conn, "UPDATE complaints SET status='dismissed' WHERE id=?");
    mysqli_stmt_bind_param($s, "i", $complaint_id);
    mysqli_stmt_execute($s);

    $nTitle = 'Complaint Withdrawn';
    $nMsg   = "Complaint #{$c['reference_number']} was withdrawn by the complainant.";
    $q = mysqli_query($conn, "SELECT id FROM admin WHERE admin_role='secretary' AND is_active=1");
    while ($row = mysqli_fetch_assoc($q)) {
        $aid = (int)$row['id'];
        $ins = mysqli_prepare($conn, "INSERT INTO notifications
                (user_type, user_id, resident_id, type, title, message, reference_id, reference_type)
                VALUES ('admin', ?, NULL, 'info', ?, ?, ?, 'complaint')");
        mysqli_stmt_bind_param($ins, "issi", $aid, $nTitle, $nMsg, $complaint_id);
        mysqli_stmt_execute($ins);
    }

    echo json_encode(['success'=>true,'message'=>'Complaint withdrawn']);
    exit;
}

/* ============================================================
   RESIDENT: Live auto-priority preview (for the badge)
   ============================================================ */

   if ($action === 'preview_duplicate_target' && $user_type === 'resident') {
    $target_id = (int)($_GET['id'] ?? 0);
    if (!$target_id) { echo json_encode(['success'=>false,'message'=>'Missing id']); exit; }

    // Only allow the resident to view their OWN complaints via this preview
    $s = mysqli_prepare($conn,
        "SELECT id, reference_number, title, description, complaint_subject,
                status, priority, created_at, hearing_date, hearing_time, hearing_location
           FROM complaints
          WHERE id = ? AND created_by = ?
          LIMIT 1");
    mysqli_stmt_bind_param($s, "ii", $target_id, $user_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);

    if (!$row) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
    echo json_encode(['success'=>true, 'complaint'=>$row]);
    exit;
}
if ($action === 'preview_auto_priority' && $user_type === 'resident') {
    $description = trim($_POST['description'] ?? '');
    $subject     = trim($_POST['complaint_subject'] ?? '');
    $escalation_type = trim($_POST['escalation_type'] ?? '');
    $is_escalated = $escalation_type !== '';

    $priority = classifyAutoPriority($description, $subject, $is_escalated);

    echo json_encode(['success' => true, 'priority' => $priority]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);