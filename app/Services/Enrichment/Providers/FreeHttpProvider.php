<?php

namespace App\Services\Enrichment\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;

/**
 * قاعدة مشتركة لكل الproviders المجانية — هدّئة وواضحة: timeout، retry محدود،
 * HTTP error بلا انهيار، نص/رد مفسّد باست غيائية، وcards ناميّة reporting.
 */
abstract class FreeHttpProvider implements MedicineDataProvider
{
    /** config key داخل config('enrichment.<key>') — يحدد base_url/timeout/... */
    protected function configKey(): string
    {
        return static::class;
    }

    /** عملية HTTP مشتركة: بلا انهيار على أخطاء؛ returns null أمنياً. */
    protected function request(string $url, array $params = [], array $headers = []): ?array
    {
        $cfg = (array) config('enrichment.'.$this->configKey());
        $timeout = (int) ($cfg['timeout'] ?? 10);
        $retries = max(0, (int) ($cfg['retries'] ?? 1));

        try {
            /** @var PendingRequest $http */
            $http = Http::withHeaders($headers + ['Accept' => 'application/json'])
                ->timeout($timeout)
                ->retry($retries, 400);

            if (! empty($cfg['key'])) {
                $http = $http->withToken((string) $cfg['key']);
            }

            $response = $http->get($url, $params);

            if (! $response->successful()) {
                Log::channel(env('LOG_CHANNEL', 'stack'))
                    ->info(static::class.' غير ناجح: '.$response->status());

                return null;
            }

            return $response->json() ?? [];
        } catch (ConnectionException $e) {
            Log::info(static::class.' timeout: '.$e->getMessage());

            return null; // لا انهيار
        } catch (\Throwable $e) {
            Log::info(static::class.' استثناء: '.$e->getMessage());

            return null;
        }
    }

    /**$json-> DTO محلي: parsing لخزن النتيجة. */
    abstract public function toResult(array $payload, string $sourceRef): ?ProviderResult;
}
