<?php

namespace App\Support;

class Image
{
    /**
     * يعيد رابط الصورة كما هو إن كان رابطاً خارجياً (Cloudinary)،
     * وإلا يبنيه من asset() للصور المخزنة محلياً.
     */
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return str_starts_with($path, 'http') ? $path : asset($path);
    }
}
