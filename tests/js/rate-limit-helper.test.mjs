/**
 * اختبار مساعد إعادة المحاولة عند HTTP 429 — بدون متصفح.
 *
 * يُرسم القالب الحقيقي `resources/views/pharmacy/import/_rate_limit.blade.php`
 * بمُصرِّف Blade (عبر PHP)، ثم يُستخرج وسم <script> من المخرَج ويُشغَّل داخل
 * vm sandbox مع بدائل لـwindow/fetch.
 *
 * لماذا نرسم القالب ولا نقرأ ملف JS؟
 * لأن المساعد مكتوب داخل Blade ويحمل نصوصاً من ملفات الترجمة. الرسم يُثبت
 * ثلاثة أشياء معاً: القالب يُصرَّف، والنصوص العربية تصل فعلاً، والمنطق يصحّ.
 * لو نسخنا المنطق هنا لكان الاختبار يشهد على نفسه لا على الكود.
 *
 * الغرض: تثبيت جدول قرار 429 — متى نعيد المحاولة ومتى نتوقف وماذا نقول
 * للصيدلي. الحالات الحدّية (15 ثانية نعم / 16 لا) هي الأهم، لأن الخطأ فيها
 * إمّا يجمّد الواجهة أو يُخفي الحجب.
 *
 * التشغيل:  node tests/js/rate-limit-helper.test.mjs
 * ⚠️ يحتاج `php` على PATH (المشروع Laravel — لا يعمل بدونه أصلاً).
 */

import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

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

/* ---------------- رسم القالب الحقيقي ---------------- */

// String.raw حتى لا يُبتلع `\C` في مسارات الأصناف (JS يحذف الشرطة المائلة في
// التخطيطات غير المعروفة مثل \C — وهذا يُنتج PHP مكسوراً بصمت).
const RENDER_SCRIPT = String.raw`
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app()->setLocale("ar");
echo view("pharmacy.import._rate_limit")->render();
`;

function renderPartial() {
    try {
        return execFileSync('php', ['-r', RENDER_SCRIPT], {
            cwd: ROOT,
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'pipe'],
        });
    } catch (e) {
        console.error('تعذّر رسم القالب بمُصرِّف Blade:');
        console.error(e.stderr ? String(e.stderr) : String(e));
        process.exit(1);
    }
}

const html = renderPartial();
const scriptMatch = html.match(/<script>([\s\S]*?)<\/script>/);

if (!scriptMatch) {
    console.error('لم أجد وسم <script> في مخرَج القالب — هل تغيّر شكل القالب؟');
    console.error(html.slice(0, 400));
    process.exit(1);
}

const SCRIPT_BODY = scriptMatch[1];

/* ---------------- بدائل الشبكة ---------------- */

/** استجابة وهمية بالشكل الذي يقرأه المساعد: status/ok/headers.get/json(). */
function fakeResponse(status, { body = {}, headers = {} } = {}) {
    return {
        status,
        ok: status >= 200 && status < 300,
        headers: {
            get: (name) => (Object.prototype.hasOwnProperty.call(headers, name) ? String(headers[name]) : null),
        },
        json: async () => body,
    };
}

/**
 * @param {Array<object|Function>} responses استجابات متتابعة؛ الأخيرة تُتكرّر.
 */
function makeFetch(responses) {
    const calls = [];

    const impl = async (url, options) => {
        calls.push({ url, options });
        const next = responses[Math.min(calls.length - 1, responses.length - 1)];
        return typeof next === 'function' ? next(url, options) : next;
    };

    return { impl, calls };
}

/**
 * يُحمّل المساعد من مخرَج القالب الحقيقي داخل vm sandbox.
 *
 * المؤقّت مُستبدَل بحيث ينفّذ فوراً ويسجّل المدة المطلوبة — فنُثبت مقدار
 * الانتظار بلا انتظار فعلي (وإلا صار كل اختبار إعادة محاولة ثانيتين).
 */
function loadHelper({ responses }) {
    const { impl, calls } = makeFetch(responses);
    const delays = [];

    const window = {
        setTimeout: (fn, ms) => {
            delays.push(ms);
            return setTimeout(fn, 0);
        },
    };

    const sandbox = { window, fetch: impl };
    vm.createContext(sandbox);
    vm.runInContext(SCRIPT_BODY, sandbox);

    return { api: sandbox.window.DawayRateLimit, calls, delays };
}

