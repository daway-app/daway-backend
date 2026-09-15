<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\SearchLog;
use App\Models\Subcategory;
use App\Services\Ai\MedicineResolver;
use App\Support\CategoryCatalogCache;
use App\Support\DosageFormNormalizer;
use App\Support\Haversine;
use App\Support\Image;
use App\Support\PharmacyAvailability;
use App\Support\StockStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MedicineController extends Controller
{
    /**
     * H-9: تغطية الصيدليات لأكثر من دواء في استعلام واحد.
     * يعيد خريطة [medicine_id => ['count' => int, 'nearest' => ?array]].
     * مع الـ geo: prefilter بصندوق إحاطة بالـ SQL ثم فلترة Haversine بالـ PHP على المجموعة الصغيرة.
     */
    private function pharmacyCoverageForMedicines(
        array $medicineIds,
        ?float $lat,
        ?float $lng,
        int $radiusKm,
        bool $hasGeo
    ): array {
        if ($medicineIds === []) {
            return [];
        }

        $query = DB::table('pharmacy_medicines as pm')
            ->join('pharmacies as p', 'p.id', '=', 'pm.pharmacy_id')
            ->whereIn('pm.medicine_id', $medicineIds)
            ->where('pm.is_available', true)
            ->where('pm.quantity', '>', 0)
            ->where('p.is_active', true);

        if ($hasGeo) {
            $query->whereNotNull('p.latitude')
                ->whereNotNull('p.longitude');

            // صندوق إحاطة خشن (~1 درجة ≈ 111كم) يقلّص الصفوف قبل الحساب الدقيق
            $query->whereBetween('p.latitude', [$lat - 1, $lat + 1])
                ->whereBetween('p.longitude', [$lng - 1, $lng + 1]);
        }

        $rows = $query->select(
            'pm.medicine_id',
            'p.id',
            'p.pharmacy_name',
            'p.latitude as lat',
            'p.longitude as lng'
        )->get();

        $coverage = [];

        foreach ($rows as $r) {
            $mid = (int) $r->medicine_id;

            if (! isset($coverage[$mid])) {
                $coverage[$mid] = ['count' => 0, 'nearest' => null];
            }

            if ($hasGeo) {
                if ($r->lat === null || $r->lng === null) {
                    continue;
                }

                $d = Haversine::kmBetween($lat, $lng, (float) $r->lat, (float) $r->lng);
                if ($d > $radiusKm) {
                    continue;
                }

                $coverage[$mid]['count']++;

                $currentNearest = $coverage[$mid]['nearest'];
                if ($currentNearest === null || $d < $currentNearest['distance_km']) {
                    $coverage[$mid]['nearest'] = [
                        'id' => (int) $r->id,
                        'name' => $r->pharmacy_name,
                        'distance_km' => round($d, 2),
                        'availability_status' => 'available',
                    ];
                }
            } else {
                $coverage[$mid]['count']++;
            }
        }

        return $coverage;
    }

    /**
     * H9: أقصى طول لقيمة البحث داخل مفتاح الـ cache — يمنع تضخم المفاتيح
     * (cache key explosion) عند إرسال استعلامات ضخمة أو تكرارية.
     */
    private const MAX_CACHE_QUERY_LENGTH = 100;

    public function __construct(private readonly MedicineResolver $resolver)
    {
    }

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

        // فلاتر اختيارية (additive) على مسار كتالوج وزارة الصحة فقط —
        // مسارات chatbot/OCR والبحث لا تتأثر بها عمداً. غيابها يُبقي
        // الاستعلام ومفتاح الـcache كما كانا تماماً.
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            // category_id غير صالح → يُتجاهل بصمت (مثل dosage_form) بدل 422 —
            // عميل بدون Accept: application/json كان يأخذ redirect بدل JSON.
            'category_id' => 'nullable|integer|min:1',
            'subcategory_id' => 'nullable|integer|min:1',
            'subcategory' => 'nullable|string|max:180',
            'dosage_form' => 'nullable|string|max:50',
        ]);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = (int) $request->get('page', 1);

        // category_id غير موجود فعلاً في جدول الأقسام → يُعامل كغير مُرسل
        $categoryId = null;
        if (! empty($validated['category_id'])) {
            $categoryId = Category::find((int) $validated['category_id'])?->id;
        }
        // قيمة dosage_form غير معروفة → null → تُتجاهل الفلترة بصمت
        $dosageForm = isset($validated['dosage_form']) ? DosageFormNormalizer::forInput($validated['dosage_form']) : null;

        // القسم الفرعي يُقبل بمعرّف رقمي أو slug. النشط فقط (مثل الأقسام
        // الرئيسية في مسارات الأقسام)، ويُقيَّد بقسمه الرئيسي عند تمريره معاً
        // حتى لا يختلط فلتران متناقضان.
        $subcategoryId = null;
        $subcategorySelector = $validated['subcategory_id'] ?? $validated['subcategory'] ?? null;
        if ($subcategorySelector !== null && $subcategorySelector !== '') {
            $subcategoryQuery = Subcategory::query()->active();
            if ($categoryId !== null) {
                $subcategoryQuery->where('category_id', $categoryId);
            }
            $subcategory = is_numeric($subcategorySelector)
                ? $subcategoryQuery->find((int) $subcategorySelector)
                : $subcategoryQuery->where('slug', (string) $subcategorySelector)->first();
            $subcategoryId = $subcategory?->id;
        }

        // null-safe: كل ارتباط يُقيَّد بـ whereNotNull داخلي، والمفاتيح مستقرة فقط
        // (moh_product_id / moh_drug_id) — لا يُشار إلى moh_medicines.id مطلقاً.
        if ($categoryId !== null) {
            $query->where(function ($outer) use ($categoryId) {
                $outer->whereExists(function ($sub) use ($categoryId) {
                    $sub->selectRaw(1)
                        ->from('category_medicine_links')
                        ->whereColumn('category_medicine_links.moh_product_id', 'moh_medicines.moh_product_id')
                        ->where('category_medicine_links.category_id', $categoryId)
                        ->whereNotNull('category_medicine_links.moh_product_id');
                })->orWhereExists(function ($sub) use ($categoryId) {
                    $sub->selectRaw(1)
                        ->from('category_medicine_links')
                        ->whereColumn('category_medicine_links.moh_drug_id', 'moh_medicines.moh_drug_id')
                        ->where('category_medicine_links.category_id', $categoryId)
                        ->whereNotNull('category_medicine_links.moh_drug_id');
                });
            });
        }

        // فلتر القسم الفرعي: يُقيَّد صراحةً بـsubcategory_id (لا يكفي category_id
        // لأن الرابط الفرعي يحمل نفس المفاتيح المستقرة للرابط الرئيسي).
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

        // العمود يخزّن نصاً إنجليزياً حراً → OR'd LIKE على tokens القياسي
        // (مع استثناءات NOT LIKE، مثال: جل يستثني gelatin/capsule)
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

        // مفتاح الـcache: مقاطع تُلحق فقط عند وجود الفلتر — بدون فلاتر يبقى
        // المفتاح مطابقاً بايت ببايت للسلوك السابق. للـdosage_form يُستخدم
        // رقم القياسي في facets() بدل النص العربي (توافق مع مخازن مفاتيح ASCII).
        $filterKeySuffix = '';
        if ($categoryId !== null) {
            $categoryVersion = CategoryCatalogCache::version();
            $filterKeySuffix .= "|cv{$categoryVersion}|cat{$categoryId}";
        }
        if ($subcategoryId !== null) {
            $filterKeySuffix .= '|sub'.$subcategoryId;
        }
        if ($dosageForm !== null) {
            $filterKeySuffix .= '|df'.array_search($dosageForm, DosageFormNormalizer::facets(), true);
        }

        $items = Cache::remember($this->cacheKey("api_meds_idx|v{$catVer}", $q).$filterKeySuffix."|{$page}|{$perPage}", 900, function () use ($query, $perPage) {
            return $query->orderBy('trade_name')->paginate($perPage);
        });

        // medicine_id يُحسب خارج الكاش عمداً: الصيدليات تُنشئ أدوية محلية في أي
        // وقت بدون أن يمسّ ذلك نسخة كاش الكتالوج.
        $pageItems = collect($items->items());
        $localMedicineIds = Medicine::idsByTradeName($pageItems->pluck('trade_name')->all());

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الأدوية بنجاح',
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
     * بحث مباشر عبر MedicineResolver بدون انتظار خدمة AI الخارجية.
     * يحوّل اسم الدواء (عربي/إنجليزي) إلى مرشحين في الكتالوج + صيدليات قريبة.
     * مفيد للبحث الفوري (autocomplete) وللتطبيقات التي لا تمر بـ /api/chat.
     */
    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $name = trim($data['name']);
        if (mb_strlen($name) < 2) {
            return response()->json([
                'success' => true,
                'message' => 'اسم قصير جداً',
                'data' => [
                    'name' => $name,
                    'moh_catalog' => [],
                    'local_catalog' => [],
                    'pharmacies' => [],
                    'alternatives' => [],
                    'requires_location' => false,
                ],
            ]);
        }

        $lat = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $lng = isset($data['longitude']) ? (float) $data['longitude'] : null;
        $radiusKm = (int) ($data['radius_km'] ?? 15);

        $payload = [
            'name' => $name,
            'moh_catalog' => [],
            'local_catalog' => [],
            'pharmacies' => [],
            'alternatives' => [],
            'requires_location' => $lat === null,
        ];

        try {
            $candidates = $this->resolver->resolveCandidates($name);
            $payload['moh_catalog'] = $candidates['moh']->values();
            $payload['local_catalog'] = $candidates['local']->values();

            $bestLocalId = $candidates['local']->first()->id ?? null;
            if ($bestLocalId) {
                $payload['alternatives'] = $this->resolver->alternatives($bestLocalId);
            }

            if ($lat !== null && $lng !== null) {
                $payload['pharmacies'] = $this->resolver->pharmaciesFor(
                    drugName: $name,
                    latitude: $lat,
                    longitude: $lng,
                    radiusKm: $radiusKm,
                    // مفاتيح إنجليزية من الـ mapping — الاسم العربي الخام لا يطابق الكتالوج
                    names: $candidates['search_keys'] ?? null,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('resolve endpoint failed', ['error' => $e->getMessage(), 'name' => $name]);
        }

        \App\Models\SearchLog::track($name, 'resolve');

        return response()->json([
            'success' => true,
            'message' => 'تم حل اسم الدواء بنجاح.',
            'data' => $payload,
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
                'data' => [
                    'medicines' => [],
                    'moh_catalog' => [],
                ],
            ]);
        }

        SearchLog::track($q, 'api');

        $validated = $request->validate([
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_km' => 'nullable|integer|min:1|max:50',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $lat = isset($validated['latitude']) ? (float) $validated['latitude'] : null;
        $lng = isset($validated['longitude']) ? (float) $validated['longitude'] : null;
        $radiusKm = (int) ($validated['radius_km'] ?? 15);
        $hasGeo = $lat !== null && $lng !== null;

        $medVer = $this->medicinesVersion();
        $catVer = $this->catalogVersion();

        // H-9: دقة 3 منازل (~110م) بدل 4 — مفتاح كاش يصيب فعلاً بدل مفتاح جديد كل خطوة
        $geoTag = $hasGeo
            ? sprintf('|geo|%.3f|%.3f|%d', $lat, $lng, $radiusKm)
            : '|geo|none';

        $result = Cache::remember($this->cacheKey("api_meds_search|v{$medVer}|v{$catVer}{$geoTag}", $q), 900, function () use ($q, $lat, $lng, $radiusKm, $hasGeo) {
            $medicineQuery = Medicine::query();
            $this->fulltextOrLike($medicineQuery, ['trade_name', 'active_ingredient'], $q);
            $medicines = $medicineQuery->limit(10)->get();

            $mohQuery = MohMedicine::query();
            $this->fulltextOrLike($mohQuery, ['trade_name', 'generic_name'], $q);
            $mohMedicines = $mohQuery->limit(20)->get();

            // H-9: استعلام مجمّع واحد لكل الأدوية (بدل 2 query لكل دواء = 20 query)
            $coverage = $this->pharmacyCoverageForMedicines(
                $medicines->pluck('id')->all(),
                $lat,
                $lng,
                $radiusKm,
                $hasGeo
            );

            $medicinesPayload = $medicines->map(function (Medicine $m) use ($coverage) {
                $payload = $this->medicinePayload($m);
                $payload['available_pharmacies_count'] = $coverage[$m->id]['count'] ?? 0;
                $payload['nearest_pharmacy'] = $coverage[$m->id]['nearest'] ?? null;

                return $payload;
            })->all();

            return [
                'medicines' => $medicinesPayload,
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
    public function show(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_km' => 'nullable|integer|min:1|max:50',
        ]);

        $medicine = Medicine::with('pharmacyMedicines.pharmacy')->findOrFail($id);

        $lat = isset($validated['latitude']) ? (float) $validated['latitude'] : null;
        $lng = isset($validated['longitude']) ? (float) $validated['longitude'] : null;
        $radiusKm = (int) ($validated['radius_km'] ?? 15);
        $hasGeo = $lat !== null && $lng !== null;

        $payload = $this->medicinePayload($medicine, true);

        if ($hasGeo) {
            $payload['nearest_pharmacy'] = $this->nearestPharmacyFor($medicine->id, $lat, $lng, $radiusKm);
        } else {
            $payload['nearest_pharmacy'] = null;
        }

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الدواء بنجاح',
            'data' => $payload,
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
     * بدائل دواء بنفس المادة الفعّالة.
     * الـSRS: medicine, image, price, pharmacy, distance, availability.
     * الـhelper `Medicine::alternativesByActiveIngredient` يستثني الـid المعطى ويحدّ بـ 10.
     */
    public function alternatives(Request $request, string $id): JsonResponse
    {
        $medicine = Medicine::findOrFail($id);

        if (! $medicine->active_ingredient) {
            return response()->json([
                'success' => true,
                'message' => 'لا توجد بدائل بنفس المادة الفعالة',
                'data' => [],
            ]);
        }

        $validated = $request->validate([
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_km' => 'nullable|integer|min:1|max:50',
        ]);

        $lat = isset($validated['latitude']) ? (float) $validated['latitude'] : null;
        $lng = isset($validated['longitude']) ? (float) $validated['longitude'] : null;
        $radius = $validated['radius_km'] ?? null;

        $alternatives = Medicine::alternativesByActiveIngredient(
            $medicine->active_ingredient,
            $medicine->id,
        );

        $payload = $alternatives->map(function (Medicine $alt) use ($lat, $lng, $radius) {
            $nearestPharmacy = $lat !== null && $lng !== null
                ? $this->nearestPharmacyFor($alt->id, $lat, $lng, $radius ?? 15)
                : null;

            $row = $this->medicinePayload($alt);
            $row['nearest_pharmacy'] = $nearestPharmacy;

            return $row;
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب البدائل بنجاح',
            'data' => $payload,
        ]);
    }

    /**
     * الصيدليات التي يتوفر بها دواء معين.
     */
    public function pharmacies(Request $request, string $id): JsonResponse
    {
        $medicine = Medicine::findOrFail($id);

        $validated = $request->validate([
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_km' => 'nullable|integer|min:1|max:50',
        ]);

        $lat = isset($validated['latitude']) ? (float) $validated['latitude'] : null;
        $lng = isset($validated['longitude']) ? (float) $validated['longitude'] : null;
        $radiusKm = (int) ($validated['radius_km'] ?? 15);
        $hasGeo = $lat !== null && $lng !== null;

        $query = PharmacyMedicine::query()
            ->where('medicine_id', $medicine->id)
            ->where('is_available', true)
            ->where('quantity', '>', 0)
            ->with(['pharmacy' => function ($q) {
                $q->with('hours');
            }]);

        $available = $query->get()->map(function (PharmacyMedicine $pm) use ($lat, $lng, $radiusKm, $hasGeo) {
            $row = $this->pharmacyRowPayload($pm, $lat, $lng, $radiusKm);

            if ($hasGeo && $row['distance_km'] !== null && $row['distance_km'] > $radiusKm) {
                return null;
            }

            return $row;
        })->filter()->values();

        if ($hasGeo) {
            $available = $available->sortBy('distance_km')->values();
        }

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
     * H9: يبني مفتاح cache آمن من مدخلات المستخدم:
     *  - يحذف المسافات الزائدة ويوحّد الـ whitespace.
     *  - يقصّ الطول إلى MAX_CACHE_QUERY_LENGTH.
     *  - يستبدل فاصل المفتاح (|) لمنع تصادم/تلاعب بمفاتيح الـ cache.
     */
    private function cacheKey(string $prefix, string $query): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($query)) ?? '';
        $normalized = mb_substr($normalized, 0, self::MAX_CACHE_QUERY_LENGTH);
        $normalized = str_replace('|', ' ', $normalized);

        return $prefix.'|'.$normalized;
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
            'image_url' => Image::url($m->image),
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

    /**
     * يبني صفّ صيدلية واحد متوافقاً مع عقد SRS:
     * pharmacy_id, name, price, quantity, availability_status,
     * distance_km, phone, latitude, longitude, working_hours, rating.
     */
    private function pharmacyRowPayload(
        PharmacyMedicine $pm,
        ?float $lat,
        ?float $lng,
        int $radiusKm
    ): array {
        $pharmacy = $pm->pharmacy;
        $hasGeo = $lat !== null && $lng !== null
            && $pharmacy->latitude !== null
            && $pharmacy->longitude !== null;

        $distance = null;
        if ($hasGeo) {
            $distance = round(
                Haversine::kmBetween($lat, $lng, (float) $pharmacy->latitude, (float) $pharmacy->longitude),
                2
            );
        }

        $isActive = (bool) ($pharmacy->is_active ?? true);

        return [
            'pharmacy_id' => $pharmacy->id,
            'name' => $pharmacy->pharmacy_name,
            'price' => (float) $pm->price,
            'quantity' => (int) $pm->quantity,
            'availability_status' => $this->availabilityStatus($pm, $isActive),
            'distance_km' => $distance,
            'phone' => $pharmacy->phone_number,
            'latitude' => $pharmacy->latitude !== null ? (float) $pharmacy->latitude : null,
            'longitude' => $pharmacy->longitude !== null ? (float) $pharmacy->longitude : null,
            'working_hours' => $this->workingHoursPayload($pharmacy),
            'rating' => $pharmacy->avg_rating !== null ? round((float) $pharmacy->avg_rating, 1) : null,
        ];
    }

    /**
     * اشتقاق availability_status وفق عتبة المخزون المنخفض الثابتة.
     * القيم المسموحة: available / low_stock / out_of_stock.
     *
     * المنطق نفسه انتقل إلى App\Support\StockStatus ليتشاركه مساعد المريض
     * بدل تكراره — السلوك لم يتغيّر بايت ببايت.
     */
    private function availabilityStatus(PharmacyMedicine $pm, bool $pharmacyActive): string
    {
        return StockStatus::of((bool) $pm->is_available, (int) $pm->quantity, $pharmacyActive);
    }

    /**
     * صياغة ساعات العمل كقائمة موحّدة وفق عقد SRS.
     */
    private function workingHoursPayload(Pharmacy $pharmacy): array
    {
        return $pharmacy->hours
            ->map(fn ($h) => [
                'day_of_week' => $h->day_of_week,
                'open_time' => $h->open_time,
                'close_time' => $h->close_time,
                'is_closed' => (bool) $h->is_closed,
            ])
            ->values()
            ->all();
    }

    /**
     * عدد الصيدليات المميّزة التي يتوفر لديها هذا الدواء متاحاً وفي المخزون.
     * إذا تُقدّمت إحداثيات + نصف قطر، تُقيَّد النتيجة بنطاق radius_km.
     */
    private function availablePharmaciesCount(
        int $medicineId,
        ?float $lat,
        ?float $lng,
        int $radiusKm,
        bool $hasGeo
    ): int {
        $base = DB::table('pharmacy_medicines as pm')
            ->join('pharmacies as p', 'p.id', '=', 'pm.pharmacy_id')
            ->where('pm.medicine_id', $medicineId)
            ->where('pm.is_available', true)
            ->where('pm.quantity', '>', 0)
            ->where('p.is_active', true);

        if ($hasGeo) {
            $rows = $base
                ->select('p.latitude as lat', 'p.longitude as lng')
                ->get();

            $count = 0;
            foreach ($rows as $r) {
                if ($r->lat === null || $r->lng === null) {
                    continue;
                }
                $d = Haversine::kmBetween($lat, $lng, (float) $r->lat, (float) $r->lng);
                if ($d <= $radiusKm) {
                    $count++;
                }
            }

            return $count;
        }

        return (int) $base->distinct()->count('p.id');
    }

    /**
     * أقرب صيدلية فيها الدواء متاح وفي المخزون، مع مراعاة نصف القطر.
     * يُعيد null إذا لم تتوفر إحداثيات أو لا توجد نتيجة ضمن النطاق.
     */
    private function nearestPharmacyFor(
        int $medicineId,
        float $lat,
        float $lng,
        int $radiusKm
    ): ?array {
        // Bounding Box خشن (~1 درجة ≈ 111كم) يقلّص الصفوف عبر فهرس الإحداثيات
        // قبل Haversine — بدل تحميل كل صيدليات الدواء ثم الحساب في PHP.
        $deltaLat = $radiusKm / 111.045;
        $cosLat = cos(deg2rad($lat));
        $deltaLng = abs($cosLat) < 0.000001
            ? 180.0
            : min(180.0, $radiusKm / (111.045 * abs($cosLat)));

        $rows = DB::table('pharmacy_medicines as pm')
            ->join('pharmacies as p', 'p.id', '=', 'pm.pharmacy_id')
            ->where('pm.medicine_id', $medicineId)
            ->where('pm.is_available', true)
            ->where('pm.quantity', '>', 0)
            ->where('p.is_active', true)
            ->whereNotNull('p.latitude')
            ->whereNotNull('p.longitude')
            ->whereBetween('p.latitude', [$lat - $deltaLat, $lat + $deltaLat])
            ->whereBetween('p.longitude', [$lng - $deltaLng, $lng + $deltaLng])
            ->select('p.id', 'p.pharmacy_name', 'p.latitude as lat', 'p.longitude as lng')
            ->get();

        $best = null;
        $bestDistance = PHP_FLOAT_MAX;

        foreach ($rows as $r) {
            $d = Haversine::kmBetween($lat, $lng, (float) $r->lat, (float) $r->lng);
            if ($d <= $radiusKm && $d < $bestDistance) {
                $bestDistance = $d;
                $best = $r;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'id' => (int) $best->id,
            'name' => $best->pharmacy_name,
            'distance_km' => round($bestDistance, 2),
            'availability_status' => 'available',
        ];
    }
}
