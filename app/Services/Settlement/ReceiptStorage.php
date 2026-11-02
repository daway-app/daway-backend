<?php

namespace App\Services\Settlement;

use App\Services\Settlement\Receipts\CloudinaryReceipts;
use App\Services\Settlement\Receipts\DiskReceipts;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * موجّه تخزين إيصالات التحويل.
 *
 * مسار واحد فقط فعّال في أي لحظة، يُحدَّد من `services.cloudinary.receipts.driver`:
 *   · cloudinary : رفع موقّع + نوع تسليم authenticated (المسار المعتمد)
 *   · disk       : قرص Laravel (local/r2) — احتياطي وللصفوف القديمة
 *   · auto       : Cloudinary إن كان مهيّأً، وإلا القرص
 *
 * الصفوف القديمة (`transfer_receipt_driver = null`) تُقرأ كـ«قرص»، فلا حاجة
 * لأي ترحيل بيانات.
 */
class ReceiptStorage
{
    public function __construct(
        private readonly DiskReceipts $disk,
        private readonly CloudinaryReceipts $cloudinary,
    ) {}

    /** اسم السائق الذي سيُستخدم للرفع الآن. */
    public function driver(): string
    {
        $mode = strtolower(trim((string) config('services.cloudinary.receipts.driver', 'auto')));

        if ($mode === 'disk') {
            return DiskReceipts::DRIVER;
        }

        if ($mode === 'cloudinary') {
            // إلزام صريح: لا نسقط إلى قرص مؤقت على Render بصمت.
            if (! $this->cloudinary->configured()) {
                throw new RuntimeException(
                    'SETTLEMENT_RECEIPT_DRIVER=cloudinary لكن مفاتيح Cloudinary غير مضبوطة '.
                    '(CLOUDINARY_CLOUD_NAME / CLOUDINARY_API_KEY / CLOUDINARY_API_SECRET).'
                );
            }

            return CloudinaryReceipts::DRIVER;
        }

        return $this->cloudinary->configured() ? CloudinaryReceipts::DRIVER : DiskReceipts::DRIVER;
    }

    public function isCloudinary(): bool
    {
        try {
            return $this->driver() === CloudinaryReceipts::DRIVER;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @return array<string, mixed> أعمدة القاعدة
     *
     * @throws RuntimeException
     */
    public function put(UploadedFile $file): array
    {
        return $this->driver() === CloudinaryReceipts::DRIVER
            ? $this->cloudinary->put($file)
            : $this->disk->put($file);
    }

    public function exists(?string $driver, ?string $path, ?array $meta = null): bool
    {
        return $this->resolve($driver)->exists($path, $meta);
    }

    public function download(
        ?string $driver,
        ?string $path,
        ?string $originalName = null,
        ?string $mime = null,
        ?array $meta = null,
    ): StreamedResponse {
        return $this->resolve($driver)->download($path, $originalName, $mime, $meta);
    }

    public function delete(?string $driver, ?string $path, ?array $meta = null): void
    {
        $this->resolve($driver)->delete($path, $meta);
    }

    /** سائق القراءة: يعتمد على ما حُفظ فعلاً، لا على الإعداد الحالي. */
    private function resolve(?string $driver): DiskReceipts|CloudinaryReceipts
    {
        return $driver === CloudinaryReceipts::DRIVER ? $this->cloudinary : $this->disk;
    }
}
