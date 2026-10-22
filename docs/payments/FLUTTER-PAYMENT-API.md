# دليل تكامل الدفع للمريض — تطبيق Flutter

> **الجمهور:** مطوّر تطبيق Flutter المنفصل.
> **النطاق:** واجهات المريض فقط لدفع الطلب بالتحويل البنكي اليدوي.
> **المرجع الملزم:** هذا المستند يصف السلوك الفعلي للكود في هذا المستودع. العقد المجمّد موجود في
> `docs/payments/PAYMENT-PHASE-CONTRACT.md`، لكن عند أي تعارض بين العقد والكود فالكود هو
> المرجع، وقد وُضّحت الفروق في القسم 9 بعنوان «ملاحظات تنفيذ».
>
> المستند عربي واتجاهه من اليمين إلى اليسار. جميع أسماء الحقول والمسارات والقيم التقنية
> مكتوبة بالإنجليزية داخل `backticks` أو داخل كتل JSON، ويجب الالتزام بها حرفيًا.

---

## 1. النطاق

- الدفع يدوي بالتحويل البنكي فقط. لا توجد بوابة دفع إلكتروني في هذه المرحلة.
- المريض يختار بنكًا نشطًا، يرفع إيصال التحويل، ويمكنه تسجيل بلاغ عن مشكلة في التحويل.
- الدفع على مستوى **الطلب الأب** (`orders`)، وليس على مستوى الطلب الفرعي.
- الإجمالي المطلوب دفعه هو `orders.total + orders.delivery_fee`، ويعيده الخادم باسم
  `grand_total`.
- رفع الإيصال **لا يعني أن الدفع تم**. رفع الإيصال ينقل حالة الدفع إلى `under_review` فقط.
  الاعتماد النهائي (`paid`) يتم حصريًا بموافقة الأدمن.
- تسجيل البلاغ لا يؤكد الدفع ولا يلغي الطلب.
- مسارات `/api/admin/payments*` و`/api/admin/payment-banks*` و`/api/admin/payment-problems*`
  مخصّصة للوحة تحكم React، وليست جزءًا من تطبيق Flutter ولا من هذا المستند.
- خارج النطاق أيضًا: إنشاء الطلب، تسجيل الدخول/OTP، إدارة البنوك، مراجعة الأدمن،
  الاسترجاع المالي، وتقارير المحاسبة.

---

## 2. المصادقة والاتفاقيات العامة

### 2.1 المصادقة

كل مسارات المريض التالية محمية بـ Laravel Sanctum:

```http
Authorization: Bearer <patient-sanctum-token>
Accept: application/json
```

- التوكن يُحصل عليه من تدفق تسجيل دخول المريض الحالي (OTP) خارج نطاق هذا المستند.
- الخادم يتحقق من أن `users.role === "patient"` داخل المتحكم نفسه.
- بدون توكن صالح: **401**.
- بتوكن صالح لدور آخر (admin/pharmacy/rider): **403**.

> **مهم:** أرسل `Accept: application/json` في كل طلب، بما فيها طلبات التنزيل.
> بعض أخطاء 403/404/422 لا تُعاد كـ JSON إذا غاب هذا الهيدر، وقد يعود HTML أو تحويل 302.
> هذا مذكور أيضًا في قائمة التحقق.

### 2.2 الاتفاقيات

| البند | القيمة |
|---|---|
| بادئة المسارات | `/api` |
| نوع الطلب | `application/json` للطلبات العادية، و`multipart/form-data` لرفع الإيصال |
| معرّفات المسارات | أرقام صحيحة: `order` = `orders.id`، `submission` = `order_payment_submissions.id` |
| التواريخ | `created_at` و`reviewed_at` بصيغة `Y-m-d H:i:s` وبتوقيت **UTC** |
| تاريخ التحويل | `transferred_at` بصيغة `Y-m-d` فقط |
| العملة | الشيكل `₪` كبقية المشروع؛ لا يوجد حقل `currency` في استجابات الدفع |
| حالة الدفع | `orders.payment_status` هي مصدر الحقيقة |
| حالة الإيصال | `order_payment_submissions.status` مستقلة عن حالة الطلب |
| حالة البلاغ | `open` أو `resolved` |

### 2.3 حدود المعدل

- الطلبات العامة تحت `throttle:api`: نحو 60 طلبًا/دقيقة لكل مستخدم.
- `POST` لرفع الإيصال و`POST` لتسجيل البلاغ تحت `throttle:writes`: نحو 20 طلبًا/دقيقة
  لكل مستخدم.
- عند تجاوز الحد يعود **429** مع `Retry-After`. تعامل معه بإعادة محاولة مؤجلة، ولا تعتبره
  فشلًا نهائيًا.

---

## 3. مرجع المسارات

| # | Method | المسار الكامل | الغرض | النجاح |
|---|---|---|---|---|
| 1 | `GET` | `/api/patient/payment-banks` | البنوك النشطة المتاحة للتحويل | `200` |
| 2 | `GET` | `/api/patient/orders/{order}/payment` | ملخص دفع الطلب وحالة آخر إيصال والبلاغ المفتوح | `200` |
| 3 | `POST` | `/api/patient/orders/{order}/payment/receipt` | رفع إيصال تحويل جديد (multipart) | `201` |
| 4 | `POST` | `/api/patient/orders/{order}/payment/problem` | تسجيل بلاغ عن مشكلة في التحويل | `201` |
| 5 | `GET` | `/api/patient/orders/{order}/payment/submissions/{submission}/receipt` | تنزيل الإيصال المحمي | `200` ملف ثنائي |

كل المسارات تتطلب `Authorization: Bearer <token>` و`Accept: application/json`.

---

### 3.1 `GET /api/patient/payment-banks`

**الغرض:** جلب البنوك النشطة فقط، مرتبة حسب `sort_order` ثم `id`.

**المدخلات:** لا يوجد body ولا معاملات استعلام.

**النجاح:** `200`

```json
{
  "success": true,
  "message": "تم جلب بنوك الدفع المتاحة بنجاح.",
  "data": [
    {
      "id": 1,
      "bank_name": "بنك فلسطين",
      "account_holder": "شركة دواي للتجارة الإلكترونية",
      "account_number": "123456789",
      "iban": "PS92PALS000000000123456789",
      "branch": "فرع رام الله - الماصيون",
      "instructions": "يرجى كتابة رقم الطلب في حقل ملاحظات التحويل، ثم رفع صورة الإيصال.",
      "is_active": true,
      "sort_order": 1
    }
  ]
}
```

**شكل `data`:** مصفوفة مباشرة من كائنات البنك، وليست كائنًا يحتوي على `banks`.
هذا يختلف عن استجابة `data.banks` داخل مسار ملخص الدفع.

**حقول كائن البنك:**

| الحقل | النوع | ملاحظات |
|---|---|---|
| `id` | `integer` | استخدمه في `bank_id` عند الرفع |
| `bank_name` | `string` | اسم البنك |
| `account_holder` | `string` | اسم صاحب الحساب |
| `account_number` | `string` | رقم الحساب |
| `iban` | `string \| null` | قد يكون `null` |
| `branch` | `string \| null` | قد يكون `null` |
| `instructions` | `string \| null` | تعليمات التحويل، قد تكون `null` |
| `is_active` | `boolean` | في هذا المسار تكون دائمًا `true` |
| `sort_order` | `integer` | ترتيب العرض |

**ملاحظات مهمة:**

- لا توجد بنوك مبذورة في قاعدة البيانات. قد تعود المصفوفة فارغة. يجب أن تعرض الواجهة
  حالة «لا توجد بنوك متاحة حاليًا» وتُعطّل الرفع حتى يضيف الأدمن بنكًا نشطًا.
- الحد الأقصى للبنوك النشطة هو 3، لكن لا تعتمد على وجود 3 دائمًا.
- لا يوجد ترقيم صفحات لهذا المسار.
- احتفظ بترتيب الخادم؛ لا تُعد ترتيب البنوك محليًا إلا إذا طلب التصميم ذلك.
- لا تفترض أن `iban` أو `branch` أو `instructions` موجودة دائمًا.

**الأخطاء:**

| HTTP | الحالة | الرسالة |
|---|---|---|
| `401` | لا يوجد توكن أو التوكن غير صالح | `يجب تسجيل الدخول` |
| `403` | الدور ليس `patient` | `غير مصرح لك بالوصول` |
| `500` | خطأ غير متوقع | `حدث خطأ داخلي` |

---

### 3.2 `GET /api/patient/orders/{order}/payment`

**الغرض:** جلب ملخص دفع الطلب: الإجمالي، حالة الدفع، آخر إيصال، البلاغ المفتوح، والبنوك
النشطة.

**المدخلات:** لا يوجد body. `{order}` هو `orders.id`.

**النجاح:** `200`

```json
{
  "success": true,
  "message": "تم جلب بيانات الدفع بنجاح.",
  "data": {
    "order_id": 1042,
    "grand_total": 125.5,
    "payment_status": "pending",
    "payment_method": null,
    "can_submit_receipt": true,
    "can_report_problem": true,
    "latest_submission": null,
    "problem": null,
    "banks": []
  }
}
```

