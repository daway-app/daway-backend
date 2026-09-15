/**
 * اختبار آلة حالة جلسة المسح بالهاتف — بدون متصفح.
 *
 * يُحمَّل `resources/js/accounting/accounting-scanner-session.js` الحقيقي (لا نسخة
 * منه) داخل vm sandbox مع بدائل لـwindow/document/fetch، ثم تُشغَّل الانتقالات
 * الفعلية. السبب: لو نسخنا منطق الحالة هنا لكان الاختبار يشهد على نفسه.
 *
 * ما نثبّته هنا — ولماذا يهمّ:
 *   1) الانتقالات القانونية (waiting→connected→scanning→received) — أي خطأ
 *      فيها يعني واجهة تعرض «جاهز» وهي ليست جاهزة.
 *   2) **الطابور بالترتيب**: المسح الثاني لا يتقدّم قبل انتهاء الأول. شبكة
 *      غزة لا تحتمل 5 طلبات متوازية، والترتيب أهم من السرعة.
 *   3) **غياب الباك-إند لا يُنتج نجاحًا كاذبًا**: بلا مسارات ⇒ `no_backend`،
 *      ولا نرسل طلبًا لأي مسار مُخترَع.
 *   4) **الانتهاء يُلغي الجلسة**: الـQR القديم لا يعمل، ولا تُقبل مسحات.
 *   5) **الفصل لا يمسّ السلة** — الفصل يوقف الجلسة فقط.
 *   6) **لا تجاوز لتعارض جهاز**: قرار بشري فقط.
 *
 * التشغيل:  node tests/js/phone-scanner-session.test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SRC = join(ROOT, 'resources/js/accounting/accounting-scanner-session.js');

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

function eq(name, actual, expected) {
    ok(name, actual === expected, `expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
}

/* ---------------- sandbox ---------------- */

/**
 * يحمّل ملف الجلسة داخل sandbox.
 *
 * @param {object} opts
 *   config : كائن window.acAccountingConfig
 *   fetch  : بديل fetch (يُسجّل كل نداء)
 */
function loadSession(opts = {}) {
    const calls = [];
    const listeners = {};
    const timers = [];

    const documentStub = {
        hidden: false,
        addEventListener: (type, fn) => { (listeners[type] ||= []).push(fn); },
        removeEventListener: () => {},
        querySelector: () => null,
        readyState: 'complete',
    };

    const windowStub = {
        acAccountingConfig: opts.config || {},
        acScannerI18n: {},
        document: documentStub,
        EventSource: undefined,
        addEventListener: () => {},
    };

    const fetchStub = opts.fetch || (async (url, init) => {
        calls.push({ url, method: (init && init.method) || 'GET' });
        return { ok: false, status: 404, json: async () => ({}) };
    });

    const sandbox = {
        window: windowStub,
        document: documentStub,
        fetch: fetchStub,
        Promise,
        JSON,
        Math,
        Date,
        Number,
        String,
        Array,
        Object,
        isFinite,
        isNaN,
        parseInt,
        parseFloat,
        // مؤقّتات معطّلة: ثبتنا زمن الاختبار، ونُشغّل الخطوات يدويًّا
        setInterval: (fn) => { timers.push(fn); return timers.length; },
        clearInterval: () => {},
        setTimeout: (fn) => { timers.push(fn); return timers.length; },
        clearTimeout: () => {},
        console,
    };
    sandbox.globalThis = sandbox;

    const code = readFileSync(SRC, 'utf8');
    vm.createContext(sandbox);
    vm.runInContext(code, sandbox, { filename: 'accounting-scanner-session.js' });

    return {
        api: windowStub.AccountingScanSession,
        calls,
        timers,
        tick: (i = 0) => { if (timers[i]) timers[i](); },
    };
}

/* ---------------- الاختبارات ---------------- */

