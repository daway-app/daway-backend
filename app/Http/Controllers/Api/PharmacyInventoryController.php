<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PharmacyInventoryBulkRequest;
use App\Http\Resources\PharmacyMedicineResource;
use App\Models\Notification;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PharmacyInventoryController extends Controller
{
    /**
     * قائمة مخزون الصيدلية مع إحصائيات سريعة.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $perPage = (int) ($validated['per_page'] ?? 20);

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $base = PharmacyMedicine::where('pharmacy_id', $pharmacy->id);

        $total = (clone $base)->count();
        $availableCount = (clone $base)->where('is_available', true)->where('quantity', '>', 0)->count();
        $outCount = (clone $base)->where(function ($query) {
            $query->where('is_available', false)->orWhere('quantity', '<=', 0);
        })->count();
        $lowCount = (clone $base)->get()->filter(fn ($i) => $i->quantity > 0 && $i->quantity <= ($i->min_stock ?? 10))->count();

        $items = $base->with('medicine')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب المخزون بنجاح',
            'data' => PharmacyMedicineResource::collection($items->items()),
            'pagination' => [
                'total' => $items->total(),
                'per_page' => $items->perPage(),
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
            ],
            'stats' => [
                'total' => $total,
                'available_count' => $availableCount,
                'low_count' => $lowCount,
                'out_count' => $outCount,
            ],
        ]);
    }

    /**
     * تحديث سريع لسطر مخزون واحد (الكمية/الحد الأدنى/التوفر).
     */
    public function update(Request $request, PharmacyMedicine $medicine): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
        if (! $pharmacy || $medicine->pharmacy_id !== $pharmacy->id) {
            return response()->json(['success' => false, 'message' => 'الدواء غير موجود في مخزون الصيدلية'], 404);
        }

        $data = $request->validate([
            'quantity' => 'required|integer|min:0',
            'min_stock' => 'nullable|integer|min:0',
            'is_available' => 'sometimes|boolean',
        ]);

        $medicine->update([
            'quantity' => $data['quantity'],
            'min_stock' => $data['min_stock'] ?? $medicine->min_stock,
            'is_available' => $request->boolean('is_available', (bool) $medicine->is_available),
        ]);

        $this->notifyIfLowStock($medicine);

        $medicine->load('medicine');

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث المخزون بنجاح',
            'data' => new PharmacyMedicineResource($medicine),
        ]);
    }

    /**
     * تحديث جماعي لكميات المخزون.
     */
    public function bulkUpdate(PharmacyInventoryBulkRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $items = $request->validated()['items'];
        $updated = 0;

        foreach ($items as $item) {
            $pm = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
                ->where('id', $item['id'])
                ->first();

            if (! $pm) {
                continue;
            }

            $payload = ['quantity' => $item['quantity']];
            if (array_key_exists('min_stock', $item) && $item['min_stock'] !== null) {
                $payload['min_stock'] = $item['min_stock'];
            }
            if (array_key_exists('is_available', $item)) {
                $payload['is_available'] = (bool) $item['is_available'];
            }

            $pm->update($payload);
            $this->notifyIfLowStock($pm);
            $updated++;
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث المخزون بنجاح',
            'data' => ['updated_count' => $updated],
        ]);
    }

    /**
     * إنشاء إشعار نقص/نفاد المخزون.
     */
    private function notifyIfLowStock(PharmacyMedicine $pm): void
    {
        $threshold = $pm->min_stock !== null ? (int) $pm->min_stock : 10;
        $pharmacyUser = $pm->pharmacy?->user;
        if (! $pharmacyUser) {
            return;
        }
        if ($pm->quantity <= 0) {
            Notification::create([
                'user_id' => $pharmacyUser->id,
                'medicine_id' => $pm->medicine_id,
                'type' => 'out_of_stock',
                'message' => __('layout.notif_out_of_stock', ['name' => $pm->medicine?->trade_name]),
                'is_read' => false,
                'created_at' => now(),
            ]);

            return;
        }
        if ($pm->quantity > 0 && $pm->quantity <= $threshold) {
            Notification::create([
                'user_id' => $pharmacyUser->id,
                'medicine_id' => $pm->medicine_id,
                'type' => 'low_stock',
                'message' => __('layout.notif_low_stock_pharmacy', [
                    'name' => $pm->medicine?->trade_name,
                    'count' => $pm->quantity,
                ]),
                'is_read' => false,
                'created_at' => now(),
            ]);
        }
    }
}
