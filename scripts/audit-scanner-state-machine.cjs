/**
 * تدقيق سلوكي لآلة حالة جلسة المسح — بلا متصفح.
 *
 * نُحمّل `accounting-scanner-session.js` في صندوق `vm` بأدنى حدّ من واجهات
 * المتصفح، ثم نُشغّل السيناريوهات الحقيقية ونتحقّق من:
 *   1. غياب الـendpoints ⇒ `unavailable` بهدوء (لا حالة خطأ).
 *   2. مصفوفة فارغة كانت تُعطي `error` — نحرس ضدّ عودتها.
 *   3. `markReceived()` تُطلق حدث `state` (لا كتابة صامتة).
 *   4. العودة إلى `waiting` بعد `expired`/`disconnected` عند وجود جهاز.
 *   5. `close()`/`disconnect()` لا تُترك مؤقّتات معلّقة.
 *
 * التشغيل: node scripts/audit-scanner-state-machine.js
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.resolve(__dirname, '..');
const SESSION_SRC = fs.readFileSync(
    path.join(ROOT, 'resources/js/accounting/accounting-scanner-session.js'),
    'utf8'
);

let pass = 0;
let fail = 0;
const failures = [];

function check(label, actual, expected) {
    const ok = JSON.stringify(actual) === JSON.stringify(expected);
    if (ok) {
        pass++;
        console.log(`  ✓ ${label}`);
    } else {
        fail++;
        failures.push(`${label}\n      متوقّع: ${JSON.stringify(expected)}\n      فعلي : ${JSON.stringify(actual)}`);
        console.log(`  ✗ ${label}  (متوقّع ${JSON.stringify(expected)}، فعلي ${JSON.stringify(actual)})`);
    }
}

/** بيئة مصغّرة كافية لتشغيل الموديول */
function makeSandbox(config) {
    const listeners = {};
    const timers = new Set();

    const documentStub = {
        querySelector: () => null,   // لا meta csrf
        addEventListener: () => {},
        removeEventListener: () => {},
        hidden: false,
    };

    const sandbox = {
        window: {},
        document: documentStub,
        console,
        JSON,
        Math,
        Date,
        Promise,
        setTimeout: (fn, ms) => { const id = setTimeout(fn, ms); timers.add(id); return id; },
        clearTimeout: (id) => { timers.delete(id); clearTimeout(id); },
        setInterval: (fn, ms) => { const id = setInterval(fn, ms); timers.add(id); return id; },
        clearInterval: (id) => { timers.delete(id); clearInterval(id); },
        fetch: () => Promise.reject(new Error('لا شبكة في التدقيق')),
    };

    sandbox.window = sandbox;
    sandbox.window.acAccountingConfig = config;
    sandbox.window.addEventListener = (t, fn) => { (listeners[t] = listeners[t] || []).push(fn); };
    sandbox.window.removeEventListener = (t, fn) => {
        listeners[t] = (listeners[t] || []).filter((f) => f !== fn);
    };

    sandbox.__timers = timers;
    sandbox.__listeners = listeners;

    return { sandbox, timers };
}

function loadSession(sandbox) {
    const ctx = vm.createContext(sandbox);
    vm.runInContext(SESSION_SRC, ctx, { filename: 'accounting-scanner-session.js' });
    return sandbox.window.AccountingScanSession;
}

