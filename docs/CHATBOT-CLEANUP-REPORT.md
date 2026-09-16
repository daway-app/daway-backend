# تقرير تنظيف الـ Chatbot — إزالة Python/NLP وإعادة Laravel لحالة integration-ready

> **الحالة:** منفَّذ. **لا commit · لا push · لا merge.**
> **التاريخ:** 2026-09-16 · **الفرع:** `develop`
> **السبب:** شخص آخر سيبني الـAI/NLP كـAPI مستقل خارج مستودع Daway ⇒ لا Python داخل المستودع.
> **المرجع:** `docs/CHATBOT-AUDIT-REPORT.md` (تدقيق المرحلة 1 — ما زال ساريًا).

---

## 0) الخلاصة

حُذف **كل** ما يخصّ Python/FastAPI/NLP الذي بُني سابقًا، وأُعيد Laravel إلى معماريته الأصلية
التي **هي بالفعل** integration-ready: طبقة نية تستدعي AI API خارجي وتتحقق من ردّه، مع
مستخرج محلي حتمي كـfallback. لم يُبنَ أي AI جديد.

---

## 1) Deleted — كل ما حُذف

### أ) مجلد Python كامل (11 ملفًا)

```
nlp-service/
├── app/{__init__.py, main.py, schemas.py, intent.py}   ← FastAPI + Pydantic + منطق النية
├── tests/{__init__.py, test_api.py, test_intent.py}    ← 22 اختبار Python
├── requirements.txt      ← fastapi · uvicorn · pydantic · pytest · httpx
├── pytest.ini
├── README.md
└── .gitignore
```

### ب) طبقة Laravel المبنية للـPython

| الملف | كان دوره |
|---|---|
| `app/Services/Ai/NlpIntentClient.php` | عميل HTTP موجَّه لخدمة `nlp-service` |
| `app/Services/Ai/NlpIntentService.php` | قائمة بيضاء + تنقية + heuristic للعقد الجديد |
| `app/Support/Nlp/IntentResult.php` | DTO العقد الجديد (`search_medicine` + `location{required,mode}`) |

### ج) اختبارات وتوثيق المهمة السابقة

| الملف | السبب |
|---|---|
| `tests/Feature/Api/Patient/NlpIntentServiceTest.php` | تختبر الطبقة المحذوفة |
| `tests/Feature/Api/Patient/AssistantResponseContractTest.php` | تختبر العقد الإضافي الملغى |
| `docs/CHATBOT-REBUILD-REPORT.md` | يوثّق عملًا أُعيد عكسه ⇒ مضلِّل لو بقي |

**أثر الحذف على عدد الاختبارات:** **33 اختبارًا** (922 → 889) · **127 تأكيدًا** (3785 → 3658).

---

## 2) Modified — ملفات Laravel أُعيدت لأصلها (8)

أُعيدت **حرفيًا** من الـgit index، وتحقّقتُ من التطابق **ببصمة SHA** (`git hash-object` = بصمة الـindex):

| الملف | ما أُزيل منه |
|---|---|
| `app/Http/Controllers/Api/PatientAssistantController.php` | استُبدل `NlpIntentService` بـ`MedicineIntentService`؛ حُذفت حقول الرد الإضافية |
| `app/Providers/AppServiceProvider.php` | أُعيدت bindings `MedicineIntentClient`/`MedicineIntentService` |
| `config/services.php` | حُذفت كتلة `nlp` (بقيت `daway_ai`) |
| `.env.example` | حُذفت `NLP_SERVICE_*` |
| `render.yaml` | حُذفت `NLP_SERVICE_*` |
| `tests/Feature/Api/Patient/AssistantChatTest.php` | أُعيدت مفاتيح config الأصلية |
| `app/Services/Ai/MedicineIntentClient.php` | **أُعيد من الحذف** (كان `AD` في الـindex) |
| `app/Services/Ai/MedicineIntentService.php` | **أُعيد من الحذف** (كان `AD` في الـindex) |

> ⚠️ **ملاحظة تقنية:** بصمة `render.yaml` مفقودة من مخزن الكائنات (`git diff` يفشل بـ
> `unable to read afc5aa9…`)، فأُعيد تحريره يدويًا — ثم تأكّد التطابق **ببصمة SHA** مع الـindex.

### ⚠️ تغيير واحد أُبقي عليه عمدًا (خارج نطاق Python)

| الملف | التغيير | لماذا بقي |
|---|---|---|
| `app/Services/Ai/MedicineResolver.php` | بوّابة `recordHaystackLoose()` في `lookupMapping` | **ليس Python/NLP** — تحسين أداء خالص لا يغيّر أي نتيجة: **1425–2731ms ⇒ 230–1159ms**، الذاكرة **24MB ثابتة**. عكسه يُعيد مسارًا بطيئًا مقيسًا. **غير مذكور في قائمة المراجعة** التي حدّدتها ⇒ أبقيته. |

