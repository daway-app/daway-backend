<?php

namespace App\Support;

/**
 * يحوّل أسماء الأدوية اللاتينية إلى كتابة عربية تقريبية (Transliteration)
 * ويبني aliases لمساعدة الشات بوت على مطابقة ما يكتبه المستخدم.
 *
 * ملاحظة: النتيجة مطابقة صوتية تقريبية وليست ترجمة طبية رسمية.
 */
class MedicineNameMapper
{
    /** ثنائيات الحروف — يجب فحصها قبل الحروف المفردة */
    private const DIGRAPHS = [
        'sh' => 'ش',
        'ch' => 'ك',
        'th' => 'ت',
        'ph' => 'ف',
        'gh' => 'غ',
        'kh' => 'خ',
        'ck' => 'ك',
        'qu' => 'كو',
        'oo' => 'و',
        'ee' => 'ي',
        'ai' => 'اي',
        'au' => 'او',
        'ou' => 'او',
        'ei' => 'اي',
        'ea' => 'ي',
        'ie' => 'ي',
        'oe' => 'و',
    ];

    private const SINGLES = [
        'a' => 'ا',
        'b' => 'ب',
        'd' => 'د',
        'f' => 'ف',
        'g' => 'ج',
        'h' => 'ه',
        'i' => 'ي',
        'j' => 'ج',
        'k' => 'ك',
        'l' => 'ل',
        'm' => 'م',
        'n' => 'ن',
        'o' => 'و',
        'p' => 'ب',
        'q' => 'ك',
        'r' => 'ر',
        's' => 'س',
        't' => 'ت',
        'u' => 'و',
        'v' => 'ف',
        'w' => 'و',
        'x' => 'كس',
        'y' => 'ي',
        'z' => 'ز',
    ];

    /** أنماط الجرعات والأشكال الدوائية التي تُزال عند بناء الاسم الأساسي (alias) */
    private const DOSAGE_PATTERNS = [
        '/\b\d+(?:[.,]\d+)?\s*(?:mg|mcg|µg|ug|g|ml|l|iu|meq|%)\b(?:\s*\/\s*\d+(?:[.,]\d+)?\s*ml\b)?/i',
        '/\b\d+(?:[.,]\d+)?\s*\/\s*\d+(?:[.,]\d+)?\s*ml\b/i',
        '/\bmlx?\s*\d+\s*ml\b/i',
        '/\/\s*\d+(?:[.,]\d+)?\s*m\b/i',
        '/\(\s*\d+\s*(?:tabs?|tablets?|caps?|capsules?|amp(?:oules?)?|vials?|supp(?:ositories)?|sachets?|ovules?|pfs?|pens?)\s*\)/i',
        '/\b\d+\s*(?:tabs?|tablets?|caps?|capsules?|amp(?:oules?)?|vials?|supp(?:ositories)?|sachets?|ovules?|pfs?|pens?)\b/i',
        '/\b(?:tab(?:s|lets?)?|cap(?:s|sules?)?|susp(?:ension)?|syr(?:up)?|inj(?:ection)?|amp(?:oules?)?|vials?|cream|oint(?:ment)?|gel|drops?|spray|sachets?|supp(?:ository)?|ovules?|fct|effervescent|gran(?:ules)?|sol(?:ution)?)\b/i',
        '/\bx\s*\d+\b/i',
    ];

    /**
     * يبني سجل mapping كامل للشات بوت من سجل كتالوج وزارة الصحة.
     */
    public static function map(array $record, int $id): array
    {
        $tradeName = self::clean($record['trade_name'] ?? '');
        $genericName = isset($record['generic_name']) ? self::clean($record['generic_name']) : null;
        $nameAr = $tradeName === '' ? null : self::toArabic($tradeName);

        return [
            'id' => $id,
            'moh_drug_id' => $record['moh_drug_id'] ?? null,
            'moh_product_id' => $record['moh_product_id'] ?? null,
            'product_class' => $record['product_class'] ?? null,
            'name_en' => $tradeName,
            'name_ar' => $nameAr,
            'aliases' => self::buildAliases($tradeName, $genericName, $nameAr),
        ];
    }

