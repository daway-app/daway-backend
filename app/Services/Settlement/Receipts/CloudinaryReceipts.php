<?php

namespace App\Services\Settlement\Receipts;

use Cloudinary\Asset\DeliveryType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * إيصالات التحويل على Cloudinary بنوع تسليم `authenticated`.
 *
 * لماذا لا نستخدم `App\Support\Cloudinary` الحالي: ذاك يرفع **غير موقّع** عبر
 * upload_preset ويرجّع `secure_url` عامًا. الإيصال مستند بنكي، فلا يجوز أن يكون
 * له رابط CDN صالح لأي شخص. هنا:
 *
 *  · رفع **موقّع** من الخادم (api_key + api_secret عبر SDK الرسمي).
 *  · `type = authenticated` ⇒ لا رابط عام صالح؛ التسليم يتطلب توقيعًا.
 *  · الرابط الموقّع يُبنى لحظيًا بانتهاء صلاحية قصير ولا يُخزَّن ولا يُعاد للعميل.
 *  · الملف يُبثّ عبر Laravel بعد التحقق من الصلاحية.
 *
 * الصور العامة (أدوية/شعارات/تصنيفات) تبقى على المسار القديم تمامًا.
 */
class CloudinaryReceipts
{
    public const DRIVER = 'cloudinary';

    /** صلاحية رابط التنزيل الموقّع — يكفي لطلب واحد. */
    private const SIGNED_URL_TTL = 300;

    /** الحد الأقصى لجلب الإيصال من Cloudinary. */
    private const DOWNLOAD_TIMEOUT = 60;

    private const IMAGE_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const PDF_MIME = 'application/pdf';

    public function __construct(private readonly CloudinaryClient $client) {}

    public function configured(): bool
    {
        return $this->client->configured();
    }

