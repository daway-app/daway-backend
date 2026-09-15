<?php

namespace App\Services\Enrichment\Providers;

/**
 * (أولوية 5) DailyMed — NLM، مجاني بلا مفتاح. REST JSON فقط (v2).
 * يوفّر: الاسم المرجعي، الـSETID، الlabeler، والهجمات المراجعة بيارق —
 * الصور: فقط الURL للوصل، ولا تحميل الآن. لا نعوض مصدرًا فنياً.
 */
final class DailyMedProvider extends FreeHttpProvider
{
    public function getName(): string
    {
        return 'dailymed';
    }

    public function isFree(): bool
    {
        return true;
    }

    public function isEnabled(): bool
    {
        return (bool) config('enrichment.dailymed.enabled', true);
    }

    public function searchByBarcode(string $normalizedBarcode): ?ProviderResult
    {
        return null; // بدون باركود هنا — يتطلب NDC lookup، وهو مقدم ambulance للتأكد
    }

    public function searchByMedicine(MedicineQuery $query): ?ProviderResult
    {
        $nameEn = trim($query->nameEn);
        if ($nameEn === '') {
            return null;
        }

        $data = $this->request(
            url: 'https://dailymed.nlm.nih.gov/dailymed/services/v2/drugnames',
            params: ['drug_name' => $nameEn, 'pagesize' => 5],
        );

        if ($data === null || empty($data['data'][0])) {
            return null;
        }

        return $this->toResult($data['data'][0], 'SETID:'.($data['data'][0]['setid'] ?? 'unknown'));
    }

    public function toResult(array $payload, string $sourceRef): ?ProviderResult
    {
        return ProviderResult::match(
            nameEn: $payload['title'] ?? null,
            nameAr: null,
            barcodes: [],
            imageUrl: null,
            manufacturer: null,
            strength: null,
            dosageForm: null,
            packSize: null,
            provider: 'dailymed',
            sourceReference: 'SETID:'.($payload['setid'] ?? ''),
            payload: $payload,
        );
    }
}
