/* Daway offline — SyncManager: token, heartbeat, push/pull. */
import { db } from './db.js';

const TOKEN_KEY = 'sync_token';
const PUSH_BATCH = 200;

function emit(name, detail) {
    window.dispatchEvent(new CustomEvent(name, { detail: detail || {} }));
}

function setBanner(state, detail) {
    emit('daway:banner', { state, ...detail });
}

function fetchWithTimeout(url, options = {}, timeoutMs = 5000) {
    return Promise.race([
        fetch(url, options),
        new Promise((resolve, reject) => setTimeout(() => reject(new Error('timeout')), timeoutMs)),
    ]);
}

export const sync = {
    attempts: {},
    MAX_ATTEMPTS: 5,     // M-29: سقف المحاولات — لا حلقة إعادة للأبد
    timer: null,
    serverUp: null,      // آخر نتيجة heartbeat حقيقية (navigator.onLine يكذب)
    lastCheckAt: 0,
    failStreak: 0,       // عدد الفشلات المتتالية — لا نعتبر Offline قبل فشلين

    init() {
        window.addEventListener('online', () => {
            this.failStreak = 0;
            this.checkThenSync();
        });
        window.addEventListener('offline', () => {
            this.serverUp = false;
            this.lastCheckAt = Date.now();
            setBanner('offline');
        });
        // heartbeat: navigator.onLine lies on captive portals
        this.timer = setInterval(() => {
            // H-10: تاب مخفي = إيقاف الـ heartbeat (المتصفح يستأنف عند العودة)
            if (document.hidden) return;
            this.checkThenSync();
        }, 30000);
        // H-10: عند إعادة ظهور التاب نفحص فوراً بعد الغياب الطويل
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) this.checkThenSync();
        });
        if (navigator.onLine) this.checkThenSync();
        else { this.serverUp = false; this.lastCheckAt = Date.now(); setBanner('offline'); }
    },

    /* null = غير معروف (heartbeat قديم) — true/false = نتيجة حديثة (خلال 20 ثانية) */
    isServerReachable() {
        if (Date.now() - this.lastCheckAt > 20000) return null;
        return this.serverUp;
    },

    checkThenSync() {
        // GET بدل HEAD — Render يقطع بعض طلبات HEAD بشكل متقطع
        fetchWithTimeout('/healthz', { method: 'GET', cache: 'no-store' }, 5000)
            .then(() => {
                this.failStreak = 0;
                this.serverUp = true;
                this.lastCheckAt = Date.now();
                return this.runSync();
            })
            .catch(() => {
                // فشل واحد متقطع لا يعني Offline — نحتاج فشلين متتاليين
                this.failStreak = (this.failStreak || 0) + 1;
                if (this.failStreak >= 2) {
                    this.serverUp = false;
                    this.lastCheckAt = Date.now();
                }
                /* still offline (أو فشل متقطع واحد — نحافظ على الحالة السابقة) */
            });
    },

    ensureToken() {
        const cached = sessionStorage.getItem(TOKEN_KEY);
        if (cached) return Promise.resolve(cached);
        return fetch('/api/sync/token', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        }).then((response) => {
            if (response.status === 401 || response.status === 419) {
                setBanner('auth');
                throw new Error('unauthenticated');
            }
            if (!response.ok) throw new Error('token request failed');
            return response.json();
        }).then((body) => {
            // السيرفر قد يُعيد الشكلين: {token} أو {data:{token}} — نقبل كليهما
            const issued = body && (body.token || (body.data && body.data.token));
            if (!body || !body.success || !issued) throw new Error('bad token payload');
            sessionStorage.setItem(TOKEN_KEY, issued);
            return issued;
        });
    },

    authHeaders(token) {
        return {
            'Authorization': 'Bearer ' + token,
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };
    },

    runSync() {
        return db.queueAll().then((queue) => {
            if (!queue.length) return this.pull().catch(() => {});
            setBanner('syncing', { count: queue.length });
            // علِمنا أن عمليات حقيقية تُزامن الآن — index.js يعيد التحميل مرة واحدة
            // بعدها فقط (بدون هذا، كل pull ناجح يطلق reload = حلقة لا نهائية).
            db.metaSet('sync_had_pending', true);
            let issued;
            return this.ensureToken().then((token) => {
                issued = token;
                return this.push(token, queue);
            }).then(() => this.pull(issued))
                .catch((error) => {
                    // فشل الـ push/pull — لا نعلق على «جارٍ المزامنة»: نعرض الفشل مع إعادة محاولة
                    // والـ queue يبقى كما هو (لا تضيع أي عملية).
                    setBanner('failed', { count: queue.length });
                    throw error;
                });
        });
    },

    // يطلب من الـ SW تخزين صفحات الصيدلية المتبقية (يحل مشكلة "الزيارة الأولى")
    requestPrefetch() {
        if (!navigator.serviceWorker || !navigator.serviceWorker.controller) return;
        try {
            navigator.serviceWorker.controller.postMessage('DAWAY_PREFETCH');
        } catch (e) { /* SW not controlling yet */ }
    },

    push(token, queue) {
        const batch = queue.slice(0, PUSH_BATCH);
        if (!batch.length) return Promise.resolve();
        return fetch('/api/sync/push', {
            method: 'POST',
            headers: this.authHeaders(token),
            body: JSON.stringify({
                operations: batch.map((op) => ({
                    uuid: op.uuid,
                    op_type: op.op_type,
                    payload: op.payload,
                    client_updated_at: op.client_updated_at,
                })),
            }),
        }).then((response) => {
            if (response.status === 401) {
                sessionStorage.removeItem(TOKEN_KEY);
                setBanner('auth');
                throw new Error('unauthenticated');
            }
            if (!response.ok) throw new Error('push failed');
            return response.json();
        }).then((body) => {
            const results = (body && body.data && body.data.results) || [];
            let failures = 0;
            const appliedUuids = [];
            results.forEach((result) => {
                if (result.status === 'applied' || result.status === 'conflict' || result.duplicate) {
                    appliedUuids.push(result.uuid);
                } else {
                    failures += 1;
                    this.attempts[result.uuid] = (this.attempts[result.uuid] || 0) + 1;
                }
            });
            // M-29: العمليات التي تجاوزت السقف تُحذف من الطابور (dead-letter محلي) —
            // منع retry storm للأبد؛ يظهر banner واحد يذكر عدد العمليات المتروكة
            const deadUuids = appliedUuids.length ? [] : [];
            queue.forEach((op) => {
                if (!appliedUuids.includes(op.uuid) && (this.attempts[op.uuid] || 0) >= this.MAX_ATTEMPTS) {
                    deadUuids.push(op.uuid);
                }
            });
            return Promise.all(appliedUuids.concat(deadUuids).map((uuid) => db.queueDelete(uuid)))
                .then(() => {
                    const remaining = failures - deadUuids.length;
                    if (deadUuids.length > 0) setBanner('failed', { count: remaining, dropped: deadUuids.length });
                    else if (remaining > 0) setBanner('failed', { count: remaining });
                    return this.push(token, queue.slice(PUSH_BATCH));
                });
        });
    },

    pull(token) {
        return this.ensureToken().then((t) => {
            const since = db.metaGet('last_pulled_at').then((value) => value || '');
            return since.then((sinceValue) => fetch('/api/sync/pull?since=' + encodeURIComponent(sinceValue), {
                headers: this.authHeaders(token || t),
            }));
        }).then((response) => {
            if (response.status === 401) {
                sessionStorage.removeItem(TOKEN_KEY);
                setBanner('auth');
                throw new Error('unauthenticated');
            }
            if (!response.ok) throw new Error('pull failed');
            return response.json();
        }).then((body) => {
            const data = (body && body.data) || {};
            const replace = [];
            // ⚠️ الـ pull يعيد DELTA (التغييرات فقط منذ آخر مزامنة) — نستخدم putAll
            // (تحديث/إدراج فقط). bulkReplace كان يحذف كل الصفوف غير الموجودة في
            // الرد → الكاش المحلي يفقد أدوية سليمة («الدواء ناقص» offline).
            // الحذف الفعلي يُدار عبر deleted_pharmacy_medicine_ids فقط.
            if (data.inventory && data.inventory.length) replace.push(db.putAll('inventory', data.inventory));
            if (data.inquiries && data.inquiries.length) replace.push(db.putAll('inquiries', data.inquiries));
            if (data.deleted_pharmacy_medicine_ids && data.deleted_pharmacy_medicine_ids.length) {
                replace.push(Promise.all(
                    data.deleted_pharmacy_medicine_ids.map((id) => db.delete('medicines', id))
                ));
            }
            return Promise.all(replace).then(() => {
                if (data.server_time) return db.metaSet('last_pulled_at', data.server_time);
            }).then(() => {
                // «تمت المزامنة» تُعرض فقط عندما كانت هناك عمليات queue حقيقية
                // تمت مزامنتها للتو — الـ pull الدوري الصامت لا يُظهر شيئاً.
                db.metaGet('sync_had_pending').then((had) => {
                    if (had) setBanner('synced');
                    emit('daway:synced', data);
                    this.requestPrefetch();
                });
            });
        });
    },
};
