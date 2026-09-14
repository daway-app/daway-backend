<?php

namespace App\Services\InventoryImport;

use App\Models\InventoryImport;
use App\Services\Ai\MedicineResolver;

/**
 * يحوّل صفوف الملف الخام إلى صفوف مُحلَّلة بحالة حتمية.
 *
 * قواعد التصميم:
 *  - لا يوجد "رقم ثقة" مُختلق: الحالة ناتجة عن قاعدة صريحة، لا عن score.
 *  - لا يُنشئ هذا الصنف أي دواء — أقصى ما يفعله هو اقتراح.
 *  - مطابقة fuzzy لا تكفي وحدها أبداً: نتيجتها "بانتظار قرار".
 *  - كل العمل في الذاكرة عبر InventoryLookupIndex — لا استعلامات داخل الحلقة.
 */
final class InventoryRowResolver
{
    public function __construct(
        private readonly InventoryLookupIndex $index,
        private readonly MedicineResolver $mapping,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  صفوف بعد التحقق البنيوي
     * @return array<int, array<string, mixed>>  صفوف مُحلَّلة
     */
    public function resolve(array $rows): array
    {
        $resolved = [];
        $fuzzyNames = [];

        foreach ($rows as $index => $row) {
            $resolved[$index] = $this->resolveOne($row);

            if ($resolved[$index]['status'] === InventoryImport::ROW_UNMATCHED) {
                $fuzzyNames[$index] = (string) ($row['input']['trade_name'] ?? '');
            }
        }

        if ($fuzzyNames !== []) {
            $resolved = $this->applyFuzzy($resolved, $fuzzyNames);
        }

        return $this->markDuplicates($resolved);
    }

    /**
     * سلّم المطابقة الحتمي — أول قاعدة تنجح تفوز.
     *
     *  1. مطابقة تامة بالاسم الإنجليزي في الكتالوج المحلي   → EXACT_EN
     *  2. مطابقة تامة بالاسم العربي بعد التطبيع             → EXACT_AR_NORMALIZED
     *  3. مرادف مؤكَّد سابقاً لهذه الصيدلية                  → ALIAS_MATCH
     *  4. مطابقة تامة في كتالوج وزارة الصحة                 → MOH_MATCH (تحتاج تأكيداً)
     *  5. مطابقة تقريبية                                    → FUZZY_MATCH / REVIEW_REQUIRED
     *  6. لا شيء                                            → UNMATCHED
     *
     * ملاحظة على الترتيب: المطابقة التامة (1 و2) تتقدّم على المرادف (3)
     * لأنها إشارة موضوعية لا تعتمد على قرار سابق — نتجنّب تجاوزاً مفاجئاً
     * لدواء موجود فعلاً. المرادف يعالج ما لم يطابق شيئاً تلقائياً.
     */
    private function resolveOne(array $row): array
    {
        $input = (array) ($row['input'] ?? []);

        $base = [
            'row' => (int) ($row['row'] ?? 0),
            'input' => $input,
            'status' => InventoryImport::ROW_UNMATCHED,
            'match_method' => null,
            'medicine_id' => null,
            'proposed_moh_id' => null,
            'resolved_name' => null,
            'resolved_name_ar' => null,
            'official_price' => null,
            'suggestions' => [],
            'warnings' => (array) ($row['warnings'] ?? []),
            'errors' => (array) ($row['errors'] ?? []),
            'decision' => InventoryImport::DECISION_PENDING,
            'duplicate_group' => null,
        ];

        // صف غير صالح بنيوياً — لا يُحلّل أصلاً
        if ($base['errors'] !== []) {
            $base['status'] = InventoryImport::ROW_INVALID;
            $base['decision'] = InventoryImport::DECISION_SKIP;

            return $base;
        }

        $en = $input['trade_name'] ?? null;
        $ar = $input['trade_name_ar'] ?? null;

        // 1) الاسم الإنجليزي
        if ($medicine = $this->index->findByEnglishName($en)) {
            return $this->linkTo($base, $medicine, InventoryImport::ROW_EXACT_EN, 'exact_en');
        }

        // 2) الاسم العربي بعد التطبيع
        if ($medicine = $this->index->findByArabicName($ar)) {
            return $this->linkTo($base, $medicine, InventoryImport::ROW_EXACT_AR, 'exact_ar');
        }

        // 3) مرادف مؤكَّد سابقاً لهذه الصيدلية
        if ($medicine = $this->index->findByAlias($en)) {
            return $this->linkTo($base, $medicine, InventoryImport::ROW_ALIAS, 'alias');
        }

        // 4) كتالوج وزارة الصحة — الدواء موجود رسمياً لكنه ليس في الكتالوج المحلي بعد.
        //    إنشاؤه يتطلّب تأكيداً صريحاً (لا نلوّث الكتالوج تلقائياً).
        if ($moh = $this->index->findMohByEnglishName($en)) {
            $base['status'] = InventoryImport::ROW_MOH;
            $base['match_method'] = 'moh';
            $base['proposed_moh_id'] = $moh['id'];
            $base['resolved_name'] = $moh['trade_name'];
            $base['official_price'] = $moh['official_price'];
            $base['decision'] = InventoryImport::DECISION_PENDING;
            $base['suggestions'] = [$this->mohSuggestion($moh)];

            return $this->withPriceWarning($base);
        }

        // 5 و6) مؤجّلان لمسار الـ fuzzy المجمّع
        return $base;
    }

    /**
     * مطابقة fuzzy على دفعة واحدة — مسح واحد لملف الـ mapping.
     *
     * @param  array<int, array<string, mixed>>  $resolved
     * @param  array<int, string>  $names  أرقام الصفوف غير المطابقة => الاسم
     */
    private function applyFuzzy(array $resolved, array $names): array
    {
        $maxQueries = (int) config('inventory_import.fuzzy_max_queries', 2000);
        $limit = (int) config('inventory_import.suggestions_limit', 3);

        // حماية: ملف كله غير مطابق لا يستحق مسحاً مكلفاً بلا فائدة
        if (count($names) > $maxQueries) {
            return $resolved;
        }

        $hits = $this->mapping->lookupMappingBatch(array_values($names), $limit);

        foreach ($names as $index => $name) {
            $key = InventoryLookupIndex::normalizeAr($name);
            $bucket = $hits[$key] ?? [];

            if ($bucket === []) {
                continue;
            }

            $suggestions = $this->suggestionsFromHits($bucket, $limit);

            if ($suggestions === []) {
                continue;
            }

            $distinct = collect($suggestions)
                ->map(fn (array $s) => $s['medicine_id'] !== null ? 'm:'.$s['medicine_id'] : 'moh:'.$s['moh_id'])
                ->unique()
                ->count();

            $resolved[$index]['suggestions'] = $suggestions;
            $resolved[$index]['status'] = $distinct > 1
                ? InventoryImport::ROW_REVIEW
                : InventoryImport::ROW_FUZZY;

            $resolved[$index]['match_method'] = 'fuzzy';

            if ($distinct === 1) {
                $top = $suggestions[0];
                $resolved[$index]['resolved_name'] = $top['name'];
                $resolved[$index]['resolved_name_ar'] = $top['name_ar'];
                $resolved[$index]['official_price'] = $top['official_price'];
            }

            $resolved[$index]['decision'] = InventoryImport::DECISION_PENDING;
        }

        return $resolved;
    }

    /**
     * ترجمة نتائج الـ mapping إلى اقتراحات حقيقية من الكتالوج المحلي أو الوزاري.
     *
     * @param  array<int, array<string, mixed>>  $hits
     * @return array<int, array<string, mixed>>
     */
    private function suggestionsFromHits(array $hits, int $limit): array
    {
        $suggestions = [];
        $seen = [];

        foreach ($hits as $hit) {
            // (أ) دواء موجود فعلاً في الكتالوج المحلي بنفس الاسم الإنجليزي
            $medicine = $this->index->findByEnglishName($hit['name_en'] ?? null);

            if ($medicine) {
                $key = 'm:'.$medicine['id'];

                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $suggestions[] = [
                        'medicine_id' => $medicine['id'],
                        'moh_id' => null,
                        'name' => $medicine['trade_name'],
                        'name_ar' => $medicine['trade_name_ar'],
                        'active_ingredient' => $medicine['active_ingredient'],
                        'official_price' => null,
                        'source' => 'catalog',
                    ];
                }

                continue;
            }

            // (ب) عنصر في كتالوج الوزارة (يتطلّب إنشاء دواء — قرار صريح)
            $moh = $this->index->findMohByIds($hit['moh_product_id'] ?? null, $hit['moh_drug_id'] ?? null)
                ?? $this->index->findMohByEnglishName($hit['name_en'] ?? null);

            if ($moh) {
                $key = 'moh:'.$moh['id'];

                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $suggestions[] = $this->mohSuggestion($moh);
                }
            }

            if (count($suggestions) >= $limit) {
                break;
            }
        }

