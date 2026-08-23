<?php

namespace Tests\Feature\Commands;

use App\Models\MohMedicine;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MohImportTest extends TestCase
{
    public function test_import_populates_moh_medicines_from_json_file(): void
    {
        Storage::fake('local');
        $path = storage_path('app/private/moh_fixture.json');

        file_put_contents($path, json_encode([
            [
                'trade_name' => 'Panadol 500 mg',
                'manufacturer' => 'GSK',
                'dosage_form' => 'Tablet',
                'product_class' => 'Pharmaceutical',
                'origin' => 'UK',
                'moh_product_id' => 100,
                'generic_name' => 'Paracetamol',
                'official_price' => 5.5,
                'packaging' => '20 TABLETS',
                'company' => 'GSK',
                'availability' => 'متوفر',
                'moh_drug_id' => 200,
                'price_updated_at' => '2026-01-01',
                'created_at' => '2026-08-23 12:00:00',
                'updated_at' => '2026-08-23 12:00:00',
            ],
            [
                'trade_name' => 'Amoxil',
                'manufacturer' => 'GSK',
                'dosage_form' => 'Capsule',
                'product_class' => 'Pharmaceutical',
                'origin' => 'UK',
                'moh_product_id' => 101,
                'generic_name' => 'Amoxicillin',
                'official_price' => null,
                'packaging' => null,
                'company' => null,
                'availability' => null,
                'moh_drug_id' => null,
                'price_updated_at' => null,
                'created_at' => '2026-08-23 12:00:00',
                'updated_at' => '2026-08-23 12:00:00',
            ],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('moh:import', ['--file' => $path])
            ->expectsOutputToContain('تم الاستيراد بنجاح: 2 دواء')
            ->assertExitCode(0);

        $this->assertSame(2, MohMedicine::count());
        $this->assertSame('Paracetamol', MohMedicine::where('trade_name', 'Panadol 500 mg')->first()->generic_name);
    }

    public function test_import_reports_missing_file(): void
    {
        $this->artisan('moh:import', ['--file' => 'storage/app/private/does_not_exist.json'])
            ->assertExitCode(1);
    }
}