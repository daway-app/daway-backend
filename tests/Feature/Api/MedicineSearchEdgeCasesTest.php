<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MedicineSearchEdgeCasesTest extends TestCase
{
    // ── Patient /api/medicines/search ──────────────────────────────────

    public function test_empty_query_returns_empty(): void
    {
        Medicine::factory()->create(['trade_name' => 'Panadol', 'active_ingredient' => 'Paracetamol']);

        $this->getJson('/api/medicines/search?q=')
            ->assertOk()
            ->assertJsonPath('data.medicines', []);
    }

    public function test_single_char_query_returns_empty(): void
    {
        Medicine::factory()->create(['trade_name' => 'Panadol', 'active_ingredient' => 'Paracetamol']);

        $this->getJson('/api/medicines/search?q=p')
            ->assertOk()
            ->assertJsonPath('data.medicines', []);
    }

    public function test_leading_trailing_whitespace_is_trimmed(): void
    {
        Medicine::factory()->create(['trade_name' => 'Panadol', 'active_ingredient' => 'Paracetamol']);

        // trim() في الـ controller يزيل المسافات البادئة/اللاحقة
        $this->getJson('/api/medicines/search?q='.urlencode('  panadol  '))
            ->assertOk()
            ->assertJsonPath('data.medicines.0.trade_name', 'Panadol');
    }

    public function test_internal_multiple_spaces_do_not_match(): void
    {
        // المسافات الداخلية المتكررة غير مُطبَّعة — سلوك متوقع (لا fuzzy)
        Medicine::factory()->create(['trade_name' => 'Panadol Extra', 'active_ingredient' => 'Paracetamol']);

        $this->getJson('/api/medicines/search?q='.urlencode('panadol   extra'))
            ->assertOk()
            ->assertJsonCount(0, 'data.medicines');
    }

    public function test_case_insensitive_search(): void
    {
        Medicine::factory()->create(['trade_name' => 'Panadol', 'active_ingredient' => 'Paracetamol']);

        $this->getJson('/api/medicines/search?q=PANADOL')
            ->assertOk()
            ->assertJsonPath('data.medicines.0.trade_name', 'Panadol');
    }

    public function test_partial_match_on_trade_name(): void
    {
        Medicine::factory()->create(['trade_name' => 'Ibuprofen 400', 'active_ingredient' => 'Ibuprofen']);

        $this->getJson('/api/medicines/search?q=ibupro')
            ->assertOk()
            ->assertJsonPath('data.medicines.0.trade_name', 'Ibuprofen 400');
    }

    public function test_search_by_active_ingredient(): void
    {
        Medicine::factory()->create(['trade_name' => 'Brufen', 'active_ingredient' => 'Ibuprofen']);

        $this->getJson('/api/medicines/search?q=ibuprofen')
            ->assertOk()
            ->assertJsonPath('data.medicines.0.active_ingredient', 'Ibuprofen');
    }

    public function test_no_results_for_unknown_query(): void
    {
        Medicine::factory()->create(['trade_name' => 'Panadol', 'active_ingredient' => 'Paracetamol']);

        $this->getJson('/api/medicines/search?q=zzznonexistent')
            ->assertOk()
            ->assertJsonCount(0, 'data.medicines');
    }

    public function test_special_characters_do_not_crash(): void
    {
        Medicine::factory()->create(['trade_name' => 'Panadol', 'active_ingredient' => 'Paracetamol']);

        $this->getJson('/api/medicines/search?q='.urlencode("'OR'1=1'--"))
            ->assertOk()
            ->assertJsonCount(0, 'data.medicines');
    }

    public function test_long_query_is_handled_gracefully(): void
    {
        Medicine::factory()->create(['trade_name' => 'Panadol', 'active_ingredient' => 'Paracetamol']);

        $this->getJson('/api/medicines/search?q='.str_repeat('a', 500))
            ->assertOk();
    }

    public function test_duplicate_medicine_not_returned_twice_in_medicines_list(): void
    {
        Medicine::factory()->create(['trade_name' => 'UniqueMed', 'active_ingredient' => 'UniqueIng']);

        $this->getJson('/api/medicines/search?q=UniqueMed')
            ->assertOk()
            ->assertJsonCount(1, 'data.medicines');
    }

    public function test_moh_catalog_search_matches_generic_name(): void
    {
        MohMedicine::create(['trade_name' => 'AMOX 500', 'generic_name' => 'Amoxicillin', 'moh_product_id' => 1]);

        $this->getJson('/api/medicines/search?q=amoxicillin')
            ->assertOk()
            ->assertJsonPath('data.moh_catalog.0.trade_name', 'AMOX 500');
    }

    public function test_moh_catalog_search_matches_manufacturer(): void
    {
        MohMedicine::create(['trade_name' => 'GSK MED', 'generic_name' => 'Test', 'manufacturer' => 'GSK', 'moh_product_id' => 2]);

        $this->getJson('/api/medicines/search?q=GSK')
            ->assertOk()
            ->assertJsonCount(1, 'data.moh_catalog');
    }

    // ── Pharmacy /api/pharmacy/medicines/search ────────────────────────

    private function pharmacyUser(): User
    {
        return User::factory()->pharmacy()->create();
    }

    public function test_pharmacy_search_requires_authentication(): void
    {
        $this->getJson('/api/pharmacy/medicines/search?q=pan')
            ->assertStatus(401);
    }

    public function test_pharmacy_search_requires_pharmacy_role(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $this->getJson('/api/pharmacy/medicines/search?q=pan')
            ->assertStatus(403);
    }

    public function test_pharmacy_search_empty_query_returns_empty(): void
    {
        Sanctum::actingAs($this->pharmacyUser());

        $this->getJson('/api/pharmacy/medicines/search?q=')
            ->assertOk()
            ->assertJsonPath('data.medicines', []);
    }

    public function test_pharmacy_search_single_char_returns_empty(): void
    {
        Sanctum::actingAs($this->pharmacyUser());

        $this->getJson('/api/pharmacy/medicines/search?q=p')
            ->assertOk()
            ->assertJsonPath('data.medicines', []);
    }

    public function test_pharmacy_search_arabic_trade_name(): void
    {
        Sanctum::actingAs($this->pharmacyUser());
        Medicine::factory()->create(['trade_name' => 'Panadol', 'trade_name_ar' => 'بنادول']);

        $this->getJson('/api/pharmacy/medicines/search?q='.urlencode('بنادول'))
            ->assertOk()
            ->assertJsonPath('data.medicines.0.name_ar', 'بنادول');
    }

    public function test_pharmacy_search_special_characters_safe(): void
    {
        Sanctum::actingAs($this->pharmacyUser());
        Medicine::factory()->create(['trade_name' => 'Panadol']);

        $this->getJson('/api/pharmacy/medicines/search?q='.urlencode("'; DROP TABLE medicines;"))
            ->assertOk()
            ->assertJsonCount(0, 'data.medicines');
    }

    // ── Resolve /api/medicines/resolve ─────────────────────────────────

    public function test_resolve_requires_authentication(): void
    {
        $this->postJson('/api/medicines/resolve', ['name' => 'panadol'])
            ->assertStatus(401);
    }

    public function test_resolve_short_name_returns_empty(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/medicines/resolve', ['name' => 'a'])
            ->assertOk()
            ->assertJsonPath('data.moh_catalog', []);
    }

    public function test_resolve_empty_name_returns_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/medicines/resolve', ['name' => ''])
            ->assertStatus(422);
    }

    public function test_resolve_long_name_is_validated(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/medicines/resolve', ['name' => str_repeat('x', 300)])
            ->assertStatus(422);
    }
}
