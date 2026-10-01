/* Daway service worker — offline shell for pharmacy dashboard. */
// v5: استبدال ستَب Chart.js بالمكتبة الحقيقية + إصلاح فلتر تجهيز ملفات الـ build
//// v6 (أمني): منع تسرّب HTML المصادَق بين المستخدمين.
// السبب: كان الـ SW يخزّن صفحات /pharmacy/* و /profile المصادَق عليها في كاش
// واحد بمفتاح URL فقط (Cache API لا يعرف الكوكيز/الجلسة). فلو زار مستخدم A
// صفحة ما، ثم سجّل مستخدم B دخوله على نفس المتصفح وكانت الشبكة أبطأ من مهلة
// السباق، كان `return cached` يخدم HTML الخاص بـ A إلى B. الحل: لا تخزين
// ولا إعادة تقديم لأي HTML مصادَق إطلاقاً — تنقّل network-only دائماً،
// مع إبقاء الكاش للأصول الثابتة العامة فقط، والأوفلاين الحقيقي يعتمد على
// IndexedDB (resources/js/offline/*) لا على HTML مخزَّن.
// v7 (تنظيف): رفع النسخة لطرد كاش الأصول القديم. السبب: عطل /pharmacy/medicines
// (صفوف بصورة فقط) كان مُهيّئ الأوفلاين القديم في حزمة JS بائتة؛ و`activate` لا
// يحذف الكاش إلا عند تغيّر VERSION. رفع النسخة يضمن أن متصفحات المستخدمين
// تطرح أي حزمة قديمة تحمل `renderMedicines` وتلتقط الحزمة الحالية النظيفة.
const VERSION = 'daway-v7';

// الأوفلاين يُبنى من IndexedDB hydration، لا من HTML مخزَّن. تبقى القشرة فقط.
const PRECACHE_URLS = [
    '/offline',
    '/vendor/chart.umd.js',
    '/manifest.json',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
];

// تخزين مسبق لأصول الـ build الحالية من الـ manifest — يضمن أن الصفحات
// الجديدة تجد CSS/JS حتى قبل أول زيارة، وoffline تماماً.
async function precacheBuildAssets() {
    try {
        const manifestResponse = await fetch('/build/manifest.json', { cache: 'no-store' });
        if (!manifestResponse.ok) return;
        const manifest = await manifestResponse.json();

        // مسارات الـ manifest نسبية داخل public/build (مثل "assets/app-xxxx.css")،
        // لذا نُسبقها بـ /build/ بدل ترشيحها بـ startsWith('/build/') — الفلتر القديم
        // كان يُسقط كل الملفات لأن أي ملف لا يبدأ فعلاً بـ /build/.
        const toUrl = (f) => {
            if (typeof f !== 'string' || f === '') return null;
            if (f.startsWith('/')) return f;
            if (f.startsWith('http')) return f;
            return '/build/' + f.replace(/^\.?\/*/, '');
        };

        const files = Object.values(manifest)
            .flatMap((entry) => (entry && typeof entry === 'object' ? [entry.file, ...(entry.css || [])] : []))
            .map(toUrl)
            .filter(Boolean);

        const unique = [...new Set(files)];
        const cache = await caches.open(VERSION);
        await Promise.allSettled(unique.map(async (file) => {
            if (await cache.match(file)) return;
            const response = await fetch(file);
            if (response && response.ok) await cache.put(file, response);
        }));
    } catch (e) { /* الأصول تُخزَّن لاحقاً عند التصفح العادي */ }
}

/* v6: مسح كل كاشات التطبيق. يُستدعى من الصفحة عند تسجيل الخروج — يضمن ألا
   يبقى أي أثر (أصول/قشرة) من جلسة مستخدم سابق. لا يمسح إلا كاشات Daway. */
async function purgeCaches() {
    const keys = await caches.keys();
    await Promise.all(
        keys.filter((k) => k.startsWith('daway-')).map((k) => caches.delete(k))
    );
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
            Promise.all(keys.filter((k) => k !== VERSION && k.startsWith('daway-')).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
        .then(() => precacheBuildAssets())
    );
});

// مسح عند تسجيل الخروج (ترسلها الصفحة قبل إرسال نموذج الخروج).
self.addEventListener('message', (event) => {
    if (event.data === 'DAWAY_PURGE') {
        event.waitUntil(purgeCaches());
    }
});

const STATIC_PREFIXES = ['/build/', '/icons/', '/vendor/', '/css/', '/js/', '/images/'];
const STATIC_EXTENSIONS = /\.(css|js|png|jpg|jpeg|webp|svg|gif|ico|woff2?|ttf|eot)$/i;

/* v6: لا "صفحات offline" تُخزَّن كـ HTML. كانت قائمة نطاقات الأوفلاين تخدم HTML
   مصادَقاً من كاش مشترك = تسرّب بين المستخدمين. الأوفلاين الحقيقي يأتي من
   IndexedDB (hydrateFromCache في resources/js/offline/index.js). */
const OFFLINE_PAGE = '/offline';

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
    if (url.pathname === '/offline') return;
    if (url.pathname === '/firebase-messaging-sw.js') return; // FCM worker بمساره الخاص

    // Navigations (HTML):
    //  v6 أمني — network-only دائماً، بلا قراءة أو كتابة أي HTML في الكاش.
    //  السبب: طلب تنقّل GET قد يحمل جلسة مصادَقة. Cache API لا يتضمّن الكوكيز،
    //  فمفتاح المفتاح = URL فقط ⇒ أي HTML مخزَّن خاص بمستخدم يمكن أن يُقدَّم
    //  لمستخدم آخر. لذلك: لا كاش للتنقّل إطلاقاً. عند فشل الشبكة نسقط على
    //  /offline (قشرة عامة) — والبيانات الحقيقية تُرسم من IndexedDB.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() =>
                caches.match(OFFLINE_PAGE).then((resp) =>
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
