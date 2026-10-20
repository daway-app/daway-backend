<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryFeeTier;
use App\Services\DeliveryPricingService;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
    /**
     * قفل موحّد لكل مسارات الكتابة على التسعير (الشرائح + الإعدادات + التفعيل).
     *
     * `lockForUpdate()` سلوكه يختلف بين المحركات (لا يُنفّذ على SQLite مثلًا)،
     * لذا الاعتماد عليه وحده لا يمنع التحقق المتزامن ثم كتابة مجموعتين متعارضتين.
     * هذا القفل عبر `Cache::lock` يعمل على كل المحركات ويُشارَك بين جميع المسارات،
     * فيبقى «اقرأ ← تحقّق ← اكتب» ذرّيًا حتى لو تعدّد المشرفون.
     */
    public const CONCURRENCY_LOCK = 'daway:delivery-pricing:write';

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
                'mode' => DeliveryPricingService::mode(),
                'base_fee' => DeliveryPricingService::baseFee(),
                'per_km_rate' => DeliveryPricingService::perKmRate(),
                'tiers' => $tiers->map(fn (DeliveryFeeTier $tier) => $this->tierPayload($tier))->values(),
            ],
        ]);
    }

    /** POST /api/admin/delivery-pricing/tiers */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        return $this->locked(function () use ($request, $data) {
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

        return $this->locked(function () use ($request, $data, $tier) {
            // ربط المسار يقرأ الصف قبل القفل؛ أعد قراءته تحت القفل كي لا يُبنى
            // القرار على لقطة قديمة عدّلها مشرف آخر في الأثناء.
            $tier->refresh();

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
        return $this->locked(function () use ($tier) {
            $tier->refresh();

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
        return $this->locked(function () use ($tier) {
            $tier->refresh();

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

        return $this->locked(function () use ($data) {
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

    /**
     * POST /api/admin/delivery-pricing/settings
     * يضبط وضع التسعير (tiers|per_km) والسعر الأساسي البديل وسعر الكيلومتر.
     * الحقول اختيارية (Partial)؛ التحقق يجري على الإعداد **الناتج** قبل أي كتابة،
     * فإن كان التسعير مفعّلًا تعذّر ترك الإعداد في حالة لا يمكن التسعير بها.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['sometimes', 'string', 'in:'.DeliveryPricingService::MODE_TIERS.','.DeliveryPricingService::MODE_PER_KM],
            'base_fee' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'per_km_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        return $this->locked(function () use ($data) {
            // اقفل صف الإعداد ثم الشرائح الفعّالة — بنفس ترتيب مسارات التفعيل/الحذف.
            DeliveryPricingService::isEnabledLocked();
            DeliveryFeeTier::query()->active()->lockForUpdate()->get();

            $mode = array_key_exists('mode', $data) ? $data['mode'] : DeliveryPricingService::mode();
            $baseFee = array_key_exists('base_fee', $data)
                ? ($data['base_fee'] === null ? null : (float) $data['base_fee'])
                : DeliveryPricingService::baseFee();
            $perKmRate = array_key_exists('per_km_rate', $data)
                ? ($data['per_km_rate'] === null ? null : (float) $data['per_km_rate'])
                : DeliveryPricingService::perKmRate();

            // التحقق على الإعداد الناتج **قبل** الكتابة: لا كتابة جزئية عند الرفض.
            if (DeliveryPricingService::isEnabled()) {
                $errors = DeliveryPricingService::activationErrorsFor($mode, $perKmRate);

                if ($errors !== []) {
                    return $this->settingsValidationError($errors);
                }
            }

            DeliveryPricingService::setMode($mode);
            DeliveryPricingService::setBaseFee($baseFee);
            DeliveryPricingService::setPerKmRate($perKmRate);

            return response()->json([
                'success' => true,
                'message' => 'تم حفظ إعدادات تسعير التوصيل.',
                'data' => [
                    'mode' => $mode,
                    'base_fee' => $baseFee,
                    'per_km_rate' => $perKmRate,
                ],
            ]);
        });
    }

    /**
     * ينفّذ كتابة تسعير واحدة تحت قفل مشترك (كل المحركات) ثم داخل معاملة.
     *
     * إن كان مشرف آخر يكتب الآن، نرفض فورًا بـ409 بدل الانتظار: الطلب المُرفض
     * لا يغيّر شيئًا، والتحقق يبقى على لقطة ما بعد القفل لا قبله.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function locked(Closure $callback)
    {
        $lock = Cache::lock(self::CONCURRENCY_LOCK, 30);

        if (! $lock->get()) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'هناك عملية تعديل أخرى على إعدادات تسعير التوصيل قيد التنفيذ. أعد المحاولة بعد لحظات.',
            ], 409));
        }

        try {
            return DB::transaction($callback);
        } finally {
            $lock->release();
        }
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
     * في وضع `per_km` تُتجاهل الشرائح كليًا، فلا معنى لهذا الحارس.
     *
     * @return string[]|null
     */
    private function enabledEmptySetErrors(DeliveryFeeTier $exclude): ?array
    {
        if (DeliveryPricingService::mode() !== DeliveryPricingService::MODE_TIERS) {
            return null;
        }

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

    /** @param string[] $errors */
    private function settingsValidationError(array $errors): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $errors[0] ?? 'إعدادات تسعير التوصيل غير صالحة.',
            'errors' => ['settings' => $errors],
        ], 422);
    }
}
