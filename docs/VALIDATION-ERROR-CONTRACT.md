# عقد أخطاء التحقق — قرار معماري مستقل

**الحالة:** مؤجَّل بقرار صريح (المرحلة C، 2026-10-27) · **لا يُنفَّذ داخل إصلاح موضعي**

## المشكلة

أخطاء **الدفتر/الأعمال** في API المحاسبة تُعاد بالشكل الموحّد:

```json
{ "success": false, "message": "…", "data": null }
```

بينما أخطاء **التحقق** (`$request->validate()` ⇒ `ValidationException`) تُعاد بشكل مختلف:

```json
{ "message": "The given data was invalid.", "errors": { "field": ["…"] } }
```

السبب: `bootstrap/app.php` يمرّر `ValidationException` كما هي (لا يُحوّلها)، وLaravel
يعرضها افتراضيًّا بصيغة `{message, errors}` **بلا مفتاح `success`**.

## لماذا لا يُصلَح الآن

1. **عبر-المشروع بالكامل:** كل متحكم يستخدم `$request->validate()` — أي تحويل
   مركزي يغيّر شكل استجابة **كل** مسار في المنصة دفعة واحدة.
2. **مستهلكون متعددون:** Flutter وReact Pharmacy Web يقرآن `success` اليوم في
   مسارات كثيرة؛ تغيير الشكل يحتاج جولة تحقق على العميلين معًا.
3. **البديل الصحيح معماريًّا** ليس معالجًا في `bootstrap/app.php`، بل:
   - `FormRequest` موحّد (`ApiFormRequest`) يبني `failedValidation()` بالشكل
     المعياري، **أو**
   - معالج `ValidationException` مركزي + تحديث اختبارات العقد في المنصة.
   كلاهما قرار مستقل يستحق جولته الخاصة.

## ما يترتب على التأجيل (مقبول ومعلوم)

- عميل يقرأ `success` من رد 422 لا يجده ⇒ عليه الاعتماد على كود HTTP أو `errors`.
- أي إصلاح مستقبلي يجب أن يحدّث **معًا**: المتحكمات + اختبارات العقد + وثائق
  `docs/*API-CONTRACT*` + تطبيق Flutter وواجهة React.

## مرجع

- `bootstrap/app.php` — معالجات الاستثناءات (`AuthenticationException` ·
  `AccessDeniedHttpException` · `ThrottleRequestsException` · `Throwable`).
- `tests/Feature/Api/AccountingApiTest.php` — يؤكد شكل أخطاء الدفتر الموحّد.
