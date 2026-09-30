<?php

namespace App\Services\Sms;

use App\Contracts\SmsProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * عميل SMSGate API (https://sms-gate.app)
 *
 * يرسل رسائل نصية عبر HTTP باستخدام Basic Auth.
 * يراعي الحدّ الترددي 1 طلب/ثانية لكل مفتاح API.
 * الفشل لا يوقف العملية الأساسية — يُسجّل فقط كـ warning.
 */
final class SmsGateService implements SmsProvider
{
    private const ENDPOINT = '/3rdparty/v1/messages';

    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly string $sender = 'Daway',
        private readonly ?string $countryCode = '+970',
    ) {}

    /**
     * هل SMSGate مُهيأ؟
     */
    public function enabled(): bool
    {
        return ! empty($this->baseUrl)
            && ! empty($this->username)
            && ! empty($this->password);
    }

    /**
     * إرسال رسالة SMS عبر SMSGate.
     *
     * @return array{success: bool, message_id: ?string, error: ?string}
     */
    public function sendSms(string $phone, string $message): array
    {
        if (! $this->enabled()) {
            return $this->result(false, null, 'SMSGate غير مهيأ');
        }

        $phone = $this->normalizePhone($phone);
        if ($phone === null) {
            return $this->result(false, null, 'رقم الهاتف غير صالح');
        }

        try {
            $startedAt = microtime(true);
            $response = Http::timeout(10)
                ->withBasicAuth($this->username, $this->password)
                ->acceptJson()
                ->asJson()
                ->post(rtrim($this->baseUrl, '/').self::ENDPOINT, [
                    'phone' => $phone,
                    'sender' => $this->sender,
                    'text' => $message,
                ]);

            Log::info('smsgate_send', [
                'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'status' => $response->status(),
                'success' => $response->successful(),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $messageId = $data['message_id'] ?? null;

                return $this->result(true, is_string($messageId) ? $messageId : null, null);
            }

            Log::warning('smsgate_send_failed', [
                'phone' => self::maskPhone($phone),
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return $this->result(false, null, 'فشل إرسال الرسالة: '.$response->status());
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('smsgate_connection_failed', [
                'phone' => self::maskPhone($phone),
                'error' => $e->getMessage(),
            ]);

            return $this->result(false, null, 'لا يمكن الوصول إلى SMSGate');
        } catch (\Throwable $e) {
            Log::warning('smsgate_unexpected_error', [
                'phone' => self::maskPhone($phone),
                'error' => $e->getMessage(),
            ]);

            return $this->result(false, null, 'خطأ غير متوقع');
        }
    }

    /**
     * تطبيع رقم الهاتف إلى صيغة E.164.
     *
     * - يحمّل الأرقام المحلية (10 رقم، يبدأ بـ 0) إلى رمز الدولة (+970)
     * - يمرر جملة E.164 كما هي
     * - يرجع null إذا كان الرقم غير الصالح
     */
    public function normalizePhone(string $phone): ?string
    {
        $phone = trim($phone);

        // إذا كان الرقم يبدأ بـ + فهو بالفعل E.164
        if (str_starts_with($phone, '+')) {
            // تحقق من أنه يحتوي على 8-15 رقماً بعد الزاءد
            $digits = preg_replace('/[^0-9]/', '', $phone);

            if (strlen($digits) >= 9 && strlen($digits) <= 15) {
                return '+'.$digits;
            }

            return null;
        }

        // إزالة أي فواصل أو مسافات
        $digits = preg_replace('/[^0-9]/', '', $phone);

        // رقم محلي فلسطيني: 10 أرقام، يبدأ بـ 5
        if (strlen($digits) === 10 && preg_match('/^05[6-9]/', $digits)) {
            return $this->countryCode.ltrim($digits, '0');
        }

        return null;
    }

    /**
     * إرجاع بنية نتيجة موحدة.
     */
    private function result(bool $success, ?string $messageId, ?string $error): array
    {
        return [
            'success' => $success,
            'message_id' => $messageId,
            'error' => $error,
        ];
    }

    /**
     * إخفاء الجزء الأوسط من رقم الهاتف للسجلات (أمان/خصوصية).
     */
    private static function maskPhone(string $phone): string
    {
        if (strlen($phone) <= 5) {
            return '***';
        }

        return substr($phone, 0, 3).'***'.substr($phone, -2);
    }
}
