<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Support\CategoryCatalogCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * لوحة الأدمن — الأقسام (JSON).
 *
 * منطق الربط منقول حرفياً من `web/Admin/CategoryController`، وبخاصة القاعدة
 * الحاكمة الموثّقة في الذاكرة:
 *
 *   🔴 كتالوج الوزارة يُربط بمفاتيح `moh_product_id` / `moh_drug_id` **فقط** —
 *      أبداً `moh_medicines.id` (معرّف داخلي غير مستقر). المخالفة تُنشئ روابط
 *      تشير إلى صفوف خاطئة بعد أي إعادة استيراد للكتالوج.
 */
class CategoryController extends Controller
{
    /** GET /api/admin/categories */
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $categories = Category::query()
            ->withCount('categoryMedicineLinks')
            ->withCount(['categoryMedicineLinks as needs_review_count' => function ($query) {
                $query->where('needs_review', true);
            }])
            ->with(['subcategories' => fn ($query) => $query->active()->ordered()])
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('name_ar', 'like', "%{$q}%")
                ->orWhere('name_en', 'like', "%{$q}%")))
            ->ordered()
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data' => [
                'categories' => $categories->items(),
                'pagination' => [
                    'current_page' => $categories->currentPage(),
                    'last_page' => $categories->lastPage(),
                    'per_page' => $categories->perPage(),
                    'total' => $categories->total(),
                ],
                'filters' => ['q' => $q],
                'stats' => [
                    'total' => Category::count(),
                    'active' => Category::where('is_active', true)->count(),
                    'links' => CategoryMedicineLink::count(),
                    'needs_review' => CategoryMedicineLink::where('needs_review', true)->count(),
                ],
            ],
        ]);
    }

    /** PATCH /api/admin/categories/{category}/toggle-status */
    public function toggleStatus(Category $category): JsonResponse
    {
        $category->is_active = ! $category->is_active;
        $category->save();
        CategoryCatalogCache::bump();

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث حالة القسم.',
            'data' => ['is_active' => (bool) $category->is_active],
        ]);
    }

    /** POST /api/admin/categories/{category}/medicines */
    public function attachMedicine(Request $request, Category $category): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:moh,medicine'],
            'moh_product_id' => ['nullable', 'integer', 'min:1'],
            'moh_drug_id' => ['nullable', 'integer', 'min:1'],
            'medicine_id' => ['nullable', 'integer', 'exists:medicines,id'],
        ]);

        if ($data['type'] === 'medicine') {
            if (empty($data['medicine_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'تعذر تحديد الدواء المطلوب ربطه.',
                ], 422);
            }

            CategoryMedicineLink::firstOrCreate(
                ['category_id' => $category->id, 'medicine_id' => $data['medicine_id']],
                ['source' => CategoryMedicineLink::SOURCE_ADMIN, 'confidence' => 100, 'needs_review' => false]
            );

            CategoryCatalogCache::bump();

            return response()->json(['success' => true, 'message' => 'تم ربط الدواء بالقسم بنجاح.']);
        }

        $productId = $data['moh_product_id'] ?? null;
        $drugId = $data['moh_drug_id'] ?? null;

        if (! $productId && ! $drugId) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن ربط هذا الدواء: معرّفات وزارة الصحة غير متوفرة.',
            ], 422);
        }

        $catalogRowExists = MohMedicine::query()
            ->when($productId, fn ($query) => $query->where('moh_product_id', $productId))
            ->when($drugId, fn ($query) => $query->where('moh_drug_id', $drugId))
            ->exists();

        if (! $catalogRowExists) {
            return response()->json([
                'success' => false,
                'message' => 'تعذر العثور على الدواء في كتالوج وزارة الصحة.',
            ], 422);
        }

        $existing = CategoryMedicineLink::query()
            ->where('category_id', $category->id)
            ->when($productId && $drugId, fn ($query) => $query->where(fn ($w) => $w
                ->where('moh_product_id', $productId)->orWhere('moh_drug_id', $drugId)))
            ->when($productId && ! $drugId, fn ($query) => $query->where('moh_product_id', $productId))
            ->when(! $productId && $drugId, fn ($query) => $query->where('moh_drug_id', $drugId))
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الدواء مرتبط مسبقاً بهذا القسم.',
            ], 422);
        }

        CategoryMedicineLink::create([
            'category_id' => $category->id,
            'moh_product_id' => $productId,
            'moh_drug_id' => $drugId,
            'medicine_id' => null,
            'source' => CategoryMedicineLink::SOURCE_ADMIN,
            'confidence' => 100,
            'needs_review' => false,
        ]);

        CategoryCatalogCache::bump();

        return response()->json(['success' => true, 'message' => 'تم ربط الدواء بالقسم بنجاح.']);
    }

    /** DELETE /api/admin/categories/{category}/medicines/{link} */
    public function detachMedicine(Category $category, CategoryMedicineLink $link): JsonResponse
    {
        if ($link->category_id !== $category->id) {
            return response()->json(['success' => false, 'message' => 'الرابط لا ينتمي لهذا القسم.'], 404);
        }

        $link->delete();
        CategoryCatalogCache::bump();

        return response()->json(['success' => true, 'message' => 'تم إزالة الدواء من القسم.']);
    }

    /** POST /api/admin/categories/{category}/review/{link} */
    public function approveReview(Category $category, CategoryMedicineLink $link): JsonResponse
    {
        if ($link->category_id !== $category->id) {
            return response()->json(['success' => false, 'message' => 'الرابط لا ينتمي لهذا القسم.'], 404);
        }

        $link->update([
            'needs_review' => false,
            'source' => CategoryMedicineLink::SOURCE_ADMIN,
            'confidence' => 100,
        ]);

        CategoryCatalogCache::bump();

        return response()->json(['success' => true, 'message' => 'تم اعتماد الربط بنجاح.']);
    }

    /** GET /api/admin/categories/{category}/links */
    public function links(Request $request, Category $category): JsonResponse
    {
        $review = $request->query('review');
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $links = CategoryMedicineLink::query()
            ->where('category_id', $category->id)
            ->when($review === '1', fn ($q) => $q->where('needs_review', true))
            ->orderByDesc('id')
            ->paginate($perPage);

        // حل أسماء الأدوية في استعلام واحد لكل نوع (لا N+1)
        $localIds = collect($links->items())->pluck('medicine_id')->filter()->unique();
        $localNames = Medicine::whereIn('id', $localIds)->pluck('trade_name', 'id');

        $mohProductIds = collect($links->items())->pluck('moh_product_id')->filter()->unique();
        $mohNames = MohMedicine::whereIn('moh_product_id', $mohProductIds)->pluck('trade_name', 'moh_product_id');

        $rows = collect($links->items())->map(function ($l) use ($localNames, $mohNames) {
            $name = $l->medicine_id
                ? ($localNames[$l->medicine_id] ?? null)
                : ($l->moh_product_id ? ($mohNames[$l->moh_product_id] ?? null) : null);

            return [
                'id' => $l->id,
                'type' => $l->medicine_id ? 'medicine' : 'moh',
                'medicine_id' => $l->medicine_id,
                'moh_product_id' => $l->moh_product_id,
                'moh_drug_id' => $l->moh_drug_id,
                'name' => $name,
                'source' => $l->source,
                'confidence' => $l->confidence,
                'needs_review' => (bool) $l->needs_review,
            ];
        })->all();

        return response()->json([
            'success' => true,
            'data' => [
                'links' => $rows,
                'pagination' => [
                    'current_page' => $links->currentPage(),
                    'last_page' => $links->lastPage(),
                    'per_page' => $links->perPage(),
                    'total' => $links->total(),
                ],
            ],
        ]);
    }
}
