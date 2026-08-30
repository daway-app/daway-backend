<?php

namespace Tests\Unit;

use App\Support\Image;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageUrlTest extends TestCase
{
    public function test_null_returns_null(): void
    {
        $this->assertNull(Image::url(null));
    }

    public function test_external_url_is_returned_as_is(): void
    {
        $url = 'https://res.cloudinary.com/demo/image/upload/sample.jpg';
        $this->assertSame($url, Image::url($url));
    }

    public function test_uploaded_file_returns_uploads_url(): void
    {
        // H2: القرص 'public' جذرُه public/uploads — الرفع المحلي يعمل
        // بدون storage:link لأن web server يقدّم المجلد مباشرة.
        Storage::fake('public');
        Storage::disk('public')->put('avatars/patient.jpg', 'binary');

        $url = Image::url('avatars/patient.jpg');

        $this->assertNotNull($url);
        $this->assertStringContainsString('avatars/patient.jpg', $url);
    }

    public function test_missing_file_falls_back_to_asset_path(): void
    {
        Storage::fake('public');

        $url = Image::url('avatars/missing.jpg');

        $this->assertNotNull($url);
        $this->assertStringContainsString('avatars/missing.jpg', $url);
    }
}
