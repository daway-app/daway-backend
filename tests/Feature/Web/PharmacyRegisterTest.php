<?php

namespace Tests\Feature\Web;

use App\Models\Pharmacy;
use App\Models\User;
use Tests\TestCase;

/**
 * صفحة إنشاء حساب الصيدلية على الويب.
 */
class PharmacyRegisterTest extends TestCase
{
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'pharmacy_name' => 'صيدلية الشفاء',
            'phone' => '0598765432',
            'region' => 'الشجاعية',
        ], $overrides);
    }

    public function test_register_page_loads(): void
    {
        $this->get(route('register.show'))
            ->assertOk()
            ->assertSee('إنشاء حساب صيدلية');
    }

    public function test_login_page_links_to_register_page(): void
    {
        $this->get(route('login.show'))
            ->assertOk()
            ->assertSee(route('register.show'), false);
    }

    public function test_pharmacy_can_register_from_web(): void
    {
        $response = $this->post(route('register'), $this->validData());

        // بعد التسجيل → redirect لصفحة الدخول مع رسالة انتظار (بلا ID)
        $response->assertRedirect(route('login.show'));
        $response->assertSessionHas('register_pending_notice');

        $pharmacy = Pharmacy::where('phone_number', '0598765432')->first();

        $this->assertNotNull($pharmacy);
        $this->assertSame('صيدلية الشفاء', $pharmacy->pharmacy_name);
        // الشارع لم يعد يُجمع في التسجيل — يُكمله صاحب الصيدلية من ملفه لاحقاً
        $this->assertNull($pharmacy->address);
        $this->assertSame('الشجاعية', $pharmacy->region);
        $this->assertSame('0598765432', $pharmacy->phone_number);
        $this->assertFalse((bool) $pharmacy->is_active);
        $this->assertNull($pharmacy->delivered_at);

        $user = $pharmacy->user;
        $this->assertNotNull($user);
        $this->assertSame('pharmacy', $user->role);
        $this->assertFalse((bool) $user->is_active);
        $this->assertTrue($user->hasRole('pharmacy'));
    }

    public function test_registration_does_not_log_the_pharmacy_in(): void
    {
        $this->post(route('register'), $this->validData());

        $this->assertGuest();
    }

    public function test_login_page_shows_pending_notice_after_registration(): void
    {
        $this->post(route('register'), $this->validData());

        $this->get(route('login.show'))
            ->assertOk()
            ->assertSee('بانتظار موافقة الإدارة');
    }

    public function test_login_page_does_not_show_id(): void
    {
        $this->post(route('register'), $this->validData());

        $pharmacy = Pharmacy::where('phone_number', '0598765432')->firstOrFail();

        $this->get(route('login.show'))
            ->assertOk()
            ->assertDontSee($pharmacy->pharmacy_custom_id);
    }

    public function test_duplicate_phone_fails(): void
    {
        User::factory()->patient()->create(['phone' => '0598765432']);

        $this->from(route('register.show'))
            ->post(route('register'), $this->validData())
            ->assertSessionHasErrors('phone');

        $this->assertDatabaseMissing('pharmacies', ['pharmacy_name' => 'صيدلية الشفاء']);
    }

    public function test_missing_required_fields_fail(): void
    {
        $this->from(route('register.show'))
            ->post(route('register'), [])
            ->assertSessionHasErrors([
                'pharmacy_name',
                'phone',
                'region',
            ]);
    }
}
