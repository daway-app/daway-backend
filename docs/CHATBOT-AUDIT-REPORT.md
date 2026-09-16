# تقرير تدقيق الـ Chatbot — Daway (المرحلة 1: تدقيق فقط)

> **النوع:** تدقيق قراءة فقط — **لم يُعدَّل ولم يُحذف أي ملف بسبب هذا التقرير.**
>
> **النتيجة النهائية (2026-09-16):** `docs/CHATBOT-CLEANUP-REPORT.md` — **أُزيلت طبقة Python/NLP
> بالكامل** (لأن شخصًا آخر سيبني الـAI كـAPI مستقل خارج المستودع)، وبقي Laravel على معماريته
> الأصلية: `MedicineIntentService` ← `MedicineIntentClient` ← AI API خارجي، مع fallback محلي.
> **هذا التدقيق ما زال ساريًا** — الفجوة الوحيدة الموصوفة في §12 (لا يوجد AI) هي الوضع المطلوب الآن.
>
> ⚠️ **ملاحظة إعادة إصدار:** هذا الملف وُجد **مقطوعاً (18 بايت)** بعد كتابته أولاً — كتابة
> متزامنة من جلسة موازية على نفس الشجرة أطاحت بمحتواه. أُعيد توليده من الكود المُتحقَّق منه
> فعلياً (`grep`/`read` على الملفات المذكورة أدناه) لا من الذاكرة.

---

## 0) نطاق التدقيق ومنهجه

**السؤال:** "أعد بناء الـChatbot من الصفر" — لكن أولاً: ما هو الموجود فعلاً؟

**المنهج:** قراءة فعلية لكل ملف يشتبه في انتمائه للـChatbot، ثم تتبّع كل مُستدعٍ له
(`grep` على `app/ routes/ tests/ config/ bootstrap/ resources/ public/`)، ثم قياس السلوك
الفعلي (زمن/ذاكرة) بدل الاعتماد على الانطباع.

**النتيجة المحورية:** ما يُسمّى "الـChatbot" هو **3 ملفات فقط**، ونظام **لقطة واحدة (single-shot)**
لا محادثات محفوظة. Domain الطبقة السفلى (البحث عن دواء + مخزون + مسافة) **سليم ولم يُمَس**.
⇒ "إعادة البناء من الصفر" عملياً = **استبدال طبقة النية وحدها**، لا هدم المشروع.

---

## 1) أين يعيش الـ Chatbot حالياً؟

| الملف | الدور |
|---|---|
| `app/Http/Controllers/Api/PatientAssistantController.php` | الـendpoint الوحيد — يستقبل الرسالة ويبني الرد |
| `app/Services/Ai/MedicineIntentService.php` | استخراج النية (كان يستدعي عميلاً خارجياً + heuristic) |
| `app/Services/Ai/MedicineIntentClient.php` | عميل HTTP للخدمة الخارجية (`POST {DAWAY_AI_BASE_URL}/ai/assistant`) |

**ما لا يوجد (مهم):**
- ❌ لا جدول `conversations` / `messages` — **لا سياق محادثة، ولا ذاكرة بين الطلبات.**
- ❌ لا Jobs/Events/Notifications لهذا المسار.
- ❌ لا Repository — الـController يخاطب الخدمات مباشرة.

---

## 2) الملفات والمسارات المرتبطة

**المسار الوحيد:**

```
POST /api/patient/assistant/chat          routes/api.php:124
  middleware: auth:sanctum  +  throttle:assistant
  throttle:assistant = 15/دقيقة            bootstrap/app.php:192
    (المفتاح: user_id أو IP — لا مشاركة مع السقف العام 'api' 60/د)
```

**السلسلة الفعلية:**

```
PatientAssistantController::chat()
   ├─ SearchLog::track($message, 'assistant')        ← تتبّع البحث (مرة واحدة)
   ├─ MedicineIntentService::analyze()               ← النية (خارجي أو محلي)
   ├─ MedicineResolver::resolveCandidates()          ← اسم → medicine_id حقيقي
   ├─ MedicineResolver::alternatives()               ← بدائل
   └─ PharmacyInventorySearch::forMedicine()         ← صيدليات + مخزون + سعر + مسافة
```

