/* Daway offline — entry point: banner, form interception, sync, hydration. */
import { db } from './db.js';
import { queueAddOp } from './queue.js';
import { sync } from './sync.js';
import { banner } from './banner.js';
import { intercept } from './intercept.js';
import { render } from './render.js';

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
        db.count(store).then((count) => {
            if (count > 0) return;
            try {
                const rows = JSON.parse(el.textContent);
                if (Array.isArray(rows) && rows.length) return db.putAll(store, rows);
            } catch (e) { /* invalid payload — ignore */ }
        }).catch(() => {});
    });
}

function hydrateFromCache() {
    if (navigator.onLine) return; // online: server HTML stands
    return render.renderFromCache().catch(() => {});
}

document.addEventListener('DOMContentLoaded', () => {
    banner.init();
    intercept.init();
    sync.init();
    seedFromPage();
    hydrateFromCache();
    window.addEventListener('daway:synced', () => {
        if (!navigator.onLine) hydrateFromCache();
    });
});

export { db, queueAddOp, sync, banner, intercept, render };
