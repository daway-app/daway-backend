<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DeleteDeviceTokenRequest;
use App\Http\Requests\Api\DeviceTokenRequest;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    public function store(DeviceTokenRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $data = $request->validated();

        $existingForUserDevice = DeviceToken::where('user_id', $user->id)
            ->where('device_id', $data['device_id'])
            ->first();

        $takenByAnotherUser = DeviceToken::where('token', $data['token'])
            ->where('user_id', '!=', $user->id)
            ->exists();

        if ($takenByAnotherUser) {
            return response()->json([
                'success' => false,
                'message' => 'رمز الجهاز مستخدم من قبل مستخدم آخر',
                'errors' => ['token' => ['هذا الرمز مسجل لمستخدم آخر']],
            ], 422);
        }

        $token = DeviceToken::updateOrCreate(
            [
                'user_id' => $user->id,
                'device_id' => $data['device_id'],
            ],
            [
                'token' => $data['token'],
                'platform' => $data['platform'],
                'last_seen_at' => now(),
            ]
        );

        $wasCreated = $existingForUserDevice === null;

        return response()->json([
            'success' => true,
            'message' => $wasCreated
                ? 'تم تسجيل رمز الجهاز بنجاح'
                : 'تم تحديث رمز الجهاز بنجاح',
            'data' => [
                'id' => $token->id,
                'user_id' => $token->user_id,
                'platform' => $token->platform,
                'device_id' => $token->device_id,
                'last_seen_at' => $token->last_seen_at,
                'created_at' => $token->created_at,
                'updated_at' => $token->updated_at,
            ],
        ], $wasCreated ? 201 : 200);
    }

    public function destroy(DeleteDeviceTokenRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $deviceId = $request->validated()['device_id'];

        DeviceToken::where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف رمز الجهاز بنجاح',
            'data' => [
                'device_id' => $deviceId,
                'deleted' => true,
            ],
        ]);
    }
}