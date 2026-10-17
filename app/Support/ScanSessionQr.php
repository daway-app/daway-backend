<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * توليد رابط الاقتران ورمز الـQR لجلسة المسح.
 *
 * ── ما الذي يحمله الـQR بالضبط؟ ─────────────────────────────────────
 * `daway://pair?sid={uuid}&token={token}` — **جلسة مؤقتة فقط**.
 *
 * ⛔ ممنوع تمامًا أن يحمل: كلمة مرور · API key · `personal_access_token`
 * · أي توكن مصادقة دائم · `pharmacy_id` صريح. التوكن هنا عمره عمر الجلسة
 * (دقائق) ويُخزَّن مُهشَّرًا في قاعدة البيانات، فلا قيمة له بعد انتهائها.
 *
 * ── لماذا رابط عميق لا رابط https؟ ──────────────────────────────────
 * `daway://` يفتح تطبيق فلاتر مباشرة: لا يفتح متصفحًا، ولا يُفهرَس، ولا
 * يترك أثرًا في سجل الزيارات. الرابط الـhttps كان سيتطلّب صفحة ويب وسيطة
 * إضافية لم تُطلب.
 *
 * ── لماذا SVG لا PNG؟ ───────────────────────────────────────────────
 * `SvgImageBackEnd` يستخدم `XMLWriter` فقط ⇒ لا يحتاج GD ولا Imagick.
 * هذا مهم لأن الخطة المجانية على Render قد لا توفّر امتدادات الصور.
 */
class ScanSessionQr
{
    /**
     * أبجدية رمز العرض القصير.
     *
     * ⛔ مستبعَد عمدًا: `0` و`O` و`1` و`I` و`L` — الرمز يُقرأ من شاشة ويُكتب
     * يدويًّا كبديل للـQR، والحروف المتشابهة بصريًّا تُنتج أخطاء إدخال
     * لا يمكن تمييزها عن خطأ المستخدم.
     */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const CODE_LENGTH = 8;

    /** حجم صورة الـQR بالبكسل (مربّعة) — الواجهة تُحجّمها بـCSS. */
    private const QR_SIZE = 260;

    /** الهامش الأبيض حول الرمز — ضروري لبعض قارئات الكاميرا. */
    private const QR_MARGIN = 1;

    /**
     * رابط الاقتران الذي يُشفَّر داخل الـQR.
     *
     * الصيغة تُبنى من `config('scan_session.*')` فلا تُكرَّر في الواجهة.
     */
    public static function pairingUrl(string $sessionId, string $token): string
    {
        $scheme = (string) config('scan_session.deep_link_scheme', 'daway');
        $host = (string) config('scan_session.deep_link_host', 'pair');

        return $scheme.'://'.$host.'?sid='.rawurlencode($sessionId).'&token='.rawurlencode($token);
    }

    /**
     * رمز عرض قصير (8 أحرف) — بديل يُكتب يدويًّا لو تعذّر مسح الـQR.
     *
     * الفرادة مفروضة بقيد `unique` على العمود، فلا نحتاج فحصًا مسبقًا؛
     * ندع قاعدة البيانات تحكم عند التصادم النادر.
     */
    public static function generatePairingCode(): string
    {
        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * توكن الاقتران الصريح — يُعاد **مرة واحدة** داخل الـQR، ويُخزَّن
     * مُهشَّرًا (`sha256`) في قاعدة البيانات.
     */
    public static function generatePairingToken(): string
    {
        return Str::random(64);
    }

    /**
     * توكن الجهاز المقترن — يُصدره `pair` الناجح ويُستخدم لإرسال الباركود.
     *
     * منفصل عن توكن الاقتران عمدًا: الـQR المعروض على الشاشة يجب ألّا يكون
     * صلاحية إرسال باركودات. رمز لكل قدرة.
     */
    public static function generateDeviceToken(): string
    {
        return Str::random(64);
    }

    /** المقارنة الوحيدة المسموحة مع التوكن المخزَّن. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * توليد SVG للـQR.
     *
     * ── لماذا لا نُسقط الطلب لو غابت المكتبة؟ ─────────────────────────
     * لو رمت استثناءً لتوقّف إنشاء الجلسة كليًّا بسبب ميزة عرض واحدة.
     * البدل المُعلَن: `null` + سجل `error` — فيُعرض الرمز القصير بدلًا
     * منه (الواجهة تدعم ذلك أصلًا)، ويظهر العطل في السجل بصوت عالٍ بدل
     * أن يُخفى. الصمت التام كان سيجعل العطل غير مرئي.
     */
    public static function svg(string $payload): ?string
    {
        if (! class_exists(Writer::class)) {
            Log::error('ScanSessionQr: مكتبة bacon/bacon-qr-code غير مثبّتة — تعذّر توليد الـQR.', [
                'hint' => 'شغّل: composer require bacon/bacon-qr-code',
            ]);

            return null;
        }

        try {
            $renderer = new ImageRenderer(
                new RendererStyle(self::QR_SIZE, self::QR_MARGIN),
                new SvgImageBackEnd
            );

            return (new Writer($renderer))->writeString($payload);
        } catch (\Throwable $e) {
            // رمز طويل جدًّا أو محتوى غير قابل للترميز — لا نُسقط الطلب.
            Log::error('ScanSessionQr: فشل توليد الـQR.', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
