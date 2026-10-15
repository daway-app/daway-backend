<?php

namespace Tests\Feature\Api;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PharmacyProfileLogoUploadTest extends TestCase
{
    private function pharmacyToken(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy, $user->createToken('test')->plainTextToken];
    }

    public function test_logo_file_upload_stores_logo_and_syncs_avatar(): void
    {
        Storage::fake('public');
        [$user, $pharmacy, $token] = $this->pharmacyToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/profile/pharmacy', [
                'logo_file' => UploadedFile::fake()->image('logo.png', 200, 200),
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $logoUrl = $response->json('data.logo_url');
        $this->assertNotEmpty($logoUrl);

        // الملف خُزن والداتا بيز تحمل القيمة + الأفاتار تزامن (مطابقة الويب)
        $this->assertNotNull($pharmacy->fresh()->logo);
        $this->assertSame($pharmacy->fresh()->logo, $user->fresh()->avatar);
    }

    public function test_logo_file_beats_logo_url_when_both_sent(): void
    {
        Storage::fake('public');
        [$user, $pharmacy, $token] = $this->pharmacyToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/profile/pharmacy', [
                'logo_url' => 'https://res.cloudinary.com/demo/image/upload/v1/daway/old.png',
                'logo_file' => UploadedFile::fake()->image('new.png', 200, 200),
            ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertNotSame(
            'https://res.cloudinary.com/demo/image/upload/v1/daway/old.png',
            $pharmacy->fresh()->logo
        );
    }

    public function test_logo_url_still_works_without_file(): void
    {
        [$user, $pharmacy, $token] = $this->pharmacyToken();

        $url = 'https://res.cloudinary.com/demo/image/upload/v1/daway/ready.png';

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/profile/pharmacy', ['logo_url' => $url])
            ->assertOk()
            ->assertJsonPath('data.logo_url', $url);

        // الأفاتار يتزامن مع الرابط أيضًا
        $this->assertSame($url, $user->fresh()->avatar);
    }

    public function test_logo_file_rejects_non_image(): void
    {
        [$user, $pharmacy, $token] = $this->pharmacyToken();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])
            ->post('/api/profile/pharmacy', [
                'logo_file' => UploadedFile::fake()->create('evil.txt', 10, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('logo_file');
    }
}
