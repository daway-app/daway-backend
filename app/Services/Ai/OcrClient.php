<?php

namespace App\Services\Ai;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * عميل خدمة OCR (daway-ocr-api).
 * يرسل صورة علبة الدواء ويرجع اسم الدواء المستخرج مع درجة الثقة.
 */
final class OcrClient
{
    public function __construct(
        private readonly ?string $baseUrl,
        private readonly int $timeout = 20,
        private readonly ?string $key = null,
    ) {}

    /**
     * @return array{drug_name:?string, ocr_success:bool, match_score:?float, message:?string}
     */
    public function extract(UploadedFile $file): array
    {
        if (empty($this->baseUrl)) {
            return $this->failure('خدمة OCR غير مهيأة.');
        }

        $startedAt = microtime(true);

        try {
            $request = Http::timeout($this->timeout)
                ->acceptJson()
                ->when($this->key, fn ($r) => $r->withToken($this->key))
                ->asMultipart()
                ->attach('file', $file->getContent(), $file->getClientOriginalName())
                ->post(rtrim($this->baseUrl, '/').'/ocr/medicine');
        } catch (\Throwable $e) {
            Log::warning('OCR call failed', ['error' => $e->getMessage()]);

            return $this->failure('تعذر الاتصال بخدمة التعرف على الصور.');
        }

        Log::info('ai_ocr', [
            'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'status' => $request->status(),
        ]);

        if (! $request->successful()) {
            Log::warning('OCR non-2xx', ['status' => $request->status()]);

            return $this->failure('فشل التعرف على الصورة.');
        }

        $data = $request->json();

        $drugName = null;
        foreach (['drug_name', 'best_candidate'] as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && trim($data[$field]) !== '') {
                $drugName = trim($data[$field]);
                break;
            }
        }

        return [
            'drug_name' => $drugName,
            'ocr_success' => (bool) ($data['ocr_success'] ?? false),
            'match_score' => isset($data['match_score']) && is_numeric($data['match_score'])
                ? (float) $data['match_score']
                : null,
            'message' => is_string($data['message'] ?? null) ? $data['message'] : null,
        ];
    }

    private function failure(string $message): array
    {
        return [
            'drug_name' => null,
            'ocr_success' => false,
            'match_score' => null,
            'message' => $message,
        ];
    }
}
