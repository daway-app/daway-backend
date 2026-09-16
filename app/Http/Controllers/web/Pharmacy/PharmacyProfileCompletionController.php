<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\PharmacyHour;
use App\Support\Cloudinary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PharmacyProfileCompletionController extends Controller
{
    /**
     * مركز الخريطة الافتراضي (غزة) — المصدر الوحيد للحقيقة.
     * يُستخدم في عرض النموذج وفي الرجوع إليه عند غياب إحداثيات من العميل.
     *
     * 🔴 سياق العطل: نموذج إكمال البيانات كان يشترط latitude/longitude، لكن
     * الكاتب الوحيد للحقلين هو applyChange() في pharmacy_hub.js — وهي لا تُستدعى
     * إلا من داخل openLocationModal()، التي تخرج فوراً لأن #locationModal
     * و#mapConfirmModal موجودان في edit.blade.php **فقط** وغائبان عن complete.blade.php.
     * ⇒ الحقلان يبقيان فارغين ⇒ التحقق يفشل دائماً ⇒ profile_completed_at لا يُضبط
     *   أبداً ⇒ EnsureProfileComplete يحبس الصيدلية في حلقة إعادة توجيه لا نهائية.
     */
    public const DEFAULT_LATITUDE = 31.5016;

    public const DEFAULT_LONGITUDE = 34.4668;

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

        $defaultLatitude = self::DEFAULT_LATITUDE;
        $defaultLongitude = self::DEFAULT_LONGITUDE;

        return view('pharmacy.profile.complete', compact('pharmacy', 'daysOfWeek', 'defaultLatitude', 'defaultLongitude'));
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
            // الموقع اختياري: النموذج لا يستطيع تعبئته (انظر شرح الثابت أعلاه).
            // اشتراطه كان يمنع أي صيدلية من إكمال بياناتها عبر الويب إطلاقاً.
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            // كلمة المرور اختيارية — الصيدلية اختارت كلمة مرورها عند التسجيل
            'password' => ['nullable', 'string', 'min:8', 'confirmed', Password::min(8)],
            'password_confirmation' => ['nullable', 'string'],
            'hours' => ['required', 'array'],
            // شعار الصيدلية — نفس قواعد مسار تعديل الملف (jpg/png/webp حتى 2MB)
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:2048'],
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

        // تحديث كلمة المرور فقط إذا أرسلت (اختيارية)
        if (! empty($validated['password'])) {
            $user->update([
                'password' => Hash::make($validated['password']),
                'email' => ! empty($validated['email']) ? $validated['email'] : $user->email,
            ]);

            // H-13: إبطال توكنات API الصادرة للحساب بعد تعيين كلمة المرور
            $user->tokens()->delete();

            // 🔴 عند تعيين كلمة مرور من هنا، يسقط علم الإلزام — وإلا بقي true
            // للأبد فطارد الصيدلية في كل دخول (ووسيط password.changed يمنعها
            // من الوصول للوحة كاملة). إكمال الملف نفسه يمر عبر مسار مسموح.
            if ($user->must_change_password) {
                $user->must_change_password = false;
                $user->save();
            }
        } else {
            $user->update([
                'email' => ! empty($validated['email']) ? $validated['email'] : $user->email,
            ]);
        }

        // تحديث بيانات الصيدلية
        // الموقع: نأخذ ما أرسله العميل إن وُجد، وإلا نرجع لمركز الخريطة الافتراضي
        // (نفس القيمة المعروضة في النموذج) — كي لا يمنع غيابه إكمال البيانات نهائياً.
        $pharmacy->update([
            'phone_number' => $validated['phone_number'],
            'address' => $validated['address'],
            'region' => $validated['region'],
            'latitude' => $validated['latitude'] ?? self::DEFAULT_LATITUDE,
            'longitude' => $validated['longitude'] ?? self::DEFAULT_LONGITUDE,
        ]);

        // شعار الصيدلية (اختياري) — رفع إلى Cloudinary وحذف القديم، مع مزامنة
        // أفاتار المستخدم كي يظهر في السايدبار (نفس منطق تعديل الملف)
        if ($request->hasFile('logo')) {
            Cloudinary::deleteLocal($pharmacy->logo);
            $pharmacy->logo = Cloudinary::upload($request->file('logo'), 'pharmacy_logos');
            $pharmacy->save();

            if ($pharmacy->user) {
                $pharmacy->user->avatar = $pharmacy->logo;
                $pharmacy->user->save();
            }
        }
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