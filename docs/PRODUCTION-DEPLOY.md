# إجراء النشر الآمن — بعد Phase F1

> منذ Phase F1: حاوية الإنتاج **لا تشغّل هجرات ولا seeders** (CMD = API فقط).
> الهجرات تُنفَّذ كخطوة نشر مستقلة ومراقبة. هذا المستند هو الإجراء المعتمد.

## لماذا الفصل؟

1. **Zero-downtime:** أثناء النشر تعمل نسخة قديمة من الكود؛ تشغيل هجرة
   (خاصة واحدة تُسقط/تعيد مفاتيح أجنبية) من داخل الحاوية الجديدة يعني أن
   نسختين من الكود تشتركان في مخطط يتغيّر تحتهما.
2. **فشل الهجرة لا يمنع الإقلاع:** سابقة `&&` كانت تُسقط الخدمة كلها لو
   فشلت هجرة واحدة.
3. **الـseeders ليست عملية نشر** — هي أدوات تزويد، والبذر في كل deploy
   كان يكتب بيانات عرض/كلمات مرور افتراضية.

## إجراء الهجرات (لكل نشر فيه هجرة)

1. **نسخة احتياطية** لقاعدة الإنتاج (Aiven snapshot).
2. اقرأ الهجرات الجديدة بين آخر `batch` على الإنتاج والكود الجديد، وتأكد
   أنها **idempotent** (تحصين `hasColumn`/`hasTable`/فحص الفهارس — كما في
   هجرة `notifications` و`order_payment_submissions`).
3. **staging أولًا**: نفّذها على نسخة MySQL محلية/تجريبية ببيانات قديمة
   ممثلة، وتحقق من القيم قبل/بعد والمفاتيح الأجنبية والفهارس (نموذج منفَّذ:
   Phase F1 — قاعدة `daway_f1` على MySQL 8.4.8).
4. نفّذ من جهاز موثوق مباشرةً على قاعدة الإنتاج (Aiven متاح عبر TLS):

   ```bash
   DB_CONNECTION=mysql DB_HOST=<aiven-host> DB_PORT=<port> DB_DATABASE=<db> \
   DB_USERNAME=<user> DB_PASSWORD=<secret> MYSQL_ATTR_SSL_CA=database/certs/ca.pem \
   php artisan migrate --force
   ```

   أو — إن توفرت خطة مدفوعة — ضع `preDeployCommand: php artisan migrate --force`
   في `render.yaml` (مدعوم للـ**web services المدفوعة فقط**؛ يفشل النشر كاملًا
   إن فشلت، والخدمة تكمل بالنسخة السابقة بلا انقطاع).
5. **تحقّق**: `php artisan migrate:status` (بلا Pending) + فحص سريع
   (`/healthz` + مسار تأثّر بالهجرة) ثم انشر الكود الجديد.

## الـSeeders

- **لا تُشغَّل إطلاقًا في الإنتاج** افتراضيًا. إن احتجت أدمنًا أوليًا:
  1. اضبط `ADMIN_EMAIL` + `ADMIN_INITIAL_PASSWORD` في بيئة النشر.
  2. `php artisan db:seed --class=Database\\Seeders\\AdminSeeder --force` (مرة واحدة).
  3. أزل المتغيرين بعدها (اختياري — الـSeeder لا يلمس حسابًا قائمًا أبدًا).
- `ADMIN_INITIAL_PASSWORD` يجب ألا يُلغى قيمته بعد الاستخدام: الحساب
  يُنشأ بـ`must_change_password=true` ⇒ التغيير مطلوب عند أول دخول.

## حسابات تجريبية قد تكون أُنشئت سابقًا في الإنتاج (فحص، بلا تعديل)

قبل Phase F1، أي deploy كان ينشئ في الإنتاج:

| الحساب | الكلمة | الحالة |
|---|---|---|
| `admin@daway.com` | `Admin@12345` (ثابتة في الكود) | `firstOrCreate` — يُنشأ مرة واحدة، ويُعاد تفعيله كل deploy |
| `pharmacy@daway.com` | `password` | يُنشأ مرة واحدة |
| `pharmacy2@daway.com` | `password` | يُنشأ مرة واحدة |

⚠️ **فحص توصيّة (على الإنتاج، من مسؤول النشر):**

```sql
SELECT email, role, is_active, must_change_password FROM users
WHERE email IN ('admin@daway.com','pharmacy@daway.com','pharmacy2@daway.com')
   OR password IN (SELECT password FROM users WHERE email IN
      ('pharmacy@daway.com','pharmacy2@daway.com'));
SELECT pharmacy_custom_id, pharmacy_name, user_id FROM pharmacies
WHERE pharmacy_custom_id IN ('PH-1234','PH-5678');
```

إن وُجدت وتُستخدم فعليًا: غيّر كلمات مرورها عبر API (لا يدويًا في القاعدة)
على حساب أصحابها. إن لم تُستخدم: عطِّلها أو احذفها بقرار صريح من عبود —
**هذه الجلسة لا تعدّل بيانات إنتاج**.

بعد Phase F1، الـseeders لن تُنشئ أيًّا منها مجددًا في الإنتاج، ولن تُعيد
تفعيل/تغيير كلمة مرور أو دور حساب قائم أبدًا (مُثبَت بـ`ProductionSeederSafetyTest`).
