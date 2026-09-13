<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class Image
{
    /**
     * يعيد رابط الصورة كما هو إن كان رابطاً خارجياً (Cloudinary)،
     * وإلا يبنيه من رابط القرص العام إن وُجد الملف،
     * أو من asset() للمسارات الأخرى.
     *
     * ملاحظة H2: القرص 'public' في config/filesystems.php جذرُه
     * public/uploads مباشرة (وليس storage/app/public)، لذا الرفع
     * المحلي يُقدَّم عبر web server دون الحاجة إلى storage:link.
     */
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return Storage::disk('public')->exists($path)
            ? Storage::disk('public')->url($path)
            : asset($path);
    }

    /**
     * رابط صورة مصغّرة بعرض محدّد.
     *
     * لروابط Cloudinary يُحقن تحويل على الخادم (w_/h_/c_fill/f_auto/q_auto)
     * فتصل الصورة بحجم العرض الفعلي بدل الأبعاد الأصلية — كان أفاتار يُعرض
     * بـ 34 بكسل يُنزَّل بأبعاد 1920×1080 (≈95 كيلوبايت للصورة الواحدة).
     *
     * لأي رابط آخر (صور محلية أو مضيف غير Cloudinary) يرجع إلى imageUrl()
     * بلا تغيير، لأن التحويل خاص بـ Cloudinary ولا يجوز افتراضه لغيره.
     */
    public static function thumbUrl(?string $path, int $width, int $height = null): ?string
    {
        $url = self::url($path);

        if (! $url || ! self::isCloudinary($url)) {
            return $url;
        }

        return self::withCloudinaryTransform($url, $width, $height ?? $width);
    }

    /**
     * هل الرابط صادر من Cloudinary (وليس أي رابط خارجي آخر)؟
     */
    public static function isCloudinary(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host)
            && ($host === 'res.cloudinary.com' || str_ends_with($host, '.res.cloudinary.com'));
    }

    /**
     * يحقن مقطع تحويل في مسار رفع Cloudinary.
     *
     * الشكل:  .../image/upload/<transform>/v1234/...
     * نتحقق أولاً أن مسار upload/ موجود، فإن لم يكن نرجّع الرابط كما هو
     * بدل إنتاج رابط مكسور. وإن كان الرابط يحمل تحويلاً مسبقاً لا نضاعفه.
     */
    public static function withCloudinaryTransform(string $url, int $width, int $height): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }

        $path = $parts['path'] ?? '';
        $marker = '/image/upload/';
        $pos = strpos($path, $marker);

        if ($pos === false) {
            return $url;
        }

        $head = substr($path, 0, $pos + strlen($marker));
        $tail = substr($path, $pos + strlen($marker));

        // تحويل موجود مسبقاً (مثل w_100,h_100/v123/...) — لا نضاعفه
        if (preg_match('#^[a-z]+_[^/]+(?:,[a-z]+_[^/]+)*/#i', $tail)) {
            return $url;
        }

        $transform = sprintf(
            'w_%d,h_%d,c_fill,g_auto,f_auto,q_auto:good,dpr_auto',
            max(1, $width),
            max(1, $height)
        );

        $rebuilt = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .$head.$transform.'/'.$tail
            .(isset($parts['query']) ? '?'.$parts['query'] : '');

        return $rebuilt;
    }
}
