<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\AuthorizeScanSession;
use App\Http\Middleware\EnsureAdminPasswordChanged;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SetAppLocale;
use App\Support\InventoryImportThrottle;
use App\Support\ScanSessionThrottle;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

Request::enableHttpMethodParameterOverride();

// دوال مساعدة عامة (مثل `account_money`) — تُحمَّل هنا لضمان توفّرها على كل
// بيئة بلا الاعتماد على تعديل `composer.json` أو إعادة توليد الـautoloader.
require_once __DIR__.'/../app/helpers.php';

/**
 * هل الطلب على مسارات الاستيراد الجماعي (API فقط بعد حذف واجهة Blade)؟
 *
 * نُقيّد معالج 429 بهذه المسارات فقط، حتى لا نغيّر سلوك بقية الحدود
 * (login / otp / register / throttle:api) التي تحتاج معالجة مختلفة.
 */
$isInventoryImportRequest = static function (Request $request): bool {
    return $request->is(
        'api/pharmacy/inventory/import',
        'api/pharmacy/inventory/import/*',
    );
};

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // `/healthz` يُسجَّل هنا — لا في routes/web.php.
        // السبب: هو عقد فحص الصحة في render.yaml (`healthCheckPath: /healthz`)
        // وحلقة الإبقاء في Dockerfile. لو بقي مسارًا ويبًا لسقط النشر عند حذف
        // واجهة Blade. نفس المسار ونفس الاستجابة (JSON من HealthController)،
        // وبلا مجموعة `web` (لا جلسة ولا كوكيز — وهي غير لازمة لفحص صحة).
        then: function () {
            Route::get('/healthz', [HealthController::class, 'index']);
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // C-1/H-3: البروكسي الموثوق يُقرأ وقت الطلب من config('trustedproxy.proxies')
        // (يرسله middleware الـ TrustProxies المدمج) — آمن بعد config:cache ولا يُنفَّذ هنا أبداً.
        $middleware->throttleApi();

        // 🔴 تطبيق API فقط: لا صفحة دخول بعد حذف Blade.
        // Laravel يضبط افتراضيًّا `redirectGuestsTo(fn () => route('login'))`؛
        // بعد حذف المسار صار كل طلب غير مصادَق **لا يتوقّع JSON** (متصفح يفتح
        // /api/... مباشرة) يرمي RouteNotFoundException داخل `Authenticate`
        // قبل حتى بناء AuthenticationException ⇒ 500 بدل 401.
        // إرجاع null يجعل الاستثناء بلا redirectTo فيلتقطه معالج API أدناه.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'role' => EnsureRole::class,
            'admin.password.changed' => EnsureAdminPasswordChanged::class,
            // جلسات المسح بالهاتف: مسارات `pair`/`barcode` تقبل توكن اقتران
            // مؤقت **أو** Bearer صيدلية، فلا تصلح معها `auth:sanctum` (ترمي
            // 401 قبل أي منطق). الحارس نفسه يفحص المصدرين ويعزل الصيدلية.
            'scan.session' => AuthorizeScanSession::class,
        ]);

        $middleware->web(append: [
            SetAppLocale::class,
        ]);

        // H3: locale middleware على API أيضاً — للـ mobile requests.
        $middleware->api(prepend: [
            // Sanctum: يسمح بكوكيز جلسة الويب على /api/* — مطلوب لـ /api/sync/token
            // (إصدار توكن مزامنة من جلسة الصيدلية على الويب).
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            SetAppLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($isInventoryImportRequest) {
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $request->expectsJson()
            ? response()->json(['success' => false, 'message' => 'غير موجود'], 404)
            : null);

        $exceptions->render(fn (AccessDeniedHttpException $e, Request $request) => ($request->expectsJson() || $request->is('api/*'))
            ? response()->json(['success' => false, 'message' => 'غير مصرح لك بالوصول'], 403)
            : null);

        // `api/*` صريحة عمدًا (مثل EnsureRole): طلب API غير مصادَق يجب أن يحصل
        // على 401 JSON دائمًا. بلا هذا الشرط يهبط الطلب غير-JSON (متصفح يفتح
        // /api/... مباشرة) إلى معالج Laravel الافتراضي
        // (Foundation\Exceptions\Handler::unauthenticated) الذي ينادي
        // `route('login')` ⇒ RouteNotFoundException بعد حذف مسارات الويب.
        $exceptions->render(fn (AuthenticationException $e, Request $request) => ($request->expectsJson() || $request->is('api/*'))
            ? response()->json(['success' => false, 'message' => 'يجب تسجيل الدخول'], 401)
            : null);

        // HTTP 429 على مسارات الاستيراد (API فقط): رسالة مفهومة + Retry-After
        // بدل جسم الخطأ الخام، مع `retry_after` بالثواني ليقرأه العميل فيعيد
        // المحاولة بعد الانتظار. يُسجَّل كل حجب حقيقي كـwarning للمراقبة.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($isInventoryImportRequest) {
            if (! $isInventoryImportRequest($request)) {
                return null;
            }

            $headers = $e->getHeaders();
            $retryAfter = (int) ($headers['Retry-After'] ?? 0);

            $message = $retryAfter > 0
                ? __('pharmacy_import.error_too_many_requests', ['seconds' => $retryAfter])
                : __('pharmacy_import.error_too_many_requests_generic');

            Log::warning('inventory import rate limit hit', [
                'user_id' => $request->user()?->id,
                'route' => $request->route()?->getName(),
                'path' => $request->path(),
                'retry_after' => $retryAfter,
            ]);

            return response()->json([
                'success' => false,
                'message' => $message,
                'retry_after' => $retryAfter,
            ], 429, $headers);
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->expectsJson() || $request->is('api/*'))) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface || $e instanceof ValidationException) {
                return null;
            }

            return response()->json(['success' => false, 'message' => 'حدث خطأ داخلي'], 500);
        });
    })
    ->create();

