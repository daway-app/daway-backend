<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * المصدر الوحيد للحقيقة لحدّ معدل الاستيراد الجماعي.
 *
 * لماذا هذا الصنف موجود؟
 *  - الـ limiter يُعرَّف في `bootstrap/app.php`، بينما الواجهة تحتاج أن تعرف
 *    الحصة المتبقية لعرضها للصيدلي. لو حسب كل مكان المفتاح بنفسه لتفرّقا عند
 *    أول تعديل، ولانكسر العرض بصمت (يُظهر رصيداً لا يطابق ما يفرضه الخادم).
 *  - `Illuminate\Routing\Middleware\ThrottleRequests` **يُجزّئ** المفتاح:
 *        md5($limiterName . $limit->key)      // لأن $shouldHashKeys = true
 *    فمفتاح الكاش الفعلي ليس النص الذي نمرّره إلى `->by()`.
 *    `cacheKey()` هنا يعيد نفس التجزئة بالضبط، فلا يفترق العرض عن الفرض.
 *
 * ⚠️ أي تغيير في شكل المفتاح يجب أن يمرّ من `scopeKey()` وحده.
 */
class InventoryImportThrottle
{
    /** اسم الـ limiter كما يُمرَّر إلى الـ middleware: `throttle:inventory-import`. */
    public const LIMITER = 'inventory-import';

    /** حد الزائر غير المسجَّل (لا ينطبق عملياً — المسار محميّ بـ auth، لكن نُبقيه صريحاً). */
    public const GUEST_PER_MINUTE = 5;

    /**
     * المفتاح المنطقي (قبل التجزئة) الذي يُبنى داخل الـ limiter.
     *
     * المفتاح = user_id + pharmacy_id وليس IP: خلف موازن Render يشترك كثير من
     * المستخدمين في نفس IP، فالمفتاح المعتمد على IP يصبح دلوًا مشتركًا يحجب
     * مستخدمين أبرياء. الحساب هو الوحدة الصحيحة للحد هنا.
     */
    public static function scopeKey(?User $user, ?string $ip = null): string
    {
        if ($user === null) {
            return 'ip|'.(string) $ip;
        }

        return 'import|'.$user->id.'|'.(int) ($user->pharmacy?->id ?? 0);
    }

    /** السقف المطبَّق فعلياً على هذا المستخدم. */
    public static function limit(?User $user): int
    {
        if ($user === null) {
            return self::GUEST_PER_MINUTE;
        }

        return max(1, (int) config('inventory_import.rate_limit_per_hour', 10));
    }

    /**
     * مفتاح الكاش الحقيقي — مطابق تماماً لما يحسبه ThrottleRequests.
     * نستخدمه للقراءة فقط (attempts/availableIn) لا للكتابة.
     */
    public static function cacheKey(?User $user, ?string $ip = null): string
    {
        return md5(self::LIMITER.self::scopeKey($user, $ip));
    }

    /** عدد العمليات المستهلكة في النافذة الحالية. */
    public static function used(?User $user, ?string $ip = null): int
    {
        return RateLimiter::attempts(self::cacheKey($user, $ip));
    }

    /** المتبقي قبل الحجب. */
    public static function remaining(?User $user, ?string $ip = null): int
    {
        return max(0, self::limit($user) - self::used($user, $ip));
    }

    /** الثواني حتى تصفير النافذة (0 = لا يوجد عدّاد نشط بعد). */
    public static function resetIn(?User $user, ?string $ip = null): int
    {
        return RateLimiter::availableIn(self::cacheKey($user, $ip));
    }

    /**
     * حالة مختصرة جاهزة للعرض في الواجهة.
     *
     * @return array{limit:int, used:int, remaining:int, reset_in:int, exhausted:bool}
     */
    public static function status(?User $user, ?string $ip = null): array
    {
        $limit = self::limit($user);
        $used = self::used($user, $ip);

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'reset_in' => self::resetIn($user, $ip),
            'exhausted' => $used >= $limit,
        ];
    }
}
