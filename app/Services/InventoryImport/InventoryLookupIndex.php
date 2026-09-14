<?php

namespace App\Services\InventoryImport;

use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicineAlias;
use App\Services\Ai\MedicineResolver;
use App\Support\MedicineNameMapper;

/**
 * فهرس بحث في الذاكرة لعملية استيراد واحدة.
 *
 * السبب: المطابقة صف-بصف ضد قاعدة البيانات تنتج N+1 كارثي (5000 صف × عدة
 * استعلامات). هنا نبني كل الخرائط بثلاث استعلامات فقط، ثم تُحلّ كل الصفوف
 * في الذاكرة.
 *
 * لماذا تحميل جدول medicines كاملاً وليس whereIn بأسماء الملف:
 *  - الجدول محدود الحجم بطبيعته (كتالوج أدوية)، وحمله استعلام واحد أرخص
 *    من آلاف الفحوص.
 *  - يزيل اختلاف حساسية حالة الأحرف بين MySQL (غير حساس) وSQLite (حساس)،
 *    فتصبح النتيجة واحدة في الإنتاج والاختبار.
 *  - لا يعتمد على وجود index على trade_name_ar (وهو غير موجود حالياً).
 *
 * خرائط إضافية: كتالوج وزارة الصحة (مرجع) + مرادفات هذه الصيدلية.
 */
final class InventoryLookupIndex
{
    /** @var array<string, array{id:int, trade_name:string, trade_name_ar:?string, active_ingredient:?string}> */
    private array $medicineByEn = [];

    /** @var array<string, array{id:int, trade_name:string, trade_name_ar:?string, active_ingredient:?string}> */
    private array $medicineByAr = [];

    /** @var array<string, array{id:int, trade_name:string, generic_name:?string, manufacturer:?string, official_price:?float}> */
    private array $mohByEn = [];

    /** @var array<int, array{id:int, trade_name:string, generic_name:?string, manufacturer:?string, official_price:?float}> */
    private array $mohByProductId = [];

    /** @var array<int, array{id:int, trade_name:string, generic_name:?string, manufacturer:?string, official_price:?float}> */
    private array $mohByDrugId = [];

    /** @var array<string, int> مرادفات الصيدلية: اسم مطبّع => medicine_id */
    private array $aliasMap = [];

    private function __construct() {}

    /**
     * بناء الفهرس لهذه الصيدلية — 3 استعلامات ثابتة بغض النظر عن عدد الصفوف.
     */
    public static function build(Pharmacy $pharmacy): self
    {
        $index = new self;

        $index->loadMedicines();
        $index->loadMohCatalog();
        $index->loadAliases($pharmacy);

        return $index;
    }

    /**
     * البحث بالاسم الإنجليزي المطبّع.
     *
     * @return array{id:int, trade_name:string, trade_name_ar:?string, active_ingredient:?string}|null
     */
    public function findByEnglishName(?string $name): ?array
    {
        $key = self::normalizeEn($name);

        return $key === '' ? null : ($this->medicineByEn[$key] ?? null);
    }

    /**
     * البحث بالاسم العربي المطبّع.
     *
     * @return array{id:int, trade_name:string, trade_name_ar:?string, active_ingredient:?string}|null
     */
    public function findByArabicName(?string $name): ?array
    {
        $key = self::normalizeAr($name);

        return $key === '' ? null : ($this->medicineByAr[$key] ?? null);
    }

