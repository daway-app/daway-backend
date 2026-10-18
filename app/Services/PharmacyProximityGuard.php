<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Support\Haversine;

/**
 * بوابة تقارب صيدليات الطلب الواحد.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * القاعدة التجارية: الطلب الواحد يُخدم برحلة توصيل واحدة، لذلك يجب ألا يبعد
 * **أبعد زوج** من صيدلياته أكثر من 500 متر.
 *
 * ── لماذا «أبعد زوج» وليس «كل صيدلية والتالية لها»؟ ──────────────────────
 * لو قارنّا كل صيدلية بالتالية فقط، فسلسلة من ثلاث صيدليات كل واحدة على بعد
 * 300م من التالية تمرّ (300 ≤ 500) رغم أن الطرفين على 600م — أي أن الطلب
 * يمتد ضعف الحد. لذلك تُجرى المقارنة على **كل الأزواج** (i < j) وتُؤخذ
 * أكبر مسافة، وهي التي تُفحص مقابل الحد.
 *
 * ── الحد عند 500 بالضبط ───────────────────────────────────────────────────
 * المسافة = 500م **مسموحة**؛ الرفض فقط عند > 500. المقارنة تجري على القيمة
 * المقرّبة للأمتار (عدد صحيح) لتفادي ضجيج الفاصلة العائمة: مسافة خام قدرها
 * 500.0001م تُقرَّب إلى 500 فتُقبل، كما هو المطلوب.
 *
 * ── الإحداثيات المفقودة: لماذا نفشل صراحةً بدل التجاوز؟ ──────────────────
 * نقاط البحث القائمة (`PharmacyController`, `PharmacyInventorySearch`)
 * تتجاوز الصيدليات بلا إحداثيات وتُبقيها في النتائج بـ`distance_km = null`.
 * هذا صحيح **هناك** لأنه تسهيل اكتشاف: غياب الإحداثيات لا يجعل الصيدلية
 * غير موجودة، ولا يصحّ إخفاؤها من نتائج البحث.
 *
 * هنا الوضع معاكس: القاعدة **قيدُ جدوى توصيل**. التجاهل الصامت يعني تمرير
 * طلب لم نتحقق من إمكانية توصيله — وهو بالضبط «تجاوز التحقق بصمت» الممنوع.
 * لذلك نفشل صراحةً (fail-closed) برسالة تسمّي الصيدلية صاحبة النقص.
 * وهذا متسق مع `Pharmacy::hasCompleteProfileData()` الذي يعتبر غياب
 * الإحداثيات ملفاً غير مكتمل (لا صيدلية جاهزة للعمل).
 *
 * ── صيدلية واحدة ─────────────────────────────────────────────────────────
 * لا مقارنة إطلاقاً — حتى لو كانت إحداثياتها ناقصة — لأنه لا يوجد زوج
 * أصلاً. طلب صيدلية واحدة لا يتأثر بهذه البوابة بأي شكل.
 *
 * ── لماذا لا تلمس هذه الخدمة المخزون ولا الطلب؟ ──────────────────────────
 * دالة نقية: تقرأ كائنات Pharmacy وترجع حكماً. لا تحفظ، ولا تعدّل، ولا
 * تعرف شيئاً عن السلة أو المخزون. الرفض يحدث داخل الـtransaction قبل أي
 * كتابة، فيتراجع كل شيء تلقائياً.
 */
final class PharmacyProximityGuard
{
    /** الحد الأقصى للمسافة بين أبعد صيدليتين في الطلب الواحد (بالمتر). */
    public const MAX_DISTANCE_METERS = 500;

