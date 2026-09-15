<?php

namespace Tests\Feature\Api\Patient;

use App\Services\Ai\MedicineResolver;
use Tests\TestCase;

/**
 * إثبات تكافؤ البوّابة (haystackMayMatch) مع المطابق الكامل (recordMatchesNeedle).
 *
 * السبب: lookupMapping() (المسار المفرد) يشغّل المطابق الكامل على كل سجل من
 * ~17k بلا بوّابة → 0.7–4.1 ثانية لكل استعلام جديد. lookupMappingBatch()
 * يستخدم البوّابة قبله. قبل تطبيق البوّابة على المسار المفرد، لازم نثبت
 * (بالداتا الحقيقية) إن البوّابة superset آمنة: لا نتيجة يفوتها المسار المفرد.
 *
 * الفحص: لكل استعلام حقيقي، قارن نتائج lookupMapping بالنتائج المحسوبة
 * يدوياً بمطابق كامل بلا بوّابة. لازم يتطابقوا تماماً.
 *
 * ⚠️ بطيء بطبيعته (~70 ثانية: مسحان كاملان لملف 13MB × 14 استعلاماً).
 * يُشغَّل بالاسم صراحةً عند تغيير منطق المطابقة:
 *   php vendor/bin/phpunit tests/Feature/Api/Patient/MappingGateEquivalenceTest.php
 * مستثنى من الحزمة الكاملة عبر group('slow-equivalence').
 *
 * @group slow-equivalence
 */
final class MappingGateEquivalenceTest extends TestCase
{
    /** استعلامات حقيقية متنوعة: عربي، إنجليزي، ملزوق، جرعة، غير موجود. */
    private function queries(): array
    {
        return [
            'بنادول',
            'بانادول',
            'اوجمنتين',
            'فولتارين',
            'اكتيفيد',
            'أموكسيسيلين',
            'amoxil',
            'panadol',
            'AUGMENTIN',
            'فيتامين د',
            'شراب كحة',
            'زززنز',
            'تو',
            'حبوب الصداع',
        ];
    }

    public function test_single_path_results_match_ungated_full_matcher(): void
    {
        $resolver = app(MedicineResolver::class);

        foreach ($this->queries() as $query) {
            // 1) النتيجة المُنتَجة عبر المسار العام (مع البوّابة بعد الإصلاح)
            $withGate = $resolver->lookupMapping($query, 10);

            // 2) النتيجة المرجعية: بلا البوّابة إطلاقاً
            $withoutGate = $this->bruteForceLookup($resolver, $query, 10);

            $this->assertSame(
                $withoutGate,
                $withGate,
                "المسار المفرد اختلف عن المطابق الكامل بلا بوّابة للاستعلام: {$query}"
            );
        }
    }

    public function test_batch_path_agrees_with_single_path_for_same_queries(): void
    {
        $resolver = app(MedicineResolver::class);

        $batch = $resolver->lookupMappingBatch($this->queries(), 10);

        foreach ($this->queries() as $query) {
            $needle = MedicineResolver::normalizeArabic($query);
            $single = $resolver->lookupMapping($query, 10);

            $this->assertSame(
                $batch[$needle] ?? [],
                $single,
                "مسار الدفعة ومسار المفرد اختلفا للاستعلام: {$query}"
            );
        }
    }

    /**
     * تنفيذ مرجعي: مسح كل سجل وتشغيل المطابق الكامل بلا أي بوّابة.
     * نستخدم Reflection لأن المطابق private — الهدف قياس سلوكه نفسه لا نسخه.
     */
    private function bruteForceLookup(MedicineResolver $resolver, string $query, int $limit): array
    {
        $needle = MedicineResolver::normalizeArabic($query);

        if (mb_strlen($needle) < 2) {
            return [];
        }

        $needleSkel = $this->callPrivate($resolver, 'skeletonOf', [$needle]);
        $matches = $this->callPrivate($resolver, 'recordMatchesNeedle');

        $path = base_path('database/data/chatbot_medicines.json');

        if (! is_file($path)) {
            $this->markTestSkipped('ملف الـ mapping غير موجود');
        }

        $hits = [];
        $handle = fopen($path, 'r');

        while (($line = fgets($handle)) !== false && count($hits) < $limit) {
            $line = trim($line, " \t\r\n,");

            if ($line === '' || $line === '[' || $line === ']') {
                continue;
            }

            $record = json_decode($line, true);

            if (! is_array($record)) {
                continue;
            }

            $records = array_is_list($record) ? $record : [$record];

            foreach ($records as $r) {
                if (! is_array($r) || count($hits) >= $limit) {
                    continue;
                }

                // بلا بوّابة — المطابق الكامل مباشرة
                if ($matches($r, $needle, $needleSkel)) {
                    $hits[] = [
                        'moh_product_id' => isset($r['moh_product_id']) ? (int) $r['moh_product_id'] : null,
                        'moh_drug_id' => isset($r['moh_drug_id']) ? (int) $r['moh_drug_id'] : null,
                        'name_en' => (string) ($r['name_en'] ?? ''),
                        'name_ar' => isset($r['name_ar']) ? (string) $r['name_ar'] : null,
                    ];
                }
            }
        }

        fclose($handle);

        return $hits;
    }

    /** @param  array<int, mixed>  $args */
    private function callPrivate(object $object, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($object, $method);
        $ref->setAccessible(true);

        if ($method === 'recordMatchesNeedle') {
            return function (array $record, string $needle, string $needleSkel) use ($ref, $object) {
                return $ref->invoke($object, $record, $needle, $needleSkel);
            };
        }

        return $ref->invokeArgs($object, $args);
    }
}
