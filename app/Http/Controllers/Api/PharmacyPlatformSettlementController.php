<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformSettlement;
use App\Services\PlatformSettlementService;
use App\Services\PharmacyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PharmacyPlatformSettlementController extends Controller
{
    public function __construct(private readonly PlatformSettlementService $settlements) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'pharmacy', 403);
        $pharmacy = PharmacyContext::forUser($request->user());
        if (! $pharmacy) return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);

        $rows = PlatformSettlement::forPharmacy($pharmacy->id)
            ->with(['items.subOrder.items', 'adjustments'])
            ->latest('period_start')->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب تسويات صيدليتك.',
            'data' => $rows->getCollection()->map(fn ($row) => $this->settlements->serialize($row))->values(),
            'pagination' => ['total' => $rows->total(), 'per_page' => $rows->perPage(), 'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage()],
        ]);
    }

    public function show(Request $request, int $settlement): JsonResponse
    {
        abort_unless($request->user()->role === 'pharmacy', 403);
        $pharmacy = PharmacyContext::forUser($request->user());
        if (! $pharmacy) return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        $row = PlatformSettlement::forPharmacy($pharmacy->id)
            ->with(['items.subOrder.items', 'adjustments'])
            ->findOrFail($settlement);

        return response()->json(['success' => true, 'message' => 'تم جلب تفاصيل التسوية.', 'data' => $this->settlements->serialize($row)]);
    }
}
