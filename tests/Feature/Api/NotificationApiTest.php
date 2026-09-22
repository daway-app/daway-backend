<?php

namespace Tests\Feature\Api;

use App\Models\Notification;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    private function createNotification(int $userId, bool $isRead = false, string $type = 'low_stock'): Notification
    {
        return Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'message' => 'Test notification',
            'is_read' => $isRead,
            'created_at' => now(),
        ]);
    }

    public function test_user_can_list_own_notifications(): void
    {
        $user = User::factory()->patient()->create();
        $other = User::factory()->patient()->create();

        $this->createNotification($user->id, false, 'low_stock');
        $this->createNotification($user->id, false, 'low_stock');
        $this->createNotification($user->id, true, 'low_stock');
        $this->createNotification($other->id, false, 'low_stock');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/notifications');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('unread_count', 2)
            ->assertJsonStructure([
                'data' => [['id', 'type', 'message', 'is_read', 'created_at']],
                'unread_count',
                'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
            ])
            ->assertJsonPath('pagination.total', 3);
    }

    public function test_user_can_get_unread_count(): void
    {
        $user = User::factory()->patient()->create();

        $this->createNotification($user->id, true);
        $this->createNotification($user->id, true);
        $this->createNotification($user->id, false);
        $this->createNotification($user->id, false);
        $this->createNotification($user->id, false);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/notifications/count');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.unread_count', 3);
    }

    public function test_user_can_mark_notification_as_read(): void
    {
        $user = User::factory()->patient()->create();
        $notification = $this->createNotification($user->id, false);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/notifications/{$notification->id}/read");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_read', true);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'is_read' => true,
        ]);
    }

    public function test_user_cannot_mark_others_notification(): void
    {
        $userA = User::factory()->patient()->create();
        $userB = User::factory()->patient()->create();
        $notificationB = $this->createNotification($userB->id, false);

        Sanctum::actingAs($userA);

        $this->postJson("/api/notifications/{$notificationB->id}/read")->assertForbidden();

        $this->assertDatabaseHas('notifications', [
            'id' => $notificationB->id,
            'is_read' => false,
        ]);
    }

    public function test_user_can_filter_by_type(): void
    {
        $user = User::factory()->patient()->create();

        $this->createNotification($user->id, false, 'medicine_available');
        $this->createNotification($user->id, false, 'inquiry_answered');
        $this->createNotification($user->id, false, 'system');
        $this->createNotification($user->id, false, 'medicine_available');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/notifications?type=medicine_available');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_filter_by_inquiry_answered(): void
    {
        $user = User::factory()->patient()->create();

        $this->createNotification($user->id, false, 'medicine_available');
        $this->createNotification($user->id, false, 'inquiry_answered');
        $this->createNotification($user->id, false, 'system');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/notifications?type=inquiry_answered');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_filter_by_system(): void
    {
        $user = User::factory()->patient()->create();

        $this->createNotification($user->id, false, 'medicine_available');
        $this->createNotification($user->id, false, 'inquiry_answered');
        $this->createNotification($user->id, false, 'system');
        $this->createNotification($user->id, true, 'system');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/notifications?type=system');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_invalid_type_returns_empty(): void
    {
        $user = User::factory()->patient()->create();

        $this->createNotification($user->id, false, 'medicine_available');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/notifications?type=nonexistent_type');

        $response->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('pagination.total', 0);
    }

    public function test_user_can_get_all_notifications_without_filter(): void
    {
        $user = User::factory()->patient()->create();

        $this->createNotification($user->id, false, 'medicine_available');
        $this->createNotification($user->id, false, 'inquiry_answered');
        $this->createNotification($user->id, false, 'system');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/notifications');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('pagination.total', 3);
    }

    public function test_user_can_mark_all_as_read(): void
    {
        $user = User::factory()->patient()->create();
        $other = User::factory()->patient()->create();

        $this->createNotification($user->id, false);
        $this->createNotification($user->id, false);
        $this->createNotification($user->id, false);
        $this->createNotification($other->id, false);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/notifications/mark-all-as-read');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'is_read' => true,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $other->id,
            'is_read' => false,
        ]);
    }
}
