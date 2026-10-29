<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Routing\RouteCollection;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint Part 1 — فصل حِدّي (Critical Decoupling).
 *
 * الهدف: أن يعمل الـAPI وفحص صحة النشر **بلا** أي اعتماد على مسارات الويب،
 * حتى لا يسقط أيٌّ منهما عند حذف واجهة Blade.
 *
 * كل اختبار هنا يفشل على الكود **قبل** الإصلاح (302 بدل 401/403، أو
 * RouteNotFoundException) — فهي حرّاس حقيقيون لا تأكيدات شكلية.
 */
class CriticalApiDecouplingTest extends TestCase
{
    public function test_healthz_is_not_registered_on_the_web_middleware_group(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'healthz');

        $this->assertNotNull($route, '/healthz must stay registered');
        $this->assertNotContains(
            'web',
            $route->gatherMiddleware(),
            '/healthz must not depend on the web route group (routes/web.php is being removed)'
        );
    }

    public function test_healthz_survives_removal_of_every_other_route(): void
    {
        $router = app('router');

        // أبقِ `/healthz` و`/up` فقط — كما لو حُذفت واجهة الويب كلها.
        $kept = new RouteCollection;

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (in_array($route->uri(), ['healthz', 'up'], true)) {
                $kept->add($route);
            }
        }

        $router->setRoutes($kept);

        $this->assertNull(
            $router->getRoutes()->getByName('login.show'),
            'precondition: no web routes should remain'
        );

        // عقد render.yaml: نفس المسار، نفس الاستجابة، بلا مصادقة.
        $this->getJson('/healthz')
            ->assertOk()
            ->assertJsonStructure(['status', 'db', 'fcm']);
    }

    public function test_api_role_denial_returns_403_without_the_json_accept_header(): void
    {
        $pharmacy = User::factory()->pharmacy()->create();
        Sanctum::actingAs($pharmacy);

        // بلا `Accept: application/json`: قبل الإصلاح كان الطلب يهبط إلى فرع
        // الويب ويُعاد توجيهه (302) عبر route('pharmacy.dashboard.index').
        $this->withHeader('Accept', 'text/html')
            ->get('/api/admin/patients')
            ->assertStatus(403);
    }

    public function test_unauthenticated_api_request_returns_401_without_the_json_accept_header(): void
    {
        // قبل الإصلاح: معالج Laravel الافتراضي ينادي route('login') ⇒ 302 الآن
        // وRouteNotFoundException بعد حذف مسارات الويب.
        $this->withHeader('Accept', 'text/html')
            ->get('/api/admin/patients')
            ->assertStatus(401);
    }
}
