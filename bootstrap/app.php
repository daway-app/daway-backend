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
        $proxies = env('TRUSTED_PROXIES');
        $middleware->trustProxies(at: $proxies ? explode(',', $proxies) : []);

        $middleware->throttleApi();

        $middleware->alias([
            'role' => EnsureRole::class,
            'profile.complete' => EnsureProfileComplete::class,
        ]);

        $middleware->web(append: [
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
});

return $app;
