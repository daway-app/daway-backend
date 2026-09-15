<?php

namespace App\Services\Enrichment\Providers;

use App\Models\Medicine;
use App\Services\Enrichment\NameNormalizer;
use Illuminate\Support\Facades\Log;

/**
 * (أولوية 1) مزوّد البيانات المحلية — مصدره كل ما هو موجود SA" DOES:
 * - mapping مسجّل من كتال sided (chatbot_medicines.json) عبر MedicineResolver
 * - الأسماء العربية: medicines.trade_name_ar (البيانات الموجودة أصلاً)
 * - صور المزوّد المحلي: medicines.image (بكين البيانات الموجودة أصلاً)
 * - **لا يوفر باركود خارجيًا** ولا صور خارجية — وهذا توثيق المحدّد.
 * لا ترجمة آلية: العربية من مصادر optained فقط.
 */
final class LocalProvider implements MedicineDataProvider
{
    public function __construct(
        private readonly \App\Services\Ai\MedicineResolver $resolver,
    ) {}

    public function getName(): string
    {
        return 'local';
    }

    public function isFree(): bool
    {
        return true;
    }

    public function isEnabled(): bool
    {
        return (bool) config('enrichment.local.enabled', true);
    }

    public function searchByBarcode(string $normalizedBarcode): ?ProviderResult
    {
        // مزوّد محلي — لا مصدر باركود (يتطلب provider خارجي).
        return null;
    }

    public function searchByMedicine(MedicineQuery $query): ?ProviderResult
    {
        $nameEn = trim((string) $query->nameEn);
        $nameAr = trim((string) $query->nameAr);
        if ($nameEn === '' && $nameAr === '') {
            return null;
        }

        // 1) mapping المحلي — الاسم العربي المُعاد من الملف (مصدر قديم جاهز).
        $hits = $nameEn !== '' ? $this->resolver->lookupMapping($nameEn, 5) : [];
        foreach ($hits as $hit) {
            if (empty($hit['ar']) && empty($hit['en'])) {
                continue;
            }

            return ProviderResult::match(
                nameEn: (string) ($hit['en'] ?? $nameEn),
                nameAr: (string) ($hit['ar'] ?? ''),
                barcodes: [],
                imageUrl: null,
                manufacturer: null,
                strength: null,
                dosageForm: null,
                packSize: null,
                provider: $this->getName(),
                sourceReference: 'local_mapping:'.$nameEn,
                payload: $hit,
            );
        }

        // 2) الكتالوج المحلي (medicines): مطابقة اسم. الأسماء العربية من بيانات المنظّم الحالي.
        $local = Medicine::query()
            ->when($nameEn !== '', fn ($q) => $q->orWhere('trade_name', 'like', $nameEn.'%'))
            ->when($nameAr !== '', fn ($q) => $q->orWhere('trade_name_ar', 'like', '%'.$nameAr.'%'))
            ->orderBy('id')
            ->first();

        if ($local !== null) {
            return ProviderResult::match(
                nameEn: (string) $local->trade_name,
                nameAr: (string) ($local->trade_name_ar ?: ''),
                barcodes: [],
                imageUrl: ($local->image !== null && $local->image !== '' ? $local->image : null),
                manufacturer: null,
                strength: null,
                dosageForm: null,
                packSize: null,
                provider: $this->getName(),
                sourceReference: 'local_medicine:'.$local->id,
                payload: ['local_medicine_id' => $local->id],
            );
        }

        Log::info('Enrichment LocalProvider: لا نتيجة');

        return null;
    }
}
