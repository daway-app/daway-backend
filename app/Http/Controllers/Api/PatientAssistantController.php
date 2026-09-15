<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\SearchLog;
use App\Services\Ai\MedicineIntentService;
use App\Services\Ai\MedicineResolver;
use App\Services\PharmacyInventorySearch;
use App\Support\Image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * مساعد المريض النصّي: رسالة حرة → دواء → صيدليات متوفرة فعلاً، مرتّبة.
 *
 * تقسيم المسؤوليات (مقصود):
 *   MedicineIntentService     رسالة → {intent, drug_name}   ← الحدّ الوحيد للـ LLM
 *   MedicineResolver          drug_name → medicine_id       ← مقابل بيانات حقيقية
 *   PharmacyInventorySearch   medicine_id → صيدليات مرتّبة   ← مخزون + سعر + مسافة
 *
 * كل ما يظهر للمريض (سعر، كمية، توفر، مسافة، ترتيب) يأتي من قاعدة البيانات.
 * الـ LLM لا يرى مخزوناً ولا يقرّر رقماً ولا يرتّب نتيجة.
 */
class PatientAssistantController extends Controller
{
    /** حدّ أعلى لنصف القطر — يمنع استعلامات جغرافية مكلفة. */
    private const MAX_RADIUS_KM = 50;

    /** نصف القطر الافتراضي عند غياب قيمة من العميل. */
    private const DEFAULT_RADIUS_KM = 10;

    /** أقصى عدد صيدليات في الرد. */
    private const MAX_RESULTS = 20;

    /** أقصى عدد بدائل في الرد. */
    private const MAX_ALTERNATIVES = 5;

    public function __construct(
        private readonly MedicineIntentService $intent,
        private readonly MedicineResolver $resolver,
        private readonly PharmacyInventorySearch $inventory,
    ) {}

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_RADIUS_KM],
            // القائمة من مصدرها الواحد في PharmacyInventorySearch — إضافة نمط
            // ترتيب جديد هناك تكفي، بلا نسخة نصية ثانية هنا تنسى التحديث.
            'sort' => ['nullable', 'string', Rule::in(PharmacyInventorySearch::SORTS)],
        ]);

        $latitude = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $longitude = isset($data['longitude']) ? (float) $data['longitude'] : null;

        // الإحداثيات إمّا تُرسَل كاملة أو لا تُرسَل — لا نخمّن موقع المستخدم.
        $hasGeo = $latitude !== null && $longitude !== null;

        if (! $hasGeo) {
            $latitude = null;
            $longitude = null;
        }

        // تتبّع البحث — نفس عُرف /medicines/resolve و/medicines/search.
        // يُسجَّل هنا (قبل تحديد النية) عمداً حتى تُحسب أيضاً الرسائل التي لم
        // نتمكّن من تحديد دواء فيها — وهي أنفع بيانات لكشف فجوات المرادفات.
        // SearchLog::track فيه منع تكرار 60 ثانية بنفس المستخدم/الاستعلام.
        SearchLog::track($data['message'], 'assistant');

        $analysis = $this->intent->analyze($data['message']);
        $drugName = $analysis['drug_name'];

        // نية غير بحث دواء، أو رسالة بلا اسم دواء صالح → لا نخترع اسماً.
        if ($analysis['intent'] !== MedicineIntentService::INTENT_MEDICINE_SEARCH || $drugName === null) {
            return $this->notIdentified($analysis);
        }

        $medicine = $this->resolveMedicine($drugName);

        if ($medicine === null) {
            return $this->notIdentified($analysis, $drugName);
        }

        $radiusKm = (int) ($data['radius_km'] ?? self::DEFAULT_RADIUS_KM);
        $sort = $data['sort'] ?? PharmacyInventorySearch::SORT_NEAREST;

        $pharmacies = $this->inventory->forMedicine(
            medicineId: $medicine->id,
            latitude: $latitude,
            longitude: $longitude,
            radiusKm: $radiusKm,
            sort: $sort,
            limit: self::MAX_RESULTS,
        );

        $payload = [
            'success' => true,
            'analysis' => [
                'intent' => $analysis['intent'],
                'drug_name' => $drugName,
                'confidence' => $analysis['confidence'],
            ],
            'medicine' => [
                'id' => $medicine->id,
                'name' => $medicine->trade_name,
                'trade_name_ar' => $medicine->trade_name_ar,
                'active_ingredient' => $medicine->active_ingredient,
                'image_url' => Image::url($medicine->image),
            ],
            'pharmacies' => $pharmacies,
            'alternatives' => $this->resolver->alternatives($medicine->id, self::MAX_ALTERNATIVES),
            'total_found' => count($pharmacies),
            'requires_location' => ! $hasGeo,
        ];

        if ($pharmacies === []) {
            $payload['message'] = 'لم أجد الصيدليات التي يتوفر فيها الدواء حاليًا.';
        }

        return response()->json($payload);
    }

    /**
     * يحوّل اسم الدواء المقترح إلى دواء حقيقي من الكتالوج المحلي.
     * الاسم المقترح لا يُعتمد إلا إذا طابق بيانات موجودة فعلاً.
     */
    private function resolveMedicine(string $drugName): ?Medicine
    {
        try {
            $candidates = $this->resolver->resolveCandidates($drugName);
        } catch (\Throwable $e) {
            // فشل الـ resolver لا يجب أن يُسقط الرد — نعيد "لم يُحدَّد الدواء".
            Log::warning('assistant resolver failed', [
                'error' => $e->getMessage(),
                'drug_name' => $drugName,
            ]);

            return null;
        }

        return $candidates['local']->first();
    }

    /**
     * الرد الموحّد عند تعذّر تحديد دواء حقيقي من رسالة المستخدم.
     *
     * @param  array{intent:string, drug_name:?string, confidence:?float, source:string}  $analysis
     */
    private function notIdentified(array $analysis, ?string $drugName = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'analysis' => [
                'intent' => $analysis['intent'],
                'drug_name' => $drugName ?? $analysis['drug_name'],
                'confidence' => $analysis['confidence'],
            ],
            'medicine' => null,
            'pharmacies' => [],
            'alternatives' => [],
            'total_found' => 0,
            'requires_location' => false,
            'message' => 'لم أتمكن من تحديد اسم الدواء.',
        ]);
    }
}
