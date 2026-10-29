<?php

namespace Tests\Feature\Api\Sync;

use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * إصدار توكن المزامنة عبر **Bearer** (`auth:sanctum`) — التأسيس اللازم
 * لحذف طبقة Blade: كان المسار يعتمد على جلسة الويب حصريًّا
 * (`Auth::guard('web')` + `middleware('auth')`)، فبعد حذف صفحة الدخول
 * Blade لم يعد ثمة جلسة تُصدر منه توكنًا.
 *
 * ══════════════════════════════════════════════════════════════════
 * ما يثبته هذا الملف (بالترتيب الذي يطلبه قرار الانتقال):
 *  1. غير المصادَق ⇒ 401 (المسار لم يصبْ مفتوحًا).
 *  2. مصادَق بلا صلاحية (مريض) ⇒ 403 — الدور يُفحص في middleware
 *     وداخل الكنترولر (دفاع في العمق).
 *  3. صيدلية عبر **دخول حقيقي** (Bearer من /api/login/pharmacy) ⇒ 200
 *     وتوكن صالح.
 *  4. التوكن الصادر **يُستخدم فعليًّا** في المزامنة (pull بـBearer).
 *  5. العزل: توكن صيدلية لا يمنح صيدلية أخرى شيئًا — البيانات المُسحَبة
 *     من مخزون صيدليتها وحدها.
 *  6. التوكن الواحد النشط: الإصدار الجديد يبطل القديم.
 *  7. التوكن القديم المُبطَل يُرفض 401 فعليًّا.
 * ══════════════════════════════════════════════════════════════════
 */
final class SyncTokenBearerTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:Pharmacy} */
    private function pharmacyUser(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    /** دخول حقيقي عبر API ⇒ Bearer صالح (كما يفعل تطبيق React تمامًا). */
    private function bearerFor(User $user, Pharmacy $pharmacy): string
    {
        $response = $this->postJson('/api/login/pharmacy', [
            'pharmacy_id' => $pharmacy->pharmacy_custom_id,
            'password' => 'password',
        ]);

        $response->assertOk();

        return (string) $response->json('data.token');
    }

    public function test_guest_cannot_issue_a_sync_token(): void
    {
        $this->postJson('/api/sync/token')->assertStatus(401);
    }

    /**
     * المستهلك القديم (`resources/js/offline/*`) يعتمد على **جلسة الويب** لا
     * على Bearer. `auth:sanctum` يقبل الجلسة عبر `sanctum.guard = ['web']`
     * (طلب stateful)، فيبقى المسار عاملًا حتى حذف Blade — وهذا يثبت أيضًا
     * سلامة إصلاح `User::createToken` مع `TransientToken` (جلسة بلا
     * `chain_started_at`).
     */
    public function test_legacy_web_session_still_issues_a_token(): void
    {
        [$user] = $this->pharmacyUser();

        $this->actingAs($user, 'web')
            ->postJson('/api/sync/token')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token', fn ($token) => is_string($token) && $token !== '');
    }

    public function test_non_pharmacy_user_is_forbidden(): void
    {
        $patient = User::factory()->patient()->create();

        $this->actingAs($patient, 'sanctum')
            ->postJson('/api/sync/token')
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_pharmacy_issues_a_token_via_real_bearer_login(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $user->update(['password' => Hash::make('password')]);

        $bearer = $this->bearerFor($user, $pharmacy);

        $response = $this->postJson(
            '/api/sync/token',
            [],
            ['Authorization' => 'Bearer '.$bearer]
        )->assertOk();

        $response->assertJsonPath('success', true);
        $this->assertNotSame('', (string) $response->json('data.token'));
    }

    public function test_issued_token_is_actually_usable_for_sync_pull(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $user->update(['password' => Hash::make('password')]);

        $bearer = $this->bearerFor($user, $pharmacy);

        $syncToken = (string) $this->postJson(
            '/api/sync/token',
            [],
            ['Authorization' => 'Bearer '.$bearer]
        )->assertOk()->json('data.token');

        // استعمال حقيقي: pull بالمزامنة عبر توكن sync الصادر.
        // forgetGuards: Sanctum يخزّن المستخدم في الـguard عبر الطلبات داخل
        // نفس العملية — بلا تصفير كان الـpull سيمرّ بكاش مستخدم الـbearer
        // حتى لو كان توكن sync معطوبًا، فيفقد الاختبار قيمته.
        $this->app['auth']->forgetGuards();

        $this->withToken($syncToken)
            ->getJson('/api/sync/pull')
            ->assertOk();
    }

    public function test_sync_data_is_isolated_per_pharmacy(): void
    {
        $userB = User::factory()->pharmacy()->create();
        $pharmacyB = Pharmacy::factory()->create(['user_id' => $userB->id]);
        $userB->update(['password' => Hash::make('password')]);

        $syncTokenB = (string) $this->postJson(
            '/api/sync/token',
            [],
            ['Authorization' => 'Bearer '.$this->bearerFor($userB, $pharmacyB)]
        )->assertOk()->json('data.token');

        // مخزون صيدلية أخرى (A) موجود في القاعدة.
        $userA = User::factory()->pharmacy()->create();
        $pharmacyA = Pharmacy::factory()->create(['user_id' => $userA->id]);
        $medicine = \App\Models\Medicine::factory()->create(['trade_name' => 'صيدلية A فقط']);
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacyA->id,
            'medicine_id' => $medicine->id,
            'price' => 20,
            'quantity' => 5,
            'is_available' => true,
        ]);

        // pull لصيدلية B لا يُرجع صفوف صيدلية A.
        $this->app['auth']->forgetGuards();

        $rows = $this->withToken($syncTokenB)
            ->getJson('/api/sync/pull')
            ->assertOk()
            ->json('data');

        $this->assertIsArray($rows);
        foreach ($rows as $row) {
            $this->assertNotSame($medicine->id, $row['medicine_id'] ?? null);
        }
    }

    public function test_reissuing_revokes_the_previous_sync_token_for_real(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $user->update(['password' => Hash::make('password')]);

        $bearer = $this->bearerFor($user, $pharmacy);

        $first = (string) $this->postJson('/api/sync/token', [], ['Authorization' => 'Bearer '.$bearer])
            ->assertOk()->json('data.token');

        $second = (string) $this->postJson('/api/sync/token', [], ['Authorization' => 'Bearer '.$bearer])
            ->assertOk()->json('data.token');

        $this->assertNotSame($first, $second);

        // تصفير الكاش: الطلبان السابقان خزّنا مستخدم الـbearer في الـguard.
        $this->app['auth']->forgetGuards();

        // القديم ميت فعليًّا: الاستعلام به يُرفض.
        $this->withToken($first)->getJson('/api/sync/pull')->assertStatus(401);

        // والجديد يعمل.
        $this->app['auth']->forgetGuards();
        $this->withToken($second)->getJson('/api/sync/pull')->assertOk();
    }
}