        return array_slice($suggestions, 0, $limit);
    }

    /**
     * @param  array{id:int, trade_name:string, generic_name:?string, manufacturer:?string, official_price:?float}  $moh
     * @return array<string, mixed>
     */
    private function mohSuggestion(array $moh): array
    {
        return [
            'medicine_id' => null,
            'moh_id' => $moh['id'],
            'name' => $moh['trade_name'],
            'name_ar' => null,
            'active_ingredient' => $moh['generic_name'],
            'official_price' => $moh['official_price'],
            'source' => 'moh',
        ];
    }

    /**
     * @param  array{id:int, trade_name:string, trade_name_ar:?string, active_ingredient:?string}  $medicine
     */
    private function linkTo(array $base, array $medicine, string $status, string $method): array
    {
        $base['status'] = $status;
        $base['match_method'] = $method;
        $base['medicine_id'] = $medicine['id'];
        $base['resolved_name'] = $medicine['trade_name'];
        $base['resolved_name_ar'] = $medicine['trade_name_ar'];
        $base['decision'] = InventoryImport::DECISION_LINK;

        return $this->withPriceWarning($base);
    }

    /**
     * تحذير (لا خطأ) عند اختلاف سعر الصيدلية عن السعر الرسمي اختلافاً كبيراً.
     * لا يمنع الاستيراد — السعر سعر الصيدلية دائماً.
     */
    private function withPriceWarning(array $row): array
    {
        $official = $row['official_price'];
        $price = $row['input']['price'] ?? null;

        if ($official === null || $official <= 0 || $price === null) {
            return $row;
        }

        $deviation = abs((float) $price - (float) $official) / (float) $official;

        if ($deviation > 0.5) {
            $row['warnings'][] = 'price_deviation';
        }

        return $row;
    }

    /**
     * كشف التكرار: صفوف تشير إلى نفس الدواء (أو نفس الاسم) داخل الملف.
     *
     * لا نجمع الكميات ولا نقرّر شيئاً — نوسم المجموعة فقط، والقرار للصيدلي.
     * السبب: الكمية هنا مطلقة (absolute)، فجمع صفين لا معنى له أصلاً.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function markDuplicates(array $rows): array
    {
        $groups = [];

        foreach ($rows as $index => $row) {
            $key = $this->dedupeKey($row);

            if ($key === null) {
                continue;
            }

            $groups[$key][] = $index;
        }

        foreach ($groups as $key => $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            foreach ($indexes as $index) {
                // نحفظ طريقة المطابقة الأصلية، ونغيّر الحالة فقط لتُعرض كتكرار
                $rows[$index]['match_method'] = $rows[$index]['match_method'] ?? 'duplicate';
                $rows[$index]['status'] = InventoryImport::ROW_DUPLICATE;
                $rows[$index]['duplicate_group'] = $key;
                $rows[$index]['decision'] = InventoryImport::DECISION_PENDING;
            }
        }

        return $rows;
    }

    /** مفتاح التكرار: الدواء المحلي، وإلا اسم الوزارة، وإلا الاسم المُدخل نفسه. */
    private function dedupeKey(array $row): ?string
    {
        if (($row['errors'] ?? []) !== []) {
            return null;
        }

        if ($row['medicine_id'] !== null) {
            return 'm:'.$row['medicine_id'];
        }

        if ($row['proposed_moh_id'] !== null) {
            return 'moh:'.$row['proposed_moh_id'];
        }

        $en = InventoryLookupIndex::normalizeEn($row['input']['trade_name'] ?? null);

        return $en === '' ? null : 'in:'.$en;
    }
}
