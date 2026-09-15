<?php

namespace App\Services\Enrichment;

/**
 * تطهير اسم الدواء (عربي/إنجليسي) — ثابت قابل اختبار وبالتداخل مع
 * أدوات التطهير المستخدمة في المشروع (Hamza/diacritics elimination).
 */
final class NameNormalizer
{
    public static function latin(?string $value): string
    {
        $v = mb_strtolower(trim(strip_tags((string) $value)));
        // إزالة الأقواس والأبناط ومسافات مزدوجة
        $v = preg_replace('/\([^)]*\)/u', '', $v);
        $v = preg_replace('/[^a-z0-9\s]+/u', ' ', (string) $v);
        $v = preg_replace('/\s+/u', ' ', (string) $v);

        return mb_trim((string) $v);
    }

    public static function arabic(?string $value): string
    {
        $v = trim(strip_tags((string) $value));
        // إزالة الحركات والدبّوس الناعمة ( Alemania/tashkeel)
        $v = preg_replace('/[\x{064B}-\x{065F}\x{0640}\x{0670}\x{06D6}-\x{06ED}]/u', '', (string) $v);
        // أ/إ/آ → ا، ة → ه، ي/ى → ي، 事项
        $v = strtr($v, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي',
            'ي' => 'ي', 'ى' => 'ي',
        ]);
        $v = preg_replace('/\s+/u', ' ', (string) $v);

        return mb_trim((string) $v);
    }

    /** نسبة تطابق (0..1) بسيطة بين نصّين بعد التطهير — Levenshtein normalized. */
    public static function similarity(?string $a, ?string $b): float
    {
        $a = self::latin($a);
        $b = self::latin($b);
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        $maxLen = max(mb_strlen($a), mb_strlen($b));
        if ($maxLen === 0) {
            return 0.0;
        }

        return 1.0 - (levenshtein($a, $b) / $maxLen);
    }
}
