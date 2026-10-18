<?php

namespace App\Services;

use App\Exceptions\DeliveryPricingException;
use App\Models\Address;
use App\Models\DeliveryFeeTier;
use App\Models\Pharmacy;
use App\Models\Setting;
use App\Support\Haversine;
use Illuminate\Support\Collection;

/**
 * Phase 1 — تسعير التوصيل القابل للتهيئة.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * القواعد الحاكمة (لا تُخالف بلا قرار منتج صريح)
 * ════════════════════════════════════════════════════════════════════════════
 *  · المسافة **بخط مستقيم** (Haversine) — ليست مسافة طريق. لا خرائط ولا خدمات خارجية.
 *  · الرسوم تُحسب في الخادم فقط؛ قيمة العميل لا تُوثق أبدًا.
 *  · «تسعير التوصيل» معطّل افتراضيًا (مفتاح الإعداد `delivery_pricing_enabled`).
 *    ما دام معطّلاً، كل الطلبات رسومها 0 — بلا أي تغيير سلوكي.
 *  · عند التفعيل: يجب أن تطابق المسافة شريحةً **مفعّلة**، وإلّا يُرفض الطلب.
 *  · طلب متعدد الصيدليات: تُحسب مسافة **أقصر مسار مفتوح** يزور صيدليات الطلب
 *    كلًّا مرة واحدة وينتهي بعنوان التسليم (TSP مفتوح: بداية حرّة، بلا مسافة
 *    رجوع)، ثم تُطبَّق الشرائح على **إجمالي المسافة** وتُحفظ رسوم واحدة على
 *    الطلب. لا تُجمَع رسوم مستقلة لكل صيدلية.
 *
 * ⚠️ «مفعّلة» هنا تعني `delivery_fee_tiers.is_active = true`، و«التسعير مفعّل»
 *    يعني مفتاح الإعداد. الاثنان مستقلان: يمكن تجهيز شرائح مفعّلة بينما التسعير
 *    نفسه ما زال معطّلاً.
 */
final class DeliveryPricingService
{
    /** مفتاح الإعداد الذي يشغّل/يوقف استخدام الشرائح في التسعير. */
    public const ENABLED_SETTING_KEY = 'delivery_pricing_enabled';

    /**
     * أكبر عدد نقاط استلام تُحسب لها المسافة المثالية **بدقة** (Held–Karp).
     * فوق هذا الحد يُستخدم تقريب حتمي (أقرب جار + 2-opt) بدل التفجّر العاملي،
     * فيبقى التسعير سريعًا لأي عدد نقاط. هذا **ليس** حدًّا تجاريًا: كل الطلبات
     * تُسعَّر مهما بلغ عدد صيدلياتها — إنه مجرد تحوّل في طريقة الحساب.
     *
     * ⚠️ فوق هذا الحد **لا يضمن التقريب الأمثلية العالمية**: قد يكون المسار
     * أطول من الأمثل ببضعة أمتار (لكنه يبقى مسارًا صالحًا يمرّ بكل النقاط وينتهي
     * بالعنوان). عمليًا تُبقي بوابة تقارب 500م كل نقاط الاستلام داخل دائرة واحدة
     * فيكون الفرق مهملًا؛ ومع ذلك يجب ألّا يُوصف الناتج فوق هذا الحد بأنه «الأمثل».
     */
    private const EXACT_ROUTE_MAX_POINTS = 12;

    /** هل تسعير التوصيل مفعّل؟ (القيمة الافتراضية عند غياب المفتاح: false). */
    public static function isEnabled(): bool
    {
        return Setting::query()->where('key', self::ENABLED_SETTING_KEY)->value('value') === '1';
    }

