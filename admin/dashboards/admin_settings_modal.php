
<style>
    /* ============ Personal Settings Modal ============ */
    .ps-modal { text-align: left; font-family: 'Segoe UI', Roboto, sans-serif; }
    .ps-popup { border-radius: 16px !important; padding: 0 !important; overflow: hidden; max-width: 820px !important; width: 95vw !important; }
    .ps-popup .swal2-html-container { margin: 0 !important; padding: 0 !important; }
    .ps-popup .swal2-actions { display: none !important; }
    .ps-popup .swal2-title { display: none !important; }

    .ps-header {
        background: linear-gradient(135deg, #1b5e20, #2E7D32 55%, #43a047);
        color: #fff; padding: 18px 24px;
        display: flex; align-items: center; gap: 14px;
    }
    .ps-header i { font-size: 1.8rem; opacity: 0.9; }
    .ps-header-title { font-size: 1.15rem; font-weight: 700; }
    .ps-header-sub { font-size: 0.8rem; opacity: 0.85; margin-top: 2px; }

    .ps-body { background: #f8faf8; max-height: 80vh; overflow-y: auto; }

    /* -------- Tabs -------- */
    .ps-tabs {
        display: flex; gap: 4px; background: #fff;
        padding: 8px 16px 0; border-bottom: 2px solid #e2efe8;
        position: sticky; top: 0; z-index: 10;
    }
    .ps-tab {
        padding: 10px 18px; border-radius: 10px 10px 0 0;
        border: none; background: transparent; cursor: pointer;
        font-size: 0.85rem; font-weight: 700; color: #667;
        display: inline-flex; align-items: center; gap: 8px;
        transition: all 0.15s;
    }
    .ps-tab:hover { color: #2E7D32; background: #f1f8e9; }
    .ps-tab.active {
        background: #f1f8e9; color: #1b5e20;
        border-bottom: 3px solid #2E7D32;
    }
    .ps-tab i { font-size: 0.9rem; }

    .ps-tab-panel {
        padding: 24px 26px 26px;
        animation: psFadeIn .25s ease-out;
    }
    @keyframes psFadeIn {
        from { opacity: 0; transform: translateY(4px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    /* -------- Fields -------- */
    .ps-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    @media (max-width: 640px) { .ps-grid-2 { grid-template-columns: 1fr; } }

    .ps-field { margin-bottom: 16px; }
    .ps-field label {
        display: block; font-weight: 600; font-size: 0.85rem;
        color: #1a472a; margin-bottom: 6px;
    }
    .ps-field label .req { color: #c62828; }
    .ps-field label .opt { color: #999; font-weight: 400; font-size: 0.75rem; }
    .ps-field input,
    .ps-field select,
    .ps-field textarea {
        width: 100%; padding: 10px 12px;
        border: 1.5px solid #cfd8dc; border-radius: 8px;
        font-size: 0.92rem; font-family: inherit; box-sizing: border-box;
        background: #fff;
    }
    .ps-field input:focus,
    .ps-field select:focus,
    .ps-field textarea:focus {
        outline: none; border-color: #2E7D32;
        box-shadow: 0 0 0 3px rgba(46,125,50,0.12);
    }
    .ps-hint { font-size: 0.72rem; color: #888; margin-top: 5px; line-height: 1.4; }

    /* -------- Buttons -------- */
    .ps-btn-row { display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end; margin-top: 8px; }
    .ps-btn {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 10px 20px; border: none; border-radius: 8px;
        font-size: 0.85rem; font-weight: 700; cursor: pointer;
        transition: all .15s;
    }
    .ps-btn.primary { background: #2E7D32; color: #fff; }
    .ps-btn.primary:hover { background: #1b5e20; }
    .ps-btn.primary:disabled { background: #a5d6a7; cursor: not-allowed; }
    .ps-btn.secondary { background: #6c757d; color: #fff; }
    .ps-btn.secondary:hover { background: #5a6268; }
    .ps-btn.danger { background: #c62828; color: #fff; }
    .ps-btn.danger:hover { background: #8e0000; }
    .ps-btn.ghost { background: #fff; color: #2E7D32; border: 1.5px solid #2E7D32; }
    .ps-btn.ghost:hover { background: #f1f8e9; }

    /* -------- Signature area -------- */
    .ps-sig-drop {
        border: 2px dashed #cfd8dc; border-radius: 12px;
        padding: 24px; text-align: center;
        background: #fff; cursor: pointer;
        transition: all .18s;
    }
    .ps-sig-drop:hover, .ps-sig-drop.dragover {
        border-color: #2E7D32; background: #f1f8e9;
    }
    .ps-sig-drop i { font-size: 2.2rem; color: #2E7D32; margin-bottom: 8px; display: block; }
    .ps-sig-drop-title { font-weight: 700; color: #1a472a; font-size: 0.9rem; }
    .ps-sig-drop-sub { font-size: 0.75rem; color: #777; margin-top: 4px; }

    .ps-sig-preview {
        margin-top: 16px; padding: 16px;
        background: #fff; border: 1.5px solid #e2efe8; border-radius: 10px;
        display: none;
    }
    .ps-sig-preview.show { display: block; }
    .ps-sig-preview-title {
        font-size: 0.72rem; text-transform: uppercase;
        color: #666; letter-spacing: 0.4px; font-weight: 700;
        margin-bottom: 10px;
    }
    .ps-sig-canvas-wrap {
        background:
            repeating-conic-gradient(#f0f0f0 0% 25%, #ffffff 0% 50%) 50% / 16px 16px;
        border-radius: 8px; padding: 12px;
        display: flex; justify-content: center; align-items: center;
        min-height: 110px;
        border: 1px dashed #ccc;
    }
    .ps-sig-canvas-wrap img,
    .ps-sig-canvas-wrap canvas {
        max-width: 100%; max-height: 120px; object-fit: contain;
        display: block;
    }
    .ps-sig-toggles { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 12px; align-items: center; }
    .ps-sig-toggles label {
        display: inline-flex; align-items: center; gap: 8px;
        font-size: 0.82rem; color: #333; cursor: pointer;
    }
    .ps-sig-tolerance { display: inline-flex; align-items: center; gap: 8px; margin-left: auto; }
    .ps-sig-tolerance input[type="range"] { width: 100px; }

    /* -------- Photo -------- */
    .ps-photo-row { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
    .ps-photo-preview {
        width: 96px; height: 96px; border-radius: 50%;
        background: #f3f6f4; display: flex; align-items: center; justify-content: center;
        overflow: hidden; border: 2px solid #e2efe8; flex-shrink: 0;
    }
    .ps-photo-preview img { width: 100%; height: 100%; object-fit: cover; }
    .ps-photo-preview i { font-size: 2.4rem; color: #b0bec5; }
</style>

<!-- ============ Modal is built dynamically by JS ============ -->
<script>
// ============================================================
//  Personal Settings — Controller
// ============================================================
(function() {
    'use strict';

    const AJAX = '../admin_settings_ajax.php';
    let PS = {
        profile: null,
        signatureUrl: null,
        photoUrl: null,
        cleanedSignatureBlob: null,   // PNG blob after bg removal
        currentTab: 'info',
    };

    /* -------------------------------------------------------- */
    /*  Helpers                                                 */
    /* -------------------------------------------------------- */
    function esc(s) {
        if (s === null || s === undefined) return '';
        const d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    function toast(icon, title) {
        Swal.fire({
            toast: true, position: 'top-end', icon, title,
            showConfirmButton: false, timer: 2200,
            didOpen: (t) => t.addEventListener('click', e => e.stopPropagation())
        });
    }

    /* -------------------------------------------------------- */
    /*  Public: openPersonalSettings()                          */
    /* -------------------------------------------------------- */
    window.openPersonalSettings = function() {
        // Reset tab to Info each time
        PS.currentTab = 'info';
        PS.cleanedSignatureBlob = null;

        Swal.fire({
            html: buildShell(),
            width: 820,
            allowOutsideClick: false,
            allowEscapeKey: true,
            showConfirmButton: false,
            showCancelButton: false,
            customClass: { popup: 'ps-popup' },
            didOpen: async () => {
                bindTabs();
                bindCloseButton();
                await loadProfile();
                renderTab('info');
            }
        });
    };

    /* -------------------------------------------------------- */
    /*  Modal Shell                                             */
    /* -------------------------------------------------------- */
    function buildShell() {
        return `
        <div class="ps-modal">
            <div class="ps-header">
                <i class="fas fa-user-cog"></i>
                <div style="flex:1;">
                    <div class="ps-header-title">Personal Settings</div>
                    <div class="ps-header-sub">Manage your account, password, signature, and profile photo</div>
                </div>
                <button type="button" id="psCloseBtn"
                    style="background:rgba(255,255,255,0.2);border:none;color:#fff;width:38px;height:38px;border-radius:50%;cursor:pointer;font-size:1.05rem;"
                    title="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="ps-body">
                <div class="ps-tabs">
                    <button type="button" class="ps-tab active" data-tab="info">
                        <i class="fas fa-id-card"></i> Profile Info
                    </button>
                    <button type="button" class="ps-tab" data-tab="password">
                        <i class="fas fa-lock"></i> Password
                    </button>
                    <button type="button" class="ps-tab" data-tab="signature">
                        <i class="fas fa-signature"></i> Signature
                    </button>
                    <button type="button" class="ps-tab" data-tab="photo">
                        <i class="fas fa-camera"></i> Photo
                    </button>
                </div>
                <div id="psTabPanel" class="ps-tab-panel"></div>
            </div>
        </div>`;
    }

    function bindTabs() {
        document.querySelectorAll('.ps-tab').forEach(btn => {
            btn.addEventListener('click', () => {
                const tab = btn.dataset.tab;
                if (!tab || tab === PS.currentTab) return;
                PS.currentTab = tab;
                document.querySelectorAll('.ps-tab').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                renderTab(tab);
            });
        });
    }

    function bindCloseButton() {
        const close = document.getElementById('psCloseBtn');
        if (close) close.addEventListener('click', () => Swal.close());
    }

    /* -------------------------------------------------------- */
    /*  Load profile                                            */
    /* -------------------------------------------------------- */
    async function loadProfile() {
        try {
            const r = await fetch(`${AJAX}?action=get_profile`);
            const d = await r.json();
            if (!d.success) throw new Error(d.message || 'Failed');
            PS.profile = d.profile || {};
            PS.signatureUrl = d.signature_url || null;
            PS.photoUrl = d.photo_url || null;
        } catch (e) {
            PS.profile = {};
        }
    }

    /* -------------------------------------------------------- */
    /*  Render a tab                                            */
    /* -------------------------------------------------------- */
    function renderTab(tab) {
        const panel = document.getElementById('psTabPanel');
        if (!panel) return;
        if (tab === 'info')      panel.innerHTML = tplInfo();
        if (tab === 'password')  panel.innerHTML = tplPassword();
        if (tab === 'signature') panel.innerHTML = tplSignature();
        if (tab === 'photo')     panel.innerHTML = tplPhoto();

        if (tab === 'info')      bindInfo();
        if (tab === 'password')  bindPassword();
        if (tab === 'signature') bindSignature();
        if (tab === 'photo')     bindPhoto();
    }

    /* ============================================================ */
    /*  TAB 1 — Profile Info                                        */
    /* ============================================================ */
    function tplInfo() {
        const p = PS.profile || {};
        return `
        <form id="psInfoForm" autocomplete="off">
            <div class="ps-grid-2">
                <div class="ps-field">
                    <label>Full Name <span class="req">*</span></label>
                    <input type="text" id="psFullName" value="${esc(p.full_name)}" required>
                </div>
                <div class="ps-field">
                    <label>Suffix <span class="opt">(Jr., Sr., III)</span></label>
                    <input type="text" id="psSuffix" value="${esc(p.suffix || '')}">
                </div>
                <div class="ps-field">
                    <label>Email <span class="req">*</span></label>
                    <input type="email" id="psEmail" value="${esc(p.email)}" required>
                </div>
                <div class="ps-field">
                    <label>Phone Number</label>
                    <input type="tel" id="psPhone" value="${esc(p.phone_number || '')}">
                </div>
                <div class="ps-field">
                    <label>Birth Date</label>
                    <input type="date" id="psBirthDate" value="${esc(p.birth_date || '')}">
                </div>
                <div class="ps-field">
                    <label>Birth Place</label>
                    <input type="text" id="psBirthPlace" value="${esc(p.birth_place || '')}">
                </div>
                <div class="ps-field">
                    <label>Gender</label>
                    <select id="psGender">
                        <option value="">—</option>
                        <option value="male"   ${p.gender === 'male'   ? 'selected' : ''}>Male</option>
                        <option value="female" ${p.gender === 'female' ? 'selected' : ''}>Female</option>
                        <option value="other"  ${p.gender === 'other'  ? 'selected' : ''}>Other</option>
                    </select>
                </div>
                <div class="ps-field">
                    <label>Civil Status</label>
                    <select id="psCivilStatus">
                        <option value="">—</option>
                        <option value="single"    ${p.civil_status === 'single'    ? 'selected' : ''}>Single</option>
                        <option value="married"   ${p.civil_status === 'married'   ? 'selected' : ''}>Married</option>
                        <option value="widowed"   ${p.civil_status === 'widowed'   ? 'selected' : ''}>Widowed</option>
                        <option value="divorced"  ${p.civil_status === 'divorced'  ? 'selected' : ''}>Divorced</option>
                        <option value="separated" ${p.civil_status === 'separated' ? 'selected' : ''}>Separated</option>
                    </select>
                </div>
                <div class="ps-field">
                    <label>Religion</label>
                    <input type="text" id="psReligion" value="${esc(p.religion || '')}">
                </div>
                <div class="ps-field">
                    <label>Occupation</label>
                    <input type="text" id="psOccupation" value="${esc(p.occupation || '')}">
                </div>
            </div>

            <div class="ps-btn-row">
                <button type="submit" class="ps-btn primary" id="psInfoSave">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>`;
    }

    function bindInfo() {
        const form = document.getElementById('psInfoForm');
        if (!form) return;
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('psInfoSave');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

            const fd = new FormData();
            fd.append('action', 'update_profile');
            fd.append('full_name',    document.getElementById('psFullName').value.trim());
            fd.append('email',        document.getElementById('psEmail').value.trim());
            fd.append('phone_number', document.getElementById('psPhone').value.trim());
            fd.append('suffix',       document.getElementById('psSuffix').value.trim());
            fd.append('birth_date',   document.getElementById('psBirthDate').value);
            fd.append('birth_place',  document.getElementById('psBirthPlace').value.trim());
            fd.append('gender',       document.getElementById('psGender').value);
            fd.append('civil_status', document.getElementById('psCivilStatus').value);
            fd.append('religion',     document.getElementById('psReligion').value.trim());
            fd.append('occupation',   document.getElementById('psOccupation').value.trim());

            try {
                const r = await fetch(AJAX, { method: 'POST', body: fd });
                const d = await r.json();
                if (d.success) {
                    toast('success', d.message || 'Profile updated.');
                    // Refresh the top-bar name without reloading the page
                    document.querySelectorAll('.profile-text .name').forEach(el => {
                        el.textContent = d.profile.full_name;
                    });
                } else {
                    toast('error', d.message || 'Update failed.');
                }
            } catch (err) {
                toast('error', 'Network error.');
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
        });
    }

    /* ============================================================ */
    /*  TAB 2 — Change Password                                     */
    /* ============================================================ */
    function tplPassword() {
        return `
        <form id="psPasswordForm" autocomplete="off">
            <div class="ps-field">
                <label>Current Password <span class="req">*</span></label>
                <input type="password" id="psCurrentPass" required>
            </div>
            <div class="ps-field">
                <label>New Password <span class="req">*</span></label>
                <input type="password" id="psNewPass" required minlength="8">
                <div class="ps-hint"><i class="fas fa-info-circle"></i> At least 8 characters. Use a mix of letters, numbers, and symbols for security.</div>
            </div>
            <div class="ps-field">
                <label>Confirm New Password <span class="req">*</span></label>
                <input type="password" id="psConfirmPass" required minlength="8">
                <div class="ps-hint" id="psMatchHint"></div>
            </div>

            <div class="ps-btn-row">
                <button type="submit" class="ps-btn primary" id="psPassSave">
                    <i class="fas fa-key"></i> Update Password
                </button>
            </div>
        </form>`;
    }

    function bindPassword() {
        const form = document.getElementById('psPasswordForm');
        if (!form) return;

        const nEl = document.getElementById('psNewPass');
        const cEl = document.getElementById('psConfirmPass');
        const hint = document.getElementById('psMatchHint');

        function checkMatch() {
            if (!cEl.value) { hint.textContent = ''; return; }
            if (nEl.value === cEl.value) {
                hint.innerHTML = '<span style="color:#2E7D32;"><i class="fas fa-check"></i> Passwords match.</span>';
            } else {
                hint.innerHTML = '<span style="color:#c62828;"><i class="fas fa-times"></i> Passwords do not match.</span>';
            }
        }
        nEl.addEventListener('input', checkMatch);
        cEl.addEventListener('input', checkMatch);

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('psPassSave');

            const cur = document.getElementById('psCurrentPass').value;
            const nw  = nEl.value;
            const cf  = cEl.value;

            if (nw !== cf) {
                toast('warning', 'New password and confirmation do not match.');
                return;
            }
            if (nw.length < 8) {
                toast('warning', 'New password must be at least 8 characters.');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating…';

            const fd = new FormData();
            fd.append('action', 'change_password');
            fd.append('current_password', cur);
            fd.append('new_password',     nw);
            fd.append('confirm_password', cf);

            try {
                const r = await fetch(AJAX, { method: 'POST', body: fd });
                const d = await r.json();
                if (d.success) {
                    toast('success', d.message || 'Password updated.');
                    form.reset();
                    hint.textContent = '';
                } else {
                    toast('error', d.message || 'Update failed.');
                }
            } catch (err) {
                toast('error', 'Network error.');
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-key"></i> Update Password';
        });
    }

    /* ============================================================ */
    /*  TAB 3 — Signature (with background remover)                 */
    /* ============================================================ */
    function tplSignature() {
        const current = PS.signatureUrl;
        return `
        <div class="ps-field">
            <label>Current Signature <span class="opt">(appears above your name on KP Forms)</span></label>
            <div class="ps-sig-canvas-wrap" id="psCurrentSigWrap">
                ${current
                    ? `<img src="${esc(current)}?t=${Date.now()}" alt="Current signature">`
                    : `<div style="color:#999;font-size:0.85rem;padding:12px;">No signature uploaded yet.</div>`}
            </div>
            ${current ? `
                <div class="ps-btn-row" style="margin-top:10px;">
                    <button type="button" class="ps-btn danger" id="psRemoveSig">
                        <i class="fas fa-trash"></i> Remove Current Signature
                    </button>
                </div>` : ''}
        </div>

        <div class="ps-field">
            <label>Upload New Signature</label>
            <div class="ps-sig-drop" id="psSigDrop">
                <i class="fas fa-cloud-upload-alt"></i>
                <div class="ps-sig-drop-title">Click or drop an image here</div>
                <div class="ps-sig-drop-sub">PNG preferred. JPG/WebP accepted — we can clean the background for you.</div>
                <input type="file" id="psSigFile" accept="image/png,image/jpeg,image/webp" style="display:none;">
            </div>
        </div>

        <div class="ps-sig-preview" id="psSigPreview">
            <div class="ps-sig-preview-title">Preview (background removed)</div>
            <div class="ps-sig-canvas-wrap">
                <img id="psSigPreviewImg" alt="Signature preview">
            </div>
            <div class="ps-sig-toggles">
                <label>
                    <input type="checkbox" id="psSigRemoveBg" checked>
                    <span>Remove white background</span>
                </label>
                <label class="ps-sig-tolerance">
                    <span style="font-size:0.75rem;color:#666;">Tolerance</span>
                    <input type="range" id="psSigTolerance" min="0" max="120" value="40">
                </label>
            </div>
            <div class="ps-btn-row" style="margin-top:14px;">
                <button type="button" class="ps-btn secondary" id="psSigCancel">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="ps-btn primary" id="psSigUpload">
                    <i class="fas fa-save"></i> Save Signature
                </button>
            </div>
        </div>`;
    }

    let originalSignatureImage = null;   // Image object of the uploaded file

    function bindSignature() {
        const drop = document.getElementById('psSigDrop');
        const input = document.getElementById('psSigFile');
        const preview = document.getElementById('psSigPreview');
        const previewImg = document.getElementById('psSigPreviewImg');
        const removeBg = document.getElementById('psSigRemoveBg');
        const tolerance = document.getElementById('psSigTolerance');
        const cancelBtn = document.getElementById('psSigCancel');
        const uploadBtn = document.getElementById('psSigUpload');
        const removeSig = document.getElementById('psRemoveSig');

        if (!drop || !input) return;

        // Open file picker
        drop.addEventListener('click', () => input.click());

        // Drag-and-drop
        ['dragenter','dragover'].forEach(ev => {
            drop.addEventListener(ev, (e) => {
                e.preventDefault(); e.stopPropagation();
                drop.classList.add('dragover');
            });
        });
        ['dragleave','drop'].forEach(ev => {
            drop.addEventListener(ev, (e) => {
                e.preventDefault(); e.stopPropagation();
                drop.classList.remove('dragover');
            });
        });
        drop.addEventListener('drop', (e) => {
            const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (f) handleSignatureFile(f);
        });

        input.addEventListener('change', () => {
            if (input.files && input.files[0]) handleSignatureFile(input.files[0]);
        });

        function handleSignatureFile(file) {
            if (!file.type.startsWith('image/')) {
                toast('error', 'Please choose an image file.');
                return;
            }
            if (file.size > 2 * 1024 * 1024) {
                toast('error', 'Signature must be 2 MB or less.');
                return;
            }
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => {
                originalSignatureImage = img;
                preview.classList.add('show');
                renderSignaturePreview();
            };
            img.onerror = () => toast('error', 'Could not read that image.');
            img.src = url;
        }

        function renderSignaturePreview() {
            if (!originalSignatureImage) return;
            const doRemove = removeBg.checked;
            const tol = parseInt(tolerance.value, 10);

            const canvas = document.createElement('canvas');
            canvas.width = originalSignatureImage.naturalWidth;
            canvas.height = originalSignatureImage.naturalHeight;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(originalSignatureImage, 0, 0);

            if (doRemove) {
                try {
                    const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                    const d = imgData.data;
                    for (let i = 0; i < d.length; i += 4) {
                        const r = d[i], g = d[i+1], b = d[i+2];
                        // Near-white check: all channels above (255 - tol)
                        const thresh = 255 - tol;
                        if (r >= thresh && g >= thresh && b >= thresh) {
                            d[i+3] = 0;
                        }
                    }
                    ctx.putImageData(imgData, 0, 0);
                } catch (e) {
                    // tainted canvas fallback: skip removal silently
                }
            }

            canvas.toBlob((blob) => {
                if (!blob) return;
                PS.cleanedSignatureBlob = blob;
                const url = URL.createObjectURL(blob);
                previewImg.src = url;
            }, 'image/png');
        }

        removeBg.addEventListener('change', renderSignaturePreview);
        tolerance.addEventListener('input', renderSignaturePreview);

        cancelBtn.addEventListener('click', () => {
            preview.classList.remove('show');
            input.value = '';
            PS.cleanedSignatureBlob = null;
            originalSignatureImage = null;
        });

        uploadBtn.addEventListener('click', async () => {
            if (!PS.cleanedSignatureBlob) {
                toast('warning', 'Please choose an image first.');
                return;
            }
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading…';

            const fd = new FormData();
            fd.append('action', 'upload_signature');
            fd.append('signature', PS.cleanedSignatureBlob, 'signature.png');

            try {
                const r = await fetch(AJAX, { method: 'POST', body: fd });
                const d = await r.json();
                if (d.success) {
                    toast('success', d.message || 'Signature uploaded.');
                    PS.signatureUrl = d.signature_url;
                    // Refresh the Signature tab
                    document.getElementById('psTabPanel').innerHTML = tplSignature();
                    bindSignature();
                } else {
                    toast('error', d.message || 'Upload failed.');
                }
            } catch (err) {
                toast('error', 'Network error.');
            }
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = '<i class="fas fa-save"></i> Save Signature';
        });

        if (removeSig) {
            removeSig.addEventListener('click', async () => {
                const c = await Swal.fire({
                    icon: 'warning',
                    title: 'Remove signature?',
                    text: 'Your signature will no longer appear on KP Forms.',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, remove',
                    confirmButtonColor: '#c62828',
                    cancelButtonText: 'Cancel',
                    cancelButtonColor: '#6c757d'
                });
                if (!c.isConfirmed) return;

                const fd = new FormData();
                fd.append('action', 'remove_signature');
                try {
                    const r = await fetch(AJAX, { method: 'POST', body: fd });
                    const d = await r.json();
                    if (d.success) {
                        toast('success', d.message || 'Signature removed.');
                        PS.signatureUrl = null;
                        document.getElementById('psTabPanel').innerHTML = tplSignature();
                        bindSignature();
                    } else {
                        toast('error', d.message || 'Remove failed.');
                    }
                } catch (err) {
                    toast('error', 'Network error.');
                }
            });
        }
    }

    /* ============================================================ */
    /*  TAB 4 — Profile Photo                                       */
    /* ============================================================ */
    function tplPhoto() {
        const url = PS.photoUrl;
        return `
        <div class="ps-photo-row">
            <div class="ps-photo-preview" id="psPhotoPreview">
                ${url
                    ? `<img src="${esc(url)}?t=${Date.now()}" alt="Profile photo">`
                    : `<i class="fas fa-user"></i>`}
            </div>
            <div style="flex:1; min-width:220px;">
                <div class="ps-field" style="margin-bottom:10px;">
                    <label>Upload Profile Photo</label>
                    <input type="file" id="psPhotoFile" accept="image/png,image/jpeg,image/webp">
                    <div class="ps-hint"><i class="fas fa-info-circle"></i> Max 5 MB. Square images look best.</div>
                </div>
                <div class="ps-btn-row">
                    ${url ? `<button type="button" class="ps-btn danger" id="psRemovePhoto">
                                <i class="fas fa-trash"></i> Remove Photo
                             </button>` : ''}
                </div>
            </div>
        </div>`;
    }

    function bindPhoto() {
        const input = document.getElementById('psPhotoFile');
        const preview = document.getElementById('psPhotoPreview');
        const removeBtn = document.getElementById('psRemovePhoto');
        if (!input) return;

        input.addEventListener('change', async () => {
            if (!input.files || !input.files[0]) return;
            const f = input.files[0];
            if (f.size > 5 * 1024 * 1024) {
                toast('error', 'Photo must be 5 MB or less.');
                return;
            }

            // Immediate preview
            const url = URL.createObjectURL(f);
            preview.innerHTML = `<img src="${url}" alt="preview">`;

            const fd = new FormData();
            fd.append('action', 'upload_profile_photo');
            fd.append('photo', f);

            try {
                const r = await fetch(AJAX, { method: 'POST', body: fd });
                const d = await r.json();
                if (d.success) {
                    toast('success', d.message || 'Photo uploaded.');
                    PS.photoUrl = d.photo_url;
                    // Update the top-bar avatar
                    document.querySelectorAll('.avatar-container').forEach(av => {
                        av.innerHTML = `<img src="${esc(d.photo_url)}?t=${Date.now()}" alt="photo" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">`;
                    });
                    // Re-render to show the Remove button
                    document.getElementById('psTabPanel').innerHTML = tplPhoto();
                    bindPhoto();
                } else {
                    toast('error', d.message || 'Upload failed.');
                    preview.innerHTML = PS.photoUrl
                        ? `<img src="${esc(PS.photoUrl)}" alt="photo">`
                        : `<i class="fas fa-user"></i>`;
                }
            } catch (err) {
                toast('error', 'Network error.');
            }
        });

        if (removeBtn) {
            removeBtn.addEventListener('click', async () => {
                const c = await Swal.fire({
                    icon: 'warning',
                    title: 'Remove profile photo?',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, remove',
                    confirmButtonColor: '#c62828',
                    cancelButtonText: 'Cancel',
                    cancelButtonColor: '#6c757d'
                });
                if (!c.isConfirmed) return;

                const fd = new FormData();
                fd.append('action', 'remove_profile_photo');
                try {
                    const r = await fetch(AJAX, { method: 'POST', body: fd });
                    const d = await r.json();
                    if (d.success) {
                        toast('success', d.message || 'Photo removed.');
                        PS.photoUrl = null;
                        document.getElementById('psTabPanel').innerHTML = tplPhoto();
                        bindPhoto();
                    } else {
                        toast('error', d.message || 'Remove failed.');
                    }
                } catch (err) {
                    toast('error', 'Network error.');
                }
            });
        }
    }

})();
</script>