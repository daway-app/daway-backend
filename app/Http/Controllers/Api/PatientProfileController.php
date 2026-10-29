<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PatientChangePasswordRequest;
use App\Http\Requests\Api\PatientProfileRequest;
use App\Support\Image;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PatientProfileController extends Controller
{
    /**
     * عرض بروفايل المريض.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        return response()->json([
            'success' => true,
            'data' => $this->payload($user),
        ]);
    }

    /**
     * تحديث بروفايل المريض.
     */
    public function update(PatientProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $data = $request->validated();

        // registration-only: أي أثر لهذين الحقلين يُتجاهل هنا دفاعاً عن العقد
        unset($data['terms_accepted'], $data['terms_accepted_at']);

        if (array_key_exists('avatar_url', $data)) {
            $data['avatar'] = $data['avatar_url'];
            unset($data['avatar_url']);
        }

        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الملف الشخصي بنجاح',
            'data' => $this->payload($user->fresh()),
        ]);
    }

    /**
     * تغيير كلمة مرور المريض.
     *
     * نظير الويب `PUT /profile/password` (كان يعمل لأي مستخدم مصادَق) — كان
     * مفقودًا من API (تغيير كلمة المرور متاح للصيدلية والأدمن فقط).
     */
    public function changePassword(PatientChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $data = $request->validated();

        // 1) كلمة المرور الحالية يجب أن تطابق — حارس ضد جلسة/توكن مسروق.
        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'كلمة المرور الحالية غير صحيحة.',
                'errors' => ['current_password' => ['كلمة المرور الحالية غير صحيحة.']],
            ], 422);
        }

        // 2) لا تُقبل نفس كلمة المرور كقيمة جديدة.
        if (Hash::check($data['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'كلمة المرور الجديدة مطابقة للحالية.',
                'errors' => ['password' => ['كلمة المرور الجديدة مطابقة للحالية.']],
            ], 422);
        }

        $user->password = Hash::make($data['password']);
        $user->save();

        // 3) إبطال كل التوكنات (بما فيها الحالي) — العميل يعيد تسجيل الدخول.
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم تغيير كلمة المرور بنجاح، يرجى تسجيل الدخول مرة أخرى.',
        ]);
    }

    private function payload($user): array
    {
        return [
            'type' => 'patient',
            'name' => $user->name,
            'phone' => $user->phone,
            'avatar_url' => Image::url($user->avatar),
            'age' => $user->birth_date ? $user->birth_date->age : null,
            'birth_date' => $user->birth_date ? Carbon::parse($user->birth_date)->toDateString() : null,
            'notifications_enabled' => (bool) $user->notifications_enabled,
            'terms_accepted' => (bool) $user->terms_accepted,
            'latitude' => $user->latitude !== null ? (float) $user->latitude : null,
            'longitude' => $user->longitude !== null ? (float) $user->longitude : null,
            'address' => $user->address,
        ];
    }
}
