<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountClosureRequest;
use App\Models\Pharmacy;
use App\Services\AccountClosure\AccountClosureBlocked;
use App\Services\AccountClosure\AccountClosureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * مراجعة طلبات إغلاق الحسابات (أدمن).
 *
 * الأدمن يوافق أو يرفض — ولا يستطيع تجاوز الموانع (D-5): محاولة الموافقة
 * مع وجود التزامات تُرجع 409 بنفس قائمة الموانع.
 */
class AccountClosureController extends Controller
{
    public function __construct(private readonly AccountClosureService $closure) {}

    /** GET /api/admin/account-closures?status=pending */
    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', AccountClosureRequest::STATUS_PENDING);

        $rows = AccountClosureRequest::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rows->map(fn (AccountClosureRequest $row) => $this->serialize($row))->all(),
        ]);
    }

    /** POST /api/admin/account-closures/{closure}/approve */
    public function approve(Request $request, AccountClosureRequest $closure): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        if ($closure->subject_type !== Pharmacy::class) {
            return response()->json([
                'success' => false,
                'message' => 'إغلاق حساب المريض فوري ولا يحتاج مراجعة.',
                'code' => 'not_reviewable',
            ], 422);
        }

        try {
            $this->closure->approvePharmacyClosure($closure, $request->user(), $data['note'] ?? null);
        } catch (AccountClosureBlocked $e) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن إتمام الإغلاق قبل تسوية الالتزامات القائمة.',
                'code' => 'closure_blocked',
                'data' => ['blockers' => $e->blockers()],
            ], 409);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'invalid_state',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم إغلاق حساب الصيدلية.',
            'data' => $this->serialize($closure->fresh()),
        ]);
    }

    /** POST /api/admin/account-closures/{closure}/reject */
    public function reject(Request $request, AccountClosureRequest $closure): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);

        try {
            $this->closure->rejectPharmacyClosure($closure, $request->user(), $data['note']);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'invalid_state',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم رفض طلب الإغلاق.',
            'data' => $this->serialize($closure->fresh()),
        ]);
    }

    /** POST /api/admin/pharmacies/{pharmacy}/reopen */
    public function reopen(Pharmacy $pharmacy): JsonResponse
    {
        if ($pharmacy->closed_at === null) {
            return response()->json([
                'success' => false,
                'message' => 'الصيدلية غير مغلقة.',
                'code' => 'not_closed',
            ], 409);
        }

        $this->closure->reopenPharmacy($pharmacy);

        return response()->json([
            'success' => true,
            'message' => 'تمت إعادة تفعيل الصيدلية.',
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(AccountClosureRequest $row): array
    {
        $pharmacy = $row->subject_type === Pharmacy::class
            ? Pharmacy::find($row->subject_id)
            : null;

        return [
            'id' => $row->id,
            'subject_type' => $row->subject_type === Pharmacy::class ? 'pharmacy' : 'user',
            'subject_id' => $row->subject_id,
            'subject_name' => $pharmacy?->pharmacy_name,
            'status' => $row->status,
            'reason' => $row->reason,
            'blockers' => $row->isPending() && $pharmacy
                ? $this->closure->pharmacyBlockers($pharmacy)
                : ($row->blockers_at_request ?? []),
            'review_note' => $row->review_note,
            'reviewed_at' => $row->reviewed_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
