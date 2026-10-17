<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthorizeScanSession;
use App\Models\ScanSession;
use App\Services\PharmacyContext;
use App\Support\ScanSessionQr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * جلسات المسح بالهاتف — إنشاء · حالة · اقتران · استقبال باركود · إغلاق.
 *
 * المسارات:
 *   POST   /api/pharmacy/scan-sessions                (auth:sanctum + role:pharmacy)
 *   GET    /api/pharmacy/scan-sessions/{id}           (auth:sanctum + role:pharmacy)
 *   DELETE /api/pharmacy/scan-sessions/{id}           (auth:sanctum + role:pharmacy)
 *   POST   /api/pharmacy/scan-sessions/{id}/pair      (scan.session — توكن أو Bearer)
 *   POST   /api/pharmacy/scan-sessions/{id}/barcode   (scan.session — توكن أو Bearer)
 *
 * ── حدود هذه الطبقة (مقصودة) ────────────────────────────────────────
 * هذا الكنترولر **لا يعرف** شيئًا عن السلة أو الطلبات أو المخزون. مهمته
 * تنتهي عند تخزين **نصّ الباركود** في الجلسة. من يقرأه ويحوّله إلى صنف في
 * السلة هو الويب (`accounting-scanner-session.js` → `AccountingBarcode`).
 * لذلك لا يوجد هنا أي استدعاء لـ`Sale` أو `SaleItem` أو `Cart` أو أي خدمة
 * محاسبة — وهذا مقصود لا سهو.
 *
 * ── لماذا البحث بالـid لا بـRoute Model Binding؟ ────────────────────
 * `{id}` هو uuid يُقيَّد بـ`whereUuid` في المسار، والبحث دائمًا مقيّد بـ
 * `pharmacy_id` **داخل نفس الاستعلام** (لا مقارنة لاحقة في PHP) — نفس نمط
 * `Accounting*Controller`، وإلا نشأ IDOR كلاسيكي لو نُسي الشرط.
 */
