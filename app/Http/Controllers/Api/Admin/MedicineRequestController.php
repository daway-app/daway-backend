<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\MohMedicine;
use App\Models\PharmacyMedicine;
use App\Models\Subcategory;
use App\Support\CategoryCatalogCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * لوحة الأدمن — طلبات الأدوية (JSON).
 *
 * منطق الاعتماد منقول حرفياً من `web/Admin/MedicineRequestController@approve`
 * بما فيه القفل (`lockForUpdate`) والمعاملة الواحدة — بدونها يقع اعتماد
 * مزدوج متزامن يُنشئ روابط مكررة.
 */
class MedicineRequestController extends Controller
{
    /** GET /api/admin/medicine-requests */
    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', MedicineRequest::STATUS_PENDING);
        if (! in_array($status, MedicineRequest::STATUSES, true)) {
            $status = MedicineRequest::STATUS_PENDING;
        }

        $q = trim((string) $request->query('q', ''));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        $requests = MedicineRequest::query()
            ->with(['pharmacy:id,pharmacy_name', 'category:id,name_ar', 'subcategory:id,name_ar'])
            ->where('status', $status)
            ->when($q !== '' && mb_strlen($q) >= 2, function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('trade_name', 'like', "%{$q}%")
                        ->orWhere('generic_name', 'like', "%{$q}%")
                        ->orWhere('active_ingredient', 'like', "%{$q}%");
                });
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'requests' => $requests->items(),
                'pagination' => [
                    'current_page' => $requests->currentPage(),
                    'last_page' => $requests->lastPage(),
                    'per_page' => $requests->perPage(),
                    'total' => $requests->total(),
                ],
                'filters' => ['q' => $q, 'status' => $status],
                'stats' => [
                    'pending' => MedicineRequest::where('status', MedicineRequest::STATUS_PENDING)->count(),
                    'approved' => MedicineRequest::where('status', MedicineRequest::STATUS_APPROVED)->count(),
                    'rejected' => MedicineRequest::where('status', MedicineRequest::STATUS_REJECTED)->count(),
                ],
            ],
        ]);
    }

    /** GET /api/admin/medicine-requests/{medicineRequest} */
    public function show(MedicineRequest $medicineRequest): JsonResponse
    {
        $medicineRequest->load([
            'pharmacy:id,pharmacy_name',
            'category:id,name_ar',
            'subcategory:id,name_ar',
            'approvedMohMedicine',
            'approvedMedicine',
        ]);

        return response()->json([
            'success' => true,
            'data' => ['request' => $medicineRequest],
        ]);
    }

    /** POST /api/admin/medicine-requests/{medicineRequest}/approve */
    public function approve(Request $request, MedicineRequest $medicineRequest): JsonResponse
    {
        $data = $request->validate([
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
            $locked = MedicineRequest::where('id', $medicineRequest->id)->lockForUpdate()->first();

            if ($locked->status !== MedicineRequest::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'request' => 'الطلب ليس بحالة بانتظار المراجعة',
                ]);
            }

            $name = trim($data['trade_name'] ?? $locked->trade_name);
            $nameAr = $data['trade_name_ar'] ?? $locked->trade_name_ar;
            $ingredient = $data['active_ingredient'] ?? $locked->active_ingredient;
            $categoryId = $data['category_id'] ?? $locked->category_id;
            $subcategoryId = $data['subcategory_id'] ?? $locked->subcategory_id;

            $category = Category::active()->find($categoryId);
            if (! $category) {
                throw ValidationException::withMessages(['category_id' => 'القسم غير صالح']);
            }
            if ($subcategoryId) {
                $sub = Subcategory::where('category_id', $category->id)->find($subcategoryId);
                if (! $sub) {
                    throw ValidationException::withMessages(['subcategory_id' => 'القسم الفرعي لا ينتمي للقسم']);
                }
            }

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

                $approvedMedId = $local->id;
                $inventoryMedId = $local->id;
            }

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

        return response()->json([
            'success' => true,
            'message' => 'تمت الموافقة على الطلب بنجاح.',
            'data' => ['status' => $result->status],
        ]);
    }

    /** POST /api/admin/medicine-requests/{medicineRequest}/reject */
    public function reject(Request $request, MedicineRequest $medicineRequest): JsonResponse
    {
        $data = $request->validate([
            'admin_notes' => ['required', 'string', 'max:2000'],
        ]);

        if ($medicineRequest->status !== MedicineRequest::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'الطلب ليس بحالة بانتظار المراجعة.',
            ], 422);
        }

        $medicineRequest->update([
            'status' => MedicineRequest::STATUS_REJECTED,
            'admin_notes' => $data['admin_notes'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم رفض الطلب.',
        ]);
    }
}