**حقول `data`:**

| الحقل | النوع | الشرح |
|---|---|---|
| `order_id` | `integer` | معرّف الطلب الأب |
| `grand_total` | `number` | `orders.total + orders.delivery_fee`، محسوب على الخادم ومقرّب لخانتين |
| `payment_status` | `string` | `pending` أو `under_review` أو `paid` أو `rejected` أو `problem_reported` |
| `payment_method` | `string \| null` | `bank_transfer` بعد أول رفع إيصال، و`null` قبل ذلك |
| `can_submit_receipt` | `boolean` | هل يُسمح للواجهة بإظهار/تفعيل رفع إيصال الآن |
| `can_report_problem` | `boolean` | هل يُسمح للواجهة بإظهار/تفعيل تسجيل بلاغ الآن |
| `latest_submission` | `object \| null` | آخر إيصال بأعلى `id`، أو `null` إذا لم يوجد |
| `problem` | `object \| null` | **البلاغ المفتوح فقط**، أو `null` إذا لا يوجد بلاغ مفتوح |
| `banks` | `array` | البنوك النشطة، بنفس كائن البنك في القسم 3.1 |

> في المثال أعلاه `banks` فارغة لأن لا يوجد بنك نشط. في الواقع يعيد الخادم البنوك
> النشطة دائمًا؛ انظر المثال الكامل في 4.4.

**قواعد يجب معرفتها:**

- `grand_total` هو المرجع المالي للعرض. لا تحسبه محليًا من أسعار السلة.
- `latest_submission` هو آخر إيصال حسب **أعلى `id`**، وليس حسب `created_at`.
- `problem` لا يعرض تاريخ البلاغات المحلولة. عندما يُحل البلاغ يصبح `problem = null`،
  وتُشتق `payment_status` من جديد. لا يوجد في واجهة المريض مسار يعيد سجل البلاغات المحلولة
  أو `admin_note` الخاص بها.
- `banks` تُعاد دائمًا كقائمة البنوك النشطة، حتى لو كانت الحالة `paid` أو `cancelled`.
  الواجهة تستخدمها فقط عندما تكون `can_submit_receipt = true`.
- `payment_method` قد يكون `null` حتى مع `payment_status = "problem_reported"` إذا سجّل
  المريض بلاغًا قبل رفع أي إيصال. لا تفترض أنه دائمًا `bank_transfer`.
- `latest_submission` و`problem` كائنان كاملان؛ انظر القسم 4 لشكلهما الدقيق.

**الأخطاء:**

| HTTP | الحالة | الرسالة |
|---|---|---|
| `401` | لا يوجد توكن أو التوكن غير صالح | `يجب تسجيل الدخول` |
| `403` | الدور ليس `patient` | `غير مصرح لك بالوصول` |
| `404` | الطلب غير موجود إطلاقًا (route model binding) | `غير موجود` |
| `404` | الطلب موجود لكنه لا يملكه هذا المريض | `الطلب غير موجود.` مع `data: []` |
| `500` | خطأ غير متوقع | `حدث خطأ داخلي` |

---

### 3.3 `POST /api/patient/orders/{order}/payment/receipt`

**الغرض:** رفع إيصال تحويل بنكي جديد. هذا الطلب **multipart/form-data** وليس JSON.

**المسار:** `/api/patient/orders/{order}/payment/receipt`

**الحقول:**

| الحقل | النوع | مطلوب | قواعد التحقق | ملاحظات |
|---|---|---|---|---|
| `bank_id` | `integer` | نعم | `exists:payment_banks,id`، ويجب أن يكون البنك نشطًا | استخدم `id` من قائمة البنوك |
| `amount` | `number` | نعم | `numeric`، `min:0`، `max:99999999.99` | المبلغ المعلن؛ الصفر مقبول تقنيًا |
| `receipt` | `file` | نعم | `mimes:jpg,jpeg,png,webp,pdf`، `max:5120` كيلوبايت | الحد الأقصى 5 MB |
| `sender_name` | `string` | لا | `max:100` | اسم المُحوِّل |
| `transfer_reference` | `string` | لا | `max:100` | رقم/مرجع الحوالة |
| `transferred_at` | `date` | لا | `before_or_equal:today` | تاريخ التحويل، وليس بعد اليوم |

**أنواع الملفات المقبولة:**

- الامتدادات: `jpg`، `jpeg`، `png`، `webp`، `pdf`.
- أنواع MIME المتوقعة: `image/jpeg`، `image/png`، `image/webp`، `application/pdf`.
- الحد الأقصى: **5120 KB** (5 MB) كما يفرضه الخادم.
- يُفضّل أن تتحقق الواجهة من النوع والحجم قبل الإرسال لتقليل الأخطاء، لكن الخادم هو
  المرجع النهائي.

**مثال multipart كامل:**

```http
POST /api/patient/orders/1042/payment/receipt HTTP/1.1
Host: <api-host>
Authorization: Bearer <token>
Accept: application/json
Content-Type: multipart/form-data; boundary=----DawayBoundary

------DawayBoundary
Content-Disposition: form-data; name="bank_id"

1
------DawayBoundary
Content-Disposition: form-data; name="amount"

125.5
------DawayBoundary
Content-Disposition: form-data; name="sender_name"

محمد أحمد
------DawayBoundary
Content-Disposition: form-data; name="transfer_reference"

TRX-20261021-9981
------DawayBoundary
Content-Disposition: form-data; name="transferred_at"

2026-10-21
------DawayBoundary
Content-Disposition: form-data; name="receipt"; filename="receipt-1042.jpg"
Content-Type: image/jpeg

<بايتات الصورة>
------DawayBoundary--
```

> لا تكتب `Content-Type: multipart/form-data` يدويًا بدون `boundary`. استخدم
> `MultipartRequest` في Dart أو `FormData` في Dio أو `curl -F`، واترك المكتبة تولّد
> الـboundary تلقائيًا.

**مثال `curl`:**

```bash
curl -X POST "$BASE_URL/patient/orders/1042/payment/receipt" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -F "bank_id=1" \
  -F "amount=125.5" \
  -F "receipt=@receipt-1042.jpg;type=image/jpeg" \
  -F "sender_name=محمد أحمد" \
  -F "transfer_reference=TRX-20261021-9981" \
  -F "transferred_at=2026-10-21"
```

> `BASE_URL` يشمل بادئة `/api`، مثال: `https://<api-host>/api`.

**النجاح:** `201`

```json
{
  "success": true,
  "message": "تم رفع إيصال التحويل بنجاح، وهو قيد المراجعة.",
  "data": {
    "submission": {
      "id": 8,
      "order_id": 1042,
      "bank_id": 1,
      "bank_name": "بنك فلسطين",
      "account_holder": "شركة دواي للتجارة الإلكترونية",
      "account_number": "123456789",
      "iban": "PS92PALS000000000123456789",
      "amount": 125.5,
      "sender_name": "محمد أحمد",
      "transfer_reference": "TRX-20261021-9981",
      "transferred_at": "2026-10-21",
      "receipt_original_name": "receipt-1042.jpg",
      "receipt_mime": "image/jpeg",
      "receipt_size": 184320,
      "status": "under_review",
      "review_note": null,
      "rejection_reason": null,
      "reviewed_at": null,
      "created_at": "2026-10-21 14:35:12"
    },
    "payment_status": "under_review"
  }
}
```

**ما يحدث على الخادم بعد النجاح:**

1. يُحفظ الملف على القرص الخاص `local` داخل `payment_receipts/`، ولا يُعاد أي رابط له.
2. تُنشأ `order_payment_submission` بحالة `under_review`.
3. تُحفظ **لقطة** من بيانات البنك (`bank_name`, `account_holder`, `account_number`, `iban`)
   وقت الرفع، حتى لو عدّل الأدمن البنك لاحقًا.
4. تتحول `orders.payment_status` إلى `under_review`، و`payment_method` إلى `bank_transfer`.
5. تُصفَّر حقول مراجعة/رفض/بلاغ سابقة على الطلب الأب.
6. تُنسخ حالة الدفع إلى الطلبات الفرعية غير الملغاة للعرض فقط.
7. **لا** تتحول الحالة إلى `paid`.

**الأخطاء:**

