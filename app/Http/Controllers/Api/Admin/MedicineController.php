<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Medicine;
use App\Support\Cloudinary;
use App\Support\Image;
use App\Support\MedicineCatalogCache;
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
        $data = $this->validated($request);

        $medicine = Medicine::create([
            'trade_name' => $data['name_ar'],
            'active_ingredient' => $data['active_ingredient'],
            'description' => $data['description'] ?? null,
        ]);

        /*
         * The Blade form uploads a FILE (`enctype=multipart/form-data` +
         * `Cloudinary::upload`). The API accepts that same file when it is sent,
         * and falls back to the pre-uploaded URL the client already has.
         *
         * WHY BOTH: the React app has no Cloudinary SDK, so it sends the URL a
         * two-step upload produced. The web form still sends bytes. Accepting
         * only one of the two would make this endpoint unusable from the other
         * caller, so the file wins when present and the URL is the fallback —
         * which is the same precedence the Blade controller uses implicitly.
         */
        if ($request->hasFile('image_file')) {
            $medicine->image = Cloudinary::upload($request->file('image_file'), 'medicines');
            $medicine->save();
        } elseif (! empty($data['image'])) {
            $medicine->image = $data['image'];
            $medicine->save();
        }

        if (! empty($data['alternatives'])) {
            $medicine->alternatives()->sync($data['alternatives']);
        }

        MedicineCatalogCache::bump();

        return response()->json([
            'success' => true,
            'message' => 'تمت إضافة الدواء بنجاح.',
            'data' => ['id' => $medicine->id],
        ], 201);
    }

    /** GET /api/admin/medicines/{medicine} */
    public function show(Medicine $medicine): JsonResponse
    {
        $medicine->load('alternatives:id,trade_name,active_ingredient');

        // Same rollup shape as `index`, scoped to one medicine, so the edit
        // screen can show current stock without a second request.
        $rollup = DB::table('pharmacy_medicines')
            ->where('medicine_id', $medicine->id)
            ->selectRaw('COALESCE(SUM(quantity), 0) as stock, COUNT(*) as pharmacy_count')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $medicine->id,
                'trade_name' => $medicine->trade_name,
                'active_ingredient' => $medicine->active_ingredient,
                'description' => $medicine->description,
                'image_url' => Image::url($medicine->image),
                'stock' => (int) ($rollup->stock ?? 0),
                'pharmacy_count' => (int) ($rollup->pharmacy_count ?? 0),
                'alternative_ids' => $medicine->alternatives->pluck('id')->all(),
                'alternatives' => $medicine->alternatives->map(fn ($a) => [
                    'id' => $a->id,
                    'trade_name' => $a->trade_name,
                ])->all(),
            ],
        ]);
    }

    /**
     * PUT|PATCH /api/admin/medicines/{medicine}
     *
     * Mirrors `web\Admin\MedicineController@update` exactly, INCLUDING its
     * destructive `detach()` when no alternatives are submitted — the Blade
     * screen has always treated an absent `alternatives` key as "remove them
     * all" (the TomSelect widget posts an empty list when cleared). Changing
     * that would silently diverge from the page this replaces.
     */
    public function update(Request $request, Medicine $medicine): JsonResponse
    {
        $data = $this->validated($request);

        $medicine->update([
            'trade_name' => $data['name_ar'],
            'active_ingredient' => $data['active_ingredient'],
            'description' => $data['description'] ?? null,
        ]);

        if ($request->hasFile('image_file')) {
            Cloudinary::deleteLocal($medicine->image);
            $medicine->image = Cloudinary::upload($request->file('image_file'), 'medicines');
            $medicine->save();
        } elseif (! empty($data['image']) && $data['image'] !== $medicine->image) {
            Cloudinary::deleteLocal($medicine->image);
            $medicine->image = $data['image'];
            $medicine->save();
        }

        if ($request->has('alternatives')) {
            $medicine->alternatives()->sync($data['alternatives'] ?? []);
        } else {
            $medicine->alternatives()->detach();
        }

        MedicineCatalogCache::bump();

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الدواء بنجاح.',
            'data' => ['id' => $medicine->id],
        ]);
    }

    /**
     * DELETE /api/admin/medicines/{medicine}
     *
     * The `favorites` cleanup is NOT optional: the relation is polymorphic
     * (`favoritable_type`/`favoritable_id`) so no foreign key exists to cascade,
     * and without it orphan rows make a patient's favourites list return a
     * deleted medicine. Same ordering as the web controller (M-25).
     */
    public function destroy(Medicine $medicine): JsonResponse
    {
        $id = $medicine->id;

        Favorite::where('favoritable_type', Medicine::class)
            ->where('favoritable_id', $id)
            ->delete();

        $medicine->delete();
        MedicineCatalogCache::bump();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف الدواء بنجاح.',
        ]);
    }

    /**
     * Shared validation — kept in one place so `store` and `update` can never
     * drift apart on length limits or on what counts as an existing
     * alternative.
     *
     * `image` is a URL string here (not `image|mimes:…` as in the web
     * controller) because the API receives an already-uploaded Cloudinary URL;
     * the byte-upload path validates the FILE instead.
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'active_ingredient' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'string', 'max:2048'],
            'image_file' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
            'alternatives' => ['nullable', 'array'],
            'alternatives.*' => ['integer', 'exists:medicines,id'],
        ]);
    }
}
