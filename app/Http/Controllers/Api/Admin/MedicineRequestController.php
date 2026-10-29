<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MedicineRequest;
use App\Services\MedicineRequest\MedicineRequestApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * لوحة الأدمن — طلبات الأدوية (JSON).
 *
 * منطق الاعتماد مشترك مع واجهة الويب عبر `MedicineRequestApprovalService`
 * (كان مُنسوخاً حرفياً في كلا الكنترولرين). القفل (`lockForUpdate`) والمعاملة
 * الواحدة داخل الخدمة — بدونهما يقع اعتماد مزدوج متزامن يُنشئ روابط مكررة.
 */
class MedicineRequestController extends Controller
{
    /** GET /api/admin/medicine-requests */
    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', MedicineRequest::STATUS_PENDING);
        if (! in_array($status, MedicineRequest::STATUSES, true)) {
            $status = MedicineRequest::STATUS_PENDING;
        }

        $q = trim((string) $request->query('q', ''));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        $requests = MedicineRequest::query()
            ->with(['pharmacy:id,pharmacy_name', 'category:id,name_ar', 'subcategory:id,name_ar'])
            ->where('status', $status)
            ->when($q !== '' && mb_strlen($q) >= 2, function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('trade_name', 'like', "%{$q}%")
                        ->orWhere('generic_name', 'like', "%{$q}%")
                        ->orWhere('active_ingredient', 'like', "%{$q}%");
                });
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'requests' => $requests->items(),
                'pagination' => [
                    'current_page' => $requests->currentPage(),
                    'last_page' => $requests->lastPage(),
                    'per_page' => $requests->perPage(),
                    'total' => $requests->total(),
                ],
                'filters' => ['q' => $q, 'status' => $status],
                'stats' => [
                    'pending' => MedicineRequest::where('status', MedicineRequest::STATUS_PENDING)->count(),
                    'approved' => MedicineRequest::where('status', MedicineRequest::STATUS_APPROVED)->count(),
                    'rejected' => MedicineRequest::where('status', MedicineRequest::STATUS_REJECTED)->count(),
                ],
            ],
        ]);
    }

    /** GET /api/admin/medicine-requests/{medicineRequest} */
    public function show(MedicineRequest $medicineRequest): JsonResponse
    {
        $medicineRequest->load([
            'pharmacy:id,pharmacy_name',
            'category:id,name_ar',
            'subcategory:id,name_ar',
            'approvedMohMedicine',
            'approvedMedicine',
        ]);

        return response()->json([
            'success' => true,
            'data' => ['request' => $medicineRequest],
        ]);
    }

    /** POST /api/admin/medicine-requests/{medicineRequest}/approve */
    public function approve(Request $request, MedicineRequest $medicineRequest): JsonResponse
    {
        $data = $request->validate([
            'trade_name' => ['nullable', 'string', 'max:150', 'not_regex:/[\x{0600}-\x{06FF}]/u'],
            'trade_name_ar' => ['nullable', 'string', 'max:150', 'regex:/[\x{0600}-\x{06FF}]/u'],
            'generic_name' => ['nullable', 'string', 'max:150'],
            'manufacturer' => ['nullable', 'string', 'max:150'],
            'active_ingredient' => ['nullable', 'string', 'max:150'],
            'dosage_form' => ['nullable', 'string', 'max:150'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'subcategory_id' => ['nullable', 'integer', 'exists:subcategories,id'],
        ]);

        // المنطق مشترك مع واجهة الويب — منقول إلى الخدمة (M-3A parity)
        $result = app(MedicineRequestApprovalService::class)->approve(
            $medicineRequest,
            $data,
            (int) $request->user()->id,
        );

        return response()->json([
            'success' => true,
            'message' => 'تمت الموافقة على الطلب بنجاح.',
            'data' => ['status' => $result->status],
        ]);
    }

    /** POST /api/admin/medicine-requests/{medicineRequest}/reject */
    public function reject(Request $request, MedicineRequest $medicineRequest): JsonResponse
    {
        $data = $request->validate([
            'admin_notes' => ['required', 'string', 'max:2000'],
        ]);

        if ($medicineRequest->status !== MedicineRequest::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'الطلب ليس بحالة بانتظار المراجعة.',
            ], 422);
        }

        $medicineRequest->update([
            'status' => MedicineRequest::STATUS_REJECTED,
            'admin_notes' => $data['admin_notes'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم رفض الطلب.',
        ]);
    }
}
