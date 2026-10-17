// resident_complaint.js
// Barangay San Bartolome Katarungang Pambarangay Classifier
// Role-aware complaint view + card view + filter chips + documentable UI + live hearing timer
//
// ⚠️  REQUIRES: complaint_keywords.js MUST be loaded BEFORE this file.

let currentComplaintId = null;
let complainantsList = [];
let respondentsList = [];
let witnessesList = [];
let incidentsList = [];

let suppressSubjectChangeEvent = false;
let userInteractedWithSubject = false;

// Role-aware tabs
let currentComplaintTab = 'complainant';
let currentStageFilter = 'all';   // 'all' | stage key from COMPLAINT_STAGES
let cachedComplaints = { as_complainant: [], as_respondent: [] };

let __liveTimerInterval = null;

const COMPLAINT_STAGES = {
    all: {
        label: 'All', icon: 'fa-list', color: '#2E7D32',
        description: 'All complaints', statuses: null,
    },

    intake: {
        label: 'Under Review', icon: 'fa-file-alt', color: '#f57c00',
        description: 'Filed and awaiting Captain\'s decision',
        statuses: ['pending_review', 'pending_captain_action', 'pending_captain_review'],
        actor: 'Secretary / Captain', forRole: 'both',
    },

    notice_issued: {
        label: 'Notice Issued', icon: 'fa-envelope', color: '#1565c0',
        description: 'Notice of Hearing issued — schedule pending',
        statuses: ['summoned'],
        actor: 'Captain', forRole: 'complainant',
    },

    summoned: {
        label: 'Summoned', icon: 'fa-envelope-open-text', color: '#1565c0',
        description: 'Summons issued by Captain — you must appear',
        statuses: ['summoned'],
        actor: 'Captain', forRole: 'respondent',
    },

    mediation: {
        label: 'Mediation', icon: 'fa-handshake', color: '#6a1b9a',
        description: 'Lupon mediation in progress',
        statuses: ['for_mediation', 'mediation_scheduled', 'in_mediation'],
        actor: 'Captain / Lupon', forRole: 'both',
    },

    pangkat_constitution: {
        label: 'Pangkat Forming', icon: 'fa-users-cog', color: '#4a148c',
        description: 'Captain is constituting the Pangkat ng Tagapagkasundo',
        statuses: ['pangkat_constitution_scheduled', 'pangkat_constituted'],
        actor: 'Captain', forRole: 'both',
    },

    pangkat_conciliation: {
        label: 'Pangkat Hearing', icon: 'fa-gavel', color: '#00838f',
        description: 'Pangkat ng Tagapagkasundo is hearing the case',
        statuses: ['pangkat_scheduled'],
        actor: 'Pangkat', forRole: 'both',
    },

    settled_pending: {
        label: 'Settlement Pending', icon: 'fa-hourglass-half', color: '#2e7d32',
        description: 'Amicable settlement signed — 10-day repudiation window',
        statuses: ['settled_pending'],
        actor: 'Barangay', forRole: 'both',
    },

    arbitration_pending: {
        label: 'Arbitration Pending', icon: 'fa-hourglass-half', color: '#e65100',
        description: 'Agreement for Arbitration signed — 5-day repudiation window',
        statuses: ['arbitration_pending'],
        actor: 'Barangay', forRole: 'both',
    },

    repudiable: {
        label: 'Repudiation Open', icon: 'fa-gavel', color: '#c62828',
        description: 'Complaints whose repudiation window is still open',
        statuses: null,
        actor: 'Barangay', forRole: 'both',
    },

    resolved: {
        label: 'Resolved', icon: 'fa-check-double', color: '#1b5e20',
        description: 'Settled / released / certificate issued',
        statuses: ['settled', 'released', 'certificate_issued'],
        actor: 'Captain / Pangkat', forRole: 'both',
    },

    failed: {
        label: 'Unresolved', icon: 'fa-times-circle', color: '#b71c1c',
        description: 'Mediation and conciliation both failed — Certificate to File Action issued',
        statuses: ['unresolved'],
        actor: 'Pangkat', forRole: 'both',
    },

    referred: {
        label: 'Referred', icon: 'fa-paper-plane', color: '#8e24aa',
        description: 'Referred to proper agency (outside KP jurisdiction)',
        statuses: ['referred_dispatched', 'escalated', 'referred'],
        actor: 'Barangay / PNP / Court', forRole: 'both',
    },

    dismissed: {
        label: 'Dismissed', icon: 'fa-ban', color: '#555',
        description: 'Withdrawn or dropped',
        statuses: ['dismissed'],
        actor: 'Complainant / Barangay', forRole: 'both',
    },
};

const STATUS_TO_STAGE = (() => {
    const map = {};
    for (const [key, stage] of Object.entries(COMPLAINT_STAGES)) {
        if (!stage.statuses) continue;
        for (const s of stage.statuses) {
            if (!map[s]) map[s] = key;
        }
    }
    map['summoned']              = map['summoned']              || 'summoned';
    map['failed_mediation']      = map['failed_mediation']      || 'pangkat_constitution';
    map['failed_conciliation_final'] = 'failed';
    map['referred']              = 'referred';
    map['arbitration_pending']    = 'arbitration_pending';
    map['arbitration_repudiated'] = 'failed';
    return map;
})();

// ============================================================
// SECTION 0: VALIDATION HELPERS
// ============================================================

function _placeholders() { return window.VALIDATION_PLACEHOLDERS; }
function _junkTokens()   { return window.VALIDATION_JUNK_TOKENS; }

function validateContactNumberJS(v) {
    v = (v || '').trim();
    if (!/^\d+$/.test(v)) return false;
    if (v.length !== 11) return false;
    if (!v.startsWith('09')) return false;
    return true;
}

function validateEmailOrEmptyJS(v) {
    v = (v || '').trim();
    if (v === '') return true;
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
}

