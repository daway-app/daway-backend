<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PharmacyInventoryBulkRequest;
use App\Http\Resources\PharmacyMedicineResource;
use App\Models\PharmacyMedicine;
use App\Services\PharmacyContext;
use App\Support\LowStockNotifier;
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

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $base = PharmacyMedicine::where('pharmacy_id', $pharmacy->id);

        // Consolidate the four inventory counters into one query. Keep the
        // inventory-page out_count rule (unavailable OR zero stock), which is
        // intentionally different from the dashboard's quantity-only rule.
        $counts = (clone $base)->selectRaw("
            COUNT(*) AS total,
            SUM(CASE WHEN is_available = 1 AND quantity > 0 THEN 1 ELSE 0 END) AS available_count,
            SUM(CASE WHEN is_available = 0 OR quantity <= 0 THEN 1 ELSE 0 END) AS out_count,
            SUM(CASE WHEN quantity > 0 AND quantity <= ? THEN 1 ELSE 0 END) AS low_count
        ", [PharmacyMedicine::LOW_STOCK_THRESHOLD])->first();

        $total = (int) $counts->total;
        $availableCount = (int) $counts->available_count;
        $outCount = (int) $counts->out_count;
        $lowCount = (int) $counts->low_count;

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
     * تحديث سريع لسطر مخزون واحد (الكمية/التوفر).
     */
    public function update(Request $request, PharmacyMedicine $medicine): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy || $medicine->pharmacy_id !== $pharmacy->id) {
            return response()->json(['success' => false, 'message' => 'الدواء غير موجود في مخزون الصيدلية'], 404);
        }

        $data = $request->validate([
            'quantity' => 'required|integer|min:0',
            'is_available' => 'sometimes|boolean',
        ]);

        $medicine->update([
            'quantity' => $data['quantity'],
            'is_available' => $request->boolean('is_available', (bool) $medicine->is_available),
        ]);

        LowStockNotifier::notifyIfLowStock($medicine);

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

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $items = $request->validated()['items'];
        $updated = 0;
        $affectedIds = [];

        // M-11: batched — استعلام واحد بدل SELECT لكل عنصر (N+1)
        $rows = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->whereIn('id', collect($items)->pluck('id')->all())
            ->get()
            ->keyBy('id');

        foreach ($items as $item) {
            $pm = $rows->get($item['id']);

            if (! $pm) {
                continue;
            }

            $payload = ['quantity' => $item['quantity']];
            if (array_key_exists('is_available', $item)) {
                $payload['is_available'] = (bool) $item['is_available'];
            }

            $pm->update($payload);
            $affectedIds[] = $pm->id;
            $updated++;
        }

        // إشعار واحد مجمّع (نفس نمط SyncService::notifyAffected) بدل إشعار لكل صف
        $affected = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->whereIn('id', $affectedIds)
            ->with('medicine', 'pharmacy.user')
            ->get()
            ->each(fn (PharmacyMedicine $pm) => LowStockNotifier::notifyIfLowStock($pm));

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث المخزون بنجاح',
            'data' => ['updated_count' => $updated],
        ]);
    }
}