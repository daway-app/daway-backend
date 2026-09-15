<?php

namespace Tests\Feature\Api;

use App\Models\MedicineBarcode;
use App\Models\MedicineImage;
use App\Models\MohMedicine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** API: GET /api/medicines/barcode/{barcode} — read-only، بلا auth، biler throttled. */
final class BarcodeLookupApiTest extends TestCase
{
    use RefreshDatabase;

    private function mohWithBarcode(): array
    {
        $moh = MohMedicine::create([
            'trade_name' => 'BARCODED MED',
            'generic_name' => 'Paracetamol',
            'manufacturer' => 'TestPharma',
            'dosage_form' => 'Tablet',
            'packaging' => '20 tablets',
            'moh_product_id' => 4444,
        ]);

        MedicineBarcode::create([
            'moh_medicine_id' => $moh->id,
            'barcode' => '6291041500213',
            'barcode_type' => 'EAN13',
            'source' => 'drugs_api',
            'confidence' => 0.97,
        ]);

        MedicineImage::create([
            'moh_medicine_id' => $moh->id,
            'image_url' => 'https://cdn.example.com/pack.png',
            'source' => 'drugs_api',
        ]);

        return [$moh];
    }

    public function test_lookup_returns_medicine_by_barcode(): void
    {
        $this->mohWithBarcode();

        $this->getJson('/api/medicines/barcode/6291041500213')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.medicine.name_en', 'BARCODED MED')
            ->assertJsonPath('data.barcode.value', '6291041500213')
            ->assertJsonPath('data.barcode.type', 'EAN13')
            ->assertJsonPath('data.image.url', 'https://cdn.example.com/pack.png');
    }

    public function test_unknown_barcode_returns_404(): void
    {
        $this->getJson('/api/medicines/barcode/6291041500999')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_invalid_barcode_format_returns_422(): void
    {
        $this->getJson('/api/medicines/barcode/xxxxx')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_no_provider_internals_leak(): void
    {
        $this->mohWithBarcode();

        $response = $this->getJson('/api/medicines/barcode/6291041500213');
        $json = $response->getContent();

        $this->assertStringNotContainsStringIgnoringCase('api_key', $json);
        $this->assertStringNotContainsStringIgnoringCase('base_url', $json);
        $this->assertStringNotContainsStringIgnoringCase('credentials', $json);
    }
}