function validatePersonNameJS(name) {
    name = (name || '').trim();
    if (!name) return { ok: false, msg: 'Name is required.' };

    if (!/^[A-Za-zÀ-ÿÑñ\s'\.\-]+$/u.test(name)) {
        return { ok: false, msg: 'Name must contain letters only — no numbers or symbols.' };
    }
    if (name.length < 3) return { ok: false, msg: 'Name is too short.' };

    if (/(.)\1{2,}/u.test(name)) return { ok: false, msg: 'Name looks invalid — please type your real name.' };
    if (/^(.)\1+$/u.test(name))   return { ok: false, msg: 'Name looks invalid — please type your real name.' };

    const flat = name.toLowerCase().replace(/[^a-zñáéíóú]/g, '');
    if (!flat) return { ok: false, msg: 'Name looks invalid — please type your real name.' };

    if (!/[aeiouyáéíóú]/i.test(flat)) {
        return { ok: false, msg: 'Name looks invalid — please type your real name.' };
    }

    if (/[bcdfghjklmnpqrstvwxzñ]{4,}/i.test(flat)) {
        return { ok: false, msg: 'Name looks invalid — please type your real name.' };
    }

    if (flat.length >= 6) {
        const vowelCount = (flat.match(/[aeiouyáéíóú]/gi) || []).length;
        if (vowelCount / flat.length < 0.15) {
            return { ok: false, msg: 'Name looks invalid — please type your real name.' };
        }
    }

    if (/(.)\1\1/u.test(name)) {
        return { ok: false, msg: 'Name looks invalid — please type your real name.' };
    }

    const norm = name.toLowerCase().replace(/\s+/g, ' ').trim();
    const normNoSpace = norm.replace(/\s/g, '');
    const ph = _placeholders();
    if (ph.includes(norm) || ph.includes(normNoSpace)) {
        return { ok: false, msg: 'Please enter a real name — placeholders like "test" are not allowed.' };
    }

    const mash = window.KEYBOARD_MASHES;
    for (const word of norm.split(' ')) {
        if (mash.includes(word)) return { ok: false, msg: 'Name looks invalid — please type your real name.' };
    }

    return { ok: true, msg: '' };
}

function validateWordingJS(text, minLen, fieldLabel) {
    text = (text || '').trim();
    if (!text) return { ok: false, msg: fieldLabel + ' is required.' };
    if (text.length < minLen) return { ok: false, msg: `${fieldLabel} is too short (minimum ${minLen} characters).` };
    if (/(.)\1{3,}/u.test(text)) return { ok: false, msg: `${fieldLabel} contains repeated characters — please describe properly.` };

    let norm = text.toLowerCase().replace(/[^\p{L}\p{N}\s]/gu, ' ').replace(/\s+/g, ' ').trim();
    const stripped = norm.replace(/\s/g, '');
    const junk = _junkTokens();
    if (junk.includes(norm) || junk.includes(stripped)) {
        return { ok: false, msg: `${fieldLabel} looks invalid — please describe your complaint.` };
    }
    if (!/[aeiouáéíóú]/i.test(text)) {
        return { ok: false, msg: `${fieldLabel} looks invalid — please describe your complaint.` };
    }
    const tokens = norm.split(' ');
    if (tokens.length >= 4) {
        let run = 1, maxRun = 1, prev = tokens[0];
        for (let i = 1; i < tokens.length; i++) {
            if (tokens[i] === prev) { run++; maxRun = Math.max(maxRun, run); }
            else { run = 1; prev = tokens[i]; }
        }
        if (maxRun >= 4) return { ok: false, msg: `${fieldLabel} repeats the same word — please describe properly.` };
    }
    const profanity = ['bobo','tanga','gago','gaga','tarantado','tarantada','putangina',
        'putang ina','puta','pakyu','fuck','fuckyou','shit','bitch','bastard',
        'asshole','dick','pussy','ulol','engot','inutil','lintik','hayop ka',
        'leche','punyeta','peste','pokpok','burat','titi','puke','kantot',
        'jakol','bilat','bagay','pekpek'];
    const words = norm.split(' ').filter(w => w !== '');
    if (words.length > 0) {
        const allProfane = words.every(w => profanity.some(p => w.includes(p) || w === p));
        if (allProfane) return { ok: false, msg: `${fieldLabel} contains only profanity — please describe your complaint.` };
    }
    return { ok: true, msg: '' };
}

// ============================================================
// SECTION 0B: AUTO-PRIORITY ENGINE
// ============================================================

function _vulnerableVictimHarmedJS(desc) {
    desc = (desc || '').toLowerCase();

    const vuln = [
        'bata','batang','anak','sanggol','menor','minor','musmos','paslit',
        'estudyante','baby','newborn',
        'matanda','lolo','lola','senior','elderly','gurang',
        'babae','asawa','nanay','ina','ate','dalaga','dalagita',
        'pwd','may kapansanan','disabled','wheelchair','bulag','bingi','pilay',
        'child','kid','kids','children','minor','minors','baby','infant',
        'toddler','son','daughter','student','pupil',
        'elderly','old man','old woman','senior citizen','grandma','grandpa',
        'grandmother','grandfather',
        'woman','women','wife','mother','sister','girlfriend','partner','spouse',
        'pwd','disabled','handicapped','wheelchair-bound','blind','deaf','mute','lame'
    ];

    const harm = [
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

    for (const v of vuln) {
        if (!desc.includes(v)) continue;
        for (const h of harm) { if (desc.includes(h)) return true; }
    }
    return false;
}

function classifyAutoPriorityJS(description, subject, isEscalated) {
    const desc = (description || '').toLowerCase();
    const subj = (subject || '').toLowerCase().trim();

    if (isEscalated) return 'urgent';
    if (_vulnerableVictimHarmedJS(desc)) return 'high';

    const highSubjects = [
        'physical altercation','threat/intimidation','violence against women (vawc)',
        'child protection concern','drug related issue'
    ];
    if (highSubjects.includes(subj)) return 'high';

    const medSubjects = ['theft/robbery','illegal gambling','illegal structure'];
    if (medSubjects.includes(subj)) return 'medium';

    const lowSubjects = [
        'abandoned vehicle','obstruction on public way','noise complaint',
        'waste management','water supply issue','electrical problem',
        'animal complaint'
    ];
    if (lowSubjects.includes(subj)) return 'low';

    return 'medium';
}

function priorityMetaJS(p) {
    const map = {
        urgent: { label: 'URGENT', color: '#c62828', bg: '#ffebee', icon: 'fa-exclamation-triangle' },
        high:   { label: 'HIGH',   color: '#e65100', bg: '#fff3e0', icon: 'fa-arrow-up' },
        medium: { label: 'MEDIUM', color: '#f9a825', bg: '#fffde7', icon: 'fa-minus' },
        low:    { label: 'LOW',    color: '#43a047', bg: '#e8f5e9', icon: 'fa-arrow-down' }
    };
    return map[p] || map.medium;
}

let __priorityUpdateTimeout = null;

function updateAutoPriorityBadge() {
    if (__priorityUpdateTimeout) clearTimeout(__priorityUpdateTimeout);
    __priorityUpdateTimeout = setTimeout(() => {
        const descEl    = document.getElementById('complaintDescription');
        const subjectEl = document.getElementById('complaintSubject');
        const badgeEl   = document.getElementById('autoPriorityBadge');
        if (!badgeEl) return;

        const desc    = descEl ? descEl.value : '';
        const subject = subjectEl ? subjectEl.value : '';
        const escalation = detectEscalation(desc);
        const isEscalated = !!escalation;

        const p = classifyAutoPriorityJS(desc, subject, isEscalated);
        const meta = priorityMetaJS(p);

        let reason = '';
        if (isEscalated) {
            reason = `non-jurisdictional — ${ESCALATION_LABELS[escalation] || 'escalated case'}`;
        } else if (_vulnerableVictimHarmedJS(desc)) {
            reason = 'a vulnerable person is involved';
        } else if (['physical altercation','threat/intimidation','violence against women (vawc)','child protection concern','drug related issue'].includes(subject.toLowerCase().trim())) {
            reason = `serious subject — ${subject}`;
        } else if (['abandoned vehicle','obstruction on public way','noise complaint','waste management','water supply issue','electrical problem','animal complaint'].includes(subject.toLowerCase().trim())) {
            reason = `nuisance-level — ${subject}`;
        } else {
            reason = 'standard case';
        }

        badgeEl.innerHTML = `
            <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;
                        background:${meta.bg};border-left:4px solid ${meta.color};border-radius:10px;">
                <i class="fas ${meta.icon}" style="color:${meta.color};font-size:1.1rem;"></i>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.4px;font-weight:800;color:${meta.color};">
                        Auto-Priority
                    </div>
                    <div style="font-weight:800;color:${meta.color};font-size:0.95rem;">
                        ${meta.label}
                    </div>
                    <div style="font-size:0.78rem;color:#555;margin-top:2px;">
                        ${escapeHtml(reason)}
                    </div>
                </div>
            </div>`;
    }, 250);
}

// ============================================================
// SECTION 1: THE 45 LAWS
// ============================================================
const barangayLaws = window.BARANGAY_LAWS;

// ============================================================
// SECTION 2: STANDARD SUBJECTS
// ============================================================
const standardSubjects = window.STANDARD_SUBJECTS;
const standardToLaw = window.STANDARD_TO_LAW;

// ============================================================
// SECTION 3: VICTIM CONTEXT DETECTION
// ============================================================
const VICTIM_PATTERNS = window.VICTIM_PATTERNS;

function detectVictimContext(description) {
    const ctx = { minor: 0, senior: 0, woman: 0, pwd: 0, property: 0, public: 0 };
    for (const [type, patterns] of Object.entries(VICTIM_PATTERNS)) {
        for (const p of patterns) {
            if (p.includes(' ')) {
                if (description.includes(p)) ctx[type] += 3;
            } else {
                const re = new RegExp(`\\b${escapeRegex(p)}\\b`, 'i');
                if (re.test(description)) ctx[type] += 1;
            }
        }
    }
    return ctx;
}

// ============================================================
// SECTION 4: NON-JURISDICTIONAL DETECTION
// ============================================================
const ESCALATION_PATTERNS             = window.ESCALATION_PATTERNS;
const ESCALATION_LABELS               = window.ESCALATION_LABELS;
const ESCALATION_REFER_TO_SHORT       = window.ESCALATION_REFER_TO_SHORT;
const ESCALATION_SUBJECT              = window.ESCALATION_SUBJECT;
const ESCALATION_ACTIONS              = window.ESCALATION_ACTIONS;
const ANONYMOUS_ELIGIBLE_ESCALATIONS  = window.ANONYMOUS_ELIGIBLE_ESCALATIONS;

function detectEscalation(description) {
    if (!description) return null;
    const normalized = normalizeText(description);
    for (const [type, patterns] of Object.entries(ESCALATION_PATTERNS)) {
        for (const p of patterns) {
            const k = p.toLowerCase();
            const matched = k.includes(' ')
                ? normalized.includes(k)
                : new RegExp(`\\b${escapeRegex(k)}\\b`, 'i').test(normalized);
            if (matched) return type;
        }
    }
    return null;
}

// ============================================================
// SECTION 5: SCORING & CLASSIFICATION (POINTING SYSTEM)
// ============================================================
function normalizeText(text) {
    return (text || '').toLowerCase().replace(/[^\w\sáéíóúñ-]/g, ' ').replace(/\s+/g, ' ').trim();
}
function escapeRegex(str) { return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

function countKeywordMatches(description, keywords) {
    const W = window.KEYWORD_WEIGHTS || {};
    const wSingle = W.single ?? 1;
    const wPhrase2 = W.phrase2 ?? 4;
    const wPhrase3plus = W.phrase3plus ?? 7;
    const maxOcc = W.maxOccurrences ?? 2;
    if (!keywords || !keywords.length) return { score: 0, hits: 0, phraseHits: 0, matchedKeywords: [] };

    let score = 0, hits = 0, phraseHits = 0;
    const matchedKeywords = [];
    const seen = new Set();
    for (const raw of keywords) {
        const kw = normalizeText(raw);
        if (!kw || seen.has(kw)) continue;
        seen.add(kw);
        const parts = kw.split(/\s+/);
        const escaped = escapeRegex(kw).replace(/\ /g, '\\s+');
        const re = new RegExp(`(?:^|\\s)${escaped}(?=$|\\s)`, 'g');
        const matches = description.match(re);
        const count = matches ? Math.min(matches.length, maxOcc) : 0;
        if (!count) continue;
        const wordCount = parts.length;
        const weight = wordCount >= 3 ? wPhrase3plus : wordCount === 2 ? wPhrase2 : wSingle;
        const points = weight * count;
        score += points;
        hits++;
        if (wordCount >= 2) phraseHits++;
        matchedKeywords.push({ kw: raw, weight, count, points });
    }
    return { score, hits, phraseHits, matchedKeywords };
}
function lawVictimBonus(law, victimCtx) {
    if (!law.victim || law.victim.length === 0) return 0;
    let bonus = 0;
    for (const v of law.victim) {
        if (v === 'any') continue;
        if (victimCtx[v] && victimCtx[v] > 0) bonus += victimCtx[v] * 4;
    }
    return bonus;
}

function classifyDescriptionMulti(description) {
    if (!description || description.trim().length < 3) return [];

    const W = window.KEYWORD_WEIGHTS || {};
    const minScore = W.minScoreToShow ?? 1;
    const maxCand  = W.maxCandidates  ?? 15;

    const normalized = normalizeText(description);
    const victimCtx  = detectVictimContext(normalized);

    const allScored = barangayLaws.map(law => {
        const m = countKeywordMatches(normalized, law.keywords);
        const bonus = lawVictimBonus(law, victimCtx);
        return {
            law,
            score:      m.score + bonus,
            baseScore:  m.score,
            bonus,
            hits:       m.hits,
            phraseHits: m.phraseHits,
            matched:    m.matchedKeywords,
        };
    });

    // Require a contextual phrase or at least two distinct keyword clues.
    const withHits = allScored.filter(x => x.hits > 0 && (x.phraseHits > 0 || x.hits >= 2) && x.score >= minScore);
    if (withHits.length === 0) return [];

    withHits.sort((a, b) => {
        if (b.score      !== a.score)      return b.score      - a.score;
        if (b.hits       !== a.hits)       return b.hits       - a.hits;
        return b.phraseHits - a.phraseHits;
    });

    const top = withHits.slice(0, maxCand);
    const topScore = top[0].score;

    return top.map((x, idx) => ({
        article:         x.law.article,
        law_title:       x.law.title,
        description:     x.law.description || `Pumapatungkol sa ${x.law.title}`,
        score:           x.score,
        baseScore:       x.baseScore,
        bonus:           x.bonus,
        hits:            x.hits,
        phraseHits:      x.phraseHits,
        matchedKeywords: x.matched,
        confidence:      Math.min(99, Math.round((x.score / Math.max(topScore, 1)) * 88 + 10)),
        isBest:          idx === 0,
    }));
}

// ============================================================
// SECTION 5B: ANONYMITY
// ============================================================
function toggleAnonymityWarning() {
    const checkbox = document.getElementById('isAnonymous');
    const card     = document.getElementById('anonymityCard');
    const box      = document.getElementById('anonCheckbox');
    const icon     = document.getElementById('anonCheckIcon');
    const hint     = document.getElementById('anonymityHint');
    const warning  = document.getElementById('anonymityWarning');
    if (!checkbox || !card) return;
    const isAnon = checkbox.checked;

    if (box && icon) {
        if (isAnon) {
            box.style.background = 'linear-gradient(135deg, #2E7D32, #43a047)';
            box.style.borderColor = '#2E7D32';
            box.style.boxShadow = '0 0 0 4px rgba(46,125,50,0.15)';
            icon.style.opacity = '1';
            icon.style.transform = 'scale(1)';
        } else {
            box.style.background = '#fff';
            box.style.borderColor = '#bdbdbd';
            box.style.boxShadow = 'none';
            icon.style.opacity = '0';
            icon.style.transform = 'scale(0.5)';
        }
    }
    if (isAnon) {
        card.style.borderColor = '#2E7D32';
        card.style.background = 'linear-gradient(135deg, #e8f5e9 0%, #f1f8e9 100%)';
        card.style.boxShadow = '0 4px 16px rgba(46,125,50,0.12)';
    } else {
        card.style.borderColor = '#e0e0e0';
        card.style.background = 'linear-gradient(135deg, #fafafa 0%, #f5f5f5 100%)';
        card.style.boxShadow = 'none';
    }
    if (hint && warning) {
        if (isAnon) { hint.style.display = 'block'; warning.style.display = 'none'; }
        else { hint.style.display = 'none'; warning.style.display = 'block'; }
    }
}

function updateAnonymityVisibility(escalationType) {
    const group = document.getElementById('anonymityGroup');
    const checkbox = document.getElementById('isAnonymous');
    if (!group) return;
    const eligible = escalationType && ANONYMOUS_ELIGIBLE_ESCALATIONS.includes(escalationType);
    if (eligible) { group.style.display = 'block'; toggleAnonymityWarning(); }
    else { group.style.display = 'none'; if (checkbox) checkbox.checked = false; toggleAnonymityWarning(); }
}

// ============================================================
// SECTION 6: LAW INFO PANEL (Custom Buttons + Tooltips)
// ============================================================
let selectedLaws = []; // Array of selected articles

function updateSelectedLaws() {
    const titleInput = document.getElementById('complaintTitle');
    const subjectInput = document.getElementById('complaintSubject');
    if (!titleInput || !subjectInput) return;

    if (selectedLaws.length === 0) {
        titleInput.value = '';
        subjectInput.value = '';
        subjectInput.dataset.rawValue = '';
        return;
    }

    // 1. Build the Summary for Title & Subject
    const articles = selectedLaws.map(c => c.article).join(' / ');
    
    const shortTitles = selectedLaws.map(c => {
        let cleanTitle = c.law_title.replace(/\s*\(.*?\)\s*/g, ''); // Remove (Value ≤ P50)
        let words = cleanTitle.split(' ');
        return words.slice(0, 3).join(' '); // Take first 3 words
    });
    const uniqueSummaries = [...new Set(shortTitles)];
    const combinedTitle = `${articles} — ${uniqueSummaries.join(' & ')}`;

    // 2. Set the Read-Only Title
    titleInput.value = combinedTitle;

    // 3. Set the Read-Only Subject
    subjectInput.value = combinedTitle;
    
    // 4. Set the raw string value for the server
    const rawArticles = selectedLaws.map(c => `${c.article} - ${c.law_title}`).join(' | ');
    subjectInput.dataset.rawValue = rawArticles;
}

function showLawInfoPanel(article, title, reason, confidence, extraInfo, exempted, exemptionReason, allCandidates) {
    let panel = document.getElementById('lawInfoPanel');
    const subjectGroup = document.getElementById('complaintSubject')?.closest('.form-group');
    if (!subjectGroup) return;
    if (!panel) { panel = document.createElement('div'); panel.id = 'lawInfoPanel'; subjectGroup.appendChild(panel); }

    if (!article && (!allCandidates || allCandidates.length === 0)) {
        panel.style.cssText = `margin-top:12px;padding:14px 16px;background:linear-gradient(135deg,#fff8e1 0%,#ffecb3 100%);border-left:4px solid #ef6c00;border-radius:10px;`;
        panel.innerHTML = `<div style="display:flex;gap:10px;"><div style="font-size:24px;">🔍</div><div style="flex:1;"><div style="font-size:11px;color:#ef6c00;font-weight:700;text-transform:uppercase;">No Matching Law Found</div><div style="font-size:14px;color:#333;margin:4px 0;">${escapeHtml(reason || 'Could not identify the law from your description. Please add more detail.')}</div><div style="font-size:11px;color:#666;"><i class="fas fa-info-circle"></i> Try to include: (1) what happened, (2) who did it, (3) who it happened to, (4) when and where.</div></div></div>`;
        return;
    }

    if (!allCandidates || allCandidates.length === 0) {
        panel.innerHTML = '';
        return;
    }

    // ── Build the custom Button Rows for all matched laws ──
    const rows = allCandidates.map((c, i) => {
        const isChecked = selectedLaws.some(s => s.article === c.article);
        const bgColor = isChecked ? '#e8f5e9' : '#fff';
        const borderColor = isChecked ? '#2E7D32' : '#e2efe8';
        const textColor = isChecked ? '#1b5e20' : '#333';

        // Build the tooltip text
        const tooltipText = `Article: ${c.article}\nTitle: ${c.law_title}\nScore: ${c.score} pts\nConfidence: ${c.confidence}%\n\nDescription:\n${c.description}`;

        return `
            <div onclick="toggleLawSelection('${escapeHtml(c.article)}', event)" 
                 title="${escapeHtml(tooltipText)}"
                 style="display:flex;align-items:center;gap:12px;padding:12px 14px;margin-bottom:8px;
                        background:${bgColor}; border:2px solid ${borderColor}; border-radius:10px; 
                        cursor:pointer; transition:all 0.2s ease;"
                 onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(0,0,0,0.05)';"
                 onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                
                <!-- Custom Checkbox Button -->
                <div style="flex-shrink:0; width:22px; height:22px; border-radius:6px; 
                            border:2px solid ${isChecked ? '#2E7D32' : '#bdbdbd'}; 
                            background:${isChecked ? '#2E7D32' : '#fff'}; 
                            display:flex; align-items:center; justify-content:center; transition:all 0.2s;">
                    <i class="fas fa-check" style="color:#fff; font-size:12px; opacity:${isChecked ? '1' : '0'}; transform:scale(${isChecked ? '1' : '0.5'}); transition:all 0.2s;"></i>
                </div>

                <!-- Law Info -->
                <div style="flex:1; min-width:0;">
                    <div style="font-weight:700; font-size:0.88rem; color:${textColor}; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        ${escapeHtml(c.article)} — ${escapeHtml(c.law_title)}
                        ${i === 0 ? `<span style="background:#2E7D32;color:#fff;font-size:9px;padding:1px 6px;border-radius:8px;">Best Match</span>` : ''}
                    </div>
                    <div style="font-size:0.75rem; color:#666; margin-top:3px;">
                        Score: ${c.score} pts | Confidence: ${c.confidence}%
                    </div>
                </div>
            </div>
        `;
    }).join('');

    panel.style.cssText = `margin-top:12px;padding:16px;background:#f8faf8;border:1px solid #e2efe8;border-radius:10px;`;
    panel.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <div style="font-size:12px;color:#2E7D32;font-weight:800;text-transform:uppercase;letter-spacing:0.4px;">
                <i class="fas fa-list-check"></i> Matched Laws (Select applicable)
            </div>
            <div style="font-size:11px;color:#666;">Total: ${allCandidates.length}</div>
        </div>
        <div style="max-height:250px;overflow-y:auto;padding-right:4px;">
            ${rows}
        </div>
    `;

    // Auto-select the top candidate if none are selected yet
    if (selectedLaws.length === 0 && allCandidates.length > 0) {
        toggleLawSelection(allCandidates[0].article, { target: {} }, allCandidates);
    }
}

function toggleLawSelection(article, event, candidatesList = null) {
    // Prevent the click event from double-firing if the user clicked exactly on the custom checkbox div
    if (event.target.tagName === 'I') {
        event.stopPropagation();
    }

    let list = candidatesList;

    if (!list) {
        const descInput = document.getElementById('complaintDescription');
        if (descInput) {
            list = classifyDescriptionMulti(descInput.value.trim());
        }
    }

    if (!list) return;

    const law = list.find(c => c.article === article);
    if (!law) return;

    // Toggle logic
    const isAlreadySelected = selectedLaws.some(s => s.article === article);
    if (isAlreadySelected) {
        selectedLaws = selectedLaws.filter(s => s.article !== article);
    } else {
        selectedLaws.push(law);
    }

    // Re-render checkboxes to update highlighting
    showLawInfoPanel(law.article, law.law_title, '', law.confidence, '', false, '', list);

    // Update the read-only fields
    updateSelectedLaws();
}

function hideLawInfoPanel() { const panel = document.getElementById('lawInfoPanel'); if (panel) panel.remove(); }

// ============================================================
// SECTION 7: ESCALATION PANEL
// ============================================================
function showEscalationPanel(type) {
    const label = ESCALATION_LABELS[type] || 'Non-Jurisdictional Case';
    const info = ESCALATION_ACTIONS[type] || { referTo: 'Kaukulang ahensya', barangayDuty: ['Itatala ang insidente sa Barangay Blotter.','HINDI maaaring i-mediate.'] };
    let panel = document.getElementById('lawInfoPanel');
    const subjectGroup = document.getElementById('complaintSubject')?.closest('.form-group');
    if (!subjectGroup) return;
    if (!panel) { panel = document.createElement('div'); panel.id = 'lawInfoPanel'; subjectGroup.appendChild(panel); }

    panel.style.cssText = `margin-top:12px;padding:16px;background:linear-gradient(135deg,#ffebee 0%,#ffcdd2 100%);border-left:4px solid #c62828;border-radius:10px;`;
    panel.innerHTML = `<div style="display:flex;gap:12px;"><div style="font-size:32px;line-height:1;">🚨</div><div style="flex:1;"><div style="font-size:11px;color:#c62828;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">HINDI Sakop ng Katarungang Pambarangay</div><div style="font-size:15px;font-weight:700;margin-bottom:6px;color:#b71c1c;">${escapeHtml(label)}</div><div style="font-size:13px;color:#333;margin-bottom:10px;line-height:1.5;">Ang kasong ito ay <strong>hindi maaaring i-mediate</strong> ng Lupong Tagapamayapa. Ito ay kabilang sa mga kasong hindi sakop ng Barangay Justice System (RA 7160, §408-422).</div><div style="font-size:12px;color:#333;background:rgba(255,255,255,0.75);padding:10px 12px;border-radius:8px;margin-bottom:8px;"><strong style="color:#c62828;">I-refer sa:</strong> ${escapeHtml(info.referTo)}</div><div style="font-size:12px;color:#333;background:rgba(255,255,255,0.75);padding:10px 12px;border-radius:8px;line-height:1.6;"><strong style="color:#1B5E20;display:block;margin-bottom:6px;">Ano ang gagawin ng Barangay:</strong><ol style="margin:0;padding-left:20px;">${info.barangayDuty.map(a => `<li>${a}</li>`).join('')}</ol></div><div style="font-size:11px;color:#666;margin-top:8px;"><i class="fas fa-info-circle"></i> Ang Barangay ay <strong>magtatala, mag-re-refer, at magpoprotekta</strong> — ngunit <strong>hindi magme-mediate</strong>.</div></div></div>`;
}

// ============================================================
// SECTION 8: MANUAL DROPDOWN (Disabled)
// ============================================================
// The subject is now a read-only input, so this event listener is no longer needed for the dropdown.

// ============================================================
// SECTION 9: MAIN DESCRIPTION HANDLER
// ============================================================
let analysisTimeout = null;

function onDescriptionChange() {
    const descInput = document.getElementById('complaintDescription');
    if (!descInput) return;
    const description = descInput.value.trim();
    const subjectInput = document.getElementById('complaintSubject');
    const titleInput    = document.getElementById('complaintTitle');
    if (!subjectInput) return;

    // ── Reset selections on new input ──
    selectedLaws = [];

    if (description.length < 5) {
        subjectInput.value = '';
        subjectInput.dataset.rawValue = '';
        if (titleInput) titleInput.value = '';
        hideLawInfoPanel();
        clearTitleSuggestion();
        updateAnonymityVisibility(null);
        return;
    }

    if (analysisTimeout) clearTimeout(analysisTimeout);
    analysisTimeout = setTimeout(() => { applyClassification(description); }, 350);
}

function applyClassification(description) {
    const subjectInput = document.getElementById('complaintSubject');
    const titleInput    = document.getElementById('complaintTitle');
    if (!subjectInput) return;

    // ── 1. Escalation check (non-jurisdictional) ──
    const escalation = detectEscalation(description);
    if (escalation) {
        const subject      = ESCALATION_SUBJECT[escalation] || 'Other Concern';
        const referToShort = ESCALATION_REFER_TO_SHORT[escalation] || 'Proper Government Agency';
        const label        = ESCALATION_LABELS[escalation] || 'Non-Jurisdictional Case';

        subjectInput.value = `🚨 ${subject} — ESCALATED (${label})`;
        subjectInput.dataset.rawValue = subject;
        subjectInput.dataset.escalated = escalation;

        if (titleInput) {
            titleInput.value = `ESCALATED: ${label} (Refer to ${referToShort})`;
            titleInput.dataset.escalated = escalation;
        }

        showEscalationPanel(escalation);
        updateAnonymityVisibility(escalation);
        if (document.getElementById('isAnonymous')) toggleAnonymityWarning();
        return;
    }

    // ── 2. Pointing-system multi-classify ──
    const candidates = classifyDescriptionMulti(description);

    if (!candidates || candidates.length === 0) {
        subjectInput.value = '';
        subjectInput.dataset.rawValue = '';
        if (titleInput) titleInput.value = '';
        showLawInfoPanel(null, null,
            'Could not identify the law from your description. Try adding more detail.',
            0, '', false, '');
        updateAnonymityVisibility(null);
        return;
    }

    // ── 3. Auto-select the Top Candidate ──
    selectedLaws = [candidates[0]];
    updateSelectedLaws();

    // ── 4. Show the info panel with Checkboxes ──
    showLawInfoPanel(
        candidates[0].article,
        candidates[0].law_title,
        '',
        candidates[0].confidence,
        candidates.length > 1
            ? `${candidates.length} possible laws matched. Check the ones that apply.`
            : '',
        false,
        '',
        candidates  
    );

    updateAnonymityVisibility(null);
}

// ============================================================
// SECTION 10: TITLE SUGGESTION
// ============================================================
function showTitleSuggestion(suggestedTitle) {
    const titleGroup = document.getElementById('complaintTitle')?.closest('.form-group');
    if (!titleGroup) return;
    const existing = document.getElementById('titleSuggestion');
    if (existing) existing.remove();
    const div = document.createElement('div');
    div.id = 'titleSuggestion';
    div.style.cssText = 'margin-top:8px;padding:8px 12px;background:#e3f2fd;border-radius:8px;border-left:3px solid #2196f3;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;';
    div.innerHTML = `<div><i class="fas fa-magic" style="color:#2196f3;"></i> <span style="font-size:13px;">Suggestion: <strong>${escapeHtml(suggestedTitle)}</strong></span></div><button type="button" onclick="applyTitleSuggestion('${escapeHtml(suggestedTitle).replace(/'/g, "\\'")}')" style="background:#2196f3;color:white;border:none;padding:4px 12px;border-radius:20px;cursor:pointer;font-size:11px;"><i class="fas fa-check"></i> Use</button>`;
    titleGroup.appendChild(div);
}
function applyTitleSuggestion(title) {
    const input = document.getElementById('complaintTitle');
    if (input) { input.value = title; const s = document.getElementById('titleSuggestion'); if (s) s.remove(); input.style.border = '2px solid #28a745'; setTimeout(() => input.style.border = '', 1500); }
}
function clearTitleSuggestion() { const s = document.getElementById('titleSuggestion'); if (s) s.remove(); }

// ============================================================
// SECTION 11: INCIDENTS
// ============================================================
function _nowDateStr() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
}
function _nowTimeStr() {
    const d = new Date();
    return `${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
}
function _newIncident() {
    return {
        incident_date: _nowDateStr(),
        incident_time: _nowTimeStr(),
        location: '',
        description: '',
        evidence_files: []
    };
}

function renderIncidentsList() {
    if (incidentsList.length === 0) incidentsList = [_newIncident()];
    return incidentsList.map((incident, index) => `
        <div class="incident-card" data-incident-index="${index}">
            <div class="incident-header">
                <strong><i class="fas fa-calendar-alt"></i> Incident ${index + 1}</strong>
                <div>
                    ${incidentsList.length > 1 ? `<button type="button" class="remove-incident-btn" onclick="removeIncident(${index})"><i class="fas fa-trash"></i> Remove</button>` : ''}
                </div>
            </div>
            <div class="incident-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Date of Incident <span style="color:red;">*</span></label>
                        <input type="date" class="incident-date" data-incident-index="${index}"
                               data-role="incident_date_${index}"
                               value="${incident.incident_date || _nowDateStr()}" required>
                    </div>
                    <div class="form-group">
                        <label>Time of Incident</label>
                        <input type="time" class="incident-time" data-incident-index="${index}"
                               value="${incident.incident_time || _nowTimeStr()}">
                    </div>
                </div>
                <div class="form-group">
                    <label>Location of Incident <span style="color:red;">*</span></label>
                    <input type="text" class="incident-location" data-incident-index="${index}"
                           data-role="incident_location_${index}"
                           value="${escapeHtml(incident.location || '')}" placeholder="e.g., Blk 12 Lot 5, Purok 3" required>
                </div>
                <div class="form-group">
                    <label>Incident Description <span style="color:red;">*</span></label>
                    <textarea class="incident-description" data-incident-index="${index}"
                              data-role="incident_description_${index}"
                              rows="3" placeholder="Describe what happened..." required>${escapeHtml(incident.description || '')}</textarea>
                </div>
                <div class="evidence-section" data-role="incidents_evidence_${index}">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:6px;">
                        <label style="margin:0;"><i class="fas fa-paperclip"></i> Evidence <span style="color:red;">*</span> <small style="color:#666;font-weight:400;">(at least 1 required)</small></label>
                        <button type="button" class="add-evidence-btn" onclick="addIncidentEvidence(${index})">
                            <i class="fas fa-plus"></i> Add Evidence
                        </button>
                    </div>
                    <div class="evidence-files-container" id="evidence-files-${index}">${renderEvidenceFiles(incident.evidence_files, index)}</div>
                    <input type="file" class="evidence-file-input" data-incident-index="${index}" multiple accept=".jpg,.jpeg,.png,.pdf,.mp4" style="display:none;">
                </div>
            </div>
        </div>`).join('');
}

function renderEvidenceFiles(files, incidentIndex) {
    if (!files || files.length === 0) return '<div class="no-evidence">No evidence yet.</div>';
    return files.map((file, i) => `<div class="evidence-file-item"><i class="fas ${file.type?.startsWith('image/') ? 'fa-image' : file.type?.includes('pdf') ? 'fa-file-pdf' : 'fa-file'}"></i><span class="file-name">${escapeHtml(file.name)}</span><button type="button" class="remove-evidence-btn" onclick="removeIncidentEvidence(${incidentIndex}, ${i})"><i class="fas fa-times"></i></button></div>`).join('');
}

function addIncident() { incidentsList.push(_newIncident()); document.getElementById('incidentsContainer').innerHTML = renderIncidentsList(); attachIncidentEventListeners(); attachFieldAutoClear(); }
function removeIncident(i) { incidentsList.splice(i, 1); document.getElementById('incidentsContainer').innerHTML = renderIncidentsList(); attachIncidentEventListeners(); attachFieldAutoClear(); }
function addIncidentEvidence(i) { document.querySelector(`.evidence-file-input[data-incident-index="${i}"]`).click(); }
function removeIncidentEvidence(i, fi) { incidentsList[i].evidence_files.splice(fi, 1); document.getElementById(`evidence-files-${i}`).innerHTML = renderEvidenceFiles(incidentsList[i].evidence_files, i); }

function attachIncidentEventListeners() {
    document.querySelectorAll('.incident-date, .incident-time, .incident-location, .incident-description').forEach(input => {
        input.removeEventListener('change', updateIncidentField);
        input.removeEventListener('input', updateIncidentField);
        input.addEventListener(input.type === 'date' || input.type === 'time' ? 'change' : 'input', updateIncidentField);
    });
    document.querySelectorAll('.evidence-file-input').forEach(input => {
        input.removeEventListener('change', handleIncidentFileUpload);
        input.addEventListener('change', handleIncidentFileUpload);
    });
}

function updateIncidentField(e) {
    const input = e.target;
    const idx = parseInt(input.dataset.incidentIndex);
    let field = '';
    if (input.classList.contains('incident-date')) field = 'incident_date';
    else if (input.classList.contains('incident-time')) field = 'incident_time';
    else if (input.classList.contains('incident-location')) field = 'location';
    else if (input.classList.contains('incident-description')) field = 'description';
    if (incidentsList[idx] && field) incidentsList[idx][field] = input.value;
}

function handleIncidentFileUpload(e) {
    const input = e.target;
    const idx = parseInt(input.dataset.incidentIndex);
    const files = Array.from(input.files);
    if (!incidentsList[idx]) return;
    if (!incidentsList[idx].evidence_files) incidentsList[idx].evidence_files = [];
    files.forEach(f => {
        if (f.size > 10 * 1024 * 1024) { showErrorModal(`File ${f.name} exceeds 10MB`); return; }
        incidentsList[idx].evidence_files.push({ name: f.name, size: f.size, type: f.type, file: f });
    });
    document.getElementById(`evidence-files-${idx}`).innerHTML = renderEvidenceFiles(incidentsList[idx].evidence_files, idx);
    input.value = '';
}

// ============================================================
// SECTION 12: PARTY MANAGEMENT
// ============================================================
function renderComplainantsList() {
    if (complainantsList.length === 0) return '<div class="empty-list">No complainants yet.</div>';
    return complainantsList.map((p, i) => `
        <div class="party-card" data-index="${i}" data-type="complainant">
            <div class="party-header">
                <strong><i class="fas fa-user"></i> Complainant ${i+1}</strong>
                ${i > 0 ? `<button type="button" class="remove-party-btn" onclick="removeComplainant(${i})"><i class="fas fa-trash"></i> Remove</button>` : ''}
            </div>
            <div class="party-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name <span style="color:red;">*</span></label>
                        <input type="text" class="party-name" data-index="${i}" data-field="full_name"
                               data-role="complainant_name_${i}"
                               value="${escapeHtml(p.full_name)}" required>
                    </div>
                    <div class="form-group">
    <label>Contact Number <span style="color:red;">*</span></label>
    <input type="text" class="party-contact" data-index="${i}" data-field="contact_number"
           data-role="complainant_contact_${i}"
           value="${escapeHtml(p.contact_number || '')}"
           maxlength="11" 
           inputmode="numeric"
           oninput="this.value = this.value.replace(/[^0-9]/g, '')"
           placeholder="09XXXXXXXXX" required>
</div>

                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Email (optional)</label>
                        <input type="email" class="party-email" data-index="${i}" data-field="email"
                               data-role="complainant_email_${i}"
                               value="${escapeHtml(p.email || '')}">
                    </div>
                    <div class="form-group">
                        <label>Address <span style="color:red;">*</span></label>
                        <input type="text" class="party-address" data-index="${i}" data-field="address"
                               data-role="complainant_address_${i}"
                               value="${escapeHtml(p.address || '')}" required>
                    </div>
                </div>
            </div>
        </div>`).join('');
}

function renderRespondentsList() {
    if (respondentsList.length === 0) return '<div class="empty-list">No respondents yet.</div>';
    return respondentsList.map((p, i) => `
        <div class="party-card" data-index="${i}" data-type="respondent">
            <div class="party-header">
                <strong><i class="fas fa-user-friends"></i> Respondent ${i+1}</strong>
                <button type="button" class="remove-party-btn" onclick="removeRespondent(${i})"><i class="fas fa-trash"></i> Remove</button>
            </div>
            <div class="party-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name <span style="color:red;">*</span></label>
                        <input type="text" class="party-name" data-index="${i}" data-field="full_name"
                               data-role="respondent_name_${i}"
                               value="${escapeHtml(p.full_name)}" required>
                    </div>
                    <div class="form-group">
    <label>Contact Number (optional)</label>
    <input type="text" class="party-contact" data-index="${i}" data-field="contact_number"
           data-role="respondent_contact_${i}"
           value="${escapeHtml(p.contact_number || '')}"
           maxlength="11" 
           inputmode="numeric" 
           oninput="this.value = this.value.replace(/[^0-9]/g, '')"
           placeholder="09XXXXXXXXX">
</div>

                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Address (optional)</label>
                        <input type="text" class="party-address" data-index="${i}" data-field="address"
                               value="${escapeHtml(p.address || '')}">
                    </div>
                    <div class="form-group">
                        <label>Relationship (optional)</label>
                        <input type="text" class="party-relationship" data-index="${i}" data-field="relationship"
                               value="${escapeHtml(p.relationship || '')}" placeholder="e.g., Neighbor">
                    </div>
                </div>
            </div>
        </div>`).join('');
}

function renderWitnessesList() {
    if (witnessesList.length === 0) return '<div class="empty-list">No witnesses yet.</div>';
    return witnessesList.map((p, i) => `<div class="party-card" data-index="${i}" data-type="witness"><div class="party-header"><strong><i class="fas fa-eye"></i> Witness ${i+1}</strong><button type="button" class="remove-party-btn" onclick="removeWitness(${i})"><i class="fas fa-trash"></i> Remove</button></div><div class="party-body"><div class="form-row"><div class="form-group"><label>Full Name</label><input type="text" class="party-name" data-index="${i}" data-field="full_name" value="${escapeHtml(p.full_name || '')}"></div><div class="form-group"><label>Contact Number</label><input type="tel" class="party-contact" data-index="${i}" data-field="contact_number" value="${escapeHtml(p.contact_number || '')}"></div></div><div class="form-group"><label>Address</label><input type="text" class="party-address" data-index="${i}" data-field="address" value="${escapeHtml(p.address || '')}"></div></div></div>`).join('');
}

function addComplainant() { complainantsList.push({ full_name: '', address: '', contact_number: '', email: '' }); document.getElementById('complainantsContainer').innerHTML = renderComplainantsList(); attachPartyEventListeners(); attachFieldAutoClear(); }
function removeComplainant(i) { complainantsList.splice(i, 1); document.getElementById('complainantsContainer').innerHTML = renderComplainantsList(); attachPartyEventListeners(); attachFieldAutoClear(); }
function addRespondent() { respondentsList.push({ full_name: '', address: '', contact_number: '', relationship: '' }); document.getElementById('respondentsContainer').innerHTML = renderRespondentsList(); attachPartyEventListeners(); attachFieldAutoClear(); }
function removeRespondent(i) { respondentsList.splice(i, 1); document.getElementById('respondentsContainer').innerHTML = renderRespondentsList(); attachPartyEventListeners(); attachFieldAutoClear(); }
function addWitness() { witnessesList.push({ full_name: '', address: '', contact_number: '' }); document.getElementById('witnessesContainer').innerHTML = renderWitnessesList(); attachPartyEventListeners(); attachFieldAutoClear(); }
function removeWitness(i) { witnessesList.splice(i, 1); document.getElementById('witnessesContainer').innerHTML = renderWitnessesList(); attachPartyEventListeners(); attachFieldAutoClear(); }

function attachPartyEventListeners() {
    ['#complainantsContainer', '#respondentsContainer', '#witnessesContainer'].forEach(sel => {
        document.querySelectorAll(`${sel} .party-name, ${sel} .party-contact, ${sel} .party-email, ${sel} .party-address, ${sel} .party-relationship`).forEach(input => {
            input.removeEventListener('input', updatePartyField);
            input.addEventListener('input', updatePartyField);
        });
    });
}

function updatePartyField(e) {
    const input = e.target;
    const idx = parseInt(input.dataset.index);
    const field = input.dataset.field;
    const type = input.closest('.party-card')?.dataset.type;
    let arr;
    if (type === 'complainant') arr = complainantsList;
    else if (type === 'respondent') arr = respondentsList;
    else if (type === 'witness') arr = witnessesList;
    else return;
    if (arr && arr[idx]) arr[idx][field] = input.value;
}

// ============================================================
// SECTION 13: SUBMIT COMPLAINT
// ============================================================
async function submitComplaint(event) {
    event.preventDefault();

    clearAllFieldErrors();

    const titleEl   = document.getElementById('complaintTitle');
    const descEl    = document.getElementById('complaintDescription');
    const subjectEl = document.getElementById('complaintSubject');
    const title     = titleEl.value.trim();
    const desc      = descEl.value.trim();
    const subject   = subjectEl.dataset.rawValue || subjectEl.value;
    const escalation = detectEscalation(desc);
    const isEscalated = !!escalation;
    const referToShort = isEscalated ? (ESCALATION_REFER_TO_SHORT[escalation] || 'Proper Government Agency') : '';

    let firstError = null;

    if (!subject) {
        markFieldError(subjectEl, 'Please select at least one law.');
        firstError = firstError || subjectEl;
    }
    {
        const r = validateWordingJS(title, 5, 'Title');
        if (!r.ok) { markFieldError(titleEl, r.msg); firstError = firstError || titleEl; }
    }
    {
        const r = validateWordingJS(desc, 15, 'Description');
        if (!r.ok) { markFieldError(descEl, r.msg); firstError = firstError || descEl; }
    }

    if (complainantsList.length === 0) {
        const card = document.querySelector('#complainantsContainer .party-card');
        if (card) { markGroupError(card, 'At least one complainant is required.'); firstError = firstError || card; }
    }
    complainantsList.forEach((p, i) => {
        const card = document.querySelector(`#complainantsContainer .party-card[data-index="${i}"]`);
        if (!card) return;
        const nameInput    = card.querySelector('.party-name');
        const contactInput = card.querySelector('.party-contact');
        const addrInput    = card.querySelector('.party-address');
        const emailInput   = card.querySelector('.party-email');

        const nameR = validatePersonNameJS(p.full_name);
        if (!nameR.ok) { markFieldError(nameInput, nameR.msg); firstError = firstError || nameInput; }

        if (!validateContactNumberJS(p.contact_number)) {
            markFieldError(contactInput, 'Contact must be 11 digits starting with 09.');
            firstError = firstError || contactInput;
        }
        if (!(p.address || '').trim()) {
            markFieldError(addrInput, 'Address is required.');
            firstError = firstError || addrInput;
        }
        if (!validateEmailOrEmptyJS(p.email)) {
            markFieldError(emailInput, 'Invalid email address.');
            firstError = firstError || emailInput;
        }
    });

    respondentsList.forEach((p, i) => {
        const card = document.querySelector(`#respondentsContainer .party-card[data-index="${i}"]`);
        if (!card) return;
        const nameInput    = card.querySelector('.party-name');
        const contactInput = card.querySelector('.party-contact');

        const nameR = validatePersonNameJS(p.full_name);
        if (!nameR.ok) { markFieldError(nameInput, nameR.msg); firstError = firstError || nameInput; }
        if ((p.contact_number || '').trim() !== '' && !validateContactNumberJS(p.contact_number)) {
            markFieldError(contactInput, 'Contact must be 11 digits starting with 09.');
            firstError = firstError || contactInput;
        }
    });

    let hasValidIncident = false;
    incidentsList.forEach((inc, idx) => {
        const card = document.querySelector(`.incident-card[data-incident-index="${idx}"]`);
        if (!card) return;
        const dateInput  = card.querySelector('.incident-date');
        const locInput   = card.querySelector('.incident-location');
        const descInput  = card.querySelector('.incident-description');
        let ok = true;

        if (!inc.incident_date) { markFieldError(dateInput, 'Date is required.'); ok = false; firstError = firstError || dateInput; }
        if (!(inc.location || '').trim()) { markFieldError(locInput, 'Location is required.'); ok = false; firstError = firstError || locInput; }
        {
            const r = validateWordingJS(inc.description, 10, `Incident ${idx+1} description`);
            if (!r.ok) { markFieldError(descInput, r.msg); ok = false; firstError = firstError || descInput; }
        }
        if (!(inc.evidence_files && inc.evidence_files.length)) {
            const section = card.querySelector('.evidence-section');
            if (section) {
                section.style.border = '2px solid #e53935';
                section.style.borderRadius = '10px';
                section.style.padding = '10px';
                section.style.background = '#fff5f5';
                const msg = document.createElement('span');
                msg.className = 'field-error-msg';
                msg.innerHTML = '<i class="fas fa-exclamation-circle"></i>At least one evidence file is required.';
                section.appendChild(msg);
                ok = false;
                firstError = firstError || section;
            }
        }
        if (ok) hasValidIncident = true;
    });

    if (!hasValidIncident && !firstError) {
        const firstCard = document.querySelector('#incidentsContainer .incident-card');
        if (firstCard) { markGroupError(firstCard, 'Please complete at least one incident.'); firstError = firstCard; }
    }

    if (firstError) {
        firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
        attachFieldAutoClear();
        return;
    }

    const submitBtn = document.querySelector('#complaintForm button[type="submit"]');
    if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...'; }

    let escalationNote = '';
    if (isEscalated) escalationNote = `[ESCALATED CASE — NOT under Katarungang Pambarangay] Reason: ${ESCALATION_LABELS[escalation]}. Refer to: ${referToShort}. The Barangay will record this in the blotter, issue a Referral Letter, and coordinate with the proper agency. NO MEDIATION will be conducted.`;

    const formData = new FormData();
    formData.append('action', 'submit_complaint');
    formData.append('title', title);
    formData.append('description', desc);
    formData.append('complaint_subject', subject);
    const computedPriority = classifyAutoPriorityJS(desc, subject, isEscalated);
    formData.append('priority', computedPriority);
    formData.append('auto_priority', computedPriority);
    formData.append('additional_notes', document.getElementById('additionalNotes').value);
    formData.append('complainants', JSON.stringify(complainantsList));
    formData.append('respondents', JSON.stringify(respondentsList));
    if (witnessesList.length) formData.append('witnesses', JSON.stringify(witnessesList));
    if (isEscalated) {
        formData.append('escalation_type', escalation);
        formData.append('escalation_refer_to', referToShort);
        formData.append('escalation_note', escalationNote);
    }

    const anonGroup    = document.getElementById('anonymityGroup');
    const anonCheckbox = document.getElementById('isAnonymous');
    const anonVisible  = anonGroup && anonGroup.style.display !== 'none';
    formData.append('is_anonymous', (anonVisible && anonCheckbox && anonCheckbox.checked) ? 1 : 0);

    const incidentsData = incidentsList.map(inc => ({
        incident_date: inc.incident_date,
        incident_time: inc.incident_time,
        location: inc.location,
        description: inc.description,
    }));
    formData.append('incidents', JSON.stringify(incidentsData));

    for (let i = 0; i < incidentsList.length; i++) {
        const evFiles = incidentsList[i].evidence_files;
        if (evFiles && evFiles.length) {
            for (const f of evFiles) { if (f.file) formData.append(`incident_evidence_${i}[]`, f.file); }
        }
    }

    await _doSubmitComplaint(formData, { isEscalated, anonVisible, anonCheckbox, submitBtn, escalation });
}

function showEscalationSuccessModal(referenceNumber, escalationType, isAnonymous) {
    const referTo = ESCALATION_REFER_TO_SHORT[escalationType] || 'Proper Government Agency';
    const label = ESCALATION_LABELS[escalationType] || 'Non-Jurisdictional Case';
    ensureModalStyles();
    const existing = document.getElementById('appModalOverlay');
    if (existing) existing.remove();
    const anonBlock = isAnonymous ? `<div style="font-size:12px;color:#333;background:#fff8e1;padding:10px 12px;border-radius:8px;border-left:3px solid #ef6c00;margin-bottom:10px;line-height:1.6;"><strong style="display:block;margin-bottom:4px;color:#e65100;"><i class="fas fa-user-shield"></i> Whistleblower Protection Active</strong>Your identity is protected. The Barangay and all referral documents will show you only as <strong>"Concerned Resident"</strong>.</div>` : '';
    const overlay = document.createElement('div');
    overlay.id = 'appModalOverlay';
    overlay.className = 'app-modal-overlay';
    overlay.innerHTML = `<div class="app-modal-box" style="max-width:480px;text-align:left;"><div style="text-align:center;"><div class="app-modal-icon warning"><i class="fas fa-exclamation-triangle"></i></div><div class="app-modal-title">Case Recorded — Referral Required</div></div><div style="font-size:13px;color:#333;line-height:1.6;margin:10px 0;">Ang inyong kaso ay <strong>naitala na sa Barangay</strong> para sa rekord at referral.</div><div style="font-size:12px;color:#333;background:#fff8e1;padding:10px 12px;border-radius:8px;border-left:3px solid #ef6c00;margin-bottom:10px;"><strong>Klase ng kaso:</strong> ${escapeHtml(label)}<br><strong>I-refer sa:</strong> ${escapeHtml(referTo)}<br><strong>Status:</strong> ESCALATED (hindi dadaan sa mediation)</div>${anonBlock}<div style="text-align:center;margin:14px 0;"><div style="font-size:11px;color:#666;margin-bottom:4px;">Reference Number</div><div class="app-modal-ref" style="margin-top:0;">${escapeHtml(referenceNumber)}</div></div><div style="font-size:12px;color:#333;background:#e8f5e9;padding:10px 12px;border-radius:8px;border-left:3px solid #2E7D32;margin-bottom:10px;line-height:1.6;"><strong style="display:block;margin-bottom:4px;color:#2E7D32;">Ano ang susunod na gagawin:</strong><ol style="margin:0;padding-left:20px;"><li>Pumunta sa Barangay Hall upang kunin ang <strong>Referral Letter</strong>.</li><li>Dalhin ang referral sa <strong>${escapeHtml(referTo)}</strong>.</li><li>Kung may agarang panganib, tumawag agad sa <strong>911</strong>.</li><li>Kung VAWC, hilingin ang <strong>Barangay Protection Order (BPO)</strong>.</li></ol></div><div style="font-size:11px;color:#666;text-align:center;"><i class="fas fa-info-circle"></i> Ang Barangay ay hindi magme-mediate sa kasong ito ayon sa batas.</div><button type="button" class="app-modal-btn warning" id="appModalBtn" style="margin-top:16px;">Naiintindihan ko</button></div>`;
    document.body.appendChild(overlay);
    return new Promise((resolve) => { const close = () => { overlay.remove(); resetComplaintForm(); showMyComplaints(); resolve(true); }; document.getElementById('appModalBtn').addEventListener('click', close); overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); }); });
}

