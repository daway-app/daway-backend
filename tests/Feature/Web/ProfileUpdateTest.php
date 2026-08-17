<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    public function test_user_can_update_profile_with_put_method_spoofing(): void
    {
        $user = User::factory()->patient()->create();

        $response = $this->actingAs($user)->from('/profile')->post('/profile', [
            '_method' => 'PUT',
            'name' => 'Updated Name',
            'phone' => '0599 222 222',
        ]);

        $response->assertRedirect(route('profile.edit'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'phone' => '0599 222 222',
        ]);
    }

    public function test_profile_update_requires_name(): void
    {
        $user = User::factory()->patient()->create();

        $response = $this->actingAs($user)->from('/profile')->post('/profile', [
            '_method' => 'PUT',
            'name' => '',
        ]);

        $response->assertSessionHasErrors('name');
    }
}
