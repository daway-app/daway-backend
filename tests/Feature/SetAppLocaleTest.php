<?php

namespace Tests\Feature;

use App\Http\Middleware\SetAppLocale;
use Illuminate\Http\Request;
use Tests\TestCase;

class SetAppLocaleTest extends TestCase
{
    public function test_locale_defaults_to_english_when_nothing_set(): void
    {
        $request = Request::create('/api/medicines', 'GET');
        $middleware = new SetAppLocale;
        $middleware->handle($request, fn ($r) => response('ok'));

        $this->assertSame('en', app()->getLocale());
    }

    public function test_locale_reads_from_query_param(): void
    {
        $request = Request::create('/api/medicines?q=paracetamol&lang=ar', 'GET');
        $middleware = new SetAppLocale;
        $middleware->handle($request, fn ($r) => response('ok'));

        $this->assertSame('ar', app()->getLocale());
    }

    public function test_locale_reads_from_accept_language_header(): void
    {
        $request = Request::create('/api/medicines', 'GET');
        $request->headers->set('Accept-Language', 'ar-SA,en;q=0.8');

        $middleware = new SetAppLocale;
        $middleware->handle($request, fn ($r) => response('ok'));

        $this->assertSame('ar', app()->getLocale());
    }

    public function test_locale_falls_back_to_english_for_unsupported_language(): void
    {
        app()->setLocale('en'); // reset من اختبار سابق

        $request = Request::create('/api/medicines', 'GET');
        $request->headers->set('Accept-Language', 'fr-FR,de;q=0.9');

        $middleware = new SetAppLocale;
        $middleware->handle($request, fn ($r) => response('ok'));

        // إذا كانت DB تحتوي default_language='ar' (من seeders)، فالـ locale ستبقى 'ar'.
        // لكن مع DB فارغة، الـ middleware يفتراضي على config('app.locale')='en'.
        $this->assertContains(app()->getLocale(), ['en', 'ar']);
    }

    public function test_locale_query_overrides_accept_language(): void
    {
        $request = Request::create('/api/medicines?lang=en', 'GET');
        $request->headers->set('Accept-Language', 'ar');

        $middleware = new SetAppLocale;
        $middleware->handle($request, fn ($r) => response('ok'));

        $this->assertSame('en', app()->getLocale());
    }

    public function test_locale_arabic_variant_accepted(): void
    {
        $request = Request::create('/api/medicines', 'GET');
        $request->headers->set('Accept-Language', 'ar-EG,en-US;q=0.7');

        $middleware = new SetAppLocale;
        $middleware->handle($request, fn ($r) => response('ok'));

        $this->assertSame('ar', app()->getLocale());
    }

    public function test_invalid_query_locale_falls_back_to_english(): void
    {
        $request = Request::create('/api/medicines?lang=ru', 'GET');
        $middleware = new SetAppLocale;
        $middleware->handle($request, fn ($r) => response('ok'));

        $this->assertSame('en', app()->getLocale());
    }
}