**الملفات المشارَكة (ليست Chatbot لكن تُستدعى منه):**
`app/Services/Ai/MedicineResolver.php` · `app/Services/PharmacyInventorySearch.php` ·
`app/Support/{Haversine,StockStatus,PharmacyAvailability,MedicineNameMapper}.php` ·
`app/Models/SearchLog.php`

---

## 3) ما يمكن حذفه

| الملف | الحكم | السبب |
|---|---|---|
| `MedicineIntentService.php` | ✅ قابل للحذف | **بعد** استبداله — وشرط ألا يبقى أي consumer |
| `MedicineIntentClient.php` | ✅ قابل للحذف | **بعد** استبداله — وشرط ألا يبقى أي consumer |

**فحص ما قبل الحذف (شرط إلزامي):** `grep` على `app/ routes/ tests/ config/ bootstrap/ resources/ public/`
⇒ **صفر مراجع خارجية**؛ الملفان كانا يشيران لبعضهما فقط. (نُفِّذ فعلاً في المرحلة 2.)

---

## 4) ما يجب الحفاظ عليه (غير قابل للمس)

| العنصر | لماذا |
|---|---|
| `MedicineResolver::resolveCandidates()` | مقابل بيانات حقيقية — الـAI لا يخترع دواءً |
| `MedicineResolver::alternatives()` | يُستخدم في الرد |
| `PharmacyInventorySearch::forMedicine()` | المصدر الوحيد للمخزون/السعر/المسافة/الترتيب |
| `Haversine::kmBetween()` | حساب المسافة بلا أي API مدفوع |
| `StockStatus` | المصدر الوحيد لمنطق "متوفر" |
| `PharmacyAvailability` | منطق أوقات العمل |
| `MedicineNameMapper` | التطبيع العربي (نواة المطابقة) |
| `SearchLog::track()` | تحليلات + كشف فجوات المرادفات |
| شكل الرد الحالي | **Flutter يقرأه** |

---

## 5) كيف يعمل البحث عن الدواء حالياً؟

```
اسم حر (نص عربي/إنجليزي)
   ▼
MedicineResolver::resolveCandidates(?string $drugName): array
   ├─ ['local'  => Collection<Medicine>]   ← الكتالوج المحلي (medicines)
   └─ ['moh'    => Collection<...>]        ← كتالوج وزارة الصحة
```

**مصدران، والـController يأخذ `$candidates['local']->first()`** — أي أن النتيجة النهائية
**دائماً من الكتالوج المحلي**، وكتالوج الوزارة يُستخدم كطبقة مساعدة/بدائل.

**`lookupMapping()`** يقرأ ملف JSONL المرادفات (`database/data/chatbot_medicines.json`، ~13.2MB،
**17,296 سجلاً**) ويطابق الاستعلام مقابل `aliases`.

---

## 6) كيف يعمل البحث في مخزون الصيدليات؟

```
PharmacyInventorySearch::forMedicine(
    medicineId, latitude, longitude, radiusKm, sort, limit
)
   ├─ 1. Bounding Box في SQL        ← يقلّص المرشّحين قبل أي حساب
   ├─ 2. Haversine::kmBetween() في PHP  ← دقّة على المرشّحين المقلَّصين فقط
   └─ 3. ترتيب + حدّ أقصى للنتائج
```

**سلوك حرج يجب الحفاظ عليه:** الصيدلية **بلا إحداثيات تبقى في النتائج بـ `distance_km = null`**
— **لا تُستبعد**. (مؤكَّد في الكود + محروس باختبار.)

---

## 7) كيف يُخزَّن موقع الصيدلية؟

`pharmacies` table — عمودان **nullable** ومفهرَسان:

```php
$table->decimal('latitude', 10, 8)->nullable()->index();
$table->decimal('longitude', 11, 8)->nullable()->index();
```

- الدقّة: 8 منازل عشرية (≈ 1mm) — كافية تماماً.
- `nullable` ⇒ **صيدليات بلا موقع موجودة فعلاً** ⇒ إلزامي التعامل مع `null` بلا كسر.
- مفهرَسان ⇒ **Bounding Box في SQL سريع**.

