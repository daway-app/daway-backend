<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Phase D: Swagger يجب أن يبقى عاملًا بعد حذف واجهة Blade.
 *
 * الصفحة `/api/documentation` تعتمد على القالب الوحيد الذي أُبقي من
 * `resources/views` (`vendor/l5-swagger/index.blade.php`) وعلى مسار الأصول
 * الذي تسجّله الحزمة (`docs/asset/{asset}`) — لا على `@vite` ولا على
 * `resources/js|css` المحذوفة. هذا الاختبار يمنع كسر ذلك بصمت.
 */
final class SwaggerDocumentationTest extends TestCase
{
    public function test_documentation_page_renders_after_blade_removal(): void
    {
        $response = $this->get('/api/documentation');

        $response->assertOk();
        // الهيكل الأساسي للواجهة (لا يعتمد على بناء Vite)
        $response->assertSee('swagger-ui', false);
        $response->assertSee('swagger-ui-bundle.js', false);
    }

    public function test_swagger_assets_route_is_registered(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => str_starts_with($r->uri(), 'docs/asset/'));

        $this->assertNotNull($route, 'مسار أصول Swagger مفقود — الصفحة ستظهر بلا تنسيق');
    }
}
