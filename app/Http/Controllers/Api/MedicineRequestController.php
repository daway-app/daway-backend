<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MedicineRequest;
use App\Models\Subcategory;
use App\Services\PharmacyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * طلب دواء جديد من صيدلية → مراجعة الإدارة.
 *
 * الصيدلية تملأ بيانات دواء غير موجود بالكتالوج وتختار قسمه،
 * فتتجه الطلب للموافقة. الملكية دائمًا من سياق المصادقة (لا pharmacy_id من العميل).
 */
class MedicineRequestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $data = $request->validate([
            'trade_name' => [
                'required',
                'string',
                'max:150',
                'not_regex:/[\x{0600}-\x{06FF}]/u',
            ],
            'trade_name_ar' => ['nullable', 'string', 'max:150', 'regex:/[\x{0600}-\x{06FF}]/u'],
            'generic_name' => ['nullable', 'string', 'max:150'],
            'manufacturer' => ['nullable', 'string', 'max:150'],
            'active_ingredient' => ['nullable', 'string', 'max:150'],
            'dosage_form' => ['nullable', 'string', 'max:150'],
            'packaging' => ['nullable', 'string', 'max:150'],
            'origin' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:150'],
            'official_price' => ['nullable', 'numeric', 'min:0'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'subcategory_id' => ['nullable', 'integer', 'exists:subcategories,id'],
        ]);

        // القسم يجب أن يكون نشطًا، والقسم الفرعي (إن وُجد) تابع للقسم المختار.
        $category = Category::active()->find($data['category_id']);
        if (! $category) {
            throw ValidationException::withMessages([
                'category_id' => 'القسم غير صالح أو غير نشط',
            ])->status(422);
        }

        if (! empty($data['subcategory_id'])) {
            $subcategory = Subcategory::where('category_id', $category->id)->find($data['subcategory_id']);
            if (! $subcategory) {
                throw ValidationException::withMessages([
                    'subcategory_id' => 'القسم الفرعي لا ينتمي إلى القسم المختار',
                ])->status(422);
            }
        }

        $requestModel = MedicineRequest::create([
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
            'origin' => $data['origin'] ?? null,
            'company' => $data['company'] ?? null,
            'official_price' => $data['official_price'] ?? null,
            'barcode' => $data['barcode'] ?? null,
            'category_id' => $category->id,
            'subcategory_id' => $data['subcategory_id'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال طلب الدواء للمراجعة',
            'data' => $this->payload($requestModel),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $status = $request->query('status', MedicineRequest::STATUS_PENDING);
        if (! in_array($status, MedicineRequest::STATUSES, true)) {
            $status = MedicineRequest::STATUS_PENDING;
        }

        $items = MedicineRequest::where('pharmacy_id', $pharmacy->id)
            ->where('status', $status)
            ->latest('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الطلبات بنجاح',
            'data' => $items->map(fn (MedicineRequest $r) => $this->payload($r))->values(),
        ]);
    }

    private function payload(MedicineRequest $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'trade_name' => $r->trade_name,
            'trade_name_ar' => $r->trade_name_ar,
            'generic_name' => $r->generic_name,
            'manufacturer' => $r->manufacturer,
            'active_ingredient' => $r->active_ingredient,
            'dosage_form' => $r->dosage_form,
            'packaging' => $r->packaging,
            'origin' => $r->origin,
            'company' => $r->company,
            'official_price' => $r->official_price !== null ? (float) $r->official_price : null,
            'barcode' => $r->barcode,
            'category_id' => $r->category_id,
            'subcategory_id' => $r->subcategory_id,
            'admin_notes' => $r->admin_notes,
            'approved_moh_medicine_id' => $r->approved_moh_medicine_id,
            'approved_medicine_id' => $r->approved_medicine_id,
            'created_at' => $r->created_at?->toDateTimeString(),
            'reviewed_at' => $r->reviewed_at?->toDateTimeString(),
        ];
    }
}
