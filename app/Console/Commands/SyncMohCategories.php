<?php

namespace App\Console\Commands;

use App\Support\CategoryCatalogCache;
use App\Support\MohCategorySync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * مزامنة التصنيفات من database/data/moh_medicines_categorized.json
 * إلى جدول category_medicine_links.
 *
 * idempotent — يمكن إعادة تشغيله بأمان. الـlinks اليدوية (source='admin')
 * لا تُمسّ أبداً. استخدم --fresh لإعادة بناء كل التصنيفات غير-الإدارية.
 */
class SyncMohCategories extends Command
{
    protected $signature = 'moh:sync-categories
        {--source= : مسار ملف الـJSON (افتراضي database/data/moh_medicines_categorized.json)}
        {--fresh : احذف كل الروابط غير-الأدمن قبل الإدراج}
        {--dry-run : اعرض خطة التنفيذ بدون كتابة على الـDB}
        {--chunk=500 : حجم الدفعة الواحدة}
        {--print-plan : اطبع خريطة الـslugs والإحصائيات المتوقعة بدون تنفيذ}';

    protected $description = 'مزامنة التصنيفات من ملف MOH المُصنَّف إلى جدول category_medicine_links';

    public function handle(MohCategorySync $sync): int
    {
        $path = (string) ($this->option('source') ?: base_path('database/data/moh_medicines_categorized.json'));
        $dry = (bool) $this->option('dry-run');
        $fresh = (bool) $this->option('fresh');
        $printPlan = (bool) $this->option('print-plan');
        $chunk = (int) $this->option('chunk');

        if (! file_exists($path)) {
            $this->error("ملف التصنيف غير موجود: {$path}");
            return self::FAILURE;
        }

        if ($printPlan || $dry) {
            $this->info('— خطة المزامنة —');
            $plan = $sync->plan($path);

            $this->table(
                ['المقياس', 'القيمة'],
                [
                    ['عدد الأدوية في الـJSON', number_format($plan['total_rows'])],
                    ['إجمالي الروابط المتوقعة', number_format($plan['total_links'])],
                    ['أدوية متعددة الأقسام', number_format($plan['multi_category_medicines'])],
                    ['روابط needs_review', number_format($plan['needs_review_links'])],
                    ['أدوية needs_review', number_format($plan['needs_review_medicines'])],
                ],
            );

            $this->info('— توزيع حسب القسم (الـDB slug) —');
            $rows = [];
            foreach ($plan['by_slug'] as $slug => $count) {
                $rows[] = [$slug, number_format($count)];
            }
            $this->table(['slug', 'links'], $rows);

            $this->info('— توزيع حسب source —');
            $rows = [];
            foreach ($plan['by_source'] as $src => $count) {
                $rows[] = [$src, number_format($count)];
            }
            $this->table(['source', 'count'], $rows);

            if (! empty($plan['unmatched_json_slugs'])) {
                $this->warn('— JSON slugs بلا مقابل في الـDB (سيتم تجاهلها) —');
                foreach ($plan['unmatched_json_slugs'] as $slug => $name) {
                    $this->line("  • {$slug} ({$name})");
                }
            } else {
                $this->info('كل JSON slugs لها مقابل في الـDB ✓');
            }

            if ($dry) {
                $this->info('--dry-run: لن تتم أي كتابة.');
                return self::SUCCESS;
            }
        }

        // تنفيذ فعلي — ملفوف بـ transaction.
        $this->info("— بدء المزامنة (fresh=".($fresh ? 'yes' : 'no').", chunk={$chunk}) —");

        $stats = DB::transaction(fn () => $sync->execute($path, [
            'fresh' => $fresh,
            'dry' => false,
            'chunk' => $chunk,
        ]));

        $this->table(
            ['المقياس', 'القيمة'],
            [
                ['روابط أُنشئت', number_format($stats['created'])],
                ['روابط حُدِّثت', number_format($stats['updated'])],
                ['admin links محفوظة', number_format($stats['skipped_admin'])],
                ['JSON slugs بلا مقابل (تم تجاهلها)', number_format($stats['unmatched_skipped'])],
                ['fresh: روابط محذوفة (غير-أدمن)', number_format($stats['fresh_deleted'])],
                ['روابط needs_review', number_format($stats['needs_review_links'])],
            ],
        );

        // bump cache version لتُجبر الـMobile API على إعادة الجلب.
        CategoryCatalogCache::bump();
        $this->info('Cache version bumped.');

        return self::SUCCESS;
    }
}