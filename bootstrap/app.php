<?php

use App\Http\Middleware\EnsureProfileComplete;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SetAppLocale;
use App\Support\InventoryImportThrottle;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

Request::enableHttpMethodParameterOverride();

/**
 * هل الطلب على مسارات الاستيراد الجماعي؟
 *
 * نُقيّد معالج 429 بهذه المسارات فقط، حتى لا نغيّر سلوك بقية الحدود
 * (login / otp / register / throttle:api) التي تحتاج معالجة مختلفة.
 *
 * ويب: أسماء المسارات `pharmacy.inventory.import.*`
 * API: لا أسماء مسارات هناك، فنطابق المسار نفسه.
 */
$isInventoryImportRequest = static function (Request $request): bool {
    $name = (string) ($request->route()?->getName() ?? '');

    if (str_starts_with($name, 'pharmacy.inventory.import.')) {
        return true;
    }

    return $request->is(
        'pharmacy/inventory/import',
        'pharmacy/inventory/import/*',
        'api/pharmacy/inventory/import',
        'api/pharmacy/inventory/import/*',
    );
};

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // C-1/H-3: البروكسي الموثوق يُقرأ وقت الطلب من config('trustedproxy.proxies')
        // (يرسله middleware الـ TrustProxies المدمج) — آمن بعد config:cache ولا يُنفَّذ هنا أبداً.
        $middleware->throttleApi();

        $middleware->alias([
            'role' => EnsureRole::class,
            'profile.complete' => EnsureProfileComplete::class,
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

        $exceptions->render(fn (AccessDeniedHttpException $e, Request $request) => $request->expectsJson()
            ? response()->json(['success' => false, 'message' => 'غير مصرح لك بالوصول'], 403)
            : null);

        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->expectsJson()
            ? response()->json(['success' => false, 'message' => 'يجب تسجيل الدخول'], 401)
            : null);

        // HTTP 429 على مسارات الاستيراد: رسالة مفهومة + Retry-After، بدل صفحة
        // الخطأ الخام التي لا تقول للصيدلي كم ينتظر ولا ما العمل.
        //
        //  - طلب JSON (Flutter / fetch): JSON 429 مع `retry_after` بالثواني،
        //    ويقرأه العميل فيعيد المحاولة بعد الانتظار.
        //  - نموذج ويب (رفع/تنفيذ/إلغاء): إعادة توجيه إلى الصفحة نفسها التي
        //    جاء منها الطلب مع خطأ واضح، فيظهر في مكان بقية أخطاء الاستيراد.
        //
        // ملاحظة مقصودة: مسار الويب يعيد 302 لا 429. السبب أن الصيدلي أمامه
        // نموذج، والرسالة داخل الصفحة أنفع له من صفحة خطأ كاملة. وحتى لا يضيع
        // الحدّ عن المراقبة، نُسجّل كل حجب حقيقي كـ warning.
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

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'retry_after' => $retryAfter,
                ], 429, $headers);
            }

            // وجهة الإرجاع محدّدة صراحةً بدل back(): لا نعتمد على Referer قد يغيب.
            $name = (string) ($request->route()?->getName() ?? '');
            $uuid = (string) $request->route('import');

            if ($name !== 'pharmacy.inventory.import.preview' && $uuid !== '') {
                $target = redirect()->route('pharmacy.inventory.import.show', ['import' => $uuid]);
            } else {
                $target = redirect()->route('pharmacy.inventory.import.index');
            }

            return $target
                ->withErrors(['file' => $message])
                ->with('retry_after', $retryAfter);
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->expectsJson()) {
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

    // M-9: حد مخصص لنقاط الكتابة المعرضة للإساءة (استفسارات، تقييمات، مزامنة، مخزون)
    RateLimiter::for('writes', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));

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
