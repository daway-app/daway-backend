<?php

namespace Tests\Feature\Web;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PharmacyProfilePasswordChangeTest extends TestCase
{
    public function test_pharmacy_can_change_password_from_profile(): void
    {
        $user = User::factory()->pharmacy()->create([
            'password' => Hash::make('current-pass-123'),
        ]);
        Pharmacy::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->put(route('pharmacy.profile.update'), [
            'current_password' => 'current-pass-123',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ]);

        $response->assertRedirect(route('pharmacy.profile.edit'));
        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
    }

    public function test_profile_password_change_rejects_wrong_current_password(): void
    {
        $user = User::factory()->pharmacy()->create([
            'password' => Hash::make('current-pass-123'),
        ]);
        Pharmacy::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->put(route('pharmacy.profile.update'), [
            'current_password' => 'wrong-password',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertSessionHasErrors(['current_password']);

        $this->assertTrue(Hash::check('current-pass-123', $user->fresh()->password));
    }

    public function test_empty_password_fields_keep_existing_password(): void
    {
        $user = User::factory()->pharmacy()->create([
            'password' => Hash::make('unchanged-pass'),
        ]);
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->put(route('pharmacy.profile.update'), [
            'phone_number' => $pharmacy->phone_number,
        ]);

        $response->assertRedirect(route('pharmacy.profile.edit'));
        $this->assertTrue(Hash::check('unchanged-pass', $user->fresh()->password));
    }
}
