/* =====================================================================
   DANOVA - AI-Powered Education Platform
   File: app.js
   Version: 3.15.1 (Bug Fix — window exports for lifecycle modules)
   Purpose: Full Frontend Logic (Single File)
   ---------------------------------------------------------------------
   Changelog v3.15.1 (BUG FIX — window exports):
     FIXED:
       - Bug #18 (API client not available): Lifecycle modules
         (AdminStorageDashboard, AdminTickets, StudentTickets,
         StudentBulkDelete) could not access window.API, window.Toast,
         window.Modal, window.Loading, window.State, window.Auth
         because these const objects were never exported to window.
       - Added 6 exports immediately after each const definition:
           * window.State   = State;
           * window.Toast   = Toast;
           * window.Loading = Loading;
           * window.Modal   = Modal;
           * window.API     = API;
           * window.Auth    = Auth;
     KEPT:
       - Entire v3.15.0 logic (Pending State Cleanup).
     COMPAT: 100% backward compatible.
   ---------------------------------------------------------------------
   Changelog v3.15.0 (BUG FIX):
     FIXED:
       - Bug #12 (STATE LEAK): _teardown() now clears all pending chat
         state variables (pendingChatFile, pendingChatClientId,
         pendingChatFingerprint, pendingChatBubble, pendingChatErrorBubble).
       - Bug #15 (STATE LEAK): resetAllState() also resets pending chat
         state to prevent stale references across login sessions.
     KEPT:
       - Entire v3.14.0 logic (Merged lifecycle.js).
     COMPAT: 100% backward compatible.
   ---------------------------------------------------------------------
   Changelog v3.14.0 (MERGED):
     MERGED:
       - محتوای کامل lifecycle.js به انتهای فایل اضافه شد.
       - شامل ۴ ماژول: StudentTickets, StudentBulkDelete,
         AdminTickets, AdminStorageDashboard.
     KEPT:
       - تمام منطق v3.13.0 (UI Enhancement — Premium Chat UX).
     COMPAT: 100% backward compatible.
   ---------------------------------------------------------------------
   Changelog v3.13.0 (SAFE UPGRADE — UI/UX Enhancement):
     ADDED:
       - renderWelcome() با welcome-hint (هماهنگ با index.html v4.2.0)
       - Badge نمایش وضعیت RAG روی هدر چت
       - Animation smooth برای toggle textbook-rag
       - Focus management بهتر بعد از send message
     IMPROVED:
       - scrollToBottom با smooth behavior
       - typing indicator removed از view بعد از error
       - رفرش خودکار daily-usage بعد از هر پیام
     KEPT: تمام منطق v3.12.0.
     COMPAT: 100% backward compatible.
   ===================================================================== */

'use strict';

/* =====================================================================
   1. CONFIG
   ===================================================================== */

const CONFIG = {
    API_BASE: '/api',
    CSRF_HEADER: 'X-CSRF-Token',
    CSRF_COOKIE: 'DANOVACSRF',
    TOAST_TIMEOUT: 4500,
    THEME_KEY: 'danova-theme',
    DEBOUNCE_MS: 350,
    VIDEO_PROGRESS_INTERVAL: 10000,
    VIDEO_PROGRESS_MIN_DELTA: 3,
    PAGE_SIZE: 25,
};

const LABELS = {
    schoolType: { elementary: 'ابتدایی', middle: 'متوسطه اول', high: 'متوسطه دوم' },
    schoolGender: { girls: 'دخترانه', boys: 'پسرانه' },
    videoGender: { girls: 'دخترانه', boys: 'پسرانه', both: 'هردو' },
    aiGender: { girls: 'دخترانه', boys: 'پسرانه', both: 'هردو' },
    collectionLevel: { elementary: 'ابتدایی', middle: 'متوسطه اول', high: 'متوسطه دوم' },
    videoStatus: { completed: 'تکمیل‌شده', in_progress: 'در حال مشاهده', not_started: 'مشاهده‌نشده' },
};

const GRADES_BY_LEVEL = {
    elementary: [1, 2, 3, 4, 5, 6],
    middle:     [7, 8, 9],
    high:       [10, 11, 12],
};

function getGradesForLevel(level) {
    if (!level) return [];
    return GRADES_BY_LEVEL[level] || [];
}

function populateGradeSelect(selectEl, level, selectedValue = null) {
    if (!selectEl) return;
    const grades = getGradesForLevel(level);
    selectEl.innerHTML = '';
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = '— کل مقطع —';
    selectEl.appendChild(placeholder);
    if (!grades.length) { selectEl.value = ''; return; }
    grades.forEach(g => {
        const opt = document.createElement('option');
        opt.value = String(g);
        opt.textContent = `پایه ${g}`;
        selectEl.appendChild(opt);
    });
    const sel = (selectedValue === null || selectedValue === undefined || selectedValue === '') ? '' : String(selectedValue);
    if (sel !== '' && grades.map(String).includes(sel)) {
        selectEl.value = sel;
    } else {
        selectEl.value = '';
    }
}

/* =====================================================================
   2. GLOBAL STATE
   ===================================================================== */

const State = {
    currentUser: null,
    currentRole: null,
    currentView: null,
    csrfToken: null,

    studentSubview: 'chat',
    aiProfiles: [],
    currentAI: null,
    currentConversation: null,
    conversations: [],
    videos: [],
    videosFilter: 'all',
    videosPage: 1,
    chatSending: false,

    // ✅ FIX: Textbook RAG پیش‌فرض روشن
    useTextbookRag: true,

    // ✅ v3.15.0 (Bug #12, #15 FIX): Pending chat state
    pendingChatFile: null,
    pendingChatClientId: null,
    pendingChatFingerprint: null,
    pendingChatBubble: null,
    pendingChatErrorBubble: null,

    currentVideo: null,
    videoSaveTimer: null,
    lastSentPosition: 0,
    lastSentPct: 0,
    _lastProgressSent: 0,

    currentCollection: null,
    currentCollectionVideoIndex: -1,

    managerSubview: 'dashboard',

    adminSubview: 'dashboard',
    adminPages: {},
    adminFilters: {},
    adminCache: {
        schools: [], classes: [], students: [], managers: [], aiProfiles: [],
        ragCollections: [], videos: [], subscriptions: [],
        settings: [], videoCollections: [],
    },

    aiModalRagSelection: new Set(),
    aiModalRagList: [],

    classAIModalClassId: null,
    classAIModalSelection: new Set(),
    classAIModalDefault: null,
    classAIModalList: [],

    gapgptModels: null,
    gapgptModelsPromise: null,  // ✅ PATCH: shared promise
    gapgptModelsLoading: false,

    collectionVideosModalCollectionId: null,
    collectionVideosModalVideos: [],
    collectionVideosModalAvailableVideos: [],

    studentCurrentCollection: null,
    studentCollectionVideos: [],

    reportsState: {
        dataset: 'schools',
        filters: {},
        page: 1,
        pageSize: 50,
        lastTotal: 0,
        lastHeaders: {},
    },

    // ✅ Bug #6 FIX: Student file upload state (previously set at runtime,
    // never declared → state leaks between users on logout/login)
    studentDailyUsage: null,
    studentFileQuotaUnits: 7,
    studentFileMaxSizeMb: 5,
    studentFileEnabled: false,
};

// ✅ EXPORT: window.State — required by lifecycle modules at end of file
window.State = State;

/* =====================================================================
   2b. ✅ CENTRAL STATE RESET
   ✅ v3.15.0 (Bug #15 FIX): Pending chat state reset
   ===================================================================== */

function resetAllState(options = {}) {
    const { keepUser = false } = options;

    if (!keepUser) {
        State.currentUser = null;
        State.currentRole = null;
        State.csrfToken = null;
    }

    // ---------- Student: Chat ----------
    State.studentSubview = 'chat';
    State.aiProfiles = [];
    State.currentAI = null;
    State.currentConversation = null;
    State.conversations = [];
    State.chatSending = false;

    // ✅ Bug #29 FIX
    State.useTextbookRag = true;
    const _tbt = document.getElementById('textbook-rag-toggle');
    if (_tbt) _tbt.checked = true;

    // ✅ Bug #6 FIX: reset student file upload state
    State.studentDailyUsage = null;
    State.studentFileQuotaUnits = 7;
    State.studentFileMaxSizeMb = 5;
    State.studentFileEnabled = false;

    // ✅ v3.15.0 (Bug #15 FIX): Pending chat state reset
    State.pendingChatFile = null;
    State.pendingChatClientId = null;
    State.pendingChatFingerprint = null;
    State.pendingChatBubble = null;
    State.pendingChatErrorBubble = null;

    // پاک کردن input file
    const _fileInput = document.getElementById('chat-file-input');
    if (_fileInput) _fileInput.value = '';
    const _filePreview = document.getElementById('chat-file-preview');
    if (_filePreview) _filePreview.hidden = true;

    // ---------- Student: Videos ----------
    State.videos = [];
    State.videosFilter = 'all';
    State.videosPage = 1;
    State.studentCurrentCollection = null;
    State.studentCollectionVideos = [];

    // ---------- Video Player ----------
    State.currentVideo = null;
    State.videoSaveTimer = null;
    State.lastSentPosition = 0;
    State.lastSentPct = 0;
    State.currentCollection = null;
    State.currentCollectionVideoIndex = -1;
    State._lastProgressSent = 0;

    // ---------- Manager ----------
    State.managerSubview = 'dashboard';

    // ---------- Admin ----------
    State.adminSubview = 'dashboard';
    State.adminPages = {};
    State.adminFilters = {};
    State.adminCache = {
        schools: [], classes: [], students: [], managers: [], aiProfiles: [],
        ragCollections: [], videos: [], subscriptions: [],
        settings: [], videoCollections: [],
    };

    State.aiModalRagSelection = new Set();
    State.aiModalRagList = [];

    State.classAIModalClassId = null;
    State.classAIModalSelection = new Set();
    State.classAIModalDefault = null;
    State.classAIModalList = [];

    State.gapgptModels = null;
    State.gapgptModelsLoading = false;

    State.collectionVideosModalCollectionId = null;
    State.collectionVideosModalVideos = [];
    State.collectionVideosModalAvailableVideos = [];

    // ---------- Reports ----------
    State.reportsState = {
        dataset: 'schools',
        filters: {},
        page: 1,
        pageSize: 50,
        lastTotal: 0,
        lastHeaders: {},
    };

    // ✅ NEW: توقف تایمر ساعت زنده
    if (typeof AdminPanel !== 'undefined' && AdminPanel.stopLiveClock) {
        try { AdminPanel.stopLiveClock(); } catch (e) {}
    }

    // ✅ FIX (Bug #3): Lifecycle modules state reset
    if (typeof StudentBulkDelete !== 'undefined') {
        StudentBulkDelete._selected = new Set();
        StudentBulkDelete._allIds = [];
        StudentBulkDelete._bulkMode = false;
    }
    if (typeof StudentTickets !== 'undefined') {
        StudentTickets._tickets = [];
        StudentTickets._currentTicketId = null;
        StudentTickets._currentTicket = null;
        StudentTickets._filterStatus = 'all';
    }
    if (typeof AdminTickets !== 'undefined') {
        AdminTickets._tickets = [];
        AdminTickets._currentTicketId = null;
        AdminTickets._currentTicket = null;
        AdminTickets._filterStatus = 'all';
        AdminTickets._search = '';
    }
    if (typeof AdminStorageDashboard !== 'undefined') {
        AdminStorageDashboard._lastStats = null;
    }
    if (typeof AdminPanel !== 'undefined' && AdminPanel._timeState) {
        // ✅ FIX: قبل از reset، liveTimer را پاک کن
        if (AdminPanel._timeState.liveTimer) {
            try { clearInterval(AdminPanel._timeState.liveTimer); } catch (e) {}
        }
        AdminPanel._timeState = {
            serverUtc: null,
            serverTimestamp: null,
            timezone: null,
            offsetSeconds: 0,
            nextResetTimestamp: null,
            nextResetSeconds: 0,
            liveTimer: null,
            health: null,
        };
    }

    // ---------- DOM Cleanup ----------
    const domCleanup = [
        '#chat-messages',
        '#chat-history-list',
        '#ai-selector',
        '#history-full-list',
        '#videos-grid',
        '#student-collections-grid',
        '#student-collection-videos-grid',
        '#admin-schools-tbody',
        '#admin-managers-tbody',
        '#admin-classes-tbody',
        '#admin-students-tbody',
        '#admin-ai-tbody',
        '#admin-rag-grid',
        '#admin-videos-tbody',
        '#admin-video-collections-grid',
        '#admin-subs-tbody',
        '#admin-settings-list',
        '#admin-audit-tbody',
        '#admin-system-grid',
        '#reports-preview-thead',
        '#reports-preview-tbody',
        '#reports-filters-bar',
        '#reports-pagination',
        '#manager-students-tbody',
        '#manager-classes-grid',
        '#rag-detail-body',
        '#collection-videos-list',
        '#class-ai-selector',
        '#ai-rag-selector',
        '#video-collection-cover-preview',
    ];

    domCleanup.forEach(selector => {
        const node = document.querySelector(selector);
        if (node) node.innerHTML = '';
    });

    // ---------- Reset panel flags ----------
    if (typeof StudentPanel !== 'undefined') {
        StudentPanel._initialized = false;
        StudentPanel._initializedUserId = null;
    }
    if (typeof AdminPanel !== 'undefined') {
        AdminPanel._initialized = false;
        AdminPanel._initializedUserId = null;
        AdminPanel._pwdModalShown = false;
    }
    if (typeof ManagerPanel !== 'undefined') {
        ManagerPanel._initialized = false;
        ManagerPanel._initializedUserId = null;
    }
    if (typeof VideoPlayer !== 'undefined') {
        VideoPlayer._initialized = false;
    }
}

/* =====================================================================
   3. UTILITIES
   ===================================================================== */

const $  = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([k, v]) => {
        if (v === undefined || v === null) return;
        if (k === 'class') node.className = v;
        else if (k === 'text') node.textContent = v;
        else if (k === 'html') node.innerHTML = v;
        else if (k === 'style' && typeof v === 'object') {
            // ✅ PATCH: پشتیبانی از kebab-case style properties (مثل -webkit-line-clamp)
            Object.entries(v).forEach(([sk, sv]) => {
                if (sk.indexOf('-') !== -1) {
                    try { node.style.setProperty(sk, String(sv)); } catch (e) {}
                } else {
                    try { node.style[sk] = sv; } catch (e) {}
                }
            });
        }
        else if (k.startsWith('on') && typeof v === 'function') {
            node.addEventListener(k.slice(2).toLowerCase(), v);
        } else if (k === 'dataset' && typeof v === 'object') {
            Object.entries(v).forEach(([dk, dv]) => node.dataset[dk] = dv);
        } else node.setAttribute(k, v);
    });
    const list = Array.isArray(children) ? children : (children ? [children] : []);
    list.forEach(c => {
        if (c === null || c === undefined || c === false) return;
        if (typeof c === 'string' || typeof c === 'number') {
            node.appendChild(document.createTextNode(String(c)));
        } else if (c instanceof Node) {
            node.appendChild(c);
        }
    });
    return node;
}

function setText(selector, text) {
    const node = document.querySelector(selector);
    if (node) node.textContent = (text === null || text === undefined) ? '—' : text;
}

function setHTML(selector, html) {
    const node = document.querySelector(selector);
    if (node) node.innerHTML = html;
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

// ✅ Bug #28 FIX
if (typeof window !== 'undefined') {
    window.escapeHtml = escapeHtml;
}

function debounce(fn, ms = CONFIG.DEBOUNCE_MS) {
    let t;
    return function (...args) {
        clearTimeout(t);
        t = setTimeout(() => fn.apply(this, args), ms);
    };
}

function formatNumber(n) {
    if (n === null || n === undefined || n === '') return '—';
    const num = Number(n);
    if (Number.isNaN(num)) return '—';
    return num.toLocaleString('fa-IR');
}

function formatPercent(n) {
    if (n === null || n === undefined) return '—';
    const num = Number(n);
    if (Number.isNaN(num)) return '—';
    return num.toLocaleString('fa-IR', { maximumFractionDigits: 1 }) + '٪';
}

function formatDate(iso) {
    if (!iso) return '—';
    try { return new Date(iso).toLocaleDateString('fa-IR'); }
    catch (e) { return '—'; }
}

function formatDateTime(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString('fa-IR', {
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit',
        });
    } catch (e) { return '—'; }
}

function formatDuration(seconds) {
    if (!seconds || seconds < 0) return '00:00';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60);
    const pad = (n) => String(n).padStart(2, '0');
    return h > 0 ? `${pad(h)}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`;
}

function formatDurationFa(seconds) {
    if (!seconds || seconds < 0) return '—';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const parts = [];
    if (h > 0) parts.push(`${formatNumber(h)} ساعت`);
    if (m > 0 || h === 0) parts.push(`${formatNumber(m)} دقیقه`);
    return parts.join(' و ');
}

function formatFileSize(bytes) {
    if (!bytes || bytes < 0) return '—';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0, n = bytes;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return `${n.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

function getCookie(name) {
    const m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
}

function storageGet(key, def = null) {
    try { const v = localStorage.getItem(key); return v === null ? def : v; }
    catch (e) { return def; }
}
function storageSet(key, val) {
    try { localStorage.setItem(key, val); } catch (e) {}
}
function storageRemove(key) {
    try { localStorage.removeItem(key); } catch (e) {}
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

function generateClientMessageId() {
    try {
        if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID();
        }
    } catch (e) {}
    return 'cmid_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
}

/* =====================================================================
   4. THEME
   ===================================================================== */

const Theme = {
    _initialized: false,
    init() {
        if (this._initialized) { console.warn('[Theme] init() already called'); return; }
        this._initialized = true;
        const saved = storageGet(CONFIG.THEME_KEY);
        const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        const theme = saved || (prefersDark ? 'dark' : 'light');
        this.set(theme);
        document.addEventListener('click', (e) => {
            if (e.target.closest('[data-theme-toggle], #theme-toggle-login')) {
                this.toggle();
            }
        });
    },
    set(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        storageSet(CONFIG.THEME_KEY, theme);
    },
    get() { return document.documentElement.getAttribute('data-theme') || 'light'; },
    toggle() { this.set(this.get() === 'dark' ? 'light' : 'dark'); },
};

(function applyThemeEarly() {
    try {
        const saved = localStorage.getItem(CONFIG.THEME_KEY);
        if (saved) {
            document.documentElement.setAttribute('data-theme', saved);
        } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    } catch (e) {}




})();

/* =====================================================================
   5. TOAST
   ===================================================================== */

const Toast = {
    _icons: { success: '✓', error: '✕', warning: '!', info: 'ℹ' },
    show(message, type = 'info', timeout = CONFIG.TOAST_TIMEOUT) {
        const container = $('#toast-container');
        if (!container) return;
        const icon = this._icons[type] || this._icons.info;
        const toast = el('div', { class: `toast toast-${type}` }, [
            el('span', { class: 'toast-icon', text: icon }),
            el('span', { class: 'toast-message', text: message }),
            el('button', {
                class: 'toast-close', text: '✕', 'aria-label': 'بستن',
                onClick: () => this._dismiss(toast),
            }),
        ]);
        container.appendChild(toast);
        const timer = setTimeout(() => this._dismiss(toast), timeout);
        toast.addEventListener('click', (e) => {
            if (e.target.closest('.toast-close')) clearTimeout(timer);
        });
    },
    success(m, t) { this.show(m, 'success', t); },
    error(m, t) { this.show(m, 'error', t); },
    warning(m, t) { this.show(m, 'warning', t); },
    info(m, t) { this.show(m, 'info', t); },
    _dismiss(toast) {
        if (!toast || !toast.parentNode) return;
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(-100%)';
        setTimeout(() => toast.remove(), 260);
    },
};

// ✅ EXPORT: window.Toast — required by lifecycle modules at end of file
window.Toast = Toast;

/* =====================================================================
   6. LOADING
   ===================================================================== */

const Loading = {
    _counter: 0,
    show(text = null) {
        const overlay = document.getElementById('loading-overlay');
        if (!overlay) return;
        const textEl = overlay.querySelector('.loading-text');
        if (textEl && text) textEl.textContent = text;
        else if (textEl) textEl.textContent = 'در حال بارگذاری...';
        this._counter++;
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
    },
    hide() {
        const overlay = document.getElementById('loading-overlay');
        if (!overlay) return;
        this._counter = Math.max(0, this._counter - 1);
        if (this._counter === 0) {
            overlay.hidden = true;
            document.body.style.overflow = '';
        }
    },
    forceHide() {
        const overlay = document.getElementById('loading-overlay');
        if (overlay) overlay.hidden = true;
        this._counter = 0;
        document.body.style.overflow = '';
    },
    async wrap(fn, text = null) {
        this.show(text);
        try { return await fn(); }
        finally { this.hide(); }
    },
};

// ✅ EXPORT: window.Loading — required by lifecycle modules at end of file
window.Loading = Loading;

/* =====================================================================
   7. MODAL
   ===================================================================== */

const Modal = {
    // ✅ FIX (BUG-010): focus trap + role=dialog
    // ─────────────────────────────────────────────────────────────
    // - role="dialog" + aria-modal="true"
    // - focus trap داخل modal
    // - بازگشت focus به trigger بعد از بستن
    // ─────────────────────────────────────────────────────────────
    _closeCallbacks: {},
    _previousFocus: {},
    _trapHandlers: {},

    open(id, onClose = null) {
        const m = document.getElementById(id);
        if (!m) { console.warn('[Modal] not found:', id); return; }

        if (onClose) this._closeCallbacks[id] = onClose;

        this._previousFocus[id] = document.activeElement;

        m.hidden = false;
        m.setAttribute('role', 'dialog');
        m.setAttribute('aria-modal', 'true');

        const title = m.querySelector('.modal-title');
        if (title) {
            if (!title.id) title.id = `modal-title-${id}`;
            m.setAttribute('aria-labelledby', title.id);
        }

        document.body.style.overflow = 'hidden';
        this._installFocusTrap(id, m);

        const first = m.querySelector(
            'input:not([type="hidden"]):not([disabled]), ' +
            'textarea:not([disabled]), select:not([disabled]), ' +
            'button:not([data-modal-close]):not([disabled])'
        );
        if (first) setTimeout(() => first.focus(), 80);
    },

    close(id) {
        const m = document.getElementById(id);
        if (!m) return;

        m.hidden = true;
        m.removeAttribute('role');
        m.removeAttribute('aria-modal');
        m.removeAttribute('aria-labelledby');

        this._removeFocusTrap(id);

        const prev = this._previousFocus[id];
        if (prev && typeof prev.focus === 'function') {
            try { prev.focus(); } catch (e) {}
        }
        delete this._previousFocus[id];

        if (this._closeCallbacks[id]) {
            try { this._closeCallbacks[id](); } catch (e) {}
            delete this._closeCallbacks[id];
        }
        if (document.querySelectorAll('.modal:not([hidden])').length === 0) {
            document.body.style.overflow = '';
        }
    },

    _installFocusTrap(id, modalEl) {
        this._removeFocusTrap(id);
        const handler = (e) => {
            if (e.key !== 'Tab') return;
            const focusables = modalEl.querySelectorAll(
                'a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), ' +
                'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            );
            if (!focusables.length) return;
            const first = focusables[0];
            const last  = focusables[focusables.length - 1];

            if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault(); first.focus();
            } else if (e.shiftKey && document.activeElement === first) {
                e.preventDefault(); last.focus();
            }
        };
        modalEl.addEventListener('keydown', handler);
        this._trapHandlers[id] = { el: modalEl, handler };
    },

    _removeFocusTrap(id) {
        const t = this._trapHandlers[id];
        if (t) {
            try { t.el.removeEventListener('keydown', t.handler); } catch (e) {}
            delete this._trapHandlers[id];
        }
    },

    closeAll() {
        const callbacks = this._closeCallbacks;
        this._closeCallbacks = {};
        Object.entries(callbacks).forEach(([id, cb]) => {
            try { cb(); } catch (e) { console.warn('[Modal] closeAll callback error:', e); }
        });
        document.querySelectorAll('.modal').forEach(m => { m.hidden = true; });
        document.body.style.overflow = '';
        this._previousFocus = {};
        this._trapHandlers = {};
    },

    isOpen(id) {
        const m = document.getElementById(id);
        return m ? !m.hidden : false;
    },

    confirm(message, onOk, options = {}) {
        const title = options.title || 'تأیید';
        const okText = options.okText || 'تأیید';
        const danger = options.danger !== false;
        setText('#modal-confirm-title', title);
        setText('#modal-confirm-message', message);
        const okBtn = document.getElementById('modal-confirm-ok');
        if (!okBtn) return;
        okBtn.textContent = okText;
        okBtn.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
        const clone = okBtn.cloneNode(true);
        okBtn.parentNode.replaceChild(clone, okBtn);
        clone.addEventListener('click', () => {
            this.close('modal-confirm');
            try { onOk && onOk(); } catch (e) { console.error(e); }
        });
        this.open('modal-confirm');
    },

    typedConfirm(expectedName, onConfirm, opts = {}) {
        const modalId = 'modal-typed-confirm';
        const modal = document.getElementById(modalId);
        if (!modal) { console.warn('typed-confirm modal not in DOM'); return; }
        setText('#typed-confirm-title', opts.title || 'تأیید حذف دائمی');
        setHTML('#typed-confirm-message',
            opts.message ||
            `برای حذف دائمی <strong>${escapeHtml(opts.entityLabel || 'این مورد')}</strong>، ` +
            `نام دقیق آن را تایپ کنید یا کلمه <code>DELETE</code> را وارد نمایید.`
        );
        setText('#typed-confirm-expected', expectedName || 'DELETE');
        const input = document.getElementById('typed-confirm-input');
        const cascadeWrap = document.getElementById('typed-confirm-cascade-wrap');
        const cascadeBox = document.getElementById('typed-confirm-cascade');
        const okBtn = document.getElementById('typed-confirm-ok');
        const errEl = document.getElementById('typed-confirm-error');
        input.value = '';
        if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
        if (cascadeWrap) cascadeWrap.hidden = !opts.allowCascade;
        if (cascadeBox) cascadeBox.checked = false;
        okBtn.disabled = true;
        const clone = okBtn.cloneNode(true);
        okBtn.parentNode.replaceChild(clone, okBtn);
        clone.disabled = true;
        clone.addEventListener('click', () => {
            const v = (input.value || '').trim();
            const ok = v.toUpperCase() === 'DELETE' ||
                       (expectedName && v.toLowerCase() === String(expectedName).toLowerCase());
            if (!ok) {
                if (errEl) { errEl.textContent = 'مقدار وارد شده صحیح نیست.'; errEl.hidden = false; }
                return;
            }
            const cascade = cascadeBox ? cascadeBox.checked : false;
            this.close(modalId);
            try { onConfirm({ confirm_name: v, cascade }); } catch (e) { console.error(e); }
        });
        // ✅ Bug #27 FIX
        if (input._typedConfirmHandler) {
            input.removeEventListener('input', input._typedConfirmHandler);
        }
        input._typedConfirmHandler = () => {
            const v = (input.value || '').trim();
            const ok = v.toUpperCase() === 'DELETE' ||
                       (expectedName && v.toLowerCase() === String(expectedName).toLowerCase());
            clone.disabled = !ok;
        };
        input.addEventListener('input', input._typedConfirmHandler);
        this.open(modalId);
    },

    prompt(title, label, defaultValue, onOk, options = {}) {
        const modalId = 'modal-prompt';
        const modal = document.getElementById(modalId);
        if (!modal) { console.warn('prompt modal not in DOM'); return; }
        setText('#prompt-title', title || 'ورود مقدار');
        setText('#prompt-label', label || '');
        const input = document.getElementById('prompt-input');
        input.value = defaultValue || '';
        input.type = options.password ? 'password' : 'text';
        input.placeholder = options.placeholder || '';
        const okBtn = document.getElementById('prompt-ok');
        const clone = okBtn.cloneNode(true);
        okBtn.parentNode.replaceChild(clone, okBtn);
        clone.addEventListener('click', () => {
            const val = input.value;
            if (!val || !val.trim()) { Toast.warning('مقدار را وارد کنید.'); return; }
            this.close(modalId);
            try { onOk(val.trim()); } catch (e) { console.error(e); }
        });
        this.open(modalId);
        setTimeout(() => { input.focus(); input.select(); }, 100);
    },

    init() {
        document.addEventListener('click', (e) => {
            const closeEl = e.target.closest('[data-modal-close]');
            if (closeEl) {
                const modal = closeEl.closest('.modal');
                if (modal) this.close(modal.id);
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const openModals = document.querySelectorAll('.modal:not([hidden])');
                if (openModals.length > 0) {
                    const top = openModals[openModals.length - 1];
                    this.close(top.id);
                }
            }
        });
    },
};

// ✅ EXPORT: window.Modal — required by lifecycle modules at end of file
window.Modal = Modal;

/* =====================================================================
   8. API CLIENT
   ===================================================================== */

const API = {
    _csrf() { return State.csrfToken || getCookie(CONFIG.CSRF_COOKIE); },

    async request(method, path, body = null, options = {}) {
        const url = path.startsWith('http') ? path : CONFIG.API_BASE + path;
        const timeout = options.timeout || 60000;
        const headers = { 'Accept': 'application/json' };
        const fetchOpts = { method, credentials: 'include', headers };
        if (!['GET', 'HEAD', 'OPTIONS'].includes(method.toUpperCase())) {
            const csrf = this._csrf();
            if (csrf) headers[CONFIG.CSRF_HEADER] = csrf;
        }
        if (body !== null && body !== undefined) {
            if (body instanceof FormData) {
                fetchOpts.body = body;
            } else {
                headers['Content-Type'] = 'application/json';
                fetchOpts.body = JSON.stringify(body);
            }
        }
        const ctrl = new AbortController();
        // ✅ FIX (B12): timeout کل چرخهٔ fetch + خواندن بدنه را پوشش می‌دهد.
        // قبلاً بعد از fetch پاک می‌شد و اگر بدنه گیر می‌کرد،
        // Promise هرگز resolve/reject نمی‌شد.
        const timer = setTimeout(() => ctrl.abort(), timeout);
        fetchOpts.signal = ctrl.signal;
        let response;
        let data = null;
        try {
            response = await fetch(url, fetchOpts);

            if (response.status === 401 && !options.skipAuthRedirect) {
                clearTimeout(timer);
                resetAllState();
                Router.show('login');
                throw { status: 401, message: 'نشست شما منقضی شده است.' };
            }

            const ct = response.headers.get('content-type') || '';
            try {
                if (ct.includes('application/json')) data = await response.json();
                else if (ct.includes('text/')) data = await response.text();
            } catch (e) { data = null; }
        } catch (err) {
            clearTimeout(timer);
            if (err && err.name === 'AbortError') throw { status: 0, message: 'زمان درخواست به پایان رسید.' };
            if (err && err.status) throw err; // 401 throw شدهٔ بالا
            throw { status: 0, message: 'ارتباط با سرور برقرار نشد.' };
        }
        clearTimeout(timer);
        if (!response.ok) {
            const msg = (data && (data.detail || data.message)) || `خطای سرور (${response.status})`;
            throw { status: response.status, message: msg, data };
        }
        return data;
    },

    get(path, options) { return this.request('GET', path, null, options); },
    post(path, body, options) { return this.request('POST', path, body, options); },
    put(path, body, options) { return this.request('PUT', path, body, options); },
    del(path, options) { return this.request('DELETE', path, null, options); },
    upload(path, formData, options) {
        return this.request('POST', path, formData, { ...options, timeout: 600000 });
    },
};

// ✅ EXPORT: window.API — CRITICAL for lifecycle modules at end of file
window.API = API;

/* =====================================================================
   9. AUTH
   ===================================================================== */

const Auth = {
    async login(username, password) {
        resetAllState({ keepUser: true });

        const data = await API.post('/auth/login', { username, password });
        State.currentUser = data.user;
        State.currentRole = data.user.role;
        State.csrfToken = data.csrf_token || getCookie(CONFIG.CSRF_COOKIE);
        return data;
    },

    async logout() {
        try { await API.post('/auth/logout'); } catch (e) {}

        resetAllState();

        Router.show('login');
    },

    async checkSession() {
        try {
            const data = await API.get('/auth/me', { skipAuthRedirect: true });
            State.currentUser = data.user;
            State.currentRole = data.user.role;
            State.csrfToken = getCookie(CONFIG.CSRF_COOKIE) || State.csrfToken;
            return true;
        } catch (e) { return false; }
    },

    async changePassword(currentPassword, newPassword) {
        return API.post('/auth/change-password', {
            current_password: currentPassword,
            new_password: newPassword,
        });
    },

    async changeUsername(newUsername, password) {
        return API.post('/auth/change-username', {
            new_username: newUsername,
            password: password,
        });
    },
};

// ✅ EXPORT: window.Auth — required by lifecycle modules at end of file
window.Auth = Auth;

/* =====================================================================
   10. ROUTER
   ===================================================================== */

const Router = {
    show(viewName) {
        State.currentView = viewName;
        $$('.view').forEach(v => { v.hidden = true; });
        const target = document.getElementById('view-' + viewName);
        if (target) target.hidden = false;

        if (viewName !== 'student') {
            $$('[data-student-view]').forEach(v => {
                if (v.getAttribute('data-student-view') !== 'chat') v.hidden = true;
            });
        }
        if (viewName !== 'manager') {
            $$('[data-manager-view]').forEach(v => {
                if (v.getAttribute('data-manager-view') !== 'dashboard') v.hidden = true;
            });
        }
        if (viewName !== 'admin') {
            $$('[data-admin-view]').forEach(v => {
                if (v.getAttribute('data-admin-view') !== 'dashboard') v.hidden = true;
            });
        }

        window.scrollTo(0, 0);
    },
    handleLoginSuccess(user) {
        State.currentUser = user;
        State.currentRole = user.role;
        /* DANOVA-FIX: DAN-011 */
        const mustChange = !!(user && user.must_change_password);
        if (user.role === 'student') {
            Router.show('student');
            StudentPanel.init();
        } else if (user.role === 'school_manager') {
            Router.show('manager');
            ManagerPanel.init();
        } else if (user.role === 'danova_admin') {
            Router.show('admin');
            AdminPanel.init();
        } else {
            Toast.error('نقش کاربری نامعتبر است.');
            Auth.logout();
            return;
        }

        // Show banner for every role when must_change_password is set
        if (mustChange) {
            const banner = document.getElementById('must-change-password-banner');
            if (banner) {
                banner.hidden = false;
                // Fallback: append to app-content if banner node does not exist in DOM
                if (!document.body.contains(banner)) {
                    const target = document.querySelector('.app-content');
                    if (target) {
                        const fallback = document.createElement('div');
                        fallback.id = 'must-change-password-banner';
                        fallback.className = 'alert alert-warning';
                        fallback.style.marginBottom = '16px';
                        fallback.innerHTML =
                            '<span class="alert-icon">⚠</span>' +
                            '<span><strong>توجه:</strong> برای امنیت، لطفاً رمز عبور خود را تغییر دهید.</span>';
                        target.insertBefore(fallback, target.firstChild);
                    }
                }
            }
            setTimeout(() => {
                Toast.warning('برای امنیت، لطفاً رمز عبور پیش‌فرض را تغییر دهید.');
                if (user.role === 'danova_admin' && AdminPanel.openChangePasswordModal) {
                    AdminPanel.openChangePasswordModal();
                }
            }, 400);
        }
    },
};

/* =====================================================================
   11. SIDEBAR + PROFILE MENU
   ===================================================================== */

function initSidebar() {
    document.addEventListener('click', (e) => {
        if (e.target.closest('[data-sidebar-toggle]')) {
            const layout = e.target.closest('.app-layout');
            const sb = layout ? layout.querySelector('.sidebar') : null;
            if (sb) sb.classList.add('sidebar-open');
        }
        if (e.target.closest('[data-sidebar-close]')) {
            const sb = e.target.closest('.sidebar');
            if (sb) sb.classList.remove('sidebar-open');
        }
        const navLink = e.target.closest('.nav-link');
        if (navLink) {
            const sb = navLink.closest('.sidebar');
            if (sb && window.innerWidth <= 1024) sb.classList.remove('sidebar-open');
        }
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.sidebar.sidebar-open').forEach(s => s.classList.remove('sidebar-open'));
        }
    });
}

function initProfileMenu() {
    // ✅ FIX (BUG-008): a11y-compliant profile menu
    // ─────────────────────────────────────────────────────────────
    // - aria-expanded / aria-haspopup روی trigger
    // - Escape برای بستن + بازگشت focus
    // - بستن با کلیک بیرون
    // - focus trap داخل dropdown
    // ─────────────────────────────────────────────────────────────

    let _openTrigger = null;

    function closeAll(restoreFocus = false) {
        $$('[data-profile-dropdown]').forEach(d => { d.hidden = true; });
        $$('[data-profile-toggle]').forEach(t => t.setAttribute('aria-expanded', 'false'));
        if (restoreFocus && _openTrigger) {
            try { _openTrigger.focus(); } catch (e) {}
        }
        _openTrigger = null;
    }

    document.addEventListener('click', (e) => {
        const toggle = e.target.closest('[data-profile-toggle]');
        const inDropdown = e.target.closest('[data-profile-dropdown]');

        if (toggle) {
            e.preventDefault();
            const menu = toggle.closest('.profile-menu');
            const dd = menu ? menu.querySelector('[data-profile-dropdown]') : null;
            if (!dd) return;

            const wasHidden = dd.hidden;
            closeAll(false);

            if (wasHidden) {
                dd.hidden = false;
                toggle.setAttribute('aria-expanded', 'true');
                toggle.setAttribute('aria-haspopup', 'true');
                _openTrigger = toggle;

                const first = dd.querySelector('.dropdown-item');
                if (first) setTimeout(() => first.focus(), 50);
            }
            return;
        }

        if (!inDropdown) closeAll(false);
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const open = document.querySelector('[data-profile-dropdown]:not([hidden])');
            if (open) {
                e.preventDefault();
                closeAll(true);
            }
        }

        if (e.key === 'Tab') {
            const open = document.querySelector('[data-profile-dropdown]:not([hidden])');
            if (!open) return;
            const items = Array.from(open.querySelectorAll('.dropdown-item:not([disabled])'));
            if (!items.length) return;
            const first = items[0];
            const last  = items[items.length - 1];

            if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault(); first.focus();
            } else if (e.shiftKey && document.activeElement === first) {
                e.preventDefault(); last.focus();
            }
        }
    });
}

/* =====================================================================
   12. LOGIN VIEW
   ===================================================================== */

function initLogin() {
    const form = $('#login-form');
    if (!form) return;

    const togglePwd = $('#toggle-password');
    if (togglePwd) {
        // ✅ FIX (BUG-011): aria-pressed toggle
        togglePwd.addEventListener('click', () => {
            const input = $('#login-password');
            const s = togglePwd.querySelector('.password-icon-show');
            const h = togglePwd.querySelector('.password-icon-hide');
            const isVisible = input.type === 'text';
            if (!isVisible) {
                input.type = 'text';
                if (s) s.hidden = true; if (h) h.hidden = false;
                togglePwd.setAttribute('aria-pressed', 'true');
                togglePwd.setAttribute('aria-label', 'مخفی کردن رمز عبور');
            } else {
                input.type = 'password';
                if (s) s.hidden = false; if (h) h.hidden = true;
                togglePwd.setAttribute('aria-pressed', 'false');
                togglePwd.setAttribute('aria-label', 'نمایش رمز عبور');
            }
        });
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const username = $('#login-username').value.trim();
        const password = $('#login-password').value;
        if (!username || !password) {
            showLoginError('نام کاربری و رمز عبور را وارد کنید.');
            return;
        }
        hideLoginError();
        setLoginLoading(true);
        try {
            const data = await Auth.login(username, password);
            Router.handleLoginSuccess(data.user);
        } catch (err) {
            showLoginError(err.message || 'خطا در ورود.');
        } finally {
            setLoginLoading(false);
        }
    });
}

function showLoginError(msg) {
    const box = $('#login-error');
    if (!box) return;
    const m = box.querySelector('.alert-message');
    if (m) m.textContent = msg;
    box.hidden = false;
}

function hideLoginError() {
    const box = $('#login-error');
    if (box) box.hidden = true;
}

function setLoginLoading(loading) {
    const btn = $('#login-submit');
    if (!btn) return;
    const label = btn.querySelector('.btn-label');
    const loader = btn.querySelector('.btn-loader');
    btn.disabled = loading;
    if (label) label.hidden = loading;
    if (loader) loader.hidden = !loading;
}

/* =====================================================================
   13. STUDENT PANEL
   ===================================================================== */

const StudentPanel = {
    _initialized: false,
    _initializedUserId: null,
    _navHandlers: [],
    _logoutHandler: null,
    _pwdFormHandler: null,
    _nameFormHandler: null,
    _newConvHandlers: [],
    _videoFilterHandlers: [],
    _collectionBackHandler: null,
    _textbookToggleHandler: null,

    init() {
        const user = State.currentUser;
        if (!user) {
            this._initialized = false;
            this._initializedUserId = null;
            return;
        }

        const currentUserId = user.id;
        const isSameUser = (this._initialized && this._initializedUserId === currentUserId);

        if (isSameUser) {
            console.warn('[StudentPanel] init() already called for this user — skipping.');
            return;
        }

        this._teardown();

        this._initialized = true;
        this._initializedUserId = currentUserId;

        const chatMessages = $('#chat-messages');
        if (chatMessages) chatMessages.innerHTML = '';

        const chatHistoryList = $('#chat-history-list');
        if (chatHistoryList) {
            chatHistoryList.innerHTML =
                '<div class="empty-state empty-state-sm">' +
                '<div class="empty-icon">💭</div>' +
                '<div class="empty-text">در حال بارگذاری...</div></div>';
        }

        const aiSelector = $('#ai-selector');
        if (aiSelector) {
            aiSelector.innerHTML = '<div class="ai-selector-placeholder">در حال بارگذاری...</div>';
        }

        setText('#student-header-name', user.full_name);
        setText('#student-dropdown-name', user.full_name);
        setText('#welcome-student-name', user.full_name);

        this._navHandlers = [];
        $$('[data-student-nav]').forEach(link => {
            const handler = (e) => {
                e.preventDefault();
                const name = link.getAttribute('data-student-nav');
                StudentPanel.switchSubview(name);
            };
            link.addEventListener('click', handler);
            this._navHandlers.push({ element: link, handler });
        });

        const logoutBtn = $('#student-logout');
        if (logoutBtn) {
            this._logoutHandler = () => Auth.logout();
            logoutBtn.addEventListener('click', this._logoutHandler);
        }

        const pwdForm = $('#profile-password-form');
        if (pwdForm) {
            this._pwdFormHandler = async (e) => {
                e.preventDefault();
                const cur = $('#current-password').value;
                const nw = $('#new-password').value;
                const cf = $('#confirm-password').value;
                if (!cur || !nw || !cf) { Toast.warning('همه فیلدها الزامی است.'); return; }
                if (nw.length < 6) { Toast.warning('رمز جدید حداقل ۶ کاراکتر باشد.'); return; }
                if (nw !== cf) { Toast.warning('تکرار رمز مطابقت ندارد.'); return; }
                try {
                    await Auth.changePassword(cur, nw);
                    Toast.success('رمز تغییر کرد. دوباره وارد شوید.');
                    setTimeout(() => Auth.logout(), 1200);
                } catch (err) { Toast.error(err.message); }
            };
            pwdForm.addEventListener('submit', this._pwdFormHandler);
        }

        const nameForm = $('#profile-name-form');
        if (nameForm) {
            this._nameFormHandler = async (e) => {
                e.preventDefault();
                const v = $('#profile-name-input').value.trim();
                if (!v || v.length < 2) { Toast.warning('نام حداقل ۲ کاراکتر.'); return; }
                try {
                    await API.put('/student/profile', { full_name: v });
                    Toast.success('ذخیره شد.');
                    State.currentUser.full_name = v;
                    setText('#student-header-name', v);
                    setText('#student-dropdown-name', v);
                    setText('#welcome-student-name', v);
                } catch (err) { Toast.error(err.message); }
            };
            nameForm.addEventListener('submit', this._nameFormHandler);
        }

        const textbookToggle = $('#textbook-rag-toggle');
        if (textbookToggle) {
            textbookToggle.checked = true;
            State.useTextbookRag = true;

            this._textbookToggleHandler = () => {
                State.useTextbookRag = !!textbookToggle.checked;
            };
            textbookToggle.addEventListener('change', this._textbookToggleHandler);
        }

        StudentPanel.initChat();
        StudentPanel.initVideoFilters();
        StudentPanel.initCollectionBackButton();
        VideoPlayer.init();

        this._newConvHandlers = [];
        $$('[data-new-conversation]').forEach(b => {
            const h = () => StudentPanel.startNewConversation();
            b.addEventListener('click', h);
            this._newConvHandlers.push({ element: b, handler: h });
        });

        StudentPanel.loadAIProfiles();
        StudentPanel.loadConversations();

        StudentPanel.switchSubview('chat');
    },

    _teardown() {
        const form = $('#chat-form');
        if (form && form._chatSubmitHandler) {
            form.removeEventListener('submit', form._chatSubmitHandler);
            form._chatSubmitHandler = null;
        }
        const input = $('#chat-input');
        if (input && input._chatInputHandler) {
            input.removeEventListener('input', input._chatInputHandler);
            input._chatInputHandler = null;
        }
        if (input && input._chatKeydownHandler) {
            input.removeEventListener('keydown', input._chatKeydownHandler);
            input._chatKeydownHandler = null;
        }
        const fileInput = $('#chat-file-input');
        const fileButton = $('#chat-file-btn');
        const fileRemove = $('#chat-file-remove');
        if (fileInput && fileInput._fileChangeHandler) fileInput.removeEventListener('change', fileInput._fileChangeHandler);
        // ✅ PATCH: null کردن file handlers
        if (fileInput) fileInput._fileChangeHandler = null;
        if (fileButton && fileButton._fileClickHandler) fileButton.removeEventListener('click', fileButton._fileClickHandler);
        if (fileButton) fileButton._fileClickHandler = null;
        if (fileRemove && fileRemove._fileRemoveHandler) fileRemove.removeEventListener('click', fileRemove._fileRemoveHandler);
        if (fileRemove) fileRemove._fileRemoveHandler = null;
        if (fileInput) fileInput.value = '';
        const filePreview = $('#chat-file-preview');
        if (filePreview) filePreview.hidden = true;

        // ✅ v3.15.0 (Bug #12, #15 FIX): پاک کردن کامل pending state
        State.pendingChatFile = null;
        State.pendingChatClientId = null;
        State.pendingChatFingerprint = null;
        State.pendingChatBubble = null;
        State.pendingChatErrorBubble = null;

        const newBtn = $('#new-conversation-btn');
        if (newBtn && newBtn._chatNewHandler) {
            newBtn.removeEventListener('click', newBtn._chatNewHandler);
            newBtn._chatNewHandler = null;
        }

        this._navHandlers.forEach(({ element, handler }) => {
            try { element.removeEventListener('click', handler); } catch (e) {}
        });
        this._navHandlers = [];

        const logoutBtn = $('#student-logout');
        if (logoutBtn && this._logoutHandler) {
            try { logoutBtn.removeEventListener('click', this._logoutHandler); } catch (e) {}
            this._logoutHandler = null;
        }

        const pwdForm = $('#profile-password-form');
        if (pwdForm && this._pwdFormHandler) {
            try { pwdForm.removeEventListener('submit', this._pwdFormHandler); } catch (e) {}
            this._pwdFormHandler = null;
        }
        const nameForm = $('#profile-name-form');
        if (nameForm && this._nameFormHandler) {
            try { nameForm.removeEventListener('submit', this._nameFormHandler); } catch (e) {}
            this._nameFormHandler = null;
        }

        this._newConvHandlers.forEach(({ element, handler }) => {
            try { element.removeEventListener('click', handler); } catch (e) {}
        });
        this._newConvHandlers = [];

        this._videoFilterHandlers.forEach(({ element, handler }) => {
            try { element.removeEventListener('click', handler); } catch (e) {}
        });
        this._videoFilterHandlers = [];

        const backBtn = $('#student-collection-back-btn');
        if (backBtn && this._collectionBackHandler) {
            try { backBtn.removeEventListener('click', this._collectionBackHandler); } catch (e) {}
            this._collectionBackHandler = null;
        }

        const textbookToggle = $('#textbook-rag-toggle');
        if (textbookToggle && this._textbookToggleHandler) {
            try { textbookToggle.removeEventListener('change', this._textbookToggleHandler); } catch (e) {}
            this._textbookToggleHandler = null;
        }

        const chatMessages = $('#chat-messages');
        if (chatMessages) chatMessages.innerHTML = '';

        const chatHistoryList = $('#chat-history-list');
        if (chatHistoryList) chatHistoryList.innerHTML = '';

        const historyFullList = $('#history-full-list');
        if (historyFullList) historyFullList.innerHTML = '';

        const aiSelector = $('#ai-selector');
        if (aiSelector) {
            aiSelector.innerHTML = '<div class="ai-selector-placeholder">در حال بارگذاری...</div>';
        }

        State.currentConversation = null;
        State.conversations = [];
        State.aiProfiles = [];
        State.currentAI = null;
        State.chatSending = false;
    },

    switchSubview(name) {
        State.studentSubview = name;
        $$('[data-student-nav]').forEach(l =>
            l.classList.toggle('nav-link-active', l.getAttribute('data-student-nav') === name));
        $$('[data-student-view]').forEach(v =>
            v.hidden = v.getAttribute('data-student-view') !== name);

        const titles = {
            chat: 'گفتگو با AI',
            history: 'گفتگوهای من',
            videos: 'ویدیوهای آموزشی',
            tickets: 'تیکت پشتیبانی',
            profile: 'پروفایل من',
        };
        setText('#student-page-title', titles[name] || 'دانش‌آموز');

        if (name === 'chat' && !State.currentConversation) {
            const c = $('#chat-messages');
            if (c && !c.querySelector('.chat-welcome') && !c.querySelector('.chat-message')) {
                StudentPanel.renderWelcome();
            }
        }

        if (name === 'history') StudentPanel.loadHistory();
        if (name === 'videos') StudentPanel.loadVideos();
        if (name === 'tickets' && window.StudentTickets) window.StudentTickets.load();
        if (name === 'profile') StudentPanel.loadProfile();
    },

    async loadAIProfiles() {
        const container = $('#ai-selector');
        if (!container) return;

        container.innerHTML = '<div class="ai-selector-placeholder">در حال بارگذاری...</div>';
        State.aiProfiles = [];
        State.currentAI = null;

        try {
            const data = await API.get('/student/ai-profiles');
            State.aiProfiles = data.profiles || [];
            if (!State.aiProfiles.length) {
                container.innerHTML = '<div class="ai-selector-placeholder">هیچ دستیار فعالی برای کلاس شما نیست.</div>';
                return;
            }
            const defId = data.default_id;
            State.currentAI = defId
                ? State.aiProfiles.find(p => p.id === defId) || State.aiProfiles[0]
                : State.aiProfiles[0];

            container.innerHTML = '';
            State.aiProfiles.forEach(p => {
                const chip = el('button', {
                    type: 'button',
                    class: 'filter-chip' + (p.id === State.currentAI.id ? ' filter-chip-active' : ''),
                    dataset: { aiId: p.id },
                    text: p.name,
                    onClick: () => StudentPanel.selectAI(p.id),
                });
                container.appendChild(chip);
            });
        } catch (err) {
            container.innerHTML = '<div class="ai-selector-placeholder">خطا در بارگذاری.</div>';
        }
    },

    selectAI(id) {
        const p = State.aiProfiles.find(x => x.id === id);
        if (!p) return;
        State.currentAI = p;
        $$('#ai-selector .filter-chip').forEach(c =>
            c.classList.toggle('filter-chip-active', c.getAttribute('data-ai-id') === id));
        State.currentConversation = null;

        State.useTextbookRag = true;
        const _tbt = document.getElementById('textbook-rag-toggle');
        if (_tbt) _tbt.checked = true;

        const msgs = $('#chat-messages');
        if (msgs) msgs.innerHTML = '';
        StudentPanel.renderWelcome();
    },

    initChat() {
        const form = $('#chat-form');
        const input = $('#chat-input');
        if (!form) return;

        const fileInput = $('#chat-file-input');
        const fileButton = $('#chat-file-btn');
        const filePreview = $('#chat-file-preview');
        const filePreviewText = $('#chat-file-preview-text');
        const fileRemove = $('#chat-file-remove');
        const updateFilePreview = () => {
            const file = fileInput && fileInput.files && fileInput.files[0];
            State.pendingChatFile = file || null;
            if (filePreview) filePreview.hidden = !file;
            if (filePreviewText && file) {
                const mb = (file.size / (1024 * 1024)).toFixed(1);
                const cost = Number(State.studentFileQuotaUnits || 7);
                const usage = State.studentDailyUsage || {};
                const remaining = usage.remaining_units == null ? 'نامحدود' : `${usage.remaining_units} / ${usage.messages_limit}`;
                filePreviewText.textContent = `${file.name} · ${mb} MB · هزینه: ${cost} پیام · باقیمانده: ${remaining}`;
            }
        };
        if (fileButton && fileInput) {
            if (fileButton._fileClickHandler) fileButton.removeEventListener('click', fileButton._fileClickHandler);
            fileButton._fileClickHandler = () => fileInput.click();
            fileButton.addEventListener('click', fileButton._fileClickHandler);
            if (fileInput._fileChangeHandler) fileInput.removeEventListener('change', fileInput._fileChangeHandler);
            fileInput._fileChangeHandler = () => {
                const file = fileInput.files && fileInput.files[0];
                if (file && ((file.type && file.type !== 'application/pdf') || !file.name.toLowerCase().endsWith('.pdf'))) {
                    fileInput.value = '';
                    State.pendingChatFile = null;
                    Toast.warning('در حال حاضر فقط فایل PDF پشتیبانی می‌شود.');
                }
                if (file && State.studentFileMaxSizeMb && file.size > State.studentFileMaxSizeMb * 1024 * 1024) {
                    fileInput.value = '';
                    State.pendingChatFile = null;
                    Toast.warning(`حداکثر حجم فایل ${State.studentFileMaxSizeMb} MB است.`);
                }
                updateFilePreview();
            };
            fileInput.addEventListener('change', fileInput._fileChangeHandler);
        }
        if (fileRemove) {
            if (fileRemove._fileRemoveHandler) fileRemove.removeEventListener('click', fileRemove._fileRemoveHandler);
            fileRemove._fileRemoveHandler = () => {
                if (fileInput) fileInput.value = '';
                State.pendingChatFile = null;
                State.pendingChatClientId = null;
                updateFilePreview();
            };
            fileRemove.addEventListener('click', fileRemove._fileRemoveHandler);
        }
        API.get('/student/daily-usage').then(data => {
            const usage = data.usage || {};
            State.studentFileQuotaUnits = Number(usage.file_quota_units || 7);
            State.studentFileMaxSizeMb = Number(usage.file_max_size_mb || 5);
            State.studentDailyUsage = usage;
            State.studentFileEnabled = !!usage.file_upload_enabled;
            if (fileButton) fileButton.hidden = !State.studentFileEnabled;
            updateFilePreview();
        }).catch(() => { if (fileButton) fileButton.hidden = true; });

        if (form._chatSubmitHandler) {
            form.removeEventListener('submit', form._chatSubmitHandler);
            form._chatSubmitHandler = null;
        }
        if (input && input._chatInputHandler) {
            input.removeEventListener('input', input._chatInputHandler);
            input._chatInputHandler = null;
        }
        if (input && input._chatKeydownHandler) {
            input.removeEventListener('keydown', input._chatKeydownHandler);
            input._chatKeydownHandler = null;
        }

        if (input) {
            input._chatInputHandler = () => {
                input.style.height = 'auto';
                input.style.height = Math.min(input.scrollHeight, 160) + 'px';
            };
            input.addEventListener('input', input._chatInputHandler);

            input._chatKeydownHandler = (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    e.stopPropagation();
                    StudentPanel.sendMessage();
                }
            };
            input.addEventListener('keydown', input._chatKeydownHandler);
        }

        form._chatSubmitHandler = async (e) => {
            e.preventDefault();
            await StudentPanel.sendMessage();
        };
        form.addEventListener('submit', form._chatSubmitHandler);

        const newBtn = $('#new-conversation-btn');
        if (newBtn) {
            if (newBtn._chatNewHandler) {
                newBtn.removeEventListener('click', newBtn._chatNewHandler);
            }
            newBtn._chatNewHandler = () => StudentPanel.startNewConversation();
            newBtn.addEventListener('click', newBtn._chatNewHandler);
        }
    },

    startNewConversation() {
        State.currentConversation = null;

        State.useTextbookRag = true;
        const _tbt = document.getElementById('textbook-rag-toggle');
        if (_tbt) _tbt.checked = true;

        StudentPanel.renderWelcome();
        StudentPanel.renderConversationList();
        if (State.currentRole === 'student') StudentPanel.switchSubview('chat');
    },

    async loadConversations() {
        const c = $('#chat-history-list');
        if (c) {
            c.innerHTML =
                '<div class="empty-state empty-state-sm">' +
                '<div class="empty-icon">💭</div>' +
                '<div class="empty-text">در حال بارگذاری...</div></div>';
        }

        try {
            const data = await API.get('/chat/conversations');
            State.conversations = data.conversations || [];
            StudentPanel.renderConversationList();
        } catch (e) {
            if (c) {
                c.innerHTML =
                    '<div class="empty-state empty-state-sm">' +
                    '<div class="empty-text">خطا در بارگذاری</div></div>';
            }
        }
    },

    renderConversationList() {
        const c = $('#chat-history-list');
        if (!c) return;
        if (!State.conversations.length) {
            c.innerHTML =
                '<div class="empty-state empty-state-sm">' +
                '<div class="empty-icon">💭</div>' +
                '<div class="empty-text">هنوز گفتگویی ندارید</div></div>';
            return;
        }
        c.innerHTML = '';
        State.conversations.forEach(conv => {
            const item = el('button', {
                type: 'button',
                class: 'chat-history-item' +
                    (State.currentConversation && State.currentConversation.id === conv.id
                        ? ' chat-history-item-active' : ''),
                onClick: () => StudentPanel.openConversation(conv.id),
            }, [
                el('span', { class: 'chat-history-title', text: conv.title || 'بدون عنوان' }),
                el('span', { class: 'chat-history-meta', text: `${formatNumber(conv.message_count)} پیام` }),
            ]);
            c.appendChild(item);
        });
    },

    async openConversation(id) {
        try {
            Loading.show();
            const data = await API.get('/chat/conversations/' + id);
            State.currentConversation = data.conversation;
            StudentPanel.renderMessages(data.messages);
            StudentPanel.renderConversationList();
        } catch (err) {
            Toast.error(err.message);
        } finally {
            Loading.hide();
        }
    },

    renderMessages(messages) {
        const c = $('#chat-messages');
        if (!c) return;
        c.innerHTML = '';
        messages.forEach(m => {
            c.appendChild(StudentPanel.createBubble(m.role, m.content, m));
        });
        StudentPanel.scrollToBottom();
    },

    createBubble(role, content, meta = {}) {
        const isUser = role === 'user';
        const wrapper = el('div', {
            class: 'chat-message ' + (isUser ? 'chat-message-user' : 'chat-message-ai'),
        });

        const bubble = el('div', { class: 'chat-bubble' });
        if (isUser) {
            bubble.textContent = content;
        } else {
            bubble.innerHTML = renderMarkdown(content);
        }

        const metaRow = el('div', { class: 'chat-message-meta' });
        if (meta.created_at) {
            const t = new Date(meta.created_at);
            metaRow.appendChild(el('span', { text: t.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' }) }));
        }
        if (isUser && meta.attachment && meta.attachment.id) {
            const link = document.createElement('a');
            link.className = 'chat-attachment-link';
            link.href = CONFIG.API_BASE + '/chat/attachments/' + encodeURIComponent(meta.attachment.id);
            link.textContent = '📎 ' + meta.attachment.filename;
            link.setAttribute('download', meta.attachment.filename);
            metaRow.appendChild(link);
        }
        if (meta.rag_used && !isUser) {
            metaRow.appendChild(el('span', { class: 'chat-rag-badge', text: '📚 با منابع' }));
        }

        wrapper.appendChild(bubble);
        if (metaRow.childNodes.length) wrapper.appendChild(metaRow);
        return wrapper;
    },

    scrollToBottom() {
        const c = $('#chat-messages');
        if (!c) return;
        try {
            c.scrollTo({ top: c.scrollHeight, behavior: 'smooth' });
        } catch (e) {
            c.scrollTop = c.scrollHeight;
        }
    },

    renderWelcome() {
        const c = $('#chat-messages');
        if (!c) return;
        const name = (State.currentUser && State.currentUser.full_name) || '';
        // ═══════════════════════════════════════════════════════════════
        // DANOVA_FIX2_TEXTBOOK_RAG_UI_UNIFIED
        // نمایش یکسان برای دانش‌آموز عادی و Test Account
        // ═══════════════════════════════════════════════════════════════
        c.innerHTML =
            '<div class="chat-welcome" id="chat-welcome">' +
            '<div class="welcome-icon">🤖</div>' +
            '<h2 class="welcome-title">سلام، ' + escapeHtml(name) + '! 👋</h2>' +
            '<p class="welcome-text">آماده‌ای با هم یاد بگیریم؟<br>یه سوال بپرس تا شروع کنیم.</p>' +
            '<div class="welcome-hint">می‌تونی از کتاب درسی هم کمک بگیری 📚</div>' +
            '</div>';

        // اطمینان از اینکه دکمه RAG وضعیت درست دارد
        const ragToggle = document.getElementById('textbook-rag-toggle');
        if (ragToggle) {
            ragToggle.checked = !!State.useTextbookRag;
            const label = ragToggle.closest('.textbook-rag-label');
            if (label) {
                const status = label.querySelector('.textbook-rag-status');
                if (status) {
                    status.textContent = State.useTextbookRag ? 'فعال' : 'غیرفعال';
                }
            }
        }
    },

    async sendMessage() {
        const input = $('#chat-input');
        const sendBtn = $('#chat-send-btn');
        if (!input || !sendBtn) return;

        const message = input.value.trim();
        const selectedFile = $('#chat-file-input')?.files?.[0] || null;
        if (!message && !selectedFile) return;

        if (State.chatSending) return;
        State.chatSending = true;

        try {
            if (!State.currentAI) {
                Toast.warning('یک دستیار انتخاب کنید.');
                return;
            }

            if (State.currentAI) {
                const isOwnAI = State.aiProfiles.some(p => p.id === State.currentAI.id);
                if (!isOwnAI && State.aiProfiles.length > 0) {
                    console.warn('[Security] Stale AI profile detected — resetting.');
                    State.currentAI = null;
                    Toast.error('لطفاً صفحه را رفرش کنید.');
                    return;
                }
            }

            if (State.currentConversation) {
                const isOwnConv = State.conversations.some(
                    c => c.id === State.currentConversation.id
                );
                if (!isOwnConv) {
                    console.warn('[Security] Stale conversation detected — resetting.');
                    State.currentConversation = null;
                    const msgs = $('#chat-messages');
                    if (msgs) msgs.innerHTML = '';
                    StudentPanel.renderWelcome();
                }
            }

            if (!State.currentConversation) {
                const d = await API.post('/chat/conversations', {
                    ai_profile_id: State.currentAI.id,
                });
                State.currentConversation = d.conversation;
            }

            const conversationIdAtSend = State.currentConversation.id;
            const requestFingerprint = `${conversationIdAtSend}|${message}|${selectedFile ? `${selectedFile.name}:${selectedFile.size}:${selectedFile.lastModified}` : ''}`;
            if (State.pendingChatClientId && State.pendingChatFingerprint !== requestFingerprint) {
                try { if (State.pendingChatBubble) State.pendingChatBubble.remove(); } catch (e) {}
                try { if (State.pendingChatErrorBubble) State.pendingChatErrorBubble.remove(); } catch (e) {}
                State.pendingChatClientId = null;
                State.pendingChatFingerprint = null;
                State.pendingChatBubble = null;
                State.pendingChatErrorBubble = null;
            }

            const welcome = $('#chat-welcome');
            if (welcome) welcome.remove();

            const messages = $('#chat-messages');
            if (!messages) return;

            if (State.pendingChatClientId && State.pendingChatBubble) {
                try { State.pendingChatBubble.remove(); } catch (e) {}
                try { if (State.pendingChatErrorBubble) State.pendingChatErrorBubble.remove(); } catch (e) {}
            }
            State.pendingChatBubble = StudentPanel.createBubble(
                'user',
                message,
                { created_at: new Date().toISOString(), attachment_filename: selectedFile ? selectedFile.name : null }
            );
            messages.appendChild(State.pendingChatBubble);
            StudentPanel.scrollToBottom();

            input.value = '';
            input.style.height = 'auto';
            sendBtn.disabled = true;

            /* DANOVA-FIX: DAN-010 */
            // Clear file input + preview immediately to avoid a lingering UI state
            // while the AI request is in flight.
            if (selectedFile) {
                const _fi = document.getElementById('chat-file-input');
                if (_fi) _fi.value = '';
                State.pendingChatFile = null;
                const _fp = document.getElementById('chat-file-preview');
                if (_fp) _fp.hidden = true;
            }

            const typing = el('div', {
                class: 'chat-message chat-message-ai',
                id: 'chat-typing',
            }, [
                el('div', { class: 'chat-bubble chat-bubble-typing' }, [
                    el('span', { class: 'typing-dot' }),
                    el('span', { class: 'typing-dot' }),
                    el('span', { class: 'typing-dot' }),
                ]),
            ]);
            messages.appendChild(typing);
            StudentPanel.scrollToBottom();

            const clientMessageId = State.pendingChatClientId || generateClientMessageId();
            State.pendingChatClientId = clientMessageId;
            State.pendingChatFingerprint = requestFingerprint;

            try {
                let data;
                if (selectedFile) {
                    const fd = new FormData();
                    fd.append('file', selectedFile);
                    fd.append('content', message);
                    fd.append('client_message_id', clientMessageId);
                    fd.append('use_textbook_rag', String(!!State.useTextbookRag));
                    data = await API.upload('/chat/conversations/' + conversationIdAtSend + '/messages-with-file', fd);
                } else {
                    data = await API.post('/chat/conversations/' + conversationIdAtSend + '/messages', {
                        content: message,
                        client_message_id: clientMessageId,
                        use_textbook_rag: !!State.useTextbookRag,
                    });
                }
                State.pendingChatClientId = null;
                State.pendingChatFingerprint = null;
                State.pendingChatBubble = null;
                State.pendingChatErrorBubble = null;

                if (!State.currentConversation || State.currentConversation.id !== conversationIdAtSend) {
                    console.warn('[Chat] Conversation changed during send — discarding response');
                    try { typing.remove(); } catch (e) {}
                    return;
                }

                typing.remove();
                messages.appendChild(StudentPanel.createBubble(
                    'assistant',
                    data.assistant_message.content,
                    {
                        created_at: data.assistant_message.created_at,
                        rag_used: data.assistant_message.rag_used,
                    }
                ));
                if (data.attachment && data.attachment.id) {
                    const userBubbles = messages.querySelectorAll('.chat-message-user');
                    const latestUser = userBubbles[userBubbles.length - 1];
                    if (latestUser) {
                        const link = document.createElement('a');
                        link.className = 'chat-attachment-link';
                        link.href = CONFIG.API_BASE + '/chat/attachments/' + encodeURIComponent(data.attachment.id);
                        link.textContent = '📎 ' + data.attachment.filename;
                        link.setAttribute('download', data.attachment.filename);
                        latestUser.appendChild(link);
                    }
                }
                StudentPanel.scrollToBottom();
                StudentPanel.loadConversations();
                API.get('/student/daily-usage').then(usageData => {
                    State.studentDailyUsage = usageData.usage || {};
                    State.studentFileQuotaUnits = Number(State.studentDailyUsage.file_quota_units || State.studentFileQuotaUnits || 7);
                    const selected = $('#chat-file-input')?.files?.[0];
                    const previewText = $('#chat-file-preview-text');
                    if (selected && previewText) {
                        const remaining = State.studentDailyUsage.remaining_units == null
                            ? 'نامحدود'
                            : `${State.studentDailyUsage.remaining_units} / ${State.studentDailyUsage.messages_limit}`;
                        previewText.textContent = `${selected.name} · ${(selected.size / (1024 * 1024)).toFixed(1)} MB · هزینه: ${State.studentFileQuotaUnits} پیام · باقیمانده: ${remaining}`;
                    }
                }).catch(() => {});
                /* DANOVA-FIX DAN-010: preview already cleared at send-time */
            } catch (err) {
                try { typing.remove(); } catch (e) {}
                Toast.error(err.message);
                if (State.currentConversation && State.currentConversation.id === conversationIdAtSend) {
                    if (!input.value) input.value = message;
                    State.pendingChatErrorBubble = StudentPanel.createBubble(
                        'assistant',
                        'متأسفم، پاسخ دریافت نشد. لطفاً دوباره تلاش کنید.'
                    );
                    messages.appendChild(State.pendingChatErrorBubble);
                    StudentPanel.scrollToBottom();
                }
            } finally {
                sendBtn.disabled = false;
                input.focus();
            }
        } finally {
            State.chatSending = false;
        }
    },

    async loadHistory() {
        const container = $('#history-full-list');
        if (!container) return;

        container.innerHTML =
            '<div class="empty-state"><div class="empty-title">در حال بارگذاری...</div></div>';

        try {
            const data = await API.get('/chat/conversations');
            State.conversations = data.conversations || [];

            if (!State.conversations.length) {
                container.innerHTML =
                    '<div class="empty-state">' +
                    '<div class="empty-icon">💭</div>' +
                    '<div class="empty-title">هنوز گفتگویی ندارید</div>' +
                    '<div class="empty-text">اولین گفتگو را شروع کنید!</div></div>';
                if (window.StudentBulkDelete && typeof window.StudentBulkDelete.attach === 'function') {
                    window.StudentBulkDelete.attach();
                }
                return;
            }

            container.innerHTML = '';
            State.conversations.forEach(conv => {
                const item = el('div', {
                    class: 'card history-card',
                    // ✅ Bug #38 FIX
                    dataset: { convId: conv.id },
                    onClick: () => {
                        StudentPanel.switchSubview('chat');
                        StudentPanel.openConversation(conv.id);
                    },
                }, [
                    el('div', { class: 'history-title', text: conv.title || 'بدون عنوان' }),
                    el('div', { class: 'history-meta' }, [
                        el('span', { text: `${formatNumber(conv.message_count)} پیام` }),
                        el('span', { text: ' • ' }),
                        el('span', { text: conv.ai_profile_name || '—' }),
                        el('span', { text: ' • ' }),
                        el('span', { text: formatDateTime(conv.updated_at) }),
                    ]),
                ]);
                const del = el('button', {
                    class: 'btn btn-sm btn-ghost history-delete',
                    text: 'حذف',
                    onClick: (e) => {
                        e.stopPropagation();
                        Modal.confirm('این گفتگو حذف شود؟', async () => {
                            try {
                                await API.del('/chat/conversations/' + conv.id);
                                Toast.success('گفتگو حذف شد.');
                                StudentPanel.loadHistory();
                                StudentPanel.loadConversations();
                            } catch (err) { Toast.error(err.message); }
                        });
                    },
                });
                item.appendChild(del);
                container.appendChild(item);
            });

            if (window.StudentBulkDelete && typeof window.StudentBulkDelete.attach === 'function') {
                window.StudentBulkDelete.attach();
            }
        } catch (err) {
            Toast.error(err.message);
            container.innerHTML =
                '<div class="empty-state"><div class="empty-title">خطا در بارگذاری</div></div>';
        }
    },

    initVideoFilters() {
        this._videoFilterHandlers = [];
        $$('[data-video-filter]').forEach(chip => {
            const handler = () => {
                $$('[data-video-filter]').forEach(c => c.classList.remove('filter-chip-active'));
                chip.classList.add('filter-chip-active');
                State.videosFilter = chip.getAttribute('data-video-filter') || 'all';
                State.videosPage = 1;
                StudentPanel.loadVideos();
            };
            chip.addEventListener('click', handler);
            this._videoFilterHandlers.push({ element: chip, handler });
        });
    },

    initCollectionBackButton() {
        const btn = $('#student-collection-back-btn');
        if (btn) {
            if (this._collectionBackHandler) {
                try { btn.removeEventListener('click', this._collectionBackHandler); } catch (e) {}
            }
            this._collectionBackHandler = () => StudentPanel.closeCollectionDetail();
            btn.addEventListener('click', this._collectionBackHandler);
        }
    },

    async loadVideos() {
        await StudentPanel.loadCollections();

        if (State.studentCurrentCollection) {
            StudentPanel.closeCollectionDetail();
        }

        const grid = $('#videos-grid');
        if (!grid) return;
        grid.innerHTML = '<div class="empty-state"><div class="empty-title">در حال بارگذاری...</div></div>';
        try {
            const params = new URLSearchParams();
            if (State.videosFilter && State.videosFilter !== 'all') params.set('filter', State.videosFilter);
            params.set('page', String(State.videosPage || 1));
            params.set('page_size', String(CONFIG.PAGE_SIZE));
            const data = await API.get('/videos?' + params.toString());
            State.videos = data.videos || [];
            if (!State.videos.length) {
                grid.innerHTML =
                    '<div class="empty-state">' +
                    '<div class="empty-icon">🎬</div>' +
                    '<div class="empty-title">ویدیویی یافت نشد</div>' +
                    '<div class="empty-text">فیلتر را تغییر دهید یا بعداً سر بزنید.</div></div>';
                return;
            }
            grid.innerHTML = '';
            State.videos.forEach(v => grid.appendChild(StudentPanel.createVideoCard(v)));
        } catch (err) {
            Toast.error(err.message);
            grid.innerHTML = '<div class="empty-state"><div class="empty-title">خطا در بارگذاری</div></div>';
        }
    },

    createVideoCard(v, options = {}) {
        const pct = v.progress ? Math.round(v.progress.watch_percentage || 0) : 0;
        const status = v.status || (
            v.progress && v.progress.completed ? 'completed'
                : pct > 0 ? 'in_progress'
                : 'not_started'
        );

        const badgeText = status === 'completed' ? '✓ تکمیل‌شده'
            : status === 'in_progress' ? `▶ ${pct}٪`
            : '○ شروع نشده';

        const badgeClass = status === 'completed' ? 'badge-success'
            : status === 'in_progress' ? 'badge-warning'
            : 'badge-primary';

        const thumb = el('div', { class: 'video-thumbnail' });
        if (v.thumbnail_path || v.id) {
            const img = document.createElement('img');
            img.src = CONFIG.API_BASE + `/videos/${v.id}/thumbnail`;
            img.alt = v.title;
            img.onerror = () => { img.remove(); thumb.textContent = '▶'; };
            thumb.appendChild(img);
        } else {
            thumb.textContent = '▶';
        }

        const statusBadge = el('span', {
            class: 'badge ' + badgeClass,
            text: badgeText,
            style: {
                position: 'absolute',
                top: '8px',
                left: '8px',
                zIndex: '2',
                fontSize: '11px',
            },
        });
        thumb.style.position = 'relative';
        thumb.appendChild(statusBadge);

        const card = el('div', {
            class: 'video-card',
            onClick: () => VideoPlayer.open(v, options.collectionContext || null),
        }, [
            thumb,
            el('div', { class: 'video-info' }, [
                el('div', { class: 'video-title', text: v.title }),
                el('div', { class: 'video-meta' }, [
                    el('span', { text: v.subject || 'آموزشی' }),
                    el('span', { text: v.duration ? formatDuration(v.duration) : '—' }),
                ]),
                el('div', { class: 'video-progress-wrap' }, [
                    el('div', { class: 'video-progress' }, [
                        el('div', { class: 'video-progress-fill', style: { width: pct + '%' } }),
                    ]),
                    el('span', { class: 'video-progress-badge', text: status === 'not_started' ? 'شروع‌نشده' : `${pct}٪` }),
                ]),
            ]),
        ]);
        return card;
    },

    async loadCollections() {
        const section = $('#student-collections-section');
        const grid = $('#student-collections-grid');
        if (!section || !grid) return;

        try {
            const data = await API.get('/video/collections');
            const collections = data.collections || [];

            if (!collections.length) {
                section.hidden = true;
                return;
            }

            section.hidden = false;
            grid.innerHTML = '';

            collections.forEach(c => {
                grid.appendChild(StudentPanel.createCollectionCard(c));
            });
        } catch (err) {
            console.warn('loadCollections failed:', err);
            section.hidden = true;
        }
    },

    createCollectionCard(c) {
        const progress = c.progress || { total_videos: 0, completed_videos: 0, percent: 0 };
        const pct = Math.round(progress.percent || 0);

        const coverWrap = el('div', {
            class: 'video-thumbnail',
            style: { position: 'relative', height: '140px' },
        });
        if (c.cover_url) {
            const img = document.createElement('img');
            img.src = c.cover_url;
            img.alt = c.title;
            img.onerror = () => {
                img.remove();
                coverWrap.textContent = '📚';
                coverWrap.style.fontSize = '48px';
            };
            coverWrap.appendChild(img);
        } else {
            coverWrap.textContent = '📚';
            coverWrap.style.fontSize = '48px';
        }

        const card = el('div', {
            class: 'class-card',
            style: { cursor: 'pointer', overflow: 'hidden', padding: '0' },
            onClick: () => StudentPanel.openCollectionDetail(c.id),
        }, [
            coverWrap,
            el('div', { style: { padding: '16px' } }, [
                el('div', { class: 'class-card-title', text: c.title }),
                c.description ? el('div', {
                    class: 'class-card-meta',
                    text: c.description,
                    style: {
                        display: '-webkit-box',
                        '-webkit-line-clamp': '2',
                        '-webkit-box-orient': 'vertical',
                        overflow: 'hidden',
                        marginBottom: '8px',
                    },
                }) : null,
                el('div', { class: 'class-card-stats', style: { marginBottom: '8px' } }, [
                    el('span', { text: `${formatNumber(c.video_count)} ویدیو` }),
                    el('span', { text: ' • ' }),
                    el('span', { text: formatDurationFa(c.total_duration) }),
                ]),
                el('div', { style: { marginTop: '8px' } }, [
                    el('div', { style: {
                        display: 'flex',
                        justifyContent: 'space-between',
                        fontSize: '12px',
                        color: 'var(--color-text-muted)',
                        marginBottom: '4px',
                    } }, [
                        el('span', { text: 'پیشرفت' }),
                        el('span', { text: `${formatNumber(progress.completed_videos)} از ${formatNumber(progress.total_videos)} (${pct}٪)` }),
                    ]),
                    el('div', { class: 'video-progress' }, [
                        el('div', {
                            class: 'video-progress-fill',
                            style: { width: pct + '%' },
                        }),
                    ]),
                ]),
            ].filter(Boolean)),
        ]);

        return card;
    },

    async openCollectionDetail(collectionId) {
        const collectionsSection = $('#student-collections-section');
        const videosMain = $('#student-videos-main');
        const detailSection = $('#student-collection-detail');

        if (collectionsSection) collectionsSection.hidden = true;
        if (videosMain) videosMain.hidden = true;
        if (detailSection) detailSection.hidden = false;

        setText('#student-collection-detail-title', 'در حال بارگذاری...');
        setText('#student-collection-detail-subtitle', '');
        setText('#student-collection-video-count', '—');
        setText('#student-collection-total-duration', '—');
        setText('#student-collection-progress', '—');
        const grid = $('#student-collection-videos-grid');
        if (grid) grid.innerHTML = '<div class="empty-state"><div class="empty-title">در حال بارگذاری...</div></div>';
        const bar = $('#student-collection-progress-bar');
        if (bar) bar.style.width = '0%';

        try {
            const data = await API.get(`/video/collections/${collectionId}`);
            const col = data.collection;
            const videos = data.videos || [];

            State.studentCurrentCollection = col;
            State.studentCollectionVideos = videos;

            setText('#student-collection-detail-title', col.title);
            setText('#student-collection-detail-subtitle', col.description || '');
            setText('#student-collection-video-count', formatNumber(col.video_count));
            setText('#student-collection-total-duration', formatDurationFa(col.total_duration));
            setText('#student-collection-progress',
                `${formatNumber(col.progress.completed_videos)} از ${formatNumber(col.progress.total_videos)} (${Math.round(col.progress.percent)}٪)`);

            if (bar) bar.style.width = Math.round(col.progress.percent || 0) + '%';

            if (!videos.length) {
                if (grid) {
                    grid.innerHTML =
                        '<div class="empty-state">' +
                        '<div class="empty-icon">🎬</div>' +
                        '<div class="empty-title">هیچ ویدیویی در این مجموعه نیست</div>' +
                        '</div>';
                }
                return;
            }

            if (grid) {
                grid.innerHTML = '';
                videos.forEach((v, idx) => {
                    const card = StudentPanel.createVideoCard(v, {
                        collectionContext: {
                            collectionId: col.id,
                            collectionTitle: col.title,
                            videos: videos,
                            index: idx,
                        },
                    });
                    grid.appendChild(card);
                });
            }
        } catch (err) {
            Toast.error(err.message || 'خطا در بارگذاری مجموعه.');
            StudentPanel.closeCollectionDetail();
        }
    },

    closeCollectionDetail() {
        const collectionsSection = $('#student-collections-section');
        const videosMain = $('#student-videos-main');
        const detailSection = $('#student-collection-detail');

        if (detailSection) detailSection.hidden = true;
        if (videosMain) videosMain.hidden = false;
        if (collectionsSection) {
            const grid = $('#student-collections-grid');
            if (grid && grid.children.length > 0) {
                collectionsSection.hidden = false;
            }
        }

        State.studentCurrentCollection = null;
        State.studentCollectionVideos = [];
    },

    async loadProfile() {
        try {
            const data = await API.get('/student/profile');
            const p = data.profile;
            setText('#profile-fullname', p.full_name);
            setText('#profile-username', p.username);
            setText('#profile-school', p.school ? p.school.name : '—');
            setText('#profile-class', p.class ? p.class.name : '—');
            const nameInput = $('#profile-name-input');
            if (nameInput) nameInput.value = p.full_name || '';
        } catch (err) { Toast.error(err.message); }
    },
};

/* =====================================================================
   14. MANAGER PANEL
   ===================================================================== */

const ManagerPanel = {
    _initialized: false,
    _initializedUserId: null,
    _navHandlers: [],
    _logoutHandler: null,
    _searchHandler: null,
    _usageHandlers: [],
    _pwdFormHandler: null,

    init() {
        const user = State.currentUser;
        if (!user) {
            this._initialized = false;
            this._initializedUserId = null;
            return;
        }

        const currentUserId = user.id;
        const isSameUser = (this._initialized && this._initializedUserId === currentUserId);
        if (isSameUser) {
            console.warn('[ManagerPanel] init() already called for this user — skipping.');
            return;
        }

        this._teardown();

        this._initialized = true;
        this._initializedUserId = currentUserId;

        setText('#manager-header-name', user.full_name);
        setText('#manager-dropdown-name', user.full_name);
        setText('#manager-school-name', (user.school && user.school.name) || '—');

        this._navHandlers = [];
        $$('[data-manager-nav]').forEach(link => {
            const handler = (e) => {
                e.preventDefault();
                ManagerPanel.switchSubview(link.getAttribute('data-manager-nav'));
            };
            link.addEventListener('click', handler);
            this._navHandlers.push({ element: link, handler });
        });

        const logoutBtn = $('#manager-logout');
        if (logoutBtn) {
            this._logoutHandler = () => Auth.logout();
            logoutBtn.addEventListener('click', this._logoutHandler);
        }

        const search = $('#manager-students-search');
        if (search) {
            this._searchHandler = debounce(() => ManagerPanel.loadStudents(search.value));
            search.addEventListener('input', this._searchHandler);
        }

        this._usageHandlers = [];
        $$('[data-usage-days]').forEach(chip => {
            const handler = () => {
                $$('[data-usage-days]').forEach(c => c.classList.remove('filter-chip-active'));
                chip.classList.add('filter-chip-active');
                ManagerPanel.loadUsage(parseInt(chip.getAttribute('data-usage-days'), 10));
            };
            chip.addEventListener('click', handler);
            this._usageHandlers.push({ element: chip, handler });
        });

        const pwdForm = $('#manager-password-form');
        if (pwdForm) {
            this._pwdFormHandler = async (e) => {
                e.preventDefault();
                const cur = $('#manager-current-password').value;
                const nw = $('#manager-new-password').value;
                const cf = $('#manager-confirm-password').value;
                if (!cur || !nw || !cf) { Toast.warning('همه فیلدها الزامی است.'); return; }
                if (nw.length < 6) { Toast.warning('رمز جدید حداقل ۶ کاراکتر.'); return; }
                if (nw !== cf) { Toast.warning('تکرار رمز مطابقت ندارد.'); return; }
                try {
                    await Auth.changePassword(cur, nw);
                    Toast.success('رمز تغییر کرد. دوباره وارد شوید.');
                    setTimeout(() => Auth.logout(), 1200);
                } catch (err) { Toast.error(err.message); }
            };
            pwdForm.addEventListener('submit', this._pwdFormHandler);
        }

        ManagerPanel.switchSubview('dashboard');
    },

    _teardown() {
        this._navHandlers.forEach(({ element, handler }) => {
            try { element.removeEventListener('click', handler); } catch (e) {}
        });
        this._navHandlers = [];

        const logoutBtn = $('#manager-logout');
        if (logoutBtn && this._logoutHandler) {
            try { logoutBtn.removeEventListener('click', this._logoutHandler); } catch (e) {}
            this._logoutHandler = null;
        }

        const search = $('#manager-students-search');
        if (search && this._searchHandler) {
            try { search.removeEventListener('input', this._searchHandler); } catch (e) {}
            this._searchHandler = null;
        }

        this._usageHandlers.forEach(({ element, handler }) => {
            try { element.removeEventListener('click', handler); } catch (e) {}
        });
        this._usageHandlers = [];

        const pwdForm = $('#manager-password-form');
        if (pwdForm && this._pwdFormHandler) {
            try { pwdForm.removeEventListener('submit', this._pwdFormHandler); } catch (e) {}
            this._pwdFormHandler = null;
        }
    },

    switchSubview(name) {
        State.managerSubview = name;
        $$('[data-manager-nav]').forEach(l =>
            l.classList.toggle('nav-link-active', l.getAttribute('data-manager-nav') === name));
        $$('[data-manager-view]').forEach(v =>
            v.hidden = v.getAttribute('data-manager-view') !== name);

        const titles = {
            dashboard: 'داشبورد مدرسه',
            students: 'دانش‌آموزان',
            classes: 'کلاس‌ها',
            usage: 'مصرف هوش مصنوعی',
            profile: 'پروفایل من',
        };
        setText('#manager-page-title', titles[name] || 'مدیر مدرسه');

        if (name === 'dashboard') ManagerPanel.loadDashboard();
        if (name === 'students') ManagerPanel.loadStudents();
        if (name === 'classes') ManagerPanel.loadClasses();
        if (name === 'usage') ManagerPanel.loadUsage();
        if (name === 'profile') ManagerPanel.loadProfile();
    },

    async loadDashboard() {
        try {
            const data = await API.get('/manager/dashboard');
            const d = data.dashboard;
            setText('#manager-kpi-students', formatNumber(d.total_students));
            setText('#manager-kpi-classes', formatNumber(d.total_classes));
            setText('#manager-kpi-messages', formatNumber(d.messages_last_30_days));
            setText('#manager-kpi-tokens', formatNumber(d.tokens_last_30_days));
        } catch (err) { Toast.error(err.message); }
    },

    async loadStudents(search) {
        search = (search || '').trim();
        const tbody = $('#manager-students-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="4" class="table-empty">در حال بارگذاری...</td></tr>';
        try {
            const url = '/manager/students' + (search ? '?search=' + encodeURIComponent(search) : '');
            const data = await API.get(url);
            const students = data.students || [];
            if (!students.length) {
                tbody.innerHTML = '<tr><td colspan="4" class="table-empty">دانش‌آموزی یافت نشد.</td></tr>';
                return;
            }
            tbody.innerHTML = '';
            students.forEach(s => {
                tbody.appendChild(el('tr', {}, [
                    el('td', { text: s.full_name }),
                    el('td', { text: s.student_code || '—' }),
                    el('td', { text: s.class_name || '—' }),
                    el('td', {}, [
                        el('span', {
                            class: 'badge ' + (s.is_active ? 'badge-success' : 'badge-error'),
                            text: s.is_active ? 'فعال' : 'غیرفعال',
                        }),
                    ]),
                ]));
            });
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="4" class="table-empty">خطا در بارگذاری.</td></tr>';
        }
    },

    async loadClasses() {
        const grid = $('#manager-classes-grid');
        if (!grid) return;
        grid.innerHTML = '<div class="empty-state"><div class="empty-title">در حال بارگذاری...</div></div>';
        try {
            const data = await API.get('/manager/classes');
            const classes = data.classes || [];
            if (!classes.length) {
                grid.innerHTML = '<div class="empty-state"><div class="empty-icon">🏫</div><div class="empty-title">کلاسی یافت نشد</div></div>';
                return;
            }
            grid.innerHTML = '';
            classes.forEach(c => {
                grid.appendChild(el('div', { class: 'class-card' }, [
                    el('div', { class: 'class-card-title', text: c.name }),
                    el('div', { class: 'class-card-meta', text: c.grade || '—' }),
                    el('div', { class: 'class-card-stats' }, [
                        el('span', { text: formatNumber(c.student_count) + ' دانش‌آموز' }),
                    ]),
                ]));
            });
        } catch (err) { Toast.error(err.message); }
    },

    async loadUsage(days) {
        days = days || 30;
        try {
            const data = await API.get('/manager/usage?days=' + days);
            const u = data.usage;
            setText('#manager-usage-messages', formatNumber(u.total_messages));
            setText('#manager-usage-input', formatNumber(u.total_input_tokens));
            setText('#manager-usage-output', formatNumber(u.total_output_tokens));
            setText('#manager-usage-total', formatNumber(u.total_tokens));
        } catch (err) { Toast.error(err.message); }
    },

    loadProfile() {
        const u = State.currentUser;
        if (!u) return;
        setText('#manager-profile-name', u.full_name);
        setText('#manager-profile-username', u.username);
        setText('#manager-profile-school', (u.school && u.school.name) || '—');
    },
};

/* =====================================================================
   15. ADMIN PANEL — Core
   ===================================================================== */

const AdminPanel = {
    _initialized: false,
    _initializedUserId: null,
    _navHandlers: [],
    _actionDelegateHandler: null,
    _reportDelegateHandler: null,
    _downloadHandler: null,
    _timeSetupHandler: null,
    _timeSyncHandler: null,
    _searchHandlers: [],
    _filterHandlers: [],
    _pwdModalShown: false,
    _logoutHandler: null,

    init() {
        const user = State.currentUser;
        if (!user) {
            this._initialized = false;
            this._initializedUserId = null;
            return;
        }

        const currentUserId = user.id;
        const isSameUser = (this._initialized && this._initializedUserId === currentUserId);
        if (isSameUser) {
            console.warn('[AdminPanel] init() already called for this user — skipping.');
            return;
        }

        this._teardown();

        this._initialized = true;
        this._initializedUserId = currentUserId;

        setText('#admin-header-name', user.full_name);
        setText('#admin-dropdown-name', user.full_name);

        this._navHandlers = [];
        $$('[data-admin-nav]').forEach(link => {
            const handler = (e) => {
                e.preventDefault();
                AdminPanel.switchSubview(link.getAttribute('data-admin-nav'));
            };
            link.addEventListener('click', handler);
            this._navHandlers.push({ element: link, handler });
        });

        const logoutBtn = $('#admin-logout');
        if (logoutBtn) {
            this._logoutHandler = () => Auth.logout();
            logoutBtn.addEventListener('click', this._logoutHandler);
        }

        this._actionDelegateHandler = (e) => {
            const btn = e.target.closest('[data-admin-action]');
            if (!btn) return;
            const action = btn.getAttribute('data-admin-action');
            if (action === 'create-school') AdminPanel.openSchoolModal();
            if (action === 'create-manager') AdminPanel.openManagerModal();
            if (action === 'create-class') AdminPanel.openClassModal();
            if (action === 'create-student') AdminPanel.openStudentModal();
            if (action === 'create-ai') AdminPanel.openAIModal();
            if (action === 'create-rag') AdminPanel.openRAGModal();
            if (action === 'upload-rag') AdminPanel.openRAGUploadModal();
            if (action === 'upload-video') AdminPanel.openVideoUploadModal();
            if (action === 'open-db-reset') AdminPanel.openDBResetModal();
            if (action === 'export-audit') AdminPanel.exportAudit();
            if (action === 'change-admin-password') AdminPanel.openChangePasswordModal();
            if (action === 'change-admin-username') AdminPanel.openChangeUsernameModal();
            if (action === 'sync-gapgpt-models') AdminPanel.syncGapGPTModels();
            if (action === 'create-video-collection') AdminPanel.openVideoCollectionModal();
            if (action === 'create-download') { if (window.AdminDownloads) window.AdminDownloads.openCreateModal(); }
            if (action === 'create-test-account') AdminPanel.openTestAccountModal();
        };
        document.addEventListener('click', this._actionDelegateHandler);

        this._reportDelegateHandler = (e) => {
            const btn = e.target.closest('[data-report-dataset]');
            if (!btn) return;
            const ds = btn.getAttribute('data-report-dataset');
            if (ds) AdminPanel.selectReportDataset(ds);
        };
        document.addEventListener('click', this._reportDelegateHandler);

        const dlBtn = $('#reports-download-btn');
        if (dlBtn) {
            this._downloadHandler = () => AdminPanel.downloadReport();
            dlBtn.addEventListener('click', this._downloadHandler);
        }

        const timeSetupBtn = $('#time-setup-btn');
        if (timeSetupBtn) {
            this._timeSetupHandler = () => AdminPanel.openTimeSetupModal();
            timeSetupBtn.addEventListener('click', this._timeSetupHandler);
        }
        const timeSyncBtn = $('#time-sync-btn');
        if (timeSyncBtn) {
            this._timeSyncHandler = () => AdminPanel.syncServerTime();
            timeSyncBtn.addEventListener('click', this._timeSyncHandler);
        }

        this._searchHandlers = [];

        const sSearch = $('#admin-schools-search');
        if (sSearch) {
            const h = debounce(() => {
                State.adminFilters.schools = { ...(State.adminFilters.schools || {}), search: sSearch.value };
                State.adminPages.schools = 1;
                AdminPanel.loadSchools();
            });
            sSearch.addEventListener('input', h);
            this._searchHandlers.push({ element: sSearch, handler: h });
        }

        const mSearch = $('#admin-managers-search');
        if (mSearch) {
            const h = debounce(() => {
                State.adminFilters.managers = { ...(State.adminFilters.managers || {}), search: mSearch.value };
                State.adminPages.managers = 1;
                AdminPanel.loadManagers();
            });
            mSearch.addEventListener('input', h);
            this._searchHandlers.push({ element: mSearch, handler: h });
        }

        const cSearch = $('#admin-classes-search');
        if (cSearch) {
            const h = debounce(() => {
                State.adminFilters.classes = { ...(State.adminFilters.classes || {}), search: cSearch.value };
                State.adminPages.classes = 1;
                AdminPanel.loadClasses();
            });
            cSearch.addEventListener('input', h);
            this._searchHandlers.push({ element: cSearch, handler: h });
        }

        const stSearch = $('#admin-students-search');
        if (stSearch) {
            const h = debounce(() => {
                State.adminFilters.students = { ...(State.adminFilters.students || {}), search: stSearch.value };
                State.adminPages.students = 1;
                AdminPanel.loadStudents();
            });
            stSearch.addEventListener('input', h);
            this._searchHandlers.push({ element: stSearch, handler: h });
        }

        const vSearch = $('#admin-videos-search');
        if (vSearch) {
            const h = debounce(() => {
                State.adminFilters.videos = { ...(State.adminFilters.videos || {}), search: vSearch.value };
                State.adminPages.videos = 1;
                AdminPanel.loadVideos();
            });
            vSearch.addEventListener('input', h);
            this._searchHandlers.push({ element: vSearch, handler: h });
        }

        const vcSearch = $('#admin-video-collections-search');
        if (vcSearch) {
            const h = debounce(() => {
                State.adminFilters.videoCollections = {
                    ...(State.adminFilters.videoCollections || {}),
                    search: vcSearch.value,
                };
                State.adminPages.videoCollections = 1;
                AdminPanel.loadVideoCollections();
            });
            vcSearch.addEventListener('input', h);
            this._searchHandlers.push({ element: vcSearch, handler: h });
        }

        this._filterHandlers = [];

        const vcLevel = $('#admin-video-collections-level-filter');
        if (vcLevel) {
            const h = () => {
                State.adminFilters.videoCollections = {
                    ...(State.adminFilters.videoCollections || {}),
                    level: vcLevel.value,
                };
                State.adminPages.videoCollections = 1;
                AdminPanel.loadVideoCollections();
            };
            vcLevel.addEventListener('change', h);
            this._filterHandlers.push({ element: vcLevel, handler: h });
        }

        const vcStatus = $('#admin-video-collections-status-filter');
        if (vcStatus) {
            const h = () => {
                State.adminFilters.videoCollections = {
                    ...(State.adminFilters.videoCollections || {}),
                    is_active: vcStatus.value,
                };
                State.adminPages.videoCollections = 1;
                AdminPanel.loadVideoCollections();
            };
            vcStatus.addEventListener('change', h);
            this._filterHandlers.push({ element: vcStatus, handler: h });
        }

        this.initForms();

        AdminPanel.switchSubview('dashboard');
    },

    _teardown() {
        this._navHandlers.forEach(({ element, handler }) => {
            try { element.removeEventListener('click', handler); } catch (e) {}
        });
        this._navHandlers = [];

        const logoutBtn = $('#admin-logout');
        if (logoutBtn && this._logoutHandler) {
            try { logoutBtn.removeEventListener('click', this._logoutHandler); } catch (e) {}
            this._logoutHandler = null;
        }

        if (this._actionDelegateHandler) {
            try { document.removeEventListener('click', this._actionDelegateHandler); } catch (e) {}
            this._actionDelegateHandler = null;
        }

        if (this._reportDelegateHandler) {
            try { document.removeEventListener('click', this._reportDelegateHandler); } catch (e) {}
            this._reportDelegateHandler = null;
        }

        const dlBtn = $('#reports-download-btn');
        if (dlBtn && this._downloadHandler) {
            try { dlBtn.removeEventListener('click', this._downloadHandler); } catch (e) {}
            this._downloadHandler = null;
        }

        const timeSetupBtn = $('#time-setup-btn');
        if (timeSetupBtn && this._timeSetupHandler) {
            try { timeSetupBtn.removeEventListener('click', this._timeSetupHandler); } catch (e) {}
            this._timeSetupHandler = null;
        }
        const timeSyncBtn = $('#time-sync-btn');
        if (timeSyncBtn && this._timeSyncHandler) {
            try { timeSyncBtn.removeEventListener('click', this._timeSyncHandler); } catch (e) {}
            this._timeSyncHandler = null;
        }

        this._searchHandlers.forEach(({ element, handler }) => {
            try { element.removeEventListener('input', handler); } catch (e) {}
        });
        this._searchHandlers = [];

        this._filterHandlers.forEach(({ element, handler }) => {
            try { element.removeEventListener('change', handler); } catch (e) {}
        });
        this._filterHandlers = [];

        if (this.stopLiveClock) {
            try { this.stopLiveClock(); } catch (e) {}
        }
        // ✅ Bug #12 FIX
        if (this._timeState) {
            if (this._timeState.liveTimer) {
                try { clearInterval(this._timeState.liveTimer); } catch (e) {}
            }
            this._timeState = {
                serverUtc: null,
                serverTimestamp: null,
                timezone: null,
                offsetSeconds: 0,
                nextResetTimestamp: null,
                nextResetSeconds: 0,
                liveTimer: null,
                health: null,
            };
        }
    },

    switchSubview(name) {
        State.adminSubview = name;

        if (name !== 'time' && AdminPanel._timeState && AdminPanel._timeState.liveTimer) {
            AdminPanel.stopLiveClock();
        }

        $$('[data-admin-nav]').forEach(l =>
            l.classList.toggle('nav-link-active', l.getAttribute('data-admin-nav') === name));
        $$('[data-admin-view]').forEach(v =>
            v.hidden = v.getAttribute('data-admin-view') !== name);

        const titles = {
            dashboard: 'داشبورد مدیریت',
            schools: 'مدیریت مدارس',
            managers: 'مدیران مدارس',
            classes: 'مدیریت کلاس‌ها',
            students: 'مدیریت دانش‌آموزان',
            'ai-profiles': 'پروفایل‌های هوش مصنوعی',
            rag: 'پایگاه دانش RAG',
            videos: 'مدیریت ویدیوها',
            'video-collections': 'مجموعه‌های ویدیو',
            subscriptions: 'اشتراک‌ها',
            settings: 'تنظیمات سیستم',
            time: 'ساعت و زمان',
            tickets: 'مدیریت تیکت‌ها',
            storage: 'مدیریت حافظه',
            audit: 'گزارش فعالیت‌ها',
            reports: 'خروجی و گزارش‌ها',
            system: 'سیستم',
        };
        setText('#admin-page-title', titles[name] || 'پنل مدیریت');

        if (name === 'dashboard') AdminPanel.loadDashboard();
        if (name === 'schools') AdminPanel.loadSchools();
        if (name === 'managers') AdminPanel.loadManagers();
        if (name === 'classes') AdminPanel.loadClasses();
        if (name === 'students') AdminPanel.loadStudents();
        if (name === 'ai-profiles') AdminPanel.loadAI();
        if (name === 'rag') AdminPanel.loadRAG();
        if (name === 'videos') AdminPanel.loadVideos();
        if (name === 'video-collections') AdminPanel.loadVideoCollections();
        if (name === 'downloads' && window.AdminDownloads) window.AdminDownloads.load();
        if (name === 'subscriptions') AdminPanel.loadSubscriptions();
        if (name === 'settings') AdminPanel.loadSettings();
        if (name === 'time') AdminPanel.loadTime();
        if (name === 'tickets' && window.AdminTickets) window.AdminTickets.load();
        if (name === 'storage' && window.AdminStorageDashboard) window.AdminStorageDashboard.load();
        if (name === 'audit') AdminPanel.loadAudit();
        if (name === 'reports') AdminPanel.loadReports();
        if (name === 'test-accounts') AdminPanel.loadTestAccounts();
        if (name === 'system') AdminPanel.loadSystemHealth();
    },

    async loadDashboard() {
        try {
            const data = await API.get('/analytics/overview');
            const o = data.overview;
            setText('#admin-kpi-schools', formatNumber(o.total_schools));
            setText('#admin-kpi-classes', formatNumber(o.total_classes));
            setText('#admin-kpi-students', formatNumber(o.total_students));
            setText('#admin-kpi-ai', formatNumber(o.total_ai_profiles));
            setText('#admin-kpi-videos', formatNumber(o.total_videos));
            setText('#admin-kpi-messages', formatNumber(o.messages_last_30_days));
        } catch (err) { Toast.error(err.message); }
    },

    _buildQuery(baseFilters, page, extra = {}) {
        const p = new URLSearchParams();
        const merged = { ...baseFilters, ...extra };

        Object.entries(merged).forEach(([k, v]) => {
            if (k === 'page_size') return;
            if (v === null || v === undefined || v === '') return;
            p.set(k, String(v));
        });

        p.set('page', String(page || 1));

        const effectivePageSize = (merged.page_size !== undefined && merged.page_size !== null)
            ? merged.page_size
            : CONFIG.PAGE_SIZE;
        p.set('page_size', String(effectivePageSize));

        return p.toString();
    },

    _renderPagination(containerId, pagination, onPage) {
        const c = document.getElementById(containerId);
        if (!c) return;
        if (!pagination || pagination.total_pages <= 1) { c.innerHTML = ''; return; }
        c.innerHTML = '';
        const { page, total_pages, total } = pagination;
        c.appendChild(el('span', { class: 'pagination-info', text: `مجموع: ${formatNumber(total)}` }));
        const wrap = el('div', { class: 'pagination-controls' });
        const prev = el('button', {
            class: 'btn btn-sm btn-ghost',
            text: 'قبلی',
            disabled: page <= 1 ? 'true' : null,
            onClick: () => onPage(page - 1),
        });
        const next = el('button', {
            class: 'btn btn-sm btn-ghost',
            text: 'بعدی',
            disabled: page >= total_pages ? 'true' : null,
            onClick: () => onPage(page + 1),
        });
        wrap.appendChild(prev);
        wrap.appendChild(el('span', { class: 'pagination-current', text: `${formatNumber(page)} / ${formatNumber(total_pages)}` }));
        wrap.appendChild(next);
        c.appendChild(wrap);
    },

    async loadSchools() {
        const tbody = $('#admin-schools-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="9" class="table-empty">در حال بارگذاری...</td></tr>';
        try {
            const page = State.adminPages.schools || 1;
            const filters = { ...(State.adminFilters.schools || {}), include_deleted: 1 };
            const data = await API.get('/admin/schools?' + AdminPanel._buildQuery(filters, page));
            State.adminCache.schools = data.schools || [];
            State.adminPages.schools = data.page || 1;
            if (!data.schools.length) {
                tbody.innerHTML = '<tr><td colspan="9" class="table-empty">مدرسه‌ای یافت نشد.</td></tr>';
                AdminPanel._renderPagination('admin-schools-pagination', data, p => { State.adminPages.schools = p; AdminPanel.loadSchools(); });
                return;
            }
            tbody.innerHTML = '';
            data.schools.forEach(s => {
                tbody.appendChild(AdminPanel._schoolRow(s));
            });
            AdminPanel._renderPagination('admin-schools-pagination', data, p => { State.adminPages.schools = p; AdminPanel.loadSchools(); });
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="9" class="table-empty">خطا در بارگذاری.</td></tr>';
        }
    },

    _schoolRow(s) {
        const tr = el('tr');
        tr.appendChild(el('td', { text: s.name }));
        tr.appendChild(el('td', { text: s.code }));
        tr.appendChild(el('td', {}, [
            el('span', {
                class: 'badge badge-primary',
                text: LABELS.schoolType[s.school_type] || '—',
            }),
        ]));
        tr.appendChild(el('td', {}, [
            el('span', {
                class: 'badge badge-accent',
                text: LABELS.schoolGender[s.gender] || '—',
            }),
        ]));
        tr.appendChild(el('td', { text: formatNumber(s.student_count) }));
        tr.appendChild(el('td', { text: formatNumber(s.class_count) }));
        tr.appendChild(el('td', {}, [
            el('span', {
                class: 'badge ' + (s.deleted_at ? 'badge-error' : s.is_active ? 'badge-success' : 'badge-warning'),
                text: s.deleted_at ? 'حذف‌شده' : s.is_active ? 'فعال' : 'غیرفعال',
            }),
        ]));
        tr.appendChild(el('td', { text: formatDate(s.created_at) }));
        tr.appendChild(el('td', { class: 'actions-cell' }, AdminPanel._schoolActions(s)));
        return tr;
    },

    _schoolActions(s) {
        const actions = [];
        actions.push(el('button', {
            class: 'btn btn-sm btn-ghost', text: 'ویرایش',
            onClick: () => AdminPanel.openSchoolModal(s),
        }));
        if (s.deleted_at) {
            actions.push(el('button', {
                class: 'btn btn-sm btn-success', text: 'بازیابی',
                onClick: () => AdminPanel.restoreEntity('school', s.id, 'مدرسه', () => AdminPanel.loadSchools()),
            }));
            actions.push(el('button', {
                class: 'btn btn-sm btn-danger', text: 'حذف دائمی',
                onClick: () => AdminPanel.confirmPermanentDelete('school', s, () => AdminPanel.loadSchools()),
            }));
        } else if (s.is_active) {
            actions.push(el('button', {
                class: 'btn btn-sm btn-ghost', text: 'غیرفعال',
                onClick: () => AdminPanel.deactivateEntity('school', s.id, 'مدرسه', () => AdminPanel.loadSchools()),
            }));
            actions.push(el('button', {
                class: 'btn btn-sm btn-danger', text: 'حذف',
                onClick: () => AdminPanel.softDeleteEntity('school', s.id, 'مدرسه', () => AdminPanel.loadSchools()),
            }));
        } else {
            actions.push(el('button', {
                class: 'btn btn-sm btn-success', text: 'فعال‌سازی',
                onClick: () => AdminPanel.activateEntity('school', s.id, 'مدرسه', () => AdminPanel.loadSchools()),
            }));
            actions.push(el('button', {
                class: 'btn btn-sm btn-danger', text: 'حذف',
                onClick: () => AdminPanel.softDeleteEntity('school', s.id, 'مدرسه', () => AdminPanel.loadSchools()),
            }));
        }
        return actions;
    },

    async loadManagers() {
        const tbody = $('#admin-managers-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="7" class="table-empty">در حال بارگذاری...</td></tr>';
        try {
            const page = State.adminPages.managers || 1;
            const filters = { ...(State.adminFilters.managers || {}), include_deleted: 1 };
            const data = await API.get('/admin/managers?' + AdminPanel._buildQuery(filters, page));
            State.adminCache.managers = data.managers || [];
            State.adminPages.managers = data.page || 1;
            if (!data.managers.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="table-empty">مدیری یافت نشد.</td></tr>';
                AdminPanel._renderPagination('admin-managers-pagination', data, p => {
                    State.adminPages.managers = p;
                    AdminPanel.loadManagers();
                });
                return;
            }
            tbody.innerHTML = '';
            data.managers.forEach(m => tbody.appendChild(AdminPanel._managerRow(m)));
            AdminPanel._renderPagination('admin-managers-pagination', data, p => {
                State.adminPages.managers = p;
                AdminPanel.loadManagers();
            });
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="7" class="table-empty">خطا در بارگذاری.</td></tr>';
        }
    },

    _managerRow(m) {
        const tr = el('tr');
        tr.appendChild(el('td', { text: m.full_name }));
        tr.appendChild(el('td', { text: m.username }));
        tr.appendChild(el('td', { text: m.school_name || '—' }));
        tr.appendChild(el('td', {}, [
            el('span', {
                class: 'badge ' + (m.deleted_at ? 'badge-error' : m.is_active ? 'badge-success' : 'badge-warning'),
                text: m.deleted_at ? 'حذف‌شده' : m.is_active ? 'فعال' : 'غیرفعال',
            }),
        ]));
        tr.appendChild(el('td', { text: m.last_login_at ? formatDateTime(m.last_login_at) : '—' }));
        tr.appendChild(el('td', { text: formatDate(m.created_at) }));
        tr.appendChild(el('td', { class: 'actions-cell' }, AdminPanel._managerActions(m)));
        return tr;
    },

    _managerActions(m) {
        const actions = [
            el('button', {
                class: 'btn btn-sm btn-ghost', text: 'ویرایش',
                onClick: () => AdminPanel.openManagerModal(m),
            }),
            el('button', {
                class: 'btn btn-sm btn-ghost', text: 'تغییر رمز',
                onClick: () => AdminPanel.resetManagerPassword(m),
            }),
        ];
        if (m.deleted_at) {
            actions.push(el('button', {
                class: 'btn btn-sm btn-success', text: 'بازیابی',
                onClick: () => AdminPanel.restoreEntity('manager', m.id, 'مدیر', () => AdminPanel.loadManagers()),
            }));
            actions.push(el('button', {
                class: 'btn btn-sm btn-danger', text: 'حذف دائمی',
                onClick: () => AdminPanel.confirmPermanentDelete('manager', m, () => AdminPanel.loadManagers()),
            }));
        } else if (m.is_active) {
            actions.push(el('button', {
                class: 'btn btn-sm btn-ghost', text: 'غیرفعال',
                onClick: () => AdminPanel.deactivateEntity('manager', m.id, 'مدیر', () => AdminPanel.loadManagers()),
            }));
            actions.push(el('button', {
                class: 'btn btn-sm btn-danger', text: 'حذف',
                onClick: () => AdminPanel.softDeleteEntity('manager', m.id, 'مدیر', () => AdminPanel.loadManagers()),
            }));
        } else {
            actions.push(el('button', {
                class: 'btn btn-sm btn-success', text: 'فعال‌سازی',
                onClick: () => AdminPanel.activateEntity('manager', m.id, 'مدیر', () => AdminPanel.loadManagers()),
            }));
            actions.push(el('button', {
                class: 'btn btn-sm btn-danger', text: 'حذف',
                onClick: () => AdminPanel.softDeleteEntity('manager', m.id, 'مدیر', () => AdminPanel.loadManagers()),
            }));
        }
        return actions;
    },

    async loadClasses() {
        const tbody = $('#admin-classes-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="7" class="table-empty">در حال بارگذاری...</td></tr>';
        try {
            const page = State.adminPages.classes || 1;
            const filters = { ...(State.adminFilters.classes || {}), include_deleted: 1 };
            const data = await API.get('/admin/classes?' + AdminPanel._buildQuery(filters, page));
            State.adminCache.classes = data.classes || [];
            State.adminPages.classes = data.page || 1;
            if (!data.classes.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="table-empty">کلاسی یافت نشد.</td></tr>';
                AdminPanel._renderPagination('admin-classes-pagination', data, p => { State.adminPages.classes = p; AdminPanel.loadClasses(); });
                return;
            }
            tbody.innerHTML = '';
            data.classes.forEach(c => tbody.appendChild(AdminPanel._classRow(c)));
            AdminPanel._renderPagination('admin-classes-pagination', data, p => { State.adminPages.classes = p; AdminPanel.loadClasses(); });
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="7" class="table-empty">خطا در بارگذاری.</td></tr>';
        }
    },

    _classRow(c) {
        const tr = el('tr');
        tr.appendChild(el('td', { text: c.name }));
        tr.appendChild(el('td', { text: c.school_name || '—' }));
        tr.appendChild(el('td', { text: c.grade || '—' }));
        tr.appendChild(el('td', { text: formatNumber(c.student_count) }));
        tr.appendChild(el('td', { text: formatNumber(c.ai_count) }));
        tr.appendChild(el('td', {}, [
            el('span', {
                class: 'badge ' + (c.deleted_at ? 'badge-error' : c.is_active ? 'badge-success' : 'badge-warning'),
                text: c.deleted_at ? 'حذف‌شده' : c.is_active ? 'فعال' : 'غیرفعال',
            }),
        ]));
        tr.appendChild(el('td', { class: 'actions-cell' }, AdminPanel._classActions(c)));
        return tr;
    },

    _classActions(c) {
        const actions = [
            el('button', { class: 'btn btn-sm btn-ghost', text: 'ویرایش', onClick: () => AdminPanel.openClassModal(c) }),
        ];
        if (c.deleted_at) {
            actions.push(el('button', { class: 'btn btn-sm btn-success', text: 'بازیابی', onClick: () => AdminPanel.restoreEntity('class', c.id, 'کلاس', () => AdminPanel.loadClasses()) }));
            actions.push(el('button', { class: 'btn btn-sm btn-danger', text: 'حذف دائمی', onClick: () => AdminPanel.confirmPermanentDelete('class', c, () => AdminPanel.loadClasses()) }));
        } else if (c.is_active) {
            actions.push(el('button', { class: 'btn btn-sm btn-ghost', text: 'غیرفعال', onClick: () => AdminPanel.deactivateEntity('class', c.id, 'کلاس', () => AdminPanel.loadClasses()) }));
            actions.push(el('button', { class: 'btn btn-sm btn-danger', text: 'حذف', onClick: () => AdminPanel.softDeleteEntity('class', c.id, 'کلاس', () => AdminPanel.loadClasses()) }));
        } else {
            actions.push(el('button', { class: 'btn btn-sm btn-success', text: 'فعال‌سازی', onClick: () => AdminPanel.activateEntity('class', c.id, 'کلاس', () => AdminPanel.loadClasses()) }));
            actions.push(el('button', { class: 'btn btn-sm btn-danger', text: 'حذف', onClick: () => AdminPanel.softDeleteEntity('class', c.id, 'کلاس', () => AdminPanel.loadClasses()) }));
        }
        return actions;
    },

    async loadStudents() {
        const tbody = $('#admin-students-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="8" class="table-empty">در حال بارگذاری...</td></tr>';
        try {
            const page = State.adminPages.students || 1;
            const filters = { ...(State.adminFilters.students || {}), include_deleted: 1 };
            const data = await API.get('/admin/students?' + AdminPanel._buildQuery(filters, page));
            State.adminCache.students = data.students || [];
            State.adminPages.students = data.page || 1;
            if (!data.students.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="table-empty">دانش‌آموزی یافت نشد.</td></tr>';
                AdminPanel._renderPagination('admin-students-pagination', data, p => { State.adminPages.students = p; AdminPanel.loadStudents(); });
                return;
            }
            tbody.innerHTML = '';
            data.students.forEach(s => tbody.appendChild(AdminPanel._studentRow(s)));
            AdminPanel._renderPagination('admin-students-pagination', data, p => { State.adminPages.students = p; AdminPanel.loadStudents(); });
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="8" class="table-empty">خطا در بارگذاری.</td></tr>';
        }
    },

    _studentRow(s) {
        const tr = el('tr');
        tr.appendChild(el('td', { text: s.full_name }));
        tr.appendChild(el('td', { text: s.username || '—' }));
        tr.appendChild(el('td', { text: s.student_code || '—' }));
        tr.appendChild(el('td', { text: s.school_name || '—' }));
        tr.appendChild(el('td', { text: s.class_name || '—' }));
        tr.appendChild(el('td', {}, [
            el('span', {
                class: 'badge ' + (s.deleted_at ? 'badge-error' : s.is_active ? 'badge-success' : 'badge-warning'),
                text: s.deleted_at ? 'حذف‌شده' : s.is_active ? 'فعال' : 'غیرفعال',
            }),
        ]));
        tr.appendChild(el('td', { text: formatDate(s.created_at) }));
        tr.appendChild(el('td', { class: 'actions-cell' }, AdminPanel._studentActions(s)));
        return tr;
    },

    _studentActions(s) {
        const actions = [
            el('button', { class: 'btn btn-sm btn-ghost', text: 'ویرایش', onClick: () => AdminPanel.openStudentModal(s) }),
            el('button', { class: 'btn btn-sm btn-ghost', text: 'تغییر رمز', onClick: () => AdminPanel.resetStudentPassword(s) }),
        ];
        if (s.deleted_at) {
            actions.push(el('button', { class: 'btn btn-sm btn-success', text: 'بازیابی', onClick: () => AdminPanel.restoreEntity('student', s.id, 'دانش‌آموز', () => AdminPanel.loadStudents()) }));
            actions.push(el('button', { class: 'btn btn-sm btn-danger', text: 'حذف دائمی', onClick: () => AdminPanel.confirmPermanentDelete('student', s, () => AdminPanel.loadStudents()) }));
        } else if (s.is_active) {
            actions.push(el('button', { class: 'btn btn-sm btn-ghost', text: 'غیرفعال', onClick: () => AdminPanel.deactivateEntity('student', s.id, 'دانش‌آموز', () => AdminPanel.loadStudents()) }));
            actions.push(el('button', { class: 'btn btn-sm btn-danger', text: 'حذف', onClick: () => AdminPanel.softDeleteEntity('student', s.id, 'دانش‌آموز', () => AdminPanel.loadStudents()) }));
        } else {
            actions.push(el('button', { class: 'btn btn-sm btn-success', text: 'فعال‌سازی', onClick: () => AdminPanel.activateEntity('student', s.id, 'دانش‌آموز', () => AdminPanel.loadStudents()) }));
            actions.push(el('button', { class: 'btn btn-sm btn-danger', text: 'حذف', onClick: () => AdminPanel.softDeleteEntity('student', s.id, 'دانش‌آموز', () => AdminPanel.loadStudents()) }));
        }
        return actions;
    },

    async loadAI() {
        const tbody = $('#admin-ai-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="8" class="table-empty">در حال بارگذاری...</td></tr>';
        try {
            const page = State.adminPages.ai || 1;
            const filters = { include_deleted: 1 };
            const data = await API.get('/admin/ai-profiles?' + AdminPanel._buildQuery(filters, page));
            State.adminCache.aiProfiles = data.ai_profiles || [];
            State.adminPages.ai = data.page || 1;
            if (!data.ai_profiles.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="table-empty">پروفایلی یافت نشد.</td></tr>';
                AdminPanel._renderPagination('admin-ai-pagination', data, p => { State.adminPages.ai = p; AdminPanel.loadAI(); });
                return;
            }
            tbody.innerHTML = '';
            data.ai_profiles.forEach(p => tbody.appendChild(AdminPanel._aiRow(p)));
            AdminPanel._renderPagination('admin-ai-pagination', data, p => { State.adminPages.ai = p; AdminPanel.loadAI(); });
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="8" class="table-empty">خطا در بارگذاری.</td></tr>';
        }
    },

    _aiRow(p) {
        const tr = el('tr');
        tr.appendChild(el('td', { text: p.name }));
        tr.appendChild(el('td', { text: p.model_provider || '—' }));
        tr.appendChild(el('td', { text: p.model_name || '—' }));

        const keyCell = el('td');
        if (p.has_api_key) {
            keyCell.appendChild(el('span', {
                class: 'badge badge-success',
                text: '🔑 فعال',
                title: p.api_key_hint || '',
            }));
            if (p.api_key_hint) {
                keyCell.appendChild(el('span', {
                    class: 'text-muted',
                    style: { marginRight: '6px', fontSize: '11px', direction: 'ltr', display: 'inline-block' },
                    text: p.api_key_hint,
                }));
            }
        } else {
            const isGapGpt = (p.model_provider === 'gapgpt');
            keyCell.appendChild(el('span', {
                class: 'badge ' + (isGapGpt ? 'badge-primary' : 'badge-warning'),
                text: isGapGpt ? '🔒 کلید سراسری' : '⚠ بدون کلید',
                title: isGapGpt ? 'کلید API در پیکربندی سرور (ENV یا secrets.php) نگهداری می‌شود' : '',
            }));
        }
        tr.appendChild(keyCell);

        tr.appendChild(el('td', { text: formatNumber(p.class_count) }));
        tr.appendChild(el('td', { text: formatNumber(p.rag_count) }));
        tr.appendChild(el('td', {}, [
            el('span', {
                class: 'badge ' + (p.deleted_at ? 'badge-error' : p.is_active ? 'badge-success' : 'badge-warning'),
                text: p.deleted_at ? 'حذف‌شده' : p.is_active ? 'فعال' : 'غیرفعال',
            }),
        ]));
        tr.appendChild(el('td', { class: 'actions-cell' }, AdminPanel._aiActions(p)));
        return tr;
    },

    _aiActions(p) {
        const actions = [
            el('button', { class: 'btn btn-sm btn-ghost', text: 'ویرایش', onClick: () => AdminPanel.openAIModal(p) }),
        ];
        if (p.model_provider && p.model_provider !== 'local') {
            actions.push(el('button', {
                class: 'btn btn-sm btn-ghost',
                text: '🔌 تست',
                title: 'تست اتصال به provider',
                onClick: () => AdminPanel.testExistingAI(p),
            }));
        }
        if (p.deleted_at) {
            actions.push(el('button', { class: 'btn btn-sm btn-success', text: 'بازیابی', onClick: () => AdminPanel.restoreEntity('ai_profile', p.id, 'پروفایل', () => AdminPanel.loadAI()) }));
            actions.push(el('button', { class: 'btn btn-sm btn-danger', text: 'حذف دائمی', onClick: () => AdminPanel.confirmPermanentDelete('ai_profile', p, () => AdminPanel.loadAI()) }));
        } else if (p.is_active) {
            actions.push(el('button', { class: 'btn btn-sm btn-ghost', text: 'غیرفعال', onClick: () => AdminPanel.deactivateEntity('ai_profile', p.id, 'پروفایل', () => AdminPanel.loadAI()) }));
            actions.push(el('button', { class: 'btn btn-sm btn-danger', text: 'حذف', onClick: () => AdminPanel.softDeleteEntity('ai_profile', p.id, 'پروفایل', () => AdminPanel.loadAI()) }));
        } else {
            actions.push(el('button', { class: 'btn btn-sm btn-success', text: 'فعال‌سازی', onClick: () => AdminPanel.activateEntity('ai_profile', p.id, 'پروفایل', () => AdminPanel.loadAI()) }));
            actions.push(el('button', { class: 'btn btn-sm btn-danger', text: 'حذف', onClick: () => AdminPanel.softDeleteEntity('ai_profile', p.id, 'پروفایل', () => AdminPanel.loadAI()) }));
        }
        return actions;
    },

    async loadRAG() {
        const grid = $('#admin-rag-grid');
        if (!grid) return;
        grid.innerHTML = '<div class="empty-state"><div class="empty-title">در حال بارگذاری...</div></div>';
        try {
            const page = State.adminPages.rag || 1;
            const filters = { include_deleted: 1 };
            const data = await API.get('/admin/rag/collections?' + AdminPanel._buildQuery(filters, page));
            State.adminCache.ragCollections = data.collections || [];
            State.adminPages.rag = data.page || 1;
            if (!data.collections.length) {
                grid.innerHTML = '<div class="empty-state"><div class="empty-icon">📚</div><div class="empty-title">مجموعه‌ای وجود ندارد</div></div>';
                AdminPanel._renderPagination('admin-rag-pagination', data, p => { State.adminPages.rag = p; AdminPanel.loadRAG(); });
                return;
            }
            grid.innerHTML = '';
            data.collections.forEach(c => grid.appendChild(AdminPanel._ragCard(c)));
            AdminPanel._renderPagination('admin-rag-pagination', data, p => { State.adminPages.rag = p; AdminPanel.loadRAG(); });
        } catch (err) { Toast.error(err.message); }
    },

    _ragCard(c) {
        const card = el('div', { class: 'class-card' });
        card.appendChild(el('div', { class: 'class-card-title', text: c.name }));
        card.appendChild(el('div', { class: 'class-card-meta', text: c.description || '—' }));
        card.appendChild(el('div', { class: 'class-card-stats' }, [
            el('span', { text: formatNumber(c.document_count) + ' سند' }),
            el('span', { class: 'badge ' + (c.deleted_at ? 'badge-error' : c.is_active ? 'badge-success' : 'badge-warning'),
                text: c.deleted_at ? 'حذف‌شده' : c.is_active ? 'فعال' : 'غیرفعال' }),
        ]));
        const actions = el('div', { class: 'class-card-actions' });
        actions.appendChild(el('button', { class: 'btn btn-sm btn-ghost', text: 'جزئیات',
            onClick: () => AdminPanel.openRAGDetailModal(c) }));
        actions.appendChild(el('button', { class: 'btn btn-sm btn-primary', text: '+ سند',
            onClick: () => AdminPanel.openRAGUploadModal(c.id) }));
        if (c.deleted_at) {
            actions.appendChild(el('button', { class: 'btn btn-sm btn-success', text: 'بازیابی',
                onClick: () => AdminPanel.restoreEntity('rag_collection', c.id, 'مجموعه', () => AdminPanel.loadRAG()) }));
            actions.appendChild(el('button', { class: 'btn btn-sm btn-danger', text: 'حذف دائمی',
                onClick: () => AdminPanel.confirmPermanentDelete('rag_collection', c, () => AdminPanel.loadRAG()) }));
        } else if (c.is_active) {
            actions.appendChild(el('button', { class: 'btn btn-sm btn-ghost', text: 'غیرفعال',
                onClick: () => AdminPanel.deactivateEntity('rag_collection', c.id, 'مجموعه', () => AdminPanel.loadRAG()) }));
            actions.appendChild(el('button', { class: 'btn btn-sm btn-danger', text: 'حذف',
                onClick: () => AdminPanel.softDeleteEntity('rag_collection', c.id, 'مجموعه', () => AdminPanel.loadRAG()) }));
        } else {
            actions.appendChild(el('button', { class: 'btn btn-sm btn-success', text: 'فعال‌سازی',
                onClick: () => AdminPanel.activateEntity('rag_collection', c.id, 'مجموعه', () => AdminPanel.loadRAG()) }));
        }
        card.appendChild(actions);
        return card;
    },

    async loadVideos() {
        const tbody = $('#admin-videos-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="9" class="table-empty">در حال بارگذاری...</td></tr>';
        try {
            const page = State.adminPages.videos || 1;
            const filters = { ...(State.adminFilters.videos || {}), include_deleted: 1 };
            const data = await API.get('/admin/videos?' + AdminPanel._buildQuery(filters, page));
            State.adminCache.videos = data.videos || [];
            State.adminPages.videos = data.page || 1;
            if (!data.videos.length) {
                tbody.innerHTML = '<tr><td colspan="9" class="table-empty">ویدیویی یافت نشد.</td></tr>';
                AdminPanel._renderPagination('admin-videos-pagination', data, p => { State.adminPages.videos = p; AdminPanel.loadVideos(); });
                return;
            }
            tbody.innerHTML = '';
            data.videos.forEach(v => tbody.appendChild(AdminPanel._videoRow(v)));
            AdminPanel._renderPagination('admin-videos-pagination', data, p => { State.adminPages.videos = p; AdminPanel.loadVideos(); });
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="9" class="table-empty">خطا در بارگذاری.</td></tr>';
        }
    },

    _videoRow(v) {
        const tr = el('tr');
        tr.appendChild(el('td', { text: v.title }));
        const gradeDisplay = (v.effective_grade !== null && v.effective_grade !== undefined)
            ? `پایه ${v.effective_grade}`
            : (v.grade || '—');
        tr.appendChild(el('td', { text: gradeDisplay }));
        tr.appendChild(el('td', { text: v.subject || '—' }));
        tr.appendChild(el('td', {}, [
            el('span', {
                class: 'badge badge-primary',
                text: LABELS.schoolType[v.target_school_type] || '—',
                style: { marginLeft: '4px' },
            }),
            el('span', {
                class: 'badge badge-accent',
                text: LABELS.videoGender[v.target_gender] || '—',
            }),
        ]));
        tr.appendChild(el('td', { text: v.duration ? formatDuration(v.duration) : '—' }));
        tr.appendChild(el('td', { text: formatFileSize(v.file_size) }));
        tr.appendChild(el('td', { text: formatNumber(v.view_count) }));
        tr.appendChild(el('td', {}, [
            el('span', {
                class: 'badge ' + (v.deleted_at ? 'badge-error' : v.is_active ? 'badge-success' : 'badge-warning'),
                text: v.deleted_at ? 'حذف‌شده' : v.is_active ? 'فعال' : 'غیرفعال',
            }),
        ]));
        tr.appendChild(el('td', { class: 'actions-cell' }, AdminPanel._videoActions(v)));
        return tr;
    },

    _videoActions(v) {
        const actions = [
            el('button', { class: 'btn btn-sm btn-ghost', text: 'ویرایش', onClick: () => AdminPanel.openVideoEditModal(v) }),
        ];
        if (v.thumbnail_path) {
            actions.push(el('button', { class: 'btn btn-sm btn-ghost', text: 'بندانگشتی',
                onClick: () => AdminPanel.regenThumb(v.id) }));
        }
        if (v.deleted_at) {
            actions.push(el('button', { class: 'btn btn-sm btn-success', text: 'بازیابی', onClick: () => AdminPanel.restoreEntity('video', v.id, 'ویدیو', () => AdminPanel.loadVideos()) }));
            actions.push(el('button', { class: 'btn btn-sm btn-danger', text: 'حذف دائمی', onClick: () => AdminPanel.confirmPermanentDelete('video', v, () => AdminPanel.loadVideos()) }));
        } else if (v.is_active) {
            actions.push(el('button', { class: 'btn btn-sm btn-ghost', text: 'غیرفعال', onClick: () => AdminPanel.deactivateEntity('video', v.id, 'ویدیو', () => AdminPanel.loadVideos()) }));
            actions.push(el('button', { class: 'btn btn-sm btn-danger', text: 'حذف', onClick: () => AdminPanel.softDeleteEntity('video', v.id, 'ویدیو', () => AdminPanel.loadVideos()) }));
        } else {
            actions.push(el('button', { class: 'btn btn-sm btn-success', text: 'فعال‌سازی', onClick: () => AdminPanel.activateEntity('video', v.id, 'ویدیو', () => AdminPanel.loadVideos()) }));
            actions.push(el('button', { class: 'btn btn-sm btn-danger', text: 'حذف', onClick: () => AdminPanel.softDeleteEntity('video', v.id, 'ویدیو', () => AdminPanel.loadVideos()) }));
        }
        return actions;
    },

    async loadVideoCollections() {
        const grid = $('#admin-video-collections-grid');
        if (!grid) return;
        grid.innerHTML = '<div class="empty-state"><div class="empty-title">در حال بارگذاری...</div></div>';

        try {
            const page = State.adminPages.videoCollections || 1;
            const filters = {
                ...(State.adminFilters.videoCollections || {}),
                include_deleted: 1,
            };
            const data = await API.get('/admin/video-collections?' + AdminPanel._buildQuery(filters, page));

            State.adminCache.videoCollections = data.collections || [];
            State.adminPages.videoCollections = data.page || 1;

            if (!data.collections.length) {
                grid.innerHTML = '<div class="empty-state"><div class="empty-icon">🎞</div><div class="empty-title">مجموعه‌ای وجود ندارد</div><div class="empty-text">اولین مجموعه را ایجاد کنید.</div></div>';
                AdminPanel._renderPagination('admin-video-collections-pagination', data, p => {
                    State.adminPages.videoCollections = p;
                    AdminPanel.loadVideoCollections();
                });
                return;
            }

            grid.innerHTML = '';
            data.collections.forEach(c => grid.appendChild(AdminPanel._videoCollectionCard(c)));
            AdminPanel._renderPagination('admin-video-collections-pagination', data, p => {
                State.adminPages.videoCollections = p;
                AdminPanel.loadVideoCollections();
            });
        } catch (err) {
            grid.innerHTML = `<div class="empty-state"><div class="empty-title">خطا: ${escapeHtml(err.message)}</div></div>`;
        }
    },

    _videoCollectionCard(c) {
        const card = el('div', { class: 'class-card', style: { overflow: 'hidden', padding: '0' } });

        const cover = el('div', {
            style: {
                height: '140px',
                background: 'var(--color-surface-alt)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                position: 'relative',
                overflow: 'hidden',
            },
        });

        if (c.cover_url) {
            const img = document.createElement('img');
            img.src = c.cover_url;
            img.alt = c.title;
            img.style.width = '100%';
            img.style.height = '100%';
            img.style.objectFit = 'cover';
            img.onerror = () => {
                img.remove();
                const fallback = document.createElement('span');
                fallback.textContent = '📚';
                fallback.style.fontSize = '48px';
                cover.insertBefore(fallback, cover.firstChild);
            };
            cover.appendChild(img);
        } else {
            const fallback = document.createElement('span');
            fallback.textContent = '📚';
            fallback.style.fontSize = '48px';
            cover.appendChild(fallback);
        }

        const statusBadge = el('span', {
            class: 'badge ' + (c.deleted_at ? 'badge-error' : c.is_active ? 'badge-success' : 'badge-warning'),
            text: c.deleted_at ? 'حذف‌شده' : c.is_active ? 'فعال' : 'غیرفعال',
            style: { position: 'absolute', top: '8px', left: '8px' },
        });
        cover.appendChild(statusBadge);

        card.appendChild(cover);

        const body = el('div', { style: { padding: '16px' } });
        body.appendChild(el('div', { class: 'class-card-title', text: c.title }));
        if (c.description) {
            body.appendChild(el('div', {
                class: 'class-card-meta',
                text: c.description,
                style: {
                    display: '-webkit-box',
                    '-webkit-line-clamp': '2',
                    '-webkit-box-orient': 'vertical',
                    overflow: 'hidden',
                    marginBottom: '8px',
                },
            }));
        }
        body.appendChild(el('div', { class: 'class-card-stats', style: { marginBottom: '8px' } }, [
            el('span', { text: `${formatNumber(c.video_count)} ویدیو` }),
            el('span', { text: ' • ' }),
            el('span', { text: formatDurationFa(c.total_duration) }),
        ]));

        const metaRow = el('div', { class: 'class-card-stats' });
        if (c.category) {
            metaRow.appendChild(el('span', { class: 'badge badge-primary', text: c.category }));
        }
        if (c.level) {
            metaRow.appendChild(el('span', { class: 'badge badge-accent', text: LABELS.collectionLevel[c.level] || c.level }));
        }
        if (metaRow.childNodes.length) body.appendChild(metaRow);

        card.appendChild(body);

        const actions = el('div', { class: 'class-card-actions', style: { padding: '0 16px 16px' } });

        actions.appendChild(el('button', {
            class: 'btn btn-sm btn-ghost',
            text: '✎ ویرایش',
            onClick: () => AdminPanel.openVideoCollectionModal(c),
        }));

        actions.appendChild(el('button', {
            class: 'btn btn-sm btn-primary',
            text: '🎬 مدیریت ویدیوها',
            onClick: () => AdminPanel.openCollectionVideosModal(c),
        }));

        if (c.deleted_at) {
            actions.appendChild(el('button', {
                class: 'btn btn-sm btn-success', text: 'بازیابی',
                onClick: () => AdminPanel.restoreEntity('video_collection', c.id, 'مجموعه', () => AdminPanel.loadVideoCollections()),
            }));
            actions.appendChild(el('button', {
                class: 'btn btn-sm btn-danger', text: 'حذف دائمی',
                onClick: () => AdminPanel.confirmPermanentDelete('video_collection', c, () => AdminPanel.loadVideoCollections()),
            }));
        } else if (c.is_active) {
            actions.appendChild(el('button', {
                class: 'btn btn-sm btn-ghost', text: 'غیرفعال',
                onClick: () => AdminPanel.deactivateEntity('video_collection', c.id, 'مجموعه', () => AdminPanel.loadVideoCollections()),
            }));
            actions.appendChild(el('button', {
                class: 'btn btn-sm btn-danger', text: 'حذف',
                onClick: () => AdminPanel.softDeleteEntity('video_collection', c.id, 'مجموعه', () => AdminPanel.loadVideoCollections()),
            }));
        } else {
            actions.appendChild(el('button', {
                class: 'btn btn-sm btn-success', text: 'فعال‌سازی',
                onClick: () => AdminPanel.activateEntity('video_collection', c.id, 'مجموعه', () => AdminPanel.loadVideoCollections()),
            }));
        }

        card.appendChild(actions);
        return card;
    },

    async loadSubscriptions() {
        const tbody = $('#admin-subs-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="6" class="table-empty">در حال بارگذاری...</td></tr>';
        try {
            const page = State.adminPages.subs || 1;
            const data = await API.get('/admin/subscriptions?' + AdminPanel._buildQuery({}, page));
            State.adminCache.subscriptions = data.subscriptions || [];
            State.adminPages.subs = data.page || 1;
            if (!data.subscriptions.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="table-empty">اشتراکی یافت نشد.</td></tr>';
                return;
            }
            tbody.innerHTML = '';
            data.subscriptions.forEach(s => {
                tbody.appendChild(el('tr', {}, [
                    el('td', { text: s.school_name || '—' }),
                    el('td', { text: s.plan_name || '—' }),
                    el('td', { text: formatDate(s.start_date) }),
                    el('td', { text: formatDate(s.end_date) }),
                    el('td', {}, [
                        el('span', {
                            class: 'badge ' + (s.status === 'active' ? 'badge-success' : 'badge-warning'),
                            text: s.status,
                        }),
                    ]),
                    el('td', {}, [
                        el('button', {
                            class: 'btn btn-sm btn-danger', text: 'حذف',
                            onClick: () => {
                                Modal.confirm('این اشتراک حذف شود؟', async () => {
                                    try {
                                        await API.del('/admin/subscriptions/' + s.id);
                                        Toast.success('اشتراک حذف شد.');
                                        AdminPanel.loadSubscriptions();
                                    } catch (err) { Toast.error(err.message); }
                                });
                            },
                        }),
                    ]),
                ]));
            });
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="6" class="table-empty">خطا در بارگذاری.</td></tr>';
        }
    },

    async loadSettings() {
        const list = $('#admin-settings-list');
        if (!list) return;
        list.innerHTML = '<div class="empty-state"><div class="empty-title">در حال بارگذاری...</div></div>';
        try {
            const data = await API.get('/admin/settings');
            const settings = data.settings || [];
            State.adminCache.settings = settings;
            if (!settings.length) {
                list.innerHTML = '<div class="empty-state"><div class="empty-title">تنظیماتی یافت نشد</div></div>';
                return;
            }
            list.innerHTML = '';
            settings.forEach(s => {
                list.appendChild(el('div', { class: 'setting-item' }, [
                    el('div', { class: 'setting-info' }, [
                        el('div', { class: 'setting-key', text: s.key }),
                        el('div', { class: 'setting-desc', text: s.description || '' }),
                    ]),
                    el('div', { class: 'setting-value' }, [
                        el('input', {
                            type: 'text', class: 'form-input', value: s.value || '',
                            'data-setting-key': s.key,
                        }),
                        el('button', {
                            class: 'btn btn-sm btn-primary', text: 'ذخیره',
                            onClick: () => AdminPanel.saveSetting(s.key),
                        }),
                    ]),
                ]));
            });
        } catch (err) { Toast.error(err.message); }
    },

    async saveSetting(key) {
        const input = document.querySelector(`[data-setting-key="${key}"]`);
        if (!input) return;
        try {
            await API.put('/admin/settings/' + key, { value: input.value });
            Toast.success('ذخیره شد.');
        } catch (err) { Toast.error(err.message); }
    },

    async loadAudit() {
        const tbody = $('#admin-audit-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="6" class="table-empty">در حال بارگذاری...</td></tr>';
        try {
            const page = State.adminPages.audit || 1;
            const data = await API.get('/admin/audit-logs?' + AdminPanel._buildQuery({}, page, { page_size: 50 }));
            State.adminPages.audit = data.page || 1;
            if (!data.audit_logs.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="table-empty">گزارشی یافت نشد.</td></tr>';
                AdminPanel._renderPagination('admin-audit-pagination', data, p => { State.adminPages.audit = p; AdminPanel.loadAudit(); });
                return;
            }
            tbody.innerHTML = '';
            data.audit_logs.forEach(log => {
                tbody.appendChild(el('tr', {}, [
                    el('td', { text: formatDateTime(log.created_at) }),
                    el('td', { text: log.actor_full_name || log.actor_username || '—' }),
                    el('td', { text: log.action }),
                    el('td', { text: log.entity_type || '—' }),
                    el('td', { text: log.ip_address || '—' }),
                    el('td', { text: (log.metadata ? JSON.stringify(log.metadata).slice(0, 60) : '—') }),
                ]));
            });
            AdminPanel._renderPagination('admin-audit-pagination', data, p => { State.adminPages.audit = p; AdminPanel.loadAudit(); });
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="6" class="table-empty">خطا در بارگذاری.</td></tr>';
        }
    },

    async exportAudit() {
        try {
            const url = CONFIG.API_BASE + '/admin/audit-logs/export?format=csv';
            const resp = await fetch(url, {
                credentials: 'include',
                headers: { [CONFIG.CSRF_HEADER]: API._csrf() || '' },
            });
            if (!resp.ok) { Toast.error('خروجی گرفتن ناموفق بود.'); return; }
            const blob = await resp.blob();
            const u = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = u;
            a.download = `danova_audit_${Date.now()}.csv`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(u);
            Toast.success('خروجی دانلود شد.');
        } catch (err) { Toast.error('خطا در خروجی گرفتن.'); }
    },

    async loadSystemHealth() {
        const grid = $('#admin-system-grid');
        if (!grid) return;
        grid.innerHTML = '<div class="empty-state"><div class="empty-title">در حال بارگذاری...</div></div>';
        try {
            const data = await API.get('/admin/system/health');
            const h = data.health;
            grid.innerHTML = '';
            const items = [
                ['نسخه', h.version],
                ['محیط', h.env],
                ['نسخه Schema', h.schema_version],
                ['حالت AI', h.ai_mode],
                ['Embedding', h.embedding_provider],
                ['Crypto', h.crypto_available ? '✓ فعال' : '✗ غیرفعال'],
                ['حجم DB', h.db_size_bytes ? formatFileSize(h.db_size_bytes) : '—'],
            ];
            items.forEach(([k, v]) => {
                grid.appendChild(el('div', { class: 'kpi-card' }, [
                    el('div', { class: 'kpi-label', text: k }),
                    el('div', { class: 'kpi-value', text: String(v) }),
                ]));
            });
            const counts = h.counts || {};
            Object.entries(counts).forEach(([k, v]) => {
                grid.appendChild(el('div', { class: 'kpi-card' }, [
                    el('div', { class: 'kpi-label', text: k }),
                    el('div', { class: 'kpi-value', text: formatNumber(v) }),
                ]));
            });
        } catch (err) { Toast.error(err.message); }
    },

    async activateEntity(type, id, label, onSuccess) {
        const url = AdminPanel._lifecycleUrl(type, id, 'activate');
        try {
            await API.post(url);
            Toast.success(`${label} فعال شد.`);
            onSuccess && onSuccess();
        } catch (err) { Toast.error(err.message); }
    },

    async deactivateEntity(type, id, label, onSuccess) {
        const url = AdminPanel._lifecycleUrl(type, id, 'deactivate');
        try {
            await API.post(url);
            Toast.success(`${label} غیرفعال شد.`);
            onSuccess && onSuccess();
        } catch (err) { Toast.error(err.message); }
    },

    async softDeleteEntity(type, id, label, onSuccess) {
        Modal.confirm(
            `${label} حذف (نرم) شود؟ می‌توانید بعداً بازیابی کنید.`,
            async () => {
                try {
                    await API.del(AdminPanel._entityUrl(type, id));
                    Toast.success(`${label} حذف شد.`);
                    onSuccess && onSuccess();
                } catch (err) { Toast.error(err.message); }
            }
        );
    },

    async restoreEntity(type, id, label, onSuccess) {
        const url = AdminPanel._lifecycleUrl(type, id, 'restore');
        try {
            await API.post(url);
            Toast.success(`${label} بازیابی شد.`);
            onSuccess && onSuccess();
        } catch (err) { Toast.error(err.message); }
    },

    confirmPermanentDelete(type, entity, onSuccess) {
        const name = entity.name || entity.title || entity.full_name || '';
        Modal.typedConfirm(name, async ({ confirm_name, cascade }) => {
            try {
                await API.post(
                    AdminPanel._lifecycleUrl(type, entity.id, 'permanent-delete'),
                    { confirm_name, cascade }
                );
                Toast.success('حذف دائمی انجام شد.');
                onSuccess && onSuccess();
            } catch (err) { Toast.error(err.message); }
        }, {
            entityLabel: name,
            allowCascade: true,
            title: 'تأیید حذف دائمی',
        });
    },

    _entityUrl(type, id) {
        const map = {
            school: '/admin/schools',
            manager: '/admin/managers',
            class: '/admin/classes',
            student: '/admin/students',
            ai_profile: '/admin/ai-profiles',
            rag_collection: '/admin/rag/collections',
            rag_document: '/admin/rag/documents',
            video: '/admin/videos',
            subscription: '/admin/subscriptions',
            video_collection: '/admin/video-collections',
        };
        return (map[type] || '/admin/' + type) + '/' + id;
    },

    _lifecycleUrl(type, id, op) {
        return AdminPanel._entityUrl(type, id) + '/' + op;
    },

    async loadGapGPTModels(force = false) {
        if (!force && State.gapgptModels !== null) {
            return State.gapgptModels;
        }

        // ✅ PATCH: Shared Promise برای loadGapGPTModels
        // جلوگیری از spin-wait و برگرداندن مقدار ناقص
        if (State.gapgptModelsPromise) {
            try { await State.gapgptModelsPromise; } catch (e) {}
            return State.gapgptModels || [];
        }

        State.gapgptModelsPromise = (async () => {
            try {
                const data = await API.get('/admin/ai-profiles/gapgpt-models');
                State.gapgptModels = (data && data.models) ? data.models : [];
            } catch (err) {
                console.warn('loadGapGPTModels failed:', err);
                State.gapgptModels = [];
            } finally {
                State.gapgptModelsPromise = null;
                State.gapgptModelsLoading = false;
            }
        })();

        State.gapgptModelsLoading = true;
        await State.gapgptModelsPromise;
        return State.gapgptModels || [];
    },
    populateGapGPTModels(selectedApiId = null) {
        const sel = $('#ai-model');
        const hint = $('#ai-model-hint');
        if (!sel) return;

        sel.innerHTML = '';

        const models = State.gapgptModels || [];
        if (!models.length) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = '— هیچ مدلی در دسترس نیست —';
            sel.appendChild(opt);
            sel.disabled = true;
            if (hint) {
                hint.textContent = 'برای فعال‌سازی مدل‌ها، ابتدا از دکمه «🔄 بروزرسانی مدل‌ها» استفاده کنید.';
            }
            return;
        }

        sel.disabled = false;

        models.forEach(m => {
            const opt = document.createElement('option');
            opt.value = m.api_id;
            opt.textContent = m.label || m.api_id;
            if (selectedApiId && m.api_id === selectedApiId) {
                opt.selected = true;
            }
            sel.appendChild(opt);
        });

        if (selectedApiId && !models.some(m => m.api_id === selectedApiId)) {
            const legacyOpt = document.createElement('option');
            legacyOpt.value = selectedApiId;
            legacyOpt.textContent = `${selectedApiId} (خارج از کاتالوگ)`;
            legacyOpt.selected = true;
            sel.insertBefore(legacyOpt, sel.firstChild);
            if (hint) hint.textContent = 'مدل فعلی در کاتالوگ مرکزی یافت نشد. می‌توانید مدل جدید انتخاب کنید.';
        } else if (hint) {
            hint.textContent = '';
        }
    },

    async syncGapGPTModels() {
        Loading.show('در حال بروزرسانی مدل‌ها از GapGPT...');
        try {
            const res = await API.post('/admin/ai-profiles/sync-models', {});
            const updated = (res && typeof res.updated === 'number') ? res.updated : 0;
            const addedCount = (res && typeof res.added === 'number') ? res.added : 0;
            Toast.success(res && res.message ? res.message : `به‌روزرسانی: ${updated} بازبینی، ${addedCount} جدید.`);
            State.gapgptModels = null;
            await AdminPanel.loadGapGPTModels(true);
        } catch (err) {
            Toast.error(err.message || 'بروزرسانی مدل‌ها انجام نشد.');
        } finally {
            Loading.hide();
        }
    },

    initForms() {
        const schoolForm = $('#form-school');
        if (schoolForm) {
            if (schoolForm._submitHandler) {
                try { schoolForm.removeEventListener('submit', schoolForm._submitHandler); } catch (e) {}
            }
            schoolForm._submitHandler = async (e) => {
                e.preventDefault();
                const id = $('#school-id').value;
                const data = {
                    name: $('#school-name').value.trim(),
                    code: $('#school-code').value.trim(),
                    description: $('#school-description').value.trim() || null,
                    phone: $('#school-phone').value.trim() || null,
                    email: $('#school-email').value.trim() || null,
                    address: $('#school-address').value.trim() || null,
                    school_type: $('#school-type').value,
                    gender: $('#school-gender').value,
                };
                if (!data.name || !data.code) { Toast.warning('نام و کد الزامی است.'); return; }
                try {
                    if (id) {
                        delete data.code;
                        await API.put('/admin/schools/' + id, data);
                        Toast.success('ذخیره شد.');
                    } else {
                        await API.post('/admin/schools', data);
                        Toast.success('مدرسه ایجاد شد.');
                    }
                    Modal.close('modal-school');
                    schoolForm.reset();
                    $('#school-id').value = '';
                    AdminPanel.loadSchools();
                } catch (err) { Toast.error(err.message); }
            };
            schoolForm.addEventListener('submit', schoolForm._submitHandler);
        }

        const managerForm = $('#form-manager');
        if (managerForm) {
            if (managerForm._submitHandler) {
                try { managerForm.removeEventListener('submit', managerForm._submitHandler); } catch (e) {}
            }
            managerForm._submitHandler = async (e) => {
                e.preventDefault();
                const id = $('#manager-id').value;
                const data = {
                    school_id: $('#manager-school').value,
                    full_name: $('#manager-fullname').value.trim(),
                };
                if (!id) {
                    data.username = $('#manager-username').value.trim();
                    data.password = $('#manager-password').value;
                }
                if (!data.school_id && !id) { Toast.warning('مدرسه را انتخاب کنید.'); return; }
                if (!data.full_name) { Toast.warning('نام الزامی است.'); return; }
                if (!id && !data.username) { Toast.warning('نام کاربری الزامی است.'); return; }
                if (!id && !data.password) { Toast.warning('رمز عبور الزامی است.'); return; }
                try {
                    if (id) {
                        delete data.school_id;
                        await API.put('/admin/managers/' + id, data);
                        Toast.success('ذخیره شد.');
                    } else {
                        await API.post('/admin/managers', data);
                        Toast.success('مدیر ایجاد شد.');
                    }
                    Modal.close('modal-manager');
                    managerForm.reset();
                    $('#manager-id').value = '';
                    AdminPanel.loadManagers();
                } catch (err) { Toast.error(err.message); }
            };
            managerForm.addEventListener('submit', managerForm._submitHandler);
        }

        const classForm = $('#form-class');
        if (classForm) {
            if (classForm._submitHandler) {
                try { classForm.removeEventListener('submit', classForm._submitHandler); } catch (e) {}
            }
            classForm._submitHandler = async (e) => {
                e.preventDefault();
                const id = $('#class-id').value;
                const data = {
                    school_id: $('#class-school').value,
                    name: $('#class-name').value.trim(),
                    grade: $('#class-grade').value.trim() || null,
                    academic_year: $('#class-year').value.trim() || null,
                    description: $('#class-description').value.trim() || null,
                };
                if (!id && !data.school_id) { Toast.warning('مدرسه را انتخاب کنید.'); return; }
                if (!data.name) { Toast.warning('نام کلاس الزامی است.'); return; }
                try {
                    if (id) {
                        delete data.school_id;
                        await API.put('/admin/classes/' + id, data);

                        const aiIds = Array.from(State.classAIModalSelection);
                        try {
                            await API.post(`/admin/classes/${id}/ai-profiles/bulk`, {
                                ai_profile_ids: aiIds,
                                replace: true,
                force: true,  // ✅ ادمین دستش بازه
            });
                            if (State.classAIModalDefault) {
                                await API.post(`/admin/classes/${id}/ai-profiles/${State.classAIModalDefault}/set-default`);
                            }
                        } catch (err) {
                            console.warn('Class AI bulk save failed:', err);
                            Toast.warning('کلاس ذخیره شد ولی اتصالات AI با خطا مواجه شد.');
                        }

                        Toast.success('ذخیره شد.');
                    } else {
                        await API.post('/admin/classes', data);
                        Toast.success('کلاس ایجاد شد. برای افزودن AI، کلاس را ویرایش کنید.');
                    }
                    Modal.close('modal-class');
                    classForm.reset();
                    $('#class-id').value = '';
                    State.classAIModalSelection = new Set();
                    State.classAIModalDefault = null;
                    State.classAIModalList = [];
                    AdminPanel.loadClasses();
                } catch (err) { Toast.error(err.message); }
            };
            classForm.addEventListener('submit', classForm._submitHandler);
        }

        const studentForm = $('#form-student');
        if (studentForm) {
            if (studentForm._submitHandler) {
                try { studentForm.removeEventListener('submit', studentForm._submitHandler); } catch (e) {}
            }
            studentForm._submitHandler = async (e) => {
                e.preventDefault();
                const id = $('#student-id').value;
                const data = {
                    school_id: $('#student-school').value,
                    class_id: $('#student-class').value || null,
                    full_name: $('#student-fullname').value.trim(),
                    student_code: $('#student-code').value.trim() || null,
                    grade: $('#student-grade').value.trim() || null,
                };
                if (!id) {
                    data.username = $('#student-username').value.trim();
                    data.password = $('#student-password').value;
                }
                if (!id && !data.school_id) { Toast.warning('مدرسه را انتخاب کنید.'); return; }
                if (!data.full_name) { Toast.warning('نام الزامی است.'); return; }
                try {
                    if (id) {
                        delete data.school_id;
                        delete data.username;
                        delete data.password;
                        await API.put('/admin/students/' + id, data);
                        Toast.success('ذخیره شد.');
                    } else {
                        await API.post('/admin/students', data);
                        Toast.success('دانش‌آموز ایجاد شد.');
                    }
                    Modal.close('modal-student');
                    studentForm.reset();
                    $('#student-id').value = '';
                    AdminPanel.loadStudents();
                } catch (err) { Toast.error(err.message); }
            };
            studentForm.addEventListener('submit', studentForm._submitHandler);
        }

        const aiForm = $('#form-ai');
        if (aiForm) {
            const providerSel = $('#ai-provider');
            if (providerSel) {
                providerSel.addEventListener('change', async () => {
                    const v = providerSel.value;
                    if (v === 'gapgpt') {
                        await AdminPanel.loadGapGPTModels();
                        AdminPanel.populateGapGPTModels();
                    }
                });
            }

            const targetSchoolTypeSel = $('#ai-target-school-type');
            const targetGradeSel = $('#ai-target-grade');
            if (targetSchoolTypeSel && targetGradeSel) {
                targetSchoolTypeSel.addEventListener('change', () => {
                    const level = targetSchoolTypeSel.value;
                    populateGradeSelect(targetGradeSel, level, '');
                });
            }

            const toggleKeyBtn = $('#ai-api-key-toggle');
            if (toggleKeyBtn) {
                toggleKeyBtn.addEventListener('click', () => {
                    const input = $('#ai-api-key');
                    const s = toggleKeyBtn.querySelector('.password-icon-show');
                    const h = toggleKeyBtn.querySelector('.password-icon-hide');
                    if (input.type === 'password') {
                        input.type = 'text';
                        if (s) s.hidden = true; if (h) h.hidden = false;
                    } else {
                        input.type = 'password';
                        if (s) s.hidden = false; if (h) h.hidden = true;
                    }
                });
            }

            const testBtn = $('#ai-test-connection-btn');
            if (testBtn) {
                testBtn.addEventListener('click', () => AdminPanel.testConnectionFromModal());
            }

            const clearBtn = $('#ai-clear-api-key-btn');
            if (clearBtn) {
                clearBtn.addEventListener('click', () => {
                    Modal.confirm('کلید API پاک شود؟', () => {
                        $('#ai-api-key').value = '__CLEAR__';
                        Toast.info('کلید پاک خواهد شد. برای تأیید، ذخیره کنید.');
                    });
                });
            }

            if (aiForm._submitHandler) {
                try { aiForm.removeEventListener('submit', aiForm._submitHandler); } catch (e) {}
            }
            aiForm._submitHandler = async (e) => {
                e.preventDefault();
                const id = $('#ai-id').value;
                const provider = $('#ai-provider').value;

                const data = {
                    name: $('#ai-name').value.trim(),
                    description: $('#ai-description').value.trim() || null,
                    model_provider: provider,
                    model_name: $('#ai-model').value,
                    system_prompt: $('#ai-prompt').value.trim() || null,
                    temperature: parseFloat($('#ai-temperature').value) || 0.7,
                    max_output_tokens: parseInt($('#ai-max-tokens').value, 10) || 1000,
                };

                const tst = $('#ai-target-school-type') ? $('#ai-target-school-type').value : '';
                const tg = $('#ai-target-gender') ? $('#ai-target-gender').value : '';
                const tgradeRaw = $('#ai-target-grade') ? $('#ai-target-grade').value : '';

                if (!tst) { Toast.warning('مقطع هدف AI الزامی است.'); return; }
                if (!tg)  { Toast.warning('جنسیت هدف AI الزامی است.'); return; }

                data.target_school_type = tst;
                data.target_gender = tg;
                data.target_grade = (tgradeRaw !== '' ? parseInt(tgradeRaw, 10) : null);

                const apiKeyVal = $('#ai-api-key').value.trim();
                if (apiKeyVal) {
                    data.api_key = apiKeyVal;
                }

                if (!data.name) { Toast.warning('نام الزامی است.'); return; }
                if (!data.model_name) { Toast.warning('یک Model انتخاب کنید.'); return; }

                try {
                    let result;
                    if (id) {
                        result = await API.put('/admin/ai-profiles/' + id, data);
                        Toast.success('ذخیره شد.');
                    } else {
                        data.rag_collection_ids = Array.from(State.aiModalRagSelection);
                        result = await API.post('/admin/ai-profiles', data);
                        Toast.success('پروفایل ایجاد شد.');
                    }

                    if (id && State.aiModalRagList.length > 0) {
                        const ids = Array.from(State.aiModalRagSelection);
                        try {
                            await API.post(`/admin/ai-profiles/${id}/rag/bulk`, {
                                collection_ids: ids,
                                replace: true,
                            });
                        } catch (err) {
                            console.warn('RAG bulk link failed:', err);
                            Toast.warning('پروفایل ذخیره شد ولی اتصال RAG با خطا مواجه شد.');
                        }
                    }

                    Modal.close('modal-ai');
                    aiForm.reset();
                    $('#ai-id').value = '';
                    State.aiModalRagSelection = new Set();
                    AdminPanel.loadAI();
                } catch (err) { Toast.error(err.message); }
            };
            aiForm.addEventListener('submit', aiForm._submitHandler);
        }

        const ragForm = $('#form-rag');
        if (ragForm) {
            if (ragForm._submitHandler) {
                try { ragForm.removeEventListener('submit', ragForm._submitHandler); } catch (e) {}
            }
            ragForm._submitHandler = async (e) => {
                e.preventDefault();
                const data = {
                    name: $('#rag-name').value.trim(),
                    description: $('#rag-description').value.trim() || null,
                    type: $('#rag-type').value,
                };
                try {
                    await API.post('/admin/rag/collections', data);
                    Toast.success('مجموعه ایجاد شد.');
                    Modal.close('modal-rag');
                    ragForm.reset();
                    AdminPanel.loadRAG();
                } catch (err) { Toast.error(err.message); }
            };
            ragForm.addEventListener('submit', ragForm._submitHandler);
        }

        const ragUploadForm = $('#form-rag-upload');
        if (ragUploadForm) {
            if (ragUploadForm._submitHandler) {
                try { ragUploadForm.removeEventListener('submit', ragUploadForm._submitHandler); } catch (e) {}
            }
            ragUploadForm._submitHandler = async (e) => {
                e.preventDefault();
                const collectionId = $('#rag-upload-collection').value;
                const fileInput = $('#rag-upload-file');
                const title = $('#rag-upload-title').value.trim();
                if (!collectionId) { Toast.warning('مجموعه را انتخاب کنید.'); return; }
                if (!fileInput.files.length) { Toast.warning('فایل انتخاب کنید.'); return; }
                const fd = new FormData();
                fd.append('file', fileInput.files[0]);
                if (title) fd.append('title', title);
                try {
                    Loading.show('در حال آپلود و پردازش...');
                    await API.upload(`/admin/rag/collections/${collectionId}/documents/upload`, fd);
                    Toast.success('سند آپلود شد و در حال پردازش است.');
                    Modal.close('modal-rag-upload');
                    ragUploadForm.reset();
                    AdminPanel.loadRAG();
                } catch (err) { Toast.error(err.message); }
                finally { Loading.hide(); }
            };
            ragUploadForm.addEventListener('submit', ragUploadForm._submitHandler);
        }

        const videoUploadForm = $('#form-video-upload');
        if (videoUploadForm) {
            const vUploadLevelSel = $('#video-upload-target-school-type');
            const vUploadGradeSel = $('#video-upload-grade');
            if (vUploadLevelSel && vUploadGradeSel) {
                vUploadLevelSel.addEventListener('change', () => {
                    const level = vUploadLevelSel.value;
                    populateGradeSelect(vUploadGradeSel, level, '');
                });
            }

            if (videoUploadForm._submitHandler) {
                try { videoUploadForm.removeEventListener('submit', videoUploadForm._submitHandler); } catch (e) {}
            }
            videoUploadForm._submitHandler = async (e) => {
                e.preventDefault();
                const fileInput = $('#video-upload-file');
                if (!fileInput.files.length) { Toast.warning('فایل ویدیو انتخاب کنید.'); return; }
                const fd = new FormData();
                fd.append('file', fileInput.files[0]);
                fd.append('title', $('#video-upload-title').value.trim());
                if ($('#video-upload-description').value.trim()) fd.append('description', $('#video-upload-description').value.trim());
                if ($('#video-upload-duration').value) fd.append('duration', $('#video-upload-duration').value);
                if ($('#video-upload-subject').value.trim()) fd.append('subject', $('#video-upload-subject').value.trim());
                if ($('#video-upload-chapter').value.trim()) fd.append('chapter', $('#video-upload-chapter').value.trim());
                if ($('#video-upload-category').value.trim()) fd.append('category', $('#video-upload-category').value.trim());
                const level = $('#video-upload-target-school-type').value;
                fd.append('target_school_type', level);
                fd.append('target_gender', $('#video-upload-target-gender').value);
                const gradeVal = $('#video-upload-grade').value;
                if (gradeVal) fd.append('grade', gradeVal);
                try {
                    Loading.show('در حال آپلود ویدیو...');
                    await API.upload('/admin/videos/upload', fd);
                    Toast.success('ویدیو آپلود شد.');
                    Modal.close('modal-video-upload');
                    videoUploadForm.reset();
                    AdminPanel.loadVideos();
                } catch (err) { Toast.error(err.message); }
                finally { Loading.hide(); }
            };
            videoUploadForm.addEventListener('submit', videoUploadForm._submitHandler);
        }

        const videoEditForm = $('#form-video-edit');
        if (videoEditForm) {
            const vEditLevelSel = $('#video-edit-target-school-type');
            const vEditGradeSel = $('#video-edit-grade');
            if (vEditLevelSel && vEditGradeSel) {
                vEditLevelSel.addEventListener('change', () => {
                    const level = vEditLevelSel.value;
                    populateGradeSelect(vEditGradeSel, level, '');
                });
            }

            if (videoEditForm._submitHandler) {
                try { videoEditForm.removeEventListener('submit', videoEditForm._submitHandler); } catch (e) {}
            }
            videoEditForm._submitHandler = async (e) => {
                e.preventDefault();
                const id = $('#video-edit-id').value;
                const data = {
                    title: $('#video-edit-title').value.trim(),
                    description: $('#video-edit-description').value.trim() || null,
                    subject: $('#video-edit-subject').value.trim() || null,
                    chapter: $('#video-edit-chapter').value.trim() || null,
                    category: $('#video-edit-category').value.trim() || null,
                    sort_order: parseInt($('#video-edit-order').value, 10) || 0,
                    target_school_type: $('#video-edit-target-school-type').value,
                    target_gender: $('#video-edit-target-gender').value,
                };
                const gradeVal = $('#video-edit-grade').value;
                data.grade = gradeVal ? gradeVal : '';
                try {
                    await API.put('/admin/videos/' + id, data);
                    Toast.success('ذخیره شد.');
                    Modal.close('modal-video-edit');
                    AdminPanel.loadVideos();
                } catch (err) { Toast.error(err.message); }
            };
            videoEditForm.addEventListener('submit', videoEditForm._submitHandler);
        }

        const videoCollectionForm = $('#form-video-collection');
        if (videoCollectionForm) {
            if (videoCollectionForm._submitHandler) {
                try { videoCollectionForm.removeEventListener('submit', videoCollectionForm._submitHandler); } catch (e) {}
            }
            videoCollectionForm._submitHandler = async (e) => {
                e.preventDefault();
                const id = $('#video-collection-id').value;
                const data = {
                    title: $('#video-collection-title').value.trim(),
                    description: $('#video-collection-description').value.trim() || null,
                    category: $('#video-collection-category').value.trim() || null,
                    level: $('#video-collection-level').value || null,
                    sort_order: parseInt($('#video-collection-sort-order').value, 10) || 0,
                    is_active: $('#video-collection-is-active').checked,
                };

                if (!data.title) { Toast.warning('عنوان الزامی است.'); return; }

                try {
                    if (id) {
                        await API.put('/admin/video-collections/' + id, data);
                        Toast.success('ذخیره شد.');
                    } else {
                        const res = await API.post('/admin/video-collections', data);
                        Toast.success('مجموعه ایجاد شد.');
                        if (res && res.collection && res.collection.id) {
                            $('#video-collection-id').value = res.collection.id;
                            const coverGroup = $('#video-collection-cover-group');
                            if (coverGroup) coverGroup.hidden = false;
                        }
                    }
                    AdminPanel.loadVideoCollections();
                    Modal.close('modal-video-collection');
                } catch (err) { Toast.error(err.message); }
            };
            videoCollectionForm.addEventListener('submit', videoCollectionForm._submitHandler);
        }

        const coverUploadBtn = $('#video-collection-cover-upload-btn');
        if (coverUploadBtn) {
            if (coverUploadBtn._clickHandler) {
                try { coverUploadBtn.removeEventListener('click', coverUploadBtn._clickHandler); } catch (e) {}
            }
            coverUploadBtn._clickHandler = () => AdminPanel.uploadCollectionCover();
            coverUploadBtn.addEventListener('click', coverUploadBtn._clickHandler);
        }

        const addVideoBtn = $('#collection-videos-add-btn');
        if (addVideoBtn) {
            if (addVideoBtn._clickHandler) {
                try { addVideoBtn.removeEventListener('click', addVideoBtn._clickHandler); } catch (e) {}
            }
            addVideoBtn._clickHandler = () => AdminPanel.addVideoToCollection();
            addVideoBtn.addEventListener('click', addVideoBtn._clickHandler);
        }

        const adminPwdForm = $('#form-admin-password');
        if (adminPwdForm) {
            if (adminPwdForm._submitHandler) {
                try { adminPwdForm.removeEventListener('submit', adminPwdForm._submitHandler); } catch (e) {}
            }
            adminPwdForm._submitHandler = async (e) => {
                e.preventDefault();
                const cur = $('#admin-current-password').value;
                const nw = $('#admin-new-password').value;
                const cf = $('#admin-confirm-password').value;
                if (!cur || !nw || !cf) { Toast.warning('همه فیلدها الزامی است.'); return; }
                if (nw.length < 6) { Toast.warning('رمز جدید حداقل ۶ کاراکتر.'); return; }
                if (nw !== cf) { Toast.warning('تکرار رمز مطابقت ندارد.'); return; }
                try {
                    await Auth.changePassword(cur, nw);
                    Toast.success('رمز تغییر کرد. دوباره وارد شوید.');

                    const banner = document.getElementById('must-change-password-banner');
                    if (banner) banner.hidden = true;

                    Modal.close('modal-admin-password');
                    adminPwdForm.reset();
                    setTimeout(() => Auth.logout(), 1400);
                } catch (err) { Toast.error(err.message); }
            };
            adminPwdForm.addEventListener('submit', adminPwdForm._submitHandler);
        }

        const adminUsernameForm = $('#form-admin-username');
        if (adminUsernameForm) {
            if (adminUsernameForm._submitHandler) {
                try { adminUsernameForm.removeEventListener('submit', adminUsernameForm._submitHandler); } catch (e) {}
            }
            adminUsernameForm._submitHandler = async (e) => {
                e.preventDefault();
                const newUsername = $('#admin-new-username').value.trim();
                const password = $('#admin-username-password').value;

                if (!newUsername || newUsername.length < 3) {
                    Toast.warning('نام کاربری حداقل ۳ کاراکتر.');
                    return;
                }
                if (!/^[A-Za-z0-9_.\-]+$/.test(newUsername)) {
                    Toast.warning('نام کاربری فقط می‌تواند شامل حروف انگلیسی، اعداد، _، . و - باشد.');
                    return;
                }
                if (!password) {
                    Toast.warning('رمز عبور فعلی الزامی است.');
                    return;
                }

                try {
                    await Auth.changeUsername(newUsername, password);
                    Toast.success('نام کاربری تغییر کرد. لطفاً دوباره وارد شوید.');
                    Modal.close('modal-admin-username');
                    adminUsernameForm.reset();
                    setTimeout(() => Auth.logout(), 1500);
                } catch (err) {
                    Toast.error(err.message);
                }
            };
            adminUsernameForm.addEventListener('submit', adminUsernameForm._submitHandler);
        }

        const testAccountForm = document.getElementById('form-test-account');
        if (testAccountForm) {
            if (testAccountForm._submitHandler) {
                try { testAccountForm.removeEventListener('submit', testAccountForm._submitHandler); } catch (e) {}
            }
            testAccountForm._submitHandler = (e) => AdminPanel.submitTestAccount(e);
            testAccountForm.addEventListener('submit', testAccountForm._submitHandler);
        }

        const dbResetForm = $('#form-db-reset');
        if (dbResetForm) {
            if (dbResetForm._submitHandler) {
                try { dbResetForm.removeEventListener('submit', dbResetForm._submitHandler); } catch (e) {}
            }
            dbResetForm._submitHandler = async (e) => {
                e.preventDefault();
                const keyword = $('#db-reset-keyword').value.trim();
                const password = $('#db-reset-password').value;
                const username = $('#db-reset-username').value.trim();
                const dropFiles = $('#db-reset-drop-files').checked;
                const keepAudit = $('#db-reset-keep-audit').checked;

                if (keyword.toUpperCase() !== 'RESET-DANOVA') {
                    Toast.error('کلمه تأیید را دقیقاً RESET-DANOVA وارد کنید.');
                    return;
                }
                if (!password) { Toast.warning('رمز عبور را وارد کنید.'); return; }

                Modal.confirm(
                    'آیا مطمئن هستید؟ تمام داده‌ها (به جز ادمین فعلی) حذف خواهند شد. این عمل برگشت‌ناپذیر است.',
                    async () => {
                        try {
                            Loading.show('در حال ریست دیتابیس...');
                            const res = await API.post('/admin/database/reset', {
                                confirm_keyword: keyword,
                                confirm_password: password,
                                confirm_username: username || null,
                                drop_files: dropFiles,
                                keep_audit: keepAudit,
                            });
                            Toast.success(res.message || 'دیتابیس ریست شد.');
                            Modal.close('modal-db-reset');
                            dbResetForm.reset();
                            AdminPanel.switchSubview('dashboard');
                        } catch (err) { Toast.error(err.message); }
                        finally { Loading.hide(); }
                    },
                    { title: 'تأیید نهایی ریست', okText: 'ریست کن', danger: true }
                );
            };
            dbResetForm.addEventListener('submit', dbResetForm._submitHandler);
        }

        const timeSetupForm = $('#form-time-setup');
        if (timeSetupForm) {
            if (timeSetupForm._submitHandler) {
                try { timeSetupForm.removeEventListener('submit', timeSetupForm._submitHandler); } catch (e) {}
            }
            timeSetupForm._submitHandler = (e) => AdminPanel.submitTimeSetup(e);
            timeSetupForm.addEventListener('submit', timeSetupForm._submitHandler);
        }
    },

    async testConnectionFromModal() {
        const provider = $('#ai-provider').value;
        const apiKey = $('#ai-api-key').value.trim();
        const model = $('#ai-model').value;
        const statusEl = $('#ai-test-status');
        const testBtn = $('#ai-test-connection-btn');

        if (provider === 'local') {
            if (statusEl) {
                statusEl.textContent = '✓ Provider محلی نیازی به تست ندارد.';
                statusEl.style.color = 'var(--color-success)';
            }
            return;
        }

        const existingId = $('#ai-id').value;
        if (!apiKey && existingId) {
            if (testBtn) testBtn.disabled = true;
            if (statusEl) { statusEl.textContent = '⏳ در حال تست...'; statusEl.style.color = 'var(--color-text-muted)'; }
            try {
                const res = await API.post(`/admin/ai-profiles/${existingId}/test`);
                if (statusEl) {
                    statusEl.textContent = (res.ok ? '✓ ' : '✗ ') + (res.message || '');
                    statusEl.style.color = res.ok ? 'var(--color-success)' : 'var(--color-error)';
                }
            } catch (err) {
                if (statusEl) {
                    statusEl.textContent = '✗ ' + (err.message || 'خطا');
                    statusEl.style.color = 'var(--color-error)';
                }
            } finally {
                if (testBtn) testBtn.disabled = false;
            }
            return;
        }

        if (provider === 'gapgpt' && !apiKey) {
            if (testBtn) testBtn.disabled = true;
            if (statusEl) { statusEl.textContent = '⏳ در حال تست کلید سراسری GapGPT...'; statusEl.style.color = 'var(--color-text-muted)'; }
            try {
                const res = await API.post('/admin/ai-profiles/test-connection', {
                    provider: 'gapgpt',
                    api_key: '',
                    model_name: model || '',
                });
                if (statusEl) {
                    statusEl.textContent = (res.ok ? '✓ ' : '✗ ') + (res.message || '');
                    statusEl.style.color = res.ok ? 'var(--color-success)' : 'var(--color-error)';
                }
            } catch (err) {
                if (statusEl) {
                    statusEl.textContent = '✗ ' + (err.message || 'خطا');
                    statusEl.style.color = 'var(--color-error)';
                }
            } finally {
                if (testBtn) testBtn.disabled = false;
            }
            return;
        }

        if (!apiKey) {
            if (statusEl) {
                statusEl.textContent = '⚠ ابتدا کلید API را وارد کنید.';
                statusEl.style.color = 'var(--color-warning)';
            }
            return;
        }

        if (testBtn) testBtn.disabled = true;
        if (statusEl) { statusEl.textContent = '⏳ در حال تست...'; statusEl.style.color = 'var(--color-text-muted)'; }
        try {
            const res = await API.post('/admin/ai-profiles/test-connection', {
                provider,
                api_key: apiKey,
                model_name: model || '',
            });
            if (statusEl) {
                statusEl.textContent = (res.ok ? '✓ ' : '✗ ') + (res.message || '');
                statusEl.style.color = res.ok ? 'var(--color-success)' : 'var(--color-error)';
            }
        } catch (err) {
            if (statusEl) {
                statusEl.textContent = '✗ ' + (err.message || 'خطا در تست');
                statusEl.style.color = 'var(--color-error)';
            }
        } finally {
            if (testBtn) testBtn.disabled = false;
        }
    },

    async testExistingAI(profile) {
        try {
            Loading.show('در حال تست اتصال...');
            const res = await API.post(`/admin/ai-profiles/${profile.id}/test`);
            Loading.hide();
            if (res.ok) {
                Toast.success(res.message || 'اتصال موفق بود.');
            } else {
                Toast.error(res.message || 'اتصال ناموفق بود.');
            }
        } catch (err) {
            Loading.hide();
            Toast.error(err.message || 'خطا در تست');
        }
    },

    openSchoolModal(school = null) {
        setText('#modal-school-title', school ? 'ویرایش مدرسه' : 'افزودن مدرسه');
        $('#school-id').value = school ? school.id : '';
        $('#school-name').value = school ? school.name : '';
        $('#school-code').value = school ? school.code : '';
        $('#school-code').disabled = !!school;
        $('#school-description').value = school ? (school.description || '') : '';
        $('#school-type').value = school ? (school.school_type || 'elementary') : 'elementary';
        $('#school-gender').value = school ? (school.gender || 'boys') : 'boys';
        $('#school-phone').value = school ? (school.phone || '') : '';
        if ($('#school-email')) $('#school-email').value = school ? (school.email || '') : '';
        if ($('#school-address')) $('#school-address').value = school ? (school.address || '') : '';
        Modal.open('modal-school');
    },

    async openManagerModal(manager = null) {
        const isEdit = !!manager;
        setText('#modal-manager-title', isEdit ? 'ویرایش مدیر مدرسه' : 'افزودن مدیر مدرسه');
        $('#manager-id').value = isEdit ? manager.id : '';
        $('#manager-fullname').value = isEdit ? manager.full_name : '';

        $$('[data-manager-create-only]').forEach(n => { n.hidden = isEdit; });

        if (!isEdit) {
            $('#manager-username').value = '';
            $('#manager-password').value = '';
        }

        const sSel = $('#manager-school');
        sSel.innerHTML = '<option value="">در حال بارگذاری...</option>';
        sSel.disabled = isEdit;

        try {
            const data = await API.get('/admin/schools?page_size=200');
            sSel.innerHTML = '<option value="">انتخاب کنید...</option>';
            (data.schools || []).forEach(s => {
                const opt = el('option', { value: s.id, text: s.name });
                if (manager && manager.school_id === s.id) opt.selected = true;
                sSel.appendChild(opt);
            });
        } catch (e) {
            sSel.innerHTML = '<option value="">خطا در بارگذاری مدارس</option>';
        }

        Modal.open('modal-manager');
    },

    async resetManagerPassword(manager) {
        Modal.prompt('تغییر رمز مدیر', `رمز جدید برای «${manager.full_name}»`, '', async (newPwd) => {
            if (newPwd.length < 6) { Toast.warning('رمز حداقل ۶ کاراکتر.'); return; }
            try {
                await API.post('/admin/managers/' + manager.id + '/reset-password', { new_password: newPwd });
                Toast.success('رمز تغییر کرد.');
            } catch (err) { Toast.error(err.message); }
        }, { password: true });
    },

    async openClassModal(cls = null) {
        setText('#modal-class-title', cls ? 'ویرایش کلاس' : 'افزودن کلاس');
        $('#class-id').value = cls ? cls.id : '';
        $('#class-name').value = cls ? cls.name : '';
        $('#class-grade').value = cls ? (cls.grade || '') : '';
        $('#class-year').value = cls ? (cls.academic_year || '') : '';
        if ($('#class-description')) $('#class-description').value = cls ? (cls.description || '') : '';

        const sel = $('#class-school');
        sel.innerHTML = '<option value="">در حال بارگذاری...</option>';
        sel.disabled = !!cls;
        try {
            const data = await API.get('/admin/schools?page_size=100');
            sel.innerHTML = '<option value="">انتخاب کنید...</option>';
            (data.schools || []).forEach(s => {
                const opt = el('option', { value: s.id, text: s.name });
                if (cls && cls.school_id === s.id) opt.selected = true;
                sel.appendChild(opt);
            });
        } catch (e) { sel.innerHTML = '<option value="">خطا</option>'; }

        State.classAIModalClassId = cls ? cls.id : null;
        State.classAIModalSelection = new Set();
        State.classAIModalDefault = null;
        State.classAIModalList = [];

        const aiGroup = $('#class-ai-group');
        if (aiGroup) aiGroup.hidden = !cls;

        if (cls) {
            await AdminPanel.loadClassAISelector(cls.id);
        }

        Modal.open('modal-class');
    },

    async loadClassAISelector(classId) {
        const container = $('#class-ai-selector');
        if (!container) return;
        container.innerHTML = '<div class="ai-selector-placeholder">در حال بارگذاری...</div>';

        try {
            const [aiData, linkData] = await Promise.all([
                API.get('/admin/ai-profiles?page_size=100&is_active=true'),
                API.get(`/admin/classes/${classId}/ai-profiles`),
            ]);

            const profiles = (aiData.ai_profiles || []).filter(p => !p.deleted_at && p.is_active);
            const links = linkData.links || [];
            const linkMap = {};
            links.forEach(l => { if (l.is_active) linkMap[l.ai_profile_id] = l; });

            State.classAIModalList = profiles;
            State.classAIModalSelection = new Set(Object.keys(linkMap));
            State.classAIModalDefault = null;
            links.forEach(l => {
                if (l.is_active && l.is_default) State.classAIModalDefault = l.ai_profile_id;
            });

            if (!profiles.length) {
                container.innerHTML = '<div class="ai-selector-placeholder">پروفایل AI فعالی وجود ندارد.</div>';
                return;
            }

            container.innerHTML = '';
            profiles.forEach(p => {
                const checked = State.classAIModalSelection.has(p.id);
                const checkbox = el('input', { type: 'checkbox', value: p.id });
                if (checked) checkbox.checked = true;

                const defaultRadio = el('input', {
                    type: 'radio',
                    name: 'class-ai-default',
                    value: p.id,
                });
                if (State.classAIModalDefault === p.id) defaultRadio.checked = true;

                checkbox.addEventListener('change', () => {
                    if (checkbox.checked) {
                        State.classAIModalSelection.add(p.id);
                    } else {
                        State.classAIModalSelection.delete(p.id);
                        if (State.classAIModalDefault === p.id) {
                            State.classAIModalDefault = null;
                            defaultRadio.checked = false;
                        }
                    }
                });
                defaultRadio.addEventListener('change', () => {
                    if (defaultRadio.checked) {
                        State.classAIModalDefault = p.id;
                        State.classAIModalSelection.add(p.id);
                        checkbox.checked = true;
                    }
                });

                const targetInfo = [];
                if (p.target_school_type) targetInfo.push(LABELS.schoolType[p.target_school_type] || p.target_school_type);
                if (p.target_grade) targetInfo.push(`پایه ${p.target_grade}`);
                if (p.target_gender) targetInfo.push(LABELS.aiGender[p.target_gender] || p.target_gender);
                const targetText = targetInfo.length ? targetInfo.join(' • ') : '';

                const label = el('label', {
                    class: 'rag-checkbox-item',
                    style: {
                        display: 'flex',
                        alignItems: 'center',
                        gap: '8px',
                        padding: '8px 12px',
                        borderRadius: '8px',
                        cursor: 'pointer',
                    },
                }, [
                    checkbox,
                    el('span', {}, [
                        el('span', { text: p.name }),
                        targetText ? el('span', {
                            style: {
                                display: 'block',
                                fontSize: '11px',
                                color: 'var(--color-text-muted)',
                                marginTop: '2px',
                            },
                            text: targetText,
                        }) : null,
                    ].filter(Boolean)),
                    el('span', {
                        style: {
                            marginRight: 'auto',
                            display: 'flex',
                            alignItems: 'center',
                            gap: '6px',
                            fontSize: '12px',
                            color: 'var(--color-text-muted)',
                        },
                    }, [
                        defaultRadio,
                        el('span', { text: 'پیش‌فرض' }),
                    ]),
                ]);

                label.addEventListener('mouseenter', () => { label.style.background = 'var(--color-surface-hover)'; });
                label.addEventListener('mouseleave', () => { label.style.background = ''; });

                container.appendChild(label);
            });
        } catch (err) {
            container.innerHTML = `<div class="ai-selector-placeholder">خطا: ${escapeHtml(err.message)}</div>`;
        }
    },

    async openStudentModal(student = null) {
        const isEdit = !!student;
        setText('#modal-student-title', isEdit ? 'ویرایش دانش‌آموز' : 'افزودن دانش‌آموز');
        $('#student-id').value = student ? student.id : '';
        $('#student-fullname').value = student ? student.full_name : '';
        $('#student-code').value = student ? (student.student_code || '') : '';
        if ($('#student-grade')) $('#student-grade').value = student ? (student.grade || '') : '';
        $$('[data-student-create-only]').forEach(n => { n.hidden = isEdit; });
        if (!isEdit) {
            $('#student-username').value = '';
            $('#student-password').value = '';
        }
        const sSel = $('#student-school');
        const cSel = $('#student-class');
        sSel.innerHTML = '<option value="">در حال بارگذاری...</option>';
        cSel.innerHTML = '<option value="">—</option>';
        sSel.disabled = isEdit;
        try {
            const data = await API.get('/admin/schools?page_size=100');
            sSel.innerHTML = '<option value="">انتخاب کنید...</option>';
            (data.schools || []).forEach(s => {
                const opt = el('option', { value: s.id, text: s.name });
                if (student && student.school_id === s.id) opt.selected = true;
                sSel.appendChild(opt);
            });
            if (student && student.school_id) {
                await AdminPanel.loadClassesForStudentForm(student.school_id, student.class_id);
            }
        } catch (e) { sSel.innerHTML = '<option value="">خطا</option>'; }
        sSel.onchange = () => AdminPanel.loadClassesForStudentForm(sSel.value);
        Modal.open('modal-student');
    },

    async loadClassesForStudentForm(schoolId, selectedClassId) {
        const cSel = $('#student-class');
        cSel.innerHTML = '<option value="">در حال بارگذاری...</option>';
        if (!schoolId) { cSel.innerHTML = '<option value="">—</option>'; return; }
        try {
            const data = await API.get('/admin/classes?school_id=' + schoolId + '&page_size=100');
            cSel.innerHTML = '<option value="">انتخاب کنید...</option>';
            (data.classes || []).forEach(c => {
                const opt = el('option', { value: c.id, text: c.name });
                if (selectedClassId === c.id) opt.selected = true;
                cSel.appendChild(opt);
            });
        } catch (e) { cSel.innerHTML = '<option value="">خطا</option>'; }
    },

    async openAIModal(profile = null) {
        const isEdit = !!profile;
        setText('#modal-ai-title', isEdit ? 'ویرایش AI Profile' : 'ایجاد AI Profile');
        $('#ai-id').value = profile ? profile.id : '';
        $('#ai-name').value = profile ? profile.name : '';
        $('#ai-description').value = profile ? (profile.description || '') : '';
        $('#ai-prompt').value = profile ? (profile.system_prompt || '') : '';
        $('#ai-temperature').value = profile ? (profile.temperature != null ? profile.temperature : 0.7) : 0.7;
        $('#ai-max-tokens').value = profile ? (profile.max_output_tokens != null ? profile.max_output_tokens : 1000) : 1000;

        const tstSel = $('#ai-target-school-type');
        const tgSel = $('#ai-target-gender');
        const tgradeSel = $('#ai-target-grade');

        const profileLevel = profile ? (profile.target_school_type || '') : '';
        const profileGender = profile ? (profile.target_gender || '') : '';
        const profileGrade = (profile && profile.target_grade != null) ? profile.target_grade : '';

        if (tstSel) tstSel.value = profileLevel;
        if (tgSel) tgSel.value = profileGender;

        if (tgradeSel) {
            populateGradeSelect(tgradeSel, profileLevel, profileGrade);
        }

        const apiKeyInput = $('#ai-api-key');
        if (apiKeyInput) {
            apiKeyInput.value = '';
            apiKeyInput.type = 'password';
            apiKeyInput.placeholder = isEdit
                ? 'برای تغییر کلید، مقدار جدید وارد کنید (خالی = بدون تغییر)'
                : 'کلید اختصاصی (اختیاری — به‌طور پیش‌فرض از کلید سراسری سرور استفاده می‌شود)';
        }

        const toggleBtn = $('#ai-api-key-toggle');
        if (toggleBtn) {
            const s = toggleBtn.querySelector('.password-icon-show');
            const h = toggleBtn.querySelector('.password-icon-hide');
            if (s) s.hidden = false;
            if (h) h.hidden = true;
        }

        const providerSel = $('#ai-provider');
        const modelSel = $('#ai-model');

        const isLegacyProvider = !!(profile && profile.model_provider && profile.model_provider !== 'gapgpt');

        if (!profile) {
            if (providerSel) {
                providerSel.innerHTML = '';
                const opt = document.createElement('option');
                opt.value = 'gapgpt';
                opt.textContent = 'GapGPT';
                providerSel.appendChild(opt);
                providerSel.value = 'gapgpt';
                providerSel.disabled = false;
            }
            await AdminPanel.loadGapGPTModels();
            AdminPanel.populateGapGPTModels();
        } else if (isLegacyProvider) {
            if (providerSel) {
                providerSel.innerHTML = '';
                const opt = document.createElement('option');
                opt.value = profile.model_provider;
                opt.textContent = profile.model_provider + ' (Legacy)';
                providerSel.appendChild(opt);
                providerSel.value = profile.model_provider;
                providerSel.disabled = true;
            }
            if (modelSel) {
                modelSel.innerHTML = '';
                const opt = document.createElement('option');
                opt.value = profile.model_name || '';
                opt.textContent = profile.model_name || '—';
                opt.selected = true;
                modelSel.appendChild(opt);
                modelSel.disabled = true;
            }
            const hint = $('#ai-model-hint');
            if (hint) {
                hint.textContent = 'پروفایل Legacy — برای مهاجرت به GapGPT، ابتدا از پنل Provider را تغییر دهید (در نسخه فعلی فقط خواندنی است).';
            }
        } else {
            if (providerSel) {
                providerSel.innerHTML = '';
                const opt = document.createElement('option');
                opt.value = 'gapgpt';
                opt.textContent = 'GapGPT';
                providerSel.appendChild(opt);
                providerSel.value = 'gapgpt';
                providerSel.disabled = false;
            }
            await AdminPanel.loadGapGPTModels();
            AdminPanel.populateGapGPTModels(profile.model_name);
        }

        const apiKeyGroup = $('#ai-api-key-group');
        if (apiKeyGroup) {
            apiKeyGroup.hidden = (providerSel && providerSel.value === 'local');
        }

        const hintWrap = $('#ai-api-key-hint-wrap');
        const hintEl = $('#ai-api-key-hint');
        const clearBtn = $('#ai-clear-api-key-btn');
        if (isEdit && profile.has_api_key) {
            if (hintWrap) hintWrap.hidden = false;
            if (hintEl) hintEl.textContent = profile.api_key_hint || '••••';
            if (clearBtn) clearBtn.hidden = false;
        } else {
            if (hintWrap) hintWrap.hidden = true;
            if (clearBtn) clearBtn.hidden = true;
        }

        const statusEl = $('#ai-test-status');
        if (statusEl) { statusEl.textContent = ''; }

        State.aiModalRagSelection = new Set();

        await AdminPanel.loadRAGsForAIModal(isEdit ? profile.id : null);

        Modal.open('modal-ai');
    },

    async loadRAGsForAIModal(aiProfileId = null) {
        const container = $('#ai-rag-selector');
        if (!container) return;
        container.innerHTML = '<div class="ai-selector-placeholder">در حال بارگذاری...</div>';

        try {
            const data = await API.get('/admin/rag/collections?page_size=100&is_active=true');
            const collections = data.collections || [];
            State.aiModalRagList = collections;

            let linkedIds = new Set();
            if (aiProfileId) {
                try {
                    const detail = await API.get('/admin/ai-profiles/' + aiProfileId);
                    const links = detail.ai_profile.rag_links || [];
                    links.forEach(l => {
                        if (l.is_active) linkedIds.add(l.collection_id);
                    });
                } catch (err) {
                    console.warn('Could not load existing RAG links:', err);
                }
            }
            State.aiModalRagSelection = new Set(linkedIds);

            if (!collections.length) {
                container.innerHTML = '<div class="ai-selector-placeholder">مجموعه RAG فعالی وجود ندارد.</div>';
                return;
            }

            container.innerHTML = '';
            collections.forEach(c => {
                const checked = State.aiModalRagSelection.has(c.id);
                const checkbox = el('input', { type: 'checkbox', value: c.id });
                if (checked) checkbox.checked = true;

                checkbox.addEventListener('change', () => {
                    if (checkbox.checked) {
                        State.aiModalRagSelection.add(c.id);
                    } else {
                        State.aiModalRagSelection.delete(c.id);
                    }
                });

                const label = el('label', {
                    class: 'rag-checkbox-item',
                    style: {
                        display: 'flex',
                        alignItems: 'center',
                        gap: '8px',
                        padding: '8px 12px',
                        borderRadius: '8px',
                        cursor: 'pointer',
                        transition: 'background 150ms ease',
                    },
                }, [
                    checkbox,
                    el('span', { text: c.name }),
                    el('span', {
                        class: 'badge badge-primary',
                        style: { marginRight: 'auto', fontSize: '11px' },
                        text: `${formatNumber(c.document_count || 0)} سند`,
                    }),
                ]);

                label.addEventListener('mouseenter', () => { label.style.background = 'var(--color-surface-hover)'; });
                label.addEventListener('mouseleave', () => { label.style.background = ''; });

                container.appendChild(label);
            });
        } catch (err) {
            container.innerHTML = `<div class="ai-selector-placeholder">خطا: ${escapeHtml(err.message)}</div>`;
        }
    },

    openRAGModal() {
        const form = $('#form-rag');
        if (form) form.reset();
        Modal.open('modal-rag');
    },

    async openRAGUploadModal(collectionId = null) {
        const sel = $('#rag-upload-collection');
        sel.innerHTML = '<option value="">در حال بارگذاری...</option>';
        try {
            const data = await API.get('/admin/rag/collections?page_size=100&is_active=true');
            sel.innerHTML = '<option value="">انتخاب کنید...</option>';
            (data.collections || []).forEach(c => {
                const opt = el('option', { value: c.id, text: c.name });
                if (collectionId === c.id) opt.selected = true;
                sel.appendChild(opt);
            });
        } catch (e) { sel.innerHTML = '<option value="">خطا</option>'; }
        const form = $('#form-rag-upload');
        if (form) form.reset();
        Modal.open('modal-rag-upload');
    },

    openRAGDetailModal(collection) {
        const container = $('#rag-detail-body');
        if (!container) return;
        container.innerHTML = '<div class="empty-title">در حال بارگذاری...</div>';
        Modal.open('modal-rag-detail');

        API.get('/admin/rag/collections/' + collection.id).then(data => {
            const col = data.collection;
            container.innerHTML = '';
            container.appendChild(el('h3', { text: col.name }));
            if (col.description) container.appendChild(el('p', { class: 'text-muted', text: col.description }));
            const stats = el('div', { class: 'info-row' }, [
                el('span', { class: 'info-label', text: 'تعداد اسناد:' }),
                el('span', { class: 'info-value', text: formatNumber((col.documents || []).length) }),
            ]);
            container.appendChild(stats);

            if ((col.documents || []).length) {
                const list = el('div', { class: 'rag-docs-list' });
                col.documents.forEach(d => {
                    const item = el('div', { class: 'rag-doc-item' }, [
                        el('div', { class: 'rag-doc-title', text: d.title }),
                        el('div', { class: 'rag-doc-meta' }, [
                            el('span', { text: d.file_name || '' }),
                            el('span', { text: ' • ' }),
                            el('span', { text: formatFileSize(d.file_size) }),
                            el('span', { text: ' • ' }),
                            el('span', {
                                class: 'badge ' + (d.status === 'ready' ? 'badge-success'
                                    : d.status === 'failed' ? 'badge-error'
                                    : d.status === 'processing' ? 'badge-warning'
                                    : 'badge-primary'),
                                text: d.status,
                            }),
                            el('span', { text: ' • ' }),
                            el('span', { text: formatNumber(d.chunk_count) + ' chunk' }),
                        ]),
                    ]);
                    const actions = el('div', { class: 'rag-doc-actions' });
                    actions.appendChild(el('button', { class: 'btn btn-sm btn-ghost', text: 'پردازش مجدد',
                        onClick: async () => {
                            try {
                                await API.post('/admin/rag/documents/' + d.id + '/reindex');
                                Toast.success('پردازش مجدد آغاز شد.');
                            } catch (err) { Toast.error(err.message); }
                        },
                    }));
                    actions.appendChild(el('button', { class: 'btn btn-sm btn-danger', text: 'حذف دائمی',
                        onClick: () => AdminPanel.confirmPermanentDelete('rag_document', d,
                            () => AdminPanel.openRAGDetailModal(collection)),
                    }));
                    item.appendChild(actions);
                    if (d.processing_error) {
                        item.appendChild(el('div', { class: 'rag-doc-error', text: d.processing_error }));
                    }
                    list.appendChild(item);
                });
                container.appendChild(list);
            } else {
                container.appendChild(el('div', { class: 'empty-state' }, [
                    el('div', { class: 'empty-text', text: 'سندی ثبت نشده است.' }),
                ]));
            }
        }).catch(err => {
            container.innerHTML = `<div class="empty-title">خطا: ${escapeHtml(err.message)}</div>`;
        });
    },

    openVideoUploadModal() {
        const form = $('#form-video-upload');
        if (form) form.reset();

        const levelSel = $('#video-upload-target-school-type');
        const gradeSel = $('#video-upload-grade');
        if (levelSel && gradeSel) {
            const level = levelSel.value || 'elementary';
            populateGradeSelect(gradeSel, level, '');
        }

        Modal.open('modal-video-upload');
    },

    openVideoEditModal(video) {
        $('#video-edit-id').value = video.id;
        $('#video-edit-title').value = video.title || '';
        $('#video-edit-description').value = video.description || '';
        $('#video-edit-subject').value = video.subject || '';
        $('#video-edit-chapter').value = video.chapter || '';
        $('#video-edit-category').value = video.category || '';
        $('#video-edit-order').value = video.sort_order != null ? video.sort_order : 0;

        const levelSel = $('#video-edit-target-school-type');
        const gradeSel = $('#video-edit-grade');
        const genderSel = $('#video-edit-target-gender');

        const level = video.target_school_type || 'elementary';
        const gender = video.target_gender || 'both';
        if (levelSel) levelSel.value = level;
        if (genderSel) genderSel.value = gender;

        let gradeValue = '';
        if (video.effective_grade !== null && video.effective_grade !== undefined) {
            gradeValue = String(video.effective_grade);
        } else if (video.grade && /^\d+$/.test(String(video.grade).trim())) {
            gradeValue = String(video.grade).trim();
        }

        if (gradeSel) {
            populateGradeSelect(gradeSel, level, gradeValue);
        }

        Modal.open('modal-video-edit');
    },

    async regenThumb(videoId) {
        try {
            await API.post('/admin/videos/' + videoId + '/regenerate-thumbnail');
            Toast.success('بندانگشتی بازتولید شد.');
            AdminPanel.loadVideos();
        } catch (err) { Toast.error(err.message); }
    },

    async resetStudentPassword(student) {
        Modal.prompt('تغییر رمز دانش‌آموز', `رمز جدید برای «${student.full_name}»`, '', async (newPwd) => {
            if (newPwd.length < 6) { Toast.warning('رمز حداقل ۶ کاراکتر.'); return; }
            try {
                await API.post('/admin/students/' + student.id + '/reset-password', { new_password: newPwd });
                Toast.success('رمز تغییر کرد.');
            } catch (err) { Toast.error(err.message); }
        }, { password: true });
    },

    openDBResetModal() {
        const form = $('#form-db-reset');
        if (form) form.reset();
        Modal.open('modal-db-reset');
    },

    openChangePasswordModal() {
        const form = $('#form-admin-password');
        if (form) form.reset();
        Modal.open('modal-admin-password');
    },

    openChangeUsernameModal() {
        const form = $('#form-admin-username');
        if (form) form.reset();

        const input = $('#admin-new-username');
        if (input && State.currentUser) {
            input.placeholder = 'فعلی: ' + (State.currentUser.username || 'admin');
        }

        Modal.open('modal-admin-username');
    },

    openVideoCollectionModal(collection = null) {
        const isEdit = !!collection;
        setText('#modal-video-collection-title', isEdit ? 'ویرایش مجموعه' : 'ایجاد مجموعه ویدیو');
        $('#video-collection-id').value = isEdit ? collection.id : '';
        $('#video-collection-title').value = isEdit ? (collection.title || '') : '';
        $('#video-collection-description').value = isEdit ? (collection.description || '') : '';
        $('#video-collection-category').value = isEdit ? (collection.category || '') : '';
        $('#video-collection-level').value = isEdit ? (collection.level || '') : '';
        $('#video-collection-sort-order').value = isEdit ? (collection.sort_order || 0) : 0;
        $('#video-collection-is-active').checked = isEdit ? !!collection.is_active : true;

        const coverGroup = $('#video-collection-cover-group');
        if (coverGroup) coverGroup.hidden = !isEdit;

        if (isEdit && collection.cover_url) {
            const preview = $('#video-collection-cover-preview');
            if (preview) {
                preview.innerHTML = '';
                const img = document.createElement('img');
                img.src = collection.cover_url + '?t=' + Date.now();
                img.style.maxWidth = '200px';
                img.style.maxHeight = '120px';
                img.style.objectFit = 'cover';
                img.style.borderRadius = '8px';
                img.style.border = '1px solid var(--color-border)';
                preview.appendChild(img);
            }
        } else {
            const preview = $('#video-collection-cover-preview');
            if (preview) preview.innerHTML = '';
        }

        const fileInput = $('#video-collection-cover-file');
        if (fileInput) fileInput.value = '';
        setText('#video-collection-cover-status', '');

        Modal.open('modal-video-collection');
    },

    async uploadCollectionCover() {
        const collectionId = $('#video-collection-id').value;
        if (!collectionId) {
            Toast.warning('ابتدا مجموعه را ذخیره کنید.');
            return;
        }

        const fileInput = $('#video-collection-cover-file');
        if (!fileInput || !fileInput.files.length) {
            Toast.warning('فایل تصویر انتخاب کنید.');
            return;
        }

        const fd = new FormData();
        fd.append('file', fileInput.files[0]);

        const statusEl = $('#video-collection-cover-status');
        if (statusEl) statusEl.textContent = '⏳ در حال آپلود...';

        try {
            const res = await API.upload(`/admin/video-collections/${collectionId}/cover`, fd);
            if (statusEl) statusEl.textContent = '✓ آپلود شد.';
            Toast.success('کاور آپلود شد.');

            const preview = $('#video-collection-cover-preview');
            if (preview) {
                preview.innerHTML = '';
                const img = document.createElement('img');
                img.src = res.cover_url + '?t=' + Date.now();
                img.style.maxWidth = '200px';
                img.style.maxHeight = '120px';
                img.style.objectFit = 'cover';
                img.style.borderRadius = '8px';
                img.style.border = '1px solid var(--color-border)';
                preview.appendChild(img);
            }

            if (fileInput) fileInput.value = '';
            AdminPanel.loadVideoCollections();
        } catch (err) {
            if (statusEl) statusEl.textContent = '✗ ' + (err.message || 'خطا');
            Toast.error(err.message || 'آپلود کاور با خطا مواجه شد.');
        }
    },

    async openCollectionVideosModal(collection) {
        State.collectionVideosModalCollectionId = collection.id;
        State.collectionVideosModalVideos = [];
        State.collectionVideosModalAvailableVideos = [];

        setText('#modal-collection-videos-title', `مدیریت ویدیوهای «${collection.title}»`);
        $('#collection-videos-collection-id').value = collection.id;

        const addSelect = $('#collection-videos-add-select');
        if (addSelect) {
            addSelect.innerHTML = '<option value="">در حال بارگذاری...</option>';
            addSelect.disabled = true;
        }

        const listEl = $('#collection-videos-list');
        if (listEl) {
            listEl.innerHTML = '<div class="empty-state"><div class="empty-title">در حال بارگذاری...</div></div>';
        }
        setText('#collection-videos-count', '(0)');

        Modal.open('modal-collection-videos');

        await Promise.all([
            AdminPanel.loadCollectionVideosList(collection.id),
            AdminPanel.loadAvailableVideosForCollection(),
        ]);
    },

    async loadCollectionVideosList(collectionId) {
        const listEl = $('#collection-videos-list');
        if (!listEl) return;

        try {
            const data = await API.get(`/admin/video-collections/${collectionId}/videos`);
            const videos = data.videos || [];

            State.collectionVideosModalVideos = videos;
            setText('#collection-videos-count', `(${formatNumber(videos.length)})`);

            if (!videos.length) {
                listEl.innerHTML =
                    '<div class="empty-state empty-state-sm">' +
                    '<div class="empty-icon">🎬</div>' +
                    '<div class="empty-text">هیچ ویدیویی در این مجموعه نیست</div>' +
                    '</div>';
                return;
            }

            listEl.innerHTML = '';
            videos.forEach((v, idx) => {
                listEl.appendChild(AdminPanel._collectionVideoItem(v, idx, videos.length));
            });
        } catch (err) {
            listEl.innerHTML = `<div class="empty-state"><div class="empty-title">خطا: ${escapeHtml(err.message)}</div></div>`;
        }
    },

    _collectionVideoItem(v, idx, total) {
        const item = el('div', {
            style: {
                display: 'flex',
                alignItems: 'center',
                gap: '8px',
                padding: '8px 12px',
                background: 'var(--color-surface-alt)',
                borderRadius: '8px',
                marginBottom: '4px',
                opacity: v.deleted_at ? '0.5' : '1',
            },
        });

        const orderBtns = el('div', { style: { display: 'flex', flexDirection: 'column', gap: '2px' } });
        const upBtn = el('button', {
            class: 'btn btn-sm btn-ghost',
            text: '▲',
            title: 'بالا',
            style: { padding: '0 6px', fontSize: '10px', lineHeight: '1' },
            disabled: idx === 0 ? 'true' : null,
            onClick: () => AdminPanel.moveCollectionVideo(idx, -1),
        });
        const downBtn = el('button', {
            class: 'btn btn-sm btn-ghost',
            text: '▼',
            title: 'پایین',
            style: { padding: '0 6px', fontSize: '10px', lineHeight: '1' },
            disabled: idx === total - 1 ? 'true' : null,
            onClick: () => AdminPanel.moveCollectionVideo(idx, 1),
        });
        orderBtns.appendChild(upBtn);
        orderBtns.appendChild(downBtn);

        item.appendChild(orderBtns);

        const info = el('div', { style: { flex: '1', minWidth: '0' } });
        info.appendChild(el('div', {
            style: { fontWeight: '500', fontSize: '13px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' },
            text: v.title,
        }));

        const meta = [];
        if (v.duration) meta.push(formatDuration(v.duration));
        if (v.subject) meta.push(v.subject);
        if (v.grade) meta.push(`پایه ${v.grade}`);

        if (meta.length) {
            info.appendChild(el('div', {
                style: { fontSize: '11px', color: 'var(--color-text-muted)', marginTop: '2px' },
                text: meta.join(' • '),
            }));
        }

        if (v.deleted_at) {
            info.appendChild(el('span', {
                class: 'badge badge-error',
                text: 'حذف‌شده',
                style: { fontSize: '10px', marginTop: '4px' },
            }));
        } else if (!v.is_active) {
            info.appendChild(el('span', {
                class: 'badge badge-warning',
                text: 'غیرفعال',
                style: { fontSize: '10px', marginTop: '4px' },
            }));
        }

        item.appendChild(info);

        item.appendChild(el('button', {
            class: 'btn btn-sm btn-danger',
            text: 'حذف از مجموعه',
            style: { flexShrink: '0' },
            onClick: () => AdminPanel.removeVideoFromCollection(v.id, v.title),
        }));

        return item;
    },

    async loadAvailableVideosForCollection() {
        const addSelect = $('#collection-videos-add-select');
        if (!addSelect) return;

        try {
            const data = await API.get('/admin/videos?page_size=100&is_active=true');
            const allVideos = data.videos || [];

            const existingIds = new Set(State.collectionVideosModalVideos.map(v => v.id));
            const available = allVideos.filter(v => !existingIds.has(v.id));

            State.collectionVideosModalAvailableVideos = available;

            addSelect.innerHTML = '<option value="">— انتخاب ویدیو —</option>';
            if (!available.length) {
                addSelect.innerHTML = '<option value="">ویدیوی جدیدی برای افزودن نیست</option>';
                addSelect.disabled = true;
                return;
            }

            available.forEach(v => {
                const opt = document.createElement('option');
                opt.value = v.id;
                opt.textContent = v.title + (v.duration ? ` (${formatDuration(v.duration)})` : '');
                addSelect.appendChild(opt);
            });
            addSelect.disabled = false;
        } catch (err) {
            addSelect.innerHTML = '<option value="">خطا در بارگذاری</option>';
            addSelect.disabled = true;
        }
    },

    async addVideoToCollection() {
        const collectionId = State.collectionVideosModalCollectionId;
        const addSelect = $('#collection-videos-add-select');
        const videoId = addSelect ? addSelect.value : '';

        if (!collectionId || !videoId) {
            Toast.warning('یک ویدیو انتخاب کنید.');
            return;
        }

        try {
            await API.post(`/admin/video-collections/${collectionId}/videos`, {
                video_id: videoId,
            });
            Toast.success('ویدیو به مجموعه اضافه شد.');
            await AdminPanel.loadCollectionVideosList(collectionId);
            await AdminPanel.loadAvailableVideosForCollection();
            AdminPanel.loadVideoCollections();
        } catch (err) {
            Toast.error(err.message || 'افزودن با خطا مواجه شد.');
        }
    },

    async removeVideoFromCollection(videoId, videoTitle) {
        const collectionId = State.collectionVideosModalCollectionId;
        if (!collectionId) return;

        Modal.confirm(
            `ویدیو «${videoTitle}» از این مجموعه حذف شود؟\n(ویدیو و فایل آن حفظ می‌شود)`,
            async () => {
                try {
                    await API.del(`/admin/video-collections/${collectionId}/videos/${videoId}`);
                    Toast.success('ویدیو از مجموعه حذف شد.');
                    await AdminPanel.loadCollectionVideosList(collectionId);
                    await AdminPanel.loadAvailableVideosForCollection();
                    AdminPanel.loadVideoCollections();
                } catch (err) {
                    Toast.error(err.message || 'حذف با خطا مواجه شد.');
                }
            }
        );
    },

    async moveCollectionVideo(idx, delta) {
        const videos = State.collectionVideosModalVideos;
        if (!videos || !videos.length) return;

        const newIdx = idx + delta;
        if (newIdx < 0 || newIdx >= videos.length) return;

        const newOrder = videos.slice();
        const [moved] = newOrder.splice(idx, 1);
        newOrder.splice(newIdx, 0, moved);

        State.collectionVideosModalVideos = newOrder;
        const listEl = $('#collection-videos-list');
        if (listEl) {
            listEl.innerHTML = '';
            newOrder.forEach((v, i) => {
                listEl.appendChild(AdminPanel._collectionVideoItem(v, i, newOrder.length));
            });
        }

        const collectionId = State.collectionVideosModalCollectionId;
        const videoIds = newOrder.map(v => v.id);

        try {
            await API.put(`/admin/video-collections/${collectionId}/videos/order`, {
                video_ids: videoIds,
            });
        } catch (err) {
            Toast.error(err.message || 'ذخیره ترتیب با خطا مواجه شد.');
            await AdminPanel.loadCollectionVideosList(collectionId);
        }
    },

    // ═══════════════════════════════════════════════════════════════
    // [DANOVA-FIX-TESTACCOUNTS] v1.1.0 — Added by fix.py
    // ─────────────────────────────────────────────────────────────
    // رفع باگ: توابع مدیریت پنل‌های تست در app.js وجود نداشتند.
    // این بلاک ۱۰۰٪ افزودنی است و هیچ کد موجودی را تغییر نمی‌دهد.
    // ═══════════════════════════════════════════════════════════════

    // ─────────────────────────────────────────────────────────────
    // لیست پنل‌های تست
    // ─────────────────────────────────────────────────────────────

    async loadTestAccounts() {
        // Bind events once (search box + status filter).
        this._bindTestAccountsEvents();

        const tbody = document.getElementById('admin-test-accounts-tbody');
        if (!tbody) return;

        tbody.innerHTML = '<tr><td colspan="8" class="table-empty">در حال بارگذاری...</td></tr>';

        // Also refresh the KPI summary (secondary, non-blocking).
        this.loadTestAccountsSummary();

        const page = (State.adminPages && State.adminPages.testAccounts) || 1;
        const searchEl = document.getElementById('admin-test-accounts-search');
        const statusEl = document.getElementById('admin-test-accounts-status-filter');
        const search = searchEl ? (searchEl.value || '') : '';
        const status = statusEl ? (statusEl.value || '') : '';

        const params = new URLSearchParams();
        if (search.trim()) params.set('search', search.trim());
        if (status) params.set('status', status);
        params.set('page', String(page));
        params.set('page_size', '25');

        try {
            const data = await API.get('/admin/test-accounts?' + params.toString());
            const accounts = data.test_accounts || [];

            if (!State.adminPages) State.adminPages = {};
            State.adminPages.testAccounts = data.page || 1;

            if (!accounts.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="table-empty">پنل تستی یافت نشد.</td></tr>';
                this._renderTestAccountsPagination(data);
                return;
            }

            tbody.innerHTML = '';
            accounts.forEach(acc => tbody.appendChild(this._testAccountRow(acc)));
            this._renderTestAccountsPagination(data);

        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="8" class="table-empty">خطا: '
                + escapeHtml(err.message || '') + '</td></tr>';
        }
    },

    async loadTestAccountsSummary() {
        try {
            const data = await API.get('/admin/test-accounts/summary');
            const s = data.summary || {};
            setText('#test-kpi-total', formatNumber(s.total || 0));
            setText('#test-kpi-active', formatNumber(s.active || 0));
            setText('#test-kpi-expired', formatNumber(s.expired_or_exhausted || 0));
            setText('#test-kpi-approaching', formatNumber(s.approaching_expiry || 0));
            setText('#test-kpi-messages', formatNumber(s.total_messages_consumed || 0));
        } catch (err) {
            // silent — KPIها ثانویه هستند
            console.warn('[TEST-ACCOUNTS] summary load failed:', err);
        }
    },

    _testAccountRow(acc) {
        const tr = el('tr');

        // 1) Name
        tr.appendChild(el('td', { text: acc.name || '—' }));

        // 2) Username
        tr.appendChild(el('td', {}, [
            el('code', {
                text: acc.username || '—',
                style: {
                    direction: 'ltr',
                    display: 'inline-block',
                    fontFamily: 'var(--font-mono)',
                },
            }),
        ]));

        // 3) School
        tr.appendChild(el('td', { text: acc.school_name || '—' }));

        // 4) AI Profile
        tr.appendChild(el('td', { text: acc.ai_profile_name || '—' }));

        // 5) Expiration
        const expiresCell = el('td');
        expiresCell.appendChild(el('div', { text: formatDateTime(acc.expires_at) }));
        if (acc.remaining_seconds > 0) {
            expiresCell.appendChild(el('small', {
                text: acc.remaining_human || '',
                style: { color: 'var(--color-text-muted)', fontSize: '11px' },
            }));
        } else {
            expiresCell.appendChild(el('small', {
                text: 'منقضی شده',
                style: { color: 'var(--color-error)', fontSize: '11px' },
            }));
        }
        tr.appendChild(expiresCell);

        // 6) Messages quota
        const msgCell = el('td');
        const used = Number(acc.messages_used) || 0;
        const limit = Number(acc.message_limit) || 0;
        const pct = limit > 0 ? Math.round((used / limit) * 100) : 0;
        msgCell.appendChild(el('div', {
            text: formatNumber(used) + ' / ' + formatNumber(limit),
        }));
        msgCell.appendChild(el('div', {
            class: 'usage-bar',
            style: { marginTop: '4px', height: '4px' },
        }, [
            el('div', {
                class: 'usage-bar-fill',
                style: { width: pct + '%' },
            }),
        ]));
        tr.appendChild(msgCell);

        // 7) Status
        const statusMap = {
            active:    { cls: 'badge-success', label: 'فعال' },
            expired:   { cls: 'badge-warning', label: 'منقضی' },
            exhausted: { cls: 'badge-warning', label: 'تمام‌شده' },
            revoked:   { cls: 'badge-error',   label: 'لغو‌شده' },
            deleted:   { cls: 'badge-error',   label: 'حذف‌شده' },
        };
        const sm = statusMap[acc.status] || { cls: 'badge-primary', label: acc.status };
        tr.appendChild(el('td', {}, [
            el('span', { class: 'badge ' + sm.cls, text: sm.label }),
        ]));

        // 8) Actions
        tr.appendChild(el('td', { class: 'actions-cell' }, this._testAccountActions(acc)));

        return tr;
    },

    _testAccountActions(acc) {
        const actions = [];
        const canRevoke = acc.status === 'active';
        const canExtend = (acc.status === 'active'
                        || acc.status === 'expired'
                        || acc.status === 'exhausted');

        if (canExtend) {
            actions.push(el('button', {
                class: 'btn btn-sm btn-ghost',
                text: 'تمدید',
                onClick: () => this.extendTestAccount(acc),
            }));
        }

        actions.push(el('button', {
            class: 'btn btn-sm btn-ghost',
            text: 'تغییر رمز',
            onClick: () => this.resetTestAccountPassword(acc),
        }));

        if (canRevoke) {
            actions.push(el('button', {
                class: 'btn btn-sm btn-ghost',
                text: 'لغو',
                onClick: () => this.revokeTestAccount(acc),
            }));
        }

        if (acc.status !== 'deleted') {
            actions.push(el('button', {
                class: 'btn btn-sm btn-danger',
                text: 'حذف',
                onClick: () => this.deleteTestAccount(acc),
            }));
        }

        return actions;
    },

    _renderTestAccountsPagination(data) {
        const c = document.getElementById('admin-test-accounts-pagination');
        if (!c) return;
        if (!data || data.total_pages <= 1) { c.innerHTML = ''; return; }

        c.innerHTML = '';
        const page = data.page || 1;
        const totalPages = data.total_pages || 1;
        const total = data.total || 0;

        c.appendChild(el('span', {
            class: 'pagination-info',
            text: 'مجموع: ' + formatNumber(total),
        }));

        const wrap = el('div', { class: 'pagination-controls' });

        wrap.appendChild(el('button', {
            class: 'btn btn-sm btn-ghost',
            text: 'قبلی',
            disabled: page <= 1 ? 'true' : null,
            onClick: () => {
                if (!State.adminPages) State.adminPages = {};
                State.adminPages.testAccounts = page - 1;
                this.loadTestAccounts();
            },
        }));

        wrap.appendChild(el('span', {
            class: 'pagination-current',
            text: formatNumber(page) + ' / ' + formatNumber(totalPages),
        }));

        wrap.appendChild(el('button', {
            class: 'btn btn-sm btn-ghost',
            text: 'بعدی',
            disabled: page >= totalPages ? 'true' : null,
            onClick: () => {
                if (!State.adminPages) State.adminPages = {};
                State.adminPages.testAccounts = page + 1;
                this.loadTestAccounts();
            },
        }));

        c.appendChild(wrap);
    },

    // ─────────────────────────────────────────────────────────────
    // مودال ساخت پنل تست
    // ─────────────────────────────────────────────────────────────

    async openTestAccountModal() {
        const form = document.getElementById('form-test-account');
        if (form) form.reset();

        // Default: +24 hours from now (local).
        const expiresInput = document.getElementById('test-account-expires');
        if (expiresInput) {
            const defaultMs = Date.now() + 24 * 3600 * 1000;
            const d = new Date(defaultMs);
            const pad = (n) => String(n).padStart(2, '0');
            const local = d.getFullYear() + '-'
                + pad(d.getMonth() + 1) + '-'
                + pad(d.getDate()) + 'T'
                + pad(d.getHours()) + ':'
                + pad(d.getMinutes());
            expiresInput.value = local;
        }

        const msgLimitInput = document.getElementById('test-account-message-limit');
        if (msgLimitInput) msgLimitInput.value = '10';

        const hint = document.getElementById('test-account-hint');
        if (hint) { hint.textContent = ''; hint.hidden = true; }

        await Promise.all([
            this._populateTestAccountSchools(),
            this._populateTestAccountAIs(),
        ]);

        Modal.open('modal-test-account');
    },

    async _populateTestAccountSchools() {
        const sel = document.getElementById('test-account-school');
        if (!sel) return;

        sel.innerHTML = '<option value="">— در حال بارگذاری... —</option>';

        try {
            const data = await API.get('/admin/schools?page_size=200&is_active=1');
            const schools = data.schools || [];

            sel.innerHTML = '<option value="">— بدون مدرسه (تست مستقل) —</option>';
            schools.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = s.name + (s.code ? ' (' + s.code + ')' : '');
                sel.appendChild(opt);
            });
        } catch (err) {
            sel.innerHTML = '<option value="">— خطا در بارگذاری مدارس —</option>';
        }
    },

    async _populateTestAccountAIs() {
        const sel = document.getElementById('test-account-ai');
        if (!sel) return;

        sel.innerHTML = '<option value="">— در حال بارگذاری... —</option>';

        try {
            const data = await API.get('/admin/ai-profiles?page_size=200&is_active=true');
            const ais = (data.ai_profiles || []).filter(p => !p.deleted_at && p.is_active);

            sel.innerHTML = '<option value="">— انتخاب کنید —</option>';
            if (!ais.length) {
                sel.innerHTML = '<option value="">هیچ AI فعالی وجود ندارد</option>';
                return;
            }
            ais.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.name;
                sel.appendChild(opt);
            });
        } catch (err) {
            sel.innerHTML = '<option value="">— خطا در بارگذاری AI —</option>';
        }
    },

    async submitTestAccount(event) {
        if (event) event.preventDefault();

        const submitBtn = document.getElementById('test-account-submit');
        const hint = document.getElementById('test-account-hint');

        const showError = (msg) => {
            if (hint) {
                hint.textContent = msg;
                hint.style.color = 'var(--color-error)';
                hint.hidden = false;
            }
            Toast.error(msg);
        };

        const getVal = (id) => {
            const n = document.getElementById(id);
            return n ? (n.value || '') : '';
        };

        const name = getVal('test-account-name').trim();
        const username = getVal('test-account-username').trim();
        const password = getVal('test-account-password');
        const schoolId = getVal('test-account-school').trim();
        const aiProfileId = getVal('test-account-ai').trim();
        const expiresLocal = getVal('test-account-expires').trim();
        const messageLimit = parseInt(getVal('test-account-message-limit') || '10', 10);
        const description = getVal('test-account-description').trim();

        // Client-side validation
        if (name.length < 2) return showError('نام پنل تست حداقل ۲ کاراکتر باشد.');
        if (!/^[A-Za-z0-9_.\-]{3,100}$/.test(username)) {
            return showError('نام کاربری باید ۳ تا ۱۰۰ کاراکتر و شامل حروف انگلیسی، اعداد، _، . و - باشد.');
        }
        if (password.length < 6) return showError('رمز عبور حداقل ۶ کاراکتر باشد.');
        if (!aiProfileId) return showError('انتخاب AI Profile الزامی است.');
        if (!expiresLocal) return showError('زمان انقضا الزامی است.');
        if (isNaN(messageLimit) || messageLimit < 1 || messageLimit > 50) {
            return showError('سقف پیام باید بین ۱ تا ۵۰ باشد.');
        }

        // Local datetime → UTC
        let expiresAtUtc;
        try {
            const dt = new Date(expiresLocal);
            if (isNaN(dt.getTime())) throw new Error('invalid');
            expiresAtUtc = dt.toISOString().slice(0, 19).replace('T', ' ');
        } catch (e) {
            return showError('فرمت زمان انقضا نامعتبر است.');
        }

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'در حال ارسال...';
        }

        try {
            const payload = {
                name: name,
                username: username,
                password: password,
                school_id: schoolId || null,
                ai_profile_id: aiProfileId,
                expires_at: expiresAtUtc,
                message_limit: messageLimit,
                description: description || null,
            };

            const res = await API.post('/admin/test-accounts', payload);

            Toast.success(res.message || 'پنل تست ایجاد شد.');
            Modal.close('modal-test-account');

            this._showTestAccountCredentials({
                name: name,
                username: username,
                password: password,
                expires_at: expiresAtUtc,
                message_limit: messageLimit,
            });

            if (!State.adminPages) State.adminPages = {};
            State.adminPages.testAccounts = 1;
            await this.loadTestAccounts();

        } catch (err) {
            showError(err.message || 'خطا در ساخت پنل تست.');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'ایجاد پنل تست';
            }
        }
    },

    _showTestAccountCredentials(acc) {
        const modalId = 'modal-test-account-created';
        let overlay = document.getElementById(modalId);
        if (overlay) overlay.remove();

        // Build DOM safely (no innerHTML with user data).
        overlay = document.createElement('div');
        overlay.id = modalId;
        overlay.className = 'modal';

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.setAttribute('data-modal-close', '');
        overlay.appendChild(backdrop);

        const content = document.createElement('div');
        content.className = 'modal-content';
        content.style.maxWidth = '520px';

        const header = document.createElement('div');
        header.className = 'modal-header';
        const title = document.createElement('h3');
        title.className = 'modal-title';
        title.textContent = '✅ پنل تست با موفقیت ایجاد شد';
        header.appendChild(title);
        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'modal-close';
        closeBtn.setAttribute('data-modal-close', '');
        closeBtn.setAttribute('aria-label', 'بستن');
        closeBtn.textContent = '✕';
        header.appendChild(closeBtn);
        content.appendChild(header);

        const body = document.createElement('div');
        body.className = 'modal-body';

        const warn = document.createElement('div');
        warn.className = 'alert alert-warning';
        warn.style.display = 'flex';
        warn.style.alignItems = 'flex-start';
        const warnIcon = document.createElement('span');
        warnIcon.className = 'alert-icon';
        warnIcon.textContent = '⚠';
        warn.appendChild(warnIcon);
        const warnMsg = document.createElement('span');
        warnMsg.textContent = 'این اطلاعات فقط یک بار نمایش داده می‌شود. لطفاً آن را در جای امنی ذخیره کنید.';
        warn.appendChild(warnMsg);
        body.appendChild(warn);

        const box = document.createElement('div');
        box.style.background = 'var(--color-surface-alt)';
        box.style.padding = '16px';
        box.style.borderRadius = 'var(--radius-md)';
        box.style.marginBottom = '12px';

        const addRow = (label, value, isMono) => {
            const row = document.createElement('div');
            row.style.display = 'flex';
            row.style.justifyContent = 'space-between';
            row.style.padding = '8px 0';
            row.style.borderBottom = '1px solid var(--color-border-subtle)';

            const lbl = document.createElement('span');
            lbl.style.color = 'var(--color-text-muted)';
            lbl.textContent = label;
            row.appendChild(lbl);

            const val = document.createElement(isMono ? 'code' : 'strong');
            if (isMono) {
                val.style.direction = 'ltr';
                val.style.fontFamily = 'var(--font-mono)';
            }
            val.textContent = value;
            row.appendChild(val);

            box.appendChild(row);
        };

        addRow('نام پنل:', acc.name || '—', false);
        addRow('نام کاربری:', acc.username || '—', true);
        addRow('رمز عبور:', acc.password || '—', true);
        addRow('سقف پیام:', formatNumber(acc.message_limit || 0), false);

        body.appendChild(box);
        content.appendChild(body);

        const footer = document.createElement('div');
        footer.className = 'modal-footer';
        const okBtn = document.createElement('button');
        okBtn.type = 'button';
        okBtn.className = 'btn btn-primary';
        okBtn.setAttribute('data-modal-close', '');
        okBtn.textContent = 'متوجه شدم';
        footer.appendChild(okBtn);
        content.appendChild(footer);

        overlay.appendChild(content);
        document.body.appendChild(overlay);

        // Ensure close buttons work via Modal.close()
        overlay.querySelectorAll('[data-modal-close]').forEach(node => {
            node.addEventListener('click', () => Modal.close(modalId));
        });

        Modal.open(modalId);
    },

    // ─────────────────────────────────────────────────────────────
    // عملیات روی پنل تست
    // ─────────────────────────────────────────────────────────────

    async revokeTestAccount(acc) {
        const self = this;
        Modal.confirm(
            'آیا از لغو پنل تست «' + (acc.name || '') + '» مطمئن هستید؟ پس از لغو، کاربر نمی‌تواند وارد شود.',
            async () => {
                try {
                    const res = await API.post(
                        '/admin/test-accounts/' + encodeURIComponent(acc.id) + '/revoke',
                        {}
                    );
                    Toast.success(res.message || 'پنل تست لغو شد.');
                    await self.loadTestAccounts();
                } catch (err) {
                    Toast.error(err.message || 'خطا در لغو پنل تست.');
                }
            },
            { title: 'تأیید لغو', okText: 'لغو کن', danger: true }
        );
    },

    async extendTestAccount(acc) {
        const self = this;
        const defaultMs = Date.now() + 24 * 3600 * 1000;
        const d = new Date(defaultMs);
        const pad = (n) => String(n).padStart(2, '0');
        const defaultLocal = d.getFullYear() + '-'
            + pad(d.getMonth() + 1) + '-'
            + pad(d.getDate()) + 'T'
            + pad(d.getHours()) + ':'
            + pad(d.getMinutes());

        Modal.prompt(
            'تمدید پنل تست',
            'زمان انقضای جدید برای «' + (acc.name || '') + '» (حداکثر ۷۲ ساعت از الان، فرمت: 2026-10-10T15:30):',
            defaultLocal,
            async (newExpiresLocal) => {
                let expiresAtUtc;
                try {
                    const dt = new Date(newExpiresLocal);
                    if (isNaN(dt.getTime())) throw new Error('invalid');
                    expiresAtUtc = dt.toISOString().slice(0, 19).replace('T', ' ');
                } catch (e) {
                    Toast.error('فرمت زمان نامعتبر است. مثال: 2026-10-10T15:30');
                    return;
                }

                try {
                    const res = await API.post(
                        '/admin/test-accounts/' + encodeURIComponent(acc.id) + '/extend',
                        { expires_at: expiresAtUtc }
                    );
                    Toast.success(res.message || 'زمان انقضا تمدید شد.');
                    await self.loadTestAccounts();
                } catch (err) {
                    Toast.error(err.message || 'خطا در تمدید.');
                }
            }
        );
    },

    async resetTestAccountPassword(acc) {
        const self = this;
        Modal.prompt(
            'تغییر رمز پنل تست',
            'رمز جدید برای «' + (acc.name || '') + '» (حداقل ۶ کاراکتر):',
            '',
            async (newPwd) => {
                if (!newPwd || newPwd.length < 6) {
                    Toast.warning('رمز باید حداقل ۶ کاراکتر باشد.');
                    return;
                }
                try {
                    const res = await API.post(
                        '/admin/test-accounts/' + encodeURIComponent(acc.id) + '/reset-password',
                        { new_password: newPwd }
                    );
                    Toast.success(res.message || 'رمز تغییر کرد.');
                    self._showTestAccountCredentials({
                        name: acc.name,
                        username: acc.username,
                        password: newPwd,
                        expires_at: acc.expires_at,
                        message_limit: acc.message_limit,
                    });
                } catch (err) {
                    Toast.error(err.message || 'خطا در تغییر رمز.');
                }
            },
            { password: false }
        );
    },

    async deleteTestAccount(acc) {
        const self = this;
        Modal.typedConfirm(acc.name || '', async ({ confirm_name }) => {
            try {
                await API.del('/admin/test-accounts/' + encodeURIComponent(acc.id));
                Toast.success('پنل تست حذف شد.');
                await self.loadTestAccounts();
            } catch (err) {
                Toast.error(err.message || 'خطا در حذف.');
            }
        }, {
            entityLabel: acc.name || '',
            allowCascade: false,
            title: 'تأیید حذف پنل تست',
        });
    },

    // ─────────────────────────────────────────────────────────────
    // اتصال رویدادها (فقط یک‌بار)
    // ─────────────────────────────────────────────────────────────

    _testAccountsEventsBound: false,

    _bindTestAccountsEvents() {
        if (this._testAccountsEventsBound) return;
        this._testAccountsEventsBound = true;

        const search = document.getElementById('admin-test-accounts-search');
        if (search) {
            let t;
            search.addEventListener('input', () => {
                clearTimeout(t);
                t = setTimeout(() => {
                    if (!State.adminPages) State.adminPages = {};
                    State.adminPages.testAccounts = 1;
                    AdminPanel.loadTestAccounts();
                }, 350);
            });
        }

        const statusFilter = document.getElementById('admin-test-accounts-status-filter');
        if (statusFilter) {
            statusFilter.addEventListener('change', () => {
                if (!State.adminPages) State.adminPages = {};
                State.adminPages.testAccounts = 1;
                AdminPanel.loadTestAccounts();
            });
        }
    },


    _reportFilterDefinitions: {
        schools: [
            { key: 'q', label: 'جستجو', type: 'text' },
            { key: 'school_type', label: 'مقطع', type: 'select', options: [
                { value: '', label: '— همه —' },
                { value: 'elementary', label: 'ابتدایی' },
                { value: 'middle', label: 'متوسطه اول' },
                { value: 'high', label: 'متوسطه دوم' },
            ]},
            { key: 'gender', label: 'جنسیت', type: 'select', options: [
                { value: '', label: '— همه —' },
                { value: 'girls', label: 'دخترانه' },
                { value: 'boys', label: 'پسرانه' },
            ]},
            { key: 'status', label: 'وضعیت', type: 'select', options: [
                { value: '', label: '— همه —' },
                { value: '1', label: 'فعال' },
                { value: '0', label: 'غیرفعال' },
            ]},
        ],
        students: [
            { key: 'q', label: 'جستجو', type: 'text' },
            { key: 'grade', label: 'پایه', type: 'text' },
            { key: 'status', label: 'وضعیت', type: 'select', options: [
                { value: '', label: '— همه —' },
                { value: '1', label: 'فعال' },
                { value: '0', label: 'غیرفعال' },
            ]},
            { key: 'from', label: 'از تاریخ (YYYY-MM-DD)', type: 'text', placeholder: '2026-01-01' },
            { key: 'to', label: 'تا تاریخ (YYYY-MM-DD)', type: 'text', placeholder: '2026-12-31' },
        ],
        classes: [
            { key: 'q', label: 'جستجو', type: 'text' },
            { key: 'grade', label: 'پایه', type: 'text' },
            { key: 'status', label: 'وضعیت', type: 'select', options: [
                { value: '', label: '— همه —' },
                { value: '1', label: 'فعال' },
                { value: '0', label: 'غیرفعال' },
            ]},
        ],
        users: [
            { key: 'q', label: 'جستجو', type: 'text' },
            { key: 'role', label: 'نقش', type: 'select', options: [
                { value: '', label: '— همه —' },
                { value: 'danova_admin', label: 'مدیر DANOVA' },
                { value: 'school_manager', label: 'مدیر مدرسه' },
                { value: 'student', label: 'دانش‌آموز' },
            ]},
            { key: 'status', label: 'وضعیت', type: 'select', options: [
                { value: '', label: '— همه —' },
                { value: '1', label: 'فعال' },
                { value: '0', label: 'غیرفعال' },
            ]},
        ],
        ai_usage: [
            { key: 'from', label: 'از تاریخ (YYYY-MM-DD)', type: 'text', placeholder: '2026-01-01' },
            { key: 'to', label: 'تا تاریخ (YYYY-MM-DD)', type: 'text', placeholder: '2026-12-31' },
        ],
        conversations: [
            { key: 'q', label: 'جستجو', type: 'text' },
            { key: 'from', label: 'از تاریخ (YYYY-MM-DD)', type: 'text', placeholder: '2026-01-01' },
            { key: 'to', label: 'تا تاریخ (YYYY-MM-DD)', type: 'text', placeholder: '2026-12-31' },
        ],
        statistics: [
            { key: 'from', label: 'از تاریخ (YYYY-MM-DD)', type: 'text', placeholder: '2026-01-01' },
            { key: 'to', label: 'تا تاریخ (YYYY-MM-DD)', type: 'text', placeholder: '2026-12-31' },
            { key: 'granularity', label: 'دوره', type: 'select', options: [
                { value: 'daily', label: 'روزانه' },
                { value: 'monthly', label: 'ماهانه' },
            ]},
        ],
        audit_logs: [
            { key: 'q', label: 'جستجو', type: 'text' },
            { key: 'action', label: 'عملیات', type: 'text' },
            { key: 'entity_type', label: 'نوع موجودیت', type: 'text' },
            { key: 'from', label: 'از تاریخ (YYYY-MM-DD)', type: 'text', placeholder: '2026-01-01' },
            { key: 'to', label: 'تا تاریخ (YYYY-MM-DD)', type: 'text', placeholder: '2026-12-31' },
        ],
    },

    loadReports() {
        if (!State.reportsState.dataset) {
            State.reportsState.dataset = 'schools';
        }
        AdminPanel.renderReportFilters();
        AdminPanel.loadReportPreview();
    },

    selectReportDataset(dataset) {
        State.reportsState.dataset = dataset;
        State.reportsState.page = 1;
        State.reportsState.filters = {};

        $$('#reports-dataset-tabs [data-report-dataset]').forEach(btn => {
            btn.classList.toggle('filter-chip-active',
                btn.getAttribute('data-report-dataset') === dataset);
        });

        AdminPanel.renderReportFilters();
        AdminPanel.loadReportPreview();
    },

    renderReportFilters() {
        const container = $('#reports-filters-bar');
        if (!container) return;

        const dataset = State.reportsState.dataset;
        const defs = AdminPanel._reportFilterDefinitions[dataset] || [];

        container.innerHTML = '';

        if (!defs.length) {
            container.appendChild(el('span', {
                class: 'text-muted',
                text: 'این گزارش فیلتری ندارد.',
                style: { fontSize: '13px' },
            }));
            return;
        }

        defs.forEach(def => {
            const wrap = el('div', {
                style: { display: 'flex', flexDirection: 'column', gap: '4px', minWidth: '140px' },
            });

            wrap.appendChild(el('label', {
                class: 'form-label',
                text: def.label,
                style: { fontSize: '12px', marginBottom: '0' },
            }));

            let inputEl;
            const currentValue = State.reportsState.filters[def.key] || '';

            if (def.type === 'select') {
                inputEl = document.createElement('select');
                inputEl.className = 'form-input';
                inputEl.style.minWidth = '140px';
                (def.options || []).forEach(opt => {
                    const o = document.createElement('option');
                    o.value = opt.value;
                    o.textContent = opt.label;
                    if (String(opt.value) === String(currentValue)) o.selected = true;
                    inputEl.appendChild(o);
                });
            } else {
                inputEl = document.createElement('input');
                inputEl.type = 'text';
                inputEl.className = 'form-input';
                inputEl.value = currentValue;
                inputEl.placeholder = def.placeholder || '';
                inputEl.style.minWidth = '140px';
            }

            inputEl.dataset.reportFilter = def.key;

            const applyFn = debounce(() => {
                const newVal = inputEl.value;
                if (newVal === '' || newVal === null || newVal === undefined) {
                    delete State.reportsState.filters[def.key];
                } else {
                    State.reportsState.filters[def.key] = newVal;
                }
                State.reportsState.page = 1;
                AdminPanel.loadReportPreview();
            }, 400);

            inputEl.addEventListener('input', applyFn);
            inputEl.addEventListener('change', applyFn);

            wrap.appendChild(inputEl);
            container.appendChild(wrap);
        });

        if (Object.keys(State.reportsState.filters).length > 0) {
            const clearBtn = el('button', {
                type: 'button',
                class: 'btn btn-sm btn-ghost',
                text: 'پاک کردن فیلترها',
                style: { alignSelf: 'flex-end' },
                onClick: () => {
                    State.reportsState.filters = {};
                    State.reportsState.page = 1;
                    AdminPanel.renderReportFilters();
                    AdminPanel.loadReportPreview();
                },
            });
            container.appendChild(clearBtn);
        }
    },

    _buildReportQuery() {
        const p = new URLSearchParams();
        Object.entries(State.reportsState.filters).forEach(([k, v]) => {
            if (v === null || v === undefined || v === '') return;
            p.set(k, String(v));
        });
        p.set('page', String(State.reportsState.page || 1));
        p.set('page_size', String(State.reportsState.pageSize || 50));
        return p.toString();
    },

    async loadReportPreview() {
        const dataset = State.reportsState.dataset;
        const thead = $('#reports-preview-thead');
        const tbody = $('#reports-preview-tbody');
        const countEl = $('#reports-count');

        if (!thead || !tbody) return;

        thead.innerHTML = '<tr><th>در حال بارگذاری...</th></tr>';
        tbody.innerHTML = '<tr><td class="table-empty">در حال بارگذاری...</td></tr>';
        if (countEl) countEl.textContent = '—';

        try {
            const url = `/admin/reports/${encodeURIComponent(dataset)}?` + AdminPanel._buildReportQuery();
            const data = await API.get(url);
            const report = data.report || {};
            AdminPanel.renderReportPreview(report);
        } catch (err) {
            thead.innerHTML = '<tr><th>خطا</th></tr>';
            tbody.innerHTML = `<tr><td class="table-empty">خطا: ${escapeHtml(err.message)}</td></tr>`;
            if (countEl) countEl.textContent = '—';
        }
    },

    renderReportPreview(report) {
        const thead = $('#reports-preview-thead');
        const tbody = $('#reports-preview-tbody');
        const countEl = $('#reports-count');
        const paginationEl = $('#reports-pagination');

        if (!thead || !tbody) return;

        const headers = report.headers || {};
        const rows = report.rows || [];
        const headerKeys = Object.keys(headers);
        const headerLabels = Object.values(headers);

        State.reportsState.lastTotal = report.total || 0;
        State.reportsState.lastHeaders = headers;

        if (countEl) {
            const total = report.total || 0;
            const shown = rows.length;
            const page = report.page || 1;
            const totalPages = report.total_pages || 0;
            countEl.textContent = `${formatNumber(total)} رکورد • نمایش ${formatNumber(shown)} رکورد (صفحه ${formatNumber(page)} از ${formatNumber(totalPages)})`;
        }

        thead.innerHTML = '';
        const headerRow = el('tr');
        headerLabels.forEach(label => {
            headerRow.appendChild(el('th', { text: label }));
        });
        thead.appendChild(headerRow);

        tbody.innerHTML = '';
        if (!rows.length) {
            const emptyRow = el('tr');
            emptyRow.appendChild(el('td', {
                class: 'table-empty',
                colspan: String(Math.max(1, headerKeys.length)),
                text: 'رکوردی برای نمایش یافت نشد.',
            }));
            tbody.appendChild(emptyRow);
        } else {
            rows.forEach(row => {
                const tr = el('tr');
                headerKeys.forEach(key => {
                    let val = row[key];
                    if (val === null || val === undefined) val = '—';
                    if (typeof val === 'object') {
                        try { val = JSON.stringify(val); } catch (e) { val = '—'; }
                    }
                    tr.appendChild(el('td', { text: String(val) }));
                });
                tbody.appendChild(tr);
            });
        }

        if (paginationEl) {
            paginationEl.innerHTML = '';
            const totalPages = report.total_pages || 0;
            if (totalPages > 1) {
                const wrap = el('div', { class: 'pagination-controls', style: { margin: '0 auto' } });
                const page = report.page || 1;

                const prev = el('button', {
                    class: 'btn btn-sm btn-ghost',
                    text: 'صفحه قبل',
                    disabled: page <= 1 ? 'true' : null,
                    onClick: () => {
                        State.reportsState.page = Math.max(1, page - 1);
                        AdminPanel.loadReportPreview();
                    },
                });

                const next = el('button', {
                    class: 'btn btn-sm btn-ghost',
                    text: 'صفحه بعد',
                    disabled: page >= totalPages ? 'true' : null,
                    onClick: () => {
                        State.reportsState.page = Math.min(totalPages, page + 1);
                        AdminPanel.loadReportPreview();
                    },
                });

                wrap.appendChild(prev);
                wrap.appendChild(el('span', {
                    class: 'pagination-current',
                    text: `${formatNumber(page)} / ${formatNumber(totalPages)}`,
                }));
                wrap.appendChild(next);

                paginationEl.appendChild(wrap);
            }
        }
    },

    async downloadReport() {
        const dataset = State.reportsState.dataset;
        const btn = $('#reports-download-btn');
        if (btn) btn.disabled = true;

        try {
            const url = CONFIG.API_BASE + `/admin/reports/${encodeURIComponent(dataset)}/export?` + AdminPanel._buildReportQuery();
            const resp = await fetch(url, {
                method: 'GET',
                credentials: 'include',
                headers: {
                    'Accept': 'text/csv',
                    [CONFIG.CSRF_HEADER]: API._csrf() || '',
                },
            });

            if (!resp.ok) {
                let msg = 'خروجی گرفتن ناموفق بود.';
                try {
                    const data = await resp.json();
                    msg = data.detail || data.message || msg;
                } catch (e) {}
                Toast.error(msg);
                return;
            }

            const blob = await resp.blob();
            const today = new Date().toISOString().slice(0, 10);
            const filename = `${dataset}_${today}.csv`;

            const u = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = u;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(u);

            Toast.success('فایل CSV دانلود شد.');
        } catch (err) {
            Toast.error(err.message || 'خطا در دانلود.');
        } finally {
            if (btn) btn.disabled = false;
        }
    },

    _timeState: {
        serverUtc: null,
        serverTimestamp: null,
        timezone: null,
        offsetSeconds: 0,
        nextResetTimestamp: null,
        nextResetSeconds: 0,
        liveTimer: null,
        health: null,
    },

    async loadTime() {
        try {
            const data = await API.get('/admin/time');
            const t = data.time;

            AdminPanel._timeState = {
                ...AdminPanel._timeState,
                serverUtc: t.server_utc,
                serverTimestamp: t.server_timestamp,
                timezone: t.timezone,
                offsetSeconds: t.timezone_offset_sec,
                nextResetTimestamp: t.next_reset.timestamp,
                nextResetSeconds: t.next_reset.seconds_until,
                health: t.drift_check,
            };

            setText('#time-kpi-persian', (t.date_persian && t.date_persian.formatted) ? t.date_persian.formatted : '—');
            setText('#time-kpi-gregorian', t.date_gregorian || '—');
            setText('#time-kpi-timezone', t.timezone || '—');
            setText('#time-kpi-offset', 'UTC' + (t.timezone_offset || ''));
            setText('#time-kpi-weekday', t.weekday_persian || '—');

            const healthEl = $('#time-kpi-health');
            if (healthEl && t.drift_check) {
                healthEl.textContent = t.drift_check.healthy
                    ? `✓ ${t.drift_check.drift_seconds} ثانیه`
                    : `⚠ ${t.drift_check.drift_seconds} ثانیه`;
                healthEl.style.color = t.drift_check.healthy
                    ? 'var(--color-success)'
                    : 'var(--color-error)';
            }

            setText('#time-live-date', `${t.weekday_persian || '—'} — ${(t.date_persian && t.date_persian.formatted) || '—'} (${t.date_gregorian || '—'})`);
            setText('#time-live-tz', `${t.timezone || '—'} (UTC${t.timezone_offset || ''})`);

            const s = t.settings || {};
            setText('#time-setting-timezone', s.timezone || '—');
            const hh = String(s.reset_hour ?? 0).padStart(2, '0');
            const mm = String(s.reset_minute ?? 0).padStart(2, '0');
            const ss = String(s.reset_second ?? 0).padStart(2, '0');
            setText('#time-setting-reset', `${hh}:${mm}:${ss}`);
            setText('#time-setting-ntp', s.ntp_server || '—');
            setText('#time-setting-sync', s.sync_enabled ? '✓ فعال' : '✗ غیرفعال');
            setText('#time-setting-offset', `${formatNumber(s.manual_offset_seconds || 0)} ثانیه`);

            setText('#time-countdown-reset-at', t.next_reset.local || '—');
            setText('#time-countdown-reset-hms', t.next_reset.hms || '—');

            AdminPanel.startLiveClock();

        } catch (err) {
            if (err && err.data && err.data.must_change_password) {
                AdminPanel._handleMustChangePassword();
                return;
            }
            Toast.error(err.message || 'خطا در بارگذاری اطلاعات زمان.');
        }
    },

    _handleMustChangePassword() {
        const banner = document.getElementById('must-change-password-banner');
        if (banner) banner.hidden = false;

        if (AdminPanel._pwdModalShown) return;
        AdminPanel._pwdModalShown = true;

        Toast.warning('برای دسترسی به بخش زمان، ابتدا رمز عبور پیش‌فرض را تغییر دهید.');

        setTimeout(() => {
            AdminPanel.openChangePasswordModal();
        }, 300);
    },

    startLiveClock() {
        if (AdminPanel._timeState.liveTimer) {
            clearInterval(AdminPanel._timeState.liveTimer);
            AdminPanel._timeState.liveTimer = null;
        }

        const state = AdminPanel._timeState;
        if (!state.serverTimestamp) return;

        const clientStart = Date.now();
        const serverStart = state.serverTimestamp * 1000;

        const tick = () => {
            const elapsed = Date.now() - clientStart;
            const serverNow = serverStart + elapsed;
            const localDate = new Date(serverNow + state.offsetSeconds * 1000);

            const hh = String(localDate.getUTCHours()).padStart(2, '0');
            const mm = String(localDate.getUTCMinutes()).padStart(2, '0');
            const ss = String(localDate.getUTCSeconds()).padStart(2, '0');
            setText('#time-live-clock', `${hh}:${mm}:${ss}`);

            if (state.nextResetTimestamp) {
                const resetMs = state.nextResetTimestamp * 1000;
                const diff = resetMs - serverNow;

                if (diff <= 0) {
                    setText('#time-countdown-display', '00:00:00');
                    AdminPanel.loadTime();
                    return;
                }

                const totalSec = Math.floor(diff / 1000);
                const cH = String(Math.floor(totalSec / 3600)).padStart(2, '0');
                const cM = String(Math.floor((totalSec % 3600) / 60)).padStart(2, '0');
                const cS = String(totalSec % 60).padStart(2, '0');
                setText('#time-countdown-display', `${cH}:${cM}:${cS}`);

                const totalDay = 86400;
                const pct = Math.min(100, Math.max(0, ((totalDay - totalSec) / totalDay) * 100));
                const bar = $('#time-countdown-bar');
                if (bar) bar.style.width = pct.toFixed(2) + '%';
            }
        };

        tick();
        AdminPanel._timeState.liveTimer = setInterval(tick, 1000);
    },

    stopLiveClock() {
        if (AdminPanel._timeState && AdminPanel._timeState.liveTimer) {
            clearInterval(AdminPanel._timeState.liveTimer);
            AdminPanel._timeState.liveTimer = null;
        }
    },

    openTimeSetupModal() {
        API.get('/admin/time').then(data => {
            const t = data.time;
            const s = t.settings || {};

            const elTz = $('#time-timezone');
            const elH  = $('#time-reset-hour');
            const elM  = $('#time-reset-minute');
            const elS  = $('#time-reset-second');
            const elN  = $('#time-ntp-server');
            const elSync = $('#time-sync-enabled');
            const elOff  = $('#time-manual-offset');

            if (elTz) elTz.value = s.timezone || 'Asia/Tehran';
            if (elH)  elH.value  = s.reset_hour ?? 0;
            if (elM)  elM.value  = s.reset_minute ?? 0;
            if (elS)  elS.value  = s.reset_second ?? 0;
            if (elN)  elN.value  = s.ntp_server || 'pool.ntp.org';
            if (elSync) elSync.checked = !!s.sync_enabled;
            if (elOff)  elOff.value = s.manual_offset_seconds || 0;

            Modal.open('modal-time-setup');
        }).catch(err => {
            if (err && err.data && err.data.must_change_password) {
                AdminPanel._handleMustChangePassword();
                return;
            }
            Toast.error(err.message);
        });
    },

    async submitTimeSetup(e) {
        if (e) e.preventDefault();

        const elTz   = $('#time-timezone');
        const elH    = $('#time-reset-hour');
        const elM    = $('#time-reset-minute');
        const elS    = $('#time-reset-second');
        const elN    = $('#time-ntp-server');
        const elSync = $('#time-sync-enabled');
        const elOff  = $('#time-manual-offset');

        const payload = {
            timezone: (elTz ? elTz.value : '').trim(),
            reset_hour: parseInt(elH ? elH.value : '0', 10),
            reset_minute: parseInt(elM ? elM.value : '0', 10),
            reset_second: parseInt(elS ? elS.value : '0', 10),
            ntp_server: (elN && elN.value.trim()) ? elN.value.trim() : null,
            sync_enabled: !!(elSync && elSync.checked),
            manual_offset_seconds: parseInt(elOff ? elOff.value : '0', 10) || 0,
        };

        if (!payload.timezone) {
            Toast.warning('منطقه زمانی الزامی است.');
            return;
        }

        try {
            const res = await API.post('/admin/time/setup', payload);
            Toast.success(res.message || 'تنظیمات ذخیره شد.');
            Modal.close('modal-time-setup');
            AdminPanel.loadTime();
        } catch (err) {
            if (err && err.data && err.data.must_change_password) {
                AdminPanel._handleMustChangePassword();
                return;
            }
            Toast.error(err.message);
        }
    },

    async syncServerTime() {
        const btn = $('#time-sync-btn');
        if (btn) btn.disabled = true;

        try {
            const res = await API.post('/admin/time/sync', {});
            const s = (res && res.sync) ? res.sync : {};
            if (s.success) {
                Toast.success(s.message || 'بررسی همگام‌سازی انجام شد.');
            } else {
                Toast.warning(s.message || 'نتیجه‌ای برای همگام‌سازی یافت نشد.');
            }
            AdminPanel.loadTime();
        } catch (err) {
            if (err && err.data && err.data.must_change_password) {
                AdminPanel._handleMustChangePassword();
                return;
            }
            Toast.error(err.message);
        } finally {
            if (btn) btn.disabled = false;
        }
    },
};

/* =====================================================================
   16. MARKDOWN MINI-RENDERER
   ===================================================================== */

function renderMarkdown(text) {
    if (!text) return '';
    let s = escapeHtml(text);

    const codeBlocks = [];

    s = s.replace(/```([\s\S]*?)```/g, (m, c) => {
        const idx = codeBlocks.length;
        codeBlocks.push(`<pre class="md-pre"><code>${c.trim()}</code></pre>`);
        return `\x00DANOVA_CODEBLOCK_${idx}\x00`;
    });

    s = s.replace(/`([^`\n]+)`/g, '<code class="md-code">$1</code>');

    s = s.replace(/^### (.*)$/gm, '<h4 class="md-h4">$1</h4>');
    s = s.replace(/^## (.*)$/gm, '<h3 class="md-h3">$1</h3>');
    s = s.replace(/^# (.*)$/gm, '<h2 class="md-h2">$1</h2>');

    s = s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');

    s = s.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (match, label, url) => {
        try {
            const cleaned = String(url).replace(/&amp;/g, '&').trim();
            // ✅ FIX: chک صریح پروتکل قبل از new URL (case-insensitive)
            const lower = cleaned.toLowerCase();
            if (lower.startsWith('javascript:') ||
                lower.startsWith('data:') ||
                lower.startsWith('vbscript:') ||
                lower.startsWith('file:')) {
                return match;
            }
            const u = new URL(cleaned);
            if (u.protocol !== 'http:' && u.protocol !== 'https:') return match;
            const safeHref = escapeHtml(u.href);
            const safeLabel = escapeHtml(label);
            return `<a href="${safeHref}" target="_blank" rel="noopener noreferrer">${safeLabel}</a>`;
        } catch (e) {
            return match;
        }
    });

    s = s.replace(/(^|\n)((?:[-*] .+\n?)+)/g, (m, pre, block) => {
        const items = block.trim().split('\n')
            .map(l => l.replace(/^[-*] /, '').trim())
            .map(l => `<li>${l}</li>`)
            .join('');
        return `${pre}<ul class="md-ul">${items}</ul>`;
    });

    s = s.replace(/(^|\n)((?:\d+\. .+\n?)+)/g, (m, pre, block) => {
        const items = block.trim().split('\n')
            .map(l => l.replace(/^\d+\. /, '').trim())
            .map(l => `<li>${l}</li>`)
            .join('');
        return `${pre}<ol class="md-ol">${items}</ol>`;
    });

    s = s.replace(/\n/g, '<br>');

    s = s.replace(/\x00DANOVA_CODEBLOCK_(\d+)\x00/g, (m, i) => {
        const idx = parseInt(i, 10);
        return codeBlocks[idx] !== undefined ? codeBlocks[idx] : m;
    });

    return s;
}

/* =====================================================================
   17. VIDEO PLAYER
   ===================================================================== */

const VideoPlayer = {
    _initialized: false,
    _timeupdateHandler: null,
    _pauseHandler: null,
    _endedHandler: null,
    _prevHandler: null,
    _nextHandler: null,

    init() {
        const video = document.getElementById('video-player');
        if (!video) return;

        if (this._initialized) {
            console.warn('[VideoPlayer] init() already called — skipping.');
            return;
        }
        // ✅ PATCH: VideoPlayer._patched_teardown
        this._teardown();
        this._initialized = true;

        this._timeupdateHandler = () => {
            if (!State.currentVideo) return;
            const now = Date.now();
            if (!State._lastProgressSent) State._lastProgressSent = 0;
            if (now - State._lastProgressSent < CONFIG.VIDEO_PROGRESS_INTERVAL) return;
            State._lastProgressSent = now;

            const pos = Math.floor(video.currentTime);
            const dur = video.duration || State.currentVideo.duration || 0;
            const pct = dur > 0 ? Math.min(100, (pos / dur) * 100) : 0;

            if (Math.abs(pos - State.lastSentPosition) < CONFIG.VIDEO_PROGRESS_MIN_DELTA &&
                Math.abs(pct - State.lastSentPct) < 1) return;

            State.lastSentPosition = pos;
            State.lastSentPct = pct;
            VideoPlayer._sendProgress(State.currentVideo.id, pos, pct);
        };
        video.addEventListener('timeupdate', this._timeupdateHandler);

        this._pauseHandler = () => {
            if (!State.currentVideo) return;
            const pos = Math.floor(video.currentTime);
            const dur = video.duration || State.currentVideo.duration || 0;
            const pct = dur > 0 ? Math.min(100, (pos / dur) * 100) : 0;
            VideoPlayer._sendProgress(State.currentVideo.id, pos, pct);
        };
        video.addEventListener('pause', this._pauseHandler);

        this._endedHandler = () => {
            if (!State.currentVideo) return;
            VideoPlayer._sendProgress(State.currentVideo.id, Math.floor(video.duration || 0), 100);
        };
        video.addEventListener('ended', this._endedHandler);

        const prevBtn = document.getElementById('video-player-prev-btn');
        if (prevBtn) {
            this._prevHandler = () => VideoPlayer._navigateCollection(-1);
            prevBtn.addEventListener('click', this._prevHandler);
        }
        const nextBtn = document.getElementById('video-player-next-btn');
        if (nextBtn) {
            this._nextHandler = () => VideoPlayer._navigateCollection(1);
            nextBtn.addEventListener('click', this._nextHandler);
        }
    },

    _teardown() {
        const video = document.getElementById('video-player');
        if (video) {
            if (this._timeupdateHandler) {
                try { video.removeEventListener('timeupdate', this._timeupdateHandler); } catch (e) {}
                this._timeupdateHandler = null;
            }
            if (this._pauseHandler) {
                try { video.removeEventListener('pause', this._pauseHandler); } catch (e) {}
                this._pauseHandler = null;
            }
            if (this._endedHandler) {
                try { video.removeEventListener('ended', this._endedHandler); } catch (e) {}
                this._endedHandler = null;
            }
        }

        const prevBtn = document.getElementById('video-player-prev-btn');
        if (prevBtn && this._prevHandler) {
            try { prevBtn.removeEventListener('click', this._prevHandler); } catch (e) {}
            this._prevHandler = null;
        }
        const nextBtn = document.getElementById('video-player-next-btn');
        if (nextBtn && this._nextHandler) {
            try { nextBtn.removeEventListener('click', this._nextHandler); } catch (e) {}
            this._nextHandler = null;
        }

        this._initialized = false;
    },

    open(video, collectionContext = null) {
        const modal = document.getElementById('video-player-modal');
        if (!modal) return;

        State.currentVideo = video;
        State.lastSentPosition = 0;
        State.lastSentPct = 0;
        State._lastProgressSent = 0;

        if (collectionContext) {
            State.currentCollection = {
                id: collectionContext.collectionId,
                title: collectionContext.collectionTitle,
                videos: collectionContext.videos || [],
            };
            State.currentCollectionVideoIndex = collectionContext.index ?? -1;
        } else {
            State.currentCollection = null;
            State.currentCollectionVideoIndex = -1;
        }

        setText('#video-player-title', video.title);
        setText('#video-player-description', video.description || '');

        VideoPlayer._renderCollectionNav();

        const player = document.getElementById('video-player');
        if (player) {
            if (player._onLoadedMeta) {
                player.removeEventListener('loadedmetadata', player._onLoadedMeta);
                player._onLoadedMeta = null;
            }

            player.src = CONFIG.API_BASE + `/videos/${video.id}/stream`;
            const restorePos = video.progress && video.progress.last_position
                ? Math.max(0, video.progress.last_position - 2) : 0;

            player._onLoadedMeta = function onMeta() {
                if (restorePos > 0 && restorePos < (player.duration || Infinity)) {
                    try { player.currentTime = restorePos; } catch (e) {}
                }
                player.play().catch(() => {});
            };
            player.addEventListener('loadedmetadata', player._onLoadedMeta, { once: true });
        }

        Modal.open('video-player-modal');
    },

    _renderCollectionNav() {
        const navWrap = document.getElementById('video-player-collection-nav');
        if (!navWrap) return;

        if (!State.currentCollection || !State.currentCollection.videos.length) {
            navWrap.hidden = true;
            return;
        }

        navWrap.hidden = false;

        const idx = State.currentCollectionVideoIndex;
        const total = State.currentCollection.videos.length;

        const prevBtn = document.getElementById('video-player-prev-btn');
        const nextBtn = document.getElementById('video-player-next-btn');
        const posEl = document.getElementById('video-player-collection-pos');

        if (prevBtn) prevBtn.disabled = idx <= 0;
        if (nextBtn) nextBtn.disabled = idx >= total - 1;
        if (posEl) posEl.textContent = `${formatNumber(idx + 1)} از ${formatNumber(total)} • ${State.currentCollection.title}`;
    },

    _navigateCollection(delta) {
        if (!State.currentCollection || !State.currentCollection.videos.length) return;

        const newIdx = State.currentCollectionVideoIndex + delta;
        if (newIdx < 0 || newIdx >= State.currentCollection.videos.length) return;

        const video = State.currentCollection.videos[newIdx];
        State.currentCollectionVideoIndex = newIdx;

        State.currentVideo = video;
        State.lastSentPosition = 0;
        State.lastSentPct = 0;
        State._lastProgressSent = 0;

        setText('#video-player-title', video.title);
        setText('#video-player-description', video.description || '');

        VideoPlayer._renderCollectionNav();

        const player = document.getElementById('video-player');
        if (player) {
            if (player._onLoadedMeta) {
                player.removeEventListener('loadedmetadata', player._onLoadedMeta);
                player._onLoadedMeta = null;
            }

            player.src = CONFIG.API_BASE + `/videos/${video.id}/stream`;
            const restorePos = video.progress && video.progress.last_position
                ? Math.max(0, video.progress.last_position - 2) : 0;

            player._onLoadedMeta = function onMeta() {
                if (restorePos > 0 && restorePos < (player.duration || Infinity)) {
                    try { player.currentTime = restorePos; } catch (e) {}
                }
                player.play().catch(() => {});
            };
            player.addEventListener('loadedmetadata', player._onLoadedMeta, { once: true });
        }
    },

    close() {
        const player = document.getElementById('video-player');
        if (player) {
            if (State.currentVideo && player.duration) {
                const pos = Math.floor(player.currentTime);
                const pct = Math.min(100, (pos / player.duration) * 100);
                VideoPlayer._sendProgress(State.currentVideo.id, pos, pct);
            }
            try { player.pause(); } catch (e) {}
            player.removeAttribute('src');
            try { player.load(); } catch (e) {}

            if (player._onLoadedMeta) {
                player.removeEventListener('loadedmetadata', player._onLoadedMeta);
                player._onLoadedMeta = null;
            }
        }
        State.currentVideo = null;
        State.currentCollection = null;
        State.currentCollectionVideoIndex = -1;
    },

    async _sendProgress(videoId, position, percentage) {
        try {
            await API.post(`/videos/${videoId}/progress`, {
                last_position: position,
                watch_percentage: percentage,
                watched_seconds: position,
            });
        } catch (e) {}
    },
};

/* =====================================================================
   18. BOOTSTRAP
   ===================================================================== */

async function init() {
    Theme.init();
    Modal.init();
    initSidebar();
    initProfileMenu();
    initLogin();

    const vpModal = document.getElementById('video-player-modal');
    if (vpModal) {
        const observer = new MutationObserver(() => {
            if (vpModal.hidden && State.currentVideo) {
                VideoPlayer.close();
            }
        });
        observer.observe(vpModal, { attributes: true, attributeFilter: ['hidden'] });
    }

    const ok = await Auth.checkSession();
    if (ok && State.currentUser) {
        Router.handleLoginSuccess(State.currentUser);
    } else {
        Router.show('login');
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

/* =====================================================================
   LIFECYCLE MODULE (سابقاً lifecycle.js)
   ===================================================================== */

(function () {

    /* =================================================================
       UTILITIES
       ================================================================= */

    const _get = (name, fallback) => {
        if (typeof window[name] !== 'undefined') return window[name];
        return fallback;
    };

    const $  = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    // ✅ Bug #44 FIX
    const escapeHtml = (typeof window !== 'undefined' && typeof window.escapeHtml === 'function')
        ? window.escapeHtml
        : (str) => {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        };

    const el = (tag, attrs = {}, children = []) => {
        const node = document.createElement(tag);
        Object.entries(attrs || {}).forEach(([k, v]) => {
            if (v === undefined || v === null) return;
            if (k === 'class') node.className = v;
            else if (k === 'text') node.textContent = v;
            else if (k === 'html') node.innerHTML = v;
            else if (k === 'style' && typeof v === 'object') Object.assign(node.style, v);
            else if (k.startsWith('on') && typeof v === 'function') {
                node.addEventListener(k.slice(2).toLowerCase(), v);
            } else if (k === 'dataset' && typeof v === 'object') {
                Object.entries(v).forEach(([dk, dv]) => node.dataset[dk] = dv);
            } else node.setAttribute(k, v);
        });
        const list = Array.isArray(children) ? children : (children ? [children] : []);
        list.forEach(c => {
            if (c === null || c === undefined || c === false) return;
            if (typeof c === 'string' || typeof c === 'number') {
                node.appendChild(document.createTextNode(String(c)));
            } else if (c instanceof Node) {
                node.appendChild(c);
            }
        });
        return node;
    };

    const apiGet = (path) => {
        if (window.API && typeof window.API.get === 'function') {
            return window.API.get(path);
        }
        return Promise.reject(new Error('API client not available'));
    };
    const apiPost = (path, body) => {
        if (window.API && typeof window.API.post === 'function') {
            return window.API.post(path, body);
        }
        return Promise.reject(new Error('API client not available'));
    };

    const toast = {
        success: (m) => { if (window.Toast) window.Toast.success(m); else console.log('[OK]', m); },
        error:   (m) => { if (window.Toast) window.Toast.error(m);   else console.error('[ERR]', m); },
        warning: (m) => { if (window.Toast) window.Toast.warning(m); else console.warn('[WARN]', m); },
        info:    (m) => { if (window.Toast) window.Toast.info(m);    else console.log('[INFO]', m); },
    };

    const loading = {
        show: (text) => { if (window.Loading) window.Loading.show(text); },
        hide: () => { if (window.Loading) window.Loading.hide(); },
    };

    const modal = {
        confirm: (message, onOk, options) => {
            if (window.Modal && typeof window.Modal.confirm === 'function') {
                window.Modal.confirm(message, onOk, options);
            } else if (window.confirm(message)) {
                onOk && onOk();
            }
        },
        open:  (id) => { if (window.Modal) window.Modal.open(id); },
        close: (id) => { if (window.Modal) window.Modal.close(id); },
    };

    const formatDate = (iso) => {
        if (!iso) return '—';
        try {
            const d = new Date(iso.replace(' ', 'T'));
            return d.toLocaleDateString('fa-IR');
        } catch (e) { return '—'; }
    };

    const formatDateTime = (iso) => {
        if (!iso) return '—';
        try {
            const d = new Date(iso.replace(' ', 'T'));
            return d.toLocaleString('fa-IR', {
                year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit',
            });
        } catch (e) { return '—'; }
    };

    const formatRelativeTime = (iso) => {
        if (!iso) return '—';
        try {
            const d = new Date(iso.replace(' ', 'T'));
            const diffMs = Date.now() - d.getTime();
            const sec = Math.floor(diffMs / 1000);
            if (sec < 60) return 'همین حالا';
            if (sec < 3600) return Math.floor(sec / 60) + ' دقیقه پیش';
            if (sec < 86400) return Math.floor(sec / 3600) + ' ساعت پیش';
            if (sec < 2592000) return Math.floor(sec / 86400) + ' روز پیش';
            return formatDate(iso);
        } catch (e) { return '—'; }
    };

    /**
     * ✅ v3.15.2 (BUG FIX): Safe formatBytes — handles strings, null, NaN
     *
     * تبدیل امن بایت به رشته خوانا (B, KB, MB, GB, TB, PB).
     */
    const formatBytes = (bytes) => {
        // مرحله ۱: تبدیل امن به عدد
        let num;

        if (typeof bytes === 'number') {
            num = bytes;
        } else if (typeof bytes === 'string') {
            const cleaned = bytes.trim().replace(/[^\d.\-eE+]/g, '');
            num = parseFloat(cleaned);
        } else {
            num = Number(bytes);
        }

        // مرحله ۲: بررسی اعتبار عدد
        if (!Number.isFinite(num) || num < 0) {
            return '0 B';
        }

        if (num === 0) {
            return '0 B';
        }

        // مرحله ۳: محاسبه واحد مناسب
        const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        const k = 1024;
        let i = 0;
        let n = num;

        while (n >= k && i < units.length - 1) {
            n /= k;
            i++;
        }

        // مرحله ۴: فرمت نهایی
        const decimals = i === 0 ? 0 : 2;
        return n.toFixed(decimals) + ' ' + units[i];
    };

    const formatNumber = (n) => {
        if (n === null || n === undefined || n === '') return '—';
        const num = Number(n);
        if (Number.isNaN(num)) return '—';
        return num.toLocaleString('fa-IR');
    };

    const TICKET_STATUS_LABELS = {
        open:         'باز',
        in_progress:  'در حال بررسی',
        waiting_user: 'در انتظار پاسخ شما',
        closed:       'بسته‌شده',
    };

    const TICKET_PRIORITY_LABELS = {
        low:    'کم',
        normal: 'معمولی',
        high:   'بالا',
        urgent: 'فوری',
    };

    const TICKET_CATEGORY_LABELS = {
        general:   'عمومی',
        technical: 'فنی',
        content:   'محتوا',
        ai:        'هوش مصنوعی',
        account:   'حساب کاربری',
        other:     'سایر',
    };

    const TICKET_CATEGORY_ICONS = {
        general:   '💬',
        technical: '🔧',
        content:   '📚',
        ai:        '🤖',
        account:   '👤',
        other:     '📌',
    };

    /* =================================================================
       MODULE 1: StudentTickets
       ================================================================= */

    const StudentTickets = {
        _initialized: false,
        _tickets: [],
        _currentTicketId: null,
        _currentTicket: null,
        _filterStatus: 'all',

        init() {
            if (this._initialized) return;
            this._initialized = true;

            this._bindGlobalHandlers();
        },

        _bindGlobalHandlers() {
            document.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-ticket-action]');
                if (!btn) return;
                const action = btn.getAttribute('data-ticket-action');
                if (action === 'open-create-modal') this.openCreateModal();
                if (action === 'refresh-list') this.load();
                if (action === 'close-ticket') this.closeCurrent();
                if (action === 'send-reply') this.sendReply();
            });

            document.addEventListener('click', (e) => {
                const chip = e.target.closest('[data-ticket-filter]');
                if (!chip) return;
                const status = chip.getAttribute('data-ticket-filter');
                this._filterStatus = status;
                $$('[data-ticket-filter]').forEach(c => c.classList.remove('tickets-filter-chip-active'));
                chip.classList.add('tickets-filter-chip-active');
                this.renderList();
            });

            document.addEventListener('click', (e) => {
                const item = e.target.closest('[data-ticket-id]');
                if (!item) return;
                const ticketId = item.getAttribute('data-ticket-id');
                if (ticketId) this.openTicket(ticketId);
            });

            document.addEventListener('keydown', (e) => {
                if (e.target && e.target.id === 'ticket-reply-input') {
                    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                        e.preventDefault();
                        this.sendReply();
                    }
                }
            });
        },

        async load() {
            this.init();

            const listEl = document.querySelector('[data-tickets-list]');
            if (!listEl) return;

            listEl.innerHTML = `
                <div class="tickets-list-empty">
                    <div class="tickets-list-empty-icon">🎫</div>
                    <div class="tickets-list-empty-title">در حال بارگذاری...</div>
                </div>
            `;

            try {
                const data = await apiGet('/student/tickets?page_size=100');
                this._tickets = data.tickets || [];
                this.renderList();
            } catch (err) {
                listEl.innerHTML = `
                    <div class="tickets-list-empty">
                        <div class="tickets-list-empty-icon">⚠</div>
                        <div class="tickets-list-empty-title">خطا در بارگذاری</div>
                        <div class="tickets-list-empty-text">${escapeHtml(err.message || '')}</div>
                    </div>
                `;
            }
        },

        renderList() {
            const listEl = document.querySelector('[data-tickets-list]');
            if (!listEl) return;

            let tickets = this._tickets;
            if (this._filterStatus && this._filterStatus !== 'all') {
                tickets = tickets.filter(t => t.status === this._filterStatus);
            }

            if (!tickets.length) {
                listEl.innerHTML = `
                    <div class="tickets-list-empty">
                        <div class="tickets-list-empty-icon">🎫</div>
                        <div class="tickets-list-empty-title">تیکتی یافت نشد</div>
                        <div class="tickets-list-empty-text">اگر سوال یا مشکلی دارید، تیکت جدید ایجاد کنید.</div>
                    </div>
                `;
                return;
            }

            listEl.innerHTML = '';
            tickets.forEach(t => {
                listEl.appendChild(this._renderItem(t));
            });
        },

        _renderItem(t) {
            const isActive = this._currentTicketId === t.id;
            const statusClass = `ticket-status ticket-status-${t.status}`;
            const statusLabel = TICKET_STATUS_LABELS[t.status] || t.status;
            const categoryIcon = TICKET_CATEGORY_ICONS[t.category] || '💬';

            const item = el('button', {
                type: 'button',
                class: 'ticket-item' + (isActive ? ' ticket-item-active' : ''),
                dataset: { ticketId: t.id },
            });

            const top = el('div', { class: 'ticket-item-top' });
            top.appendChild(el('div', { class: 'ticket-item-subject', text: t.subject }));
            top.appendChild(el('span', { class: statusClass, text: statusLabel }));
            item.appendChild(top);

            const meta = el('div', { class: 'ticket-item-meta' });
            meta.appendChild(el('span', { text: `${categoryIcon} ${TICKET_CATEGORY_LABELS[t.category] || t.category}` }));
            meta.appendChild(el('span', { text: `• ${formatRelativeTime(t.last_activity_at)}` }));
            if (t.message_count > 0) {
                meta.appendChild(el('span', { text: `• ${formatNumber(t.message_count)} پیام` }));
            }
            item.appendChild(meta);

            return item;
        },

        async openTicket(ticketId) {
            this._currentTicketId = ticketId;
            this.renderList();

            const mainEl = document.querySelector('[data-tickets-main]');
            if (!mainEl) return;

            mainEl.innerHTML = `
                <div class="storage-loading">
                    <span class="spinner spinner-md"></span>
                    <span class="text-muted">در حال بارگذاری تیکت...</span>
                </div>
            `;

            try {
                const data = await apiGet(`/student/tickets/${encodeURIComponent(ticketId)}`);
                this._currentTicket = data.ticket;
                this._renderTicketDetail(data.ticket, data.messages || []);
            } catch (err) {
                mainEl.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon">⚠</div>
                        <div class="empty-title">خطا در بارگذاری</div>
                        <div class="empty-text">${escapeHtml(err.message || '')}</div>
                    </div>
                `;
            }
        },

        _renderTicketDetail(ticket, messages) {
            const mainEl = document.querySelector('[data-tickets-main]');
            if (!mainEl) return;

            mainEl.innerHTML = '';

            const header = el('div', { class: 'tickets-main-header' });
            const titleWrap = el('div', { class: 'tickets-main-title-wrap' });
            titleWrap.appendChild(el('div', { class: 'tickets-main-title', text: ticket.subject }));

            const meta = el('div', { class: 'tickets-main-meta' });
            const statusClass = `ticket-status ticket-status-${ticket.status}`;
            meta.appendChild(el('span', { class: statusClass, text: TICKET_STATUS_LABELS[ticket.status] || ticket.status }));
            meta.appendChild(el('span', { text: `• ${TICKET_CATEGORY_LABELS[ticket.category] || ticket.category}` }));
            meta.appendChild(el('span', { text: `• ${formatDateTime(ticket.created_at)}` }));
            titleWrap.appendChild(meta);
            header.appendChild(titleWrap);

            const actions = el('div', { class: 'tickets-main-actions' });
            if (ticket.status !== 'closed') {
                actions.appendChild(el('button', {
                    type: 'button',
                    class: 'btn btn-sm btn-ghost',
                    text: '🔒 بستن تیکت',
                    dataset: { ticketAction: 'close-ticket' },
                }));
            }
            actions.appendChild(el('button', {
                type: 'button',
                class: 'btn btn-sm btn-ghost',
                text: '↻ بازخوانی',
                dataset: { ticketAction: 'refresh-list' },
            }));
            header.appendChild(actions);

            mainEl.appendChild(header);

            const thread = el('div', { class: 'ticket-thread' });
            messages.forEach(m => thread.appendChild(this._renderMessage(m)));

            if (!messages.length) {
                thread.appendChild(el('div', { class: 'empty-state empty-state-sm' }, [
                    el('div', { class: 'empty-text', text: 'هنوز پیامی در این تیکت نیست.' }),
                ]));
            }

            mainEl.appendChild(thread);

            if (ticket.status !== 'closed') {
                const form = el('div', { class: 'ticket-reply-form' });
                const textarea = el('textarea', {
                    id: 'ticket-reply-input',
                    class: 'ticket-reply-input',
                    placeholder: 'پاسخ خود را بنویسید...',
                    rows: '3',
                    maxlength: '5000',
                });
                form.appendChild(textarea);

                const actionsRow = el('div', { class: 'ticket-reply-actions' });
                actionsRow.appendChild(el('span', {
                    class: 'ticket-reply-hint',
                    text: 'Ctrl+Enter برای ارسال سریع',
                }));
                actionsRow.appendChild(el('button', {
                    type: 'button',
                    class: 'btn btn-sm btn-primary',
                    text: '➤ ارسال پاسخ',
                    dataset: { ticketAction: 'send-reply' },
                }));
                form.appendChild(actionsRow);
                mainEl.appendChild(form);
            } else {
                mainEl.appendChild(el('div', {
                    class: 'ticket-closed-notice',
                    text: 'این تیکت بسته شده است. برای ادامه گفتگو، تیکت جدیدی ایجاد کنید.',
                }));
            }

            const threadEl = mainEl.querySelector('.ticket-thread');
            if (threadEl) threadEl.scrollTop = threadEl.scrollHeight;
        },

        _renderMessage(m) {
            const isAdmin = m.author_role === 'admin';
            const cls = 'ticket-message ' + (isAdmin ? 'ticket-message-admin' : 'ticket-message-user');
            const wrap = el('div', { class: cls });

            const bubble = el('div', { class: 'ticket-message-bubble', text: m.body });
            wrap.appendChild(bubble);

            const meta = el('div', { class: 'ticket-message-meta' });
            const badgeClass = 'ticket-author-badge' + (isAdmin ? ' ticket-author-badge-admin' : '');
            meta.appendChild(el('span', {
                class: badgeClass,
                text: isAdmin ? '👔 پشتیبانی' : '👤 شما',
            }));
            meta.appendChild(el('span', { text: formatDateTime(m.created_at) }));
            wrap.appendChild(meta);

            return wrap;
        },

        openCreateModal() {
            const modalId = 'modal-ticket-create';
            if (!document.getElementById(modalId)) {
                this._buildCreateModal(modalId);
            }

            const form = document.getElementById('form-ticket-create');
            if (form) form.reset();
            const formErr = document.getElementById('ticket-create-error');
            if (formErr) { formErr.textContent = ''; formErr.hidden = true; }

            $$('[data-ticket-category]').forEach(c => {
                c.classList.toggle('ticket-form-category-item-active',
                    c.getAttribute('data-ticket-category') === 'general');
            });
            const catInput = document.getElementById('ticket-create-category');
            if (catInput) catInput.value = 'general';

            modal.open(modalId);
        },

        _buildCreateModal(modalId) {
            const overlay = el('div', { id: modalId, class: 'modal', hidden: 'hidden' });

            const backdrop = el('div', { class: 'modal-backdrop', 'data-modal-close': '' });
            overlay.appendChild(backdrop);

            const content = el('div', { class: 'modal-content' });

            const header = el('div', { class: 'modal-header' });
            header.appendChild(el('h3', { class: 'modal-title', text: '🎫 ایجاد تیکت جدید' }));
            header.appendChild(el('button', {
                type: 'button', class: 'modal-close', 'data-modal-close': '', 'aria-label': 'بستن', text: '✕',
            }));
            content.appendChild(header);

            const form = el('form', { id: 'form-ticket-create', class: 'modal-body' });

            const titleGroup = el('div', { class: 'form-group' });
            titleGroup.appendChild(el('label', { for: 'ticket-create-subject', class: 'form-label', text: 'عنوان *' }));
            titleGroup.appendChild(el('input', {
                type: 'text', id: 'ticket-create-subject', class: 'form-input',
                required: 'required', minlength: '3', maxlength: '200',
                placeholder: 'موضوع تیکت را کوتاه بنویسید',
            }));
            form.appendChild(titleGroup);

            const catGroup = el('div', { class: 'form-group' });
            catGroup.appendChild(el('label', { class: 'form-label', text: 'دسته‌بندی *' }));
            const catGrid = el('div', { class: 'ticket-form-category-grid' });
            const categories = [
                { key: 'general',   label: 'عمومی',         icon: '💬' },
                { key: 'technical', label: 'فنی',           icon: '🔧' },
                { key: 'content',   label: 'محتوا',         icon: '📚' },
                { key: 'ai',        label: 'هوش مصنوعی',    icon: '🤖' },
                { key: 'account',   label: 'حساب کاربری',   icon: '👤' },
                { key: 'other',     label: 'سایر',          icon: '📌' },
            ];
            categories.forEach(c => {
                const isActive = c.key === 'general';
                const item = el('button', {
                    type: 'button',
                    class: 'ticket-form-category-item' + (isActive ? ' ticket-form-category-item-active' : ''),
                    dataset: { ticketCategory: c.key },
                });
                item.appendChild(el('span', { class: 'ticket-form-category-item-icon', text: c.icon }));
                item.appendChild(el('span', { text: c.label }));

                item.addEventListener('click', () => {
                    $$('[data-ticket-category]', catGrid).forEach(x =>
                        x.classList.remove('ticket-form-category-item-active'));
                    item.classList.add('ticket-form-category-item-active');
                    const hidden = document.getElementById('ticket-create-category');
                    if (hidden) hidden.value = c.key;
                });

                catGrid.appendChild(item);
            });
            catGroup.appendChild(catGrid);
            catGroup.appendChild(el('input', {
                type: 'hidden', id: 'ticket-create-category', value: 'general',
            }));
            form.appendChild(catGroup);

            const bodyGroup = el('div', { class: 'form-group' });
            bodyGroup.appendChild(el('label', { for: 'ticket-create-body', class: 'form-label', text: 'متن پیام *' }));
            bodyGroup.appendChild(el('textarea', {
                id: 'ticket-create-body', class: 'form-input',
                required: 'required', minlength: '5', maxlength: '5000',
                rows: '6', placeholder: 'جزئیات مشکل یا سوال خود را بنویسید...',
            }));
            form.appendChild(bodyGroup);

            const errEl = el('div', {
                id: 'ticket-create-error',
                class: 'alert alert-error',
                hidden: 'hidden',
                style: { marginTop: '8px' },
            });
            form.appendChild(errEl);

            content.appendChild(form);

            const footer = el('div', { class: 'modal-footer' });
            footer.appendChild(el('button', {
                type: 'button', class: 'btn btn-ghost', 'data-modal-close': '', text: 'انصراف',
            }));
            const submitBtn = el('button', {
                type: 'button', class: 'btn btn-primary', id: 'ticket-create-submit', text: 'ایجاد تیکت',
            });
            submitBtn.addEventListener('click', () => this._submitCreate());
            footer.appendChild(submitBtn);
            content.appendChild(footer);

            overlay.appendChild(content);
            document.body.appendChild(overlay);
        },

        async _submitCreate() {
            const subject = (document.getElementById('ticket-create-subject')?.value || '').trim();
            const category = (document.getElementById('ticket-create-category')?.value || 'general').trim();
            const body = (document.getElementById('ticket-create-body')?.value || '').trim();
            const errEl = document.getElementById('ticket-create-error');
            const submitBtn = document.getElementById('ticket-create-submit');

            const showError = (msg) => {
                if (errEl) { errEl.textContent = msg; errEl.hidden = false; }
            };

            if (subject.length < 3) return showError('عنوان باید حداقل ۳ کاراکتر باشد.');
            if (body.length < 5) return showError('متن پیام باید حداقل ۵ کاراکتر باشد.');
            if (errEl) errEl.hidden = true;

            if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'در حال ارسال...'; }

            try {
                await apiPost('/student/tickets', {
                    subject, category, body, priority: 'normal',
                });
                toast.success('تیکت با موفقیت ایجاد شد.');
                modal.close('modal-ticket-create');
                await this.load();
            } catch (err) {
                showError(err.message || 'خطا در ایجاد تیکت.');
            } finally {
                if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'ایجاد تیکت'; }
            }
        },

        async sendReply() {
            const input = document.getElementById('ticket-reply-input');
            if (!input || !this._currentTicketId) return;

            const body = (input.value || '').trim();
            if (body.length < 1) {
                toast.warning('متن پاسخ را وارد کنید.');
                return;
            }

            input.disabled = true;

            try {
                await apiPost(`/student/tickets/${encodeURIComponent(this._currentTicketId)}/reply`, { body });
                toast.success('پاسخ ارسال شد.');
                input.value = '';
                await this.openTicket(this._currentTicketId);
                await this.load();
            } catch (err) {
                toast.error(err.message || 'خطا در ارسال پاسخ.');
            } finally {
                input.disabled = false;
            }
        },

        closeCurrent() {
            if (!this._currentTicketId) return;
            const self = this;
            modal.confirm(
                'آیا از بستن این تیکت مطمئن هستید؟ پس از بستن، امکان پاسخ‌دهی وجود نخواهد داشت.',
                async () => {
                    try {
                        await apiPost(`/student/tickets/${encodeURIComponent(self._currentTicketId)}/close`, {});
                        toast.success('تیکت بسته شد.');
                        await self.openTicket(self._currentTicketId);
                        await self.load();
                    } catch (err) {
                        toast.error(err.message || 'خطا در بستن تیکت.');
                    }
                },
                { title: 'بستن تیکت', okText: 'بستن', danger: false }
            );
        },
    };

    /* =================================================================
       MODULE 2: StudentBulkDelete
       ================================================================= */

    const StudentBulkDelete = {
        _initialized: false,
        _selected: new Set(),
        _allIds: [],
        _bulkMode: false,

        init() {
            if (this._initialized) return;
            this._initialized = true;

            document.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-bulk-action]');
                if (!btn) return;
                const action = btn.getAttribute('data-bulk-action');
                if (action === 'toggle-mode')    this.toggleMode();
                if (action === 'select-all')     this.selectAll();
                if (action === 'clear-selection') this.clearSelection();
                if (action === 'delete-selected') this.confirmDelete();
            });

            document.addEventListener('change', (e) => {
                const cb = e.target.closest('[data-bulk-checkbox]');
                if (!cb) return;
                const id = cb.getAttribute('data-conv-id');
                if (!id) return;
                if (cb.checked) this._selected.add(id);
                else this._selected.delete(id);
                this._updateUI();
            });
        },

        attach() {
            this.init();

            const container = document.getElementById('history-full-list');
            if (!container) return;

            const cards = container.querySelectorAll('.history-card');
            if (!cards.length) return;

            let conversations = [];
            if (window.State && Array.isArray(window.State.conversations)) {
                conversations = window.State.conversations;
            }

            this._allIds = conversations.map(c => c.id).filter(Boolean);

            if (this._bulkMode) {
                this._injectToolbar(container);
            }

            cards.forEach((card, idx) => {
                // ✅ Bug #37 FIX
                let convId = card.getAttribute('data-conv-id');
                if (!convId) {
                    convId = conversations[idx] ? conversations[idx].id : null;
                }
                if (!convId) return;

                if (card.querySelector('[data-bulk-checkbox]')) return;

                card.classList.add('history-card-bulk');
                if (this._selected.has(convId)) {
                    card.classList.add('history-card-selected');
                }
                card.setAttribute('data-conv-id', convId);

                const cb = el('input', {
                    type: 'checkbox',
                    class: 'history-card-checkbox',
                    'data-bulk-checkbox': '',
                    'data-conv-id': convId,
                    'aria-label': 'انتخاب گفتگو',
                });
                if (this._selected.has(convId)) cb.checked = true;

                cb.addEventListener('click', (e) => e.stopPropagation());

                card.insertBefore(cb, card.firstChild);
            });

            this._updateUI();
        },

        toggleMode() {
            this._bulkMode = !this._bulkMode;
            if (!this._bulkMode) this._selected.clear();

            const container = document.getElementById('history-full-list');
            if (!container) return;

            if (this._bulkMode) {
                this._injectToolbar(container);
                toast.info('حالت انتخاب گروهی فعال شد.');
            } else {
                const toolbar = container.querySelector('.bulk-toolbar');
                if (toolbar) toolbar.remove();
            }

            container.querySelectorAll('.history-card-checkbox').forEach(cb => cb.remove());
            container.querySelectorAll('.history-card-bulk').forEach(c => {
                c.classList.remove('history-card-bulk', 'history-card-selected');
            });

            if (this._bulkMode) {
                this.attach();
            }
        },

        _injectToolbar(container) {
            const existing = container.querySelector('.bulk-toolbar');
            if (existing) existing.remove();

            const toolbar = el('div', { class: 'bulk-toolbar' });

            const left = el('div', { class: 'bulk-toolbar-left' });
            const selectAllLabel = el('label', { class: 'bulk-checkbox-wrap' });
            const selectAllCb = el('input', {
                type: 'checkbox',
                class: 'bulk-checkbox',
                'data-bulk-checkbox-all': '',
            });
            selectAllCb.addEventListener('change', (e) => {
                if (e.target.checked) this.selectAll();
                else this.clearSelection();
            });
            selectAllLabel.appendChild(selectAllCb);
            selectAllLabel.appendChild(el('span', { text: 'انتخاب همه' }));
            left.appendChild(selectAllLabel);

            const countBadge = el('span', {
                class: 'bulk-count-badge',
                'data-bulk-count': '',
                text: '۰ مورد',
                style: { display: 'none' },
            });
            left.appendChild(countBadge);

            toolbar.appendChild(left);

            const right = el('div', { class: 'bulk-toolbar-right' });
            right.appendChild(el('button', {
                type: 'button',
                class: 'btn btn-sm btn-ghost',
                'data-bulk-action': 'clear-selection',
                text: 'لغو انتخاب',
            }));
            right.appendChild(el('button', {
                type: 'button',
                class: 'btn btn-sm btn-bulk-delete',
                'data-bulk-action': 'delete-selected',
                disabled: 'disabled',
                text: 'حذف انتخاب‌شده‌ها',
            }));
            right.appendChild(el('button', {
                type: 'button',
                class: 'btn btn-sm btn-ghost',
                'data-bulk-action': 'toggle-mode',
                text: '✕ خروج',
            }));
            toolbar.appendChild(right);

            container.insertBefore(toolbar, container.firstChild);
        },

        selectAll() {
            this._selected = new Set(this._allIds);

            $$('[data-bulk-checkbox]').forEach(cb => {
                const id = cb.getAttribute('data-conv-id');
                cb.checked = this._selected.has(id);
                const card = cb.closest('.history-card');
                if (card) card.classList.toggle('history-card-selected', cb.checked);
            });

            const selectAllCb = document.querySelector('[data-bulk-checkbox-all]');
            if (selectAllCb) selectAllCb.checked = true;

            this._updateUI();
        },

        clearSelection() {
            this._selected.clear();

            $$('[data-bulk-checkbox]').forEach(cb => {
                cb.checked = false;
                const card = cb.closest('.history-card');
                if (card) card.classList.remove('history-card-selected');
            });
            const selectAllCb = document.querySelector('[data-bulk-checkbox-all]');
            if (selectAllCb) selectAllCb.checked = false;

            this._updateUI();
        },

        _updateUI() {
            const countBadge = document.querySelector('[data-bulk-count]');
            const deleteBtn = document.querySelector('[data-bulk-action="delete-selected"]');

            const n = this._selected.size;

            if (countBadge) {
                if (n > 0) {
                    countBadge.textContent = `${n.toLocaleString('fa-IR')} مورد انتخاب شده`;
                    countBadge.style.display = 'inline-flex';
                    countBadge.classList.remove('bulk-count-pulse');
                    void countBadge.offsetWidth;
                    countBadge.classList.add('bulk-count-pulse');
                } else {
                    countBadge.style.display = 'none';
                }
            }

            if (deleteBtn) {
                deleteBtn.disabled = n === 0;
            }

            const selectAllCb = document.querySelector('[data-bulk-checkbox-all]');
            if (selectAllCb) {
                selectAllCb.checked = (n > 0 && n === this._allIds.length);
                selectAllCb.indeterminate = (n > 0 && n < this._allIds.length);
            }
        },

        confirmDelete() {
            const n = this._selected.size;
            if (n === 0) return;

            const self = this;

            const modalId = 'modal-bulk-delete-confirm';
            let overlay = document.getElementById(modalId);
            if (overlay) overlay.remove();

            overlay = el('div', { id: modalId, class: 'modal modal-sm' });
            overlay.appendChild(el('div', { class: 'modal-backdrop', 'data-modal-close': '' }));

            const content = el('div', { class: 'modal-content' });

            const header = el('div', { class: 'modal-header' });
            header.appendChild(el('h3', { class: 'modal-title', text: '🗑 حذف گروهی گفتگوها' }));
            header.appendChild(el('button', {
                type: 'button', class: 'modal-close', 'data-modal-close': '', text: '✕',
            }));
            content.appendChild(header);

            const body = el('div', { class: 'modal-body' });
            body.appendChild(el('p', {
                class: 'confirm-message',
                text: 'آیا از حذف موارد انتخاب‌شده مطمئن هستید؟ این عملیات قابل بازگشت نیست.',
            }));

            const summary = el('div', { class: 'bulk-confirm-summary' });
            const row = el('div', { class: 'bulk-confirm-row' });
            row.appendChild(el('span', { text: 'تعداد گفتگوهای انتخاب‌شده:' }));
            row.appendChild(el('strong', { text: `${n.toLocaleString('fa-IR')} مورد` }));
            summary.appendChild(row);

            const warningRow = el('div', { class: 'bulk-confirm-row' });
            warningRow.appendChild(el('span', { text: 'هشدار:' }));
            warningRow.appendChild(el('strong', {
                class: 'bulk-confirm-warning',
                text: 'تمام پیام‌های این گفتگوها نیز حذف خواهند شد.',
            }));
            summary.appendChild(warningRow);

            body.appendChild(summary);
            content.appendChild(body);

            const footer = el('div', { class: 'modal-footer' });
            footer.appendChild(el('button', {
                type: 'button', class: 'btn btn-ghost', 'data-modal-close': '', text: 'انصراف',
            }));
            const confirmBtn = el('button', {
                type: 'button', class: 'btn btn-danger', text: 'حذف کن',
            });
            confirmBtn.addEventListener('click', async () => {
                confirmBtn.disabled = true;
                confirmBtn.textContent = 'در حال حذف...';
                modal.close(modalId);
                await self.executeDelete();
            });
            footer.appendChild(confirmBtn);
            content.appendChild(footer);

            overlay.appendChild(content);
            document.body.appendChild(overlay);

            modal.open(modalId);
        },

        async executeDelete() {
            const ids = Array.from(this._selected);
            if (!ids.length) return;

            loading.show('در حال حذف...');

            try {
                const res = await apiPost('/student/conversations/bulk-delete', {
                    conversation_ids: ids,
                });

                const deleted = res.deleted || 0;
                const failed = (res.failed || []).length;

                if (deleted > 0 && failed === 0) {
                    toast.success(`${deleted.toLocaleString('fa-IR')} گفتگو با موفقیت حذف شد.`);
                } else if (deleted > 0 && failed > 0) {
                    toast.warning(`${deleted.toLocaleString('fa-IR')} گفتگو حذف شد. ${failed.toLocaleString('fa-IR')} مورد ناموفق بود.`);
                } else {
                    toast.error('حذف انجام نشد.');
                }

                this._selected.clear();
                this._bulkMode = false;

                if (window.StudentPanel && typeof window.StudentPanel.loadHistory === 'function') {
                    window.StudentPanel.loadHistory();
                }
                if (window.StudentPanel && typeof window.StudentPanel.loadConversations === 'function') {
                    window.StudentPanel.loadConversations();
                }
            } catch (err) {
                toast.error(err.message || 'خطا در حذف گروهی.');
            } finally {
                loading.hide();
            }
        },
    };

    /* =================================================================
       MODULE 3: AdminTickets
       ================================================================= */

    const AdminTickets = {
        _initialized: false,
        _tickets: [],
        _currentTicketId: null,
        _currentTicket: null,
        _filterStatus: 'all',
        _search: '',

        init() {
            if (this._initialized) return;
            this._initialized = true;

            document.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-admin-ticket-action]');
                if (!btn) return;
                const action = btn.getAttribute('data-admin-ticket-action');
                if (action === 'refresh') this.load();
                if (action === 'send-reply') this.sendReply();
                if (action === 'change-status') {
                    const status = btn.getAttribute('data-status');
                    if (status) this.changeStatus(status);
                }
            });

            document.addEventListener('click', (e) => {
                const chip = e.target.closest('[data-admin-ticket-filter]');
                if (!chip) return;
                this._filterStatus = chip.getAttribute('data-admin-ticket-filter');
                $$('[data-admin-ticket-filter]').forEach(c =>
                    c.classList.toggle('tickets-filter-chip-active', c === chip));
                this.load();
            });

            document.addEventListener('click', (e) => {
                const row = e.target.closest('[data-admin-ticket-id]');
                if (!row) return;
                const ticketId = row.getAttribute('data-admin-ticket-id');
                if (ticketId) this.openTicket(ticketId);
            });

            document.addEventListener('keydown', (e) => {
                if (e.target && e.target.id === 'admin-ticket-reply-input') {
                    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                        e.preventDefault();
                        this.sendReply();
                    }
                }
            });

            const searchInput = document.getElementById('admin-tickets-search');
            if (searchInput) {
                let t;
                searchInput.addEventListener('input', () => {
                    clearTimeout(t);
                    t = setTimeout(() => {
                        this._search = searchInput.value.trim();
                        this.load();
                    }, 350);
                });
            }
        },

        async load() {
            this.init();

            const tbody = document.querySelector('[data-admin-tickets-tbody]');
            if (tbody) {
                tbody.innerHTML = `<tr><td colspan="7" class="table-empty">در حال بارگذاری...</td></tr>`;
            }

            try {
                const params = new URLSearchParams();
                if (this._filterStatus && this._filterStatus !== 'all') {
                    params.set('status', this._filterStatus);
                }
                if (this._search) params.set('search', this._search);
                params.set('page_size', '100');

                const data = await apiGet('/admin/tickets?' + params.toString());
                this._tickets = data.tickets || [];
                this.renderList();
            } catch (err) {
                if (tbody) {
                    tbody.innerHTML = `<tr><td colspan="7" class="table-empty">خطا: ${escapeHtml(err.message || '')}</td></tr>`;
                }
            }
        },

        renderList() {
            const tbody = document.querySelector('[data-admin-tickets-tbody]');
            if (!tbody) return;

            if (!this._tickets.length) {
                tbody.innerHTML = `<tr><td colspan="7" class="table-empty">تیکتی یافت نشد.</td></tr>`;
                return;
            }

            tbody.innerHTML = '';
            this._tickets.forEach(t => {
                const tr = el('tr', {
                    style: { cursor: 'pointer' },
                    dataset: { adminTicketId: t.id },
                });

                tr.appendChild(el('td', { text: t.subject }));
                tr.appendChild(el('td', { text: t.user_full_name || t.username || '—' }));

                const priorityCell = el('td');
                priorityCell.appendChild(el('span', {
                    class: `admin-ticket-priority admin-ticket-priority-${t.priority}`,
                    text: TICKET_PRIORITY_LABELS[t.priority] || t.priority,
                }));
                tr.appendChild(priorityCell);

                const statusCell = el('td');
                statusCell.appendChild(el('span', {
                    class: `ticket-status ticket-status-${t.status}`,
                    text: TICKET_STATUS_LABELS[t.status] || t.status,
                }));
                tr.appendChild(statusCell);

                tr.appendChild(el('td', { text: TICKET_CATEGORY_LABELS[t.category] || t.category }));
                tr.appendChild(el('td', { text: formatRelativeTime(t.last_activity_at) }));
                tr.appendChild(el('td', { text: formatDateTime(t.created_at) }));

                tbody.appendChild(tr);
            });
        },

        async openTicket(ticketId) {
            this._currentTicketId = ticketId;

            const mainEl = document.querySelector('[data-admin-tickets-main]');
            if (!mainEl) {
                this._openTicketModal(ticketId);
                return;
            }

            mainEl.innerHTML = `
                <div class="storage-loading">
                    <span class="spinner spinner-md"></span>
                    <span class="text-muted">در حال بارگذاری تیکت...</span>
                </div>
            `;

            try {
                const data = await apiGet(`/admin/tickets/${encodeURIComponent(ticketId)}`);
                this._currentTicket = data.ticket;
                this._renderTicketDetail(data.ticket, data.messages || [], mainEl);
            } catch (err) {
                mainEl.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon">⚠</div>
                        <div class="empty-title">خطا در بارگذاری</div>
                        <div class="empty-text">${escapeHtml(err.message || '')}</div>
                    </div>
                `;
            }
        },

        _openTicketModal(ticketId) {
            const modalId = 'modal-admin-ticket-view';
            let overlay = document.getElementById(modalId);
            if (overlay) overlay.remove();

            overlay = el('div', { id: modalId, class: 'modal modal-lg' });
            overlay.appendChild(el('div', { class: 'modal-backdrop', 'data-modal-close': '' }));

            const content = el('div', { class: 'modal-content modal-lg' });

            const header = el('div', { class: 'modal-header' });
            header.appendChild(el('h3', { class: 'modal-title', text: 'جزئیات تیکت' }));
            header.appendChild(el('button', {
                type: 'button', class: 'modal-close', 'data-modal-close': '', text: '✕',
            }));
            content.appendChild(header);

            const body = el('div', {
                class: 'modal-body',
                'data-admin-tickets-main': '',
                style: { maxHeight: '70vh', overflow: 'auto' },
            });
            body.innerHTML = `
                <div class="storage-loading">
                    <span class="spinner spinner-md"></span>
                    <span class="text-muted">در حال بارگذاری...</span>
                </div>
            `;
            content.appendChild(body);
            overlay.appendChild(content);
            document.body.appendChild(overlay);

            modal.open(modalId);

            const self = this;
            apiGet(`/admin/tickets/${encodeURIComponent(ticketId)}`)
                .then(data => {
                    self._currentTicket = data.ticket;
                    self._renderTicketDetail(data.ticket, data.messages || [], body);
                })
                .catch(err => {
                    body.innerHTML = `<div class="empty-state"><div class="empty-title">خطا: ${escapeHtml(err.message || '')}</div></div>`;
                });
        },

        _renderTicketDetail(ticket, messages, container) {
            container.innerHTML = '';

            const info = el('div', { style: { marginBottom: '16px' } });
            info.appendChild(el('div', {
                style: { fontSize: '16px', fontWeight: '700', marginBottom: '8px' },
                text: ticket.subject,
            }));

            const metaRow = el('div', { class: 'tickets-main-meta', style: { marginBottom: '16px' } });
            metaRow.appendChild(el('span', {
                class: `ticket-status ticket-status-${ticket.status}`,
                text: TICKET_STATUS_LABELS[ticket.status] || ticket.status,
            }));
            metaRow.appendChild(el('span', {
                text: `کاربر: ${ticket.user_full_name || ticket.username || '—'}`,
            }));
            metaRow.appendChild(el('span', { text: `• ${formatDateTime(ticket.created_at)}` }));
            info.appendChild(metaRow);

            const statusRow = el('div', { style: { display: 'flex', gap: '6px', flexWrap: 'wrap', marginBottom: '16px' } });
            ['open', 'in_progress', 'waiting_user', 'closed'].forEach(st => {
                if (st === ticket.status) return;
                statusRow.appendChild(el('button', {
                    type: 'button',
                    class: 'btn btn-sm btn-ghost',
                    text: `→ ${TICKET_STATUS_LABELS[st]}`,
                    dataset: { adminTicketAction: 'change-status', status: st },
                }));
            });
            info.appendChild(statusRow);

            container.appendChild(info);

            const thread = el('div', { class: 'ticket-thread', style: { maxHeight: '380px', overflow: 'auto', padding: '16px', background: 'var(--color-surface-alt)', borderRadius: '12px' } });
            messages.forEach(m => thread.appendChild(this._renderMessage(m)));

            if (!messages.length) {
                thread.appendChild(el('div', {
                    class: 'empty-text',
                    text: 'هیچ پیامی وجود ندارد.',
                    style: { textAlign: 'center', padding: '16px' },
                }));
            }

            container.appendChild(thread);

            if (ticket.status !== 'closed') {
                const form = el('div', { class: 'ticket-reply-form', style: { marginTop: '16px' } });
                const textarea = el('textarea', {
                    id: 'admin-ticket-reply-input',
                    class: 'ticket-reply-input',
                    placeholder: 'پاسخ خود را بنویسید...',
                    rows: '3',
                    maxlength: '5000',
                });
                form.appendChild(textarea);

                const actionsRow = el('div', { class: 'ticket-reply-actions' });
                actionsRow.appendChild(el('span', {
                    class: 'ticket-reply-hint',
                    text: 'Ctrl+Enter برای ارسال سریع',
                }));
                actionsRow.appendChild(el('button', {
                    type: 'button',
                    class: 'btn btn-sm btn-primary',
                    text: '➤ ارسال پاسخ',
                    dataset: { adminTicketAction: 'send-reply' },
                }));
                form.appendChild(actionsRow);
                container.appendChild(form);
            } else {
                container.appendChild(el('div', {
                    class: 'ticket-closed-notice',
                    text: 'این تیکت بسته شده است.',
                }));
            }
        },

        _renderMessage(m) {
            const isAdmin = m.author_role === 'admin';
            const cls = 'ticket-message ' + (isAdmin ? 'ticket-message-admin' : 'ticket-message-user');
            const wrap = el('div', { class: cls });

            wrap.appendChild(el('div', { class: 'ticket-message-bubble', text: m.body }));

            const meta = el('div', { class: 'ticket-message-meta' });
            const badgeClass = 'ticket-author-badge' + (isAdmin ? ' ticket-author-badge-admin' : '');
            meta.appendChild(el('span', {
                class: badgeClass,
                text: isAdmin ? '👔 پشتیبانی' : '👤 کاربر',
            }));
            meta.appendChild(el('span', { text: formatDateTime(m.created_at) }));
            wrap.appendChild(meta);

            return wrap;
        },

        async sendReply() {
            const input = document.getElementById('admin-ticket-reply-input');
            if (!input || !this._currentTicketId) return;

            const body = (input.value || '').trim();
            if (body.length < 1) {
                toast.warning('متن پاسخ را وارد کنید.');
                return;
            }

            input.disabled = true;
            try {
                await apiPost(`/admin/tickets/${encodeURIComponent(this._currentTicketId)}/reply`, { body });
                toast.success('پاسخ ارسال شد.');
                input.value = '';
                await this.openTicket(this._currentTicketId);
                await this.load();
            } catch (err) {
                toast.error(err.message || 'خطا در ارسال پاسخ.');
            } finally {
                input.disabled = false;
            }
        },

        async changeStatus(status) {
            if (!this._currentTicketId) return;

            try {
                await apiPost(`/admin/tickets/${encodeURIComponent(this._currentTicketId)}/status`, { status });
                toast.success(`وضعیت به «${TICKET_STATUS_LABELS[status] || status}» تغییر یافت.`);
                await this.openTicket(this._currentTicketId);
                await this.load();
            } catch (err) {
                toast.error(err.message || 'خطا در تغییر وضعیت.');
            }
        },
    };

    /* =================================================================
       MODULE 4: AdminStorageDashboard
       ================================================================= */

    const AdminStorageDashboard = {
        _initialized: false,
        _lastStats: null,
        _loading: false,

        init() {
            if (this._initialized) return;
            this._initialized = true;

            document.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-storage-action]');
                if (!btn) return;
                const action = btn.getAttribute('data-storage-action');
                if (action === 'refresh') this.load();
                if (action === 'run-cleanup') this.confirmRunCleanup();
            });
        },

        async load() {
            this.init();

            if (this._loading) {
                return;
            }
            this._loading = true;

            const root = document.querySelector('[data-storage-dashboard]');
            if (!root) {
                this._loading = false;
                return;
            }

            root.innerHTML = `
                <div class="storage-loading">
                    <span class="spinner spinner-lg"></span>
                    <div class="text-muted">در حال بارگذاری اطلاعات حافظه...</div>
                </div>
            `;

            try {
                const [statsRes, runsRes] = await Promise.all([
                    apiGet('/admin/storage/stats'),
                    apiGet('/admin/storage/last-runs').catch(() => ({ runs: [] })),
                ]);

                this._lastStats = statsRes.stats || {};
                this._render(statsRes.stats || {}, runsRes.runs || []);
            } catch (err) {
                root.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon">⚠</div>
                        <div class="empty-title">خطا در بارگذاری</div>
                        <div class="empty-text">${escapeHtml(err.message || '')}</div>
                    </div>
                `;
            } finally {
                this._loading = false;
            }
        },

        _render(stats, runs) {
            const root = document.querySelector('[data-storage-dashboard]');
            if (!root) return;

            root.innerHTML = '';
            root.className = 'storage-dashboard';

            const hero = el('div', { class: 'storage-hero' });
            hero.appendChild(el('div', { class: 'storage-hero-icon', text: '💾' }));
            hero.appendChild(el('div', { class: 'storage-hero-title', text: 'مدیریت داده و حافظه' }));
            hero.appendChild(el('div', {
                class: 'storage-hero-subtitle',
                text: 'پایش مصرف فضای ذخیره‌سازی و پاکسازی داده‌های منقضی',
            }));
            root.appendChild(hero);

            const statsGrid = el('div', { class: 'storage-stats-grid' });

            const statItems = [
                { icon: '🗄', label: 'حجم فایل دیتابیس', value: formatBytes(stats.db_size_bytes || 0) },
                { icon: '📁', label: 'حجم پوشه data/', value: formatBytes(stats.data_dir_bytes || 0) },
                { icon: '📎', label: 'پیوست‌های کاربران', value: formatBytes(stats.student_uploads_bytes || 0) },
                { icon: '🎫', label: 'پیوست‌های تیکت', value: formatBytes(stats.ticket_uploads_bytes || 0) },
                { icon: '💬', label: 'تعداد گفتگوها', value: formatNumber(stats.conversations_count || 0) },
                { icon: '📨', label: 'تعداد پیام‌ها', value: formatNumber(stats.messages_count || 0) },
                { icon: '🎫', label: 'کل تیکت‌ها', value: formatNumber(stats.tickets_count || 0) },
                { icon: '🔓', label: 'تیکت‌های باز', value: formatNumber(stats.ticket_open_count || 0) },
            ];

            statItems.forEach(item => {
                const card = el('div', { class: 'storage-stat-card' });
                card.appendChild(el('div', { class: 'storage-stat-icon', text: item.icon }));
                card.appendChild(el('div', { class: 'storage-stat-label', text: item.label }));
                card.appendChild(el('div', { class: 'storage-stat-value', text: item.value }));
                statsGrid.appendChild(card);
            });

            root.appendChild(statsGrid);

            const previewCard = this._buildCleanupPreviewCard(stats);
            root.appendChild(previewCard);

            if (runs.length) {
                root.appendChild(this._buildLastRunsCard(runs));
            }
        },

        _buildCleanupPreviewCard(stats) {
            const card = el('div', { class: 'cleanup-preview-card' });

            const header = el('div', { class: 'cleanup-preview-header' });
            header.appendChild(el('div', { class: 'cleanup-preview-icon', text: '🧹' }));

            const titleWrap = el('div', { class: 'cleanup-preview-title-wrap' });
            titleWrap.appendChild(el('div', { class: 'cleanup-preview-title', text: 'پیش‌نمایش پاکسازی خودکار' }));
            titleWrap.appendChild(el('div', {
                class: 'cleanup-preview-subtitle',
                text: 'داده‌های واجد شرایط حذف بر اساس سیاست‌های retention',
            }));
            header.appendChild(titleWrap);
            card.appendChild(header);

            const grid = el('div', { class: 'cleanup-preview-grid' });

            const items = [
                {
                    label: 'گفتگوهای منقضی',
                    value: formatNumber(stats.expired_conversations || 0),
                    hint: `≈ ${formatBytes(stats.expired_conversations_bytes || 0)} قابل آزادسازی`,
                },
                {
                    label: 'تیکت‌های بسته منقضی',
                    value: formatNumber(stats.expired_tickets || 0),
                    hint: `≈ ${formatBytes(stats.expired_tickets_bytes || 0)} قابل آزادسازی`,
                },
                {
                    label: 'آخرین اجرای موفق',
                    value: stats.last_success_at ? formatRelativeTime(stats.last_success_at) : 'هرگز',
                    hint: stats.last_success_at ? formatDateTime(stats.last_success_at) : 'هنوز اجرا نشده',
                },
            ];

            items.forEach(item => {
                const itemEl = el('div', { class: 'cleanup-preview-item' });
                itemEl.appendChild(el('div', { class: 'cleanup-preview-item-label', text: item.label }));
                itemEl.appendChild(el('div', { class: 'cleanup-preview-item-value', text: item.value }));
                if (item.hint) {
                    itemEl.appendChild(el('div', { class: 'cleanup-preview-item-hint', text: item.hint }));
                }
                grid.appendChild(itemEl);
            });

            card.appendChild(grid);

            const actions = el('div', { class: 'cleanup-preview-actions' });

            actions.appendChild(el('button', {
                type: 'button',
                class: 'btn btn-ghost btn-refresh',
                'data-storage-action': 'refresh',
                text: 'بروزرسانی',
            }));

            actions.appendChild(el('button', {
                type: 'button',
                class: 'btn btn-danger',
                'data-storage-action': 'run-cleanup',
                text: '🧹 اجرای پاکسازی دستی',
            }));

            card.appendChild(actions);

            if ((stats.expired_conversations || 0) === 0 && (stats.expired_tickets || 0) === 0) {
                const warn = el('div', { class: 'storage-warning-banner', style: { marginTop: '12px' } });
                warn.appendChild(el('span', {
                    text: 'در حال حاضر داده‌ای واجد شرایط حذف وجود ندارد. برای فعال‌سازی پاکسازی خودکار، تنظیمات retention را بررسی کنید.',
                }));
                card.appendChild(warn);
            }

            return card;
        },

        _buildLastRunsCard(runs) {
            const card = el('div', { class: 'last-run-card' });

            const header = el('div', { class: 'last-run-card-header' });
            header.appendChild(el('div', { class: 'last-run-card-title', text: '📋 تاریخچه‌ی اجراها' }));
            card.appendChild(header);

            const list = el('div', { class: 'last-run-list' });

            runs.slice(0, 8).forEach(run => {
                const item = el('div', { class: 'last-run-item' });

                const status = run.status || 'unknown';
                item.appendChild(el('span', { class: `last-run-item-status ${status}` }));

                const main = el('div', { class: 'last-run-item-main' });
                main.appendChild(el('div', {
                    class: 'last-run-item-title',
                    text: run.run_type === 'full_cleanup' ? 'پاکسازی کامل' : run.run_type,
                }));
                main.appendChild(el('div', {
                    class: 'last-run-item-meta',
                    text: `${formatDateTime(run.started_at)} • توسط: ${run.triggered_by}`,
                }));
                item.appendChild(main);

                const stats = el('div', { class: 'last-run-item-stats' });
                if (run.items_deleted) {
                    stats.appendChild(el('span', {
                        text: `🗑 ${formatNumber(run.items_deleted)}`,
                    }));
                }
                if (run.bytes_freed) {
                    stats.appendChild(el('span', {
                        text: `💾 ${formatBytes(run.bytes_freed)}`,
                    }));
                }
                if (run.error_message) {
                    stats.appendChild(el('span', {
                        style: { color: 'var(--color-error)' },
                        text: `⚠ ${(run.error_message || '').substring(0, 30)}...`,
                    }));
                }
                item.appendChild(stats);

                list.appendChild(item);
            });

            card.appendChild(list);
            return card;
        },

        confirmRunCleanup() {
            const self = this;

            const modalId = 'modal-storage-cleanup-confirm';
            let overlay = document.getElementById(modalId);
            if (overlay) overlay.remove();

            overlay = el('div', { id: modalId, class: 'modal modal-sm' });
            overlay.appendChild(el('div', { class: 'modal-backdrop', 'data-modal-close': '' }));

            const content = el('div', { class: 'modal-content' });
            const header = el('div', { class: 'modal-header' });
            header.appendChild(el('h3', { class: 'modal-title', text: '🧹 اجرای پاکسازی دستی' }));
            header.appendChild(el('button', {
                type: 'button', class: 'modal-close', 'data-modal-close': '', text: '✕',
            }));
            content.appendChild(header);

            const body = el('div', { class: 'modal-body' });
            body.appendChild(el('p', {
                class: 'confirm-message',
                text: 'پاکسازی بر اساس سیاست‌های retention انجام می‌شود. داده‌های واجد شرایط برای همیشه حذف خواهند شد.',
            }));

            const summary = el('div', { class: 'bulk-confirm-summary' });
            const stats = this._lastStats || {};

            const rows = [
                { label: 'گفتگوهای واجد شرایط', value: formatNumber(stats.expired_conversations || 0) },
                { label: 'تیکت‌های واجد شرایط', value: formatNumber(stats.expired_tickets || 0) },
                { label: 'حجم تقریبی قابل آزادسازی',
                  value: formatBytes((stats.expired_conversations_bytes || 0) + (stats.expired_tickets_bytes || 0)) },
            ];

            rows.forEach(r => {
                const row = el('div', { class: 'bulk-confirm-row' });
                row.appendChild(el('span', { text: r.label }));
                row.appendChild(el('strong', { text: r.value }));
                summary.appendChild(row);
            });

            body.appendChild(summary);
            content.appendChild(body);

            const footer = el('div', { class: 'modal-footer' });
            footer.appendChild(el('button', {
                type: 'button', class: 'btn btn-ghost', 'data-modal-close': '', text: 'انصراف',
            }));
            const confirmBtn = el('button', {
                type: 'button', class: 'btn btn-danger', text: 'اجرا کن',
            });
            confirmBtn.addEventListener('click', async () => {
                confirmBtn.disabled = true;
                confirmBtn.textContent = 'در حال اجرا...';
                modal.close(modalId);
                await self.executeCleanup();
            });
            footer.appendChild(confirmBtn);
            content.appendChild(footer);

            overlay.appendChild(content);
            document.body.appendChild(overlay);

            modal.open(modalId);
        },

        async executeCleanup() {
            loading.show('در حال اجرای پاکسازی...');
            try {
                const res = await apiPost('/admin/storage/run-cleanup', {});
                const result = res.result || {};
                if (result.success) {
                    const freed = formatBytes(result.bytes_freed || 0);
                    const items = formatNumber(result.items_deleted || 0);
                    toast.success(`پاکسازی انجام شد. ${items} مورد حذف شد و ${freed} آزاد شد.`);
                } else {
                    toast.warning('پاکسازی اجرا شد اما با خطا مواجه شد.');
                }
                await this.load();
            } catch (err) {
                toast.error(err.message || 'خطا در اجرای پاکسازی.');
            } finally {
                loading.hide();
            }
        },
    };

    /* =================================================================
       EXPORT TO WINDOW
       ================================================================= */

    window.StudentTickets        = StudentTickets;
    window.StudentBulkDelete     = StudentBulkDelete;
    window.AdminTickets          = AdminTickets;
    window.AdminStorageDashboard = AdminStorageDashboard;

    // ✅ Bug #2 FIX: window exports for panel objects
    // (required by StudentBulkDelete.executeDelete → window.StudentPanel.loadHistory)
    window.StudentPanel          = StudentPanel;
    window.ManagerPanel          = ManagerPanel;
    window.AdminPanel            = AdminPanel;
    window.VideoPlayer           = VideoPlayer;

    console.info('[DANOVA] lifecycle modules loaded — 4 modules registered');

})();

/* =====================================================================
   DOWNLOAD CENTER — Frontend Module
   ===================================================================== */

(function () {

    'use strict';

    const $  = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    const escapeHtml = (s) => {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    };

    const el = (tag, attrs = {}, children = []) => {
        const node = document.createElement(tag);
        Object.entries(attrs || {}).forEach(([k, v]) => {
            if (v === undefined || v === null) return;
            if (k === 'class') node.className = v;
            else if (k === 'text') node.textContent = v;
            else if (k === 'html') node.innerHTML = v;
            else if (k === 'style' && typeof v === 'object') Object.assign(node.style, v);
            else if (k.startsWith('on') && typeof v === 'function') {
                node.addEventListener(k.slice(2).toLowerCase(), v);
            } else if (k === 'dataset' && typeof v === 'object') {
                Object.entries(v).forEach(([dk, dv]) => node.dataset[dk] = dv);
            } else node.setAttribute(k, v);
        });
        const list = Array.isArray(children) ? children : (children ? [children] : []);
        list.forEach(c => {
            if (c === null || c === undefined || c === false) return;
            if (typeof c === 'string' || typeof c === 'number') {
                node.appendChild(document.createTextNode(String(c)));
            } else if (c instanceof Node) {
                node.appendChild(c);
            }
        });
        return node;
    };

    const apiGet    = (p) => (window.API && window.API.get(p))         || Promise.reject(new Error('API unavailable'));
    const apiPost   = (p, b) => (window.API && window.API.post(p, b || {})) || Promise.reject(new Error('API unavailable'));
    const apiPut    = (p, b) => (window.API && window.API.put(p, b || {}))  || Promise.reject(new Error('API unavailable'));
    const apiDel    = (p) => (window.API && window.API.del(p))         || Promise.reject(new Error('API unavailable'));
    const apiUpload = (p, fd) => (window.API && window.API.upload(p, fd)) || Promise.reject(new Error('API unavailable'));

    const toast = {
        success: (m) => window.Toast && window.Toast.success(m),
        error:   (m) => window.Toast && window.Toast.error(m),
        warning: (m) => window.Toast && window.Toast.warning(m),
        info:    (m) => window.Toast && window.Toast.info(m),
    };

    const PLATFORM_LABELS = { windows: 'Windows', android: 'Android', ios: 'iOS' };
    const PLATFORM_ICONS  = { windows: '🪟', android: '🤖', ios: '🍎' };

    const fmtBytes = (bytes) => {
        const n = Number(bytes);
        if (!Number.isFinite(n) || n <= 0) return '—';
        const units = ['B', 'KB', 'MB', 'GB'];
        let i = 0, v = n;
        while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
        return v.toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
    };

    const fmtDate = (iso) => {
        if (!iso) return '—';
        try { return new Date(iso.replace(' ', 'T')).toLocaleDateString('fa-IR'); }
        catch (e) { return '—'; }
    };

    const fmtNumber = (n) => {
        if (n === null || n === undefined || n === '') return '—';
        const num = Number(n);
        if (Number.isNaN(num)) return '—';
        return num.toLocaleString('fa-IR');
    };

    /* =================================================================
       MODULE 1: Public Download Center (Login CTA)
       ================================================================= */

    const DownloadCenter = {
        _initialized: false,

        init() {
            if (this._initialized) return;
            this._initialized = true;

            const cta = document.getElementById('login-download-cta');
            if (cta) {
                cta.addEventListener('click', () => this.open());
            }
        },

        async open() {
            const modalId = 'modal-download-center';
            const body = document.getElementById('download-center-body');
            if (!body) return;

            window.Modal && window.Modal.open(modalId);

            body.innerHTML = `
                <div class="empty-state">
                    <div class="empty-icon">📥</div>
                    <div class="empty-title">در حال بارگذاری...</div>
                </div>`;

            try {
                const data = await apiGet('/downloads/latest');
                const items = data.latest || [];
                this._render(items);
            } catch (err) {
                body.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon">⚠</div>
                        <div class="empty-title">دریافت اطلاعات دانلود با مشکل مواجه شد</div>
                        <div class="empty-text">${escapeHtml(err.message || 'لطفاً دوباره تلاش کنید.')}</div>
                    </div>`;
            }
        },

        _render(items) {
            const body = document.getElementById('download-center-body');
            if (!body) return;

            const byPlatform = {};
            items.forEach(r => { byPlatform[r.platform] = r; });

            body.innerHTML = '';
            const grid = el('div', { class: 'download-center-grid' });

            ['windows', 'android', 'ios'].forEach(platform => {
                const r = byPlatform[platform];
                grid.appendChild(this._card(platform, r));
            });

            body.appendChild(grid);
        },

        _card(platform, release) {
            const card = el('div', { class: 'download-card download-card-' + platform });

            const header = el('div', { class: 'download-card-header' });
            header.appendChild(el('span', {
                class: 'download-card-icon',
                text: PLATFORM_ICONS[platform] || '💾',
            }));
            header.appendChild(el('span', {
                class: 'download-card-platform',
                text: PLATFORM_LABELS[platform] || platform,
            }));
            card.appendChild(header);

            if (!release) {
                card.appendChild(el('div', {
                    class: 'download-card-empty',
                    text: 'نسخه‌ای برای این پلتفرم هنوز منتشر نشده است.',
                }));
                return card;
            }

            card.appendChild(el('div', {
                class: 'download-card-title',
                text: release.title || 'DANOVA',
            }));

            const verRow = el('div', { class: 'download-card-version-row' });
            verRow.appendChild(el('span', {
                class: 'download-card-version',
                text: 'نسخه ' + (release.version || '—'),
            }));
            if (release.is_latest) {
                verRow.appendChild(el('span', {
                    class: 'badge badge-success',
                    text: '✓ آخرین نسخه',
                }));
            }
            card.appendChild(verRow);

            const meta = el('div', { class: 'download-card-meta' });
            if (release.min_os_version) meta.appendChild(el('span', { text: release.min_os_version }));
            if (release.file_size)      meta.appendChild(el('span', { text: fmtBytes(release.file_size) }));
            if (release.published_at)   meta.appendChild(el('span', { text: fmtDate(release.published_at) }));
            if (meta.childNodes.length) card.appendChild(meta);

            if (release.description) {
                card.appendChild(el('p', {
                    class: 'download-card-description',
                    text: release.description,
                }));
            }

            const actions = el('div', { class: 'download-card-actions' });
            if (release.release_type === 'store_link' && release.external_url) {
                const a = document.createElement('a');
                a.href = release.external_url;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                a.className = 'btn btn-primary btn-block';
                a.textContent = platform === 'ios' ? 'دریافت از App Store' : 'مشاهده در فروشگاه';
                actions.appendChild(a);
            } else if (release.release_type === 'file') {
                const a = document.createElement('a');
                a.href = '/api/downloads/' + encodeURIComponent(release.id) + '/file';
                a.className = 'btn btn-primary btn-block';
                a.setAttribute('download', release.file_name || '');
                a.textContent = platform === 'android' ? 'دانلود APK' : 'دانلود';
                actions.appendChild(a);
            } else {
                actions.appendChild(el('span', { class: 'text-muted', text: 'در دسترس نیست' }));
            }
            card.appendChild(actions);

            return card;
        },
    };

    /* =================================================================
       MODULE 2: Admin Downloads
       ================================================================= */

    /* =================================================================
       DOWNLOAD-CENTER: upload with progress
       - فقط برای آپلود فایل‌های Download Center استفاده می‌شود
       - بقیه API clientها دست‌نخورده باقی می‌مانند
       ================================================================= */

    // ✅ FIX: Class-based upload state بهجای global object.
    // این اجازه میدهد چند آپلود همزمان مدیریت شوند (هر آپلود یک نمونه).
    class UploadState {
        constructor() {
            this.xhr = null;
            this.onProgress = null;
            this.onCancel = null;
            this.active = false;
        }
        cancel() {
            if (this.xhr && this.active) {
                try { this.xhr.abort(); } catch (e) {}
            }
        }
        reset() {
            this.xhr = null;
            this.active = false;
        }
    }

    /** @type {Map<string, UploadState>} — key: upload id */
    const _activeUploads = new Map();

    /**
     * آپلود فایل با Progress واقعی (XHR upload.onprogress)
     * @param {string} endpoint
     * @param {FormData} formData
     * @param {object} callbacks  { onProgress, onSuccess, onError, onCancel }
     */
    const uploadDownloadFile = (endpoint, formData, callbacks) => {
        const csrf = (window.State && State.csrfToken) || null;
        const url = (window.CONFIG && CONFIG.API_BASE ? CONFIG.API_BASE : '/api')
                  + (endpoint.startsWith('/') ? endpoint : '/' + endpoint);

        const xhr = new XMLHttpRequest();
        const uploadState = new UploadState();
        uploadState.xhr = xhr;
        uploadState.active = true;
        const _uploadId = 'u_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8);
        _activeUploads.set(_uploadId, uploadState);

        xhr.open('POST', url, true);
        xhr.withCredentials = true;
        xhr.setRequestHeader('Accept', 'application/json');
        if (csrf) xhr.setRequestHeader('X-CSRF-Token', csrf);

        // Progress
        if (xhr.upload && typeof callbacks.onProgress === 'function') {
            xhr.upload.onprogress = (evt) => {
                if (evt.lengthComputable) {
                    callbacks.onProgress({
                        loaded: evt.loaded,
                        total: evt.total,
                        percent: (evt.loaded / evt.total) * 100,
                    });
                }
            };
        }

        xhr.onload = () => {
            uploadState.active = false;
            uploadState.xhr = null;
            _activeUploads.delete(_uploadId);

            // ✅ FIX (Download System — HIGH): Previously 401 was lumped into the
            // generic error branch, leaving the admin stuck on a broken modal with
            // a still-dead session. Delegate to the global logout flow so the app
            // behaves consistently with API.request().
            /* DANOVA-FIX: DAN-009 */
            if (xhr.status === 401) {
                hideProgressOverlay();
                resetProgressState();

                if (window.Toast) {
                    window.Toast.error('نشست شما منقضی شده است. لطفاً دوباره وارد شوید.');
                }
                // Clean SPA logout — no full page reload
                if (window.Auth && typeof window.Auth.logout === 'function') {
                    try { window.Auth.logout(); } catch (e) { /* silent */ }
                }
                return;
            }

            if (xhr.status >= 200 && xhr.status < 300) {
                let data = null;
                try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
                if (typeof callbacks.onSuccess === 'function') callbacks.onSuccess(data);
            } else {
                let msg = 'خطای سرور (' + xhr.status + ')';
                try {
                    const parsed = JSON.parse(xhr.responseText);
                    if (parsed && (parsed.detail || parsed.message)) {
                        msg = parsed.detail || parsed.message;
                    }
                } catch (e) {}
                if (typeof callbacks.onError === 'function') callbacks.onError(msg, xhr.status);
            }
        };

        xhr.onerror = () => {
            uploadState.active = false;
            uploadState.xhr = null;
            _activeUploads.delete(_uploadId);
            if (typeof callbacks.onError === 'function') {
                callbacks.onError('ارتباط با سرور قطع شد.', 0);
            }
        };

        xhr.onabort = () => {
            uploadState.active = false;
            uploadState.xhr = null;
            _activeUploads.delete(_uploadId);
            if (typeof callbacks.onCancel === 'function') callbacks.onCancel();
        };

        xhr.send(formData);
    };

    const cancelUpload = () => {
        // ✅ FIX: کنسل کردن همه آپلودهای فعال (سازگار با نسخه قبلی)
        for (const [id, st] of _activeUploads) {
            st.cancel();
        }
    };

    /* =================================================================
       Progress UI helpers
       ================================================================= */

    const _progressEls = {
        overlay:  () => document.getElementById('download-progress-overlay'),
        bar:      () => document.getElementById('download-progress-bar'),
        percent:  () => document.getElementById('download-progress-percent'),
        size:     () => document.getElementById('download-progress-size'),
        speed:    () => document.getElementById('download-progress-speed'),
        eta:      () => document.getElementById('download-progress-eta'),
        filename: () => document.getElementById('download-progress-filename'),
        cancel:   () => document.getElementById('download-progress-cancel'),
    };

    const fmtBytesLocal = (bytes) => {
        const n = Number(bytes);
        if (!Number.isFinite(n) || n <= 0) return '۰ B';
        const units = ['B', 'KB', 'MB', 'GB'];
        let i = 0, v = n;
        while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
        return v.toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
    };

    const fmtFaNum = (n, digits) => {
        const num = Number(n);
        if (!Number.isFinite(num)) return '—';
        return num.toLocaleString('fa-IR', {
            maximumFractionDigits: digits !== undefined ? digits : 1,
        });
    };

    const showProgressOverlay = (fileName) => {
        const ov = _progressEls.overlay();
        if (!ov) return;
        ov.hidden = false;
        const fn = _progressEls.filename();
        if (fn) fn.textContent = fileName || '—';
        setProgressUI({ percent: 0, loaded: 0, total: 0 });
    };

    const hideProgressOverlay = () => {
        const ov = _progressEls.overlay();
        if (ov) ov.hidden = true;
    };

    let _lastProgressTime = 0;
    let _lastProgressLoaded = 0;
    let _uploadStartTime = 0;

    const setProgressUI = ({ percent, loaded, total }) => {
        const p = Math.max(0, Math.min(100, percent || 0));

        const bar = _progressEls.bar();
        if (bar) bar.style.width = p + '%';

        const pe = _progressEls.percent();
        if (pe) pe.textContent = fmtFaNum(p, 0) + '٪';

        const sz = _progressEls.size();
        if (sz) {
            const totalStr = total > 0 ? fmtBytesLocal(total) : '—';
            sz.textContent = fmtBytesLocal(loaded) + ' / ' + totalStr;
        }

        // محاسبه سرعت و ETA
        const now = performance.now();
        if (_uploadStartTime === 0) {
            _uploadStartTime = now;
            _lastProgressTime = now;
            _lastProgressLoaded = 0;
        }

        if (now - _lastProgressTime > 500) {
            const deltaTime = (now - _lastProgressTime) / 1000; // seconds
            const deltaLoaded = loaded - _lastProgressLoaded;   // bytes

            if (deltaTime > 0 && deltaLoaded >= 0) {
                const speedBytesPerSec = deltaLoaded / deltaTime;

                const sp = _progressEls.speed();
                if (sp) {
                    sp.textContent = fmtBytesLocal(speedBytesPerSec) + '/s';
                }

                if (speedBytesPerSec > 0 && total > loaded) {
                    const remainingBytes = total - loaded;
                    const etaSec = remainingBytes / speedBytesPerSec;

                    const eta = _progressEls.eta();
                    if (eta) {
                        if (etaSec < 60) {
                            eta.textContent = fmtFaNum(Math.ceil(etaSec), 0) + ' ثانیه';
                        } else if (etaSec < 3600) {
                            const m = Math.floor(etaSec / 60);
                            const s = Math.ceil(etaSec % 60);
                            eta.textContent = fmtFaNum(m, 0) + 'د ' + fmtFaNum(s, 0) + 'ث';
                        } else {
                            eta.textContent = 'بیش از ۱ ساعت';
                        }
                    }
                }
            }

            _lastProgressTime = now;
            _lastProgressLoaded = loaded;
        }
    };

    const resetProgressState = () => {
        _lastProgressTime = 0;
        _lastProgressLoaded = 0;
        _uploadStartTime = 0;
    };

    const AdminDownloads = {
        _initialized: false,
        _cache: [],

        init() {
            if (this._initialized) return;
            this._initialized = true;

            const search = document.getElementById('admin-downloads-search');
            if (search && !search._downloadHandler) {
                let t;
                search._downloadHandler = () => {
                    clearTimeout(t);
                    t = setTimeout(() => {
                        if (window.State) {
                            State.adminFilters.downloads = {
                                ...(State.adminFilters.downloads || {}),
                                search: search.value,
                            };
                            State.adminPages.downloads = 1;
                        }
                        this.load();
                    }, 350);
                };
                search.addEventListener('input', search._downloadHandler);
            }

            const pFilter = document.getElementById('admin-downloads-platform-filter');
            if (pFilter && !pFilter._downloadHandler) {
                pFilter._downloadHandler = () => {
                    if (window.State) {
                        State.adminFilters.downloads = {
                            ...(State.adminFilters.downloads || {}),
                            platform: pFilter.value,
                        };
                        State.adminPages.downloads = 1;
                    }
                    this.load();
                };
                pFilter.addEventListener('change', pFilter._downloadHandler);
            }

            const sFilter = document.getElementById('admin-downloads-status-filter');
            if (sFilter && !sFilter._downloadHandler) {
                sFilter._downloadHandler = () => {
                    if (window.State) {
                        State.adminFilters.downloads = {
                            ...(State.adminFilters.downloads || {}),
                            is_published: sFilter.value,
                        };
                        State.adminPages.downloads = 1;
                    }
                    this.load();
                };
                sFilter.addEventListener('change', sFilter._downloadHandler);
            }

            this._initForm();
        },

        _initForm() {
            const form = document.getElementById('form-download');
            if (!form || form._submitHandler) return;

            const platformSel = document.getElementById('download-platform');
            const typeSel = document.getElementById('download-release-type');

            const syncTypeVisibility = () => {
                const platform = platformSel ? platformSel.value : 'windows';
                const type = typeSel ? typeSel.value : 'file';

                const fileGroup = document.getElementById('download-file-group');
                const urlGroup = document.getElementById('download-url-group');
                const typeGroup = document.getElementById('download-release-type-group');
                const fileInput = document.getElementById('download-file');

                if (platform === 'ios') {
                    if (typeSel) typeSel.value = 'store_link';
                    if (fileInput) { fileInput.value = ''; }
                    if (fileInput) fileInput.removeAttribute('required');
                }

                const effectiveType = platform === 'ios'
                    ? 'store_link'
                    : (typeSel ? typeSel.value : 'file');

                if (fileGroup) fileGroup.hidden = (effectiveType !== 'file');
                if (urlGroup) urlGroup.hidden = (effectiveType !== 'store_link');

                if (fileInput) {
                    const mode = (document.getElementById('download-mode') || {}).value;
                    if (effectiveType === 'file' && mode === 'create') {
                        fileInput.setAttribute('required', 'required');
                    } else {
                        fileInput.removeAttribute('required');
                    }
                }
                if (typeGroup) typeGroup.hidden = (platform === 'ios');
            };

            if (platformSel) platformSel.addEventListener('change', syncTypeVisibility);
            if (typeSel) typeSel.addEventListener('change', syncTypeVisibility);

            form._submitHandler = (e) => this._submit(e);
            form.addEventListener('submit', form._submitHandler);
        },

        async load() {
            this.init();

            const tbody = document.getElementById('admin-downloads-tbody');
            if (!tbody) return;

            tbody.innerHTML = `<tr><td colspan="8" class="table-empty">در حال بارگذاری...</td></tr>`;

            const page = (window.State && State.adminPages.downloads) || 1;
            const filters = (window.State && State.adminFilters.downloads) || {};

            const params = new URLSearchParams();
            Object.entries(filters).forEach(([k, v]) => {
                if (v === null || v === undefined || v === '') return;
                params.set(k, String(v));
            });
            params.set('page', String(page));
            params.set('page_size', '25');
            params.set('include_deleted', '1');

            try {
                const data = await apiGet('/admin/downloads?' + params.toString());
                this._cache = data.downloads || [];
                this._renderRows(this._cache, data);
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="8" class="table-empty">خطا: ${escapeHtml(err.message || '')}</td></tr>`;
            }
        },

        _renderRows(items, pagination) {
            const tbody = document.getElementById('admin-downloads-tbody');
            if (!tbody) return;

            if (!items.length) {
                tbody.innerHTML = `<tr><td colspan="8" class="table-empty">انتشاری یافت نشد.</td></tr>`;
                this._renderPagination(pagination);
                return;
            }

            tbody.innerHTML = '';
            items.forEach(r => tbody.appendChild(this._row(r)));
            this._renderPagination(pagination);
        },

        _row(r) {
            const tr = el('tr');
            tr.appendChild(el('td', { text: r.title }));

            const platformCell = el('td');
            platformCell.appendChild(el('span', {
                class: 'badge badge-primary',
                text: (PLATFORM_ICONS[r.platform] || '') + ' ' + (PLATFORM_LABELS[r.platform] || r.platform),
            }));
            tr.appendChild(platformCell);

            tr.appendChild(el('td', { text: r.version || '—' }));
            tr.appendChild(el('td', { text: r.release_type === 'file' ? '📦 فایل' : '🔗 لینک' }));
            tr.appendChild(el('td', { text: r.file_size ? fmtBytes(r.file_size) : '—' }));

            const latestCell = el('td');
            if (r.is_latest) {
                latestCell.appendChild(el('span', { class: 'badge badge-success', text: '✓ آخرین' }));
            } else {
                latestCell.appendChild(el('span', { class: 'text-muted', text: '—' }));
            }
            tr.appendChild(latestCell);

            const statusCell = el('td');
            let statusText = 'پیش‌نویس';
            let statusClass = 'badge-warning';
            if (r.deleted_at)        { statusText = 'حذف‌شده';   statusClass = 'badge-error'; }
            else if (!r.is_active)   { statusText = 'غیرفعال';   statusClass = 'badge-warning'; }
            else if (r.is_published) { statusText = 'منتشرشده';  statusClass = 'badge-success'; }
            statusCell.appendChild(el('span', { class: 'badge ' + statusClass, text: statusText }));
            tr.appendChild(statusCell);

            tr.appendChild(el('td', { class: 'actions-cell' }, this._actions(r)));

            return tr;
        },

        _actions(r) {
            const actions = [];

            if (!r.deleted_at) {
                actions.push(el('button', {
                    class: 'btn btn-sm btn-ghost', text: 'ویرایش',
                    onClick: () => this.openEditModal(r),
                }));
            }

            if (!r.deleted_at && r.is_active && !r.is_latest && r.is_published) {
                actions.push(el('button', {
                    class: 'btn btn-sm btn-ghost', text: 'آخرین نسخه',
                    onClick: () => this.setLatest(r.id),
                }));
            }

            if (r.deleted_at) {
                actions.push(el('button', {
                    class: 'btn btn-sm btn-success', text: 'بازیابی',
                    onClick: () => this.restore(r.id),
                }));
                actions.push(el('button', {
                    class: 'btn btn-sm btn-danger', text: 'حذف دائمی',
                    onClick: () => this.permanentDelete(r),
                }));
            } else if (r.is_active) {
                actions.push(el('button', {
                    class: 'btn btn-sm btn-ghost', text: 'غیرفعال',
                    onClick: () => this.deactivate(r.id),
                }));
                actions.push(el('button', {
                    class: 'btn btn-sm btn-danger', text: 'حذف',
                    onClick: () => this.softDelete(r.id),
                }));
            } else {
                actions.push(el('button', {
                    class: 'btn btn-sm btn-success', text: 'فعال‌سازی',
                    onClick: () => this.activate(r.id),
                }));
                actions.push(el('button', {
                    class: 'btn btn-sm btn-danger', text: 'حذف',
                    onClick: () => this.softDelete(r.id),
                }));
            }

            return actions;
        },

        _renderPagination(pagination) {
            const c = document.getElementById('admin-downloads-pagination');
            if (!c) return;
            if (!pagination || pagination.total_pages <= 1) { c.innerHTML = ''; return; }

            c.innerHTML = '';
            const { page, total_pages, total } = pagination;
            c.appendChild(el('span', { class: 'pagination-info', text: `مجموع: ${fmtNumber(total)}` }));

            const wrap = el('div', { class: 'pagination-controls' });
            const prev = el('button', {
                class: 'btn btn-sm btn-ghost', text: 'قبلی',
                disabled: page <= 1 ? 'true' : null,
                onClick: () => { if (window.State) State.adminPages.downloads = page - 1; this.load(); },
            });
            const next = el('button', {
                class: 'btn btn-sm btn-ghost', text: 'بعدی',
                disabled: page >= total_pages ? 'true' : null,
                onClick: () => { if (window.State) State.adminPages.downloads = page + 1; this.load(); },
            });
            wrap.appendChild(prev);
            wrap.appendChild(el('span', { class: 'pagination-current', text: `${fmtNumber(page)} / ${fmtNumber(total_pages)}` }));
            wrap.appendChild(next);
            c.appendChild(wrap);
        },

        openCreateModal() {
            this.init();

            const form = document.getElementById('form-download');
            if (form) form.reset();

            document.getElementById('modal-download-title').textContent = 'افزودن انتشار';
            document.getElementById('download-id').value = '';
            document.getElementById('download-mode').value = 'create';
            document.getElementById('download-publish-now').checked = true;

            const currentEl = document.getElementById('download-file-current');
            if (currentEl) currentEl.textContent = '';

            const fileInput = document.getElementById('download-file');
            if (fileInput) { fileInput.required = true; fileInput.value = ''; }

            const platformSel = document.getElementById('download-platform');
            const typeSel = document.getElementById('download-release-type');
            if (platformSel) platformSel.disabled = false;
            if (typeSel) typeSel.disabled = false;
            if (platformSel) platformSel.value = 'windows';
            if (typeSel) typeSel.value = 'file';

            if (platformSel) platformSel.dispatchEvent(new Event('change'));

            window.Modal && window.Modal.open('modal-download');
        },

        openEditModal(r) {
            this.init();

            const form = document.getElementById('form-download');
            if (form) form.reset();

            document.getElementById('modal-download-title').textContent = 'ویرایش انتشار';
            document.getElementById('download-id').value = r.id;
            document.getElementById('download-mode').value = 'edit';

            document.getElementById('download-title').value = r.title || '';
            document.getElementById('download-version').value = r.version || '';
            document.getElementById('download-description').value = r.description || '';
            document.getElementById('download-min-os').value = r.min_os_version || '';
            document.getElementById('download-publish-now').checked = !!r.is_published;

            const platformSel = document.getElementById('download-platform');
            const typeSel = document.getElementById('download-release-type');
            if (platformSel) { platformSel.value = r.platform; platformSel.disabled = true; }
            if (typeSel) { typeSel.value = r.release_type; typeSel.disabled = true; }

            const urlInput = document.getElementById('download-external-url');
            if (urlInput) urlInput.value = r.external_url || '';

            const fileInput = document.getElementById('download-file');
            if (fileInput) { fileInput.required = false; fileInput.value = ''; }

            const currentEl = document.getElementById('download-file-current');
            if (currentEl) {
                currentEl.textContent = r.file_name
                    ? `فایل فعلی: ${r.file_name} (${fmtBytes(r.file_size)})`
                    : '';
            }

            if (platformSel) platformSel.dispatchEvent(new Event('change'));

            window.Modal && window.Modal.open('modal-download');
        },



        async _submit(e) {
            e.preventDefault();

            const mode = document.getElementById('download-mode').value;
            const id = document.getElementById('download-id').value;

            const title       = document.getElementById('download-title').value.trim();
            const platform    = document.getElementById('download-platform').value;
            const version     = document.getElementById('download-version').value.trim();
            const type        = document.getElementById('download-release-type').value;
            const description = document.getElementById('download-description').value.trim();
            const minOs       = document.getElementById('download-min-os').value.trim();
            const url         = document.getElementById('download-external-url').value.trim();
            const publish     = document.getElementById('download-publish-now').checked;
            const fileInput   = document.getElementById('download-file');
            const file        = fileInput && fileInput.files ? fileInput.files[0] : null;

            if (!title || title.length < 2) { toast.warning('عنوان الزامی است.'); return; }
            if (!version) { toast.warning('نسخه الزامی است.'); return; }

            const submitBtn = document.getElementById('download-submit-btn');
            if (submitBtn) submitBtn.disabled = true;

            try {
                if (mode === 'create') {
                    // DOWNLOAD-CENTER: submit with progress
                    if (type === 'file' && file) {
                        const fd = new FormData();
                        fd.append('file', file);
                        fd.append('title', title);
                        fd.append('platform', platform);
                        fd.append('version', version);
                        fd.append('description', description);
                        fd.append('min_os_version', minOs);
                        fd.append('is_published', publish ? '1' : '0');

                        const uploadResult = await new Promise((resolve, reject) => {
                            resetProgressState();
                            showProgressOverlay(file.name);

                            const cancelBtn = _progressEls.cancel();
                            if (cancelBtn && !cancelBtn._progressHandler) {
                                cancelBtn._progressHandler = () => {
                                    cancelUpload();
                                };
                                cancelBtn.addEventListener('click', cancelBtn._progressHandler);
                            }

                            uploadDownloadFile('/admin/downloads/upload', fd, {
                                onProgress: (p) => setProgressUI(p),
                                onSuccess: (data) => {
                                    hideProgressOverlay();
                                    resolve(data);
                                },
                                onError: (msg) => {
                                    hideProgressOverlay();
                                    reject(new Error(msg));
                                },
                                onCancel: () => {
                                    hideProgressOverlay();
                                    reject(new Error('UPLOAD_CANCELLED'));
                                },
                            });
                        });
                    } else if (type === 'store_link') {
                        await apiPost('/admin/downloads', {
                            title, platform, version,
                            release_type: 'store_link',
                            external_url: url,
                            description, min_os_version: minOs,
                            is_published: publish,
                        });
                    } else {
                        toast.warning('برای انتشار از نوع فایل، فایل را انتخاب کنید.');
                        return;
                    }
                    toast.success('انتشار ایجاد شد.');
                } else {
                    const payload = {
                        title, version, description,
                        min_os_version: minOs,
                        is_published: publish,
                    };
                    if (type === 'store_link') payload.external_url = url;
                    await apiPut('/admin/downloads/' + encodeURIComponent(id), payload);
                    toast.success('انتشار به‌روزرسانی شد.');
                }

                window.Modal && window.Modal.close('modal-download');
                this.load();
            } catch (err) {
                if (err && err.message === 'UPLOAD_CANCELLED') {
                    toast.info('آپلود لغو شد.');
                    return;
                }
                toast.error(err.message || 'خطا در ذخیره‌سازی.');
            } finally {
                if (submitBtn) submitBtn.disabled = false;
            }
        },

        async setLatest(id) {
            try {
                await apiPost('/admin/downloads/' + encodeURIComponent(id) + '/set-latest', {});
                toast.success('به‌عنوان آخرین نسخه تنظیم شد.');
                this.load();
            } catch (err) { toast.error(err.message); }
        },

        async activate(id) {
            try {
                await apiPost('/admin/downloads/' + encodeURIComponent(id) + '/activate', {});
                toast.success('انتشار فعال شد.');
                this.load();
            } catch (err) { toast.error(err.message); }
        },

        async deactivate(id) {
            try {
                await apiPost('/admin/downloads/' + encodeURIComponent(id) + '/deactivate', {});
                toast.success('انتشار غیرفعال شد.');
                this.load();
            } catch (err) { toast.error(err.message); }
        },

        async restore(id) {
            try {
                await apiPost('/admin/downloads/' + encodeURIComponent(id) + '/restore', {});
                toast.success('انتشار بازیابی شد.');
                this.load();
            } catch (err) { toast.error(err.message); }
        },

        softDelete(id) {
            window.Modal && window.Modal.confirm(
                'این انتشار حذف (نرم) شود؟ می‌توانید بعداً بازیابی کنید.',
                async () => {
                    try {
                        await apiDel('/admin/downloads/' + encodeURIComponent(id));
                        toast.success('انتشار حذف شد.');
                        this.load();
                    } catch (err) { toast.error(err.message); }
                }
            );
        },

        permanentDelete(r) {
            const name = r.title || 'download';
            window.Modal && window.Modal.typedConfirm(name, async ({ confirm_name }) => {
                try {
                    await apiPost('/admin/downloads/' + encodeURIComponent(r.id) + '/permanent-delete', {
                        confirm_name,
                    });
                    toast.success('حذف دائمی انجام شد.');
                    this.load();
                } catch (err) { toast.error(err.message); }
            }, {
                entityLabel: name,
                allowCascade: false,
                title: 'تأیید حذف دائمی انتشار',
            });
        },
    };

    window.DownloadCenter = DownloadCenter;
    window.AdminDownloads = AdminDownloads;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => DownloadCenter.init());
    } else {
        DownloadCenter.init();
    }

    console.info('[DANOVA] Download Center modules loaded');

})();

/* =====================================================================
   END OF app.js (v3.15.1 — Bug Fix: window exports for lifecycle modules)
   ===================================================================== */


/* =====================================================================
   ✅ DANOVA_AUTOFIX_REQUIRED_FIELDS_V1
   =====================================================================
   رفع خطای "invalid form control with name='' is not focusable"
   
   وقتی مودالی باز می‌شود و برخی فیلدهای required در آن مخفی
   هستند، مرورگر هنگام submit خطا می‌دهد. این helper با استفاده
   از MutationObserver، هر فیلد required را در صورت مخفی بودن،
   به‌صورت موقت غیر-required می‌کند.
   ===================================================================== */

(function installRequiredFieldGuard() {
    'use strict';

    const STYLE_ID = 'danova-required-guard-style';

    if (!document.getElementById(STYLE_ID)) {
        const style = document.createElement('style');
        style.id = STYLE_ID;
        style.textContent = `
            input[required][hidden],
            input[required][aria-hidden="true"],
            input[required][type="hidden"] {
                pointer-events: none;
            }
        `;
        document.head.appendChild(style);
    }

    function isVisible(el) {
        if (!el || !el.isConnected) return false;
        if (el.hidden) return false;
        if (el.disabled) return false;
        const cs = window.getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden') return false;
        if (el.offsetParent === null && cs.position !== 'fixed') return false;
        return true;
    }

    function syncRequiredFields(container) {
        if (!container) return;
        const fields = container.querySelectorAll(
            'input[required], select[required], textarea[required]'
        );
        fields.forEach(field => {
            const visible = isVisible(field);
            const hadRequired = field.dataset._danovaHadRequired === '1';

            if (!visible) {
                if (!hadRequired) {
                    field.dataset._danovaHadRequired = '1';
                    field.dataset._danovaWasRequired = field.getAttribute('required') || '';
                    field.removeAttribute('required');
                }
            } else {
                if (hadRequired) {
                    delete field.dataset._danovaHadRequired;
                    if (field.dataset._danovaWasRequired !== undefined) {
                        field.setAttribute('required', field.dataset._danovaWasRequired || '');
                        delete field.dataset._danovaWasRequired;
                    }
                }
            }
        });
    }

    function guardModal(modal) {
        if (!modal || modal.dataset._danovaGuardInstalled === '1') return;
        modal.dataset._danovaGuardInstalled = '1';

        const observer = new MutationObserver(() => {
            syncRequiredFields(modal);
        });
        observer.observe(modal, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['hidden', 'style', 'class', 'disabled', 'required'],
        });

        syncRequiredFields(modal);
    }

    function watchNewModals() {
        const observer = new MutationObserver((mutations) => {
            mutations.forEach(m => {
                m.addedNodes.forEach(node => {
                    if (node.nodeType !== 1) return;
                    if (node.classList && node.classList.contains('modal')) {
                        guardModal(node);
                    }
                    if (node.querySelectorAll) {
                        node.querySelectorAll('.modal').forEach(guardModal);
                    }
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    function init() {
        document.querySelectorAll('.modal').forEach(guardModal);
        watchNewModals();
        console.info('[DANOVA] Required-field guard installed (v1)');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

/* =====================================================================
   END DANOVA_AUTOFIX_REQUIRED_FIELDS_V1
   ===================================================================== */

/* DANOVA_CACHE_BREAKER_v1 — تأیید نسخه در کنسول */
console.info('[DANOVA] app.js loaded — version 3.15.1 + RAG fix');
