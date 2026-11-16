<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PatientPasswordAuthTest extends TestCase
{
    public function test_patient_session_identity_requires_an_active_patient_token(): void
    {
        $this->getJson('/api/auth/patient/me')->assertUnauthorized();
        $user = User::factory()->patient()->create(['is_active' => true]);
        $token = $user->createToken('patient')->plainTextToken;
        $this->withToken($token)->getJson('/api/auth/patient/me')
            ->assertOk()->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role', 'patient');
        $user->forceFill(['is_active' => false])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/patient/me')->assertUnauthorized();
        $other = User::factory()->create(['role' => 'pharmacy', 'is_active' => true]);
        $this->app['auth']->forgetGuards();
        $this->withToken($other->createToken('pharmacy')->plainTextToken)
            ->getJson('/api/auth/patient/me')->assertUnauthorized();
    }

    public function test_patient_can_register_and_login_without_otp(): void
    {
        $this->postJson('/api/auth/patient/register', [
            'name' => 'Test Patient',
            'phone' => '0591234581',
            'email' => 'new-patient@example.test',
            'password' => 'StrongPassword123',
            'password_confirmation' => 'StrongPassword123',
            'age' => 25,
            'terms_accepted' => true,
        ])->assertCreated()
            ->assertJsonPath('data.user.role', 'patient')
            ->assertJsonStructure(['data' => ['token']]);

        $user = User::where('email', 'new-patient@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('StrongPassword123', $user->password));

        $this->postJson('/api/auth/patient/login', [
            'identifier' => '0591234581',
            'password' => 'StrongPassword123',
        ])->assertOk()->assertJsonPath('data.user.role', 'patient');
    }

    public function test_password_registration_rejects_duplicate_email_and_weak_password(): void
    {
        User::factory()->patient()->create(['email' => 'taken@example.test']);
        $this->postJson('/api/auth/patient/register', [
            'name' => 'Patient',
            'phone' => '0591234582',
            'email' => 'taken@example.test',
            'password' => 'short',
            'password_confirmation' => 'short',
            'age' => 22,
            'terms_accepted' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_patient_password_login_rejects_wrong_password(): void
    {
        User::factory()->patient()->create([
            'email' => 'patient@example.test',
            'password' => Hash::make('CorrectPassword123'),
        ]);
        $this->postJson('/api/auth/patient/login', [
            'identifier' => 'patient@example.test',
            'password' => 'WrongPassword123',
        ])->assertUnauthorized();
    }

    public function test_patient_login_cannot_authenticate_pharmacy_account(): void
    {
        User::factory()->create([
            'role' => 'pharmacy',
            'email' => 'pharmacy@example.test',
            'password' => Hash::make('StrongPassword123'),
        ]);
        $this->postJson('/api/auth/patient/login', [
            'identifier' => 'pharmacy@example.test',
            'password' => 'StrongPassword123',
        ])->assertUnauthorized();
    }
    public function test_registration_assigns_the_patient_role(): void
    {
        $this->postJson('/api/auth/patient/register', [
            'name' => 'Role Patient', 'phone' => '0591234590',
            'email' => 'role-patient@example.test', 'age' => 24,
            'password' => 'StrongPassword123', 'password_confirmation' => 'StrongPassword123',
            'terms_accepted' => true,
        ])->assertCreated();
        $this->assertTrue(User::where('email', 'role-patient@example.test')->firstOrFail()->hasRole('patient'));
    }

    public function test_closed_or_inactive_patient_cannot_log_in_even_with_the_correct_password(): void
    {
        foreach (['closed' => ['is_active' => true, 'closed_at' => now()], 'inactive' => ['is_active' => false, 'closed_at' => null]] as $suffix => $attributes) {
            $user = User::factory()->patient()->create(['email' => $suffix.'@example.test', 'password' => Hash::make('StrongPassword123')]);
            $user->forceFill($attributes)->save();
            $this->postJson('/api/auth/patient/login', ['identifier' => $user->email, 'password' => 'StrongPassword123'])->assertUnauthorized();
            $this->assertSame(0, $user->tokens()->count());
        }
    }

    public function test_patient_login_is_limited_per_identifier_across_addresses(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attempt])
                ->postJson('/api/auth/patient/login', ['identifier' => 'not-found@example.test', 'identity' => '', 'pharmacy_id' => '', 'password' => 'IncorrectPassword123'])
                ->assertUnauthorized();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.6'])
            ->postJson('/api/auth/patient/login', ['identifier' => 'not-found@example.test', 'password' => 'IncorrectPassword123'])
            ->assertStatus(429);
    }

    public function test_unknown_and_wrong_password_login_have_the_same_error(): void
    {
        $user = User::factory()->patient()->create([
            'email' => 'existing@example.test',
            'password' => Hash::make('StrongPassword123'),
        ]);
        $existing = $this->postJson('/api/auth/patient/login', ['identifier' => $user->email, 'password' => 'IncorrectPassword123']);
        $unknown = $this->postJson('/api/auth/patient/login', ['identifier' => 'missing@example.test', 'password' => 'IncorrectPassword123']);
        $existing->assertUnauthorized();
        $unknown->assertUnauthorized();
        $this->assertSame($existing->json(), $unknown->json());
    }

}

