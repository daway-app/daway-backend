<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Support\CategoryCatalogCache;
use Database\Seeders\CategorySeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * مزامنة التصنيف الجاهز من ملف كتالوج وزارة الصحة المصنّف إلى قاعدة البيانات.
 *
 * مصدر الحقيقة للتصنيف الآلي: database/data/moh_medicines_categorized.json
 * (يُولَّد مسبقاً — لا يقرؤه التطبيق في كل request، يُستخدم للـSYNC فقط).
 *
 * كل سجل في الملف يحمل categories[] بالشكل:
 *   { slug, name_ar, confidence, source, needs_review }
 * ويحمل المعرّفين المستقرين moh_product_id / moh_drug_id (كلاهما أو أحدهما).
 *
 * القواعد:
 *  - المطابقة مع أقسام قاعدة البيانات عبر slug مع ALIAS MAP — لا تُنشأ أقسام
 *    جديدة من الملف، ولا يُعتمد على id الملف الداخلي إطلاقاً (قد يختلف عن DB).
 *  - مفاتيح مستقرة فقط: ممنوع الإشارة إلى moh_medicines.id (import/sync
 *    يعمل delete-all + insert).
 *  - سجل يحمل المفتاحين معاً → صف رابط واحد بالعمودين (يُطابق من أي اتجاه).
 *  - Idempotent: التشغيل المتكرر لا ينتج duplicates ولا يغيّر النتيجة.
 *  - روابط source='admin' محمية: لا update ولا delete (قرار الأدمن يتغلب على
 *    التوصية الآلية). --fresh يحذف source != 'admin' فقط.
 *  - يدوي حالياً: لا يُربط تلقائياً بعد moh:sync — الـWorkflow اليدوي:
 *      moh:sync → moh:sync-categories --dry-run → مراجعة → moh:sync-categories
 *
 * classify:moh-catalog يبقى أداة بديلة/احتياطية ولا يُعدَّل — ملف الـJSON هو
 * مصدر الحقيقة للتصنيف الآلي، ونتائج classify لا تتغلب عليه.
 */
class SyncMohCategories extends Command
{
    /**
     * خرائط slugs الملف → slugs قاعدة البيانات (الفروقات المعروفة فقط).
     * Slugs المتطابقة تُستخدم مباشرة دون إدخالها هنا.
     */
    private const SLUG_ALIASES = [
        'personal-care-and-beauty' => 'skin-care-beauty',
        'mother-and-child' => 'mother-baby',
        'health-and-wellness' => 'health-safety',
        'vitamins-and-dietary-supplements' => 'vitamins-supplements',
        'herbal-products' => 'herbal',
    ];

    protected $signature = 'moh:sync-categories
        {--file=database/data/moh_medicines_categorized.json : ملف التصنيف المصدر}
        {--fresh : حذف الروابط الآلية (source != admin) قبل المزامنة}
        {--chunk=500 : حجم الدفعات عند المعالجة}
        {--dry-run : عرض الأعداد المتوقعة دون كتابة أي رابط}';

    protected $description = 'مزامنة التصنيف من ملف الكتالوج المصنّف إلى category_medicine_links (المصدر: ملف الـJSON — يدوي، idempotent، يحفظ روابط admin)';

