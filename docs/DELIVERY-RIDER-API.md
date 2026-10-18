# Daway — Rider Delivery API Contract

> واجهة برمجية لتسليم الطلبات عبر مندوب (Rider Delivery). هذه الوثيقة موجّهة
> لمطوّر تطبيق الموبايل الخاص بالمندوب. **Backend + API فقط** — لا واجهة ويب.

- Base URL: `{APP_URL}/api`
- Content-Type: `application/json`
- كل الاستجابات JSON. الرسائل بالعربية. الأخطاء تحمل عادةً `success: false` + `message`.
- المصادقة: **Laravel Sanctum** (Bearer token).

---

## 1. نظرة عامة على النموذج

- الطلب **الأب** (`order`) يُسند إلى **مندوب واحد**، حتى لو ضمّ طلبات فرعية من عدة صيدليات.
- الصيدلية تملك دورة التحضير حتى حالة `ready` لكل `sub_order`.
- المندوب يملك مراحل التوصيل على الطلب **الأب**: `picked_up → out_for_delivery → delivered`.
- الاستلام يُتتبّع **لكل `sub_order`** على حدة (`picked_up_at`)، والطلب الأب لا يصبح
  `picked_up` إلا بعد استلام **كل** الطلبات الفرعية النشطة (غير الملغاة).
- الرسوم (`delivery_fee`) محسوبة مرة واحدة على الأب منذ الـcheckout — **لا تتغير** بدورة المندوب.

---

## 2. الأدوار والصلاحيات

| الدور | القيمة | الوصول |
|---|---|---|
| أدمن | `admin` | إدارة المندوبين + عرض الطلبات + إسناد/إعادة إسناد/فك إسناد |
| مندوب | `rider` | طلباته المُسندة فقط: عرض · استلام · بدء توصيل · تسليم |

- المندوب حساب `users.role = 'rider'` في نظام المستخدمين الحالي (لا جدول منفصل).
- حماية المسارات: `auth:sanctum` + `role:admin` أو `role:rider`.
- المندوب **لا يرى ولا يعدّل** أي طلب غير مُسند إليه (يُفرض خادمياً).

---

## 3. المصادقة

### 3.1 دخول المندوب

`POST /api/login/rider`

```json
{ "email": "rider@example.com", "password": "secret123" }
```

**200**
```json
{
  "success": true,
  "message": "تم تسجيل الدخول بنجاح.",
  "data": {
    "user": { "id": 12, "name": "مندوب", "email": "rider@example.com", "phone": "059...", "role": "rider" },
    "token": "1|xxxxxxxxxxxxxxxxxxxxxxxx"
  }
}
```

| الحالة | المعنى |
|---|---|
| `401` | بيانات اعتماد غير صحيحة |
| `403` | الحساب ليس `rider` أو الحساب معطّل (`code: account_inactive`) |
| `422` | أخطاء تحقق |

- أرسل التوكن في كل الطلبات: `Authorization: Bearer <token>`.
- حدود المعدّل: نفس حدود دخول الأدمن/الصيدلية (`throttle:login` + `throttle:login-account`).
- إنشاء حسابات المندوبين يتم من الأدمن (لا تسجيل ذاتي).

---

## 4. تسلسل الحالات (State model)

### 4.1 حالة الطلب الأب (`order.status`)

```
pending → confirmed → preparing → ready
        → picked_up → out_for_delivery → delivered
        ↘ cancelled (من أي مرحلة نشطة)
```

- `pending..ready` مُشتقّة تلقائياً من الطلبات الفرعية (تبقى مسؤولية الصيدليات).
- `picked_up` · `out_for_delivery` · `delivered` **يملكها المندوب**.
- بعد بدء الاستلام تُجمَّد الاشتقاقة: الصيدلية لا تُرجِع حالة الأب للخلف.
- عند `delivered` على الأب تُوسَم كل الطلبات الفرعية النشطة `delivered`.

### 4.2 الاستلام لكل صيدلية (`sub_order.picked_up_at`)

- كل طلب فرعي يُستلَم منفرداً بعد أن يصبح `ready`.
- الطلب الأب → `picked_up` فقط لحظة استلام **آخر** طلب فرعي نشط.

### 4.3 الانتقالات المسموحة للمندوب

