<?php

namespace App\Services\Settlement\Receipts;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * إيصالات التحويل على Cloudinary بالوضع **العام غير الموقّع**.
 *
 * لماذا: لا يوجد API Key/Secret في المشروع، والمطلوب استخدام الـpreset
 * الموجود أصلًا لصور الأدوية (`CLOUDINARY_UPLOAD_PRESET`). هذا الوضع **لا
 * يوفّر أي حماية للملف**: أي شخص يملك الرابط يفتحه.
 *
 * ⚠️ نتيجة أمنية يجب ألا تُخفى:
 *  · الملف يُخزَّن بنوع التسليم الافتراضي (`upload`) = عام.
 *  · إخفاء الرابط في استجابات الـAPI **لا يجعل الملف خاصًا** — إنه إجراء
 *    لتقليل الانتشار فقط، وليس حماية.
 *  · لا يمكن حذف الملف من Cloudinary بدون API Secret ⇒ التنظيف عن بُعد
 *    غير متاح في هذا الوضع (مُوثَّق في التقرير).
 *
 * لذلك: تُقبل الصور فقط (jpg/png/webp) ويُرفض PDF، وتُنبَّه الواجهة إلى ضرورة
 * رفع صورة منقّحة بلا أرقام حسابات أو بيانات شخصية.
 */
class CloudinaryPublicReceipts
{
    public const DRIVER = 'cloudinary_public';

    /** جلب الصورة من Cloudinary للبثّ عبر Laravel. */
    private const DOWNLOAD_TIMEOUT = 30;

    /** فحص وجود الملف برأس HTTP — لا يحتاج مفاتيح. */
    private const HEAD_TIMEOUT = 8;

    private const IMAGE_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function configured(): bool
    {
        return filled(config('services.cloudinary.cloud'))
            && filled(config('services.cloudinary.upload_preset'));
    }

    /**
     * @return array<string, mixed> أعمدة القاعدة
     *
     * @throws RuntimeException
     */
    public function put(UploadedFile $file): array
    {
        if (! $this->configured()) {
            throw new RuntimeException(
                'رفع الإيصالات يحتاج CLOUDINARY_CLOUD_NAME و CLOUDINARY_UPLOAD_PRESET.'
            );
        }

        $format = $this->assertSupportedImage($file);

        try {
            $response = Http::asMultipart()
                ->attach('file', $file->getContent(), $file->getClientOriginalName())
                ->timeout(30)
                ->post($this->endpoint(), [
                    ['name' => 'upload_preset', 'contents' => (string) config('services.cloudinary.upload_preset')],
                    ['name' => 'folder', 'contents' => $this->folder()],
                ]);
        } catch (Throwable $e) {
            Log::warning('settlement_receipt_public_upload_failed', ['exception' => $e::class]);

            throw new RuntimeException('تعذّر رفع إشعار التحويل إلى التخزين.', 0, $e);
        }

        if (! $response->successful()) {
            // لا نسجّل جسم الاستجابة كاملًا: قد يحتوي مسارًا داخليًا.
            Log::warning('settlement_receipt_public_upload_rejected', ['status' => $response->status()]);

            throw new RuntimeException('رفض التخزين صورة الإيصال.');
        }

        $publicId = (string) $response->json('public_id', '');
        $url = (string) $response->json('secure_url', '');

        if ($publicId === '' || ! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('استجابة التخزين ناقصة: لا معرّف ملف ولا رابط.');
        }

        return [
            // المعرّف فقط هو ما يُخزَّن كمرجع — الرابط داخل meta ولا يُعاد للعميل.
            'transfer_receipt_path' => $publicId,
            'transfer_receipt_original_name' => $file->getClientOriginalName(),
            'transfer_receipt_mime' => $file->getMimeType() ?: $file->getClientMimeType(),
            'transfer_receipt_size' => (int) $response->json('bytes', $file->getSize()),
            'transfer_receipt_driver' => self::DRIVER,
            'transfer_receipt_meta' => [
                'public_id' => $publicId,
                'resource_type' => (string) $response->json('resource_type', 'image'),
                'type' => (string) $response->json('type', 'upload'),
                'format' => (string) $response->json('format', $format),
                'url' => $url,
                // تصريح صريح بالطبيعة الأمنية للملف — يُقرأ في التقارير والاختبارات.
                'delivery' => 'public',
            ],
        ];
    }

    public function exists(?string $path, ?array $meta = null): bool
    {
        $url = $this->urlOf($meta);

        if ($url === '') {
            return false;
        }

        try {
            return Http::timeout(self::HEAD_TIMEOUT)->head($url)->successful();
        } catch (Throwable $e) {
            Log::info('settlement_receipt_public_lookup_failed', ['exception' => $e::class]);

            return false;
        }
    }

    /**
     * يُبثّ عبر Laravel بعد التحقق من الصلاحية.
     * الرابط العام لا يُعاد للعميل — لكن تذكّر: الملف نفسه عام عند من يعرف رابطه.
     */
    public function download(
        string $path,
        ?string $originalName = null,
        ?string $mime = null,
        ?array $meta = null,
    ): StreamedResponse {
        $url = $this->urlOf($meta);

        if ($url === '') {
            throw new RuntimeException('لا يوجد رابط تخزين لهذا الإيصال.');
        }

        $response = Http::timeout(self::DOWNLOAD_TIMEOUT)->get($url);

        if (! $response->successful()) {
            Log::warning('settlement_receipt_public_fetch_failed', ['status' => $response->status()]);

            throw new RuntimeException('تعذّر جلب إشعار التحويل من التخزين.');
        }

        $body = $response->body();
        $filename = $originalName ?: basename($path);

        return response()->streamDownload(
            fn () => print $body,
            $filename,
            [
                'Content-Type' => $mime ?: 'application/octet-stream',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    /**
     * لا يمكن حذف الأصل من Cloudinary بدون API Secret.
     * لا نرمي استثناءً: فشل التنظيف يجب ألا يُفشل عملية مالية تمّت.
     */
    public function delete(?string $path, ?array $meta = null): void
    {
        if (! is_string($path) || trim($path) === '') {
            return;
        }

        Log::notice('settlement_receipt_public_delete_skipped', [
            'reason' => 'no_api_secret',
            'public_id' => $path,
        ]);
    }

    /**
     * التحقق من النوع **بالمحتوى** لا بالامتداد، ورفض ما ليس صورة مدعومة.
     *
     * @return string الامتداد المعتمد
     */
    private function assertSupportedImage(UploadedFile $file): string
    {
        $mime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType()));

        if (! isset(self::IMAGE_MIMES[$mime])) {
            throw new RuntimeException('الوضع العام يقبل صور JPG أو PNG أو WEBP فقط.');
        }

        return self::IMAGE_MIMES[$mime];
    }

    private function urlOf(?array $meta): string
    {
        $url = (string) ($meta['url'] ?? '');

        return str_starts_with($url, 'https://') ? $url : '';
    }

    private function folder(): string
    {
        return trim((string) config('services.cloudinary.receipts.folder', 'daway/settlement-receipts'), '/');
    }

    private function endpoint(): string
    {
        return sprintf(
            'https://api.cloudinary.com/v1_1/%s/image/upload',
            config('services.cloudinary.cloud')
        );
    }
}
