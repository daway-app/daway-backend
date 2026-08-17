<?php

namespace Tests\Feature\Api;

use App\Models\Reminder;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReminderTest extends TestCase
{
    public function test_patient_can_create_a_reminder(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $response = $this->postJson('/api/reminders', [
            'medicine_name' => 'Panadol',
            'dosage' => '1 tablet',
            'reminder_date' => now()->toDateString(),
            'reminder_time' => '09:00',
            'frequency' => 'daily',
            'quantity_remaining' => 10,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['id', 'medicine_name', 'dosage', 'reminder_date', 'reminder_time', 'frequency', 'quantity_remaining', 'is_active'],
            ]);

        $this->assertDatabaseHas('reminders', [
            'user_id' => $patient->id,
            'medicine_name' => 'Panadol',
        ]);
    }

    public function test_index_returns_only_own_reminders(): void
    {
        $patient = User::factory()->patient()->create();
        $other = User::factory()->patient()->create();

        Reminder::factory()->create(['user_id' => $patient->id, 'medicine_name' => 'Mine']);
        Reminder::factory()->create(['user_id' => $other->id, 'medicine_name' => 'Theirs']);

        Sanctum::actingAs($patient);

        $response = $this->getJson('/api/reminders');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.medicine_name', 'Mine');
    }

    public function test_cannot_access_another_users_reminder(): void
    {
        $owner = User::factory()->patient()->create();
        $intruder = User::factory()->patient()->create();
        $reminder = Reminder::factory()->create(['user_id' => $owner->id]);

        Sanctum::actingAs($intruder);

        $this->getJson("/api/reminders/{$reminder->id}")->assertNotFound();
        $this->putJson("/api/reminders/{$reminder->id}", [
            'medicine_name' => 'Hacked',
            'dosage' => '2 tablets',
            'reminder_date' => now()->toDateString(),
            'reminder_time' => '08:00',
            'quantity_remaining' => 1,
        ])->assertNotFound();
        $this->deleteJson("/api/reminders/{$reminder->id}")->assertNotFound();

        $this->assertDatabaseHas('reminders', ['id' => $reminder->id]);
    }

    public function test_mark_taken_decrements_quantity_remaining(): void
    {
        $patient = User::factory()->patient()->create();
        $reminder = Reminder::factory()->create([
            'user_id' => $patient->id,
            'quantity_remaining' => 3,
        ]);

        Sanctum::actingAs($patient);

        $this->postJson("/api/reminders/{$reminder->id}/taken")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.quantity_remaining', 2)
            ->assertJsonPath('data.is_active', true);
    }

    public function test_mark_taken_deactivates_reminder_at_zero(): void
    {
        $patient = User::factory()->patient()->create();
        $reminder = Reminder::factory()->create([
            'user_id' => $patient->id,
            'quantity_remaining' => 1,
        ]);

        Sanctum::actingAs($patient);

        $this->postJson("/api/reminders/{$reminder->id}/taken")
            ->assertOk()
            ->assertJsonPath('data.quantity_remaining', 0)
            ->assertJsonPath('data.is_active', false);
    }
}
