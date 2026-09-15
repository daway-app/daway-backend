<?php

namespace App\Support\Accounting;

/**
 * مفردات حالة الباركود — مصدر الحقيقة الوحيد لها في الواجهة.
 *
 * ## من أين جاءت هذه الحالات؟
 *
 * من مخطط قاعدة البيانات الفعلي، لا من الافتراض:
 *
 *  - `medicine_barcodes.is_verified`  (boolean)          → موثَّق / غير موثَّق
 *  - `medicine_barcodes.confidence`   (decimal 4,3)      → ثقة المصدر
 *  - `medicine_barcodes.source`       (string 60)        → من أين جاء
 *  - `medicine_enrichment_reviews.status` = pending|approved|rejected
 *                                                          → بانتظار مراجعة بشرية
 *
 * ⚠️ **قيد حقيقي ومهم:** `medicine_barcodes` **لا يحتوي عمود `pharmacy_id`**،
 * والباركود **فريد عالميًا** (`uniq_medicine_barcodes_barcode`). معنى ذلك:
 *
 *  1) لا يمكن للواجهة أن تقول «هذا الباركود أضافته صيدليتك» — لا يوجد من يسجّل ذلك.
 *  2) لا يمكن أن يظهر باركود واحد تحت دوائين مختلفين — القيد يمنع، والازدواجية
 *     تُكشف في `medicine_enrichment_reviews` وليس بصمت.
 *  3) أي رسالة للمستخدم بعد «الربط» يجب أن تكون محايدة
 *     (`Barcode mapping saved`) وليست وعدًا بمزامنة لم تحدث.
 *
 * ## الحالات الأربع
 *
 * | الحالة     | المعنى عند الصيدلي                                        | مصدرها في المخطط |
 * |------------|-----------------------------------------------------------|------------------|
 * | `unknown`  | لم نرَ هذا الباركود من قبل — **حالة عادية لا خطأ**        | لا سجل مطابق   |
 * | `pending`  | مرتبط لكن بانتظار مراجعة/توثيق                            | is_verified=0   |
 * | `verified` | مرتبط وموثَّق — يُضاف للسلة مباشرة                        | is_verified=1   |
 * | `conflict` | الباركود مرتبط بدواء آخر — يحتاج قرارًا بشريًا            | تعارض كشفه الباك-إند |
 *
 * **`unknown` ليست خطأ.** التغطية تنمو تدريجيًا مع إضافة الصيدليات لباركوداتها،
 * والواجهة يجب أن تعرضها كـ«فرصة للربط» لا كـ«فشل».
 */
final class BarcodeStatus
{
    /** الباركود غير موجود في القاعدة — حالة طبيعية، ليست خطأ. */
    public const UNKNOWN = 'unknown';

    /** مرتبط لكن لم يُوثَّق بعد (is_verified = 0). */
    public const PENDING = 'pending';

    /** مرتبط وموثَّق (is_verified = 1). */
    public const VERIFIED = 'verified';

    /** مرتبط بدواء آخر — يتطلب قرارًا بشريًا، ولا يُتجاوز من الواجهة. */
    public const CONFLICT = 'conflict';

    /**
     * كل الحالات — للتحقق من صحة المدخلات في الاختبارات.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::UNKNOWN, self::PENDING, self::VERIFIED, self::CONFLICT];
    }

    /**
     * الحالات التي يُسمح فيها بإضافة سطر للسلة مباشرة.
     *
     * `pending` مسموح: التوثيق يخصّ **جودة البيانات**، لا صلاحية البيع.
     * منع البيع بسبب بيانات ناقصة يُعطّل العمل الحقيقي في الصيدلية.
     */
    public static function sellable(): array
    {
        return [self::PENDING, self::VERIFIED];
    }

    /**
     * هل هذه حالة تُوقف المسار وتطلب تدخّلًا؟
     *
     * `unknown` تطلب **الربط** (مسار بناء تغطية)، و`conflict` تطلب **مراجعة** (مسار حلّ تعارض).
     * الاثنتان «تحتاج انتباهًا» لكن بمعنى مختلف تمامًا — لذلك نسمّيها `requiresAction`
     * لا `isProblem`.
     */
    public static function requiresAction(string $status): bool
    {
        return in_array($status, [self::UNKNOWN, self::CONFLICT], true);
    }

