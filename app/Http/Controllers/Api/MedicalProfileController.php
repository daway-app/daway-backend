<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MedicalProfileRequest;
use App\Models\MedicalProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MedicalProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $profile = MedicalProfile::firstOrCreate(['user_id' => $user->id]);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الملف الصحي بنجاح',
            'data' => $this->payload($profile),
        ]);
    }

    public function update(MedicalProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $profile = MedicalProfile::firstOrCreate(['user_id' => $user->id]);

        $data = $request->validated();
        unset($data['last_local_update']);

        $profile->fill($data)->save();

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الملف الصحي بنجاح',
            'data' => $this->payload($profile),
        ]);
    }

    private function payload(MedicalProfile $profile): array
    {
        $allergies = $profile->allergies;
        if (is_string($allergies)) {
            $decoded = json_decode($allergies, true);
            $allergies = is_array($decoded) ? $decoded : [];
        } elseif (! is_array($allergies)) {
            $allergies = [];
        }

        $chronic = $profile->chronic_diseases;
        if (is_string($chronic)) {
            $decoded = json_decode($chronic, true);
            $chronic = is_array($decoded) ? $decoded : [];
        } elseif (! is_array($chronic)) {
            $chronic = [];
        }

        return [
            'user_id' => $profile->user_id,
            'allergies' => $allergies,
            'chronic_diseases' => $chronic,
            'blood_type' => $profile->blood_type,
            'notes' => $profile->notes,
            'updated_at' => $profile->updated_at,
        ];
    }
}