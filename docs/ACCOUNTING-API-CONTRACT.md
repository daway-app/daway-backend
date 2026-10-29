# عقد API المحاسبة — مرجع تكامل تطبيق فلاتر

> **الغرض:** مرجع واحد مُتحقَّق منه لمحاسبة الصيدلية، جاهز للتسليم لتيم فلاتر.
> **المصدر:** الكود الفعلي في `routes/api.php` + `app/Http/Controllers/Api/Accounting*Controller.php` + `app/Services/Accounting/*` + `app/Models/*`.
> **طريقة التحقق:** قراءة الكود سطرًا بسطر + `php artisan route:list --path=api/pharmacy/accounting` → **24 مسارًا** مطابقًا.
> **تاريخ التوليد:** 2026-10-16.

---

## 1) القواعد العامة

| البند | القيمة |
|---|---|
| **البادئة** | `/api/pharmacy/accounting` |
| **العدد الكلي** | **24 نقطة نهاية** (14 قراءة + 10 كتابة) |
| **المصادقة** | `Authorization: Bearer <token>` — توكن Sanctum |
| **التفويض** | `auth:sanctum` + `role:pharmacy` — الدور `patient` يُرفض بـ**403** |
| **الترويسات الإلزامية** | `Accept: application/json` (بدونها تُعاد صفحة HTML عند الخطأ) |
| **تشفير الأخطاء** | `401` بلا توكن · `403` دور غير صحيح · `404` صيدلية/سجل غير موجود · `409` حالة متعارضة · `422` تحقّق · `429` حد المعدّل · `500` خطأ خادم |
| **حد الكتابة** | `throttle:writes` = **20 طلب/دقيقة** لكل مستخدم — على كل POST فقط |
| **حد عام** | `throttle:api` = 60/دقيقة على كل الطلبات |
| **التدويل** | الحقول `*_label` و`title`/`description` في التنبيهات **مُترجَمة مسبقًا من الخادم** — اعرِضها كما هي ولا تُعِد اشتقاقها من خريطة ترجمة محلية |
| **العملة** | `₪` (شيكل) — تنسيق `1,234.50 ₪` من `AccountingReports::money()` |

### 1.1 الغلاف (Envelope)

لا يوجد شكل عالمي واحد — كل نقطة نهاية تُبنى يدويًا. القاعدة الغالبة:

```json
{ "success": true, "message": "…", "data": … }
```

إضافات حسب النوع:
- **قوائم مُرقَّمة (paginated):** `pagination: { total, per_page, current_page, last_page }`
- **قوائم بإحصاءات:** `stats: { … }`
- **خطأ تحقّق (422):** `{ "message": "…", "errors": { "field": ["…"] } }` — وليس `success:false`

> ⚠️ **`GET /refunds` لا يتبع هذا النمط:** يُعيد نماذج Eloquent خام (انظر §4.1).

### 1.2 الترقيم (Pagination)

| النقطة | الحجم الافتراضي | الأقصى |
|---|---|---|
| `GET sales` | 20 | 100 |
| `GET expenses` | 20 | 100 |
| `GET customers` | 20 | 100 |
| `GET cash` | **30** | 100 |
| `GET refunds` | 20 (ثابت — لا يقبل `per_page`) | — |
| `GET suppliers` | **50 (بلا ترقيم — `get()` كامل)** | — |

### 1.3 فلاتر التاريخ

نمطان، و`from`/`to` **يغلبان** `range`:

| النمط | القيم |
|---|---|
| `?range=` | `today` · `7d` · `30d` · `month` |
| `?from=YYYY-MM-DD&to=YYYY-MM-DD` | `from` يبدأ اليوم، `to` ينتهي اليوم |
| **بلا أي فلتر** | = **كل السجل** (ليس «اليوم») — مقصود لشاشة السجل الكامل |

> **قيم غير معروفة تُتجاهَل بصمت (لا 422)** — في `status` و`payment_method` و`category` و`direction` و`source_type` و`range`.

---

## 2) الثوابت (Enums) — القيم الحرفية

| المجال | القيم |
|---|---|
| `payment_method` (بيع) | `cash` · `card` · `bank_transfer` · `credit` |
| `payment_method` (مصروف/دفعة) | `cash` · `card` · `bank_transfer` — **بلا `credit`** |
| `status` (فاتورة) | `paid` · `partially_paid` · `unpaid` · `refunded` · `cancelled` |
| `status` (إرجاع) | `pending` · `completed` · `cancelled` |
| `direction` (صندوق) | `in` · `out` |
| `kind` (تسوية صندوق) | `withdrawal` · `deposit` · `adjustment` |
| `source_type` (حركة صندوق) | `sale` · `expense` · `purchase` · `customer_payment` · `supplier_payment` · `withdrawal` · `deposit` · `adjustment` · `refund` |
| تصنيفات المصروف الافتراضية | `salaries` · `rent` · `electricity` · `water` · `internet` · `transport` · `maintenance` · `taxes` · `other` |
| `by_status` (تغطية باركود) | `verified` · `pending` · `unknown` (و`conflict` مُعرَّف لكن لا يُرجَع) |

