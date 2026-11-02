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

class FcmPushService implements FcmSender
{
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
        try {
            $tokens = DeviceToken::where('user_id', $user->id)->get();
            if ($tokens->isEmpty()) {
                Log::info('fcm_push_skipped', ['user_id' => $user->id, 'reason' => 'no_devices']);

                return;
            }

            $messaging = $this->messaging();
            if ($messaging === null) {
                return; // The database notification remains available to the client.
            }

            $dataPayload = collect($data)->map(fn ($value) => (string) $value)->all();
            // kreait/firebase-php 8.x: CloudMessage + sendMulticast use HTTP v1.
            $message = CloudMessage::new()
                ->withNotification(['title' => $title, 'body' => $body])
                ->withData($dataPayload);

            $accepted = 0;
            $pruned = 0;
            $failureCodes = [];

            foreach ($tokens->chunk(500) as $chunk) {
                $report = $messaging->sendMulticast($message, $chunk->pluck('token')->all());

                foreach ($report->getItems() as $item) {
                    if ($item->isSuccess()) {
                        $accepted++;

                        continue;
                    }

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
                'total' => $tokens->count(),
                'pruned' => $pruned,
                'failure_codes' => $failureCodes,
            ]);
        } catch (Throwable $e) {
            Log::warning('fcm_push_failed', [
                'user_id' => $user->id,
                'notification_id' => $data['notification_id'] ?? null,
                'exception' => $e::class,
            ]);
        }
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

    public function fromNotification(Notification $notification): void
    {
        try {
            $user = User::find($notification->user_id);
            if (! $user) {
                return;
            }

            // Lock-screen previews must not reveal medicine names, dosage or inquiries.
            // Full content stays in the authenticated database notification API.
            $this->sendToUser($user, 'إشعار جديد من دواك', 'لديك تحديث جديد. افتح التطبيق للاطلاع عليه.', [
                'notification_id' => $notification->id,
                'type' => (string) $notification->type,
                'medicine_id' => $notification->medicine_id,
                'order_id' => $notification->order_id,
                'sub_order_id' => $notification->sub_order_id,
            ]);
        } catch (Throwable $e) {
            Log::warning('fcm_from_notification_failed', [
                'notification_id' => $notification->id,
                'exception' => $e::class,
            ]);
        }
    }
}
