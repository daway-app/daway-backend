<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformSettlement;
use App\Services\PlatformSettlementService;
use App\Services\PharmacyContext;
use App\Services\Settlement\ReceiptStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PharmacyPlatformSettlementController extends Controller
{
    public function __construct(
        private readonly PlatformSettlementService $settlements,
        private readonly ReceiptStorage $receipts,
    ) {}

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
            'outstanding' => $this->settlements->outstandingSummary($pharmacy->id),
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

    public function downloadReceipt(Request $request, int $settlement): StreamedResponse|JsonResponse
    {
        abort_unless($request->user()->role === 'pharmacy', 403);
        $pharmacy = PharmacyContext::forUser($request->user());
        if (! $pharmacy) return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        $row = PlatformSettlement::forPharmacy($pharmacy->id)->findOrFail($settlement);

        if (! $this->receipts->exists(
            $row->transfer_receipt_driver,
            $row->transfer_receipt_path,
            $row->transfer_receipt_meta
        )) {
            return response()->json(['success' => false, 'message' => 'إشعار التحويل غير موجود.'], 404);
        }

        try {
            return $this->receipts->download(
                $row->transfer_receipt_driver,
                $row->transfer_receipt_path,
                $row->transfer_receipt_original_name,
                $row->transfer_receipt_mime,
                $row->transfer_receipt_meta
            );
        } catch (Throwable $e) {
            // يشمل رفض Cloudinary للرابط الموقّع بعد انتهاء صلاحيته.
            Log::warning('settlement_receipt_download_failed', [
                'settlement_id' => $row->id,
                'exception' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'تعذّر جلب إشعار التحويل من التخزين المحمي. أعد المحاولة.',
            ], 502);
        }
    }
}
