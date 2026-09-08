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
    /**
     * قاموس كلمات شائعة (علامات تجارية ومواد فعالة) — يُفحص قبل التحويل الحرف-بحرف
     * لأنه أدق من القواعد العامة. المفتاح lowercase للكلمة الإنجليزية الكاملة.
     *
     * ملاحظة: هذه مطابقة صوتية للمبحث وليست ترجمة طبية رسمية.
     */
    private const KNOWN_WORDS = [
        // علامات تجارية شائعة في السوق الفلسطيني
        'panadol' => 'بنادول',
        'extra' => 'اكسترا',
        'augmentin' => 'اوجمنتين',
        'brufen' => 'بروفين',
        'voltaren' => 'فولتارين',
        'ventolin' => 'فينتولين',
        'aspirin' => 'اسبرين',
        'adol' => 'ادول',
        'buscopan' => 'بوسكوبان',
        'flagyl' => 'فلاجيل',
        'amoxil' => 'اموكسيل',
        'glucophage' => 'جلوكوفاج',
        'concor' => 'كونكور',
        'nurofen' => 'نيوروفين',
        'advil' => 'ادفيل',
        'dolgit' => 'دولجيت',
        // مواد فعالة شائعة
        'paracetamol' => 'باراسيتامول',
        'ibuprofen' => 'ايبوبروفين',
        'amoxicillin' => 'اموكسيسيلين',
        'diclofenac' => 'ديكلوفيناك',
        'omeprazole' => 'اوميبرازول',
        'metformin' => 'ميتفورمين',
        'azithromycin' => 'ازيترومايسين',
        'ciprofloxacin' => 'سيبروفلوكساسين',
        'cetirizine' => 'سيتيريزين',
        'loratadine' => 'لوراتادين',
        'salbutamol' => 'سالبوتامول',
        'prednisolone' => 'بريدنيزولون',
        'cephalexin' => 'سيفالكسين',
        'clavulanic' => 'كلافولانيك',
        'tramadol' => 'ترامادول',
        'amlodipine' => 'املوديبين',
        'valsartan' => 'فالسارتان',
        'losartan' => 'لوسارتان',
        'atenolol' => 'اتينولول',
        'gabapentin' => 'جابابنتين',
        'pregabalin' => 'بريجابالين',
        'fluconazole' => 'فلوكونازول',
        'vitamin' => 'فيتامين',
        'calcium' => 'كالسيوم',
        'folic' => 'فوليك',
    ];

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
     * يولّد قائمة aliases: الاسم كاملاً + الاسم بدون جرعة/شكل دوائي + المادة الفعالة + العربي
     * + ~10 متغيرات عربية (بدون مسافات، كلمة أولى/أخيرة، إلخ) للبحث التقريبي.
     */
    public static function buildAliases(?string $nameEn, ?string $genericName, ?string $nameAr): array
    {
        $aliases = [];
        $add = function (?string $v) use (&$aliases): void {
            $v = mb_strtolower(trim((string) $v));
            if (mb_strlen($v) >= 2) {
                $aliases[] = $v;
            }
        };

        // الأساسي (موجود مسبقاً — لا تغيير على الـ API للمستهلكين)
        $add($nameEn);
        $add(self::stripDosage((string) $nameEn));
        $add($genericName);
        $add($nameAr);

        // متغيرات عربية إضافية لمساعدة المطابقة التقريبية
        foreach (self::arabicVariants($nameAr) as $variant) {
            $add($variant);
        }

        return array_values(array_unique($aliases));
    }

    /**
     * يولّد حتى 10 متغيرات عربية من اسم_عربي:
     * الكامل، بدون جرعات/شكل، بدون مسافات، كلمة أولى/أخيرة/آخر كلمتين/بدون آخر كلمة.
     */
    public static function arabicVariants(?string $nameAr, int $max = 10): array
    {
        if ($nameAr === null || trim($nameAr) === '') {
            return [];
        }

        $variants = [];
        $add = function (string $v) use (&$variants): void {
            $v = mb_strtolower(trim($v));
            if (mb_strlen($v) >= 2) {
                $variants[] = $v;
            }
        };

        // 1) الاسم بعد إزالة الجرعات والأشكال الدوائية بالعربية + نسخته بدون الألف الواصلة
        $stripped = self::stripArabicDosage($nameAr);
        $add($stripped);
        $add(self::dropMedialAlif($stripped));

        $words = self::splitWords($stripped);
        if (count($words) >= 2) {
            // 2) الكلمة الأولى فقط (بحث جزئي) + نسختها بدون الألف
            $add($words[0]);
            $add(self::dropMedialAlif($words[0]));
            // 3) الكلمات مع "و" بين كل اثنين (بحث مرن: "بنادول و اكسترا")
            $joined = implode(' و ', $words);
            $add($joined);
            $add(self::dropMedialAlif($joined));
            // 4) آخر كلمتين
            $add(implode(' ', array_slice($words, -2)));
            // 5) جميع الكلمات عدا الأخيرة
            $add(implode(' ', array_slice($words, 0, -1)));
            // 6) أول كلمتين
            $add(implode(' ', array_slice($words, 0, 2)));
        }

        // 8) الاسم الكامل كما هو + نسخته بدون الألف
        $add($nameAr);
        $add(self::dropMedialAlif($nameAr));

        $variants = array_values(array_unique($variants));

        return array_slice($variants, 0, $max);
    }

    /**
     * يولّد الكتابة الشائعة البديلة بحذف الألف الواصلة بعد الحرف الأول
     * (بانادول → بنادول، اوجمينتين → وجمينتين...).
     */
    public static function dropMedialAlif(string $phrase): string
    {
        $words = self::splitWords($phrase);
        $out = [];
        foreach ($words as $word) {
            if (mb_strlen($word) >= 3 && mb_substr($word, 1, 1) === 'ا') {
                $word = mb_substr($word, 0, 1).mb_substr($word, 2);
            }
            $out[] = $word;
        }

        return implode(' ', $out);
    }

    /**
     * يقطّع النص إلى كلمات حسب المسافات (يتجاهل الفراغات الزائدة).
     */
    private static function splitWords(string $s): array
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');

        return $s === '' ? [] : explode(' ', $s);
    }

    /**
     * يزيل الجرعات والأشكال الدوائية من اسم عربي (أرقام + مج/ملغ/قرص/كبسولة/...).
     */
    private static function stripArabicDosage(string $name): string
    {
        $name = self::clean($name);
        if ($name === '') {
            return '';
        }

        $previous = null;
        while ($previous !== $name) {
            $previous = $name;
            // أرقام مع وحدة عربية (500 مج، 100 مل...)
            $name = preg_replace('/\b\d+(?:[.,]\d+)?\s*(?:مج|ملغ|مل|غرام|كغم|كبسولات?|كبس|اقراص|قرص|حبوب|امبولات?|امبول|شراب|سيروب|مرهم|كريم|جل|قطرات?|بخاخ|تحاميل|اكياس|كيس|تحميلة|ف|ف ام)\b/u', ' ', $name) ?? $name;
            // وحدات لاتينية
            $name = preg_replace('/\b(?:mg|mcg|µg|ug|g|ml|l|iu|meq|%)\b/i', ' ', $name) ?? $name;
            // أرقام منفردة متبقية
            $name = preg_replace('/\b\d+(?:[.,]\d+)?\b/u', ' ', $name) ?? $name;
            // أقواس بمحتوى
            $name = preg_replace('/\([^)]*\)/u', ' ', $name) ?? $name;
            // رموز/ترقيم
            $name = preg_replace('/[^\p{Arabic}\p{L}\s]/u', ' ', $name) ?? $name;
            $name = preg_replace('/\s+/u', ' ', trim($name));
        }

        return $name;
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
        $out = '';

        foreach (preg_split('/([^a-zA-Z]+)/', $segment, -1, PREG_SPLIT_DELIM_CAPTURE) as $chunk) {
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
        // القاموس أولاً — غير حساس لحالة الأحرف: PANADOL وAugmentin وparacetamol
        // كلها تُطابق القاموس. بيانات MoH الحقيقية مختلطة الحالة.
        if (isset(self::KNOWN_WORDS[strtolower($word)])) {
            return self::KNOWN_WORDS[strtolower($word)];
        }

        $word = strtolower($word);

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