---

## 8) كيف نحصل على موقع المستخدم من Flutter؟

**القرار المعماري: Flutter هو مصدر الموقع الوحيد.**

```
Flutter (GPS / permission)  →  { message, latitude, longitude, radius_km?, sort?, language? }
                             →  Laravel
```

**Laravel لا يستنتج موقعاً ولا يخزّنه تلقائياً.** والـController يعالج الحالة بصرامة:

```php
$hasGeo = $latitude !== null && $longitude !== null;
if (! $hasGeo) { $latitude = null; $longitude = null; }   // إمّا الاثنان أو لا شيء
```

- إحداثيات ناقصة (واحد فقط) ⇒ تُلغى الاثنتان — **لا تخمين.**
- **بلا إحداثيات ⇒ لا فشل**: النتائج تُعاد بترتيب آخر، و`distance_km = null`.

**لماذا لا تُرسَل الإحداثيات للـPython؟** الـPython يفهم **اللغة فقط** — "هل طلب المستخدم
القريب؟" — أما الموقع فيُحلّ في Laravel حيث بيانات الصيدليات. هذا يمنع Python من رؤية
أي بيانات جغرافية أو تشغيلية.

---

## 9) كيف نرتّب الصيدليات حسب المسافة؟

`App\Support\Haversine::kmBetween()` — صيغة هافرسين، نصف قطر الأرض 6371 كم، **بلا Google Maps
وبلا أي API مدفوع.**

الترتيب (`PharmacyInventorySearch::SORTS`):

| النمط | الترتيب |
|---|---|
| `nearest` (الافتراضي) | مسافة ASC → سعر |
| `cheapest` | سعر → مسافة ASC |
| `best` | توفر → مسافة → سعر → تقييم |

- الصيدليات بلا مسافة تُدفَع للآخر بـ`PHP_FLOAT_MAX` (لا تُحذف).
- `sort` غير صالح ⇒ يسقط لـ`nearest` بلا خطأ.

---

## 10) الـAPIs التي **لا يجوز** كسرها

| المسار | الحالة |
|---|---|
| `POST /api/patient/assistant/chat` | المسار والمصادقة والـthrottle تبقى كما هي |
| `GET /api/pharmacies` | **Public دائمًا** — لا يتغيّر |
| `GET /api/pharmacies/{id}` | لا يتغيّر |
| `GET /api/patient/medicines/*` | لا يتغيّر |
| `POST /api/medicines/resolve` | لا يتغيّر |
| `POST /api/ocr/medicine` | لا يتغيّر |

**قيد الرد:** كل حقول الرد الحالية تُقرأ من Flutter (`success`, `analysis`, `medicine`,
`pharmacies[].{pharmacy_id,name,price,quantity,availability,distance_km,is_open_now,rating,address,region}`,
`alternatives`, `total_found`, `requires_location`).

⇒ **التوسيع إضافي فقط (additive).** لا إعادة تسمية، لا حذف، لا تغيير نوع.

---

## 11) الاختبارات الموجودة والتعديلات اللازمة

| الملف | الحكم |
|---|---|
| `tests/Feature/Api/Patient/AssistantChatTest.php` | **يُعدَّل** — مفاتيح config الجديدة + قيمة `analysis.intent` |
| `tests/Feature/Api/Patient/MedicineResolverTest.php` | يبقى — لا تغيير سلوك |
| `tests/Feature/Api/Patient/PharmacySearchRadiusTest.php` | يبقى — يحرُس الـradius |
| `tests/Feature/Api/Patient/PatientMedicineAvailabilityTest.php` | يبقى |
| `tests/Feature/Api/Patient/MappingGateEquivalenceTest.php` | يبقى (كان بطيئاً/يسقط — صار يعمل) |
| `tests/Feature/Api/Patient/*` (البقية) | تبقى كما هي |
| **جديد** | اختبارات NLP + عقد الرد الإضافي + حالات A–L |

---

## 12) تحليل الفجوة مقابل التصميم الهدف

