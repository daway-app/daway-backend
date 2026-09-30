/**
 * حارس انحدار لعطل /pharmacy/medicines — بدون متصفح.
 *
 * السياق: كان resources/js/offline/render.js يهدم جدول الأدوية
 * (`tbody.innerHTML = ...`) ويعيد بناءه بقالب ناقص (أيقونة بديلة دائمًا بدل
 * الصورة، وخلية إجراءات فارغة) ⇒ «الصف الأول كامل والبقية صور فقط».
 *
 * الحل المُثبت: أُلغيت إعادة بناء جدول الأدوية من الكاش نهائيًا. هذا الاختبار
 * يثبّت القرار حتى لا يعود أحد بإضافة إعادة البناء مستقبلًا.
 *
 * التشغيل:  node tests/js/offline-render-medicines.test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const RENDER_SRC = readFileSync(join(ROOT, 'resources', 'js', 'offline', 'render.js'), 'utf8');

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

/* ---------------- sandbox: عناصر DOM وهمية ---------------- */

/** عنصر بسيط يتتبّع كتابة innerHTML ويدعم querySelector(All) الشكلية. */
function makeElement(tag = 'div') {
    const el = {
        tagName: tag,
        innerHTML: '',
        textContent: '',
        dataset: {},
        classList: { add() {}, remove() {} },
        children: [],
        _listeners: {},
        addEventListener(type, fn) { (this._listeners[type] ||= []).push(fn); },
        removeEventListener() {},
        querySelector() { return null; },
        querySelectorAll() { return []; },
        closest() { return null; },
        setAttribute() {},
        getAttribute() { return null; },
        appendChild(c) { this.children.push(c); return c; },
        style: {},
    };
    return el;
}

function makeSandbox({ tbodyHtmlBySelector = {} } = {}) {
    /** @type {Record<string, ReturnType<typeof makeElement>>} */
    const bySelector = {};
    for (const [sel, html] of Object.entries(tbodyHtmlBySelector)) {
        bySelector[sel] = makeElement('tbody');
        bySelector[sel].innerHTML = html;
    }

    const document = {
        createElement: (tag) => makeElement(tag),
        // يُطابق سيلكتور الـ tbody فقط — وهو كل ما تستعمله render.js
        querySelector: (sel) => bySelector[sel] || null,
        querySelectorAll: () => [],
    };

    const sandbox = {
        document,
        console,
        Promise,
        Object,
        Array,
        String,
        Number,
        Boolean,
        Math,
        JSON,
        setTimeout,
        // نُهيّئ db على الواجهة العالمية (render.js يستوردها، لكن نمرّر كائناً جاهزاً).
        db: {
            _rows: {},
            getAll(store) { return Promise.resolve(this._rows[store] || []); },
        },
        __selectors: bySelector,
    };
    sandbox.window = sandbox;
    sandbox.globalThis = sandbox;
    return sandbox;
}

/**
 * يُحمّل render.js داخل vm بعد استبدال `import { db }` بكائن محقون.
 * نُزيل سطر الاستيراد لأنه غير قابل للتنفيذ بلا محمّل وحدات.
 */
function loadRender(sandbox, renderSrc) {
    const src = renderSrc
        .replace(/^\s*import\s+\{[^}]*\}\s+from\s+['"][^'"]+['"];?\s*$/gm, '')
        .replace(/^\s*export\s+const\s+/gm, 'const ')
        .concat('\n;globalThis.__render = render;');
    const context = vm.createContext(sandbox);
    vm.runInContext(src, context, { filename: 'render.js' });
    return sandbox.__render;
}

/* ---------------- الاختبارات ---------------- */

const SENTINEL = '<tr id="sentinel-server-row"><td>SERVER ROW</td></tr>';
const MED_SEL = '[data-offline-page="medicines"] tbody';

console.log('offline renderer — medicines table must not be rebuilt from cache');

// 1) كود المصدر لا يحتوي أي استعلام عن جدول الأدوية (حارس بنيوي).
ok(
    'render.js no longer selects the medicines tbody',
    !RENDER_SRC.includes('[data-offline-page="medicines"] tbody'),
    'وجدنا سيلكتور جدول الأدوية في المصدر',
);
ok(
    'render.js has no renderMedicines function',
    !/function\s+renderMedicines/.test(RENDER_SRC),
    'الدالة renderMedicines ما زالت معرّفة',
);
ok(
    'render.js does not read the medicines store',
    !/getAll\(\s*['"]medicines['"]\s*\)/.test(RENDER_SRC),
    'render.js ما زال يقرأ مخزن medicines',
);

// 2) سلوكيًا: renderFromCache لا يلمس innerHTML للصفوف المُرسَلة من السيرفر.
{
    const sandbox = makeSandbox({ tbodyHtmlBySelector: { [MED_SEL]: SENTINEL } });
    sandbox.db._rows = {
        inventory: [
            { id: 1, quantity: 4, medicine: { trade_name: 'Inv', active_ingredient: 'ing' } },
        ],
        medicines: [
            { id: 9, quantity: 3, price: 5, medicine: { trade_name: 'CachedMed' } },
        ],
        inquiries: [],
    };

    const render = loadRender(sandbox, RENDER_SRC);

    await render.renderFromCache();

    const tbody = sandbox.__selectors[MED_SEL];
    ok(
        'medicines tbody keeps the server-rendered row untouched',
        tbody.innerHTML === SENTINEL,
        'الجسم تغيّر: ' + JSON.stringify(tbody.innerHTML).slice(0, 120),
    );
    ok(
        'cached medicine text is NOT injected into the medicines table',
        !tbody.innerHTML.includes('CachedMed'),
    );

    const invTbody = sandbox.__selectors['[data-offline-page="inventory"] tbody'];
    // المخزون ليس له tbody في هذا الصندوق ⇒ نتحقق من عدم انهيار الدالة فقط.
    ok('renderFromCache resolved without throwing', true);
    void invTbody;
}

// 3) المخزون ما زال يُعاد بناؤه (لم نكسر الصفحة السليمة).
{
    const INV_SEL = '[data-offline-page="inventory"] tbody';
    const sandbox = makeSandbox({ tbodyHtmlBySelector: { [INV_SEL]: '<tr><td>old</td></tr>' } });
    sandbox.db._rows = {
        inventory: [
            { id: 7, quantity: 4, medicine: { trade_name: 'InvName', active_ingredient: 'ing' } },
        ],
        medicines: [],
        inquiries: [],
    };

    const render = loadRender(sandbox, RENDER_SRC);
    await render.renderFromCache();

    const html = sandbox.__selectors[INV_SEL].innerHTML;
    ok('inventory tbody is still rebuilt from cache', html.includes('InvName'));
    ok(
        'inventory row keeps its stepper input',
        html.includes("name='quantities[7]'") || html.includes('name="quantities[7]"') || html.includes('quantities[7]'),
    );
    ok('inventory row keeps generated status badge', html.includes('ph-badge'));
}

/* ---------------- النتيجة ---------------- */

console.log('');
if (failures.length) {
    console.log(`\u2717 ${failures.length} فشل من ${passed + failures.length}`);
    failures.forEach((f) => console.log('   - ' + f));
    process.exit(1);
}
console.log(`\u2713 كل الاختبارات نجحت (${passed})`);
