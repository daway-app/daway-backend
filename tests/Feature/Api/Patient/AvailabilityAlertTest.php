<?php

namespace Tests\Feature\Api\Patient;

use App\Models\AvailabilityNotification;
use App\Models\Medicine;
use App\Models\Notification;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AvailabilityAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_alerts_requires_authentication(): void
    {
        $this->getJson('/api/patient/availability-alerts')->assertStatus(401);
        $this->postJson('/api/patient/availability-alerts')->assertStatus(401);
        $this->deleteJson('/api/patient/availability-alerts/1')->assertStatus(401);
    }

    public function test_alerts_rejects_non_patient(): void
    {
        Sanctum::actingAs(User::factory()->pharmacy()->create());

        $this->getJson('/api/patient/availability-alerts')->assertStatus(403);
    }

    public function test_create_alert_succeeds_with_pharmacy_id(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();
        $pharmacy = Pharmacy::factory()->create();

        Sanctum::actingAs($patient);
        $this->postJson('/api/patient/availability-alerts', [
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $pharmacy->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('availability_notifications', [
            'user_id' => $patient->id,
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $pharmacy->id,
            'is_notified' => false,
        ]);
    }

    public function test_create_alert_succeeds_with_null_pharmacy_id(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();

        Sanctum::actingAs($patient);
        $this->postJson('/api/patient/availability-alerts', [
            'medicine_id' => $medicine->id,
        ])->assertStatus(201);

        $alert = AvailabilityNotification::first();
        $this->assertNotNull($alert);
        $this->assertNull($alert->pharmacy_id);
    }

    public function test_duplicate_alert_returns_existing_row(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();
        $pharmacy = Pharmacy::factory()->create();

        Sanctum::actingAs($patient);
        $this->postJson('/api/patient/availability-alerts', [
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $pharmacy->id,
        ])->assertStatus(201);
        $this->postJson('/api/patient/availability-alerts', [
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $pharmacy->id,
        ])->assertStatus(200);

        $this->assertSame(1, AvailabilityNotification::count());
    }

    public function test_list_alerts_returns_only_my_alerts(): void
    {
        $a = User::factory()->patient()->create();
        $b = User::factory()->patient()->create();
        $m1 = Medicine::factory()->create();
        $m2 = Medicine::factory()->create();

        Sanctum::actingAs($a);
        $this->postJson('/api/patient/availability-alerts', ['medicine_id' => $m1->id])->assertStatus(201);
        $this->postJson('/api/patient/availability-alerts', ['medicine_id' => $m2->id])->assertStatus(201);

        Sanctum::actingAs($b);
        $this->postJson('/api/patient/availability-alerts', ['medicine_id' => $m1->id])->assertStatus(201);

        Sanctum::actingAs($a);
        $aIds = $this->getJson('/api/patient/availability-alerts')
            ->assertOk()
            ->json('data.*.medicine_id');
        $this->assertEqualsCanonicalizing([$m1->id, $m2->id], $aIds);

        Sanctum::actingAs($b);
        $bIds = $this->getJson('/api/patient/availability-alerts')
            ->assertOk()
            ->json('data.*.medicine_id');
        $this->assertEquals([$m1->id], $bIds);
    }

    public function test_delete_alert_is_idor_safe(): void
    {
        $a = User::factory()->patient()->create();
        $b = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();

        Sanctum::actingAs($a);
        $r = $this->postJson('/api/patient/availability-alerts', ['medicine_id' => $medicine->id]);
        $r->assertStatus(201);
        $alertId = $r->json('data.id');

        Sanctum::actingAs($b);
        $this->deleteJson("/api/patient/availability-alerts/{$alertId}")->assertStatus(403);

        $this->assertDatabaseHas('availability_notifications', ['id' => $alertId]);
    }

    public function test_create_alert_validates_medicine_exists(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $this->postJson('/api/patient/availability-alerts', [
            'medicine_id' => 999999,
        ])->assertStatus(422);
    }

    public function test_create_alert_validates_pharmacy_exists_when_provided(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();
        Sanctum::actingAs($patient);

        $this->postJson('/api/patient/availability-alerts', [
            'medicine_id' => $medicine->id,
            'pharmacy_id' => 999999,
        ])->assertStatus(422);
    }

    public function test_observer_fires_only_on_transition_to_available(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();
        $medicine2 = Medicine::factory()->create();
        $medicine3 = Medicine::factory()->create();
        $pharmacy = Pharmacy::factory()->create();

        Sanctum::actingAs($patient);
        // اشترك في تنبيه توفر medicine3 عند pharmacy — هذا هو الذي سيُفعَّل الإشعار له.
        $this->postJson('/api/patient/availability-alerts', [
            'medicine_id' => $medicine3->id,
            'pharmacy_id' => $pharmacy->id,
        ])->assertStatus(201);

        $pm = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 0,
            'is_available' => true,
        ]);

        $pm->update(['quantity' => 5, 'is_available' => true]);
        $this->assertSame(0, Notification::where('user_id', $patient->id)->where('type', 'medicine_available')->count(),
            'No notification expected when going from quantity=0->5 (already is_available=true).');

        $pm2 = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine2->id,
            'quantity' => 5,
            'is_available' => true,
        ]);
        $pm2->update(['quantity' => 11, 'is_available' => true]);
        $this->assertSame(0, Notification::where('user_id', $patient->id)->where('type', 'medicine_available')->count(),
            'No notification expected on incrementing quantity of an in-stock medicine.');

        $pm3 = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine3->id,
            'quantity' => 0,
            'is_available' => false,
        ]);
        $pm3->update(['quantity' => 3, 'is_available' => true]);
        $this->assertSame(1, Notification::where('user_id', $patient->id)->where('type', 'medicine_available')->count(),
            'Exactly one notification on the transition unavailable->available.');
    }
}
