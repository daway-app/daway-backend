<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sync\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SyncController extends Controller
{
    public function __construct(private SyncService $syncService)
    {
    }

    /**
     * إصدار Sanctum token لمستخدم لوحة التحكم (جلسة ويب) لتفعيل المزامنة.
     * هذا المسار خارج auth:sanctum — يعتمد على جلسة الويب.
     */
    public function issueToken(Request $request): JsonResponse
    {
        $user = Auth::guard('web')->user();

        if (! $user || $user->role !== 'pharmacy') {
            return response()->json(['success' => false, 'message' => 'غير مصرح'], 403);
        }

        $token = $user->createToken('sync')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء رمز المزامنة بنجاح',
            'data' => ['token' => $token],
        ]);
    }

    /**
     * دفع دفعة عمليات مزامنة من قائمة الانتظار المحلية (offline queue).
     */
    public function push(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $validated = $request->validate([
            'operations' => 'required|array|max:200',
            'operations.*.uuid' => 'required|uuid',
            'operations.*.op_type' => 'required|in:inventory.update,medicine.store,medicine.update,inquiry.status',
            'operations.*.payload' => 'required|array',
            'operations.*.client_updated_at' => 'nullable|date',
        ]);

        $results = $this->syncService->push($validated['operations'], $user);

        return response()->json([
            'success' => true,
            'message' => 'تمت معالجة عمليات المزامنة',
            'data' => ['results' => $results],
        ]);
    }

    /**
     * سحب آخر التغييرات منذ آخر مزامنة (incremental pull).
     */
    public function pull(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $validated = $request->validate([
            'since' => 'nullable|date',
        ]);

        $payload = $this->syncService->pull($user, $validated['since'] ?? null);

        return response()->json($payload);
    }
}
