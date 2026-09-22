<?php

namespace Tests\Feature\Api;

use App\Models\Notification;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReminderNotificationTest extends TestCase
{
    private function createReminder(User $user, array $overrides = []): Reminder
    {
        return Reminder::factory()->create(array_merge([
            'user_id' => $user->id,
            'medicine_name' => 'Panadol',
            'dosage' => '1 tablet',
            'reminder_date' => now()->toDateString(),
            'reminder_time' => now()->subMinute()->format('H:i'),
            'frequency' => 'daily',
            'quantity_remaining' => 10,
            'is_active' => true,
        ], $overrides));
    }

    public function test_due_reminder_creates_notification_record(): void
    {
        $user = User::factory()->patient()->create(['notifications_enabled' => true]);
        $reminder = $this->createReminder($user);

        Artisan::call('reminders:send-due');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'reminder',
        ]);

        $this->assertNotNull($reminder->fresh()->reminder_sent_at);
    }

    public function test_inactive_reminder_is_ignored(): void
    {
        $user = User::factory()->patient()->create(['notifications_enabled' => true]);

        $this->createReminder($user, ['is_active' => false]);

        Artisan::call('reminders:send-due');

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_already_sent_today_is_not_resent(): void
    {
        $user = User::factory()->patient()->create(['notifications_enabled' => true]);
        $reminder = $this->createReminder($user, [
            'reminder_sent_at' => now(),
        ]);

        Artisan::call('reminders:send-due');

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_user_without_notifications_enabled_is_skipped(): void
    {
        $user = User::factory()->patient()->create(['notifications_enabled' => false]);
        $this->createReminder($user);

        Artisan::call('reminders:send-due');

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_notification_contains_correct_type(): void
    {
        $user = User::factory()->patient()->create(['notifications_enabled' => true]);
        $this->createReminder($user, [
            'medicine_name' => 'Glucophage',
            'dosage' => '2mg',
        ]);

        Artisan::call('reminders:send-due');

        $notification = Notification::where('user_id', $user->id)->first();
        $this->assertNotNull($notification);
        $this->assertEquals('reminder', $notification->type);
        $this->assertStringContainsString('Glucophage', $notification->message);
        $this->assertStringContainsString('2mg', $notification->message);
    }

    public function test_due_reminder_from_yesterday_with_daily_frequency(): void
    {
        $user = User::factory()->patient()->create(['notifications_enabled' => true]);
        $this->createReminder($user, [
            'reminder_date' => now()->subDay()->toDateString(),
            'reminder_time' => now()->subMinute()->format('H:i'),
            'frequency' => 'daily',
        ]);

        Artisan::call('reminders:send-due');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'reminder',
        ]);
    }

    public function test_notification_type_filter_returns_reminders(): void
    {
        $user = User::factory()->patient()->create(['notifications_enabled' => true]);
        Sanctum::actingAs($user);

        $this->createReminder($user);

        Artisan::call('reminders:send-due');

        $response = $this->getJson('/api/notifications?type=reminder');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'reminder');
    }

    public function test_notification_type_filter_excludes_other_types(): void
    {
        $user = User::factory()->patient()->create(['notifications_enabled' => true]);
        Sanctum::actingAs($user);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'medicine_available',
            'message' => 'Test',
            'is_read' => false,
            'created_at' => now(),
        ]);

        $this->createReminder($user);
        Artisan::call('reminders:send-due');

        $response = $this->getJson('/api/notifications?type=reminder');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'reminder');
    }

    public function test_invalid_type_does_not_return_reminders(): void
    {
        $user = User::factory()->patient()->create(['notifications_enabled' => true]);
        Sanctum::actingAs($user);

        $this->createReminder($user);
        Artisan::call('reminders:send-due');

        $response = $this->getJson('/api/notifications?type=invalid_type');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_fcm_not_configured_does_not_crash(): void
    {
        $user = User::factory()->patient()->create(['notifications_enabled' => true]);
        $this->createReminder($user);

        Artisan::call('reminders:send-due');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'reminder',
        ]);
    }
}
