<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * عميل خدمة تحليل نية رسالة المريض (daway-ai-api).
 *
 * يعكس OcrClient بالضبط في الشكل والسلوك: نفس نمط البناء (baseUrl/timeout/key)،
 * ونفس سياسة المحاولتين، ونفس الـ fallback الآمن عند أي فشل.
 *
 * نطاق مسؤوليته ضيّق عن قصد: يرسل رسالة نصية ويرجع JSON أولياً فقط.
 * لا يرى قاعدة البيانات، ولا المخزون، ولا الأسعار، ولا يقرّر ترتيباً.
 * مخرجات هذا العميل **غير موثوقة** — تُفحص في MedicineIntentService قبل أي استخدام.
 */
final class MedicineIntentClient
{
    public function __construct(
        private readonly ?string $baseUrl,
        private readonly int $timeout = 8,
        private readonly ?string $key = null,
    ) {}

    /**
     * @return array{intent:mixed, drug_name:mixed, confidence:mixed, source:string}
     */
    public function analyze(string $message): array
    {
        if (empty($this->baseUrl)) {
            return $this->failure();
        }

        // محاولتان: الخدمة على خطة مجانية أحياناً ترجع 503 لحظياً
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $startedAt = microtime(true);

            try {
                $request = Http::timeout($this->timeout)
                    ->acceptJson()
                    ->when($this->key, fn ($r) => $r->withToken($this->key))
                    ->post(rtrim($this->baseUrl, '/').'/ai/assistant', [
                        'message' => $message,
                    ]);
            } catch (\Throwable $e) {
                Log::warning('AI intent call failed', [
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);

                continue;
            }

            Log::info('ai_intent', [
                'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'status' => $request->status(),
                'attempt' => $attempt,
            ]);

            if ($request->successful()) {
                $data = $request->json();

                if (! is_array($data)) {
                    return $this->failure();
                }

                return [
                    'intent' => $data['intent'] ?? null,
                    'drug_name' => $data['drug_name'] ?? null,
                    'confidence' => $data['confidence'] ?? null,
                    'source' => 'llm',
                ];
            }

            if ($attempt < 2) {
                sleep(2);
            }
        }

        return $this->failure();
    }

    private function failure(): array
    {
        return [
            'intent' => null,
            'drug_name' => null,
            'confidence' => null,
            'source' => 'fallback',
        ];
    }
}
