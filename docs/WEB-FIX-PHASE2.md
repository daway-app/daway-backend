# WEB FIX — PHASE 2 + PHASE 3

**المشروع:** Daway · **التاريخ:** 2026-09-12 · **الفرع:** `develop`
**الأساس:** `docs/WEB-AUDIT-PHASE1.md` — لم يُعدَّل أي شيء قبل انتهاء الفحص.

---

## ملخص: 19 ملفاً في الـdiff (13 ملف مصدر + ملفات البناء)

```text
CRITICAL  3/3  ✅
HIGH      6/6  ✅   (FontAwesome استُثني بقرار موثّق)
MEDIUM    1/3  ✅   (2 بندين أُجّلا بقرار هندسي موثّق)
LOW       1/4  ✅   (3 بنود أُجّلت — مخاطرة > فائدة)

الاختبارات: 479 → **509 passed** / 2047 assertions  ✅
  (+3 اختبارات ترقيم · +27 اختبار smoke لكل صفحات الويب)
اختبار JS:  **16 نجح · 0 فشل** — منطق تنقّل الـService Worker (`npm run test:js`)
انحدار واحد كشفته الاختبارات وأُصلح (انظر PHASE 3)
```

---

## 1. CRITICAL — N+1 داخل حلقة العرض (البدائل)

**الملفات:** `app/Http/Controllers/web/Pharmacy/PharmacyAlternativeController.php` · `resources/views/pharmacy/alternatives/index.blade.php`

**BEFORE**
```blade
@forelse($pharmacyMedicines as $pm)
    $candidates = \App\Models\Medicine::where(...)->get();          {{-- استعلام --}}
    $stockByCandidate = \App\Models\PharmacyMedicine::whereIn(...)->get(); {{-- استعلام --}}
```
والكنترولر: `->get()` بلا حد → كل أدوية الصيدلية.

**CHANGE**
- الكنترولر: `paginate(20)` + استعلام واحد لكل المرشّحين (`$candidatesByIngredient` مُجمَّعة بالمادة الفعالة) + استعلام واحد لمخزون المرشّحين (`$stockByCandidate`).
- الإحصاءات تُحسب الآن بـSQL: `totalAlternatives` عبر join، و`needsAlternative` عبر `whereNotExists`.
- الـview يقرأ من الخريطتين بدل ما يستعلم.

**AFTER:** استعلامان مُجمَّعان للصفحة كلها بدل `2 × عدد الصفوف` **داخل حلقة العرض**.
**IMPACT — مقيس فعلياً (اختبار `test_pharmacy_alternatives_index_paginates_without_n_plus_one`):**
```text
الصفحة كلها = 10 استعلامات ثابتة (ترقيم + استعلامان مجمّعان + إحصاءان + eager loads + auth)
لا تتغيّر مهما زاد عدد أدوية الصيدلية — 20 صفاً/صفحة
قبل الإصلاح: استعلامان لكل صف معروض (≈40 لـ20 صفاً) + كل الصفوف في DOM واحد
```
**REGRESSION:** منطق المرشّحين نفسه (نفس المادة الفعالة، استثناء الدواء الحالي، مادة فارغة = لا مرشّحين). الإحصاءات على كامل المجموعة كالسابق. **التغيير الوحيد المقصود:** إضافة ترقيم (20/صفحة) — قبلها كانت كل الصفوف في صفحة واحدة.
**ملاحظة:** حُذف `$availableAlternatives` — كان يُحسب في الكنترولر ولا يُستخدم في الـview إطلاقاً (مُتحقَّق بـgrep).

---

## 2. CRITICAL — علاقة غير محدودة: `pharmacies/show`

**الملفات:** `PharmacyController.php` · `pharmacies/show.blade.php`

**BEFORE:** `Pharmacy::with('user','pharmacyMedicines.medicine')` يحمّل كل المخزون، والـview يعرضه كاملاً، والعدّاد من المجموعة.

**CHANGE:** عدّ على مستوى SQL (`count()` و`where('quantity','>',0)->count()`) + `paginate(20)` للجدول.

