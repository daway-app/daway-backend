/* Daway offline — form interception: queue operations when submitting offline. */
import { db } from './db.js';
import { queueAddOp } from './queue.js';

function bannerSet(state, detail) {
    window.dispatchEvent(new CustomEvent('daway:banner', { detail: { state, ...detail } }));
}

function statusFor(qty) {
    return qty <= 0 ? 'out' : (qty <= 10 ? 'low' : 'ok');
}

function badgeText(status) {
    return { ok: 'متوفر', low: 'منخفض', out: 'غير متوفر' }[status] || '';
}

/* inventory: queue only CHANGED quantities */
function handleInventory(form) {
    const items = [];
    const promises = [];
    form.querySelectorAll('input[name^="quantities"]').forEach((input) => {
        const id = (input.name.match(/quantities\[(\d+)\]/) || [])[1];
        if (!id) return;
        const newValue = parseInt(input.value, 10) || 0;
        promises.push(db.get('inventory', Number(id)).then((row) => {
            const oldQty = row ? Number(row.quantity) : null;
            if (oldQty === null || oldQty !== newValue) {
                items.push({
                    pharmacy_medicine_id: Number(id),
                    quantity: newValue,
                    client_updated_at: new Date().toISOString(),
                });
                /* optimistic UI: update current-qty cell + status badge in this row */
                const rowEl = input.closest('tr');
                if (rowEl) {
                    const qtyCell = rowEl.children[2];
                    const badgeCell = rowEl.querySelector('.ph-badge');
                    if (qtyCell) qtyCell.textContent = newValue;
                    const status = statusFor(newValue);
                    if (badgeCell) {
                        badgeCell.className = 'ph-badge ' + status;
                        badgeCell.textContent = badgeText(status);
                    }
                    if (rowEl.dataset.status !== undefined) rowEl.dataset.status = status;
                }
            }
        }));
    });
    return Promise.all(promises).then(() => {
        if (!items.length) return false;
        return queueAddOp('inventory.update', { items }).then(() => true);
    });
}

function formPayload(form) {
    const data = new FormData(form);
    const payload = {};
    ['trade_name', 'trade_name_ar', 'active_ingredient', 'price', 'quantity'].forEach((field) => {
        const value = data.get(field);
        if (value !== null && String(value).trim() !== '') payload[field] = field === 'price' || field === 'quantity' ? Number(value) : String(value);
    });
    if (form.querySelector('input[name="is_available"]')) {
        payload.is_available = !!data.get('is_available');
    }
    return payload;
}

function handleMedicineCreate(form) {
    const payload = formPayload(form);
    return queueAddOp('medicine.store', payload).then(() => {
        form.reset();
        const manual = document.getElementById('manual_box');
        if (manual) manual.style.display = 'none';
        return true;
    });
}

function handleMedicineEdit(form) {
    const payload = formPayload(form);
    payload.pharmacy_medicine_id = Number(form.getAttribute('data-pharmacy-medicine-id'));
    return queueAddOp('medicine.update', payload).then(() => true);
}

function handleInquiryStatus(form) {
    const payload = {
        inquiry_id: Number(form.getAttribute('data-inquiry-id')),
        status: String((form.querySelector('input[name="status"]') || {}).value || 'answered'),
    };
    return queueAddOp('inquiry.status', payload).then(() => {
        const row = form.closest('tr');
        if (row) {
            const badge = row.querySelector('.ph-badge');
            const actions = form.parentElement;
            if (badge) {
                const cls = payload.status === 'answered' ? 'ans' : 'closed';
                badge.className = 'ph-badge ' + cls;
                badge.textContent = payload.status === 'answered' ? 'تم الرد' : 'مغلقة';
            }
            if (row.dataset.status !== undefined) row.dataset.status = payload.status === 'answered' ? 'ans' : 'closed';
            if (actions) actions.innerHTML = '';
        }
        return true;
    });
}

const HANDLERS = {
    'inventory': handleInventory,
    'medicine-create': handleMedicineCreate,
    'medicine-edit': handleMedicineEdit,
    'inquiry-status': handleInquiryStatus,
};

function interceptForm(form, kind) {
    form.addEventListener('submit', (event) => {
        if (navigator.onLine) return; // online: normal submit
        event.preventDefault();
        const handler = HANDLERS[kind];
        if (!handler) return;
        Promise.resolve().then(() => handler(form)).then((queued) => {
            if (queued) {
                bannerSet('offline');
                if (window.DawayOffline && window.DawayOffline.sync && window.DawayOffline.db) {
                    window.DawayOffline.db.queueAll().then((q) => bannerSet('offline', { queued: q.length }));
                }
            } else {
                bannerSet('online');
            }
        }).catch(() => bannerSet('failed', { count: 1 }));
    });
}

export const intercept = {
    init() {
        document.querySelectorAll('form[data-offline-form]').forEach((form) => {
            const kind = form.getAttribute('data-offline-form');
            if (HANDLERS[kind]) interceptForm(form, kind);
        });
    },
};
