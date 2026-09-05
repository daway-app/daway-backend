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

/* inventory: queue only CHANGED quantities + persist new values to IndexedDB */
function handleInventory(form) {
    const items = [];
    const storeUpdates = [];
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
                /* persist optimistically so offline re-render keeps the user's edit */
                if (row) {
                    row.quantity = newValue;
                    row.updated_at = new Date().toISOString();
                    storeUpdates.push(db.put('inventory', row));
                }
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
        return Promise.all(storeUpdates)
            .catch(() => {})
            .then(() => queueAddOp('inventory.update', { items }))
            .then(() => true);
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
    return queueAddOp('medicine.store', payload).then((op) => {
        /* optimistic: add to local medicines store so offline re-render shows it */
        try {
            db.put('medicines', {
                id: 'local-' + op.uuid,
                price: payload.price || 0,
                quantity: payload.quantity || 0,
                is_available: payload.is_available !== undefined ? payload.is_available : (payload.quantity || 0) > 0,
                updated_at: op.client_updated_at,
                medicine: {
                    id: null,
                    trade_name: payload.trade_name || '',
                    trade_name_ar: payload.trade_name_ar || '',
                    active_ingredient: payload.active_ingredient || '',
                    strength: '',
                },
            }).catch(() => {});
        } catch (e) { /* non-fatal */ }
        form.reset();
        const manual = document.getElementById('manual_box');
        if (manual) manual.style.display = 'none';
        return true;
    });
}

function handleMedicineEdit(form) {
    const payload = formPayload(form);
    const pmId = Number(form.getAttribute('data-pharmacy-medicine-id'));
    payload.pharmacy_medicine_id = pmId;
    return queueAddOp('medicine.update', payload).then((op) => {
        /* persist optimistic edit so offline re-render keeps it */
        db.get('medicines', pmId).then((row) => {
            if (!row) return;
            if (payload.quantity !== undefined) row.quantity = payload.quantity;
            if (payload.price !== undefined) row.price = payload.price;
            if (payload.is_available !== undefined) row.is_available = payload.is_available;
            row.updated_at = op.client_updated_at;
            return db.put('medicines', row);
        }).catch(() => {});
        return true;
    });
}

function handleInquiryStatus(form) {
    const inquiryId = Number(form.getAttribute('data-inquiry-id'));
    const payload = {
        inquiry_id: inquiryId,
        status: String((form.querySelector('input[name="status"]') || {}).value || 'answered'),
    };
    return queueAddOp('inquiry.status', payload).then(() => {
        /* persist optimistic status change */
        db.get('inquiries', inquiryId).then((row) => {
            if (!row) return;
            row.status = payload.status;
            return db.put('inquiries', row);
        }).catch(() => {});
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

/* قرار الحفظ: navigator.onLine وحده يكذب (VPN/سيرفر متوقف) —
   نعتمد heartbeat الـ sync، وعند الغموض أو فشل واحد نفحص /healthz (GET) مرتين
   قبل وضع mutation في الـ queue — فشل متقطع واحد لا يخفي حفظاً طبيعياً. */
function probeOnce() {
    return Promise.race([
        fetch('/healthz', { method: 'GET', cache: 'no-store' })
            .then(() => true)   // السيرفر متاح
            .catch(() => false),
        new Promise((resolve) => setTimeout(() => resolve(false), 4000)),
    ]);
}

function shouldQueue() {
    if (!navigator.onLine) return Promise.resolve(true);
    const sync = (window.DawayOffline && window.DawayOffline.sync) || null;
    const reachable = sync && sync.isServerReachable ? sync.isServerReachable() : null;
    if (reachable === true) return Promise.resolve(false);
    if (reachable === false) {
        // الـ heartbeat يقول offline — نعيد المحاولة مرة قبل الـ queue (تجنّب false-offline)
        return probeOnce().then((up) => !up);
    }
    // غير معروف: probe — وإن فشل نعيد المحاولة مرة ثانية قبل القرار
    return probeOnce().then((up) => up ? Promise.resolve(false) : probeOnce().then((up2) => !up2));
}

function interceptForm(form, kind) {
    form.addEventListener('submit', (event) => {
        event.preventDefault(); // القرار أولاً — ثم إما queue أو إرسال أصلي
        shouldQueue().then((queue) => {
            if (!queue) {
                form.submit(); // إرسال أصلي (يتجاوز هذا المستمع) — السيرفر متاح
                return;
            }
            const handler = HANDLERS[kind];
            if (!handler) { form.submit(); return; }
            return Promise.resolve().then(() => handler(form)).then((queued) => {
                if (queued) {
                    if (window.DawayOffline && window.DawayOffline.db) {
                        window.DawayOffline.db.queueAll().then((q) => bannerSet('queued', { count: q.length }));
                    } else {
                        bannerSet('queued', { count: 1 });
                    }
                } else {
                    bannerSet('online');
                }
            }).catch(() => bannerSet('failed', { count: 1 }));
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
