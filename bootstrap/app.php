<?php

use App\Http\Middleware\EnsureProfileComplete;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SetAppLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

Request::enableHttpMethodParameterOverride();

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
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $request->expectsJson()
            ? response()->json(['success' => false, 'message' => 'غير موجود'], 404)
            : null);

        $exceptions->render(fn (AccessDeniedHttpException $e, Request $request) => $request->expectsJson()
            ? response()->json(['success' => false, 'message' => 'غير مصرح لك بالوصول'], 403)
            : null);

        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->expectsJson()
            ? response()->json(['success' => false, 'message' => 'يجب تسجيل الدخول'], 401)
            : null);

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
    // المفتاح = user_id + pharmacy_id (وليس IP): خلف موازن Render يشترك كثير
    // من المستخدمين في نفس IP، فالمفتاح المعتمد على IP يصبح دلو مشتركاً
    // يحجب مستخدمين أبرياء. الحساب نفسه هو الوحدة الصحيحة للحد هنا.
    RateLimiter::for('inventory-import', function (Request $request) {
        $user = $request->user();

        if ($user === null) {
            return Limit::perMinute(5)->by('ip|'.$request->ip());
        }

        $pharmacyId = (int) ($user->pharmacy?->id ?? 0);

        return Limit::perHour(max(1, (int) config('inventory_import.rate_limit_per_hour', 10)))
            ->by('import|'.$user->id.'|'.$pharmacyId);
    });
});

return $app;
