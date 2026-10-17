<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * المصدر الوحيد للحقيقة لحدّ إرسال الباركود في جلسة المسح.
 *
 * ── لماذا المفتاح هو الجلسة لا الـIP ولا المستخدم؟ ──────────────────
 * تطبيق الجوال **لا يسجّل دخولًا** (يُفوَّض بتوكن الاقتران)، فلا يوجد
 * `user_id` لبناء المفتاح عليه. والـIP غير صالح: جهازان على نفس شبكة
 * الصيدلية يشتركان فيه، فيصير الدلو مشتركًا ويُحجب مستخدم بريء. الجلسة
 * هي الوحدة الصحيحة — هي ما نريد حمايته من الإغراق.
 *
 * ── لماذا صنف مستقل؟ ────────────────────────────────────────────────
 * نفس سبب `InventoryImportThrottle`: الـlimiter يُعرَّف في
 * `bootstrap/app.php`، وأي مكان آخر يحتاج قراءة الحصة يجب أن يبني
 * **نفس المفتاح بالضبط**. لو تفرّق الحساب لانكسر العرض بصمت.
 *
 * ⚠️ `ThrottleRequests` يُجزّئ المفتاح بـ`md5(LIMITER . key)`، فمفتاح
 * الكاش الفعلي ليس النصّ الممرَّر إلى `->by()`. `cacheKey()` يعيد نفس
 * التجزئة فلا يفترق العرض عن الفرض.
 *
 * ⚠️ أي تغيير في شكل المفتاح يجب أن يمرّ من `scopeKey()` وحده.
 */
class ScanSessionThrottle
{
    /** اسم الـlimiter كما يُمرَّر إلى الـmiddleware: `throttle:scan-session`. */
    public const LIMITER = 'scan-session';

    /**
     * المفتاح المنطقي (قبل التجزئة).
     *
     * جلسة بلا معرّف (مسار خاطئ) تُجمَّع في دلو واحد — أسوأ حالة هي حدّ
     * مشترك لطلبات غير صالحة، وهو مقبول.
     */
    public static function scopeKey(?string $sessionId): string
    {
        return 'scan|'.(string) $sessionId;
    }

    /** السقف المطبَّق فعليًّا على الجلسة. */
    public static function limit(): int
    {
        return max(1, (int) config('scan_session.barcode_per_minute', 60));
    }

    /**
     * مفتاح الكاش الحقيقي — مطابق تمامًا لما يحسبه ThrottleRequests.
     * يُستخدم للقراءة فقط (attempts/availableIn) لا للكتابة.
     */
    public static function cacheKey(?string $sessionId): string
    {
        return md5(self::LIMITER.self::scopeKey($sessionId));
    }

    /** عدد العمليات المستهلكة في النافذة الحالية. */
    public static function used(?string $sessionId): int
    {
        return RateLimiter::attempts(self::cacheKey($sessionId));
    }

    /** المتبقي قبل الحجب. */
    public static function remaining(?string $sessionId): int
    {
        return max(0, self::limit() - self::used($sessionId));
    }

    /** الثواني حتى تصفير النافذة (0 = لا عدّاد نشط بعد). */
    public static function resetIn(?string $sessionId): int
    {
        return RateLimiter::availableIn(self::cacheKey($sessionId));
    }

    /**
     * حالة مختصرة جاهزة للعرض.
     *
     * @return array{limit:int, used:int, remaining:int, reset_in:int, exhausted:bool}
     */
    public static function status(?string $sessionId): array
    {
        $limit = self::limit();
        $used = self::used($sessionId);

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'reset_in' => self::resetIn($sessionId),
            'exhausted' => $used >= $limit,
        ];
    }
}