| HTTP | الحالة | الرسالة |
|---|---|---|
| `401` | لا يوجد توكن أو التوكن غير صالح | `يجب تسجيل الدخول` |
| `403` | الدور ليس `patient` | `غير مصرح لك بالوصول` |
| `404` | الطلب غير موجود إطلاقًا | `غير موجود` |
| `404` | الطلب لا يملكه هذا المريض | `الطلب غير موجود.` مع `data: []` |
| `409` | يوجد إيصال `under_review` لهذا الطلب | `يوجد إيصال قيد المراجعة لهذا الطلب.` |
| `422` | حقول ناقصة أو غير صالحة | جسم Laravel القياسي: `message` + `errors` |
| `422` | الطلب ملغى | `لا يمكن إرسال إيصال لطلب ملغى.` |
| `422` | الدفع معتمد بالفعل | `الدفع معتمد بالفعل — لا يمكن إرسال إيصال جديد.` |
| `422` | يوجد بلاغ مفتوح (وبلا إيصال قيد المراجعة) | `يوجد بلاغ دفع مفتوح لهذا الطلب. انتظر حله قبل إرسال إيصال.` |
| `422` | البنك غير نشط | `البنك المحدد غير متاح.` |
| `422` | البنك غير موجود (حالة سباق نادرة) | `البنك المحدد غير موجود.` |
| `500` | فشل حفظ الملف | `تعذّر حفظ الإيصال. أعد المحاولة.` |
| `500` | استثناء تخزين/قاعدة بيانات غير متوقع | `حدث خطأ داخلي` |
| `429` | تجاوز حد الكتابة | جسم 429 الافتراضي؛ راعِ `Retry-After` |

**ملاحظات تنفيذ مهمة:**

- **لا يطابق الخادم `amount` مع `grand_total`.** التحقق هو `min:0` و`max:99999999.99`
  فقط. المطابقة تتم يدويًا أثناء مراجعة الأدمن. أرسل المبلغ الذي حوّلته فعلًا، ولا تعتمد
  على رفض الخادم لفرق المبلغ. هذه نقطة تعارض مع صياغة قائمة التحقق في الطلب، والكود هو
  المرجع.
- `bank_id` يجب أن يكون لبنك **نشط** وقت الرفع. إذا كانت الشاشة مفتوحة وعُطّل البنك
  لاحقًا، سيعود `422` برسالة `البنك المحدد غير متاح.`؛ حدّث قائمة البنوك واطلب اختيار
  بنك آخر.
- إذا كان `bank_id` **غير موجود إطلاقًا**، فالتحقق `exists` يعيد `422` مع
  `errors.bank_id`، وليس رسالة `البنك المحدد غير موجود.`. الأخيرة تخص حالة سباق نادرة
  بعد نجاح التحقق وقبل القفل على الصف.
- لا ترسل حقولًا غير مذكورة مثل `payment_status` أو `order_id` أو `receipt_path`؛
  الخادم لا يحتاجها.
- إذا حُذف البنك لاحقًا، قد يصبح `submission.bank_id = null` في الاستجابات التاريخية
  بسبب `nullOnDelete`، لكن حقول اللقطة تبقى. لا تعتمد على `bank_id` لعرض إيصال قديم.

---

### 3.4 `POST /api/patient/orders/{order}/payment/problem`

**الغرض:** تسجيل بلاغ عن مشكلة في التحويل (بيانات البنك، المبلغ، الإيصال، أو سبب آخر).

**نوع الطلب:** `application/json`.

**الحقول:**

| الحقل | النوع | مطلوب | قواعد التحقق | ملاحظات |
|---|---|---|---|---|
| `category` | `string` | نعم | `in:bank_details,amount,receipt,other` | فئة البلاغ |
| `message` | `string` | نعم | `max:2000` | وصف المشكلة |
| `submission_id` | `integer` | لا | `exists:order_payment_submissions,id`، ويجب أن يتبع نفس الطلب | اختياري |

**قيم `category`:**

| القيمة | المعنى المقترح للعرض |
|---|---|
| `bank_details` | مشكلة في بيانات البنك |
| `amount` | مشكلة في المبلغ/التحويل |
| `receipt` | مشكلة في الإيصال |
| `other` | مشكلة أخرى |

**مثال جسم الطلب:**

```json
{
  "category": "amount",
  "message": "حوّلت المبلغ كاملًا 125.5 شيكل، لكن المبلغ الظاهر في الإيصال يختلف بسبب رسوم التحويل. أرجو مراجعة التحويل.",
  "submission_id": 8
}
```

**النجاح:** `201`

```json
{
  "success": true,
  "message": "تم تسجيل بلاغ الدفع بنجاح.",
  "data": {
    "problem": {
      "id": 3,
      "order_id": 1042,
      "submission_id": 8,
      "category": "amount",
      "message": "حوّلت المبلغ كاملًا 125.5 شيكل، لكن المبلغ الظاهر في الإيصال يختلف بسبب رسوم التحويل. أرجو مراجعة التحويل.",
      "status": "open",
      "admin_note": null,
      "resolved_by": null,
      "resolved_at": null,
      "created_at": "2026-10-21 15:02:44"
    },
    "payment_status": "problem_reported"
  }
}
```

**ما يحدث على الخادم بعد النجاح:**

1. يُنشأ `payment_problem` بحالة `open`.
2. تتحول `orders.payment_status` إلى `problem_reported`.
3. يُسجَّل `payment_problem_reported_at` على الطلب الأب.
4. تُنسخ الحالة إلى الطلبات الفرعية غير الملغاة للعرض فقط.
5. البلاغ **لا** يؤكد الدفع ولا يلغي الطلب.
6. بينما البلاغ مفتوح، لا يستطيع الأدمن اعتماد الإيصال أو رفضه؛ المحاولة تعيد `422`.

**الأخطاء:**

| HTTP | الحالة | الرسالة |
|---|---|---|
| `401` | لا يوجد توكن أو التوكن غير صالح | `يجب تسجيل الدخول` |
| `403` | الدور ليس `patient` | `غير مصرح لك بالوصول` |
| `404` | الطلب غير موجود إطلاقًا | `غير موجود` |
| `404` | الطلب لا يملكه هذا المريض | `الطلب غير موجود.` مع `data: []` |
| `422` | `submission_id` لا يتبع هذا الطلب | جسم Laravel القياسي: `message` + `errors` |
| `409` | يوجد بلاغ مفتوح بالفعل | `يوجد بلاغ مفتوح لهذا الطلب بالفعل.` |
| `422` | حقول ناقصة أو غير صالحة | جسم Laravel القياسي: `message` + `errors` |
| `422` | الطلب ملغى | `لا يمكن تسجيل بلاغ على طلب ملغى.` |
| `422` | الدفع معتمد بالفعل | `الدفع معتمد بالفعل — لا يمكن تسجيل بلاغ.` |
| `500` | خطأ غير متوقع | `حدث خطأ داخلي` |
| `429` | تجاوز حد الكتابة | جسم 429 الافتراضي؛ راعِ `Retry-After` |

**ملاحظات تنفيذ مهمة:**

- القيد هو **بلاغ مفتوح واحد لكل طلب**، وليس بلاغًا واحدًا في العمر. بعد حل البلاغ يمكن
  فتح بلاغ جديد إذا سمحت الحالة.
- يمكن تسجيل بلاغ من الحالات التي يعرضها الملخص: `pending` أو `under_review` أو `rejected`
  ما دام لا يوجد بلاغ مفتوح. هذا يشمل حالة `pending` قبل رفع أي إيصال.
- إذا كان هناك إيصال قيد المراجعة، يمكن تسجيل بلاغ أثناء المراجعة؛ عندها تصبح حالة الطلب
  `problem_reported` بينما يبقى آخر إيصال بحالة `under_review`.
- `submission_id` اختياري. إذا لم يُرسل، يُنشأ البلاغ بدون ربط بإيصال. وجود بلاغ مفتوح
  يمنع الاعتماد/الرفض حتى لو لم يكن مرتبطًا بإيصال.
- إذا كان `submission_id` **غير موجود إطلاقًا**، فالتحقق `exists` يعيد `422` مع
  `errors.submission_id`، وليس `404`. الـ`404` برسالة `الإيصال غير موجود.` يخص إيصالًا
  موجودًا لكنه تابع لطلب آخر.
- إذا كانت `payment_status = paid` أو الطلب ملغى، لا يمكن تسجيل بلاغ.
- لا تفترض أن حالة الإيصال ستتغير إلى `problem_reported`؛ هذه حالة **الطلب**، وليست حالة
  الإيصال.

---

### 3.5 `GET /api/patient/orders/{order}/payment/submissions/{submission}/receipt`

**الغرض:** تنزيل ملف الإيصال المحمي.

**المدخلات:**

| المعامل | النوع | الشرح |
|---|---|---|
| `order` | `integer` | `orders.id` |
| `submission` | `integer` | `order_payment_submissions.id` |

**النجاح:** `200` مع جسم ثنائي (ملف)، وليس JSON.

```http
HTTP/1.1 200 OK
Content-Type: image/jpeg
Content-Disposition: attachment; filename="receipt-1042.jpg"
Content-Length: 184320

<بايتات الملف>
```

- `Content-Type` هو `receipt_mime` المحفوظ وقت الرفع.
- اسم الملف في `Content-Disposition` هو `receipt_original_name`.
- لا يعيد الخادم `receipt_path` ولا رابطًا عامًا.
- الملف مخزّن على القرص الخاص `local`، والوصول إليه يتطلب التوكن والملكية.

**الأخطاء:**

