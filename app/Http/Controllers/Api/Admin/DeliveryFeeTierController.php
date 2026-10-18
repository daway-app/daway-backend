<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryFeeTier;
use App\Services\DeliveryPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * لوحة الأدمن (React) — شرائح رسوم التوصيل (Phase 1).
 *
 * ════════════════════════════════════════════════════════════════════════════
 * المبادئ الحاكمة
 * ════════════════════════════════════════════════════════════════════════════
 *  · لا أسعار تجارية مبدئية: الجدول يبدأ فارغًا وكل شريحة جديدة **معطّلة**
 *    (`is_active = false`) حتى يعتمدها الأدمن صراحةً.
 *  · تفعيل التسعير (`enabled = true`) يتطلب وجود مجموعة شرائح فعّالة سليمة
 *    (تبدأ من 0، بلا تداخل ولا فجوات) — وإلّا يُرفض الطلب بـ422.
 *  · كل كتابة على شريحة **فعّالة** تُحافظ على سلامة المجموعة الفعّالة؛ أي
 *    تعديل يُحدِث تداخلًا أو فجوة يُرفض فورًا. لإعادة الهيكلة: عطّل الشريحة
 *    (المصفوفة الناتجة تُفحص) أو أضِف/احذف من الطرف المفتوح.
 *  · القيم لا تُقرأ من العميل في التسعير — هذا الكنترولر يهيّئ الشرائح فقط.
 */
