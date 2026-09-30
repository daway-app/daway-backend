<?php

namespace App\Console\Commands;

use App\Models\MohMedicine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MohImport extends Command
{
    protected $signature = 'moh:import {--file=database/data/moh_medicines.json} {--report-conflicts : Report cross-field conflicts without merging}';

    protected $description = 'استيراد كتالوج أدوية وزارة الصحة من ملف ثابت محلي (بدون الحاجة للإنترنت)';

    public function handle(): int
    {
        ini_set('memory_limit', '512M');
        set_time_limit(0);

        try {
            return $this->import();
        } catch (\Throwable $e) {
            $this->error('فشل الاستيراد: '.$e->getMessage());
            Log::error('moh:import فشل: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }

    private function import(): int
    {
        $file = (string) $this->option('file');

        // المسار المطلق يُستخدم كما هو، والمسار النسبي يُبنى من جذر المشروع
        $isAbsolute = preg_match('/^[A-Za-z]:[\\\\\\/]/', $file) || str_starts_with($file, '/') || str_starts_with($file, '\\');
        $path = $isAbsolute ? $file : base_path($file);

        Log::info('moh:import يبحث عن الملف', ['path' => $path, 'exists' => is_file($path)]);

        if (! is_file($path)) {
            $this->error("الملف غير موجود: {$path}");
            Log::error('moh:import الملف غير موجود', ['path' => $path]);

            return self::FAILURE;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            $this->error("تعذر قراءة الملف: {$path}");
            Log::error('moh:import تعذر قراءة الملف', ['path' => $path]);

            return self::FAILURE;
        }

        $size = strlen($json);
        Log::info('moh:import قرأ الملف', ['bytes' => $size, 'mb' => round($size / 1024 / 1024, 2)]);

        $rows = json_decode($json, true);
        if (! is_array($rows)) {
            $this->error('ملف غير صالح: يجب أن يكون مصفوفة JSON.');
            Log::error('moh:import JSON غير صالح');

            return self::FAILURE;
        }

        $count = count($rows);
        $this->info("جاري استيراد {$count} دواء...");
        Log::info('moh:import بدأ الاستيراد', ['rows' => $count]);

        $reportConflicts = (bool) $this->option('report-conflicts');
        $conflictCount = 0;

        DB::transaction(function () use ($rows, $reportConflicts, &$conflictCount) {
            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                $productId = $row['moh_product_id'] ?? null;
                $drugId = $row['moh_drug_id'] ?? null;

                if (! $productId && ! $drugId) {
                    $skipped++;
                    continue;
                }

                if ($reportConflicts && $productId && $drugId) {
                    $existingByProduct = MohMedicine::where('moh_product_id', $productId)->first();
                    $existingByDrug = MohMedicine::where('moh_drug_id', $drugId)->first();
                    if ($existingByProduct && $existingByDrug && $existingByProduct->id !== $existingByDrug->id) {
                        $this->warn("CONFLICT: moh_product_id {$productId} and moh_drug_id {$drugId} point to different rows");
                        $conflictCount++;
                        $skipped++;
                        continue;
                    }
                }

                if ($productId) {
                    $existing = MohMedicine::where('moh_product_id', $productId)->first();
                } elseif ($drugId) {
                    $existing = MohMedicine::where('moh_drug_id', $drugId)->first();
                }

                if (isset($existing)) {
                    $existing->update($row);
                    $updated++;
                } else {
                    MohMedicine::create($row);
                    $created++;
                }
            }

            $this->info("تم الاستيراد: {$created} جديد، {$updated} محدّث، {$skipped} تخطيت.");
        });

        // إبطال الكاش المرتبط بكتالوج الوزارة بعد نجاح الاستيراد
        Cache::add('med_catalog_version', 1, 3600 * 24 * 30);
        Cache::increment('med_catalog_version');
        Cache::add('med_medicines_version', 1, 3600 * 24 * 30);
        Cache::increment('med_medicines_version');

        $finalCount = MohMedicine::count();
        $this->info('تم الاستيراد بنجاح: '.$finalCount.' دواء.');
        Log::info('moh:import انتهى', ['final_count' => $finalCount]);

        if ($reportConflicts && $conflictCount > 0) {
            $this->error("تم العثور على {$conflictCount} تعارض(ات) — لم يتم الدمج أي إنشاء صفوف جديدة.");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}