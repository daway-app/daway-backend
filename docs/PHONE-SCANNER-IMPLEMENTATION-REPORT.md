# المسح بالهاتف — الهاتف كقارئ باركود عن بُعد للويب
## تقرير التنفيذ + Required Backend Support

**التاريخ:** 2026-09-15 · **الفرع:** `develop` (working tree فقط — لا commit ولا push)
**المشروع:** Daway — Laravel 13 · PHP 8.4 · Blade + Vanilla JS

---

## 0 · الملخّص التنفيذي

نُفِّذت الميزة **كاملة على مستوى الواجهة** وفق المراحل الست المطلوبة. الهاتف صار
**طريقة إدخال ثالثة** بنفس وزن البحث والمسح المحلي، وكل الطرق تنتهي إلى نفس السلة
ونفس مسار البحث:

```
🔌 قارئ USB  ┐
📱 الهاتف    ├──▶ AccountingBarcode.resolveBarcode() ──▶ السلة ──▶ البيع
⌨️ البحث     ┘
```

**لم يُبنَ أي باك-إند، ولم يُختلَق أي endpoint.** كل عمليات جلسة المسح موجودة
كعقد واضح (`createScanSession / getScanSessionStatus / pairPhone / receiveBarcode /
closeScanSession`) ينقصها فقط المسارات — وهي موثَّقة في §5.

**لا WebSocket.** المشروع لا يملك أي بنية real-time (تفصيلها في §2)، فأُضيفت طبقة
نقل مجرَّدة تبدأ بـ**polling محدود (2000ms)** وتقبل `sse`/`websocket` لاحقًا بلا
تعديل أي كود واجهة.

---

## 1 · المرحلة 1 — READ-ONLY AUDIT (النتائج الفاصلة)

| السؤال | النتيجة | الأثر على القرار |
|---|---|---|
| بنية real-time | **لا توجد إطلاقًا** — لا `config/broadcasting.php`، لا `routes/channels.php`، لا Pusher/Reverb/Echo، `BROADCAST_CONNECTION=log` | polling محدود، وطبقة نقل قابلة للتبديل |
| مكتبة QR | **لا توجد** — لا في `composer.json`، ولا `package.json`، ولا `node_modules` | خانة QR تستقبل SVG/URL من الباك-إند، ولا نصنع QR بأنفسنا |
| مصادقة SANCTUM | جاهزة للمشاركة: cookie/session للويب + PAT (`createToken` → `AuthController.php:59`)؛ `EnsureFrontendRequestsAreStateful` مُسبق على مجموعة api (`bootstrap/app.php:72`) | الهاتف والويب يستطيعان نداء نفس مسارات `role:pharmacy` |
| `pharmacies` | `id` + `user_id` (unique) — **لا شيء متعلّق بالجلسات/التوكنات** | يلزم جدول جديد (لا يوجد جدول مناسب) |
| جداول توكن قصير العمر | `personal_access_tokens` (فيه `abilities` + `expires_at`) هو الأنسب؛ `otp_codes` مفتاحه `phone` لا `user` | التوثيق في §5 |
| مخزون/POS | `AccountingBarcode` هو المالك الوحيد لمنطق البحث — موجود من الجولة السابقة | الهاتف يُعيد استخدامه، لا منطق موازٍ |

**نمط polling المعتمد في المشروع:** `resources/js/offline/sync.js:42` — heartbeat 60s
مع حارس `document.hidden`. اتبعنا نفس النمط بفاصل أقصر (2s) لأن انتظار المسح لحظي.

---

## 2 · لماذا polling وليس WebSocket

`BROADCAST_CONNECTION=log` + غياب أي مشغّل أو مكتبة عميل ⇒ إضافة WebSocket الآن تعني
**بنية تحتية بلا مشغّل**: تبعيات composer + node جديدة، ومشغّل خارجي، وقناة خاصة
محمية، وبيئة تشغيل مختلفة على Render (الخطة المجانية بلا عمليات دائمة). ذلك عمل
باك-إند حقيقي ومخاطرة نشر، وليس شأن واجهة.

**الحل:** `ScanSessionTransport` — مجرَّد يقبل `polling | sse | websocket`.
- الافتراضي: `polling`.
- عند طلب `websocket` (غير منفَّذ): **يُبلّغ بصراحة** (`reason: transport_unavailable`)
  ويسقط إلى polling — لا واجهة معلّقة للأبد.