**حالة الفاتورة مشتقّة لا مُدخَلة:** `paid=0` ⇒ `unpaid` · `paid>=total` ⇒ `paid` · غير ذلك ⇒ `partially_paid`. لا ترسل `status` في `POST sales`.

---

## 3) نقاط النهاية

### 3.1 نظرة عامة

#### `GET /overview`
نقطة واحدة تُغذّي لوحة كاملة (بطاقات + رسوم + جدول + تنبيهات).

**استعلام:** `?range=today|7d|30d|month` (الافتراضي `today`) — يتحكّم في `comparison`/`profit_indicator` فقط؛ **البطاقات دائمًا «اليوم»**.

```json
{ "success": true, "message": "تم جلب نظرة عامة المحاسبة",
  "data": {
    "kpis": [
      { "key": "today_sales", "label": "مبيعات اليوم", "value": 1250.5,
        "format": "money", "icon": "fas fa-cash-register", "tone": "teal",
        "href": "/accounting/sales" }
    ],
    "series": { "today": { "labels": ["00-06","06-12","12-18","18-24"], "data": [0,120,540,590] },
                "7d":  { "labels": ["اليوم","2","3", "…"], "data": [ … ] },
                "30d": { "labels": ["1","6","11", "…"], "data": [ … ] },
                "month": { "labels": ["W1","W2","W3","W4"], "data": [ … ] } },
    "range": "today",
    "expense_breakdown": [ { "category": "rent", "label": "إيجار", "amount": 800, "percentage": 62.5 } ],
    "recent_transactions": [ { "type": "sale", "reference": "INV-1042",
      "description": "فاتورة بيع — بنادول ×2", "amount": 45.0,
      "method": "cash", "status": "paid", "date": "2026-10-16T10:20:00+00:00" } ],
    "alerts": [ { "severity": "danger", "icon": "fas fa-wallet", "title": "رصيد الصندوق منخفض",
      "description": "الرصيد الحالي 320.00 ₪ — أقل من الحد الآمن", "href": null } ],
    "profit_indicator": { "sales": 1250.5, "purchases": 600.0, "expenses": 120.0, "net": 530.5 },
    "comparison": { "current": 1250.5, "previous": 980.0, "change_percent": 27.6, "direction": "up" },
    "receivables": { "customer_balances": 450.0, "invoice_remaining": 450.0, "drift": 0.0,
                     "supplier_balances": 800.0, "purchase_remaining": 800.0 },
    "barcode_coverage": { "total": 120, "with_barcode": 84, "without_barcode": 36, "percent": 70.0,
                          "by_status": { "verified": 60, "pending": 24, "unknown": 36 } }
  } }
```

**مفاتيح `kpis[]`:** `today_sales` · `today_purchases` · `today_expenses` · `today_profit` · `cash_balance` · `outstanding_debts`.
**`format`:** `money` (استخدم `value` كرقم) · `tone`:** `teal|blue|orange|green|red|gray` — للتلوين فقط.

> ⚠️ `href` مسار نسبي محايد (`/accounting/sales`) — **ليس** رابطًا كاملًا. اربطه بمسارك الداخلي ولا تفتحه في WebView.
> ⚠️ `recent_transactions[].amount` **موقَّع**: مبيعات/تحصيل موجب، مصروفات/مشتريات/دفعة مورد سالب. النوع في `type`.
> ⚠️ `recent_transactions[].date` يُعاد كـ**كائن Carbon** (يُسلسَل ISO8601) — قد يختلف عن صيغة `date` في باقي النقاط.

#### `GET /sales-summary`
إجماليات الفترة لعرضها في ترويسة شاشة التقارير (بلا جلب صفوف).

**استعلام:** `?range=` أو `?from=&to=` — **بلا فلتر = كل السجل**.

```json
{ "success": true, "message": "تم جلب ملخّص المبيعات",
  "data": {
    "summary": { "total": 5000.0, "count": 42, "paid": 4600.0, "remaining": 400.0, "discount": 150.0 },
    "comparison": { "current": 5000.0, "previous": 4200.0, "change_percent": 19.0, "direction": "up" },
    "by_payment_method": [ { "method": "cash", "count": 30, "total": 3500.0, "percentage": 70.0 } ],
    "top_items": [ { "medicine_name": "بنادول", "quantity": 120, "revenue": 600.0 } ],
    "average_items_per_sale": 2.3,
    "profit_indicator": { "sales": 5000.0, "purchases": 2000.0, "expenses": 800.0, "net": 2200.0 }
  } }
```

> ⚠️ `summary` يستثني الملغى (`active()`)، و`total` هنا = مجموع `total` للفواتير غير الملغاة.
> ⚠️ `profit_indicator.net` **ليس ربحًا حقيقيًا** — لا يوجد عمود سعر تكلفة على بنود الفاتورة. هو «مبيعات − مشتريات − مصروفات». سمّه «الصافي» لا «الربح».

---

### 3.2 المبيعات

#### `GET /sales`
**استعلام:** `per_page` (≤100) · `page` · `search` (رقم الفاتورة أو اسم العميل، ≤100 حرف) · `status` · `payment_method` · `range` · `from` · `to`.

