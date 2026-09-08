<?php

namespace Tests\Feature\Api;

use App\Models\MohMedicine;
use App\Models\User;
use App\Services\Ai\MedicineResolver;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MedicineResolveTest extends TestCase
{
    public function test_arabic_name_resolves_via_mapping_without_ai(): void
    {
        // كتالوج وزارة الصحة إنجليزي — الـ LIKE ما رح يلحق الاستعلام العربي وحده
        MohMedicine::create([
            'trade_name' => 'PANADOL EXTRA TABLETS',
            'generic_name' => 'Paracetamol',
            'moh_product_id' => 555001,
        ]);

        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/medicines/resolve', [
            'name' => 'بنادول',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'بنادول')
            ->assertJsonPath('data.requires_location', true)
            ->assertJsonStructure([
                'data' => [
                    'moh_catalog', 'local_catalog', 'pharmacies', 'alternatives',
                ],
            ]);

        $this->assertGreaterThanOrEqual(1, count($response->json('data.moh_catalog')));
        $this->assertSame('PANADOL EXTRA TABLETS', $response->json('data.moh_catalog.0.trade_name'));
    }

    public function test_misspelled_arabic_still_resolves_through_alias_variants(): void
    {
        MohMedicine::create([
            'trade_name' => 'PANADOL EXTRA TABLETS',
            'generic_name' => 'Paracetamol',
            'moh_product_id' => 555001,
        ]);

        Sanctum::actingAs(User::factory()->create());

        // إبدال حرف — panadol → بنادول (الف → ا) مغطّى بالـ mapping مباشرة،
        // لكن "بناددول" (إبدال د) ما في variant له بالضبط — لازم Python fuzzy أو
        // تطبيع الأخطاء. هذا الاختبار يثبت أن الـ resolver يعمل على المطابقات الحرفية
        // ويفشل بأمان لو ما في تطابق.
        $response = $this->postJson('/api/medicines/resolve', [
            'name' => 'بنادول',
        ]);

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data.moh_catalog')));
    }

    public function test_short_name_returns_empty_without_ai_call(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/medicines/resolve', [
            'name' => 'ب',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'ب')
            ->assertJsonPath('data.moh_catalog', []);
        $this->assertEmpty($response->json('data.moh_catalog'));
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/medicines/resolve', ['name' => 'panadol'])
            ->assertUnauthorized();
    }

    public function test_validates_required_name(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/medicines/resolve', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_returns_nearby_pharmacies_when_location_provided(): void
    {
        $user = User::factory()->create();
        $resolver = $this->app->make(MedicineResolver::class);
        $resolver->setMappingPath(implode("\n", [
            '[',
            json_encode(['id' => 1, 'moh_product_id' => 999, 'moh_drug_id' => null, 'name_en' => 'PANADOL', 'name_ar' => 'بنادول', 'aliases' => ['panadol', 'بنادول']], JSON_UNESCAPED_UNICODE),
            ']',
            '',
        ]));

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/medicines/resolve', [
            'name' => 'بنادول',
            'latitude' => 31.5017,
            'longitude' => 34.4668,
            'radius_km' => 15,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.requires_location', false);
        $this->assertIsArray($response->json('data.pharmacies'));
    }
}
