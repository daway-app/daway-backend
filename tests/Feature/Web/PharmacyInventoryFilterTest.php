<?php

namespace Tests\Feature\Web;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Tests\TestCase;

class PharmacyInventoryFilterTest extends TestCase
{
    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    public function test_inventory_search_filters_by_trade_name_arabic_and_english(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        $panadol = Medicine::factory()->create(['trade_name' => 'Panadol Extra', 'active_ingredient' => 'Paracetamol']);
        $brufen = Medicine::factory()->create(['trade_name' => 'Brufen 400', 'active_ingredient' => 'Ibuprofen']);
        $paramol = Medicine::factory()->create(['trade_name' => 'Paramol', 'trade_name_ar' => 'بارامول']);

        foreach ([$panadol, $brufen, $paramol] as $m) {
            PharmacyMedicine::create([
                'pharmacy_id' => $pharmacy->id,
                'medicine_id' => $m->id,
                'price' => 5,
                'quantity' => 20,
            ]);
        }

        $this->actingAs($user)->get('/pharmacy/inventory?q=Para')
            ->assertOk()
            ->assertSee('Panadol Extra')
            ->assertSee('Paramol')
            ->assertDontSee('Brufen 400');
    }

    public function test_inventory_search_filters_by_arabic_trade_name(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        $a = Medicine::factory()->create(['trade_name' => 'Panadol Extra', 'trade_name_ar' => 'بنادول اكسترا']);
        $b = Medicine::factory()->create(['trade_name' => 'Brufen 400', 'trade_name_ar' => 'بروفين']);

        foreach ([$a, $b] as $m) {
            PharmacyMedicine::create([
                'pharmacy_id' => $pharmacy->id,
                'medicine_id' => $m->id,
                'price' => 5,
                'quantity' => 20,
            ]);
        }

        $this->actingAs($user)->get('/pharmacy/inventory?q='.urlencode('بنادول'))
            ->assertOk()
            ->assertSee('Panadol Extra')
            ->assertDontSee('Brufen 400');
    }

    public function test_inventory_filter_by_status_out(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        $a = Medicine::factory()->create(['trade_name' => 'Alpha']);
        $b = Medicine::factory()->create(['trade_name' => 'Beta']);

        PharmacyMedicine::create(['pharmacy_id' => $pharmacy->id, 'medicine_id' => $a->id, 'price' => 1, 'quantity' => 50]);
        PharmacyMedicine::create(['pharmacy_id' => $pharmacy->id, 'medicine_id' => $b->id, 'price' => 1, 'quantity' => 0]);

        $this->actingAs($user)->get('/pharmacy/inventory?status=out')
            ->assertOk()
            ->assertSee('Beta')
            ->assertDontSee('Alpha');
    }

    public function test_inventory_stats_remain_global_when_filter_is_applied(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        $a = Medicine::factory()->create(['trade_name' => 'Alpha']);
        $b = Medicine::factory()->create(['trade_name' => 'Beta']);

        PharmacyMedicine::create(['pharmacy_id' => $pharmacy->id, 'medicine_id' => $a->id, 'price' => 1, 'quantity' => 50]);
        PharmacyMedicine::create(['pharmacy_id' => $pharmacy->id, 'medicine_id' => $b->id, 'price' => 1, 'quantity' => 0]);

        // الفلاتر تخفي بيتا لكن الإحصائيات تبقى شاملة (متوفر=1، نافد=1)
        $this->actingAs($user)->get('/pharmacy/inventory?status=ok')
            ->assertOk()
            ->assertSee('Alpha')
            ->assertDontSee('Beta');
    }
}
