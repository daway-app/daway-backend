<?php

namespace Tests\Feature\Api\Sync;

use App\Models\Medicine;
use App\Models\PatientInquiry;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\SyncOperation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SyncPushTest extends TestCase
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

    private function push(User $user, array $operations)
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/sync/push', [
            'operations' => $operations,
        ]);
    }

    public function test_push_requires_authentication(): void
    {
        $this->postJson('/api/sync/push', ['operations' => []])->assertStatus(401);
    }

    public function test_push_rejects_non_pharmacy_role(): void
    {
        $patient = User::factory()->patient()->create();

        Sanctum::actingAs($patient);
        $this->postJson('/api/sync/push', ['operations' => []])->assertStatus(403);
    }

    public function test_push_validates_operation_shape(): void
    {
        $user = $this->pharmacyUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/sync/push', ['operations' => [
            ['uuid' => 'not-a-uuid', 'op_type' => 'inventory.update', 'payload' => []],
        ]])->assertStatus(422);

        $this->postJson('/api/sync/push', ['operations' => [
            ['uuid' => (string) Str::uuid(), 'op_type' => 'unknown.op', 'payload' => []],
        ]])->assertStatus(422);
    }

    public function test_push_applies_inventory_update(): void
    {
        $user = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($user);
        $medicine = Medicine::factory()->create();
        $pm = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 5,
            'is_available' => true,
        ]);

        $response = $this->push($user, [[
            'uuid' => (string) Str::uuid(),
            'op_type' => 'inventory.update',
            'payload' => ['items' => [['pharmacy_medicine_id' => $pm->id, 'quantity' => 42]]],
        ]]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.results.0.status', 'applied');

        $this->assertDatabaseHas('pharmacy_medicines', [
            'id' => $pm->id,
            'quantity' => 42,
            'is_available' => true,
        ]);
        $this->assertDatabaseHas('sync_operations', [
            'uuid' => $response->json('data.results.0.uuid'),
            'status' => 'applied',
        ]);
    }

    public function test_push_is_idempotent_for_same_uuid(): void
    {
        $user = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($user);
        $medicine = Medicine::factory()->create();
        $pm = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 5,
        ]);

        $uuid = (string) Str::uuid();
        $op = [[
            'uuid' => $uuid,
            'op_type' => 'inventory.update',
            'payload' => ['items' => [['pharmacy_medicine_id' => $pm->id, 'quantity' => 42]]],
        ]];

        $this->push($user, $op)->assertOk();
        $this->push($user, $op)->assertOk()
            ->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.0.duplicate', true);

        $this->assertSame(1, SyncOperation::where('uuid', $uuid)->count());
        $this->assertDatabaseHas('pharmacy_medicines', ['id' => $pm->id, 'quantity' => 42]);
    }

    public function test_push_batch_with_duplicate_uuid_applies_once(): void
    {
        $user = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($user);
        $medicine = Medicine::factory()->create();
        $pm = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
        ]);

        $uuid = (string) Str::uuid();
        $op = [[
            'uuid' => $uuid,
            'op_type' => 'inventory.update',
            'payload' => ['items' => [['pharmacy_medicine_id' => $pm->id, 'quantity' => 42]]],
        ]];

        $response = $this->push($user, array_merge($op, $op))->assertOk();

        $this->assertCount(2, $response->json('data.results'));
        $this->assertSame(1, SyncOperation::where('uuid', $uuid)->count());
    }

    public function test_inventory_update_conflict_when_client_timestamp_older(): void
    {
        $user = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($user);
        $medicine = Medicine::factory()->create();
        $pm = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 7,
        ]);
        // جعل updated_at أحدث من وقت العميل
        $pm->forceFill(['updated_at' => now()->addMinutes(5)])->save();

        $response = $this->push($user, [[
            'uuid' => (string) Str::uuid(),
            'op_type' => 'inventory.update',
            'client_updated_at' => now()->toIso8601String(),
            'payload' => ['items' => [['pharmacy_medicine_id' => $pm->id, 'quantity' => 99]]],
        ]]);

        $response->assertOk()
            ->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.0.data.items.0.status', 'conflict');

        // قيمة السيرفر بقيت كما هي (Server Wins)
        $this->assertDatabaseHas('pharmacy_medicines', ['id' => $pm->id, 'quantity' => 7]);
    }

    public function test_medicine_store_is_idempotent_on_duplicate(): void
    {
        $user = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($user);
        $medicine = Medicine::factory()->create(['trade_name' => 'Panadol']);

        $op = [[
            'uuid' => (string) Str::uuid(),
            'op_type' => 'medicine.store',
            'payload' => [
                'trade_name' => 'Panadol',
                'active_ingredient' => 'Paracetamol',
                'price' => 10,
                'quantity' => 20,
            ],
        ]];

        $this->push($user, $op)->assertOk()
            ->assertJsonPath('data.results.0.status', 'applied');

        // نفس العملية برقم uuid مختلف — الدواء مضاف مسبقاً → نجاح idempotent
        $this->push($user, [[
            'uuid' => (string) Str::uuid(),
            'op_type' => 'medicine.store',
            'payload' => [
                'trade_name' => 'Panadol',
                'active_ingredient' => 'Paracetamol',
                'price' => 10,
                'quantity' => 20,
            ],
        ]])->assertOk()->assertJsonPath('data.results.0.status', 'applied');

        $this->assertSame(1, PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('medicine_id', $medicine->id)->count());
    }

    public function test_medicine_store_creates_new_medicine(): void
    {
        $user = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($user);

        $this->push($user, [[
            'uuid' => (string) Str::uuid(),
            'op_type' => 'medicine.store',
            'payload' => [
                'trade_name' => 'BrandNewMed',
                'active_ingredient' => 'Testicine',
                'price' => 15,
                'quantity' => 3,
            ],
        ]])->assertOk()->assertJsonPath('data.results.0.status', 'applied');

        $medicine = Medicine::where('trade_name', 'BrandNewMed')->first();
        $this->assertNotNull($medicine);
        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 3,
        ]);
    }

    public function test_medicine_update_rejects_other_pharmacys_row(): void
    {
        $user = $this->pharmacyUser();
        $this->pharmacyFor($user);

        $otherUser = $this->pharmacyUser();
        $otherPharmacy = $this->pharmacyFor($otherUser);
        $medicine = Medicine::factory()->create();
        $otherPm = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $otherPharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 10,
        ]);

        $this->push($user, [[
            'uuid' => (string) Str::uuid(),
            'op_type' => 'medicine.update',
            'payload' => ['pharmacy_medicine_id' => $otherPm->id, 'price' => 999],
        ]])->assertOk()->assertJsonPath('data.results.0.status', 'failed');

        $this->assertDatabaseHas('pharmacy_medicines', ['id' => $otherPm->id, 'price' => 10]);
    }

    public function test_inquiry_status_updates_owned_inquiry_only(): void
    {
        $user = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($user);
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();

        $inquiry = PatientInquiry::factory()->create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'status' => 'new',
        ]);

        $this->push($user, [[
            'uuid' => (string) Str::uuid(),
            'op_type' => 'inquiry.status',
            'payload' => ['inquiry_id' => $inquiry->id, 'status' => 'answered'],
        ]])->assertOk()->assertJsonPath('data.results.0.status', 'applied');

        $this->assertDatabaseHas('patient_inquiries', ['id' => $inquiry->id, 'status' => 'answered']);

        // حالة غير صالحة
        $this->push($user, [[
            'uuid' => (string) Str::uuid(),
            'op_type' => 'inquiry.status',
            'payload' => ['inquiry_id' => $inquiry->id, 'status' => 'bogus'],
        ]])->assertOk()->assertJsonPath('data.results.0.status', 'failed');
    }

    public function test_failed_operation_is_recorded_and_batch_continues(): void
    {
        $user = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($user);
        $medicine = Medicine::factory()->create();
        $pm = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 5,
        ]);

        $response = $this->push($user, [
            [
                'uuid' => (string) Str::uuid(),
                'op_type' => 'medicine.store',
                'payload' => ['trade_name' => '', 'active_ingredient' => ''],
            ],
            [
                'uuid' => (string) Str::uuid(),
                'op_type' => 'inventory.update',
                'payload' => ['items' => [['pharmacy_medicine_id' => $pm->id, 'quantity' => 33]]],
            ],
        ])->assertOk();

        $results = $response->json('data.results');
        $this->assertSame('failed', $results[0]['status']);
        $this->assertSame('applied', $results[1]['status']);
        $this->assertNotNull($results[0]['error']);
        $this->assertDatabaseHas('pharmacy_medicines', ['id' => $pm->id, 'quantity' => 33]);
    }

    public function test_low_stock_notification_fires_once_after_push(): void
    {
        $user = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($user);
        $medicine = Medicine::factory()->create(['trade_name' => 'LowStockMed']);
        $pm = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 50,
            'is_available' => true,
        ]);

        $op = [[
            'uuid' => (string) Str::uuid(),
            'op_type' => 'inventory.update',
            'payload' => ['items' => [['pharmacy_medicine_id' => $pm->id, 'quantity' => 5]]],
        ]];

        $this->push($user, $op)->assertOk();
        $this->assertSame(1, \App\Models\Notification::where('user_id', $user->id)
            ->where('type', 'low_stock')->count());

        // إعادة إرسال نفس العملية (idempotent) لا تضاعف الإشعار
        $this->push($user, $op)->assertOk();
        $this->assertSame(1, \App\Models\Notification::where('user_id', $user->id)
            ->where('type', 'low_stock')->count());
    }
}
