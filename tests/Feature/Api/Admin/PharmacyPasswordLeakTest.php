<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Pharmacy;
use App\Models\User;
use App\Services\PharmacyRegistrationService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regression for P0.4.
 *
 * `pharmacies.pending_password` holds a reversibly-encrypted copy of the
 * pharmacy password until it is handed to the owner once. `Pharmacy` had no
 * `$hidden`, and both `Admin\PharmacyController@index/show` return the raw model
 * — so the ciphertext shipped to the admin list, the admin detail page, and the
 * public pharmacy endpoint.
 *
 * The delivery design itself is intentionally unchanged: the plaintext is still
 * revealed exactly once, then the column is cleared.
 */
class PharmacyPasswordLeakTest extends TestCase
{
    private string $plainPassword = 'Temp-Pass-9f3x';

    private function admin(): User
    {
        $admin = User::factory()->admin()->create(['must_change_password' => false]);
        $admin->syncRoles(['admin']);

        return $admin;
    }

    private function pharmacyWithPendingPassword(): Pharmacy
    {
        $user = User::factory()->pharmacy()->create(['password' => Hash::make('Owner-Chosen-123')]);

        $pharmacy = new Pharmacy;
        $pharmacy->pharmacy_name = 'صيدلية الاختبار';
        $pharmacy->region = 'غزة';
        $pharmacy->phone_number = '0599888777';
        $pharmacy->user_id = $user->id;
        $pharmacy->pharmacy_custom_id = 'PH-LEAK-1';
        $pharmacy->is_active = true;
        $pharmacy->save();
        $pharmacy->pending_password = Crypt::encryptString($this->plainPassword);
        $pharmacy->save();

        return $pharmacy->fresh();
    }

    private function assertNoSecretIn(string $content, Pharmacy $pharmacy): void
    {
        $this->assertStringNotContainsString('pending_password', $content);
        $this->assertStringNotContainsString($this->plainPassword, $content);
        $this->assertStringNotContainsString(
            (string) $pharmacy->getAttributes()['pending_password'],
            $content,
            'the ciphertext must not leak either'
        );
    }

    public function test_the_admin_pharmacy_list_does_not_expose_the_pending_password(): void
    {
        $pharmacy = $this->pharmacyWithPendingPassword();
        $this->actingAs($this->admin(), 'sanctum');

        $response = $this->getJson('/api/admin/pharmacies')->assertOk();
        $this->assertNoSecretIn($response->getContent(), $pharmacy);
    }

    public function test_the_admin_pharmacy_detail_does_not_expose_the_pending_password(): void
    {
        $pharmacy = $this->pharmacyWithPendingPassword();
        $this->actingAs($this->admin(), 'sanctum');

        $response = $this->getJson('/api/admin/pharmacies/'.$pharmacy->id)->assertOk();
        $this->assertNoSecretIn($response->getContent(), $pharmacy);
    }

    public function test_the_public_pharmacy_endpoint_does_not_expose_the_pending_password(): void
    {
        $pharmacy = $this->pharmacyWithPendingPassword();

        $response = $this->getJson('/api/pharmacies/'.$pharmacy->id)->assertOk();
        $this->assertNoSecretIn($response->getContent(), $pharmacy);
    }

    public function test_the_pharmacy_profile_does_not_expose_the_pending_password(): void
    {
        $pharmacy = $this->pharmacyWithPendingPassword();
        $this->actingAs($pharmacy->user, 'sanctum');

        $response = $this->getJson('/api/profile/pharmacy')->assertOk();
        $this->assertNoSecretIn($response->getContent(), $pharmacy);
    }

    public function test_the_model_serialisation_hides_the_column(): void
    {
        $pharmacy = $this->pharmacyWithPendingPassword();

        $this->assertArrayNotHasKey('pending_password', $pharmacy->toArray());
        $this->assertArrayHasKey('pending_password', $pharmacy->getAttributes());
        $this->assertNotSame($this->plainPassword, $pharmacy->getAttributes()['pending_password']);
    }

    public function test_delivery_still_hands_the_password_over_exactly_once(): void
    {
        $pharmacy = $this->pharmacyWithPendingPassword();
        $service = app(PharmacyRegistrationService::class);

        $first = $service->deliver($pharmacy);
        $this->assertSame($this->plainPassword, $first['password']);
        $this->assertSame('PH-LEAK-1', $first['pharmacy_id']);

        // مرة ثانية: لا إعادة تسليم.
        $this->assertNull($service->deliver($pharmacy->fresh()));

        // العمود مُصفَّر — لا تبقى نسخة قابلة للفك.
        $this->assertNull(DB::table('pharmacies')->where('id', $pharmacy->id)->value('pending_password'));
    }

    public function test_the_stored_password_is_hashed_not_recoverable(): void
    {
        $pharmacy = $this->pharmacyWithPendingPassword();
        $hash = DB::table('users')->where('id', $pharmacy->user_id)->value('password');

        $this->assertNotSame('Owner-Chosen-123', $hash);
        $this->assertTrue(Hash::check('Owner-Chosen-123', $hash));
        $this->assertStringStartsWith('$2y$', $hash);
    }
}