    public function handle(): int
    {
        $path = base_path($this->option('file'));
        $dryRun = (bool) $this->option('dry-run');
        $fresh = (bool) $this->option('fresh');
        $chunkSize = max(1, (int) $this->option('chunk'));

        if (! is_file($path)) {
            $this->error("ملف التصنيف غير موجود: {$path}");

            return self::FAILURE;
        }

        // التأكد من وجود الأقسام (نفس الـCategorySeeder) — مطلوب لمطابقة الـslugs
        if (! Category::count()) {
            $this->call('db:seed', ['--class' => CategorySeeder::class, '--force' => true]);
        }

        try {
            $rows = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->error('فشل قراءة ملف التصنيف: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! is_array($rows) || $rows === []) {
            $this->error('ملف التصنيف فارغ أو بصيغة غير صحيحة');

            return self::FAILURE;
        }

        // withTrashed: قسم moh-medicine slug ناعم الحذف يُستعاد تلقائياً
        // (المواصفة: soft-deleted → restore بدل إنشاء duplicate)
        $categoriesBySlug = Category::withTrashed()->get(['id', 'slug', 'deleted_at'])
            ->keyBy(fn ($c) => $c->slug);

        $resolveCategory = function (string $dbSlug) use ($categoriesBySlug) {
            $category = $categoriesBySlug->get($dbSlug);
            if ($category === null) {
                return null;
            }
            if ($category->trashed()) {
                $category->restore();
            }

            return $category;
        };

        $counters = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped_admin' => 0,
            'unclassified' => 0,
            'unknown_slug' => 0,
            'no_stable_keys' => 0,
            'needs_review' => 0,
        ];
        $perCategory = [];
        $unknownSlugs = [];

        if ($fresh) {
            $deleted = CategoryMedicineLink::query()
                ->where('source', '!=', CategoryMedicineLink::SOURCE_ADMIN)
                ->delete();
            $adminPreserved = CategoryMedicineLink::query()
                ->where('source', CategoryMedicineLink::SOURCE_ADMIN)->count();
            $this->warn("تم حذف {$deleted} رابطاً آلياً (محافظاً على {$adminPreserved} رابطاً يدوياً admin).");
        }

        if ($dryRun) {
            $this->info('[Dry Run] لن تُكتب أي روابط — الأعداد أدناه متوقعة فقط.');
        }

        $total = count($rows);
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        // all-or-nothing: فشل أي صف (خطأ DB مثلاً) يلغي المزامنة كاملة —
        // لا يُترك الكتالوج بحالة نصف مُزامنة، وإعادة التشغيل idempotent.
        // ملاحظة: يجب تمرير resolveCategory (Closure) صراحةً إلى الـtransaction.
        DB::transaction(function () use ($rows, $chunkSize, $bar, &$counters, &$perCategory, &$unknownSlugs, $dryRun, $categoriesBySlug, $resolveCategory) {
        foreach (array_chunk($rows, $chunkSize) as $batch) {
            foreach ($batch as $row) {
                $counters['processed']++;
                $bar->advance();

                $mohProductId = isset($row['moh_product_id']) && $row['moh_product_id'] !== null
                    ? (int) $row['moh_product_id'] : null;
                $mohDrugId = isset($row['moh_drug_id']) && $row['moh_drug_id'] !== null
                    ? (int) $row['moh_drug_id'] : null;

                if ($mohProductId === null && $mohDrugId === null) {
                    $counters['no_stable_keys']++;
                    continue;
                }

                $recordCategories = is_array($row['categories'] ?? null) ? $row['categories'] : [];
                if ($recordCategories === []) {
                    $counters['unclassified']++;
                    continue;
                }

                foreach ($recordCategories as $cat) {
                    $fileSlug = trim((string) ($cat['slug'] ?? ''));
                    if ($fileSlug === '') {
                        continue;
                    }

                    $dbSlug = self::SLUG_ALIASES[$fileSlug] ?? $fileSlug;
                    $category = $resolveCategory($dbSlug);
                    if ($category === null) {
                        $counters['unknown_slug']++;
                        $unknownSlugs[$fileSlug] = ($unknownSlugs[$fileSlug] ?? 0) + 1;
                        continue;
                    }

                    $source = (string) ($cat['source'] ?? 'rules');
                    if (! in_array($source, [
                        CategoryMedicineLink::SOURCE_ADMIN,
                        CategoryMedicineLink::SOURCE_PRODUCT_CLASS,
                        CategoryMedicineLink::SOURCE_RULES,
                        'combined_rules',
                        'dosage_form',
                    ], true)) {
                        $source = CategoryMedicineLink::SOURCE_RULES;
                    }

                    $confidence = isset($cat['confidence']) && is_numeric($cat['confidence'])
                        ? max(0, min(100, (int) $cat['confidence']))
                        : null;
                    $needsReview = (bool) ($cat['needs_review'] ?? false);

                    if ($needsReview) {
                        $counters['needs_review']++;
                    }

                    $perCategory[$dbSlug] = ($perCategory[$dbSlug] ?? 0) + 1;

                    if ($dryRun) {
                        $counters['created']++;

                        continue;
                    }

                    $status = $this->upsertLink(
                        $category->id,
                        $mohProductId,
                        $mohDrugId,
                        $source,
                        $confidence,
                        $needsReview
                    );

                    if ($status === 'admin') {
                        $counters['skipped_admin']++;
                    } else {
                        $counters[$status]++;
                    }
                }
            }
        }
        }); // DB::transaction — نهاية الكتلة الذرية

        $bar->finish();
        $this->newLine(2);

        // إبطال كاش الكتالوج — الأدمن والـAPI يرون التغيير فوراً
        if (! $dryRun) {
            CategoryCatalogCache::bump();
        }

        $summary = collect($perCategory)
            ->sortKeys()
            ->map(fn ($count, $slug) => [$slug, $count])
            ->values()->all();

        $this->table(['القسم', 'الروابط'], $summary);

        $this->info('إجمالي السجلات: '.$counters['processed']." (بلا مفاتيح مستقرة: {$counters['no_stable_keys']})");
        $this->info('روابط منشأة: '.$counters['created']);
        $this->info('روابط محدثة: '.$counters['updated']);
        $this->info('تخطي لصالح روابط admin: '.$counters['skipped_admin']);
        $this->info('سجلات غير مصنّفة (في الملف): '.$counters['unclassified']);
        $this->info('needs_review: '.$counters['needs_review']);

        if ($unknownSlugs !== []) {
            $this->warn('Slugs غير معروفة (تُخطّت): '.json_encode($unknownSlugs, JSON_UNESCAPED_UNICODE));
        }

        Log::info('moh_sync_categories', $counters + [
            'dry_run' => $dryRun,
            'fresh' => $fresh,
            'file' => $this->option('file'),
        ]);

        return self::SUCCESS;
    }

