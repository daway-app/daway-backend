<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Support\CategoryCatalogCache;
use App\Support\DosageFormNormalizer;
use App\Support\Image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * أقسام الكتالوج العامة — عامة بدون مصادقة (بيانات وصفية للكتالوج).
 *
 * الروابط مع الأدوية تعتمد المفاتيح المستقرة فقط (moh_product_id / moh_drug_id)
 * ولا يُشار إلى moh_medicines.id مطلقاً — فهو غير مستقر عبر moh:import / moh:sync
 * (كلاهما يعمل delete-all ثم insert).
 */
class CategoryController extends Controller
{
    private const CATEGORIES_CACHE_KEY = 'api_categories_list';
    private const CACHE_TTL = 900;

    /**
     * قائمة الأقسام النشطة مرتّبة (كاش كامل — الأقسام تتغير نادراً).
     */
    public function index(): JsonResponse
    {
        $categoryVersion = CategoryCatalogCache::version();
        $cacheKey = self::CATEGORIES_CACHE_KEY.'|v'.$categoryVersion;
        $categories = Cache::remember($cacheKey, self::CACHE_TTL, function () {
            return Category::query()
                ->active()
                ->ordered()
                ->withCount('categoryMedicineLinks')
                ->get()
                ->map(fn (Category $category) => $this->payload($category))
                ->values()
                ->all();
        });

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الأقسام بنجاح',
            'data' => $categories,
        ]);
    }

    /**
     * قسم واحد — يقبل id رقمي أو slug. العام: النشط فقط.
     */
    public function show(string $category): JsonResponse
    {
        $model = $this->resolveActiveCategory($category);

        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'القسم غير موجود'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم جلب القسم بنجاح',
            'data' => $this->payload($model),
        ]);
    }

    /**
     * أدوية كتالوج وزارة الصحة التابعة لقسم معيّن (مقسّمة صفحات).
     */
    public function medicines(Request $request, string $category): JsonResponse
    {
        $model = $this->resolveActiveCategory($category);

        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'القسم غير موجود'], 404);
        }

        $validated = $request->validate(['per_page' => 'nullable|integer|min:1|max:100']);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = (int) $request->get('page', 1);
        $q = trim((string) $request->get('q', ''));

        $query = MohMedicine::query();

        // null-safe: كل ارتباط يُقيَّد بـ whereNotNull داخلي (صفوف الروابط ذات
        // moh_product_id/moh_drug_id فارغة لا تُصادف أصلاً عبر whereColumn مع NULL،
        // لكن القيد الصريح يجعل المقصد واضحاً ويستفيد من الفهارس المنفصلة).
        $query->where(function ($outer) use ($model) {
            $outer->whereExists(function ($sub) use ($model) {
                $sub->selectRaw(1)
                    ->from('category_medicine_links')
                    ->whereColumn('category_medicine_links.moh_product_id', 'moh_medicines.moh_product_id')
                    ->where('category_medicine_links.category_id', $model->id)
                    ->whereNotNull('category_medicine_links.moh_product_id');
            })->orWhereExists(function ($sub) use ($model) {
                $sub->selectRaw(1)
                    ->from('category_medicine_links')
                    ->whereColumn('category_medicine_links.moh_drug_id', 'moh_medicines.moh_drug_id')
                    ->where('category_medicine_links.category_id', $model->id)
                    ->whereNotNull('category_medicine_links.moh_drug_id');
            });
        });

        // بحث اختياري بسيط داخل أدوية القسم (LIKE — بديل مبسّط لـ fulltextOrLike)
        if (mb_strlen($q) >= 2) {
            $query->where(function ($builder) use ($q) {
                $builder->where('trade_name', 'like', "%{$q}%")
                    ->orWhere('generic_name', 'like', "%{$q}%");
            });
        }

        $catalogVersion = (int) Cache::get('med_catalog_version', 1);
        $categoryVersion = CategoryCatalogCache::version();
        $key = "api_cat_meds|v{$catalogVersion}|cv{$categoryVersion}|cat{$model->id}|{$page}|{$perPage}";
        if (mb_strlen($q) >= 2) {
            $key .= '|q'.str_replace('|', ' ', (string) preg_replace('/\s+/u', ' ', mb_substr($q, 0, 100)));
        }

        $items = Cache::remember($key, self::CACHE_TTL, fn () => $query->orderBy('trade_name')->paginate($perPage));

        // خريطة الربط بالكتالوج المحلي تُحسب خارج الكاش عمداً: الصيدليات تُنشئ
        // أدوية محلية في أي وقت بدون أن يمسّ ذلك نسخة كاش الأقسام.
        $pageItems = collect($items->items());
        $localMedicineIds = Medicine::idsByTradeName($pageItems->pluck('trade_name')->all());

        return response()->json([
            'success' => true,
            'message' => 'تم جلب أدوية القسم بنجاح',
            'data' => $pageItems->map(fn (MohMedicine $m) => $this->mohPayload($m)
                + ['medicine_id' => $localMedicineIds[$m->trade_name] ?? null]),
            'pagination' => [
                'total' => $items->total(),
                'per_page' => $items->perPage(),
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    /**
     * القائمة القياسية لأشكال الجرعات (facets) لاستخدامها مع فلتر dosage_form.
     */
    public function dosageForms(): JsonResponse
    {
        $facets = Cache::remember('api_dosage_forms_v1', self::CACHE_TTL, fn () => DosageFormNormalizer::facets());

        return response()->json([
            'success' => true,
            'message' => 'تم جلب أشكال الأدوية بنجاح',
            'data' => $facets,
        ]);
    }

    /**
     * {category} يقبل id أو slug — يُحل يدوياً (بدون route model binding)
     * والنشط فقط للعام. SoftDeletes يستبعد المحذوف تلقائياً.
     */
    private function resolveActiveCategory(string $idOrSlug): ?Category
    {
        $query = Category::query()
            ->active()
            ->withCount('categoryMedicineLinks');

        return ctype_digit($idOrSlug)
            ? $query->find((int) $idOrSlug)
            : $query->where('slug', $idOrSlug)->first();
    }

    private function payload(Category $category): array
    {
        return [
            'id' => $category->id,
            'name_ar' => $category->name_ar,
            'name_en' => $category->name_en,
            'slug' => $category->slug,
            'image' => Image::url($category->image),
            'is_active' => (bool) $category->is_active,
            'sort_order' => (int) $category->sort_order,
            'medicines_count' => (int) ($category->category_medicine_links_count ?? 0),
        ];
    }

    /**
     * نفس شكل صف الكتالوج في MedicineController@index (mohPayload).
     *
     * medicine_id (nullable) يُضاف من المتصل عبر Medicine::idsByTradeName()
     * = معرّف الدواء في الكتالوج المحلي حين يوجد مطابق بالاسم، حتى يستطيع
     * العميل إكمال مسار التفاصيل/التوفر بدل الاعتماد على moh_medicines.id.
     */
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
}
