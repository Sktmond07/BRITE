<?php
// admin/print_referral_letter.php
// Multi-template referral letter. Auto-detects escalation type and renders
// the correct addressee, subject line, statutory basis, and body text.
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../sign_in.php'); exit();
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { echo "Invalid complaint ID."; exit; }

$stmt = mysqli_prepare($conn, "SELECT c.*, r.first_name, r.last_name, r.address AS resident_address,
                                      r.phone AS resident_phone, r.email AS resident_email
                                FROM complaints c
                                JOIN resident r ON c.created_by = r.id
                                WHERE c.id = ?");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$c = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$c) { echo "Complaint not found."; exit; }

// Fetch complainants + respondents
$partiesQ = mysqli_prepare($conn, "SELECT party_type, full_name, address, contact_number
                                    FROM complaint_parties
                                    WHERE complaint_id = ?
                                    ORDER BY FIELD(party_type,'complainant','respondent','witness'), id");
mysqli_stmt_bind_param($partiesQ, "i", $id);
mysqli_stmt_execute($partiesQ);
$partiesR = mysqli_stmt_get_result($partiesQ);
$complainants = []; $respondents = [];
while ($p = mysqli_fetch_assoc($partiesR)) {
    if ($p['party_type'] === 'complainant') $complainants[] = $p;
    elseif ($p['party_type'] === 'respondent') $respondents[] = $p;
}

// ---- Auto-detect escalation type ----
$escalation = strtolower(trim($c['escalation_type'] ?? ''));

if ($escalation === '') {
    $desc = strtolower($c['description'] ?? '');
    $patterns = [
        'rape'             => ['ginahasa','gahasa','rape','pinilit na makipagtalik','sexual assault'],
        'vawc'             => ['sinasaktan ako ng asawa','binubugbog ako','sinakal ako ng asawa','vawc','ra 9262'],
        'child_abuse'      => ['pang-aabuso sa bata','child abuse','ra 7610','pinagtatrabaho ang bata','pinapalo ang bata ng matindi'],
        'drugs'            => ['shabu','droga','marijuana','nagbebenta ng droga','drug den','ra 9165'],
        'illegal_gambling' => ['jueteng','tupada','sabong','illegal gambling','pd 1602'],
        'labor'            => ['hindi sumasahod','delayed sahod','labor dispute','dole','nlrc'],
        'government'       => ['gobyerno','barangay official','kapitan','public officer'],
        'high_penalty'     => ['murder','homicide','kidnapping','robbery with violence','terrorism'],
    ];
    foreach ($patterns as $type => $kw) {
        foreach ($kw as $k) {
            if (strpos($desc, $k) !== false) { $escalation = $type; break 2; }
        }
    }
}

// ---- Template library ----
$templates = [
    'rape' => [
        'subject'   => 'REFERRAL OF SEXUAL ASSAULT / RAPE CASE',
        'addressee' => "THE OFFICER-IN-CHARGE\nPNP Women & Children Protection Desk (WCPD)\nSto. Tomas, Pampanga",
        'cc'        => "Provincial Prosecutor's Office\nDSWD Field Office\nMunicipal Social Welfare & Development Office",
        'law'       => 'Republic Act No. 8353 (Anti-Rape Law), Republic Act No. 11648, and Section 408 of RA 7160',
        'body'      => "This Barangay respectfully refers the above-captioned case to your good office for appropriate action. The case involves alleged <strong>sexual assault / rape</strong>, which is expressly excluded from the jurisdiction of the Katarungang Pambarangay under Section 408 of RA 7160 and falls squarely within the jurisdiction of the PNP, the Prosecutor's Office, and the proper courts.\n\nNO MEDIATION of any kind was conducted, nor shall any be conducted, as the same is prohibited by law.",
        'extra'     => "The complainant has been advised to proceed immediately to the PNP-WCPD. Should the victim require protective custody, the DSWD and MSWDO have been coordinated with."
    ],
    'vawc' => [
        'subject'   => 'REFERRAL OF VIOLENCE AGAINST WOMEN AND CHILDREN (VAWC) CASE',
        'addressee' => "THE OFFICER-IN-CHARGE\nPNP Women's Desk\nSto. Tomas, Pampanga",
        'cc'        => "Municipal Social Welfare & Development Office\nProvincial Prosecutor's Office\nDepartment of Social Welfare and Development",
        'law'       => 'Republic Act No. 9262 (Anti-Violence Against Women and Their Children Act of 2004) and Section 408 of RA 7160',
        'body'      => "This Barangay respectfully refers the above-captioned case to your good office for appropriate action. The case involves alleged <strong>violence against women and children</strong> under RA 9262, which is expressly excluded from the jurisdiction of the Katarungang Pambarangay.\n\nIn compliance with RA 9262, the Barangay has issued / shall issue a <strong>Barangay Protection Order (BPO)</strong> to the victim, valid for fifteen (15) days. <strong>NO MEDIATION</strong> was conducted nor shall any be conducted, as the same is strictly prohibited under Section 33 of RA 9262.",
        'extra'     => "The victim has been informed of her rights and of the services available through the DSWD and the PNP Women's Desk."
    ],
    'child_abuse' => [
        'subject'   => 'REFERRAL OF CHILD ABUSE CASE',
        'addressee' => "THE OFFICER-IN-CHARGE\nPNP Women & Children Protection Desk (WCPD)\nSto. Tomas, Pampanga",
        'cc'        => "Municipal Social Welfare & Development Office\nBantay Bata 163\nDepartment of Social Welfare and Development\nProvincial Prosecutor's Office",
        'law'       => 'Republic Act No. 7610 (Special Protection of Children Against Abuse, Exploitation and Discrimination Act) and Section 408 of RA 7160',
        'body'      => "This Barangay respectfully refers the above-captioned case to your good office for appropriate action. The case involves alleged <strong>child abuse / exploitation</strong> under RA 7610, which is expressly excluded from the jurisdiction of the Katarungang Pambarangay.\n\nThe Barangay has taken steps to ensure the <strong>safety of the minor</strong>, including coordinating with the DSWD and MSWDO for possible protective custody. <strong>NO MEDIATION</strong> was conducted, as it is prohibited by law.",
        'extra'     => "Immediate coordination with the PNP-WCPD and Bantay Bata 163 was initiated to secure the welfare of the child."
    ],
    'drugs' => [
        'subject'   => 'REFERRAL OF ILLEGAL DRUGS CASE',
        'addressee' => "THE OFFICER-IN-CHARGE\nPNP Anti-Illegal Drugs Group / PDEA\nSto. Tomas, Pampanga",
        'cc'        => "Barangay Anti-Drug Abuse Council (BADAC)\nMunicipal Anti-Drug Abuse Council (MADAC)",
        'law'       => 'Republic Act No. 9165 (Comprehensive Dangerous Drugs Act of 2002) and Section 408 of RA 7160',
        'body'      => "This Barangay respectfully refers the above-captioned case to your good office for appropriate action. The case involves alleged <strong>illegal drug activities</strong> under RA 9165, which are expressly excluded from the jurisdiction of the Katarungang Pambarangay.\n\n<strong>NO MEDIATION</strong> was conducted. The Barangay is not authorized to investigate, arrest, or process drug-related offenses.",
        'extra'     => "The BADAC has been notified of this report. The Barangay commits full cooperation with your office."
    ],
    'illegal_gambling' => [
        'subject'   => 'REFERRAL OF ILLEGAL GAMBLING CASE',
        'addressee' => "THE OFFICER-IN-CHARGE\nPhilippine National Police (PNP)\nSto. Tomas, Pampanga",
        'cc'        => "Municipal Anti-Illegal Gambling Task Force",
        'law'       => 'Presidential Decree No. 1602 (Anti-Illegal Gambling) and Section 408 of RA 7160',
        'body'      => "This Barangay respectfully refers the above-captioned case to your good office for appropriate action. The case involves alleged <strong>illegal gambling activities</strong> under PD 1602, which are expressly excluded from the jurisdiction of the Katarungang Pambarangay.\n\n<strong>NO MEDIATION</strong> was conducted. The Barangay is not authorized to adjudicate gambling offenses.",
        'extra'     => "The Barangay will provide full assistance to the PNP in the conduct of any operation."
    ],
    'labor' => [
        'subject'   => 'REFERRAL OF LABOR DISPUTE',
        'addressee' => "THE REGIONAL DIRECTOR\nDepartment of Labor and Employment (DOLE)\nRegional Office No. III",
        'cc'        => "National Labor Relations Commission (NLRC)\nPublic Employment Service Office (PESO)",
        'law'       => 'Labor Code of the Philippines (Presidential Decree No. 442) and Section 408 of RA 7160',
        'body'      => "This Barangay respectfully refers the above-captioned case to your good office for appropriate action. The case involves a <strong>labor / employment dispute</strong>, which is expressly excluded from the jurisdiction of the Katarungang Pambarangay under Section 408 of RA 7160.\n\n<strong>NO MEDIATION</strong> was conducted. Labor disputes fall exclusively within the jurisdiction of the DOLE and the NLRC.",
        'extra'     => "The parties have been advised to file the appropriate case with the DOLE or the NLRC."
    ],
    'government' => [
        'subject'   => 'REFERRAL OF CASE INVOLVING A GOVERNMENT OFFICIAL / ENTITY',
        'addressee' => "THE HONORABLE PROSECUTOR\nProvincial Prosecutor's Office\nPampanga",
        'cc'        => "Office of the Ombudsman\nCivil Service Commission\nCommission on Audit",
        'law'       => 'Section 408 of RA 7160 and relevant administrative and criminal statutes',
        'body'      => "This Barangay respectfully refers the above-captioned case to your good office for appropriate action. The case involves an <strong>offense committed by or against a government official or entity</strong>, which is expressly excluded from the jurisdiction of the Katarungang Pambarangay.\n\n<strong>NO MEDIATION</strong> was conducted, in accordance with Section 408 of RA 7160.",
        'extra'     => "The complaint has been recorded in the Barangay Blotter for proper documentation."
    ],
    'no_private_party' => [
        'subject'   => 'REFERRAL OF OFFENSE WITH NO PRIVATE PARTY',
        'addressee' => "THE OFFICER-IN-CHARGE\nPhilippine National Police (PNP)\nSto. Tomas, Pampanga",
        'cc'        => "Provincial Prosecutor's Office",
        'law'       => 'Section 408 of RA 7160',
        'body'      => "This Barangay respectfully refers the above-captioned case to your good office for appropriate action. The offense complained of has <strong>no private offended party</strong>, and is therefore expressly excluded from the jurisdiction of the Katarungang Pambarangay.\n\n<strong>NO MEDIATION</strong> was conducted.",
        'extra'     => "The matter is referred for proper police investigation and/or prosecution."
    ],
    'high_penalty' => [
        'subject'   => 'REFERRAL OF SERIOUS OFFENSE (Penalty exceeds 1 year / Fine exceeds P5,000)',
        'addressee' => "THE OFFICER-IN-CHARGE\nPhilippine National Police (PNP)\nSto. Tomas, Pampanga",
        'cc'        => "Provincial Prosecutor's Office",
        'law'       => 'Section 408 of RA 7160 (Katarungang Pambarangay Law)',
        'body'      => "This Barangay respectfully refers the above-captioned case to your good office for appropriate action. The offense complained of carries a penalty <strong>exceeding one (1) year imprisonment or a fine exceeding Five Thousand Pesos (P5,000.00)</strong>, and is therefore excluded from the jurisdiction of the Katarungang Pambarangay under Section 408 of RA 7160.\n\n<strong>NO MEDIATION</strong> was conducted.",
        'extra'     => "The case is referred for proper police investigation and/or prosecution."
    ],
    'default' => [
        'subject'   => 'REFERRAL OF NON-JURISDICTIONAL CASE',
        'addressee' => "THE OFFICER-IN-CHARGE\nProper Government Agency\nSto. Tomas, Pampanga",
        'cc'        => "Provincial Prosecutor's Office",
        'law'       => 'Section 408 of RA 7160',
        'body'      => "This Barangay respectfully refers the above-captioned case to your good office for appropriate action. The case falls outside the jurisdiction of the Katarungang Pambarangay under Section 408 of RA 7160.\n\n<strong>NO MEDIATION</strong> was conducted.",
        'extra'     => "The matter is referred for appropriate action by your office."
    ]
];

$tpl = $templates[$escalation] ?? $templates['default'];

$referTo    = $c['escalation_refer_to'] ?? '';
$today      = date('F j, Y');
$refNo      = $c['reference_number'];
$residentName = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
$description  = nl2br(htmlspecialchars($c['description'] ?? ''));
$complaint    = htmlspecialchars($c['complaint_subject'] ?? '—');

$complainantLine = '';
if (!empty($complainants)) {
    $names = array_map(fn($p) => htmlspecialchars($p['full_name']), $complainants);
    $complainantLine = implode(', ', $names);
} else {
    $complainantLine = htmlspecialchars($residentName);
}

$respondentLine = '';
if (!empty($respondents)) {
    $names = array_map(fn($p) => htmlspecialchars($p['full_name']), $respondents);
    $respondentLine = implode(', ', $names);
} else {
    $respondentLine = '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Referral Letter — <?= htmlspecialchars($refNo) ?></title>
    <style>
        @page { size: A4; margin: 20mm 18mm; }
        body { font-family: 'Times New Roman', serif; font-size: 12pt; line-height: 1.6; color: #000; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 12px; }
        .header .republic { font-size: 11pt; margin: 0; }
        .header .province { font-size: 11pt; margin: 0; }
        .header .municipality { font-size: 11pt; margin: 0; }
        .header h1 { margin: 6px 0 0; font-size: 15pt; letter-spacing: 1px; }
        .header h2 { margin: 2px 0 0; font-size: 13pt; letter-spacing: 2px; }
        .header .seal {
            display: inline-flex; align-items: center; justify-content: center;
            width: 60px; height: 60px; border: 2px solid #000; border-radius: 50%;
            font-size: 24px; margin: 0 auto 6px;
        }
        .date { margin: 20px 0 16px; }
        .addressee { margin: 16px 0; line-height: 1.5; white-space: pre-line; font-weight: 500; }
        .subject { font-weight: bold; margin: 18px 0; text-decoration: underline; text-transform: uppercase; }
        .body p { text-align: justify; margin: 10px 0; }
        .case-info { background: #f4f4f4; padding: 12px 16px; border-left: 4px solid #c62828; margin: 16px 0; }
        .case-info table { width: 100%; border-collapse: collapse; }
        .case-info td { padding: 5px 6px; vertical-align: top; font-size: 11pt; }
        .case-info td:first-child { font-weight: bold; width: 170px; }
        .no-mediation { background: #ffebee; color: #b71c1c; padding: 10px 14px; border-left: 4px solid #c62828; margin: 16px 0; font-weight: bold; text-align: center; }
        .signature { margin-top: 60px; text-align: right; line-height: 1.8; }
        .signature .line { display: inline-block; border-top: 1px solid #000; padding: 4px 50px 0; font-weight: bold; text-transform: uppercase; }
        .noted { margin-top: 40px; text-align: right; line-height: 1.8; }
        .noted .line { display: inline-block; border-top: 1px solid #000; padding: 4px 50px 0; font-weight: bold; text-transform: uppercase; }
        .cc { margin-top: 40px; font-size: 10.5pt; white-space: pre-line; }
        .footer-note { margin-top: 40px; font-size: 9.5pt; text-align: center; color: #555; font-style: italic; border-top: 1px solid #ccc; padding-top: 8px; }
        @media print { .no-print { display: none !important; } }
        .print-toolbar {
            position: fixed; top: 20px; right: 20px; z-index: 999;
            background: #2E7D32; color: #fff; border: none; padding: 10px 20px;
            border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .print-toolbar:hover { background: #1b5e20; }
    </style>
</head>
<body>
<button class="print-toolbar no-print" onclick="window.print()">🖨️ Print Letter</button>

<div class="header">
    <div class="seal">⚖️</div>
    <p class="republic">Republic of the Philippines</p>
    <p class="province">Province of Pampanga</p>
    <p class="municipality">Municipality of Sto. Tomas</p>
    <h1>BARANGAY SAN BARTOLOME</h1>
    <h2>LUPONG TAGAPAMAYAPA</h2>
</div>

<div class="date"><?= $today ?></div>

<div class="addressee"><?= nl2br(htmlspecialchars($tpl['addressee'])) ?></div>

<div class="subject">RE: <?= htmlspecialchars($tpl['subject']) ?> — <?= htmlspecialchars($refNo) ?></div>

<div class="body">
    <p>Sir/Madam:</p>

    <p><?= nl2br($tpl['body']) ?></p>

    <div class="case-info">
        <table>
            <tr><td>Reference No.:</td><td><?= htmlspecialchars($refNo) ?></td></tr>
            <tr><td>Complainant(s):</td><td><?= $complainantLine ?></td></tr>
            <tr><td>Respondent(s):</td><td><?= $respondentLine ?></td></tr>
            <tr><td>Nature of Case:</td><td><?= $complaint ?></td></tr>
            <tr><td>Escalation Type:</td><td><?= htmlspecialchars(strtoupper($escalation ?: 'general')) ?></td></tr>
            <tr><td>Date Filed:</td><td><?= date('F j, Y', strtotime($c['created_at'])) ?></td></tr>
            <tr><td>Statutory Basis:</td><td><?= htmlspecialchars($tpl['law']) ?></td></tr>
            <tr><td>Brief Description:</td><td><?= $description ?></td></tr>
        </table>
    </div>

    <div class="no-mediation">
        ⚠️ NO MEDIATION WAS CONDUCTED. This case is excluded from the Katarungang Pambarangay pursuant to law.
    </div>

    <?php if (!empty($tpl['extra'])): ?>
        <p><?= $tpl['extra'] ?></p>
    <?php endif; ?>

    <p>We respectfully request your good office to take the necessary action on this matter.</p>

    <p>Thank you.</p>
</div>

<div class="signature">
    Very truly yours,<br><br><br>
    <span class="line">BARANGAY SECRETARY</span><br>
    Barangay San Bartolome
</div>

<div class="noted">
    Noted by:<br><br><br>
    <span class="line">PUNONG BARANGAY</span><br>
    Barangay San Bartolome
</div>

<div class="cc">
    <strong>cc:</strong> <?= nl2br(htmlspecialchars($tpl['cc'])) ?>
</div>

<div class="footer-note">
    This document is generated by the Barangay Records Information &amp; Transaction Engine (BRITE).<br>
    This referral letter does not constitute mediation or adjudication. It is a formal endorsement.
</div>

</body>
</html>