<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\AccountingOverviewController;
use App\Models\Medicine;
use App\Models\MedicineBarcode;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingBarcodeCoveragePerformanceTest extends TestCase
{
    private function coverage(int $pharmacyId): array
    {
        $method = new \ReflectionMethod(AccountingOverviewController::class, 'barcodeCoverage');

        return $method->invoke(new AccountingOverviewController, $pharmacyId);
    }

    private function stock(Pharmacy $pharmacy): Medicine
    {
        $medicine = Medicine::factory()->create();
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 10,
            'quantity' => 5,
            'is_available' => true,
        ]);

        return $medicine;
    }

    private function barcode(Medicine $medicine, string $barcode, bool $verified): void
    {
        MedicineBarcode::create([
            'moh_medicine_id' => null,
            'local_medicine_id' => $medicine->id,
            'barcode' => $barcode,
            'barcode_type' => 'EAN13',
            'source' => 'pharmacy',
            'confidence' => 1,
            'is_verified' => $verified,
        ]);
    }

    public function test_barcode_coverage_preserves_overlapping_statuses_and_uses_one_query(): void
    {
        $pharmacy = Pharmacy::factory()->create();
        $other = Pharmacy::factory()->create();
        $verified = $this->stock($pharmacy);
        $pending = $this->stock($pharmacy);
        $both = $this->stock($pharmacy);
        $this->stock($pharmacy);
        $otherMedicine = $this->stock($other);
        $this->barcode($verified, '6291041500001', true);
        $this->barcode($pending, '6291041500002', false);
        $this->barcode($both, '6291041500003', true);
        $this->barcode($both, '6291041500004', false);
        $this->barcode($otherMedicine, '6291041500005', true);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $coverage = $this->coverage($pharmacy->id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertSame([
            'total' => 4,
            'with_barcode' => 4,
            'without_barcode' => 0,
            'percent' => 100.0,
            'by_status' => ['verified' => 2, 'pending' => 2, 'unknown' => 0],
        ], $coverage);
    }

    public function test_empty_pharmacy_coverage_is_zero(): void
    {
        $pharmacy = Pharmacy::factory()->create();

        $this->assertSame([
            'total' => 0,
            'with_barcode' => 0,
            'without_barcode' => 0,
            'percent' => 0.0,
            'by_status' => ['verified' => 0, 'pending' => 0, 'unknown' => 0],
        ], $this->coverage($pharmacy->id));
    }
}