$app->booted(function () {
    RateLimiter::for('api', function (Request $request) {
        return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
    });

    RateLimiter::for('otp', fn (Request $request) => Limit::perMinute(5)->by($request->ip().'|'.$request->string('phone')));

    RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinutes(15, 5)->by($request->ip().'|'.$request->string('phone')));

    RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

    // التسجيل الذاتي للصيدليات (ويب + API): 5 محاولات/دقيقة لكل IP
    // — يمنع إنشاء حسابات وهمية بالجملة. قيد users.phone unique يمنع التكرار برقم واحد.
    RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

    // M-3: حد معدل لكل حساب (وليس لكل IP فقط) — يحمي حساب صيدلية محدداً من هجوم
    // موزع من عناوين متعددة. pharmacy_id للـ API وidentity للويب؛ فارغ = بلا حد.
    RateLimiter::for('login-account', function (Request $request) {
        $account = strtolower(trim((string) ($request->input('pharmacy_id') ?? $request->input('identity'))));

        if ($account === '') {
            return Limit::none();
        }

        return Limit::perMinute(5)->by('acct|'.$account);
    });

    // Patient login accepts only "identifier" for its account-level limit.
    // Do not let unrelated request fields override the protected account key.
    RateLimiter::for('patient-login-account', function (Request $request) {
        $identifier = strtolower(trim((string) $request->input('identifier', '')));
        if ($identifier === '') {
            return Limit::none();
        }

        return Limit::perMinute(5)->by('patient|'.hash('sha256', $identifier));
    });

    // Prevent distributed reset-link spam and token guessing per email.
    RateLimiter::for('email-password-account', function (Request $request) {
        $email = strtolower(trim((string) $request->input('email', '')));
        return $email === '' ? Limit::none() : Limit::perMinute(5)->by('email-reset|'.hash('sha256', $email));
    });

    // M-9: حد مخصص لنقاط الكتابة المعرضة للإساءة (استفسارات، تقييمات، مزامنة، مخزون)
    RateLimiter::for('writes', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));

    // Link requires the current password: limit attempts per account, even
    // across IPs, without consuming the quota of unrelated pharmacy actions.
    RateLimiter::for('google-link-password', fn (Request $request) => Limit::perMinute(6)
        ->by('google-link|'.($request->user()?->id ?: $request->ip())));

    // إرسال الباركود من الجوال: المفتاح هو **جلسة المسح** لا المستخدم ولا IP.
    // السبب: تطبيق الجوال لا يسجّل دخولًا (يُفوَّض بتوكن الاقتران)، فلا user_id
    // لبناء المفتاح عليه؛ والـIP مشترك بين أجهزة شبكة الصيدلية فيحجب أبرياء.
    // بناء المفتاح والسقف في ScanSessionThrottle حتى لا يفترق الفرض عن العرض.
    RateLimiter::for(ScanSessionThrottle::LIMITER, fn (Request $request) => Limit::perMinute(ScanSessionThrottle::limit())
        ->by(ScanSessionThrottle::scopeKey($request->route('id'))));

    // مساعد المريض النصّي: حدّ مخصّص أضيق من 'api' العام.
    // السبب: كل طلب قد يستدعي خدمة AI خارجية (تكلفة tokens + زمن استجابة)،
    // ولا يجوز أن يكون استهلاكه بسقف 60/دقيقة المخصّص للقراءة العادية.
    RateLimiter::for('assistant', fn (Request $request) => Limit::perMinute(15)->by($request->user()?->id ?: $request->ip()));

    // الاستيراد الجماعي: حد منفصل تماماً عن 'writes'.
    // السبب: الاستيراد عملية ثقيلة بطبيعتها، ورفع الحد العام 'writes' يفتح
    // كل نقاط الكتابة الأخرى للإساءة. هنا نرفع السقف لكن على مفتاح أضيق.
    //
    // ⚠️ هذا الـ limiter يحرس **الأفعال** فقط (رفع/قرارات/تنفيذ/إلغاء) —
    // لا يحرس تحميل الصفحات ولا تنزيل القالب. راجع routes/web.php.
    //
    // بناء المفتاح والسقف يتمّان في InventoryImportThrottle حتى تبقى الواجهة
    // (التي تعرض الحصة المتبقية) والخادم (الذي يفرضها) على نفس المفتاح بالضبط.
    RateLimiter::for(InventoryImportThrottle::LIMITER, function (Request $request) {
        $user = $request->user();

        if ($user === null) {
            return Limit::perMinute(InventoryImportThrottle::GUEST_PER_MINUTE)
                ->by(InventoryImportThrottle::scopeKey(null, $request->ip()));
        }

        return Limit::perHour(InventoryImportThrottle::limit($user))
            ->by(InventoryImportThrottle::scopeKey($user));
    });
});

return $app;