**للعكس إن أردت:** `git checkout -- app/Services/Ai/MedicineResolver.php`
(سيُعيد المسار البطيء — `MappingGateEquivalenceTest` يبقى حارسًا للتكافؤ.)

---

## 3) Preserved — ما بقي كما هو

**لم تُمَس أي من هذه:**

`MedicineResolver::resolveCandidates()` · `MedicineResolver::alternatives()` ·
`PharmacyInventorySearch::forMedicine()` · `app/Support/Haversine.php` ·
`app/Support/StockStatus.php` · `app/Support/PharmacyAvailability.php` ·
`app/Support/MedicineNameMapper.php` · `database/data/chatbot_medicines.json` ·
`SearchLog::track()` · `throttle:assistant` (15/د)

**المسارات:** `GET /api/pharmacies` · `GET /api/pharmacies/{id}` · `GET /api/patient/medicines/*` ·
`POST /api/medicines/resolve` · `POST /api/ocr/medicine` · `POST /api/patient/assistant/chat`
— **كلها بنفس المسار والمصادقة والصلاحيات.**

**ملفات الجلسات الموازية لم تُمَس إطلاقًا:** المحاسبة · الماسح · تسجيل دخول الصيدلية ·
`bootstrap/app.php` · `vite.config.js` · `package.json` · `resources/css/**` · `routes/web.php`.

---

## 4) API readiness — كيف صار Laravel جاهزًا لـExternal AI API

**لا يوجد AI داخل المشروع، ومع ذلك الربط المستقبلي جاهز — لأن نقطة الفصل موجودة ومعرَّفة:**

```
Flutter ──{message, latitude?, longitude?, radius_km?, sort?}──▶ Laravel
                                                                   │
                       ┌───────────────────────────────────────────┴──────────────┐
                       │  MedicineIntentService  ← الحدّ الوحيد للـAI              │
                       │    ├─ MedicineIntentClient ──▶ External AI API (لاحقًا)  │
                       │    │     POST {DAWAY_AI_BASE_URL}/ai/assistant {message} │
                       │    └─ heuristic() ← fallback محلي حتمي (يعمل بلا شبكة)   │
                       └───────────────────────────┬──────────────────────────────┘
                                                   ▼
                                        MedicineResolver::resolveCandidates()
                                                   ▼
                                        PharmacyInventorySearch::forMedicine()
                                                   ▼
                                    Haversine → الترتيب → PharmacyAvailability
                                                   ▼
                                              JSON ──▶ Flutter
```

### العقد المتوقَّع من الـExternal AI — مدعوم اليوم بلا أي تعديل

الرد المطلوب:
```json
{ "intent": "search_medicine",
  "drug_name": "panadol",
  "location": { "required": true, "mode": "nearby" } }
```

**كيف يُستهلك الآن:**

| حقل | كيف يُقرأ | أين |
|---|---|---|
| `intent` | `$analysis['intent']` — يُقارَن بـ`MedicineIntentService::INTENT_MEDICINE_SEARCH` | `PatientAssistantController:81` |
| `drug_name` | `$drugName` → `resolveCandidates()` | `PatientAssistantController` |
| `confidence` / `source` | يُمرَّران في `analysis` للرد | `PatientAssistantController` |

**نقطتا الضبط الوحيدتان لربط الـAI المستقبلي:**

```env
DAWAY_AI_BASE_URL=https://<external-ai-host>
DAWAY_AI_KEY=<token>
DAWAY_AI_TIMEOUT=8
```

⇒ **بلا `DAWAY_AI_BASE_URL` لا يُنفَّذ أي HTTP إطلاقًا**، ويعمل المستخرج المحلي وحده
(`MedicineIntentClient::analyze()` تُرجع `failure()` فورًا عند `empty($baseUrl)`).

### ما يبقى مسؤولية Laravel (مؤكَّد بالكود)

medicine resolution · `medicine_id` · `moh_medicines` · `chatbot_medicines.json` ·
pharmacy inventory · `quantity` · price · pharmacy coordinates · Haversine distance ·
nearest sorting · availability · `is_open_now` · final response · validation ·
authentication · authorization · security

### ثغرة واحدة يجب أن يعرفها من سيبني الـExternal AI

الـ`MedicineIntentClient` الحالي **لا يرسل ولا يقرأ** `location{required,mode}` — يقرأ
`intent`/`drug_name`/`confidence` فقط. لو الـAI الخارجي أرجع `location`، سيُهمَل حتى تُضاف
قراءته. **هذا مقصود الآن** (لم يُطلب بناء الـAI)، وهو التعديل الوحيد المطلوب مستقبلًا:
سطران في `MedicineIntentService::analyze()` + حقل في `MedicineIntentClient::analyze()`.

---

## 5) OTP

**`OTP changed: NO`** ✅