class DeliveryFeeTierController extends Controller
{
    /** GET /api/admin/delivery-pricing */
    public function index(): JsonResponse
    {
        $tiers = DeliveryFeeTier::query()
            ->orderBy('min_distance_meters')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => DeliveryPricingService::isEnabled(),
                'tiers' => $tiers->map(fn (DeliveryFeeTier $tier) => $this->tierPayload($tier))->values(),
            ],
        ]);
    }

    /** POST /api/admin/delivery-pricing/tiers */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        return DB::transaction(function () use ($request, $data) {
            $isActive = $request->has('is_active') ? $request->boolean('is_active') : false;

            if ($isActive) {
                $errors = DeliveryPricingService::validateActiveSet(
                    DeliveryPricingService::activeTiersAsArrays(null, $this->candidate($data)),
                );

                if ($errors !== []) {
                    return $this->validationError($errors);
                }
            }

            $tier = DeliveryFeeTier::create([
                'min_distance_meters' => (int) $data['min_distance_meters'],
                'max_distance_meters' => ($data['max_distance_meters'] ?? null) === null ? null : (int) $data['max_distance_meters'],
                'fee' => (float) $data['fee'],
                'is_active' => $isActive,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء شريحة التوصيل بنجاح.',
                'data' => ['tier' => $this->tierPayload($tier)],
            ], 201);
        });
    }

    /** PUT /api/admin/delivery-pricing/tiers/{tier} */
    public function update(Request $request, DeliveryFeeTier $tier): JsonResponse
    {
        $data = $this->validated($request);

        return DB::transaction(function () use ($request, $data, $tier) {
            $isActive = $request->has('is_active') ? $request->boolean('is_active') : (bool) $tier->is_active;

            if ($isActive) {
                // تفعيل/إبقاء مفعّلة: افحص المجموعة الناتجة بالقيم الجديدة.
                $errors = DeliveryPricingService::validateActiveSet(
                    DeliveryPricingService::activeTiersAsArrays($tier, $this->candidate($data)),
                );
            } elseif ((bool) $tier->is_active) {
                // تعطيل شريحة كانت مفعّلة: يُمنع إن كان التسعير مفعّلًا وهذا آخر
                // شريحة فعّالة؛ وإلّا يُفحص الباقي (قد يُحدِث فجوة).
                $errors = $this->enabledEmptySetErrors($tier)
                    ?? DeliveryPricingService::validateActiveSet(
                        DeliveryPricingService::activeTiersAsArrays($tier),
                    );
            } else {
                $errors = [];
            }

            if ($errors !== []) {
                return $this->validationError($errors);
            }

            $tier->update([
                'min_distance_meters' => (int) $data['min_distance_meters'],
                'max_distance_meters' => ($data['max_distance_meters'] ?? null) === null ? null : (int) $data['max_distance_meters'],
                'fee' => (float) $data['fee'],
                'is_active' => $isActive,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث شريحة التوصيل بنجاح.',
                'data' => ['tier' => $this->tierPayload($tier)],
            ]);
        });
    }

    /** PATCH /api/admin/delivery-pricing/tiers/{tier}/toggle-status */
    public function toggleStatus(DeliveryFeeTier $tier): JsonResponse
    {
        return DB::transaction(function () use ($tier) {
            $newState = ! $tier->is_active;

            if ($newState) {
                // إضافة شريحة إلى المجموعة الفعّالة: افحص المجموعة الناتجة.
                $errors = DeliveryPricingService::validateActiveSet(
                    DeliveryPricingService::activeTiersAsArrays($tier, $this->candidateFromTier($tier)),
                );
            } else {
                // إزالة شريحة من المجموعة الفعّالة: يُمنع إفراغها أثناء التفعيل،
                // وإلّا يُفحص الباقي (قد يُحدِث فجوة).
                $errors = $this->enabledEmptySetErrors($tier)
                    ?? DeliveryPricingService::validateActiveSet(
                        DeliveryPricingService::activeTiersAsArrays($tier),
                    );
            }

            if ($errors !== []) {
                return $this->validationError($errors);
            }

            $tier->is_active = $newState;
            $tier->save();

            return response()->json([
                'success' => true,
                'message' => $newState
                    ? 'تم تفعيل شريحة التوصيل.'
                    : 'تم تعطيل شريحة التوصيل.',
                'data' => ['is_active' => (bool) $tier->is_active],
            ]);
        });
    }

    /** DELETE /api/admin/delivery-pricing/tiers/{tier} */
    public function destroy(DeliveryFeeTier $tier): JsonResponse
    {
        return DB::transaction(function () use ($tier) {
            // حذف شريحة فعّالة قد يُفرغ المجموعة (ممنوع أثناء التفعيل) أو يفتح فجوة.
            if ($tier->is_active) {
                $errors = $this->enabledEmptySetErrors($tier)
                    ?? DeliveryPricingService::validateActiveSet(
                        DeliveryPricingService::activeTiersAsArrays($tier),
                    );

                if ($errors !== []) {
                    return $this->validationError($errors);
                }
            }

            $tier->delete();

            return response()->json([
                'success' => true,
                'message' => 'تم حذف شريحة التوصيل.',
            ]);
        });
    }

    /** POST /api/admin/delivery-pricing/status  (تفعيل/إيقاف التسعير) */
    public function setEnabled(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        return DB::transaction(function () use ($data) {
            // اقفل صف الإعداد ثم الشرائح الفعّالة بنفس ترتيب مسارات التعطيل/الحذف،
            // لمزامنة التفعيل مع أي تعديل متزامن على الشرائح.
            DeliveryPricingService::isEnabledLocked();

            if ($data['enabled']) {
                DeliveryFeeTier::query()->active()->lockForUpdate()->get();

                $errors = DeliveryPricingService::enableErrors();

                if ($errors !== []) {
                    return $this->validationError($errors);
                }
            }

            DeliveryPricingService::setEnabled((bool) $data['enabled']);

            return response()->json([
                'success' => true,
                'message' => $data['enabled']
                    ? 'تم تفعيل تسعير التوصيل.'
                    : 'تم إيقاف تسعير التوصيل.',
                'data' => ['enabled' => (bool) $data['enabled']],
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'min_distance_meters' => ['required', 'integer', 'min:0', 'max:100000000'],
            'max_distance_meters' => ['nullable', 'integer', 'min:0', 'max:100000000', 'gte:min_distance_meters'],
            'fee' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function candidate(array $data): array
    {
        return [
            'min_distance_meters' => (int) $data['min_distance_meters'],
            'max_distance_meters' => ($data['max_distance_meters'] ?? null) === null
                ? null
                : (int) $data['max_distance_meters'],
            'fee' => (float) $data['fee'],
        ];
    }

    private function candidateFromTier(DeliveryFeeTier $tier): array
    {
        return [
            'min_distance_meters' => (int) $tier->min_distance_meters,
            'max_distance_meters' => $tier->max_distance_meters === null ? null : (int) $tier->max_distance_meters,
            'fee' => (float) $tier->fee,
        ];
    }

    private function tierPayload(DeliveryFeeTier $tier): array
    {
        return [
            'id' => $tier->id,
            'min_distance_meters' => (int) $tier->min_distance_meters,
            'max_distance_meters' => $tier->max_distance_meters === null ? null : (int) $tier->max_distance_meters,
            'min_distance_km' => round($tier->min_distance_meters / 1000, 2),
            'max_distance_km' => $tier->max_distance_meters === null ? null : round($tier->max_distance_meters / 1000, 2),
            'fee' => (float) $tier->fee,
            'is_active' => (bool) $tier->is_active,
        ];
    }

    /**
     * حارس «التسعير مفعّل بلا أي شريحة فعّالة»: يُعيد خطأً (422) إذا كان تعطيل
     * أو حذف الشريحة `$exclude` سيترك المجموعة الفعّالة فارغة بينما التسعير مفعّل،
     * وإلّا يُعيد `null`. يُقفل صف الإعداد وكل الشرائح الفعّالة (داخل المعاملة)
     * لتقليل التداخل بين طلبات إدارة متزامنة. لا يُستدعى إلا في مسارات الإزالة.
     *
     * @return string[]|null
     */
    private function enabledEmptySetErrors(DeliveryFeeTier $exclude): ?array
    {
        if (! DeliveryPricingService::isEnabledLocked()) {
            return null;
        }

        $remaining = DeliveryFeeTier::query()
            ->active()
            ->lockForUpdate()
            ->get()
            ->reject(fn (DeliveryFeeTier $tier) => $tier->getKey() === $exclude->getKey())
            ->count();

        if ($remaining > 0) {
            return null;
        }

        return ['لا يمكن تعطيل أو حذف آخر شريحة فعّالة ما دام تسعير التوصيل مفعّلًا. أوقف التسعير أولًا أو فعّل شريحة بديلة.'];
    }

    /** @param string[] $errors */
    private function validationError(array $errors): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $errors[0] ?? 'بيانات شرائح التوصيل غير صالحة.',
            'errors' => ['tiers' => $errors],
        ], 422);
    }
}
