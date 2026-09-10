<?php

namespace App\Console\Commands;

use App\Models\Pharmacy;
use Illuminate\Console\Command;

/**
 * H-6: إعادة بناء avg_rating لكل الصيدليات من التقييمات الفعلية.
 * الاستخدام: بعد استيراد بيانات، تصحيح انحراف، أو تهيئة قاعدة جديدة.
 */
class RebuildRatingsAverages extends Command
{
    protected $signature = 'ratings:rebuild {--pharmacy= : معرّف صيدلية محدد (اختياري)}';

    protected $description = 'إعادة حساب avg_rating لكل الصيدليات (أو صيدلية محددة) من جدول التقييمات';

    public function handle(): int
    {
        $query = Pharmacy::query()->withCount('ratings');

        if ($id = $this->option('pharmacy')) {
            $query->where('id', $id);
        }

        $pharmacies = $query->get();
        $bar = $this->output->createProgressBar($pharmacies->count());
        $updated = 0;

        foreach ($pharmacies as $pharmacy) {
            $avg = $pharmacy->ratings()->avg('stars_rating');

            $pharmacy->forceFill([
                'avg_rating' => $avg !== null ? round((float) $avg, 2) : 0.00,
            ])->save();

            $updated++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("تم تحديث {$updated} صيدلية.");

        return self::SUCCESS;
    }
}
