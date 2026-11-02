<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\PlatformSettlement;
use App\Services\PlatformSettlementService;
use App\Services\Settlement\ReceiptStorage;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PlatformSettlementController extends Controller
{
    public function __construct(
        private readonly PlatformSettlementService $settlements,
        private readonly ReceiptStorage $receipts,
    ) {}

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

    public function due(): JsonResponse
    {
        $unbatched = DB::table('sub_orders')
            ->join('orders', 'orders.id', '=', 'sub_orders.order_id')
            ->where('sub_orders.status', 'delivered')
            ->whereNotNull('sub_orders.delivered_at')
            ->where('orders.payment_status', 'paid')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('platform_settlement_items')->whereColumn('platform_settlement_items.sub_order_id', 'sub_orders.id'))
            ->groupBy('sub_orders.pharmacy_id')
            ->selectRaw('sub_orders.pharmacy_id, COUNT(*) as orders_count, SUM(sub_orders.total) as orders_total')
            ->get()->keyBy('pharmacy_id');
        $drafts = DB::table('platform_settlements')->where('status', PlatformSettlement::STATUS_DRAFT)
            ->groupBy('pharmacy_id')->selectRaw('pharmacy_id, COUNT(*) as draft_count, SUM(net_total) as drafts_total')->get()->keyBy('pharmacy_id');
        $adjustments = DB::table('platform_settlement_adjustments')->whereNull('settlement_id')
            ->whereDate('effective_period_start', '<=', now()->toDateString())
            ->groupBy('pharmacy_id')->selectRaw('pharmacy_id, SUM(amount) as adjustments_total')->get()->keyBy('pharmacy_id');

        $ids = collect([$unbatched->keys(), $drafts->keys(), $adjustments->keys()])->flatten()->unique()->values();
        $rows = Pharmacy::query()->whereIn('id', $ids)->orderBy('pharmacy_name')->get(['id', 'pharmacy_name'])
            ->map(function (Pharmacy $pharmacy) use ($unbatched, $drafts, $adjustments) {
                $orders = $unbatched->get($pharmacy->id);
                $draft = $drafts->get($pharmacy->id);
                $adjustment = $adjustments->get($pharmacy->id);
                $ordersTotal = round((float) ($orders->orders_total ?? 0), 2);
                $draftsTotal = round((float) ($draft->drafts_total ?? 0), 2);
                $adjustmentsTotal = round((float) ($adjustment->adjustments_total ?? 0), 2);
                return [
                    'pharmacy_id' => $pharmacy->id,
                    'pharmacy_name' => $pharmacy->pharmacy_name,
                    'orders_count' => (int) ($orders->orders_count ?? 0),
                    'orders_total' => $ordersTotal,
                    'draft_count' => (int) ($draft->draft_count ?? 0),
                    'drafts_total' => $draftsTotal,
                    'adjustments_total' => $adjustmentsTotal,
                    'total_due' => round($ordersTotal + $draftsTotal + $adjustmentsTotal, 2),
                    'currency' => 'ILS',
                ];
            })->filter(fn (array $row) => $row['total_due'] > 0)->values();

        return response()->json(['success' => true, 'message' => 'الصيدليات التي لديها مستحقات غير مدفوعة.', 'data' => $rows]);
    }

    public function payPharmacy(Request $request, int $pharmacy): JsonResponse
    {
        $data = $request->validate([
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'transfer_reference' => ['nullable', 'string', 'max:120'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);

        // لا نُسجّل تحويلًا مدفوعًا قبل التأكد أن الإثبات وصل القرص فعلاً.
        try {
            $receipt = $this->receipts->put($request->file('receipt'));
        } catch (Throwable $e) {
            Log::error('settlement_receipt_store_failed', ['exception' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'تعذّر حفظ إشعار التحويل. لم يتم تسجيل أي تحويل.',
            ], 500);
        }

        $path = $receipt['transfer_receipt_path'];
        $driver = $receipt['transfer_receipt_driver'] ?? null;
        $meta = $receipt['transfer_receipt_meta'] ?? null;

        try {
            $rows = DB::transaction(function () use ($pharmacy, $request, $data, $receipt) {
                $pharmacyRow = Pharmacy::query()->lockForUpdate()->findOrFail($pharmacy);
                // اطوِ التعديلات المعلّقة في المسودة القائمة أولًا، حتى يتطابق
                // المبلغ المعروض (total_due) مع المبلغ المحوّل فعليًا.
                $this->settlements->absorbPendingAdjustments($pharmacyRow);
                $this->settlements->createDraftFromOutstanding($pharmacyRow, (int) $request->user()->id);
                $drafts = PlatformSettlement::query()->where('pharmacy_id', $pharmacyRow->id)
                    ->where('status', PlatformSettlement::STATUS_DRAFT)->lockForUpdate()->orderBy('id')->get();
                if ($drafts->isEmpty()) throw new InvalidArgumentException('لا توجد مستحقات غير مدفوعة لهذه الصيدلية.');

                return $drafts->map(fn (PlatformSettlement $draft) => $this->settlements->serialize(
                    $this->settlements->markPaid(
                        $draft,
                        $data['transfer_reference'] ?? null,
                        isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now(),
                        (int) $request->user()->id,
                        $receipt
                    )
                ))->values();
            });
        } catch (InvalidArgumentException $e) {
            $this->receipts->delete($driver, $path, $meta);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $this->receipts->delete($driver, $path, $meta);
            throw $e;
        }

        return response()->json(['success' => true, 'message' => 'تم تسجيل التحويل وإرفاق الإشعار.', 'data' => $rows]);
    }

    public function show(int $settlement): JsonResponse
    {
        $row = PlatformSettlement::with(['items.subOrder.items', 'adjustments'])->findOrFail($settlement);
        return response()->json(['success' => true, 'message' => 'تم جلب تفاصيل التسوية.', 'data' => $this->settlements->serialize($row)]);
    }

    public function downloadReceipt(int $settlement): StreamedResponse|JsonResponse
    {
        $row = PlatformSettlement::findOrFail($settlement);

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
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'transfer_reference' => ['nullable', 'string', 'max:120'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);
        // لا نُسجّل تحويلًا مدفوعًا قبل التأكد أن الإثبات وصل القرص فعلاً.
        try {
            $receipt = $this->receipts->put($request->file('receipt'));
        } catch (Throwable $e) {
            Log::error('settlement_receipt_store_failed', ['exception' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'تعذّر حفظ إشعار التحويل. لم يتم تسجيل أي تحويل.',
            ], 500);
        }

        $path = $receipt['transfer_receipt_path'];
        $driver = $receipt['transfer_receipt_driver'] ?? null;
        $meta = $receipt['transfer_receipt_meta'] ?? null;

        try {
            $row = $this->settlements->markPaid(
                PlatformSettlement::findOrFail($settlement), $data['transfer_reference'] ?? null,
                isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now(), (int) $request->user()->id, $receipt
            );
        } catch (InvalidArgumentException $e) {
            $this->receipts->delete($driver, $path, $meta);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $this->receipts->delete($driver, $path, $meta);
            throw $e;
        }

        return response()->json(['success' => true, 'message' => 'تم تسجيل التحويل.', 'data' => $this->settlements->serialize($row)]);
    }
}
