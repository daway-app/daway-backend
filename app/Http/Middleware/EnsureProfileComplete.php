<?php

namespace App\Http\Middleware;

use App\Models\Pharmacy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfileComplete
{
    /**
     * إذا كان المستخدم صيدلي ولم يكمل بياناته عند أول دخول،
     * أعد توجيهه إلى صفحة إكمال الملف.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->role === 'pharmacy') {
            // 🔴 أداء (Phase 2): السطر كان `$user->pharmacy` — علاقة HasOne كسولة
            // تُطلق `select * from pharmacies where user_id = ?` على **كل** صفحة
            // صيدلية، مع أن هذا الوسيط لا يقرأ إلا `profile_completed_at`.
            //
            // الآن:
            //  - نقرأ العمودين المطلوبين فقط (id + profile_completed_at) بدل `*`.
            //  - نثبّت النتيجة على العلاقة عبر setRelation() حتى لا تُعاد القراءة
            //    إن لمستها طبقة أخرى في نفس الطلب (نفس ما يعيده Laravel للمستخدم
            //    لو حُمّلت العلاقة مسبقًا).
            //
            // ⚠️ لا يجوز حذف هذا الاستعلام: هو الحارس الوحيد الذي يمنع حبس
            //    الصيدلية في حلقة إعادة توجيه. انظر PharmacyProfileCompletionTest.
            $pharmacy = $user->relationLoaded('pharmacy')
                ? $user->pharmacy
                : tap(
                    Pharmacy::query()
                        ->where('user_id', $user->getKey())
                        ->first(['id', 'profile_completed_at']),
                    fn ($loaded) => $user->setRelation('pharmacy', $loaded)
                );

            if ($pharmacy && ! $pharmacy->profileCompleted()) {
                $allowedRoutes = [
                    'pharmacy.profile.complete.show',
                    'pharmacy.profile.complete',
                    'logout',
                ];

                if (! in_array($request->route()?->getName(), $allowedRoutes, true)) {
                    return redirect()->route('pharmacy.profile.complete.show')
                        ->with('warning', __('pharmacy.profile.complete.required_message'));
                }
            }
        }

        return $next($request);
    }
}
