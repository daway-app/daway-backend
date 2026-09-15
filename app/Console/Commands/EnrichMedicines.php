<?php

namespace App\Console\Commands;

use App\Models\MedicineEnrichmentRun;
use App\Models\MohMedicine;
use App\Services\Enrichment\BarcodeNormalizer;
use App\Services\Enrichment\EnrichmentEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * إثراء الكتالوج الرئيسي (moh_medicines) — بلا overwrite للأصل:
 *
 *   php artisan medicines:enrich --limit=20 --dry-run
 *   php artisan medicines:enrich --limit=100
 *   php artisan medicines:enrich --medicine=123
 *   php artisan medicines:enrich --barcode=6290123456789
 *
 * resume: من last_offset المُسجّل في medicine_enrichment_runs (مصدر الحقيقة).
 */
final class EnrichMedicines extends Command
{
    protected $signature = 'medicines:enrich
        {--batch=100 : حجم الدفعة في كل تشغيل}
        {--limit= : حد أقصى عدد السجلات لهذا التشغيل}
        {--medicine= : دواء واحد (moh_medicines.id)}
        {--barcode= : بحث محفوظ في medicine_barcodes (بلا ضرب مزوّد)}
        {--dry-run : عرض النتائج فقط — بلا أي كتابة}';

    protected $description = 'إثراء بيانات الأدوية (أسماء/باركود/صور) من مزوّدات مفعّلة — الأصل له الأولوية وأقل من الثقة المعتبرة لا يُقبَل آلياً';

    public function handle(): int
    {
        $engine = app(EnrichmentEngine::class);
        $dryRun = (bool) $this->option('dry-run');

        try {
            if (($medicineId = $this->option('medicine')) !== null) {
                return $this->enrichOne((int) $medicineId, $engine, $dryRun);
            }

            if (($barcodeValue = $this->option('barcode')) !== null) {
                return $this->lookupBarcode((string) $barcodeValue);
            }

            return $this->runBatch($engine, $dryRun, (int) $this->option('batch'), $this->option('limit'));
        } catch (Throwable $e) {
            report($e);
            $this->error('فشل: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /** batch مربوط بـ resume — batch واحدة كل تشغيل (batches متأخرة تكرار). */
    private function runBatch(EnrichmentEngine $engine, bool $dryRun, int $batch, ?int $limit): int
    {
        // Resume: أحدث run نصف منتهٍ → نستكمل من last_offset بدون ضعيّف وحد'.
        $previous = MedicineEnrichmentRun::where('status', MedicineEnrichmentRun::STATUS_RUNNING)
            ->orderByDesc('id')->first();

        $batchSize = $limit ?? $batch;

        $run = $engine->run($previous, $dryRun, $batchSize);

        if ($run->status === MedicineEnrichmentRun::STATUS_ABORTED) {
            $this->error('لا مزوّد مفعّل — تحقق config/enrichment.php و credentials');

            return self::FAILURE;
        }

        $this->table(
            ['المقيس', 'القيمة'],
            [
                ['عدّاد مِصطائم', number_format($run->processed_records)],
                ['مطابق (auto)', number_format($run->matched_records)],
                ['للمراجعة', number_format($run->review_records)],
                ['مرفوض/لا مطابقة', number_format($run->failed_records)],
                ['آخر offset (للاستئناف)', number_format($run->last_offset)],
                ['run id', $run->id],
                ['dry run', $dryRun ? 'نعم' : 'لا'],
            ]
        );

        return self::SUCCESS;
    }

    /** دواء واحد عبر الengine مباشرة. */
    private function enrichOne(int $mohId, EnrichmentEngine $engine, bool $dryRun): int
    {
        $moh = MohMedicine::find($mohId);
        if ($moh === null) {
            $this->error("دواء #{$mohId} غير موجود");

            return self::FAILURE;
        }

        $decision = $engine->enrichOne($moh, $dryRun);

        $this->table(
            ['العنصر', 'القيمة'],
            [
                ['medicine', "#{$mohId} — ".($moh->trade_name ?: '—')],
                ['قرار', $decision],
                ['dry run', $dryRun ? 'نعم' : 'لا'],
            ]
        );

        return $decision === 'no_provider' ? self::FAILURE : self::SUCCESS;
    }

    /** بحث باركود من الDB وصله — لا مزوّد خارجي. */
    private function lookupBarcode(string $barcode): int
    {
        $normalized = BarcodeNormalizer::normalize($barcode);
        if ($normalized === null) {
            $this->error('تنسيق باركود غير معروف (EAN13/EAN8/UPCA/GTIN14 فقط)');

            return self::FAILURE;
        }
        if ($normalized['raw'] !== '' && $normalized['raw'] != null) {
            $this->line('raw: '.$normalized['raw'].' | normalized: '.$normalized['barcode'].' | type: '.$normalized['type']);
        } else {
            $this->line('normalized: '.$normalized['barcode'].' | type: '.$normalized['type']);
        }

        $row = DB::table('medicine_barcodes')->where('barcode', $normalized['barcode'])->first();
        if ($row === null) {
            $this->warn('لا سجل بهذا الباركود في Daway بعد');

            return self::SUCCESS;
        }
        $this->line('=== .'.json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $medicine = MohMedicine::find($row->moh_medicine_id);
        if ($medicine !== null) {
            $this->info('الدواء: '.($medicine->trade_name ?: '—').' ('.($medicine->generic_name ?: '—').')');
        }

        return self::SUCCESS;
    }
}
