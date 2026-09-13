<?php

namespace App\Http\Controllers\web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Support\CategoryCatalogCache;
use App\Support\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * قائمة الأقسام — ترتيب ثابت (sort_order, name_ar) مع بحث وترقيم.
     */
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $categories = Category::query()
            ->withCount('categoryMedicineLinks')
            ->withCount(['categoryMedicineLinks as needs_review_count' => function ($query) {
                $query->where('needs_review', true);
            }])
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('name_ar', 'like', "%{$q}%")
                ->orWhere('name_en', 'like', "%{$q}%")))
            ->ordered()
            ->paginate(7)
            ->withQueryString();

        $stats = [
            'total' => Category::count(),
            'active' => Category::where('is_active', true)->count(),
            'links' => CategoryMedicineLink::count(),
            'needs_review' => CategoryMedicineLink::where('needs_review', true)->count(),
        ];

        return view('categories.index', compact('categories', 'stats', 'q'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('categories.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $this->validateCategory($request);

        $data['slug'] = $this->uniqueSlug(
            $request->filled('slug')
                ? Str::slug($request->input('slug'))
                : Str::slug($request->input('name_en'))
        );

        unset($data['image']);
        $category = Category::create($data);

        if ($request->hasFile('image')) {
            $image = Cloudinary::upload($request->file('image'), 'categories');
            if ($image !== null) {
                $category->image = $image;
                $category->save();
            }
        }

        CategoryCatalogCache::bump();

        return Redirect::route('categories.index')->with('success', 'تم إنشاء القسم بنجاح!');
    }

    /**
     * Display the specified resource — تفاصيل القسم وروابط الأدوية داخله.
     * الروابط هي الصفوف ( paginate على category_medicine_links )،
     * وتُستهدف الأدوية بمفاتيحها المستقرة فقط (moh_product_id / moh_drug_id / medicine_id).
     */
    public function show(Request $request, Category $category)
    {
        $q = trim((string) $request->query('q', ''));
        $review = $request->query('review') === '1';
        $source = (string) $request->query('source', '');

        $links = CategoryMedicineLink::query()
            ->where('category_id', $category->id)
            ->when($review, fn ($query) => $query->where('needs_review', true))
            ->when($source !== '', fn ($query) => $query->where('source', $source))
            ->orderByDesc('needs_review')
            ->orderBy('id')
            ->paginate(7)
            ->withQueryString();

        // resolve كل صفوف الصفحة الحالية باستعلام واحد لكل نوع
        $pageLinks = $links->getCollection();
        $productIds = $pageLinks->pluck('moh_product_id')->filter()->unique()->values();
        $drugIds = $pageLinks->pluck('moh_drug_id')->filter()->unique()->values();
        $medicineIds = $pageLinks->pluck('medicine_id')->filter()->unique()->values();

        $mohByProduct = $productIds->isNotEmpty()
            ? MohMedicine::whereIn('moh_product_id', $productIds)->get()->keyBy('moh_product_id')
            : collect();
        $mohByDrug = $drugIds->isNotEmpty()
            ? MohMedicine::whereIn('moh_drug_id', $drugIds)->get()->keyBy('moh_drug_id')
            : collect();
        $localMedicines = $medicineIds->isNotEmpty()
            ? Medicine::whereIn('id', $medicineIds)->get()->keyBy('id')
            : collect();

        // بحث الربط: كتالوج الوزارة + الأدوية المحلية (بلا dropdown بـ 17 ألف صف)
        $searchResults = collect();
        if (mb_strlen($q) >= 2) {
            $linkedProductIds = CategoryMedicineLink::where('category_id', $category->id)
                ->whereNotNull('moh_product_id')->pluck('moh_product_id')->all();
            $linkedDrugIds = CategoryMedicineLink::where('category_id', $category->id)
                ->whereNotNull('moh_drug_id')->pluck('moh_drug_id')->all();
            $linkedMedicineIds = CategoryMedicineLink::where('category_id', $category->id)
                ->whereNotNull('medicine_id')->pluck('medicine_id')->all();

            $mohResults = MohMedicine::query()
                ->where(fn ($w) => $w
                    ->where('trade_name', 'like', "%{$q}%")
                    ->orWhere('generic_name', 'like', "%{$q}%"))
                ->orderBy('trade_name')
                ->limit(10)
                ->get();

            foreach ($mohResults as $moh) {
                $searchResults->push([
                    'type' => 'moh',
                    'type_label' => __('categories.type_moh'),
                    'name' => $moh->trade_name,
                    'sub' => $moh->generic_name ?: '—',
                    'class' => $moh->product_class ?: '—',
                    'moh_product_id' => $moh->moh_product_id,
                    'moh_drug_id' => $moh->moh_drug_id,
                    'medicine_id' => null,
                    'linked' => in_array($moh->moh_product_id, $linkedProductIds, true)
                        || ($moh->moh_drug_id !== null && in_array($moh->moh_drug_id, $linkedDrugIds, true)),
                ]);
            }

            $medicineResults = Medicine::query()
                ->where('trade_name', 'like', "%{$q}%")
                ->orderBy('trade_name')
                ->limit(10)
                ->get();

            foreach ($medicineResults as $medicine) {
                $searchResults->push([
                    'type' => 'medicine',
                    'type_label' => __('categories.type_local'),
                    'name' => $medicine->trade_name,
                    'sub' => $medicine->active_ingredient ?: '—',
                    'class' => '—',
                    'moh_product_id' => null,
                    'moh_drug_id' => null,
                    'medicine_id' => $medicine->id,
                    'linked' => in_array($medicine->id, $linkedMedicineIds, true),
                ]);
            }
        }

        $stats = [
            'links' => CategoryMedicineLink::where('category_id', $category->id)->count(),
            'needs_review' => CategoryMedicineLink::where('category_id', $category->id)
                ->where('needs_review', true)->count(),
        ];

        return view('categories.show', compact(
            'category', 'links', 'mohByProduct', 'mohByDrug', 'localMedicines',
            'searchResults', 'q', 'review', 'source', 'stats'
        ));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Category $category)
    {
        return view('categories.edit', compact('category'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Category $category)
    {
        $data = $this->validateCategory($request);

        // الـ slug: يُستبدل فقط إذا أُدخل صراحة، أو يُعاد توليده عند تغيّر الاسم الإنجليزي
        if ($request->filled('slug')) {
            $data['slug'] = $this->uniqueSlug(Str::slug($request->input('slug')), $category->id);
        } elseif ($request->input('name_en') !== $category->name_en) {
            $data['slug'] = $this->uniqueSlug(Str::slug($request->input('name_en')), $category->id);
        } else {
            unset($data['slug']);
        }

        unset($data['image']);
        $category->update($data);

        if ($request->hasFile('image')) {
            $oldImage = $category->image;
            $newImage = Cloudinary::upload($request->file('image'), 'categories');
            if ($newImage !== null) {
                $category->image = $newImage;
                $category->save();
                Cloudinary::deleteLocal($oldImage);
            }
        }

        CategoryCatalogCache::bump();

        return Redirect::route('categories.index')->with('success', 'تم تحديث القسم بنجاح!');
    }

    /**
     * Remove the specified resource — حذف ناعم فقط؛ صفوف الروابط تبقى
     * (FK cascade يمسّها عند forceDelete فقط).
     */
    public function destroy(Category $category)
    {
        $category->delete();
        CategoryCatalogCache::bump();

        return Redirect::route('categories.index')->with('success', 'تم حذف القسم بنجاح!');
    }

    /**
     * تفعيل/تعطيل القسم.
     */
    public function toggleStatus(Category $category)
    {
        $category->is_active = ! $category->is_active;
        $category->save();
        CategoryCatalogCache::bump();

        return Redirect::back()->with('success', 'تم تحديث حالة القسم بنجاح!');
    }

    /**
     * ربط دواء بالقسم (من نتائج البحث في صفحة القسم).
     * كتالوج الوزارة: بمفاتيح moh_product_id / moh_drug_id فقط — أبداً moh_medicines.id.
     */
    public function attachMedicine(Request $request, Category $category)
    {
        $data = $request->validate([
            'type' => 'required|in:moh,medicine',
            'moh_product_id' => 'nullable|integer|min:1',
            'moh_drug_id' => 'nullable|integer|min:1',
            'medicine_id' => 'nullable|integer|exists:medicines,id',
        ]);

        if ($data['type'] === 'medicine') {
            if (empty($data['medicine_id'])) {
                return Redirect::back()->with('error', 'تعذر تحديد الدواء المطلوب ربطه.');
            }

            CategoryMedicineLink::firstOrCreate(
                ['category_id' => $category->id, 'medicine_id' => $data['medicine_id']],
                [
                    'source' => CategoryMedicineLink::SOURCE_ADMIN,
                    'confidence' => 100,
                    'needs_review' => false,
                ]
            );

            CategoryCatalogCache::bump();

            return Redirect::back()->with('success', 'تم ربط الدواء بالقسم بنجاح!');
        }

        $productId = $data['moh_product_id'] ?? null;
        $drugId = $data['moh_drug_id'] ?? null;

        if (! $productId && ! $drugId) {
            return Redirect::back()->with('error', 'لا يمكن ربط هذا الدواء: معرّفات وزارة الصحة غير متوفرة.');
        }

        // لا يُقبل ربط إلا بمفاتيح موجودة فعلاً في الكتالوج وتخصّ نفس الصف
        // (نموذج البحث يرسلها صحيحة، لكن الطلب نفسه غير موثوق).
        $catalogRowExists = MohMedicine::query()
            ->when($productId, fn ($query) => $query->where('moh_product_id', $productId))
            ->when($drugId, fn ($query) => $query->where('moh_drug_id', $drugId))
            ->exists();

        if (! $catalogRowExists) {
            return Redirect::back()->with('error', 'تعذر العثور على الدواء في كتالوج وزارة الصحة.');
        }

        // فحص تكرار على مستوى الدواء الواحد (بأي من مفتاحيه المستقرين)
        $existing = CategoryMedicineLink::query()
            ->where('category_id', $category->id)
            ->when($productId && $drugId, fn ($query) => $query->where(fn ($w) => $w
                ->where('moh_product_id', $productId)
                ->orWhere('moh_drug_id', $drugId)))
            ->when($productId && ! $drugId, fn ($query) => $query->where('moh_product_id', $productId))
            ->when(! $productId && $drugId, fn ($query) => $query->where('moh_drug_id', $drugId))
            ->first();

        if ($existing) {
            return Redirect::back()->with('error', 'هذا الدواء مرتبط مسبقاً بهذا القسم.');
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

        return Redirect::back()->with('success', 'تم ربط الدواء بالقسم بنجاح!');
    }

    /**
     * إزالة رابط دواء من القسم.
     */
    public function detachMedicine(Category $category, CategoryMedicineLink $link)
    {
        abort_unless($link->category_id === $category->id, 404);

        $link->delete();
        CategoryCatalogCache::bump();

        return Redirect::back()->with('success', 'تم إزالة الدواء من القسم بنجاح!');
    }

    /**
     * اعتماد رابط بانتظار المراجعة.
     */
    public function approveReview(Category $category, CategoryMedicineLink $link)
    {
        abort_unless($link->category_id === $category->id, 404);

        $link->update([
            'needs_review' => false,
            'source' => CategoryMedicineLink::SOURCE_ADMIN,
            'confidence' => 100,
        ]);

        CategoryCatalogCache::bump();

        return Redirect::back()->with('success', 'تم اعتماد الربط بنجاح!');
    }

    /**
     * قواعد التحقق المشتركة بين store و update.
     */
    private function validateCategory(Request $request): array
    {
        $validated = $request->validate([
            'name_ar' => 'required|string|max:150',
            'name_en' => 'required|string|max:150',
            'slug' => 'nullable|string|max:180',
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $validated['is_active'] = $request->boolean('is_active');
        $validated['sort_order'] = $request->filled('sort_order') ? (int) $request->input('sort_order') : 0;

        return $validated;
    }

    /**
     * توليد slug فريد (يتضمّن المحذوف ناعماً لأن قيد unique على مستوى الجدول).
     */
    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $base = $base !== '' ? $base : 'category';
        $base = mb_substr($base, 0, 180);
        $slug = $base;
        $suffix = 2;

        while (Category::withTrashed()
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            // القصّ يحفظ الطول داخل حدّ العمود (180) مع بقاء اللاحقة مميّزة
            $suffixPart = '-'.$suffix;
            $slug = mb_substr($base, 0, 180 - mb_strlen($suffixPart)).$suffixPart;
            $suffix++;
        }

        return $slug;
    }
}
