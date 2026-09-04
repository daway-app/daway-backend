<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateAvailabilityAlertRequest;
use App\Models\AvailabilityNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityAlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $alerts = AvailabilityNotification::where('user_id', $user->id)
            ->with(['medicine', 'pharmacy'])
            ->latest()
            ->paginate(20);

        $data = $alerts->getCollection()->map(function (AvailabilityNotification $alert) {
            return [
                'id' => $alert->id,
                'medicine_id' => $alert->medicine_id,
                'medicine' => $alert->medicine ? [
                    'id' => $alert->medicine->id,
                    'trade_name' => $alert->medicine->trade_name,
                    'trade_name_ar' => $alert->medicine->trade_name_ar,
                ] : null,
                'pharmacy_id' => $alert->pharmacy_id,
                'pharmacy' => $alert->pharmacy ? [
                    'id' => $alert->pharmacy->id,
                    'pharmacy_name' => $alert->pharmacy->pharmacy_name,
                ] : null,
                'is_notified' => (bool) $alert->is_notified,
                'notified_at' => $alert->notified_at,
                'created_at' => $alert->created_at,
            ];
        })->all();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب اشتراكات التوفر بنجاح',
            'data' => $data,
            'pagination' => [
                'total' => $alerts->total(),
                'per_page' => $alerts->perPage(),
                'current_page' => $alerts->currentPage(),
                'last_page' => $alerts->lastPage(),
            ],
        ]);
    }

    public function store(CreateAvailabilityAlertRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $data = $request->validated();

        $existing = AvailabilityNotification::where('user_id', $user->id)
            ->where('medicine_id', $data['medicine_id'])
            ->where('pharmacy_id', $data['pharmacy_id'] ?? null)
            ->first();

        $wasCreated = false;

        if (! $existing) {
            $alert = AvailabilityNotification::create([
                'user_id' => $user->id,
                'medicine_id' => $data['medicine_id'],
                'pharmacy_id' => $data['pharmacy_id'] ?? null,
                'is_notified' => false,
            ]);
            $wasCreated = true;
        } else {
            $alert = $existing;
        }

        $alert->load(['medicine', 'pharmacy']);

        return response()->json([
            'success' => true,
            'message' => $wasCreated
                ? 'تم الاشتراك في تنبيه التوفر بنجاح'
                : 'الاشتراك موجود مسبقاً',
            'data' => [
                'id' => $alert->id,
                'medicine_id' => $alert->medicine_id,
                'pharmacy_id' => $alert->pharmacy_id,
                'is_notified' => (bool) $alert->is_notified,
                'notified_at' => $alert->notified_at,
                'created_at' => $alert->created_at,
            ],
        ], $wasCreated ? 201 : 200);
    }

    public function destroy(Request $request, AvailabilityNotification $alert): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);
        abort_unless($alert->user_id === $user->id, 403);

        $alert->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء الاشتراك من تنبيه التوفر بنجاح',
            'data' => [
                'id' => $alert->id,
            ],
        ]);
    }
}