**AFTER:** 20 صفاً في الـDOM بدل آلاف.
**REGRESSION:** العدّاد في العنوان صار `$pharmacyMedicines->total()` — نفس الرقم السابق.

---

## 3. CRITICAL — علاقة غير محدودة: `medicines/show`

**الملفات:** `MedicineController.php` · `medicines/show.blade.php`

**BEFORE:** `with(['alternatives','pharmacyMedicines.pharmacy'])` — كل الصيدليات.
**CHANGE:** `paginate(20)` + `alternatives` كما هي (علاقة صغيرة).
**REGRESSION:** العدّادان (أعلى الصفحة + عنوان القسم) صارا `->total()` — نفس الأرقام.

---

## 4. HIGH — الإشعارات بلا حد

**الملفات:** `Admin/NotificationController.php` · `notifications/index.blade.php`

**BEFORE:** `$user->notifications()->get()->each(...)` — كل الإشعارات في الـDOM.
**CHANGE:** `paginate(20)`، وحساب الرابط على مجموعة الصفحة فقط. فرع `catch` يعيد `LengthAwarePaginator` فارغاً حتى يبقى الـview متوافقاً.
**REGRESSION:** الـview يستخدم `method_exists($notifications,'hasPages')` — آمن في كل الحالات.

---

## 5. HIGH — `COUNT(*)` على كل صفحة

**الملف:** `resources/views/components/sidebar.blade.php:64`

**BEFORE:** `{{ \App\Models\User::count() }}` — استعلام في كل طلب لكل أدمن.
**CHANGE:** `Cache::remember('nav_users_count', 60, ...)`.
**AFTER:** استعلام واحد كحد أقصى كل دقيقة مهما كان عدد الطلبات.
**REGRESSION:** العدّاد قد يتأخر 60 ثانية — معلومة عرضية فقط.

---

## 6. HIGH — انفجار الـprefetch بعد الدخول

**الملف:** `resources/views/dashboard/index.blade.php`

**BEFORE:** 6 صفحات كاملة + API تُطلب تلقائياً بعد الدخول (كل 400ms، ~2.4s) — كل واحدة render كامل + استعلامات.
**CHANGE:** التسخين يحدث عند `mouseenter`/`focus` على روابط التنقّل، مع منع التكرار.
**AFTER:** **صفر طلبات تلقائية.** التسخين ما زال يحدث عند إبداء نية الانتقال.
**REGRESSION:** أول انتقال قد يكون أبطأ قليلاً لو المستخدم لم يمرّر المؤشر إطلاقاً.

---

## 7. HIGH — نبضة المزامنة على كل صفحة

**الملفات:** `resources/js/offline/index.js` · `resources/js/offline/sync.js`

**BEFORE:** `/healthz` + `/api/sync/pull` كل **30 ثانية** على **كل** صفحة (لأن `offline/index.js` محمَّل عالمياً في `layouts/app.blade.php:48`).
**CHANGE:** `sync.init()` تُشغَّل فقط داخل `/pharmacy` أو `/profile` (نفس نطاق `OFFLINE_NAV_PREFIXES` في `sw.js`)، والفترة **30s → 60s**.

**AFTER**
```text
صفحات الأدمن:  0 طلب polling
داخل النطاق:   2 طلب / 60 ثانية = 120 طلب/ساعة/تاب   (كان 240)
8 ساعات × 3 تبويبات: 2,880 طلب   (كان 5,760)
استعلامات DB من /healthz: 1,440   (كان 2,880)
```
**REGRESSION:** صفحات الأدمن لم تعد تُحدِّث حالة «متصل/غير متصل» — لا تحتاجها أصلاً (لا نماذج offline فيها). صفحات الصيدلية والملف الشخصي غير متأثرة. حاجز `document.hidden` ما زال قائماً.

---

## 8. HIGH — مهلة الـService Worker (الأخطر)

**الملف:** `public/sw.js`