    /**
     * upsert رابط واحد ضمن category — نفس منطق de-dup المجرب في
     * ClassifyMohCatalog: صف واحد يحمل كلا المفتاحين إن توفّرا،
     * ويُحافظ على روابط admin.
     */
    private function upsertLink(
        int $categoryId,
        ?int $mohProductId,
        ?int $mohDrugId,
        string $source,
        ?int $confidence,
        bool $needsReview
    ): string {
        $keyMatch = function ($q) use ($mohProductId, $mohDrugId) {
            if ($mohProductId !== null) {
                $q->where('moh_product_id', $mohProductId);
            }
            if ($mohDrugId !== null) {
                $q->orWhere('moh_drug_id', $mohDrugId);
            }
        };

        // 1) قرار الأدمن محمي — أي رابط admin بنفس القسم/المفاتيح يمنع الكتابة
        $adminExists = CategoryMedicineLink::query()
            ->where('category_id', $categoryId)
            ->where('source', CategoryMedicineLink::SOURCE_ADMIN)
            ->where($keyMatch)
            ->exists();
        if ($adminExists) {
            return 'admin';
        }

        // 2) ابحث عن رابط آلي مطابق (باتجاه أي مفتاح)
        $existing = CategoryMedicineLink::query()
            ->where('category_id', $categoryId)
            ->where('source', '!=', CategoryMedicineLink::SOURCE_ADMIN)
            ->where($keyMatch)
            ->orderBy('id')
            ->first();

        if ($existing) {
            // اضبط كلا المفتاحين — إعادة المطابقة تعمل عبر أي اتجاه بعد re-import
            $existing->update([
                'moh_product_id' => $mohProductId ?? $existing->moh_product_id,
                'moh_drug_id' => $mohDrugId ?? $existing->moh_drug_id,
                'source' => $source,
                'confidence' => $confidence,
                'needs_review' => $needsReview,
            ]);

            // 3) نظّف تكرارات آلية أخرى بنفس المفاتيح (تركتها تشغيلات قديمة)،
            //    مع الحفاظ على روابط admin دائماً
            CategoryMedicineLink::query()
                ->where('category_id', $categoryId)
                ->where('id', '!=', $existing->id)
                ->where('source', '!=', CategoryMedicineLink::SOURCE_ADMIN)
                ->where($keyMatch)
                ->delete();

            return 'updated';
        }

        CategoryMedicineLink::create([
            'category_id' => $categoryId,
            'moh_product_id' => $mohProductId,
            'moh_drug_id' => $mohDrugId,
            'medicine_id' => null,
            'source' => $source,
            'confidence' => $confidence,
            'needs_review' => $needsReview,
        ]);

        return 'created';
    }
}
