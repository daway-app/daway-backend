<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Models\User;

/**
 * نقطة حلّ ملكية الصيدلية الوحيدة (نمط C1).
 *
 * كل متحكمات API الخاصة بالصيدلية تحصل على صيدلية المستخدم الحالي
 * عبر هذا الصنف بدلاً من تكرار Pharmacy::where('user_id', ...)->first().
 *
 * ملاحظة مهمة: عند غياب الصيدلية تختلف الاستجابات الحالية بين المتحكمات
 * (JSON 404 برسالة «الصيدلية غير موجودة» في الغالبية، أو abort 403 في
 * استفسارات/تقييمات الصيدلية) — لذلك تُرجع forUser() القيمة null وتُترك
 * معالجة الغياب لكل متحكم بشكلها الحالي، ولا يُوحَّد السلوك في هذه الخطوة.
 */
final class PharmacyContext
{
    /**
     * صيدلية المستخدم الحالي أو null إن لم توجد.
     *
     * @param  array<int, string>  $with  علاقات تُحمّل استباقياً (مثل ['hours'])
     */
    public static function forUser(User $user, array $with = []): ?Pharmacy
    {
        return Pharmacy::with($with)->where('user_id', $user->id)->first();
    }

    /**
     * صيدلية المستخدم الحالي أو إجهاض 404 إن لم توجد.
     *
     * غير مستخدمة في المتحكمات حالياً: إجهاض 404 هنا يُعاد تشكيله بواسطة
     * معالج الاستثناءات إلى الرسالة الثابتة «غير موجود»، بما يخالف الردود
     * الحالية («الصيدلية غير موجودة» أو 403). تُترك للتوحيد لاحقاً بعد
     * الاتفاق على شكل رد واحد.
     */
    public static function requireForUser(User $user): Pharmacy
    {
        return self::forUser($user) ?? abort(404, 'الصيدلية غير موجودة');
    }
}
