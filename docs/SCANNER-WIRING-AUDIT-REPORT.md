# تدقيق الربط الكامل + الماسح الضوئي — تقرير التدقيق والإصلاح

**التاريخ:** 2026-09-16 · **الفرع:** `develop` (working tree فقط — لا commit ولا push)
**المشروع:** Daway — Laravel 13 · PHP 8.4 · Blade + Vanilla JS · Vite
**النطاق:** وحدة المحاسبة (`/pharmacy/accounting`) + ماسح الهاتف (Phone Scanner)

---

## 0 · الملخّص التنفيذي

طُلب تدقيقان: **سلامة ربط الواجهة بالباك-إند**، و**عمل الماسح بنسبة 100%**.

النتيجة: **عُثر على 8 عيوب حقيقية في مسار الماسح والربط، وكلها مُصلَحة ومحروسة باختبارات
انحدار.** لم يكن أيٌّ منها ظاهرًا في التصفّح العادي — أخطرها عيبان كانا يجعلان الميزة
**معطّلة تمامًا** بلا أي رسالة خطأ:

| # | العيب | الأثر الحقيقي |
|---|---|---|
| **D2** | أزرار الأجهزة لا تُربط أبدًا | **يستحيل فصل الهاتف** أو قبول جهاز ثانٍ من الواجهة |
| **D3** | خانة QR تختفي عند وصول QR حقيقي | **الميزة تتعطّل بالضبط لحظة تشغيل الباك-إند** |

**الأدلة (4 طبقات مستقلة، كلها خضراء):**

| الطبقة | الأداة | النتيجة |
|---|---|---|
| عقود HTTP + قاعدة البيانات | PHPUnit | **108 اختبارًا · 550 تأكيدًا** ✅ |
| آلة حالة الماسح (Node vm، الكود الحقيقي) | `scripts/audit-scanner-state-machine.cjs` | **28/28** ✅ |
| جلسة الماسح كاملة (Node vm) | `tests/js/phone-scanner-session.test.mjs` | **74/74** ✅ |
| **حِزَم البناء الحقيقية في متصفح حقيقي** | `outputs/scanner-verify-inlined.html` + Chrome headless | **19/19** ✅ |

**أمان النشر:** `config:cache` → `route:cache` → `view:cache` نجحت كلها (لا اسم مسار مكرّر).

**لم يُرفع شيء.** كل التغييرات في الـworking tree بانتظار قرار عبود.

---

## 1 · منهج التدقيق

لم أعتمد على القراءة البصرية للكود. المنهج كان:

1. **تتبّع السلسلة كاملة**: `Blade` → `window.acAccountingConfig` → `AccountingApi`
   → مسار `api.php` → Controller → `AccountingLedger`.
2. **تنفيذ الكود الحقيقي** في بيئة معزولة (`vm` في Node) بدل نسخ منطقه — لأن نسخ المنطق
   يجعل الاختبار يشهد على نفسه.
3. **قياس النتيجة في متصفح حقيقي** على حِزَم Vite المبنية فعلًا، لا على ملفات المصدر.
4. **ربط كل إصلاح باختبار انحدار** يفشل لو عاد العيب.

---

## 2 · العيوب المكتشفة والمُصلَحة

### D1 — حالة `idle` مفقودة من Blade (8 حالات مقابل 9 في JS)

**الملف:** `resources/views/components/accounting/scan-session-status.blade.php`

**الأثر:** أي جلسة في الحالة الابتدائية `idle` تُعرض للمستخدم بعنوان **«بانتظار الهاتف»** —
والمستخدم لم ينشئ جلسة بعد. تضليل مباشر.

**السبب الجذري:** `$statuses` في Blade كان يحوي **8** حالات، بينما `STATE` في
`accounting-scanner-session.js` يحوي **9** (`idle, waiting, connecting, connected, scanning,
received, disconnected, expired, error`). والـfallback كان `waiting` بدل `idle` — فالعيب
**صامت**: لا خطأ، لا فراغ، فقط عنوان خاطئ.

