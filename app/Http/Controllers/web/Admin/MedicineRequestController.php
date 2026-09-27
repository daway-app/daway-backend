<?php

namespace App\Http\Controllers\web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\MohMedicine;
use App\Models\PharmacyMedicine;
use App\Models\Subcategory;
use App\Support\CategoryCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * مراجعة طلبات الأدوية الجديدة من الصيدليات.
 *
 * عند الاعتماد: يُعاد استخدام دواء MOH موجود إن وُجد (بالاسم)، وإلا يُنشأ
 * دواء محلي canonical في medicines. يُنشأ رابط القسم والمخزون، وتُربط الـ request
 * بأحد الحقلين حصريًا. العملية كاملة داخل transaction.
 */
class MedicineRequestController extends Controller
{
    public function index(Request $request)
    {
        $status = (string) $request->query('status', MedicineRequest::STATUS_PENDING);
        if (! in_array($status, MedicineRequest::STATUSES, true)) {
            $status = MedicineRequest::STATUS_PENDING;
        }

        $q = trim((string) $request->query('q', ''));

        $requests = MedicineRequest::query()
            ->with(['pharmacy', 'category', 'subcategory'])
            ->where('status', $status)
            ->when($q !== '' && mb_strlen($q) >= 2, function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('trade_name', 'like', "%{$q}%")
                        ->orWhere('generic_name', 'like', "%{$q}%")
                        ->orWhere('active_ingredient', 'like', "%{$q}%");
                });
            })
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $stats = [
            'pending' => MedicineRequest::where('status', MedicineRequest::STATUS_PENDING)->count(),
            'approved' => MedicineRequest::where('status', MedicineRequest::STATUS_APPROVED)->count(),
            'rejected' => MedicineRequest::where('status', MedicineRequest::STATUS_REJECTED)->count(),
        ];

        return view('medicine_requests.index', compact('requests', 'status', 'q', 'stats'));
    }

    public function show(MedicineRequest $medicineRequest)
    {
        $medicineRequest->load(['pharmacy', 'category', 'subcategory', 'approvedMohMedicine', 'approvedMedicine']);

        return view('medicine_requests.show', compact('medicineRequest'));
    }

    public function approve(Request $request, MedicineRequest $medicineRequest): RedirectResponse
    {
        $data = $request->validate([
            // حقول قابلة للتعديل قبل الاعتماد (اختيارية)
            'trade_name' => ['nullable', 'string', 'max:150', 'not_regex:/[\x{0600}-\x{06FF}]/u'],
            'trade_name_ar' => ['nullable', 'string', 'max:150', 'regex:/[\x{0600}-\x{06FF}]/u'],
            'generic_name' => ['nullable', 'string', 'max:150'],
            'manufacturer' => ['nullable', 'string', 'max:150'],
            'active_ingredient' => ['nullable', 'string', 'max:150'],
            'dosage_form' => ['nullable', 'string', 'max:150'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'subcategory_id' => ['nullable', 'integer', 'exists:subcategories,id'],
        ]);

        $result = DB::transaction(function () use ($medicineRequest, $data, $request) {
            // قفل صف الطلب لمنع الاعتماد المتزامن
            $locked = MedicineRequest::where('id', $medicineRequest->id)->lockForUpdate()->first();

            if ($locked->status !== MedicineRequest::STATUS_PENDING) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'request' => 'الطلب ليس بحالة بانتظار المراجعة',
                ]);
            }

            // الحقول النهائية بعد تعديل الإدارة (التغييرات غير المرسلة تبقى كما هي)
            $name = trim($data['trade_name'] ?? $locked->trade_name);
            $nameAr = $data['trade_name_ar'] ?? $locked->trade_name_ar;
            $ingredient = $data['active_ingredient'] ?? $locked->active_ingredient;
            $categoryId = $data['category_id'] ?? $locked->category_id;
            $subcategoryId = $data['subcategory_id'] ?? $locked->subcategory_id;

            // تحقق من صحة القسم/القسم الفرعي بعد التعديل
            $category = Category::active()->find($categoryId);
            if (! $category) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'category_id' => 'القسم غير صالح',
                ]);
            }
            if ($subcategoryId) {
                $sub = Subcategory::where('category_id', $category->id)->find($subcategoryId);
                if (! $sub) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'subcategory_id' => 'القسم الفرعي لا ينتمي للقسم',
                    ]);
                }
            }

            // منع الدوبليكات: MOH أولاً (بالاسم)، ثم محلي
            $moh = MohMedicine::where('trade_name', $name)->lockForUpdate()->first();

            $approvedMohId = null;
            $approvedMedId = null;
            $inventoryMedId = null;

            if ($moh) {
                $bridge = Medicine::where('trade_name', $moh->trade_name)->lockForUpdate()->first()
                    ?? Medicine::create([
                        'trade_name' => $moh->trade_name,
                        'active_ingredient' => $moh->generic_name ?? $moh->trade_name,
                        'description' => $moh->manufacturer ?? $moh->company,
                    ]);

                $approvedMohId = $moh->id;
                $approvedMedId = null;
                $inventoryMedId = $bridge->id;
            } else {
                $local = Medicine::where('trade_name', $name)->lockForUpdate()->first();

                if (! $local) {
                    $local = Medicine::create([
                        'trade_name' => $name,
                        'trade_name_ar' => $nameAr,
                        'active_ingredient' => $ingredient ?? $name,
                    ]);
                }

                $approvedMohId = null;
                $approvedMedId = $local->id;
                $inventoryMedId = $local->id;
            }

            // رابط القسم (نفس مفاتيح المصدر المستقر)
            if ($approvedMohId !== null) {
                $mohRow = MohMedicine::find($approvedMohId);
                CategoryMedicineLink::firstOrCreate(
                    [
                        'category_id' => $category->id,
                        'subcategory_id' => $subcategoryId,
                        'moh_product_id' => $mohRow->moh_product_id,
                        'moh_drug_id' => $mohRow->moh_drug_id,
                    ],
                    ['source' => CategoryMedicineLink::SOURCE_ADMIN, 'confidence' => 100, 'needs_review' => false]
                );
            } else {
                CategoryMedicineLink::firstOrCreate(
                    [
                        'category_id' => $category->id,
                        'subcategory_id' => $subcategoryId,
                        'medicine_id' => $approvedMedId,
                    ],
                    ['source' => CategoryMedicineLink::SOURCE_ADMIN, 'confidence' => 100, 'needs_review' => false]
                );
            }

            // سطر مخزون الصيدلية المطلوبة (للمريض يظهر فور تفعيل المخزون)
            if (! PharmacyMedicine::where('pharmacy_id', $locked->pharmacy_id)
                ->where('medicine_id', $inventoryMedId)
                ->where(fn ($q) => $approvedMohId
                    ? $q->where('moh_medicine_id', $approvedMohId)
                    : $q->whereNull('moh_medicine_id'))
                ->exists()) {
                PharmacyMedicine::create([
                    'pharmacy_id' => $locked->pharmacy_id,
                    'medicine_id' => $inventoryMedId,
                    'moh_medicine_id' => $approvedMohId,
                    'price' => $locked->official_price ?? 0,
                    'quantity' => 0,
                    'is_available' => false,
                ]);
            }

            // تحديث الطلب بالحالة والحقل الحصري
            $locked->fill([
                'trade_name' => $name,
                'trade_name_ar' => $nameAr,
                'active_ingredient' => $ingredient,
                'category_id' => $category->id,
                'subcategory_id' => $subcategoryId,
                'status' => MedicineRequest::STATUS_APPROVED,
                'approved_moh_medicine_id' => $approvedMohId,
                'approved_medicine_id' => $approvedMedId,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();

            CategoryCatalogCache::bump();

            return $locked;
        });

        return redirect()
            ->route('medicine_requests.index', ['status' => MedicineRequest::STATUS_APPROVED])
            ->with('success', 'تمت الموافقة على الطلب بنجاح');
    }

    public function reject(Request $request, MedicineRequest $medicineRequest): RedirectResponse
    {
        $data = $request->validate([
            'admin_notes' => ['required', 'string', 'max:2000'],
        ]);

        $medicineRequest->update([
            'status' => MedicineRequest::STATUS_REJECTED,
            'admin_notes' => $data['admin_notes'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return redirect()
            ->route('medicine_requests.index', ['status' => MedicineRequest::STATUS_REJECTED])
            ->with('success', 'تم رفض الطلب');
    }
}