// ============================================================
// SECTION 14: MAIN FORM
// ============================================================
function getSubmitComplaintForm() {
    const resident = window.resident || {};
    const rName = resident.name || resident.firstName + ' ' + resident.lastName || '';
    const rPhone = resident.phone || '';
    const rEmail = resident.email || '';
    const rAddr = resident.address || '';
    if (complainantsList.length === 0) complainantsList = [{ full_name: rName, address: rAddr, contact_number: rPhone, email: rEmail }];
    if (incidentsList.length === 0) incidentsList = [_newIncident()];

    return `
        <form id="complaintForm" onsubmit="submitComplaint(event)" novalidate>
            <div class="form-section">
                <h3><i class="fas fa-info-circle"></i> Complaint Information</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Complaint Subject <span style="color:red;">*</span></label>
                        <!-- Read-Only Subject Input -->
                        <input type="text" id="complaintSubject" class="form-control" readonly style="background-color: #f8f9fa; cursor: not-allowed;" required placeholder="Auto-filled from selected laws">
                        <small style="color:#2eaa5e;"><i class="fas fa-lock"></i> Auto-generated from selected laws. Cannot be edited.</small>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-exclamation-triangle"></i> Complaint Title <span style="color:red;">*</span></label>
                        <!-- Read-Only Title -->
                        <input type="text" id="complaintTitle" class="form-control" readonly style="background-color: #f8f9fa; cursor: not-allowed;" required placeholder="Auto-filled from selected laws">
                        <small style="color:#2eaa5e;"><i class="fas fa-lock"></i> Auto-generated summary.</small>
                    </div>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-align-left"></i> Overall Description <span style="color:red;">*</span></label>
                    <textarea id="complaintDescription" class="form-control" rows="4" required placeholder="Describe the complaint in detail. Include: what happened, who did it, who it happened to, when and where."></textarea>
                    <small style="color:#2eaa5e;"><i class="fas fa-magic"></i> The system identifies the applicable law from the full description.</small>
                </div>
                <div class="form-group" id="anonymityGroup" style="display: none; margin-top: 18px;">
                    <div id="anonymityCard" style="position: relative; border: 2px solid #e0e0e0; border-radius: 14px; padding: 18px 20px; background: linear-gradient(135deg, #fafafa 0%, #f5f5f5 100%); transition: all 0.25s ease; cursor: pointer;" onclick="if(event.target.tagName !== 'INPUT' && event.target.tagName !== 'A' && event.target.tagName !== 'I' && !event.target.closest('a')) { document.getElementById('isAnonymous').click(); }">
                        <div style="display: flex; align-items: flex-start; gap: 14px;">
                            <div id="anonCheckbox" style="flex-shrink: 0; width: 24px; height: 24px; border-radius: 6px; border: 2px solid #bdbdbd; background: #fff; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease; margin-top: 1px;">
                                <i class="fas fa-check" id="anonCheckIcon" style="color: #fff; font-size: 13px; opacity: 0; transform: scale(0.5); transition: all 0.2s ease;"></i>
                            </div>
                            <input type="checkbox" id="isAnonymous" name="is_anonymous" value="1" onchange="toggleAnonymityWarning()" style="position: absolute; opacity: 0; pointer-events: none;">
                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <i class="fas fa-user-shield" style="color: #e65100; font-size: 1rem;"></i>
                                    <span style="font-weight: 700; font-size: 0.95rem; color: #5d4037;">File Anonymously</span>
                                    <span style="background: linear-gradient(135deg, #e65100, #ef6c00); color: #fff; font-size: 0.62rem; font-weight: 700; padding: 2px 8px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.5px;">Whistleblower</span>
                                </div>
                                <div style="font-size: 0.82rem; color: #666; margin-top: 4px; line-height: 1.5;">Your identity will be hidden. The Barangay will see you only as <strong style="color: #5d4037;">"Concerned Resident"</strong>.</div>
                            </div>
                        </div>
                        <div id="anonymityHint" style="display: none; margin-top: 14px; padding-top: 14px; border-top: 1px dashed #d0d0d0;">
                            <div style="display: flex; gap: 12px; font-size: 0.8rem; line-height: 1.6;">
                                <div style="flex-shrink: 0; width: 26px; height: 26px; border-radius: 50%; background: #e8f5e9; display: flex; align-items: center; justify-content: center;"><i class="fas fa-shield-alt" style="color: #2E7D32; font-size: 12px;"></i></div>
                                <div style="color: #333;"><div style="font-weight: 700; color: #2E7D32; margin-bottom: 3px;">Protection Active</div><ul style="margin: 0; padding-left: 18px; color: #555;"><li>Your name won't appear on the referral letter or any official document</li><li>You can still track this case using your account</li><li>The Barangay can contact you privately if needed</li></ul></div>
                            </div>
                        </div>
                        <div id="anonymityWarning" style="display: none; margin-top: 14px; padding-top: 14px; border-top: 1px dashed #d0d0d0;">
                            <div style="display: flex; gap: 12px; font-size: 0.8rem; line-height: 1.6;">
                                <div style="flex-shrink: 0; width: 26px; height: 26px; border-radius: 50%; background: #fff3e0; display: flex; align-items: center; justify-content: center;"><i class="fas fa-info-circle" style="color: #ef6c00; font-size: 12px;"></i></div>
                                <div style="color: #555;">If you don't check this, your name and contact details will appear on the official record.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="form-section">
                <h3><i class="fas fa-calendar-alt"></i> Incidents <button type="button" class="add-item-btn" onclick="addIncident()" style="float:right;"><i class="fas fa-plus"></i> Add Another Incident</button></h3>
                <div id="incidentsContainer">${renderIncidentsList()}</div>
            </div>
            <div class="form-section">
                <h3><i class="fas fa-users"></i> Complainants <button type="button" class="add-item-btn" onclick="addComplainant()" style="float:right;"><i class="fas fa-plus"></i> Add Another</button></h3>
                <div id="complainantsContainer">${renderComplainantsList()}</div>
            </div>
            <div class="form-section">
                <h3><i class="fas fa-user-friends"></i> Respondents <button type="button" class="add-item-btn" onclick="addRespondent()" style="float:right;"><i class="fas fa-plus"></i> Add Another</button></h3>
                <div id="respondentsContainer">${renderRespondentsList()}</div>
            </div>
            <div class="form-section">
                <h3><i class="fas fa-eye"></i> Witnesses <button type="button" class="add-item-btn" onclick="addWitness()" style="float:right;"><i class="fas fa-plus"></i> Add Witness</button></h3>
                <div id="witnessesContainer">${renderWitnessesList()}</div>
            </div>
            <div class="form-section">
    <h3><i class="fas fa-flag"></i> Auto-Priority & Additional Notes</h3>
    <div id="autoPriorityBadge" style="margin-bottom:14px;">
        <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;
                    background:#fffde7;border-left:4px solid #f9a825;border-radius:10px;">
            <i class="fas fa-minus" style="color:#f9a825;font-size:1.1rem;"></i>
            <div style="flex:1;min-width:0;">
                <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.4px;font-weight:800;color:#f9a825;">Auto-Priority</div>
                <div style="font-weight:800;color:#f9a825;font-size:0.95rem;">MEDIUM</div>
                <div style="font-size:0.78rem;color:#555;margin-top:2px;">standard case</div>
            </div>
        </div>
    </div>
    <div class="form-group"><label>Additional Notes</label><textarea id="additionalNotes" class="form-control" rows="2" placeholder="Any other information..."></textarea></div>
</div>
            <div class="form-actions">
                <button type="submit" class="btn-verify"><i class="fas fa-paper-plane"></i> Submit Complaint</button>
                <button type="button" class="btn-close" onclick="resetComplaintForm()"><i class="fas fa-eraser"></i> Reset Form</button>
            </div>
        </form>
    `;
}

