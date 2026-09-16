<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * إلزام تغيير كلمة المرور المؤقتة قبل استخدام اللوحة.
 *
 * الخلفية: عند إنشاء صيدلية من لوحة الأدمن (أو إعادة تعيين بياناتها) تُولَّد
 * كلمة مرور مؤقتة وتُسلَّم شفهياً/برسالة، ويُرفع العلم users.must_change_password.
 *
 * 🔴 كانت هذه الحماية موجودة في مسار الـAPI فقط، ومسار الويب كان يتجاهل العلم
 * تماماً — أي أن صيدلية بكلمة مرور مؤقتة معروفة تستطيع استخدام اللوحة كاملة
 * دون تغييرها. هذا الوسيط يسدّ تلك الثغرة على الويب.
 *
 * ملاحظة ترتيب: يُسجَّل **قبل** profile.complete، لأن تغيير كلمة المرور إجراء
 * أمني لا يجوز تأجيله خلف إكمال البيانات.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            // المسارات المسموح بها أثناء فرض التغيير: صفحة التغيير نفسها + الخروج.
            $allowedRoutes = [
                'pharmacy.password.change.show',
                'pharmacy.password.change',
                'logout',
            ];

            if (! in_array($request->route()?->getName(), $allowedRoutes, true)) {
                return redirect()->route('pharmacy.password.change.show')
                    ->with('warning', __('pharmacy.password.change.required_message'));
            }
        }

        return $next($request);
    }
}
