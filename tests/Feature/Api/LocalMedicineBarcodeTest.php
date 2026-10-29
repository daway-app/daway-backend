<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\MedicineBarcode;
use App\Models\MohMedicine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * باركود الدواء **المحلي** بلا سجل في كتالوج وزارة الصحة.
 *
 * ── الثغرة التي يثبّتها هذا الملف ────────────────────────────────────
 * الهجرة الأصلية أنشأت `medicine_barcodes.moh_medicine_id` بـ`NOT NULL`،
 * فكان تسجيل باركود لدواء محلي (غير مسجّل في MOH) **مستحيلًا** على مستوى
 * قاعدة البيانات — رغم أن `AccountingBarcodeController` مكتوب ليتعامل مع
 * الحالتين. والنتيجة العملية: أدوية الصيدليات المحلية لا يمكن مسحها.
 *
 * الإصلاح: هجرة `2026_10_27_000001` تجعل العمود nullable، و
 * `BarcodeLookupController` صار يبني الرد من الكتالوج المحلي عند غياب MOH
 * بدل الانهيار على `$moh->id`.
 */
final class LocalMedicineBarcodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_barcode_row_can_exist_for_a_local_medicine_without_moh(): void
    {
        $medicine = Medicine::factory()->create(['trade_name' => 'Local Only Med']);

        $record = MedicineBarcode::create([
            'moh_medicine_id' => null,
            'local_medicine_id' => $medicine->id,
            'barcode' => '6291041500999',
            'barcode_type' => 'EAN13',
            'source' => 'pharmacy',
            'confidence' => 1,
            'is_verified' => true,
        ]);

        $this->assertNull($record->fresh()->moh_medicine_id);
        $this->assertSame($medicine->id, $record->fresh()->local_medicine_id);
    }

    public function test_lookup_endpoint_returns_local_medicine_without_moh(): void
    {
        $medicine = Medicine::factory()->create([
            'trade_name' => 'Local Only Med',
            'active_ingredient' => 'localium',
        ]);

        MedicineBarcode::create([
            'moh_medicine_id' => null,
            'local_medicine_id' => $medicine->id,
            'barcode' => '6291041500999',
            'barcode_type' => 'EAN13',
            'source' => 'pharmacy',
            'confidence' => 1,
            'is_verified' => true,
        ]);

        $response = $this->getJson('/api/medicines/barcode/6291041500999')->assertOk();

        $response->assertJsonPath('data.medicine.source', 'local')
            ->assertJsonPath('data.medicine.name_en', 'Local Only Med')
            ->assertJsonPath('data.medicine.local_medicine_id', $medicine->id)
            ->assertJsonPath('data.image', null);
    }

    /** المسار القديم (MOH) يبقى كما هو — الإصلاح لم يغيّر عقده. */
    public function test_lookup_endpoint_still_returns_moh_medicine(): void
    {
        $moh = MohMedicine::create([
            'trade_name' => 'MOH Only Med',
            'generic_name' => 'mohium',
            'moh_product_id' => 771001,
            'moh_drug_id' => 881001,
        ]);

        MedicineBarcode::create([
            'moh_medicine_id' => $moh->id,
            'local_medicine_id' => null,
            'barcode' => '6291041500777',
            'barcode_type' => 'EAN13',
            'source' => 'moh',
            'confidence' => 1,
            'is_verified' => true,
        ]);

        $this->getJson('/api/medicines/barcode/6291041500777')
            ->assertOk()
            ->assertJsonPath('data.medicine.source', 'moh')
            ->assertJsonPath('data.medicine.name_en', 'MOH Only Med');
    }
}
