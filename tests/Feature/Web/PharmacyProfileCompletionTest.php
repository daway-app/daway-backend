<?php

namespace Tests\Feature\Web;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PharmacyProfileCompletionTest extends TestCase
{
    public function test_pharmacy_redirected_to_completion_on_first_login(): void
    {
        $user = User::factory()->pharmacy()->create([
            'password' => Hash::make('PH-FORCE1'),
        ]);
        Pharmacy::factory()->create([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'PH-FORCE1',
            'profile_completed_at' => null,
        ]);

        $response = $this->post(route('login'), [
            'identity' => 'PH-FORCE1',
            'password' => 'PH-FORCE1',
            'account_type' => 'pharmacy',
        ]);

        $response->assertRedirect(route('pharmacy.profile.complete.show'));
    }

    public function test_pharmacy_can_access_dashboard_after_profile_completed(): void
    {
        $user = User::factory()->pharmacy()->create([
            'password' => Hash::make('PH-FORCE2'),
        ]);
        Pharmacy::factory()->create([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'PH-FORCE2',
            'profile_completed_at' => now(),
        ]);

        $response = $this->post(route('login'), [
            'identity' => 'PH-FORCE2',
            'password' => 'PH-FORCE2',
            'account_type' => 'pharmacy',
        ]);

        $response->assertRedirect(route('pharmacy.dashboard.index'));
    }

