/**
 * اختبار منطق قرار الحفظ (R1) — sync.js + intercept.js بدون متصفح.
 *
 * يُحمَّل الملفان داخل vm sandbox مع بدائل لـdb/queue/window/navigator/fetch،
 * ثم يُختبر:
 *   isServerReachable()  — عتبة صلاحية قراءة النبضة
 *   probeOnce()          — الفحص الفردي ومهلته
 *   shouldQueue()        — القرار النهائي: queue أم submit، وكم فحصاً يستهلك
 *
 * الغرض الأساسي (R1): إثبات أن العتبة 75000 متوافقة مع فترة النبضة 60000،
 * فلا يدخل قرار الحفظ في حالة «مجهول» أثناء الدورة الطبيعية (وهو المسار الذي
 * يستهلك probeOnce مرتين ويؤخّر الحفظ حتى ~8 ثوانٍ).
 *
 * التشغيل:  node tests/js/sync-reachability.test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const HEARTBEAT_INTERVAL_MS = 60000;   // فترة النبضة في sync.js
const HEARTBEAT_TIMEOUT_MS = 5000;     // مهلة طلب /healthz في sync.js
const THRESHOLD_MS = 75000;            // العتبة بعد الإصلاح

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

const DB_STUB = `
const db = {
    queueAll: async () => [],
    metaGet: async () => null,
    metaSet: async () => {},
    putAll: async () => {},
    delete: async () => {},
    get: async () => null,
    put: async () => {},
};`;

const QUEUE_STUB = `
const queueAddOp = async (opType, payload) => ({
    uuid: 'test-uuid', op_type: opType, payload,
    client_updated_at: new Date().toISOString(),
});`;

/** يُحمّل وحدة ESM كمصدر نصّي: يزيل imports (تُستبدل بـstubs لاحقاً) ويجرّد export. */
function loadModule(relPath) {
    let src = readFileSync(join(ROOT, relPath), 'utf8');
    src = src.replace(/^import .*$/gm, '');
    src = src.replace(/\bexport const (\w+)/g, 'const $1');
    return src;
}

/** يلفّ مصدر وحدة داخل IIFE مع stubs — يمنع تعارض `const` بين الوحدتين بنفس الـcontext. */
function wrapModule(source, stubs, expose) {
    return `(function(){\n${stubs}\n${source}\n${expose}\n})();`;
}

function makeSandbox(fetchImpl, { online = true } = {}) {
    const calls = { fetch: [] };

    const sandbox = {
        window: {},
        navigator: { onLine: online },
        fetch: (url, opts) => {
            calls.fetch.push(url);
            return fetchImpl(url, opts);
        },
        sessionStorage: {
            _v: {},
            getItem(k) { return this._v[k] ?? null; },
            setItem(k, v) { this._v[k] = String(v); },
            removeItem(k) { delete this._v[k]; },
        },
        CustomEvent: class CustomEvent { constructor(t, o) { this.type = t; this.detail = (o || {}).detail; } },
        document: { hidden: false, addEventListener() {}, querySelectorAll: () => [] },
        setTimeout, clearTimeout, console, Promise, Date, Object, JSON,
    };
    sandbox.window = sandbox;
    sandbox.window.dispatchEvent = () => {};
    sandbox.globalThis = sandbox;

    vm.createContext(sandbox);
    return { sandbox, calls };
}

/** يُنشئ sync + intercept داخل نفس الـsandbox (كما في التطبيق الحقيقي). */
function boot({ online = true, fetchImpl } = {}) {
    const impl = fetchImpl || (async () => ({ ok: true, json: async () => ({}) }));
    const { sandbox, calls } = makeSandbox(impl, { online });

    vm.runInContext(
        wrapModule(
            loadModule('resources/js/offline/sync.js'),
            DB_STUB,
            'globalThis.__sync = sync;'
        ),
        sandbox
    );
    vm.runInContext(
        wrapModule(
            loadModule('resources/js/offline/intercept.js'),
            DB_STUB + QUEUE_STUB,
            'globalThis.__intercept = intercept;'
            + '\nglobalThis.__probeOnce = probeOnce;'
            + '\nglobalThis.__shouldQueue = shouldQueue;'
        ),
        sandbox
    );

    sandbox.window.DawayOffline = { sync: sandbox.__sync };

    return { sandbox, calls, sync: sandbox.__sync, shouldQueue: sandbox.__shouldQueue, probeOnce: sandbox.__probeOnce };
}

