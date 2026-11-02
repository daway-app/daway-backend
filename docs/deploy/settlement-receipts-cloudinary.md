# إشعارات التحويل — Cloudinary (authenticated)

> **الحالة:** الكود جاهز ومُختبر بعميل وهمي. التفعيل يحتاج مفاتيحك + تحققًا واحدًا
> من أن حسابك يقبل النوع `authenticated` وملفات PDF.

---

## 1. لماذا مسار منفصل عن صور الأدوية

| | الصور العامة (قائم) | إيصالات التحويل (جديد) |
|---|---|---|
| الرفع | غير موقّع عبر `upload_preset` | **موقّع** من الخادم بـ`api_key` + `api_secret` |
| نوع التسليم | `upload` (عام) | **`authenticated`** |
| الرابط | `secure_url` عام يُخزَّن | **لا رابط** — `public_id` فقط |
| التنزيل | مباشر من CDN | يمر عبر Laravel بعد التحقق من الصلاحية |

الصور العامة **لم تتغيّر**: `app/Support/Cloudinary.php` كما هو، والاختبار
`tests/Feature/CloudinaryUploadTest.php` يثبت أنها ما زالت غير موقّعة وترجع
`secure_url`. أُضيف حارس صريح لذلك في `SettlementReceiptCloudinaryTest`.

## 2. متطلبات حساب Cloudinary

1. **API Key + API Secret** من Console → Dashboard → API Keys.
   - يُستخدمان على الخادم فقط. لا يُرسلان إلى React ولا يُسجَّلان في أي log.
2. **السماح بتسليم PDF** — Cloudinary يقيّد تسليم PDF/ZIP على بعض الخطط.
   اختبر قبل الاعتماد: ارفع PDF ثم نزّله من داخل التطبيق.
3. النوع `authenticated` متاح على الحساب (تحقق منه بأول رفع حقيقي).

## 3. متغيرات Render

```env
SETTLEMENT_RECEIPT_DRIVER=cloudinary
CLOUDINARY_API_KEY=<...>
CLOUDINARY_API_SECRET=<...>
CLOUDINARY_RECEIPTS_FOLDER=daway/settlement-receipts
CLOUDINARY_RECEIPTS_TYPE=authenticated
CLOUDINARY_RECEIPTS_PDF_RESOURCE=raw
```

> `SETTLEMENT_RECEIPT_DRIVER=cloudinary` **صريح** لا `auto`: لو نسيت المفاتيح
> يفشل الرفع برسالة واضحة بدل أن يسقط بصمت إلى قرص مؤقت على Render.

## 4. Migration مطلوبة (لم تُنفَّذ على Aiven)

```
2026_11_01_000003_add_receipt_driver_and_meta_to_platform_settlements
```
تضيف عمودين: `transfer_receipt_driver` و`transfer_receipt_meta` (JSON).
الصفوف القديمة (`null`) تُقرأ كـ«قرص» — **لا ترحيل بيانات**.

## 5. التحقق بعد النشر

1. تحويل تجريبي بمبلغ صغير + إيصال PDF.
2. الاستجابة: `receipt_available: true` وبلا أي `res.cloudinary.com`.
3. في Cloudinary Console: الأصل ظاهر بنوع **authenticated**.
4. افتح `https://res.cloudinary.com/<cloud>/raw/authenticated/<public_id>` في المتصفح
   **بدون توقيع** → يجب أن يُرفض.
5. من حساب صيدلية أخرى: تنزيل نفس الإيصال → **404**.

## 6. تنظيف الملفات اليتيمة

```bash
php artisan settlements:prune-orphan-receipts --hours=24 --dry-run
php artisan settlements:prune-orphan-receipts --hours=24
```
يعمل تلقائيًا على السائق الفعلي (Cloudinary أو قرص). لا يحذف ما تشير إليه تسوية،
ولا ملفًا أحدث من فترة السماح.

## 7. التراجع

- **إلى القرص:** `SETTLEMENT_RECEIPT_DRIVER=disk` (+ `SETTLEMENT_RECEIPT_DISK=r2` للدائم).
- **إلى R2:** انظر `settlement-receipts-r2.md`. التكامل ما زال موجودًا ومُختبرًا.

> الإيصالات المرفوعة على Cloudinary تبقى على Cloudinary؛ تغيير السائق لا ينقلها.

## 8. ما لم يُختبر

**الرفع الحقيقي إلى حساب Cloudinary لم يُنفَّذ** — لا مفاتيح. كل الاختبارات
الآلية تستخدم عميلًا وهميًا يثبت المنطق والصلاحيات وحفظ المعرّفات، ولا تثبت أن
حسابك يقبل `authenticated` أو PDF. هذا يحتاج الاختبار في القسم 5.
