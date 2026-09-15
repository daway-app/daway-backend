<?php

namespace Tests\Feature;

use App\Models\MedicineBarcode;
use App\Models\MedicineImage;
use App\Models\MedicineEnrichmentReview;
use App\Models\MedicineEnrichmentRun;
use App\Models\MohMedicine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests التحقق من المديدة — بنية الجداول الجديدة + القيود الفريدة (unique).
 */
final class EnrichmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    /** helper: إنشاء صف كتالوج سريع */
    private function moh(array $attributes = []): MohMedicine
    {
        return MohMedicine::create($attributes + [
            'trade_name' => 'ENRICH MED '.uniqid(),
            'moh_product_id' => random_int(100000, 999999),
        ]);
    }

    public function test_barcodes_table_uses_global_unique_barcode(): void
    {
        $mohA = $this->moh();
        $mohB = $this->moh();

        $first = MedicineBarcode::create([
            'moh_medicine_id' => $mohA->id,
            'barcode' => '6291041500213',
            'barcode_type' => 'EAN13',
            'source' => 'drugs_api',
        ]);

        $this->assertDatabaseHas('medicine_barcodes', ['barcode' => '6291041500213']);

        // محاولة إضافة نفس الباركود لدواء آخر — القيد unique يمنعها.
        $this->expectException(\Illuminate\Database\QueryException::class);

        MedicineBarcode::create([
            'moh_medicine_id' => $mohB->id,
            'barcode' => '6291041500213',
            'barcode_type' => 'EAN13',
            'source' => 'manual',
        ]);
    }

    public function test_images_relate_to_moh_medicine(): void
    {
        $moh = $this->moh();
        $image = MedicineImage::create([
            'moh_medicine_id' => $moh->id,
            'image_url' => 'http://example.com/pack.png',
            'source' => 'manual',
        ]);

        $this->assertSame($moh->id, $image->mohMedicine->id);
    }

    public function test_runs_table_tracks_offset_and_records(): void
    {
        $run = MedicineEnrichmentRun::create([
            'provider' => 'manual',
            'status' => 'running',
            'batch_size' => 20,
            'total_records' => 100,
            'processed_records' => 30,
            'matched_records' => 10,
            'last_offset' => 30,
            'started_at' => now(),
        ]);

        $this->assertDatabaseHas('medicine_enrichment_runs', ['id' => $run->id, 'processed_records' => 30]);
    }

    public function test_reviews_default_pending(): void
    {
        $review = MedicineEnrichmentReview::create([
            'moh_medicine_id' => $this->moh()->id,
            'provider' => 'drugs_api',
            'confidence' => 0.75,
        ]);

        $this->assertSame('pending', $review->status);

        // والستات، ومقضا نقد
        $review->update(['status' => 'approved', 'reviewed_at' => now()]);
        $this->assertSame('approved', $review->fresh()->status);
    }
}
