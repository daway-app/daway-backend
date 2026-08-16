<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Ensure the authenticated user has the required role.
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login.show')->with('error', __('pharmacy.access_denied'));
        }

        if ($user->role === $role) {
            return $next($request);
        }

        // Admin tries to access a pharmacy route → send to the admin dashboard
        if ($user->role === 'admin') {
            return redirect()->route('dashboard.index')->with('error', __('pharmacy.access_denied'));
        }

        // Pharmacy tries to access an admin route → send to the pharmacy dashboard
        if ($user->role === 'pharmacy') {
            return redirect()->route('pharmacy.dashboard.index')->with('error', __('pharmacy.access_denied'));
        }

        return redirect()->route('login.show')->with('error', __('pharmacy.access_denied'));
    }
}
