<?php

namespace App\Services\Enrichment\Providers;

/**
 * (أولوية 3) RxNorm / RxNav — NLM، مجاني بلا key، بيانات Public Domain.
 * يوفّر: الاسم الرسمي، الأسماء المرادفة، الأسماء المرربط (ingredients)،
 * shkata من الRxCUI. لا expected ركُم تجارياً (الباركود غير موجود هنا).
 */
final class RxNormProvider extends FreeHttpProvider
{
    public function getName(): string
    {
        return 'rxnorm';
    }

    public function isFree(): bool
    {
        return true;
    }

    public function isEnabled(): bool
    {
        return (bool) config('enrichment.rxnorm.enabled', true);
    }

    public function searchByBarcode(string $normalizedBarcode): ?ProviderResult
    {
        return null; // لا باركود في RxNorm
    }

    public function searchByMedicine(MedicineQuery $query): ?ProviderResult
    {
        $nameEn = trim($query->nameEn);
        if ($nameEn === '') {
            return null;
        }

        $data = $this->request(
            url: 'https://rxnav.nlm.nih.gov/REST/drugs.json',
            params: ['name' => $nameEn],
        );

        if (empty($data['drugGroup']['conceptGroup'])) {
            return null;
        }

        foreach ($data['drugGroup']['conceptGroup'] as $group) {
            foreach ((array) ($group['conceptProperties'] ?? []) as $concept) {
                $rxcui = $concept['rxcui'] ?? null;
                if ($rxcui === null) {
                    continue;
                }

                return ProviderResult::match(
                    nameEn: $concept['name'] ?? $nameEn,
                    nameAr: null,
                    barcodes: [],
                    imageUrl: null,
                    manufacturer: null,
                    strength: $concept['strength'] ?? null,
                    dosageForm: $concept['doseFormName'] ?? null,
                    packSize: null,
                    provider: $this->getName(),
                    sourceReference: 'RXCUI:'.$rxcui,
                    payload: $concept,
                );
            }
        }

        return null;
    }

    public function toResult(array $payload, string $sourceRef): ?ProviderResult
    {
        $concept = [
            'rxcui' => $payload['rxcui'] ?? null,
            'name' => $payload['name'] ?? null,
            'strength' => $payload['strength'] ?? null,
            'doseFormName' => $payload['doseFormName'] ?? null,
        ];

        return ProviderResult::match(
            nameEn: $concept['name'],
            nameAr: null,
            barcodes: [],
            imageUrl: null,
            manufacturer: null,
            strength: $concept['strength'],
            dosageForm: $concept['doseFormName'],
            packSize: null,
            provider: 'rxnorm',
            sourceReference: 'RXCUI:'.$concept['rxcui'],
            payload: $concept,
        );
    }
}