| HTTP | الحالة | الرسالة |
|---|---|---|
| `401` | لا يوجد توكن أو التوكن غير صالح | `يجب تسجيل الدخول` |
| `403` | الدور ليس `patient` | `غير مصرح لك بالوصول` |
| `404` | الطلب لا يملكه هذا المريض | `الإيصال غير موجود.` |
| `404` | الإيصال لا يتبع هذا الطلب | `الإيصال غير موجود.` |
| `404` | ملف الإيصال غير موجود على القرص | `ملف الإيصال غير موجود.` |
| `404` | الطلب/الإيصال غير موجود إطلاقًا | `غير موجود` |
| `500` | خطأ غير متوقع | `حدث خطأ داخلي` |

**ملاحظات تنفيذ مهمة:**

- التنزيل مسموح طالما أن الطلب يملكه المريض والإيصال يتبع الطلب والملف موجود، **بغض النظر
  عن حالة الإيصال أو حالة الطلب**. لذلك يمكن تنزيل إيصال مرفوض أو معتمد، ويمكن تنزيل إيصال
  طلب ملغى.
- لا تستخدم `Image.network` أو أي أداة تحميل لا ترسل هيدر `Authorization`. نفّذ طلب GET
  موثّقًا واكتب البايتات إلى ملف محلي، ثم اعرضها/شاركها.
- لا تُخزّن التوكن داخل رابط التنزيل أو في سجل التطبيق.

---

## 4. نماذج JSON كاملة

جميع الأمثلة أدناه مبنية على مُولِّدات الاستجابة الفعلية في
`app/Services/PaymentFlowService.php`، مع قيم عربية واقعية. المفاتيح يجب أن تبقى كما هي.

### 4.1 كائن البنك

```json
{
  "id": 1,
  "bank_name": "بنك فلسطين",
  "account_holder": "شركة دواي للتجارة الإلكترونية",
  "account_number": "123456789",
  "iban": "PS92PALS000000000123456789",
  "branch": "فرع رام الله - الماصيون",
  "instructions": "يرجى كتابة رقم الطلب في حقل ملاحظات التحويل، ثم رفع صورة الإيصال.",
  "is_active": true,
  "sort_order": 1
}
```

### 4.2 استجابة قائمة البنوك

```json
{
  "success": true,
  "message": "تم جلب بنوك الدفع المتاحة بنجاح.",
  "data": [
    {
      "id": 1,
      "bank_name": "بنك فلسطين",
      "account_holder": "شركة دواي للتجارة الإلكترونية",
      "account_number": "123456789",
      "iban": "PS92PALS000000000123456789",
      "branch": "فرع رام الله - الماصيون",
      "instructions": "يرجى كتابة رقم الطلب في حقل ملاحظات التحويل، ثم رفع صورة الإيصال.",
      "is_active": true,
      "sort_order": 1
    }
  ]
}
```

### 4.3 كائن الإيصال `submission`

```json
{
  "id": 8,
  "order_id": 1042,
  "bank_id": 1,
  "bank_name": "بنك فلسطين",
  "account_holder": "شركة دواي للتجارة الإلكترونية",
  "account_number": "123456789",
  "iban": "PS92PALS000000000123456789",
  "amount": 125.5,
  "sender_name": "محمد أحمد",
  "transfer_reference": "TRX-20261021-9981",
  "transferred_at": "2026-10-21",
  "receipt_original_name": "receipt-1042.jpg",
  "receipt_mime": "image/jpeg",
  "receipt_size": 184320,
  "status": "under_review",
  "review_note": null,
  "rejection_reason": null,
  "reviewed_at": null,
  "created_at": "2026-10-21 14:35:12"
}
```

ملاحظات على الحقول:

- `bank_id` قد يصبح `null` تاريخيًا إذا حُذف البنك، بينما تبقى حقول اللقطة.
- `sender_name` و`transfer_reference` و`transferred_at` قد تكون `null`.
- `review_note` و`rejection_reason` و`reviewed_at` قد تكون `null`.
- `status` هنا واحد من `under_review` أو `approved` أو `rejected` فقط.
- `receipt_size` بالبايت، و`receipt_mime` هو النوع المحفوظ وقت الرفع.
- `created_at` و`reviewed_at` بتوقيت UTC.

### 4.4 ملخص الدفع — حالة `pending` بدون إيصال

```json
{
  "success": true,
  "message": "تم جلب بيانات الدفع بنجاح.",
  "data": {
    "order_id": 1042,
    "grand_total": 125.5,
    "payment_status": "pending",
    "payment_method": null,
    "can_submit_receipt": true,
    "can_report_problem": true,
    "latest_submission": null,
    "problem": null,
    "banks": [
      {
        "id": 1,
        "bank_name": "بنك فلسطين",
        "account_holder": "شركة دواي للتجارة الإلكترونية",
        "account_number": "123456789",
        "iban": "PS92PALS000000000123456789",
        "branch": "فرع رام الله - الماصيون",
        "instructions": "يرجى كتابة رقم الطلب في حقل ملاحظات التحويل، ثم رفع صورة الإيصال.",
        "is_active": true,
        "sort_order": 1
      }
    ]
  }
}
```

### 4.5 ملخص الدفع — حالة `under_review` مع آخر إيصال

```json
{
  "success": true,
  "message": "تم جلب بيانات الدفع بنجاح.",
  "data": {
    "order_id": 1042,
    "grand_total": 125.5,
    "payment_status": "under_review",
    "payment_method": "bank_transfer",
    "can_submit_receipt": false,
    "can_report_problem": true,
    "latest_submission": {
      "id": 8,
      "order_id": 1042,
      "bank_id": 1,
      "bank_name": "بنك فلسطين",
      "account_holder": "شركة دواي للتجارة الإلكترونية",
      "account_number": "123456789",
      "iban": "PS92PALS000000000123456789",
      "amount": 125.5,
      "sender_name": "محمد أحمد",
      "transfer_reference": "TRX-20261021-9981",
      "transferred_at": "2026-10-21",
      "receipt_original_name": "receipt-1042.jpg",
      "receipt_mime": "image/jpeg",
      "receipt_size": 184320,
      "status": "under_review",
      "review_note": null,
      "rejection_reason": null,
      "reviewed_at": null,
      "created_at": "2026-10-21 14:35:12"
    },
    "problem": null,
    "banks": [
      {
        "id": 1,
        "bank_name": "بنك فلسطين",
        "account_holder": "شركة دواي للتجارة الإلكترونية",
        "account_number": "123456789",
        "iban": "PS92PALS000000000123456789",
        "branch": "فرع رام الله - الماصيون",
        "instructions": "يرجى كتابة رقم الطلب في حقل ملاحظات التحويل، ثم رفع صورة الإيصال.",
        "is_active": true,
        "sort_order": 1
      }
    ]
  }
}
```

### 4.6 استجابة رفع الإيصال

```json
{
  "success": true,
  "message": "تم رفع إيصال التحويل بنجاح، وهو قيد المراجعة.",
  "data": {
    "submission": {
      "id": 8,
      "order_id": 1042,
      "bank_id": 1,
      "bank_name": "بنك فلسطين",
      "account_holder": "شركة دواي للتجارة الإلكترونية",
      "account_number": "123456789",
      "iban": "PS92PALS000000000123456789",
      "amount": 125.5,
      "sender_name": "محمد أحمد",
      "transfer_reference": "TRX-20261021-9981",
      "transferred_at": "2026-10-21",
      "receipt_original_name": "receipt-1042.jpg",
      "receipt_mime": "image/jpeg",
      "receipt_size": 184320,
      "status": "under_review",
      "review_note": null,
      "rejection_reason": null,
      "reviewed_at": null,
      "created_at": "2026-10-21 14:35:12"
    },
    "payment_status": "under_review"
  }
}
```

### 4.7 كائن البلاغ `problem`

```json
{
  "id": 3,
  "order_id": 1042,
  "submission_id": 8,
  "category": "amount",
  "message": "حوّلت المبلغ كاملًا 125.5 شيكل، لكن المبلغ الظاهر في الإيصال يختلف بسبب رسوم التحويل. أرجو مراجعة التحويل.",
  "status": "open",
  "admin_note": null,
  "resolved_by": null,
  "resolved_at": null,
  "created_at": "2026-10-21 15:02:44"
}
```

### 4.8 استجابة تسجيل البلاغ

```json
{
  "success": true,
  "message": "تم تسجيل بلاغ الدفع بنجاح.",
  "data": {
    "problem": {
      "id": 3,
      "order_id": 1042,
      "submission_id": 8,
      "category": "amount",
      "message": "حوّلت المبلغ كاملًا 125.5 شيكل، لكن المبلغ الظاهر في الإيصال يختلف بسبب رسوم التحويل. أرجو مراجعة التحويل.",
      "status": "open",
      "admin_note": null,
      "resolved_by": null,
      "resolved_at": null,
      "created_at": "2026-10-21 15:02:44"
    },
    "payment_status": "problem_reported"
  }
}
```

### 4.9 ملخص الدفع — حالة `problem_reported`