```json
{ "success": true, "message": "تم جلب فواتير البيع",
  "data": [ {
    "id": 12, "number": "INV-1042",
    "date": "2026-10-16T10:20:00+00:00", "date_human": "2026-10-16 10:20",
    "customer_id": 5, "customer": "أحمد سالم",
    "items_count": 2, "subtotal": 50.0, "discount": 5.0, "total": 45.0,
    "paid": 45.0, "remaining": 0.0,
    "method": "cash", "method_label": "نقدًا",
    "status": "paid", "status_label": "مدفوع",
    "notes": null } ],
  "pagination": { "total": 42, "per_page": 20, "current_page": 1, "last_page": 3 },
  "stats": { "total": 5000.0, "count": 42, "paid": 4600.0, "remaining": 400.0, "discount": 150.0 } }
```

> `stats` تُحسب على **نفس** الفلتر المعروض — لا على كل السجل. استخدمها للترويسة.
> `customer` = `customer_name` المخزَّن، وإن كان فارغًا يُستخدم اسم العميل المرتبط.
> **بلا `items[]`** في القائمة — تُجلب من `GET sales/{number}`.

#### `GET /sales/{number}`
`{number}` = **رقم الفاتورة** (`INV-1042`) لا الـ`id`. البحث مقيّد بـ`pharmacy_id` داخل الاستعلام (لا IDOR).

**الاستجابة:** نفس حقول القائمة **+ `items[]` + `created_by`**:

```json
"data": {
  "…": "كل حقول القائمة أعلاه",
  "items": [ { "id": 30, "medicine_id": 7, "pharmacy_medicine_id": 21,
               "medicine_name": "بنادول", "barcode": "6281000123456",
               "unit_price": 25.0, "quantity": 2, "line_discount": 0.0, "line_total": 50.0 } ],
  "created_by": "سامي"
}
```

#### `POST /sales` — إنشاء فاتورة
**حد الكتابة:** 20/دقيقة.

**الطلب:**
```json
{
  "customer_id": 5,
  "items": [ {
    "pharmacy_medicine_id": 21,
    "medicine_id": 7,
    "medicine_name": "بنادول",
    "barcode": "6281000123456",
    "unit_price": 25.0,
    "quantity": 2,
    "line_discount": 0.0 } ],
  "discount": 5.0,
  "paid": 45.0,
  "payment_method": "cash",
  "notes": "…",
  "sold_at": "2026-10-16 10:20:00"
}
```

| الحقل | القاعدة |
|---|---|
| `items` | **إلزامي** · 1–200 بند |
| `items.*.medicine_name` | **إلزامي** ≤200 حرف |
| `items.*.unit_price` | **إلزامي** ≥0 ≤999999 |
| `items.*.quantity` | **إلزامي** ≥1 ≤100000 |
| `items.*.pharmacy_medicine_id` | اختياري — **إن أُرسل يجب أن يخصّ هذه الصيدلية** |
| `items.*.medicine_id` · `barcode` · `line_discount` | اختيارية |
| `discount` · `paid` | اختيارية ≥0 |
| `payment_method` | **إلزامي** — `cash` \| `card` \| `bank_transfer` \| `credit` |
| `sold_at` | اختياري (افتراضي: الآن) |

**السلوك (داخل `DB::transaction` واحدة):**
1. حساب المجاميع: `line_total = unit_price × quantity − line_discount`، ثم `total = subtotal − discount`.
2. **خصم المخزون ذرّيًا** `UPDATE … WHERE quantity >= ?` — فشل ⇒ لا تُكتب أي فاتورة.
3. إنشاء الفاتورة + البنود.
4. `payment_method=cash` ⇒ حركة صندوق `in`. `credit` ⇒ لا صندوق وزيادة دين العميل.
5. بلا `paid` صريح: `credit` ⇒ `paid=0`، غيره ⇒ `paid=total`. `paid > total` يُقصّ إلى `total`.

**الاستجابة:** `201` + `data` = الفاتورة **ببنودها** (نفس شكل `GET sales/{number}`).

> ⚠️ **`price` ليس حقلًا.** الشكل القديم (`price`/`discount` داخل البند) **مرفوض** باختبار `AccountingWiringTest::test_old_pos_payload_shape_is_rejected`. استخدم `unit_price`.
> ⚠️ **بيع بلا `pharmacy_medicine_id` لا يخصم مخزونًا** (بيع من الكتالوج العام) — لكنه يُنشئ فاتورة. أرسِل `pharmacy_medicine_id` إن أردت الخصم.
> ⚠️ **`items[].quantity` أكبر من المخزون ⇒ `422` برسالة عربية** مثل `الكمية غير كافية في المخزون للدواء: بنادول` (تُعاد تحت مفتاح `items`).
> ⚠️ عميل لا يخصّ الصيدلية ⇒ `422` `العميل لا يتبع هذه الصيدلية`. صنف من مخزون صيدلية أخرى ⇒ `422` + `data.invalid_pharmacy_medicine_ids`.

#### `POST /sales/{number}/cancel` — إلغاء فاتورة
لا حقل في الطلب. **السلوك:** إرجاع المخزون + عكس حركة الصندوق + عكس دين العميل، والفاتورة **تبقى في السجل** بحالة `cancelled`.

