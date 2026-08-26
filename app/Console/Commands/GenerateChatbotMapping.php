<?php

namespace App\Console\Commands;

use App\Support\MedicineNameMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateChatbotMapping extends Command
{
    protected $signature = 'chatbot:mapping
        {--file=database/data/moh_medicines.json : ملف كتالوج وزارة الصحة المصدر}
        {--out=database/data/chatbot_medicines.json : مسار ملف المخرجات للشات بوت}';

    protected $description = 'توليد ملف mapping الأدوية للشات بوت (اسم إنجليزي + عربي تقريبي + aliases) من كتالوج وزارة الصحة';

    public function handle(): int
    {
        ini_set('memory_limit', '512M');
        set_time_limit(0);

        try {
            return $this->generate();
        } catch (\Throwable $e) {
            $this->error('فشل التوليد: '.$e->getMessage());
            Log::error('chatbot:mapping فشل: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }

    private function generate(): int
    {
        $sourcePath = $this->resolvePath((string) $this->option('file'));
        if (! is_file($sourcePath)) {
            $this->error("الملف المصدر غير موجود: {$sourcePath}");
            Log::error('chatbot:mapping الملف المصدر غير موجود', ['path' => $sourcePath]);

            return self::FAILURE;
        }

        $json = file_get_contents($sourcePath);
        if ($json === false) {
            $this->error("تعذر قراءة الملف: {$sourcePath}");

            return self::FAILURE;
        }

        $rows = json_decode($json, true);
        if (! is_array($rows)) {
            $this->error('ملف المصدر غير صالح: يجب أن يكون مصفوفة JSON.');

            return self::FAILURE;
        }

        $total = count($rows);
        $this->info("جاري توليد الـ mapping لـ {$total} دواء...");
        Log::info('chatbot:mapping بدأ التوليد', ['rows' => $total]);

        $entries = [];
        $id = 0;
        foreach ($rows as $row) {
            $tradeName = MedicineNameMapper::clean((string) ($row['trade_name'] ?? ''));
            if ($tradeName === '') {
                continue;
            }
            $id++;
            $entries[] = MedicineNameMapper::map($row, $id);
        }

        if ($entries === []) {
            $this->error('لا توجد أسماء أدوية صالحة في الملف المصدر.');

            return self::FAILURE;
        }

        // سطر واحد لكل سجل — حجم أصغر وسهل القراءة والمراجعة اليدوية
        $lines = array_map(
            fn (array $entry): string => json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $entries
        );
        $output = "[\n".implode(",\n", $lines)."\n]\n";

        $outPath = $this->resolvePath((string) $this->option('out'));
        $outDir = dirname($outPath);
        if (! is_dir($outDir) && ! mkdir($outDir, 0775, true) && ! is_dir($outDir)) {
            $this->error("تعذر إنشاء مجلد المخرجات: {$outDir}");

            return self::FAILURE;
        }

        if (file_put_contents($outPath, $output, LOCK_EX) === false) {
            $this->error("تعذر كتابة الملف: {$outPath}");
            Log::error('chatbot:mapping فشلت كتابة الملف', ['path' => $outPath]);

            return self::FAILURE;
        }

        $sizeMb = round(strlen($output) / 1024 / 1024, 2);
        $this->info("تم توليد الملف بنجاح: {$id} دواء في {$outPath} ({$sizeMb} MB).");
        Log::info('chatbot:mapping انتهى', ['count' => $id, 'path' => $outPath, 'mb' => $sizeMb]);

        return self::SUCCESS;
    }

    /** المسار المطلق يُستخدم كما هو، والمسار النسبي يُبنى من جذر المشروع */
    private function resolvePath(string $path): string
    {
        $isAbsolute = preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || str_starts_with($path, '/') || str_starts_with($path, '\\');

        return $isAbsolute ? $path : base_path($path);
    }
}