| من | إلى | الشرط |
|---|---|---|
| `ready` | `picked_up` (تلقائي عبر pickup) | كل الطلبات الفرعية النشطة استُلمت |
| `picked_up` | `out_for_delivery` | — |
| `picked_up` / `out_for_delivery` | `delivered` | — |
| `ready` | `delivered` | **مرفوض** (يجب الاستلام أولاً) |

---

## 5. واجهة الأدمن

كل المسارات تتطلب `Authorization: Bearer <admin token>`.

### 5.1 إدارة المندوبين

| Method | Path | الوصف |
|---|---|---|
| GET | `/api/admin/delivery-riders` | قائمة المندوبين + عدّادات (فلاتر: `q`, `status=all\|active\|inactive`, `per_page`) |
| POST | `/api/admin/delivery-riders` | إنشاء مندوب |
| GET | `/api/admin/delivery-riders/{rider}` | تفاصيل مندوب |
| PUT/PATCH | `/api/admin/delivery-riders/{rider}` | تعديل بيانات المندوب |
| PATCH | `/api/admin/delivery-riders/{rider}/toggle-status` | تفعيل/تعطيل |
| DELETE | `/api/admin/delivery-riders/{rider}` | حذف |

**POST body**
```json
{ "name": "مندوب", "phone": "0591112233", "email": "rider@example.com", "password": "secret123", "is_active": true }
```
- `phone` و`email` فريدان. `email` و`is_active` اختياريان (`is_active` افتراضياً `true`).

**PUT/PATCH body**
```json
{ "name": "مندوب", "phone": "0591112233", "email": "rider@example.com", "status": true }
```

**قواعد التعطيل/الحذف**
- لا يجوز تعطيل/حذف مندوب لديه **توصيل جارٍ** (بدأ الاستلام ولم يكتمل) → `422`.
- عند التعطيل/الحذف تُفكّ إسنادات الطلبات **التي لم يبدأ استلامها** تلقائياً (يُعاد عددها في `data.unassigned_orders`).

**GET list — 200 (مقتطف)**
```json
{
  "success": true,
  "data": {
    "riders": [
      { "id": 12, "name": "مندوب", "email": "rider@example.com", "phone": "059...",
        "is_active": true, "active_deliveries_count": 1, "total_deliveries_count": 5,
        "created_at": "2026-10-18 12:00:00" }
    ],
    "pagination": { "total": 1, "per_page": 20, "current_page": 1, "last_page": 1 },
    "filters": { "q": "", "status": "all" },
    "counts": { "total": 1, "active": 1, "inactive": 0 }
  }
}
```

### 5.2 عرض الطلبات وإسناد المندوب

| Method | Path | الوصف |
|---|---|---|
| GET | `/api/admin/orders` | قائمة الطلبات (فلاتر: `status`, `assignment=all\|assigned\|unassigned`, `rider_id`, `q` = رقم الطلب أو هاتف/اسم المريض, `per_page`) |
| GET | `/api/admin/orders/{order}` | تفاصيل الطلب + سجل الحالات |
| POST | `/api/admin/orders/{order}/assign-rider` | إسناد/إعادة إسناد |
| DELETE | `/api/admin/orders/{order}/assign-rider` | فك الإسناد |

**POST assign-rider body**
```json
{ "rider_id": 12 }
```

**200**
```json
{
  "success": true,
  "message": "تم إسناد الطلب إلى المندوب بنجاح.",
  "data": { "order_id": 7, "rider_id": 12, "previous_rider_id": null,
            "assigned_at": "2026-10-18 12:05:00", "reassigned": false }
}
```

**قواعد الإسناد**
- المندوب يجب أن يكون `role=rider` و`is_active=true` → وإلا `422`.
- لا إسناد لطلب `delivered` أو `cancelled` → `422`.
- **إعادة الإسناد مسموحة حتى بدء الاستلام** (`reassigned: true`, `previous_rider_id`).
- **فك الإسناد مسموح قبل بدء الاستلام فقط** → بعده `422`.
- التزامن: كل عملية داخل معاملة مع قفل صف الطلب.