    public function test_incomplete_pharmacy_cannot_access_dashboard(): void
    {
        $user = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create([
            'user_id' => $user->id,
            'profile_completed_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('pharmacy.dashboard.index'))
            ->assertRedirect(route('pharmacy.profile.complete.show'));
    }

    public function test_pharmacy_can_complete_profile_with_password_change(): void
    {
        $user = User::factory()->pharmacy()->create([
            'password' => Hash::make('PH-COMPL1'),
        ]);
        $pharmacy = Pharmacy::factory()->create([
            'user_id' => $user->id,
            'profile_completed_at' => null,
        ]);

        $response = $this->actingAs($user)->post(route('pharmacy.profile.complete'), [
            'phone_number' => '0599123456',
            'address' => 'Main St, Gaza',
            'region' => 'Rimal',
            'latitude' => 31.5,
            'longitude' => 34.4,
            'email' => 'pharmacy@example.com',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
            'hours' => [
                'Saturday' => ['open_time' => '09:00', 'close_time' => '17:00', 'is_closed' => false],
                'Sunday' => ['open_time' => '09:00', 'close_time' => '17:00', 'is_closed' => false],
                'Monday' => ['is_closed' => true],
                'Tuesday' => ['is_closed' => true],
                'Wednesday' => ['is_closed' => true],
                'Thursday' => ['is_closed' => true],
                'Friday' => ['is_closed' => true],
            ],
        ]);

        $response->assertRedirect(route('pharmacy.dashboard.index'));

        $pharmacy->refresh();
        $this->assertNotNull($pharmacy->profile_completed_at);
        $this->assertEquals('0599123456', $pharmacy->phone_number);
        $this->assertEquals('Rimal', $pharmacy->region);

        $user->refresh();
        $this->assertEquals('pharmacy@example.com', $user->email);
        $this->assertTrue(Hash::check('new-secure-password', $user->password));

        $this->assertDatabaseHas('pharmacy_hours', [
            'pharmacy_id' => $pharmacy->id,
            'day_of_week' => 'Saturday',
            'open_time' => '09:00',
            'close_time' => '17:00',
            'is_closed' => false,
        ]);
    }

    public function test_profile_completion_does_not_require_current_password(): void
    {
        $user = User::factory()->pharmacy()->create([
            'password' => Hash::make('PH-COMPL4'),
        ]);
        Pharmacy::factory()->create([
            'user_id' => $user->id,
            'profile_completed_at' => null,
        ]);

        // بدون حقل current_password إطلاقاً — يجب أن ينجح
        $response = $this->actingAs($user)->post(route('pharmacy.profile.complete'), [
            'phone_number' => '0599123456',
            'address' => 'Main St, Gaza',
            'region' => 'Rimal',
            'latitude' => 31.5,
            'longitude' => 34.4,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
            'hours' => [
                'Saturday' => ['open_time' => '09:00', 'close_time' => '17:00'],
            ],
        ]);

        $response->assertRedirect(route('pharmacy.dashboard.index'));
    }

    public function test_profile_completion_requires_at_least_one_open_day(): void
    {
        $user = User::factory()->pharmacy()->create([
            'password' => Hash::make('PH-COMPL2'),
        ]);
        Pharmacy::factory()->create([
            'user_id' => $user->id,
            'profile_completed_at' => null,
        ]);

        $response = $this->actingAs($user)->post(route('pharmacy.profile.complete'), [
            'phone_number' => '0599123456',
            'address' => 'Main St, Gaza',
            'region' => 'Rimal',
            'latitude' => 31.5,
            'longitude' => 34.4,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
            'hours' => [
                'Saturday' => ['is_closed' => true],
                'Sunday' => ['is_closed' => true],
            ],
        ]);

        $response->assertSessionHasErrors(['hours']);
    }

    public function test_profile_completion_email_is_optional(): void
    {
        $user = User::factory()->pharmacy()->create([
            'email' => null,
            'password' => Hash::make('PH-COMPL3'),
        ]);
        $pharmacy = Pharmacy::factory()->create([
            'user_id' => $user->id,
            'profile_completed_at' => null,
        ]);

        $response = $this->actingAs($user)->post(route('pharmacy.profile.complete'), [
            'phone_number' => '0599123456',
            'address' => 'Main St, Gaza',
            'region' => 'Rimal',
            'latitude' => 31.5,
            'longitude' => 34.4,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
            'hours' => [
                'Saturday' => ['open_time' => '09:00', 'close_time' => '17:00'],
            ],
        ]);

        $response->assertRedirect(route('pharmacy.dashboard.index'));
        $this->assertNull($user->fresh()->email);
        $this->assertNotNull($pharmacy->fresh()->profile_completed_at);
    }

    public function test_profile_completion_password_is_optional(): void
    {
        $user = User::factory()->pharmacy()->create([
            'password' => Hash::make('PH-COMPL5'),
        ]);
        $pharmacy = Pharmacy::factory()->create([
            'user_id' => $user->id,
            'profile_completed_at' => null,
        ]);

        // بدون حقول كلمة المرور إطلاقاً — كلمة المرور التي اختارتها الصيدلية عند
        // التسجيل تبقى كما هي، والإكمال ينجح
        $response = $this->actingAs($user)->post(route('pharmacy.profile.complete'), [
            'phone_number' => '0599123456',
            'address' => 'Main St, Gaza',
            'region' => 'Rimal',
            'latitude' => 31.5,
            'longitude' => 34.4,
            'hours' => [
                'Saturday' => ['open_time' => '09:00', 'close_time' => '17:00'],
            ],
        ]);

        $response->assertRedirect(route('pharmacy.dashboard.index'));

        $pharmacy->refresh();
        $this->assertNotNull($pharmacy->profile_completed_at);
        $this->assertTrue(Hash::check('PH-COMPL5', $user->fresh()->password), 'كلمة المرور الأصلية يجب أن تبقى دون تغيير');
    }

    public function test_profile_completion_password_change_still_works_when_provided(): void
    {
        $user = User::factory()->pharmacy()->create([
            'password' => Hash::make('PH-COMPL6'),
        ]);
        $pharmacy = Pharmacy::factory()->create([
            'user_id' => $user->id,
            'profile_completed_at' => null,
        ]);

        $this->actingAs($user)->post(route('pharmacy.profile.complete'), [
            'phone_number' => '0599123456',
            'address' => 'Main St, Gaza',
            'region' => 'Rimal',
            'latitude' => 31.5,
            'longitude' => 34.4,
            'password' => 'changed-in-completion',
            'password_confirmation' => 'changed-in-completion',
            'hours' => [
                'Saturday' => ['open_time' => '09:00', 'close_time' => '17:00'],
            ],
        ])->assertRedirect(route('pharmacy.dashboard.index'));

        $this->assertTrue(Hash::check('changed-in-completion', $user->fresh()->password));
    }

    public function test_profile_edit_all_fields_optional_and_empty_does_not_wipe_data(): void
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create([
            'user_id' => $user->id,
            'phone_number' => '0599000000',
            'address' => 'Old Address',
            'region' => 'Old Region',
        ]);

        // إرسال حقول فارغة فقط — يجب ألا تُمسح البيانات الموجودة
        $response = $this->actingAs($user)->put(route('pharmacy.profile.update'), [
            'pharmacy_name' => '',
            'phone_number' => '',
            'address' => '',
            'region' => '',
            'latitude' => '',
            'longitude' => '',
        ]);

        $response->assertRedirect(route('pharmacy.profile.edit'));

        $pharmacy->refresh();
        $this->assertEquals('0599000000', $pharmacy->phone_number);
        $this->assertEquals('Old Address', $pharmacy->address);
        $this->assertEquals('Old Region', $pharmacy->region);
    }

    public function test_profile_edit_updates_only_filled_fields(): void
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create([
            'user_id' => $user->id,
            'phone_number' => '0599000000',
            'address' => 'Old Address',
            'region' => 'Old Region',
        ]);

        $response = $this->actingAs($user)->put(route('pharmacy.profile.update'), [
            'phone_number' => '0599111222',
        ]);

        $response->assertRedirect(route('pharmacy.profile.edit'));

        $pharmacy->refresh();
        $this->assertEquals('0599111222', $pharmacy->phone_number);
        $this->assertEquals('Old Address', $pharmacy->address);
    }

