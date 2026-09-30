# P3 PERFORMANCE FIXES REPORT

> النطاق: الإصلاحات الأربعة التي **أثبتها** تقرير `P3 AJAX FILTER AUDIT` فقط.
> لا AJAX · لا `filter-ajax.js` · لا pushState · لا Blade filter redesign · لا migrations/فهارس جديدة.
> HEAD عند البدء والانتهاء: `400f4f7` — لا commit، لا push.

قياس الزمن كله **من جهة السيرفر** (`EXPLAIN ANALYZE`) لأن زمن الرحلة ذهابًا وإيابًا إلى Aiven
≈ **181 ms** ويجعل قياس wall-clock من جهة العميل بلا معنى.

---

## 1) Changes

### FIX 1 — Admin Logs N+1

| البند | التفصيل |
|---|---|
| **File** | `app/Http/Controllers/web/Admin/LogController.php` |
| **Problem** | `Activity::with('causer')` بينما `resources/views/logs/index.blade.php:68` يستخدم `$log->subject` ⇒ تحميل كسول لعلاقة `morphTo` **لكل صف** (حتى 50 استعلامًا إضافيًا في الصفحة) |
| **Edit** | `Activity::with(['causer', 'subject'])` — سطر واحد |
| **Before** | 53 استعلامًا (baseline) |
| **After** | 8 استعلامات |
| **Equivalence proven?** | **نعم** — نفس البيانات المرسومة. `MorphTo::buildDictionary()` (vendor `Relations/MorphTo.php:113`) يتجاهل الصفوف التي نوعها morph فارغ (`if ($model->{$this->morphType})`)، لذا الـ177 صفًا ذات `causer_type` فارغ آمنة. `Relation::morphMap` مُسجَّل في `AppServiceProvider.php:64` وكل الأنواع قابلة للحل |

**لماذا 8 وليس ~4:** الاستعلامات الثمانية = 2 للـ`paginate` (count + select) + **6 استعلامات eager لـ6 أنواع morph مختلفة** موجودة فعليًا في الـ50 صفًا المرسومة:
`users` (causer) · `pharmacies` · `medicines` · `users` · `patient_inquiries` · `pharmacy_medicines`.
الـ**50 استعلامًا الكسولة اختفت بالكامل** — المتبقّي هو الحد الأدنى البنيوي لعدد الأنواع.

---

### FIX 2 — Admin Logs date filter (sargability)

| البند | التفصيل |
|---|---|
| **File** | `app/Http/Controllers/web/Admin/LogController.php` |
| **Problem** | `whereDate('created_at', $date)` تُغلّف العمود بـ`date()` ⇒ **غير قابل للفهرسة** (non-sargable) |
| **Edit** | مقارنة نطاق `>= startOfDay` و `< startOfDay()->addDay()` — نفس دلالة «اليوم كاملًا» بما فيه الكسور الثانية. مع مسار احتياطي حرفي (`whereDate`) لتاريخ مطابق للـregex لكن غير صالح تقويميًا (`2026-13-45`، `2026-02-30`) |
| **Before** | `type=index` — مسح فهرس كامل + `cast(created_at as date)` على كل مُدخل |
| **After** | `type=range` — مسح نطاق دقيق على الفهرس، تقدير `rows=1` |
| **Equivalence proven?** | **نعم** — 8/8 حالات تاريخ متطابقة (`identical=YES`)؛ والحالتان `2026-02-30` و`2026-13-45` تسقطان للمسار الأصلي حرفيًا |

> ⚠️ **بصراحة:** عند حجم الجدول الحالي (189 صفًا) يختار المحسّن `type=ALL` **لكلا** الاستعلامين،
> فالفرق المرصود ≈ 0 (0.141 ms مقابل 0.112 ms). الفرق **بنيوي** ومُثبَت بـ`FORCE INDEX`
> (قراءة فقط)، والفائدة تظهر لاحقًا مع نمو `activity_log`.

---

### FIX 3 — Pharmacy Ratings aggregation

