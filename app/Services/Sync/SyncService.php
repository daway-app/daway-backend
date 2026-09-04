<?php

namespace App\Services\Sync;

use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\PatientInquiry;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\SyncOperation;
use App\Models\SyncState;
use App\Models\User;
use App\Support\LowStockNotifier;
use Illuminate\Support\Carbon;
use Throwable;

class SyncService
{
    /**
     * معالجة دفعة عمليات مزامنة من العميل (offline queue).
     * كل عملية idempotent عبر الـ uuid؛ فشل عملية واحدة لا يوقف الدفعة.
     */
    public function push(array $operations, User $user): array
    {
        $pharmacy = Pharmacy::where('user_id', $user->id)->first();

        $results = [];

        foreach ($operations as $op) {
            $uuid = (string) ($op['uuid'] ?? '');
            $opType = (string) ($op['op_type'] ?? '');
            $payload = (array) ($op['payload'] ?? []);
            $clientUpdatedAt = ! empty($op['client_updated_at']) ? Carbon::parse($op['client_updated_at']) : null;

            // Idempotency: نفس الـ uuid يعيد النتيجة المخزّنة
            $existing = $uuid !== '' ? SyncOperation::where('uuid', $uuid)->where('user_id', $user->id)->first() : null;
            if ($existing) {
                $result = [
                    'uuid' => $uuid,
                    'status' => $existing->status,
                    'duplicate' => true,
                ];
                if ($existing->error) {
                    $result['error'] = $existing->error;
                }
                $results[] = $result;

                continue;
            }

            $record = new SyncOperation([
                'uuid' => $uuid,
                'user_id' => $user->id,
                'pharmacy_id' => $pharmacy?->id,
                'op_type' => $opType,
                'payload' => $payload,
                'client_updated_at' => $clientUpdatedAt,
            ]);
            $record->attempts = 1;

            try {
                $handler = match ($opType) {
                    'inventory.update' => 'handleInventoryUpdate',
                    'medicine.store' => 'handleMedicineStore',
                    'medicine.update' => 'handleMedicineUpdate',
                    'inquiry.status' => 'handleInquiryStatus',
                    default => null,
                };

                if ($handler === null || $pharmacy === null) {
                    $error = 'نوع عملية المزامنة غير معروف أو لا توجد صيدلية مرتبطة بالمستخدم';
                    $record->status = SyncOperation::STATUS_FAILED;
                    $record->error = $error;
                    $result = ['uuid' => $uuid, 'status' => SyncOperation::STATUS_FAILED, 'error' => $error];
                } else {
                    $result = $this->{$handler}($payload, $pharmacy, $clientUpdatedAt);
                    $record->status = $result['status'];
                    $record->error = $result['error'] ?? null;
                    $record->server_applied_at = now();
                }
            } catch (Throwable $e) {
                $record->status = SyncOperation::STATUS_FAILED;
                $record->error = $e->getMessage();
                $result = ['uuid' => $uuid, 'status' => SyncOperation::STATUS_FAILED, 'error' => $e->getMessage()];
            }

            $record->save();

            $result['uuid'] = $uuid;
            $results[] = $result;
        }

        return $results;
    }

