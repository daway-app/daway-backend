# P2 — Performance Audit & Safe Optimization Report

**التاريخ:** 2026-09-29
**النطاق:** بحث الكتالوج · لوحة الصيدلية/المخزون · استعلامات `moh_medicines` · Cache/Session/Queue · مدخلات Vite
**القاعدة المرجعية:** `develop` @ `0236a66` (P1) — **بلا commit وبلا push في هذه المرحلة**
**قاعدة العمل:** قياس أولًا (`EXPLAIN ANALYZE` + عدّ استعلامات) ⇒ لا تغيير بلا برهان تكافؤ.

---

## 0) المنهج — لماذا `EXPLAIN ANALYZE` وليس زمن العميل

الإنتاج على Aiven، وذهاب-وإياب الشبكة الواحد ≈ **181ms**. أي قياس بزمن العميل يُقيس الشبكة لا الاستعلام:
`SELECT id ORDER BY id` ظهر بـ **3,854ms** على العميل وهو على السيرفر **0.045ms**.

| أداة القياس | الاستخدام |
|---|---|
| `EXPLAIN` | خطة الوصول: `type` · `key` · `rows` · `Extra` |
| `EXPLAIN ANALYZE` | **الزمن الفعلي على السيرفر** (مستقل عن الشبكة) — المعيار المعتمد |
| `DB::enableQueryLog()` | عدّ الاستعلامات لكل صفحة/طلب |
| مقارنة مجموعات النتائج | برهان التكافؤ على **بيانات MySQL الحقيقية** |

> **مرفوض:** `DB::listen()` — المستمعون **يتراكمون** عبر المراحل ولا يُصفَّرون بـ`flushQueryLog()` ⇒ عدّ مضاعف كاذب.

**بيئة القياس:** MySQL **8.4.8** · `moh_medicines` = **17,295** صفًا · `innodb_ft_min_token_size = 3` · collation `utf8mb4_0900_ai_ci`.

---

## 1) المشاكل المُثبتة

### 1.1 بحث الكتالوج — `LIKE '%q%'` داخل سلسلة `OR` (مسح كامل)

`PharmacyMedicineController::catalogSearch()` (السطر 191+) يبني:

```
trade_name LIKE '%q%' OR generic_name LIKE '%q%' OR manufacturer LIKE '%q%'
  OR trade_name IN (…) OR moh_product_id = n OR moh_drug_id = n
```

`LIKE '%…%'` **غير قابل لاستخدام فهرس B-tree** (wildcard بادئ)، وسلسلة `OR` تمنع `index_merge` ⇒ MySQL يمسح الجدول كاملًا.

**القياس (EXPLAIN ANALYZE — سيرفر):**

| المصطلح | الخطة | زمن السيرفر | صفوف مفحوصة |
|---|---|---|---|
| `panadol` (سلسلة OR الحالية) | `type=index` · `key=idx_moh_search` | **84–114 ms** | **17,295** |
| `panadol` (عمود واحد فقط) | `type=index` · `Using index` | 12–17 ms | 17,295 |
| `12345` (رقمي، وحده) | `type=ref` · `moh_product_id_index` | **0.047 ms** | 1 |
| `panadol*` (FULLTEXT) | `type=fulltext` · `ft_moh_search` | **1.04–1.37 ms** | 2 |

مصطلحات قليلة المطابقة (`zzz` · `AUGMENTIN` · `بنادول`) تصل إلى **236–318 ms** لأنها لا تُنهي المسح مبكرًا.

### 1.2 الفرع الرقمي ينهار داخل `OR`

`moh_product_id = 12345` **وحده** ⇒ `type=ref` · **0.047 ms**.
**داخل** سلسلة `OR` ⇒ `type=index` مسح كامل · **114 ms** (≈ 2,400× أبطأ).

### 1.3 عدّادات مكرّرة + N+1 في لوحة الصيدلية/المخزون

| الصفحة/الطلب | قبل | الملاحظة |
|---|---|---|
| `MEDICINES INDEX` | **8** استعلامات | 4 × `count(*)` على نفس الجدول بنفس `pharmacy_id` |
| `INVENTORY INDEX` | **14** | 3 × عدّاد حالة + **7 × `count(*) … created_at <= ?`** متطابقة (واحدة لكل يوم في الرسم) |
| `INVENTORY UPDATE` (N=30) | **327** | `find($id)` لكل صف + `LowStockNotifier` يقرأ `pharmacy`/`user`/`medicine` داخل الحلقة |

تفصيل الـ327 قبل الإصلاح: 30 find + 30 pharmacies + 30 medicines + 30 users + 30 notification SELECT + 30 notification INSERT + 57 activity_log.

---

## 2) التغيير المُطبَّق (٤ تحسينات — بعد برهان التكافؤ)

### A + B — `app/Http/Controllers/web/Pharmacy/PharmacyInventoryController.php::index()`

