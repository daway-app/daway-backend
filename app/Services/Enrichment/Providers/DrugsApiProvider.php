<?php

namespace App\Services\Enrichment\Providers;

use App\Services\Enrichment\BarcodeNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Provider رسمي (DrugsAPI) — معطّل افتراضياً حتى توفر الـcredentials.
 *
 * قواعد انصراف هيدر المؤست GIT: بدون أي endpoint مختلق — المسارات
 * الافتراضية خالية وتُحمى بالفشل الصامت حتى توفر الـpaths الصحيحة من
 * documentation المزوّد. التكامل جاهز وليس معبأً.
 */
final class DrugsApiProvider implements MedicineDataProvider
{
    public function getName(): string
    {
        return 'drugs_api';
    }

    public function isFree(): bool
    {
        return false; // paid provider — مقفول بـ cost guard (ENRICHMENT_PAID_PROVIDERS=false)
    }

    public function isEnabled(): bool
    {
        $cfg = config('enrichment.drugs_api');

        // enabled أبداً مسمى false حتى إد إتم الـcredentials أياً ما كانت.
        return (bool) ($cfg['enabled'] ?? false)
            && ! empty($cfg['base_url'])
            && ! empty($cfg['search_path']);
    }

    public function searchByBarcode(string $normalizedBarcode): ?ProviderResult
    {
        return $this->call('barcode_path', [
            'barcode' => $normalizedBarcode,
        ], $normalizedBarcode);
    }

    public function searchByMedicine(MedicineQuery $query): ?ProviderResult
    {
        $nameEn = trim($query->nameEn);
        if ($nameEn === '') {
            return null;
        }

        return $this->call('search_path', ['query' => $nameEn, 'limit' => 5], $nameEn);
    }

    /**
     * فحص مكتمل مع: HTTP timeout، محاولة إعادة محدودة، error handling،
     * وDTO typed؛ لا مرج النتيجات غير الموثقة داخل الكتالوج.
     */
    private function call(string $pathKey, array $params, string $term): ?ProviderResult
    {
        $cfg = config('enrichment.drugs_api');
        $path = (string) ($cfg[$pathKey] ?? '');
        if ($path === '') {
            return null; // مسار غير مؤكد — لا افتراض
        }
        $url = rtrim((string) $cfg['base_url'], '/').'/'.ltrim($path, '/');
        $retries = max(0, (int) ($cfg['max_retries'] ?? 2));
        $timeout = (int) ($cfg['timeout'] ?? 15);

        try {
            $response = Http::withHeaders($this->authHeaders())
                ->timeout($timeout)
                ->retry($retries, 500)
                ->get($url, $params);

            if (! $response->successful()) {
                Log::warning('DrugsApiProvider غير ناجح', ['url' => $url, 'status' => $response->status()]);

                return null;
            }

            return $this->toResult($response->json() ?? [], $term);
        } catch (ConnectionException $e) {
            Log::error('DrugsApiProvider ارتباط فاشل', ['url' => $url, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('DrugsApiProvider استثناء', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /** map الرد المرن إلى DTO المحدد — من المصدر المقتبس لا ایظن */
    private function toResult(array $body, string $sourceRef): ?ProviderResult
    {
        $item = $body['results'][0] ?? $body['data'][0] ?? $body['product'] ?? $body;

        if (! is_array($item)) {
            return null;
        }

        $barcodeRaw = (string) ($item['barcode'] ?? '');
        $normalized = BarcodeNormalizer::normalize($barcodeRaw);

        $imageUrl = $item['image_url'] ?? null;
        if (is_array($item['image'] ?? null)) {
            $imageUrl = $item['image']['url'] ?? null;
        }

        return new ProviderResult(
            nameEn: isset($item['name']) ? (string) $item['name'] : null,
            nameAr: isset($item['name_ar']) ? (string) $item['name_ar'] : null,
            barcodes: $normalized
                ? [['value' => $normalized['barcode'], 'type' => $normalized['type'], 'raw' => $normalized['raw']]]
                : [],
            imageUrl: $imageUrl !== null && $imageUrl !== '' ? (string) $imageUrl : null,
            manufacturer: isset($item['manufacturer']) ? (string) $item['manufacturer'] : null,
            strength: isset($item['strength']) ? (string) $item['strength'] : null,
            dosageForm: isset($item['dosage_form']) ? (string) $item['dosage_form'] : null,
            packSize: isset($item['pack_size']) ? (string) $item['pack_size'] : null,
            provider: 'drugs_api',
            sourceReference: $sourceRef,
            payload: $item,
        );
    }
}
