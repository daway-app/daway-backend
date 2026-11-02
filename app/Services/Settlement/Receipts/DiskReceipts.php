<?php

namespace App\Services\Settlement\Receipts;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * تخزين الإيصالات على قرص Laravel (local أو r2).
 *
 * يبقى مدعومًا كمسار احتياطي وللصفوف القديمة، لكن Cloudinary هو المسار المعتمد
 * عندما تكون مفاتيحه مضبوطة.
 *
 * الخصوصية: نرفض أي قرص مضبوط على `visibility` عام — الإيصال يحتوي بيانات بنكية
 * ولا يجوز أن يُخدم عبر رابط مباشر.
 */
class DiskReceipts
{
    public const DIRECTORY = 'platform-settlement-receipts';

    public const DEFAULT_DISK = 'local';

    public const DRIVER = 'disk';

    public function diskName(): string
    {
        $disk = trim((string) config('filesystems.settlement_receipt_disk', self::DEFAULT_DISK));

        return $disk !== '' ? $disk : self::DEFAULT_DISK;
    }

    /**
     * @throws RuntimeException قرص غير مهيّأ أو مضبوط على وصول عام
     */
    public function disk(): Filesystem
    {
        $name = $this->diskName();
        $disks = (array) config('filesystems.disks', []);

        if (! array_key_exists($name, $disks)) {
            throw new RuntimeException("قرص إشعارات التحويل [{$name}] غير مُهيّأ في config/filesystems.php.");
        }

        if ((config("filesystems.disks.{$name}.visibility") ?? 'private') === 'public') {
            throw new RuntimeException("قرص إشعارات التحويل [{$name}] مضبوط على وصول عام؛ يجب أن يكون خاصًا.");
        }

        return Storage::disk($name);
    }

    /**
     * @return array<string, mixed> أعمدة القاعدة التي تُحفظ مع التسوية
     *
     * @throws RuntimeException عند فشل الكتابة أو غياب الملف بعدها
     */
    public function put(UploadedFile $file): array
    {
        $name = $this->diskName();
        $disk = $this->disk();

        $path = $file->store(self::DIRECTORY, $name);

        if (! is_string($path) || $path === '' || ! $disk->exists($path)) {
            if (is_string($path) && $path !== '') {
                $this->silentlyDelete($disk, $path);
            }

            throw new RuntimeException("تعذّر حفظ إشعار التحويل على القرص [{$name}].");
        }

        return [
            'transfer_receipt_path' => $path,
            'transfer_receipt_original_name' => $file->getClientOriginalName(),
            'transfer_receipt_mime' => $file->getMimeType() ?: $file->getClientMimeType(),
            'transfer_receipt_size' => (int) $file->getSize(),
            'transfer_receipt_driver' => self::DRIVER,
            'transfer_receipt_meta' => null,
        ];
    }

    public function exists(?string $path, ?array $meta = null): bool
    {
        if (! is_string($path) || trim($path) === '') {
            return false;
        }

        try {
            return $this->disk()->exists($path);
        } catch (Throwable $e) {
            Log::warning('settlement_receipt_disk_unavailable', ['exception' => $e::class]);

            return false;
        }
    }

    public function download(string $path, ?string $originalName, ?string $mime, ?array $meta = null): StreamedResponse
    {
        $headers = $mime ? ['Content-Type' => $mime] : [];

        return $this->disk()->download($path, $originalName ?: basename($path), $headers);
    }

    public function delete(?string $path, ?array $meta = null): void
    {
        if (! is_string($path) || trim($path) === '') {
            return;
        }

        try {
            $this->silentlyDelete($this->disk(), $path);
        } catch (Throwable $e) {
            Log::warning('settlement_receipt_delete_failed', ['exception' => $e::class]);
        }
    }

    private function silentlyDelete(Filesystem $disk, string $path): void
    {
        try {
            $disk->delete($path);
        } catch (Throwable $e) {
            Log::warning('settlement_receipt_delete_failed', ['exception' => $e::class]);
        }
    }
}
