<?php

namespace App\Http\Middleware;

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
            $pharmacy = $user->pharmacy;

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
