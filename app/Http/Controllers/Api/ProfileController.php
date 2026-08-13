<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    // 1. عرض بيانات الملف الشخصي للمستخدم الحالي
    public function show(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'success' => true,
            'data' => $user->only(['id', 'name', 'phone', 'address', 'birth_date', 'avatar', 'emergency_contact', 'role'])
        ]);
    }

    // 2. تحديث بيانات الملف الشخصي (مع رفع الصورة)
    public function update(UpdateProfileRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();

        // معالجة رفع الصورة (إذا تم إرسال صورة)
        if ($request->hasFile('avatar')) {
            // حذف الصورة القديمة (إذا كانت موجودة)
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }
            // حفظ الصورة الجديدة في مجلد public/avatars
            $path = $request->file('avatar')->store('avatars', 'public');
            $data['avatar'] = $path;
        }

        // تحديث بيانات المستخدم
        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الملف الشخصي بنجاح',
            'data' => $user->fresh()->only(['id', 'name', 'phone', 'address', 'birth_date', 'avatar', 'emergency_contact', 'role'])
        ]);
    }
}
