/* Daway offline — render cached data into offline-marked pages (mirrors server markup). */
import { db } from './db.js';

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.textContent;
}

function statusFor(qty) {
    return qty <= 0 ? 'out' : (qty <= 10 ? 'low' : 'ok');
}

function badge(status, text) {
    return '<span class=\'ph-badge ' + status + '\'>' + escapeHtml(text) + '</span>';
}

function renderInventory(items) {
    const tbody = document.querySelector('[data-offline-page="inventory"] tbody');
    if (!tbody) return;
    const statusText = { ok: 'متوفر', low: 'منخفض', out: 'غير متوفر' };
    tbody.innerHTML = items.map((item) => {
        const qty = Number(item.quantity) || 0;
        const status = statusFor(qty);
        const med = item.medicine || {};
        const tradeName = med.trade_name || item.trade_name || '';
        const ingredient = med.active_ingredient || item.active_ingredient || '';
        return '<tr data-status=\'' + status + '\' data-min=\'10\'>' +
            '<td><strong>' + escapeHtml(tradeName) + '</strong><br><small style=\'color:var(--ph-ink-faint);\'>' + escapeHtml(ingredient) + '</small></td>' +
            '<td>' + badge(status, statusText[status]) + '</td>' +
            '<td>' + qty + '</td>' +
            '<td><div class=\'ph-stepper\'>' +
                '<button type=\'button\' class=\'dec\'><i class=\'fas fa-minus\'></i></button>' +
                '<input type=\'number\' name=\'quantities[' + item.id + ']\' value=\'' + qty + '\' min=\'0\'>' +
                '<button type=\'button\' class=\'inc\'><i class=\'fas fa-plus\'></i></button>' +
            '</div></td>' +
        '</tr>';
    }).join('');
}

function renderMedicines(items) {
    const tbody = document.querySelector('[data-offline-page="medicines"] tbody');
    if (!tbody) return;
    const statusText = { ok: 'متوفر', low: 'منخفض', out: 'غير متوفر' };
    tbody.innerHTML = items.map((item) => {
        const qty = Number(item.quantity) || 0;
        const status = statusFor(qty);
        const med = item.medicine || {};
        const tradeName = med.trade_name || item.trade_name || '';
        const ingredient = med.active_ingredient || item.active_ingredient || '';
        return '<tr data-status=\'' + status + '\' data-min=\'10\'>' +
            '<td><div style=\'display:flex;align-items:center;gap:12px;\'><div class=\'ph-med-thumb\'><i class=\'fas fa-pills\'></i></div>' +
                '<div><strong>' + escapeHtml(tradeName) + '</strong><br><small style=\'color:var(--ph-ink-faint);\'>' + escapeHtml(med.strength || item.strength || '') + '</small></div></div></td>' +
            '<td>' + escapeHtml(ingredient) + '</td>' +
            '<td>' + Number(item.price || 0).toFixed(2) + ' شيكل</td>' +
            '<td>' + qty + '</td>' +
            '<td>' + badge(status, statusText[status]) + '</td>' +
            '<td></td>' +
        '</tr>';
    }).join('');
}

function renderInquiries(items) {
    const tbody = document.querySelector('[data-offline-page="inquiries"] tbody');
    if (!tbody) return;
    const statusText = { new: 'جديد', answered: 'تم الرد', closed: 'مغلقة' };
    tbody.innerHTML = items.map((item) => {
        const status = item.status || 'new';
        const badgeClass = status === 'new' ? 'new' : (status === 'answered' ? 'ans' : 'closed');
        const name = (item.user && item.user.name) || 'مستخدم';
        const initials = String(name).slice(0, 2);
        const date = item.created_at ? String(item.created_at).slice(0, 10) : '';
        return '<tr data-status=\'' + status + '\'>' +
            '<td><div style=\'display:flex;align-items:center;gap:10px;\'><span class=\'ph-avatar-sm\'>' + escapeHtml(initials) + '</span><strong>' + escapeHtml(name) + '</strong></div></td>' +
            '<td>' + escapeHtml((item.medicine && item.medicine.trade_name) || 'دواء') + '</td>' +
            '<td style=\'max-width:280px;\'>' + escapeHtml(item.message || '') + '</td>' +
            '<td>' + escapeHtml(date) + '</td>' +
            '<td>' + badge(badgeClass, statusText[status] || status) + '</td>' +
            '<td></td>' +
        '</tr>';
    }).join('');
}

/* إعادة ربط أزرار +/− بعد إعادة بناء الجدول من الكاش —
   innerHTML ينشئ عناصر جديدة بلا مستمعين (pharmacy_hub ربط القديمة فقط). */
function attachSteppers(scope) {
    scope.querySelectorAll('.ph-stepper').forEach((s) => {
        const input = s.querySelector('input');
        const dec = s.querySelector('.dec');
        const inc = s.querySelector('.inc');
        if (!input || input.dataset.stepperInit) return;
        input.dataset.stepperInit = '1';
        const update = () => {
            let v = parseInt(input.value, 10) || 0;
            if (v < 0) v = 0;
            input.value = v;
            const row = s.closest('[data-status]');
            if (!row) return;
            const min = parseInt(row.dataset.min || 0);
            const status = v === 0 ? 'out' : (v <= min ? 'low' : 'ok');
            row.dataset.status = status;
            const badgeEl = row.querySelector('.ph-badge');
            if (badgeEl) {
                badgeEl.className = 'ph-badge ' + status;
                const i18n = (window.phHubI18n && window.phHubI18n.status) || {};
                badgeEl.textContent = status === 'ok' ? (i18n.available || 'متوفر')
                    : (status === 'low' ? (i18n.low || 'منخفض') : (i18n.out || 'غير متوفر'));
            }
        };
        if (dec) dec.addEventListener('click', () => { input.value = (parseInt(input.value, 10) || 0) - 1; update(); });
        if (inc) inc.addEventListener('click', () => { input.value = (parseInt(input.value, 10) || 0) + 1; update(); });
        input.addEventListener('input', update);
    });
}

export const render = {
    renderFromCache() {
        return Promise.all([
            db.getAll('inventory').then((rows) => { renderInventory(rows); attachSteppers(document); }),
            db.getAll('medicines').then((rows) => renderMedicines(rows)),
            db.getAll('inquiries').then((rows) => renderInquiries(rows)),
        ]);
    },
};