| الحالة | الاستجابة |
|---|---|
| نجاح | `200` + `data` = الفاتورة الملغاة ببنودها |
| ملغاة مسبقًا | **`409`** `{ "success": false, "message": "الفاتورة ملغاة بالفعل" }` |
| غير موجودة/صيدلية أخرى | `404` `الفاتورة غير موجودة` |

---

### 3.3 الإرجاعات

#### `GET /refunds`
**استعلام:** لا يقبل أي معامل. الصفحة **ثابتة 20**.

> 🔴 **هذه النقطة الوحيدة التي تُعيد نماذج Eloquent خامًا** — لا `presentSale`-style shaping. انظر §4.1 للتفصيل الكامل.

#### `GET /refunds/{refund}`
`{refund}` = **`id` رقمي** (يُقيَّد بـ`[0-9]+`).

```json
{ "success": true, "message": "تم جلب عملية الإرجاع",
  "data": {
    "id": 3, "sale_id": 12, "sale_number": "INV-1042",
    "amount": 45.0, "reason": "إرجاع بالموافقة", "status": "completed",
    "refunded_at": "2026-10-16T11:00:00+00:00", "created_by": "سامي",
    "items": [ { "id": 8, "sale_item_id": 30, "medicine_name": "بنادول",
                 "quantity": 1, "amount": 25.0 } ] } }
```

> هنا `amount` **رقم** (يُحوَّل صراحةً بـ`(float)`)، بخلاف `GET /refunds` — انظر §4.1.

#### `GET /sales/{number}/refunds` — إرجاعات فاتورة
```json
"data": [ { "id": 3, "amount": 45.0, "status": "completed",
            "refunded_at": "2026-10-16T11:00:00+00:00", "items_count": 2 } ]
```
`404` إن كانت الفاتورة ليست لهذه الصيدلية.

#### `GET /sales/{number}/refund-items` — بنود قابلة للإرجاع
الشاشة التي تبني منها طلب الإرجاع. تُخصم الكميات المُرجَعة سابقًا.

```json
"data": {
  "sale_number": "INV-1042", "total": 45.0, "paid": 45.0,
  "already_refunded": 0.0, "available_to_refund": 45.0,
  "items": [ { "id": 30, "medicine_name": "بنادول", "unit_price": 25.0,
               "original_quantity": 2, "refunded_quantity": 0,
               "available_quantity": 2, "line_total": 50.0 } ] }
```
فاتورة ملغاة/مرجوعة ⇒ **`409`**.

> ⚠️ `already_refunded` يُحسب كـ`paid − remaining` و`available_to_refund` = `paid` — رقمان **مختلفان في المعنى** عن مجموع كميات البنود. اعتمد `items[].available_quantity` لقرار الواجهة.

#### `POST /refunds` — إنشاء إرجاع
**الطلب:**
```json
{ "sale_number": "INV-1042",
  "items": [ { "sale_item_id": 30, "quantity": 1 } ],
  "reason": "إرجاع بالموافقة",
  "refunded_at": "2026-10-16 11:00:00" }
```

| الحقل | القاعدة |
|---|---|
| `sale_number` | **إلزامي** — يجب أن يكون رقمًا **موجودًا** في جدول `sales` |
| `items` | **إلزامي** — 1 على الأقل |
| `items.*.sale_item_id` | **إلزامي** — معرّف بند فاتورة (من `refund-items`) |
| `items.*.quantity` | **إلزامي** ≥1 |
| `reason` · `refunded_at` | اختيارية |

**الاستجابة:** `201`
```json
"data": {
  "refund": { "id": 3, "sale_id": 12, "sale_number": "INV-1042", "amount": 25.0,
              "reason": "…", "status": "completed",
              "refunded_at": "…", "items": [ … ] },
  "sale": { "number": "INV-1042", "paid": 20.0, "remaining": 0.0, "status": "refunded" }
}
```

**حالات الفشل (`422` برسالة عربية):** إرجاع فاتورة ملغاة/مرجوعة · إرجاع مزدوج · كمية أكبر من المتاح · بند لا يخصّ الفاتورة · مجموع الإرجاع أكبر من المبلغ المتاح · الصنف غير موجود في مخزون الصيدلية.

---

### 3.4 المصروفات

#### `GET /expense-categories`
**تنشئ التصنيفات الافتراضية كسولًا** إن لم توجد (9 تصنيفات) ثم تُرجعها.

```json
"data": [ { "id": 1, "key": "rent", "label": "إيجار", "sort_order": 20 } ]
```
استخدم `key` في `POST expenses`، و`label` للعرض.

#### `GET /expenses`
**استعلام:** `per_page` · `page` · `search` (الوصف/المرجع/اسم التصنيف) · `category` (=`category_key`) · `range` · `from` · `to` · `include_cancelled` (bool — **الملغى مخفي افتراضيًا**).