مُتحقَّق منه بثلاث طرق:
1. **لا ملف OTP في قائمة تغييراتي** — لا في المعدَّل (`AM`) ولا المحذوف (`AD`).
2. **ملفات OTP مطابقة للـindex ببصمة SHA** (`A ` = نظيفة):
   `app/Http/Controllers/Api/AuthController.php` · `app/Models/OtpCode.php` ·
   `database/migrations/2026_08_21_000001_add_unique_to_otp_codes_phone_table.php` ·
   `tests/Feature/Api/OtpAuthTest.php` · `tests/Feature/Security/OtpRaceTest.php`
3. `grep` على كل المستودع: **صفر مرجع** لأي رمز يخصّ الـNLP داخل مسار OTP.

---

## 6) Flutter

**`Flutter changed: NO`** ✅

- لا مستودع Flutter داخل هذا المشروع أصلًا (لا `pubspec.yaml`)، ولم يُلمَس أي شيء خارجي.
- **عقد الرد عاد لقيمته الأصلية المتوافقة:** `analysis.intent` = **`medicine_search`**
  (كان قد صار `search_medicine` في المهمة السابقة — **أُعيد**).
  مُتحقَّق: `MedicineIntentService::INTENT_MEDICINE_SEARCH = 'medicine_search'` و
  `AssistantChatTest:721` يؤكّدها.
- **لا حقل رد أُضيف أو أُعيد تسميته** ⇒ صفر أثر على الـFlutter client.

---

## 7) Tests

```
passed:     889
failed:     0
skipped:    0
assertions: 3658
duration:   230.01s
```

| المجموعة | النتيجة |
|---|---|
| `AssistantChatTest` | ✅ ضمن 889 |
| `MedicineResolverTest` (Unit) | ✅ ضمن 889 |
| `MedicineResolveTest` | ✅ ضمن 889 |
| `PharmacySearchRadiusTest` | ✅ ضمن 889 |
| `PatientMedicineAvailabilityTest` | ✅ ضمن 889 |
| الأهداف الخمسة مجتمعة | **71 passed · 291 assertions** |
| `MappingGateEquivalenceTest` | **2 passed · 28 assertions** (حارس تكافؤ إصلاح `lookupMapping`) |
| **الحزمة الكاملة** | **889 passed · 3658 assertions** |
| Python tests | **لا شيء — Python أُزيل** |

> الحزمة الكاملة شُغّلت بعد **استعارة مؤقتة** لـ3 اختبارات تُسقط عملية PHP (عطل بيئي قديم موثَّق،
> لا علاقة له بهذا العمل): `CategorySyncApiTest` · `CategorySyncCommandTest` · `EnrichmentEngineTest`.
> **أُعيدت جميعها وتحقّقتُ من تطابقها ببصمة SHA.**

**قبل/بعد:** 922 → 889 اختبارًا (‑33) · 3785 → 3658 تأكيدًا (‑127) — والفرق بالضبط اختبارات الـNLP المحذوفة.

---

## 8) Git

```
current branch:     develop
uncommitted changes: yes (working tree — لم يُجهَّز أي ملف)
commit:             NO
push:               NO
merge:              NO
```

**حالة الـworking tree:** `981 A` (نظيفة) · `33 AM` · `2 AD` · `27 ??`

**من أصل 33 ملفًا معدَّلًا، ملف واحد فقط من هذه المهمة:** `app/Services/Ai/MedicineResolver.php`
(إصلاح الأداء المُبقى عمدًا). الباقي (32) يعود لجلسات موازية على نفس الشجرة
(المحاسبة · الماسح · تسجيل دخول الصيدلية) — **لم يُمَس أيٌّ منها.**

`AD` (2): `resources/css/auth/auth.css` · `resources/css/auth/auth_login.css` — من جلسة موازية
(مدخلات Vite الميتة)، **ليست منّي**.

---

## 9) ما يجب أن يعرفه من سيبني الـExternal AI

1. **نقطة الربط:** `DAWAY_AI_BASE_URL` (+ `DAWAY_AI_KEY`، `DAWAY_AI_TIMEOUT=8`).
2. **المسار المتوقَّع:** `POST {DAWAY_AI_BASE_URL}/ai/assistant` بجسم `{message}`.
3. **مخرجاته غير موثوقة:** تُفحَص في `MedicineIntentService` قبل أي استخدام.
4. **سياسة المحاولات:** محاولتان مع `sleep(2)` بينهما (خطة مجانية ⇒ 503 لحظي).
5. **الـfallback إلزامي:** أي فشل (شبكة · 503 · JSON تالف · رد فارغ) ⇒ المستخرج المحلي.
6. **لتشغيل `location{required,mode}`:** سطران في `MedicineIntentService::analyze()`
   وحقل في `MedicineIntentClient::analyze()` — لا شيء غيرهما.
7. **ممنوعات:** لا قاعدة بيانات · لا مخزون · لا سعر · لا مسافة · لا GPS للـAI. Laravel هو مصدر الحقيقة.

---

**انتهى التنظيف. لا AI داخل المستودع، والربط المستقبلي جاهز.**