async function _doSubmitComplaint(formData, opts) {
    const { isEscalated, anonVisible, anonCheckbox, submitBtn, escalation } = opts;

    showLoading(isEscalated ? 'Saving escalated case...' : 'Submitting complaint...');

    try {
        const resp = await fetch('complaint_ajax.php', { method: 'POST', body: formData });
        const text = await resp.text();
        let data;
        try { data = JSON.parse(text); }
        catch (parseErr) { throw new Error('Server returned invalid response.'); }

        hideLoading();
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Complaint'; }

        if (!data.success && data.code === 'duplicate_blocked' && data.possible_duplicate) {
            const dup = data.possible_duplicate;

            await Swal.fire({
                title: 'Duplicate Complaint Detected',
                html: `
                    <div style="text-align:left;font-size:0.9rem;line-height:1.55;">
                        <p style="margin:0 0 12px;">You already filed this exact complaint. To avoid duplicate cases, you cannot file it again.</p>
                        <div style="background:#fff5f5;border:1px solid #ffcdd2;border-left:4px solid #c62828;border-radius:10px;padding:12px 14px;margin-bottom:14px;">
                            <div style="font-weight:800;color:#1a2b22;font-size:0.95rem;margin-bottom:4px;">${escapeHtml(dup.title || 'Untitled')}</div>
                            <div style="font-family:monospace;font-weight:700;color:#c62828;margin-bottom:6px;">${escapeHtml(dup.reference_number)}</div>
                            <div style="font-size:0.78rem;color:#555;">
                                <div><strong>Status:</strong> ${escapeHtml((dup.status || '').replace(/_/g,' '))}</div>
                                <div><strong>Filed:</strong> ${new Date(dup.created_at).toLocaleString('en-US',{dateStyle:'medium',timeStyle:'short'})}</div>
                                <div><strong>Matched on:</strong> ${escapeHtml(dup.reason)}</div>
                                <div style="margin-top:4px;color:#c62828;font-weight:700;">${dup.confidence}% match</div>
                            </div>
                        </div>
                        <p style="margin:0;font-size:0.82rem;color:#666;">If you need to add information, open your existing complaint and add a note instead.</p>
                    </div>`,
                width: 560,
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-eye"></i> View My Complaint',
                cancelButtonText:  'Close',
                confirmButtonColor: '#1565c0',
                cancelButtonColor:  '#6c757d',
                reverseButtons: true,
                allowOutsideClick: false,
            }).then((result) => {
                if (result.isConfirmed) {
                    resetComplaintForm();
                    showMyComplaints();
                    setTimeout(() => viewComplaintDetails(dup.id), 400);
                }
            });

            return;
        }

        if (!data.success && data.code === 'possible_duplicate' && data.possible_duplicate) {
            const dup = data.possible_duplicate;

            const choice = await Swal.fire({
                title: 'Similar Complaint Found',
                html: `
                    <div style="text-align:left;font-size:0.9rem;line-height:1.55;">
                        <p style="margin:0 0 12px;">You already filed a complaint that looks similar:</p>
                        <div style="background:#f8faf8;border:1px solid #e2efe8;border-left:4px solid #f9a825;border-radius:10px;padding:12px 14px;margin-bottom:14px;">
                            <div style="font-weight:800;color:#1a2b22;font-size:0.95rem;margin-bottom:4px;">${escapeHtml(dup.title || 'Untitled')}</div>
                            <div style="font-family:monospace;font-weight:700;color:#2E7D32;margin-bottom:6px;">${escapeHtml(dup.reference_number)}</div>
                            <div style="font-size:0.78rem;color:#555;">
                                <div><strong>Status:</strong> ${escapeHtml((dup.status || '').replace(/_/g,' '))}</div>
                                <div><strong>Filed:</strong> ${new Date(dup.created_at).toLocaleString('en-US',{dateStyle:'medium',timeStyle:'short'})}</div>
                                <div><strong>Matched on:</strong> ${escapeHtml(dup.reason)}</div>
                                <div style="margin-top:4px;color:#f57c00;font-weight:700;">${dup.confidence}% match</div>
                            </div>
                        </div>
                        <p style="margin:0;font-size:0.82rem;color:#666;">If this is a different incident, you can continue filing a new complaint.</p>
                    </div>`,
                width: 560,
                showCancelButton: true,
                showDenyButton: true,
                confirmButtonText: '<i class="fas fa-paper-plane"></i> File Anyway',
                denyButtonText:    '<i class="fas fa-eye"></i> View Existing',
                cancelButtonText:  'Cancel',
                confirmButtonColor: '#2E7D32',
                denyButtonColor:    '#1565c0',
                cancelButtonColor:  '#6c757d',
                reverseButtons: true,
            });

            if (choice.isDenied) {
                viewComplaintDetails(dup.id);
                return;
            }
            if (!choice.isConfirmed) return;

            const retry = new FormData();
            for (const [k, v] of formData.entries()) retry.append(k, v);
            retry.set('force_duplicate', '1');

            if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...'; }
            await _doSubmitComplaint(retry, opts);
            return;
        }
        if (data.success) {
            if (isEscalated) showEscalationSuccessModal(data.reference_number, escalation, anonVisible && anonCheckbox.checked);
            else showComplaintSuccessModal(data.reference_number, () => { resetComplaintForm(); showMyComplaints(); });
        } else {
            if (data.error_field) {
                highlightField(data.error_field, data.message || 'Please fix this field.');
            } else {
                showErrorModal(data.message || 'Failed to submit');
            }
        }
    } catch (err) {
        hideLoading();
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Complaint'; }
        showErrorModal('An error occurred: ' + err.message);
    }
}

// ============================================================
// SECTION 15: MY COMPLAINTS — Tabs + Filter + CARD VIEW
// ============================================================
async function showMyComplaints() {
    if (!ensureComplaintContainer()) return;
    const container = document.getElementById('complaintContent');
    container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading complaints...</p></div>';
    try {
        const resp = await fetch('complaint_ajax.php?action=get_my_complaints');
        const data = await resp.json();
        if (data.success) {
            cachedComplaints.as_complainant = data.as_complainant || [];
            cachedComplaints.as_respondent  = data.as_respondent  || [];
                      currentStageFilter = 'all';
            renderComplaintsWithTabs();
        } else {
            container.innerHTML = `<div class="empty-state"><i class="fas fa-folder-open fa-3x"></i><p>No complaints yet.</p><button class="btn-verify" onclick="showSubmitComplaintForm()"><i class="fas fa-plus"></i> File a Complaint</button></div>`;
        }
    } catch (err) {
        container.innerHTML = '<div class="empty-state"><p>Error loading complaints.</p></div>';
    }
}

function resolveStageForComplaint(c, role) {
    const status = c.status;

    const inRepudiationWindow =
        (status === 'settled_pending'      && Number(c.amicable_repudiated    || 0) === 0 && c.repudiation_deadline) ||
        (status === 'arbitration_pending'  && Number(c.arbitration_repudiated || 0) === 0 && c.repudiation_deadline);

 let baseStage = STATUS_TO_STAGE[status] || 'intake';
    if (status === 'summoned') {
        const sessionType = c.next_hearing_type || c.hearing_type || null;
        if (sessionType === 'conciliation') return 'pangkat_conciliation';
        if (sessionType === 'mediation')    return 'mediation';
        return role === 'respondent' ? 'summoned' : 'notice_issued';
    }

    if (status === 'failed_mediation') {
        const sessionType = c.next_hearing_type || null;
        if (sessionType === 'conciliation') return 'pangkat_conciliation';
        return 'pangkat_constitution';
    }

    if ((baseStage === 'mediation' || baseStage === 'pangkat_constitution')
        && c.next_hearing_type === 'conciliation') {
        return 'pangkat_conciliation';
    }
   
    return baseStage;
}

function computeStageCounts(list, role) {
    const counts = { all: list.length };
    for (const key of Object.keys(COMPLAINT_STAGES)) {
        if (key === 'all') continue;
        counts[key] = 0;
    }

    list.forEach(c => {
        const stageKey = resolveStageForComplaint(c, role);
        if (counts[stageKey] !== undefined) counts[stageKey]++;
    });

    counts.repudiable = list.filter(c => {
        const s = c.status;
        const inSettle = (s === 'settled_pending'
                          && Number(c.amicable_repudiated || 0) === 0
                          && !!c.repudiation_deadline);
        const inArb    = (s === 'arbitration_pending'
                          && Number(c.arbitration_repudiated || 0) === 0
                          && !!c.repudiation_deadline);
        return inSettle || inArb;
    }).length;

    return counts;
}

function filterByStage(list, stageKey, role) {
    if (stageKey === 'all') return list;

    if (stageKey === 'repudiable') {
        return list.filter(c => {
            const s = c.status;
            const inSettle = (s === 'settled_pending'
                              && Number(c.amicable_repudiated || 0) === 0
                              && !!c.repudiation_deadline);
            const inArb    = (s === 'arbitration_pending'
                              && Number(c.arbitration_repudiated || 0) === 0
                              && !!c.repudiation_deadline);
            return inSettle || inArb;
        });
    }

    return list.filter(c => resolveStageForComplaint(c, role) === stageKey);
}

function renderComplaintsWithTabs() {
    const container = document.getElementById('complaintContent');
    const asC = cachedComplaints.as_complainant || [];
    const asR = cachedComplaints.as_respondent  || [];
    const currentList = currentComplaintTab === 'complainant' ? asC : asR;
    const currentRole = currentComplaintTab === 'complainant' ? 'complainant' : 'respondent';

    const stageCounts = computeStageCounts(currentList, currentRole);

    const tabsHtml = `
        <div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap;align-items:center;">
            <button type="button" onclick="switchComplaintTab('complainant')"
                    style="padding:10px 18px;border-radius:22px;border:1.5px solid #e2efe8;
                           background:${currentComplaintTab === 'complainant' ? '#2E7D32' : '#fff'};
                           color:${currentComplaintTab === 'complainant' ? '#fff' : '#555'};
                           font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:8px;">
                <i class="fas fa-user"></i> As Complainant
                <span style="background:${currentComplaintTab === 'complainant' ? 'rgba(255,255,255,0.3)' : '#f0f0f0'};padding:2px 8px;border-radius:10px;font-size:0.72rem;">${asC.length}</span>
            </button>
            <button type="button" onclick="switchComplaintTab('respondent')"
                    style="padding:10px 18px;border-radius:22px;border:1.5px solid #e2efe8;
                           background:${currentComplaintTab === 'respondent' ? '#e65100' : '#fff'};
                           color:${currentComplaintTab === 'respondent' ? '#fff' : '#555'};
                           font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:8px;">
                <i class="fas fa-user-friends"></i> As Respondent
                <span style="background:${currentComplaintTab === 'respondent' ? 'rgba(255,255,255,0.3)' : '#f0f0f0'};padding:2px 8px;border-radius:10px;font-size:0.72rem;">${asR.length}</span>
            </button>
            <button type="button" class="btn-verify" onclick="showSubmitComplaintForm()" style="margin-left:auto;"><i class="fas fa-plus"></i> File New Complaint</button>
        </div>
    `;

    const visibleStages = Object.entries(COMPLAINT_STAGES).filter(([key, stage]) => {
        if (key === 'all') return true;
        if (stage.forRole && stage.forRole !== 'both' && stage.forRole !== currentRole) return false;
          if (key === 'repudiable') return true;
        return (stageCounts[key] || 0) > 0;
    });

    const stageChipsHtml = `
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;padding:12px 14px;
                    background:#f8faf8;border-radius:12px;border:1px solid #e2efe8;">
            <div style="display:flex;align-items:center;gap:6px;color:#666;font-size:0.78rem;font-weight:700;margin-right:6px;">
                <i class="fas fa-layer-group"></i> Stage:
            </div>
            ${visibleStages.map(([key, stage]) => {
                const isActive = currentStageFilter === key;
                const cnt = stageCounts[key] || 0;
                const tooltip = stage.description
                    ? ` title="${escapeHtml(stage.description)}${stage.actor ? ` — Handled by: ${stage.actor}` : ''}"`
                    : '';
                return `<button type="button" onclick="setStageFilter('${key}')"${tooltip}
                               style="padding:7px 13px;border-radius:20px;
                                      border:1.5px solid ${isActive ? stage.color : '#e2efe8'};
                                      background:${isActive ? stage.color : '#fff'};
                                      color:${isActive ? '#fff' : '#555'};
                                      font-size:0.75rem;font-weight:700;cursor:pointer;
                                      display:inline-flex;align-items:center;gap:6px;
                                      transition:all .15s;">
                    <i class="fas ${stage.icon}"></i> ${stage.label}
                    <span style="background:${isActive ? 'rgba(255,255,255,0.3)' : '#f0f0f0'};
                                 padding:1px 7px;border-radius:10px;font-size:0.68rem;">${cnt}</span>
                </button>`;
            }).join('')}
        </div>
    `;

    const legendHtml = `
        <details style="margin-bottom:16px;padding:10px 14px;background:#fafbfa;border-radius:10px;border:1px solid #e2efe8;">
            <summary style="cursor:pointer;font-size:0.8rem;font-weight:700;color:#555;">
                <i class="fas fa-info-circle"></i> What do these stages mean?
            </summary>
            <div style="margin-top:10px;font-size:0.78rem;color:#666;line-height:1.7;">
                ${Object.entries(COMPLAINT_STAGES)
                    .filter(([k, s]) => k !== 'all' && (!s.forRole || s.forRole === 'both' || s.forRole === currentRole))
                    .map(([k, s]) => `
                        <div style="display:flex;gap:10px;margin-bottom:8px;align-items:flex-start;">
                            <i class="fas ${s.icon}" style="color:${s.color};width:18px;margin-top:3px;flex-shrink:0;"></i>
                            <div style="flex:1;">
                                <div>
                                    <strong style="color:${s.color};">${s.label}</strong>
                                    <span style="color:#999;"> — ${escapeHtml(s.description)}</span>
                                </div>
                                ${s.actor ? `<div style="font-size:0.72rem;color:#888;margin-top:2px;">
                                    <i class="fas fa-user-tie"></i> Handled by: <strong>${escapeHtml(s.actor)}</strong>
                                </div>` : ''}
                            </div>
                        </div>
                    `).join('')}
            </div>
        </details>
    `;

    const filteredList = filterByStage(currentList, currentStageFilter, currentRole);

    let bodyHtml = '';
    if (!filteredList.length) {
        const stageInfo = COMPLAINT_STAGES[currentStageFilter];
        bodyHtml = `
            <div class="empty-state">
                <i class="fas ${stageInfo ? stageInfo.icon : 'fa-folder-open'} fa-3x"></i>
                <p>${currentStageFilter === 'all'
                    ? (currentComplaintTab === 'complainant'
                        ? "You haven't filed any complaints yet."
                        : "No complaints have named you as respondent yet.")
                    : `No complaints in the "${stageInfo.label}" stage.`}</p>
                ${currentStageFilter !== 'all'
                    ? `<button type="button" class="btn-verify" onclick="setStageFilter('all')" style="margin-top:12px;">
                           <i class="fas fa-list"></i> Show All
                       </button>`
                    : ''}
            </div>`;
    } else {
        const { upcoming, others } = splitByUpcomingHearing(filteredList);
        const gridStyle = `display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;align-items:start;`;

        const sectionHeader = (icon, label, count, color = '#2E7D32') => `
            <div style="display:flex;align-items:center;gap:10px;margin:0 0 14px;padding:10px 14px;
                        background:${color}0D;border-left:4px solid ${color};border-radius:10px;">
                <i class="fas ${icon}" style="color:${color};font-size:1rem;"></i>
                <div style="font-weight:800;color:${color};font-size:0.9rem;letter-spacing:0.3px;text-transform:uppercase;">
                    ${label}
                </div>
                <span style="background:${color};color:#fff;padding:2px 10px;border-radius:12px;font-size:0.7rem;font-weight:800;">${count}</span>
            </div>`;

        const divider = `
            <div style="display:flex;align-items:center;gap:12px;margin:28px 0 20px;">
                <div style="flex:1;height:1px;background:linear-gradient(to right, transparent, #cfd8dc, transparent);"></div>
                <div style="display:inline-flex;align-items:center;gap:8px;padding:6px 16px;border-radius:20px;
                            background:#f8faf8;border:1px solid #e2efe8;color:#666;font-size:0.72rem;font-weight:800;
                            letter-spacing:0.4px;text-transform:uppercase;">
                    <i class="fas fa-ellipsis-h"></i> Other Complaints
                </div>
                <div style="flex:1;height:1px;background:linear-gradient(to right, transparent, #cfd8dc, transparent);"></div>
            </div>`;

        let html = '';
        if (upcoming.length) {
            html += sectionHeader('fa-calendar-check', 'Upcoming Hearings', upcoming.length, '#6a1b9a');
            html += `<div style="${gridStyle}">
                ${upcoming.map(c => renderComplaintCard(c, currentComplaintTab)).join('')}
            </div>`;
        }
        if (others.length) {
            if (upcoming.length) html += divider;
            if (!upcoming.length) {
                const stageInfo = COMPLAINT_STAGES[currentStageFilter];
                const label = currentStageFilter === 'all' ? 'All Complaints' : `${stageInfo.label} Complaints`;
                const icon  = currentStageFilter === 'all' ? 'fa-folder-open' : stageInfo.icon;
                const color = currentStageFilter === 'all' ? '#2E7D32' : stageInfo.color;
                html += sectionHeader(icon, label, others.length, color);
            }
            html += `<div style="${gridStyle}">
                ${others.map(c => renderComplaintCard(c, currentComplaintTab)).join('')}
            </div>`;
        }
        bodyHtml = html;
    }

    container.innerHTML = tabsHtml + stageChipsHtml + legendHtml + bodyHtml;
    startLiveCountdowns();
}

