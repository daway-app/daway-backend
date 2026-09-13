<?php

namespace Tests\Feature\Api;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * التسجيل الذاتي للصيدليات عبر API الموبايل.
 *
 * الحساب يُنشأ غير مفعّل (بانتظار موافقة الأدمن) ويُعاد Pharmacy ID للدخول لاحقاً.
 */
class PharmacyRegistrationTest extends TestCase
{
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'pharmacy_name' => 'صيدلية النور',
            'phone_number' => '0599123456',
            'address' => 'شارع عمر المختار',
            'region' => 'الرمال',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ], $overrides);
    }

    public function test_pharmacy_can_register_and_receives_pharmacy_id(): void
    {
        $response = $this->postJson('/api/register/pharmacy', $this->validData());

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.pharmacy_name', 'صيدلية النور')
            ->assertJsonPath('data.phone_number', '0599123456');

        $this->assertMatchesRegularExpression(
            '/^PH-[A-Z0-9]{4}$/',
            $response->json('data.pharmacy_id'),
            'معرّف الصيدلية يجب أن يكون بصيغة PH-XXXX'
        );
    }

    public function test_registration_creates_inactive_pharmacy_account(): void
    {
        $pharmacyCustomId = $this->postJson('/api/register/pharmacy', $this->validData())
            ->json('data.pharmacy_id');

        $user = User::where('phone', '0599123456')->first();

        $this->assertNotNull($user);
        $this->assertSame('pharmacy', $user->role);
        $this->assertFalse((bool) $user->is_active, 'الحساب يبقى غير مفعّل حتى موافقة الأدمن');
        $this->assertFalse((bool) $user->must_change_password, 'الصيدلية اختارت كلمة المرور بنفسها');
        $this->assertTrue($user->hasRole('pharmacy'), 'دور Spatie يجب أن يُضبط مع enum role');
        $this->assertTrue(Hash::check('secret1234', $user->password));

        $pharmacy = Pharmacy::where('pharmacy_custom_id', $pharmacyCustomId)->first();

        $this->assertNotNull($pharmacy);
        $this->assertSame($user->id, $pharmacy->user_id);
        $this->assertSame('صيدلية النور', $pharmacy->pharmacy_name);
        $this->assertSame('شارع عمر المختار', $pharmacy->address);
        $this->assertSame('الرمال', $pharmacy->region);
        $this->assertSame('0599123456', $pharmacy->phone_number);
        $this->assertFalse((bool) $pharmacy->is_active);
        $this->assertNull($pharmacy->profile_completed_at, 'إكمال الملف يُطلب أول دخول بعد الموافقة');
    }

    public function test_duplicate_phone_is_rejected(): void
    {
        User::factory()->patient()->create(['phone' => '0599123456']);

        $this->postJson('/api/register/pharmacy', $this->validData())
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['phone_number']);

        $this->assertDatabaseMissing('pharmacies', ['pharmacy_name' => 'صيدلية النور']);
    }

    public function test_password_confirmation_must_match(): void
    {
        $this->postJson('/api/register/pharmacy', $this->validData([
            'password_confirmation' => 'different123',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->assertDatabaseMissing('users', ['phone' => '0599123456']);
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        $this->postJson('/api/register/pharmacy', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors([
                'pharmacy_name',
                'phone_number',
                'address',
                'region',
                'password',
            ]);
    }

    public function test_pending_pharmacy_cannot_login_before_approval(): void
    {
        $pharmacyCustomId = $this->postJson('/api/register/pharmacy', $this->validData())
            ->json('data.pharmacy_id');

        $this->postJson('/api/login/pharmacy', [
            'pharmacy_id' => $pharmacyCustomId,
            'password' => 'secret1234',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_inactive');
    }

    public function test_pending_pharmacy_can_login_after_admin_approval(): void
    {
        $pharmacyCustomId = $this->postJson('/api/register/pharmacy', $this->validData())
            ->json('data.pharmacy_id');

        // نفس ما يفعله الأدمن من pharmacies.toggleStatus
        $pharmacy = Pharmacy::where('pharmacy_custom_id', $pharmacyCustomId)->firstOrFail();
        $pharmacy->is_active = true;
        $pharmacy->save();
        $pharmacy->user->is_active = true;
        $pharmacy->user->save();

        $this->postJson('/api/login/pharmacy', [
            'pharmacy_id' => $pharmacyCustomId,
            'password' => 'secret1234',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.pharmacy_id', $pharmacyCustomId);
    }

    public function test_pharmacy_ids_are_unique_across_registrations(): void
    {
        $first = $this->postJson('/api/register/pharmacy', $this->validData())
            ->json('data.pharmacy_id');

        $second = $this->postJson('/api/register/pharmacy', $this->validData([
            'phone_number' => '0599000000',
        ]))->json('data.pharmacy_id');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
    }
}
