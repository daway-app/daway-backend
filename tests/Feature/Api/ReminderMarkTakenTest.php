<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\Reminder;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReminderMarkTakenTest extends TestCase
{
    public function test_mark_taken_decrements_quantity_once(): void
    {
        $user = User::factory()->patient()->create();
        $reminder = Reminder::create([
            'user_id' => $user->id,
            'medicine_name' => 'Panadol',
            'dosage' => '1 pill',
            'reminder_date' => now()->toDateString(),
            'reminder_time' => '08:00',
            'frequency' => 'once',
            'quantity_remaining' => 3,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/reminders/{$reminder->id}/taken")
            ->assertOk()
            ->assertJsonPath('data.quantity_remaining', 2)
            ->assertJsonPath('data.is_active', true);
    }

    public function test_mark_taken_deactivates_when_reaching_zero(): void
    {
        $user = User::factory()->patient()->create();
        $reminder = Reminder::create([
            'user_id' => $user->id,
            'medicine_name' => 'Panadol',
            'dosage' => '1 pill',
            'reminder_date' => now()->toDateString(),
            'reminder_time' => '08:00',
            'frequency' => 'once',
            'quantity_remaining' => 1,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/reminders/{$reminder->id}/taken")
            ->assertOk()
            ->assertJsonPath('data.quantity_remaining', 0)
            ->assertJsonPath('data.is_active', false);
    }

    public function test_mark_taken_never_goes_negative(): void
    {
        $user = User::factory()->patient()->create();
        $reminder = Reminder::create([
            'user_id' => $user->id,
            'medicine_name' => 'Panadol',
            'dosage' => '1 pill',
            'reminder_date' => now()->toDateString(),
            'reminder_time' => '08:00',
            'frequency' => 'once',
            'quantity_remaining' => 0,
            'is_active' => false,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/reminders/{$reminder->id}/taken")->assertOk();

        $this->assertSame(0, $response->json('data.quantity_remaining'));
        $this->assertFalse((bool) $response->json('data.is_active'));

        $reminder->refresh();
        $this->assertSame(0, (int) $reminder->quantity_remaining);
    }

    public function test_sequential_mark_taken_requests_never_double_decrement(): void
    {
        // H7: سلسلة طلبات متتالية — كل طلب يخصم مرة واحدة بالضبط (لا read-modify-write).
        $user = User::factory()->patient()->create();
        $reminder = Reminder::create([
            'user_id' => $user->id,
            'medicine_name' => 'Panadol',
            'dosage' => '1 pill',
            'reminder_date' => now()->toDateString(),
            'reminder_time' => '08:00',
            'frequency' => 'daily',
            'quantity_remaining' => 5,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        for ($i = 5; $i >= 1; $i--) {
            $this->postJson("/api/reminders/{$reminder->id}/taken")
                ->assertOk()
                ->assertJsonPath('data.quantity_remaining', $i - 1);
        }

        $reminder->refresh();
        $this->assertSame(0, (int) $reminder->quantity_remaining);
        $this->assertFalse((bool) $reminder->is_active);
    }

    public function test_cannot_mark_taken_on_another_users_reminder(): void
    {
        $owner = User::factory()->patient()->create();
        $other = User::factory()->patient()->create();
        $reminder = Reminder::create([
            'user_id' => $owner->id,
            'medicine_name' => 'Panadol',
            'dosage' => '1 pill',
            'reminder_date' => now()->toDateString(),
            'reminder_time' => '08:00',
            'frequency' => 'once',
            'quantity_remaining' => 3,
            'is_active' => true,
        ]);

        Sanctum::actingAs($other);

        // authorizeOwner يُرجع 404 لغير المالك (وليس 403 — لعدم كشف وجود السجل).
        $this->postJson("/api/reminders/{$reminder->id}/taken")->assertNotFound();

        $reminder->refresh();
        $this->assertSame(3, (int) $reminder->quantity_remaining);
    }
}
