<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SetAppLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = session('locale');

        if (! $locale) {
            $locale = DB::table('settings')->where('key', 'default_language')->value('value') ?: config('app.locale', 'en');
        }

        if (! in_array($locale, ['ar', 'en'])) {
            $locale = 'en';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