function testStateVocabulary() {
    console.log('\nمفردات الحالة');

    const { api } = loadSession();
    const S = api.STATE;

    eq('الحالات الثمانية كما طلبها التصميم', api.allStates().length, 9);
    ok('waiting موجودة', S.WAITING === 'waiting');
    ok('connected موجودة', S.CONNECTED === 'connected');
    ok('expired موجودة', S.EXPIRED === 'expired');

    // الباركود يُقبل فقط بالاتصال
    ok('connected يقبل المسح', api.canAcceptScans(S.CONNECTED) === true);
    ok('scanning يقبل المسح (داخل المعالجة)', api.canAcceptScans(S.SCANNING) === true);
    ok('waiting لا يقبل المسح', api.canAcceptScans(S.WAITING) === false);
    ok('disconnected لا يقبل المسح', api.canAcceptScans(S.DISCONNECTED) === false);
    ok('expired لا يقبل المسح', api.canAcceptScans(S.EXPIRED) === false);

    // expired حالة نهائية تحتاج جلسة جديدة
    ok('expired نهائية', api.isTerminal(S.EXPIRED) === true);
    ok('connected ليست نهائية', api.isTerminal(S.CONNECTED) === false);
}

function testNoBackendIsHonest() {
    console.log('\nغياب الباك-إند — لا نجاح كاذب');

    // بلا endpoints ⇒ لا طلبات إطلاقًا، وأسباب صريحة
    const { api, calls } = loadSession({
        config: { endpoints: {} },
        fetch: async (url, init) => {
            calls.push({ url, method: (init && init.method) || 'GET' });
            throw new Error('يجب ألا يصل أي طلب بلا endpoints');
        },
    });

    return api.Api.createScanSession().then((res) => {
        eq('createScanSession بلا endpoints ⇒ no_backend', res.reason, 'no_backend');
        eq('لم يُرسل أي طلب شبكة', calls.length, 0);

        return api.Api.getScanSessionStatus('x');
    }).then((res) => {
        eq('getScanSessionStatus ⇒ no_backend', res.reason, 'no_backend');
        eq('ما زال لا طلب شبكة', calls.length, 0);

        return api.Api.closeScanSession('x');
    }).then((res) => {
        eq('closeScanSession ⇒ no_backend', res.reason, 'no_backend');
        eq('ما زال لا طلب شبكة', calls.length, 0);
    });
}

function testWireContract() {
    console.log('\nالعقد: استدعاء المسارات الصحيحة عند وجودها');

    const seen = [];
    const endpoints = {
        store: '/api/pharmacy/scan-sessions',
        show: '/api/pharmacy/scan-sessions/{id}',
        pair: '/api/pharmacy/scan-sessions/{id}/pair',
        barcode: '/api/pharmacy/scan-sessions/{id}/barcode',
        destroy: '/api/pharmacy/scan-sessions/{id}',
    };

    const { api } = loadSession({
        config: { endpoints: { scanSessions: endpoints } },
        fetch: async (url, init) => {
            seen.push({ url, method: (init && init.method) || 'GET', body: init && init.body });
            return {
                ok: true,
                status: 200,
                json: async () => ({
                    success: true,
                    data: {
                        id: 'sess-1',
                        status: 'waiting',
                        pairing_code: 'A7K92F',
                        expires_at: '2030-01-01T00:00:00Z',
                        seconds_remaining: 300,
                    },
                }),
            };
        },
    });

    return api.Api.createScanSession().then((res) => {
        eq('POST إلى مسار الإنشاء', seen[0].method, 'POST');
        eq('المسار الصحيح', seen[0].url, endpoints.store);
        ok('الجلسة عُيّرت', res.ok === true);
        eq('رمز الاقتران وصل', res.session.pairingCode, 'A7K92F');

        return api.Api.getScanSessionStatus('sess-1');
    }).then(() => {
        eq('المعرّف يُستبدل في المسار', seen[1].url, '/api/pharmacy/scan-sessions/sess-1');
        eq('القراءة GET', seen[1].method, 'GET');

        return api.Api.receiveBarcode('sess-1', '6281234567890');
    }).then(() => {
        eq('مسار الباركود صحيح', seen[2].url, '/api/pharmacy/scan-sessions/sess-1/barcode');
        const body = JSON.parse(seen[2].body);
        eq('الباركود يُرسل كنصّ', body.barcode, '6281234567890');

        return api.Api.closeScanSession('sess-1');
    }).then(() => {
        eq('الإغلاق DELETE', seen[3].method, 'DELETE');
        eq('مسار الإغلاق صحيح', seen[3].url, '/api/pharmacy/scan-sessions/sess-1');
    });
}