| البند | التفصيل |
|---|---|
| **File** | `app/Http/Controllers/web/Pharmacy/PharmacyRatingController.php` |
| **Problem** | 5 استعلامات `count()` داخل حلقة (نجمة نجمة) + 6 استعلامات `avg()` داخل حلقة (شهر شهر) = **11 استعلامًا زائدًا** |
| **Edit** | النجمة: `selectRaw('stars_rating, COUNT(*) as c')->groupBy('stars_rating')` واحد. الأشهر: **تجميع شرطي واحد** — `SUM(CASE WHEN created_at >= ? AND < ? THEN stars_rating END)` + `COUNT(CASE …)` لكل نافذة شهرية، مع نطاق خارجي يحدّ الصفوف الممسوحة |
| **Before** | 15 استعلامًا |
| **After** | 5 استعلامات |
| **Equivalence proven?** | **نعم — على MySQL وSQLite معًا** (انظر §Tests). الاتجاه `[5,2,3,3,4,4]` متطابق بـ`===`، والتوزيع متطابق (total=11، والنسب لكل نجمة)، والحالات الحدّية مُعالَجة صحيحًا: `2026-03-31 23:59:59` و`2025-09-30` و`2026-10-01` **مستثناة**، و`2026-04-01 00:00:00` **مُدرَجة**، و`2026-06-30 23:59:59`→يونيو، و`2026-07-01 00:00:00`→يوليو |

**🔴 انحدار أُمسِك وأُصلح أثناء التنفيذ (مهم):**
النسخة الأولى استخدمت `GROUP BY YEAR(created_at), MONTH(created_at)`. هذه دوال **خاصة بـMySQL**،
ومجموعة الاختبار تعمل على **SQLite** (`phpunit.xml`) ⇒ `no such function: YEAR` ⇒ صفحة
`/pharmacy/ratings` ترجع **500**، وسقط الاختبار `WebSmokeRegressionTest::test_pharmacy_page_renders_without_server_error`
(345 اختبارًا: 1 فشل). أُعيدت الكتابة إلى **تجميع شرطي بمدى تاريخ** — معياري على المحرّكين،
وقابل لاستخدام فهرس `created_at`، وبنفس الدلالة تمامًا.

**حفظ المتطلبات:** نفس ترتيب الأشهر (الأقدم→الأحدث)، نفس معالجة الأشهر بلا بيانات (`0` عدد صحيح لا `0.0`)، نفس أسماء متغيرات الـBlade (`$averageRating` · `$totalRatings` · `$trendLabels` · `$trendData` · `$distribution`)، نفس النطاق/التفويض (`Auth::user()->id` → `Pharmacy::where('user_id', …)`). لم يُمَس `averageRating` ولا `$ratings` paginate.

---

### FIX 4 — `categories.show?q=`

| البند | التفصيل |
|---|---|
| **File** | `app/Http/Controllers/web/Admin/CategoryController.php` |
| **Problem** | `whereHas('mohProduct'\|'mohDrug'\|'medicine')` ⇒ ثلاث `EXISTS` **مرتبطة** تُنفَّذ **9828 مرة** (مرة لكل صف من روابط القسم رقم 5 = 9866 رابطًا)، وكل تقييم يمسح `moh_medicines` كاملًا (17295 صفًا) |
| **Edit** | ثلاث `whereIn(<subquery>)` **غير مرتبطة** على أعمدة المفاتيح المفهرسة: `moh_product_id` / `moh_drug_id` / `medicine_id`. تُنفَّذ مرة واحدة (`Materialize with deduplication`) |
| **Before** | 131–180 ms (استعلام العدّ، EXPLAIN ANALYZE، أدنى 3 قياسات: 134 / 131 / 131) |
| **After** | 63.7–76.2 ms (أدنى 3 قياسات: 63.7 / 68.7 / 76.2) — **~2× أسرع** |
| **Equivalence proven?** | **نعم** — **15 حالة عبر الكود الحقيقي للـcontroller** (تطابق تام في مصفوفة الـIDs المرتّبة + تطابق `total()`) |

