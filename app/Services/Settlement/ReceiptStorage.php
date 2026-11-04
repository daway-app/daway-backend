<?php

namespace App\Services\Settlement;

use App\Services\Settlement\Receipts\CloudinaryPublicReceipts;
use App\Services\Settlement\Receipts\CloudinaryReceipts;
use App\Services\Settlement\Receipts\DiskReceipts;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * موجّه تخزين إيصالات التحويل.
 *
 * السائقون:
 *   · cloudinary_public : الوضع المعتمد — رفع غير موقّع عبر upload_preset،
 *                         الملف **عام** (أي من يملك الرابط يفتحه). صور فقط.
 *   · cloudinary        : الوضع المحمي القديم (signed + authenticated) —
 *                         يبقى للقراءة فقط ما دامت المفاتيح غير متوفرة.
 *   · disk              : قرص Laravel (local/r2) — احتياطي وللصفوف القديمة.
 *
 * الصفوف القديمة (`transfer_receipt_driver = null`) تُقرأ كـ«قرص».
 */
class ReceiptStorage
{
    public function __construct(
        private readonly DiskReceipts $disk,
        private readonly CloudinaryReceipts $cloudinary,
        private readonly CloudinaryPublicReceipts $publicCloudinary,
    ) {}

    /** اسم السائق الذي سيُستخدم للرفع الآن. */
    public function driver(): string
    {
        $mode = strtolower(trim((string) config('services.cloudinary.receipts.driver', 'auto')));

        if ($mode === 'disk') {
            return DiskReceipts::DRIVER;
        }

        if ($mode === CloudinaryPublicReceipts::DRIVER) {
            if (! $this->publicCloudinary->configured()) {
                throw new RuntimeException(
                    'SETTLEMENT_RECEIPT_DRIVER=cloudinary_public يحتاج '.
                    'CLOUDINARY_CLOUD_NAME و CLOUDINARY_UPLOAD_PRESET.'
                );
            }

            return CloudinaryPublicReceipts::DRIVER;
        }

        if ($mode === CloudinaryReceipts::DRIVER) {
            // الوضع المحمي يتطلب مفاتيح؛ لا نسقط إلى قرص مؤقت بصمت.
            if (! $this->cloudinary->configured()) {
                throw new RuntimeException(
                    'SETTLEMENT_RECEIPT_DRIVER=cloudinary يحتاج CLOUDINARY_API_KEY و CLOUDINARY_API_SECRET. '.
                    'للعمل بلا مفاتيح استخدم cloudinary_public.'
                );
            }

            return CloudinaryReceipts::DRIVER;
        }

        // auto: الوضع العام إن كان الـpreset مضبوطًا، وإلا القرص.
        return $this->publicCloudinary->configured()
            ? CloudinaryPublicReceipts::DRIVER
            : DiskReceipts::DRIVER;
    }

    /** هل التخزين الحالي على Cloudinary (أي وضع)؟ */
    public function usesCloudinary(): bool
    {
        try {
            return $this->driver() !== DiskReceipts::DRIVER;
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
        return match ($this->driver()) {
            CloudinaryPublicReceipts::DRIVER => $this->publicCloudinary->put($file),
            CloudinaryReceipts::DRIVER => $this->cloudinary->put($file),
            default => $this->disk->put($file),
        };
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
    private function resolve(?string $driver): DiskReceipts|CloudinaryReceipts|CloudinaryPublicReceipts
    {
        return match ($driver) {
            CloudinaryReceipts::DRIVER => $this->cloudinary,
            CloudinaryPublicReceipts::DRIVER => $this->publicCloudinary,
            default => $this->disk,
        };
    }
}
