<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Services\Ai\AiAssistantClient;
use App\Services\Ai\MedicineResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * شات المساعد الذكي للمريض/الصيدلي:
 * يستقبل رسالة نصية، يحللها عبر AI Assistant، ثم يبحث في قاعدة البيانات
 * ويرجع مرشحي الكتالوج + الصيدليات القريبة المتوفرة + البدائل.
 */
class ChatAssistantController extends Controller
{
    public function __construct(
        private readonly AiAssistantClient $ai,
        private readonly MedicineResolver $resolver,
    ) {}

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        // H8: تحليل AI مع fallback داخلي — الـ client يرجع مصفوفة آمنة عند الفشل.
        $analysis = $this->ai->analyze($data['message']);

        $latitude = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $longitude = isset($data['longitude']) ? (float) $data['longitude'] : null;
        $radiusKm = $data['radius_km'] ?? 15;

        $payload = [
            'analysis' => $analysis,
            'results' => null,
        ];

        // H8: data_get بدل الوصول المباشر — يحمي من تغيّر شكل الرد مستقبلاً.
        if (data_get($analysis, 'intent') === 'search_medicine' && data_get($analysis, 'drug_name') !== null) {
            $drugName = $analysis['drug_name'];

            try {
                $candidates = $this->resolver->resolveCandidates($drugName);

                // أفضل تطابق محلي لجلب البدائل
                $bestLocalId = $candidates['local']->first()->id ?? null;

                $payload['results'] = [
                    'drug_name' => $drugName,
                    'moh_catalog' => $candidates['moh']->values(),
                    'local_catalog' => $candidates['local']->values(),
                    'pharmacies' => $this->resolver->pharmaciesFor(
                        drugName: $drugName,
                        latitude: $latitude,
                        longitude: $longitude,
                        radiusKm: $radiusKm,
                    ),
                    'alternatives' => $bestLocalId ? $this->resolver->alternatives($bestLocalId) : [],
                    'requires_location' => data_get($analysis, 'requires_location', false) && $latitude === null,
                ];
            } catch (\Throwable $e) {
                // H8: خطأ في قاعدة البيانات/الخدمات لا يكسر الرد — نرجع نتائج فارغة مع تحليل AI.
                Log::warning('AI chat resolver failed', ['error' => $e->getMessage()]);
                $payload['results'] = null;
            }
        }

        \App\Models\SearchLog::track($data['message'], 'ai');

        Log::info('ai_chat_result', [
            'user_id' => $request->user()?->id,
            'intent' => data_get($analysis, 'intent'),
            'drug_name' => data_get($analysis, 'drug_name'),
            'source' => data_get($analysis, 'source'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تمت معالجة طلبك بنجاح.',
            'data' => $payload,
        ]);
    }
}
