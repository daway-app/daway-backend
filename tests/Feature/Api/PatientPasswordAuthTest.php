<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PatientPasswordAuthTest extends TestCase
{
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
}