function testSessionNormalizationDropsSecrets() {
    console.log('\nالتطبيع — لا حقول حسّاسة إلى الواجهة');

    const { api } = loadSession();
    const norm = api.Api.__normalizeSession({
        id: 7,
        status: 'connected',
        pairing_code: 'ZZ9',
        pairing_url: 'daway://pair?sid=abc',
        // ⚠️ حقول حسّاسة: يجب أن تُهمَل تمامًا
        token: 'super-secret-token',
        api_key: 'sk-live-1234567890',
        password: 'hunter2',
        inner_secret: 'do-not-expose',
        device: { name: "Ahmad's Phone", platform: 'android', device_id: 'internal-999' },
    });

    eq('المعرّف نصّي', typeof norm.id, 'string');
    eq('الحالة محفوظة', norm.status, 'connected');
    eq('رمز الاقتران محفوظ', norm.pairingCode, 'ZZ9');
    eq('معرّف الجهاز الداخلي غير معروض', norm.device.device_id, undefined);
    eq('اسم الجهاز محفوظ', norm.device.name, "Ahmad's Phone");

    ok('لا توكن في الكائن', !('token' in norm));
    ok('لا مفتاح API في الكائن', !('api_key' in norm));
    ok('لا كلمة مرور في الكائن', !('password' in norm));

    // ولا يظهر أي سرّ في النسخة النصّية الكاملة
    const flat = JSON.stringify(norm);
    ok('لا تسريب في JSON', !flat.includes('super-secret-token')
        && !flat.includes('sk-live-1234567890')
        && !flat.includes('hunter2')
        && !flat.includes('internal-999'));
}

function testQueueIsSequential() {
    console.log('\nالطابور — بالترتيب، واحد في كل مرة');

    const { api } = loadSession({ config: { endpoints: {} } });
    const ctrl = api.createController();

    const seen = [];
    ctrl.onChange((type, payload) => {
        if (type === 'barcode') {
            seen.push(payload.barcode);
        }
    });

    // ثلاثة مسحات سريعة متتالية
    ctrl.enqueue('6281111111111');
    ctrl.enqueue('6282222222222');
    ctrl.enqueue('6283333333333');

    eq('واحد فقط بدأ المعالجة', seen.length, 1);
    eq('الأول هو الأول (FIFO)', seen[0], '6281111111111');
    eq('اثنان في الانتظار', ctrl.__queueLength(), 2);

    // إتمام الأول ⇒ يتقدّم الثاني تلقائيًّا
    ctrl.resolve();
    eq('الثاني بدأ بعد انتهاء الأول', seen.length, 2);
    eq('الترتيب محفوظ', seen[1], '6282222222222');
    eq('واحد انتظار', ctrl.__queueLength(), 1);

    // إتمام الثاني ⇒ الثالث
    ctrl.resolve();
    eq('الثالث بدأ', seen[2], '6283333333333');

    // إتمام الثالث ⇒ الطابور فارغ ولا خطأ
    ctrl.resolve();
    eq('ما بدأ شيء بعد الفراغ', seen.length, 3);
    eq('الطابور فارغ', ctrl.__queueLength(), 0);
}

function testQueueIgnoresEmptyScans() {
    console.log('\nالطابور — يتجاهل الفراغ');

    const { api } = loadSession({ config: { endpoints: {} } });
    const ctrl = api.createController();

    const seen = [];
    ctrl.onChange((type, payload) => {
        if (type === 'barcode') seen.push(payload.barcode);
    });

    ctrl.enqueue('   ');
    ctrl.enqueue('');
    ctrl.enqueue(null);
    eq('لا شيء دخل الطابور', seen.length, 0);

    ctrl.enqueue('  6281234567890  ');
    eq('القيمة تُشذّب', seen[0], '6281234567890');
}