/* ---------------- R1: عتبة الصلاحية ---------------- */

function testThresholdMatchesHeartbeatCycle() {
    console.log('\n1) العتبة متوافقة مع دورة النبضة (لا فجوة «مجهول»)');

    const { sync } = boot();
    sync.serverUp = true;

    // أقصى تأخير طبيعي: النبضة نفسها تستغرق حتى مهلة الطلب قبل أن تُحدِّث القراءة
    const worstCase = HEARTBEAT_INTERVAL_MS + HEARTBEAT_TIMEOUT_MS;
    sync.lastCheckAt = Date.now() - worstCase;
    ok(`قراءة عمرها ${worstCase}ms (فترة + مهلة) ما زالت صالحة`,
        sync.isServerReachable() === true,
        `got: ${sync.isServerReachable()}`);

    // بعد العتبة تنتهي الصلاحية (لا تبقى للأبد)
    sync.lastCheckAt = Date.now() - (THRESHOLD_MS + 1);
    ok('بعد انتهاء العتبة تصبح غير معروفة (null)',
        sync.isServerReachable() === null,
        `got: ${sync.isServerReachable()}`);
}

function testOldThresholdWouldHaveFailed() {
    console.log('\n2) إثبات أن العتبة القديمة (20000) كانت تكسر الدورة');

    const { sync } = boot();
    sync.serverUp = true;

    // عمر القراءة = دورة نبضة كاملة (الحالة الطبيعية في منتصف الدورة)
    sync.lastCheckAt = Date.now() - HEARTBEAT_INTERVAL_MS;

    const nowFresh = sync.isServerReachable() === true;
    ok('بالعتبة الجديدة (75000): القراءة صالحة عند عمر دورة كاملة', nowFresh);

    // محاكاة العتبة القديمة: نفس العمر يتجاوز 20000 بكثير
    ok('بالعتبة القديمة (20000) كان العمر نفسه يُعدّ «مجهولاً»',
        HEARTBEAT_INTERVAL_MS > 20000,
        `${HEARTBEAT_INTERVAL_MS} > 20000`);
}

/* ---------------- shouldQueue: القرار وعدد الفحوصات ---------------- */

async function testOfflineBrowserQueuesWithoutProbe() {
    console.log('\n3) navigator.onLine=false -> queue فوراً بلا أي فحص شبكة');

    const { shouldQueue, calls } = boot({ online: false });
    const decision = await shouldQueue();

    ok('القرار = queue', decision === true, `got: ${decision}`);
    ok('صفر طلبات /healthz', calls.fetch.length === 0, `got: ${calls.fetch.length}`);
}

async function testFreshReachableSubmitsWithoutProbe() {
    console.log('\n4) قراءة طازجة + serverUp=true -> submit بلا أي فحص شبكة');

    const { shouldQueue, sync, calls } = boot();
    sync.serverUp = true;
    sync.lastCheckAt = Date.now();

    const decision = await shouldQueue();

    ok('القرار = submit (لا queue)', decision === false, `got: ${decision}`);
    ok('صفر طلبات /healthz', calls.fetch.length === 0, `got: ${calls.fetch.length}`);
}

async function testFreshUnreachableProbesExactlyOnce() {
    console.log('\n5) قراءة طازجة + serverUp=false -> فحص واحد فقط');

    const { shouldQueue, sync, calls } = boot({
        fetchImpl: async () => { throw new Error('down'); },
    });
    sync.serverUp = false;
    sync.lastCheckAt = Date.now();

    const decision = await shouldQueue();

    ok('القرار = queue', decision === true, `got: ${decision}`);
    ok('فحص واحد بالضبط (لا double probe)', calls.fetch.length === 1, `got: ${calls.fetch.length}`);
}

