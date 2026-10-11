<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Support\Image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * لوحة الأدمن — كتالوج الأدوية (JSON).
 *
 * الحساب منقول من `web/Admin/MedicineController@index` بنفس الاستعلامات
 * (`leftJoinSub` + تجميع على SQL) لأنها مُحسَّنة بالفعل ومصمّمة لتفادي
 * `ONLY_FULL_GROUP_BY` في MySQL.
 */
class MedicineController extends Controller
{
    /** GET /api/admin/medicines */
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', 'out', 'low', 'available'], true)) {
            $status = 'all';
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $perMedicine = DB::table('pharmacy_medicines')
            ->selectRaw('medicine_id, SUM(quantity) as stock, COUNT(*) as pharmacy_count, MIN(CASE WHEN price > 0 THEN price END) as min_price')
            ->groupBy('medicine_id');

        $base = DB::table('medicines as m')
            ->leftJoinSub($perMedicine, 'pm', 'pm.medicine_id', '=', 'm.id');

        $medicines = (clone $base)
            ->selectRaw('m.id, m.trade_name, m.active_ingredient, m.image,
                COALESCE(pm.stock, 0) as stock,
                COALESCE(pm.pharmacy_count, 0) as pharmacy_count,
                pm.min_price')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('m.trade_name', 'like', "%{$q}%")
                        ->orWhere('m.active_ingredient', 'like', "%{$q}%");
                });
            })
            ->when($status === 'out', fn ($query) => $query->whereRaw('COALESCE(pm.stock, 0) <= 0'))
            ->when($status === 'low', fn ($query) => $query->whereRaw('COALESCE(pm.stock, 0) > 0 AND COALESCE(pm.stock, 0) <= 10'))
            ->when($status === 'available', fn ($query) => $query->whereRaw('COALESCE(pm.stock, 0) > 10'))
            ->orderByDesc('m.created_at')
            ->paginate($perPage);

        $statsRow = (clone $base)->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN COALESCE(pm.stock, 0) <= 0 THEN 1 ELSE 0 END) as out_c,
                SUM(CASE WHEN COALESCE(pm.stock, 0) > 0 AND COALESCE(pm.stock, 0) <= 10 THEN 1 ELSE 0 END) as low_c,
                SUM(CASE WHEN COALESCE(pm.pharmacy_count, 0) > 0 THEN 1 ELSE 0 END) as in_pharmacy
            ")->first();

        $total = (int) ($statsRow->total ?? 0);
        $out = (int) ($statsRow->out_c ?? 0);
        $low = (int) ($statsRow->low_c ?? 0);
        $available = max(0, $total - $out - $low);
        $inPharmacy = (int) ($statsRow->in_pharmacy ?? 0);
        $pct = fn ($count) => $total > 0 ? (int) round(($count / $total) * 100) : 0;

        // `image` is a stored path, not a URL. Resolve it the same way the
        // pharmacy API does (`Image::url`) so the client gets an absolute,
        // Cloudinary-aware link — the React table then applies its own size
        // transform client-side. The raw path is dropped from the payload.
        $rows = collect($medicines->items())->map(function ($m) {
            $row = (array) $m;
            $row['image_url'] = Image::url($m->image);
            unset($row['image']);

            return $row;
        })->all();

        return response()->json([
            'success' => true,
            'data' => [
                'medicines' => $rows,
                'pagination' => [
                    'current_page' => $medicines->currentPage(),
                    'last_page' => $medicines->lastPage(),
                    'per_page' => $medicines->perPage(),
                    'total' => $medicines->total(),
                ],
                'filters' => ['q' => $q, 'status' => $status],
                'stats' => [
                    'total' => $total,
                    'available' => $available,
                    'low' => $low,
                    'out' => $out,
                    'available_pct' => $pct($available),
                    'low_pct' => $pct($low),
                    'out_pct' => $pct($out),
                    'in_pharmacy_pct' => $pct($inPharmacy),
                    'not_in_pharmacy_pct' => $pct($total - $inPharmacy),
                ],
            ],
        ]);
    }

    /** POST /api/admin/medicines */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'active_ingredient' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'string'],
            'alternatives' => ['nullable', 'array'],
            'alternatives.*' => ['integer', 'exists:medicines,id'],
        ]);

        $medicine = Medicine::create([
            'trade_name' => $request->name_ar,
            'active_ingredient' => $request->active_ingredient,
            'description' => $request->description,
            'image' => $request->image,
        ]);

        if ($request->filled('alternatives')) {
            $medicine->alternatives()->sync($request->alternatives);
        }

        \App\Support\MedicineCatalogCache::bump();

        return response()->json([
            'success' => true,
            'message' => 'تمت إضافة الدواء بنجاح.',
            'data' => ['id' => $medicine->id],
        ], 201);
    }
}