```json
{
  "success": true,
  "message": "تم جلب بيانات الدفع بنجاح.",
  "data": {
    "order_id": 1042,
    "grand_total": 125.5,
    "payment_status": "problem_reported",
    "payment_method": "bank_transfer",
    "can_submit_receipt": false,
    "can_report_problem": false,
    "latest_submission": {
      "id": 8,
      "order_id": 1042,
      "bank_id": 1,
      "bank_name": "بنك فلسطين",
      "account_holder": "شركة دواي للتجارة الإلكترونية",
      "account_number": "123456789",
      "iban": "PS92PALS000000000123456789",
      "amount": 125.5,
      "sender_name": "محمد أحمد",
      "transfer_reference": "TRX-20261021-9981",
      "transferred_at": "2026-10-21",
      "receipt_original_name": "receipt-1042.jpg",
      "receipt_mime": "image/jpeg",
      "receipt_size": 184320,
      "status": "under_review",
      "review_note": null,
      "rejection_reason": null,
      "reviewed_at": null,
      "created_at": "2026-10-21 14:35:12"
    },
    "problem": {
      "id": 3,
      "order_id": 1042,
      "submission_id": 8,
      "category": "amount",
      "message": "حوّلت المبلغ كاملًا 125.5 شيكل، لكن المبلغ الظاهر في الإيصال يختلف بسبب رسوم التحويل. أرجو مراجعة التحويل.",
      "status": "open",
      "admin_note": null,
      "resolved_by": null,
      "resolved_at": null,
      "created_at": "2026-10-21 15:02:44"
    },
    "banks": []
  }
}
```

> في المثال أعلاه `banks` فارغة للاختصار فقط. في الواقع يعيد الخادم البنوك النشطة دائمًا،
> حتى في حالة `problem_reported`. لا تعتمد على كونها فارغة.

### 4.10 الفروق في حالتي `rejected` و`paid`

عند الرفض:

```json
{
  "payment_status": "rejected",
  "payment_method": "bank_transfer",
  "can_submit_receipt": true,
  "can_report_problem": true,
  "latest_submission": {
    "status": "rejected",
    "review_note": null,
    "rejection_reason": "المبلغ المحوّل لا يطابق إجمالي الطلب.",
    "reviewed_at": "2026-10-21 16:10:05"
  },
  "problem": null
}
```

عند الاعتماد:

```json
{
  "payment_status": "paid",
  "payment_method": "bank_transfer",
  "can_submit_receipt": false,
  "can_report_problem": false,
  "latest_submission": {
    "status": "approved",
    "review_note": "تم التحقق من التحويل.",
    "rejection_reason": null,
    "reviewed_at": "2026-10-21 16:20:00"
  },
  "problem": null
}
```

هذه مقتطفات من كائن `data` وليست استجابة كاملة؛ الحقول الأخرى تبقى كما في الأمثلة السابقة.

### 4.11 سلوك تنزيل الإيصال

```http
GET /api/patient/orders/1042/payment/submissions/8/receipt HTTP/1.1
Host: <api-host>
Authorization: Bearer <token>
Accept: application/json

HTTP/1.1 200 OK
Content-Type: image/jpeg
Content-Disposition: attachment; filename="receipt-1042.jpg"
Content-Length: 184320

<بايتات الصورة>
```

- لا تحاول تحليل جسم الاستجابة كـ JSON عندما تكون الحالة `200` ونوع المحتوى ليس JSON.
- عند الفشل (401/403/404/500) يكون الجسم JSON كما في القسم 6.

---

## 5. آلة حالات الدفع

### 5.1 القيم الممكنة

**`orders.payment_status`:**

| القيمة | المعنى |
|---|---|
| `pending` | لم يُرفع إيصال بعد، أو لا يوجد إيصال نشط |
| `under_review` | يوجد إيصال قيد المراجعة |
| `paid` | تم اعتماد الدفع من الأدمن — حالة نهائية |
| `rejected` | تم رفض آخر إيصال |
| `problem_reported` | يوجد بلاغ مفتوح على الطلب |

**`order_payment_submissions.status`:**

| القيمة | المعنى |
|---|---|
| `under_review` | قيد المراجعة |
| `approved` | معتمد (يعني عادةً أن الطلب `paid`) |
| `rejected` | مرفوض، ويحمل `rejection_reason` |

**`payment_problems.status`:**

| القيمة | المعنى |
|---|---|
| `open` | بلاغ مفتوح |
| `resolved` | بلاغ محلول (لا يعرضه مسار المريض بعد الحل) |

**`payment_problems.category`:** `bank_details`، `amount`، `receipt`، `other`.

### 5.2 الانتقالات

| من | الحدث | إلى | الشرط/النتيجة |
|---|---|---|---|
| `pending` | رفع إيصال | `under_review` | لا يوجد إيصال `under_review`، ولا بلاغ مفتوح، والطلب ليس ملغى/مدفوعًا، والبنك نشط |
| `rejected` | إعادة رفع إيصال | `under_review` | نفس الشروط؛ يبقى الإيصال المرفوض السابق في السجل |
| `under_review` | موافقة الأدمن | `paid` | آخر إيصال `under_review`، ولا بلاغ مفتوح، والطلب ليس ملغى |
| `under_review` | رفض الأدمن | `rejected` | آخر إيصال `under_review`، ولا بلاغ مفتوح، مع سبب رفض |
| `pending` | تسجيل بلاغ | `problem_reported` | لا بلاغ مفتوح، والطلب ليس ملغى/مدفوعًا |
| `under_review` | تسجيل بلاغ | `problem_reported` | لا بلاغ مفتوح، والطلب ليس ملغى/مدفوعًا؛ يبقى الإيصال `under_review` |
| `rejected` | تسجيل بلاغ | `problem_reported` | لا بلاغ مفتوح، والطلب ليس ملغى/مدفوعًا |
| `problem_reported` | حل البلاغ | `paid` أو `under_review` أو `rejected` أو `pending` | تُشتق من حالة آخر إيصال كما في 5.3 |
| `paid` | أي إجراء دفع من المريض | `paid` | نهائي: الرفع/البلاغ يعيدان `422`؛ موافقة الأدمن المتكررة ناجحة بلا تغيير |
| أي حالة مع طلب `cancelled` | رفع إيصال أو بلاغ | لا تغيير | `422`؛ تنزيل إيصال قديم يبقى مسموحًا |

### 5.3 اشتقاق الحالة بعد حل البلاغ

عند حل البلاغ، يعيد الخادم اشتقاق `orders.payment_status` من **آخر إيصال** (أعلى `id`):

| حالة آخر إيصال | الحالة المشتقة |
|---|---|
| `approved` | `paid` |
| `under_review` | `under_review` |
| `rejected` | `rejected` |
| لا يوجد إيصال (أو حالة أخرى) | `pending` |

- يُصفَّر `payment_problem_reported_at`.
- إذا كانت الحالة المشتقة `rejected`، يُعاد تعيين `payment_rejection_reason` من آخر إيصال.
- إذا كانت الحالة المشتقة غير ذلك، يُصفَّر `payment_rejection_reason`.
- في التدفق الطبيعي لا يمكن أن يكون آخر إيصال `approved` مع بلاغ مفتوح، لأن الاعتماد
  محجوب أثناء البلاغ. لكن الكود يشتق `paid` إذا حدث ذلك لأي سبب.

### 5.4 قاعدة «آخر إيصال»

- `latest_submission` في ملخص المريض هو الإيصال ذو **أعلى `id`** للطلب، وليس الأحدث
  زمنيًا بالضرورة. في التطبيق العملي هما متطابقان لأن الإيصالات تُنشأ بالتسلسل.
- الأدمن يعتمد/يرفض **آخر إيصال فقط**، ويجب أن يكون `under_review`.
- رفع إيصال جديد ممنوع إذا وُجد **أي** إيصال `under_review` للطلب، وليس فقط إذا كان
  الأخير كذلك. هذا يفرض قاعدة «إيصال واحد قيد المراجعة لكل طلب».
- بعد الرفض، يبقى الإيصال المرفوض في السجل، ويصبح آخر إيصال `rejected`. عند إعادة الرفع
  يُنشأ إيصال جديد `under_review` ويصبح هو «آخر إيصال».
- لا يوجد في واجهة المريض مسار يعيد كل الإيصالات؛ فقط `latest_submission`، مع إمكانية
  تنزيل أي إيصال بمعرّفه إذا كان يتبع الطلب.

### 5.5 قاعدة «بلاغ مفتوح واحد»

- لا يمكن إنشاء بلاغ جديد إذا وُجد بلاغ `open` للطلب؛ المحاولة تعيد `409`.
- وجود بلاغ مفتوح يمنع رفع إيصال جديد: إذا وُجد إيصال `under_review` أيضًا يُعاد `409`
  أولًا (فحص التكرار)، وإلا يُعاد `422` بسبب البلاغ المفتوح.
- وجود بلاغ مفتوح يمنع اعتماد/رفض الإيصال من الأدمن (`422`).
- يمكن فتح بلاغ جديد بعد حل البلاغ السابق، ما دامت حالة الدفع تسمح بذلك.
- حل البلاغ لا يغيّر حالة الإيصال نفسه؛ يغيّر حالة الطلب المشتقة.

