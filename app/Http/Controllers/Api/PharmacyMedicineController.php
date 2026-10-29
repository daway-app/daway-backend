<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PharmacyMedicineRequest;
use App\Http\Resources\PharmacyMedicineResource;
use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\PharmacyMedicine;
use App\Models\SearchLog;
use App\Models\Subcategory;
use App\Services\MedicineCatalogService;
use App\Services\PharmacyContext;
use App\Models\SyncTombstone;
use App\Support\CategoryCatalogCache;
use App\Support\Cloudinary;
use App\Support\Image;
use App\Support\LowStockNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PharmacyMedicineController extends Controller
{
    public function __construct(private readonly MedicineCatalogService $catalog)
    {
    }

    /**
     * كتابة صورة الدواء على الكتالوج **المشترك** — بشرط الحكر فقط.
     *
     * ── المشكلة التي تمنعها ─────────────────────────────────────────
     * `medicines` جدول كتالوج عام تشترك فيه كل الصيدليات. الكتابة عليه
     * بلا شرط تعني أن صيدلية ترفع صورة فتُغيّر صورة الدواء عند **كل**
     * الصيدليات، والأخطر أن `Cloudinary::deleteLocal` كان يحذف ملف
     * الصورة السابق — أي حذف أصل يملكه غيرنا.
     *
     * ── القاعدة ─────────────────────────────────────────────────────
     * نفس بوابة `MedicineCatalogService::applySoleOwnerEdits`: لا كتابة
     * إلا إذا كانت هذه الصيدلية المستخدمة **الوحيدة** للدواء. الفحص يجري
     * **قبل** الرفع إلى Cloudinary أيضًا، فلا نُنشئ ملفًا يتيمًا في كل
     * محاولة مرفوضة.
     *
     * الصيدلية غير الحصرية لا تفقد شيئًا: الاستجابة تُرجع صورة الكتالوج
     * القائمة كما هي (`Image::url($medicine->image)`)، ونُسجّل تحذيرًا
     * ليكون القرار مرئيًا لا صامتًا.
     */
    private function applyCatalogImage(
        Medicine $medicine,
        int $pharmacyId,
        ?string $imageUrl,
        ?UploadedFile $file,
    ): void {
        if ($imageUrl === null && $file === null) {
            return;
        }

        // ── قفل صف الكتالوج قبل القرار (TOCTOU) ────────────────────────
        // الفحص والكتابة يجب أن يجريا تحت قفل صف `medicines`: صيدليتان
        // تضيفان نفس الدواء في نفس اللحظة كانتا ترين "لا مستخدم غيري"
        // قبل أن يُدرج أي صفّ مخزون، فتكتب كلتاهما وتحذف الثانية ملف
        // الأولى. القفل يجعل "اقرأ ← تحقّق ← اكتب" ذرّيًّا على السجل.
        $locked = Medicine::query()->whereKey($medicine->id)->lockForUpdate()->first();

        if ($locked === null) {
            return;
        }

        $medicine = $locked;

        if (! $this->catalog->isSoleUser($medicine->id, $pharmacyId)) {
            Log::warning('catalog image write skipped — medicine is shared with other pharmacies', [
                'medicine_id' => $medicine->id,
                'pharmacy_id' => $pharmacyId,
            ]);

            return;
        }

        $image = $imageUrl;

        if ($file !== null) {
            $image = Cloudinary::upload($file, 'medicines') ?? $image;
        }

        if ($image === null || $image === '') {
            return;
        }

        $previous = $medicine->image;
        $medicine->image = $image;

        try {
            $medicine->save();
        } catch (\Throwable $e) {
            // اتساق قاعدة البيانات والملفات: فشل الحفظ ⇒ التراجع يلغي كل
            // شيء، لكن الملف المرفوع حديثًا كان سيبقى يتيمًا — ننظّفه.
            if ($file !== null && $image !== $imageUrl) {
                Cloudinary::deleteLocal($image);
            }

            throw $e;
        }

        // حذف الملف السابق **بعد** نجاح الحفظ لا قبله: لو حُذف أولًا وفشل
        // الحفظ لَفقدنا الصورة القديمة من الملف والسجل معًا. فشل التنظيف
        // لا يُفشل الطلب — الملف اليتيم القديم أقل ضررًا من فقدان الصورة.
        if ($previous && $previous !== $image) {
            try {
                Cloudinary::deleteLocal($previous);
            } catch (\Throwable $e) {
                Log::warning('previous catalog image cleanup failed', [
                    'medicine_id' => $medicine->id,
                    'image' => $previous,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * قائمة أدوية الصيدلية الحالية مع pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $perPage = (int) ($validated['per_page'] ?? 20);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $items = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->with('medicine')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الأدوية بنجاح',
            'data' => PharmacyMedicineResource::collection($items->items()),
            'pagination' => [
                'total' => $items->total(),
                'per_page' => $items->perPage(),
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    /**
     * أدوية الصيدلية الحالية غير المرتبطة بأي قسم.
     */
    public function uncategorized(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $q = trim((string) ($validated['q'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 50);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $items = PharmacyMedicine::query()
            ->where('pharmacy_id', $pharmacy->id)
            ->whereHas('medicine')
            ->whereDoesntHave('medicine.categoryLinks')
            ->when($q !== '', function ($query) use ($q): void {
                $query->whereHas('medicine', fn ($medicine) => $medicine
                    ->where('trade_name', 'like', "%{$q}%")
                    ->orWhere('trade_name_ar', 'like', "%{$q}%")
                    ->orWhere('active_ingredient', 'like', "%{$q}%"));
            })
            ->with('medicine')
            ->orderByDesc('id')
            ->paginate($perPage);

        $data = collect($items->items())->map(function (PharmacyMedicine $item): array {
            $medicine = $item->medicine;

            return [
                'id' => $item->id,
                'medicine_id' => $item->medicine_id !== null ? (int) $item->medicine_id : null,
                'trade_name' => $medicine?->trade_name,
                'trade_name_ar' => $medicine?->trade_name_ar,
                'description' => $medicine?->description,
                'active_ingredient' => $medicine?->active_ingredient,
                'image_url' => Image::url($medicine?->image),
                'quantity' => (int) $item->quantity,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الأدوية غير المصنفة بنجاح',
            'data' => $data,
            'pagination' => [
                'total' => $items->total(),
                'per_page' => $items->perPage(),
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    /**
     * تعيين قسم (وقسم فرعي اختياري) لدواء في مخزون الصيدلية.
     */
    public function assignCategory(Request $request, PharmacyMedicine $medicine): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy || $medicine->pharmacy_id !== $pharmacy->id) {
            return response()->json(['success' => false, 'message' => 'الدواء غير موجود في مخزون الصيدلية'], 404);
        }

        $data = $request->validate([
            'category_id' => ['required', 'integer'],
            'subcategory_id' => ['nullable', 'integer'],
        ]);

        if ($medicine->medicine_id === null) {
            throw ValidationException::withMessages([
                'medicine_id' => 'الدواء غير مرتبط بالكتالوج العام ولا يمكن تصنيفه',
            ])->status(422);
        }

        $category = Category::active()->find((int) $data['category_id']);
        if (! $category) {
            throw ValidationException::withMessages([
                'category_id' => 'القسم غير صالح أو غير نشط',
            ])->status(422);
        }

        $subcategoryId = isset($data['subcategory_id']) && $data['subcategory_id'] !== null
            ? (int) $data['subcategory_id']
            : null;
        if ($subcategoryId !== null) {
            $subcategory = Subcategory::active()
                ->where('category_id', $category->id)
                ->find($subcategoryId);

            if (! $subcategory) {
                throw ValidationException::withMessages([
                    'subcategory_id' => 'القسم الفرعي لا ينتمي إلى القسم المختار أو غير نشط',
                ])->status(422);
            }
        }

        $medicineId = (int) $medicine->medicine_id;

        DB::transaction(function () use ($medicineId, $category, $subcategoryId): void {
            $locked = Medicine::whereKey($medicineId)->lockForUpdate()->first();
            if (! $locked) {
                throw ValidationException::withMessages([
                    'medicine_id' => 'الدواء غير موجود في الكتالوج العام',
                ])->status(422);
            }

            $alreadyLinked = CategoryMedicineLink::where('medicine_id', $medicineId)->exists();
            if ($alreadyLinked) {
                throw ValidationException::withMessages([
                    'category_id' => 'هذا الدواء مصنف مسبقاً',
                ])->status(422);
            }

            CategoryMedicineLink::create([
                'category_id' => $category->id,
                'subcategory_id' => $subcategoryId,
                'moh_product_id' => null,
                'moh_drug_id' => null,
                'medicine_id' => $medicineId,
                'source' => CategoryMedicineLink::SOURCE_PHARMACY,
                'confidence' => 100,
                'needs_review' => false,
            ]);
        });

        CategoryCatalogCache::bump();

        return response()->json([
            'success' => true,
            'message' => 'تم تعيين القسم بنجاح',
            'data' => [
                'medicine_id' => $medicineId,
                'category_id' => (int) $category->id,
                'subcategory_id' => $subcategoryId,
            ],
        ]);
    }

    /**
     * تفاصيل سطر دواء واحد ضمن مخزون الصيدلية.
     */
    public function show(Request $request, PharmacyMedicine $medicine): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy || $medicine->pharmacy_id !== $pharmacy->id) {
            return response()->json(['success' => false, 'message' => 'الدواء غير موجود في مخزون الصيدلية'], 404);
        }

        $medicine->load('medicine');

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الدواء بنجاح',
            'data' => new PharmacyMedicineResource($medicine),
        ]);
    }

    /**
     * إضافة دواء لمخزون الصيدلية.
     */
    public function store(PharmacyMedicineRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $data = $request->validated();

        // 1) دواء مختار من الكتالوج العام
        // 2) عنصر من كتالوج وزارة الصحة — يضاف تلقائيا للكتالوج العام عند الحاجة (نفس منطق الويب)
        $mohMedicineId = null;
        if (! empty($data['medicine_id'])) {
            $medicine = Medicine::findOrFail($data['medicine_id']);
        } else {
            $moh = MohMedicine::findOrFail($data['moh_medicine_id']);
            $medicine = $this->catalog->findOrCreateFromMoh($moh);
            $mohMedicineId = $moh->id;
        }

        $exists = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('medicine_id', $medicine->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'medicine_id' => 'هذا الدواء مضاف مسبقاً لمخزون الصيدلية',
            ])->status(422);
        }

        // صورة اختيارية: ملف مرفوع يُرفع إلى Cloudinary، أو رابط مباشر كبديل.
        // الملف يفوز عند إرسال الاثنين معاً.
        //
        // ⚠️ الكتابة على `medicines` (الكتالوج **المشترك**) محكومة ببوابة
        // الحكر نفسها المستخدمة في `applySoleOwnerEdits`: صيدلية ليست
        // المستخدمة الوحيدة لا يحق لها تغيير صورة الدواء الذي تستخدمه غيرها.
        //
        // الإدراج وكتابة الصورة داخل **معاملة واحدة**: قفل صف الكتالوج في
        // `applyCatalogImage` يحمي "اقرأ ← تحقّق ← اكتب" من سباق صيدليتين
        // تضيفان نفس الدواء في نفس اللحظة، وفشل أي خطوة يتراجع بالكامل.
        unset($data['image_file']);

        $pharmacyMedicine = DB::transaction(function () use ($medicine, $pharmacy, $data, $request, $mohMedicineId) {
            $row = PharmacyMedicine::create([
                'pharmacy_id' => $pharmacy->id,
                'medicine_id' => $medicine->id,
                'moh_medicine_id' => $mohMedicineId,
                'price' => $data['price'],
                'quantity' => $data['quantity'],
                'is_available' => $request->boolean('is_available'),
            ]);

            $this->applyCatalogImage(
                $medicine,
                $pharmacy->id,
                $data['image_url'] ?? null,
                $request->file('image_file'),
            );

            return $row;
        });

        LowStockNotifier::notifyIfLowStock($pharmacyMedicine);

        $pharmacyMedicine->load('medicine');

        return response()->json([
            'success' => true,
            'message' => 'تمت إضافة الدواء بنجاح',
            'data' => new PharmacyMedicineResource($pharmacyMedicine),
        ], 201);
    }

    /**
     * إضافة دواء للمخزون بالاسم مباشرة — مخصصة للموبايل بدون الاعتماد على أي معرّف.
     *
     * الاسم الإنجليزي (trade_name) إلزامي، والعربي (trade_name_ar) اختياري.
     *
     * ترتيب الحل:
     *  1) الاسم الإنجليزي في الكتالوج العام، ثم الاسم العربي إن أُرسل.
     *  2) البحث في كتالوج وزارة الصحة وإنشاؤه تلقائياً بالكتالوج العام (نفس منطق الويب).
     *  3) إنشاء دواء جديد من الأسماء والمادة الفعالة الاختيارية.
     */
    public function storeByName(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $data = $request->validate([
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
            'active_ingredient' => ['required', 'string', 'max:255'],
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0',
            'is_available' => 'sometimes|boolean',
            // قاعدة «دواء جديد = قسم إلزامي»: يُختار من القائمة المغلقة للأقسام الحالية
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'subcategory_id' => ['nullable', 'integer', 'exists:subcategories,id'],
            // C4: SecureImageUrl rule تستبعد javascript:/data: و http://
            'image_url' => ['nullable', 'string', 'max:2048', new \App\Rules\SecureImageUrl],
            'image_file' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ]);

        // القسم يجب أن يكون نشطاً (ضمن القائمة المغلقة للأقسام الحالية)
        $category = Category::active()->find($data['category_id']);
        if (! $category) {
            throw ValidationException::withMessages([
                'category_id' => 'القسم غير صالح أو غير نشط',
            ])->status(422);
        }

        if (! empty($data['subcategory_id'])) {
            $subcategory = Subcategory::where('category_id', $category->id)->find((int) $data['subcategory_id']);
            if (! $subcategory) {
                throw ValidationException::withMessages([
                    'subcategory_id' => 'القسم الفرعي لا ينتمي إلى القسم المختار',
                ])->status(422);
            }
        }

        $name = trim($data['trade_name']);
        $nameAr = isset($data['trade_name_ar']) ? trim($data['trade_name_ar']) : null;

        // الحل بالأسماء فقط بدون أي معرّف:
        // 1) الاسم الإنجليزي في الكتالوج العام، وإلا الاسم العربي إن أُرسل
        // ملاحظة D-1: البحث في كتالوج الوزارة هنا يُستخدم للوصف فقط (وليس للنسخ)
        // لأن الإنشاء يكون من أسماء المدخلات — يُحفظ السلوك حرفياً
        $medicine = $this->catalog->resolveByName($name, $nameAr, lookupMoh: false);

        if (! $medicine) {
            // 2) كتالوج وزارة الصحة بالاسم الإنجليزي — يُشتق منه الوصف فقط
            $moh = MohMedicine::where('trade_name', $name)->first();

            // 3) لا وجود بالكتالوجين — إنشاء دواء جديد (المادة الفعالة إجبارية من الموبايل)
            $medicine = $this->catalog->createFromNames(
                $name,
                $nameAr,
                trim($data['active_ingredient']),
                $moh
            );
        } else {
            // إثراء الكتالوج: دواء موجود بفراغات → تُكمَّل (أبداً لا تستبدل)
            $this->catalog->fillMissingAttributes(
                $medicine,
                $nameAr,
                trim($data['active_ingredient'])
            );
        }

        // دواء محلي جديد/محل — يربطه بقسمه (إلزامي) حفاظاً على «دواء واحد = قسم واحد»:
        // يُنشأ الرابط فقط إذا كان الدواء بلا قسم بعد، وبمفتاح medicine_id المستقر.
        $hasCategory = CategoryMedicineLink::where('medicine_id', $medicine->id)->exists();
        if (! $hasCategory) {
            CategoryMedicineLink::create([
                'category_id' => $category->id,
                'medicine_id' => $medicine->id,
                'moh_product_id' => null,
                'moh_drug_id' => null,
                'subcategory_id' => ! empty($data['subcategory_id']) ? (int) $data['subcategory_id'] : null,
                'source' => CategoryMedicineLink::SOURCE_ADMIN,
                'confidence' => 100,
                'needs_review' => false,
            ]);
        }

        $exists = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('medicine_id', $medicine->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'trade_name' => 'هذا الدواء مضاف مسبقاً لمخزون الصيدلية',
            ])->status(422);
        }

        // صورة اختيارية: ملف مرفوع يُرفع إلى Cloudinary، أو رابط مباشر كبديل.
        // الملف يفوز عند إرسال الاثنين معاً.
        //
        // ⚠️ نفس بوابة الحكر: `storeByName` قد تحلّ إلى دواء موجود يستخدمه
        // غيرنا، وكتابة الصورة عليه كانت تلوّث كتالوج صيدليات أخرى (وقد
        // تحذف ملفها من Cloudinary). الإدراج والصورة في معاملة واحدة —
        // نفس سبب `store()` أعلاه.
        unset($data['image_file']);

        $pharmacyMedicine = DB::transaction(function () use ($medicine, $pharmacy, $data, $request) {
            $row = PharmacyMedicine::create([
                'pharmacy_id' => $pharmacy->id,
                'medicine_id' => $medicine->id,
                'price' => $data['price'],
                'quantity' => $data['quantity'],
                'is_available' => $request->boolean('is_available'),
            ]);

            $this->applyCatalogImage(
                $medicine,
                $pharmacy->id,
                $data['image_url'] ?? null,
                $request->file('image_file'),
            );

            return $row;
        });

        LowStockNotifier::notifyIfLowStock($pharmacyMedicine);

        $pharmacyMedicine->load('medicine');

        return response()->json([
            'success' => true,
            'message' => 'تمت إضافة الدواء بنجاح',
            'data' => new PharmacyMedicineResource($pharmacyMedicine),
        ], 201);
    }

    /**
     * تحديث سطر دواء ضمن مخزون الصيدلية.
     */
    public function update(PharmacyMedicineRequest $request, PharmacyMedicine $medicine): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy || $medicine->pharmacy_id !== $pharmacy->id) {
            return response()->json(['success' => false, 'message' => 'الدواء غير موجود في مخزون الصيدلية'], 404);
        }

        $data = $request->validated();

        $medicine->update([
            'price' => $data['price'],
            'quantity' => $data['quantity'],
            'is_available' => $request->boolean('is_available'),
        ]);

        // إثراء بيانات الكتالوج عند تحديث المخزون.
        // القاعدة: اسمح بالتحديث فقط إذا لم تستخدم صيدلية أخرى نفس الدواء
        // (الـ catalog منفرد لصيدلية هذه). وإلا فالـ catalog ملك عام.
        $medicine->loadMissing('medicine');
        $this->catalog->applySoleOwnerEdits(
            $medicine->medicine,
            $pharmacy->id,
            [
                'trade_name' => $data['trade_name'] ?? null,
                'trade_name_ar' => $data['trade_name_ar'] ?? null,
                'active_ingredient' => $data['active_ingredient'] ?? null,
            ]
        );

        LowStockNotifier::notifyIfLowStock($medicine);

        $medicine->load('medicine');

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الدواء بنجاح',
            'data' => new PharmacyMedicineResource($medicine),
        ]);
    }

    /**
     * حذف دواء من مخزون الصيدلية.
     */
    public function destroy(Request $request, PharmacyMedicine $medicine): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy || $medicine->pharmacy_id !== $pharmacy->id) {
            return response()->json(['success' => false, 'message' => 'الدواء غير موجود في مخزون الصيدلية'], 404);
        }

        $medicine->delete();

        // M-16: tombstone — حتى يعرف الـ PWA بالحذف عند الـ pull
        SyncTombstone::create([
            'pharmacy_id' => $pharmacy->id,
            'pharmacy_medicine_id' => $medicine->id,
            'deleted_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم حذف الدواء بنجاح',
        ]);
    }

    /**
     * بحث فوري عن دواء في الكتالوج العام وفي كتالوج وزارة الصحة.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->get('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([
                'success' => true,
                'message' => 'تم البحث بنجاح',
                'data' => ['medicines' => [], 'moh_catalog' => []],
            ]);
        }

        SearchLog::track($q, 'pharmacy');

        $medicines = Medicine::where('trade_name', 'like', "%{$q}%")
            ->orWhere('trade_name_ar', 'like', "%{$q}%")
            ->orWhere('active_ingredient', 'like', "%{$q}%")
            ->limit(10)
            ->get()
            ->map(fn (Medicine $m) => [
                'type' => 'medicine',
                'id' => $m->id,
                'name' => $m->trade_name,
                'name_ar' => $m->trade_name_ar,
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
                'official_price' => $m->official_price !== null ? (float) $m->official_price : null,
            ]);

        return response()->json([
            'success' => true,
            'message' => 'تم البحث بنجاح',
            'data' => [
                'medicines' => $medicines->values(),
                'moh_catalog' => $mohMedicines->values(),
            ],
        ]);
    }

    /**
     * الأدوية البديلة بنفس المادة الفعالة.
     */
    public function alternatives(Request $request, PharmacyMedicine $medicine): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy || $medicine->pharmacy_id !== $pharmacy->id) {
            return response()->json(['success' => false, 'message' => 'الدواء غير موجود في مخزون الصيدلية'], 404);
        }

        $medicine->loadMissing('medicine');
        $activeIngredient = $medicine->medicine?->active_ingredient;

        if (! $activeIngredient) {
            return response()->json([
                'success' => true,
                'message' => 'تم جلب البدائل بنجاح',
                'data' => [],
            ]);
        }

        $alternatives = Medicine::where('active_ingredient', $activeIngredient)
            ->where('id', '!=', $medicine->medicine_id)
            ->orderBy('trade_name')
            ->limit(10)
            ->get()
            ->map(fn (Medicine $m) => [
                'id' => $m->id,
                'trade_name' => $m->trade_name,
                'active_ingredient' => $m->active_ingredient,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب البدائل بنجاح',
            'data' => $alternatives,
        ]);
    }
}