<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class Cloudinary
{
    /**
     * هل تم ضبط Cloudinary (اسم السحابة + preset)؟
     */
    public static function enabled(): bool
    {
        return filled(config('services.cloudinary.cloud'))
            && filled(config('services.cloudinary.upload_preset'));
    }

    /**
     * رفع صورة إلى Cloudinary (رفع غير موقّع عبر upload_preset)
     * وإعادة الرابط الآمن للصورة.
     *
     * عند فشل الرفع أو عدم ضبط الإعدادات: تُخزن الصورة محلياً على قرص
     * 'public' كحل بديل حتى لا تتعطل العملية أبداً.
     */
    public static function upload(UploadedFile $file, string $folder): ?string
    {
        if (self::enabled()) {
            try {
                $response = Http::asMultipart()
                    ->attach('file', $file->getContent(), $file->getClientOriginalName())
                    ->timeout(30)
                    ->post(self::endpoint(), [
                        ['name' => 'upload_preset', 'contents' => config('services.cloudinary.upload_preset')],
                        ['name' => 'folder', 'contents' => trim(config('services.cloudinary.folder', 'daway').'/'.$folder, '/')],
                    ]);

                if ($response->successful()) {
                    return $response->json('secure_url');
                }

                Log::warning('Cloudinary upload failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Cloudinary upload exception: '.$e->getMessage());
            }
        }

        return $file->store($folder, 'public');
    }

    /**
     * حذف الصورة القديمة فقط إن كانت مخزنة محلياً
     * (روابط Cloudinary الخارجية تُترك كما هي).
     */
    public static function deleteLocal(?string $path): void
    {
        if ($path && ! str_starts_with($path, 'http')) {
            Storage::disk('public')->delete($path);
        }
    }

    private static function endpoint(): string
    {
        return sprintf(
            'https://api.cloudinary.com/v1_1/%s/image/upload',
            config('services.cloudinary.cloud')
        );
    }
}
