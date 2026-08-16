<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SetAppLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
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
        ]);

        $middleware->web(append: [
            SetAppLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {})->create();