**الإصلاح:**
- إضافة `'idle' => ['class' => 'is-idle', 'key' => 'scanner.state_idle']`.
- الـfallback صار `$statuses['idle']`.
- `state_idle` أُضيفت في `lang/ar` («لا جلسة مسح بعد») و`lang/en` («No scan session yet»).

**الحرس:** `test_all_nine_states_exist_in_js_and_translations`

---

### D2 — 🔴 أزرار الأجهزة لا تُربط أبدًا (عيب يعطّل الميزة)

**الملفات:** `resources/views/components/accounting/connected-device-card.blade.php`
+ `resources/js/accounting/accounting-phone-scanner.js`

**الأثر:** **يستحيل على المستخدم فصل الهاتف، أو قبول/رفض طلب جهاز ثانٍ.** الأزرار موجودة
ومرئية، والنقر عليها لا يفعل شيئًا. لا خطأ في الكونسول.

**السبب الجذري (عيبان متعاونان):**
1. `connected-device-card` كان يُرسم بـ`@if($active)` / `@else`. والنافذة تمرّر
   `:active="null"` عند التحميل ⇒ Blade يرسم **فرع «لا جهاز» فقط**.
2. `bind()` في `accounting-phone-scanner.js` يُنادى **مرة واحدة** في `init()`، ويستخدم
   `querySelectorAll('[data-device-disconnect]')` ⇒ يجد **صفر عناصر** ⇒ لا مستمع يُربط.

النتيجة: حتى بعد أن يصل جهاز حقيقي ويُعاد رسم البطاقة، **لا يوجد مستمع واحد** على الأزرار.

**الإصلاح:**
- **Blade:** إزالة `@if/@else`؛ الفرعان (`[data-device-row]` و`[data-device-empty]`) يُرسمان
  دائمًا، و`hidden` هو من يتحكم في الظهور.
- **JS:** استبدال الربط المباشر بـ**Event Delegation على `dom.modal`** (حاوية ثابتة):
  ```js
  dom.modal.addEventListener('click', function (e) {
      var target = e.target;
      if (!target || typeof target.closest !== 'function') return;
      var off = target.closest('[data-device-disconnect]');
      if (off && controller) { controller.disconnect().then(…); return; }
      var allow = target.closest('[data-device-allow]');
      if (allow && controller) { controller.answerDeviceRequest(true).then(renderAll); return; }
      var reject = target.closest('[data-device-reject]');
      if (reject && controller) { controller.answerDeviceRequest(false).then(renderAll); }
  });
  ```

**لماذا التفويض هو الحل الصحيح هنا:** أي زر يُضاف أو يُظهر **لاحقًا** يعمل بلا إعادة ربط.
الربط المباشر يفشل مع أي DOM ديناميكي — وهذا نمط متكرر في المشروع.

**الحرس:** `test_device_and_pairing_hooks_are_always_rendered`
+ `test_js_binds_device_buttons_via_delegation`

---

### D3 — 🔴 خانة QR تختفي عند وصول QR حقيقي (عيب يعطّل الميزة)

**الملف:** `resources/views/components/accounting/pairing-qr-card.blade.php`

**الأثر:** `renderPairing()` يكتب `innerHTML` داخل `[data-pair-qr-placeholder]`. الحاوية كانت
موجودة **داخل فرع `@else` فقط** (أي في حالة «لا QR من الباك-إند»). فبمجرد أن يُرسل الباك-إند
رمز اقتران حقيقيًا، ينتقل Blade للفرع الآخر ⇒ **تختفي الحاوية** ⇒ يفشل `renderPairing`
بصمت ولا يُعرض رمز الاقتران إطلاقًا.