    /**
     * 🔴 انحدار حرج: النموذج كان **لا يمكن إرساله أبداً** من المتصفح.
     *
     * السبب: latitude/longitude كانا `required`، والكاتب الوحيد للحقلين هو
     * applyChange() في pharmacy_hub.js — ولا تُستدعى إلا من داخل openLocationModal()
     * التي تخرج فوراً لأن #locationModal غائب عن complete.blade.php (موجود في
     * edit.blade.php فقط). ⇒ الإرسال يفشل دائماً ⇒ profile_completed_at لا يُضبط
     * ⇒ حلقة إعادة توجيه لا نهائية تحبس الصيدلية.
     *
     * هذا الاختبار يحاكي **ما يرسله المتصفح فعلاً** — بلا إحداثيات.
     */
    public function test_completion_succeeds_without_coordinates_as_the_browser_submits(): void
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create([
            'user_id' => $user->id,
            'profile_completed_at' => null,
            'latitude' => null,
            'longitude' => null,
        ]);

        $response = $this->actingAs($user)->post(route('pharmacy.profile.complete'), [
            'phone_number' => '0599123456',
            'address' => 'Main St, Gaza',
            'region' => 'Rimal',
            // لا latitude ولا longitude — تماماً كما يرسل المتصفح
            'hours' => [
                'Saturday' => ['open_time' => '09:00', 'close_time' => '17:00'],
                'Sunday' => ['open_time' => '09:00', 'close_time' => '17:00'],
                'Monday' => ['is_closed' => true],
                'Tuesday' => ['is_closed' => true],
                'Wednesday' => ['is_closed' => true],
                'Thursday' => ['is_closed' => true],
                'Friday' => ['is_closed' => true],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('pharmacy.dashboard.index'));

        $pharmacy->refresh();
        // الحقل الحاسم: بدون ضبطه تبقى الصيدلية محبوسة للأبد
        $this->assertNotNull($pharmacy->profile_completed_at);
        // وقعنا على مركز الخريطة الافتراضي بدل ترك الحقل فارغاً
        $this->assertEquals(31.5016, (float) $pharmacy->latitude);
        $this->assertEquals(34.4668, (float) $pharmacy->longitude);
    }

    /**
     * وبعد الإكمال، اللوحة متاحة فعلاً (لا إعادة توجيه).
     */
    public function test_dashboard_is_reachable_after_completing_without_coordinates(): void
    {
        $user = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create([
            'user_id' => $user->id,
            'profile_completed_at' => null,
            'latitude' => null,
            'longitude' => null,
        ]);

        $this->actingAs($user)->post(route('pharmacy.profile.complete'), [
            'phone_number' => '0599123456',
            'address' => 'Main St, Gaza',
            'region' => 'Rimal',
            'hours' => [
                'Saturday' => ['open_time' => '09:00', 'close_time' => '17:00'],
            ],
        ]);

        $this->actingAs($user->fresh())
            ->get(route('pharmacy.dashboard.index'))
            ->assertOk();
    }

    /**
     * تحصين مسبق: الحقلان المخفيان يجب أن يحملا قيمة ابتدائية صالحة،
     * حتى لو فشل تحميل الخريطة (unpkg محجوب مثلاً) يبقى النموذج قابلاً للإرسال.
     */
    public function test_completion_view_renders_non_empty_coordinate_inputs(): void
    {
        $user = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create([
            'user_id' => $user->id,
            'profile_completed_at' => null,
        ]);

        $html = $this->actingAs($user)
            ->get(route('pharmacy.profile.complete.show'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            "/name='latitude'\s+id='latitude'\s+value='31\.5016'/",
            $html,
            'حقل latitude المخفي يجب أن يحمل القيمة الافتراضية'
        );
        $this->assertMatchesRegularExpression(
            "/name='longitude'\s+id='longitude'\s+value='34\.4668'/",
            $html,
            'حقل longitude المخفي يجب أن يحمل القيمة الافتراضية'
        );
    }
}