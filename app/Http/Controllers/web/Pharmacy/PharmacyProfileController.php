<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\PharmacyHour;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class PharmacyProfileController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth'); // Ensure user is authenticated
        // Add middleware to check if the user is a pharmacy
        $this->middleware(function ($request, $next) {
            if (Auth::check() && Auth::user()->role === 'pharmacy') {
                return $next($request);
            }

            return redirect('/')->with('error', __('pharmacy.access_denied'));
        });
    }

    /**
     * Show the form for editing the authenticated pharmacy's profile.
     *
     * @return Response
     */
    public function edit()
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->with('hours')->firstOrFail();

        // Prepare data for working hours
        $daysOfWeek = [
            'Sunday' => __('pharmacy.profile.days.Sunday'),
            'Monday' => __('pharmacy.profile.days.Monday'),
            'Tuesday' => __('pharmacy.profile.days.Tuesday'),
            'Wednesday' => __('pharmacy.profile.days.Wednesday'),
            'Thursday' => __('pharmacy.profile.days.Thursday'),
            'Friday' => __('pharmacy.profile.days.Friday'),
            'Saturday' => __('pharmacy.profile.days.Saturday'),
        ];

        // Map existing hours to a more accessible format
        $pharmacyHours = $pharmacy->hours->keyBy('day_of_week');

        return view('pharmacy.profile.edit', compact('pharmacy', 'daysOfWeek', 'pharmacyHours'));
    }

    /**
     * Update the authenticated pharmacy's profile in storage.
     *
     * @return Response
     */
    public function update(Request $request)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        $request->validate([
            'pharmacy_name' => ['required', 'string', 'max:255'],
            'phone_number' => ['required', 'string', 'max:20'],
            'address' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'logo' => ['nullable', 'image', 'max:2048'], // Max 2MB
            'hours.*.open_time' => ['nullable', 'date_format:H:i'],
            'hours.*.close_time' => ['nullable', 'date_format:H:i', 'after:hours.*.open_time'],
            'hours.*.is_closed' => ['boolean'],
        ]);

        // Handle logo upload
        if ($request->hasFile('logo')) {
            // Delete old logo if exists
            if ($pharmacy->logo) {
                Storage::disk('public')->delete($pharmacy->logo);
            }
            $logoPath = $request->file('logo')->store('pharmacy_logos', 'public');
            $pharmacy->logo = $logoPath;
        }

        // Update pharmacy details
        $pharmacy->update([
            'pharmacy_name' => $request->pharmacy_name,
            'phone_number' => $request->phone_number,
            'address' => $request->address,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
        ]);

        // Update working hours
        foreach ($request->input('hours', []) as $dayOfWeek => $hourData) {
            $pharmacyHour = PharmacyHour::firstOrNew([
                'pharmacy_id' => $pharmacy->id,
                'day_of_week' => $dayOfWeek,
            ]);

            $pharmacyHour->is_closed = $hourData['is_closed'] ?? false;
            $pharmacyHour->open_time = $pharmacyHour->is_closed ? null : $hourData['open_time'];
            $pharmacyHour->close_time = $pharmacyHour->is_closed ? null : $hourData['close_time'];
            $pharmacyHour->save();
        }

        return redirect()->route('pharmacy.profile.edit')->with('success', __('pharmacy.profile.success'));
    }
}