const decideUrl = 'https://daway.test/pharmacy/inventory/import/abc/decide';

const post = (api) => api.request(decideUrl, {
    method: 'POST',
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
    body: '{}',
});

/* ---------------- الحالات ---------------- */

console.log('اختبار مساعد 429 (DawayRateLimit) — من مخرَج Blade الحقيقي');

function testThePartialExposesTheHelper() {
    console.log('\n[1] القالب يُعرّف المساعد');
    const { api } = loadHelper({ responses: [fakeResponse(200)] });

    ok('window.DawayRateLimit موجود', api !== undefined);
    ok('request دالة', api && typeof api.request === 'function');
    ok('message دالة', api && typeof api.message === 'function');
    ok('autoRetryMaxSeconds = 15', api && api.autoRetryMaxSeconds === 15, `=${api && api.autoRetryMaxSeconds}`);

    // النصوص عربية فعلاً (لا إنجليزي ولا مفتاح ترجمة خام)
    const msg = api.message(30);
    ok('رسالة الانتظار عربية', msg.includes('تجاوزت الحد'), msg);
    ok('رسالة الانتظار تحمل الثواني', msg.includes('30'), msg);

    const generic = api.message(0);
    ok('الرسالة العامة مختلفة عن رسالة الثواني', generic !== msg && generic.length > 0, generic);
}

async function testRetriesOnceWhenWaitIsShort() {
    console.log('\n[2] 429 وانتظار قصير ⇒ إعادة محاولة واحدة تنجح');
    const { api, calls, delays } = loadHelper({
        responses: [
            fakeResponse(429, { headers: { 'Retry-After': 1 }, body: { message: 'x' } }),
            fakeResponse(200, { body: { success: true } }),
        ],
    });

    const res = await post(api);

    ok('النتيجة ناجحة', res.ok === true, `ok=${res.ok} status=${res.status}`);
    ok('الجسم من المحاولة الثانية', res.body && res.body.success === true);
    ok('محاولتان بالضبط', calls.length === 2, `calls=${calls.length}`);
    // 1 ثانية + هامش 1 ثانية = 2000ms
    ok('انتظر ثانيتين قبل الإعادة', delays[0] === 2000, `delays=${JSON.stringify(delays)}`);
}

async function testRetriesAtTheBoundary() {
    console.log('\n[3] الحدّ الأعلى 15 ثانية ⇒ يعيد المحاولة');
    const { api, calls } = loadHelper({
        responses: [
            fakeResponse(429, { headers: { 'Retry-After': 15 } }),
            fakeResponse(200, { body: { success: true } }),
        ],
    });

    const res = await post(api);
    ok('نجحت بعد الإعادة', res.ok === true);
    ok('محاولتان', calls.length === 2, `calls=${calls.length}`);
}

async function testDoesNotRetryJustAboveTheBoundary() {
    console.log('\n[4] 16 ثانية ⇒ لا يعيد المحاولة (لا تجميد للواجهة)');
    const { api, calls } = loadHelper({
        responses: [fakeResponse(429, { headers: { 'Retry-After': 16 } })],
    });

    const res = await post(api);
    ok('لم يُعد المحاولة', calls.length === 1, `calls=${calls.length}`);
    ok('أُعيد 429 للواجهة', res.status === 429 && res.ok === false);
    ok('retryAfter = 16', res.retryAfter === 16, `=${res.retryAfter}`);
    ok('الرسالة تحمل 16', res.message.includes('16'), res.message);
}

async function testLongWaitReportsSecondsWithoutRetrying() {
    console.log('\n[5] 429 وانتظار طويل (300) ⇒ رسالة بالثواني بلا إعادة');
    const { api, calls, delays } = loadHelper({
        responses: [fakeResponse(429, { headers: { 'Retry-After': 300 } })],
    });

    const res = await post(api);

    ok('لا إعادة محاولة', calls.length === 1, `calls=${calls.length}`);
    ok('لا انتظار مُجدول', delays.length === 0, `delays=${JSON.stringify(delays)}`);
    ok('retryAfter = 300', res.retryAfter === 300, `=${res.retryAfter}`);
    ok('الرسالة تحمل 300', res.message.includes('300'), res.message);
}

