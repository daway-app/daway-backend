# تشخيص عطل تسجيل دخول الصيدليات من الويب

> **البلاغ:** «بعض الصيدليات كانت تستطيع الدخول ثم توقفت، وصيدليات أخرى ما زالت تعمل.»
> **الرسالة الظاهرة للحسابات المتعطلة:** «بيانات الاعتماد غير صحيحة.»
> **التاريخ:** 2026-09-16 · **الحالة:** تشخيص مكتمل + إصلاحات مُنفَّذة في الـworking tree (لم يُرفع شيء).

---

## 1) الخلاصة — الفرق بين الحسابات

الفرق **ليس عشوائياً**، وهو **طريقة إنشاء الحساب**:

| كيف أُنشئ الحساب | الدخول من الويب | السبب |
|---|---|---|
| **تسجيل ذاتي** (الصيدلية اختارت كلمة مرورها) | ✅ **يعمل** | تعرف كلمة مرورها، والأدمن يراها في صندوق `delivered_*` عند الموافقة |
| **إنشأه الأدمن** من اللوحة | ❌ **لا يعمل** | كلمة المرور تُولَّد ثم **تُفقد** — لا عرض، ولا تسليم، ولا إعادة تعيين |

الرسالة «بيانات الاعتماد غير صحيحة» هي **الرسالة الوحيدة** الخارجة من فرع مقارنة كلمة المرور
في `LoginController:62-66` — أي أن هذه الحسابات تصل للتحقق وتفشل فيه، ولا تصل أبداً لمراحل لاحقة.

---

## 2) الأعطال الثلاثة المؤكدة

### 🔴 العطل #1 — كلمة مرور الصيدلية التي ينشئها الأدمن تُفقد نهائياً

| الخطوة | الدليل |
|---|---|
| تُولَّد كلمة مرور وتُرسل بالـflash بمفاتيح `initial_*` | `PharmacyController.php:115-118` |
| صندوق العرض الوحيد يقرأ `delivered_*` | `resources/views/pharmacies/index.blade.php:336` |
| لا يوجد أي قالب يقرأ `initial_*` | بحث في كل `resources/` ⇒ **صفر نتائج** |
| لا مسار بديل: `pending_password` يبقى `null` ⇒ `deliver()` يُرجع `null` | `PharmacyRegistrationService.php:93-95` |
| مسار الاسترجاع معطوب (500) | `PharmacyController.php:288` — `Crypt` غير مستورد |

**النتيجة:** لا يصل أي إنسان لكلمة المرور. العطل **دائم**، والرسالة مطابقة تماماً للبلاغ.

> التعليق في `index.blade.php:335` يقول صراحةً: «بيانات دخول صيدلية **مسجّلة ذاتياً**» —
> أي أن الصندوق أُعيد توجيهه لمسار التسجيل الذاتي، وبقيت مفاتيح مسار الأدمن يتيمة.

### 🔴 العطل #2 — `resetCredentials` معطوب (500) ⇒ لا مخرج للأدمن

`PharmacyController.php:288` يستدعي `Crypt::encryptString()` بلا استيراد للـfacade
(وقائمة `config/app.php` للـaliases تضم 3 عناصر فقط: `Flare`, `Str`, `DB`).
⇒ كل ضغطة على زر إعادة التعيين = **HTTP 500**، وكلمة المرور لا تتغير (rollback).

### 🔴 العطل #3 — صفحة إكمال البيانات لا يمكن إرسالها ⇒ انحباس دائم

1. `profile_completed_at` أُضيف في `2026_08_23_000002` كـ**nullable بلا backfill** ⇒ كل صيدلية قديمة = `NULL`.
2. `LoginController:88-91` + `EnsureProfileComplete:22-32` يحبسان أي `NULL` في صفحة الإكمال.
3. الصفحة **غير قابلة للإرسال**:
   - `latitude`/`longitude` مطلوبان: `PharmacyProfileCompletionController:62-63` (قبل الإصلاح).
   - الكاتب الوحيد للحقلين هو `applyChange()` — `resources/js/pharmacy_hub.js:48`.
   - ولا تُستدعى إلا من `requestChange()`، الذي يعتمد على `map`/`marker` المُنشأين **داخل**
     `openLocationModal()` فقط.
   - و`openLocationModal()` يخرج فوراً: `pharmacy_hub.js:54` — `const lm=document.getElementById('locationModal'); if(!lm) return;`
   - و`#locationModal` / `#mapConfirmModal` موجودان في `edit.blade.php` **فقط** (189 · 280)
     — **غائبان** عن `complete.blade.php`.

