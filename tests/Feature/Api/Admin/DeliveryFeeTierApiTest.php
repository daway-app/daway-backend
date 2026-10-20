<?php

namespace Tests\Feature\Api\Admin;

use App\Http\Controllers\Api\Admin\DeliveryFeeTierController;
use App\Models\DeliveryFeeTier;
use App\Models\User;
use App\Services\DeliveryPricingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * لوحة الأدمن API — شرائح رسوم التوصيل (Phase 1).
 *
 * يحرس العقود التالية:
 *   1. لا أسعار مبدئية: شريحة جديدة تُنشأ **معطّلة** افتراضيًا.
 *   2. مجموعة الشرائح الفعّالة تبقى سليمة (تبدأ من 0، بلا تداخل/فجوات) — أي
 *      كتابة تُخلّ بذلك تُرفض 422 ولا تُكتب.
 *   3. تفعيل التسعير يتطلب مجموعة سليمة؛ ولا يمكن تفعيله بلا شرائح.
 *   4. المسارات محميّة بـ`role:admin` (وغير المصادق 401).
 */
class DeliveryFeeTierApiTest extends TestCase
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

    // ══════════════════════════════════════════════════════════════════
    // Index
    // ══════════════════════════════════════════════════════════════════

    public function test_index_starts_empty_and_disabled(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/delivery-pricing')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.tiers', []);
    }

    // ══════════════════════════════════════════════════════════════════
    // Create
    // ══════════════════════════════════════════════════════════════════

    public function test_store_creates_an_inactive_tier_by_default(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/delivery-pricing/tiers', [
                'min_distance_meters' => 0,
                'max_distance_meters' => 1000,
                'fee' => 5,
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tier.is_active', false);

        $this->assertDatabaseHas('delivery_fee_tiers', [
            'min_distance_meters' => 0,
            'max_distance_meters' => 1000,
            'is_active' => false,
        ]);
    }

    public function test_store_accepts_a_valid_active_first_tier(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/delivery-pricing/tiers', [
                'min_distance_meters' => 0,
                'max_distance_meters' => null,
                'fee' => 7.5,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.tier.is_active', true);
    }

    public function test_store_rejects_an_active_tier_that_does_not_start_at_zero(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/delivery-pricing/tiers', [
                'min_distance_meters' => 500,
                'max_distance_meters' => null,
                'fee' => 7.5,
                'is_active' => true,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('delivery_fee_tiers', 0);
    }

    public function test_store_rejects_an_active_tier_creating_an_overlap(): void
    {
        $this->tier(0, 1000);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/delivery-pricing/tiers', [
                'min_distance_meters' => 900,
                'max_distance_meters' => null,
                'fee' => 5,
                'is_active' => true,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('delivery_fee_tiers', 1);
    }

    public function test_store_rejects_an_active_tier_creating_a_gap(): void
    {
        $this->tier(0, 1000);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/delivery-pricing/tiers', [
                'min_distance_meters' => 1002,
                'max_distance_meters' => null,
                'fee' => 5,
                'is_active' => true,
            ])
            ->assertStatus(422);
    }

    public function test_store_allows_a_gap_for_an_inactive_draft_tier(): void
    {
        $this->tier(0, 1000);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/delivery-pricing/tiers', [
                'min_distance_meters' => 2000,
                'max_distance_meters' => 3000,
                'fee' => 5,
                'is_active' => false,
            ])
            ->assertCreated();
    }

    public function test_store_rejects_a_negative_fee(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/delivery-pricing/tiers', [
                'min_distance_meters' => 0,
                'max_distance_meters' => 1000,
                'fee' => -1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fee']);
    }

    public function test_store_rejects_max_below_min(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/delivery-pricing/tiers', [
                'min_distance_meters' => 1000,
                'max_distance_meters' => 500,
                'fee' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_distance_meters']);
    }

    public function test_store_without_the_max_field_creates_an_open_ended_tier(): void
    {
        // `max_distance_meters` اختياري: غيابه يجب أن يُنشئ شريحة مفتوحة، لا 500.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/delivery-pricing/tiers', [
                'min_distance_meters' => 0,
                'fee' => 5,
            ])
            ->assertCreated()
            ->assertJsonPath('data.tier.max_distance_meters', null);

        $this->assertDatabaseHas('delivery_fee_tiers', [
            'min_distance_meters' => 0,
            'max_distance_meters' => null,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // Update / toggle / delete
    // ══════════════════════════════════════════════════════════════════

    public function test_update_changes_a_tier(): void
    {
        $tier = $this->tier(0, 1000, 5.0);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/admin/delivery-pricing/tiers/{$tier->id}", [
                'min_distance_meters' => 0,
                'max_distance_meters' => 1000,
                'fee' => 12.5,
            ])
            ->assertOk()
            ->assertJsonPath('data.tier.fee', 12.5);
    }

    public function test_update_deactivating_a_middle_tier_that_creates_a_gap_is_rejected(): void
    {
        $this->tier(0, 1000);
        $middle = $this->tier(1001, 2000);
        $this->tier(2001, null);

        // التعطيل عبر PUT يجب أن يخضع لنفس فحص الفجوة الذي يفرضه toggle-status.
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/admin/delivery-pricing/tiers/{$middle->id}", [
                'min_distance_meters' => 1001,
                'max_distance_meters' => 2000,
                'fee' => 10,
                'is_active' => false,
            ])
            ->assertStatus(422);

        $this->assertTrue($middle->fresh()->is_active);
    }

    public function test_update_deactivating_the_last_tier_is_allowed(): void
    {
        $this->tier(0, 1000);
        $last = $this->tier(1001, null);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/admin/delivery-pricing/tiers/{$last->id}", [
                'min_distance_meters' => 1001,
                'max_distance_meters' => null,
                'fee' => 10,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.tier.is_active', false);

        $this->assertFalse($last->fresh()->is_active);
    }

    public function test_toggle_off_that_creates_a_gap_is_rejected(): void
    {
        $first = $this->tier(0, 1000);
        $middle = $this->tier(1001, 2000);
        $this->tier(2001, null);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/admin/delivery-pricing/tiers/{$middle->id}/toggle-status")
            ->assertStatus(422);

        $this->assertTrue($middle->fresh()->is_active);
        $this->assertTrue($first->fresh()->is_active);
    }

    public function test_toggle_off_the_last_tier_is_allowed(): void
    {
        $this->tier(0, 1000);
        $last = $this->tier(1001, null);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/admin/delivery-pricing/tiers/{$last->id}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_delete_of_an_active_middle_tier_that_creates_a_gap_is_rejected(): void
    {
        $this->tier(0, 1000);
        $middle = $this->tier(1001, 2000);
        $this->tier(2001, null);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/admin/delivery-pricing/tiers/{$middle->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('delivery_fee_tiers', ['id' => $middle->id]);
    }

    public function test_delete_of_an_inactive_tier_is_allowed(): void
    {
        $draft = $this->tier(5000, 6000, 5.0, active: false);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/admin/delivery-pricing/tiers/{$draft->id}")
            ->assertOk();

        $this->assertDatabaseMissing('delivery_fee_tiers', ['id' => $draft->id]);
    }

    // ══════════════════════════════════════════════════════════════════
    // Enable / disable
    // ══════════════════════════════════════════════════════════════════

    public function test_enabling_without_active_tiers_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/delivery-pricing/status', ['enabled' => true])
            ->assertStatus(422);

        $this->assertFalse(DeliveryPricingService::isEnabled());
    }

    public function test_enabling_with_a_valid_set_succeeds_and_is_reflected(): void
    {
        $this->tier(0, null, 8.0);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/delivery-pricing/status', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->assertTrue(DeliveryPricingService::isEnabled());

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/delivery-pricing')
            ->assertJsonPath('data.enabled', true);
    }

    public function test_disabling_always_succeeds(): void
    {
        $this->tier(0, null, 8.0);
        DeliveryPricingService::setEnabled(true);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/delivery-pricing/status', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->assertFalse(DeliveryPricingService::isEnabled());
    }

    // ══════════════════════════════════════════════════════════════════
    // ثابت: لا يمكن أن يبقى التسعير مفعّلًا بلا أي شريحة فعّالة
    // ══════════════════════════════════════════════════════════════════

    public function test_toggle_off_the_last_active_tier_is_rejected_while_enabled(): void
    {
        $only = $this->tier(0, null);
        DeliveryPricingService::setEnabled(true);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/admin/delivery-pricing/tiers/{$only->id}/toggle-status")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertTrue($only->fresh()->is_active);
        $this->assertTrue(DeliveryPricingService::isEnabled());
    }

    public function test_delete_of_the_last_active_tier_is_rejected_while_enabled(): void
    {
        $only = $this->tier(0, null);
        DeliveryPricingService::setEnabled(true);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/admin/delivery-pricing/tiers/{$only->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('delivery_fee_tiers', ['id' => $only->id, 'is_active' => true]);
        $this->assertTrue(DeliveryPricingService::isEnabled());
    }

    public function test_update_deactivating_the_last_active_tier_is_rejected_while_enabled(): void
    {
        $only = $this->tier(0, null);
        DeliveryPricingService::setEnabled(true);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/admin/delivery-pricing/tiers/{$only->id}", [
                'min_distance_meters' => 0,
                'max_distance_meters' => null,
                'fee' => 10,
                'is_active' => false,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertTrue($only->fresh()->is_active);
        $this->assertTrue(DeliveryPricingService::isEnabled());
    }

    public function test_deactivating_one_of_two_active_tiers_is_allowed_while_enabled(): void
    {
        $this->tier(0, 1000);
        $open = $this->tier(1001, null);
        DeliveryPricingService::setEnabled(true);

        // تبقى شريحة فعّالة واحدة ⇒ مسموح (لا إفراغ للمجموعة).
        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/admin/delivery-pricing/tiers/{$open->id}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertTrue(DeliveryPricingService::isEnabled());
        $this->assertSame(1, DeliveryFeeTier::query()->active()->count());
    }

    public function test_toggle_off_the_last_active_tier_is_allowed_while_disabled(): void
    {
        $only = $this->tier(0, null); // التسعير معطّل افتراضيًا

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/admin/delivery-pricing/tiers/{$only->id}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_delete_of_the_last_active_tier_is_allowed_while_disabled(): void
    {
        $only = $this->tier(0, null); // التسعير معطّل افتراضيًا

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/admin/delivery-pricing/tiers/{$only->id}")
            ->assertOk();

        $this->assertDatabaseMissing('delivery_fee_tiers', ['id' => $only->id]);
    }

    public function test_store_cannot_empty_the_active_set_while_enabled(): void
    {
        $this->tier(0, null);
        DeliveryPricingService::setEnabled(true);

        // إضافة شريحة معطّلة (مسودة) لا تمسّ المجموعة الفعّالة.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/delivery-pricing/tiers', [
                'min_distance_meters' => 5000,
                'max_distance_meters' => 6000,
                'fee' => 5,
                'is_active' => false,
            ])
            ->assertCreated();

        $this->assertTrue(DeliveryPricingService::isEnabled());
        $this->assertSame(1, DeliveryFeeTier::query()->active()->count());
    }

    public function test_disabling_then_deactivating_the_last_tier_is_allowed(): void
    {
        $only = $this->tier(0, null);
        DeliveryPricingService::setEnabled(true);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/delivery-pricing/status', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/admin/delivery-pricing/tiers/{$only->id}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertSame(0, DeliveryFeeTier::query()->active()->count());
    }

    // ══════════════════════════════════════════════════════════════════
    // Authorization
    // ══════════════════════════════════════════════════════════════════

    public function test_guest_cannot_access_delivery_pricing(): void
    {
        $this->getJson('/api/admin/delivery-pricing')->assertStatus(401);
        $this->postJson('/api/admin/delivery-pricing/tiers', [
            'min_distance_meters' => 0,
            'fee' => 5,
        ])->assertStatus(401);
    }

    public function test_non_admin_cannot_access_delivery_pricing(): void
    {
        $pharmacy = $this->userWithRole('pharmacy');

        $this->actingAs($pharmacy, 'sanctum')
            ->getJson('/api/admin/delivery-pricing')
            ->assertStatus(403);

        $this->actingAs($pharmacy, 'sanctum')
            ->postJson('/api/admin/delivery-pricing/status', ['enabled' => true])
            ->assertStatus(403);
    }

    // ══════════════════════════════════════════════════════════════════
    // Concurrency guard (P1)
    // ══════════════════════════════════════════════════════════════════

    /**
     * كل مسارات الكتابة تشترك في القفل نفسه: أثناء حجزه لا تُنفَّذ أي كتابة
     * (لا «تحقّق ثم اكتب» متزامن)، وبعد تحريره يمرّ المسار ويُحرَّر القفل.
     */
    public function test_every_pricing_write_path_is_blocked_while_another_write_holds_the_shared_lock(): void
    {
        $tier = $this->tier(0, null, 10.0, active: false);

        $this->actingAs($this->admin(), 'sanctum');

        // الرسالة نفسها في كل المسارات ⇒ يثبت أن 409 من قفل الكتابة لا من سبب آخر.
        $lockedMessage = 'هناك عملية تعديل أخرى على إعدادات تسعير التوصيل قيد التنفيذ. أعد المحاولة بعد لحظات.';

        $held = Cache::lock(DeliveryFeeTierController::CONCURRENCY_LOCK, 10);
        $this->assertTrue($held->get(), 'تعذّر حجز القفل لمحاكاة تعديل متزامن.');

        try {
            $this->postJson('/api/admin/delivery-pricing/tiers', [
                'min_distance_meters' => 0,
                'fee' => 5,
            ])->assertStatus(409)->assertJsonPath('success', false)->assertJsonPath('message', $lockedMessage);

            $this->putJson("/api/admin/delivery-pricing/tiers/{$tier->id}", [
                'min_distance_meters' => 0,
                'fee' => 6,
            ])->assertStatus(409)->assertJsonPath('success', false)->assertJsonPath('message', $lockedMessage);

            $this->patchJson("/api/admin/delivery-pricing/tiers/{$tier->id}/toggle-status")
                ->assertStatus(409)->assertJsonPath('success', false)->assertJsonPath('message', $lockedMessage);

            $this->deleteJson("/api/admin/delivery-pricing/tiers/{$tier->id}")
                ->assertStatus(409)->assertJsonPath('success', false)->assertJsonPath('message', $lockedMessage);

            $this->postJson('/api/admin/delivery-pricing/settings', [
                'mode' => DeliveryPricingService::MODE_PER_KM,
                'per_km_rate' => 2,
            ])->assertStatus(409)->assertJsonPath('success', false)->assertJsonPath('message', $lockedMessage);

            $this->postJson('/api/admin/delivery-pricing/status', ['enabled' => true])
                ->assertStatus(409)->assertJsonPath('success', false)->assertJsonPath('message', $lockedMessage);
        } finally {
            $held->release();
        }

        // لم تُكتب أي قيمة أثناء القفل.
        $this->assertSame(1, DeliveryFeeTier::count());
        $this->assertSame(10.0, (float) $tier->fresh()->fee);
        $this->assertFalse($tier->fresh()->is_active);
        $this->assertNull(DeliveryPricingService::baseFee());
        $this->assertSame(DeliveryPricingService::MODE_TIERS, DeliveryPricingService::mode());
        $this->assertFalse(DeliveryPricingService::isEnabled());

        // بعد التحرير يمرّ المسار نفسه، ويُحرَّر القفل بعد النجاح أيضًا.
        $this->postJson('/api/admin/delivery-pricing/tiers', [
            'min_distance_meters' => 0,
            'fee' => 5,
        ])->assertStatus(201);

        $this->assertSame(2, DeliveryFeeTier::count());

        $after = Cache::lock(DeliveryFeeTierController::CONCURRENCY_LOCK, 10);
        $this->assertTrue($after->get(), 'القفل بقي معلّقًا بعد كتابة ناجحة.');
        $after->release();
    }
}