    /**
     * سحب آخر التغييرات (inventory + inquiries) منذ آخر مزامنة.
     */
    public function pull(User $user, ?string $sinceIso): array
    {
        $pharmacy = Pharmacy::where('user_id', $user->id)->first();

        if ($sinceIso) {
            $since = Carbon::parse($sinceIso);
        } else {
            $state = SyncState::where('user_id', $user->id)->where('entity', 'pharmacy')->first();
            $since = $state?->last_pulled_at ?? Carbon::createFromTimestampUTC(0);
        }

        $now = now();

        $inventory = [];
        $inquiries = [];

        if ($pharmacy) {
            $inventory = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
                ->where('updated_at', '>', $since)
                ->with('medicine')
                ->get()
                ->map(fn (PharmacyMedicine $pm) => [
                    'id' => $pm->id,
                    'medicine_id' => $pm->medicine_id,
                    'price' => $pm->price !== null ? (float) $pm->price : null,
                    'quantity' => (int) $pm->quantity,
                    'is_available' => (bool) $pm->is_available,
                    'updated_at' => optional($pm->updated_at)->toISOString(),
                    'medicine' => $pm->medicine ? [
                        'id' => $pm->medicine->id,
                        'trade_name' => $pm->medicine->trade_name,
                        'trade_name_ar' => $pm->medicine->trade_name_ar,
                        'active_ingredient' => $pm->medicine->active_ingredient,
                    ] : null,
                ])
                ->values()
                ->all();

            $inquiries = PatientInquiry::where('pharmacy_id', $pharmacy->id)
                ->where('updated_at', '>', $since)
                ->get()
                ->map(fn (PatientInquiry $inquiry) => [
                    'id' => $inquiry->id,
                    'user_id' => $inquiry->user_id,
                    'medicine_id' => $inquiry->medicine_id,
                    'message' => $inquiry->message,
                    'status' => $inquiry->status,
                    'reply' => $inquiry->reply,
                    'availability_status' => $inquiry->availability_status,
                    'replied_at' => optional($inquiry->replied_at)->toISOString(),
                    'updated_at' => optional($inquiry->updated_at)->toISOString(),
                ])
                ->values()
                ->all();
        }

        SyncState::updateOrCreate(
            ['user_id' => $user->id, 'entity' => 'pharmacy'],
            ['last_pulled_at' => $now]
        );

        return [
            'success' => true,
            'message' => 'تم جلب آخر التغييرات بنجاح',
            'data' => [
                'since' => $since->toISOString(),
                'server_time' => $now->toISOString(),
                'inventory' => $inventory,
                'inquiries' => $inquiries,
                'deleted_pharmacy_medicine_ids' => [],
            ],
        ];
    }

    /**
     * تحديث كميات المخزون مع قاعدة LWW على الكمية.
     */
    private function handleInventoryUpdate(array $payload, Pharmacy $pharmacy, ?Carbon $opClientUpdatedAt): array
    {
        $items = (array) ($payload['items'] ?? []);
        if ($items === []) {
            return ['status' => SyncOperation::STATUS_FAILED, 'error' => 'لا توجد عناصر لتحديثها في عملية المخزون'];
        }

        $applied = [];
        $affectedIds = [];

        foreach ($items as $item) {
            $pmId = (int) ($item['pharmacy_medicine_id'] ?? 0);
            $pm = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->where('id', $pmId)->first();

            if (! $pm) {
                $applied[] = ['id' => $pmId, 'status' => 'failed', 'quantity' => null];

                continue;
            }

            // LWW: نطبّق فقط إذا كان وقت العميل >= وقت التحديث الحالي (null = تطبيق غير مشروط)
            $clientTs = ! empty($item['client_updated_at'])
                ? Carbon::parse($item['client_updated_at'])
                : $opClientUpdatedAt;

            if ($clientTs !== null && $pm->updated_at !== null && $clientTs->lt($pm->updated_at)) {
                $applied[] = ['id' => $pm->id, 'status' => 'conflict', 'quantity' => (int) $pm->quantity];

                continue;
            }

            $quantity = max(0, (int) ($item['quantity'] ?? $pm->quantity));
            $isAvailable = array_key_exists('is_available', $item)
                ? (bool) $item['is_available']
                : ($quantity > 0);

            $pm->update([
                'quantity' => $quantity,
                'is_available' => $isAvailable,
            ]);

            $applied[] = ['id' => $pm->id, 'status' => 'applied', 'quantity' => $quantity];
            $affectedIds[] = $pm->id;
        }

        $this->notifyAffected($pharmacy, $affectedIds);

        return ['status' => SyncOperation::STATUS_APPLIED, 'data' => ['items' => $applied]];
    }