**دليل أن المطوّرين علموا بالعطل:** `Api/PharmacyProfileController.php:109-112`
```php
// حتى لا تنحبس الصيديلة المسجّلة من الموبايل بصفحة الإكمال عند دخولها الويب.
```
وضعوا الحل على جهة **الموبايل** فقط، ولم يصلحوا نموذج الويب نفسه.

**الفرق العملي:** من أكملت عبر الموبايل ⇒ `profile_completed_at` مضبوط ⇒ تعمل.
من تحاول عبر الويب ⇒ محبوسة للأبد (برسالة مختلفة: «يجب إكمال بيانات الصيدلية»).

---

## 3) نطاق التأثير — استعلام الفرز

```sql
SELECT
    p.pharmacy_custom_id, p.pharmacy_name,
    p.is_active AS pharmacy_active, u.is_active AS user_active,
    u.role, u.must_change_password,
    p.profile_completed_at, p.delivered_at,
    p.pending_password IS NOT NULL AS has_pending_password,
    CASE
        WHEN u.role <> 'pharmacy'                THEN 'C- دور غير صيدلية'
        WHEN u.is_active = 0 OR p.is_active = 0  THEN 'D- معطّل'
        WHEN p.profile_completed_at IS NULL      THEN 'B- محبوس في إكمال البيانات'
        WHEN p.delivered_at IS NULL
             AND p.pending_password IS NULL      THEN 'A- بيانات الدخول مفقودة'
        ELSE 'OK- يعمل'
    END AS verdict
FROM pharmacies p
JOIN users u ON u.id = p.user_id
ORDER BY verdict, p.id;
```

---

## 4) الإصلاحات المُنفَّذة

| # | الملف | التغيير |
|---|---|---|
| 1 | `app/Http/Controllers/web/Pharmacy/PharmacyController.php` | إضافة `use Illuminate\Support\Facades\Crypt;` (يُصلح 500) |
| 2 | نفس الملف (115-129) | توحيد مفاتيح الـflash: `initial_*` → `delivered_*` ⇒ الأدمن يرى كلمة المرور فعلاً |
| 3 | `app/Http/Controllers/web/Pharmacy/PharmacyProfileCompletionController.php` | `latitude`/`longitude` صارا `nullable` + ثابتان `DEFAULT_LATITUDE/LONGITUDE` + رجوع آمن عند الغياب |
| 4 | `resources/views/pharmacy/profile/complete.blade.php` | الحقلان المخفيان يحملان القيمة الافتراضية ⇒ النموذج يُرسل إحداثيات صالحة حتى لو فشل تحميل الخريطة |
| 5 | `resources/lang/{ar,en}/pharmacy.php` | مفتاح `location_default_hint` — يوضّح أن الموقع مبدئي ويُعدَّل لاحقاً من «تعديل الملف» |
| 6 | `app/Http/Controllers/web/Admin/UserController.php` (`update`) | 🔴 **مزامنة `pharmacies.is_active`** مع حالة المستخدم + تفريغ `pharmacies_list_cache` — نفس منطق `toggleStatus()` |
| 7 | نفس الملف (`update`) | 🔴 **منع تغيير دور حساب مرتبط بصيدلية** — رسالة صريحة بدل الكسر الصامت |
| 8 | `app/Http/Controllers/web/Auth/LoginController.php` | 🔴 **حارس الدور**: `$user->role !== 'pharmacy'` ⇒ رسالة صريحة بدل «نجاح» ثم ارتداد صامت |

### 🔴 عطلان إضافيان (نفس عائلة العرض: «كان يدخل ثم توقف»)

اكتُشفا أثناء استكمال العمل، وهما **مسارَان يكتبان نفس الحقل بسلوك مختلف**:

**أ) `Admin\UserController::update()` لا يزامن `pharmacies.is_active`.**
- `toggleStatus()` (سطر 193-199) **يزامن**، و`Admin\PharmacyController::toggleStatus()` (سطر 237-242) **يزامن**.
- لكن **نموذج تعديل المستخدم** (`update()`, سطر 150) كان يكتب `users.is_active` **وحده**.
- ⇒ الأدمن يعطّل الصيدلية من نموذج التعديل، فيُعطَّل المستخدم وتبقى **الصيدلية مفعّلة** ⇒
  حالة متناقضة صامتة: فحص الدخول يقرأ الاثنين (`! $pharmacy->is_active || ! $user->is_active`)
  فيمرّ، بينما قائمة الصيدليات تُظهرها مفعّلة. وأي منطق آخر يقرأ `pharmacies.is_active` وحده
  يرى الحالة المعاكسة تماماً.

