<?php

namespace Tests\Feature\Api\Pharmacy;

use App\Models\Medicine;
use App\Models\MedicineBarcode;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/pharmacy/accounting/inventory/search — بحث POS سيرفري.
 *
 * يثبت ثلاثة أشياء لا يثبتها اختبار الردّ:
 *   1. العزل بين الصيدليات (لا صنف لغيرك ولو تطابق الاسم).
 *   2. أن السعر والكمية يأتيان من المخزون لا من الكتالوج.
 *   3. أن الترقيم يعمل فعلًا (صفحة 2 تُرجع صفوفًا مختلفة).
 */
final class AccountingInventorySearchTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Pharmacy} */
    private function pharmacyUser(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    private function stock(
        Pharmacy $pharmacy,
        string $name,
        string $ingredient = 'Paracetamol',
        float $price = 20.00,
        int $qty = 10,
        bool $available = true
    ): PharmacyMedicine {
        $medicine = Medicine::factory()->create([
            'trade_name' => $name,
            'active_ingredient' => $ingredient,
        ]);

        return PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => $price,
            'quantity' => $qty,
            'is_available' => $available,
        ]);
    }

    public function test_searches_by_trade_name_and_returns_stock_price_and_quantity(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $row = $this->stock($pharmacy, 'بنادول اكسترا', price: 27.50, qty: 8);

        Sanctum::actingAs($user);

        $this->getJson('/api/pharmacy/accounting/inventory/search?q=بنادول')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.pharmacy_medicine_id', $row->id)
            ->assertJsonPath('data.0.name', 'بنادول اكسترا')
            ->assertJsonPath('data.0.price', 27.5)
            ->assertJsonPath('data.0.quantity', 8)
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_searches_by_active_ingredient(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $this->stock($pharmacy, 'دواء أ', 'Ibuprofen');
        $this->stock($pharmacy, 'دواء ب', 'Paracetamol');

        Sanctum::actingAs($user);

        $this->getJson('/api/pharmacy/accounting/inventory/search?q=Ibuprofen')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.name', 'دواء أ');
    }

    public function test_searches_by_barcode(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $row = $this->stock($pharmacy, 'دواء باركود', price: 13.00, qty: 4);

        MedicineBarcode::create([
            'moh_medicine_id' => null,
            'local_medicine_id' => $row->medicine_id,
            'barcode' => '6291041500213',
            'barcode_type' => 'EAN13',
            'source' => 'test',
            'confidence' => 1,
            'is_verified' => true,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/pharmacy/accounting/inventory/search?barcode=6291041500213')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.pharmacy_medicine_id', $row->id)
            ->assertJsonPath('data.0.barcode', '6291041500213');
    }

    public function test_unknown_barcode_returns_empty_result(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $this->stock($pharmacy, 'دواء');

        Sanctum::actingAs($user);

        $this->getJson('/api/pharmacy/accounting/inventory/search?barcode=0000000000000')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('pagination.total', 0);
    }

    public function test_never_returns_another_pharmacy_stock(): void
    {
        [$userA, $pharmacyA] = $this->pharmacyUser();
        [, $pharmacyB] = $this->pharmacyUser();

        $this->stock($pharmacyA, 'بنادول خاص بي');
        $this->stock($pharmacyB, 'بنادول خاص بغيري');

        Sanctum::actingAs($userA);

        $response = $this->getJson('/api/pharmacy/accounting/inventory/search?q=بنادول')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);

        $this->assertSame('بنادول خاص بي', $response->json('data.0.name'));
    }

    public function test_pagination_returns_different_rows_on_page_two(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();

        for ($i = 1; $i <= 5; $i++) {
            $this->stock($pharmacy, 'دواء رقم '.$i);
        }

        Sanctum::actingAs($user);

        $page1 = $this->getJson('/api/pharmacy/accounting/inventory/search?q=دواء&per_page=2&page=1')
            ->assertOk()
            ->assertJsonPath('pagination.total', 5)
            ->assertJsonPath('pagination.last_page', 3);

        $page2 = $this->getJson('/api/pharmacy/accounting/inventory/search?q=دواء&per_page=2&page=2')
            ->assertOk();

        $this->assertCount(2, $page1->json('data'));
        $this->assertCount(2, $page2->json('data'));

        $ids1 = array_column($page1->json('data'), 'pharmacy_medicine_id');
        $ids2 = array_column($page2->json('data'), 'pharmacy_medicine_id');

        $this->assertEmpty(array_intersect($ids1, $ids2));
    }

    public function test_short_query_returns_empty_without_touching_db(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $this->stock($pharmacy, 'دواء');

        Sanctum::actingAs($user);

        $this->getJson('/api/pharmacy/accounting/inventory/search?q=د')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_only_available_filter_excludes_out_of_stock(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $this->stock($pharmacy, 'دواء متوفر', qty: 5);
        $this->stock($pharmacy, 'دواء نافد', qty: 0, available: false);

        Sanctum::actingAs($user);

        $this->getJson('/api/pharmacy/accounting/inventory/search?q=دواء&only_available=1')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.name', 'دواء متوفر');
    }

    public function test_low_stock_and_out_of_stock_flags_are_reported(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $this->stock($pharmacy, 'دواء قليل', qty: 2);
        $this->stock($pharmacy, 'دواء نافد', qty: 0, available: false);

        Sanctum::actingAs($user);

        $data = $this->getJson('/api/pharmacy/accounting/inventory/search?q=دواء')
            ->assertOk()
            ->json('data');

        $low = collect($data)->firstWhere('name', 'دواء قليل');
        $out = collect($data)->firstWhere('name', 'دواء نافد');

        $this->assertTrue($low['is_low_stock']);
        $this->assertTrue($out['is_out_of_stock']);
    }

    public function test_patient_cannot_use_pos_search(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);
        Sanctum::actingAs($patient);

        $this->getJson('/api/pharmacy/accounting/inventory/search?q=بنادول')
            ->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/pharmacy/accounting/inventory/search?q=بنادول')
            ->assertStatus(401);
    }
}
