<?php

namespace Tests\Feature\Api;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * توحيد إكمال الملف الشخصي بين الموبايل والويب:
 * POST /api/profile/pharmacy يضبط profile_completed_at تلقائياً حين تكتمل
 * البيانات (هاتف + عنوان + منطقة + موقع + يوم دوام مفتوح واحد على الأقل) —
 * حتى لا تنحبس الصيدلية المسجّلة من الموبايل بصفحة الإكمال عند دخولها الويب.
 */
class PharmacyProfileCompletionApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        Sanctum::actingAs($user);
        $pharmacy = Pharmacy::factory()->create([
            'user_id' => $user->id,
            'profile_completed_at' => null,
            'phone_number' => null,
            'address' => null,
            'region' => null,
            'latitude' => null,
            'longitude' => null,
        ]);

        return [$user, $pharmacy];
    }

    private function completePayload(array $overrides = []): array
    {
        return array_merge([
            'phone' => '0599123456',
            'address' => 'شارع عمر المختار',
            'region' => 'الرمال',
            'latitude' => 31.5,
            'longitude' => 34.4,
            'working_hours' => [
                'sat' => ['open' => '09:00', 'close' => '17:00'],
            ],
        ], $overrides);
    }

    public function test_complete_profile_update_sets_profile_completed_at(): void
    {
        [, $pharmacy] = $this->actingAsPharmacy();

        $this->postJson('/api/profile/pharmacy', $this->completePayload())
            ->assertOk()
            ->assertJsonPath('success', true);

        $pharmacy->refresh();
        $this->assertNotNull($pharmacy->profile_completed_at, 'اكتمال البيانات من الموبايل يجب أن يضبط علم الإكمال');
    }

    public function test_incomplete_update_does_not_set_profile_completed_at(): void
    {
        [, $pharmacy] = $this->actingAsPharmacy();

        // عنوان فقط — الباقي ناقص
        $this->postJson('/api/profile/pharmacy', ['address' => 'شارع جزئي'])
            ->assertOk();

        $pharmacy->refresh();
        $this->assertNull($pharmacy->profile_completed_at);
    }

    public function test_missing_open_day_does_not_set_profile_completed_at(): void
    {
        [, $pharmacy] = $this->actingAsPharmacy();

        // كل الحقول موجودة لكن كل الأيام مغلقة (لا working_hours أصلاً)
        $this->postJson('/api/profile/pharmacy', collect($this->completePayload())->except('working_hours')->all())
            ->assertOk();

        $pharmacy->refresh();
        $this->assertNull($pharmacy->profile_completed_at);
    }

    public function test_region_is_accepted_and_persisted(): void
    {
        [, $pharmacy] = $this->actingAsPharmacy();

        $this->postJson('/api/profile/pharmacy', $this->completePayload(['region' => 'الشجاعية']))
            ->assertOk();

        $pharmacy->refresh();
        $this->assertSame('الشجاعية', $pharmacy->region);
    }

    public function test_completion_happens_once_and_is_idempotent(): void
    {
        [$user, $pharmacy] = $this->actingAsPharmacy();

        $this->postJson('/api/profile/pharmacy', $this->completePayload())->assertOk();
        $pharmacy->refresh();
        $first = $pharmacy->profile_completed_at;

        // تحديث ثانٍ لجزئي — العلم لا يتغير
        $this->postJson('/api/profile/pharmacy', ['address' => 'تحديث لاحق'])->assertOk();
        $pharmacy->refresh();

        $this->assertSame($first?->toDateString(), $pharmacy->profile_completed_at?->toDateString());
        $this->assertNotNull($user->fresh()->pharmacy->profile_completed_at);
    }

    public function test_web_login_after_mobile_completion_goes_to_dashboard(): void
    {
        // توكن حقيقي بدل Sanctum::actingAs — لأن actingAs يُصادق كل الطلبات التالية
        // بنفس الاختبار، فيكسر دخول الويب (guest middleware يرجّع '/' لمن هو مسجّل).
        $user = User::factory()->pharmacy()->create();
        $token = $user->createToken('test')->plainTextToken;
        $pharmacy = Pharmacy::factory()->create([
            'user_id' => $user->id,
            'profile_completed_at' => null,
            'phone_number' => null,
            'address' => null,
            'region' => null,
            'latitude' => null,
            'longitude' => null,
        ]);

        $this->postJson('/api/profile/pharmacy', $this->completePayload(), [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        // ثم تدخل من الويب — يجب ألا تنحبس بصفحة الإكمال
        // (pharmacy_custom_id خارج $fillable — يُضبط صراحةً كما في الكود الإنتاجي)
        $user->update(['password' => bcrypt('WEB-PASS1')]);
        $pharmacy->pharmacy_custom_id = 'PH-WEB1';
        $pharmacy->save();

        // طلب الـ API السابق جعل Sanctum هو الـ guard الافتراضي مع مستخدم cached —
        // بدونه يرى guest middleware الجلسة "مصادقة" ويرجّع '/' بدلاً من الدخول.
        auth()->forgetGuards();
        auth()->shouldUse('web');

        $this->post(route('login'), [
            'identity' => 'PH-WEB1',
            'password' => 'WEB-PASS1',
            'account_type' => 'pharmacy',
        ])->assertRedirect(route('pharmacy.dashboard.index'));
    }
}