- عند إضافة مشغّل لاحقًا: `window.acAccountingConfig.scanSessionTransport = 'sse'`
  و**لا يتغيّر سطر واحد** في الشرائح.

**الفاصل محدود:** 2000ms، ويتوقف عند `document.hidden`، وعند إغلاق النافذة، وعند
انتهاء الجلسة. لا polling كل ثانية.

---

## 3 · الملفات المُنشأة / المُعدَّلة

### JS — منطق الميزة
| الملف | الدور |
|---|---|
`resources/js/accounting/accounting-scanner-session.js` | **جديد** — آلة حالة (9 حالات)، طبقة API، طبقة النقل، المحاكاة |
`resources/js/accounting/accounting-phone-scanner.js` | **جديد** — ربط آلة الحالة بالـDOM + تسليم الباركود للمحرّك المشترك |
`resources/js/accounting/accounting-barcode.js` | **جديد (هذه الجولة)** — المالك الوحيد لمنطق المسح/الربط/التعارض |
`resources/js/accounting/accounting-pos.js` | مُعدَّل — مسار مزدوج، عمود باركود، خطّاف `__acPosHook` |
`resources/js/accounting/accounting-shared.js` | كما هو — لم يُلمس (نقطة الفصل الوحيدة) |

### Blade — مكوّنات قابلة لإعادة الاستخدام
`resources/views/components/accounting/`
| المكوّن | الدور |
|---|---|
`phone-scanner-button.blade.php` | [📱 المسح بالهاتف] + نقطة «جهاز متصل» |
`phone-scanner-modal.blade.php` | النافذة الكاملة (خطوات، حالة، طوارئ، أجهزة) |
`pairing-qr-card.blade.php` | خانة QR + رمز الاقتران (بلا أي توكن) |
`scan-session-status.blade.php` | مؤشّر الحالات الثمانية (`aria-live`) |
`connected-device-card.blade.php` | الأجهزة + [سماح]/[رفض] |
`barcode-status-badge.blade.php` | شارة حالة الباركود (4 حالات) |
`barcode-input.blade.php` · `barcode-scan-button.blade.php` | حقل وزر المسح المحلي |
`barcode-link-modal.blade.php` | ربط باركود مجهول بدواء موجود |
`barcode-conflict-modal.blade.php` | التعارض — [مراجعة] [إلغاء] بلا تجاوز |
`barcode-activity-card.blade.php` | آخر نشاط الباركود |

### أخرى
- `app/Support/Accounting/BarcodeStatus.php` — **جديد** — مفردات الحالة مشتقّة من المخطط الحقيقي.
- `app/Support/Accounting/AccountingMockData.php` — أُضيفت `scanSessionEndpoints()` (تُرجع `[]` عمدًا) و `scanSessionDemoBarcodes()`.
- `resources/css/pages/pharmacy_accounting.css` — ~420 سطرًا جديدًا (أصناف `ac-`، صفر ألوان حرفية).
- `resources/lang/{ar,en}/accounting.php` — مقطعا `barcode` و `scanner`.
- `resources/views/partials/accounting-i18n.blade.php` — `acBarcodeI18n` + `acScannerI18n`.
- `vite.config.js` — مدخلات JS الثلاثة الجديدة.

---

## 4 · الاختبارات والتحقّق

| الفحص | النتيجة |
|---|---|
| `PhoneScannerTest` (PHP) | **15 نجح · 74 تأكيد** |
| `AccountingPagesTest` (PHP) | **28 نجح · 111 تأكيد** |
| `tests/Feature/Web/` كامل | **213 نجح · 861 تأكيد** |
| الحزمة الكاملة | **827 نجح · 3350 تأكيد** ✅ |
| `tests/js/phone-scanner-session.test.mjs` | **74 نجح · 0 فشل** |
| `npm run build` | ✅ نجح (2.74s) |
| التباين WCAG (8 أزواج) | **ALL PASS** (4.84:1 ← 9.21:1) |
| تحقّق بصري (حاملة الحِزَم الفعلية) | ✅ فاتح + داكن |

> **ملاحظة:** تشغيل الحزمة بلا `-d memory_limit=-1` يُسقط عملية PHP عند
> `CategorySyncApiTest::test_full_pipeline_...` — وهذا **عطل بيئي معروف موثَّق**،
> ليس انحدارًا من هذا العمل. بـ`memory_limit=-1`: 827/827.

