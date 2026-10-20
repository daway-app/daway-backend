<?php

namespace Tests\Feature\Api\Admin;

use App\Models\DeliveryFeeTier;
use App\Models\User;
use App\Services\DeliveryPricingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * لوحة الأدمن API — إعدادات وضع التسعير (السعر الأساسي / سعر الكيلومتر).
 *
 * يحرس العقود التالية:
 *   1. الضبط ي persisted ويظهر في `GET /delivery-pricing`.
 *   2. الوضع الافتراضي `tiers` بلا سعر أساسي ولا سعر كيلومتر.
 *   3. التفعيل في وضع `per_km` يتطلب سعر كيلومتر، ويُرفض 422 بلا كتابة.
 *   4. التبديل إلى `per_km` أثناء التفعيل بلا سعر يُرفض ولا يغيّر الوضع.
 *   5. التحقق من صحة المدخلات (وضع غير معروف، قيم سالبة).
 *   6. المسارات محميّة بـ`role:admin` (وغير المصادق 401).
 */
class DeliveryPricingSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role).' User',
            'email' => $role.'-'.uniqid().'@test.com',
            'phone' => '0599'.random_int(100000, 999999),
            'password' => Hash::make('password123'),
        ]);
        $user->role = $role;
        $user->is_active = true;
        $user->save();
        $user->syncRoles([$role]);

        return $user;
    }

    private function admin(): User
    {
        return $this->userWithRole('admin');
    }

    private function tier(int $min, ?int $max, float $fee = 10.0, bool $active = true): DeliveryFeeTier
    {
        return DeliveryFeeTier::create([
            'min_distance_meters' => $min,
            'max_distance_meters' => $max,
            'fee' => $fee,
            'is_active' => $active,
        ]);
    }

    private function setSettings(User $admin, array $body)
    {
        return $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/delivery-pricing/settings', $body);
    }

    private function indexPath(User $admin, string $key)
    {
        return $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/delivery-pricing')
            ->json($key);
    }

    // ══════════════════════════════════════════════════════════════════
    // Defaults + persistence
    // ══════════════════════════════════════════════════════════════════

    public function test_index_defaults_to_tiers_mode_with_no_base_or_rate(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/delivery-pricing')
            ->assertOk()
            ->assertJsonPath('data.mode', DeliveryPricingService::MODE_TIERS)
            ->assertJsonPath('data.base_fee', null)
            ->assertJsonPath('data.per_km_rate', null);
    }

    public function test_settings_are_persisted_and_reflected_by_index(): void
    {
        $admin = $this->admin();

        $this->setSettings($admin, [
            'mode' => DeliveryPricingService::MODE_PER_KM,
            'base_fee' => 8.5,
            'per_km_rate' => 2.25,
        ])->assertOk()
            ->assertJsonPath('data.mode', DeliveryPricingService::MODE_PER_KM)
            ->assertJsonPath('data.base_fee', 8.5)
            ->assertJsonPath('data.per_km_rate', 2.25);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/delivery-pricing')
            ->assertOk()
            ->assertJsonPath('data.mode', DeliveryPricingService::MODE_PER_KM)
            ->assertJsonPath('data.base_fee', 8.5)
            ->assertJsonPath('data.per_km_rate', 2.25);

        $this->assertDatabaseHas('settings', ['key' => 'delivery_pricing_mode', 'value' => 'per_km']);
        $this->assertDatabaseHas('settings', ['key' => 'delivery_base_fee', 'value' => '8.5']);
        $this->assertDatabaseHas('settings', ['key' => 'delivery_per_km_rate', 'value' => '2.25']);
    }

    public function test_partial_update_leaves_other_fields_untouched(): void
    {
        $admin = $this->admin();

        $this->setSettings($admin, ['mode' => DeliveryPricingService::MODE_PER_KM, 'per_km_rate' => 3.0])->assertOk();

        $this->setSettings($admin, ['base_fee' => 12.0])->assertOk();

        $this->assertSame(DeliveryPricingService::MODE_PER_KM, $this->indexPath($admin, 'data.mode'));
        $this->assertSame(3.0, (float) $this->indexPath($admin, 'data.per_km_rate'));
        $this->assertSame(12.0, (float) $this->indexPath($admin, 'data.base_fee'));
    }

    // ══════════════════════════════════════════════════════════════════
    // Enabling in per_km mode
    // ══════════════════════════════════════════════════════════════════

    public function test_enabling_per_km_without_a_rate_is_rejected(): void
    {
        $admin = $this->admin();

        $this->setSettings($admin, ['mode' => DeliveryPricingService::MODE_PER_KM])->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/delivery-pricing/status', ['enabled' => true])
            ->assertStatus(422);

        $this->assertDatabaseMissing('settings', ['key' => 'delivery_pricing_enabled', 'value' => '1']);
    }

    public function test_enabling_per_km_with_a_rate_succeeds_without_any_tiers(): void
    {
        $admin = $this->admin();

        $this->setSettings($admin, ['mode' => DeliveryPricingService::MODE_PER_KM, 'per_km_rate' => 2.0])->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/delivery-pricing/status', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->assertSame(0, DeliveryFeeTier::query()->count());
        $this->assertTrue(DeliveryPricingService::isEnabled());
    }

    // ══════════════════════════════════════════════════════════════════
    // Zero / negative per_km rate cannot enable per_km
    // ══════════════════════════════════════════════════════════════════

    public function test_enabling_per_km_with_a_zero_rate_is_rejected(): void
    {
        $admin = $this->admin();

        // حفظ إعداد ناقص (وضع per_km بمعدل 0) مسموح ما دام التسعير معطّلًا…
        $this->setSettings($admin, ['mode' => DeliveryPricingService::MODE_PER_KM, 'per_km_rate' => 0])
            ->assertOk();

        // …لكن التفعيل بمعدل 0 مرفوض، ولا يُكتب أي تفعيل.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/delivery-pricing/status', ['enabled' => true])
            ->assertStatus(422);

        $this->assertFalse(DeliveryPricingService::isEnabled());
        $this->assertDatabaseMissing('settings', ['key' => 'delivery_pricing_enabled', 'value' => '1']);
    }

    public function test_enabling_per_km_with_a_negative_rate_is_rejected(): void
    {
        $admin = $this->admin();

        $this->setSettings($admin, ['mode' => DeliveryPricingService::MODE_PER_KM, 'per_km_rate' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_km_rate']);

        // لا كتابة جزئية: الوضع بقي الافتراضي ولم يُخزَّن أي معدل.
        $this->assertSame(DeliveryPricingService::MODE_TIERS, $this->indexPath($admin, 'data.mode'));
        $this->assertDatabaseMissing('settings', ['key' => 'delivery_per_km_rate']);
        $this->assertFalse(DeliveryPricingService::isEnabled());
    }

    public function test_zero_rate_may_be_saved_while_disabled_but_cannot_be_enabled(): void
    {
        $admin = $this->admin();

        $this->setSettings($admin, ['per_km_rate' => 0])->assertOk();

        $this->assertFalse(DeliveryPricingService::isEnabled());
        $this->assertSame(0.0, (float) $this->indexPath($admin, 'data.per_km_rate'));
    }

    public function test_rejected_settings_update_while_enabled_persists_nothing(): void
    {
        $admin = $this->admin();

        $this->setSettings($admin, ['mode' => DeliveryPricingService::MODE_PER_KM, 'per_km_rate' => 5.0])
            ->assertOk();
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/delivery-pricing/status', ['enabled' => true])
            ->assertOk();

        // محاولة جعل المعدل 0 بينما per_km مفعّل ⇒ 422 بلا أي كتابة.
        $this->setSettings($admin, ['mode' => DeliveryPricingService::MODE_TIERS, 'per_km_rate' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['settings']);

        $this->assertSame(DeliveryPricingService::MODE_PER_KM, $this->indexPath($admin, 'data.mode'));
        $this->assertSame(5.0, (float) $this->indexPath($admin, 'data.per_km_rate'));
        $this->assertTrue(DeliveryPricingService::isEnabled());
    }

    // ══════════════════════════════════════════════════════════════════
    // Switching mode while enabled
    // ══════════════════════════════════════════════════════════════════

    public function test_switching_to_per_km_while_enabled_without_a_rate_is_rejected_and_keeps_mode(): void
    {
        $admin = $this->admin();

        $this->tier(0, null, 5.0);
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/delivery-pricing/status', ['enabled' => true])
            ->assertOk();

        $this->setSettings($admin, ['mode' => DeliveryPricingService::MODE_PER_KM])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['settings']);

        // الوضع لم يتغيّر: لا كتابة جزئية عند الرفض.
        $this->assertSame(DeliveryPricingService::MODE_TIERS, $this->indexPath($admin, 'data.mode'));
    }

    public function test_switching_to_per_km_while_enabled_with_a_rate_succeeds(): void
    {
        $admin = $this->admin();

        $this->tier(0, null, 5.0);
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/delivery-pricing/status', ['enabled' => true])
            ->assertOk();

        $this->setSettings($admin, ['mode' => DeliveryPricingService::MODE_PER_KM, 'per_km_rate' => 4.0])
            ->assertOk()
            ->assertJsonPath('data.mode', DeliveryPricingService::MODE_PER_KM);

        $this->assertSame(DeliveryPricingService::MODE_PER_KM, $this->indexPath($admin, 'data.mode'));
    }

    // ══════════════════════════════════════════════════════════════════
    // Validation + authorization
    // ══════════════════════════════════════════════════════════════════

    public function test_settings_validation_rejects_bad_input(): void
    {
        $admin = $this->admin();

        $this->setSettings($admin, ['mode' => 'bogus'])->assertStatus(422);
        $this->setSettings($admin, ['base_fee' => -1])->assertStatus(422);
        $this->setSettings($admin, ['per_km_rate' => -2])->assertStatus(422);
    }

    public function test_guest_cannot_access_settings(): void
    {
        $this->postJson('/api/admin/delivery-pricing/settings', ['mode' => 'per_km'])
            ->assertStatus(401);
    }

    public function test_non_admin_cannot_access_settings(): void
    {
        $pharmacy = $this->userWithRole('pharmacy');

        $this->actingAs($pharmacy, 'sanctum')
            ->postJson('/api/admin/delivery-pricing/settings', ['mode' => 'per_km'])
            ->assertStatus(403);
    }
}
