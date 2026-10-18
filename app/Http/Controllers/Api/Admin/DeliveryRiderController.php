<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\DeliveryLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Rider Delivery — إدارة المندوبين (لوحة الأدمن عبر API).
 *
 * المندوب حساب `users.role = 'rider'` بإعادة استخدام المصادقة الحالية.
 * هذا الكنترولر مسؤول عن إنشاء/تعديل/تفعيل/تعطيل/حذف المندوبين فقط؛ إسناد
 * الطلبات في `Admin\OrderDispatchController`.
 *
 * قواعد التعطيل/الحذف:
 *   · لا يجوز تعطيل/حذف مندوب لديه توصيل جارٍ (بدأ الاستلام ولم يكتمل).
 *   · عند التعطيل أو الحذف تُفكّ إسنادات الطلبات التي لم يبدأ استلامها،
 *     فلا يبقى طلب محتجزاً بلا مندوب فعّال.
 */
class DeliveryRiderController extends Controller
{
    public function __construct(private readonly DeliveryLifecycleService $lifecycle)
    {
    }

    /** GET /api/admin/delivery-riders */
    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', 'active', 'inactive'], true)) {
            $status = 'all';
        }

        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $query = User::query()
            ->where('role', 'rider')
            ->withCount(['assignedOrders as active_deliveries_count' => function ($qq) {
                $qq->whereIn('status', Order::DELIVERY_PHASE);
            }])
            ->latest();

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(function ($qq) use ($like) {
                $qq->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
            });
        }

        $riders = $query->paginate($perPage);

        $total = User::where('role', 'rider')->count();
        $active = User::where('role', 'rider')->where('is_active', true)->count();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب المندوبين بنجاح.',
            'data' => [
                'riders' => collect($riders->items())->map(fn (User $u) => $this->riderPayload($u))->values(),
                'pagination' => [
                    'total' => $riders->total(),
                    'per_page' => $riders->perPage(),
                    'current_page' => $riders->currentPage(),
                    'last_page' => $riders->lastPage(),
                ],
                'filters' => ['q' => $q, 'status' => $status],
                'counts' => [
                    'total' => $total,
                    'active' => $active,
                    'inactive' => $total - $active,
                ],
            ],
        ]);
    }

    /** POST /api/admin/delivery-riders */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255', 'unique:users'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $rider = DB::transaction(function () use ($data, $request) {
            $rider = new User;
            $rider->name = $data['name'];
            $rider->email = $data['email'] ?? null;
            $rider->phone = $data['phone'];
            $rider->password = Hash::make($data['password']);
            $rider->role = 'rider';
            $rider->is_active = $request->has('is_active') ? $request->boolean('is_active') : true;
            $rider->save();

            // مزامنة Spatie مع الدور — نفس نمط Admin\UserController.
            $rider->syncRoles(['rider']);

            return $rider;
        });

        return response()->json([
            'success' => true,
            'message' => 'تمت إضافة المندوب بنجاح.',
            'data' => ['rider' => $this->riderPayload($rider)],
        ], 201);
    }

    /** GET /api/admin/delivery-riders/{rider} */
    public function show(User $rider): JsonResponse
    {
        abort_unless($rider->role === 'rider', 404);

        $rider->loadCount([
            'assignedOrders as active_deliveries_count' => function ($q) {
                $q->whereIn('status', Order::DELIVERY_PHASE);
            },
            'assignedOrders as total_deliveries_count',
        ]);

        return response()->json([
            'success' => true,
            'data' => ['rider' => $this->riderPayload($rider)],
        ]);
    }

    /** PUT/PATCH /api/admin/delivery-riders/{rider} */
    public function update(Request $request, User $rider): JsonResponse
    {
        abort_unless($rider->role === 'rider', 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255', 'unique:users,phone,'.$rider->id],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email,'.$rider->id],
            'status' => ['required', 'boolean'],
        ]);

        $deactivating = (bool) $rider->is_active && ! (bool) $data['status'];

        if ($deactivating && $this->lifecycle->hasActiveDelivery($rider)) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تعطيل مندوب لديه توصيل جارٍ.',
            ], 422);
        }

        $rider->name = $data['name'];
        $rider->phone = $data['phone'];
        $rider->email = $data['email'] ?? null;
        $rider->is_active = (bool) $data['status'];
        $rider->save();

        if ($deactivating) {
            $this->lifecycle->unassignAllForRider($rider, $request->user());
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث بيانات المندوب بنجاح.',
            'data' => ['rider' => $this->riderPayload($rider->fresh())],
        ]);
    }

    /** PATCH /api/admin/delivery-riders/{rider}/toggle-status */
    public function toggleStatus(Request $request, User $rider): JsonResponse
    {
        abort_unless($rider->role === 'rider', 404);

        $newState = ! $rider->is_active;

        if (! $newState && $this->lifecycle->hasActiveDelivery($rider)) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تعطيل مندوب لديه توصيل جارٍ.',
            ], 422);
        }

        $unassigned = 0;
        if (! $newState) {
            $unassigned = $this->lifecycle->unassignAllForRider($rider, $request->user());
        }

        $rider->is_active = $newState;
        $rider->save();

        return response()->json([
            'success' => true,
            'message' => $newState ? 'تم تفعيل المندوب.' : 'تم تعطيل المندوب.',
            'data' => [
                'is_active' => (bool) $rider->is_active,
                'unassigned_orders' => $unassigned,
            ],
        ]);
    }

    /** DELETE /api/admin/delivery-riders/{rider} */
    public function destroy(Request $request, User $rider): JsonResponse
    {
        abort_unless($rider->role === 'rider', 404);

        if ($this->lifecycle->hasActiveDelivery($rider)) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن حذف مندوب لديه توصيل جارٍ.',
            ], 422);
        }

        DB::transaction(function () use ($rider, $request) {
            // فكّ الإسنادات غير المبدوءة ثم تفريغ مرجع المندوب عن الطلبات
            // التاريخية حتى لا يبقى مرجع معلّق بعد الحذف.
            $this->lifecycle->unassignAllForRider($rider, $request->user());

            Order::where('rider_id', $rider->id)->update([
                'rider_id' => null,
                'assigned_by' => null,
                'assigned_at' => null,
            ]);

            $rider->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'تم حذف المندوب بنجاح.',
        ]);
    }

    /** @return array<string, mixed> */
    private function riderPayload(User $rider): array
    {
        return [
            'id' => $rider->id,
            'name' => $rider->name,
            'email' => $rider->email,
            'phone' => $rider->phone,
            'is_active' => (bool) $rider->is_active,
            'active_deliveries_count' => (int) ($rider->active_deliveries_count ?? 0),
            'total_deliveries_count' => (int) ($rider->total_deliveries_count ?? 0),
            'created_at' => $rider->created_at?->toDateTimeString(),
        ];
    }
}
