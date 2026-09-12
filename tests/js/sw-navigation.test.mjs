/**
 * اختبار منطق تنقّل الـService Worker — بدون متصفح.
 *
 * يُحمَّل public/sw.js داخل vm sandbox مع بدائل لـself/caches/fetch،
 * ثم يُستدعى معالج حدث fetch مباشرةً ويُتحقق من سلوك التنقّل.
 *
 * السبب: التعديل الأخطر في إصلاح الأداء كان استبدال AbortController(3000ms)
 * بسباق مهلة (1200ms مع كاش / 8000ms بدونه) مع عدم إلغاء الطلب أبداً.
 * هذا الاختبار يُثبّت أن الكاش يُحدَّث في الخلفية حتى عندما يُخدَم الكاش أولاً —
 * وهو بالضبط ما كان مكسوراً سابقاً (الطلب المُلغى لا يُحدِّث الكاش أبداً).
 *
 * التشغيل:  node tests/js/sw-navigation.test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SW_SOURCE = readFileSync(join(ROOT, 'public', 'sw.js'), 'utf8');
const ORIGIN = 'https://daway.test';

/* ---------------- harness ---------------- */

let passed = 0;
const failures = [];

function ok(name, cond, extra = '') {
    if (cond) {
        passed++;
        console.log(`  \u2713 ${name}`);
    } else {
        failures.push(name);
        console.log(`  \u2717 ${name}${extra ? '  -> ' + extra : ''}`);
    }
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** جسم الاستجابة المخزّنة — يُقرأ من نسخة لأن Response يُقرأ مرة واحدة فقط. */
async function cachedBody(store, url) {
    const r = store.get(url);
    return r ? await r.clone().text() : null;
}

/* ---------------- sandbox ---------------- */

function makeResponse(body, { status = 200, type = 'basic' } = {}) {
    const res = new Response(body, { status });
    Object.defineProperty(res, 'type', { value: type });
    return res;
}

function keyOf(req) {
    const raw = typeof req === 'string' ? req : req.url;
    // Cache API الحقيقي يحوّل المسار النسبي ('/offline') إلى مطلق مقابل الأصل
    return raw.startsWith('/') ? ORIGIN + raw : raw;
}

/**
 * @param {(req:any, opts:any) => Promise<Response>} fetchImpl
 * @param {Record<string, string>} seed  محتوى الكاش المبدئي (url -> body)
 */
function loadServiceWorker(fetchImpl, seed = {}, opts = {}) {
    const handlers = {};
    const store = new Map();

    for (const [url, body] of Object.entries(seed)) {
        store.set(url, makeResponse(body));
    }

    const cache = {
        // مثل Cache API الحقيقي: كل match يُعيد Response جديداً (لا نفس الكائن)
        match: async (req) => {
            if (opts.matchThrows) throw new Error('cache unavailable');
            const stored = store.get(keyOf(req));
            return stored ? stored.clone() : undefined;
        },
        put: async (req, res) => { store.set(keyOf(req), res); },
        add: async () => {},
    };

    const cachesStub = {
        open: async () => cache,
        match: async (req) => cache.match(req),
        keys: async () => ['daway-v4'],
        delete: async () => true,
    };

    const sandbox = {
        self: {
            addEventListener: (type, fn) => { handlers[type] = fn; },
            location: { origin: ORIGIN },
            skipWaiting: () => {},
            clients: { claim: async () => {} },
        },
        caches: cachesStub,
        fetch: fetchImpl,
        Response,
        Request,
        URL,
        AbortController,
        setTimeout,
        clearTimeout,
        console,
    };
    sandbox.self.self = sandbox.self;

    vm.createContext(sandbox);
    vm.runInContext(SW_SOURCE, sandbox);

    return { handlers, store, cache };
}

function navigate(handlers, url, { mode = 'navigate' } = {}) {
    const request = new Request(url, { method: 'GET' });
    Object.defineProperty(request, 'mode', { value: mode });

    let captured = null;
    handlers.fetch({ request, respondWith: (p) => { captured = p; } });
    return captured;
}

/* ---------------- tests ---------------- */

const URL_INVENTORY = `${ORIGIN}/pharmacy/inventory`;

async function testFastNetworkWithCache() {
    console.log('\n1) شبكة سريعة + يوجد كاش -> يجب أن تُخدَم النسخة الطازجة ويُحدَّث الكاش');

    const { handlers, store } = loadServiceWorker(
        async () => makeResponse('FRESH'),
        { [URL_INVENTORY]: 'STALE' }
    );

    const res = await navigate(handlers, URL_INVENTORY);
    const body = await res.text();

    ok('يُعيد المحتوى الطازج', body === 'FRESH', `got: ${body}`);
    await sleep(10);
    ok('الكاش صار يحتوي الطازج', (await cachedBody(store, URL_INVENTORY)) === 'FRESH');
}

async function testSlowNetworkWithCacheServesCacheButStillUpdates() {
    console.log('\n2) شبكة بطيئة (>1200ms) + يوجد كاش -> كاش فوراً + تحديث خلفي (الإصلاح الأساسي)');

    let resolveSlow;
    const slow = new Promise((r) => { resolveSlow = r; });

    const { handlers, store } = loadServiceWorker(
        async () => {
            await slow;
            return makeResponse('FRESH-SLOW');
        },
        { [URL_INVENTORY]: 'STALE' }
    );

    const started = Date.now();
    const res = await navigate(handlers, URL_INVENTORY);
    const elapsed = Date.now() - started;
    const body = await res.text();

    ok('يُخدَم الكاش بسرعة (< 1600ms)', elapsed < 1600, `${elapsed}ms`);
    ok('المحتوى هو الكاش القديم', body === 'STALE', `got: ${body}`);
    ok('الكاش لم يُحدَّث بعد (الطلب ما زال جارياً)',
        (await cachedBody(store, URL_INVENTORY)) === 'STALE');

    // الآن نكمل الطلب البطيء — الأهم: هل يُحدَّث الكاش؟
    resolveSlow();
    await sleep(80);

    ok('الكاش تحدّث في الخلفية بعد اكتمال الطلب',
        (await cachedBody(store, URL_INVENTORY)) === 'FRESH-SLOW',
        'هذا هو الإصلاح: كان AbortController يمنع أي تحديث للكاش');
}

async function testSlowNetworkNoCacheWaitsForNetwork() {
    console.log('\n3) شبكة بطيئة + لا يوجد كاش -> ينتظر الشبكة (ضمن مهلة 8s) ولا يعرض /offline');

    const { handlers } = loadServiceWorker(
        async () => {
            await sleep(1500);
            return makeResponse('FRESH-AFTER-1500');
        },
        {}
    );

    const res = await navigate(handlers, URL_INVENTORY);
    const body = await res.text();

    ok('ينتظر الشبكة ويُعيد المحتوى الطازج', body === 'FRESH-AFTER-1500', `got: ${body}`);
}

async function testOfflineWithCache() {
    console.log('\n4) الشبكة فاشلة + يوجد كاش -> كاش');

    const { handlers } = loadServiceWorker(
        async () => { throw new Error('offline'); },
        { [URL_INVENTORY]: 'CACHED-OFFLINE' }
    );

    const res = await navigate(handlers, URL_INVENTORY);
    ok('يُعيد الكاش', (await res.text()) === 'CACHED-OFFLINE');
}

async function testOfflineNoCacheFallsBackToOfflinePage() {
    console.log('\n5) الشبكة فاشلة + لا كاش -> صفحة /offline');

    const { handlers } = loadServiceWorker(
        async (req) => {
            const url = typeof req === 'string' ? req : req.url;
            if (url.endsWith('/offline')) return makeResponse('OFFLINE-PAGE');
            throw new Error('offline');
        },
        { [`${ORIGIN}/offline`]: 'OFFLINE-PAGE' }
    );

    const res = await navigate(handlers, URL_INVENTORY);
    ok('يُعيد صفحة /offline', (await res.text()) === 'OFFLINE-PAGE');
}

async function testPaginatedNavigationIsNetworkOnly() {
    console.log('\n6) تنقّل ?page= -> network-only بلا كاش (يُحفظ سلوك عدم تقديم صفحات قديمة)');

    const seen = [];
    const { handlers, store } = loadServiceWorker(
        async (req, opts) => {
            seen.push(opts && opts.cache);
            return makeResponse('PAGE2');
        },
        { [`${URL_INVENTORY}?page=2`]: 'STALE-PAGE2' }
    );

    const res = await navigate(handlers, `${URL_INVENTORY}?page=2`);
    ok('يُعيد الشبكة لا الكاش', (await res.text()) === 'PAGE2');
    ok("fetch نُفِّذ بـ cache: 'no-store'", seen[0] === 'no-store', `got: ${seen[0]}`);
    ok('الكاش لم يُستبدل (بقيت النسخة القديمة)',
        (await cachedBody(store, `${URL_INVENTORY}?page=2`)) === 'STALE-PAGE2');
}

async function testNonOfflineScopeNavigationUntouched() {
    console.log('\n7) صفحة أدمن (خارج نطاق offline) -> لا يمسّها الـSW (fetch عادي)');

    let touched = false;
    const { handlers } = loadServiceWorker(async () => { touched = true; return makeResponse('ADMIN'); });

    const captured = navigate(handlers, `${ORIGIN}/users`);
    ok('الـSW لا يعترض التنقّل (respondWith غير مُستدعى)', captured !== null);
    await sleep(20);
    ok('الطلب مرّ إلى الشبكة', touched);
}

async function testApiAndPostAreIgnored() {
    console.log('\n8) /api/* و POST لا يُمَسّان');

    const { handlers } = loadServiceWorker(async () => makeResponse('X'));

    const apiReq = new Request(`${ORIGIN}/api/notifications`, { method: 'GET' });
    Object.defineProperty(apiReq, 'mode', { value: 'cors' });
    let captured = null;
    handlers.fetch({ request: apiReq, respondWith: (p) => { captured = p; } });
    ok('طلب /api/ غير معترَض', captured === null);

    const postReq = new Request(`${ORIGIN}/pharmacy/inventory`, { method: 'POST' });
    Object.defineProperty(postReq, 'mode', { value: 'navigate' });
    captured = null;
    handlers.fetch({ request: postReq, respondWith: (p) => { captured = p; } });
    ok('طلب POST غير معترَض', captured === null);
}

async function testCacheLookupErrorDoesNotBreakNavigation() {
    console.log('\n9) خطأ في Cache API + شبكة سليمة -> التنقّل يكمل (لا رفض)');

    const { handlers } = loadServiceWorker(
        async () => makeResponse('NETWORK-OK'),
        {},
        { matchThrows: true }
    );

    const res = await navigate(handlers, URL_INVENTORY);
    ok('يُعيد استجابة ولا يرفض', res instanceof Response, `got: ${res}`);
    ok('المحتوى من الشبكة', res && (await res.text()) === 'NETWORK-OK');
}

async function testCacheLookupErrorAndNetworkFailureStillResponds() {
    console.log('\n10) خطأ Cache + فشل الشبكة -> استجابة 503 لا رفض');

    const { handlers } = loadServiceWorker(
        async () => { throw new Error('offline'); },
        {},
        { matchThrows: true }
    );

    const res = await navigate(handlers, URL_INVENTORY);
    ok('يُعيد استجابة', res instanceof Response, `got: ${res}`);
    ok('الحالة 503', res && res.status === 503, `status=${res && res.status}`);
}

/* ---------------- run ---------------- */

console.log('اختبار منطق تنقّل الـService Worker (public/sw.js)');

await testFastNetworkWithCache();
await testSlowNetworkWithCacheServesCacheButStillUpdates();
await testSlowNetworkNoCacheWaitsForNetwork();
await testOfflineWithCache();
await testOfflineNoCacheFallsBackToOfflinePage();
await testPaginatedNavigationIsNetworkOnly();
await testNonOfflineScopeNavigationUntouched();
await testApiAndPostAreIgnored();
await testCacheLookupErrorDoesNotBreakNavigation();
await testCacheLookupErrorAndNetworkFailureStillResponds();

console.log(`\nالنتيجة: ${passed} نجح · ${failures.length} فشل`);
if (failures.length) {
    console.log('الفاشلة:');
    failures.forEach((f) => console.log('  - ' + f));
    process.exit(1);
}
console.log('كل اختبارات منطق الـService Worker نجحت.');
