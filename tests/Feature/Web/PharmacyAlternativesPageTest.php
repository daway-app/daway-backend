<?php

namespace Tests\Feature\Web;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PharmacyAlternativesPageTest extends TestCase
{
    use RefreshDatabase;

    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    public function test_index_page_renders_with_add_button(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
        ]);

        $response = $this->actingAs($user)->get(route('pharmacy.alternatives.index'));

        $response->assertOk()
            // "Add New Alternative" — نص الزر (مترجم)
            ->assertSee(__('pharmacy.alternatives.index.add_button'))
            // اسم الدواء ظاهر
            ->assertSee($medicine->trade_name);
    }

    public function test_index_page_has_search_input(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
        ]);

        $response = $this->actingAs($user)->get(route('pharmacy.alternatives.index'));

        $response->assertOk()
            // حقل البحث موجود (الـ " البحث عن دواء أو مادة فعالة..." مكتوب escaped في HTML)
            ->assertSee('data-ph-search=', false)
            ->assertSee(__('pharmacy.alternatives.index.search_placeholder'));
    }

    public function test_index_page_has_collapse_toggle(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
        ]);

        $response = $this->actingAs($user)->get(route('pharmacy.alternatives.index'));

        $response->assertOk()
            // aria-expanded موجود
            ->assertSee('aria-expanded', false)
            // أيقونة chevron
            ->assertSee('fa-chevron-down', false);
    }

    public function test_index_shows_in_stock_badge_for_alternatives_in_inventory(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        $base = Medicine::factory()->create(['trade_name' => 'Base Med', 'active_ingredient' => 'X']);
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $base->id,
            'price' => 5,
            'quantity' => 10,
        ]);

        $altInStock = Medicine::factory()->create(['trade_name' => 'Alt In Stock', 'active_ingredient' => 'X']);
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $altInStock->id,
            'price' => 5,
            'quantity' => 5,
            'is_available' => true,
        ]);

        $altOut = Medicine::factory()->create(['trade_name' => 'Alt Out', 'active_ingredient' => 'X']);
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $altOut->id,
            'price' => 5,
            'quantity' => 0,
            'is_available' => true,
        ]);

        $response = $this->actingAs($user)->get(route('pharmacy.alternatives.index'));

        $response->assertOk()
            ->assertSee('Alt In Stock')
            ->assertSee('Alt Out')
            // Badge in_stock و not_in_stock موجودان (مترجمان)
            ->assertSee(__('pharmacy.alternatives.index.badge_in_stock'))
            ->assertSee(__('pharmacy.alternatives.index.badge_not_in_stock'));
    }

    public function test_index_delete_modal_present(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $base = Medicine::factory()->create(['active_ingredient' => 'X']);
        $alt = Medicine::factory()->create(['active_ingredient' => 'X']);
        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $base->id,
            'price' => 5,
            'quantity' => 10,
        ]);
        $alt->alternatives()->attach($base->id);

        $response = $this->actingAs($user)->get(route('pharmacy.alternatives.index'));

        $response->assertOk()
            // Modal الحذف موجود بكل عناصره
            ->assertSee('phDeleteConfirmModal', false)
            ->assertSee('data-ph-confirm-ok', false)
            ->assertSee('data-ph-confirm-cancel', false);
    }

    public function test_create_route_with_pharmacy_medicine_pre_selects_base(): void
    {
        // الـ route الجديد يجب أن يستقبل {pharmacyMedicine} كـ path param
        // ويحدد الـ select تلقائياً عبر route model binding.
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();
        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
        ]);

        $response = $this->actingAs($user)
            ->get(route('pharmacy.alternatives.create', ['pharmacyMedicine' => $pm->id]));

        $response->assertOk()
            // selected على option الـ pm (الـ HTML literal)
            ->assertSee('value="'.$pm->id.'" selected', false)
            // الـ title في h1 يجب أن يكون مخصص
            ->assertSee(__('pharmacy.alternatives.create.heading', ['pharmacy' => $pharmacy->pharmacy_name]));
    }

    public function test_create_route_without_pharmacy_medicine_works(): void
    {
        // الـ route بدون {pharmacyMedicine} يجب أن يعمل أيضاً (الـ user يختار يدوياً)
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
        ]);

        $response = $this->actingAs($user)
            ->get(route('pharmacy.alternatives.create'));

        $response->assertOk()
            // لا selected attribute على أي option — option placeholder فارغ
            ->assertSee('value=""', false)
            ->assertSee(__('pharmacy.alternatives.create.base_placeholder'));
    }

    public function test_store_validates_duplicate_alternative(): void
    {
        // C1: لا يمكن إضافة نفس البديل مرتين — العلاقة (base→alt) موجودة مسبقاً
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $base = Medicine::factory()->create(['active_ingredient' => 'X']);
        $alt = Medicine::factory()->create(['active_ingredient' => 'X']);
        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $base->id,
            'price' => 5,
            'quantity' => 10,
        ]);
        // العلاقة الصحيحة: base → alt (التي تختبر الـ already_exists)
        $base->alternatives()->attach($alt->id);

        $response = $this->actingAs($user)->post(route('pharmacy.alternatives.store'), [
            'base_medicine_id' => $pm->id,
            'alternative_medicine_id' => $alt->id,
        ]);

        $response->assertRedirect(route('pharmacy.alternatives.index'))
            ->assertSessionHas('error', __('pharmacy.alternatives.create.already_exists'));
    }

    public function test_store_rejects_self_alternative(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();
        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
        ]);

        $response = $this->actingAs($user)->post(route('pharmacy.alternatives.store'), [
            'base_medicine_id' => $pm->id,
            'alternative_medicine_id' => $medicine->id,
        ]);

        $response->assertRedirect(route('pharmacy.alternatives.index'))
            ->assertSessionHas('error', __('pharmacy.alternatives.create.self_alternative'));
    }

    public function test_store_rejects_reverse_pair(): void
    {
        // C1: إذا (alt → base) موجودة، لا يمكن إضافة (base → alt)
        // الـ relation الحالية: alt هو base، base هو alternative
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $base = Medicine::factory()->create(['active_ingredient' => 'X']);
        $alt = Medicine::factory()->create(['active_ingredient' => 'X']);
        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $base->id,
            'price' => 5,
            'quantity' => 10,
        ]);
        // alt → base (العلاقة المعكوسة — alt هو الـ base في الجدول)
        $alt->alternatives()->attach($base->id);

        $response = $this->actingAs($user)->post(route('pharmacy.alternatives.store'), [
            'base_medicine_id' => $pm->id,
            'alternative_medicine_id' => $alt->id,
        ]);

        $response->assertRedirect(route('pharmacy.alternatives.index'))
            ->assertSessionHas('error', __('pharmacy.alternatives.create.reverse_exists'));
    }

    public function test_destroy_uses_atomic_detach(): void
    {
        // C1.3: destroy يعمل atomic
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $base = Medicine::factory()->create(['active_ingredient' => 'X']);
        $alt = Medicine::factory()->create(['active_ingredient' => 'X']);
        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $base->id,
            'price' => 5,
            'quantity' => 10,
        ]);
        $base->alternatives()->attach($alt->id);

        $response = $this->actingAs($user)->delete(
            route('pharmacy.alternatives.destroy', ['pharmacyMedicine' => $pm->id, 'alternative' => $alt->id])
        );

        $response->assertRedirect(route('pharmacy.alternatives.index'))
            ->assertSessionHas('success', __('pharmacy.alternatives.destroy.success'));

        $this->assertDatabaseMissing('alternative_medicine', [
            'medicine_id' => $base->id,
            'alternative_id' => $alt->id,
        ]);
    }

    public function test_destroy_fails_for_nonexistent_alternative(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $base = Medicine::factory()->create(['active_ingredient' => 'X']);
        $alt = Medicine::factory()->create(['active_ingredient' => 'X']);
        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $base->id,
            'price' => 5,
            'quantity' => 10,
        ]);
        // لا attach — لا توجد علاقة

        $response = $this->actingAs($user)->delete(
            route('pharmacy.alternatives.destroy', ['pharmacyMedicine' => $pm->id, 'alternative' => $alt->id])
        );

        $response->assertRedirect(route('pharmacy.alternatives.index'))
            ->assertSessionHas('error', __('pharmacy.alternatives.destroy.not_found'));
    }

    public function test_destroy_rejects_other_pharmacy_medicine(): void
    {
        // authorization: لا يمكن حذف بديل من صيدلية أخرى
        $ownerUser = User::factory()->pharmacy()->create();
        $ownerPharmacy = Pharmacy::factory()->create(['user_id' => $ownerUser->id]);
        $attackerUser = User::factory()->pharmacy()->create();
        $attackerPharmacy = Pharmacy::factory()->create(['user_id' => $attackerUser->id]);

        $base = Medicine::factory()->create(['active_ingredient' => 'X']);
        $alt = Medicine::factory()->create(['active_ingredient' => 'X']);
        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $ownerPharmacy->id,
            'medicine_id' => $base->id,
            'price' => 5,
            'quantity' => 10,
        ]);
        $base->alternatives()->attach($alt->id);

        $response = $this->actingAs($attackerUser)->delete(
            route('pharmacy.alternatives.destroy', ['pharmacyMedicine' => $pm->id, 'alternative' => $alt->id])
        );

        $response->assertRedirect(route('pharmacy.alternatives.index'))
            ->assertSessionHas('error', __('pharmacy.alternatives.index.no_access'));
    }
}
