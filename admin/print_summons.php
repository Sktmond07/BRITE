<?php
// admin/print_summons.php — Print-ready Summons (ONE party at a time)
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../sign_in.php');
    exit();
}

require_once __DIR__ . '/../config/database.php';

$complaint_id = (int)($_GET['id'] ?? 0);
if ($complaint_id <= 0) { die('Invalid complaint ID.'); }

/* Fetch complaint + Captain */
$s = mysqli_prepare($conn,
    "SELECT c.*, a.full_name AS captain_name
     FROM complaints c
     LEFT JOIN admin a ON c.assigned_to = a.id
     WHERE c.id = ?");
mysqli_stmt_bind_param($s, "i", $complaint_id);
mysqli_stmt_execute($s);
$complaint = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
if (!$complaint) { die('Complaint not found.'); }

/* Parties with NO account */
$parties = [];
$rs = mysqli_prepare($conn,
    "SELECT id, party_type, full_name, address, contact_number
     FROM complaint_parties
     WHERE complaint_id = ? AND notified_via = 'print'
     ORDER BY FIELD(party_type, 'complainant','respondent','witness'), id");
mysqli_stmt_bind_param($rs, "i", $complaint_id);
mysqli_stmt_execute($rs);
$rr = mysqli_stmt_get_result($rs);
while ($row = mysqli_fetch_assoc($rr)) $parties[] = $row;

if (!$parties) {
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px;text-align:center;">
          <h2>No summons to print</h2>
          <p>All parties have Barangay accounts and were notified via the app.</p>
          <button onclick="window.close()" style="padding:10px 20px;margin-top:20px;">Close</button>
          </body></html>';
    exit;
}

$hearing_date     = $complaint['hearing_date'] ? date('F j, Y', strtotime($complaint['hearing_date'])) : '____________';
$hearing_time     = $complaint['hearing_time'] ? date('g:i A', strtotime($complaint['hearing_time'])) : '__________';
$hearing_location = $complaint['hearing_location'] ?: 'Barangay Hall';
$captain_name     = $complaint['captain_name'] ?: 'Punong Barangay';
$ref_no           = $complaint['reference_number'];
$today_long       = date('jS') . ' day of ' . date('F Y');

$roleLabel = [
    'complainant' => 'Complainant',
    'respondent'  => 'Respondent',
    'witness'     => 'Witness'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Summons — <?php echo htmlspecialchars($ref_no); ?></title>
<style>
    @page { size: A4; margin: 18mm 16mm; }
    * { box-sizing: border-box; }
    body {
        font-family: 'Times New Roman', Times, serif;
        color: #000; font-size: 12pt; line-height: 1.55;
        margin: 0; padding: 20px; background: #eee;
    }

    /* ===== Toolbar (hidden when printing) ===== */
    .toolbar {
        max-width: 800px;
        margin: 0 auto 20px;
        padding: 14px 20px;
        background: #fff;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }
    .toolbar-left {
        display: flex; align-items: center; gap: 10px;
        font-family: 'Segoe UI', sans-serif; font-size: 0.9rem; color: #333;
    }
    .toolbar-left .party-count {
        background: #e8f5e9; color: #2E7D32;
        padding: 4px 12px; border-radius: 20px;
        font-weight: 700; font-size: 0.82rem;
    }
    .toolbar-left .party-role {
        padding: 3px 10px; border-radius: 12px;
        font-size: 0.7rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.4px;
    }
    .toolbar-left .party-role.complainant { background: #e8f5e9; color: #2E7D32; }
    .toolbar-left .party-role.respondent  { background: #fff3e0; color: #ef6c00; }
    .toolbar-left .party-role.witness     { background: #e3f2fd; color: #1565c0; }

    .toolbar-right { display: flex; gap: 8px; flex-wrap: wrap; }
    .toolbar button {
        padding: 9px 18px;
        font-size: 13px;
        font-family: 'Segoe UI', sans-serif;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.15s;
    }
    .toolbar button:disabled { opacity: 0.4; cursor: not-allowed; }
    .toolbar button.primary { background: #2E7D32; color: #fff; }
    .toolbar button.primary:hover:not(:disabled) { background: #1b5e20; }
    .toolbar button.nav { background: #1976d2; color: #fff; }
    .toolbar button.nav:hover:not(:disabled) { background: #0d47a1; }
    .toolbar button.secondary { background: #6c757d; color: #fff; }
    .toolbar button.secondary:hover { background: #5a6268; }

    /* ===== Summons page ===== */
    .page {
        width: 210mm;
        min-height: 297mm;
        margin: 0 auto;
        background: #fff;
        padding: 20mm 18mm;
        box-shadow: 0 2px 12px rgba(0,0,0,0.12);
        display: none;
    }
    .page.active { display: block; }

    .header {
        text-align: center;
        border-bottom: 2px solid #000;
        padding-bottom: 12px;
        margin-bottom: 22px;
    }
    .republic { font-size: 11pt; letter-spacing: 1px; }
    .province { font-size: 11pt; }
    .municipality { font-size: 11pt; }
    .barangay { font-size: 14pt; font-weight: bold; letter-spacing: 1px; margin: 4px 0; }
    .office { font-size: 11pt; }
    .title {
        text-align: center;
        font-size: 20pt;
        font-weight: bold;
        letter-spacing: 6px;
        margin: 22px 0 6px;
    }
    .subtitle {
        text-align: center;
        font-size: 10pt;
        color: #444;
        margin-bottom: 22px;
    }
    .case-ref {
        text-align: right;
        font-size: 11pt;
        margin-bottom: 18px;
    }
    .body p { margin: 12px 0; text-align: justify; }
    .indent { margin-left: 28px; }
    .party-block {
        background: #f5f5f5;
        border-left: 3px solid #000;
        padding: 10px 16px;
        margin: 14px 0;
    }
    .party-block strong { font-size: 12pt; }
    .party-block .role {
        display: inline-block;
        margin-top: 4px;
        font-size: 10pt;
        background: #333;
        color: #fff;
        padding: 2px 10px;
        border-radius: 12px;
        letter-spacing: 0.5px;
    }
    .signature-block {
        margin-top: 60px;
        text-align: center;
    }
    .signature-line {
        border-top: 1px solid #000;
        width: 280px;
        margin: 0 auto 4px;
        padding-top: 6px;
        font-weight: bold;
        text-transform: uppercase;
        font-size: 11pt;
    }
    .signature-role { font-size: 10pt; color: #333; }
    .footer-note {
        margin-top: 36px;
        padding-top: 14px;
        border-top: 1px dashed #999;
        font-size: 9pt;
        color: #555;
        text-align: center;
    }

    @media print {
        body { background: #fff; padding: 0; }
        .toolbar { display: none; }
        .page {
            width: 100%;
            min-height: auto;
            margin: 0;
            padding: 0;
            box-shadow: none;
            display: block !important;
        }
        .page:not(.active) { display: none !important; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <div class="toolbar-left">
        <span>Summoning</span>
        <span class="party-count" id="partyCounter">1 / <?php echo count($parties); ?></span>
        <span class="party-role" id="partyRole"><?php echo htmlspecialchars($roleLabel[$parties[0]['party_type']] ?? ''); ?></span>
        <span id="partyName" style="color:#555;"></span>
    </div>
    <div class="toolbar-right">
        <button class="nav" id="prevBtn" onclick="showPrev()" disabled>← Previous</button>
        <button class="nav" id="nextBtn" onclick="showNext()">Next →</button>
        <button class="primary" onclick="window.print()">🖨 Print This</button>
        <button class="secondary" onclick="window.close()">Close</button>
    </div>
</div>

<?php foreach ($parties as $idx => $p): ?>
    <div class="page <?php echo $idx === 0 ? 'active' : ''; ?>" data-index="<?php echo $idx; ?>">
        <div class="header">
            <div class="republic">Republic of the Philippines</div>
            <div class="province">Province of Pampanga</div>
            <div class="municipality">Municipality of Sto. Tomas</div>
            <div class="barangay">BARANGAY SAN BARTOLOME</div>
            <div class="office">Office of the Punong Barangay</div>
        </div>

        <div class="title">SUMMONS</div>
        <div class="subtitle">Katarungang Pambarangay</div>

        <div class="case-ref">
            <strong>Case No.:</strong> <?php echo htmlspecialchars($ref_no); ?><br>
            <strong>Date Issued:</strong> <?php echo date('F j, Y'); ?>
        </div>

        <div class="body">
            <p><strong>TO:</strong></p>

            <div class="party-block">
                <strong><?php echo htmlspecialchars($p['full_name']); ?></strong><br>
                <?php if (!empty($p['address'])): ?>
                    <?php echo htmlspecialchars($p['address']); ?><br>
                <?php endif; ?>
                <?php if (!empty($p['contact_number'])): ?>
                    Contact No.: <?php echo htmlspecialchars($p['contact_number']); ?><br>
                <?php endif; ?>
                <span class="role"><?php echo htmlspecialchars($roleLabel[$p['party_type']] ?? ucfirst($p['party_type'])); ?></span>
            </div>

            <p class="indent"><strong>GREETINGS:</strong></p>

            <p class="indent">
                You are hereby summoned to appear before the
                <strong>Punong Barangay <?php echo htmlspecialchars($captain_name); ?></strong>
                on <strong><?php echo $hearing_date; ?></strong>
                at <strong><?php echo $hearing_time; ?></strong>,
                at the <strong><?php echo htmlspecialchars($hearing_location); ?></strong>,
                in connection with the complaint docketed as
                Case No. <strong><?php echo htmlspecialchars($ref_no); ?></strong>.
            </p>

            <p class="indent">
                You are summoned in your capacity as
                <strong><?php echo htmlspecialchars($roleLabel[$p['party_type']] ?? 'Party'); ?></strong>
                in the above-entitled case, and are required to appear
                <strong>in person</strong> and to bring with you any evidence
                and witnesses in support of your side. Pursuant to the
                Katarungang Pambarangay Law,
                <strong>no lawyer shall appear for or in behalf of any party</strong>
                during the mediation proceedings.
            </p>

            <p class="indent">
                <strong>FAILURE TO APPEAR</strong> without justifiable cause shall be deemed
                a waiver of your right to present evidence and may result in the case being
                endorsed to the proper court or prosecutor's office.
            </p>

            <p>
                Given this <strong><?php echo $today_long; ?></strong> at Barangay San Bartolome,
                Sto. Tomas, Pampanga.
            </p>
        </div>

        <div class="signature-block">
            <div class="signature-line"><?php echo htmlspecialchars($captain_name); ?></div>
            <div class="signature-role">Punong Barangay</div>
        </div>

        <div class="footer-note">
            This summons is issued pursuant to the Katarungang Pambarangay Law (P.D. 1508, as amended).
            Keep this document and present it upon appearing at the Barangay Hall.
        </div>
    </div>
<?php endforeach; ?>

<script>
    const parties = <?php echo json_encode(array_map(function($p) use ($roleLabel) {
        return [
            'name' => $p['full_name'],
            'role' => $roleLabel[$p['party_type']] ?? ucfirst($p['party_type'])
        ];
    }, $parties)); ?>;

    let currentIdx = 0;
    const total = parties.length;

    function render() {
        document.querySelectorAll('.page').forEach((el, i) => {
            el.classList.toggle('active', i === currentIdx);
        });
        document.getElementById('partyCounter').textContent = (currentIdx + 1) + ' / ' + total;
        document.getElementById('partyRole').textContent = parties[currentIdx].role;
        document.getElementById('partyRole').className = 'party-role ' + parties[currentIdx].role.toLowerCase();
        document.getElementById('partyName').textContent = '· ' + parties[currentIdx].name;
        document.getElementById('prevBtn').disabled = currentIdx === 0;
        document.getElementById('nextBtn').disabled = currentIdx === total - 1;
    }

    function showPrev() { if (currentIdx > 0) { currentIdx--; render(); } }
    function showNext() { if (currentIdx < total - 1) { currentIdx++; render(); } }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowLeft')  showPrev();
        if (e.key === 'ArrowRight') showNext();
    });

    render();
</script>
</body>
</html>