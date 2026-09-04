/* Daway offline — sync status banner (RTL, Arabic) using pharmacy_hub design tokens. */
const STYLES = `
.daway-sync-banner{position:fixed;top:0;left:0;right:0;z-index:9999;display:none;align-items:center;justify-content:center;gap:10px;padding:10px 18px;font-size:.88rem;font-weight:600;font-family:inherit;box-shadow:0 1px 3px rgba(12,34,36,.06);}
.daway-sync-banner.show{display:flex;}
.daway-sync-banner.online{background:var(--ph-green-bg,#DCFCE7);color:var(--ph-green,#16A34A);}
.daway-sync-banner.offline{background:var(--ph-orange-bg,#FEF9C3);color:var(--ph-orange,#CA8A04);}
.daway-sync-banner.syncing{background:var(--ph-teal-mist,#EAF5F4);color:var(--ph-teal,#0B8FAC);}
.daway-sync-banner.synced{background:var(--ph-green-bg,#DCFCE7);color:var(--ph-green,#16A34A);}
.daway-sync-banner.failed{background:var(--ph-red-bg,#FEE2E2);color:var(--ph-red,#DC2626);}
.daway-sync-banner.auth{background:var(--ph-red-bg,#FEE2E2);color:var(--ph-red,#DC2626);}
.daway-sync-banner .dsb-retry{border:1px solid currentColor;background:transparent;color:inherit;border-radius:9999px;padding:5px 14px;font-size:.8rem;font-weight:700;cursor:pointer;font-family:inherit;}
.daway-sync-banner i{font-size:1rem;}
`;

const MESSAGES = {
    online: 'عاد الاتصال بالإنترنت',
    offline: 'غير متصل — التغييرات محفوظة وستتم مزامنتها عند عودة الإنترنت',
    synced: 'تمت المزامنة بنجاح',
    auth: 'انتهت الجلسة — سجّل الدخول لإتمام المزامنة',
};

let bannerEl = null;
let hideTimer = null;
let lastSyncCount = 0;

function setState(state, detail = {}) {
    if (!bannerEl) return;
    clearTimeout(hideTimer);
    bannerEl.className = 'daway-sync-banner show ' + state;
    let text = MESSAGES[state] || '';
    if (state === 'syncing') text = 'جارٍ المزامنة (' + (detail.count || 0) + ' عملية...)';
    if (state === 'failed') text = 'فشلت مزامنة ' + (detail.count || 0) + ' عملية — ستتم إعادة المحاولة';
    bannerEl.innerHTML = '';
    const span = document.createElement('span');
    span.textContent = text;
    bannerEl.appendChild(span);
    if (state === 'failed' || state === 'auth') {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dsb-retry';
        btn.textContent = 'إعادة المحاولة';
        btn.addEventListener('click', () => {
            if (state === 'auth') { window.location.href = '/login'; return; }
            setState('syncing', { count: lastSyncCount });
            if (window.DawayOffline && window.DawayOffline.sync) window.DawayOffline.sync.checkThenSync();
        });
        bannerEl.appendChild(btn);
    }
    if (state === 'online' || state === 'synced') {
        hideTimer = setTimeout(() => bannerEl.classList.remove('show'), 2000);
    }
}

export const banner = {
    init() {
        if (document.getElementById('daway-sync-banner-root')) {
            const style = document.createElement('style');
            style.textContent = STYLES;
            document.head.appendChild(style);
            bannerEl = document.createElement('div');
            bannerEl.className = 'daway-sync-banner';
            const root = document.getElementById('daway-sync-banner-root');
            root.style.position = 'relative';
            root.style.zIndex = '9999';
            root.appendChild(bannerEl);
            window.addEventListener('daway:banner', (event) => {
                const d = event.detail || {};
                if (d.state === 'syncing') lastSyncCount = d.count || lastSyncCount;
                setState(d.state, d);
            });
        }
    },
    setState,
};
