/* Daway service worker — offline shell for pharmacy dashboard. */
const VERSION = 'daway-v4';
const PRECACHE_URLS = [
    '/offline',
    '/vendor/chart.umd.js',
    '/manifest.json',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
];

// صفحات الصيدلية — تُخزَّن تلقائياً لحل مشكلة "الزيارة الأولى".
// الـ fetch داخل الـ SW يرسل كوكيز الجلسة تلقائياً (same-origin)،
// وإذا المستخدم غير مسجل يرجع السيرفر 302 → response غير ok → لا يُخزَّن شيء.
const PHARMACY_PAGES = [
    '/pharmacy/dashboard',
    '/pharmacy/inventory',
    '/pharmacy/medicines',
    '/pharmacy/medicines/create',
    '/pharmacy/inquiries',
    '/pharmacy/alternatives',
    '/pharmacy/ratings',
    '/profile',
];

// تخزين مسبق لأصول الـ build الحالية من الـ manifest — يضمن أن الصفحات
// الجديدة تجد CSS/JS حتى قبل أول زيارة، وoffline تماماً.
async function precacheBuildAssets() {
    try {
        const manifestResponse = await fetch('/build/manifest.json', { cache: 'no-store' });
        if (!manifestResponse.ok) return;
        const manifest = await manifestResponse.json();
        const files = Object.values(manifest)
            .flatMap((entry) => [entry.file, ...(entry.css || [])])
            .filter((f) => typeof f === 'string' && f.startsWith('/build/'));
        const cache = await caches.open(VERSION);
        await Promise.allSettled(files.map(async (file) => {
            if (await cache.match(file)) return;
            const response = await fetch(file);
            if (response && response.ok) await cache.put(file, response);
        }));
    } catch (e) { /* الأصول تُخزَّن لاحقاً عند التصفح العادي */ }
}

async function precachePharmacyPages() {
    const cache = await caches.open(VERSION);
    // A6-9: تسلسل بدل تزامن — 8 صفحات مصادَقة ثقيلة في وقت واحد كانت تُجمّد
    // العامل الواحد بعد كل deploy؛ طلب واحد كل 400ms يوزّع الحمل
    for (const url of PHARMACY_PAGES) {
        try {
            if (await cache.match(url)) continue; // موجود مسبقاً — تخطَّ
            const response = await fetch(url, { credentials: 'same-origin', redirect: 'follow' });
            if (response && response.ok && response.type === 'basic') {
                await cache.put(url, response.clone());
            }
        } catch (e) { /* offline أو خطأ متقطع — نتجاهل (يعاد لاحقاً عبر DAWAY_PREFETCH) */ }
        await new Promise((r) => setTimeout(r, 400));
    }
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(VERSION).then((cache) =>
            Promise.allSettled(PRECACHE_URLS.map((url) => cache.add(url)))
        ).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
        .then(() => precacheBuildAssets())
        .then(() => precachePharmacyPages())
    );
});

// إعادة محاولة التخزين عند طلب الصفحة (بعد كل مزامنة ناجحة / تسجيل دخول)
self.addEventListener('message', (event) => {
    if (event.data === 'DAWAY_PREFETCH') {
        event.waitUntil(precachePharmacyPages());
    }
});

const STATIC_PREFIXES = ['/build/', '/icons/', '/vendor/', '/css/', '/js/', '/images/'];
const STATIC_EXTENSIONS = /\.(css|js|png|jpg|jpeg|webp|svg|gif|ico|woff2?|ttf|eot)$/i;

// صفحات الموقع الصيدلي — تُخزّن وتُخدم offline (stale-while-revalidate)
const OFFLINE_NAV_PREFIXES = ['/pharmacy', '/profile'];

function isStaticAsset(url) {
    return STATIC_PREFIXES.some((p) => url.pathname.startsWith(p)) || STATIC_EXTENSIONS.test(url.pathname);
}

function isOfflinePage(url) {
    return OFFLINE_NAV_PREFIXES.some((p) => url.pathname === p || url.pathname.startsWith(p + '/'));
}

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') return; // never cache POST/PUT/DELETE

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return; // ignore cross-origin (CDN, FontAwesome)
    if (url.pathname.startsWith('/api/')) return; // never touch API
    if (url.pathname === '/login' || url.pathname.startsWith('/logout')) return;
    if (url.pathname === '/offline') return;
    if (url.pathname === '/firebase-messaging-sw.js') return; // FCM worker بمساره الخاص

    // Navigations (HTML):
    //  - صفحات الصيدلية/الملف الشخصي: NETWORK-FIRST مع fallback للكاش.
    //    أونلاين → HTML طازج دائماً (يُصلح زر اللغة /locale/* — كان SWR يخدم
    //    النسخة القديمة بعد الـ redirect فيبدو أن التبديل لا يعمل).
    //    offline → الكاش فوراً (fetch يفشل فوراً)، وبلا كاش → /offline.
    //  - بقية الصفحات: network-only مع fallback.
    if (request.mode === 'navigate') {
        if (isOfflinePage(url)) {
            event.respondWith(
                (async () => {
                    try {
                        const controller = new AbortController();
                        const timer = setTimeout(() => controller.abort(), 3000);
                        const fresh = await fetch(request, { signal: controller.signal, credentials: 'same-origin' });
                        clearTimeout(timer);
                        if (fresh && fresh.ok && fresh.type === 'basic') {
                            const cache = await caches.open(VERSION);
                            cache.put(request, fresh.clone());
                        }
                        return fresh;
                    } catch (e) {
                        // offline (أو timeout نادر) — من الكاش
                        const cached = await caches.match(request, { ignoreSearch: false });
                        if (cached) return cached;
                        return caches.match('/offline').then((resp) =>
                            resp || new Response('offline', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } })
                        );
                    }
                })()
            );
            return;
        }

        event.respondWith(
            fetch(request).catch(() =>
                caches.match('/offline').then((resp) =>
                    resp || new Response('offline', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } })
                )
            )
        );
        return;
    }

    // Same-origin static assets: cache-first with network fill
    if (isStaticAsset(url)) {
        event.respondWith(
            caches.match(request).then((cached) => {
                if (cached) return cached;
                return fetch(request).then((response) => {
                    if (response && response.ok) {
                        const clone = response.clone();
                        caches.open(VERSION).then((cache) => cache.put(request, clone));
                    }
                    return response;
                }).catch(() => new Response('', { status: 504 }));
            })
        );
    }
});
