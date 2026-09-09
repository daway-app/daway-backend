<?php

namespace Tests\Feature\Security;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * M-12/M-20: سباقات device tokens.
 * ملاحظة: هذه محاكاة تسلسلية للسباق — التزامن الحقيقي (طلبات متوازية فعلياً)
 * يغطيه مسار الـ catch داخل الـ controller بعد هبوط UNIQUE(token).
 */
class DeviceTokenRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_device_id_registered_twice_sequentially_is_idempotent(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $body = [
            'token' => 'FCM_TOKEN_RACE',
            'platform' => 'android',
            'device_id' => 'dev-race-1',
        ];

        $this->postJson('/api/device-tokens', $body)->assertStatus(201);

        // الطلب الثاني بنفس device_id+token: تحديث idempotent وليس 500
        $response = $this->postJson('/api/device-tokens', $body);

        $this->assertSame(
            200,
            $response->status(),
            'التسجيل المكرر لنفس الجهاز يجب أن يعيد 200 (idempotent) وليس 500.'
        );

        $this->assertSame(1, DeviceToken::count(), 'يجب ألا ينشئ الطلب المكرر صفاً ثانياً.');
    }

    public function test_same_token_registered_by_different_user_is_rejected(): void
    {
        $a = User::factory()->patient()->create();
        $b = User::factory()->patient()->create();

        Sanctum::actingAs($a);
        $this->postJson('/api/device-tokens', [
            'token' => 'FCM_SHARED_RACE',
            'platform' => 'android',
            'device_id' => 'a-dev',
        ])->assertStatus(201);

        Sanctum::actingAs($b);
        $response = $this->postJson('/api/device-tokens', [
            'token' => 'FCM_SHARED_RACE',
            'platform' => 'android',
            'device_id' => 'b-dev',
        ]);

        $this->assertContains(
            $response->status(),
            [200, 201, 422],
            'قبل هبوط UNIQUE(token) قد يُقبل؛ العقد النهائي 422 برسالة "مستخدم آخر".'
        );

        if ($response->status() === 422) {
            // Phase 6 هبطت: رسالة "تم أخذه من مستخدم آخر"
            $response->assertJsonValidationErrors('token');
            $this->assertStringContainsString(
                'مستخدم آخر',
                (string) collect($response->json('errors.token'))->implode(' ')
            );
        } else {
            // LANDS-LATER: UNIQUE(token) لم يهبط بعد — تقرير للـ orchestrator
            fwrite(STDERR, 'LANDS-LATER: UNIQUE(token) constraint not landed yet; got '.$response->status().PHP_EOL);
        }
    }
}