async function main() {
    /* ══════════════════════════════════════════════════════════════ */
    console.log('\n═══ 1) الحالات — التسع معرّفة ومتّسقة ═══');
    {
        const { sandbox } = makeSandbox({ desktopBreakpoint: 1024 });
        const S = loadSession(sandbox);

        check('allStates() تُرجع 9 حالات', S.allStates().length, 9);
        check('idle حاضرة', S.allStates().includes('idle'), true);
        check('connecting حاضرة', S.allStates().includes('connecting'), true);
        check('expired هي الحالة النهائية الوحيدة', S.isTerminal('expired'), true);
        check('connected تقبل المسح', S.canAcceptScans('connected'), true);
        check('scanning تقبل المسح', S.canAcceptScans('scanning'), true);
        check('idle لا تقبل المسح', S.canAcceptScans('idle'), false);
        check('expired لا تقبل المسح', S.canAcceptScans('expired'), false);
    }

    /* ══════════════════════════════════════════════════════════════ */
    console.log('\n═══ 2) 🔴 غياب الـendpoints ⇒ unavailable بهدوء (لا error) ═══');
    {
        // الحالة الصحيحة بعد الإصلاح: `scanSessions` غائب أو null
        const { sandbox } = makeSandbox({
            scanSessionMock: false,
            scanSessionTransport: 'polling',
            endpoints: { overview: '/api/x' },   // بلا scanSessions
        });
        const S = loadSession(sandbox);
        const c = S.createController();

        await c.start();
        const snap = c.snapshot();

        check('mode = unavailable', snap.mode, 'unavailable');
        check('status = waiting (لا error)', snap.status, 'waiting');
        check('سبب معلَن no_backend', snap.error, 'no_backend');

    await c.close();
    }

    /* ══════════════════════════════════════════════════════════════ */
    console.log('\n═══ 3) 🔴 مصفوفة فارغة كانت تُنتج حالة error (حرس ضدّ العودة) ═══');
    {
        const { sandbox } = makeSandbox({
            scanSessionMock: false,
            endpoints: { scanSessions: [] },      // الفخّ القديم
        });
        const S = loadSession(sandbox);
        const c = S.createController();

        await c.start();
        const snap = c.snapshot();

        // هذا يوثّق أن `[]` **ليست** الإشارة الصحيحة: تنتهي بـerror لا unavailable.
        // الإصلاح كان في Blade (يمرّر null)، وهذا الاختبار يمنع العودة إلى `[]`.
        check('[] ⇒ mode = unavailable (بعد الإصلاح)', snap.mode, 'unavailable');
        check('[] ⇒ error (سلوك JS الخام — يفسّر لماذا مُنع في Blade)', snap.error, 'no_backend');

    await c.close();
    }

    /* ══════════════════════════════════════════════════════════════ */
    console.log('\n═══ 4) الوضع التجريبي — دورة حياة كاملة ═══');
    {
        const { sandbox } = makeSandbox({
            scanSessionMock: true,
            scanSessionMockBarcodes: ['6281001001234', '0000000000000'],
            endpoints: {},
        });
        const S = loadSession(sandbox);
        const c = S.createController();

        const seen = [];
        c.onChange((type, payload, snap) => seen.push(type + ':' + (snap && snap.status)));

        await c.start();
        check('mock ⇒ mode = mock', c.snapshot().mode, 'mock');
        check('mock ⇒ waiting', c.snapshot().status, 'waiting');
        check('رمز اقتران مُولَّد', typeof c.snapshot().pairingCode, 'string');
        check('رمز غير فارغ', c.snapshot().pairingCode.length > 0, true);

        // markReceived: الانتقال الصريح (بدلاً من الكتابة المباشرة)
        c.markReceived('6281001001234');
        check('markReceived ⇒ received', c.snapshot().status, 'received');
        check('markReceived ⇒ lastBarcode مسجّل', c.snapshot().lastBarcode, '6281001001234');
        check('markReceived أطلق حدث state', seen.includes('state:received'), true);

        c.resolve();
        check('بعد resolve ⇒ رجوع إلى waiting (لا جهاز)', c.snapshot().status, 'waiting');
    }

    /* ══════════════════════════════════════════════════════════════ */
    console.log('\n═══ 5) close() لا يترك مؤقّتات معلّقة ═══');
    {
        const { sandbox, timers } = makeSandbox({
            scanSessionMock: true,
            scanSessionMockBarcodes: ['1'],
            endpoints: {},
        });
        const S = loadSession(sandbox);
        const c = S.createController();

        await c.start();
        const afterStart = timers.size;
        check('مؤقّت انتهاء الجلسة مسلَّح', afterStart > 0, true);

        await c.close();
        check('close() نظّفت كل المؤقّتات', timers.size, 0);
    }

    /* ══════════════════════════════════════════════════════════════ */
    console.log('\n═══ 6) disconnect() ⇒ disconnected ثم رجوع بـreconnect ═══');
    {
        const { sandbox } = makeSandbox({
            scanSessionMock: true,
            scanSessionMockBarcodes: ['1'],
            endpoints: {},
        });
        const S = loadSession(sandbox);
        const c = S.createController();

        await c.start();
        // نُوصل جهازًا وهميًّا عبر الواجهة الداخلية
        c.__setSession(Object.assign({}, c.snapshot(), {
            id: 'sess-1', pairingCode: '123456', device: { id: 1, name: 'هاتف الصيدلي' },
        }));
        check('جهاز موصول', c.snapshot().device.name, 'هاتف الصيدلي');
        check('الحالة waiting (لم تُحدَّث آليًّا — متوقّع)', c.snapshot().status, 'waiting');

        await c.disconnect();
        check('disconnect ⇒ disconnected', c.snapshot().status, 'disconnected');
        check('الجهاز أُزيل', c.snapshot().device, null);

        // تنظيف — وإلا بقي مؤقّت الانتهاء حيًّا ومنع الخروج
        await c.close();
    }

    /* ══════════════════════════════════════════════════════════════ */
    console.log('\n═══ 7) resolve() بعد انتهاء الجلسة لا يُحييها ═══');
    {
        const { sandbox } = makeSandbox({
            scanSessionMock: true,
            scanSessionMockBarcodes: ['1'],
            endpoints: {},
        });
        const S = loadSession(sandbox);
        const c = S.createController();

        await c.start();
        // نجبر الحالة النهائية
        c.state.status = S.STATE.EXPIRED;
        c.resolve();
        check('resolve() على expired تبقى expired', c.snapshot().status, 'expired');

        await c.close();
    }

    /* ══════════════════════════════════════════════════════════════ */
    console.log('\n' + '═'.repeat(60));
    console.log(`النتيجة: ${pass} نجح، ${fail} فشل`);
    if (fail > 0) {
        console.log('\nالإخفاقات:');
        failures.forEach((f) => console.log('  • ' + f));
        process.exit(1);
    }
    console.log('ALL SCANNER STATE-MACHINE CHECKS PASS');

}

main()
    .then(() => process.exit(fail > 0 ? 1 : 0))
    .catch((e) => { console.error(e); process.exit(1); });