**مصفوفة الإثبات (كلها `SAME`):** `q=''` · `para` · `amox` · `pa` (ص1 وص3) · `al` · `zzzznope` · عربي `زيت` · `vitamin` · `review=1` · `source=rules` · مجمّعة (q+review+source) · `q` بحرف واحد (البوّابة `mb_strlen >= 2` تتخطاه) · `page=9999` · وأقسام أخرى (cat 1: 5130 رابطًا، cat 3: 6، cat 9: 447). الـSQL المُولَّد نظيف بـ**6 bindings** بترتيب صحيح.

**❌ بديل الـAudit — مُقاسة ومرفوضة (مهم):**
اقترح الـAudit **استخراج الـIDs في PHP** ثم `whereIn(array)`. طُبِّق وقيس فعليًا فتبيّن أنه **أبطأ**:
| q | OLD (correlated) | NEW-A (استخراج IDs) | NEW-B (IN-subquery، المُطبَّق) |
|---|---|---|---|
| `para` | 626.7 ms | 818.6 ms | **506.5 ms** |
| `pa` | 557.3 ms | **1446.7 ms** | **468.7 ms** |
| `zzzznope` | 278.2 ms | 536.4 ms | **226.2 ms** |

السببان: (1) استعلاما استخراج إضافيان = **رحلتان إضافيتان** (~181 ms لكل واحدة)؛ (2) تضخّم قائمة الـbindings حتى **8983** لـ`q='in'` (وأكثر حالة مرصودة: `al`=6363، `am`=5304). لذلك **لم يُطبَّق**، والمُطبَّق هو NEW-B: نفس `whereIn` على `category_medicine_links`، بلا subquery لكل صف، وبلا رحلات إضافية.

---

## 2) Query measurements

| الصفحة | الحالة | Before | After |
|---|---|---|---|
| `/logs` | baseline | **53** | **8** |
| `/logs` | `event=created` | 22 | 9 |
| `/logs` | `date=<today>` | 53 | 8 |
| `/logs` | `date=2026-09-29` | 53 | 8 |
| `/logs` | `page=2` | 53 | 7 |
| `/logs` | `q=admin` (لا نتائج) | 1 | 1 |
| `/logs` | `q+event+date` (لا نتائج) | 1 | 1 |
| `/pharmacy/ratings` | baseline (جدول `ratings` فارغ) | **15** | **5** |
| `/categories/5` | `q=para` — زمن العدّ (ms) | **139** | **45.0** |
| `/categories/5` | `q=pa` — زمن العدّ (ms) | **139** | **55.7** |
| `/categories/5` | بلا `q` — زمن العدّ (ms) | 29.8 | 4.3 |

**ملاحظات على الجدول:**
- `/logs` بحالة «لا نتائج» = استعلام واحد: `paginate()` **يتخطى الـSELECT عندما يكون العدّ = 0** (سلوك Laravel)، ولا صفوف ⇒ لا تحميل relations.
- عدّ `/categories/5` **بلا `q`** هو **نفس SQL حرفيًا** في الحالتين، ففرق (29.8 → 4.3) هو **تشويش إحماء** لا تحسين.
- عدّ الاستعلامات في `/categories/5` **لم يتغيّر** (10 استعلامات: 2 paginate + 3 resolve + 3 linked-pluck + 2 search) — التحسين في **زمن** الاستعلامات لا عددها، وكل ما بعد كتلة الفلتر لم يُمَس.

---

## 3) EXPLAIN ANALYZE

### `categories.show?q=para` — استعلام العدّ

**OLD** (correlated EXISTS):
```
-> Aggregate: count(0)  (actual time=… rows=1 loops=1)
  -> Filter: (<in_optimizer>… or …)  loops=1
    -> Index lookup on category_medicine_links using uniq_cat_sub_moh_product (category_id=5)  rows=9866
    -> Select #2 (subquery in condition; dependent)
        -> Limit: 1 row(s)  (actual time=0.00129..0.00129 rows=0 loops=9828)      ← 9828 مرة!
          -> Filter: ((trade_name like '%para%') or (generic_name like '%para%'))  loops=9828
            -> Index lookup on moh_medicines using moh_medicines_moh_product_id_index  loops=9828
    -> Select #3 … loops=9828      ← مرة لكل صف
    -> Select #4 … loops=9828      ← مرة لكل صف
```

