<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\PharmacyHour;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PharmacyProfileCompletionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(function ($request, $next) {
            if (Auth::check() && Auth::user()->role === 'pharmacy') {
                return $next($request);
            }

            return redirect('/')->with('error', __('pharmacy.access_denied'));
        });
    }

    /**
     * Show the first-login profile completion form.
     */
    public function show(): View
    {
        $user = Auth::user();
        $pharmacy = $user->pharmacy;

        $daysOfWeek = [
            'Sunday' => __('pharmacy.profile.days.Sunday'),
            'Monday' => __('pharmacy.profile.days.Monday'),
            'Tuesday' => __('pharmacy.profile.days.Tuesday'),
            'Wednesday' => __('pharmacy.profile.days.Wednesday'),
            'Thursday' => __('pharmacy.profile.days.Thursday'),
            'Friday' => __('pharmacy.profile.days.Friday'),
            'Saturday' => __('pharmacy.profile.days.Saturday'),
        ];

        return view('pharmacy.profile.complete', compact('pharmacy', 'daysOfWeek'));
    }

    /**
     * Save the completed profile data.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $pharmacy = $user->pharmacy;

        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'max:20'],
            'address' => ['required', 'string', 'max:255'],
            'region' => ['required', 'string', 'max:150'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'hours' => ['required', 'array'],
            'hours.*.open_time' => ['nullable', 'date_format:H:i'],
            'hours.*.close_time' => ['nullable', 'date_format:H:i', 'after:hours.*.open_time'],
            'hours.*.is_closed' => ['boolean'],
        ]);

        // يجب تحديد مواعيد عمل يوم واحد على الأقل
        $hasOpenDay = collect($validated['hours'])->contains(function ($hour) {
            return empty($hour['is_closed']) && ! empty($hour['open_time']) && ! empty($hour['close_time']);
        });

        if (! $hasOpenDay) {
            return back()->withErrors(['hours' => __('pharmacy.profile.complete.hours_required')])->withInput();
        }

        // تحديث بيانات المستخدم (البريد اختياري)
        if (! empty($validated['email'])) {
            $user->update(['email' => $validated['email']]);
        }

        // تحديث بيانات الصيدلية
        $pharmacy->update([
            'phone_number' => $validated['phone_number'],
            'address' => $validated['address'],
            'region' => $validated['region'],
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'profile_completed_at' => now(),
        ]);

        // حفظ ساعات العمل
        foreach ($validated['hours'] as $dayOfWeek => $hourData) {
            $isClosed = $hourData['is_closed'] ?? false;

            PharmacyHour::updateOrCreate(
                ['pharmacy_id' => $pharmacy->id, 'day_of_week' => $dayOfWeek],
                [
                    'is_closed' => $isClosed,
                    'open_time' => $isClosed ? null : ($hourData['open_time'] ?? null),
                    'close_time' => $isClosed ? null : ($hourData['close_time'] ?? null),
                ]
            );
        }

        return redirect()->route('pharmacy.dashboard.index')->with('success', __('pharmacy.profile.complete.success'));
    }
}
