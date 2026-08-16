<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PatientProfileRequest;
use App\Support\Image;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    private function payload($user): array
    {
        return [
            'type' => 'patient',
            'name' => $user->name,
            'phone' => $user->phone,
            'avatar_url' => Image::url($user->avatar),
            'birth_date' => $user->birth_date ? Carbon::parse($user->birth_date)->toDateString() : null,
            'latitude' => $user->latitude !== null ? (float) $user->latitude : null,
            'longitude' => $user->longitude !== null ? (float) $user->longitude : null,
            'address' => $user->address,
        ];
    }
}
