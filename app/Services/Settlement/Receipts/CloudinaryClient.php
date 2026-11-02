<?php

namespace App\Services\Settlement\Receipts;

/**
 * العمليات التي نحتاجها من Cloudinary، معزولة عن SDK.
 *
 * السبب: SDK يتصل بـGuzzle مباشرة فلا يمكن اعتراضه بـ`Http::fake()`.
 * الفصل يسمح باختبار كل مسار الإيصال (رفع/تحقق/توقيع/حذف/سرد) دون شبكة
 * ودون مفاتيح حقيقية — مع بقاء التنفيذ الحقيقي في SdkCloudinaryClient.
 */
interface CloudinaryClient
{
    public function configured(): bool;

    /**
     * رفع ملف موقّع.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> استجابة Cloudinary (public_id, resource_type, type, format, bytes)
     */
    public function upload(string $localPath, array $options): array;

    /** @param array<string, mixed> $options */
    public function assetExists(string $publicId, array $options): bool;

    /**
     * رابط تنزيل موقّع قصير الصلاحية.
     *
     * @param  array<string, mixed>  $options
     */
    public function signedDownloadUrl(string $publicId, string $format, array $options): string;

    /** @param array<string, mixed> $options */
    public function destroy(string $publicId, array $options): void;

    /**
     * @param  array<string, mixed>  $options
     * @return array{resources?: array<int, mixed>, next_cursor?: string|null}
     */
    public function assets(array $options): array;
}
