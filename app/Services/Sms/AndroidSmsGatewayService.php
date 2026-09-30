<?php

namespace App\Services\Sms;

use App\Contracts\SmsProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * عميل Android SMS Gateway (https://github.com/capcom6/android-sms-gateway)
 *
 * يحوّل هاتف Android + SIM إلى SMS Gateway عبر HTTP API في Local Server mode.
 * يستقبل طلبات POST /message بصيغة JSON مع Basic Auth.
 *
 * الفرق عن SMSGate:
 * - Endpoint: POST /message (ليس /3rdparty/v1/messages)
 * - Payload: { "textMessage": { "text": "..." }, "phoneNumbers": ["+970..."] }
 * - لا يدعم sender — الرسالة تُرسل من رقم الهاتف المرتبط بالسيم
 *
 * الفشل لا يوقف العملية الأساسية — يُسجّل فقط كـ warning.
 */
final class AndroidSmsGatewayService implements SmsProvider
{
    private const ENDPOINT = '/message';

    private const DEFAULT_TIMEOUT = 10;

    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
        private readonly bool $enabled = false,
        private readonly ?string $countryCode = '+970',
    ) {}

    /**
     * هل Android SMS Gateway مُفعّل؟
     */
    public function enabled(): bool
    {
        return $this->enabled
            && ! empty($this->baseUrl)
            && ! empty($this->username)
            && ! empty($this->password);
    }

    /**
     * إرسال رسالة SMS عبر Android SMS Gateway.
     *
     * @return array{success: bool, message_id: ?string, error: ?string}
     */
    public function sendSms(string $phone, string $message): array
    {
        if (! $this->enabled()) {
            return $this->result(false, null, 'Android SMS Gateway غير مهيأ');
        }

        $phone = $this->normalizePhone($phone);
        if ($phone === null) {
            return $this->result(false, null, 'رقم الهاتف غير صالح');
        }

        try {
            $startedAt = microtime(true);
            $response = Http::timeout($this->timeout)
                ->withBasicAuth($this->username, $this->password)
                ->acceptJson()
                ->asJson()
                ->post(rtrim($this->baseUrl, '/').self::ENDPOINT, [
                    'textMessage' => [
                        'text' => $message,
                    ],
                    'phoneNumbers' => [$phone],
                ]);

            Log::info('android_sms_gateway_send', [
                'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'status' => $response->status(),
                'success' => $response->successful(),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                // Android SMS Gateway يُرجع معرف الرسالة أو ببساطة 200 OK
                $messageId = $data['smsId'] ?? $data['message_id'] ?? null;

                return $this->result(true, is_string($messageId) ? $messageId : null, null);
            }

            Log::warning('android_sms_gateway_send_failed', [
                'phone' => self::maskPhone($phone),
                'status' => $response->status(),
            ]);

            return $this->result(false, null, 'فشل إرسال الرسالة: '.$response->status());
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('android_sms_gateway_connection_failed', [
                'phone' => self::maskPhone($phone),
            ]);

            return $this->result(false, null, 'لا يمكن الاتصال بـ Android SMS Gateway');
        } catch (\Throwable $e) {
            Log::warning('android_sms_gateway_unexpected_error', [
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
            $digits = preg_replace('/[^0-9]/', '', $phone);

            if (strlen($digits) >= 9 && strlen($digits) <= 15) {
                return '+'.$digits;
            }

            return null;
        }

        // رقم محلي فلسطيني: 10 أرقام، يبدأ بـ 059 أو 056
        $digits = preg_replace('/[^0-9]/', '', $phone);

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