**BEFORE**
```js
const timer = setTimeout(() => controller.abort(), 3000);   // يلغي الطلب
...
} catch (e) { const cached = await caches.match(request); return cached; }  // كاش قديم
```
الطلب **يُلغى** → `cache.put` لا يُنفَّذ أبداً → الكاش يبقى قديماً للأبد كلما كان التحميل بطيئاً.

**CHANGE:** الطلب **لا يُلغى إطلاقاً**. سباق مع مهلة:
- يوجد كاش → **1200ms** للشبكة، ثم الكاش فوراً (والطلب يكمل في الخلفية ويُحدِّث الكاش).
- لا يوجد كاش → **8000ms** (تغطي cold start على Render).
- الترتيب: طازج → كاش → `/offline`.

**AFTER:** stale-while-revalidate حقيقي — الكاش يُحدَّث دائماً في الخلفية.
**REGRESSION:** لا شيء على الـoffline: ما زال يخدم الكاش عند الانقطاع، و`/offline` احتياطي، و`?page=` ما زال network-only بلا كاش، و`/api/*` ما زال غير ملموس.

---

## 9. HIGH — الخطوط الخارجية الحاجبة

**الملفات:** `vite.config.js` · `layouts/app.blade.php` · `auth/login.blade.php`

**BEFORE:** Tajawal من `fonts.googleapis.com` على كل صفحة (+2 preconnect).
**CHANGE:** `bunny('Tajawal', { weights: [400,500,700,800] })` — استضافة ذاتية مثل Cairo، وحُذف الرابط الخارجي.

**AFTER (مُتحقَّق من الـbuild):**
```text
8 ملفات Tajawal (4 أوزان × woff/woff2)
fonts-manifest.json → families: ['cairo', 'tajawal']
fonts-B7qq6EhU.css → 5.81 kB
```
**REGRESSION:** لا شيء — نفس العائلة ونفس الأوزان. أفضل للـoffline أيضاً (الخطوط صارت ضمن أصول الـbuild التي يخزّنها الـSW).

**تصحيح مهم:** تقرير Phase 1 قال إن Cairo غير مستخدم — **هذا خطأ**. Cairo هو خط لوحة الصيدلية الأساسي (`pharmacy_dashboard.css`). صُحّح التقرير.

---

## 10. LOW — تشويه UTF-16

**الملف:** `resources/views/dashboard/index.blade.php`

**BEFORE:** 51 بايت UTF-16LE (`< ! - - F o r c e   r e b u i l d`) ملحقة بعد `@endsection` — `grep` كان يصنّف الملف binary.
**CHANGE:** قُصّت البايتات الزائدة؛ الملف الآن UTF-8 صالح.
**REGRESSION:** لا شيء — كان تعليقاً شاردة.

---

## 11. MEDIUM — أصول البناء غير مستثناة

**الملف:** `.gitignore` → أُضيف `/public/build`

**دليل السلامة:** الـDockerfile **يبني الأصول بنفسه** (مرحلة frontend: `npm ci` + `npm run build`) وينسخها — يعني الأصول المرفوعة زائدة أصلاً. لا خطر على النشر.
**⚠️ خطوة ناقصة (بقرارك أنت):** `public/build` **متتبَّع حالياً**، فقاعدة `.gitignore` وحدها لا تكفي. لإتمامها:
```bash
git rm -r --cached public/build
```
لم أُنفّذها — قرار مستودع يخصّك.

---

## المؤجَّل — ولماذا (بقرار هندسي، لا نسيان)

