<?php

namespace Tests\Feature\Api\Pharmacy;

use App\Models\Pharmacy;
use App\Models\ScanSession;
use App\Models\User;
use App\Support\ScanSessionQr;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * جلسات المسح بالهاتف — من الطلب إلى قاعدة البيانات.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * ما تُثبته هذه الاختبارات فعليًّا (لا «أن الـendpoint يرد 200»):
 *
 *   • **فصل القدرات**: توكن الاقتران (من الـQR) لا يرسل باركودًا، وتوكن
 *     الجهاز لا يقترن. لو انفصل أحدهما لصار الـQR المعروض صلاحية مفتوحة.
 *   • **الاقتران المزدوج** يُرفض ذرّيًّا بـ409 — ويُسجَّل الجهاز الطالب كطلب
 *     معلّق بدل رفضه صامتًا (قرار بشري).
 *   • **الجهاز غير المقترن لا يرسل باركودًا** (403) — الحارس في الخادم لا
 *     في الواجهة.
 *   • **عزل الصيدليات**: صيدلية أخرى ترى 404 لا جلسة غيرها.
 *   • **لا تسرّب**: الـQR لا يحمل أي توكن دائم، والمُهشَّرات لا تظهر في أي
 *     استجابة، وتوكن الجهاز يُعاد مرة واحدة فقط.
 *
 * الرقم الأهم في هذا الملف:
 *   `test_second_device_gets_409_and_is_recorded_as_pending` — لأن فقدانه
 *   يعني أن جهازًا ثانيًا يستولي على الجلسة بلا قرار المستخدم.
 * ══════════════════════════════════════════════════════════════════════════
 */
