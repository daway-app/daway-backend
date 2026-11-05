<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountClosureRequest;
use App\Models\Pharmacy;
use App\Services\AccountClosure\AccountClosureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * طلب إغلاق حساب الصيدلية.
 *
 * يمكن تقديم الطلب **حتى مع وجود مستحقات** (D-6). لكن الإغلاق لا يكتمل
 * إلا بعد موافقة الأدمن وتسوية كل الالتزامات — والموانع تُعرض للمالك فورًا
 * كي يعرف ما يجب تسويته قبل الموافقة.
 *
 * المالك فقط يقدّم الطلب (صلاحية إدارية على كيان الصيدلية).
 */
class PharmacyAccountClosureController extends Controller
{
    public function __construct(private readonly AccountClosureService $closure) {}

    /** POST /api/pharmacy/account/closure-request */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->first();

        if (! $pharmacy instanceof Pharmacy) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الحساب غير مرتبط بصيدلية.',
                'code' => 'not_a_pharmacy_owner',
            ], 403);
        }

        if ($pharmacy->closed_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'حساب الصيدلية مغلق بالفعل.',
                'code' => 'already_closed',
            ], 409);
        }

        try {
            $closure = $this->closure->requestPharmacyClosure($pharmacy, $user, $data['reason'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'request_failed',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال طلب إغلاق الحساب للمراجعة.',
            'data' => $this->serialize($closure, $pharmacy),
        ], 201);
    }

    /** GET /api/pharmacy/account/closure-request */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->first();

        if (! $pharmacy instanceof Pharmacy) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الحساب غير مرتبط بصيدلية.',
                'code' => 'not_a_pharmacy_owner',
            ], 403);
        }

        $closure = AccountClosureRequest::query()
            ->where('subject_type', Pharmacy::class)
            ->where('subject_id', $pharmacy->id)
            ->latest('id')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'account_status' => $pharmacy->closed_at ? 'closed' : 'active',
                'closed_at' => $pharmacy->closed_at?->toIso8601String(),
                'request' => $closure ? $this->serialize($closure, $pharmacy) : null,
                'blockers' => $pharmacy->closed_at ? [] : $this->closure->pharmacyBlockers($pharmacy),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(AccountClosureRequest $closure, Pharmacy $pharmacy): array
    {
        return [
            'id' => $closure->id,
            'status' => $closure->status,
            'reason' => $closure->reason,
            'created_at' => $closure->created_at?->toIso8601String(),
            'reviewed_at' => $closure->reviewed_at?->toIso8601String(),
            'review_note' => $closure->review_note,
            // الموانع الحالية تُحسب لحظيًا، لا نعتمد نسخة الطلب القديمة.
            'blockers' => $closure->isPending()
                ? $this->closure->pharmacyBlockers($pharmacy)
                : ($closure->blockers_at_request ?? []),
        ];
    }
}