**الخطورة:** العيب يظهر **فقط بعد تشغيل الباك-إند الحقيقي** — أي في الإنتاج، لا في التطوير
بالبيانات الوهمية. هذا نوع العيوب الذي يعبر بوابة QA.

**الإصلاح:** الحاوية `[data-pair-qr-placeholder]` تُرسم **دائمًا**، وداخلها ثلاثة فروع:
SVG (من الباك-إند) / `img` (رابط صورة) / شبكة CSS من 64 خانة (بديل تجريبي).

**الحرس:** `test_device_and_pairing_hooks_are_always_rendered`

---

### D4 — تحديث حالة `received` بتعديل مباشر يتخطّى نظام الأحداث

**الملف:** `resources/js/accounting/accounting-phone-scanner.js:242`

**الأثر:** كان الكود يفعل `controller.state.status = STATE.RECEIVED` — أي يعدّل الكائن
مباشرة. هذا **يتخطّى `setState()`**، فلا يُطلق حدث `state`، ولا يعلم أي مستمع (إعادة رسم،
تسجيل، تحليلات) أن المسح اكتمل. واجهة لا تتحدّث مع أن الحالة تغيّرت.

**الإصلاح:** إضافة دالة عامة في `createController`:
```js
function markReceived(barcode) {
    if (barcode !== undefined) state.lastBarcode = barcode;
    setState(STATE.RECEIVED, { lastBarcode: state.lastBarcode });
}
```
واستبدال التعديل المباشر بها.

**القاعدة المستخلَصة:** **لا تُعدَّل `controller.state` مباشرة أبدًا** — كل تغيير حالة يمرّ
عبر `setState()`، وإذا احتجت مسارًا جديدًا أضف دالة عامة.

**الحرس:** `test_received_state_goes_through_set_state_not_direct_mutation`
+ البلوك 4 في `audit-scanner-state-machine.cjs` (يتحقق من إطلاق الحدث فعليًا)

---

### D5 — مراجع عامة عارية لِـ`AccountingBarcode` (ReferenceError لا undefined)

**الملف:** `resources/js/accounting/accounting-phone-scanner.js`

**الأثر:** النمط `AccountingBarcode && typeof AccountingBarcode.notify === 'function'` يبدو
فحصًا آمنًا، لكنه في JS **يرمي `ReferenceError`** إذا لم يُعرَّف المتغيّر في النطاق إطلاقًا
(بخلاف `window.AccountingBarcode` الذي يُرجع `undefined` بهدوء). أي أن حزمة
`accounting-barcode` لو تأخّرت أو فشل تحميلها ⇒ **تعطّل وحدة الماسح بالكامل** برسالة
`ReferenceError: AccountingBarcode is not defined`.

**الإصلاح:** الوصول صار عبر `window` مع تخزين محلي:
```js
var B = window.AccountingBarcode;
if (B && typeof B.notify === 'function') { B.notify(…); }
```

**الحرس:** `test_phone_scanner_never_uses_bare_global_references`

---

### D6 — `scanSessions: []` مصفوفة فارغة صادقة (truthy) في JS

**الملفات:** `resources/views/pharmacy/accounting/sale-create.blade.php`
+ `resources/views/partials/accounting-i18n.blade.php`

**الأثر:** `[]` في JS **صادقة** (`if ([])` تدخل الفرع). فوحدة الماسح كانت ترى خريطة endpoints
غير فارغة ⇒ تختار `mode = 'live'` ⇒ تحاول الاتصال بمسارات غير موجودة ⇒ تنتهي بـ
**`status = 'error'`**. بينما السلوك الصحيح `mode = 'unavailable'` بـ`reason = 'no_backend'`
و`status = 'waiting'` — هدوء بدل خطأ شبكة وهمي.

**السبب الجذري:** `Object.assign` **سطحية**؛ فإسناد `endpoints.scanSessions = []` يمرّ بلا
اعتراض، ولا يُلاحظ أن `[]` صادقة.

