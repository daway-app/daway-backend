/* Daway service worker — offline shell for pharmacy dashboard. */
const VERSION = 'daway-v3';
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

async function precachePharmacyPages() {
    const cache = await caches.open(VERSION);
    await Promise.allSettled(PHARMACY_PAGES.map(async (url) => {
        try {
            if (await cache.match(url)) return; // مخزّنة مسبقاً
            const response = await fetch(url, { credentials: 'same-origin', redirect: 'follow' });
            if (response && response.ok && response.type === 'basic') {
                await cache.put(url, response);
            }
        } catch (e) { /* offline أو فشل شبكة — ستُجرّب لاحقاً عبر DAWAY_PREFETCH */ }
    }));
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

    // Navigations (HTML):
    //  - صفحات الصيدلية/الملف الشخصي: stale-while-revalidate — تُخدم من الكاش فوراً
    //    وتُحدَّث بالخلفية عند توفر الشبكة. بدون كاش → شبكة → /offline كحل أخير.
    //  - بقية الصفحات: network-only مع fallback.
    if (request.mode === 'navigate') {
        if (isOfflinePage(url)) {
            event.respondWith(
                caches.open(VERSION).then(async (cache) => {
                    const cached = await cache.match(request, { ignoreSearch: false });
                    const network = fetch(request).then((response) => {
                        if (response && response.ok && response.type === 'basic') {
                            cache.put(request, response.clone());
                        }
                        return response;
                    }).catch(() => null);

                    if (cached) {
                        network.catch(() => {});
                        return cached;
                    }

                    const fresh = await network;
                    if (fresh) return fresh;

                    return caches.match('/offline').then((resp) =>
                        resp || new Response('offline', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } })
                    );
                })
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