```php
// A: 3 × count()  →  استعلام تجميعي واحد
$stats = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
    ->selectRaw(
        'SUM(CASE WHEN quantity > ? THEN 1 ELSE 0 END) as available,
         SUM(CASE WHEN quantity > 0 AND quantity <= ? THEN 1 ELSE 0 END) as low,
         SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END) as out_count',
        [$threshold, $threshold]
    )->first();

// B: 7 × count() داخل حلقة  →  استعلام واحد بـ7 تعبيرات
$trendExpr = implode(', ', array_map(
    fn (int $idx): string => "SUM(CASE WHEN created_at <= ? THEN 1 ELSE 0 END) as d{$idx}",
    array_keys($cutoffs)
));
$trendRow = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
    ->selectRaw($trendExpr, $cutoffs)->first();
```

### C — `PharmacyInventoryController::update()` — إلغاء N+1

```php
$items = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
    ->whereIn('id', array_keys($quantities))->get()->keyBy('id');   // كان: find() لكل صف

PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
    ->with(['pharmacy.user', 'medicine'])                          // كان: 3 استعلامات لكل صف
    ->get()->each(fn ($pm) => LowStockNotifier::notifyIfLowStock($pm));
```

### D — `app/Http/Controllers/web/Pharmacy/PharmacyMedicineController.php::index()`

```php
$stats = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
    ->selectRaw(
        'COUNT(*) as total,
         SUM(CASE WHEN is_available = 1 AND quantity > 0 THEN 1 ELSE 0 END) as available,
         SUM(CASE WHEN is_available = 0 OR quantity <= 0 THEN 1 ELSE 0 END) as out_count,
         SUM(CASE WHEN quantity > 0 AND quantity <= ? THEN 1 ELSE 0 END) as low',
        [$threshold]
    )->first();
```

**لماذا الدمج آمن:** `pharmacy_medicines.quantity` و`is_available` **NOT NULL** ⇒ لا حالة `NULL` تُفسد `CASE`.
العدّادات **غير متنافية** (`low` و`out` قد يجتمعان) ⇒ كل عدّاد في `SUM(CASE…)` **مستقل**، لا `COUNT` مشروط واحد.

---

## 3) القياسات بعد

| المقياس | قبل | بعد | الفرق |
|---|---|---|---|
| `MEDICINES INDEX` — استعلامات | 8 | **5** | −3 |
| `INVENTORY INDEX` — استعلامات | 14 | **6** | −8 |
| `INVENTORY UPDATE` (N=30) — استعلامات | **327** | **211** | **−116** |

**الـ211 المتبقية مقصودة:** 30 notification SELECT + 30 notification INSERT + activity_log لكل صف — كتابات فعلية لكل عنصر، **ليست N+1** (لا استعلام متكرر على نفس الصف بلا داعٍ).

### برهان التكافؤ (بيانات MySQL الحقيقية)

```
=== A) INVENTORY status: 3 COUNTs vs 1 aggregate ===
pharmacy 1   OLD=1/0/0   NEW=1/0/0   identical=YES
=== B) MEDICINES INDEX: 4 COUNTs vs 1 aggregate ===
pharmacy 1   OLD=1/1/0/0   NEW=1/1/0/0   identical=YES
=== C) 7-DAY TREND: 7 COUNTs vs 1 aggregate ===
pharmacy 1   OLD=[0,0,0,0,0,0,0]   NEW=[0,0,0,0,0,0,0]   identical=YES
=== D) N+1: batch whereIn vs per-row find ===
pharmacy 1   per-row=1  batched=1   identical=YES
```

`LOW_STOCK_THRESHOLD = 10`.

---

## 4) مُقاس ولم يُطبَّق (تغيير سلوك = ممنوع)

### 4.1 `LIKE → FULLTEXT` في بحث الكتالوج
FULLTEXT أسرع **~100×** (1.37ms مقابل 114ms) لكن **مجموعة النتائج مختلفة**:

| المصطلح | LIKE (السلوك الحالي) | FULLTEXT | الحكم |
|---|---|---|---|
| `panadol` | 2 | 2 | متطابق |
| `pa` | **3,823** | 1,688 | **مختلف** |
| `pan` | **1,516** | 111 | **مختلف** |
| `x` | **4,219** | 149 | **مختلف** |
| `بنادول` | 0 | 0 | متطابق |

السبب: FULLTEXT يطابق **بادئة كلمة** لا **تحت-نص**، و`ft_min_token_size = 3` يستثني `pa`/`x`.
⇒ التحويل يغيّر شكل الرد ومحتواه ⇒ **مرفوض** (ممنوع تغيير عقد/سلوك البحث).

### 4.2 فصل الفرع الرقمي
`UNION` نظريًا يستعيد `type=ref` للفرع الرقمي، لكن MySQL لم يستخدم `index_merge` نظيفًا في الاختبار ⇒ لا مكسب مُثبت ⇒ **متروك**.

### 4.3 مدخلات Vite ميتة (إثبات فقط)
صفر `@vite` وصفر import في كامل `resources/`:

- `resources/css/pages/medicines_create.css`
- `resources/css/pages/pharmacies.css`
- `resources/css/pages/pharmacy_dashboard.css`
- `resources/js/pharmacy_dashboard.js`