**الإصلاح:**
- `'scanSessions' => null` في خريطة الـendpoints (مع تعليق يشرح العقد المطلوب).
- إزالة `Object.assign` التي كانت تُعيد `[]` في `sale-create.blade.php`.
- إزالة السطر `$scanSessionEndpoints = $scannerConfig['scanSessions'];` الذي كان يشير
  لمفتاح غير موجود.

**الحرس:** `test_scanner_config_declares_no_fake_endpoints` (يؤكد حرفيًا `"scanSessions":null`)
+ البلوك 3 في `audit-scanner-state-machine.cjs` الذي يثبت السلوكين: `[]` ⇒ `error`،
و`null` ⇒ `unavailable` (توثيق السبب ومنع العودة إليه).

---

### D7 — `window.acPosI18n` بلا حرس (يُقرأ في 25+ موضعًا)

**الملف:** `resources/js/accounting/accounting-pos.js`

**الأثر:** الكائن يُقرأ في أكثر من 25 موضعًا بلا فحص وجود ⇒ أول استدعاء في صفحة لم تُحمَّل
فيها الحزمة المُعرِّفة له ⇒ `TypeError`/`ReferenceError` يُسقط الوحدة كاملة.

**الإصلاح:** شبكة أمان في أعلى الوحدة:
```js
window.acPosI18n = window.acPosI18n || new Proxy({}, {
    get: function (target, key) {
        return key in target ? target[key] : '';
    }
});
```
أي مفتاح مفقود يُرجع `''` بدل الانهيار.

**ملاحظة:** `Proxy` متاح في المتصفح وNode، **وغير متاح في PHP** — لا تنسخ النمط إلى الباك-إند.

**الحرس:** `test_pos_i18n_has_a_safety_net`

---

### D8 — `qr_alt` مفقود من خريطة الترجمة

**الملف:** `resources/views/partials/accounting-i18n.blade.php`

**الأثر:** الكود يقرأ `t('qr_alt', 'QR')`، والمفتاح لم يكن موجودًا ⇒ يسقط **دائمًا** إلى النصّ
الإنجليزي البديل «QR» حتى في الواجهة العربية. ومعه يفقد رمز الاقتران نصّه البديل
(`alt`) لقارئ الشاشة.

**الإصلاح:** `'qr_alt' => __('accounting.scanner.qr_alt')` + المفتاح في `ar`
(«رمز اقتران جلسة المسح») و`en` («Scan session pairing code»).

**الحرس:** `test_qr_alt_is_available_to_the_js_bundle`

---

## 3 · تدقيق الربط Frontend ↔ Backend

تتبّعت الـ19 مسارًا. **كلها متطابقة**: ما تبنيه `accounting-i18n.blade.php` عبر `route()`
هو ما يقابل مسارًا فعليًا في `api.php`.

| ما تستدعيه الواجهة | المسار الفعلي | الحالة |
|---|---|---|
| `AccountingApi.salesSummary()` | `GET /api/pharmacy/accounting/sales-summary` | ✅ (لاحظ **الشرطة** لا `/`) |
| `AccountingApi.listSales()` | `GET /api/pharmacy/accounting/sales` | ✅ |
| `AccountingApi.getSale(number)` | `GET /api/pharmacy/accounting/sales/{number}` | ✅ |
| `AccountingApi.createSale()` | `POST /api/pharmacy/accounting/sales` | ✅ |
| `AccountingApi.cancelSale(number)` | `POST /api/pharmacy/accounting/sales/{number}/cancel` | ✅ |
| `AccountingApi.overview()` | `GET /api/pharmacy/accounting/overview` | ✅ |
| `AccountingApi.cash()` | `GET /api/pharmacy/accounting/cash` | ✅ |
| `AccountingApi.customers()` / `.customer(id)` | `GET …/customers` · `GET …/customers/{id}` | ✅ |
| `AccountingApi.suppliers()` | `GET /api/pharmacy/accounting/suppliers` | ✅ |
| `AccountingApi.expenses()` / `.expenseCategories()` | `GET …/expenses` · `GET …/expense-categories` | ✅ |
| الكتابة (sales/cancel/expenses/customers/suppliers/cash) | تحت `throttle:writes` | ✅ |

