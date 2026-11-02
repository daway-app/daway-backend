# إشعارات التحويل المالي — Cloudflare R2

> **الحالة:** الكود جاهز ومُختبر. التفعيل يحتاج خطوات منك على Cloudflare وRender فقط.
> لا يحتاج أي تغيير كود لاحق.

---

## 1. المشكلة

ملفات إثبات التحويل (receipts) تُحفظ على قرص `local` داخل حاوية Render.
Render بلا قرص دائم على الخطة الحالية ⇒ **كل إعادة نشر تمسح الملفات**.
الصف في `platform_settlements` يبقى يقول "مدفوع بإيصال"، لكن الملف نفسه يصبح غير موجود
→ زر التنزيل يرجع 404، وسجل التحويل المالي يصبح بلا إثبات.

## 2. ما نُفّذ في الكود

| المكوّن | الدور |
|---|---|
| `config/filesystems.php` → `disks.r2` | قرص S3-compatible، `visibility=private`، `throw=true`، بلا `url` عام. |
| `config/filesystems.php` → `settlement_receipt_disk` | مفتاح التبديل: `local` أو `r2` (من `SETTLEMENT_RECEIPT_DISK`). |
| `App\Services\Settlement\ReceiptStorage` | نقطة واحدة للحفظ/التحقق/التنزيل/الحذف. ترفض أي قرص غير مهيّأ أو عام. |
| `App\Console\Commands\PruneOrphanSettlementReceipts` | حذف الملفات اليتيمة (فشل المعاملة بعد الرفع) مع فترة سماح. |
| `league/flysystem-aws-s3-v3` | محوّل S3 الرسمي (مضاف إلى `composer.json` + `composer.lock`). |

**الخصوصية:** لا روابط عامة ولا روابط موقّعة. التنزيل يمرّ حصرًا عبر متحكمين محميين:
- `GET /api/admin/platform-settlements/{id}/receipt` — أدمن فقط
- `GET /api/pharmacy/accounting/platform-settlements/{id}/receipt` — الصيدلية المالكة فقط

---

## 3. خطوات Cloudflare (أنت تنفّذها)

1. **أنشئ bucket** باسم مثل `daway-settlement-receipts`.
   - **لا تفعّل** `Public access` ولا `r2.dev` public URL ولا Custom Domain.
   - البكت يبقى خاصًا بالكامل.
2. **أنشئ R2 API Token** من `Manage R2 API Tokens`:
   - Permissions: **Object Read & Write**
   - Scope: **Bucket محدد** (البكت أعلاه فقط) — ليس كل الحساب.
   - احفظ: `Access Key ID` و`Secret Access Key` (تظهر مرة واحدة).
3. **احفظ Account ID** من صفحة R2 — يظهر في الـendpoint:
   `https://<ACCOUNT_ID>.r2.cloudflarestorage.com`

> لا ترسل لي المفاتيح. تُدخل مباشرة في Render.

## 4. متغيرات Render

في خدمة `daway-backend` → Environment، أضف:

```env
SETTLEMENT_RECEIPT_DISK=r2
R2_ACCESS_KEY_ID=<من الخطوة 2>
R2_SECRET_ACCESS_KEY=<من الخطوة 2>
R2_BUCKET=daway-settlement-receipts
R2_ENDPOINT=https://<ACCOUNT_ID>.r2.cloudflarestorage.com
R2_REGION=auto
R2_USE_PATH_STYLE_ENDPOINT=false
```

ثم أعد النشر. **لا تحتاج Migration** — أعمدة الإيصال موجودة منذ batch 20.

## 5. التحقق بعد النشر

1. سجّل دخول كأدمن → شاشة «مستحقات الصيدليات».
2. نفّذ تحويلًا تجريبيًا بمبلغ صغير على صيدلية اختبار + إرفاق إيصال.
3. تحقّق:
   - الاستجابة `receipt_available: true`.
   - زر «تنزيل إشعار التحويل» يعمل.
   - الملف موجود في bucket R2 (Objects → `platform-settlement-receipts/`).
   - رابط مباشر للملف **لا يعمل** (البكت خاص) — هذا هو المطلوب.
4. من حساب صيدلية أخرى: تنزيل نفس الإيصال يجب أن يرجع **404**.

## 6. تنظيف الملفات اليتيمة

لو فشلت المعاملة بعد رفع الملف (انقطاع نشر/فشل قاعدة)، يبقى الملف بلا صف.
الأمر يحذفه بعد 24 ساعة سماح:

```bash
php artisan settlements:prune-orphan-receipts --hours=24 --dry-run   # معاينة
php artisan settlements:prune-orphan-receipts --hours=24             # تنفيذ
```

مضاف إلى `routes/console.php` يوميًا 03:20. **Render المجاني لا يشغّل scheduler** —
شغّله كـCron Job أو GitHub Action إن أردت أتمتته.

## 7. التراجع (Rollback)

```env
SETTLEMENT_RECEIPT_DISK=local
```
الكود يعمل فورًا. **لكن** الملفات المرفوعة على R2 لن تكون مرئية للقرص المحلي —
التراجع مقبول مؤقتًا فقط، وليس حلًا دائمًا.

## 8. ملاحظات أمنية

- لا تُمنح مفاتيح R2 صلاحية إدارة الحساب — Object Read & Write على bucket واحد تكفي.
- لا تُضاف `AWS_URL` / `url` لقرص `r2` — أي رابط عام يهدم متطلب الخصوصية.
- حجم الملف محدود بـ5MB ونوعه محصور في `jpg,jpeg,png,webp,pdf` (تحقق في المتحكم).
- الملفات القديمة على القرص المحلي في الإنتاج **ضاعت فعلًا** مع عمليات النشر السابقة —
  لا يمكن استرجاعها؛ الحل يمنع تكرار ذلك من الآن.