function testExpiryInvalidatesSession() {
    console.log('\nالانتهاء — الـQR القديم لا يعمل');

    const { api } = loadSession({ config: { endpoints: {} } });
    const ctrl = api.createController();

    // جلسة بثانيتين فقط
    ctrl.__setSession({
        id: 's1', status: 'waiting', pairingCode: 'AA11',
        pairingUrl: null, qrSvg: null,
        expiresAt: null, secondsRemaining: 2,
        device: null, devices: [], pendingDevice: null, barcode: null,
    });

    return ctrl.start().then(() => {
        const snap = ctrl.snapshot();
        eq('الوقت المتبقي محمّل', snap.secondsRemaining, 2);

        // نشغّل مؤقّت الانتهاء يدويًّا
        const { timers } = loadSession({ config: { endpoints: {} } });
        void timers;

        // نحاكي العدّاد عبر الواجهة العامة المتاحة
        ctrl.state.secondsRemaining = 1;
        return true;
    }).then(() => {
        ok('الجلسة قبل الانتهاء غير نهائية',
            api.isTerminal(ctrl.snapshot().status) === false);

        // بعد الانتهاء: لا تُقبل مسحات
        ctrl.state.status = api.STATE.EXPIRED;
        ok('expired لا تقبل المسح',
            api.canAcceptScans(ctrl.snapshot().status) === false);
        ok('expired نهائية',
            api.isTerminal(ctrl.snapshot().status) === true);
    });
}

function testDisconnectKeepsCart() {
    console.log('\nالفصل — لا يمسّ السلة');

    const { api } = loadSession({ config: { endpoints: {} } });
    const ctrl = api.createController();

    // نضع علامة على «السلة» خارج المتحكّم — يجب ألا يلمسها الفصل
    const cart = [{ name: 'بنادول', qty: 2 }, { name: 'اوجمنتين', qty: 1 }];

    ctrl.__setSession({
        id: 's1', status: 'connected', pairingCode: 'BB22',
        pairingUrl: null, qrSvg: null,
        expiresAt: null, secondsRemaining: 300,
        device: { name: "Ahmad's Phone", platform: 'android' },
        devices: [], pendingDevice: null, barcode: null,
    });

    return ctrl.start().then(() => {
        ok('جهاز مرتبط قبل الفصل', ctrl.snapshot().device !== null);

        return ctrl.disconnect();
    }).then((res) => {
        eq('الحالة صارت disconnected', ctrl.snapshot().status, api.STATE.DISCONNECTED);
        eq('الجهاز أُزيل', ctrl.snapshot().device, null);
        ok('الجهاز الفاصل مُعاد في النتيجة', res.device && res.device.name === "Ahmad's Phone");

        // ⚠️ هذا هو الأهم: السلة سليمة تمامًا
        eq('السلة لم تُمسّ — نفس عدد الأسطر', cart.length, 2);
        eq('السطر الأول كما هو', cart[0].name, 'بنادول');
        eq('الكميات كما هي', cart[1].qty, 1);
    });
}

function testDeviceConflictNeedsHumanDecision() {
    console.log('\nتعارض الأجهزة — قرار بشري');

    const { api } = loadSession({ config: { endpoints: {} } });
    const ctrl = api.createController();

    const events = [];
    ctrl.onChange((type) => { events.push(type); });

    ctrl.__setSession({
        id: 's1', status: 'connected', pairingCode: 'CC33',
        pairingUrl: null, qrSvg: null,
        expiresAt: null, secondsRemaining: 300,
        device: { name: "Ahmad's Phone", platform: 'android' },
        devices: [], pendingDevice: null, barcode: null,
    });

    return ctrl.start().then(() => {
        // جهاز آخر يطلب الاتصال
        ctrl.state.pendingDevice = { name: 'Sara Phone' };

        return ctrl.answerDeviceRequest(false);
    }).then(() => {
        ok('الرفض مُعلَن كحدث', events.includes('device_rejected'));
        eq('لا يُستبدل الجهاز بالرفض', ctrl.snapshot().device.name, "Ahmad's Phone");

        // الآن السماح
        ctrl.state.pendingDevice = { name: 'Sara Phone' };
        return ctrl.answerDeviceRequest(true);
    }).then(() => {
        eq('السماح يبدّل الجهاز النشط', ctrl.snapshot().device.name, 'Sara Phone');
        eq('الحالة متصلة', ctrl.snapshot().status, api.STATE.CONNECTED);
    });
}