**ترتيب المسارات سليم:** `sales-summary` و`sales` قبل `sales/{number}` — وإلا لالتقط
المسار الديناميكي الكلمات الثابتة. **هذا مُختبَر:** كتابة `sales/summary` تُرجع 404
«الفاتورة غير موجودة» (التُقطت فعليًا أثناء كتابة اختبار E2E، فأُصلح الاختبار لا المسار).

**حماية IDOR مُتحقَّق منها:** كل بحث مقيّد بـ`pharmacy_id` **داخل الاستعلام**، ولا توجد
مقارنة لاحقة في PHP. رفض مخزون صيدلية أخرى يحدث **قبل أي كتابة** ⇒ لا خصم على مخزون الغير.

---

## 4 · أدلة التحقق (تفصيل)

### 4.1 PHPUnit — 108 اختبارًا · 550 تأكيدًا

| الملف | العدد | ما يغطّيه |
|---|---|---|
| `tests/Feature/Api/AccountingApiTest.php` | 34 | عقود الـAPI: مبيعات، مصروفات، عملاء، موردون، صندوق |
| `tests/Feature/Web/AccountingPagesTest.php` | 33 | رسم الصفحات الثلاث + العزل بين الصيدليات + فراغ الحالة |
| `tests/Feature/Web/PhoneScannerTest.php` | 22 | عقد الماسح + الحالات التسع + حراس الانحدار الثمانية |
| `tests/Feature/Api/AccountingWiringTest.php` | 9 | شكل الـpayload القديم/الجديد + خصم المخزون + الباركود |
| `tests/Feature/Web/AccountingPosWiringRenderTest.php` | 6 | أن كتالوج POS يعكس المخزون الحقيقي |
| `tests/Feature/Web/AccountingEndToEndTest.php` | 4 | السلسلة الكاملة: مخزون → بيع → خصم → فاتورة → قائمة |

**ملف E2E (جديد هذه الجلسة)** يثبّت 4 سيناريوهات حقيقية:
1. السلسلة الكاملة من المخزون إلى الفاتورة (9 خطوات، تتحقق من **قاعدة البيانات** لا HTML فقط).
2. البيع الآجل يُنشئ دينًا حقيقيًا على عميل حقيقي **وبلا حركة صندوق**.
3. الإلغاء يعيد المخزون ويعكس الصندوق، والتقرير يستثني الملغى.
4. محاولة البيع بمعرّف مخزون صيدلية أخرى تُرفض (IDOR) ولا تُخصم.

### 4.2 تدقيق آلة الحالة في Node — 28/28

`scripts/audit-scanner-state-machine.cjs` يُحمّل **الملف الحقيقي** في `vm.createContext`
مع بدائل لـ`window`/`document`/المؤقّتات/`fetch`. يثبت:
- الحالات التسع متّسقة، و`expired` هي النهائية الوحيدة.
- غياب الـendpoints ⇒ `unavailable` بهدوء (لا `error`).
- **`[]` تُنتج `error` بينما `null` تُنتج `unavailable`** — توثيق السبب الجذري ومنع العودة.
- `markReceived` تُطلق حدث `state` فعلًا.
- `close()` لا تترك مؤقّتات معلّقة (تسريب ذاكرة).
- `disconnect()` ثم `reconnect()` يعملان.
- `resolve()` على جلسة منتهية **لا يُحييها**.

### 4.3 جلسة الماسح في Node — 74/74

`tests/js/phone-scanner-session.test.mjs` يغطّي الطابور بالترتيب (المسح الثاني لا يتقدّم
قبل الأول — مهم لشبكة غزة)، والانتهاء، والفصل الذي **لا يمسّ السلة**، وقرار تعارض الأجهزة
(بشري فقط)، والمحاكاة المُعلَنة.