### 5.6 صيغة `can_submit_receipt` و`can_report_problem`

هذه هي الصيغة الفعلية في `PaymentFlowService::patientPayment()`:

```text
can_submit_receipt =
    order.status != "cancelled"
    AND order.payment_status != "paid"
    AND latest_submission?.status != "under_review"
    AND open_problem == null
```

```text
can_report_problem =
    order.status != "cancelled"
    AND order.payment_status != "paid"
    AND open_problem == null
    AND order.payment_status IN ["pending", "under_review", "rejected"]
```

ملاحظات:

- `can_submit_receipt` لا يفحص قائمة حالات مسموحة صراحةً؛ هو يعتمد على غياب
  `under_review`/`paid`/`cancelled`/البلاغ المفتوح. في الحالات الطبيعية يطابق
  `pending` و`rejected`.
- `can_submit_receipt` في الملخص يعتمد على **آخر إيصال** فقط. أما `POST` فيرفض إذا وُجد
  أي إيصال `under_review`. تحت قاعدة الإيصال الواحد قيد المراجعة، النتيجتان متطابقتان.
- `can_report_problem` يكون `true` في `pending` و`under_review` و`rejected` ما دام لا يوجد
  بلاغ مفتوح. لذلك يمكن للمريض الإبلاغ حتى قبل رفع أي إيصال، وأثناء المراجعة.
- بعد حل البلاغ، تُعاد الحسابات من الحالة المشتقة؛ قد يعود `can_report_problem = true`
  مباشرة إذا كانت الحالة `pending` أو `under_review` أو `rejected`.
- `paid` نهائي: القيمتان `false`.
- الطلب الملغى: القيمتان `false`، لكن تنزيل إيصال قديم يبقى مسموحًا.

### 5.7 الرسم المختصر

```text
pending ──رفع إيصال──▶ under_review ──موافقة──▶ paid (نهائي)
   │                        │
   │                        └──رفض──▶ rejected ──إعادة رفع──▶ under_review
   │
   ├──بلاغ──▶ problem_reported ──حل──▶ (paid | under_review | rejected | pending)
   │
rejected ──بلاغ──▶ problem_reported
under_review ──بلاغ──▶ problem_reported
```

---

## 6. الأخطاء

### 6.1 أشكال جسم الخطأ الفعلية

ليست كل الأخطاء بالشكل نفسه. يجب أن يتعامل التطبيق مع الأشكال التالية:

**أ) خطأ عمل من الخدمة (409/422/500 وغيرها):**

```json
{
  "success": false,
  "message": "يوجد إيصال قيد المراجعة لهذا الطلب.",
  "data": []
}
```

**ب) خطأ تحقق Laravel (422):**

```json
{
  "message": "حقل receipt مطلوب.",
  "errors": {
    "receipt": [
      "حقل receipt مطلوب."
    ]
  }
}
```

> **ملاحظة تنفيذ:** جسم تحقق 422 **لا يحتوي** على `success` ولا `data`. أسماء الحقول
> تبقى إنجليزية (`bank_id`, `amount`, `receipt`, ...)، أما نص الرسالة فيتبع لغة الطلب
> (`?lang=ar` أو `Accept-Language`). أرسل `Accept-Language: ar` إذا أردت رسائل عربية،
> ولا تعتمد على نص الرسالة في منطق التطبيق. لا تفترض وجود `errors` في أخطاء العمل، ولا
> وجود `success` في أخطاء التحقق. إذا أردت نموذجًا موحّدًا، اجمع `success = false` و
> `message` و`errors` (إن وجدت) في طبقة Flutter.

**ج) خطأ مصادقة/صلاحية:**

```json
{
  "success": false,
  "message": "يجب تسجيل الدخول"
}
```

```json
{
  "success": false,
  "message": "غير مصرح لك بالوصول"
}
```

**د) 404 من route model binding (المعرّف غير موجود إطلاقًا):**

```json
{
  "success": false,
  "message": "غير موجود"
}
```

**هـ) 404 ملكية أو إيصال غير تابع:**

```json
{
  "success": false,
  "message": "الطلب غير موجود.",
  "data": []
}
```

```json
{
  "success": false,
  "message": "الإيصال غير موجود.",
  "data": []
}
```

**و) 500 فشل حفظ الإيصال:**

```json
{
  "success": false,
  "message": "تعذّر حفظ الإيصال. أعد المحاولة.",
  "data": []
}
```

**ز) 500 عام:**

```json
{
  "success": false,
  "message": "حدث خطأ داخلي"
}
```

### 6.2 جدول الأخطاء

| HTTP | متى | الرسالة/الجسم | ملاحظات |
|---|---|---|---|
| `401` | لا توكن، توكن منتهٍ، أو غير صالح | `يجب تسجيل الدخول` | أعد تسجيل الدخول |
| `403` | الدور ليس `patient` | `غير مصرح لك بالوصول` | لا تعد المحاولة بنفس التوكن |
| `404` | `order`/`submission` غير موجود إطلاقًا | `غير موجود` | route model binding |
| `404` | الطلب يخص مريضًا آخر | `الطلب غير موجود.` + `data: []` | عدم كشف الوجود |
| `422` | `submission_id` في البلاغ لا يتبع الطلب | `message` + `errors` | تحقق مسبق |
| `404` | `submission` في مسار التنزيل لا يتبع الطلب | `الإيصال غير موجود.` | قد يكون بدون `data` |
| `404` | ملف الإيصال مفقود على القرص | `ملف الإيصال غير موجود.` | في التنزيل |
| `409` | يوجد إيصال `under_review` | `يوجد إيصال قيد المراجعة لهذا الطلب.` | لا تعِد المحاولة فورًا؛ انتظر المراجعة |
| `409` | يوجد بلاغ مفتوح | `يوجد بلاغ مفتوح لهذا الطلب بالفعل.` | لا تفتح بلاغًا ثانيًا |
| `422` | حقول ناقصة/غير صالحة | `message` + `errors` | اعرض أخطاء الحقول |
| `422` | طلب ملغى — إيصال | `لا يمكن إرسال إيصال لطلب ملغى.` | زر الرفع يجب أن يكون مخفيًا أصلًا |
| `422` | طلب ملغى — بلاغ | `لا يمكن تسجيل بلاغ على طلب ملغى.` | — |
| `422` | دفع معتمد — إيصال | `الدفع معتمد بالفعل — لا يمكن إرسال إيصال جديد.` | — |
| `422` | دفع معتمد — بلاغ | `الدفع معتمد بالفعل — لا يمكن تسجيل بلاغ.` | — |
| `422` | بلاغ مفتوح يمنع رفع إيصال (وبلا إيصال `under_review`) | `يوجد بلاغ دفع مفتوح لهذا الطلب. انتظر حله قبل إرسال إيصال.` | إذا وُجد إيصال `under_review` أيضًا يُعاد `409` أولًا |
| `422` | بنك غير نشط | `البنك المحدد غير متاح.` | حدّث قائمة البنوك |
| `422` | بنك غير موجود (سباق) | `البنك المحدد غير موجود.` | حدّث القائمة |
| `500` | فشل حفظ الإيصال | `تعذّر حفظ الإيصال. أعد المحاولة.` | يمكن إعادة المحاولة |
| `500` | خطأ غير متوقع | `حدث خطأ داخلي` | لا تعرض تفاصيل تقنية للمريض |
| `429` | تجاوز الحد | جسم 429 الافتراضي | استخدم `Retry-After` |

### 6.3 ترتيب الأخطاء في طلبات POST

- التحقق من الحقول (`$request->validate`) يحدث **قبل** فحص ملكية الطلب داخل الخدمة.
- لذلك، إذا أرسلت حقولًا غير صالحة إلى طلب لا يملكه المريض، قد يعود `422` قبل `404`.
  لا تعتمد على ترتيب الأخطاء ولا تستنتج ملكية الطلب من نوع الخطأ.
- بعد اجتياز التحقق، فحص الملكية يعيد `404` برسالة `الطلب غير موجود.` كما هو موضح.

---

## 7. التدفق الكامل للمريض

### 7.1 السيناريو الطبيعي

1. **بعد إنشاء الطلب:** يكون `payment_status = "pending"` و`payment_method = null`.
2. **جلب البنوك:** `GET /api/patient/payment-banks`.
   - إذا كانت القائمة فارغة: اعرض «لا توجد بنوك متاحة حاليًا» وعطّل زر رفع الإيصال.
   - إذا كانت غير فارغة: اعرض البنوك بترتيب الخادم.
3. **فتح شاشة الدفع:** `GET /api/patient/orders/{order}/payment`.
   - اعرض `grand_total` من الخادم.
   - اعرض `payment_status` و`latest_submission` و`problem`.
   - استخدم `can_submit_receipt` و`can_report_problem` لتحديد الأزرار.
4. **تعبئة النموذج:**
   - اختر `bank_id` من القائمة.
   - ضع `amount` افتراضيًا على `grand_total`، واسمح للمريض بتعديله إذا كان المبلغ المحوّل
     مختلفًا.
   - `sender_name` و`transfer_reference` و`transferred_at` اختيارية.
   - أرفق `receipt` مع التحقق المحلي من النوع (`jpg/jpeg/png/webp/pdf`) والحجم (`<= 5120 KB`).