> ⚠️ **لا يُخلط:** `resources/css/pages/pharmacies_create.css` **مستخدم** في `pharmacies/create.blade.php` و`edit.blade.php`.
> `build.emptyOutDir: false` مقصود (service-worker يخدم صفحات مخزّنة بمراجع مُجزَّأة) ⇒ العائد من الإزالة منخفض ⇒ **إثبات فقط بلا إزالة**.

### 4.4 Cache / Session / Queue — لا عنق زجاجة مُثبت
- الإنتاج (`render.yaml`) يضبط `CACHE_STORE=file` فقط.
- `SESSION_DRIVER` و`QUEUE_CONNECTION` **غير مضبوطين** ⇒ الافتراضي `database` (`config/session.php:21` · `config/queue.php:16`).
- ⇒ **الجلسات والمهام على نفس Aiven MySQL**. ملاحظة مخاطر، بلا تغيير (`CACHE_DRIVER`/`SESSION_DRIVER`/`QUEUE_CONNECTION` خارج النطاق المسموح).

### 4.5 فهارس `moh_medicines` — لا فهرس مقترح
| الفهرس | الأعمدة | الحالة |
|---|---|---|
| `PRIMARY` | `id` | UNIQUE |
| `idx_moh_search` | `trade_name, generic_name` | NON-UNIQUE |
| `moh_medicines_moh_product_id_index` | `moh_product_id` | NON-UNIQUE |
| `moh_medicines_moh_drug_id_index` | `moh_drug_id` | NON-UNIQUE |
| `ft_moh_search` | `trade_name, generic_name, manufacturer, company` | FULLTEXT |
| `ft_moh_tg_search` | `trade_name, generic_name` | FULLTEXT |

**لا فهرس B-tree يفيد `LIKE '%…%'`** — أي إضافة تخزين بلا فائدة ⇒ **لا migration مقترح**.

> 🔴 **انحراف مؤكَّد:** هجرة `database/migrations/2026_09_23_000001_add_unique_indexes_to_moh_medicines.php` تُعلن فهارس **UNIQUE**، لكن القاعدة الحيّة تُظهر النسخ **NON-UNIQUE** (`_index`) ⇒ الهجرة **لم تُطبَّق على الإنتاج**. يحتاج قرارًا منفصلًا (خارج نطاق P2).

---

## 5) الملفات المعدّلة

| الملف | التغيير |
|---|---|
| `app/Http/Controllers/web/Pharmacy/PharmacyInventoryController.php` | A + B + C |
| `app/Http/Controllers/web/Pharmacy/PharmacyMedicineController.php` | D |

**لا ملفات أخرى.** لا migrations · لا seeds · لا Blade · لا JS · لا CSS · لا routes · لا config.

---

## 6) الاختبارات

| المجموعة | النتيجة |
|---|---|
| `tests/Feature/Web` (كامل) | **OK (316 tests, 1332 assertions)** — مطابق لما قبل P2 ⇒ صفر انحدار |
| الموجّهة: `PharmacyInventoryFilterTest` · `PharmacyMinStockTest` · `PharmacyMedicineCatalogSearchTest` · `PharmacyManualMedicineNameTest` · `PharmacyInventoryImportTest` | **OK (87 tests, 416 assertions)** |
| API/Unit: `PharmacyInventoryTest` · `PharmacyInventoryApiTest` · `PharmacyMedicineApiTest` · `PharmacyMedicineAddFlowTest` · `PharmacyMedicineCatalogUpdateTest` · `MedicineApiTest` · `MedicineSearchEdgeCasesTest` · `MedicineSearchCacheKeyTest` · `PharmacyMedicineMinStockTest` · `PharmacyAvailabilityTest` | **OK (96 tests, 284 assertions)** |

- `php -l` للملفين: نظيف · `git diff --check`: نظيف.
- ملف الفحص المؤقت `tests/Feature/Web/P2QueryCountProbeTest.php` **حُذف** (لم يُشحن).
- لا `migrate:fresh` · لا `truncate` · لا seed · لا تغيير بيانات إنتاج.

---

## 7) الخلاصة

- **٤ تحسينات مُطبَّقة ومُثبتة**: −3 و−8 استعلامات في صفحتَي العرض، و**−116 استعلامًا** في تحديث مخزون بـ30 صفًا، **بنفس النتائج حرفيًا** على بيانات حقيقية.
- **بحث الكتالوج**: عنق الزجاجة مُثبت بالأرقام (مسح 17,295 صفًا) لكن الحل الوحيد الفعّال (FULLTEXT) **يغيّر السلوك** ⇒ تقرير فقط.
- **لا فهرس ولا migration مقترح** — لا فهرس يفيد `LIKE '%…%'`.
- **انحراف يستحق قرارًا:** فهارس UNIQUE في هجرة `2026_09_23_000001` غير مطبَّقة على الإنتاج.
- **بلا commit وبلا push** — التغييرات في الـworking tree فقط.
