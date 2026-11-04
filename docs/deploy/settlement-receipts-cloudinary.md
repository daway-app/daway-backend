# إشعارات التحويل — Cloudinary (الوضع العام غير الموقّع)

> **الحالة المعتمدة:** `cloudinary_public` — رفع غير موقّع عبر الـpreset الموجود،
> بلا أي API Key أو Secret. الملف الناتج **عام**.

---

## 1. ⚠️ اقرأ هذا أولًا

**الإيصال يُخزَّن على رابط عام.** أي شخص يملك الرابط يستطيع فتحه.

- إخفاء الرابط داخل استجابات الـAPI **لا يجعل الملف خاصًا** — هو تقليل انتشار فقط.
- لذلك: **لا ترفع مستندًا بنكيًا حقيقيًا** يحتوي أرقام حسابات أو بيانات شخصية.
  ارفع صورة **منقّحة** تُظهر المبلغ والتاريخ فقط.
- الواجهة تعرض هذا التنبيه في نموذج الدفع.

إن أردت حماية فعلية لاحقًا: أضف `CLOUDINARY_API_KEY` + `CLOUDINARY_API_SECRET`
وغيّر `SETTLEMENT_RECEIPT_DRIVER=cloudinary` (الوضع الموقّع `authenticated` — ما زال
في الكود ويعمل).

## 2. كيف يعمل

| | الصور العامة (أدوية/شعارات) | الإيصالات (الوضع الحالي) |
|---|---|---|
| الرفع | غير موقّع عبر `upload_preset` | **نفس الأسلوب** |
| المفاتيح | لا شيء | **لا شيء** |
| المجلد | `daway/medicines` … | `daway/settlement-receipts` |
| النوع | jpg/png/webp/pdf | **jpg/png/webp فقط — PDF موقوف** |
| الرابط | عام | **عام** |

الملف يُرفع إلى `https://api.cloudinary.com/v1_1/<cloud>/image/upload` مع
`upload_preset` + `folder`. الاستجابة تعطي `public_id` و`secure_url`.

**ما يُحفظ في القاعدة:**
- `transfer_receipt_path` = `public_id`
- `transfer_receipt_driver` = `cloudinary_public`
- `transfer_receipt_meta` = `{public_id, resource_type, type, format, url, delivery: "public"}`

**التنزيل:** يمر عبر Laravel فقط. `GET .../receipt` يتحقق أن المستخدم أدمن أو
الصيدلية المالكة، ثم يجلب الصورة من Cloudinary ويبثّها (`Cache-Control: private, no-store`).
الرابط لا يُعاد في أي استجابة.

## 3. متغيرات Render

```env
SETTLEMENT_RECEIPT_DRIVER=cloudinary_public
CLOUDINARY_RECEIPTS_FOLDER=daway/settlement-receipts
```

**لا تلمس:**
- `CLOUDINARY_CLOUD_NAME` (مستخدم لصور الأدوية)
- `CLOUDINARY_UPLOAD_PRESET` (نفسه يُستخدم للإيصالات)

**يمكن حذفها** (لم تعد تُستخدم في هذا الوضع):
- `CLOUDINARY_RECEIPTS_TYPE`
- `CLOUDINARY_RECEIPTS_PDF_RESOURCE`

> ⚠️ إن تركت `SETTLEMENT_RECEIPT_DRIVER=cloudinary` (القديم) سيفشل رفع الإيصال
> برسالة واضحة لأنه يطلب مفاتيح غير موجودة. غيّرها إلى `cloudinary_public`.

## 4. القيود المعروفة

| القيد | الأثر |
|---|---|
| **لا حذف من Cloudinary** بدون API Secret | الملفات اليتيمة تبقى وتحتاج حذفًا يدويًا من الـConsole |
| **لا سرد للأصول** بدون API Secret | `settlements:prune-orphan-receipts` يطبع تنبيهًا ولا يحذف شيئًا في هذا الوضع |
| **PDF موقوف** | الواجهة تقبل صورًا فقط؛ الـAPI يرفض PDF بـ422 |
| **الملف عام** | أي شخص يملك الرابط يفتحه |

## 5. التراجع إلى الوضع المحمي

1. أضف `CLOUDINARY_API_KEY` و`CLOUDINARY_API_SECRET` في Render.
2. `SETTLEMENT_RECEIPT_DRIVER=cloudinary`.
3. أعد النشر.

الإيصالات العامة القديمة تبقى مقروءة لأن القراءة تتبع `transfer_receipt_driver`
المحفوظ في كل صف.

## 6. ما لم يُختبر

**الرفع الحقيقي إلى حساب Cloudinary لم يُنفَّذ.** كل الاختبارات الآلية تستخدم
`Http::fake()` وتثبت: أن الرفع غير موقّع، أن الرابط لا يُعاد، أن الصلاحيات مضبوطة،
وأن PDF مرفوض. لا تثبت أن حسابك سيقبل الرفع فعليًا — هذا يحتاج اختبارًا واحدًا
بصورة تجريبية بعد النشر.
