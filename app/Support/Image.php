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
}