    /** ضبط حالة التفعيل (يستخدمه كنترولر الأدمن فقط؛ لا يُنفّذ تحققًا هنا). */
    public static function setEnabled(bool $enabled): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::ENABLED_SETTING_KEY],
            ['value' => $enabled ? '1' : '0'],
        );
    }

    /**
     * يقرأ حالة التفعيل مع **قفل صف الإعداد** — يُستدعى داخل معاملة إدارية فقط،
     * لتفادي سباق «تفعيل التسعير بالتوازي مع تعطيل/حذف آخر شريحة فعّالة».
     * لا يُستخدَم في مسار الشراء (الطريق الحار)؛ هناك يكفي `isEnabled()` بلا قفل.
     */
    public static function isEnabledLocked(): bool
    {
        return Setting::query()
            ->where('key', self::ENABLED_SETTING_KEY)
            ->lockForUpdate()
            ->value('value') === '1';
    }

    /**
     * تسعير رحلة توصيل واحدة لطلبٍ ما.
     *
     * @param  Collection<int, Pharmacy>  $pharmacies  صيدليات الطلب (بدون تكرار)
     * @return array{fee: float, distance_meters: int|null, tier_id: int|null}
     *
     * @throws DeliveryPricingException  عند نقص الإحداثيات أو غياب شريحة مطابقة
     */
    public static function quoteForCheckout(Collection $pharmacies, Address $address): array
    {
        // التسعير معطّل ⇒ صفر، بلا تغيير سلوكي إطلاقًا.
        if (! self::isEnabled()) {
            return ['fee' => 0.0, 'distance_meters' => null, 'tier_id' => null];
        }

        $unique = $pharmacies->unique('id')->values();

        if ($unique->isEmpty()) {
            throw new DeliveryPricingException('تعذّر تحديد صيدلية الطلب لاحتساب رسوم التوصيل.');
        }

        // مسافة الرحلة الكاملة: أقصر مسار مفتوح يزور كل صيدليات الطلب مرة واحدة
        // وينتهي بعنوان التسليم. لصيدلية واحدة تساوي المسافة المباشرة نفسها.
        $distance = self::routeDistanceMeters($unique, $address);

        if ($distance === null) {
            throw new DeliveryPricingException(
                'تعذّر حساب مسافة التوصيل: إحداثيات العنوان أو إحدى صيدليات الطلب غير متوفرة.'
            );
        }

        $tier = self::tierForMeters($distance);

        if ($tier === null) {
            throw new DeliveryPricingException(
                'لا توجد شريحة توصيل مطابقة لمسافة هذا الطلب. يرجى مراجعة إعدادات التوصيل.'
            );
        }

        return [
            'fee' => (float) $tier->fee,
            'distance_meters' => $distance,
            'tier_id' => $tier->getKey(),
        ];
    }

    /** الشريحة الفعّالة المطابقة لمسافة (بالمتر)، أو null. */
    public static function tierForMeters(int $meters): ?DeliveryFeeTier
    {
        return DeliveryFeeTier::query()
            ->active()
            ->where('min_distance_meters', '<=', $meters)
            ->where(function ($query) use ($meters) {
                $query->whereNull('max_distance_meters')
                    ->orWhere('max_distance_meters', '>=', $meters);
            })
            ->orderBy('min_distance_meters')
            ->first();
    }

    /**
     * المسافة المستقيمة (بالمتر) من الصيدلية إلى عنوان التسليم.
     * تُعيد null عند نقص/عدم صلاحية أي إحداثيات — لا قيمة مصطنعة.
     */
    public static function distanceMeters(Pharmacy $pharmacy, Address $address): ?int
    {
        if (! self::validCoordinates($pharmacy->latitude, $pharmacy->longitude)) {
            return null;
        }

        if (! self::validCoordinates($address->latitude, $address->longitude)) {
            return null;
        }

        $km = Haversine::kmBetween(
            (float) $pharmacy->latitude,
            (float) $pharmacy->longitude,
            (float) $address->latitude,
            (float) $address->longitude,
        );

        return (int) round($km * 1000);
    }

    /**
     * مسافة **أقصر مسار مفتوح** يزور صيدليات الطلب كلًّا مرة واحدة وينتهي
     * بعنوان التسليم — بخط مستقيم (Haversine)، بلا مسافة رجوع إلى نقطة البداية.
     *
     * تُعيد null عند نقص/عدم صلاحية أي إحداثيات (العنوان أو أي صيدلية) — لا قيمة
     * مصطنعة، تمامًا كسلوك مسار الصيدلية الواحدة.
     *
     * @param  Collection<int, Pharmacy>  $pharmacies
     */
    public static function routeDistanceMeters(Collection $pharmacies, Address $address): ?int
    {
        if (! self::validCoordinates($address->latitude, $address->longitude)) {
            return null;
        }

        $pickups = [];

        foreach ($pharmacies->unique('id') as $pharmacy) {
            if (! $pharmacy instanceof Pharmacy) {
                continue;
            }

            if (! self::validCoordinates($pharmacy->latitude, $pharmacy->longitude)) {
                return null;
            }

            $pickups[] = [(float) $pharmacy->latitude, (float) $pharmacy->longitude];
        }

        if ($pickups === []) {
            return null;
        }

        $meters = self::shortestOpenPathMeters(
            $pickups,
            [(float) $address->latitude, (float) $address->longitude],
        );

        return (int) round($meters);
    }

    /**
     * أقصر مسار مفتوح يزور كل نقاط الاستلام مرة واحدة وينتهي عند `$destination`.
     * البداية حرّة (أي نقطة)، ولا تُحتسب أي مسافة رجوع. النتيجة بالمتر (Haversine).
     *
     * للمجموعات الصغيرة يُستخدم حل أمثل دقيق (Held–Karp)؛ وتُستخدم بعده طريقة
     * تقريبية حتمية (أقرب جار + 2-opt) بدل التفجّر العاملي.
     *
     * @param  array<int, array{0: float, 1: float}>  $pickups
     * @param  array{0: float, 1: float}  $destination
     */
    public static function shortestOpenPathMeters(array $pickups, array $destination): float
    {
        $n = count($pickups);

        if ($n === 0) {
            return 0.0;
        }

        $toDestination = [];
        foreach ($pickups as $i => $point) {
            $toDestination[$i] = self::haversineMeters($point, $destination);
        }

        if ($n === 1) {
            return $toDestination[0];
        }

        // مصفوفة المسافات بين نقاط الاستلام (متناظرة).
        $between = [];
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $d = self::haversineMeters($pickups[$i], $pickups[$j]);
                $between[$i][$j] = $d;
                $between[$j][$i] = $d;
            }
        }

        if ($n <= self::EXACT_ROUTE_MAX_POINTS) {
            return self::exactOpenPathMeters($n, $between, $toDestination);
        }

        return self::approximateOpenPathMeters($n, $between, $toDestination);
    }

    /**
     * Held–Karp للمسار المفتوح:
     *   dp[mask][last] = أقل تكلفة لمسار يبدأ من أي نقطة، يزور المجموعة `mask`
     *   بالضبط، وينتهي عند `last`.
     * الجواب = أصغر (dp[full][last] + المسافة من `last` إلى الوجهة).
     * التعقيد O(2^n · n^2) والخزين O(2^n · n).
     *
     * @param  array<int, array<int, float>>  $between
     * @param  array<int, float>  $toDestination
     */
    private static function exactOpenPathMeters(int $n, array $between, array $toDestination): float
    {
        $full = (1 << $n) - 1;
        $dp = [];

        // البداية حرّة: الوقوف على نقطة واحدة بعد زيارتها فقط تكلفته صفر.
        for ($i = 0; $i < $n; $i++) {
            $dp[1 << $i][$i] = 0.0;
        }

        for ($mask = 1; $mask <= $full; $mask++) {
            if (! isset($dp[$mask])) {
                continue;
            }

            foreach ($dp[$mask] as $last => $cost) {
                for ($next = 0; $next < $n; $next++) {
                    if (($mask & (1 << $next)) !== 0) {
                        continue;
                    }

                    $nextMask = $mask | (1 << $next);
                    $candidate = $cost + $between[$last][$next];

                    if (! isset($dp[$nextMask][$next]) || $candidate < $dp[$nextMask][$next]) {
                        $dp[$nextMask][$next] = $candidate;
                    }
                }
            }
        }

        $best = null;
        for ($last = 0; $last < $n; $last++) {
            if (! isset($dp[$full][$last])) {
                continue;
            }

            $total = $dp[$full][$last] + $toDestination[$last];
            if ($best === null || $total < $best) {
                $best = $total;
            }
        }

        return $best ?? 0.0;
    }

    /**
     * تقريب حتمي للنقاط الكثيفة: أقرب جار من كل نقطة بداية، ثم تحسين 2-opt،
     * ويُؤخذ أفضل ناتج. لا يُستخدم إلا فوق `EXACT_ROUTE_MAX_POINTS`، و**لا يضمن
     * الأمثلية العالمية** — قد يزيد عن الأمثل ببضعة أمتار. يكفي عمليًا لأن نقاط
     * الاستلام كلها داخل دائرة 500 م (بوابة التقارب) فلا يتبقى إلا فرق مهمل.
     *
     * @param  array<int, array<int, float>>  $between
     * @param  array<int, float>  $toDestination
     */
    private static function approximateOpenPathMeters(int $n, array $between, array $toDestination): float
    {
        $best = null;

        for ($start = 0; $start < $n; $start++) {
            $order = self::nearestNeighbourOrder($start, $n, $between);
            $cost = self::twoOptOpenPath($order, $between, $toDestination);

            if ($best === null || $cost < $best) {
                $best = $cost;
            }
        }

        return $best ?? 0.0;
    }

    /**
     * @param  array<int, array<int, float>>  $between
     * @return array<int, int>
     */
    private static function nearestNeighbourOrder(int $start, int $n, array $between): array
    {
        $visited = [$start => true];
        $order = [$start];
        $current = $start;

        for ($step = 1; $step < $n; $step++) {
            $next = null;
            $nextCost = null;

            for ($candidate = 0; $candidate < $n; $candidate++) {
                if (isset($visited[$candidate])) {
                    continue;
                }

                $cost = $between[$current][$candidate];
                if ($nextCost === null || $cost < $nextCost) {
                    $nextCost = $cost;
                    $next = $candidate;
                }
            }

            if ($next === null) {
                break;
            }

            $visited[$next] = true;
            $order[] = $next;
            $current = $next;
        }

        return $order;
    }

    /**
     * تحسين 2-opt لترتيب مفتوح: البداية حرّة والوجهة ثابتة، فنعكس أي مقطع
     * يُقصّر المسافة الكلية. يُعيد التكلفة النهائية.
     *
     * @param  array<int, int>  $order
     * @param  array<int, array<int, float>>  $between
     * @param  array<int, float>  $toDestination
     */
    private static function twoOptOpenPath(array $order, array $between, array $toDestination): float
    {
        $n = count($order);
        $cost = self::pathMeters($order, $between, $toDestination);
        $improved = true;

        while ($improved) {
            $improved = false;

            for ($i = 0; $i < $n - 1; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $candidate = $order;
                    $segment = array_reverse(array_slice($candidate, $i, $j - $i + 1));
                    array_splice($candidate, $i, $j - $i + 1, $segment);

                    $candidateCost = self::pathMeters($candidate, $between, $toDestination);

                    if ($candidateCost + 1.0e-9 < $cost) {
                        $cost = $candidateCost;
                        $order = $candidate;
                        $improved = true;
                    }
                }
            }
        }

        return $cost;
    }

    /**
     * تكلفة ترتيب معيّن: مجموع المسافات بين النقاط المتتالية + المسافة الأخيرة
     * إلى الوجهة (بلا رجوع إلى البداية).
     *
     * @param  array<int, int>  $order
     * @param  array<int, array<int, float>>  $between
     * @param  array<int, float>  $toDestination
     */
    private static function pathMeters(array $order, array $between, array $toDestination): float
    {
        $meters = 0.0;
        $count = count($order);

        for ($i = 0; $i < $count - 1; $i++) {
            $meters += $between[$order[$i]][$order[$i + 1]];
        }

        $meters += $toDestination[$order[$count - 1]];

        return $meters;
    }

    /**
     * المسافة المستقيمة بالمتر بين نقطتين مُعطاتين كـ[خط العرض, خط الطول].
     *
     * @param  array{0: float, 1: float}  $a
     * @param  array{0: float, 1: float}  $b
     */
    private static function haversineMeters(array $a, array $b): float
    {
        return Haversine::kmBetween($a[0], $a[1], $b[0], $b[1]) * 1000.0;
    }

    /**
     * الشرائح الفعّالة كصفوف مصفوفية (لأغراض التحقق من المجموعة).
     * يمكن استثناء شريحة (أثناء التعديل) وإضافة مرشّح.
     *
     * @return array<int, array{min_distance_meters: int, max_distance_meters: int|null, fee: float}>
     */
    public static function activeTiersAsArrays(?DeliveryFeeTier $exclude = null, ?array $include = null): array
    {
        $rows = DeliveryFeeTier::query()
            ->active()
            ->when($exclude, fn ($query) => $query->whereKeyNot($exclude->getKey()))
            ->get()
            ->map(fn (DeliveryFeeTier $tier) => [
                'min_distance_meters' => (int) $tier->min_distance_meters,
                'max_distance_meters' => $tier->max_distance_meters === null
                    ? null
                    : (int) $tier->max_distance_meters,
                'fee' => (float) $tier->fee,
            ])
            ->all();

        if ($include !== null) {
            $rows[] = $include;
        }

        return $rows;
    }

    /**
     * أخطاء تفعيل التسعير: يجب وجود شريحة فعّالة واحدة على الأقل، ومجموعة سليمة.
     *
     * @return string[]
     */
    public static function enableErrors(): array
    {
        $tiers = self::activeTiersAsArrays();

        if ($tiers === []) {
            return ['لا يمكن تفعيل تسعير التوصيل دون شريحة مفعّلة واحدة على الأقل.'];
        }

        return self::validateActiveSet($tiers);
    }

    /**
     * التحقق من مجموعة الشرائح **الفعّالة** المُرتقاة.
     *
     * القواعد:
     *   1. كل شريحة: min ≥ 0، و(max = null أو max ≥ min)، و fee ≥ 0.
     *   2. المجموعة مرتّبة تبدأ من 0 متر (لا فجوة في البداية).
     *   3. لا تداخل ولا فجوة بين الشرائح المتتالية.
     *   4. الشريحة المفتوحة (max = null) يجب أن تكون الأخيرة.
     *
     * @param  array<int, array{min_distance_meters: int|string, max_distance_meters: int|string|null, fee: int|float|string}>  $tiers
     * @return string[]  قائمة أخطاء عربية (فارغة = سليم)
     */
    public static function validateActiveSet(array $tiers): array
    {
        $errors = [];

        $normalized = [];
        foreach ($tiers as $tier) {
            $min = (int) $tier['min_distance_meters'];
            $max = $tier['max_distance_meters'] === null ? null : (int) $tier['max_distance_meters'];
            $fee = (float) $tier['fee'];

            if ($min < 0) {
                $errors[] = 'حد المسافة الأدنى لا يمكن أن يكون سالبًا.';
            }

            if ($max !== null && $max < $min) {
                $errors[] = 'حد المسافة الأعلى يجب أن يكون أكبر من أو يساوي الحد الأدنى.';
            }

            if ($fee < 0) {
                $errors[] = 'قيمة الرسوم لا يمكن أن تكون سالبة.';
            }

            $normalized[] = ['min' => $min, 'max' => $max];
        }

        if ($errors !== []) {
            return array_values(array_unique($errors));
        }

        if ($normalized === []) {
            return [];
        }

        usort($normalized, fn ($a, $b) => $a['min'] <=> $b['min']);

        if ($normalized[0]['min'] !== 0) {
            $errors[] = 'أول شريحة مفعّلة يجب أن تبدأ من 0 متر.';
        }

        $count = count($normalized);
        for ($i = 0; $i < $count; $i++) {
            if ($normalized[$i]['max'] === null && $i !== $count - 1) {
                $errors[] = 'الشريحة المفتوحة (بلا حد أعلى) يجب أن تكون الأخيرة.';
            }

            if ($i === 0) {
                continue;
            }

            $prev = $normalized[$i - 1];
            $current = $normalized[$i];

            if ($prev['max'] === null) {
                $errors[] = 'توجد شريحة بعد شريحة مفتوحة — تداخل.';
                continue;
            }

            if ($current['min'] <= $prev['max']) {
                $errors[] = 'تداخل بين شرائح التوصيل المفعّلة.';
            } elseif ($current['min'] > $prev['max'] + 1) {
                $errors[] = 'توجد فجوة بين شرائح التوصيل المفعّلة.';
            }
        }

        return array_values(array_unique($errors));
    }

    private static function validCoordinates(mixed $lat, mixed $lng): bool
    {
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return false;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        // نفس نطاق `PharmacyProximityGuard::hasUsableCoordinates` حرفيًا:
        // القاعدة تقبل قيمًا خارج النطاق الجغرافي، فنفحص النطاق هنا.
        return $lat >= -90.0 && $lat <= 90.0
            && $lng >= -180.0 && $lng <= 180.0;
    }
}
