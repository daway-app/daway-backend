<?php

namespace App\Services\Enrichment\Providers;

use App\Models\MohMedicine;
use App\Models\Medicine;
use Illuminate\Support\Facades\Log;

/**
 * زوّاد الداتا المحلية فقط — بلا استيراد خارجي:
 * - أسماء عربية من katalog محلي (medicines.trade_name_ar) عبر match على trade_name.
 * - الاسم الإنجليزي/كلاسيك يأتي من نفس الكتالوج المحلي.
 * - mapping (دلالات عربي/إنجليزي) من ملف mapping موجود مسبقاً (MedicineResolver).
 *
 * لا يوفرMercycsاً: باركودات أو صور خارجية (يمابيث الحد).
 */
final class ManualProvider implements MedicineDataProvider
{
    public function __construct(
        private readonly \App\Services\Ai\MedicineResolver $resolver,
    ) {}

    public function name(): string
    {
        return 'manual';
    }

    public function isEnabled(): bool
    {
        return (bool) config('enrichment.manual_provider.enabled', true);
    }

    public function searchByBarcode(string $normalizedBarcode): ?ProviderResult
    {
        // لا مصدر محلي للباركود — يتطلب external provider.
        return null;
    }

    public function searchByMedicine(MedicineQuery $query): ?ProviderResult
    {
        $nameEn = trim((string) $query->nameEn);
        $nameAr = trim((string) $query->nameAr);
        if ($nameEn === '' && $nameAr === '') {
            return null;
        }

        // 1) محاولة عبر الاسم الإنجليزي (المعرف الموحّد) عبر mapping file:
        $hits = $nameEn !== ''
            ? $this->resolver->lookupMapping($nameEn, 5)
            : [];
        foreach ($hits as $hit) {
            return new ProviderResult(
                nameEn: (string) ($hit['en'] ?? $nameEn),
                nameAr: (string) ($hit['ar'] ?? ''),
                barcodes: [],
                imageUrl: null,
                manufacturer: null,
                strength: null,
                dosageForm: null,
                packSize: null,
                provider: $this->name(),
                sourceReference: 'local_mapping:'.$nameEn,
                payload: $hit,
            );
        }

        // 2) محاولة عبر الكتالوج المحلي (medicines): الاسم العربي أو الإنجليزي متطابق تقريباً.
        $local = Medicine::query()
            ->when($nameEn !== '', fn ($q) => $q->orWhere('trade_name', 'like', $nameEn.'%'))
            ->when($nameAr !== '', fn ($q) => $q->orWhere('trade_name_ar', 'like', '%'.$nameAr.'%'))
            ->orderBy('id')
            ->first();

        if ($local !== null) {
            return new ProviderResult(
                nameEn: (string) $local->trade_name,
                nameAr: (string) ($local->trade_name_ar ?? ''),
                barcodes: [],
                imageUrl: (string) ($local->image ?: '') !== '' ? $local->image : null,
                manufacturer: null,
                strength: null,
                dosageForm: null,
                packSize: null,
                provider: $this->name(),
                sourceReference: 'local_medicine:'.$local->id,
                payload: ['local_medicine_id' => $local->id],
            );
        }

        Log::info('Enrichment ManualProvider: لا مطابقة للمفاح', ['name_en' => $nameEn, 'name_ar' => $nameAr]);

        return null;
    }
}