```json
{ "success": true, "message": "تم جلب المصروفات",
  "data": [ { "id": 9, "category_key": "rent", "category_label": "إيجار",
              "amount": 800.0, "description": "إيجار أكتوبر", "reference": "RC-11",
              "payment_method": "bank_transfer", "method_label": "حوالة بنكية",
              "expense_date": "2026-10-01", "date_human": "2026-10-01",
              "is_cancelled": false, "created_by": "سامي" } ],
  "pagination": { … },
  "stats": { "total": 920.0, "count": 3,
             "breakdown": [ { "category": "rent", "label": "إيجار", "amount": 800.0, "percentage": 87.0 } ] } }
```
`stats.breakdown` تُحسب على **نفس** الفلتر الزمني المعروض (لا على الشهر دائمًا) — تُغذّي الرسم الدائري.

#### `POST /expenses`
```json
{ "category_key": "rent", "amount": 800.0, "payment_method": "bank_transfer",
  "description": "إيجار أكتوبر", "reference": "RC-11", "expense_date": "2026-10-01" }
```

| الحقل | القاعدة |
|---|---|
| `category_key` | **إلزامي** ≤40 حرف |
| `amount` | **إلزامي** > 0 (min 0.01) ≤999999 |
| `payment_method` | **إلزامي** — `cash` \| `card` \| `bank_transfer` (**لا `credit`**) |
| `description` ≤500 · `reference` ≤60 · `expense_date` | اختيارية |

**السلوك:** `payment_method=cash` ⇒ حركة صندوق `out`. **التحويل البنكي/البطاقة لا يمسّ الصندوق.**
**الاستجابة:** `201` + `data` = المصروف (نفس شكل القائمة).

> 🔴 **`expense_category_id` غير قابل للإرسال:** الكنترولر يفحص ملكيته لكنه **ليس ضمن قواعد التحقّق** ⇒ الحقل لا يصل أبدًا، والتصنيف يُحفظ **كلقطة نصّية** (`category_key` + `category_name`) فقط. أرسِل `category_key` دائمًا.

#### `POST /expenses/{expense}/cancel`
`{expense}` = **`id` رقمي** (Route Model Binding). لا حقل في الطلب.

| الحالة | الاستجابة |
|---|---|
| نجاح | `200` + `data` = المصروف (`is_cancelled: true`) — **يُعيد المبلغ إلى الصندوق** |
| ملغى مسبقًا | **`409`** |
| مصروف صيدلية أخرى | `404` `المصروف غير موجود` |

---

### 3.5 العملاء والموردون

#### `GET /customers`
**استعلام:** `per_page` · `page` · `search` (الاسم/الهاتف) · `only_debtors` (bool — `current_balance > 0`).
**الترتيب:** الأعلى دينًا أولًا.

```json
{ "success": true, "message": "تم جلب العملاء",
  "data": [ { "id": 5, "name": "أحمد سالم", "phone": "0599…", "email": null,
              "notes": null, "current_balance": 450.0, "credit_limit": 500.0,
              "credit_limit_label": "500.00 ₪", "is_active": true,
              "exceeds_credit_limit": false, "created_at": "…" } ],
  "pagination": { … },
  "stats": { "total_debt": 450.0, "customers_count": 12, "debtors_count": 3 } }
```
`credit_limit_label` = `غير محدود` إن كان الحد 0. `exceeds_credit_limit` يُحسب على الخادم.

#### `POST /customers`
```json
{ "name": "أحمد سالم", "phone": "0599…", "email": "a@b.c", "notes": "…", "credit_limit": 500 }
```
`name` **إلزامي** ≤150. `phone` ≤25 · `email` صيغة صحيحة ≤150 · `credit_limit` ≥0.

> ⚠️ **الهاتف المكرّر داخل نفس الصيدلية لا يُنشئ عميلًا جديدًا:** تُعاد `200` (لا 201) بالرسالة `العميل مسجَّل مسبقًا بنفس الرقم` مع العميل الموجود. **تعامل مع 200 و201 كنجاح.** الهاتف نفسه مسموح عبر صيدليات مختلفة.

#### `GET /customers/{customer}`
`{customer}` = `id` رقمي. `404` إن كان لصيدلية أخرى.

```json
"data": {
  "…": "كل حقول العميل أعلاه",
  "sales": [ { "number": "INV-1042", "date": "2026-10-16", "total": 45.0,
               "paid": 0.0, "remaining": 45.0, "status": "unpaid", "status_label": "غير مدفوع" } ],
  "payments": [ { "id": 4, "amount": 20.0, "method": "cash", "date": "2026-10-16", "reference": null } ]
}
```
**حدّ أقصى 50 فاتورة و50 دفعة** — لا ترقيم على هذين.

#### `POST /customers/{customer}/payments` — تسجيل دفعة
```json
{ "amount": 20.0, "payment_method": "cash", "sale_id": 12,
  "reference": "…", "description": "…", "paid_at": "2026-10-16" }
```
`amount` **إلزامي** > 0 · `payment_method` **إلزامي** (`cash|card|bank_transfer`) · `sale_id` اختياري — إن أُرسل **يجب أن يكون فاتورة لهذا العميل** وإلا `422` `الفاتورة لا تتبع هذا العميل`.

**الاستجابة:** `201` + `data.payment` + `data.customer` (العميل بعد التحديث).