function splitByUpcomingHearing(list) {
    const now = Date.now();

    const upcoming = [];
    const others   = [];

    list.forEach(c => {
        if (!c.hearing_date) { others.push(c); return; }

        const dtStr = c.hearing_date + (c.hearing_time ? ' ' + c.hearing_time : ' 00:00:00');
        const ts = new Date(dtStr.replace(' ', 'T')).getTime();

        if (isNaN(ts) || ts <= now) {
            others.push(c);
        } else {
            upcoming.push({ ...c, __hearingTs: ts });
        }
    });

    upcoming.sort((a, b) => a.__hearingTs - b.__hearingTs);

    return { upcoming, others };
}

function switchComplaintTab(tab) {
    currentComplaintTab = tab;
    currentStageFilter = 'all';
    renderComplaintsWithTabs();
}

function setStageFilter(stageKey) {
    if (stageKey !== 'all' && !COMPLAINT_STAGES[stageKey]) return;
    currentStageFilter = stageKey;
    renderComplaintsWithTabs();
}

function setStatusFilter(key) {
const legacyMap = {
    'all': 'all',
    'pending_review': 'intake',
    'pending_captain_action': 'intake',
    'pending_captain_review': 'intake',
    'summoned': 'summoned',
    'for_mediation': 'mediation',
    'mediation_scheduled': 'mediation',
    'in_mediation': 'mediation',
    'pangkat_constitution_scheduled': 'pangkat_constitution',
    'pangkat_constituted': 'pangkat_constitution',
    'pangkat_scheduled': 'pangkat_conciliation',
    'settled_pending': 'settled_pending',
    'settled': 'resolved',
    'released': 'resolved',
    'certificate_issued': 'resolved',
    'failed_mediation': 'pangkat_constitution',
    'failed_conciliation_final': 'failed',
    'unresolved': 'failed',
    'referred': 'referred',
    'referred_dispatched': 'referred',
    'escalated': 'referred',
    'dismissed': 'dismissed',
};
    setStageFilter(legacyMap[key] || 'all');
}

function getStatusBadgeInfo(s) {
    const map = {
        'pending_review':                 { label: 'Pending Review',                 color: '#f57c00', bg: '#fff8e1', icon: 'fa-clock' },
        'pending_captain_action':         { label: 'Awaiting Captain Action',        color: '#f57c00', bg: '#fff8e1', icon: 'fa-hourglass-half' },
        'summoned':                       { label: 'Summons Issued',                 color: '#1565c0', bg: '#e3f2fd', icon: 'fa-envelope-open-text' },
        'for_mediation':                  { label: 'For Mediation',                  color: '#6a1b9a', bg: '#f3e5f5', icon: 'fa-gavel' },
        'mediation_scheduled':            { label: 'Mediation Hearing Scheduled',    color: '#6a1b9a', bg: '#f3e5f5', icon: 'fa-calendar-check' },
        'in_mediation':                   { label: 'In Mediation',                   color: '#6a1b9a', bg: '#f3e5f5', icon: 'fa-handshake' },
        'failed_mediation':               { label: 'Awaiting Pangkat Constitution',  color: '#4a148c', bg: '#f3e5f5', icon: 'fa-hourglass-half' },
        'pangkat_constitution_scheduled': { label: 'Constitution Meeting Set',       color: '#4a148c', bg: '#f3e5f5', icon: 'fa-calendar-check' },
        'pangkat_constituted':            { label: 'Awaiting Position Assignment',   color: '#4a148c', bg: '#f3e5f5', icon: 'fa-users-cog' },
        'pangkat_scheduled':              { label: 'Conciliation Hearing Scheduled', color: '#00838f', bg: '#e0f7fa', icon: 'fa-gavel' },
        'settled_pending':                { label: 'Awaiting Repudiation Window',    color: '#2e7d32', bg: '#e8f5e9', icon: 'fa-hourglass-half' },
        'settled':                        { label: 'Settled — Executory',            color: '#1b5e20', bg: '#e8f5e9', icon: 'fa-handshake' },
        'certificate_issued':             { label: 'Certificate Issued',             color: '#1b5e20', bg: '#e8f5e9', icon: 'fa-certificate' },
        'released':                       { label: 'Released to Complainant',        color: '#1565c0', bg: '#e3f2fd', icon: 'fa-check-double' },
        'arbitration_pending':            { label: 'Arbitration — Awaiting Repudiation', color: '#e65100', bg: '#fff3e0', icon: 'fa-gavel' },
        'arbitration_repudiated':         { label: 'Arbitration Repudiated',             color: '#c62828', bg: '#ffebee', icon: 'fa-file-signature' },
        'amicable_repudiated':            { label: 'Settlement Repudiated',              color: '#c62828', bg: '#ffebee', icon: 'fa-file-signature' },
        'unresolved':                     { label: 'Unresolved — For Certification', color: '#b71c1c', bg: '#ffebee', icon: 'fa-file-certificate' },
        'failed_conciliation_final':      { label: 'Unresolved — For Certification', color: '#b71c1c', bg: '#ffebee', icon: 'fa-file-certificate' },
        'escalated':                      { label: 'ESCALATED — Non-Jurisdictional', color: '#c62828', bg: '#ffebee', icon: 'fa-exclamation-triangle' },
        'pending_captain_review':         { label: 'Awaiting Captain Signature',     color: '#f57c00', bg: '#fff8e1', icon: 'fa-signature' },
        'referred_dispatched':            { label: 'For Release to Complainant',     color: '#8e24aa', bg: '#f3e5f5', icon: 'fa-inbox' },
        'referred':                       { label: 'Referred to Agency',             color: '#8e24aa', bg: '#f3e5f5', icon: 'fa-paper-plane' },
        'dismissed':                      { label: 'Dismissed',                      color: '#555',    bg: '#e0e0e0', icon: 'fa-ban' },
    };
    return map[s] || { label: (s || '').replace(/_/g, ' '), color: '#666', bg: '#f0f0f0', icon: 'fa-info-circle' };
}

function getRoleAwareBadgeInfo(c, roleTab) {
    const ambiguous = ['summoned', 'failed_mediation'];
    if (ambiguous.includes(c.status)) {
        const stageKey = resolveStageForComplaint(c, roleTab);
        const stage = COMPLAINT_STAGES[stageKey];
        if (stage) {
            return {
                label: stage.label,
                color: stage.color,
                bg:    stage.color + '15',
                icon:  stage.icon,
            };
        }
    }
    return getStatusBadgeInfo(c.status);
}

