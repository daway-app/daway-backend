/* Daway offline — entry point: banner, form interception, sync, hydration. */
import { db } from './db.js';
import { queueAddOp } from './queue.js';
import { sync } from './sync.js';
import { banner } from './banner.js';
import { intercept } from './intercept.js';
import { render } from './render.js';
import { fcm } from './fcm.js';

/* Seed IndexedDB from the inline @json payloads the first time (only if store is empty). */
const PAYLOAD_IDS = {
    'daway-offline-inventory': 'inventory',
    'daway-offline-medicines': 'medicines',
    'daway-offline-inquiries': 'inquiries',
};

function seedFromPage() {
    Object.entries(PAYLOAD_IDS).forEach(([elementId, store]) => {
        const el = document.getElementById(elementId);
        if (!el) return;
        try {
            const rows = JSON.parse(el.textContent);
            if (Array.isArray(rows) && rows.length) {
                // دمج دائم: يُحدّث صفوف الصفحة الحالية في الكاش ويُعيد بناء
                // أي صفوف فقدوها سابقاً (delta-bulkReplace القديم) — بلا حذف.
                db.putAll(store, rows).catch(() => {});
            }
        } catch (e) { /* invalid payload — ignore */ }
    });
}

function hydrateFromCache() {
    if (navigator.onLine) return; // online: server HTML stands
    return render.renderFromCache().catch(() => {});
}

/* نطاق العمل offline = نفس نطاق الـ Service Worker (OFFLINE_NAV_PREFIXES في sw.js).
   نبضة المزامنة تُشغَّل فقط داخل هذا النطاق: على صفحات الأدمن لا معنى لها وكانت
   تُنتج /healthz + /api/sync/pull كل 30 ثانية بلا داعٍ. */
const OFFLINE_SCOPE = /^\/(pharmacy|profile)(\/|$)/.test(window.location.pathname);

document.addEventListener('DOMContentLoaded', () => {
    banner.init();
    intercept.init();
    if (OFFLINE_SCOPE) sync.init();
    seedFromPage();
    hydrateFromCache();
    fcm.initFcm();
    window.addEventListener('daway:synced', () => {
        if (!navigator.onLine) {
            hydrateFromCache();
            return;
        }
        // نُعيد التحميل فقط إذا كانت هناك عمليات queue حقيقية تمت مزامنتها الآن
        // (meta flag يبقى عبر الـ reload — يمنع حلقة reload عند pull-only sync).
        db.metaGet('sync_had_pending').then((had) => {
            if (!had) return;
            db.metaSet('sync_had_pending', false);
            if (!window.__dawayReloadedAfterSync) {
                window.__dawayReloadedAfterSync = true;
                window.location.reload();
            }
        });
    });
});

/* تعريض الوحدات للـ window: زر «إعادة المحاولة» في الـ Banner
   وقرار الاعتراض يعتمدان عليه. */
window.DawayOffline = { db, queueAddOp, sync, banner, intercept, render, fcm };

export { db, queueAddOp, sync, banner, intercept, render, fcm };
