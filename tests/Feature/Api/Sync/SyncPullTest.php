<?php

namespace Tests\Feature\Api\Sync;

use App\Models\Medicine;
use App\Models\PatientInquiry;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\SyncState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SyncPullTest extends TestCase
{
    use RefreshDatabase;

    private function pharmacyUser(): User
    {
        return User::factory()->pharmacy()->create();
    }

    private function pharmacyFor(User $user): Pharmacy
    {
        return Pharmacy::factory()->create(['user_id' => $user->id]);
    }

    public function test_pull_requires_authentication(): void
    {
        $this->getJson('/api/sync/pull')->assertStatus(401);
    }

    public function test_pull_rejects_non_pharmacy_role(): void
    {
        Sanctum::actingAs(User::factory()->patient()->create());
        $this->getJson('/api/sync/pull')->assertStatus(403);
    }

    public function test_pull_returns_full_inventory_from_epoch(): void
    {
        $user = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($user);
        $medicine = Medicine::factory()->create(['trade_name' => 'Panadol']);
        $pm = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 12,
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/sync/pull');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.inventory')
            ->assertJsonPath('data.inventory.0.id', $pm->id)
            ->assertJsonPath('data.inventory.0.quantity', 12)
            ->assertJsonPath('data.inventory.0.medicine.trade_name', 'Panadol')
            ->assertJsonPath('data.deleted_pharmacy_medicine_ids', []);

        $this->assertDatabaseHas('sync_state', ['user_id' => $user->id, 'entity' => 'pharmacy']);
    }

    public function test_pull_returns_only_changes_since_cursor(): void
    {
        $user = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($user);
        $medicine = Medicine::factory()->create();

        $old = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 1,
        ]);
        $old->forceFill(['updated_at' => now()->subDays(2)])->save();

        $cursor = now()->subDay()->toIso8601String();

        // دواء تغيّر بعد الـ cursor
        $freshMedicine = Medicine::factory()->create();
        $fresh = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $freshMedicine->id,
            'quantity' => 99,
        ]);
        $fresh->forceFill(['updated_at' => now()])->save();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/sync/pull?since='.urlencode($cursor));

        $response->assertOk()->assertJsonCount(1, 'data.inventory');

        $ids = array_column($response->json('data.inventory'), 'id');
        $this->assertContains($fresh->id, $ids);
        $this->assertNotContains($old->id, $ids);
    }

    public function test_pull_includes_inquiries_for_pharmacy(): void
    {
        $user = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($user);
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();

        PatientInquiry::factory()->create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'status' => 'new',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/sync/pull');

        $response->assertOk()->assertJsonCount(1, 'data.inquiries');

        // pull ثانية بعد cursor السيرفر → لا تكرار
        $serverTime = $response->json('data.server_time');
        $second = $this->actingAs($user, 'sanctum')
            ->getJson('/api/sync/pull?since='.urlencode($serverTime));
        $second->assertOk()->assertJsonCount(0, 'data.inquiries');
    }

    public function test_pull_does_not_leak_other_pharmacies_data(): void
    {
        $user = $this->pharmacyUser();
        $this->pharmacyFor($user);

        $otherUser = $this->pharmacyUser();
        $otherPharmacy = $this->pharmacyFor($otherUser);
        $medicine = Medicine::factory()->create();
        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $otherPharmacy->id,
            'medicine_id' => $medicine->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/sync/pull');

        $response->assertOk()->assertJsonCount(0, 'data.inventory');
    }

    public function test_issue_token_for_session_pharmacy_user(): void
    {
        $user = $this->pharmacyUser();
        $this->pharmacyFor($user);

        $response = $this->actingAs($user)->postJson('/api/sync/token');

        $response->assertOk()
            ->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('data.token'));

        // التوكن يعمل فعلاً على pull
        $token = $response->json('data.token');
        $this->withToken($token)->getJson('/api/sync/pull')->assertOk();
    }

    public function test_issue_token_rejects_patient(): void
    {
        $this->actingAs(User::factory()->patient()->create())
            ->postJson('/api/sync/token')
            ->assertStatus(403);
    }

    public function test_issue_token_requires_session(): void
    {
        // middleware 'auth' يرفض الطلب قبل وصوله للـ controller
        $this->postJson('/api/sync/token')->assertStatus(401);
    }
}