| البند | السبب |
|---|---|
| **CSS داخلي** (`pharmacies/index` 316 سطر، `profile/edit` 187) | نقل CSS داخلي إلى ملف خارجي يقايض بايتات HTML بـ**رحلة شبكة إضافية**. جمهورنا على اتصالات بطيئة/موبايل في غزة — الـCSS الداخلي يُرسم من أول طلب بلا انتظار. **المقايضة ليست رابحة** → أُعيد تصنيفه إلى «أبقِه» |
| **FontAwesome من cdnjs** | يحتاج قرار استضافة ذاتية (تبعية جديدة). لم أخاطر بـFOUT للأيقونات |
| **تبويبات الإعدادات (4 تُرسم، 3 مخفية)** | رسم التبويب النشط فقط **تغيير سلوك** يحتاج قرار UX/توجيه |
| **`Medicine::all()` في قائمتي الاختيار** | الإصلاح الحقيقي = endpoint بحث عن بُعد (**ميزة جديدة**) لا تحسين أداء |
| **`LogsExport` يحمّل كل الصفوف** | `LogsExport implements FromCollection` — التحويل إلى cursor يستلزم إعادة كتابة كلاس التصدير. خطر كسر التصدير > الفائدة |
| **تكرار JS قص الصورة** | صيانة فقط |
| **`<select>`/`<input>` ميتة في `logs/index`** | تجميلي |

---

## PHASE 3 — التحقق

| الفحص | النتيجة |
|---|---|
| `php -l` على كل ملف PHP/Blade معدَّل | ✅ `No syntax errors detected` ×12 |
| `php artisan view:cache` (يترجم كل الـviews) | ✅ `Blade templates cached successfully` (40 view) |
| `npm run build` | ✅ `✓ built in 10.75s` |
| `node --check` على `sw.js` و`sync.js` و`index.js` | ✅ سليم |
| `npm run test:js` — منطق تنقّل الـService Worker | ✅ **16 نجح · 0 فشل** |
| خطوط Tajawal مُولَّدة | ✅ 8 ملفات + manifest فيه cairo+tajawal |
| `php artisan test` | ✅ **509 passed · 2047 assertions** · 180s (479 قائم + 3 ترقيم + 27 smoke) |
| `git diff --stat` | 19 ملفاً (منها 2 من مخرجات البناء) · +314 / −89 · + ملفَّي اختبار جديدين |

### 🆕 اختبارات تغطية أُضيفت — `tests/Feature/Web/AdminShowPaginationTest.php`

عند التحقق من التغطية اكتشفت أن **`pharmacies.show` و`medicines.show` بلا أي اختبار** —
أي أن نجاح السويت لم يكن دليلاً على سلامة هذين التعديلين. فكتبت 3 اختبارات:

```text
✓ pharmacy show paginates inventory and reports full total
✓ medicine show paginates pharmacies and reports full total
✓ pharmacy alternatives index paginates without n plus one
```

تُثبّت: العدّاد الكلي (25) لا عدد الصفحة (20) · الصفحة الأولى تعرض **20 صفاً بالضبط**
(تُعدّ فعلياً في HTML) · الصفحة الثانية تعرض البقية · عدد الاستعلامات في صفحة البدائل
**10 ثابتة** ومستقلة عن حجم البيانات.

**هذا يسدّ الفجوة:** قبلها كان «479 نجحوا» لا يعني شيئاً عن الصفحتين المعدَّلتين.

### 🆕 ملف اختبار ثانٍ — `tests/Feature/Web/WebSmokeRegressionTest.php`

يغطي **كل** صفحات Admin وPharmacy + الصفحات العامة (انظر قسم REGRESSION CHECK أدناه).

### ⚠️ انحدار كشفته الاختبارات — وأُصلح

أول تشغيل للسويت أعطى **5 failed / 474 passed**:

```text
Call to a member function count() on int
(View: resources/views/pharmacy/alternatives/index.blade.php)
```

**السبب:** غيّرت `$needsAlternative` من `Collection` (كان يُحسب في الـview) إلى `int`
(يُحسب بـSQL في الكنترولر) — لكن الـview كان ما زال ينادي `$needsAlternative->count()`.

**الإصلاح:** `{{ $needsAlternative }}` بدل `{{ $needsAlternative->count() }}`.
**التحقق:** `--filter=PharmacyAlternativesPageTest` → 13/13 ✅ ثم السويت الكامل → 479/479 ✅.

**درس:** المشروع عنده **479 اختباراً حقيقياً** — و`AGENTS.md` يقول «skeleton tests فقط» وهي
معلومة **قديمة**. هذا الانحدار ما كان لينكشف بدون تشغيل السويت. **شغّل الاختبارات بعد كل تعديل.**

