<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PharmacyChangePasswordRequest;
use App\Http\Requests\Api\PharmacyProfileRequest;
use App\Models\Pharmacy;
use App\Models\PharmacyHour;
use App\Services\PharmacyContext;
use App\Support\Image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PharmacyProfileController extends Controller
{
    private const DAY_MAP = [
        'sat' => 'Saturday',
        'sun' => 'Sunday',
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
    ];

    /**
     * عرض بروفايل الصيدلية (البيانات + ساعات العمل).
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user, ['hours']);

        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->payload($pharmacy),
        ]);
    }

    /**
     * تحديث بروفايل الصيدلية (البيانات + استبدال كامل لساعات العمل).
     */
    public function update(PharmacyProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user, ['hours']);

        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $data = $request->validated();

        // تحديث المستخدم + الصيدلية + ساعات العمل في معاملة واحدة
        DB::transaction(function () use ($data, $user, $pharmacy) {
            if (array_key_exists('logo_url', $data)) {
                $data['logo'] = $data['logo_url'];
                unset($data['logo_url']);
            }

            if (array_key_exists('name', $data)) {
                $data['pharmacy_name'] = $data['name'];
                unset($data['name']);
                $user->name = $data['pharmacy_name'];
            }

            if (array_key_exists('phone', $data)) {
                $user->phone = $data['phone'];
                // مزامنة الرقم مع سطر الصيدلية لأن الرد الرسمي يقرأ pharmacies.phone_number
                $pharmacy->phone_number = $data['phone'];
            }

            if ($user->isDirty(['name', 'phone'])) {
                $user->save();
            }

            $workingHours = $data['working_hours'] ?? null;
            unset($data['working_hours']);

            if (! empty($data)) {
                $pharmacy->update(array_intersect_key($data, array_flip([
                    'pharmacy_name',
                    'address',
                    'region',
                    'latitude',
                    'longitude',
                    'phone_number',
                    'logo',
                ])));
            }

            if (is_array($workingHours)) {
                $this->replaceWorkingHours($pharmacy, $workingHours);
            }
        });

        // توحيد مع الويب: حين تكتمل بيانات الصيدلية (هاتف + عنوان + منطقة + موقع
        // + يوم دوام مفتوح واحد على الأقل) نضبط profile_completed_at مرة واحدة —
        // حتى لا تنحبس الصيدلية المسجّلة من الموبايل بصفحة الإكمال عند دخولها الويب.
        // C1: الحقل الحساس يُضبط صراحةً (خارج $fillable).
        $fresh = $pharmacy->fresh(['hours']);
        if ($fresh->profile_completed_at === null && $this->isProfileComplete($fresh)) {
            $fresh->profile_completed_at = now();
            $fresh->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الملف الشخصي بنجاح',
            'data' => $this->payload($fresh),
        ]);
    }

    /**
     * هل اكتملت بيانات الصيدلية؟ نفس شروط صفحة الإكمال على الويب
     * (بدون كلمة المرور — يديرها must_change_password عبر change-password).
     */
    private function isProfileComplete(Pharmacy $pharmacy): bool
    {
        if (empty($pharmacy->phone_number) || empty($pharmacy->address) || empty($pharmacy->region)) {
            return false;
        }

        if ($pharmacy->latitude === null || $pharmacy->longitude === null) {
            return false;
        }

        // يوم دوام مفتوح واحد على الأقل
        return $pharmacy->hours->contains(fn ($hour) => ! $hour->is_closed && $hour->open_time && $hour->close_time);
    }

    /**
     * تغيير كلمة المرور للصيدلية وإلغاء إجبارية التغيير.
     */
    public function changePassword(PharmacyChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403, 'غير مصرح');

        $user->password = Hash::make($request->input('password'));
        $user->must_change_password = false;
        $user->save();

        // H-13: إبطال كل التوكنات القديمة (بما فيها الحالي) — يتوقع التطبيق إعادة تسجيل دخول
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم تغيير كلمة المرور بنجاح، يرجى تسجيل الدخول مرة أخرى',
        ]);
    }

    /**
     * استبدال كامل: اليوم المرسل بمعلومات صالحة = مفتوح، والغائب/الفارغ = مغلق.
     */
    private function replaceWorkingHours(Pharmacy $pharmacy, array $workingHours): void
    {
        foreach (self::DAY_MAP as $short => $full) {
            $day = $workingHours[$short] ?? null;
            $isClosed = ! is_array($day) || empty($day['open']) || empty($day['close']);

            $hour = PharmacyHour::firstOrNew([
                'pharmacy_id' => $pharmacy->id,
                'day_of_week' => $full,
            ]);

            $hour->is_closed = $isClosed;
            $hour->open_time = $isClosed ? null : $day['open'];
            $hour->close_time = $isClosed ? null : $day['close'];
            $hour->save();
        }
    }

    private function payload(Pharmacy $pharmacy): array
    {
        $hours = [];

        foreach (self::DAY_MAP as $short => $full) {
            $hour = $pharmacy->hours->firstWhere('day_of_week', $full);
            $isOpen = $hour && ! $hour->is_closed && $hour->open_time && $hour->close_time;

            $hours[$short] = [
                'open' => $isOpen ? $hour->open_time->format('H:i') : null,
                'close' => $isOpen ? $hour->close_time->format('H:i') : null,
            ];
        }

        return [
            'type' => 'pharmacy',
            'pharmacy_id' => $pharmacy->pharmacy_custom_id,
            'name' => $pharmacy->pharmacy_name,
            'phone' => $pharmacy->phone_number,
            'logo_url' => Image::url($pharmacy->logo),
            'latitude' => $pharmacy->latitude !== null ? (float) $pharmacy->latitude : null,
            'longitude' => $pharmacy->longitude !== null ? (float) $pharmacy->longitude : null,
            'address' => $pharmacy->address,
            'working_hours' => $hours,
        ];
    }
}