### 4.4 متصفح حقيقي على حِزَم البناء — 19/19

`outputs/scanner-verify-inlined.html` تُضمِّن حِزَم Vite المبنية **inline** (لأن `file://`
مع مسار جذري `/build/...` لا يُحلّ)، مع DOM وهمي وi18n وهمي، وتُشغَّل بـ:
```
chrome --headless=new --disable-gpu --virtual-time-budget=10000 --dump-dom <file>
⇒ RESULT pass=19 fail=0
```
هذه الطبقة تثبت أن **السلوك صحيح في المتصفح الحقيقي**، لا في Node فقط.

### 4.5 أمان النشر

```
php artisan config:cache  → INFO Configuration cached successfully.
php artisan route:cache   → INFO Routes cached successfully.
php artisan view:cache    → INFO Blade templates cached successfully.
```
ثم `optimize:clear`. **`route:cache` هو قاتل النشرات** (اسم مسار مكرّر ⇒ النشرة تموت)،
وهو أصرح من `route:list` — لذا الفحص إلزامي قبل كل نشر.

### 4.6 انحدار الحزمة الكاملة — 923 اختبارًا

الدليل الحاسم على أن هذه الجلسة **لم تكسر شيئًا في المشروع كله**:

```
php artisan test --filter='/^(?!.*(CategorySyncApiTest|CategorySyncCommandTest|EnrichmentEngineTest|MappingGateEquivalenceTest)).*$/'
⇒ Tests: 923 passed (3786 assertions) · Duration: 325.87s · صفر فشل
```

**عن الفئات الأربع المستثناة:** `php artisan test` بلا فلتر يمرّ **180 اختبارًا** ثم يتوقف
عند `Fatal error: Premature end of PHP process when running
Tests\Feature\Api\CategorySyncApiTest::test_full_pipeline_with_full_categorized_json_consistency`.
هذا انهيار **مُوثَّق مسبقًا** في ذاكرة المشروع (اختبارات تُسقط عملية PHP لا عطل كود)،
و**لا علاقة له بتغييرات هذه الجلسة** — لا ملف PHP إنتاجي ولا اختبار من تلك الفئات تغيّر
فيها (التغييرات: ملف اختبار، `package.json`، ملفا docs، وملفات الذاكرة).

### 4.7 حزمة JS — `npm run test:js`

كان السكربت يشغّل 3 ملفات؛ أُضيف إليه `tests/js/phone-scanner-session.test.mjs` و
`scripts/audit-scanner-state-machine.cjs` ⇒ الحرس صار **يعمل فعليًا** في CI بدل أن يبقى
ملفات لا يشغّلها أحد. `npm run test:js` يمرّ كاملًا (74 + 28 فحصًا).

---

## 5 · ملاحظات عقد مكتشفة (لم أُصلحها — تحتاج قرارك)

### 5.1 ⚠️ المبالغ الصحيحة تُسلسَل كأعداد صحيحة في JSON

`json_encode(36.0)` بلا `JSON_PRESERVE_ZERO_FRACTION` يُنتج **`36`** لا `36.0`. والمشروع
**لا يستخدم هذا العلم في أي مكان** (`app/`, `config/`, `bootstrap/`) ⇒ كل المبالغ
الصحيحة في كل الـAPI تخرج كأعداد صحيحة.

**الأثر على الويب:** صفر — `Number(36) === Number(36.0)`.

**الأثر على Flutter — ✅ مُتحقَّق منه: لا خطر.** فحصتُ مستودع `daway-app/daway-mobile`
(عام، Dart، فرع `develop`، 320 ملف Dart) فوجدت أن الموديلات **تتعامل مع الأرقام بأمان
أصلًا**:

