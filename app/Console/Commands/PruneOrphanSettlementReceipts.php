<?php

namespace App\Console\Commands;

use App\Models\PlatformSettlement;
use App\Services\Settlement\Receipts\CloudinaryReceipts;
use App\Services\Settlement\Receipts\DiskReceipts;
use App\Services\Settlement\ReceiptStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * تنظيف إيصالات التحويل اليتيمة.
 *
 * متى تنشأ؟ إذا رُفع الملف ثم ماتت العملية قبل commit المعاملة (أو انقطع النشر
 * في المنتصف). الملف يبقى في التخزين بلا أي صف في `platform_settlements` يشير
 * إليه — تكلفة دائمة وبيانات بنكية بلا مالك.
 *
 * الأمان:
 *  · لا يُحذف ملف أحدث من `--hours` لأنه قد يكون رفعًا جارٍ الآن.
 *  · لا يُحذف أي ملف مشار إليه في القاعدة.
 *  · يعمل على السائق الفعلي: Cloudinary أو القرص.
 */
class PruneOrphanSettlementReceipts extends Command
{
    protected $signature = 'settlements:prune-orphan-receipts
                            {--hours=24 : لا تحذف ملفات أحدث من هذا العمر بالساعات}
                            {--dry-run : اعرض ما سيُحذف دون حذف}';

    protected $description = 'حذف إشعارات التحويل التي لا تشير إليها أي تسوية (مع فترة سماح)';

    public function handle(ReceiptStorage $storage, DiskReceipts $disk, CloudinaryReceipts $cloudinary): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subHours($hours)->getTimestamp();

        try {
            return $storage->isCloudinary()
                ? $this->pruneCloudinary($cloudinary, $cutoff, $dryRun)
                : $this->pruneDisk($disk, $cutoff, $dryRun);
        } catch (Throwable $e) {
            $this->error('تعذّر تجهيز التخزين: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function pruneDisk(DiskReceipts $disk, int $cutoff, bool $dryRun): int
    {
        $files = $disk->disk()->files(DiskReceipts::DIRECTORY);
        $referenced = $this->referencedPaths();

        $deleted = 0;
        $kept = 0;
        $skippedRecent = 0;

        foreach ($files as $file) {
            if ($referenced->has($file)) {
                $kept++;

                continue;
            }

            if ((int) $disk->disk()->lastModified($file) > $cutoff) {
                $skippedRecent++;

                continue;
            }

            if ($dryRun) {
                $this->line("سيُحذف: {$file}");
                $deleted++;

                continue;
            }

            $disk->delete($file);
            $deleted++;
        }

        return $this->report('disk', count($files), $kept, $skippedRecent, $deleted, $dryRun);
    }

    private function pruneCloudinary(CloudinaryReceipts $cloudinary, int $cutoff, bool $dryRun): int
    {
        $referenced = $this->referencedPaths();
        $prefix = trim((string) config('services.cloudinary.receipts.folder', 'daway/settlement-receipts'), '/');

        $total = 0;
        $kept = 0;
        $skippedRecent = 0;
        $deleted = 0;

        // الإيصالات قد تكون `image` أو `raw` — نفحص النوعين.
        foreach (['image', 'raw'] as $resourceType) {
            foreach ($cloudinary->listAssets($resourceType, $prefix) as $asset) {
                $publicId = (string) ($asset['public_id'] ?? '');
                if ($publicId === '') {
                    continue;
                }

                $total++;

                if ($referenced->has($publicId)) {
                    $kept++;

                    continue;
                }

                $createdAt = strtotime((string) ($asset['created_at'] ?? '')) ?: 0;
                if ($createdAt > $cutoff) {
                    $skippedRecent++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("سيُحذف: {$publicId} ({$resourceType})");
                    $deleted++;

                    continue;
                }

                $cloudinary->delete($publicId, [
                    'resource_type' => $resourceType,
                    'type' => (string) ($asset['type'] ?? 'authenticated'),
                ]);
                $deleted++;
            }
        }

        return $this->report('cloudinary', $total, $kept, $skippedRecent, $deleted, $dryRun);
    }

    /** المسارات/المعرّفات المشار إليها في القاعدة — مصدر الحقيقة. */
    private function referencedPaths(): Collection
    {
        return PlatformSettlement::query()
            ->whereNotNull('transfer_receipt_path')
            ->pluck('transfer_receipt_path')
            ->flip();
    }

    private function report(string $driver, int $total, int $kept, int $recent, int $deleted, bool $dryRun): int
    {
        $this->info(sprintf(
            '%s — السائق: %s · ملفات: %d · مُشار إليها: %d · حديثة (تُركت): %d · %s: %d',
            $dryRun ? 'محاكاة' : 'تنفيذ',
            $driver,
            $total,
            $kept,
            $recent,
            $dryRun ? 'ستُحذف' : 'حُذفت',
            $deleted
        ));

        return self::SUCCESS;
    }
}
