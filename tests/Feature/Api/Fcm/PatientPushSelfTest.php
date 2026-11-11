<?php

namespace Tests\Feature\Api\Fcm;

use App\Contracts\FcmSender;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientPushSelfTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_can_send_test_only_to_their_own_registered_device(): void
    {
        $patient = User::factory()->patient()->create();
        $other = User::factory()->patient()->create();
        DeviceToken::create([
            'user_id' => $patient->id,
            'token' => 'PATIENT_TEST_TOKEN',
            'device_id' => 'patient-phone',
            'platform' => 'android',
        ]);
        DeviceToken::create([
            'user_id' => $other->id,
            'token' => 'OTHER_TEST_TOKEN',
            'device_id' => 'other-phone',
            'platform' => 'android',
        ]);

        $sender = \Mockery::mock(FcmSender::class);
        $sender->shouldReceive('fromNotification')->once()->withArgs(
            fn (Notification $notification) => $notification->user_id === $patient->id
                && $notification->type === 'system'
        );
        $this->app->instance(FcmSender::class, $sender);

        Sanctum::actingAs($patient);
        $this->postJson('/api/patient/notifications/test-push', ['user_id' => $other->id])
            ->assertStatus(202)->assertJsonPath('success', true);
        $this->assertDatabaseHas('notifications', ['user_id' => $patient->id, 'type' => 'system']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $other->id, 'type' => 'system']);
    }

    public function test_patient_without_device_cannot_send_test(): void
    {
        Sanctum::actingAs(User::factory()->patient()->create());
        $this->postJson('/api/patient/notifications/test-push')->assertStatus(409);
        $this->assertSame(0, Notification::count());
    }

    public function test_pharmacy_cannot_use_patient_test_endpoint(): void
    {
        Sanctum::actingAs(User::factory()->pharmacy()->create());
        $this->postJson('/api/patient/notifications/test-push')->assertForbidden();
    }
}
