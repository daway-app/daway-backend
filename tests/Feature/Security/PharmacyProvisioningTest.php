<?php

namespace Tests\Feature\Security;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * M-4: تزويد الصيدليات من الأدمن — كلمة مرور أولية عشوائية (غير مساوية للمعرف)
 * مع flash في الجلسة وإلزام تغييرها عند أول دخول.
 */
class PharmacyProvisioningTest extends TestCase
{
    public function test_admin_provisions_pharmacy_with_initial_password_flash(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('pharmacies.store'), [
            'pharmacy_name' => 'Provision Test Pharmacy',
        ]);

        $response->assertRedirect(route('pharmacies.index'));
        $response->assertSessionHas('initial_password');

        $initialPassword = $response->getSession()->get('initial_password');
        $this->assertNotEmpty($initialPassword);

        $pharmacy = Pharmacy::where('pharmacy_name', 'Provision Test Pharmacy')->first();
        $this->assertNotNull($pharmacy);

        // كلمة المرور الأولية ليست معرّف الصيدلية (لا يمكن تخمينها من الـ ID)
        $this->assertNotSame($initialPassword, $pharmacy->pharmacy_custom_id);

        $user = $pharmacy->user;
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check($initialPassword, $user->password));

        // إلزام تغيير كلمة المرور عند أول تسجيل دخول
        $this->assertTrue((bool) $user->must_change_password);
    }

    public function test_provisioned_pharmacy_custom_id_matches_format(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('pharmacies.store'), [
            'pharmacy_name' => 'Format Check Pharmacy',
        ])->assertRedirect(route('pharmacies.index'));

        $pharmacy = Pharmacy::where('pharmacy_name', 'Format Check Pharmacy')->first();
        $this->assertNotNull($pharmacy);
        $this->assertMatchesRegularExpression('/^PH-[A-Z0-9]{4}$/', $pharmacy->pharmacy_custom_id);
    }
}
