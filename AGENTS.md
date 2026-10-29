# Daway — Project Brain (AGENTS.md)

> **آخر تحديث: 2026-10-27 (Phase D).** هذا المستودع **Laravel API فقط**.
> واجهة Blade القديمة (routes/views/controllers web + Vite + npm) **حُذفت نهائيًا**.
> أي كود واجهة جديد **لا يُكتب هنا** — مكانه مستودع React.

## Project at a glance

- **Product**: Daway — Palestinian pharmacy/medicine availability app.
- **هذا المستودع**: Laravel 13 (PHP 8.4) — **API-only** · MySQL (Aiven على Render) · Sanctum · Spatie Permission + ActivityLog · l5-swagger.
- **لا واجهة هنا**: لا Blade · لا Vite · لا npm · لا `package.json` · لا `resources/js|css` · لا `public/build`.
- **React Pharmacy Web**: مستودع منفصل `github.com/daway-app/daway-Frontend` (المجلد المحلي `daway-web`) — يستهلك هذا الـAPI.
- **Flutter**: مستودع منفصل — يستهلك هذا الـAPI فقط، **ولا يتعامل مع قاعدة البيانات مباشرة**.
- **النشر**: Render (خطة free) · Dockerfile أحادي المرحلة · `php artisan serve` على المنفذ 10000 · فحص الصحة `/healthz`.
- **اللغات**: عربي أولًا (`ar` افتراضي) + `en` · رسائل الـAPI عربية.

## ما يعيش أين (لا تخلط الطبقات)

| الطبقة | المكان | ملاحظة |
|---|---|---|
| المسارات | `routes/api.php` (كل شيء) + `routes/console.php` | لا `routes/web.php` |
| المتحكمات | `app/Http/Controllers/Api/**` | لا `Controllers/web` |
| الحُرّاس | `app/Http/Middleware/{EnsureRole,SetAppLocale,AuthorizeScanSession}.php` | API-only |
| القوالب | `resources/views/vendor/l5-swagger/` **فقط** | واجهة Swagger — لا تُحذف |
| الترجمات | `resources/lang/{ar,en}/**` | عقد رسائل الـAPI |
| الأصول العامة | `public/` (entrypoint + أصول Swagger) | لا مخرجات بناء أمامية |
| الاختبارات | `tests/Feature`, `tests/Unit` | لا `tests/Feature/Web` |

## Hard rules (never break)

1. **Project root is the repo itself** — تحقّق من المسارات قبل أي إجراء.
2. **Never rewrite working code** — تغييرات صغيرة موجّهة؛ افحص الاستخدامات قبل الحذف.
3. **Never commit secrets** — `.env`, كلمات مرور DB، مفاتيح API، مواد OTP، `*.sql` backups.
4. **Never weaken security** — لا تسجيل/إرجاع OTP أو التوكنات، لا إزالة حدود معدل، لا تجاوز صلاحيات.
5. **Audits are read-only** — أثناء مراجعة شاملة لا تعدّل ملفات؛ قدّم التقرير وانتظر.
6. **API-only, بلا استثناء** — ⛔ لا تُضِف Blade/Vite/npm/`package.json` أو `resources/js|css` إلى هذا المستودع. أي واجهة = مستودع React.
7. **Domain facts (مصحَّحة 2026-10-27)** — الأقسام **موجودة** (`categories`, `category_medicine_links`) · يوجد Observers (`PharmacyMedicineObserver`, `RatingObserver`) · **لا** repositories · **لا** Jobs/Events/Mail classes. ابنِ على الموجود.
8. **Keep the dual role system in sync** — `users.role` enum **و** Spatie roles معًا (انظر `RolePermissionSeeder`).
9. **Respect response conventions** — `{success, message, data, pagination}` برسائل عربية، بنفس نمط المتحكمات الحالية.
10. **Tests must pass** — `php artisan test` بعد أي تغيير سلوكي؛ لا تحذف اختبارًا لجعل المجموعة خضراء.
11. **NEVER push** — `git push` / `gh pr create` / أي نشر يتطلّب موافقة عبود **الصريحة لكل مرة**. `commit` أيضًا لا يتم إلا بطلب. **القراءة مسموحة دائمًا**: `status`, `diff`, `log`, `show`, `ls-remote`, `fetch`.

## Auth & error contract (بعد Phase D)

