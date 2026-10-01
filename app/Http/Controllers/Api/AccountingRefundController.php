<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\Refund;
use App\Services\Accounting\RefundService;
use App\Services\PharmacyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AccountingRefundController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $query = Refund::forPharmacy($pharmacy->id)
            ->with(['sale:id,number,sold_at,total,paid,remaining,customer_name', 'items.saleItem'])
            ->orderByDesc('refunded_at');

        $refunds = $query->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب عمليات الإرجاع',
            'data' => $refunds->items(),
            'pagination' => [
                'total' => $refunds->total(),
                'per_page' => $refunds->perPage(),
                'current_page' => $refunds->currentPage(),
                'last_page' => $refunds->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $validated = $request->validate([
            'sale_number' => 'required|string|exists:sales,number',
            'items' => 'required|array|min:1',
            'items.*.sale_item_id' => 'required|integer|exists:sale_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'reason' => 'nullable|string|max:500',
            'refunded_at' => 'nullable|date',
        ]);

        $sale = Sale::query()
            ->forPharmacy($pharmacy->id)
            ->where('number', $validated['sale_number'])
            ->first();

        if (! $sale) {
            return response()->json(['success' => false, 'message' => 'الفاتورة غير موجودة'], 404);
        }

        if ($sale->status === Sale::STATUS_CANCELLED || $sale->status === Sale::STATUS_REFUNDED) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن إرجاع فاتورة ملغاة أو مرجوعة',
            ], 422);
        }

        $itemsToRefund = collect($validated['items'])->map(function ($item) use ($sale) {
            return [
                'sale_item_id' => $item['sale_item_id'],
                'quantity' => $item['quantity'],
            ];
        })->all();

        $refundedAt = isset($validated['refunded_at'])
            ? Carbon::parse($validated['refunded_at'])
            : Carbon::now();

        try {
            $refund = RefundService::processRefund(
                $pharmacy->id,
                $user->id,
                $sale,
                $itemsToRefund,
                $validated['reason'] ?? null,
                $refundedAt
            );

            $refund->load(['sale', 'items.saleItem']);

            return response()->json([
                'success' => true,
                'message' => 'تم إجراء الإرجاع بنجاح',
                'data' => [
                    'refund' => [
                        'id' => $refund->id,
                        'sale_id' => $refund->sale_id,
                        'sale_number' => $refund->sale->number,
                        'amount' => (float) $refund->amount,
                        'reason' => $refund->reason,
                        'status' => $refund->status,
                        'refunded_at' => $refund->refunded_at->toIso8601String(),
                        'items' => $refund->items->map(fn ($item) => [
                            'id' => $item->id,
                            'sale_item_id' => $item->sale_item_id,
                            'medicine_name' => $item->saleItem->medicine_name,
                            'quantity' => $item->quantity,
                            'amount' => (float) $item->amount,
                        ])->all(),
                    ],
                    'sale' => [
                        'number' => $sale->number,
                        'paid' => (float) $sale->paid,
                        'remaining' => (float) $sale->remaining,
                        'status' => $sale->status,
                    ],
                ],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function show(Request $request, int $refundId): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $refund = Refund::forPharmacy($pharmacy->id)
            ->where('id', $refundId)
            ->with(['sale:id,number,sold_at,total,paid,remaining,customer_name', 'items.saleItem'])
            ->first();

        if (! $refund) {
            return response()->json(['success' => false, 'message' => 'عملية الإرجاع غير موجودة'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم جلب عملية الإرجاع',
            'data' => [
                'id' => $refund->id,
                'sale_id' => $refund->sale_id,
                'sale_number' => $refund->sale->number,
                'amount' => (float) $refund->amount,
                'reason' => $refund->reason,
                'status' => $refund->status,
                'refunded_at' => $refund->refunded_at->toIso8601String(),
                'created_by' => $refund->creator?->name,
                'items' => $refund->items->map(fn ($item) => [
                    'id' => $item->id,
                    'sale_item_id' => $item->sale_item_id,
                    'medicine_name' => $item->saleItem->medicine_name,
                    'quantity' => $item->quantity,
                    'amount' => (float) $item->amount,
                ])->all(),
            ],
        ]);
    }

    public function indexForSale(Request $request, string $saleNumber): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $sale = Sale::query()
            ->forPharmacy($pharmacy->id)
            ->where('number', $saleNumber)
            ->first();

        if (! $sale) {
            return response()->json(['success' => false, 'message' => 'الفاتورة غير موجودة'], 404);
        }

        $refunds = Refund::forPharmacy($pharmacy->id)
            ->where('sale_id', $sale->id)
            ->with(['items.saleItem'])
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب إرجاعات الفاتورة',
            'data' => $refunds->map(fn ($refund) => [
                'id' => $refund->id,
                'amount' => (float) $refund->amount,
                'status' => $refund->status,
                'refunded_at' => $refund->refunded_at->toIso8601String(),
                'items_count' => $refund->items->count(),
            ])->all(),
        ]);
    }

    public function availableItems(Request $request, string $saleNumber): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $sale = Sale::query()
            ->forPharmacy($pharmacy->id)
            ->where('number', $saleNumber)
            ->with(['items:id,sale_id,medicine_name,quantity,unit_price,line_total'])
            ->first();

        if (! $sale) {
            return response()->json(['success' => false, 'message' => 'الفاتورة غير موجودة'], 404);
        }

        if ($sale->status === Sale::STATUS_CANCELLED || $sale->status === Sale::STATUS_REFUNDED) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن إرجاع فاتورة ملغاة أو مرجوعة',
            ], 409);
        }

        $refundedItems = Refund::forPharmacy($pharmacy->id)
            ->where('sale_id', $sale->id)
            ->join('refund_items', 'refunds.id', '=', 'refund_items.refund_id')
            ->select('refund_items.sale_item_id', DB::raw('SUM(refund_items.quantity) as refunded_qty'))
            ->groupBy('refund_items.sale_item_id')
            ->pluck('refunded_qty', 'refund_items.sale_item_id');

        $availableItems = $sale->items->map(function ($item) use ($refundedItems, $sale) {
            $refundedQty = $refundedItems->get($item->id, 0);
            $availableQty = $item->quantity - $refundedQty;

            return [
                'id' => $item->id,
                'medicine_name' => $item->medicine_name,
                'unit_price' => (float) $item->unit_price,
                'original_quantity' => $item->quantity,
                'refunded_quantity' => (int) $refundedQty,
                'available_quantity' => $availableQty,
                'line_total' => (float) $item->line_total,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'تم جلب أصناف الفاتورة المتاحة للإرجاع',
            'data' => [
                'sale_number' => $sale->number,
                'total' => (float) $sale->total,
                'paid' => (float) $sale->paid,
                'already_refunded' => (float) $sale->paid - $sale->remaining,
                'available_to_refund' => (float) $sale->paid,
                'items' => $availableItems,
            ],
        ]);
    }
}