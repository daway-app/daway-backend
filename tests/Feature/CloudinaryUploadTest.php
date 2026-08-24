<?php

namespace Tests\Feature;

use App\Support\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CloudinaryUploadTest extends TestCase
{
    protected function tearDown(): void
    {
        Storage::disk('public')->deleteDirectory('medicines');
        parent::tearDown();
    }

    public function test_uploads_to_cloudinary_and_returns_secure_url(): void
    {
        config()->set([
            'services.cloudinary.cloud' => 'test-cloud',
            'services.cloudinary.upload_preset' => 'test-preset',
            'services.cloudinary.folder' => 'daway',
        ]);

        Http::fake([
            'api.cloudinary.com/v1_1/test-cloud/image/upload' => Http::response([
                'secure_url' => 'https://res.cloudinary.com/test-cloud/image/upload/v1/daway/medicines/abc.jpg',
            ], 200),
        ]);

        $url = Cloudinary::upload(UploadedFile::fake()->image('med.jpg'), 'medicines');

        $this->assertSame('https://res.cloudinary.com/test-cloud/image/upload/v1/daway/medicines/abc.jpg', $url);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1_1/test-cloud/image/upload'));
    }

    public function test_falls_back_to_local_disk_when_disabled(): void
    {
        config()->set([
            'services.cloudinary.cloud' => null,
            'services.cloudinary.upload_preset' => null,
        ]);

        $path = Cloudinary::upload(UploadedFile::fake()->image('med.jpg'), 'medicines');

        $this->assertStringStartsWith('medicines/', $path);
        $this->assertTrue(Storage::disk('public')->exists($path));
    }

    public function test_falls_back_to_local_disk_when_api_fails(): void
    {
        config()->set([
            'services.cloudinary.cloud' => 'test-cloud',
            'services.cloudinary.upload_preset' => 'test-preset',
        ]);

        Http::fake([
            'api.cloudinary.com/v1_1/test-cloud/image/upload' => Http::response(['error' => ['message' => 'boom']], 500),
        ]);

        $path = Cloudinary::upload(UploadedFile::fake()->image('med.jpg'), 'medicines');

        $this->assertStringStartsWith('medicines/', $path);
        $this->assertTrue(Storage::disk('public')->exists($path));
    }
}
