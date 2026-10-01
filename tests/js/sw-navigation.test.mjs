/**
 * اختبار منطق تنقّل الـService Worker — بدون متصفح.
 *
 * يُحمَّل public/sw.js داخل vm sandbox مع بدائل لـself/caches/fetch،
 * ثم يُستدعى معالج حدث fetch مباشرةً ويُتحقق من سلوك التنقّل.
 *
 * v6 (أمني): الغرض الآن إثبات أن أي HTML مصادَق لا يُخزَّن ولا يُعاد تقديمه
 * إطلاقاً — لا قراءة من الكاش ولا كتابة إليه في مسار التنقّل. هذا يمنع تسرّب
 * صفحة مستخدم A إلى مستخدم B على نفس المتصفح. الأوفلاين الحقيقي مبنيّ على
 * IndexedDB (resources/js/offline/*) لا على HTML مخزَّن.
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

/* الإصدار الحالي يُقرأ من المصدر بدل تثبيته — رفع VERSION (مثل v6→v7)
   كان يكسر هذه الاختبارات بلا سبب. الهدف الحقيقي: إثبات أن activate ينظّف
   الإصدارات الأقدم ويُبقي الإصدار الحالي. */
const SW_VERSION = (SW_SOURCE.match(/const VERSION = '([^']+)'/) || [])[1];
if (!SW_VERSION) throw new Error('لم يُعثر على VERSION في public/sw.js');

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
    const deleted = [];

    for (const [url, body] of Object.entries(seed)) {
        store.set(keyOf(url), makeResponse(body));
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
        match: async (req, o) => cache.match(req, o),
        // كاشات: نسختان من التطبيق + كاش تطبيق آخر (للتأكد أننا لا نمسّه)
        keys: async () => ['daway-v5', SW_VERSION, 'unrelated-app-cache'],
        delete: async (name) => { deleted.push(name); return true; },
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

    return { handlers, store, cache, deleted };
}

function navigate(handlers, url, { mode = 'navigate' } = {}) {
    const request = new Request(url, { method: 'GET' });
    Object.defineProperty(request, 'mode', { value: mode });

    let captured = null;
    handlers.fetch({ request, respondWith: (p) => { captured = p; } });
    return captured;
}

function postMessage(handlers, data) {
    let captured = null;
    handlers.message({ data, waitUntil: (p) => { captured = p; } });
    return captured;
}

/* ---------------- tests ---------------- */

const URL_INVENTORY = `${ORIGIN}/pharmacy/inventory`;
const URL_MEDICINES = `${ORIGIN}/pharmacy/medicines`;
const URL_PROFILE = `${ORIGIN}/profile`;

async function testPrivateNavigationNeverReadsCache() {
    console.log('\n1) تنقّل خاص (مصادَق) + HTML مستخدم سابق مخزَّن -> يجب ألا يُقدَّم الكاش');

    const { handlers } = loadServiceWorker(
        async () => makeResponse('FRESH-A'),
        { [URL_INVENTORY]: 'STALE-USER-A' }
    );

    const res = await navigate(handlers, URL_INVENTORY);
    const body = await res.text();

    ok('يُخدَم محتوى الشبكة لا الكاش', body === 'FRESH-A', `got: ${body}`);
    ok('لم يُقدَّم HTML المستخدم السابق', body !== 'STALE-USER-A');
}

async function testPrivateNavigationNeverWritesCache() {
    console.log('\n2) تنقّل خاص -> يجب ألا يُكتب HTML في الكاش');

    const { handlers, store } = loadServiceWorker(async () => makeResponse('FRESH-A'));

    await navigate(handlers, URL_INVENTORY);
    await sleep(20);

    ok('كاش /pharmacy/inventory بقي فارغاً (لا كتابة)',
        (await cachedBody(store, URL_INVENTORY)) === null,
        `got: ${await cachedBody(store, URL_INVENTORY)}`);
}