    /**
     * هامش عددي = **1 ملّيمتر**، ولا شيء غيره.
     *
     * ── لماذا يوجد هامش أصلاً؟ ───────────────────────────────────────────
     * الإحداثيات مخزَّنة كـ`decimal(10,8)` للخط و`decimal(11,8)` للطول، أي أن
     * أصغر خطوة تخزين = 1e-8 درجة ≈ **1.11 ملّيمتر** على خط عرض غزة. لذلك زوج
     * إحداثياته «500.000 متر بالضبط» رياضياً قد يُخزَّن ويُقرأ بمسافة محسوبة
     * 500.000219 م — وهذا ليس خطأ حساباً بل حدّ دقة البيانات نفسها.
     * بدون هامش كانت المسافة 500 م ستُرفض أحياناً بشكل عشوائي حسب كيفية
     * تقريب الإحداثيات — وهو بالضبط ما يجب تجنّبه.
     *
     * ── لماذا 1 ملّيمتر، ولماذا لا يُعدّ تسامحاً عملياً؟ ────────────────────
     *   · إنه نفس حجم دقة التخزين تقريباً (1.11 مم) — لا أكثر.
     *   · 500.1 م = 100 ضعف الهامش ⇒ تُرفض بفارق لا لبس فيه.
     *   · 500.01 م = 10 أضعاف الهامش ⇒ تُرفض أيضاً (اختبار يثبته).
     *   · كنسبة: 1 مم / 500 م = 0.0002% — بلا أي معنى تشغيلي في قرار توصيل.
     * فلا يتحوّل الهامش إلى «تسامح في حد التوصيل»؛ هو فقط يمنع رفض مسافة
     * مطابقة للحد بسبب دقة تخزين الإحداثيات.
     */
    private const DISTANCE_EPSILON_METERS = 1.0e-3;

