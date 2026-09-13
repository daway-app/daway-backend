<?php

namespace App\Support;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use Illuminate\Support\Facades\DB;

/**
 * مزامنة التصنيفات من database/data/moh_medicines_categorized.json
 * إلى category_medicine_links — idempotent وtransaction-safe.
 *
 * القرار المعماري:
 * - المعرّف المستقر هو moh_product_id / moh_drug_id (لا moh_medicines.id).
 * - الـJSON يُترجم عبر alias map إلى slugs الـDB الرسمية (الـCategorySeeder).
 * - links ذات source='admin' لا تُمسّ أبداً (إدخال يدوي من الأدمن).
 */
class MohCategorySync
{
    /**
     * خريطة ترجمة slugs الـJSON إلى slugs الـDB الرسمية.
     * الأقسام المتطابقة بين الملفين لا تحتاج إلى إدخال.
     */
    public const SLUG_ALIAS_MAP = [
        'mother-and-child' => 'mother-baby',
        'personal-care-and-beauty' => 'skin-care-beauty',
        'health-and-wellness' => 'health-safety',
        'vitamins-and-dietary-supplements' => 'vitamins-supplements',
        'herbal-products' => 'herbal',
    ];

    /**
     * القيم المسموح بها للحقل source — يجب أن تطابق constants الـCategoryMedicineLink.
     */
    private const VALID_SOURCES = [
        CategoryMedicineLink::SOURCE_PRODUCT_CLASS,
        CategoryMedicineLink::SOURCE_RULES,
        CategoryMedicineLink::SOURCE_ADMIN,
    ];

    /**
     * @return array{
     *   total_rows:int,
     *   total_links:int,
     *   by_slug:array<string,int>,
     *   by_source:array<string,int>,
     *   needs_review_links:int,
     *   needs_review_medicines:int,
     *   multi_category_medicines:int,
     *   unmatched_json_slugs:array<string,string>,
     *   missing_db_categories:array<string,string>,
     * }
     */
    public function plan(string $jsonPath): array
    {
        $rows = $this->loadJson($jsonPath);

        $bySlug = [];
        $bySource = [];
        $unmatched = [];
        $needsReviewLinks = 0;
        $needsReviewMedicines = 0;
        $multiCategory = 0;
        $totalLinks = 0;

        // نبني slug→id map من الـDB (مع المحذوف ناعماً كي لا نحذف أقسام محذوفة بالخطأ).
        $dbBySlug = Category::withTrashed()
            ->pluck('id', 'slug')
            ->all();

        foreach ($rows as $row) {
            $cats = $row['categories'] ?? [];
            if (count($cats) > 1) {
                $multiCategory++;
            }

            $rowHasReview = false;
            foreach ($cats as $cat) {
                $totalLinks++;
                $jsonSlug = (string) ($cat['slug'] ?? '');
                $dbSlug = self::SLUG_ALIAS_MAP[$jsonSlug] ?? $jsonSlug;

                $bySlug[$dbSlug] = ($bySlug[$dbSlug] ?? 0) + 1;
                $src = (string) ($cat['source'] ?? '');
                $bySource[$src] = ($bySource[$src] ?? 0) + 1;

                if (! array_key_exists($dbSlug, $dbBySlug)) {
                    $unmatched[$jsonSlug] = $cat['name_ar'] ?? '';
                }

                if (! empty($cat['needs_review'])) {
                    $needsReviewLinks++;
                    $rowHasReview = true;
                }
            }

            if ($rowHasReview) {
                $needsReviewMedicines++;
            }
        }

        ksort($bySlug);

        // جهّز قائمة unmatched: كل json slug يظهر فقط مرة واحدة في القائمة
        $missingDBCategories = [];
        $seen = [];
        foreach ($rows as $row) {
            foreach ($row['categories'] ?? [] as $cat) {
                $jsonSlug = (string) ($cat['slug'] ?? '');
                if (isset($seen[$jsonSlug])) {
                    continue;
                }
                $dbSlug = self::SLUG_ALIAS_MAP[$jsonSlug] ?? $jsonSlug;
                if (! array_key_exists($dbSlug, $dbBySlug)) {
                    $missingDBCategories[$jsonSlug] = $cat['name_ar'] ?? '';
                    $seen[$jsonSlug] = true;
                }
            }
        }

        return [
            'total_rows' => count($rows),
            'total_links' => $totalLinks,
            'by_slug' => $bySlug,
            'by_source' => $bySource,
            'needs_review_links' => $needsReviewLinks,
            'needs_review_medicines' => $needsReviewMedicines,
            'multi_category_medicines' => $multiCategory,
            'unmatched_json_slugs' => $missingDBCategories,
            'missing_db_categories' => $missingDBCategories,
        ];
    }

    /**
     * تنفيذ المزامنة الفعلية. يجب أن تُستدعى من الـcommand الذي يلفّها بـDB::transaction.
     *
     * @param  array{dry?:bool, fresh?:bool, chunk?:int}  $opts
     * @return array{
     *   created:int, updated:int, skipped_admin:int, unmatched_skipped:int,
     *   needs_review_links:int, fresh_deleted:int
     * }
     */
    public function execute(string $jsonPath, array $opts = []): array
    {
        $dry = (bool) ($opts['dry'] ?? false);
        $fresh = (bool) ($opts['fresh'] ?? false);
        $chunk = max(1, (int) ($opts['chunk'] ?? 500));

        $rows = $this->loadJson($jsonPath);

        // نبني slug→id map من الـDB.
        $dbBySlug = Category::withTrashed()->pluck('id', 'slug')->all();

        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped_admin' => 0,
            'unmatched_skipped' => 0,
            'needs_review_links' => 0,
            'fresh_deleted' => 0,
        ];