#### `GET /suppliers`
**استعلام:** `per_page` · `search` (الاسم/الشركة/الهاتف).
> ⚠️ **بلا ترقيم** — تُرجع كل الموردين (`get()`)، والافتراضي 50 **غير مطبَّق** في الاستعلام. لا تعتمد على `pagination`.

```json
{ "success": true, "message": "تم جلب الموردين",
  "data": [ { "id": 2, "name": "م. الأدوية", "company": "شركة…", "phone": "…",
              "email": null, "notes": null, "current_balance": 800.0,
              "is_active": true, "created_at": "…" } ],
  "stats": { "total_payables": 800.0, "suppliers_count": 4 } }
```

#### `POST /suppliers`
```json
{ "name": "م. الأدوية", "company": "…", "phone": "…", "email": "…", "notes": "…" }
```
`name` **إلزامي**. نفس قاعدة الهاتف المكرّر: تُعاد `200` مع المورّد الموجود.

#### `POST /suppliers/{supplier}/payments`
```json
{ "amount": 300.0, "payment_method": "cash", "purchase_id": 3,
  "reference": "…", "description": "…", "paid_at": "…" }
```
`purchase_id` اختياري — إن أُرسل يجب أن يخصّ هذا المورّد. **`201`** + `data.payment` + `data.supplier`.

---

### 3.6 الصندوق

#### `GET /cash`
**استعلام:** `per_page` (≤100، افتراضي **30**) · `page` · `range` · `from` · `to` · `direction` (`in|out`) · `source_type`.
> ⚠️ **بلا فلتر ⇒ من بداية الشهر حتى الآن** (وليس كل السجل — بخلاف المبيعات/المصروفات).

```json
{ "success": true, "message": "تم جلب حركات الصندوق",
  "data": [ { "id": 77, "direction": "in", "amount": 45.0, "signed_amount": 45.0,
              "source_type": "sale", "source_id": 12, "description": "فاتورة بيع",
              "reason": null, "date": "2026-10-16T10:20:00+00:00",
              "date_human": "2026-10-16 10:20", "created_by": "سامي" } ],
  "pagination": { … },
  "stats": {
    "balance_now": 320.0,
    "balance_as_of": 350.0,
    "period": { "in": 500.0, "out": 180.0, "net": 320.0, "count": 14 },
    "low_threshold": 500.0,
    "is_low": true },
  "period": { "from": "2026-10-01", "to": "2026-10-16" } }
```
- `balance_now` = الرصيد **الحقيقي الآن** · `balance_as_of` = رصيد نهاية الفترة المختارة. **اعرض الاثنين.**
- `low_threshold` = **500.00 ₪** (`AccountingReports::LOW_CASH_THRESHOLD`) — استخدمه من الاستجابة ولا تُثبّته في التطبيق.
- `signed_amount` = المبلغ بإشارته (`in` موجب · `out` سالب).

#### `POST /cash/adjustments` — إيداع/سحب/تسوية
```json
{ "direction": "out", "kind": "withdrawal", "amount": 200.0,
  "reason": "سحب شخصي", "moved_at": "2026-10-16 18:00:00" }
```

| الحقل | القاعدة |
|---|---|
| `direction` | **إلزامي** — `in` \| `out` |
| `kind` | **إلزامي** — `withdrawal` \| `deposit` \| `adjustment` |
| `amount` | **إلزامي** > 0 ≤999999 |
| `reason` | **إلزامي** — 3..500 حرف (لا تُقبل تسوية بلا سبب) |
| `moved_at` | اختياري (افتراضي: الآن) |

**قاعدة التناسق:** `deposit` ⇒ `direction` **يجب** أن يكون `in`؛ `withdrawal` ⇒ **يجب** أن يكون `out`. عدم التطابق ⇒ `422` `اتجاه الحركة لا يطابق نوعها`. أما `adjustment` فيقبل الاتجاهين.

**الاستجابة:** `201`
```json
"data": {
  "movement": { "id": 80, "direction": "out", "amount": 200.0,
                "source_type": "withdrawal", "reason": "سحب شخصي", "date": "…" },
  "balance_now": 120.0, "is_low": true,
  "warning": "تنبيه: رصيد الصندوق صار سالبًا — راجع حركة السحب"
}
```
> ⚠️ السحب **لا يُرفض** لو جعل الرصيد سالبًا — يُبلَّغ عنه في `warning` (قد يكون الحدث واقعًا حقيقيًا). اعرِض `warning` إن لم يكن `null`.
> 🔴 **لا يوجد endpoint لتعيين الرصيد مباشرة** — بالتصميم. أي فرق يُسجَّل كحركة بسبب إلزامي.

---

## 4) فخاخ التكامل (اقرأها قبل الكود)

### 4.1 🔴 `GET /refunds` يُعيد نماذج Eloquent خامًا
الكنترولر يمرّر `$refunds->items()` **بلا أي shaping** (`return ... 'data' => $refunds->items()`)، مع تحميل `sale` و`items.saleItem`. النتيجة أعمدة قاعدة البيانات كما هي:

```json
{ "success": true, "message": "تم جلب عمليات الإرجاع",
  "data": [ {
    "id": 3, "pharmacy_id": 1, "sale_id": 12, "created_by": 9,
    "refunded_at": "2026-10-16T11:00:00.000000Z",
    "amount": "25.00",
    "reason": "إرجاع بالموافقة", "status": "completed",
    "created_at": "…", "updated_at": "…",
    "sale": { "id": 12, "number": "INV-1042", "sold_at": "…",
              "total": "45.00", "paid": "20.00", "remaining": "0.00",
              "customer_name": null },
    "items": [ { "id": 8, "refund_id": 3, "sale_item_id": 30,
                 "quantity": 1, "amount": "25.00",
                 "created_at": "…", "updated_at": "…",
                 "sale_item": { "id": 30, "sale_id": 12, "medicine_id": 7,
                                "pharmacy_medicine_id": 21, "medicine_name": "بنادول",
                                "barcode": "…", "unit_price": "25.00", "quantity": 2,
                                "line_discount": "0.00", "line_total": "50.00",
                                "created_at": "…", "updated_at": "…" } } ]
  } ],
  "pagination": { … } }
```

**النتائج العملية:**
1. **لا `sale_number`** — استخرجه من `sale.number`.
2. **لا `medicine_name` مباشر** — من `items[].sale_item.medicine_name`.
3. **`amount` نصّ (`"25.00"`) لا رقم.** حقول `decimal:2` في Laravel تُسلسَل كنصوص لحفظ الدقة. نفس الشيء في `sale.total`/`paid`/`remaining` و`sale_item.*`.
   > **اكتب محلّلًا يقبل `num` و`String` معًا** (`double.tryParse(v.toString())` في Dart) — لا تفترض نوعًا واحدًا، فالفرق بين `GET /refunds` و`GET /refunds/{id}` حقيقي.
4. **لا `sale_id`→`number` map جاهز** ولا `customer` مُهيّأ — اعمل على النموذج الخام.
5. حقول غير لازمة للواجهة موجودة (`pharmacy_id`, `created_by`, `created_at`, `updated_at`) — تجاهلها ولا تعرضها.

> هذا **ليس** عطلًا يُصلَح من جهة فلاتر: العقد قائم. اكتب طبقة تحويل (mapper) في التطبيق.

### 4.2 حقول `*_label` مُترجَمة مسبقًا
`method_label` · `status_label` · `category_label` · `label` (التصنيفات) و`title`/`description` في التنبيهات — كلها من `__()` على الخادم. **اعرِضها حرفيًا.** المفاتيح الخام غير المترجمة: `method` · `status` · `type` · `direction` · `source_type` · `category_key`.

### 4.3 الفرق بين `method` و`method_label`
استخدم `method` للمنطق (الفلترة، المقارنة)، و`method_label` للعرض. لا تُقارن نصًّا عربيًا.

### 4.4 `href` في الـKPI والتنبيهات مسار نسبي محايد
قيم مثل `/accounting/sales` أو `/accounting/sales/INV-1042` — **ليست روابط كاملة** ولا مسارات فلاتر. تجاهلها أو اربطها بشاشتك الداخلية.

### 4.5 بطء DB البعيد — لا تقرأه كعطل
كل الطلبات تذهب إلى MySQL مُدار بعيد. زمن قياسي مُسجَّل: **نظرة عامة 17–62 ثانية** (أثقل نقطة — 6 تقارير مجمَّعة في طلب واحد). البقية 3–12 ثانية.
- **اضبط مهلة العميل ≥ 60 ثانية**، وإلا ستظهر النظرة العامة «فاشلة» بلا سبب.
- اعرِض حالة تحميل صريحة؛ لا تعتبر التأخير خطأً.

### 4.6 الأخطاء: 422 لا يحمل `success`
خطأ التحقّق يخرج من Laravel مباشرة: `{ "message": "…", "errors": { "items": ["…"] } }`. **افحص `errors` أولًا، ثم `message`.** أما أخطاء المنطق التجاري من الكنترولر فتخرج `{ "success": false, "message": "…" }` مع 409/422.

### 4.7 التصحيح اللغوي في الرسائل
كل رسائل الخادم **عربية** — اعرِضها كما هي ولا تترجمها. بعض رسائل المنطق تحتوي اسم الدواء/الكمية مُدمجًا.

### 4.8 لا تغيير على العقد من جهة فلاتر
إن احتجت حقلًا غير موجود (مثلًا `medicine_name` مباشر في `GET /refunds`) — **اطلبه من الـbackend كتغيير مقترح**، ولا تبنِ على افتراض أنه سيُضاف.

---

## 5) 🔴 تعارض وثيقة OpenAPI الحالية مع الكود

الوثيقة على `/api/documentation` (مولَّدة في `storage/api-docs/api-docs.json`) **مصدرها ملف مُكتوب يدويًا** `app/OpenApi/Paths/AccountingPaths.php` (3 أكتوبر) — و**يخالف الكود الفعلي في مواضع حرجة**. **لا تبنِ على الوثيقة التفاعلية وحدها.**