    /**
     * يفحص قائمة صيدليات الطلب.
     *
     * @param  iterable<Pharmacy>  $pharmacies
     * @return array{
     *     reason: 'too_far'|'missing_coordinates',
     *     message: string,
     *     pharmacies: list<array{id:int,name:string}>,
     *     distance_meters?: float
     * }|null  null يعني «لا مخالفة» — الطلب مسموح
     */
    public static function inspect(iterable $pharmacies): ?array
    {
        $unique = self::uniqueById($pharmacies);

        // صيدلية واحدة (أو صفر) ⇒ لا زوج ⇒ لا فحص. مقصود: لا نعاقب صيدلية
        // واحدة على إحداثيات ناقصة لأنها لا تدخل أي مقارنة.
        if (count($unique) < 2) {
            return null;
        }

        $unusable = [];

        foreach ($unique as $pharmacy) {
            if (! self::hasUsableCoordinates($pharmacy)) {
                $unusable[] = self::ref($pharmacy);
            }
        }

        if ($unusable !== []) {
            return [
                'reason' => 'missing_coordinates',
                'message' => self::missingCoordinatesMessage($unusable),
                'pharmacies' => $unusable,
            ];
        }

        $worst = null;
        $list = array_values($unique);
        $count = count($list);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $meters = self::distanceMeters($list[$i], $list[$j]);

                if ($worst === null || $meters > $worst['meters']) {
                    $worst = [
                        'meters' => $meters,
                        'a' => self::ref($list[$i]),
                        'b' => self::ref($list[$j]),
                    ];
                }
            }
        }

        // ── القرار: مقارنة **خام** لا مقرّبة ─────────────────────────────
        // `(int) round($km * 1000)` كان يسمح بما دون 500.5 م — أي تسامح نصف
        // متر فعلي. الآن: 500.000 م مسموحة، و500.1 م مرفوضة. الهامش الوحيد
        // هو DISTANCE_EPSILON_METERS = 1 مم (دقة تخزين الإحداثيات، مشروح
        // أعلى الصنف). لا `round` ولا `int` في مسار القرار إطلاقاً.
        if ($worst === null
            || $worst['meters'] <= self::MAX_DISTANCE_METERS + self::DISTANCE_EPSILON_METERS) {
            return null;
        }

        return [
            'reason' => 'too_far',
            'message' => self::tooFarMessage($worst['a'], $worst['b'], $worst['meters']),
            'pharmacies' => [$worst['a'], $worst['b']],
            // تقريب للعرض فقط (3 منازل = ملّيمتر) — القرار أعلاه استخدم الخام.
            'distance_meters' => round($worst['meters'], 3),
        ];
    }

    /**
     * المسافة بالأمتار بين صيدليتين لهما إحداثيات صالحة — **قيمة خام** بلا
     * تقريب، لأن قرار السماح/الرفض يُبنى عليها مباشرة.
     *
     * لا تُقرَّب هنا عمداً: أي تقريب إلى متر صحيح يعيد تسامحاً يصل إلى نصف
     * متر. التقريب للعرض يحدث في `inspect()` على نسخة منفصلة (`round(...,3)`).
     */
    public static function distanceMeters(Pharmacy $a, Pharmacy $b): float
    {
        $km = Haversine::kmBetween(
            (float) $a->latitude,
            (float) $a->longitude,
            (float) $b->latitude,
            (float) $b->longitude,
        );

        return $km * 1000.0;
    }

    /**
     * صيدلية واحدة لكل معرّف — السلة قد تحمل عدة أسطر من نفس الصيدلية،
     * ومقارنتها بنفسها تعطي مسافة صفر وتُلوّث منطق «أبعد زوج».
     *
     * @param  iterable<Pharmacy>  $pharmacies
     * @return array<int, Pharmacy>
     */
    private static function uniqueById(iterable $pharmacies): array
    {
        $unique = [];

        foreach ($pharmacies as $pharmacy) {
            if (! $pharmacy instanceof Pharmacy) {
                continue;
            }

            $unique[(int) $pharmacy->id] = $pharmacy;
        }

        return $unique;
    }

    /**
     * هل الإحداثيات موجودة **وصالحة**؟
     *
     * `latitude` هو `decimal(10,8)` و`longitude` هو `decimal(11,8)` في
     * قاعدة البيانات — أي أن القاعدة تقبل قيماً خارج النطاق الجغرافي
     * (مثل 95 خط عرض). لذلك نتحقق من النطاق هنا بدل الاعتماد على نوع العمود.
     */
    private static function hasUsableCoordinates(Pharmacy $pharmacy): bool
    {
        $lat = $pharmacy->latitude;
        $lng = $pharmacy->longitude;

        if ($lat === null || $lng === null) {
            return false;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        return $lat >= -90.0 && $lat <= 90.0
            && $lng >= -180.0 && $lng <= 180.0;
    }

    /** @return array{id:int,name:string} */
    private static function ref(Pharmacy $pharmacy): array
    {
        return [
            'id' => (int) $pharmacy->id,
            'name' => (string) ($pharmacy->pharmacy_name ?: '#'.$pharmacy->id),
        ];
    }

    private static function tooFarMessage(array $a, array $b, float $meters): string
    {
        return sprintf(
            'المسافة بين الصيدليتين «%s» و«%s» هي %s متر، وهي تتجاوز الحد الأقصى المسموح %d متر. '
            .'أزِل أصناف إحدى الصيدليتين من السلة ثم أعد المحاولة.',
            $a['name'],
            $b['name'],
            self::formatMeters($meters),
            self::MAX_DISTANCE_METERS,
        );
    }

    /**
     * عرض المسافة بلا أصفار زائدة: 620.0 ⇒ "620" · 500.4 ⇒ "500.4".
     *
     * منزلة واحدة (10 سم) كافية لنصّ موجّه للمستخدم؛ أما الحقل الآلي
     * `distance_meters` فيُعاد بثلاث منازل (ملّيمتر). لا تُستخدم هذه القيمة
     * في القرار — عرض فقط.
     */
    private static function formatMeters(float $meters): string
    {
        $formatted = number_format(round($meters, 1), 1, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private static function missingCoordinatesMessage(array $unusable): string
    {
        $names = implode('»، «', array_column($unusable, 'name'));

        return sprintf(
            'لا يمكن التحقق من تقارب الصيدليات: الصيدلية «%s» بلا إحداثيات موقع صالحة. '
            .'الطلب الذي يشمل أكثر من صيدلية يتطلب إحداثيات دقيقة لكل صيدلية.',
            $names,
        );
    }
}