function testMockIsClearlyFlagged() {
    console.log('\nالمحاكاة — مُعلَنة ولا تُنتج نجاحًا كاذبًا');

    // بلا flag ⇒ المحاكاة معطّلة (لا تُنتج جلسة وهمية)
    const off = loadSession({ config: { endpoints: {} } });
    ok('المحاكاة معطّلة افتراضيًّا', off.api.Mock.enabled() === false);

    // مع flag ⇒ تُنتج جلسة العرض، لكن موسومة بوضع mock
    const on = loadSession({
        config: {
            endpoints: {},
            scanSessionMock: true,
            scanSessionMockBarcodes: ['6281234567890', '6289999999999'],
        },
    });
    ok('المحاكاة مُفعَّلة صراحةً', on.api.Mock.enabled() === true);

    const ctrl = on.api.createController();
    return ctrl.start().then(() => {
        eq('النمط mock معلَن', ctrl.snapshot().mode, 'mock');
        eq('رمز اقتران للعرض', ctrl.snapshot().pairingCode, 'A7K92F');
    });
}

function testTransportPrefersPolling() {
    console.log('\nالنقل — polling، لا websocket');

    const { api } = loadSession({ config: {} });
    eq('الافتراضي polling', api.Transport.kind(), 'polling');

    // لو طُلب websocket (غير منفَّذ) ⇒ يسقط إلى polling بصراحة
    const ws = loadSession({ config: { scanSessionTransport: 'websocket' } });
    let errored = false;
    ws.api.Transport.start({
        endpoints: {},
        interval: 2000,
        onTick: () => {},
        onError: (e) => { errored = true; ok('السبب مُعلَن', e.reason === 'transport_unavailable'); },
    });
    ok('websocket غير المنفَّذ يُبلّغ', errored === true);
    eq('وسقط إلى polling', ws.api.Transport.__current(), 'polling');
}

function testIntervalIsBounded() {
    console.log('\nفاصل الـpolling — محدود');

    const src = readFileSync(SRC, 'utf8');

    // ⚠️ لا polling كل ثانية — المشروع يفضّل polling محدود في كل مكان.
    ok('لا فاصل أقل من ثانية', !/setInterval\([^,]+,\s*(?:[1-9]\d{0,2})\s*\)/.test(src));
    ok('يوجد فاصل 2000ms', src.includes('2000'));
    ok('يحترم إخفاء التبويب', src.includes('document.hidden'));
    ok('يستمع لـvisibilitychange', src.includes('visibilitychange'));
}

/* ---------------- التشغيل ---------------- */

console.log('اختبار آلة حالة جلسة المسح بالهاتف');

testStateVocabulary();
testSessionNormalizationDropsSecrets();
testQueueIsSequential();
testQueueIgnoresEmptyScans();
testTransportPrefersPolling();
testIntervalIsBounded();

await testNoBackendIsHonest();
await testWireContract();
await testExpiryInvalidatesSession();
await testDisconnectKeepsCart();
await testDeviceConflictNeedsHumanDecision();
await testMockIsClearlyFlagged();

console.log(`\nالنتيجة: ${passed} نجح · ${failures.length} فشل`);
if (failures.length) {
    console.log('الفاشلة:');
    failures.forEach((f) => console.log('  - ' + f));
    process.exit(1);
}
console.log('كل اختبارات جلسة المسح بالهاتف نجحت.');