**GET /api/admin/orders — 200 (عنصر واحد)**
```json
{
  "order_id": 7, "status": "ready", "payment_status": "pending",
  "subtotal": 130.0, "discount": 0.0, "total": 130.0, "delivery_fee": 15.0, "grand_total": 145.0,
  "is_multi_pharmacy": true,
  "patient": { "id": 3, "name": "عبود", "phone": "059...", "email": null, "role": "patient" },
  "rider": null,
  "assigned_by": null,
  "assigned_at": null, "picked_up_at": null, "delivered_at": null,
  "address_snapshot": { "recipient_name": "عبود", "phone": "059...", "address": "غزة", "latitude": 31.50, "longitude": 34.47 },
  "sub_orders": [
    { "sub_order_id": 21, "pharmacy_id": 1, "pharmacy_name": "صيدلية أ", "status": "ready",
      "picked_up_at": null, "delivered_at": null, "total": 100.0, "items_count": 1,
      "items": [ { "medicine_trade_name": "Panadol", "quantity": 2 } ] }
  ],
  "created_at": "2026-10-18 12:00:00"
}
```

---

## 6. واجهة المندوب

كل المسارات تتطلب `Authorization: Bearer <rider token>` و`role:rider`.
لا يظهر للمندوب إلا ما هو مُسند إليه.

### 6.1 عرض الطلبات

| Method | Path | الوصف |
|---|---|---|
| GET | `/api/rider/orders` | طلباتي المُسندة (فلتر `status`, `per_page`) — مرتّبة بالأحدث إسناداً |
| GET | `/api/rider/orders/{order}` | تفاصيل طلب مُسند إليّ (+ سجل الحالات)؛ **404** إن لم يكن مُسنداً إليّ |

**GET /api/rider/orders — 200 (عنصر واحد)**
```json
{
  "order_id": 7, "status": "ready",
  "delivery_fee": 15.0, "grand_total": 145.0,
  "address_snapshot": { "recipient_name": "عبود", "phone": "059...", "address": "غزة", "latitude": 31.50, "longitude": 34.47 },
  "assigned_at": "2026-10-18 12:05:00", "picked_up_at": null, "delivered_at": null,
  "all_picked_up": false, "is_multi_pharmacy": true,
  "sub_orders": [
    { "sub_order_id": 21, "pharmacy_id": 1, "pharmacy_name": "صيدلية أ", "status": "ready",
      "picked_up_at": null, "delivered_at": null, "total": 100.0, "items_count": 1,
      "items": [ { "medicine_trade_name": "Panadol", "quantity": 2 } ] }
  ],
  "created_at": "2026-10-18 12:00:00"
}
```

### 6.2 الاستلام (لكل صيدلية)

`POST /api/rider/orders/{order}/pickup`

```json
{ "sub_order_id": 21 }
```

**200**
```json
{
  "success": true,
  "message": "تم تسجيل استلام الطلب الفرعي.",
  "data": { "order_id": 7, "sub_order_id": 21,
            "sub_order_picked_up_at": "2026-10-18 12:10:00",
            "all_picked_up": false, "order_status": "ready" }
}
```

- يتكرّر لكل طلب فرعي. عند استلام آخر طلب فرعي نشط: `all_picked_up: true` و`order_status: "picked_up"`.
- `422` إن: الطلب غير جاهز (`sub_order.status !== ready`)، أو مُستلَم مسبقاً، أو ملغى، أو الأب مكتمل/ملغى.

### 6.3 تحديث مرحلة التوصيل

`POST /api/rider/orders/{order}/status`

```json
{ "status": "out_for_delivery" }
```
ثم:
```json
{ "status": "delivered" }
```

**200**
```json
{ "success": true, "message": "تم بدء التوصيل.",
  "data": { "order_id": 7, "order_status": "out_for_delivery" } }
```
```json
{ "success": true, "message": "تم تسليم الطلب بنجاح.",
  "data": { "order_id": 7, "order_status": "delivered", "delivered_at": "2026-10-18 12:30:00" } }
```

- `out_for_delivery` يتطلب أن يكون الأب `picked_up` بالكامل → وإلا `422`.
- `delivered` يتطلب `picked_up` أو `out_for_delivery` → وقبل الاستلام `422`.
- عند التسليم تُوسَم كل الطلبات الفرعية النشطة `delivered`.

---

## 7. الأخطاء

### 7.1 الشكل العام

