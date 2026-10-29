<?php

namespace App\Services\MedicineRequest;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\MohMedicine;
use App\Models\PharmacyMedicine;
use App\Models\Subcategory;
use App\Support\CategoryCatalogCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * اعتماد طلب دواء — المنطق المشترك بين الويب والـAPI.
 *
 * منقول حرفياً من `web\Admin\MedicineRequestController::approve` و
 * `Api\Admin\MedicineRequestController::approve` (الكانا متطابقين حرفياً
 * عدا طبقة النقل: redirect مقابل JSON). الاستخراج هنا **لا يغيّر أي سلوك
 * خارجي مرئي**: نفس حدود المعاملة، نفس الأقفال، نفس الاستثناءات، نفس
 * الآثار الجانبية على المخزون والكتالوج والكاش.
 *
 * 🔴 القاعدة الحاكمة (موثّقة في الذاكرة):
 *    كتالوج الوزارة يُربط بمفاتيح `moh_product_id` / `moh_drug_id` **فقط** —
 *    أبداً `moh_medicines.id` (معرّف داخلي غير مستقر).
 */
class MedicineRequestApprovalService
{
    /**
     * اعتماد طلب بانتظار المراجعة.
     *
     * يرمي `ValidationException` عند: الطلب ليس بانتظار المراجعة (مُعتمد/
     * مرفوض/مكرر) أو القسم/القسم الفرعي غير صالح — وتُصيَّرها طبقة النقل
     * (redirect مع أخطاء في الويب، JSON 422 في الـAPI) كما كان.
     *
     * @param  array<string,mixed>  $data  الحقول المُتحقَّق منها (نفس قواعد كلا المستدعين)
     * @param  int  $reviewerId  معرّف الأدمن المعتمِد
     */
    public function approve(MedicineRequest $medicineRequest, array $data, int $reviewerId): MedicineRequest
    {
        return DB::transaction(function () use ($medicineRequest, $data, $reviewerId) {
            // قفل صف الطلب لمنع الاعتماد المتزامن
            $locked = MedicineRequest::where('id', $medicineRequest->id)->lockForUpdate()->first();

            if ($locked->status !== MedicineRequest::STATUS_PENDING) {
                throw ValidationException::withMessages([
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
                throw ValidationException::withMessages(['category_id' => 'القسم غير صالح']);
            }

            if ($subcategoryId) {
                $sub = Subcategory::where('category_id', $category->id)->find($subcategoryId);
                if (! $sub) {
                    throw ValidationException::withMessages(['subcategory_id' => 'القسم الفرعي لا ينتمي للقسم']);
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
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ])->save();

            CategoryCatalogCache::bump();

            return $locked;
        });
    }
}
