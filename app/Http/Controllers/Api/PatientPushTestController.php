<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientPushTestController extends Controller
{
    public function __invoke(Request $request, NotificationDispatcher $dispatcher): JsonResponse
    {
        $user = $request->user();

        // A patient can test only their own devices. Never accept a user or token ID.
        if (! DeviceToken::query()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد جهاز مسجل للإشعارات. افتح تطبيق دواك ثم حاول مجددًا.',
            ], 409);
        }

        $notification = $dispatcher->createAndPush($user, [
            'type' => 'system',
            'message' => 'إشعار تجريبي من دواك للتحقق من وصول التنبيهات.',
        ]);

        if (! $notification) {
            return response()->json([
                'success' => false,
                'message' => 'تعذر إنشاء إشعار الاختبار.',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء إشعار الاختبار ومحاولة إرساله إلى جهازك.',
            'data' => ['notification_id' => $notification->id],
        ], 202);
    }
}
