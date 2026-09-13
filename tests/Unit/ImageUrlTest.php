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

    /* ------------------------------------------------- thumbUrl */

    public function test_thumb_url_injects_cloudinary_transform(): void
    {
        $src = 'https://res.cloudinary.com/demo/image/upload/v1234/daway/avatars/pic.jpg';

        $out = Image::thumbUrl($src, 72, 72);

        $this->assertStringContainsString('/image/upload/w_72,h_72,c_fill,g_auto,f_auto,q_auto:good,dpr_auto/v1234/', $out);
        // الأصل محفوظ بعد التحويل
        $this->assertStringEndsWith('/daway/avatars/pic.jpg', $out);
    }

    public function test_thumb_url_preserves_folder_path_in_public_id(): void
    {
        $src = 'https://res.cloudinary.com/xyz/image/upload/v999/daway/pharmacy_logos/logo.jpg';

        $out = Image::thumbUrl($src, 180, 180);

        $this->assertStringContainsString('w_180,h_180', $out);
        $this->assertStringEndsWith('/daway/pharmacy_logos/logo.jpg', $out);
    }

    public function test_thumb_url_does_not_double_apply_existing_transform(): void
    {
        $src = 'https://res.cloudinary.com/demo/image/upload/w_100,h_100/v1234/pic.jpg';

        $this->assertSame($src, Image::thumbUrl($src, 72, 72));
    }

    public function test_thumb_url_leaves_non_cloudinary_hosts_untouched(): void
    {
        foreach ([
            'https://example.com/image/upload/v1/pic.jpg',
            'https://daway-backend.onrender.com/images/dawak-logo.jpg',
            'https://res.cloudinary.com.evil.test/image/upload/v1/pic.jpg',
        ] as $src) {
            $this->assertSame($src, Image::thumbUrl($src, 72, 72), "يجب ألا يُمَس الرابط: {$src}");
        }
    }

    public function test_thumb_url_returns_null_for_null_input(): void
    {
        $this->assertNull(Image::thumbUrl(null, 72, 72));
    }

    public function test_thumb_url_leaves_cloudinary_url_without_upload_segment(): void
    {
        // رابط Cloudinary لكن بلا /image/upload/ — لا نخترع تحويلاً
        $src = 'https://res.cloudinary.com/demo/video/upload/v1/clip.mp4';

        $this->assertSame($src, Image::thumbUrl($src, 72, 72));
    }

    public function test_thumb_url_rounds_dimensions_to_positive_integers(): void
    {
        $src = 'https://res.cloudinary.com/demo/image/upload/v1/pic.jpg';

        $out = Image::thumbUrl($src, 0, 0);

        // الأبعاد الصفرية تُرفع إلى 1 على الأقل بدل إنتاج رابط غير صالح
        $this->assertStringContainsString('w_1,h_1', $out);
    }

    public function test_is_cloudinary_detects_only_real_cloudinary_hosts(): void
    {
        $this->assertTrue(Image::isCloudinary('https://res.cloudinary.com/demo/image/upload/v1/a.jpg'));
        $this->assertTrue(Image::isCloudinary('https://sub.res.cloudinary.com/demo/image/upload/v1/a.jpg'));
        $this->assertFalse(Image::isCloudinary('https://cloudinary.com/demo/a.jpg'));
        $this->assertFalse(Image::isCloudinary('https://res.cloudinary.com.evil.test/a.jpg'));
        $this->assertFalse(Image::isCloudinary('not-a-url'));
    }
}
