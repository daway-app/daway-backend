<?php

namespace App\Services\Settlement\Receipts;

use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;

/**
 * التنفيذ الحقيقي عبر SDK الرسمي `cloudinary/cloudinary_php`.
 *
 * التوقيع (signature + api_key) يتولاه الـSDK تلقائيًا لأن api_secret مضبوط،
 * فلا نكتب منطق توقيع يدويًا في مسار مالي.
 *
 * كل استدعاء يبني نسخة إعداد مستقلة — لا نستخدم `Configuration::instance()`
 * العام حتى لا تتسرّب إعدادات بين الطلبات.
 */
class SdkCloudinaryClient implements CloudinaryClient
{
    public function configured(): bool
    {
        return filled(config('services.cloudinary.cloud'))
            && filled(config('services.cloudinary.receipts.api_key'))
            && filled(config('services.cloudinary.receipts.api_secret'));
    }

    public function upload(string $localPath, array $options): array
    {
        $result = $this->cloudinary()->uploadApi()->upload($localPath, $options);

        return (array) $result->getArrayCopy();
    }

    public function assetExists(string $publicId, array $options): bool
    {
        $this->cloudinary()->adminApi()->asset($publicId, $options);

        return true;
    }

    public function signedDownloadUrl(string $publicId, string $format, array $options): string
    {
        return $this->cloudinary()->uploadApi()->privateDownloadUrl($publicId, $format, $options);
    }

    public function destroy(string $publicId, array $options): void
    {
        $this->cloudinary()->uploadApi()->destroy($publicId, $options);
    }

    public function assets(array $options): array
    {
        $response = $this->cloudinary()->adminApi()->assets($options);

        return (array) $response->getArrayCopy();
    }

    private function cloudinary(): Cloudinary
    {
        return new Cloudinary(new Configuration([
            'cloud' => [
                'cloud_name' => (string) config('services.cloudinary.cloud'),
                'api_key' => (string) config('services.cloudinary.receipts.api_key'),
                'api_secret' => (string) config('services.cloudinary.receipts.api_secret'),
            ],
            'url' => ['secure' => true],
        ]));
    }
}