```json
{ "success": false, "message": "نص عربي يشرح السبب.", "data": {} }
```
> مسارات المصادقة/التحقق قد تعيد شكل Laravel القياسي: `{ "message": "...", "errors": { "field": ["..."] } }`.

### 7.2 أكواد الحالة

| الكود | المعنى |
|---|---|
| `401` | غير مُصادَق (توكن مفقود/منتهٍ) أو بيانات اعتماد خاطئة عند الدخول |
| `403` | الدور غير المطلوب (مثال: مريض يطلب مسار مندوب) / الطلب غير مُسند إليك |
| `404` | الطلب/المندوب/الطلب الفرعي غير موجود (أو غير مرئي لك — لا نكشف الوجود) |
| `422` | انتقال حالة غير مسموح / مندوب غير صالح / شرط استلام غير مستوفى / أخطاء تحقق |

### 7.3 رسائل الانتقال الشائعة

| السيناريو | الرسالة |
|---|---|
| استلام قبل جهوز الصيدلية | `لا يمكن استلام الطلب الفرعي قبل أن تجهّزه الصيدلية (ready).` |
| استلام مكرّر | `تم استلام هذا الطلب الفرعي مسبقاً.` |
| بدء توصيل قبل الاستلام | `يجب استلام كل الطلبات الفرعية المطلوبة قبل بدء التوصيل.` |
| تسليم قبل الاستلام | `لا يمكن تسليم الطلب قبل استلامه من الصيدليات.` |
| طلب غير مُسند للمندوب | `هذا الطلب غير مُسند إليك.` |
| إعادة إسناد بعد بدء الاستلام | `بدأ الاستلام — لا يمكن إعادة الإسناد بعد بدء الاستلام.` |
| إسناد مندوب معطّل | `المندوب غير صالح أو غير مفعّل.` |
| الصيدلية تحدّث مرحلة التوصيل | `التوصيل مُسند إلى مندوب — لا يمكن للصيدلية تحديث مراحل التوصيل.` |

---

## 8. رحلة المندوب الكاملة (مثال)

1. دخول: `POST /api/login/rider` → توكن.
2. `GET /api/rider/orders?status=ready` → الطلبات الجاهزة للاستلام.
3. `GET /api/rider/orders/{order}` → العنوان + الصيدليات + الأصناف.
4. لكل صيدلية: `POST /api/rider/orders/{order}/pickup { sub_order_id }`.
5. عند اكتمال الاستلام: `POST /api/rider/orders/{order}/status { "status": "out_for_delivery" }`.
6. التسليم: `POST /api/rider/orders/{order}/status { "status": "delivered" }`.

---

## 9. ملاحظات وقيود مهمة

- **لا إثبات تسليم بـOTP أو توقيع** في هذه المرحلة (لم يُعتمد).
- **لا تغيير على رسوم التوصيل أو الإجماليات أو الـcheckout** — دورة المندوب لا تمسّها.
- **التزامن**: كل كتابة داخل `DB::transaction` مع `lockForUpdate` على صف الطلب (وعلى
  الطلب الفرعي عند الاستلام). إسناد واحد لكل طلب (عمود واحد `rider_id`).
- **التدقيق**: كل انتقال يُسجّل في `order_status_histories` مع `actor_user_id` و`actor_role`.
- **العزل**: المندوب يعمل على الطلبات المُسندة إليه فقط؛ لا تعتمد على إخفاء الواجهة.
- الطلبات القديمة (قبل الميزة) تبقى تعمل: `rider_id = NULL` ولا تغيير سلوكي.

---

## 10. تغطية الاختبارات (Backend)

- `tests/Feature/Api/Admin/DeliveryRiderApiTest.php` — إنشاء/تعديل/تفعيل/تعطيل/حذف المندوب + حارس التوصيل الجاري.
- `tests/Feature/Api/Admin/OrderDispatchApiTest.php` — عرض الطلبات + الإسناد/إعادة الإسناد/الفك + الفلاتر + الصلاحيات.
- `tests/Feature/Api/Rider/RiderOrderApiTest.php` — دخول المندوب · العزل · الاستلام لكل صيدلية · الانتقالات · تجميد الاشتقاق · التدقيق · ثبات الرسوم.
- اختبارات الانحدار القائمة (`MultiPharmacyCheckoutTest`, `SubOrderIntegrityTest`) تبقى خضراء.
