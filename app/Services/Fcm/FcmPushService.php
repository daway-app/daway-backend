<?php

namespace App\Services\Fcm;

use App\Contracts\FcmSender;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\CloudMessage;
use Throwable;

/**
 * إرسال push عبر Firebase HTTP v1.
 *
 * العقد مع بقية النظام:
 *  · لا يرمي استثناءات أبدًا — الفشل يُسجَّل ويُعاد كحالة.
 *  · لا يحذف إلا التوكنات الميتة المُثبتة (UNREGISTERED، أو INVALID_ARGUMENT
 *    الذي يشير صراحةً إلى `message.token`).
 *  · لا يكتب توكنات ولا نصوصًا من المزوّد في السجلات.
 *  · يسجّل نتيجة كل محاولة على صف `notifications` كي تصبح قابلة لإعادة
 *    المحاولة لاحقًا (بدون Worker دائم).
 */
class FcmPushService implements FcmSender
{
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    private function messaging(): ?Messaging
    {
        try {
            return app(Messaging::class);
        } catch (Throwable $e) {
            // SDK exceptions can contain credential paths, request bodies or tokens.
            Log::warning('fcm_unavailable', ['exception' => $e::class]);

            return null;
        }
    }

    /** SDK initialization only; this does not prove Firebase acceptance or delivery. */
    public function enabled(): bool
    {
        return $this->messaging() !== null;
    }

    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        $this->deliver($user, $title, $body, $data);
    }

    /**
     * الإرسال الفعلي. يُعيد نتيجة قابلة للتسجيل بدل رمي استثناء.
     *
     * @return array{status: string, accepted: int, failed: int, codes: array<string, int>, reason: string|null}
     */
    private function deliver(User $user, string $title, string $body, array $data): array
    {
        try {
            $tokens = DeviceToken::where('user_id', $user->id)->get();

            if ($tokens->isEmpty()) {
                Log::info('fcm_push_skipped', ['user_id' => $user->id, 'reason' => 'no_devices']);

                return $this->result(self::STATUS_SKIPPED, 0, 0, [], 'no_devices');
            }

            $messaging = $this->messaging();
            if ($messaging === null) {
                return $this->result(self::STATUS_SKIPPED, 0, 0, [], 'sdk_unavailable');
            }

            // كل القيم نصوص، والمفاتيح تبقى موجودة دائمًا (null ⇒ "") لأن
            // Flutter يعتمد على وجود `order_id`/`sub_order_id` ولو فارغين.
            $dataPayload = collect($data)->map(fn ($value) => (string) $value)->all();

            $message = CloudMessage::new()
                ->withNotification(['title' => $title, 'body' => $body])
                ->withData($dataPayload);

            $accepted = 0;
            $failed = 0;
            $pruned = 0;
            $failureCodes = [];

            foreach ($tokens->chunk(500) as $chunk) {
                $report = $messaging->sendMulticast($message, $chunk->pluck('token')->all());

                foreach ($report->getItems() as $item) {
                    if ($item->isSuccess()) {
                        $accepted++;

                        continue;
                    }

                    $failed++;
                    $error = $item->error();
                    $code = $this->fcmErrorCode($error);
                    $failureCodes[$code ?? 'OTHER'] = ($failureCodes[$code ?? 'OTHER'] ?? 0) + 1;

                    if (! $this->isDeadToken($error, $code)) {
                        continue;
                    }

                    // Match the actual report target, never its position in a batch.
                    $device = $chunk->firstWhere('token', $item->target()->value());
                    if ($device) {
                        // A refresh/account change during the request must survive.
                        $pruned += DeviceToken::whereKey($device->id)
                            ->where('user_id', $user->id)
                            ->where('token', $device->token)
                            ->delete();
                    }
                }
            }

            Log::info('fcm_push_result', [
                'user_id' => $user->id,
                'notification_id' => $data['notification_id'] ?? null,
                'accepted' => $accepted,
                'failed' => $failed,
                'total' => $tokens->count(),
                'pruned' => $pruned,
                'failure_codes' => $failureCodes,
            ]);

            // وصول جزئي يُعدّ نجاحًا: إعادة المحاولة سترسل نسخة مكررة
            // لمن وصلهم بالفعل. الفشل الكامل وحده يستحق المحاولة مجددًا.
            $status = $accepted > 0 ? self::STATUS_SENT : self::STATUS_FAILED;

            return $this->result($status, $accepted, $failed, $failureCodes, null);
        } catch (Throwable $e) {
            Log::warning('fcm_push_failed', [
                'user_id' => $user->id,
                'notification_id' => $data['notification_id'] ?? null,
                'exception' => $e::class,
            ]);

            return $this->result(self::STATUS_FAILED, 0, 0, [], 'exception');
        }
    }

    private function result(string $status, int $accepted, int $failed, array $codes, ?string $reason): array
    {
        return [
            'status' => $status,
            'accepted' => $accepted,
            'failed' => $failed,
            'codes' => $codes,
            'reason' => $reason,
        ];
    }

    private function fcmErrorCode(?MessagingException $error): ?string
    {
        $details = $error?->errors()['error']['details'] ?? [];
        foreach (is_array($details) ? $details : [] as $detail) {
            if (! is_array($detail)
                || ($detail['@type'] ?? null) !== 'type.googleapis.com/google.firebase.fcm.v1.FcmError') {
                continue;
            }

            // Only known enum values reach logs, never provider-supplied free text.
            $code = $detail['errorCode'] ?? null;
            if (in_array($code, ['UNREGISTERED', 'INVALID_ARGUMENT', 'SENDER_ID_MISMATCH',
                'QUOTA_EXCEEDED', 'UNAVAILABLE', 'INTERNAL', 'THIRD_PARTY_AUTH_ERROR'], true)) {
                return $code;
            }
        }

        return null;
    }

    private function isDeadToken(?MessagingException $error, ?string $code): bool
    {
        if ($code === 'UNREGISTERED') {
            return true;
        }

        // INVALID_ARGUMENT also describes bad payloads. Require token-specific proof.
        if ($code !== 'INVALID_ARGUMENT') {
            return false;
        }

        $details = $error?->errors()['error']['details'] ?? [];
        foreach (is_array($details) ? $details : [] as $detail) {
            if (! is_array($detail) || ($detail['@type'] ?? null) !== 'type.googleapis.com/google.rpc.BadRequest') {
                continue;
            }
            $violations = $detail['fieldViolations'] ?? [];
            foreach (is_array($violations) ? $violations : [] as $violation) {
                if (is_array($violation) && ($violation['field'] ?? null) === 'message.token') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * يرسل إشعارًا قائمًا ويسجّل نتيجة المحاولة عليه.
     *
     * العنوان والنص عامّان عمدًا: معاينة شاشة القفل لا يجوز أن تكشف اسم دواء
     * أو استفسارًا. المحتوى الكامل يبقى في API الإشعارات بعد المصادقة،
     * و`type` + معرّفات الكيانات تكفي Flutter لفتح الشاشة الصحيحة.
     */
    public function fromNotification(Notification $notification): void
    {
        try {
            $user = User::find($notification->user_id);
            if (! $user) {
                return;
            }

            $result = $this->deliver(
                $user,
                'إشعار جديد من دواك',
                'لديك تحديث جديد. افتح التطبيق للاطلاع عليه.',
                $this->payloadFor($notification),
            );

            $this->record($notification, $result);
        } catch (Throwable $e) {
            Log::warning('fcm_from_notification_failed', [
                'notification_id' => $notification->id,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * حمولة موحّدة لكل الأدوار: النوع + معرّفات الكيانات فقط.
     *
     * @return array<string, mixed>
     */
    private function payloadFor(Notification $notification): array
    {
        return [
            'notification_id' => $notification->id,
            'type' => (string) $notification->type,
            'medicine_id' => $notification->medicine_id,
            'order_id' => $notification->order_id,
            'sub_order_id' => $notification->sub_order_id,
        ];
    }

    /** @param array{status: string, codes: array<string, int>} $result */
    private function record(Notification $notification, array $result): void
    {
        $codes = array_keys($result['codes']);

        // saveQuietly: لا نريد تشغيل أي Observer بسبب تحديث حالة الإرسال.
        $notification->forceFill([
            'push_status' => $result['status'],
            'push_attempts' => (int) $notification->push_attempts + 1,
            'push_error_code' => $codes === [] ? null : implode(',', array_slice($codes, 0, 3)),
            'pushed_at' => $result['status'] === self::STATUS_SENT ? now() : $notification->pushed_at,
        ])->saveQuietly();
    }
}