    /**
     * إضافة دواء للمخزون بالاسم — نفس منطق storeByName في الـ API.
     */
    private function handleMedicineStore(array $payload, Pharmacy $pharmacy, ?Carbon $opClientUpdatedAt): array
    {
        $tradeName = trim((string) ($payload['trade_name'] ?? ''));
        $tradeNameAr = isset($payload['trade_name_ar']) ? trim((string) $payload['trade_name_ar']) : null;
        $activeIngredient = trim((string) ($payload['active_ingredient'] ?? ''));
        $price = (float) ($payload['price'] ?? 0);
        $quantity = max(0, (int) ($payload['quantity'] ?? 0));
        $isAvailable = array_key_exists('is_available', $payload) ? (bool) $payload['is_available'] : ($quantity > 0);

        if ($tradeName === '' || $activeIngredient === '') {
            return ['status' => SyncOperation::STATUS_FAILED, 'error' => 'اسم الدواء والمادة الفعالة مطلوبان'];
        }

        // 1) الاسم الإنجليزي في الكتالوج العام، وإلا الاسم العربي إن أُرسل
        $medicine = Medicine::where('trade_name', $tradeName)->first()
            ?? ($tradeNameAr ? Medicine::where('trade_name_ar', $tradeNameAr)->first() : null);

        if (! $medicine) {
            // 2) كتالوج وزارة الصحة بالاسم الإنجليزي — يُنسخ للكتالوج العام عند الحاجة
            $moh = MohMedicine::where('trade_name', $tradeName)->first();

            // 3) إنشاء دواء جديد
            $medicine = Medicine::create([
                'trade_name' => $tradeName,
                'trade_name_ar' => $tradeNameAr,
                'active_ingredient' => $activeIngredient,
                'description' => $moh->manufacturer ?? ($moh->company ?? null),
            ]);
        } else {
            // إثراء الكتالوج: دواء موجود بمادة فعالة فارغة → تُكمّل
            if (empty($medicine->active_ingredient) && $activeIngredient !== '') {
                $medicine->active_ingredient = $activeIngredient;
                $medicine->save();
            }

            if ($tradeNameAr && empty($medicine->trade_name_ar)) {
                $medicine->trade_name_ar = $tradeNameAr;
                $medicine->save();
            }
        }

        $existing = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('medicine_id', $medicine->id)
            ->first();

        // Idempotent: مضاف مسبقاً → نجاح بدون تكرار
        if ($existing) {
            return [
                'status' => SyncOperation::STATUS_APPLIED,
                'duplicate' => true,
                'data' => [
                    'pharmacy_medicine_id' => $existing->id,
                    'medicine_id' => $medicine->id,
                ],
            ];
        }

        $pharmacyMedicine = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => $price,
            'quantity' => $quantity,
            'is_available' => $isAvailable,
        ]);

        LowStockNotifier::notifyIfLowStock($pharmacyMedicine->loadMissing('medicine', 'pharmacy.user'));

        return [
            'status' => SyncOperation::STATUS_APPLIED,
            'data' => [
                'pharmacy_medicine_id' => $pharmacyMedicine->id,
                'medicine_id' => $medicine->id,
            ],
        ];
    }

    /**
     * تحديث سطر دواء ضمن المخزون — LWW على الكمية + إثراء الكتالوج.
     */
    private function handleMedicineUpdate(array $payload, Pharmacy $pharmacy, ?Carbon $opClientUpdatedAt): array
    {
        $pmId = (int) ($payload['pharmacy_medicine_id'] ?? 0);
        $pm = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->where('id', $pmId)->first();

        if (! $pm) {
            return ['status' => SyncOperation::STATUS_FAILED, 'error' => 'الدواء غير موجود في مخزون الصيدلية'];
        }

        $update = [];

        // LWW على الكمية (نفس قاعدة inventory.update)
        if (array_key_exists('quantity', $payload)) {
            $clientTs = ! empty($payload['client_updated_at'])
                ? Carbon::parse($payload['client_updated_at'])
                : $opClientUpdatedAt;

            if ($clientTs !== null && $pm->updated_at !== null && $clientTs->lt($pm->updated_at)) {
                // تعارض على الكمية — نتخطى الكمية لكن نكمل بقية الحقول
            } else {
                $update['quantity'] = max(0, (int) $payload['quantity']);
                $update['is_available'] = array_key_exists('is_available', $payload)
                    ? (bool) $payload['is_available']
                    : ($update['quantity'] > 0);
            }
        } elseif (array_key_exists('is_available', $payload)) {
            $update['is_available'] = (bool) $payload['is_available'];
        }

        if (array_key_exists('price', $payload) && $payload['price'] !== null) {
            $update['price'] = max(0, (float) $payload['price']);
        }

        if ($update !== []) {
            $pm->update($update);
        }

        // إثراء بيانات الكتالوج: فقط إذا لم تستخدم صيدلية أخرى نفس الدواء.
        $catalogDirty = false;
        $pm->loadMissing('medicine');
        $catalogMedicine = $pm->medicine;

        $otherPharmaciesUsingSame = PharmacyMedicine::where('medicine_id', $catalogMedicine->id)
            ->where('pharmacy_id', '!=', $pharmacy->id)
            ->exists();

        if (! $otherPharmaciesUsingSame) {
            if (! empty($payload['trade_name'])) {
                $catalogMedicine->trade_name = trim((string) $payload['trade_name']);
                $catalogDirty = true;
            }
            if (! empty($payload['trade_name_ar'])) {
                $catalogMedicine->trade_name_ar = trim((string) $payload['trade_name_ar']);
                $catalogDirty = true;
            }
            if (! empty($payload['active_ingredient'])) {
                $catalogMedicine->active_ingredient = trim((string) $payload['active_ingredient']);
                $catalogDirty = true;
            }
        }

        if ($catalogDirty) {
            $catalogMedicine->save();
        }

        LowStockNotifier::notifyIfLowStock($pm->loadMissing('medicine', 'pharmacy.user'));

        return [
            'status' => SyncOperation::STATUS_APPLIED,
            'data' => [
                'id' => $pm->id,
                'quantity' => (int) $pm->quantity,
                'price' => $pm->price !== null ? (float) $pm->price : null,
            ],
        ];
    }

    /**
     * تحديث حالة استفسار مريض.
     */
    private function handleInquiryStatus(array $payload, Pharmacy $pharmacy, ?Carbon $opClientUpdatedAt): array
    {
        $inquiryId = (int) ($payload['inquiry_id'] ?? 0);
        $status = (string) ($payload['status'] ?? '');

        if (! in_array($status, PatientInquiry::STATUSES, true)) {
            return ['status' => SyncOperation::STATUS_FAILED, 'error' => 'حالة الاستفسار غير صالحة'];
        }

        $inquiry = PatientInquiry::where('pharmacy_id', $pharmacy->id)->where('id', $inquiryId)->first();

        if (! $inquiry) {
            return ['status' => SyncOperation::STATUS_FAILED, 'error' => 'الاستفسار غير موجود لهذه الصيدلية'];
        }

        $inquiry->update(['status' => $status]);

        return [
            'status' => SyncOperation::STATUS_APPLIED,
            'data' => ['inquiry_id' => $inquiry->id, 'status' => $inquiry->status],
        ];
    }

    /**
     * إطلاق إشعارات نقص المخزون للصفوف المتأثرة فقط.
     */
    private function notifyAffected(Pharmacy $pharmacy, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->whereIn('id', $ids)
            ->with('medicine', 'pharmacy.user')
            ->get()
            ->each(fn (PharmacyMedicine $pm) => LowStockNotifier::notifyIfLowStock($pm));
    }
}
