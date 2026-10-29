<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * فحص دور المستخدم — **API فقط** بعد حذف واجهة Blade.
 *
 * كان الحارس يملك فرعًا كاملًا لإعادة التوجيه إلى صفحات الويب
 * (`login.show` / `dashboard` / `pharmacy.dashboard.index`). بعد إزالة
 * الواجهة لم يعد لتلك المسارات وجود، فأي وصول إليها يرمي
 * `RouteNotFoundException` ويحوّل 403 متوقعًا إلى 500.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        // 401 عبر `AuthenticationException` لا `abort(401)`: المعالج في
        // bootstrap يترجمها إلى `{success:false, message:'يجب تسجيل الدخول'}`
        // بنفس شكل بقية أخطاء API. (`abort(401)` ينتج HttpException عامة
        // برسالة فارغة لا يلتقطها معالج المصادقة.)
        if (! $user) {
            throw new AuthenticationException;
        }

        if ($user->role !== $role) {
            // AccessDeniedHttpException لا HttpException عامة: معالج
            // الاستثناءات في bootstrap يلتقط هذا النوع تحديدًا ويعيد
            // `{success:false, message}` — الشكل الموحّد لعقود API.
            // `abort(403)` كان يرمي استثناء عام برسالة فارغة يفوّته
            // المعالج فيصل العميل `{"message":""}` بلا `success`.
            throw new AccessDeniedHttpException;
        }

        return $next($request);
    }
}
