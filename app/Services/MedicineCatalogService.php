<?php

namespace App\Services;

use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\PharmacyMedicine;

/**
 * H-8: مصدر منطق الكتالوج العام الموحد — resolve-or-create + الإثراء.
 *
 * تاريخياً كانت هذه القواعد منسوخة 4 مرات (API store/storeByName، web store،
 * SyncService medicine.store/update) بفروقات سلوكية بين الأسطح. الخدمة توحّد
 * القواعد عبر معاملات صريحة تحفظ كل سلوك سطح كما هو (بدون تغيير عقود):
 *
 * D-WEB1: الويب (الإدخال اليدوي) لا يبحث في كتالوج وزارة الصحة ولا بالاسم العربي
 *         عند الحل — يُحفظ عبر $lookupAr/$lookupMoh.
 * D-WEB2: الويب لا يُثري المادة الفعالة لدواء موجود مسبقاً — يُحفظ عبر $fillIngredient.
 * D-FCM:  مسار الويب كان يُنشئ إشعار نقص المخزون بدون FCM؛ بعد التوحيد يستخدم
 *         LowStockNotifier (بالإرسال) — التوحيد الوحيد المعتمد عمداً.
 *
 * قواعد التحقق (max/regex) تبقى في الـ controllers/requests — لا تدخل هنا.
 */
final class MedicineCatalogService
{
    /**
     * الحل بالاسم: إنجليزي → عربي (اختياري) → كتالوج الوزارة (اختياري).
     * يعيد null إذا لم يوجد شيء (على المتصل القرار: إنشاء أو فشل).
     */
    public function resolveByName(
        string $enName,
        ?string $arName = null,
        bool $lookupAr = true,
        bool $lookupMoh = true,
    ): ?Medicine {
        $medicine = Medicine::where('trade_name', $enName)->first();

        if (! $medicine && $lookupAr && $arName) {
            $medicine = Medicine::where('trade_name_ar', $arName)->first();
        }

        if (! $medicine && $lookupMoh) {
            $medicine = $this->copyFromMohByName($enName);
        }

        return $medicine;
    }

    /**
     * نسخ عنصر من كتالوج الوزارة إلى الكتالوج العام عند الحاجة (نفس mapping بالأسطح).
     * يعيد null إذا لم يوجد بالكتالوج الوزاري.
     */
    public function copyFromMohByName(string $enName): ?Medicine
    {
        $moh = MohMedicine::where('trade_name', $enName)->first();

        return $moh ? $this->findOrCreateFromMoh($moh) : null;
    }

    /**
     * find-or-create من صف وزارة الصحة المعروف (branch الـ moh_medicine_id في store).
     */
    public function findOrCreateFromMoh(MohMedicine $moh): Medicine
    {
        return Medicine::where('trade_name', $moh->trade_name)->first()
            ?? Medicine::create([
                'trade_name' => $moh->trade_name,
                'active_ingredient' => $moh->generic_name ?? $moh->trade_name,
                'description' => $moh->manufacturer ?? $moh->company,
            ]);
    }

    /**
     * إنشاء دواء جديد بالأسماء والمادة الفعالة.
     * $moh اختياري: عند تمريره يُستمد الوصف من الشركة/المصنّع (سلوك storeByName وsync).
     */
    public function createFromNames(
        string $enName,
        ?string $arName,
        string $activeIngredient,
        ?MohMedicine $moh = null,
    ): Medicine {
        return Medicine::create([
            'trade_name' => $enName,
            'trade_name_ar' => $arName,
            'active_ingredient' => $activeIngredient,
            'description' => $moh?->manufacturer ?? $moh?->company,
        ]);
    }

    /**
     * إثراء دواء موجود: يملأ الفراغات فقط — لا يستبدل قيمة موجودة أبداً.
     * $fillIngredient = false يحفظ سلوك الويب (D-WEB2: لا يملأ المادة الفعالة).
     */
    public function fillMissingAttributes(
        Medicine $medicine,
        ?string $arName = null,
        ?string $activeIngredient = null,
        bool $fillIngredient = true,
    ): void {
        if ($fillIngredient && $activeIngredient !== null
            && $activeIngredient !== '' && empty($medicine->active_ingredient)) {
            $medicine->active_ingredient = $activeIngredient;
            $medicine->save();
        }

        if ($arName !== null && $arName !== '' && empty($medicine->trade_name_ar)) {
            $medicine->trade_name_ar = $arName;
            $medicine->save();
        }
    }

    /**
     * هل الدواء حكر على صيدلية واحدة؟ (بوابة إثراء الكتالوج المشترك)
     */
    public function isSoleUser(int $medicineId, int $exceptPharmacyId): bool
    {
        return ! PharmacyMedicine::where('medicine_id', $medicineId)
            ->where('pharmacy_id', '!=', $exceptPharmacyId)
            ->exists();
    }

    /**
     * إثراء بكتالوج مشترك بشرط الحكر (سلوك API update + sync medicine.update):
     * كل حقل غير فارغ من $fields يُكتب فوق الكتالوج فقط إذا كانت الصيدلية
     * المستخدِمة الوحيدة — وإلا يتجاهل. حفظ واحد عند وجود تغيير.
     */
    public function applySoleOwnerEdits(Medicine $medicine, int $pharmacyId, array $fields): void
    {
        if (! $this->isSoleUser($medicine->id, $pharmacyId)) {
            return;
        }

        $dirty = false;

        foreach (['trade_name', 'trade_name_ar', 'active_ingredient'] as $field) {
            if (! empty($fields[$field])) {
                $medicine->{$field} = trim((string) $fields[$field]);
                $dirty = true;
            }
        }

        if ($dirty) {
            $medicine->save();
        }
    }
}