### ما تثبّته اختبارات JS (آلة الحالة الفعلية، لا نسخة منها)
- الطابور **FIFO**: المسح الثاني لا يتقدّم قبل انتهاء الأول.
- بلا endpoints: `no_backend` + **صفر طلبات شبكة** (لا مسار مُخترَع).
- عند وجود endpoints: المسارات والـmethods الصحيحة، والباركود يُرسل **نصًّا**.
- التطبيع **يُهمل** `token` / `api_key` / `password` / `device_id` الداخلي.
- الفصل **لا يمسّ السلة** (نفس عدد الأسطر والقيم).
- الرفض لا يستبدل الجهاز النشط؛ السماح يبدّله.
- المحاكاة **معطّلة افتراضيًّا**، وموسومة `mode: 'mock'` عند تفعيلها.

### ما تثبّته اختبارات PHP
- النافذة والأزرار والمناطق الحيّة تُرسم فعلًا (لا `assertRedirect` فقط).
- **صفر تسريب**: البحث عن `personal_access_token`/`apiKey`/`password`/`secret`/`Bearer ` في HTML ⇒ لا نتيجة.
- **لا كاميرا في الويب**: لا `getUserMedia`، لا `mediaDevices`، لا `BarcodeDetector`.
- الوضع التجريبي **مُعلَن** لأن `scanSessionEndpoints()` تُرجع `[]`.
- **قارئ USB لم يُكسر**: الحقل ما زال `<input inputmode="numeric" autocomplete="off">`.
- «غير مرتبط» تُرسم بالعائلة **المحايدة** لا الحمراء.

---

## 5 · 🔴 Required Backend Support

> هذا القسم هو مخرج المهمة الأهمّ لجلسة الباك-إند القادمة.
> **كل ما يلي غير منفَّذ** — الواجهة جاهزة ومجرَّبة ضده.

### 5.1 العمليات المطلوبة (العقد الفعلي في الكود)

| # | العملية | دالة الواجهة | مُعاملات | استجابة متوقّعة |
|---|---|---|---|---|
| 1 | إنشاء جلسة مسح مؤقتة | `createScanSession()` | `{}` | `{success, data:{id, status, pairing_code, pairing_url, seconds_remaining, expires_at}}` |
| 2 | قراءة حالة الجلسة | `getScanSessionStatus(id)` | `{id}` في المسار | نفس الشكل + `device`, `devices`, `pending_device`, `barcode` |
| 3 | اقتران الهاتف | `pairPhone(id, deviceName)` | `{device_name}` | 200 = مرتبط · **409** = جهاز آخر مرتبط |
| 4 | استقبال باركود من الهاتف | `receiveBarcode(id, barcode)` | `{barcode}` (نصّ) | 200 |
| 5 | إغلاق الجلسة | `closeScanSession(id)` | `{id}` في المسار | 200 |

**الواجهة تقرأ هذه المفاتيح حرفيًّا** من `window.acAccountingConfig.endpoints.scanSessions`:
`store` · `show` · `pair` · `barcode` · `destroy` — حيث `{id}` يُستبدل.

عند تنفيذها: أرجِع المصفوفة من
`AccountingMockData::scanSessionEndpoints()` و**سيعمل كل شيء بلا تعديل JS**،
وسيختفي وسم «وضع تجريبي» تلقائيًّا.

### 5.2 المسارات المقترحة (للتوثيق فقط — غير منفَّذة)

```http
POST   /api/pharmacy/scan-sessions            → createScanSession
GET    /api/pharmacy/scan-sessions/{id}       → getScanSessionStatus  (قناة الباركود في polling)
POST   /api/pharmacy/scan-sessions/{id}/pair  → pairPhone
POST   /api/pharmacy/scan-sessions/{id}/barcode → receiveBarcode  (من الهاتف)
DELETE /api/pharmacy/scan-sessions/{id}       → closeScanSession
```
`middleware(['auth:sanctum', 'role:pharmacy'])` — نفس نمط مجموعة `sync` في
`routes/api.php:175`.

### 5.3 متطلبات الجلسة (من §2 في الطلب)