### 🔁 قائمة REGRESSION CHECK — صارت آلية بدل يدوية

`tests/Feature/Web/WebSmokeRegressionTest.php` يلمس **كل** صفحة ويؤكّد أنها تُبنى وتُخدَم
بـHTTP 200 (أي خطأ 500 من Blade/PHP/استعلام يُفشل الاختبار):

```text
Admin (15):     /  ·  users  ·  users/create  ·  medicines  ·  medicines/create  ·
                pharmacies  ·  pharmacies/create  ·  patients  ·  categories  ·
                categories/create  ·  inventory  ·  logs  ·  settings  ·
                notifications  ·  profile

Pharmacy (9):   pharmacy/dashboard  ·  pharmacy/inventory  ·  pharmacy/medicines  ·
                pharmacy/medicines/create  ·  pharmacy/inquiries  ·
                pharmacy/alternatives  ·  pharmacy/alternatives/create  ·
                pharmacy/ratings  ·  pharmacy/profile

عامة:           /login  ·  /healthz  ·  /offline
إضافي:          pharmacies/{id} ببيانات حقيقية · medicines/{id} ببيانات حقيقية · تصدير Excel

النتيجة: 27 passed · 56 assertions · 17s
```

### 🆕 اختبار منطق الـService Worker — بدون متصفح

`tests/js/sw-navigation.test.mjs` — يُشغَّل بـ **`npm run test:js`**
(يُحمّل `public/sw.js` داخل `vm` sandbox مع بدائل لـ`self`/`caches`/`fetch`،
ثم يستدعي معالج حدث `fetch` مباشرةً). **النتيجة: 16 نجح · 0 فشل.**

```text
1) شبكة سريعة + كاش        → طازج + تحديث الكاش
2) شبكة بطيئة + كاش        → كاش فوراً (<1600ms) + الكاش يُحدَّث في الخلفية  ← الإصلاح الأساسي
3) شبكة بطيئة + بلا كاش    → ينتظر الشبكة (لا يعرض /offline خطأً)
4) شبكة فاشلة + كاش        → كاش
5) شبكة فاشلة + بلا كاش    → صفحة /offline
6) تنقّل ?page=            → network-only · cache:'no-store' · الكاش لا يُستبدل
7) صفحة أدمن               → الـSW لا يعترض التنقّل
8) /api/* و POST           → لا يُمَسّان
```

**الحالة 2 هي جوهر الإصلاح:** تُثبت أن الكاش يُحدَّث خلفياً **حتى عندما يُخدَم الكاش أولاً** —
وهو ما كان مستحيلاً قبل الإصلاح (الطلب المُلغى لا يُحدِّث الكاش أبداً).

**ما زال يدوياً (بصراحة):** التجربة الحيّة على جهاز وشبكة حقيقيين (قطع الشبكة يدوياً،
إعادة الاتصال، تسجيل الدخول، FCM)، وأي قياس زمن حقيقي (Lighthouse/RUM).

**ولا توجد قياسات متصفح حقيقية.** الأدلة المتوفرة: عدد الاستعلامات (مقيس = 10) ·
عدد الطلبات الدورية (من الكود) · البناء · 509 اختبار PHP · 16 اختبار JS. لا أرقام مُختلقة.

---

## FINAL VERDICT

```text
MAIN BOTTLENECK  →  ✅ مُصلَح
  Database + rendering: N+1 (2 استعلام/صف) + علاقات غير محدودة
  → استعلامان للصفحة + ترقيم 20/صفحة في 3 شاشات

SECONDARY        →  ✅ مُصلَح
  Network volume: polling كل 30s على كل صفحة + prefetch 7 طلبات
  → 0 على صفحات الأدمن · 120 طلب/ساعة داخل النطاق (كان 240) · 0 prefetch تلقائي

THIRD            →  ✅ مُصلَح
  Service Worker 3s timeout + كاش لا يُحدَّث
  → 1200ms مع الكاش + تحديث خلفي دائماً + 8s عند غياب الكاش

لم يكن يوماً عنقاً: حجم الـbundle (أكبر JS = 27KB)
```

