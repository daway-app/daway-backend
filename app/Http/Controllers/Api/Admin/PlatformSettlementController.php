<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\PlatformSettlement;
use App\Services\PlatformSettlementService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class PlatformSettlementController extends Controller
{
    public function __construct(private readonly PlatformSettlementService $settlements) {}

    public function eligible(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pharmacy_id' => ['required', 'integer', 'exists:pharmacies,id'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
        ]);
        $orders = $this->settlements->eligibleQuery((int) $data['pharmacy_id'], $data['period_start'], $data['period_end'])->get();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الطلبات المؤهلة للتسوية.',
            'data' => $orders->map(fn ($sub) => [
                'sub_order_id' => $sub->id,
                'order_id' => $sub->order_id,
                'delivered_at' => $sub->delivered_at?->toIso8601String(),
                'amount' => (float) $sub->total,
                'currency' => 'ILS',
                'items_count' => $sub->items->count(),
            ])->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['draft', 'paid'])],
            'pharmacy_id' => ['sometimes', 'integer', 'exists:pharmacies,id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = PlatformSettlement::query()->with(['items.subOrder.items', 'adjustments'])->latest('period_start')->latest('id');
        if (isset($data['status'])) $query->where('status', $data['status']);
        if (isset($data['pharmacy_id'])) $query->where('pharmacy_id', $data['pharmacy_id']);
        $rows = $query->paginate($data['per_page'] ?? 20);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب تسويات المنصة.',
            'data' => $rows->getCollection()->map(fn ($row) => $this->settlements->serialize($row))->values(),
            'pagination' => ['total' => $rows->total(), 'per_page' => $rows->perPage(), 'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage()],
        ]);
    }

    public function show(int $settlement): JsonResponse
    {
        $row = PlatformSettlement::with(['items.subOrder.items', 'adjustments'])->findOrFail($settlement);
        return response()->json(['success' => true, 'message' => 'تم جلب تفاصيل التسوية.', 'data' => $this->settlements->serialize($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pharmacy_id' => ['required', 'integer', 'exists:pharmacies,id'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
        ]);

        try {
            $row = $this->settlements->createDraft(
                Pharmacy::findOrFail($data['pharmacy_id']), $data['period_start'], $data['period_end'], (int) $request->user()->id
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (QueryException $e) {
            return response()->json(['success' => false, 'message' => 'توجد تسوية متزامنة لهذه الصيدلية والفترة.'], 409);
        }

        return response()->json(['success' => true, 'message' => 'تم إنشاء مسودة التسوية.', 'data' => $this->settlements->serialize($row)], 201);
    }

    public function adjustment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pharmacy_id' => ['required', 'integer', 'exists:pharmacies,id'],
            'effective_period_start' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'between:-9999999999.99,9999999999.99', function (string $attribute, mixed $value, \Closure $fail): void {
                if ((float) $value === 0.0) $fail('قيمة التعديل يجب ألا تساوي صفرًا.');
            }],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $row = $this->settlements->addAdjustment(Pharmacy::findOrFail($data['pharmacy_id']), $data['effective_period_start'], (float) $data['amount'], $data['reason'], (int) $request->user()->id);

        return response()->json(['success' => true, 'message' => 'تم تسجيل التعديل للدفعة القادمة.', 'data' => [
            'id' => $row->id, 'pharmacy_id' => $row->pharmacy_id, 'effective_period_start' => $row->effective_period_start->toDateString(),
            'amount' => (float) $row->amount, 'reason' => $row->reason, 'status' => 'pending',
        ]], 201);
    }

    public function markPaid(Request $request, int $settlement): JsonResponse
    {
        $data = $request->validate([
            'transfer_reference' => ['required', 'string', 'max:120'],
            'paid_at' => ['required', 'date'],
        ]);
        try {
            $row = $this->settlements->markPaid(
                PlatformSettlement::findOrFail($settlement), $data['transfer_reference'], Carbon::parse($data['paid_at']), (int) $request->user()->id
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'تم تسجيل التحويل.', 'data' => $this->settlements->serialize($row)]);
    }
}