| # | الموضع | الوثيقة تقول | الكود يقول | الخطورة |
|---|---|---|---|---|
| 1 | `POST /sales` — حقول البند | `price` · `discount` | **`unit_price`** · `line_discount` | 🔴 **حرج** — ينتج 422 دائمًا |
| 2 | `POST /sales` — `payment_method` | `cash, credit, bank` | `cash, card, bank_transfer, credit` | 🔴 **حرج** — `bank` قيمة خاطئة |
| 3 | `POST /sales` — حقول إلزامية | لا تُذكر `medicine_name` | `medicine_name` **إلزامي** | 🔴 حرج |
| 4 | `GET /overview` — المعاملات | `from` · `to` | **`range`** (`today\|7d\|30d\|month`) | 🟠 خطأ |
| 5 | `GET /sales` — المعاملات | `status` · `payment_method` فقط | + `per_page` · `page` · `search` · `range` · `from` · `to` | 🟠 ناقص |
| 6 | `GET /sales-summary` | لا معاملات | `range` · `from` · `to` | 🟠 ناقص |
| 7 | `POST /expenses` · `/refunds` · `/customers` · `/suppliers` · `/cash/adjustments` · الدفعات | **لا `requestBody` إطلاقًا** | لكل منها حقول إلزامية | 🔴 حرج — لا يمكن بناء الطلب |
| 8 | `POST /sales/{number}/cancel` | `422` عند «ملغاة مسبقًا» | **`409`** | 🟡 |
| 9 | `POST /expenses/{expense}/cancel` | `422` عند «ملغى مسبقًا» | **`409`** | 🟡 |
| 10 | `GET /sales` — الاستجابة | بلا ذكر `stats` | `stats` موجود | 🟡 |
| 11 | `GET /suppliers` | بلا ذكر «لا ترقيم» | بلا ترقيم (`get()`) | 🟡 |
| 12 | استجابة `GET /refunds` | «قائمة الإرجاعات» | **نماذج خام** (§4.1) | 🟠 مضلِّل |

**الخلاصة للتيم:** اعتمد **هذه الوثيقة** كمصدر أول، والـOpenAPI للتنقّل السريع في المسارات فقط.
**التوصية لعبود:** تحديث `AccountingPaths.php` (خاصة `requestBody`) — يتطلب قرارًا منفصلًا، لم يُنفَّذ هنا.

---

## 6) أمثلة جاهزة (curl)

```bash
BASE=https://daway-backend.onrender.com/api
TOKEN=<sanctum-token>

# نظرة عامة — الأثقل، مهلة ≥60ث
curl -s -H "Accept: application/json" -H "Authorization: Bearer $TOKEN" \
  "$BASE/pharmacy/accounting/overview?range=7d"

# فاتورة نقدية بند واحد — ملاحظة: unit_price لا price
curl -s -X POST "$BASE/pharmacy/accounting/sales" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"payment_method":"cash","items":[{"pharmacy_medicine_id":21,"medicine_name":"بنادول","unit_price":25,"quantity":2}]}'

# الأصناف المتاحة للإرجاع (قبل بناء طلب الإرجاع)
curl -s -H "Accept: application/json" -H "Authorization: Bearer $TOKEN" \
  "$BASE/pharmacy/accounting/sales/INV-1042/refund-items"

# إرجاع جزئي
curl -s -X POST "$BASE/pharmacy/accounting/refunds" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"sale_number":"INV-1042","items":[{"sale_item_id":30,"quantity":1}],"reason":"تالف"}'

# تسوية صندوق — direction يجب أن يطابق kind
curl -s -X POST "$BASE/pharmacy/accounting/cash/adjustments" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"direction":"out","kind":"withdrawal","amount":200,"reason":"سحب شخصي"}'
```

---

## 7) قائمة تحقّق للتيم قبل الإطلاق

- [ ] التوكن في ترويسة `Authorization: Bearer` + `Accept: application/json` في **كل** طلب.
- [ ] مهلة الطلب **≥ 60 ثانية** (النظرة العامة قد تصل 60ث).
- [ ] محلّل مبلغ يقبل **رقمًا ونصًّا** (فخ `decimal` في `GET /refunds`).
- [ ] `POST sales` يستخدم `unit_price` و`medicine_name` — **لا `price`**.
- [ ] `payment_method`: `bank_transfer` وليس `bank`.
- [ ] 200 و201 كلاهما نجاح في `POST customers` و`POST suppliers`.
- [ ] 409 (ملغى/مرجوع مسبقًا) له واجهة مخصّصة — لا رسالة خطأ عامة.
- [ ] 422 يُقرأ من `errors`، ورسالة الخادم تُعرَض كما هي.
- [ ] «بلا فلتر تاريخ» في المبيعات/المصروفات = **كل السجل**، وفي الصندوق = **الشهر الحالي**. لا تخلط.
- [ ] `*_label` تُعرَض كما هي بلا إعادة اشتقاق.
- [ ] `low_threshold` يُقرأ من الاستجابة (500 ₪) ولا يُثبَّت في الكود.
- [ ] `GET suppliers` بلا ترقيم — لا تفترض `pagination`.
- [ ] كل POST يخضع لـ20/دقيقة — نفّذ تراجعًا (backoff) على `429`.
