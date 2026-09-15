<?php

namespace Tests\Feature;

use App\Models\MedicineBarcode;
use App\Models\MohMedicine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** medicines:provider-test — Probe (لا كتابة) + لا fake success. */
final class ProviderTestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_disabled_reports_clearly_and_fails(): void
    {
        config(['enrichment.drugs_api.enabled' => false]);

        $this->artisan('medicines:provider-test', ['--provider' => 'drugs_api', '--limit' => 5])
            ->expectsOutputToContain('Provider disabled / credentials missing')
            ->assertFailed();
    }

    public function test_missing_credentials_reports_clearly(): void
    {
        config([
            'enrichment.paid_providers' => true,
            'enrichment.drugs_api.enabled' => true,
            'enrichment.drugs_api.base_url' => null,
            'enrichment.drugs_api.search_path' => null,
        ]);

        $this->artisan('medicines:provider-test', ['--provider' => 'drugs_api', '--limit' => 5])
            ->expectsOutputToContain('Provider disabled / credentials missing')
            ->assertFailed();
    }

    public function test_successful_provider_lookup_is_probed_without_db_writes(): void
    {
        // seed a catalog medicine (test driver must not be able to write DB).
        MohMedicine::create([
            'trade_name' => 'PROBE MED',
            'manufacturer' => 'TestPharma',
            'dosage_form' => 'Tablet',
            'packaging' => '20 tablets',
            'moh_product_id' => 99001,
        ]);

        config([
            'enrichment.paid_providers' => true,
            'enrichment.drugs_api.enabled' => true,
            'enrichment.drugs_api.base_url' => 'https://api.example.com',
            'enrichment.drugs_api.search_path' => '/search',
            'enrichment.drugs_api.barcode_path' => '/lookup',
        ]);

        Http::fake([
            'api.example.com/search*' => Http::response([
                'results' => [[
                    'name' => 'PROBE MED EXACT',
                    'name_ar' => 'بروب',
                    'barcode' => '6291041500213',
                    'image_url' => 'https://img.example.com/x.png',
                    'manufacturer' => 'TestPharma',
                    'pack_size' => '20 tablets',
                ]],
            ], 200),
        ]);

        $this->artisan('medicines:provider-test', ['--provider' => 'drugs_api', '--limit' => 2])
            ->assertSuccessful();

        // لا في DB كتابة أبداً (بلا barcodes / images / review / run / مفيش معالجات)
        $this->assertSame(0, \App\Models\MedicineBarcode::count());
        $this->assertSame(0, \App\Models\MedicineEnrichmentReview::count());
        $this->assertSame(0, \App\Models\MedicineEnrichmentRun::count());
        $this->assertSame(0, \App\Models\MedicineImage::count());
    }

    public function test_provider_timeout_is_caught_and_counted(): void
    {
        config([
            'enrichment.paid_providers' => true,
            'enrichment.drugs_api.enabled' => true,
            'enrichment.drugs_api.base_url' => 'https://api.example.com',
            'enrichment.drugs_api.search_path' => '/search',
        ]);

        // الprovider يلتهم الاستثناء داخلياً (does not crash) → يُقص كnot_found. لا نجح كـfake.
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        MohMedicine::create(['trade_name' => 'T-MED', 'moh_product_id' => 99002]);

        $this->artisan('medicines:provider-test', ['--provider' => 'drugs_api', '--limit' => 1])
            ->assertSuccessful();

        $json = json_decode(file_get_contents(database_path('reports/provider_test_report.json')), true);
        $this->assertSame(1, $json['stats']['total']);
        $this->assertSame(1, $json['stats']['not_found']);
        $this->assertNotSame(1, $json['stats']['found']);
    }

    public function test_malformed_provider_response_is_counted_as_not_found(): void
    {
        config([
            'enrichment.paid_providers' => true,
            'enrichment.drugs_api.enabled' => true,
            'enrichment.drugs_api.base_url' => 'https://api.example.com',
            'enrichment.drugs_api.search_path' => '/search',
        ]);

        Http::fake(fn () => Http::response('<html>bad json not json</html>', 200));

        MohMedicine::create(['trade_name' => 'M-MED', 'moh_product_id' => 99003]);

        $this->artisan('medicines:provider-test', ['--provider' => 'drugs_api', '--limit' => 1]);
        $json = json_decode(file_get_contents(database_path('reports/provider_test_report.json')), true);

        $this->assertSame(1, $json['stats']['total']);
        $this->assertSame(1, $json['stats']['not_found']);
    }

    public function test_coverage_report_can_be_generated(): void
    {
        config([
            'enrichment.paid_providers' => true,
            'enrichment.drugs_api.enabled' => true,
            'enrichment.drugs_api.base_url' => 'https://api.example.com',
            'enrichment.drugs_api.search_path' => '/search',
        ]);

        Http::fake(fn () => Http::response(['name' => 'FOO MED'], 200));

        MohMedicine::create(['trade_name' => 'FOO', 'moh_product_id' => 99004]);

        $this->artisan('medicines:provider-test', ['--provider' => 'drugs_api', '--limit' => 1])->assertSuccessful();

        $json = json_decode(file_get_contents(database_path('reports/provider_test_report.json')), true);
        $this->assertSame(1, $json['stats']['total']);
        $this->assertFileExists(database_path('reports/provider_test_report.csv'));
    }
}
