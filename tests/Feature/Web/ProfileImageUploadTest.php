<?php

namespace Tests\Feature\Web;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileImageUploadTest extends TestCase
{
    protected function tearDown(): void
    {
        Storage::disk('public')->deleteDirectory('avatars');
        Storage::disk('public')->deleteDirectory('pharmacy_logos');
        parent::tearDown();
    }

    public function test_ajax_avatar_upload_returns_reachable_url(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)
            ->postJson('/profile/update-ajax', [
                'name' => 'New Name',
                'avatar' => UploadedFile::fake()->image('avatar.jpg', 10, 10),
            ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $avatar = $user->fresh()->avatar;
        $this->assertNotNull($avatar);
        $this->assertStringStartsWith('avatars/', $avatar);
        $this->assertTrue(Storage::disk('public')->exists($avatar));

        $url = $response->json('avatar');
        $this->assertStringContainsString('/uploads/avatars/', $url);

        $this->assertFileExists(public_path('uploads/'.$avatar));
    }

    public function test_form_avatar_upload_persists_and_old_file_is_deleted(): void
    {
        $user = User::factory()->admin()->create();
        $oldAvatar = 'avatars/old.jpg';
        Storage::disk('public')->put($oldAvatar, 'old-content');
        $user->avatar = $oldAvatar;
        $user->save();

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->put('/profile', [
                'name' => 'Updated Name',
                'phone' => '+970599999999',
                'avatar' => UploadedFile::fake()->image('new.jpg', 10, 10),
            ])
            ->assertRedirect(route('profile.edit'));

        $newAvatar = $user->fresh()->avatar;
        $this->assertNotEquals($oldAvatar, $newAvatar);
        $this->assertTrue(Storage::disk('public')->exists($newAvatar));
        $this->assertFalse(Storage::disk('public')->exists($oldAvatar));
    }

    public function test_pharmacy_logo_upload_persists_and_view_renders_url(): void
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->from(route('pharmacy.profile.edit'))
            ->put('/pharmacy/profile', [
                'pharmacy_name' => $pharmacy->pharmacy_name,
                'phone_number' => $pharmacy->phone_number,
                'address' => $pharmacy->address,
                'region' => $pharmacy->region,
                'latitude' => $pharmacy->latitude,
                'longitude' => $pharmacy->longitude,
                'logo' => UploadedFile::fake()->image('logo.jpg', 10, 10),
            ])
            ->assertRedirect(route('pharmacy.profile.edit'));

        $logo = $pharmacy->fresh()->logo;
        $this->assertNotNull($logo);
        $this->assertStringStartsWith('pharmacy_logos/', $logo);
        $this->assertTrue(Storage::disk('public')->exists($logo));

        $this->actingAs($user)
            ->get(route('pharmacy.profile.edit'))
            ->assertOk()
            ->assertSee('/uploads/pharmacy_logos/');
    }
}
