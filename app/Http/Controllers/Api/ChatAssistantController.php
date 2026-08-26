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

        $analysis = $this->ai->analyze($data['message']);

        $latitude = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $longitude = isset($data['longitude']) ? (float) $data['longitude'] : null;
        $radiusKm = $data['radius_km'] ?? 15;

        $payload = [
            'analysis' => $analysis,
            'results' => null,
        ];

        if ($analysis['intent'] === 'search_medicine' && $analysis['drug_name'] !== null) {
            $drugName = $analysis['drug_name'];

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
                'requires_location' => $analysis['requires_location'] && $latitude === null,
            ];
        }

        \App\Models\SearchLog::track($data['message'], 'ai');

        Log::info('ai_chat_result', [
            'user_id' => $request->user()?->id,
            'intent' => $analysis['intent'],
            'drug_name' => $analysis['drug_name'],
            'source' => $analysis['source'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تمت معالجة طلبك بنجاح.',
            'data' => $payload,
        ]);
    }
}
