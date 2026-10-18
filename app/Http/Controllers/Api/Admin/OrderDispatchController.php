<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\FormatsDeliveryOrders;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\DeliveryLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rider Delivery — إدارة طلبات التوصيل من لوحة الأدمن: العرض والإسناد.
 *
 * عرض الطلبات لم يكن متاحاً للأدمن عبر API إطلاقاً؛ هذه نافذة القراءة التي
 * يحتاجها الإسناد. كل الكتابة (إسناد/فك) تمر عبر `DeliveryLifecycleService`
 * لتوحيد القيود والتزامن والتدقيق.
 */
class OrderDispatchController extends Controller
{
    use FormatsDeliveryOrders;

    public function __construct(private readonly DeliveryLifecycleService $lifecycle)
    {
    }

    /** GET /api/admin/orders */
    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', 'all');
        $assignment = (string) $request->query('assignment', 'all');
        $riderId = $request->query('rider_id');
        $q = trim((string) $request->query('q', ''));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $query = Order::query()
            ->with(['user', 'rider', 'assignedBy', 'subOrders.pharmacy', 'subOrders.items'])
            ->latest('created_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($assignment === 'assigned') {
            $query->whereNotNull('rider_id');
        } elseif ($assignment === 'unassigned') {
            $query->whereNull('rider_id');
        }

        if ($riderId !== null && $riderId !== '') {
            $query->where('rider_id', (int) $riderId);
        }

        if ($q !== '') {
            if (ctype_digit($q)) {
                $query->where('id', (int) $q);
            } else {
                $like = '%'.addcslashes(mb_strtolower($q), '%_\\').'%';
                $query->whereHas('user', function ($qq) use ($like) {
                    $qq->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(phone) LIKE ?', [$like]);
                });
            }
        }

        $orders = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الطلبات بنجاح.',
            'data' => collect($orders->items())->map(fn (Order $o) => $this->adminOrderPayload($o))->values(),
            'pagination' => [
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    /** GET /api/admin/orders/{order} */
    public function show(Order $order): JsonResponse
    {
        $order->load(['user', 'rider', 'assignedBy', 'subOrders.pharmacy', 'subOrders.items', 'statusHistories']);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب تفاصيل الطلب بنجاح.',
            'data' => array_merge($this->adminOrderPayload($order), [
                'status_histories' => $order->statusHistories->map(fn ($h) => [
                    'status' => $h->status,
                    'note' => $h->note,
                    'actor_role' => $h->actor_role,
                    'actor_user_id' => $h->actor_user_id,
                    'status_changed_at' => $h->status_changed_at?->toDateTimeString(),
                ])->values(),
            ]),
        ]);
    }

    /** POST /api/admin/orders/{order}/assign-rider */
    public function assignRider(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'rider_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $rider = User::findOrFail((int) $data['rider_id']);

        $result = $this->lifecycle->assignRider($order, $rider, $request->user());

        return $this->respond($result);
    }

    /** DELETE /api/admin/orders/{order}/assign-rider */
    public function unassignRider(Request $request, Order $order): JsonResponse
    {
        $result = $this->lifecycle->unassignRider($order, $request->user());

        return $this->respond($result);
    }

    /** @param array{ok: bool, status: int, message: string, data: array<string, mixed>} $result */
    private function respond(array $result): JsonResponse
    {
        return response()->json([
            'success' => $result['ok'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }
}