كل جلسة **يجب** أن تكون:
1. **مربوطة بـ`pharmacy_id`** — لا بـ`user_id` وحده (قد يشارك أكثر من مستخدم الحساب).
2. **مربوطة بـ`user/session`** المُنشئ.
3. **قصيرة العمر** — نقترح `expires_at` بين 2–10 دقائق. الواجهة تعرض عدّادًا محليًّا وتُبطل الجلسة عند الصفر.
4. **قابلة للإلغاء** فورًا (`DELETE`)، وإلغاء الـQR القديم عند إنشاء جلسة جديدة.
5. **لا تقبل إرسالًا من جهاز غير مُقترَن** — التحقق في الباك-إند لا في الواجهة.

### 5.4 تخزين الجلسة — شكل مقترح

`personal_access_tokens` يصلح لتوكن المسح القصير (`abilities` + `expires_at`)، لكن
**جلسة المسح كائن مختلف** عن التوكن. المقترح جدول مخصّص:

```
scan_sessions
  id                uuid (primary)      -- uuid لا تسلسل: لا تعداد للجلسات
  pharmacy_id       FK → pharmacies     -- العزل الأساسي
  created_by        FK → users          -- من أنشأها
  pairing_code      string(8) unique    -- رمز قصير للعرض
  pairing_token     string(64) hashed   -- يُخزَّن مُهشَّرًا؛ الـQR يحمل المعرّف لا السرّ
  status            enum(waiting|paired|expired|closed)
  active_device_id  string(191) null    -- الجهاز النشط الواحد
  active_device_name string(120) null   -- للعرض فقط
  scanned_barcode   string(32) null     -- آخر باركود لم يُستهلك
  expires_at        timestamp (index)   -- القصير العمر
  closed_at         timestamp null      -- للإلغاء
```

> **قرار مقصود:** `id` من نوع `uuid` لا تسلسل — يمنع تعداد جلسات الصيدليات الأخرى
> بمسح `id=1,2,3`. نفس مبدأ عدم التعداد المستخدم في مسار مستند الاستيراد
> (`routes/web.php:270`).

### 5.5 قواعد الوقاية من الإساءة
- **limiter على الإرسال**: تحديد معدّل على `POST /barcode` بمفتاح `scan_session_id`
  (لا IP — جهازان قد يتشاركان شبكة). مشروعنا يفعل ذلك في
  `App\Support\InventoryImportThrottle` — نفس النمط.
- **جلسة واحدة نشطة لكل صيدلية** (أو عدد محدود صريح) لمنع تراكم الجلسات.
- **`pending_device` تحتاج مسار قرار**: `[سماح]`/`[رفض]` في الواجهة ينتظران
  `POST /pair` (سماح) أو رفضًا يُبلّغ به الباك-إند. الرفض **لا** يُرسل شيئًا حاليًّا.

### 5.6 متطلبات الـQR

الـQR يجب أن يحمل **جلسة اقتران مؤقتة فقط**:
- ✅ صيغة مقترحة: `daway://pair?sid={uuid}` أو `https://…/pair/{uuid}`.
- ❌ **ممنوع**: كلمة مرور · API key · `personal_access_token` ·
  أي توكن مصادقة دائم · `pharmacy_id` صريح.

الواجهة تقبل أحد شكلين:
- `data.pairing_qr_svg` — SVG جاهز من الباك-إند (يُدرج كما هو)، أو
- `data.pairing_url` — تُصاغ كـ`<img>`.

**لا مكتبة QR في المشروع.** إن أردت توليد الـQR في الخادم فالأخفّ
`bacon/bacon-qr-code` (بلا تبعيات)؛ أو أرسل `pairing_url` وليولّده العميل لاحقًا.
حتى ذلك الحين تعرض الواجهة **بطاقة الرمز القصير** + ملاحظة صريحة — لا QR مزيف.

### 5.7 النقل (Transport) — التوصية

| الخيار | الحالة | التوصية |
|---|---|---|
`polling` (2000ms) | **منفَّذ ومختبَر** | ✅ ابدأ به — يعمل على الخطة المجانية بلا بنية إضافية |
`sse` | مدعوم في الطبقة | جيد إن أردت لاحقًا — دفق أحادي بلا تبعيات ثقيلة |
`websocket` | غير منفَّذ | يحتاج: مشغّل (Reverb/Pusher) + `laravel-echo` + `pusher-js` + متغيرات بيئة + قناة خاصة محمية |

**ملاحظة صدق:** الـpolling الحالي يعتمد على قراءة الحالة عبر `show`. إن أردت دفعة
حقيقية (push)، فالأخفّ هو **SSE** لأنه بلا تبعيات ويقبل نفس الشكل — ولا يحتاج تغييرًا
في الواجهة، فقط إرجاع `scanSessionTransport = 'sse'` و`endpoints.events`.

