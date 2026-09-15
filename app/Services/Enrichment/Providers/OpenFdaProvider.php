<?php

namespace App\Services\Enrichment\Providers;

/**
 * (أولوية 4) openFDA — مجاني (مفتاح اختياري مجاني). لا تحويل NDC → EAN
 * بالتخمين أبداً؛ الباركود يُقبل فقط إن قدم المصدر 필ح رمزاً حقيقيًا.
 */
final class OpenFdaProvider extends FreeHttpProvider
{
    public function getName(): string
    {
        return 'openfda';
    }

    public function isFree(): bool
    {
        return true;
    }

    public function isEnabled(): bool
    {
        return (bool) config('enrichment.openfda.enabled', true);
    }

    public function searchByBarcode(string $normalizedBarcode): ?ProviderResult
    {
        // NDC ≠ EAN: نُرجع null للأمان ولا نقوم بالتحويل الظني.
        return null;
    }

    public function searchByMedicine(MedicineQuery $query): ?ProviderResult
    {
        $nameEn = trim($query->nameEn);
        if ($nameEn === '') {
            return null;
        }

        // Drug Label API — search by generic name + brand name (escaped).
        $searchString = sprintf('openfda.generic_name:"%s" OR openfda.brand_name:"%s"',
            self::escape($nameEn), self::escape($nameEn));

        $data = $this->request(
            url: 'https://api.fda.gov/drug/label.json',
            params: ['limit' => 5, 'search' => $searchString],
            headers: $this->apiKeyHeaders(),
        );

        if ($data === null || empty($data['results'][0])) {
            return null;
        }

        return $this->toResult($data['results'][0], $searchString);
    }

    public function toResult(array $payload, string $sourceRef): ?ProviderResult
    {
        $openfda = $payload['openfda'] ?? [];
        $brandName = $openfda['brand_name'][0] ?? null;
        $ingredient = $payload['active_ingredient'][0] ?? null;
        $form = $payload['dosage_form'][0] ?? null;
        $labeler = $openfda['labeler_name'][0] ?? null;
        $manufacturerName = $openfda['manufacturer_name'][0] ?? null;

        return ProviderResult::match(
            nameEn: $brandName ?: $ingredient,
            nameAr: null,
            barcodes: [],
            imageUrl: null,
            manufacturer: $labeler ?: $manufacturerName,
            strength: null,
            dosageForm: $payload['dosage_form'][0] ?? null,
            packSize: null,
            provider: $this->getName(),
            sourceReference: 'FDA_SETID'.(isset($payload['id']) ? (string) $payload['id'] : ''),
            payload: $payload,
        );
    }

    private function apiKeyHeaders(): array
    {
        $key = config('enrichment.openfda.key');
        // openFDA key هو مفتاح مجاني بالمعقدة (مقيم إن وُجد)
        return $key ? ['X-API-KEY' => (string) $key] : [];
    }

    private static function escape(string $input): string
    {
        // openFDA query-language escape؛ يحوي به كسر الاقتباسات أو slurping
        return str_replace(['"', '\\'], '', $input);
    }
}
