<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SetAppLocale
{
    /**
     * H3: يحدّد لغة التطبيق من عدة مصادر حسب نوع الـ request:
     *  1) session('locale') — للـ web requests.
     *  2) `?lang=ar|en` query param — للـ API requests (mobile apps).
     *  3) Accept-Language header — fallback للـ API requests.
     *  4) DB settings (default_language) — admin default.
     *  5) config('app.locale', 'en') — fallback نهائي.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = session('locale');

        if (! $locale && $request->has('lang')) {
            $locale = (string) $request->query('lang');
        }

        if (! $locale && $request->headers->has('Accept-Language')) {
            $locale = $this->parseAcceptLanguage($request->headers->get('Accept-Language'));
        }

        if (! $locale) {
            try {
                $locale = DB::table('settings')->where('key', 'default_language')->value('value');
            } catch (\Throwable) {
                $locale = null;
            }
        }

        if (! $locale) {
            $locale = config('app.locale', 'en');
        }

        if (! in_array($locale, ['ar', 'en'], true)) {
            $locale = 'en';
        }

        app()->setLocale($locale);

        return $next($request);
    }

    /**
     * يلتقط أفضل لغة مدعومة من Accept-Language header.
     * يقبل أنماط "ar", "ar-SA,en;q=0.9", "en-US,en;q=0.5,ar;q=0.3".
     */
    private function parseAcceptLanguage(?string $header): ?string
    {
        if (! $header) {
            return null;
        }

        $candidates = [];
        foreach (explode(',', $header) as $part) {
            $pieces = explode(';', trim($part));
            $tag = strtolower(trim($pieces[0]));
            if ($tag === '') {
                continue;
            }
            $q = 1.0;
            foreach (array_slice($pieces, 1) as $attr) {
                $attr = trim($attr);
                if (str_starts_with($attr, 'q=')) {
                    $q = (float) substr($attr, 2);
                }
            }
            $primary = substr($tag, 0, 2);
            $candidates[] = ['tag' => $tag, 'primary' => $primary, 'q' => $q];
        }

        usort($candidates, fn ($a, $b) => $b['q'] <=> $a['q']);

        foreach ($candidates as $c) {
            if (in_array($c['tag'], ['ar', 'en'], true)) {
                return $c['tag'];
            }
            if (in_array($c['primary'], ['ar', 'en'], true)) {
                return $c['primary'];
            }
        }

        return null;
    }
}