        // --fresh: حذف كل links غير-الأدمن
        if ($fresh && ! $dry) {
            $stats['fresh_deleted'] = CategoryMedicineLink::query()
                ->where('source', '!=', CategoryMedicineLink::SOURCE_ADMIN)
                ->delete();
        }

        // جهّز lookups لتفادي تكرار الاستعلامات
        $adminLinksKeyed = [];
        if (! $dry) {
            // نبني مفاتيح (category|moh_product|moh_drug) لكل link يدوي موجود كي نمنع الكتابة فوقه.
            // القيد الفريد الفعلي في الـDB يعتمد على category_id+moh_product_id (index 1)
            // وعلى category_id+moh_drug_id (index 2).
            $existing = CategoryMedicineLink::query()
                ->where('source', CategoryMedicineLink::SOURCE_ADMIN)
                ->get(['id', 'category_id', 'moh_product_id', 'moh_drug_id']);
            foreach ($existing as $row) {
                $adminLinksKeyed[$row->category_id.'|'.($row->moh_product_id ?? '0').'|'.($row->moh_drug_id ?? '0')] = true;
            }
        }

        $processed = 0;
        $batch = [];

        foreach ($rows as $row) {
            $processed++;
            $mohProductId = $row['moh_product_id'] ?? null;
            $mohDrugId = $row['moh_drug_id'] ?? null;

            foreach ($row['categories'] ?? [] as $cat) {
                $jsonSlug = (string) ($cat['slug'] ?? '');
                $dbSlug = self::SLUG_ALIAS_MAP[$jsonSlug] ?? $jsonSlug;

                if (! array_key_exists($dbSlug, $dbBySlug)) {
                    $stats['unmatched_skipped']++;
                    continue;
                }

                $categoryId = (int) $dbBySlug[$dbSlug];
                $source = (string) ($cat['source'] ?? '');
                if (! in_array($source, self::VALID_SOURCES, true)) {
                    $source = CategoryMedicineLink::SOURCE_RULES;
                }
                $confidence = (int) ($cat['confidence'] ?? 0);
                $needsReview = (bool) ($cat['needs_review'] ?? false);

                $key = $categoryId.'|'.((string) ($mohProductId ?? '0')).'|'.((string) ($mohDrugId ?? '0'));
                if (isset($adminLinksKeyed[$key])) {
                    $stats['skipped_admin']++;
                    if ($needsReview) {
                        $stats['needs_review_links']++;
                    }
                    continue;
                }

                if ($needsReview) {
                    $stats['needs_review_links']++;
                }

                if ($dry) {
                    continue;
                }

                $batch[] = [
                    'category_id' => $categoryId,
                    'moh_product_id' => $mohProductId,
                    'moh_drug_id' => $mohDrugId,
                    'medicine_id' => null,
                    'source' => $source,
                    'confidence' => $confidence,
                    'needs_review' => $needsReview,
                    '_json_slug' => $jsonSlug,
                    '_db_slug' => $dbSlug,
                ];
            }

            // تنفيذ الـbatch كل N صف
            if (count($batch) >= $chunk) {
                [$created, $updated] = $this->applyBatch($batch);
                $stats['created'] += $created;
                $stats['updated'] += $updated;
                $batch = [];
            }
        }

        // تفريغ ما تبقى
        if (! empty($batch)) {
            [$created, $updated] = $this->applyBatch($batch);
            $stats['created'] += $created;
            $stats['updated'] += $updated;
        }

        return $stats;
    }

    /**
     * تطبيق batch من links على الـDB بشكل idempotent.
     * يستخدم updateOrCreate لمنع التكرار، ويرجع عدد created/updated.
     *
     * @param  array<int,array<string,mixed>>  $batch
     * @return array{0:int,1:int}
     */
    private function applyBatch(array $batch): array
    {
        $created = 0;
        $updated = 0;

        foreach ($batch as $link) {
            // الحقول الصالحة للـupdateOrCreate (بدون _json_slug, _db_slug)
            $attrs = [
                'category_id' => $link['category_id'],
                'moh_product_id' => $link['moh_product_id'],
                'moh_drug_id' => $link['moh_drug_id'],
            ];
            $values = [
                'medicine_id' => $link['medicine_id'],
                'source' => $link['source'],
                'confidence' => $link['confidence'],
                'needs_review' => $link['needs_review'],
            ];

            $existing = CategoryMedicineLink::query()
                ->where('category_id', $attrs['category_id'])
                ->where('moh_product_id', $attrs['moh_product_id'])
                ->where('moh_drug_id', $attrs['moh_drug_id'])
                ->first();

            if ($existing) {
                $existing->fill($values);
                if ($existing->isDirty()) {
                    $existing->save();
                    $updated++;
                }
            } else {
                CategoryMedicineLink::create($attrs + $values);
                $created++;
            }
        }

        return [$created, $updated];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadJson(string $path): array
    {
        if (! file_exists($path)) {
            throw new \RuntimeException("ملف التصنيف غير موجود: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            throw new \RuntimeException("ملف التصنيف غير صالح JSON: {$path}");
        }

        return $data;
    }
}