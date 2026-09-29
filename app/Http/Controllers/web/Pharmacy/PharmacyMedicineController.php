<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine; // Assuming this model exists for pivot table
use App\Models\Subcategory;
use App\Models\SearchLog;
use App\Services\MedicineCatalogService;
use App\Support\Cloudinary;
use App\Support\LowStockNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class PharmacyMedicineController extends Controller
{
    public function __construct(private readonly MedicineCatalogService $catalog)
    {
        $this->middleware('auth'); // Ensure user is authenticated
        // Add middleware to check if the user is a pharmacy
        $this->middleware(function ($request, $next) {
            if (Auth::check() && Auth::user()->role === 'pharmacy') {
                return $next($request);
            }

            return redirect('/')->with('error', __('pharmacy.access_denied'));
        })->except(['index', 'show']); // Apply to all methods except index and show for now
    }

    /**
     * Display a listing of the pharmacy's medicines.
     *
     * @return Response
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();
        $threshold = PharmacyMedicine::LOW_STOCK_THRESHOLD;

        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');
        // القيم المعتمدة مطابقة لتبويبات الواجهة: all/out/low/ok
        // ('available' تُقبل كمرادف لـ 'ok' فقط للتوافق)
        if ($status === 'available') {
            $status = 'ok';
        }
        if (! in_array($status, ['all', 'ok', 'low', 'out'], true)) {
            $status = 'all';
        }

        $query = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->with('medicine');

        if ($q !== '') {
            $query->whereHas('medicine', function ($mq) use ($q) {
                $mq->where('trade_name', 'like', "%{$q}%")
                    ->orWhere('active_ingredient', 'like', "%{$q}%")
                    ->orWhere('trade_name_ar', 'like', "%{$q}%");
            });
        }

        if ($status === 'ok') {
            $query->where('is_available', true)->where('quantity', '>', 0);
        } elseif ($status === 'low') {
            $query->where('quantity', '>', 0)->where('quantity', '<=', $threshold);
        } elseif ($status === 'out') {
            $query->where(function ($sq) {
                $sq->where('is_available', false)->orWhere('quantity', '<=', 0);
            });
        }

        $pharmacyMedicines = $query->orderByDesc('id')->paginate(50)->withQueryString();

        $availableCount = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('is_available', true)
            ->where('quantity', '>', 0)
            ->count();

        $outCount = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where(function ($query) {
                $query->where('is_available', false)
                    ->orWhere('quantity', '<=', 0);
            })
            ->count();

        $lowCount = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('quantity', '>', 0)
            ->where('quantity', '<=', $threshold)
            ->count();

        $totalCount = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->count();

        return view('pharmacy.medicines.index', compact('pharmacyMedicines', 'pharmacy', 'availableCount', 'outCount', 'lowCount', 'totalCount', 'q', 'status', 'threshold'));
    }

    /**
     * Show the form for creating a new medicine for the pharmacy.
     *
     * @return Response
     */
    public function create()
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        $suggestedAlternatives = Medicine::alternativesByActiveIngredient(null);

        $catalogEmpty = MohMedicine::count() === 0 && Medicine::count() === 0;

        // الـ11 أقسام الحالية فقط (القائمة المغلقة — لا قسم جديد ولا نص حر)
        $categories = Category::active()->ordered()->get();
        $subcategories = Subcategory::active()->with('category')->get()->groupBy('category_id');

        return view('pharmacy.medicines.create', compact('pharmacy', 'suggestedAlternatives', 'catalogEmpty', 'categories', 'subcategories'));
    }

    /**
     * بحث فوري عن دواء في الكتالوج العام وفي كتالوج وزارة الصحة.
     *
     * @return JsonResponse
     */
    public function search(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        SearchLog::track($q, 'pharmacy');

        $medicines = Medicine::where('trade_name', 'like', "%{$q}%")
            ->orWhere('active_ingredient', 'like', "%{$q}%")
            ->limit(10)
            ->get()
            ->map(fn (Medicine $m) => [
                'type' => 'medicine',
                'id' => $m->id,
                'name' => $m->trade_name,
                'sub' => $m->active_ingredient,
            ]);

        $mohMedicines = MohMedicine::where('trade_name', 'like', "%{$q}%")
            ->orWhere('generic_name', 'like', "%{$q}%")
            ->orWhere('manufacturer', 'like', "%{$q}%")
            ->limit(20)
            ->get()
            ->map(fn (MohMedicine $m) => [
                'type' => 'moh',
                'id' => $m->id,
                'name' => $m->trade_name,
                'sub' => $m->generic_name ?? $m->manufacturer,
                'official_price' => $m->official_price,
            ]);

        return response()->json([...$medicines, ...$mohMedicines]);
    }

    /**
     * بحث محسوب في الكتالوج المحلي (moh_medicines) فقط — لا اتصال بوزارة الصحة.
     *
     * الحقول المدعومة:
     *   - الاسم التجاري  (moh_medicines.trade_name)
     *   - المادة الفعالة (moh_medicines.generic_name)
     *   - المصنّع        (moh_medicines.manufacturer)
     *   - الاسم العربي    (medicines.trade_name_ar → جسر الاسم التجاري)
     *   - معرفات MOH المستقرة (moh_product_id / moh_drug_id) عند بحث رقمي
     *
     * النتائج محدودة بـ20، وتُعلَّم الحالات المضافة مسبقًا لصيدليتك.
     */
    public function catalogSearch(Request $request)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['items' => [], 'count' => 0, 'is_complete' => false]);
        }

        SearchLog::track($q, 'pharmacy');

        $query = MohMedicine::query()->where(function ($outer) use ($q) {
            $outer
                ->where('trade_name', 'like', "%{$q}%")
                ->orWhere('generic_name', 'like', "%{$q}%")
                ->orWhere('manufacturer', 'like', "%{$q}%");

            // الاسم العربي: عبر جسر الكتالوج المحلي (medicines.trade_name_ar → trade_name)
            $arabicMatches = Medicine::where('trade_name_ar', 'like', "%{$q}%")
                ->whereNotNull('trade_name')
                ->pluck('trade_name');
            if ($arabicMatches->isNotEmpty()) {
                $outer->orWhereIn('trade_name', $arabicMatches);
            }

            // بحث رقمي بمعرّفات MOH المستقرة
            if (ctype_digit($q)) {
                $outer->orWhere('moh_product_id', (int) $q)
                    ->orWhere('moh_drug_id', (int) $q);
            }
        });

        $items = $query->orderBy('trade_name')->limit(20)->get();

        // إسكايه واحدة: trade_name → medicine_id لكل النتائج (نفس جسر الكتالوج المحلي)
        $idMap = Medicine::idsByTradeName($items->pluck('trade_name')->all());

        $rows = [];
        if ($idMap !== []) {
            $rows = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
                ->whereIn('medicine_id', array_values($idMap))
                ->get()
                ->keyBy('medicine_id');
        }

        return response()->json([
            'items' => $items->map(function (MohMedicine $m) use ($idMap, $rows) {
                $bridgeId = $idMap[$m->trade_name] ?? null;
                $row = $bridgeId !== null ? ($rows->get($bridgeId) ?? null) : null;

                return [
                    'type' => 'moh',
                    'id' => $m->id,
                    'moh_medicine_id' => $m->id,
                    'moh_product_id' => $m->moh_product_id,
                    'moh_drug_id' => $m->moh_drug_id,
                    'name' => $m->trade_name,
                    'sub' => $m->generic_name ?: $m->manufacturer,
                    'official_price' => $m->official_price !== null ? (float) $m->official_price : null,
                    'already_added' => $row !== null,
                    'existing_row_id' => $row?->id,
                ];
            })->values()->all(),
            'count' => $items->count(),
            'is_complete' => true,
        ]);
    }


    /**
     * Store a newly created medicine in storage for the pharmacy.
     *
     * @return Response
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        $request->validate([
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0', // Changed from 'stock' to 'quantity'
            'is_available' => 'boolean',
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
        ], [
            'price.required' => __('pharmacy.medicines.create.price_required'),
            'quantity.required' => __('pharmacy.medicines.create.quantity_required'),
        ]);

        // 1) دواء مختار من الكتالوج العام
        // 2) دواء من كتالوج وزارة الصحة (يُضاف تلقائيا للكتالوج العام عند الحاجة)
        // 3) إضافة يدوية ببيانات كاملة
        $mohMedicineId = null;
        if ($request->filled('medicine_id')) {
            $medicine = Medicine::findOrFail($request->medicine_id);
        } elseif ($request->filled('moh_medicine_id')) {
            $moh = MohMedicine::findOrFail($request->moh_medicine_id);
            $medicine = $this->catalog->findOrCreateFromMoh($moh);
            $mohMedicineId = $moh->id;
        } else {
            $request->validate([
                // الاسم الإنجليزي إلزامي — يُرفض أي اسم يحتوي حروفاً عربية
                'trade_name' => [
                    'required',
                    'string',
                    'max:150',
                    'not_regex:/[\x{0600}-\x{06FF}]/u',
                ],
                // الاسم العربي اختياري — عند إرساله يجب أن يحتوي حروفاً عربية
                'trade_name_ar' => [
                    'nullable',
                    'string',
                    'max:150',
                    'regex:/[\x{0600}-\x{06FF}]/u',
                ],
                'active_ingredient' => 'required|string|max:150',
                // قاعدة «دواء جديد = قسم إلزامي»: يُختار من الأقسام الحالية فقط
                'category_id' => ['required', 'integer', 'exists:categories,id'],
                'subcategory_id' => ['nullable', 'integer', 'exists:subcategories,id'],
            ], [
                'trade_name.required' => __('pharmacy.medicines.create.trade_name_required'),
                'trade_name.not_regex' => __('pharmacy.medicines.create.trade_name_english'),
                'trade_name_ar.regex' => __('pharmacy.medicines.create.arabic_name_required'),
                'active_ingredient.required' => __('pharmacy.medicines.create.ingredient_required'),
                'category_id.required' => __('pharmacy.medicines.create.category_required'),
            ]);

            $nameAr = trim((string) $request->input('trade_name_ar'));

            // القسم يجب أن يكون نشطاً (ضمن القائمة المغلقة للأقسام الحالية)
            $category = Category::active()->find($request->category_id);
            if (! $category) {
                return back()->withInput()->withErrors(['category_id' => __('pharmacy.medicines.create.category_invalid')]);
            }
            if (! empty($request->input('subcategory_id'))) {
                $subcategory = Subcategory::where('category_id', $category->id)->find((int) $request->subcategory_id);
                if (! $subcategory) {
                    return back()->withInput()->withErrors(['subcategory_id' => __('pharmacy.medicines.create.subcategory_mismatch')]);
                }
            }

            // D-WEB1/D-WEB2: مسار الويب اليدوي يبقى كما هو عمداً — بدون بحث بالاسم
            // العربي وبدون بحث بكتالوج الوزارة وبدون إثراء المادة الفعالة
            // (توحيد هذه السلوكيات قرار منفصل؛ انظر docblock الخدمة)
            $medicine = $this->catalog->resolveByName(
                (string) $request->trade_name,
                null,
                lookupAr: false,
                lookupMoh: false
            );

            if ($medicine) {
                // إثراء محدود: الاسم العربي فقط عند الفراغ (لا مادة فعالة)
                $this->catalog->fillMissingAttributes($medicine, $nameAr, null, fillIngredient: false);
            } else {
                $medicine = $this->catalog->createFromNames(
                    (string) $request->trade_name,
                    $nameAr !== '' ? $nameAr : null,
                    (string) $request->active_ingredient
                );
            }

            // دواء محلي جديد/محل — يربطه بقسمه (إلزامي) لضمان عدم وجود دواء بلا قسم.
            // يُنشأ فقط إذا كان الدواء بلا قسم بعد — حفاظا على قاعدة «دواء واحد = قسم واحد»
            // (لا يُضاف قسم ثانٍ لدواء سبق تصنيفه، و firstOrCreate يمنع التكرار داخل نفس القسم).
            $hasCategory = CategoryMedicineLink::where('medicine_id', $medicine->id)->exists();
            if (! $hasCategory) {
                CategoryMedicineLink::create([
                    'category_id' => $category->id,
                    'medicine_id' => $medicine->id,
                    'moh_product_id' => null,
                    'moh_drug_id' => null,
                    'subcategory_id' => $request->input('subcategory_id') ? (int) $request->input('subcategory_id') : null,
                    'source' => CategoryMedicineLink::SOURCE_ADMIN,
                    'confidence' => 100,
                    'needs_review' => false,
                ]);
            }
        }

        $existingRow = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('medicine_id', $medicine->id)
            ->first();

        if ($existingRow) {
            return back()->withInput()->withErrors([
                'medicine_id' => __('pharmacy.medicines.create.already_exists'),
            ]);
        }

        // صورة الدواء (اختيارية) — تُرفع إلى Cloudinary وتُخزن على الدواء نفسه في الكتالوج العام
        if ($request->hasFile('image')) {
            Cloudinary::deleteLocal($medicine->image);
            $medicine->image = Cloudinary::upload($request->file('image'), 'medicines');
            $medicine->save();
        }

        $pharmacyMedicine = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'moh_medicine_id' => $mohMedicineId,
            'price' => $request->price,
            'quantity' => $request->quantity, // Changed from 'stock' to 'quantity'
            'is_available' => $request->boolean('is_available'),
        ]);

        // D-FCM: توحيد مع LowStockNotifier — النسخة الخاصة المحذوفة كانت تُنشئ
        // الإشعار بدون FCM؛ الآن الويب أيضا يرسل push مثل API والـ sync
        LowStockNotifier::notifyIfLowStock($pharmacyMedicine);

        return redirect()->route('pharmacy.medicines.index')->with('success', __('pharmacy.medicines.create.success'));
    }

    /**
     * صفحة طلب دواء جديد (غير موجود بالكتالوج) → تُحفظ كـ medicine_request
     * بانتظار مراجعة الإدارة.
     *
     * ⚠️ لا تُمرَّر `$subcategories`: حقل «القسم الفرعي» أُزيل من الواجهة
     * (لا يقرأه الأدمن عند الاعتماد)، فبقي الاستعلام بلا مستهلك. `storeRequest()`
     * ما زال يقبل `subcategory_id` اختياريًا، و`approve()` ما زال يقرأه من
     * الطلب — فلا انحدار في البيانات القديمة.
     */
    public function createRequest()
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        $categories = Category::active()->ordered()->get();

        return view('pharmacy.medicines.request', compact('pharmacy', 'categories'));
    }

    /**
     * حفظ طلب دواء جديد (pending) للمراجعة الإدارية.
     */
    public function storeRequest(Request $request)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        $data = $request->validate([
            'trade_name' => ['required', 'string', 'max:150', 'not_regex:/[\x{0600}-\x{06FF}]/u'],
            'trade_name_ar' => ['nullable', 'string', 'max:150', 'regex:/[\x{0600}-\x{06FF}]/u'],
            'generic_name' => ['nullable', 'string', 'max:150'],
            'manufacturer' => ['nullable', 'string', 'max:150'],
            'active_ingredient' => ['nullable', 'string', 'max:150'],
            'dosage_form' => ['nullable', 'string', 'max:150'],
            'packaging' => ['nullable', 'string', 'max:150'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'official_price' => ['nullable', 'numeric', 'min:0'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'subcategory_id' => ['nullable', 'integer', 'exists:subcategories,id'],
        ]);

        $category = Category::active()->find($data['category_id']);
        if (! $category) {
            return back()->withInput()->withErrors(['category_id' => 'القسم غير صالح']);
        }

        if (! empty($data['subcategory_id'])) {
            $subcategory = Subcategory::where('category_id', $category->id)->find($data['subcategory_id']);
            if (! $subcategory) {
                return back()->withInput()->withErrors(['subcategory_id' => 'القسم الفرعي لا ينتمي إلى القسم المختار']);
            }
        }

        MedicineRequest::create([
            'pharmacy_id' => $pharmacy->id,
            'requested_by' => $user->id,
            'status' => MedicineRequest::STATUS_PENDING,
            'trade_name' => $data['trade_name'],
            'trade_name_ar' => $data['trade_name_ar'] ?? null,
            'generic_name' => $data['generic_name'] ?? null,
            'manufacturer' => $data['manufacturer'] ?? null,
            'active_ingredient' => $data['active_ingredient'] ?? null,
            'dosage_form' => $data['dosage_form'] ?? null,
            'packaging' => $data['packaging'] ?? null,
            'barcode' => $data['barcode'] ?? null,
            'official_price' => $data['official_price'] ?? null,
            'category_id' => $category->id,
            'subcategory_id' => $data['subcategory_id'] ?? null,
        ]);

        return redirect()->route('pharmacy.medicines.index')
            ->with('success', 'تم إرسال طلب الدواء للمراجعة بنجاح. سيُضاف لمخزونك فور موافقة الإدارة.');
    }

    /**
     * Show the form for editing the specified medicine for the pharmacy.
     *
     * @return Response
     */
    public function edit(PharmacyMedicine $pharmacyMedicine)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        // Ensure the pharmacy medicine belongs to the authenticated pharmacy
        if ($pharmacyMedicine->pharmacy_id !== $pharmacy->id) {
            return redirect()->route('pharmacy.medicines.index')->with('error', __('pharmacy.medicines.edit.not_found'));
        }

        $suggestedAlternatives = Medicine::alternativesByActiveIngredient(
            $pharmacyMedicine->medicine?->active_ingredient,
            $pharmacyMedicine->medicine_id
        );

        return view('pharmacy.medicines.edit', compact('pharmacyMedicine', 'pharmacy', 'suggestedAlternatives'));
    }

    /**
     * Update the specified medicine in storage for the pharmacy.
     *
     * @return Response
     */
    public function update(Request $request, PharmacyMedicine $pharmacyMedicine)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        // Ensure the pharmacy medicine belongs to the authenticated pharmacy
        if ($pharmacyMedicine->pharmacy_id !== $pharmacy->id) {
            return redirect()->route('pharmacy.medicines.index')->with('error', __('pharmacy.medicines.edit.not_found'));
        }

        $request->validate([
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0', // Changed from 'stock' to 'quantity'
            'is_available' => 'boolean',
        ]);

        $pharmacyMedicine->update([
            'price' => $request->price,
            'quantity' => $request->quantity, // Changed from 'stock' to 'quantity'
            'is_available' => $request->boolean('is_available'),
        ]);

        // D-FCM: توحيد مع LowStockNotifier (بالإرسال)
        LowStockNotifier::notifyIfLowStock($pharmacyMedicine);

        return redirect()->route('pharmacy.medicines.index')->with('success', __('pharmacy.medicines.edit.success'));
    }

    /**
     * Remove the specified medicine from storage for the pharmacy.
     *
     * @return Response
     */
    public function destroy(PharmacyMedicine $pharmacyMedicine)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        // Ensure the pharmacy medicine belongs to the authenticated pharmacy
        if ($pharmacyMedicine->pharmacy_id !== $pharmacy->id) {
            return redirect()->route('pharmacy.medicines.index')->with('error', __('pharmacy.medicines.destroy.error'));
        }

        $pharmacyMedicine->delete();

        return redirect()->route('pharmacy.medicines.index')->with('success', __('pharmacy.medicines.destroy.success'));
    }
}
