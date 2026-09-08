<?php

namespace App\Services\Fcm;

use App\Contracts\FcmSender;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\MulticastMessage;
use Throwable;

class FcmPushService implements FcmSender
{
    /**
     * عنوان الإشعار لكل نوع — النص الكامل يأتي من جدول notifications.
     */
    private const TITLES = [
        'medicine_available' => 'دواء أصبح متوفراً ✓',
        'low_stock' => 'تنبيه: مخزون منخفض',
        'out_of_stock' => 'تنبيه: نفد المخزون',
        'new_inquiry' => 'استفسار جديد من مريض',
    ];

    /**
     * حل Messaging من الحاوية — يرمي إذا Firebase غير مُهيأ (no-op).
     */
    private function messaging(): ?Messaging
    {
        try {
            return app(Messaging::class);
        } catch (Throwable $e) {
            return null;
        }
    }

    public function enabled(): bool
    {
        return $this->messaging() !== null;
    }

    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        try {
            $messaging = $this->messaging();
            if ($messaging === null) {
                return; // FCM غير مُهيأ — no-op (الإشعار يبقى في جدول notifications)
            }

            $tokens = DeviceToken::where('user_id', $user->id)->get();
            if ($tokens->isEmpty()) {
                return;
            }

            // FCM يتطلب قيم data نصية
            $dataPayload = collect($data)->map(fn ($v) => (string) $v)->all();

            $message = MulticastMessage::create()
                ->withNotification(['title' => $title, 'body' => $body])
                ->withData($dataPayload);

            $report = $messaging->sendEachForMulticast($message, $tokens->pluck('token')->all());

            $sent = 0;
            $staleTokens = [];

            foreach ($report->getItems() as $index => $item) {
                if ($item->isSuccess()) {
                    $sent++;
                    continue;
                }

                $error = $item->error();
                $isUnregistered = $error instanceof NotFound
                    || str_contains($error->getMessage(), 'UNREGISTERED')
                    || str_contains($error->getMessage(), 'NotRegistered')
                    || str_contains($error->getMessage(), 'INVALID_REGISTRATION');

                if ($isUnregistered && isset($tokens[$index])) {
                    $staleTokens[] = $tokens[$index]->token;
                }
            }

            if ($staleTokens !== []) {
                DeviceToken::whereIn('token', $staleTokens)->delete();
            }

            Log::info('fcm_push_result', [
                'user_id' => $user->id,
                'sent' => $sent,
                'total' => $tokens->count(),
                'pruned' => count($staleTokens),
            ]);
        } catch (Throwable $e) {
            // فشل الإرسال لا يُسقط العملية الأساسية أبداً
            Log::warning('fcm_push_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * إرسال push لسجل Notification موجود (المصدر الدائم) —
     * يُستدعى فقط بعد نجاح إنشاء السجل (وليس داخل الـ transaction).
     */
    public function fromNotification(Notification $notification): void
    {
        try {
            $user = User::find($notification->user_id);
            if (! $user) {
                return;
            }

            $type = (string) $notification->type;
            $title = self::TITLES[$type] ?? 'إشعار من دوائي';

            $this->sendToUser($user, $title, (string) $notification->message, [
                'notification_id' => $notification->id,
                'type' => $type,
                'medicine_id' => $notification->medicine_id,
            ]);
        } catch (Throwable $e) {
            Log::warning('fcm_from_notification_failed', [
                'notification_id' => $notification->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