5. **رفع الإيصال:** `POST /api/patient/orders/{order}/payment/receipt` كـmultipart.
   - عند `201`: خزّن `data.submission.id`، واعرض «الإيصال قيد المراجعة».
   - `payment_status` يصبح `under_review`.
   - `can_submit_receipt` يصبح `false`، و`can_report_problem` يبقى `true`.
6. **المتابعة/الاستطلاع:** أعد جلب `GET /api/patient/orders/{order}/payment` عند فتح
   الشاشة، وعند العودة من الخلفية، ويمكن كل 20–30 ثانية أثناء بقاء الشاشة مرئية.
   لا توجد قناة دفع لحظية (WebSocket/Push) في هذه المرحلة.
7. **حالات النتيجة:**
   - `under_review`: استمر في عرض «قيد المراجعة».
   - `paid`: اعرض «تم اعتماد الدفع» وأوقف إجراءات الدفع.
   - `rejected`: اعرض `latest_submission.rejection_reason`، وأعد تفعيل إعادة الرفع
     (`can_submit_receipt = true`).
   - `problem_reported`: اعرض البلاغ المفتوح، وأوقف الرفع والبلاغ حتى الحل.
8. **عند الحاجة لبلاغ:** `POST /api/patient/orders/{order}/payment/problem` مع
   `category` و`message`، ويمكن تمرير `submission_id` (آخر إيصال).
   - عند `201`: اعرض «بلاغ مفتوح» وانتظر حل الإدارة.
   - البلاغ لا يؤكد الدفع ولا يلغي الطلب.
9. **بعد حل البلاغ:** أعد جلب الملخص. يعود `problem = null` وتُشتق الحالة:
   - `under_review`: انتظر المراجعة، ويمكن فتح بلاغ جديد.
   - `rejected`: اعرض السبب وأعد الرفع.
   - `pending`: يمكن رفع إيصال جديد.
   - `paid`: تم الاعتماد.
10. **تنزيل الإيصال:** `GET /api/patient/orders/{order}/payment/submissions/{submission}/receipt`
    مع التوكن. احفظ البايتات محليًا واعرضها/شاركها. لا يوجد رابط عام.

### 7.2 ماذا تعرض الواجهة حسب الأعلام

| `can_submit_receipt` | `can_report_problem` | الحالة النموذجية | العرض المقترح |
|---|---|---|---|
| `true` | `true` | `pending` بدون إيصال | زر «رفع إيصال» + زر «الإبلاغ عن مشكلة» |
| `true` | `true` | `rejected` | سبب الرفض + «إعادة رفع الإيصال» + «الإبلاغ عن مشكلة» |
| `false` | `true` | `under_review` | «الإيصال قيد المراجعة» + «الإبلاغ عن مشكلة» |
| `false` | `false` | `problem_reported` | «بلاغ مفتوح» + لا إجراءات دفع |
| `false` | `false` | `paid` | «تم اعتماد الدفع» + تنزيل الإيصال |
| `false` | `false` | `cancelled` | «الطلب ملغى» + لا إجراءات دفع |

**قواعد عرض إضافية:**

- لا تستنتج الأزرار من `payment_status` وحدها؛ استخدم `can_submit_receipt` و
  `can_report_problem` كما يعيدهما الخادم.
- إذا كانت `can_submit_receipt = true` لكن `banks` فارغة، عطّل الرفع واعرض رسالة
  «لا توجد بنوك متاحة».
- إذا كان `latest_submission = null`، لا تعرض بيانات إيصال.
- إذا كان `latest_submission.status = "rejected"`، اعرض `rejection_reason` بوضوح.
- إذا كان `latest_submission.status = "approved"`، اعرض `review_note` إن وُجد.
- إذا كان `problem != null`، اعرض `category` و`message` و`created_at`، وبيّن أن الحل
  قيد المعالجة. لا تعرض `admin_note` لأنه لا يكون متاحًا إلا بعد الحل، وعندها يختفي
  البلاغ من الاستجابة.
- لا تعرض زر «تم الدفع» بعد الرفع؛ الاعتماد فقط عندما يصبح `payment_status = "paid"`.
- عند `rejected`، يمكن للمريض إما إعادة رفع إيصال أو فتح بلاغ. اعرض الخيارين حسب الأعلام.
- لا تفترض أن `problem` سيبقى ظاهرًا بعد الحل؛ بعد الحل يصبح `null` وتُعرض الحالة المشتقة.
- لا تعرض `receipt_path` أو أي رابط مباشر؛ استخدم مسار التنزيل الموثّق فقط.

### 7.3 مثال تسلسل مختصر

```text
GET  /api/patient/payment-banks
GET  /api/patient/orders/1042/payment
POST /api/patient/orders/1042/payment/receipt      (multipart)
GET  /api/patient/orders/1042/payment               (polling/متابعة)
POST /api/patient/orders/1042/payment/problem       (عند الحاجة)
GET  /api/patient/orders/1042/payment               (بعد الحل)
GET  /api/patient/orders/1042/payment/submissions/8/receipt  (تنزيل)
```

---

## 8. قائمة التحقق للتكامل

- [ ] استخدم المسارات الخمسة أعلاه حرفيًا، ولا تستخدم أي مسار إداري.
- [ ] أرسل `Authorization: Bearer <token>` و`Accept: application/json` في كل طلب.
- [ ] لا تضبط `Content-Type` يدويًا لطلب multipart؛ اترك المكتبة تولّد الـboundary.
- [ ] استخدم `bank_id` من استجابة `payment-banks`، وحدّث القائمة عند `422` الخاص بالبنك.
- [ ] أرسل الحقول المطلوبة: `bank_id`، `amount`، `receipt`.
- [ ] الحقول الاختيارية بأسمائها الدقيقة: `sender_name`، `transfer_reference`،
      `transferred_at`.
- [ ] تحقق محليًا من أن الإيصال من نوع `jpg/jpeg/png/webp/pdf` وحجمه لا يتجاوز
      `5120 KB`، مع إبقاء تحقق الخادم هو المرجع.
- [ ] استخدم `grand_total` من الخادم للعرض. أرسل `amount` كما حوّله المريض فعلًا.
      **لا يطابق الخادم `amount` مع `grand_total`**؛ المطابقة تتم في مراجعة الأدمن.
- [ ] لا تعتبر `201` من رفع الإيصال تأكيدًا للدفع؛ انتظر `payment_status = "paid"`.
- [ ] استخدم `can_submit_receipt` و`can_report_problem` لتحديد الأزرار، لا حالة
      `payment_status` وحدها.
- [ ] تعامل مع `409` كحالة «موجود بالفعل» وليس كخطأ عام: إيصال قيد المراجعة أو بلاغ مفتوح.
- [ ] تعامل مع `422` التحقق عبر `errors` (بدون `success`)، ومع `422` العمل عبر `message`
      و`data` (بدون `errors`).
- [ ] لا تعتمد على نص رسالة التحقق؛ أرسل `Accept-Language: ar` إذا أردت رسائل عربية،
      واربط العرض بمفاتيح `errors`.
- [ ] لا تنشئ روابط إيصالات عامة، ولا تستخدم `receipt_path`، ولا تسجّل التوكن أو روابط
      التنزيل.
- [ ] استخدم طلب GET موثّقًا لتنزيل الإيصال، واكتب البايتات محليًا.
- [ ] تعامل مع `created_at` و`reviewed_at` كتوقيت UTC، واعرضها بتوقيت فلسطين.
- [ ] `transferred_at` تاريخ فقط، و«اليوم» محسوب بتوقيت الخادم UTC.
- [ ] اعرض بيانات البنك من **لقطة الإيصال** (`submission.bank_*`) وليس من قائمة البنوك
      الحالية عند عرض إيصال قديم.
- [ ] لا ترسل حقولًا غير موجودة في هذا المستند، ولا تفترض حقولًا مثل `receipt_url` أو
      `payment_confirmed`.
- [ ] لا تستخدم مسارات الأدمن من تطبيق المريض.

---

## 9. ملاحظات تنفيذ وفروق بين العقد والكود

هذه ملاحظات مقصودة لمنع الالتباس. كل عبارة هنا مبنية على الكود الفعلي.

### 9.1 `can_report_problem` في `pending` وأثناء المراجعة

الكود يجعله `true` عندما تكون الحالة `pending` أو `under_review` أو `rejected`، بشرط ألا
يكون الطلب ملغى أو مدفوعًا ولا يوجد بلاغ مفتوح. العقد المجمّد حُدّث ليطابق هذا السلوك.

**الأثر على Flutter:** يمكن للمريض فتح بلاغ حتى قبل رفع أي إيصال، وأثناء وجود إيصال قيد
المراجعة. لا تخفِ زر البلاغ في هاتين الحالتين.

### 9.2 اعتماد/رفض الإيصال محجوب أثناء البلاغ المفتوح

