<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Subcategory;
use App\Support\CategoryCatalogCache;
use App\Support\DosageFormNormalizer;
use App\Support\Image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * أقسام الكتالوج العامة — عامة بدون مصادقة (بيانات وصفية للكتالوج).
 *
 * الروابط مع الأدوية تعتمد المفاتيح المستقرة فقط (moh_product_id / moh_drug_id)
 * ولا يُشار إلى moh_medicines.id في الروابط — فـ moh:import / moh:sync يحافظان
 * على نفس moh_medicines.id لنفس المنتج (key-aware upsert بلا delete-all).
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
        // Patient default = available only: counts depend on inventory too,
        // so the key includes the catalog version (bumped on inventory writes).
        $catalogVersion = (int) Cache::get('med_catalog_version', 1);
        $cacheKey = self::CATEGORIES_CACHE_KEY.'|v'.$categoryVersion.'|mv'.$catalogVersion.'|availdef1';
        $categories = Cache::remember($cacheKey, self::CACHE_TTL, function () {
            $models = Category::query()
                ->active()
                ->ordered()
                ->with(['subcategories' => fn ($q) => $q->active()->ordered()])
                ->get();
            $catCounts = $this->availableCategoryCounts($models->pluck('id')->all());
            $subCounts = $this->availableSubcategoryCounts(
                $models->flatMap(fn (Category $c) => $c->subcategories->pluck('id'))->unique()->values()->all()
            );

            return $models
                ->map(fn (Category $category) => $this->payload($category, $catCounts, $subCounts))
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

        $catCounts = $this->availableCategoryCounts([$model->id]);
        $subCounts = $this->availableSubcategoryCounts($model->subcategories->pluck('id')->all());

        return response()->json([
            'success' => true,
            'message' => 'تم جلب القسم بنجاح',
            'data' => $this->payload($model, $catCounts, $subCounts),
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

        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'subcategory_id' => 'nullable|integer|min:1',
            'subcategory' => 'nullable|string|max:180',
            'dosage_form' => 'nullable|string|max:50',
            // توافق خلفي فقط: المريض يرى المتوفر دائماً (DEFAULT)، والقيمة تُتجاهل.
            // لا يجعل available_only=0 المسار يعرض الكتالوج الكامل.
            'available_only' => 'nullable|boolean',
        ]);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = (int) $request->get('page', 1);
        $q = trim((string) $request->get('q', ''));

        // القسم الفرعي: رقمي أو slug، ويُقيَّد حصراً بقسمه الرئيسي (المُحلّ من
        // المسار) — فلا يمكن تمرير قسم فرعي تابع لقسم آخر.
        $subcategorySelector = $validated['subcategory_id'] ?? $validated['subcategory'] ?? null;
        $subcategoryId = null;
        if ($subcategorySelector !== null && $subcategorySelector !== '') {
            $subcategoryQuery = Subcategory::query()
                ->active()
                ->where('category_id', $model->id);
            $subcategory = is_numeric($subcategorySelector)
                ? $subcategoryQuery->find((int) $subcategorySelector)
                : $subcategoryQuery->where('slug', (string) $subcategorySelector)->first();
            $subcategoryId = $subcategory?->id;
        }

        // قيمة غير معروفة → null → تُتجاهل بصمت (نفس سلوك category_id في MedicineController)
        $dosageForm = isset($validated['dosage_form']) ? DosageFormNormalizer::forInput($validated['dosage_form']) : null;

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

        // فلتر القسم الفرعي
        if ($subcategoryId !== null) {
            $query->where(function ($outer) use ($subcategoryId) {
                $outer->whereExists(function ($sub) use ($subcategoryId) {
                    $sub->selectRaw(1)
                        ->from('category_medicine_links')
                        ->whereColumn('category_medicine_links.moh_product_id', 'moh_medicines.moh_product_id')
                        ->where('category_medicine_links.subcategory_id', $subcategoryId)
                        ->whereNotNull('category_medicine_links.moh_product_id');
                })->orWhereExists(function ($sub) use ($subcategoryId) {
                    $sub->selectRaw(1)
                        ->from('category_medicine_links')
                        ->whereColumn('category_medicine_links.moh_drug_id', 'moh_medicines.moh_drug_id')
                        ->where('category_medicine_links.subcategory_id', $subcategoryId)
                        ->whereNotNull('category_medicine_links.moh_drug_id');
                });
            });
        }

        // الشكل الدوائي — نفس منطق MedicineController (tokens قياسية + استثناءات)
        if ($dosageForm !== null) {
            $query->where(function ($builder) use ($dosageForm) {
                foreach (DosageFormNormalizer::likeTokens($dosageForm) as $i => $token) {
                    $i === 0
                        ? $builder->where('moh_medicines.dosage_form', 'like', "%{$token}%")
                        : $builder->orWhere('moh_medicines.dosage_form', 'like', "%{$token}%");
                }

                foreach (DosageFormNormalizer::excludeTokens($dosageForm) as $excluded) {
                    $builder->where('moh_medicines.dosage_form', 'not like', "%{$excluded}%");
                }
            });
        }

        // بحث اختياري بسيط داخل أدوية القسم (LIKE — بديل مبسّط لـ fulltextOrLike)
        if (mb_strlen($q) >= 2) {
            $query->where(function ($builder) use ($q) {
                $builder->where('trade_name', 'like', "%{$q}%")
                    ->orWhere('generic_name', 'like', "%{$q}%");
            });
        }

        // Patient DEFAULT: moh موجود + مخزون مرتبط فعلياً + متاح + كمية + صيدلية
        // نشطة. دائماً مطبّق هنا — عرض الإدارة (web/Admin) لا يستخدم هذا المسار أصلاً.
        $this->whereAvailable($query);

        $catalogVersion = (int) Cache::get('med_catalog_version', 1);
        $categoryVersion = CategoryCatalogCache::version();
        // availdef1: السلوك الافتراضي تغيّر (متوفر فقط) — مفتاح جديد حتى لا تُخدم
        // نتائج الكتالوج الكامل المخزّنة سابقاً.
        $key = "api_cat_meds|v{$catalogVersion}|cv{$categoryVersion}|availdef1|cat{$model->id}|{$page}|{$perPage}";
        if ($subcategoryId !== null) {
            $key .= '|sub'.$subcategoryId;
        }
        if ($dosageForm !== null) {
            $key .= '|df'.array_search($dosageForm, DosageFormNormalizer::facets(), true);
        }
        if (mb_strlen($q) >= 2) {
            $key .= '|q'.str_replace('|', ' ', (string) preg_replace('/\s+/u', ' ', mb_substr($q, 0, 100)));
        }

        $items = Cache::remember($key, self::CACHE_TTL, fn () => $query->orderBy('trade_name')->paginate($perPage));

        // خريطة الربط بالكتالوج المحلي تُحسب خارج الكاش عمداً: الصيدليات تُنشئ
        // أدوية محلية في أي وقت بدون أن يمسّ ذلك نسخة كاش الأقسام.
        $pageItems = collect($items->items());
        $localMedicineIds = Medicine::idsByTradeName($pageItems->pluck('trade_name')->all());

        // بيانات التصنيف (source/confidence/needs_review) من category_medicine_links
        // تُحسب خارج الكاش أيضاً — تتغير مع كل sync/admin تعديل، والـcache version
        // يضمن الاتساق لأن مفتاح الكاش يشمل categoryVersion.
        $linkMeta = $this->fetchLinkMeta($model->id, $pageItems);

        // مسار محلي إضافي (لا يمسّ مسار MOH): أدوية local canonical معتمدة
        // تظهر في القسم عبر category_medicine_links.medicine_id + مخزون متوفر.
        $localRows = $this->availableLocalMedicines($model->id, $subcategoryId, $q);

        // صور أدوية MOH للصفحة الحالية — نداء واحد (لا N+1).
        $mohImages = $this->mohImages($pageItems);

        // عدد الصيدليات المتوفر لديها كل دواء — نداء واحد لكل مصدر (لا N+1).
        $mohPharmacyCounts = $this->mohPharmacyCounts($pageItems);
        $localPharmacyCounts = $this->localPharmacyCounts($localRows);

        $mohData = $pageItems->map(fn (MohMedicine $m) => $this->mohPayload(
            $m,
            $mohImages[$m->id] ?? null,
            $mohPharmacyCounts[$m->id] ?? 0
        )
            + ['medicine_id' => $localMedicineIds[$m->trade_name] ?? null]
            + ($linkMeta[$m->moh_product_id] ?? $linkMeta['d:'.$m->moh_drug_id] ?? [
                'source' => null,
                'confidence' => null,
                'needs_review' => null,
            ]))->values();

        // دمج المحلي مع حذف المكرر (دواء محلي بنفس اسم دواء MOH موجود لا يُعاد)
        $mohNames = $mohData->pluck('trade_name')->all();
        $localData = collect($localRows)
            ->map(fn (Medicine $m) => $this->localPayload($m, $localPharmacyCounts[$m->id] ?? 0))
            ->filter(fn ($row) => ! in_array($row['trade_name'], $mohNames, true))
            ->values();

        $data = $mohData->concat($localData)->values();

        $localCount = $localData->count();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب أدوية القسم بنجاح',
            'data' => $data->all(),
            'pagination' => [
                'total' => $items->total() + $localCount,
                'per_page' => $items->perPage(),
                'current_page' => $items->currentPage(),
                'last_page' => max(1, (int) ceil(($items->total() + $localCount) / $items->perPage())),
            ],
        ]);
    }

    /**
     * Admin: Full catalog of MOH medicines in a category (no availability filter).
     * Requires role:admin. Used by admin tools to manage the complete catalog.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $category
     * @return \Illuminate\Http\JsonResponse
     */
    public function adminMedicines(Request $request, string $category): JsonResponse
    {
        if (! $request->user() || $request->user()->role !== 'admin') {
            return response()->json(['success' => false, 'message' => 'غير مخول'], 403);
        }

        $model = $this->resolveActiveCategory($category);

        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'القسم غير موجود'], 404);
        }

        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'subcategory_id' => 'nullable|integer|min:1',
            'dosage_form' => 'nullable|string|max:50',
            'q' => 'nullable|string|max:180',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = (int) $request->get('page', 1);
        $q = trim((string) ($validated['q'] ?? ''));
        $subcategoryId = $validated['subcategory_id'] ?? null;
        $dosageForm = isset($validated['dosage_form']) ? DosageFormNormalizer::forInput($validated['dosage_form']) : null;

        $query = MohMedicine::query();

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

        if ($subcategoryId !== null) {
            $query->where(function ($outer) use ($subcategoryId) {
                $outer->whereExists(function ($sub) use ($subcategoryId) {
                    $sub->selectRaw(1)
                        ->from('category_medicine_links')
                        ->whereColumn('category_medicine_links.moh_product_id', 'moh_medicines.moh_product_id')
                        ->where('category_medicine_links.subcategory_id', $subcategoryId)
                        ->whereNotNull('category_medicine_links.moh_product_id');
                })->orWhereExists(function ($sub) use ($subcategoryId) {
                    $sub->selectRaw(1)
                        ->from('category_medicine_links')
                        ->whereColumn('category_medicine_links.moh_drug_id', 'moh_medicines.moh_drug_id')
                        ->where('category_medicine_links.subcategory_id', $subcategoryId)
                        ->whereNotNull('category_medicine_links.moh_drug_id');
                });
            });
        }

        if ($dosageForm !== null) {
            $query->where(function ($builder) use ($dosageForm) {
                $builder->where(function ($inner) use ($dosageForm) {
                    $inner->whereJsonContains('moh_medicines.dosage_forms', $dosageForm)
                        ->whereNotNull('moh_medicines.moh_product_id');
                })->orWhere(function ($inner) use ($dosageForm) {
                    $inner->whereJsonContains('moh_medicines.dosage_forms', $dosageForm)
                        ->whereNotNull('moh_medicines.moh_drug_id');
                });
            });
        }

        if ($q !== '') {
            $query->where(function ($builder) use ($q) {
                $builder->where('trade_name', 'like', "%{$q}%")
                    ->orWhere('generic_name', 'like', "%{$q}%");
            });
        }

        $total = $query->count();
        $medicines = $query->orderBy('trade_name')
            ->skip(($page - 1) * $perPage)->take($perPage)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'moh_medicine_id' => $m->moh_medicine_id ?? $m->id,
                'trade_name' => $m->trade_name,
                'generic_name' => $m->generic_name,
                'dosage_form' => $m->dosage_form,
                'unit' => $m->unit,
                'strength' => $m->strength,
            ]);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الكتالوج الكامل للقسم',
            'data' => $medicines,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil($total / $perPage),
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
     * كل خيارات فلاتر الكتالوج في نداء واحد — الأقسام (مع أقسامها الفرعية)
     * وأشكال الجرعات.
     *
     * الغرض: الواجهة تبني شاشة "تصفية النتائج" من نداء واحد بدل عدة نداءات،
     * وكل خيار يحمل عدد الأدوية المتوفرة فعلاً تحته (facets_counts) حتى لا
     * تعرض الواجهة فلتراً يرجع صفراً.
     *
     * الأقسام غير النشطة تُستبعد (نفس index)، وكذلك الأقسام الفرعية غير النشطة.
     */
    public function filters(): JsonResponse
    {
        $categoryVersion = CategoryCatalogCache::version();
        $catalogVersion = (int) Cache::get('med_catalog_version', 1);
        // availdef1: العدّادات أصبحت للمتوفر فقط — مفتاح جديد يبطل الكاش القديم.
        $cacheKey = "api_medicine_filters|v{$catalogVersion}|cv{$categoryVersion}|availdef1";

        $payload = Cache::remember($cacheKey, self::CACHE_TTL, function () {
            $categories = Category::query()
                ->active()
                ->ordered()
                ->with(['subcategories' => fn ($q) => $q->active()->ordered()->withCount('categoryMedicineLinks')])
                ->get();

            return [
                'categories' => $categories->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name_ar' => $category->name_ar,
                    'name_en' => $category->name_en,
                    'slug' => $category->slug,
                    'medicines_count' => $this->categoryMedicinesCount($category->id),
                    'subcategories' => $category->subcategories->map(fn (Subcategory $sub) => [
                        'id' => $sub->id,
                        'name_ar' => $sub->name_ar,
                        'name_en' => $sub->name_en,
                        'slug' => $sub->slug,
                        'group_key' => $sub->group_key,
                        'medicines_count' => $this->subcategoryMedicinesCount($sub->id),
                    ])->values()->all(),
                ])->values()->all(),
                'dosage_forms' => $this->dosageFormFacets(),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'تم جلب خيارات الفلاتر بنجاح',
            'data' => $payload,
        ]);
    }

    /**
     * Patient DEFAULT: الدواء مرئي فقط مع مخزون متوفر فعلياً في صيدلية نشطة
     * واحدة على الأقل (moh_medicine_id + is_available + quantity>0 + is_active).
     * تعريف واحد تشترك فيه القائمة والعدّادات حتى تتطابق الأرقام مع النتائج.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    private function whereAvailable($query, string $mohAlias = 'moh_medicines'): void
    {
        $query->whereExists(function ($sub) use ($mohAlias) {
            $sub->selectRaw(1)
                ->from('pharmacy_medicines as pm')
                ->join('pharmacies as p', 'p.id', '=', 'pm.pharmacy_id')
                ->whereColumn('pm.moh_medicine_id', $mohAlias.'.id')
                ->where('pm.is_available', true)
                ->where('pm.quantity', '>', 0)
                ->where('p.is_active', true);
        });
    }

    /**
     * مسار محلي إضافي (لا يمسّ مسار MOH): أدوية local canonical معتمدة تظهر في
     * القسم عبر category_medicine_links.medicine_id + مخزون متوفر فعلاً.
     * كل دواء يظهر مرة واحدة مهما تكررت صيدلياته أو روابطه.
     */
    private function availableLocalMedicines(int $categoryId, ?int $subcategoryId, string $q): array
    {
        $q = trim($q);

        $rows = Medicine::query()
            ->whereExists(function ($sub) use ($categoryId, $subcategoryId) {
                $sub->selectRaw(1)
                    ->from('category_medicine_links as l')
                    ->where('l.category_id', $categoryId)
                    ->whereNotNull('l.medicine_id')
                    ->whereColumn('l.medicine_id', 'medicines.id');
                if ($subcategoryId !== null) {
                    $sub->where('l.subcategory_id', $subcategoryId);
                }
            })
            ->whereExists(function ($sub) {
                $sub->selectRaw(1)
                    ->from('pharmacy_medicines as pm')
                    ->join('pharmacies as p', 'p.id', '=', 'pm.pharmacy_id')
                    ->whereColumn('pm.medicine_id', 'medicines.id')
                    ->where('pm.is_available', true)
                    ->where('pm.quantity', '>', 0)
                    ->where('p.is_active', true);
            })
            ->when($q !== '', fn ($query) => $query->where('trade_name', 'like', "%{$q}%"))
            ->orderBy('trade_name')
            ->get()
            ->all();

        return $rows;
    }

    /** شكل صف الدواء المحلي بنفس مفاتيح mohPayload + medicine_id (توافق العقد). */
    private function localPayload(Medicine $m, int $pharmaciesCount = 0): array
    {
        return [
            'id' => $m->id,
            'medicine_id' => $m->id,
            'trade_name' => $m->trade_name,
            'generic_name' => $m->active_ingredient,
            'manufacturer' => null,
            'dosage_form' => null,
            'product_class' => null,
            'origin' => null,
            'official_price' => null,
            'packaging' => null,
            'company' => null,
            'availability' => null,
            'price_updated_at' => null,
            // مصدر الصور المعتمد محليًا = عمود medicines.image (نفس ما تستخدمه
            // PharmacyMedicineResource/MedicineController) — لا روابط وهمية.
            'image_url' => Image::url($m->image),
            'pharmacies_count' => $pharmaciesCount,
            'source' => CategoryMedicineLink::SOURCE_ADMIN,
            'confidence' => 100,
            'needs_review' => false,
        ];
    }

    /**
     * عدد الأدوية *المتوفرة* (مميزة) في أقسام رئيسية — دفعة واحدة (استعلامان
     * فقط مهما بلغ عدد الأقسام) بنفس تعريف whereAvailable حرفياً.
     *
     * @param  array<int>  $categoryIds
     * @return array<int, int>
     */
    private function availableCategoryCounts(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $rows = DB::table('moh_medicines as m')
            ->join('category_medicine_links as l', function ($join) {
                $join->where(function ($w) {
                    $w->whereColumn('l.moh_product_id', 'm.moh_product_id')
                        ->whereNotNull('l.moh_product_id');
                })->orWhere(function ($w) {
                    $w->whereColumn('l.moh_drug_id', 'm.moh_drug_id')
                        ->whereNotNull('l.moh_drug_id');
                });
            })
            ->whereIn('l.category_id', $categoryIds)
            ->whereExists(function ($sub) {
                $sub->selectRaw(1)
                    ->from('pharmacy_medicines as pm')
                    ->join('pharmacies as p', 'p.id', '=', 'pm.pharmacy_id')
                    ->whereColumn('pm.moh_medicine_id', 'm.id')
                    ->where('pm.is_available', true)
                    ->where('pm.quantity', '>', 0)
                    ->where('p.is_active', true);
            })
            ->groupBy('l.category_id')
            ->select('l.category_id as id', DB::raw('COUNT(DISTINCT m.id) as c'))
            ->get();

        $out = array_fill_keys(array_map('intval', $categoryIds), 0);
        foreach ($rows as $r) {
            $out[(int) $r->id] = (int) $r->c;
        }

        // مسار محلي إضافي: أدوية local canonical متوفرة في القسم.
        $localRows = DB::table('category_medicine_links as l')
            ->whereIn('l.category_id', $categoryIds)
            ->whereNotNull('l.medicine_id')
            ->whereExists(function ($sub) {
                $sub->selectRaw(1)
                    ->from('pharmacy_medicines as pm')
                    ->join('pharmacies as p', 'p.id', '=', 'pm.pharmacy_id')
                    ->whereColumn('pm.medicine_id', 'l.medicine_id')
                    ->where('pm.is_available', true)
                    ->where('pm.quantity', '>', 0)
                    ->where('p.is_active', true);
            })
            ->groupBy('l.category_id')
            ->select('l.category_id as id', DB::raw('COUNT(DISTINCT l.medicine_id) as c'))
            ->get();

        foreach ($localRows as $r) {
            $out[(int) $r->id] += (int) $r->c;
        }

        return $out;
    }

    /**
     * عدد الأدوية *المتوفرة* (مميزة) في أقسام فرعية — دفعة واحدة.
     *
     * @param  array<int>  $subcategoryIds
     * @return array<int, int>
     */
    private function availableSubcategoryCounts(array $subcategoryIds): array
    {
        $subcategoryIds = array_values(array_filter(array_map('intval', $subcategoryIds)));
        if ($subcategoryIds === []) {
            return [];
        }

        $rows = DB::table('moh_medicines as m')
            ->join('category_medicine_links as l', function ($join) {
                $join->where(function ($w) {
                    $w->whereColumn('l.moh_product_id', 'm.moh_product_id')
                        ->whereNotNull('l.moh_product_id');
                })->orWhere(function ($w) {
                    $w->whereColumn('l.moh_drug_id', 'm.moh_drug_id')
                        ->whereNotNull('l.moh_drug_id');
                });
            })
            ->whereIn('l.subcategory_id', $subcategoryIds)
            ->whereExists(function ($sub) {
                $sub->selectRaw(1)
                    ->from('pharmacy_medicines as pm')
                    ->join('pharmacies as p', 'p.id', '=', 'pm.pharmacy_id')
                    ->whereColumn('pm.moh_medicine_id', 'm.id')
                    ->where('pm.is_available', true)
                    ->where('pm.quantity', '>', 0)
                    ->where('p.is_active', true);
            })
            ->groupBy('l.subcategory_id')
            ->select('l.subcategory_id as id', DB::raw('COUNT(DISTINCT m.id) as c'))
            ->get();

        $out = array_fill_keys($subcategoryIds, 0);
        foreach ($rows as $r) {
            $out[(int) $r->id] = (int) $r->c;
        }

        // مسار محلي إضافي: أدوية local canonical متوفرة في القسم الفرعي.
        $localRows = DB::table('category_medicine_links as l')
            ->whereIn('l.subcategory_id', $subcategoryIds)
            ->whereNotNull('l.medicine_id')
            ->whereExists(function ($sub) {
                $sub->selectRaw(1)
                    ->from('pharmacy_medicines as pm')
                    ->join('pharmacies as p', 'p.id', '=', 'pm.pharmacy_id')
                    ->whereColumn('pm.medicine_id', 'l.medicine_id')
                    ->where('pm.is_available', true)
                    ->where('pm.quantity', '>', 0)
                    ->where('p.is_active', true);
            })
            ->groupBy('l.subcategory_id')
            ->select('l.subcategory_id as id', DB::raw('COUNT(DISTINCT l.medicine_id) as c'))
            ->get();

        foreach ($localRows as $r) {
            $out[(int) $r->id] = ($out[(int) $r->id] ?? 0) + (int) $r->c;
        }

        return $out;
    }

    /**
     * عدد الأدوية المتوفرة في قسم رئيسي — يُحسب من الروابط (سواء على مستوى
     * القسم أو على مستوى قسم فرعي) بنفس منطق المفاتيح المستقرة + التوفر.
     */
    private function categoryMedicinesCount(int $categoryId): int
    {
        return $this->availableCategoryCounts([$categoryId])[$categoryId] ?? 0;
    }

    /** عدد الأدوية المتوفرة المرتبطة بقسم فرعي بعينه */
    private function subcategoryMedicinesCount(int $subcategoryId): int
    {
        return $this->availableSubcategoryCounts([$subcategoryId])[$subcategoryId] ?? 0;
    }

    /**
     * أشكال الجرعات مع عدد الأدوية لكل شكل — نفس منطق الفلترة حرفياً
     * (tokens موجبة + استثناءات) حتى لا يختلف الرقم المعروض عن نتيجة الفلتر.
     *
     * @return array<int, array{value: string, name_ar: string, medicines_count: int}>
     */
    private function dosageFormFacets(): array
    {
        $out = [];

        foreach (DosageFormNormalizer::facets() as $canonical) {
            $count = (int) DB::table('moh_medicines')
                ->where(function ($builder) use ($canonical) {
                    foreach (DosageFormNormalizer::likeTokens($canonical) as $i => $token) {
                        $i === 0
                            ? $builder->where('dosage_form', 'like', "%{$token}%")
                            : $builder->orWhere('dosage_form', 'like', "%{$token}%");
                    }

                    foreach (DosageFormNormalizer::excludeTokens($canonical) as $excluded) {
                        $builder->where('dosage_form', 'not like', "%{$excluded}%");
                    }
                })
                ->count();

            $out[] = [
                'value' => $canonical,
                'name_ar' => $canonical,
                'medicines_count' => $count,
            ];
        }

        return $out;
    }

    /**
     * {category} يقبل id أو slug — يُحل يدوياً (بدون route model binding)
     * والنشط فقط للعام. SoftDeletes يستبعد المحذوف تلقائياً.
     */
    private function resolveActiveCategory(string $idOrSlug): ?Category
    {
        $query = Category::query()
            ->active()
            ->withCount('categoryMedicineLinks')
            ->with(['subcategories' => fn ($q) => $q->active()->ordered()->withCount('categoryMedicineLinks')]);

        return ctype_digit($idOrSlug)
            ? $query->find((int) $idOrSlug)
            : $query->where('slug', $idOrSlug)->first();
    }

    /**
     * @param  array<int, int>  $catCounts  عدّادات التوفر (availableCategoryCounts)
     * @param  array<int, int>  $subCounts  عدّادات التوفر (availableSubcategoryCounts)
     */
    private function payload(Category $category, array $catCounts = [], array $subCounts = []): array
    {
        return [
            'id' => $category->id,
            'name_ar' => $category->name_ar,
            'name_en' => $category->name_en,
            'slug' => $category->slug,
            'image' => Image::url($category->image),
            'is_active' => (bool) $category->is_active,
            'sort_order' => (int) $category->sort_order,
            // Patient DEFAULT: أدوية متوفرة فقط — لا عدد الروابط الخام.
            'medicines_count' => $catCounts[$category->id] ?? (int) ($category->category_medicine_links_count ?? 0),
            // الأقسام الفرعية النشطة — تُحمَّل مسبقاً (eager) عند توفرها على الموديل
            // حتى لا يتحول العرض إلى N+1 على قائمة الأقسام.
            'subcategories' => $category->relationLoaded('subcategories')
                ? $category->subcategories->map(fn (Subcategory $sub) => $this->subcategoryPayload($sub, $subCounts))->values()->all()
                : [],
        ];
    }

    /**
     * شكل القسم الفرعي في الـAPI.
     *
     * @param  array<int, int>  $subCounts
     */
    private function subcategoryPayload(Subcategory $subcategory, array $subCounts = []): array
    {
        return [
            'id' => $subcategory->id,
            'name_ar' => $subcategory->name_ar,
            'name_en' => $subcategory->name_en,
            'slug' => $subcategory->slug,
            'group_key' => $subcategory->group_key,
            'sort_order' => (int) $subcategory->sort_order,
            'medicines_count' => $subCounts[$subcategory->id] ?? (int) ($subcategory->category_medicine_links_count ?? 0),
        ];
    }

    /**
     * نفس شكل صف الكتالوج في MedicineController@index (mohPayload).
     *
     * medicine_id (nullable) يُضاف من المتصل عبر Medicine::idsByTradeName()
     * = معرّف الدواء في الكتالوج المحلي حين يوجد مطابق بالاسم، حتى يستطيع
     * العميل إكمال مسار التفاصيل/التوفر بدل الاعتماد على moh_medicines.id.
     *
     * image_url يُمرَّر من المتصل (محسوب دفعة واحدة لصفحة النتائج — لا N+1).
     * pharmacies_count كذلك: عدد الصيدليات النشطة المتوفر لديها الدواء فعلياً.
     */
    private function mohPayload(MohMedicine $m, ?string $imageUrl = null, int $pharmaciesCount = 0): array
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
            'image_url' => $imageUrl,
            'pharmacies_count' => $pharmaciesCount,
        ];
    }

    /**
     * صور أدوية صفحة MOH الحالية — دفعة واحدة (استعلام واحد مهما بلغ حجم الصفحة).
     *
     * المصدر المعتمد = جدول medicine_images (نفس مصدر BarcodeLookupController)،
     * يُفضَّل is_verified ثم الأقدم. لا تنزيل ولا base64 ولا روابط وهمية:
     * نُعيد القيمة المخزَّنة كما هي، أو null إن لم توجد صورة.
     *
     * @param  \Illuminate\Support\Collection<int, MohMedicine>  $pageItems
     * @return array<int, string>  مفاتيحه moh_medicines.id → image_url
     */
    private function mohImages($pageItems): array
    {
        $ids = $pageItems->pluck('id')->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $map = [];

        // orderByDesc(is_verified) ثم id: أول صف لكل دواء هو «الأفضل» — نفس
        // دلالة BarcodeLookupController::image() لكن لصفحة كاملة في نداء واحد.
        $rows = DB::table('medicine_images')
            ->whereIn('moh_medicine_id', $ids)
            ->orderByDesc('is_verified')
            ->orderBy('id')
            ->get(['moh_medicine_id', 'image_url']);

        foreach ($rows as $row) {
            $map[(int) $row->moh_medicine_id] ??= $row->image_url;
        }

        return $map;
    }

    /**
     * عدد الصيدليات *المختلفة* التي يتوفر لديها كل دواء MOH في الصفحة —
     * دفعة واحدة (استعلام واحد مهما بلغ حجم الصفحة ⇒ لا N+1).
     *
     * نفس تعريف whereAvailable حرفياً: is_available=true + quantity>0 +
     * pharmacies.is_active=true — لكن بصيغة COUNT(DISTINCT pm.pharmacy_id)
     * مجموعة على moh_medicine_id، فيظهر للدواء المتوفر لدى عدة صيدليات
     * عدد صحيح بلا تكرار أي صيدلية (UNIQUE(pharmacy_id, medicine_id) في
     * الجدول يمنع التكرار أصلاً، و COUNT DISTINCT يحسم الاحتياط).
     *
     * @param  \Illuminate\Support\Collection<int, MohMedicine>  $pageItems
     * @return array<int, int>  مفاتيحه moh_medicines.id → عدد الصيدليات
     */
    private function mohPharmacyCounts($pageItems): array
    {
        $ids = $pageItems->pluck('id')->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return DB::table('pharmacy_medicines as pm')
            ->join('pharmacies as p', 'p.id', '=', 'pm.pharmacy_id')
            ->whereIn('pm.moh_medicine_id', $ids)
            ->where('pm.is_available', true)
            ->where('pm.quantity', '>', 0)
            ->where('p.is_active', true)
            ->groupBy('pm.moh_medicine_id')
            ->pluck(DB::raw('COUNT(DISTINCT pm.pharmacy_id) as cnt'), 'pm.moh_medicine_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * عدد الصيدليات *المختلفة* المتوفر لديها كل دواء محلي في الصفحة —
     * دفعة واحدة بنفس منطق mohPharmacyCounts لكن على pm.medicine_id.
     *
     * @param  array<int, Medicine>|\Illuminate\Support\Collection<int, Medicine>  $rows
     * @return array<int, int>  مفاتيحه medicines.id → عدد الصيدليات
     */
    private function localPharmacyCounts($rows): array
    {
        $ids = collect($rows)->pluck('id')->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return DB::table('pharmacy_medicines as pm')
            ->join('pharmacies as p', 'p.id', '=', 'pm.pharmacy_id')
            ->whereIn('pm.medicine_id', $ids)
            ->where('pm.is_available', true)
            ->where('pm.quantity', '>', 0)
            ->where('p.is_active', true)
            ->groupBy('pm.medicine_id')
            ->pluck(DB::raw('COUNT(DISTINCT pm.pharmacy_id) as cnt'), 'pm.medicine_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * يجلب بيانات التصنيف (source/confidence/needs_review) من category_medicine_links
     * لأدوية الصفحة الحالية. يُرجع map مفاتيحه moh_product_id أو 'd:'.moh_drug_id.
     *
     * @param  int  $categoryId
     * @param  \Illuminate\Support\Collection  $pageItems
     * @return array<string, array{source:?string,confidence:?int,needs_review:?bool}>
     */
    private function fetchLinkMeta(int $categoryId, $pageItems): array
    {
        $productIds = $pageItems->pluck('moh_product_id')->filter()->unique()->values()->all();
        $drugIds = $pageItems->pluck('moh_drug_id')->filter()->unique()->values()->all();

        if (empty($productIds) && empty($drugIds)) {
            return [];
        }

        $query = CategoryMedicineLink::query()->where('category_id', $categoryId);
        $query->where(function ($q) use ($productIds, $drugIds) {
            if (! empty($productIds)) {
                $q->orWhereIn('moh_product_id', $productIds);
            }
            if (! empty($drugIds)) {
                $q->orWhereIn('moh_drug_id', $drugIds);
            }
        });

        $meta = [];
        foreach ($query->get() as $link) {
            $entry = [
                'source' => $link->source,
                'confidence' => (int) $link->confidence,
                'needs_review' => (bool) $link->needs_review,
            ];
            if ($link->moh_product_id !== null) {
                $meta[$link->moh_product_id] = $entry;
            }
            if ($link->moh_drug_id !== null) {
                $meta['d:'.$link->moh_drug_id] = $entry;
            }
        }

        return $meta;
    }
}
