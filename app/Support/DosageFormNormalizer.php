<?php

namespace App\Support;

/**
 * توحيد أشكال الجرعات (dosage_form) في كتالوج وزارة الصحة.
 *
 * العمود moh_medicines.dosage_form نص إنجليزي حر وغير موحّد
 * (مثال من بيانات حقيقية: 'tablet'، 'film coated tablet'، 'Film-coated tablets'،
 * 'Solution for injection (S.C)'، 'Soft gelatin capsule') — وأكثر من 13,700 صف من
 * أصل 17,295 قيمته null (منتجات تجميل/مستلزمات بلا شكل جرعي).
 *
 * الاستراتيجية: كل اسم عربي قياسي يقابله مجموعة tokens إنجليزية (substring،
 * غير حساسة لحالة الأحرف في MySQL ci / sqlite) تُطبَّق بـ OR'd LIKE،
 * وقائمة exclude اختيارية لمنع التطابقات الخاطئة (مثال: 'gelatin' يحوي 'gel').
 * ما لا يطابق أي canonical يُعامل كـ unknown (null).
 */
class DosageFormNormalizer
{
    /**
     * الترتيب مهم لـ normalize(): الأكثر تحديداً أولاً
     * (مثال: 'Solution for injection' → حقن وليس محلول،
     *  'soft gelatin capsule' → كبسولات وليس جل).
     *
     * @var array<string, array{tokens: list<string>, exclude?: list<string>}>
     */
    private const MAP = [
        'حبوب' => ['tokens' => ['tab', 'caplet']],
        'كبسولات' => ['tokens' => ['capsule', 'cpsule']],
        'شراب' => ['tokens' => ['syrup']],
        'كريم' => ['tokens' => ['cream']],
        'مرهم' => ['tokens' => ['ointment']],
        'جل' => ['tokens' => ['gel'], 'exclude' => ['gelatin', 'capsule']],
        'قطرات' => ['tokens' => ['drop']],
        'بخاخ' => ['tokens' => ['spray', 'spary', 'inhalation']],
        'حقن' => ['tokens' => ['injection', 'injectable', 'infusion', 'syringe', 'ampoule', 'vial', 'parentral']],
        'تحاميل' => ['tokens' => ['suppositor', 'pessar', 'ovul']],
        'لصقات' => ['tokens' => ['patch']],
        'غسول' => ['tokens' => ['wash', 'gargle']],
        'بودرة' => ['tokens' => ['powder', 'granul']],
        'معلق' => ['tokens' => ['suspension']],
        'محلول' => ['tokens' => ['solution']],
    ];

    /**
     * قيمة من عمود dosage_form (نص إنجليزي حر) → الاسم القياسي العربي أو null.
     */
    public static function normalize(?string $dosageForm): ?string
    {
        if ($dosageForm === null) {
            return null;
        }

        $haystack = mb_strtolower($dosageForm);

        foreach (self::MAP as $canonical => $spec) {
            foreach ($spec['exclude'] ?? [] as $excluded) {
                if (str_contains($haystack, $excluded)) {
                    continue 2;
                }
            }

            foreach ($spec['tokens'] as $token) {
                if (str_contains($haystack, $token)) {
                    return $canonical;
                }
            }
        }

        return null;
    }

    /**
     * قيمة الفلتر القادمة من المستخدم: إما اسم قياسي عربي (من facets())
     * أو نص إنجليزي حر يُطبَّع — ما لا يُعرَف يعود null (تُتجاهل الفلترة بصمت).
     */
    public static function forInput(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $value = trim($input);
        if ($value === '') {
            return null;
        }

        if (isset(self::MAP[$value])) {
            return $value;
        }

        return self::normalize($value);
    }

    /**
     * القائمة القياسية الكاملة (للـ facet endpoint).
     *
     * @return list<string>
     */
    public static function facets(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * tokens الـ LIKE الإيجابية لاسم قياسي (عمود يخزّن نصاً إنجليزياً).
     *
     * @return list<string>
     */
    public static function likeTokens(string $canonical): array
    {
        return self::MAP[$canonical]['tokens'] ?? [];
    }

    /**
     * tokens الـ NOT LIKE للاستثناءات (مثال: جل يستثني gelatin/capsule).
     *
     * @return list<string>
     */
    public static function excludeTokens(string $canonical): array
    {
        return self::MAP[$canonical]['exclude'] ?? [];
    }
}