// ============================================================
// REPUDIATION MODAL
// ============================================================
async function openRepudiationModal(complaintId) {
    Swal.fire({ title: 'Loading…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    let state;
    try {
        const r = await fetch(`complaint_ajax.php?action=get_repudiation_state&complaint_id=${complaintId}`);
        state = await r.json();
    } catch (e) {
        Swal.close();
        showErrorModal('Could not load the repudiation details.');
        return;
    }
    Swal.close();

    if (!state || !state.success) {
        showErrorModal(state?.message || 'Could not load the repudiation details.');
        return;
    }

    if (state.already_filed) {
        const filedDate = new Date(state.already_filed.filed_at.replace(' ', 'T'))
            .toLocaleString('en-US', { dateStyle: 'long', timeStyle: 'short' });
        const agreementLabel = (state.already_filed.repudiated_agreement === 'amicable_settlement')
            ? 'Amicable Settlement (KP #16)'
            : 'Agreement for Arbitration (KP #14)';
        await Swal.fire({
            icon: 'info',
            title: 'Repudiation Already Filed',
            html: `<div style="text-align:left;font-size:0.9rem;line-height:1.6;">
                <p>You already filed a repudiation for this complaint on <strong>${escapeHtml(filedDate)}</strong>.</p>
                <p><strong>Agreement:</strong> ${escapeHtml(agreementLabel)}</p>
                <p><strong>Ground:</strong> ${escapeHtml(state.already_filed.ground)}</p>
                <p><strong>Reason:</strong> ${escapeHtml(state.already_filed.reason_text)}</p>
                ${state.already_filed.kp17_pdf_path ? `
                    <a href="../${escapeHtml(state.already_filed.kp17_pdf_path)}" target="_blank"
                       style="display:inline-block;margin-top:10px;padding:8px 14px;
                              background:#c62828;color:#fff;border-radius:8px;text-decoration:none;
                              font-size:0.8rem;font-weight:700;">
                        <i class="fas fa-file-pdf"></i> View KP Form #17
                    </a>` : ''}
            </div>`,
            confirmButtonColor: '#2E7D32'
        });
        return;
    }

    if (!state.window_open) {
        await Swal.fire({
            icon: 'warning',
            title: 'Window Closed',
            text: 'The repudiation window for this agreement has already closed.',
            confirmButtonColor: '#2E7D32'
        });
        return;
    }

    const isSettlement = (state.agreement === 'amicable_settlement');
    const agreementLabel = isSettlement
        ? 'Amicable Settlement (KP Form #16)'
        : 'Agreement for Arbitration (KP Form #14)';

    const headerBg     = isSettlement
        ? 'linear-gradient(135deg,#2E7D32,#43a047)'
        : 'linear-gradient(135deg,#e65100,#f57c00)';
    const headerIcon   = isSettlement ? 'fa-file-signature' : 'fa-gavel';
    const windowBlurb  = isSettlement
        ? 'You have 10 days from the date the Amicable Settlement was signed to file this repudiation.'
        : 'You have 5 days from the date the Agreement for Arbitration was signed to file this repudiation.';
    const effectBlurb  = isSettlement
        ? 'The settlement will become <strong>void</strong>. The case will proceed to <strong>Certification to File Action (KP #20 / #21)</strong> so either party may file it in court.'
        : 'The arbitration agreement will become <strong>void</strong>. The case will <strong>fall back to mediation</strong> before the Punong Barangay.';

    const niceDeadline = new Date(state.deadline.replace(' ', 'T')).toLocaleString('en-US', {
        weekday: 'long', month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit'
    });
    const daysLabel = state.days_left > 1
        ? `${state.days_left} days left`
        : (state.days_left === 1 ? '1 day left' : 'Less than a day left');

    const result = await Swal.fire({
        title: '',
        html: `
        <div style="text-align:left;font-family:'Segoe UI',Roboto,sans-serif;">
            <div style="background:${headerBg};color:#fff;padding:18px 22px;
                        display:flex;justify-content:space-between;align-items:center;gap:14px;border-radius:14px 14px 0 0;">
                <div style="display:flex;align-items:center;gap:14px;">
                    <i class="fas ${headerIcon}" style="font-size:1.8rem;opacity:0.9;"></i>
                    <div>
                        <div style="font-size:1.1rem;font-weight:700;">File Repudiation (KP Form #17)</div>
                        <div style="font-size:0.78rem;opacity:0.9;margin-top:2px;">Complaint #${escapeHtml(state.reference_number)}</div>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:0.65rem;text-transform:uppercase;letter-spacing:1px;opacity:0.8;">Days Left</div>
                    <div style="font-size:1rem;font-weight:800;margin-top:2px;">${escapeHtml(daysLabel)}</div>
                </div>
            </div>

            <div style="padding:20px 22px;background:#fff;border:1px solid #eee;border-top:none;border-radius:0 0 14px 14px;">
                <div style="background:#f8faf8;border-left:4px solid ${isSettlement ? '#2E7D32' : '#f57c00'};
                            border-radius:10px;padding:12px 14px;margin-bottom:16px;">
                    <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;
                                font-weight:800;color:${isSettlement ? '#2E7D32' : '#e65100'};margin-bottom:4px;">
                        Agreement Being Repudiated
                    </div>
                    <div style="font-size:0.95rem;font-weight:700;color:#1a2b22;">${escapeHtml(agreementLabel)}</div>
                    <div style="font-size:0.78rem;color:#555;margin-top:6px;">${escapeHtml(windowBlurb)}</div>
                    <div style="font-size:0.75rem;color:#c62828;font-weight:800;margin-top:8px;">
                        <i class="fas fa-hourglass-half"></i> Deadline: ${escapeHtml(niceDeadline)}
                    </div>
                </div>

                <div style="background:#ffebee;border-left:4px solid #c62828;border-radius:10px;
                            padding:12px 14px;margin-bottom:16px;font-size:0.8rem;color:#b71c1c;line-height:1.55;">
                    <strong style="display:block;margin-bottom:4px;">
                        <i class="fas fa-exclamation-triangle"></i> This action cannot be undone.
                    </strong>
                    ${effectBlurb}
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block;font-size:0.85rem;font-weight:700;color:#1a472a;margin-bottom:8px;">
                        Ground for Repudiation <span style="color:#c62828;">*</span>
                    </label>
                    <div id="repudGroundGroup" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        ${[
                            { v: 'fraud',         label: 'Fraud',         icon: 'fa-user-secret' },
                            { v: 'violence',      label: 'Violence',      icon: 'fa-hand-fist' },
                            { v: 'intimidation',  label: 'Intimidation',  icon: 'fa-bullhorn' },
                            { v: 'other',         label: 'Other',         icon: 'fa-ellipsis-h' },
                        ].map(g => `
                            <label class="repud-ground" data-value="${g.v}"
                                   style="display:flex;align-items:center;gap:10px;padding:10px 12px;
                                          border:2px solid #e2efe8;border-radius:10px;background:#fff;
                                          cursor:pointer;font-size:0.85rem;font-weight:600;color:#333;transition:all .15s;">
                                <input type="radio" name="repud_ground" value="${g.v}" style="margin:0;">
                                <i class="fas ${g.icon}" style="color:#666;width:16px;text-align:center;"></i>
                                ${escapeHtml(g.label)}
                            </label>
                        `).join('')}
                    </div>
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block;font-size:0.85rem;font-weight:700;color:#1a472a;margin-bottom:6px;">
                        Reason for Repudiation <span style="color:#c62828;">*</span>
                        <span style="font-weight:400;color:#888;font-size:0.75rem;">(minimum 20 characters)</span>
                    </label>
                    <textarea id="repudReason" rows="4"
                              placeholder="Explain in detail what happened — who did what, when, and why you believe the agreement should be repudiated."
                              style="width:100%;padding:10px 12px;border:1.5px solid #cfd8dc;border-radius:8px;
                                     font-size:0.9rem;font-family:inherit;box-sizing:border-box;resize:vertical;"></textarea>
                    <div id="repudReasonCount" style="font-size:0.72rem;color:#888;margin-top:4px;">0 / 20</div>
                </div>

                <div style="margin-bottom:6px;">
                    <label style="display:block;font-size:0.85rem;font-weight:700;color:#1a472a;margin-bottom:6px;">
                        Evidence <span style="color:#888;font-weight:400;font-size:0.75rem;">(optional — PDF, JPG, PNG, WEBP, max 10 MB)</span>
                    </label>
                    <input type="file" id="repudEvidence" accept=".pdf,.jpg,.jpeg,.png,.webp"
                           style="font-size:0.82rem;color:#555;">
                </div>

                <div id="repudError" style="display:none;margin-top:12px;padding:10px 12px;
                                            background:#ffebee;border-left:4px solid #c62828;
                                            border-radius:8px;font-size:0.82rem;color:#b71c1c;"></div>
            </div>
        </div>`,
        width: 640,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-gavel"></i> File Repudiation',
        confirmButtonColor: '#c62828',
        cancelButtonText: 'Cancel',
        cancelButtonColor: '#6c757d',
        reverseButtons: true,
        allowOutsideClick: false,
        didOpen: () => {
            const reasonEl = document.getElementById('repudReason');
            const countEl  = document.getElementById('repudReasonCount');
            reasonEl.addEventListener('input', () => {
                const n = reasonEl.value.trim().length;
                countEl.textContent = `${n} / 20`;
                countEl.style.color = n >= 20 ? '#2E7D32' : '#888';
            });
            document.querySelectorAll('.repud-ground').forEach(card => {
                card.addEventListener('click', () => {
                    document.querySelectorAll('.repud-ground').forEach(c => {
                        c.style.borderColor = '#e2efe8';
                        c.style.background  = '#fff';
                        c.querySelector('i').style.color = '#666';
                    });
                    card.style.borderColor = '#c62828';
                    card.style.background  = '#fff5f5';
                    card.querySelector('i').style.color = '#c62828';
                });
            });
        },
        preConfirm: () => {
            const groundEl = document.querySelector('input[name="repud_ground"]:checked');
            const reason   = document.getElementById('repudReason').value.trim();
            const errEl    = document.getElementById('repudError');

            const fail = (msg) => {
                errEl.textContent = msg;
                errEl.style.display = 'block';
                errEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return false;
            };

            if (!groundEl) return fail('Please select a ground for the repudiation.');
            if (reason.length < 20) return fail('Please write at least 20 characters explaining your reason.');

            errEl.style.display = 'none';
            return {
                ground: groundEl.value,
                reason,
                file: document.getElementById('repudEvidence').files[0] || null,
            };
        }
    });

    if (!result.isConfirmed || !result.value) return;

    const confirmAgain = await Swal.fire({
        icon: 'warning',
        title: 'Confirm Repudiation',
        html: `<div style="text-align:left;font-size:0.9rem;line-height:1.6;">
            <p>You are about to <strong>formally repudiate</strong> the <strong>${escapeHtml(agreementLabel)}</strong>
               for complaint <strong>#${escapeHtml(state.reference_number)}</strong>.</p>
            <p style="margin-top:8px;">${effectBlurb}</p>
            <p style="margin-top:8px;color:#c62828;font-weight:700;">This cannot be undone.</p>
        </div>`,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-check"></i> Yes, File Repudiation',
        confirmButtonColor: '#c62828',
        cancelButtonText: 'Cancel',
        cancelButtonColor: '#6c757d',
        reverseButtons: true,
    });
    if (!confirmAgain.isConfirmed) return;

    Swal.fire({ title: 'Filing repudiation…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    const fd = new FormData();
    fd.append('action',       'file_repudiation');
    fd.append('complaint_id', complaintId);
    fd.append('ground',       result.value.ground);
    fd.append('reason_text',  result.value.reason);
    if (result.value.file) fd.append('evidence', result.value.file);

    let resp;
    try {
        const r = await fetch('complaint_ajax.php', { method: 'POST', body: fd });
        resp = await r.json();
    } catch (e) {
        Swal.close();
        showErrorModal('Network error while filing the repudiation.');
        return;
    }
    Swal.close();

    if (!resp || !resp.success) {
        showErrorModal(resp?.message || 'Could not file the repudiation.');
        return;
    }

    await Swal.fire({
        icon: 'success',
        title: 'Repudiation Filed',
        html: `<div style="text-align:left;font-size:0.9rem;line-height:1.6;">
            <p>Your repudiation has been filed and <strong>KP Form #17</strong> has been issued.</p>
            <div style="margin-top:12px;padding:12px 14px;background:#ffebee;border-left:4px solid #c62828;border-radius:8px;font-size:0.83rem;color:#b71c1c;">
                <strong>${isSettlement ? 'The Amicable Settlement is now void.' : 'The Agreement for Arbitration is now void.'}</strong><br>
                ${isSettlement
                    ? 'The case will proceed to Certification to File Action. You may claim it at the Barangay Hall.'
                    : 'The case will return to mediation before the Punong Barangay.'}
            </div>
            ${resp.kp17_pdf_path ? `
                <a href="../${escapeHtml(resp.kp17_pdf_path)}" target="_blank"
                   style="display:inline-block;margin-top:12px;padding:8px 14px;
                          background:#c62828;color:#fff;border-radius:8px;text-decoration:none;
                          font-size:0.8rem;font-weight:700;">
                    <i class="fas fa-file-pdf"></i> View KP Form #17
                </a>` : ''}
        </div>`,
        confirmButtonText: '<i class="fas fa-check"></i> Done',
        confirmButtonColor: '#2E7D32',
        width: 560,
    });

    if (typeof showMyComplaints === 'function') showMyComplaints();
}

window.openRepudiationModal = openRepudiationModal;

function renderComplaintCard(c, roleTab) {
    const st = getRoleAwareBadgeInfo(c, roleTab);
    const isRespondent = roleTab === 'respondent';
    const isAnonymous  = c.is_anonymous == 1;
    const roleStripeColor = isRespondent ? '#e65100' : '#2E7D32';
    const roleLabel       = isRespondent ? 'Respondent' : 'Complainant';
    const roleIcon        = isRespondent ? 'fa-user-friends' : 'fa-user';
    const filedDate = c.created_at ? new Date(c.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—';
    const canCancel = !isRespondent && (c.status === 'pending_review' || c.status === 'summoned');

    const priorityColors = { urgent: '#c62828', high: '#e65100', medium: '#f9a825', low: '#43a047' };
    const priorityColor  = priorityColors[c.priority] || '#888';

let repudiationBlock = '';

const buildRepudiationBlock = (opts) => {
    const { deadlineIso, color, borderColor, bg, headerIcon, headerText,
            explainer, newStatusLine, complaintId } = opts;
    const dt = new Date(deadlineIso.replace(' ', 'T'));
    if (isNaN(dt.getTime())) return '';

    const isOpen = dt.getTime() > Date.now();
    const niceDeadline = dt.toLocaleDateString('en-US', {
        weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'
    });

    const blockTooltip  = `${headerText}\n\n${explainer}`;
    const buttonTooltip = isOpen
        ? `${newStatusLine}\n\nDeadline: ${niceDeadline}`
        : 'The repudiation window has closed.';

    return `
        <div title="${escapeHtml(blockTooltip)}"
             style="margin-top:12px;padding:10px 12px;border-radius:10px;
                    background:${bg};border-left:4px solid ${borderColor};">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                <span title="${escapeHtml(blockTooltip)}"
                      style="display:inline-flex;align-items:center;gap:6px;
                             font-size:0.72rem;font-weight:800;text-transform:uppercase;
                             letter-spacing:0.4px;color:${color};">
                    <i class="fas ${headerIcon}"></i> ${escapeHtml(headerText)}
                </span>
                <span style="display:inline-flex;align-items:center;gap:6px;
                             font-size:0.72rem;color:#555;">
                    <i class="fas fa-stopwatch" style="color:${color};"></i>
                    <span class="live-countdown" data-deadline="${escapeHtml(deadlineIso)}"
                          style="font-family:'Courier New',monospace;font-weight:800;
                                 font-size:0.78rem;color:${color};">…</span>
                </span>
            </div>
            ${isOpen ? `
                <button type="button"
                        title="${escapeHtml(buttonTooltip)}"
                        onclick="event.stopPropagation(); openRepudiationModal(${complaintId})"
                        style="margin-top:10px;width:100%;padding:9px 12px;border:none;
                               border-radius:10px;background:linear-gradient(135deg,#c62828,#e53935);
                               color:#fff;font-size:0.82rem;font-weight:800;cursor:pointer;
                               display:inline-flex;align-items:center;justify-content:center;gap:8px;
                               box-shadow:0 3px 10px rgba(198,40,40,0.25);">
                    <i class="fas fa-gavel"></i> File Repudiation (KP #17)
                </button>` : `
                <div title="${escapeHtml(buttonTooltip)}"
                     style="margin-top:8px;padding:7px 10px;background:#ffebee;
                            border-left:3px solid #c62828;border-radius:6px;
                            font-size:0.72rem;font-weight:700;color:#b71c1c;
                            display:inline-flex;align-items:center;gap:6px;">
                    <i class="fas fa-lock"></i> Window closed
                </div>`}
        </div>`;
};

if (c.status === 'settled_pending'
    && c.repudiation_deadline
    && Number(c.amicable_repudiated || 0) === 0) {
    repudiationBlock = buildRepudiationBlock({
        complaintId:   c.id,
        deadlineIso:   c.repudiation_deadline,
        color:         '#2e7d32',
        borderColor:   '#2e7d32',
        bg:            'linear-gradient(135deg,#e8f5e9 0%,#f1f8e9 100%)',
        headerIcon:    'fa-file-signature',
        headerText:    'Settlement — 10-Day Repudiation Window',
        explainer:     'Grounds: fraud, violence, or intimidation. If repudiated, the settlement is void and the case proceeds to Certification to File Action.',
        newStatusLine: 'Filing a repudiation will void the settlement and send the case to Certification to File Action.',
    });
}

if (c.status === 'arbitration_pending'
    && c.repudiation_deadline
    && Number(c.arbitration_repudiated || 0) === 0) {
    repudiationBlock = buildRepudiationBlock({
        complaintId:   c.id,
        deadlineIso:   c.repudiation_deadline,
        color:         '#e65100',
        borderColor:   '#f57c00',
        bg:            'linear-gradient(135deg,#fff8e1 0%,#ffecb3 100%)',
        headerIcon:    'fa-gavel',
        headerText:    'Arbitration — 5-Day Repudiation Window',
        explainer:     'Grounds: fraud, violence, or intimidation. If repudiated, the arbitration is void and the case returns to mediation.',
        newStatusLine: 'Filing a repudiation will void the arbitration agreement and send the case back to mediation.',
    });
}

let repudiationFiledBlock = '';

if (Number(c.amicable_repudiated || 0) === 1) {
    repudiationFiledBlock = `
        <div style="margin-top:12px;padding:10px 12px;border-radius:10px;
                    background:#ffebee;border-left:4px solid #c62828;">
            <div style="display:inline-flex;align-items:center;gap:6px;
                        font-size:0.72rem;font-weight:800;text-transform:uppercase;
                        letter-spacing:0.4px;color:#c62828;">
                <i class="fas fa-file-signature"></i> Settlement Repudiated (KP #17)
            </div>
            <div style="font-size:0.78rem;color:#555;margin-top:6px;line-height:1.5;">
                The <strong>Amicable Settlement (KP #16)</strong> was repudiated
                by the <strong>${escapeHtml(c.amicable_repudiated_by_party || 'party')}</strong>.
                The case will proceed to Certification to File Action.
            </div>
            ${c.kp17_pdf_path ? `
                <a href="../${escapeHtml(c.kp17_pdf_path)}" target="_blank" rel="noopener"
                   style="display:inline-block;margin-top:8px;padding:5px 12px;
                          background:#c62828;color:#fff;border-radius:6px;
                          text-decoration:none;font-size:0.72rem;font-weight:700;">
                    <i class="fas fa-file-pdf"></i> View KP Form #17
                </a>` : ''}
        </div>`;
} else if (Number(c.arbitration_repudiated || 0) === 1) {
    repudiationFiledBlock = `
        <div style="margin-top:12px;padding:10px 12px;border-radius:10px;
                    background:#ffebee;border-left:4px solid #c62828;">
            <div style="display:inline-flex;align-items:center;gap:6px;
                        font-size:0.72rem;font-weight:800;text-transform:uppercase;
                        letter-spacing:0.4px;color:#c62828;">
                <i class="fas fa-gavel"></i> Arbitration Repudiated (KP #17)
            </div>
            <div style="font-size:0.78rem;color:#555;margin-top:6px;line-height:1.5;">
                The <strong>Agreement for Arbitration (KP #14)</strong> was repudiated
                by the <strong>${escapeHtml(c.arbitration_repudiated_by_party || 'party')}</strong>.
                The case has fallen back to mediation.
            </div>
            ${c.kp17_pdf_path ? `
                <a href="../${escapeHtml(c.kp17_pdf_path)}" target="_blank" rel="noopener"
                   style="display:inline-block;margin-top:8px;padding:5px 12px;
                          background:#c62828;color:#fff;border-radius:6px;
                          text-decoration:none;font-size:0.72rem;font-weight:700;">
                    <i class="fas fa-file-pdf"></i> View KP Form #17
                </a>` : ''}
        </div>`;
}

let hearingBlock = '';

const HEARING_ACTIVE_STATUSES = [
    'for_mediation', 'mediation_scheduled', 'in_mediation',
    'pangkat_constitution_scheduled',
    'pangkat_constituted', 'pangkat_scheduled',
];
const isHearingPhaseActive = HEARING_ACTIVE_STATUSES.includes(c.status);

if (c.hearing_date && isHearingPhaseActive) {
    const hDateTime = c.hearing_date + (c.hearing_time ? ' ' + c.hearing_time : ' 00:00:00');
    const hearingTs = new Date(hDateTime.replace(' ', 'T')).getTime();
    const isPast    = !isNaN(hearingTs) && hearingTs < Date.now();

    if (!isPast) {
            const niceDate = new Date(c.hearing_date).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
            const niceTime = c.hearing_time ? formatTime12(c.hearing_time) : '';
            const loc      = c.hearing_location || 'Barangay Hall';
            const isToday  = c.hearing_date === new Date().toISOString().split('T')[0];

            let hearingLabel = 'Hearing Scheduled';
            let accentColor  = '#6a1b9a';
            if (c.status === 'for_mediation' || c.status === 'mediation_scheduled') {
                hearingLabel = 'Mediation Hearing';
                accentColor  = '#6a1b9a';
            } else if (c.status === 'pangkat_scheduled' || c.status === 'pangkat_constituted') {
                hearingLabel = 'Conciliation Hearing';
                accentColor  = '#00838f';
            } else if (c.status === 'pangkat_constitution_scheduled') {
                hearingLabel = 'Pangkat Constitution Meeting';
                accentColor  = '#4a148c';
            }

            hearingBlock = `
                <div style="margin-top:12px;padding:12px 14px;border-radius:10px;
                            background:linear-gradient(135deg, #f3e5f5 0%, #ede7f6 100%);
                            border-left:4px solid ${accentColor};">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
                        <div style="display:inline-flex;align-items:center;gap:6px;font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.4px;
                                    color:${accentColor};">
                            <i class="fas fa-gavel"></i> ${hearingLabel}
                        </div>
                        ${isToday ? `<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;font-size:0.62rem;font-weight:800;background:#c62828;color:#fff;text-transform:uppercase;letter-spacing:0.3px;animation:pulseToday 1.5s infinite;"><i class="fas fa-bell"></i> Today</span>` : ''}
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:10px;font-size:0.78rem;color:#333;">
                        <span style="display:inline-flex;align-items:center;gap:5px;">
                            <i class="fas fa-calendar" style="color:${accentColor};"></i>
                            ${escapeHtml(niceDate)}
                        </span>
                        ${niceTime ? `
                        <span style="display:inline-flex;align-items:center;gap:5px;">
                            <i class="fas fa-clock" style="color:${accentColor};"></i>
                            ${escapeHtml(niceTime)}
                        </span>` : ''}
                        <span style="display:inline-flex;align-items:center;gap:5px;">
                            <i class="fas fa-map-marker-alt" style="color:${accentColor};"></i>
                            ${escapeHtml(loc)}
                        </span>
                    </div>
                    <div style="margin-top:8px;padding-top:8px;border-top:1px dashed rgba(106,27,154,0.2);
                                display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <i class="fas fa-stopwatch" style="color:${accentColor};font-size:0.85rem;"></i>
                        <span style="font-size:0.68rem;font-weight:800;color:#666;text-transform:uppercase;letter-spacing:0.4px;">Starts in</span>
                        <span class="live-countdown" data-deadline="${escapeHtml(hDateTime)}"
                              style="font-family:'Courier New',monospace;font-weight:800;font-size:0.85rem;color:${accentColor};">
                            Calculating…
                        </span>
                    </div>
                </div>`;
        }
    }

    return `
        <div style="position:relative;background:#fff;border-radius:14px;overflow:hidden;
                    box-shadow:0 2px 10px rgba(0,0,0,0.06);border:1px solid #e2efe8;
                    cursor:pointer;transition:all 0.2s ease;display:flex;flex-direction:column;"
             onclick="viewComplaintDetails(${c.id})"
             onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 22px rgba(0,0,0,0.10)';"
             onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 2px 10px rgba(0,0,0,0.06)';">
            <div style="height:5px;background:${roleStripeColor};"></div>
            <div style="padding:14px 16px 10px;display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">
                <div style="flex:1;min-width:0;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
                        <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:12px;font-size:0.68rem;font-weight:800;text-transform:uppercase;letter-spacing:0.4px;background:${roleStripeColor}15;color:${roleStripeColor};">
                            <i class="fas ${roleIcon}"></i> ${roleLabel}
                        </span>
                        ${isAnonymous ? `<span style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:12px;font-size:0.68rem;font-weight:800;text-transform:uppercase;letter-spacing:0.4px;background:#fff8e1;color:#e65100;"><i class="fas fa-user-shield"></i> Anonymous</span>` : ''}
                    </div>
                    <div style="font-weight:800;color:#1a2b22;font-size:0.95rem;line-height:1.35;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">
                        ${escapeHtml(c.title || 'Untitled Complaint')}
                    </div>
                </div>
                <span style="display:inline-flex;align-items:center;gap:5px;padding:5px 11px;border-radius:20px;font-size:0.68rem;font-weight:800;text-transform:uppercase;letter-spacing:0.4px;background:${st.bg};color:${st.color};white-space:nowrap;flex-shrink:0;">
                    <i class="fas ${st.icon}"></i> ${st.label}
                </span>
            </div>
            <div style="padding:6px 16px 14px;flex:1;">
                <div style="display:flex;gap:10px;font-size:0.78rem;color:#666;margin-bottom:8px;flex-wrap:wrap;">
                    <span><i class="fas fa-hashtag" style="color:#999;"></i> ${escapeHtml(c.reference_number)}</span>
                    <span><i class="fas fa-calendar" style="color:#999;"></i> ${escapeHtml(filedDate)}</span>
                </div>
                <div style="font-size:0.82rem;color:#555;line-height:1.5;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">
                    ${escapeHtml((c.description || '').substring(0, 140))}${(c.description || '').length > 140 ? '…' : ''}
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                    <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:8px;background:#f0f4ff;color:#3949ab;font-size:0.68rem;font-weight:700;">
                        <i class="fas fa-tag"></i> ${escapeHtml(c.complaint_subject || '—')}
                    </span>
                    <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:8px;background:${priorityColor}15;color:${priorityColor};font-size:0.68rem;font-weight:800;text-transform:uppercase;letter-spacing:0.3px;">
                        <i class="fas fa-flag"></i> ${(c.priority || 'medium').toUpperCase()}
                    </span>
                </div>
                ${c.status === 'failed_mediation' ? `
                    <div style="margin-top:10px;padding:10px 12px;background:#f3e5f5;border-left:3px solid #4a148c;border-radius:8px;font-size:0.75rem;color:#4a148c;line-height:1.5;">
                        <i class="fas fa-info-circle"></i>
                        <strong>Mediation ended.</strong> The Punong Barangay is now preparing the Pangkat ng Tagapagkasundo.
                    </div>` : ''}

                ${c.status === 'pangkat_constituted' ? `
                    <div style="margin-top:10px;padding:10px 12px;background:#f3e5f5;border-left:3px solid #4a148c;border-radius:8px;font-size:0.75rem;color:#4a148c;line-height:1.5;">
                        <i class="fas fa-info-circle"></i>
                        The <strong>Chairperson</strong> will assign positions and schedule the conciliation hearing.
                    </div>` : ''}
                ${hearingBlock}
                ${repudiationFiledBlock}
                ${repudiationBlock}
            </div>
            <div style="padding:11px 16px;background:#f8faf8;border-top:1px solid #e2efe8;display:flex;gap:8px;align-items:center;">
                <button type="button" onclick="event.stopPropagation(); viewComplaintDetails(${c.id})"
                        style="flex:1;padding:9px 12px;border:none;border-radius:8px;background:${roleStripeColor};color:#fff;font-size:0.8rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;">
                    <i class="fas fa-eye"></i> View Details
                </button>
                ${canCancel ? `<button type="button" onclick="event.stopPropagation(); cancelMyComplaint(${c.id}, '${escapeHtml(c.reference_number)}')"
                        style="padding:9px 12px;border:1.5px solid #ef9a9a;border-radius:8px;background:#fff;color:#c62828;font-size:0.8rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;">
                    <i class="fas fa-times-circle"></i> Cancel
                </button>` : ''}
            </div>
        </div>
    `;
}

function formatTime12(timeStr) {
    if (!timeStr) return '';
    const parts = timeStr.split(':');
    if (parts.length < 2) return timeStr;
    let h = parseInt(parts[0], 10);
    const m = parts[1];
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return `${h}:${m} ${ampm}`;
}

(function injectHearingCardStyles() {
    if (document.getElementById('hearingCardStyles')) return;
    const s = document.createElement('style');
    s.id = 'hearingCardStyles';
    s.textContent = `
        @keyframes pulseToday {
            0%, 100% { box-shadow: 0 0 0 0 rgba(198,40,40,0.5); }
            50%      { box-shadow: 0 0 0 6px rgba(198,40,40,0); }
        }
    `;
    document.head.appendChild(s);
})();

async function viewComplaintDetails(id) {
    currentComplaintId = id;
    const container = document.getElementById('complaintContent');
    container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading details...</p></div>';
    try {
        const resp = await fetch(`complaint_ajax.php?action=get_complaint_details&id=${id}`);
        const data = await resp.json();
        if (data.success) renderComplaintDetails(data);
        else container.innerHTML = `<div class="empty-state"><p>${escapeHtml(data.message || 'Error loading details.')}</p><button class="btn-verify" onclick="showMyComplaints()" style="margin-top:12px;"><i class="fas fa-arrow-left"></i> Back</button></div>`;
    } catch (err) {
        container.innerHTML = '<div class="empty-state"><p>Error loading details.</p></div>';
    }
}

// ============================================================
// SECTION 15B: COMPLAINT DETAILS — Documentable UI
// ============================================================
function renderComplaintDetails(data) {
    const c = data.complaint;
    const myRole = data.my_role || 'complainant';
    const isRespondent = myRole === 'respondent';

    const hearings = data.hearings || [];
    const complainants = data.complainants || [];
    const respondents = data.respondents || [];
    const witnesses = data.witnesses || [];
    const incidents = data.incidents || [];
    const evidence = data.evidence || [];
    const kpForms = data.kp_forms || [];

    const st = getStatusBadgeInfo(c.status);
    const roleStripeColor = isRespondent ? '#e65100' : '#2E7D32';

    const filedDateNice = c.created_at ? new Date(c.created_at).toLocaleString('en-US', { dateStyle: 'long', timeStyle: 'short' }) : '—';

    const roleBanner = isRespondent ? `
        <div style="background:linear-gradient(135deg,#fff3e0,#ffe0b2);border-left:4px solid #e65100;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;gap:12px;align-items:flex-start;">
            <i class="fas fa-user-friends" style="color:#e65100;font-size:1.4rem;flex-shrink:0;"></i>
            <div style="font-size:0.85rem;color:#5d4037;line-height:1.5;">
                <strong style="display:block;margin-bottom:4px;color:#e65100;">You are the Respondent in this case</strong>
                You will see notices and updates issued by the Barangay. Some internal details are not shown here.
            </div>
        </div>` : '';

    const anonBanner = c.is_anonymous == 1 ? `
        <div style="background:linear-gradient(135deg,#fff8e1,#ffecb3);border-left:4px solid #ef6c00;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;gap:12px;align-items:flex-start;">
            <i class="fas fa-user-shield" style="color:#e65100;font-size:1.5rem;flex-shrink:0;"></i>
            <div style="font-size:0.82rem;color:#5d4037;line-height:1.5;">
                <strong style="display:block;margin-bottom:4px;color:#e65100;">Whistleblower Protection Active</strong>
                This complaint was filed anonymously.
            </div>
        </div>` : '';

    const sectionHeader = (num, icon, title) => `
        <div style="display:flex;align-items:center;gap:12px;font-size:0.95rem;font-weight:800;color:#1a472a;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:14px;padding-bottom:10px;border-bottom:2px solid #e2efe8;">
            <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;background:#2E7D32;color:#fff;border-radius:50%;font-size:0.8rem;font-weight:700;flex-shrink:0;">${num}</span>
            <i class="fas ${icon}"></i> ${title}
        </div>`;

    const docBlock = (num, icon, title, bodyHtml) => `
        <div style="background:#fff;border-radius:12px;padding:18px 22px;border:1px solid #e2efe8;margin-bottom:16px;">
            ${sectionHeader(num, icon, title)}
            ${bodyHtml}
        </div>`;

    let progressHtml = '';
    if (!isRespondent) {
        const order = ['pending_review','pending_captain_action','summoned','for_mediation','mediation_scheduled','settled'];
        const currentIdx = order.indexOf(c.status);
        progressHtml = `
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin:14px 0 20px;">
                ${[
                    { key: 'pending_review', label: '1. Filed' },
                    { key: 'pending_captain_action', label: '2. Reviewed' },
                    { key: 'summoned', label: '3. Summoned' },
                    { key: 'for_mediation', label: '4. Mediation' },
                    { key: 'mediation_scheduled', label: '5. Conciliation' },
                    { key: 'settled', label: '6. Settled' }
                ].map(step => {
                    const stepIdx = order.indexOf(step.key);
                    const isDone = stepIdx < currentIdx || c.status === 'settled';
                    const isCurrent = step.key === c.status;
                    const bg = isDone ? '#2E7D32' : (isCurrent ? '#1976d2' : '#e0e0e0');
                    const fg = (isDone || isCurrent) ? '#fff' : '#888';
                    return `<div style="background:${bg};color:${fg};padding:6px 12px;border-radius:20px;font-size:0.75rem;font-weight:600;">${step.label}</div>`;
                }).join('')}
            </div>`;
    }

    const section1 = docBlock(1, 'fa-info-circle', 'Complaint Information', `
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px 20px;">
            <div><div style="font-size:0.7rem;text-transform:uppercase;color:#999;margin-bottom:3px;letter-spacing:0.4px;font-weight:600;">Reference</div><div style="font-size:0.9rem;color:#1a2b22;font-weight:700;font-family:monospace;">${escapeHtml(c.reference_number)}</div></div>
            <div><div style="font-size:0.7rem;text-transform:uppercase;color:#999;margin-bottom:3px;letter-spacing:0.4px;font-weight:600;">Filed</div><div style="font-size:0.9rem;color:#1a2b22;font-weight:600;">${escapeHtml(filedDateNice)}</div></div>
            <div><div style="font-size:0.7rem;text-transform:uppercase;color:#999;margin-bottom:3px;letter-spacing:0.4px;font-weight:600;">Type</div><div style="font-size:0.9rem;color:#1a2b22;font-weight:600;">${escapeHtml(c.complaint_subject || '—')}</div></div>
            <div><div style="font-size:0.7rem;text-transform:uppercase;color:#999;margin-bottom:3px;letter-spacing:0.4px;font-weight:600;">Priority</div><div style="font-size:0.9rem;color:#1a2b22;font-weight:600;">${(c.priority || 'medium').toUpperCase()}</div></div>
        </div>
        <div style="margin-top:14px;">
            <div style="font-size:0.7rem;text-transform:uppercase;color:#999;margin-bottom:5px;letter-spacing:0.4px;font-weight:600;">Description</div>
            <p style="color:#1a2b22;font-size:0.92rem;line-height:1.6;margin:0;white-space:pre-wrap;">${escapeHtml(c.description || '')}</p>
        </div>
        ${c.additional_notes ? `
            <div style="margin-top:14px;">
                <div style="font-size:0.7rem;text-transform:uppercase;color:#999;margin-bottom:5px;letter-spacing:0.4px;font-weight:600;">Additional Notes</div>
                <p style="color:#1a2b22;font-size:0.92rem;line-height:1.6;margin:0;white-space:pre-wrap;">${escapeHtml(c.additional_notes)}</p>
            </div>` : ''}
    `);

    const partyCard = (p, idx, type) => {
        const roleColor = type === 'complainant' ? '#2E7D32' : '#e65100';
        const roleBg    = type === 'complainant' ? '#e8f5e9' : '#fff3e0';
        return `
            <div style="background:#fafbfa;border-left:4px solid ${roleColor};border-radius:10px;padding:12px 16px;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                    <div style="width:32px;height:32px;border-radius:50%;background:${roleBg};color:${roleColor};display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.8rem;">${idx+1}</div>
                    <div style="font-weight:700;color:#1a2b22;font-size:0.9rem;">${escapeHtml(p.full_name)}</div>
                </div>
                <div style="font-size:0.78rem;color:#666;display:flex;flex-wrap:wrap;gap:12px;padding-left:42px;">
                    ${p.address ? `<span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(p.address)}</span>` : ''}
                    ${p.contact_number ? `<span><i class="fas fa-phone"></i> ${escapeHtml(p.contact_number)}</span>` : ''}
                    ${p.relationship ? `<span><i class="fas fa-handshake"></i> ${escapeHtml(p.relationship)}</span>` : ''}
                </div>
            </div>`;
    };

    const section2 = docBlock(2, 'fa-users', `Parties Involved (${complainants.length + respondents.length}${witnesses.length ? ` + ${witnesses.length} witnesses` : ''})`, `
        ${complainants.length ? `<div style="font-size:0.75rem;font-weight:800;color:#2E7D32;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:8px;">Complainants</div>
            <div style="display:grid;gap:8px;margin-bottom:16px;">${complainants.map((p,i) => partyCard(p,i,'complainant')).join('')}</div>` : ''}
        ${respondents.length ? `<div style="font-size:0.75rem;font-weight:800;color:#e65100;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:8px;">Respondents</div>
            <div style="display:grid;gap:8px;margin-bottom:16px;">${respondents.map((p,i) => partyCard(p,i,'respondent')).join('')}</div>` : ''}
        ${witnesses.length ? `<div style="font-size:0.75rem;font-weight:800;color:#1565c0;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:8px;">Witnesses</div>
            <div style="display:grid;gap:8px;">${witnesses.map((p,i) => `
                <div style="background:#fafbfa;border-left:4px solid #1565c0;border-radius:10px;padding:12px 16px;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <div style="width:32px;height:32px;border-radius:50%;background:#e3f2fd;color:#1565c0;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.8rem;">${i+1}</div>
                        <div style="font-weight:700;color:#1a2b22;font-size:0.9rem;">${escapeHtml(p.full_name)}</div>
                    </div>
                    <div style="font-size:0.78rem;color:#666;display:flex;flex-wrap:wrap;gap:12px;padding-left:42px;">
                        ${p.address ? `<span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(p.address)}</span>` : ''}
                        ${p.contact_number ? `<span><i class="fas fa-phone"></i> ${escapeHtml(p.contact_number)}</span>` : ''}
                    </div>
                </div>`).join('')}</div>` : ''}
    `);

    const section3 = incidents.length ? docBlock(3, 'fa-calendar-alt', `Incidents (${incidents.length})`, `
        <div style="display:grid;gap:12px;">
            ${incidents.map((inc, i) => {
                const ev = evidence[i] || [];
                return `
                    <div style="background:#fafbfa;border-left:4px solid #1976d2;border-radius:10px;padding:14px 16px;">
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                            <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;background:#1976d2;color:#fff;border-radius:8px;font-weight:700;font-size:0.8rem;">${i+1}</span>
                            <div style="font-weight:800;color:#1a2b22;font-size:0.9rem;">Incident ${i+1}</div>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;font-size:0.82rem;color:#555;margin-bottom:10px;">
                            ${inc.incident_date ? `<div><i class="fas fa-calendar" style="color:#1976d2;"></i> ${new Date(inc.incident_date).toLocaleDateString('en-US', {dateStyle:'long'})}</div>` : ''}
                            ${inc.incident_time ? `<div><i class="fas fa-clock" style="color:#1976d2;"></i> ${escapeHtml(inc.incident_time)}</div>` : ''}
                            ${inc.location ? `<div><i class="fas fa-map-marker-alt" style="color:#1976d2;"></i> ${escapeHtml(inc.location)}</div>` : ''}
                        </div>
                        <p style="font-size:0.85rem;color:#1a2b22;line-height:1.6;margin:0;white-space:pre-wrap;">${escapeHtml(inc.description || '')}</p>
                        ${ev.length ? `<div style="margin-top:10px;padding-top:10px;border-top:1px dashed #cfd8dc;">
                            <div style="font-size:0.72rem;font-weight:800;color:#666;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:6px;"><i class="fas fa-paperclip"></i> Evidence (${ev.length})</div>
                            <div style="display:flex;flex-wrap:wrap;gap:6px;">
                                ${ev.map(e => `<a href="../${escapeHtml(e.file_path)}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:5px;padding:5px 10px;background:#e3f2fd;color:#1565c0;border-radius:6px;font-size:0.72rem;font-weight:600;text-decoration:none;"><i class="fas fa-file"></i> ${escapeHtml(e.file_name)}</a>`).join('')}
                            </div>
                        </div>` : ''}
                    </div>`;
            }).join('')}
        </div>
    `) : '';

    const section4 = kpForms.length ? docBlock(4, 'fa-file-signature', `Documents Issued to You (${kpForms.length})`, `
        <div style="display:grid;gap:10px;">
            ${kpForms.map(f => `
                <div style="background:#fafbfa;border-left:4px solid #1565c0;border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <div style="width:44px;height:44px;border-radius:10px;background:#e3f2fd;display:flex;align-items:center;justify-content:center;color:#1565c0;font-weight:800;font-size:0.9rem;flex-shrink:0;">
                        #${f.form_no}
                    </div>
                    <div style="flex:1;min-width:180px;">
                        <div style="font-weight:700;color:#1a2b22;font-size:0.9rem;">${escapeHtml(f.form_name)}</div>
                        <div style="font-size:0.78rem;color:#555;margin-top:2px;">${escapeHtml(f.event || '')}</div>
                        ${f.issued_at ? `<div style="font-size:0.7rem;color:#888;margin-top:4px;"><i class="fas fa-clock"></i> Issued ${new Date(f.issued_at.replace(' ','T')).toLocaleDateString('en-US',{dateStyle:'medium'})}</div>` : ''}
                    </div>
                    ${f.pdf_path ? `<a href="../${escapeHtml(f.pdf_path)}" target="_blank" rel="noopener" style="padding:8px 14px;background:#1565c0;color:#fff;text-decoration:none;border-radius:8px;font-size:0.78rem;font-weight:700;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-file-pdf"></i> Open PDF</a>` : ''}
                </div>
            `).join('')}
        </div>
    `) : '';

    const outcomeMap = { settled: 'Settled', no_settlement: 'No Settlement', cancelled: 'Cancelled' };
    const section5 = hearings.length ? docBlock(5, 'fa-gavel', `Hearing Schedule (${hearings.length})`, `
        <div style="display:grid;gap:12px;">
            ${hearings.map(h => {
                const typeLabel = h.session_type === 'mediation' ? 'Mediation' : (h.session_type === 'conciliation' ? 'Conciliation' : 'Hearing');
                const outcomeLabel = h.outcome ? (outcomeMap[h.outcome] || h.outcome.replace(/_/g,' ')) : null;
                const hearingDateTime = (h.hearing_date && h.hearing_time) ? `${h.hearing_date} ${h.hearing_time}` : (h.hearing_date ? `${h.hearing_date} 00:00:00` : null);
                const isCancelled = h.status === 'cancelled';
                const isCompleted = h.status === 'completed';
                const isScheduled = h.status === 'scheduled';

                return `
                    <div style="background:#f0f4ff;border-left:4px solid ${isCancelled ? '#9e9e9e' : (isCompleted ? '#43a047' : '#3949ab')};border-radius:10px;padding:14px 16px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px;">
                            <div style="font-weight:800;color:#1a2b22;font-size:0.92rem;display:flex;align-items:center;gap:8px;">
                                <i class="fas fa-calendar-check" style="color:${isCancelled ? '#9e9e9e' : (isCompleted ? '#43a047' : '#3949ab')};"></i>
                                ${typeLabel} Hearing #${h.session_number || 1}
                            </div>
                            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                ${isScheduled ? `<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:12px;font-size:0.66rem;font-weight:800;text-transform:uppercase;background:#e8eaf6;color:#3949ab;"><i class="fas fa-hourglass-half"></i> Scheduled</span>` : ''}
                                ${isCompleted ? `<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:12px;font-size:0.66rem;font-weight:800;text-transform:uppercase;background:#e8f5e9;color:#1b5e20;"><i class="fas fa-check-circle"></i> Completed</span>` : ''}
                                ${isCancelled ? `<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:12px;font-size:0.66rem;font-weight:800;text-transform:uppercase;background:#e0e0e0;color:#555;"><i class="fas fa-ban"></i> Cancelled</span>` : ''}
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;font-size:0.82rem;color:#555;">
                            <div><i class="fas fa-calendar" style="color:#3949ab;"></i> ${h.hearing_date ? new Date(h.hearing_date).toLocaleDateString('en-US',{dateStyle:'long'}) : '—'}</div>
                            <div><i class="fas fa-clock" style="color:#3949ab;"></i> ${escapeHtml(h.hearing_time || '—')}</div>
                            <div><i class="fas fa-map-marker-alt" style="color:#3949ab;"></i> ${escapeHtml(h.location || 'Barangay Hall')}</div>
                        </div>
                        ${isScheduled && hearingDateTime ? `
                            <div style="margin-top:10px;padding:10px 14px;background:#fff;border:1px solid #c5cae9;border-radius:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;" class="live-countdown-wrap">
                                <i class="fas fa-stopwatch" style="color:#3949ab;font-size:1.1rem;"></i>
                                <div style="font-size:0.75rem;font-weight:700;color:#666;text-transform:uppercase;letter-spacing:0.4px;">Time Remaining:</div>
                                <div class="live-countdown" data-deadline="${escapeHtml(hearingDateTime)}" style="font-family:'Courier New',monospace;font-weight:800;font-size:0.95rem;color:#3949ab;">Calculating…</div>
                            </div>` : ''}
                        ${outcomeLabel ? `<div style="font-size:0.78rem;color:#3949ab;font-weight:800;margin-top:8px;text-transform:uppercase;letter-spacing:0.4px;"><i class="fas fa-info-circle"></i> Outcome: ${escapeHtml(outcomeLabel)}</div>` : ''}
                    </div>`;
            }).join('')}
        </div>
    `) : '';

    const section6 = !isRespondent ? docBlock(6, 'fa-comment', 'Add a Note', `
        <textarea id="complaintNote" class="form-control" rows="3" placeholder="Add a note..."></textarea>
        <button class="btn-verify" onclick="addComplaintNote(${c.id})" style="margin-top:10px;"><i class="fas fa-paper-plane"></i> Add Note</button>
    `) : `
        <div style="background:#f8faf8;border-radius:12px;padding:18px 22px;border:1px dashed #e2efe8;text-align:center;margin-bottom:16px;">
            <i class="fas fa-lock" style="color:#999;font-size:1.2rem;margin-bottom:6px;display:block;"></i>
            <p style="font-size:0.8rem;color:#888;margin:0;">Notes are not available while you are a respondent in this case.</p>
        </div>`;

    const container = document.getElementById('complaintContent');
    container.innerHTML = `
        <button class="btn-back" onclick="showMyComplaints()" style="margin-bottom:20px;"><i class="fas fa-arrow-left"></i> Back to Complaints</button>

        <div style="position:relative;overflow:hidden;background:#f8faf8;border-radius:16px;padding:0 0 22px 0;">
            <div style="position:absolute;top:0;left:0;right:0;height:5px;background:${roleStripeColor};"></div>

            <div style="padding:20px 24px 4px;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;">
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:0.72rem;color:#666;text-transform:uppercase;letter-spacing:0.6px;font-weight:800;margin-bottom:6px;">Complaint Record</div>
                        <h2 style="font-size:1.2rem;font-weight:800;color:#1a2b22;margin:0 0 8px;line-height:1.35;">${escapeHtml(c.title)}</h2>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                            <span style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:20px;font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.4px;background:${st.bg};color:${st.color};">
                                <i class="fas ${st.icon}"></i> ${st.label}
                            </span>
                            <span style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:20px;font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.4px;background:${roleStripeColor}15;color:${roleStripeColor};">
                                <i class="fas ${isRespondent ? 'fa-user-friends' : 'fa-user'}"></i> ${isRespondent ? 'Respondent' : 'Complainant'}
                            </span>
                        </div>
                    </div>
                </div>
                ${progressHtml}
            </div>

            <div style="padding:0 24px;">
                ${roleBanner}
                ${anonBanner}
            </div>

            <div style="padding:0 24px;">
                ${section1}
                ${section2}
                ${section3}
                ${section4}
                ${section5}
                ${section6}
            </div>
        </div>
    `;

    startLiveCountdowns();
}

function startLiveCountdowns() {
    if (__liveTimerInterval) clearInterval(__liveTimerInterval);
    const tick = () => document.querySelectorAll('.live-countdown[data-deadline]').forEach(renderCountdown);
    tick();
    __liveTimerInterval = setInterval(tick, 1000);
}

function renderCountdown(el) {
    const iso = el.getAttribute('data-deadline');
    if (!iso) { el.textContent = '—'; return; }
    const target = new Date(iso.replace(' ', 'T'));
    if (isNaN(target.getTime())) { el.textContent = 'Invalid date'; return; }
    const diff = target.getTime() - Date.now();

    if (diff <= 0) {
        el.innerHTML = '<span style="color:#c62828;"><i class="fas fa-play-circle"></i> Hearing in progress / past</span>';
        return;
    }
    const totalSec = Math.floor(diff / 1000);
    const d = Math.floor(totalSec / 86400);
    const h = Math.floor((totalSec % 86400) / 3600);
    const m = Math.floor((totalSec % 3600) / 60);
    const s = totalSec % 60;

    const pad = n => String(n).padStart(2, '0');
    let text = '';
    if (d > 0) text = `${d}d ${pad(h)}h ${pad(m)}m ${pad(s)}s`;
    else if (h > 0) text = `${pad(h)}h ${pad(m)}m ${pad(s)}s`;
    else text = `${pad(m)}m ${pad(s)}s`;

    let color = '#3949ab';
    if (d === 0 && h < 3) color = '#f57c00';
    if (d === 0 && h === 0 && m < 30) color = '#c62828';

    el.innerHTML = `<span style="color:${color};"><i class="fas fa-hourglass-half"></i> ${text}</span>`;
}

async function addComplaintNote(id) {
    const note = document.getElementById('complaintNote')?.value;
    if (!note?.trim()) { showWarningModal('Please enter a note.'); return; }
    const fd = new FormData();
    fd.append('action', 'add_complaint_note');
    fd.append('complaint_id', id);
    fd.append('note', note);
    try {
        const resp = await fetch('complaint_ajax.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) { showSuccessModal('Note added'); document.getElementById('complaintNote').value = ''; viewComplaintDetails(id); }
        else showErrorModal(data.message);
    } catch (err) { showErrorModal('Error adding note'); }
}

async function cancelMyComplaint(id, referenceNumber) {
    const confirm = await openAppModal({ type: 'warning', title: 'Cancel Complaint?', message: `Are you sure you want to withdraw complaint ${referenceNumber}? This cannot be undone.`, buttonText: 'Yes, Cancel Complaint' });
    if (!confirm) return;
    const fd = new FormData();
    fd.append('action', 'cancel_complaint');
    fd.append('complaint_id', id);
    try {
        const r = await fetch('complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) { showSuccessModal('Complaint withdrawn'); showMyComplaints(); }
        else { showErrorModal(d.message || 'Could not cancel'); }
    } catch (e) { showErrorModal('Network error'); }
}

// ============================================================
// SECTION 16: FORM HELPERS
// ============================================================
function showSubmitComplaintForm() {
    if (!ensureComplaintContainer()) return;
    const r = window.resident || {};
    const rName = r.name || r.firstName + ' ' + r.lastName || '';
    complainantsList = [{ full_name: rName, address: r.address || '', contact_number: r.phone || '', email: r.email || '' }];
    respondentsList = [];
    witnessesList = [];
    incidentsList = [_newIncident()];
    selectedLaws = []; // Reset selected laws

    const container = document.getElementById('complaintContent');
    if (container) {
        container.innerHTML = getSubmitComplaintForm();
        attachIncidentEventListeners();
        attachPartyEventListeners();
        attachLiveValidation();

        const descEl2 = document.getElementById('complaintDescription');
        const subjectEl2 = document.getElementById('complaintSubject');
        if (descEl2) {
            descEl2.removeEventListener('input', updateAutoPriorityBadge);
            descEl2.addEventListener('input', updateAutoPriorityBadge);
        }
        if (subjectEl2) {
            subjectEl2.removeEventListener('change', updateAutoPriorityBadge);
            subjectEl2.addEventListener('change', updateAutoPriorityBadge);
        }
        updateAutoPriorityBadge();
        ensureFieldErrorStyles();
        attachFieldAutoClear();
        const descInput = document.getElementById('complaintDescription');
        if (descInput) { descInput.removeEventListener('input', onDescriptionChange); descInput.addEventListener('input', onDescriptionChange); }
    }
}

function resetComplaintForm() {
    const form = document.getElementById('complaintForm');
    if (form) form.reset();
    const r = window.resident || {};
    const rName = r.name || r.firstName + ' ' + r.lastName || '';
    complainantsList = [{ full_name: rName, address: r.address || '', contact_number: r.phone || '', email: r.email || '' }];
    respondentsList = [];
    witnessesList = [];
    incidentsList = [_newIncident()];
    selectedLaws = []; // Reset selections
    ['complainantsContainer', 'respondentsContainer', 'witnessesContainer', 'incidentsContainer'].forEach(id => {
        const el = document.getElementById(id);
        if (id === 'complainantsContainer' && el) el.innerHTML = renderComplainantsList();
        else if (id === 'respondentsContainer' && el) el.innerHTML = renderRespondentsList();
        else if (id === 'witnessesContainer' && el) el.innerHTML = renderWitnessesList();
        else if (id === 'incidentsContainer' && el) el.innerHTML = renderIncidentsList();
    });
    attachPartyEventListeners();
    attachIncidentEventListeners();
    ensureFieldErrorStyles();
    attachFieldAutoClear();
    const subjectInput = document.getElementById('complaintSubject');
    if (subjectInput) {
        subjectInput.value = '';
        subjectInput.dataset.rawValue = '';
    }
    clearTitleSuggestion();
    hideLawInfoPanel();
    const anon = document.getElementById('isAnonymous');
    if (anon) anon.checked = false;
    const anonGroup = document.getElementById('anonymityGroup');
    if (anonGroup) anonGroup.style.display = 'none';
    toggleAnonymityWarning();
    updateAutoPriorityBadge();

    const titleEl = document.getElementById('complaintTitle');
    if (titleEl) delete titleEl.dataset.userEdited;
}

function ensureComplaintContainer() {
    let body = document.getElementById('dashboardBody');
    if (!body) return false;
    let content = document.getElementById('complaintContent');
    if (!content) {
        body.innerHTML = `<div class="content-card"><div class="section-title"><i class="fas fa-gavel"></i> Complaint Management</div><div id="complaintContent"><div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div></div></div>`;
        content = document.getElementById('complaintContent');
    }
    return !!content;
}

function initComplaintModule() { if (ensureComplaintContainer()) showSubmitComplaintForm(); }
function escapeHtml(t) { if (!t) return ''; const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

function showLoading(m) {
    let ld = document.getElementById('loadingOverlay');
    if (!ld) { ld = document.createElement('div'); ld.id = 'loadingOverlay'; ld.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:10000;display:flex;justify-content:center;align-items:center;'; document.body.appendChild(ld); }
    ld.style.display = 'flex';
    ld.innerHTML = `<div style="background:white;padding:30px;border-radius:16px;text-align:center;"><i class="fas fa-spinner fa-spin" style="font-size:40px;color:#2E7D32;"></i><p>${m}</p></div>`;
}
function hideLoading() { const ld = document.getElementById('loadingOverlay'); if (ld) ld.style.display = 'none'; }

// ============================================================
// SECTION 17: MODAL HELPERS
// ============================================================
function ensureModalStyles() {
    if (document.getElementById('appModalStyle')) return;
    const style = document.createElement('style');
    style.id = 'appModalStyle';
    style.textContent = `
        .app-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; z-index: 10001; }
        .app-modal-box { background: #fff; border-radius: 16px; padding: 32px 28px 24px; width: 90%; max-width: 400px; text-align: center; }
        .app-modal-icon { width: 68px; height: 68px; margin: 0 auto 16px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 34px; }
        .app-modal-icon.success { background: #e8f5e9; color: #2E7D32; }
        .app-modal-icon.error   { background: #ffebee; color: #c62828; }
        .app-modal-icon.warning { background: #fff8e1; color: #ef6c00; }
        .app-modal-icon.info    { background: #e3f2fd; color: #1565c0; }
        .app-modal-title { font-size: 19px; font-weight: 700; margin-bottom: 8px; }
        .app-modal-message { font-size: 14px; color: #555; }
        .app-modal-ref { display: inline-block; margin-top: 12px; padding: 8px 16px; background: #f1f8e9; border: 1px dashed #a5d6a7; border-radius: 8px; font-family: monospace; font-weight: 700; color: #2E7D32; }
        .app-modal-btn { margin-top: 22px; width: 100%; padding: 12px 20px; border: none; border-radius: 10px; color: #fff; font-size: 15px; font-weight: 600; cursor: pointer; }
        .app-modal-btn.success { background: #2E7D32; }
        .app-modal-btn.error   { background: #c62828; }
        .app-modal-btn.warning { background: #ef6c00; }
        .app-modal-btn.info    { background: #1565c0; }
    `;
    document.head.appendChild(style);
}

function openAppModal({ type = 'info', title = '', message = '', reference = '', buttonText = 'OK' }) {
    ensureModalStyles();
    const existing = document.getElementById('appModalOverlay');
    if (existing) existing.remove();
    const iconMap = { success: 'fa-check', error: 'fa-times', warning: 'fa-exclamation-triangle', info: 'fa-info-circle' };
    const iconClass = iconMap[type] || iconMap.info;
    const overlay = document.createElement('div');
    overlay.id = 'appModalOverlay';
    overlay.className = 'app-modal-overlay';
    overlay.innerHTML = `
        <div class="app-modal-box">
            <div class="app-modal-icon ${type}"><i class="fas ${iconClass}"></i></div>
            <div class="app-modal-title">${escapeHtml(title)}</div>
            ${message ? `<div class="app-modal-message">${escapeHtml(message)}</div>` : ''}
            ${reference ? `<div class="app-modal-ref">${escapeHtml(reference)}</div>` : ''}
            <button type="button" class="app-modal-btn ${type}" id="appModalBtn">${escapeHtml(buttonText)}</button>
        </div>
    `;
    document.body.appendChild(overlay);
    return new Promise((resolve) => {
        const close = () => { overlay.remove(); resolve(true); };
        document.getElementById('appModalBtn').addEventListener('click', close);
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    });
}

function showSuccessModal(m) { return openAppModal({ type: 'success', title: 'Success!', message: m, buttonText: 'OK' }); }
function showErrorModal(m)   { return openAppModal({ type: 'error',   title: 'Error!',   message: m, buttonText: 'OK' }); }
function showWarningModal(m) { return openAppModal({ type: 'warning', title: 'Warning',  message: m, buttonText: 'OK' }); }
function showComplaintSuccessModal(referenceNumber, onClose) {
    openAppModal({ type: 'success', title: 'Complaint Submitted!', message: 'Your complaint has been filed successfully. Please save your reference number:', reference: referenceNumber, buttonText: 'OK' }).then(() => { if (typeof onClose === 'function') onClose(); });
}

// ============================================================
// SECTION 18: FIELD-LEVEL VALIDATION
// ============================================================

function attachLiveValidation() {
    const debounce = (fn, ms = 150) => {
        let t;
        return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
    };

    document.querySelectorAll('.party-name').forEach(input => {
        const run = debounce(() => liveNameCheck(input, false));
        input.addEventListener('input', run);
        input.addEventListener('blur', () => liveNameCheck(input, true));
    });

    document.querySelectorAll('.party-contact').forEach(input => {
        const run = debounce(() => liveContactCheck(input, false));
        input.addEventListener('input', run);
        input.addEventListener('blur', () => liveContactCheck(input, true));
    });

    document.querySelectorAll('.party-email').forEach(input => {
        const run = debounce(() => liveEmailCheck(input, false));
        input.addEventListener('input', run);
        input.addEventListener('blur', () => liveEmailCheck(input, true));
    });

    document.querySelectorAll('.party-address').forEach(input => {
        const run = debounce(() => liveAddressCheck(input, false));
        input.addEventListener('input', run);
        input.addEventListener('blur', () => liveAddressCheck(input, true));
    });

    document.querySelectorAll('.incident-description').forEach(input => {
        const run = debounce(() => liveWordingCheck(input, 10, 'Incident description', false));
        input.addEventListener('input', run);
        input.addEventListener('blur', () => liveWordingCheck(input, 10, 'Incident description', true));
    });

    document.querySelectorAll('.incident-location').forEach(input => {
        const run = debounce(() => liveRequiredCheck(input, 'Location is required.', false));
        input.addEventListener('input', run);
        input.addEventListener('blur', () => liveRequiredCheck(input, 'Location is required.', true));
    });

    document.querySelectorAll('.incident-date').forEach(input => {
        input.addEventListener('change', () => liveRequiredCheck(input, 'Date is required.', true));
        input.addEventListener('blur',   () => liveRequiredCheck(input, 'Date is required.', true));
    });
}

function _setLiveError(input, msg) {
    input.classList.add('field-error');
    let next = input.nextElementSibling;
    if (!(next && next.classList && next.classList.contains('field-error-msg'))) {
        const s = document.createElement('span');
        s.className = 'field-error-msg';
        input.parentNode.insertBefore(s, input.nextSibling);
        next = s;
    }
    next.innerHTML = '<i class="fas fa-exclamation-circle"></i>' + msg;
}
function _clearLiveError(input) {
    input.classList.remove('field-error');
    const next = input.nextElementSibling;
    if (next && next.classList && next.classList.contains('field-error-msg')) next.remove();
}

function liveNameCheck(input, force = false) {
    const v = input.value.trim();

    if (v.length < 2) {
        if (force && v === '') _setLiveError(input, 'Name is required.');
        else _clearLiveError(input);
        return;
    }

    const { ok, msg } = validatePersonNameJS(v);
    if (ok) _clearLiveError(input);
    else    _setLiveError(input, msg);
}

function liveContactCheck(input, force = false) {
    const v = input.value.trim();
    if (v === '') {
        if (force && input.hasAttribute('required')) _setLiveError(input, 'Contact number is required.');
        else _clearLiveError(input);
        return;
    }
    if (validateContactNumberJS(v)) _clearLiveError(input);
    else _setLiveError(input, 'Contact must be 11 digits starting with 09.');
}

function liveEmailCheck(input, force = false) {
    const v = input.value.trim();
    if (v === '') { _clearLiveError(input); return; }
    if (validateEmailOrEmptyJS(v)) _clearLiveError(input);
    else _setLiveError(input, 'Invalid email address.');
}

function liveAddressCheck(input, force = false) {
    const v = input.value.trim();
    if (v === '') {
        if (force && input.hasAttribute('required')) _setLiveError(input, 'Address is required.');
        else _clearLiveError(input);
        return;
    }
    _clearLiveError(input);
}

function liveWordingCheck(input, minLen, label, force = false) {
    const v = input.value.trim();
    if (v === '') {
        if (force && input.hasAttribute('required')) _setLiveError(input, label + ' is required.');
        else _clearLiveError(input);
        return;
    }
    const { ok, msg } = validateWordingJS(v, minLen, label);
    if (ok) _clearLiveError(input); else _setLiveError(input, msg);
}

function liveRequiredCheck(input, msg, force = false) {
    const v = (input.value || '').trim();
    if (v === '') {
        if (force) _setLiveError(input, msg);
        else _clearLiveError(input);
        return;
    }
    _clearLiveError(input);
}

function highlightField(role, message) {
    let el = document.querySelector(`[data-role="${role}"]`);
    if (!el) el = document.getElementById(role);

    if (!el) {
        showErrorModal(message);
        return;
    }

    if (role.startsWith('incidents_evidence')) {
        const section = document.querySelector(`[data-role="${role}"]`);
        if (section) {
            section.style.border = '2px solid #e53935';
            section.style.borderRadius = '10px';
            section.style.padding = '10px';
            section.style.background = '#fff5f5';
            setTimeout(() => { section.style.border = ''; section.style.padding = ''; section.style.background = ''; }, 4000);
            section.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const msgEl = document.createElement('span');
            msgEl.className = 'field-error-msg';
            msgEl.innerHTML = '<i class="fas fa-exclamation-circle"></i>' + message;
            section.appendChild(msgEl);
            setTimeout(() => msgEl.remove(), 5000);
        }
        return;
    }

    el.classList.add('field-error');
    let next = el.nextElementSibling;
    if (!(next && next.classList && next.classList.contains('field-error-msg'))) {
        const msgEl = document.createElement('span');
        msgEl.className = 'field-error-msg';
        el.parentNode.insertBefore(msgEl, el.nextSibling);
        next = msgEl;
    }
    next.innerHTML = '<i class="fas fa-exclamation-circle"></i>' + message;
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    attachFieldAutoClear();
}
function ensureFieldErrorStyles() {
    if (document.getElementById('fieldErrorStyle')) return;
    const style = document.createElement('style');
    style.id = 'fieldErrorStyle';
    style.textContent = `
        .field-error { border: 2px solid #e53935 !important; background-color: #fff5f5 !important; }
        .field-error-msg { display: block; margin-top: 6px; color: #e53935; font-size: 12px; font-weight: 500; }
        .party-card.field-error-group, .incident-card.field-error-group { border: 2px solid #e53935 !important; background-color: #fff5f5 !important; }
        .add-evidence-btn { padding: 6px 14px; border-radius: 20px; border: none; background: linear-gradient(135deg, #2E7D32, #43a047); color: #fff; font-size: 0.75rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; }
        .add-evidence-btn:hover { background: linear-gradient(135deg, #1b5e20, #2E7D32); }
        .evidence-section { background: #fafbfa; border: 1px dashed #cfd8dc; border-radius: 10px; padding: 10px 12px; margin-top: 8px; }
        .no-evidence { font-size: 0.8rem; color: #999; font-style: italic; padding: 4px 0; }
        .evidence-file-item { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; margin: 3px 4px 3px 0; background: #e8f5e9; color: #2E7D32; border-radius: 6px; font-size: 0.75rem; font-weight: 600; }
        .evidence-file-item .remove-evidence-btn { background: none; border: none; color: #c62828; cursor: pointer; padding: 0 2px; font-size: 0.7rem; }
    `;
    document.head.appendChild(style);
}

function markFieldError(el, message) {
    if (!el) return;
    el.classList.add('field-error');
    const next = el.nextElementSibling;
    if (next && next.classList && next.classList.contains('field-error-msg')) { next.innerHTML = '<i class="fas fa-exclamation-circle"></i>' + message; return; }
    const msg = document.createElement('span');
    msg.className = 'field-error-msg';
    msg.innerHTML = '<i class="fas fa-exclamation-circle"></i>' + message;
    el.parentNode.insertBefore(msg, el.nextSibling);
}

function markGroupError(el, message) {
    if (!el) return;
    el.classList.add('field-error-group');
    let msg = el.querySelector(':scope > .field-error-msg');
    if (msg) { msg.innerHTML = '<i class="fas fa-exclamation-circle"></i>' + message; return; }
    msg = document.createElement('span');
    msg.className = 'field-error-msg';
    msg.style.margin = '10px 0 0';
    msg.innerHTML = '<i class="fas fa-exclamation-circle"></i>' + message;
    el.appendChild(msg);
}

function clearAllFieldErrors() {
    document.querySelectorAll('#complaintForm .field-error').forEach(el => el.classList.remove('field-error'));
    document.querySelectorAll('#complaintForm .field-error-group').forEach(el => el.classList.remove('field-error-group'));
    document.querySelectorAll('#complaintForm .field-error-msg').forEach(el => el.remove());
}

function attachFieldAutoClear() {
    const form = document.getElementById('complaintForm');
    if (!form) return;
    form.querySelectorAll('input, select, textarea').forEach(el => {
        el.removeEventListener('input', clearFieldErrorOnInput);
        el.removeEventListener('change', clearFieldErrorOnInput);
        el.addEventListener('input', clearFieldErrorOnInput);
        el.addEventListener('change', clearFieldErrorOnInput);
    });
}

function clearFieldErrorOnInput(e) {
    const el = e.target;
    el.classList.remove('field-error');
    const next = el.nextElementSibling;
    if (next && next.classList && next.classList.contains('field-error-msg')) next.remove();
    const card = el.closest('.party-card, .incident-card');
    if (card) {
        card.classList.remove('field-error-group');
        const cardMsg = card.querySelector(':scope > .field-error-msg');
        if (cardMsg) cardMsg.remove();
    }
}

// ============================================================
// GLOBAL EXPORTS
// ============================================================
window.initComplaintModule = initComplaintModule;
window.showSubmitComplaintForm = showSubmitComplaintForm;
window.showMyComplaints = showMyComplaints;
window.switchComplaintTab = switchComplaintTab;
window.setStageFilter = setStageFilter;
window.getStageForStatus = (s) => STATUS_TO_STAGE[s] || 'intake';
window.resolveStageForComplaint = resolveStageForComplaint;
window.COMPLAINT_STAGES = COMPLAINT_STAGES;
window.submitComplaint = submitComplaint;
window.viewComplaintDetails = viewComplaintDetails;
window.addComplaintNote = addComplaintNote;
window.cancelMyComplaint = cancelMyComplaint;
window.resetComplaintForm = resetComplaintForm;
window.addComplainant = addComplainant;
window.addRespondent = addRespondent;
window.addWitness = addWitness;
window.addIncident = addIncident;
window.removeIncident = removeIncident;
window.addIncidentEvidence = addIncidentEvidence;
window.removeIncidentEvidence = removeIncidentEvidence;
window.applyTitleSuggestion = applyTitleSuggestion;
window.toggleAnonymityWarning = toggleAnonymityWarning;
window.updateAnonymityVisibility = updateAnonymityVisibility;
window.toggleLawSelection = toggleLawSelection;
window.updateSelectedLaws = updateSelectedLaws;

console.log('✅ Katarungang Pambarangay Classifier loaded (POINTING SYSTEM enabled)');
console.log('📚 Laws loaded from complaint_keywords.js:', (window.BARANGAY_LAWS || []).length);
console.log('🎯 Victim-aware: minor, senior, woman, pwd, property, public');
console.log('🚨 Non-jurisdictional handling active');
console.log('🔒 Selective Anonymity: enabled');
console.log('⏱️ Live hearing countdown timers active');
console.log('📅 Incident date/time pre-filled with current date/time');
console.log('⚖️  Checkbox selection system: enabled (Title/Subject are read-only)');