**لا أكتب «الأداء تحسّن» كرقم.** الأدلة المتوفرة: عدد الاستعلامات انخفض من `2N+2` إلى `2`، عدد الطلبات الدورية من 240 إلى 120/ساعة/تاب (و0 على الأدمن)، وطلب خطوط خارجي حاجب أُزيل. **التحقق النهائي من الإحساس الفعلي يحتاج قياس متصفح — وهو غير متوفر لي.**

---

# FINAL PRE-MERGE FIXES (R1–R5)

مراجعة الـdiff كشفت 5 نقاط. نُفِّذت كلها.

## R1 — انحدار المزامنة: العتبة مقابل فترة النبضة

**التحليل (قبل التعديل).** نقاط تحديث `lastCheckAt`: نجاح `/healthz` · فشلان متتاليان ·
حدث `offline` · `init` عند عدم الاتصال. مهلة النبضة `5000ms`، الفترة `60000ms`.

```text
العتبة القديمة 20000 مع فترة 60000  →  فجوة «مجهول» = 40000ms من كل دورة
مسار «مجهول» في shouldQueue() = probeOnce() ثم probeOnce()  (كل واحدة بمهلة 4000ms)
→ تأخير حفظ محتمل حتى ~8 ثوانٍ، في ثلثي الوقت، على نماذج الصيدلية
```

**الاشتقاق (لا رقم اعتباطي).** العتبة يجب أن تغطي دورة نبضة كاملة حتى تصل القراءة التالية:

```text
60000 (الفترة) + 5000 (مهلة الطلب) + 10000 (هامش) = 75000ms
```

**التغيير:** `resources/js/offline/sync.js` → `20000` → `75000` مع تعليق يشرح الاشتقاق.
**لم يُحذف** الفحص المزدوج — هو سلوك مقصود («فشل متقطع واحد لا يخفي حفظاً طبيعياً»).

**التحقق:** `tests/js/sync-reachability.test.mjs` — **18 نجح · 0 فشل**

```text
✓ العتبة تغطي أسوأ حالة طبيعية (فترة + مهلة) · وتنتهي بعدها
✓ بالعتبة الجديدة: قراءة عمرها دورة كاملة صالحة (بالقديمة كانت «مجهولة»)
✓ offline (onLine=false) → queue بلا أي فحص شبكة
✓ قراءة طازجة + متاح → submit بلا أي فحص
✓ قراءة طازجة + غير متاح → فحص واحد فقط (لا double probe)
✓ منتهية + فشل أول → فحصان (مقصود) · منتهية + نجاح أول → فحص واحد
✓ الأهم: لا double probe أثناء الدورة الطبيعية (صفر فحوصات)
✓ probeOnce محدود بـ~4000ms عند تعليق الشبكة
```

## R2 — صلابة الـService Worker

`caches.match` صار **محميّاً بـtry/catch** في الموضعين (قراءة الصفحة المخزّنة + قراءة
`/offline`). عند فشل الـCache API: نكمل إلى الشبكة، وإن فشلت أيضًا نسقط على `/offline`
أو استجابة 503 نصية. **لم تتغيّر الاستراتيجية** (1200/8000ms، لا إلغاء للطلب).

**التحقق:** حالتان جديدتان في `sw-navigation.test.mjs` → **20 نجح · 0 فشل**

```text
✓ خطأ Cache + شبكة سليمة → التنقّل يكمل بالمحتوى الطازج (لا رفض)
✓ خطأ Cache + فشل الشبكة → استجابة 503 (لا رفض)
✓ وكل الحالات العشر السابقة ما زالت ناجحة
```

## R3 — خط صفحة الـoffline

**القرار: إزالة الرابط الخارجي، والاعتماد على `system-ui` المُعلَن أصلاً.**