async function testUserBDoesNotReceiveUserAWhenNetworkSlow() {
    console.log('\n3) 🔴 الأمني: صفحة A مخزَّنة → مستخدم B يدخل وشبكته بطيئة → لا يحصل على صفحة A');

    // صفحات A مخزَّنة (كما كان يحدث سابقاً عبر precachePharmacyPages).
    const seed = {
        [URL_INVENTORY]: 'USER-A-PRIVATE-HTML',
        [URL_MEDICINES]: 'USER-A-PRIVATE-HTML',
        [URL_PROFILE]: 'USER-A-PRIVATE-HTML',
    };

    // شبكة B بطيئة (2000ms) — كانت تتجاوز مهلة السباق 1200ms فتُخدم صفحة A.
    const { handlers } = loadServiceWorker(
        async () => { await sleep(2000); return makeResponse('USER-B-FRESH'); },
        seed
    );

    const started = Date.now();
    const res = await navigate(handlers, URL_INVENTORY);
    const body = await res.text();
    const elapsed = Date.now() - started;

    ok('B لا يستلم HTML الخاص بـ A', body !== 'USER-A-PRIVATE-HTML', `got: ${body}`);
    ok('B ينتظر الشبكة كاملة (لا اختصار للكاش)', elapsed >= 1900, `${elapsed}ms`);
    ok('المحتوى النهائي هو محتوى B', body === 'USER-B-FRESH', `got: ${body}`);
}

async function testOfflineFallsBackToOfflineShellNotPrivateHtml() {
    console.log('\n4) الشبكة فاشلة + يوجد HTML خاص مخزَّن -> /offline (لا HTML خاص)');

    const { handlers } = loadServiceWorker(
        async () => { throw new Error('offline'); },
        {
            [URL_INVENTORY]: 'USER-A-PRIVATE-HTML',
            [`${ORIGIN}/offline`]: 'OFFLINE-SHELL',
        }
    );

    const res = await navigate(handlers, URL_INVENTORY);
    const body = await res.text();

    ok('يُعيد قشرة /offline', body === 'OFFLINE-SHELL', `got: ${body}`);
    ok('لا يُعيد HTML المستخدم المخزَّن', body !== 'USER-A-PRIVATE-HTML');
}

async function testOfflineNoCacheStillResponds() {
    console.log('\n5) الشبكة فاشلة + لا يوجد قشرة /offline -> استجابة 503 لا رفض');

    const { handlers } = loadServiceWorker(async () => { throw new Error('offline'); }, {});

    const res = await navigate(handlers, URL_INVENTORY);
    ok('يُعيد استجابة', res instanceof Response, `got: ${res}`);
    ok('الحالة 503', res && res.status === 503, `status=${res && res.status}`);
}

async function testPaginatedNavigationIsNetworkOnly() {
    console.log('\n6) تنقّل ?page= -> network-only بلا كاش');

    const { handlers, store } = loadServiceWorker(
        async () => makeResponse('PAGE2'),
        { [`${URL_INVENTORY}?page=2`]: 'STALE-USER-A' }
    );

    const res = await navigate(handlers, `${URL_INVENTORY}?page=2`);
    ok('يُعيد الشبكة لا الكاش', (await res.text()) === 'PAGE2');
    ok('الكاش لم يُستبدل (بقيت النسخة القديمة)',
        (await cachedBody(store, `${URL_INVENTORY}?page=2`)) === 'STALE-USER-A');
}

