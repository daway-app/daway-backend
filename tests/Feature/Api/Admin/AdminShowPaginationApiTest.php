<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ترقيم صفحات عرض الصيدلية في لوحة الأدمن — **منقول من
 * `tests/Feature/Web/AdminShowPaginationTest.php`** (حُذف مع Blade).
 *
 * العقد المطلوب لبقاء واجهة React سليمة: `data.medicines` يحمل **صفحة واحدة**
 * (20 صفًّا)، و`data.medicines_pagination.total` يحمل **العدد الكامل** — لا
 * عدد الصفحة. لو صار total = عدد عناصر الصفحة، تختفي أسهم الترقيم في الواجهة.
 */
final class AdminShowPaginationApiTest extends TestCase
{
    public function test_pharmacy_show_paginates_inventory_and_reports_the_full_total(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $owner = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $owner->id]);

        // 25 دواءً مختلفًا ⇒ صفحتان بحجم 20
        foreach (range(1, 25) as $i) {
            $medicine = Medicine::factory()->create(['trade_name' => 'PAGINATED MED '.$i]);
            PharmacyMedicine::factory()->create([
                'pharmacy_id' => $pharmacy->id,
                'medicine_id' => $medicine->id,
                'quantity' => 5,
            ]);
        }

        $first = $this->getJson('/api/admin/pharmacies/'.$pharmacy->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.medicines_pagination.total', 25)
            ->assertJsonPath('data.medicines_pagination.current_page', 1)
            ->assertJsonPath('data.medicines_pagination.last_page', 2);

        $this->assertCount(20, $first->json('data.medicines'), 'الصفحة الأولى تحمل 20 صفًّا فقط');

        $second = $this->getJson('/api/admin/pharmacies/'.$pharmacy->id.'?page=2')
            ->assertOk()
            ->assertJsonPath('data.medicines_pagination.total', 25)
            ->assertJsonPath('data.medicines_pagination.current_page', 2);

        $this->assertCount(5, $second->json('data.medicines'), 'الصفحة الثانية تحمل الباقي');
    }

    public function test_pharmacy_show_is_limited_to_the_admin_role(): void
    {
        $owner = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $owner->id]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/admin/pharmacies/'.$pharmacy->id)->assertForbidden();
    }
}
