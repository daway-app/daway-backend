<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Api\Concerns\FormatsDeliveryOrders;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SubOrder;
use App\Services\DeliveryLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rider Delivery — واجهة المندوب عبر API (تطبيق الموبايل).
 *
 * العزل صارم: المندوب يرى ويعدّل **الطلبات المُسندة إليه فقط** (`rider_id`).
 * أي طلب آخر يُعاد 404 (لا 403) حتى لا نكشف وجود طلب ليس له. كل الانتقالات
 * تمر عبر `DeliveryLifecycleService` فتخضع لنفس القيود والتزامن والتدقيق.
 */
class OrderController extends Controller
{
    use FormatsDeliveryOrders;

    public function __construct(private readonly DeliveryLifecycleService $lifecycle)
    {
    }

    /** GET /api/rider/orders */
    public function index(Request $request): JsonResponse
    {
        $rider = $request->user();

        $status = (string) $request->query('status', 'all');
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $query = Order::query()
            ->where('rider_id', $rider->id)
            ->with(['subOrders.pharmacy', 'subOrders.items'])
            ->latest('assigned_at')
            ->latest('id');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $orders = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الطلبات المُسندة إليك بنجاح.',
            'data' => collect($orders->items())->map(fn (Order $o) => $this->riderOrderPayload($o))->values(),
            'pagination' => [
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    /** GET /api/rider/orders/{order} */
    public function show(Request $request, Order $order): JsonResponse
    {
        if ((int) $order->rider_id !== (int) $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'الطلب غير موجود.'], 404);
        }

        $order->load(['subOrders.pharmacy', 'subOrders.items', 'statusHistories']);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب تفاصيل الطلب بنجاح.',
            'data' => array_merge($this->riderOrderPayload($order), [
                'status_histories' => $order->statusHistories->map(fn ($h) => [
                    'status' => $h->status,
                    'note' => $h->note,
                    'status_changed_at' => $h->status_changed_at?->toDateTimeString(),
                ])->values(),
            ]),
        ]);
    }

    /** POST /api/rider/orders/{order}/pickup  { sub_order_id } */
    public function pickup(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'sub_order_id' => ['required', 'integer'],
        ]);

        $subOrder = SubOrder::find((int) $data['sub_order_id']);

        if (! $subOrder || (int) $subOrder->order_id !== (int) $order->id) {
            return response()->json([
                'success' => false,
                'message' => 'الطلب الفرعي غير موجود ضمن هذا الطلب.',
            ], 404);
        }

        $result = $this->lifecycle->markPickup($order, $subOrder, $request->user());

        return $this->respond($result, 200);
    }

    /** POST /api/rider/orders/{order}/status  { status } */
    public function status(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:out_for_delivery,delivered'],
        ]);

        $result = $data['status'] === Order::STATUS_DELIVERED
            ? $this->lifecycle->markDelivered($order, $request->user())
            : $this->lifecycle->startDelivery($order, $request->user());

        return $this->respond($result, 200);
    }

    /** @param array{ok: bool, status: int, message: string, data: array<string, mixed>} $result */
    private function respond(array $result, int $successStatus = 200): JsonResponse
    {
        $status = $result['ok'] ? $successStatus : $result['status'];

        return response()->json([
            'success' => $result['ok'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $status);
    }
}
