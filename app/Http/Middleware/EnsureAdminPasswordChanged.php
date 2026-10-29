<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Blocks administrative work until an initial admin password is changed. */
class EnsureAdminPasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // This middleware is intentionally inert for non-admin callers, even if
        // a route is accidentally shared with another authenticated role.
        if ($user?->role !== 'admin') {
            return $next($request);
        }

        // Read the persisted value on every request. Do not trust model/session
        // snapshots, token abilities, or client-side state for this decision.
        $mustChangePassword = $user->newQuery()
            ->whereKey($user->getKey())
            ->value('must_change_password');

        if ((bool) $mustChangePassword) {
            return response()->json([
                'success' => false,
                'code' => 'PASSWORD_CHANGE_REQUIRED',
                'message' => 'يجب تغيير كلمة المرور الأولية قبل استخدام لوحة الإدارة.',
            ], 403);
        }

        return $next($request);
    }
}