**NEW** (non-correlated IN):
```
-> Aggregate: count(0)  (actual time=64.9..64.9 rows=1 loops=1)
  -> Filter: (<in_optimizer>(moh_product_id, … in (select #2)) or … or …)
    -> Index lookup on category_medicine_links using uniq_cat_sub_moh_product (category_id=5)  rows=9866
    -> Select #2 (subquery in condition; run only once)          ← مرة واحدة فقط
        -> Materialize with deduplication  (actual time=26.2..26.2 rows=65 loops=1)
          -> Filter: ((trade_name like '%para%') or (generic_name like '%para%'))  rows=137
            -> Table scan on moh_medicines  (rows=17295 loops=1)   ← مسح واحد
    -> Select #3 (subquery in condition; run only once) → never executed   ← OR قصّر المسار
    -> Select #4 (subquery in condition; run only once) → never executed
```

**الخلاصة:** الـsubquery المرتبطة انتقلت من **9828 تنفيذًا** إلى **تنفيذ واحد** (`Materialize`)،
والبقية lookups فهرسية على مجموعة مُهشَّرة (`0.003 ms` لكل lookup).

### فلتر التاريخ (`activity_log`) — الفرق البنيوي

| | الخطة | Key | Extra |
|---|---|---|---|
| OLD `whereDate` + `FORCE INDEX` | `type=index` | `activity_log_created_at_index` | `Using where; Backward index scan` |
| NEW range + `FORCE INDEX` | **`type=range`** | `activity_log_created_at_index` | `Using index condition; Backward index scan` |

بلا `FORCE INDEX` (الحجم الحالي): `type=ALL` للحالتين ⇒ لا فرق مرصود اليوم.

---

## 4) Tests

| الأمر | النتيجة |
|---|---|
| `php -l` على الملفات الثلاثة | ✅ `No syntax errors detected` ×3 — و**بلا BOM** ×3 |
| اختبارات مستهدفة (`AdminLogsFilterTest` · `RatingAggregateTest` · `CategoryIndexRenderTest` · `CategoryManagementTest` · `AdminShowPaginationTest`) | ✅ **OK (41 tests, 168 assertions)** |
| `tests/Feature/Web tests/Feature/Admin` (انحدار الـWeb) | ✅ **OK (345 tests, 1443 assertions)** |
| اختبارات API ذات الصلة (`RatingApiTest` · `CategoryApiTest` · `MedicineFilterApiTest` · `CategoryAvailabilityFilterTest` · `SubcategoryFilterApiTest` · `MedicineCategoryNormalizationTest`) | ✅ **OK (92 tests, 438 assertions)** |

- **أول تشغيل** لانحدار الـWeb أعطى **345/1443 مع 1 فشل** — `WebSmokeRegressionTest`
  على `/pharmacy/ratings` (500 بسبب `YEAR()` على SQLite). أُصلح FIX 3 ثم أُعيد التشغيل ⇒ أخضر بالكامل.
- لم يُستخدم `migrate:fresh` / `truncate` / `seed` / أي إعادة تعيين لبيانات الإنتاج.
- القياس على الإنتاج كان **قراءة فقط** بحارِس كتابة: `DB::listen` يرمي استثناءً على أي SQL لا يبدأ بـ`select|show|explain|describe|with`. الحارِس لم يُطلَق ولو مرة.
- **لا migrations ولا فهارس جديدة** — كل الأعمدة المستخدمة مفهرسة أصلًا:
  `category_medicine_links.{moh_product_id, moh_drug_id, medicine_id}` و`activity_log.created_at`.

---

## 5) Git

```
HEAD   = 400f4f7 perf(pharmacy): reduce inventory and medicine query counts   (لم يتغيّر)

 M app/Http/Controllers/web/Admin/CategoryController.php          ← هذا العمل
 M app/Http/Controllers/web/Admin/LogController.php               ← هذا العمل
 M app/Http/Controllers/web/Pharmacy/PharmacyRatingController.php ← هذا العمل

 git diff --stat (هذا العمل فقط):  3 files changed, 91 insertions(+), 19 deletions(-)
 git diff --check                :  نظيف (لا أخطاء مسافات)
 git diff --cached               :  فارغ — لا شيء staged
```