الكود يرفض اعتماد أو رفض الإيصال إذا وُجد بلاغ `open`، ويعيد `422`، ويعيد
`can_approve`/`can_reject` بالقيمة `false`. العقد المجمّد يوثّق هذا القيد الآن.

**الأثر على Flutter:** بعد تسجيل بلاغ، لا تنتظر تغير حالة الإيصال إلى `approved` أو
`rejected` قبل حل البلاغ. انتظر `problem = null` ثم أعد جلب الحالة.

### 9.3 `can_submit_receipt` يعتمد على آخر إيصال، والرفع يفحص أي إيصال

- الملخص يحسب `can_submit_receipt` باستخدام `latest_submission` فقط.
- `POST` يرفض الرفع إذا وُجد **أي** إيصال `under_review` للطلب (`409`).

تحت قاعدة «إيصال واحد قيد المراجعة»، النتيجتان متطابقتان. إذا حدثت بيانات غير متسقة
(أكثر من إيصال قيد المراجعة)، فقد يقول الملخص `true` بينما يعيد `POST` `409`. اعتمد على
استجابة `POST` عند التعارض.

### 9.4 «آخر إيصال» = أعلى `id`

العلاقة `latestOfMany()` تستخدم أعلى `id`، وليس `created_at`. في التطبيق الطبيعي
الإيصالات متسلسلة، لكن لا تعتمد على `created_at` لترتيب الإيصالات في العميل.

### 9.5 حل البلاغ قد يشتق `paid`

العقد يذكر أن حل البلاغ يعيد الحالة المشتقة إلى `under_review` أو `rejected` أو `pending`.
الكود يشتق أيضًا `paid` إذا كان آخر إيصال `approved`. في التدفق الطبيعي لا يحدث ذلك لأن
الاعتماد محجوب أثناء البلاغ، لكن الكود يدعمها كحالة دفاعية.

**الأثر على Flutter:** بعد حل البلاغ، لا تفترض أن الحالة ستكون واحدة من ثلاث فقط؛ تعامل
مع `paid` أيضًا.

### 9.6 البلاغ المعروض للمريض هو المفتوح فقط

`patientPayment()` يحمّل `openPaymentProblem` فقط. بعد الحل:
- يصبح `problem = null`.
- لا يُعاد `admin_note` ولا `resolved_at` للمريض.
- لا يوجد مسار للمريض يعيد سجل البلاغات المحلولة.

**الأثر على Flutter:** لا تعتمد على وجود `problem` لعرض تاريخ البلاغات. بعد الحل، اعرض
الحالة المشتقة فقط. وإذا كان التصميم يتطلب عرض ملاحظة الأدمن، فهذه الإمكانية غير متاحة في
واجهة المريض الحالية.

### 9.7 `amount` لا يُطابق مع `grand_total`

التحقق الفعلي:

```text
amount: required | numeric | min:0 | max:99999999.99
```

لا توجد مقارنة مع `grand_total` ولا مع `orders.total + delivery_fee`. المبلغ المعلن
معلوماتي، والمطابقة تتم يدويًا في مراجعة الأدمن. هذه نقطة تعارض مع صياغة قائمة التحقق
التي تفترض أن «الخادم يتحقق من إجمالي الطلب الأب»؛ الكود الحالي لا يفعل ذلك.

**الأثر على Flutter:** لا تنتظر `422` بسبب فرق المبلغ. أرسل المبلغ الفعلي، واعرض
`grand_total` للمريض لتقليل الالتباس.

### 9.8 `payment_method` قد يكون `null` مع `problem_reported`

إذا سجّل المريض بلاغًا قبل رفع أي إيصال، يتحول `payment_status` إلى `problem_reported`
لكن `payment_method` يبقى `null`، لأن البلاغ لا يضبطه. لا تفترض أن `problem_reported`
تعني دائمًا `bank_transfer`.

### 9.9 تنزيل الإيصال مسموح للطلب الملغى

الكود يفحص الملكية ووجود الملف فقط، ولا يفحص `status` الطلب أو `status` الإيصال. لذلك
يمكن تنزيل إيصال قديم حتى لو أُلغي الطلب. هذا لا يغيّر قاعدة منع رفع إيصال جديد أو تسجيل
بلاغ جديد على طلب ملغى.

### 9.10 التحقق من الدور داخل المتحكم وليس في middleware المسار

المسارات موجودة داخل مجموعة `auth:sanctum` فقط، والتحقق من `users.role === "patient"`
يحدث داخل `OrderPaymentController` عبر `abort_unless`. النتيجة للمريض نفسه هي `403` عند
الدور الخاطئ، لكن آلية التنفيذ تختلف عن صياغة العقد «patient role middleware».

**الأثر على Flutter:** لا شيء عمليًا؛ تعامل مع `403` كما هو موصوف. لكن لا تفترض أن
`role:patient` موجود في تعريف المسار.

### 9.11 جسم 422 التحقق لا يحتوي `success`

أخطاء التحقق تعود من Laravel بالشكل:

```json
{
  "message": "...",
  "errors": {
    "field": ["..."]
  }
}
```

بينما أخطاء العمل تعود بالشكل:

```json
{
  "success": false,
  "message": "...",
  "data": []
}
```

لا يوجد جسم واحد يجمع `success` و`message` و`errors` معًا في الكود الحالي. إذا كان
التطبيق يريد نموذجًا موحّدًا، فليبنِه في طبقة العميل من الشكلين أعلاه.

### 9.12 «اليوم» في `transferred_at` بتوقيت UTC

`before_or_equal:today` يستخدم منطقة زمنية للتطبيق هي `UTC` (في `config/app.php`).
إذا كان المريض في فلسطين وأرسل تاريخ اليوم المحلي قرب منتصف الليل، فقد يختلف عن تاريخ
UTC. استخدم تاريخ التحويل الفعلي، وتجنّب إرسال تاريخ مستقبلي.

### 9.13 لا يوجد مسار لقائمة كل الإيصالات

واجهة المريض تعرض `latest_submission` فقط. لا يوجد `GET submissions` للمريض. إذا احتاج
التطبيق عرض إيصال سابق، فيجب أن يكون قد حفظ `submission.id` من استجابة الرفع أو من
`latest_submission`، ثم يستخدم مسار التنزيل. لا تفترض وجود قائمة كاملة.

### 9.14 لا توجد قناة دفع لحظية

لا يوجد في هذا النطاق WebSocket أو Push خاص بتغير حالة الدفع. الاعتماد على إعادة جلب
`GET /api/patient/orders/{order}/payment` (عند فتح الشاشة، عند العودة من الخلفية، أو
باستطلاع معقول أثناء العرض). لا تستطلع بكثافة عالية؛ احترم `429`.

### 9.15 `bank_id` قد يصبح `null` في الإيصالات التاريخية

مفتاح البنك في جدول الإيصالات قابل للأنواع `nullable` مع `nullOnDelete`. إذا حُذف البنك
بعد الرفع، تبقى حقول اللقطة (`bank_name`, `account_holder`, `account_number`, `iban`)
لكن `bank_id` قد يعود `null`. اعرض اللقطة، ولا تعتمد على `bank_id` للعرض التاريخي.

---

## 10. مرجع سريع للحالات والرسائل

### 10.1 الحالات

```text
order.payment_status:
  pending | under_review | paid | rejected | problem_reported

submission.status:
  under_review | approved | rejected

problem.status:
  open | resolved

problem.category:
  bank_details | amount | receipt | other

payment_method:
  null | bank_transfer
```

### 10.2 رسائل النجاح

| العملية | HTTP | الرسالة |
|---|---|---|
| قائمة البنوك | `200` | `تم جلب بنوك الدفع المتاحة بنجاح.` |
| ملخص الدفع | `200` | `تم جلب بيانات الدفع بنجاح.` |
| رفع الإيصال | `201` | `تم رفع إيصال التحويل بنجاح، وهو قيد المراجعة.` |
| تسجيل البلاغ | `201` | `تم تسجيل بلاغ الدفع بنجاح.` |
| تنزيل الإيصال | `200` | جسم ثنائي، بلا رسالة JSON |

### 10.3 رسائل الأخطاء الأكثر تكرارًا

```text
يجب تسجيل الدخول
غير مصرح لك بالوصول
غير موجود
الطلب غير موجود.
الإيصال غير موجود.
ملف الإيصال غير موجود.
يوجد إيصال قيد المراجعة لهذا الطلب.
يوجد بلاغ مفتوح لهذا الطلب بالفعل.
لا يمكن إرسال إيصال لطلب ملغى.
لا يمكن تسجيل بلاغ على طلب ملغى.
الدفع معتمد بالفعل — لا يمكن إرسال إيصال جديد.
الدفع معتمد بالفعل — لا يمكن تسجيل بلاغ.
يوجد بلاغ دفع مفتوح لهذا الطلب. انتظر حله قبل إرسال إيصال.
البنك المحدد غير متاح.
البنك المحدد غير موجود.
تعذّر حفظ الإيصال. أعد المحاولة.
حدث خطأ داخلي
```

---

**نهاية المستند.**