السبب: `offline.blade.php` صفحة standalone بلا `@vite`، ومُخزَّنة مسبقاً بالـSW،
و`<link rel="stylesheet">` **حاجب للعرض**. وهي الصفحة التي يجب أن تظهر فوراً عند الانقطاع —
والطلب الخارجي **يفشل دائماً offline** فيؤخّر الظهور بلا أي فائدة، وينتهي أصلاً بـ`system-ui`.

**لا انحدار بصري offline** (النتيجة النهائية هي نفسها). الخطوط الذاتية لا تنفع هنا لأن
أسماء ملفاتها مُجزَّأة/hashed وتتغيّر مع كل build. **لا تبعية جديدة، ولا `@vite`.**

## R4 — اتساق المستودع: الاختيار مبني على دليل

```text
Selected Option: B — الإبقاء على public/build متتبَّعاً + إرجاع قاعدة ignore

Reason:
  1) vite.config.js يضبط emptyOutDir:false عمداً وتعليقه يشرح السبب: صفحات كاش الـSW
     تشير لحمولات hash قديمة، وحذفها يكسر JS في الصفحات المخزّنة. إبقاء مخرجات البناء
     القديمة متاحة قرار تصميمي مقصود، لا إهمال.
  2) Dockerfile: مرحلة frontend تعمل COPY public ./public ثم npm run build → الأصول
     القديمة المتتبَّعة تُنسخ للحاوية ويُبنى فوقها. إلغاء التتبّع يُفقدها من النسخة
     النظيفة = نفس العطل الذي يحذّر منه تعليق vite.
  3) CI لا يبني الأصول إطلاقاً لكنه يشغّل اختبارات ترسم Blade يستخدم @vite → يحتاج
     manifest.json موجوداً في المستودع، وإلا ViteManifestNotFoundException.

Docker build evidence : Dockerfile س1-9 (npm ci + npm run build) · س50 COPY --from=frontend
CI evidence           : .github/workflows/laravel.yml — checkout→setup-php→composer→
                        key:generate→artisan test (بلا أي npm/vite/build)
Render safety         : render.yaml runtime: docker → الأصول تُبنى وقت الصورة،
                        والأصول القديمة المتتبَّعة تبقى داخلها
```

Option A كان سيتطلّب إضافة خطوة بناء للـCI (تغيير أكبر) **وما كان ليعالج** ضمانة
الكاش القديم. لذلك B هو الصحيح.

**التنفيذ:** إرجاع `/public/build` من `.gitignore` + تتبّع الـ12 ملفاً التي يشير إليها
الـmanifest ولم تكن متتبَّعة (8 خطوط Tajawal + ملفَّي assets + manifest المُحدَّثان).

**التحقق الآلي:** `يشير لها الـmanifest: 46 · متتبَّع: 46 · ناقص: 0` ✅

## R5 — `.workbuddy-ai/`

أُضيف `/.workbuddy-ai/` إلى `.gitignore`. يحتوي ملف ذاكرة محلي واحد لا يخصّ الفريق.
**التحقق:** لم يعد يظهر في `git status --untracked-files=all` ✅

---

## نتائج التحقق النهائية (بالترتيب المطلوب)

| الخطوة | النتيجة |
|---|---|
| `npm run test:js` | ✅ **38 نجح · 0 فشل** (20 SW + 18 قرار الحفظ) |
| `php artisan test` | ✅ **509 passed · 2047 assertions** |
| `npm run build` | ✅ `✓ built in 5.29s` |
| `php -l` (13 ملف) | ✅ `No syntax errors` ×13 |
| اتساق `public/build` | ✅ 46/46 متتبَّع · 0 ناقص |
| `git status` | ✅ `.workbuddy-ai/` غير ظاهر |
| `git diff --check` | ✅ نظيف |
| `git diff --stat` | 32 ملف · +526/−93 |

```text
Laravel tests        : PASS (509)
JS SW tests          : PASS (20)
JS sync decision     : PASS (18)
Web Smoke            : 27/27 PASS
Build                : PASS
PHP lint             : PASS
Repo consistency     : PASS (46/46)
```

**جاهز للدمج** — بشرط قرارك على `docs/*.md` (تقارير المراجعة، غير متتبَّعة ومقصودة).
