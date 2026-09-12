<?php

namespace Tests\Feature\Web;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * فحص انحدار شامل (Smoke) لكل صفحات الويب.
 *
 * يحوّل قائمة «REGRESSION CHECK» اليدوية إلى اختبار آلي: أي خطأ 500 في أي صفحة
 * (من Blade أو PHP أو استعلام) يُفشل الاختبار. هذا هو الدليل البديل عن الفحص بالمتصفح.
 *
 * ملاحظة: هذا لا يقيس الأداء ولا يرسم الصفحة — يتحقق فقط أن كل صفحة تُبنى وتُخدَم
 * بلا خطأ سيرفر.
 */
class WebSmokeRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** صفحات الأدمن المتوقّع أن تُرجع 200 مباشرة. */
    public static function adminPages(): array
    {
        return [
            'dashboard' => ['/'],
            'users' => ['/users'],
            'users create' => ['/users/create'],
            'medicines' => ['/medicines'],
            'medicines create' => ['/medicines/create'],
            'pharmacies' => ['/pharmacies'],
            'pharmacies create' => ['/pharmacies/create'],
            'patients' => ['/patients'],
            'categories' => ['/categories'],
            'categories create' => ['/categories/create'],
            'inventory' => ['/inventory'],
            'logs' => ['/logs'],
            'settings' => ['/settings'],
            'notifications' => ['/notifications'],
            'profile' => ['/profile'],
        ];
    }

    /** صفحات الصيدلية — تتطلّب مستخدماً بدور pharmacy وملفاً مكتملاً. */
    public static function pharmacyPages(): array
    {
        return [
            'pharmacy dashboard' => ['/pharmacy/dashboard'],
            'pharmacy inventory' => ['/pharmacy/inventory'],
            'pharmacy medicines' => ['/pharmacy/medicines'],
            'pharmacy medicines create' => ['/pharmacy/medicines/create'],
            'pharmacy inquiries' => ['/pharmacy/inquiries'],
            'pharmacy alternatives' => ['/pharmacy/alternatives'],
            'pharmacy alternatives create' => ['/pharmacy/alternatives/create'],
            'pharmacy ratings' => ['/pharmacy/ratings'],
            'pharmacy profile' => ['/pharmacy/profile'],
        ];
    }

    #[DataProvider('adminPages')]
    public function test_admin_page_renders_without_server_error(string $uri): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get($uri);

        $this->assertLessThan(
            500,
            $response->getStatusCode(),
            "الصفحة {$uri} أرجعت {$response->getStatusCode()} — خطأ سيرفر"
        );

        $response->assertOk();
    }

    #[DataProvider('pharmacyPages')]
    public function test_pharmacy_page_renders_without_server_error(string $uri): void
    {
        $user = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get($uri);

        $this->assertLessThan(
            500,
            $response->getStatusCode(),
            "الصفحة {$uri} أرجعت {$response->getStatusCode()} — خطأ سيرفر"
        );

        $response->assertOk();
    }

    /** صفحتا التفاصيل المعدَّلتان — ببيانات حقيقية (صفوف مخزون موجودة). */
    public function test_show_pages_render_with_data(): void
    {
        $admin = User::factory()->admin()->create();

        $pharmacy = Pharmacy::factory()->create();
        $medicine = Medicine::factory()->create(['trade_name' => 'SMOKE-MED']);

        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 9,
            'quantity' => 4,
        ]);

        $this->actingAs($admin)
            ->get(route('pharmacies.show', $pharmacy->id))
            ->assertOk()
            ->assertSee('SMOKE-MED');

        $this->actingAs($admin)
            ->get(route('medicines.show', $medicine->id))
            ->assertOk()
            ->assertSee($pharmacy->pharmacy_name);
    }

    /** تصدير السجلات (Excel) — لا يجب أن يرمي خطأ. */
    public function test_logs_export_does_not_error(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/logs/export-excel');

        $this->assertLessThan(500, $response->getStatusCode());
    }

    /** الصفحات العامة: يجب أن تُخدَم بلا خطأ سيرفر. */
    public function test_public_pages_do_not_error(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/healthz')->assertOk();
        $this->get('/offline')->assertOk();
    }
}