    /**
     * التحويل الصوتي: يدعم النصوص المختلطة (عربي/لاتيني) فيُبقي العربي كما هو.
     */
    public static function toArabic(string $name): string
    {
        $name = self::clean($name);
        if ($name === '') {
            return '';
        }

        // تقسيم النص إلى مقاطع عربية (تبقى كما هي) وأخرى لاتينية/أرقام (تُحوَّل)
        preg_match_all('/([\x{0600}-\x{06FF}\s]+)|([^\x{0600}-\x{06FF}]+)/u', $name, $parts, PREG_SET_ORDER);

        $segments = [];
        foreach ($parts as $part) {
            if (($part[1] ?? '') !== '') {
                $segments[] = $part[1];
            } else {
                $segments[] = self::transliterateSegment($part[2]);
            }
        }

        $out = trim(preg_replace('/\s+/u', ' ', implode(' ', array_filter($segments, fn (string $s): bool => trim($s) !== ''))));

        // لا تبدأ الكلمة العربية الطبيعية بحرف علة مجرد — نضيف ألف الوصل
        if (mb_strlen($out) > 1 && in_array(mb_substr($out, 0, 1), ['و', 'ي'], true)) {
            $out = 'ا'.$out;
        }

        return $out;
    }

    /**
     * ينظّف الاسم من التشكيل والتطويل والرموز الزائدة ويوحّد الفواصل.
     */
    public static function clean(string $name): string
    {
        $name = str_replace(["\u{00A0}", '"', "'", '’', '‘', '`'], ' ', $name);
        $name = preg_replace('/[\x{064B}-\x{0652}\x{0640}\x{0670}]/u', '', $name);
        $name = preg_replace('/[\r\n\t]+/', ' ', $name);
        $name = preg_replace('/\s+/u', ' ', trim($name));

        return $name ?? '';
    }

    /**
     * يولّد قائمة aliases: الاسم كاملاً + الاسم بدون جرعة/شكل دوائي + المادة الفعالة + العربي.
     */
    public static function buildAliases(?string $nameEn, ?string $genericName, ?string $nameAr): array
    {
        $aliases = [];

        foreach ([$nameEn, self::stripDosage((string) $nameEn), $genericName, $nameAr] as $candidate) {
            $candidate = mb_strtolower(trim((string) $candidate));
            if (mb_strlen($candidate) >= 2) {
                $aliases[] = $candidate;
            }
        }

        return array_values(array_unique($aliases));
    }

    /**
     * يعيد الاسم الأساسي بدون أرقام الجرعات والأشكال الدوائية (مفيد للبحث).
     */
    public static function stripDosage(string $name): string
    {
        $name = self::clean($name);
        if ($name === '') {
            return '';
        }

        $previous = null;
        while ($previous !== $name) {
            $previous = $name;
            foreach (self::DOSAGE_PATTERNS as $pattern) {
                $name = preg_replace($pattern, ' ', $name);
            }
            // تنظيف علامات ترقيم يتيمة بقاءها بعد إزالة الجرعات
            $name = preg_replace('/(^|\s)[.,\/;:()\-]+(?=\s|$)/', ' ', $name);
            $name = preg_replace('/\s+/', ' ', trim($name, " \t.,\/;:-"));
        }

        return $name;
    }

    /** يحوّل مقطعاً لاتينياً واحداً مع الحفاظ على الفواصل الأصلية (مسافات، شرطات، أرقام). */
    private static function transliterateSegment(string $segment): string
    {
        $segment = strtolower($segment);
        $out = '';

        foreach (preg_split('/([^a-z]+)/', $segment, -1, PREG_SPLIT_DELIM_CAPTURE) as $chunk) {
            if ($chunk === '' || $chunk === false) {
                continue;
            }

            if (ctype_alpha($chunk)) {
                $out .= self::transliterateWord($chunk);

                continue;
            }

            $out .= $chunk;
        }

        return $out;
    }

    private static function transliterateWord(string $word): string
    {
        $length = strlen($word);
        $i = 0;
        $out = '';

        while ($i < $length) {
            $pair = substr($word, $i, 2);

            if (strlen($pair) === 2 && isset(self::DIGRAPHS[$pair])) {
                $out .= self::DIGRAPHS[$pair];
                $i += 2;

                continue;
            }

            $char = $word[$i];

            if ($char === 'c') {
                $next = $word[$i + 1] ?? '';
                $out .= in_array($next, ['e', 'i', 'y'], true) ? 'س' : 'ك';
                $i++;

                continue;
            }

            if ($char === 'e') {
                if ($i === 0 && $length > 1) {
                    $out .= 'ا';
                } elseif ($i === $length - 1) {
                    // e الأخيرة صامتة غالباً (Omeprazole)
                } else {
                    $out .= 'ي';
                }
                $i++;

                continue;
            }

            $out .= self::SINGLES[$char] ?? '';
            $i++;
        }

        return $out;
    }
}
