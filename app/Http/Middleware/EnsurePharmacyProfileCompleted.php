<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePharmacyProfileCompleted
{
    /**
     * Force a pharmacy user with an initial password (must_change_password)
     * to complete their profile (name, map location, new password) before
     * accessing any other pharmacy page.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->role === 'pharmacy' && $user->must_change_password) {
            if (! $request->routeIs('pharmacy.profile.edit', 'pharmacy.profile.update')) {
                return redirect()
                    ->route('pharmacy.profile.edit')
                    ->with('notice', __('pharmacy.profile.must_complete'));
            }
        }

        return $next($request);
    }
}