class ScanSessionApiTest extends TestCase
{
    /** @return array{0:User,1:Pharmacy} */
    private function pharmacyUser(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    /**
     * إنشاء جلسة على مستوى الموديل (بلا HTTP).
     *
     * ⚠️ مقصود: اختبارات مسار التوكن يجب ألّا تحمل مستخدمًا مُصادَقًا، وإلا
     * أخذ الحارس فرع «Bearer صيدلية» ولم يُختبر مسار التوكن إطلاقًا.
     *
     * @return array{0:ScanSession,1:string} [الجلسة، توكن الاقتران الصريح]
     */
    private function makeSession(Pharmacy $pharmacy, ?User $creator = null, int $ttlMinutes = 5): array
    {
        $plainToken = ScanSessionQr::generatePairingToken();

        $session = ScanSession::create([
            'pharmacy_id' => $pharmacy->id,
            'created_by' => $creator?->id,
            'pairing_code' => ScanSessionQr::generatePairingCode(),
            'pairing_token' => ScanSessionQr::hashToken($plainToken),
            'status' => ScanSession::STATUS_WAITING,
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        return [$session, $plainToken];
    }

    /** اقتران ناجح عبر HTTP، وإرجاع توكن الجهاز. */
    private function pairDevice(ScanSession $session, string $pairingToken, string $name = 'Phone A'): string
    {
        $response = $this->postJson(
            '/api/pharmacy/scan-sessions/'.$session->id.'/pair',
            ['device_name' => $name],
            ['X-Scan-Token' => $pairingToken]
        )->assertOk();

        $deviceToken = (string) $response->json('data.device_token');
        $this->assertNotSame('', $deviceToken);

        return $deviceToken;
    }

    /** استخراج التوكن من رابط الاقتران — كما يفعل تطبيق الجوال من الـQR. */
    private function tokenFromUrl(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) ($query['token'] ?? '');
    }

    private function pairUrl(ScanSession $session): string
    {
        return '/api/pharmacy/scan-sessions/'.$session->id.'/pair';
    }

    private function barcodeUrl(ScanSession $session): string
    {
        return '/api/pharmacy/scan-sessions/'.$session->id.'/barcode';
    }

    // ══════════════════════════════════════════════════════════════════
    // الصلاحيات
    // ══════════════════════════════════════════════════════════════════

    public function test_guest_cannot_create_or_read_sessions(): void
    {
        $this->postJson('/api/pharmacy/scan-sessions')->assertUnauthorized();
        $this->getJson('/api/pharmacy/scan-sessions/'.fake()->uuid())->assertUnauthorized();
    }

    public function test_patient_cannot_create_sessions(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $this->postJson('/api/pharmacy/scan-sessions')->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════════
    // الإنشاء
    // ══════════════════════════════════════════════════════════════════

    public function test_store_returns_whitelisted_payload(): void
    {
        [$user] = $this->pharmacyUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pharmacy/scan-sessions');

        $response->assertCreated()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.status', ScanSession::STATUS_WAITING)
            ->assertJsonPath('data.device', null)
            ->assertJsonPath('data.pending_device', null)
            ->assertJsonPath('data.barcode', null);

        $data = $response->json('data');

        // المفاتيح التي تقرأها الواجهة حرفيًّا — لا أكثر.
        $this->assertEqualsCanonicalizing([
            'id', 'status', 'pairing_code', 'pairing_url', 'pairing_qr_svg',
            'expires_at', 'seconds_remaining', 'device', 'devices',
            'pending_device', 'barcode',
        ], array_keys($data));

        $this->assertSame(8, strlen((string) $data['pairing_code']));
        $this->assertGreaterThan(0, $data['seconds_remaining']);
        $this->assertStringContainsString('<svg', (string) $data['pairing_qr_svg']);
    }

    public function test_store_closes_previous_live_session(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$previous] = $this->makeSession($pharmacy, $user);

        Sanctum::actingAs($user);
        $this->postJson('/api/pharmacy/scan-sessions')->assertCreated();

        $previous->refresh();

        $this->assertSame(ScanSession::STATUS_CLOSED, $previous->status);
        $this->assertNotNull($previous->closed_at);
    }

    // ══════════════════════════════════════════════════════════════════
    // الحالة + العزل
    // ══════════════════════════════════════════════════════════════════

    public function test_show_returns_own_session(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$session] = $this->makeSession($pharmacy, $user);

        Sanctum::actingAs($user);

        $this->getJson('/api/pharmacy/scan-sessions/'.$session->id)
            ->assertOk()
            ->assertJsonPath('data.id', $session->id)
            ->assertJsonPath('data.status', ScanSession::STATUS_WAITING);
    }

    public function test_show_is_isolated_per_pharmacy(): void
    {
        [, $pharmacyA] = $this->pharmacyUser();
        [$userB] = $this->pharmacyUser();

        [$sessionA] = $this->makeSession($pharmacyA);

        Sanctum::actingAs($userB);

        // صيدلية أخرى: 404 لا 403 — لا نكشف وجود الجلسة أصلًا.
        $this->getJson('/api/pharmacy/scan-sessions/'.$sessionA->id)->assertNotFound();
    }

    public function test_show_returns_410_after_expiry_and_marks_it(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$session] = $this->makeSession($pharmacy, $user);

        $session->forceFill(['expires_at' => now()->subMinute()])->save();

        Sanctum::actingAs($user);

        $this->getJson('/api/pharmacy/scan-sessions/'.$session->id)
            ->assertStatus(410)
            ->assertJson(['success' => false]);

        $this->assertSame(ScanSession::STATUS_EXPIRED, $session->fresh()->status);
    }

    // ══════════════════════════════════════════════════════════════════
    // الاقتران
    // ══════════════════════════════════════════════════════════════════

    public function test_pair_with_pairing_token_issues_device_token(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        [$session, $pairingToken] = $this->makeSession($pharmacy);

        $response = $this->postJson(
            $this->pairUrl($session),
            ['device_name' => 'Samsung A52'],
            ['X-Scan-Token' => $pairingToken]
        );

        $response->assertOk()->assertJsonPath('data.name', 'Samsung A52');

        // 🔑 توكن الجهاز يُعاد مرة واحدة، ويختلف عن توكن الاقتران.
        $deviceToken = (string) $response->json('data.device_token');
        $this->assertNotSame('', $deviceToken);
        $this->assertNotSame($pairingToken, $deviceToken);

        $session->refresh();

        $this->assertSame(ScanSession::STATUS_PAIRED, $session->status);
        $this->assertSame('Samsung A52', $session->active_device_name);
        $this->assertNotNull($session->active_device_id);

        // المخزَّن مُهشَّر لا صريح.
        $this->assertSame(ScanSessionQr::hashToken($deviceToken), $session->device_token);
    }