    /**
     * البحث في مرادفات هذه الصيدلية (قرارات سابقة أكّدها الصيدلي).
     *
     * @return array{id:int, trade_name:string, trade_name_ar:?string, active_ingredient:?string}|null
     */
    public function findByAlias(?string $name): ?array
    {
        $key = self::normalizeEn($name);

        if ($key === '') {
            return null;
        }

        $medicineId = $this->aliasMap[$key] ?? null;

        if ($medicineId === null) {
            return null;
        }

        foreach ($this->medicineByEn as $entry) {
            if ($entry['id'] === $medicineId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * البحث في كتالوج وزارة الصحة بالاسم الإنجليزي.
     *
     * @return array{id:int, trade_name:string, generic_name:?string, manufacturer:?string, official_price:?float}|null
     */
    public function findMohByEnglishName(?string $name): ?array
    {
        $key = self::normalizeEn($name);

        return $key === '' ? null : ($this->mohByEn[$key] ?? null);
    }

    /** بيانات دواء بالمعرّف — للتحقق server-side من قرارات الواجهة. */
    public function medicineById(int $medicineId): ?array
    {
        foreach ($this->medicineByEn as $entry) {
            if ($entry['id'] === $medicineId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * عنصر كتالوج الوزارة بمعرّف المنتج أو الدواء — يُستخدم لترجمة نتائج
     * الـ mapping إلى صفوف حقيقية بلا استعلامات إضافية.
     *
     * @return array{id:int, trade_name:string, generic_name:?string, manufacturer:?string, official_price:?float}|null
     */
    public function findMohByIds(?int $productId, ?int $drugId): ?array
    {
        if ($productId !== null && isset($this->mohByProductId[$productId])) {
            return $this->mohByProductId[$productId];
        }

        if ($drugId !== null && isset($this->mohByDrugId[$drugId])) {
            return $this->mohByDrugId[$drugId];
        }

        return null;
    }

    public static function normalizeEn(?string $name): string
    {
        if ($name === null) {
            return '';
        }

        return mb_strtolower(MedicineNameMapper::clean($name));
    }

    public static function normalizeAr(?string $name): string
    {
        if ($name === null) {
            return '';
        }

        return MedicineResolver::normalizeArabic($name);
    }

    private function loadMedicines(): void
    {
        Medicine::query()
            ->select(['id', 'trade_name', 'trade_name_ar', 'active_ingredient'])
            ->orderBy('id')
            ->chunkById(2000, function ($chunk): void {
                foreach ($chunk as $medicine) {
                    $entry = [
                        'id' => (int) $medicine->id,
                        'trade_name' => (string) $medicine->trade_name,
                        'trade_name_ar' => $medicine->trade_name_ar,
                        'active_ingredient' => $medicine->active_ingredient,
                    ];

                    // ترتيب تصاعدي بالـ id → أصغر id يفوز، نفس دلالة first()
                    $en = self::normalizeEn($medicine->trade_name);
                    if ($en !== '' && ! isset($this->medicineByEn[$en])) {
                        $this->medicineByEn[$en] = $entry;
                    }

                    $ar = self::normalizeAr($medicine->trade_name_ar);
                    if ($ar !== '' && ! isset($this->medicineByAr[$ar])) {
                        $this->medicineByAr[$ar] = $entry;
                    }
                }
            });
    }

    private function loadMohCatalog(): void
    {
        MohMedicine::query()
            ->select(['id', 'trade_name', 'generic_name', 'manufacturer', 'official_price', 'moh_product_id', 'moh_drug_id'])
            ->orderBy('id')
            ->chunkById(2000, function ($chunk): void {
                foreach ($chunk as $moh) {
                    $entry = [
                        'id' => (int) $moh->id,
                        'trade_name' => (string) $moh->trade_name,
                        'generic_name' => $moh->generic_name,
                        'manufacturer' => $moh->manufacturer,
                        'official_price' => $moh->official_price !== null ? (float) $moh->official_price : null,
                    ];

                    $key = self::normalizeEn($moh->trade_name);

                    if ($key !== '' && ! isset($this->mohByEn[$key])) {
                        $this->mohByEn[$key] = $entry;
                    }

                    if ($moh->moh_product_id !== null && ! isset($this->mohByProductId[(int) $moh->moh_product_id])) {
                        $this->mohByProductId[(int) $moh->moh_product_id] = $entry;
                    }

                    if ($moh->moh_drug_id !== null && ! isset($this->mohByDrugId[(int) $moh->moh_drug_id])) {
                        $this->mohByDrugId[(int) $moh->moh_drug_id] = $entry;
                    }
                }
            });
    }

    private function loadAliases(Pharmacy $pharmacy): void
    {
        PharmacyMedicineAlias::query()
            ->where('pharmacy_id', $pharmacy->id)
            ->get(['alias', 'medicine_id'])
            ->each(function (PharmacyMedicineAlias $alias): void {
                $key = self::normalizeEn($alias->alias);

                if ($key !== '' && ! isset($this->aliasMap[$key])) {
                    $this->aliasMap[$key] = (int) $alias->medicine_id;
                }
            });
    }
}