- **Sanctum Bearer** لكل مسارات API المحمية (`auth:sanctum` + `role:*`).
- **401** = `AuthenticationException` → `{success:false, message:'يجب تسجيل الدخول'}`.
- **403** = `AccessDeniedHttpException` → `{success:false, message:'غير مصرح لك بالوصول'}`.
- **🔴 `redirectGuestsTo(fn () => null)`** في `bootstrap/app.php` — بدونه يعود Laravel إلى `route('login')` غير الموجودة ⇒ **500 بدل 401**.
- **جلسات الويب**: مدعومة عمدًا عبر Sanctum stateful (`EnsureFrontendRequestsAreStateful` + `sanctum.guard=['web']`) — يستخدمها `/api/sync/token` لعميل يرسل كوكي جلسة. **لا تُزل دون إثبات الاستغناء.**
- **`/api/sync/token`** يعمل بـBearer (React/Flutter) **وبالجلسة** — كلاهما مختبَر.

## Tests — كيف تُشغَّل

- **المجموعة كاملة**: `php artisan test` (≈1585 اختبارًا).
- **ثلاثة اختبارات تُسقط عملية PHP إذا شُغّلت مع البقية** (مشكلة بيئة قائمة، ليست عطل كود) — شغّلها **منفردة**:
  - `tests/Feature/EnrichmentEngineTest.php`
  - `tests/Feature/Api/CategorySyncApiTest.php --filter=test_full_pipeline`
  - `tests/Feature/Commands/CategorySyncCommandTest.php --filter=test_works_with_real_full`
- **لا `--filter "Inventory"`** — فضفاض ويلتقط مسارات ملفات أخرى.
- ⚠️ **فخ إيجابي كاذب**: مشغّل المهام قد يوسم التشغيل "failed" بسبب اعتراض شبكة على `169.254.169.254:80` بينما المخرج `OK` — **اقرأ مخرجات PHPUnit لا حالة المهمة**.
- **فخ كاش الـGuard**: عند تبديل التوكن داخل نفس الاختبار استدعِ `$this->app['auth']->forgetGuards()` وإلا مرّ التوكن المُبطَل بنجاح كاذب.

## Deploy (Render / Docker)

- `Dockerfile` **مرحلة واحدة** (PHP 8.4 CLI) — لا Node ولا Vite. `render.yaml` يستخدم `runtime: docker` و`healthCheckPath: /healthz`.
- `CMD` يشغّل: `config:cache` → `route:cache` → `storage:link` → `migrate --force` → الـseeders الخمسة → حلقة `/healthz` → `artisan serve`.
- **لا Queue Worker ولا Scheduler منفصلين** على Render حاليًا: لا `queue:work` في الـCMD ولا في `render.yaml`. أي عمل يحتاج طابورًا يجب أن يعمل synchronously أو يُضاف كخدمة منفصلة بقرار صريح.
- أوامر التحقق: `php artisan route:list` · `php artisan migrate:status` · `php artisan config:cache` (محليًا أولًا).

## Known issues (current — لا تُعامَل كحقائق قديمة)

- **OTP يُعاد في JSON** (لا مزوّد SMS في الإنتاج) — مقصود مؤقتًا؛ لا تغيّره دون تنسيق مع العميل.
- **3 هجرات غير متتبَّعة** تنتظر قرارًا (`purchase_items` · `notifications.order_context` · `moh_medicine_id nullable`) — لم تُطبَّق.
- **`app/OpenApi/Paths/AccountingPaths.php` منحرف عن الكود** (`price` بدل `unit_price`, بلا `requestBody`) — لا يُعتمد مصدرًا؛ المرجع `docs/ACCOUNTING-API-CONTRACT.md`.
- **أخطاء `ValidationException`** تُعاد `{message, errors}` بلا `success` — قرار معماري مؤجَّل موثّق في `docs/VALIDATION-ERROR-CONTRACT.md`.

## Skills

السكيلات صارت **على مستوى المستخدم** في `~/.workbuddy-ai/skills/` (لا `.opencode/skills/`). الأكثر صلة بهذا المستودع:
`laravel` · `compound-eng-php-laravel` · `mysql` · `api-contract-audit` · `daway-phpunit-isolated` · `laravel-log-triage` · `hotpath-gate-optimization` · `remote-db-perf-audit` · `testing-patterns` · `tech-debt-audit-remediation`.
سكيلات الواجهة (`react-frontend-architecture`, `visual-regression-qa`, `daway-pharmacy-web`, `vitest-windows-reliable`, `service-worker-testing`, `blade-js-wiring-audit`) تخصّ **مستودع React** لا هذا المستودع.

## Repo hygiene reminders

- فروع الميزات: `feature/US-XX-slug` · CI (اختبارات) يعمل على push/PR إلى `develop`.
- حدّث `.env.example` عند إضافة أي متغيّر بيئة.
- أوامر التحقق: `php artisan test` · `php artisan route:list` · `php artisan migrate:status` · `git diff --check`.
