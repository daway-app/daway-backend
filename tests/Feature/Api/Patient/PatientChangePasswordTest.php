<?php

namespace Tests\Feature\Api\Patient;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * تغيير كلمة مرور المريض عبر API.
 *
 * كان هذا المسار مفقودًا تمامًا من API: الويب `PUT /profile/password` يعمل لأي
 * مستخدم مصادَق (يشمل المرضى)، بينما API كان يوفّر change-password للصيدلية
 * والأدمن فقط.
 */
class PatientChangePasswordTest extends TestCase
{
    /**
     * @return array{0: User, 1: string}
     */
    private function patientWithToken(string $password = 'password'): array
    {
        $user = User::factory()->patient()->create([
            'password' => Hash::make($password),
        ]);

        return [$user, $user->createToken('test')->plainTextToken];
    }

    public function test_patient_can_change_password_and_all_tokens_are_revoked(): void
    {
        [$user, $token] = $this->patientWithToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/profile/patient/change-password', [
                'current_password' => 'password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $user->refresh();
        $this->assertTrue(Hash::check('new-secure-password', $user->password));

        // H-13: كل التوكنات (بما فيها الحالي) تُبطَل — العميل يعيد تسجيل الدخول.
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_patient_change_password_rejects_wrong_current_password(): void
    {
        [$user, $token] = $this->patientWithToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/profile/patient/change-password', [
                'current_password' => 'wrong-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('current_password');

        // كلمة المرور لم تتغيّر، والتوكن لم يُبطَل.
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_patient_change_password_rejects_reusing_the_same_password(): void
    {
        [$user, $token] = $this->patientWithToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/profile/patient/change-password', [
                'current_password' => 'password',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_patient_change_password_enforces_min_length_and_confirmation(): void
    {
        [$user, $token] = $this->patientWithToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/profile/patient/change-password', [
                'current_password' => 'password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/profile/patient/change-password', [
                'current_password' => 'password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'different-password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_pharmacy_cannot_use_the_patient_change_password_endpoint(): void
    {
        $pharmacy = User::factory()->pharmacy()->create();
        $token = $pharmacy->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/profile/patient/change-password', [
                'current_password' => 'password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertStatus(403);
    }

    public function test_guest_cannot_change_patient_password(): void
    {
        $this->postJson('/api/profile/patient/change-password', [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertStatus(401);
    }
}