async function testAdminNavigationIsNetworkOnly() {
    console.log('\n7) صفحة أدمن -> network-only (لا إعادة تقديم قديمة)');

    const { handlers, store } = loadServiceWorker(
        async () => makeResponse('ADMIN-FRESH'),
        { [`${ORIGIN}/users`]: 'STALE-ADMIN' }
    );

    const res = await navigate(handlers, `${ORIGIN}/users`);
    ok('يُعيد الشبكة', (await res.text()) === 'ADMIN-FRESH');
    ok('لم يُقدَّم HTML أدمن قديم', (await cachedBody(store, `${ORIGIN}/users`)) === 'STALE-ADMIN');
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

async function testStaticAssetsStillCached() {
    console.log('\n9) الأصول الثابتة -> cache-first (الكاش الآمن يبقى يعمل)');

    const cssUrl = `${ORIGIN}/build/assets/app-abc.css`;
    const { handlers } = loadServiceWorker(
        async () => makeResponse('body { color: red }'),
        { [cssUrl]: 'CACHED-CSS' }
    );

    const req = new Request(cssUrl, { method: 'GET' });
    Object.defineProperty(req, 'mode', { value: 'no-cors' });
    let captured = null;
    handlers.fetch({ request: req, respondWith: (p) => { captured = p; } });

    ok('الأصل الثابت مُعترَض (يُخدَم من الكاش)', captured !== null);
    const body = await (await captured).text();
    ok('يُخدَم من الكاش', body === 'CACHED-CSS', `got: ${body}`);
}

async function testPurgeDeletesOnlyDawayCaches() {
    console.log('\n10) DAWAY_PURGE -> يحذف كاشات Daway فقط ولا يمسّ كاش تطبيق آخر');

    const { handlers, deleted } = loadServiceWorker(async () => makeResponse('X'));

    await postMessage(handlers, 'DAWAY_PURGE');
    await sleep(20);

    ok('حُذف كاش daway-v5', deleted.includes('daway-v5'), JSON.stringify(deleted));
    ok(`حُذف كاش ${SW_VERSION}`, deleted.includes(SW_VERSION), JSON.stringify(deleted));
    ok('لم يُحذف كاش تطبيق آخر', !deleted.includes('unrelated-app-cache'), JSON.stringify(deleted));
}

async function testVersionWasBumpedAndOldCacheSwept() {
    console.log('\n11) رفع الإصدار -> activate ينظّف الإصدارات القديمة فقط');

    ok(`الإصدار مُعرَّف بصيغة daway-v<N>`, /const VERSION = 'daway-v\d+'/.test(SW_SOURCE), `VERSION الحالي: ${SW_VERSION}`);

    const { handlers, deleted } = loadServiceWorker(async () => makeResponse('X'));
    let captured = null;
    handlers.activate({ waitUntil: (p) => { captured = p; } });
    await captured;
    await sleep(20);

    ok('حُذف daway-v5 عند activate', deleted.includes('daway-v5'), JSON.stringify(deleted));
    ok(`لم يُحذف الكاش الحالي ${SW_VERSION}`, !deleted.includes(SW_VERSION), JSON.stringify(deleted));
    ok('لم يُحذف كاش تطبيق آخر', !deleted.includes('unrelated-app-cache'), JSON.stringify(deleted));
}

async function testNoPrivatePagePrecachingRemains() {
    console.log('\n12) لا يوجد بعد الآن تخزين مسبق لصفحات مصادَقة (PHARMACY_PAGES / DAWAY_PREFETCH)');

    ok('لا توجد قائمة PHARMACY_PAGES', !/PHARMACY_PAGES/.test(SW_SOURCE));
    ok('لا توجد دالة precachePharmacyPages', !/precachePharmacyPages/.test(SW_SOURCE));
    ok('لا توجد معالجة DAWAY_PREFETCH', !/DAWAY_PREFETCH/.test(SW_SOURCE));
    ok('لا توجد OFFLINE_NAV_PREFIXES', !/OFFLINE_NAV_PREFIXES/.test(SW_SOURCE));
}

/* ---------------- run ---------------- */

console.log('اختبار منطق تنقّل الـService Worker (public/sw.js) — v6 أمني');

await testPrivateNavigationNeverReadsCache();
await testPrivateNavigationNeverWritesCache();
await testUserBDoesNotReceiveUserAWhenNetworkSlow();
await testOfflineFallsBackToOfflineShellNotPrivateHtml();
await testOfflineNoCacheStillResponds();
await testPaginatedNavigationIsNetworkOnly();
await testAdminNavigationIsNetworkOnly();
await testApiAndPostAreIgnored();
await testStaticAssetsStillCached();
await testPurgeDeletesOnlyDawayCaches();
await testVersionWasBumpedAndOldCacheSwept();
await testNoPrivatePagePrecachingRemains();

console.log(`\nالنتيجة: ${passed} نجح · ${failures.length} فشل`);
if (failures.length) {
    console.log('الفاشلة:');
    failures.forEach((f) => console.log('  - ' + f));
    process.exit(1);
}
console.log('كل اختبارات منطق الـService Worker نجحت.');
