<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\MedicineResolver;
use App\Services\Ai\OcrClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * التعرف على الدواء من صورة العلبة عبر OCR،
 * ثم البحث عنه في الكتالوج والصيدليات القريبة المتوفرة.
 */
class OcrController extends Controller
{
    public function __construct(
        private readonly OcrClient $ocr,
        private readonly MedicineResolver $resolver,
    ) {}

    public function identify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $extracted = $this->ocr->extract($request->file('file'));

        $drugName = $extracted['drug_name'];

        $payload = [
            'ocr' => [
                'success' => $extracted['ocr_success'],
                'match_score' => $extracted['match_score'],
                'message' => $extracted['message'],
            ],
            'results' => null,
        ];

        if ($drugName !== null) {
            $candidates = $this->resolver->resolveCandidates($drugName);
            $bestLocalId = $candidates['local']->first()->id ?? null;

            $payload['results'] = [
                'drug_name' => $drugName,
                'moh_catalog' => $candidates['moh']->take(5)->values(),
                'local_catalog' => $candidates['local']->take(5)->values(),
                'pharmacies' => $this->resolver->pharmaciesFor(
                    drugName: $drugName,
                    latitude: isset($data['latitude']) ? (float) $data['latitude'] : null,
                    longitude: isset($data['longitude']) ? (float) $data['longitude'] : null,
                    radiusKm: $data['radius_km'] ?? 15,
                ),
                'alternatives' => $bestLocalId ? $this->resolver->alternatives($bestLocalId) : [],
            ];
        }

        return response()->json([
            'success' => true,
            'message' => $drugName !== null
                ? 'تم التعرف على الدواء بنجاح.'
                : 'لم نتمكن من التعرف على الدواء في الصورة.',
            'data' => $payload,
        ]);
    }
}
