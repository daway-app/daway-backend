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
            'phone_number' => '0598765432',
            'region' => 'الشجاعية',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
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

        $response->assertRedirect(route('register.success'));
        $response->assertSessionHas('registered_pharmacy_id');

        $pharmacyCustomId = $response->getSession()->get('registered_pharmacy_id');
        $this->assertMatchesRegularExpression('/^PH-[A-Z0-9]{4}$/', $pharmacyCustomId);

        $pharmacy = Pharmacy::where('pharmacy_custom_id', $pharmacyCustomId)->first();

        $this->assertNotNull($pharmacy);
        $this->assertSame('صيدلية الشفاء', $pharmacy->pharmacy_name);
        // الشارع لم يعد يُجمع في التسجيل — يُكمله صاحب الصيدلية من ملفه لاحقاً
        $this->assertNull($pharmacy->address);
        $this->assertSame('الشجاعية', $pharmacy->region);
        $this->assertSame('0598765432', $pharmacy->phone_number);
        $this->assertFalse((bool) $pharmacy->is_active);

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

    public function test_success_page_shows_pharmacy_id(): void
    {
        $response = $this->post(route('register'), $this->validData());
        $pharmacyCustomId = $response->getSession()->get('registered_pharmacy_id');

        $this->withSession([
            'registered_pharmacy_id' => $pharmacyCustomId,
            'registered_pharmacy_name' => 'صيدلية الشفاء',
        ])
            ->get(route('register.success'))
            ->assertOk()
            ->assertSee($pharmacyCustomId)
            ->assertSee('موافقة الإدارة');
    }

    public function test_success_page_redirects_without_session(): void
    {
        $this->get(route('register.success'))
            ->assertRedirect(route('register.show'));
    }

    public function test_password_mismatch_is_no_longer_a_concept(): void
    {
        // تأكيد كلمة المرور أُزيل من النموذج — أي قيمة مرسلة تُتجاهل ولا يوجد حقل errors
        $this->from(route('register.show'))
            ->post(route('register'), $this->validData([
                'password_confirmation' => 'nope12345',
            ]))
            ->assertRedirect(route('register.success'));

        $this->assertDatabaseHas('users', ['phone' => '0598765432']);
    }

    public function test_duplicate_phone_fails(): void
    {
        User::factory()->patient()->create(['phone' => '0598765432']);

        $this->from(route('register.show'))
            ->post(route('register'), $this->validData())
            ->assertSessionHasErrors('phone_number');

        $this->assertDatabaseMissing('pharmacies', ['pharmacy_name' => 'صيدلية الشفاء']);
    }

    public function test_missing_required_fields_fail(): void
    {
        $this->from(route('register.show'))
            ->post(route('register'), [])
            ->assertSessionHasErrors([
                'pharmacy_name',
                'phone_number',
                'region',
                'password',
            ]);
    }
}
