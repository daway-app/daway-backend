<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AlternativePairProtectionTest extends TestCase
{
    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    public function test_self_pair_is_rejected(): void
    {
        // H6: دواء لا يمكن أن يكون بديلاً لنفسه.
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create(['trade_name' => 'Panadol', 'active_ingredient' => 'Paracetamol']);

        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/alternatives', [
            'base_medicine_id' => $pm->id,
            'alternative_id' => $medicine->id, // نفس الدواء
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('alternative_medicine')->count());
    }

    public function test_reverse_pair_is_rejected(): void
    {
        // H6: إذا (B → A) مسجلة، لا يُسمح بإضافة (A → B).
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $panadol = Medicine::factory()->create(['trade_name' => 'Panadol', 'active_ingredient' => 'Paracetamol']);
        $adol = Medicine::factory()->create(['trade_name' => 'Adol', 'active_ingredient' => 'Paracetamol']);

        $pmPanadol = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $panadol->id,
            'price' => 5,
            'quantity' => 10,
        ]);
        $pmAdol = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $adol->id,
            'price' => 5,
            'quantity' => 10,
        ]);

        Sanctum::actingAs($user);

        // (Panadol ← Adol) يُضاف بنجاح
        $this->postJson('/api/pharmacy/alternatives', [
            'base_medicine_id' => $pmPanadol->id,
            'alternative_id' => $adol->id,
        ])->assertStatus(201);

        // (Adol ← Panadol) — العكس — يجب أن يُرفض بـ 409
        $this->postJson('/api/pharmacy/alternatives', [
            'base_medicine_id' => $pmAdol->id,
            'alternative_id' => $panadol->id,
        ])->assertStatus(409);

        $this->assertSame(1, DB::table('alternative_medicine')->count());
    }

    public function test_forward_pair_still_allowed(): void
    {
        // H6: الزوج العادي غير المعكوس يبقى مسموحاً.
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $a = Medicine::factory()->create(['trade_name' => 'Alpha', 'active_ingredient' => 'X']);
        $b = Medicine::factory()->create(['trade_name' => 'Beta', 'active_ingredient' => 'X']);

        $pmA = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $a->id,
            'price' => 5,
            'quantity' => 10,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/alternatives', [
            'base_medicine_id' => $pmA->id,
            'alternative_id' => $b->id,
        ])->assertStatus(201);

        $this->assertSame(1, DB::table('alternative_medicine')->count());
    }
}
