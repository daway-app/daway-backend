/* Daway service worker — offline shell for pharmacy dashboard. */
const VERSION = 'daway-v1';
const PRECACHE_URLS = [
    '/offline',
    '/vendor/chart.umd.js',
    '/manifest.json',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
];

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
    );
});

const STATIC_PREFIXES = ['/build/', '/icons/', '/vendor/', '/css/', '/js/', '/images/'];
const STATIC_EXTENSIONS = /\.(css|js|png|jpg|jpeg|webp|svg|gif|ico|woff2?|ttf|eot)$/i;

function isStaticAsset(url) {
    return STATIC_PREFIXES.some((p) => url.pathname.startsWith(p)) || STATIC_EXTENSIONS.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') return; // never cache POST/PUT/DELETE

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return; // ignore cross-origin (CDN, FontAwesome)
    if (url.pathname.startsWith('/api/')) return; // never touch API
    if (url.pathname === '/login' || url.pathname.startsWith('/logout')) return;

    // Navigations (HTML): network-only, offline fallback to /offline
    if (request.mode === 'navigate') {
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
