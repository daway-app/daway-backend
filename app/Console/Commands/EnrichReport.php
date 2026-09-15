<?php

namespace App\Console\Commands;

use App\Models\MedicineBarcode;
use App\Models\MedicineEnrichmentReview;
use App\Models\MedicineEnrichmentRun;
use App\Models\MohMedicine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * تقرير الإثراء (JSON/CSV + عرض بالكونsoles) — أرقام من DB (لا hardcode).
 */
final class EnrichReport extends Command
{
    protected $signature = 'medicines:enrich-report
        {--export : حفظ تقرير بملف database/reports/medicine_enrichment_report.json}';

    protected $description = 'تقرير شامل لنظام الإثراء: الكتالوج، الباركودات، الصور، المراجعات، تشغيلات الـrun';

    public function handle(): int
    {
        $report = [
            'generated_at' => now()->toDateTimeString(),
            'total_medicines' => MohMedicine::count(),
            'distict_medicines_with_barcode' => (int) MedicineBarcode::query()->distinct()->count('moh_medicine_id'),
            'arabic_names_matched' => (int) MohMedicine::whereNotNull('generic_name')->where('generic_name', '!=', '')->count(),
            'english_names_matched' => (int) MohMedicine::whereNotNull('trade_name')->where('trade_name', '!=', '')->count(),
            'barcode_rows' => (int) MedicineBarcode::count(),
            'barcode_verified' => (int) MedicineBarcode::where('is_verified', true)->count(),
            'image_rows' => (int) DB::table('medicine_images')->count(),
            'manufacturer_matched' => (int) MohMedicine::whereNotNull('manufacturer')->where('manufacturer', '!=', '')->count(),
            'runs' => $this->runsSummary(),
            'reviews' => [
                'pending' => (int) MedicineEnrichmentReview::where('status', MedicineEnrichmentReview::STATUS_PENDING)->count(),
                'approved' => (int) MedicineEnrichmentReview::where('status', MedicineEnrichmentReview::STATUS_APPROVED)->count(),
                'rejected' => (int) MedicineEnrichmentReview::where('status', MedicineEnrichmentReview::STATUS_REJECTED)->count(),
            ],
        ];

        $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->newLine();

        if ((bool) $this->option('export')) {
            $this->export($report);
        }

        return self::SUCCESS;
    }

    private function runsSummary(): array
    {
        return MedicineEnrichmentRun::query()
            ->orderByDesc('id')
            ->take(5)
            ->get()
            ->map(fn ($run) => [
                'id' => $run->id,
                'provider' => $run->provider,
                'status' => $run->status,
                'total_records' => $run->total_records,
                'processed_records' => $run->processed_records,
                'matched_records' => $run->matched_records,
                'barcode_matches' => $run->barcode_matches,
                'review_records' => $run->review_records,
                'failed_records' => $run->failed_records,
                'last_offset' => $run->last_offset,
            ])->all();
    }

    private function export(array $report): void
    {
        // lưu الإصدار داخل database/reports (لا خارج المستودع).
        $dir = database_path('reports');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($dir.'/medicine_enrichment_report.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->info('report saved to database/reports/medicine_enrichment_report.json');
    }
}
