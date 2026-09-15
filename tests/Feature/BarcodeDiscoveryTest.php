<?php
namespace Tests\Feature;

use App\Models\MohMedicine;
use App\Services\Enrichment\Providers\MedicineQuery;
use App\Services\Enrichment\Providers\OpenFdaProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** أدوات اختبار مركب المستوى: نضمن ال"No fake barcode" في الcommand + validation. */
final class BarcodeDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_rejects_without_dry_run_flag(): void
    {
        config(['enrichment.rxnorm.enabled' => true]);

        $this->artisan('medicines:barcode-discover')
            ->expectsOutputToContain('بلا --dry-run غير مسموح في هذه المرحلة')
            ->assertFailed();
    }

    public function test_discovery_writes_nothing_to_database(): void
    {
        MohMedicine::create(['trade_name' => 'B-MED', 'moh_product_id' => 99001, 'moh_drug_id' => 88002]);

        config(['enrichment.rxnorm.enabled' => true]);
        Http::fake([
            'https://rxnav.nlm.nih.gov/*' => Http::response([
                // قدمي barcode من "المزوّد" لكن به شكل غير صالح (10 digits — ليس EAN/UPC/GTIN)
                'drugGroup' => ['conceptGroup' => [[
                    'conceptProperties' => [['rxcui' => '1', 'name' => 'B MED EX', 'strength' => null, 'doseFormName' => 'Tablet']],
                ]]],
            ], 200),
        ]);

        $this->artisan('medicines:barcode-discover', ['--provider' => 'rxnorm', '--limit' => 3, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('medicine_barcodes', 0);
        $this->assertDatabaseCount('medicine_enrichment_reviews', 0);

        $json = json_decode(file_get_contents(storage_path('app/reports/barcode_discovery.json')), true);
        $this->assertSame(1, $json['stats']['total']);
        $this->assertSame(0, $json['stats']['found']);
    }
}
