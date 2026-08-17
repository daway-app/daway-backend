<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\MohMedicine;
use Tests\TestCase;

class MedicineApiTest extends TestCase
{
    public function test_index_returns_moh_catalog_with_pagination(): void
    {
        MohMedicine::create([
            'trade_name' => 'Panadol Extra',
            'generic_name' => 'Paracetamol',
            'manufacturer' => 'GSK',
        ]);
        MohMedicine::create([
            'trade_name' => 'Brufen',
            'generic_name' => 'Ibuprofen',
        ]);

        $response = $this->getJson('/api/medicines');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data',
                'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
            ])
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('pagination.per_page', 20);
    }

    public function test_search_returns_medicine_and_moh_catalog_results(): void
    {
        Medicine::create([
            'trade_name' => 'Panadol',
            'active_ingredient' => 'Paracetamol',
            'is_available' => true,
            'stock' => 50,
        ]);
        MohMedicine::create([
            'trade_name' => 'Panadol Extra',
            'generic_name' => 'Paracetamol',
            'manufacturer' => 'GSK',
        ]);

        $response = $this->getJson('/api/medicines/search?q=pan');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['medicines', 'moh_catalog']])
            ->assertJsonCount(1, 'data.medicines')
            ->assertJsonCount(1, 'data.moh_catalog')
            ->assertJsonPath('data.medicines.0.trade_name', 'Panadol')
            ->assertJsonPath('data.moh_catalog.0.trade_name', 'Panadol Extra');
    }

    public function test_search_with_short_query_returns_empty_results(): void
    {
        $response = $this->getJson('/api/medicines/search?q=a');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', []);
    }
}