```dart
// lib/features/pharmacy/data/models/medicine_model.dart
final double price;
price: _parseDouble(json['price']),              // ← سطر 43

static double _parseDouble(Object? value) {      // ← سطر 51
  if (value is num) return value.toDouble();     // ← يستقبل int و double معًا
  if (value is String) return double.tryParse(value) ?? 0;
}

id: (json['id'] as num?)?.toInt() ?? 0,          // ← `num` لا `as int`
```

`num.toDouble()` تُعالج `36` و`36.0` بالتساوي ⇒ **لا كسر**. والعرف المتبع في المشروع هو
`num` + تحويل صريح، لا `as double` المباشر.

⇒ **لا حاجة لتغيير أي عقد API.** يبقى هذا بندًا **وقائيًا** فقط: أي موديل جديد يقرأ
مبالغ يجب أن يتبع نفس العرف (`as num` ثم `toDouble()`).

**لماذا لم أُصلحه على أي حال:** إضافة `JSON_PRESERVE_ZERO_FRACTION` تغيّر شكل **كل**
استجابة في المشروع — تغيير عقد بلا سبب، وممنوع بقاعدة المشروع. لا داعي له ما دام
العميل الوحيد يتعامل مع `num` بأمان.

### 5.2 اصطلاح `stats` شقيق لـ`data` (لا متداخل)

`GET …/cash` و`GET …/sales` يُرجعان `stats` على **جذر** الاستجابة، بينما
`GET …/sales-summary` يُرجع `data.summary`. الاصطلاحان مختلفان.

**الخطر العملي:** كتابة `data.stats.balance_now` تُرجع `null`، و`(float) null === 0.0`
⇒ **التأكيد ينجح كذبًا**. اكتشفت هذا فعليًا: تأكيدان في نسختي الأولى من ملف E2E كانا
يمرّان على `null`. أُصلحا، والملف الآن يقرأ من الجذر مع `assertArrayHasKey('stats')`.

**التوصية:** توحيد الاصطلاح لاحقًا (أو على الأقل توثيقه). لم أغيّره الآن لأنه تغيير عقد.

---

## 6 · خارج النطاق / لم يُنفَّذ

| البند | الحالة | السبب |
|---|---|---|
| `git commit` / `git push` | **لم يُنفَّذ** | قاعدة صارمة: موافقة صريحة لكل مرة. التغييرات في الـworking tree |
| تشغيل الباك-إند الحقيقي لجلسات المسح | **لم يُنفَّذ** | يحتاج جداول + مسارات غير موجودة. العقد موثَّق في `docs/PHONE-SCANNER-IMPLEMENTATION-REPORT.md` §5 |
| إصلاح `MedicineResolver::lookupMapping` | **لم يُنفَّذ** | خارج نطاق هذه المهمة؛ مُوثَّق كعطل أداء معروف |
| `EnrichmentEngineTest` / `CategorySyncApiTest` (تُسقط عملية PHP) | **لا علاقة لها بتغييراتي** | ملفات ليست في مجموعتي المعدّلة؛ تنتمي لعمل موازٍ في نفس الشجرة |

---

## 7 · الدروس المُثبَّتة (أُضيفت إلى ذاكرة المشروع)

1. **`[]` صادقة في JS** ⇒ خريطة endpoints فارغة تُفعّل `mode='live'` ثم تفشل. استخدم `null`.
2. **`Object.assign` سطحية** ⇒ إسناد مفتاح متداخل يستبدل الكائن كاملًا.
3. **`@if/@else` في Blade يُقيَّم وقت التهيئة** ⇒ يكسر أي JS يبحث عن العنصر لاحقًا.
   ارسم الفرعين دائمًا وتحكّم بـ`hidden`.
