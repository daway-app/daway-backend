<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountClosure\AccountClosureBlocked;
use App\Services\AccountClosure\AccountClosureService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * إغلاق حساب المريض — فوري بعد المصادقة وتأكيد الطلب (قرار D-3).
 *
 * لا يُغلق الحساب إن كانت هناك طلبات نشطة أو إثباتات دفع قيد المراجعة؛
 * الإغلاق يوقف الوصول ويُخفي الهوية، ولا يمسّ أي سجل مالي (D-1).
 */
class PatientAccountController extends Controller
{
    public function __construct(private readonly AccountClosureService $closure) {}

    /** POST /api/patient/account/closure */
    public function close(Request $request): JsonResponse
    {
        // Sanctum can also authenticate a web session; closure requires a real bearer token.
        if (! $request->bearerToken() || ! ($request->user()?->currentAccessToken() instanceof PersonalAccessToken)) {
            throw new AuthenticationException;
        }

        $data = $request->validate([
            'confirm' => ['required', 'string', 'in:DELETE'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();

        try {
            $closure = $this->closure->closePatient($user, $data['reason'] ?? null);
        } catch (AccountClosureBlocked $e) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن إغلاق الحساب الآن لوجود التزامات قائمة.',
                'code' => 'closure_blocked',
                'data' => ['blockers' => $e->blockers()],
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم إغلاق الحساب. لن تتمكن من تسجيل الدخول مرة أخرى.',
            'data' => [
                'status' => 'closed',
                'closed_at' => $closure->created_at?->toIso8601String(),
            ],
        ]);
    }

    /** GET /api/patient/account/status */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $user->closed_at ? 'closed' : 'active',
                'closed_at' => $user->closed_at?->toIso8601String(),
                'blockers' => $user->closed_at ? [] : $this->closure->patientBlockers($user),
            ],
        ]);
    }
}
