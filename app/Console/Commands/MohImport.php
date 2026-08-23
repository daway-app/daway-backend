<?php

namespace App\Console\Commands;

use App\Models\MohMedicine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MohImport extends Command
{
    protected $signature = 'moh:import {--file=database/data/moh_medicines.json}';

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

        DB::transaction(function () use ($rows) {
            MohMedicine::query()->delete();
            $chunks = array_chunk($rows, 500);
            foreach ($chunks as $index => $chunk) {
                MohMedicine::insert($chunk);
                Log::info('moh:import تم إدخال مجموعة', ['chunk' => $index + 1, 'rows' => count($chunk)]);
            }
        });

        // إبطال الكاش المرتبط بكتالوج الوزارة بعد نجاح الاستيراد
        Cache::add('med_catalog_version', 1, 3600 * 24 * 30);
        Cache::increment('med_catalog_version');
        Cache::add('med_medicines_version', 1, 3600 * 24 * 30);
        Cache::increment('med_medicines_version');

        $finalCount = MohMedicine::count();
        $this->info('تم الاستيراد بنجاح: '.$finalCount.' دواء.');
        Log::info('moh:import انتهى', ['final_count' => $finalCount]);

        return self::SUCCESS;
    }
}