**ب) تغيير دور حساب مرتبط بصيدلية يكسره تماماً.**
- `update()` كان يمنع **فقط** أن يغيّر المستخدم دوره لنفسه (سطر 140) — لا شيء يمنع تغيير دور
  **صيدلية** إلى `patient`.
- مسار الدخول يختار الفرع بحسب `account_type` **من النموذج** لا من قاعدة البيانات ⇒ يجد الصيدلية،
  يتحقق من كلمة المرور بنجاح، يتحقق من التفعيل بنجاح، ثم **`Auth::login()` ينجح**.
- بعده `EnsureRole('pharmacy')` يرى `role === 'patient'` ⇒ لا admin ولا pharmacy ⇒
  `redirect()->route('login.show')` برسالة عامة.
- ⇒ المستخدم يُدخل بياناته الصحيحة **تماماً** فيعود لصفحة الدخول بلا أي تفسير.
  **هذا بالضبط وصف «كان يستطيع الدخول ثم توقف».**

**الإصلاح:** حارسان (سطران 7 و8) + مزامنة الحالة. والحارس في `LoginController` **بعد** التحقق من
كلمة المرور (لا تسريب تعداد) و**قبل** فحص التفعيل (الأدقّ: الحساب ليس حساب صيدلية أصلاً).

**الأدلة:** 5 اختبارات انحدار جديدة:
- `AdminCrudAccessTest::test_editing_user_status_via_form_syncs_pharmacy_is_active`
- `AdminCrudAccessTest::test_admin_cannot_change_role_of_account_linked_to_pharmacy`
- `AdminCrudAccessTest::test_admin_can_still_change_role_of_account_without_pharmacy` (الحارس لا يوسّع نطاقه)
- `PharmacyWebLoginTest::test_pharmacy_account_whose_role_changed_fails_with_clear_message`
- `PharmacyWebLoginTest::test_wrong_password_on_role_changed_account_still_generic` (لا تسريب)

> **تحقّق عكسي (falsification):** عُطِّل حارس الدور مؤقتاً (`if (false && …)`) فسقط الاختبار
> برسالة `Session is missing expected key [errors]` — أي أن الدخول كان ينجح فعلاً بلا خطأ.
> أُعيد الحارس، ورجع يمرّ. ⇒ الاختبارات حقيقية لا زخرفية.

**الاختبارات:** 5 اختبارات انحدار جديدة (إجمالي المجموعة المتأثرة **58 تمرّ**):
- صندوق بيانات الدخول **يُرسم فعلاً** بعد الإنشاء من الأدمن (فحص HTML لا الجلسة) — كان هذا هو الثغرة التي أخفت العطل.
- `resetCredentials` يعمل ويُفلاش كلمة مرور جديدة (كان 500).
- إكمال البيانات **بلا إحداثيات** — كما يرسل المتصفح فعلاً — ينجح ويضبط `profile_completed_at`.
- اللوحة متاحة بعد الإكمال بلا إحداثيات.
- الحقلان المخفيان يحملان القيمة الافتراضية في الـHTML.

**تحقق ما قبل النشر:** `route:cache` ✅ · `view:cache` ✅

---

## 5) ما زال مطلوباً (يحتاج قراراً)

| البند | لماذا |
|---|---|
| **الصيدليات المتعطلة حالياً** | الإصلاح يمنع تكرار العطل للأمام، لكن الحسابات الموجودة تحتاج **إعادة تعيين كلمة مرور** من زر `resetCredentials` بعد النشر (صار يعمل الآن). |
| عرض الخريطة فعلياً في صفحة الإكمال | حالياً الحقلان يحملان قيمة افتراضية، لكن لا خريطة مرئية (الحوارات في `edit.blade.php` فقط) |
| استعادة كلمة المرور عبر OTP | ميزة جديدة لا إصلاح عطل — البنية موجودة (`otp` limiter + `password_reset_tokens`) |

> ✅ **حُسِما:** مزامنة `is_active` عند تعديل مستخدم من اللوحة · منع تغيير `role` لحساب مرتبط
> بصيدلية — انظر الإصلاحان 6 و7 في §4.
