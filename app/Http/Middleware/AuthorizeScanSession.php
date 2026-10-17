<?php

namespace App\Http\Middleware;

use App\Models\ScanSession;
use App\Services\PharmacyContext;
use App\Support\ScanSessionQr;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * تفويض مسارات جلسة المسح.
 *
 * ── لماذا middleware مستقل بدل `auth:sanctum`؟ ──────────────────────
 * مسارا `pair` و`barcode` يناديهما **تطبيق الجوال**، وهو لا يملك حسابًا
 * ولا كلمة مرور — يُفوَّض بتوكن مؤقت. و`auth:sanctum` يرمي 401 **قبل** أي
 * منطق، فيستحيل داخله قبول مصدر تفويض آخر.
 *
 * ── رمزان لا رمز واحد (فصل القدرات) ─────────────────────────────────
 * يُمرَّر نوع الاعتماد كوسيط: `scan.session:pairing` أو `scan.session:device`.
 *
 *   • `pairing` — للاقتران: يقبل Bearer صيدلية (قرار الويب البشري) **أو**
 *     توكن الاقتران من الـQR. توكن الاقتران **لا يصلح لإرسال باركود**.
 *   • `device`  — لإرسال الباركود: يقبل **توكن الجهاز وحده**. لا Bearer
 *     ولا توكن اقتران — وإلا أمكن إرسال باركود بلا جهاز مقترن أصلًا.
 *
 * الفصل مقصود: لو أرسل الجهاز باركوده بتوكن الاقتران، لصار الـQR المعروض
 * على الشاشة صلاحية إرسال مفتوحة. رمز لكل قدرة، وكلاهما ينتهي بانتهاء
 * الجلسة.
 *
 * ── ما لا يفعله هذا الحارس ──────────────────────────────────────────
 * لا يقرّر **ماذا** يجوز للجهاز أن يفعل («هل الجهاز مقترن؟» يُحسم في
 * الكنترولر بـ403 لأن القرار يحتاج رؤية الجهاز النشط). هذا الحارس يجيب
 * سؤالًا واحدًا: **من أنت؟**
 */
class AuthorizeScanSession
{
    /** مفتاح السمة الذي يحمل الجلسة المُصرَّح بها للكنترولر. */
    public const SESSION_ATTRIBUTE = 'scan_session';

    /** مفتاح السمة الذي يحمل مصدر التفويض. */
    public const SOURCE_ATTRIBUTE = 'scan_session_auth';

    public const SOURCE_PHARMACY = 'pharmacy';

    public const SOURCE_PAIRING = 'pairing';

    public const SOURCE_DEVICE = 'device';

    /** نوع الاعتماد المسموح: 'pairing' (افتراضي) أو 'device'. */
    public const CREDENTIAL_PAIRING = 'pairing';

    public const CREDENTIAL_DEVICE = 'device';

    public function handle(Request $request, Closure $next, string $credential = self::CREDENTIAL_PAIRING): Response
    {
        $sessionId = (string) $request->route('id');

        if ($sessionId === '') {
            return $this->deny('جلسة المسح غير موجودة', 404);
        }

        // `user('sanctum')` لا يرمي — يُعيد null عند غياب مصادقة صالحة،
        // ويفهم التوكن Bearer وكوكي جلسة الويب معًا (EnsureFrontendRequestsAreStateful).
        $user = $request->user('sanctum');

        // ⚠️ الترتيب مقصود: وضع الجهاز يُفحص **قبل** Bearer.
        // لو مرّ Bearer الصيدلية في وضع الجهاز، لأمكن للويب إرسال باركودات
        // بلا جهاز مقترن — وهو بالضبط ما يمنعه المتطلب. الفصل قدرات لا ترتيب.
        if ($credential === self::CREDENTIAL_DEVICE) {
            $session = $this->resolveByToken($request, $sessionId, 'X-Device-Token', 'device_token');
            $source = self::SOURCE_DEVICE;
        } elseif ($user !== null && $user->role === 'pharmacy') {
            $session = $this->resolveForPharmacy($user, $sessionId);
            $source = self::SOURCE_PHARMACY;
        } else {
            $session = $this->resolveByToken($request, $sessionId, 'X-Scan-Token', 'pairing_token');
            $source = self::SOURCE_PAIRING;
        }

        if ($session instanceof Response) {
            return $session;
        }

        // انتهاء كسول: نُوسم الحالة أولًا حتى تُعيد `show` المفهومة `410`
        // لاحقًا، لا `waiting` أبدية.
        if ($session->isExpired()) {
            $session->markExpired();

            return $this->deny('انتهت جلسة المسح', 410);
        }

        $request->attributes->set(self::SESSION_ATTRIBUTE, $session);
        $request->attributes->set(self::SOURCE_ATTRIBUTE, $source);

        return $next($request);
    }

    /**
     * مصدر الويب: الجلسة **يجب** أن تخصّ صيدلية المستخدم.
     *
     * القيد داخل الاستعلام نفسه (لا مقارنة لاحقة في PHP) — نفس نمط
     * `Accounting*Controller`، وإلا نشأ IDOR كلاسيكي لو نُسي الشرط.
     */
    private function resolveForPharmacy($user, string $sessionId): ScanSession|Response
    {
        $pharmacy = PharmacyContext::forUser($user);

        if (! $pharmacy) {
            return $this->deny('الصيدلية غير موجودة', 404);
        }

        $session = ScanSession::query()
            ->forPharmacy($pharmacy->id)
            ->whereKey($sessionId)
            ->first();

        return $session ?? $this->deny('جلسة المسح غير موجودة', 404);
    }

    /**
     * مصدر الجهاز: توكن من ترويسة مخصّصة.
     *
     * المقارنة **هي البحث نفسه**: نخزّن `sha256`، ونبحث بالمُهشَّر. لا حاجة
     * إلى `hash_equals` يدويًا لأن المطابقة تامة على عمود فريد مفهرس، ولا
     * تُسرّب أي معلومة عن التوكن الصحيح.
     *
     * `$column` ليس مدخلًا من المستخدم — قيمتان حرفيتان فقط من الكود.
     */
    private function resolveByToken(Request $request, string $sessionId, string $header, string $column): ScanSession|Response
    {
        $token = (string) ($request->header($header) ?? '');

        if (trim($token) === '') {
            return $this->deny(
                $column === 'device_token' ? 'توكن الجهاز مطلوب' : 'توكن جلسة المسح مطلوب',
                401
            );
        }

        $session = ScanSession::query()
            ->whereKey($sessionId)
            ->where($column, ScanSessionQr::hashToken($token))
            ->first();

        return $session ?? $this->deny(
            $column === 'device_token' ? 'توكن الجهاز غير صالح' : 'توكن جلسة المسح غير صالح',
            401
        );
    }

    /** رد فشل موحّد — نفس غلاف الـAPI `{success,message}` برسالة عربية. */
    private function deny(string $message, int $status): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
