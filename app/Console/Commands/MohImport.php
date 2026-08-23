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

        if (! is_file($path)) {
            $this->error("الملف غير موجود: {$path}");

            return self::FAILURE;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            $this->error("تعذر قراءة الملف: {$path}");

            return self::FAILURE;
        }

        $rows = json_decode($json, true);
        if (! is_array($rows)) {
            $this->error('ملف غير صالح: يجب أن يكون مصفوفة JSON.');

            return self::FAILURE;
        }

        $this->info('جاري استيراد '.count($rows).' دواء...');

        DB::transaction(function () use ($rows) {
            MohMedicine::query()->delete();
            foreach (array_chunk($rows, 1000) as $chunk) {
                MohMedicine::insert($chunk);
            }
        });

        // إبطال الكاش المرتبط بكتالوج الوزارة بعد نجاح الاستيراد
        Cache::add('med_catalog_version', 1, 3600 * 24 * 30);
        Cache::increment('med_catalog_version');
        Cache::add('med_medicines_version', 1, 3600 * 24 * 30);
        Cache::increment('med_medicines_version');

        $this->info('تم الاستيراد بنجاح: '.MohMedicine::count().' دواء.');

        return self::SUCCESS;
    }
}