    /**
     * اشتقاق الحالة من صفوف المخطط الحقيقية.
     *
     * @param  bool   $found       هل وُجد سجل باركود أصلًا
     * @param  bool   $isVerified  medicine_barcodes.is_verified
     * @param  bool   $isConflict  هل كشف الباك-إند تعارضًا (دواء مختلف)
     */
    public static function derive(bool $found, bool $isVerified = false, bool $isConflict = false): string
    {
        if ($isConflict) {
            return self::CONFLICT;
        }

        if (! $found) {
            return self::UNKNOWN;
        }

        return $isVerified ? self::VERIFIED : self::PENDING;
    }

    /**
     * صنف CSS للشارة — يطابق عائلات `--*-bg` في `tokens.css`.
     *
     * ⚠️ `unknown` تستخدم العائلة **المحايدة** (`--neutral-*`) عن قصد: بصريًّا يجب
     * أن تنتمي إلى «طبيعي» لا إلى «تحذير». لو لوّنّاها أصفر/أحمر لصار الصيدلي يرى
     * خطأً في كل مسح لدواء جديد، وهذا بالضبط ما نرفضه.
     */
    public static function cssClass(string $status): string
    {
        return match ($status) {
            self::VERIFIED => 'is-verified',
            self::PENDING  => 'is-pending',
            self::CONFLICT => 'is-conflict',
            default        => 'is-unknown',
        };
    }

    /**
     * مفاتيح الترجمة — لا نصوص مكتوبة هنا.
     *
     * القاعدة في هذا المشروع: كل نص يراه المستخدم يأتي من `lang/`، ليبقى
     * تبديل اللغة والمراجعة اللغوية ممكنًا بلا لمس PHP.
     */
    public static function labelKey(string $status): string
    {
        return 'accounting::accounting.barcode.status.' . self::normalize($status);
    }

    public static function hintKey(string $status): string
    {
        return 'accounting::accounting.barcode.status_hint.' . self::normalize($status);
    }

    /**
     * نصّ الشارة مترجمًا.
     */
    public static function label(string $status): string
    {
        return __(self::labelKey($status));
    }

    /**
     * الشرح القصير مترجمًا — يظهر كـ`title`/تحت الشارة.
     */
    public static function hint(string $status): string
    {
        return __(self::hintKey($status));
    }

    /**
     * حالة غير معروفة تُختزل إلى `unknown` بدل أن تُرمى استثناءات في الواجهة.
     *
     * الواجهة يجب ألا تنكسر لأن الباك-إند أضاف حالة جديدة لم نعرفها بعد.
     */
    public static function normalize(string $status): string
    {
        $status = strtolower(trim($status));

        return in_array($status, self::all(), true) ? $status : self::UNKNOWN;
    }

    /**
     * تصنيف الباركودات المدخلة إلى «معروفة» و«غير معروفة» — لبطاقة التغطية.
     *
     * @param  iterable<string> $barcodes
     * @return array{linked:int, unlinked:int, total:int, percent:float}
     */
    public static function coverage(iterable $barcodes, array $knownBarcodes): array
    {
        $normalized = [];

        foreach ($knownBarcodes as $code) {
            $normalized[static::normalizeBarcode((string) $code)] = true;
        }

        $linked = 0;
        $total = 0;

        foreach ($barcodes as $code) {
            $clean = static::normalizeBarcode((string) $code);

            if ($clean === '') {
                continue;
            }

            $total++;

            if (isset($normalized[$clean])) {
                $linked++;
            }
        }

        return [
            'linked'   => $linked,
            'unlinked' => $total - $linked,
            'total'    => $total,
            'percent'  => $total > 0 ? round(($linked / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * تنظيف الباركود — نفس المنطق في PHP و JS (`AccountingUtil.normalizeBarcode`)
     * حتى لا تختلف النتيجة بين الخادم والمتصفح.
     *
     * ⚠️ `trim()` بـcharlist تعمل على البايتات: محارف عربية متعددة البايتات قد
     * تُقصّ بايتًا واحدًا ⇒ UTF-8 تالف. الباركود رقمي/لاتيني عمليًّا، لكن نبقي
     * التنظيف على `preg_replace` بالـ`/u` دفاعًا عن الحالات الشاذة.
     */
    public static function normalizeBarcode(string $barcode): string
    {
        $clean = preg_replace('/[\s\-_]+/u', '', $barcode) ?? '';

        return strtoupper($clean);
    }
}
