<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\PharmacyMedicine;
use App\Models\SearchLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MedicineController extends Controller
{
    /**
     * عرض كتالوج أدوية وزارة الصحة (مع بحث اختياري).
     */
    public function index(Request $request): JsonResponse
    {
        $catVer = $this->catalogVersion();

        $query = MohMedicine::query();

        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) >= 2) {
            SearchLog::track($q, 'api');

            $query->where(function ($builder) use ($q) {
                $this->fulltextOrLike($builder, ['trade_name', 'generic_name', 'manufacturer', 'company'], $q);
            });
        }

        $validated = $request->validate(['per_page' => 'nullable|integer|min:1|max:100']);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = (int) $request->get('page', 1);

        $items = Cache::remember("api_meds_idx|v{$catVer}|{$q}|{$page}|{$perPage}", 900, function () use ($query, $perPage) {
            return $query->orderBy('trade_name')->paginate($perPage);
        });

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الأدوية بنجاح',
            'data' => collect($items->items())->map(fn (MohMedicine $m) => $this->mohPayload($m)),
            'pagination' => [
                'total' => $items->total(),
                'per_page' => $items->perPage(),
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    /**
     * بحث عن دواء (الكتالوج العام + كتالوج وزارة الصحة) مع توفر الصيدليات.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([
                'success' => true,
                'message' => 'تم البحث بنجاح',
                'data' => [],
            ]);
        }

        SearchLog::track($q, 'api');

        $medVer = $this->medicinesVersion();
        $catVer = $this->catalogVersion();

        $result = Cache::remember("api_meds_search|v{$medVer}|v{$catVer}|{$q}", 900, function () use ($q) {
            $medicineQuery = Medicine::query();
            $this->fulltextOrLike($medicineQuery, ['trade_name', 'active_ingredient'], $q);
            $medicines = $medicineQuery->limit(10)->get();

            $mohQuery = MohMedicine::query();
            $this->fulltextOrLike($mohQuery, ['trade_name', 'generic_name'], $q);
            $mohMedicines = $mohQuery->limit(20)->get();

            return [
                'medicines' => $medicines->map(fn (Medicine $m) => $this->medicinePayload($m))->all(),
                'moh_catalog' => $mohMedicines->map(fn (MohMedicine $m) => $this->mohPayload($m))->all(),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'تم البحث بنجاح',
            'data' => [
                'medicines' => $result['medicines'],
                'moh_catalog' => $result['moh_catalog'],
            ],
        ]);
    }

    /**
     * تفاصيل دواء من الكتالوج العام مع الصيدليات المتوفرة.
     */
    public function show(string $id): JsonResponse
    {
        $medicine = Medicine::with('pharmacyMedicines.pharmacy')->findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الدواء بنجاح',
            'data' => $this->medicinePayload($medicine, true),
        ]);
    }

    /**
     * البحث عن دواء حسب المادة الفعالة.
     */
    public function byActiveIngredient(string $ingredient): JsonResponse
    {
        $medVer = $this->medicinesVersion();

        $medicines = Cache::remember("api_meds_active|v{$medVer}|{$ingredient}", 900, function () use ($ingredient) {
            $query = Medicine::query();
            $this->fulltextOrLike($query, ['active_ingredient'], $ingredient);

            return $query->orderBy('trade_name')
                ->limit(20)
                ->get();
        });

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الأدوية بنجاح',
            'data' => $medicines->map(fn (Medicine $m) => $this->medicinePayload($m)),
        ]);
    }

    /**
     * الصيدليات التي يتوفر بها دواء معين.
     */
    public function pharmacies(string $id): JsonResponse
    {
        $medicine = Medicine::findOrFail($id);

        $available = PharmacyMedicine::where('medicine_id', $medicine->id)
            ->where('is_available', true)
            ->where('quantity', '>', 0)
            ->with('pharmacy')
            ->get()
            ->map(fn (PharmacyMedicine $pm) => [
                'pharmacy_id' => $pm->pharmacy->id,
                'pharmacy_name' => $pm->pharmacy->pharmacy_name,
                'price' => (float) $pm->price,
                'quantity' => $pm->quantity,
            ]);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الصيدليات المتوفرة بنجاح',
            'data' => $available,
        ]);
    }

    private function catalogVersion(): int
    {
        return (int) Cache::get('med_catalog_version', 1);
    }

    private function medicinesVersion(): int
    {
        return (int) Cache::get('med_medicines_version', 1);
    }

    /**
     * بحث FULLTEXT على MySQL (إن توفرت الفهارس) مع تراجع إلى LIKE على باقي الأنظمة.
     */
    private function fulltextOrLike(Builder $query, array $columns, string $value): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $query->whereRaw('MATCH('.implode(',', $columns).') AGAINST (? IN BOOLEAN MODE)', [str_replace(' ', '* ', $value).'*']);

            return;
        }

        $query->where($columns[0], 'like', "%{$value}%");
        foreach (array_slice($columns, 1) as $column) {
            $query->orWhere($column, 'like', "%{$value}%");
        }
    }

    private function mohPayload(MohMedicine $m): array
    {
        return [
            'id' => $m->id,
            'trade_name' => $m->trade_name,
            'generic_name' => $m->generic_name,
            'manufacturer' => $m->manufacturer,
            'dosage_form' => $m->dosage_form,
            'product_class' => $m->product_class,
            'origin' => $m->origin,
            'official_price' => $m->official_price !== null ? (float) $m->official_price : null,
            'packaging' => $m->packaging,
            'company' => $m->company,
            'availability' => $m->availability,
            'price_updated_at' => $m->price_updated_at?->toDateString(),
        ];
    }

    private function medicinePayload(Medicine $m, bool $withPharmacies = false): array
    {
        $payload = [
            'id' => $m->id,
            'trade_name' => $m->trade_name,
            'active_ingredient' => $m->active_ingredient,
            'description' => $m->description,
            'image' => $m->image ? asset($m->image) : null,
            'is_available' => $m->is_available,
        ];

        if ($withPharmacies) {
            $payload['pharmacies'] = $m->pharmacyMedicines
                ->filter(fn ($pm) => $pm->is_available && $pm->quantity > 0)
                ->map(fn ($pm) => [
                    'pharmacy_id' => $pm->pharmacy->id,
                    'pharmacy_name' => $pm->pharmacy->pharmacy_name,
                    'price' => (float) $pm->price,
                    'quantity' => $pm->quantity,
                ])
                ->values();
        }

        return $payload;
    }
}