- **17 ملفًا معدَّلًا مسبقًا** + **10 ملفات غير متتبَّعة** (منها `docs/P2-PERFORMANCE-AUDIT-REPORT.md`، وهجرتا `moh_medicines`/`popular_medicines_cache`) — **لم يُمَس أيٌّ منها**.
- **لا commit · لا push.** الملف الوحيد الجديد المُنشأ هو هذا التقرير.

---

## 6) Deferred

1. **🔴 `LogController::exportExcel()` فيه نفس الـN+1 بالحرف — لم يُمَس.**
   `app/Exports/LogsExport.php:42` يستخدم `$log->subject`، بينما `exportExcel()` يحمّل
   `Activity::with('causer')` فقط. أي تصدير Excel ينفّذ **استعلامًا لكل سجل** (189 سجلًا الآن
   ≈ **34 ثانية** زمن رحلات عند 181 ms/رحلة). الإصلاح كلمة واحدة: `with(['causer', 'subject'])`.
   **سبب عدم التنفيذ:** الـAudit أثبت الـN+1 في **صفحة `/logs`** فقط، وهذه المهمة مقيَّدة بـ«نفّذ ما أثبته الـAudit فقط». يحتاج موافقة صريحة.

2. **العدّادات المتكررة (OPTIONAL) — قُيِّمت ولم تُدمج.** المواقع:
   `CategoryController::index` (4 عدّات) · `CategoryController::show` (2) · `PharmacyInquiryController` (3: new/answered/closed) · `MedicineRequestController` (3: pending/approved/rejected) · `PharmacyController` (2–3) · `PharmacyDashboardController`/`DashboardController` · `PatientController`.
   **القياس:** كل `count()` يكلّف **رحلة كاملة** (مرصود 170–830 ms من عميل عالي التأخير، وخادميًا بضعة ms) ⇒ دمج N→1 يوفّر (N−1) رحلة.
   **سبب التأجيل:** يمسّ 6+ controllers، وكل واحد يحتاج إثبات تكافؤ مستقلًا، وفيه فخ دلالي حقيقي:
   `CategoryController::show` فيه `$stats['links']` = عدّ **غير مُفلتر** بينما `$links->total()` = عدّ **مُفلتر** بالـpaginate — دمجهما **خطأ**. يُترك كتذكرة لاحقة.

3. **فائدة FIX 2 كامنة حاليًا:** عند 189 صفًا يختار المحسّن `type=ALL` لكلا الاستعلامين. القيمة تتحقّق مع نمو `activity_log`.

4. **كتلة `searchResults` في `categories.show`** ما زالت تُنفّذ 3 `pluck` + 2 استعلام بحث عند وجود `q` (بلا تغيير). لم يُثبت الـAudit أنها عنق زجاجة ⇒ لم تُمَس.

5. **AJAX / `filter-ajax.js`** — لم يُطبَّق شيء منه في هذه المرحلة، التزامًا بالنطاق (كان حكم الـAudit: فائدة UX فقط، بكلفة تعقيد ومخاطر انحدار عالية، خصوصًا `pharmacy/inventory` بـ`quantities[]`).

---

## ملخّص القرار

| FIX | الملف | قبل → بعد | التكافؤ |
|---|---|---|---|
| 1 — N+1 `subject` | `LogController` | 53 → 8 استعلامًا | ✅ مُثبَت |
| 2 — فلتر تاريخ sargable | `LogController` | `type=index` → `type=range` | ✅ مُثبَت (الفائدة كامنة) |
| 3 — تجميع التقييمات | `PharmacyRatingController` | 15 → 5 استعلامات | ✅ مُثبَت على MySQL **و**SQLite |
| 4 — فلتر بحث الأقسام | `CategoryController` | 139 → 45 ms (عدّ) | ✅ مُثبَت (15 حالة) |
| — بديل الـAudit لـFIX 4 | — | 557 → 1446 ms | ❌ **مرفوض بالقياس** |