### 5.8 ما لم يُبنَ ولماذا

| لم يُبنَ | السبب |
|---|---|
مسارات جلسة المسح | لا وجود لها في المشروع — الطلب منع صراحة اختراع endpoint |
جدول `scan_sessions` | هجرة بلا باك-إند = جدول ميت |
كاميرا في الويب | قرار تصميمي مقصود: الكاميرا في الهاتف فقط |
WebSocket | لا بنية تحتية في المشروع — الطلب منع إضافتها |
كود Flutter | المشروع Laravel — لا يحتوي Flutter فعليًّا |
مكتبة QR | لا تبعية موجودة، ولا نضيف تبعية بلا إذن |
حفظ البيع في قاعدة البيانات | `createSale()` ما زالت تُرجع `no_backend` كما في الجولة السابقة |

### 5.9 فجوات إضافية (من عمل الباركود في نفس الجولة)

| الحاجة | الحالة |
|---|---|
`POST` لربط باركود بدواء | **غير موجود** — `link_modal` معطّلة بصراحة |
نقطة تغطية الباركود | **غير موجودة** — كل الأرقام تُحسب من المخزون عند توفّرها |
نقطة آخر نشاط الباركود | **غير موجودة** — تُعرض فارغة مع شرح |
`medicine_barcodes` **بلا `pharmacy_id`** | ⇒ لا يمكن نسب إضافة باركود لصيدلية، ولا الوعد بمزامنة مشتركة. الرسالة المعتمدة محايدة: «تم حفظ ربط الباركود» |

---

## 6 · المراحل — ما أُنجز

| المرحلة | الحالة |
|---|---|
**1** Audit (READ-ONLY) | ✅ — §1 |
**2** زر + نافذة + QR + حالة + جهاز | ✅ — مكوّنات + CSS + lang |
**3** دمج الباركود الوارد مع POS | ✅ — نفس المحرّك، نفس السلة، بلا POS ثانٍ |
**4** مجهول ⇒ ربط بدواء موجود | ✅ — `barcode-link-modal` |
**5** Conflict / Pending / Verified | ✅ — مفردات `BarcodeStatus` + شارات |
**6** Responsive + A11y + QA | ✅ — 4 نقاط توقّف، تباين، حاملتان بصريتان |

### §15 من الطلب — POS لم يُقسَّم
```text
1. Search medicine      ─┐
2. Keyboard/USB scanner ─┼──▶ resolveBarcode() ──▶ Cart ──▶ Sale
3. Phone remote scanner ─┘
```
لا مسار موازٍ، ولا آلة سلة ثانية. اختبار
`test_phone_scanner_and_local_scan_coexist_on_the_same_page` يثبّت ذلك.

---

## 7 · الوصولية (حرجة لقارئات USB)

- **قارئ USB = لوحة مفاتيح**: يكتب الرقم ثم يرسل `Enter` ⇒ `initScanner` يستمع
  لـ`keydown` Enter، ويمنع `submit`، ويجمّع الضغطات المتتالية (نافذة 900ms) لمنع
  المسح المزدوج.
- **`Esc`** يُغلق أي حوار باركود + النقر على الخلفية.
- **إعادة التركيز** لمصدر الفتح عند الإغلاق (WCAG 2.4.3).
- **`aria-live="polite"`** على مؤشّر الجلسة — التغيير يأتي من جهاز آخر فلا يجوز
  سرقة التركيز من حقل الإدخال.
- **`<label for>` حقيقي** لكل حقول الباركود، و`dir="ltr"` لأن الرقم لاتيني.
- الحالات الأربع في `BarcodeStatus` لها `title` مترجم يشرح المعنى.
- **التركيز البصري** عبر `--focus-ring` (توكن مُصحَّح للوضع الداكن: 5.4:1).

---

## 8 · قبل أي commit (لعبود)

```bash
git status --short          # 30 ملفًا: جديد + معدَّل
git diff --stat             # مراجعة الحجم
composer test               # أو: php vendor/bin/phpunit
node tests/js/phone-scanner-session.test.mjs
npm run build               # ضروري: أُضيفت مدخلات Vite جديدة
```

**لم يُنفَّذ:** `commit` · `push` · أي رفع · أي تغيير في Inventory أو
`MedicineResolver` أو عقود API قائمة. كل العمل في الـworking tree.
