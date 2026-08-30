<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\PharmacyHour;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
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
            'password' => ['required', 'string', 'min:8', 'confirmed', Password::min(8)],
            'password_confirmation' => ['required', 'string'],
            'hours' => ['required', 'array'],
        ]);

        // تحقق من مواعيد العمل — يجب تحديد يوم واحد على الأقل
        $hoursData = $request->input('hours', []);
        $hasOpenDay = false;

        foreach ($hoursData as $dayOfWeek => $hourData) {
            $isClosed = ! empty($hourData['is_closed']);
            $openTime = $hourData['open_time'] ?? null;
            $closeTime = $hourData['close_time'] ?? null;

            if (! $isClosed && ! empty($openTime) && ! empty($closeTime)) {
                $hasOpenDay = true;
            }
        }

        if (! $hasOpenDay) {
            return redirect()->route('pharmacy.profile.complete.show')
                ->withErrors(['hours' => __('pharmacy.profile.complete.hours_required')])
                ->withInput();
        }

        // تحديث كلمة المرور
        $user->update([
            'password' => Hash::make($validated['password']),
            'email' => ! empty($validated['email']) ? $validated['email'] : $user->email,
        ]);

        // تحديث بيانات الصيدلية
        $pharmacy->update([
            'phone_number' => $validated['phone_number'],
            'address' => $validated['address'],
            'region' => $validated['region'],
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
        ]);
        // C1: profile_completed_at يُضبط صراحة (الحقول الحساسة تُدار عبر direct assignment
        // حتى لو بقيت في $fillable — لمنع الـ user من التلاعب بها عبر payload).
        $pharmacy->profile_completed_at = now();
        $pharmacy->save();

        // حفظ ساعات العمل
        foreach ($hoursData as $dayOfWeek => $hourData) {
            $isClosed = ! empty($hourData['is_closed']);

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