```
الهدف:  Flutter → Laravel → Python NLP → Resolver → Inventory → Distance → Nearest → JSON → Flutter
```

| الطبقة | الحالة قبل التدقيق |
|---|---|
| Flutter مصدر الموقع | ✅ موجود |
| Laravel نقطة العبور | ✅ موجود |
| **Python NLP مستقل** | ❌ **غير موجود** — كان استدعاءً خارجياً بمفتاح `message` فقط، بلا عقد مُوثَّق |
| عقد نية صريح (DTO) | ❌ **غير موجود** — كان مصفوفة بلا قائمة بيضاء صريحة ولا `location` |
| Resolver | ✅ موجود وسليم |
| Inventory | ✅ موجود وسليم |
| Haversine + nearest | ✅ موجود وسليم |
| رد additive | ❌ **ناقص** — لا `found`/`pharmacies_count`/`location_available` |
| Fallback محلي حتمي | ⚠️ موجود لكن بلا فصل واضح عن طبقة HTTP |

**الخلاصة:** ~90% من الطبقة السفلى جاهز. **الفجوة كلها في طبقة النية**: لا عقد صريح،
لا خدمة Python مستقلة، لا حقول رد جديدة.

---

## 13) تعارضات تحتاج قراراً (مُوثَّقة قبل التنفيذ)

| # | التعارض | القرار المعتمد |
|---|---|---|
| C1 | شكل الرد: استبدال أم إضافة؟ | **إضافة فقط** — كل حقل قديم يبقى حرفياً |
| C2 | أسماء متغيّرات البيئة: `DAWAY_AI_*` أم `NLP_SERVICE_*`؟ | **`NLP_SERVICE_*`** + fallback انتقالي لـ`DAWAY_AI_BASE_URL` |
| C3 | `location.mode` قيمة واحدة أم `required`+`mode`؟ | **الاثنان** — `mode` وصفية، و`required` مشتقة (`nearby` ⇒ `required=true`) |
| C4 | `NLP_SERVICE_ENABLED` افتراضياً `true` أم `false`؟ | **`true`** — بلا `URL` لا يحدث أي HTTP أصلاً، فالسلوك آمن |

---

## 14) مخاطر مُحدَّدة قبل البدء

| المخاطرة | الأثر | المعالجة |
|---|---|---|
| **`lookupMapping` بطيء** (0.7–4.1 ث) | كل رسالة مساعد تتأخّر | يُصلَح ببوّابة `haystackMayMatch` — **بلا تغيير نتائج** |
| **Python يصبح نقطة فشل واحدة** | تعطّل المساعد كاملاً | إبقاء الـheuristic المحلي إلزامي |
| **رد Python غير موثوق** | حقن/نية مختلقة | قائمة بيضاء + تنقية + تطبيع `mode` في Laravel |
| **كسر Flutter** | توقف الإنتاج | توسيع إضافي فقط + اختبار عقد صريح |
| **ذاكرة زائدة** | خطة Render المجانية | أي فهرس دائم **مرفوض** — يجب قياس الذاكرة |

---

## 15) نقاط كانت تحتاج قرارك — **حُسِمت (2026-09-16)**

1. **`analysis.intent`** — ✅ **بقيت `medicine_search`** (القيمة الأصلية المتوافقة مع Flutter).
   القيمة `search_medicine` رُفضت وأُلغيت مع إزالة طبقة الـNLP.
2. **`nlp-service`** — ✅ **حُذف بالكامل**. لا خدمة Python داخل المستودع.
3. **`NLP_SERVICE_*`** — ✅ **أُزيلت** من `config/services.php` و`.env.example` و`render.yaml`.
4. **`MappingGateEquivalenceTest`** — ✅ يبقى مستثنى من الحزمة (بطيء ~60ث) لكن **إلزامي عند أي
   تغيير في منطق المطابقة**. يمرّ الآن (2 tests · 28 assertions).

**⇒ لا نقاط معلّقة. التفاصيل في `docs/CHATBOT-CLEANUP-REPORT.md`.**

---

**انتهى التدقيق. لم يُعدَّل أي ملف إنتاجي بسبب هذا التقرير.**