    /**
     * @return array<string, mixed> أعمدة القاعدة
     *
     * @throws RuntimeException
     */
    public function put(UploadedFile $file): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('مفاتيح Cloudinary الخاصة بالإيصالات غير مضبوطة.');
        }

        [$resourceType, $fallbackFormat] = $this->classify($file);

        try {
            $result = $this->client->upload($file->getRealPath(), [
                'resource_type' => $resourceType,
                'type' => DeliveryType::AUTHENTICATED,
                'folder' => (string) config('services.cloudinary.receipts.folder', 'daway/settlement-receipts'),
                'use_filename' => true,
                'unique_filename' => true,
                'overwrite' => false,
            ]);
        } catch (Throwable $e) {
            // لا نُسجّل الاستجابة الكاملة: قد تحتوي بيانات اعتماد أو مسارًا داخليًا.
            Log::warning('settlement_receipt_cloudinary_upload_failed', ['exception' => $e::class]);

            throw new RuntimeException('تعذّر رفع إشعار التحويل إلى التخزين المحمي.', 0, $e);
        }

        $publicId = (string) ($result['public_id'] ?? '');

        if ($publicId === '') {
            throw new RuntimeException('رفض التخزين المحمي الإيصال بلا معرّف ملف.');
        }

        return [
            'transfer_receipt_path' => $publicId,
            'transfer_receipt_original_name' => $file->getClientOriginalName(),
            'transfer_receipt_mime' => $file->getMimeType() ?: $file->getClientMimeType(),
            'transfer_receipt_size' => (int) ($result['bytes'] ?? $file->getSize()),
            'transfer_receipt_driver' => self::DRIVER,
            'transfer_receipt_meta' => [
                'public_id' => $publicId,
                'resource_type' => (string) ($result['resource_type'] ?? $resourceType),
                'type' => (string) ($result['type'] ?? DeliveryType::AUTHENTICATED),
                'format' => (string) ($result['format'] ?? $fallbackFormat),
            ],
        ];
    }

    public function exists(?string $path, ?array $meta = null): bool
    {
        if (! is_string($path) || trim($path) === '' || ! $this->configured()) {
            return false;
        }

        try {
            return $this->client->assetExists($path, $this->assetOptions($meta));
        } catch (Throwable $e) {
            // 404 من Cloudinary أو تعذّر الوصول: لا نُسلّم شيئًا.
            Log::info('settlement_receipt_cloudinary_lookup_failed', ['exception' => $e::class]);

            return false;
        }
    }

    /**
     * يبني رابطًا موقّعًا قصير الصلاحية على الخادم، يجلب البايتات، ثم يبثّها.
     * الرابط نفسه لا يصل إلى العميل ولا يُخزَّن في أي مكان.
     */
    public function download(
        string $path,
        ?string $originalName = null,
        ?string $mime = null,
        ?array $meta = null,
    ): StreamedResponse {
        $format = (string) ($meta['format'] ?? $this->guessFormat($originalName, $mime));

        try {
            $url = $this->client->signedDownloadUrl($path, $format, [
                'type' => (string) ($meta['type'] ?? DeliveryType::AUTHENTICATED),
                'resource_type' => (string) ($meta['resource_type'] ?? 'image'),
                'attachment' => true,
                'expires_at' => time() + self::SIGNED_URL_TTL,
            ]);
        } catch (Throwable $e) {
            Log::warning('settlement_receipt_cloudinary_sign_failed', ['exception' => $e::class]);

            throw new RuntimeException('تعذّر تجهيز رابط تنزيل موقّع.', 0, $e);
        }

        $response = Http::timeout(self::DOWNLOAD_TIMEOUT)->get($url);

        if (! $response->successful()) {
            Log::warning('settlement_receipt_cloudinary_fetch_failed', ['status' => $response->status()]);

            throw new RuntimeException('تعذّر جلب إشعار التحويل من التخزين المحمي.');
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

    public function delete(?string $path, ?array $meta = null): void
    {
        if (! is_string($path) || trim($path) === '' || ! $this->configured()) {
            return;
        }

        try {
            $this->client->destroy($path, [
                'type' => (string) ($meta['type'] ?? DeliveryType::AUTHENTICATED),
                'resource_type' => (string) ($meta['resource_type'] ?? 'image'),
                'invalidate' => true,
            ]);
        } catch (Throwable $e) {
            Log::warning('settlement_receipt_cloudinary_delete_failed', ['exception' => $e::class]);
        }
    }

    /**
     * أصول مجلد الإيصالات على Cloudinary — تُستخدم لتنظيف اليتامى فقط.
     *
     * @return list<array<string, mixed>>
     */
    public function listAssets(string $resourceType, string $prefix, int $maxPages = 20): array
    {
        $assets = [];
        $cursor = null;
        $pages = 0;

        do {
            $options = [
                'type' => DeliveryType::AUTHENTICATED,
                'resource_type' => $resourceType,
                'prefix' => $prefix,
                'max_results' => 100,
            ];

            if ($cursor !== null) {
                $options['next_cursor'] = $cursor;
            }

            $response = $this->client->assets($options);

            foreach ($response['resources'] ?? [] as $resource) {
                $assets[] = (array) $resource;
            }

            $cursor = $response['next_cursor'] ?? null;
            $pages++;
        } while ($cursor !== null && $pages < $maxPages);

        return $assets;
    }

    /**
     * PDF يُرفع كمورد `raw` حتى لا يمرّ بمسارات معالجة الصور.
     *
     * @return array{0: string, 1: string} [resource_type, format]
     */
    private function classify(UploadedFile $file): array
    {
        $mime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType()));
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($mime === self::PDF_MIME || $extension === 'pdf') {
            return [(string) config('services.cloudinary.receipts.pdf_resource_type', 'raw'), 'pdf'];
        }

        if (isset(self::IMAGE_MIMES[$mime])) {
            return [
                (string) config('services.cloudinary.receipts.image_resource_type', 'image'),
                self::IMAGE_MIMES[$mime],
            ];
        }

        throw new RuntimeException('نوع الملف غير مدعوم لإشعار التحويل.');
    }

    private function guessFormat(?string $originalName, ?string $mime): string
    {
        $extension = strtolower((string) pathinfo((string) $originalName, PATHINFO_EXTENSION));

        if ($extension !== '') {
            return $extension;
        }

        return self::IMAGE_MIMES[$mime ?? ''] ?? 'pdf';
    }

    /** @return array<string, string> */
    private function assetOptions(?array $meta): array
    {
        return [
            'type' => (string) ($meta['type'] ?? DeliveryType::AUTHENTICATED),
            'resource_type' => (string) ($meta['resource_type'] ?? 'image'),
        ];
    }
}
