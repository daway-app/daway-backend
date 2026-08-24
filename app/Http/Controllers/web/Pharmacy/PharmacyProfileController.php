<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\PharmacyHour;
use App\Support\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class PharmacyProfileController extends Controller
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

    public function edit(): View
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->with('hours')->firstOrFail();

        $daysOfWeek = [
            'Sunday' => __('pharmacy.profile.days.Sunday'),
            'Monday' => __('pharmacy.profile.days.Monday'),
            'Tuesday' => __('pharmacy.profile.days.Tuesday'),
            'Wednesday' => __('pharmacy.profile.days.Wednesday'),
            'Thursday' => __('pharmacy.profile.days.Thursday'),
            'Friday' => __('pharmacy.profile.days.Friday'),
            'Saturday' => __('pharmacy.profile.days.Saturday'),
        ];

        $pharmacyHours = $pharmacy->hours->keyBy('day_of_week');

        return view('pharmacy.profile.edit', compact('pharmacy', 'daysOfWeek', 'pharmacyHours'));
    }

    public function update(Request $request)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        // تنظيف بيانات ساعات العمل: تحويل القيم الفارغة إلى null قبل التحقق
        $hoursInput = $request->input('hours', []);
        foreach ($hoursInput as $day => &$hourData) {
            $hourData['open_time'] = ! empty($hourData['open_time']) ? $hourData['open_time'] : null;
            $hourData['close_time'] = ! empty($hourData['close_time']) ? $hourData['close_time'] : null;
            $hourData['is_closed'] = ! empty($hourData['is_closed']);
        }
        unset($hourData);
        $request->merge(['hours' => $hoursInput]);

        $request->validate([
            'pharmacy_name' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:150'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        // كل الحقول اختيارية: نحدّث فقط ما أُرسل ولا نمسح البيانات الموجودة
        $data = collect($request->only(['pharmacy_name', 'phone_number', 'address', 'region', 'latitude', 'longitude']))
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();

        if (! empty($data)) {
            $pharmacy->update($data);
        }

        if ($request->hasFile('logo')) {
            Cloudinary::deleteLocal($pharmacy->logo);
            $pharmacy->logo = Cloudinary::upload($request->file('logo'), 'pharmacy_logos');
            $pharmacy->save();
        }

        // تغيير كلمة المرور اختياري: يُطبق فقط عند تعبئة حقل كلمة المرور الجديدة
        $newPassword = (string) $request->input('password');
        if ($newPassword !== '') {
            $request->validate([
                'current_password' => ['required', 'string'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
                'password_confirmation' => ['required', 'string'],
            ]);

            if (! Hash::check($request->input('current_password'), $user->password)) {
                return redirect()->route('pharmacy.profile.edit')
                    ->withErrors(['current_password' => __('pharmacy.profile.password_change.wrong_current')])
                    ->withInput();
            }

            $user->update(['password' => Hash::make($newPassword)]);
        }

        // حفظ ساعات العمل بدون قواعد تحقق معقدة
        foreach ($request->input('hours', []) as $dayOfWeek => $hourData) {
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

        return redirect()->route('pharmacy.profile.edit')->with('success', __('pharmacy.profile.success'));
    }
}