async function testStaleUnknownProbesTwiceWhenFirstFails() {
    console.log('\n6) قراءة منتهية الصلاحية + فشل أول -> فحصان (السلوك المقصود)');

    const { shouldQueue, sync, calls } = boot({
        fetchImpl: async () => { throw new Error('down'); },
    });
    sync.serverUp = null;
    sync.lastCheckAt = Date.now() - (THRESHOLD_MS + 1000);   // منتهية الصلاحية

    const decision = await shouldQueue();

    ok('القرار = queue', decision === true, `got: ${decision}`);
    ok('فحصان بالضبط', calls.fetch.length === 2, `got: ${calls.fetch.length}`);
}

async function testStaleUnknownProbesOnceWhenFirstSucceeds() {
    console.log('\n7) قراءة منتهية الصلاحية + نجاح أول -> فحص واحد فقط');

    const { shouldQueue, sync, calls } = boot({
        fetchImpl: async () => ({ ok: true }),
    });
    sync.serverUp = null;
    sync.lastCheckAt = Date.now() - (THRESHOLD_MS + 1000);

    const decision = await shouldQueue();

    ok('القرار = submit', decision === false, `got: ${decision}`);
    ok('فحص واحد بالضبط', calls.fetch.length === 1, `got: ${calls.fetch.length}`);
}

async function testNoDoubleProbeWithinNormalCycle() {
    console.log('\n8) الأهم: لا double probe أثناء الدورة الطبيعية (جوهر إصلاح R1)');

    const { shouldQueue, sync, calls } = boot({
        fetchImpl: async () => { throw new Error('down'); },
    });
    sync.serverUp = true;
    // أسوأ عمر ممكن داخل دورة طبيعية: دورة كاملة + مهلة النبضة
    sync.lastCheckAt = Date.now() - (HEARTBEAT_INTERVAL_MS + HEARTBEAT_TIMEOUT_MS);

    const decision = await shouldQueue();

    ok('لا يدخل مسار «مجهول» (صفر فحوصات)', calls.fetch.length === 0, `got: ${calls.fetch.length}`);
    ok('القرار يعتمد على القراءة المحفوظة (submit)', decision === false, `got: ${decision}`);
}

async function testProbeOnceIsBounded() {
    console.log('\n9) probeOnce محدود بمهلة (fetch معلّق لا يعلّق الحفظ)');

    const { probeOnce } = boot({
        fetchImpl: () => new Promise(() => {}),   // لا يستجيب أبداً
    });

    const started = Date.now();
    const result = await probeOnce();
    const elapsed = Date.now() - started;

    ok('يُرجع false عند التعليق', result === false, `got: ${result}`);
    ok('يحسم خلال ~4000ms', elapsed >= 3900 && elapsed < 5000, `${elapsed}ms`);
}

/* ---------------- run ---------------- */

console.log('اختبار منطق قرار الحفظ (sync.js + intercept.js)');

testThresholdMatchesHeartbeatCycle();
testOldThresholdWouldHaveFailed();
await testOfflineBrowserQueuesWithoutProbe();
await testFreshReachableSubmitsWithoutProbe();
await testFreshUnreachableProbesExactlyOnce();
await testStaleUnknownProbesTwiceWhenFirstFails();
await testStaleUnknownProbesOnceWhenFirstSucceeds();
await testNoDoubleProbeWithinNormalCycle();
await testProbeOnceIsBounded();

console.log(`\nالنتيجة: ${passed} نجح · ${failures.length} فشل`);
if (failures.length) {
    console.log('الفاشلة:');
    failures.forEach((f) => console.log('  - ' + f));
    process.exit(1);
}
console.log('كل اختبارات منطق قرار الحفظ نجحت.');