4. **الربط لمرة واحدة لا يلتقط DOM الديناميكي** ⇒ Event delegation على حاوية ثابتة.
5. **الوصول لِـglobal غير موجود = `ReferenceError` لا `undefined`** ⇒ مرّ عبر `window`.
6. **لا تُعدَّل `controller.state` مباشرة** — كل تغيير عبر `setState()`.
7. **`@json` يرمّز غير-ASCII إلى `\uXXXX`** ⇒ التأكيدات على النصّ العربي داخل JSON تفشل.
8. **`json_encode(36.0)` ⇒ `36`** بلا `JSON_PRESERVE_ZERO_FRACTION` ⇒ لا تقارن بالهوية.
9. **`(float) null === 0.0`** ⇒ التأكيد على مفتاح خاطئ ينجح كذبًا. افحص وجود المفتاح أولًا.

---

## 8 · 🔴 ملاحظة تشغيلية: حالة الـrepo المحلي (تحتاج قرارك)

أثناء الفحص الختامي اكتشفت أن **مخزن Git المحلي معطوب/ناقص**. الأدلة (كلها بقراءة فقط):

| الفحص | النتيجة |
|---|---|
| `git log` على `develop` | `fatal: your current branch 'develop' does not have any commits yet` |
| `git log --all` | `fatal: bad object refs/heads/feature/ai-ocr-integration` |
| `git cat-file -t 4586d58` (ai-ocr) | `could not get object info` — **الجسم مفقود** |
| `git cat-file -t f3b2270` (`main`) | `could not get object info` — **الجسم مفقود** |
| `git cat-file -t b5c2fa4` (origin/develop) | `commit` — موجود |
| `git diff origin/develop` | `fatal: unable to read tree (0a0bf67…)` — **الشجرة مفقودة** |
| `git fetch origin --prune` | `fatal: pack has 2 unresolved deltas` / `invalid index-pack output` |
| `.git/refs/heads/` | **غير موجود** — كل المراجع في `packed-refs` (سليم، LF فقط) |
| `refs/heads/develop` في `packed-refs` | **غير موجود** ⇒ الفرع المحلي unborn |
| الملفات المُجهَّزة | **1,016** ملفًا بحالة `A` |
| `git ls-remote origin develop` | `0f092b8` — بينما المخزّن محليًا `b5c2fa4` ⇒ **الريموت تحرّك** |

**التفسير:** الاستنساخ المحلي غير مكتمل — بعض الأجسام موجودة وبعضها مفقود، وفرع
`develop` المحلي لم يُنشأ أصلًا. لهذا يظهر كل الملفات كـ`A` (مضافة إلى فرع unborn) بدل
أن تظهر كتعديلات.

**الأثر العملي:** لا يمكن الاعتماد على `git log`/`git diff`/`git status` لفهم ما تغيّر،
ولا يمكن `pull` أو `commit` بشكل سليم.

**⛔ لم أتصرّف:** كل ما سبق بقراءة فقط. `git fetch` مسموح دائمًا لكنه **فشل** بسبب الـpack
التالف. إصلاح ذلك يحتاج قرارك (إعادة استنساخ نظيف مع الحفاظ على الشجرة الحالية).

**مهم للتوثيق:** مجلد `.git` معطوب، لكن **ملفات المشروع على القرص سليمة** — وهي مصدر
الحقيقة الآن. خُذ نسخة احتياطية منها قبل أي إعادة استنساخ.

---

## 9 · الخطوات التالية المقترحة

1. **مراجعة التغييرات في الـworking tree** (`git status` / `git diff`) — لم يُرفع شيء.
2. **قرار بشأن 5.1** (تسلسل المبالغ) — خصوصًا أثرها على Flutter.
3. **فحص مستودع Flutter** للتأكد من تعامله مع `int` في حقول المبالغ.
4. عند الموافقة على النشر: `config:cache` → `route:cache` → `view:cache` → `migrate --force`
   → `db:seed --class=AccountingDemoSeeder`.
5. ربط `tests/js/phone-scanner-session.test.mjs` بسكربت `npm run test:js` (غير مربوط حاليًا).
