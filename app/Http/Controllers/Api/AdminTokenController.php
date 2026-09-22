<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * مؤقت — يصدر Sanctum token من جلسة ويب أدمن.
 *
 * يعتمد على auth:web (session) — لا يقبل API tokens.
 * محمي بـ role:admin.
 *
 * يُستَخدم للوصول إلى admin maintenance endpoints مثل:
 *   GET /api/admin/maintenance/pharmacy-moh-backfill/dry-run
 *
 * ⚠️ مؤقت — يُمسح بعد الاستخدام.
 */
class AdminTokenController extends Controller
{
    public function issue(): JsonResponse
    {
        $user = Auth::guard('web')->user();

        if (! $user || $user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح',
            ], 403);
        }

        $token = $user->createToken('admin-maintenance')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Admin token issued successfully.',
            'data' => [
                'token' => $token,
            ],
        ]);
    }
}
