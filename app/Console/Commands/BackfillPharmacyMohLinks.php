<?php

namespace App\Console\Commands;

use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\PharmacyMedicine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillPharmacyMohLinks extends Command
{
    protected $signature = 'backfill:pharmacy-moh-links {--dry-run : Run without making changes}';

    protected $description = 'Backfill pharmacy_medicines.moh_medicine_id for rows with NULL value';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info($dryRun ? '=== DRY RUN MODE ===' : '=== EXECUTING ===');

        // Check if migration has been run
        $hasMohMedicineIdColumn = Schema::hasColumn('pharmacy_medicines', 'moh_medicine_id');

        // إحصاءات إجمالية - استخدام DB::table للقراءة السريعة
        $totalRows = DB::table('pharmacy_medicines')->count();

        if ($hasMohMedicineIdColumn) {
            $alreadyLinkedCount = DB::table('pharmacy_medicines')
                ->whereNotNull('moh_medicine_id')
                ->count();
            $toProcess = DB::table('pharmacy_medicines')
                ->whereNull('moh_medicine_id')
                ->count();

            $this->info("Total pharmacy_medicines rows: {$totalRows}");
            $this->info("Rows already linked: {$alreadyLinkedCount}");
            $this->info("Rows to process (NULL moh_medicine_id): {$toProcess}");
        } else {
            $alreadyLinkedCount = 0;
            $this->info("Total pharmacy_medicines rows: {$totalRows}");
            $this->info("Already linked: N/A (moh_medicine_id column not present — migration not run)");
            $this->info("Rows to process: ALL rows (moh_medicine_id column not present)");
        }

        // البدء في المعالجة فورًا
        $matched = 0;
        $ambiguous = 0;
        $unmatched = 0;
        $updated = 0;

        // عينات للحالات المشكلة
        $ambiguousSamples = [];
        $unmatchedSamples = [];

        // عند وجود العمود نعالج فقط الصفوف غير المرتبطة حتى لا نمسّ أي
        // linked row موجودة. بدون العمود (pre-migration) نفحص الكل للتدقيق فقط.
        $rowsQuery = DB::table('pharmacy_medicines')->select(['id', 'medicine_id']);
        if ($hasMohMedicineIdColumn) {
            $rowsQuery->whereNull('moh_medicine_id');
        }
        $rows = $rowsQuery->get();

        $totalRowsCount = $rows->count();
        $processed = 0;

        $this->info("Processing {$totalRowsCount} rows...");

        foreach ($rows as $row) {
            $processed++;
            
            // Progress every 10 rows
            if ($processed % 10 === 0) {
                $this->line("Processed {$processed}/{$totalRowsCount}...");
            }

            $medicine = Medicine::find($row->medicine_id);

            if (! $medicine) {
                $unmatched++;
                if (count($unmatchedSamples) < 5) {
                    $unmatchedSamples[] = [
                        'pharmacy_medicine_id' => $row->id,
                        'medicine_trade_name' => 'not found',
                    ];
                }
                continue;
            }

            $matches = MohMedicine::where('trade_name', $medicine->trade_name)->count();

            if ($matches === 1) {
                $matched++;

                // كتابة فعلية فقط خارج dry-run وعند وجود العمود. الصفوف
                // المرتبطة مسبقاً مستبعدة أصلاً من الاستعلام فلا تُمس.
                if (! $dryRun && $hasMohMedicineIdColumn) {
                    $mohId = MohMedicine::where('trade_name', $medicine->trade_name)->value('id');
                    if ($mohId !== null) {
                        DB::table('pharmacy_medicines')->where('id', $row->id)->update(['moh_medicine_id' => $mohId]);
                        $updated++;
                    }
                }
            } elseif ($matches > 1) {
                $ambiguous++;
                if (count($ambiguousSamples) < 5) {
                    $matchingMohs = MohMedicine::where('trade_name', $medicine->trade_name)
                        ->select('id', 'trade_name', 'manufacturer', 'moh_product_id')
                        ->get()
                        ->map(fn ($m) => [
                            'id' => $m->id,
                            'trade_name' => $m->trade_name,
                            'manufacturer' => $m->manufacturer,
                        ])->values()->all();
                    $ambiguousSamples[] = [
                        'pharmacy_medicine_id' => $row->id,
                        'medicine_trade_name' => $medicine->trade_name,
                        'matching_moh_count' => $matches,
                        'matching_mohs' => $matchingMohs,
                    ];
                }
            } else {
                $unmatched++;
                if (count($unmatchedSamples) < 5) {
                    $unmatchedSamples[] = [
                        'pharmacy_medicine_id' => $row->id,
                        'medicine_trade_name' => $medicine->trade_name,
                    ];
                }
            }
        }

        $this->info("\n=== RESULTS ===");
        $this->info("Total inventory rows: {$totalRows}");
        $this->info("Already linked: " . ($hasMohMedicineIdColumn ? $alreadyLinkedCount : 'N/A (moh_medicine_id column not present — migration not run)'));
        $this->info("Matched: {$matched}");
        $this->info("Ambiguous: {$ambiguous}");
        $this->info("Unmatched: {$unmatched}");

        if (! empty($ambiguousSamples)) {
            $this->warn("\n=== AMBIGUOUS SAMPLES ===");
            foreach ($ambiguousSamples as $sample) {
                $this->line(json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }

        if (! empty($unmatchedSamples)) {
            $this->warn("\n=== UNMATCHED SAMPLES ===");
            foreach ($unmatchedSamples as $sample) {
                $this->line(json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }

        if ($dryRun) {
            $this->info("\n=== DRY RUN COMPLETE - NO CHANGES MADE ===");
        } else {
            // الكتابة عبر DB::table تتجاوز model events — نبطل كاش الرؤية يدوياً.
            if ($updated > 0) {
                PharmacyMedicine::bumpInventoryVersion();
            }
            $this->info("Actually updated: {$updated}");
            $this->info("\n=== BACKFILL COMPLETE ===");
        }

        return self::SUCCESS;
    }
}