    public function test_second_device_gets_409_and_is_recorded_as_pending(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        [$session, $pairingToken] = $this->makeSession($pharmacy);

        $this->postJson($this->pairUrl($session), ['device_name' => 'Phone A'], ['X-Scan-Token' => $pairingToken])
            ->assertOk();

        $this->postJson($this->pairUrl($session), ['device_name' => 'Phone B'], ['X-Scan-Token' => $pairingToken])
            ->assertStatus(409)
            ->assertJson(['success' => false]);

        $session->refresh();

        // الجهاز النشط **لم** يُستبدل…
        $this->assertSame('Phone A', $session->active_device_name);

        // …لكن الطالب لم يُرفض صامتًا: صار طلبًا معلّقًا يقرأه الويب.
        $this->assertSame('Phone B', $session->pending_device_name);
    }

    public function test_same_device_repairing_issues_a_fresh_device_token(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        [$session, $pairingToken] = $this->makeSession($pharmacy);

        $first = $this->pairDevice($session, $pairingToken, 'Phone A');

        // نفس الاسم = نفس الجهاز ⇒ نجاح متكرر (مسار التعافي) لا 409.
        $second = $this->pairDevice($session, $pairingToken, 'Phone A');

        $this->assertNotSame($first, $second, 'التوكن القديم يجب أن يُستبدل لا أن يبقى صالحًا.');

        $session->refresh();

        // رمز واحد صالح فقط للجهاز في أي لحظة.
        $this->assertSame(ScanSessionQr::hashToken($second), $session->device_token);
    }

    public function test_pharmacy_bearer_approves_and_replaces_active_device(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$session, $pairingToken] = $this->makeSession($pharmacy, $user);

        $deviceTokenA = $this->pairDevice($session, $pairingToken, 'Phone A');

        $this->postJson($this->pairUrl($session), ['device_name' => 'Phone B'], ['X-Scan-Token' => $pairingToken])
            ->assertStatus(409);

        // الويب يضغط [سماح] بـBearer — الموضع الوحيد الذي يُسمح فيه بالاستبدال.
        Sanctum::actingAs($user);
        $this->postJson($this->pairUrl($session), ['device_name' => 'Phone B'])->assertOk();

        $session->refresh();

        $this->assertSame('Phone B', $session->active_device_name);
        $this->assertNull($session->pending_device_name);

