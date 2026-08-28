<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PharmacyMedicineRequest;
use App\Http\Resources\PharmacyMedicineResource;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Notification;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\SearchLog;
use App\Support\Cloudinary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PharmacyMedicineController extends Controller
{
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

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
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
     * تفاصيل سطر دواء واحد ضمن مخزون الصيدلية.
     */
    public function show(Request $request, PharmacyMedicine $medicine): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
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

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $data = $request->validated();

        // 1) دواء مختار من الكتالوج العام
        // 2) عنصر من كتالوج وزارة الصحة — يُضاف تلقائياً للكتالوج العام عند الحاجة (نفس منطق الويب)
        if (! empty($data['medicine_id'])) {
            $medicine = Medicine::findOrFail($data['medicine_id']);
        } else {
            $moh = MohMedicine::findOrFail($data['moh_medicine_id']);
            $medicine = Medicine::where('trade_name', $moh->trade_name)->first()
                ?? Medicine::create([
                    'trade_name' => $moh->trade_name,
                    'active_ingredient' => $moh->generic_name ?? $moh->trade_name,
                    'description' => $moh->manufacturer ?? $moh->company,
                ]);
        }

        $exists = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('medicine_id', $medicine->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'medicine_id' => 'هذا الدواء مضاف مسبقاً لمخزون الصيدلية',
            ])->status(422);
        }

        // صورة اختيارية من الموبايل (رابط مباشر — Cloudinary) تُحفظ على الدواء في الكتالوج العام
        if (! empty($data['image_url'])) {
            Cloudinary::deleteLocal($medicine->image);
            $medicine->image = $data['image_url'];
            $medicine->save();
        }

        $pharmacyMedicine = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => $data['price'],
            'quantity' => $data['quantity'],
            'is_available' => $request->boolean('is_available'),
        ]);

        $this->notifyIfLowStock($pharmacyMedicine);

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

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
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
            // صورة اختيارية — رابط Cloudinary مباشر من الموبايل
            'image_url' => 'nullable|url|max:2048',
        ]);

        $name = trim($data['trade_name']);
        $nameAr = isset($data['trade_name_ar']) ? trim($data['trade_name_ar']) : null;

        // الحل بالأسماء فقط بدون أي معرّف:
        // 1) الاسم الإنجليزي في الكتالوج العام، وإلا الاسم العربي إن أُرسل
        $medicine = Medicine::where('trade_name', $name)->first()
            ?? ($nameAr ? Medicine::where('trade_name_ar', $nameAr)->first() : null);

        if (! $medicine) {
            // 2) كتالوج وزارة الصحة بالاسم الإنجليزي — يُنسخ للكتالوج العام عند الحاجة
            $moh = MohMedicine::where('trade_name', $name)->first();

            // 3) لا وجود بالكتالوجين — إنشاء دواء جديد (المادة الفعالة إجبارية من الموبايل)
            $medicine = Medicine::create([
                'trade_name' => $name,
                'trade_name_ar' => $nameAr,
                'active_ingredient' => trim($data['active_ingredient']),
                'description' => $moh->manufacturer ?? ($moh->company ?? null),
            ]);
        } else {
            // إثراء الكتالوج: دواء موجود بمادة فعالة فارغة + الموبايل أرسلها → تُكمّل
            if (empty($medicine->active_ingredient)) {
                $medicine->active_ingredient = trim($data['active_ingredient']);
                $medicine->save();
            }

            if ($nameAr && empty($medicine->trade_name_ar)) {
                $medicine->trade_name_ar = $nameAr;
                $medicine->save();
            }
        }

        $exists = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('medicine_id', $medicine->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'trade_name' => 'هذا الدواء مضاف مسبقاً لمخزون الصيدلية',
            ])->status(422);
        }

        // صورة اختيارية من الموبايل (رابط مباشر — Cloudinary) تُحفظ على الدواء في الكتالوج العام
        if (! empty($data['image_url'])) {
            Cloudinary::deleteLocal($medicine->image);
            $medicine->image = $data['image_url'];
            $medicine->save();
        }

        $pharmacyMedicine = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => $data['price'],
            'quantity' => $data['quantity'],
            'is_available' => $request->boolean('is_available'),
        ]);

        $this->notifyIfLowStock($pharmacyMedicine);

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

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
        if (! $pharmacy || $medicine->pharmacy_id !== $pharmacy->id) {
            return response()->json(['success' => false, 'message' => 'الدواء غير موجود في مخزون الصيدلية'], 404);
        }

        $data = $request->validated();

        $medicine->update([
            'price' => $data['price'],
            'quantity' => $data['quantity'],
            'is_available' => $request->boolean('is_available'),
        ]);

        // إثراء بيانات الكتالوج عند تحديث المخزون: لا نمسح قيمة صحيحة موجودة
        $medicine->loadMissing('medicine');
        $catalogMedicine = $medicine->medicine;
        $catalogDirty = false;

        if (! empty($data['trade_name']) && empty($catalogMedicine->trade_name)) {
            $catalogMedicine->trade_name = trim($data['trade_name']);
            $catalogDirty = true;
        }

        if (! empty($data['trade_name_ar']) && empty($catalogMedicine->trade_name_ar)) {
            $catalogMedicine->trade_name_ar = trim($data['trade_name_ar']);
            $catalogDirty = true;
        }

        if (! empty($data['active_ingredient']) && empty($catalogMedicine->active_ingredient)) {
            $catalogMedicine->active_ingredient = trim($data['active_ingredient']);
            $catalogDirty = true;
        }

        if ($catalogDirty) {
            $catalogMedicine->save();
        }

        $this->notifyIfLowStock($medicine);

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

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
        if (! $pharmacy || $medicine->pharmacy_id !== $pharmacy->id) {
            return response()->json(['success' => false, 'message' => 'الدواء غير موجود في مخزون الصيدلية'], 404);
        }

        $medicine->delete();

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

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
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

    /**
     * إنشاء إشعار نقص/نفاد المخزون بنفس منطق لوحة التحكم.
     */
    private function notifyIfLowStock(PharmacyMedicine $pm): void
    {
        $threshold = PharmacyMedicine::LOW_STOCK_THRESHOLD;
        $pharmacyUser = $pm->pharmacy?->user;
        if (! $pharmacyUser) {
            return;
        }
        if ($pm->quantity <= 0) {
            Notification::create([
                'user_id' => $pharmacyUser->id,
                'medicine_id' => $pm->medicine_id,
                'type' => 'out_of_stock',
                'message' => __('layout.notif_out_of_stock', ['name' => $pm->medicine?->trade_name]),
                'is_read' => false,
                'created_at' => now(),
            ]);

            return;
        }
        if ($pm->quantity > 0 && $pm->quantity <= $threshold) {
            Notification::create([
                'user_id' => $pharmacyUser->id,
                'medicine_id' => $pm->medicine_id,
                'type' => 'low_stock',
                'message' => __('layout.notif_low_stock_pharmacy', [
                    'name' => $pm->medicine?->trade_name,
                    'count' => $pm->quantity,
                ]),
                'is_read' => false,
                'created_at' => now(),
            ]);
        }
    }
}
