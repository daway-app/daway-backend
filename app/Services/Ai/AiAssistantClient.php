<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * عميل مساعد الذكاء الاصطناعي (daway-ai-api).
 * يستقبل رسالة المستخدم ويرجع تحليلاً منظماً: نية الطلب + اسم الدواء + الأعراض.
 * عند فشل الخدمة الخارجية يرجع fallback آمن بدل الاستثناء.
 */
final class AiAssistantClient
{
    public function __construct(
        private readonly ?string $baseUrl,
        private readonly int $timeout = 15,
        private readonly ?string $key = null,
    ) {}

    /**
     * @return array{intent:string, drug_name:?string, symptoms:array, user_message:?string, requires_location:bool, source:string}
     */
    public function analyze(string $message): array
    {
        if (empty($this->baseUrl)) {
            return $this->fallback();
        }

        // محاولتان: خدمة الـ AI على خطة مجانية أحياناً ترجع 503 لحظياً
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
                Log::warning('AI Assistant call failed', [
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);

                continue;
            }

            Log::info('ai_chat', [
                'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'status' => $request->status(),
                'attempt' => $attempt,
            ]);

            if ($request->successful()) {
                $data = $request->json();

                return [
                    'intent' => is_string($data['intent'] ?? null) ? $data['intent'] : 'unknown',
                    'drug_name' => isset($data['drug_name']) && is_string($data['drug_name']) && trim($data['drug_name']) !== ''
                        ? trim($data['drug_name'])
                        : null,
                    'symptoms' => is_array($data['symptoms'] ?? null) ? $data['symptoms'] : [],
                    'user_message' => isset($data['user_message']) && is_string($data['user_message'])
                        ? $data['user_message']
                        : null,
                    'requires_location' => (bool) ($data['requires_location'] ?? false),
                    'source' => is_string($data['source'] ?? null) ? $data['source'] : 'gemini',
                ];
            }

            if ($attempt < 2) {
                sleep(2);
            }
        }

        return $this->fallback();
    }

    private function fallback(): array
    {
        return [
            'intent' => 'unknown',
            'drug_name' => null,
            'symptoms' => [],
            'user_message' => null,
            'requires_location' => true,
            'source' => 'fallback',
        ];
    }
}