        // 🔒 الجهاز المطرود يفقد قدرته فورًا: توكنه مات مع الاستبدال.
        $this->assertNull($session->device_token);
        $this->postJson($this->barcodeUrl($session), ['barcode' => '111'], ['X-Device-Token' => $deviceTokenA])
            ->assertUnauthorized();
    }

    public function test_pair_with_invalid_token_is_401(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        [$session] = $this->makeSession($pharmacy);

        $this->postJson($this->pairUrl($session), ['device_name' => 'X'], ['X-Scan-Token' => 'wrong-token'])
            ->assertUnauthorized();

        $this->assertNull($session->fresh()->active_device_id);
    }

    public function test_pair_without_any_credential_is_401(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        [$session] = $this->makeSession($pharmacy);

        $this->postJson($this->pairUrl($session), ['device_name' => 'X'])->assertUnauthorized();
    }

    // ══════════════════════════════════════════════════════════════════
    // فصل القدرات — جوهر الأمان في هذه الميزة
    // ══════════════════════════════════════════════════════════════════

    public function test_pairing_token_cannot_send_barcode(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        [$session, $pairingToken] = $this->makeSession($pharmacy);

        $this->pairDevice($session, $pairingToken);

        // توكن الـQR المعروض على الشاشة **لا** يصلح لإرسال باركود.
        $this->postJson($this->barcodeUrl($session), ['barcode' => '6281000123456'], ['X-Scan-Token' => $pairingToken])
            ->assertUnauthorized();

        $this->assertNull($session->fresh()->scanned_barcode);
    }

    public function test_device_token_cannot_be_used_to_pair(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        [$session, $pairingToken] = $this->makeSession($pharmacy);

        $deviceToken = $this->pairDevice($session, $pairingToken, 'Phone A');

        // توكن الجهاز لا يقترن — القدرة معكوسة أيضًا.
        $this->postJson($this->pairUrl($session), ['device_name' => 'Phone C'], ['X-Scan-Token' => $deviceToken])
            ->assertUnauthorized();
    }

    // ══════════════════════════════════════════════════════════════════
    // الباركود
    // ══════════════════════════════════════════════════════════════════

    public function test_barcode_is_stored_when_paired(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        [$session, $pairingToken] = $this->makeSession($pharmacy);

        $deviceToken = $this->pairDevice($session, $pairingToken);

        $this->postJson($this->barcodeUrl($session), ['barcode' => '6281000123456'], ['X-Device-Token' => $deviceToken])
            ->assertOk()
            ->assertJsonPath('data.barcode', '6281000123456');

        $this->assertSame('6281000123456', $session->fresh()->scanned_barcode);
    }

    public function test_barcode_without_device_token_is_401(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        [$session, $pairingToken] = $this->makeSession($pharmacy);

        $this->pairDevice($session, $pairingToken);

        $this->postJson($this->barcodeUrl($session), ['barcode' => '6281000123456'])->assertUnauthorized();
        $this->assertNull($session->fresh()->scanned_barcode);
    }

    public function test_barcode_is_403_when_session_is_not_paired(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        [$session, $pairingToken] = $this->makeSession($pharmacy);

        $deviceToken = $this->pairDevice($session, $pairingToken);

        // حالة شاذّة مصنوعة يدويًّا: توكن جهاز صالح لكن الجلسة ليست مقترنة.
        // الحارس يمرّر (التوكن صحيح)، والكنترولر يرفض بـ403 — دفاع في العمق.
        //
        // ⚠️ `refresh()` إلزامي: نموذج الاختبار لا يعرف تعديلات طلبات HTTP
        // السابقة، فبدونه لا تتغيّر القيمة في الذاكرة ⇒ `save()` لا يكتب شيئًا.
        $session->refresh()->forceFill(['status' => ScanSession::STATUS_WAITING])->save();

        $this->postJson($this->barcodeUrl($session), ['barcode' => '6281000123456'], ['X-Device-Token' => $deviceToken])
            ->assertForbidden();

        $this->assertNull($session->fresh()->scanned_barcode);
    }

    public function test_barcode_validation_rejects_blank_and_overlong(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        [$session, $pairingToken] = $this->makeSession($pharmacy);

        $deviceToken = $this->pairDevice($session, $pairingToken);

        $this->postJson($this->barcodeUrl($session), ['barcode' => '   '], ['X-Device-Token' => $deviceToken])
            ->assertStatus(422);

        $this->postJson($this->barcodeUrl($session), ['barcode' => str_repeat('9', 33)], ['X-Device-Token' => $deviceToken])
            ->assertStatus(422);
    }

    public function test_barcode_is_rate_limited_per_session(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        [$session, $pairingToken] = $this->makeSession($pharmacy);

        $deviceToken = $this->pairDevice($session, $pairingToken);
        $headers = ['X-Device-Token' => $deviceToken];

        config(['scan_session.barcode_per_minute' => 2]);

        $this->postJson($this->barcodeUrl($session), ['barcode' => '111'], $headers)->assertOk();
        $this->postJson($this->barcodeUrl($session), ['barcode' => '222'], $headers)->assertOk();
        $this->postJson($this->barcodeUrl($session), ['barcode' => '333'], $headers)->assertStatus(429);
    }

    public function test_expired_session_rejects_barcode(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        [$session, $pairingToken] = $this->makeSession($pharmacy);

        $deviceToken = $this->pairDevice($session, $pairingToken);

        $session->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->postJson($this->barcodeUrl($session), ['barcode' => '6281000123456'], ['X-Device-Token' => $deviceToken])
            ->assertStatus(410);
    }

    // ══════════════════════════════════════════════════════════════════
    // الإغلاق
    // ══════════════════════════════════════════════════════════════════

    public function test_destroy_closes_session_and_invalidates_device_token(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$session, $pairingToken] = $this->makeSession($pharmacy, $user);

        $deviceToken = $this->pairDevice($session, $pairingToken);

        Sanctum::actingAs($user);
        $this->deleteJson('/api/pharmacy/scan-sessions/'.$session->id)->assertOk();

        $session->refresh();

        $this->assertSame(ScanSession::STATUS_CLOSED, $session->status);
        $this->assertNotNull($session->closed_at);

        // الجهاز لم يعد يرسل: 410 من الحارس (مغلقة).
        $this->postJson($this->barcodeUrl($session), ['barcode' => '111'], ['X-Device-Token' => $deviceToken])
            ->assertStatus(410);
    }

    public function test_destroy_is_isolated_per_pharmacy(): void
    {
        [, $pharmacyA] = $this->pharmacyUser();
        [$userB] = $this->pharmacyUser();
        [$sessionA] = $this->makeSession($pharmacyA);

        Sanctum::actingAs($userB);

        $this->deleteJson('/api/pharmacy/scan-sessions/'.$sessionA->id)->assertNotFound();
        $this->assertSame(ScanSession::STATUS_WAITING, $sessionA->fresh()->status);
    }

    // ══════════════════════════════════════════════════════════════════
    // الأمان
    // ══════════════════════════════════════════════════════════════════

    public function test_qr_payload_carries_only_an_ephemeral_session(): void
    {
        [$user] = $this->pharmacyUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pharmacy/scan-sessions')->assertCreated();

        $url = (string) $response->json('data.pairing_url');
        $svg = (string) $response->json('data.pairing_qr_svg');

        $this->assertStringStartsWith('daway://pair?', $url);
        $this->assertStringContainsString('sid=', $url);
        $this->assertStringContainsString('token=', $url);

        // ⛔ لا أسرار دائمة ولا معرّفات صيدلية في الحمولة.
        foreach (['password', 'api_key', 'apikey', 'Bearer', 'personal_access_token', 'pharmacy'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $url);
        }

        // الـQR يحمل توكن الاقتران فقط — ولا يحمل توكن جهاز (لا وجود له بعد).
        $session = ScanSession::query()->latest('created_at')->first();
        $this->assertNotNull($session);
        $this->assertNull($session->device_token);
        $this->assertSame(ScanSessionQr::hashToken($this->tokenFromUrl($url)), $session->pairing_token);

        $this->assertNotEmpty($svg);
        $this->assertStringContainsString('<svg', $svg);
    }

    public function test_secrets_never_leak_in_responses(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$session, $pairingToken] = $this->makeSession($pharmacy, $user);

        Sanctum::actingAs($user);

        $show = $this->getJson('/api/pharmacy/scan-sessions/'.$session->id)->assertOk();

        $this->assertStringNotContainsString($session->pairing_token, $show->getContent());
        $this->assertStringNotContainsString($pairingToken, $show->getContent());

        // والـQR لا يُعاد في الاستطلاع (يُولَّد مرة واحدة عند الإنشاء فقط).
        $this->assertNull($show->json('data.pairing_qr_svg'));
        $this->assertNull($show->json('data.pairing_url'));

        // ولا توكن الجهاز يظهر في استجابة الحالة.
        $this->assertArrayNotHasKey('device_token', $show->json('data'));
    }
}
