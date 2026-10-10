<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * لوحة الأدمن — المرضى + المخزون (JSON).
 *
 * كنترولر واحد لأن الاثنين صفحات قراءة ضيقة بلا كتابة. لدمجهما سبب عملي:
 * كلاهما تحويل مباشر لاستعلام محسوب بالفعل في الويب، وفصل كل واحد في ملف
 * مستقل يضيف ملفين لا منطق.
 */
class PatientInventoryController extends Controller
{
    /**
     * GET /api/admin/patients
     *
     * ملاحظة: الويب يعرض `$patients->count()` موسوماً «نشط هذا الشهر» — وهذا
     * حجم الصفحة الحالية لا عدداً شهرياً (عطل موثّق في تقرير الجرد). لا أنقل
     * الوهم: أرسل `total` الحقيقي فقط، والعميل يعرضه بوسم صحيح.
     */
    public function patients(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $patients = User::where('role', 'patient')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%");
                });
            })
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'patients' => $patients->items(),
                'pagination' => [
                    'current_page' => $patients->currentPage(),
                    'last_page' => $patients->lastPage(),
                    'per_page' => $patients->perPage(),
                    'total' => $patients->total(),
                ],
                'filters' => ['q' => $q],
                'total_patients' => User::where('role', 'patient')->count(),
            ],
        ]);
    }

    /** GET /api/admin/inventory */
    public function inventory(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $medicines = Medicine::withSum('pharmacyMedicines', 'quantity')
            ->withCount('pharmacyMedicines')
            ->latest()
            ->paginate($perPage);

        $items = collect($medicines->items())->map(fn ($m) => [
            'id' => $m->id,
            'trade_name' => $m->trade_name,
            'scientific_name' => $m->active_ingredient ?? null,
            'pharmacy_medicines_sum_quantity' => $m->pharmacy_medicines_sum_quantity ?? 0,
            'pharmacy_medicines_count' => $m->pharmacy_medicines_count ?? 0,
        ])->all();

        $stockTotals = PharmacyMedicine::query()
            ->select('medicine_id')
            ->selectRaw('SUM(quantity) as total_quantity')
            ->groupBy('medicine_id');

        $summary = Medicine::query()
            ->leftJoinSub($stockTotals, 'stock_totals', function ($join) {
                $join->on('stock_totals.medicine_id', '=', 'medicines.id');
            })
            ->selectRaw('COUNT(medicines.id) as totalItems')
            ->selectRaw('SUM(CASE WHEN COALESCE(stock_totals.total_quantity, 0) > 20 THEN 1 ELSE 0 END) as inStock')
            ->selectRaw('SUM(CASE WHEN COALESCE(stock_totals.total_quantity, 0) BETWEEN 1 AND 20 THEN 1 ELSE 0 END) as lowStock')
            ->selectRaw('SUM(CASE WHEN COALESCE(stock_totals.total_quantity, 0) = 0 THEN 1 ELSE 0 END) as outOfStock')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'medicines' => $items,
                'pagination' => [
                    'current_page' => $medicines->currentPage(),
                    'last_page' => $medicines->lastPage(),
                    'per_page' => $medicines->perPage(),
                    'total' => $medicines->total(),
                ],
                'stock_summary' => [
                    'total_items' => (int) ($summary->totalItems ?? 0),
                    'in_stock' => (int) ($summary->inStock ?? 0),
                    'low_stock' => (int) ($summary->lowStock ?? 0),
                    'out_of_stock' => (int) ($summary->outOfStock ?? 0),
                ],
            ],
        ]);
    }
}