async function testMissingHeadersFallBackToGenericMessage() {
    console.log('\n[6] 429 بلا ترويسات ⇒ رسالة عامة بلا إعادة');
    const { api, calls } = loadHelper({ responses: [fakeResponse(429)] });

    const res = await post(api);

    ok('لا إعادة محاولة', calls.length === 1, `calls=${calls.length}`);
    ok('retryAfter = 0', res.retryAfter === 0, `=${res.retryAfter}`);
    ok('استُخدمت الرسالة العامة', res.message === api.message(0), res.message);
}

async function testDerivesSecondsFromResetHeader() {
    console.log('\n[7] بلا Retry-After لكن مع X-RateLimit-Reset ⇒ يُشتق العدد');
    const resetAt = Math.floor(Date.now() / 1000) + 300;

    const { api, calls } = loadHelper({
        responses: [fakeResponse(429, { headers: { 'X-RateLimit-Reset': resetAt } })],
    });

    const res = await post(api);

    ok('اشتُقّت الثواني من الـreset', res.retryAfter >= 295 && res.retryAfter <= 300, `=${res.retryAfter}`);
    ok('لا إعادة محاولة (الانتظار طويل)', calls.length === 1, `calls=${calls.length}`);
}

async function testNonNumericRetryAfterDoesNotCrash() {
    console.log('\n[8] Retry-After غير رقمي (HTTP-date) ⇒ لا انهيار');
    const { api, calls } = loadHelper({
        responses: [fakeResponse(429, { headers: { 'Retry-After': 'Wed, 21 Oct 2026 07:28:00 GMT' } })],
    });

    const res = await post(api);

    ok('لم ينهر', res && res.status === 429);
    ok('retryAfter = 0', res.retryAfter === 0, `=${res.retryAfter}`);
    ok('رسالة عامة', res.message === api.message(0), res.message);
    ok('لا إعادة محاولة', calls.length === 1, `calls=${calls.length}`);
}

async function testRetriesOnlyOnceEvenIfStillBlocked() {
    console.log('\n[9] الحجب مستمرّ ⇒ محاولتان لا أكثر');
    const { api, calls } = loadHelper({
        responses: [fakeResponse(429, { headers: { 'Retry-After': 1 } })],
    });

    const res = await post(api);

    ok('محاولتان بالضبط', calls.length === 2, `calls=${calls.length}`);
    ok('أُعيد 429 بعد المحاولة الثانية', res.status === 429 && res.ok === false);
}

async function testSuccessfulResponseIsParsed() {
    console.log('\n[10] استجابة ناجحة ⇒ JSON بلا إعادة');
    const { api, calls } = loadHelper({
        responses: [fakeResponse(200, { body: { success: true, decisions: { pending: 0 } } })],
    });

    const res = await post(api);

    ok('نجحت', res.ok === true);
    ok('الجسم مقروء', res.body && res.body.decisions && res.body.decisions.pending === 0);
    ok('محاولة واحدة', calls.length === 1, `calls=${calls.length}`);
    ok('retryAfter = 0', res.retryAfter === 0);
}

async function testServerErrorIsNotRetried() {
    console.log('\n[11] خطأ 500 ⇒ لا إعادة (429 فقط تُعاد)');
    const { api, calls } = loadHelper({
        responses: [fakeResponse(500, { body: { message: 'boom' } })],
    });

    const res = await post(api);

    ok('محاولة واحدة', calls.length === 1, `calls=${calls.length}`);
    ok('ok = false', res.ok === false);
    ok('status = 500', res.status === 500);
    ok('الجسم محفوظ', res.body && res.body.message === 'boom');
}

/* ---------------- run ---------------- */

testThePartialExposesTheHelper();
await testRetriesOnceWhenWaitIsShort();
await testRetriesAtTheBoundary();
await testDoesNotRetryJustAboveTheBoundary();
await testLongWaitReportsSecondsWithoutRetrying();
await testMissingHeadersFallBackToGenericMessage();
await testDerivesSecondsFromResetHeader();
await testNonNumericRetryAfterDoesNotCrash();
await testRetriesOnlyOnceEvenIfStillBlocked();
await testSuccessfulResponseIsParsed();
await testServerErrorIsNotRetried();

console.log(`\nالنتيجة: ${passed} نجح · ${failures.length} فشل`);
if (failures.length) {
    console.log('الفاشلة:');
    failures.forEach((f) => console.log('  - ' + f));
    process.exit(1);
}
console.log('كل اختبارات مساعد 429 نجحت.');