class ScanSessionController extends Controller
{
    /**
     * إنشاء جلسة مسح جديدة.
     *
     * ── جلسة نشطة واحدة لكل صيدلية ───────────────────────────────────
     * كل `store` يُغلق الجلسات الحيّة السابقة داخل نفس المعاملة. السبب
     * عملي: نافذة المسح تُفتح وتُغلق كثيرًا، ولو تركت كل واحدة صفًّا لتراكمت
     * مئات الجلسات الصالحة — وكل واحدة منها توكن يقدر على إرسال باركود.
     *
     * ── التنظيف فرصي لا مجدول ────────────────────────────────────────
     * نحذف الجلسات المنتهية منذ مدة السماح هنا، لا عبر مهمة مجدولة: خطة
     * Render المجانية لا تُشغّل عمليات دائمة. الكلفة صفّ واحد بفهرس
     * `expires_at` — أرخص من بنية جدولة كاملة.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return $this->fail('الصيدلية غير موجودة', 404);
        }

        $ttlMinutes = max(1, (int) config('scan_session.session_ttl_minutes', 5));
        $graceMinutes = max(0, (int) config('scan_session.cleanup_grace_minutes', 15));

        // التوكن الصريح يُعاد مرة واحدة داخل الـQR، ويُخزَّن مُهشَّرًا فقط.
        $plainToken = ScanSessionQr::generatePairingToken();

        $session = DB::transaction(function () use ($pharmacy, $user, $ttlMinutes, $graceMinutes, $plainToken) {
            $now = now();

            ScanSession::query()
                ->forPharmacy($pharmacy->id)
                ->live()
                ->update([
                    'status' => ScanSession::STATUS_CLOSED,
                    'closed_at' => $now,
                ]);

            ScanSession::query()
                ->forPharmacy($pharmacy->id)
                ->where('expires_at', '<', $now->copy()->subMinutes($graceMinutes))
                ->delete();

            return ScanSession::create([
                'pharmacy_id' => $pharmacy->id,
                'created_by' => $user->id,
                'pairing_code' => $this->uniquePairingCode(),
                'pairing_token' => ScanSessionQr::hashToken($plainToken),
                'status' => ScanSession::STATUS_WAITING,
                'expires_at' => $now->copy()->addMinutes($ttlMinutes),
            ]);
        });

        $pairingUrl = ScanSessionQr::pairingUrl($session->id, $plainToken);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء جلسة المسح',
            'data' => $this->presentSession(
                $session,
                pairingUrl: $pairingUrl,
                qrSvg: ScanSessionQr::svg($pairingUrl),
            ),
        ], 201);
    }

    /**
     * حالة الجلسة — وهي أيضًا **قناة وصول الباركود** في وضع polling.
     *
     * ── لماذا 410 لا 404 عند الانتهاء؟ ───────────────────────────────
     * الفرق يحمل معنى للعميل: `404` = «هذا المعرّف ليس لك / غير موجود»
     * (يُعيده الحارس أو هذا الاستعلام)، و`410` = «كان موجودًا وانتهى» —
     * وهي الحالة التي يعرفها المستخدم («انتهت الجلسة، أنشئ واحدة جديدة»).
     * الواجهة تتعامل مع الاثنين كـ`expired`، لكن الفصل يخدم التشخيص.
     *
     * ⚠️ لا يُولَّد الـQR هنا: توليده في كل استطلاع (كل ثانيتين) عمل بلا
     * فائدة — الواجهة لا تمسح `state.qrSvg` بعد الإنشاء.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return $this->fail('الصيدلية غير موجودة', 404);
        }

        $session = ScanSession::query()
            ->forPharmacy($pharmacy->id)
            ->whereKey($id)
            ->first();

        if (! $session) {
            return $this->fail('جلسة المسح غير موجودة', 404);
        }

        if ($session->isExpired()) {
            $session->markExpired();

            return $this->fail('انتهت جلسة المسح', 410);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم جلب جلسة المسح',
            'data' => $this->presentSession($session),
        ]);
    }

    /**
     * اقتران الجهاز بالجلسة.
     *
     * ── سلوكان حسب مصدر التفويض ──────────────────────────────────────
     * • `token` (الجوال نفسه): الجهاز **يطلب** الاقتران. إن كان هناك جهاز
     *   نشط ⇒ `409` (قرار بشري لا تجاوز تلقائي)، ويُسجَّل الجهاز الطالب في
     *   `pending_device_*` ليظهر على شاشة الويب.
     * • `pharmacy` (الويب): المستخدم **يقرّر**. يضغط [سماح] فيُستبدل الجهاز
     *   النشط فورًا. هذا هو الموضع الوحيد الذي يُسمح فيه بالاستبدال.
     *
     * ── لماذا UPDATE شرطي لا قراءة-ثم-كتابة؟ ─────────────────────────
     * جهازان يضغطان «اقترن» في نفس اللحظة: لو قرأنا `active_device_id` في
     * PHP ثم كتبنا، لمرّ الاثنان. الشرط `WHERE active_device_id IS NULL`
     * يجعل قاعدة البيانات هي الحَكَم — من خسر السباق يحصل على 0 صفوف
     * متأثرة ⇒ `409`. نفس مبدأ الخصم الذرّي للمخزون في `AccountingLedger`.
     */
    public function pair(Request $request, string $id): JsonResponse
    {
        $session = $this->authorizedSession($request);

        if ($session instanceof JsonResponse) {
            return $session;
        }

        $validated = $request->validate([
            'device_name' => 'nullable|string|max:120',
        ]);

        $deviceName = isset($validated['device_name']) ? trim((string) $validated['device_name']) : null;
        $deviceName = $deviceName === '' ? null : $deviceName;
        $deviceId = $this->deviceId($deviceName);

        $source = $request->attributes->get(AuthorizeScanSession::SOURCE_ATTRIBUTE);

        // الويب: موافقة بشرية ⇒ استبدال مباشر (الموضع الوحيد المسموح به).
        //
        // ⚠️ `device_token = null` عمدًا: توكن الجهاز السابق يجب أن يموت مع
        // استبداله، وإلا بقي جهاز مطرود قادرًا على إرسال باركودات. الجهاز
        // الجديد يستلم توكنه عندما يُعيد الاقتران (نجاح متكرر أدناه).
        if ($source === AuthorizeScanSession::SOURCE_PHARMACY) {
            $session->forceFill([
                'status' => ScanSession::STATUS_PAIRED,
                'active_device_id' => $deviceId,
                'active_device_name' => $deviceName,
                'pending_device_id' => null,
                'pending_device_name' => null,
                'device_token' => null,
            ])->save();

            return response()->json([
                'success' => true,
                'message' => 'تم اقتران الجهاز',
                'data' => [
                    'name' => $session->active_device_name,
                    'platform' => null,
                ],
            ]);
        }

        // الجوال: إعادة اقتران نفس الجهاز ⇒ نجاح متكرر لا 409، مع إصدار
        // توكن جهاز جديد. هذا مسار «التعافي»: تطبيق فقد توكنه (إعادة تشغيل،
        // إعادة تثبيت) يعيد النداء فيستلم توكنًا صالحًا بلا مسح QR جديد.
        if ($session->active_device_id !== null && $session->active_device_id === $deviceId) {
            return $this->issueDeviceToken($session, $deviceName);
        }

        // جهاز نشط آخر ⇒ لا تجاوز: نُسجّل الطالب كطلب معلّق ونُبلّغ بالتعارض.
        if ($session->active_device_id !== null) {
            $this->recordPendingDevice($session, $deviceId, $deviceName);

            return $this->fail('جهاز آخر مرتبط بالجلسة', 409);
        }

        $deviceToken = ScanSessionQr::generateDeviceToken();

        $claimed = ScanSession::query()
            ->whereKey($session->id)
            ->whereNull('active_device_id')
            ->update([
                'status' => ScanSession::STATUS_PAIRED,
                'active_device_id' => $deviceId,
                'active_device_name' => $deviceName,
                'pending_device_id' => null,
                'pending_device_name' => null,
                'device_token' => ScanSessionQr::hashToken($deviceToken),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            // سباق: جهاز آخر حسم الأمر بين قراءتنا والكتابة.
            $session->refresh();
            $this->recordPendingDevice($session, $deviceId, $deviceName);

            return $this->fail('جهاز آخر مرتبط بالجلسة', 409);
        }

        $session->refresh();

        return response()->json([
            'success' => true,
            'message' => 'تم اقتران الجهاز',
            'data' => [
                'name' => $session->active_device_name,
                'platform' => null,
                // 🔑 الرمز الوحيد الذي يصلح لإرسال الباركود. يُعاد هنا فقط،
                // ويُخزَّن مُهشَّرًا — لا سبيل لاسترجاعه لاحقًا.
                'device_token' => $deviceToken,
            ],
        ]);
    }

    /**
     * استقبال باركود من الجهاز المقترن.
     *
     * ── من يحقّ له النداء ────────────────────────────────────────────
     * **توكن الجهاز وحده** (`X-Device-Token`) — وليس توكن الاقتران من الـQR.
     * الحارس (`scan.session:device`) يفرض ذلك قبل الوصول إلى هنا، فتوكن
     * الاقتران المعروض على الشاشة لا يصلح لإرسال باركود.
     *
     * ── ما يُخزَّن بالضبط ────────────────────────────────────────────
     * **نصّ الباركود فقط** (≤32 حرفًا بعد `trim`). لا بحث عن دواء، ولا
     * خصم مخزون، ولا إضافة إلى سلة. الويب هو من يقرأه في الاستطلاع ويمرّره
     * إلى محرّك الباركود القائم (`AccountingBarcode.resolveBarcode`).
     *
     * ── لماذا 403 لا 401 لجهاز غير مقترن؟ ────────────────────────────
     * `401` = «لم تُعرّفني» (توكن خاطئ/مفقود — يقرّره الحارس). `403` =
     * «أعرفك، لكن هذا الفعل ليس لك» — توكن صالح لجلسة لم يُقترن بها جهاز
     * بعد. الخلط بينهما يجعل تشخيص التطبيق أصعب بلا داعٍ.
     */
    public function barcode(Request $request, string $id): JsonResponse
    {
        $session = $this->authorizedSession($request);

        if ($session instanceof JsonResponse) {
            return $session;
        }

        $validated = $request->validate([
            'barcode' => 'required|string|max:32',
        ]);

        $barcode = trim($validated['barcode']);

        if ($barcode === '') {
            return response()->json([
                'success' => false,
                'message' => 'الباركود مطلوب',
                'errors' => ['barcode' => ['الباركود مطلوب']],
            ], 422);
        }

        if (! $session->isPaired()) {
            return $this->fail('الجهاز غير مقترن بالجلسة', 403);
        }

        $session->forceFill(['scanned_barcode' => $barcode])->save();

        return response()->json([
            'success' => true,
            'message' => 'تم استقبال الباركود',
            'data' => ['barcode' => $barcode],
        ]);
    }

    /**
     * إغلاق الجلسة — يُبطل التوكن فورًا.
     *
     * الإغلاق المتكرّر **نجاح** لا خطأ: الواجهة تُغلق الجلسة عند إغلاق
     * النافذة وعند فصل الجهاز، وقد يقع النداء مرتين. ردّ 409 هنا كان
     * سيجعل «إغلاقًا نظيفًا» يبدو كعطل في السجل.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return $this->fail('الصيدلية غير موجودة', 404);
        }

        $session = ScanSession::query()
            ->forPharmacy($pharmacy->id)
            ->whereKey($id)
            ->first();

        if (! $session) {
            return $this->fail('جلسة المسح غير موجودة', 404);
        }

        $session->markClosed();

        return response()->json([
            'success' => true,
            'message' => 'تم إغلاق جلسة المسح',
            'data' => null,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // عرض
    // ══════════════════════════════════════════════════════════════════

    /**
     * تحويل الجلسة إلى مصفوفة الاستجابة.
     *
     * ⚠️ **قائمة بيضاء صارمة** مطابقة حرفيًّا لما تقرأه الواجهة في
     * `normalizeSession()` (`accounting-scanner-session.js:370-391`).
     * ما ليس في هذه القائمة لا يخرج — ولو أُضيف عمود للجدول لاحقًا لن
     * يتسرّب تلقائيًّا إلى الاستجابة.
     */
    private function presentSession(ScanSession $session, ?string $pairingUrl = null, ?string $qrSvg = null): array
    {
        // وجود الجهاز يُحكم عليه بالـid لا بالاسم: تطبيق قد يقترن بلا اسم
        // (`device_name` اختياري في العقد)، وحينها يجب أن يظهر كجهاز متصل.
        $hasDevice = $session->active_device_id !== null;

        return [
            'id' => $session->id,
            'status' => $session->status,
            'pairing_code' => $session->pairing_code,
            'pairing_url' => $pairingUrl,
            'pairing_qr_svg' => $qrSvg,
            'expires_at' => $session->expires_at?->toIso8601String(),
            'seconds_remaining' => $session->secondsRemaining(),
            'device' => $hasDevice ? [
                'name' => $session->active_device_name,
                'platform' => null,
            ] : null,
            'devices' => $hasDevice ? [[
                'name' => $session->active_device_name,
                'platform' => null,
                'active' => true,
            ]] : [],
            'pending_device' => $session->pending_device_id !== null ? [
                'name' => $session->pending_device_name,
            ] : null,
            'barcode' => $session->scanned_barcode,
        ];
    }

    /** @return ScanSession|JsonResponse */
    private function authorizedSession(Request $request)
    {
        $session = $request->attributes->get(AuthorizeScanSession::SESSION_ATTRIBUTE);

        if (! $session instanceof ScanSession) {
            // لا يُفترض الوصول هنا: الحارس يرفض قبل الكنترولر. دفاع في العمق.
            return $this->fail('جلسة المسح غير موجودة', 404);
        }

        return $session;
    }

    private function recordPendingDevice(ScanSession $session, string $deviceId, ?string $deviceName): void
    {
        $session->forceFill([
            'pending_device_id' => $deviceId,
            'pending_device_name' => $deviceName,
        ])->save();
    }

    /**
     * إصدار توكن جهاز جديد لنفس الجهاز (مسار التعافي).
     *
     * التوكن القديم يُستبدل — لا يبقى رمزان صالحان لجهاز واحد. هذا يمنع
     * تراكم رموز صالحة لو أعاد التطبيق الاقتران مرارًا.
     */
    private function issueDeviceToken(ScanSession $session, ?string $deviceName): JsonResponse
    {
        $deviceToken = ScanSessionQr::generateDeviceToken();

        $session->forceFill([
            'active_device_name' => $deviceName ?? $session->active_device_name,
            'device_token' => ScanSessionQr::hashToken($deviceToken),
            'pending_device_id' => null,
            'pending_device_name' => null,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'الجهاز مقترن بالفعل',
            'data' => [
                'name' => $session->active_device_name,
                'platform' => null,
                'device_token' => $deviceToken,
            ],
        ]);
    }

    /**
     * هوية الجهاز.
     *
     * ⚠️ **قيد معروف في العقد:** `pair` يحمل `device_name` فقط — لا معرّف
     * جهاز. لذا تُشتقّ الهوية من الاسم: تطبيق يرسل نفس الاسم يُعتبر نفس
     * الجهاز (إعادة اقتران ⇒ نجاح متكرر لا 409). بلا اسم نُولّد هوية
     * عشوائية، فلا يمكن التعرّف على الجهاز مرة أخرى — وهو السلوك الصحيح
     * لغياب أي معرّف.
     */
    private function deviceId(?string $deviceName): string
    {
        if ($deviceName === null) {
            return 'dev-'.Str::random(24);
        }

        return 'dev-'.sha1($deviceName);
    }

    /**
     * رمز عرض فريد (8 أحرف).
     *
     * الفحص المسبق هنا **تحسين لا ضمان**: الضمان هو قيد `unique` على العمود
     * (يرمي عند التصادم). الاحتمال النظري للتصادم مع 31^8 ≈ 8.5e11 تركيبة
     * مهمل، لكن الفحص يمنع حتى الخطأ الصامت.
     */
    private function uniquePairingCode(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = ScanSessionQr::generatePairingCode();

            if (! ScanSession::query()->where('pairing_code', $code)->exists()) {
                return $code;
            }
        }

        // لا يُبلغ عمليًّا. رسالة صريحة أفضل من حلقة لا تنتهي.
        throw new \RuntimeException('تعذّر توليد رمز اقتران فريد بعد 8 محاولات.');
    }

    private function fail(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
